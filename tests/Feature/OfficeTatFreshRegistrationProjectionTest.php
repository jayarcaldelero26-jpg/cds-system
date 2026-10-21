<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\OfficeTatComplianceAssessmentService;
use App\Services\Reports\ReportRequirementRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Storage::fake('local');
    $this->seed(ModuleDefinitionSeeder::class);
    Role::findOrCreate('no_role', 'web');
    Role::findOrCreate('Super Admin', 'web');
    freshOfficeTatEnsureOffice('CENRO Baganga', 'cenro_baganga', 'cenro');
    freshOfficeTatEnsureOffice('CENRO Manay', 'cenro_manay', 'cenro');
    freshOfficeTatEnsureOffice('PENRO Davao Oriental', 'penro_davao_oriental', 'penro');
});

function freshOfficeTatEnsureOffice(string $name, string $code, string $type): OrganizationalOffice
{
    $office = OrganizationalOffice::query()->where('name', $name)->first();
    if (! $office) {
        return OrganizationalOffice::query()->create([
            'code' => $code, 'name' => $name, 'office_type' => $type, 'is_active' => true,
        ]);
    }

    $office->forceFill(['code' => $code, 'office_type' => $type, 'is_active' => true])->save();
    return $office->fresh();
}

function freshOfficeTatAdmin(): User
{
    $admin = User::factory()->create([
        'is_approved' => true, 'is_active' => true, 'section' => 'CDS',
        'office_designated' => 'PENRO Davao Oriental', 'unit_assignment' => null,
    ]);
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    return $admin;
}

/** @return array{0:ProtectedArea,1:OrganizationalOffice} */
function freshOfficeTatArea(User $admin, string $supervisingOffice = 'CENRO Baganga'): array
{
    $areaName = 'Fresh Office TAT Participant '.Str::uuid();
    $area = ProtectedArea::query()->create([
        'name' => $areaName, 'short_name' => strtoupper(substr((string) Str::uuid(), 0, 8)),
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'Region XI', 'status' => 'Active',
    ]);
    $supervisor = OrganizationalOffice::query()->where('name', $supervisingOffice)->firstOrFail();
    $organization = app(OrganizationalAccessService::class);
    $organization->assignSupervisingOffice($area, (int) $supervisor->getKey(), $admin);
    $pamo = $organization->assignPamoOfficeByName($area, 'PAMO Fresh '.substr(str_replace('-', '', (string) Str::uuid()), 0, 10), $admin);
    return [$area->fresh(), $pamo];
}

function freshOfficeTatRegisterAndApprove($test, User $admin, string $section, ?int $protectedAreaId = null, ?string $office = null, bool $approve = true): User
{
    $test->post('/logout');
    $group = $section === 'PAMO' ? 'pamo' : (str_starts_with($section, 'PENRO_') || $section === 'OFFICE_OF_THE_PENRO' ? 'penro' : 'cenro');
    $email = 'fresh-'.str_replace('-', '', (string) Str::uuid()).'@example.test';
    $payload = [
        'name' => 'Fresh Registration '.$section,
        'email' => $email,
        'operational_group' => $group,
        'section' => $section,
        'password' => 'Passw0rd!123',
        'password_confirmation' => 'Passw0rd!123',
    ];
    if ($group === 'pamo') $payload['protected_area_id'] = $protectedAreaId;
    else $payload['office_designated'] = $office ?? ($group === 'penro' ? 'PENRO Davao Oriental' : 'CENRO Baganga');

    $test->post('/register', $payload)->assertStatus(302)->assertSessionHasNoErrors();
    $user = User::query()->where('email', $email)->firstOrFail();
    expect($user->is_approved)->toBeFalse()
        ->and($user->is_active)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->getRoleNames()->all())->toBe(['no_role']);

    if (! $approve) return $user;
    $test->actingAs($admin)->patch(route('admin.users.approve', $user))
        ->assertStatus(302)->assertSessionHasNoErrors();
    return $user->fresh();
}

function freshOfficeTatBms(ProtectedArea $area, int $creatorId, string $label): BmsReportSubmission
{
    $path = 'bms-report-movs/fresh-registration-'.$label.'-'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nFresh isolated attachment");
    return BmsReportSubmission::query()->create([
        'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Baganga',
        'activity_name' => $label, 'document_type' => 'Final Report', 'semester' => '2nd Semester',
        'date_conducted' => '2026-10-05', 'date_accomplished' => '2026-10-05',
        'mov_file_name' => $label.'.pdf', 'mov_file_path' => $path, 'created_by' => $creatorId,
    ]);
}

