<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

function sharedLoginLimiterTestKey(string $email, string $ip = '127.0.0.1'): string
{
    return Str::transliterate(Str::lower($email).'|'.$ip);
}

beforeEach(function (): void {
    $this->sharedLoginPassword = 'fictional-shared-login-password';
    $this->sharedLoginUser = User::factory()->create([
        'email' => 'shared-limiter@example.test',
        'password' => $this->sharedLoginPassword,
        'is_active' => true,
        'is_approved' => true,
        'email_verified_at' => now(),
    ]);
    $this->otherLoginUser = User::factory()->create([
        'email' => 'other-limiter@example.test',
        'password' => $this->sharedLoginPassword,
        'is_active' => true,
        'is_approved' => true,
        'email_verified_at' => now(),
    ]);

    RateLimiter::clear(sharedLoginLimiterTestKey($this->sharedLoginUser->email));
    RateLimiter::clear(sharedLoginLimiterTestKey($this->otherLoginUser->email));
    RateLimiter::clear(sharedLoginLimiterTestKey($this->sharedLoginUser->email, '198.51.100.44'));
});

it('keeps web login blocked after five failed attempts for normalized email and IP', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post('/login', [
            'email' => strtoupper($this->sharedLoginUser->email),
            'password' => 'incorrect-fictional-password',
        ])->assertSessionHasErrors('email');
    }

    $this->post('/login', [
        'email' => $this->sharedLoginUser->email,
        'password' => $this->sharedLoginPassword,
    ])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('keeps mobile login blocked after five failed attempts for normalized email and IP', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/mobile/login', [
            'email' => strtoupper($this->sharedLoginUser->email),
            'password' => 'incorrect-fictional-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/mobile/login', [
        'email' => $this->sharedLoginUser->email,
        'password' => $this->sharedLoginPassword,
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('shares the five-attempt budget across web and mobile without weakening web protection', function (): void {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/login', [
            'email' => $this->sharedLoginUser->email,
            'password' => 'incorrect-fictional-password',
        ])->assertSessionHasErrors('email');
    }
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->postJson('/api/mobile/login', [
            'email' => $this->sharedLoginUser->email,
            'password' => 'incorrect-fictional-password',
        ])->assertUnprocessable();
    }

    expect(RateLimiter::attempts(sharedLoginLimiterTestKey($this->sharedLoginUser->email)))->toBe(5);
    $this->post('/login', [
        'email' => $this->sharedLoginUser->email,
        'password' => $this->sharedLoginPassword,
    ])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('does not share attempts across different emails or client IP addresses', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/mobile/login', [
            'email' => $this->sharedLoginUser->email,
            'password' => 'incorrect-fictional-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/mobile/login', [
        'email' => $this->otherLoginUser->email,
        'password' => $this->sharedLoginPassword,
    ])->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44'])->post('/login', [
        'email' => $this->sharedLoginUser->email,
        'password' => $this->sharedLoginPassword,
    ])->assertRedirect(route('dashboard', absolute: false));
});

it('clears only the shared email and IP counter after successful credentials', function (): void {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/login', [
            'email' => $this->sharedLoginUser->email,
            'password' => 'incorrect-fictional-password',
        ])->assertSessionHasErrors('email');
    }
    expect(RateLimiter::attempts(sharedLoginLimiterTestKey($this->sharedLoginUser->email)))->toBe(3);

    $this->postJson('/api/mobile/login', [
        'email' => $this->sharedLoginUser->email,
        'password' => $this->sharedLoginPassword,
    ])->assertOk();
    expect(RateLimiter::attempts(sharedLoginLimiterTestKey($this->sharedLoginUser->email)))->toBe(0);

    $this->post('/login', [
        'email' => $this->otherLoginUser->email,
        'password' => 'incorrect-fictional-password',
    ])->assertSessionHasErrors('email');
    expect(RateLimiter::attempts(sharedLoginLimiterTestKey($this->otherLoginUser->email)))->toBe(1);
});