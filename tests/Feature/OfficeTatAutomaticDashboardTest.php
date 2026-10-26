<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\OfficeTatComplianceAssessmentService;
use App\Services\Reports\ReportRequirementRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Storage::fake('local');
    $this->seed(ModuleDefinitionSeeder::class);

    foreach (['CENRO Baganga', 'CENRO Manay', 'CENRO Lupon', 'CENRO Mati'] as $index => $name) {
        $office = OrganizationalOffice::query()->where('name', $name)->first();
        if (! $office) {
            OrganizationalOffice::query()->create([
                'code' => 'automatic-tat-cenro-'.$index, 'name' => $name, 'office_type' => 'cenro', 'is_active' => true,
            ]);
        } else {
            $office->forceFill(['office_type' => 'cenro', 'is_active' => true])->save();
        }
    }
});

function automaticTatGlobalViewer(): User
{
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));

    return $actor;
}

function automaticTatArea(string $name): ProtectedArea
{
    return ProtectedArea::query()->create([
        'name' => $name, 'short_name' => strtoupper(substr($name, 0, 5)),
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Proposed',
        'pamo' => 'PAMO Dashboard Test',
    ]);
}

function automaticTatEngpSource(string $office, string $period, ?string $received): EngpReportSubmission
{
    $registry = app(EngpReportWorkflowRegistry::class);
    $requirements = app(ReportRequirementRegistry::class);
    $definition = $registry->find('cbep');

    return EngpReportSubmission::query()->create([
        'workflow_key' => 'cbep', 'office' => $office, 'section_name' => 'ENGP',
        'activity_name' => $definition['activity'], 'document_type' => $definition['document'],
        'reporting_year' => 2026, 'period_key' => $period,
        'period_label' => data_get($registry->period('cbep', 2026, $period), 'label', $period),
        'deadline_submission' => $registry->deadline('cbep', 2026, $period),
        'date_received_penro' => $received,
    ]);
}

function automaticTatPaSource(ProtectedArea $area, array $pair, string $periodLabel, ?string $date, ?string $received): ConservationReportSubmission
{
    return ConservationReportSubmission::query()->create([
        'workflow_key' => $pair['workflow_key'], 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => $pair['activity_name'],
        'document_type' => $pair['document_type'], 'reporting_period' => $periodLabel,
        'date_conducted' => $date, 'date_accomplished' => $date, 'date_received_penro' => $received,
    ]);
}

