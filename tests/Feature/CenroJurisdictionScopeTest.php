<?php

use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function cenroScopeFixture(string $shortName, string $name, string $office, User $owner): ProtectedArea
{
    $area = ProtectedArea::create([
        'name' => $name,
        'short_name' => $shortName,
        'category' => 'National Park',
        'municipality' => $office === 'CENRO Mati' ? 'Mati' : 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::where('name', $office)->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return $area;
}

test('CENRO office scope is jurisdiction-wide while PAMO remains exact PA', function (): void {
    $owner = User::factory()->create();
    $apl = cenroScopeFixture('APL', 'Aliwagwag Protected Landscape', 'CENRO Baganga', $owner);
    $bpl = cenroScopeFixture('BPL', 'BPL Fixture', 'CENRO Baganga', $owner);
    $bmsfr = cenroScopeFixture('BMSFR', 'BMSFR Fixture', 'CENRO Baganga', $owner);
    $mpl = cenroScopeFixture('MPL', 'Mati Protected Landscape', 'CENRO Mati', $owner);
    $pbpls = cenroScopeFixture('PBPLS', 'Pujada Bay Protected Landscape and Seascape', 'CENRO Mati', $owner);
    $mhrws = cenroScopeFixture('MHRWS', 'Mt. Hamiguitan Range Wildlife Sanctuary', 'PENRO Davao Oriental', $owner);

    $baganga = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga', 'protected_area_id' => $apl->id,
    ]);
    $mati = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Mati', 'protected_area_id' => $pbpls->id,
    ]);
    $pamo = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'PAMO',
        'office_designated' => 'PENRO Davao Oriental', 'protected_area_id' => $apl->id,
    ]);

    $organization = app(OrganizationalAccessService::class);
    expect($organization->scopeProtectedAreaQuery(ProtectedArea::query(), $baganga, 'id')->pluck('short_name')->sort()->values()->all())
        ->toBe(['APL', 'BMSFR', 'BPL'])
        ->and($organization->scopeProtectedAreaQuery(ProtectedArea::query(), $mati, 'id')->pluck('short_name')->sort()->values()->all())
        ->toBe(['MPL', 'PBPLS'])
        ->and($organization->scopeProtectedAreaQuery(ProtectedArea::query(), $pamo, 'id')->pluck('short_name')->all())
        ->toBe([])
        ->and($organization->canAccessProtectedArea($pamo, $apl->id))->toBeFalse()
        ->and($organization->canViewSubmissionTracking($pamo))->toBeFalse()
        ->and($organization->canAccessProtectedArea($baganga, $mhrws->id))->toBeFalse()
        ->and($organization->canAccessProtectedArea($mati, $apl->id))->toBeFalse();
});

test('CENRO Focal gets PA preparation gates without restoring Technical Staff', function (): void {
    $focal = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $chief = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_CHIEF',
        'office_designated' => 'CENRO Baganga',
    ]);
    $records = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'CENRO_RECORDS',
        'office_designated' => 'CENRO Baganga',
    ]);

    foreach (['technical-reports.create', 'bms.create', 'bams.create', 'imea.create', 'aws.create', 'management-plans.create'] as $ability) {
        $chief->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($ability, 'web'));
        $records->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($ability, 'web'));
    }

    $organization = app(OrganizationalAccessService::class);
    expect($organization->effectiveCategory($chief))->toBe(OrganizationalAccessService::CENRO_CHIEF)
        ->and($organization->isGlobal($chief))->toBeFalse()
        ->and($organization->canPrepareProtectedAreaSource($chief, 'technical-reports'))->toBeFalse()
        ->and(Gate::forUser($focal)->allows('technical-reports.create'))->toBeTrue()
        ->and(Gate::forUser($focal)->allows('bms.create'))->toBeTrue()
        ->and(Gate::forUser($focal)->allows('bams.create'))->toBeTrue()
        ->and(Gate::forUser($focal)->allows('imea.create'))->toBeTrue()
        ->and(Gate::forUser($focal)->allows('aws.create'))->toBeTrue()
        ->and(Gate::forUser($focal)->allows('management-plans.create'))->toBeTrue()
        ->and($organization->canPrepareProtectedAreaSource($records, 'technical-reports'))->toBeFalse();
});

test('CENRO Focal Homestay and PAMB pages expose preparation authority and scoped PA options', function (): void {
    $owner = User::factory()->create();
    $apl = cenroScopeFixture('APL', 'Aliwagwag Protected Landscape', 'CENRO Baganga', $owner);
    cenroScopeFixture('BPL', 'BPL Fixture', 'CENRO Baganga', $owner);
    cenroScopeFixture('BMSFR', 'BMSFR Fixture', 'CENRO Baganga', $owner);
    cenroScopeFixture('MPL', 'Mati Protected Landscape', 'CENRO Mati', $owner);
    cenroScopeFixture('PBPLS', 'Pujada Bay Protected Landscape and Seascape', 'CENRO Mati', $owner);

    $focal = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga', 'protected_area_id' => $apl->id,
    ]);

    $this->actingAs($focal)->get(route('conservation-reports.index', 'homestay'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.canCreateTechnicalReports', true)
            ->where('protectedAreas', fn ($areas) => collect($areas)->pluck('short_name')->sort()->values()->all() === ['APL', 'BMSFR', 'BPL']));

    $this->actingAs($focal)->get(route('conservation-reports.index', 'regular_pamb'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.canCreateTechnicalReports', true));

    $chief = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_CHIEF', 'office_designated' => 'CENRO Baganga']);
    $chief->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('technical-reports.create', 'web'));
    $this->actingAs($chief)->get(route('conservation-reports.index', 'homestay'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.canCreateTechnicalReports', false));
});

test('CENRO direct create attempts remain office scoped for generic Conservation', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $apl = cenroScopeFixture('APL', 'Aliwagwag Protected Landscape', 'CENRO Baganga', $owner);
    $pbpls = cenroScopeFixture('PBPLS', 'Pujada Bay Protected Landscape and Seascape', 'CENRO Mati', $owner);
    $focal = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga', 'protected_area_id' => $apl->id,
    ]);

    $response = $this->actingAs($focal)->post(route('conservation-reports.store', 'homestay'), [
        'protected_area_id' => $pbpls->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-09-01',
        'mov' => UploadedFile::fake()->create('wrong-office.pdf', 10, 'application/pdf'),
    ]);

    $response->assertForbidden();
    expect(ConservationReportSubmission::query()->where('protected_area_id', $pbpls->id)->count())->toBe(0);

    $chief = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'CENRO_CDS_CHIEF', 'office_designated' => 'CENRO Baganga']);
    $chief->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('technical-reports.create', 'web'));
    $this->actingAs($chief)->post(route('conservation-reports.store', 'homestay'), [
        'protected_area_id' => $apl->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Training on Homestay Program', 'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 1', 'date_accomplished' => '2026-09-01',
        'mov' => UploadedFile::fake()->create('chief.pdf', 10, 'application/pdf'),
    ])->assertForbidden();
});
