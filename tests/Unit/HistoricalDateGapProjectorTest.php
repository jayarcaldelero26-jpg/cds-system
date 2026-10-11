<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Services\HistoricalReports\HistoricalDateGapProjector;

function historicalGapOrigin(array $milestones, string $workflow = 'regular_pamb'): HistoricalReportOrigin
{
    return new HistoricalReportOrigin([
        'workflow_key' => $workflow,
        'original_milestones' => ['milestones' => $milestones],
    ]);
}

test('historical date gaps use distinct date-only PAMB intervals and preserve equal zero-day gaps', function (): void {
    $record = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb',
        'date_conducted' => '2026-03-18',
        'date_accomplished' => '2026-03-18',
    ]);
    $origin = historicalGapOrigin([
        'date_report_released_cenro' => '2026-03-25',
        'date_received_penro' => '2026-03-25',
        'date_endorsed_regional' => '2026-03-31',
    ]);

    $gaps = app(HistoricalDateGapProjector::class)->project('conservation', $record, $origin);

    expect($gaps['preparation']['status'])->toBe('complete')
        ->and($gaps['preparation']['elapsed_calendar_days'])->toBe(0)
        ->and($gaps['processing']['status'])->toBe('complete')
        ->and($gaps['processing']['elapsed_calendar_days'])->toBe(7)
        ->and($gaps['handoff']['status'])->toBe('complete')
        ->and($gaps['handoff']['elapsed_calendar_days'])->toBe(0)
        ->and($gaps['processing']['unit'])->toBe('calendar days');
});

test('only PAMB meeting workflows may use the existing conducted-date accomplishment fallback', function (): void {
    $projector = app(HistoricalDateGapProjector::class);
    $milestones = ['date_report_released_cenro' => '2026-03-25', 'date_received_penro' => '2026-03-25'];
    $pamb = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb',
        'date_conducted' => '2026-03-18',
        'date_accomplished' => null,
    ]);
    $generic = new ConservationReportSubmission([
        'workflow_key' => 'conservation_activity',
        'date_conducted' => '2026-03-18',
        'date_accomplished' => null,
    ]);

    $pambGap = $projector->project('conservation', $pamb, historicalGapOrigin($milestones));
    $genericGap = $projector->project('conservation', $generic, historicalGapOrigin($milestones, 'conservation_activity'));

    expect($pambGap['processing']['start_label'])->toBe('Date Conducted (per PAMB accomplished-date fallback)')
        ->and($pambGap['processing']['elapsed_calendar_days'])->toBe(7)
        ->and($pamb->getAttribute('date_accomplished'))->toBeNull()
        ->and($genericGap['processing']['status'])->toBe('missing')
        ->and($genericGap['processing']['elapsed_calendar_days'])->toBeNull();
});

test('structured conducted ranges use their earliest valid source date and reversed gaps are invalid', function (): void {
    $record = new BmsReportSubmission([
        'workflow_key' => 'additional_bms_site',
        'date_conducted_ranges' => [
            ['from' => '2026-01-20', 'to' => '2026-01-21'],
            ['from' => '2026-01-05', 'to' => '2026-01-06'],
        ],
        'date_accomplished' => '2026-01-25',
    ]);
    $gaps = app(HistoricalDateGapProjector::class)->project('bms', $record, historicalGapOrigin([
        'date_report_released_cenro' => '2026-01-24',
        'date_received_penro' => '2026-01-24',
    ], 'additional_bms_site'));
    $reversed = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb', 'date_conducted' => '2026-03-18', 'date_accomplished' => '2026-03-25',
    ]);
    $reversedGaps = app(HistoricalDateGapProjector::class)->project('conservation', $reversed, historicalGapOrigin([
        'date_report_released_cenro' => '2026-03-18', 'date_received_penro' => null,
    ]));

    expect($gaps['preparation']['start_label'])->toBe('Date Conducted (earliest structured range)')
        ->and($gaps['preparation']['start_date'])->toBe('2026-01-05')
        ->and($gaps['preparation']['elapsed_calendar_days'])->toBe(20)
        ->and($gaps['processing']['status'])->toBe('invalid')
        ->and($reversedGaps['processing']['status'])->toBe('invalid')
        ->and($reversedGaps['handoff']['status'])->toBe('missing');
});

test('ENGP supports only original release-to-receipt and has no fabricated parent dates', function (): void {
    $projector = app(HistoricalDateGapProjector::class);
    $engp = new EngpReportSubmission(['workflow_key' => 'cbep']);
    $engpGaps = $projector->project('engp', $engp, historicalGapOrigin([
        'date_report_released_cenro' => '2026-07-13', 'date_received_penro' => '2026-07-15',
    ], 'cbep'));

    expect($engpGaps['preparation']['status'])->toBe('unsupported')
        ->and($engpGaps['processing']['status'])->toBe('unsupported')
        ->and($engpGaps['handoff']['elapsed_calendar_days'])->toBe(2);
});
