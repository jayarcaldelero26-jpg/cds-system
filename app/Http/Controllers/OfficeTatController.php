<?php

namespace App\Http\Controllers;

use App\Services\OfficeTatAnalyticsService;
use App\Services\OfficeTatComplianceAssessmentService;
use App\Services\OfficeTatDocumentTurnaroundService;
use App\Services\OfficeTatObligationEvidenceSetupService;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

final class OfficeTatController extends Controller
{
    public function index(Request $request, OfficeTatAnalyticsService $analytics, OfficeTatComplianceAssessmentService $assessments, OfficeTatDocumentTurnaroundService $documentTurnaround): Response
    {
        $organization = app(OrganizationalAccessService::class);
        $authorizedUnits = $organization->effectiveUnits($request->user());
        $canViewLiveRoutingAverage = $request->user()->can('office-tat.view')
            && $analytics->canAccessCategory($request->user(), 'CENRO');
        $canViewPamoPreparationAverage = $request->user()->can('office-tat.view')
            && $analytics->canAccessCategory($request->user(), 'PAMO');
        $requestedUnit = $request->query('unit');
        $selectedUnit = $requestedUnit && in_array($requestedUnit, $authorizedUnits, true)
            ? $requestedUnit
            : ($authorizedUnits[0] ?? 'conservation');
        $periodKey = $analytics->canonicalPeriodKey($request->query('processing_period'));
        [$today, $asOfDate] = $this->normalizedDateFilter($request);
        $moduleOptions = $assessments->moduleOptions($selectedUnit);
        $validWorkflows = array_column($moduleOptions, 'value');
        $requestedWorkflow = trim((string) $request->query('workflow', 'all'));
        $workflow = in_array($requestedWorkflow, $validWorkflows, true) ? $requestedWorkflow : 'all';
        $periodOptions = collect($assessments->periodOptions());
        if (! $periodOptions->contains(fn (array $option): bool => $option['value'] === $periodKey)) {
            $periodOptions->prepend(['value' => $periodKey, 'label' => $periodKey]);
        }
        $filters = ['unit' => $selectedUnit, 'processing_period' => $periodKey, 'as_of_date' => $asOfDate, 'workflow' => $workflow];
        $recordedAssessment = $assessments->recordedAssessments($request->user(), $selectedUnit, $periodKey, $asOfDate, $workflow);
        return Inertia::render('Admin/OfficeTat/Index', [
            'recordedAssessment' => $recordedAssessment,
            'documentTurnaround' => $documentTurnaround->forAssessment($request->user(), $recordedAssessment),
            'selectedUnit' => $selectedUnit,
            'authorizedUnits' => $authorizedUnits,
            'periodOptions' => $periodOptions->values()->all(),
            'moduleOptions' => $moduleOptions,
            'filters' => $filters,
            'today' => $today,
            'canViewLiveRoutingAverage' => $canViewLiveRoutingAverage,
            'canViewPamoPreparationAverage' => $canViewPamoPreparationAverage,
        ]);
    }

    public function pamoPreparationAverage(Request $request, OfficeTatAnalyticsService $analytics, OfficeTatComplianceAssessmentService $assessments): Response
    {
        $periodKey = $analytics->canonicalPeriodKey($request->query('processing_period'));
        [$today, $asOfDate] = $this->normalizedDateFilter($request);
        $result = $analytics->analytics($request->user(), [
            'category' => 'PAMO',
            'processing_period' => $periodKey,
            'as_of_date' => $asOfDate,
            'evidence_type' => 'live',
        ]);
        $preparation = $result['pamo_preparation'] ?? null;
        $periodOptions = collect($assessments->periodOptions());
        if (! $periodOptions->contains(fn (array $option): bool => $option['value'] === $periodKey)) {
            $periodOptions->prepend(['value' => $periodKey, 'label' => $result['processing_period_label']]);
        }

        return Inertia::render('Admin/OfficeTat/PamoPreparationAverage', [
            'preparation' => $preparation,
            'filters' => ['processing_period' => $periodKey, 'as_of_date' => $asOfDate],
            'periodOptions' => $periodOptions->values()->all(),
            'today' => $today,
            'canViewLiveRoutingAverage' => $request->user()->can('office-tat.view')
                && $analytics->canAccessCategory($request->user(), 'CENRO'),
        ]);
    }

