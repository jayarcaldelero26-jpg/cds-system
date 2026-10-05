<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\PambRoutingEvent;
use App\Models\User;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function routingCorrectionActor(string $category): User
{
    $office = str_starts_with($category, 'CENRO_') ? 'CENRO Mati' : 'PENRO Davao Oriental';

    return User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => $category,
        'office_designated' => $office,
    ]);
}

function routingCorrectionAdmin(string $password = 'secret-password'): User
{
    $admin = User::factory()->create(['password' => $password, 'section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));

    return $admin;
}

function routingCorrectionCycleFixture(object $test, string $workflow = 'regular_pamb'): array
{
    Notification::fake();

    $actors = [
        OrganizationalAccessService::PENRO_RECORDS => routingCorrectionActor(OrganizationalAccessService::PENRO_RECORDS),
        OrganizationalAccessService::OFFICE_PENRO => routingCorrectionActor(OrganizationalAccessService::OFFICE_PENRO),
        OrganizationalAccessService::PENRO_TSD_CHIEF => routingCorrectionActor(OrganizationalAccessService::PENRO_TSD_CHIEF),
        OrganizationalAccessService::PENRO_FOCAL => routingCorrectionActor(OrganizationalAccessService::PENRO_FOCAL),
        OrganizationalAccessService::PENRO_CHIEF => routingCorrectionActor(OrganizationalAccessService::PENRO_CHIEF),
    ];
    $report = ConservationReportSubmission::create([
        'workflow_key' => $workflow,
        'activity_name' => 'Routing correction cycle fixture',
        'target_office' => 'CENRO Mati',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04',
        'date_received_penro' => '2026-08-05',
        'created_by' => $test->user->id,
        'updated_by' => $test->user->id,
    ]);

    $timeline = app(PambRoutingTimelineService::class);
    $timeline->recordCanonical(
        $report,
        SubmissionTrackingService::CENRO_RELEASE,
        '2026-08-04',
        $actors[OrganizationalAccessService::PENRO_RECORDS]->id,
        CarbonImmutable::parse('2026-08-04 09:00:00', 'Asia/Manila'),
    );
    $timeline->recordCanonical(
        $report,
        SubmissionTrackingService::PENRO_RECEIPT,
        '2026-08-05',
        $actors[OrganizationalAccessService::PENRO_RECORDS]->id,
        CarbonImmutable::parse('2026-08-05 09:00:00', 'Asia/Manila'),
    );

    $firstCycle = [
        [PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, OrganizationalAccessService::PENRO_RECORDS, '2026-08-05 10:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 11:00:00'],
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 12:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_TSD, OrganizationalAccessService::PENRO_TSD_CHIEF, '2026-08-05 13:00:00'],
        [PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, OrganizationalAccessService::PENRO_TSD_CHIEF, '2026-08-05 14:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_CDS, OrganizationalAccessService::PENRO_FOCAL, '2026-08-05 15:00:00'],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, OrganizationalAccessService::PENRO_FOCAL, '2026-08-05 16:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, OrganizationalAccessService::PENRO_CHIEF, '2026-08-05 17:00:00'],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, OrganizationalAccessService::PENRO_CHIEF, '2026-08-05 18:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 19:00:00'],
        [PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 20:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_CDS.'__cycle_2', OrganizationalAccessService::PENRO_FOCAL, '2026-08-06 09:00:00'],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF.'__cycle_2', OrganizationalAccessService::PENRO_FOCAL, '2026-08-06 10:00:00'],
    ];

    foreach ($firstCycle as [$stage, $category, $occurredAt]) {
        $timeline->record($report, $stage, $occurredAt, $actors[$category]->id);
    }

    $admin = routingCorrectionAdmin();

    return [$report->fresh(), $admin];
}

