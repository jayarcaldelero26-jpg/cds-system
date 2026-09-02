<?php

use App\Models\AuditLog;
use App\Models\ConservationReportSubmission;
use App\Models\NonWorkingDay;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach (['reports.view', 'technical-reports.update'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $this->user->assignRole(Role::findOrCreate('PENRO CDS Focal Person', 'web'));
});

afterEach(function (): void { CarbonImmutable::setTestNow(); });

function timelinePambReport(object $test, array $overrides = []): ConservationReportSubmission
{
    return ConservationReportSubmission::create(array_merge([
        'workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03', 'created_by' => $test->user->id, 'updated_by' => $test->user->id,
    ], $overrides));
}

function timelineService(): PambRoutingTimelineService { return app(PambRoutingTimelineService::class); }

function routeEvent(ConservationReportSubmission $report, string $stage, string $date, ?int $userId = null): PambRoutingEvent
{
    return timelineService()->record($report, $stage, $date.' 09:00:00', $userId, null);
}

function completeThrough(ConservationReportSubmission $report, string $date = '2026-08-05'): void
{
    routeEvent($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, $date);
    routeEvent($report, PambRoutingTimelineService::RECEIVED_BY_PENRO, $date);
}

test('detailed routing applies only to the three PAMB meeting workflows', function () {
    expect(timelineService()->applies(timelinePambReport($this, ['workflow_key' => 'regular_pamb'])))->toBeTrue()
        ->and(timelineService()->applies(timelinePambReport($this, ['workflow_key' => 'special_pamb'])))->toBeTrue()
        ->and(timelineService()->applies(timelinePambReport($this, ['workflow_key' => 'twc_meetings'])))->toBeTrue()
        ->and(timelineService()->applies(timelinePambReport($this, ['workflow_key' => 'updating_pamb_manual'])))->toBeFalse();
});

