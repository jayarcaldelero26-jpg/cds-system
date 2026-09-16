<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\SubmissionRoutingAttachment;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\NonWorkingDay;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pambRoleUser(string $role, string $section, string $office = 'CENRO Mati', array $attributes = []): User
{
    $user = User::factory()->create(['unit_assignment' => 'conservation', ...$attributes, 'section' => $section, 'office_designated' => $office]);
    $spatieRole = Role::findOrCreate($role, 'web');
    $permissions = collect([
        'reports.view',
        'technical-reports.view',
        'technical-reports.create',
        'technical-reports.update',
    ])->map(fn (string $permission) => Permission::findOrCreate($permission, 'web'))->all();
    $spatieRole->syncPermissions($permissions);
    $user->assignRole($spatieRole);

    return $user;
}

function pambReport(User $user, array $overrides = []): ConservationReportSubmission
{
    return ConservationReportSubmission::create([...[
        'workflow_key' => 'regular_pamb',
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], ...$overrides]);
}

test('a conducted record without an MOV starts at zero percent and an uploaded MOV is thirty-five percent', function (): void {
    $user = User::factory()->create();
    $service = app(PambMovProcessingService::class);
    $report = pambReport($user);

    expect($service->present($report)['percent'])->toBe(0)
        ->and($service->present($report)['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED);

    $report->update(['mov_file_path' => 'conservation-report-movs/example.pdf']);
    expect($service->present($report->fresh())['percent'])->toBe(35)
        ->and($service->present($report->fresh())['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED)
        ->and($service->present($report->fresh())['status_label'])->toBe('MOV Uploaded / Ready for Submission')
        ->and($service->present($report->fresh())['workflow_status'])->toBe('Pending Submission by CENRO')
        ->and($service->present($report->fresh())['queue'])->toBe('for_submission');
});

test('saving a MOV records upload without submitting it for review', function (): void {
    Storage::fake('local');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');

    $this->actingAs($focal)->post(route('conservation-reports.store', ['workflow' => 'regular_pamb']), [
        'target_office' => 'CENRO Mati',
        'protected_area_id' => null,
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 2',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'mov' => UploadedFile::fake()->create('quarter-2.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->latest('id')->firstOrFail();
    $present = app(PambMovProcessingService::class)->present($report);

    expect($present['percent'])->toBe(35)
        ->and($present['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED)
        ->and($present['status_label'])->toBe('MOV Uploaded / Ready for Submission')
        ->and($present['queue'])->toBe('for_submission')
        ->and($report->movReviewEvents()->pluck('event_key')->all())->toBe([PambMovProcessingService::MOV_UPLOADED]);

    $snapshot = app(SubmissionTrackingService::class)->snapshot();
    expect($snapshot['queues']['for_submission']->pluck('source_id')->all())->toContain($report->id)
        ->and($snapshot['queues']['for_review']->pluck('source_id')->all())->not->toContain($report->id);
});

test('a newly saved CENRO-managed Regular PAMB report enters only its CENRO CDS Focal Incoming workspace', function (): void {
    Storage::fake('local');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Baganga');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Baganga');
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Baganga');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $penroFocal = pambRoleUser('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $area = ProtectedArea::create([
        'name' => 'Baganga Mangrove Swamp Forest Reserve', 'short_name' => 'BMSFR',
        'category' => 'Protected Landscape', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_baganga')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $focal->id,
    ]);

    $this->actingAs($focal)->post(route('conservation-reports.store', ['workflow' => 'regular_pamb']), [
        'target_office' => 'CENRO Baganga', 'protected_area_id' => $area->id,
        'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-09-01',
        'date_accomplished' => '2026-09-01',
        'mov' => UploadedFile::fake()->create('bmsfr-september.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->latest('id')->firstOrFail();
    $tracking = app(SubmissionTrackingService::class);
    $row = $tracking->records()->firstWhere('source_id', $report->id);
    $workspace = $tracking->workspaceQueues();

    expect($row['source'])->toBe('conservation')
        ->and($row['submission_status'])->toBe('Pending Submission by CENRO')
        ->and($row['routing']['responsible_office'])->toBe('CENRO Baganga')
        ->and($row['routing']['responsible_user_category'])->toBe('CENRO_CDS_FOCAL')
        ->and($row['routing']['next_expected_action'])->toBe('Submit MOV/report for CENRO CDS Chief review')
        ->and($workspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($workspace['incoming']->firstWhere('source_id', $report->id)['incoming_action_category'])->toBe('decision')
        ->and($workspace['history']->pluck('source_id')->all())->not->toContain($report->id);

    foreach ([$chief, $records, $penroRecords, $penroFocal] as $nonOwner) {
        $this->actingAs($nonOwner);
        expect($tracking->workspaceQueues()['incoming']->pluck('source_id')->all())->not->toContain($report->id);
    }
});

test('a direct-PENRO Regular PAMB report does not enter a CENRO Incoming workspace', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Baganga');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $area = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro',
        'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    $report = pambReport($focal, [
        'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental',
        'mov_file_path' => 'conservation-report-movs/mhrws-initial.pdf',
    ]);
    $tracking = app(SubmissionTrackingService::class);

    $this->actingAs($focal);
    expect($tracking->workspaceQueues()['incoming']->pluck('source_id')->all())->not->toContain($report->id);

    $this->actingAs($penroRecords);
    expect($tracking->workspaceQueues()['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($tracking->records()->firstWhere('source_id', $report->id)['routing']['responsible_user_category'])->toBe('PENRO_RECORDS');
});

test('PENRO Records receipt accepts a working copy and hands off directly to Office of the PENRO', function (): void {
    Storage::fake('local');
    $records = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $report = pambReport($records, [
        'target_office' => 'CENRO Mati',
        'date_report_released_cenro' => '2026-08-04',
    ]);
    $tracking = app(SubmissionTrackingService::class);

    $this->actingAs($records);
    $before = $tracking->records()->firstWhere('source_id', $report->id);
    expect(collect($before['routing']['actions'])->pluck('action_label')->all())
        ->toBe(['Receive', 'Return for Correction'])
        ->and(collect($before['routing']['actions'])->firstWhere('key', 'penro_receipt')['attachment_allowed'])->toBeTrue()
        ->and(collect($before['routing']['actions'])->pluck('key')->all())->not->toContain('release_to_regional');

    expect($tracking->canAttachRoutingCopy('conservation', $report, SubmissionTrackingService::PENRO_RECEIPT))->toBeTrue();
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
        'stage' => SubmissionTrackingService::PENRO_RECEIPT,
        'date' => '2026-08-05',
        'attachment' => UploadedFile::fake()->create('penro-received.pdf', 12, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $attachment = SubmissionRoutingAttachment::query()->where('source', 'conservation')->where('source_id', $report->id)->firstOrFail();
    expect(SubmissionRoutingAttachment::query()->where('source', 'conservation')->where('source_id', $report->id)->count())->toBe(1)
        ->and($attachment->purpose)->toBe('routing_copy')
        ->and($attachment->pambRoutingEvent->stage_key)->toBe(PambRoutingTimelineService::RECORDS_RECEIVED);

    $this->actingAs($records);
    $recordsWorkspace = $tracking->workspaceQueues();
    expect($recordsWorkspace['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($recordsWorkspace['outgoing']->pluck('source_id')->all())->toContain($report->id);
    $this->actingAs($office);
    $officeRow = $tracking->workspaceQueues()['incoming']->firstWhere('source_id', $report->id);
    expect($officeRow)->not->toBeNull()
        ->and($officeRow['routing']['current_stage'])->toBe(PambRoutingTimelineService::RECEIVED_BY_PENRO)
        ->and($officeRow['routing']['responsible_user_category'])->toBe('OFFICE_OF_THE_PENRO');

    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT,
    ]), [
        'stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT,
        'date' => '2026-08-06',
    ])->assertForbidden();
});

test('PENRO TSD Receive keeps ownership until the explicit Forward action', function (): void {
    $records = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $tsd = pambRoleUser('PENRO TSD Chief', 'PENRO_TSD_CHIEF', 'PENRO Davao Oriental');
    $focal = pambRoleUser('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $report = pambReport($records, [
        'target_office' => 'CENRO Mati',
        'date_report_released_cenro' => '2026-08-04',
        'date_received_penro' => '2026-08-05',
    ]);
    // A stale generic handoff must not override newer PAMB milestones.
    DocumentRoutingEvent::create([
        'source_type' => 'conservation',
        'source_id' => $report->id,
        'workflow_key' => $report->workflow_key,
        'event_key' => 'forwarded',
        'from_stage' => 'cenro_records',
        'to_stage' => 'transit_to_penro_records',
        'from_office' => 'CENRO Records Unit',
        'to_office' => 'PENRO Records Unit',
        'occurred_at' => '2026-08-05 08:00:00',
        'recorded_by' => $records->id,
        'metadata' => ['action_key' => 'forward_to_penro_records'],
    ]);
    $timeline = app(PambRoutingTimelineService::class);
    $timeline->record($report->fresh(), PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-06 09:00:00', $records->id);
    $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO, '2026-08-06 10:00:00', $office->id);
    $timeline->record($report->fresh(), PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-06 11:00:00', $office->id);

    $this->actingAs($tsd);
    $tracking = app(SubmissionTrackingService::class);
    $before = $tracking->records()->firstWhere('source_id', $report->id);
    $beforeQueue = $tracking->workspaceQueues();
    expect($before['routing']['responsible_user_category'])->toBe('PENRO_TSD_CHIEF')
        ->and($before['routing']['next_expected_action'])->toBe('Record Receipt by PENRO TSD Chief')
        ->and($beforeQueue['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and(collect($tracking->dashboardActionQueue())->contains(fn (array $item): bool => (int) $item['source_id'] === $report->id && $item['required_action'] === 'Record Receipt by PENRO TSD Chief'))->toBeTrue();

    $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_TSD, '2026-08-07 09:00:00', $tsd->id);
    $afterReceive = $tracking->records()->firstWhere('source_id', $report->id);
    $afterReceiveQueue = $tracking->workspaceQueues();
    expect($afterReceive['routing']['responsible_user_category'])->toBe('PENRO_TSD_CHIEF')
        ->and($afterReceive['routing']['next_expected_action'])->toBe('Forward to PENRO CDS Focal Person')
        ->and($afterReceiveQueue['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($afterReceiveQueue['outgoing']->pluck('source_id')->all())->not->toContain($report->id)
        ->and(collect($tracking->dashboardActionQueue())->contains(fn (array $item): bool => (int) $item['source_id'] === $report->id && $item['required_action'] === 'Forward to PENRO CDS Focal Person'))->toBeTrue();

    $timeline->record($report->fresh(), PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, '2026-08-07 10:00:00', $tsd->id);
    $afterForwardQueue = $tracking->workspaceQueues();
    $this->actingAs($focal);
    $focalQueue = $tracking->workspaceQueues();
    expect($afterForwardQueue['incoming']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($afterForwardQueue['outgoing']->pluck('source_id')->all())->toContain($report->id)
        ->and($focalQueue['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and(collect($tracking->dashboardActionQueue())->contains(fn (array $item): bool => (int) $item['source_id'] === $report->id))->toBeTrue();
});

test('completed PAMB submissions reject source and routing attachment mutations while retaining document access', function (): void {
    Storage::fake('local');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $report = pambReport($focal, [
        'date_report_released_cenro' => '2026-08-04',
        'date_received_penro' => '2026-08-05',
        'date_endorsed_regional' => '2026-08-06',
        'mov_file_path' => 'conservation-report-movs/completed.pdf',
        'mov_file_name' => 'completed.pdf',
    ]);
    Storage::disk('local')->put($report->mov_file_path, '%PDF completed');
    expect(app(SubmissionTrackingService::class)->isRoutingComplete($report))->toBeTrue()
        ->and(app(SubmissionTrackingService::class)->canAttachRoutingCopy('conservation', $report, SubmissionTrackingService::REGIONAL_ENDORSEMENT))->toBeFalse();

    $this->actingAs($focal)->put(route('conservation-reports.update', ['regular_pamb', $report->id]), [
        'target_office' => 'CENRO Mati', 'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1', 'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
        'mov' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('submission');
    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), [
        'stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-07',
        'attachment' => UploadedFile::fake()->create('late-copy.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('attachment');
    $this->actingAs($focal)->get(route('conservation-reports.mov', ['regular_pamb', $report->id]))->assertOk();
    expect(SubmissionRoutingAttachment::query()->where('source', 'conservation')->where('source_id', $report->id)->count())->toBe(0);
});

test('routing attachment UI only renders upload progress for an active selected File', function (): void {
    $field = file_get_contents(resource_path('js/Components/SubmissionTracking/RoutingAttachmentField.jsx'));
    $tracker = file_get_contents(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));

    expect($field)->toContain("file instanceof File")
        ->toContain('hasSelectedAttachment && processing && uploadProgress')
        ->not->toContain('>Preview</a>')
        ->and($tracker)->toContain('canUpdate && !completedSubmission');
});

test('submitting for review is idempotent within a review cycle', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/idempotent.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $submittedAt = $report->fresh()->mov_submitted_at;

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $submitted = $report->fresh();

    expect($submitted->mov_processing_status)->toBe(PambMovProcessingService::SUBMITTED_FOR_REVIEW)
        ->and($submitted->mov_submitted_at?->equalTo($submittedAt))->toBeTrue()
        ->and($submitted->movReviewEvents()->where('event_key', PambMovProcessingService::SUBMITTED_FOR_REVIEW)->count())->toBe(1)
        ->and(app(PambMovProcessingService::class)->present($submitted)['workflow_status'])->toBe('Awaiting Review by CENRO CDS Chief');

    $snapshot = app(SubmissionTrackingService::class)->snapshot();
    expect($snapshot['queues']['for_submission']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($snapshot['queues']['for_review']->pluck('source_id')->all())->toContain($report->id);
});

test('the active MOV marker uses a compact concentric pulse ring with reduced-motion fallback', function (): void {
    $component = file_get_contents(resource_path('js/Components/SubmissionTracking/PambMovProgress.jsx'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($component)->toContain('edats-current-stage-marker__halo')
        ->toContain('edats-current-stage-marker__ripple--first')
        ->toContain('edats-current-stage-marker__ripple--second')
        ->toContain('disabled={submitting}')
        ->and($styles)->toContain('@keyframes edats-current-stage-ripple')
        ->toContain('2.2s ease-out infinite')
        ->toContain('animation-delay: 1.1s')
        ->toContain('scale(0.70)')
        ->toContain('scale(1.55)')
        ->toContain('prefers-reduced-motion: reduce');
});

test('Chief review supports ready and correction decisions without changing compliance values', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/review.pdf']);
    $deadline = $report->deadline_submission;
    $timeliness = $report->timeliness;

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Please attach the signed attendance sheet.'])->assertSessionHasNoErrors();

    $corrected = $report->fresh();
    expect($corrected->mov_processing_status)->toBe(PambMovProcessingService::NEEDS_CORRECTION)
        ->and(app(PambMovProcessingService::class)->present($corrected)['percent'])->toBe(35)
        ->and($corrected->deadline_submission)->toBe($deadline)
        ->and($corrected->timeliness)->toBe($timeliness)
        ->and($corrected->movReviewEvents()->count())->toBe(2);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    expect($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::SUBMITTED_FOR_REVIEW)->count())->toBe(1)
        ->and($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::RESUBMITTED_FOR_REVIEW)->count())->toBe(1);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE])->assertSessionHasNoErrors();
    expect(app(PambMovProcessingService::class)->present($report->fresh())['percent'])->toBe(70);
});

test('Chief review decisions are idempotent within the current review state', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/idempotent-review.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE])->assertSessionHasNoErrors();
    $reviewedAt = $report->fresh()->mov_reviewed_at;

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE])->assertSessionHasNoErrors();
    $reviewed = $report->fresh();

    expect($reviewed->mov_processing_status)->toBe(PambMovProcessingService::READY_FOR_RELEASE)
        ->and($reviewed->mov_reviewed_at?->equalTo($reviewedAt))->toBeTrue()
        ->and($reviewed->movReviewEvents()->where('event_key', PambMovProcessingService::READY_FOR_RELEASE)->count())->toBe(1);
});

test('CENRO review summary exposes the current verdict and preserves prior correction cycles', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/verdict-summary.pdf']);
    $service = app(PambMovProcessingService::class);
    $service->recordUpload($report, $focal);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    expect($service->present($report->fresh())['cenro_review']['verdict'])->toBe('Awaiting CENRO CDS Chief Review');

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Attach the signed resolution.']);
    $needsCorrection = $service->present($report->fresh())['cenro_review'];
    expect($needsCorrection['verdict'])->toBe('Needs Correction')
        ->and($needsCorrection['reviewed_by'])->toBe($chief->name)
        ->and($needsCorrection['reviewed_user_category'])->toBe('CENRO CDS Chief')
        ->and($needsCorrection['correction_reason'])->toBe('Attach the signed resolution.')
        ->and($needsCorrection['previous_correction_cycles'])->toBe(1);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $awaitingSecondReview = $service->present($report->fresh())['cenro_review'];
    expect($awaitingSecondReview['verdict'])->toBe('Awaiting CENRO CDS Chief Review')
        ->and($awaitingSecondReview['previous_correction']['reason'])->toBe('Attach the signed resolution.');

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE]);
    $final = $service->present($report->fresh())['cenro_review'];
    expect($final['verdict'])->toBe('Ready for Release')
        ->and($final['reviewed_by'])->toBe($chief->name)
        ->and($final['reviewed_user_category'])->toBe('CENRO CDS Chief')
        ->and($final['previous_correction_cycles'])->toBe(1)
        ->and($final['previous_correction']['reason'])->toBe('Attach the signed resolution.')
        ->and($report->fresh()->movReviewEvents()->reorder('id', 'asc')->pluck('event_key')->all())->toBe([
            PambMovProcessingService::MOV_UPLOADED,
            PambMovProcessingService::SUBMITTED_FOR_REVIEW,
            PambMovProcessingService::NEEDS_CORRECTION,
            PambMovProcessingService::RESUBMITTED_FOR_REVIEW,
            PambMovProcessingService::READY_FOR_RELEASE,
        ]);
});

test('For Review status presents the Chief as the next action owner', function (): void {
    $component = file_get_contents(resource_path('js/Components/SubmissionTracking/PambMovProgress.jsx'));
    $page = file_get_contents(resource_path('js/Pages/SubmissionTracking/Index.jsx'));

    expect($component)->toContain('Review Status')
        ->toContain('Awaiting Review by CENRO CDS Chief')
        ->toContain('Next Action: CENRO CDS Chief must review this MOV/report.')
        ->toContain('Edit / Correct Submission')
        ->and(preg_replace('/\\s+/', ' ', $page))->toContain('mov_processing')
        ->toContain('workflow_status')->toContain('submission_status')->toContain('Workflow Status')->toContain('Routing Status');

});
test('needs correction requires remarks and returns the record to the focal queue', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/correction.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION])->assertSessionHasErrors('remarks');
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Please correct the signature page.'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->get(route('submission-tracking.index'))->assertInertia(fn ($page) => $page->where('queues.needs_correction.0.source_id', $report->id));
});

test('records release uses the canonical CENRO release date and reaches one hundred percent', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/release.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE]);
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE]), ['stage' => SubmissionTrackingService::CENRO_RELEASE, 'date' => '2026-08-10'])->assertSessionHasNoErrors();

    $released = $report->fresh();
    expect($released->date_report_released_cenro->toDateString())->toBe('2026-08-10')
        ->and(app(PambMovProcessingService::class)->present($released)['percent'])->toBe(100)
        ->and(app(PambMovProcessingService::class)->present($released)['status_label'])->toBe('Released by CENRO to PENRO');
});