function routingCorrectionCompletedSecondCycleFixture(object $test, string $workflow = 'regular_pamb'): array
{
    [$report, $admin] = routingCorrectionCycleFixture($test, $workflow);
    $timeline = app(PambRoutingTimelineService::class);
    $chief = routingCorrectionActor(OrganizationalAccessService::PENRO_CHIEF);
    $office = routingCorrectionActor(OrganizationalAccessService::OFFICE_PENRO);
    $records = routingCorrectionActor(OrganizationalAccessService::PENRO_RECORDS);

    $report->update([
        'date_report_released_cenro' => '2026-09-29',
        'date_received_penro' => '2026-09-29',
        'date_endorsed_regional' => null,
    ]);
    foreach ($report->routingEvents()->get() as $event) {
        $stageKey = (string) $event->stage_key;
        if ($timeline->stageCycle($stageKey) === 1) {
            $event->update(['occurred_at' => '2026-09-29 '.$event->occurred_at->format('H:i:s')]);
        } elseif ($stageKey === PambRoutingTimelineService::RECEIVED_BY_CDS.'__cycle_2') {
            $event->update(['occurred_at' => '2026-09-30 08:00:00']);
        } elseif ($stageKey === PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF.'__cycle_2') {
            $event->update(['occurred_at' => '2026-09-30 08:30:00']);
        }
    }
    foreach ([
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF.'__cycle_2', $chief, '2026-09-30 09:00:00'],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO.'__cycle_2', $chief, '2026-09-30 10:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL.'__cycle_2', $office, '2026-09-30 11:00:00'],
        [PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL.'__cycle_2', $office, '2026-09-30 12:00:37'],
        [PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL.'__cycle_2', $records, '2026-09-30 13:00:00'],
    ] as [$stageKey, $actor, $occurredAt]) {
        $timeline->record($report->fresh(), $stageKey, $occurredAt, $actor->id);
    }
    $report->update(['date_endorsed_regional' => '2026-09-30']);
    $timeline->recordCanonical(
        $report->fresh(),
        SubmissionTrackingService::REGIONAL_ENDORSEMENT,
        '2026-09-30',
        $records->id,
        CarbonImmutable::parse('2026-09-30 14:00:00', 'Asia/Manila'),
    );

    return [$report->fresh(), $admin];
}

