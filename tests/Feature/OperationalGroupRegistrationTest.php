<?php

use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('no_role', 'web');
    Role::findOrCreate('Super Admin', 'web');
});

function operationalGroupUser(string $category, ?string $unit = null, string $office = 'CENRO Baganga'): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'section' => $category,
        'unit_assignment' => $unit,
        'office_designated' => $office,
        'protected_area_id' => null,
    ]);
    $user->assignRole('no_role');

    return $user;
}

test('registration exposes the supported operational group catalog without duplicate categories', function (): void {
    $this->get('/register')->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Register')
        ->has('registrationOptions.operationalGroups', 2)
        ->where('registrationOptions.operationalGroups.0.value', 'cenro')
        ->where('registrationOptions.operationalGroups.1.value', 'penro'));

    $groups = app(OrganizationalAccessService::class)->operationalGroups();
    expect(collect(collect($groups)->firstWhere('value', 'penro')['categories'])
        ->pluck('value')->all())
        ->toBe([
            OrganizationalAccessService::PENRO_RECORDS,
            OrganizationalAccessService::OFFICE_PENRO,
            OrganizationalAccessService::PENRO_TSD_CHIEF,
            OrganizationalAccessService::PENRO_FOCAL,
            OrganizationalAccessService::PENRO_CHIEF,
        ]);
});

test('public and admin account forms expose the canonical CENRO office options', function (): void {
    $expected = ['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon'];

    $this->get('/register')->assertInertia(fn (Assert $page) => $page
        ->where('registrationOptions.offices', fn ($offices) => collect($offices)
            ->where('office_type', 'cenro')->pluck('name')->sort()->values()->all() === collect($expected)->sort()->values()->all()));

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('Super Admin');

    $this->actingAs($admin)->get(route('admin.users.create'))->assertInertia(fn (Assert $page) => $page
        ->where('offices', fn ($offices) => collect($offices)
            ->where('office_type', 'cenro')->pluck('name')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
});

test('operational group validation rejects category and unit mismatches', function (): void {
    $organization = app(OrganizationalAccessService::class);

    expect(fn () => $organization->validateAssignment(
        null,
        OrganizationalAccessService::CENRO_FOCAL,
        'CENRO Baganga',
        null,
        null,
        OrganizationalAccessService::OPERATIONAL_GROUP_PENRO,
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(fn () => $organization->validateAssignment(
        null,
        OrganizationalAccessService::PENRO_RECORDS,
        'PENRO Davao Oriental',
        null,
        null,
        OrganizationalAccessService::OPERATIONAL_GROUP_CENRO,
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('normal users cannot access passkey management endpoints while super admins can', function (): void {
    $normal = operationalGroupUser(OrganizationalAccessService::CENRO_RECORDS);
    $superAdmin = User::factory()->create(['is_active' => true]);
    $superAdmin->assignRole('Super Admin');

    $this->actingAs($normal)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'))
        ->assertForbidden();

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'))
        ->assertOk();
});

test('profile only shares passkey management for super admins', function (): void {
    $normal = operationalGroupUser(OrganizationalAccessService::CENRO_RECORDS);
    $superAdmin = User::factory()->create(['is_active' => true]);
    $superAdmin->assignRole('Super Admin');

    $this->actingAs($normal)->get('/profile')->assertInertia(fn (Assert $page) => $page
        ->component('Profile/Edit')
        ->where('canManagePasskeys', false)
        ->where('passkeys', []));

    $this->actingAs($superAdmin)->get('/profile')->assertInertia(fn (Assert $page) => $page
        ->component('Profile/Edit')
        ->where('canManagePasskeys', true));
});
