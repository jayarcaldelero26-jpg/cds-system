<?php

namespace App\Http\Middleware;

use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\PambComplianceCalculator;
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
        $organization = app(OrganizationalAccessService::class);
        $user = $request->user();
        $pamoPilotRequest = $unit === OrganizationalAccessService::CONSERVATION
            && $request->routeIs(
                'conservation-reports.index',
                'conservation-reports.pamo-drafts.*',
                'conservation-reports.update',
            )
            && in_array($request->route('workflow'), PambComplianceCalculator::MEETING_WORKFLOWS, true)
            && $organization->effectiveCategory($user) === OrganizationalAccessService::PAMO
            && $organization->canViewPamoPilotWorkflow($user);

        abort_unless($pamoPilotRequest || $organization->canBrowseModuleUnit($user, $unit), 403);
        return $next($request);
    }
}
