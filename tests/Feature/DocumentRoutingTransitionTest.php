<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\SubmissionRoutingAttachment;
use App\Models\ProtectedArea;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

function routingActor(string $category, string $office, string $permission = 'bms.update'): User
{
    $user = User::factory()->create([
        'section' => $category,
        'office_designated' => $office,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
    ]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    return $user;
}

function routingReport(string $areaName = 'Routing PA'): BmsReportSubmission
{
    $creator = User::query()->firstOrFail();
    $area = ProtectedArea::create([
        'name' => $areaName, 'short_name' => strtoupper(substr($areaName, 0, 3)),
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'Region XI', 'created_by' => $creator->id, 'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id')]);
    return BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Routing report', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
    ]);
}

function performRouting(DocumentRoutingTransitionService $service, BmsReportSubmission $report, string $action, User $actor): void
{
    $service->transition($report, 'bms', $action, $actor->id);
}

test('generic CENRO custody route atomically receives and hands off at PENRO Records', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $officePenro = routingActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsdChief = routingActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = routingActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = routingActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $report = routingReport();
    $service = app(DocumentRoutingTransitionService::class);
    $deadline = $report->deadline_submission;

    performRouting($service, $report, 'forward_to_cenro_chief', $focal);
    expect(DocumentRoutingEvent::query()->count())->toBe(1)
        ->and(DocumentRoutingEvent::query()->first()->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)
        ->and($report->fresh()->date_received_penro)->toBeNull();

    expect(fn () => performRouting($service, $report, 'forward_to_cenro_chief', $focal))
        ->toThrow(ValidationException::class);
    expect(DocumentRoutingEvent::query()->count())->toBe(1);

    expect(fn () => performRouting($service, $report, 'receive_at_cenro_chief', $focal))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'receive_at_cenro_chief', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);
    performRouting($service, $report, 'receive_at_cenro_records', $cenroRecords);
    performRouting($service, $report, 'forward_to_penro_records', $cenroRecords);

    $transit = $service->state($report->fresh(), 'bms');
    expect($transit['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and($report->fresh()->date_report_released_cenro)->not->toBeNull()
        ->and($report->fresh()->date_received_penro)->toBeNull();

    performRouting($service, $report, 'receive_at_penro_records', $penroRecords);
    expect($report->fresh()->date_received_penro)->not->toBeNull();
    $ordinaryPenroActions = $service->presentation($report->fresh(), 'bms', null, $penroRecords)['allowed_actions'];
    expect(collect($ordinaryPenroActions)->pluck('key')->all())
        ->not->toContain('forward_to_office_penro')
        ->not->toContain('release_to_regional');
    expect(fn () => performRouting($service, $report, 'receive_at_office_penro', $penroFocal))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'receive_at_office_penro', $officePenro);

    expect(fn () => performRouting($service, $report, 'assign_to_tsd_chief', $tsdChief))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'assign_to_tsd_chief', $officePenro);

    expect(fn () => performRouting($service, $report, 'receive_at_tsd_chief', $officePenro))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'receive_at_tsd_chief', $tsdChief);

    expect(fn () => performRouting($service, $report, 'forward_to_cds_focal', $penroFocal))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'forward_to_cds_focal', $tsdChief);

    expect(fn () => performRouting($service, $report, 'receive_at_cds_focal', $tsdChief))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'receive_at_cds_focal', $penroFocal);

    expect(fn () => performRouting($service, $report, 'forward_to_cds_chief', $penroChief))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'forward_to_cds_chief', $penroFocal);

    expect(fn () => performRouting($service, $report, 'receive_at_cds_chief', $penroFocal))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'receive_at_cds_chief', $penroChief);

    expect(fn () => performRouting($service, $report, 'recommend_to_office_penro', $officePenro))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'recommend_to_office_penro', $penroChief);

    expect(fn () => performRouting($service, $report, 'receive_at_office_penro_final', $penroChief))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'receive_at_office_penro_final', $officePenro);

    expect(fn () => performRouting($service, $report, 'approve_for_regional_release', $penroChief))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'approve_for_regional_release', $officePenro);

    expect(fn () => performRouting($service, $report, 'receive_at_penro_records_final', $officePenro))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'receive_at_penro_records_final', $penroRecords);

    expect(fn () => performRouting($service, $report, 'release_to_regional', $officePenro))
        ->toThrow(HttpException::class);
    performRouting($service, $report, 'release_to_regional', $penroRecords);

    $state = $service->state($report->fresh(), 'bms');
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($report->fresh()->date_endorsed_regional)->not->toBeNull()
        ->and($report->fresh()->deadline_submission)->toBe($deadline)
        ->and(DocumentRoutingEvent::query()->count())->toBe(19);
});