beforeEach(function (): void {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Storage::fake('public');
    config(['services.document_archive.driver' => 'fake', 'services.google_drive_archive.enabled' => true]);
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach (['reports.view', 'technical-reports.update'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

test('date-only corrections preserve completed Regular, Special and TWC custody while refreshing their business-date projection', function (): void {
    Notification::fake();
    $categories = [
        OrganizationalAccessService::CENRO_RECORDS,
        OrganizationalAccessService::PENRO_RECORDS,
        OrganizationalAccessService::OFFICE_PENRO,
        OrganizationalAccessService::PENRO_TSD_CHIEF,
        OrganizationalAccessService::PENRO_FOCAL,
        OrganizationalAccessService::PENRO_CHIEF,
        OrganizationalAccessService::CENRO_CHIEF,
    ];
    $actors = collect($categories)->mapWithKeys(fn (string $category): array => [$category => routingCorrectionActor($category)]);
    $timeline = app(PambRoutingTimelineService::class);
    $admin = routingCorrectionAdmin();
    $reports = [];

    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $index => $workflow) {
        $report = ConservationReportSubmission::create([
            'workflow_key' => $workflow,
            'activity_name' => 'Completed '.$workflow.' correction fixture',
            'target_office' => 'CENRO Mati',
            'date_conducted' => '2026-08-03',
            'date_accomplished' => '2026-08-03',
            'date_report_released_cenro' => '2026-08-04',
            'date_received_penro' => '2026-08-05',
            'mov_processing_status' => PambMovProcessingService::READY_FOR_RELEASE,
            'mov_reviewed_at' => '2026-08-03 16:00:00',
            'mov_reviewed_by' => $actors[OrganizationalAccessService::CENRO_CHIEF]->id,
            'mov_file_name' => 'minutes.pdf',
            'mov_file_path' => 'routing-correction/'.$workflow.'.pdf',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $timeline->recordCanonical(
            $report,
            SubmissionTrackingService::CENRO_RELEASE,
            '2026-08-04',
            $actors[OrganizationalAccessService::CENRO_RECORDS]->id,
            CarbonImmutable::parse('2026-08-04 09:00:00', 'Asia/Manila'),
        );
        $timeline->recordCanonical(
            $report,
            SubmissionTrackingService::PENRO_RECEIPT,
            '2026-08-05',
            $actors[OrganizationalAccessService::PENRO_RECORDS]->id,
            CarbonImmutable::parse('2026-08-05 09:00:00', 'Asia/Manila'),
        );

        $route = [
            [PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, OrganizationalAccessService::PENRO_RECORDS, '2026-08-05 10:00:00'],
            [PambRoutingTimelineService::RECEIVED_BY_PENRO, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 11:00:00'],
            [PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 12:00:00'],
            [PambRoutingTimelineService::RECEIVED_BY_TSD, OrganizationalAccessService::PENRO_TSD_CHIEF, '2026-08-05 13:00:00'],
            [PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, OrganizationalAccessService::PENRO_TSD_CHIEF, '2026-08-05 14:00:00'],
            [PambRoutingTimelineService::RECEIVED_BY_CDS, OrganizationalAccessService::PENRO_FOCAL, '2026-08-05 15:00:00'],
            [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, OrganizationalAccessService::PENRO_FOCAL, '2026-08-05 16:00:00'],
            [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, OrganizationalAccessService::PENRO_CHIEF, '2026-08-05 17:00:00'],
            [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, OrganizationalAccessService::PENRO_CHIEF, '2026-08-05 18:00:00'],
            [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 19:00:00'],
            [PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL, OrganizationalAccessService::OFFICE_PENRO, '2026-08-05 20:00:00'],
            [PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL, OrganizationalAccessService::PENRO_RECORDS, '2026-08-06 09:00:00'],
        ];
        foreach ($route as [$stage, $category, $occurredAt]) {
            $timeline->record($report, $stage, $occurredAt, $actors[$category]->id);
        }

        $report->update(['date_endorsed_regional' => '2026-08-07']);
        $timeline->recordCanonical(
            $report,
            SubmissionTrackingService::REGIONAL_ENDORSEMENT,
            '2026-08-07',
            $actors[OrganizationalAccessService::PENRO_RECORDS]->id,
            CarbonImmutable::parse('2026-08-07 09:00:00', 'Asia/Manila'),
        );
        $reports[$workflow] = $report->fresh();
    }

    $this->actingAs($admin);
    $tracking = app(SubmissionTrackingService::class);
    $before = [];
    foreach ($reports as $workflow => $report) {
        $presentation = app(PambRoutingTimelineService::class)->present($report);
        $row = $tracking->records(['program' => 'conservation'])->first(fn (array $item): bool => $item['source'] === 'conservation' && (int) $item['source_id'] === $report->id);
        $before[$workflow] = [
            'events' => $report->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
                $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
            ])->all(),
            'presentation' => $presentation,
            'row' => $row,
            'queues' => $tracking->workspaceQueues(),
        ];
        expect($presentation['routing_complete'])->toBeTrue()
            ->and($presentation['actions'])->toBeEmpty()
            ->and($row['routing_complete'])->toBeTrue()
            ->and($row['mov_processing']['status_key'])->toBe(PambMovProcessingService::RELEASED_BY_CENRO)
            ->and($row['mov_processing']['chief_verdict_label'])->toBe('Ready for Release');
    }

    foreach ($reports as $workflow => $report) {
        $initialCount = \App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->count();
        $this->patch(route('submission-tracking.correct-routing', ['conservation', $report->id]), [
            'dates' => ['date_report_released_cenro' => '2026-08-03'],
            'reason' => 'Correct the recorded CENRO release business date.',
            'password' => 'secret-password',
        ])->assertSessionHasNoErrors();

        $fresh = $report->fresh();
        $presentation = app(PambRoutingTimelineService::class)->present($fresh);
        $row = $tracking->records(['program' => 'conservation'])->first(fn (array $item): bool => $item['source'] === 'conservation' && (int) $item['source_id'] === $report->id);
        $release = collect($presentation['timeline'])->firstWhere('key', SubmissionTrackingService::CENRO_RELEASE);
        $queues = $tracking->workspaceQueues();
        $membership = fn (string $queue) => collect($queues[$queue] ?? [])->contains(fn (array $item): bool => $item['source'] === 'conservation' && (int) $item['source_id'] === $report->id);

        expect($fresh->date_report_released_cenro?->toDateString())->toBe('2026-08-03')
            ->and($fresh->date_received_penro?->toDateString())->toBe('2026-08-05')
            ->and($fresh->date_endorsed_regional?->toDateString())->toBe('2026-08-07')
            ->and($fresh->mov_file_name)->toBe('minutes.pdf')
            ->and($fresh->mov_file_path)->toBe('routing-correction/'.$workflow.'.pdf')
            ->and($report->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
                $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
            ])->all())->toBe($before[$workflow]['events'])
            ->and($presentation['routing_complete'])->toBeTrue()
            ->and($release['business_date'])->toBe('2026-08-03')
            ->and($release['occurred_at'])->toBe(collect($before[$workflow]['presentation']['timeline'])->firstWhere('key', SubmissionTrackingService::CENRO_RELEASE)['occurred_at'])
            ->and($membership('history'))->toBeTrue()
            ->and($membership('incoming'))->toBeFalse()
            ->and($membership('outgoing'))->toBeFalse()
            ->and($row['routing_complete'])->toBeTrue()
            ->and($row['mov_processing']['status_key'])->toBe(PambMovProcessingService::RELEASED_BY_CENRO)
            ->and($row['mov_processing']['chief_verdict_label'])->toBe('Ready for Release')
            ->and($row['mov_processing']['percent'])->toBe(100)
            ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->count())->toBe($initialCount + 1);
    }

    $completed = $reports['regular_pamb'];
    $eventsBeforeClear = $completed->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
        $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
    ])->all();
    $correctionsBeforeClear = \App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->get()->toArray();
    $auditsBeforeClear = \App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $completed->id)->where('action', 'Routing Date Corrected')->get()->toArray();
    $beforeClearRow = $tracking->records(['program' => 'conservation'])->first(fn (array $item): bool => $item['source'] === 'conservation' && (int) $item['source_id'] === $completed->id);
    $beforeClearMov = app(PambMovProcessingService::class)->present($completed);
    $this->patch(route('submission-tracking.correct-routing', ['conservation', $completed->id]), [
        'dates' => ['date_report_released_cenro' => null],
        'reason' => 'Verify the completed-date clearing guard.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('dates.date_report_released_cenro');

    $afterRejectedClear = $completed->fresh();
    $afterRejectedPresentation = app(PambRoutingTimelineService::class)->present($afterRejectedClear);
    $afterRejectedMov = app(PambMovProcessingService::class)->present($afterRejectedClear);
    $afterRejectedRow = $tracking->records(['program' => 'conservation'])->first(fn (array $item): bool => $item['source'] === 'conservation' && (int) $item['source_id'] === $completed->id);
    $afterRejectedQueues = $tracking->workspaceQueues();
    $stillInHistory = collect($afterRejectedQueues['history'] ?? [])->contains(fn (array $item): bool => $item['source'] === 'conservation' && (int) $item['source_id'] === $completed->id);

    expect($afterRejectedClear->date_report_released_cenro?->toDateString())->toBe('2026-08-03')
        ->and($afterRejectedClear->date_received_penro?->toDateString())->toBe('2026-08-05')
        ->and($afterRejectedClear->date_endorsed_regional?->toDateString())->toBe('2026-08-07')
        ->and($afterRejectedPresentation['routing_complete'])->toBeTrue()
        ->and($afterRejectedClear->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
            $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
        ])->all())->toBe($eventsBeforeClear)
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->get()->toArray())->toBe($correctionsBeforeClear)
        ->and(\App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $completed->id)->where('action', 'Routing Date Corrected')->get()->toArray())->toBe($auditsBeforeClear)
        ->and($afterRejectedRow['routing_complete'])->toBe($beforeClearRow['routing_complete'])
        ->and($afterRejectedMov['status_key'])->toBe($beforeClearMov['status_key'])
        ->and($afterRejectedMov['percent'])->toBe($beforeClearMov['percent'])
        ->and($stillInHistory)->toBeTrue()
        ->and($afterRejectedMov['status_key'])->toBe(PambMovProcessingService::RELEASED_BY_CENRO)
        ->and($afterRejectedMov['percent'])->toBe(100);
});

