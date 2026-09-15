<?php

use App\Models\ConservationReportSubmission;
use App\Models\PambRoutingEvent;
use App\Models\User;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;

use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void { CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'Asia/Manila')); });
afterEach(function (): void { CarbonImmutable::setTestNow(); });

function batch1bActor(string $role, string $section, string $office = 'PENRO Davao Oriental', array $attributes = []): User
{
    $user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => $section,
        'office_designated' => $office,
        ...$attributes,
    ]);
    $spatieRole = Role::findOrCreate($role, 'web');
    $spatieRole->syncPermissions(collect([
        'reports.view', 'technical-reports.view', 'technical-reports.create', 'technical-reports.update',
    ])->map(fn (string $permission) => Permission::findOrCreate($permission, 'web'))->all());
    $user->assignRole($spatieRole);

    return $user;
}

function batch1bReport(User $owner, array $overrides = []): ConservationReportSubmission
{
    return ConservationReportSubmission::create(array_merge([
        'workflow_key' => 'regular_pamb',
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Batch 1B PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 4',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04',
        'date_received_penro' => '2026-08-05',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ], $overrides));
}

function batch1bReachFinal(ConservationReportSubmission $report, int $userId): void
{
    $timeline = app(PambRoutingTimelineService::class);
    foreach ([
        PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        PambRoutingTimelineService::RECEIVED_BY_PENRO,
        PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD,
        PambRoutingTimelineService::RECEIVED_BY_TSD,
        PambRoutingTimelineService::FORWARDED_TSD_TO_CDS,
        PambRoutingTimelineService::RECEIVED_BY_CDS,
        PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF,
        PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF,
        PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
        PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL,
    ] as $index => $stage) {
        $date = ['2026-08-06', '2026-08-06', '2026-08-07', '2026-08-07', '2026-08-08', '2026-08-08', '2026-08-09', '2026-08-09', '2026-08-10', '2026-08-10'][$index];
        $timeline->record($report->fresh(), $stage, $date.' 09:00:00', $userId);
    }
}

function batch1bReachFinalCycle(ConservationReportSubmission $report, int $cycle, int $userId): void
{
    $timeline = app(PambRoutingTimelineService::class);
    $suffix = '__cycle_'.$cycle;
    foreach ([
        PambRoutingTimelineService::RECEIVED_BY_CDS,
        PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF,
        PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF,
        PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
        PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL,
    ] as $stage) {
        $date = sprintf('2026-08-%02d', 19 + $cycle);
        $timeline->record($report->fresh(), $stage.$suffix, $date.' 09:00:00', $userId);
    }
}

function batch1bCompleteAfterApproval(ConservationReportSubmission $report, int $cycle, int $userId): void
{
    $timeline = app(PambRoutingTimelineService::class);
    $suffix = $cycle === 1 ? '' : '__cycle_'.$cycle;
    foreach ([PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL] as $stage) {
        $date = sprintf('2026-08-%02d', 20 + $cycle);
        $timeline->record($report->fresh(), $stage.$suffix, $date.' 09:00:00', $userId);
    }
}

test('Office approval leaves final Records receipt and Regional release explicit', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $records = batch1bActor('PENRO Records Unit', 'PENRO_RECORDS');
    $report = batch1bReport($office);
    batch1bReachFinal($report, $office->id);

    $this->actingAs($office)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL,
    ]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])->assertSessionHasNoErrors();

    $approval = $report->fresh()->routingEvents()->where('stage_key', PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL)->first();
    expect($approval)->not->toBeNull()
        ->and($approval->recorded_by)->toBe($office->id)
        ->and($report->fresh()->date_endorsed_regional)->toBeNull()
        ->and(collect(app(PambRoutingTimelineService::class)->present($report->fresh())['timeline'])->firstWhere('status', 'current')['key'])
            ->toBe(PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL);

    $this->actingAs($office)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])->assertForbidden();

    batch1bCompleteAfterApproval($report, 1, $records->id);
    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT,
    ]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-10'])->assertSessionHasNoErrors();

    expect($report->fresh()->date_endorsed_regional?->toDateString())->toBe('2026-08-10');
});