test('correction recipient must receive before corrected resubmission', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = routingReport('Correction state machine PA');
    $service = app(DocumentRoutingTransitionService::class);

    performRouting($service, $report, 'forward_to_cenro_chief', $focal);
    performRouting($service, $report, 'receive_at_cenro_chief', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);
    performRouting($service, $report, 'receive_at_cenro_records', $cenroRecords);
    performRouting($service, $report, 'forward_to_penro_records', $cenroRecords);
    $service->transition($report, 'bms', 'return_for_correction_penro_records', $penroRecords->id, null, 'missing_received_copy');
    $returned = $service->presentation($report->fresh(), 'bms', null, $cenroRecords)['allowed_actions'];
    expect(collect($returned)->pluck('key')->all())->toBe(['receive_correction']);
    test()->actingAs($cenroRecords);
    $tracking = app(\App\Services\SubmissionTracking\SubmissionTrackingService::class);
    $returnedRow = $tracking->workspaceQueues()['incoming']
        ->firstWhere(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id);
    expect($returnedRow['routing']['next_expected_action'])->toBe('Receive Correction')
        ->and($returnedRow['incoming_action_category'])->toBe('correction')
        ->and(collect($returnedRow['routing']['actions'])->pluck('key')->all())->toBe(['receive_correction']);
    test()->actingAs($penroRecords);
    $outgoingRow = $tracking->workspaceQueues()['outgoing']
        ->firstWhere(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id);
    expect($outgoingRow)->not->toBeNull()
        ->and($outgoingRow['routing']['actions'])->toBe([]);
    test()->actingAs($cenroRecords);

    $service->transition($report, 'bms', 'receive_correction', $cenroRecords->id);
    $received = $service->presentation($report->fresh(), 'bms', null, $cenroRecords)['allowed_actions'];
    expect(collect($received)->pluck('key')->all())->toContain('forward_to_penro_records')
        ->and(collect($received)->firstWhere('key', 'forward_to_penro_records')['action_label'])->toBe('Resubmit Corrected Copy');
    $receivedRow = $tracking->workspaceQueues()['incoming']
        ->firstWhere(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id);
    expect($receivedRow['routing']['next_expected_action'])->toBe('Resubmit Corrected Copy')
        ->and(collect($receivedRow['routing']['actions'])->pluck('key')->all())->toBe(['forward_to_penro_records']);

    $service->transition($report, 'bms', 'forward_to_penro_records', $cenroRecords->id);
    $penroActions = $service->presentation($report->fresh(), 'bms', null, $penroRecords)['allowed_actions'];
    expect(collect($penroActions)->pluck('key')->all())->toContain('receive_at_penro_records', 'return_for_correction_penro_records');
});

test('generic transition endpoint records a server-timestamped action without a user date', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = routingReport();

    $response = $this->actingAs($focal)->post(route('submission-tracking.transition', [
        'source' => 'bms', 'record' => $report->id, 'stage' => 'forward_to_cenro_chief',
    ]), ['stage' => 'forward_to_cenro_chief', 'remarks' => 'Ready for custody handoff.', 'date' => '2001-01-01']);

    $response->assertRedirect();
    $event = DocumentRoutingEvent::query()->first();
    expect($event)->not->toBeNull()
        ->and($event)->toMatchArray([
            'event_key' => 'forwarded', 'recorded_by' => $focal->id,
            'remarks' => 'Ready for custody handoff.',
        ])
        ->and($event->occurred_at->toDateString())->toBe(now()->toDateString())
        ->and($event->occurred_at->toDateString())->not->toBe('2001-01-01');
});

test('legacy PAMO accounts do not create a canonical generic routing stage 1', function (): void {
    $pamo = routingActor(OrganizationalAccessService::PAMO, '');
    $report = routingReport('Mt. Hamiguntan Range Wildlife Sanctuary');
    $pamo->update(['protected_area_id' => $report->protected_area_id]);
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $pamo);
    $presentation = $service->presentation($report, 'bms', null, $pamo);

    expect($state['stage'])->not->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
        ->and(collect($presentation['allowed_actions'])->pluck('key')->all())->not->toContain('forward_from_pamo');
});

test('legacy PAMO accounts do not create a canonical generic routing stage 2', function (): void {
    $pamo = routingActor(OrganizationalAccessService::PAMO, '');
    $report = routingReport('Mt. Hamiguntan Range Wildlife Sanctuary');
    $pamo->update(['protected_area_id' => $report->protected_area_id]);
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $pamo);
    $presentation = $service->presentation($report, 'bms', null, $pamo);

    expect($state['stage'])->not->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
        ->and(collect($presentation['allowed_actions'])->pluck('key')->all())->not->toContain('forward_from_pamo');
});

