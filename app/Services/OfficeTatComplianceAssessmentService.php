<?php

namespace App\Services;

use Brick\Math\BigRational;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\OfficeTatAssessmentScopeVersion;
use App\Models\OfficeTatTechnicalClassificationVersion;
use App\Models\OfficeTatTieDecision;
use App\Models\OfficeTatApplicabilityVersion;
use App\Models\OfficeTatObligationScheduleVersion;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\HistoricalReports\ConservationHistoricalEvidenceProjection;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Reports\ReportRequirementRegistry;
use App\Services\Authorization\OrganizationalAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Compliance-first report assessment. Average receipt TAT is computed elsewhere and remains unchanged. */
final class OfficeTatComplianceAssessmentService
{
    private const EXPECTED_PA_ROSTER = 6;
    private const EXPECTED_CENRO_ROSTER = 4;
    private const ENGP_CBEP_ALLOWED_CALENDAR_DAYS = 'engp_cbep_allowed_calendar_days';
    private const CONSERVATION_NATIVE_DAY_MODE = 'conservation_native_working_days';
    public const POLICY_EFFECTIVE_DATE = '2026-10-11';
    /** @var array<int,bool> */
    private array $evidenceValidity = [];
    public function __construct(
        private readonly ReportRequirementRegistry $requirements,
        private readonly EngpReportWorkflowRegistry $engp,
        private readonly BusinessCalendarService $calendar,
        private readonly OfficeTatObligationEvidenceSetupService $obligationEvidence,
        private readonly OrganizationalAccessService $organization,
    ) {}

    /** @return array{conservation:array<string,mixed>,development:array<string,mixed>} */
    public function assessments(string $periodKey, string $asOfDate): array
    {
        [$periodStart, $periodEnd] = $this->assessmentBounds($periodKey);
        $asOf = CarbonImmutable::parse($asOfDate, BusinessCalendarService::TIMEZONE)->startOfDay();
        $cutoff = $asOf->lessThan($periodEnd) ? $asOf : $periodEnd;

        $areas = ProtectedArea::query()->with('pamoOfficeAssignment.office')->orderBy('id')->get();
        $cenros = OrganizationalOffice::query()->whereRaw('LOWER(office_type) = ?', ['cenro'])->where('is_active', true)->orderBy('id')->get();
        $classifications = $this->latestClassifications($cutoff->toDateString());

        $scopeVersions = Schema::hasTable('office_tat_assessment_scope_versions')
            ? OfficeTatAssessmentScopeVersion::query()->where('unit', 'conservation')->orderBy('version')->get()->groupBy('protected_area_id')->map(fn ($versions) => $versions->last())
            : collect();
        $conservationRows = $areas->map(function (ProtectedArea $area) use ($scopeVersions): array {
            $scope = $scopeVersions->get((string) $area->id) ?? $scopeVersions->get($area->id);
            return [...$this->participant(
                'conservation', 'pa:'.$area->getKey(), (string) $area->name,
                $area->pamoOfficeAssignment?->office?->name,
                (string) ($area->status ?? 'Unknown'),
            ), 'scope_version_id' => $scope?->id, 'scope_workflow_keys' => $scope?->workflow_keys ?? [], 'scope_configured_complete' => (bool) ($scope?->coverage_complete ?? false)];
        })->all();
        $developmentRows = $cenros->map(fn (OrganizationalOffice $office): array => $this->participant(
            'development', 'cenro:'.$office->getKey(), (string) $office->name, null, $office->is_active ? 'Active' : 'Inactive',
        ))->all();

        $conservation = $this->conservationAssessment($conservationRows, $periodKey, $periodStart, $periodEnd, $cutoff, $classifications);
        $development = $this->developmentAssessment($developmentRows, $periodKey, $periodStart, $periodEnd, $cutoff, $classifications);

        $assessmentYear = (int) substr($periodKey, 0, 4);
        $conservation['applicability_catalogue'] = $this->obligationEvidence->catalogue();
        $conservation['setup_snapshots'] = $areas->mapWithKeys(fn (ProtectedArea $area): array => [
            'pa:'.$area->getKey() => $this->obligationEvidence->snapshot((int) $area->getKey(), $assessmentYear),
        ])->all();

        $workflowOptions = collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic'))
            ->map(fn (array $workflow): array => ['value' => (string) $workflow['key'], 'label' => (string) $workflow['label']])->values()->all();
        $nonreceiptRegisters = $this->obligationEvidence->schemaReady()
            ? \App\Models\OfficeTatNonReceiptRegisterVersion::query()->orderBy('version')->get()
                ->groupBy(fn ($item) => $item->participant_key.'|'.$item->cutoff_date->toDateString())
                ->map(fn ($versions) => $versions->last())
                ->map(fn ($item) => ['participant_key' => $item->participant_key, 'cutoff_date' => $item->cutoff_date->toDateString(), 'version' => $item->version, 'obligation_keys' => $item->obligation_keys, 'obligation_manifest' => $item->obligation_manifest, 'approving_authority' => $item->approving_authority, 'approval_reference' => $item->approval_reference, 'evidence_reference' => \App\Models\OfficeTatApprovalEvidence::query()->find($item->evidence_id)?->public_id])
                ->values()->all()
            : [];
        return ['conservation' => [...$conservation, 'workflow_options' => $workflowOptions], 'development' => $development,
            'classification_catalogue' => $this->classificationCatalogue($classifications),
            'nonreceipt_registers' => $nonreceiptRegisters,
            'configuration_schema_ready' => Schema::hasTable('office_tat_assessment_scope_versions')
                && Schema::hasTable('office_tat_technical_classification_versions') && Schema::hasTable('office_tat_tie_decisions')
                && $this->obligationEvidence->schemaReady() && Schema::hasColumn('office_tat_technical_classification_versions', 'evidence_id')];
    }

    /** @return array<string,mixed> */
    public function saveScope(User $actor, int $protectedAreaId, array $workflowKeys, bool $coverageComplete): OfficeTatAssessmentScopeVersion
    {
        abort_unless($actor->can('office-tat.configure'), 403);
        abort_unless(Schema::hasTable('office_tat_assessment_scope_versions'), 409, 'Install the Office TAT compliance assessment schema before saving scope versions.');
        $area = ProtectedArea::query()->findOrFail($protectedAreaId);
        $allowed = $this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic')->pluck('key')->all();
        $keys = collect($workflowKeys)->map(fn ($key): string => (string) $key)->unique()->values()->all();
        if (array_diff($keys, $allowed) !== []) {
            throw ValidationException::withMessages(['workflow_keys' => 'Select only active Conservation report workflows from the approved catalogue.']);
        }

        return DB::transaction(function () use ($actor, $area, $keys, $coverageComplete): OfficeTatAssessmentScopeVersion {
            $version = (int) OfficeTatAssessmentScopeVersion::query()->where('unit', 'conservation')->where('protected_area_id', $area->id)->max('version') + 1;
            return OfficeTatAssessmentScopeVersion::query()->create([
                'unit' => 'conservation', 'protected_area_id' => $area->id, 'workflow_keys' => $keys,
                'coverage_complete' => $coverageComplete, 'version' => $version, 'configured_by' => $actor->id,
            ]);
        });
    }

    public function saveTechnicalClassification(User $actor, string $domain, string $workflowKey, bool $highlyTechnical, array $approval = []): OfficeTatTechnicalClassificationVersion
    {
        abort_unless($actor->can('office-tat.configure'), 403);
        abort_unless(Schema::hasTable('office_tat_technical_classification_versions'), 409, 'Install the Office TAT compliance assessment schema before saving technical classifications.');
        $workflows = $domain === 'conservation'
            ? collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic'))->pluck('key')->all()
            : collect($this->engp->all())->pluck('key')->all();
        abort_unless(in_array($workflowKey, $workflows, true), 422, 'The workflow is not in the active approved catalogue.');

        $this->obligationEvidence->uploadScope($actor, 'classification', ['domain' => $domain, 'workflow_key' => $workflowKey]);
        $scopeKey = $this->obligationEvidence->evidenceScopeForClassification($domain, $workflowKey);
        $evidence = \App\Models\OfficeTatApprovalEvidence::query()->where('public_id', $approval['evidence_reference'] ?? '')->where('scope_key', $scopeKey)->first();
        if (! $evidence || ! $this->obligationEvidence->evidenceFileIsValid((int) $evidence->id)
            || trim((string) ($approval['approving_authority'] ?? '')) === '' || trim((string) ($approval['approval_reference'] ?? '')) === ''
            || mb_strlen(trim((string) ($approval['rationale'] ?? ''))) < 10 || empty($approval['effective_from'])) {
            throw ValidationException::withMessages(['approval' => 'Classification requires a stored evidence file, authority, written reference, rationale and effective bounds.']);
        }
        if (! empty($approval['effective_to']) && $approval['effective_to'] < $approval['effective_from']) {
            throw ValidationException::withMessages(['effective_to' => 'Effective-to must be on or after effective-from.']);
        }

        return DB::transaction(function () use ($actor, $domain, $workflowKey, $highlyTechnical, $approval, $evidence): OfficeTatTechnicalClassificationVersion {
            $latest = (int) OfficeTatTechnicalClassificationVersion::query()
                ->where('domain', $domain)->where('workflow_key', $workflowKey)->max('version');
            if ((int) ($approval['expected_version'] ?? -1) !== $latest) {
                throw ValidationException::withMessages(['expected_version' => 'This classification changed since it was opened. Refresh and re-enter the approved facts.']);
            }
            $version = $latest + 1;
            return OfficeTatTechnicalClassificationVersion::query()->create([
                'domain' => $domain, 'workflow_key' => $workflowKey, 'is_highly_technical' => $highlyTechnical,
                'approving_authority' => trim($approval['approving_authority']), 'approval_reference' => trim($approval['approval_reference']),
                'rationale' => trim($approval['rationale']), 'effective_from' => $approval['effective_from'],
                'effective_to' => $approval['effective_to'] ?? null, 'evidence_id' => $evidence->id,
                'version' => $version, 'configured_by' => $actor->id,
            ]);
        });
    }

    public function saveTieDecision(User $actor, string $unit, string $periodKey, string $fingerprint, array $participants, array $order, string $reason, string $asOfDate, string $population = 'official'): OfficeTatTieDecision
    {
        abort_unless($actor->can('office-tat.configure'), 403);
        abort_unless(Schema::hasTable('office_tat_tie_decisions'), 409, 'Install the Office TAT compliance assessment schema before resolving ties.');
        abort_unless(in_array($unit, ['conservation', 'development'], true), 422);
        if (count($participants) < 2 || count($participants) !== count(array_unique($participants)) || count($participants) !== count($order)
            || count($order) !== count(array_unique($order)) || array_diff($participants, $order) !== [] || array_diff($order, $participants) !== []) {
            throw ValidationException::withMessages(['order' => 'A tie decision must assign a complete unique order to the exact tied participants.']);
        }
        if (mb_strlen(trim($reason)) < 10) throw ValidationException::withMessages(['reason' => 'Record a reason of at least 10 characters.']);

        abort_unless(in_array($population, ['official', 'recorded'], true), 422);
        $data = $population === 'recorded'
            ? $this->recordedAssessments($actor, $unit, $periodKey, $asOfDate)
            : $this->assessments($periodKey, $asOfDate)[$unit];
        $group = collect($data['tie_groups'] ?? [])->first(fn (array $candidate): bool => $candidate['fingerprint'] === $fingerprint
            && $candidate['participant_keys'] === collect($participants)->sort()->values()->all());
        abort_unless($group && $group['resolvable'], 422, 'The tie has changed or one of its five ranking measures is incomplete. Refresh the assessment before deciding.');
        $sortedOrder = $order;
        abort_unless(count($sortedOrder) === count($participants), 422);

        return OfficeTatTieDecision::query()->create([
            'unit' => $unit, 'period_key' => $periodKey, 'metric_fingerprint' => $fingerprint,
            'participant_keys' => collect($participants)->sort()->values()->all(), 'ordered_participant_keys' => $order,
            'reason' => trim($reason), 'approved_by' => $actor->id, 'approved_at' => now(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function recordedConservationRows(array $participants, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff, array $classifications, ?string $workflowFilter = null): array
    {
        $areas = array_map(fn (array $row): int => (int) substr($row['participant_key'], 3), $participants);
        $pairs = collect($this->obligationEvidence->catalogue())
            ->when($workflowFilter !== null && $workflowFilter !== '' && $workflowFilter !== 'all', fn ($items) => $items->where('workflow_key', $workflowFilter))
            ->values()->all();
        $workflows = collect($pairs)->pluck('workflow_key')->unique()->values()->all();
        $sourceRows = $areas !== [] && $workflows !== []
            ? ConservationReportSubmission::query()->whereIn('protected_area_id', $areas)->whereIn('workflow_key', $workflows)
                ->where(fn ($query) => $query->whereNull('is_historical')->orWhere('is_historical', false))->get()
                ->groupBy(fn (ConservationReportSubmission $record): string => $this->paRecordKey($record))
            : collect();
        $definitions = collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic'))->keyBy('key');
        $year = (int) substr($periodKey, 0, 4);
        $rows = [];

        foreach ($participants as $participant) {
            $paId = (int) substr($participant['participant_key'], 3);
            $obligations = [];
            $incompleteDetails = [];
            $activityCount = $receivedCount = $notYetDueCount = 0;
            $incompleteDates = $incompleteEvidence = 0;
            $recognizedIdentities = [];

            foreach ($pairs as $pair) {
                $workflow = (string) $pair['workflow_key'];
                $definition = $definitions->get($workflow);
                foreach ($this->requirements->periods(ReportRequirementRegistry::PA, $workflow, $year) as $sourcePeriod) {
                    $periodIdentity = $this->paPeriodRecordKey($paId, $workflow, (string) $sourcePeriod['label'], (string) $pair['activity_name'], (string) $pair['document_type']);
                    $recognizedIdentities[$periodIdentity] = true;
                    $matches = $sourceRows->get($periodIdentity, collect())
                        ->filter(fn (ConservationReportSubmission $row): bool => $this->conservationRecordBelongsToYear($row, $year));
                    $liveMatches = $matches;
                    $record = $liveMatches->sort(fn (ConservationReportSubmission $a, ConservationReportSubmission $b): int =>
                        (($a->date_received_penro?->toDateString() ?: '9999-12-31') <=> ($b->date_received_penro?->toDateString() ?: '9999-12-31'))
                        ?: ((int) $a->getKey() <=> (int) $b->getKey())
                    )->first();

                    $catalogStart = $this->requirements->periodStartDate(ReportRequirementRegistry::PA, $workflow, $year, (string) $sourcePeriod['key']);
                    $catalogDeadline = $this->requirements->deadline(ReportRequirementRegistry::PA, $workflow, $year, (string) $sourcePeriod['key']);
                    $catalogRelevant = ($catalogStart && $catalogStart >= $periodStart->toDateString() && $catalogStart <= $periodEnd->toDateString())
                        || ($catalogDeadline && $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($catalogDeadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd));

                    // A normal source row is evidence that this exact PA/workflow/
                    // activity/document target applies. Without it, do not invent
                    // a PA-wide obligation from a catalogue entry alone.
                    $applicabilityEvidence = $sourceRows->flatten()->contains(function (ConservationReportSubmission $row) use ($paId, $workflow, $pair): bool {
                        return (int) $row->protected_area_id === $paId
                            && (string) $row->workflow_key === $workflow
                            && mb_strtolower(trim((string) $row->activity_name)) === mb_strtolower(trim((string) $pair['activity_name']))
                            && mb_strtolower(trim((string) $row->document_type)) === mb_strtolower(trim((string) $pair['document_type']))
                            && ! (bool) $row->getAttribute('is_historical');
                    });
                    if (! $applicabilityEvidence && ! $record) {
                        if ($catalogRelevant) {
                            $incompleteEvidence++;
                            $incompleteDetails[] = $this->recordedIncompleteDetail(
                                $this->recordedPaLabel($pair, $sourcePeriod), 'Applicability not established', $catalogStart, $catalogDeadline, null,
                                'No normal source record or participant assignment confirms that this PA activity/document target applies. It is excluded from N.',
                                null,
                                ['participant_key' => $participant['participant_key'], 'workflow_key' => $workflow,
                                    'workflow_label' => $pair['workflow_label'], 'activity_name' => $pair['activity_name'],
                                    'document_type' => $pair['document_type'], 'reporting_year' => $year, 'period_key' => $sourcePeriod['key']],
                            );
                        }
                        continue;
                    }

                    if ($record) {
                        $activityCount++;
                        $received = $record->date_received_penro?->toDateString();
                        if ($received !== null && $received <= $cutoff->toDateString()) $receivedCount++;
                        $start = $this->conservationRecordStart($record);
                        $deadline = $record->deadline_submission
                            ? CarbonImmutable::parse($record->deadline_submission, BusinessCalendarService::TIMEZONE)->toDateString()
                            : null;
                        if ($start === null || $deadline === null) {
                            $incompleteDates++;
                            $incompleteDetails[] = $this->recordedIncompleteDetail(
                                $this->recordedPaLabel($pair, $sourcePeriod), 'Incomplete source dates', $start, $deadline, $received,
                                'The accepted report is missing its authoritative workflow start or deadline.',
                                $this->recordedSourceReference('conservation', (int) $record->getKey()),
                                ['participant_key' => $participant['participant_key'], 'workflow_key' => $workflow,
                                    'workflow_label' => $pair['workflow_label'], 'activity_name' => $pair['activity_name'],
                                    'document_type' => $pair['document_type'], 'reporting_year' => $year, 'period_key' => $sourcePeriod['key']],
                            );
                            continue;
                        }
                        if (! $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd)) continue;
                    } else {
                        if (in_array($workflow, PambComplianceCalculator::MEETING_WORKFLOWS, true)) {
                            $incompleteEvidence++;
                            $incompleteDetails[] = $this->recordedIncompleteDetail(
                                $this->recordedPaLabel($pair, $sourcePeriod), 'Occurrence inventory unavailable', $catalogStart, $catalogDeadline, null,
                                'The event workflow has no supported occurrence inventory for this period. It is not counted as a missed report.',
                                null,
                                ['participant_key' => $participant['participant_key'], 'workflow_key' => $workflow,
                                    'workflow_label' => $pair['workflow_label'], 'activity_name' => $pair['activity_name'],
                                    'document_type' => $pair['document_type'], 'reporting_year' => $year, 'period_key' => $sourcePeriod['key']],
                            );
                            continue;
                        }
                        if (! $catalogStart || ! $catalogDeadline) {
                            if ($catalogRelevant) {
                                $incompleteDates++;
                                $incompleteDetails[] = $this->recordedIncompleteDetail(
                                    $this->recordedPaLabel($pair, $sourcePeriod), 'Unsupported schedule', $catalogStart, $catalogDeadline, null,
                                    'The active Report Requirement Registry does not provide a supported start and deadline.',
                                    null,
                                    ['participant_key' => $participant['participant_key'], 'workflow_key' => $workflow,
                                        'workflow_label' => $pair['workflow_label'], 'activity_name' => $pair['activity_name'],
                                        'document_type' => $pair['document_type'], 'reporting_year' => $year, 'period_key' => $sourcePeriod['key']],
                                );
                            }
                            continue;
                        }
                        $start = $catalogStart;
                        $deadline = $catalogDeadline;
                        $received = null;
                        if (! $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd)) continue;
                    }

                    $key = implode('|', ['recorded', 'pa:'.$paId, $workflow, $sourcePeriod['key'], sha1($pair['activity_name']."\0".$pair['document_type'])]);
                    $obligation = $this->makeObligation(
                        $key, $start, $deadline, $received, $record?->deadline_submission, null,
                        $record ? (int) $record->getKey() : null, false, 'conservation',
                        (string) ($definition['deadline_mode'] ?? ''), $record?->target_office, null,
                        $this->recordedPaLabel($pair, $sourcePeriod),
                    );
                    $obligation['source_rows_matched'] = $liveMatches->count();
                    $obligation['source_identity'] = [
                        'source_key' => 'conservation', 'source_id' => $record ? (int) $record->getKey() : null,
                        'source_ids' => $liveMatches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                        'participant_key' => $participant['participant_key'], 'workflow_key' => $workflow,
                        'workflow_label' => $pair['workflow_label'], 'activity_name' => $pair['activity_name'],
                        'registered_activity_name' => $pair['activity_name'],
                        'submitted_activity_name' => (string) ($record?->activity_name ?? $pair['activity_name']),
                        'document_type' => $pair['document_type'], 'reporting_year' => $year,
                        'period_key' => $sourcePeriod['key'], 'occurrence_key' => null,
                    ];
                    if (CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE)->greaterThan($cutoff)) $notYetDueCount++;
                    $obligations[] = $obligation;
                }
            }
            $nativeRegistry = app(\App\Services\Pamo\PamoNativeSourceRegistry::class);
            $nativeDefinitions = collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'specialized'))
                ->keyBy('key');
            foreach ($nativeRegistry->all() as $nativeWorkflow => $nativeSource) {
                if ($workflowFilter !== null && $workflowFilter !== '' && $workflowFilter !== 'all' && $workflowFilter !== $nativeWorkflow) continue;
                $nativeDefinition = $nativeDefinitions->get($nativeWorkflow);
                $modelClass = $nativeSource['model'];
                $table = (new $modelClass)->getTable();
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'protected_area_id')) continue;
                $nativeRows = $modelClass::query()->where('protected_area_id', $paId)
                    ->where(fn ($query) => $query->whereNull('is_historical')->orWhere('is_historical', false))->get();
                $liveNativeRows = $nativeRows;
                $workflowPeriods = $this->requirements->periods(ReportRequirementRegistry::PA, $nativeWorkflow, $year);

