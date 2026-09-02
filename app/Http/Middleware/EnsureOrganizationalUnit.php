<?php

namespace App\Http\Middleware;

use App\Services\Authorization\OrganizationalAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOrganizationalUnit
{
    public function handle(Request $request, Closure $next, ?string $unit = null): Response
    {
        if (! $unit) {
            $unit = match (true) {
                $request->is('engp-reports', 'engp-reports/*') => OrganizationalAccessService::DEVELOPMENT,
                $request->is('protected-areas', 'protected-areas/*', 'management-plans', 'management-plans/*', 'conservation-reports', 'conservation-reports/*', 'ecotourism-monitorings', 'ecotourism-monitorings/*', 'issue-monitorings', 'issue-monitorings/*', 'lawin-monitorings', 'lawin-monitorings/*', 'cds-lawin', 'cds-lawin/*', 'bms', 'bms/*', 'bams', 'bams/*', 'imea', 'imea/*', 'aws', 'aws/*', 'ipaf', 'ipaf/*', 'program-project-activities', 'program-project-activities/*') => OrganizationalAccessService::CONSERVATION,
                default => null,
            };
        }

        if (! $unit || ! $request->user()) return $next($request);
        abort_unless(app(OrganizationalAccessService::class)->canAccessUnit($request->user(), $unit), 403);
        return $next($request);
    }
}
