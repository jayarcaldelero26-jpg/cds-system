<?php

use App\Models\BmsRecord;
use App\Models\BamsFlora;
use App\Models\ImeaAssessment;
use App\Models\Aws;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;

function scopedArea(string $name): ProtectedArea
{
    $owner = User::factory()->create();

    return ProtectedArea::create([
        'name' => $name,
        'category' => 'National Park',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function scopedUser(ProtectedArea $area, array $permissions): User
{
    $user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'PAMO',
        'office_designated' => 'PENRO Davao Oriental',
        'protected_area_id' => $area->id,
    ]);
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

function scopedBmsRecord(int $areaId): BmsRecord
{
    return BmsRecord::create([
        'protected_area_id' => $areaId,
        'monitoring_date' => '2026-08-01',
        'taxonomic_group' => 'Bird',
        'species_scientific_name' => 'Testus species',
        'count' => '1',
    ]);
}

test('PAMO BMS access is scoped for reads, writes, and mixed bulk deletes', function (): void {
    $area = scopedArea('Assigned BMS Area');
    $otherArea = scopedArea('Other BMS Area');
    $user = scopedUser($area, ['bms.view', 'bms.create', 'bms.update', 'bms.delete', 'reports.export']);
    $allowed = scopedBmsRecord($area->id);
    $denied = scopedBmsRecord($otherArea->id);

    $this->actingAs($user)->get(route('bms.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bmsRecords', 1)
            ->where('bmsRecords.0.id', $allowed->id)
            ->has('protectedAreas', 1)
            ->where('protectedAreas.0.id', $area->id));

    $this->actingAs($user)->put(route('bms.update', $denied), [
        'protected_area_id' => $otherArea->id,
        'monitoring_date' => '2026-08-01',
        'taxonomic_group' => 'Bird',
        'species_scientific_name' => 'Tampered species',
        'count' => '1',
    ])->assertForbidden();

    $this->actingAs($user)->post(route('bms.store'), [
        'protected_area_id' => $otherArea->id,
        'monitoring_date' => '2026-08-01',
        'taxonomic_group' => 'Bird',
        'species_scientific_name' => 'Tampered species',
        'count' => '1',
    ])->assertForbidden();

    $this->actingAs($user)->post(route('bms.bulk-destroy'), [
        'ids' => [$allowed->id, $denied->id],
    ])->assertForbidden();
    $this->actingAs($user)->get(route('bms.export-pdf', ['protected_area_id' => $otherArea->id]))
        ->assertForbidden();
    expect(BmsRecord::query()->whereKey([$allowed->id, $denied->id])->count())->toBe(2);
});

test('PAMO IMEA reads and facility writes reject another protected area', function (): void {
    $area = scopedArea('Assigned IMEA Area');
    $otherArea = scopedArea('Other IMEA Area');
    $user = scopedUser($area, ['imea.view', 'imea.create', 'imea.update', 'imea.delete', 'imea.export', 'imea.import']);
    $assessment = ImeaAssessment::create([
        'protected_area_id' => $area->id,
        'pamo_name' => 'Assigned PAMO',
        'assessment_year' => 2026,
        'assessment_period' => 'Annual',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    ImeaAssessment::create([
        'protected_area_id' => $otherArea->id,
        'pamo_name' => 'Other PAMO',
        'assessment_year' => 2026,
        'assessment_period' => 'Annual',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('imea.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('assessments.data.0.id', $assessment->id)
            ->has('protectedAreas', 1)
            ->where('protectedAreas.0.id', $area->id));

    $this->actingAs($user)->get(route('imea.report', ['protected_area_id' => $otherArea->id]))
        ->assertForbidden();
    $this->actingAs($user)->get(route('imea.report'))
        ->assertInertia(fn (Assert $page) => $page->has('assessmentsList', 1)->where('assessmentsList.0.id', $assessment->id));

    $this->actingAs($user)->post(route('imea.store'), [
        'protected_area_id' => $otherArea->id,
        'pamo_name' => 'Other PAMO',
        'assessment_year' => 2026,
        'assessment_period' => 'Annual',
    ])->assertForbidden();
});

test('PAMO BAMS writes reject another protected area', function (): void {
    $area = scopedArea('Assigned BAMS Area');
    $otherArea = scopedArea('Other BAMS Area');
    $user = scopedUser($area, ['bams.view', 'bams.create', 'bams.manage-spatial']);

    $flora = [
        'protected_area_id' => $otherArea->id,
        'quadrat_no' => 1,
        'transect_no' => 1,
        'species_code' => 'SP-1',
        'dbh' => 1,
    ];
    $this->actingAs($user)->post(route('bams.flora.store'), $flora)->assertForbidden();

    $this->actingAs($user)->post(route('bams.fauna.store'), [
        'protected_area_id' => $otherArea->id,
        'fauna_type' => 'mammal',
        'species' => 'Test mammal',
    ])->assertForbidden();

    $this->actingAs($user)->post(route('bams.store-spatial'), [
        'protected_area_id' => $otherArea->id,
        'spatial_geojson' => json_encode(['type' => 'Point', 'coordinates' => [126.2, 7.0]]),
    ])->assertForbidden();
});

test('global administrators retain all protected-area access', function (): void {
    $area = scopedArea('Global Area One');
    $otherArea = scopedArea('Global Area Two');
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    foreach (['bms.view', 'bms.create'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    scopedBmsRecord($area->id);
    scopedBmsRecord($otherArea->id);

    $this->actingAs($user)->get(route('bms.index'))
        ->assertInertia(fn (Assert $page) => $page->has('bmsRecords', 2)->has('protectedAreas', 2));
});

test('CENRO AWS scope filters the index and rejects wrong-area direct and mutation access', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $baganga = ProtectedArea::create(['name' => 'AWS Baganga', 'category' => 'National Park', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
    $mati = ProtectedArea::create(['name' => 'AWS Mati', 'category' => 'National Park', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
    $office = OrganizationalOffice::where('code', 'cenro_baganga')->firstOrFail();
    ProtectedAreaOfficeAssignment::insert([
        ['protected_area_id' => $baganga->id, 'organizational_office_id' => $office->id, 'assignment_type' => 'supervising', 'created_at' => now(), 'updated_at' => now()],
        ['protected_area_id' => $mati->id, 'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id'), 'assignment_type' => 'supervising', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $user = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_FOCAL', 'office_designated' => 'CENRO Baganga']);
    foreach (['aws.view', 'aws.create', 'aws.update', 'aws.delete'] as $ability) $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    $make = fn (int $areaId) => Aws::create(['protected_area_id' => $areaId, 'station_name' => 'Station', 'location' => 'Field', 'report_period_type' => 'Monthly', 'document_type' => 'Final Report', 'semester' => '1st Semester', 'status' => 'Active']);
    $visible = $make($baganga->id);
    $hidden = $make($mati->id);

    $this->actingAs($user)->get(route('aws.index'))->assertInertia(fn (Assert $page) => $page
        ->where('awsRecords.total', 1));
    expect($this->actingAs($user)->get(route('aws.report-file.show', $hidden))->status())->toBe(403)
        ->and($this->actingAs($user)->put(route('aws.update', $hidden), [])->status())->toBe(403)
        ->and($this->actingAs($user)->post(route('aws.store'), [
        'protected_area_id' => $mati->id, 'station_name' => 'Spoofed station', 'location' => 'Field',
        'report_period_type' => 'Monthly', 'document_type' => 'Final Report', 'semester' => '1st Semester',
        'status' => 'Active', 'report_file' => UploadedFile::fake()->create('spoof.pdf', 10, 'application/pdf'),
    ])->status())->toBe(403);
});
