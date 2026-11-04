<?php

use App\Models\CalendarTask;
use App\Models\AuditLog;
use App\Models\NonWorkingDay;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Calendar\CalendarTaskAudienceService;
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

function makeCalendarOfficeShareUser(string $office, string $category = OrganizationalAccessService::CENRO_FOCAL): User
{
    return User::factory()->create(['section' => $category, 'office_designated' => $office]);
}

/** @return array{User,ProtectedArea,OrganizationalOffice} */
function makeCalendarPamoShareFixture(string $areaName, string $shortName, string $officeCode, ?string $supervisingOfficeCode = 'cenro_baganga'): array
{
    $creator = User::factory()->create(['section' => OrganizationalAccessService::PAMO]);
    $pamoOffice = OrganizationalOffice::query()->create([
        'code' => $officeCode,
        'name' => $officeCode.' Office',
        'office_type' => 'pamo',
        'is_active' => true,
    ]);
    $area = ProtectedArea::query()->create([
        'name' => $areaName,
        'short_name' => $shortName,
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $creator->getKey(),
        'updated_by' => $creator->getKey(),
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->getKey(),
        'organizational_office_id' => $pamoOffice->getKey(),
        'assignment_type' => 'pamo',
        'assigned_by' => $creator->getKey(),
    ]);
    if ($supervisingOfficeCode) {
        $supervisingOffice = OrganizationalOffice::query()->where('code', $supervisingOfficeCode)->firstOrFail();
        ProtectedAreaOfficeAssignment::query()->create([
            'protected_area_id' => $area->getKey(),
            'organizational_office_id' => $supervisingOffice->getKey(),
            'assignment_type' => 'supervising',
            'assigned_by' => $creator->getKey(),
        ]);
    }
    $creator->forceFill(['protected_area_id' => $area->getKey(), 'office_designated' => $pamoOffice->name])->save();

    return [$creator->fresh(), $area, $pamoOffice];
}

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
    ])->assertSessionHasErrors('created_by');

    $this->actingAs($owner)->post(route('calendar.tasks.store'), [
        'title' => 'Quarterly coordination', 'scheduled_date' => '2026-11-03', 'is_all_day' => false,
        'start_time' => '09:15', 'end_time' => '10:00', 'notes' => 'Local wall-clock schedule.',
    ])->assertRedirect();

    $task = CalendarTask::query()->firstOrFail();
    expect($task->created_by)->toBe($owner->id)
        ->and($task->visibility)->toBe('private')
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

