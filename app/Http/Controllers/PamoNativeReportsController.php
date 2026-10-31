<?php

namespace App\Http\Controllers;

use App\Models\ModuleDefinition;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class PamoNativeReportsController extends Controller
{
    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly PamoNativeSourceRegistry $sources,
        private readonly PamoOriginSchemaReadiness $pamoOrigins,
        private readonly \App\Services\Pamo\PamoNativeDraftAdapter $nativeDrafts,
        private readonly ProtectedAttachmentService $attachments,
    ) {}

    public function index(Request $request, string $workflow): Response
    {
        $actor = $request->user();
        $facts = $this->assertActor($actor, $workflow);
        $assignedArea = ProtectedArea::query()->findOrFail($facts['protected_area_id']);
        $source = $this->sources->find($workflow);
        $model = $source['model'];
        $query = $model::query()->where('protected_area_id', $facts['protected_area_id'])
            ->with('protectedArea:id,name,short_name');
        if ($workflow === 'automated_weather_station') $query->whereNull('timestamps');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search, $workflow): void {
                $q->where('activity_name', 'like', "%{$search}%")
                    ->orWhere('document_type', 'like', "%{$search}%")
                    ->orWhere('target_office', 'like', "%{$search}%");
                if ($workflow === 'automated_weather_station') {
                    $q->orWhere('station_name', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%");
                }
            });
        }
        $reports = $query->latest('id')->paginate(15)->withQueryString()->through(
            fn (Model $record): array => $this->reportRow($record, $workflow, $source, $actor),
        );

        $drafts = PamoPambDraft::query()
            ->where('protected_area_id', $facts['protected_area_id'])
            ->where('pamo_office_id', $facts['pamo_office_id'])
            ->where('workflow_key', $workflow)
            ->latest('started_at')->limit(100)->get()
            ->map(function (PamoPambDraft $draft) use ($source, $workflow): array {
                $id = $draft->getAttribute($source['column']);
                $sourceKey = $source['source'];
                $recordId = $id ?: $draft->conservation_report_submission_id;
                $recordSource = $id ? $sourceKey : ($recordId ? 'conservation' : null);

                return [
                    'id' => $draft->draft_uuid,
                    'workflow_key' => $draft->workflow_key,
                    'status' => $draft->status,
                    'report_payload' => $draft->report_payload ?? [],
                    'started_at' => $draft->started_at?->toIso8601String(),
                    'forwarded_at' => $draft->forwarded_at?->toIso8601String(),
                    'lock_version' => (int) $draft->lock_version,
                    'mov_file_name' => $draft->mov_file_name,
                    'mov_url' => $draft->mov_path ? route('conservation-reports.pamo-drafts.mov', [$workflow, $draft->draft_uuid]) : null,
                    'source_mov_url' => $id ? route('conservation-reports.pamo-drafts.source-mov', [$workflow, $draft->draft_uuid]) : null,
                    'source_key' => $recordSource,
                    'source_submission_id' => $recordId,
                    'source_url' => $recordId ? $this->draftTrackingUrl($draft, $source, $id, $recordId, $recordSource) : null,
                ];
            });

        return Inertia::render('PamoNativeReports/Index', [
            'workflow' => $this->workspaceWorkflowConfig($workflow),
            'reports' => $reports,
            'pamoDrafts' => $drafts,
            'assignedProtectedArea' => [
                'id' => $facts['protected_area_id'],
                'name' => $assignedArea->name,
            ],
            'pamoContext' => [
                'protected_area' => $assignedArea->name,
                'target_office' => $facts['target_office'],
            ],
            'filters' => $request->only(['search']),
            'pamoWorkspace' => true,
        ]);
    }

    public function mov(Request $request, string $workflow, int $record): HttpResponse
    {
        $facts = $this->assertActor($request->user(), $workflow);
        $source = $this->sources->find($workflow);
        $model = $source['model'];
        $nativeRecord = $model::query()->findOrFail($record);
        abort_unless((int) $nativeRecord->protected_area_id === (int) $facts['protected_area_id'], 404);
        abort_unless(app(\App\Services\SubmissionTracking\DocumentRoutingAccessService::class)
            ->canView($request->user(), $nativeRecord, $source['source']), 403);

        return $this->attachments->response($source['attachment'], $nativeRecord, $source['file_field']);
    }

    public function updateCorrection(Request $request, string $workflow, int $record): RedirectResponse
    {
        $actor = $request->user();
        $facts = $this->assertActor($actor, $workflow);
        $this->nativeDrafts->updateCorrection($request, $workflow, $record, $facts, $actor);

        return back()->with('success', 'The corrected report was saved. Forward it again when it is ready.');
    }

    private function assertActor(?User $actor, string $workflow): array
    {
        $source = $this->sources->find($workflow);
        abort_unless($source && $this->sources->isConfigured($workflow), 404);
        $this->sources->assertSchemaReady();
        $this->pamoOrigins->assertDraftAndOriginSchemaReady();
        abort_unless($actor && $actor->is_active && $actor->is_approved
            && $this->organization->effectiveCategory($actor) === OrganizationalAccessService::PAMO
            && $this->organization->canViewPamoPilotWorkflow($actor), 403);
        $facts = $this->organization->pamoAssignmentForProtectedArea((int) $actor->protected_area_id);
        abort_unless($facts && $this->organization->canAccessProtectedArea($actor, $facts['protected_area_id']), 403);

        return $facts;
    }

    private function reportRow(Model $record, string $workflow, array $source, User $actor): array
    {
        $fields = [
            'id', 'protected_area_id', 'target_office', 'activity_name', 'document_type',
            'semester', 'quarter', 'reporting_month', 'reporting_year', 'total_collected',
            'deadline_submission', 'date_conducted', 'date_conducted_ranges', 'date_accomplished',
            'monitoring_period_start', 'monitoring_period_end', 'reporting_period',
            'report_period_type', 'station_name', 'location', 'reporting_year', 'remarks',
            'recommendation_remarks', 'status', 'start_date', 'end_date',
            'mov_file_name', 'report_file_name', 'created_at', 'updated_at',
        ];
        $data = collect($record->only($fields))->all();
        $data['protected_area_name'] = $record->protectedArea?->short_name ?: $record->protectedArea?->name;
        $data['source_key'] = $source['source'];
        $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->state($record, $source['source'], actor: $actor);
        $descriptor = $this->attachments->descriptor($source['attachment'], $record, $source['file_field']);
        $data['mov_url'] = $descriptor !== null
            ? route('pamo-native-reports.mov', [$workflow, $record->getKey()])
            : null;
        $data['mov_available'] = $descriptor !== null;
        $data['mov_file_name'] = $record->getAttribute('mov_file_name') ?: $record->getAttribute('report_file_name');
        $data['source_updated_at'] = $record->updated_at?->toISOString();
        $data['workflow_stage'] = $routing['stage'];
        $snapshot = \App\Models\SubmissionRoutingSnapshot::query()
            ->where('source_key', $source['source'])->where('source_id', $record->getKey())->first();
        $facts = $this->organization->pamoAssignmentForProtectedArea((int) $record->protected_area_id);
        $linkedDraft = PamoPambDraft::query()
            ->where('workflow_key', $workflow)->where('protected_area_id', $record->protected_area_id)
            ->where('pamo_office_id', $facts['pamo_office_id'] ?? 0)
            ->where('status', 'forwarded')->where($source['column'], $record->getKey())->exists();
        $lastEvent = $routing['events']->last();
        $resubmit = collect($routing['actions'])->first(fn (array $action): bool => in_array($action['key'], ['forward_from_pamo', 'forward_from_pamo_to_cenro_focal'], true));
        $data['can_edit_correction'] = $linkedDraft && $snapshot?->origin_kind === 'pamo_pamb'
            && (int) $snapshot?->pamo_office_id === (int) ($facts['pamo_office_id'] ?? 0)
            && $routing['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
            && $lastEvent?->event_key === 'correction_received' && $resubmit !== null;
        $data['resubmit_action'] = $data['can_edit_correction'] ? $resubmit['key'] : null;
        $view = $routing['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL
            ? 'history'
            : ($routing['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
                && $lastEvent?->event_key === 'correction_received'
                ? 'incoming'
                : ($linkedDraft && ($routing['route_position']['origin_kind'] ?? null) === 'pamo_pamb' ? 'outgoing' : 'incoming'));
        $data['source_url'] = route('submission-tracking.index', [
            'view' => $view,
            'source' => $source['source'],
            'source_id' => $record->getKey(),
        ]);

        return $data;
    }

    private function draftTrackingUrl(PamoPambDraft $draft, array $source, mixed $nativeId, int $recordId, ?string $recordSource): ?string
    {
        if (! $recordSource) return null;
        if ($nativeId) {
            $model = $source['model'];
            $record = $model::query()->find($recordId);
        } else {
            $record = \App\Models\ConservationReportSubmission::query()->find($recordId);
        }
        if (! $record || (int) $record->protected_area_id !== (int) $draft->protected_area_id) return null;
        if (! $nativeId && ($record->workflow_key !== $draft->workflow_key || $recordSource !== 'conservation')) return null;

        $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->state($record, $recordSource);
        $lastEvent = $routing['events']->last();
        $view = $routing['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL
            ? 'history'
            : ($routing['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
                && $lastEvent?->event_key === 'correction_received'
                ? 'incoming'
                : (($routing['route_position']['origin_kind'] ?? null) === 'pamo_pamb' ? 'outgoing' : 'incoming'));

        return route('submission-tracking.index', ['view' => $view, 'source' => $recordSource, 'source_id' => $recordId]);
    }

    public function workspaceWorkflowConfig(string $workflow): array
    {
        $source = $this->sources->find($workflow);
        $definition = ModuleDefinition::query()->notRetired()->where('implementation_type', ModuleDefinition::IMPLEMENTATION_SPECIALIZED)
            ->where('requirement_domain', 'pa')->where('code', $workflow)->firstOrFail();
        $form = match ($workflow) {
            'bms' => 'standard_bms',
            'bams', 'imea' => 'standard_a',
            'imea_facility_maintenance' => 'imea_maintenance',
            'automated_weather_station' => 'aws_submission',
            'ipaf_management' => 'ipaf_management',
            'revenue_collection' => 'revenue',
        };

        $awsSubmission = $workflow === 'automated_weather_station';

        return [
            'key' => $workflow,
            'label' => $definition->name,
            'source' => $source['source'],
            'is_active' => $this->sources->isEligible($workflow),
            'form' => $form,
            'activity_required' => $awsSubmission,
            'default_activity' => $awsSubmission ? 'Monitoring and Maintenance of AWS' : null,
            'activities' => $awsSubmission ? ['Monitoring and Maintenance of AWS'] : [],
            'period_field' => $awsSubmission ? 'quarter' : null,
            'period_label' => $awsSubmission ? 'Reporting Period' : null,
            'period_required' => $awsSubmission,
            'period_options' => $awsSubmission ? [
                ['value' => 1, 'label' => 'Quarter 1'], ['value' => 2, 'label' => 'Quarter 2'],
                ['value' => 3, 'label' => 'Quarter 3'], ['value' => 4, 'label' => 'Quarter 4'],
            ] : [],
            'coverage_period' => $awsSubmission,
            'date_conducted_required' => $awsSubmission,
            'date_accomplished_required' => false,
            'document_type_required' => $awsSubmission,
            'documents' => $awsSubmission ? ['Progress Report', 'Final Report'] : ['Final Report', 'Progress Report'],
            'date_conducted_ranges_enabled' => in_array($workflow, ['bms', 'bams', 'imea'], true),
            'periods' => ['1st Semester', '2nd Semester'],
            'quarters' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
            'documents' => ['Final Report', 'Progress Report'],
            'mov_url_template' => null,
        ];
    }
}