test('legacy PAMO accounts do not create a canonical generic routing stage 3', function (): void {
    $pamo = routingActor(OrganizationalAccessService::PAMO, '');
    $report = routingReport('Mt. Hamiguntan Range Wildlife Sanctuary');
    $pamo->update(['protected_area_id' => $report->protected_area_id]);
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $pamo);
    $presentation = $service->presentation($report, 'bms', null, $pamo);

    expect($state['stage'])->not->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
        ->and(collect($presentation['allowed_actions'])->pluck('key')->all())->not->toContain('forward_from_pamo');
});

test('legacy milestone state starts the canonical routing timeline at its current stage', function (): void {
    routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = routingReport();
    $deadline = $report->deadline_submission;
    $report->update([
        'date_report_released_cenro' => '2026-08-04',
        'date_received_penro' => '2026-08-05',
    ]);

    $record = $report->fresh();
    $state = app(DocumentRoutingTransitionService::class)->presentation($record, 'bms', collect());

    expect($state)->toHaveKeys(['stage', 'bootstrapped', 'events', 'profile', 'actions', 'allowed_actions', 'capabilities'])
        ->and($state['bootstrapped'])->toBeTrue()
        ->and($state['stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($state['events'])->toBeEmpty();

    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)->present($record, 'bms', collect(), collect());
    $canonicalTimeline = collect($routing['timeline'])->filter(fn (array $item): bool => ! str_starts_with($item['key'], 'legacy:'))->values();

    expect($canonicalTimeline->first()['key'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($canonicalTimeline->first()['status'])->toBe('current')
        ->and($routing['current_location'])->toBe('PENRO Records Unit')
        ->and($record->fresh()->deadline_submission)->toBe($deadline)
        ->and($record->fresh()->date_received_penro->toDateString())->toBe('2026-08-05');
});

test('Records correction returns to the immediate sender and resubmission preserves the correction cycle', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = routingReport('Receipt Correction PA');
    $service = app(DocumentRoutingTransitionService::class);

    performRouting($service, $report, 'forward_to_cenro_chief', $focal);
    performRouting($service, $report, 'receive_at_cenro_chief', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);
    $cenroActions = $service->presentation($report->fresh(), 'bms', null, $cenroRecords)['allowed_actions'];
    expect(collect($cenroActions)->pluck('key'))->toContain('receive_at_cenro_records', 'return_for_correction_cenro_records');
    $returned = $service->transition($report, 'bms', 'return_for_correction_cenro_records', $cenroRecords->id, null, 'missing_signature', 'Signature page missing');
    expect($returned->to_stage)->toBe(DocumentRoutingProfileRegistry::CENRO_CHIEF)
        ->and(data_get($returned->metadata, 'correction_reason_key'))->toBe('missing_signature')
        ->and($service->state($report->fresh(), 'bms')['stage'])->toBe(DocumentRoutingProfileRegistry::CENRO_CHIEF);

    performRouting($service, $report, 'receive_correction', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);
    expect($service->state($report->fresh(), 'bms')['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_CENRO_RECORDS)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_id', $report->id)->where('event_key', 'returned_for_correction')->count())->toBe(1)
        ->and($report->fresh()->date_received_penro)->toBeNull();

    performRouting($service, $report, 'receive_at_cenro_records', $cenroRecords);
    performRouting($service, $report, 'forward_to_penro_records', $cenroRecords);
    $penroActions = $service->presentation($report->fresh(), 'bms', null, $penroRecords)['allowed_actions'];
    expect(collect($penroActions)->pluck('key'))->toContain('receive_at_penro_records', 'return_for_correction_penro_records');
    test()->actingAs($penroRecords);
    $normalized = app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->records()
        ->first(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id);
    expect($normalized)->not->toBeNull()
        ->and($normalized['routing']['current_stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and($normalized['routing']['responsible_user_category'])->toBe('PENRO Records Unit')
        ->and($normalized['can_transition'])->toBeTrue()
        ->and(collect($normalized['routing']['actions'])->pluck('key')->all())->toContain('receive_at_penro_records', 'return_for_correction_penro_records')
        ->and(collect($normalized['routing']['actions'])->firstWhere('key', 'return_for_correction_penro_records')['attachment_allowed'])->toBeFalse();
    $returnedToCenro = $service->transition($report, 'bms', 'return_for_correction_penro_records', $penroRecords->id, null, 'missing_received_copy');
    expect($returnedToCenro->to_stage)->toBe(DocumentRoutingProfileRegistry::CENRO_RECORDS)
        ->and(data_get($returnedToCenro->metadata, 'correction_reason_key'))->toBe('missing_received_copy')
        ->and($report->fresh()->date_received_penro)->toBeNull()
        ->and($service->state($report->fresh(), 'bms')['stage'])->toBe(DocumentRoutingProfileRegistry::CENRO_RECORDS);
    performRouting($service, $report, 'receive_correction', $cenroRecords);
    expect($service->state($report->fresh(), 'bms')['correction'])->toBeTrue();
});

test('Records correction HTTP action accepts server-timed reasoned requests without a date', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = routingReport('HTTP Receipt Correction PA');
    $service = app(DocumentRoutingTransitionService::class);

    performRouting($service, $report, 'forward_to_cenro_chief', $focal);
    performRouting($service, $report, 'receive_at_cenro_chief', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);
    performRouting($service, $report, 'receive_at_cenro_records', $cenroRecords);
    performRouting($service, $report, 'forward_to_penro_records', $cenroRecords);

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $report->id, 'stage' => 'return_for_correction_penro_records']), [
        'stage' => 'return_for_correction_penro_records',
        'correction_reason_key' => 'missing_endorsement',
    ])->assertSessionHasNoErrors();

    $event = DocumentRoutingEvent::query()->where('source_id', $report->id)->where('event_key', 'returned_for_correction')->latest('id')->firstOrFail();
    expect($event->occurred_at)->not->toBeNull()
        ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::CENRO_RECORDS)
        ->and(data_get($event->metadata, 'correction_reason_key'))->toBe('missing_endorsement')
        ->and(SubmissionRoutingAttachment::query()->where('document_routing_event_id', $event->id)->count())->toBe(0);
});

