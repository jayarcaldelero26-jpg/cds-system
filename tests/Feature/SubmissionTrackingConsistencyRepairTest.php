<?php

use App\Models\BmsReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('no_role', 'web');
});

function consistencyRepairAdmin(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));

    return $user;
}

test('Super Admin receives active global tracking rows without normal actions', function (): void {
    $admin = consistencyRepairAdmin();
    $area = ProtectedArea::create([
        'name' => 'Consistency Repair PA', 'short_name' => 'CRPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Active global visibility', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
    ]);

    $this->actingAs($admin);
    $snapshot = app(SubmissionTrackingService::class)->snapshot([], 1, 25);
    $row = $snapshot['records']->firstWhere('source_id', $report->id);

    expect($row)->not->toBeNull()
        ->and($snapshot['queues']['active']->pluck('source_id'))->toContain($report->id)
        ->and($snapshot['queues']['history']->pluck('source_id'))->not->toContain($report->id)
        ->and($row['routing_complete'])->toBeFalse()
        ->and($row['routing']['actions'])->toBeEmpty()
        ->and(app(DocumentRoutingAccessService::class)->canView($admin, $report, 'bms', 'bms.update'))->toBeTrue()
        ->and(app(OrganizationalAccessService::class)->canActOnSubmission($admin, $report))->toBeFalse();
});

test('new registration rejects PAMO instead of deriving a legacy account', function (): void {
    $this->post('/register', [
        'name' => 'Derived PAMO Applicant', 'email' => 'derived-pamo@example.com',
        'operational_group' => 'pamo', 'password' => 'Password!123', 'password_confirmation' => 'Password!123',
    ])->assertSessionHasErrors('operational_group');

    expect(User::where('email', 'derived-pamo@example.com')->exists())->toBeFalse();

    $this->post('/register', [
        'name' => 'Tampered PAMO Applicant', 'email' => 'tampered-pamo@example.com',
        'operational_group' => 'pamo', 'section' => OrganizationalAccessService::CENRO_FOCAL,
        'password' => 'Password!123', 'password_confirmation' => 'Password!123',
    ])->assertSessionHasErrors('operational_group');
});