test('PAMB PENRO Records handoff moves workspace ownership to Office of the PENRO', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $records = batch1bActor('PENRO Records Unit', 'PENRO_RECORDS');
    $report = batch1bReport($office, ['date_received_penro' => null]);

    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT,
    ]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-05'])->assertSessionHasNoErrors();

    $this->actingAs($records);
    $afterReceipt = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($afterReceipt['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($afterReceipt['outgoing']->pluck('source_id')->all())->toContain($report->id);

    $this->actingAs($records)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Document forwarded successfully.');

    $presentation = app(PambRoutingTimelineService::class)->present($report->fresh());
    expect(collect($presentation['timeline'])->firstWhere('status', 'current')['key'])
        ->toBe(PambRoutingTimelineService::RECEIVED_BY_PENRO);

    $this->actingAs($records);
    $recordsWorkspace = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($recordsWorkspace['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($recordsWorkspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $this->actingAs($office);
    $officeWorkspace = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($officeWorkspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($officeWorkspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id);
});

test('PAMB Chief recommendation transfers final ownership back to Office of the PENRO', function (): void {
    $records = batch1bActor('PENRO Records Unit', 'PENRO_RECORDS');
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $tsd = batch1bActor('PENRO TSD Chief', 'PENRO_TSD_CHIEF');
    $focal = batch1bActor('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL');
    $chief = batch1bActor('PENRO CDS Chief', 'PENRO_CDS_CHIEF');
    $report = batch1bReport($office);
    $timeline = app(PambRoutingTimelineService::class);

    foreach ([
        [PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, $records, '2026-08-06 09:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO, $office, '2026-08-06 10:00:00'],
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, $office, '2026-08-06 11:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_TSD, $tsd, '2026-08-07 09:00:00'],
        [PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, $tsd, '2026-08-07 10:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_CDS, $focal, '2026-08-08 09:00:00'],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, $focal, '2026-08-08 10:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, $chief, '2026-08-09 09:00:00'],
    ] as [$stage, $actor, $occurredAt]) {
        $timeline->record($report->fresh(), $stage, $occurredAt, $actor->id);
    }

    $this->actingAs($chief);
    $chiefBefore = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($chiefBefore['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($chiefBefore['outgoing']->pluck('source_id')->all())->toContain($report->id);

    $timeline->record($report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, '2026-08-09 10:00:00', $chief->id);

    $afterRecommendation = $timeline->present($report->fresh());
    $current = collect($afterRecommendation['timeline'])->firstWhere('status', 'current');
    expect($current['key'])->toBe(PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL)
        ->and($current['action_label'])->toBe('Record Receipt')
        ->and($afterRecommendation['routing_summary']['current_location'])->toBe('Office of the PENRO')
        ->and($afterRecommendation['routing_summary']['next_expected_action'])->toBe('Record Receipt')
        ->and(app(PambSubmissionAccessService::class)->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL))->toBeTrue();

    $this->actingAs($chief);
    $chiefAfter = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($chiefAfter['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($chiefAfter['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $this->actingAs($office);
    $officeRowBeforeReceipt = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    $officeCurrentStage = collect($officeRowBeforeReceipt['routing_timeline'])->firstWhere('status', 'current');
    expect($officeRowBeforeReceipt['routing']['responsible_user_category'])->toBe(PambSubmissionAccessService::OFFICE_PENRO)
        ->and($officeRowBeforeReceipt['routing']['current_stage'])->toBe(PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL)
        ->and($officeCurrentStage['can_record'])->toBeTrue()
        ->and($officeCurrentStage['action_label'])->toBe('Record Receipt');

    $officeBeforeReceipt = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($officeBeforeReceipt['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($officeBeforeReceipt['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, '2026-08-09 11:00:00', $office->id);
    $afterReceipt = $timeline->present($report->fresh());
    $officeRowAfterReceipt = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    expect($afterReceipt['routing_summary']['next_expected_action'])->toBe('Review and select an action')
        ->and($officeRowAfterReceipt['pamb_action_flags']['can_return_for_penro_correction'])->toBeTrue()
        ->and($officeRowAfterReceipt['pamb_action_flags']['can_approve_for_regional_release'])->toBeTrue()
        ->and(app(PambSubmissionAccessService::class)->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION))->toBeTrue()
        ->and(app(PambSubmissionAccessService::class)->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL))->toBeTrue();

    $officeAfterReceipt = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($officeAfterReceipt['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($officeAfterReceipt['incoming']->firstWhere('source_id', $report->id)['incoming_action_category'])->toBe('decision')
        ->and($officeAfterReceipt['outgoing']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($officeAfterReceipt)->not->toHaveKey('other');

    $timeline->record($report->fresh(), PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL, '2026-08-09 12:00:00', $office->id);

    $officeAfterApproval = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($officeAfterApproval['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($officeAfterApproval['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $this->actingAs($records);
    $recordsAfterApproval = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($recordsAfterApproval['incoming']->pluck('source_id')->all())->toContain($report->id);
});

test('Office correction returns to CDS Focal and preserves remarks through re-review', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $focal = batch1bActor('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL');
    $report = batch1bReport($office);
    batch1bReachFinal($report, $office->id);

    $return = PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION;
    $this->actingAs($office)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $return]), [
        'stage' => $return, 'remarks' => 'Please correct the missing supporting explanation.',
    ])->assertSessionHasNoErrors();

    $presentation = app(PambRoutingTimelineService::class)->present($report->fresh());
    expect($report->fresh()->routingEvents()->where('stage_key', $return)->value('remarks'))
        ->toBe('Please correct the missing supporting explanation.')
        ->and(collect($presentation['timeline'])->firstWhere('status', 'current')['key'])
        ->toBe(PambRoutingTimelineService::RECEIVED_BY_CDS.'__cycle_2')
        ->and($presentation['current_document_location'])->toBe('PENRO CDS Focal Person');

    batch1bReachFinalCycle($report, 2, $focal->id);
    $keys = $report->fresh()->routingEvents()->pluck('stage_key')->all();
    expect($keys)->toContain(PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL.'__cycle_2')
        ->and($keys)->toContain($return);
});

test('two Office correction cycles remain unique, auditable, and only Office may approve', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $focal = batch1bActor('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL');
    $report = batch1bReport($office);
    batch1bReachFinal($report, $office->id);

    foreach ([1, 2] as $cycle) {
        $suffix = $cycle === 1 ? '' : '__cycle_'.$cycle;
        $return = PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION.$suffix;
        CarbonImmutable::setTestNow(CarbonImmutable::parse(sprintf('2026-08-%02d 12:00:00', 20 + $cycle), 'Asia/Manila'));
        $this->actingAs($office)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $return]), [
            'stage' => $return, 'remarks' => 'Correction cycle '.$cycle.' requires a documented response.',
        ])->assertSessionHasNoErrors();
        batch1bReachFinalCycle($report, $cycle + 1, $focal->id);
    }

    $keys = $report->fresh()->routingEvents()->pluck('stage_key')->all();
    expect(collect($keys)->filter(fn (string $key): bool => str_starts_with($key, PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION))->count())
        ->toBe(2)
        ->and($keys)->toContain(PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION.'__cycle_2')
        ->and($keys)->toContain(PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION.'__cycle_2');

    $this->actingAs($focal)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL.'__cycle_3',
    ]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL.'__cycle_3'])->assertForbidden();
});


