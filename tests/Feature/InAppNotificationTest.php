<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\BamsReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\Aws;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Notifications\EdatsInAppNotification;
use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\Compliance\OverdueReportService;
use App\Services\Authorization\OrganizationalAccessService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

test('marking an owned notification read immediately updates the bell unread count and persists on reload', function () {
    $this->user->notify(new EdatsInAppNotification(notificationPayload('badge-first')));
    $this->user->notify(new EdatsInAppNotification(notificationPayload('badge-second')));
    $notification = $this->user->notifications()->where('data->dedup_key', 'badge-first')->firstOrFail();

    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertOk()
        ->assertJsonPath('unread_count', 2);

    $this->actingAs($this->user)->withHeader('Accept', 'application/json')
        ->patch(route('notifications.read', $notification))
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertOk()
        ->assertJsonPath('unread_count', 1);
    $this->actingAs($this->user)->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('notifications.0.read_at', fn ($value) => $value !== null));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('bell unread count is total eligible unread notifications while recent list stays capped at eight', function () {
    foreach ([0, 1, 8, 9, 14] as $expectedCount) {
        $user = User::factory()->create(['section' => 'CDS']);
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
        for ($index = 0; $index < $expectedCount; $index++) {
            $user->notify(new EdatsInAppNotification(notificationPayload("bell-{$expectedCount}-{$index}")));
        }

        $response = $this->actingAs($user)->get(route('notifications.recent'))->assertOk();
        $response->assertJsonPath('unread_count', $expectedCount)
            ->assertJsonCount(min($expectedCount, 8), 'notifications');
    }
});

test('bell unread total updates from fourteen to thirteen after marking one eligible notification read', function () {
    for ($index = 0; $index < 14; $index++) {
        $this->user->notify(new EdatsInAppNotification(notificationPayload("fourteen-{$index}")));
    }
    $other = User::factory()->create(['section' => 'CDS']);
    $other->notify(new EdatsInAppNotification(notificationPayload('another-users-unread')));
    $notification = $this->user->notifications()->where('data->dedup_key', 'fourteen-0')->firstOrFail();

    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertJsonPath('unread_count', 14)
        ->assertJsonCount(8, 'notifications');
    $this->actingAs($this->user)->withHeader('Accept', 'application/json')
        ->patch(route('notifications.read', $notification))->assertOk();
    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertJsonPath('unread_count', 13)
        ->assertJsonCount(8, 'notifications');
    $this->actingAs($this->user)->get(route('notifications.recent'))
        ->assertJsonPath('unread_count', 13);

    expect($other->fresh()->unreadNotifications()->count())->toBe(1);
});

test('workflow notification URLs select the exact supported source record safely', function () {
    $sourceCases = [
        ['conservation', 'conservation', 41],
        ['engp', 'engp', 42],
        [ConservationReportSubmission::class, 'conservation', 43],
        ['bms', 'bms', 44],
        ['bams', 'bams', 45],
        ['imea', 'imea', 46],
        ['imea-maintenance', 'imea-maintenance', 47],
        ['aws', 'aws', 48],
        ['ipaf-management', 'ipaf-management', 49],
        ['revenue', 'revenue', 50],
        ['management-plans', 'management-plans', 51],
        [EngpReportSubmission::class, 'engp', 52],
        [BmsReportSubmission::class, 'bms', 53],
        [BamsReportSubmission::class, 'bams', 54],
        [ImeaReportSubmission::class, 'imea', 55],
        [ImeaFacilityMaintenanceReport::class, 'imea-maintenance', 56],
        [Aws::class, 'aws', 57],
        [IpafManagementReport::class, 'ipaf-management', 58],
        [IpafRevenueCollection::class, 'revenue', 59],
        [ManagementPlan::class, 'management-plans', 60],
    ];

    foreach ($sourceCases as [$sourceType, $expectedSource, $id]) {
        $url = EdatsInAppNotificationService::actionUrl([
            'source_type' => $sourceType,
            'source_id' => $id,
            'url' => 'https://attacker.invalid/',
        ], $this->user);

        expect($url)->toContain('source='.$expectedSource)
            ->and($url)->toContain('source_id='.$id)
            ->and($url)->not->toContain('attacker.invalid');
    }

    $fallback = EdatsInAppNotificationService::actionUrl([
        'source_type' => 'unknown-family',
        'source_id' => 44,
        'url' => 'https://attacker.invalid/',
    ], $this->user);
    expect($fallback)->toBe(route('submission-tracking.index'))
        ->and($fallback)->not->toContain('attacker.invalid');
});

test('unauthenticated users cannot mark notifications as read', function () {
    $this->user->notify(new EdatsInAppNotification(notificationPayload('guest-read')));
    $notification = $this->user->notifications()->first();

    $this->patch(route('notifications.read', $notification))->assertRedirect(route('login'));
    expect($notification->fresh()->read_at)->toBeNull();
});

test('overdue and due soon in-app notifications are derived once from the live alert source', function () {
    $this->user->update(['section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati']);
    $area = notificationProtectedArea('Pujada Bay Protected Landscape', $this->user);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $this->user->id,
    ]);
    notificationConservationReport($area, $this->user, ['target_office' => 'CENRO Mati', 'date_accomplished' => '2026-08-03']);
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
    $engpActor = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => null, 'office_designated' => 'CENRO Mati']);
    $mhrws = notificationConservationReport(notificationProtectedArea('Mt. Hamiguitan Range Wildlife Sanctuary', $this->user, 'MHRWS'), $this->user);
    $engp = notificationEngpReport($this->user, ['workflow_key' => 'cbep']);
    $tracking = app(SubmissionTrackingService::class);

    $tracking->transition('conservation', $mhrws->id, SubmissionTrackingService::PENRO_RECEIPT, '2026-08-10', $this->user->id);
    $tracking->transition('engp', $engp->id, 'forward_to_cenro_chief', '2026-08-10', $engpActor->id);

    expect($this->user->notifications()->count())->toBe(0)
        ->and($tracking->queues()[SubmissionTrackingService::CENRO_RELEASE]->where('source_id', $mhrws->id))->toBeEmpty()
        ->and($engp->fresh()->date_received_penro)->toBeNull();
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
    $this->user->update(['section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati']);
    $engp = notificationEngpReport($this->user, ['deadline_submission' => '2026-08-20']);
    app(EdatsInAppNotificationService::class)->syncDeadlineNotifications(CarbonImmutable::parse('2026-08-29', 'Asia/Manila'));

    $notification = $this->user->notifications()->first();
    expect($notification->data['type'])->toBe(EdatsInAppNotificationService::OVERDUE)
        ->and($notification->data['source_type'])->toBe(EngpReportSubmission::class)
        ->and(app(OverdueReportService::class)->sourceDefinitions())->toHaveKey(ImeaFacilityMaintenanceReport::class)
        ->and($engp->date_received_penro)->toBeNull();
});