test('recorded ENGP scoring uses only matched source identities and excludes future work from due N', function (): void {
    $actor = automaticTatGlobalViewer();
    $registry = app(EngpReportWorkflowRegistry::class);
    $requirements = app(ReportRequirementRegistry::class);
    foreach (['2026-08', '2026-10', '2026-11'] as $period) {
        $deadline = $registry->deadline('cbep', 2026, $period);
        expect($deadline)->not->toBeNull();
        $receipt = match ($period) {
            '2026-08' => \Carbon\CarbonImmutable::parse($deadline)->addDays(2)->toDateString(),
            '2026-10' => $deadline,
            default => null,
        };
        automaticTatEngpSource('CENRO Baganga', $period, $receipt);
    }
    EngpReportSubmission::query()->create([
        'workflow_key' => 'unmapped_engp_test', 'office' => 'CENRO Baganga', 'section_name' => 'ENGP',
        'activity_name' => 'Unmapped test source', 'document_type' => 'Report',
        'reporting_year' => 2026, 'period_key' => '2026-06', 'period_label' => 'June 2026',
        'deadline_submission' => '2026-06-20', 'date_received_penro' => '2026-06-20',
    ]);
    $unsupportedEngp = EngpReportSubmission::query()->create([
        'workflow_key' => 'unmapped_engp_test', 'office' => 'CENRO Baganga', 'section_name' => 'ENGP',
        'activity_name' => 'Unmapped test source', 'document_type' => 'Report',
        'reporting_year' => 2026, 'period_key' => '2026-10', 'period_label' => 'October 2026',
        'deadline_submission' => '2026-10-24', 'date_received_penro' => '2026-10-24',
    ]);

    $service = app(OfficeTatComplianceAssessmentService::class);
    $recorded = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-31');
    $baganga = collect($recorded['rows'])->firstWhere('name', 'CENRO Baganga');
    $unsupportedDetail = collect($baganga['obligation_details'])->firstWhere('source_reference', 'ENGP report #'.$unsupportedEngp->getKey());
    $futureRecorded = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-20');
    $futureBaganga = collect($futureRecorded['rows'])->firstWhere('name', 'CENRO Baganga');
    $futureUnsupportedDetail = collect($futureBaganga['obligation_details'])->firstWhere('source_reference', 'ENGP report #'.$unsupportedEngp->getKey());
    $octoberCbep = collect($baganga['obligation_details'])->first(fn (array $detail): bool =>
        ($detail['source_identity']['workflow_key'] ?? null) === 'cbep'
        && ($detail['source_identity']['period_key'] ?? null) === '2026-10'
    );
    $official = $service->assessments('2026-H2', '2026-10-31')['development'];
    $officialBaganga = collect($official['rows'])->firstWhere('name', 'CENRO Baganga');
    $officialOctoberCbep = collect($officialBaganga['obligation_details'])->first(fn (array $detail): bool =>
        ($detail['source_identity']['workflow_key'] ?? null) === 'cbep'
        && ($detail['source_identity']['period_key'] ?? null) === '2026-10'
    );

    expect($recorded['basis'])->toBe('recorded_live_source')
        ->and($recorded['activity_count'])->toBe(3)
        ->and($recorded['received_activity_count'])->toBe(2)
        ->and($recorded['not_yet_due_count'])->toBe(1)
        ->and($recorded['due_count'])->toBeGreaterThan($recorded['activity_count'])
        ->and($recorded['incomplete_evidence_count'])->toBe(1)
        ->and($recorded['tc_percent'])->toEqual(100 * $recorded['on_time_count'] / $recorded['due_count'])
        ->and($unsupportedDetail['status'])->toBe('Unsupported source identity')
        ->and($unsupportedDetail['source_reference'])->toBe('ENGP report #'.$unsupportedEngp->getKey())
        ->and($unsupportedDetail['source_identity']['workflow_key'])->toBe('unmapped_engp_test')
        ->and($unsupportedDetail['source_identity']['activity_name'])->toBe('Unmapped test source')
        ->and($unsupportedDetail['received'])->toBe('2026-10-24')
        ->and($unsupportedDetail['deadline'])->toBe('2026-10-24')
        ->and($unsupportedDetail['due_status'])->toBe('due')
        ->and($unsupportedDetail['due_as_of_date'])->toBe('2026-10-31')
        ->and($futureUnsupportedDetail['due_status'])->toBe('not_yet_due')
        ->and($futureUnsupportedDetail['due_as_of_date'])->toBe('2026-10-20')
        ->and($baganga['activity_count'])->toBe(3)
        ->and($baganga['due_count'])->toBeGreaterThan($baganga['activity_count'])
        ->and($baganga['tc_percent'])->toEqual(100 * $baganga['on_time_count'] / $baganga['due_count'])
        ->and($octoberCbep['allowed_days'])->toBe(20)
        ->and($octoberCbep['days_used'])->toBe(20)
        ->and($octoberCbep['status'])->toBe('Submitted on deadline')
        ->and($octoberCbep['day_convention'])->toContain('inclusive')
        ->and(collect($baganga['obligation_details'])->contains(fn (array $detail): bool =>
            ($detail['source_identity']['workflow_key'] ?? null) === 'cbep'
            && ($detail['source_identity']['period_key'] ?? null) === '2026-09'
            && ($detail['source_identity']['source_id'] ?? null) === null
        ))->toBeTrue()
        ->and($official['coverage_complete'])->toBeFalse()
        ->and($official['due_count'])->toBeNull()
        ->and($officialOctoberCbep['allowed_days'])->toBe(20)
        ->and($officialOctoberCbep['days_used'])->toBe(20)
        ->and($officialOctoberCbep['status'])->toBe('Submitted on deadline')
        ->and($officialOctoberCbep['day_convention'])->toContain('inclusive');
});

