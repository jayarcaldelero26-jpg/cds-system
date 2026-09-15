<?php

use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Permission;

function genericQueueUser(string $section, string $office): User
{
    $user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => $section,
        'office_designated' => $office,
    ]);
    foreach (['reports.view', 'technical-reports.update'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    return $user;
}

function genericQueueReport(User $owner): ConservationReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Homestay Queue PA', 'short_name' => 'HQPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Generic Homestay',
        'target_office' => 'CENRO Mati', 'protected_area_id' => $area->id,
        'date_accomplished' => '2026-08-03', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

function genericQueueRow(SubmissionTrackingService $tracking, int $id): array
{
    return $tracking->records()->first(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $id);
}

function assertGenericQueueContains(SubmissionTrackingService $tracking, string $queue, int $id): void
{
    $actual = $tracking->queues()[$queue]->pluck('source_id')->all();
    expect($actual)->toContain($id);
}

test('generic Homestay uses canonical routing actions and never exposes PAMB MOV submission', function (): void {
    $focal = genericQueueUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = genericQueueUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $report = genericQueueReport($focal);

    $this->actingAs($focal);
    $tracking = app(SubmissionTrackingService::class);
    $row = genericQueueRow($tracking, $report->id);

    expect($row['pamb_routing_applicable'])->toBeFalse()
        ->and($row['mov_processing']['applicable'])->toBeFalse()
        ->and($row['routing']['responsible_user_category'])->toBe('CENRO CDS Focal Person')
        ->and($row['routing']['next_expected_action'])->toBe('Forward to CENRO Chief')
        ->and($row['routing']['actions'])->toHaveCount(1)
        ->and($row['routing']['actions'][0]['key'])->toBe('forward_to_cenro_chief');

    assertGenericQueueContains($tracking, 'for_submission', $report->id);
    expect($tracking->queues()['for_review']->pluck('source_id')->all())->not->toContain($report->id);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertStatus(422);

    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])
        ->assertSessionHas('success', 'Document forwarded successfully.');
    $this->actingAs($chief);
    assertGenericQueueContains($tracking, 'for_review', $report->id);
});


