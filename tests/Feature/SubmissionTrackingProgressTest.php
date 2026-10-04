<?php

use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Models\DocumentRoutingEvent;
use App\Models\BmsReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function progressActor(string $category, string $office): User
{
    $user = User::factory()->create([
        'section' => $category,
        'office_designated' => $office,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
    ]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
    return $user;
}

function progressReport(string $name): BmsReportSubmission
{
    $creator = User::query()->firstOrFail();
    $area = ProtectedArea::create([
        'name' => $name,
        'short_name' => strtoupper(substr($name, 0, 3)),
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id'),
    ]);
    return BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Progress report',
        'document_type' => 'Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
    ]);
}

function progressEngpReport(): EngpReportSubmission
{
    return EngpReportSubmission::create([
        'workflow_key' => 'weekly_accomplishment',
        'office' => 'CENRO Mati',
        'activity_name' => 'ENGP Progress Report',
        'document_type' => 'Report',
        'reporting_year' => 2026,
        'period_key' => '2026-w1',
        'period_label' => 'Week 1',
        'deadline_submission' => '2026-08-20',
    ]);
}

test('processing percentage is profile-aware for ENGP and extended generic routing', function (): void {
    $focal = progressActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = progressActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = progressActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = progressActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = progressActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = progressActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = progressActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = progressActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $presenter = app(DocumentRoutingPresenter::class);

    $engp = progressEngpReport();
    $engpSteps = [
        [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records'],
        [$records, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
    ];
    expect($presenter->present($engp->fresh(), 'engp')['processing_percentage'])->toBe(0);
    DocumentRoutingEvent::create([
        'source_type' => 'engp', 'source_id' => $engp->id, 'workflow_key' => $engp->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
        'to_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS, 'from_office' => 'CENRO Records Unit',
        'to_office' => 'PENRO Records Unit', 'occurred_at' => now(), 'recorded_by' => $penroRecords->id,
    ]);
    $engpEvents = DocumentRoutingEvent::query()->where('source_type', 'engp')->where('source_id', $engp->id)->get();
    $penroView = $presenter->present($engp->fresh(), 'engp', null, $engpEvents);
    expect($penroView['processing_percentage'])->toBe(80)
        ->and($penroView['current_stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($penroView['next_expected_action'])->toBe('Forward to Office of the PENRO');

    $penroAction = collect(app(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::class)->actionProfile('engp')['actions'])
        ->firstWhere('key', 'forward_to_office_penro');
    expect($penroAction)->not->toBeNull()
        ->and($penroAction)->not->toHaveKey('internal_only');

    $extended = progressReport('Extended Progress PA');
    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $extended->id, 'workflow_key' => $extended->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_CENRO_RECORDS,
        'to_stage' => DocumentRoutingProfileRegistry::CENRO_RECORDS, 'from_office' => 'CENRO CDS Chief',
        'to_office' => 'CENRO Records Unit', 'occurred_at' => now(), 'recorded_by' => $records->id,
    ]);
    $extendedEvents = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $extended->id)->get();
    expect($presenter->present($extended->fresh(), 'bms', null, $extendedEvents)['processing_percentage'])->toBe(50);

    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $extended->id, 'workflow_key' => $extended->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
        'to_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS, 'from_office' => 'CENRO Records Unit',
        'to_office' => 'PENRO Records Unit', 'occurred_at' => now(), 'recorded_by' => $penroRecords->id,
    ]);
    $extendedEvents = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $extended->id)->get();
    expect($presenter->present($extended->fresh(), 'bms', null, $extendedEvents)['processing_percentage'])->toBe(80);

    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $extended->id, 'workflow_key' => $extended->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF,
        'to_stage' => DocumentRoutingProfileRegistry::CDS_CHIEF, 'from_office' => 'PENRO CDS Focal Person',
        'to_office' => 'PENRO CDS Chief', 'occurred_at' => now(), 'recorded_by' => $penroChief->id,
    ]);

    $extendedEvents = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $extended->id)->get();
    expect($presenter->present($extended->fresh(), 'bms', null, $extendedEvents)['processing_percentage'])->toBe(100);
    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $extended->id, 'workflow_key' => $extended->workflow_key,
        'event_key' => 'recommended', 'from_stage' => DocumentRoutingProfileRegistry::CDS_CHIEF,
        'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN,
        'from_office' => 'PENRO CDS Chief', 'to_office' => 'Office of the PENRO',
        'occurred_at' => now(), 'recorded_by' => $penroChief->id,
    ]);
    $extendedEvents = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $extended->id)->get();
    expect($presenter->present($extended->fresh(), 'bms', null, $extendedEvents)['processing_percentage'])->toBe(100);
});

