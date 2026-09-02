<?php

use Illuminate\Support\Facades\File;

it('standardizes active report summary fields and supplies routing performance in the timeline', function () {
    $modal = File::get(resource_path('js/Components/Crud/CrudDetailsModal.jsx'));
    $section = File::get(resource_path('js/Components/Crud/CrudSection.jsx'));

    expect($modal)
        ->toContain("label: 'Reporting Period'")
        ->toContain("label: 'Submission Status'")
        ->toContain("label: 'Deadline'")
        ->toContain("label: 'Timeliness Rating'");

    expect($section)
        ->toContain("'Submission Information': 'Submission Timeline'")
        ->toContain('Days Complied');
});

it('keeps the Generic Conservation, BMS, BAMS, and IMEA shared tracker free of duplicate compliance fields', function () {
    $tracker = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));

    expect($tracker)
        ->toContain("useReportDetails")
        ->toContain('Date Accomplished')
        ->not->toContain('<CrudSection title="Compliance">');
});

it('retains source-specific report details without duplicating summary values', function () {
    $aws = File::get(resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'));
    $engp = File::get(resource_path('js/Pages/Engp/Index.jsx'));
    $revenue = File::get(resource_path('js/Pages/Ipaf/Index.jsx'));
    $section = File::get(resource_path('js/Components/Crud/CrudSection.jsx'));
    $plans = File::get(resource_path('js/Pages/ManagementPlans/Index.jsx'));

    expect($aws)->toContain('Station / Source')->toContain('Location');
    expect($engp)->not->toContain('Date Accomplished')->not->toContain('Regional Endorsement');
    expect($revenue)->toContain('Total Collected')->toContain('IPAF RIA 75%')->toContain('SAGF 25%');
    expect($section)->toContain("'Revenue': 'Financial Details'");
    expect($plans)->toContain('useReportDetails')->toContain('Plan');
});

it('uses one attachment heading for report details and leaves non-report modals structurally independent', function () {
    $preview = File::get(resource_path('js/Components/Crud/FilePreviewPanel.jsx'));
    $modal = File::get(resource_path('js/Components/Crud/CrudDetailsModal.jsx'));

    expect($preview)->toContain('hideHeader')->toContain('useReportDetails');
    expect($modal)->toContain("<CrudSection title=\"Attachment / MOV\">");
});

it('does not reintroduce IMEA Maintenance into active tracking or alerts', function () {
    $sidebar = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
    $tracking = File::get(resource_path('js/Pages/SubmissionTracking/Index.jsx'));
    $alerts = File::get(resource_path('js/Pages/ComplianceAlerts/Index.jsx'));

    expect($sidebar)->not->toContain('IMEA Maintenance');
    expect($tracking)->not->toContain('IMEA Maintenance');
    expect($alerts)->not->toContain('IMEA Maintenance');
});

it('keeps Submission Tracking tables navigation-only and preserves Full Details actions', function () {
    $tracking = File::get(resource_path('js/Pages/SubmissionTracking/Index.jsx'));

    expect($tracking)
        ->not->toContain("{ key: 'action'")
        ->toContain('onRowClick={setDetails}')
        ->toContain('<PambMovProgress')
        ->toContain('<PambRoutingTimeline');
});

it('uses a safe UTF-8 fallback throughout Submission Tracking', function () {
    $tracking = File::get(resource_path('js/Pages/SubmissionTracking/Index.jsx'));

    expect($tracking)
        ->toContain("const FALLBACK = '\\u2014';")
        ->not->toContain("\xC3\xA2")
        ->not->toContain("\xC3\x83");
});
