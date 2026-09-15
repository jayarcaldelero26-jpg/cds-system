<?php

use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('CDS Admin', 'web');
    Role::findOrCreate('no_role', 'web');
});

function accessAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    return $admin;
}

test('normal users cannot access User Management', function (): void {
    $user = User::factory()->create();
    $user->assignRole('no_role');

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
});

test('an admin creates a CENRO conservation focal using the Operational Group contract', function (): void {
    $admin = accessAdmin();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'CENRO Focal', 'email' => 'viewer@example.com',
        'account_role' => 'User', 'operational_group' => 'cenro',
        'office_designated' => 'CENRO Baganga', 'section' => 'CENRO_CDS_FOCAL',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'is_active' => false,
    ])->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'viewer@example.com')->firstOrFail();
    expect($user->is_active)->toBeFalse()
        ->and($user->section)->toBe(OrganizationalAccessService::CENRO_FOCAL)
        ->and($user->unit_assignment)->toBeNull()
        ->and($user->getRoleNames()->all())->toBe(['no_role']);
});

test('admin creation rejects group and category mismatches', function (): void {
    $admin = accessAdmin();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Invalid group', 'email' => 'invalid-group@example.com',
        'account_role' => 'User', 'operational_group' => 'penro',
        'section' => 'CENRO_CDS_FOCAL', 'office_designated' => 'CENRO Baganga',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'is_active' => false,
    ])->assertSessionHasErrors('section');

    expect(User::where('email', 'invalid-group@example.com')->exists())->toBeFalse();
});

test('admin creation and edit pages share operational groups', function (): void {
    $admin = accessAdmin();
    $managed = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => 'development', 'office_designated' => 'CENRO Mati']);
    $managed->assignRole('no_role');

    $this->actingAs($admin)->get(route('admin.users.create'))->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Users/Create')
        ->has('operationalGroups', 2));

    $this->get(route('admin.users.edit', $managed))->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Users/Edit')
        ->where('user.operational_group', 'cenro')
        ->has('operationalGroups', 2));
});

test('admin creation rejects unsupported PAMO accounts', function (): void {
    $admin = accessAdmin();
    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'MHRWS PAMO', 'email' => 'mhrws-staff@example.com',
        'account_role' => 'User', 'operational_group' => 'pamo', 'section' => 'PAMO',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'is_active' => false,
    ])->assertSessionHasErrors(['operational_group', 'section']);

    expect(User::where('email', 'mhrws-staff@example.com')->exists())->toBeFalse();
});

test('legacy PAMO accounts remain viewable but are not supported as new account options', function (): void {
    $admin = accessAdmin();
    $legacy = User::factory()->create([
        'section' => 'PAMO',
        'office_designated' => 'PENRO Davao Oriental',
        'protected_area_id' => null,
    ]);
    $legacy->assignRole('no_role');

    $this->actingAs($admin)->get(route('admin.users.edit', $legacy))
        ->assertInertia(fn (Assert $page) => $page
            ->where('user.section', 'PAMO')
            ->where('operationalGroups', fn ($groups) => collect($groups)->pluck('value')->all() === ['cenro', 'penro']));
});

test('fully configured CENRO and PENRO accounts activate without a legacy Technical Staff role', function (): void {
    $admin = accessAdmin();
    $cenro = User::factory()->create(['is_approved' => true, 'is_active' => false, 'unit_assignment' => 'development', 'section' => 'CENRO_CDS_FOCAL', 'office_designated' => 'CENRO Baganga']);
    $cenro->assignRole('no_role');
    $penro = User::factory()->create(['is_approved' => true, 'is_active' => false, 'unit_assignment' => null, 'section' => 'PENRO_TSD_CHIEF', 'office_designated' => 'PENRO Davao Oriental']);
    $penro->assignRole('no_role');

    foreach ([$cenro, $penro] as $managed) {
        $this->actingAs($admin)->patch(route('admin.users.activate', $managed))->assertRedirect(route('admin.users.index'));
        expect($managed->fresh()->is_active)->toBeTrue()->and($managed->fresh()->getRoleNames()->all())->toBe(['no_role']);
    }
});

