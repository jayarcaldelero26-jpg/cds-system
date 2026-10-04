<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Inertia\Testing\AssertableInertia as Assert;

function meetingSharedActor(string $category, string $office): User
{
    $user = User::factory()->create([
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'section' => $category,
        'office_designated' => $office,
    ]);
    foreach (['reports.view', 'technical-reports.update'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    return $user;
}

function meetingSharedReport(string $workflow, User $owner, bool $direct = false): ConservationReportSubmission
{
    $name = $direct ? 'Mt. Hamiguitan Range Wildlife Sanctuary' : 'Shared Route '.$workflow.' PA';
    $area = ProtectedArea::create([
        'name' => $name, 'short_name' => strtoupper(substr($workflow, 0, 4)),
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return ConservationReportSubmission::create([
        'workflow_key' => $workflow,
        'protected_area_id' => $area->id,
        'target_office' => $direct ? 'PENRO Davao Oriental' : 'CENRO Mati',
        'activity_name' => match ($workflow) {
            'regular_pamb' => 'Regular PAMB', 'special_pamb' => 'Special PAMB', default => 'TWC Meeting',
        },
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3',
        'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
        'mov_file_path' => $direct ? null : 'isolated/mov.pdf',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

test('Regular Special and TWC meetings use the shared Conservation route end to end with an independent MOV gate', function (string $workflow): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $focal = meetingSharedActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = meetingSharedActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = meetingSharedActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = meetingSharedActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = meetingSharedActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = meetingSharedActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = meetingSharedActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = meetingSharedActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $report = meetingSharedReport($workflow, $focal);
    $routing = app(DocumentRoutingTransitionService::class);
    $mov = app(PambMovProcessingService::class);

    $initial = $routing->presentation($report, 'conservation', null, $focal);
    expect($initial['profile']['key'])->toBe('canonical_cenro_penro_regional')
        ->and($initial['stage'])->toBe(DocumentRoutingProfileRegistry::PREPARATION)
        ->and($initial['allowed_actions'])->toBe([]);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['source' => 'conservation', 'record' => $report->id]))->assertRedirect();
    $mov->submit($report->fresh()->load('protectedArea'), $focal);
    expect(collect($routing->presentation($report->fresh(), 'conservation', null, $focal)['allowed_actions'])->pluck('key')->all())
        ->toBe(['forward_to_cenro_chief']);

    $routing->transition($report->fresh(), 'conservation', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report->fresh(), 'conservation', 'receive_at_cenro_chief', $chief->id);
    $mov->review($report->fresh()->load('protectedArea'), $chief, PambMovProcessingService::READY_FOR_RELEASE);
    $ready = $routing->presentation($report->fresh(), 'conservation', null, $chief);
    expect($ready['stage'])->toBe(DocumentRoutingProfileRegistry::CENRO_CHIEF)
        ->and($report->fresh()->date_report_released_cenro)->toBeNull()
        ->and(collect($ready['allowed_actions'])->pluck('key')->all())->toBe(['forward_to_cenro_records']);

    $steps = [
        [$chief, 'forward_to_cenro_records'], [$cenroRecords, 'receive_at_cenro_records'],
        [$cenroRecords, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
        [$penroRecords, 'forward_to_office_penro'], [$office, 'receive_at_office_penro'],
        [$office, 'assign_to_tsd_chief'], [$tsd, 'receive_at_tsd_chief'],
        [$tsd, 'forward_to_cds_focal'], [$penroFocal, 'receive_at_cds_focal'],
        [$penroFocal, 'forward_to_cds_chief'], [$penroChief, 'receive_at_cds_chief'],
        [$penroChief, 'recommend_to_office_penro'], [$office, 'receive_at_office_penro_final'],
        [$office, 'approve_for_regional_release'], [$penroRecords, 'receive_at_penro_records_final'],
        [$penroRecords, 'release_to_regional'],
    ];
    foreach ($steps as [$actor, $action]) $routing->transition($report->fresh(), 'conservation', $action, $actor->id);

    $complete = $report->fresh();
    expect($routing->state($complete, 'conservation')['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($complete->date_report_released_cenro)->not->toBeNull()
        ->and($complete->date_received_penro)->not->toBeNull()
        ->and($complete->date_endorsed_regional)->not->toBeNull()
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $complete->id)->count())->toBe(19)
        ->and(PambRoutingEvent::query()->where('conservation_report_submission_id', $complete->id)->count())->toBe(0)
        ->and(app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->isRoutingComplete($complete))->toBeTrue();
})->with(['regular_pamb', 'special_pamb', 'twc_meetings']);

test('legacy PAMB custody events continue at their mapped shared state without new bridge rows or cross-table id ordering', function (): void {
    $owner = meetingSharedActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = meetingSharedReport('regular_pamb', $owner);
    $report->update(['date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    $legacyRelease = $report->routingEvents()->create([
        'workflow_key' => $report->workflow_key,
        'stage_key' => SubmissionTrackingService::CENRO_RELEASE,
        'occurred_at' => '2026-08-04 09:00:00', 'recorded_by' => $owner->id,
    ]);
    $legacyReceipt = $report->routingEvents()->create([
        'workflow_key' => $report->workflow_key,
        'stage_key' => PambRoutingTimelineService::RECORDS_RECEIVED,
        'occurred_at' => '2026-08-05 09:00:00', 'recorded_by' => $owner->id,
    ]);
    $routing = app(DocumentRoutingTransitionService::class);
    $state = $routing->state($report->fresh()->load('protectedArea'), 'conservation');
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($state['bootstrapped'])->toBeFalse()
        ->and($routing->events($report->fresh()->load('protectedArea'), 'conservation')->pluck('metadata.legacy_pamb_event_id')->filter()->values()->all())
            ->toBe([$legacyRelease->id, $legacyReceipt->id]);

    $routing->transition($report->fresh()->load('protectedArea'), 'conservation', 'forward_to_office_penro', $owner->id);
    expect(PambRoutingEvent::query()->where('conservation_report_submission_id', $report->id)->count())->toBe(2)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(1)
        ->and($routing->state($report->fresh()->load('protectedArea'), 'conservation')['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO);
});

test('mixed equal-time legacy and shared events preserve source-local history ordering', function (): void {
    $owner = meetingSharedActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = meetingSharedReport('regular_pamb', $owner);
    $occurredAt = '2026-08-05 09:00:00';
    $release = $report->routingEvents()->create([
        'workflow_key' => $report->workflow_key,
        'stage_key' => SubmissionTrackingService::CENRO_RELEASE,
        'occurred_at' => $occurredAt,
        'recorded_by' => $owner->id,
    ]);
    $receipt = $report->routingEvents()->create([
        'workflow_key' => $report->workflow_key,
        'stage_key' => PambRoutingTimelineService::RECORDS_RECEIVED,
        'occurred_at' => $occurredAt,
        'recorded_by' => $owner->id,
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation',
        'source_id' => $report->id,
        'workflow_key' => $report->workflow_key,
        'event_key' => 'forwarded',
        'from_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS,
        'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO,
        'from_office' => 'PENRO Records Unit',
        'to_office' => 'Office of the PENRO',
        'occurred_at' => $occurredAt,
        'recorded_by' => $owner->id,
        'metadata' => ['action_key' => 'forward_to_office_penro'],
    ]);

    $events = app(DocumentRoutingTransitionService::class)
        ->state($report->fresh()->load('protectedArea'), 'conservation')['events'];

    expect($events->map(fn (DocumentRoutingEvent $event) => data_get($event->metadata, 'legacy_pamb_event_id'))->filter()->values()->all())
        ->toBe([$release->id, $receipt->id])
        ->and(data_get($events->last()->metadata, 'action_key'))->toBe('forward_to_office_penro');
});

test('legacy active correction cycle resumes in the shared graph and source IDs remain scoped by type', function (): void {
    $owner = meetingSharedActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $report = meetingSharedReport('regular_pamb', $owner);
    $legacyReturn = $report->routingEvents()->create([
        'workflow_key' => $report->workflow_key,
        'stage_key' => PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION.'__cycle_1',
        'occurred_at' => '2026-08-12 09:00:00',
        'recorded_by' => $owner->id,
        'remarks' => 'Correct the missing supporting explanation.',
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'bms',
        'source_id' => $report->id,
        'event_key' => 'released',
        'from_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
        'to_stage' => DocumentRoutingProfileRegistry::RELEASED_REGIONAL,
        'occurred_at' => '2026-08-13 09:00:00',
        'recorded_by' => $owner->id,
        'metadata' => ['action_key' => 'release_to_regional'],
    ]);

    $state = app(DocumentRoutingTransitionService::class)
        ->state($report->fresh()->load('protectedArea'), 'conservation');

    expect($state['active_cycle'])->toBe(2)
        ->and($state['stage'])->toBe(DocumentRoutingProfileRegistry::CDS_FOCAL)
        ->and(collect($state['actions'])->pluck('key')->all())->toBe(['receive_correction'])
        ->and($state['events']->pluck('metadata.legacy_pamb_event_id')->filter()->values()->all())->toBe([$legacyReturn->id]);
});

test('direct-to-PENRO meeting records use the shared PENRO-origin profile and omit CENRO actions', function (string $workflow): void {
    $owner = meetingSharedActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = meetingSharedActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $report = meetingSharedReport($workflow, $owner, true);
    $routing = app(DocumentRoutingTransitionService::class);
    $state = $routing->presentation($report->fresh()->load('protectedArea'), 'conservation', null, $owner);

    expect($state['profile']['key'])->toBe('canonical_direct_penro')
        ->and($state['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(collect($state['allowed_actions'])->pluck('key')->all())->toContain('receive_at_penro_records')
        ->and(collect($state['allowed_actions'])->pluck('key')->all())->not->toContain('forward_to_cenro_chief', 'forward_to_cenro_records');
    $routing->transition($report->fresh()->load('protectedArea'), 'conservation', 'receive_at_penro_records', $owner->id);
    $routing->transition($report->fresh()->load('protectedArea'), 'conservation', 'forward_to_office_penro', $owner->id);
    $routing->transition($report->fresh()->load('protectedArea'), 'conservation', 'receive_at_office_penro', $office->id);

    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->where('to_stage', DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)->exists())->toBeFalse()
        ->and($report->fresh()->date_report_released_cenro)->toBeNull()
        ->and($report->fresh()->date_received_penro)->not->toBeNull();
})->with(['regular_pamb', 'special_pamb', 'twc_meetings']);