test('CENRO office scope and PAMO protected-area scope are enforced on tracking and attachments', function (): void {
    Storage::fake('local');
    $cenro = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $area = ProtectedArea::create(['name' => 'Mati Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $cenro->id, 'updated_by' => $cenro->id]);
    $otherArea = ProtectedArea::create(['name' => 'Baganga Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $cenro->id, 'updated_by' => $cenro->id]);
    $pamo = pambRoleUser('PAMO', 'PAMO', 'PENRO Davao Oriental', ['protected_area_id' => $area->id]);
    $visible = pambReport($cenro, ['protected_area_id' => $area->id, 'mov_file_path' => 'conservation-report-movs/visible.pdf']);
    $hidden = pambReport($cenro, ['target_office' => 'CENRO Baganga', 'protected_area_id' => $otherArea->id, 'mov_file_path' => 'conservation-report-movs/hidden.pdf']);

    $this->actingAs($cenro)->get(route('submission-tracking.index'))->assertInertia(fn ($page) => $page->where('queues.for_release', fn ($queue) => collect($queue)->pluck('source_id')->doesntContain($hidden->id)));
    $this->actingAs($cenro)->get(route('attachments.show', ['conservation-report', $hidden->id, 'mov']))->assertForbidden();
    $this->actingAs($pamo)->get(route('submission-tracking.index'))->assertForbidden();
    expect($visible->protected_area_id)->toBe($area->id);
});

test('Submission Tracking serves original Conservation MOVs through the protected attachment endpoint', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/records-original.pdf']);
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\nprotected original MOV");
    $protectedUrl = route('attachments.show', ['source' => 'conservation-report', 'record' => $report->id, 'attachment' => 'mov']);

    $this->actingAs($records)->get(route('submission-tracking.index'))->assertOk();
    $row = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);

    expect($row['mov_url'])->toBe($protectedUrl)
        ->and($row['mov_attachment']['url'])->toBe($protectedUrl)
        ->and($row['current_document']['preview_url'])->toBe($protectedUrl)
        ->and($row['current_document']['download_url'])->toBe($protectedUrl)
        ->and($row['current_document']['source'])->toBe('Original MOV / report');

    $this->get($row['current_document']['preview_url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="records-original.pdf"');
    $this->get($row['current_document']['download_url'])->assertOk();

    $this->get(route('conservation-reports.mov', ['workflow' => $report->workflow_key, 'submission' => $report->id]))
        ->assertForbidden();
    $this->get(route('conservation-reports.index', $report->workflow_key))->assertForbidden();
});

test('protected original MOV access follows existing office and PA scope for tracking users', function (): void {
    Storage::fake('local');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $wrongCenro = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Baganga');
    $area = ProtectedArea::create([
        'name' => 'Mati Supervised Attachment PA',
        'short_name' => 'MSAPA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $focal->id,
        'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $focal->id,
    ]);
    $report = pambReport($focal, [
        'protected_area_id' => $area->id,
        'mov_file_path' => 'conservation-report-movs/scoped-original.pdf',
    ]);
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\nscoped MOV");
    $url = route('attachments.show', ['source' => 'conservation-report', 'record' => $report->id, 'attachment' => 'mov']);

    $this->actingAs($records)->get($url)->assertOk();
    $this->actingAs($wrongCenro)->get($url)->assertForbidden();

    foreach ([
        ['CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati'],
        ['CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati'],
        ['PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental'],
        ['Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental'],
        ['PENRO TSD Chief', 'PENRO_TSD_CHIEF', 'PENRO Davao Oriental'],
        ['PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental'],
        ['PENRO CDS Chief', 'PENRO_CDS_CHIEF', 'PENRO Davao Oriental'],
    ] as [$role, $section, $office]) {
        $user = pambRoleUser($role, $section, $office);
        $this->actingAs($user)->get($url)->assertOk();
    }

    $admin = pambRoleUser('Super Admin', 'SUPER_ADMIN', 'PENRO Davao Oriental');
    $this->actingAs($admin)->get($url)->assertOk();

    $inactive = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $inactive->update(['is_active' => false]);
    $this->actingAs($inactive)->get($url)->assertRedirect(route('login'));

    $unapproved = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $unapproved->update(['is_approved' => false]);
    $this->actingAs($unapproved)->get($url)->assertRedirect(route('login'));

    $mhrws = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $focal->id,
        'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $mhrws->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'penro_davao_oriental')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $focal->id,
    ]);
    $directPenro = pambReport($focal, [
        'protected_area_id' => $mhrws->id,
        'target_office' => 'PENRO Davao Oriental',
        'mov_file_path' => 'conservation-report-movs/mhrws-original.pdf',
    ]);
    Storage::disk('local')->put($directPenro->mov_file_path, "%PDF-1.4\ndirect PENRO MOV");
    $directUrl = route('attachments.show', ['source' => 'conservation-report', 'record' => $directPenro->id, 'attachment' => 'mov']);

    $this->actingAs($records)->get($directUrl)->assertForbidden();
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $this->actingAs($penroRecords)->get($directUrl)->assertOk();
});

test('PENRO-managed PAMB uses its legitimate PENRO MOV stages', function (): void {
    $user = User::factory()->create();
    $area = ProtectedArea::create(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $report = pambReport($user, ['protected_area_id' => $area->id, 'target_office' => 'PENRO Mati']);

    $present = app(PambMovProcessingService::class)->present($report);
    expect($present['applicable'])->toBeTrue()
        ->and($present['percent'])->toBe(0)
        ->and($present['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED)
        ->and($present['cenro_review']['applicable'])->toBeFalse()
        ->and(collect($present['milestones'])->pluck('key')->all())->not->toContain(PambMovProcessingService::RELEASED_BY_CENRO);
});

test('CENRO users cannot operate PENRO receipt, internal routing, or regional endorsement', function (): void {
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS');
    $report = pambReport($records);

    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-11'])->assertForbidden();
    $this->actingAs($records)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, 'records_to_penro']), ['stage' => 'records_to_penro', 'occurred_at' => '2026-08-11 09:00'])->assertForbidden();
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-11'])->assertForbidden();
});

test('turnaround status uses the authoritative PAMB calendar and configured non-working days', function (): void {
    $user = User::factory()->create();
    $report = pambReport($user);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12', 'Asia/Manila'));
    NonWorkingDay::create(['date' => '2026-08-11', 'name' => 'Configured PAMB non-working day', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);

    try {
        $turnaround = app(PambMovProcessingService::class)->present($report)['turnaround'];
        expect($turnaround['day'])->toBe(5)
            ->and($turnaround['remaining'])->toBe(2)
            ->and($turnaround['deadline'])->toBe('2026-08-17');
    } finally {
        CarbonImmutable::setTestNow();
    }
});


test('PambSubmissionAccess enforces one actor per internal stage', function (): void {
    $records = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $tsd = pambRoleUser('PENRO TSD Chief', 'PENRO_TSD_CHIEF', 'PENRO Davao Oriental');
    $focal = pambRoleUser('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $chief = pambRoleUser('PENRO CDS Chief', 'PENRO_CDS_CHIEF', 'PENRO Davao Oriental');
    $report = pambReport($records, [
        'date_report_released_cenro' => '2026-08-10',
        'date_received_penro' => null,
    ]);
    $access = app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class);

    expect($access->canPerformForSubmission($records, 'penro_receipt', $report))->toBeTrue()
        ->and($access->canPerformForSubmission($focal, 'penro_receipt', $report))->toBeFalse()
        ->and($access->canPerformForSubmission($chief, 'penro_receipt', $report))->toBeFalse()
        ->and($access->canRecordInternalRouting($records, $report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeFalse();

    $report->update(['date_received_penro' => '2026-08-11']);
    expect($access->canRecordInternalRouting($records, $report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeTrue()
        ->and($access->canRecordInternalRouting($office, $report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeFalse();

    timelineServiceForBatch1()->record($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-11 09:00:00', $records->id);

    expect($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO))->toBeTrue()
        ->and($access->canRecordInternalRouting($tsd, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO))->toBeFalse()
        ->and($access->canRecordInternalRouting($focal, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO))->toBeFalse();

    timelineServiceForBatch1()->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO, '2026-08-11 09:00:00', $office->id);
    expect($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD))->toBeTrue()
        ->and($access->canRecordInternalRouting($tsd, $report->fresh(), PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD))->toBeFalse();

    timelineServiceForBatch1()->record($report->fresh(), PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-11 09:00:00', $office->id);
    expect($access->canRecordInternalRouting($tsd, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_TSD))->toBeTrue()
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_TSD))->toBeFalse();

    timelineServiceForBatch1()->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_TSD, '2026-08-11 09:00:00', $tsd->id);
    expect($access->canRecordInternalRouting($tsd, $report->fresh(), PambRoutingTimelineService::FORWARDED_TSD_TO_CDS))->toBeTrue()
        ->and($access->canRecordInternalRouting($focal, $report->fresh(), PambRoutingTimelineService::FORWARDED_TSD_TO_CDS))->toBeFalse();

    timelineServiceForBatch1()->record($report->fresh(), PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, '2026-08-11 09:00:00', $tsd->id);
    expect($access->canRecordInternalRouting($focal, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_CDS))->toBeTrue()
        ->and($access->canRecordInternalRouting($chief, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_CDS))->toBeFalse();

    timelineServiceForBatch1()->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_CDS, '2026-08-11 09:00:00', $focal->id);
    expect($access->canRecordInternalRouting($focal, $report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF))->toBeTrue()
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF))->toBeFalse()
        ->and($access->canRecordInternalRouting($tsd, $report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF))->toBeFalse();

    timelineServiceForBatch1()->record($report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, '2026-08-11 09:00:00', $focal->id);
    expect($access->canRecordInternalRouting($chief, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF))->toBeTrue()
        ->and($access->canRecordInternalRouting($focal, $report->fresh(), PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF))->toBeFalse();

    timelineServiceForBatch1()->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, '2026-08-11 09:00:00', $chief->id);
    expect($access->canRecordInternalRouting($chief, $report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO))->toBeTrue()
        ->and($access->canRecordInternalRouting($office, $report->fresh(), PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO))->toBeFalse();
});

