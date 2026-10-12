<?php

use App\Models\User;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\RoutingPositionSettingsService;
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

function saveOperationalAccountRoutingFlags(User $actor, bool $office, bool $tsd): array
{
    $settings = app(RoutingPositionSettingsService::class);

    return $settings->save($settings->current()['version'], $office, $tsd, 'Isolated registration test', $actor);
}

test('registration exposes the supported operational group catalog without duplicate categories', function (): void {
    $this->get('/register')->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Register')
        ->has('registrationOptions.operationalGroups', 3)
        ->where('registrationOptions.operationalGroups.0.value', 'cenro')
        ->where('registrationOptions.operationalGroups.1.value', 'penro')
        ->where('registrationOptions.operationalGroups.2.value', 'pamo'));

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

test('PAMO registration derives canonical office and supervisor and rejects submitted mapping overrides', function (): void {
    $supervisor = OrganizationalOffice::query()->where('office_type', 'cenro')->where('name', 'CENRO Mati')->firstOrFail();
    $pamoOffice = OrganizationalOffice::query()->create([
        'code' => 'test_pamo_mpl_registration', 'name' => 'PAMO MPL Registration', 'office_type' => 'pamo', 'is_active' => true,
    ]);
    $area = ProtectedArea::query()->create([
        'name' => 'Mati Protected Landscape', 'short_name' => 'MPL', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active',
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id, 'organizational_office_id' => $supervisor->id, 'assignment_type' => 'supervising',
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id, 'organizational_office_id' => $pamoOffice->id, 'assignment_type' => 'pamo',
    ]);

    $this->get('/register')->assertInertia(fn (Assert $page) => $page
        ->where('registrationOptions.operationalGroups.2.value', 'pamo')
        ->where('registrationOptions.protectedAreas.0.pamo_office_name', 'PAMO MPL Registration')
        ->where('registrationOptions.protectedAreas.0.supervising_office_name', 'CENRO Mati'));

    $payload = [
        'name' => 'PAMO Pilot User', 'email' => 'pamo-pilot-user@example.test',
        'operational_group' => 'pamo', 'section' => 'PAMO', 'protected_area_id' => $area->id,
        'password' => 'Passw0rd!123', 'password_confirmation' => 'Passw0rd!123',
    ];

    $this->post('/register', [...$payload, 'office_designated' => 'PAMO forged office'])
        ->assertSessionHasErrors('office_designated');
    $this->assertDatabaseMissing('users', ['email' => $payload['email']]);

    $this->post('/register', [...$payload, 'supervising_office_id' => $supervisor->id])
        ->assertSessionHasErrors('supervising_office_id');
    $this->assertDatabaseMissing('users', ['email' => $payload['email']]);

    $this->post('/register', $payload)->assertRedirect(route('login'))->assertSessionHasNoErrors();
    $user = User::query()->where('email', $payload['email'])->firstOrFail();
    expect($user->office_designated)->toBe('PAMO MPL Registration')
        ->and($user->protected_area_id)->toBe($area->id)
        ->and($user->section)->toBe(OrganizationalAccessService::PAMO)
        ->and($user->is_approved)->toBeFalse()
        ->and($user->is_active)->toBeFalse()
        ->and($user->roles()->pluck('name')->all())->toBe(['no_role']);
});

test('admin user management exposes unresolved legacy PAMO assignments for correction', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('Super Admin');
    $area = ProtectedArea::query()->create([
        'name' => 'Unmapped Legacy PA', 'short_name' => 'ULPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active',
    ]);
    $legacy = User::factory()->create([
        'section' => OrganizationalAccessService::PAMO,
        'office_designated' => 'Legacy PAMO text',
        'protected_area_id' => $area->id,
        'is_approved' => false,
        'is_active' => false,
    ]);
    $legacy->assignRole('no_role');

    $this->actingAs($admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
        ->where('protectedAreaAssignments.0.eligible', false)
        ->where('protectedAreaAssignments.0.issue', 'No stable PAMO office identity is mapped.')
        ->where('users.data', fn ($users) => collect($users)->contains(fn ($user) => $user['id'] === $legacy->id && $user['pamo_assignment_issue'] === 'No stable PAMO office identity is mapped.')));

    $this->actingAs($admin)->get(route('admin.users.edit', $legacy))->assertInertia(fn (Assert $page) => $page
        ->where('user.protected_area_id', $area->id)
        ->where('user.pamo_assignment_issue', 'No stable PAMO office identity is mapped.')
        ->where('protectedAreas', fn ($areas) => collect($areas)->contains(fn ($option) => $option['id'] === $area->id && $option['eligible'] === false)));
});