test('Office final verdict and Regional release reject every wrong category', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $records = batch1bActor('PENRO Records Unit', 'PENRO_RECORDS');
    $wrongActors = [
        batch1bActor('PAMO', 'PAMO', 'CENRO Mati'),
        batch1bActor('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati'),
        batch1bActor('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati'),
        batch1bActor('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati'),
        $records,
        batch1bActor('PENRO TSD Chief', 'PENRO_TSD_CHIEF'),
        batch1bActor('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL'),
        batch1bActor('PENRO CDS Chief', 'PENRO_CDS_CHIEF'),
        batch1bActor('User', 'ENGP', 'CENRO Mati', ['unit_assignment' => 'development']),
    ];
    $report = batch1bReport($office);
    batch1bReachFinal($report, $office->id);

    foreach ($wrongActors as $actor) {
        $this->actingAs($actor)->post(route('submission-tracking.internal-routing', [
            'conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL,
        ]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])->assertForbidden();
    }

    $this->actingAs($office)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL,
    ]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])->assertSessionHasNoErrors();
    $this->actingAs($office)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])->assertForbidden();

    batch1bCompleteAfterApproval($report, 1, $records->id);

    foreach (array_values(array_filter($wrongActors, fn (User $actor): bool => ! $actor->is($records))) as $actor) {
        $this->actingAs($actor)->post(route('submission-tracking.transition', [
            'conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT,
        ]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-22'])->assertForbidden();
    }
    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT,
    ]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-22'])->assertSessionHasNoErrors();
});