test('office sharing is explicit, office-scoped, read-only for peers, and immutable on creator edits', function (): void {
    $owner = makeCalendarOfficeShareUser('CENRO Baganga');
    $peer = makeCalendarOfficeShareUser('CENRO Baganga', OrganizationalAccessService::CENRO_CHIEF);
    $otherOffice = makeCalendarOfficeShareUser('CENRO Mati');
    $global = User::factory()->create();
    $global->assignRole(Role::findOrCreate('Super Admin', 'web'));

    $this->actingAs($owner)->post(route('calendar.tasks.store'), [
        'title' => 'Office coordination', 'scheduled_date' => '2026-11-12', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true,
    ])->assertRedirect();

    $task = CalendarTask::query()->where('title', 'Office coordination')->firstOrFail();
    $audience = DB::table('calendar_task_audiences')->where('calendar_task_id', $task->id)->first();
    $created = AuditLog::query()->where('entity_type', CalendarTask::class)->where('entity_id', (string) $task->id)->where('action', 'created')->firstOrFail();
    expect($task->visibility)->toBe('shared')
        ->and($audience->audience_type)->toBe('office')
        ->and($audience->office_name_snapshot)->toBe('CENRO Baganga')
        ->and($created->metadata['calendar_task_sharing']['choice'])->toBe('shared_with_office')
        ->and($created->metadata['calendar_task_sharing']['audiences'][0]['office_name'])->toBe('CENRO Baganga');

    $this->actingAs($peer)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)
            ->where('tasks.0.visibility', 'shared')
            ->where('tasks.0.is_owner', false)
            ->where('tasks.0.can_edit', false)
            ->where('tasks.0.can_complete', false)
            ->where('tasks.0.can_cancel', false)
            ->where('tasks.0.audiences.0.label', 'CENRO Baganga'));
    $otherResponse = $this->actingAs($otherOffice)->get(route('business-calendar.index', ['month' => '2026-11']));
    $otherResponse->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
    $globalResponse = $this->actingAs($global)->get(route('business-calendar.index', ['month' => '2026-11']));
    $globalResponse->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

    $policy = app(\App\Policies\CalendarTaskPolicy::class);
    expect($policy->view($peer, $task))->toBeTrue()
        ->and($policy->update($peer, $task))->toBeFalse()
        ->and($policy->complete($peer, $task))->toBeFalse()
        ->and($policy->cancel($peer, $task))->toBeFalse();
    $this->actingAs($peer)->put(route('calendar.tasks.update', $task->id), [
        'title' => 'Peer edit', 'scheduled_date' => '2026-11-13', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertNotFound();
    $this->actingAs($peer)->patch(route('calendar.tasks.status', $task->id), ['status' => CalendarTask::STATUS_DONE])->assertNotFound();
    $this->actingAs($owner)->put(route('calendar.tasks.update', $task->id), [
        'title' => 'Attempt to change audience', 'scheduled_date' => '2026-11-13', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => false,
    ])->assertSessionHasErrors('share_with_office');
    $this->actingAs($owner)->put(route('calendar.tasks.update', $task->id), [
        'title' => 'Edited without changing audience', 'scheduled_date' => '2026-11-13', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertRedirect();
    expect($task->fresh()->visibility)->toBe('shared')
        ->and(DB::table('calendar_task_audiences')->where('calendar_task_id', $task->id)->count())->toBe(1);
});

test('PAMO sharing stays within the assigned Protected Area and adds only an explicitly selected mapped supervisor', function (): void {
    [$pamo, $area, $pamoOffice] = makeCalendarPamoShareFixture('Aliwagwag Protected Landscape', 'APL', 'pamo_apl_share', 'cenro_baganga');
    [$otherPamo, $otherArea] = makeCalendarPamoShareFixture('Mati Protected Landscape', 'MPL', 'pamo_mpl_share', 'cenro_mati');
    $samePaPeer = User::factory()->create([
        'section' => OrganizationalAccessService::PAMO,
        'protected_area_id' => $area->id,
        'office_designated' => $pamoOffice->name,
    ]);
    $baganga = makeCalendarOfficeShareUser('CENRO Baganga');
    $mati = makeCalendarOfficeShareUser('CENRO Mati');

    $this->actingAs($pamo)->post(route('calendar.tasks.store'), [
        'title' => 'PA team schedule', 'scheduled_date' => '2026-11-14', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true,
    ])->assertRedirect();
    $primaryOnly = CalendarTask::query()->where('title', 'PA team schedule')->firstOrFail();
    $this->actingAs($samePaPeer)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.audiences.0.label', 'pamo_apl_share Office — Aliwagwag Protected Landscape'));
    $this->actingAs($otherPamo)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
    $this->actingAs($baganga)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

    $this->actingAs($pamo)->post(route('calendar.tasks.store'), [
        'title' => 'PA and supervisor schedule', 'scheduled_date' => '2026-11-15', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true, 'include_supervising_office' => true,
    ])->assertRedirect();
    $this->actingAs($baganga)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)
            ->where('tasks.0.title', 'PA and supervisor schedule')
            ->has('tasks.0.audiences', 2));
    $this->actingAs($mati)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

    [$withoutSupervisor] = makeCalendarPamoShareFixture('Example Protected Area', 'EPA', 'pamo_without_supervisor', null);
    $options = app(CalendarTaskAudienceService::class)->sharingOptions($withoutSupervisor);
    expect($options['available'])->toBeTrue()->and($options['supervising'])->toBeNull();
    $this->actingAs($withoutSupervisor)->post(route('calendar.tasks.store'), [
        'title' => 'Independent PA task', 'scheduled_date' => '2026-11-16', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true,
    ])->assertRedirect();
    $this->actingAs($withoutSupervisor)->post(route('calendar.tasks.store'), [
        'title' => 'Invalid supervisor choice', 'scheduled_date' => '2026-11-17', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true, 'include_supervising_office' => true,
    ])->assertSessionHasErrors('include_supervising_office');

    [$directPamo] = makeCalendarPamoShareFixture('Mt. Hamiguitan Range Wildlife Sanctuary', 'MHRWS', 'pamo_mhrws_share', 'penro_davao_oriental');
    $directOptions = app(CalendarTaskAudienceService::class)->sharingOptions($directPamo);
    expect($directOptions['supervising']['label'])->toContain('PENRO Davao Oriental');
    $this->actingAs($directPamo)->post(route('calendar.tasks.store'), [
        'title' => 'Direct PA supervisor schedule', 'scheduled_date' => '2026-11-18', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true, 'include_supervising_office' => true,
    ])->assertRedirect();
    $penro = makeCalendarOfficeShareUser('PENRO Davao Oriental', OrganizationalAccessService::PENRO_FOCAL);
    $this->actingAs($penro)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->where('tasks.0.title', 'Direct PA supervisor schedule'));

    expect($primaryOnly->fresh()->visibility)->toBe('shared');
});

