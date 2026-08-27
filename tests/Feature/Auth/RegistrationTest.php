<?php

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
        'office_designated' => 'PENRO Davao Oriental',
        'section' => 'CDS',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect('/');
    $response->assertSessionHas('success', 'Your account was created successfully and is pending administrator approval.');
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'is_active' => false]);
});
