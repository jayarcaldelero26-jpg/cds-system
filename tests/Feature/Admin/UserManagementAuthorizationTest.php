<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['CDS Admin', 'Technical Staff', 'Viewer'] as $role) {
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

test('a CDS admin can create an inactive user and assign a role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $response = $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'CDS Viewer',
        'email' => 'viewer@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => 'Viewer',
        'is_active' => false,
    ]);

    $response->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'viewer@example.com')->firstOrFail();

    expect($user->is_active)->toBeFalse()
        ->and($user->hasRole('Viewer'))->toBeTrue();
});

test('CDS admin user management pages render with the admin navigation link', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $managedUser = User::factory()->create();
    $managedUser->assignRole('Viewer');

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('User Management')
        ->assertSee($managedUser->email);

    $this->get(route('admin.users.create'))
        ->assertOk()
        ->assertSee('Create User');

    $this->get(route('admin.users.edit', $managedUser))
        ->assertOk()
        ->assertSee('Edit User');
});

test('a CDS admin cannot delete their own account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('CDS Admin');

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});
