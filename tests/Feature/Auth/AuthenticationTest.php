<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('inactive users with correct credentials are blocked with an inactive-account flash', function () {
    $user = User::factory()->create(['is_active' => false]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHas('account_inactive', true)
        ->assertSessionMissing('pending_approval')
        ->assertSessionDoesntHaveErrors();
    $this->assertGuest();
});

test('inactive users with an incorrect password retain normal credential failure behavior', function () {
    $user = User::factory()->create(['is_active' => false]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    $response->assertSessionMissing('pending_approval');
    $this->assertGuest();
});

test('unapproved users with active flags are redirected with a pending approval flash', function () {
    $user = User::factory()->create(['is_approved' => false, 'is_active' => true]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHas('pending_approval', true)
        ->assertSessionDoesntHaveErrors();
    $this->assertGuest();
});

test('unapproved inactive users are still pending approval rather than merely inactive', function () {
    $user = User::factory()->create(['is_approved' => false, 'is_active' => false]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('pending_approval', true)
        ->assertSessionMissing('account_inactive');

    $this->assertGuest();
});

test('an unapproved active session cannot access the dashboard', function () {
    $user = User::factory()->create(['is_approved' => false, 'is_active' => true]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('pending_approval', true);

    $this->assertGuest();
});

test('an approved but manually deactivated user cannot access the dashboard', function () {
    $user = User::factory()->create(['is_approved' => true, 'is_active' => false]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('account_inactive', true)
        ->assertSessionMissing('pending_approval');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('account_inactive', true)
        ->assertSessionMissing('pending_approval');

    $this->assertGuest();
});

test('legacy privileged accounts are normalized by role and can still access User Management', function () {
    \Spatie\Permission\Models\Role::findOrCreate('CDS Admin', 'web');
    $admin = User::factory()->create([
        'email' => 'renamed-admin@example.com',
        'is_approved' => false,
        'is_active' => true,
    ]);
    $admin->assignRole('CDS Admin');

    $this->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($admin);
    expect($admin->fresh()->is_approved)->toBeTrue()
        ->and($admin->fresh()->getRoleNames()->all())->toBe(['CDS Admin']);

    $this->get(route('admin.users.index'))->assertOk();
});

test('an ordinary account using the former bootstrap email remains pending and is not an administrator', function () {
    \Spatie\Permission\Models\Role::findOrCreate('no_role', 'web');
    $user = User::factory()->create([
        'email' => 'tempcdsims@gmail.com',
        'is_approved' => false,
        'is_active' => true,
    ]);
    $user->assignRole('no_role');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('pending_approval', true);

    $this->assertGuest();
    expect($user->fresh()->getRoleNames()->all())->toBe(['no_role']);
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout', [
        '_token' => csrf_token(),
    ]);

    $this->assertGuest();
    $response->assertRedirect('/login');
});

test('logout invalidates the session and rejects missing csrf tokens', function () {
    $user = User::factory()->create();

    $this->app->instance(
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        new class($this->app, $this->app['encrypter'])
            extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        },
    );

    $this->withMiddleware()->actingAs($user)->withSession(['logout-test' => 'present'])
        ->post('/logout')
        ->assertStatus(419);

    $this->assertAuthenticatedAs($user);
    expect(session('logout-test'))->toBe('present');
});

test('guests cannot use the logout endpoint', function () {
    $this->post('/logout', [
        '_token' => csrf_token(),
    ])->assertRedirect(route('login'));
});

test('an active PENRO no_role user ignores a stale admin intended URL but cannot visit admin directly', function () {
    \Spatie\Permission\Models\Role::findOrCreate('no_role', 'web');
    $user = User::factory()->create([
        'is_active' => true,
        'unit_assignment' => 'conservation',
        'section' => 'PENRO_RECORDS',
        'office_designated' => 'PENRO Davao Oriental',
        'protected_area_id' => null,
    ]);
    $user->assignRole('no_role');

    $this->withSession(['url.intended' => route('admin.users.index')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard')
        ->assertSessionMissing('url.intended');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->getRoleNames()->all())->toBe(['no_role']);
    $this->get('/dashboard')->assertOk();
    $this->get('/admin/users')->assertForbidden();
});

test('an approved active CENRO CDS focal with no optional access assignment reaches the dashboard', function () {
    \Spatie\Permission\Models\Role::findOrCreate('no_role', 'web');
    $user = User::factory()->create([
        'is_approved' => true,
        'is_active' => true,
        'office_designated' => 'CENRO Baganga',
        'section' => 'CENRO_CDS_FOCAL',
        'unit_assignment' => null,
        'protected_area_id' => null,
    ]);
    $user->assignRole('no_role');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.is_approved', true)
            ->where('auth.user.is_active', true)
            ->where('auth.user.office_designated', 'CENRO Baganga')
            ->where('auth.user.section', 'CENRO_CDS_FOCAL')
            ->where('auth.user.unit_assignment', null)
            ->where('auth.user.protected_area_id', null));
});

test('login always lands on the dashboard instead of an authorized intended URL', function () {
    $user = User::factory()->create();
    $this->withSession(['url.intended' => route('profile.edit')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
});

test('a global administrator also lands on the dashboard', function () {
    \Spatie\Permission\Models\Role::findOrCreate('CDS Admin', 'web');
    $user = User::factory()->create();
    $user->assignRole('CDS Admin');
    $this->withSession(['url.intended' => route('admin.users.index')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->get('/admin/users')->assertOk();
});

test('login discards an intended route lacking its required permission', function () {
    $user = User::factory()->create();
    $this->withSession(['url.intended' => route('module-definitions.index')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->get(route('module-definitions.index'))->assertForbidden();
});

test('login does not follow external intended destinations', function () {
    $user = User::factory()->create();
    $this->withSession(['url.intended' => 'https://example.invalid/dashboard'])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
});

test('an intended forbidden route is ignored and login lands on the dashboard', function () {
    \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])
        ->get('/login-policy-target/{record}', fn () => abort(403));
    $user = User::factory()->create();
    $this->withSession(['url.intended' => '/login-policy-target/1'])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->get('/login-policy-target/1')->assertForbidden()
        ->assertSessionMissing('auth.login_destination');
    $this->get('/login-policy-target/1')->assertForbidden();
});

test('an authorized administrator does not retain a resource intended destination', function () {
    \Spatie\Permission\Models\Role::findOrCreate('CDS Admin', 'web');
    $user = User::factory()->create();
    $user->assignRole('CDS Admin');
    $destination = route('admin.users.edit', $user);
    $this->withSession(['url.intended' => $destination])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->get($destination)->assertOk();
});
