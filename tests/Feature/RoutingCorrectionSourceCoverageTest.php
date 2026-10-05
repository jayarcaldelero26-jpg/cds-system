<?php

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentArchive;
use App\Models\DocumentRoutingEvent;
use App\Models\EngpReportReleaseEvent;
use App\Models\EngpReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ProtectedArea;
use App\Models\SubmissionRoutingCorrection;
use App\Models\User;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Notification::fake();
    $this->corrector = User::factory()->create(['password' => 'secret-password', 'section' => 'CDS']);
    $this->corrector->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $this->corrector->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));
    $this->corrector->givePermissionTo(Permission::findOrCreate('submission-tracking.view', 'web'));
});

function correctionCoverageArea(User $owner): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'Correction Source Coverage PA', 'short_name' => 'CSCPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

/** @return array<string, array{0:class-string,1:array<string,mixed>,2:string}> */
function correctionCoverageSources(User $owner, ProtectedArea $area): array
{
    $dates = [
        'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => '2026-08-14',
        'date_received_penro' => '2026-08-15',
        'date_endorsed_regional' => '2026-08-16',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ];

    return [
        'conservation' => [ConservationReportSubmission::class, [
            ...$dates, 'workflow_key' => 'homestay', 'target_office' => 'CENRO Mati', 'activity_name' => 'Source matrix report',
        ], 'date_received_penro'],
        'bms' => [BmsReportSubmission::class, [...$dates, 'semester' => '1st Semester 2026'], 'date_received_penro'],
        'bams' => [BamsReportSubmission::class, [...$dates, 'semester' => '1st Semester 2026'], 'date_received_penro'],
        'imea' => [ImeaReportSubmission::class, [...$dates, 'semester' => '1st Semester 2026'], 'date_received_penro'],
        'imea-maintenance' => [ImeaFacilityMaintenanceReport::class, [
            ...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'Source matrix maintenance', 'document_type' => 'Report', 'quarter' => 'Q3 2026',
        ], 'date_received_penro'],
        'aws' => [Aws::class, [
            ...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'station_name' => 'Source matrix station', 'location' => 'Mati',
        ], 'date_received_penro'],
        'ipaf-management' => [IpafManagementReport::class, [
            ...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'IPAF management source matrix', 'document_type' => 'Report',
        ], 'date_received_penro'],
        'revenue' => [IpafRevenueCollection::class, [
            'date_report_released_cenro' => '2026-08-14', 'date_received_penro' => '2026-08-15', 'date_endorsed_regional' => '2026-08-16',
            'created_by' => $owner->id, 'updated_by' => $owner->id,
            'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'document_type' => 'Collection report',
            'reporting_month' => 8, 'reporting_year' => 2026, 'total_collected' => '1200.00',
        ], 'date_received_penro'],
        'management-plans' => [ManagementPlan::class, [
            ...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'plan_type' => 'Protected Area Management Plan',
            'title' => 'Source matrix plan', 'version' => '1', 'prepared_year' => 2026, 'status' => 'Submitted',
        ], 'date_received_penro'],
    ];
}

function correctionCoverageEvent(string $source, int $recordId, int $actorId): DocumentRoutingEvent
{
    return DocumentRoutingEvent::create([
        'source_type' => $source,
        'source_id' => $recordId,
        'event_key' => 'released',
        'from_stage' => \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
        'to_stage' => \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL,
        'from_office' => 'PENRO Records Unit',
        'to_office' => 'Regional Office',
        'occurred_at' => '2026-08-17 09:00:00',
        'recorded_by' => $actorId,
        'remarks' => 'Isolated completed state fixture',
        'metadata' => ['fixture' => 'routing-correction-source-matrix'],
    ]);
}

function correctionCoverageSnapshot(string $source, Model $record): array
{
    return [
        'record' => (array) DB::table($record->getTable())->where('id', $record->getKey())->first(),
        'events' => DocumentRoutingEvent::query()->where('source_type', $source)->where('source_id', $record->getKey())->orderBy('id')->get()->toArray(),
        'documents' => DocumentArchive::query()->where('source_type', $source)->where('source_id', $record->getKey())->orderBy('id')->get()->toArray(),
        'corrections' => SubmissionRoutingCorrection::query()->where('source', $source)->where('source_id', $record->getKey())->orderBy('id')->get()->toArray(),
        'audits' => \App\Models\AuditLog::query()->where('entity_type', $source)->where('entity_id', (string) $record->getKey())->where('action', 'Routing Date Corrected')->orderBy('id')->get()->toArray(),
        'workspace' => collect(app(SubmissionTrackingService::class)->workspaceQueues())
            ->map(fn ($queue): array => json_decode(json_encode($queue->values()->all()), true) ?? [])
            ->all(),
    ];
}

