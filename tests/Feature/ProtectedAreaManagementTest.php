<?php

use App\Models\ProtectedArea;
use App\Models\User;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['CDS Admin', 'CDS Admin', 'Viewer'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    foreach (['protected-areas.view', 'protected-areas.create', 'protected-areas.update', 'protected-areas.delete'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findByName('CDS Admin')->syncPermissions(['protected-areas.view', 'protected-areas.create', 'protected-areas.update', 'protected-areas.delete']);
    Role::findByName('CDS Admin')->syncPermissions(['protected-areas.view', 'protected-areas.create', 'protected-areas.update']);
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
        'supervising_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id'),
    ], ...$overrides];
}

test('technical staff can create and update protected areas with audit users', function () {
    $staff = User::factory()->create();
    $staff->assignRole('CDS Admin');

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
    $viewer = User::factory()->create(['section' => 'PENRO_CDS_FOCAL', 'unit_assignment' => null, 'office_designated' => 'PENRO Davao Oriental']);
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
    $staff->assignRole('CDS Admin');
    ProtectedArea::create([...protectedAreaPayload(), 'created_by' => $staff->id, 'updated_by' => $staff->id]);

    $this->actingAs($staff)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('protectedAreasCount', 1));

    $this->get(route('protected-areas.index'))->assertOk();
    $this->get(route('protected-areas.create'))->assertOk();
});

test('protected area forms expose active organizational offices without retired Cateel options', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $this->actingAs($admin)->get(route('protected-areas.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('officeOptions', fn ($options): bool => $options->pluck('name')->all() === [
                'CENRO Baganga',
                'CENRO Lupon',
                'CENRO Manay',
                'CENRO Mati',
                'PENRO Davao Oriental',

            ])
            ->where('officeOptions', fn ($options): bool => $options->pluck('name')->doesntContain('CENRO Cateel')));

    $this->actingAs($admin)->get(route('protected-areas.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('officeOptions.0.name', 'CENRO Baganga'));
});

test('protected area edit form binds existing, preserves unassigned, and updates one supervising assignment', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $baganga = OrganizationalOffice::where('code', 'cenro_baganga')->firstOrFail();
    $mati = OrganizationalOffice::where('code', 'cenro_mati')->firstOrFail();
    $penro = OrganizationalOffice::where('code', 'penro_davao_oriental')->firstOrFail();
    $aliwagwag = ProtectedArea::create([...protectedAreaPayload(['name' => 'Aliwagwag Protected Landscape']), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $mhrws = ProtectedArea::create([...protectedAreaPayload(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary']), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $unassigned = ProtectedArea::create([...protectedAreaPayload(['name' => 'Unassigned Protected Area']), 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $aliwagwag->id,
        'organizational_office_id' => $baganga->id,
        'assignment_type' => 'supervising',
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $mhrws->id,
        'organizational_office_id' => $penro->id,
        'assignment_type' => 'supervising',
    ]);

    $this->actingAs($admin)->get(route('protected-areas.edit', $aliwagwag))
        ->assertInertia(fn (Assert $page) => $page
            ->where('protectedArea.supervising_office_id', $baganga->id)
            ->where('protectedArea.supervising_office_name', 'CENRO Baganga'));

    $this->actingAs($admin)->get(route('protected-areas.edit', $mhrws))
        ->assertInertia(fn (Assert $page) => $page
            ->where('protectedArea.supervising_office_id', $penro->id)
            ->where('protectedArea.supervising_office_name', 'PENRO Davao Oriental'));

    $this->actingAs($admin)->get(route('protected-areas.edit', $unassigned))
        ->assertInertia(fn (Assert $page) => $page
            ->where('protectedArea.supervising_office_id', null)
            ->where('officeOptions.0.name', 'CENRO Baganga'));

    $this->actingAs($admin)->patch(route('protected-areas.update', $aliwagwag), protectedAreaPayload([
        'name' => 'Aliwagwag Protected Landscape',
        'supervising_office_id' => $mati->id,
    ]))->assertRedirect(route('protected-areas.index'));

    expect(ProtectedAreaOfficeAssignment::query()
        ->where('protected_area_id', $aliwagwag->id)
        ->where('assignment_type', 'supervising')
        ->count())->toBe(1)
        ->and(ProtectedAreaOfficeAssignment::query()
            ->where('protected_area_id', $aliwagwag->id)
            ->where('assignment_type', 'supervising')
            ->value('organizational_office_id'))->toBe($mati->id);
});
