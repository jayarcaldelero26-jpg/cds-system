<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Dashboard\DashboardMonitoringService;
use App\Services\Dashboard\EngpDashboardMonitoringService;
use Carbon\CarbonImmutable;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardMonitoringService $monitoring, private readonly EngpDashboardMonitoringService $engpMonitoring) {}

    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user->is_approved) {
            return Inertia::render('Auth/WaitingApproval');
        }

        if (! $user->is_active) {
            return redirect()->route('login')->with('account_inactive', true);
        }

        if ($request->string('view')->toString() === 'combined') {
            return redirect()->route('dashboard', ['view' => 'pa']);
        }

        $view = in_array($request->string('view')->toString(), ['all', 'pa', 'engp'], true)
            ? $request->string('view')->toString()
            : 'all';
        $filters = $request->only(['year', 'program', 'office', 'protected_area_id', 'report_type', 'period', 'frequency', 'search', 'page']);
        $tab = in_array($request->string('tab')->toString(), ['conservation', 'development'], true)
            ? $request->string('tab')->toString()
            : ($view === 'engp' ? 'development' : 'conservation');

        // The report-submission overview reuses the normalized, authorization-
        // scoped projection used by Submission Tracking.
        $overview = fn (string $program = 'all'): array => $this->monitoring->submissionOverview([
            ...$filters,
            'program' => $program,
        ]) + ['tab' => $tab];

        if ($view === 'pa') {
            return Inertia::render('Dashboard', [
                'dashboard' => $overview('pa'),
                'view' => $view,
            ]);
        }

        if ($view === 'engp') {
            return Inertia::render('Dashboard', [
                'view' => $view,
                'engp' => $this->engpMonitoring->overview($filters),
                // This view renders only the ENGP schedule projection. Preserve
                // the shared heading context without loading a second set of
                // normalized submission rows that the page does not consume.
                'dashboard' => [
                    'as_of' => CarbonImmutable::now('Asia/Manila')->startOfDay()->toIso8601String(),
                    'tab' => $tab,
                ],
            ]);
        }

        return Inertia::render('Dashboard', [
            'view' => 'all',
            'dashboard' => $overview($tab === 'development' ? 'engp' : 'pa'),
        ]);


    }

}
