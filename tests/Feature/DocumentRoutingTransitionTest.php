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
    $penroFocal = routingActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
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
    performRouting($service, $report, 'receive_at_office_penro', $penroFocal);
    performRouting($service, $report, 'forward_to_tsd', $penroFocal);
    performRouting($service, $report, 'receive_at_tsd', $penroFocal);
    performRouting($service, $report, 'forward_to_cds', $penroFocal);
    performRouting($service, $report, 'receive_at_cds', $penroFocal);
    performRouting($service, $report, 'forward_back_to_office_penro', $penroFocal);
    performRouting($service, $report, 'receive_at_office_penro_return', $penroFocal);
    performRouting($service, $report, 'forward_to_penro_records_final', $penroFocal);
    performRouting($service, $report, 'receive_at_penro_records_final', $penroRecords);
    performRouting($service, $report, 'release_to_regional', $penroRecords);

    $state = $service->state($report->fresh(), 'bms');
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($report->fresh()->date_endorsed_regional)->not->toBeNull()
        ->and($report->fresh()->deadline_submission)->toBe($deadline)
        ->and(DocumentRoutingEvent::query()->count())->toBe(17);
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

test('direct PENRO protected-area routing has no fabricated CENRO stages', function (): void {
    $penroFocal = routingActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $report = routingReport('Mt. Hamiguitan Range Wildlife Sanctuary');
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $penroFocal);
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_ORIGIN)
        ->and(collect($state['actions'])->pluck('key')->all())->toContain('forward_from_penro_origin')
        ->and(collect($state['actions'])->pluck('key')->all())->not->toContain('forward_to_cenro_chief');
});

test('PAMO origin enters PENRO custody without a fabricated CENRO route', function (): void {
    $areaOwner = routingActor(OrganizationalAccessService::PAMO, 'PAMO');
    $area = ProtectedArea::create([
        'name' => 'PAMO Routing PA', 'short_name' => 'PRP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $areaOwner->id, 'updated_by' => $areaOwner->id,
    ]);
    $areaOwner->update(['protected_area_id' => $area->id]);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'PAMO routing report', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
    ]);
    $service = app(DocumentRoutingTransitionService::class);

    $state = $service->state($report, 'bms', null, $areaOwner);
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
        ->and(collect($state['actions'])->firstWhere('key', 'forward_from_pamo'))->not->toBeNull();

    performRouting($service, $report, 'forward_from_pamo', $areaOwner);
    expect($service->state($report->fresh(), 'bms')['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
});