test('recognized ENGP source identity stays excluded until its canonical definition is active, then scores without rewriting title', function (): void {
    $actor = automaticTatGlobalViewer();
    $requirements = app(ReportRequirementRegistry::class);
    \App\Models\ModuleDefinition::query()->where('code', 'engp_elcac')->delete();
    $requirements->forgetCache();

    $title = 'QA Office TAT Run01 ELCAC free-text activity';
    $record = EngpReportSubmission::query()->create([
        'workflow_key' => 'elcac', 'office' => 'CENRO Baganga', 'section_name' => 'ENGP',
        'activity_name' => $title, 'document_type' => 'Monthly Report',
        'reporting_year' => 2026, 'period_key' => '2026-10', 'period_label' => 'October 2026',
        'deadline_submission' => '2026-10-20', 'date_received_penro' => '2026-10-22',
    ]);

    $service = app(OfficeTatComplianceAssessmentService::class);
    $before = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-22');
    $beforeRow = collect($before['rows'])->firstWhere('name', 'CENRO Baganga');
    $excluded = collect($beforeRow['obligation_details'])->firstWhere('source_reference', 'ENGP report #'.$record->getKey());

    expect($excluded['status'])->toBe('Missing or inactive workflow definition')
        ->and($excluded['issue'])->toContain('workflow is recognized')
        ->and($beforeRow['activity_count'])->toBe(0)
        ->and($beforeRow['incomplete_evidence_count'])->toBe(1);

    $setup = app(\App\Services\Modules\CanonicalModuleDefinitionService::class)->ensureCanonicalEngpDefinitions();
    $requirements->forgetCache();
    $after = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-22');
    $afterRow = collect($after['rows'])->firstWhere('name', 'CENRO Baganga');
    $scored = collect($afterRow['obligation_details'])->first(fn (array $detail): bool =>
        ($detail['source_identity']['workflow_key'] ?? null) === 'elcac'
        && ($detail['source_identity']['period_key'] ?? null) === '2026-10'
    );

    expect($setup['created'])->toHaveCount(1)
        ->and($afterRow['activity_count'])->toBe(1)
        ->and($afterRow['received_activity_count'])->toBe(1)
        ->and($afterRow['due_count'])->toBeGreaterThan($afterRow['activity_count'])
        ->and($afterRow['on_time_count'])->toBe(0)
        ->and($afterRow['tc_percent'])->toEqual(0.0)
        ->and($afterRow['incomplete_evidence_count'])->toBe(0)
        ->and($scored['status'])->toBe('Submitted late')
        ->and($scored['deadline'])->toBe('2026-10-20')
        ->and($scored['received'])->toBe('2026-10-22')
        ->and($scored['source_reference'])->toBe('ENGP report #'.$record->getKey())
        ->and($scored['source_identity']['source_ids'])->toBe([$record->getKey()])
        ->and($record->fresh()->activity_name)->toBe($title);
});

