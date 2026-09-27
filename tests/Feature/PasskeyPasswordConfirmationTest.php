<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Spatie\Permission\Models\Role;

function passkeyConfirmationAdmin(): User
{
    Role::findOrCreate('Super Admin', 'web');
    $admin = User::factory()->create(['is_active' => true, 'is_approved' => true]);
    $admin->assignRole('Super Admin');

    return $admin;
}

test('passkey registration options remain protected and stale JSON requests receive Laravel confirmation response', function (): void {
    $admin = passkeyConfirmationAdmin();

    $this->actingAs($admin)
        ->getJson(route('passkey.registration-options'))
        ->assertStatus(423)
        ->assertJson(['message' => 'Password confirmation required.']);

    $this->postJson(route('passkey.store'), [])->assertStatus(423);

    expect($admin->passkeys()->count())->toBe(0);
});

test('valid JSON current-password confirmation establishes middleware state and permits the protected passkey action', function (): void {
    $admin = passkeyConfirmationAdmin();

    $this->actingAs($admin)
        ->postJson('/confirm-password', ['password' => 'password'])
        ->assertOk()
        ->assertJson(['confirmed' => true])
        ->assertSessionHas('auth.password_confirmed_at');

    $this->getJson(route('passkey.registration-options'))->assertOk();
});

test('invalid JSON password confirmation returns a validation error and leaves passkeys unchanged', function (): void {
    $admin = passkeyConfirmationAdmin();

    $this->actingAs($admin)
        ->postJson('/confirm-password', ['password' => 'not-the-current-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password')
        ->assertSessionMissing('auth.password_confirmed_at');

    $this->getJson(route('passkey.registration-options'))->assertStatus(423);
    expect($admin->passkeys()->count())->toBe(0);
});

test('stale remove request is blocked without deleting the passkey and confirmed removal can proceed', function (): void {
    $admin = passkeyConfirmationAdmin();
    $passkey = $admin->passkeys()->create([
        'name' => 'Isolated test credential',
        'credential_id' => 'test-credential-'.str()->random(24),
        'credential' => ['id' => 'test-credential'],
    ]);

    $this->actingAs($admin)
        ->deleteJson(route('passkey.destroy', $passkey->id))
        ->assertStatus(423)
        ->assertJson(['message' => 'Password confirmation required.']);

    $this->assertDatabaseHas('passkeys', ['id' => $passkey->id, 'user_id' => $admin->id]);

    $this->postJson('/confirm-password', ['password' => 'password'])->assertOk();
    $this->deleteJson(route('passkey.destroy', $passkey->id))->assertOk();
    $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
});

test('profile exposes confirmation freshness for the modal without weakening the route guard', function (): void {
    $admin = passkeyConfirmationAdmin();

    $this->actingAs($admin)->get('/profile')->assertInertia(fn (Assert $page) => $page
        ->component('Profile/Edit')
        ->where('passwordConfirmationFresh', false));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('passwordConfirmationFresh', true));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout') - 1])
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('passwordConfirmationFresh', false));
});

test('profile passkey actions use the reusable current-password dialog and resume only after confirmation', function (): void {
    $profile = file_get_contents(resource_path('js/Pages/Profile/Edit.jsx'));
    $dialog = file_get_contents(resource_path('js/Components/CurrentPasswordConfirmDialog.jsx'));
    $cancelStart = strpos($profile, 'const cancelPasswordConfirmation = () => {');
    $cancelEnd = strpos($profile, 'const revokePasskey =', $cancelStart);
    $cancelHandler = substr($profile, $cancelStart, $cancelEnd - $cancelStart);

    expect($profile)
        ->toContain('CurrentPasswordConfirmDialog')
        ->toContain('if (!passwordIsFresh)')
        ->toContain('setPendingPasskeyAction(action)')
        ->toContain('performPasskeyAction(action)')
        ->toContain('const cancelPasswordConfirmation')
        ->and($cancelHandler)
        ->toContain('setPasswordDialogOpen(false)')
        ->toContain('setPendingPasskeyAction(null)')
        ->not->toContain('performPasskeyAction')
        ->and($dialog)
        ->toContain("role=\"dialog\"")
        ->toContain('aria-modal="true"')
        ->toContain('autoComplete="current-password"')
        ->toContain("fetch('/confirm-password'")
        ->toContain('payload.confirmed !== true')
        ->toContain('role="alert"')
        ->toContain("event.key === 'Escape'")
        ->toContain("event.key !== 'Tab'")
        ->toContain('setPassword(\'\')');
});
