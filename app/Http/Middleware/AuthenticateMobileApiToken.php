<?php

namespace App\Http\Middleware;

use App\Models\MobileApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMobileApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();
        if (! is_string($plainTextToken) || ! str_contains($plainTextToken, '|')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        [$tokenId, $secret] = explode('|', $plainTextToken, 2);
        if (! ctype_digit($tokenId) || $secret === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = MobileApiToken::query()->with('user')->find((int) $tokenId);
        if (! $token || ! hash_equals($token->token, hash('sha256', $secret))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (($token->expires_at && $token->expires_at->isPast())
            || ! $token->user
            || ! $token->user->is_active) {
            $token->delete();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('mobile_api_token', $token);
        $request->setUserResolver(fn () => $token->user);
        Auth::guard()->setUser($token->user);

        try {
            return $next($request);
        } finally {
            Auth::forgetGuards();
        }
    }
}
