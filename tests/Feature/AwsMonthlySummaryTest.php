<?php

use App\Models\Aws;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\AwsMonthlySummaryService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function awsArea(User $owner, string $name): ProtectedArea
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

function awsRaw(ProtectedArea $area, array $values): Aws
{
    $row = Aws::create(array_merge([
        'protected_area_id' => $area->id,
        'station_name' => 'Test AWS',
        'location' => 'Test location',
        'report_period_type' => 'Daily',
        'status' => 'Active',
        'timestamps' => 'July 01, 2026',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-01',
    ], $values));
    foreach (['observation_count', 'expected_observations', 'data_completeness'] as $key) {
        if (array_key_exists($key, $values)) {
            $row->{$key} = $values[$key];
        }
    }
    $row->save();
    return $row;
}

function awsAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    return $user;
}

test('monthly summary uses weighted arithmetic means, interval rainfall totals, derived vapor pressure, and circular wind mean', function () {
    $user = awsAdmin();
    $area = awsArea($user, 'Circular AWS PA');
    awsRaw($area, [
        'start_date' => '2026-07-01', 'timestamps' => 'July 01, 2026',
        'observation_count' => 96, 'atmospheric_pressure' => 100, 'air_temperature' => 20,
        'relative_humidity' => 50, 'precipitation' => 2, 'wind_direction' => '359', 'wind_speed' => 2,
    ]);
    awsRaw($area, [
        'start_date' => '2026-07-02', 'timestamps' => 'July 02, 2026',
        'observation_count' => 96, 'atmospheric_pressure' => 102, 'air_temperature' => 24,
        'relative_humidity' => 70, 'precipitation' => 3, 'wind_direction' => '1', 'wind_speed' => 4,
    ]);

    $row = app(AwsMonthlySummaryService::class)->summarize($user, 2026, 7, $area->id)->sole();

    expect($row['average_atmospheric_pressure'])->toBe(101.0)
        ->and($row['average_air_temperature'])->toBe(22.0)
        ->and($row['average_relative_humidity'])->toBe(60.0)
        ->and($row['average_wind_speed'])->toBe(3.0)
        ->and($row['total_precipitation'])->toBe(5.0)
        ->and($row['mean_wind_direction'])->toBe(0.0)
        ->and($row['observation_count'])->toBe(192)
        ->and($row['expected_observations'])->toBe(2976)
        ->and($row['remarks'])->toBe('Normal Weather Conditions')
        ->and($row['average_vapor_pressure'])->toBeGreaterThan(1.0)
        ->and($row['average_vapor_pressure_deficit'])->toBeGreaterThan(0.0)
        ->and($row['average_vapor_pressure_deficit'])->toBeLessThan($row['average_vapor_pressure']);
});

test('null sensor values are excluded and a month with no persisted raw rows returns no fabricated row', function () {
    $user = awsAdmin();
    $area = awsArea($user, 'Null AWS PA');
    awsRaw($area, [
        'observation_count' => 2976, 'atmospheric_pressure' => null, 'air_temperature' => 25,
        'relative_humidity' => 80, 'precipitation' => null, 'wind_direction' => null, 'wind_speed' => null,
    ]);

    $service = app(AwsMonthlySummaryService::class);
    $row = $service->summarize($user, 2026, 7, $area->id)->sole();
    expect($row['average_atmospheric_pressure'])->toBeNull()
        ->and($row['total_precipitation'])->toBeNull()
        ->and($row['mean_wind_direction'])->toBeNull();

    expect($service->summarize($user, 2026, 8, $area->id))->toBeEmpty();
});

test('all protected areas produce one row per PA with July observations', function () {
    $user = awsAdmin();
    $first = awsArea($user, 'First AWS PA');
    $second = awsArea($user, 'Second AWS PA');
    awsRaw($first, ['observation_count' => 96, 'air_temperature' => 20]);
    awsRaw($second, ['observation_count' => 96, 'air_temperature' => 30]);

    $rows = app(AwsMonthlySummaryService::class)->summarize($user, 2026, 7);
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('protected_area_name')->all())->toContain('First AWS PA', 'Second AWS PA');
});

test('scoped users cannot read another PA monthly summary or export it', function () {
    $admin = awsAdmin();
    $allowed = awsArea($admin, 'Allowed AWS PA');
    $other = awsArea($admin, 'Other AWS PA');
    awsRaw($allowed, ['observation_count' => 96, 'air_temperature' => 20]);
    awsRaw($other, ['observation_count' => 96, 'air_temperature' => 30]);

    $scoped = User::factory()->create([
        'section' => 'PAMO', 'unit_assignment' => 'conservation', 'protected_area_id' => $allowed->id,
    ]);
    $scoped->assignRole(Role::findOrCreate('PAMO', 'web'));
    $scoped->givePermissionTo(Permission::findOrCreate('aws.view', 'web'));

    $this->actingAs($scoped)->get(route('aws.monthly-summary.pdf', [
        'monthly_year' => 2026, 'monthly_month' => 7, 'monthly_protected_area_id' => $other->id,
    ]))->assertForbidden();
    $this->actingAs($scoped)->get(route('aws.monthly-summary.xlsx', [
        'monthly_year' => 2026, 'monthly_month' => 7, 'monthly_protected_area_id' => $other->id,
    ]))->assertForbidden();
});

test('monthly summary page, PDF, and XLSX routes use the same selected summary period', function () {
    $user = awsAdmin();
    $area = awsArea($user, 'Export AWS PA');
    awsRaw($area, ['observation_count' => 2976, 'air_temperature' => 26, 'precipitation' => 7.5]);
    $query = ['monthly_year' => 2026, 'monthly_month' => 7, 'monthly_protected_area_id' => $area->id];

    $page = $this->actingAs($user)->get(route('aws.index', $query + ['tab' => 'monthly-summary']));
    $page->assertInertia(fn ($assert) => $assert->component('AWS/Aws')->where('monthlySummary.0.period', 'July 2026')->where('monthlySummary.0.total_precipitation', 7.5));
    $this->actingAs($user)->get(route('aws.monthly-summary.pdf', $query))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($user)->get(route('aws.monthly-summary.xlsx', $query))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
