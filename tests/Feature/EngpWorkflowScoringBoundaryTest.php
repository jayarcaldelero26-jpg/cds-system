<?php

use App\Domain\Modules\ProgramArea;
use App\Models\EngpReportSubmission;
use App\Models\ModuleDefinition;
use App\Models\OrganizationalOffice;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\OfficeTatComplianceAssessmentService;
use App\Services\Reports\ReportRequirementRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    $this->withoutVite();
    Storage::fake('local');

    foreach (['CENRO Baganga', 'CENRO Manay', 'CENRO Lupon', 'CENRO Mati'] as $index => $name) {
        OrganizationalOffice::query()->firstOrCreate(['name' => $name], [
            'code' => 'engp-score-boundary-'.$index, 'office_type' => 'cenro', 'is_active' => true,
        ]);
    }
});

/**
 * Independently recorded date facts for each rule family. The registry supplies only the
 * remaining on-deadline filler periods; these dates, T, D and expected ratings are literal.
 * @return array<string,mixed>
 */
function engpWorkflowBoundaryFacts(string $workflowKey): array
{
    $fourOffices = ['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon'];
    $monthlyBoundary = [
        'CENRO Baganga' => ['received' => '2028-02-01', 'days_used' => 0, 'status' => 'Submitted early', 'days_complied' => 19, 'rating' => 'Outstanding'],
        'CENRO Manay' => ['received' => '2028-02-19', 'days_used' => 18, 'status' => 'Submitted early', 'days_complied' => 1, 'rating' => 'Satisfactory'],
        'CENRO Mati' => ['received' => '2028-02-20', 'days_used' => 19, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
        'CENRO Lupon' => ['received' => '2028-02-21', 'days_used' => 20, 'status' => 'Submitted late', 'days_complied' => -1, 'rating' => 'Unsatisfactory'],
    ];

    if ($workflowKey === 'cbep') {
        return [
            'year' => 2028, 'period_count' => 12, 'expected_due' => 48, 'expected_on_time' => 47,
            'expected_early' => 2, 'expected_se_percent' => 100 / 48,
            'focus_period' => '2028-02', 'focus_deadline' => '2028-02-20', 'as_of_due' => 8,
            'checks' => [
                '2028-02' => ['start' => '2028-02-01', 'deadline' => '2028-02-20', 'allowed' => 20, 'facts' => [
                    'CENRO Baganga' => ['received' => '2028-02-01', 'days_used' => 1, 'status' => 'Submitted early', 'days_complied' => 19, 'rating' => 'Outstanding'],
                    'CENRO Manay' => ['received' => '2028-02-19', 'days_used' => 19, 'status' => 'Submitted early', 'days_complied' => 1, 'rating' => 'Satisfactory'],
                    'CENRO Mati' => ['received' => '2028-02-20', 'days_used' => 20, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                    'CENRO Lupon' => ['received' => '2028-02-21', 'days_used' => 21, 'status' => 'Submitted late', 'days_complied' => -1, 'rating' => 'Unsatisfactory'],
                ]],
            ],
        ];
    }

    if ($workflowKey === 'ngp_staff_accomplishment') {
        return [
            'year' => 2028, 'period_count' => 12, 'expected_due' => 36, 'expected_on_time' => 35,
            'expected_early' => 3, 'expected_se_percent' => (100 + (100 / 19) + (100 / 19)) / 36,
            'focus_period' => '2028-03', 'focus_deadline' => '2028-03-20', 'as_of_due' => 9,
            'checks' => [
                '2028-02' => ['start' => '2028-02-01', 'deadline' => '2028-02-20', 'allowed' => 19, 'facts' => [
                    'CENRO Baganga' => ['received' => '2028-02-01', 'days_used' => 0, 'status' => 'Submitted early', 'days_complied' => 19, 'rating' => 'Outstanding'],
                    'CENRO Mati' => ['received' => '2028-02-19', 'days_used' => 18, 'status' => 'Submitted early', 'days_complied' => 1, 'rating' => 'Satisfactory'],
                    'CENRO Lupon' => ['received' => '2028-02-20', 'days_used' => 19, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                ]],
                '2028-03' => ['start' => '2028-03-01', 'deadline' => '2028-03-20', 'allowed' => 19, 'facts' => [
                    'CENRO Baganga' => ['received' => '2028-03-21', 'days_used' => 20, 'status' => 'Submitted late', 'days_complied' => -1, 'rating' => 'Unsatisfactory'],
                    'CENRO Mati' => ['received' => '2028-03-20', 'days_used' => 19, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                    'CENRO Lupon' => ['received' => '2028-03-19', 'days_used' => 18, 'status' => 'Submitted early', 'days_complied' => 1, 'rating' => 'Satisfactory'],
                ]],
            ],
        ];
    }

    if ($workflowKey === 'rims') {
        return [
            'year' => 2028, 'period_count' => 12, 'expected_due' => 48, 'expected_on_time' => 46,
            'expected_early' => 4, 'expected_se_percent' => (200 + (100 / 28) + (100 / 19)) / 48,
            'focus_period' => '2028-01', 'focus_deadline' => '2028-01-29', 'as_of_due' => 4,
            'checks' => [
                '2028-01' => ['start' => '2028-01-01', 'deadline' => '2028-01-29', 'allowed' => 28, 'facts' => [
                    'CENRO Baganga' => ['received' => '2028-01-01', 'days_used' => 0, 'status' => 'Submitted early', 'days_complied' => 28, 'rating' => 'Outstanding'],
                    'CENRO Manay' => ['received' => '2028-01-28', 'days_used' => 27, 'status' => 'Submitted early', 'days_complied' => 1, 'rating' => 'Satisfactory'],
                    'CENRO Mati' => ['received' => '2028-01-29', 'days_used' => 28, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                    'CENRO Lupon' => ['received' => '2028-01-30', 'days_used' => 29, 'status' => 'Submitted late', 'days_complied' => -1, 'rating' => 'Unsatisfactory'],
                ]],
                '2028-02' => ['start' => '2028-02-01', 'deadline' => '2028-02-20', 'allowed' => 19, 'facts' => $monthlyBoundary],
            ],
        ];
    }

    if (in_array($workflowKey, ['ngp_produce', 'nursery_maintenance', 'site_visit'], true)) {
        return [
            'year' => 2028, 'period_count' => 4, 'expected_due' => 16, 'expected_on_time' => 15,
            'expected_early' => 2, 'expected_se_percent' => (100 + (100 / 69)) / 16,
            'focus_period' => 'Q1', 'focus_deadline' => '2028-03-10', 'as_of_due' => 4,
            'checks' => [
                'Q1' => ['start' => '2028-01-01', 'deadline' => '2028-03-10', 'allowed' => 69, 'facts' => [
                    'CENRO Baganga' => ['received' => '2028-01-01', 'days_used' => 0, 'status' => 'Submitted early', 'days_complied' => 69, 'rating' => 'Outstanding'],
                    'CENRO Manay' => ['received' => '2028-03-09', 'days_used' => 68, 'status' => 'Submitted early', 'days_complied' => 1, 'rating' => 'Satisfactory'],
                    'CENRO Mati' => ['received' => '2028-03-10', 'days_used' => 69, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                    'CENRO Lupon' => ['received' => '2028-03-11', 'days_used' => 70, 'status' => 'Submitted late', 'days_complied' => -1, 'rating' => 'Unsatisfactory'],
                ]],
                'Q2' => ['start' => '2028-04-01', 'deadline' => '2028-06-10', 'allowed' => 70, 'facts' => [
                    'CENRO Mati' => ['received' => '2028-06-10', 'days_used' => 70, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                ]],
                'Q3' => ['start' => '2028-07-01', 'deadline' => '2028-09-10', 'allowed' => 71, 'facts' => [
                    'CENRO Mati' => ['received' => '2028-09-10', 'days_used' => 71, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                ]],
                'Q4' => ['start' => '2028-10-01', 'deadline' => '2028-12-10', 'allowed' => 70, 'facts' => [
                    'CENRO Mati' => ['received' => '2028-12-10', 'days_used' => 70, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                ]],
            ],
        ];
    }

    if ($workflowKey === 'weekly_accomplishment') {
        return [
            'year' => 2026, 'period_count' => 51, 'expected_due' => 204, 'expected_on_time' => 203,
            'expected_early' => 2, 'expected_se_percent' => (100 + (100 / 15)) / 204,
            'focus_period' => 'W51', 'focus_deadline' => '2026-12-31', 'as_of_due' => 204,
            'checks' => [
                'W01' => ['start' => '2026-01-05', 'deadline' => '2026-01-20', 'allowed' => 15, 'facts' => [
                    'CENRO Baganga' => ['received' => '2026-01-05', 'days_used' => 0, 'status' => 'Submitted early', 'days_complied' => 15, 'rating' => 'Outstanding'],
                    'CENRO Manay' => ['received' => '2026-01-19', 'days_used' => 14, 'status' => 'Submitted early', 'days_complied' => 1, 'rating' => 'Satisfactory'],
                    'CENRO Mati' => ['received' => '2026-01-20', 'days_used' => 15, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                    'CENRO Lupon' => ['received' => '2026-01-21', 'days_used' => 16, 'status' => 'Submitted late', 'days_complied' => -1, 'rating' => 'Unsatisfactory'],
                ]],
                'W02' => ['start' => '2026-01-12', 'deadline' => '2026-01-20', 'allowed' => 8, 'facts' => [
                    'CENRO Mati' => ['received' => '2026-01-20', 'days_used' => 8, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                ]],
                'W03' => ['start' => '2026-01-19', 'deadline' => '2026-01-22', 'allowed' => 3, 'facts' => [
                    'CENRO Mati' => ['received' => '2026-01-22', 'days_used' => 3, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                ]],
                'W51' => ['start' => '2026-12-28', 'deadline' => '2026-12-31', 'allowed' => 3, 'facts' => [
                    'CENRO Mati' => ['received' => '2026-12-31', 'days_used' => 3, 'status' => 'Submitted on deadline', 'days_complied' => 0, 'rating' => 'Unsatisfactory'],
                ]],
            ],
        ];
    }

    if (in_array($workflowKey, ['elcac', 'forest_disturbance', 'monthly_accomplishment_pmd_fmb', 'cenro_nursery_seedling', 'tree_replacement'], true)) {
        return [
            'year' => 2028, 'period_count' => 12, 'expected_due' => 48, 'expected_on_time' => 47,
            'expected_early' => 2, 'expected_se_percent' => (100 + (100 / 19)) / 48,
            'focus_period' => '2028-02', 'focus_deadline' => '2028-02-20', 'as_of_due' => 8,
            'checks' => ['2028-02' => ['start' => '2028-02-01', 'deadline' => '2028-02-20', 'allowed' => 19, 'facts' => $monthlyBoundary]],
        ];
    }

    throw new InvalidArgumentException('No independent ENGP scorer facts for '.$workflowKey);
}

function engpScorerFixtureDefinition(array $workflow): ModuleDefinition
{
    return ModuleDefinition::query()->create([
        'name' => $workflow['label'], 'code' => 'engp_'.$workflow['key'], 'program_area' => ProgramArea::ENGP,
        'requirement_domain' => 'engp', 'requirement_key' => $workflow['key'],
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_SPECIALIZED,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET, 'reporting_frequency' => $workflow['period'],
        'deadline_mode' => ModuleDefinition::DEADLINE_CUSTOM, 'allow_deadline_override' => true,
        'existing_route_name' => 'engp-reports.index', 'existing_source_key' => 'engp',
        'requirement_metadata' => $workflow, 'is_active' => true,
    ]);
}

function expectEngpPercent(?float $actual, float $expected): void
{
    expect($actual)->not->toBeNull();
    expect(abs((float) $actual - $expected))->toBeLessThan(0.0001);
}

test('ENGP workflows score independently checked T, D, due cohorts and as-of evidence through both Development paths', function (string $workflowKey): void {
    $registry = app(EngpReportWorkflowRegistry::class);
    $requirements = app(ReportRequirementRegistry::class);
    $workflow = $registry->defaultFind($workflowKey);
    $facts = engpWorkflowBoundaryFacts($workflowKey);
    expect($workflow)->not->toBeNull();
    engpScorerFixtureDefinition($workflow);
    $requirements->forgetCache();

    $periods = $registry->periods($workflowKey, $facts['year']);
    expect($periods)->toHaveCount($facts['period_count']);
    $sourceRowIds = [];
    foreach ($periods as $period) {
        $periodKey = $period['key'];
        $deadline = $registry->deadline($workflowKey, $facts['year'], $periodKey);
        expect($deadline)->not->toBeNull();
        $receipts = $facts['checks'][$periodKey]['facts'] ?? [];
        foreach ($workflow['offices'] as $office) {
            $receipt = $receipts[$office]['received'] ?? $deadline;
            $record = EngpReportSubmission::query()->create([
                'workflow_key' => $workflowKey, 'office' => $office, 'section_name' => 'NGP',
                'activity_name' => $workflow['activity'], 'document_type' => $workflow['document'],
                'reporting_year' => $facts['year'], 'period_key' => $periodKey, 'period_label' => $period['label'],
                'deadline_submission' => $deadline, 'date_received_penro' => $receipt,
            ]);
            $sourceRowIds[$periodKey][$office] = $record->getKey();
        }
    }

    foreach ($facts['checks'] as $periodKey => $check) {
        expect($requirements->periodStartDate('engp', $workflowKey, $facts['year'], $periodKey))->toBe($check['start'])
            ->and($registry->deadline($workflowKey, $facts['year'], $periodKey))->toBe($check['deadline']);
        foreach ($check['facts'] as $office => $expected) {
            $source = EngpReportSubmission::query()->findOrFail($sourceRowIds[$periodKey][$office]);
            $detailExpected = [
                'start_date' => $check['start'], 'deadline' => $check['deadline'], 'received' => $expected['received'],
                'allowed_days' => $check['allowed'], 'days_used' => $expected['days_used'], 'status' => $expected['status'],
                'source_identity' => [
                    'workflow_key' => $workflowKey, 'workflow_label' => $workflow['label'],
                    'activity_name' => $workflow['activity'], 'document_type' => $workflow['document'],
                    'reporting_year' => $facts['year'], 'period_key' => $periodKey,
                    'participant_key' => 'cenro:'.OrganizationalOffice::query()->where('name', $office)->value('id'),
                    'occurrence_key' => null,
                ],
            ];
            expect($source->days_complied)->toBe($expected['days_complied'])
                ->and($source->timeliness_rating)->toBe($expected['rating']);
            $facts['checks'][$periodKey]['facts'][$office]['expected_detail'] = $detailExpected;
        }
    }

    if ($workflowKey === 'ngp_produce' || $workflowKey === 'nursery_maintenance' || $workflowKey === 'site_visit') {
        expect($registry->deadline($workflowKey, 2028, 'Q2'))->toBe('2028-06-10')
            ->and($registry->deadline($workflowKey, 2028, 'Q3'))->toBe('2028-09-10')
            ->and($registry->deadline($workflowKey, 2028, 'Q4'))->toBe('2028-12-10')
            ->and(CarbonImmutable::parse('2028-09-10')->isSunday())->toBeTrue();
    }
    if ($workflowKey === 'weekly_accomplishment') {
        $weeks2026 = $registry->periods($workflowKey, 2026);
        $weeks2027 = $registry->periods($workflowKey, 2027);
        expect($weeks2026)->toHaveCount(51)
            ->and(collect($weeks2026)->contains(fn (array $week): bool => str_contains($week['label'], 'Aug 31')))->toBeFalse()
            ->and(collect($weeks2026)->firstWhere('key', 'W51')['label'])->toContain('Dec 28-Dec 31')
            ->and($registry->deadline($workflowKey, 2026, 'W51'))->toBe('2026-12-31')
            ->and(collect($weeks2027)->firstWhere('key', 'W01')['label'])->toContain('Jan 4-Jan 7')
            ->and($registry->deadline($workflowKey, 2026, 'W02'))->toBe('2026-01-20')
            ->and($registry->deadline($workflowKey, 2026, 'W03'))->toBe('2026-01-22');
    }

    $viewer = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $viewer->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $viewer->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    $service = app(OfficeTatComplianceAssessmentService::class);
    $periodKey = (string) $facts['year'];
    $asOf = $facts['year'].'-12-31';
    $recorded = $service->recordedAssessments($viewer, 'development', $periodKey, $asOf);
    $official = $service->assessments($periodKey, $asOf)['development'];
    $expectedTc = 100 * $facts['expected_on_time'] / $facts['expected_due'];
    $expectedOtps = round(($expectedTc * 0.80) + ($facts['expected_se_percent'] * 0.20), 2);

    expect($recorded['as_of_date'])->toBe($asOf)
        ->and($recorded['roster_count'])->toBe(4)
        ->and($recorded['due_count'])->toBe($facts['expected_due'])
        ->and($recorded['on_time_count'])->toBe($facts['expected_on_time']);
    expectEngpPercent($recorded['tc_percent'], $expectedTc);
    expectEngpPercent($recorded['se_percent'], $facts['expected_se_percent']);
    expect($recorded['otps'])->toBe($expectedOtps);

    expect($official['period_key'])->toBe($periodKey)
        ->and($official['coverage_complete'])->toBeTrue()
        ->and($official['roster_count'])->toBe(4)
        ->and($official['due_count'])->toBe($facts['expected_due'])
        ->and($official['submitted_count'])->toBe($facts['expected_due'])
        ->and($official['volume_count'])->toBe($facts['expected_due'])
        ->and($official['on_time_count'])->toBe($facts['expected_on_time'])
        ->and($official['early_count'])->toBe($facts['expected_early']);
    expectEngpPercent($official['tc_percent'], $expectedTc);
    expectEngpPercent($official['se_percent'], $facts['expected_se_percent']);
    expect($official['otps'])->toBe($expectedOtps);

    foreach ($facts['checks'] as $periodKey => $check) {
        foreach ($check['facts'] as $office => $expected) {
            $participantKey = 'cenro:'.OrganizationalOffice::query()->where('name', $office)->value('id');
            $recordedOffice = collect($recorded['rows'])->firstWhere('participant_key', $participantKey);
            $recordedDetail = collect($recordedOffice['obligation_details'])->firstWhere('source_identity.period_key', $periodKey);
            $officialOffice = collect($official['rows'])->firstWhere('participant_key', $participantKey);
            $officialDetail = collect($officialOffice['obligation_details'])->firstWhere('source_identity.period_key', $periodKey);
            foreach ([$recordedDetail, $officialDetail] as $detail) {
                foreach ($facts['checks'][$periodKey]['facts'][$office]['expected_detail'] as $field => $value) {
                    expect(data_get($detail, $field))->toEqual($value);
                }
            }
            expect($recordedDetail['source_rows_matched'])->toBe(1)
                ->and($officialDetail['source_rows_matched'])->toBe(1);
        }
    }

    if ($workflowKey !== 'weekly_accomplishment') {
        $cutoffKey = str_contains($facts['focus_period'], 'Q') ? $facts['year'].'-H1' : $facts['year'].'-H1';
        $cutoffDate = $facts['focus_deadline'];
        $recordedCutoff = $service->recordedAssessments($viewer, 'development', $cutoffKey, $cutoffDate);
        $officialCutoff = $service->assessments($cutoffKey, $cutoffDate)['development'];
        $focusCheck = $facts['checks'][$facts['focus_period']];
        $lateOffice = collect($focusCheck['facts'])->first(fn (array $fact): bool => $fact['status'] === 'Submitted late');
        $lateOfficeName = collect($focusCheck['facts'])->search($lateOffice);
        $lateParticipant = 'cenro:'.OrganizationalOffice::query()->where('name', $lateOfficeName)->value('id');
        $recordedLate = collect(collect($recordedCutoff['rows'])->firstWhere('participant_key', $lateParticipant)['obligation_details'])
            ->firstWhere('source_identity.period_key', $facts['focus_period']);
        $officialLate = collect(collect($officialCutoff['rows'])->firstWhere('participant_key', $lateParticipant)['obligation_details'])
            ->firstWhere('source_identity.period_key', $facts['focus_period']);
        expect($recordedCutoff['as_of_date'])->toBe($cutoffDate)
            ->and($recordedCutoff['due_count'])->toBe($facts['as_of_due'])
            ->and($officialCutoff['coverage_complete'])->toBeTrue()
            ->and($officialCutoff['due_count'])->toBe($facts['as_of_due'])
            ->and($officialCutoff['submitted_count'])->toBe($facts['as_of_due'] - 1)
            ->and($officialCutoff['on_time_count'])->toBe($facts['as_of_due'] - 1)
            ->and($recordedLate['received'])->toBe($lateOffice['received'])
            ->and($recordedLate['days_used'])->toBeNull()
            ->and($recordedLate['status'])->toBe('Overdue at cutoff with no accepted receipt')
            ->and($officialLate['received'])->toBe($lateOffice['received'])
            ->and($officialLate['days_used'])->toBeNull()
            ->and($officialLate['status'])->toBe('Non-receipt evidence is not verified');
    }
})->with([
    'cbep' => ['cbep'],
    'elcac' => ['elcac'],
    'ngp staff monthly accomplishment' => ['ngp_staff_accomplishment'],
    'forest disturbance' => ['forest_disturbance'],
    'monthly PMD and FMB' => ['monthly_accomplishment_pmd_fmb'],
    'CENRO nursery seedling' => ['cenro_nursery_seedling'],
    'tree replacement monthly' => ['tree_replacement'],
    'RIMS January exception and regular month' => ['rims'],
    'NGP Produce quarterly' => ['ngp_produce'],
    'nursery maintenance quarterly' => ['nursery_maintenance'],
    'site visit quarterly' => ['site_visit'],
    'weekly accomplishment' => ['weekly_accomplishment'],
]);

test('ENGP missing pre-effective receipt evidence withholds the official score and keeps the source date absent', function (): void {
    $workflow = app(EngpReportWorkflowRegistry::class)->defaultFind('elcac');
    engpScorerFixtureDefinition($workflow);
    app(ReportRequirementRegistry::class)->forgetCache();
    foreach (app(EngpReportWorkflowRegistry::class)->periods('elcac', 2026) as $period) {
        $deadline = app(EngpReportWorkflowRegistry::class)->deadline('elcac', 2026, $period['key']);
        foreach ($workflow['offices'] as $office) {
            if ($period['key'] === '2026-01' && $office === 'CENRO Baganga') continue;
            EngpReportSubmission::query()->create([
                'workflow_key' => 'elcac', 'office' => $office, 'section_name' => 'NGP',
                'activity_name' => $workflow['activity'], 'document_type' => $workflow['document'],
                'reporting_year' => 2026, 'period_key' => $period['key'], 'period_label' => $period['label'],
                'deadline_submission' => $deadline, 'date_received_penro' => $deadline,
            ]);
        }
    }

    $official = app(OfficeTatComplianceAssessmentService::class)->assessments('2026', '2026-12-31')['development'];
    $baganga = collect($official['rows'])->firstWhere('name', 'CENRO Baganga');
    $missing = collect($baganga['obligation_details'])->firstWhere('source_identity.period_key', '2026-01');
    expect($official['coverage_complete'])->toBeFalse()
        ->and($official['due_count'])->toBeNull()
        ->and($official['tc_percent'])->toBeNull()
        ->and($official['se_percent'])->toBeNull()
        ->and($official['otps'])->toBeNull()
        ->and($baganga['score_status'])->toBe('Coverage incomplete')
        ->and($missing['start_date'])->toBe('2026-01-01')
        ->and($missing['deadline'])->toBe('2026-01-20')
        ->and($missing['received'])->toBeNull()
        ->and($missing['allowed_days'])->toBe(19)
        ->and($missing['days_used'])->toBeNull()
        ->and($missing['status'])->toBe('Historical inventory unverified');
});

test('ENGP weekly as-of projection excludes a post-cutoff receipt without inventing its missing date', function (): void {
    $workflow = app(EngpReportWorkflowRegistry::class)->defaultFind('weekly_accomplishment');
    engpScorerFixtureDefinition($workflow);
    app(ReportRequirementRegistry::class)->forgetCache();
    $periods = app(EngpReportWorkflowRegistry::class)->periods('weekly_accomplishment', 2028);
    expect($periods)->toHaveCount(52);

    foreach ($periods as $period) {
        foreach ($workflow['offices'] as $office) {
            $deadline = app(EngpReportWorkflowRegistry::class)->deadline('weekly_accomplishment', 2028, $period['key']);
            $received = $period['key'] === 'W01' && $office === 'CENRO Lupon' ? '2028-01-21' : $deadline;
            EngpReportSubmission::query()->create([
                'workflow_key' => 'weekly_accomplishment', 'office' => $office, 'section_name' => 'NGP',
                'activity_name' => $workflow['activity'], 'document_type' => $workflow['document'],
                'reporting_year' => 2028, 'period_key' => $period['key'], 'period_label' => $period['label'],
                'deadline_submission' => $deadline, 'date_received_penro' => $received,
            ]);
        }
    }

    $viewer = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $viewer->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $viewer->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    $service = app(OfficeTatComplianceAssessmentService::class);
    $recorded = $service->recordedAssessments($viewer, 'development', '2028-H1', '2028-01-20');
    $official = $service->assessments('2028-H1', '2028-01-20')['development'];
    $lateKey = 'cenro:'.OrganizationalOffice::query()->where('name', 'CENRO Lupon')->value('id');
    $recordedLate = collect(collect($recorded['rows'])->firstWhere('participant_key', $lateKey)['obligation_details'])
        ->firstWhere('source_identity.period_key', 'W01');
    $officialLate = collect(collect($official['rows'])->firstWhere('participant_key', $lateKey)['obligation_details'])
        ->firstWhere('source_identity.period_key', 'W01');
    expect($recorded['as_of_date'])->toBe('2028-01-20')
        ->and($recorded['due_count'])->toBe(12)
        ->and($official['coverage_complete'])->toBeTrue()
        ->and($official['due_count'])->toBe(12)
        ->and($official['submitted_count'])->toBe(11)
        ->and($official['on_time_count'])->toBe(11)
        ->and($recordedLate['received'])->toBe('2028-01-21')
        ->and($recordedLate['days_used'])->toBeNull()
        ->and($recordedLate['status'])->toBe('Overdue at cutoff with no accepted receipt')
        ->and($officialLate['received'])->toBe('2028-01-21')
        ->and($officialLate['days_used'])->toBeNull()
        ->and($officialLate['status'])->toBe('Non-receipt evidence is not verified');

    foreach (app(EngpReportWorkflowRegistry::class)->periods('weekly_accomplishment', 2026) as $period) {
        $deadline = app(EngpReportWorkflowRegistry::class)->deadline('weekly_accomplishment', 2026, $period['key']);
        foreach ($workflow['offices'] as $office) {
            EngpReportSubmission::query()->create([
                'workflow_key' => 'weekly_accomplishment', 'office' => $office, 'section_name' => 'NGP',
                'activity_name' => $workflow['activity'], 'document_type' => $workflow['document'],
                'reporting_year' => 2026, 'period_key' => $period['key'], 'period_label' => $period['label'],
                'deadline_submission' => $deadline, 'date_received_penro' => $deadline,
            ]);
        }
    }

    $this->actingAs($viewer)->get(route('office-tat.index', [
        'unit' => 'development', 'processing_period' => '2026', 'as_of_date' => '2026-10-15',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedUnit', 'development')
        ->where('filters.processing_period', '2026')
        ->where('complianceAssessments.development.due_count', 160)
        ->where('complianceAssessments.development.coverage_complete', true)
        ->where('complianceAssessments.development.tc_percent', 100)
        ->where('complianceAssessments.development.se_percent', 0)
        ->where('complianceAssessments.development.otps', 80)
        ->where('recordedAssessment.due_count', 160)
        ->where('recordedAssessment.as_of_date', '2026-10-15')
        ->where('recordedAssessment.tc_percent', 100)
        ->where('recordedAssessment.se_percent', 0)
        ->where('recordedAssessment.otps', 80));
});
