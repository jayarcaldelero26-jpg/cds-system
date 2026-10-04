<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Inertia\Inertia;
use App\Http\Middleware\PreventBackHistory;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            null,
            Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(prepend: [
            \App\Http\Middleware\LocalNavigationTiming::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\ResolveLoginDestination::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureOrganizationalUnit::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\SecurityHeaders::class,
            PreventBackHistory::class, // 🚀 Gidugang kini dinhi aron ma-prevent ang back button cache
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsCdsAdmin::class,
            'unit' => \App\Http\Middleware\EnsureOrganizationalUnit::class,
            'pa-preparation' => \App\Http\Middleware\EnsurePaPreparationAuthority::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || ($request->is('confirm-password') && $request->expectsJson()),
        );

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return Inertia::render('Errors/404')
                ->toResponse($request)
                ->setStatusCode(404);
        });

        $exceptions->render(function (AuthorizationException|HttpExceptionInterface $e, Request $request) {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() !== 403) return null;

            return Inertia::render('Errors/403')
                ->toResponse($request)
                ->setStatusCode(403);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419) return null;

            return Inertia::render('Errors/419')->toResponse($request)->setStatusCode(419);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! app()->isProduction()) return null;

            return Inertia::render('Errors/500')->toResponse($request)->setStatusCode(500);
        });
    })->create();
