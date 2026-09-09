<?php

use App\Models\Aws;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\AwsMonthlySummaryService;
use App\Services\AwsWeatherConditionService;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

function weatherAuditAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    return $user;
}

function weatherAuditArea(User $owner, string $name = 'Weather Audit PA'): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name,
        'short_name' => 'WAP',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function weatherAuditDaily(ProtectedArea $area, string $date, array $values = []): Aws
{
    $row = Aws::create(array_merge([
        'protected_area_id' => $area->id,
        'station_name' => 'Weather Audit AWS',
        'location' => 'Test location',
        'report_period_type' => 'Daily',
        'status' => 'Active',
        'timestamps' => date('F j, Y', strtotime($date)),
        'start_date' => $date,
        'end_date' => $date,
        'observation_count' => 96,
        'air_temperature' => 25,
        'relative_humidity' => 80,
        'atmospheric_pressure' => 100,
        'wind_direction' => 90,
        'wind_speed' => 2,
        'precipitation' => 0,
    ], $values));
    $row->observation_count = $values['observation_count'] ?? 96;
    $row->save();

    return $row;
}

test('daily weather remarks use the existing importer rule and do not use completeness labels', function () {
    $service = app(AwsWeatherConditionService::class);

    expect($service->classifyDaily([
        'precipitation' => 0, 'wind_speed' => 2, 'air_temperature' => 25,
    ]))->toBe('Normal Weather Conditions')
        ->and($service->classifyDaily([
            'precipitation' => 16, 'wind_speed' => 11, 'air_temperature' => 33,
        ]))->toBe("Moderate Rain Observed | Strong Wind Alert (11 m/s) | High Temperature (33\u{00B0}C)")
        ->and($service->classifyDaily([
            'precipitation' => null, 'wind_speed' => null, 'air_temperature' => null,
        ]))->toBe('Weather Condition Unavailable');
});

test('custom range summarizes every included daily condition and excludes dates outside the range', function () {
    $user = weatherAuditAdmin();
    $area = weatherAuditArea($user);
    weatherAuditDaily($area, '2026-01-14', ['precipitation' => 60]);
    weatherAuditDaily($area, '2026-01-15', ['precipitation' => 0]);
    weatherAuditDaily($area, '2026-01-16', ['precipitation' => 20]);
    weatherAuditDaily($area, '2026-01-17', [
        'precipitation' => null, 'wind_speed' => null, 'air_temperature' => null,
    ]);

    $service = app(AwsMonthlySummaryService::class);
    $partial = $service->summarizePeriod($user, 'custom_range', [
        'date_from' => '2026-01-15', 'date_to' => '2026-01-17',
    ], $area->id)->sole();

    expect($partial['remarks'])->toBe('Moderate Rain Observed')
        ->and($partial['total_precipitation'])->toBe(20.0)
        ->and($partial['data_completeness'])->toBe(100.0);

    $outsideDate = $service->summarizePeriod($user, 'custom_range', [
        'date_from' => '2026-01-15', 'date_to' => '2026-01-15',
    ], $area->id)->sole();

    expect($outsideDate['remarks'])->toBe('Normal Weather Conditions')
        ->and($outsideDate['total_precipitation'])->toBe(0.0);
});

test('monthly weather remarks use deterministic severity without distribution text', function () {
    $service = app(AwsWeatherConditionService::class);

    expect($service->summarizeMonthly(array_merge(array_fill(0, 30, 'Normal Weather Conditions'), ['Moderate Rain Observed'])))
        ->toBe('Moderate Rain Observed')
        ->and($service->summarizeMonthly(array_merge(
            array_fill(0, 22, 'Normal Weather Conditions'),
            array_fill(0, 5, 'Moderate Rain Observed'),
            ['Heavy Rainfall Advisory (60mm)'],
        )))->toBe('Heavy Rainfall Advisory')
        ->and($service->summarizeMonthly(['Normal Weather Conditions']))
        ->toBe('Normal Weather Conditions')
        ->and($service->summarizeMonthly([]))
        ->toBe('Weather Condition Unavailable')
        ->and($service->summarizeMonthly(['Weather Condition Unavailable']))
        ->toBe('Weather Condition Unavailable');
});

