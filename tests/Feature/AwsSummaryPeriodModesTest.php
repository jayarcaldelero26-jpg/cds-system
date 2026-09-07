<?php

use App\Models\Aws;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\AwsMonthlySummaryService;
use Spatie\Permission\Models\Role;

function periodAwsAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    return $user;
}

function periodAwsArea(User $owner, string $name): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name, 'short_name' => strtoupper(substr($name, 0, 3)),
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

function periodAwsRaw(ProtectedArea $area, string $date, array $values = []): Aws
{
    $row = Aws::create(array_merge([
        'protected_area_id' => $area->id, 'station_name' => 'Period Test AWS',
        'location' => 'Test location', 'report_period_type' => 'Daily', 'status' => 'Active',
        'timestamps' => date('F j, Y', strtotime($date)), 'start_date' => $date, 'end_date' => $date,
    ], $values));
    foreach (['observation_count', 'expected_observations', 'data_completeness'] as $key) {
        if (array_key_exists($key, $values)) $row->{$key} = $values[$key];
    }
    $row->save();
    return $row;
}

test('by day filters exactly one date and expects 96 observations', function () {
    $user = periodAwsAdmin();
    $area = periodAwsArea($user, 'Daily AWS PA');
    periodAwsRaw($area, '2026-09-06', ['observation_count' => 96, 'air_temperature' => 18, 'precipitation' => 2]);
    periodAwsRaw($area, '2026-09-07', ['observation_count' => 96, 'air_temperature' => 24, 'precipitation' => 3]);

    $row = app(AwsMonthlySummaryService::class)->summarizePeriod($user, 'day', ['date' => '2026-09-07'], $area->id)->sole();

    expect($row['period'])->toBe('September 7, 2026')
        ->and($row['average_air_temperature'])->toBe(24.0)
        ->and($row['total_precipitation'])->toBe(3.0)
        ->and($row['observation_count'])->toBe(96)
        ->and($row['expected_observations'])->toBe(96);
});

test('date range is inclusive and uses weighted metrics and summed daily interval rainfall', function () {
    $user = periodAwsAdmin();
    $area = periodAwsArea($user, 'Range AWS PA');
    periodAwsRaw($area, '2026-09-01', ['observation_count' => 96, 'air_temperature' => 20, 'precipitation' => 4, 'wind_direction' => '359']);
    periodAwsRaw($area, '2026-09-07', ['observation_count' => 192, 'air_temperature' => 26, 'precipitation' => 6, 'wind_direction' => '1']);

    $row = app(AwsMonthlySummaryService::class)->summarizePeriod($user, 'range', ['date_from' => '2026-09-01', 'date_to' => '2026-09-07'], $area->id)->sole();

    expect($row['period'])->toBe('September 1–7, 2026')
        ->and($row['average_air_temperature'])->toBe(24.0)
        ->and($row['total_precipitation'])->toBe(10.0)
        ->and($row['mean_wind_direction'])->toBe(0.33)
        ->and($row['observation_count'])->toBe(288)
        ->and($row['expected_observations'])->toBe(672);
});

test('date range rejects an end date before its start date', function () {
    $user = periodAwsAdmin();

    $this->actingAs($user)->get(route('aws.monthly-summary.pdf', [
        'mode' => 'range', 'date_from' => '2026-09-07', 'date_to' => '2026-09-01',
    ]))->assertSessionHasErrors('date_to');
});

test('day and range page and export routes use the shared summary result contract', function () {
    $user = periodAwsAdmin();
    $area = periodAwsArea($user, 'Period Export AWS PA');
    periodAwsRaw($area, '2026-09-07', ['observation_count' => 96, 'air_temperature' => 25, 'precipitation' => 2]);
    periodAwsRaw($area, '2026-09-08', ['observation_count' => 96, 'air_temperature' => 27, 'precipitation' => 3]);

    foreach ([
        ['mode' => 'day', 'date' => '2026-09-07', 'period' => 'September 7, 2026'],
        ['mode' => 'range', 'date_from' => '2026-09-07', 'date_to' => '2026-09-08', 'period' => 'September 7–8, 2026'],
    ] as $period) {
        $query = array_merge($period, ['protected_area_id' => $area->id]);
        $this->actingAs($user)->get(route('aws.index', $query + ['tab' => 'monthly-summary']))
            ->assertInertia(fn ($assert) => $assert->component('AWS/Aws')->where('monthlySummary.0.period', $period['period']));
        $this->actingAs($user)->get(route('aws.monthly-summary.pdf', $query))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($user)->get(route('aws.monthly-summary.xlsx', $query))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
});
