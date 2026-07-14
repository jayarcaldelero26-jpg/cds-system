<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCdsAdmin
{
    /**
     * Restrict user management routes to CDS administrators.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasRole('CDS Admin'), 403);

        return $next($request);
    }
}
