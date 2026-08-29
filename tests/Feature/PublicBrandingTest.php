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
        ->toContain('Access Secure Login')
        ->toContain('href="/login"')
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

    expect($layout)
        ->toContain('ThemeIcon')
        ->toContain('dark:from-[#10231f]')
        ->toContain('BrandDivider');

    expect($login)
        ->toContain('autoComplete="email"')
        ->toContain('icon="mail"')
        ->toContain('icon="lock"')
        ->toContain('absolute inset-y-0 left-0')
        ->toContain('type="button"')
        ->toContain("showPassword ? 'text' : 'password'")
        ->toContain("showPassword ? 'Hide password' : 'Show password'")
        ->toContain('Forgot password?')
        ->toContain('Need an account?')
        ->not->toContain("{showPassword ? 'Hide' : 'Show'}")
        ->not->toContain('flex items-center rounded-xl border bg-white');
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