test('completed correction endpoint writes each registered source through its actual date columns and rejects blank clears atomically', function (): void {
    $area = correctionCoverageArea($this->corrector);
    $sources = correctionCoverageSources($this->corrector, $area);

    foreach ($sources as $source => [$modelClass, $attributes, $receiptField]) {
        $record = $modelClass::query()->create($attributes);
        foreach (['date_report_released_cenro', $receiptField, 'date_endorsed_regional'] as $column) {
            expect(Schema::hasColumn($record->getTable(), $column))->toBeTrue("{$source} is expected to persist {$column}");
        }
        $event = correctionCoverageEvent($source, (int) $record->getKey(), (int) $this->corrector->id);
        $eventIdentity = [
            $event->id, $event->event_key, $event->from_stage, $event->to_stage,
            $event->getRawOriginal('occurred_at'), $event->recorded_by,
        ];
        expect($event->exists)->toBeTrue();

        $this->actingAs($this->corrector)->patch(route('submission-tracking.correct-routing', [$source, $record->getKey()]), [
            'dates' => ['date_report_released_cenro' => '2026-08-13'],
            'reason' => "Correct the {$source} release business date.",
            'password' => 'secret-password',
        ])->assertSessionHasNoErrors();

        $record->refresh();
        expect((string) $record->getRawOriginal('date_report_released_cenro'))->toBe('2026-08-13')
            ->and((string) $record->getRawOriginal($receiptField))->toBe('2026-08-15')
            ->and((string) $record->getRawOriginal('date_endorsed_regional'))->toBe('2026-08-16')
            ->and([
                $event->fresh()->id, $event->fresh()->event_key, $event->fresh()->from_stage, $event->fresh()->to_stage,
                $event->fresh()->getRawOriginal('occurred_at'), $event->fresh()->recorded_by,
            ])->toBe($eventIdentity);

        $selectedResponse = $this->get(route('submission-tracking.index', ['source' => $source, 'source_id' => $record->getKey(), 'view' => 'history']))->assertOk();
        $selectedProps = $selectedResponse->inertiaProps();
        $selected = data_get($selectedProps, 'trackingContext.selected_record');
        $queues = data_get($selectedProps, 'workspaceQueues', []);
        $queueContainsRecord = fn (string $queue): bool => collect(data_get($queues, $queue, []))
            ->contains(fn (array $row): bool => $row['source'] === $source && (int) $row['source_id'] === (int) $record->getKey());
        expect(data_get($selected, 'date_report_released_cenro'))->toBe('2026-08-13')
            ->and(data_get($selected, 'routing_complete'))->toBeTrue()
            ->and(data_get($selected, 'routing.actions'))->toBeEmpty()
            ->and($queueContainsRecord('history'))->toBeTrue()
            ->and($queueContainsRecord('incoming'))->toBeFalse()
            ->and($queueContainsRecord('outgoing'))->toBeFalse();

        foreach ([null, ''] as $clearValue) {
            $before = correctionCoverageSnapshot($source, $record);
            $this->patch(route('submission-tracking.correct-routing', [$source, $record->getKey()]), [
                'dates' => [
                    $receiptField => '2026-08-18',
                    'date_report_released_cenro' => $clearValue,
                ],
                'reason' => "Reject mixed completed {$source} correction.",
                'password' => 'secret-password',
            ])->assertSessionHasErrors('dates.date_report_released_cenro');
            expect(correctionCoverageSnapshot($source, $record))->toBe($before);
        }
    }
});

