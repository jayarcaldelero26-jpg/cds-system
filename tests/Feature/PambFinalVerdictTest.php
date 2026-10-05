<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\DocumentArchive;
use App\Models\PambRoutingEvent;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;

use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

function batch1bReachFinalCycle(\PHPUnit\Framework\TestCase $test, ConservationReportSubmission $report, int $cycle, int $userId): void
{
    $focal = User::query()->findOrFail($userId);
    $chief = batch1bActor('PENRO CDS Chief', 'PENRO_CDS_CHIEF');
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $suffix = '__cycle_'.$cycle;
    foreach ([
        [PambRoutingTimelineService::RECEIVED_BY_CDS, $focal],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, $focal],
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, $chief],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, $chief],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, $office],
    ] as [$legacyStage, $actor]) {
        $stage = $legacyStage.$suffix;
        $test->actingAs($actor)->post(route('submission-tracking.internal-routing', [
            'conservation', $report->id, $stage,
        ]), ['stage' => $stage])->assertSessionHasNoErrors();
    }
}

function batch1bCompleteAfterApproval(\PHPUnit\Framework\TestCase $test, ConservationReportSubmission $report, int $cycle, int $userId): void
{
    $actor = User::query()->findOrFail($userId);
    $stage = PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL.($cycle === 1 ? '' : '__cycle_'.$cycle);
    $test->actingAs($actor)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, $stage,
    ]), ['stage' => $stage])->assertSessionHasNoErrors();
}

test('Office approval leaves final Records receipt and Regional release explicit', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $records = batch1bActor('PENRO Records Unit', 'PENRO_RECORDS');
    $report = batch1bReport($office);
    batch1bReachFinal($report, $office->id);

    $this->actingAs($office)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL,
    ]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])->assertSessionHasNoErrors();

    $approval = DocumentRoutingEvent::query()
        ->where('source_type', 'conservation')->where('source_id', $report->id)
        ->where('metadata->action_key', 'approve_for_regional_release')->first();
    expect($approval)->not->toBeNull()
        ->and($approval->recorded_by)->toBe($office->id)
        ->and($approval->workflow_key)->toBe('regular_pamb')
        ->and($approval->event_key)->toBe('approved')
        ->and(data_get($approval->metadata, 'state_source'))->toBe('routing_events')
        ->and($report->fresh()->routingEvents()->where('stage_key', PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL)->exists())->toBeFalse()
        ->and($report->fresh()->date_endorsed_regional)->toBeNull()
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->state($report->fresh()->load('protectedArea'), 'conservation')['stage'])
            ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL);

    $this->actingAs($office)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])
        ->assertRedirect()->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
        ->where('metadata->action_key', 'approve_for_regional_release')->count())->toBe(1);

    batch1bCompleteAfterApproval($this, $report, 1, $records->id);
    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT,
    ]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-10'])->assertSessionHasNoErrors();

    expect($report->fresh()->date_endorsed_regional?->toDateString())->toBe(CarbonImmutable::now('Asia/Manila')->toDateString());
});

