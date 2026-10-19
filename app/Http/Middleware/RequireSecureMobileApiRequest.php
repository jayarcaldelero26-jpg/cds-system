<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSecureMobileApiRequest
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', 'cds-system.test'];

    private const LOOPBACK_ADDRESSES = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isSecure() || $this->isLocalDevelopmentRequest($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'HTTPS is required for the mobile API.',
            'code' => 'https_required',
        ], 426);
    }

    private function isLocalDevelopmentRequest(Request $request): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        return in_array(strtolower($request->getHost()), self::LOCAL_HOSTS, true)
            && in_array($request->server->get('REMOTE_ADDR'), self::LOOPBACK_ADDRESSES, true);
    }
}
