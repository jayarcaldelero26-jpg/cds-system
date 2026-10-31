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
        $workflowRegistry = app(ConservationReportWorkflowRegistry::class);
        $configuredPamoWorkflow = $workflowRegistry->supportsPamoSubmission($workflow, false);
        $pamoWorkflow = $configuredPamoWorkflow && (
            $workflowRegistry->supportsPamoSubmission($workflow)
            || $this->hasPamoWorkflowHistory($workflow, $user)
        );
        $nativeSources = app(\App\Services\Pamo\PamoNativeSourceRegistry::class);
        $nativeDraftWorkflow = $request->routeIs('conservation-reports.pamo-drafts.*')
            && $nativeSources->isSchemaReady() && $nativeSources->isConfigured($workflow)
            && ($nativeSources->isEligible($workflow) || $this->hasPamoWorkflowHistory($workflow, $user));
        $nativePageWorkflow = $request->routeIs('pamo-native-reports.*')
            && $nativeSources->isSchemaReady() && $nativeSources->isConfigured($workflow)
            && ($nativeSources->isEligible($workflow) || $this->hasPamoWorkflowHistory($workflow, $user));
        $pamoWorkspaceRoute = $request->routeIs('pamo-reports.index', 'pamo-archives.index', 'pamo-archives.file');
        $pamoPilotRequest = $unit === OrganizationalAccessService::CONSERVATION
            && ($pilotRoute || $pamoWorkspaceRoute)
            && ($pamoWorkflow || $nativeDraftWorkflow || $nativePageWorkflow || $pamoWorkspaceRoute)
            && $organization->effectiveCategory($user) === OrganizationalAccessService::PAMO
            && $organization->canViewPamoPilotWorkflow($user);

        abort_unless($pamoPilotRequest || $organization->canBrowseModuleUnit($user, $unit), 403);
        return $next($request);
    }

    private function hasPamoWorkflowHistory(string $workflow, \App\Models\User $user): bool
    {
        if ($user->protected_area_id === null) return false;

        if (app(ConservationReportWorkflowRegistry::class)->supportsPamoSubmission($workflow, false)) {
            return \App\Models\ConservationReportSubmission::query()
                ->where('workflow_key', $workflow)
                ->where('protected_area_id', $user->protected_area_id)
                ->exists();
        }

        $source = app(\App\Services\Pamo\PamoNativeSourceRegistry::class)->find($workflow);
        if (! $source) return false;

        $model = $source['model'];
        return $model::query()->where('protected_area_id', $user->protected_area_id)->exists();
    }
}
