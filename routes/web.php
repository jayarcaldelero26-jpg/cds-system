<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProtectedAreaController;
use App\Http\Controllers\ManagementPlanController;
use App\Http\Controllers\ManagementPlanProfileController;
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
use App\Http\Controllers\BmsThreatController;
use App\Http\Controllers\BamsAssessmentController;
use App\Http\Controllers\BamsReportSubmissionController;
use App\Http\Controllers\ImeaAssessmentController;
use App\Http\Controllers\ImeaReportSubmissionController;
use App\Http\Controllers\ImeaFacilityMaintenanceReportController;
use App\Http\Controllers\IpafController;
use App\Http\Controllers\AwsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SpatialLayerController;
use App\Http\Controllers\ComplianceAlertController;

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
    Route::get('compliance-alerts', [ComplianceAlertController::class, 'index'])->middleware('can:reports.view')->name('compliance-alerts.index');
    Route::get('compliance-alerts/preview', [ComplianceAlertController::class, 'preview'])->middleware('can:reports.view')->name('compliance-alerts.preview');
    Route::post('compliance-alerts/send', [ComplianceAlertController::class, 'send'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.send');
    Route::post('compliance-alerts/send-test', [ComplianceAlertController::class, 'sendTest'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.send-test');
    Route::post('compliance-alerts/recipients', [ComplianceAlertController::class, 'storeRecipient'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.recipients.store');
    Route::put('compliance-alerts/recipients/{recipient}', [ComplianceAlertController::class, 'updateRecipient'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.recipients.update');
    Route::patch('compliance-alerts/recipients/{recipient}/status', [ComplianceAlertController::class, 'toggleRecipient'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.recipients.status');
    Route::delete('compliance-alerts/recipients/{recipient}', [ComplianceAlertController::class, 'destroyRecipient'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.recipients.destroy');
    Route::put('compliance-alerts/settings', [ComplianceAlertController::class, 'updateSettings'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.settings.update');
    Route::post('compliance-alerts/non-working-days', [ComplianceAlertController::class, 'storeNonWorkingDay'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.non-working-days.store');
    Route::put('compliance-alerts/non-working-days/{nonWorkingDay}', [ComplianceAlertController::class, 'updateNonWorkingDay'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.non-working-days.update');
    Route::post('compliance-alerts/confirmations', [ComplianceAlertController::class, 'confirm'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.confirm');
    Route::delete('compliance-alerts/confirmations', [ComplianceAlertController::class, 'unconfirm'])->middleware('can:compliance-alerts.manage')->name('compliance-alerts.unconfirm');

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
    Route::get('bms/{bmsRecord}/attachment', [BmsController::class, 'showAttachment'])->middleware('can:bms.view')->name('bms.attachment.show');
    Route::post('/bms/bulk-destroy', [BmsController::class, 'bulkDestroy'])->middleware('can:bms.delete')->name('bms.bulk-destroy');
    Route::post('/bms/bulk-update-header', [BmsController::class, 'bulkUpdateHeader'])->middleware('can:bms.update')->name('bms.bulk-update-header');
    Route::get('/bms/semestral-report', [BmsController::class, 'semestralReport'])->middleware('can:bms.view')->name('bms.semestral-report');
    Route::get('/bms/export-pdf', [BmsController::class, 'exportPdf'])->middleware(['can:bms.view', 'can:reports.export'])->name('bms.export-pdf');
    Route::post('/bms/import-geojson', [BmsController::class, 'importGeoJson'])->middleware(['can:bms.view', 'can:gis.manage'])->name('bms.import-geojson');
    Route::delete('/bms/spatial-layers/{spatialLayer}', [SpatialLayerController::class, 'destroy'])->middleware(['can:bms.view', 'can:gis.manage'])->name('bms.spatial-layers.destroy');
    Route::post('/bms/threats', [BmsThreatController::class, 'store'])->middleware('can:bms.create')->name('bms.threats.store');
    Route::put('/bms/threats/{bmsThreat}', [BmsThreatController::class, 'update'])->middleware('can:bms.update')->name('bms.threats.update');
    Route::delete('/bms/threats/{bmsThreat}', [BmsThreatController::class, 'destroy'])->middleware('can:bms.delete')->name('bms.threats.destroy');
    Route::post('/bms/report-submissions', [BmsReportSubmissionController::class, 'store'])->middleware('can:bms.create')->name('bms.report-submissions.store');
    Route::put('/bms/report-submissions/{bmsReportSubmission}', [BmsReportSubmissionController::class, 'update'])->middleware('can:bms.update')->name('bms.report-submissions.update');
    Route::delete('/bms/report-submissions/{bmsReportSubmission}', [BmsReportSubmissionController::class, 'destroy'])->middleware('can:bms.delete')->name('bms.report-submissions.destroy');
    Route::delete('/bms/report-submissions/{bmsReportSubmission}/mov', [BmsReportSubmissionController::class, 'destroyMov'])->middleware('can:bms.update')->name('bms.report-submissions.mov.destroy');

    // BAMS ROUTES
    Route::get('bams', [BamsAssessmentController::class, 'index'])->middleware('can:bams.view')->name('bams.index');
    Route::post('bams/flora', [BamsAssessmentController::class, 'storeFlora'])->middleware('can:bams.create')->name('bams.flora.store');
    Route::post('bams/fauna', [BamsAssessmentController::class, 'storeFauna'])->middleware('can:bams.create')->name('bams.fauna.store');
    Route::post('bams/spatial', [BamsAssessmentController::class, 'storeSpatial'])->middleware('can:bams.manage-spatial')->name('bams.store-spatial');
    Route::delete('bams/spatial-layers/{spatialLayer}', [SpatialLayerController::class, 'destroy'])->middleware('can:bams.manage-spatial')->name('bams.spatial-layers.destroy');
    Route::post('bams/calculate', [BamsAssessmentController::class, 'calculateIndices'])->middleware('can:bams.calculate')->name('bams.calculate');
    Route::get('bams/report-submissions', [BamsReportSubmissionController::class, 'index'])->middleware('can:bams.view')->name('bams.report-submissions.index');
    Route::post('bams/report-submissions', [BamsReportSubmissionController::class, 'store'])->middleware('can:bams.create')->name('bams.report-submissions.store');
    Route::put('bams/report-submissions/{reportSubmission}', [BamsReportSubmissionController::class, 'update'])->middleware('can:bams.update')->name('bams.report-submissions.update');
    Route::delete('bams/report-submissions/{reportSubmission}', [BamsReportSubmissionController::class, 'destroy'])->middleware('can:bams.delete')->name('bams.report-submissions.destroy');
    Route::get('bams/report-submissions/{reportSubmission}/mov', [BamsReportSubmissionController::class, 'showMov'])->middleware('can:bams.view')->name('bams.report-submissions.mov');

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
    Route::get('imea/report-submissions', [ImeaReportSubmissionController::class, 'index'])->middleware('can:imea.view')->name('imea.report-submissions.index');
    Route::post('imea/report-submissions', [ImeaReportSubmissionController::class, 'store'])->middleware('can:imea.create')->name('imea.report-submissions.store');
    Route::put('imea/report-submissions/{reportSubmission}', [ImeaReportSubmissionController::class, 'update'])->middleware('can:imea.update')->name('imea.report-submissions.update');
    Route::delete('imea/report-submissions/{reportSubmission}', [ImeaReportSubmissionController::class, 'destroy'])->middleware('can:imea.delete')->name('imea.report-submissions.destroy');
    Route::get('imea/report-submissions/{reportSubmission}/mov', [ImeaReportSubmissionController::class, 'showMov'])->middleware('can:imea.view')->name('imea.report-submissions.mov');
    Route::get('imea/maintenance-reports', [ImeaFacilityMaintenanceReportController::class, 'index'])->middleware('can:imea.view')->name('imea.maintenance-reports.index');
    Route::post('imea/maintenance-reports', [ImeaFacilityMaintenanceReportController::class, 'store'])->middleware('can:imea.create')->name('imea.maintenance-reports.store');
    Route::put('imea/maintenance-reports/{maintenanceReport}', [ImeaFacilityMaintenanceReportController::class, 'update'])->middleware('can:imea.update')->name('imea.maintenance-reports.update');
    Route::delete('imea/maintenance-reports/{maintenanceReport}', [ImeaFacilityMaintenanceReportController::class, 'destroy'])->middleware('can:imea.delete')->name('imea.maintenance-reports.destroy');
    Route::get('imea/maintenance-reports/{maintenanceReport}/mov', [ImeaFacilityMaintenanceReportController::class, 'showMov'])->middleware('can:imea.view')->name('imea.maintenance-reports.mov');

    // AUTOMATED WEATHER STATION (AWS) ROUTES
    Route::get('aws', [AwsController::class, 'index'])->middleware('can:aws.view')->name('aws.index');
    Route::post('aws', [AwsController::class, 'store'])->middleware('can:aws.create')->name('aws.store');
    Route::put('aws/{aws}', [AwsController::class, 'update'])->middleware('can:aws.update')->name('aws.update');
    Route::delete('aws/{aws}', [AwsController::class, 'destroy'])->middleware('can:aws.delete')->name('aws.destroy');
    Route::post('aws/bulk-destroy', [AwsController::class, 'bulkDestroy'])->middleware('can:aws.delete')->name('aws.bulk-destroy');
    Route::post('aws/import', [AwsController::class, 'import'])->middleware('can:aws.create')->name('aws.import');
    Route::get('aws/{aws}/report-file', [AwsController::class, 'showReportFile'])->middleware('can:aws.view')->name('aws.report-file.show');
    // Gitangtang na dinhi ang Zentra API route (/aws/zentra-sync)

    // MANAGEMENT PLANS ROUTES
    Route::get('management-plans', [ManagementPlanController::class, 'index'])->middleware('can:management-plans.view')->name('management-plans.index');
    Route::get('management-plans/summary', [ManagementPlanController::class, 'summary'])->middleware('can:management-plans.view')->name('management-plans.summary');
    Route::post('management-plans/types', [ManagementPlanController::class, 'storeType'])->middleware('can:management-plans.create')->name('management-plans.types.store');
    Route::get('management-plans/types/{managementPlanType:slug}', [ManagementPlanController::class, 'tracker'])->middleware('can:management-plans.view')->name('management-plans.types.show');
    Route::post('management-plans/types/{managementPlanType:slug}/plans', [ManagementPlanProfileController::class, 'store'])->middleware('can:management-plans.create')->name('management-plans.types.profiles.store');
    Route::patch('management-plans/types/{managementPlanType:slug}/plans/{profile}', [ManagementPlanProfileController::class, 'update'])->middleware('can:management-plans.update')->name('management-plans.types.profiles.update');
    Route::get('management-plans/types/{managementPlanType:slug}/plans/{profile}/documents/{document}', [ManagementPlanProfileController::class, 'viewDocument'])->middleware('can:management-plans.view')->whereNumber('document')->name('management-plans.types.profiles.documents.view');
    Route::get('management-plans/types/{managementPlanType:slug}/reports/create', [ManagementPlanController::class, 'createReport'])->middleware('can:management-plans.create')->name('management-plans.types.reports.create');
    Route::post('management-plans/types/{managementPlanType:slug}/reports', [ManagementPlanController::class, 'storeReport'])->middleware('can:management-plans.create')->name('management-plans.types.reports.store');
    Route::get('management-plans/types/{managementPlanType:slug}/reports/{managementPlan}/edit', [ManagementPlanController::class, 'editReport'])->middleware('can:management-plans.update')->name('management-plans.types.reports.edit');
    Route::patch('management-plans/types/{managementPlanType:slug}/reports/{managementPlan}', [ManagementPlanController::class, 'updateReport'])->middleware('can:management-plans.update')->name('management-plans.types.reports.update');
    Route::delete('management-plans/types/{managementPlanType:slug}/reports/{managementPlan}', [ManagementPlanController::class, 'destroyReport'])->middleware('can:management-plans.delete')->name('management-plans.types.reports.destroy');
    Route::get('management-plans/types/{managementPlanType:slug}/reports/{managementPlan}/attachments/{attachment}', [ManagementPlanController::class, 'viewScopedAttachment'])->middleware('can:management-plans.view')->whereNumber('attachment')->name('management-plans.types.reports.attachments.view');
    Route::get('management-plans/{managementPlan}/edit', [ManagementPlanController::class, 'legacyEdit'])->middleware('can:management-plans.update')->name('management-plans.edit');
    Route::get('management-plans/{managementPlan}/attachments/{attachment}', [ManagementPlanController::class, 'viewAttachment'])->middleware('can:management-plans.view')->whereNumber('attachment')->name('management-plans.attachments.view');

    // TECHNICAL REPORTS ROUTES
    Route::get('ipaf', [IpafController::class, 'index'])->middleware('can:technical-reports.view')->name('ipaf.index');
    Route::redirect('ipaf-collection', '/ipaf')->middleware('can:technical-reports.view')->name('ipaf-collection.index');
    Route::post('ipaf/revenue-collections', [IpafController::class, 'storeRevenue'])->middleware('can:technical-reports.create')->name('ipaf.revenue.store');
    Route::put('ipaf/revenue-collections/{revenueCollection}', [IpafController::class, 'updateRevenue'])->middleware('can:technical-reports.update')->name('ipaf.revenue.update');
    Route::delete('ipaf/revenue-collections/{revenueCollection}', [IpafController::class, 'destroyRevenue'])->middleware('can:technical-reports.delete')->name('ipaf.revenue.destroy');
    Route::get('ipaf/revenue-collections/{revenueCollection}/mov', [IpafController::class, 'revenueMov'])->middleware('can:technical-reports.view')->name('ipaf.revenue.mov');
    Route::put('ipaf/revenue-targets', [IpafController::class, 'updateRevenueTargets'])->middleware('can:technical-reports.update')->name('ipaf.revenue-targets.update');
    Route::put('ipaf/accounting-status', [IpafController::class, 'updateAccountingStatus'])->middleware('can:technical-reports.update')->name('ipaf.accounting-status.update');
    Route::post('ipaf/accounting/sync-bank-balances', [IpafController::class, 'syncAccountingBankBalances'])->middleware('can:technical-reports.update')->name('ipaf.accounting.bank-balances.sync');
    Route::post('ipaf/management-reports', [IpafController::class, 'storeManagement'])->middleware('can:technical-reports.create')->name('ipaf.management.store');
    Route::put('ipaf/management-reports/{managementReport}', [IpafController::class, 'updateManagement'])->middleware('can:technical-reports.update')->name('ipaf.management.update');
    Route::delete('ipaf/management-reports/{managementReport}', [IpafController::class, 'destroyManagement'])->middleware('can:technical-reports.delete')->name('ipaf.management.destroy');
    Route::get('ipaf/management-reports/{managementReport}/mov', [IpafController::class, 'managementMov'])->middleware('can:technical-reports.view')->name('ipaf.management.mov');
    Route::get('technical-reports', [TechnicalReportController::class, 'index'])->middleware('can:technical-reports.view')->name('technical-reports.index');
    Route::get('technical-reports/create', [TechnicalReportController::class, 'create'])->middleware('can:technical-reports.create')->name('technical-reports.create');
    Route::post('technical-reports', [TechnicalReportController::class, 'store'])->middleware('can:technical-reports.create')->name('technical-reports.store');
    Route::get('technical-reports/{technicalReport}/edit', [TechnicalReportController::class, 'edit'])->middleware('can:technical-reports.update')->name('technical-reports.edit');
    Route::get('technical-reports/{technicalReport}/attachment', [TechnicalReportController::class, 'viewAttachment'])->middleware('can:technical-reports.view')->name('technical-reports.attachment.show');
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
