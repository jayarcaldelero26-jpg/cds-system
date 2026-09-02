<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Inertia\Inertia;
use App\Http\Middleware\PreventBackHistory;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureOrganizationalUnit::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            PreventBackHistory::class, // 🚀 Gidugang kini dinhi aron ma-prevent ang back button cache
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsCdsAdmin::class,
            'unit' => \App\Http\Middleware\EnsureOrganizationalUnit::class,
        ]);

        // 🚀 Gidugang kini aron ma-except ang logout sa CSRF check ug malikayan ang 419 error
        $middleware->validateCsrfTokens(except: [
            'logout',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return Inertia::render('Errors/404')
                ->toResponse($request)
                ->setStatusCode(404);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('bms', 'bms/*')) {
                return null;
            }

            return Inertia::render('Errors/403', [
                'message' => 'You do not have permission to perform this BMS action.',
            ])->toResponse($request)->setStatusCode(403);
        });
    })->create();
