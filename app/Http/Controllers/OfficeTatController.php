<?php

namespace App\Http\Controllers;

use App\Services\OfficeTatAnalyticsService;
use App\Services\OfficeTatComplianceAssessmentService;
use App\Services\OfficeTatObligationEvidenceSetupService;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class OfficeTatController extends Controller
{
    public function index(Request $request, OfficeTatAnalyticsService $analytics, OfficeTatComplianceAssessmentService $assessments): Response|RedirectResponse
    {
        $filters = $request->only([
            'category', 'processing_period', 'as_of_date', 'reporting_period', 'reporting_year',
            'workflow', 'protected_area_id', 'view', 'unit',
        ]);
        $periodKey = $analytics->canonicalPeriodKey($filters['processing_period'] ?? null);
        $legacyAssessmentState = $request->hasAny(['assessment_type', 'assessment_year', 'semester']);
        if (($filters['processing_period'] ?? null) !== $periodKey || $legacyAssessmentState) {
            $filters['processing_period'] = $periodKey;
            unset($filters['assessment_type'], $filters['assessment_year'], $filters['semester']);

            return Redirect::route('office-tat.index', $filters);
        }
        $filters['processing_period'] = $periodKey;
        $filters['evidence_type'] = 'live';
        $data = $analytics->analytics($request->user(), $filters);

        $organization = app(OrganizationalAccessService::class);
        $authorizedUnits = $organization->effectiveUnits($request->user());
        $requestedUnit = in_array(($filters['unit'] ?? null), ['conservation', 'development'], true) ? $filters['unit'] : null;
        $selectedUnit = $requestedUnit && in_array($requestedUnit, $authorizedUnits, true)
            ? $requestedUnit
            : ($authorizedUnits[0] ?? 'conservation');
        $filters['unit'] = $selectedUnit;
        $filters['view'] = in_array(($filters['view'] ?? null), ['report-performance', 'average-tat'], true)
            ? $filters['view'] : 'report-performance';

        $recordedAssessment = $assessments->recordedAssessments($request->user(), $selectedUnit, $periodKey, $data['as_of_date']);
        $tatSamplesBySource = collect($data['rows'] ?? [])->flatMap(fn (array $row): array => $row['tat_interval_details'] ?? [])
            ->filter(fn (array $sample): bool => filled($sample['source_key'] ?? null))
            ->countBy(fn (array $sample): string => $sample['source_key'].':'.($sample['workflow_key'] ?? $sample['source_key']));
        if (isset($recordedAssessment['module_coverage']) && is_array($recordedAssessment['module_coverage'])) {
            foreach ($recordedAssessment['module_coverage'] as &$moduleCoverage) {
                $sampleKey = $moduleCoverage['source_key'].':'.($moduleCoverage['workflow_key'] ?? $moduleCoverage['source_key']);
                $moduleCoverage['live_tat_samples'] = (int) ($tatSamplesBySource[$sampleKey] ?? 0);
            }
            unset($moduleCoverage);
        }
        return Inertia::render('Admin/OfficeTat/Index', [
            'analytics' => $data,

            'recordedAssessment' => $recordedAssessment,
            'selectedUnit' => $selectedUnit,
            'authorizedUnits' => $authorizedUnits,
            'selectedView' => $filters['view'],
            'filters' => $filters,
            'canConfigure' => $request->user()?->can('office-tat.configure') ?? false,
            'configurationSaved' => $request->session()->get('office_tat_configuration_saved'),

        ]);
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
