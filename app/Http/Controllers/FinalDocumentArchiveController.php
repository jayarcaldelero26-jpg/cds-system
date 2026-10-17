<?php

namespace App\Http\Controllers;

use App\Models\DocumentArchive;
use App\Models\DocumentRoutingEvent;
use App\Models\FinalDocumentArchiveFile;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
use App\Models\ModuleDefinition;
use App\Models\PambRoutingEvent;
use App\Models\User;
use App\Services\Archive\FinalDocumentArchiveService;
use App\Services\Archive\FinalDocumentSourceContractRegistry;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class FinalDocumentArchiveController extends Controller
{
    public function __construct(
        private readonly FinalDocumentArchiveService $archives,
        private readonly FinalDocumentSourceContractRegistry $contracts,
        private readonly SubmissionTrackingService $tracking,
        private readonly OrganizationalAccessService $organization,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor && $this->archives->canBrowse($actor), 403);
        $filters = $request->only(['search', 'module', 'protected_area', 'office', 'reporting_year']);
        $partialData = array_values(array_filter(array_map('trim', explode(',', (string) $request->header('X-Inertia-Partial-Data', '')))));
        $selectedOnlyRefresh = $request->header('X-Inertia') === 'true'
            && $request->header('X-Inertia-Partial-Component') === 'SubmissionTracking/Archives'
            && $partialData === ['selected'];
        $rows = $selectedOnlyRefresh ? [] : $this->authorizedRows($actor, 'ARCHIVED', $filters);
        $awaitingRows = ! $selectedOnlyRefresh && $this->isFocal($actor) ? $this->authorizedRows($actor, 'AWAITING', $filters) : [];
        $receiveRows = ! $selectedOnlyRefresh && $this->isFocal($actor) ? $this->authorizedRows($actor, 'RECEIVE', $filters) : [];
        $selected = null;
        $source = $request->query('source');
        $recordId = filter_var($request->query('record'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (is_string($source) && $recordId !== false && $recordId !== null) {
            $selected = $this->archives->context($actor, $source, (int) $recordId, true);
        }

        return Inertia::render('SubmissionTracking/Archives', [
            'archives' => $this->paginate($rows, $request, 'page'),
            'awaitingArchive' => $this->paginate($awaitingRows, $request, 'awaiting_page'),
            'awaitingReceipt' => $this->paginate($receiveRows, $request, 'receipt_page'),
            'filters' => $filters,
            'selected' => $selected,
            'canRecordReceipt' => $this->isFocal($actor),
            'filterOptions' => $selectedOnlyRefresh ? [] : $this->filterOptions([...$rows, ...$awaitingRows, ...$receiveRows]),
        ]);
    }

    public function receive(Request $request, string $source, int $record): RedirectResponse
    {
        $data = $request->validate(['physically_received_at' => ['required', 'date']]);
        $this->archives->recordReceipt($request->user(), $source, $record, (string) $data['physically_received_at']);

        return back()->with('success', 'Physical receipt recorded. Attach the complete signed set in Archives to finish verification.');
    }

    public function archive(Request $request, string $source, int $record): RedirectResponse
    {
        $data = $request->validate([
            'physically_received_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'intent_key' => ['required', 'uuid'],
            'signature_confirmation' => ['accepted'],
            'final_files' => ['required', 'array', 'min:1'],
            'final_files.*' => ['required', 'file'],
        ]);
        $result = $this->archives->receiveAndArchive(
            $request->user(), $source, $record, (string) $data['physically_received_at'],
            (string) $data['intent_key'], array_values($request->file('final_files', [])), true,
        );

        return $this->archiveResult($result);
    }

    public function retry(Request $request, int $manifest): RedirectResponse
    {
        return $this->archiveResult($this->archives->retry($request->user(), $manifest));
    }

    public function file(Request $request, int $file): BinaryFileResponse
    {
        $item = FinalDocumentArchiveFile::query()->findOrFail($file);
        $archive = $this->archives->authorizeManifestFileReader($request->user(), $item);
        abort_unless($archive->archive_phase === FinalDocumentArchiveManifest::PHASE, 404);

        // The private staged copy is retained as the exact manifest-bound
        // source. Rehash it on each request, but do not redownload and hash the
        // already verified provider object multiple times just to render it.
        $relativePath = str_replace('\\', '/', (string) $item->staged_path);
        abort_unless(str_starts_with($relativePath, 'regional-return-final/')
            && ! str_starts_with($relativePath, '/')
            && ! preg_match('/^[A-Za-z]:\//', $relativePath)
            && ! str_contains($relativePath, "\0")
            && ! in_array('..', explode('/', $relativePath), true), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($relativePath), 404);
        $storageRoot = realpath($disk->path(''));
        $path = realpath($disk->path($relativePath));
        $rootPrefix = is_string($storageRoot) ? rtrim($storageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : '';
        $insidePrivateRoot = is_string($path) && $rootPrefix !== ''
            && (DIRECTORY_SEPARATOR === '\\'
                ? strncasecmp($path, $rootPrefix, strlen($rootPrefix)) === 0
                : str_starts_with($path, $rootPrefix));
        abort_unless($insidePrivateRoot && is_file($path) && is_readable($path), 404);

        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $mime = mime_content_type($path);
        abort_unless(is_int($size) && $size > 0
            && $size === (int) $item->file_size
            && $size === (int) $archive->archived_size
            && is_string($sha256)
            && hash_equals((string) $item->sha256, $sha256)
            && hash_equals((string) $archive->archived_sha256, $sha256)
            && is_string($mime)
            && $mime === (string) $item->mime_type
            && in_array($mime, $this->contracts->allowedMimeTypes((string) $archive->source_type), true), 404);

        $filename = preg_replace('/[\r\n"\\\\]/', '_', basename((string) ($item->original_filename ?: 'final-returned-document')));
        $inline = $request->boolean('preview') && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'final-returned-document';
        $response = response()->file($path);
        $response->headers->set('Content-Type', $mime);
        $response->setContentDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            $fallback,
        );
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    public function dispatchFile(Request $request, int $archive): StreamedResponse
    {
        $candidate = DocumentArchive::query()->findOrFail($archive);
        try {
            $retrieved = $this->archives->retrieveEarlierDispatchArchive($request->user(), $candidate);
        } catch (Throwable) {
            abort(404);
        }

        $remoteStream = $retrieved['stream'] ?? null;
        $privateStream = tmpfile();
        if (! is_resource($remoteStream) || ! is_resource($privateStream)) {
            if (is_resource($remoteStream)) fclose($remoteStream);
            if (is_resource($privateStream)) fclose($privateStream);
            abort(404);
        }

        try {
            $copied = stream_copy_to_stream($remoteStream, $privateStream);
            fclose($remoteStream);
            $remoteStream = null;
            $metadata = stream_get_meta_data($privateStream);
            $privatePath = $metadata['uri'] ?? null;
            $stat = fstat($privateStream);
            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            $sha256 = is_string($privatePath) ? hash_file('sha256', $privatePath) : false;
            $mime = is_string($privatePath) ? mime_content_type($privatePath) : false;
            $recorded = $retrieved['archive'];
            if (! is_int($copied) || $copied < 1 || $copied !== $size
                || $size !== (int) $recorded->archived_size
                || ! is_string($sha256) || ! hash_equals((string) $recorded->archived_sha256, $sha256)
                || ! is_string($mime) || ! in_array($mime, $retrieved['allowed_mime_types'], true)) {
                fclose($privateStream);
                abort(404);
            }
        } catch (Throwable) {
            if (is_resource($remoteStream)) fclose($remoteStream);
            if (is_resource($privateStream)) fclose($privateStream);
            abort(404);
        }

        $filename = preg_replace('/[\r\n"\\\\]/', '_', basename((string) ($recorded->original_filename ?: 'earlier-dispatch-copy')));
        $inline = $request->boolean('preview') && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'earlier-dispatch-copy';

        return response()->stream(function () use ($privateStream): void {
            try {
                rewind($privateStream);
                fpassthru($privateStream);
            } finally {
                if (is_resource($privateStream)) fclose($privateStream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                $fallback,
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function authorizedRows(User $actor, string $kind, array $filters): array
    {
        $sources = $this->contracts->sourceKeys();
        $manifests = collect();
        $receipts = collect();
        $receiveCandidates = collect();
        if ($kind === 'ARCHIVED') {
            $manifests = FinalDocumentArchiveManifest::query()
                ->whereIn('source_type', $sources)
                ->where('archive_phase', FinalDocumentArchiveManifest::PHASE)
                ->where('archive_status', 'ARCHIVED')
                ->orderByDesc('finalized_at')->orderByDesc('id')
                ->get(['id', 'source_type', 'source_id']);
        } elseif ($kind === 'AWAITING') {
            if (! $this->isFocal($actor)) return [];
            $office = $this->organization->normalizeOffice($actor->office_designated);
            $receipts = FinalDocumentReceipt::query()
                ->whereIn('source_type', $sources)->where('receiving_category', OrganizationalAccessService::PENRO_FOCAL)
                ->where('receiving_office', $office)->orderByDesc('physically_received_at')->orderByDesc('id')
                ->get(['id', 'source_type', 'source_id']);
            $verified = array_fill_keys($this->archives->verifiedFinalArchiveRecordKeys($actor, $receipts->map(fn (FinalDocumentReceipt $receipt): array => [
                'source' => (string) $receipt->source_type, 'source_id' => (int) $receipt->source_id,
            ])), true);
            $receipts = $receipts->reject(fn (FinalDocumentReceipt $receipt) => isset($verified[$receipt->source_type.':'.$receipt->source_id]));
        } else {
            if (! $this->isFocal($actor)) return [];
            foreach ($sources as $source) {
                $definition = $this->tracking->source($source);
                $query = $this->tracking->archiveSourceQuery($source);
                if (! $definition || ! $query) continue;
                $releasedIds = DocumentRoutingEvent::query()
                    ->where('source_type', $source)
                    ->where('event_key', 'released')
                    ->where('from_stage', 'penro_records_final')
                    ->where('to_stage', 'released_to_regional')
                    ->where('from_office', 'PENRO Records Unit')
                    ->where('to_office', 'Regional Office')
                    ->pluck('source_id')->map(fn ($id) => (int) $id)->unique()->values();

                // Older PAMB submissions persist their terminal release in
                // PambRoutingEvent. Feed those record identities into the
                // same scoped/context-validated receipt flow; do not create a
                // synthetic shared event or infer a receipt from release.
                if ($source === 'conservation') {
                    $pambTimeline = app(PambRoutingTimelineService::class);
                    $legacyReleasedIds = PambRoutingEvent::query()
                        ->whereIn('workflow_key', PambComplianceCalculator::MEETING_WORKFLOWS)
                        ->whereHas('submission', fn ($query) => $query->whereColumn(
                            'conservation_report_submissions.workflow_key', 'pamb_routing_events.workflow_key',
                        ))
                        ->where(function ($query): void {
                            $query->where('stage_key', PambRoutingTimelineService::RELEASED_TO_REGIONAL)
                                ->orWhere('stage_key', 'like', PambRoutingTimelineService::RELEASED_TO_REGIONAL.'%');
                        })
                        ->get(['conservation_report_submission_id', 'stage_key'])
                        ->filter(fn (PambRoutingEvent $event): bool =>
                            $pambTimeline->canonicalStageKey((string) $event->stage_key) === PambRoutingTimelineService::RELEASED_TO_REGIONAL)
                        ->map(fn (PambRoutingEvent $event): int => (int) $event->conservation_report_submission_id);

                    $releasedIds = $releasedIds->concat($legacyReleasedIds)->unique()->values();
                }
                if ($releasedIds->isEmpty()) continue;

                $query->whereIn($query->getModel()->getQualifiedKeyName(), $releasedIds);
                if ($source !== 'engp') $query->with('protectedArea');
                foreach ($query->get() as $record) {
                    $existingReceipt = FinalDocumentReceipt::query()->where('source_type', $source)->where('source_id', $record->getKey())->exists();
                    if ($existingReceipt) continue;
                    try {
                        $context = $this->archives->context($actor, $source, (int) $record->getKey());
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                        continue;
                    }
                    if (! $context['can_receive_final']) continue;
                    $receiveCandidates->push(['source' => $source, 'definition' => $definition, 'record' => $record, 'context' => $context]);
                }
            }
        }

        $identities = $kind === 'ARCHIVED'
            ? $manifests->groupBy('source_type')->map(fn (Collection $rows) => $rows->pluck('source_id')->map(fn ($id) => (int) $id)->unique()->values())
            : $receipts->groupBy('source_type')->map(fn (Collection $rows) => $rows->pluck('source_id')->map(fn ($id) => (int) $id)->unique()->values());
        $recordsByIdentity = collect();
        foreach ($identities as $source => $ids) {
            $definition = $this->tracking->source((string) $source);
            $query = $this->tracking->archiveSourceQuery((string) $source);
            if (! $definition || ! $query) continue;
            if ($source !== 'engp') $query->with('protectedArea');
            $models = $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids)->get();
            foreach ($models as $record) {
                $recordsByIdentity->put($source.':'.$record->getKey(), ['definition' => $definition, 'record' => $record]);
            }
        }

        $contextsByIdentity = collect();
        foreach ($recordsByIdentity as $sourceIdentity => $scoped) {
            [$source, $recordId] = explode(':', $sourceIdentity, 2);
            try {
                $contextsByIdentity->put($sourceIdentity, $this->archives->context($actor, $source, (int) $recordId));
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // Keep unauthorized or inactive source records out of all later metadata and count queries.
            }
        }

        if ($kind === 'RECEIVE') {
            $rows = $receiveCandidates->map(fn (array $candidate): array => $this->row(
                $candidate['source'], $candidate['record'], $candidate['definition'], $candidate['context'], null,
            ))->all();
        } elseif ($kind === 'ARCHIVED') {
            $manifestIds = $manifests->filter(fn ($row) => $contextsByIdentity->has($row->source_type.':'.$row->source_id))->pluck('id');
            $scopedManifests = FinalDocumentArchiveManifest::query()->whereIn('id', $manifestIds)->with(['receipt', 'files.archive'])->get()->keyBy('id');
            $rows = $manifests->map(function ($identity) use ($recordsByIdentity, $contextsByIdentity, $scopedManifests): ?array {
                $sourceIdentity = $identity->source_type.':'.$identity->source_id;
                $scoped = $recordsByIdentity->get($sourceIdentity);
                $context = $contextsByIdentity->get($sourceIdentity);
                $manifest = $scopedManifests->get($identity->id);
                if (! $scoped || ! $context || ! $manifest) return null;
                if ((int) data_get($context, 'latest_manifest.id', 0) !== (int) $manifest->getKey()) return null;
                [$definition, $record] = [$scoped['definition'], $scoped['record']];
                abort_unless($manifest->archive_status === 'ARCHIVED' && $manifest->archive_phase === FinalDocumentArchiveManifest::PHASE, 404);
                return $this->row($identity->source_type, $record, $definition, $context, $manifest);
            })->filter()->values()->all();
        } else {
            $rows = $receipts->map(function (FinalDocumentReceipt $receipt) use ($recordsByIdentity, $contextsByIdentity): ?array {
                $sourceIdentity = $receipt->source_type.':'.$receipt->source_id;
                $scoped = $recordsByIdentity->get($sourceIdentity);
                $context = $contextsByIdentity->get($sourceIdentity);
                if (! $scoped || ! $context) return null;
                [$definition, $record] = [$scoped['definition'], $scoped['record']];
                if (! $context['can_archive_final'] || (int) data_get($context, 'receipt.id') !== (int) $receipt->getKey()) return null;
                return $this->row($receipt->source_type, $record, $definition, $context, null);
            })->filter()->values()->all();
        }

        if ($kind === 'ARCHIVED') {
            $verified = array_fill_keys($this->archives->verifiedFinalArchiveRecordKeys($actor, collect($rows)->map(fn (array $row): array => [
                'source' => $row['source'], 'source_id' => $row['source_id'],
            ])), true);
            $rows = array_values(array_filter($rows, fn (array $row): bool => isset($verified[$row['source'].':'.$row['source_id']])));
            $rows = [...$rows, ...$this->earlierDispatchRows($actor, $filters, $rows)];
            usort($rows, static fn (array $left, array $right): int => strcmp(
                (string) ($right['manifest']['finalized_at'] ?? $right['manifest']['archived_at'] ?? ''),
                (string) ($left['manifest']['finalized_at'] ?? $left['manifest']['archived_at'] ?? ''),
            ));
        }

        return array_values(array_filter($rows, fn (array $row): bool => $this->matchesFilters($row, $filters)));
    }

    /** @param list<array<string,mixed>> $finalRows @return list<array<string,mixed>> */
    private function earlierDispatchRows(User $actor, array $filters, array $finalRows): array
    {
        $finalIdentities = array_fill_keys(array_map(static fn (array $row): string => $row['source'].':'.$row['source_id'], $finalRows), true);
        $candidates = DocumentArchive::query()
            ->whereIn('source_type', $this->contracts->sourceKeys())
            ->where('archive_phase', 'dispatch_checkpoint')
            ->where('archive_status', 'ARCHIVED')
            ->whereNotNull('google_drive_file_id')
            ->whereNotNull('archived_sha256')
            ->where('archived_size', '>', 0)
            ->orderByDesc('archived_at')->orderByDesc('id')
            ->get(['id', 'source_type', 'source_id']);
        $rows = [];
        $seen = [];

        foreach ($candidates->groupBy('source_type') as $source => $archives) {
            $definition = $this->tracking->source((string) $source);
            $query = $this->tracking->archiveSourceQuery((string) $source);
            if (! $definition || ! $query) continue;
            if ($source !== 'engp') $query->with('protectedArea');
            $models = $query->whereIn($query->getModel()->getQualifiedKeyName(), $archives->pluck('source_id')->unique()->values())->get();
            $records = $models->keyBy(fn ($record) => (int) $record->getKey());

            foreach ($archives as $candidate) {
                $sourceId = (int) $candidate->source_id;
                $identity = $source.':'.$sourceId;
                if (isset($finalIdentities[$identity]) || isset($seen[$identity])) continue;
                $record = $records->get($sourceId);
                if (! $record) continue;
                try {
                    $context = $this->archives->context($actor, (string) $source, $sourceId, true);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    continue;
                }
                $copy = $context['earlier_dispatch_copy'] ?? null;
                if (! is_array($copy) || (int) ($copy['archive_id'] ?? 0) !== (int) $candidate->getKey()) continue;

                $row = $this->row((string) $source, $record, $definition, $context, null);
                $row['id'] = $identity.':dispatch:'.$candidate->getKey();
                $row['earlier_dispatch_copy'] = true;
                $row['manifest'] = [
                    'id' => 'dispatch-'.$candidate->getKey(),
                    'status' => 'EARLIER_DISPATCH_COPY',
                    'finalized_at' => null,
                    'archived_at' => $copy['archived_at'],
                    'files' => [$copy['file']],
                ];
                $seen[$identity] = true;
                if (! $this->matchesFilters($row, $filters)) continue;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function row(string $source, $record, array $definition, array $context, ?FinalDocumentArchiveManifest $manifest): array
    {
        $reportMetadata = $this->tracking->archiveReportMetadata($source, $record, $definition);
        $module = $reportMetadata['module'];
        $title = $reportMetadata['title'];
        $period = $record->getAttribute('reporting_period') ?: $record->getAttribute('semester') ?: $record->getAttribute('quarter');
        $year = $record->getAttribute('reporting_year');
        if (! $year && $record->getAttribute('created_at')) $year = CarbonImmutable::parse($record->getAttribute('created_at'))->year;

        return [
            'id' => $source.':'.$record->getKey().':'.($manifest?->getKey() ?? 'receipt'),
            'source' => $source, 'source_id' => (int) $record->getKey(),
            'workflow_key' => (string) $record->getAttribute('workflow_key'),
            'module' => $module, 'title' => $title,
            'protected_area' => method_exists($record, 'protectedArea') ? $record->protectedArea?->name : null,
            'office' => $context['originating_office'] ?? null,
            'origin_kind' => $context['origin_kind'] ?? null,
            'supervising_office' => $context['supervising_office'] ?? null,
            'period' => $period, 'reporting_year' => $year,
            'release_occurred_at' => $context['release_occurred_at'] ?? null,
            'receipt' => $context['receipt'] ?? null,
            'manifest' => $manifest ? $this->archivesManifestData($manifest) : $context['latest_manifest'],
            'can_receive_final' => (bool) $context['can_receive_final'],
            'can_archive_final' => (bool) $context['can_archive_final'],
            'details_url' => route('final-archives.index', ['source' => $source, 'record' => $record->getKey()]),
        ];
    }

    private function archivesManifestData(FinalDocumentArchiveManifest $manifest): array
    {
        $verified = $manifest->archive_phase === FinalDocumentArchiveManifest::PHASE
            && $manifest->archive_status === 'ARCHIVED'
            && $manifest->finalized_at !== null
            && $manifest->files->isNotEmpty()
            && $manifest->files->every(function ($file): bool {
                $archive = $file->archive;
                return $file->file_status === 'ARCHIVED'
                    && $archive
                    && $archive->archive_phase === FinalDocumentArchiveManifest::PHASE
                    && $archive->archive_status === 'ARCHIVED'
                    && $archive->remote_availability === 'verified'
                    && hash_equals((string) $file->sha256, (string) $archive->archived_sha256)
                    && (int) $file->file_size === (int) $archive->archived_size;
            });

        return [
            'id' => (int) $manifest->getKey(), 'revision' => (int) $manifest->revision,
            'status' => $manifest->archive_status, 'finalized_at' => $manifest->finalized_at?->toIso8601String(),
            'processing_claim_active' => false,
            'retry_available' => false,
            'files' => $manifest->files->map(fn ($file) => [
                'id' => (int) $file->getKey(), 'name' => $file->original_filename,
                'mime_type' => $file->mime_type, 'size' => (int) $file->file_size,
                'status' => $file->file_status,
                'preview_url' => $verified ? route('final-archives.files.show', $file->getKey()).'?preview=1' : null,
                'download_url' => $verified ? route('final-archives.files.show', $file->getKey()).'?download=1' : null,
            ])->all(),
        ];
    }

    private function matchesFilters(array $row, array $filters): bool
    {
        $needle = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        if ($needle !== '' && ! str_contains(mb_strtolower(implode(' ', [
            $row['source'], $row['workflow_key'], $row['module'], $row['title'], $row['protected_area'],
            $row['office'], $row['period'], (string) $row['reporting_year'],
            ...collect($row['manifest']['files'] ?? [])->pluck('name')->all(),
        ])), $needle)) return false;
        foreach (['module' => 'module', 'protected_area' => 'protected_area', 'office' => 'office', 'reporting_year' => 'reporting_year'] as $filter => $field) {
            if (filled($filters[$filter] ?? null) && (string) ($row[$field] ?? '') !== (string) $filters[$filter]) return false;
        }
        return true;
    }

    private function filterOptions(array $rows): array
    {
        return [
            'modules' => collect($rows)->pluck('module')->filter()->unique()->sort()->values()->all(),
            'protectedAreas' => collect($rows)->pluck('protected_area')->filter()->unique()->sort()->values()->all(),
            'offices' => collect($rows)->pluck('office')->filter()->unique()->sort()->values()->all(),
            'years' => collect($rows)->pluck('reporting_year')->filter()->unique()->sortDesc()->values()->all(),
        ];
    }

    private function paginate(array $rows, Request $request, string $pageParameter): array
    {
        $page = max(1, $request->integer($pageParameter, 1));
        $perPage = 25;
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page,
            ['path' => route('final-archives.index'), 'pageName' => $pageParameter, 'query' => $request->query()],
        );
        return $paginator->toArray();
    }

    private function isFocal(User $actor): bool
    {
        return $actor->is_active && $actor->is_approved
            && ! $this->organization->isGlobal($actor)
            && $this->organization->effectiveCategory($actor) === OrganizationalAccessService::PENRO_FOCAL;
    }

    private function archiveResult(array $result): RedirectResponse
    {
        $status = (string) ($result['status'] ?? 'failed');
        $redirect = back()->with('archive_status', $status);
        if (isset($result['manifest_id'])) $redirect->with('archive_manifest_id', (int) $result['manifest_id']);
        $errorCode = match (true) {
            ($result['oauth_error_category'] ?? null) === 'invalid_grant'
                && ($result['oauth_error_subtype'] ?? null) === 'invalid_rapt' => 'google_drive_session_reauth',
            ($result['oauth_error_category'] ?? null) === 'invalid_grant' => 'google_drive_invalid_grant',
            in_array($result['oauth_error_category'] ?? null, ['invalid_client', 'deleted_client'], true) => 'google_drive_client_configuration',
            default => null,
        };
        if ($errorCode !== null) $redirect->with('archive_error_code', $errorCode);

        return match ($status) {
            'archived' => $redirect->with('success', 'The complete signed final set was verified and archived.'),
            'processing' => $redirect->with('info', 'Receipt recorded; archive verification is still processing. Refresh this record to check its status.'),
            'failed' => $redirect->with('error', match ($errorCode) {
                'google_drive_session_reauth' => 'Receipt recorded; archive needs retry. Google Workspace requires the existing Drive account to be reauthenticated through the approved flow before retrying.',
                'google_drive_invalid_grant' => 'Receipt recorded; archive needs retry. Google Drive rejected its saved authorization. Ask the CDS-SMART administrator to confirm the existing approved account and client, restore authorization, then retry this manifest.',
                'google_drive_client_configuration' => 'Receipt recorded; archive needs retry. Google rejected the configured OAuth client. Ask the CDS-SMART administrator to verify the existing approved client configuration before retrying.',
                default => 'Receipt recorded; archive needs retry. The exact signed files and archive intent are retained.',
            }),
            default => $redirect->with('error', 'Receipt recorded; archive needs retry. The exact signed files and archive intent are retained.'),
        };
    }
}
