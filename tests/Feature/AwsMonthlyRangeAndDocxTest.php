<?php

use App\Models\Aws;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\AwsMonthlySummaryService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;


function rangeDocxAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    return $user;
}

function rangeDocxArea(User $owner, string $name): ProtectedArea
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

function rangeDocxAws(ProtectedArea $area, string $date, array $values = []): Aws
{
    $row = Aws::create(array_merge([
        'protected_area_id' => $area->id,
        'station_name' => 'Range DOCX AWS',
        'location' => 'Test location',
        'report_period_type' => 'Daily',
        'status' => 'Active',
        'timestamps' => date('F j, Y', strtotime($date)),
        'start_date' => $date,
        'end_date' => $date,
        'atmospheric_pressure' => 100,
        'air_temperature' => 25,
        'relative_humidity' => 80,
        'wind_direction' => 359,
        'wind_speed' => 2,
        'precipitation' => 1.5,
    ], $values));
    $row->observation_count = $values['observation_count'] ?? 96;
    $row->save();

    return $row;
}

test('monthly range returns one ascending row per month for each PA and marks missing months without zeros', function () {
    $user = rangeDocxAdmin();
    $area = rangeDocxArea($user, 'Range Summary PA');
    rangeDocxAws($area, '2026-01-15', ['precipitation' => 4]);
    rangeDocxAws($area, '2026-03-15', ['precipitation' => 6]);

    $rows = app(AwsMonthlySummaryService::class)->summarizePeriod($user, 'month', [
        'year' => 2026, 'from_month' => 1, 'to_month' => 3,
    ], $area->id);

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('period')->all())->toBe(['January 2026', 'February 2026', 'March 2026'])
        ->and($rows[0]['total_precipitation'])->toBe(4.0)
        ->and($rows[1]['remarks'])->toBe('No Data')
        ->and($rows[1]['average_air_temperature'])->toBeNull()
        ->and($rows[2]['total_precipitation'])->toBe(6.0);
});

test('unified export accepts only supported formats and produces a real DOCX with shared report content', function () {
    $user = rangeDocxAdmin();
    $area = rangeDocxArea($user, 'Export Range PA');
    rangeDocxAws($area, '2026-01-15', ['precipitation' => 4]);

    $query = [
        'mode' => 'month', 'year' => 2026, 'from_month' => 1, 'to_month' => 3,
        'protected_area_id' => $area->id,
    ];

    $page = $this->actingAs($user)->get(route('aws.index', $query + ['tab' => 'monthly-summary']));
    $page->assertInertia(fn ($assert) => $assert
        ->component('AWS/Aws')
        ->has('monthlySummary', 3)
        ->where('monthlySummary.0.period', 'January 2026')
        ->where('monthlySummary.1.remarks', 'No Data'));

    foreach (['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] as $format => $mime) {
        $response = $this->actingAs($user)->get(route('aws.summary.export', $query + ['format' => $format]));
        $response->assertOk()->assertHeader('content-type', $mime);
    }

    $docx = $this->actingAs($user)->get(route('aws.summary.export', $query + ['format' => 'docx']));
    $path = $docx->baseResponse->getFile()->getPathname();
    expect(substr((string) file_get_contents($path), 0, 2))->toBe('PK');

    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();
    $document = $zip->getFromName('word/document.xml');
    expect($document)->toContain('AUTOMATED WEATHER STATION (AWS)')
        ->and($document)->toContain('Export Range PA')
        ->and($document)->toContain('Average Vapor Pressure Deficit');
    $zip->close();
});

test('unified export rejects arbitrary formats and enforces PA authorization', function () {
    $user = rangeDocxAdmin();
    $area = rangeDocxArea($user, 'Protected Export PA');
    rangeDocxAws($area, '2026-01-15');

    $this->actingAs($user)->get(route('aws.summary.export', [
        'mode' => 'month', 'year' => 2026, 'from_month' => 1, 'to_month' => 1,
        'format' => 'html', 'protected_area_id' => $area->id,
    ]))->assertSessionHasErrors('format');

    $other = rangeDocxArea($user, 'Other Protected Export PA');
    $scoped = User::factory()->create([
        'section' => 'PAMO', 'unit_assignment' => 'conservation', 'protected_area_id' => $area->id,
    ]);
    $scoped->assignRole(Role::findOrCreate('PAMO', 'web'));
    $scoped->givePermissionTo(Permission::findOrCreate('aws.view', 'web'));

    $this->actingAs($scoped)->get(route('aws.summary.export', [
        'mode' => 'month', 'year' => 2026, 'from_month' => 1, 'to_month' => 1,
        'format' => 'docx', 'protected_area_id' => $other->id,
    ]))->assertForbidden();
});
