<?php

use App\Models\ConservationReportSubmission;
use App\Models\AuditLog;
use App\Models\DocumentRoutingEvent;
use App\Models\DocumentArchive;
use App\Models\PambMovReviewEvent;
use App\Models\PambRoutingEvent;
use App\Models\SubmissionRoutingAttachment;
use App\Models\SubmissionRoutingCorrection;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\SubmissionTracking\AdminRoutingOverrideService;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 12:00:00', BusinessCalendarService::TIMEZONE));
    $this->recordsActor = User::factory()->create([
        'section' => OrganizationalAccessService::PENRO_RECORDS,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'office_designated' => 'PENRO Davao Oriental',
    ]);
    foreach (['reports.view', 'technical-reports.update'] as $ability) {
        $this->recordsActor->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    BusinessCalendarService::forgetCache();
});

function pambScreenshotReport(object $test, string $workflow = 'regular_pamb', array $overrides = []): ConservationReportSubmission
{
    return ConservationReportSubmission::create(array_merge([
        'workflow_key' => $workflow,
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'Screenshot date review fixture',
        'document_type' => 'Reso',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-10-07',
        'date_accomplished' => '2026-10-07',
        'date_report_released_cenro' => '2026-10-02',
        'date_received_penro' => '2026-10-03',
        'date_endorsed_regional' => '2026-10-03',
        'created_by' => $test->recordsActor->id,
        'updated_by' => $test->recordsActor->id,
    ], $overrides));
}

