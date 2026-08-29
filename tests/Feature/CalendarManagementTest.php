<?php

use App\Models\NonWorkingDay;
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
