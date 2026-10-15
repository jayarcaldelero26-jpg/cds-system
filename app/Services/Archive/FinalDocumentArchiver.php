<?php

namespace App\Services\Archive;

use App\Models\DocumentArchive;
use App\Models\ModuleDefinition;
use App\Models\ReportTrackingReference;
use App\Services\Attachments\DocumentReferenceAdapter;
use App\Services\Attachments\ScalarDocumentReferenceAdapter;
use App\Support\ArchiveRequestTrace;
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
        string $archivePhase = 'dispatch_checkpoint',
        array $allowedMimeTypes = ['application/pdf'],
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
            $archivePhase,
            $allowedMimeTypes,
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
        string $archivePhase = 'dispatch_checkpoint',
        array $allowedMimeTypes = ['application/pdf'],
    ): array {
        $archiveStartedAt = hrtime(true);
        $assertFinalAndAuthorized($record);
        $identity = ['source_type' => $sourceType, 'source_id' => (int) $record->getKey(), 'logical_slot' => $logicalSlot];
        $archive = DocumentArchive::query()->firstOrCreate($identity, ['archive_status' => 'PENDING', 'archive_phase' => $archivePhase]);
        if ($archive->archive_phase !== $archivePhase) {
            Log::warning('Archive identity is already bound to a different lifecycle phase.', ['source' => $sourceType, 'record_id' => $record->getKey(), 'logical_slot' => $logicalSlot]);
            return ['status' => 'failed'];
        }

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
                Log::debug('Existing archived checkpoint rechecked without path resolution or upload.', ['request_id' => ArchiveRequestTrace::currentId(), 'phase' => 'archive_total', 'duration_ms' => (hrtime(true) - $archiveStartedAt) / 1_000_000]);
                if ($availability === 'verified') {
                    return ['status' => 'archived', 'file_id' => $archive->google_drive_file_id, 'working_file_removed' => false];
                }
                // Historical dispatch checkpoints remain frozen and fail closed.
                // A final-return revision may reconcile its exact identity/hash
                // before uploading a replacement object; it never overwrites an
                // existing Drive object as a recovery shortcut.
                if ($archivePhase !== \App\Models\FinalDocumentArchiveManifest::PHASE) return ['status' => 'failed'];
            }

            $phaseStartedAt = hrtime(true);
            $validationStartedAt = $phaseStartedAt;
            $reference = $adapter->read($record, $logicalSlot);
            $path = $reference['path'];
            $archive->forceFill(['archive_status' => 'PENDING', 'last_error' => null])->saveOrFail();
            $filename = $reference['filename'];
            if (! is_string($path) || ! $this->safeRelativePath($path) || ! Storage::disk('local')->exists($path)) {
                throw new \RuntimeException('The final working document is unavailable.');
            }

            $this->logPhase('local_document_reference', $phaseStartedAt);
            $phaseStartedAt = hrtime(true);
            $absolute = Storage::disk('local')->path($path);
            $size = Storage::disk('local')->size($path);
            $this->logPhase('local_document_storage_stat', $phaseStartedAt);
            $phaseStartedAt = hrtime(true);
            $sha256 = hash_file('sha256', $absolute);
            $this->logPhase('local_document_hash', $phaseStartedAt);
            $phaseStartedAt = hrtime(true);
            $mime = mime_content_type($absolute);
            $this->logPhase('local_document_mime_check', $phaseStartedAt);
            if (! is_string($sha256) || $size < 1 || ! is_string($mime) || ! in_array($mime, $allowedMimeTypes, true)) {
                throw new \RuntimeException('The final working document failed type or integrity validation.');
            }
            if ($mime !== 'application/pdf' && ! $this->drive instanceof MimeAwareGoogleDriveArchiveGateway) {
                throw new \RuntimeException('The configured archive provider cannot preserve this source file type.');
            }
            $this->logPhase('local_document_validation', $validationStartedAt);

            $phaseStartedAt = hrtime(true);
            if (! $module || ! $archiveUnit || ! $archiveOffice) throw new \RuntimeException('The active submission module, unit, or CENRO office is unmapped for archive storage.');
            $fileExtension = $archivePhase === \App\Models\FinalDocumentArchiveManifest::PHASE
                ? pathinfo((string) $filename, PATHINFO_EXTENSION)
                : 'pdf';
            $archiveContext = $this->archiveContext(
                $record, $sourceType, $module, $archiveUnit, $archiveOffice, $fileExtension,
                $archivePhase === \App\Models\FinalDocumentArchiveManifest::PHASE,
            );
            $archiveFilename = $archiveContext['filename'];
            $this->logPhase('archive_path_resolution', $phaseStartedAt);

            $phaseStartedAt = hrtime(true);
            $remote = null;
            $existing = $this->drive->findByIdentityAndHash($identity, $sha256);
            if ($existing) {
                if (! $this->drive->verify($existing['file_id'], $sha256, $size)) {
                    throw new \RuntimeException('An exact-identity archive object was found but its content could not be verified.');
                }
                $remote = $existing;
            }
            $this->logPhase('existing_archive_reconciliation', $phaseStartedAt);

            if (! $remote) {
                $phaseStartedAt = hrtime(true);
                $mimeAware = $this->drive instanceof MimeAwareGoogleDriveArchiveGateway;
                if ($archive->google_drive_file_id && $archivePhase !== \App\Models\FinalDocumentArchiveManifest::PHASE) {
                    $remote = $mimeAware
                        ? $this->drive->replaceWithMime($archive->google_drive_file_id, $absolute, $archiveFilename, $identity, $sha256, $folderId, $archiveContext, $mime)
                        : $this->drive->replace($archive->google_drive_file_id, $absolute, $archiveFilename, $identity, $sha256, $folderId, $archiveContext);
                } else {
                    $remote = $mimeAware
                        ? $this->drive->uploadWithMime($absolute, $archiveFilename, $identity, $sha256, $folderId, $archiveContext, $mime)
                        : $this->drive->upload($absolute, $archiveFilename, $identity, $sha256, $folderId, $archiveContext);
                }
                $this->logPhase('archive_upload', $phaseStartedAt);
            }
            $phaseStartedAt = hrtime(true);
            if (! $this->drive->verify($remote['file_id'], $sha256, $size)) {
                throw new \RuntimeException('The archive object did not pass verification.');
            }
            $this->logPhase('archive_upload_verification', $phaseStartedAt);

            $phaseStartedAt = hrtime(true);
            DB::transaction(function () use ($identity, $remote, $sha256, $size, $actorId, $archiveFilename, $record, $adapter, $logicalSlot, $path, $archivePhase): void {
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
                    'archive_phase' => $archivePhase,
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
            Log::debug('Synchronous document archive completed.', ['request_id' => ArchiveRequestTrace::currentId(), 'phase' => 'archive_total', 'duration_ms' => (hrtime(true) - $archiveStartedAt) / 1_000_000]);
            return ['status' => 'archived', 'file_id' => $remote['file_id'], 'working_file_removed' => false];
        } catch (Throwable $exception) {
            try {
                $archive->refresh();
                if ($archive->archive_status !== 'ARCHIVED') {
                    $archive->forceFill(['archive_status' => 'FAILED', 'last_error' => 'Archive operation failed; working document retained.'])->save();
                }
            } catch (Throwable $metadataException) {
                Log::warning('Archive failure status could not be persisted; retry remains safe.', ['request_id' => ArchiveRequestTrace::currentId(), 'phase' => 'archive_failure_status_persistence', 'exception' => $metadataException::class]);
            }
            Log::error('Final document archive failed; private working file retained.', ['request_id' => ArchiveRequestTrace::currentId(), 'phase' => 'archive_total', 'exception' => $exception::class]);
            Log::debug('Synchronous document archive failed.', ['request_id' => ArchiveRequestTrace::currentId(), 'phase' => 'archive_total', 'duration_ms' => (hrtime(true) - $archiveStartedAt) / 1_000_000]);
            return ['status' => 'failed'];
        }
    }

    /** Validate the currently attached source document without creating an archive row or calling Drive. */
    public function validateLocalDocumentUsingAdapter(
        Model $record,
        string $logicalSlot,
        DocumentReferenceAdapter $adapter,
        array $allowedMimeTypes = ['application/pdf'],
    ): array {
        $reference = $adapter->read($record, $logicalSlot);
        $path = $reference['path'] ?? null;
        $filename = $reference['filename'] ?? null;
        if (! is_string($path) || ! $this->safeRelativePath($path)
            || ! is_string($filename) || trim($filename) === ''
            || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('The official source document is unavailable.');
        }

        $absolute = Storage::disk('local')->path($path);
        $size = (int) Storage::disk('local')->size($path);
        $sha256 = hash_file('sha256', $absolute);
        $mime = mime_content_type($absolute);
        if ($size < 1 || ! is_string($sha256) || ! is_string($mime)
            || ! in_array($mime, $allowedMimeTypes, true)) {
            throw new \RuntimeException('The official source document failed its type or integrity validation.');
        }

        return ['path' => $path, 'filename' => $filename, 'size' => $size, 'sha256' => $sha256, 'mime_type' => $mime];
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
    private function archiveContext(Model $record, string $sourceType, ModuleDefinition $module, string $archiveUnit, string $archiveOffice, string $extension = 'pdf', bool $allowPenroOffice = false): array
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
        $activityName = trim((string) $record->getAttribute('activity_name'));
        $filenameBase = $activityName !== ''
            ? $activityName
            : (string) ($trackingNumber ?: 'Report-'.$record->getKey());

        $path = $this->paths->build($module, $archiveUnit, $archiveOffice, $filenameBase, $extension, $allowPenroOffice);

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
        Log::debug('Synchronous document archive phase completed.', [
            'request_id' => ArchiveRequestTrace::currentId(),
            'phase' => $phase,
            'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
        ]);
    }
}