test('a completed legacy PAMB correction protects the exact cycle-suffixed internal timestamp', function (): void {
    [$report, $admin] = routingCorrectionCycleFixture($this);
    $timeline = app(PambRoutingTimelineService::class);
    $chief = routingCorrectionActor(OrganizationalAccessService::PENRO_CHIEF);
    $office = routingCorrectionActor(OrganizationalAccessService::OFFICE_PENRO);
    $records = routingCorrectionActor(OrganizationalAccessService::PENRO_RECORDS);
    foreach ([
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF.'__cycle_2', $chief, '2026-08-06 11:00:00'],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO.'__cycle_2', $chief, '2026-08-06 12:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL.'__cycle_2', $office, '2026-08-06 13:00:00'],
        [PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL.'__cycle_2', $office, '2026-08-06 14:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL.'__cycle_2', $records, '2026-08-06 15:00:00'],
    ] as [$stageKey, $actor, $occurredAt]) {
        $timeline->record($report->fresh(), $stageKey, $occurredAt, $actor->id);
    }
    $timeline->recordCanonical(
        $report->fresh(),
        SubmissionTrackingService::REGIONAL_ENDORSEMENT,
        '2026-08-07',
        $records->id,
        CarbonImmutable::parse('2026-08-07 09:00:00', 'Asia/Manila'),
    );

    $stageKey = PambRoutingTimelineService::RECEIVED_BY_CDS.'__cycle_2';
    $completed = $report->fresh()->load('protectedArea');
    expect(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($completed, 'conservation')['stage'])
        ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($completed->routingEvents()->where('stage_key', $stageKey)->count())->toBe(1);
    $eventsBefore = $completed->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
        $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
    ])->all();
    $correctionsBefore = \App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->get()->toArray();
    $auditsBefore = \App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $completed->id)->where('action', 'Routing Date Corrected')->get()->toArray();

        $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['conservation', $completed->id]), [
        'dates' => ['date_received_penro' => '2026-08-06'],
        'internal_events' => [$stageKey => null],
        'reason' => 'Reject a cycle-two timestamp clear.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('internal_events.'.$stageKey);

    expect($completed->fresh()->date_received_penro?->toDateString())->toBe('2026-08-05')
        ->and($completed->fresh()->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
            $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
        ])->all())->toBe($eventsBefore)
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->get()->toArray())->toBe($correctionsBefore)
        ->and(\App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $completed->id)->where('action', 'Routing Date Corrected')->get()->toArray())->toBe($auditsBefore);
});

