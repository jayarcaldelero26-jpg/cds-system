<?php

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
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
        'office_designated' => 'PENRO Davao Oriental',
        'section' => 'CDS',
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
