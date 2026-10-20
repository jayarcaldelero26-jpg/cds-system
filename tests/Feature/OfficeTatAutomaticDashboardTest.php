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
    EngpReportSubmission::query()->create([
        'workflow_key' => 'unmapped_engp_test', 'office' => 'CENRO Baganga', 'section_name' => 'ENGP',
        'activity_name' => 'Unmapped test source', 'document_type' => 'Report',
        'reporting_year' => 2026, 'period_key' => '2026-10', 'period_label' => 'October 2026',
        'deadline_submission' => '2026-10-24', 'date_received_penro' => '2026-10-24',
    ]);

    $service = app(OfficeTatComplianceAssessmentService::class);
    $recorded = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-31');
    $baganga = collect($recorded['rows'])->firstWhere('name', 'CENRO Baganga');
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
        ->and($recorded['due_count'])->toBe(2)
        ->and($recorded['incomplete_evidence_count'])->toBe(1)
        ->and($recorded['tc_percent'])->toBe(50)
        ->and($baganga['activity_count'])->toBe(3)
        ->and($baganga['due_count'])->toBe(2)
        ->and($octoberCbep['allowed_days'])->toBe(20)
        ->and($octoberCbep['days_used'])->toBe(20)
        ->and($octoberCbep['status'])->toBe('Submitted on deadline')
        ->and($octoberCbep['day_convention'])->toContain('inclusive')
        ->and(collect($baganga['obligation_details'])->pluck('source_identity.period_key')->all())->not->toContain('2026-09')
        ->and($official['coverage_complete'])->toBeFalse()
        ->and($official['due_count'])->toBeNull()
        ->and($officialOctoberCbep['allowed_days'])->toBe(20)
        ->and($officialOctoberCbep['days_used'])->toBe(20)
        ->and($officialOctoberCbep['status'])->toBe('Submitted on deadline')
        ->and($officialOctoberCbep['day_convention'])->toContain('inclusive');
});

test('recorded Conservation deduplicates PAMB source identities and separates incomplete and historical-conflict evidence', function (): void {
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

    $recorded = app(OfficeTatComplianceAssessmentService::class)->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-31');
    $paRow = collect($recorded['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $regularDetail = collect($paRow['obligation_details'])->firstWhere('source_identity.workflow_key', 'regular_pamb');
    $conflictDetail = collect($paRow['obligation_details'])->firstWhere('status', 'Conflicting historical receipt');

    expect($first->getKey())->toBeInt()
        ->and($liveConflict->getKey())->not->toBe($historicConflict->getKey())
        ->and($recorded['activity_count'])->toBe(3)
        ->and($recorded['due_count'])->toBe(1)
        ->and($recorded['incomplete_date_count'])->toBe(1)
        ->and($recorded['incomplete_evidence_count'])->toBe(1)
        ->and($recorded['on_time_count'])->toBe(1)
        ->and($regularDetail['received'])->toBe('2026-08-10')
        ->and($regularDetail['source_rows_matched'])->toBe(2)
        ->and($conflictDetail['received'])->toBeNull()
        ->and($conflictDetail['issue'])->toContain('cannot be credited as live or treated as a verified miss');
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
