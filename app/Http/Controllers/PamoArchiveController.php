<?php

namespace App\Http\Controllers;

use App\Models\ConservationReportSubmission;
use App\Models\DocumentArchive;
use App\Models\ModuleDefinition;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Archive\FinalDocumentArchiver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use App\Services\SubmissionTracking\DocumentRoutingAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PamoArchiveController extends Controller
{
    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly ConservationReportWorkflowRegistry $workflows,
        private readonly PamoNativeSourceRegistry $nativeSources,
        private readonly PamoOriginSchemaReadiness $pamoOrigins,
        private readonly SubmissionTrackingService $tracking,
        private readonly DocumentRoutingAccessService $routingAccess,
        private readonly FinalDocumentArchiver $archiver,
    ) {}

    public function index(Request $request): Response
    {
        [$facts, $area] = $this->assertActor($request->user());
        $sources = $this->eligibleSourceLinks();
        $search = trim((string) $request->query('search', ''));
        $draftScope = PamoPambDraft::query()
            ->where('protected_area_id', $facts['protected_area_id'])
            ->where('pamo_office_id', $facts['pamo_office_id'])
            ->where('status', 'forwarded');

        $archives = DocumentArchive::query()->where(function ($query) use ($sources, $draftScope, $search): void {
            foreach ($sources as $sourceKey => $source) {
                $query->orWhere(function ($match) use ($sourceKey, $source, $draftScope): void {
                    $link = (clone $draftScope)->whereIn('workflow_key', $source['workflows'])
                        ->whereNotNull($source['column'])->select($source['column']);
                    $match->where('source_type', $sourceKey)->whereIn('source_id', $link);
                });
            }
        })->when($search !== '', function ($query) use ($search, $sources, $facts): void {
            $query->where(function ($matches) use ($search, $sources, $facts): void {
                $matches->where('original_filename', 'like', '%'.$search.'%')->orWhere('logical_slot', 'like', '%'.$search.'%');
                foreach ($sources as $sourceKey => $source) {
                    $matches->orWhereExists(function ($draft) use ($sourceKey, $source, $search, $facts): void {
                        $draft->selectRaw('1')->from('pamo_pamb_drafts')
                            ->whereColumn('pamo_pamb_drafts.'.$source['column'], 'document_archives.source_id')
                            ->where('pamo_pamb_drafts.protected_area_id', $facts['protected_area_id'])
                            ->where('pamo_pamb_drafts.pamo_office_id', $facts['pamo_office_id'])
                            ->where('pamo_pamb_drafts.status', 'forwarded')
                            ->whereIn('pamo_pamb_drafts.workflow_key', $source['workflows'])
                            ->where(function ($payload) use ($search): void {
                                $payload->where('pamo_pamb_drafts.workflow_key', 'like', '%'.$search.'%')
                                    ->orWhere('pamo_pamb_drafts.report_payload->activity_name', 'like', '%'.$search.'%')
                                    ->orWhere('pamo_pamb_drafts.report_payload->document_type', 'like', '%'.$search.'%')
                                    ->orWhere('pamo_pamb_drafts.report_payload->reporting_period', 'like', '%'.$search.'%')
                                    ->orWhere('pamo_pamb_drafts.report_payload->semester', 'like', '%'.$search.'%')
                                    ->orWhere('pamo_pamb_drafts.report_payload->quarter', 'like', '%'.$search.'%');
                            });
                    });
                }
            });
        })->whereExists(function ($snapshot) use ($facts): void {
            $snapshot->selectRaw('1')->from('submission_routing_snapshots')
                ->whereColumn('submission_routing_snapshots.source_key', 'document_archives.source_type')
                ->whereColumn('submission_routing_snapshots.source_id', 'document_archives.source_id')
                ->where('submission_routing_snapshots.origin_kind', 'pamo_pamb')
                ->where('submission_routing_snapshots.protected_area_id', $facts['protected_area_id'])
                ->where('submission_routing_snapshots.pamo_office_id', $facts['pamo_office_id']);
        })->latest('archived_at')->latest('id')->paginate(25)->withQueryString();

        $idsBySource = $archives->getCollection()->groupBy('source_type')
            ->map(fn (Collection $rows) => $rows->pluck('source_id')->unique()->values());
        $draftsByIdentity = collect();
        foreach ($idsBySource as $sourceKey => $ids) {
            $source = $sources[$sourceKey] ?? null;
            if (! $source) continue;
            $draftQuery = (clone $draftScope)->whereIn('workflow_key', $source['workflows'])
                ->whereIn($source['column'], $ids)->get();
            foreach ($draftQuery as $draft) {
                $draftsByIdentity->put($sourceKey.':'.$draft->getAttribute($source['column']), $draft);
            }
        }

        $labels = collect($this->workflowLabels())->all();
        $archives->setCollection($archives->getCollection()->map(function (DocumentArchive $archive) use ($draftsByIdentity, $sources, $labels): array {
            $source = $sources[$archive->source_type] ?? null;
            $draft = $draftsByIdentity->get($archive->source_type.':'.$archive->source_id);
            if (! $source || ! $draft) return [];
            $payload = $draft->report_payload ?? [];
            $freshness = match (true) {
                $archive->archive_status !== 'ARCHIVED' => 'Unavailable',
                $archive->remote_availability === 'unavailable' => 'Unavailable',
                $archive->remote_availability === 'verified' && $archive->last_verified_at => 'Recorded · checked '.$archive->last_verified_at->format('M j, Y'),
                default => 'Recorded · availability not confirmed',
            };

            return [
                'id' => (int) $archive->getKey(),
                'workflow_key' => $draft->workflow_key,
                'report' => $labels[$draft->workflow_key] ?? $draft->workflow_key,
                'activity_name' => $payload['activity_name'] ?? null,
                'document_type' => $payload['document_type'] ?? null,
                'reporting_period' => $payload['reporting_period'] ?? $payload['semester'] ?? (! empty($payload['quarter']) ? 'Quarter '.$payload['quarter'] : null),
                'archived_at' => $archive->archived_at?->toIso8601String(),
                'status' => $freshness,
                'file_url' => route('pamo-archives.file', $archive->getKey()),
                'filename' => $archive->original_filename,
            ];
        })->filter(fn (array $row): bool => $row !== [])->values());

        return Inertia::render('PamoReports/Archives', [
            'archives' => $archives,
            'assignedProtectedArea' => ['id' => $facts['protected_area_id'], 'name' => $area->name],
            'filters' => ['search' => $search],
        ]);
    }

    public function file(Request $request, int $archive): StreamedResponse
    {
        [$facts] = $this->assertActor($request->user());
        $document = DocumentArchive::query()->findOrFail($archive);
        [$draft, $record, $sourceKey] = $this->authorizeArchive($request->user(), $document, $facts);
        abort_unless($draft->status === 'forwarded' && (int) $record->protected_area_id === (int) $facts['protected_area_id'], 404);

        $stream = $this->archiver->retrieveArchivedDocument($document);
        $filename = preg_replace('/[\r\n"\\\\]/', '_', basename((string) ($document->original_filename ?: 'archived-report.pdf')));

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** @return array{0:array<string,mixed>,1:ProtectedArea} */
    private function assertActor(?User $actor): array
    {
        abort_unless($actor && $actor->is_active && $actor->is_approved
            && $this->organization->effectiveCategory($actor) === OrganizationalAccessService::PAMO
            && $this->organization->canViewPamoPilotWorkflow($actor)
            && $actor->protected_area_id !== null, 403);
        $facts = $this->organization->pamoAssignmentForProtectedArea((int) $actor->protected_area_id);
        abort_unless($facts && $this->organization->canAccessProtectedArea($actor, $facts['protected_area_id']), 403);
        $this->pamoOrigins->assertDraftAndOriginSchemaReady();

        return [$facts, ProtectedArea::query()->findOrFail($facts['protected_area_id'])];
    }

    /** @return array<string,array{column:string,workflows:list<string>}> */
    private function eligibleSourceLinks(): array
    {
        $generic = $this->workflows->pamoSubmissionKeys(false);
        $sources = $generic ? ['conservation' => ['column' => 'conservation_report_submission_id', 'workflows' => $generic]] : [];
        if (! $this->nativeSources->isSchemaReady()) return $sources;
        foreach ($this->nativeSources->all() as $workflow => $definition) {
            if (! $this->nativeSources->isConfigured($workflow)) continue;
            $sources[$definition['source']] = ['column' => $definition['column'], 'workflows' => [$workflow]];
        }

        return $sources;
    }

    /** @return array<string,string> */
    private function workflowLabels(): array
    {
        $labels = ModuleDefinition::query()->notRetired()->whereIn('code', array_merge(
            $this->workflows->pamoSubmissionKeys(false),
            array_keys($this->nativeSources->all()),
        ))->pluck('name', 'code')->all();

        return $labels;
    }

    /** @return array{0:PamoPambDraft,1:\Illuminate\Database\Eloquent\Model,2:string} */
    private function authorizeArchive(User $actor, DocumentArchive $archive, array $facts): array
    {
        $source = $archive->source_type === 'conservation'
            ? ['workflow' => null, 'definition' => ['source' => 'conservation', 'column' => 'conservation_report_submission_id', 'model' => ConservationReportSubmission::class]]
            : $this->nativeSources->findBySource((string) $archive->source_type);
        abort_unless($source, 404);
        $workflow = $source['workflow'];
        $definition = $source['definition'];
        if ($archive->source_type === 'conservation') {
            $draft = PamoPambDraft::query()->whereIn('workflow_key', $this->workflows->pamoSubmissionKeys())
                ->where('protected_area_id', $facts['protected_area_id'])->where('pamo_office_id', $facts['pamo_office_id'])
                ->where('status', 'forwarded')->where($definition['column'], $archive->source_id)->firstOrFail();
            $workflow = (string) $draft->workflow_key;
            abort_unless($this->workflows->supportsPamoSubmission($workflow, false), 404);
        } else {
            abort_unless($this->nativeSources->isSchemaReady() && $this->nativeSources->isConfigured((string) $workflow), 404);
            $draft = PamoPambDraft::query()->where('workflow_key', $workflow)
                ->where('protected_area_id', $facts['protected_area_id'])->where('pamo_office_id', $facts['pamo_office_id'])
                ->where('status', 'forwarded')->where($definition['column'], $archive->source_id)->firstOrFail();
        }

        $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', $archive->source_type)
            ->where('source_id', $archive->source_id)->firstOrFail();
        abort_unless($snapshot->origin_kind === 'pamo_pamb'
            && (int) $snapshot->protected_area_id === (int) $facts['protected_area_id']
            && (int) $snapshot->pamo_office_id === (int) $facts['pamo_office_id'], 404);

        $model = $this->tracking->source((string) $archive->source_type);
        abort_unless($model, 404);
        $record = $model['model']::query()->findOrFail($archive->source_id);
        abort_unless((int) $record->protected_area_id === (int) $facts['protected_area_id']
            && $this->routingAccess->canView($actor, $record, (string) $archive->source_type, $model['ability']), 404);

        return [$draft, $record, (string) $archive->source_type];
    }
}
