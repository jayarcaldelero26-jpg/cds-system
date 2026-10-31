<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentArchive;
use App\Models\ModuleDefinition;
use App\Models\OrganizationalOffice;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\RoutingPositionSnapshotService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
});

function p2PamoScopeUser(?int $protectedAreaId = null): User
{
    $pamoOffice = OrganizationalOffice::query()->firstOrCreate(
        ['code' => 'p2_pamo_scope_office'],
        ['name' => 'P2 Test PAMO Office', 'office_type' => 'pamo', 'is_active' => true],
    );
    $user = User::factory()->create([
        'section' => 'PAMO',
        'unit_assignment' => null,
        'office_designated' => $pamoOffice->name,
        'protected_area_id' => $protectedAreaId,
    ]);
    $role = Role::findOrCreate('PAMO', 'web');
    $role->syncPermissions([
        Permission::findOrCreate('technical-reports.view', 'web'),
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

function p2MapPamoArea(ProtectedArea $area, User $actor): void
{
    $pamoOffice = OrganizationalOffice::query()->where('code', 'p2_pamo_scope_office')->firstOrFail();
    ProtectedAreaOfficeAssignment::query()->updateOrCreate(
        ['protected_area_id' => $area->id, 'assignment_type' => 'pamo'],
        ['organizational_office_id' => $pamoOffice->id, 'assigned_by' => $actor->id],
    );
    $cenro = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
    ProtectedAreaOfficeAssignment::query()->updateOrCreate(
        ['protected_area_id' => $area->id, 'assignment_type' => 'supervising'],
        ['organizational_office_id' => $cenro->id, 'assigned_by' => $actor->id],
    );
}

test('assigned PAMO sees active generic PA Conservation submissions only for its assigned PA', function (): void {
    $pamo = p2PamoScopeUser();
    $areaA = ProtectedArea::create(['name' => 'P2 PAMO Area A', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $pamo->id, 'updated_by' => $pamo->id]);
    $areaB = ProtectedArea::create(['name' => 'P2 PAMO Area B', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $pamo->id, 'updated_by' => $pamo->id]);
    p2MapPamoArea($areaA, $pamo);
    $pamo->update(['protected_area_id' => $areaA->id]);
    $pamo->refresh();
    $regular = p2PambScopeReport($pamo, $areaA, 'regular_pamb');
    $special = p2PambScopeReport($pamo, $areaA, 'special_pamb');
    $generic = ConservationReportSubmission::create([
        'workflow_key' => 'maintenance_monuments', 'protected_area_id' => $areaA->id,
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Maintenance of Monuments',
        'document_type' => 'Progress Report', 'date_accomplished' => '2026-09-01',
        'created_by' => $pamo->id, 'updated_by' => $pamo->id,
    ]);
    $other = p2PambScopeReport($pamo, $areaB, 'regular_pamb');
    $access = app(PambSubmissionAccessService::class);

    $visibleIds = $access->scopeQuery(ConservationReportSubmission::query(), $pamo)->pluck('id')->all();
    $organization = app(OrganizationalAccessService::class);

    expect($access->canView($pamo, $regular))->toBeTrue()
        ->and($access->canView($pamo, $special))->toBeTrue()
        ->and($access->canView($pamo, $generic))->toBeTrue()
        ->and($access->canView($pamo, $other))->toBeFalse()
        // Directly created rows have no captured PAMO origin route to submit from.
        ->and($access->canPerformForSubmission($pamo, 'submit', $regular))->toBeFalse()
        ->and($visibleIds)->toContain($regular->id, $special->id, $generic->id)
        ->and($visibleIds)->not->toContain($other->id)
        ->and($organization->effectiveCategory($pamo))->toBe(OrganizationalAccessService::PAMO)
        ->and($organization->canAccessProtectedArea($pamo, $areaA->id))->toBeTrue()
        ->and($organization->canAccessProtectedArea($pamo, $areaB->id))->toBeFalse()
        ->and($organization->isGlobal($pamo))->toBeFalse();

    $this->actingAs($pamo)
        ->get(route('conservation-reports.index', ['workflow' => 'regular_pamb']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('protectedAreas', 1)
            ->where('protectedAreas.0.id', $areaA->id)
            ->where('auth.canViewPambWorkflow', true)
            ->where('auth.canBrowseConservationModules', false)
            ->where('auth.canViewTechnicalReports', false)
            ->where('auth.canViewBms', false));

    $this->actingAs($pamo)
        ->get(route('conservation-reports.index', ['workflow' => 'regular_pamb', 'protected_area_id' => $areaB->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('submissions.data', 0));

    $trackingResponse = $this->actingAs($pamo)
        ->get(route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $regular->id]))
        ->assertOk();
    $trackingProps = $trackingResponse->inertiaProps();
    expect($trackingProps)->not->toHaveKey('queues')
        ->and($trackingProps['trackingContext']['selected_record']['source_id'])->toBe($regular->id)
        // Direct fixtures without a captured PAMO origin route stay outside operational queues.
        ->and(collect($trackingProps['workspaceQueues'])->flatten(1)->pluck('source_id')->map(fn ($id): int => (int) $id))->not->toContain($regular->id);

    $this->actingAs($pamo)
        ->get(route('settings.index'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('Errors/403'));
});

test('assigned PAMO retains inactive configured report history and archive access within its PA', function (): void {
    $assigned = p2PamoScopeUser();
    $areaA = ProtectedArea::create(['name' => 'P2 Inactive PAMO Area A', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $assigned->id, 'updated_by' => $assigned->id]);
    $areaB = ProtectedArea::create(['name' => 'P2 Inactive PAMO Area B', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $assigned->id, 'updated_by' => $assigned->id]);
    p2MapPamoArea($areaA, $assigned);
    p2MapPamoArea($areaB, $assigned);
    $assigned->update(['protected_area_id' => $areaA->id]);
    $assigned->refresh();
    $foreign = p2PamoScopeUser((int) $areaB->id);

    ModuleDefinition::query()->where('code', 'maintenance_monuments')->firstOrFail()->update(['is_active' => false]);
    $historic = ConservationReportSubmission::create([
        'workflow_key' => 'maintenance_monuments', 'protected_area_id' => $areaA->id,
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Maintenance of Monuments',
        'document_type' => 'Progress Report', 'date_accomplished' => '2026-09-01',
        'created_by' => $assigned->id, 'updated_by' => $assigned->id,
    ]);
    $outside = ConservationReportSubmission::create([
        'workflow_key' => 'maintenance_monuments', 'protected_area_id' => $areaB->id,
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Maintenance of Monuments',
        'document_type' => 'Progress Report', 'date_accomplished' => '2026-09-02',
        'created_by' => $assigned->id, 'updated_by' => $assigned->id,
    ]);
    $draft = PamoPambDraft::create([
        'draft_uuid' => (string) Str::uuid(), 'protected_area_id' => $areaA->id,
        'pamo_office_id' => OrganizationalOffice::query()->where('code', 'p2_pamo_scope_office')->value('id'),
        'pamo_office_name' => 'P2 Test PAMO Office', 'originator_user_id' => $assigned->id,
        'created_by' => $assigned->id, 'updated_by' => $assigned->id,
        'workflow_key' => 'maintenance_monuments',
        'report_payload' => ['activity_name' => 'Maintenance of Monuments', 'document_type' => 'Progress Report'],
        'started_at' => now(), 'status' => 'forwarded', 'forwarded_at' => now(),
        'conservation_report_submission_id' => $historic->id, 'lock_version' => 2,
    ]);
    $settingVersionId = (int) DB::table('routing_position_setting_versions')->value('id');
    SubmissionRoutingSnapshot::create([
        'source_key' => 'conservation', 'source_id' => $historic->id,
        'setting_version_id' => $settingVersionId, 'profile' => 'pamo_direct',
        'graph_version' => RoutingPositionSnapshotService::PAMO_GRAPH_VERSION,
        'captured_by' => $assigned->id, 'captured_at' => now(), 'origin_kind' => 'pamo_pamb',
        'protected_area_id' => $areaA->id, 'pamo_office_id' => $draft->pamo_office_id,
        'pamo_office_name' => $draft->pamo_office_name, 'originator_user_id' => $assigned->id,
        'origin_started_at' => now(),
    ]);
    $archiveBytes = "%PDF-1.4\nInactive PAMO archive fixture\n%%EOF";
    $archivePath = tempnam(sys_get_temp_dir(), 'pamo-inactive-archive-');
    file_put_contents($archivePath, $archiveBytes);
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $archiveHash = hash('sha256', $archiveBytes);
    $remote = $gateway->upload($archivePath, 'inactive-report.pdf', [
        'source_type' => 'conservation', 'source_id' => (int) $historic->id, 'logical_slot' => 'mov',
    ], $archiveHash, null);
    unlink($archivePath);
    $archive = DocumentArchive::create([
        'source_type' => 'conservation', 'source_id' => $historic->id, 'logical_slot' => 'mov',
        'google_drive_file_id' => $remote['file_id'], 'archived_sha256' => $archiveHash,
        'archived_size' => strlen($archiveBytes), 'archived_at' => now(), 'archive_status' => 'ARCHIVED',
        'original_filename' => 'inactive-report.pdf',
    ]);

    $access = app(PambSubmissionAccessService::class);
    expect($access->canView($assigned, $historic))->toBeTrue()
        ->and($access->canView($foreign, $historic))->toBeFalse()
        ->and($access->scopeQuery(ConservationReportSubmission::query(), $assigned)->pluck('id')->all())->toContain($historic->id)
        ->and($access->scopeQuery(ConservationReportSubmission::query(), $assigned)->pluck('id')->all())->not->toContain($outside->id);

    $this->actingAs($assigned)->get(route('conservation-reports.index', 'maintenance_monuments'))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('submissions.data.0.id', $historic->id)
            ->where('pamoWorkspace', true));
    $this->actingAs($assigned)->post(route('conservation-reports.pamo-drafts.store', 'maintenance_monuments'), [
        'start_idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();
    expect(PamoPambDraft::query()->where('workflow_key', 'maintenance_monuments')->count())->toBe(1);

    $this->actingAs($assigned)->get(route('pamo-archives.index'))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('archives.total', 1)
            ->where('archives.data.0.id', $archive->id)
            ->where('archives.data.0.workflow_key', 'maintenance_monuments'));
    $this->actingAs($assigned)->get(route('pamo-archives.file', $archive->id))->assertOk();
    $this->actingAs($foreign)->get(route('pamo-archives.index'))
        ->assertOk()->assertInertia(fn ($page) => $page->where('archives.total', 0));
    $this->actingAs($foreign)->get(route('pamo-archives.file', $archive->id))->assertNotFound();
    expect($gateway->retrievals())->toBe(1);
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
