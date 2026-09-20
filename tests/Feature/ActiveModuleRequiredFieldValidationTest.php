<?php

use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('CDS Admin', 'web');

    foreach (['protected-areas.create', 'bms.create', 'bams.create'] as $ability) {
        Permission::findOrCreate($ability, 'web');
    }
});

function activeEntryFormUser(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole('CDS Admin');
    $user->givePermissionTo(['protected-areas.create', 'bms.create', 'bams.create']);

    return $user;
}

function activeEntryFormArea(User $user): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'Required Field Audit Area',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

test('active protected-area, BMS, and BAMS entry endpoints reject missing required user fields', function (): void {
    $user = activeEntryFormUser();
    $area = activeEntryFormArea($user);
    $officeId = OrganizationalOffice::query()->value('id');

    expect($officeId)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('protected-areas.store'), [
            'region' => 'Region XI',
            'supervising_office_id' => $officeId,
        ])
        ->assertSessionHasErrors(['name', 'category', 'municipality', 'province', 'status']);

    $this->actingAs($user)
        ->post(route('bms.store'), ['protected_area_id' => $area->id])
        ->assertSessionHasErrors(['monitoring_date', 'taxonomic_group', 'species_scientific_name', 'count']);

    $this->actingAs($user)
        ->post(route('bams.flora.store'), ['protected_area_id' => $area->id])
        ->assertSessionHasErrors(['quadrat_no', 'species_code', 'dbh']);
});
test('active BMS report submissions reject missing Target Office and Protected Area', function (): void {
    $user = activeEntryFormUser();
    $area = activeEntryFormArea($user);

    $payload = [
        'protected_area_id' => $area->id,
        'activity_name' => 'BMS monitoring report',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_conducted_ranges' => [['from' => '2026-08-01', 'to' => '2026-08-01']],
        'date_accomplished' => '2026-08-01',
        'mov' => UploadedFile::fake()->create('bms-report.pdf', 10, 'application/pdf'),
    ];

    $this->actingAs($user)
        ->post(route('bms.report-submissions.store'), $payload)
        ->assertSessionHasErrors('target_office');

    $this->actingAs($user)
        ->post(route('bms.report-submissions.store'), [...$payload, 'target_office' => 'CENRO Mati', 'protected_area_id' => null])
        ->assertSessionHasErrors('protected_area_id');
});
test('active BMS threat entries reject a missing Protected Area', function (): void {
    $user = activeEntryFormUser();

    $this->actingAs($user)
        ->post(route('bms.threats.store'), [
            'date' => '2026-09-20',
            'threat_type' => 'UAT threat',
        ])
        ->assertSessionHasErrors('protected_area_id');
});

test('active Conservation reports reject missing Target Office and Protected Area', function (): void {
    $user = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'office_designated' => 'CENRO Mati']);
    $user->givePermissionTo(Permission::findOrCreate('technical-reports.create', 'web'));
    $area = activeEntryFormArea($user);

    $payload = [
        'activity_name' => 'Homestay required-field audit',
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-09-20',
        'mov' => UploadedFile::fake()->create('homestay-required.pdf', 10, 'application/pdf'),
    ];

    $this->actingAs($user)
        ->post(route('conservation-reports.store', 'homestay'), $payload)
        ->assertSessionHasErrors(['protected_area_id', 'target_office']);

    $this->actingAs($user)
        ->post(route('conservation-reports.store', 'homestay'), [...$payload, 'protected_area_id' => $area->id])
        ->assertSessionHasErrors('target_office');

    $this->actingAs($user)
        ->post(route('conservation-reports.store', 'homestay'), [...$payload, 'target_office' => 'CENRO Mati'])
        ->assertSessionHasErrors('protected_area_id');
});