test('terminal regional release keeps its resolved stage while presenting completed without pending age', function (): void {
    $this->actingAs($this->recordsActor);
    $report = pambScreenshotReport($this);
    $event = DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation',
        'source_id' => $report->id,
        'workflow_key' => $report->workflow_key,
        'event_key' => 'released',
        'from_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
        'to_stage' => DocumentRoutingProfileRegistry::RELEASED_REGIONAL,
        'from_office' => 'PENRO Records Unit',
        'to_office' => 'Regional Office',
        'occurred_at' => '2026-10-03 18:08:15',
        'recorded_by' => $this->recordsActor->id,
    ]);
    $routingEvents = app(DocumentRoutingTransitionService::class)->events($report, 'conservation');
    $routing = app(DocumentRoutingPresenter::class)->present($report, 'conservation', null, $routingEvents);
    $terminal = collect($routing['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::RELEASED_REGIONAL);

    expect($routing['current_stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($routing['current_location'])->toBe('Regional Office')
        ->and($routing['current_status'])->toBe('Completed')
        ->and($routing['processing_percentage'])->toBe(100)
        ->and($routing['actions'])->toBeEmpty()
        ->and($terminal['status'])->toBe('current')
        ->and($terminal['display_status'] ?? null)->toBe('completed')
        ->and($terminal['display_status_label'] ?? null)->toBe('Completed')
        ->and($terminal['occurred_at'])->toBe($event->occurred_at->toIso8601String())
        ->and($terminal['pending_since'])->toBeNull()
        ->and($terminal['working_days_pending'])->toBeNull()
        ->and($routing['pending_since'])->toBeNull()
        ->and($routing['working_days_pending'])->toBeNull();
});

test('all ten active tracking sources use terminal display status without pending age', function (): void {
    $sources = [
        'conservation', 'engp', 'bms', 'bams', 'imea',
        'imea-maintenance', 'aws', 'ipaf-management', 'revenue', 'management-plans',
    ];
    $tracking = app(SubmissionTrackingService::class);
    $presenter = app(DocumentRoutingPresenter::class);

    foreach ($sources as $index => $source) {
        $config = $tracking->source($source);
        $record = new $config['model'];
        $sourceId = 90001 + $index;
        $record->setAttribute($record->getKeyName(), $sourceId);
        $record->setAttribute('workflow_key', match ($source) {
            'conservation' => 'homestay',
            'engp' => 'site_visit',
            'imea-maintenance' => 'imea_facility_maintenance',
            default => str_replace('-', '_', $source),
        });
        $record->setAttribute('target_office', 'CENRO Baganga');
        $record->setAttribute('office', 'CENRO Baganga');
        DocumentRoutingEvent::query()->create([
            'source_type' => $source,
            'source_id' => $sourceId,
            'workflow_key' => (string) $record->getAttribute('workflow_key'),
            'event_key' => 'released',
            'from_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
            'to_stage' => DocumentRoutingProfileRegistry::RELEASED_REGIONAL,
            'from_office' => 'PENRO Records Unit',
            'to_office' => 'Regional Office',
            'occurred_at' => '2026-10-03 18:08:15',
            'recorded_by' => $this->recordsActor->id,
        ]);

        $events = app(DocumentRoutingTransitionService::class)->events($record, $source);
        $routing = $presenter->present($record, $source, null, $events);
        $terminal = collect($routing['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::RELEASED_REGIONAL);

        expect($routing['current_stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL, $source)
            ->and($terminal['status'])->toBe('current', $source)
            ->and($terminal['display_status'])->toBe('completed', $source)
            ->and($terminal['display_status_label'])->toBe('Completed', $source)
            ->and($terminal['pending_since'])->toBeNull($source)
            ->and($terminal['working_days_pending'])->toBeNull($source)
            ->and($routing['pending_since'])->toBeNull($source)
            ->and($routing['working_days_pending'])->toBeNull($source)
            ->and($routing['actions'])->toBeEmpty($source);
    }
});

test('active PAMB custody at one hundred percent remains current and keeps its pending age', function (): void {
    $this->actingAs($this->recordsActor);
    $report = pambScreenshotReport($this, 'special_pamb', [
        'date_report_released_cenro' => null,
        'date_received_penro' => null,
        'date_endorsed_regional' => null,
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation',
        'source_id' => $report->id,
        'workflow_key' => $report->workflow_key,
        'event_key' => 'received',
        'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF,
        'to_stage' => DocumentRoutingProfileRegistry::CDS_CHIEF,
        'from_office' => 'PENRO CDS Focal Person',
        'to_office' => 'PENRO CDS Chief',
        'occurred_at' => '2026-10-02 09:00:00',
        'recorded_by' => $this->recordsActor->id,
    ]);

    $routingEvents = app(DocumentRoutingTransitionService::class)->events($report, 'conservation');
    $routing = app(DocumentRoutingPresenter::class)->present($report, 'conservation', null, $routingEvents);
    $current = collect($routing['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::CDS_CHIEF);

    expect($routing['current_stage'])->toBe(DocumentRoutingProfileRegistry::CDS_CHIEF)
        ->and($routing['processing_percentage'])->toBe(100)
        ->and($current['status'])->toBe('current')
        ->and($current['display_status'] ?? null)->toBe('current')
        ->and($current['pending_since'])->toBe('2026-10-02')
        ->and($current['working_days_pending'])->toBe(1)
        ->and($routing['pending_since'])->toBe('2026-10-02')
        ->and($routing['working_days_pending'])->toBe(1);
});

test('post-completion custody endpoints reject records actors and global admins without mutation while a date-only correction preserves History', function (): void {
    $report = pambScreenshotReport($this, 'regular_pamb', [
        'date_report_released_cenro' => '2026-10-03',
        'mov_processing_status' => 'ready_for_release',
        'mov_reviewed_at' => '2026-10-02 09:00:00',
        'mov_reviewed_by' => $this->recordsActor->id,
        'mov_review_remarks' => 'Approved for release.',
    ]);
    $report->movReviewEvents()->create([
        'event_key' => 'ready_for_release',
        'remarks' => 'Approved for release.',
        'recorded_by' => $this->recordsActor->id,
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation', 'source_id' => $report->id, 'workflow_key' => $report->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL,
        'to_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
        'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO Records Unit',
        'occurred_at' => '2026-10-03 17:00:00', 'recorded_by' => $this->recordsActor->id,
        'metadata' => ['action_key' => 'receive_at_penro_records_final', 'pamb_cycle' => 1],
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation', 'source_id' => $report->id, 'workflow_key' => $report->workflow_key,
        'event_key' => 'released', 'from_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
        'to_stage' => DocumentRoutingProfileRegistry::RELEASED_REGIONAL,
        'from_office' => 'PENRO Records Unit', 'to_office' => 'Regional Office',
        'occurred_at' => '2026-10-03 18:08:15', 'recorded_by' => $this->recordsActor->id,
        'metadata' => ['action_key' => 'release_to_regional', 'pamb_cycle' => 1],
    ]);
    $unrelated = pambScreenshotReport($this, 'twc_meetings', [
        'activity_name' => 'Unrelated report remains unchanged',
        'date_report_released_cenro' => null,
        'date_received_penro' => null,
        'date_endorsed_regional' => null,
        'mov_processing_status' => null,
    ]);

    $reportTableSnapshot = fn (int $id): array => (array) \Illuminate\Support\Facades\DB::table('conservation_report_submissions')->where('id', $id)->first();
    $eventSnapshot = fn (int $id): array => DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $id)->orderBy('occurred_at')->orderBy('id')->get(['id', 'event_key', 'from_stage', 'to_stage', 'occurred_at', 'recorded_by', 'metadata'])->toArray();
    $movEventSnapshot = fn (int $id): array => PambMovReviewEvent::query()->where('conservation_report_submission_id', $id)->orderBy('id')->get(['id', 'event_key', 'remarks', 'recorded_by'])->toArray();
    $pambEventSnapshot = fn (int $id): array => PambRoutingEvent::query()->where('conservation_report_submission_id', $id)->orderBy('occurred_at')->orderBy('id')->get(['id', 'stage_key', 'occurred_at', 'recorded_by'])->toArray();
    $reportBefore = $reportTableSnapshot($report->id);
    $unrelatedBefore = $reportTableSnapshot($unrelated->id);
    $eventsBefore = $eventSnapshot($report->id);
    $movBefore = $movEventSnapshot($report->id);
    $pambBefore = $pambEventSnapshot($report->id);
    $attachmentCount = SubmissionRoutingAttachment::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $archiveCount = DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $auditCount = AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $report->id)->count();

    $this->actingAs($this->recordsActor)->post(route('submission-tracking.transition', [
        'conservation', $report->id, 'release_to_regional',
    ]), ['stage' => 'release_to_regional'])->assertRedirect()->assertSessionHasErrors('stage');

    $globalAdmin = User::factory()->create([
        'password' => 'secret-password',
        'section' => 'SUPER_ADMIN',
        'office_designated' => 'PENRO Davao Oriental',
    ]);
    foreach (['reports.view', 'technical-reports.update', 'submission-tracking.admin-override', 'submission-tracking.correct-routing'] as $ability) {
        $globalAdmin->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $globalAdmin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $globalResponse = $this->actingAs($globalAdmin)->post(route('submission-tracking.transition', [
        'conservation', $report->id, 'release_to_regional',
    ]), ['stage' => 'release_to_regional']);
    expect($globalResponse->getStatusCode())->toBeIn([302, 403]);
    if ($globalResponse->getStatusCode() === 302) $globalResponse->assertSessionHasErrors('stage');

    $adminOverride = app(AdminRoutingOverrideService::class)->available('conservation', $report->id, $globalAdmin);
    expect($adminOverride['available'])->toBeFalse()
        ->and($adminOverride['actions'])->toBeEmpty()
        ->and($reportTableSnapshot($report->id))->toBe($reportBefore)
        ->and($eventSnapshot($report->id))->toBe($eventsBefore)
        ->and($movEventSnapshot($report->id))->toBe($movBefore)
        ->and($pambEventSnapshot($report->id))->toBe($pambBefore)
        ->and(SubmissionRoutingAttachment::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($attachmentCount)
        ->and(DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($archiveCount)
        ->and(AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $report->id)->count())->toBe($auditCount);

    $historyBefore = app(SubmissionTrackingService::class)->queues()['history']->firstWhere(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $report->id);
    $workspaceBefore = app(SubmissionTrackingService::class)->workspaceQueues();
    $detailsBefore = $this->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'history',
    ]))->assertOk()->inertiaProps();
    $selectedBefore = data_get($detailsBefore, 'trackingContext.selected_record');
    expect($historyBefore)->not->toBeNull()
        ->and(collect($workspaceBefore['incoming'])->pluck('source_id'))->not->toContain($report->id)
        ->and(collect($workspaceBefore['outgoing'])->pluck('source_id'))->not->toContain($report->id)
        ->and(data_get($selectedBefore, 'routing.actions'))->toBeEmpty();

    $this->patch(route('submission-tracking.correct-routing', ['conservation', $report->id]), [
        'dates' => ['date_report_released_cenro' => '2026-10-02'],
        'reason' => 'Correct only the CENRO release business date.',
        'password' => 'secret-password',
    ])->assertSessionHasNoErrors();

    $reportAfter = $reportTableSnapshot($report->id);
    $allowedChanges = ['date_report_released_cenro', 'updated_at', 'updated_by'];
    $changedColumns = collect($reportAfter)->filter(fn (mixed $value, string $column): bool => ($reportBefore[$column] ?? null) !== $value)->keys()->all();
    expect(array_diff($changedColumns, $allowedChanges))->toBeEmpty()
        ->and($reportAfter['date_report_released_cenro'])->toBe('2026-10-02')
        ->and($reportAfter['date_conducted'])->toBe($reportBefore['date_conducted'])
        ->and($reportAfter['date_accomplished'])->toBe($reportBefore['date_accomplished'])
        ->and($reportAfter['date_received_penro'])->toBe($reportBefore['date_received_penro'])
        ->and($reportAfter['date_endorsed_regional'])->toBe($reportBefore['date_endorsed_regional'])
        ->and($reportAfter['mov_processing_status'])->toBe($reportBefore['mov_processing_status'])
        ->and($reportAfter['mov_reviewed_at'])->toBe($reportBefore['mov_reviewed_at'])
        ->and($reportAfter['mov_reviewed_by'])->toBe($reportBefore['mov_reviewed_by'])
        ->and($reportAfter['mov_review_remarks'])->toBe($reportBefore['mov_review_remarks'])
        ->and($reportTableSnapshot($unrelated->id))->toBe($unrelatedBefore)
        ->and($eventSnapshot($report->id))->toBe($eventsBefore)
        ->and($movEventSnapshot($report->id))->toBe($movBefore)
        ->and($pambEventSnapshot($report->id))->toBe($pambBefore);

    $correction = SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->sole();
    $audit = AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $report->id)->sole();
    expect($correction->field)->toBe('date_report_released_cenro')
        ->and($correction->original_value)->toStartWith('2026-10-03')
        ->and($correction->corrected_value)->toStartWith('2026-10-02')
        ->and($correction->corrected_by)->toBe($globalAdmin->id)
        ->and($audit->action)->toBe('Routing Date Corrected')
        ->and($audit->metadata['field'])->toBe('date_report_released_cenro')
        ->and($audit->metadata['old'])->toBe('2026-10-03')
        ->and($audit->metadata['new'])->toBe('2026-10-02')
        ->and($audit->metadata['correction_id'])->toBe($correction->id);

    $finalState = app(DocumentRoutingTransitionService::class)->state($report->fresh(), 'conservation');
    $trackingAfter = app(SubmissionTrackingService::class);
    $historyAfter = $trackingAfter->queues()['history']->firstWhere(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $report->id);
    $workspaceAfter = $trackingAfter->workspaceQueues();
    $detailsAfter = $this->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'history',
    ]))->assertOk()->inertiaProps();
    $selectedAfter = data_get($detailsAfter, 'trackingContext.selected_record');
    expect($finalState['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($historyAfter)->not->toBeNull()
        ->and($historyAfter['completed_at'])->toBe('2026-10-03T18:08:15+08:00')
        ->and(collect($workspaceAfter['incoming'])->pluck('source_id'))->not->toContain($report->id)
        ->and(collect($workspaceAfter['outgoing'])->pluck('source_id'))->not->toContain($report->id)
        ->and(data_get($selectedAfter, 'routing.actions'))->toBeEmpty();
});
