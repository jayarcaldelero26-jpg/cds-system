<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

beforeEach(function (): void {
    $this->withoutVite();
});

test('legacy mutation success feedback is bridged once to Inertia flash and never minted by a read', function (): void {
    Route::middleware('web')->post('/_tests/legacy-success', fn () => redirect('/_tests/legacy-success-landing')->with('success', 'Saved successfully.'));
    Route::middleware('web')->get('/_tests/legacy-success-landing', fn (Request $request) => Inertia::render('Test/SuccessFlash', [
        'record' => 'read-only',
    ]));

    $this->post('/_tests/legacy-success')->assertRedirect('/_tests/legacy-success-landing');
    $firstRead = $this->get('/_tests/legacy-success-landing')->assertOk();
    $firstPage = $firstRead->viewData('page');

    expect(data_get($firstPage, 'flash.success'))->toBe('Saved successfully.')
        ->and(data_get($firstPage, 'props.flash.success'))->toBe('Saved successfully.');

    $readAgain = $this->get('/_tests/legacy-success-landing')->assertOk();
    $secondPage = $readAgain->viewData('page');
    expect(data_get($secondPage, 'flash', []))->toBe([])
        ->and(data_get($secondPage, 'props.flash.success'))->toBeNull();
});