test('same-day regional business dates accept unchanged later internal timestamps for all PAMB workflows', function (): void {
    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        [$completed, $admin] = routingCorrectionCompletedSecondCycleFixture($this, $workflow);
        expect(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($completed, 'conservation')['stage'])
            ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL);
        $timeline = app(PambRoutingTimelineService::class);
        $eventsBefore = $completed->routingEvents()->orderBy('id')->get();
        $correctionsBefore = \App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->count();
        $lastInternal = $eventsBefore->firstWhere('stage_key', PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL.'__cycle_2');
        expect(CarbonImmutable::parse($completed->date_endorsed_regional->toDateString(), 'Asia/Manila')
            ->lessThan(CarbonImmutable::parse($lastInternal->occurred_at, 'Asia/Manila')))->toBeTrue();
        $internalEvents = $eventsBefore
            ->filter(fn (PambRoutingEvent $event): bool => $timeline->isInternalStageKey($event->stage_key))
            ->mapWithKeys(fn (PambRoutingEvent $event): array => [
                $event->stage_key => $event->occurred_at->format('Y-m-d\\TH:i'),
            ])->all();

        $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['conservation', $completed->id]), [
            'dates' => ['date_report_released_cenro' => '2026-09-28'],
            'internal_events' => $internalEvents,
            'reason' => 'Correct the CENRO business date while preserving recorded routing times.',
            'password' => 'secret-password',
        ])->assertSessionHasNoErrors();

        $eventsAfter = $completed->fresh()->routingEvents()->orderBy('id')->get();
        expect($completed->fresh()->date_report_released_cenro?->toDateString())->toBe('2026-09-28')
            ->and($eventsAfter->map(fn (PambRoutingEvent $event): array => [
                $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
            ])->all())->toBe($eventsBefore->map(fn (PambRoutingEvent $event): array => [
                $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
            ])->all())
            ->and($eventsAfter->firstWhere('stage_key', PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL.'__cycle_2')->occurred_at->toDateTimeString())
            ->toBe('2026-09-30 12:00:37')
            ->and($eventsAfter->firstWhere('stage_key', PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS.'__cycle_2')->occurred_at->toDateTimeString())
            ->toBe('2026-09-30 12:00:37')
            ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->count())
            ->toBe($correctionsBefore + 1)
            ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->pluck('field')->all())
            ->toBe(['date_report_released_cenro']);
    }
});

