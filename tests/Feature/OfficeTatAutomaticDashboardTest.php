<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
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

test('recorded module coverage includes native BMS reports and deduplicates completed archives', function (): void {
    $viewer = automaticTatGlobalViewer();
    $area = automaticTatArea('Native BMS Coverage Area');
    $rows = collect();
    foreach ([0, 1] as $index) {
        $rows->push(BmsReportSubmission::query()->create([

            'protected_area_id' => $area->getKey(),
            'target_office' => 'CENRO Baganga',
            'activity_name' => 'BMS coverage fixture '.$index,
            'document_type' => 'BMS Report',
            'semester' => '2nd Semester',
            'date_accomplished' => '2026-10-15',
            'date_received_penro' => '2026-10-20',
        ]));
    }

    $receipt = FinalDocumentReceipt::query()->create([
        'source_type' => 'bms', 'source_id' => (int) $rows[0]->getKey(), 'workflow_key' => 'bms',
        'release_evidence_type' => 'regional_endorsement', 'release_evidence_key' => 'bms-coverage-release-' . $rows[0]->getKey(),
        'received_by' => $viewer->getKey(), 'receiving_category' => 'PENRO_CDS_FOCAL',
        'receiving_office' => 'PENRO Davao Oriental', 'physically_received_at' => '2026-10-20 09:00:00',
        'recorded_at' => '2026-10-20 09:00:00',
    ]);
    FinalDocumentArchiveManifest::query()->create([
        'receipt_id' => $receipt->getKey(), 'source_type' => 'bms', 'source_id' => (int) $rows[0]->getKey(),
        'workflow_key' => 'bms', 'archive_phase' => FinalDocumentArchiveManifest::PHASE,
        'revision' => 1, 'intent_key' => (string) Str::uuid(), 'archive_status' => 'ARCHIVED',
        'signature_confirmation' => true, 'signature_set_sha256' => str_repeat('b', 64),
        'signature_confirmed_by' => $viewer->getKey(), 'signature_confirmed_at' => '2026-10-20 09:01:00',
        'created_by' => $viewer->getKey(), 'finalized_at' => '2026-10-20 09:02:00',
    ]);

    $recorded = app(OfficeTatComplianceAssessmentService::class)
        ->recordedAssessments($viewer, 'conservation', '2026-H2', '2026-10-21');
    $participant = collect($recorded['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $coverage = collect($recorded['module_coverage'])->firstWhere('source_key', 'bms');
    $bmsDetails = collect($participant['obligation_details'])
        ->filter(fn (array $detail): bool => ($detail['source_identity']['source_key'] ?? null) === 'bms');

    expect($recorded['activity_count'])->toBe(2)
        ->and($recorded['received_activity_count'])->toBe(2)
        ->and($recorded['not_yet_due_count'])->toBe(2)
        ->and($participant['due_count'])->toBe(2)
        ->and($bmsDetails)->toHaveCount(2)
        ->and($bmsDetails->pluck('source_identity.source_id')->sort()->values()->all())->toBe($rows->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->and($bmsDetails->every(fn (array $detail): bool => $detail['deadline'] > '2026-10-21'))->toBeTrue()
        ->and($coverage['live_record_count'])->toBe(2)
        ->and($coverage['completed_archived_count'])->toBe(1)
        ->and($coverage['eligible_recorded_count'])->toBe(2)
        ->and($coverage['received_count'])->toBe(2)
        ->and($coverage['due_count'])->toBe(0);
});
test('Office TAT page projects native live TAT samples into the module coverage table', function (): void {
    $viewer = automaticTatGlobalViewer();
    $area = automaticTatArea('Native TAT Projection Area');
    $record = BmsReportSubmission::query()->create([
        'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'Native TAT projection fixture',
        'document_type' => 'BMS Report',
        'semester' => '2nd Semester',
        'date_accomplished' => '2026-10-15',
        'date_received_penro' => '2026-10-20',
    ]);

    DocumentRoutingEvent::query()->create([
        'source_type' => 'bms', 'source_id' => $record->getKey(), 'workflow_key' => null,
        'event_key' => 'received', 'from_stage' => 'penro_records', 'to_stage' => 'cenro_records',
        'from_office' => 'PENRO Davao Oriental', 'to_office' => 'CENRO Baganga',
        'occurred_at' => '2026-10-20 09:00:00',
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'bms', 'source_id' => $record->getKey(), 'workflow_key' => null,
        'event_key' => 'forwarded', 'from_stage' => 'cenro_records', 'to_stage' => 'penro_records',
        'from_office' => 'CENRO Baganga', 'to_office' => 'PENRO Davao Oriental',
        'occurred_at' => '2026-10-20 10:00:00',
    ]);

    $this->actingAs($viewer)->get('/admin/office-tat?category=CENRO&processing_period=2026-H2&as_of_date=2026-10-21&unit=conservation&view=report-performance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/OfficeTat/Index')
            ->where('analytics.category', 'CENRO')
            ->where('recordedAssessment.module_coverage', function ($coverage): bool {
                $bms = collect($coverage)->firstWhere('source_key', 'bms');

                return $bms !== null && (int) ($bms['live_tat_samples'] ?? -1) === 1;
            }));
});
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

test('recorded Conservation excludes historical receipts from live counts, scores and rankings', function (): void {
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
    $liveRecord = automaticTatPaSource($area, $special, $specialQ4['label'], '2026-10-12', '2026-10-20');

    $service = app(OfficeTatComplianceAssessmentService::class);
    $before = $service->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-31');
    $beforePa = collect($before['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $historicalRecord = automaticTatPaSource($area, $special, $specialQ4['label'], '2026-10-12', null);
    $historicalRecord->forceFill(['is_historical' => true])->save();
    HistoricalReportOrigin::query()->create([
        'source' => 'conservation', 'source_record_id' => $historicalRecord->getKey(),
        'workflow_key' => 'special_pamb', 'encoded_by' => $actor->getKey(),
        'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        'original_milestones' => ['provenance' => 'manually_recorded_original_dates', 'milestones' => [
            'date_received_penro' => '2026-10-19',
        ]],
        'encoded_at' => now(),
    ]);
    $after = $service->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-31');
    $afterPa = collect($after['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $regularDetail = collect($afterPa['obligation_details'])->firstWhere('source_identity.workflow_key', 'regular_pamb');
    $specialDetail = collect($afterPa['obligation_details'])->firstWhere('source_identity.workflow_key', 'special_pamb');

    $metricSnapshot = fn (array $result, array $row): array => [
        'activity_count' => $result['activity_count'],
        'received_activity_count' => $result['received_activity_count'],
        'due_count' => $result['due_count'],
        'on_time_count' => $result['on_time_count'],
        'tc_percent' => $result['tc_percent'],
        'se_percent' => $result['se_percent'],
        'otps' => $result['otps'],
        'participant_due_count' => $row['due_count'],
        'participant_rank' => $row['rank'],
        'participant_rank_status' => $row['rank_status'],
    ];

    expect($first->getKey())->toBeInt()
        ->and($liveRecord->getKey())->not->toBe($historicalRecord->getKey())
        ->and($metricSnapshot($after, $afterPa))->toBe($metricSnapshot($before, $beforePa))
        ->and($after['activity_count'])->toBe(3)
        ->and($after['received_activity_count'])->toBe(2)
        ->and($after['incomplete_date_count'])->toBe(1)
        ->and($after['due_count'])->toBe(2)
        ->and($after['on_time_count'])->toBe(2)
        ->and($regularDetail['received'])->toBe('2026-08-10')
        ->and($regularDetail['source_rows_matched'])->toBe(2)
        ->and($specialDetail['received'])->toBe('2026-10-20')
        ->and($specialDetail['status'])->not->toBe('Conflicting historical receipt');
});

test('recorded Development excludes a historical-only source identity from live scores', function (): void {
    $actor = automaticTatGlobalViewer();
    $service = app(OfficeTatComplianceAssessmentService::class);
    $before = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-31');
    $beforeRow = collect($before['rows'])->firstWhere('name', 'CENRO Baganga');

    $historical = automaticTatEngpSource('CENRO Baganga', '2026-10', null);
    $historical->forceFill(['is_historical' => true])->save();
    HistoricalReportOrigin::query()->create([
        'source' => 'engp', 'source_record_id' => $historical->getKey(), 'workflow_key' => 'cbep',
        'encoded_by' => $actor->getKey(), 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        'original_milestones' => ['provenance' => 'manually_recorded_original_dates', 'milestones' => [
            'date_received_penro' => '2026-10-19',
        ]],
        'encoded_at' => now(),
    ]);

    $after = $service->recordedAssessments($actor, 'development', '2026-H2', '2026-10-31');
    $afterRow = collect($after['rows'])->firstWhere('name', 'CENRO Baganga');
    $snapshot = static fn (array $result, array $row): array => [
        $result['activity_count'], $result['received_activity_count'], $result['due_count'],
        $result['on_time_count'], $result['tc_percent'], $result['se_percent'], $result['otps'],
        $row['due_count'], $row['rank'], $row['rank_status'],
    ];

    expect($snapshot($after, $afterRow))->toBe($snapshot($before, $beforeRow))
        ->and($after['activity_count'])->toBe(0)
        ->and($after['received_activity_count'])->toBe(0)
        ->and($after['due_count'])->toBe(0)
        ->and($afterRow['obligation_details'])->toBe([])
        ->and($historical->getAttribute('is_historical'))->toBeTrue();
});

test('validated Restoration Plan reports resolve free-text titles through their canonical workflow and preserve future-due status', function (): void {
    $actor = automaticTatGlobalViewer();
    $actor->givePermissionTo(Permission::findOrCreate('technical-reports.create', 'web'));
    $area = automaticTatArea('Restoration Workflow Fixture');
    $officeId = OrganizationalOffice::query()->where('name', 'CENRO Baganga')->value('id');
    DB::table('protected_area_office_assignments')->insert([
        'protected_area_id' => $area->getKey(), 'organizational_office_id' => $officeId,
        'assignment_type' => 'supervising', 'assigned_by' => $actor->getKey(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($actor)->post(route('conservation-reports.store', 'restoration_plan_5_year'), [
        'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Restoration field progress update', 'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 4', 'date_conducted' => '2026-10-18',
        'date_accomplished' => '2026-10-18',
        'mov' => UploadedFile::fake()->create('restoration-progress.pdf', 12, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $record = ConservationReportSubmission::query()->latest('id')->firstOrFail();
    expect($record->workflow_key)->toBe('restoration_plan_5_year')
        ->and($record->activity_name)->toBe('Restoration field progress update')
        ->and($record->deadline_submission)->toBe('2026-10-28');

    $record->date_received_penro = '2026-10-20';
    $record->save();
    $record = $record->fresh();
    $recorded = app(OfficeTatComplianceAssessmentService::class)->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-21');
    $row = collect($recorded['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $detail = collect($row['obligation_details'])->firstWhere('source_reference', 'Conservation report #'.$record->getKey());

    expect($recorded['activity_count'])->toBe(1)
        ->and($recorded['received_activity_count'])->toBe(1)
        ->and($recorded['due_count'])->toBe(1)
        ->and($row['not_yet_due_count'])->toBe(1)
        ->and($detail)->not->toBeNull()
        ->and($detail['deadline'])->toBe('2026-10-28')
        ->and($detail['source_identity']['workflow_key'])->toBe('restoration_plan_5_year')
        ->and($detail['source_identity']['activity_name'])->toBe('Preparation of 5-Year Restoration Plan')
        ->and($detail['source_identity']['source_ids'])->toBe([$record->getKey()]);
});

test('registered Restoration Plan free-text title remains included in recorded counts', function (): void {
    $actor = automaticTatGlobalViewer();
    $area = automaticTatArea('Restoration Free Text Fixture');
    $record = ConservationReportSubmission::query()->create([
        'workflow_key' => 'restoration_plan_5_year', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Restoration field progress update',
        'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 4',
        'date_conducted' => '2026-10-18', 'date_accomplished' => '2026-10-18',
        'date_received_penro' => '2026-10-20',
    ]);

    $recorded = app(OfficeTatComplianceAssessmentService::class)->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-21');
    $row = collect($recorded['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $detail = collect($row['obligation_details'])->firstWhere('source_reference', 'Conservation report #'.$record->getKey());

    expect($recorded['activity_count'])->toBe(1)
        ->and($recorded['received_activity_count'])->toBe(1)
        ->and($recorded['due_count'])->toBe(1)
        ->and($row['not_yet_due_count'])->toBe(1)
        ->and($row['on_time_count'])->toBe(1)
        ->and($detail)->not->toBeNull()
        ->and($detail['source_identity']['workflow_key'])->toBe('restoration_plan_5_year')
        ->and($detail['source_identity']['activity_name'])->toBe('Preparation of 5-Year Restoration Plan')
        ->and($detail['due_status'])->toBe('received_before_deadline')
        ->and($detail['source_identity']['source_ids'])->toBe([$record->getKey()]);
});

test('ambiguous free-text Conservation identity stays unsupported without fuzzy matching', function (): void {
    $actor = automaticTatGlobalViewer();
    $area = automaticTatArea('Ambiguous CEPA Fixture');
    $record = ConservationReportSubmission::query()->create([
        'workflow_key' => 'cepa_plan', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Unclassified CEPA progress narrative',
        'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 4',
        'date_conducted' => '2026-10-18', 'date_accomplished' => '2026-10-18',
        'date_received_penro' => '2026-10-20',
    ]);

    $recorded = app(OfficeTatComplianceAssessmentService::class)->recordedAssessments($actor, 'conservation', '2026-H2', '2026-10-21');
    $row = collect($recorded['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $detail = collect($row['obligation_details'])->firstWhere('source_reference', 'Conservation report #'.$record->getKey());

    $later = app(OfficeTatComplianceAssessmentService::class)->recordedAssessments($actor, 'conservation', '2026-H2', '2026-12-31');
    $laterRow = collect($later['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $laterDetail = collect($laterRow['obligation_details'])->firstWhere('source_reference', 'Conservation report #'.$record->getKey());

    expect($detail)->not->toBeNull()
        ->and($detail['status'])->toBe('Unsupported report identity')
        ->and($detail['source_reference'])->toBe('Conservation report #'.$record->getKey())
        ->and($detail['source_identity']['activity_name'])->toBe('Unclassified CEPA progress narrative')
        ->and($detail['source_identity']['registered_activity_name'])->toBeNull()
        ->and($detail['received'])->toBe('2026-10-20')
        ->and($detail['deadline'])->toBeGreaterThan('2026-10-21')
        ->and($detail['due_as_of_date'])->toBe('2026-10-21')
        ->and($detail['due_status'])->toBe('not_yet_due')
        ->and($detail['issue'])->not->toContain('after selected date')
        ->and($row['activity_count'])->toBe(0)
        ->and($row['received_activity_count'])->toBe(0)
        ->and($row['due_count'])->toBe(0)
        ->and($laterDetail['status'])->toBe('Unsupported report identity')
        ->and($laterDetail['due_status'])->toBe('due')
        ->and($later['activity_count'])->toBe(0)
        ->and($later['received_activity_count'])->toBe(0)
        ->and($later['due_count'])->toBe(0);
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
    $scored = collect($afterRow['obligation_details'])->firstWhere('source_identity.workflow_key', 'elcac');

    expect($setup['created'])->toHaveCount(1)
        ->and($afterRow['activity_count'])->toBe(1)
        ->and($afterRow['received_activity_count'])->toBe(1)
        ->and($afterRow['due_count'])->toBe(1)
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