test('PAMB review uses the correct Chief for the routing context', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $cenroChief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $penroChief = pambRoleUser('PENRO CDS Chief', 'PENRO_CDS_CHIEF', 'PENRO Davao Oriental');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');

    $cenroReport = pambReport($focal, [
        'mov_file_path' => 'conservation-report-movs/cenro-review.pdf',
        'mov_processing_status' => PambMovProcessingService::SUBMITTED_FOR_REVIEW,
    ]);
    $directArea = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $focal->id,
        'updated_by' => $focal->id,
    ]);
    $directReport = pambReport($focal, [
        'protected_area_id' => $directArea->id,
        'target_office' => 'PENRO Davao Oriental',
        'mov_file_path' => 'conservation-report-movs/penro-review.pdf',
        'mov_processing_status' => PambMovProcessingService::SUBMITTED_FOR_REVIEW,
    ]);
    $access = app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class);

    expect($access->canPerformForSubmission($cenroChief, 'review', $cenroReport))->toBeTrue()
        ->and($access->canPerformForSubmission($penroChief, 'review', $cenroReport))->toBeFalse()
        ->and($access->canPerformForSubmission($penroChief, 'review', $directReport))->toBeFalse()
        ->and($access->canPerformForSubmission($cenroChief, 'review', $directReport))->toBeFalse();

    $this->actingAs($penroChief)->post(route('submission-tracking.mov.review', ['conservation', $cenroReport->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertForbidden();

    $this->actingAs($penroChief)->post(route('submission-tracking.mov.review', ['conservation', $directReport->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertForbidden();

    expect($access->canPerformForSubmission($penroChief, 'penro_receipt', $directReport))->toBeFalse()
        ->and($access->canPerformForSubmission($penroRecords, 'penro_receipt', $directReport))->toBeTrue()
        ->and($directReport->fresh()->date_received_penro)->toBeNull()
        ->and(app(PambMovProcessingService::class)->present($directReport->fresh())['workflow_status'])->toBe('Awaiting PENRO Records Receipt');
});

test('full CENRO to PENRO PAMB flow rejects every wrong PENRO category', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $cenroChief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $cenroRecords = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $penroFocal = pambRoleUser('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $penroChief = pambRoleUser('PENRO CDS Chief', 'PENRO_CDS_CHIEF', 'PENRO Davao Oriental');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $tsd = pambRoleUser('PENRO TSD Chief', 'PENRO_TSD_CHIEF', 'PENRO Davao Oriental');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/full-flow.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $this->actingAs($penroChief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertForbidden();
    $this->actingAs($cenroChief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertSessionHasNoErrors();

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE]), [
        'stage' => SubmissionTrackingService::CENRO_RELEASE,
        'date' => '2026-08-10',
    ])->assertForbidden();
    $this->actingAs($cenroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE]), [
        'stage' => SubmissionTrackingService::CENRO_RELEASE,
        'date' => '2026-08-10',
    ])->assertSessionHasNoErrors();

    foreach ([$penroFocal, $penroChief] as $wrongActor) {
        $this->actingAs($wrongActor)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
            'stage' => SubmissionTrackingService::PENRO_RECEIPT,
            'date' => '2026-08-11',
        ])->assertForbidden();
    }
    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
        'stage' => SubmissionTrackingService::PENRO_RECEIPT,
        'date' => '2026-08-11',
    ])->assertSessionHasNoErrors();

    $actorStages = [
        [PambRoutingTimelineService::RECEIVED_BY_PENRO, $office],
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, $office],
        [PambRoutingTimelineService::RECEIVED_BY_TSD, $tsd],
        [PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, $tsd],
        [PambRoutingTimelineService::RECEIVED_BY_CDS, $penroFocal],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, $penroFocal],
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, $penroChief],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, $penroChief],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, $office],
        [PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL, $office],
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS, $office],
    ];

    $this->actingAs($penroRecords)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO]), [
        'stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ])->assertSessionHasNoErrors();

    foreach ($actorStages as [$stage, $expectedActor]) {
        foreach ([$penroFocal, $penroChief, $penroRecords, $office, $tsd] as $wrongActor) {
            if ($wrongActor->is($expectedActor)) continue;
            $this->actingAs($wrongActor)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $stage]), [
                'stage' => $stage,
            ])->assertForbidden();
        }
        $this->actingAs($expectedActor)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $stage]), [
            'stage' => $stage,
        ])->assertSessionHasNoErrors();
    }

    $finalStage = PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL;
    foreach ([$penroFocal, $penroChief] as $wrongActor) {
        $this->actingAs($wrongActor)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $finalStage]), [
            'stage' => $finalStage,
        ])->assertForbidden();
    }
    $this->actingAs($penroRecords)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $finalStage]), [
        'stage' => $finalStage,
    ])->assertSessionHasNoErrors();

    foreach ([$penroFocal, $penroChief] as $wrongActor) {
        $this->actingAs($wrongActor)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), [
            'stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT,
            'date' => '2026-08-12',
        ])->assertForbidden();
    }
    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), [
        'stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT,
        'date' => '2026-08-12',
    ])->assertSessionHasNoErrors();

    expect($report->fresh()->date_endorsed_regional->toDateString())->toBe('2026-08-12');
});