test('ENGP endpoint corrects parent receipt and event-owned release columns and rejects clearing without regional parent dates', function (): void {
    $report = EngpReportSubmission::query()->create([
        'workflow_key' => 'conservation_report', 'office' => 'CENRO Mati', 'section_name' => 'CDS',
        'activity_name' => 'ENGP source matrix', 'document_type' => 'Report', 'reporting_year' => 2026,
        'period_key' => 'q3', 'period_label' => 'Q3 2026', 'deadline_submission' => '2026-08-20',
        'date_received_penro' => '2026-08-15', 'created_by' => $this->corrector->id, 'updated_by' => $this->corrector->id,
    ]);
    $release = EngpReportReleaseEvent::query()->create([
        'engp_report_submission_id' => $report->id, 'period_component' => 'q3', 'component_label' => 'Q3', 'date_report_released_cenro' => '2026-08-14',
    ]);
    $otherRelease = EngpReportReleaseEvent::query()->create([
        'engp_report_submission_id' => $report->id, 'period_component' => 'q2', 'component_label' => 'Q2', 'date_report_released_cenro' => '2026-08-12',
    ]);
    expect(Schema::hasColumn($report->getTable(), 'date_received_penro'))->toBeTrue()
        ->and(Schema::hasColumn($report->getTable(), 'date_report_released_cenro'))->toBeFalse()
        ->and(Schema::hasColumn($report->getTable(), 'date_endorsed_regional'))->toBeFalse()
        ->and(Schema::hasColumn($release->getTable(), 'date_report_released_cenro'))->toBeTrue();
    $routingEvent = correctionCoverageEvent('engp', (int) $report->id, (int) $this->corrector->id);
    $releaseIdsBefore = $report->releaseEvents()->orderBy('id')->pluck('id')->all();
    $routingIdentity = [
        $routingEvent->id, $routingEvent->event_key, $routingEvent->from_stage, $routingEvent->to_stage,
        $routingEvent->getRawOriginal('occurred_at'), $routingEvent->recorded_by,
    ];

    $this->actingAs($this->corrector)->patch(route('submission-tracking.correct-routing', ['engp', $report->id]), [
        'dates' => ['date_received_penro' => '2026-08-16'],
        'release_events' => [(string) $release->id => '2026-08-13'],
        'reason' => 'Correct both ENGP date owners.',
        'password' => 'secret-password',
    ])->assertSessionHasNoErrors();
    expect((string) $report->fresh()->getRawOriginal('date_received_penro'))->toBe('2026-08-16')
        ->and((string) $release->fresh()->getRawOriginal('date_report_released_cenro'))->toBe('2026-08-13')
        ->and((string) $otherRelease->fresh()->getRawOriginal('date_report_released_cenro'))->toBe('2026-08-12')
        ->and($report->fresh()->releaseEvents()->orderBy('id')->pluck('id')->all())->toBe($releaseIdsBefore)
        ->and(SubmissionRoutingCorrection::query()->where('source', 'engp')->where('source_id', $report->id)->orderBy('id')->pluck('field')->all())
        ->toBe(['release_events.'.$release->id.'.date_report_released_cenro', 'date_received_penro'])
        ->and([
            $routingEvent->fresh()->id, $routingEvent->fresh()->event_key, $routingEvent->fresh()->from_stage, $routingEvent->fresh()->to_stage,
            $routingEvent->fresh()->getRawOriginal('occurred_at'), $routingEvent->fresh()->recorded_by,
        ])->toBe($routingIdentity);

    $engpResponse = $this->get(route('submission-tracking.index', ['source' => 'engp', 'source_id' => $report->id, 'view' => 'history']))->assertOk();
    $engpProps = $engpResponse->inertiaProps();
    $engpSelected = data_get($engpProps, 'trackingContext.selected_record');
    $engpRelease = collect(data_get($engpSelected, 'release_events', []))->firstWhere('id', $release->id);
    $engpQueues = data_get($engpProps, 'workspaceQueues', []);
    $engpQueueContains = fn (string $queue): bool => collect(data_get($engpQueues, $queue, []))
        ->contains(fn (array $row): bool => $row['source'] === 'engp' && (int) $row['source_id'] === (int) $report->id);
    expect(data_get($engpSelected, 'date_received_penro'))->toBe('2026-08-16')
        ->and(data_get($engpRelease, 'id'))->toBe($release->id)
        ->and(data_get($engpRelease, 'date_report_released_cenro'))->toBe('2026-08-13')
        ->and(data_get($engpSelected, 'routing_complete'))->toBeTrue()
        ->and($engpQueueContains('history'))->toBeTrue()
        ->and($engpQueueContains('incoming'))->toBeFalse()
        ->and($engpQueueContains('outgoing'))->toBeFalse();

    $before = correctionCoverageSnapshot('engp', $report);
    $before['release'] = (array) DB::table($release->getTable())->where('id', $release->id)->first();
    $this->patch(route('submission-tracking.correct-routing', ['engp', $report->id]), [
        'dates' => ['date_received_penro' => '2026-08-17'],
        'release_events' => [(string) $release->id => null],
        'reason' => 'Reject mixed ENGP parent and component correction.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('release_events.'.$release->id);
    $after = correctionCoverageSnapshot('engp', $report);
    $after['release'] = (array) DB::table($release->getTable())->where('id', $release->id)->first();
    expect($after)->toBe($before);

    foreach ([null, ''] as $clearValue) {
        $this->patch(route('submission-tracking.correct-routing', ['engp', $report->id]), [
            'dates' => ['date_received_penro' => $clearValue],
            'reason' => 'Reject a cleared completed ENGP receipt.',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('dates.date_received_penro');
    }

    $this->patch(route('submission-tracking.correct-routing', ['engp', $report->id]), [
        'dates' => ['date_received_penro' => '2026-08-17', 'date_endorsed_regional' => '2026-08-18'],
        'reason' => 'Reject unsupported regional ENGP parent date.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('dates.date_endorsed_regional');
});

test('an in-flight nullable source milestone can be cleared and unchanged null direct-PENRO milestones remain absent', function (): void {
    $area = correctionCoverageArea($this->corrector);
    $inFlight = BmsReportSubmission::query()->create([
        'semester' => '1st Semester 2026', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => '2026-08-14', 'date_received_penro' => null,
        'date_endorsed_regional' => null, 'created_by' => $this->corrector->id, 'updated_by' => $this->corrector->id,
    ]);
    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $inFlight->id, 'event_key' => 'released',
        'from_stage' => \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CENRO_RECORDS,
        'to_stage' => \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
        'occurred_at' => '2026-08-14 09:00:00', 'recorded_by' => $this->corrector->id,
    ]);
    $this->actingAs($this->corrector)->patch(route('submission-tracking.correct-routing', ['bms', $inFlight->id]), [
        'dates' => ['date_report_released_cenro' => null],
        'reason' => 'Clear a milestone while routing is in flight.',
        'password' => 'secret-password',
    ])->assertSessionHasNoErrors();
    expect($inFlight->fresh()->date_report_released_cenro)->toBeNull();

    $area->update(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS']);
    $direct = ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental',
        'activity_name' => 'Direct PENRO completed source matrix', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => null, 'date_received_penro' => null, 'date_endorsed_regional' => null,
        'mov_processing_status' => \App\Services\SubmissionTracking\PambMovProcessingService::READY_FOR_RELEASE,
        'mov_file_name' => 'direct-penro-minutes.pdf', 'mov_file_path' => 'source-matrix/direct-penro.pdf',
        'created_by' => $this->corrector->id, 'updated_by' => $this->corrector->id,
    ]);
    \Illuminate\Support\Facades\Storage::disk('local')->put($direct->mov_file_path, "%PDF-1.4\ndirect PENRO isolated fixture");
    $actors = collect([
        \App\Services\Authorization\OrganizationalAccessService::PENRO_RECORDS,
        \App\Services\Authorization\OrganizationalAccessService::OFFICE_PENRO,
        \App\Services\Authorization\OrganizationalAccessService::PENRO_TSD_CHIEF,
        \App\Services\Authorization\OrganizationalAccessService::PENRO_FOCAL,
        \App\Services\Authorization\OrganizationalAccessService::PENRO_CHIEF,
    ])->mapWithKeys(function (string $category): array {
        $actor = User::factory()->create([
            'unit_assignment' => 'conservation', 'section' => $category, 'office_designated' => 'PENRO Davao Oriental',
        ]);
        foreach (['reports.view', 'technical-reports.update'] as $ability) {
            $actor->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        return [$category => $actor];
    });
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    expect($routing->state($direct->fresh()->load('protectedArea'), 'conservation')['profile']['key'])
        ->toBe('canonical_direct_penro');
    foreach ([
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_RECORDS, 'receive_at_penro_records'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_RECORDS, 'forward_to_office_penro'],
        [\App\Services\Authorization\OrganizationalAccessService::OFFICE_PENRO, 'receive_at_office_penro'],
        [\App\Services\Authorization\OrganizationalAccessService::OFFICE_PENRO, 'assign_to_tsd_chief'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_TSD_CHIEF, 'receive_at_tsd_chief'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_TSD_CHIEF, 'forward_to_cds_focal'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_FOCAL, 'receive_at_cds_focal'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_FOCAL, 'forward_to_cds_chief'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_CHIEF, 'receive_at_cds_chief'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_CHIEF, 'recommend_to_office_penro'],
        [\App\Services\Authorization\OrganizationalAccessService::OFFICE_PENRO, 'receive_at_office_penro_final'],
        [\App\Services\Authorization\OrganizationalAccessService::OFFICE_PENRO, 'approve_for_regional_release'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_RECORDS, 'receive_at_penro_records_final'],
        [\App\Services\Authorization\OrganizationalAccessService::PENRO_RECORDS, 'release_to_regional'],
    ] as [$category, $action]) {
        $routing->transition($direct->fresh()->load('protectedArea'), 'conservation', $action, $actors[$category]->id);
    }
    expect($routing->state($direct->fresh()->load('protectedArea'), 'conservation')['stage'])
        ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL);
    $this->patch(route('submission-tracking.correct-routing', ['conservation', $direct->id]), [
        'dates' => ['date_report_released_cenro' => null, 'date_received_penro' => '2026-08-06'],
        'reason' => 'Keep direct-PENRO release absent while correcting receipt.',
        'password' => 'secret-password',
    ])->assertSessionHasNoErrors();
    expect($direct->fresh()->date_report_released_cenro)->toBeNull()
        ->and($direct->fresh()->date_received_penro?->toDateString())->toBe('2026-08-06');
});
