<?php

use App\Models\ManagementPlan;
use App\Models\ManagementPlanType;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

function managementPlanReportPayload(int $areaId, array $overrides = []): array
{
    return [...['protected_area_id' => $areaId, 'target_office' => 'PENRO Davao Oriental', 'activity_name' => 'Management Plan Implementation', 'document_type' => 'Final Report', 'semester' => '1st Semester', 'date_conducted' => '2026-01-15', 'date_accomplished' => '2026-02-01', 'remarks' => 'Initial report.', 'attachments' => [UploadedFile::fake()->create('management-plan-report.pdf', 10, 'application/pdf')]], ...$overrides];
}

test('authorized users can access the dynamic management plan workspace', function () {
    $staff = User::factory()->create(); $staff->assignRole('Technical Staff');
    $type = ManagementPlanType::create(['name' => 'PAMP', 'slug' => 'pamp', 'created_by' => $staff->id, 'updated_by' => $staff->id]);

    $this->actingAs($staff)->get(route('management-plans.types.show', $type->slug))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('ManagementPlans/Index')->where('selectedPlanType.slug', 'pamp')->has('managementPlans.data', 0));
});

test('viewers can view the management plan index but cannot create a plan type', function () {
    $viewer = User::factory()->create(); $viewer->assignRole('Viewer');
    $this->actingAs($viewer)->get(route('management-plans.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ManagementPlans/Index')->has('planTypes', 0));
    $this->actingAs($viewer)->post(route('management-plans.types.store'), ['name' => 'PAMP'])->assertForbidden();
});

test('authorized users can create, update, and soft delete reports in a dynamic workspace', function () {
    $staff = User::factory()->create(); $staff->assignRole('CDS Admin'); $area = managementPlanArea($staff);
    $type = ManagementPlanType::create(['name' => 'PAMP', 'slug' => 'pamp', 'created_by' => $staff->id, 'updated_by' => $staff->id]);
    $this->actingAs($staff)->post(route('management-plans.types.reports.store', $type->slug), managementPlanReportPayload($area->id))->assertRedirect(route('management-plans.types.show', $type->slug));
    $plan = ManagementPlan::firstOrFail();
    expect($plan->management_plan_type_id)->toBe($type->id)->and($plan->protected_area_id)->toBe($area->id)->and($plan->created_by)->toBe($staff->id);
    $this->actingAs($staff)->patch(route('management-plans.types.reports.update', [$type->slug, $plan]), managementPlanReportPayload($area->id, ['activity_name' => 'Updated Implementation']))->assertRedirect(route('management-plans.types.show', $type->slug));
    expect($plan->fresh()->activity_name)->toBe('Updated Implementation');
    $this->actingAs($staff)->delete(route('management-plans.types.reports.destroy', [$type->slug, $plan]))->assertRedirect(route('management-plans.types.show', $type->slug));
    $this->assertSoftDeleted('management_plans', ['id' => $plan->id]);
});

test('management plan reports require an existing protected area', function () {
    $staff = User::factory()->create(); $staff->assignRole('Technical Staff');
    $type = ManagementPlanType::create(['name' => 'PAMP', 'slug' => 'pamp', 'created_by' => $staff->id, 'updated_by' => $staff->id]);
    $this->actingAs($staff)->from(route('management-plans.types.reports.create', $type->slug))->post(route('management-plans.types.reports.store', $type->slug), managementPlanReportPayload(99999))->assertRedirect(route('management-plans.types.reports.create', $type->slug))->assertSessionHasErrors('protected_area_id');
});

test('authorized users receive 404 for a missing management plan attachment', function () {
    $user = User::factory()->create(); $user->assignRole('Technical Staff');
    $this->actingAs($user)->get('/view-file/non-existent.pdf')->assertStatus(404);
});

test('management plan report forms reject routing dates and ordinary edits preserve them', function () {
    Storage::fake('local');
    Storage::fake('public');
    $staff = User::factory()->create();
    $staff->assignRole('CDS Admin');
    $area = managementPlanArea($staff);
    $type = ManagementPlanType::create(['name' => 'PAMP', 'slug' => 'pamp', 'created_by' => $staff->id, 'updated_by' => $staff->id]);

    $this->actingAs($staff)
        ->post(route('management-plans.types.reports.store', $type->slug), managementPlanReportPayload($area->id, [
            'date_received_penro' => '2026-02-05',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('routing');

    $this->actingAs($staff)
        ->post(route('management-plans.types.reports.store', $type->slug), managementPlanReportPayload($area->id))
        ->assertRedirect();

    $plan = ManagementPlan::firstOrFail();
    $plan->update([
        'date_report_released_cenro' => '2026-02-03',
        'date_received_penro' => '2026-02-05',
        'date_endorsed_regional' => '2026-02-06',
    ]);

    $this->actingAs($staff)
        ->patch(route('management-plans.types.reports.update', [$type->slug, $plan]), managementPlanReportPayload($area->id, [
            'activity_name' => 'Updated Implementation',
            'date_endorsed_regional' => '2026-02-09',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('routing');

    $this->actingAs($staff)
        ->patch(route('management-plans.types.reports.update', [$type->slug, $plan]), managementPlanReportPayload($area->id, [
            'activity_name' => 'Updated Implementation',
        ]))
        ->assertRedirect();

    $plan->refresh();
    expect($plan->activity_name)->toBe('Updated Implementation')
        ->and($plan->date_report_released_cenro?->toDateString())->toBe('2026-02-03')
        ->and($plan->date_received_penro?->toDateString())->toBe('2026-02-05')
        ->and($plan->date_endorsed_regional?->toDateString())->toBe('2026-02-06');
});
