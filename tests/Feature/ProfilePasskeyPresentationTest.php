<?php

test('profile passkey enrollment distinguishes insecure origins from unsupported browsers', function (): void {
    $profile = file_get_contents(resource_path('js/Pages/Profile/Edit.jsx'));

    expect($profile)
        ->toContain('window.isSecureContext === false')
        ->toContain('Passkeys require a secure HTTPS connection. Open CDS-SMART using HTTPS to register or use a passkey.')
        ->toContain('Passkeys are not supported in this browser or device.')
        ->toContain('window.PublicKeyCredential')
        ->toContain('navigator.credentials?.create')
        ->toContain('Passkeys.register');
});

test('profile passkey preflight runs before registration', function (): void {
    $profile = file_get_contents(resource_path('js/Pages/Profile/Edit.jsx'));

    expect(strpos($profile, 'const supportError = passkeySupportMessage();'))
        ->toBeLessThan(strpos($profile, 'Passkeys.register({ name: passkeyName.trim() })'));
});
