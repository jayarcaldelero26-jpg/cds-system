<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('no_role', 'web');
});

function organizationalUser(string $role, string $unit, string $office = 'CENRO Baganga', array $extra = []): User
{
    $permissions = ['reports.view', 'technical-reports.view', 'technical-reports.create', 'technical-reports.update'];
    foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
    $spatieRole = Role::findOrCreate($role, 'web');
    $spatieRole->syncPermissions($permissions);

    $user = User::factory()->create(array_merge([
        'unit_assignment' => $unit,
        'section' => $role === 'PAMO' ? 'PAMO' : ($role === 'Development User' ? 'ENGP' : 'CENRO_CDS_FOCAL'),
        'office_designated' => $office,
    ], $extra));
    $user->assignRole($spatieRole);
    return $user;
}

function organizationalArea(User $owner, string $name = 'Aliwagwag Protected Landscape'): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name, 'category' => 'National Park', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

test('registration exposes only organizational request options and no super admin', function () {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('registrationOptions.units', 2)
            ->where('registrationOptions.units.0.value', 'conservation')
            ->where('registrationOptions.units.1.value', 'development')
            ->where('registrationOptions.categories.conservation.5.value', 'PAMO')
            ->missing('registrationOptions.categories.development.5'));
});

test('public registration stores unit and PA request without granting the requested role', function () {
    $owner = User::factory()->create();
    $area = organizationalArea($owner);

    $this->post('/register', [
        'name' => 'PAMO Applicant', 'email' => 'pamo-applicant@example.com',
        'unit_assignment' => 'conservation', 'section' => 'PAMO',
        'office_designated' => 'PENRO Davao Oriental', 'protected_area_id' => $area->id,
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('login'));

    $applicant = User::where('email', 'pamo-applicant@example.com')->firstOrFail();
    expect($applicant->unit_assignment)->toBe('conservation')
        ->and($applicant->protected_area_id)->toBe($area->id)
        ->and($applicant->is_active)->toBeFalse()
        ->and($applicant->roles()->pluck('name')->all())->toBe(['no_role']);
});

test('invalid development PAMO request and PA scope are rejected', function () {
    $this->post('/register', [
        'name' => 'Invalid Applicant', 'email' => 'invalid-org@example.com',
        'unit_assignment' => 'development', 'section' => 'PAMO',
        'office_designated' => 'CENRO Baganga',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasErrors('section');
});

test('explicit conservation and development users are isolated at the route boundary', function () {
    $conservation = organizationalUser('CENRO CDS Focal Person', 'conservation');
    $development = organizationalUser('CENRO CDS Focal Person', 'development');

    $this->actingAs($conservation)->get('/engp-reports/summary')->assertForbidden();
    $this->actingAs($development)->get('/conservation-reports/regular_pamb')->assertForbidden();

    $this->actingAs($conservation)->get('/conservation-reports/regular_pamb')->assertOk();
    $this->actingAs($development)->get('/engp-reports/summary')->assertOk();
});

test('a CENRO Chief with a repaired organizational tuple can reach the Regular PAMB module', function () {
    $chief = organizationalUser('CENRO CDS Chief', 'conservation', 'CENRO Baganga', [
        'section' => 'CENRO_CDS_CHIEF',
        'protected_area_id' => null,
    ]);

    $this->actingAs($chief)->get('/conservation-reports/regular_pamb')->assertOk();
    expect(app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class)->isCenro($chief))->toBeTrue()
        ->and(app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class)->isPamo($chief))->toBeFalse();
});

test('PAMB tracking and ENGP records are filtered by the assigned office', function () {
    $baganga = organizationalUser('CENRO CDS Focal Person', 'conservation', 'CENRO Baganga');
    $area = organizationalArea($baganga);
    $otherArea = organizationalArea($baganga, 'Mount Hamiguitan Range Wildlife Sanctuary');

    ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Report', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-20',
    ]);
    ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $otherArea->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Report', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-20',
    ]);

    $this->actingAs($baganga)->get('/submission-tracking')->assertInertia(fn (Assert $page) => $page
        ->has('queues.for_submission', 1));

    $development = organizationalUser('CENRO CDS Focal Person', 'development', 'CENRO Baganga');
    EngpReportSubmission::create([
        'workflow_key' => 'cbep', 'office' => 'CENRO Baganga', 'activity_name' => 'CBEP', 'document_type' => 'Monthly Report',
        'reporting_year' => 2026, 'period_key' => '2026-01', 'period_label' => 'January 2026', 'deadline_submission' => '2026-01-20',
    ]);
    EngpReportSubmission::create([
        'workflow_key' => 'cbep', 'office' => 'CENRO Mati', 'activity_name' => 'CBEP', 'document_type' => 'Monthly Report',
        'reporting_year' => 2026, 'period_key' => '2026-02', 'period_label' => 'February 2026', 'deadline_submission' => '2026-02-20',
    ]);

    $this->actingAs($development)->get('/engp-reports/cbep')->assertInertia(fn (Assert $page) => $page
        ->where('submissions.total', 1)
        ->where('submissions.data.0.office', 'CENRO Baganga'));
});

test('PAMO record and attachment access stay within the assigned protected area', function () {
    $pamo = organizationalUser('PAMO', 'conservation', 'PENRO Davao Oriental');
    $area = organizationalArea($pamo);
    $otherArea = organizationalArea($pamo, 'Other Protected Area');
    $allowed = ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental', 'date_conducted' => '2026-08-20', 'mov_file_path' => 'missing.pdf']);
    Storage::disk('local')->put('conservation-report-movs/denied.pdf', 'protected test fixture');
    $denied = ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'protected_area_id' => $otherArea->id, 'target_office' => 'PENRO Davao Oriental', 'date_conducted' => '2026-08-20', 'mov_file_path' => 'conservation-report-movs/denied.pdf']);

    $this->actingAs($pamo)->get(route('attachments.show', ['source' => 'conservation-report', 'record' => $denied->id, 'attachment' => 'mov']))->assertForbidden();
    expect(app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class)->canView($pamo, $denied))->toBeFalse();
});

test('PAMO cannot access compliance alert management or internal PENRO routing', function () {
    $pamo = organizationalUser('PAMO', 'conservation', 'PENRO Davao Oriental');
    $area = organizationalArea($pamo);
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Report', 'date_conducted' => '2026-08-20',
    ]);

    $this->actingAs($pamo)->get('/compliance-alerts')->assertForbidden();
    $this->actingAs($pamo)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, 'records_to_penro']), [
        'stage' => 'records_to_penro', 'remarks' => 'Attempted by PAMO',
    ])->assertForbidden();
});

test('super admin retains cross-unit visibility and administration', function () {
    foreach (['reports.view', 'technical-reports.view'] as $permission) Permission::findOrCreate($permission, 'web');
    $role = Role::findOrCreate('Super Admin', 'web');
    $role->syncPermissions(Permission::all());
    $admin = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $admin->assignRole($role);

    $this->actingAs($admin)->get('/engp-reports/summary')->assertOk();
    $this->actingAs($admin)->get('/conservation-reports/regular_pamb')->assertOk();
    $this->actingAs($admin)->get('/admin/users')->assertOk();
});