test('changed internal times report their exact event and reject writes on both sides of the timeline', function (): void {
    [$completed, $admin] = routingCorrectionCompletedSecondCycleFixture($this);
    $timeline = app(PambRoutingTimelineService::class);
    $stageKey = PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL.'__cycle_2';
    $eventsBefore = $completed->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
        $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
    ])->all();
    $recordDatesBefore = [
        $completed->date_report_released_cenro?->toDateString(),
        $completed->date_received_penro?->toDateString(),
        $completed->date_endorsed_regional?->toDateString(),
    ];
    $correctionsBefore = \App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->get()->toArray();
    $auditsBefore = \App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $completed->id)->where('action', 'Routing Date Corrected')->get()->toArray();

    $unchanged = $completed->routingEvents()->get()
        ->filter(fn (PambRoutingEvent $event): bool => $timeline->isInternalStageKey($event->stage_key))
        ->mapWithKeys(fn (PambRoutingEvent $event): array => [
            $event->stage_key => $event->occurred_at->format('Y-m-d\\TH:i'),
        ])->all();
    $unchanged[$stageKey] = '2026-09-30T11:59';

    $earlierResponse = $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['conservation', $completed->id]), [
        'dates' => ['date_report_released_cenro' => '2026-09-29'],
        'internal_events' => $unchanged,
        'reason' => 'Reject an earlier PENRO Records receipt time.',
        'password' => 'secret-password',
    ]);

    $earlierErrors = $earlierResponse->getSession()->get('errors');
    $earlierMessages = data_get($earlierErrors, 'default.messages', []);
    expect($earlierMessages)->toHaveKey('internal_events.'.$stageKey)
        ->and($earlierMessages['internal_events'][0])->toBe('A routing event conflicts with an adjacent milestone. Review the highlighted event and compare the named values.')
        ->and($earlierMessages['internal_events'][0])->not->toContain('chronological')
        ->and($earlierMessages['internal_events.'.$stageKey][0])->toBe(
        'Received by PENRO Records (cycle 2) (September 30, 2026 at 11:59 AM Asia/Manila) is earlier than Forwarded to PENRO Records (cycle 2) (September 30, 2026 at 12:00:37 PM Asia/Manila). Set this action time to the previous action\'s time or later.'
    );
    expect($completed->fresh()->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
        $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
    ])->all())->toBe($eventsBefore)
        ->and([
            $completed->fresh()->date_report_released_cenro?->toDateString(),
            $completed->fresh()->date_received_penro?->toDateString(),
            $completed->fresh()->date_endorsed_regional?->toDateString(),
        ])->toBe($recordDatesBefore)
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->get()->toArray())->toBe($correctionsBefore)
        ->and(\App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $completed->id)->where('action', 'Routing Date Corrected')->get()->toArray())->toBe($auditsBefore);

    $unchanged[$stageKey] = '2026-10-01T09:00';
    $laterResponse = $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['conservation', $completed->id]), [
        'dates' => ['date_report_released_cenro' => '2026-09-29'],
        'internal_events' => $unchanged,
        'reason' => 'Reject a PENRO Records receipt after Regional endorsement.',
        'password' => 'secret-password',
    ]);

    $laterErrors = $laterResponse->getSession()->get('errors');
    $laterMessages = data_get($laterErrors, 'default.messages', []);
    expect($laterMessages)->toHaveKey('internal_events.'.$stageKey)
        ->and($laterMessages['internal_events.'.$stageKey][0])->toBe(
        'Received by PENRO Records (cycle 2) (October 1, 2026 at 9:00 AM Asia/Manila) is after Regional Endorsed date (September 30, 2026). Set the action date on or before the endorsement date.'
    )
        ->and($completed->fresh()->routingEvents()->orderBy('id')->get()->map(fn (PambRoutingEvent $event): array => [
            $event->id, $event->stage_key, $event->occurred_at?->toDateTimeString(), $event->recorded_by,
        ])->all())->toBe($eventsBefore)
        ->and([
            $completed->fresh()->date_report_released_cenro?->toDateString(),
            $completed->fresh()->date_received_penro?->toDateString(),
            $completed->fresh()->date_endorsed_regional?->toDateString(),
        ])->toBe($recordDatesBefore)
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $completed->id)->get()->toArray())->toBe($correctionsBefore)
        ->and(\App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $completed->id)->where('action', 'Routing Date Corrected')->get()->toArray())->toBe($auditsBefore);
});

