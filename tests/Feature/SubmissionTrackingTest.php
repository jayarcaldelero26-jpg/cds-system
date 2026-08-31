<?php

use App\Models\ConservationReportSubmission;
use App\Models\ModuleDefinition;
use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Compliance\OverdueReportService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach (['reports.view', 'technical-reports.update'] as $ability) $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
});

test('routing status is normalized across each required stage', function () {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Status contract', 'date_accomplished' => '2026-08-03',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    expect($report->submission_status)->toBe('Pending Submission by CENRO');
    $report->update(['date_report_released_cenro' => '2026-08-04']);
    expect($report->fresh()->submission_status)->toBe('Pending Receipt by PENRO');
    $report->update(['date_received_penro' => '2026-08-05']);
    expect($report->fresh()->submission_status)->toBe('Pending Regional Endorsement');
    $report->update(['date_endorsed_regional' => '2026-08-06']);
    expect($report->fresh()->submission_status)->toBe('Completed');
});

test('submission status overview exposes canonical module and program area metadata', function () {
    $homestay = ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Training on Homestay Program',
        'date_accomplished' => '2026-08-03', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $pamb = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Regular PAMB',
        'date_accomplished' => '2026-08-03', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $dynamic = ModuleDefinition::query()->create([
        'name' => 'Dynamic Status Overview Module', 'code' => 'dynamic_status_overview',
        'program_area' => \App\Domain\Modules\ProgramArea::DEVELOPMENT->value,
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET, 'reporting_frequency' => 'quarterly',
        'deadline_mode' => ModuleDefinition::DEADLINE_NONE, 'is_active' => true,
    ]);
    $dynamicRecord = ConservationReportSubmission::create([
        'workflow_key' => $dynamic->code, 'activity_name' => 'Dynamic activity',
        'date_accomplished' => '2026-08-03', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $siteVisit = EngpReportSubmission::create([
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'ENGP Site Visit Report', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'Q1', 'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-09-01', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $unknown = ConservationReportSubmission::create([
        'workflow_key' => 'unknown_overview_workflow', 'activity_name' => 'Unknown activity',
        'date_accomplished' => '2026-08-03', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $rows = app(SubmissionTrackingService::class)->records();
    $row = fn (string $source, int $id): array => $rows->first(fn (array $item): bool => $item['source'] === $source && (int) $item['source_id'] === $id);

    expect($row('conservation', $homestay->id)['module_name'])->toBe('Homestay')
        ->and($row('conservation', $homestay->id)['program_area'])->toBe('Protected Area Management and Development')
        ->and($row('conservation', $pamb->id)['module_name'])->toBe('Regular PAMB Meetings')
        ->and($row('conservation', $dynamicRecord->id)['module_name'])->toBe('Dynamic Status Overview Module')
        ->and($row('conservation', $dynamicRecord->id)['program_area'])->toBe('Development')
        ->and($row('engp', $siteVisit->id)['module_name'])->toBe('Site Visit')
        ->and($row('engp', $siteVisit->id)['program_area'])->toBe('National Greening Program')
        ->and($row('conservation', $unknown->id)['module_name'])->toBe('Conservation Report');

    $this->actingAs($this->user)->get(route('submission-tracking.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('queues.cenro_release', fn ($queue): bool => collect($queue)->contains(fn (array $item): bool => $item['source'] === 'conservation' && $item['source_id'] === $homestay->id && $item['module_name'] === 'Homestay'))
        ->where('queues.cenro_release', fn ($queue): bool => collect($queue)->contains(fn (array $item): bool => $item['source'] === 'conservation' && $item['source_id'] === $pamb->id && $item['module_name'] === 'Regular PAMB Meetings'))
        ->where('queues.cenro_release', fn ($queue): bool => collect($queue)->contains(fn (array $item): bool => $item['source'] === 'engp' && $item['source_id'] === $siteVisit->id && $item['module_name'] === 'Site Visit'))
    );
});

test('CDS Admin can correct routing dates and every correction is retained', function () {
    $admin = User::factory()->create(['password' => 'secret-password', 'section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Correction test', 'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $payload = ['dates' => ['date_report_released_cenro' => '2026-08-06', 'date_received_penro' => '2026-08-07'], 'reason' => 'Incorrect dates entered during encoding.', 'password' => 'secret-password'];
    $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['conservation', $report->id]), $payload)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('conservation_report_submissions', ['id' => $report->id, 'date_report_released_cenro' => '2026-08-06', 'date_received_penro' => '2026-08-07']);
    $this->assertDatabaseHas('submission_routing_corrections', ['source' => 'conservation', 'source_id' => $report->id, 'field' => 'date_report_released_cenro', 'reason' => 'Incorrect dates entered during encoding.', 'corrected_by' => $admin->id]);

    $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['conservation', $report->id]), [...$payload, 'dates' => ['date_received_penro' => '2026-08-08'], 'reason' => 'Second correction for the receipt date.'])->assertSessionHasNoErrors();
    expect(\App\Models\SubmissionRoutingCorrection::query()->where('source_id', $report->id)->count())->toBe(3);
    expect($report->fresh()->submission_status)->toBe('Pending Regional Endorsement');
});

test('routing correction rejects wrong password, non-admins, missing reason, and invalid chronology', function () {
    $admin = User::factory()->create(['password' => 'secret-password', 'section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Correction validation', 'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $route = route('submission-tracking.correct-routing', ['conservation', $report->id]);
    $dates = ['date_report_released_cenro' => '2026-08-06', 'date_received_penro' => '2026-08-07'];

    $this->actingAs($admin)->patch($route, ['dates' => $dates, 'reason' => 'Wrong password check.', 'password' => 'wrong'])->assertSessionHasErrors('password');
    $this->actingAs($admin)->patch($route, ['dates' => $dates, 'reason' => '', 'password' => 'secret-password'])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->patch($route, ['dates' => ['date_report_released_cenro' => '2026-08-09', 'date_received_penro' => '2026-08-07'], 'reason' => 'Chronology check.', 'password' => 'secret-password'])->assertSessionHasErrors('dates');

    $staff = User::factory()->create(['section' => 'CDS']);
    $staff->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));
    $this->actingAs($staff)->patch($route, ['dates' => $dates, 'reason' => 'Unauthorized check.', 'password' => 'secret-password'])->assertForbidden();
    expect(\App\Models\SubmissionRoutingCorrection::query()->count())->toBe(0);
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

test('History contains each completed routing workflow once and excludes intermediate active stages', function () {
    $released = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Released only', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => '2026-08-02', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $received = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Received only', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => '2026-08-02', 'date_received_penro' => '2026-08-03', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $completedEarlier = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Completed earlier', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => '2026-08-02', 'date_received_penro' => '2026-08-03', 'date_endorsed_regional' => '2026-08-04', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $completedLater = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Completed later', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => '2026-08-02', 'date_received_penro' => '2026-08-03', 'date_endorsed_regional' => '2026-08-05', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $queues = app(SubmissionTrackingService::class)->queues();
    $history = $queues['history']->where('source', 'conservation')->values();

    expect($queues[SubmissionTrackingService::PENRO_RECEIPT]->pluck('source_id'))->toContain($released->id)
        ->and($queues[SubmissionTrackingService::REGIONAL_ENDORSEMENT]->pluck('source_id'))->toContain($received->id)
        ->and($history->pluck('source_id')->all())->toBe([$completedLater->id, $completedEarlier->id])
        ->and($history->pluck('source_id'))->not->toContain($released->id, $received->id)
        ->and($history->firstWhere('source_id', $completedLater->id)['completed_at'])->toBe('2026-08-05')
        ->and($history->where('source_id', $completedLater->id))->toHaveCount(1);
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
        ->and($row['stage'])->toBe('endorsed')
        ->and($row['completed_at'])->toBe('2026-03-11');
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
