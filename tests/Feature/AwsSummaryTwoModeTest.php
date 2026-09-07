<?php

use App\Models\Aws;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\AwsMonthlySummaryService;
use Spatie\Permission\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

function twoModeAwsAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    return $user;
}

function twoModeAwsArea(User $owner, string $name): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name,
        'short_name' => strtoupper(substr($name, 0, 3)),
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function twoModeAwsDaily(ProtectedArea $area, string $date, array $values = []): Aws
{
    $row = Aws::create(array_merge([
        'protected_area_id' => $area->id,
        'station_name' => 'Two Mode AWS',
        'location' => 'Test location',
        'report_period_type' => 'Daily',
        'status' => 'Active',
        'timestamps' => date('F j, Y', strtotime($date)),
        'start_date' => $date,
        'end_date' => $date,
        'atmospheric_pressure' => 100,
        'air_temperature' => 25,
        'relative_humidity' => 80,
        'wind_direction' => 90,
        'wind_speed' => 2,
        'precipitation' => 1,
    ], $values));
    $row->observation_count = $values['observation_count'] ?? 96;
    $row->save();

    return $row;
}

test('one month materializes every calendar day and keeps missing metrics null', function () {
    $user = twoModeAwsAdmin();
    $area = twoModeAwsArea($user, 'One Month AWS PA');
    twoModeAwsDaily($area, '2026-01-01', ['precipitation' => 0, 'observation_count' => 96]);
    twoModeAwsDaily($area, '2026-01-03', ['precipitation' => 2, 'observation_count' => 48]);

    $rows = app(AwsMonthlySummaryService::class)->summarizePeriod($user, 'one_month', [
        'year' => 2026, 'month' => 1,
    ], $area->id);

    expect($rows)->toHaveCount(31)
        ->and($rows->first()['period'])->toBe('January 1, 2026')
        ->and($rows->last()['period'])->toBe('January 31, 2026')
        ->and($rows[0]['total_precipitation'])->toBe(0.0)
        ->and($rows[0]['expected_observations'])->toBe(96)
        ->and($rows[2]['data_completeness'])->toBe(50.0)
        ->and($rows[1]['remarks'])->toBe('No Data')
        ->and($rows[1]['average_air_temperature'])->toBeNull()
        ->and($rows[1]['total_precipitation'])->toBeNull();
});

test('one month uses actual calendar lengths including leap February and April', function () {
    $user = twoModeAwsAdmin();
    $area = twoModeAwsArea($user, 'Calendar Length AWS PA');
    $service = app(AwsMonthlySummaryService::class);

    expect($service->summarizePeriod($user, 'one_month', ['year' => 2026, 'month' => 2], $area->id))->toHaveCount(28)
        ->and($service->summarizePeriod($user, 'one_month', ['year' => 2024, 'month' => 2], $area->id))->toHaveCount(29)
        ->and($service->summarizePeriod($user, 'one_month', ['year' => 2026, 'month' => 4], $area->id))->toHaveCount(30);
});

test('custom range creates independent partial and complete monthly buckets', function () {
    $user = twoModeAwsAdmin();
    $area = twoModeAwsArea($user, 'Custom Range AWS PA');
    twoModeAwsDaily($area, '2026-01-15', ['precipitation' => 4]);
    twoModeAwsDaily($area, '2026-02-01', ['precipitation' => 2]);
    twoModeAwsDaily($area, '2026-08-20', ['precipitation' => 3]);

    $rows = app(AwsMonthlySummaryService::class)->summarizePeriod($user, 'custom_range', [
        'date_from' => '2026-01-15', 'date_to' => '2026-08-20',
    ], $area->id);

    expect($rows)->toHaveCount(8)
        ->and($rows->pluck('period')->all())->toBe([
            'January 15–31, 2026', 'February 2026', 'March 2026', 'April 2026',
            'May 2026', 'June 2026', 'July 2026', 'August 1–20, 2026',
        ])
        ->and($rows[0]['expected_observations'])->toBe(1632)
        ->and($rows[1]['expected_observations'])->toBe(2688)
        ->and($rows[7]['expected_observations'])->toBe(1920)
        ->and($rows[0]['total_precipitation'])->toBe(4.0)
        ->and($rows[7]['total_precipitation'])->toBe(3.0)
        ->and($rows[2]['remarks'])->toBe('No Data');
});

test('custom range supports a cross-year sequence in chronological order', function () {
    $user = twoModeAwsAdmin();
    $area = twoModeAwsArea($user, 'Cross Year AWS PA');

    $rows = app(AwsMonthlySummaryService::class)->summarizePeriod($user, 'custom_range', [
        'date_from' => '2026-11-20', 'date_to' => '2027-02-15',
    ], $area->id);

    expect($rows->pluck('period')->all())->toBe([
        'November 20–30, 2026', 'December 2026', 'January 2027', 'February 1–15, 2027',
    ]);
});


test('controller accepts strict custom date ranges without shifting endpoints', function () {
    $user = twoModeAwsAdmin();
    twoModeAwsArea($user, 'Controller Range AWS PA');

    foreach ([
        ['2026-01-01', '2026-08-31'],
        ['2026-01-15', '2026-08-20'],
        ['2026-11-20', '2027-02-15'],
    ] as [$from, $to]) {
        $this->actingAs($user)
            ->get(route('aws.index', [
                'mode' => 'custom_range',
                'date_from' => $from,
                'date_to' => $to,
                'tab' => 'monthly-summary',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('monthlyFilters.mode', 'custom_range')
                ->where('monthlyFilters.date_from', $from)
                ->where('monthlyFilters.date_to', $to));
    }

    $this->actingAs($user)
        ->get(route('aws.index', [
            'mode' => 'custom_range',
            'date_from' => '2026-08-31',
            'date_to' => '2026-01-01',
            'tab' => 'monthly-summary',
        ]))
        ->assertSessionHasErrors('date_to');
});