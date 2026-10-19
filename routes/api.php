<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileSubmissionTrackingController;
use App\Http\Middleware\AuthenticateMobileApiToken;
use App\Http\Middleware\PreventMobileApiCaching;
use App\Http\Middleware\RequireMobileApiAbility;
use App\Http\Middleware\RequireSecureMobileApiRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->middleware([
    PreventMobileApiCaching::class,
    RequireSecureMobileApiRequest::class,
])->group(function (): void {
    Route::post('login', [MobileAuthController::class, 'login']);

    Route::middleware(AuthenticateMobileApiToken::class)->group(function (): void {
        Route::get('me', [MobileAuthController::class, 'me'])->middleware(RequireMobileApiAbility::class.':mobile:auth');
        Route::post('logout', [MobileAuthController::class, 'logout'])->middleware(RequireMobileApiAbility::class.':mobile:auth');

        Route::middleware(RequireMobileApiAbility::class.':submission-tracking:read')->group(function (): void {
            Route::get('submission-tracking/{queue}', [MobileSubmissionTrackingController::class, 'index'])
                ->whereIn('queue', ['incoming', 'outgoing', 'history']);
            Route::get('submission-tracking/documents/{source}/{record}', [MobileSubmissionTrackingController::class, 'show'])
                ->where('source', '[a-z][a-z0-9-]*')->whereNumber('record');
        });
    });
});
