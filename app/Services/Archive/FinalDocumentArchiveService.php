<?php

namespace App\Services\Archive;

use App\Models\DocumentArchive;
use App\Models\FinalDocumentArchiveFile;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
use App\Models\ModuleDefinition;
use App\Models\PambRoutingEvent;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Attachments\StagedFinalDocumentAdapter;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Regional-return final copy lifecycle kept separate from report custody history. */
final class FinalDocumentArchiveService
{
    private const CLAIM_TTL_MINUTES = 30;

    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly SubmissionTrackingService $tracking,
        private readonly DocumentRoutingAccessService $routingAccess,
        private readonly DocumentRoutingTransitionService $routing,
        private readonly ReportDocumentAdapterResolver $documents,
        private readonly FinalDocumentArchiver $archiver,
        private readonly ArchiveOfficeResolver $archiveOffices,
        private readonly RegionalReceivingOfficeResolver $receivingOffices,
        private readonly ArchivePathBuilder $archivePaths,
        private readonly FinalDocumentSourceContractRegistry $sourceContracts,
        private readonly FinalReturnArchiveAvailability $availability,
    ) {}

    /** @return array<string,mixed> */
    public function context(User $actor, string $source, int $recordId): array
    {
        [$definition, $record, $module] = $this->authorizedRecord($actor, $source, $recordId);
        $release = $this->releaseEvidence($record, $source);
        $receipt = FinalDocumentReceipt::query()->where('source_type', $source)->where('source_id', $recordId)->first();
        $contract = $this->sourceContracts->forRegisteredSource($source, $record, $this->documents);
        $latestManifest = $receipt?->manifests()->with('files.archive')->latest('revision')->first();
        $receivingOffice = $this->receivingOfficeFor($record, $source, $definition);
        $writer = $this->canReceive($actor, $record, $source);
        $receiptMatches = $receipt && $release
            && $receipt->release_evidence_key === $release['key']
            && $receipt->release_evidence_type === $release['type']
            && $receipt->workflow_key === (string) $record->getAttribute('workflow_key')
            && $receipt->receiving_category === OrganizationalAccessService::PENRO_FOCAL
            && $this->sameOffice($receipt->receiving_office, $receivingOffice);
        $visibleReceipt = $receiptMatches && ($writer || $latestManifest?->archive_status === 'ARCHIVED');
        $visibleManifest = $latestManifest && ($latestManifest->archive_status === 'ARCHIVED' || $writer) ? $latestManifest : null;

        return [
            'source' => $source,
            'source_id' => $recordId,
            'workflow_key' => (string) $record->getAttribute('workflow_key'),
            'module' => (string) $module->name,
            'protected_area' => $record->relationLoaded('protectedArea') ? $record->getRelation('protectedArea')?->name : null,
            'originating_office' => $record->getAttribute($definition['archive_office_attribute'] ?? $definition['target_office'] ?? 'target_office'),
            'released' => $release !== null,
            'release_occurred_at' => $release['occurred_at'] ?? null,
            'receipt' => $visibleReceipt ? $this->receiptData($receipt) : null,
            'receiving_office' => $receivingOffice,
            'can_receive_final' => $writer && $release !== null && $receipt === null,
            'can_archive_final' => $writer && $receiptMatches,
            'archive_unit' => $definition['archive_unit'] ?? null,
            'final_file_contract' => $contract ? [
                'identity' => $contract['identity'], 'label' => $contract['label'],
                'extensions' => $contract['extensions'], 'max_kilobytes' => $contract['max_kilobytes'],
                'required_slots' => 1,
                'minimum_files' => 1,
            ] : null,
            'latest_manifest' => $visibleManifest ? $this->manifestData($visibleManifest) : null,
        ];
    }

    public function recordReceipt(User $actor, string $source, int $recordId, ?string $physicalReceivedAt): FinalDocumentReceipt
    {
        [$definition, $record] = $this->authorizedRecord($actor, $source, $recordId);
        $this->assertFinalReceiptWriter($actor, $record, $source);
        $release = $this->releaseEvidence($record, $source);
        if ($release === null) throw ValidationException::withMessages(['receipt' => 'The report has not been released to the Regional Office.']);

        $physical = $physicalReceivedAt !== null
            ? CarbonImmutable::parse($physicalReceivedAt, 'Asia/Manila')
            : CarbonImmutable::now('Asia/Manila');
        if ($release['occurred_at'] && $physical->utc()->lt(CarbonImmutable::parse($release['occurred_at'])->utc())) {
            throw ValidationException::withMessages(['physically_received_at' => 'The physical receipt time cannot precede the recorded Regional release.']);
        }

        return DB::transaction(function () use ($actor, $source, $recordId, $definition, $record, $release, $physical): FinalDocumentReceipt {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($recordId);
            $this->assertFinalReceiptWriter($actor, $locked, $source);
            $currentRelease = $this->releaseEvidence($locked, $source);
            if (! $currentRelease || $currentRelease['key'] !== $release['key']) {
                throw ValidationException::withMessages(['receipt' => 'The report release evidence changed. Refresh the report and try again.']);
            }

            $existing = FinalDocumentReceipt::query()->where('source_type', $source)->where('source_id', $recordId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->release_evidence_key !== $release['key']
                    || $existing->workflow_key !== (string) $locked->getAttribute('workflow_key')
                    || $existing->receiving_category !== OrganizationalAccessService::PENRO_FOCAL
                    || ! $this->sameOffice($existing->receiving_office, $this->receivingOfficeFor($locked, $source, $definition))) {
                    throw ValidationException::withMessages(['receipt' => 'A receipt already exists for different Regional release evidence.']);
                }
                return $existing;
            }

            return FinalDocumentReceipt::query()->create([
                'source_type' => $source,
                'source_id' => $recordId,
                'workflow_key' => (string) $locked->getAttribute('workflow_key'),
                'release_evidence_type' => $release['type'],
                'release_evidence_key' => $release['key'],
                'received_by' => $actor->getKey(),
                'receiving_category' => OrganizationalAccessService::PENRO_FOCAL,
                'receiving_office' => (string) $this->organization->normalizeOffice($actor->office_designated),
                'physically_received_at' => $physical,
                'recorded_at' => CarbonImmutable::now(config('app.timezone')),
            ]);
        }, 3);
    }

    /** @param list<UploadedFile> $uploads */
    public function createOrRetryManifest(
        User $actor,
        string $source,
        int $recordId,
        string $intentKey,
        array $uploads,
        bool $signatureConfirmation,
    ): array {
        if (! Str::isUuid($intentKey)) throw ValidationException::withMessages(['intent_key' => 'The archive request identity is invalid.']);
        if (! $signatureConfirmation) throw ValidationException::withMessages(['signature_confirmation' => 'Confirm that the selected returned documents have complete signatures.']);
        if ($uploads === []) throw ValidationException::withMessages(['final_files' => 'Select every file in the complete returned final document set.']);
        if (count($uploads) > 65535) throw ValidationException::withMessages(['final_files' => 'The returned file set is outside this source contract.']);

        [$sourceDefinition, $record, $module] = $this->authorizedRecord($actor, $source, $recordId);
        $this->assertFinalReceiptWriter($actor, $record, $source);
        $receipt = FinalDocumentReceipt::query()->where('source_type', $source)->where('source_id', $recordId)->firstOrFail();
        $this->assertReceiptStillMatchesRelease($record, $source, $receipt);
        $contract = $this->sourceContracts->forRegisteredSource($source, $record, $this->documents);
        if (! $contract) {
            throw ValidationException::withMessages(['final_files' => 'This report source has no supported official document contract for final archival.']);
        }

        $staged = [];
        try {
            foreach (array_values($uploads) as $upload) {
                if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                    throw ValidationException::withMessages(['final_files' => 'One of the selected final documents could not be read.']);
                }
                $validated = $this->sourceContracts->validateUpload($source, $upload);
                $path = $upload->store('regional-return-final', 'local');
                if (! is_string($path) || $path === '') throw new \RuntimeException('Private final document staging failed.');
                $absolute = Storage::disk('local')->path($path);
                $mime = mime_content_type($absolute);
                $size = (int) Storage::disk('local')->size($path);
                $hash = hash_file('sha256', $absolute);
                if (! in_array($mime, $this->sourceContracts->allowedMimeTypes($source), true)
                    || $size !== $validated['size'] || $hash !== $validated['sha256']) {
                    Storage::disk('local')->delete($path);
                    throw ValidationException::withMessages(['final_files' => 'The staged returned file did not match its validated source contract.']);
                }
                $filename = $this->safeFilename($upload->getClientOriginalName(), $validated['extension']);
                $staged[] = ['path' => $path, 'filename' => $filename, 'mime' => $mime, 'size' => $size, 'sha256' => $hash];
            }

            usort($staged, static fn (array $left, array $right): int =>
                strcmp(mb_strtolower($left['filename']), mb_strtolower($right['filename']))
                ?: strcmp($left['sha256'], $right['sha256'])
                ?: strcmp($left['mime'], $right['mime'])
                ?: ($left['size'] <=> $right['size']));
            foreach ($staged as $index => &$item) {
                $item['ordinal'] = $index + 1;
                $item['identity'] = $this->sourceContracts->logicalIdentity($source, $item['ordinal']);
            }
            unset($item);

            $manifest = DB::transaction(function () use ($actor, $source, $recordId, $record, $receipt, $intentKey, $staged): FinalDocumentArchiveManifest {
                $locked = $record->newQuery()->lockForUpdate()->findOrFail($recordId);
                $this->assertFinalReceiptWriter($actor, $locked, $source);
                $lockedReceipt = FinalDocumentReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
                $this->assertReceiptStillMatchesRelease($locked, $source, $lockedReceipt);

                $existingIntent = FinalDocumentArchiveManifest::query()->where('intent_key', $intentKey)->with('files')->lockForUpdate()->first();
                if ($existingIntent) {
                    if ((int) $existingIntent->receipt_id !== (int) $lockedReceipt->getKey() || ! $this->sameFileSet($existingIntent, $staged)) {
                        throw ValidationException::withMessages(['intent_key' => 'This archive request identity is already bound to a different report or file set.']);
                    }
                    return $existingIntent;
                }

                $latest = FinalDocumentArchiveManifest::query()->where('receipt_id', $lockedReceipt->getKey())->lockForUpdate()->latest('revision')->first();
                if ($latest && $latest->archive_status === 'ARCHIVED') {
                    $latest->load('files');
                    if (! $this->sameFileSet($latest, $staged)) {
                        throw ValidationException::withMessages(['archive' => 'A verified final set is already published for this Regional receipt.']);
                    }
                    return $latest;
                }
                if ($latest && $latest->archive_status !== 'ARCHIVED') {
                    throw ValidationException::withMessages(['archive' => 'Retry the existing final archive attempt before starting another revision.']);
                }
                $revision = ((int) ($latest?->revision ?? 0)) + 1;
                $manifest = FinalDocumentArchiveManifest::query()->create([
                    'receipt_id' => $lockedReceipt->getKey(),
                    'source_type' => $source,
                    'source_id' => $recordId,
                    'workflow_key' => (string) $locked->getAttribute('workflow_key'),
                    'archive_phase' => FinalDocumentArchiveManifest::PHASE,
                    'revision' => $revision,
                    'intent_key' => $intentKey,
                    'archive_status' => 'PENDING',
                    'signature_confirmation' => true,
                    'signature_set_sha256' => $this->signatureSetHash($staged),
                    'signature_confirmed_by' => $actor->getKey(),
                    'signature_confirmed_at' => now(),
                    'created_by' => $actor->getKey(),
                ]);
                foreach ($staged as $item) {
                    $manifest->files()->create([
                        'logical_identity' => $item['identity'],
                        'ordinal' => $item['ordinal'],
                        'original_filename' => $item['filename'],
                        'mime_type' => $item['mime'],
                        'file_size' => $item['size'],
                        'sha256' => $item['sha256'],
                        'staged_path' => $item['path'],
                        'file_status' => 'PENDING',
                    ]);
                }

                return $manifest->load('files');
            }, 3);

            // A duplicate request reuses the exact stored intent. Newly uploaded
            // duplicate bytes are discarded only after equality is proven.
            $manifestPaths = $manifest->files->pluck('staged_path')->all();
            foreach ($staged as $item) {
                if (! in_array($item['path'], $manifestPaths, true)) Storage::disk('local')->delete($item['path']);
            }

            return $this->processManifest($actor, $manifest->getKey(), $sourceDefinition, $record, $module);
        } catch (Throwable $exception) {
            foreach ($staged as $item) {
                $path = $item['path'];
                $isReferenced = FinalDocumentArchiveFile::query()->where('staged_path', $path)->exists();
                if (! $isReferenced) Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function retry(User $actor, int $manifestId): array
    {
        $this->assertFeatureEnabled();
        $manifest = FinalDocumentArchiveManifest::query()->with('receipt')->findOrFail($manifestId);
        [$definition, $record, $module] = $this->authorizedRecord($actor, (string) $manifest->source_type, (int) $manifest->source_id);
        $this->assertFinalReceiptWriter($actor, $record, (string) $manifest->source_type);
        abort_unless((int) $manifest->receipt_id === (int) $manifest->receipt->getKey(), 404);
        $this->assertReceiptStillMatchesRelease($record, (string) $manifest->source_type, $manifest->receipt);

        return $this->processManifest($actor, $manifestId, $definition, $record, $module);
    }

    public function authorizeManifestReader(User $actor, FinalDocumentArchiveManifest $manifest): array
    {
        [$definition, $record] = $this->authorizedRecord($actor, (string) $manifest->source_type, (int) $manifest->source_id);
        $receipt = $manifest->receipt;
        abort_unless($manifest->archive_phase === FinalDocumentArchiveManifest::PHASE
            && $manifest->archive_status === 'ARCHIVED'
            && $manifest->signature_confirmation
            && (int) $manifest->source_id === (int) $record->getKey()
            && $manifest->workflow_key === (string) $record->getAttribute('workflow_key')
            && $receipt
            && (string) $receipt->source_type === (string) $manifest->source_type
            && (int) $receipt->source_id === (int) $manifest->source_id
            && (string) $receipt->workflow_key === (string) $manifest->workflow_key
            && $receipt->receiving_category === OrganizationalAccessService::PENRO_FOCAL
            && $this->sameOffice($receipt->receiving_office, $this->receivingOfficeFor($record, (string) $manifest->source_type, $definition)), 404);
        $this->assertReceiptStillMatchesRelease($record, (string) $manifest->source_type, $receipt);
        $manifest->loadMissing('files');
        abort_unless($this->manifestMatchesSourceContract($manifest, $record), 404);
        return [$definition, $record];
    }

    /** Recheck record scope and the exact source/phase/hash binding for each protected final file. */
    public function authorizeManifestFileReader(User $actor, FinalDocumentArchiveFile $file): DocumentArchive
    {
        $manifest = $file->manifest()->with('receipt')->firstOrFail();
        [$definition, $record] = $this->authorizeManifestReader($actor, $manifest);
        abort_unless($this->manifestMatchesSourceContract($manifest, $record)
            && $manifest->files->contains(fn (FinalDocumentArchiveFile $candidate): bool => (int) $candidate->getKey() === (int) $file->getKey()), 404);
        $archive = $file->archive()->firstOrFail();
        $expectedSlot = 'regional-return-final:receipt-'.$manifest->receipt_id.':r'.$manifest->revision.':'.$file->logical_identity;
        abort_unless($file->file_status === 'ARCHIVED'
            && $archive->source_type === $manifest->source_type
            && (int) $archive->source_id === (int) $manifest->source_id
            && $archive->logical_slot === $expectedSlot
            && $archive->archive_phase === FinalDocumentArchiveManifest::PHASE
            && $archive->archive_status === 'ARCHIVED'
            && $archive->archived_sha256 === $file->sha256
            && (int) $archive->archived_size === (int) $file->file_size, 404);
        return $archive;
    }

    public function canBrowse(User $actor): bool
    {
        if (! $this->featureEnabled() || ! $actor->is_active || ! $actor->is_approved) return false;
        if ($this->organization->isGlobal($actor)) return true;
        if (! $this->organization->canViewSubmissionTracking($actor)) return false;
        foreach ($this->sourceContracts->sourceKeys() as $source) {
            $definition = $this->tracking->source($source);
            if ($definition && $this->organization->canUseSubmissionTrackingSource($actor, $source, $definition['ability'])) return true;
        }
        return false;
    }

    /** @return list<string> */
    public function sourceKeys(): array
    {
        return $this->sourceContracts->sourceKeys();
    }

    private function processManifest(User $actor, int $manifestId, array $sourceDefinition, Model $record, ModuleDefinition $module): array
    {
        $token = (string) Str::uuid();
        $claim = DB::transaction(function () use ($manifestId, $token): string {
            $manifest = FinalDocumentArchiveManifest::query()->lockForUpdate()->findOrFail($manifestId);
            if ($manifest->archive_status === 'ARCHIVED') return 'archived';
            if ($manifest->archive_status === 'PROCESSING' && $manifest->claimed_at && $manifest->claimed_at->gt(now()->subMinutes(self::CLAIM_TTL_MINUTES))) return 'processing';
            $manifest->forceFill(['archive_status' => 'PROCESSING', 'claim_token' => $token, 'claimed_at' => now(), 'last_error' => null])->saveOrFail();
            return 'claimed';
        }, 3);
        if ($claim !== 'claimed') return ['status' => $claim, 'manifest_id' => $manifestId];

        try {
            $manifest = FinalDocumentArchiveManifest::query()->with(['receipt', 'files.archive'])->findOrFail($manifestId);
            $this->assertFinalReceiptWriter($actor, $record, (string) $manifest->source_type);
            $this->assertReceiptStillMatchesRelease($record, (string) $manifest->source_type, $manifest->receipt);
            if (! $this->manifestMatchesSourceContract($manifest, $record)) {
                throw ValidationException::withMessages(['final_files' => 'The stored final file set no longer matches this report source.']);
            }
            if (! $manifest->signature_confirmation || (int) $manifest->signature_confirmed_by !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['signature_confirmation' => 'The signed-document confirmation is not bound to this authorized archive actor.']);
            }

            $archiveUnit = (string) ($sourceDefinition['archive_unit'] ?? '');
            $archiveOffice = $this->archiveOffices->resolveRegionalReturn($sourceDefinition, $record);
            $primaryExtension = strtolower(pathinfo((string) $manifest->files->first()?->original_filename, PATHINFO_EXTENSION)) ?: 'pdf';
            $this->archivePaths->build($module, $archiveUnit, $archiveOffice, (string) $record->getKey(), $primaryExtension, true);

            foreach ($manifest->files as $file) {
                $alreadyVerified = $file->file_status === 'ARCHIVED'
                    && $file->archive
                    && $this->archiver->verifyArchivedDocument($file->archive);
                if ($alreadyVerified) continue;
                if (! Storage::disk('local')->exists($file->staged_path)
                    || hash_file('sha256', Storage::disk('local')->path($file->staged_path)) !== $file->sha256) {
                    throw new \RuntimeException('A staged final copy is missing or no longer matches its manifest hash.');
                }
                $fileToken = (string) Str::uuid();
                $canClaim = DB::transaction(function () use ($file, $fileToken, $alreadyVerified): bool {
                    $locked = FinalDocumentArchiveFile::query()->lockForUpdate()->findOrFail($file->getKey());
                    if ($locked->file_status === 'ARCHIVED' && $alreadyVerified) return false;
                    if ($locked->file_status === 'UPLOADING'
                        && $locked->claimed_at
                        && $locked->claimed_at->gt(now()->subMinutes(self::CLAIM_TTL_MINUTES))) return false;
                    $locked->forceFill([
                        'file_status' => 'UPLOADING', 'claim_token' => $fileToken, 'claimed_at' => now(),
                        'attempt_count' => (int) $locked->attempt_count + 1, 'last_error' => null,
                    ])->saveOrFail();
                    return true;
                }, 3);
                if (! $canClaim) continue;

                $slot = 'regional-return-final:receipt-'.$manifest->receipt_id.':r'.$manifest->revision.':'.$file->logical_identity;
                $result = $this->archiver->archiveFinalDocumentUsingAdapter(
                    $record,
                    (string) $manifest->source_type,
                    $slot,
                    new StagedFinalDocumentAdapter($file->staged_path, $file->original_filename),
                    $slot,
                    (int) $actor->getKey(),
                    function (Model $locked) use ($actor, $manifest): void {
                        $this->assertFinalReceiptWriter($actor, $locked, (string) $manifest->source_type);
                        $currentManifest = FinalDocumentArchiveManifest::query()->findOrFail($manifest->getKey());
                        abort_unless($currentManifest->archive_phase === FinalDocumentArchiveManifest::PHASE
                            && $currentManifest->signature_confirmation
                            && (int) $currentManifest->signature_confirmed_by === (int) $actor->getKey(), 403);
                    },
                    config('services.document_archive.folder_id'),
                    $module,
                    $archiveUnit,
                    $archiveOffice,
                    FinalDocumentArchiveManifest::PHASE,
                    $this->sourceContracts->allowedMimeTypes((string) $manifest->source_type),
                );

                if (($result['status'] ?? null) !== 'archived') {
                    DB::transaction(function () use ($file, $fileToken): void {
                        $lockedFile = FinalDocumentArchiveFile::query()->lockForUpdate()->findOrFail($file->getKey());
                        abort_unless($lockedFile->claim_token === $fileToken, 409);
                        $lockedFile->forceFill(['file_status' => 'FAILED', 'claim_token' => null, 'claimed_at' => null, 'last_error' => 'Provider upload or verification did not complete.'])->saveOrFail();
                    }, 3);
                    throw new \RuntimeException('A final copy did not pass archive verification.');
                }
                $archive = DocumentArchive::query()->where('source_type', $manifest->source_type)->where('source_id', $manifest->source_id)->where('logical_slot', $slot)->firstOrFail();
                DB::transaction(function () use ($file, $fileToken, $archive): void {
                    $lockedFile = FinalDocumentArchiveFile::query()->lockForUpdate()->findOrFail($file->getKey());
                    abort_unless($lockedFile->claim_token === $fileToken, 409);
                    $lockedFile->forceFill([
                        'file_status' => 'ARCHIVED', 'document_archive_id' => $archive->getKey(),
                        'claim_token' => null, 'claimed_at' => null, 'verified_at' => now(), 'last_error' => null,
                    ])->saveOrFail();
                }, 3);
            }

            $manifest->load('files.archive');
            foreach ($manifest->files as $file) {
                if ($file->file_status !== 'ARCHIVED' || ! $file->archive || ! $this->archiver->verifyArchivedDocument($file->archive)) {
                    throw new \RuntimeException('The complete final document set is not currently verified.');
                }
            }

            DB::transaction(function () use ($manifestId, $token, $actor, $record, $sourceDefinition): void {
                $lockedRecord = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
                $current = FinalDocumentArchiveManifest::query()->lockForUpdate()->findOrFail($manifestId);
                abort_unless($current->claim_token === $token, 409);
                $this->assertFinalReceiptWriter($actor, $lockedRecord, (string) $current->source_type);
                $this->assertReceiptStillMatchesRelease($lockedRecord, (string) $current->source_type, $current->receipt);
                abort_unless($current->files()->where('file_status', '!=', 'ARCHIVED')->doesntExist(), 409);
                $current->forceFill(['archive_status' => 'ARCHIVED', 'finalized_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'last_error' => null])->saveOrFail();
            }, 3);

            return ['status' => 'archived', 'manifest_id' => $manifestId];
        } catch (Throwable $exception) {
            DB::transaction(function () use ($manifestId, $token): void {
                $manifest = FinalDocumentArchiveManifest::query()->lockForUpdate()->find($manifestId);
                if (! $manifest || $manifest->claim_token !== $token || $manifest->archive_status === 'ARCHIVED') return;
                $manifest->forceFill(['archive_status' => 'FAILED', 'claim_token' => null, 'claimed_at' => null, 'last_error' => 'Archive incomplete; staged files and verified provider objects were retained for retry.'])->save();
            }, 3);
            report($exception);
            return ['status' => 'failed', 'manifest_id' => $manifestId];
        }
    }

    private function authorizedRecord(User $actor, string $source, int $recordId): array
    {
        $this->assertFeatureEnabled();
        abort_unless($actor->is_active && $actor->is_approved, 403);
        $sourceDefinition = $this->tracking->source($source);
        abort_unless($sourceDefinition, 404);
        abort_unless(in_array($source, $this->sourceContracts->sourceKeys(), true), 404);
        $query = $sourceDefinition['model']::query();
        if ($source !== 'engp') $query->with('protectedArea');
        $record = $query->findOrFail($recordId);
        abort_unless($this->routingAccess->canView($actor, $record, $source, $sourceDefinition['ability']), 404);
        $contract = $this->sourceContracts->forRegisteredSource($source, $record, $this->documents);
        abort_unless($contract, 404);
        $moduleCode = $sourceDefinition['archive_module_code'] ?? null;
        abort_unless(is_callable($moduleCode), 404);
        $module = ModuleDefinition::query()->active()->notRetired()->where('code', (string) $moduleCode($record))->firstOrFail();
        return [$sourceDefinition, $record, $module];
    }

    private function featureEnabled(): bool
    {
        return $this->availability->enabled();
    }

    private function assertFeatureEnabled(): void
    {
        abort_unless($this->featureEnabled(), 404);
    }

    private function canReceive(User $actor, Model $record, string $source): bool
    {
        $sourceDefinition = $this->tracking->source($source);
        $expectedOffice = $sourceDefinition ? $this->receivingOfficeFor($record, $source, $sourceDefinition) : null;
        return $this->hasEligibleFocalAssignment($actor)
            && $expectedOffice !== null
            && $this->sameOffice($this->organization->normalizeOffice($actor->office_designated), $expectedOffice)
            && $sourceDefinition !== null
            && $this->routingAccess->canView($actor, $record, $source, $sourceDefinition['ability']);
    }

    /** Actor-only portion of the final receipt writer predicate; release and record gates remain separate. */
    public function hasEligibleFocalAssignment(User $actor): bool
    {
        $office = $this->organization->normalizeOffice($actor->office_designated);

        return $actor->is_active && $actor->is_approved
            && ! $this->organization->isGlobal($actor)
            && $this->organization->effectiveCategory($actor) === OrganizationalAccessService::PENRO_FOCAL
            && $actor->protected_area_id === null
            && is_string($office) && in_array($office, $this->organization->penroOffices(), true)
            && $this->organization->hasSubmissionTrackingScope($actor);
    }

    private function canArchive(User $actor, Model $record, string $source): bool
    {
        return $this->canReceive($actor, $record, $source);
    }

    private function assertFinalReceiptWriter(User $actor, Model $record, string $source): void
    {
        abort_unless($this->canReceive($actor, $record, $source), 403);
    }

    private function assertReceiptStillMatchesRelease(Model $record, string $source, FinalDocumentReceipt $receipt): void
    {
        $release = $this->releaseEvidence($record, $source);
        $sourceDefinition = $this->tracking->source($source);
        $expectedOffice = $sourceDefinition ? $this->receivingOfficeFor($record, $source, $sourceDefinition) : null;
        abort_unless($release && $release['key'] === $receipt->release_evidence_key
            && $receipt->release_evidence_type === $release['type']
            && $receipt->source_type === $source
            && (int) $receipt->source_id === (int) $record->getKey()
            && $receipt->workflow_key === (string) $record->getAttribute('workflow_key')
            && $receipt->receiving_category === OrganizationalAccessService::PENRO_FOCAL
            && $expectedOffice !== null && $this->sameOffice($receipt->receiving_office, $expectedOffice), 409);
    }

    /** @return array{type:string,key:string,occurred_at:?string}|null */
    private function releaseEvidence(Model $record, string $source): ?array
    {
        $state = $this->routing->state($record, $source);
        if (($state['stage'] ?? null) !== DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return null;
        $last = $state['events']->last();
        if (! $last || $last->to_stage !== DocumentRoutingProfileRegistry::RELEASED_REGIONAL || $last->event_key !== 'released') return null;
        $legacyId = data_get($last->metadata, 'legacy_pamb_event_id');
        if ($legacyId !== null && $record instanceof \App\Models\ConservationReportSubmission && $source === 'conservation') {
            $legacy = PambRoutingEvent::query()->whereKey((int) $legacyId)->where('conservation_report_submission_id', $record->getKey())->first();
            if (! $legacy || app(PambRoutingTimelineService::class)->canonicalStageKey((string) $legacy->stage_key) !== PambRoutingTimelineService::RELEASED_TO_REGIONAL) return null;
            return ['type' => 'pamb_routing_events', 'key' => 'pamb_routing_events:'.$legacy->getKey(), 'occurred_at' => $legacy->occurred_at?->toIso8601String()];
        }
        $persisted = \App\Models\DocumentRoutingEvent::query()->whereKey($last->getKey())
            ->where('source_type', $source)->where('source_id', $record->getKey())
            ->where('event_key', 'released')
            ->where('from_stage', DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL)
            ->where('to_stage', DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
            ->where('from_office', 'PENRO Records Unit')
            ->where('to_office', 'Regional Office')->first();
        if (! $persisted || data_get($persisted->metadata, 'action_key') !== 'release_to_regional') return null;

        return ['type' => 'document_routing_events', 'key' => 'document_routing_events:'.$persisted->getKey(), 'occurred_at' => $persisted->occurred_at?->toIso8601String()];
    }

    private function sameFileSet(FinalDocumentArchiveManifest $manifest, array $staged): bool
    {
        $existing = $manifest->files->sortBy('ordinal')->values();
        if ($existing->count() !== count($staged)) return false;
        foreach ($existing as $index => $file) {
            if ((int) $file->ordinal !== (int) $staged[$index]['ordinal']
                || $file->logical_identity !== $staged[$index]['identity']
                || $file->sha256 !== $staged[$index]['sha256']
                || (int) $file->file_size !== (int) $staged[$index]['size']
                || $file->mime_type !== $staged[$index]['mime']
                || $file->original_filename !== $staged[$index]['filename']) return false;
        }
        return true;
    }

    private function safeFilename(string $filename, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', '-', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        $extension = preg_replace('/[^a-z0-9]+/i', '', strtolower($extension)) ?: 'file';
        $stem = pathinfo($name, PATHINFO_FILENAME);
        return mb_substr(($stem !== '' ? $stem : 'Regional-returned-copy').'.'.$extension, 0, 255);
    }

    private function receivingOfficeFor(Model $record, string $source, array $sourceDefinition): ?string
    {
        return $this->receivingOffices->resolve($source, $record, $sourceDefinition);
    }

    /** @param list<array<string,mixed>> $files */
    private function signatureSetHash(array $files): string
    {
        $canonical = collect($files)->sortBy('ordinal')->map(fn (array $file): array => [
            'identity' => (string) $file['identity'],
            'ordinal' => (int) $file['ordinal'],
            'filename' => (string) $file['filename'],
            'mime_type' => (string) $file['mime'],
            'size' => (int) $file['size'],
            'sha256' => (string) $file['sha256'],
        ])->values()->all();

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function manifestMatchesSourceContract(FinalDocumentArchiveManifest $manifest, Model $record): bool
    {
        $source = (string) $manifest->source_type;
        if (! $this->sourceContracts->forRegisteredSource($source, $record, $this->documents)) return false;
        $files = $manifest->files->sortBy('ordinal')->values();
        if ($files->isEmpty()) return false;

        $canonical = [];
        foreach ($files as $index => $file) {
            $ordinal = $index + 1;
            try {
                $expectedIdentity = $this->sourceContracts->logicalIdentity($source, $ordinal);
            } catch (ValidationException) {
                return false;
            }
            if ((int) $file->ordinal !== $ordinal || $file->logical_identity !== $expectedIdentity) return false;
            $canonical[] = [
                'identity' => (string) $file->logical_identity,
                'ordinal' => (int) $file->ordinal,
                'filename' => (string) $file->original_filename,
                'mime_type' => (string) $file->mime_type,
                'size' => (int) $file->file_size,
                'sha256' => (string) $file->sha256,
            ];
        }

        $setHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return is_string($manifest->signature_set_sha256)
            && hash_equals($manifest->signature_set_sha256, $setHash);
    }

    private function sameOffice(?string $left, ?string $right): bool
    {
        return filled($left) && filled($right) && mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    private function manifestData(FinalDocumentArchiveManifest $manifest): array
    {
        return [
            'id' => (int) $manifest->getKey(),
            'revision' => (int) $manifest->revision,
            'status' => $manifest->archive_status,
            'finalized_at' => $manifest->finalized_at?->toIso8601String(),
            'signature_confirmed_at' => $manifest->signature_confirmed_at?->toIso8601String(),
            'files' => $manifest->files->map(fn (FinalDocumentArchiveFile $file): array => [
                'id' => (int) $file->getKey(),
                'name' => $file->original_filename,
                'mime_type' => $file->mime_type,
                'size' => (int) $file->file_size,
                'status' => $file->file_status,
                'preview_url' => $file->file_status === 'ARCHIVED' ? route('final-archives.files.show', $file->getKey()).'?preview=1' : null,
                'download_url' => $file->file_status === 'ARCHIVED' ? route('final-archives.files.show', $file->getKey()).'?download=1' : null,
            ])->all(),
        ];
    }

    private function receiptData(FinalDocumentReceipt $receipt): array
    {
        return [
            'id' => (int) $receipt->getKey(),
            'physically_received_at' => $receipt->physically_received_at?->toIso8601String(),
            'recorded_at' => $receipt->recorded_at?->toIso8601String(),
            'received_by' => $receipt->received_by,
            'receiving_category' => $receipt->receiving_category,
            'receiving_office' => $receipt->receiving_office,
        ];
    }
}
