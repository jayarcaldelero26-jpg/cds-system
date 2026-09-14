<?php
use App\Models\Aws;
use App\Models\BamsFlora;
use App\Models\BmsRecord;
use App\Models\ImeaAssessment;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Role::findOrCreate('no_role', 'web');
});

function conservationNavigationUser(string $category, string $office = 'CENRO Baganga', array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'is_active' => true,
        'unit_assignment' => 'conservation',
        'section' => $category,
        'office_designated' => $office,
        'protected_area_id' => null,
    ], $overrides));
    $user->assignRole('no_role');
    return $user;
}

function conservationNavigationArea(User $owner, string $name = 'Aliwagwag Protected Landscape (APL)', string $office = 'CENRO Baganga'): ProtectedArea
{
    $area = ProtectedArea::create([
        'name' => $name,
        'short_name' => str_contains($name, 'MHRWS') ? 'MHRWS' : 'APL',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
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

test('valid conservation categories receive scoped read navigation without CRUD permissions', function (string $category, string $office) {
    $user = conservationNavigationUser($category, $office);
    if ($category === 'PAMO') {
        $area = conservationNavigationArea($user, 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)', 'PENRO Davao Oriental');
        $user->update(['protected_area_id' => $area->id]);
    }

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.canViewProtectedAreas', true)
        ->where('auth.canViewManagementPlans', true)
        ->where('auth.canViewTechnicalReports', true)
        ->where('auth.canViewBms', true)
        ->where('auth.canViewBams', true)
        ->where('auth.canViewAws', true)
        ->where('auth.canViewImea', true)
        ->where('auth.canManageUsers', false));

    expect($user->fresh()->getRoleNames()->all())->toBe(['no_role'])
        ->and($user->fresh()->getAllPermissions())->toHaveCount(0);
})->with([
    ['CENRO_CDS_FOCAL', 'CENRO Baganga'],
    ['CENRO_CDS_CHIEF', 'CENRO Baganga'],
    ['PENRO_CDS_FOCAL', 'PENRO Davao Oriental'],
    ['PENRO_CDS_CHIEF', 'PENRO Davao Oriental'],
]);

test('real conservation child routes open while CRUD and administration remain denied', function () {
    $user = conservationNavigationUser('CENRO_CDS_CHIEF');
    $this->actingAs($user);

    foreach ([
        '/protected-areas', '/management-plans', '/conservation-reports/regular_pamb',
        '/ipaf?ipaf_tab=management', '/bms?tracker=1', '/bams/report-submissions',
        '/imea/report-submissions', '/aws',
    ] as $path) {
        $this->get($path)->assertOk();
    }

    $this->get('/admin/users')->assertForbidden();
    $this->get('/protected-areas/create')->assertForbidden();
    $this->post('/conservation-reports/regular_pamb', [])->assertForbidden();
    $this->post('/bms', [])->assertForbidden();
    $this->post('/bams/flora', [])->assertForbidden();
    $this->post('/imea', [])->assertForbidden();
    $this->post('/aws', [])->assertForbidden();});

test('legacy PAMO accounts cannot access conservation modules', function () {
    $user = conservationNavigationUser('PAMO', 'PENRO Davao Oriental');

    $this->actingAs($user)->get('/protected-areas')->assertForbidden();
    $this->post('/conservation-reports/regular_pamb', [])->assertForbidden();
});

test('Super Admin retains global conservation navigation and administration', function () {
    $admin = User::factory()->create(['is_active' => true, 'unit_assignment' => null, 'section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.canViewProtectedAreas', true)
        ->where('auth.canViewManagementPlans', true)
        ->where('auth.canViewTechnicalReports', true)
        ->where('auth.canManageUsers', true));
    $this->get('/admin/users')->assertOk();
});

test('sidebar filters unauthorized children before hiding empty parents', function () {
    $source = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($source)
        ->toContain('if (item.permission && !auth[item.permission]) return null;')
        ->toContain('if (item.children && !children?.length) return null;')
        ->toContain('safeAuth,');
});
test('scoped conservation read access returns only authorized specialized records', function (): void {
    $user = conservationNavigationUser('CENRO_CDS_CHIEF');
    $allowedArea = conservationNavigationArea($user, 'Allowed APL', 'CENRO Baganga');
    $otherArea = conservationNavigationArea($user, 'Other CENRO Area', 'CENRO Mati');

    $allowedBms = BmsRecord::create([
        'protected_area_id' => $allowedArea->id,
        'monitoring_date' => '2026-08-01',
        'taxonomic_group' => 'Bird',
        'species_scientific_name' => 'Allowed species',
        'count' => '1',
    ]);
    BmsRecord::create([
        'protected_area_id' => $otherArea->id,
        'monitoring_date' => '2026-08-01',
        'taxonomic_group' => 'Bird',
        'species_scientific_name' => 'Other species',
        'count' => '1',
    ]);

    $allowedBams = BamsFlora::create([
        'protected_area_id' => $allowedArea->id,
        'quadrat_no' => 1,
        'transect_no' => 1,
        'species_code' => 'ALLOWED-1',
        'dbh' => 1,
    ]);
    BamsFlora::create([
        'protected_area_id' => $otherArea->id,
        'quadrat_no' => 1,
        'transect_no' => 1,
        'species_code' => 'OTHER-1',
        'dbh' => 1,
    ]);

    $allowedImea = ImeaAssessment::create([
        'protected_area_id' => $allowedArea->id,
        'pamo_name' => 'Allowed PAMO',
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

    $allowedAws = Aws::create([
        'protected_area_id' => $allowedArea->id,
        'station_name' => 'Allowed Station',
        'location' => 'Field',
        'report_period_type' => 'Monthly',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'status' => 'Active',
    ]);
    Aws::create([
        'protected_area_id' => $otherArea->id,
        'station_name' => 'Other Station',
        'location' => 'Field',
        'report_period_type' => 'Monthly',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'status' => 'Active',
    ]);

    $this->actingAs($user)->get('/bms?tracker=1')->assertInertia(fn (Assert $page) => $page
        ->has('bmsRecords', 1)
        ->where('bmsRecords.0.id', $allowedBms->id));
    $this->get('/bms?protected_area_id='.$otherArea->id)->assertForbidden();

    $this->get('/bams')->assertInertia(fn (Assert $page) => $page
        ->has('bamsRecords', 1)
        ->where('bamsRecords.0.id', $allowedBams->id));
    $this->get('/bams?protected_area_id='.$otherArea->id)->assertForbidden();

    $this->get('/imea')->assertInertia(fn (Assert $page) => $page
        ->where('assessments.total', 1)
        ->where('assessments.data.0.id', $allowedImea->id));
    $this->get('/imea?protected_area_id='.$otherArea->id)->assertForbidden();

    $this->get('/aws')->assertInertia(fn (Assert $page) => $page
        ->where('awsRecords.total', 1)
        ->where('awsRecords.data.0.id', $allowedAws->id));
    $this->get('/aws?protected_area_id='.$otherArea->id)->assertInertia(fn (Assert $page) => $page
        ->where('awsRecords.total', 0));
});