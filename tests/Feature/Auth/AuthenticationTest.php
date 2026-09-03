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

test('inactive users with correct credentials are redirected with a pending approval flash', function () {
    $user = User::factory()->create(['is_active' => false]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHas('pending_approval', true)
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
