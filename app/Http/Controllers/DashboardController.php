<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Dashboard\DashboardMonitoringService;
use App\Services\Dashboard\EngpDashboardMonitoringService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardMonitoringService $monitoring,
        private readonly EngpDashboardMonitoringService $engpMonitoring,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->hasRole('no_role') || ! $user->is_active) {
            return Inertia::render('Auth/WaitingApproval');
        }

        if ($request->string('view')->toString() === 'combined') {
            return redirect()->route('dashboard', ['view' => 'pa']);
        }

        $view = in_array($request->string('view')->toString(), ['pa', 'engp'], true)
            ? $request->string('view')->toString()
            : 'pa';
        $organization = app(OrganizationalAccessService::class);
        $protectedAreasCount = match ($organization->unitFor($user)) {
            OrganizationalAccessService::DEVELOPMENT => 0,
            default => $user->section === 'PAMO' && $user->protected_area_id
                ? ProtectedArea::whereKey($user->protected_area_id)->count()
                : $organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')->count(),
        };
        $filters = $request->only(['year', 'program', 'office', 'protected_area_id', 'report_type', 'period', 'frequency', 'search', 'page']);

        if ($view === 'pa') {
            return Inertia::render('Dashboard', [
                ...$this->monitoring->overview([...$filters, 'program' => 'conservation']),
                'view' => $view,
                'protectedAreasCount' => $protectedAreasCount,
            ]);
        }

        return Inertia::render('Dashboard', [
            'view' => $view,
            'engp' => $this->engpMonitoring->overview($filters),
            'protectedAreasCount' => $protectedAreasCount,
        ]);
    }
}
