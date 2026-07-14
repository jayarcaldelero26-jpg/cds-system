<?php

use App\Models\ProtectedArea;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['CDS Admin', 'Technical Staff', 'Viewer'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    foreach (['protected-areas.view', 'protected-areas.create', 'protected-areas.update', 'protected-areas.delete'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findByName('CDS Admin')->syncPermissions(['protected-areas.view', 'protected-areas.create', 'protected-areas.update', 'protected-areas.delete']);
    Role::findByName('Technical Staff')->syncPermissions(['protected-areas.view', 'protected-areas.create', 'protected-areas.update']);
    Role::findByName('Viewer')->syncPermissions(['protected-areas.view']);
});

function protectedAreaPayload(array $overrides = []): array
{
    return [...[
        'name' => 'Mati Protected Landscape', 'short_name' => 'MPL', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'area_hectares' => 1200.50, 'pamo' => 'Mati PAMO', 'pasu' => 'Juan Dela Cruz',
        'year_established' => 2002, 'legal_basis' => 'Proclamation No. 123',
        'description' => 'A protected landscape.', 'status' => 'Active', 'remarks' => 'Verified record.',
    ], ...$overrides];
}

test('technical staff can create and update protected areas with audit users', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Technical Staff');

    $this->actingAs($staff)->post(route('protected-areas.store'), protectedAreaPayload())
        ->assertRedirect(route('protected-areas.index'));

    $area = ProtectedArea::firstOrFail();
    expect($area->created_by)->toBe($staff->id)->and($area->updated_by)->toBe($staff->id);

    $this->patch(route('protected-areas.update', $area), protectedAreaPayload(['name' => 'Updated Protected Landscape']))
        ->assertRedirect(route('protected-areas.index'));

    expect($area->fresh()->name)->toBe('Updated Protected Landscape')->and($area->fresh()->updated_by)->toBe($staff->id);
});

test('viewer can view but cannot modify protected areas', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $area = ProtectedArea::create([...protectedAreaPayload(), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $viewer = User::factory()->create();
    $viewer->assignRole('Viewer');

    $this->actingAs($viewer)->get(route('protected-areas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ProtectedAreas/Index')->has('protectedAreas.data', 1));
    $this->get(route('protected-areas.create'))->assertForbidden();
    $this->delete(route('protected-areas.destroy', $area))->assertForbidden();
});

test('admin soft deletes protected areas and search filters records', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $area = ProtectedArea::create([...protectedAreaPayload(), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    ProtectedArea::create([...protectedAreaPayload(['name' => 'Coastal Sanctuary', 'municipality' => 'Baganga']), 'created_by' => $admin->id, 'updated_by' => $admin->id]);

    $this->actingAs($admin)->get(route('protected-areas.index', ['search' => 'Baganga']))
        ->assertInertia(fn (Assert $page) => $page->has('protectedAreas.data', 1)->where('protectedAreas.data.0.name', 'Coastal Sanctuary'));
    $this->delete(route('protected-areas.destroy', $area))->assertRedirect(route('protected-areas.index'));
    $this->assertSoftDeleted('protected_areas', ['id' => $area->id]);
});

test('dashboard reports the protected area total and authorized navigation routes are reachable', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Technical Staff');
    ProtectedArea::create([...protectedAreaPayload(), 'created_by' => $staff->id, 'updated_by' => $staff->id]);

    $this->actingAs($staff)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('protectedAreasCount', 1));

    $this->get(route('protected-areas.index'))->assertOk();
    $this->get(route('protected-areas.create'))->assertOk();
});
