<?php

use Illuminate\Support\Facades\File;

test('timeliness has one shared semantic presentation mapping', function (): void {
    $badge = File::get(resource_path('js/Components/TimelinessBadge.jsx'));

    expect($badge)->toContain("outstanding: 'bg-emerald-100 text-emerald-800")
        ->and($badge)->toContain("'very satisfactory': 'bg-blue-100 text-blue-800")
        ->and($badge)->toContain("satisfactory: 'bg-amber-100 text-amber-800")
        ->and($badge)->toContain("unsatisfactory: 'bg-orange-100 text-orange-800")
        ->and($badge)->toContain("poor: 'bg-red-100 text-red-800")
        ->and($badge)->toContain("default: 'bg-gray-100 text-gray-700")
        ->and($badge)->toContain('normalizeTimeliness')
        ->and($badge)->toContain("? 'No Rating'");
});

test('active report surfaces use the shared timeliness presentation', function (): void {
    $reportSurfaces = [
        resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'),
        resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'),
        resource_path('js/Pages/Ipaf/Index.jsx'),
        resource_path('js/Pages/Imea/MaintenanceReports.jsx'),
        resource_path('js/Pages/ManagementPlans/Index.jsx'),
        resource_path('js/Pages/ManagementPlans/Form.jsx'),
        resource_path('js/Pages/TechnicalReports/Index.jsx'),
        resource_path('js/Pages/TechnicalReports/Form.jsx'),
        resource_path('js/Pages/SubmissionTracking/Index.jsx'),
        resource_path('js/Pages/Dashboard.jsx'),
        resource_path('js/Pages/Engp/Index.jsx'),
    ];

    foreach ($reportSurfaces as $file) {
        expect(File::get($file))->toContain('TimelinessBadge');
    }

    expect(File::get(resource_path('js/Components/Crud/CrudDetailsModal.jsx')))
        ->toContain('TimelinessBadge');
});

test('report modules no longer duplicate the old green timeliness mapping', function (): void {
    $reportSurfaces = [
        resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'),
        resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'),
        resource_path('js/Pages/Ipaf/Index.jsx'),
        resource_path('js/Pages/Imea/MaintenanceReports.jsx'),
        resource_path('js/Pages/ManagementPlans/Index.jsx'),
        resource_path('js/Pages/ManagementPlans/Form.jsx'),
        resource_path('js/Pages/TechnicalReports/Index.jsx'),
        resource_path('js/Pages/TechnicalReports/Form.jsx'),
    ];

    foreach ($reportSurfaces as $file) {
        $source = File::get($file);

        expect($source)->not->toContain("'Very Satisfactory': 'bg-green")
            ->not->toContain("'Poor': 'bg-green")
            ->not->toContain("'Unsatisfactory': 'bg-green");
    }
});
