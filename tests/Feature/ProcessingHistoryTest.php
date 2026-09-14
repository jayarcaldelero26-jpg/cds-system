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

function processingHistoryUser(string $section, string $office): User
{
    $user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => $section,
        'office_designated' => $office,
    ]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));

    return $user;
}

function processingHistoryReport(User $owner): BmsReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Processing History PA',
        'short_name' => 'PHPA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Processing history report',
        'document_type' => 'Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-09-10',
    ]);
}

test('active routed records remain Outgoing and enter History only at terminal release', function (): void {
    $actors = collect([
        'focal' => processingHistoryUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        'chief' => processingHistoryUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        'cenro_records' => processingHistoryUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        'penro_records' => processingHistoryUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
        'office' => processingHistoryUser(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental'),
        'tsd' => processingHistoryUser(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'),
        'penro_focal' => processingHistoryUser(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental'),
        'penro_chief' => processingHistoryUser(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental'),
    ]);
    $report = processingHistoryReport($actors['focal']);
    $routing = app(DocumentRoutingTransitionService::class);

    foreach ([
        ['focal', 'forward_to_cenro_chief'],
        ['chief', 'receive_at_cenro_chief'],
        ['chief', 'forward_to_cenro_records'],
        ['cenro_records', 'receive_at_cenro_records'],
        ['cenro_records', 'forward_to_penro_records'],
        ['penro_records', 'receive_at_penro_records'],
        ['penro_records', 'forward_to_office_penro'],
        ['office', 'receive_at_office_penro'],
        ['office', 'assign_to_tsd_chief'],
        ['tsd', 'receive_at_tsd_chief'],
        ['tsd', 'forward_to_cds_focal'],
        ['penro_focal', 'receive_at_cds_focal'],
        ['penro_focal', 'forward_to_cds_chief'],
        ['penro_chief', 'receive_at_cds_chief'],
        ['penro_chief', 'recommend_to_office_penro'],
        ['office', 'receive_at_office_penro_final'],
        ['office', 'approve_for_regional_release'],
        ['penro_records', 'receive_at_penro_records_final'],
    ] as [$actor, $action]) {
        $routing->transition($report, 'bms', $action, $actors[$actor]->id);
    }

    test()->actingAs($actors['focal']);
    $tracking = app(SubmissionTrackingService::class);
    $workspace = $tracking->workspaceQueues();
    expect($workspace['history']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($workspace['outgoing']->pluck('source_id')->all())->toContain($report->id);

    $routing->transition($report, 'bms', 'release_to_regional', $actors['penro_records']->id);

    test()->actingAs($actors['focal']);
    $queues = app(SubmissionTrackingService::class)->queues();
    $workspace = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($workspace['history']->pluck('source_id')->all())->toContain($report->id)
        ->and($workspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id);
});