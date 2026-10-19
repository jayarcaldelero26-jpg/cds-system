<?php

namespace App\Http\Middleware;

use App\Models\MobileApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireMobileApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get('mobile_api_token');
        abort_unless($token instanceof MobileApiToken
            && in_array($ability, $token->abilities ?? [], true), 403);

        return $next($request);
    }
}