    public function liveRoutingAverage(Request $request, OfficeTatAnalyticsService $analytics, OfficeTatComplianceAssessmentService $assessments): Response
    {
        $periodKey = $analytics->canonicalPeriodKey($request->query('processing_period'));
        [$today, $asOfDate] = $this->normalizedDateFilter($request);
        $result = $analytics->analytics($request->user(), [
            'category' => 'CENRO',
            'processing_period' => $periodKey,
            'as_of_date' => $asOfDate,
            'evidence_type' => 'live',
        ]);
        $periodKey = $result['processing_period'];
        $asOfDate = $result['as_of_date'];
        $authorizedUnits = app(OrganizationalAccessService::class)->effectiveUnits($request->user());
        $requestedReturnUnit = $request->query('return_unit');
        $returnUnit = is_string($requestedReturnUnit) && in_array($requestedReturnUnit, $authorizedUnits, true)
            ? $requestedReturnUnit
            : (in_array('development', $authorizedUnits, true) ? 'development' : ($authorizedUnits[0] ?? 'conservation'));
        $periodOptions = collect($assessments->periodOptions());
        if (! $periodOptions->contains(fn (array $option): bool => $option['value'] === $periodKey)) {
            $periodOptions->prepend(['value' => $periodKey, 'label' => $result['processing_period_label']]);
        }

        return Inertia::render('Admin/OfficeTat/LiveRoutingAverage', [
            'liveAnalytics' => [
                'category' => 'CENRO',
                'processing_period' => $result['processing_period'],
                'processing_period_label' => $result['processing_period_label'],
                'processing_period_start' => $result['processing_period_start'],
                'processing_period_end' => $result['processing_period_end'],
                'as_of_date' => $result['as_of_date'],
                'evidence_type' => 'live',
                'tat_definition' => $result['definitions']['tat'],
                'rows' => array_map(static fn (array $row): array => [
                    'office' => $row['office'],
                    'documents_received' => $row['documents_received'],
                    'tat_completed_documents' => $row['tat_completed_documents'],
                    'tat_elapsed_seconds_total' => $row['tat_elapsed_seconds_total'],
                    'tat_average_elapsed_seconds' => $row['tat_average_elapsed_seconds'],
                    'pending' => $row['pending'],
                    'tat_missing_evidence' => $row['tat_missing_evidence'],
                ], $result['rows']),
            ],
            'filters' => ['processing_period' => $periodKey, 'as_of_date' => $asOfDate, 'return_unit' => $returnUnit],
            'periodOptions' => $periodOptions->values()->all(),
            'today' => $today,
            'canViewPamoPreparationAverage' => $request->user()->can('office-tat.view')
                && $analytics->canAccessCategory($request->user(), 'PAMO'),
        ]);
    }