test('a cross-year shared range is returned once only inside its office audience', function (): void {
    $owner = makeCalendarOfficeShareUser('CENRO Baganga');
    $peer = makeCalendarOfficeShareUser('CENRO Baganga', OrganizationalAccessService::CENRO_CHIEF);
    $other = makeCalendarOfficeShareUser('CENRO Mati');
    $payload = [
        'title' => 'Year-boundary office task', 'scheduled_date' => '2025-12-30', 'scheduled_end_date' => '2026-01-03',
        'is_all_day' => true, 'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true,
    ];
    $this->actingAs($owner)->post(route('calendar.tasks.store'), $payload)->assertRedirect();
    $this->actingAs($other)->post(route('calendar.tasks.store'), [...$payload, 'title' => 'Other office task'])->assertRedirect();

    foreach ([['month' => '2025-12'], ['month' => '2026-01'], ['view' => 'year', 'year' => 2025], ['view' => 'year', 'year' => 2026]] as $range) {
        $this->actingAs($peer)->get(route('business-calendar.index', $range))
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.title', 'Year-boundary office task'));
    }
});

test('creator fallback attribution is correct for each shared task in a multi-owner feed with bounded queries', function (): void {
    $viewer = makeCalendarOfficeShareUser('CENRO Baganga');
    $office = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
    $audienceService = app(CalendarTaskAudienceService::class);
    $creators = collect([
        makeCalendarOfficeShareUser('CENRO Baganga'),
        makeCalendarOfficeShareUser('CENRO Baganga', OrganizationalAccessService::CENRO_CHIEF),
        makeCalendarOfficeShareUser('CENRO Baganga', OrganizationalAccessService::CENRO_RECORDS),
    ]);
    $addCreatorTasks = static function (User $creator, int $creatorIndex, array $indexes) use ($audienceService): void {
        foreach ($indexes as $index) {
            $task = CalendarTask::query()->create([
                'created_by' => $creator->id,
                'title' => 'Fallback owner '.$creator->name.' '.$index,
                'scheduled_date' => '2026-11-'.str_pad((string) (10 + $creatorIndex * 3 + $index), 2, '0', STR_PAD_LEFT),
                'is_all_day' => true, 'start_time' => null, 'end_time' => null, 'notes' => '',
                'status' => CalendarTask::STATUS_SCHEDULED,
            ]);
            $grant = $audienceService->createAudiences($creator, true, false);
            $task->forceFill(['visibility' => 'shared'])->save();
            $audienceService->persistAudiences($task, $grant);
        }
    };
    foreach ($creators as $creatorIndex => $creator) {
        $addCreatorTasks($creator, $creatorIndex, [0]);
    }

    $service = app(CalendarTaskService::class);
    $range = [Carbon\CarbonImmutable::parse('2026-11-01'), Carbon\CarbonImmutable::parse('2026-11-30')];
    $capture = false;
    $queryCount = 0;
    DB::listen(static function (QueryExecuted $query) use (&$capture, &$queryCount): void {
        if ($capture) $queryCount++;
    });
    $capture = true;
    $service->forRange($viewer, ...$range);
    $threeCreatorCount = $queryCount;
    $capture = false;
    foreach ($creators as $creatorIndex => $creator) {
        $addCreatorTasks($creator, $creatorIndex, [1, 2]);
    }
    $queryCount = 0;
    $capture = true;
    $tasks = $service->forRange($viewer, ...$range);
    $capture = false;

    expect($tasks)->toHaveCount(9)
        ->and($tasks->pluck('id')->unique())->toHaveCount(9)
        ->and($tasks->pluck('creator_attribution.actor_name')->unique())->toHaveCount(3)
        ->and($tasks->every(fn (array $task): bool => $task['creator_attribution']['office_name'] === $office->name))->toBeTrue()
        ->and($queryCount)->toBeLessThanOrEqual($threeCreatorCount)
        ->and($queryCount)->toBeLessThan(25);
});