                if (! $nativeDefinition) {
                    foreach ($liveNativeRows as $unsupportedNative) {
                        $start = $this->nativeRecordStart($unsupportedNative);
                        $deadline = $this->nativeRecordDate($unsupportedNative, ['deadline_submission']);
                        $received = $this->nativeRecordDate($unsupportedNative, ['date_received_penro']);
                        $inPeriod = ($deadline && $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd))
                            || ($start && $start >= $periodStart->toDateString() && $start <= $periodEnd->toDateString());
                        if (! $inPeriod) continue;
                        $incompleteEvidence++;
                        $incompleteDetails[] = $this->recordedIncompleteDetail(
                            trim((string) ($unsupportedNative->getAttribute('activity_name') ?? $nativeSource['label'])),
                            'Missing or inactive workflow definition', $start, $deadline, $received,
                            'This native report family is recognized, but its canonical module definition is missing or inactive; the source is excluded from scoring.',
                            $this->recordedSourceReference($nativeSource['source'], (int) $unsupportedNative->getKey()),
                            ['participant_key' => $participant['participant_key'], 'source_key' => $nativeSource['source'],
                                'source_id' => (int) $unsupportedNative->getKey(), 'workflow_key' => $nativeWorkflow,
                                'workflow_label' => $nativeSource['label'], 'activity_name' => (string) ($unsupportedNative->getAttribute('activity_name') ?? $nativeSource['label']),
                                'document_type' => (string) ($unsupportedNative->getAttribute('document_type') ?? ''),
                                'reporting_year' => $year],
                        );
                    }
                    continue;
                }

                foreach ($workflowPeriods as $sourcePeriod) {
                    $registryStart = $this->requirements->periodStartDate(ReportRequirementRegistry::PA, $nativeWorkflow, $year, (string) $sourcePeriod['key']);
                    $registryDeadline = $this->requirements->deadline(ReportRequirementRegistry::PA, $nativeWorkflow, $year, (string) $sourcePeriod['key']);
                    $matches = $liveNativeRows->filter(fn ($row): bool => $this->nativeRecordMatchesPeriod($row, $sourcePeriod, $year))->values();
                    $record = $matches->sort(fn ($a, $b): int =>
                        (($this->nativeRecordDate($a, ['date_received_penro']) ?: '9999-12-31') <=> ($this->nativeRecordDate($b, ['date_received_penro']) ?: '9999-12-31'))
                        ?: ((int) $a->getKey() <=> (int) $b->getKey())
                    )->first();
                    $catalogRelevant = ($registryStart && $registryStart >= $periodStart->toDateString() && $registryStart <= $periodEnd->toDateString())
                        || ($registryDeadline && $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($registryDeadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd));
                    if (! $record && $liveNativeRows->isEmpty()) {
                        if ($catalogRelevant) {
                            $incompleteEvidence++;
                            $incompleteDetails[] = $this->recordedIncompleteDetail(
                                $nativeSource['label'].' · '.$sourcePeriod['label'], 'Applicability not established', $registryStart, $registryDeadline, null,
                                'No normal native report confirms that this PA target applies. The registry occurrence is excluded from N.',
                                null,
                                ['participant_key' => $participant['participant_key'], 'source_key' => $nativeSource['source'],
                                    'workflow_key' => $nativeWorkflow, 'workflow_label' => $nativeDefinition['label'] ?? $nativeSource['label'],
                                    'activity_name' => $nativeSource['label'], 'document_type' => null,
                                    'reporting_year' => $year, 'period_key' => $sourcePeriod['key']],
                            );
                        }
                        continue;
                    }

                    if ($record) {
                        $activityCount++;
                        $received = $this->nativeRecordDate($record, ['date_received_penro']);
                        if ($received !== null && $received <= $cutoff->toDateString()) $receivedCount++;
                        $start = $this->nativeRecordStart($record);
                        $deadline = $this->nativeRecordDate($record, ['deadline_submission']);
                        if ($start === null || $deadline === null) {
                            $incompleteDates++;
                            $incompleteDetails[] = $this->recordedIncompleteDetail(
                                $nativeSource['label'].' · '.$sourcePeriod['label'], 'Incomplete source dates', $start, $deadline, $received,
                                'The native source is missing its authoritative workflow start or deadline.',
                                $this->recordedSourceReference($nativeSource['source'], (int) $record->getKey()),
                                ['participant_key' => $participant['participant_key'], 'source_key' => $nativeSource['source'],
                                    'source_id' => (int) $record->getKey(), 'workflow_key' => $nativeWorkflow,
                                    'workflow_label' => $nativeDefinition['label'] ?? $nativeSource['label'],
                                    'activity_name' => (string) ($record->getAttribute('activity_name') ?? $nativeSource['label']),
                                    'document_type' => (string) ($record->getAttribute('document_type') ?? ''),
                                    'reporting_year' => $year, 'period_key' => $sourcePeriod['key']],
                            );
                            continue;
                        }
                        if (! $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd)) continue;
                    } else {
                        if (! $registryStart || ! $registryDeadline) {
                            if ($catalogRelevant) {
                                $incompleteDates++;
                                $incompleteDetails[] = $this->recordedIncompleteDetail(
                                    $nativeSource['label'].' · '.$sourcePeriod['label'], 'Unsupported schedule', $registryStart, $registryDeadline, null,
                                    'The canonical native workflow does not provide a supported start and deadline.',
                                    null,
                                    ['participant_key' => $participant['participant_key'], 'source_key' => $nativeSource['source'],
                                        'workflow_key' => $nativeWorkflow, 'workflow_label' => $nativeDefinition['label'] ?? $nativeSource['label'],
                                        'activity_name' => $nativeSource['label'], 'reporting_year' => $year, 'period_key' => $sourcePeriod['key']],
                                );
                            }
                            continue;
                        }
                        $start = $registryStart;
                        $deadline = $registryDeadline;
                        $received = null;
                        if (! $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd)) continue;
                    }

                    $sourceId = $record ? (int) $record->getKey() : null;
                    $activity = trim((string) ($record?->getAttribute('activity_name') ?? $nativeSource['label']));
                    $document = trim((string) ($record?->getAttribute('document_type') ?? ''));
                    $key = implode('|', ['recorded', 'pa:'.$paId, $nativeWorkflow, $year, $sourcePeriod['key']]);
                    $obligation = $this->makeObligation(
                        $key, $start, $deadline, $received, $record ? $deadline : null, null,
                        $sourceId, false, $nativeSource['source'], self::CONSERVATION_NATIVE_DAY_MODE,
                        $record?->getAttribute('target_office'), null,
                        trim($nativeSource['label'].' · '.$sourcePeriod['label'].' · '.$activity.' · '.$document, ' ·'),
                    );
                    $obligation['source_rows_matched'] = $matches->count();
                    $obligation['source_identity'] = [
                        'source_key' => $nativeSource['source'], 'source_id' => $sourceId,
                        'source_ids' => $matches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                        'participant_key' => $participant['participant_key'], 'workflow_key' => $nativeWorkflow,
                        'workflow_label' => $nativeDefinition['label'] ?? $nativeSource['label'],
                        'activity_name' => $activity, 'registered_activity_name' => $nativeDefinition['label'] ?? $nativeSource['label'],
                        'submitted_activity_name' => $activity, 'document_type' => $document,
                        'reporting_year' => $year, 'period_key' => $sourcePeriod['key'], 'occurrence_key' => null,
                    ];
                    if (CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE)->greaterThan($cutoff)) $notYetDueCount++;
                    $obligations[] = $obligation;
                }
            }
            foreach ($sourceRows as $identity => $matches) {
                if (isset($recognizedIdentities[$identity])) continue;
                $liveMatches = $matches;
                $unmapped = $liveMatches->filter(function (ConservationReportSubmission $row) use ($periodStart, $periodEnd): bool {
                    $start = $this->conservationRecordStart($row);
                    $deadline = $row->deadline_submission;
                    return ($start && $start >= $periodStart->toDateString() && $start <= $periodEnd->toDateString())
                        || ($deadline && $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE), $periodStart, $periodEnd));
                });
                if ($unmapped->isNotEmpty() && (int) $unmapped->first()->protected_area_id === $paId) {
                    $incompleteEvidence++;
                    foreach ($unmapped as $sourceRecord) {
                        $start = $this->conservationRecordStart($sourceRecord);
                        $deadline = $sourceRecord->deadline_submission
                            ? CarbonImmutable::parse($sourceRecord->deadline_submission, BusinessCalendarService::TIMEZONE)->toDateString()
                            : null;
                        $received = $sourceRecord->date_received_penro?->toDateString();
                        $registeredActivity = $this->registeredPaActivityFor($sourceRecord, $pairs, $year);
                        $activity = trim((string) $sourceRecord->activity_name);
                        $issue = $registeredActivity !== null
                            ? 'Source activity “'.$activity.'” does not match registered activity “'.$registeredActivity.'”; this report is excluded from live scoring.'
                            : 'The source workflow, activity or document is not in the active reporting registry; this report is excluded from live scoring.';
                        $incompleteDetails[] = $this->recordedIncompleteDetail(
                            $activity !== '' ? $activity : 'Unsupported Conservation report',
                            'Unsupported source identity', $start, $deadline, $received, $issue,
                            $this->recordedSourceReference('conservation', (int) $sourceRecord->getKey()),
                            [
                                'workflow_key' => (string) $sourceRecord->workflow_key,
                                'reporting_period' => (string) $sourceRecord->reporting_period,
                                'activity_name' => $activity,
                                'registered_activity_name' => $registeredActivity,
                                'document_type' => (string) $sourceRecord->document_type,
                                'reporting_year' => $year,
                            ],
                        );
                    }
                }
            }

            $rows[] = $this->recordedParticipantRow($participant, $obligations, $cutoff, [
                'activity_count' => $activityCount, 'received_activity_count' => $receivedCount,
                'not_yet_due_count' => $notYetDueCount, 'incomplete_date_count' => $incompleteDates,
                'incomplete_evidence_count' => $incompleteEvidence,
                'incomplete_details' => $incompleteDetails,
            ]);
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function recordedDevelopmentRows(array $participants, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff, array $classifications, User $user, ?string $workflowFilter = null): array
    {
        $year = (int) substr($periodKey, 0, 4);
        $records = EngpReportSubmission::query()->where('reporting_year', $year)
            ->where(fn ($query) => $query->whereNull('is_historical')->orWhere('is_historical', false));
        $this->organization->scopeDevelopmentQuery($records, $user);
        $records = $records->get()->groupBy(fn (EngpReportSubmission $record): string => $this->engpRecordKey($record));

        $definitions = collect($this->engp->all())
            ->when($workflowFilter !== null && $workflowFilter !== '' && $workflowFilter !== 'all', fn ($items) => $items->filter(fn (array $definition, string $key): bool => $key === $workflowFilter))
            ->keyBy('key');
        $rows = [];

        foreach ($participants as $participant) {
            $office = (string) $participant['name'];
            $officeId = (int) substr($participant['participant_key'], 6);
            $obligations = [];
            $incompleteDetails = [];
            $activityCount = $receivedCount = $notYetDueCount = 0;
            $incompleteDates = $incompleteEvidence = 0;
            $recognizedIdentities = [];

            foreach ($definitions as $workflow => $definition) {
                if (! in_array($office, $definition['offices'] ?? [], true)) continue;
                foreach ($this->engp->periods((string) $workflow, $year) as $sourcePeriod) {
                    $expectedStart = $this->requirements->periodStartDate(ReportRequirementRegistry::ENGP, (string) $workflow, $year, (string) $sourcePeriod['key']);
                    $expectedDeadline = $this->engp->deadline((string) $workflow, $year, (string) $sourcePeriod['key']);
                    if (! $expectedStart || ! $expectedDeadline) continue;
                    $deadlineDate = CarbonImmutable::parse($expectedDeadline, BusinessCalendarService::TIMEZONE)->startOfDay();
                    if (! $this->deadlineInAssessmentPeriod($deadlineDate, $periodStart, $periodEnd)) continue;

                    $identity = $this->engpPeriodRecordKey((string) $workflow, $office, $year, (string) $sourcePeriod['key']);
                    $recognizedIdentities[$identity] = true;
                    $matches = $records->get($identity, collect());
                    $liveMatches = $matches;
                    $record = $liveMatches->sort(fn (EngpReportSubmission $a, EngpReportSubmission $b): int =>
                        (($a->date_received_penro?->toDateString() ?: '9999-12-31') <=> ($b->date_received_penro?->toDateString() ?: '9999-12-31'))
                        ?: ((int) $a->getKey() <=> (int) $b->getKey())
                    )->first();
                    // Do not expand the current report into every future registry
                    // cell. A future, unreceived occurrence is outside the as-of
                    // cohort until an actual source row exists or the deadline is due.
                    if (! $record && $deadlineDate->greaterThan($cutoff)) continue;
                    $received = $record?->date_received_penro?->toDateString();
                    if ($record) {
                        $activityCount++;
                        if ($received !== null && $received <= $cutoff->toDateString()) $receivedCount++;
                    }
                    $storedDeadline = $record?->deadline_submission?->toDateString();
                    if ($record && $storedDeadline === null) {
                        $incompleteDates++;
                        $incompleteDetails[] = $this->recordedIncompleteDetail(
                            (string) $definition['label'].' · '.$sourcePeriod['label'], 'Incomplete source dates', $expectedStart, $expectedDeadline, $received,
                            'The ENGP source deadline is missing; this activity is not scored.',
                            $this->recordedSourceReference('engp', (int) $record->getKey()),
                            ['workflow_key' => (string) $workflow, 'workflow_label' => $definition['label'], 'period_key' => $sourcePeriod['key']],
                        );
                        continue;
                    }
                    if ($record && $storedDeadline !== $expectedDeadline) {
                        $incompleteEvidence++;
                        $incompleteDetails[] = $this->recordedIncompleteDetail(
                            (string) $definition['label'].' · '.$sourcePeriod['label'], 'Unsupported deadline evidence', $expectedStart, $expectedDeadline, $received,
                            'The stored ENGP source deadline does not match the active Report Requirement Registry rule.',
                            $this->recordedSourceReference('engp', (int) $record->getKey()),
                            ['workflow_key' => (string) $workflow, 'workflow_label' => $definition['label'], 'period_key' => $sourcePeriod['key']],
                        );
                        continue;
                    }

                    $key = implode('|', ['cenro:'.$officeId, $workflow, $year, $sourcePeriod['key']]);
                    $obligation = $this->makeObligation(
                        $key, $expectedStart, $expectedDeadline, $received, $storedDeadline, null,
                        $record ? (int) $record->getKey() : null, false, 'engp', $this->engpDayMode((string) $workflow, $definition),
                        null, null, (string) $definition['label'].' · '.$sourcePeriod['label'],
                    );
                    $obligation['source_rows_matched'] = $liveMatches->count();
                    $obligation['source_identity'] = [
                        'source_key' => 'engp', 'source_id' => $record ? (int) $record->getKey() : null,
                        'source_ids' => $liveMatches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                        'participant_key' => $participant['participant_key'], 'workflow_key' => (string) $workflow,
                        'workflow_label' => $definition['label'], 'activity_name' => $definition['activity'],
                        'document_type' => $definition['document'], 'reporting_year' => $year,
                        'period_key' => $sourcePeriod['key'], 'occurrence_key' => null,
                    ];
                    if ($deadlineDate->greaterThan($cutoff)) $notYetDueCount++;
                    $obligations[] = $obligation;
                }
            }
            $supportedWorkflows = $definitions->keys()->all();
            $unsupported = $records->flatten()->filter(function (EngpReportSubmission $record) use ($office, $supportedWorkflows, $periodStart, $periodEnd, $workflowFilter): bool {
                if (mb_strtolower(trim((string) $record->office)) !== mb_strtolower(trim($office))
                    || ($workflowFilter !== null && $workflowFilter !== '' && $workflowFilter !== 'all' && (string) $record->workflow_key !== $workflowFilter)
                    || in_array((string) $record->workflow_key, $supportedWorkflows, true)) return false;

                $dates = [
                    $record->deadline_submission?->toDateString(),
                    $record->date_received_penro?->toDateString(),
                ];
                return collect($dates)->filter()->contains(fn (string $date): bool =>
                    $date >= $periodStart->toDateString() && $date <= $periodEnd->toDateString());
            })->unique(fn (EngpReportSubmission $record): string => $this->engpRecordKey($record));
            foreach ($unsupported as $source) {
                $incompleteEvidence++;
                $deadline = $source->deadline_submission?->toDateString();
                $received = $source->date_received_penro?->toDateString();
                $registeredWorkflow = $this->engp->defaultFind((string) $source->workflow_key);
                $status = $registeredWorkflow === null ? 'Unsupported source identity' : 'Missing or inactive workflow definition';
                $issue = $registeredWorkflow === null
                    ? 'The source workflow key is not registered in the ENGP workflow registry; this report is excluded from live scoring.'
                    : 'This ENGP workflow is recognized, but its canonical module definition is missing or inactive; the source is excluded from live scoring.';
                $incompleteDetails[] = $this->recordedIncompleteDetail(
                    (string) ($source->activity_name ?: 'Unsupported ENGP report'), $status, null, $deadline, $received,
                    $issue,
                    $this->recordedSourceReference('engp', (int) $source->getKey()),
                    [
                        'workflow_key' => (string) $source->workflow_key,
                        'reporting_period' => (string) $source->period_key,
                        'activity_name' => (string) $source->activity_name,
                        'document_type' => (string) $source->document_type,
                        'reporting_year' => (int) $source->reporting_year,
                    ],
                );
            }

            $rows[] = $this->recordedParticipantRow($participant, $obligations, $cutoff, [
                'activity_count' => $activityCount, 'received_activity_count' => $receivedCount,
                'not_yet_due_count' => $notYetDueCount, 'incomplete_date_count' => $incompleteDates,
                'incomplete_evidence_count' => $incompleteEvidence,
                'incomplete_details' => $incompleteDetails,
            ]);
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function recordedParticipantRow(array $participant, array $obligations, CarbonImmutable $cutoff, array $counts): array
    {
        $score = $this->scoreRecordedParticipant($obligations, $cutoff);
        $score['obligation_details'] = array_map(function (array $item) use ($cutoff): array {
            if (($item['status'] ?? null) === 'Non-receipt evidence is not verified'
                && (! ($item['received'] ?? null) || $item['received'] > $cutoff->toDateString())
                && ($item['deadline'] ?? '') <= $cutoff->toDateString()
                && ($item['deadline'] ?? '') >= self::POLICY_EFFECTIVE_DATE) {
                $item['status'] = 'Overdue at cutoff with no accepted receipt';
            }
            return $item;
        }, $score['obligation_details']);
        $score['obligation_details'] = [...$score['obligation_details'], ...($counts['incomplete_details'] ?? [])];
        $score['obligation_details'] = array_map(function (array $item) use ($cutoff): array {
            $item['due_as_of_date'] = $cutoff->toDateString();
            $validDeadline = is_string($item['deadline'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['deadline']);
            $receivedByCutoff = is_string($item['received'] ?? null) && $item['received'] <= $item['due_as_of_date'];
            $receivedAssessedBeforeDeadline = ($item['issue'] ?? null) === null && $receivedByCutoff && $validDeadline && $item['deadline'] > $item['due_as_of_date'];
            $item['due_status'] = $receivedAssessedBeforeDeadline
                ? 'received_before_deadline'
                : ($validDeadline ? ($item['deadline'] <= $item['due_as_of_date'] ? 'due' : 'not_yet_due') : 'unavailable');

            return $item;
        }, $score['obligation_details']);
        return [
            ...$participant, ...$score,
            'activity_count' => (int) ($counts['activity_count'] ?? 0),
            'received_activity_count' => (int) ($counts['received_activity_count'] ?? 0),
            'not_yet_due_count' => (int) ($counts['not_yet_due_count'] ?? 0),
            'incomplete_date_count' => (int) ($counts['incomplete_date_count'] ?? 0),
            'incomplete_evidence_count' => (int) ($counts['incomplete_evidence_count'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function recordedIncompleteDetail(
        string $label,
        string $status,
        ?string $start,
        ?string $deadline,
        ?string $received,
        string $issue,
        ?string $sourceReference = null,
        ?array $sourceIdentity = null,
    ): array {
        if ($sourceReference !== null && preg_match('/^(.+?) report #(\d+)$/i', $sourceReference, $match)) {
            $sourceKey = match (strtolower($match[1])) {
                'conservation' => 'conservation',
                'engp' => 'engp',
                'bms' => 'bms',
                'bams' => 'bams',
                'imea' => 'imea',
                'imea facility maintenance' => 'imea-maintenance',
                'automated weather station' => 'aws',
                'management of ipaf' => 'ipaf-management',
                'revenue collection' => 'revenue',
                default => strtolower(str_replace(' ', '-', $match[1])),
            };
            $sourceIdentity = array_replace($sourceIdentity ?? [], ['source_key' => $sourceKey, 'source_id' => (int) $match[2]]);
        }
        if (isset($sourceIdentity['source_id']) && ! isset($sourceIdentity['source_ids'])) {
            $sourceIdentity['source_ids'] = [(int) $sourceIdentity['source_id']];
        }

        return [
            'label' => $label, 'status' => $status, 'start_date' => $start,
            'deadline' => $deadline, 'received' => $received, 'receipt_endpoint' => 'date_received_penro',
            'allowed_days' => null, 'days_used' => null, 'technical' => null, 'issue' => $issue,
            'source_reference' => $sourceReference, 'source_identity' => $sourceIdentity,
            'source_rows_matched' => $sourceReference === null ? 0 : 1,
        ];
    }

    private function recordedSourceReference(string $source, int $recordId): string
    {
        $label = match ($source) {
            'conservation' => 'Conservation report',
            'engp' => 'ENGP report',
            'bms' => 'BMS report',
            'bams' => 'BAMS report',
            'imea' => 'IMEA report',
            'imea-maintenance' => 'IMEA Facility Maintenance report',
            'aws' => 'Automated Weather Station report',
            'ipaf-management' => 'Management of IPAF report',
            'revenue' => 'Revenue Collection report',
            default => strtoupper($source).' report',
        };

        return $label.' #'.$recordId;
    }

    /** @param list<array<string,mixed>> $pairs */
    private function registeredPaActivityFor(ConservationReportSubmission $record, array $pairs, int $year): ?string
    {
        $workflow = (string) $record->workflow_key;
        $document = mb_strtolower(trim((string) $record->document_type));
        $recordPeriod = mb_strtolower(trim((string) $record->reporting_period));
        $candidates = collect($pairs)->filter(fn (array $pair): bool =>
            (string) $pair['workflow_key'] === $workflow
            && mb_strtolower(trim((string) $pair['document_type'])) === $document
        )->values();
        $activity = mb_strtolower(trim((string) $record->activity_name));
        if ($candidates->contains(fn (array $pair): bool => mb_strtolower(trim((string) $pair['activity_name'])) === $activity)) return null;
        if ($candidates->count() !== 1) return null;
        $periods = $this->requirements->periods(ReportRequirementRegistry::PA, $workflow, $year);
        $periodIsRegistered = collect($periods)->contains(fn (array $period): bool =>
            mb_strtolower(trim((string) $period['label'])) === $recordPeriod
            || mb_strtolower(trim((string) $period['key'])) === $recordPeriod
        );

        return $periodIsRegistered ? (string) $candidates->first()['activity_name'] : null;
    }

    /** @param array<string,mixed> $pair @param array<string,mixed> $period */
    private function recordedPaLabel(array $pair, array $period): string
    {
        return trim(implode(' · ', array_filter([
            $pair['workflow_label'] ?? null, $period['label'] ?? null,
            $pair['activity_name'] ?? null, $pair['document_type'] ?? null,
        ], fn ($value): bool => is_string($value) && trim($value) !== '')));
    }

    private function recordedPaNonReceiptEvidence(int $paId, string $workflow, string $activity, string $document, int $year, string $period, string $start, string $deadline, string $cutoff): ?\App\Models\OfficeTatNonReceiptRegisterVersion
    {
        if (! $this->obligationEvidence->schemaReady() || $deadline >= self::POLICY_EFFECTIVE_DATE) return null;
        $schedules = OfficeTatObligationScheduleVersion::query()->where('protected_area_id', $paId)->where('workflow_key', $workflow)
            ->where('activity_name', $activity)->where('document_type', $document)->where('reporting_year', $year)->where('period_key', $period)->orderByDesc('version')->get();
        foreach ($schedules as $schedule) {
            foreach ($schedule->occurrences ?? [] as $occurrence) {
                if (($occurrence['start_date'] ?? null) !== $start || ($occurrence['deadline'] ?? null) !== $deadline) continue;
                $key = OfficeTatObligationEvidenceSetupService::obligationKey($paId, $workflow, $activity, $document, $year, $period, (string) ($occurrence['occurrence_key'] ?? ''));
                $evidence = $this->obligationEvidence->nonReceiptEvidence('pa:'.$paId, $key, $deadline, $cutoff);
                if ($evidence && $this->validEvidence((int) $evidence->evidence_id)) return $evidence;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function conservationAssessment(array $participants, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff, array $classifications): array
    {
        $ready = $this->obligationEvidence->schemaReady();
        $year = (int) substr($periodKey, 0, 4);
        $areas = collect($participants)->map(fn (array $row): int => (int) substr($row['participant_key'], 3))->all();
        $applicabilityRows = $ready
            ? OfficeTatApplicabilityVersion::query()->whereIn('protected_area_id', $areas)->orderBy('id')->get()->groupBy('protected_area_id')
            : collect();
        $scheduleRows = $ready
            ? OfficeTatObligationScheduleVersion::query()->with('evidence')->whereIn('protected_area_id', $areas)->where('reporting_year', $year)->orderBy('version')->get()->groupBy(fn ($row) => implode('|', [$row->protected_area_id, $row->workflow_key, $row->activity_name, $row->document_type, $row->period_key]))
            : collect();
        $pairs = $this->obligationEvidence->catalogue();
        $workflows = collect($pairs)->pluck('workflow_key')->unique()->values()->all();
        $sourceRows = $ready && $areas !== []
            ? ConservationReportSubmission::query()->whereIn('protected_area_id', $areas)->whereIn('workflow_key', $workflows)->get()
                ->groupBy(fn (ConservationReportSubmission $record): string => $this->paRecordKey($record))
            : collect();
        $origins = Schema::hasTable('historical_report_origins')
            ? HistoricalReportOrigin::query()->where('source', 'conservation')->whereIn('source_record_id', $sourceRows->flatten()->filter(fn ($row) => (bool) $row->getAttribute('is_historical'))->pluck('id'))->get()->keyBy('source_record_id')
            : collect();
        $obligationsByParticipant = [];
        $coverageReasons = [];
        $historicalCount = 0;
        $scopeFingerprint = [];
        $definitions = collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic'))->keyBy('key');

        foreach ($participants as $participant) {
            $paId = (int) substr($participant['participant_key'], 3);
            $versions = $applicabilityRows->get($paId, collect());
            $obligations = [];
            if (! $ready) $coverageReasons[] = $participant['name'].': obligation evidence schema is not installed; approved applicability and occurrence inventory cannot be verified.';

            foreach ($pairs as $pair) {
                $workflow = $pair['workflow_key'];
                $appVersions = $versions->where('workflow_key', $workflow)->where('activity_name', $pair['activity_name'])->where('document_type', $pair['document_type']);
                $appFingerprints = $appVersions->map(fn ($v) => [$v->id, $v->status, $v->effective_from->toDateString(), $v->effective_to?->toDateString(), $v->evidence_id])->all();
                $pairIssueAdded = false;

                // Unknown gaps are explicit blockers; the old coverage checkbox is never consulted.
                for ($day = $periodStart->startOfDay(); $day->lessThanOrEqualTo($cutoff->startOfDay()); $day = $day->addDay()) {
                    $state = $this->applicabilityFromRows($appVersions, $day->toDateString());
                    if (! $state || $state->status === 'unknown') {
                        if (! $pairIssueAdded) $coverageReasons[] = $participant['name'].': applicability is Unknown or has an effective-date gap for '.$workflow.' / '.$pair['activity_name'].' / '.$pair['document_type'].'.';
                        $pairIssueAdded = true;
                        break;
                    }
                }

                $workflowDefinition = $definitions->get($workflow);
                $sourcePeriods = $this->requirements->periods(ReportRequirementRegistry::PA, $workflow, $year);
                foreach ($sourcePeriods as $sourcePeriod) {
                    $catalogStart = $this->requirements->periodStartDate(ReportRequirementRegistry::PA, $workflow, $year, $sourcePeriod['key']);
                    if (! $catalogStart || $catalogStart < $periodStart->toDateString() || $catalogStart > $cutoff->toDateString()) continue;
                    $applicability = $this->applicabilityFromRows($appVersions, $catalogStart);
                    if (! $applicability || $applicability->status === 'unknown') continue;
                    $baseKey = implode('|', [$paId, $workflow, $pair['activity_name'], $pair['document_type'], $sourcePeriod['key']]);
                    if ($applicability->status === 'not_applicable') {
                        $scopeFingerprint[] = [$participant['participant_key'], ...explode('|', $baseKey), 'not_applicable', $applicability->id, $appFingerprints];
                        continue;
                    }

                    $scheduleVersion = ($scheduleRows->get($baseKey, collect()))->last();
                    if (! $scheduleVersion || ! $scheduleVersion->inventory_complete) {
                        $catalogDeadline = $this->requirements->deadline(ReportRequirementRegistry::PA, $workflow, $year, $sourcePeriod['key']);
                        if ($catalogDeadline && $catalogDeadline > $cutoff->toDateString()) continue;
                        $coverageReasons[] = $participant['name'].': approved '.$pair['schedule_kind'].' inventory is missing for '.$workflow.' / '.$sourcePeriod['label'].' / '.$pair['activity_name'].' / '.$pair['document_type'].'.';
                        $scopeFingerprint[] = [$participant['participant_key'], ...explode('|', $baseKey), 'missing_schedule', $applicability->id];
                        continue;
                    }
                    $schedule = $scheduleVersion;
                    $scopeFingerprint[] = [$participant['participant_key'], ...explode('|', $baseKey), $applicability->id, $appFingerprints, $schedule->id, $schedule->version, $schedule->occurrences, $schedule->evidence_id];
                    $periodRows = $sourceRows->get($this->paPeriodRecordKey($paId, $workflow, (string) $sourcePeriod['label'], $pair['activity_name'], $pair['document_type']), collect());
                    $historicalCount += $periodRows->filter(fn ($row) => (bool) $row->getAttribute('is_historical'))->count();
                    $liveRows = $periodRows->reject(fn ($row) => (bool) $row->getAttribute('is_historical'));
                    $usedLiveIds = [];
                    $occurrences = $schedule->occurrences ?? [];
                    if ($schedule->schedule_kind === 'event' && $occurrences === []) {
                        // Explicit empty event inventory: this proves zero meetings, creates zero obligations.
                        if (! $this->validEvidence((int) $schedule->evidence_id) || blank($schedule->approving_authority) || blank($schedule->approval_reference)) {
                            $coverageReasons[] = $participant['name'].': zero-event inventory approval source is missing or invalid for '.$workflow.' / '.$sourcePeriod['label'].'.';
                        }
                        if ($periodRows->isNotEmpty()) $coverageReasons[] = $participant['name'].': source reports exist without an approved event occurrence for '.$workflow.' / '.$sourcePeriod['label'].'.';
                        continue;
                    }

                    foreach ($occurrences as $occurrence) {
                        $occurrenceStart = (string) ($occurrence['start_date'] ?? '');
                        $deadline = (string) ($occurrence['deadline'] ?? '');
                        if ($occurrenceStart === '' || $deadline === '' || $deadline < $occurrenceStart) {
                            $coverageReasons[] = $participant['name'].': an approved '.$workflow.' occurrence has missing or invalid start/deadline evidence.';
                            continue;
                        }
                        if ($this->applicabilityFromRows($appVersions, $occurrenceStart)?->status !== 'applicable') {
                            $coverageReasons[] = $participant['name'].': schedule occurrence falls outside its approved Applicable effective bounds for '.$workflow.'.';
                            continue;
                        }
                        if ($deadline < $periodStart->toDateString() || $deadline > $periodEnd->toDateString() || $deadline > $cutoff->toDateString()) continue;

                        $matchingLive = $liveRows->filter(fn (ConservationReportSubmission $row): bool => $this->conservationRecordStart($row) === $occurrenceStart)->values();
                        $record = $matchingLive->sortBy(fn (ConservationReportSubmission $row) => $row->date_received_penro?->toDateString() ?: '9999-12-31')->first();
                        if ($record) $usedLiveIds[] = $record->id;
                        $historicalMatches = $periodRows->filter(fn (ConservationReportSubmission $row): bool => (bool) $row->getAttribute('is_historical') && $this->conservationRecordStart($row) === $occurrenceStart)->values();
                        $historicalReceipt = null;
                        foreach ($historicalMatches as $historic) {
                            $origin = $origins->get($historic->id);
                            $projection = $origin ? app(ConservationHistoricalEvidenceProjection::class)->project($historic, $origin) : null;
                            $date = $projection['milestones']['date_received_penro'] ?? null;
                            if ($date && $date <= $cutoff->toDateString()) $historicalReceipt = $date;
                        }
                        $key = OfficeTatObligationEvidenceSetupService::obligationKey($paId, $workflow, $pair['activity_name'], $pair['document_type'], $year, $sourcePeriod['key'], (string) ($occurrence['occurrence_key'] ?? ''));
                        $received = $record?->date_received_penro?->toDateString();
                        $nonreceipt = ($received === null || $received > $cutoff->toDateString()) && $historicalReceipt === null
                            ? $this->obligationEvidence->nonReceiptEvidence($participant['participant_key'], $key, $deadline, $cutoff->toDateString())
                            : null;
                        $technical = $classifications['conservation'][$workflow]['is_highly_technical'] ?? null;
                        $obligation = $this->makeObligation(
                            $key, $occurrenceStart, $deadline, $received, $record?->deadline_submission,
                            $technical, $record?->id, false, 'conservation', (string) ($workflowDefinition['deadline_mode'] ?? ''),
                            $record?->target_office, $classifications['conservation'][$workflow]['id'] ?? null,
                            trim((string) $pair['workflow_label'].' · '.$sourcePeriod['label'].' · '.$pair['activity_name'].' · '.$pair['document_type'], ' ·'),
                        );
                        $obligation['source_identity'] = [
                            'participant_key' => $participant['participant_key'], 'workflow_key' => $workflow,
                            'workflow_label' => $pair['workflow_label'],
                        'activity_name' => $pair['activity_name'], 'registered_activity_name' => $pair['activity_name'],
                        'submitted_activity_name' => (string) ($record?->activity_name ?? $pair['activity_name']),
                            'document_type' => $pair['document_type'], 'reporting_year' => $year,
                            'period_key' => $sourcePeriod['key'], 'occurrence_key' => (string) ($occurrence['occurrence_key'] ?? ''),
                        ];
                        $obligation['source_rows_matched'] = $matchingLive->count();
                        $obligation['schedule_version_id'] = $schedule->id;
                        $obligation['applicability_version_id'] = $applicability->id;
                        $obligation['nonreceipt_register_id'] = $nonreceipt?->id;
                        $obligation['nonreceipt_verified'] = $nonreceipt !== null && $this->validEvidence((int) $nonreceipt->evidence_id);
                        $obligation['historical_receipt_conflict'] = $historicalReceipt !== null;
                        $obligation['historical_receipt_evidence'] = $historicalReceipt;
                        $obligation['approval_reference'] = $schedule->approval_reference;
                        $obligation['approving_authority'] = $schedule->approving_authority;
                        $obligation['approval_evidence_reference'] = $schedule->evidence?->public_id;
                        $obligation['occurrence_key'] = (string) ($occurrence['occurrence_key'] ?? '');
                        $obligation['nonreceipt_approving_authority'] = $nonreceipt?->approving_authority;
                        $obligation['nonreceipt_approval_reference'] = $nonreceipt?->approval_reference;
                        $obligation['nonreceipt_evidence_reference'] = $nonreceipt?->evidence?->public_id;
                        if ($record && $record->deadline_submission !== $deadline) $obligation['invalid_reason'] = 'Live source deadline differs from the approved occurrence deadline.';
                        if ($historicalReceipt !== null) $obligation['invalid_reason'] = 'Original historical receipt '.$historicalReceipt.' conflicts with the proposed non-receipt declaration.';
                        if (! $this->validEvidence((int) $schedule->evidence_id)) $obligation['invalid_reason'] = 'Approved schedule evidence is missing or no longer matches its stored digest.';
                        if ($nonreceipt && ! $obligation['nonreceipt_verified']) $obligation['invalid_reason'] = 'Historical non-receipt approval evidence is missing or no longer matches its stored digest.';
                        $obligations[] = $obligation;
                    }
                    if ($liveRows->contains(fn ($row) => ! in_array($row->id, $usedLiveIds, true))) {
                        $coverageReasons[] = $participant['name'].': a live source report is not mapped to an approved occurrence identity for '.$workflow.' / '.$sourcePeriod['label'].'.';
                    }
                }
            }
            $obligationsByParticipant[$participant['participant_key']] = $obligations;
        }

        return $this->finishUnit('conservation', 'Conservation', self::EXPECTED_PA_ROSTER, $participants, $obligationsByParticipant,
            $periodKey, $periodStart, $cutoff, array_values(array_unique($coverageReasons)), $classifications['conservation'] ?? [],
            $historicalCount, $scopeFingerprint);
    }

    private function applicabilityFromRows($rows, string $date): ?OfficeTatApplicabilityVersion
    {
        $state = $rows->filter(fn (OfficeTatApplicabilityVersion $row): bool => $row->effective_from->toDateString() <= $date
            && ($row->effective_to === null || $row->effective_to->toDateString() >= $date))
            ->sortBy(fn (OfficeTatApplicabilityVersion $row): string => $row->effective_from->toDateString().'|'.str_pad((string) $row->version, 10, '0', STR_PAD_LEFT))
            ->last();
        if ($state && $state->status !== 'unknown' && (! $this->validEvidence((int) $state->evidence_id) || blank($state->approving_authority) || blank($state->approval_reference) || blank($state->rationale))) return null;
        return $state;
    }

    private function validEvidence(int $id): bool
    {
        return $id > 0 && ($this->evidenceValidity[$id] ??= $this->obligationEvidence->evidenceFileIsValid($id));
    }

    private function nativeRecordDate(\Illuminate\Database\Eloquent\Model $record, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $record->getAttribute($field);
            if ($value instanceof \Carbon\CarbonInterface) return $value->toDateString();
            if (! is_string($value) || trim($value) === '') continue;
            try {
                return CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function nativeRecordStart(\Illuminate\Database\Eloquent\Model $record): ?string
    {
        return $this->nativeRecordDate($record, ['date_accomplished', 'start_date', 'monitoring_period_start', 'date_conducted']);
    }

    private function nativeRecordMatchesPeriod(\Illuminate\Database\Eloquent\Model $record, array $period, int $year): bool
    {
        $recordYear = $record->getAttribute('reporting_year')
            ?? $record->getAttribute('prepared_year')
            ?? $record->getAttribute('year');
        $sourceStart = $this->nativeRecordStart($record);
        if (is_numeric($recordYear) && (int) $recordYear !== $year) return false;
        if (! is_numeric($recordYear) && $sourceStart && (int) substr($sourceStart, 0, 4) !== $year) return false;

        $normalize = static fn (mixed $value): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', trim((string) $value)));
        $periodKey = (string) ($period['key'] ?? '');
        $periodLabel = (string) ($period['label'] ?? '');
        $key = $normalize($periodKey);
        $label = $normalize($periodLabel);
        $markers = [
            $record->getAttribute('period_key'), $record->getAttribute('period_label'),
            $record->getAttribute('reporting_period'), $record->getAttribute('semester'),
            $record->getAttribute('quarter'), $record->getAttribute('reporting_month'),
            $record->getAttribute('month'),
        ];
        $hasMarker = false;
        foreach ($markers as $marker) {
            if ($marker === null || trim((string) $marker) === '') continue;
            $hasMarker = true;
            $normalized = $normalize($marker);
            if ($normalized === $key || ($label !== '' && $normalized === $label)) return true;
            if (preg_match('/^([qhs]?)([12])$/', $key, $match)) {
                $ordinal = (int) $match[2];
                if (($ordinal === 1 && in_array($normalized, ['s1', 'h1', 'semester1', '1stsemester', 'firstsemester'], true))
                    || ($ordinal === 2 && in_array($normalized, ['s2', 'h2', 'semester2', '2ndsemester', 'secondsemester'], true))) return true;
            }
            if (preg_match('/^q([1-4])$/', $key, $match)) {
                $quarter = (int) $match[1];
                if ($normalized === 'quarter'.$quarter || $normalized === $quarter.'thquarter'
                    || ($quarter === 1 && in_array($normalized, ['1stquarter', 'firstquarter'], true))
                    || ($quarter === 2 && in_array($normalized, ['2ndquarter', 'secondquarter'], true))
                    || ($quarter === 3 && in_array($normalized, ['3rdquarter', 'thirdquarter'], true))
                    || ($quarter === 4 && in_array($normalized, ['4thquarter', 'fourthquarter'], true))) return true;
            }
            if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $match)) {
                $month = (int) $match[2];
                $monthName = strtolower(CarbonImmutable::create((int) $match[1], $month, 1)->format('F'));
                if (in_array($normalized, [(string) $month, str_pad((string) $month, 2, '0', STR_PAD_LEFT), $monthName], true)) return true;
            }
        }
        if ($hasMarker || ! $sourceStart) return false;

        $month = (int) substr($sourceStart, 5, 2);
        if (preg_match('/^(?:s|h)([12])$/', $key, $match)) return ((int) $match[1] === 1) === ($month <= 6);
        if (preg_match('/^q([1-4])$/', $key, $match)) return (int) ceil($month / 3) === (int) $match[1];
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $match)) return substr($sourceStart, 0, 7) === $periodKey;
        if (preg_match('/^\d{4}$/', $periodKey)) return (int) substr($sourceStart, 0, 4) === $year;

        return false;
    }
    private function conservationRecordStart(ConservationReportSubmission $record): ?string
    {
        if (in_array($record->workflow_key, PambComplianceCalculator::MEETING_WORKFLOWS, true)) {
            return app(PambComplianceCalculator::class)->authoritativeDate($record)?->toDateString();
        }
        return $record->date_accomplished?->toDateString();
    }

    private function conservationRecordBelongsToYear(ConservationReportSubmission $record, int $year): bool
    {
        $date = $this->conservationRecordStart($record) ?: $record->deadline_submission;
        if ($date) {
            return CarbonImmutable::parse($date, BusinessCalendarService::TIMEZONE)->year === $year;
        }

        return $record->created_at?->timezone(BusinessCalendarService::TIMEZONE)->year === $year;
    }

    /** @deprecated Kept temporarily as source-history for the pre-evidence implementation. */
    private function legacyConservationAssessment(array $participants, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff, array $classifications): array
    {
        $scopes = Schema::hasTable('office_tat_assessment_scope_versions')
            ? OfficeTatAssessmentScopeVersion::query()->where('unit', 'conservation')->orderBy('version')->get()->groupBy('protected_area_id')->map(fn ($versions) => $versions->last())
            : collect();
        $workflowKeys = $scopes->flatMap(fn ($scope) => $scope->workflow_keys ?? [])->unique()->values()->all();
        $records = $workflowKeys !== [] && $participants !== []
            ? ConservationReportSubmission::query()->whereIn('protected_area_id', collect($participants)->map(fn ($row) => substr($row['participant_key'], 3))->all())->whereIn('workflow_key', $workflowKeys)->get()->groupBy(fn (ConservationReportSubmission $record): string => $this->paRecordKey($record))
            : collect();
        $definitions = collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic'))->keyBy('key');
        $obligationsByParticipant = [];
        $coverageReasons = [];
        $historicalCount = 0;

        foreach ($participants as $participant) {
            $areaId = (int) substr($participant['participant_key'], 3);
            $scope = $scopes->get($areaId);
            $obligations = [];
            if (! $scope || ! $scope->coverage_complete) {
                $coverageReasons[] = $participant['name'].': approved applicable Conservation workflow inventory is missing or incomplete.';
            }
            if ($scope?->coverage_complete && ($scope->workflow_keys ?? []) !== []) {
                $coverageReasons[] = $participant['name'].': per-obligation Conservation start dates and assigned schedule evidence are not yet versioned; date_accomplished is not inferred for a missed report.';
            }
            foreach (($scope?->workflow_keys ?? []) as $workflowKey) {
                $definition = $definitions->get($workflowKey);
                if (! $definition) {
                    $coverageReasons[] = $participant['name'].': an assigned workflow is no longer present in the active catalogue.';
                    continue;
                }
                foreach ($this->requirements->periods(ReportRequirementRegistry::PA, $workflowKey, (int) substr($periodKey, 0, 4)) as $sourcePeriod) {
                    $startValue = $this->requirements->periodStartDate(ReportRequirementRegistry::PA, $workflowKey, (int) substr($periodKey, 0, 4), $sourcePeriod['key']);
                    $deadlineValue = $this->requirements->deadline(ReportRequirementRegistry::PA, $workflowKey, (int) substr($periodKey, 0, 4), $sourcePeriod['key']);
                    if (! $startValue || ! $deadlineValue) {
                        $coverageReasons[] = $participant['name'].': '.$workflowKey.' has no verifiable scheduled start or deadline.';
                        continue;
                    }
                    $activities = $definition['activity_documents'] ?? array_fill_keys($definition['activities'] ?? [''], $definition['documents'] ?? ['Report']);
                    foreach ($activities as $activity => $documents) {
                        foreach ((array) $documents as $document) {
                            $obligationKey = implode('|', ['pa', $areaId, $workflowKey, $sourcePeriod['key'], $activity, $document]);
                            $technical = $classifications['conservation'][$workflowKey]['is_highly_technical'] ?? null;
                            $matches = $records->get($this->paPeriodRecordKey($areaId, $workflowKey, $sourcePeriod['label'], (string) $activity, (string) $document), collect());
                            $historicalCount += $matches->filter(fn (ConservationReportSubmission $item): bool => (bool) $item->getAttribute('is_historical'))->count();
                            $liveMatches = $matches->reject(fn (ConservationReportSubmission $item): bool => (bool) $item->getAttribute('is_historical'));
                            $record = $liveMatches
                                ->sortBy(fn (ConservationReportSubmission $item) => $item->date_received_penro?->toDateString() ?: '9999-12-31')->first();
                            $sourceStart = $record
                                ? (in_array($workflowKey, PambComplianceCalculator::MEETING_WORKFLOWS, true)
                                    ? app(PambComplianceCalculator::class)->authoritativeDate($record)?->toDateString()
                                    : $record->date_accomplished?->toDateString())
                                : null;
                            $sourceDeadline = $record && $record->deadline_submission
                                ? (string) $record->deadline_submission
                                : null;
                            $obligationStart = $sourceStart ?: $startValue;
                            $obligationDeadline = $sourceDeadline ?: $deadlineValue;
                            if (! $this->deadlineInAssessmentPeriod(CarbonImmutable::parse($obligationDeadline, BusinessCalendarService::TIMEZONE)->startOfDay(), $periodStart, $periodEnd)) continue;
                            $obligation = $this->makeObligation($obligationKey, $obligationStart, $obligationDeadline, $record?->date_received_penro?->toDateString(), $record ? $sourceDeadline : null, $technical, $record?->id, false, 'conservation', (string) ($definition['deadline_mode'] ?? ''), $record?->target_office, $classifications['conservation'][$workflowKey]['id'] ?? null,
                            trim((string) $definition['label'].' · '.$sourcePeriod['label'].' · '.(string) $activity.' · '.(string) $document, ' ·'));
                            if ($record && (! $sourceStart || ! $sourceDeadline)) {
                                $obligation['invalid_reason'] = 'The source-specific Conservation start or deadline evidence is unavailable.';
                            }
                            $obligation['source_rows_matched'] = $liveMatches->count();
                            $obligations[] = $obligation;
                        }
                    }
                }
            }
            $obligationsByParticipant[$participant['participant_key']] = $obligations;
        }

        $scopeFingerprint = array_map(fn (array $row): array => [$row['participant_key'], $row['scope_version_id'], $row['scope_workflow_keys'], $row['scope_configured_complete']], $participants);
        return $this->finishUnit('conservation', 'Conservation', self::EXPECTED_PA_ROSTER, $participants, $obligationsByParticipant, $periodKey, $periodStart, $cutoff, $coverageReasons, $classifications['conservation'] ?? [], $historicalCount, $scopeFingerprint);
    }

    /** @return array<string,mixed> */
    private function developmentAssessment(array $participants, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff, array $classifications): array
    {
        $year = (int) substr($periodKey, 0, 4);
        $definitions = collect($this->engp->all())->keyBy('key');
        $records = EngpReportSubmission::query()->where('reporting_year', $year)->get()->groupBy(fn (EngpReportSubmission $record): string => $this->engpRecordKey($record));
        $engpHistoricalIds = $records->flatten()->filter(fn (EngpReportSubmission $row) => (bool) $row->getAttribute('is_historical'))->pluck('id');
        $engpOrigins = Schema::hasTable('historical_report_origins') && $engpHistoricalIds->isNotEmpty()
            ? HistoricalReportOrigin::query()->where('source', 'engp')->whereIn('source_record_id', $engpHistoricalIds)->get()->keyBy('source_record_id')
            : collect();
        $obligationsByParticipant = [];
        $coverageReasons = [];
        $historicalCount = 0;

        foreach ($participants as $participant) {
            $officeName = $participant['name'];
            $officeId = (int) substr($participant['participant_key'], 6);
            $obligations = [];
            foreach ($definitions as $workflowKey => $workflow) {
                if (! in_array($officeName, $workflow['offices'] ?? [], true)) continue;
                foreach ($this->engp->periods($workflowKey, $year) as $sourcePeriod) {
                    $startValue = $this->requirements->periodStartDate(ReportRequirementRegistry::ENGP, $workflowKey, $year, $sourcePeriod['key']);
                    $deadlineValue = $this->engp->deadline($workflowKey, $year, $sourcePeriod['key']);
                    if (! $startValue || ! $deadlineValue) {
                        $coverageReasons[] = $officeName.': '.$workflowKey.' '.$sourcePeriod['key'].' has no verifiable scheduled start or deadline.';
                        continue;
                    }
                    $deadline = CarbonImmutable::parse($deadlineValue, BusinessCalendarService::TIMEZONE)->startOfDay();
                    if (! $this->deadlineInAssessmentPeriod($deadline, $periodStart, $periodEnd)) continue;
                    $obligationKey = implode('|', ['cenro:'.$officeId, $workflowKey, $year, $sourcePeriod['key']]);
                    $technical = $classifications['engp'][$workflowKey]['is_highly_technical'] ?? null;
                    $matches = $records->get($this->engpPeriodRecordKey($workflowKey, $officeName, $year, $sourcePeriod['key']), collect());
                    $historicalCount += $matches->filter(fn (EngpReportSubmission $item): bool => (bool) $item->getAttribute('is_historical'))->count();
                    $liveMatches = $matches->reject(fn (EngpReportSubmission $item): bool => (bool) $item->getAttribute('is_historical'));
                    $record = $liveMatches
                        ->sortBy(fn (EngpReportSubmission $item) => $item->date_received_penro?->toDateString() ?: '9999-12-31')->first();
                    $historicalReceipt = $matches->filter(fn (EngpReportSubmission $row): bool => (bool) $row->getAttribute('is_historical'))
                        ->map(fn (EngpReportSubmission $row) => data_get($engpOrigins->get($row->id)?->original_milestones, 'milestones.date_received_penro'))
                        ->filter(fn ($date) => $date && $date <= $cutoff->toDateString())->sort()->first();
                    $obligation = $this->makeObligation($obligationKey, $startValue, $deadlineValue, $record?->date_received_penro?->toDateString(), $record?->deadline_submission?->toDateString(), $technical, $record?->id, false, 'engp', $this->engpDayMode((string) $workflowKey, $workflow), null, $classifications['engp'][$workflowKey]['id'] ?? null,
                        (string) $workflow['label'].' · '.$sourcePeriod['label']);
                    $obligation['source_identity'] = [
                        'participant_key' => $participant['participant_key'], 'workflow_key' => $workflowKey,
                        'workflow_label' => $workflow['label'], 'activity_name' => $workflow['activity'],
                        'document_type' => $workflow['document'], 'reporting_year' => $year,
                        'period_key' => $sourcePeriod['key'], 'occurrence_key' => null,
                    ];
                    $nonreceipt = (! $record?->date_received_penro || $record->date_received_penro->toDateString() > $cutoff->toDateString()) && ! $historicalReceipt
                        ? $this->obligationEvidence->nonReceiptEvidence($participant['participant_key'], $obligationKey, $deadlineValue, $cutoff->toDateString())
                        : null;
                    $obligation['nonreceipt_register_id'] = $nonreceipt?->id;
                    $obligation['nonreceipt_verified'] = $nonreceipt !== null && $this->obligationEvidence->evidenceFileIsValid((int) $nonreceipt->evidence_id);
                    $obligation['nonreceipt_approving_authority'] = $nonreceipt?->approving_authority;
                    $obligation['nonreceipt_approval_reference'] = $nonreceipt?->approval_reference;
                    $obligation['nonreceipt_evidence_reference'] = $nonreceipt?->evidence?->public_id;
                    $obligation['historical_receipt_conflict'] = $historicalReceipt !== null;
                    $obligation['historical_receipt_evidence'] = $historicalReceipt;
                    if ($historicalReceipt) $obligation['invalid_reason'] = 'Original ENGP historical receipt '.$historicalReceipt.' conflicts with the proposed non-receipt declaration.';
                    if ($nonreceipt && ! $obligation['nonreceipt_verified']) $obligation['invalid_reason'] = 'Historical non-receipt approval evidence is missing or no longer matches its stored digest.';
                    $obligations[] = $obligation;
                    $obligations[array_key_last($obligations)]['source_rows_matched'] = $liveMatches->count();
                }
            }
            $obligationsByParticipant[$participant['participant_key']] = $obligations;
        }

        return $this->finishUnit('development', 'Development', self::EXPECTED_CENRO_ROSTER, $participants, $obligationsByParticipant, $periodKey, $periodStart, $cutoff, $coverageReasons, $classifications['engp'] ?? [], $historicalCount, array_map(fn (array $row): string => $row['participant_key'], $participants));
    }

    /** @return array<string,mixed> */
    private function makeObligation(string $key, string $start, string $deadline, ?string $received, mixed $recordDeadline, ?bool $technical, ?int $recordId, bool $historical, string $domain, string $dayMode, ?string $office = null, ?int $classificationVersion = null, ?string $label = null): array
    {
        return [
            'key' => $key, 'start' => $start, 'deadline' => $deadline, 'received' => $received,
            'source_deadline' => $recordDeadline ? (string) $recordDeadline : null,
            'technical' => $technical, 'record_id' => $recordId, 'historical' => $historical,
            'domain' => $domain, 'day_mode' => $dayMode, 'office' => $office, 'classification_version' => $classificationVersion,
            'label' => $label ?? $key,
        ];
    }

    /**
     * Read-only, source-record population. This is intentionally separate from
     * the all-required-obligations assessment returned by assessments().
     *
     * @return array<string,mixed>
     */
    public function recordedAssessments(User $user, string $unit, string $periodKey, string $asOfDate, ?string $workflowFilter = null): array
    {
        abort_unless($user->can('office-tat.view'), 403);
        abort_unless(in_array($unit, $this->organization->effectiveUnits($user), true), 403);
        abort_unless(in_array($unit, ['conservation', 'development'], true), 422);

        [$periodStart, $periodEnd] = $this->assessmentBounds($periodKey);
        $asOf = CarbonImmutable::parse($asOfDate, BusinessCalendarService::TIMEZONE)->startOfDay();
        $cutoff = ($asOf->lessThan($periodEnd) ? $asOf : $periodEnd)->startOfDay();
        $year = (int) substr($periodKey, 0, 4);
        $classifications = [];

        if ($unit === 'conservation') {
            $areas = $this->organization->scopeProtectedAreaQuery(
                ProtectedArea::query()->with('pamoOfficeAssignment.office')->orderBy('id'), $user,
            )->get();
            $participants = $areas->map(fn (ProtectedArea $area): array => [
                ...$this->participant('conservation', 'pa:'.$area->getKey(), (string) $area->name,
                    $area->pamoOfficeAssignment?->office?->name, (string) ($area->status ?? 'Unknown')),
                'registration_eligibility' => null,
            ])->all();
            $rows = $this->recordedConservationRows($participants, $periodKey, $periodStart, $periodEnd, $cutoff, $classifications, $workflowFilter);
            $expectedRoster = self::EXPECTED_PA_ROSTER;
            $label = 'Conservation';
        } else {
            $offices = OrganizationalOffice::query()->whereRaw('LOWER(office_type) = ?', ['cenro'])
                ->where('is_active', true)->orderBy('id')->get()
                ->filter(fn (OrganizationalOffice $office): bool => $this->organization->canUseDevelopmentOffice($user, (string) $office->name));
            $participants = $offices->map(fn (OrganizationalOffice $office): array => $this->participant(
                'development', 'cenro:'.$office->getKey(), (string) $office->name, null, 'Active',
            ))->all();
            $rows = $this->recordedDevelopmentRows($participants, $periodKey, $periodStart, $periodEnd, $cutoff, $classifications, $user, $workflowFilter);
            $expectedRoster = self::EXPECTED_CENRO_ROSTER;
            $label = 'Development';
        }

        $rosterComplete = count($participants) === $expectedRoster;
        foreach ($rows as &$row) {
            if ($row['score_status'] !== 'Coverage incomplete') {
                if ($row['due_count'] > 0) {
                    $row['score_status'] = ($row['incomplete_date_count'] + $row['incomplete_evidence_count']) > 0
                        ? 'Scored with data issues' : 'Scored';
                } else {
                    $row['score_status'] = ($row['incomplete_date_count'] + $row['incomplete_evidence_count']) > 0
                        ? 'No assessable due reports' : 'No due reports';
                }
            }
            $row['rank'] = null;
            $row['rank_status'] = $rosterComplete ? $row['score_status'] : 'Comparison limited by organizational access';
        }
        unset($row);

        $fingerprint = hash('sha256', json_encode([
            'population' => 'recorded-live-source-v1', 'unit' => $unit, 'period' => $periodKey,
            'cutoff' => $cutoff->toDateString(), 'participants' => array_column($participants, 'participant_key'),
            'rows' => array_map(fn (array $row): array => [
                $row['participant_key'], $row['activity_count'], $row['received_activity_count'], $row['due_count'],
                $row['not_yet_due_count'], $row['incomplete_date_count'], $row['incomplete_evidence_count'],
                $row['tc_numerator'], $row['tc_denominator'], $row['se_ratio'], $row['early_count'],
                $row['volume_count'], $row['technical_on_time_count'],
            ], $rows),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $ties = $rosterComplete ? $this->rankRecordedRows($rows, $unit, $periodKey, $fingerprint) : [];
        if (! $rosterComplete) {
            foreach ($rows as &$row) {
                $row['rank'] = null;
                $row['rank_status'] = 'Comparison limited by organizational access';
            }
            unset($row);
        }

        $activityCount = (int) collect($rows)->sum('activity_count');
        $receivedCount = (int) collect($rows)->sum('received_activity_count');
        $notYetDueCount = (int) collect($rows)->sum('not_yet_due_count');
        $incompleteDates = (int) collect($rows)->sum('incomplete_date_count');
        $incompleteEvidence = (int) collect($rows)->sum('incomplete_evidence_count');
        $eligibleRows = collect($rows)->filter(fn (array $row): bool => in_array($row['score_status'], ['Scored', 'Scored with data issues'], true));
        $assessableDue = (int) $eligibleRows->sum('due_count');
        $onTimeCount = (int) $eligibleRows->sum('on_time_count');
        $earlyCount = (int) $eligibleRows->sum('early_count');
        $pooled = $this->pooledScore($rows);
        $coverageReasons = [];
        if (! $rosterComplete) $coverageReasons[] = 'The authorized view does not include the complete recognition-group roster.';
        if ($cutoff->lessThan($periodEnd->startOfDay())) $coverageReasons[] = 'The selected as-of date is before the reporting period ends.';
        if ($incompleteDates > 0) $coverageReasons[] = $incompleteDates.' source records have missing or invalid dates.';
        if ($incompleteEvidence > 0) $coverageReasons[] = $incompleteEvidence.' applicability, identity or schedule data gaps remain.';
        if (collect($rows)->contains(fn (array $row): bool => $row['score_status'] === 'Coverage incomplete')) $coverageReasons[] = 'At least one participant has invalid scoring dates or chronology.';
        $coverageComplete = $coverageReasons === [];
        foreach ($rows as &$row) {
            if (! $rosterComplete) {
                $row['rank'] = null;
                $row['rank_status'] = 'Comparison limited by organizational access';
            } elseif ($row['rank'] !== null) {
                $row['rank_status'] = $coverageComplete ? 'Final competition rank' : 'Provisional as-of rank';
            }
        }
        unset($row);
        return [
            'unit' => $unit, 'label' => $label, 'basis' => 'recorded_live_source',
            'basis_label' => 'Based on recorded reports', 'period_key' => $periodKey,
            'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString(),
            'as_of_date' => $cutoff->toDateString(), 'full_period_coverage' => $coverageComplete ? 'complete' : 'not_established', 'ranking_is_final' => $coverageComplete, 'coverage_reasons' => $coverageReasons,
            'roster_count' => count($participants), 'expected_roster_count' => $expectedRoster,
            'roster_complete' => $rosterComplete, 'ranking_scope' => $rosterComplete ? 'unit_roster' : 'authorized_participants_only', 'coverage_complete' => $coverageComplete,
            'activity_count' => $activityCount, 'received_activity_count' => $receivedCount,
            'due_count' => $assessableDue, 'on_time_count' => $assessableDue > 0 ? $onTimeCount : null,
            'early_count' => $assessableDue > 0 ? $earlyCount : null,
            'not_yet_due_count' => $notYetDueCount, 'incomplete_date_count' => $incompleteDates,
            'incomplete_evidence_count' => $incompleteEvidence,
            'tc_percent' => $assessableDue > 0 ? $pooled['tc_percent'] : null,
            'se_percent' => $assessableDue > 0 ? $pooled['se_percent'] : null,
            'otps' => $assessableDue > 0 ? round(($pooled['tc_percent'] * 0.80) + ($pooled['se_percent'] * 0.20), 2) : null,
            'early_percent' => $assessableDue > 0 ? 100 * $earlyCount / $assessableDue : null,
            'result_identity' => $fingerprint, 'tie_groups' => [], 'rows' => $rows,
        ];
    }

    /** Registry-backed recognition filters; Management Plans are deliberately omitted. */
    public function moduleOptions(string $unit): array
    {
        abort_unless(in_array($unit, ['conservation', 'development'], true), 422);

        if ($unit === 'development') {
            return collect($this->engp->all())->map(fn (array $definition): array => [
                'value' => (string) $definition['key'], 'label' => (string) $definition['label'],
            ])->values()->all();
        }

        $generic = $this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic')
            ->reject(fn (array $definition): bool => ($definition['module_type'] ?? null) === \App\Models\ModuleDefinition::TYPE_PLAN)
            ->map(fn (array $definition): array => ['value' => (string) $definition['key'], 'label' => (string) $definition['label']]);
        $nativeDefinitions = $this->requirements->definitions(ReportRequirementRegistry::PA, true, 'specialized')->keyBy('key');
        $native = collect(app(\App\Services\Pamo\PamoNativeSourceRegistry::class)->all())
            ->map(function (array $source, string $workflow) use ($nativeDefinitions): ?array {
                $definition = $nativeDefinitions->get($workflow);
                if (! $definition || ($definition['module_type'] ?? null) === \App\Models\ModuleDefinition::TYPE_PLAN) return null;
                return ['value' => $workflow, 'label' => (string) ($definition['label'] ?? $source['label'])];
            })->filter();

        return $generic->concat($native)->unique('value')->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** @return list<array{value:string,label:string}> */
    public function periodOptions(): array
    {
        $years = collect([
            ...$this->requirements->availableYears(ReportRequirementRegistry::PA),
            ...$this->requirements->availableYears(ReportRequirementRegistry::ENGP),
        ])->unique()->sortDesc();

        return $years->flatMap(function (int $year): array {
            $periods = [
                ['value' => (string) $year, 'label' => 'Annual '.$year],
                ['value' => $year.'-H1', 'label' => '1st Semester '.$year],
                ['value' => $year.'-H2', 'label' => '2nd Semester '.$year],
            ];
            return $periods;
        })->values()->all();
    }

    /** @return array<string,mixed> */
    private function finishUnit(string $unit, string $label, int $expectedRoster, array $participants, array $obligationsByParticipant, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $cutoff, array $coverageReasons, array $technicalCatalog, int $historicalCount, array $scopeFingerprint): array
    {
        $rosterComplete = count($participants) === $expectedRoster;
        if (! $rosterComplete) $coverageReasons[] = 'Directory roster contains '.count($participants).' participants; the approved roster size is '.$expectedRoster.'.';
        $rows = [];
        foreach ($participants as $participant) {
            $rows[] = [...$participant, ...$this->scoreParticipant($obligationsByParticipant[$participant['participant_key']] ?? [], $cutoff)];
        }
        $coverageReasons = array_values(array_unique($coverageReasons));
        $complete = $rosterComplete && $coverageReasons === [] && collect($rows)->every(fn (array $row): bool => in_array($row['score_status'], ['Scored', 'No due reports'], true));
        $baseFingerprint = hash('sha256', json_encode([
            'policy' => 'compliance-first-v1-80-20', 'unit' => $unit, 'period' => $periodKey,
            'cutoff' => $cutoff->toDateString(), 'assessment_start' => $periodStart->toDateString(), 'scope' => $scopeFingerprint,
            'rows' => array_map(fn (array $row): array => [
                $row['participant_key'], $row['due_count'], $row['submitted_count'], $row['volume_count'], $row['on_time_count'],
                $row['tc_numerator'], $row['tc_denominator'], $row['se_ratio'], $row['early_count'],
                $row['technical_on_time_count'], $row['score_status'], $row['evidence_fingerprint'],
            ], $rows),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $ties = $this->rankRows($rows, $unit, $periodKey, $baseFingerprint);
        if (! $complete) {
            foreach ($rows as &$row) {
                $row['rank'] = null;
                $row['rank_status'] = 'Coverage incomplete';
                $row['tc_percent'] = null;
                $row['se_percent'] = null;
                $row['otps'] = null;
                $row['early_percent'] = null;
                $row['due_count'] = null;
                $row['submitted_count'] = null;
                $row['volume_count'] = null;
                $row['on_time_count'] = null;
                $row['early_count'] = null;
                $row['technical_on_time_count'] = null;
                unset($row['reserved_rank_start'], $row['reserved_rank_end']);
                $row['score_status'] = 'Coverage incomplete';
            }
            unset($row);
            $ties = [];
        }
        $validRows = collect($rows)->filter(fn (array $row): bool => $row['score_status'] === 'Scored');
        $volumeRows = collect($rows)->filter(fn (array $row): bool => in_array($row['score_status'], ['Scored', 'No due reports'], true));
        $due = (int) $validRows->sum('due_count');
        $volume = (int) $volumeRows->sum('volume_count');
        $onTime = (int) $validRows->sum('on_time_count');
        $early = (int) $validRows->sum('early_count');
        $pooled = $this->pooledScore($rows);
        $pooledComplete = $complete && $due > 0;
        $tc = $pooledComplete ? $pooled['tc_percent'] : null;
        $se = $pooledComplete ? $pooled['se_percent'] : null;
        $catalogueDomain = $unit === 'development' ? 'engp' : 'conservation';
        $catalogueKeys = $catalogueDomain === 'engp'
            ? collect($this->engp->all())->pluck('key')->all()
            : $this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic')->pluck('key')->all();
        $missingClassifications = array_values(array_filter($catalogueKeys, fn (string $key): bool => ! isset($technicalCatalog[$key])));

        return [
            'unit' => $unit, 'label' => $label, 'period_key' => $periodKey,
            'expected_roster_count' => $expectedRoster, 'roster_count' => count($participants), 'roster_complete' => $rosterComplete,
            'coverage_complete' => $complete, 'coverage_reasons' => array_values(array_unique($coverageReasons)),
            'due_count' => $complete && $due > 0 ? $due : null,
            'submitted_count' => $complete && $due > 0 ? (int) $validRows->sum('submitted_count') : null,
            'volume_count' => $complete ? $volume : null,
            'on_time_count' => $complete && $due > 0 ? $onTime : null,
            'early_count' => $complete && $due > 0 ? $early : null,
            'tc_percent' => $tc, 'se_percent' => $se,
            'otps' => $tc !== null && $se !== null ? round(($tc * 0.80) + ($se * 0.20), 2) : null,
            'unit_score_status' => $due === 0 && $complete ? 'No due reports' : ($complete ? 'Scored' : 'Coverage incomplete'),
            'historical_evidence_count' => $historicalCount,
            'technical_classification_missing' => $missingClassifications,
            'metric_fingerprint' => $baseFingerprint,
            'tie_groups' => $ties,
            'rows' => $rows,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array{tc_percent:?float,se_percent:?float} */
    private function pooledScore(array $rows): array
    {
        $scored = collect($rows)->filter(fn (array $row): bool => in_array($row['score_status'], ['Scored', 'Scored with data issues'], true));
        $due = (int) $scored->sum('due_count');
        if ($due === 0) return ['tc_percent' => null, 'se_percent' => null];

        $onTime = (int) $scored->sum('on_time_count');
        $seSum = BigRational::zero();
        foreach ($scored as $row) {
            $seSum = $seSum->plus(BigRational::ofFraction($row['se_sum_numerator'], $row['se_sum_denominator']));
        }

        return ['tc_percent' => 100 * $onTime / $due, 'se_percent' => $seSum->dividedBy($due)->toFloat()];
    }

    /** @param list<array<string,mixed>> $obligations @return array<string,mixed> */
    private function scoreParticipant(array $obligations, CarbonImmutable $cutoff): array
    {
        $due = $submitted = $onTime = $early = $technicalOnTime = $volumeCount = 0;
        $seSum = BigRational::zero();
        $invalid = [];
        $evidence = [];
        $details = [];
        foreach ($obligations as $obligation) {
            $start = CarbonImmutable::parse($obligation['start'], BusinessCalendarService::TIMEZONE)->startOfDay();
            $deadline = CarbonImmutable::parse($obligation['deadline'], BusinessCalendarService::TIMEZONE)->startOfDay();
            $allowedDays = $this->durationDays($start, $deadline, $obligation);
            if ($deadline->greaterThan($cutoff)) {
                $receivedBeforeCutoff = $obligation['received'] ? CarbonImmutable::parse($obligation['received'], BusinessCalendarService::TIMEZONE)->startOfDay() : null;
                $issue = null;
                $usedDays = null;
                $detailStatus = $obligation['received'] && CarbonImmutable::parse($obligation['received'], BusinessCalendarService::TIMEZONE)->startOfDay()->greaterThan($cutoff)
                    ? 'Receipt after cutoff; excluded from this assessment'
                    : 'Not yet due; excluded from N';
                if ($allowedDays <= 0) $issue = 'The scheduled allowed period is not positive.';
                if ($receivedBeforeCutoff && $receivedBeforeCutoff->lessThanOrEqualTo($cutoff)) {
                    $usedDays = $this->durationDays($start, $receivedBeforeCutoff, $obligation);
                    if ($obligation['historical']) $issue = 'Historical source evidence is kept separate from live compliance scoring.';
                    elseif (isset($obligation['invalid_reason'])) $issue = $obligation['invalid_reason'];
                    elseif ($obligation['source_deadline'] !== $obligation['deadline']) $issue = 'Source deadline does not match the authoritative workflow schedule.';
                    elseif ($receivedBeforeCutoff->lessThan($start)) $issue = 'Submission precedes the approved workflow start.';
                    elseif ($allowedDays <= 0) $issue = 'Allowed period must be positive.';
                    else {
                        $volumeCount++;
                        $detailStatus = 'Submitted before due date; volume only';
                    }
                    if ($issue) {
                        $invalid[] = $obligation['key'].': '.$issue;
                        $detailStatus = 'Invalid source evidence';
                    }
                    $evidence[] = [$obligation['key'], $obligation['start'], $obligation['deadline'], $obligation['record_id'], $obligation['received'], $obligation['source_deadline'], $obligation['technical'], $obligation['day_mode'], $obligation['classification_version']];
                }
                $details[] = $this->obligationDetail($obligation, $allowedDays, $usedDays, $detailStatus, $issue);
                continue;
            }
            $due++;
            $t = $allowedDays;
            $detailStatus = $obligation['record_id'] === null ? 'Missed by cutoff' : 'No accepted receipt endpoint by cutoff';
            $detailIssue = null;
            $detailUsedDays = null;
            if ($t <= 0) {
                $invalid[] = $obligation['key'].': allowed period must be positive.';
                $detailIssue = 'Allowed period must be positive.';
            }

            if (isset($obligation['invalid_reason'])) {
                $invalid[] = $obligation['key'].': '.$obligation['invalid_reason'];
                $detailIssue = $obligation['invalid_reason'];
            }
            if ($obligation['historical']) {
                $invalid[] = $obligation['key'].': historical source evidence is separated from live scoring.';
                $detailIssue = 'Historical source evidence is kept separate from live compliance scoring.';
                $details[] = $this->obligationDetail($obligation, $t, null, 'Historical evidence; excluded from live scoring', $detailIssue);
                $evidence[] = [$obligation['key'], $obligation['start'], $obligation['deadline'], 'historical', $obligation['record_id'], $obligation['technical']];
                continue;
            }
            if ($obligation['record_id'] !== null && $obligation['source_deadline'] !== $obligation['deadline']) {
                $invalid[] = $obligation['key'].': source deadline does not match the authoritative workflow schedule.';
                $detailIssue = 'Source deadline does not match the authoritative workflow schedule.';
            }
            if ($deadline->lessThan(CarbonImmutable::parse(self::POLICY_EFFECTIVE_DATE, BusinessCalendarService::TIMEZONE)->startOfDay())
                && ($obligation['record_id'] === null || ! $obligation['received'] || $obligation['received'] > $cutoff->toDateString())
                && ! ($obligation['nonreceipt_verified'] ?? false)) {
                $invalid[] = $obligation['key'].': historical submission coverage is unverified; absence is not a verified missed report.';
                $detailIssue = 'Historical submission inventory is unverified; absence is not a verified missed report.';
                $detailStatus = 'Historical inventory unverified';
            }
            if (! empty($obligation['historical_receipt_conflict'])) {
                $invalid[] = $obligation['key'].': original historical receipt evidence contradicts the non-receipt assertion.';
                $detailIssue = 'Original historical receipt evidence contradicts the non-receipt assertion.';
                $detailStatus = 'Conflicting historical evidence';
            }

            $received = $obligation['received'] ? CarbonImmutable::parse($obligation['received'], BusinessCalendarService::TIMEZONE)->startOfDay() : null;
            if ($received && $received->greaterThan($cutoff)) $detailStatus = 'Receipt after cutoff; excluded from this assessment';
            if ($received && $received->lessThan($start)) {
                $invalid[] = $obligation['key'].': submission precedes the approved workflow start.';
                $detailIssue = 'Submission precedes the approved workflow start.';
                $detailStatus = 'Invalid source chronology';
            }
            if ($received && $received->lessThanOrEqualTo($cutoff)) {
                $volumeCount++;
                $submitted++;
                $d = $this->durationDays($start, $received, $obligation);
                $detailUsedDays = $d;
                if ($d < 0 || $t <= 0) {
                    $invalid[] = $obligation['key'].': start, deadline and receipt are not chronological.';
                    $detailIssue = 'Start, deadline and receipt are not chronological.';
                    $detailStatus = 'Invalid source chronology';
                } else {
                    if ($received->lessThanOrEqualTo($deadline)) {
                        $onTime++;
                        if ($received->lessThan($deadline)) $early++;
                        if ($obligation['technical'] === true) $technicalOnTime++;
                        $detailStatus = $received->lessThan($deadline) ? 'Submitted early' : 'Submitted on deadline';
                    } else {
                        $detailStatus = 'Submitted late';
                    }
                    if ($d === 0) {
                        $seSum = $seSum->plus(100);
                    } elseif ($d < $t) {
                        $seSum = $seSum->plus(BigRational::ofFraction(100 * ($t - $d), $t));
                    }
                }
            } else {
                $detailStatus = ! empty($obligation['historical_receipt_conflict'])
                    ? 'Conflicting historical evidence'
                    : (($obligation['nonreceipt_verified'] ?? false)
                        ? 'Approved non-receipt by cutoff'
                        : ($deadline->lessThan(CarbonImmutable::parse(self::POLICY_EFFECTIVE_DATE, BusinessCalendarService::TIMEZONE)->startOfDay())
                            ? 'Historical inventory unverified'
                            : 'Non-receipt evidence is not verified'));
            }
            $details[] = $this->obligationDetail($obligation, $t, $detailUsedDays, $detailStatus, $detailIssue);
            $evidence[] = [$obligation['key'], $obligation['start'], $obligation['deadline'], $obligation['record_id'], $obligation['received'], $obligation['source_deadline'], $obligation['technical'], $obligation['day_mode'], $obligation['classification_version'], $obligation['schedule_version_id'] ?? null, $obligation['applicability_version_id'] ?? null, $obligation['nonreceipt_register_id'] ?? null, $obligation['historical_receipt_evidence'] ?? null];
        }

        $valid = $invalid === [];
        $seRatio = $due > 0 ? $seSum->dividedBy($due) : BigRational::zero();
        $tc = $valid && $due > 0 ? 100 * $onTime / $due : null;
        $se = $valid && $due > 0 ? $seRatio->toFloat() : null;
        $earlyPercent = $valid && $due > 0 ? 100 * $early / $due : null;
        return [
            'due_count' => $due, 'submitted_count' => $submitted, 'on_time_count' => $onTime, 'early_count' => $early,
            'volume_count' => $volumeCount,
            'tc_numerator' => $onTime, 'tc_denominator' => $due,
            'se_sum_numerator' => $seSum->getNumerator()->__toString(),
            'se_sum_denominator' => $seSum->getDenominator()->__toString(),
            'se_ratio' => $seRatio->getNumerator()->__toString().'/'.$seRatio->getDenominator()->__toString(),
            'tc_percent' => $tc, 'se_percent' => $se,
            'otps' => $tc !== null && $se !== null ? round(($tc * 0.80) + ($se * 0.20), 2) : null,
            'early_percent' => $earlyPercent, 'technical_on_time_count' => $this->technicalCountKnown($obligations, $cutoff) ? $technicalOnTime : null,
            'obligation_details' => $details,
            'score_status' => ! $valid ? 'Coverage incomplete' : ($due === 0 ? 'No due reports' : 'Scored'),
            'score_issues' => array_values(array_unique($invalid)),
            'rank' => null, 'rank_status' => ! $valid ? 'Coverage incomplete' : ($due === 0 ? 'N/A' : 'Pending comparison'),
            'evidence_fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function scoreRecordedParticipant(array $obligations, CarbonImmutable $cutoff): array
    {
        $due = $submitted = $onTime = $early = $volumeCount = 0;
        $seSum = BigRational::zero();
        $invalid = [];
        $evidence = [];
        $details = [];

        foreach ($obligations as $obligation) {
            if (! empty($obligation['historical'])) continue;

            $validDate = static function (mixed $value): bool {
                if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match)) return false;
                return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
            };
            $startValue = $obligation['start'] ?? null;
            $deadlineValue = $obligation['deadline'] ?? null;
            if (! $validDate($startValue) || ! $validDate($deadlineValue)) {
                $issue = 'The authoritative workflow start or deadline is missing or invalid.';
                $invalid[] = ($obligation['key'] ?? 'unknown').': '.$issue;
                $details[] = $this->obligationDetail($obligation, 0, null, 'Invalid source dates', $issue, null);
                continue;
            }

            $start = CarbonImmutable::parse($startValue, BusinessCalendarService::TIMEZONE)->startOfDay();
            $deadline = CarbonImmutable::parse($deadlineValue, BusinessCalendarService::TIMEZONE)->startOfDay();
            $allowedDays = $this->durationDays($start, $deadline, $obligation);
            if ($allowedDays <= 0) {
                $issue = 'Allowed period must be positive and chronological.';
                $invalid[] = ($obligation['key'] ?? 'unknown').': '.$issue;
                $details[] = $this->obligationDetail($obligation, $allowedDays, null, 'Invalid allowed period', $issue, null);
                continue;
            }
            $receivedValue = $obligation['received'] ?? null;
            if ($receivedValue !== null && ! $validDate($receivedValue)) {
                $issue = 'Accepted PENRO receipt date is invalid.';
                $invalid[] = ($obligation['key'] ?? 'unknown').': '.$issue;
                $details[] = $this->obligationDetail($obligation, $allowedDays, null, 'Invalid receipt date', $issue, null);
                continue;
            }

            $received = $receivedValue
                ? CarbonImmutable::parse($receivedValue, BusinessCalendarService::TIMEZONE)->startOfDay()
                : null;
            $receivedByCutoff = $received !== null && $received->lessThanOrEqualTo($cutoff);
            if ($deadline->greaterThan($cutoff) && ! $receivedByCutoff) {
                $details[] = $this->obligationDetail(
                    $obligation, $allowedDays, null,
                    $received ? 'Receipt after cutoff; excluded from this assessment' : 'Not yet due; excluded from N',
                    null, null,
                );
                continue;
            }

            // The current as-of cohort contains obligations already due and valid
            // accepted receipts already received, including receipts before deadline.
            $due++;
            $issue = null;
            $daysUsed = null;
            $individualEfficiency = 0.0;
            $status = 'No accepted PENRO receipt by cutoff';

            if (isset($obligation['invalid_reason'])) {
                $issue = (string) $obligation['invalid_reason'];
                $invalid[] = ($obligation['key'] ?? 'unknown').': '.$issue;
            }
            if ($obligation['record_id'] !== null && ($obligation['source_deadline'] ?? null) !== $obligation['deadline']) {
                $issue = 'Source deadline does not match the authoritative workflow schedule.';
                $invalid[] = ($obligation['key'] ?? 'unknown').': '.$issue;
            }
            if ($receivedByCutoff && $received->lessThan($start)) {
                $issue = 'Accepted PENRO receipt precedes the authoritative workflow start.';
                $invalid[] = ($obligation['key'] ?? 'unknown').': '.$issue;
                $status = 'Invalid source chronology';
            }

            if ($receivedByCutoff && $issue === null) {
                $daysUsed = $this->durationDays($start, $received, $obligation);
                if ($daysUsed < 0) {
                    $issue = 'Accepted PENRO receipt precedes the authoritative workflow start.';
                    $invalid[] = ($obligation['key'] ?? 'unknown').': '.$issue;
                    $status = 'Invalid source chronology';
                } else {
                    $volumeCount++;
                    $submitted++;
                    if ($received->lessThanOrEqualTo($deadline)) {
                        $onTime++;
                        if ($received->lessThan($deadline)) $early++;
                        $status = $received->lessThan($deadline) ? 'Submitted early' : 'Submitted on deadline';
                    } else {
                        $status = 'Submitted late';
                    }

                    $remaining = max(0, $allowedDays - $daysUsed);
                    $seSum = $seSum->plus(BigRational::ofFraction(100 * $remaining, $allowedDays));
                    $individualEfficiency = 100 * $remaining / $allowedDays;
                }
            }

            $obligation['individual_efficiency_percent'] = $individualEfficiency;
            $details[] = $this->obligationDetail($obligation, $allowedDays, $daysUsed, $status, $issue, $individualEfficiency);
            $evidence[] = [
                $obligation['key'], $obligation['start'], $obligation['deadline'], $obligation['record_id'],
                $obligation['received'], $obligation['source_deadline'], $obligation['day_mode'],
            ];
        }

        $valid = $invalid === [];
        $seRatio = $due > 0 ? $seSum->dividedBy($due) : BigRational::zero();
        $tc = $valid && $due > 0 ? 100 * $onTime / $due : null;
        $se = $valid && $due > 0 ? $seRatio->toFloat() : null;
        $earlyPercent = $valid && $due > 0 ? 100 * $early / $due : null;

        return [
            'due_count' => $due, 'submitted_count' => $submitted, 'on_time_count' => $onTime, 'early_count' => $early,
            'volume_count' => $volumeCount,
            'tc_numerator' => $onTime, 'tc_denominator' => $due,
            'se_sum_numerator' => $seSum->getNumerator()->__toString(),
            'se_sum_denominator' => $seSum->getDenominator()->__toString(),
            'se_ratio' => $seRatio->getNumerator()->__toString().'/'.$seRatio->getDenominator()->__toString(),
            'tc_percent' => $tc, 'se_percent' => $se,
            'otps' => $tc !== null && $se !== null ? round(($tc * 0.80) + ($se * 0.20), 2) : null,
            'early_percent' => $earlyPercent, 'technical_on_time_count' => null,
            'obligation_details' => $details,
            'score_status' => ! $valid ? 'Coverage incomplete' : ($due === 0 ? 'No due reports' : 'Scored'),
            'score_issues' => array_values(array_unique($invalid)),
            'rank' => null, 'rank_status' => ! $valid ? 'Coverage incomplete' : ($due === 0 ? 'N/A' : 'Pending comparison'),
            'evidence_fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }
    private function obligationDetail(array $obligation, int $allowedDays, ?int $daysUsed, string $status, ?string $issue, ?float $efficiency = null): array
    {
        $sourceIds = array_values(array_unique(array_map('intval', $obligation['source_identity']['source_ids'] ?? [])));
        if ($sourceIds === [] && $obligation['record_id'] !== null) $sourceIds = [(int) $obligation['record_id']];
        $sourceReference = null;
        if ($sourceIds !== [] && in_array($obligation['domain'], ['conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws', 'ipaf-management', 'revenue'], true)) {
            $sourceName = match ($obligation['domain']) {
                'conservation' => 'Conservation', 'engp' => 'ENGP', 'bms' => 'BMS', 'bams' => 'BAMS',
                'imea' => 'IMEA', 'imea-maintenance' => 'IMEA Facility Maintenance',
                'aws' => 'Automated Weather Station', 'ipaf-management' => 'Management of IPAF',
                'revenue' => 'Revenue Collection',
            };
            $sourceReference = $sourceName.(count($sourceIds) === 1 ? ' report #' : ' reports #').implode(', #', $sourceIds);
        }

        return [
            'key' => $obligation['key'], 'label' => $obligation['label'], 'status' => $status, 'start_date' => $obligation['start'],
            'deadline' => $obligation['deadline'], 'received' => $obligation['received'],
            'allowed_days' => $allowedDays > 0 ? $allowedDays : null, 'days_used' => $daysUsed,
            'individual_efficiency_percent' => $efficiency,
            'day_convention' => match ($obligation['day_mode']) {
                'calendar_days' => 'Calendar days',
                self::ENGP_CBEP_ALLOWED_CALENDAR_DAYS => 'CBEP allowed calendar days (period start through deadline, inclusive)',
                'engp_standard_working_days' => 'Standard working days (shared holiday calendar)',
                \App\Models\ModuleDefinition::DEADLINE_PAMB_WORKING_DAYS => 'PAMB working days (Monday–Thursday shared calendar)',
                \App\Models\ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS => 'Conservation working days (Monday–Thursday shared calendar)',
                self::CONSERVATION_NATIVE_DAY_MODE => 'Conservation working days (Monday to Thursday shared calendar)',
                default => 'Source-defined day convention',
            },
            'receipt_endpoint' => 'date_received_penro', 'technical' => $obligation['technical'],
            'source_reference' => $sourceReference,
            'source_rows_matched' => (int) ($obligation['source_rows_matched'] ?? ($obligation['record_id'] === null ? 0 : 1)),
            'issue' => $issue,
            'nonreceipt_verified' => (bool) ($obligation['nonreceipt_verified'] ?? false),
            'nonreceipt_register_id' => $obligation['nonreceipt_register_id'] ?? null,
            'schedule_version_id' => $obligation['schedule_version_id'] ?? null,
            'applicability_version_id' => $obligation['applicability_version_id'] ?? null,
            'historical_receipt_evidence' => $obligation['historical_receipt_evidence'] ?? null,
            'approving_authority' => $obligation['approving_authority'] ?? null,
            'approval_reference' => $obligation['approval_reference'] ?? null,
            'source_identity' => $obligation['source_identity'] ?? null,
            'occurrence_key' => $obligation['occurrence_key'] ?? null,
            'approval_evidence_reference' => $obligation['approval_evidence_reference'] ?? null,
            'nonreceipt_approving_authority' => $obligation['nonreceipt_approving_authority'] ?? null,
            'nonreceipt_approval_reference' => $obligation['nonreceipt_approval_reference'] ?? null,
            'nonreceipt_evidence_reference' => $obligation['nonreceipt_evidence_reference'] ?? null,
        ];
    }

    private function technicalCountKnown(array $obligations, CarbonImmutable $cutoff): bool
    {
        foreach ($obligations as $obligation) {
            $deadline = CarbonImmutable::parse($obligation['deadline'], BusinessCalendarService::TIMEZONE)->startOfDay();
            $received = $obligation['received'] ? CarbonImmutable::parse($obligation['received'], BusinessCalendarService::TIMEZONE)->startOfDay() : null;
            if ($deadline->lessThanOrEqualTo($cutoff) && $received?->lessThanOrEqualTo($cutoff)
                && $received->lessThanOrEqualTo($deadline) && $obligation['technical'] === null) return false;
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    private function rankRows(array &$rows, string $unit, string $periodKey, string $baseFingerprint): array
    {
        usort($rows, function (array $a, array $b): int {
            $aScored = $a['score_status'] === 'Scored';
            $bScored = $b['score_status'] === 'Scored';
            if ($aScored !== $bScored) return $aScored ? -1 : 1;
            $tc = $this->compareRatios($a['tc_numerator'], $a['tc_denominator'], $b['tc_numerator'], $b['tc_denominator']);
            if ($tc !== 0) return -$tc;
            $se = $this->compareSeRatios($a['se_ratio'], $b['se_ratio']);
            if ($se !== 0) return -$se;
            $early = $this->compareRatios($a['early_count'], max(1, $a['due_count']), $b['early_count'], max(1, $b['due_count']));
            if ($early !== 0) return -$early;
            if ($a['volume_count'] !== $b['volume_count']) return $b['volume_count'] <=> $a['volume_count'];
            if ($a['technical_on_time_count'] !== null && $b['technical_on_time_count'] !== null && $a['technical_on_time_count'] !== $b['technical_on_time_count']) {
                return $b['technical_on_time_count'] <=> $a['technical_on_time_count'];
            }
            return strnatcasecmp($a['name'], $b['name']); // display order only; rank is assigned below.
        });

        $ties = [];
        $rankCursor = 1;
        $index = 0;
        while ($index < count($rows)) {
            if ($rows[$index]['score_status'] !== 'Scored') {
                $rows[$index]['rank_status'] = $rows[$index]['score_status'];
                $index++;
                continue;
            }
            $end = $index + 1;
            while ($end < count($rows) && $rows[$end]['score_status'] === 'Scored' && $this->sameFirstFour($rows[$index], $rows[$end])) $end++;
            $group = array_slice($rows, $index, $end - $index);
            if (count($group) === 1) {
                $rows[$index]['rank'] = $rankCursor++;
                $rows[$index]['rank_status'] = 'Official rank';
                $index = $end;
                continue;
            }

            if (collect($group)->contains(fn (array $row): bool => $row['technical_on_time_count'] === null)) {
                foreach (range($index, $end - 1) as $rowIndex) {
                    $rows[$rowIndex]['rank_status'] = 'Awaiting technical classification';
                    $rows[$rowIndex]['reserved_rank_start'] = $rankCursor;
                    $rows[$rowIndex]['reserved_rank_end'] = $rankCursor + count($group) - 1;
                }
                $ties[] = ['participant_keys' => collect($group)->pluck('participant_key')->sort()->values()->all(), 'resolvable' => false, 'fingerprint' => null, 'rank_start' => $rankCursor];
                $rankCursor += count($group);
                $index = $end;
                continue;
            }

            $techGroups = [];
            foreach ($group as $row) $techGroups[(string) $row['technical_on_time_count']][] = $row;
            foreach ($techGroups as $technicalCount => $technicalGroup) {
                $groupSize = count($technicalGroup);
                if ($groupSize === 1) {
                    $key = $technicalGroup[0]['participant_key'];
                    $rowIndex = array_search($key, array_column($rows, 'participant_key'), true);
                    $rows[$rowIndex]['rank'] = $rankCursor++;
                    $rows[$rowIndex]['rank_status'] = 'Official rank';
                    continue;
                }
                $members = collect($technicalGroup)->pluck('participant_key')->sort()->values()->all();
                $fingerprint = hash('sha256', $baseFingerprint.'|'.json_encode($members, JSON_THROW_ON_ERROR));
                $decision = Schema::hasTable('office_tat_tie_decisions')
                    ? OfficeTatTieDecision::query()->where('unit', $unit)->where('period_key', $periodKey)->where('metric_fingerprint', $fingerprint)->latest('id')->first()
                    : null;
                $validDecision = $decision && $decision->participant_keys === $members
                    && count($decision->ordered_participant_keys ?? []) === $groupSize
                    && array_diff($members, $decision->ordered_participant_keys ?? []) === []
                    && array_diff($decision->ordered_participant_keys ?? [], $members) === [];
                if ($validDecision) {
                    foreach ($decision->ordered_participant_keys as $key) {
                        $rowIndex = array_search($key, array_column($rows, 'participant_key'), true);
                        $rows[$rowIndex]['rank'] = $rankCursor++;
                        $rows[$rowIndex]['rank_status'] = 'Official rank · manual tie decision';
                    }
                } else {
                    foreach ($technicalGroup as $tiedRow) {
                        $rowIndex = array_search($tiedRow['participant_key'], array_column($rows, 'participant_key'), true);
                        $rows[$rowIndex]['rank_status'] = 'Awaiting tie resolution';
                        $rows[$rowIndex]['reserved_rank_start'] = $rankCursor;
                        $rows[$rowIndex]['reserved_rank_end'] = $rankCursor + $groupSize - 1;
                    }
                    $ties[] = ['participant_keys' => $members, 'resolvable' => true, 'fingerprint' => $fingerprint, 'rank_start' => $rankCursor];
                    $rankCursor += $groupSize;
                }
            }
            $index = $end;
        }
        usort($rows, static function (array $a, array $b): int {
            $aPosition = $a['rank'] ?? $a['reserved_rank_start'] ?? PHP_INT_MAX;
            $bPosition = $b['rank'] ?? $b['reserved_rank_start'] ?? PHP_INT_MAX;
            if ($aPosition !== $bPosition) return $aPosition <=> $bPosition;
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $ties;
    }

    private function rankRecordedRows(array &$rows, string $unit, string $periodKey, string $baseFingerprint): array
    {
        usort($rows, function (array $a, array $b): int {
            $aScored = in_array(($a['score_status'] ?? null), ['Scored', 'Scored with data issues'], true)
                && is_numeric($a['tc_numerator'] ?? null) && is_numeric($a['tc_denominator'] ?? null)
                && is_string($a['se_ratio'] ?? null);
            $bScored = in_array(($b['score_status'] ?? null), ['Scored', 'Scored with data issues'], true)
                && is_numeric($b['tc_numerator'] ?? null) && is_numeric($b['tc_denominator'] ?? null)
                && is_string($b['se_ratio'] ?? null);
            if ($aScored !== $bScored) return $aScored ? -1 : 1;
            if ($aScored && $bScored) {
                $tc = $this->compareRatios(
                    (int) $a['tc_numerator'], max(1, (int) $a['tc_denominator']),
                    (int) $b['tc_numerator'], max(1, (int) $b['tc_denominator']),
                );
                if ($tc !== 0) return -$tc;
                $se = $this->compareSeRatios((string) $a['se_ratio'], (string) $b['se_ratio']);
                if ($se !== 0) return -$se;
            }
            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        $position = 0;
        $rank = null;
        $previous = null;
        foreach ($rows as &$row) {
            $rankable = in_array(($row['score_status'] ?? null), ['Scored', 'Scored with data issues'], true)
                && is_numeric($row['tc_numerator'] ?? null) && is_numeric($row['tc_denominator'] ?? null)
                && is_string($row['se_ratio'] ?? null);
            if (! $rankable) {
                $row['rank'] = null;
                $row['rank_status'] = $row['score_status'] ?? 'Not applicable';
                continue;
            }

            $position++;
            $sameScore = $previous !== null
                && $this->compareRatios(
                    (int) $previous['tc_numerator'], max(1, (int) $previous['tc_denominator']),
                    (int) $row['tc_numerator'], max(1, (int) $row['tc_denominator']),
                ) === 0
                && $this->compareSeRatios((string) $previous['se_ratio'], (string) $row['se_ratio']) === 0;
            if (! $sameScore) $rank = $position;

            $row['rank'] = $rank;
            $row['rank_status'] = 'Current as-of rank';
            $previous = $row;
        }
        unset($row);

        return [];
    }
    private function sameFirstFour(array $a, array $b): bool
    {
        return $this->compareRatios($a['tc_numerator'], $a['tc_denominator'], $b['tc_numerator'], $b['tc_denominator']) === 0
            && $this->compareSeRatios($a['se_ratio'], $b['se_ratio']) === 0
            && $this->compareRatios($a['early_count'], max(1, $a['due_count']), $b['early_count'], max(1, $b['due_count'])) === 0
            && $a['volume_count'] === $b['volume_count'];
    }

    private function compareRatios(int $aNumerator, int $aDenominator, int $bNumerator, int $bDenominator): int
    {
        return ($aNumerator * $bDenominator) <=> ($bNumerator * $aDenominator);
    }

    private function compareSeRatios(string $a, string $b): int
    {
        [$aNumerator, $aDenominator] = explode('/', $a, 2);
        [$bNumerator, $bDenominator] = explode('/', $b, 2);

        return BigRational::ofFraction($aNumerator, $aDenominator)->compareTo(BigRational::ofFraction($bNumerator, $bDenominator));
    }

    /** @return array<string,array<string,mixed>> */
    private function latestClassifications(string $asOfDate): array
    {
        if (! Schema::hasTable('office_tat_technical_classification_versions')
            || ! Schema::hasColumn('office_tat_technical_classification_versions', 'evidence_id')) return [];
        $latest = [];
        foreach (OfficeTatTechnicalClassificationVersion::query()->orderBy('id')->get() as $version) {
            if (! $version->effective_from || $version->effective_from->toDateString() > $asOfDate
                || ($version->effective_to && $version->effective_to->toDateString() < $asOfDate)
                || blank($version->approving_authority) || blank($version->approval_reference) || blank($version->rationale)
                || ! $this->obligationEvidence->evidenceFileIsValid((int) $version->evidence_id)) continue;
            $latest[$version->domain][$version->workflow_key] = [
                'is_highly_technical' => (bool) $version->is_highly_technical, 'version' => (int) $version->version, 'id' => (int) $version->id,
                'approving_authority' => $version->approving_authority, 'approval_reference' => $version->approval_reference,
                'rationale' => $version->rationale, 'effective_from' => $version->effective_from->toDateString(),
                'effective_to' => $version->effective_to?->toDateString(), 'evidence_id' => (int) $version->evidence_id,
            ];
        }
        return $latest;
    }

    private function durationDays(CarbonImmutable $start, CarbonImmutable $end, array $obligation): int
    {
        if ($obligation['day_mode'] === self::ENGP_CBEP_ALLOWED_CALENDAR_DAYS) {
            return (int) $start->diffInDays($end, false) + 1;
        }
        if ($obligation['day_mode'] === 'calendar_days') return (int) $start->diffInDays($end, false);
        $weekdays = match ($obligation['day_mode']) {
            'engp_standard_working_days' => BusinessCalendarService::STANDARD_WORKING_WEEKDAYS,
            self::CONSERVATION_NATIVE_DAY_MODE => BusinessCalendarService::CONSERVATION_WORKING_WEEKDAYS,
            \App\Models\ModuleDefinition::DEADLINE_PAMB_WORKING_DAYS => BusinessCalendarService::PAMB_WORKING_WEEKDAYS,
            \App\Models\ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS => BusinessCalendarService::CONSERVATION_WORKING_WEEKDAYS,
            default => null,
        };
        if ($weekdays === null) return 0;
        return $this->calendar->workingDaysBetween($start, $end, 'after_through', $obligation['office'], $weekdays);
    }

    /** @return list<array<string,mixed>> */
    private function classificationCatalogue(array $classifications): array
    {
        $items = [];
        foreach ([
            'conservation' => collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic')),
            'engp' => collect($this->engp->all()),
        ] as $domain => $definitions) {
            foreach ($definitions as $definition) {
                $key = (string) $definition['key'];
                $classification = $classifications[$domain][$key] ?? null;
                $startBasis = $domain === 'engp'
                    ? 'Catalogued reporting-period start'
                    : (in_array($key, PambComplianceCalculator::MEETING_WORKFLOWS, true) ? 'PAMB authoritative date: date_accomplished, otherwise date_conducted' : 'Source date_accomplished');
                $deadlineBasis = $domain === 'engp'
                    ? 'Active ENGP workflow registry deadline'
                    : 'Conservation source workflow deadline; scheduled start must be verified per obligation';
                $dayConvention = $domain === 'engp' ? match ($definition['deadline_mode'] ?? '') {
                    \App\Models\ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS => 'Standard working days: Monday–Friday with the shared calendar',
                    default => 'Elapsed calendar days, deadline boundary inclusive',
                } : match ($definition['deadline_mode'] ?? '') {
                    \App\Models\ModuleDefinition::DEADLINE_CALENDAR_DAYS => 'Calendar days',
                    \App\Models\ModuleDefinition::DEADLINE_PAMB_WORKING_DAYS => 'PAMB working days: Monday–Thursday with the shared calendar',
                    \App\Models\ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS => 'Conservation working days: Monday–Thursday with the shared calendar',
                    default => 'Workflow rule or stored deadline; requires source evidence',
                };
                if ($domain === 'engp' && $key === 'cbep') {
                    $dayConvention = 'CBEP allowed calendar days, counting the period start as day 1 through the deadline';
                }
                $items[] = ['domain' => $domain, 'workflow_key' => $key, 'label' => (string) $definition['label'],
                    'is_highly_technical' => $classification['is_highly_technical'] ?? null, 'version' => $classification['version'] ?? null,
                    'stored_version' => Schema::hasTable('office_tat_technical_classification_versions')
                        ? (int) OfficeTatTechnicalClassificationVersion::query()->where('domain', $domain)->where('workflow_key', $key)->max('version') : 0,
                    'approving_authority' => $classification['approving_authority'] ?? null,
                    'approval_reference' => $classification['approval_reference'] ?? null,
                    'rationale' => $classification['rationale'] ?? null,
                    'effective_from' => $classification['effective_from'] ?? null,
                    'effective_to' => $classification['effective_to'] ?? null,
                    'evidence_reference' => isset($classification['evidence_id']) ? \App\Models\OfficeTatApprovalEvidence::query()->find($classification['evidence_id'])?->public_id : null,
                    'start_basis' => $startBasis, 'deadline_basis' => $deadlineBasis, 'day_convention' => $dayConvention,
                    'submission_endpoint' => 'date_received_penro',
                    'allowed_days' => $domain === 'engp' && $key === 'cbep' ? 20 : ($definition['default_deadline_days'] ?? null),
                    'frequency' => $definition['reporting_frequency'] ?? $definition['period'] ?? null];
            }
        }
        return $items;
    }

    private function engpDayMode(string $workflowKey, array $workflow): string
    {
        if ($workflowKey === 'cbep') return self::ENGP_CBEP_ALLOWED_CALENDAR_DAYS;
        return ($workflow['deadline_mode'] ?? null) === \App\Models\ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS
            ? 'engp_standard_working_days'
            : 'calendar_days';
    }

    private function deadlineInAssessmentPeriod(CarbonImmutable $deadline, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        return $deadline->greaterThanOrEqualTo($periodStart) && $deadline->lessThanOrEqualTo($periodEnd);
    }

    private function assessmentBounds(string $periodKey): array
    {
        $year = (int) substr($periodKey, 0, 4);
        $suffix = strlen($periodKey) > 4 ? substr($periodKey, -2) : null;
        $month = $suffix === 'H2' ? 7 : 1;
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE);
        $end = $suffix === 'H1' ? $start->addMonths(6)->subDay()->endOfDay() : CarbonImmutable::create($year, 12, 31, 23, 59, 59, BusinessCalendarService::TIMEZONE);
        return [$start, $end];
    }

    private function participant(string $unit, string $key, string $name, ?string $binding, string $status): array
    {
        return ['unit' => $unit, 'participant_key' => $key, 'name' => $name, 'office_binding' => $binding, 'legal_status' => $status];
    }

    private function engpPeriodRecordKey(string $workflow, string $office, int $year, string $period): string
    {
        return implode('|', [$workflow, mb_strtolower(trim($office)), $year, $period]);
    }

    private function engpRecordKey(EngpReportSubmission $record): string
    {
        return $this->engpPeriodRecordKey((string) $record->workflow_key, (string) $record->office, (int) $record->reporting_year, (string) $record->period_key);
    }

    private function paPeriodRecordKey(int $paId, string $workflow, string $period, string $activity, string $document): string
    {
        return implode('|', [$paId, $workflow, mb_strtolower(trim($period)), mb_strtolower(trim($activity)), mb_strtolower(trim($document))]);
    }

    private function paRecordKey(ConservationReportSubmission $record): string
    {
        $sourceDate = $this->conservationRecordStart($record)
            ?: ($record->deadline_submission ?: $record->created_at?->timezone(BusinessCalendarService::TIMEZONE)->toDateString());
        $year = $sourceDate ? CarbonImmutable::parse($sourceDate, BusinessCalendarService::TIMEZONE)->year : 0;
        $registeredActivity = $year > 0
            ? $this->registeredPaActivityFor($record, $this->obligationEvidence->catalogue(), $year)
            : null;

        return $this->paPeriodRecordKey(
            (int) $record->protected_area_id, (string) $record->workflow_key, (string) $record->reporting_period,
            $registeredActivity ?? (string) $record->activity_name, (string) $record->document_type,
        );
    }
}
