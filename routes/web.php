<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProtectedAreaController;
use App\Http\Controllers\ManagementPlanController;
use App\Http\Controllers\TechnicalReportController;
use App\Http\Controllers\EcotourismMonitoringController;
use App\Http\Controllers\IssueMonitoringController;
use App\Http\Controllers\LawinMonitoringController;
use App\Http\Controllers\CdsLawinMonitoringController;
use App\Http\Controllers\ProgramProjectActivityController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\BmsController;
use App\Http\Controllers\BamsAssessmentController; // 🚀 Naa na dinhi ang BAMS Controller import

use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use App\Models\ProgramProjectActivity;
use App\Models\IssueMonitoring;
use App\Models\LawinMonitoring;
use App\Models\CdsLawinMonitoring;
use App\Models\TechnicalReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/dashboard', function () {
    $user = request()->user();

    if ($user->hasRole('no_role') || !$user->is_active) {
        return Inertia::render('Auth/WaitingApproval');
    }

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

    } else {
        $protectedAreasCount = ProtectedArea::count();
        $activeManagementPlansCount = ManagementPlan::where('status', 'Active')->count();
        $expiredManagementPlansCount = ManagementPlan::where('status', 'Expired')->count();
        $plansForUpdatingCount = ManagementPlan::where('status', 'For Update')->count();
        $ppaCount = ProgramProjectActivity::count();
        $technicalReportsCount = TechnicalReport::count();
        $cdsLawinCount = CdsLawinMonitoring::count();

        $recentPpas = ProgramProjectActivity::latest()->take(2)->get()->map(function ($item) {
            return [
                'id' => 'ppa-' . $item->id,
                'activity' => "New PPA recorded: " . $item->title,
                'module' => 'PPA Projects',
                'date' => $item->created_at->diffForHumans(),
                'status' => 'Completed',
            ];
        });

        $recentCdsLawins = CdsLawinMonitoring::latest()->take(2)->get()->map(function ($item) {
            return [
                'id' => 'cds-lawin-' . $item->id,
                'activity' => "Patrol conducted at " . ($item->patrol_area ?? 'Protected Area'),
                'module' => 'CDS LAWIN',
                'date' => $item->created_at->diffForHumans(),
                'status' => 'Completed',
            ];
        });

        $dbActivities = collect()
            ->merge($recentPpas)
            ->merge($recentCdsLawins)
            ->take(4)
            ->values()
            ->toArray();

        return Inertia::render('Dashboard', [
            'protectedAreasCount' => $protectedAreasCount,
            'activeManagementPlansCount' => $activeManagementPlansCount,
            'expiredManagementPlansCount' => $expiredManagementPlansCount,
            'plansForUpdatingCount' => $plansForUpdatingCount,
            'ppaCount' => $ppaCount,
            'cdsLawinCount' => $cdsLawinCount,
            'technicalReportsCount' => $technicalReportsCount,
            'dbActivities' => $dbActivities,
        ]);
    }

})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    // PROFILE ROUTES
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // PROTECTED AREAS ROUTES
    Route::get('protected-areas', [ProtectedAreaController::class, 'index'])->middleware('can:protected-areas.view')->name('protected-areas.index');
    Route::get('protected-areas/create', [ProtectedAreaController::class, 'create'])->middleware('can:protected-areas.create')->name('protected-areas.create');
    Route::post('protected-areas', [ProtectedAreaController::class, 'store'])->middleware('can:protected-areas.create')->name('protected-areas.store');
    Route::get('protected-areas/{protectedArea}/edit', [ProtectedAreaController::class, 'edit'])->middleware('can:protected-areas.update')->name('protected-areas.edit');
    Route::patch('protected-areas/{protectedArea}', [ProtectedAreaController::class, 'update'])->middleware('can:protected-areas.update')->name('protected-areas.update');
    Route::delete('protected-areas/{protectedArea}', [ProtectedAreaController::class, 'destroy'])->middleware('can:protected-areas.delete')->name('protected-areas.destroy');

    Route::get('reports', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');

    // ECOTOURISM IMPACT MONITORING ROUTES
    Route::get('ecotourism-monitorings', [EcotourismMonitoringController::class, 'index'])->middleware('can:ecotourism-monitoring.view')->name('ecotourism-monitorings.index');
    Route::get('ecotourism-monitorings/create', [EcotourismMonitoringController::class, 'create'])->middleware('can:ecotourism-monitoring.create')->name('ecotourism-monitorings.create');
    Route::post('ecotourism-monitorings', [EcotourismMonitoringController::class, 'store'])->middleware('can:ecotourism-monitoring.create')->name('ecotourism-monitorings.store');
    Route::get('ecotourism-monitorings/{ecotourismMonitoring}/edit', [EcotourismMonitoringController::class, 'edit'])->middleware('can:ecotourism-monitoring.update')->name('ecotourism-monitorings.edit');
    Route::patch('ecotourism-monitorings/{ecotourismMonitoring}', [EcotourismMonitoringController::class, 'update'])->middleware('can:ecotourism-monitoring.update')->name('ecotourism-monitorings.update');
    Route::delete('ecotourism-monitorings/{ecotourismMonitoring}', [EcotourismMonitoringController::class, 'destroy'])->middleware('can:ecotourism-monitoring.delete')->name('ecotourism-monitorings.destroy');

    // ISSUES MONITORING ROUTES
    Route::get('issue-monitorings', [IssueMonitoringController::class, 'index'])->middleware('can:issue-monitoring.view')->name('issue-monitorings.index');
    Route::get('issue-monitorings/create', [IssueMonitoringController::class, 'create'])->middleware('can:issue-monitoring.create')->name('issue-monitorings.create');
    Route::post('issue-monitorings', [IssueMonitoringController::class, 'store'])->middleware('can:issue-monitoring.create')->name('issue-monitorings.store');
    Route::get('issue-monitorings/{issueMonitoring}/edit', [IssueMonitoringController::class, 'edit'])->middleware('can:issue-monitoring.update')->name('issue-monitorings.edit');
    Route::patch('issue-monitorings/{issueMonitoring}', [IssueMonitoringController::class, 'update'])->middleware('can:issue-monitoring.update')->name('issue-monitorings.update');
    Route::delete('issue-monitorings/{issueMonitoring}', [IssueMonitoringController::class, 'destroy'])->middleware('can:issue-monitoring.delete')->name('issue-monitorings.destroy');

    // MES LAWIN MONITORING ROUTES
    Route::get('lawin-monitorings', [LawinMonitoringController::class, 'index'])->middleware('can:lawin-monitoring.view')->name('lawin-monitorings.index');
    Route::get('lawin-monitorings/create', [LawinMonitoringController::class, 'create'])->middleware('can:lawin-monitoring.create')->name('lawin-monitorings.create');
    Route::post('lawin-monitorings', [LawinMonitoringController::class, 'store'])->middleware('can:lawin-monitoring.create')->name('lawin-monitorings.store');
    Route::get('lawin-monitorings/{lawinMonitoring}/edit', [LawinMonitoringController::class, 'edit'])->middleware('can:lawin-monitoring.update')->name('lawin-monitorings.edit');
    Route::patch('lawin-monitorings/{lawinMonitoring}', [LawinMonitoringController::class, 'update'])->middleware('can:lawin-monitoring.update')->name('lawin-monitorings.update');
    Route::delete('lawin-monitorings/{lawinMonitoring}', [LawinMonitoringController::class, 'destroy'])->middleware('can:lawin-monitoring.delete')->name('lawin-monitorings.destroy');

    // CDS LAWIN ROUTES
    Route::get('cds-lawin', [CdsLawinMonitoringController::class, 'index'])->middleware('can:cds-lawin.view')->name('cds-lawin.index');
    Route::get('cds-lawin/create', [CdsLawinMonitoringController::class, 'create'])->middleware('can:cds-lawin.create')->name('cds-lawin.create');
    Route::post('cds-lawin', [CdsLawinMonitoringController::class, 'store'])->middleware('can:cds-lawin.create')->name('cds-lawin.store');
    Route::get('cds-lawin/{cdsLawinMonitoring}/edit', [CdsLawinMonitoringController::class, 'edit'])->middleware('can:cds-lawin.update')->name('cds-lawin.edit');
    Route::patch('cds-lawin/{cdsLawinMonitoring}', [CdsLawinMonitoringController::class, 'update'])->middleware('can:cds-lawin.update')->name('cds-lawin.update');
    Route::delete('cds-lawin/{cdsLawinMonitoring}', [CdsLawinMonitoringController::class, 'destroy'])->middleware('can:cds-lawin.delete')->name('cds-lawin.destroy');

    // BIODIVERSITY MONITORING SYSTEM (BMS) ROUTES
    Route::get('bms', [BmsController::class, 'index'])->middleware('can:bms.view')->name('bms.index');
    Route::post('bms', [BmsController::class, 'store'])->middleware('can:bms.create')->name('bms.store');
    Route::post('bms/import', [BmsController::class, 'importExcel'])->middleware('can:bms.create')->name('bms.import');
    Route::put('bms/{bmsRecord}', [BmsController::class, 'update'])->middleware('can:bms.update')->name('bms.update');
    Route::delete('bms/{bmsRecord}', [BmsController::class, 'destroy'])->middleware('can:bms.delete')->name('bms.destroy');
    Route::post('/bms/bulk-destroy', [BmsController::class, 'bulkDestroy'])->middleware('can:bms.delete')->name('bms.bulk-destroy');
    Route::post('/bms/bulk-update-header', [BmsController::class, 'bulkUpdateHeader'])->name('bms.bulk-update-header');
    Route::get('/bms/semestral-report', [BmsController::class, 'semestralReport'])->name('bms.semestral-report');
    Route::get('/bms/export-pdf', [BmsController::class, 'exportPdf'])->name('bms.export-pdf');
    Route::post('/bms/import-geojson', [BmsController::class, 'importGeoJson'])->name('bms.import-geojson');

    // 🚀 BIODIVERSITY ASSESSMENT AND MONITORING SYSTEM (BAMS) ROUTES
    Route::get('bams', [BamsAssessmentController::class, 'index'])->name('bams.index');
    Route::post('bams/flora', [BamsAssessmentController::class, 'storeFlora'])->name('bams.flora.store');
    Route::post('bams/fauna', [BamsAssessmentController::class, 'storeFauna'])->name('bams.fauna.store');
    Route::post('bams/spatial', [BamsAssessmentController::class, 'storeSpatial'])->name('bams.store-spatial'); // <-- KINI ANG IDUGANG
    Route::post('bams/calculate', [BamsAssessmentController::class, 'calculateIndices'])->name('bams.calculate');
    // MANAGEMENT PLANS ROUTES
    Route::get('management-plans', [ManagementPlanController::class, 'index'])->middleware('can:management-plans.view')->name('management-plans.index');
    Route::get('management-plans/summary', [ManagementPlanController::class, 'summary'])->middleware('can:management-plans.view')->name('management-plans.summary');
    Route::get('management-plans/create', [ManagementPlanController::class, 'create'])->middleware('can:management-plans.create')->name('management-plans.create');
    Route::post('management-plans', [ManagementPlanController::class, 'store'])->middleware('can:management-plans.create')->name('management-plans.store');
    Route::get('management-plans/{managementPlan}/edit', [ManagementPlanController::class, 'edit'])->middleware('can:management-plans.update')->name('management-plans.edit');
    Route::patch('management-plans/{managementPlan}', [ManagementPlanController::class, 'update'])->middleware('can:management-plans.update')->name('management-plans.update');
    Route::delete('management-plans/{managementPlan}', [ManagementPlanController::class, 'destroy'])->middleware('can:management-plans.delete')->name('management-plans.destroy');

    // TECHNICAL REPORTS ROUTES
    Route::get('technical-reports', [TechnicalReportController::class, 'index'])->middleware('can:technical-reports.view')->name('technical-reports.index');
    Route::get('technical-reports/create', [TechnicalReportController::class, 'create'])->middleware('can:technical-reports.create')->name('technical-reports.create');
    Route::post('technical-reports', [TechnicalReportController::class, 'store'])->middleware('can:technical-reports.create')->name('technical-reports.store');
    Route::get('technical-reports/{technicalReport}/edit', [TechnicalReportController::class, 'edit'])->middleware('can:technical-reports.update')->name('technical-reports.edit');
    Route::patch('technical-reports/{technicalReport}', [TechnicalReportController::class, 'update'])->middleware('can:technical-reports.update')->name('technical-reports.update');
    Route::delete('technical-reports/{technicalReport}', [TechnicalReportController::class, 'destroy'])->middleware('can:technical-reports.delete')->name('technical-reports.destroy');

    // PROGRAMS, PROJECTS & ACTIVITIES (PPA) ROUTES
    Route::get('program-project-activities', [ProgramProjectActivityController::class, 'index'])->middleware('can:programs-projects-activities.view')->name('program-project-activities.index');
    Route::get('program-project-activities/create', [ProgramProjectActivityController::class, 'create'])->middleware('can:programs-projects-activities.create')->name('program-project-activities.create');
    Route::post('program-project-activities', [ProgramProjectActivityController::class, 'store'])->middleware('can:programs-projects-activities.create')->name('program-project-activities.store');
    Route::get('program-project-activities/{programProjectActivity}/edit', [ProgramProjectActivityController::class, 'edit'])->middleware('can:programs-projects-activities.update')->name('program-project-activities.edit');
    Route::post('program-project-activities/{programProjectActivity}', [ProgramProjectActivityController::class, 'update'])->middleware('can:programs-projects-activities.update')->name('program-project-activities.update');
    Route::delete('program-project-activities/{programProjectActivity}', [ProgramProjectActivityController::class, 'destroy'])->middleware('can:programs-projects-activities.delete')->name('program-project-activities.destroy');

    Route::get('api/global-search', [GlobalSearchController::class, 'search'])->name('api.global-search');

    // FILE VIEWER — auth required + path traversal protection
    Route::get('/view-file/{path}', function ($path) {
        $baseDir = realpath(storage_path('app/public'));
        $fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . $path);

        if ($fullPath === false || $baseDir === false || !str_starts_with($fullPath, $baseDir . DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        return response()->file($fullPath);
    })->where('path', '.*')->name('view-file');
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::resource('users', UserController::class)->except('show');
    });

require __DIR__.'/auth.php';