    /** @return array{0:string,1:string} Manila today and the normalized cutoff date. */
    private function normalizedDateFilter(Request $request): array
    {
        $today = CarbonImmutable::now(\App\Services\BusinessCalendarService::TIMEZONE)->toDateString();
        $requestedAsOf = $request->query('as_of_date');
        $asOfDate = is_string($requestedAsOf) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $requestedAsOf, $dateParts)
            && checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]) && $requestedAsOf <= $today
            ? $requestedAsOf : $today;

        return [$today, $asOfDate];
    }

    public function storeApprovalEvidence(Request $request, OfficeTatObligationEvidenceSetupService $setup): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:applicability,schedule,nonreceipt,classification'],
            'identity' => ['required', 'array'],
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:25600'],
        ]);
        $scopeKey = $setup->uploadScope($request->user(), $data['kind'], $data['identity']);
        $evidence = $setup->uploadApprovalEvidence($request->user(), $request->file('evidence'), $scopeKey);
        return Redirect::back()->with('office_tat_assessment_saved', [
            'kind' => 'evidence', 'version' => $evidence->public_id, 'evidence_reference' => $evidence->public_id,
            'label' => 'Approval evidence registered',
        ]);
    }

    public function downloadApprovalEvidence(Request $request, string $reference, OfficeTatObligationEvidenceSetupService $setup, OrganizationalAccessService $organization): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $evidence = $setup->evidenceForDownload($reference);
        abort_unless($organization->isGlobal($request->user()) || (int) $evidence->uploaded_by === (int) $request->user()?->getKey(), 403);
        return Storage::disk('local')->download($evidence->storage_path, $evidence->original_name, [
            'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $evidence->media_type,
        ]);
    }

    public function storeApplicability(Request $request, OfficeTatObligationEvidenceSetupService $setup): RedirectResponse
    {
        $data = $request->validate([
            'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
            'workflow_key' => ['required', 'string', 'max:120'], 'activity_name' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:120'], 'status' => ['required', 'string', 'in:unknown,applicable,not_applicable'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'approving_authority' => ['nullable', 'string', 'max:180'], 'approval_reference' => ['nullable', 'string', 'max:180'],
            'rationale' => ['required', 'string', 'max:4000'], 'evidence_reference' => ['nullable', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $version = $setup->saveApplicability($request->user(), $data);
        return Redirect::back()->with('office_tat_assessment_saved', ['kind' => 'applicability', 'version' => $version->version, 'label' => 'Applicability fact']);
    }

    public function storeObligationSchedule(Request $request, OfficeTatObligationEvidenceSetupService $setup): RedirectResponse
    {
        $data = $request->validate([
            'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
            'workflow_key' => ['required', 'string', 'max:120'], 'activity_name' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:120'], 'reporting_year' => ['required', 'integer', 'between:2000,2100'],
            'period_key' => ['required', 'string', 'max:80'], 'schedule_kind' => ['required', 'string', 'in:recurring,event'],
            'occurrences' => ['required', 'array', 'max:50'], 'occurrences.*.occurrence_key' => ['required', 'string', 'max:120'],
            'occurrences.*.start_date' => ['required', 'date_format:Y-m-d'], 'occurrences.*.deadline' => ['required', 'date_format:Y-m-d'],
            'inventory_complete' => ['required', 'accepted'], 'approving_authority' => ['required', 'string', 'max:180'],
            'approval_reference' => ['required', 'string', 'max:180'], 'rationale' => ['required', 'string', 'min:10', 'max:4000'],
            'evidence_reference' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $version = $setup->saveSchedule($request->user(), $data);
        return Redirect::back()->with('office_tat_assessment_saved', ['kind' => 'schedule', 'version' => $version->version, 'label' => 'Obligation schedule']);
    }

    public function storeNonReceiptRegister(Request $request, OfficeTatComplianceAssessmentService $assessments, OfficeTatObligationEvidenceSetupService $setup): RedirectResponse
    {
        $data = $request->validate([
            'participant_key' => ['required', 'string', 'max:100'], 'period_key' => ['required', 'regex:/^\d{4}(?:-H[12])?$/'],
            'as_of_date' => ['required', 'date_format:Y-m-d'], 'cutoff_date' => ['required', 'date_format:Y-m-d'],
            'obligation_keys' => ['required', 'array', 'min:1', 'max:500'], 'obligation_keys.*' => ['required', 'string', 'max:300'],
            'approving_authority' => ['required', 'string', 'max:180'], 'approval_reference' => ['required', 'string', 'max:180'],
            'rationale' => ['required', 'string', 'min:10', 'max:4000'], 'evidence_reference' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $unit = str_starts_with($data['participant_key'], 'pa:') ? 'conservation' : 'development';
        abort_unless($data['cutoff_date'] <= $data['as_of_date'] && $data['cutoff_date'] <= now(\App\Services\BusinessCalendarService::TIMEZONE)->toDateString(), 422, 'A historical register cutoff cannot be after the selected assessment or today.');
        $assessment = $assessments->assessments($data['period_key'], $data['as_of_date'])[$unit];
        $row = collect($assessment['rows'])->firstWhere('participant_key', $data['participant_key']);
        abort_unless($row, 422, 'Participant is not in the current assessment roster.');
        $version = $setup->saveNonReceiptRegister($request->user(), $data, $row['obligation_details'] ?? []);
        return Redirect::back()->with('office_tat_assessment_saved', ['kind' => 'nonreceipt', 'version' => $version->version, 'label' => 'Historical non-receipt register']);
    }

    public function storeConfiguration(Request $request, OfficeTatAnalyticsService $analytics): RedirectResponse
    {
        $data = $request->validate([
            'office_category' => ['required', 'string', 'in:PAMO,CENRO'],
            'office' => ['required', 'string', 'max:160'],
            'period_key' => ['required', 'regex:/^\d{4}(?:-H[12])?$/'],
            'volume_target' => ['required', 'integer', 'min:1', 'max:1000000'],
            'weights.timeliness' => ['required', 'numeric', 'between:0,1'],
            'weights.tat_compliance' => ['required', 'numeric', 'between:0,1'],
            'weights.volume' => ['required', 'numeric', 'between:0,1'],
        ]);
        $total = (float) $data['weights']['timeliness'] + (float) $data['weights']['tat_compliance'] + (float) $data['weights']['volume'];
        if (abs($total - 1.0) > 0.00001) {
            throw ValidationException::withMessages(['weights' => 'The three score weights must total 100%.']);
        }

        $version = $analytics->saveConfiguration($request->user(), $data);
        return Redirect::back()->with('office_tat_configuration_saved', [
            'office' => $version->office,
            'period_key' => $version->period_key,
            'version' => $version->version,
        ]);
    }

    public function storeAssessmentScope(Request $request, OfficeTatComplianceAssessmentService $assessments): RedirectResponse
    {
        $data = $request->validate([
            'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
            'workflow_keys' => ['present', 'array', 'max:100'],
            'workflow_keys.*' => ['required', 'string', 'max:120'],
            'coverage_complete' => ['required', 'boolean'],
        ]);
        $version = $assessments->saveScope($request->user(), (int) $data['protected_area_id'], $data['workflow_keys'], (bool) $data['coverage_complete']);
        return Redirect::back()->with('office_tat_assessment_saved', [
            'kind' => 'scope', 'version' => $version->version, 'label' => 'Conservation workflow applicability',
        ]);
    }

    public function storeTechnicalClassification(Request $request, OfficeTatComplianceAssessmentService $assessments): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'in:conservation,engp'],
            'workflow_key' => ['required', 'string', 'max:120'],
            'is_highly_technical' => ['required', 'boolean'],
            'approving_authority' => ['required', 'string', 'max:180'],
            'approval_reference' => ['required', 'string', 'max:180'],
            'rationale' => ['required', 'string', 'min:10', 'max:4000'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'evidence_reference' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $version = $assessments->saveTechnicalClassification(
            $request->user(), $data['domain'], $data['workflow_key'], $request->boolean('is_highly_technical'), $data,
        );
        return Redirect::back()->with('office_tat_assessment_saved', [
            'kind' => 'classification', 'version' => $version->version, 'label' => 'Technical report classification',
        ]);
    }

    public function storeTieDecision(Request $request, OfficeTatComplianceAssessmentService $assessments): RedirectResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'string', 'in:conservation,development'],
            'period_key' => ['required', 'regex:/^\d{4}(?:-H[12])?$/'],
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'population' => ['nullable', 'string', 'in:official,recorded'],
            'metric_fingerprint' => ['required', 'string', 'size:64'],
            'participant_keys' => ['required', 'array', 'min:2', 'max:20'],
            'participant_keys.*' => ['required', 'string', 'max:100'],
            'ordered_participant_keys' => ['required', 'array', 'min:2', 'max:20'],
            'ordered_participant_keys.*' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $decision = $assessments->saveTieDecision(
            $request->user(), $data['unit'], $data['period_key'], $data['metric_fingerprint'],
            $data['participant_keys'], $data['ordered_participant_keys'], $data['reason'], $data['as_of_date'],
            $data['population'] ?? 'official',
        );
        return Redirect::back()->with('office_tat_assessment_saved', [
            'kind' => 'tie', 'version' => $decision->id, 'label' => 'Manual tie order',
        ]);
    }
}
