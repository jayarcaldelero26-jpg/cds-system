<?php

namespace App\Http\Controllers;

use App\Models\DocumentArchive;
use App\Models\DocumentRoutingEvent;
use App\Models\FinalDocumentArchiveFile;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
use App\Models\ModuleDefinition;
use App\Models\User;
use App\Services\Archive\FinalDocumentArchiveService;
use App\Services\Archive\FinalDocumentArchiver;
use App\Services\Archive\FinalDocumentSourceContractRegistry;
use App\Services\Authorization\OrganizationalAccessService;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinalDocumentArchiveController extends Controller
{
    public function __construct(
        private readonly FinalDocumentArchiveService $archives,
        private readonly FinalDocumentArchiver $archiver,
        private readonly FinalDocumentSourceContractRegistry $contracts,
        private readonly SubmissionTrackingService $tracking,
        private readonly OrganizationalAccessService $organization,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor && $this->archives->canBrowse($actor), 403);
        $filters = $request->only(['search', 'module', 'protected_area', 'office', 'reporting_year']);
        $rows = $this->authorizedRows($actor, 'ARCHIVED', $filters);
        $awaitingRows = $this->isFocal($actor) ? $this->authorizedRows($actor, 'AWAITING', $filters) : [];
        $receiveRows = $this->isFocal($actor) ? $this->authorizedRows($actor, 'RECEIVE', $filters) : [];
        $selected = null;
        $source = $request->query('source');
        $recordId = filter_var($request->query('record'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (is_string($source) && $recordId !== false && $recordId !== null) {
            $selected = $this->archives->context($actor, $source, (int) $recordId);
        }

        return Inertia::render('SubmissionTracking/Archives', [
            'archives' => $this->paginate($rows, $request, 'page'),
            'awaitingArchive' => $this->paginate($awaitingRows, $request, 'awaiting_page'),
            'awaitingReceipt' => $this->paginate($receiveRows, $request, 'receipt_page'),
            'filters' => $filters,
            'selected' => $selected,
            'canRecordReceipt' => $this->isFocal($actor),
            'filterOptions' => $this->filterOptions([...$rows, ...$awaitingRows, ...$receiveRows]),
        ]);
    }

    public function receive(Request $request, string $source, int $record): RedirectResponse
    {
        $data = $request->validate(['physically_received_at' => ['required', 'date']]);
        $this->archives->recordReceipt($request->user(), $source, $record, (string) $data['physically_received_at']);

        return back()->with('success', 'Final MOV receipt recorded. No archive upload was started.');
    }

    public function archive(Request $request, string $source, int $record): RedirectResponse
    {
        $data = $request->validate([
            'intent_key' => ['required', 'uuid'],
            'signature_confirmation' => ['accepted'],
            'final_files' => ['required', 'array', 'min:1'],
            'final_files.*' => ['required', 'file'],
        ]);
        $result = $this->archives->createOrRetryManifest(
            $request->user(), $source, $record, (string) $data['intent_key'],
            array_values($request->file('final_files', [])), true,
        );

        return $this->archiveResult($result);
    }

    public function retry(Request $request, int $manifest): RedirectResponse
    {
        return $this->archiveResult($this->archives->retry($request->user(), $manifest));
    }

    public function file(Request $request, int $file): StreamedResponse
    {
        $item = FinalDocumentArchiveFile::query()->findOrFail($file);
        $archive = $this->archives->authorizeManifestFileReader($request->user(), $item);
        abort_unless($archive->archive_phase === FinalDocumentArchiveManifest::PHASE
            && $this->archiver->verifyArchivedDocument($archive), 404);
        $stream = $this->archiver->retrieveArchivedDocument($archive);
        $filename = preg_replace('/[\r\n"\\\\]/', '_', basename((string) ($item->original_filename ?: 'final-returned-document')));
        $mime = (string) ($item->mime_type ?: 'application/octet-stream');
        $inline = $request->boolean('preview') && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
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
            $completed = FinalDocumentArchiveManifest::query()->whereIn('source_type', $sources)
                ->where('archive_phase', FinalDocumentArchiveManifest::PHASE)->where('archive_status', 'ARCHIVED')
                ->get(['source_type', 'source_id'])->map(fn ($row) => $row->source_type.':'.$row->source_id)->flip();
            $receipts = $receipts->reject(fn (FinalDocumentReceipt $receipt) => $completed->has($receipt->source_type.':'.$receipt->source_id));
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

        return array_values(array_filter($rows, fn (array $row): bool => $this->matchesFilters($row, $filters)));
    }

    private function row(string $source, $record, array $definition, array $context, ?FinalDocumentArchiveManifest $manifest): array
    {
        $module = ($definition['module'])($record);
        $title = $record->getAttribute('activity_name') ?: $record->getAttribute('station_name') ?: $record->getAttribute('report_title');
        $period = $record->getAttribute('reporting_period') ?: $record->getAttribute('semester') ?: $record->getAttribute('quarter');
        $year = $record->getAttribute('reporting_year');
        if (! $year && $record->getAttribute('created_at')) $year = CarbonImmutable::parse($record->getAttribute('created_at'))->year;

        return [
            'id' => $source.':'.$record->getKey().':'.($manifest?->getKey() ?? 'receipt'),
            'source' => $source, 'source_id' => (int) $record->getKey(),
            'workflow_key' => (string) $record->getAttribute('workflow_key'),
            'module' => $module, 'title' => $title,
            'protected_area' => method_exists($record, 'protectedArea') ? $record->protectedArea?->name : null,
            'office' => $context['originating_office'] ?? null, 'period' => $period, 'reporting_year' => $year,
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
        return [
            'id' => (int) $manifest->getKey(), 'revision' => (int) $manifest->revision,
            'status' => $manifest->archive_status, 'finalized_at' => $manifest->finalized_at?->toIso8601String(),
            'files' => $manifest->files->map(fn ($file) => [
                'id' => (int) $file->getKey(), 'name' => $file->original_filename,
                'mime_type' => $file->mime_type, 'size' => (int) $file->file_size,
                'status' => $file->file_status,
                'preview_url' => route('final-archives.files.show', $file->getKey()).'?preview=1',
                'download_url' => route('final-archives.files.show', $file->getKey()).'?download=1',
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
        return match ($result['status'] ?? 'failed') {
            'archived' => back()->with('success', 'The complete final document set was verified and archived.'),
            'processing' => back()->with('info', 'The archive is still processing. Its verified progress has been retained.'),
            default => back()->with('error', 'The final archive could not be fully verified. The receipt and staged files are retained for retry.'),
        };
    }
}
