<?php

use App\Models\ManagementPlan;
use App\Models\ProtectedArea;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['CDS Admin', 'Technical Staff', 'Viewer'] as $role) Role::findOrCreate($role, 'web');
    foreach (['management-plans.view', 'management-plans.create', 'management-plans.update', 'management-plans.delete'] as $permission) Permission::findOrCreate($permission, 'web');
    Role::findByName('CDS Admin')->syncPermissions(['management-plans.view', 'management-plans.create', 'management-plans.update', 'management-plans.delete']);
    Role::findByName('Technical Staff')->syncPermissions(['management-plans.view', 'management-plans.create', 'management-plans.update']);
    Role::findByName('Viewer')->syncPermissions(['management-plans.view']);
});

function managementPlanArea(User $user): ProtectedArea
{
    return ProtectedArea::create(['name' => 'Mt. Hamiguitan', 'category' => 'Natural Park', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $user->id, 'updated_by' => $user->id]);
}

function managementPlanPayload(ProtectedArea $area, array $overrides = []): array
{
    return [...['protected_area_id' => $area->id, 'plan_type' => 'PAMP', 'title' => 'Mt. Hamiguitan PAMP', 'version' => 'Version 1', 'prepared_year' => 2015, 'approval_date' => '2015-01-15', 'valid_from' => '2015-01-01', 'valid_until' => '2024-12-31', 'status' => 'Expired', 'remarks' => 'Initial plan.'], ...$overrides];
}

test('technical staff can create and update a management plan with audit fields', function () {
    $staff = User::factory()->create(); $staff->assignRole('Technical Staff'); $area = managementPlanArea($staff);
    $this->actingAs($staff)->post(route('management-plans.store'), managementPlanPayload($area))->assertRedirect(route('management-plans.index'));
    $plan = ManagementPlan::firstOrFail();
    expect($plan->created_by)->toBe($staff->id)->and($plan->updated_by)->toBe($staff->id);
    $this->patch(route('management-plans.update', $plan), managementPlanPayload($area, ['version' => 'Version 1.1']))->assertRedirect(route('management-plans.index'));
    expect($plan->fresh()->version)->toBe('Version 1.1');
});

test('viewer can view management plans but cannot create or delete them', function () {
    $admin = User::factory()->create(); $admin->assignRole('CDS Admin'); $area = managementPlanArea($admin);
    $plan = ManagementPlan::create([...managementPlanPayload($area), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $viewer = User::factory()->create(); $viewer->assignRole('Viewer');
    $this->actingAs($viewer)->get(route('management-plans.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ManagementPlans/Index')->has('managementPlans.data', 1));
    $this->get(route('management-plans.create'))->assertForbidden();
    $this->delete(route('management-plans.destroy', $plan))->assertForbidden();
});

test('management plan requires an existing protected area', function () {
    $staff = User::factory()->create(); $staff->assignRole('Technical Staff'); $area = managementPlanArea($staff);
    $this->actingAs($staff)->from(route('management-plans.create'))->post(route('management-plans.store'), managementPlanPayload($area, ['protected_area_id' => 99999]))
        ->assertRedirect(route('management-plans.create'))->assertSessionHasErrors('protected_area_id');
});

test('management plans are soft deleted and plan versions remain separate records', function () {
    $admin = User::factory()->create(); $admin->assignRole('CDS Admin'); $area = managementPlanArea($admin);
    $first = ManagementPlan::create([...managementPlanPayload($area), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $second = ManagementPlan::create([...managementPlanPayload($area, ['version' => 'Version 2', 'prepared_year' => 2025, 'status' => 'Active']), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    expect($area->managementPlans()->count())->toBe(2)->and($first->id)->not->toBe($second->id);
    $this->actingAs($admin)->delete(route('management-plans.destroy', $first))->assertRedirect(route('management-plans.index'));
    $this->assertSoftDeleted('management_plans', ['id' => $first->id]);
    expect($area->fresh()->managementPlans()->count())->toBe(1);
});

test('view-file route displays a file inline if it exists, or returns 404', function () {
    $this->get('/view-file/non-existent.pdf')->assertStatus(404);

    $filename = 'test_inline_file.pdf';
    $filePath = storage_path('app/public/' . $filename);
    
    @mkdir(dirname($filePath), 0755, true);
    file_put_contents($filePath, 'dummy pdf content');
    
    try {
        $response = $this->get('/view-file/' . $filename);
        $response->assertStatus(200);
        expect($response->baseResponse)->toBeInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class);
        expect($response->baseResponse->getFile()->getPathname())->toBe($filePath);
    } finally {
        @unlink($filePath);
    }
});
