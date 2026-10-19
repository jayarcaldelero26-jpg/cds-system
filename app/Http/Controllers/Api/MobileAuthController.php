<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginAttemptLimiter;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class MobileAuthController extends Controller
{
    private const AUTH_ABILITY = 'mobile:auth';

    private const TRACKING_ABILITY = 'submission-tracking:read';

    public function login(Request $request, LoginAttemptLimiter $limiter, OrganizationalAccessService $organization): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $limitKey = $limiter->ensureAllowed($request, $credentials['email']);

        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $limiter->hit($limitKey);
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }

        $limiter->clear($limitKey);

        // Mobile login requires an existing active web account. Tracking access
        // is granted separately below through the organizational access policy.
        if (! $user->is_active) {
            return response()->json(['message' => 'This account is not eligible to sign in.'], 403);
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $abilities = [self::AUTH_ABILITY];
        if ($organization->canViewSubmissionTracking($user)) {
            $abilities[] = self::TRACKING_ABILITY;
        }

        $token = $user->mobileApiTokens()->create([
            'name' => 'CDS-SMART Mobile',
            'token' => hash('sha256', $secret),
            'abilities' => $abilities,
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'token' => $token->getKey().'|'.$secret,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('mobile_api_token')?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
