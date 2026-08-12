<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use App\Models\BmsRecord;
use App\Models\BamsFauna;
use App\Models\BamsFlora;
use App\Models\ImeaAssessment;
use App\Models\ProtectedAreaFacility;
use App\Models\ProgramProjectActivity;
use App\Models\IssueMonitoring;
use App\Models\LawinMonitoring;
use App\Models\CdsLawinMonitoring;
use App\Models\TechnicalReport;
use Carbon\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->hasRole('no_role') || !$user->is_active) {
            return Inertia::render('Auth/WaitingApproval');
        }

        // Kung MES user ang naka-login
        if ($user->section === 'MES') {
            $issueCount = IssueMonitoring::count();
            $lawinCount = LawinMonitoring::count();

            $recentLawins = LawinMonitoring::latest()->take(2)->get()->map(function ($item) {
                return [
                    'id' => 'lawin-' . $item->id,
                    'activity' => "Patrol conducted at " . ($item->cenro ?? 'CENRO'),
                    'module' => 'LAWIN (MES)',
                    'date' => $item->created_at->diffForHumans(),
                    'status' => $item->status ?? 'Completed',
                ];
            });

            $recentIssues = IssueMonitoring::latest()->take(2)->get()->map(function ($item) {
                return [
                    'id' => 'issue-' . $item->id,
                    'activity' => "Threat reported: " . $item->threat_type,
                    'module' => 'Issues',
                    'date' => $item->created_at->diffForHumans(),
                    'status' => 'Pending Review',
                ];
            });

            $dbActivities = collect()
                ->merge($recentLawins)
                ->merge($recentIssues)
                ->take(4)
                ->values()
                ->toArray();

            return Inertia::render('MesDashboard', [
                'issueCount' => $issueCount,
                'lawinCount' => $lawinCount,
                'dbActivities' => $dbActivities,
            ]);
        }

        // Kung CDS user ang naka-login
        else {
            $protectedAreasCount = ProtectedArea::count();

            $activeManagementPlansCount = ManagementPlan::where('status', 'Active')->count();
            $expiredManagementPlansCount = ManagementPlan::where('status', 'Expired')->count();
            $plansForUpdatingCount = ManagementPlan::where('status', 'For Update')->count();

            $bmsRecordsCount = BmsRecord::count();
            $bamsRecordsCount = BamsFauna::count() + BamsFlora::count();

            // 🚀 Dynamic Pag-ihap sa mga bag-ong species base sa monitoring dates ug semesters
            $latestRecordDate = BmsRecord::max('monitoring_date');

            $newSpeciesCount = 0;
            if ($latestRecordDate) {
                $latestDate = Carbon::parse($latestRecordDate);
                $year = $latestDate->year;
                $sem = $latestDate->month <= 6 ? 1 : 2;

                $allRecords = BmsRecord::select('species_scientific_name', 'station', 'monitoring_date')->get();

                $speciesHistory = [];
                foreach ($allRecords as $record) {
                    if (!$record->monitoring_date) continue;
                    $d = Carbon::parse($record->monitoring_date);
                    $pYear = $d->year;
                    $pSem = $d->month <= 6 ? 1 : 2;
                    $periodKey = "{$pYear}-Sem {$pSem}";

                    $key = ($record->species_scientific_name ?? 'Unknown') . '___' . ($record->station ?? '-');
                    if (!isset($speciesHistory[$key])) {
                        $speciesHistory[$key] = [];
                    }
                    $speciesHistory[$key][$periodKey] = true;
                }

                $latestOverall = "{$year}-Sem {$sem}";
                foreach ($speciesHistory as $key => $periods) {
                    $semKeys = array_keys($periods);
                    sort($semKeys);
                    $earliest = $semKeys[0];

                    if ($earliest === $latestOverall && count($semKeys) === 1) {
                        $newSpeciesCount++;
                    }
                }
            }

            $bmsThreatsCount = 0;
            $imeaAssessmentsCount = ImeaAssessment::count();

            $totalFacilitiesCount = ProtectedAreaFacility::count();
            $functionalFacilitiesCount = ProtectedAreaFacility::where('status', 'Functional')->count();

            $ppaCount = ProgramProjectActivity::count();
            $technicalReportsCount = TechnicalReport::count();
            $cdsLawinCount = CdsLawinMonitoring::count();

            // 🔄 Dynamic Recent Activities gikan sa BMS, BAMS, IMEA, ug Management Plans
            $recentBms = BmsRecord::latest('updated_at')->take(2)->get()->map(function ($item) {
                return [
                    'id' => 'bms-' . $item->id,
                    'activity' => "BMS record logged: " . ($item->species_scientific_name ?? 'Wildlife/Flora'),
                    'module' => 'BMS',
                    'date' => $item->updated_at ? $item->updated_at->diffForHumans() : now()->diffForHumans(),
                    'status' => 'Completed',
                ];
            });

            $recentBams = BamsFauna::latest('updated_at')->take(2)->get()->map(function ($item) {
                return [
                    'id' => 'bams-' . $item->id,
                    'activity' => "BAMS Fauna assessment recorded",
                    'module' => 'BAMS',
                    'date' => $item->updated_at ? $item->updated_at->diffForHumans() : now()->diffForHumans(),
                    'status' => 'Completed',
                ];
            });

            $recentImea = ImeaAssessment::latest('updated_at')->take(2)->get()->map(function ($item) {
                return [
                    'id' => 'imea-' . $item->id,
                    'activity' => "IMEA Assessment conducted",
                    'module' => 'IMEA',
                    'date' => $item->updated_at ? $item->updated_at->diffForHumans() : now()->diffForHumans(),
                    'status' => 'Completed',
                ];
            });

            $recentMp = ManagementPlan::latest('updated_at')->take(2)->get()->map(function ($item) {
                return [
                    'id' => 'mp-' . $item->id,
                    'activity' => "Management Plan status: " . ($item->status ?? 'Updated'),
                    'module' => 'Management Plans',
                    'date' => $item->updated_at ? $item->updated_at->diffForHumans() : now()->diffForHumans(),
                    'status' => $item->status === 'Active' ? 'Completed' : 'Pending Review',
                ];
            });

            $dbActivities = collect()
                ->merge($recentBms)
                ->merge($recentBams)
                ->merge($recentImea)
                ->merge($recentMp)
                ->take(5)
                ->values()
                ->toArray();

            // Pagkuha sa saktong semestral counts
            $latestRecord = BmsRecord::latest('monitoring_date')->first();
            $latestYear = $latestRecord ? Carbon::parse($latestRecord->monitoring_date)->year : 2025;

            $semester1Count = BmsRecord::whereYear('monitoring_date', $latestYear)
                ->whereMonth('monitoring_date', '>=', 1)
                ->whereMonth('monitoring_date', '<=', 6)
                ->count();

            $semester2Count = BmsRecord::whereYear('monitoring_date', $latestYear)
                ->whereMonth('monitoring_date', '>=', 7)
                ->whereMonth('monitoring_date', '<=', 12)
                ->count();

            if ($semester1Count == 0 && $semester2Count == 0 && $bmsRecordsCount > 0) {
                $semester1Count = BmsRecord::whereMonth('monitoring_date', '>=', 1)->whereMonth('monitoring_date', '<=', 6)->count();
                $semester2Count = BmsRecord::whereMonth('monitoring_date', '>=', 7)->whereMonth('monitoring_date', '<=', 12)->count();
            }

            return Inertia::render('Dashboard', [
                'protectedAreasCount' => $protectedAreasCount,
                'activeManagementPlansCount' => $activeManagementPlansCount,
                'expiredManagementPlansCount' => $expiredManagementPlansCount,
                'plansForUpdatingCount' => $plansForUpdatingCount,
                'bmsRecordsCount' => $bmsRecordsCount,
                'bamsRecordsCount' => $bamsRecordsCount,
                'newSpeciesCount' => $newSpeciesCount, // 🚀 Gidugang nato dinhi ang variable
                'bmsThreatsCount' => $bmsThreatsCount,
                'imeaAssessmentsCount' => $imeaAssessmentsCount,
                'totalFacilitiesCount' => $totalFacilitiesCount,
                'functionalFacilitiesCount' => $functionalFacilitiesCount,
                'ppaCount' => $ppaCount,
                'cdsLawinCount' => $cdsLawinCount,
                'technicalReportsCount' => $technicalReportsCount,
                'dbActivities' => $dbActivities,
                'semester1Count' => $semester1Count,
                'semester2Count' => $semester2Count,
            ]);
        }
    }
}
