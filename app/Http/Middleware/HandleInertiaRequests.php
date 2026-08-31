<?php

namespace App\Http\Middleware;

use App\Models\ManagementPlanType;
use App\Models\ModuleDefinition;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
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

        // CDS Admin is the only role with a global bypass. All other UI
        // visibility must follow the same named abilities enforced by routes.
        $isAdmin = $user?->hasRole('CDS Admin') ?? false;

        // Susiha ang section sa user ('CDS' o 'MES')
        $userSection = $user?->section ?? '';
        $isMes = ($userSection === 'MES'); // Monitoring and Enforcement Section
        $isCds = ($userSection === 'CDS'); // Conservation Development Section

        $can = static fn (?string $ability): bool => $isAdmin || ($user?->can($ability) ?? false);
        $canViewManagementPlans = ! $isMes && $can('management-plans.view');
        $engpIacGeneratorUrl = config('services.engp_iac_generator_url');
        $engpIacGeneratorUrl = is_string($engpIacGeneratorUrl) && str_starts_with($engpIacGeneratorUrl, 'https://')
            ? $engpIacGeneratorUrl
            : null;

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
                'canManageAdministration' => $isAdmin || (!$isMes && $can('compliance-alerts.manage')),
                'canCorrectSubmissionRouting' => $isAdmin && ($user?->can('submission-tracking.correct-routing') ?? false),

                // 🚀 SECTION-BASED PERMISSIONS FILTERING

                // Protected Areas (CDS ra)
                'canViewProtectedAreas' => !$isMes && $can('protected-areas.view'),
                'canCreateProtectedAreas' => !$isMes && $can('protected-areas.create'),
                'canUpdateProtectedAreas' => !$isMes && $can('protected-areas.update'),
                'canDeleteProtectedAreas' => $can('protected-areas.delete'),

                // Management Plans (CDS ra)
                'canViewManagementPlans' => $canViewManagementPlans,
                'canCreateManagementPlans' => !$isMes && $can('management-plans.create'),
                'canUpdateManagementPlans' => !$isMes && $can('management-plans.update'),
                'canDeleteManagementPlans' => $can('management-plans.delete'),

                // Technical Reports (CDS ra)
                'canViewTechnicalReports' => !$isMes && $can('technical-reports.view'),
                'canCreateTechnicalReports' => !$isMes && $can('technical-reports.create'),
                'canUpdateTechnicalReports' => !$isMes && $can('technical-reports.update'),
                'canDeleteTechnicalReports' => $can('technical-reports.delete'),

                // Ecotourism Impact Monitoring (CDS ra)
                'canViewEcotourismMonitoring' => !$isMes && $can('ecotourism-monitoring.view'),
                'canCreateEcotourismMonitoring' => !$isMes && $can('ecotourism-monitoring.create'),
                'canUpdateEcotourismMonitoring' => !$isMes && $can('ecotourism-monitoring.update'),
                'canDeleteEcotourismMonitoring' => $can('ecotourism-monitoring.delete'),

                // Issues Monitoring (Pwede sa MES ug CDS)
                'canViewIssueMonitoring' => $can('issue-monitoring.view'),
                'canCreateIssueMonitoring' => $can('issue-monitoring.create'),
                'canUpdateIssueMonitoring' => $can('issue-monitoring.update'),
                'canDeleteIssueMonitoring' => $can('issue-monitoring.delete'),

                // LAWIN Monitoring (GI-BLOCK DIRI PARA SA TECHNICAL STAFF)
                'canViewLawinMonitoring' => $can('lawin-monitoring.view'),
                'canCreateLawinMonitoring' => $can('lawin-monitoring.create'),
                'canUpdateLawinMonitoring' => $can('lawin-monitoring.update'),
                'canDeleteLawinMonitoring' => $can('lawin-monitoring.delete'),

                // Automated Weather Station (AWS)
                'canViewAws' => $can('aws.view'),
                'canCreateAws' => $can('aws.create'),
                'canUpdateAws' => $can('aws.update'),
                'canDeleteAws' => $can('aws.delete'),

                // Biodiversity Monitoring System (BMS)
                'canViewBms' => $user?->can('bms.view') ?? false,
                'canCreateBms' => $user?->can('bms.create') ?? false,
                'canUpdateBms' => $user?->can('bms.update') ?? false,
                'canDeleteBms' => $user?->can('bms.delete') ?? false,
                'canExportBms' => ($user?->can('bms.view') ?? false) && ($user?->can('reports.export') ?? false),
                'canManageBmsSpatial' => ($user?->can('bms.view') ?? false) && ($user?->can('gis.manage') ?? false),

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
                'canExportImea' => $can('imea.export'),

                // PPA (CDS ra)
                'canViewPPA' => !$isMes && $can('programs-projects-activities.view'),
                'canCreatePPA' => !$isMes && $can('programs-projects-activities.create'),
                'canUpdatePPA' => !$isMes && $can('programs-projects-activities.update'),
                'canDeletePPA' => $can('programs-projects-activities.delete'),

                // Reports (CDS ra)
                'canViewReports' => !$isMes && $can('reports.view'),
                'canViewComplianceAlerts' => !$isMes && $can('reports.view'),
                'canManageComplianceAlerts' => !$isMes && $can('compliance-alerts.manage'),
            ],
            'managementPlanTypes' => fn () => $canViewManagementPlans
                ? ManagementPlanType::query()
                    ->where('is_active', true)
                    ->orderByRaw('sort_order IS NULL')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug'])
                : [],
            'genericModuleNavigation' => fn () => ! $isMes && $can('technical-reports.view')
                ? ModuleDefinition::query()->active()->generic()->notRetired()->orderByRaw('display_order IS NULL')->orderBy('display_order')->orderBy('name')
                    ->get(['name', 'code', 'program_area'])
                    ->map(fn (ModuleDefinition $module): array => ['label' => $module->name, 'href' => route('conservation-reports.index', $module->code), 'program_area' => $module->program_area->value])
                    ->values()
                : [],
            'engpIacGeneratorUrl' => $engpIacGeneratorUrl,
            'notificationBell' => fn () => $user && Schema::hasTable('notifications') ? [
                'unread_count' => $user->unreadNotifications()->count(),
                'notifications' => $user->notifications()->latest()->take(8)->get()->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'System notification',
                    'message' => $notification->data['message'] ?? '',
                    'severity' => $notification->data['severity'] ?? 'info',
                    'category' => $notification->data['category'] ?? 'submission_updates',
                    'source_label' => $notification->data['source_label'] ?? 'Report',
                    'url' => $notification->data['url'] ?? null,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ]),
            ] : ['unread_count' => 0, 'notifications' => []],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