test('PAMB Regular, Special TWG, and TWC Meeting timelines use the shared processing milestones', function (): void {
    $presenter = app(DocumentRoutingPresenter::class);
    $report = \App\Models\ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb',
        'activity_name' => 'Progress mapping regression',
        'date_report_released_cenro' => '2026-08-03',
    ]);

    $percentage = fn (string $stage): int => $presenter->presentPamb($report, [
        'routing_summary' => [],
        'timeline' => [['key' => $stage, 'stage_key' => $stage, 'status' => 'current', 'held_at' => 'PENRO CDS Chief']],
    ])['processing_percentage'];

    expect($percentage(\App\Services\SubmissionTracking\SubmissionTrackingService::CENRO_RELEASE))->toBe(35)
        ->and($percentage(\App\Services\SubmissionTracking\PambRoutingTimelineService::RECORDS_RECEIVED))->toBe(80)
        ->and($percentage(\App\Services\SubmissionTracking\PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBe(85)
        ->and($percentage(\App\Services\SubmissionTracking\PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF))->toBe(100)
        ->and($percentage(\App\Services\SubmissionTracking\PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO))->toBe(100)
        ->and($percentage(\App\Services\SubmissionTracking\PambRoutingTimelineService::RELEASED_TO_REGIONAL))->toBe(100);
});

test('PAMB terminal completion from the active timeline cycle reaches 100 percent in the shared presenter', function (): void {
    $timelineService = app(PambRoutingTimelineService::class);
    $presenter = app(DocumentRoutingPresenter::class);
    $workflows = ['regular_pamb', 'special_pamb', 'twc_meetings'];

    foreach ($workflows as $workflow) {
        $report = \App\Models\ConservationReportSubmission::create([
            'workflow_key' => $workflow,
            'activity_name' => 'PAMB terminal progress handoff',
            'date_report_released_cenro' => '2026-08-03',
        ]);
        \App\Models\PambRoutingEvent::query()->create([
            'conservation_report_submission_id' => $report->id,
            'workflow_key' => $workflow,
            'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL,
            'occurred_at' => '2026-08-10 09:00:00',
        ]);

        $timeline = $timelineService->present($report->fresh());
        $routing = $presenter->presentPamb($report->fresh(), $timeline);

        expect($timeline['routing_complete'])->toBeTrue()
            ->and(collect($timeline['timeline'])->contains(fn (array $stage): bool => ($stage['status'] ?? null) === 'current'))->toBeFalse()
            ->and($routing['processing_percentage'])->toBe(100);
    }
});

test('PAMB terminal event from an earlier cycle does not complete progress in an incomplete active correction cycle', function (): void {
    $timelineService = app(PambRoutingTimelineService::class);
    $presenter = app(DocumentRoutingPresenter::class);
    $workflow = 'regular_pamb';
    $report = \App\Models\ConservationReportSubmission::create([
        'workflow_key' => $workflow,
        'activity_name' => 'PAMB active-cycle progress boundary',
        'date_report_released_cenro' => '2026-08-03',
        'date_endorsed_regional' => '2026-08-10',
    ]);
    foreach ([
        [PambRoutingTimelineService::RELEASED_TO_REGIONAL, '2026-08-10 09:00:00'],
        [PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION, '2026-08-11 09:00:00'],
    ] as [$stage, $occurredAt]) {
        \App\Models\PambRoutingEvent::query()->create([
            'conservation_report_submission_id' => $report->id,
            'workflow_key' => $workflow,
            'stage_key' => $stage,
            'occurred_at' => $occurredAt,
        ]);
    }

    $incompleteActiveTimeline = $timelineService->present($report->fresh());
    $incompleteActiveRouting = $presenter->presentPamb($report->fresh(), $incompleteActiveTimeline);

    expect($incompleteActiveTimeline['routing_complete'])->toBeFalse()
        ->and($incompleteActiveRouting['processing_percentage'])->not->toBe(100);

    \App\Models\PambRoutingEvent::query()->create([
        'conservation_report_submission_id' => $report->id,
        'workflow_key' => $workflow,
        'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL.'__cycle_2',
        'occurred_at' => '2026-08-20 09:00:00',
    ]);
    $completedActiveTimeline = $timelineService->present($report->fresh());
    $completedActiveRouting = $presenter->presentPamb($report->fresh(), $completedActiveTimeline);

    expect($completedActiveTimeline['routing_complete'])->toBeTrue()
        ->and($completedActiveRouting['processing_percentage'])->toBe(100);
});

test('tracking progress bar retains its gradient, sweep, and compact status styles', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));
    $progress = file_get_contents(base_path('resources/js/Components/SubmissionTracking/SubmissionTrackingProgress.jsx'));
    $css = file_get_contents(base_path('resources/css/app.css'));

    expect($tracking)
        ->toContain('edats-tracking-current-marker__pulse')
        ->not->toContain('official-report-document-update')
        ->not->toContain('Official report document update')
        ->and($progress)
        ->toContain('h-3 w-full')
        ->toContain('bg-gradient-to-r from-green-800 via-emerald-700 to-blue-800')
        ->toContain('submission-tracking-progress__sweep')
        ->toContain('bg-slate-200')
        ->toContain('bg-slate-700')
        ->and($css)
        ->toContain('@keyframes edats-tracking-status-pulse')
        ->toContain('2.1s ease-out infinite')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});
