<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next)
    {
        // I-check kung naka-login ang user pero gi-deactivate siya (is_active = false)
        if (Auth::check() && !Auth::user()->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated or is currently pending administrator approval.',
            ]);
        }

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        // Check kon Admin ba o Staff ba ang user
        $isAdmin = $user?->hasAnyRole(['Super Admin', 'CDS Admin', 'Admin']) ?? false;
        $isStaff = $user?->hasAnyRole(['Staff', 'staff']) ?? false;

        // Susiha ang section sa user ('CDS' o 'MES')
        $userSection = $user?->section ?? '';
        $isMes = ($userSection === 'MES'); // Monitoring and Enforcement Section
        $isCds = ($userSection === 'CDS'); // Conservation Development Section

        // Susiha kung Technical Staff ba ang nag-log in
        $isTechnicalStaff = $user?->hasRole('Technical Staff') ?? false;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'office_designated' => $user->office_designated,
                    'section' => $user->section,
                    'is_active' => $user->is_active,
                ] : null,
                'canManageUsers' => $isAdmin,

                // 🚀 SECTION-BASED PERMISSIONS FILTERING

                // Protected Areas (CDS ra)
                'canViewProtectedAreas' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('protected-areas.view') ?? false))),
                'canCreateProtectedAreas' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('protected-areas.create') ?? false))),
                'canUpdateProtectedAreas' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('protected-areas.update') ?? false))),
                'canDeleteProtectedAreas' => $isAdmin,

                // Management Plans (CDS ra)
                'canViewManagementPlans' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('management-plans.view') ?? false))),
                'canCreateManagementPlans' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('management-plans.create') ?? false))),
                'canUpdateManagementPlans' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('management-plans.update') ?? false))),
                'canDeleteManagementPlans' => $isAdmin,

                // Technical Reports (CDS ra)
                'canViewTechnicalReports' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('technical-reports.view') ?? false))),
                'canCreateTechnicalReports' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('technical-reports.create') ?? false))),
                'canUpdateTechnicalReports' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('technical-reports.update') ?? false))),
                'canDeleteTechnicalReports' => $isAdmin,

                // Ecotourism Impact Monitoring (CDS ra)
                'canViewEcotourismMonitoring' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('ecotourism-monitoring.view') ?? false))),
                'canCreateEcotourismMonitoring' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('ecotourism-monitoring.create') ?? false))),
                'canUpdateEcotourismMonitoring' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('ecotourism-monitoring.update') ?? false))),
                'canDeleteEcotourismMonitoring' => $isAdmin,

                // Issues Monitoring (Pwede sa MES ug CDS)
                'canViewIssueMonitoring' => $isAdmin || $isStaff || ($user?->can('issue-monitoring.view') ?? false),
                'canCreateIssueMonitoring' => $isAdmin || $isStaff || ($user?->can('issue-monitoring.create') ?? false),
                'canUpdateIssueMonitoring' => $isAdmin || $isStaff || ($user?->can('issue-monitoring.update') ?? false),
                'canDeleteIssueMonitoring' => $isAdmin,

                // LAWIN Monitoring (GI-BLOCK DIRI PARA SA TECHNICAL STAFF)
                'canViewLawinMonitoring' => $isAdmin || (!$isTechnicalStaff && ($isStaff || ($user?->can('lawin-monitoring.view') ?? false))),
                'canCreateLawinMonitoring' => $isAdmin || (!$isTechnicalStaff && ($isStaff || ($user?->can('lawin-monitoring.create') ?? false))),
                'canUpdateLawinMonitoring' => $isAdmin || (!$isTechnicalStaff && ($isStaff || ($user?->can('lawin-monitoring.update') ?? false))),
                'canDeleteLawinMonitoring' => $isAdmin,

                // Automated Weather Station (AWS)
                'canViewAws' => $isAdmin || ($user?->can('aws.view') ?? false),
                'canCreateAws' => $isAdmin || ($user?->can('aws.create') ?? false),
                'canUpdateAws' => $isAdmin || ($user?->can('aws.update') ?? false),
                'canDeleteAws' => $isAdmin || ($user?->can('aws.delete') ?? false),

                // Biodiversity Monitoring System (BMS)
                'canViewBms' => $user?->can('bms.view') ?? false,
                'canCreateBms' => $user?->can('bms.create') ?? false,
                'canUpdateBms' => $user?->can('bms.update') ?? false,
                'canDeleteBms' => $user?->can('bms.delete') ?? false,

                // Biodiversity Assessment and Monitoring System (BAMS)
                'canViewBams' => $user?->can('bams.view') ?? false,
                'canCreateBams' => $user?->can('bams.create') ?? false,
                'canUpdateBams' => $user?->can('bams.update') ?? false,
                'canDeleteBams' => $user?->can('bams.delete') ?? false,
                'canManageBamsSpatial' => $user?->can('bams.manage-spatial') ?? false,
                'canCalculateBams' => $user?->can('bams.calculate') ?? false,

                // Integrated Management Effectiveness Assessment (IMEA)
                'canViewImea' => $user?->can('imea.view') ?? false,
                'canCreateImea' => $user?->can('imea.create') ?? false,
                'canUpdateImea' => $user?->can('imea.update') ?? false,
                'canDeleteImea' => $user?->can('imea.delete') ?? false,
                'canImportImea' => $user?->can('imea.import') ?? false,
                'canExportImea' => $user?->can('imea.export') ?? false,

                // PPA (CDS ra)
                'canViewPPA' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('programs-projects-activities.view') ?? false))),
                'canCreatePPA' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('programs-projects-activities.create') ?? false))),
                'canUpdatePPA' => $isAdmin || (!$isMes && ($isStaff || ($user?->can('programs-projects-activities.update') ?? false))),
                'canDeletePPA' => $isAdmin,

                // Reports (CDS ra)
                'canViewReports' => $isAdmin || (!$isMes && ($user?->can('reports.view') ?? false)),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
