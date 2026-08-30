<?php

use App\Models\NonWorkingDay;
use App\Models\BmsReportSubmission;
use App\Models\BamsReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\BusinessCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
});

test('a reports viewer can open Calendar but cannot mutate non-working days', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));

    $this->actingAs($viewer)->get(route('business-calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Calendar/Index')->has('nonWorkingDays'));

    $this->actingAs($viewer)->post(route('compliance-alerts.non-working-days.store'), [
        'date' => '2026-08-26', 'name' => 'Unauthorized holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ])->assertForbidden();
});

test('an authorized calendar manager can create, edit, deactivate, and delete a configured day', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('compliance-alerts.manage', 'web'),
    ]);

    $this->actingAs($manager)->post(route('compliance-alerts.non-working-days.store'), [
        'date' => '2026-08-26', 'name' => 'Local Foundation Day', 'type' => NonWorkingDay::TYPE_LOCAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_DAVAO_ORIENTAL, 'reference' => 'Memo 1', 'remarks' => 'Annual event', 'is_active' => true,
    ])->assertRedirect()->assertSessionHas('success');

    $day = NonWorkingDay::query()->firstOrFail();
    expect($day->name)->toBe('Local Foundation Day')
        ->and(app(BusinessCalendarService::class)->isWorkingDay('2026-08-26'))->toBeFalse();

    $this->actingAs($manager)->put(route('compliance-alerts.non-working-days.update', $day), [
        'date' => '2026-08-26', 'name' => 'Updated Foundation Day', 'type' => NonWorkingDay::TYPE_OTHER,
        'scope' => NonWorkingDay::SCOPE_DAVAO_ORIENTAL, 'reference' => null, 'remarks' => null, 'is_active' => false,
    ])->assertRedirect()->assertSessionHas('success');

    expect($day->fresh()->name)->toBe('Updated Foundation Day')
        ->and($day->fresh()->is_active)->toBeFalse()
        ->and(app(BusinessCalendarService::class)->isWorkingDay('2026-08-26'))->toBeTrue();

    $this->actingAs($manager)->delete(route('compliance-alerts.non-working-days.destroy', $day))
        ->assertRedirect()->assertSessionHas('success');

    expect(NonWorkingDay::query()->find($day->id))->toBeNull();
});

test('duplicate configured dates for the same scope and location are rejected by the calendar endpoint', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo(Permission::findOrCreate('compliance-alerts.manage', 'web'));
    $payload = [
        'date' => '2026-08-26', 'name' => 'Holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ];

    $this->actingAs($manager)->post(route('compliance-alerts.non-working-days.store'), $payload)->assertRedirect();
    $this->actingAs($manager)->post(route('compliance-alerts.non-working-days.store'), $payload)
        ->assertSessionHasErrors('date');
});

test('calendar projects submitted reports by actual submission month and combines module and protected area filters', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
    ]);
    $otherUser = User::factory()->create();
    $area = ProtectedArea::query()->create(['name' => 'Mount Hamiguitan Range Wildlife Sanctuary', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $otherUser->id, 'updated_by' => $otherUser->id]);
    $otherArea = ProtectedArea::query()->create(['name' => 'Pujada Bay Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $otherUser->id, 'updated_by' => $otherUser->id]);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'activity_name' => 'BMS Report', 'date_accomplished' => '2026-07-30', 'date_received_penro' => '2026-08-20']);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'activity_name' => 'Outside Month', 'date_received_penro' => '2026-07-31']);
    BmsReportSubmission::query()->create(['protected_area_id' => $otherArea->id, 'semester' => '1st Semester', 'activity_name' => 'Other PA', 'date_received_penro' => '2026-08-21']);

    $this->actingAs($viewer)->get(route('business-calendar.index', ['month' => '2026-08', 'module' => 'bms', 'protected_area_id' => $area->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Calendar/Index')
            ->where('month', '2026-08')
            ->where('filters.module', 'bms')
            ->where('filters.protected_area_id', $area->id)
            ->has('movEvents', 1)
            ->where('movEvents.0.submission_date', '2026-08-20')
            ->where('movEvents.0.date_accomplished', '2026-07-30')
            ->where('movEvents.0.protected_area_name', $area->name));
});

test('calendar year view groups permitted submissions by month and respects protected area and module filters', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
        Permission::findOrCreate('bams.view', 'web'),
    ]);
    $owner = User::factory()->create();
    $area = ProtectedArea::query()->create(['name' => 'Mount Hamiguitan Range Wildlife Sanctuary', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
    $otherArea = ProtectedArea::query()->create(['name' => 'Pujada Bay Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-20']);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '2nd Semester', 'date_received_penro' => '2026-09-10']);
    BamsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-21']);
    BmsReportSubmission::query()->create(['protected_area_id' => $otherArea->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-22']);
    NonWorkingDay::query()->create(['date' => '2026-08-21', 'name' => 'Foundation Day', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);

    $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026, 'protected_area_id' => $area->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Calendar/Index')->where('view', 'year')->where('year', 2026)->has('yearSummary.months', 12)->where('yearSummary.months.08.submitted_movs', 2)->where('yearSummary.months.08.modules.0.key', 'bams')->where('yearSummary.months.08.modules.0.count', 1)->where('yearSummary.months.08.modules.1.key', 'bms')->where('yearSummary.months.08.modules.1.count', 1)->where('yearSummary.months.08.days.20.0.source_type', 'bms')->where('yearSummary.months.08.days.21.0.source_type', 'bams')->where('yearSummary.months.09.submitted_movs', 1)->where('yearSummary.overview.submitted_movs', 3)->where('yearSummary.overview.active_modules', 2)->where('yearSummary.overview.months_with_submissions', 2)->where('yearSummary.overview.non_working_days', 1));

    $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026, 'protected_area_id' => $area->id, 'module' => 'bms']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('yearSummary.months.08.submitted_movs', 1)->where('yearSummary.months.08.days.20.0.source_type', 'bms')->missing('yearSummary.months.08.days.21')->where('yearSummary.months.09.submitted_movs', 1)->where('yearSummary.overview.submitted_movs', 2)->where('yearSummary.overview.active_modules', 1));

    $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'month', 'month' => '2026-08', 'protected_area_id' => $area->id, 'module' => 'bms']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('view', 'month')->where('month', '2026-08')->where('filters.module', 'bms')->where('filters.protected_area_id', $area->id)->has('movEvents', 1));
});
