<?php

use App\Models\User;

use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('no_role', 'web');
});

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users register as pending and are not authenticated', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'unit_assignment' => 'conservation',
        'office_designated' => 'CENRO Baganga',
        'section' => 'CENRO_CDS_FOCAL',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('registration_success', 'Your account has been created successfully and is awaiting administrator approval. You may sign in once your account has been activated.');
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'is_active' => false]);
});

test('registration success flash is available once on the login page', function () {
    $this->post('/register', [
        'name' => 'Flash Test User',
        'email' => 'flash-test@example.com',
        'unit_assignment' => 'conservation',
        'office_designated' => 'CENRO Baganga',
        'section' => 'CENRO_CDS_FOCAL',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->where('flash.registration_success', 'Your account has been created successfully and is awaiting administrator approval. You may sign in once your account has been activated.'));

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('flash.registration_success', null));
});
test('registration accepts the supported user category without assigning access', function (string $section) {
    $email = 'category-' . strtolower($section) . '@example.com';

    $response = $this->post('/register', [
        'name' => 'Category Test User',
        'email' => $email,
        'unit_assignment' => $section === 'PAMO' ? 'conservation' : 'development',
        'office_designated' => $section === 'PAMO' || str_starts_with($section, 'PENRO_') ? 'PENRO Davao Oriental' : 'CENRO Baganga',
        'section' => $section,
        ...($section === 'PAMO' ? ['protected_area_id' => null] : []),
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('login'));
    $this->assertGuest();

    $user = User::where('email', $email)->firstOrFail();

    expect($user->section)->toBe($section)
        ->and($user->is_active)->toBeFalse()
        ->and($user->roles()->pluck('name')->all())->toBe(['no_role']);
})->with(['CENRO_CDS_FOCAL', 'PENRO_CDS_FOCAL']);