test('registration choices follow all four routing-position combinations and return when re-enabled', function (): void {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole('Super Admin');
    $allCategories = [
        OrganizationalAccessService::PENRO_RECORDS,
        OrganizationalAccessService::OFFICE_PENRO,
        OrganizationalAccessService::PENRO_TSD_CHIEF,
        OrganizationalAccessService::PENRO_FOCAL,
        OrganizationalAccessService::PENRO_CHIEF,
    ];

    foreach ([[true, true], [false, true], [true, false], [false, false]] as [$office, $tsd]) {
        saveOperationalAccountRoutingFlags($actor, $office, $tsd);
        $expected = array_values(array_filter($allCategories, fn (string $category): bool =>
            ($category !== OrganizationalAccessService::OFFICE_PENRO || $office)
            && ($category !== OrganizationalAccessService::PENRO_TSD_CHIEF || $tsd)
        ));

        $this->get('/register')->assertInertia(fn (Assert $page) => $page
            ->where('registrationOptions.operationalGroups.1.categories', fn ($categories) => collect($categories)->pluck('value')->all() === $expected));
    }
});

test('disabled position categories are hidden and rejected by public registration even when posted directly', function (): void {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole('Super Admin');
    saveOperationalAccountRoutingFlags($actor, false, false);

    $this->get('/register')->assertInertia(fn (Assert $page) => $page
        ->where('registrationOptions.operationalGroups.1.categories', fn ($categories) => ! collect($categories)->pluck('value')->contains(OrganizationalAccessService::OFFICE_PENRO)
            && ! collect($categories)->pluck('value')->contains(OrganizationalAccessService::PENRO_TSD_CHIEF)));

    foreach ([OrganizationalAccessService::OFFICE_PENRO, OrganizationalAccessService::PENRO_TSD_CHIEF] as $index => $category) {
        $email = "disabled-position-{$index}@example.test";
        $this->from('/register')->post('/register', [
            'name' => 'Disabled Position Request',
            'email' => $email,
            'operational_group' => 'penro',
            'office_designated' => 'PENRO Davao Oriental',
            'section' => $category,
            'password' => 'Passw0rd!123',
            'password_confirmation' => 'Passw0rd!123',
        ])->assertSessionHasErrors('section');

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }
});

test('admin account creation hides disabled categories and rejects a tampered category', function (): void {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole('Super Admin');
    saveOperationalAccountRoutingFlags($actor, false, false);

    $this->actingAs($actor)->get(route('admin.users.create'))->assertInertia(fn (Assert $page) => $page
        ->where('operationalGroups.1.categories', fn ($categories) => ! collect($categories)->pluck('value')->contains(OrganizationalAccessService::OFFICE_PENRO)
            && ! collect($categories)->pluck('value')->contains(OrganizationalAccessService::PENRO_TSD_CHIEF)));

    $this->from(route('admin.users.create'))->post(route('admin.users.store'), [
        'name' => 'Disabled TSD Request',
        'email' => 'disabled-tsd-admin@example.test',
        'account_role' => 'User',
        'operational_group' => 'penro',
        'office_designated' => 'PENRO Davao Oriental',
        'section' => OrganizationalAccessService::PENRO_TSD_CHIEF,
        'password' => 'Passw0rd!123',
        'password_confirmation' => 'Passw0rd!123',
    ])->assertSessionHasErrors('section');

    $this->assertDatabaseMissing('users', ['email' => 'disabled-tsd-admin@example.test']);
});

test('existing disabled-position category remains visible and valid when editing that account', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('Super Admin');
    saveOperationalAccountRoutingFlags($admin, false, false);
    $existing = operationalGroupUser(OrganizationalAccessService::OFFICE_PENRO, null, 'PENRO Davao Oriental');

    $this->actingAs($admin)->get(route('admin.users.edit', $existing))->assertInertia(fn (Assert $page) => $page
        ->where('user.effective_category', OrganizationalAccessService::OFFICE_PENRO)
        ->where('operationalGroups.1.categories', fn ($categories) => collect($categories)->pluck('value')->contains(OrganizationalAccessService::OFFICE_PENRO)
            && ! collect($categories)->pluck('value')->contains(OrganizationalAccessService::PENRO_TSD_CHIEF)));

    $this->put(route('admin.users.update', $existing), [
        'name' => 'Existing Office Account',
        'email' => $existing->email,
        'account_role' => 'User',
        'operational_group' => 'penro',
        'office_designated' => 'PENRO Davao Oriental',
        'section' => OrganizationalAccessService::OFFICE_PENRO,
    ])->assertRedirect(route('admin.users.index'));

    expect($existing->fresh()->section)->toBe(OrganizationalAccessService::OFFICE_PENRO);
});

test('public registration still accepts a position category when routing includes it', function (): void {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole('Super Admin');
    saveOperationalAccountRoutingFlags($actor, true, true);

    $this->post('/register', [
        'name' => 'Enabled Office Request',
        'email' => 'enabled-office@example.test',
        'operational_group' => 'penro',
        'office_designated' => 'PENRO Davao Oriental',
        'section' => OrganizationalAccessService::OFFICE_PENRO,
        'password' => 'Passw0rd!123',
        'password_confirmation' => 'Passw0rd!123',
    ])->assertRedirect(route('login'));

    $this->assertDatabaseHas('users', [
        'email' => 'enabled-office@example.test',
        'section' => OrganizationalAccessService::OFFICE_PENRO,
        'is_active' => false,
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
