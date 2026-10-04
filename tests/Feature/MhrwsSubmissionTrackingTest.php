<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Compliance\OverdueReportService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    $this->mhrws = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $this->normalArea = ProtectedArea::create([
        'name' => 'Aliwagwag Protected Landscape',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
});

function mhrwsConservationReport(object $test, array $overrides = []): ConservationReportSubmission
{
    $data = array_merge([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $test->mhrws->id,
        'target_office' => 'PENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'created_by' => $test->user->id,
        'updated_by' => $test->user->id,
    ], $overrides);
    if (array_key_exists('date_accomplished', $overrides) && ! array_key_exists('date_conducted', $overrides)) {
        $data['date_conducted'] = $data['date_accomplished'];
    }

    return ConservationReportSubmission::create($data);
}

function mhrwsRoutingActor(string $category, string $office): User
{
    $actor = User::factory()->create([
        'section' => $category,
        'unit_assignment' => 'conservation',
        'office_designated' => $office,
        'is_active' => true,
        'is_approved' => true,
    ]);
    $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('reports.view', 'web'));
    $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('technical-reports.update', 'web'));

    return $actor;
}

test('MHRWS reports bypass CENRO and enter PENRO receipt directly', function () {
    $report = mhrwsConservationReport($this);
    $penroRecords = mhrwsRoutingActor('PENRO_RECORDS', 'PENRO Davao Oriental');
    $this->actingAs($penroRecords);
    $record = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    $queues = app(SubmissionTrackingService::class)->queues();

    expect($record['submission_origin'])->toBe('PENRO')
        ->and($record['cenro_release_applicable'])->toBeFalse()
        ->and($record['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and($queues)->not->toHaveKey(SubmissionTrackingService::CENRO_RELEASE)
        ->and($queues[SubmissionTrackingService::PENRO_RECEIPT]->pluck('source_id'))->toContain($report->id);
});

test('normal protected-area reports still enter CENRO release first', function () {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $this->normalArea->id,
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    expect(app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id)['stage'])
        ->toBe(DocumentRoutingProfileRegistry::PREPARATION);
});

test('MHRWS aliases and seeded display names resolve to the same PENRO origin', function () {
    $variant = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $report = mhrwsConservationReport($this, ['protected_area_id' => $variant->id]);

    expect(app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id)['stage'])
        ->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
});

test('MHRWS receipt advances to endorsement and then history without requiring a CENRO date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 14:32:00', 'Asia/Manila'));
    $report = mhrwsConservationReport($this);
    $records = mhrwsRoutingActor('PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = mhrwsRoutingActor('OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $tsd = mhrwsRoutingActor('PENRO_TSD_CHIEF', 'PENRO Davao Oriental');
    $focal = mhrwsRoutingActor('PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $chief = mhrwsRoutingActor('PENRO_CDS_CHIEF', 'PENRO Davao Oriental');
    $tracking = app(SubmissionTrackingService::class);

    $this->actingAs($records);
    app(DocumentRoutingTransitionService::class)->transition($report, 'conservation', 'receive_at_penro_records', $records->id);
    $report->refresh();
    expect($report->date_report_released_cenro)->toBeNull()
        ->and($tracking->queues()[SubmissionTrackingService::PENRO_RECEIPT]->pluck('source_id'))->not->toContain($report->id)
        ->and($tracking->queues()['penro_records_routing']->pluck('source_id'))->toContain($report->id)
        ->and($tracking->queues()['history']->where('source', 'conservation')->pluck('source_id'))->not->toContain($report->id);

    $routing = app(DocumentRoutingTransitionService::class);
    foreach ([
        [$records, 'forward_to_office_penro'], [$office, 'receive_at_office_penro'],
        [$office, 'assign_to_tsd_chief'], [$tsd, 'receive_at_tsd_chief'],
        [$tsd, 'forward_to_cds_focal'], [$focal, 'receive_at_cds_focal'],
        [$focal, 'forward_to_cds_chief'], [$chief, 'receive_at_cds_chief'],
        [$chief, 'recommend_to_office_penro'], [$office, 'receive_at_office_penro_final'],
        [$office, 'approve_for_regional_release'], [$records, 'receive_at_penro_records_final'],
        [$records, 'release_to_regional'],
    ] as [$actor, $action]) {
        $routing->transition($report->fresh(), 'conservation', $action, $actor->id);
    }
    $this->actingAs($records);
    $regionalEvent = DocumentRoutingEvent::query()
        ->where('source_type', 'conservation')->where('source_id', $report->id)
        ->where('event_key', 'released')->where('to_stage', DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->firstOrFail();
    $history = $tracking->queues()['history']->where('source', 'conservation');
    expect($history->pluck('source_id'))->toContain($report->id)
        ->and($report->fresh()->date_endorsed_regional->toDateString())->toBe('2026-08-29')
        ->and($regionalEvent->occurred_at->toDateTimeString())->toBe('2026-08-29 14:32:00')
        ->and($history->firstWhere('source_id', $report->id)['completed_at'])->toBe('2026-08-29T14:32:00+08:00');
});

test('the MHRWS routing rule applies to another protected-area report source', function () {
    $report = BmsReportSubmission::create([
        'protected_area_id' => $this->mhrws->id,
        'target_office' => 'PENRO Mati',
        'activity_name' => 'BMS Report',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
    ]);

    $record = app(SubmissionTrackingService::class)->records()->firstWhere(fn (array $item): bool => $item['source'] === 'bms' && $item['source_id'] === $report->id);
    expect($record['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and($record['cenro_release_applicable'])->toBeFalse();
});

test('MHRWS overdue alerts remain active until PENRO receipt and then close', function () {
    $report = mhrwsConservationReport($this, ['date_accomplished' => '2026-07-01']);
    $alerts = app(OverdueReportService::class);
    $today = CarbonImmutable::parse('2026-08-28', 'Asia/Manila');

    expect($alerts->overdueReports($today)->firstWhere('sourceId', $report->id))->not->toBeNull();

    $report->update(['date_report_released_cenro' => '2026-07-10']);
    expect($alerts->overdueReports($today)->firstWhere('sourceId', $report->id))->not->toBeNull();

    $report->update(['date_received_penro' => '2026-07-15']);
    expect($alerts->overdueReports($today)
        ->first(fn ($alert): bool => $alert->sourceId === $report->id && $alert->complianceIssue === 'Report Not Yet Submitted'))
        ->toBeNull();
});
