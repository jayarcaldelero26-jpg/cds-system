<?php

use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaFacility;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function imeaFacilitiesArea(string $name, User $owner): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name,
        'category' => 'National Park',
        'municipality' => 'Davao Oriental',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function imeaFacilitiesAssign(ProtectedArea $area, string $officeCode): void
{
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', $officeCode)->valueOrFail('id'),
        'assignment_type' => 'supervising',
    ]);
}

function imeaFacilityFixture(ProtectedArea $area, array $overrides = []): ProtectedAreaFacility
{
    return ProtectedAreaFacility::create(array_merge([
        'protected_area_id' => $area->id,
        'inventory_date' => '2026-09-22',
        'facility_type' => 'UAT-CONS-AUDIT Field Shelter',
        'unit_no' => 1,
        'year_established' => 2024,
        'location_brgy_muni' => 'UAT-CONS-AUDIT local',
        'management_zone' => 'MUZ',
        'within_easement_zone' => 'No',
        'status' => 'Serviceable',
        'remarks' => 'UAT-CONS-AUDIT',
    ], $overrides));
}

function imeaFacilitiesCenroUser(): User
{
    $user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $user->givePermissionTo(Permission::findOrCreate('imea.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('imea.export', 'web'));

    return $user;
}

function imeaFacilitiesTestAreas(User $owner): array
{
    $baganga = imeaFacilitiesArea('UAT-P2 CENRO Baganga PA', $owner);
    $mati = imeaFacilitiesArea('Out of Scope CENRO Mati PA', $owner);
    imeaFacilitiesAssign($baganga, 'cenro_baganga');
    imeaFacilitiesAssign($mati, 'cenro_mati');

    return [$baganga, $mati];
}

test('IMEA Facilities Report renders for an authorized CENRO and remains scoped to assigned PAs', function (): void {
    $user = imeaFacilitiesCenroUser();
    [$baganga, $mati] = imeaFacilitiesTestAreas($user);
    $allowed = imeaFacilityFixture($baganga);
    imeaFacilityFixture($mati, ['facility_type' => 'Out of Scope facility']);

    $this->actingAs($user)->get(route('imea.facilities.report', [
        'protected_area_id' => $baganga->id,
        'zone' => 'MUZ',
        'inventory_date' => '2026-09-22',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Imea/FacilitiesReport')
        ->where('totalFacilities', 1)
        ->has('protectedAreas', 1)
        ->where('protectedAreas.0.id', $baganga->id)
        ->has('facilitiesList', 1)
        ->where('facilitiesList.0.id', $allowed->id)
        ->where('facilitiesList.0.protected_area.name', $baganga->name));

    $this->get(route('imea.facilities.report', ['protected_area_id' => $mati->id]))->assertForbidden();
});

test('IMEA Facilities CSV exports the canonical PA name once and preserves broader administrator scope', function (): void {
    $cenro = imeaFacilitiesCenroUser();
    [$baganga, $mati] = imeaFacilitiesTestAreas($cenro);
    imeaFacilityFixture($baganga);
    imeaFacilityFixture($mati, ['facility_type' => 'Out of Scope facility']);

    $response = $this->actingAs($cenro)->get(route('imea.facilities.export', [
        'protected_area_id' => $baganga->id,
        'zone' => 'MUZ',
        'inventory_date' => '2026-09-22',
    ]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $csvRows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

    expect($csvRows)->toHaveCount(2)
        ->and($csvRows[0][0])->toBe('Protected Area')
        ->and($csvRows[1][0])->toBe($baganga->name)
        ->and($csvRows[1][2])->toBe('UAT-CONS-AUDIT Field Shelter');

    $admin = User::factory()->create(['section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('imea.view', 'web'));

    $this->actingAs($admin)->get(route('imea.facilities.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('protectedAreas', 2)->where('totalFacilities', 2));

    $penro = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'PENRO_CDS_FOCAL',
        'office_designated' => 'PENRO Davao Oriental',
    ]);
    $penro->givePermissionTo(Permission::findOrCreate('imea.view', 'web'));

    $this->actingAs($penro)->get(route('imea.facilities.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('protectedAreas', 2)->where('totalFacilities', 2));
});
