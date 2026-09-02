<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Notifications\EdatsInAppNotification;
use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\Compliance\OverdueReportService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00', 'Asia/Manila'));
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach (['reports.view', 'technical-reports.update'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('unread count, read state, and mark all as read are user-specific', function () {
    $this->user->notify(new EdatsInAppNotification(notificationPayload('first')));
    $this->user->notify(new EdatsInAppNotification(notificationPayload('second')));
    $this->user->notifications()->first()->markAsRead();

    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertOk()
        ->assertJsonPath('unread_count', 1);
    $this->actingAs($this->user)->post(route('notifications.read-all'))->assertRedirect();

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('authenticated user can mark their own notification as read', function () {
    $this->user->notify(new EdatsInAppNotification(notificationPayload('mark-one')));
    $notification = $this->user->notifications()->first();

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->patch(route('notifications.read', $notification))
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('unauthenticated users cannot mark notifications as read', function () {
    $this->user->notify(new EdatsInAppNotification(notificationPayload('guest-read')));
    $notification = $this->user->notifications()->first();

    $this->patch(route('notifications.read', $notification))->assertRedirect(route('login'));
    expect($notification->fresh()->read_at)->toBeNull();
});

test('overdue and due soon in-app notifications are derived once from the live alert source', function () {
    notificationConservationReport(notificationProtectedArea('Pujada Bay Protected Landscape', $this->user), $this->user, ['date_accomplished' => '2026-08-03']);
    notificationEngpReport($this->user, ['deadline_submission' => '2026-09-01']);
    $service = app(EdatsInAppNotificationService::class);
    $today = CarbonImmutable::parse('2026-08-29', 'Asia/Manila');

    $service->syncDeadlineNotifications($today);
    $service->syncDeadlineNotifications($today);

    $types = $this->user->notifications()->get()->pluck('data')->pluck('type');
    expect($types)->toContain(EdatsInAppNotificationService::OVERDUE, EdatsInAppNotificationService::DUE_SOON)
        ->and($this->user->notifications()->count())->toBe(2);
});

test('submission tracking records routing dates without creating bell notifications', function () {
    $report = notificationConservationReport(notificationProtectedArea('Pujada Bay Protected Landscape', $this->user), $this->user);
    $tracking = app(SubmissionTrackingService::class);

    $tracking->transition('conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE, '2026-08-10', $this->user->id);
    $tracking->transition('conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT, '2026-08-11', $this->user->id);
    $tracking->transition('conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT, '2026-08-12', $this->user->id);

    $types = $this->user->notifications()->get()->pluck('data')->pluck('type');
    expect($types)->not->toContain('cenro_released', 'penro_received', 'for_regional_endorsement', 'region_endorsed')
        ->and($report->fresh()->date_endorsed_regional?->toDateString())->toBe('2026-08-12');
});

test('MHRWS bypasses CENRO routing and ENGP never generates regional endorsement routing', function () {
    $mhrws = notificationConservationReport(notificationProtectedArea('Mt. Hamiguitan Range Wildlife Sanctuary', $this->user, 'MHRWS'), $this->user);
    $engp = notificationEngpReport($this->user, ['workflow_key' => 'cbep']);
    $tracking = app(SubmissionTrackingService::class);

    $tracking->transition('conservation', $mhrws->id, SubmissionTrackingService::PENRO_RECEIPT, '2026-08-10', $this->user->id);
    $tracking->transition('engp', $engp->id, SubmissionTrackingService::CENRO_RELEASE, '2026-08-10', $this->user->id);
    $tracking->transition('engp', $engp->id, SubmissionTrackingService::PENRO_RECEIPT, '2026-08-11', $this->user->id);

    expect($this->user->notifications()->count())->toBe(0)
        ->and($tracking->queues()[SubmissionTrackingService::CENRO_RELEASE]->where('source_id', $mhrws->id))->toBeEmpty()
        ->and($engp->fresh()->date_received_penro?->toDateString())->toBe('2026-08-11');
});

test('notification routes do not allow a user to read another users notification', function () {
    $other = User::factory()->create(['section' => 'CDS']);
    $other->notify(new EdatsInAppNotification(notificationPayload('private')));
    $notification = $other->notifications()->first();

    $this->actingAs($this->user)->patch(route('notifications.read', $notification))->assertNotFound();
});

test('bell shows only unread three-day and overdue alerts and clear preserves notification history', function () {
    $this->user->notify(new EdatsInAppNotification([
        'type' => EdatsInAppNotificationService::DUE_SOON,
        'dedup_key' => 'due-soon-clear-test',
        'title' => '3-Day Reminder',
        'message' => 'A report is due on Sep 1, 2026.',
        'severity' => 'warning',
        'category' => 'due_soon',
    ]));
    $this->user->notify(new EdatsInAppNotification([
        'type' => 'submission_updates',
        'dedup_key' => 'routing-clear-test',
        'title' => 'Report Received by PENRO',
        'message' => 'Routing event.',
        'severity' => 'info',
        'category' => 'submission_updates',
    ]));

    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertJsonPath('unread_count', 1)
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', '3-Day Reminder');

    $this->actingAs($this->user)->withHeader('Accept', 'application/json')
        ->post(route('notifications.clear'))
        ->assertOk()
        ->assertJson(['ok' => true, 'unread_count' => 0, 'notifications' => []]);

    expect($this->user->fresh()->notifications()->count())->toBe(2)
        ->and($this->user->fresh()->unreadNotifications()->count())->toBe(1);
});

test('a future compliance alert can appear after clear', function () {
    $this->user->notify(new EdatsInAppNotification([
        'type' => EdatsInAppNotificationService::DUE_SOON,
        'dedup_key' => 'old-alert',
        'title' => '3-Day Reminder',
        'message' => 'Old report.',
        'severity' => 'warning',
        'category' => 'due_soon',
    ]));

    $this->actingAs($this->user)->withHeader('Accept', 'application/json')->post(route('notifications.clear'))->assertOk();

    $this->user->notify(new EdatsInAppNotification([
        'type' => EdatsInAppNotificationService::OVERDUE,
        'dedup_key' => 'new-alert',
        'title' => 'Overdue Report',
        'message' => 'New overdue report.',
        'severity' => 'danger',
        'category' => 'overdue',
    ]));

    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('notifications.0.title', 'Overdue Report');
});

test('ENGP overdue reports use the same live Alerts source and active IMEA Maintenance remains included', function () {
    $engp = notificationEngpReport($this->user, ['deadline_submission' => '2026-08-20']);
    app(EdatsInAppNotificationService::class)->syncDeadlineNotifications(CarbonImmutable::parse('2026-08-29', 'Asia/Manila'));

    $notification = $this->user->notifications()->first();
    expect($notification->data['type'])->toBe(EdatsInAppNotificationService::OVERDUE)
        ->and($notification->data['source_type'])->toBe(EngpReportSubmission::class)
        ->and(app(OverdueReportService::class)->sourceDefinitions())->toHaveKey(ImeaFacilityMaintenanceReport::class)
        ->and($engp->date_received_penro)->toBeNull();
});

function notificationPayload(string $key): array
{
    return ['type' => EdatsInAppNotificationService::DUE_SOON, 'dedup_key' => $key, 'title' => 'Test notification', 'message' => 'Test message', 'severity' => 'warning', 'category' => 'due_soon', 'source_label' => 'Test Report', 'url' => route('dashboard')];
}

function notificationProtectedArea(string $name, User $user, ?string $shortName = null): ProtectedArea
{
    return ProtectedArea::create(['name' => $name, 'short_name' => $shortName, 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $user->id, 'updated_by' => $user->id]);
}

function notificationConservationReport(ProtectedArea $area, User $user, array $overrides = []): ConservationReportSubmission
{
    $data = array_merge(['workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental', 'activity_name' => 'Regular PAMB Meetings', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03', 'created_by' => $user->id, 'updated_by' => $user->id], $overrides);
    if (array_key_exists('date_accomplished', $overrides) && ! array_key_exists('date_conducted', $overrides)) {
        $data['date_conducted'] = $data['date_accomplished'];
    }

    return ConservationReportSubmission::create($data);
}

function notificationEngpReport(User $user, array $overrides = []): EngpReportSubmission
{
    return EngpReportSubmission::create(array_merge(['workflow_key' => 'cbep', 'office' => 'CENRO Mati', 'section_name' => 'NGP', 'activity_name' => 'Community-Based Employment Program (CBEP)', 'document_type' => 'Monthly Report', 'reporting_year' => 2026, 'period_key' => '2026-08', 'period_label' => 'August 2026', 'deadline_submission' => '2026-09-01', 'created_by' => $user->id, 'updated_by' => $user->id], $overrides));
}
