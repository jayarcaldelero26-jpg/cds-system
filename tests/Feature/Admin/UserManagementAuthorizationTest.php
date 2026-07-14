<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
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

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});