test('workspace Incoming and Outgoing preserve handoff semantics while categorizing current actions', function (): void {
    $focal = genericQueueUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = genericQueueUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = genericQueueUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $report = genericQueueReport($focal);
    $tracking = app(SubmissionTrackingService::class);

    $this->actingAs($focal);
    $workspace = $tracking->workspaceQueues();
    expect($workspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($workspace['incoming']->firstWhere('source_id', $report->id)['incoming_action_category'])->toBe('forward')
        ->and($workspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $tracking->transition('conservation', $report->id, 'forward_to_cenro_chief', null, $focal->id);

    $workspace = $tracking->workspaceQueues();
    expect($workspace['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($workspace['outgoing']->pluck('source_id')->all())->toContain($report->id);

    $this->actingAs($chief);
    $workspace = $tracking->workspaceQueues();
    expect($workspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($workspace['incoming']->firstWhere('source_id', $report->id)['incoming_action_category'])->toBe('receive')
        ->and($workspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $tracking->transition('conservation', $report->id, 'receive_at_cenro_chief', null, $chief->id);
    $this->actingAs($chief);
    $workspace = $tracking->workspaceQueues();
    expect($workspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($workspace['incoming']->firstWhere('source_id', $report->id)['incoming_action_category'])->toBe('forward')
        ->and($workspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($workspace['history']->pluck('source_id')->all())->not->toContain($report->id);

    $tracking->transition('conservation', $report->id, 'forward_to_cenro_records', null, $chief->id);
    $this->actingAs($chief);
    $workspace = $tracking->workspaceQueues();
    expect($workspace['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($workspace['outgoing']->pluck('source_id')->all())->toContain($report->id);

    $this->actingAs($records);
    $workspace = $tracking->workspaceQueues();
    expect($workspace['incoming']->pluck('source_id')->all())->toContain($report->id);
});
test('generic Homestay remains discoverable through the complete shared custody chain', function (): void {
    $focal = genericQueueUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = genericQueueUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = genericQueueUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = genericQueueUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = genericQueueUser(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = genericQueueUser(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = genericQueueUser(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = genericQueueUser(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $report = genericQueueReport($focal);
    $tracking = app(SubmissionTrackingService::class);

    $steps = [
        [$focal, 'forward_to_cenro_chief', 'for_review'],
        [$chief, 'receive_at_cenro_chief', 'for_review'],
        [$chief, 'forward_to_cenro_records', 'cenro_release'],
        [$cenroRecords, 'receive_at_cenro_records', 'cenro_release'],
        [$cenroRecords, 'forward_to_penro_records', 'penro_receipt'],
        [$penroRecords, 'receive_at_penro_records', 'penro_records_routing'],
        [$penroRecords, 'forward_to_office_penro', 'office_initial_routing'],
        [$office, 'receive_at_office_penro', 'office_initial_routing'],
        [$office, 'assign_to_tsd_chief', 'tsd_routing'],
        [$tsd, 'receive_at_tsd_chief', 'tsd_routing'],
        [$tsd, 'forward_to_cds_focal', 'cds_processing'],
        [$penroFocal, 'receive_at_cds_focal', 'cds_processing'],
        [$penroFocal, 'forward_to_cds_chief', 'cds_review'],
        [$penroChief, 'receive_at_cds_chief', 'cds_review'],
        [$penroChief, 'recommend_to_office_penro', 'office_final_verdict'],
        [$office, 'receive_at_office_penro_final', 'office_final_verdict'],
        [$office, 'approve_for_regional_release', 'penro_records_final'],
        [$penroRecords, 'receive_at_penro_records_final', 'penro_records_final'],
    ];

    $queueViewers = [
        'for_review' => $chief, 'cenro_release' => $cenroRecords, 'penro_receipt' => $penroRecords,
        'penro_records_routing' => $penroRecords, 'office_initial_routing' => $office, 'tsd_routing' => $tsd,
        'cds_processing' => $penroFocal, 'cds_review' => $penroChief, 'office_final_verdict' => $office,
        'penro_records_final' => $penroRecords,
    ];
    $nextActions = [
        'for_review' => 'receive_at_cenro_chief', 'cenro_release' => 'receive_at_cenro_records',
        'penro_receipt' => 'receive_at_penro_records', 'penro_records_routing' => 'forward_to_office_penro',
        'office_initial_routing' => 'receive_at_office_penro', 'tsd_routing' => 'receive_at_tsd_chief',
        'cds_processing' => 'receive_at_cds_focal', 'cds_review' => 'receive_at_cds_chief',
        'office_final_verdict' => 'receive_at_office_penro_final', 'penro_records_final' => 'receive_at_penro_records_final',
    ];

    foreach ($steps as [$actor, $action, $queue]) {
        $this->actingAs($actor);
        $tracking->transition('conservation', $report->id, $action, null, $actor->id);
        $this->actingAs($queueViewers[$queue]);
        assertGenericQueueContains($tracking, $queue, $report->id);

        if ($action === 'receive_at_office_penro_final') {
            $workspace = $tracking->workspaceQueues();
            expect($workspace['incoming']->pluck('source_id')->all())->toContain($report->id)
                ->and($workspace['incoming']->firstWhere('source_id', $report->id)['incoming_action_category'])->toBe('decision')
                ->and($workspace['history']->pluck('source_id')->all())->not->toContain($report->id);
        }

        if (in_array($action, [
            'forward_to_penro_records',
            'receive_at_penro_records',
            'forward_to_office_penro',
            'receive_at_office_penro',
            'assign_to_tsd_chief',
            'receive_at_tsd_chief',
            'forward_to_cds_focal',
            'receive_at_cds_focal',
            'forward_to_cds_chief',
            'receive_at_cds_chief',
            'recommend_to_office_penro',
            'receive_at_office_penro_final',
            'approve_for_regional_release',
            'receive_at_penro_records_final',
            'release_to_regional',
        ], true)) {
            foreach ([$focal, $chief, $cenroRecords] as $cenroViewer) {
                $this->actingAs($cenroViewer);
                expect($tracking->queues()['processed']->pluck('source_id')->all())->toContain($report->id);
                $historyRow = $tracking->queues()['processed']->firstWhere('source_id', $report->id);
                expect($historyRow['routing']['actions'])->toBeEmpty();
            }
        }
    }

    $this->actingAs($penroRecords);
    $tracking->transition('conservation', $report->id, 'release_to_regional', null, $penroRecords->id);
    expect($tracking->queues()['history']->pluck('source_id')->all())->toContain($report->id);

    foreach ([$focal, $chief, $cenroRecords] as $cenroViewer) {
        $this->actingAs($cenroViewer);
        expect($tracking->queues()['history']->pluck('source_id')->all())->toContain($report->id);
        expect($tracking->queues()['history']->firstWhere('source_id', $report->id)['routing']['actions'])->toBeEmpty();
    }
});