test('Records correction accepts an optional reference attachment without replacing the source document', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = routingReport('HTTP Correction Attachment PA');
    $service = app(DocumentRoutingTransitionService::class);
    performRouting($service, $report, 'forward_to_cenro_chief', $focal);
    performRouting($service, $report, 'receive_at_cenro_chief', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);
    performRouting($service, $report, 'receive_at_cenro_records', $cenroRecords);
    performRouting($service, $report, 'forward_to_penro_records', $cenroRecords);
    test()->actingAs($penroRecords);
    $beforeReturn = app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->records()
        ->firstWhere(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id);
    $returnAction = collect($beforeReturn['routing']['actions'])->firstWhere('key', 'return_for_correction_penro_records');
    expect($returnAction['correction_reference_allowed'])->toBeTrue()
        ->and($returnAction['attachment_allowed'])->toBeFalse();

    $response = $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $report->id, 'stage' => 'return_for_correction_penro_records']), [
        'stage' => 'return_for_correction_penro_records',
        'correction_reason_key' => 'missing_received_copy',
        'attachment' => UploadedFile::fake()->create('marked-up-copy.pdf', 10, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $response->assertRedirect();
    $event = DocumentRoutingEvent::query()->where('source_id', $report->id)->where('event_key', 'returned_for_correction')->latest('id')->firstOrFail();
    $attachment = SubmissionRoutingAttachment::query()->where('document_routing_event_id', $event->id)->first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->purpose)->toBe('correction_reference')
        ->and($report->fresh()->mov_file_path)->toBe($report->mov_file_path);
});

test('Records correction HTTP action validates reason detail and rejects attachments', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = routingReport('HTTP Receipt Correction Validation PA');
    $service = app(DocumentRoutingTransitionService::class);
    performRouting($service, $report, 'forward_to_cenro_chief', $focal);
    performRouting($service, $report, 'receive_at_cenro_chief', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);
    performRouting($service, $report, 'receive_at_cenro_records', $cenroRecords);
    performRouting($service, $report, 'forward_to_penro_records', $cenroRecords);

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $report->id, 'stage' => 'return_for_correction_penro_records']), [
        'stage' => 'return_for_correction_penro_records',
        'correction_reason_key' => 'other',
    ])->assertSessionHasErrors('correction_detail');
});

test('CENRO Records correction HTTP action uses the shared reasoned contract', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $report = routingReport('HTTP CENRO Correction PA');
    $service = app(DocumentRoutingTransitionService::class);
    performRouting($service, $report, 'forward_to_cenro_chief', $focal);
    performRouting($service, $report, 'receive_at_cenro_chief', $chief);
    performRouting($service, $report, 'forward_to_cenro_records', $chief);

    $this->actingAs($cenroRecords)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $report->id, 'stage' => 'return_for_correction_cenro_records']), [
        'stage' => 'return_for_correction_cenro_records',
        'correction_reason_key' => 'missing_signature',
    ])->assertSessionHasNoErrors();

    $event = DocumentRoutingEvent::query()->where('source_id', $report->id)->where('event_key', 'returned_for_correction')->latest('id')->firstOrFail();
    expect($event->to_stage)->toBe(DocumentRoutingProfileRegistry::CENRO_CHIEF)
        ->and(data_get($event->metadata, 'correction_reason_key'))->toBe('missing_signature');
});
