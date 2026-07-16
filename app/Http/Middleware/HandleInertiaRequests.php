<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'canManageUsers' => fn (): bool => $request->user()?->hasRole('CDS Admin') ?? false,

                // Protected Areas
                'canViewProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.view') ?? false,
                'canCreateProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.create') ?? false,
                'canUpdateProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.update') ?? false,
                'canDeleteProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.delete') ?? false,

                // Management Plans
                'canViewManagementPlans' => fn (): bool => $request->user()?->can('management-plans.view') ?? false,
                'canCreateManagementPlans' => fn (): bool => $request->user()?->can('management-plans.create') ?? false,
                'canUpdateManagementPlans' => fn (): bool => $request->user()?->can('management-plans.update') ?? false,
                'canDeleteManagementPlans' => fn (): bool => $request->user()?->can('management-plans.delete') ?? false,

                // Technical Reports
                'canViewTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.view') ?? false,
                'canCreateTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.create') ?? false,
                'canUpdateTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.update') ?? false,
                'canDeleteTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.delete') ?? false,

                // Ecotourism Impact Monitoring
                'canViewEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.view') ?? false,
                'canCreateEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.create') ?? false,
                'canUpdateEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.update') ?? false,
                'canDeleteEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.delete') ?? false,

                // Issues Monitoring
                'canViewIssueMonitoring' => fn (): bool => $request->user()?->can('issue-monitoring.view') ?? false,
                'canCreateIssueMonitoring' => fn (): bool => $request->user()?->can('issue-monitoring.create') ?? false,
                'canUpdateIssueMonitoring' => fn (): bool => $request->user()?->can('issue-monitoring.update') ?? false,
                'canDeleteIssueMonitoring' => fn (): bool => $request->user()?->can('issue-monitoring.delete') ?? false,

                // LAWIN Monitoring (GI-CORRECT: Gigamitan og saktong can() check imbes hasRole)
                'canViewLawinMonitoring' => fn (): bool => $request->user()?->can('lawin-monitoring.view') ?? false,
                'canCreateLawinMonitoring' => fn (): bool => $request->user()?->can('lawin-monitoring.create') ?? false,
                'canUpdateLawinMonitoring' => fn (): bool => $request->user()?->can('lawin-monitoring.update') ?? false,
                'canDeleteLawinMonitoring' => fn (): bool => $request->user()?->can('lawin-monitoring.delete') ?? false,

                // PROGRAMS, PROJECTS & ACTIVITIES (PPA) (GI-CORRECT: Saktong can() check aron makita sa Staff)
                'canViewPPA' => fn (): bool => $request->user()?->can('programs-projects-activities.view') ?? false,
                'canCreatePPA' => fn (): bool => $request->user()?->can('programs-projects-activities.create') ?? false,
                'canUpdatePPA' => fn (): bool => $request->user()?->can('programs-projects-activities.update') ?? false,
                'canDeletePPA' => fn (): bool => $request->user()?->can('programs-projects-activities.delete') ?? false,

                // Reports View
                'canViewReports' => fn (): bool => $request->user()?->can('reports.view') ?? false,
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