test('sharing stays unavailable while audience schema is missing and old tasks remain private after the additive migration', function (): void {
    $owner = makeCalendarOfficeShareUser('CENRO Baganga');
    $peer = makeCalendarOfficeShareUser('CENRO Baganga');
    $migration = require base_path('database/migrations/2026_11_03_120000_add_office_sharing_to_calendar_tasks.php');
    $migration->down();
    $legacy = CalendarTask::query()->create([
        'created_by' => $owner->id, 'title' => 'Pre-sharing task', 'scheduled_date' => '2026-11-20',
        'is_all_day' => true, 'start_time' => null, 'end_time' => null, 'notes' => '', 'status' => CalendarTask::STATUS_SCHEDULED,
    ]);
    $migration->up();

    expect($legacy->fresh()->visibility)->toBe('private')
        ->and(DB::table('calendar_task_audiences')->count())->toBe(0);
    $options = app(CalendarTaskAudienceService::class)->sharingOptions($owner);
    expect($options['ready'])->toBeTrue()->and($options['available'])->toBeTrue();
    $this->actingAs($peer)->get(route('business-calendar.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

    Schema::dropIfExists('calendar_task_audiences');
    expect(app(CalendarTaskAudienceService::class)->sharingOptions($owner)['ready'])->toBeFalse();
    $this->actingAs($owner)->post(route('calendar.tasks.store'), [
        'title' => 'Legacy private save', 'scheduled_date' => '2026-11-21', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '',
    ])->assertRedirect();
    $this->actingAs($owner)->post(route('calendar.tasks.store'), [
        'title' => 'No silent shared save', 'scheduled_date' => '2026-11-22', 'is_all_day' => true,
        'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true,
    ])->assertSessionHasErrors('share_with_office');
});

test('a failed creation audit rolls back the task and its audience rows', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') $this->markTestSkipped('This focused rollback test uses an isolated SQLite trigger.');
    $owner = makeCalendarOfficeShareUser('CENRO Baganga');
    DB::statement("CREATE TRIGGER calendar_task_share_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.module = 'Calendar' AND NEW.action = 'created' BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");
    try {
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($owner)->post(route('calendar.tasks.store'), [
                'title' => 'Atomic share fixture', 'scheduled_date' => '2026-11-23', 'is_all_day' => true,
                'start_time' => '', 'end_time' => '', 'notes' => '', 'share_with_office' => true,
            ]);
            $this->fail('The injected audit write failure should abort shared task creation.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('forced audit failure');
        }
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS calendar_task_share_audit_failure');
    }

    expect(CalendarTask::query()->where('title', 'Atomic share fixture')->exists())->toBeFalse()
        ->and(DB::table('calendar_task_audiences')->count())->toBe(0)
        ->and(AuditLog::query()->where('entity_type', CalendarTask::class)->count())->toBe(0);
});
