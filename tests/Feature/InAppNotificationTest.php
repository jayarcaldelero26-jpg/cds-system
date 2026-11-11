<?php

use App\Domain\Modules\ProgramArea;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\EngpReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\BamsReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\Aws;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ModuleDefinition;
use App\Models\ManagementPlan;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Events\LiveRefreshRequested;
use App\Notifications\EdatsInAppNotification;
use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\IncomingUnreadCountService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\Compliance\OverdueReportService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

test('shared notification bell returns the full unread alert count and a capped list', function (): void {
    for ($index = 1; $index <= 10; $index++) {
        $this->user->notify(new EdatsInAppNotification(notificationPayload('shared-bell-'.$index)));
    }

    $bellCountQueries = 0;
    $bellListQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$bellCountQueries, &$bellListQueries): void {
        $sql = strtolower($query->sql);
        if (! str_contains($sql, 'from "notifications"') || ! str_contains($sql, '"read_at" is null')) {
            return;
        }

        if (str_contains($sql, 'count(')) {
            $bellCountQueries++;
        } elseif (str_contains($sql, 'order by "created_at" desc')) {
            $bellListQueries++;
        }
    });

    $response = $this->actingAs($this->user)->get(route('notifications.index'))->assertOk();
    $props = $response->inertiaProps();

    expect(data_get($props, 'notificationBell.unread_count'))->toBe(10)
        ->and(data_get($props, 'notificationBell.notifications'))->toHaveCount(8)
        ->and($bellCountQueries)->toBe(1)
        ->and($bellListQueries)->toBe(1);
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
        ], $this->user->can('submission-tracking.view'));

        expect($url)->toContain('source='.$expectedSource)
            ->and($url)->toContain('source_id='.$id)
            ->and($url)->not->toContain('attacker.invalid');
    }

    $fallback = EdatsInAppNotificationService::actionUrl([
        'source_type' => 'unknown-family',
        'source_id' => 44,
        'url' => 'https://attacker.invalid/',
    ], $this->user->can('submission-tracking.view'));
    expect($fallback)->toBe(route('submission-tracking.index'))
        ->and($fallback)->not->toContain('attacker.invalid');
});

test('recent workflow notifications preserve the intended Tracking tab and selected source record', function (): void {
    foreach ([
        ['view' => 'incoming', 'source' => 'engp', 'source_id' => 71, 'arrival_event_id' => 101],
        ['view' => 'outgoing', 'source' => 'conservation', 'source_id' => 72],
    ] as $index => $target) {
        $this->user->notify(new EdatsInAppNotification([
            'type' => EdatsInAppNotificationService::WORKFLOW,
            'dedup_key' => 'workflow-tab-'.$index,
            'title' => 'Workflow notice',
            'message' => 'Routing update.',
            'severity' => 'info',
            'category' => 'submission_updates',
            'source_type' => $target['source'],
            'source_id' => $target['source_id'],
            ...($target['view'] === 'incoming' ? ['arrival_event_id' => $target['arrival_event_id']] : []),
            'url' => route('submission-tracking.index', $target),
        ]));
    }

    $response = $this->actingAs($this->user)->get(route('notifications.recent'))->assertOk();
    $links = collect($response->json('notifications'))->keyBy('id');

    foreach ($this->user->notifications()->get() as $notification) {
        $link = $links->get($notification->id)['url'];
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        expect(parse_url($link, PHP_URL_HOST))->toBe(parse_url(route('submission-tracking.index'), PHP_URL_HOST))
            ->and($query)->toMatchArray([
                'view' => $notification->data['source_id'] === 72 ? 'outgoing' : 'incoming',
                'source' => $notification->data['source_type'],
                'source_id' => (string) $notification->data['source_id'],
                'open_details' => '1',
                ...($notification->data['source_id'] === 71 ? ['arrival_event_id' => '101'] : []),
            ]);
    }
});

