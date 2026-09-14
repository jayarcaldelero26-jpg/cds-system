<?php

use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Permission;

function currentActorOwnershipUser(string $section, string $office): User
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

function currentActorOwnershipReport(User $owner): ConservationReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Current Actor Queue PA',
        'short_name' => 'CAQPA',
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

    return ConservationReportSubmission::create([
        'workflow_key' => 'homestay',
        'activity_name' => 'Current actor ownership report',
        'target_office' => 'CENRO Mati',
        'protected_area_id' => $area->id,
        'date_accomplished' => '2026-09-10',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function currentActorQueueIds(User $user, string $queue): array
{
    test()->actingAs($user);

    return app(SubmissionTrackingService::class)->queues()[$queue]->pluck('source_id')->map(fn ($id): int => (int) $id)->all();
}

test('the canonical current actor always owns exactly one active queue and handoffs do not skip PENRO CDS Focal', function (): void {
    $cenroFocal = currentActorOwnershipUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $cenroChief = currentActorOwnershipUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = currentActorOwnershipUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = currentActorOwnershipUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = currentActorOwnershipUser(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = currentActorOwnershipUser(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');

    // These display-label values are intentionally not rewritten in storage.
    $focal = currentActorOwnershipUser('PENRO CDS Focal Person', 'PENRO Davao Oriental');
    $chief = currentActorOwnershipUser('PENRO CDS Chief', 'PENRO Davao Oriental');
    $report = currentActorOwnershipReport($cenroFocal);
    $routing = app(DocumentRoutingTransitionService::class);

    foreach ([
        [$cenroFocal, 'forward_to_cenro_chief'],
        [$cenroChief, 'receive_at_cenro_chief'],
        [$cenroChief, 'forward_to_cenro_records'],
        [$cenroRecords, 'receive_at_cenro_records'],
        [$cenroRecords, 'forward_to_penro_records'],
        [$penroRecords, 'receive_at_penro_records'],
        [$penroRecords, 'forward_to_office_penro'],
        [$office, 'receive_at_office_penro'],
        [$office, 'assign_to_tsd_chief'],
        [$tsd, 'receive_at_tsd_chief'],
        [$tsd, 'forward_to_cds_focal'],
    ] as [$actor, $action]) {
        $routing->transition($report, 'conservation', $action, $actor->id);
    }

    expect($focal->fresh()->section)->toBe('PENRO CDS Focal Person')
        ->and(app(OrganizationalAccessService::class)->effectiveCategory($focal->fresh()))
        ->toBe(OrganizationalAccessService::PENRO_FOCAL)
        ->and(currentActorQueueIds($focal, 'cds_processing'))->toContain($report->id)
        ->and(currentActorQueueIds($chief, 'cds_review'))->not->toContain($report->id)
        ->and(currentActorQueueIds($focal, 'processed'))->not->toContain($report->id);

    $focalQueues = app(SubmissionTrackingService::class)->queues();
    $activeCount = collect($focalQueues)
        ->except(['processed', 'history', 'release_history'])
        ->flatten(1)
        ->filter(fn (array $row): bool => (int) ($row['source_id'] ?? 0) === $report->id)
        ->count();
    expect($activeCount)->toBe(1);

    $routing->transition($report, 'conservation', 'receive_at_cds_focal', $focal->id);
    $routing->transition($report, 'conservation', 'forward_to_cds_chief', $focal->id);

    expect(currentActorQueueIds($focal, 'cds_processing'))->not->toContain($report->id)
        ->and(currentActorQueueIds($focal, 'processed'))->toContain($report->id)
        ->and(currentActorQueueIds($chief, 'cds_review'))->toContain($report->id)
        ->and(currentActorQueueIds($chief, 'history'))->not->toContain($report->id);

    $chiefQueues = app(SubmissionTrackingService::class)->queues();
    $chiefActiveCount = collect($chiefQueues)
        ->except(['processed', 'history', 'release_history'])
        ->flatten(1)
        ->filter(fn (array $row): bool => (int) ($row['source_id'] ?? 0) === $report->id)
        ->count();
    expect($chiefActiveCount)->toBe(1);
});