test('no-data month keeps technical completeness separate from unavailable weather remarks', function () {
    $user = weatherAuditAdmin();
    $area = weatherAuditArea($user, 'No Data Weather Audit PA');
    $service = app(AwsMonthlySummaryService::class);

    $empty = $service->summarizePeriod($user, 'custom_range', [
        'date_from' => '2026-02-01', 'date_to' => '2026-02-28',
    ], $area->id)->sole();

    expect($empty['observation_count'])->toBe(0)
        ->and($empty['data_completeness'])->toBe(0.0)
        ->and($empty['remarks'])->toBe('No Data');

    weatherAuditDaily($area, '2026-03-01', [
        'observation_count' => 1,
        'precipitation' => null,
        'wind_speed' => null,
        'air_temperature' => null,
    ]);
    $partial = $service->summarizePeriod($user, 'custom_range', [
        'date_from' => '2026-03-01', 'date_to' => '2026-03-01',
    ], $area->id)->sole();

    expect($partial['data_completeness'])->toBe(1.0)
        ->and($partial['remarks'])->toBe('Weather Condition Unavailable');
});

test('unified index scopes summary, analytics records, and source records to the same period and protected area', function () {
    $user = weatherAuditAdmin();
    $area = weatherAuditArea($user, 'Unified Scope Weather Audit PA');
    $otherArea = weatherAuditArea($user, 'Other Scope Weather Audit PA');
    weatherAuditDaily($area, '2026-06-14', ['precipitation' => 60]);
    weatherAuditDaily($area, '2026-06-15', ['precipitation' => 20]);
    weatherAuditDaily($otherArea, '2026-06-15', ['precipitation' => 60]);

    $this->actingAs($user)->get(route('aws.index', [
        'mode' => 'custom_range', 'date_from' => '2026-06-15', 'date_to' => '2026-06-15',
        'protected_area_id' => $area->id,
    ]))->assertInertia(fn ($assert) => $assert
        ->where('monthlySummary.0.period', 'June 15–15, 2026')
        ->where('monthlySummary.0.remarks', 'Moderate Rain Observed')
        ->where('chartRecords', fn ($records): bool => count($records) === 1 && (int) $records[0]['protected_area_id'] === $area->id)
        ->where('rawRecords.data', fn ($records): bool => count($records) === 1 && (int) $records[0]['protected_area_id'] === $area->id));
});

test('import preserves the first row, date coverage, zero precipitation, and missing precipitation', function () {
    $user = weatherAuditAdmin();
    $area = weatherAuditArea($user, 'Import Weather Audit PA');
    $csv = implode("\n", [
        'Timestamps,mm precipitation,Wind Speed (m/s),Air Temperature (°C),Relative Humidity (%),Atmospheric Pressure (kPa),Wind Direction (°)',
        '2026-04-01 00:00:00,0,2,25,80,100,90',
        '2026-04-02 00:00:00,,2,25,80,100,90',
    ]);

    $this->actingAs($user)->post(route('aws.import'), [
        'protected_area_id' => $area->id,
        'file' => UploadedFile::fake()->createWithContent('weather.csv', $csv),
    ])->assertRedirect();

    $rows = Aws::query()->where('protected_area_id', $area->id)->orderBy('start_date')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->start_date->toDateString())->toBe('2026-04-01')
        ->and((float) $rows[0]->precipitation)->toBe(0.0)
        ->and($rows[0]->remarks)->toBe('Normal Weather Conditions')
        ->and($rows[1]->precipitation)->toBeNull()
        ->and($rows[1]->remarks)->toBe('Normal Weather Conditions');
});

test('aws import rejects unsupported files and missing timestamp headers', function () {
    $user = weatherAuditAdmin();
    $area = weatherAuditArea($user, 'Import Validation Weather Audit PA');

    $this->actingAs($user)->post(route('aws.import'), [
        'protected_area_id' => $area->id,
        'file' => UploadedFile::fake()->createWithContent('weather.json', '{}'),
    ])->assertSessionHasErrors('file');

    $this->actingAs($user)->post(route('aws.import'), [
        'protected_area_id' => $area->id,
        'file' => UploadedFile::fake()->createWithContent('weather.csv', "Temperature,Humidity\n25,80\n"),
    ])->assertSessionHasErrors('file');
});

