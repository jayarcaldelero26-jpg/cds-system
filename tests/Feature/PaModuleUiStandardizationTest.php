<?php

use Illuminate\Support\Facades\File;

test('AWS report tracker imports the shared input and AWS data has a dedicated route', function (): void {
    $tracker = File::get(resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'));
    $aws = File::get(resource_path('js/Pages/AWS/Aws.jsx'));

    expect($tracker)->toContain("@/Pages/Bms/ReportSubmissionTracker");

    expect($aws)
        ->not->toContain('aria-label="AWS workspaces"')
        ->not->toContain('aria-label="Weather Analytics views"');

    expect(route('aws.index'))->toEndWith('/aws')
        ->and(route('aws.data'))->toEndWith('/aws-data');
});

test('AWS report form separates quarter, document type, and shared coverage range', function (): void {
    $tracker = File::get(resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'));
    $shared = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));

    expect($tracker)
        ->toContain("period_field: 'quarter'")
        ->toContain("period_label: 'Reporting Period'")
        ->toContain("documents: ['Progress Report', 'Final Report']")
        ->not->toContain('Final Report (1-15)')
        ->not->toContain('Final Report (16-30)')
        ->not->toContain('Final Report (16-28)');
    expect($shared)
        ->toContain("@/Components/DateRangePicker")
        ->toContain('Coverage Period')
        ->toContain('FloatingInput id="reportsubmissiontracker-name-of-activity"')
        ->not->toContain('FloatingSelect id="reportsubmissiontracker-name-of-activity"')
        ->toContain('monitoring_period_start')
        ->toContain('monitoring_period_end');
});

test('PA report workflows use the shared standard tracker and completed state contract', function (): void {
    $standard = File::get(resource_path('js/Components/StandardAReportSubmissionTracker.jsx'));
    $tracker = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));
    $sidebar = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($standard)
        ->toContain("import PageHeader from '@/Components/PageHeader';")
        ->toContain("import ReportSubmissionTracker from '@/Pages/Bms/ReportSubmissionTracker';");

    expect($tracker)
        ->toContain('CrudDetailsModal')
        ->toContain('completedSubmission')
        ->toContain('FileAttachmentPanel');

    expect($sidebar)
        ->toContain("label: 'Automated Weather Station'")
        ->toContain("{ label: 'AWS Data', href: '/aws-data'")
        ->not->toContain("label: 'AWS Monitoring Reports'");

    $layout = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
    expect(substr_count($layout, "label: 'AWS Data'"))->toBe(1);
    expect(strpos($layout, "label: 'AWS Data'") > strpos($layout, "label: 'Conservation Database'"))->toBeTrue();
});
