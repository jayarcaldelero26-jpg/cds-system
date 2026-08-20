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
use App\Http\Controllers\BmsReportSubmissionController;
use App\Http\Controllers\BamsAssessmentController;
use App\Http\Controllers\ImeaAssessmentController;
use App\Http\Controllers\AwsController;
use App\Http\Controllers\DashboardController;

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

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
    Route::post('/bms/report-submissions', [BmsReportSubmissionController::class, 'store'])->middleware('can:bms.create')->name('bms.report-submissions.store');
    Route::put('/bms/report-submissions/{bmsReportSubmission}', [BmsReportSubmissionController::class, 'update'])->middleware('can:bms.update')->name('bms.report-submissions.update');
    Route::delete('/bms/report-submissions/{bmsReportSubmission}', [BmsReportSubmissionController::class, 'destroy'])->middleware('can:bms.delete')->name('bms.report-submissions.destroy');
    Route::delete('/bms/report-submissions/{bmsReportSubmission}/mov', [BmsReportSubmissionController::class, 'destroyMov'])->middleware('can:bms.update')->name('bms.report-submissions.mov.destroy');

    // BAMS ROUTES
    Route::get('bams', [BamsAssessmentController::class, 'index'])->middleware('can:bams.view')->name('bams.index');
    Route::post('bams/flora', [BamsAssessmentController::class, 'storeFlora'])->middleware('can:bams.create')->name('bams.flora.store');
    Route::post('bams/fauna', [BamsAssessmentController::class, 'storeFauna'])->middleware('can:bams.create')->name('bams.fauna.store');
    Route::post('bams/spatial', [BamsAssessmentController::class, 'storeSpatial'])->middleware('can:bams.manage-spatial')->name('bams.store-spatial');
    Route::post('bams/calculate', [BamsAssessmentController::class, 'calculateIndices'])->middleware('can:bams.calculate')->name('bams.calculate');

    // IMEA ROUTES
    Route::get('imea', [ImeaAssessmentController::class, 'index'])->middleware('can:imea.view')->name('imea.index');
    Route::get('imea/create', [ImeaAssessmentController::class, 'create'])->middleware('can:imea.create')->name('imea.create');
    Route::post('imea', [ImeaAssessmentController::class, 'store'])->middleware('can:imea.create')->name('imea.store');
    Route::put('imea/{imeaAssessment}', [ImeaAssessmentController::class, 'update'])->middleware('can:imea.update')->name('imea.update');
    Route::delete('imea/{imeaAssessment}', [ImeaAssessmentController::class, 'destroy'])->middleware('can:imea.delete')->name('imea.destroy');
    Route::get('/imea/report', [ImeaAssessmentController::class, 'report'])->middleware('can:imea.view')->name('imea.report');
    Route::post('/imea/facilities', [ImeaAssessmentController::class, 'storeFacility'])->middleware('can:imea.create')->name('imea.facilities.store');
    Route::put('/imea/facilities/{id}', [ImeaAssessmentController::class, 'updateFacility'])->middleware('can:imea.update')->name('imea.facilities.update');
    Route::delete('/imea/facilities/{id}', [ImeaAssessmentController::class, 'destroyFacility'])->middleware('can:imea.delete')->name('imea.facilities.destroy');
    Route::get('/imea/facilities-report', [ImeaAssessmentController::class, 'facilitiesReport'])->middleware('can:imea.view')->name('imea.facilities.report');
    Route::get('/imea/facilities-export', [ImeaAssessmentController::class, 'exportFacilitiesExcel'])->middleware('can:imea.export')->name('imea.facilities.export');
    Route::post('/imea/facilities-import', [ImeaAssessmentController::class, 'importFacilitiesExcel'])->middleware('can:imea.import')->name('imea.facilities.import');
    Route::post('/imea/facilities-bulk-delete', [ImeaAssessmentController::class, 'bulkDeleteFacilities'])->middleware('can:imea.delete')->name('imea.facilities.bulk-delete');

    // AUTOMATED WEATHER STATION (AWS) ROUTES
    Route::get('aws', [AwsController::class, 'index'])->middleware('can:aws.view')->name('aws.index');
    Route::post('aws', [AwsController::class, 'store'])->middleware('can:aws.create')->name('aws.store');
    Route::put('aws/{aws}', [AwsController::class, 'update'])->middleware('can:aws.update')->name('aws.update');
    Route::delete('aws/{aws}', [AwsController::class, 'destroy'])->middleware('can:aws.delete')->name('aws.destroy');
    Route::post('aws/bulk-destroy', [AwsController::class, 'bulkDestroy'])->middleware('can:aws.delete')->name('aws.bulk-destroy');
    Route::post('aws/import', [AwsController::class, 'import'])->middleware('can:aws.create')->name('aws.import');
    // Gitangtang na dinhi ang Zentra API route (/aws/zentra-sync)

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
    Route::delete('program-project-activities/{programProjectActivity}', [ProgramProjectActivityController::class, 'destroy'])->middleware('can:programs-projects-activities.delete')->name('program-project-activities.delete');

    Route::get('api/global-search', [GlobalSearchController::class, 'search'])->name('api.global-search');

    // FILE VIEWER
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