test('PAMB PENRO Records handoff moves workspace ownership to Office of the PENRO', function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $archivePath = 'conservation-report-movs/penro-handoff.pdf';
    Storage::disk('local')->put($archivePath, "%PDF-1.4\nIsolated PENRO handoff checkpoint fixture");
    $archiveGateway = \Mockery::mock(GoogleDriveArchiveGateway::class);
    $archiveGateway->shouldReceive('findByIdentityAndHash')->once()->andReturnNull();
    $archiveGateway->shouldReceive('upload')->once()->andReturn(['file_id' => 'penro-handoff-test-archive', 'folder_id' => 'penro-handoff-test-folder']);
    $archiveGateway->shouldReceive('verify')->once()->with('penro-handoff-test-archive', \Mockery::type('string'), \Mockery::type('int'))->andReturnTrue();
    $archiveGateway->shouldReceive('verifyAvailability')->once()->with('penro-handoff-test-archive', \Mockery::type('string'), \Mockery::type('int'))->andReturn('verified');
    app()->instance(GoogleDriveArchiveGateway::class, $archiveGateway);

    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $records = batch1bActor('PENRO Records Unit', 'PENRO_RECORDS');
    $report = batch1bReport($office, [
        'date_received_penro' => null, 'mov_file_name' => 'penro-handoff.pdf', 'mov_file_path' => $archivePath,
    ]);

    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT,
    ]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-05'])->assertSessionHasNoErrors();

    $this->actingAs($records);
    $afterReceipt = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($afterReceipt['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($afterReceipt['outgoing']->pluck('source_id')->all())->not->toContain($report->id)
        ->and(DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(0);

    $presentation = app(PambRoutingTimelineService::class)->present($report->fresh());
    expect(collect($presentation['timeline'])->firstWhere('status', 'current')['key'])
        ->toBe(PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO);

    $this->actingAs($records)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])->assertSessionHasNoErrors();

    $archive = DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->where('logical_slot', 'mov')->firstOrFail();
    expect($archive->archive_status)->toBe('ARCHIVED')
        ->and($archive->remote_availability)->toBe('verified')
        ->and(Storage::disk('local')->exists($archivePath))->toBeTrue();

    $this->actingAs($office);
    $officeWorkspace = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($officeWorkspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($officeWorkspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id)
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->state($report->fresh()->load('protectedArea'), 'conservation')['stage'])
            ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO);
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
    expect($chiefBefore['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($chiefBefore['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $timeline->record($report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, '2026-08-09 10:00:00', $chief->id);

    $afterRecommendation = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    $current = collect($afterRecommendation['routing']['timeline'])->firstWhere('status', 'current');
    expect($current['key'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN)
        ->and($current['action_label'])->toBe('Recommend to Office of the PENRO')
        ->and($afterRecommendation['routing']['current_location'])->toBe('In Transit')
        ->and($afterRecommendation['routing']['next_expected_action'])->toBe('Receive')
        ->and(app(PambSubmissionAccessService::class)->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL))->toBeTrue();

    $this->actingAs($chief);
    $chiefAfter = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($chiefAfter['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($chiefAfter['outgoing']->pluck('source_id')->all())->toContain($report->id);

    $this->actingAs($office);
    $officeRowBeforeReceipt = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    $officeCurrentStage = collect($officeRowBeforeReceipt['routing']['timeline'])->firstWhere('status', 'current');
    expect($officeRowBeforeReceipt['routing']['responsible_user_category'])->toBe('Office of the PENRO')
        ->and($officeRowBeforeReceipt['routing']['current_stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN)
        ->and(collect($officeRowBeforeReceipt['routing']['actions'])->pluck('key')->all())->toContain('receive_at_office_penro_final')
        ->and($officeCurrentStage['action_label'])->toBe('Recommend to Office of the PENRO');

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
        ->and($officeAfterApproval['outgoing']->pluck('source_id')->all())->toContain($report->id);

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

    $returnEvent = DocumentRoutingEvent::query()->where('source_type', 'conservation')
        ->where('source_id', $report->id)->where('metadata->action_key', 'return_from_office_for_correction')->firstOrFail();
    $state = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->state($report->fresh()->load('protectedArea'), 'conservation');
    expect($returnEvent->remarks)->toBe('Please correct the missing supporting explanation.')
        ->and($state['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CDS_FOCAL)
        ->and(collect($state['actions'])->pluck('key')->all())->toBe(['receive_correction'])
        ->and($returnEvent->to_office)->toBe('PENRO CDS Focal Person');

    batch1bReachFinalCycle($this, $report, 2, $focal->id);
    $shared = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->get();
    expect($shared->pluck('metadata.action_key')->all())->toContain('receive_correction', 'forward_to_cds_chief', 'receive_at_cds_chief', 'recommend_to_office_penro', 'receive_at_office_penro_final')
        ->and($report->fresh()->routingEvents()->where('stage_key', PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL.'__cycle_2')->exists())->toBeFalse();
});

test('Regular Special and TWC correction cycles resume from the real PENRO return event', function (): void {
    $office = batch1bActor('Office of the PENRO', 'OFFICE_OF_THE_PENRO');
    $chief = batch1bActor('PENRO CDS Chief', 'PENRO_CDS_CHIEF');
    $focal = batch1bActor('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL');

    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        $report = batch1bReport($office, ['workflow_key' => $workflow]);
        batch1bReachFinal($report, $office->id);

        $returnStage = PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION;
        $this->actingAs($office)->post(route('submission-tracking.internal-routing', [
            'conservation', $report->id, $returnStage,
        ]), ['stage' => $returnStage, 'remarks' => 'Please correct the missing supporting explanation.'])
            ->assertSessionHasNoErrors();

        $returned = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->state($report->fresh()->load('protectedArea'), 'conservation');
        $returnEvent = DocumentRoutingEvent::query()->where('source_type', 'conservation')
            ->where('source_id', $report->id)->where('metadata->action_key', 'return_from_office_for_correction')->latest('id')->firstOrFail();
        expect($returned['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CDS_FOCAL)
            ->and(collect($returned['actions'])->pluck('key')->all())->toBe(['receive_correction'])
            ->and($returnEvent->remarks)->toBe('Please correct the missing supporting explanation.');

        foreach ([
            [PambRoutingTimelineService::RECEIVED_BY_CDS, $focal],
            [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, $focal],
            [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, $chief],
            [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, $chief],
            [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, $office],
        ] as [$stage, $actor]) {
            $cycleStage = $stage.'__cycle_2';
            $this->actingAs($actor)->post(route('submission-tracking.internal-routing', [
                'conservation', $report->id, $cycleStage,
            ]), ['stage' => $cycleStage])->assertSessionHasNoErrors();
        }

        $resubmitted = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->state($report->fresh()->load('protectedArea'), 'conservation');
        expect($resubmitted['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN)
            ->and($resubmitted['active_cycle'])->toBe(2)
            ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
                ->where('metadata->action_key', 'receive_at_office_penro_final')->exists())->toBeTrue();
    }
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
        batch1bReachFinalCycle($this, $report, $cycle + 1, $focal->id);
    }

    $returns = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
        ->where('metadata->action_key', 'return_from_office_for_correction')->orderBy('id')->get();
    expect($returns)->toHaveCount(2)
        ->and($returns->map(fn (DocumentRoutingEvent $event) => data_get($event->metadata, 'pamb_cycle'))->all())->toBe([2, 3])
        ->and($report->fresh()->routingEvents()->where('stage_key', 'like', PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION.'%')->exists())->toBeFalse();

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
    $this->actingAs($office)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL]), ['stage' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL])->assertRedirect()->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
        ->where('metadata->action_key', 'approve_for_regional_release')->count())->toBe(1);

    batch1bCompleteAfterApproval($this, $report, 1, $records->id);

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
    expect($report->fresh()->date_endorsed_regional?->toDateString())->toBe(CarbonImmutable::now('Asia/Manila')->toDateString())
        ->and($queues['history']->pluck('source_id')->all())->toContain($report->id)
        ->and($queues['processed']->pluck('source_id')->all())->not->toContain($report->id);
});

test('current canonical Regular Special and TWC routes reject completed-date clearing atomically', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    Notification::fake();
    $actors = [
        OrganizationalAccessService::CENRO_FOCAL => batch1bActor('CENRO CDS Focal Person', OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        OrganizationalAccessService::CENRO_CHIEF => batch1bActor('CENRO CDS Chief', OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        OrganizationalAccessService::CENRO_RECORDS => batch1bActor('CENRO Records Unit', OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        OrganizationalAccessService::PENRO_RECORDS => batch1bActor('PENRO Records Unit', OrganizationalAccessService::PENRO_RECORDS),
        OrganizationalAccessService::OFFICE_PENRO => batch1bActor('Office of the PENRO', OrganizationalAccessService::OFFICE_PENRO),
        OrganizationalAccessService::PENRO_TSD_CHIEF => batch1bActor('PENRO TSD Chief', OrganizationalAccessService::PENRO_TSD_CHIEF),
        OrganizationalAccessService::PENRO_FOCAL => batch1bActor('PENRO CDS Focal Person', OrganizationalAccessService::PENRO_FOCAL),
        OrganizationalAccessService::PENRO_CHIEF => batch1bActor('PENRO CDS Chief', OrganizationalAccessService::PENRO_CHIEF),
    ];
    $admin = batch1bActor('CDS Admin', 'CDS', 'PENRO Davao Oriental', ['password' => 'secret-password']);
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));
    $transition = app(DocumentRoutingTransitionService::class);
    $actions = [
        ['forward_to_cenro_chief', OrganizationalAccessService::CENRO_FOCAL], ['receive_at_cenro_chief', OrganizationalAccessService::CENRO_CHIEF],
        ['forward_to_cenro_records', OrganizationalAccessService::CENRO_CHIEF], ['receive_at_cenro_records', OrganizationalAccessService::CENRO_RECORDS],
        ['forward_to_penro_records', OrganizationalAccessService::CENRO_RECORDS], ['receive_at_penro_records', OrganizationalAccessService::PENRO_RECORDS],
        ['forward_to_office_penro', OrganizationalAccessService::PENRO_RECORDS], ['receive_at_office_penro', OrganizationalAccessService::OFFICE_PENRO],
        ['assign_to_tsd_chief', OrganizationalAccessService::OFFICE_PENRO], ['receive_at_tsd_chief', OrganizationalAccessService::PENRO_TSD_CHIEF],
        ['forward_to_cds_focal', OrganizationalAccessService::PENRO_TSD_CHIEF], ['receive_at_cds_focal', OrganizationalAccessService::PENRO_FOCAL],
        ['forward_to_cds_chief', OrganizationalAccessService::PENRO_FOCAL], ['receive_at_cds_chief', OrganizationalAccessService::PENRO_CHIEF],
        ['recommend_to_office_penro', OrganizationalAccessService::PENRO_CHIEF], ['receive_at_office_penro_final', OrganizationalAccessService::OFFICE_PENRO],
        ['approve_for_regional_release', OrganizationalAccessService::OFFICE_PENRO], ['receive_at_penro_records_final', OrganizationalAccessService::PENRO_RECORDS],
        ['release_to_regional', OrganizationalAccessService::PENRO_RECORDS],
    ];
    $tracking = app(SubmissionTrackingService::class);

    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        $report = batch1bReport($actors[OrganizationalAccessService::CENRO_FOCAL], [
            'workflow_key' => $workflow,
            'activity_name' => 'Canonical completed-date guard '.$workflow,
            'date_report_released_cenro' => null,
            'date_received_penro' => null,
            'date_endorsed_regional' => null,
            'mov_processing_status' => \App\Services\SubmissionTracking\PambMovProcessingService::READY_FOR_RELEASE,
            'mov_file_name' => 'minutes.pdf',
            'mov_file_path' => 'canonical-route/'.$workflow.'.pdf',
        ]);
        Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\ncanonical isolated fixture");
        foreach ($actions as [$action, $category]) {
            $transition->transition($report->fresh()->load('protectedArea'), 'conservation', $action, $actors[$category]->id);
        }

        $canonicalEvents = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
            ->orderBy('id')->get()->map(fn (DocumentRoutingEvent $event): array => [
                $event->id, $event->event_key, $event->from_stage, $event->to_stage,
                $event->occurred_at?->toDateTimeString(), $event->recorded_by,
            ])->all();
        expect(count($canonicalEvents))->toBe(count($actions))
            ->and($report->fresh()->routingEvents()->count())->toBe(0)
            ->and($transition->state($report->fresh()->load('protectedArea'), 'conservation')['stage'])
                ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL);

        $this->actingAs($admin)->patch(route('submission-tracking.correct-routing', ['conservation', $report->id]), [
            'dates' => ['date_report_released_cenro' => '2026-08-19'],
            'reason' => 'Correct the completed CENRO business date.',
            'password' => 'secret-password',
        ])->assertSessionHasNoErrors();

        $afterValid = $report->fresh();
        $eventsAfterValid = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
            ->orderBy('id')->get()->map(fn (DocumentRoutingEvent $event): array => [
                $event->id, $event->event_key, $event->from_stage, $event->to_stage,
                $event->occurred_at?->toDateTimeString(), $event->recorded_by,
            ])->all();
        $beforeRejected = [
            'dates' => [$afterValid->date_report_released_cenro?->toDateString(), $afterValid->date_received_penro?->toDateString(), $afterValid->date_endorsed_regional?->toDateString()],
            'events' => $eventsAfterValid,
            'corrections' => \App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->get()->toArray(),
            'audits' => \App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $report->id)->where('action', 'Routing Date Corrected')->get()->toArray(),
        ];
        $this->patch(route('submission-tracking.correct-routing', ['conservation', $report->id]), [
            'dates' => ['date_received_penro' => '2026-08-18', 'date_report_released_cenro' => null],
            'reason' => 'Reject mixed valid and invalid completed-date edits.',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('dates.date_report_released_cenro');

        $props = $this->actingAs($admin)->get(route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $report->id]))->assertOk()->inertiaProps();
        $selected = data_get($props, 'trackingContext.selected_record');
        $history = collect(data_get($props, 'workspaceQueues.history', []))->contains(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $report->id);
        $queues = data_get($props, 'workspaceQueues', []);
        $contains = fn (string $queue): bool => collect(data_get($queues, $queue, []))->contains(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $report->id);
        $finalRecord = $report->fresh();
        $eventsAfterRejected = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
            ->orderBy('id')->get()->map(fn (DocumentRoutingEvent $event): array => [
                $event->id, $event->event_key, $event->from_stage, $event->to_stage,
                $event->occurred_at?->toDateTimeString(), $event->recorded_by,
            ])->all();
        expect([$finalRecord->date_report_released_cenro?->toDateString(), $finalRecord->date_received_penro?->toDateString(), $finalRecord->date_endorsed_regional?->toDateString()])
            ->toBe($beforeRejected['dates'])
            ->and($eventsAfterRejected)->toBe($beforeRejected['events'])
            ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $report->id)->get()->toArray())->toBe($beforeRejected['corrections'])
            ->and(\App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $report->id)->where('action', 'Routing Date Corrected')->get()->toArray())->toBe($beforeRejected['audits'])
            ->and($selected['date_report_released_cenro'])->toBe('2026-08-19')
            ->and($selected['routing_complete'])->toBeTrue()
            ->and($selected['routing']['actions'])->toBeEmpty()
            ->and($selected['mov_processing']['status_key'])->toBe(\App\Services\SubmissionTracking\PambMovProcessingService::RELEASED_BY_CENRO)
            ->and($selected['mov_processing']['percent'])->toBe(100)
            ->and($history)->toBeTrue()
            ->and($contains('incoming'))->toBeFalse()
            ->and($contains('outgoing'))->toBeFalse();
    }
});

