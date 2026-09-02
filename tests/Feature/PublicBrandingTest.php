<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

test('the welcome page is public and exposes only safe overview aggregates', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('overview', 6)
            ->missing('overview.rows')
            ->missing('overview.users')
            ->missing('overview.attachments'));
});

test('welcome page source uses eDATS branding and the existing login route', function () {
    $welcome = File::get(resource_path('js/Pages/Welcome.jsx'));

    expect($welcome)
        ->toContain('Enhanced Digital Alert and Tracking System')
        ->toContain('PENRO Mati – Conservation and Development Section')
        ->toContain('Access Login')
        ->toContain('href="/login"')
        ->not->toContain('Secure')
        ->not->toContain('Confidential')
        ->not->toContain('Reliable')
        ->not->toMatch('/CDSIMS|CDS-IMS|CDS IMS|CDS System|CDIMS|Conservation and Development Information Management System/i');

    expect(route('login', absolute: false))->toBe('/login');
});

test('login and authenticated layouts follow the eDATS and DENR branding rules', function () {
    $login = File::get(resource_path('js/Layouts/AuthLayout.jsx'));
    $authenticated = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($login)
        ->toContain('eDATS')
        ->toContain('Enhanced Digital Alert and Tracking System')
        ->toContain('PENRO Mati – Conservation and Development Section');

    expect($authenticated)
        ->toContain('const logoSrc = "/images/DENR LOGO.png"')
        ->toContain('eDATS-CDS')
        ->toContain('Enhanced Digital Alert and Tracking System')
        ->toContain('Conservation and Development Section')
        ->not->toContain('eDATS-ENGP')
        ->not->toContain('CDS Logo.png')
        ->not->toMatch('/CDSIMS|CDS-IMS|CDS IMS|CDS System|CDIMS/i');
});

test('login presentation keeps accessible icon input and theme controls', function () {
    $layout = File::get(resource_path('js/Layouts/AuthLayout.jsx'));
    $login = File::get(resource_path('js/Pages/Auth/Login.jsx'));
    $authField = File::get(resource_path('js/Components/AuthField.jsx'));
    $flashDialog = File::get(resource_path('js/Components/FlashSuccessDialog.jsx'));

    expect($layout)
        ->toContain('ThemeIcon')
        ->toContain('dark:from-[#10231f]')
        ->toContain('BrandDivider');

    expect($login)
        ->toContain('autoComplete="email"')
        ->toContain('icon="mail"')
        ->toContain('icon="lock"')

        ->toContain('type="button"')
        ->toContain("showPassword ? 'text' : 'password'")
        ->toContain("showPassword ? 'Hide password' : 'Show password'")
        ->toContain('Forgot password?')
        ->toContain('Need an account?')
        ->toContain('Account Pending Approval')
        ->toContain('pendingApproval')
        ->not->toContain("{showPassword ? 'Hide' : 'Show'}")
        ->not->toContain('flex items-center rounded-xl border bg-white');

    expect($authField)
        ->toContain('leadingIcon')
        ->toContain('absolute right-2 top-6 z-20 flex h-11 items-center');

    expect($flashDialog)
        ->toContain('Registration Request Submitted')
        ->toContain('registration_success');
});

test('ENGP remains a program within the authenticated eDATS navigation', function () {
    $authenticated = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($authenticated)
        ->toContain("label: 'National Greening Program'")
        ->toContain("label: 'ENGP IAC Generator'")
        ->toContain("label: 'Report Submission Monitoring'")
        ->not->toContain("label: 'ENGP Dashboard'")
        ->not->toContain("label: 'OPERATIONS'");
});

test('the existing authentication entry points remain available', function () {
    $user = User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
});
