<?php

namespace App\Http\Middleware;

use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
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
                $request->is('protected-areas', 'protected-areas/*', 'management-plans', 'management-plans/*', 'conservation-reports', 'conservation-reports/*', 'pamo-native-reports', 'pamo-native-reports/*', 'ecotourism-monitorings', 'ecotourism-monitorings/*', 'issue-monitorings', 'issue-monitorings/*', 'lawin-monitorings', 'lawin-monitorings/*', 'cds-lawin', 'cds-lawin/*', 'bms', 'bms/*', 'bams', 'bams/*', 'imea', 'imea/*', 'aws', 'aws/*', 'ipaf', 'ipaf/*', 'program-project-activities', 'program-project-activities/*') => OrganizationalAccessService::CONSERVATION,
                default => null,
            };
        }

        if (! $unit || ! $request->user()) return $next($request);
        $organization = app(OrganizationalAccessService::class);
        $user = $request->user();
        $pilotRoute = $request->routeIs(
                'conservation-reports.index',
                'conservation-reports.mov',
                'conservation-reports.pamo-drafts.*',
                'conservation-reports.update',
                'pamo-native-reports.*',
            );
        $workflow = (string) $request->route('workflow');
        $pamoWorkflow = app(ConservationReportWorkflowRegistry::class)->supportsPamoSubmission($workflow);
        $nativeSources = app(\App\Services\Pamo\PamoNativeSourceRegistry::class);
        $nativeDraftWorkflow = $request->routeIs('conservation-reports.pamo-drafts.*')
            && $nativeSources->isSchemaReady() && $nativeSources->isEligible($workflow);
        $nativePageWorkflow = $request->routeIs('pamo-native-reports.*')
            && $nativeSources->isSchemaReady() && $nativeSources->isEligible($workflow);
        $pamoWorkspaceRoute = $request->routeIs('pamo-reports.index', 'pamo-archives.index', 'pamo-archives.file');
        $pamoPilotRequest = $unit === OrganizationalAccessService::CONSERVATION
            && ($pilotRoute || $pamoWorkspaceRoute)
            && ($pamoWorkflow || $nativeDraftWorkflow || $nativePageWorkflow || $pamoWorkspaceRoute)
            && $organization->effectiveCategory($user) === OrganizationalAccessService::PAMO
            && $organization->canViewPamoPilotWorkflow($user);

        abort_unless($pamoPilotRequest || $organization->canBrowseModuleUnit($user, $unit), 403);
        return $next($request);
    }
}
