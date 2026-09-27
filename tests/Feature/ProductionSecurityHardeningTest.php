<?php

use App\Support\ProductionSecuritySettings;

test('production security settings override unsafe debug and session cookie values', function (): void {
    expect(ProductionSecuritySettings::debugEnabled('production', true))->toBeFalse()
        ->and(ProductionSecuritySettings::debugbarEnabled('production', true))->toBeFalse()
        ->and(ProductionSecuritySettings::secureCookie('production', false))->toBeTrue()
        ->and(ProductionSecuritySettings::httpOnlyCookie('production', false))->toBeTrue()
        ->and(ProductionSecuritySettings::sameSite('production', 'none'))->toBe('lax')
        ->and(ProductionSecuritySettings::sameSite('production', 'strict'))->toBe('strict');
});

test('local security settings preserve developer configuration', function (): void {
    expect(ProductionSecuritySettings::debugEnabled('local', true))->toBeTrue()
        ->and(ProductionSecuritySettings::debugbarEnabled('local', null))->toBeNull()
        ->and(ProductionSecuritySettings::secureCookie('local', false))->toBeFalse()
        ->and(ProductionSecuritySettings::httpOnlyCookie('local', true))->toBeTrue()
        ->and(ProductionSecuritySettings::sameSite('local', 'none'))->toBe('none');

    $this->get('/login')->assertOk()->assertDontSee('Strict-Transport-Security');
});

test('web responses include protective headers and production HSTS requires HTTPS', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeaderMissing('Strict-Transport-Security');

    app()->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production', 'app.debug' => false]);

    $this->get('https://cds-smart/login')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=15552000');

    $this->get('http://cds-smart/login')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('production CSP uses a request nonce and allows only required browser origins', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production', 'app.debug' => false]);

    $response = $this->get('/login')->assertOk();
    $policy = $response->headers->get('Content-Security-Policy');

    expect($policy)
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self' 'nonce-")
        ->toContain("script-src-attr 'none'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("frame-src 'self' blob:")
        ->toContain("object-src 'none'")
        ->toContain('https://psgc.cloud')
        ->toContain('https://*.tile.openstreetmap.org')
        ->toContain('https://server.arcgisonline.com')
        ->toContain('https://fonts.googleapis.com')
        ->toContain('https://fonts.gstatic.com')
        ->not->toContain("'unsafe-eval'");

    preg_match("/'nonce-([^']+)'/", (string) $policy, $matches);
    expect($matches[1] ?? null)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.($matches[1] ?? '').'"');
});

test('production HTTPS detection uses configured trusted proxy ranges', function (): void {
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'trustedproxy.proxies' => '127.0.0.1',
    ]);

    $this->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->get('/login')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=15552000');
});

test('local development and HMR responses do not receive the production CSP', function (): void {
    config(['app.env' => 'local']);

    $this->get('/login')
        ->assertOk()
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('authenticated profile and passkey page renders under production CSP', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production', 'app.debug' => false]);

    $user = \App\Models\User::factory()->create(['is_active' => true, 'is_approved' => true]);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Profile/Edit'))
        ->assertHeader('Content-Security-Policy');
});

test('production login page does not render developer diagnostics even if debug is misconfigured', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.env' => 'production',
        'app.debug' => true,
        'debugbar.enabled' => ProductionSecuritySettings::debugbarEnabled('production', true),
    ]);

    $this->get('/login')->assertOk()->assertDontSee('phpdebugbar')->assertDontSee('Debugbar');
});
