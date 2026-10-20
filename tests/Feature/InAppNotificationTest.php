<?php

use App\Domain\Modules\ProgramArea;
use App\Models\ConservationReportSubmission;
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
use App\Notifications\EdatsInAppNotification;
use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\Compliance\OverdueReportService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

test('shared notification bell reads the capped unread alert collection once per page request', function (): void {
    for ($index = 1; $index <= 10; $index++) {
        $this->user->notify(new EdatsInAppNotification(notificationPayload('shared-bell-'.$index)));
    }

    $bellQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$bellQueries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'from "notifications"')
            && str_contains($sql, '"read_at" is null')
            && str_contains($sql, 'order by "created_at" desc')) {
            $bellQueries++;
        }
    });

    $response = $this->actingAs($this->user)->get(route('notifications.index'))->assertOk();
    $props = $response->inertiaProps();

    expect(data_get($props, 'notificationBell.unread_count'))->toBe(8)
        ->and(data_get($props, 'notificationBell.notifications'))->toHaveCount(8)
        ->and($bellQueries)->toBe(1);
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

test('recent workflow notifications preserve the intended Tracking tab and selected source record', function (): void {
    foreach ([
        ['view' => 'incoming', 'source' => 'engp', 'source_id' => 71],
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
            ]);
    }
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