test('canonical receipt appears once and internal forward does not auto-create receipt', function () {
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    $before = timelineService()->present($report);
    expect($before['timeline'][1]['key'])->toBe(PambRoutingTimelineService::RECORDS_RECEIVED)
        ->and($before['timeline'][2]['key'])->toBe(PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO)
        ->and(PambRoutingEvent::count())->toBe(0);

    routeEvent($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-05');
    $after = timelineService()->present($report->fresh());
    expect(PambRoutingEvent::count())->toBe(1)
        ->and($after['current_document_location'])->toBe('For Receipt by Office of the PENRO')
        ->and(collect($after['timeline'])->firstWhere('key', PambRoutingTimelineService::RECEIVED_BY_PENRO)['status'])->toBe('current');
});

test('receipt is required before the next forward and same-day receipt is accepted', function () {
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    routeEvent($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-05');
    expect(fn () => routeEvent($report, PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-05'))->toThrow(ValidationException::class);
    routeEvent($report, PambRoutingTimelineService::RECEIVED_BY_PENRO, '2026-08-05');
    routeEvent($report, PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-05');
    expect(timelineService()->present($report->fresh())['current_document_location'])->toBe('For Receipt by TSD');
});

test('current location advances only when each receiving event exists', function () {
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    completeThrough($report);
    routeEvent($report, PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-06');
    expect(timelineService()->present($report->fresh())['current_document_location'])->toBe('For Receipt by TSD');
    routeEvent($report, PambRoutingTimelineService::RECEIVED_BY_TSD, '2026-08-06');
    expect(timelineService()->present($report->fresh())['current_document_location'])->toBe('TSD');
});

test('routing summary exposes the current owner, delay, next action and last event', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', BusinessCalendarService::TIMEZONE));
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    routeEvent($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-05', $this->user->id);

    $summary = timelineService()->present($report->fresh())['routing_summary'];

    expect($summary['current_location'])->toBe('For Receipt by Office of the PENRO')
        ->and($summary['current_status'])->toBe('Awaiting Receipt by Office of the PENRO')
        ->and($summary['responsible_office'])->toBe('Office of the PENRO')
        ->and($summary['pending_since'])->not->toBeNull()
        ->and($summary['working_days_pending'])->toBeInt()
        ->and($summary['next_expected_action'])->toBe('Record Receipt by Office of the PENRO')
        ->and($summary['last_action']['label'])->toBe('Forwarded to Office of the PENRO')
        ->and($summary['last_action']['recorded_by'])->toBe($this->user->name);
});

test('a complete detailed route retains the canonical regional endorsement event', function () {
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    completeThrough($report);
    foreach ([
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-06'], [PambRoutingTimelineService::RECEIVED_BY_TSD, '2026-08-06'],
        [PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, '2026-08-07'], [PambRoutingTimelineService::RECEIVED_BY_CDS, '2026-08-07'],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, '2026-08-07'], [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, '2026-08-07'],
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS, '2026-08-10'], [PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL, '2026-08-10'],
    ] as [$stage, $date]) routeEvent($report, $stage, $date);
    $report->update(['date_endorsed_regional' => '2026-08-10']);
    $timeline = timelineService()->present($report->fresh());
    expect($timeline['current_document_location'])->toBe('Regional Office')
        ->and(collect($timeline['timeline'])->last()['key'])->toBe(PambRoutingTimelineService::RELEASED_TO_REGIONAL)
        ->and(collect($timeline['timeline'])->last()['status'])->toBe('completed');
});

test('receipt and office processing delays use the PAMB calendar', function () {
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-03', 'date_received_penro' => '2026-08-03']);
    routeEvent($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-05');
    routeEvent($report, PambRoutingTimelineService::RECEIVED_BY_PENRO, '2026-08-06');
    routeEvent($report, PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-06');
    $items = collect(timelineService()->present($report->fresh(), CarbonImmutable::parse('2026-08-10', BusinessCalendarService::TIMEZONE))['timeline'])->keyBy('key');
    expect($items[PambRoutingTimelineService::RECEIVED_BY_PENRO]['elapsed_working_days'])->toBe(1)
        ->and($items[PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD]['elapsed_working_days'])->toBe(0)
        ->and($items[PambRoutingTimelineService::RECEIVED_BY_TSD]['pending_working_days'])->toBe(1);
});

test('Friday, Saturday, Sunday and active configured non-working days are excluded', function () {
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-06', 'date_received_penro' => '2026-08-06']);
    routeEvent($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-10');
    $items = collect(timelineService()->present($report->fresh())['timeline'])->keyBy('key');
    expect($items[PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO]['elapsed_working_days'])->toBe(1);
    NonWorkingDay::create(['date' => '2026-08-10', 'name' => 'Configured test day', 'type' => NonWorkingDay::TYPE_OTHER, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'location' => '', 'is_active' => true, 'created_by' => $this->user->id]);
    $other = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-06', 'date_received_penro' => '2026-08-06']);
    routeEvent($other, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-10');
    expect(collect(timelineService()->present($other->fresh())['timeline'])->keyBy('key')[PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO]['elapsed_working_days'])->toBe(0);
});

test('PENRO-managed PAMB skips CENRO without creating a fake event', function () {
    $area = ProtectedArea::create(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
    $report = timelinePambReport($this, ['protected_area_id' => $area->id, 'target_office' => 'PENRO Mati']);
    $timeline = timelineService()->present($report);
    expect(collect($timeline['timeline'])->first()['key'])->toBe(PambRoutingTimelineService::RECORDS_RECEIVED)
        ->and($timeline['current_document_location'])->toBe('Awaiting PENRO Receipt')
        ->and($timeline['current_processing_status'])->toBe('Awaiting PENRO Receipt')
        ->and($timeline['routing_summary']['next_expected_action'])->toBe('Record PENRO Receipt')
        ->and($report->date_report_released_cenro)->toBeNull();
});

test('normal routing endpoint ignores client timestamp and records actor once', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 14:30:00', BusinessCalendarService::TIMEZONE));
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    $this->actingAs($this->user)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, 'occurred_at' => '2020-01-01 00:00:00', 'remarks' => 'Slip 123'])->assertSessionHasNoErrors();
    $event = $report->fresh()->routingEvents()->first();
    expect($event->occurred_at->toDateTimeString())->toBe('2026-08-10 14:30:00')
        ->and($event->recorded_by)->toBe($this->user->id)
        ->and(AuditLog::query()->where('action', 'PAMB Internal Routing Event Recorded')->where('entity_id', (string) $report->id)->count())->toBe(1);
});

test('Super Admin can execute each valid PENRO stage without skipping the order', function () {
    $admin = User::factory()->create(['section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04']);
    $this->actingAs($admin);

    $this->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-05'])->assertSessionHasNoErrors();
    foreach ([
        PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        PambRoutingTimelineService::RECEIVED_BY_PENRO,
        PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD,
        PambRoutingTimelineService::RECEIVED_BY_TSD,
        PambRoutingTimelineService::FORWARDED_TSD_TO_CDS,
        PambRoutingTimelineService::RECEIVED_BY_CDS,
        PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
        PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL,
        PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS,
        PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL,
    ] as $stage) {
        $this->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $stage]), ['stage' => $stage])->assertSessionHasNoErrors();
    }

    $this->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-10'])->assertSessionHasNoErrors();
    expect(timelineService()->present($report->fresh())['routing_summary']['next_expected_action'])->toBe('No further routing action');
});

test('an authorized CENRO focal can load the Regular PAMB page through the HTTP route', function () {
    $this->user->update(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => 'conservation', 'office_designated' => 'CENRO Mati']);
    $this->user->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    timelinePambReport($this);

    $this->actingAs($this->user)->get('/conservation-reports/regular_pamb')->assertOk();
});

test('internal routing does not change compliance values or notifications', function () {
    $report = timelinePambReport($this, ['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    $before = [$report->deadline_submission, $report->days_complied, $report->timeliness, $report->submission_status];
    routeEvent($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-05', $this->user->id);
    $after = $report->fresh();
    expect([$after->deadline_submission, $after->days_complied, $after->timeliness, $after->submission_status])->toBe($before)
        ->and($this->user->unreadNotifications()->count())->toBe(0);
});
