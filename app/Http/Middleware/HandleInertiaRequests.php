<?php

namespace App\Http\Middleware;

use App\Models\ManagementPlanType;
use App\Models\ModuleDefinition;
use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\Authorization\OrganizationalAccessService;
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

            return redirect()->route('login')->with('pending_approval', true);
        }

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        // CDS Admin is the only role with a global bypass. All other UI
        // visibility must follow the same named abilities enforced by routes.
        $isAdmin = $user?->hasAnyRole(['CDS Admin', 'Super Admin']) ?? false;
        $organization = app(OrganizationalAccessService::class);
        $userUnit = $organization->unitFor($user);
        $allowsConservation = $organization->canAccessUnit($user, OrganizationalAccessService::CONSERVATION);
        $allowsDevelopment = $organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT);

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
                    'unit_assignment' => $user->unit_assignment,
                    'organizational_unit' => $userUnit,
                    'protected_area_id' => $user->protected_area_id,
                    'roles' => $user->roles->pluck('name')->values(),
                    'is_active' => $user->is_active,
                ] : null,
                'canManageUsers' => $isAdmin,
                'organizationalUnit' => $userUnit,
                'unitVisibility' => [
                    'conservation' => $allowsConservation,
                    'development' => $allowsDevelopment,
                    'isGlobal' => $isAdmin,
                ],
                'canManageAdministration' => $isAdmin || (!$isMes && $can('compliance-alerts.manage')),
                'canCorrectSubmissionRouting' => $isAdmin && ($user?->can('submission-tracking.correct-routing') ?? false),
                'pambScope' => $user ? [
                    'isCenro' => app(PambSubmissionAccessService::class)->isCenro($user),
                    'isGlobal' => app(PambSubmissionAccessService::class)->isGlobal($user),
                ] : ['isCenro' => false, 'isGlobal' => false],

                // 🚀 SECTION-BASED PERMISSIONS FILTERING

                // Protected Areas (CDS ra)
                'canViewProtectedAreas' => $allowsConservation && !$isMes && $can('protected-areas.view'),
                'canCreateProtectedAreas' => $allowsConservation && !$isMes && $can('protected-areas.create'),
                'canUpdateProtectedAreas' => $allowsConservation && !$isMes && $can('protected-areas.update'),
                'canDeleteProtectedAreas' => $allowsConservation && $can('protected-areas.delete'),

                // Management Plans (CDS ra)
                'canViewManagementPlans' => $allowsConservation && $canViewManagementPlans,
                'canCreateManagementPlans' => $allowsConservation && !$isMes && $can('management-plans.create'),
                'canUpdateManagementPlans' => $allowsConservation && !$isMes && $can('management-plans.update'),
                'canDeleteManagementPlans' => $allowsConservation && $can('management-plans.delete'),

                // Technical Reports (CDS ra)
                'canViewTechnicalReports' => $can('technical-reports.view'),
                'canCreateTechnicalReports' => $can('technical-reports.create'),
                'canUpdateTechnicalReports' => $can('technical-reports.update'),
                'canDeleteTechnicalReports' => $can('technical-reports.delete'),

                // Ecotourism Impact Monitoring (CDS ra)
                'canViewEcotourismMonitoring' => $allowsConservation && !$isMes && $can('ecotourism-monitoring.view'),
                'canCreateEcotourismMonitoring' => $allowsConservation && !$isMes && $can('ecotourism-monitoring.create'),
                'canUpdateEcotourismMonitoring' => $allowsConservation && !$isMes && $can('ecotourism-monitoring.update'),
                'canDeleteEcotourismMonitoring' => $allowsConservation && $can('ecotourism-monitoring.delete'),

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
                'canViewAws' => $allowsConservation && $can('aws.view'),
                'canCreateAws' => $allowsConservation && $can('aws.create'),
                'canUpdateAws' => $allowsConservation && $can('aws.update'),
                'canDeleteAws' => $allowsConservation && $can('aws.delete'),

                // Biodiversity Monitoring System (BMS)
                'canViewBms' => $allowsConservation && ($user?->can('bms.view') ?? false),
                'canCreateBms' => $allowsConservation && ($user?->can('bms.create') ?? false),
                'canUpdateBms' => $allowsConservation && ($user?->can('bms.update') ?? false),
                'canDeleteBms' => $allowsConservation && ($user?->can('bms.delete') ?? false),
                'canExportBms' => $allowsConservation && ($user?->can('bms.view') ?? false) && ($user?->can('reports.export') ?? false),
                'canManageBmsSpatial' => $allowsConservation && ($user?->can('bms.view') ?? false) && ($user?->can('gis.manage') ?? false),

                // Biodiversity Assessment and Monitoring System (BAMS)
                'canViewBams' => $allowsConservation && ($user?->can('bams.view') ?? false),
                'canCreateBams' => $allowsConservation && ($user?->can('bams.create') ?? false),
                'canUpdateBams' => $allowsConservation && ($user?->can('bams.update') ?? false),
                'canDeleteBams' => $allowsConservation && ($user?->can('bams.delete') ?? false),
                'canManageBamsSpatial' => $allowsConservation && ($user?->can('bams.manage-spatial') ?? false),
                'canCalculateBams' => $allowsConservation && ($user?->can('bams.calculate') ?? false),

                // Integrated Management Effectiveness Assessment (IMEA)
                'canViewImea' => $allowsConservation && ($user?->can('imea.view') ?? false),
                'canCreateImea' => $allowsConservation && ($user?->can('imea.create') ?? false),
                'canUpdateImea' => $allowsConservation && ($user?->can('imea.update') ?? false),
                'canDeleteImea' => $allowsConservation && ($user?->can('imea.delete') ?? false),
                'canImportImea' => $allowsConservation && ($user?->can('imea.import') ?? false),
                'canExportImea' => $allowsConservation && $can('imea.export'),

                // PPA (CDS ra)
                'canViewPPA' => $allowsConservation && !$isMes && $can('programs-projects-activities.view'),
                'canCreatePPA' => $allowsConservation && !$isMes && $can('programs-projects-activities.create'),
                'canUpdatePPA' => $allowsConservation && !$isMes && $can('programs-projects-activities.update'),
                'canDeletePPA' => $allowsConservation && $can('programs-projects-activities.delete'),

                // Reports (CDS ra)
                'canViewReports' => !$isMes && $can('reports.view'),
                'canViewComplianceAlerts' => !$isMes && $can('compliance-alerts.manage'),
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
            'genericModuleNavigation' => fn () => $allowsConservation && ! $isMes && $can('technical-reports.view')
                ? ModuleDefinition::query()->active()->generic()->notRetired()->orderByRaw('display_order IS NULL')->orderBy('display_order')->orderBy('name')
                    ->get(['name', 'code', 'program_area'])
                    ->map(fn (ModuleDefinition $module): array => ['label' => $module->name, 'href' => route('conservation-reports.index', $module->code), 'program_area' => $module->program_area->value])
                    ->values()
                : [],
            'engpIacGeneratorUrl' => $engpIacGeneratorUrl,
            'notificationBell' => fn () => $user && Schema::hasTable('notifications') ? [
                'unread_count' => $user->unreadNotifications()->latest()->get()->filter(fn ($notification): bool => EdatsInAppNotificationService::isBellAlert($notification->data))->take(8)->count(),
                'notifications' => $user->unreadNotifications()->latest()->get()->filter(fn ($notification): bool => EdatsInAppNotificationService::isBellAlert($notification->data))->take(8)->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'System notification',
                    'message' => $notification->data['message'] ?? '',
                    'severity' => $notification->data['severity'] ?? 'info',
                    'category' => $notification->data['category'] ?? 'submission_updates',
                    'source_label' => $notification->data['source_label'] ?? 'Report',
                    'office' => $notification->data['office'] ?? null,
                    'protected_area' => $notification->data['protected_area'] ?? null,
                    'url' => $notification->data['url'] ?? null,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ]),
            ] : ['unread_count' => 0, 'notifications' => []],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'registration_success' => fn () => $request->session()->get('registration_success'),
                'pending_approval' => fn () => $request->session()->get('pending_approval'),
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
