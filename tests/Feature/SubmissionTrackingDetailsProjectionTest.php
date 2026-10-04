<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function detailsProjectionActor(string $category, string $office): User
{
    $user = User::factory()->create([
        'section' => $category, 'office_designated' => $office,
        'unit_assignment' => 'conservation', 'is_active' => true, 'is_approved' => true,
    ]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    return $user;
}

function detailsProjectionReport(string $workflow, User $owner): ConservationReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Detail Projection '.$workflow, 'short_name' => 'DPPA',
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id, 'assignment_type' => 'supervising',
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
    ]);
    return ConservationReportSubmission::create([
        'workflow_key' => $workflow, 'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati', 'activity_name' => 'Meeting activity',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3',
        'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

test('a PENRO correction keeps its real current step and cannot reuse later checkpoints from the preceding cycle', function (string $workflow): void {
    $focal = detailsProjectionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $chief = detailsProjectionActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $office = detailsProjectionActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $report = detailsProjectionReport($workflow, $focal);
    $fixtures = [
        [$focal, 'forward_to_cds_chief', 'forwarded', 'penro_cds_focal', 'transit_to_cds_chief'],
        [$chief, 'receive_at_cds_chief', 'received', 'transit_to_cds_chief', 'penro_cds_chief'],
        [$chief, 'recommend_to_office_penro', 'recommended', 'penro_cds_chief', 'transit_to_office_of_penro_final'],
        [$office, 'receive_at_office_penro_final', 'received', 'transit_to_office_of_penro_final', 'office_of_penro_final'],
        [$office, 'return_from_office_for_correction', 'returned_for_correction', 'office_of_penro_final', 'penro_cds_focal'],
    ];
    foreach ($fixtures as $index => [$actor, $action, $type, $from, $to]) {
        DocumentRoutingEvent::query()->create([
            'source_type' => 'conservation', 'source_id' => $report->id, 'workflow_key' => $workflow,
            'event_key' => $type, 'from_stage' => $from, 'to_stage' => $to,
            'from_office' => $actor->office_designated, 'to_office' => 'PENRO Davao Oriental',
            'occurred_at' => '2026-09-01 09:0'.$index.':00', 'recorded_by' => $actor->id,
            'remarks' => $type === 'returned_for_correction' ? 'Correct the meeting minutes.' : null,
            'metadata' => ['action_key' => $action, 'pamb_cycle' => $index === 4 ? 2 : 1],
        ]);
    }

    $this->actingAs($focal);
    $routing = app(DocumentRoutingTransitionService::class);
    $projection = app(DocumentRoutingPresenter::class)->present($report->fresh(), 'conservation', null, $routing->events($report->fresh(), 'conservation'));
    expect($projection['current_stage'])->toBe(DocumentRoutingProfileRegistry::CDS_FOCAL)
        ->and(collect($projection['timeline'])->firstWhere('status', 'current')['key'])->toBe(DocumentRoutingProfileRegistry::CDS_FOCAL)
        ->and(collect($projection['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::CDS_CHIEF)['status'])->toBe('pending')
        ->and($projection['last_action']['label'])->toBe('Returned for Correction')
        ->and(collect($projection['actions'])->pluck('key')->all())->toBe(['receive_correction'])
        ->and($projection['routing_history'])->toHaveCount(5);

    $url = route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $report->id]);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('trackingContext.selected_record.routing.current_stage', DocumentRoutingProfileRegistry::CDS_FOCAL)
        ->where('trackingContext.selected_record.routing.actions.0.key', 'receive_correction')
        ->where('trackingContext.selected_record.can_transition', true));
    $this->actingAs($office)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_correction']), ['stage' => 'receive_correction'])->assertForbidden();
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(5);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Manila'));
    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_correction']), ['stage' => 'receive_correction'])->assertRedirect()->assertSessionHasNoErrors();
    expect(collect($routing->presentation($report->fresh(), 'conservation', null, $focal)['allowed_actions'])->pluck('key')->all())->toContain('forward_to_cds_chief');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(6);
})->with(['regular_pamb', 'special_pamb', 'twc_meetings']);

test('ENGP preparation and CENRO handoff details show the stored office rather than a role name', function (): void {
    $focal = detailsProjectionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $chief = detailsProjectionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga');
    $report = \App\Models\EngpReportSubmission::create([
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'Watershed field verification', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'Q3', 'period_label' => 'Quarter 3',
        'deadline_submission' => '2026-10-15',
        'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    $this->actingAs($focal);
    $presenter = app(DocumentRoutingPresenter::class);
    $routing = app(DocumentRoutingTransitionService::class);
    $initial = $presenter->present($report, 'engp', null, collect());
    expect($initial['current_location'])->toBe('CENRO Baganga')
        ->and($initial['responsible_office'])->toBe('CENRO Baganga')
        ->and(collect($initial['actions'])->pluck('key')->all())->toContain('forward_to_cenro_chief');
    $routing->transition($report, 'engp', 'forward_to_cenro_chief', $focal->id);
    $this->actingAs($chief);
    $transit = $presenter->present($report->fresh(), 'engp', null, $routing->events($report->fresh(), 'engp'));
    expect($transit['responsible_office'])->toBe('CENRO Baganga')
        ->and(collect($transit['actions'])->pluck('key')->all())->toContain('receive_at_cenro_chief')
        ->and($report->fresh()->date_received_penro)->toBeNull();
});

test('approved legacy MOV release ownership does not replace canonical focal custody actions', function (string $workflow): void {
    Storage::fake('local');
    Storage::disk('local')->put('details-projection/approved.pdf', "%PDF-1.4\nIsolated MOV fixture");
    $focal = detailsProjectionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $focal->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    $records = detailsProjectionActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $chief = detailsProjectionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $report = detailsProjectionReport($workflow, $focal);
    $report->update([
        'date_report_released_cenro' => null, 'date_received_penro' => null,
        'mov_file_path' => 'details-projection/approved.pdf', 'mov_processing_status' => 'activity_conducted',
    ]);
    $routing = app(DocumentRoutingTransitionService::class);
    $pambAccess = app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class);
    foreach (['activity_conducted', 'submitted_for_review', 'needs_correction'] as $blockedStatus) {
        $report->update(['mov_processing_status' => $blockedStatus]);
        expect($pambAccess->canPerformForSubmission($records, 'release', $report->fresh()))->toBeFalse();
    }
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(0);
    $report->update(['mov_processing_status' => 'ready_for_release']);

    $routing = app(DocumentRoutingTransitionService::class);
    expect($routing->state($report->fresh(), 'conservation', null, $focal)['stage'])->toBe(DocumentRoutingProfileRegistry::PREPARATION)
        ->and(collect($routing->presentation($report->fresh(), 'conservation', null, $focal)['allowed_actions'])->pluck('key')->all())->toBe(['forward_to_cenro_chief'])
        ->and($report->fresh()->date_report_released_cenro)->toBeNull()
        ->and($report->fresh()->date_received_penro)->toBeNull()
        ->and($report->fresh()->routingEvents)->toHaveCount(0);

    $indexUrl = route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $report->id]);
    $this->actingAs($focal)->get($indexUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('trackingContext.selected_record.routing.current_stage', DocumentRoutingProfileRegistry::PREPARATION)
        ->where('trackingContext.selected_record.routing.responsible_user_category', 'CENRO CDS Focal Person')
        ->where('trackingContext.selected_record.routing.actions.0.key', 'forward_to_cenro_chief')
        ->where('trackingContext.selected_record.can_transition', true)
        ->has('workspaceQueues.incoming', fn (Assert $rows) => $rows->where('0.source', 'conservation')->where('0.source_id', $report->id))
        ->where('trackingContext.selected_record.pamb_action_flags.can_release', false));
    $this->actingAs($records)->get(route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $report->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('trackingContext.selected_record.pamb_action_flags.can_release', true)
        ->where('trackingContext.selected_record.can_transition', true));

    $eventCount = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])->assertForbidden();
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($eventCount);

    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $event = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->sole();
    expect($event->event_key)->toBe('forwarded')
        ->and($event->from_stage)->toBe(DocumentRoutingProfileRegistry::PREPARATION)
        ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)
        ->and($report->fresh()->date_report_released_cenro)->toBeNull()
        ->and($report->fresh()->date_received_penro)->toBeNull();

    $chiefUrl = route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $report->id]);
    $this->actingAs($chief)->get($chiefUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('trackingContext.selected_record.routing.current_stage', DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)
        ->where('trackingContext.selected_record.routing.actions.0.key', 'receive_at_cenro_chief')
        ->where('trackingContext.selected_record.can_transition', true));

    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])
        ->assertRedirect()->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(1)
        ->and($pambAccess->canPerformForSubmission($records, 'release', $report->fresh()))->toBeTrue();
})->with(['regular_pamb', 'special_pamb', 'twc_meetings']);
