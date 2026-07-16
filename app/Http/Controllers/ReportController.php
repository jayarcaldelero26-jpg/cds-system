<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use App\Models\TechnicalReport;
use App\Models\EcotourismMonitoring;
use App\Models\IssueMonitoring;
use App\Models\LawinMonitoring;
use App\Models\ProgramProjectActivity;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index()
    {
        // Pagkuha sa kinatibuk-ang ihap gikan sa matag table
        $paCount = ProtectedArea::count();
        $mpCount = ManagementPlan::count();
        $trCount = TechnicalReport::count();
        $ecoCount = EcotourismMonitoring::count();
        $issueCount = IssueMonitoring::count();
        $lawinCount = LawinMonitoring::count();
        $ppaCount = ProgramProjectActivity::count();

        // Sumada sa PPA Budgets
        $totalPpaBudget = ProgramProjectActivity::sum('budget') ?? 0;
        $ppaByCategory = ProgramProjectActivity::selectRaw('category, count(*) as count, sum(budget) as total_budget')
            ->groupBy('category')
            ->get();

        // Sumada sa Issues base sa Status
        $issuesByStatus = IssueMonitoring::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->get();

        // Sumada sa distansya sa Lawin Patrols
        $totalPatrolDistance = LawinMonitoring::sum('patrol_distance') ?? 0;

        return Inertia::render('Reports/Index', [
            'stats' => [
                'protected_areas_count' => $paCount,
                'management_plans_count' => $mpCount,
                'technical_reports_count' => $trCount,
                'ecotourism_count' => $ecoCount,
                'issues_count' => $issueCount,
                'lawin_count' => $lawinCount,
                'ppa_count' => $ppaCount,
                'total_ppa_budget' => $totalPpaBudget,
                'ppa_by_category' => $ppaByCategory,
                'issues_by_status' => $issuesByStatus,
                'total_patrol_distance' => $totalPatrolDistance,
            ]
        ]);
    }
}