test('ENGP date correction rejects unsupported parent milestone fields without partial writes', function (): void {
    $admin = routingCorrectionAdmin();
    $report = EngpReportSubmission::create([
        'workflow_key' => 'cbep',
        'office' => 'CENRO Mati',
        'section_name' => 'NGP',
        'activity_name' => 'ENGP unsupported correction field fixture',
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => '2026-01',
        'period_label' => 'January 2026',
        'deadline_submission' => '2026-01-20',
        'date_received_penro' => '2026-01-20',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $event = $report->releaseEvents()->create([
        'period_component' => '2026-01',
        'component_label' => 'January',
        'date_report_released_cenro' => '2026-01-10',
    ]);

    $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['engp', $report->id]), [
        'dates' => [
            'date_received_penro' => '2026-01-22',
            'date_endorsed_regional' => '2026-01-23',
        ],
        'release_events' => [$event->id => '2026-01-21'],
        'reason' => 'Reject an unsupported ENGP field.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('dates.date_endorsed_regional');

    expect($report->fresh()->date_received_penro->toDateString())->toBe('2026-01-20')
        ->and($event->fresh()->date_report_released_cenro->toDateString())->toBe('2026-01-10')
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'engp')->where('source_id', $report->id)->count())->toBe(0);
});

test('ENGP release correction rejects malformed event identities without matching by integer cast', function (): void {
    $admin = routingCorrectionAdmin();
    $report = EngpReportSubmission::create([
        'workflow_key' => 'cbep',
        'office' => 'CENRO Mati',
        'section_name' => 'NGP',
        'activity_name' => 'ENGP malformed release identity fixture',
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => '2026-01',
        'period_label' => 'January 2026',
        'deadline_submission' => '2026-01-20',
        'date_received_penro' => '2026-01-20',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $event = $report->releaseEvents()->create([
        'period_component' => '2026-01',
        'component_label' => 'January',
        'date_report_released_cenro' => '2026-01-10',
    ]);

    $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['engp', $report->id]), [
        'dates' => ['date_received_penro' => '2026-01-21'],
        'release_events' => [((string) $event->id).'-invalid' => '2026-01-19'],
        'reason' => 'Reject a malformed release event key.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('release_events');

    expect($report->fresh()->date_received_penro->toDateString())->toBe('2026-01-20')
        ->and($event->fresh()->date_report_released_cenro->toDateString())->toBe('2026-01-10')
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'engp')->where('source_id', $report->id)->count())->toBe(0);
});

test('ENGP release event identifiers must belong to the exact source record', function (): void {
    $admin = routingCorrectionAdmin();
    $makeReport = fn (string $name, string $period = '2026-01'): EngpReportSubmission => EngpReportSubmission::create([
        'workflow_key' => 'cbep',
        'office' => 'CENRO Mati',
        'section_name' => 'NGP',
        'activity_name' => $name,
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => $period,
        'period_label' => $period === '2026-01' ? 'January 2026' : 'February 2026',
        'deadline_submission' => '2026-01-20',
        'date_received_penro' => '2026-01-20',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $target = $makeReport('Target ENGP report');
    $other = $makeReport('Other ENGP report', '2026-02');
    $targetEvent = $target->releaseEvents()->create(['period_component' => '2026-01', 'component_label' => 'January', 'date_report_released_cenro' => '2026-01-10']);
    $foreignEvent = $other->releaseEvents()->create(['period_component' => '2026-02', 'component_label' => 'February', 'date_report_released_cenro' => '2026-02-11']);

    $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['engp', $target->id]), [
        'dates' => ['date_received_penro' => '2026-01-22'],
        'release_events' => [$foreignEvent->id => '2026-01-21'],
        'reason' => 'Reject another report release event.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('release_events');

    expect($target->fresh()->date_received_penro->toDateString())->toBe('2026-01-20')
        ->and($targetEvent->fresh()->date_report_released_cenro->toDateString())->toBe('2026-01-10')
        ->and($foreignEvent->fresh()->date_report_released_cenro->toDateString())->toBe('2026-02-11')
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'engp')->where('source_id', $target->id)->count())->toBe(0);
});

