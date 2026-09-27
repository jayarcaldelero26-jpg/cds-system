<?php

namespace App\Services\Archive;

use App\Models\DocumentArchive;
use App\Models\ModuleDefinition;
use App\Models\ReportTrackingReference;
use App\Services\Attachments\DocumentReferenceAdapter;
use App\Services\Attachments\ScalarDocumentReferenceAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Idempotent archive coordinator for an authorized records checkpoint. */
final class FinalDocumentArchiver
{
    public function __construct(private readonly GoogleDriveArchiveGateway $drive, private readonly ArchivePathBuilder $paths) {}

    /**
     * @return array{status:string,file_id?:string}
     */
    public function archiveFinalDocument(
        Model $record,
        string $sourceType,
        string $logicalSlot,
        string $pathColumn,
        string $filenameColumn,
        int $actorId,
        callable $assertFinalAndAuthorized,
        ?string $folderId,
        ?ModuleDefinition $module = null,
        ?string $archiveUnit = null,
        ?string $archiveOffice = null,
    ): array {
        return $this->archiveFinalDocumentUsingAdapter(
            $record,
            $sourceType,
            $logicalSlot,
            new ScalarDocumentReferenceAdapter($pathColumn, $filenameColumn),
            $logicalSlot,
            $actorId,
            $assertFinalAndAuthorized,
            $folderId,
            $module,
            $archiveUnit,
            $archiveOffice,
        );
    }

