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

    $addHandler = strpos($profile, 'const addPasskey = () => {');
    $preflight = strpos($profile, 'const supportError = passkeySupportMessage();', $addHandler);
    $guardedAction = strpos($profile, "startPasskeyAction({ type: 'register', name: passkeyName.trim() })", $preflight);
    $actionHandler = strpos($profile, 'const startPasskeyAction = (action) => {');
    $freshnessGuard = strpos($profile, 'if (!passwordIsFresh)', $actionHandler);
    $modal = strpos($profile, 'setPasswordDialogOpen(true)', $freshnessGuard);
    $directAction = strpos($profile, 'void performPasskeyAction(action)', $freshnessGuard);
    $performHandler = strpos($profile, 'const performPasskeyAction = async (action) => {');
    $registration = strpos($profile, 'await Passkeys.register({ name: action.name })');

    expect($addHandler)->toBeGreaterThanOrEqual(0)
        ->and($preflight)->toBeGreaterThan($addHandler)
        ->and($guardedAction)->toBeGreaterThan($preflight)
        ->and($freshnessGuard)->toBeGreaterThan($actionHandler)
        ->and($modal)->toBeGreaterThan($freshnessGuard)
        ->and($directAction)->toBeGreaterThan($freshnessGuard)
        ->and($registration)->toBeGreaterThan($performHandler);
});

test('passkey registration is a compact responsive row with plain password confirmation copy', function (): void {
    $profile = file_get_contents(resource_path('js/Pages/Profile/Edit.jsx'));

    expect($profile)
        ->toContain('sm:flex-row sm:items-end')
        ->toContain('h-11 w-full py-0 sm:w-auto sm:shrink-0')
        ->toContain('For your security, you may be asked to confirm your password before adding or removing a passkey.')
        ->not->toContain('password confirmation middleware');
});

test('passkey confirmation help text promises the current password dialog', function (): void {
    $profile = file_get_contents(resource_path('js/Pages/Profile/Edit.jsx'));

    expect($profile)
        ->toContain('For your security, you may be asked to confirm your password before adding or removing a passkey.')
        ->toContain('CurrentPasswordConfirmDialog');
});

test('passkey revocation uses the shared danger confirmation dialog without native confirm', function (): void {
    $profile = file_get_contents(resource_path('js/Pages/Profile/Edit.jsx'));

    expect($profile)
        ->toContain("import ConfirmDialog from '../../Components/ConfirmDialog';")
        ->not->toContain('window.confirm')
        ->toContain('setPasskeyRevokeConfirmation(action)')
        ->toContain('open={Boolean(passkeyRevokeConfirmation)}')
        ->toContain('title="Revoke passkey"')
        ->toContain('passkeyRevokeConfirmation?.name')
        ->toContain('confirmLabel="Revoke passkey"')
        ->toContain('variant="danger"')
        ->toContain('onCancel={() => setPasskeyRevokeConfirmation(null)}')
        ->toContain('onConfirm={confirmPasskeyRevoke}')
        ->toContain('processing={passkeyProcessing}');
});

test('profile keeps profile updates, password updates, and account deletion sections', function (): void {
    $profile = file_get_contents(resource_path('js/Pages/Profile/Edit.jsx'));

    expect($profile)
        ->toContain('Profile information')
        ->toContain("profile.patch('/profile')")
        ->toContain('Update password')
        ->toContain("password.put('/password')")
        ->toContain('Delete account')
        ->toContain("remove.delete('/profile'");
});