function batch1bReachRecommendationOnly(ConservationReportSubmission $report, int $userId): void
{
    $timeline = app(PambRoutingTimelineService::class);
    foreach ([
        PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        PambRoutingTimelineService::RECEIVED_BY_PENRO,
        PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD,
        PambRoutingTimelineService::RECEIVED_BY_TSD,
        PambRoutingTimelineService::FORWARDED_TSD_TO_CDS,
        PambRoutingTimelineService::RECEIVED_BY_CDS,
        PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF,
        PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF,
        PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
    ] as $index => $stage) {
        $date = sprintf('2026-08-%02d 09:00:00', 6 + intdiv($index, 2));
        $timeline->record($report->fresh(), $stage, $date, $userId);
    }
}

test('OfficePenroFinalReviewGatingTest: receipt precedes final review and approval remains non-terminal', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $records = batch1bActor('PENRO Records Unit', 'PENRO_RECORDS');
    $report = batch1bReport($office);
    batch1bReachRecommendationOnly($report, $office->id);

    $timeline = app(PambRoutingTimelineService::class);
    $access = app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class);
    $before = $timeline->present($report->fresh());

    expect($before['current_processing_status'])->toBe('Awaiting Receipt by Office of the PENRO')
        ->and($before['routing_summary']['next_expected_action'])->toBe('Record Receipt')
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL))->toBeTrue()
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION))->toBeFalse()
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL))->toBeFalse();

    $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, '2026-08-10 09:00:00', $office->id);
    $afterReceipt = $timeline->present($report->fresh());

    expect($afterReceipt['current_processing_status'])->toBe('For Final Review')
        ->and($afterReceipt['routing_summary']['next_expected_action'])->toBe('Review and select an action')
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL))->toBeFalse()
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION))->toBeTrue()
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL))->toBeTrue()
        ->and(fn () => $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, '2026-08-10 10:00:00', $office->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    $timeline->record($report->fresh(), PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL, '2026-08-10 10:30:00', $office->id);
    expect($timeline->present($report->fresh())['current_processing_status'])->toBe('Awaiting PENRO Records Receipt')
        ->and($report->fresh()->date_endorsed_regional)->toBeNull();

    $this->actingAs($office);
    expect(app(SubmissionTrackingService::class)->queues()['processed']->pluck('source_id')->all())->toContain($report->id);

    $this->actingAs($records);
    expect(app(SubmissionTrackingService::class)->queues()['penro_records_final']->pluck('source_id')->all())->toContain($report->id);

    $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL, '2026-08-11 10:00:00', $records->id);

    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT,
    ]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-12'])->assertSessionHasNoErrors();

    $queues = app(SubmissionTrackingService::class)->queues();
    expect($report->fresh()->date_endorsed_regional?->toDateString())->toBe('2026-08-12')
        ->and($queues['history']->pluck('source_id')->all())->toContain($report->id)
        ->and($queues['processed']->pluck('source_id')->all())->not->toContain($report->id);
});

test('RoutingTerminologyTest: generic workspace navigation uses Incoming, Outgoing, and History with action filters inside Incoming', function (): void {
    $service = file_get_contents(base_path('app/Services/SubmissionTracking/SubmissionTrackingService.php'));
    $registry = file_get_contents(base_path('app/Services/SubmissionTracking/DocumentRoutingProfileRegistry.php'));
    $index = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));
    $timeline = file_get_contents(base_path('resources/js/Components/SubmissionTracking/PambRoutingTimeline.jsx'));

    expect($service)->toContain("[['incoming', 'Incoming'], ['outgoing', 'Outgoing'], ['history', 'History']]")
        ->and($service)->not->toContain("Action History")
        ->and($service)->not->toContain("Final Records")
        ->and($registry)->toContain('Received by Office of the PENRO for Final Review')
        ->and($index)->not->toContain('Final Verdict')
        ->and($index)->not->toContain('Processed by My Office')
        ->and($timeline)->not->toContain('Final Verdict')
        ->and($timeline)->not->toContain('Processed by My Office');
});