test('an administrator can approve and immediately activate a pending user without changing access data', function (): void {
    $admin = accessAdmin();
    $managed = User::factory()->create([
        'is_approved' => false,
        'is_active' => false,
        'office_designated' => 'CENRO Baganga',
        'section' => 'CENRO_CDS_FOCAL',
        'unit_assignment' => 'development',
    ]);
    $managed->assignRole('no_role');
    $before = $managed->only(['name', 'email', 'password', 'is_active', 'office_designated', 'section', 'unit_assignment', 'protected_area_id']);
    $permissionsBefore = $managed->getAllPermissions()->pluck('name')->sort()->values()->all();

    $this->actingAs($admin)
        ->patch(route('admin.users.approve', $managed))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success', 'User account approved successfully.');

    $after = $managed->fresh();
    expect($after->is_approved)->toBeTrue()
        ->and($after->is_active)->toBeTrue()
        ->and($after->name)->toBe($before['name'])
        ->and($after->email)->toBe($before['email'])
        ->and($after->password)->toBe($before['password'])
        ->and($after->office_designated)->toBe($before['office_designated'])
        ->and($after->section)->toBe($before['section'])
        ->and($after->unit_assignment)->toBe($before['unit_assignment'])
        ->and($after->protected_area_id)->toBe($before['protected_area_id'])
        ->and($after->getRoleNames()->all())->toBe(['no_role'])
        ->and($after->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($permissionsBefore);

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page->where('users.data', function ($rows) use ($after): bool {
            return collect($rows)->contains(fn (array $row): bool => $row['id'] === $after->id
                && $row['is_approved'] === true
                && $row['is_active'] === true);
        }));

    $this->post('/logout');

    $this->post('/login', [
        'email' => $after->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($after);
    $this->get(route('dashboard'))->assertOk();
});

test('a non administrator cannot approve a user', function (): void {
    $user = User::factory()->create();
    $user->assignRole('no_role');
    $managed = User::factory()->create(['is_approved' => false]);

    $this->actingAs($user)
        ->patch(route('admin.users.approve', $managed))
        ->assertForbidden();

    expect($managed->fresh()->is_approved)->toBeFalse();
});

test('an unapproved user cannot be activated before approval', function (): void {
    $admin = accessAdmin();
    $managed = User::factory()->create(['is_approved' => false, 'is_active' => false]);

    $this->actingAs($admin)
        ->patch(route('admin.users.activate', $managed))
        ->assertRedirect()
        ->assertSessionHas('error', 'Approve the account before activating it.');

    expect($managed->fresh()->is_approved)->toBeFalse()
        ->and($managed->fresh()->is_active)->toBeFalse();
});

test('user management exposes the three separate approval and account states', function (): void {
    $admin = accessAdmin();
    User::factory()->create(['is_approved' => false, 'is_active' => true]);
    User::factory()->create(['is_approved' => true, 'is_active' => true]);
    User::factory()->create(['is_approved' => true, 'is_active' => false]);

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data', function ($rows): bool {
                $states = collect($rows)->map(fn (array $row): string => ($row['is_approved'] ? 'approved' : 'pending').':'.($row['is_active'] ? 'active' : 'inactive'));

                return $states->contains('pending:active')
                    && $states->contains('approved:active')
                    && $states->contains('approved:inactive');
            }));
});

test('a CDS admin cannot delete their own account', function (): void {
    $admin = accessAdmin();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

test('the last CDS admin cannot be deleted by the Super Admin', function (): void {
    Role::findOrCreate('Super Admin', 'web');
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $admin = accessAdmin();

    $this->actingAs($superAdmin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

test('a CDS admin can delete another CDS admin while one remains', function (): void {
    $admin = accessAdmin();
    $managed = User::factory()->create();
    $managed->assignRole('CDS Admin');

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $managed))
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($managed->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

test('a CDS admin can delete an eligible normal user', function (): void {
    $admin = accessAdmin();
    $managed = User::factory()->create();
    $managed->assignRole('no_role');

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $managed))
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($managed->id)->exists())->toBeFalse();
});

test('normal users cannot delete managed accounts', function (): void {
    $user = User::factory()->create();
    $user->assignRole('no_role');
    $managed = User::factory()->create();
    $managed->assignRole('no_role');

    $this->actingAs($user)
        ->delete(route('admin.users.destroy', $managed))
        ->assertForbidden();

    expect(User::query()->whereKey($managed->id)->exists())->toBeTrue();
});
