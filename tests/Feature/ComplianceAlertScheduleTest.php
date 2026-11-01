<?php

use App\Services\Compliance\ComplianceAlertSchedule;
use Carbon\CarbonImmutable;

test('configured compliance runs follow weekdays at the saved Manila time', function (): void {
    $cases = [
        ['2026-08-28 07:59:00', 'Asia/Manila', '2026-08-28T08:00:00+08:00'],
        ['2026-08-28 08:01:00', 'Asia/Manila', '2026-08-31T08:00:00+08:00'],
        ['2026-08-29 07:00:00', 'Asia/Manila', '2026-08-31T08:00:00+08:00'],
        ['2026-08-30 09:00:00', 'Asia/Manila', '2026-08-31T08:00:00+08:00'],
        ['2026-08-31 07:59:00', 'Asia/Manila', '2026-08-31T08:00:00+08:00'],
        ['2026-08-31 08:01:00', 'Asia/Manila', '2026-09-01T08:00:00+08:00'],
        // These UTC instants are Monday in Manila despite being Sunday/Monday UTC.
        ['2026-08-30 23:30:00', 'UTC', '2026-08-31T08:00:00+08:00'],
        ['2026-08-31 00:30:00', 'UTC', '2026-09-01T08:00:00+08:00'],
    ];

    foreach ($cases as [$instant, $timezone, $expected]) {
        expect(ComplianceAlertSchedule::nextWeekdayRun(
            CarbonImmutable::parse($instant, $timezone),
            '08:00',
        )->toIso8601String())->toBe($expected);
    }
});
