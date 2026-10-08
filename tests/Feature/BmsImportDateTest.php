<?php

use App\Models\BmsRecord;
use App\Models\BmsAnnexHeader;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function bmsImportDateActor(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('bms.create', 'web'));

    return $user;
}

function bmsImportDateArea(User $user): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'BMS Import Date Test PA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

function bmsImportCsv(array $rows): UploadedFile
{
    $content = "Station,Category,Common Name,Scientific Name,Count,Date\n";
    foreach ($rows as $row) $content .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row))."\n";

    return UploadedFile::fake()->createWithContent('bms-monitoring.csv', $content);
}

test('BMS import preserves supported explicit text date formats and imports valid neighboring rows', function (): void {
    \Carbon\CarbonImmutable::setTestNow('2026-09-26 12:00:00');
    $user = bmsImportDateActor();
    $area = bmsImportDateArea($user);
    $rows = [
        ['North', 'Birds', '', 'BmsDate.Species1', 1, '2026-08-01'],
        ['North', 'Birds', '', 'BmsDate.Species2', 2, '23/08/2026'],
        ['North', 'Birds', '', 'BmsDate.Species3', 3, '08/24/2026'],
        ['North', 'Birds', '', 'BmsDate.Species4', 4, '25-08-2026'],
        ['North', 'Birds', '', 'BmsDate.Species5', 5, '2026/08/26'],
        ['North', 'Birds', '', 'BmsDate.Species6', 6, '08-27-2026'],
        ['North', 'Birds', '', 'BmsDate.Species7', 7, '2026-08-28 14:35'],
        ['North', 'Birds', '', 'BmsDate.Missing', 8, ''],
        ['North', 'Birds', '', 'BmsDate.Invalid', 9, 'not-a-date'],
    ];

    $response = $this->actingAs($user)->post(route('bms.import'), [
        'protected_area_id' => $area->id,
        'file' => bmsImportCsv($rows),
    ])->assertRedirect()->assertSessionHas('success');

    $imported = BmsRecord::query()->where('protected_area_id', $area->id)->orderBy('species_scientific_name')->get();
    expect($imported)->toHaveCount(7)
        ->and($imported->pluck('monitoring_date')->map(fn ($date) => substr((string) $date, 0, 10))->sort()->values()->all())
        ->toBe(['2026-08-01', '2026-08-23', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28'])
        ->and(session('success'))->toContain('2 row(s) skipped because the monitoring date is missing or invalid')
        ->and(session('success'))->toContain('Data rows: 8, 9.');
});

test('BMS import rejects all missing or invalid dates without substituting today', function (): void {
    \Carbon\CarbonImmutable::setTestNow('2026-09-26 12:00:00');
    $user = bmsImportDateActor();
    $area = bmsImportDateArea($user);

    $this->actingAs($user)->post(route('bms.import'), [
        'protected_area_id' => $area->id,
        'file' => bmsImportCsv([
            ['North', 'Birds', '', 'BmsDate.MissingOnly', 1, ''],
            ['North', 'Birds', '', 'BmsDate.InvalidOnly', 1, '31/02/2026'],
        ]),
    ])->assertRedirect()->assertSessionHasErrors('file');

    expect(BmsRecord::query()->where('protected_area_id', $area->id)->count())->toBe(0);
});

test('BMS Annex header rejects a future Date Conducted before creating metadata', function (): void {
    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $user = bmsImportDateActor();
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
    $area = bmsImportDateArea($user);

    $this->actingAs($user)->post(route('bms.bulk-update-header'), [
        'protected_area_id' => $area->id,
        'category' => 'Birds',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'date_conducted' => '2026-10-09',
    ])->assertSessionHasErrors('date_conducted');

    expect(BmsAnnexHeader::query()->count())->toBe(0);
});

test('BMS Annex header can retain an unchanged legacy future Date Conducted during another edit', function (): void {
    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $user = bmsImportDateActor();
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
    $area = bmsImportDateArea($user);
    $identity = ['protected_area_id' => $area->id, 'category' => 'Birds', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31'];
    $header = BmsAnnexHeader::query()->create([...$identity, 'date_conducted' => '2026-10-22']);

    $this->actingAs($user)->post(route('bms.bulk-update-header'), [
        ...$identity,
        'date_conducted' => '2026-10-22',
        'location' => 'Synthetic location correction',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($header->fresh()->date_conducted)->toBe('2026-10-22')
        ->and($header->fresh()->location)->toBe('Synthetic location correction')
        ->and(BmsAnnexHeader::query()->count())->toBe(1);
});