test('workflow handoffs notify only the next accountable office and receipt returns information to the sender', function (): void {
    $focal = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'office_designated' => 'CENRO Mati', 'unit_assignment' => null]);
    $chief = User::factory()->create(['section' => 'CENRO_CDS_CHIEF', 'office_designated' => 'CENRO Mati', 'unit_assignment' => null]);
    foreach ([$focal, $chief] as $actor) {
        foreach (['reports.view', 'technical-reports.update'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }
    $report = notificationEngpReport($focal, ['office' => 'CENRO Mati']);
    $routing = app(DocumentRoutingTransitionService::class);

    expect(app(OrganizationalAccessService::class)->effectiveCategory($chief))->toBe(OrganizationalAccessService::CENRO_CHIEF);
    $event = $routing->transition($report, 'engp', 'forward_to_cenro_chief', $focal->id);
    app(EdatsInAppNotificationService::class)->notifyGenericTransition($report, 'engp', $event, ['to_office' => 'CENRO CDS Chief']);

    expect($chief->fresh()->notifications()->get()->pluck('data')->pluck('title')->all())->toBe(['Submission Forwarded'])
        ->and($this->user->fresh()->notifications()->count())->toBe(0);

    $handoff = $chief->fresh()->notifications()->first();
    expect($handoff->data['url'])->toContain('view=incoming')
        ->and($handoff->data['source_type'])->toBe('engp')
        ->and((int) $handoff->data['source_id'])->toBe($report->id);

    $routing->transition($report, 'engp', 'receive_at_cenro_chief', $chief->id);

    expect($focal->fresh()->notifications()->get()->pluck('data')->pluck('title')->all())->toContain('Submission Received')
        ->and($focal->fresh()->notifications()->where('data->title', 'Submission Received')->first()->data['url'])->toContain('view=outgoing');
});