test('screen and all export formats receive the same generated weather remark', function () {
    $user = weatherAuditAdmin();
    $area = weatherAuditArea($user, 'Parity Weather Audit PA');
    weatherAuditDaily($area, '2026-05-01', ['precipitation' => 20]);
    $query = [
        'mode' => 'custom_range', 'date_from' => '2026-05-01', 'date_to' => '2026-05-01',
        'protected_area_id' => $area->id,
    ];
    $remark = 'Moderate Rain Observed';

    $this->actingAs($user)->get(route('aws.index', $query + ['tab' => 'monthly-summary']))
        ->assertInertia(fn ($assert) => $assert->where('monthlySummary.0.remarks', $remark));

    $pdf = $this->actingAs($user)->get(route('aws.summary.export', $query + ['format' => 'pdf']));
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf');

    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('The CLI PHP zip extension is unavailable for XLSX/DOCX parity inspection.');
    }

    foreach (['xlsx' => 'xl/worksheets/sheet1.xml', 'docx' => 'word/document.xml'] as $format => $entry) {
        $response = $this->actingAs($user)->get(route('aws.summary.export', $query + ['format' => $format]));
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        expect($zip->open($path))->toBeTrue();
        expect($zip->getFromName($entry))->toContain($remark);
        $zip->close();
    }
});

test('aws uses only the four configured active protected areas', function () {
    $user = weatherAuditAdmin();
    $activeCodes = config('aws.active_protected_area_codes');
    $activeNames = [
        'APL' => 'Aliwagwag Protected Landscape',
        'MHRWS' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'MPL' => 'Mati Protected Landscape',
        'PBPLS' => 'Pujada Bay Protected Landscape and Seascape',
    ];
    $activeAreas = collect($activeCodes)->mapWithKeys(function (string $code) use ($activeNames, $user): array {
        $area = weatherAuditArea($user, $activeNames[$code]);
        $area->update(['short_name' => $code]);
        return [$code => $area->fresh()];
    });
    $excludedBmsfr = weatherAuditArea($user, 'Baganga Mangrove Swamp Forest Reserve');
    $excludedBmsfr->update(['short_name' => 'BMSFR']);
    $excludedBpl = weatherAuditArea($user, 'Baganga Protected Landscape');
    $excludedBpl->update(['short_name' => 'BPL']);

    foreach ($activeAreas as $area) {
        weatherAuditDaily($area, '2026-08-01');
    }
    weatherAuditDaily($excludedBmsfr, '2026-08-01');
    weatherAuditDaily($excludedBpl, '2026-08-01');

    $this->actingAs($user)->get(route('aws.index', [
        'mode' => 'one_month',
        'year' => 2026,
        'month' => 8,
    ]))->assertInertia(fn ($assert) => $assert
        ->where('protectedAreas', fn ($areas): bool => collect($areas)->pluck('short_name')->sort()->values()->all() === collect($activeCodes)->sort()->values()->all())
        ->where('monthlySummary', fn ($rows): bool => collect($rows)->pluck('protected_area_name')->unique()->sort()->values()->all() === collect($activeNames)->sort()->values()->all())
        ->where('monthlySummary', fn ($rows): bool => ! collect($rows)->pluck('protected_area_name')->contains('Baganga Mangrove Swamp Forest Reserve')
            && ! collect($rows)->pluck('protected_area_name')->contains('Baganga Protected Landscape'))
        ->where('rawRecords.data', fn ($rows): bool => collect($rows)->every(fn (array $row): bool => in_array($row['protected_area']['short_name'] ?? null, $activeCodes, true))));

    $this->actingAs($user)->post(route('aws.import'), [
        'protected_area_id' => $excludedBpl->id,
        'file' => UploadedFile::fake()->createWithContent('weather.csv', "Timestamps,mm precipitation\n2026-08-02 00:00:00,0\n"),
    ])->assertForbidden();
});
