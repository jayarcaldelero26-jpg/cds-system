<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Fall back only for the first destination selected by a successful login. */
final class ResolveLoginDestination
{
    public function handle(Request $request, Closure $next): Response
    {
        $destination = $request->session()->pull('auth.login_destination');
        if (! $request->user() || ! $request->isMethod('GET')
            || $destination !== $request->getRequestUri()) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (AuthorizationException $exception) {
            // Policies may deliberately hide records behind a 404.
            if ($exception->hasStatus() && $exception->status() !== 403) throw $exception;
            return redirect()->route('dashboard');
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 403) throw $exception;
            return redirect()->route('dashboard');
        }

        return $response->getStatusCode() === 403
            ? redirect()->route('dashboard')
            : $response;
    }
}
