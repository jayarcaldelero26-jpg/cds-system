<?php

namespace App\Http\Middleware;

use App\Services\Authorization\OrganizationalAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePaPreparationAuthority
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function handle(Request $request, Closure $next, string $source): Response
    {
        $user = $request->user();
        $category = $user ? $this->organization->effectiveCategory($user) : null;

        if ($user && in_array($category, [
            OrganizationalAccessService::CENRO_RECORDS,
            OrganizationalAccessService::CENRO_CHIEF,
            OrganizationalAccessService::CENRO_FOCAL,
        ], true)) {
            abort_unless($this->organization->canPrepareProtectedAreaSource($user, $source), 403);
        }

        return $next($request);
    }
}
