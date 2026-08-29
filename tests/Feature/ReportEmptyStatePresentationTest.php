<?php

use Illuminate\Support\Facades\File;

test('CrudTable keeps its existing default and supports an opt-in compact report empty state', function (): void {
    $table = File::get(resource_path('js/Components/Crud/CrudTable.jsx'));

    expect($table)->toContain('compactEmpty = false')
        ->and($table)->toContain('compactEmpty ? <CompactReportEmptyState')
        ->and($table)->toContain('const showHeader = !compactEmpty || !empty;')
        ->and($table)->toContain('const showPagination = pagination && (!compactEmpty || !empty);')
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
        resource_path('js/Pages/TechnicalReports/Index.jsx'),
        resource_path('js/Pages/ManagementPlans/Index.jsx'),
    ];

    foreach ($specialized as $file) {
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

test('IMEA Report and IMEA Data do not render the redundant local tab shell', function (): void {
    $report = File::get(resource_path('js/Pages/Imea/ReportSubmissions.jsx'));
    $data = File::get(resource_path('js/Pages/Imea/Index.jsx'));

    expect($report)->not->toContain('WorkflowTabs')
        ->and($data)->not->toContain('WorkflowTabs')
        ->and($report)->toContain('PageHeader')
        ->and($data)->toContain('Facilities & Infrastructures inventory');
});

test('legitimate multi-view navigation remains in BMS, BAMS, AWS, and IPAF', function (): void {
    expect(File::get(resource_path('js/Pages/Bms/Index.jsx')))->toContain("setActiveTab('list')")
        ->and(File::get(resource_path('js/Pages/Bams/Index.jsx')))->toContain("setActiveTab('map')")
        ->and(File::get(resource_path('js/Pages/AWS/Aws.jsx')))->toContain("handleTabChange('analytics')")
        ->and(File::get(resource_path('js/Pages/Ipaf/Index.jsx')))->toContain("key: 'accounting'");
});
