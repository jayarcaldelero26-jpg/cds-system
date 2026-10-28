<?php

namespace App\Http\Controllers;

use App\Models\ModuleDefinition;
use App\Models\ConservationReportSubmission;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PamoReportWorkspaceController extends Controller
{
    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly ConservationReportWorkflowRegistry $workflows,
        private readonly PamoNativeSourceRegistry $nativeSources,
        private readonly PamoOriginSchemaReadiness $pamoOrigins,
        private readonly PamoNativeReportsController $nativeReports,
        private readonly ProtectedAttachmentService $attachments,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $actor = $request->user();
        $facts = $this->assertActor($actor);
        $search = trim((string) $request->query('search', ''));
        if ($request->query('tab') === 'submitted') {
            return redirect()->route('submission-tracking.index', array_filter([
                'view' => 'outgoing',
                'search' => $search !== '' ? $search : null,
            ], static fn (mixed $value): bool => $value !== null));
        }

        $this->pamoOrigins->assertDraftAndOriginSchemaReady();
        $area = ProtectedArea::query()->findOrFail($facts['protected_area_id']);
        $workflowCatalog = $this->workflowCatalog();
        $workflowKeys = array_fill_keys(array_column($workflowCatalog, 'key'), true);
        $requestedWorkflow = trim((string) $request->query('workflow', ''));
        $workflowFilter = isset($workflowKeys[$requestedWorkflow]) ? $requestedWorkflow : '';
        $page = max(1, $request->integer('page', 1));
        $perPage = 25;

        $query = PamoPambDraft::query()
            ->where('protected_area_id', $facts['protected_area_id'])
            ->where('pamo_office_id', $facts['pamo_office_id'])
            ->whereIn('workflow_key', array_keys($workflowKeys))
            ->where('status', 'draft')
            ->when($workflowFilter !== '', fn ($builder) => $builder->where('workflow_key', $workflowFilter))
            ->when($search !== '', function ($builder) use ($search): void {
                $builder->where(function ($where) use ($search): void {
                    $where->where('workflow_key', 'like', '%'.$search.'%')
                        ->orWhere('report_payload->activity_name', 'like', '%'.$search.'%')
                        ->orWhere('report_payload->document_type', 'like', '%'.$search.'%')
                        ->orWhere('report_payload->reporting_period', 'like', '%'.$search.'%')
                        ->orWhere('report_payload->semester', 'like', '%'.$search.'%')
                        ->orWhere('report_payload->quarter', 'like', '%'.$search.'%');
                });
            })
            ->latest('started_at');

        $draftPage = $query->paginate($perPage)->appends(array_filter([
            'search' => $search !== '' ? $search : null,
            'workflow' => $workflowFilter !== '' ? $workflowFilter : null,
        ], static fn (mixed $value): bool => $value !== null));
        $workflowsByKey = collect($workflowCatalog)->keyBy('key');
        $rows = $draftPage->getCollection()->map(function (PamoPambDraft $draft) use ($workflowsByKey, $actor): array {
            $workflow = $workflowsByKey->get($draft->workflow_key);
            $native = $this->nativeSources->find((string) $draft->workflow_key);
            $sourceId = $native
                ? $draft->getAttribute($native['column'])
                : $draft->conservation_report_submission_id;
            $sourceKey = $sourceId ? ($native['source'] ?? 'conservation') : null;
            $payload = $draft->report_payload ?? [];
            $sourceRecord = null;
            $sourceMovUrl = null;
            if ($sourceId && $native) {
                $model = $native['model'];
                $sourceRecord = $model::query()->find($sourceId);
                if ($sourceRecord
                    && (int) $sourceRecord->protected_area_id === (int) $draft->protected_area_id
                    && app(\App\Services\SubmissionTracking\DocumentRoutingAccessService::class)
                        ->canView($actor, $sourceRecord, $native['source'])
                    && $this->attachments->descriptor($native['attachment'], $sourceRecord, $native['file_field']) !== null) {
                    $sourceMovUrl = route('conservation-reports.pamo-drafts.source-mov', [$draft->workflow_key, $draft->draft_uuid]);
                }
            } elseif ($sourceId) {
                $sourceRecord = ConservationReportSubmission::query()->find($sourceId);
                if ($sourceRecord
                    && $sourceRecord->workflow_key === $draft->workflow_key
                    && (int) $sourceRecord->protected_area_id === (int) $draft->protected_area_id
                    && $this->organization->canAccessProtectedArea($actor, $sourceRecord->protected_area_id)
                    && $this->attachments->descriptor('conservation-report', $sourceRecord, 'mov') !== null) {
                    $sourceMovUrl = route('conservation-reports.mov', [$draft->workflow_key, $sourceRecord->getKey()]);
                }
            }
            $sourceUrl = null;
            if ($sourceId && $sourceRecord) {
                $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
                    ->state($sourceRecord, $sourceKey, actor: $actor);
                $lastEvent = $routing['events']->last();
                $view = $routing['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL
                    ? 'history'
                    : ($routing['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
                        && $lastEvent?->event_key === 'correction_received'
                        ? 'incoming'
                        : (($routing['route_position']['origin_kind'] ?? null) === 'pamo_pamb' ? 'outgoing' : 'incoming'));
                $sourceUrl = route('submission-tracking.index', ['view' => $view, 'source' => $sourceKey, 'source_id' => $sourceId]);
            }

            return [
                'id' => $draft->draft_uuid,
                'workflow_key' => $draft->workflow_key,
                'workflow_label' => $workflow['label'] ?? $draft->workflow_key,
                'activity_name' => $payload['activity_name'] ?? null,
                'document_type' => $payload['document_type'] ?? null,
                'reporting_period' => $payload['reporting_period'] ?? $payload['semester'] ?? (! empty($payload['quarter']) ? 'Quarter '.$payload['quarter'] : null),
                'date_conducted' => $payload['date_conducted'] ?? null,
                'date_accomplished' => $payload['date_accomplished'] ?? null,
                'started_at' => $draft->started_at?->toIso8601String(),
                'updated_at' => $draft->updated_at?->toIso8601String(),
                'forwarded_at' => $draft->forwarded_at?->toIso8601String(),
                'status' => $draft->status === 'forwarded' ? 'Submitted' : 'Draft',
                'lock_version' => (int) $draft->lock_version,
                'report_payload' => $payload,
                'mov_file_name' => $draft->mov_file_name,
                'mov_url' => $draft->mov_path ? route('conservation-reports.pamo-drafts.mov', [$draft->workflow_key, $draft->draft_uuid]) : null,
                'source_key' => $sourceKey,
                'source_submission_id' => $sourceId,
                'source_url' => $sourceUrl,
                'source_mov_url' => $sourceMovUrl,
            ];
        })->values();
        $draftPage->setCollection($rows);

        return Inertia::render('PamoReports/Index', [
            'workflows' => $workflowCatalog,
            'rows' => $draftPage,
            'assignedProtectedArea' => ['id' => $facts['protected_area_id'], 'name' => $area->name],
            'pamoContext' => [
                'protected_area' => $area->name,
                'pamo_office' => $facts['pamo_office_name'],
                'target_office' => $facts['target_office'],
                'first_recipient' => $facts['profile'] === 'pamo_direct'
                    ? $facts['target_office'].' Records'
                    : $facts['target_office'].' CDS Focal Person',
            ],
            'filters' => ['search' => $search, 'workflow' => $workflowFilter, 'page' => $page],
            'pamoWorkspace' => true,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function workflowCatalog(): array
    {
        $genericKeys = $this->workflows->pamoSubmissionKeys(false);
        $generic = ModuleDefinition::query()->notRetired()
            ->where('implementation_type', ModuleDefinition::IMPLEMENTATION_GENERIC)
            ->whereIn('code', $genericKeys)
            ->orderByRaw('display_order IS NULL')->orderBy('display_order')->orderBy('name')
            ->get(['name', 'code'])
            ->map(function (ModuleDefinition $module): array {
                return [
                    ...($this->workflows->find((string) $module->code) ?? []),
                    'key' => (string) $module->code,
                    'label' => (string) $module->name,
                    'source' => 'conservation',
                ];
            });

        $native = collect();
        if ($this->nativeSources->isSchemaReady()) {
            $native = ModuleDefinition::query()->notRetired()
                ->where('implementation_type', ModuleDefinition::IMPLEMENTATION_SPECIALIZED)
                ->where('requirement_domain', 'pa')->whereIn('code', array_keys($this->nativeSources->all()))
                ->orderByRaw('display_order IS NULL')->orderBy('display_order')->orderBy('name')
                ->get(['code'])
                ->filter(fn (ModuleDefinition $module): bool => $this->nativeSources->isConfigured((string) $module->code))
                ->map(fn (ModuleDefinition $module): array => $this->nativeReports->workspaceWorkflowConfig((string) $module->code));
        }

        return $generic->concat($native)->values()->all();
    }

    /** @return array<string,mixed> */
    private function assertActor(?User $actor): array
    {
        abort_unless($actor && $actor->is_active && $actor->is_approved
            && $this->organization->effectiveCategory($actor) === OrganizationalAccessService::PAMO
            && $this->organization->canViewPamoPilotWorkflow($actor)
            && $actor->protected_area_id !== null, 403);

        $facts = $this->organization->pamoAssignmentForProtectedArea((int) $actor->protected_area_id);
        abort_unless($facts && $this->organization->canAccessProtectedArea($actor, $facts['protected_area_id']), 403);

        return $facts;
    }
}
