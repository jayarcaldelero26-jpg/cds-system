<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = $request->user();

        // Legacy privileged accounts predate is_approved. Their authority is
        // role-based, so normalize only CDS Admin/Super Admin accounts here.
        if ($user && app(\App\Services\Authorization\OrganizationalAccessService::class)->isGlobal($user) && ! $user->is_approved) {
            $user->forceFill(['is_approved' => true])->saveQuietly();
        }

        if ($user && ! $user->is_approved) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();

            $request->session()->regenerateToken();

            return redirect()->route('login')->with('pending_approval', true);
        }

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();

            $request->session()->regenerateToken();

            return redirect()->route('login')->with('account_inactive', true);
        }

        $request->session()->regenerate();

        // Successful authentication always starts from the dashboard. Stale or unauthorized intended URLs must not control landing.
        $request->session()->forget(['url.intended', 'auth.login_destination']);

        return redirect()->route('dashboard');
    }

    private function canFollowIntended(Request $request, string $intended): bool
    {
        // Never dispatch an intended request here: controllers may have side
        // effects. Only preserve destinations whose route gates can be checked.
        $parts = parse_url($intended);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])
            || str_contains($intended, chr(92))
            || (isset($parts['host']) && strcasecmp($parts['host'], $request->getHost()) !== 0)
            || (isset($parts['scheme']) && $parts['scheme'] !== $request->getScheme())
            || (isset($parts['port']) && $parts['port'] !== $request->getPort())) {
            return false;
        }

        try {
            $probe = Request::create($intended, 'GET');
            $probe->setUserResolver(fn () => $request->user());
            $route = app('router')->getRoutes()->match($probe);
            $organization = app(\App\Services\Authorization\OrganizationalAccessService::class);

            foreach ($route->gatherMiddleware() as $middleware) {
                [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);
                if ($name === 'guest') return false;
                if ($name === 'admin' && ! $organization->isGlobal($request->user())) return false;
                if ($name === 'can' && $parameters !== null && ! str_contains($parameters, ',')
                    && ! $request->user()->can($parameters)) return false;
                if ($name === 'unit' && ! $organization->canAccessUnit($request->user(), $parameters ?? '')) return false;
            }

            // Reuse the existing path-based unit classification as well.
            app(\App\Http\Middleware\EnsureOrganizationalUnit::class)
                ->handle($probe, fn () => response('', 204));

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return false;
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // 🚀 GI-USAB KINI: Gi-diretso sa /login ug gi-butangan og no-cache headers
        // aron dili na ma-access ang dashboard gamit ang back button sa browser.
        return redirect('/login')->withHeaders([
            'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => 'Sat, 01 Jan 1990 00:00:00 GMT',
        ]);
    }
}
