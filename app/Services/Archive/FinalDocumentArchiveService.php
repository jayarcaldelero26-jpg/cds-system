<?php

namespace App\Services\Archive;

use App\Models\DocumentArchive;
use App\Models\FinalDocumentArchiveFile;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
use App\Models\ModuleDefinition;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Attachments\StagedFinalDocumentAdapter;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use App\Services\SubmissionTracking\DocumentRoutingAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Support\ArchiveRequestTrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        private readonly PamoOriginSchemaReadiness $pamoOrigins,
        private readonly CurrentDocumentReplacementService $currentDocuments,
    ) {}

    /** @return array<string,mixed> */
    public function context(User $actor, string $source, int $recordId, bool $includeEarlierDispatchCopy = false): array
    {
        [$definition, $record] = $this->authorizedRecord($actor, $source, $recordId);
        $reportMetadata = $this->tracking->archiveReportMetadata($source, $record, $definition);
        $release = $this->releaseEvidence($record, $source);
        $receipt = FinalDocumentReceipt::query()->where('source_type', $source)->where('source_id', $recordId)->first();
        $contract = $this->sourceContracts->forRegisteredSource($source, $record, $this->documents);
        $latestManifest = $receipt?->manifests()->with('files.archive')->latest('revision')->first();
        $receivingOffice = $this->receivingOfficeFor($record, $source, $definition);
        $officeContext = $this->originOfficeContext($record, $source, $definition);
        $writer = $this->canReceive($actor, $record, $source);
        $receiptMatches = $receipt && $release
            && $receipt->release_evidence_key === $release['key']
            && $receipt->release_evidence_type === $release['type']
            && $receipt->workflow_key === (string) $record->getAttribute('workflow_key')
            && $receipt->receiving_category === OrganizationalAccessService::PENRO_FOCAL
            && $this->sameOffice($receipt->receiving_office, $receivingOffice);
        $visibleReceipt = $receiptMatches && ($writer || $latestManifest?->archive_status === 'ARCHIVED');
        $visibleManifest = $latestManifest && ($latestManifest->archive_status === 'ARCHIVED' || $writer) ? $latestManifest : null;
        $canReceiveFinal = $writer && $release !== null && $receipt === null;
        $canArchiveFinal = $writer && $receiptMatches;
        $finalActionUnavailableReason = $this->finalActionUnavailableReason(
            $actor, $record, $source, $definition, $release, $receipt, (bool) $receiptMatches, $latestManifest, $writer,
        );
        $manifestVerified = $visibleManifest
            ? $this->manifestIsVerifiedForRecord($record, $source, $definition, $visibleManifest)
            : false;

        return [
            'source' => $source,
            'source_id' => $recordId,
            'workflow_key' => (string) $record->getAttribute('workflow_key'),
            'module' => $reportMetadata['module'],
            'title' => $reportMetadata['title'],
            'protected_area' => $record->relationLoaded('protectedArea') ? $record->getRelation('protectedArea')?->name : null,
            ...$officeContext,
            'released' => $release !== null,
            'release_occurred_at' => $release['occurred_at'] ?? null,
            'receipt' => $visibleReceipt ? $this->receiptData($receipt) : null,
            'receiving_office' => $receivingOffice,
            'can_receive_final' => $canReceiveFinal,
            'can_archive_final' => $canArchiveFinal,
            'final_action_unavailable_reason' => $finalActionUnavailableReason,
            'archive_unit' => $definition['archive_unit'] ?? null,
            'final_file_contract' => $contract ? [
                'identity' => $contract['identity'], 'label' => $contract['label'],
                'extensions' => $contract['extensions'], 'max_kilobytes' => $contract['max_kilobytes'],
                'required_slots' => 1,
                'minimum_files' => 1,
            ] : null,
            'earlier_dispatch_copy' => $includeEarlierDispatchCopy
                ? $this->earlierDispatchCopyData($source, $record, $contract)
                : null,
            'latest_manifest' => $visibleManifest ? $this->manifestData($visibleManifest, $manifestVerified) : null,
            'routing_timeline' => $this->custodyTimeline($source, $recordId, $visibleReceipt ? $receipt : null),
        ];
    }

    public function recordReceipt(User $actor, string $source, int $recordId, ?string $physicalReceivedAt): FinalDocumentReceipt
    {
        [$definition, $record] = $this->authorizedRecord($actor, $source, $recordId);
        $this->assertFinalReceiptWriter($actor, $record, $source);
        $release = $this->releaseEvidence($record, $source);
        if ($release === null) throw ValidationException::withMessages(['receipt' => 'The report has not been released to the Regional Office.']);

        $physical = $this->physicalReceiptTime($physicalReceivedAt);
        $this->assertReceiptTimeAfterRelease($physical, $release);

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

    /** One request records a new physical receipt and submits its complete signed set. */
    public function receiveAndArchive(
        User $actor,
        string $source,
        int $recordId,
        string $physicalReceivedAt,
        string $intentKey,
        array $uploads,
        bool $signatureConfirmation,
    ): array {
        return $this->createOrRetryManifest($actor, $source, $recordId, $intentKey, $uploads, $signatureConfirmation, $physicalReceivedAt);
    }

    /** @param list<UploadedFile> $uploads */
    public function createOrRetryManifest(
        User $actor,
        string $source,
        int $recordId,
        string $intentKey,
        array $uploads,
        bool $signatureConfirmation,
        ?string $physicalReceivedAt = null,
    ): array {
        $validationStartedAt = hrtime(true);
        if (! Str::isUuid($intentKey)) throw ValidationException::withMessages(['intent_key' => 'The archive request identity is invalid.']);
        if (! $signatureConfirmation) throw ValidationException::withMessages(['signature_confirmation' => 'Confirm that the selected returned documents have complete signatures.']);
        if ($uploads === []) throw ValidationException::withMessages(['final_files' => 'Select every file in the complete returned final document set.']);
        if (count($uploads) > 65535) throw ValidationException::withMessages(['final_files' => 'The returned file set is outside this source contract.']);

        [$sourceDefinition, $record, $module] = $this->authorizedRecord($actor, $source, $recordId);
        $this->assertFinalReceiptWriter($actor, $record, $source);
        $release = $this->releaseEvidence($record, $source);
        if ($release === null) throw ValidationException::withMessages(['receipt' => 'The report has not been released to the Regional Office.']);
        $receipt = FinalDocumentReceipt::query()->where('source_type', $source)->where('source_id', $recordId)->first();
        if ($receipt) {
            $this->assertReceiptStillMatchesRelease($record, $source, $receipt);
        } elseif ($physicalReceivedAt === null) {
            throw ValidationException::withMessages(['receipt' => 'Record the actual physical receipt with the complete signed file set.']);
        }
        $physical = $receipt?->physically_received_at ?? $this->physicalReceiptTime($physicalReceivedAt);
        $this->assertReceiptTimeAfterRelease($physical, $release);
        $contract = $this->sourceContracts->forRegisteredSource($source, $record, $this->documents);
        if (! $contract) {
            throw ValidationException::withMessages(['final_files' => 'This report source has no supported official document contract for final archival.']);
        }

        $validatedUploads = [];
        $seenBytes = [];
        foreach (array_values($uploads) as $uploadIndex => $upload) {
            if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                throw ValidationException::withMessages(['final_files.'.$uploadIndex => 'One of the selected final documents could not be read.']);
            }
            try {
                $validated = $this->sourceContracts->validateUpload($source, $upload);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first() ?: 'This file does not match the registered final document contract.';
                throw ValidationException::withMessages(['final_files.'.$uploadIndex => $message]);
            }
            if (! in_array($validated['mime_type'], $this->sourceContracts->allowedMimeTypes($source), true)) {
                throw ValidationException::withMessages(['final_files.'.$uploadIndex => 'The selected file MIME type is not allowed for this report source.']);
            }
            $byteKey = $validated['sha256'].':'.$validated['size'].':'.$validated['mime_type'];
            if (isset($seenBytes[$byteKey])) continue;
            $seenBytes[$byteKey] = true;
            $validatedUploads[] = ['upload' => $upload, 'validated' => $validated];
        }

        if ($validatedUploads === []) {
            throw ValidationException::withMessages(['final_files' => 'Select at least one distinct file in the complete returned final set.']);
        }
        $validatedBytes = array_sum(array_column(array_column($validatedUploads, 'validated'), 'size'));
        $this->logArchivePhase('request_validation', $validationStartedAt, count($validatedUploads), $validatedBytes);

        $staged = [];
        $stagingStartedAt = hrtime(true);
        try {
            foreach ($validatedUploads as $validatedUpload) {
                $upload = $validatedUpload['upload'];
                $validated = $validatedUpload['validated'];
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

            $this->logArchivePhase('private_staging_and_rehash', $stagingStartedAt, count($staged), array_sum(array_column($staged, 'size')));
            $manifestCommitStartedAt = hrtime(true);
            $manifest = DB::transaction(function () use ($actor, $source, $recordId, $record, $receipt, $release, $physical, $intentKey, $staged): FinalDocumentArchiveManifest {
                $locked = $record->newQuery()->lockForUpdate()->findOrFail($recordId);
                $this->assertFinalReceiptWriter($actor, $locked, $source);
                $currentRelease = $this->releaseEvidence($locked, $source);
                if (! $currentRelease || $currentRelease['key'] !== $release['key']) {
                    throw ValidationException::withMessages(['receipt' => 'The report release evidence changed. Refresh the report and try again.']);
                }
                $lockedReceipt = FinalDocumentReceipt::query()->where('source_type', $source)->where('source_id', $recordId)->lockForUpdate()->first();
                if ($lockedReceipt === null) {
                    $this->assertReceiptTimeAfterRelease($physical, $currentRelease);
                    $lockedReceipt = FinalDocumentReceipt::query()->create([
                        'source_type' => $source,
                        'source_id' => $recordId,
                        'workflow_key' => (string) $locked->getAttribute('workflow_key'),
                        'release_evidence_type' => $currentRelease['type'],
                        'release_evidence_key' => $currentRelease['key'],
                        'received_by' => $actor->getKey(),
                        'receiving_category' => OrganizationalAccessService::PENRO_FOCAL,
                        'receiving_office' => (string) $this->organization->normalizeOffice($actor->office_designated),
                        'physically_received_at' => $physical,
                        'recorded_at' => CarbonImmutable::now(config('app.timezone')),
                    ]);
                }
                $this->assertReceiptStillMatchesRelease($locked, $source, $lockedReceipt);

                $existingIntent = FinalDocumentArchiveManifest::query()->where('intent_key', $intentKey)->with('files')->lockForUpdate()->first();
                if ($existingIntent) {
                    if ((int) $existingIntent->receipt_id !== (int) $lockedReceipt->getKey() || ! $this->sameFileSet($existingIntent, $staged)) {
                        throw ValidationException::withMessages(['intent_key' => 'This archive request identity is already bound to a different report or file set.']);
                    }
                    return $existingIntent;
                }

                $latest = FinalDocumentArchiveManifest::query()->where('receipt_id', $lockedReceipt->getKey())->lockForUpdate()->latest('revision')->first();
                if ($latest) {
                    $latest->load('files');
                    if ($this->sameFileSet($latest, $staged)) return $latest;
                    if ($latest->archive_status === 'ARCHIVED') {
                        throw ValidationException::withMessages(['archive' => 'A verified final set is already published for this Regional receipt.']);
                    }
                    throw ValidationException::withMessages(['archive' => 'The signed file set is already bound to a recoverable archive attempt. Retry that exact set before requesting a different revision.']);
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
            $this->logArchivePhase('manifest_commit', $manifestCommitStartedAt, count($staged), array_sum(array_column($staged, 'size')));

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
        abort_unless($this->manifestIsVerifiedForRecord($record, (string) $manifest->source_type, $definition, $manifest), 404);
        return [$definition, $record];
    }

    /** Recheck and return a prior Dispatch copy only for its registered source, record, and official slot. */
    public function retrieveEarlierDispatchArchive(User $actor, DocumentArchive $candidate): array
    {
        $archive = DocumentArchive::query()->findOrFail($candidate->getKey());
        [$definition, $record] = $this->authorizedRecord($actor, (string) $archive->source_type, (int) $archive->source_id);
        $contract = $this->sourceContracts->forRegisteredSource((string) $archive->source_type, $record, $this->documents);
        abort_unless($contract && $this->isEarlierDispatchArchive($archive, (string) $archive->source_type, (int) $record->getKey(), (string) $contract['slot']), 404);

        return [
            'archive' => $archive,
            'stream' => $this->archiver->retrieveArchivedDocument($archive),
            'allowed_mime_types' => $this->sourceContracts->allowedMimeTypes((string) $archive->source_type),
        ];
    }

    /**
     * Return exact source/record identities with a database-verified Regional-return
     * manifest. Candidate rows come from Submission Tracking's already-scoped queues;
     * records, receipts, manifests, files, and routing events are loaded in batches.
     * This read path never contacts the archive provider.
     *
     * @param Collection<int,array<string,mixed>> $candidateRows
     * @return list<string>
     */
    public function verifiedFinalArchiveRecordKeys(User $actor, Collection $candidateRows): array
    {
        if (! $this->featureEnabled()
            || ! $actor->is_active || ! $actor->is_approved
            || (int) auth()->id() !== (int) $actor->getKey()
            || ! $this->canBrowse($actor)
            || $candidateRows->isEmpty()) return [];

        $idsBySource = $candidateRows->map(function (array $row): ?array {
            $source = (string) ($row['source'] ?? '');
            $id = filter_var($row['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $id !== false && $id !== null && in_array($source, $this->sourceContracts->sourceKeys(), true)
                ? ['source' => $source, 'id' => (int) $id]
                : null;
        })->filter()->groupBy('source')->map(fn (Collection $items) => $items->pluck('id')->unique()->values());
        if ($idsBySource->isEmpty()) return [];

        $receiptsByIdentity = collect();
        foreach ($idsBySource as $source => $ids) {
            foreach ($ids->chunk(400) as $chunk) {
                foreach (FinalDocumentReceipt::query()->where('source_type', $source)->whereIn('source_id', $chunk)->get() as $receipt) {
                    $receiptsByIdentity->put($source.':'.$receipt->source_id, $receipt);
                }
            }
        }
        if ($receiptsByIdentity->isEmpty()) return [];

        // Include every Regional-return revision so a newer pending/failed
        // manifest cannot be masked by an older archived revision.
        $manifestsByReceipt = collect();
        foreach ($receiptsByIdentity->pluck('id')->unique()->chunk(400) as $chunk) {
            foreach (FinalDocumentArchiveManifest::query()
                ->whereIn('receipt_id', $chunk)
                ->where('archive_phase', FinalDocumentArchiveManifest::PHASE)
                ->with('files.archive')
                ->orderBy('revision')
                ->get() as $manifest) {
                $manifestsByReceipt->put((int) $manifest->receipt_id, $manifestsByReceipt->get((int) $manifest->receipt_id, collect())->push($manifest));
            }
        }

        $latestCandidates = collect();
        foreach ($receiptsByIdentity as $identity => $receipt) {
            $manifests = $manifestsByReceipt->get((int) $receipt->getKey(), collect());
            $latest = $manifests->sortByDesc('revision')->first();
            if (! $latest || $latest->archive_status !== 'ARCHIVED') continue;
            $latest->setRelation('receipt', $receipt);
            $latestCandidates->put($identity, [
                'source' => (string) $receipt->source_type,
                'receipt' => $receipt,
                'manifest' => $latest,
            ]);
        }
        if ($latestCandidates->isEmpty()) return [];

        // Reapply live source and exact reader scope only to records that have
        // an archived final-manifest candidate, not every tracking row.
        $scopedCandidates = collect();
        foreach ($latestCandidates->groupBy('source') as $source => $items) {
            $definition = $this->tracking->source((string) $source);
            if (! $definition) continue;
            foreach ($items->pluck('receipt')->map(fn (FinalDocumentReceipt $receipt) => (int) $receipt->source_id)->unique()->chunk(400) as $chunk) {
                $query = $this->tracking->archiveSourceQuery((string) $source);
                if (! $query) continue;
                if ($source !== 'engp') $query->with('protectedArea');
                if ($source === 'conservation') $query->with('routingEvents');
                foreach ($query->whereIn($query->getModel()->getQualifiedKeyName(), $chunk)->get() as $record) {
                    if (! $this->routingAccess->canView($actor, $record, (string) $source, (string) $definition['ability'])) continue;
                    $identity = $source.':'.$record->getKey();
                    $candidate = $latestCandidates->get($identity);
                    if (! $candidate) continue;
                    $scopedCandidates->put($identity, [
                        ...$candidate, 'definition' => $definition, 'record' => $record,
                    ]);
                }
            }
        }
        $latestCandidates = $scopedCandidates;
        if ($latestCandidates->isEmpty()) return [];

        $moduleCodes = $latestCandidates->map(function (array $item): ?string {
            $resolver = $item['definition']['archive_module_code'] ?? null;
            return is_callable($resolver) ? (string) $resolver($item['record']) : null;
        })->filter()->unique()->values();
        $activeModuleCodes = ModuleDefinition::query()->active()->notRetired()
            ->whereIn('code', $moduleCodes)->pluck('code')->map(fn ($code) => (string) $code)->all();

        // Resolve current route evidence from one batched query per source chunk.
        $routingEventsByIdentity = [];
        foreach ($latestCandidates->groupBy('source') as $source => $items) {
            foreach ($items->pluck('record')->map(fn (Model $record) => (int) $record->getKey())->unique()->chunk(400) as $chunk) {
                $events = DocumentRoutingEvent::query()->where('source_type', $source)->whereIn('source_id', $chunk)
                    ->orderBy('occurred_at')->orderBy('id')->get();
                foreach ($events->groupBy(fn (DocumentRoutingEvent $event): string => $event->source_type.':'.$event->source_id) as $identity => $group) {
                    $routingEventsByIdentity[$identity] = $group;
                }
            }
        }

        $routeItems = $latestCandidates->map(fn (array $item): array => ['record' => $item['record'], 'key' => $item['source']])->values();
        $this->routing->primeRoutingPositions($routeItems, $routingEventsByIdentity);
        try {
            $verified = [];
            $receivingOfficeCache = [];
            foreach ($latestCandidates as $identity => $item) {
                $moduleCode = is_callable($item['definition']['archive_module_code'] ?? null)
                    ? (string) $item['definition']['archive_module_code']($item['record'])
                    : null;
                if ($moduleCode === null || ! in_array($moduleCode, $activeModuleCodes, true)) continue;
                $officeAttribute = $item['source'] === 'engp'
                    ? 'office'
                    : ($item['definition']['archive_office_attribute'] ?? $item['definition']['target_office'] ?? 'target_office');
                $officeCacheKey = implode(':', [
                    $item['source'],
                    (string) ($item['record']->getAttribute('protected_area_id') ?? ''),
                    mb_strtolower(trim((string) $item['record']->getAttribute($officeAttribute))),
                ]);
                if (! array_key_exists($officeCacheKey, $receivingOfficeCache)) {
                    $receivingOfficeCache[$officeCacheKey] = $this->receivingOfficeFor($item['record'], $item['source'], $item['definition']);
                }
                $expectedOffice = $receivingOfficeCache[$officeCacheKey];
                if (! $expectedOffice) continue;
                $events = $routingEventsByIdentity[$identity] ?? collect();
                if ($this->manifestIsVerifiedForRecord(
                    $item['record'], $item['source'], $item['definition'], $item['manifest'], $events, $expectedOffice,
                )) $verified[] = $identity;
            }
            return $verified;
        } finally {
            $this->routing->clearPrimedRoutingPositions();
        }
    }

    /** @param array<string,mixed> $definition */
    private function manifestIsVerifiedForRecord(
        Model $record,
        string $source,
        array $definition,
        FinalDocumentArchiveManifest $manifest,
        ?Collection $routingEvents = null,
        ?string $resolvedReceivingOffice = null,
    ): bool {
        $receipt = $manifest->receipt;
        if (! ($manifest->archive_phase === FinalDocumentArchiveManifest::PHASE
            && $manifest->archive_status === 'ARCHIVED'
            && $manifest->finalized_at !== null
            && $manifest->signature_confirmation
            && (string) $manifest->source_type === $source
            && (int) $manifest->source_id === (int) $record->getKey()
            && $manifest->workflow_key === (string) $record->getAttribute('workflow_key')
            && $receipt
            && (string) $receipt->source_type === $source
            && (int) $receipt->source_id === (int) $record->getKey()
            && (string) $receipt->workflow_key === (string) $manifest->workflow_key
            && $receipt->receiving_category === OrganizationalAccessService::PENRO_FOCAL)) return false;
        $expectedOffice = $resolvedReceivingOffice ?? $this->receivingOfficeFor($record, $source, $definition);
        if (! $this->sameOffice($receipt->receiving_office, $expectedOffice)) return false;
        $release = $this->releaseEvidence($record, $source, $routingEvents);
        if (! $release
            || $receipt->release_evidence_key !== $release['key']
            || $receipt->release_evidence_type !== $release['type']) return false;
        $manifest->loadMissing('files');
        if (! $this->manifestMatchesSourceContract($manifest, $record) || $manifest->files->isEmpty()) return false;

        foreach ($manifest->files as $file) {
            $archive = $file->archive;
            $expectedSlot = 'regional-return-final:receipt-'.$manifest->receipt_id.':r'.$manifest->revision.':'.$file->logical_identity;
            if ($file->file_status !== 'ARCHIVED' || ! $archive
                || (string) $archive->source_type !== $source
                || (int) $archive->source_id !== (int) $record->getKey()
                || $archive->logical_slot !== $expectedSlot
                || $archive->archive_phase !== FinalDocumentArchiveManifest::PHASE
                || $archive->archive_status !== 'ARCHIVED'
                || $archive->remote_availability !== 'verified'
                || $archive->archived_sha256 !== $file->sha256
                || (int) $archive->archived_size !== (int) $file->file_size) return false;
        }

        return true;
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
        $claimStartedAt = hrtime(true);
        $claim = DB::transaction(function () use ($manifestId, $token): string {
            $manifest = FinalDocumentArchiveManifest::query()->lockForUpdate()->findOrFail($manifestId);
            if ($manifest->archive_status === 'ARCHIVED') return 'archived';
            if ($this->manifestClaimIsActive($manifest)) return 'processing';
            $manifest->forceFill(['archive_status' => 'PROCESSING', 'claim_token' => $token, 'claimed_at' => now(), 'last_error' => null])->saveOrFail();
            return 'claimed';
        }, 3);
        $this->logArchivePhase('manifest_claim', $claimStartedAt, 0, 0);
        if ($claim !== 'claimed') return ['status' => $claim, 'manifest_id' => $manifestId];

        $providerFailure = [];
        $verifiedParts = [];
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

            $verificationBinding = [
                'request_id' => ArchiveRequestTrace::currentId(),
                'manifest_id' => (int) $manifest->getKey(),
                'revision' => (int) $manifest->revision,
                'receipt_id' => (int) $manifest->receipt_id,
                'source_type' => (string) $manifest->source_type,
                'source_id' => (int) $manifest->source_id,
                'workflow_key' => (string) $manifest->workflow_key,
                'release_evidence_type' => (string) $manifest->receipt->release_evidence_type,
                'release_evidence_key' => (string) $manifest->receipt->release_evidence_key,
                'signature_set_sha256' => (string) $manifest->signature_set_sha256,
                'signature_confirmed_by' => (int) $manifest->signature_confirmed_by,
                'signature_confirmed_at' => $manifest->signature_confirmed_at?->toIso8601String(),
                'manifest_claim_token' => $token,
            ];

            $archiveUnit = (string) ($sourceDefinition['archive_unit'] ?? '');
            $archiveOffice = $this->archiveOffices->resolveRegionalReturn($sourceDefinition, $record);
            $primaryExtension = strtolower(pathinfo((string) $manifest->files->first()?->original_filename, PATHINFO_EXTENSION)) ?: 'pdf';
            $this->archivePaths->build($module, $archiveUnit, $archiveOffice, (string) $record->getKey(), $primaryExtension, true);

            foreach ($manifest->files as $file) {
                $alreadyVerified = $file->file_status === 'ARCHIVED'
                    && $file->archive
                    && $this->archiver->verifyArchivedDocument($file->archive);
                if ($alreadyVerified) {
                    $attestation = $this->verifiedPartAttestation($verificationBinding, $file, $file->archive, null);
                    if ($attestation === null) throw new \RuntimeException('An existing archived part did not match its current manifest identity.');
                    $verifiedParts[(int) $file->getKey()] = $attestation;
                    continue;
                }
                if (! Storage::disk('local')->exists($file->staged_path)
                    || hash_file('sha256', Storage::disk('local')->path($file->staged_path)) !== $file->sha256) {
                    throw new \RuntimeException('A staged final copy is missing or no longer matches its manifest hash.');
                }
                $fileToken = (string) Str::uuid();
                $canClaim = DB::transaction(function () use ($file, $fileToken, $alreadyVerified): bool {
                    $locked = FinalDocumentArchiveFile::query()->lockForUpdate()->findOrFail($file->getKey());
                    if ($locked->file_status === 'ARCHIVED' && $alreadyVerified) return false;
                    if ($locked->file_status === 'UPLOADING'
                        && filled($locked->claim_token)
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
                    function (Model $locked) use ($actor, $manifest, $token, $file, $fileToken): void {
                        DB::transaction(function () use ($manifest, $token, $file, $fileToken): void {
                            $currentManifest = FinalDocumentArchiveManifest::query()->lockForUpdate()->findOrFail($manifest->getKey());
                            abort_unless($currentManifest->archive_status === 'PROCESSING'
                                && $currentManifest->claim_token === $token, 409);
                            $currentFile = FinalDocumentArchiveFile::query()->lockForUpdate()->findOrFail($file->getKey());
                            abort_unless($currentFile->file_status === 'UPLOADING'
                                && $currentFile->claim_token === $fileToken, 409);
                            $heartbeat = now();
                            $currentManifest->forceFill(['claimed_at' => $heartbeat])->saveOrFail();
                            $currentFile->forceFill(['claimed_at' => $heartbeat])->saveOrFail();
                        }, 3);
                        $this->assertFinalReceiptWriter($actor, $locked, (string) $manifest->source_type);
                        $currentManifest = FinalDocumentArchiveManifest::query()->findOrFail($manifest->getKey());
                        abort_unless($currentManifest->archive_phase === FinalDocumentArchiveManifest::PHASE
                            && $currentManifest->archive_status === 'PROCESSING'
                            && $currentManifest->claim_token === $token
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
                    if (in_array($result['oauth_error_category'] ?? null, [
                        'invalid_request', 'invalid_client', 'invalid_grant', 'unauthorized_client',
                        'unsupported_grant_type', 'invalid_scope', 'deleted_client', 'admin_policy_enforced',
                        'invalid_dpop_proof', 'use_dpop_nonce',
                    ], true)) {
                        $providerFailure['oauth_error_category'] = $result['oauth_error_category'];
                        if (($result['oauth_error_subtype'] ?? null) === 'invalid_rapt'
                            && $result['oauth_error_category'] === 'invalid_grant') {
                            $providerFailure['oauth_error_subtype'] = 'invalid_rapt';
                        }
                    }
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
                $freshFile = FinalDocumentArchiveFile::query()->with('archive')->findOrFail($file->getKey());
                $attestation = $this->verifiedPartAttestation($verificationBinding, $freshFile, $freshFile->archive, $fileToken);
                if ($attestation === null) throw new \RuntimeException('The persisted archive part did not match its verified manifest identity.');
                $verifiedParts[(int) $freshFile->getKey()] = $attestation;
            }

            $finalizationStartedAt = hrtime(true);
            $verifiedPartCount = count($verifiedParts);
            $verifiedByteCount = array_sum(array_map(static fn (array $part): int => (int) $part['file_size'], $verifiedParts));
            DB::transaction(function () use ($manifestId, $token, $actor, $record, $sourceDefinition, $verificationBinding, $verifiedParts): void {
                $lockedRecord = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
                $current = FinalDocumentArchiveManifest::query()->lockForUpdate()->findOrFail($manifestId);
                abort_unless($current->archive_status === 'PROCESSING' && $current->claim_token === $token, 409);
                $this->assertFinalReceiptWriter($actor, $lockedRecord, (string) $current->source_type);
                $lockedReceipt = FinalDocumentReceipt::query()->whereKey($current->receipt_id)->lockForUpdate()->firstOrFail();
                $current->setRelation('receipt', $lockedReceipt);
                $this->assertReceiptStillMatchesRelease($lockedRecord, (string) $current->source_type, $lockedReceipt);
                $currentRelease = $this->releaseEvidence($lockedRecord, (string) $current->source_type);
                abort_unless($currentRelease
                    && $currentRelease['key'] === $verificationBinding['release_evidence_key']
                    && $currentRelease['type'] === $verificationBinding['release_evidence_type'], 409);
                abort_unless((int) $current->getKey() === $verificationBinding['manifest_id']
                    && (int) $current->revision === $verificationBinding['revision']
                    && (int) $current->receipt_id === $verificationBinding['receipt_id']
                    && $current->source_type === $verificationBinding['source_type']
                    && (int) $current->source_id === $verificationBinding['source_id']
                    && (string) $current->workflow_key === $verificationBinding['workflow_key']
                    && $lockedReceipt->release_evidence_type === $verificationBinding['release_evidence_type']
                    && $lockedReceipt->release_evidence_key === $verificationBinding['release_evidence_key']
                    && (string) $current->signature_set_sha256 === $verificationBinding['signature_set_sha256']
                    && (int) $current->signature_confirmed_by === $verificationBinding['signature_confirmed_by']
                    && $current->signature_confirmed_at?->toIso8601String() === $verificationBinding['signature_confirmed_at'], 409);
                abort_unless($current->archive_phase === FinalDocumentArchiveManifest::PHASE
                    && $current->signature_confirmation
                    && (int) $current->signature_confirmed_by === (int) $actor->getKey(), 409);

                $currentFiles = $current->files()->orderBy('ordinal')->lockForUpdate()->get();
                abort_unless($currentFiles->isNotEmpty() && $currentFiles->count() === count($verifiedParts), 409);
                $currentSetHash = $this->signatureSetHash($currentFiles->map(fn (FinalDocumentArchiveFile $file): array => [
                    'identity' => (string) $file->logical_identity,
                    'ordinal' => (int) $file->ordinal,
                    'filename' => (string) $file->original_filename,
                    'mime' => (string) $file->mime_type,
                    'size' => (int) $file->file_size,
                    'sha256' => (string) $file->sha256,
                ])->all());
                abort_unless(hash_equals($verificationBinding['signature_set_sha256'], $currentSetHash), 409);
                $archiveIds = $currentFiles->pluck('document_archive_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
                abort_unless($archiveIds->count() === $currentFiles->count(), 409);
                $currentArchives = DocumentArchive::query()->whereIn('id', $archiveIds)->lockForUpdate()->get()->keyBy(fn (DocumentArchive $archive) => (int) $archive->getKey());
                abort_unless($currentArchives->count() === $currentFiles->count(), 409);
                foreach ($currentFiles as $currentFile) {
                    $evidence = $verifiedParts[(int) $currentFile->getKey()] ?? null;
                    $currentArchive = $currentArchives->get((int) $currentFile->document_archive_id);
                    abort_unless($evidence !== null && $currentArchive instanceof DocumentArchive
                        && $this->attestationMatches($verificationBinding, $currentFile, $currentArchive, $evidence), 409);
                }

                $contract = $this->sourceContracts->forRegisteredSource((string) $current->source_type, $lockedRecord, $this->documents);
                $primary = $current->files()->orderBy('ordinal')->firstOrFail();
                if (! $contract) throw new \RuntimeException('The source file contract is no longer available for current-copy promotion.');
                $adapter = $this->documents->resolve($contract['attachment_source'], $lockedRecord, $contract['slot']);
                $this->currentDocuments->adoptStoredUsingAdapter(
                    $lockedRecord,
                    $contract['attachment_source'],
                    $contract['slot'],
                    (string) $primary->staged_path,
                    (string) $primary->original_filename,
                    (string) $primary->sha256,
                    (int) $primary->file_size,
                    $adapter,
                    (int) $actor->getKey(),
                    fn (Model $sourceRecord) => $this->assertFinalReceiptWriter($actor, $sourceRecord, (string) $current->source_type),
                );
                $current->forceFill(['archive_status' => 'ARCHIVED', 'finalized_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'last_error' => null])->saveOrFail();
            }, 3);
            $this->logArchivePhase('manifest_finalization', $finalizationStartedAt, $verifiedPartCount, $verifiedByteCount);

            return ['status' => 'archived', 'manifest_id' => $manifestId];
        } catch (Throwable $exception) {
            $failureState = DB::transaction(function () use ($manifestId, $token): string {
                $manifest = FinalDocumentArchiveManifest::query()->lockForUpdate()->find($manifestId);
                if (! $manifest) return 'failed';
                if ($manifest->archive_status === 'ARCHIVED') return 'archived';
                if ($manifest->claim_token !== $token) {
                    if ($this->manifestClaimIsActive($manifest)) return 'processing';
                    return 'failed';
                }
                $manifest->forceFill(['archive_status' => 'FAILED', 'claim_token' => null, 'claimed_at' => null, 'last_error' => 'Archive incomplete; staged files and verified provider objects were retained for retry.'])->save();
                return 'failed';
            }, 3);
            if ($failureState === 'archived') return ['status' => 'archived', 'manifest_id' => $manifestId];
            if ($failureState === 'processing') return ['status' => 'processing', 'manifest_id' => $manifestId];
            report($exception);
            return ['status' => 'failed', 'manifest_id' => $manifestId, ...$providerFailure];
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

    private function finalActionUnavailableReason(
        User $actor,
        Model $record,
        string $source,
        array $sourceDefinition,
        ?array $release,
        ?FinalDocumentReceipt $receipt,
        bool $receiptMatches,
        ?FinalDocumentArchiveManifest $latestManifest,
        bool $writer,
    ): ?string {
        if ($latestManifest?->archive_status === 'ARCHIVED' && $receiptMatches) {
            return 'This report already has a verified final archive. No additional receipt or upload is available.';
        }

        if (! $writer) {
            return $this->finalWriterEligibilityReason($actor, $record, $source, $sourceDefinition);
        }

        if ($receipt !== null && ! $receiptMatches) {
            return 'The saved final receipt does not match the current Regional release and receiving office. Receipt and archive actions are blocked pending records review.';
        }

        if ($release === null) {
            return 'A verified PENRO Records release to the Regional Office is required before recording the final physical receipt.';
        }

        if ($receipt === null) return null;

        return match ($latestManifest?->archive_status) {
            'PROCESSING' => 'Archive verification is currently claimed by an active attempt. Retry Archive can resume it after that claim expires.',
            'PENDING', 'FAILED' => 'The final archive is incomplete. Use Retry Archive to resume the retained file set.',
            default => null,
        };
    }

    private function finalWriterEligibilityReason(User $actor, Model $record, string $source, array $sourceDefinition): string
    {
        if (! $actor->is_active) return 'This account is inactive and cannot record or archive Regional-return files.';
        if (! $actor->is_approved) return 'This account is not approved to record or archive Regional-return files.';
        if ($this->organization->isGlobal($actor)) return 'Receipt and archive writes are limited to a non-global PENRO CDS Focal account.';
        if ($this->organization->effectiveCategory($actor) !== OrganizationalAccessService::PENRO_FOCAL) {
            return 'Receipt and archive writes require the PENRO CDS Focal account category.';
        }
        if ($actor->protected_area_id !== null) {
            return 'The PENRO CDS Focal receiving account must not have a Protected Area assignment.';
        }

        $actorOffice = $this->organization->normalizeOffice($actor->office_designated);
        if (! is_string($actorOffice) || ! in_array($actorOffice, $this->organization->penroOffices(), true)) {
            return 'This account is not assigned to a recognized PENRO receiving office.';
        }
        if (! $this->organization->hasSubmissionTrackingScope($actor)) {
            return 'This account has no active Submission Tracking source scope.';
        }

        $expectedOffice = $this->receivingOfficeFor($record, $source, $sourceDefinition);
        if ($expectedOffice === null) return 'The report\'s Protected Area and supervising-office assignment do not resolve to an eligible Regional-return PENRO under the current jurisdiction rule. A records review is needed before a receipt can be recorded.';
        if (! $this->sameOffice($actorOffice, $expectedOffice)) {
            return 'This report is assigned to '.$expectedOffice.' for final receipt; the signed-in account is assigned to '.$actorOffice.'.';
        }

        return 'This account is not authorized to act on this report source within its current Submission Tracking scope.';
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
    private function releaseEvidence(Model $record, string $source, ?Collection $routingEvents = null): ?array
    {
        $state = $this->routing->state($record, $source, $routingEvents);
        if (($state['stage'] ?? null) !== DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return null;
        $last = $state['events']->last();
        if (! $last || $last->to_stage !== DocumentRoutingProfileRegistry::RELEASED_REGIONAL || $last->event_key !== 'released') return null;
        $legacyId = data_get($last->metadata, 'legacy_pamb_event_id');
        if ($legacyId !== null && $record instanceof \App\Models\ConservationReportSubmission && $source === 'conservation') {
            $legacy = $record->relationLoaded('routingEvents')
                ? $record->routingEvents->first(fn (PambRoutingEvent $event): bool => (int) $event->getKey() === (int) $legacyId)
                : PambRoutingEvent::query()->whereKey((int) $legacyId)->where('conservation_report_submission_id', $record->getKey())->first();
            if (! $legacy
                || (string) $legacy->workflow_key !== (string) $record->getAttribute('workflow_key')
                || app(PambRoutingTimelineService::class)->canonicalStageKey((string) $legacy->stage_key) !== PambRoutingTimelineService::RELEASED_TO_REGIONAL) return null;
            return ['type' => 'pamb_routing_events', 'key' => 'pamb_routing_events:'.$legacy->getKey(), 'occurred_at' => $legacy->occurred_at?->toIso8601String()];
        }
        $matchesReleaseEvent = fn (DocumentRoutingEvent $event): bool =>
            (int) $event->getKey() === (int) $last->getKey()
            && $event->source_type === $source && (int) $event->source_id === (int) $record->getKey()
            && $event->event_key === 'released'
            && $event->from_stage === DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL
            && $event->to_stage === DocumentRoutingProfileRegistry::RELEASED_REGIONAL
            && $event->from_office === 'PENRO Records Unit'
            && $event->to_office === 'Regional Office'
            && data_get($event->metadata, 'action_key') === 'release_to_regional';
        $persisted = $routingEvents !== null
            ? $routingEvents->first(fn (mixed $event): bool => $event instanceof DocumentRoutingEvent && $matchesReleaseEvent($event))
            : DocumentRoutingEvent::query()->whereKey($last->getKey())
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

    private function physicalReceiptTime(?string $value): CarbonImmutable
    {
        if ($value === null || trim($value) === '') return CarbonImmutable::now('Asia/Manila');
        try {
            return CarbonImmutable::parse($value, 'Asia/Manila');
        } catch (Throwable) {
            throw ValidationException::withMessages(['physically_received_at' => 'Enter the actual received date and time in Asia/Manila.']);
        }
    }

    /** @param array{type:string,key:string,occurred_at:?string} $release */
    private function assertReceiptTimeAfterRelease(CarbonImmutable $physical, array $release): void
    {
        if ($release['occurred_at'] && $physical->utc()->lt(CarbonImmutable::parse($release['occurred_at'])->utc())) {
            throw ValidationException::withMessages([
                'physically_received_at' => 'The physical receipt time cannot precede the recorded Regional release. Choose a time at or after the release shown in Asia/Manila time.',
            ]);
        }
    }

    private function receivingOfficeFor(Model $record, string $source, array $sourceDefinition): ?string
    {
        return $this->receivingOffices->resolve($source, $record, $sourceDefinition);
    }

    /** Keep captured PAMO provenance distinct from a supervising/receiving CENRO. */
    private function originOfficeContext(Model $record, string $source, array $sourceDefinition): array
    {
        $fallback = $record->getAttribute($sourceDefinition['archive_office_attribute'] ?? $sourceDefinition['target_office'] ?? 'target_office');
        if (! $this->pamoOrigins->isOriginSchemaReady()) {
            return ['origin_kind' => null, 'originating_office' => $fallback, 'supervising_office' => null];
        }

        $snapshot = SubmissionRoutingSnapshot::query()
            ->where('source_key', $source)->where('source_id', $record->getKey())
            ->latest('id')->first();
        $originOffice = trim((string) $snapshot?->pamo_office_name);
        if (! $snapshot || $snapshot->origin_kind !== 'pamo_pamb'
            || ! in_array($snapshot->profile, ['pamo_regular', 'pamo_direct'], true)
            || (int) $snapshot->protected_area_id !== (int) $record->getAttribute('protected_area_id')
            || (int) $snapshot->pamo_office_id < 1 || $originOffice === '') {
            return ['origin_kind' => null, 'originating_office' => $fallback, 'supervising_office' => null];
        }

        return [
            'origin_kind' => 'pamo_pamb',
            'originating_office' => $originOffice,
            'supervising_office' => filled($snapshot->responsible_cenro_name) ? (string) $snapshot->responsible_cenro_name : null,
        ];
    }

    /** Build a read-only audit projection for this authorized archive detail. */
    private function custodyTimeline(string $source, int $recordId, ?FinalDocumentReceipt $receipt): array
    {
        $timeline = [];
        $routeEvents = DocumentRoutingEvent::query()
            ->where('source_type', $source)->where('source_id', $recordId)
            ->with('recordedBy:id,name')->orderBy('occurred_at')->orderBy('id')->get();
        foreach ($routeEvents as $event) {
            $eventName = match ($event->event_key) {
                'released' => 'Released to Regional Office',
                'returned_for_correction' => 'Returned for correction',
                'correction_received' => 'Correction received',
                default => Str::headline(str_replace('_', ' ', (string) $event->event_key)),
            };
            $timeline[] = [
                'type' => 'routing', 'id' => (int) $event->getKey(), 'label' => $eventName,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'actor' => $event->recordedBy?->name,
                'office' => trim(implode(' → ', array_filter([$event->from_office, $event->to_office]))),
                'details' => filled($event->remarks) ? (string) $event->remarks : null,
                '_sort' => $event->occurred_at?->getTimestamp() ?? 0,
            ];
        }

        if ($source === 'conservation' && Schema::hasTable('pamb_routing_events')) {
            $legacyEvents = PambRoutingEvent::query()
                ->where('conservation_report_submission_id', $recordId)
                ->with('recordedBy:id,name')->orderBy('occurred_at')->orderBy('id')->get();
            foreach ($legacyEvents as $event) {
                $timeline[] = [
                    'type' => 'pamb-routing', 'id' => (int) $event->getKey(),
                    'label' => 'PAMO / PAMB · '.Str::headline(str_replace('_', ' ', (string) $event->stage_key)),
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'actor' => $event->recordedBy?->name, 'office' => null,
                    'details' => filled($event->remarks) ? (string) $event->remarks : null,
                    '_sort' => $event->occurred_at?->getTimestamp() ?? 0,
                ];
            }
        }

        if ($receipt) {
            $receivedBy = User::query()->whereKey($receipt->received_by)->value('name');
            $timeline[] = [
                'type' => 'receipt', 'id' => (int) $receipt->getKey(), 'label' => 'Final documents physically received',
                'occurred_at' => $receipt->physically_received_at?->toIso8601String(),
                'actor' => $receivedBy, 'office' => $receipt->receiving_office,
                'details' => 'Receipt recorded '.($receipt->recorded_at?->toIso8601String() ?? 'at an unknown time').'.',
                '_sort' => $receipt->physically_received_at?->getTimestamp() ?? 0,
            ];

            $manifests = $receipt->manifests()->with('files')->orderBy('revision')->get();
            $actorNames = User::query()->whereIn('id', $manifests->pluck('signature_confirmed_by')->filter()->unique())->pluck('name', 'id');
            foreach ($manifests as $manifest) {
                if ($manifest->signature_confirmed_at) {
                    $timeline[] = [
                        'type' => 'archive-submission', 'id' => (int) $manifest->getKey(), 'label' => 'Signed set submitted for archive',
                        'occurred_at' => $manifest->signature_confirmed_at->toIso8601String(),
                        'actor' => $actorNames->get($manifest->signature_confirmed_by), 'office' => null,
                        'details' => 'Revision '.$manifest->revision.' · '.$manifest->files->count().' file(s).',
                        '_sort' => $manifest->signature_confirmed_at->getTimestamp(),
                    ];
                }
                foreach ($manifest->files as $file) {
                    if ($file->file_status !== 'ARCHIVED' || ! $file->verified_at) continue;
                    $timeline[] = [
                        'type' => 'archive-file', 'id' => (int) $file->getKey(), 'label' => 'Archive file verified',
                        'occurred_at' => $file->verified_at->toIso8601String(),
                        'actor' => null, 'office' => null,
                        'details' => (string) $file->original_filename,
                        '_sort' => $file->verified_at->getTimestamp(),
                    ];
                }
                if ($manifest->finalized_at) {
                    $timeline[] = [
                        'type' => 'archive-finalized', 'id' => (int) $manifest->getKey(), 'label' => 'Final archive verified and finalized',
                        'occurred_at' => $manifest->finalized_at->toIso8601String(),
                        'actor' => $actorNames->get($manifest->signature_confirmed_by), 'office' => null,
                        'details' => 'Revision '.$manifest->revision.'.',
                        '_sort' => $manifest->finalized_at->getTimestamp(),
                    ];
                } elseif (in_array($manifest->archive_status, ['FAILED', 'PROCESSING'], true)) {
                    $timeline[] = [
                        'type' => 'archive-status', 'id' => (int) $manifest->getKey(),
                        'label' => 'Archive status: '.Str::lower((string) $manifest->archive_status),
                        'occurred_at' => $manifest->updated_at?->toIso8601String(),
                        'actor' => null, 'office' => null,
                        'details' => 'Revision '.$manifest->revision.' remains available for retry.',
                        '_sort' => $manifest->updated_at?->getTimestamp() ?? 0,
                    ];
                }
            }
        }

        usort($timeline, static fn (array $left, array $right): int => ($left['_sort'] <=> $right['_sort']) ?: strcmp($left['type'].$left['id'], $right['type'].$right['id']));
        return array_map(static function (array $event): array {
            unset($event['_sort']);
            return $event;
        }, $timeline);
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

    private function earlierDispatchCopyData(string $source, Model $record, ?array $contract): ?array
    {
        if (! $contract) return null;

        $archive = DocumentArchive::query()
            ->where('source_type', $source)
            ->where('source_id', $record->getKey())
            ->where('logical_slot', $contract['slot'])
            ->where('archive_phase', 'dispatch_checkpoint')
            ->where('archive_status', 'ARCHIVED')
            ->orderByDesc('archived_at')->orderByDesc('id')
            ->first();
        if (! $archive || ! $this->isEarlierDispatchArchive($archive, $source, (int) $record->getKey(), (string) $contract['slot'])) return null;

        $filename = trim((string) $archive->original_filename) ?: 'Earlier archived copy';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return [
            'archive_id' => (int) $archive->getKey(),
            'archived_at' => $archive->archived_at?->toIso8601String(),
            'availability_note' => 'Availability is rechecked when the earlier copy is opened.',
            'file' => [
                'id' => (int) $archive->getKey(),
                'name' => $filename,
                'mime_type' => $mimeType,
                'size' => (int) $archive->archived_size,
                'status' => 'ARCHIVED',
                'preview_url' => route('final-archives.dispatch-files.show', $archive->getKey()).'?preview=1',
                'download_url' => route('final-archives.dispatch-files.show', $archive->getKey()).'?download=1',
            ],
        ];
    }

    private function isEarlierDispatchArchive(DocumentArchive $archive, string $source, int $recordId, string $slot): bool
    {
        return $archive->source_type === $source
            && (int) $archive->source_id === $recordId
            && $archive->logical_slot === $slot
            && $archive->archive_phase === 'dispatch_checkpoint'
            && $archive->archive_status === 'ARCHIVED'
            && filled($archive->google_drive_file_id)
            && is_string($archive->archived_sha256)
            && preg_match('/\A[a-f0-9]{64}\z/i', $archive->archived_sha256) === 1
            && (int) $archive->archived_size > 0;
    }

    /**
     * Capture a provider verification performed in this claimed request. The
     * final transaction rechecks every database identity field against this
     * request-local proof before promoting the current private document.
     */
    private function verifiedPartAttestation(
        array $binding,
        FinalDocumentArchiveFile $file,
        ?DocumentArchive $archive,
        ?string $fileClaimToken,
    ): ?array {
        if (! $archive
            || $file->file_status !== 'ARCHIVED'
            || (int) $file->manifest_id !== (int) $binding['manifest_id']
            || ! $file->verified_at
            || ! $file->logical_identity
            || ! preg_match('/\A[a-f0-9]{64}\z/i', (string) $file->sha256)
            || (int) $file->file_size < 1
            || (int) $file->document_archive_id !== (int) $archive->getKey()
            || $archive->source_type !== $binding['source_type']
            || (int) $archive->source_id !== (int) $binding['source_id']
            || $archive->archive_phase !== FinalDocumentArchiveManifest::PHASE
            || $archive->archive_status !== 'ARCHIVED'
            || $archive->remote_availability !== 'verified'
            || ! $archive->last_verified_at
            || ! filled($archive->google_drive_file_id)
            || ! hash_equals((string) $file->sha256, (string) $archive->archived_sha256)
            || (int) $file->file_size !== (int) $archive->archived_size) {
            return null;
        }

        $expectedSlot = 'regional-return-final:receipt-'.$binding['receipt_id'].':r'.$binding['revision'].':'.$file->logical_identity;
        if ($archive->logical_slot !== $expectedSlot) return null;

        return [
            ...$binding,
            'file_id' => (int) $file->getKey(),
            'file_claim_token' => $fileClaimToken,
            'logical_identity' => (string) $file->logical_identity,
            'ordinal' => (int) $file->ordinal,
            'original_filename' => (string) $file->original_filename,
            'mime_type' => (string) $file->mime_type,
            'staged_path' => (string) $file->staged_path,
            'file_sha256' => (string) $file->sha256,
            'file_size' => (int) $file->file_size,
            'file_verified_at' => $file->verified_at->toIso8601String(),
            'archive_id' => (int) $archive->getKey(),
            'archive_slot' => (string) $archive->logical_slot,
            'provider_file_id' => (string) $archive->google_drive_file_id,
            'archive_sha256' => (string) $archive->archived_sha256,
            'archive_size' => (int) $archive->archived_size,
            'archive_verified_at' => $archive->last_verified_at->toIso8601String(),
        ];
    }

    private function attestationMatches(
        array $binding,
        FinalDocumentArchiveFile $file,
        DocumentArchive $archive,
        array $evidence,
    ): bool {
        foreach ([
            'request_id', 'manifest_id', 'revision', 'receipt_id', 'source_type', 'source_id',
            'workflow_key', 'release_evidence_type', 'release_evidence_key', 'signature_set_sha256',
            'signature_confirmed_by', 'signature_confirmed_at', 'manifest_claim_token',
        ] as $key) {
            if (($evidence[$key] ?? null) !== ($binding[$key] ?? null)) return false;
        }

        return ($evidence['file_id'] ?? null) === (int) $file->getKey()
            && ($evidence['logical_identity'] ?? null) === (string) $file->logical_identity
            && ($evidence['ordinal'] ?? null) === (int) $file->ordinal
            && ($evidence['original_filename'] ?? null) === (string) $file->original_filename
            && ($evidence['mime_type'] ?? null) === (string) $file->mime_type
            && ($evidence['staged_path'] ?? null) === (string) $file->staged_path
            && ($evidence['file_sha256'] ?? null) === (string) $file->sha256
            && ($evidence['file_size'] ?? null) === (int) $file->file_size
            && ($evidence['file_verified_at'] ?? null) === $file->verified_at?->toIso8601String()
            && $file->file_status === 'ARCHIVED'
            && $file->claim_token === null
            && $file->claimed_at === null
            && ($evidence['archive_id'] ?? null) === (int) $archive->getKey()
            && ($evidence['archive_slot'] ?? null) === (string) $archive->logical_slot
            && ($evidence['provider_file_id'] ?? null) === (string) $archive->google_drive_file_id
            && ($evidence['archive_sha256'] ?? null) === (string) $archive->archived_sha256
            && ($evidence['archive_size'] ?? null) === (int) $archive->archived_size
            && ($evidence['archive_verified_at'] ?? null) === $archive->last_verified_at?->toIso8601String()
            && $archive->source_type === $binding['source_type']
            && (int) $archive->source_id === (int) $binding['source_id']
            && $archive->archive_phase === FinalDocumentArchiveManifest::PHASE
            && $archive->archive_status === 'ARCHIVED'
            && $archive->remote_availability === 'verified'
            && $file->document_archive_id !== null
            && (int) $file->document_archive_id === (int) $archive->getKey()
            && hash_equals((string) $file->sha256, (string) $archive->archived_sha256)
            && (int) $file->file_size === (int) $archive->archived_size;
    }

    private function logArchivePhase(string $phase, int $startedAt, int $partCount, int $byteCount): void
    {
        Log::debug('Regional-return archive phase completed.', [
            'request_id' => ArchiveRequestTrace::currentId(),
            'phase' => $phase,
            'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
            'part_count' => max(0, $partCount),
            'byte_count' => max(0, $byteCount),
        ]);
    }

    private function manifestClaimIsActive(FinalDocumentArchiveManifest $manifest): bool
    {
        return $manifest->archive_status === 'PROCESSING'
            && filled($manifest->claim_token)
            && $manifest->claimed_at !== null
            && $manifest->claimed_at->gt(now()->subMinutes(self::CLAIM_TTL_MINUTES));
    }

    private function manifestData(FinalDocumentArchiveManifest $manifest, bool $verified): array
    {
        $claimActive = $this->manifestClaimIsActive($manifest);
        $retryAvailable = in_array($manifest->archive_status, ['PENDING', 'FAILED'], true)
            || ($manifest->archive_status === 'PROCESSING' && ! $claimActive);

        return [
            'id' => (int) $manifest->getKey(),
            'revision' => (int) $manifest->revision,
            'status' => $manifest->archive_status,
            'finalized_at' => $manifest->finalized_at?->toIso8601String(),
            'processing_claim_active' => $claimActive,
            'retry_available' => $retryAvailable,
            'signature_confirmed_at' => $manifest->signature_confirmed_at?->toIso8601String(),
            'files' => $manifest->files->map(function (FinalDocumentArchiveFile $file) use ($manifest, $verified): array {
                $archive = $file->archive;
                $available = $verified
                    && $manifest->finalized_at !== null
                    && $file->file_status === 'ARCHIVED'
                    && $archive
                    && $archive->archive_phase === FinalDocumentArchiveManifest::PHASE
                    && $archive->archive_status === 'ARCHIVED'
                    && $archive->remote_availability === 'verified'
                    && hash_equals((string) $file->sha256, (string) $archive->archived_sha256)
                    && (int) $file->file_size === (int) $archive->archived_size;

                return [
                    'id' => (int) $file->getKey(),
                    'name' => $file->original_filename,
                    'mime_type' => $file->mime_type,
                    'size' => (int) $file->file_size,
                    'status' => $file->file_status,
                    'preview_url' => $available ? route('final-archives.files.show', $file->getKey()).'?preview=1' : null,
                    'download_url' => $available ? route('final-archives.files.show', $file->getKey()).'?download=1' : null,
                ];
            })->all(),
        ];
    }

    private function receiptData(FinalDocumentReceipt $receipt): array
    {
        return [
            'id' => (int) $receipt->getKey(),
            'physically_received_at' => $receipt->physically_received_at?->toIso8601String(),
            'recorded_at' => $receipt->recorded_at?->toIso8601String(),
            'received_by' => $receipt->received_by,
            'received_by_name' => User::query()->whereKey($receipt->received_by)->value('name'),
            'receiving_category' => $receipt->receiving_category,
            'receiving_office' => $receipt->receiving_office,
        ];
    }
}
