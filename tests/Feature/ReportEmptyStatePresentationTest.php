<?php

use Illuminate\Support\Facades\File;

test('CrudTable keeps its existing default and supports an opt-in compact report empty state', function (): void {
    $table = File::get(resource_path('js/Components/Crud/CrudTable.jsx'));

    expect($table)->toContain('compactEmpty = false')
        ->and($table)->toContain('compactEmpty ? <CompactReportEmptyState')
        ->and($table)->toContain('const showHeader = !compactEmpty || !empty || preserveFrameWhenEmpty;')
        ->and($table)->toContain('const showPagination = pagination && (!compactEmpty || !empty || preserveFrameWhenEmpty);')
        ->and($table)->toContain('No records found');
});

test('the shared Conservation, BMS, BAMS, and IMEA report path opts into compact empty mode', function (): void {
    $shared = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));
    $standard = File::get(resource_path('js/Components/StandardAReportSubmissionTracker.jsx'));
    $bams = File::get(resource_path('js/Pages/Bams/ReportSubmissions.jsx'));
    $imea = File::get(resource_path('js/Pages/Imea/ReportSubmissions.jsx'));

    expect($shared)->toContain('compactEmpty={true}')
        ->and($shared)->toContain('title={rows.length > 0 ? `${moduleLabel} Report` : undefined}')
        ->and($shared)->toContain("helperText={rows.length > 0 ? 'Click any row to view full details' : undefined}")
        ->and($standard)->toContain('ReportSubmissionTracker')
        ->and($bams)->toContain('StandardAReportSubmissionTracker')
        ->and($imea)->toContain('ReportSubmissionTracker');
});

test('specialized report trackers opt in without changing raw and monitoring tables', function (): void {
    $specialized = [
        resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'),
        resource_path('js/Pages/Imea/MaintenanceReports.jsx'),
        resource_path('js/Pages/Ipaf/Index.jsx'),
        resource_path('js/Pages/ManagementPlans/Index.jsx'),
    ];

    expect(File::get($specialized[0]))->toContain('ReportSubmissionTracker');
    foreach (array_slice($specialized, 1) as $file) {
        expect(File::get($file))->toContain('compactEmpty={true}');
    }

    expect(File::get(resource_path('js/Pages/Bms/Index.jsx')))->not->toContain('compactEmpty={true}')
        ->and(File::get(resource_path('js/Pages/AWS/AwsTable.jsx')))->not->toContain('compactEmpty={true}')
        ->and(File::get(resource_path('js/Pages/Ipaf/AccountingSection.jsx')))->not->toContain('compactEmpty={true}');
});

test('compact report empty mode suppresses helper, headers, and pagination only for empty results', function (): void {
    $table = File::get(resource_path('js/Components/Crud/CrudTable.jsx'));
    $shared = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));

    expect($table)->toContain('{showHeader &&')
        ->and($table)->toContain('{showPagination &&')
        ->and($table)->toContain('safeRows.map(row =>')
        ->and($shared)->toContain('pagination={rows.length > 0 ? pagination : null}')
        ->and($shared)->not->toContain('loading={');
});

test('normal Inertia navigation uses only the built-in top progress indicator', function (): void {
    $app = File::get(resource_path('js/app.jsx'));
    $layout = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($app)->not->toContain('GlobalLoadingOverlay')
        ->and($app)->toContain("progress: { color: '#16a34a', delay: 150, showSpinner: false }")
        ->and($app)->toContain('<App {...props} />')
        ->and($layout)->toContain('export function AuthenticatedShell')
        ->and(File::exists(resource_path('js/Components/GlobalLoadingOverlay.jsx')))->toBeFalse();
});

test('IMEA Report omits the local tab shell while IMEA Data retains shared workflow navigation', function (): void {
    $report = File::get(resource_path('js/Pages/Imea/ReportSubmissions.jsx'));
    $data = File::get(resource_path('js/Pages/Imea/Index.jsx'));

    expect($report)->not->toContain('WorkflowTabs')
        ->and($data)->toContain("import WorkflowTabs from './WorkflowTabs';")
        ->and($data)->toContain('<WorkflowTabs active="facilities" />')
        ->and($report)->toContain('PageHeader')
        ->and($data)->toContain('Facilities & Infrastructures inventory');
});

test('legitimate multi-view navigation remains in BMS, BAMS, and IPAF while AWS keeps summary and analytics separate', function (): void {
    $aws = File::get(resource_path('js/Pages/AWS/Aws.jsx'));
    $navigation = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
    $summary = File::get(resource_path('js/Pages/AWS/AwsMonthlySummary.jsx'));
    $pdf = File::get(resource_path('views/aws/monthly-summary-pdf.blade.php'));
    $xlsx = File::get(app_path('Services/AwsMonthlySummaryXlsxService.php'));
    $docx = File::get(app_path('Services/AwsSummaryDocxService.php'));

    expect(File::get(resource_path('js/Pages/Bms/Index.jsx')))->toContain("setActiveTab('list')")
        ->and(File::get(resource_path('js/Pages/Bams/Index.jsx')))->toContain("setActiveTab('map')")
        ->and($aws)->not->toContain('Daily AWS Data')
        ->and($aws)->toContain('Monitoring Summary')
        ->and($aws)->toContain('Weather Analytics &amp; Graph')
        ->and($aws)->toContain('AWS Observation Records')
        ->and($aws)->toContain('Import AWS Data')
        ->and($summary)->toContain('canImport')
        ->and($summary)->toContain('onImport')
        ->and($aws)->toContain('<AwsGraph')
        ->and($aws)->toContain("urlTab === 'raw-data'")
        ->and($aws)->toContain(": 'monitoring-summary'")
        ->and($navigation)->toContain("href: '/aws?tab=monitoring-summary'")
        ->and($navigation)->toContain("{ tab: 'raw-data' }")
        ->and($navigation)->toContain("{ tab: 'analytics' }")
        ->and($summary)->toContain('AwsWeatherRemarkBadge')
        ->and($summary)->not->toContain('Data Completeness (%)')
        ->and($pdf)->not->toContain('Data Completeness (%)')
        ->and($xlsx)->not->toContain('Data Completeness (%)')
        ->and($docx)->not->toContain('Data Completeness (%)')
        ->and($pdf)->toContain('Remarks')
        ->and($xlsx)->toContain('Remarks')
        ->and($docx)->toContain('Remarks')
        ->and(File::get(resource_path('js/Pages/Ipaf/Index.jsx')))->toContain("key: 'accounting'");
});
