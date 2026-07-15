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

                'canViewProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.view') ?? false,
                'canCreateProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.create') ?? false,
                'canUpdateProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.update') ?? false,
                'canDeleteProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.delete') ?? false,

                'canViewManagementPlans' => fn (): bool => $request->user()?->can('management-plans.view') ?? false,
                'canCreateManagementPlans' => fn (): bool => $request->user()?->can('management-plans.create') ?? false,
                'canUpdateManagementPlans' => fn (): bool => $request->user()?->can('management-plans.update') ?? false,
                'canDeleteManagementPlans' => fn (): bool => $request->user()?->can('management-plans.delete') ?? false,

                'canViewTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.view') ?? false,
                'canCreateTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.create') ?? false,
                'canUpdateTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.update') ?? false,
                'canDeleteTechnicalReports' => fn (): bool => $request->user()?->can('technical-reports.delete') ?? false,

                // Ecotourism Impact Monitoring Permissions (GI-DUGANG KINI)
                'canViewEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.view') ?? false,
                'canCreateEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.create') ?? false,
                'canUpdateEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.update') ?? false,
                'canDeleteEcotourismMonitoring' => fn (): bool => $request->user()?->can('ecotourism-monitoring.delete') ?? false,
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
