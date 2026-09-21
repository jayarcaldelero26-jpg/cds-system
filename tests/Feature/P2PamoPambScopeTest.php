<?php

use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function p2PamoScopeUser(?int $protectedAreaId = null): User
{
    $user = User::factory()->create([
        'section' => 'PAMO',
        'unit_assignment' => 'conservation',
        'office_designated' => 'CENRO Baganga',
        'protected_area_id' => $protectedAreaId,
    ]);
    $role = Role::findOrCreate('PAMO', 'web');
    $role->syncPermissions([
        Permission::findOrCreate('submission-tracking.view', 'web'),
    ]);
    $user->assignRole($role);

    return $user;
}

function p2PambScopeReport(User $creator, ProtectedArea $area, string $workflow): ConservationReportSubmission
{
    return ConservationReportSubmission::create([
        'workflow_key' => $workflow,
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Baganga',
        'activity_name' => $workflow === 'special_pamb' ? 'Special PAMB' : 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-09-01',
        'date_accomplished' => '2026-09-01',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
}

test('assigned PAMO sees only Regular and Special PAMB submissions for its assigned PA', function (): void {
    $pamo = p2PamoScopeUser();
    $areaA = ProtectedArea::create(['name' => 'P2 PAMO Area A', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $pamo->id, 'updated_by' => $pamo->id]);
    $areaB = ProtectedArea::create(['name' => 'P2 PAMO Area B', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $pamo->id, 'updated_by' => $pamo->id]);
    $pamo->update(['protected_area_id' => $areaA->id]);
    $pamo->refresh();
    $regular = p2PambScopeReport($pamo, $areaA, 'regular_pamb');
    $special = p2PambScopeReport($pamo, $areaA, 'special_pamb');
    $other = p2PambScopeReport($pamo, $areaB, 'regular_pamb');
    $access = app(PambSubmissionAccessService::class);

    $visibleIds = $access->scopeQuery(ConservationReportSubmission::query(), $pamo)->pluck('id')->all();

    expect($access->canView($pamo, $regular))->toBeTrue()
        ->and($access->canView($pamo, $special))->toBeTrue()
        ->and($access->canView($pamo, $other))->toBeFalse()
        ->and($access->canPerformForSubmission($pamo, 'submit', $regular))->toBeTrue()
        ->and($visibleIds)->toContain($regular->id, $special->id)
        ->and($visibleIds)->not->toContain($other->id);

    $this->actingAs($pamo)
        ->get(route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $regular->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('trackingContext.selected_record.source_id', $regular->id)
            ->where('queues.for_submission.0.source_id', $regular->id));
});

test('unassigned PAMO cannot see PAMB submissions and permission remains required', function (): void {
    $assigned = p2PamoScopeUser();
    $area = ProtectedArea::create(['name' => 'P2 Unassigned PAMO Area', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $assigned->id, 'updated_by' => $assigned->id]);
    $report = p2PambScopeReport($assigned, $area, 'regular_pamb');
    $unassigned = p2PamoScopeUser();
    $access = app(PambSubmissionAccessService::class);

    expect($access->canView($unassigned, $report))->toBeFalse()
        ->and($access->scopeQuery(ConservationReportSubmission::query(), $unassigned)->pluck('id')->all())->toBe([]);

    $unauthorized = User::factory()->create(['section' => 'Viewer', 'unit_assignment' => null]);
    $this->actingAs($unauthorized)->get(route('submission-tracking.index'))->assertForbidden();
});

test('PAMO scope does not change CENRO, PENRO, or global PAMB visibility', function (): void {
    $creator = User::factory()->create();
    $area = ProtectedArea::create(['name' => 'P2 Category Scope Area', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $creator->id, 'updated_by' => $creator->id]);
    $office = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => $office->id, 'assignment_type' => 'supervising', 'assigned_by' => $creator->id]);
    $report = p2PambScopeReport($creator, $area, 'regular_pamb');
    $cenro = User::factory()->create(['section' => 'CENRO_RECORDS', 'office_designated' => 'CENRO Baganga', 'unit_assignment' => 'conservation']);
    $penro = User::factory()->create(['section' => 'PENRO_RECORDS', 'office_designated' => 'PENRO Davao Oriental']);
    $admin = User::factory()->create(['section' => 'CDS']);
    foreach ([[$cenro, 'CENRO Records Unit'], [$penro, 'PENRO Records Unit'], [$admin, 'Super Admin']] as [$user, $roleName]) {
        $user->assignRole(Role::findOrCreate($roleName, 'web'));
    }
    $access = app(PambSubmissionAccessService::class);

    expect($access->canView($cenro, $report))->toBeTrue()
        ->and($access->canView($penro, $report))->toBeTrue()
        ->and($access->canView($admin, $report))->toBeTrue();
});