test('deadline notifications respect ENGP office scope while retaining province-wide PENRO visibility', function (): void {
    $mati = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $baganga = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $penro = notificationScopedUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $global = User::factory()->create();
    $global->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $inactiveMati = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati', active: false);
    $withoutPermission = notificationScopedUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati', permission: false);
    notificationEngpReport($this->user, ['office' => 'CENRO Mati', 'deadline_submission' => '2026-08-20']);
    notificationEngpReport($this->user, ['office' => 'CENRO Baganga', 'period_key' => '2026-07', 'period_label' => 'July 2026', 'deadline_submission' => '2026-08-20']);

    app(EdatsInAppNotificationService::class)->syncDeadlineNotifications(CarbonImmutable::parse('2026-08-29', 'Asia/Manila'));

    expect($mati->fresh()->notifications()->count())->toBe(1)
        ->and($mati->fresh()->notifications()->first()->data['office'])->toBe('CENRO Mati')
        ->and($baganga->fresh()->notifications()->count())->toBe(1)
        ->and($baganga->fresh()->notifications()->first()->data['office'])->toBe('CENRO Baganga')
        ->and($penro->fresh()->notifications()->count())->toBe(2)
        ->and($global->fresh()->notifications()->count())->toBe(2)
        ->and($inactiveMati->fresh()->notifications()->count())->toBe(0)
        ->and($withoutPermission->fresh()->notifications()->count())->toBe(0)
        ->and($this->user->fresh()->notifications()->count())->toBe(0)
        ->and($mati->fresh()->notifications()->first()->data['dedup_key'])->toBe($penro->fresh()->notifications()->where('data->office', 'CENRO Mati')->first()->data['dedup_key']);
});

test('PA deadline notifications respect CENRO jurisdiction and assigned PAMO scope without duplicate recipients', function (): void {
    $mati = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $baganga = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $penro = notificationScopedUser(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $area = notificationProtectedArea('Scoped notification PA', $this->user);
    $pamo = notificationScopedUser(OrganizationalAccessService::PAMO, 'CENRO Mati', protectedAreaId: $area->id);
    $otherArea = notificationProtectedArea('Other scoped notification PA', $this->user);
    $otherPamo = notificationScopedUser(OrganizationalAccessService::PAMO, 'CENRO Mati', protectedAreaId: $otherArea->id);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $this->user->id,
    ]);
    notificationConservationReport($area, $this->user, [
        'workflow_key' => 'homestay',
        'activity_name' => 'Homestay',
        'target_office' => 'CENRO Mati',
        'date_accomplished' => '2026-08-01',
    ]);

    app(EdatsInAppNotificationService::class)->syncDeadlineNotifications(CarbonImmutable::parse('2026-08-29', 'Asia/Manila'));

    expect($mati->fresh()->notifications()->count())->toBe(1)
        ->and($penro->fresh()->notifications()->count())->toBe(1)
        ->and($pamo->fresh()->notifications()->count())->toBe(1)
        ->and($baganga->fresh()->notifications()->count())->toBe(0)
        ->and($otherPamo->fresh()->notifications()->count())->toBe(0)
        ->and($mati->fresh()->notifications()->first()->data['protected_area'])->toBe($area->name);
});

function notificationScopedUser(string $category, string $office, ?int $protectedAreaId = null, bool $active = true, bool $permission = true): User
{
    $user = User::factory()->create([
        'section' => $category,
        'unit_assignment' => $category === OrganizationalAccessService::PAMO ? OrganizationalAccessService::CONSERVATION : null,
        'office_designated' => $office,
        'protected_area_id' => $protectedAreaId,
        'is_active' => $active,
    ]);
    if ($permission) $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));

    return $user;
}

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
