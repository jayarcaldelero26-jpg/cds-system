<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Compliance\OverdueReportService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach (['reports.view', 'technical-reports.update'] as $ability) $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
});

test('an accomplished conservation report enters the CENRO release queue and transitions through routing without duplication', function () {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1', 'date_accomplished' => '2026-08-03', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'activity_name' => 'Regular PAMB', 'date_accomplished' => null, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);

    $this->actingAs($this->user)->get(route('submission-tracking.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('SubmissionTracking/Index')->count('queues.cenro_release', 1));
    $this->actingAs($this->user)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE]), ['stage' => SubmissionTrackingService::CENRO_RELEASE, 'date' => '2026-08-04'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('conservation_report_submissions', ['id' => $report->id, 'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => null]);
    $this->actingAs($this->user)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-06'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('conservation_report_submissions', ['id' => $report->id, 'date_received_penro' => '2026-08-06']);
    $this->actingAs($this->user)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-07'])->assertSessionHasNoErrors();
    expect(ConservationReportSubmission::count())->toBe(2);
});

test('submission tracking rejects impossible receipt and endorsement chronology', function () {
    $report = ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'activity_name' => 'Regular PAMB', 'date_accomplished' => '2026-08-03', 'date_report_released_cenro' => '2026-08-05', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);

    $this->actingAs($this->user)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-04'])->assertSessionHasErrors('date');
    $report->update(['date_received_penro' => '2026-08-06']);
    $this->actingAs($this->user)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-04'])->assertSessionHasErrors('date');
});

test('generic conservation reports remain live in Alerts until PENRO receipt while they advance through submission tracking', function () {
    Storage::fake('public');
    $tracking = app(SubmissionTrackingService::class);
    $alerts = app(OverdueReportService::class);
    $today = CarbonImmutable::parse('2026-08-25', 'Asia/Manila');

    $future = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_accomplished' => '2026-08-24', 'mov_file_path' => 'conservation-report-movs/future.pdf', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    Storage::disk('public')->put($future->mov_file_path, 'future MOV');

    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 2', 'date_accomplished' => '2026-08-03', 'mov_file_path' => 'conservation-report-movs/overdue.pdf', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    Storage::disk('public')->put($report->mov_file_path, 'overdue MOV');

    $cenroQueue = $tracking->queues()[SubmissionTrackingService::CENRO_RELEASE];
    expect($cenroQueue->pluck('source_id'))->toContain($future->id, $report->id)
        ->and($alerts->overdueReports($today)->firstWhere('sourceId', $future->id))->toBeNull();

    $overdue = $alerts->overdueReports($today)->firstWhere('sourceId', $report->id);
    expect($overdue)->not->toBeNull()
        ->and($overdue->module)->toBe('Regular PAMB Meetings')
        ->and($overdue->deadline)->toBe($report->deadline_submission)
        ->and($overdue->complianceIssue)->toBe('Report Not Yet Submitted');

    $tracking->transition('conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE, '2026-08-14', $this->user->id);
    expect($tracking->queues()[SubmissionTrackingService::PENRO_RECEIPT]->pluck('source_id'))->toContain($report->id)
        ->and($alerts->overdueReports($today)->firstWhere('sourceId', $report->id))->not->toBeNull();

    $tracking->transition('conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT, '2026-08-18', $this->user->id);
    expect($tracking->queues()[SubmissionTrackingService::REGIONAL_ENDORSEMENT]->pluck('source_id'))->toContain($report->id)
        ->and($alerts->overdueReports($today)->firstWhere('sourceId', $report->id))->toBeNull();

    $tracking->transition('conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT, '2026-08-19', $this->user->id);
    expect($tracking->records()->firstWhere('source_id', $report->id)['stage'])->toBe('endorsed')
        ->and($alerts->overdueReports($today)->firstWhere('sourceId', $report->id))->toBeNull();
});

test('Alerts consumes each generic conservation workflow deadline from its live report record', function (string $workflowKey, string $activity, string $document, string $expectedDeadline) {
    $report = ConservationReportSubmission::create([
        'workflow_key' => $workflowKey, 'target_office' => 'CENRO Mati', 'activity_name' => $activity, 'document_type' => $document, 'reporting_period' => $workflowKey === 'additional_bms_site' ? '1st Semester' : 'Quarter 3', 'date_accomplished' => '2026-08-26', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $alert = app(OverdueReportService::class)->overdueReports(CarbonImmutable::parse('2026-09-23', 'Asia/Manila'))->firstWhere('sourceId', $report->id);

    expect($report->deadline_submission)->toBe($expectedDeadline)
        ->and($alert)->not->toBeNull()
        ->and($alert->deadline)->toBe($report->deadline_submission)
        ->and($alert->module)->toBe(app(ConservationReportWorkflowRegistry::class)->find($workflowKey)['label']);
})->with([
    ['regular_pamb', 'Regular PAMB', 'Minutes', '2026-09-08'],
    ['additional_bms_site', 'Establishment of additional BMS site (Davao de Oro)', 'Progress Report', '2026-09-22'],
    ['cepa_plan', 'CEPA Plan preparation (Analysis/Stocktaking)', 'Progress Report', '2026-09-08'],
    ['cepa_plan', 'Submission of Final CEPA Plan', 'Final Report', '2026-09-22'],
    ['monitoring_mangroves_corals_seagrass', 'Monitoring of Habitat condition (Mangroves - 1st Q)', 'Report', '2026-09-22'],
]);

test('one live Conservation Alerts source definition covers every configured generic conservation workflow', function () {
    $definitions = app(OverdueReportService::class)->sourceDefinitions();
    $workflows = app(ConservationReportWorkflowRegistry::class)->all();

    expect($definitions)->toHaveKey(ConservationReportSubmission::class)
        ->and(collect(array_keys($definitions))->filter(fn (string $source): bool => $source === ConservationReportSubmission::class))->toHaveCount(1)
        ->and($workflows)->toHaveCount(22)
        ->and(collect($workflows)->pluck('label')->filter()->unique())->toHaveCount(22);
});

test('history uses authoritative ENGP period labels and clean non-applicable fields', function () {
    $report = EngpReportSubmission::create([
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'ENGP Site Visit Report', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'Q1', 'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10', 'date_received_penro' => '2026-03-11',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $report->releaseEvents()->createMany([
        ['period_component' => '2026-01', 'component_label' => 'January', 'date_report_released_cenro' => '2026-01-10'],
        ['period_component' => '2026-02', 'component_label' => 'February', 'date_report_released_cenro' => '2026-02-10'],
        ['period_component' => '2026-03', 'component_label' => 'March', 'date_report_released_cenro' => '2026-03-10'],
    ]);

    $row = app(SubmissionTrackingService::class)->queues()['history']->firstWhere('source_id', $report->id);

    expect($row)->not->toBeNull()
        ->and($row['module'])->toBe('Site Visit')
        ->and($row['target_office'])->toBe('CENRO Baganga')
        ->and($row['reporting_period'])->toBe('Quarter 1')
        ->and($row['protected_area'])->toBeNull()
        ->and($row['date_accomplished'])->toBeNull()
        ->and($row['stage'])->toBe('endorsed');
});

test('history preserves Conservation reporting period, protected area, and accomplished date', function () {
    $area = \App\Models\ProtectedArea::create([
        'name' => 'Aliwagwag Protected Landscape', 'short_name' => 'APL', 'category' => 'Protected Landscape',
        'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 2',
        'date_accomplished' => '2026-08-03', 'date_report_released_cenro' => '2026-08-04',
        'date_received_penro' => '2026-08-06', 'date_endorsed_regional' => '2026-08-07',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $row = app(SubmissionTrackingService::class)->queues()['history']->firstWhere('source_id', $report->id);

    expect($row['protected_area'])->toBe('Aliwagwag Protected Landscape')
        ->and($row['reporting_period'])->toBe('Quarter 2')
        ->and($row['date_accomplished'])->toBe('2026-08-03');
});
