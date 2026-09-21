<?php

use App\Services\DateConductedRangeService;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

function conductedRanges(): DateConductedRangeService
{
    return app(DateConductedRangeService::class);
}

test('selected workflows support structured conducted-date ranges only', function (): void {
    expect(conductedRanges()->supportsWorkflow('additional_bms_site'))->toBeTrue()
        ->and(conductedRanges()->supportsWorkflow('bdfe_terrestrial'))->toBeTrue()
        ->and(conductedRanges()->supportsWorkflow('maintenance_pamo_ecotourism'))->toBeTrue()
        ->and(conductedRanges()->supportsWorkflow('homestay'))->toBeFalse()
        ->and(conductedRanges()->supportsWorkflow('regular_pamb'))->toBeFalse();
});

test('normalizes one and multiple ranges without merging entries', function (): void {
    $ranges = conductedRanges()->normalize([
        ['from' => '2026-02-03', 'to' => '2026-02-05'],
        ['from' => '2026-02-09', 'to' => '2026-02-12'],
    ]);

    expect($ranges)->toHaveCount(2)
        ->and($ranges[0])->toBe(['from' => '2026-02-03', 'to' => '2026-02-05'])
        ->and($ranges[1])->toBe(['from' => '2026-02-09', 'to' => '2026-02-12']);
});

test('single-date entries use the same date for both endpoints and legacy dates remain readable', function (): void {
    expect(conductedRanges()->normalize([['from' => '2026-02-03', 'to' => '']]))
        ->toBe([['from' => '2026-02-03', 'to' => '2026-02-03']])
        ->and(conductedRanges()->display(null, '2026-02-03'))->toBe('2026-02-03');
});

test('conducted-date ranges reject an end before its start', function (): void {
    expect(fn () => conductedRanges()->normalize([['from' => '2026-02-05', 'to' => '2026-02-03']]))
        ->toThrow(ValidationException::class);
});

test('conducted-date formatter uses compact same-month and readable cross-period output', function (): void {
    $service = conductedRanges();

    expect($service->display([
        ['from' => '2026-02-03', 'to' => '2026-02-05'],
        ['from' => '2026-02-09', 'to' => '2026-02-12'],
        ['from' => '2026-02-16', 'to' => '2026-02-20'],
    ]))->toBe('Feb. 3-5, 9-12 and 16-20, 2026')
        ->and($service->display([
            ['from' => '2026-02-03', 'to' => '2026-02-05'],
            ['from' => '2026-02-09', 'to' => '2026-02-09'],
            ['from' => '2026-02-16', 'to' => '2026-02-20'],
        ]))->toBe('Feb. 3-5, 9 and 16-20, 2026')
        ->and($service->display([
            ['from' => '2026-02-27', 'to' => '2026-03-02'],
        ]))->toBe('Feb. 27-Mar. 2, 2026')
        ->and($service->display([
            ['from' => '2025-12-30', 'to' => '2025-12-30'],
            ['from' => '2026-01-02', 'to' => '2026-01-04'],
        ]))->toBe('Dec. 30, 2025 and Jan. 2-4, 2026');
});

test('shared tracker keeps structured ranges out of visible text and scopes the PAMB note', function (): void {
    $tracker = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));

    expect($tracker)
        ->toContain('date_conducted_display || row.date_conducted')
        ->toContain('date_conducted_display || selectedReport.date_conducted')
        ->toContain('+ Add Date')
        ->toContain('date_conducted_ranges')
        ->toContain('const meetingPambWorkflows = [\'regular_pamb\', \'special_pamb\', \'twc_meetings\']')
        ->toContain('The term "report" in the Column "Status of Submission"')
        ->toContain('text-red-600')
        ->toContain('text-xs italic')
        ->not->toContain('JSON.stringify(row.date_conducted_ranges)');
});
