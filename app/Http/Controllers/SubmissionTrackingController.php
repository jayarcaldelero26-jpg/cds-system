<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\RoutingCorrectionService;
use App\Services\SubmissionTracking\RoutingAttachmentService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\BusinessCalendarService;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\SubmissionTracking\RoutingTransitionLifecycle;
use App\Support\LocalNavigationTrace;
use App\Services\SubmissionTracking\SubmissionStorageStatusPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SubmissionTrackingController extends Controller
{
    public function __construct(private readonly SubmissionTrackingService $tracking, private readonly RoutingCorrectionService $corrections, private readonly PambMovProcessingService $pambMov, private readonly PambSubmissionAccessService $pambAccess, private readonly RoutingAttachmentService $routingAttachments, private readonly DocumentRoutingTransitionService $documentRouting, private readonly CurrentDocumentReplacementService $documents, private readonly ReportDocumentAdapterResolver $documentAdapters, private readonly RoutingTransitionLifecycle $transitionLifecycle) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'module', 'protected_area_id', 'target_office', 'reporting_period', 'reporting_year', 'status']);
        $view = in_array($request->query('view'), ['incoming', 'outgoing', 'history'], true) ? $request->query('view') : 'incoming';
        $focusSource = $request->query('focus_source');
        $focusSource = is_string($focusSource) && in_array($focusSource, ['conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws', 'ipaf-management', 'revenue', 'management-plans'], true)
            ? $focusSource
            : null;
        $focusId = filter_var($request->query('focus_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $focusId = $focusId === false ? null : $focusId;
        $trackingFilters = $focusSource !== null && $focusId !== null
            ? [...$filters, 'focus_source' => $focusSource, 'focus_id' => $focusId]
            : $filters;
        $page = max(1, $request->integer('page', 1));
        $pagination = LocalNavigationTrace::measure($request, 'st_pagination', fn () => $this->tracking->pagination($trackingFilters, $page, 25));
        $selectedSource = $request->string('source')->toString();
        $selectedId = $request->integer('source_id');
        $trackingRecords = null;
        $selectedRecord = null;
        if (filled($selectedSource) && $selectedId > 0) {
            // A selected record must use the same authorized, normalized
            // snapshot as the complete workspace. Reprojecting all sources
            // here duplicated the largest part of an action's redirect GET.
            $trackingRecords = LocalNavigationTrace::measure($request, 'st_selected_projection', fn () => $this->tracking->records($filters, null, false));
            $selectedRecord = LocalNavigationTrace::measure($request, 'st_selected_match', fn () => $trackingRecords->first(fn (array $row): bool => ($row['source'] ?? null) === $selectedSource && (int) ($row['source_id'] ?? 0) === $selectedId));
        }
        // Workspace queues must span the complete authorized filtered set.
        // The snapshot is intentionally paginated for the tracking table, so
        // using it here can hide an actionable record that is outside page 1.
        $workspaceQueues = LocalNavigationTrace::measure($request, 'st_workspace_queues', fn () => $this->tracking->workspaceQueues($filters, $trackingRecords));
        LocalNavigationTrace::context($request, [
            'view' => $view,
            'selected' => $selectedRecord !== null,
            'incoming' => $workspaceQueues['incoming']->count(),
            'outgoing' => $workspaceQueues['outgoing']->count(),
            'history' => $workspaceQueues['history']->count(),
        ]);
        if ($selectedRecord && $request->user()?->hasRole(OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN)) {
            $sourceConfig = $this->tracking->source($selectedSource);
            $selectedModel = $sourceConfig
                ? $sourceConfig['model']::query()->find($selectedId)
                : null;
            if ($selectedModel) {
                $selectedRecord['storage_status'] = app(SubmissionStorageStatusPresenter::class)->present($selectedSource, $selectedModel);
            }
        }
        $filterOptions = LocalNavigationTrace::measure($request, 'st_filter_options', fn () => [
            ...$this->tracking->filterOptions($filters),
            'protectedAreas' => app(OrganizationalAccessService::class)->scopeProtectedAreaQuery(ProtectedArea::query(), $request->user(), 'id')->orderBy('name')->get(['id', 'name']),
        ]);
        $context = LocalNavigationTrace::measure($request, 'st_context', fn () => [
            'archive_checkpoint_enabled' => (bool) config('services.google_drive_archive.enabled'),
            'is_cenro_user' => $this->pambAccess->isCenro($request->user())
                && app(OrganizationalAccessService::class)->canAccessUnit($request->user(), OrganizationalAccessService::CONSERVATION),
            'is_pamo_user' => $this->pambAccess->isPamo($request->user()),
            'is_global_user' => $this->pambAccess->isGlobal($request->user()),
            'can_submit_mov' => $this->pambAccess->canPerform($request->user(), 'submit'),
            'can_review_mov' => $this->pambAccess->canPerform($request->user(), 'review'),
            'can_release_mov' => $this->pambAccess->canPerform($request->user(), 'release'),
            'queue_tabs' => $this->tracking->queueTabs($request->user()),
            'view' => $view,
            'selected_record' => $selectedRecord,
            'selected_source' => $selectedSource ?: null,
            'selected_id' => $selectedId > 0 ? $selectedId : null,
        ]);
        $response = LocalNavigationTrace::measure($request, 'st_response_build', fn () => Inertia::render('SubmissionTracking/Index', [
            'workspaceQueues' => $workspaceQueues,
            'filters' => $filters,
            'focus' => ['source' => $focusSource, 'id' => $focusId],
            'filterOptions' => $filterOptions,
            'trackingContext' => $context,
            'pagination' => $pagination,
        ]));

        return $response;
    }

    public function transition(Request $request, string $source, int $record, string $stage): RedirectResponse
    {
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        abort_unless(app(OrganizationalAccessService::class)->canUseSubmissionTrackingSource($request->user(), $source, $sourceConfig['ability']), 403);
        if ($source === 'conservation') {
            $submission = \App\Models\ConservationReportSubmission::query()->findOrFail($record);
            $compatibility = app(\App\Services\SubmissionTracking\ConservationMeetingRoutingCompatibilityAdapter::class);
            if ($compatibility->applies($submission)) {
                // Legacy PAMB stage URLs remain accepted, but resolve to an
                // authorized action in the shared Conservation transition graph.
                $stage = $compatibility->actionForLegacyStage($submission, $stage) ?? $stage;
                $request->merge(['stage' => $stage]);
            }
        }
        if ($request->hasFile('official_document')) {
            validator($request->all(), ['official_document' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400']])->validate();
            $documentSource = $sourceConfig['model']::query()->findOrFail($record);
            $slotResolution = $this->documentAdapters->resolveOfficialDocumentSlot($source, $documentSource);
            if ($slotResolution['status'] !== ReportDocumentAdapterResolver::SUPPORTED) {
                throw \Illuminate\Validation\ValidationException::withMessages(['official_document' => 'The official report document slot is not configured for this source.']);
            }
            if ($request->exists('official_document_slot') && (string) $request->input('official_document_slot') !== $slotResolution['slot']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['official_document_slot' => 'The official document slot is determined by CDS-SMART and cannot be selected in the request.']);
            }
            if (! $this->officialDocumentOperation($source, $documentSource, $stage)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['official_document' => 'An official document can only be updated when forwarding the submission or resubmitting a corrected document.']);
            }
        }
        // ENGP retains its existing transition contract. Phase 1 generic
        // correction attachment handling does not apply to that source.
        if ($source !== 'engp' && str_starts_with($stage, 'return_for_correction_')) {
            $data = $request->validate([
                'stage' => ['required', 'string'],
                'remarks' => ['nullable', 'string', 'max:2000'],
                'correction_reason_key' => ['required', 'string', Rule::in(['missing_signature', 'missing_endorsement', 'missing_attachment', 'missing_received_copy', 'incomplete_document', 'other'])],
                'correction_detail' => [$request->input('correction_reason_key') === 'other' ? 'required' : 'nullable', 'string', 'max:2000'],
                'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
            ]);
            abort_unless($data['stage'] === $stage, 422);
            $this->transitionWithAttachment($request, $source, $record, $stage, null, $data['remarks'] ?? null, $data['correction_reason_key'], $data['correction_detail'] ?? null, 'correction_reference');

            return back()->with('success', 'Document returned for correction successfully.');
        }
        if ($this->isGenericCorrectionAction($source, $record, $stage)) {
            $data = $request->validate([
                'stage' => ['required', 'string'],
                'remarks' => ['nullable', 'string', 'max:2000'],
                'correction_reason_key' => ['nullable', 'string', Rule::in(['missing_signature', 'missing_endorsement', 'missing_attachment', 'missing_received_copy', 'incomplete_document', 'other'])],
                'correction_detail' => [$request->input('correction_reason_key') === 'other' ? 'required' : 'nullable', 'string', 'max:2000'],
                'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
            ]);
            abort_unless($data['stage'] === $stage, 422);
            $this->transitionWithAttachment($request, $source, $record, $stage, null, $data['remarks'] ?? null, $data['correction_reason_key'] ?? null, $data['correction_detail'] ?? null, 'correction_reference');

            return back()->with('success', 'Document returned for correction successfully.');
        }
        if (in_array($stage, ['receive_correction', 'forward_to_penro_records'], true) && $source === 'conservation') {
            $data = $request->validate([
                'stage' => ['required', 'string'],
                'remarks' => ['nullable', 'string', 'max:2000'],
                'attachment' => [$stage === 'receive_correction' ? 'prohibited' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
            ]);
            abort_unless($data['stage'] === $stage, 422);
            $this->transitionWithAttachment($request, $source, $record, $stage, null, $data['remarks'] ?? null);
            return back()->with('success', $stage === 'receive_correction' ? 'Correction received successfully.' : 'Document released to PENRO Records successfully.');
        }
        if ($this->tracking->usesGenericRouting($source, $record)) {
            $data = $request->validate([
                'stage' => ['required', 'string', Rule::in($this->tracking->genericTransitionKeys($source, $record))],
                'remarks' => ['nullable', 'string', 'max:2000'],
                'correction_reason_key' => ['nullable', 'string', Rule::in(['missing_signature', 'missing_endorsement', 'missing_attachment', 'missing_received_copy', 'incomplete_document', 'other'])],
                'correction_detail' => ['nullable', 'string', 'max:2000'],
                'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
            ]);
            abort_unless($data['stage'] === $stage, 422);
            $this->assertRoutingAttachmentAllowed($source, $record, $stage, $request->hasFile('attachment'));
            if (str_starts_with($stage, 'return_for_correction_')) {
                validator($data, ['correction_reason_key' => ['required', 'string'], 'correction_detail' => [$data['correction_reason_key'] === 'other' ? 'required' : 'nullable', 'string', 'max:2000']])->validate();
            }
            $this->transitionWithAttachment($request, $source, $record, $stage, null, $data['remarks'] ?? null, $data['correction_reason_key'] ?? null, $data['correction_detail'] ?? null);

            return back()->with('success', $this->routingSuccessMessage($stage));
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'stage' => ['nullable', Rule::in([SubmissionTrackingService::CENRO_RELEASE, SubmissionTrackingService::PENRO_RECEIPT, SubmissionTrackingService::REGIONAL_ENDORSEMENT])],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
            'official_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
        ]);
        abort_unless(($data['stage'] ?? $stage) === $stage, 422);
        $this->assertRoutingAttachmentAllowed($source, $record, $stage, $request->hasFile('attachment'));

        $this->transitionWithAttachment($request, $source, $record, $stage, $data['date'], null);

        return back()->with('success', match ($stage) {
            SubmissionTrackingService::CENRO_RELEASE => 'Document released successfully. CENRO MOV processing: 100% complete.',
            SubmissionTrackingService::PENRO_RECEIPT => 'Document received successfully.',
            default => 'Document forwarded successfully.',
        });
    }

    public function internalRouting(Request $request, string $source, int $record, string $stage): RedirectResponse
    {
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        abort_unless(app(OrganizationalAccessService::class)->canUseSubmissionTrackingSource($request->user(), $source, $sourceConfig['ability']), 403);
        abort_unless($source === 'conservation', 404);
        if ($source === 'conservation') {
            $submission = \App\Models\ConservationReportSubmission::query()->with('protectedArea')->findOrFail($record);
            abort_unless($this->pambAccess->canView($request->user(), $submission), 403);
        }
        $timeline = app(\App\Services\SubmissionTracking\PambRoutingTimelineService::class);
        $data = $request->validate([
            'stage' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($timeline): void {
                if (! $timeline->isInternalStageKey((string) $value)) {
                    $fail('This routing stage is not valid for this workflow.');
                }
            }],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
            'official_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'],
        ]);
        abort_unless(($data['stage'] ?? $stage) === $stage, 422);

        $baseStage = $timeline->canonicalStageKey($stage);
        if ($baseStage === \App\Services\SubmissionTracking\PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION) {
            validator($data, ['remarks' => ['required', 'string', 'min:5', 'max:2000']])->validate();
        }

        $compatibility = app(\App\Services\SubmissionTracking\ConservationMeetingRoutingCompatibilityAdapter::class);
        if ($compatibility->applies($submission)) {
            $sharedAction = $compatibility->actionForLegacyStage($submission, $stage);
            abort_unless($sharedAction, 422, 'This legacy routing event is represented by the shared Conservation transition or is no longer current.');
            // Old bookmarked/internal action URLs continue through the shared
            // controller validation, lock, authorization, attachment, and
            // archive lifecycle. No second PAMB custody transition is written.
            $request->merge(['stage' => $sharedAction]);
            return $this->transition($request, $source, $record, $sharedAction);
        }

        abort_unless($this->pambAccess->canRecordInternalRouting($request->user(), $submission, $stage), 403);
        $officialFile = $request->file('official_document');
        $officialOperation = $officialFile ? $timeline->documentOperation($stage) : null;
        if ($officialFile && ! $officialOperation) {
            throw \Illuminate\Validation\ValidationException::withMessages(['official_document' => 'An official document can only be updated when forwarding the submission or resubmitting a corrected document.']);
        }
        if ($officialFile) {
            $resolution = $this->documentAdapters->resolveOfficialDocumentSlot('conservation', $submission);
            if ($resolution['status'] !== ReportDocumentAdapterResolver::SUPPORTED || ! $resolution['adapter'] || blank($resolution['slot'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['official_document' => 'The official report document slot is not configured for this source.']);
            }
        }
        if ($request->hasFile('attachment') && ! $timeline->isCorrectionStageKey($stage) && ! $this->tracking->canAttachRoutingCopy($source, $submission, $stage)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['attachment' => 'A routing document copy cannot be attached to this receipt-only or completed action.']);
        }
        $file = $request->file('attachment');
        $path = $file ? $this->routingAttachments->store($file) : null;
        $officialPath = null;
        $attachmentPurpose = $timeline->isCorrectionStageKey($stage) ? 'correction_reference' : 'routing_copy';
        $committed = false;
        try {
            DB::transaction(function () use ($source, $record, $stage, $data, $request, $file, $path, $officialFile, $officialOperation, &$officialPath, $attachmentPurpose): void {
                $event = $this->tracking->recordInternalRouting($source, $record, $stage, now(BusinessCalendarService::TIMEZONE)->toDateTimeString(), $request->user()?->id, $data['remarks'] ?? null);
                if ($officialFile) $officialPath = $this->replaceOfficialDocument($source, $record, $stage, $officialFile, $request->user(), $data['remarks'] ?? null, $event, $officialOperation);
                if ($file && $path) $this->routingAttachments->create($source, $record, $file, $path, $request->user(), $stage, $stage, $data['remarks'] ?? null, null, $event, $attachmentPurpose);
                $this->transitionLifecycle->afterTransition($event, $request->user());
            });
            $committed = true;
        } catch (\Throwable $exception) {
            if ($path && ! $committed) $this->routingAttachments->discard($path);
            if ($officialPath && ! $committed) \Illuminate\Support\Facades\Storage::disk(CurrentDocumentReplacementService::DISK)->delete($officialPath);
            throw $exception;
        }

        return back()->with('success', $this->routingSuccessMessage($stage));
    }

    private function routingSuccessMessage(string $action): string
    {
        $key = strtolower($action);

        if (str_contains($key, 'correction') || str_starts_with($key, 'return_')) {
            return 'Document returned for correction successfully.';
        }
        if (str_contains($key, 'approv')) {
            return 'Routing approval recorded successfully.';
        }
        if (str_contains($key, 'receive') || str_contains($key, 'receipt') || str_starts_with($key, 'received_')) {
            return 'Document received successfully.';
        }
        if (str_contains($key, 'release_to_regional') || str_contains($key, 'released_to_regional')) {
            return 'Document released successfully.';
        }
        if (str_contains($key, 'forward') || str_contains($key, 'assign') || str_contains($key, 'recommend') || str_contains($key, 'release')) {
            return 'Document forwarded successfully.';
        }

        return 'Routing event recorded successfully.';
    }

    public function submitMovForReview(Request $request, string $source, int $record): RedirectResponse
    {
        abort_unless($source === 'conservation', 404);
        $submission = \App\Models\ConservationReportSubmission::query()->with('protectedArea')->findOrFail($record);
        $this->pambMov->submit($submission, $request->user());

        return back()->with('success', 'MOV/report submitted for CENRO CDS Chief review.');
    }

    public function reviewMov(Request $request, string $source, int $record): RedirectResponse
    {
        abort_unless($source === 'conservation', 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in([PambMovProcessingService::READY_FOR_RELEASE, PambMovProcessingService::NEEDS_CORRECTION])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['decision'] === PambMovProcessingService::NEEDS_CORRECTION) {
            validator($data, ['remarks' => ['required', 'string', 'min:5', 'max:2000']])->validate();
        }
        $submission = \App\Models\ConservationReportSubmission::query()->with('protectedArea')->findOrFail($record);
        $this->pambMov->review($submission, $request->user(), $data['decision'], $data['remarks'] ?? null);

        return back()->with('success', $data['decision'] === PambMovProcessingService::READY_FOR_RELEASE ? 'MOV/report marked Ready for Release.' : 'MOV/report returned for correction.');
    }

    public function correctRouting(Request $request, string $source, int $record): RedirectResponse
    {
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        $data = $request->validate([
            'dates' => ['required', 'array'],
            'dates.date_report_released_cenro' => ['sometimes', 'nullable', 'date'],
            'dates.date_received_penro' => ['sometimes', 'nullable', 'date'],
            'dates.date_endorsed_regional' => ['sometimes', 'nullable', 'date'],
            'release_events' => ['sometimes', 'array'],
            'release_events.*' => ['nullable', 'date'],
            'internal_events' => ['sometimes', 'array'],
            'internal_events.*' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'password' => ['required', 'current_password:web'],
        ]);

        $this->corrections->correct(
            $source,
            $record,
            $data['dates'],
            $data['release_events'] ?? [],
            trim($data['reason']),
            (int) $request->user()->id,
            $data['internal_events'] ?? [],
        );

        return back()->with('success', 'Routing record corrected successfully.');
    }

    private function transitionWithAttachment(Request $request, string $source, int $record, string $stage, ?string $date, ?string $remarks, ?string $correctionReasonKey = null, ?string $correctionDetail = null, string $attachmentPurpose = 'routing_copy'): void
    {
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        $file = $request->file('attachment');
        $officialFile = $request->file('official_document');
        $officialOperation = null;
        if ($officialFile) {
            $sourceConfig = $this->tracking->source($source);
            abort_unless($sourceConfig, 404);
            $document = $sourceConfig['model']::query()->findOrFail($record);
            $officialOperation = $this->officialDocumentOperation($source, $document, $stage);
            abort_unless($officialOperation, 422);
        }
        $path = $file ? $this->routingAttachments->store($file) : null;
        $officialPath = null;
        $committed = false;
        $routingStartedAt = hrtime(true);
        try {
            DB::transaction(function () use ($request, $source, $record, $stage, $date, $remarks, $file, $path, $officialFile, $officialOperation, &$officialPath, $correctionReasonKey, $correctionDetail, $attachmentPurpose): void {
                $event = (in_array($stage, ['receive_correction', 'forward_to_penro_records'], true) || str_starts_with($stage, 'return_for_correction_')) && $source === 'conservation'
                    ? $this->documentRouting->transition(
                        $this->tracking->source($source)['model']::query()->findOrFail($record),
                        $source,
                        $stage,
                        $request->user()?->id,
                        $remarks,
                        $correctionReasonKey,
                        $correctionDetail,
                    )
                    : $this->tracking->transition($source, $record, $stage, $date, $request->user()?->id, $remarks, $correctionReasonKey, $correctionDetail);
                if ($officialFile) {
                    $officialPath = $this->replaceOfficialDocument($source, $record, $stage, $officialFile, $request->user(), $remarks, $event, $officialOperation);
                }
                if ($file && $path) $this->routingAttachments->create($source, $record, $file, $path, $request->user(), $stage, $stage, $remarks, $event instanceof \App\Models\DocumentRoutingEvent ? $event : null, $event instanceof \App\Models\PambRoutingEvent ? $event : null, $attachmentPurpose);
                if ($event instanceof \App\Models\DocumentRoutingEvent || $event instanceof \App\Models\PambRoutingEvent) {
                    $this->transitionLifecycle->afterTransition($event, $request->user());
                }
            });
            $committed = true;
            \Illuminate\Support\Facades\Log::debug('Submission routing transaction and required lifecycle completed.', [
                'source_type' => $source,
                'action' => $stage,
                'replacement_attached' => $officialFile !== null,
                'duration_ms' => (hrtime(true) - $routingStartedAt) / 1_000_000,
            ]);
        } catch (\Throwable $exception) {
            if ($path && ! $committed) $this->routingAttachments->discard($path);
            if ($officialPath && ! $committed) \Illuminate\Support\Facades\Storage::disk(CurrentDocumentReplacementService::DISK)->delete($officialPath);
            throw $exception;
        }
    }

    private function officialDocumentOperation(string $source, \Illuminate\Database\Eloquent\Model $record, string $stage): ?string
    {
        if (in_array($stage, [SubmissionTrackingService::CENRO_RELEASE, SubmissionTrackingService::REGIONAL_ENDORSEMENT], true)) return 'forward';
        if ($stage === SubmissionTrackingService::PENRO_RECEIPT) return null;

        return $this->documentRouting->documentOperation($record, $source, $stage);
    }

    private function replaceOfficialDocument(string $source, int $recordId, string $stage, \Illuminate\Http\UploadedFile $file, ?\App\Models\User $actor, ?string $remarks, ?\Illuminate\Database\Eloquent\Model $event, ?string $operation): ?string
    {
        abort_unless($actor, 403);
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        $document = $sourceConfig['model']::query()->findOrFail($recordId);
        $resolution = $this->documentAdapters->resolveOfficialDocumentSlot($source, $document);
        if ($resolution['status'] !== ReportDocumentAdapterResolver::SUPPORTED
            || ! $resolution['adapter']
            || $resolution['slot'] === null
            || $resolution['slot'] === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['official_document' => 'The official report document slot is not configured for this source.']);
        }
        $slot = $resolution['slot'];
        $adapter = $resolution['adapter'];
        abort_unless($operation, 422);
        $action = $operation === 'correction_resubmission' ? 'CORRECTION' : 'RELEASE';
        $result = $this->documents->replaceUsingAdapter(
            $document, $source, $slot, $file, $adapter, $slot,
            (int) $actor->getKey(),
            function (\Illuminate\Database\Eloquent\Model $locked) use ($actor, $source): void {
                abort_unless(app(OrganizationalAccessService::class)->canUseSubmissionTrackingSource($actor, $source, $this->tracking->source($source)['ability'] ?? null), 403);
                if ($locked instanceof \App\Models\ConservationReportSubmission) abort_unless($this->pambAccess->canView($actor, $locked), 403);
            },
            $remarks,
            [],
            $action,
        );
        return in_array($result['status'], ['uploaded', 'replaced'], true) ? $result['path'] : null;
    }

    private function assertRoutingAttachmentAllowed(string $source, int $record, string $stage, bool $hasAttachment): void
    {
        if (! $hasAttachment) return;

        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        $submission = $sourceConfig['model']::query()->findOrFail($record);
        if (! $this->tracking->canAttachRoutingCopy($source, $submission, $stage)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['attachment' => 'A routing document copy cannot be attached to this receipt-only or completed action.']);
        }
    }

    private function isGenericCorrectionAction(string $source, int $record, string $stage): bool
    {
        if ($source === 'engp') return false;
        $sourceConfig = $this->tracking->source($source);
        if (! $sourceConfig || ! $this->tracking->usesGenericRouting($source, $record)) return false;

        $submission = $sourceConfig['model']::query()->findOrFail($record);

        return $this->documentRouting->isCorrectionAction($submission, $source, $stage);
    }
}