function timelineServiceForBatch1(): PambRoutingTimelineService
{
    return app(PambRoutingTimelineService::class);
}


test('PAMB negative authority matrix denies cross-category operations', function (): void {
    $cenroFocal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $cenroChief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $cenroRecords = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $penroFocal = pambRoleUser('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $penroChief = pambRoleUser('PENRO CDS Chief', 'PENRO_CDS_CHIEF', 'PENRO Davao Oriental');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $tsd = pambRoleUser('PENRO TSD Chief', 'PENRO_TSD_CHIEF', 'PENRO Davao Oriental');
    $area = ProtectedArea::create([
        'name' => 'Assigned PAMB Area',
        'short_name' => 'APAMB',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $cenroFocal->id,
        'updated_by' => $cenroFocal->id,
    ]);
    $pamo = pambRoleUser('PAMO', 'PAMO', 'PENRO Davao Oriental', ['protected_area_id' => $area->id]);
    $reviewReport = pambReport($cenroFocal, ['mov_processing_status' => PambMovProcessingService::SUBMITTED_FOR_REVIEW]);
    $readyReport = pambReport($cenroFocal, ['mov_processing_status' => PambMovProcessingService::READY_FOR_RELEASE]);
    $receiptReport = pambReport($cenroFocal, ['date_report_released_cenro' => '2026-08-10']);
    $internalReport = pambReport($cenroFocal, [
        'date_report_released_cenro' => '2026-08-10',
        'date_received_penro' => '2026-08-11',
    ]);
    $pamoReport = pambReport($pamo, ['protected_area_id' => $area->id]);
    $access = app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class);

    expect($access->canPerformForSubmission($cenroChief, 'review', $reviewReport))->toBeTrue()
        ->and($access->canPerformForSubmission($penroChief, 'review', $reviewReport))->toBeFalse()
        ->and($access->canPerformForSubmission($pamo, 'review', $pamoReport))->toBeFalse()
        ->and($access->canPerformForSubmission($cenroFocal, 'review', $reviewReport))->toBeFalse()
        ->and($access->canPerformForSubmission($cenroRecords, 'review', $reviewReport))->toBeFalse()
        ->and($access->canPerformForSubmission($cenroRecords, 'release', $readyReport))->toBeTrue()
        ->and($access->canPerformForSubmission($cenroChief, 'release', $readyReport))->toBeFalse()
        ->and($access->canPerformForSubmission($cenroFocal, 'release', $readyReport))->toBeFalse()
        ->and($access->canPerformForSubmission($pamo, 'release', $pamoReport))->toBeFalse()
        ->and($access->canPerformForSubmission($penroRecords, 'release', $receiptReport))->toBeFalse()
        ->and($access->canPerformForSubmission($penroRecords, 'penro_receipt', $receiptReport))->toBeTrue()
        ->and($access->canPerformForSubmission($penroFocal, 'penro_receipt', $receiptReport))->toBeFalse()
        ->and($access->canPerformForSubmission($penroChief, 'penro_receipt', $receiptReport))->toBeFalse()
        ->and($access->canPerformForSubmission($cenroRecords, 'penro_receipt', $receiptReport))->toBeFalse()
        ->and($access->canPerformForSubmission($pamo, 'penro_receipt', $pamoReport))->toBeFalse()
        ->and($access->canRecordInternalRouting($penroRecords, $internalReport, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeTrue()
        ->and($access->canRecordInternalRouting($penroFocal, $internalReport, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeFalse()
        ->and($access->canRecordInternalRouting($penroChief, $internalReport, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeFalse()
        ->and($access->canRecordInternalRouting($cenroRecords, $internalReport, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeFalse()
        ->and($access->canRecordInternalRouting($pamo, $pamoReport, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeFalse();
});

test('legacy PAMO accounts cannot submit MHRWS MOVs inside eDATS', function (): void {
    $pamo = pambRoleUser('PAMO', 'PAMO', 'PENRO Davao Oriental');

    $this->actingAs($pamo)->get('/submission-tracking')->assertForbidden();
});

test('MHRWS Office of the PENRO correction loop stays inside PENRO and preserves history', function (): void {
    $pamo = pambRoleUser('PAMO', 'PAMO', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $tsd = pambRoleUser('PENRO TSD Chief', 'PENRO_TSD_CHIEF', 'PENRO Davao Oriental');
    $penroFocal = pambRoleUser('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $penroChief = pambRoleUser('PENRO CDS Chief', 'PENRO_CDS_CHIEF', 'PENRO Davao Oriental');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');

    $area = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $pamo->id,
        'updated_by' => $pamo->id,
    ]);
    $pamo->update(['protected_area_id' => $area->id]);
    $report = pambReport($pamo, [
        'protected_area_id' => $area->id,
        'target_office' => 'PENRO Davao Oriental',
        'date_received_penro' => '2026-09-01',
    ]);

    $timeline = app(PambRoutingTimelineService::class);
    $seed = [
        [PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, $penroRecords],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO, $office],
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, $office],
        [PambRoutingTimelineService::RECEIVED_BY_TSD, $tsd],
        [PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, $tsd],
        [PambRoutingTimelineService::RECEIVED_BY_CDS, $penroFocal],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, $penroFocal],
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, $penroChief],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO, $penroChief],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, $office],
    ];
    foreach ($seed as [$stage, $actor]) {
        $timeline->record($report->fresh(), $stage, '2026-09-01 09:00:00', $actor->id);
    }

    $this->actingAs($office)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION]), [
        'stage' => PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION,
        'remarks' => 'Please correct the missing technical attachment.',
    ])->assertSessionHasNoErrors();

    expect($report->fresh()->routingEvents()->where('stage_key', PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION)->count())->toBe(1);

    $cycleTwo = [
        [PambRoutingTimelineService::RECEIVED_BY_CDS.'__cycle_2', $penroFocal],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF.'__cycle_2', $penroFocal],
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF.'__cycle_2', $penroChief],
        [PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO.'__cycle_2', $penroChief],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL.'__cycle_2', $office],
    ];
    foreach ($cycleTwo as [$stage, $actor]) {
        $this->actingAs($actor)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $stage]), [
            'stage' => $stage,
        ])->assertSessionHasNoErrors();
    }

    $history = $timeline->present($report->fresh());
    expect($report->fresh()->routingEvents()->where('stage_key', 'like', '%__cycle_2')->count())->toBe(5)
        ->and(collect($history['timeline'])->pluck('key')->all())
            ->toContain(PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION)
            ->and(collect($history['timeline'])->pluck('key')->filter(fn (string $key): bool => str_contains($key, 'cenro'))->all())
            ->toBeEmpty()
            ->and(collect($history['timeline'])->firstWhere('status', 'current')['key'])
            ->toBe(PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL.'__cycle_2');
});
