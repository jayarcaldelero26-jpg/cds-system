<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Illuminate\Validation\ValidationException;
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

test('generic CENRO custody route persists separate forward and receive events', function (): void {
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
    performRouting($service, $report, 'forward_to_office_penro', $penroRecords);
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

test('generic transition endpoint records a server-timestamped action without a user date', function (): void {
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = routingReport();

    $response = $this->actingAs($focal)->post(route('submission-tracking.transition', [
        'source' => 'bms', 'record' => $report->id, 'stage' => 'forward_to_cenro_chief',
    ]), ['stage' => 'forward_to_cenro_chief', 'remarks' => 'Ready for custody handoff.']);

    $response->assertRedirect();
    expect(DocumentRoutingEvent::query()->first())->toMatchArray([
        'event_key' => 'forwarded', 'recorded_by' => $focal->id,
        'remarks' => 'Ready for custody handoff.',
    ]);
});

test('legacy PAMO accounts do not create a canonical generic routing stage 1', function (): void {
    $pamo = routingActor(OrganizationalAccessService::PAMO, '');
    $report = routingReport('Mt. Hamiguitan Range Wildlife Sanctuary');
    $pamo->update(['protected_area_id' => $report->protected_area_id]);
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $pamo);
    $presentation = $service->presentation($report, 'bms', null, $pamo);

    expect($state['stage'])->not->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
        ->and(collect($presentation['allowed_actions'])->pluck('key')->all())->not->toContain('forward_from_pamo')
        ->and(collect($state['actions'])->pluck('key')->all())->not->toContain('forward_to_cenro_chief');
});
test('legacy PAMO accounts do not create a canonical generic routing stage 2', function (): void {
    $pamo = routingActor(OrganizationalAccessService::PAMO, '');
    $report = routingReport('Mt. Hamiguitan Range Wildlife Sanctuary');
    $pamo->update(['protected_area_id' => $report->protected_area_id]);
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $pamo);
    $presentation = $service->presentation($report, 'bms', null, $pamo);

    expect($state['stage'])->not->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
        ->and(collect($presentation['allowed_actions'])->pluck('key')->all())->not->toContain('forward_from_pamo')
        ->and(collect($state['actions'])->pluck('key')->all())->not->toContain('forward_to_cenro_chief');
});
test('legacy PAMO accounts do not create a canonical generic routing stage 3', function (): void {
    $pamo = routingActor(OrganizationalAccessService::PAMO, '');
    $report = routingReport('Mt. Hamiguitan Range Wildlife Sanctuary');
    $pamo->update(['protected_area_id' => $report->protected_area_id]);
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $pamo);
    $presentation = $service->presentation($report, 'bms', null, $pamo);

    expect($state['stage'])->not->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
        ->and(collect($presentation['allowed_actions'])->pluck('key')->all())->not->toContain('forward_from_pamo')
        ->and(collect($state['actions'])->pluck('key')->all())->not->toContain('forward_to_cenro_chief');
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