test('uniquely registered Conservation identity preserves the free-text title and assesses an early PENRO receipt', function (): void {
    $actor = automaticTatGlobalViewer();
    $area = automaticTatArea('Free-text PAMB identity fixture');
    $catalogue = app(App\Services\OfficeTatObligationEvidenceSetupService::class)->catalogue();
    $report = collect($catalogue)->first(fn (array $pair): bool => $pair['workflow_key'] === 'regular_pamb' && $pair['document_type'] === 'Report');
    $periods = app(ReportRequirementRegistry::class)->periods('pa', 'regular_pamb', 2026);
    $period = collect($periods)->firstWhere('key', 'Q4') ?? collect($periods)->firstWhere('key', 'quarter_4') ?? $periods[3];
    $title = 'QA OFFICE - MHRWS - 2026-10-19';

    $record = ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => $title,
        'document_type' => $report['document_type'], 'reporting_period' => $period['label'],
        'date_conducted' => '2026-10-19', 'date_accomplished' => '2026-10-19',
        'date_received_penro' => '2026-10-19',
    ]);

    $service = app(OfficeTatComplianceAssessmentService::class);
    $recorded = $service->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-20');
    $row = collect($recorded['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $detail = collect($row['obligation_details'])->firstWhere('source_identity.submitted_activity_name', $title);
    $later = $service->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-31');
    $laterRow = collect($later['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $laterDetail = collect($laterRow['obligation_details'])->firstWhere('source_identity.submitted_activity_name', $title);

    expect($detail)->not->toBeNull()
        ->and($detail['status'])->toBe('Submitted early')
        ->and($detail['source_identity']['registered_activity_name'])->toBe('Regular PAMB')
        ->and($detail['source_identity']['submitted_activity_name'])->toBe($title)
        ->and($detail['deadline'])->toBe('2026-10-29')
        ->and($detail['due_status'])->toBe('received_before_deadline')
        ->and($recorded['activity_count'])->toBe(1)
        ->and($recorded['received_activity_count'])->toBe(1)
        ->and($recorded['due_count'])->toBe(1)
        ->and($row['tc_percent'])->toEqual(100.0)
        ->and($row['se_percent'])->toEqual(100.0)
        ->and($row['otps'])->toEqual(100.0)
        ->and($laterDetail['due_status'])->toBe('due')
        ->and($later['activity_count'])->toBe(1)
        ->and($later['received_activity_count'])->toBe(1)
        ->and($later['due_count'])->toBe(1);
});


test('recorded Conservation deduplicates PAMB source identities and excludes historical source details', function (): void {
    $actor = automaticTatGlobalViewer();
    $area = automaticTatArea('Automatic Dashboard PA');
    $catalogue = app(App\Services\OfficeTatObligationEvidenceSetupService::class)->catalogue();
    $regular = collect($catalogue)->first(fn (array $pair): bool => $pair['workflow_key'] === 'regular_pamb');
    $special = collect($catalogue)->first(fn (array $pair): bool => $pair['workflow_key'] === 'special_pamb');
    expect($regular)->not->toBeNull()->and($special)->not->toBeNull();

    $requirements = app(ReportRequirementRegistry::class);
    $regularPeriods = $requirements->periods('pa', 'regular_pamb', 2026);
    $specialPeriods = $requirements->periods('pa', 'special_pamb', 2026);
    $regularQ3 = collect($regularPeriods)->firstWhere('key', 'Q3') ?? $regularPeriods[2];
    $regularQ4 = collect($regularPeriods)->firstWhere('key', 'Q4') ?? $regularPeriods[3];
    $specialQ4 = collect($specialPeriods)->firstWhere('key', 'Q4') ?? $specialPeriods[3];

    $first = automaticTatPaSource($area, $regular, $regularQ3['label'], '2026-08-03', '2026-08-10');
    automaticTatPaSource($area, $regular, $regularQ3['label'], '2026-08-03', '2026-08-11');
    automaticTatPaSource($area, $regular, $regularQ3['label'], '2025-08-03', '2025-08-10');
    automaticTatPaSource($area, $regular, $regularQ4['label'], null, null);
    $liveConflict = automaticTatPaSource($area, $special, $specialQ4['label'], '2026-10-12', null);
    $service = app(OfficeTatComplianceAssessmentService::class);
    $withoutHistorical = $service->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-31');
    $withoutHistoricalRow = collect($withoutHistorical['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $historicConflict = automaticTatPaSource($area, $special, $specialQ4['label'], '2026-10-12', null);
    $historicConflict->forceFill(['is_historical' => true])->save();
    HistoricalReportOrigin::query()->create([
        'source' => 'conservation', 'source_record_id' => $historicConflict->getKey(),
        'workflow_key' => 'special_pamb', 'encoded_by' => $actor->getKey(),
        'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        'original_milestones' => ['provenance' => 'manually_recorded_original_dates', 'milestones' => [
            'date_received_penro' => '2026-10-20',
        ]],
        'encoded_at' => now(),
    ]);

    $recorded = $service->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-31');
    $paRow = collect($recorded['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $regularDetail = collect($paRow['obligation_details'])->firstWhere('source_identity.workflow_key', 'regular_pamb');
    $historicalDetail = collect($paRow['obligation_details'])->first(fn (array $detail): bool =>
        ($detail['source_identity']['source_id'] ?? null) === $historicConflict->getKey()
    );

    expect($first->getKey())->toBeInt()
        ->and($liveConflict->getKey())->not->toBe($historicConflict->getKey())
        ->and($recorded['activity_count'])->toBe(3)
        ->and($recorded['due_count'])->toBe($withoutHistorical['due_count'])
        ->and($recorded['incomplete_date_count'])->toBe($withoutHistorical['incomplete_date_count'])
        ->and($recorded['incomplete_evidence_count'])->toBe($withoutHistorical['incomplete_evidence_count'])
        ->and($recorded['on_time_count'])->toBe($withoutHistorical['on_time_count'])
        ->and($paRow['activity_count'])->toBe($withoutHistoricalRow['activity_count'])
        ->and($paRow['due_count'])->toBe($withoutHistoricalRow['due_count'])
        ->and($paRow['incomplete_evidence_count'])->toBe($withoutHistoricalRow['incomplete_evidence_count'])
        ->and($regularDetail['received'])->toBe('2026-08-10')
        ->and($regularDetail['source_rows_matched'])->toBe(2)
        ->and($historicalDetail)->toBeNull()
        ->and(array_key_exists('historical_excluded_count', $paRow))->toBeFalse();
});

test('recorded assessment enforces the viewer unit and designated CENRO scope', function (): void {
    $viewer = User::factory()->create([
        'unit_assignment' => 'development', 'section' => 'ENGP', 'office_designated' => 'CENRO Baganga',
    ]);
    $viewer->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    automaticTatEngpSource('CENRO Baganga', '2026-10', '2026-10-20');
    automaticTatEngpSource('CENRO Manay', '2026-10', '2026-10-20');
    $service = app(OfficeTatComplianceAssessmentService::class);

    $recorded = $service->recordedAssessments($viewer, 'development', '2026-H2', '2026-10-31');
    expect($recorded['roster_count'])->toBe(1)
        ->and(collect($recorded['rows'])->pluck('name')->all())->toBe(['CENRO Baganga'])
        ->and($recorded['activity_count'])->toBe(1);

    expect(fn () => $service->recordedAssessments($viewer, 'conservation', '2026-H2', '2026-10-31'))
        ->toThrow(HttpException::class);
});

test('automated recognition rosters and module filters use separate canonical groups', function (): void {
    $actor = automaticTatGlobalViewer();
    foreach ([
        'MHRWS', 'APL', 'BMSFR', 'BPL', 'MPL', 'PBPLS',
    ] as $name) automaticTatArea($name.' roster fixture');

    $service = app(OfficeTatComplianceAssessmentService::class);
    $conservation = $service->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-26');
    $development = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-26');
    $paModules = collect($service->moduleOptions('conservation'))->pluck('value');
    $engpModules = collect($service->moduleOptions('development'))->pluck('value');
    $genericKeys = app(ReportRequirementRegistry::class)->definitions('pa', true, 'generic')
        ->reject(fn (array $definition): bool => ($definition['module_type'] ?? null) === \App\Models\ModuleDefinition::TYPE_PLAN)
        ->pluck('key');
    $nativeKeys = array_keys(app(\App\Services\Pamo\PamoNativeSourceRegistry::class)->all());
    $engpKeys = collect(app(EngpReportWorkflowRegistry::class)->all())->pluck('key');
    $periodValues = collect($service->periodOptions())->pluck('value');

    expect($conservation['roster_count'])->toBe(6)
        ->and($conservation['expected_roster_count'])->toBe(6)
        ->and($development['roster_count'])->toBe(4)
        ->and($development['expected_roster_count'])->toBe(4)
        ->and($paModules->intersect($genericKeys)->sort()->values()->all())->toBe($genericKeys->sort()->values()->all())
        ->and($paModules->intersect($nativeKeys)->sort()->values()->all())->toBe(collect($nativeKeys)->sort()->values()->all())
        ->and($paModules)->not->toContain('management_plans')
        ->and($engpModules->sort()->values()->all())->toBe($engpKeys->sort()->values()->all())
        ->and($periodValues)->toContain(CarbonImmutable::now('Asia/Manila')->year.'-H2');
});

test('PA traffic handled by a CENRO does not change that CENRO Development score', function (): void {
    $actor = automaticTatGlobalViewer();
    $area = automaticTatArea('PA handling stays in Conservation');
    automaticTatEngpSource('CENRO Baganga', '2026-10', '2026-10-20');
    $service = app(OfficeTatComplianceAssessmentService::class);
    $before = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-26');
    $bagangaBefore = collect($before['rows'])->firstWhere('name', 'CENRO Baganga');

    $pair = collect(app(App\Services\OfficeTatObligationEvidenceSetupService::class)->catalogue())
        ->first(fn (array $item): bool => $item['workflow_key'] === 'regular_pamb');
    $period = collect(app(ReportRequirementRegistry::class)->periods('pa', 'regular_pamb', 2026))->last();
    automaticTatPaSource($area, $pair, $period['label'], '2026-10-18', '2026-10-20');

    $after = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-26');
    $bagangaAfter = collect($after['rows'])->firstWhere('name', 'CENRO Baganga');
    expect($bagangaAfter['activity_count'])->toBe($bagangaBefore['activity_count'])
        ->and($bagangaAfter['due_count'])->toBe($bagangaBefore['due_count'])
        ->and($bagangaAfter['tc_percent'])->toBe($bagangaBefore['tc_percent'])
        ->and($bagangaAfter['se_percent'])->toBe($bagangaBefore['se_percent'])
        ->and($bagangaAfter['otps'])->toBe($bagangaBefore['otps'])
        ->and($after['result_identity'])->toBe($before['result_identity']);
});
