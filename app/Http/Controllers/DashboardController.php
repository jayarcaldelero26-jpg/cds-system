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
use App\Models\Aws;
use App\Services\Dashboard\DashboardMonitoringService;
use Carbon\Carbon;
use Inertia\Inertia;
use App\Services\Authorization\OrganizationalAccessService;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardMonitoringService $monitoring) {}

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->hasRole('no_role') || !$user->is_active) {
            return Inertia::render('Auth/WaitingApproval');
        }

        if ($user->section !== 'MES') {
            $dashboard = $this->monitoring->overview($request->only([
                'year', 'program', 'office', 'period', 'page',
            ]));

            // Retained for existing authorized-navigation consumers; the new
            // monitoring dashboard itself uses the normalized live report rows.
            $organization = app(OrganizationalAccessService::class);
            $dashboard['protectedAreasCount'] = match ($organization->unitFor($user)) {
                OrganizationalAccessService::DEVELOPMENT => 0,
                default => $user->section === 'PAMO' && $user->protected_area_id
                    ? ProtectedArea::whereKey($user->protected_area_id)->count()
                    : ProtectedArea::count(),
            };

            return Inertia::render('Dashboard', $dashboard);
        }

        // ============================================================
        // MES DASHBOARD
        // ============================================================

        if ($user->section === 'MES') {

            $issueCount = IssueMonitoring::count();

            $lawinCount = LawinMonitoring::count();

            $recentLawins = LawinMonitoring::latest()
                ->take(2)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => 'lawin-' . $item->id,

                        'activity' =>
                            'Patrol conducted at ' .
                            ($item->cenro ?? 'CENRO'),

                        'module' => 'LAWIN (MES)',

                        'date' => $item->created_at
                            ? $item->created_at->diffForHumans()
                            : now()->diffForHumans(),

                        'status' => $item->status ?? 'Completed',
                    ];
                });

            $recentIssues = IssueMonitoring::latest()
                ->take(2)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => 'issue-' . $item->id,

                        'activity' =>
                            'Threat reported: ' .
                            ($item->threat_type ?? 'Unknown'),

                        'module' => 'Issues',

                        'date' => $item->created_at
                            ? $item->created_at->diffForHumans()
                            : now()->diffForHumans(),

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

        // ============================================================
        // CDS DASHBOARD
        // ============================================================

        // ------------------------------------------------------------
        // PROTECTED AREAS
        // ------------------------------------------------------------

        $protectedAreasCount = ProtectedArea::count();

        // ------------------------------------------------------------
        // MANAGEMENT PLANS
        // ------------------------------------------------------------

        $activeManagementPlansCount =
            ManagementPlan::where('status', 'Active')->count();

        $expiredManagementPlansCount =
            ManagementPlan::where('status', 'Expired')->count();

        $plansForUpdatingCount =
            ManagementPlan::where('status', 'For Update')->count();

        // ------------------------------------------------------------
        // BMS
        // ------------------------------------------------------------

        $bmsRecordsCount = BmsRecord::count();

        // ------------------------------------------------------------
        // BAMS
        // ------------------------------------------------------------

        $bamsRecordsCount =
            BamsFauna::count() +
            BamsFlora::count();

        // ============================================================
        // BMS NEW SPECIES
        // ============================================================

        $latestRecordDate = BmsRecord::max('monitoring_date');

        $newSpeciesCount = 0;

        if ($latestRecordDate) {

            $latestDate = Carbon::parse($latestRecordDate);

            $year = $latestDate->year;

            $sem = $latestDate->month <= 6
                ? 1
                : 2;

            $allRecords = BmsRecord::select(
                'species_scientific_name',
                'station',
                'monitoring_date'
            )->get();

            $speciesHistory = [];

            foreach ($allRecords as $record) {

                if (!$record->monitoring_date) {
                    continue;
                }

                $d = Carbon::parse(
                    $record->monitoring_date
                );

                $pYear = $d->year;

                $pSem = $d->month <= 6
                    ? 1
                    : 2;

                $periodKey =
                    "{$pYear}-Sem {$pSem}";

                $key =
                    ($record->species_scientific_name ?? 'Unknown') .
                    '___' .
                    ($record->station ?? '-');

                if (!isset($speciesHistory[$key])) {
                    $speciesHistory[$key] = [];
                }

                $speciesHistory[$key][$periodKey] = true;
            }

            $latestOverall =
                "{$year}-Sem {$sem}";

            foreach ($speciesHistory as $periods) {

                $semKeys = array_keys($periods);

                sort($semKeys);

                $earliest = $semKeys[0];

                if (
                    $earliest === $latestOverall &&
                    count($semKeys) === 1
                ) {
                    $newSpeciesCount++;
                }
            }
        }

        // ------------------------------------------------------------
        // BMS THREATS
        // ------------------------------------------------------------

        $bmsThreatsCount = 0;

        // ------------------------------------------------------------
        // IMEA
        // ------------------------------------------------------------

        $imeaAssessmentsCount =
            ImeaAssessment::count();

        // ------------------------------------------------------------
        // FACILITIES
        // ------------------------------------------------------------

        $totalFacilitiesCount =
            ProtectedAreaFacility::count();

        $functionalFacilitiesCount =
            ProtectedAreaFacility::where(
                'status',
                'Functional'
            )->count();

        // ------------------------------------------------------------
        // OTHER MODULES
        // ------------------------------------------------------------

        $ppaCount =
            ProgramProjectActivity::count();

        $cdsLawinCount =
            CdsLawinMonitoring::count();

        // ============================================================
        // AWS
        // ============================================================

        /*
        |--------------------------------------------------------------------------
        | AWS SUBMITTED REPORTS
        |--------------------------------------------------------------------------
        |
        | Manual / official submitted reports:
        |
        | timestamps = NULL
        |
        | These are the ONLY records counted in the AWS statistics card.
        |
        */

        $awsCount = Aws::whereNull('timestamps')->count();

        /*
        |--------------------------------------------------------------------------
        | AWS RAW / IMPORTED WEATHER DATA
        |--------------------------------------------------------------------------
        |
        | Imported CSV weather data:
        |
        | timestamps IS NOT NULL
        |
        | These records are used for the dashboard graph.
        |
        */

        $awsRawQuery = Aws::whereNotNull('timestamps')
            ->whereNotNull('start_date');

        $awsLatestDate =
            (clone $awsRawQuery)->max('start_date');

        $awsChartData = collect();

        if ($awsLatestDate) {

            $awsGraphEndDate =
                Carbon::parse($awsLatestDate)
                    ->startOfDay();

            $awsGraphStartDate =
                $awsGraphEndDate
                    ->copy()
                    ->subDays(29);

            $awsRawData = $awsRawQuery
                ->whereBetween('start_date', [
                    $awsGraphStartDate->toDateString(),
                    $awsGraphEndDate->toDateString(),
                ])
                ->orderBy('start_date')
                ->get([
                    'start_date',
                    'precipitation',
                    'air_temperature',
                    'relative_humidity',
                    'atmospheric_pressure',
                    'wind_speed',
                    'wind_direction',
                    'protected_area_id',
                ]);

            /*
            |--------------------------------------------------------------------------
            | GROUP WEATHER DATA BY DATE
            |--------------------------------------------------------------------------
            */

            $groupedAws = $awsRawData->groupBy(
                function ($item) {
                    return Carbon::parse(
                        $item->start_date
                    )->format('Y-m-d');
                }
            );

            $awsChartData = $groupedAws
                ->map(function ($records, $date) {

                    /*
                    |--------------------------------------------------------------------------
                    | Average numeric value helper
                    |--------------------------------------------------------------------------
                    */

                    $numericAverage =
                        function ($collection, $field) {

                            $values = $collection
                                ->pluck($field)
                                ->filter(function ($value) {
                                    return $value !== null &&
                                        $value !== '' &&
                                        is_numeric($value);
                                })
                                ->map(function ($value) {
                                    return (float) $value;
                                });

                            if ($values->isEmpty()) {
                                return null;
                            }

                            return round(
                                $values->avg(),
                                2
                            );
                        };

                    /*
                    |--------------------------------------------------------------------------
                    | Rainfall
                    |--------------------------------------------------------------------------
                    |
                    | Precipitation is summed because rainfall is
                    | cumulative over the daily observations.
                    |
                    */

                    $rainfallValues = $records
                        ->pluck('precipitation')
                        ->filter(function ($value) {
                            return $value !== null &&
                                $value !== '' &&
                                is_numeric($value);
                        })
                        ->map(function ($value) {
                            return (float) $value;
                        });

                    /*
                    |--------------------------------------------------------------------------
                    | Wind Direction
                    |--------------------------------------------------------------------------
                    */

                    $windDirection = $records
                        ->pluck('wind_direction')
                        ->filter(function ($value) {
                            return $value !== null &&
                                trim((string) $value) !== '';
                        })
                        ->first();

                    return [

                        'date' =>
                            Carbon::parse($date)
                                ->format('M d'),

                        'full_date' =>
                            Carbon::parse($date)
                                ->format('M d, Y'),

                        'precipitation' =>
                            $rainfallValues->isNotEmpty()
                                ? round(
                                    $rainfallValues->sum(),
                                    2
                                )
                                : null,

                        'air_temperature' =>
                            $numericAverage(
                                $records,
                                'air_temperature'
                            ),

                        'relative_humidity' =>
                            $numericAverage(
                                $records,
                                'relative_humidity'
                            ),

                        'atmospheric_pressure' =>
                            $numericAverage(
                                $records,
                                'atmospheric_pressure'
                            ),

                        'wind_speed' =>
                            $numericAverage(
                                $records,
                                'wind_speed'
                            ),

                        'wind_direction' =>
                            $windDirection,

                        'record_count' =>
                            $records->count(),
                    ];
                })
                ->sortKeys()
                ->values()
                ->toArray();
        }

        // ============================================================
        // RECENT ACTIVITIES
        // ============================================================

        /*
        |--------------------------------------------------------------------------
        | BMS RECENT ACTIVITIES
        |--------------------------------------------------------------------------
        */

        $recentBms = BmsRecord::latest('updated_at')
            ->take(2)
            ->get()
            ->map(function ($item) {

                return [
                    'id' =>
                        'bms-' . $item->id,

                    'activity' =>
                        'BMS record logged: ' .
                        (
                            $item->species_scientific_name
                            ?? 'Wildlife/Flora'
                        ),

                    'module' =>
                        'BMS',

                    'date' =>
                        $item->updated_at
                            ? $item->updated_at->diffForHumans()
                            : now()->diffForHumans(),

                    'status' =>
                        'Completed',

                    /*
                    |--------------------------------------------------------------------------
                    | Internal sorting timestamp
                    |--------------------------------------------------------------------------
                    */

                    'activity_at' =>
                        $item->updated_at
                        ?? $item->created_at
                        ?? now(),
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | BAMS RECENT ACTIVITIES
        |--------------------------------------------------------------------------
        */

        $recentBams = BamsFauna::latest('updated_at')
            ->take(2)
            ->get()
            ->map(function ($item) {

                return [
                    'id' =>
                        'bams-' . $item->id,

                    'activity' =>
                        'BAMS Fauna assessment recorded',

                    'module' =>
                        'BAMS',

                    'date' =>
                        $item->updated_at
                            ? $item->updated_at->diffForHumans()
                            : now()->diffForHumans(),

                    'status' =>
                        'Completed',

                    /*
                    |--------------------------------------------------------------------------
                    | Internal sorting timestamp
                    |--------------------------------------------------------------------------
                    */

                    'activity_at' =>
                        $item->updated_at
                        ?? $item->created_at
                        ?? now(),
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | IMEA RECENT ACTIVITIES
        |--------------------------------------------------------------------------
        */

        $recentImea = ImeaAssessment::latest('updated_at')
            ->take(2)
            ->get()
            ->map(function ($item) {

                return [
                    'id' =>
                        'imea-' . $item->id,

                    'activity' =>
                        'IMEA Assessment conducted',

                    'module' =>
                        'IMEA',

                    'date' =>
                        $item->updated_at
                            ? $item->updated_at->diffForHumans()
                            : now()->diffForHumans(),

                    'status' =>
                        'Completed',

                    /*
                    |--------------------------------------------------------------------------
                    | Internal sorting timestamp
                    |--------------------------------------------------------------------------
                    */

                    'activity_at' =>
                        $item->updated_at
                        ?? $item->created_at
                        ?? now(),
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | MANAGEMENT PLAN RECENT ACTIVITIES
        |--------------------------------------------------------------------------
        */

        $recentMp = ManagementPlan::latest('updated_at')
            ->take(2)
            ->get()
            ->map(function ($item) {

                return [
                    'id' =>
                        'mp-' . $item->id,

                    'activity' =>
                        'Management Plan status: ' .
                        (
                            $item->status
                            ?? 'Updated'
                        ),

                    'module' =>
                        'Management Plans',

                    'date' =>
                        $item->updated_at
                            ? $item->updated_at->diffForHumans()
                            : now()->diffForHumans(),

                    'status' =>
                        $item->status === 'Active'
                            ? 'Completed'
                            : 'Pending Review',

                    /*
                    |--------------------------------------------------------------------------
                    | Internal sorting timestamp
                    |--------------------------------------------------------------------------
                    */

                    'activity_at' =>
                        $item->updated_at
                        ?? $item->created_at
                        ?? now(),
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | AWS RECENT ACTIVITIES
        |--------------------------------------------------------------------------
        |
        | ONLY submitted AWS reports.
        |
        | Raw imported weather records are excluded.
        |
        */

        $recentAws = Aws::whereNull('timestamps')
            ->latest('updated_at')
            ->take(2)
            ->get()
            ->map(function ($item) {

                return [
                    'id' =>
                        'aws-' . $item->id,

                    'activity' =>
                        'AWS monitoring report submitted: ' .
                        (
                            $item->station_name
                            ?? 'Weather Station'
                        ),

                    'module' =>
                        'AWS',

                    'date' =>
                        $item->updated_at
                            ? $item->updated_at->diffForHumans()
                            : now()->diffForHumans(),

                    'status' =>
                        $item->status
                        ?? 'Submitted',

                    /*
                    |--------------------------------------------------------------------------
                    | Internal sorting timestamp
                    |--------------------------------------------------------------------------
                    */

                    'activity_at' =>
                        $item->updated_at
                        ?? $item->created_at
                        ?? now(),
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | MERGE ALL ACTIVITIES
        |--------------------------------------------------------------------------
        |
        | Each module contributes its latest records.
        |
        */

        $dbActivities = collect()
            ->merge($recentBms)
            ->merge($recentBams)
            ->merge($recentImea)
            ->merge($recentMp)
            ->merge($recentAws)

            /*
            |--------------------------------------------------------------------------
            | SORT ALL MODULES BY ACTUAL TIMESTAMP
            |--------------------------------------------------------------------------
            */

            ->sortByDesc(function ($item) {
                return $item['activity_at'];
            })

            /*
            |--------------------------------------------------------------------------
            | SHOW ONLY THE 5 MOST RECENT ACTIVITIES
            |--------------------------------------------------------------------------
            */

            ->take(5)

            ->values()

            /*
            |--------------------------------------------------------------------------
            | Remove internal timestamp before sending to React.
            |--------------------------------------------------------------------------
            */

            ->map(function ($item) {

                unset($item['activity_at']);

                return $item;
            })

            ->toArray();

        // ============================================================
        // BMS SEMESTER COUNTS
        // ============================================================

        $latestRecord =
            BmsRecord::latest('monitoring_date')->first();

        $latestYear =
            $latestRecord
                ? Carbon::parse(
                    $latestRecord->monitoring_date
                )->year
                : 2025;

        /*
        |--------------------------------------------------------------------------
        | SEMESTER 1
        |--------------------------------------------------------------------------
        */

        $semester1Count =
            BmsRecord::whereYear(
                'monitoring_date',
                $latestYear
            )
            ->whereMonth(
                'monitoring_date',
                '>=',
                1
            )
            ->whereMonth(
                'monitoring_date',
                '<=',
                6
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | SEMESTER 2
        |--------------------------------------------------------------------------
        */

        $semester2Count =
            BmsRecord::whereYear(
                'monitoring_date',
                $latestYear
            )
            ->whereMonth(
                'monitoring_date',
                '>=',
                7
            )
            ->whereMonth(
                'monitoring_date',
                '<=',
                12
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | FALLBACK BMS SEMESTER COUNTS
        |--------------------------------------------------------------------------
        */

        if (
            $semester1Count == 0 &&
            $semester2Count == 0 &&
            $bmsRecordsCount > 0
        ) {

            $semester1Count =
                BmsRecord::whereMonth(
                    'monitoring_date',
                    '>=',
                    1
                )
                ->whereMonth(
                    'monitoring_date',
                    '<=',
                    6
                )
                ->count();

            $semester2Count =
                BmsRecord::whereMonth(
                    'monitoring_date',
                    '>=',
                    7
                )
                ->whereMonth(
                    'monitoring_date',
                    '<=',
                    12
                )
                ->count();
        }

        // ============================================================
        // SEND DATA TO DASHBOARD
        // ============================================================

        return Inertia::render('Dashboard', [

            // --------------------------------------------------------
            // PROTECTED AREAS
            // --------------------------------------------------------

            'protectedAreasCount' =>
                $protectedAreasCount,

            // --------------------------------------------------------
            // MANAGEMENT PLANS
            // --------------------------------------------------------

            'activeManagementPlansCount' =>
                $activeManagementPlansCount,

            'expiredManagementPlansCount' =>
                $expiredManagementPlansCount,

            'plansForUpdatingCount' =>
                $plansForUpdatingCount,

            // --------------------------------------------------------
            // BMS / BAMS
            // --------------------------------------------------------

            'bmsRecordsCount' =>
                $bmsRecordsCount,

            'bamsRecordsCount' =>
                $bamsRecordsCount,

            'newSpeciesCount' =>
                $newSpeciesCount,

            'bmsThreatsCount' =>
                $bmsThreatsCount,

            // --------------------------------------------------------
            // IMEA
            // --------------------------------------------------------

            'imeaAssessmentsCount' =>
                $imeaAssessmentsCount,

            // --------------------------------------------------------
            // FACILITIES
            // --------------------------------------------------------

            'totalFacilitiesCount' =>
                $totalFacilitiesCount,

            'functionalFacilitiesCount' =>
                $functionalFacilitiesCount,

            // --------------------------------------------------------
            // AWS
            // --------------------------------------------------------

            /*
            |--------------------------------------------------------------------------
            | AWS CARD
            |--------------------------------------------------------------------------
            |
            | Submitted reports only.
            |
            */

            'awsCount' =>
                $awsCount,

            /*
            |--------------------------------------------------------------------------
            | AWS GRAPH
            |--------------------------------------------------------------------------
            |
            | Imported/raw weather data.
            |
            */

            'awsChartData' =>
                $awsChartData,

            // --------------------------------------------------------
            // OTHER MODULES
            // --------------------------------------------------------

            'ppaCount' =>
                $ppaCount,

            'cdsLawinCount' =>
                $cdsLawinCount,

            // --------------------------------------------------------
            // RECENT ACTIVITIES
            // --------------------------------------------------------

            'dbActivities' =>
                $dbActivities,

            // --------------------------------------------------------
            // BMS SEMESTER DATA
            // --------------------------------------------------------

            'semester1Count' =>
                $semester1Count,

            'semester2Count' =>
                $semester2Count,
        ]);
    }
}
