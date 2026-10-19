<?php

test('rejects insecure mobile API requests from a non-local client without redirecting', function (): void {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.24',
        'HTTP_HOST' => 'cds-system.test',
    ])->postJson('/api/mobile/login', []);

    $response->assertStatus(426)
        ->assertJsonPath('code', 'https_required')
        ->assertHeaderMissing('Location');
});

test('allows HTTPS mobile API requests to reach the endpoint', function (): void {
    $this->postJson('https://cds-system.test/api/mobile/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('allows HTTPS reported by a configured trusted proxy', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'cds-system.test',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->postJson('/api/mobile/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('does not accept an untrusted forwarded HTTPS header', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.24',
        'HTTP_HOST' => 'cds-system.test',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->postJson('/api/mobile/login', [])
        ->assertStatus(426)
        ->assertJsonPath('code', 'https_required');
});

test('allows HTTP only for loopback requests in local and testing environments', function (): void {
    foreach (['local', 'testing'] as $environment) {
        app()->detectEnvironment(fn () => $environment);
        config(['app.env' => $environment]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'cds-system.test',
        ])->postJson('/api/mobile/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }
});

test('production rejects HTTP even when the client and host are local', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production']);

    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ])->postJson('/api/mobile/login', [])
        ->assertStatus(426)
        ->assertJsonPath('code', 'https_required');
});
