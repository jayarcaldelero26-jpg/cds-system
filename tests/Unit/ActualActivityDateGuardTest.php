<?php

use App\Services\ActualActivityDateGuard;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('actual activity dates allow yesterday and today and reject tomorrow with a field-specific message', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 15:30:00', 'UTC'));
    $guard = app(ActualActivityDateGuard::class);

    $guard->assertNotFuture('2026-10-07', 'date_conducted', 'Date Conducted');
    $guard->assertNotFuture('2026-10-08', 'date_accomplished', 'Date Accomplished');

    try {
        $guard->assertNotFuture('2026-10-09', 'date_accomplished', 'Date Accomplished');
        test()->fail('Tomorrow must be rejected in the Philippines business timezone.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'date_accomplished' => ['Date Accomplished cannot be later than today (Philippines time).'],
        ]);
    }
});

test('the business-date boundary follows Asia Manila rather than the PHP host date', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 16:30:00', 'UTC'));
    $guard = app(ActualActivityDateGuard::class);

    // UTC is still October 8, but the Philippines business date is October 9.
    $guard->assertNotFuture('2026-10-09', 'date_conducted', 'Date Conducted');

    expect(fn () => $guard->assertNotFuture('2026-10-10', 'date_conducted', 'Date Conducted'))
        ->toThrow(ValidationException::class);
});

test('unchanged legacy future actual dates remain editable while changed future dates fail', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $guard = app(ActualActivityDateGuard::class);

    $guard->assertNotFuture('2026-10-22', 'date_accomplished', 'Date Accomplished', '2026-10-22');

    expect(fn () => $guard->assertNotFuture('2026-10-23', 'date_accomplished', 'Date Accomplished', '2026-10-22'))
        ->toThrow(ValidationException::class);
});

test('conducted date ranges reject a new future endpoint but retain an unchanged legacy range', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $guard = app(ActualActivityDateGuard::class);
    $existing = [['from' => '2026-10-22', 'to' => '2026-10-22']];

    $guard->assertDateConductedRanges($existing, $existing);

    try {
        $guard->assertDateConductedRanges([['from' => '2026-10-23', 'to' => '2026-10-23']], $existing);
        test()->fail('A changed future range must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('date_conducted_ranges.0.from');
    }
});