test('a stale Incoming notification is marked read and returns to the queue without selecting its report', function (): void {
    $focal = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    foreach ([$focal, $chief] as $user) {
        foreach (['technical-reports.update', 'submission-tracking.view'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }
    $report = notificationEngpReport($focal, ['office' => 'CENRO Mati']);
    $routing = app(DocumentRoutingTransitionService::class);
    $arrival = $routing->transition($report, 'engp', 'forward_to_cenro_chief', $focal->getKey());
    $notification = $chief->notifications()->where('data->arrival_event_id', $arrival->getKey())->firstOrFail();

    $routing->transition($report, 'engp', 'receive_at_cenro_chief', $chief->getKey());
    $routing->transition($report, 'engp', 'forward_to_cenro_records', $chief->getKey());

    $this->actingAs($chief)->get(route('notifications.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('notifications.0.id', $notification->id)
        ->where('notifications.0.current_state', 'No longer in Incoming')
        ->where('notificationBell.notifications.0.id', $notification->id)
        ->where('notificationBell.notifications.0.current_state', 'No longer in Incoming'));

    $this->patch(route('notifications.read', $notification))
        ->assertRedirect(route('submission-tracking.index', ['view' => 'incoming']))
        ->assertSessionHas('status', 'This report no longer needs your action.')
        ->assertSessionHas('notification_action', 'stale_incoming');

    expect($notification->fresh()->read_at)->not->toBeNull();
    $this->get(route('submission-tracking.index', ['view' => 'incoming']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SubmissionTracking/Index')
            ->where('trackingContext.view', 'incoming')
            ->where('trackingContext.selected_record', null)
            ->where('trackingContext.open_details', false)
            ->where('trackingContext.notification_action', 'stale_incoming'));

    $this->get(route('notifications.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('notifications.0.id', $notification->id)
        ->where('notifications.0.current_state', 'No longer in Incoming'));
});

test('a handoff between marking a notice read and opening its details is rechecked on the destination request', function (): void {
    $focal = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    foreach ([$focal, $chief] as $user) {
        foreach (['technical-reports.update', 'submission-tracking.view'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }
    $report = notificationEngpReport($focal, ['office' => 'CENRO Mati']);
    $routing = app(DocumentRoutingTransitionService::class);
    $arrival = $routing->transition($report, 'engp', 'forward_to_cenro_chief', $focal->getKey());
    $notification = $chief->notifications()->where('data->arrival_event_id', $arrival->getKey())->firstOrFail();
    $destination = EdatsInAppNotificationService::actionUrl($notification->data, true);

    $this->actingAs($chief)->from(route('notifications.index'))
        ->patch(route('notifications.read', $notification))
        ->assertRedirect(route('notifications.index'));
    $routing->transition($report, 'engp', 'receive_at_cenro_chief', $chief->getKey());
    $routing->transition($report, 'engp', 'forward_to_cenro_records', $chief->getKey());

    $this->get($destination)
        ->assertRedirect(route('submission-tracking.index', ['view' => 'incoming', 'page' => 1]))
        ->assertSessionHas('notification_action', 'stale_incoming');
    $this->get(route('submission-tracking.index', ['view' => 'incoming']))
        ->assertInertia(fn ($page) => $page
            ->where('trackingContext.selected_record', null)
            ->where('trackingContext.open_details', false));
});

test('Incoming notification resolution ignores list filters when checking its current queue handoff', function (): void {
    $focal = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    foreach ([$focal, $chief] as $user) {
        foreach (['technical-reports.update', 'submission-tracking.view'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }
    $report = notificationEngpReport($focal, ['office' => 'CENRO Mati']);
    $arrival = app(DocumentRoutingTransitionService::class)->transition($report, 'engp', 'forward_to_cenro_chief', $focal->getKey());
    $notification = $chief->notifications()->where('data->arrival_event_id', $arrival->getKey())->firstOrFail();
    $destination = EdatsInAppNotificationService::actionUrl($notification->data, true);
    $destination .= '&module=Not+the+report+module';

    $this->actingAs($chief)->get($destination)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SubmissionTracking/Index')
            ->where('trackingContext.selected_record.source', 'engp')
            ->where('trackingContext.selected_record.source_id', $report->getKey())
            ->where('trackingContext.open_details', true));
});

test('a scoped CENRO chief can open an authorized read-only revenue notification without Reports permission', function (): void {
    $chief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga', permission: false);
    $otherChief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati', permission: false);
    $area = notificationProtectedArea('Revenue notification scope', $chief);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail()->id,
        'assignment_type' => 'supervising',
        'assigned_by' => $chief->id,
    ]);
    $report = IpafRevenueCollection::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'Revenue Collection',
        'document_type' => 'Final Report',
        'reporting_month' => 10,
        'reporting_year' => 2026,
        'total_collected' => '100.00',
    ]);
    // This fixture has no live routing handoff, so its notice must remain a
    // read-only destination instead of claiming the report is actionable.
    $target = ['view' => 'outgoing', 'source' => 'revenue', 'source_id' => $report->id];
    $payload = [
        ...notificationPayload('scoped-revenue'),
        'type' => EdatsInAppNotificationService::WORKFLOW,
        'category' => 'submission_updates',
        'source_type' => 'revenue',
        'source_id' => $report->id,
        'url' => route('submission-tracking.index', $target),
    ];
    $chief->notify(new EdatsInAppNotification($payload));

    expect($chief->can('reports.view'))->toBeFalse()
        ->and($chief->can('submission-tracking.view'))->toBeTrue();

    $link = route('submission-tracking.index', [...$target, 'open_details' => 1]);
    $this->actingAs($chief)->getJson(route('notifications.recent'))
        ->assertOk()->assertJsonPath('notifications.0.url', $link);
    $this->get(route('notifications.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('notificationBell.notifications.0.url', $link)
        ->where('notifications.0.url', $link));
    $this->get($link)->assertOk()->assertInertia(fn ($page) => $page
        ->where('trackingContext.open_details', true)
        ->where('trackingContext.selected_record.source', 'revenue')
        ->where('trackingContext.selected_record.source_id', $report->id));

    $filteredLink = route('submission-tracking.index', [...$target, 'open_details' => 1, 'search' => 'no-matching-report']);
    $this->get($filteredLink)->assertOk()->assertInertia(fn ($page) => $page
        ->where('filters.search', 'no-matching-report')
        ->where('trackingContext.selected_record.source', 'revenue')
        ->where('trackingContext.selected_record.source_id', $report->id));

    $this->actingAs($otherChief)->get($link)->assertOk()->assertInertia(fn ($page) => $page
        ->where('trackingContext.open_details', true)
        ->where('trackingContext.selected_record', null));
    expect(EdatsInAppNotificationService::actionUrl($payload, User::factory()->create()->can('submission-tracking.view')))
        ->toBe(route('dashboard'));
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

test('submission tracking notifies the next owner at a canonical custody handoff', function () {
    $area = notificationProtectedArea('Pujada Bay Protected Landscape', $this->user);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $this->user->id,
    ]);
    $focal = User::factory()->create(['section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati', 'unit_assignment' => null]);
    $chief = User::factory()->create(['section' => OrganizationalAccessService::CENRO_CHIEF, 'office_designated' => 'CENRO Mati', 'unit_assignment' => null]);
    foreach ([$focal, $chief] as $actor) {
        foreach (['reports.view', 'technical-reports.update'] as $permission) $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $report = notificationConservationReport($area, $focal, ['workflow_key' => 'homestay', 'target_office' => 'CENRO Mati']);

    app(DocumentRoutingTransitionService::class)->transition($report, 'conservation', 'forward_to_cenro_chief', $focal->id);

    expect($chief->fresh()->notifications()->get()->pluck('data')->pluck('title')->all())->toBe(['Submission Forwarded'])
        ->and($focal->fresh()->notifications()->count())->toBe(0)
        ->and($this->user->fresh()->notifications()->count())->toBe(0)
        ->and($report->fresh()->date_report_released_cenro)->toBeNull()
        ->and($report->fresh()->date_received_penro)->toBeNull()
        ->and($report->fresh()->date_endorsed_regional)->toBeNull();
});

test('MHRWS bypasses CENRO routing and ENGP never generates regional endorsement routing', function () {
    $engpActor = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => null, 'office_designated' => 'CENRO Mati']);
    foreach (['reports.view', 'technical-reports.update'] as $permission) $engpActor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    $penroRecords = User::factory()->create(['section' => OrganizationalAccessService::PENRO_RECORDS, 'unit_assignment' => 'conservation', 'office_designated' => 'PENRO Davao Oriental']);
    foreach (['reports.view', 'technical-reports.update'] as $permission) $penroRecords->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    $mhrws = notificationConservationReport(notificationProtectedArea('Mt. Hamiguitan Range Wildlife Sanctuary', $this->user, 'MHRWS'), $this->user);
    $engp = notificationEngpReport($this->user, ['workflow_key' => 'cbep']);
    $tracking = app(DocumentRoutingTransitionService::class);

    $directEvent = $tracking->transition($mhrws, 'conservation', 'receive_at_penro_records', $penroRecords->id);
    try {
        $engpEvent = $tracking->transition($engp, 'engp', 'forward_to_cenro_chief', $engpActor->id);
    } catch (\Throwable $exception) { throw new \RuntimeException('ENGP forward failed at '.json_encode($tracking->state($engp->fresh(), 'engp')['stage']).': '.$exception->getMessage(), previous: $exception); }

    expect($this->user->notifications()->count())->toBe(0)
        ->and($directEvent->from_stage)->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and($tracking->state($mhrws->fresh(), 'conservation')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and(collect($tracking->state($mhrws->fresh(), 'conservation')['actions'])->pluck('key'))->toContain('forward_to_office_penro')->not->toContain('forward_to_cenro_chief')
        ->and($engpEvent->to_stage)->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)
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

test('deadline-day ENGP receipt with zero Days Complied and Unsatisfactory rating is not alerted as late', function (): void {
    $today = CarbonImmutable::parse('2026-08-29', 'Asia/Manila');
    $report = notificationEngpReport($this->user, [
        'deadline_submission' => '2026-08-29',
        'date_received_penro' => '2026-08-29',
    ]);

    expect($report->days_complied)->toBe(0)
        ->and($report->timeliness_rating)->toBe('Unsatisfactory');

    app(EdatsInAppNotificationService::class)->syncDeadlineNotifications($today);
    $alerts = app(OverdueReportService::class);

    expect($this->user->notifications()->count())->toBe(0)
        ->and($alerts->dueSoonReports(3, $today)->pluck('sourceId'))->not->toContain($report->id)
        ->and($alerts->dueTodayReports($today)->pluck('sourceId'))->not->toContain($report->id)
        ->and($alerts->overdueReports($today)->pluck('sourceId'))->not->toContain($report->id);
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

test('location-qualified CENRO focal handoffs notify the assigned CENRO instead of PENRO focal users', function (): void {
    $sender = notificationScopedUser(OrganizationalAccessService::PAMO, 'PAMO Baganga');
    $assignedFocal = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $assignedPeer = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $unrelatedFocal = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $penroFocal = notificationScopedUser(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $area = notificationProtectedArea('Location-qualified CENRO notification PA', $sender);
    $report = notificationConservationReport($area, $sender, ['target_office' => 'CENRO Baganga']);
    $event = new DocumentRoutingEvent([
        'source_type' => 'conservation',
        'source_id' => $report->getKey(),
        'event_key' => 'forwarded',
        'from_stage' => 'pamo_origin',
        'to_stage' => 'transit_to_cenro_focal',
        'from_office' => 'PAMO Baganga',
        'to_office' => 'CENRO Baganga CDS Focal Person',
        'recorded_by' => $sender->getKey(),
        'metadata' => ['action_key' => 'forward_from_pamo_to_cenro_focal'],
    ]);
    $event->setAttribute('id', 1);

    app(EdatsInAppNotificationService::class)->notifyGenericTransition(
        $report,
        'conservation',
        $event,
        ['to_office' => $event->to_office],
    );

    expect($assignedFocal->fresh()->notifications()->get()->pluck('data')->pluck('title')->all())->toBe(['Submission Forwarded'])
        ->and($assignedPeer->fresh()->notifications()->get()->pluck('data')->pluck('title')->all())->toBe(['Submission Forwarded'])
        ->and($unrelatedFocal->fresh()->notifications()->count())->toBe(0)
        ->and($penroFocal->fresh()->notifications()->count())->toBe(0)
        ->and($this->user->fresh()->notifications()->count())->toBe(0);
});

test('CENRO and PENRO workflow notices include active exact-guard Super Admins once per event', function (): void {
    $sender = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $penroRecords = notificationScopedUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $superAdminRole = Role::findOrCreate('Super Admin', 'web');
    $overlappingRecipient = notificationScopedUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $overlappingRecipient->assignRole($superAdminRole);
    $superAdmin = User::factory()->create(['section' => 'CDS']);
    $superAdmin->assignRole($superAdminRole);
    $inactiveSuperAdmin = User::factory()->create(['section' => 'CDS', 'is_active' => false]);
    $inactiveSuperAdmin->assignRole($superAdminRole);
    $cdsAdmin = User::factory()->create(['section' => 'CDS']);
    $cdsAdmin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $wrongGuard = User::factory()->create(['section' => 'CDS']);
    $apiRole = Role::findOrCreate('Super Admin', 'api');
    DB::table(config('permission.table_names.model_has_roles'))->insert([
        'role_id' => $apiRole->getKey(),
        'model_type' => User::class,
        'model_id' => $wrongGuard->getKey(),
    ]);

    $report = notificationEngpReport($sender, ['office' => 'CENRO Baganga']);
    $event = new DocumentRoutingEvent([
        'source_type' => 'engp',
        'source_id' => $report->getKey(),
        'event_key' => 'forwarded',
        'from_stage' => 'preparation',
        'to_stage' => 'transit_penro_records',
        'from_office' => 'CENRO Baganga CDS Focal Person',
        'to_office' => 'PENRO Records Unit',
        'recorded_by' => $sender->getKey(),
        'metadata' => ['action_key' => 'forward_to_penro_records'],
    ]);
    $event->setAttribute('id', 780);
    $service = app(EdatsInAppNotificationService::class);

    $affected = $service->notifyGenericTransition($report, 'engp', $event, ['to_office' => 'PENRO Records Unit']);
    $service->notifyGenericTransition($report, 'engp', $event, ['to_office' => 'PENRO Records Unit']);

    $notification = $superAdmin->fresh()->notifications()->firstOrFail();
    expect($penroRecords->fresh()->notifications()->count())->toBe(1)
        ->and($overlappingRecipient->fresh()->notifications()->count())->toBe(1)
        ->and($superAdmin->fresh()->notifications()->count())->toBe(1)
        ->and($inactiveSuperAdmin->fresh()->notifications()->count())->toBe(0)
        ->and($cdsAdmin->fresh()->notifications()->count())->toBe(0)
        ->and($wrongGuard->fresh()->notifications()->count())->toBe(0)
        ->and($affected)->toContain((int) $superAdmin->getKey())
        ->and($notification->data['arrival_event_id'])->toBe(780)
        ->and($notification->data['arrival_category'])->toBe(OrganizationalAccessService::PENRO_RECORDS)
        ->and($notification->data['from_office'])->toBe('CENRO Baganga CDS Focal Person')
        ->and($notification->data['source_id'])->toBe($report->getKey())
        ->and($notification->data['office'])->toBe('PENRO Records Unit');

    $this->actingAs($superAdmin)->get(route('notifications.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('incomingUnreadCount', 1));
    expect(app(IncomingUnreadCountService::class)->countFor($superAdmin))->toBe(1);

    $notification->markAsRead();
    expect($overlappingRecipient->fresh()->unreadNotifications()->count())->toBe(1);
});

test('correction notice targets the eligible report originator and excludes the correcting actor and PAMO peers', function (): void {
    $originator = notificationScopedUser(OrganizationalAccessService::PAMO, 'PAMO Baganga');
    $area = notificationProtectedArea('Correction recipient PA', $originator);
    $originator->update(['protected_area_id' => $area->getKey()]);
    $pamoPeer = notificationScopedUser(OrganizationalAccessService::PAMO, 'PAMO Baganga', $area->getKey());
    $cenroActor = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga');
    $report = notificationConservationReport($area, $originator, ['target_office' => 'CENRO Baganga']);
    $event = new DocumentRoutingEvent([
        'source_type' => 'conservation', 'source_id' => $report->getKey(),
        'event_key' => 'returned_for_correction', 'from_stage' => 'cenro_chief',
        'to_stage' => 'pamo_origin', 'from_office' => 'CENRO Baganga CDS Chief',
        'to_office' => 'PAMO Baganga', 'recorded_by' => $cenroActor->getKey(),
        'metadata' => ['action_key' => 'return_to_pamo_for_correction'],
    ]);
    $event->setAttribute('id', 9501);

    $affected = app(EdatsInAppNotificationService::class)->notifyGenericTransition(
        $report, 'conservation', $event, ['to_office' => 'PAMO Baganga'],
    );

    expect($originator->fresh()->notifications()->get()->pluck('data')->pluck('title')->all())->toBe(['Correction Required'])
        ->and($pamoPeer->fresh()->notifications()->count())->toBe(0)
        ->and($cenroActor->fresh()->notifications()->count())->toBe(0)
        ->and($affected)->toContain((int) $cenroActor->getKey(), (int) $originator->getKey());
});

test('ordinary destination recipients exclude the action actor while a Super Admin actor still gets one global copy', function (): void {
    $superAdminRole = Role::findOrCreate('Super Admin', 'web');
    $actor = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $actor->assignRole($superAdminRole);
    $destinationPeer = notificationScopedUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = notificationEngpReport($actor, ['office' => 'CENRO Baganga']);
    $event = new DocumentRoutingEvent([
        'source_type' => 'engp', 'source_id' => $report->getKey(), 'event_key' => 'forwarded',
        'from_stage' => 'preparation', 'to_stage' => 'transit_penro_records',
        'from_office' => 'CENRO Baganga CDS Focal Person', 'to_office' => 'PENRO Records Unit',
        'recorded_by' => $actor->getKey(), 'metadata' => ['action_key' => 'forward_to_penro_records'],
    ]);
    $event->setAttribute('id', 9502);

    app(EdatsInAppNotificationService::class)->notifyGenericTransition($report, 'engp', $event, ['to_office' => 'PENRO Records Unit']);

    expect($destinationPeer->fresh()->notifications()->count())->toBe(1)
        ->and($actor->fresh()->notifications()->count())->toBe(1)
        ->and($actor->fresh()->notifications()->first()->data['arrival_category'])->toBe(OrganizationalAccessService::PENRO_RECORDS);
});

test('PAMB routing notices include active Super Admins and the after-commit audience contains them', function (): void {
    Event::fake([LiveRefreshRequested::class]);
    $actor = notificationScopedUser(OrganizationalAccessService::PAMO, 'PAMO Baganga');
    $penroRecords = notificationScopedUser(OrganizationalAccessService::OFFICE_PENRO, 'Office of the PENRO');
    $superAdmin = User::factory()->create(['section' => 'CDS']);
    $superAdmin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = notificationProtectedArea('PAMB Super Admin notification PA', $actor);
    $report = notificationConservationReport($area, $actor, ['target_office' => 'PENRO Davao Oriental']);
    $event = new PambRoutingEvent([
        'conservation_report_submission_id' => $report->getKey(),
        'workflow_key' => $report->workflow_key,
        'stage_key' => 'forwarded_records_to_penro',
        'recorded_by' => $actor->getKey(),
    ]);
    $event->setAttribute('id', 881);

    $affected = app(EdatsInAppNotificationService::class)->notifyPambTransition($report, $event, 'forwarded_records_to_penro');
    app(\App\Services\LiveRefreshPublisher::class)->afterCommit($affected, ['notifications', 'tracking']);

    expect($penroRecords->fresh()->notifications()->count())->toBe(1)
        ->and($superAdmin->fresh()->notifications()->count())->toBe(1)
        ->and($superAdmin->fresh()->notifications()->first()->data['arrival_event_id'])->toBe(881)
        ->and($superAdmin->fresh()->notifications()->first()->data['office'])->toBe('Office of the PENRO')
        ->and($affected)->toContain((int) $superAdmin->getKey());
    Event::assertDispatched(LiveRefreshRequested::class, fn (LiveRefreshRequested $refresh): bool =>
        $refresh->recipientId === (int) $superAdmin->getKey() && $refresh->scope === 'notifications'
    );
    Event::assertDispatched(LiveRefreshRequested::class, fn (LiveRefreshRequested $refresh): bool =>
        $refresh->recipientId === (int) $superAdmin->getKey() && $refresh->scope === 'tracking'
    );
});

test('Incoming unread count follows the authorized queue and the newest arrival read state', function (): void {
    $focal = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    foreach ([$focal, $chief] as $user) {
        $user->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    }
    $report = notificationEngpReport($focal, ['office' => 'CENRO Mati']);
    $event = app(DocumentRoutingTransitionService::class)->transition($report, 'engp', 'forward_to_cenro_chief', $focal->getKey());
    $notification = $chief->notifications()->where('data->arrival_event_id', $event->getKey())->firstOrFail();

    $this->actingAs($chief);
    expect(app(IncomingUnreadCountService::class)->countFor($chief))->toBe(1);
    $this->get(route('notifications.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('incomingUnreadCount', 1));
    $this->patch(route('notifications.read', $notification))->assertRedirect();
    expect($notification->fresh()->read_at)->not->toBeNull()
        ->and(app(IncomingUnreadCountService::class)->countFor($chief))->toBe(0);

    $routing = app(DocumentRoutingTransitionService::class);
    $routing->transition($report, 'engp', 'receive_at_cenro_chief', $chief->getKey());
    $routing->transition($report, 'engp', 'return_to_cenro_focal', $chief->getKey(), 'Please correct the report.');
    $routing->transition($report, 'engp', 'receive_correction', $focal->getKey());
    $nextArrival = $routing->transition($report, 'engp', 'forward_to_cenro_chief', $focal->getKey());
    $nextNotification = $chief->notifications()->where('data->arrival_event_id', $nextArrival->getKey())->firstOrFail();
    expect(app(IncomingUnreadCountService::class)->countFor($chief))->toBe(1);

    $routing->transition($report, 'engp', 'receive_at_cenro_chief', $chief->getKey());
    $routing->transition($report, 'engp', 'return_to_cenro_focal', $chief->getKey(), 'Please correct the report again.');
    expect($nextNotification->fresh()->read_at)->toBeNull()
        ->and(app(IncomingUnreadCountService::class)->countFor($chief))->toBe(0);
});

test('canonical routing refreshes the sender and authorized receiving office after commit only', function (): void {
    Event::fake([LiveRefreshRequested::class]);
    $focal = notificationScopedUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $unrelatedChief = notificationScopedUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga');
    foreach ([$focal, $chief, $unrelatedChief] as $viewer) {
        $viewer->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    }
    $report = notificationEngpReport($focal, ['office' => 'CENRO Mati']);

    DB::beginTransaction();
    app(DocumentRoutingTransitionService::class)->transition($report, 'engp', 'forward_to_cenro_chief', $focal->getKey());

    Event::assertNotDispatched(LiveRefreshRequested::class);
    DB::commit();

    Event::assertDispatchedTimes(LiveRefreshRequested::class, 4);
    foreach ([$focal, $chief] as $viewer) {
        foreach (['notifications', 'tracking'] as $scope) {
            Event::assertDispatched(LiveRefreshRequested::class, fn (LiveRefreshRequested $event): bool =>
                $event->recipientId === (int) $viewer->getKey() && $event->scope === $scope
            );
        }
    }
    Event::assertNotDispatched(LiveRefreshRequested::class, fn (LiveRefreshRequested $event): bool =>
        $event->recipientId === (int) $unrelatedChief->getKey()
    );
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
    $workflowKey = 'maintenance_pamo_ecotourism';
    ModuleDefinition::query()->create([
        'name' => 'Active PAMO Notification Workflow',
        'code' => $workflowKey,
        'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT->value,
        'requirement_domain' => 'pa',
        'requirement_key' => $workflowKey,
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
        'reporting_frequency' => 'monthly',
        'deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS,
        'default_deadline_days' => 7,
        'is_active' => true,
    ]);
    $pamoOffice = OrganizationalOffice::query()->create([
        'code' => 'test_pamo_notification_scope',
        'name' => 'PAMO Notification Scope',
        'office_type' => 'pamo',
        'is_active' => true,
    ]);
    $otherPamoOffice = OrganizationalOffice::query()->create([
        'code' => 'test_pamo_notification_other_scope',
        'name' => 'PAMO Notification Other Scope',
        'office_type' => 'pamo',
        'is_active' => true,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $this->user->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => $pamoOffice->id,
        'assignment_type' => 'pamo',
        'assigned_by' => $this->user->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $otherArea->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_baganga')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $this->user->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $otherArea->id,
        'organizational_office_id' => $otherPamoOffice->id,
        'assignment_type' => 'pamo',
        'assigned_by' => $this->user->id,
    ]);
    $area->update(['pamo' => $pamoOffice->name]);
    $otherArea->update(['pamo' => $otherPamoOffice->name]);
    $pamo->update(['office_designated' => $pamoOffice->name]);
    $otherPamo->update(['office_designated' => $otherPamoOffice->name]);
    expect(app(ConservationReportWorkflowRegistry::class)->supportsPamoSubmission($workflowKey))->toBeTrue()
        ->and(app(OrganizationalAccessService::class)->canViewPamoPilotWorkflow($pamo))->toBeTrue();
    $report = notificationConservationReport($area, $this->user, [
        'workflow_key' => $workflowKey,
        'activity_name' => 'Maintenance of PAMO',
        'document_type' => 'Report',
        'target_office' => 'CENRO Mati',
        'date_accomplished' => '2026-08-01',
    ]);
    expect(app(OrganizationalAccessService::class)->canAccessUnit($pamo, OrganizationalAccessService::CONSERVATION))->toBeTrue()
        ->and(app(PambSubmissionAccessService::class)->canView($pamo, $report))->toBeTrue()
        ->and($pamo->can('reports.view'))->toBeTrue()
        ->and($pamo->hasRole('no_role'))->toBeFalse();

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