test('completed legacy mixed PAMB histories reject clearing exposed cycle-aware internal timestamps atomically', function (): void {
    Storage::fake('local');
    Notification::fake();
    $actors = [
        OrganizationalAccessService::CENRO_FOCAL => batch1bActor('CENRO CDS Focal Person', OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        OrganizationalAccessService::CENRO_RECORDS => batch1bActor('CENRO Records Unit', OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        OrganizationalAccessService::PENRO_RECORDS => batch1bActor('PENRO Records Unit', OrganizationalAccessService::PENRO_RECORDS),
        OrganizationalAccessService::OFFICE_PENRO => batch1bActor('Office of the PENRO', OrganizationalAccessService::OFFICE_PENRO),
        OrganizationalAccessService::PENRO_TSD_CHIEF => batch1bActor('PENRO TSD Chief', OrganizationalAccessService::PENRO_TSD_CHIEF),
        OrganizationalAccessService::PENRO_FOCAL => batch1bActor('PENRO CDS Focal Person', OrganizationalAccessService::PENRO_FOCAL),
        OrganizationalAccessService::PENRO_CHIEF => batch1bActor('PENRO CDS Chief', OrganizationalAccessService::PENRO_CHIEF),
    ];
    $admin = batch1bActor('CDS Admin', 'CDS', 'PENRO Davao Oriental', ['password' => 'secret-password']);
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.correct-routing', 'web'));
    $report = batch1bReport($actors[OrganizationalAccessService::CENRO_FOCAL], [
        'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05',
        'date_endorsed_regional' => null,
        'mov_processing_status' => \App\Services\SubmissionTracking\PambMovProcessingService::READY_FOR_RELEASE,
        'mov_file_name' => 'legacy-meeting-minutes.pdf', 'mov_file_path' => 'legacy-routing/full-complete-pamb.pdf',
    ]);
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\nlegacy isolated fixture");
    $timeline = app(PambRoutingTimelineService::class);
    $timeline->recordCanonical($report, SubmissionTrackingService::CENRO_RELEASE, '2026-08-04', $actors[OrganizationalAccessService::CENRO_RECORDS]->id, CarbonImmutable::parse('2026-08-04 09:00:00', 'Asia/Manila'));
    $timeline->recordCanonical($report, SubmissionTrackingService::PENRO_RECEIPT, '2026-08-05', $actors[OrganizationalAccessService::PENRO_RECORDS]->id, CarbonImmutable::parse('2026-08-05 09:00:00', 'Asia/Manila'));

    $legacyEvents = [
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
        [PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL, OrganizationalAccessService::OFFICE_PENRO, '2026-08-06 09:00:00'],
        [PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL, OrganizationalAccessService::PENRO_RECORDS, '2026-08-06 11:00:00'],
    ];
    foreach ($legacyEvents as [$stage, $category, $occurredAt]) {
        $timeline->record($report, $stage, $occurredAt, $actors[$category]->id);
    }
    $timeline->recordCanonical($report, SubmissionTrackingService::REGIONAL_ENDORSEMENT, '2026-08-07', $actors[OrganizationalAccessService::PENRO_RECORDS]->id, CarbonImmutable::parse('2026-08-07 09:00:00', 'Asia/Manila'));

    $this->actingAs($admin);
    $fresh = $report->fresh()->load('protectedArea');
    $sharedState = app(DocumentRoutingTransitionService::class)->state($fresh, 'conservation');
    $presentation = $timeline->present($fresh);
    expect($sharedState['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($presentation['routing_complete'])->toBeTrue()
        ->and($presentation['actions'])->toBeEmpty()
        ->and($fresh->routingEvents()->count())->toBe(count($legacyEvents) + 4);

    $tracking = app(SubmissionTrackingService::class);
    $queueSnapshot = fn (): array => collect($tracking->workspaceQueues())
        ->map(fn ($queue): array => $queue->values()->all())
        ->all();
    $mov = app(\App\Services\SubmissionTracking\PambMovProcessingService::class)->present($fresh);
    $before = [
        'record' => $fresh->getRawOriginal(),
        'legacy_events' => $fresh->routingEvents()->orderBy('id')->get()->toArray(),
        'shared_events' => DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $fresh->id)->orderBy('id')->get()->toArray(),
        'corrections' => \App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $fresh->id)->get()->toArray(),
        'audits' => \App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $fresh->id)->where('action', 'Routing Date Corrected')->get()->toArray(),
        'queues' => $queueSnapshot(),
        'mov' => $mov,
    ];
    $this->patch(route('submission-tracking.correct-routing', ['conservation', $fresh->id]), [
        'dates' => ['date_received_penro' => '2026-08-06'],
        'internal_events' => [PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO => null],
        'reason' => 'Reject a completed internal timestamp clear.',
        'password' => 'secret-password',
    ])->assertSessionHasErrors('internal_events.'.PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO);

    $after = $fresh->fresh()->load('protectedArea');
    $afterMov = app(\App\Services\SubmissionTracking\PambMovProcessingService::class)->present($after);
    expect($after->getRawOriginal())->toBe($before['record'])
        ->and($after->routingEvents()->orderBy('id')->get()->toArray())->toBe($before['legacy_events'])
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $fresh->id)->orderBy('id')->get()->toArray())->toBe($before['shared_events'])
        ->and(\App\Models\SubmissionRoutingCorrection::query()->where('source', 'conservation')->where('source_id', $fresh->id)->get()->toArray())->toBe($before['corrections'])
        ->and(\App\Models\AuditLog::query()->where('entity_type', 'conservation')->where('entity_id', (string) $fresh->id)->where('action', 'Routing Date Corrected')->get()->toArray())->toBe($before['audits'])
        ->and($queueSnapshot())->toBe($before['queues'])
        ->and($afterMov)->toBe($before['mov'])
        ->and($timeline->present($after)['routing_complete'])->toBeTrue();
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
