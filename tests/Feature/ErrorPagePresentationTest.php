<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

function configureProductionErrorPresentation(): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
    ]);
}

test('forbidden web requests retain 403 and render the CDS-SMART restricted page', function (): void {
    configureProductionErrorPresentation();
    Route::get('/__verification/errors/403', fn () => abort(403));

    $this->actingAs(User::factory()->create())
        ->get('/__verification/errors/403')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Errors/403'))
        ->assertDontSee('Debugbar')
        ->assertDontSee('Stack trace');
});

test('missing web pages retain 404 and render the not found page', function (): void {
    configureProductionErrorPresentation();
    $this->get('/__verification/errors/not-found')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('Errors/404'));
});

test('expired sessions retain 419 and render the session expired page', function (): void {
    configureProductionErrorPresentation();
    Route::get('/__verification/errors/419', fn () => abort(419));

    $this->get('/__verification/errors/419')
        ->assertStatus(419)
        ->assertSee('"component":"Errors\\/419"', false)
        ->assertSee('419-');
});

test('production exceptions retain 500 and render no exception details', function (): void {
    configureProductionErrorPresentation();
    Route::get('/__verification/errors/500', fn () => throw new RuntimeException('verification-secret-stack-marker'));

    $this->get('/__verification/errors/500')
        ->assertInternalServerError()
        ->assertSee('"component":"Errors\\/500"', false)
        ->assertSee('500-')
        ->assertDontSee('verification-secret-stack-marker')
        ->assertDontSee('RuntimeException');
});
