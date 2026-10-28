<?php

namespace App\Http\Controllers;

use App\Models\ConservationReportSubmission;
use App\Models\ModuleDefinition;
use App\Models\ProtectedArea;
use App\Models\SubmissionRoutingSnapshot;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\DateConductedRangeService;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\HistoricalReports\ConservationHistoricalEvidenceProjection;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\RoutingAttachmentService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ConservationReportSubmissionController extends Controller
{
    private const PRIMARY_ATTACHMENT_MAX_KB = 102400;

    public function __construct(private readonly ConservationReportWorkflowRegistry $workflows, private readonly ProtectedAttachmentService $attachments, private readonly PambComplianceCalculator $pambCompliance, private readonly PambSubmissionAccessService $pambAccess, private readonly OrganizationalAccessService $organization, private readonly PambMovProcessingService $pambMov, private readonly RoutingAttachmentService $routingAttachments, private readonly DateConductedRangeService $dateConductedRanges, private readonly CurrentDocumentReplacementService $documents, private readonly ReportDocumentAdapterResolver $documentAdapters) {}

    public function index(Request $request, string $workflow): Response
    {
        $meetingWorkflow = $this->pambCompliance->isMeeting($workflow);
        $workflowConfig = $this->workflow($workflow);
        $configuredGenericFlow = (bool) ($workflowConfig['configured_generic_flow'] ?? false);
        $config = [...$workflowConfig, 'date_conducted_ranges_enabled' => $this->dateConductedRanges->supportsWorkflow($workflow), 'activity_required' => true, 'document_type_required' => $configuredGenericFlow, 'period_required' => $configuredGenericFlow, 'date_conducted_required' => $meetingWorkflow, 'date_accomplished_required' => ! $meetingWorkflow];
        $submissions = $this->pambAccess->scopeQuery(ConservationReportSubmission::query(), $request->user())
            ->where('workflow_key', $workflow)
            ->with(['protectedArea:id,name,short_name', 'movReviewEvents.recordedBy'])
            ->when($request->filled('protected_area_id'), fn ($query) => $query->where('protected_area_id', $request->integer('protected_area_id')))
            ->when($request->filled('reporting_period'), fn ($query) => $query->where('reporting_period', $request->string('reporting_period')->toString()))
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->string('document_type')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(fn ($query) => $query->where('target_office', 'like', "%{$search}%")
                    ->orWhere('activity_name', 'like', "%{$search}%")
                    ->orWhere('document_type', 'like', "%{$search}%")
                    ->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%")));
            })
            ->latest('id')->paginate(10)->withQueryString()
            ->through(fn (ConservationReportSubmission $submission) => $this->submissionData($submission, $request->user()));

        $formScope = app(SubmissionFormScopeService::class)->options($request->user());
        $pamoDraftProps = $this->pambAccess->isPamo($request->user())
            ? app(PamoPambDraftController::class)->index($request, $workflow)
            : [];

        $response = Inertia::render('ConservationReports/Index', [
            'workflow' => $config,
            'submissions' => $submissions,
            'protectedAreas' => $this->pambAccess->isPamo($request->user())
                ? ProtectedArea::query()->whereKey($request->user()->protected_area_id)->orderBy('name')->get(['id', 'name', 'short_name'])
                : app(OrganizationalAccessService::class)->scopeProtectedAreaQuery(ProtectedArea::query(), $request->user(), 'id')->orderBy('name')->get(['id', 'name', 'short_name']),
            ...$formScope,
            'filters' => $request->only(['search', 'protected_area_id', 'reporting_period', 'document_type']),
            ...$pamoDraftProps,
        ]);

        return $response;
    }

    public function store(Request $request, string $workflow): RedirectResponse
    {
        abort_unless(! $this->pambAccess->isPamo($request->user()), 403, 'PAMO reports must be created from a saved draft.');
        if (! $this->workflows->isActive($workflow)) {
            throw ValidationException::withMessages(['workflow' => 'This report module is inactive and cannot accept a new report.']);
        }
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $config = $this->workflow($workflow);
        $validated = $request->validate($this->reportRules($config, requireMov: true, activityName: $request->string('activity_name')->toString()), $this->attachmentMessages($request->string('document_type')->toString()));
        $this->assertNoFutureActualDates($validated, $request, $workflow);
        $validated['target_office'] = $this->resolvedTargetOffice($request, $validated['target_office'] ?? null);
        if ($this->dateConductedRanges->supportsWorkflow($workflow)) { $validated = $this->dateConductedRanges->applyToPayload($validated, $request->input('date_conducted_ranges')); }
        $this->assertScopedWorkflow($request, $workflow, $validated['target_office'], $validated['protected_area_id'] ?? null);
        $file = $request->file('mov');
        unset($validated['mov']);
        try {
            $submission = ConservationReportSubmission::create([...$validated, 'workflow_key' => $workflow, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
        } catch (\Throwable $exception) {
            throw $exception;
        }
        try {
            $adapter = $this->documentAdapters->resolve('conservation-report', $submission, 'mov');
            $this->documents->replaceUsingAdapter($submission, 'conservation-report', 'mov', $file, $adapter, 'mov', (int) $request->user()->id,
                fn (ConservationReportSubmission $record) => $this->assertScopedWorkflow($request, $workflow, $record->target_office, $record->protected_area_id),
                $request->input('remarks'), [], 'UPLOAD');
        } catch (\Throwable $exception) {
            $submission->delete();
            throw $exception;
        }
        if ($request->user()) $this->pambMov->recordUpload($submission->fresh(), $request->user());
        return back()->with('success', 'Conservation report successfully added.');
    }

    public function update(Request $request, string $workflow, ConservationReportSubmission $submission): RedirectResponse
    {
        app(SubmissionTrackingService::class)->assertMutable($submission);
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $config = $this->workflow($workflow);
        $this->ensureWorkflow($workflow, $submission);
        $isPamoCorrection = $this->pambAccess->isPamo($request->user());
        if ($isPamoCorrection) {
            $this->assertPamoCorrectionEdit($request->user(), $workflow, $submission);
            if ($request->filled('protected_area_id') && (int) $request->input('protected_area_id') !== (int) $submission->protected_area_id) {
                abort(403, 'PAMO corrections cannot change the report origin Protected Area.');
            }
            $request->merge([
                'protected_area_id' => $submission->protected_area_id,
                'target_office' => $submission->target_office,
            ]);
        } elseif ($this->pambCompliance->isMeeting($submission->workflow_key)
            && $this->pambMov->status($submission) === PambMovProcessingService::NEEDS_CORRECTION) {
            abort_unless($this->pambAccess->canPerformForSubmission($request->user(), 'submit', $submission), 403);
        }
        if (app(SubmissionTrackingService::class)->isRoutingComplete($submission)) {
            throw ValidationException::withMessages(['submission' => 'Completed submissions are read-only.']);
        }
        $validated = $request->validate($this->reportRules($config, $submission->document_type, activityName: $request->string('activity_name')->toString()), $this->attachmentMessages($request->string('document_type')->toString() ?: $submission->document_type));
        $this->assertNoFutureActualDates($validated, $request, $workflow, $submission);
        $validated['target_office'] = $this->pambAccess->isPamo($request->user())
            ? $submission->target_office
            : $this->resolvedTargetOffice($request, $validated['target_office'] ?? $submission->target_office);
        if ($this->dateConductedRanges->supportsWorkflow($workflow)) { $validated = $this->dateConductedRanges->applyToPayload($validated, $request->input('date_conducted_ranges')); }
        $this->assertScopedWorkflow($request, $workflow, $validated['target_office'], $validated['protected_area_id'] ?? $submission->protected_area_id);
        $hasReplacement = $request->hasFile('mov');
        unset($validated['mov']);
        $attributes = [...$validated, ...($hasReplacement ? ['mov_processing_status' => null, 'mov_submitted_at' => null, 'mov_submitted_by' => null, 'mov_reviewed_at' => null, 'mov_reviewed_by' => null, 'mov_review_remarks' => null] : []), 'updated_by' => $request->user()?->id];
        if ($hasReplacement) {
            $adapter = $this->documentAdapters->resolve('conservation-report', $submission, 'mov');
            $this->documents->replaceUsingAdapter($submission, 'conservation-report', 'mov', $request->file('mov'), $adapter, 'mov', (int) $request->user()->id,
                fn (ConservationReportSubmission $record) => $this->assertScopedWorkflow($request, $workflow, $record->target_office, $record->protected_area_id),
                $request->input('remarks'), $attributes, 'REPLACEMENT');
            $this->pambMov->recordUpload($submission->fresh(), $request->user());
        } else {
            $submission->update($attributes);
        }
        return back()->with('success', 'Conservation report successfully updated.');
    }

    public function destroy(string $workflow, ConservationReportSubmission $submission): RedirectResponse
    {
        abort_unless(! $this->pambAccess->isPamo(request()->user()), 403);
        app(SubmissionTrackingService::class)->assertMutable($submission);
        $this->ensureWorkflow($workflow, $submission);
        if (app(SubmissionTrackingService::class)->isRoutingComplete($submission)) {
            throw ValidationException::withMessages(['submission' => 'Completed submissions are read-only.']);
        }
        $path = $submission->mov_file_path;
        app(\App\Services\Reports\ReportTrackingReferenceLifecycle::class)
            ->deleteSource($submission, fn () => $submission->delete());
        if ($path) $this->attachments->delete($path);
        return back()->with('success', 'Conservation report deleted.');
    }

    public function showMov(string $workflow, ConservationReportSubmission $submission): HttpResponse
    {
        $this->ensureWorkflow($workflow, $submission);
        return $this->attachments->response('conservation-report', $submission, 'mov');
    }

    /** @param array<string, mixed> $config */
    private function reportRules(array $config, ?string $legacyDocumentType = null, bool $requireMov = false, ?string $activityName = null): array
    {
        $activityDocuments = $config['activity_documents'] ?? [];
        $documents = array_values(array_unique(array_filter([
            ...array_merge(...array_values($activityDocuments ?: ['default' => $config['documents'] ?? []])),
            $legacyDocumentType,
        ])));
        $allowedDocuments = $activityName && array_key_exists($activityName, $activityDocuments) ? $activityDocuments[$activityName] : $documents;

        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'target_office' => ['required', 'string', 'max:255'],
            'activity_name' => ['required', 'string', 'max:255'],
            'document_type' => [($config['configured_generic_flow'] ?? false) ? 'required' : 'nullable', 'string', Rule::in(array_values(array_unique([...$allowedDocuments, $legacyDocumentType])))],
            'reporting_period' => [($config['configured_generic_flow'] ?? false) ? 'required' : 'nullable', 'string', Rule::in($config['periods'] ?? [])],
            'date_conducted_ranges' => $this->dateConductedRanges->supportsWorkflow($config['key'] ?? null) ? ['required', 'array', 'min:1'] : [],
            'date_conducted_ranges.*' => $this->dateConductedRanges->supportsWorkflow($config['key'] ?? null) ? ['array'] : [],
            'date_conducted_ranges.*.from' => $this->dateConductedRanges->supportsWorkflow($config['key'] ?? null) ? ['required', 'date_format:Y-m-d'] : [],
            'date_conducted_ranges.*.to' => $this->dateConductedRanges->supportsWorkflow($config['key'] ?? null) ? ['required', 'date_format:Y-m-d'] : [],
            'date_conducted' => $this->pambCompliance->isMeeting($config['key'] ?? null)
                ? ['required', 'date']
                : ['nullable', 'date'],
            'date_accomplished' => $this->pambCompliance->isMeeting($config['key'] ?? null)
                ? ['nullable', 'date', 'after_or_equal:date_conducted']
                : ['required', 'date'],
            'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:'.self::PRIMARY_ATTACHMENT_MAX_KB],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /** The same full source contract used by the scoped PAMO draft promotion endpoint. */
    public function pamoPilotReportRules(string $workflow, bool $requireMov): array
    {
        abort_unless($this->workflows->supportsPamoSubmission($workflow), 404);
        $config = $this->workflow($workflow);
        // PAMO activity_name is a user-entered title; the trusted workflow key
        // selects its document contract independently of that title.
        return $this->reportRules($config, null, $requireMov);
    }

    public function assertPamoPilotActualDates(array $validated, Request $request, string $workflow): void
    {
        abort_unless($this->workflows->supportsPamoSubmission($workflow), 404);
        $this->assertNoFutureActualDates($validated, $request, $workflow);
    }

    public function applyPamoDateConductedRanges(array $validated, string $workflow, mixed $ranges): array
    {
        return $this->dateConductedRanges->supportsWorkflow($workflow)
            ? $this->dateConductedRanges->applyToPayload($validated, $ranges)
            : $validated;
    }

    /** @param array<string, mixed> $validated */
    private function assertNoFutureActualDates(array $validated, Request $request, string $workflow, ?ConservationReportSubmission $existing = null): void
    {
        $dates = app(\App\Services\ActualActivityDateGuard::class);
        if ($this->dateConductedRanges->supportsWorkflow($workflow)) {
            $dates->assertDateConductedRanges(
                $request->input('date_conducted_ranges'),
                $existing?->date_conducted_ranges,
                $existing?->date_conducted,
            );
        } else {
            $dates->assertNotFuture(
                $validated['date_conducted'] ?? null,
                'date_conducted',
                'Date Conducted',
                $existing?->date_conducted,
            );
        }
        $dates->assertNotFuture(
            $validated['date_accomplished'] ?? null,
            'date_accomplished',
            'Date Accomplished',
            $existing?->date_accomplished,
        );
    }

    /** @return array<string, string> */
    private function attachmentMessages(?string $documentType): array
    {
        $label = match ($documentType) {
            'Minutes' => 'Minutes',
            'Reso', 'Resolution' => 'Resolution',
            default => null,
        };

        $messages = [
            'date_accomplished.after_or_equal' => 'Date Accomplished must be on or after Date Conducted.',
        ];

        return $label ? [...$messages,
            'mov.required' => "A {$label} attachment is required.",
            'mov.max' => "The {$label} attachment must not exceed 100 MB.",
        ] : [...$messages,
            'mov.required' => 'A report attachment / MOV is required.',
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ];
    }

    private function deleteMov(ConservationReportSubmission $submission): void
    {
        if ($submission->mov_file_path) {
            $this->attachments->delete($submission->mov_file_path);
        }
    }

    /** @return array<string, mixed> */
    private function workflow(string $key): array
    {
        if ($workflow = $this->workflows->find($key)) {
            return $workflow;
        }

        $module = ModuleDefinition::query()->generic()->where('code', $key)->firstOrFail();
        $period = match ($module->reporting_frequency) {
            'weekly' => ['Weekly'], 'monthly' => ['Monthly'], 'quarterly' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
            'semestral' => ['1st Semester', '2nd Semester'], 'annual' => ['Annual'], 'custom' => ['Custom'], default => [],
        };

        return [
            'key' => $module->code, 'label' => $module->name, 'description' => $module->description,
            'period_field' => 'reporting_period', 'period_label' => 'Reporting Period', 'periods' => $period,
            'activities' => ['General Report'], 'documents' => ['Progress Report', 'Final Report'],
            'activity_documents' => ['General Report' => ['Progress Report', 'Final Report']],
            'days_complied_field' => 'days_complied', 'penro_delay_field' => 'penro_delay',
            'deadline_mode' => $module->deadline_mode, 'default_deadline_days' => $module->default_deadline_days,
            'allow_deadline_override' => $module->allow_deadline_override,
            'module_definition_code' => $module->code,
        ];
    }

    private function ensureWorkflow(string $workflow, ConservationReportSubmission $submission): void
    {
        $this->workflow($workflow);
        abort_unless($submission->workflow_key === $workflow, 404);
        if ($user = request()->user()) {
            $submission->loadMissing('protectedArea');
            abort_unless($this->pambAccess->canView($user, $submission), 403);
        }
    }

    private function assertScopedWorkflow(Request $request, string $workflow, ?string $targetOffice, mixed $protectedAreaId = null): void
    {
        $user = $request->user();
        if (! $user) return;
        if ($this->pambAccess->isCenro($user)) {
            $organization = app(OrganizationalAccessService::class);
            abort_unless($targetOffice !== null && $organization->normalizeOffice($targetOffice) === $organization->normalizeOffice($user->office_designated), 403);

            if ($protectedAreaId !== null) {
                $scopedSubmission = new ConservationReportSubmission([
                    'protected_area_id' => (int) $protectedAreaId,
                    'target_office' => $targetOffice,
                ]);
                $scopedSubmission->setRelation('protectedArea', ProtectedArea::query()->find($protectedAreaId));
                abort_unless($this->pambAccess->canView($user, $scopedSubmission), 403);
            }
        }
        if ($this->pambAccess->isPamo($user)) {
            abort_unless($this->organization->canViewPamoPilotWorkflow($user)
                && $this->workflows->supportsPamoSubmission($workflow, false)
                && $user->protected_area_id !== null
                && (int) $user->protected_area_id === (int) $protectedAreaId, 403);
        }
    }

    private function assertPamoCorrectionEdit(?\App\Models\User $user, string $workflow, ConservationReportSubmission $submission): void
    {
        abort_unless($user && $this->workflows->supportsPamoSubmission($workflow, false), 403);
        $state = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($submission, 'conservation');
        $position = $state['route_position'] ?? [];
        $last = $state['events']->last();
        abort_unless(($position['origin_kind'] ?? null) === 'pamo_pamb'
            && ($position['protected_area_id'] ?? null) === (int) $user->protected_area_id
            && ($state['stage'] ?? null) === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
            && $last?->event_key === 'correction_received'
            && data_get($last->metadata, 'action_key') === 'receive_correction', 403);
    }

    private function resolvedTargetOffice(Request $request, ?string $submittedTargetOffice): ?string
    {
        $user = $request->user();
        $organization = app(OrganizationalAccessService::class);

        // CENRO scope is an authenticated organizational assignment, never a
        // client-controlled form value. This also supports the create form,
        // which intentionally does not ask the operator to re-enter it.
        return $user && $this->pambAccess->isCenro($user)
            ? $organization->normalizeOffice($user->office_designated)
            : $organization->normalizeOffice($submittedTargetOffice);
    }

    /** @return array<string, mixed> */
    private function submissionData(ConservationReportSubmission $submission, ?\App\Models\User $viewer = null): array
    {
        $directPenro = app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission);
        $historical = (bool) $submission->getAttribute('is_historical')
            ? app(ConservationHistoricalEvidenceProjection::class)->forRecord($submission)
            : null;
        $presentation = $historical['record'] ?? $submission;
        $originSnapshot = ! $historical && Schema::hasTable('submission_routing_snapshots')
            ? SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')
                ->where('source_id', $submission->getKey())->first()
            : null;
        $pamoOrigin = $originSnapshot?->origin_kind === 'pamo_pamb';

        $original = $this->attachments->descriptor('conservation-report', $submission, 'mov');

        return [
            ...collect($presentation->toArray())->except(['mov_file_path', 'mov_file_name'])->all(),
            ...($historical ? [
                'deadline_submission' => $historical['deadline_submission'],
                'days_complied' => $historical['days_complied'],
                'timeliness' => $historical['timeliness'],
                'submission_status' => $historical['submission_status'],
                'penro_delay' => $historical['penro_delay'],
                'historical_evidence' => $historical['historical_evidence'],
            ] : []),
            'mov' => $original,
            'mov_url' => $submission->mov_file_path ? $this->attachments->url('conservation-report', $submission, 'mov') : null,
            'current_document' => $this->routingAttachments->currentDescriptor('conservation', (int) $submission->getKey(), $original),
            'pamo_origin' => $pamoOrigin,
            'pamo_origin_office' => $pamoOrigin ? $originSnapshot?->pamo_office_name : null,
            'submission_origin' => $directPenro ? 'PENRO' : 'CENRO',
            'cenro_release_applicable' => ! $directPenro,
            ...($this->dateConductedRanges->supportsWorkflow($submission->workflow_key) ? [
                'date_conducted_display' => $this->dateConductedRanges->display($submission->date_conducted_ranges, $submission->date_conducted),
                'date_conducted_ranges' => $submission->date_conducted_ranges,
            ] : []),
            'mov_processing' => $this->pambMov->present($submission),
            ...($viewer && $this->pambAccess->isPamo($viewer)
                ? ['pamo_correction_ready' => $this->canPamoCorrect($viewer, $submission)]
                : []),
        ];
    }

    private function canPamoCorrect(\App\Models\User $user, ConservationReportSubmission $submission): bool
    {
        if (! $this->workflows->supportsPamoSubmission((string) $submission->workflow_key, false)
            || ! app(OrganizationalAccessService::class)->canViewPamoPilotWorkflow($user)
            || (int) $user->protected_area_id !== (int) $submission->protected_area_id) return false;

        try {
            $state = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($submission, 'conservation');
            $last = $state['events']->last();
            return ($state['route_position']['origin_kind'] ?? null) === 'pamo_pamb'
                && (int) ($state['route_position']['protected_area_id'] ?? 0) === (int) $user->protected_area_id
                && (int) ($state['route_position']['pamo_office_id'] ?? 0) === (int) (app(OrganizationalAccessService::class)->pamoAssignmentForProtectedArea((int) $user->protected_area_id)['pamo_office_id'] ?? 0)
                && ($state['stage'] ?? null) === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
                && $last?->event_key === 'correction_received'
                && data_get($last->metadata, 'action_key') === 'receive_correction';
        } catch (\Throwable) {
            return false;
        }
    }
}