test('new PAMO registration remains pending, gets its assigned scope after approval, and loses that scope after an admin reassignment', function (): void {
    $admin = freshOfficeTatAdmin();
    [$areaA] = freshOfficeTatArea($admin, 'CENRO Baganga');
    [$areaB] = freshOfficeTatArea($admin, 'CENRO Manay');

    $invalidEmail = 'invalid-pamo-'.str_replace('-', '', (string) Str::uuid()).'@example.test';
    $this->post('/register', [
        'name' => 'Invalid Fresh PAMO', 'email' => $invalidEmail, 'operational_group' => 'pamo',
        'section' => 'PAMO', 'password' => 'Passw0rd!123', 'password_confirmation' => 'Passw0rd!123',
    ])->assertSessionHasErrors('protected_area_id');
    $this->assertDatabaseMissing('users', ['email' => $invalidEmail]);

    $reportA = freshOfficeTatBms($areaA, $admin->id, 'Fresh PA A');
    $reportB = freshOfficeTatBms($areaB, $admin->id, 'Fresh PA B');
    $pending = freshOfficeTatRegisterAndApprove($this, $admin, 'PAMO', $areaA->id, null, false);
    expect($pending->is_approved)->toBeFalse()->and($pending->is_active)->toBeFalse();
    $this->actingAs($pending)->get('/submission-tracking')->assertStatus(302);
    $this->actingAs($admin)->patch(route('admin.users.approve', $pending))
        ->assertStatus(302)->assertSessionHasNoErrors();
    $this->post('/logout');
    $pending = $pending->fresh();
    expect($pending->is_approved)->toBeTrue()->and($pending->is_active)->toBeTrue();
    expect($pending->getAllPermissions())->toBeEmpty()
        ->and($pending->can('office-tat.view'))->toBeFalse()
        ->and($pending->can('office-tat.configure'))->toBeFalse()
        ->and($pending->can('compliance-alerts.manage'))->toBeFalse();

    $this->actingAs($pending)->get('/submission-tracking')->assertOk();
    $access = app(OrganizationalAccessService::class);
    expect($access->canAccessProtectedArea($pending, $areaA->id))->toBeTrue()
        ->and($access->canAccessProtectedArea($pending, $areaB->id))->toBeFalse()
        ->and($access->canAccessProtectedAreaRecord($pending, $reportA))->toBeTrue()
        ->and($access->canAccessProtectedAreaRecord($pending, $reportB))->toBeFalse();

    $this->post(route('final-archives.receipts.store', ['source' => 'bms', 'record' => $reportA->id]), [
        'physically_received_at' => '2026-10-20',
    ])->assertForbidden();

    $beforeAssignmentChange = $reportA->fresh()->only(['id', 'protected_area_id', 'created_by', 'date_received_penro']);
    $this->actingAs($admin)->put(route('admin.users.update', $pending), [
        'name' => $pending->name, 'email' => $pending->email, 'account_role' => 'User',
        'operational_group' => 'cenro', 'section' => 'CENRO_CDS_FOCAL', 'office_designated' => 'CENRO Manay',
    ])->assertStatus(302)->assertSessionHasNoErrors();

    $reassigned = $pending->fresh();
    expect($reassigned->section)->toBe('CENRO_CDS_FOCAL')
        ->and($reassigned->office_designated)->toBe('CENRO Manay')
        ->and($reassigned->protected_area_id)->toBeNull()
        ->and($reassigned->getRoleNames()->all())->toBe(['no_role'])
        ->and($reassigned->getAllPermissions())->toBeEmpty()
        ->and($reportA->fresh()->only(['id', 'protected_area_id', 'created_by', 'date_received_penro']))->toBe($beforeAssignmentChange);

    expect($access->canAccessProtectedArea($reassigned, $areaA->id))->toBeFalse()
        ->and($access->canAccessProtectedArea($reassigned, $areaB->id))->toBeTrue()
        ->and($access->canAccessProtectedAreaRecord($reassigned, $reportA))->toBeFalse()
        ->and($access->canAccessProtectedAreaRecord($reassigned, $reportB))->toBeTrue();
    $this->actingAs($reassigned)->get(route('attachments.show', ['source' => 'bms-report', 'record' => $reportA->id, 'attachment' => 'mov']))->assertForbidden();
});

