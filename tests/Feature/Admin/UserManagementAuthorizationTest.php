<?php

use App\Models\User;
use App\Policies\UserPolicy;
use App\Models\ProtectedArea;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['CDS Admin', 'Technical Staff', 'Viewer', 'no_role', 'CENRO CDS Focal Person', 'PAMO', 'CENRO CDS Chief'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

test('technical staff cannot access user management', function () {
    $user = User::factory()->create();
    $user->assignRole('Technical Staff');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('a CDS admin can create an inactive user from the operational category', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $response = $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'CENRO Focal',
        'email' => 'viewer@example.com',
        'office_designated' => 'CENRO Baganga',
        'section' => 'CENRO_CDS_FOCAL',
        'unit_assignment' => 'conservation',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'is_active' => false,
    ]);

    $response->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'viewer@example.com')->firstOrFail();

    expect($user->is_active)->toBeFalse()
        ->and($user->hasRole('CENRO CDS Focal Person'))->toBeTrue();
});

test('a CDS admin can activate a fully configured user without resubmitting a role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $managedUser = User::factory()->create([
        'is_active' => false,
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $managedUser->assignRole('CENRO CDS Focal Person');

    $this->actingAs($admin)
        ->patch(route('admin.users.activate', $managedUser), [])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success', 'User account activated successfully.');

    $activatedUser = $managedUser->fresh();
    expect($activatedUser->is_active)->toBeTrue()
        ->and($activatedUser->hasRole('CENRO CDS Focal Person'))->toBeTrue()
        ->and($activatedUser->unit_assignment)->toBe('conservation')
        ->and($activatedUser->office_designated)->toBe('CENRO Baganga');

    $this->post('/logout');
    $this->post('/login', ['email' => $managedUser->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($managedUser->fresh());
});

test('changing a PAMO user to a CENRO role normalizes category and clears PA scope atomically', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $area = ProtectedArea::create(['name' => 'Aliwagwag Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $managedUser = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'PAMO', 'office_designated' => 'PENRO Davao Oriental', 'protected_area_id' => $area->id, 'is_active' => false]);
    $managedUser->assignRole('PAMO');

    $this->actingAs($admin)->patch(route('admin.users.update', $managedUser), [
        'name' => $managedUser->name,
        'email' => $managedUser->email,
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_CHIEF',
        'office_designated' => 'CENRO Baganga',
        'protected_area_id' => '',
        'is_active' => false,
    ])->assertRedirect(route('admin.users.index'));

    $updated = $managedUser->fresh();
    expect($updated->section)->toBe('CENRO_CDS_CHIEF')
        ->and($updated->protected_area_id)->toBeNull()
        ->and($updated->office_designated)->toBe('CENRO Baganga')
        ->and($updated->hasRole('CENRO CDS Chief'))->toBeTrue();
});

test('an account without a configured operational role cannot be activated', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $managedUser = User::factory()->create([
        'is_active' => false,
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $managedUser->assignRole('no_role');

    $this->actingAs($admin)
        ->patch(route('admin.users.activate', $managedUser), [])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please complete the user\'s access role and organizational assignment before activating this account.');

    expect($managedUser->fresh()->is_active)->toBeFalse();
});

test('an account with an invalid organizational scope cannot be activated', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $managedUser = User::factory()->create([
        'is_active' => false,
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'PENRO Davao Oriental',
    ]);
    $managedUser->assignRole('CENRO CDS Focal Person');

    $this->actingAs($admin)
        ->patch(route('admin.users.activate', $managedUser), [])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please complete the user\'s access role and organizational assignment before activating this account.');

    expect($managedUser->fresh()->is_active)->toBeFalse();
});

test('CDS admin user management pages render with the admin navigation link', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $managedUser = User::factory()->create();
    $managedUser->assignRole('Viewer');

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data', 2)
            ->where('users.data.1.email', $managedUser->email));

    $this->get(route('admin.users.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Create'));

    $this->get(route('admin.users.edit', $managedUser))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Edit')
            ->where('user.email', $managedUser->email));
});

test('a CDS admin cannot delete their own account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $admin));
    $response->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
    expect(Gate::forUser($admin)->allows('delete', $admin))->toBeFalse();
});

test('the last CDS admin cannot be deleted', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $actor = Mockery::mock(User::class);
    $actor->shouldReceive('hasRole')->with('CDS Admin')->andReturnTrue();
    $actor->shouldReceive('is')->with($admin)->andReturnFalse();

    expect(app(UserPolicy::class)->delete($actor, $admin))->toBeFalse();
    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('a CDS admin can delete another CDS admin while one remains', function () {
    $actor = User::factory()->create();
    $actor->assignRole('CDS Admin');
    $managedUser = User::factory()->create();
    $managedUser->assignRole('CDS Admin');

    $this->actingAs($actor)
        ->delete(route('admin.users.destroy', $managedUser))
        ->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseMissing('users', ['id' => $managedUser->id]);
    $this->assertDatabaseHas('users', ['id' => $actor->id]);
});

test('a CDS admin can delete an eligible normal user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');
    $managedUser = User::factory()->create();
    $managedUser->assignRole('Viewer');

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $managedUser))
        ->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseMissing('users', ['id' => $managedUser->id]);
});

test('technical staff cannot delete users', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Technical Staff');
    $managedUser = User::factory()->create();
    $managedUser->assignRole('Viewer');

    $this->actingAs($staff)
        ->delete(route('admin.users.destroy', $managedUser))
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $managedUser->id]);
});

test('viewers cannot delete users', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('Viewer');
    $managedUser = User::factory()->create();
    $managedUser->assignRole('Viewer');

    $this->actingAs($viewer)
        ->delete(route('admin.users.destroy', $managedUser))
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $managedUser->id]);
});
