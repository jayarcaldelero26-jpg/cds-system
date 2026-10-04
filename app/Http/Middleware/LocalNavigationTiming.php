<?php

namespace App\Http\Middleware;

use App\Support\LocalNavigationTrace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LocalNavigationTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        LocalNavigationTrace::begin($request);

        try {
            $response = $next($request);

            LocalNavigationTrace::finish($request, $response);

            return $response;
        } finally {
            LocalNavigationTrace::end($request);
        }
    }
}