test('Super Admin correction is permitted only with the named ability while operational actors are denied', function (): void {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay',
        'activity_name' => 'Routing correction authority fixture',
        'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04',
        'date_received_penro' => '2026-08-05',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $route = route('submission-tracking.correct-routing', ['conservation', $report->id]);
    $payload = [
        'dates' => ['date_report_released_cenro' => '2026-08-06', 'date_received_penro' => '2026-08-07'],
        'reason' => 'Correct the registered routing dates.',
        'password' => 'secret-password',
    ];
    $superAdmin = User::factory()->create(['password' => 'secret-password', 'section' => 'CDS']);
    $superAdmin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $superAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));

    $this->actingAs($superAdmin)->patch($route, $payload)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('submission_routing_corrections', [
        'source' => 'conservation', 'source_id' => $report->id,
        'field' => 'date_report_released_cenro', 'corrected_by' => $superAdmin->id,
    ]);

    $withoutPermission = User::factory()->create(['password' => 'secret-password', 'section' => 'CDS']);
    $withoutPermission->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $operational = User::factory()->create([
        'password' => 'secret-password',
        'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_RECORDS,
        'office_designated' => 'CENRO Mati',
    ]);
    $operational->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));
    $rejectedPayload = [...$payload, 'dates' => ['date_report_released_cenro' => '2026-08-08', 'date_received_penro' => '2026-08-09']];

    $withoutPermissionResponse = $this->actingAs($withoutPermission)->patchJson($route, $rejectedPayload);
    $operationalResponse = $this->actingAs($operational)->patchJson($route, $rejectedPayload);
    expect([$withoutPermissionResponse->status(), $operationalResponse->status()])->toBe([403, 403]);

    expect($report->fresh()->date_report_released_cenro->toDateString())->toBe('2026-08-06')
        ->and($report->fresh()->date_received_penro->toDateString())->toBe('2026-08-07')
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->count())->toBe(2);
});

test('correction-cycle timeline marks canonical business milestones as non-editable routing events', function (): void {
    [$report] = routingCorrectionCycleFixture($this);
    $timeline = app(PambRoutingTimelineService::class)->present($report);
    $items = collect($timeline['timeline'])->keyBy('stage_key');

    expect($items->get(SubmissionTrackingService::CENRO_RELEASE)['is_internal'])->toBeFalse()
        ->and($items->get(PambRoutingTimelineService::RECORDS_RECEIVED)['is_internal'])->toBeFalse()
        ->and($items->get(PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF)['is_internal'])->toBeTrue()
        ->and($items->get(PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF.'__cycle_2')['is_internal'])->toBeTrue();
});

test('correction endpoint edits the exact repeated PAMB stage and cycle selected in the modal', function (): void {
    [$report, $admin] = routingCorrectionCycleFixture($this);
    $service = app(PambRoutingTimelineService::class);
    $timeline = $service->present($report);
    $internalEvents = collect($timeline['timeline'])
        ->filter(fn (array $item): bool => ($item['is_internal'] ?? false) && filled($item['occurred_at'] ?? null))
        ->mapWithKeys(fn (array $item): array => [
            $item['stage_key'] => CarbonImmutable::parse($item['occurred_at'])->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
        ])
        ->all();
    $selectedKey = PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF;
    $internalEvents[$selectedKey] = '2026-08-05T16:05';
    $eventsBefore = $report->routingEvents()->get()->keyBy('stage_key');
    $cycleOneBefore = $eventsBefore->get($selectedKey);
    $cycleTwoBefore = $eventsBefore->get($selectedKey.'__cycle_2');

    $this->actingAs($admin)
        ->patch(route('submission-tracking.correct-routing', ['conservation', $report->id]), [
            'dates' => ['date_report_released_cenro' => '2026-08-04'],
            'internal_events' => $internalEvents,
            'reason' => 'Correct cycle one forwarding time.',
            'password' => 'secret-password',
        ])
        ->assertSessionHasNoErrors();

    $cycleOneAfter = PambRoutingEvent::query()->findOrFail($cycleOneBefore->id);
    $cycleTwoAfter = PambRoutingEvent::query()->findOrFail($cycleTwoBefore->id);

    expect($cycleOneAfter->occurred_at->toDateTimeString())->toBe('2026-08-05 16:05:00')
        ->and($cycleTwoAfter->occurred_at->toDateTimeString())->toBe($cycleTwoBefore->occurred_at->toDateTimeString())
        ->and($cycleOneAfter->recorded_by)->toBe($cycleOneBefore->recorded_by)
        ->and($cycleTwoAfter->recorded_by)->toBe($cycleTwoBefore->recorded_by)
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->where('field', 'internal_events.'.$selectedKey.'.occurred_at')->count())->toBe(1)
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->where('field', 'internal_events.'.$selectedKey.'__cycle_2.occurred_at')->count())->toBe(0);
});