test('a freshly registered CENRO report is projected once by PA identity and a second account does not change its metrics', function (): void {
    $admin = freshOfficeTatAdmin();
    [$area] = freshOfficeTatArea($admin, 'CENRO Baganga');
    $focal = freshOfficeTatRegisterAndApprove($this, $admin, 'CENRO_CDS_FOCAL', null, 'CENRO Baganga');
    $chief = freshOfficeTatRegisterAndApprove($this, $admin, 'CENRO_CDS_CHIEF', null, 'CENRO Baganga');
    $cenroRecords = freshOfficeTatRegisterAndApprove($this, $admin, 'CENRO_RECORDS', null, 'CENRO Baganga');
    $penroRecords = freshOfficeTatRegisterAndApprove($this, $admin, 'PENRO_RECORDS');
    $penroFocal = freshOfficeTatRegisterAndApprove($this, $admin, 'PENRO_CDS_FOCAL');

    expect($focal->getAllPermissions())->toBeEmpty()
        ->and($penroFocal->getAllPermissions())->toBeEmpty()
        ->and($focal->can('office-tat.configure'))->toBeFalse()
        ->and($penroFocal->can('compliance-alerts.manage'))->toBeFalse();

    $definition = app(ReportRequirementRegistry::class)->find('pa', 'restoration_plan_5_year');
    $activity = (string) $definition['default_activity'];
    $document = (string) $definition['activity_documents'][$activity][0];
    $period = 'Quarter 4';
    expect($period)->not->toBeNull();

    $this->actingAs($focal)->post(route('conservation-reports.store', 'restoration_plan_5_year'), [
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => $activity, 'document_type' => $document, 'reporting_period' => $period,
        'date_conducted' => '2026-10-05', 'date_accomplished' => '2026-10-05',
        'mov' => UploadedFile::fake()->create('fresh-restoration-report.pdf', 32, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->where('created_by', $focal->id)->firstOrFail();
    expect($report->workflow_key)->toBe('restoration_plan_5_year')
        ->and($report->activity_name)->toBe($activity)
        ->and($report->reporting_period)->toBe($period);

    $routing = app(DocumentRoutingTransitionService::class);
    $routing->transition($report, 'conservation', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report->fresh(), 'conservation', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report->fresh(), 'conservation', 'forward_to_cenro_records', $chief->id);
    $routing->transition($report->fresh(), 'conservation', 'receive_at_cenro_records', $cenroRecords->id);
    $routing->transition($report->fresh(), 'conservation', 'forward_to_penro_records', $cenroRecords->id);
    $routing->transition($report->fresh(), 'conservation', 'receive_at_penro_records', $penroRecords->id);

    $viewer = freshOfficeTatAdmin();
    $service = app(OfficeTatComplianceAssessmentService::class);
    $before = $service->recordedAssessments($viewer, 'conservation', '2026-H2', '2026-12-31');
    $rowBefore = collect($before['rows'])->firstWhere('participant_key', 'pa:'.$area->id);
    expect($rowBefore)->not->toBeNull()
        ->and($rowBefore['activity_count'])->toBe(1)
        ->and($rowBefore['received_activity_count'])->toBe(1)
        ->and($rowBefore['due_count'])->toBe(1)
        ->and($before['full_period_coverage'])->toBe('not_established_by_recorded_population');

    $pamoOne = freshOfficeTatRegisterAndApprove($this, $admin, 'PAMO', $area->id);
    $pamoTwo = freshOfficeTatRegisterAndApprove($this, $admin, 'PAMO', $area->id);
    expect($pamoOne->protected_area_id)->toBe($area->id)
        ->and($pamoTwo->protected_area_id)->toBe($area->id)
        ->and($pamoOne->getRoleNames()->all())->toBe(['no_role'])
        ->and($pamoTwo->getRoleNames()->all())->toBe(['no_role']);

    $after = $service->recordedAssessments($viewer, 'conservation', '2026-H2', '2026-12-31');
    $rowAfter = collect($after['rows'])->firstWhere('participant_key', 'pa:'.$area->id);
    expect(collect($after['rows'])->where('participant_key', 'pa:'.$area->id))->toHaveCount(1)
        ->and($after['roster_count'])->toBe($before['roster_count'])
        ->and($rowAfter['activity_count'])->toBe($rowBefore['activity_count'])
        ->and($rowAfter['received_activity_count'])->toBe($rowBefore['received_activity_count'])
        ->and($rowAfter['due_count'])->toBe($rowBefore['due_count'])
        ->and($rowAfter['tc_percent'])->toBe($rowBefore['tc_percent'])
        ->and($rowAfter['se_percent'])->toBe($rowBefore['se_percent'])
        ->and($rowAfter['otps'])->toBe($rowBefore['otps'])
        ->and($report->fresh()->created_by)->toBe($focal->id)
        ->and($report->fresh()->date_received_penro)->not->toBeNull();
});
