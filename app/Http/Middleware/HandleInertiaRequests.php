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
        $user = Auth::user();

        // Keep legacy privileged accounts usable after approval state was
        // introduced. Ordinary users never receive this compatibility path.
        if ($user && app(OrganizationalAccessService::class)->isGlobal($user) && ! $user->is_approved) {
            $user->forceFill(['is_approved' => true])->saveQuietly();
        }

        if ($user && ! $user->is_approved) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('pending_approval', true);
        }

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('account_inactive', true);
        }

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        // CDS Admin is the only role with a global bypass. All other UI
        // visibility must follow the same named abilities enforced by routes.
        $isAdmin = app(OrganizationalAccessService::class)->isGlobal($user);
        $organization = app(OrganizationalAccessService::class);
        $userUnit = $organization->unitFor($user);
        $allowsConservation = $organization->canViewConservationModules($user);
        $allowsDevelopment = $organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT);
        $canBrowseConservation = $organization->canViewConservationModules($user);
        $canBrowseDevelopment = $organization->canViewDevelopmentModules($user);

        // Susiha ang section sa user ('CDS' o 'MES')
        $userSection = $user?->section ?? '';
        $isMes = ($userSection === 'MES'); // Monitoring and Enforcement Section
        $isCds = ($userSection === 'CDS'); // Conservation Development Section

        $can = static fn (?string $ability): bool => $isAdmin || ($user?->can($ability) ?? false);
        $canPrepare = static fn (string $ability, string $source): bool => $user && in_array($organization->effectiveCategory($user), [OrganizationalAccessService::CENRO_RECORDS, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_FOCAL], true)
            ? $organization->canPrepareProtectedAreaSource($user, $source)
            : $can($ability);
        $canViewManagementPlans = $allowsConservation && ! $isMes && $can('management-plans.view');
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
                    'roles' => collect([$organization->accountRole($user)]),
                    'account_role' => $organization->accountRole($user),
                    'user_category' => $organization->effectiveCategory($user),
                    'is_active' => $user->is_active,
                    'is_approved' => $user->is_approved,
                ] : null,
                'canManageUsers' => $isAdmin,
                'canViewSystemDiagnostics' => $isAdmin || ($user?->can('system-diagnostics.view') ?? false),
                'canManagePasskeys' => $isAdmin,
                'organizationalUnit' => $userUnit,
                'unitVisibility' => [
                    'conservation' => $canBrowseConservation,
                    'development' => $canBrowseDevelopment,
                    'effectiveUnits' => $organization->effectiveUnits($user),
                    'isGlobal' => $isAdmin,
                ],
                'canManageAdministration' => $isAdmin || (!$isMes && $can('compliance-alerts.manage')),
                'canCorrectSubmissionRouting' => $isAdmin && ($user?->can('submission-tracking.correct-routing') ?? false),
                'canAdminRoutingOverride' => $isAdmin && ($user?->can('submission-tracking.admin-override') ?? false),
                'pambScope' => $user ? [
                    'isCenro' => app(PambSubmissionAccessService::class)->isCenro($user),
                    'isGlobal' => app(PambSubmissionAccessService::class)->isGlobal($user),
                ] : ['isCenro' => false, 'isGlobal' => false],

                // 🚀 SECTION-BASED PERMISSIONS FILTERING

                // Protected Areas (CDS ra)
                'canViewProtectedAreas' => $canBrowseConservation && !$isMes && $can('protected-areas.view'),
                'canCreateProtectedAreas' => $allowsConservation && !$isMes && $can('protected-areas.create'),
                'canUpdateProtectedAreas' => $allowsConservation && !$isMes && $can('protected-areas.update'),
                'canDeleteProtectedAreas' => $allowsConservation && $can('protected-areas.delete'),

                // Management Plans (CDS ra)
                'canViewManagementPlans' => $allowsConservation && $canViewManagementPlans,
                'canCreateManagementPlans' => $allowsConservation && !$isMes && $canPrepare('management-plans.create', 'management-plans'),
                'canUpdateManagementPlans' => $allowsConservation && !$isMes && $canPrepare('management-plans.update', 'management-plans'),
                'canDeleteManagementPlans' => $allowsConservation && $can('management-plans.delete'),

                // Technical Reports (CDS ra)
                'canViewTechnicalReports' => ($canBrowseConservation || $canBrowseDevelopment) && $can('technical-reports.view'),
                'canCreateTechnicalReports' => $canPrepare('technical-reports.create', 'technical-reports'),
                'canUpdateTechnicalReports' => $canPrepare('technical-reports.update', 'technical-reports'),
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
                'canCreateAws' => $allowsConservation && $canPrepare('aws.create', 'aws'),
                'canUpdateAws' => $allowsConservation && $canPrepare('aws.update', 'aws'),
                'canDeleteAws' => $allowsConservation && $can('aws.delete'),

                // Biodiversity Monitoring System (BMS)
                'canViewBms' => $allowsConservation && ($user?->can('bms.view') ?? false),
                'canCreateBms' => $allowsConservation && $canPrepare('bms.create', 'bms'),
                'canUpdateBms' => $allowsConservation && $canPrepare('bms.update', 'bms'),
                'canDeleteBms' => $allowsConservation && ($user?->can('bms.delete') ?? false),
                'canExportBms' => $allowsConservation && ($user?->can('bms.view') ?? false) && ($user?->can('reports.export') ?? false),
                'canManageBmsSpatial' => $allowsConservation && ($user?->can('bms.view') ?? false) && ($user?->can('gis.manage') ?? false),

                // Biodiversity Assessment and Monitoring System (BAMS)
                'canViewBams' => $allowsConservation && ($user?->can('bams.view') ?? false),
                'canCreateBams' => $allowsConservation && $canPrepare('bams.create', 'bams'),
                'canUpdateBams' => $allowsConservation && $canPrepare('bams.update', 'bams'),
                'canDeleteBams' => $allowsConservation && ($user?->can('bams.delete') ?? false),
                'canManageBamsSpatial' => $allowsConservation && ($user?->can('bams.manage-spatial') ?? false),
                'canCalculateBams' => $allowsConservation && ($user?->can('bams.calculate') ?? false),

                // Integrated Management Effectiveness Assessment (IMEA)
                'canViewImea' => $allowsConservation && ($user?->can('imea.view') ?? false),
                'canCreateImea' => $allowsConservation && $canPrepare('imea.create', 'imea'),
                'canUpdateImea' => $allowsConservation && $canPrepare('imea.update', 'imea'),
                'canDeleteImea' => $allowsConservation && ($user?->can('imea.delete') ?? false),
                'canImportImea' => $allowsConservation && ($user?->can('imea.import') ?? false),
                'canExportImea' => $allowsConservation && $can('imea.export'),

                // PPA (CDS ra)
                'canViewPPA' => $allowsConservation && !$isMes && $can('programs-projects-activities.view'),
                'canCreatePPA' => $allowsConservation && !$isMes && $can('programs-projects-activities.create'),
                'canUpdatePPA' => $allowsConservation && !$isMes && $can('programs-projects-activities.update'),
                'canDeletePPA' => $allowsConservation && $can('programs-projects-activities.delete'),

                // Reports (CDS ra)
                'canViewReports' => ($canBrowseConservation || $canBrowseDevelopment) && !$isMes && $can('reports.view'),
                'canViewSubmissionTracking' => $organization->canViewSubmissionTracking($user),
                'canBrowseConservationModules' => $canBrowseConservation,
                'canBrowseDevelopmentModules' => $canBrowseDevelopment,
                'canViewComplianceAlerts' => ($canBrowseConservation || $canBrowseDevelopment) && !$isMes && $can('compliance-alerts.manage'),
                'canManageComplianceAlerts' => ($canBrowseConservation || $canBrowseDevelopment) && !$isMes && $can('compliance-alerts.manage'),
            ],
            'managementPlanTypes' => fn () => $canViewManagementPlans
                ? ManagementPlanType::query()
                    ->where('is_active', true)
                    ->orderByRaw('sort_order IS NULL')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug'])
                : [],
            'genericModuleNavigation' => fn () => $canBrowseConservation && ! $isMes && $can('technical-reports.view')
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
                'account_inactive' => fn () => $request->session()->get('account_inactive'),
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
