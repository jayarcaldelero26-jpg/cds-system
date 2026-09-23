<?php

use App\Models\BmsReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Permission;

function bmsQueueTestActor(string $category, string $office): User
{
    $user = User::factory()->create([
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'section' => $category,
        'office_designated' => $office,
    ]);
    $user->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.update', 'web'),
    ]);

    return $user;
}

function bmsQueueTestReport(User $creator): BmsReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'BMS Queue Projection PA',
        'short_name' => 'BQPA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'UAT-BMS-QUEUE-2026',
        'document_type' => 'Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
}

function bmsQueueAssertIncoming(User $actor, BmsReportSubmission $report, string $action): void
{
    test()->actingAs($actor);
    $row = app(SubmissionTrackingService::class)->workspaceQueues()['incoming']
        ->firstWhere(fn (array $candidate): bool => $candidate['source'] === 'bms' && (int) $candidate['source_id'] === $report->id);

    expect($row)->not->toBeNull()
        ->and($row['source'])->toBe('bms')
        ->and((int) $row['source_id'])->toBe($report->id)
        ->and($row['routing']['actions'])->not->toBeEmpty()
        ->and($row['incoming_action_category'])->toBe($action);
}

test('BMS actionable records remain discoverable in the normal Incoming queue across custody stages', function (): void {
    $focal = bmsQueueTestActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = bmsQueueTestActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = bmsQueueTestActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = bmsQueueTestActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = bmsQueueTestActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = bmsQueueTestActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $wrongOffice = bmsQueueTestActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Lupon');
    $report = bmsQueueTestReport($focal);
    $routing = app(DocumentRoutingTransitionService::class);

    bmsQueueAssertIncoming($focal, $report, 'forward');
    test()->actingAs($wrongOffice);
    expect(app(SubmissionTrackingService::class)->workspaceQueues()['incoming']->pluck('source_id')->all())->not->toContain($report->id);

    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    bmsQueueAssertIncoming($chief, $report, 'receive');
    $this->get(route('submission-tracking.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('workspaceQueues.incoming', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id)));

    $routing->transition($report, 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report, 'bms', 'forward_to_cenro_records', $chief->id);
    bmsQueueAssertIncoming($cenroRecords, $report, 'receive');

    $routing->transition($report, 'bms', 'receive_at_cenro_records', $cenroRecords->id);
    $routing->transition($report, 'bms', 'forward_to_penro_records', $cenroRecords->id);
    bmsQueueAssertIncoming($penroRecords, $report, 'receive');

    $routing->transition($report, 'bms', 'receive_at_penro_records', $penroRecords->id);
    $routing->transition($report, 'bms', 'receive_at_office_penro', $office->id);
    bmsQueueAssertIncoming($office, $report, 'forward');

    $routing->transition($report, 'bms', 'assign_to_tsd_chief', $office->id);
    bmsQueueAssertIncoming($tsd, $report, 'receive');
});

test('terminal BMS records are excluded from actionable Incoming while remaining trackable', function (): void {
    $focal = bmsQueueTestActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = bmsQueueTestActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = bmsQueueTestActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = bmsQueueTestActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = bmsQueueTestActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = bmsQueueTestActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = bmsQueueTestActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = bmsQueueTestActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $report = bmsQueueTestReport($focal);
    $routing = app(DocumentRoutingTransitionService::class);

    foreach ([
        [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'], [$chief, 'forward_to_cenro_records'],
        [$cenroRecords, 'receive_at_cenro_records'], [$cenroRecords, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
        [$office, 'receive_at_office_penro'], [$office, 'assign_to_tsd_chief'], [$tsd, 'receive_at_tsd_chief'], [$tsd, 'forward_to_cds_focal'],
        [$penroFocal, 'receive_at_cds_focal'], [$penroFocal, 'forward_to_cds_chief'], [$penroChief, 'receive_at_cds_chief'],
        [$penroChief, 'recommend_to_office_penro'], [$office, 'receive_at_office_penro_final'], [$office, 'approve_for_regional_release'],
        [$penroRecords, 'receive_at_penro_records_final'], [$penroRecords, 'release_to_regional'],
    ] as [$actor, $action]) {
        $routing->transition($report, 'bms', $action, $actor->id);
        if ($action === 'receive_at_penro_records_final') {
            bmsQueueAssertIncoming($penroRecords, $report, 'release');
        }
    }

    test()->actingAs($penroRecords);
    $workspace = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($workspace['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($workspace['history']->pluck('source_id')->all())->toContain($report->id)
        ->and($report->fresh()->date_endorsed_regional)->not->toBeNull();
});
