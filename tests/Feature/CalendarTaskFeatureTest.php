<?php

use App\Models\CalendarTask;
use App\Models\AuditLog;
use App\Models\NonWorkingDay;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Calendar\CalendarTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
    Mail::fake();
});

test('Calendar is available to requested organizational categories and existing reports viewers', function (): void {
    $organization = app(OrganizationalAccessService::class);
    $categories = [
        OrganizationalAccessService::PAMO,
        OrganizationalAccessService::CENRO_RECORDS,
        OrganizationalAccessService::CENRO_CHIEF,
        OrganizationalAccessService::CENRO_FOCAL,
        OrganizationalAccessService::PENRO_CHIEF,
        OrganizationalAccessService::PENRO_FOCAL,
    ];

    foreach ($categories as $category) {
        $user = User::factory()->create(['section' => $category]);
        expect($organization->canViewCalendar($user))->toBeTrue();
        $this->actingAs($user)->get(route('business-calendar.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.canViewCalendar', true));
    }

    $reportViewer = User::factory()->create();
    $reportViewer->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $this->actingAs($reportViewer)->get(route('business-calendar.index'))->assertOk();

    $global = User::factory()->create();
    $global->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $this->actingAs($global)->get(route('business-calendar.index'))->assertOk();
});

test('Calendar denies unapproved or inactive users even when a global role would normally bypass permissions', function (): void {
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $unapproved = User::factory()->create(['is_approved' => false]);
    $unapproved->assignRole(Role::findOrCreate('Super Admin', 'web'));

    $this->actingAs($inactive)->get(route('business-calendar.index'))->assertRedirect(route('login'));
    $this->actingAs($unapproved)->get(route('business-calendar.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['section' => 'CDS']))
        ->get(route('business-calendar.index'))->assertForbidden();
});

test('Calendar tasks are private, creator-owned, and use Manila wall-clock values', function (): void {
    $owner = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));

    $this->actingAs($owner)->post(route('calendar.tasks.store'), [
        'title' => 'Quarterly coordination', 'scheduled_date' => '2026-11-03', 'is_all_day' => false,
        'start_time' => '09:15', 'end_time' => '10:00', 'notes' => 'Local wall-clock schedule.',
        'created_by' => $viewer->id, 'actor_name' => 'Spoofed actor', 'office_name' => 'Spoofed office',
    ])->assertRedirect();

    $task = CalendarTask::query()->firstOrFail();
    expect($task->created_by)->toBe($owner->id)
        ->and($task->scheduled_date->format('Y-m-d'))->toBe('2026-11-03')
        ->and($task->start_time)->toBe('09:15')
        ->and($task->end_time)->toBe('10:00')
        ->and($task->status)->toBe(CalendarTask::STATUS_SCHEDULED);

    $createdEvent = AuditLog::query()->where('entity_type', CalendarTask::class)->where('entity_id', (string) $task->id)->where('action', 'created')->firstOrFail();
    expect($createdEvent->user_id)->toBe($owner->id)
        ->and($createdEvent->metadata['calendar_task_attribution']['actor_name'])->toBe($owner->name)
        ->and($createdEvent->metadata['calendar_task_attribution']['office_name'])->not->toBe('Spoofed office')
        ->and($createdEvent->summary)->not->toContain('Quarterly coordination');
    $payload = app(CalendarTaskService::class)->forRange($owner, Carbon\CarbonImmutable::parse('2026-11-01'), Carbon\CarbonImmutable::parse('2026-11-30'))->first();
    expect($payload['creator_attribution']['label'])->toBe('Recorded creator office')
        ->and($payload['status_attribution']['label'])->toBe('No status action yet');

    $this->actingAs($owner)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('taskStorageReady', true)
            ->where('taskRangeReady', true)
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Quarterly coordination')
            ->where('tasks.0.start_time', '09:15'));

    $this->actingAs($viewer)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

    $this->actingAs($viewer)->put(route('calendar.tasks.update', $task->id), [
        'title' => 'Unauthorized edit', 'scheduled_date' => '2026-11-04', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertNotFound();

    $this->actingAs($owner)->put(route('calendar.tasks.update', $task->id), [
        'title' => 'Updated coordination', 'scheduled_date' => '2026-11-04', 'is_all_day' => false,
        'start_time' => '13:00', 'end_time' => '13:30', 'notes' => 'Updated in Manila time.',
    ])->assertRedirect();

    expect($task->fresh()->title)->toBe('Updated coordination')
        ->and($task->fresh()->scheduled_date->format('Y-m-d'))->toBe('2026-11-04')
        ->and($task->fresh()->start_time)->toBe('13:00');

    $this->actingAs($owner)->patch(route('calendar.tasks.status', $task->id), ['status' => CalendarTask::STATUS_DONE])->assertRedirect();
    expect($task->fresh()->status)->toBe(CalendarTask::STATUS_DONE);
    $statusEvent = AuditLog::query()->where('entity_type', CalendarTask::class)->where('entity_id', (string) $task->id)->where('action', 'status_changed')->firstOrFail();
    expect($statusEvent->user_id)->toBe($owner->id)
        ->and($statusEvent->metadata['status_from'])->toBe(CalendarTask::STATUS_SCHEDULED)
        ->and($statusEvent->metadata['status_to'])->toBe(CalendarTask::STATUS_DONE);
    $donePayload = app(CalendarTaskService::class)->forRange($owner, Carbon\CarbonImmutable::parse('2026-11-01'), Carbon\CarbonImmutable::parse('2026-11-30'))->first();
    expect($donePayload['status_attribution']['actor_name'])->toBe($owner->name)
        ->and($donePayload['status_attribution']['label'])->toBe('Recorded status action')
        ->and($donePayload['status_attribution']['timestamp'])->toContain('+08:00');
    $this->actingAs($owner)->patch(route('calendar.tasks.status', $task->id), ['status' => CalendarTask::STATUS_CANCELLED])->assertForbidden();
    expect(AuditLog::query()->where('entity_type', CalendarTask::class)->where('entity_id', (string) $task->id)->count())->toBe(2);
});

test('year Calendar returns the private task range for the whole selected Manila year', function (): void {
    $owner = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $otherOwner = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $fixtures = [
        ['created_by' => $owner->id, 'title' => 'New Year all-day task', 'scheduled_date' => '2026-01-01', 'is_all_day' => true, 'start_time' => null, 'end_time' => null],
        ['created_by' => $owner->id, 'title' => 'May timed task', 'scheduled_date' => '2026-05-17', 'is_all_day' => false, 'start_time' => '09:15', 'end_time' => '10:00'],
        ['created_by' => $owner->id, 'title' => 'Year-end timed task', 'scheduled_date' => '2026-12-31', 'is_all_day' => false, 'start_time' => '23:15', 'end_time' => '23:45'],
        ['created_by' => $owner->id, 'title' => 'Previous year task', 'scheduled_date' => '2025-12-31', 'is_all_day' => true],
        ['created_by' => $owner->id, 'title' => 'Next year task', 'scheduled_date' => '2027-01-01', 'is_all_day' => true],
        ['created_by' => $otherOwner->id, 'title' => 'Another owner private task', 'scheduled_date' => '2026-05-18', 'is_all_day' => true],
    ];
    foreach ($fixtures as $fixture) {
        CalendarTask::query()->create($fixture + ['status' => CalendarTask::STATUS_SCHEDULED, 'notes' => '']);
    }

    NonWorkingDay::query()->create([
        'date' => '2026-05-18', 'name' => 'Year view fixture holiday',
        'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ]);

    $this->actingAs($owner)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026, 'month' => '2026-05']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Calendar/Index')
            ->where('view', 'year')
            ->where('year', 2026)
            ->where('taskStorageReady', true)
            ->where('taskRangeReady', true)
            ->has('tasks', 3)
            ->where('tasks.0.title', 'New Year all-day task')
            ->where('tasks.0.scheduled_date', '2026-01-01')
            ->where('tasks.0.is_all_day', true)
            ->where('tasks.0.start_time', null)
            ->where('tasks.1.title', 'May timed task')
            ->where('tasks.1.scheduled_date', '2026-05-17')
            ->where('tasks.1.start_time', '09:15')
            ->where('tasks.2.title', 'Year-end timed task')
            ->where('tasks.2.scheduled_date', '2026-12-31')
            ->where('tasks.2.start_time', '23:15')
            ->has('nonWorkingDays', 1)
            ->has('yearSummary.overview'));

    $this->actingAs($otherOwner)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('taskStorageReady', true)
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Another owner private task'));
});

test('invalid task dates and time ranges are rejected before storage', function (): void {
    $user = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);

    $this->actingAs($user)->post(route('calendar.tasks.store'), [
        'title' => 'Invalid date', 'scheduled_date' => '2026-02-30', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertSessionHasErrors('scheduled_date');

    $this->actingAs($user)->post(route('calendar.tasks.store'), [
        'title' => 'Invalid time', 'scheduled_date' => '2026-11-03', 'is_all_day' => false,
        'start_time' => '25:00', 'end_time' => '25:30', 'notes' => '',
    ])->assertSessionHasErrors(['start_time', 'end_time']);

    $this->actingAs($user)->post(route('calendar.tasks.store'), [
        'title' => 'Invalid range', 'scheduled_date' => '2026-11-03', 'is_all_day' => false,
        'start_time' => '10:00', 'end_time' => '09:59', 'notes' => '',
    ])->assertSessionHasErrors('end_time');

    $this->actingAs($user)->post(route('calendar.tasks.store'), [
        'title' => 'Reverse date range', 'scheduled_date' => '2026-11-04', 'scheduled_end_date' => '2026-11-03',
        'is_all_day' => true, 'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertSessionHasErrors('scheduled_end_date');

    $this->actingAs($user)->post(route('calendar.tasks.store'), [
        'title' => 'Cross-day task', 'scheduled_date' => '2026-11-03', 'scheduled_end_date' => '2026-11-04', 'is_all_day' => false,
        'start_time' => '23:30', 'end_time' => '00:15', 'notes' => '',
    ])->assertRedirect();
    $this->actingAs($user)->post(route('calendar.tasks.store'), [
        'title' => 'Equal combined datetime', 'scheduled_date' => '2026-11-03', 'scheduled_end_date' => '2026-11-03', 'is_all_day' => false,
        'start_time' => '10:00', 'end_time' => '10:00', 'notes' => '',
    ])->assertSessionHasErrors('end_time');

    expect(CalendarTask::query()->count())->toBe(1);
});

test('legacy update payloads preserve retained task ranges and reject a start date after the retained end', function (): void {
    $owner = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $task = CalendarTask::query()->create([
        'created_by' => $owner->id,
        'title' => 'Existing range',
        'scheduled_date' => '2026-11-03',
        'scheduled_end_date' => '2026-11-05',
        'is_all_day' => true,
        'start_time' => null,
        'end_time' => null,
        'notes' => 'Keep this range when an older client omits the end date.',
        'status' => CalendarTask::STATUS_SCHEDULED,
    ]);

    $this->actingAs($owner)->put(route('calendar.tasks.update', $task->id), [
        'title' => 'Edited by legacy client',
        'scheduled_date' => '2026-11-04',
        'is_all_day' => true,
        'start_time' => '',
        'end_time' => '',
        'notes' => 'The omitted end date must remain stored.',
    ])->assertRedirect();

    expect($task->fresh()->scheduled_end_date->format('Y-m-d'))->toBe('2026-11-05');

    $overnight = CalendarTask::query()->create([
        'created_by' => $owner->id,
        'title' => 'Existing overnight range',
        'scheduled_date' => '2026-11-03',
        'scheduled_end_date' => '2026-11-04',
        'is_all_day' => false,
        'start_time' => '23:30',
        'end_time' => '00:15',
        'notes' => '',
        'status' => CalendarTask::STATUS_SCHEDULED,
    ]);

    $this->actingAs($owner)->put(route('calendar.tasks.update', $overnight->id), [
        'title' => 'Edited overnight by legacy client',
        'scheduled_date' => '2026-11-03',
        'is_all_day' => false,
        'start_time' => '23:30',
        'end_time' => '00:15',
        'notes' => 'Keep the retained next-day end date for time validation.',
    ])->assertRedirect();

    expect($overnight->fresh()->scheduled_end_date->format('Y-m-d'))->toBe('2026-11-04');

    $this->actingAs($owner)->put(route('calendar.tasks.update', $task->id), [
        'title' => 'Invalid truncated range',
        'scheduled_date' => '2026-11-06',
        'is_all_day' => true,
        'start_time' => '',
        'end_time' => '',
        'notes' => '',
    ])->assertSessionHasErrors('scheduled_end_date');

    expect($task->fresh()->title)->toBe('Edited by legacy client')
        ->and($task->fresh()->scheduled_date->format('Y-m-d'))->toBe('2026-11-04')
        ->and($task->fresh()->scheduled_end_date->format('Y-m-d'))->toBe('2026-11-05');
});

test('date ranges overlap visible months and years while legacy single dates remain valid without range storage', function (): void {
    $owner = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $this->actingAs($owner)->post(route('calendar.tasks.store'), [
        'title' => 'Cross-month all-day work', 'scheduled_date' => '2026-10-01', 'scheduled_end_date' => '2026-11-02',
        'is_all_day' => true, 'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertRedirect();

    $this->actingAs($owner)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->where('taskRangeReady', true)->has('tasks', 1)->where('tasks.0.scheduled_end_date', '2026-11-02'));
    $this->actingAs($owner)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026]))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
    expect(Schema::getColumnType('calendar_tasks', 'scheduled_end_date'))->toBe('date');

    Schema::table('calendar_tasks', fn ($table) => $table->dropColumn('scheduled_end_date'));
    try {
        $this->actingAs($owner)->get(route('business-calendar.index', ['month' => '2026-11']))
            ->assertInertia(fn (Assert $page) => $page->where('taskStorageReady', true)->where('taskRangeReady', false)->has('tasks', 0));
        $this->actingAs($owner)->post(route('calendar.tasks.store'), [
            'title' => 'Legacy single date', 'scheduled_date' => '2026-11-05', 'is_all_day' => true,
            'start_time' => '', 'end_time' => '', 'notes' => '',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('calendar.tasks.store'), [
            'title' => 'Unavailable range', 'scheduled_date' => '2026-11-06', 'scheduled_end_date' => '2026-11-07', 'is_all_day' => true,
            'start_time' => '', 'end_time' => '', 'notes' => '',
        ])->assertSessionHasErrors('scheduled_end_date');
        expect(CalendarTask::query()->where('title', 'Legacy single date')->exists())->toBeTrue();

        $legacy = CalendarTask::query()->create([
            'created_by' => $owner->id, 'title' => 'Pre-attribution task', 'scheduled_date' => '2026-11-08',
            'is_all_day' => true, 'start_time' => null, 'end_time' => null, 'notes' => '', 'status' => CalendarTask::STATUS_SCHEDULED,
        ]);
        $legacyPayload = app(CalendarTaskService::class)->forRange($owner, Carbon\CarbonImmutable::parse('2026-11-01'), Carbon\CarbonImmutable::parse('2026-11-30'))->firstWhere('id', $legacy->id);
        expect($legacyPayload['creator_attribution']['label'])->toBe('Creator current office')
            ->and($legacyPayload['status_attribution']['label'])->toBe('No status action yet');
        $legacy->update(['status' => CalendarTask::STATUS_DONE]);
        $terminalPayload = app(CalendarTaskService::class)->forRange($owner, Carbon\CarbonImmutable::parse('2026-11-01'), Carbon\CarbonImmutable::parse('2026-11-30'))->firstWhere('id', $legacy->id);
        expect($terminalPayload['status_attribution']['label'])->toBe('Action details not recorded');
    } finally {
        Schema::table('calendar_tasks', fn ($table) => $table->date('scheduled_end_date')->nullable()->after('scheduled_date'));
    }
});

test('Calendar task create rolls back if its audit record cannot be written', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') $this->markTestSkipped('This focused rollback test uses an isolated SQLite trigger.');
    $user = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    DB::statement("CREATE TRIGGER calendar_task_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.module = 'Calendar' AND NEW.action = 'created' BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");
    try {
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post(route('calendar.tasks.store'), [
                'title' => 'Atomic task fixture', 'scheduled_date' => '2026-11-10', 'is_all_day' => true,
                'start_time' => '', 'end_time' => '', 'notes' => '',
            ]);
            $this->fail('The injected audit write failure should abort task creation.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('forced audit failure');
        }
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS calendar_task_audit_failure');
    }

    expect(CalendarTask::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('Calendar task attribution and audit reads stay bounded as the visible task set grows', function (): void {
    $owner = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $addTask = static fn (int $index) => CalendarTask::query()->create([
        'created_by' => $owner->id, 'title' => 'Bounded query fixture '.$index, 'scheduled_date' => '2026-11-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
        'is_all_day' => true, 'start_time' => null, 'end_time' => null, 'notes' => '', 'status' => CalendarTask::STATUS_SCHEDULED,
    ]);
    $addTask(0);
    $service = app(CalendarTaskService::class);
    $range = [Carbon\CarbonImmutable::parse('2026-11-01'), Carbon\CarbonImmutable::parse('2026-11-30')];
    $service->forRange($owner, ...$range); // warm the same service/profile path before measuring
    $capture = false;
    $queryCount = 0;
    DB::listen(static function (QueryExecuted $query) use (&$capture, &$queryCount): void {
        if ($capture) $queryCount++;
    });
    $capture = true;
    $service->forRange($owner, ...$range);
    $singleCount = $queryCount;
    $capture = false;
    for ($index = 1; $index < 12; $index++) $addTask($index);
    $queryCount = 0;
    $capture = true;
    $result = $service->forRange($owner, ...$range);
    $capture = false;

    expect($result)->toHaveCount(12)
        ->and($queryCount)->toBe($singleCount);
});

test('tasks do not alter non-working days, business-day calculations, report rows, or mail delivery', function (): void {
    NonWorkingDay::query()->create([
        'date' => '2026-11-03', 'name' => 'Existing calendar fixture',
        'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ]);
    $user = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $calendar = app(BusinessCalendarService::class);
    $calendar->forgetCache();
    $daysBefore = $calendar->workingDaysBetween('2026-11-02', '2026-11-05');
    $holidaysBefore = NonWorkingDay::query()->count();
    $reportsBefore = TechnicalReport::query()->count();

    $this->actingAs($user)->post(route('calendar.tasks.store'), [
        'title' => 'Task on an existing holiday', 'scheduled_date' => '2026-11-03', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertRedirect();

    $calendar->forgetCache();
    expect(NonWorkingDay::query()->count())->toBe($holidaysBefore)
        ->and($calendar->workingDaysBetween('2026-11-02', '2026-11-05'))->toBe($daysBefore)
        ->and(TechnicalReport::query()->count())->toBe($reportsBefore)
        ->and(CalendarTask::query()->count())->toBe(1);
    Mail::assertNothingOutgoing();
});

test('a missing task table leaves the existing report Calendar available', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    Schema::dropIfExists('calendar_tasks');

    try {
        $this->actingAs($viewer)->get(route('business-calendar.index', ['month' => '2026-11']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->where('taskStorageReady', false)
                ->has('tasks', 0)
                ->has('nonWorkingDays'));
        $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('taskStorageReady', false)
                ->has('tasks', 0)
                ->has('nonWorkingDays'));
    } finally {
        if (! Schema::hasTable('calendar_tasks')) {
            (require base_path('database/migrations/2026_11_01_190000_create_calendar_tasks_table.php'))->up();
        }
    }
});