    /** @param callable(Model):void $assertFinalAndAuthorized */
    public function archiveFinalDocumentUsingAdapter(
        Model $record,
        string $sourceType,
        string $logicalSlot,
        DocumentReferenceAdapter $adapter,
        string $historySlot,
        int $actorId,
        callable $assertFinalAndAuthorized,
        ?string $folderId,
        ?ModuleDefinition $module = null,
        ?string $archiveUnit = null,
        ?string $archiveOffice = null,
    ): array {
        $archiveStartedAt = hrtime(true);
        $assertFinalAndAuthorized($record);
        $identity = ['source_type' => $sourceType, 'source_id' => (int) $record->getKey(), 'logical_slot' => $logicalSlot];
        $archive = DocumentArchive::query()->firstOrCreate($identity, ['archive_status' => 'PENDING']);

        try {
            // A records checkpoint is frozen. Later working-document changes
            // remain authoritative on private storage but never replace the
            // already verified Drive checkpoint object.
            if ($archive->archive_status === 'ARCHIVED') {
                $phaseStartedAt = hrtime(true);
                $availability = $this->drive->verifyAvailability(
                    (string) $archive->google_drive_file_id,
                    (string) $archive->archived_sha256,
                    (int) $archive->archived_size,
                );
                $errorClass = match ($availability) {
                    'verified' => null,
                    'unavailable' => 'NOT_FOUND',
                    'content_mismatch' => 'CONTENT_MISMATCH',
                    default => 'PROVIDER_ERROR',
                };
                $archive->forceFill([
                    'remote_availability' => in_array($availability, ['verified', 'unavailable'], true) ? $availability : 'unknown',
                    'last_verified_at' => now(),
                    'last_verification_error_class' => $errorClass,
                ])->saveOrFail();

                $this->logPhase('existing_archive_availability_check', $phaseStartedAt);
                Log::debug('Existing archived checkpoint rechecked without path resolution or upload.', ['source_type' => $sourceType, 'duration_ms' => (hrtime(true) - $archiveStartedAt) / 1_000_000]);
                return ['status' => 'archived', 'file_id' => $archive->google_drive_file_id, 'working_file_removed' => false];
            }

            $phaseStartedAt = hrtime(true);
            $reference = $adapter->read($record, $logicalSlot);
            $path = $reference['path'];
            $archive->forceFill(['archive_status' => 'PENDING', 'last_error' => null])->saveOrFail();
            $filename = $reference['filename'];
            if (! is_string($path) || ! $this->safeRelativePath($path) || ! Storage::disk('local')->exists($path)) {
                throw new \RuntimeException('The final working document is unavailable.');
            }

            $absolute = Storage::disk('local')->path($path);
            $size = Storage::disk('local')->size($path);
            $sha256 = hash_file('sha256', $absolute);
            if (! is_string($sha256) || $size < 1 || mime_content_type($absolute) !== 'application/pdf') {
                throw new \RuntimeException('The final working document failed type or integrity validation.');
            }
            $this->logPhase('local_document_validation', $phaseStartedAt);

            $phaseStartedAt = hrtime(true);
            if (! $module || ! $archiveUnit || ! $archiveOffice) throw new \RuntimeException('The active submission module, unit, or CENRO office is unmapped for archive storage.');
            $archiveContext = $this->archiveContext($record, $sourceType, $module, $archiveUnit, $archiveOffice);
            $archiveFilename = $archiveContext['filename'];
            $this->logPhase('archive_path_resolution', $phaseStartedAt);

            $phaseStartedAt = hrtime(true);
            $remote = null;
            $existing = $this->drive->findByIdentityAndHash($identity, $sha256);
            if ($existing && $this->drive->verify($existing['file_id'], $sha256, $size)) {
                $remote = $existing;
            }
            $this->logPhase('existing_archive_reconciliation', $phaseStartedAt);

            if (! $remote) {
                $phaseStartedAt = hrtime(true);
                if ($archive->google_drive_file_id) {
                    $remote = $this->drive->replace($archive->google_drive_file_id, $absolute, $archiveFilename, $identity, $sha256, $folderId, $archiveContext);
                } else {
                    $remote = $this->drive->upload($absolute, $archiveFilename, $identity, $sha256, $folderId, $archiveContext);
                }
                $this->logPhase('archive_upload', $phaseStartedAt);
            }
            $phaseStartedAt = hrtime(true);
            if (! $this->drive->verify($remote['file_id'], $sha256, $size)) {
                throw new \RuntimeException('The archive object did not pass verification.');
            }
            $this->logPhase('archive_upload_verification', $phaseStartedAt);

            $phaseStartedAt = hrtime(true);
            DB::transaction(function () use ($identity, $remote, $sha256, $size, $actorId, $archiveFilename, $record, $adapter, $logicalSlot, $path): void {
                $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
                if ($adapter->read($locked, $logicalSlot)['path'] !== $path) {
                    throw new \RuntimeException('The working document changed while archival was in progress.');
                }
                $current = DocumentArchive::query()->where($identity)->lockForUpdate()->firstOrFail();
                $current->forceFill([
                    'google_drive_file_id' => $remote['file_id'],
                    'google_drive_folder_id' => $remote['folder_id'] ?? null,
                    'archived_sha256' => $sha256,
                    'archived_size' => $size,
                    'archived_at' => now(),
                    'archived_by' => $actorId,
                    'archive_status' => 'ARCHIVED',
                    'remote_availability' => 'verified',
                    'last_verified_at' => now(),
                    'last_verification_error_class' => null,
                    'original_filename' => $archiveFilename,
                    'last_error' => null,
                ])->saveOrFail();
            });
            $this->logPhase('archive_row_persistence', $phaseStartedAt);

            $verified = DocumentArchive::query()->where($identity)->first();
            $phaseStartedAt = hrtime(true);
            if (! $verified || $verified->archive_status !== 'ARCHIVED'
                || $verified->google_drive_file_id !== $remote['file_id']
                || $verified->archived_sha256 !== $sha256
                || ! $this->verifyArchivedDocument($verified)) {
                throw new \RuntimeException('Committed archive metadata or remote verification failed.');
            }
            $this->logPhase('persisted_archive_verification', $phaseStartedAt);

            // The verified final document remains the authoritative private
            // working copy after archival. Drive is the archive/checkpoint
            // copy; it is not a replacement for operational storage.
            Log::debug('Synchronous document archive completed.', ['source_type' => $sourceType, 'duration_ms' => (hrtime(true) - $archiveStartedAt) / 1_000_000]);
            return ['status' => 'archived', 'file_id' => $remote['file_id'], 'working_file_removed' => false];
        } catch (Throwable $exception) {
            try {
                $archive->refresh();
                if ($archive->archive_status !== 'ARCHIVED') {
                    $archive->forceFill(['archive_status' => 'FAILED', 'last_error' => 'Archive operation failed; working document retained.'])->save();
                }
            } catch (Throwable $metadataException) {
                Log::warning('Archive failure status could not be persisted; retry remains safe.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $historySlot, 'exception' => $metadataException::class]);
            }
            Log::error('Final document archive failed; private working file retained.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $historySlot, 'exception' => $exception::class]);
            Log::debug('Synchronous document archive failed.', ['source_type' => $sourceType, 'duration_ms' => (hrtime(true) - $archiveStartedAt) / 1_000_000]);
            return ['status' => 'failed'];
        }
    }

    public function verifyArchivedDocument(DocumentArchive $archive): bool
    {
        return $archive->archive_status === 'ARCHIVED'
            && filled($archive->google_drive_file_id)
            && $this->drive->verifyAvailability($archive->google_drive_file_id, (string) $archive->archived_sha256, (int) $archive->archived_size) === 'verified';
    }

    public function retrieveArchivedDocument(DocumentArchive $archive)
    {
        abort_unless($this->verifyArchivedDocument($archive), 404);
        return $this->drive->retrieve((string) $archive->google_drive_file_id);
    }

    private function safeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        return $normalized !== '' && ! str_starts_with($normalized, '/')
            && ! preg_match('/^[A-Za-z]:\//', $normalized)
            && ! in_array('..', explode('/', $normalized), true)
            && ! str_contains($normalized, "\0");
    }

    /** @return array{year:string,protected_area:string,archive_unit:string,archive_office:string,module_name:string,folder_path:list<string>,filename:string} */
    private function archiveContext(Model $record, string $sourceType, ModuleDefinition $module, string $archiveUnit, string $archiveOffice): array
    {
        $tracking = ReportTrackingReference::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $record->getKey())
            ->first();

        $year = $tracking?->reporting_year ?? $record->getAttribute('reporting_year');
        if (! $year) {
            foreach (['date_accomplished', 'date_conducted', 'date_received_penro', 'created_at'] as $dateField) {
                $date = $record->getAttribute($dateField);
                if (! $date) continue;
                try {
                    $year = ($date instanceof \DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date))->format('Y');
                    break;
                } catch (Throwable) {
                    // Try the next established report-date field.
                }
            }
        }

        $protectedArea = null;
        if (method_exists($record, 'protectedArea')) {
            $record->loadMissing('protectedArea');
            $protectedArea = $record->getRelation('protectedArea')?->name;
        }

        $trackingNumber = $tracking?->tracking_number;

        $path = $this->paths->build($module, $archiveUnit, $archiveOffice, (string) ($trackingNumber ?: 'Report-'.$record->getKey()));

        return [
            'year' => (string) ($year ?: now()->format('Y')),
            'protected_area' => (string) ($protectedArea ?: 'Unassigned Protected Area'),
            'archive_unit' => $path['unit'],
            'archive_office' => $path['office'],
            'module_name' => $path['module'],
            'folder_path' => $path['segments'],
            'filename' => $path['filename'],
        ];
    }

    private function logPhase(string $phase, int $startedAt): void
    {
        Log::debug('Synchronous document archive phase completed.', ['phase' => $phase, 'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000]);
    }
}
