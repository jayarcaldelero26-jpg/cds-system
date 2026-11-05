<?php

use Illuminate\Support\Facades\File;

test('selected multi-date rows use the shared premium range picker', function (): void {
    $tracker = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));
    $rangeField = File::get(resource_path('js/Components/Reports/DateConductedRangesField.jsx'));

    expect($tracker)
        ->toContain('<DateConductedRangesField')
        ->toContain('idPrefix="reportsubmissiontracker-date-conducted-range"')
        ->toContain('onRangeChange={updateDateConductedRange}')
        ->and($rangeField)
        ->toContain('<DateRangePicker')
        ->toContain('onRangeChange(index, value)')
        ->toContain('actualDate')
        ->not->toContain('reportsubmissiontracker-date-conducted-from-')
        ->not->toContain('reportsubmissiontracker-date-conducted-to-');
});

test('editable single-date report surfaces use the shared premium date picker', function (): void {
    $paths = [
        'js/Pages/Bms/ReportSubmissionTracker.jsx',
        'js/Pages/Ipaf/Index.jsx',
        'js/Pages/Imea/MaintenanceReports.jsx',
        'js/Pages/ManagementPlans/Form.jsx',
        'js/Pages/TechnicalReports/Form.jsx',
    ];

    foreach ($paths as $path) {
        $source = File::get(resource_path($path));

        expect($source)->toContain('DatePicker')
            ->toContain('canonicalDateConductedValue(form.data.date_conducted)')
            ->toContain('legacyDateConductedHelper(form.data.date_conducted)');
    }
});

test('inventory as-of metadata remains outside the report Date Conducted standardization scope', function (): void {
    $inventory = File::get(resource_path('js/Pages/Imea/Index.jsx'));

    expect($inventory)
        ->toContain('Date Conducted / Inventory As-Of Period')
        ->toContain('inventory_date');
});
