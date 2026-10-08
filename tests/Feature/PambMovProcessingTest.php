<?php

use App\Models\ConservationReportSubmission;
use App\Models\AuditLog;
use App\Models\DocumentArchive;
use App\Models\DocumentAttachmentHistory;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\SubmissionRoutingAttachment;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
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

final class PambMovArchiveGateway implements \App\Services\Archive\GoogleDriveArchiveGateway
{
    private array $objects = [];
    public function findByIdentityAndHash(array $identity, string $sha256): ?array { return null; }
    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $id = 'pamb-mov-archive-'.count($this->objects);
        $this->objects[$id] = ['sha256' => $sha256, 'size' => filesize($localPath)];
        return ['file_id' => $id, 'folder_id' => $folderId];
    }
    public function verify(string $fileId, string $sha256, int $size): bool { return $this->verifyAvailability($fileId, $sha256, $size) === 'verified'; }
    public function verifyAvailability(string $fileId, string $sha256, int $size): string { return ($this->objects[$fileId]['sha256'] ?? null) === $sha256 && ($this->objects[$fileId]['size'] ?? null) === $size ? 'verified' : 'content_mismatch'; }
    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array { return ['file_id' => $fileId, 'folder_id' => $folderId]; }
    public function retrieve(string $fileId) { $stream = fopen('php://memory', 'r+'); rewind($stream); return $stream; }
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

function pambReceiveAtCenroChief(ConservationReportSubmission $report, User $focal, User $chief): void
{
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($report->fresh(), 'conservation', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report->fresh(), 'conservation', 'receive_at_cenro_chief', $chief->id);
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

    $area = ProtectedArea::create(['name' => 'PAMB MOV Area', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $focal->id, 'updated_by' => $focal->id]);
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'), 'assignment_type' => 'supervising', 'assigned_by' => $focal->id]);

    $area = ProtectedArea::create(['name' => 'PAMB MOV Area', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $focal->id, 'updated_by' => $focal->id]);
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'), 'assignment_type' => 'supervising', 'assigned_by' => $focal->id]);

    $this->actingAs($focal)->post(route('conservation-reports.store', ['workflow' => 'regular_pamb']), [
        'target_office' => 'CENRO Mati',
        'protected_area_id' => $area->id,
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
        ->and($row['routing']['responsible_user_category'])->toBe('CENRO CDS Focal Person')
        ->and($row['routing']['next_expected_action'])->toBe('Forward to CENRO Chief')
        ->and($workspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($workspace['incoming']->firstWhere('source_id', $report->id)['incoming_action_category'])->toBe('forward')
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
        ->and($tracking->records()->firstWhere('source_id', $report->id)['routing']['responsible_user_category'])->toBe('PENRO Records Unit');
});

test('PENRO Records receipt has no upload and exposes a separate Office forwarding action', function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $records = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $report = pambReport($records, [
        'target_office' => 'CENRO Mati',
        'date_report_released_cenro' => '2026-08-04',
        'mov_file_path' => 'conservation-report-movs/receipt-current.pdf',
    ]);
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\nreceipt checkpoint");
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, new PambMovArchiveGateway());
    $tracking = app(SubmissionTrackingService::class);

    $this->actingAs($records);
    $before = $tracking->records()->firstWhere('source_id', $report->id);
    expect(collect($before['routing']['actions'])->pluck('action_label')->all())
        ->toBe(['Receive'])
        ->and(collect($before['routing']['actions'])->firstWhere('key', 'receive_at_penro_records')['can_replace_document'])->toBeFalse()
        ->and(collect($before['routing']['actions'])->pluck('key')->all())->not->toContain('release_to_regional');

    expect($tracking->canAttachRoutingCopy('conservation', $report, SubmissionTrackingService::PENRO_RECEIPT))->toBeTrue();
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
        'stage' => SubmissionTrackingService::PENRO_RECEIPT,
        'date' => '2026-08-05',
        'official_document' => UploadedFile::fake()->createWithContent('must-not-replace.pdf', '%PDF disallowed receive replacement'),
    ])->assertSessionHasErrors('official_document');
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
        'stage' => SubmissionTrackingService::PENRO_RECEIPT,
        'date' => '2026-08-05',
    ])->assertSessionHasNoErrors();
    expect(SubmissionRoutingAttachment::query()->where('source', 'conservation')->where('source_id', $report->id)->count())->toBe(0);

    $this->actingAs($records);
    $recordsWorkspace = $tracking->workspaceQueues();
    expect($recordsWorkspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($recordsWorkspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id);

    $recordsRow = $tracking->records()->firstWhere('source_id', $report->id);
    expect($recordsRow['routing']['current_stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($recordsRow['routing']['next_expected_action'])->toBe('Forward to Office of the PENRO');
    $this->actingAs($records)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])->assertSessionHasNoErrors();

    $this->actingAs($office);
    $officeRow = $tracking->workspaceQueues()['incoming']->firstWhere('source_id', $report->id);
    expect($officeRow)->not->toBeNull()
        ->and($officeRow['routing']['current_stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)
        ->and($officeRow['routing']['responsible_user_category'])->toBe('Office of the PENRO');

    $this->actingAs($records)->post(route('submission-tracking.transition', [
        'conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT,
    ]), [
        'stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT,
        'date' => '2026-08-06',
    ])->assertRedirect()->assertSessionHasErrors('stage');
    expect($report->fresh()->date_endorsed_regional)->toBeNull();
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
    expect($before['routing']['responsible_user_category'])->toBe('PENRO TSD Chief')
        ->and($before['routing']['next_expected_action'])->toBe('Receive')
        ->and($beforeQueue['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and(collect($tracking->dashboardActionQueue())->contains(fn (array $item): bool => (int) $item['source_id'] === $report->id && $item['required_action'] === 'Receive'))->toBeTrue();

    $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_TSD, '2026-08-07 09:00:00', $tsd->id);
    $afterReceive = $tracking->records()->firstWhere('source_id', $report->id);
    $afterReceiveQueue = $tracking->workspaceQueues();
    expect($afterReceive['routing']['responsible_user_category'])->toBe('PENRO TSD Chief')
        ->and($afterReceive['routing']['next_expected_action'])->toBe('Forward to CDS Focal')
        ->and($afterReceiveQueue['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($afterReceiveQueue['outgoing']->pluck('source_id')->all())->not->toContain($report->id)
        ->and(collect($tracking->dashboardActionQueue())->contains(fn (array $item): bool => (int) $item['source_id'] === $report->id && $item['required_action'] === 'Forward to CDS Focal'))->toBeTrue();

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
    // PAMB completion is established by the terminal canonical routing event;
    // legacy date fields alone are metadata and do not complete the route.
    PambRoutingEvent::query()->create([
        'conservation_report_submission_id' => $report->id,
        'workflow_key' => $report->workflow_key,
        'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL,
        'occurred_at' => '2026-08-06 09:00:00',
        'recorded_by' => $focal->id,
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
    pambReceiveAtCenroChief($report, $focal, $chief);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Please attach the signed attendance sheet.'])->assertSessionHasNoErrors();

    $corrected = $report->fresh();
    expect($corrected->mov_processing_status)->toBe(PambMovProcessingService::NEEDS_CORRECTION)
        ->and(app(PambMovProcessingService::class)->present($corrected)['percent'])->toBe(35)
        ->and($corrected->deadline_submission)->toBe($deadline)
        ->and($corrected->timeliness)->toBe($timeliness)
        ->and($corrected->movReviewEvents()->count())->toBe(2);

    app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->transition($report->fresh(), 'conservation', 'receive_correction', $focal->id);
    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    expect($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::SUBMITTED_FOR_REVIEW)->count())->toBe(1)
        ->and($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::RESUBMITTED_FOR_REVIEW)->count())->toBe(1);
    pambReceiveAtCenroChief($report, $focal, $chief);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE])->assertSessionHasNoErrors();
    expect(app(PambMovProcessingService::class)->present($report->fresh())['percent'])->toBe(70);
});

test('no-file Ready for Release accepts Inertia empty-file serialization and keeps custody Forward separate', function (): void {
    Storage::fake('local');
    \Illuminate\Support\Facades\Notification::fake();
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/no-file-review.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    pambReceiveAtCenroChief($report, $focal, $chief);

    $reviewEvents = $report->fresh()->movReviewEvents()->count();
    $routingEvents = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $verdictAudits = \App\Models\AuditLog::query()->where('action', 'PAMB MOV Marked Ready for Release')->count();
    $archiveRows = \App\Models\DocumentArchive::query()->count();
    $notifications = count(\Illuminate\Support\Facades\Notification::sentNotifications());
    $files = Storage::disk('local')->allFiles();

    // Inertia forceFormData serializes attachment: null as attachment="".
    // This protects the controller path even if an older client submits that empty field.
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
        'remarks' => '',
        'attachment' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $reviewed = $report->fresh();
    $row = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    expect($reviewed->movReviewEvents()->count())->toBe($reviewEvents + 1)
        ->and($reviewed->movReviewEvents()->where('event_key', PambMovProcessingService::READY_FOR_RELEASE)->count())->toBe(1)
        ->and($reviewed->mov_processing_status)->toBe(PambMovProcessingService::READY_FOR_RELEASE)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($routingEvents)
        ->and(\App\Models\AuditLog::query()->where('action', 'PAMB MOV Marked Ready for Release')->count())->toBe($verdictAudits + 1)
        ->and(\App\Models\DocumentArchive::query()->count())->toBe($archiveRows)
        ->and(count(\Illuminate\Support\Facades\Notification::sentNotifications()))->toBe($notifications)
        ->and(Storage::disk('local')->allFiles())->toBe($files)
        ->and(SubmissionRoutingAttachment::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(0)
        ->and(collect($row['routing']['actions'])->pluck('key')->all())->toBe(['forward_to_cenro_records']);
});

test('an invalid optional correction reference is rejected without recording review or custody writes', function (): void {
    Storage::fake('local');
    \Illuminate\Support\Facades\Notification::fake();
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/invalid-reference-review.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    pambReceiveAtCenroChief($report, $focal, $chief);

    $reviewEvents = $report->fresh()->movReviewEvents()->count();
    $routingEvents = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $auditRows = \App\Models\AuditLog::query()->count();
    $archiveRows = \App\Models\DocumentArchive::query()->count();
    $notifications = count(\Illuminate\Support\Facades\Notification::sentNotifications());
    $files = Storage::disk('local')->allFiles();

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::NEEDS_CORRECTION,
        'remarks' => 'Please check the signed page.',
        'attachment' => UploadedFile::fake()->create('correction-reference.txt', 10, 'text/plain'),
    ])->assertSessionHasErrors('attachment');

    expect($report->fresh()->movReviewEvents()->count())->toBe($reviewEvents)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($routingEvents)
        ->and(\App\Models\AuditLog::query()->count())->toBe($auditRows)
        ->and(\App\Models\DocumentArchive::query()->count())->toBe($archiveRows)
        ->and(count(\Illuminate\Support\Facades\Notification::sentNotifications()))->toBe($notifications)
        ->and(Storage::disk('local')->allFiles())->toBe($files)
        ->and(SubmissionRoutingAttachment::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(0)
        ->and($report->fresh()->mov_processing_status)->toBe(PambMovProcessingService::SUBMITTED_FOR_REVIEW);
});

test('Chief review decisions are idempotent within the current review state', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/idempotent-review.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    pambReceiveAtCenroChief($report, $focal, $chief);
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
    pambReceiveAtCenroChief($report, $focal, $chief);
    expect($service->present($report->fresh())['cenro_review']['verdict'])->toBe('Awaiting CENRO CDS Chief Review');

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Attach the signed resolution.']);
    $needsCorrection = $service->present($report->fresh())['cenro_review'];
    expect($needsCorrection['verdict'])->toBe('Needs Correction')
        ->and($needsCorrection['reviewed_by'])->toBe($chief->name)
        ->and($needsCorrection['reviewed_user_category'])->toBe('CENRO CDS Chief')
        ->and($needsCorrection['correction_reason'])->toBe('Attach the signed resolution.')
        ->and($needsCorrection['previous_correction_cycles'])->toBe(1);

    app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->transition($report->fresh(), 'conservation', 'receive_correction', $focal->id);
    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $awaitingSecondReview = $service->present($report->fresh())['cenro_review'];
    expect($awaitingSecondReview['verdict'])->toBe('Awaiting CENRO CDS Chief Review')
        ->and($awaitingSecondReview['previous_correction']['reason'])->toBe('Attach the signed resolution.');

    pambReceiveAtCenroChief($report, $focal, $chief);
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

test('For Review MOV status keeps canonical custody ownership with the focal', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/owner-review.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])->assertSessionHasNoErrors();
    $tracking = app(SubmissionTrackingService::class);
    $row = $tracking->records()->firstWhere('source_id', $report->id);
    expect($row['mov_processing']['workflow_status'])->toBe('Awaiting Review by CENRO CDS Chief')
        ->and($row['routing']['responsible_user_category'])->toBe('CENRO CDS Chief')
        ->and($row['routing']['next_expected_action'])->toBe('Receive')
        ->and($row['pamb_action_flags']['can_review'])->toBeFalse();

    $this->actingAs($chief);
    $transitRow = $tracking->records()->firstWhere('source_id', $report->id);
    expect($transitRow['pamb_action_flags']['can_review'])->toBeFalse()
        ->and(collect($transitRow['routing']['actions'])->pluck('key')->all())->toBe(['receive_at_cenro_chief']);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertForbidden();
    $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_chief']), ['stage' => 'receive_at_cenro_chief'])->assertSessionHasNoErrors();
    $chiefRow = $tracking->records()->firstWhere('source_id', $report->id);
    expect($chiefRow['pamb_action_flags']['can_review'])->toBeTrue();
});
test('needs correction requires remarks and returns the record to the focal queue', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/correction.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    pambReceiveAtCenroChief($report, $focal, $chief);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION])->assertSessionHasErrors('remarks');
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Please correct the signature page.'])->assertSessionHasNoErrors();
    $response = $this->actingAs($focal)->get(route('submission-tracking.index'))->assertOk();
    $props = $response->inertiaProps();
    $incoming = collect($props['workspaceQueues']['incoming']);
    expect($props)->not->toHaveKey('queues')
        ->and($incoming->pluck('source_id'))->toContain($report->id)
        ->and($incoming->firstWhere('source_id', $report->id)['mov_processing']['queue'])->toBe('needs_correction')
        ->and($incoming->firstWhere('source_id', $report->id)['mov_processing']['review_remarks'])->toBe('Please correct the signature page.');
});

test('Needs Correction records its MOV verdict and custody return atomically', function (): void {
    Storage::fake('local');
    \Illuminate\Support\Facades\Notification::fake();
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $path = 'conservation-report-movs/mov-review-custody-separation.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nSynthetic MOV");
    $report = pambReport($focal, ['mov_file_path' => $path, 'mov_file_name' => basename($path)]);
    app(PambMovProcessingService::class)->recordUpload($report, $focal);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), [
        'stage' => 'forward_to_cenro_chief',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_chief']), [
        'stage' => 'receive_at_cenro_chief',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $reviewResponse = $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::NEEDS_CORRECTION,
        'remarks' => 'Please correct the signature page.',
        'attachment' => UploadedFile::fake()->createWithContent('correction-reference.pdf', "%PDF-1.4\nSynthetic correction reference"),
    ]);
    $reviewResponse->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success',
        'MOV returned to CENRO Focal for correction. The MOV verdict and custody return were recorded together.');

    $routingEvents = DocumentRoutingEvent::query()
        ->where('source_type', 'conservation')
        ->where('source_id', $report->id)
        ->orderBy('id')
        ->get();
    $correctedProjection = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    expect($routingEvents->pluck('event_key')->all())->toBe(['forwarded', 'received', 'returned_for_correction'])
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($report->fresh(), 'conservation')['stage'])
        ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PREPARATION)
        ->and(data_get($correctedProjection, 'mov_processing.cenro_review.custody_return_recorded'))->toBeTrue()
        ->and($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::NEEDS_CORRECTION)->count())->toBe(1)
        ->and($report->fresh()->mov_file_path)->toBe($path);
    $returned = $routingEvents->last();
    $reference = SubmissionRoutingAttachment::query()->where('document_routing_event_id', $returned->id)->firstOrFail();
    expect($reference->purpose)->toBe('correction_reference')
        ->and($reference->stage_key)->toBe('return_to_cenro_focal')
        ->and($reference->uploaded_by)->toBe($chief->id);

    $chiefDetails = $this->actingAs($chief)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    $chiefRow = data_get($chiefDetails, 'trackingContext.selected_record');
    $chiefIncoming = collect(data_get($chiefDetails, 'workspaceQueues.incoming', []));
    expect(data_get($chiefRow, 'routing.current_stage'))->toBe('cenro_preparation')
        ->and(data_get($chiefRow, 'routing.actions'))->toBeEmpty()
        ->and($chiefIncoming->contains(fn (array $row): bool => ($row['source'] ?? null) === 'conservation' && (int) ($row['source_id'] ?? 0) === $report->id))->toBeFalse();

    $focalIncoming = collect($this->actingAs($focal)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps()['workspaceQueues']['incoming']);
    $focalRow = $focalIncoming->first(fn (array $row): bool => ($row['source'] ?? null) === 'conservation' && (int) ($row['source_id'] ?? 0) === $report->id);
    expect($focalRow)->not->toBeNull()
        ->and(collect($focalRow['routing']['actions'])->pluck('key')->all())->toBe(['receive_correction'])
        ->and($focalRow['pamb_action_flags']['can_submit'])->toBeFalse();

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, 'return_to_cenro_focal']), [
        'stage' => 'return_to_cenro_focal', 'remarks' => 'Wrong category.',
    ])->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(3)
        ->and($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::NEEDS_CORRECTION)->count())->toBe(1);

    $focalDetails = $this->actingAs($focal)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    $focalRow = data_get($focalDetails, 'trackingContext.selected_record');
    $focalIncoming = collect(data_get($focalDetails, 'workspaceQueues.incoming', []));
    expect(collect(data_get($focalRow, 'routing.actions', []))->pluck('key')->all())->toBe(['receive_correction'])
        ->and(data_get($focalRow, 'routing.actions.0.action_label'))->toBe('Receive Correction')
        ->and($focalIncoming->contains(fn (array $row): bool => ($row['source'] ?? null) === 'conservation'
            && (int) ($row['source_id'] ?? 0) === $report->id
            && ($row['incoming_action_category'] ?? null) === 'correction'))->toBeTrue();

    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_correction']), [
        'stage' => 'receive_correction',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $receivedFocalRow = collect($this->actingAs($focal)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps()['workspaceQueues']['incoming'])
        ->first(fn (array $row): bool => ($row['source'] ?? null) === 'conservation' && (int) ($row['source_id'] ?? 0) === $report->id);
    expect($receivedFocalRow['pamb_action_flags']['can_submit'])->toBeTrue()
        ->and(collect($receivedFocalRow['routing']['actions'])->pluck('key')->all())->toBe([]);

    $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'return_to_cenro_focal']), [
        'stage' => 'return_to_cenro_focal', 'remarks' => 'Stale duplicate.',
    ])->assertRedirect()->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(4)
        ->and($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::NEEDS_CORRECTION)->count())->toBe(1);
    \Illuminate\Support\Facades\Notification::assertSentTo($focal, \App\Notifications\EdatsInAppNotification::class, fn ($notification): bool => data_get($notification->toArray($focal), 'source_id') === $report->id
        && data_get($notification->toArray($focal), 'title') === 'Correction Required');
});

test('legacy Needs Correction without a return exposes one custody action and adds no second MOV verdict', function (): void {
    \Illuminate\Support\Facades\Notification::fake();
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $report = pambReport($focal, [
        'mov_file_path' => 'conservation-report-movs/legacy-needs-correction.pdf',
        'mov_processing_status' => PambMovProcessingService::SUBMITTED_FOR_REVIEW,
    ]);
    pambReceiveAtCenroChief($report, $focal, $chief);
    $report->update([
        'mov_processing_status' => PambMovProcessingService::NEEDS_CORRECTION,
        'mov_review_remarks' => 'Legacy correction remarks remain the recorded reason.',
        'mov_reviewed_at' => now(),
        'mov_reviewed_by' => $chief->id,
    ]);
    $report->movReviewEvents()->create([
        'event_key' => PambMovProcessingService::NEEDS_CORRECTION,
        'remarks' => 'Legacy correction remarks remain the recorded reason.',
        'recorded_by' => $chief->id,
    ]);

    $details = $this->actingAs($chief)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id,
    ]))->assertOk()->inertiaProps();
    $chiefRow = data_get($details, 'trackingContext.selected_record');
    expect(collect(data_get($chiefRow, 'routing.actions', []))->pluck('key')->all())->toBe(['return_to_cenro_focal'])
        ->and(data_get($chiefRow, 'pamb_action_flags.can_review'))->toBeFalse()
        ->and(data_get($chiefRow, 'pamb_action_flags.can_submit'))->toBeFalse()
        ->and(data_get($chiefRow, 'mov_processing.cenro_review.custody_return_recorded'))->toBeFalse();

    $this->actingAs($chief)->post(route('submission-tracking.transition', [
        'conservation', $report->id, 'return_to_cenro_focal',
    ]), ['stage' => 'return_to_cenro_focal'])->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::NEEDS_CORRECTION)->count())->toBe(1)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
            ->where('event_key', 'returned_for_correction')->count())->toBe(1);

    $focalDetails = $this->actingAs($focal)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    $focalRow = collect(data_get($focalDetails, 'workspaceQueues.incoming', []))
        ->first(fn (array $row): bool => ($row['source'] ?? null) === 'conservation' && (int) ($row['source_id'] ?? 0) === $report->id);
    expect(collect($focalRow['routing']['actions'])->pluck('key')->all())->toBe(['receive_correction'])
        ->and(data_get($focalRow, 'mov_processing.cenro_review.custody_return_recorded'))->toBeTrue()
        ->and($focalRow['pamb_action_flags']['can_submit'])->toBeFalse();

});

test('a prior-cycle custody return does not satisfy a newer legacy correction verdict', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $earlier = CarbonImmutable::parse('2026-10-07 10:00:00', 'Asia/Manila');
    $current = CarbonImmutable::parse('2026-10-08 10:00:00', 'Asia/Manila');
    $this->travelTo($earlier);
    $report = pambReport($focal, [
        'mov_file_path' => 'conservation-report-movs/prior-cycle-return.pdf',
        'mov_processing_status' => PambMovProcessingService::NEEDS_CORRECTION,
        'mov_reviewed_at' => $earlier,
        'mov_reviewed_by' => $chief->id,
        'mov_review_remarks' => 'Earlier correction verdict.',
    ]);
    $report->movReviewEvents()->create([
        'event_key' => PambMovProcessingService::NEEDS_CORRECTION,
        'remarks' => 'Earlier correction verdict.',
        'recorded_by' => $chief->id,
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation', 'source_id' => $report->id, 'workflow_key' => 'regular_pamb',
        'event_key' => 'returned_for_correction', 'from_stage' => 'cenro_chief', 'to_stage' => 'cenro_preparation',
        'from_office' => 'CENRO Mati', 'to_office' => 'CENRO Mati', 'occurred_at' => $earlier,
        'recorded_by' => $chief->id, 'remarks' => 'Earlier cycle returned.',
        'metadata' => ['state_source' => 'routing_events', 'action_key' => 'return_to_cenro_focal', 'correction' => true, 'correction_cycle' => true, 'pamb_cycle' => 1],
    ]);

    $this->travelTo($current);
    $report->movReviewEvents()->create([
        'event_key' => PambMovProcessingService::NEEDS_CORRECTION,
        'remarks' => 'Current legacy verdict still needs a return.',
        'recorded_by' => $chief->id,
    ]);
    $report->update([
        'mov_processing_status' => PambMovProcessingService::NEEDS_CORRECTION,
        'mov_reviewed_at' => $current,
        'mov_reviewed_by' => $chief->id,
        'mov_review_remarks' => 'Current legacy verdict still needs a return.',
    ]);

    $this->actingAs($focal);
    $row = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
    expect($row['mov_processing']['status_key'])->toBe(PambMovProcessingService::NEEDS_CORRECTION)
        ->and($row['mov_processing']['cenro_review']['custody_return_recorded'])->toBeFalse();
});

test('Needs Correction rolls back its verdict when the custody return fails', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $report = pambReport($focal, [
        'mov_file_path' => 'conservation-report-movs/failed-atomic-return.pdf',
        'mov_processing_status' => PambMovProcessingService::SUBMITTED_FOR_REVIEW,
    ]);
    pambReceiveAtCenroChief($report, $focal, $chief);

    expect(fn () => app(PambMovProcessingService::class)->review(
        $report->fresh(),
        $chief,
        PambMovProcessingService::NEEDS_CORRECTION,
        'Please attach the signed page.',
        fn () => throw new \RuntimeException('Synthetic return failure'),
    ))->toThrow(\RuntimeException::class, 'Synthetic return failure');

    expect($report->fresh()->mov_processing_status)->toBe(PambMovProcessingService::SUBMITTED_FOR_REVIEW)
        ->and($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::NEEDS_CORRECTION)->count())->toBe(0)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(2);
});

test('MOV correction replacement and resubmission return each PAMB meeting to its authorized Chief reviewer', function (string $workflow): void {
    Storage::fake('local');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $activity = app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class)->find($workflow)['default_activity'];
    $area = ProtectedArea::create([
        'name' => "{$workflow} MOV correction area", 'short_name' => strtoupper(substr($workflow, 0, 4)),
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'XI', 'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $focal->id,
    ]);
    $originalPath = "conservation-report-movs/{$workflow}-original.pdf";
    Storage::disk('local')->put($originalPath, "%PDF-1.4\nOriginal {$workflow} MOV");
    $report = pambReport($focal, [
        'workflow_key' => $workflow,
        'protected_area_id' => $area->id,
        'activity_name' => $activity,
        'mov_file_name' => basename($originalPath),
        'mov_file_path' => $originalPath,
    ]);
    app(PambMovProcessingService::class)->recordUpload($report, $focal);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    pambReceiveAtCenroChief($report, $focal, $chief);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::NEEDS_CORRECTION,
        'remarks' => "{$workflow}: attach the signed meeting record.",
    ])->assertRedirect()->assertSessionHasNoErrors();

    $needsCorrection = $this->actingAs($focal)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    $focalRow = collect($needsCorrection['workspaceQueues']['incoming'])->firstWhere('source_id', $report->id);
    expect($focalRow['mov_processing']['queue'])->toBe('needs_correction')
        ->and($focalRow['mov_processing']['review_remarks'])->toBe("{$workflow}: attach the signed meeting record.")
        ->and($focalRow['mov_processing']['cenro_review']['reviewed_user_category'])->toBe('CENRO CDS Chief')
        ->and($focalRow['routing']['responsible_user_category'])->toBe('CENRO CDS Focal Person')
        ->and(collect($focalRow['routing']['actions'])->pluck('key')->all())->toBe(['receive_correction'])
        ->and($focalRow['pamb_action_flags']['can_submit'])->toBeFalse()
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(3)
        ->and(PambRoutingEvent::query()->where('conservation_report_submission_id', $report->id)->count())->toBe(0);

    $this->actingAs($focal)->put(route('conservation-reports.update', [$workflow, $report->id]), [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => $activity,
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'mov' => UploadedFile::fake()->createWithContent("{$workflow}-premature.pdf", "%PDF-1.4\nPremature {$workflow} MOV"),
    ])->assertForbidden();
    expect($report->fresh()->mov_file_path)->toBe($originalPath);

    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_correction']), [
        'stage' => 'receive_correction',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($focal)->put(route('conservation-reports.update', [$workflow, $report->id]), [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => $activity,
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'mov' => UploadedFile::fake()->createWithContent("{$workflow}-corrected.pdf", "%PDF-1.4\nCorrected {$workflow} MOV"),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $corrected = $report->fresh();
    expect($corrected->mov_file_path)->not->toBe($originalPath)
        ->and($corrected->mov_file_name)->toBe("{$workflow}-corrected.pdf")
        ->and($corrected->mov_processing_status)->toBeNull()
        ->and($corrected->movReviewEvents()->where('event_key', PambMovProcessingService::NEEDS_CORRECTION)->count())->toBe(1)
        ->and($corrected->movReviewEvents()->where('event_key', PambMovProcessingService::MOV_UPLOADED)->count())->toBe(2);
    Storage::disk('local')->assertExists($corrected->mov_file_path);
    Storage::disk('local')->assertMissing($originalPath);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    pambReceiveAtCenroChief($report, $focal, $chief);
    expect($report->fresh()->movReviewEvents()->reorder('id', 'asc')->pluck('event_key')->all())->toBe([
        PambMovProcessingService::MOV_UPLOADED,
        PambMovProcessingService::SUBMITTED_FOR_REVIEW,
        PambMovProcessingService::NEEDS_CORRECTION,
        PambMovProcessingService::MOV_UPLOADED,
        PambMovProcessingService::RESUBMITTED_FOR_REVIEW,
    ]);

    $this->actingAs($focal)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertForbidden();
    $reviewQueue = $this->actingAs($chief)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    $chiefRow = data_get($reviewQueue, 'trackingContext.selected_record');
    expect(data_get($chiefRow, 'mov_processing.workflow_status'))->toBe('Awaiting Review by CENRO CDS Chief')
        ->and(data_get($chiefRow, 'pamb_action_flags.can_review'))->toBeTrue()
        ->and($report->fresh()->date_report_released_cenro)->toBeNull()
        ->and($report->fresh()->date_received_penro)->toBeNull()
        ->and($report->fresh()->date_endorsed_regional)->toBeNull()
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(6)
        ->and(PambRoutingEvent::query()->where('conservation_report_submission_id', $report->id)->count())->toBe(0);
})->with(['regular_pamb', 'special_pamb', 'twc_meetings']);

test('a future activity date cannot be saved after a PAMB correction is received', function (): void {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $area = ProtectedArea::create([
        'name' => 'Future date guard PAMB area', 'short_name' => 'FDG',
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'XI', 'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $focal->id,
    ]);
    $path = 'conservation-report-movs/future-date-correction.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nSynthetic correction-cycle MOV");
    $report = pambReport($focal, [
        'protected_area_id' => $area->id,
        'mov_file_path' => $path,
        'mov_file_name' => basename($path),
    ]);
    app(PambMovProcessingService::class)->recordUpload($report, $focal);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    pambReceiveAtCenroChief($report, $focal, $chief);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::NEEDS_CORRECTION,
        'remarks' => 'Correct the activity dates.',
    ])->assertSessionHasNoErrors();
    app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->transition($report->fresh(), 'conservation', 'receive_correction', $focal->id);

    $before = [
        'report' => $report->fresh()->getAttributes(),
        'routing_events' => DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->get()->toArray(),
        'mov_events' => $report->fresh()->movReviewEvents()->get()->toArray(),
        'audit_count' => AuditLog::query()->count(),
        'attachment_history' => DocumentAttachmentHistory::query()->count(),
        'archive_count' => DocumentArchive::query()->count(),
        'files' => Storage::disk('local')->allFiles(),
    ];

    $this->actingAs($focal)->put(route('conservation-reports.update', ['regular_pamb', $report->id]), [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-10-09',
        'date_accomplished' => '2026-10-09',
    ])->assertSessionHasErrors('date_conducted');

    expect($report->fresh()->getAttributes())->toBe($before['report'])
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->get()->toArray())->toBe($before['routing_events'])
        ->and($report->fresh()->movReviewEvents()->get()->toArray())->toBe($before['mov_events'])
        ->and(AuditLog::query()->count())->toBe($before['audit_count'])
        ->and(DocumentAttachmentHistory::query()->count())->toBe($before['attachment_history'])
        ->and(DocumentArchive::query()->count())->toBe($before['archive_count'])
        ->and(Storage::disk('local')->allFiles())->toBe($before['files']);
});

test('records release uses the canonical CENRO release date and reaches one hundred percent', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/release.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    foreach ([[$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief']] as [$actor, $action]) {
        $this->actingAs($actor)->post(route('submission-tracking.transition', ['conservation', $report->id, $action]), [
            'stage' => $action,
        ])->assertSessionHasNoErrors();
    }
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE]);
    foreach ([[$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records']] as [$actor, $action]) {
        $this->actingAs($actor)->post(route('submission-tracking.transition', ['conservation', $report->id, $action]), [
            'stage' => $action,
        ])->assertSessionHasNoErrors();
    }
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE]), ['stage' => SubmissionTrackingService::CENRO_RELEASE, 'date' => '2026-08-10'])->assertSessionHasNoErrors();

    $released = $report->fresh();
    expect($released->date_report_released_cenro->toDateString())->toBe(CarbonImmutable::now('Asia/Manila')->toDateString())
        ->and(app(PambMovProcessingService::class)->present($released)['percent'])->toBe(100)
        ->and(app(PambMovProcessingService::class)->present($released)['status_label'])->toBe('Released by CENRO to PENRO');
});

test('fixed-clock PAMB custody release and receipt preserve activity dates and action timestamps', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 09:15:00', 'Asia/Manila'));

    try {
        $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
        $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
        $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS');
        $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
        $report = pambReport($focal, [
            'date_conducted' => '2026-10-07',
            'date_accomplished' => '2026-10-07',
            'mov_file_name' => 'fixed-clock-pamb.pdf',
            'mov_file_path' => 'conservation-report-movs/fixed-clock-pamb.pdf',
            'mov_processing_status' => PambMovProcessingService::READY_FOR_RELEASE,
        ]);
        $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);

        foreach ([
            [$focal, 'forward_to_cenro_chief'],
            [$chief, 'receive_at_cenro_chief'],
            [$chief, 'forward_to_cenro_records'],
            [$records, 'receive_at_cenro_records'],
            [$records, 'forward_to_penro_records'],
        ] as [$actor, $action]) {
            $routing->transition($report->fresh(), 'conservation', $action, $actor->id);
        }
        $events = fn () => DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->get();
        $releaseEvent = $events()->first(fn ($event) => data_get($event->metadata, 'action_key') === 'forward_to_penro_records');

        $routing->transition($report->fresh(), 'conservation', 'receive_at_penro_records', $penroRecords->id);
        $received = $report->fresh();
        $receiptEvent = $events()->first(fn ($event) => data_get($event->metadata, 'action_key') === 'receive_at_penro_records');

        expect($releaseEvent)->not->toBeNull()
            ->and($receiptEvent)->not->toBeNull()
            ->and($received->date_conducted)->toBe('2026-10-07')
            ->and($received->date_accomplished->toDateString())->toBe('2026-10-07')
            ->and($received->date_report_released_cenro->toDateString())->toBe('2026-10-05')
            ->and($received->date_received_penro->toDateString())->toBe('2026-10-05')
            ->and($releaseEvent->occurred_at->toDateTimeString())->toBe('2026-10-05 09:15:00')
            ->and($receiptEvent->occurred_at->toDateTimeString())->toBe('2026-10-05 09:15:00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('CENRO Records PAMB routing stays at 35 percent across authorized viewer contexts while MOV remains complete', function (): void {
    $superAdmin = pambRoleUser('Super Admin', 'SUPER_ADMIN', 'PENRO Davao Oriental');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Baganga');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Baganga');
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Baganga');
    $report = pambReport($focal, [
        'target_office' => 'CENRO Baganga',
        'mov_processing_status' => PambMovProcessingService::READY_FOR_RELEASE,
        'date_report_released_cenro' => '2026-08-04',
    ]);
    $presenter = app(DocumentRoutingPresenter::class);
    $pamb = [
        'routing_complete' => false,
        'routing_summary' => [],
        'timeline' => [[
            'key' => SubmissionTrackingService::CENRO_RELEASE,
            'stage_key' => SubmissionTrackingService::CENRO_RELEASE,
            'status' => 'current',
            'held_at' => 'CENRO Records Unit',
            'occurred_at' => '2026-08-04T09:00:00+08:00',
        ]],
    ];

    $percentages = collect([$superAdmin, $focal, $chief, $records])->map(function (User $viewer) use ($presenter, $report, $pamb): int {
        $this->actingAs($viewer);
        return $presenter->presentPamb($report->fresh(), $pamb)['processing_percentage'];
    });

    expect($percentages->all())->toBe([35, 35, 35, 35])
        ->and(app(PambMovProcessingService::class)->present($report->fresh())['percent'])->toBe(100);

    $pamb['timeline'][0]['stage_key'] = PambRoutingTimelineService::RECORDS_RECEIVED;
    $pamb['timeline'][0]['key'] = PambRoutingTimelineService::RECORDS_RECEIVED;
    expect($presenter->presentPamb($report->fresh(), $pamb)['processing_percentage'])->toBeLessThan(100);

    $pamb['timeline'][0]['stage_key'] = PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF;
    $pamb['timeline'][0]['key'] = PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF;
    expect($presenter->presentPamb($report->fresh(), $pamb)['processing_percentage'])->toBe(100);
});

test('real Regular PAMB workspace and details progress separate receipt from forwarding', function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');

    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $cenroRecords = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/progress-contract.pdf']);
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\nregular PAMB progress contract");
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, new PambMovArchiveGateway());

    $workspaceRow = function (User $viewer) use ($report): array {
        $url = route('submission-tracking.index').'?source=conservation&source_id='.$report->id;
        $props = $this->actingAs($viewer)->get($url)->assertOk()->inertiaProps();
        $row = collect($props['workspaceQueues'])->flatten(1)->first(fn (array $candidate): bool =>
            ($candidate['source'] ?? null) === 'conservation'
            && (int) ($candidate['source_id'] ?? 0) === $report->id
        );
        expect($row)->not->toBeNull();
        expect(data_get($props, 'trackingContext.selected_record.routing.processing_percentage'))
            ->toBe($row['routing']['processing_percentage']);

        return $row;
    };

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_chief']), ['stage' => 'receive_at_cenro_chief'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertSessionHasNoErrors();
    $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_records']), ['stage' => 'forward_to_cenro_records'])->assertSessionHasNoErrors();
    $this->actingAs($cenroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_records']), ['stage' => 'receive_at_cenro_records'])->assertSessionHasNoErrors();

    $readyForRelease = $workspaceRow($cenroRecords);
    $releaseCheckpoint = collect($readyForRelease['routing']['timeline'])
        ->firstWhere('key', \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CENRO_RECORDS);
    expect($readyForRelease['mov_processing']['status_key'])->toBe(PambMovProcessingService::READY_FOR_RELEASE)
        ->and($releaseCheckpoint['status'])->toBe('current')
        ->and($releaseCheckpoint['occurred_at'])->not->toBeNull();

    $this->actingAs($cenroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE]), [
        'stage' => SubmissionTrackingService::CENRO_RELEASE,
        'date' => '2026-08-10',
    ])->assertSessionHasNoErrors();

    $released = $workspaceRow($penroRecords);
    expect($report->fresh()->date_received_penro)->toBeNull()
        ->and($released['routing']['current_stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and($released['routing']['profile_key'])->toBe('canonical_cenro_penro_regional')
        ->and($released['routing']['profile_label'])->toBe('CENRO-to-PENRO canonical routing')
        ->and($released['routing']['next_expected_action'])->toBe('Receive')
        ->and($released['routing']['processing_percentage'])->toBe(80)
        ->and($released['mov_processing']['percent'])->toBe(100)
        ->and(collect($released['routing']['actions'])->pluck('key'))->toContain('receive_at_penro_records')
        ->and(collect($released['routing']['timeline'])->firstWhere('key', \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS))
            ->toMatchArray([
                'status' => 'current',
                'recorded_by' => $cenroRecords->name,
            ])
        ->and(collect($released['routing']['timeline'])->firstWhere('key', \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)['status'])->toBe('pending')
        ->and(collect($released['routing']['timeline'])->firstWhere('key', \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)['status'])->toBe('pending');

    $penroReceiptResponse = $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
        'stage' => SubmissionTrackingService::PENRO_RECEIPT,
        'date' => '2026-08-11',
    ]);
    $penroReceiptState = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->state($report->fresh()->load('protectedArea'), 'conservation');
    $penroReceiptErrors = $penroReceiptResponse->getSession()->get('errors')?->getBag('default')->all() ?? [];
    expect($penroReceiptErrors)->toBe([], 'PENRO receipt; current='.$penroReceiptState['stage'].' actions='.implode(',', collect($penroReceiptState['actions'])->pluck('key')->all()));

    $received = $workspaceRow($penroRecords);
    expect($report->fresh()->date_received_penro->toDateString())->toBe(CarbonImmutable::now('Asia/Manila')->toDateString())
        ->and(collect($received['routing']['timeline'])->firstWhere('status', 'current')['key'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($received['routing']['processing_percentage'])->toBe(80)
        ->and($received['routing']['next_expected_action'])->toBe('Forward to Office of the PENRO')
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
            ->get()->map(fn (DocumentRoutingEvent $event) => data_get($event->metadata, 'action_key'))->all())
            ->toContain('forward_to_penro_records', 'receive_at_penro_records')
        ->and($report->fresh()->routingEvents()->exists())->toBeFalse();

    $this->actingAs($penroRecords)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])->assertSessionHasNoErrors();

    $forwarded = $workspaceRow($penroRecords);
    expect(collect($forwarded['routing']['timeline'])->firstWhere('status', 'current')['key'])
        ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)
        ->and($forwarded['routing']['next_expected_action'])->toBe('Receive')
        ->and($report->fresh()->routingEvents()->exists())->toBeFalse();
});

test('Special PAMB and TWC controllers preserve the authorized receipt then forwarding sequence', function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');

    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $cenroRecords = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, new PambMovArchiveGateway());

    foreach (['special_pamb', 'twc_meetings'] as $workflow) {
        $report = pambReport($focal, [
            'workflow_key' => $workflow,
            'activity_name' => $workflow === 'special_pamb' ? 'Special PAMB Meetings' : 'TWC Meetings',
            'mov_file_path' => "conservation-report-movs/{$workflow}-handoff.pdf",
        ]);
        Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\n{$workflow} isolated routing fixture");

        $workspaceRow = function (User $viewer) use ($report): array {
            $props = $this->actingAs($viewer)->get(route('submission-tracking.index').'?source=conservation&source_id='.$report->id)
                ->assertOk()->inertiaProps();
            $row = collect($props['workspaceQueues'])->flatten(1)->first(fn (array $candidate): bool =>
                ($candidate['source'] ?? null) === 'conservation' && (int) ($candidate['source_id'] ?? 0) === $report->id
            );
            expect($row)->not->toBeNull();
            expect(data_get($props, 'trackingContext.selected_record.routing.current_stage'))
                ->toBe($row['routing']['current_stage']);

            return $row;
        };

        $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
            ->assertSessionHasNoErrors();
        $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])->assertSessionHasNoErrors();
        $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_chief']), ['stage' => 'receive_at_cenro_chief'])->assertSessionHasNoErrors();
        $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
            'decision' => PambMovProcessingService::READY_FOR_RELEASE,
        ])->assertSessionHasNoErrors();
        $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_records']), ['stage' => 'forward_to_cenro_records'])->assertSessionHasNoErrors();
        $this->actingAs($cenroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_records']), ['stage' => 'receive_at_cenro_records'])->assertSessionHasNoErrors();

        $ready = $workspaceRow($cenroRecords);
        expect($ready['mov_processing']['status_key'])->toBe(PambMovProcessingService::READY_FOR_RELEASE)
            ->and(collect($ready['routing']['timeline'])->firstWhere('key', \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CENRO_RECORDS))
            ->toMatchArray(['status' => 'current'])
            ->and(collect($ready['routing']['actions'])->pluck('key')->all())->toContain('forward_to_penro_records');

        $this->actingAs($cenroRecords)->post(route('submission-tracking.transition', [
            'conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE,
        ]), ['stage' => SubmissionTrackingService::CENRO_RELEASE, 'date' => '2026-08-10'])->assertSessionHasNoErrors();
        $awaitingReceipt = $workspaceRow($penroRecords);
        expect(collect($awaitingReceipt['routing']['timeline'])->firstWhere('status', 'current')['key'])
            ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
            ->and($awaitingReceipt['routing']['next_expected_action'])->toBe('Receive')
            ->and($report->fresh()->date_received_penro)->toBeNull();
        $this->actingAs($penroRecords)->post(route('submission-tracking.transition', [
            'conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT,
        ]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-11'])->assertSessionHasNoErrors();

        $awaitingForward = $workspaceRow($penroRecords);
        $current = collect($awaitingForward['routing']['timeline'])->firstWhere('status', 'current');
        expect($report->fresh()->date_received_penro->toDateString())->toBe(CarbonImmutable::now('Asia/Manila')->toDateString())
            ->and($current['key'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
            ->and(collect($awaitingForward['routing']['actions'])->pluck('key')->all())->toContain('forward_to_office_penro')
            ->and($current['action_label'])->toBe('Receive')
            ->and($awaitingForward['routing']['next_expected_action'])->toBe('Forward to Office of the PENRO')
            ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
                ->get()->map(fn (DocumentRoutingEvent $event) => data_get($event->metadata, 'action_key'))->all())
                ->toContain('forward_to_cenro_chief', 'receive_at_cenro_chief', 'forward_to_cenro_records', 'receive_at_cenro_records', 'forward_to_penro_records', 'receive_at_penro_records')
            ->and($report->fresh()->routingEvents()->exists())->toBeFalse();

        $this->actingAs($office)->post(route('submission-tracking.internal-routing', [
            'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])->assertForbidden();

        $this->actingAs($penroRecords)->post(route('submission-tracking.internal-routing', [
            'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])->assertSessionHasNoErrors();

        $forwarded = $workspaceRow($penroRecords);
        expect(collect($forwarded['routing']['timeline'])->firstWhere('status', 'current')['key'])
            ->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)
            ->and($forwarded['routing']['next_expected_action'])->toBe('Receive')
            ->and($report->fresh()->routingEvents()->exists())->toBeFalse();
    }
});

test('direct-to-PENRO Regular, Special, and TWC profiles omit CENRO release and retain explicit PENRO forwarding', function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $creator = User::factory()->create();
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $area = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $creator->id,
    ]);
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, new PambMovArchiveGateway());

    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        $report = pambReport($penroRecords, [
            'workflow_key' => $workflow,
            'protected_area_id' => $area->id,
            'target_office' => 'CENRO Mati',
            'mov_file_path' => "conservation-report-movs/{$workflow}-direct.pdf",
        ]);
        Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\n{$workflow} direct route fixture");
        $this->actingAs($penroRecords);

        $before = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->presentation($report->fresh()->load('protectedArea'), 'conservation', null, $penroRecords);
        $detailRow = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
        expect($before['profile']['key'])->toBe('canonical_direct_penro')
            ->and($before['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
            ->and(collect($before['allowed_actions'])->pluck('key'))->not->toContain('forward_to_cenro_chief')
            ->and($detailRow['submission_status'])->toBe('Pending Receipt by PENRO')
            ->and($detailRow['cenro_release_applicable'])->toBeFalse()
            ->and($detailRow['date_report_released_cenro'])->toBeNull()
            ->and($detailRow['date_received_penro'])->toBeNull()
            ->and($detailRow['date_endorsed_regional'])->toBeNull();

        $this->actingAs($penroRecords)->post(route('submission-tracking.transition', [
            'conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT,
        ]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-11'])->assertSessionHasNoErrors();

        $afterReceipt = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->presentation($report->fresh()->load('protectedArea'), 'conservation', null, $penroRecords);
        $receiptRow = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
        $storedReceiptDate = $report->fresh()->getRawOriginal('date_received_penro');
        expect($report->fresh()->date_report_released_cenro)->toBeNull()
            ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
                ->get()->map(fn (DocumentRoutingEvent $event) => data_get($event->metadata, 'action_key'))->all())->toBe(['receive_at_penro_records'])
            ->and($report->fresh()->routingEvents()->exists())->toBeFalse()
            ->and($afterReceipt['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
            ->and(collect($afterReceipt['allowed_actions'])->pluck('key'))->toContain('forward_to_office_penro')
            ->and($receiptRow['submission_status'])->toBe('Pending Regional Endorsement')
            ->and($receiptRow['cenro_release_applicable'])->toBeFalse()
            ->and($receiptRow['date_report_released_cenro'])->toBeNull()
            ->and($receiptRow['date_received_penro'])->toBe($storedReceiptDate);

        $this->actingAs($penroRecords)->post(route('submission-tracking.internal-routing', [
            'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])->assertSessionHasNoErrors();
        expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
            ->get()->map(fn (DocumentRoutingEvent $event) => data_get($event->metadata, 'action_key'))->all())->toBe(['receive_at_penro_records', 'forward_to_office_penro'])
            ->and($report->fresh()->routingEvents()->exists())->toBeFalse();
    }
});

test('CENRO office scope and PAMO protected-area scope are enforced on tracking and attachments', function (): void {
    Storage::fake('local');
    $cenro = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $area = ProtectedArea::create(['name' => 'Mati Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $cenro->id, 'updated_by' => $cenro->id]);
    $otherArea = ProtectedArea::create(['name' => 'Baganga Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $cenro->id, 'updated_by' => $cenro->id]);
    $pamo = pambRoleUser('PAMO', 'PAMO', 'PENRO Davao Oriental', ['protected_area_id' => $area->id]);
    $visible = pambReport($cenro, ['protected_area_id' => $area->id, 'mov_file_path' => 'conservation-report-movs/visible.pdf']);
    $hidden = pambReport($cenro, ['target_office' => 'CENRO Baganga', 'protected_area_id' => $otherArea->id, 'mov_file_path' => 'conservation-report-movs/hidden.pdf']);

    $response = $this->actingAs($cenro)->get(route('submission-tracking.index'))->assertOk();
    $props = $response->inertiaProps();
    $workspaceIds = collect($props['workspaceQueues'])->flatten(1)->pluck('source_id');
    expect($props)->not->toHaveKey('queues')
        ->and($workspaceIds)->not->toContain($hidden->id);
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
        ->and($row['current_document']['preview_url'])->toBe($protectedUrl.'?preview=1')
        ->and($row['current_document']['download_url'])->toBe($protectedUrl.'?download=1')
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

test('finished MOV milestones stop counting down at CENRO release or direct PENRO receipt', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 12:00:00', 'Asia/Manila'));
    $user = User::factory()->create();
    $service = app(PambMovProcessingService::class);

    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        $released = pambReport($user, [
            'workflow_key' => $workflow,
            'date_report_released_cenro' => '2026-10-03',
            'date_received_penro' => null,
            'mov_processing_status' => PambMovProcessingService::READY_FOR_RELEASE,
            'mov_reviewed_at' => '2026-10-02 09:00:00',
        ]);
        $presented = $service->present($released);

        expect($presented['status_key'])->toBe(PambMovProcessingService::RELEASED_BY_CENRO)
            ->and($presented['cenro_review']['verdict'])->toBe('Ready for Release')
            ->and(collect($presented['milestones'])->last()['complete'])->toBeTrue()
            ->and($presented['turnaround']['label'])->toBe('Completed')
            ->and($presented['turnaround']['remaining'])->toBeNull();
    }

    $mhrws = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $received = pambReport($user, [
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $mhrws->id,
        'target_office' => 'PENRO Davao Oriental',
        'date_report_released_cenro' => null,
        'date_received_penro' => '2026-10-03',
        'mov_processing_status' => PambMovProcessingService::READY_FOR_RELEASE,
        'mov_reviewed_at' => '2026-10-02 09:00:00',
    ]);
    $directPresented = $service->present($received);
    expect($directPresented['status_key'])->toBe(PambMovProcessingService::RECEIVED_BY_PENRO)
        ->and($directPresented['cenro_review']['applicable'])->toBeFalse()
        ->and(collect($directPresented['milestones'])->last()['complete'])->toBeTrue()
        ->and($directPresented['turnaround']['label'])->toBe('Completed')
        ->and($directPresented['turnaround']['remaining'])->toBeNull();

    $active = pambReport($user, [
        'workflow_key' => 'twc_meetings',
        'reporting_period' => 'Quarter 4',
        'date_report_released_cenro' => null,
        'date_received_penro' => null,
        'mov_processing_status' => PambMovProcessingService::READY_FOR_RELEASE,
        'mov_reviewed_at' => '2026-10-02 09:00:00',
    ]);
    $activePresented = $service->present($active);
    expect($activePresented['status_key'])->toBe(PambMovProcessingService::READY_FOR_RELEASE)
        ->and($activePresented['turnaround']['label'])->not->toBe('Completed')
        ->and(collect($activePresented['milestones'])->last()['complete'])->toBeFalse();
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
    pambReceiveAtCenroChief($cenroReport, $focal, $cenroChief);
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
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');
    $cenroChief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF', 'CENRO Mati');
    $cenroRecords = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $penroFocal = pambRoleUser('PENRO CDS Focal Person', 'PENRO_CDS_FOCAL', 'PENRO Davao Oriental');
    $penroChief = pambRoleUser('PENRO CDS Chief', 'PENRO_CDS_CHIEF', 'PENRO Davao Oriental');
    $penroRecords = pambRoleUser('PENRO Records Unit', 'PENRO_RECORDS', 'PENRO Davao Oriental');
    $office = pambRoleUser('Office of the PENRO', 'OFFICE_OF_THE_PENRO', 'PENRO Davao Oriental');
    $tsd = pambRoleUser('PENRO TSD Chief', 'PENRO_TSD_CHIEF', 'PENRO Davao Oriental');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/full-flow.pdf']);
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.4\nfull PAMB flow");
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, new PambMovArchiveGateway());

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $this->actingAs($penroChief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertForbidden();

    // The shared Conservation profile requires these CENRO handoffs before
    // Records forwards the submission to PENRO.
    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), ['stage' => 'forward_to_cenro_chief'])->assertSessionHasNoErrors();
    $this->actingAs($cenroChief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_chief']), ['stage' => 'receive_at_cenro_chief'])->assertSessionHasNoErrors();
    $this->actingAs($cenroChief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertSessionHasNoErrors();
    $this->actingAs($cenroChief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_records']), ['stage' => 'forward_to_cenro_records'])->assertSessionHasNoErrors();
    $this->actingAs($cenroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_cenro_records']), ['stage' => 'receive_at_cenro_records'])->assertSessionHasNoErrors();

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

    $this->actingAs($penroRecords)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ]), ['stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO])->assertSessionHasNoErrors();

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
    ];

    foreach ($actorStages as [$stage, $expectedActor]) {
        foreach ([$penroFocal, $penroChief, $penroRecords, $office, $tsd] as $wrongActor) {
            if ($wrongActor->is($expectedActor)) continue;
            $this->actingAs($wrongActor)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $stage]), [
                'stage' => $stage,
            ])->assertForbidden();
        }
        $response = $this->actingAs($expectedActor)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $stage]), [
            'stage' => $stage,
        ]);
        $state = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
            ->state($report->fresh()->load('protectedArea'), 'conservation');
        $errors = $response->getSession()->get('errors')?->getBag('default')->all() ?? [];
        expect($errors)->toBe([], 'Accepted legacy stage '.$stage.' for '.$expectedActor->section.'; current='.$state['stage'].' actions='.implode(',', collect($state['actions'])->pluck('key')->all()));
    }
    expect($report->fresh()->routingEvents()->where('stage_key', PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS)->exists())->toBeFalse();

    $finalStage = PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL;
    foreach ([$penroFocal, $penroChief] as $wrongActor) {
        $this->actingAs($wrongActor)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $finalStage]), [
            'stage' => $finalStage,
        ])->assertForbidden();
    }
    $finalReceiptResponse = $this->actingAs($penroRecords)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $finalStage]), [
        'stage' => $finalStage,
    ]);
    $finalReceiptState = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->state($report->fresh()->load('protectedArea'), 'conservation');
    $finalReceiptErrors = $finalReceiptResponse->getSession()->get('errors')?->getBag('default')->all() ?? [];
    expect($finalReceiptErrors)->toBe([], 'PENRO final receipt; current='.$finalReceiptState['stage'].' actions='.implode(',', collect($finalReceiptState['actions'])->pluck('key')->all()));

    foreach ([$penroFocal, $penroChief] as $wrongActor) {
        $this->actingAs($wrongActor)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), [
            'stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT,
            'date' => '2026-08-12',
        ])->assertForbidden();
    }
    $regionalReleaseResponse = $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), [
        'stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT,
        'date' => '2026-08-12',
    ]);
    $regionalReleaseState = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->state($report->fresh()->load('protectedArea'), 'conservation');
    $regionalReleaseErrors = $regionalReleaseResponse->getSession()->get('errors')?->getBag('default')->all() ?? [];
    expect($regionalReleaseErrors)->toBe([], 'PENRO regional release; current='.$regionalReleaseState['stage'].' actions='.implode(',', collect($regionalReleaseState['actions'])->pluck('key')->all()));

    expect($report->fresh()->date_endorsed_regional->toDateString())->toBe(CarbonImmutable::now('Asia/Manila')->toDateString());
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
    pambReceiveAtCenroChief($reviewReport, $cenroFocal, $cenroChief);
    pambReceiveAtCenroChief($readyReport, $cenroFocal, $cenroChief);
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($readyReport->fresh(), 'conservation', 'forward_to_cenro_records', $cenroChief->id);
    $routing->transition($readyReport->fresh(), 'conservation', 'receive_at_cenro_records', $cenroRecords->id);
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

    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)
        ->where('metadata->action_key', 'return_from_office_for_correction')->count())->toBe(1)
        ->and($report->fresh()->routingEvents()->where('stage_key', PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION)->exists())->toBeFalse();

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

    $state = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)
        ->state($report->fresh()->load('protectedArea'), 'conservation');
    $legacyCount = $report->fresh()->routingEvents()->count();
    expect($legacyCount)->toBe(10)
        ->and($state['active_cycle'])->toBe(2)
        ->and($state['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN)
        ->and($state['events']->filter(fn (DocumentRoutingEvent $event): bool => ! str_starts_with((string) data_get($event->metadata, 'state_source'), 'legacy_pamb_event_projection'))->count())->toBe(6)
        ->and($state['events']->filter(fn (DocumentRoutingEvent $event): bool => str_contains((string) $event->from_office, 'CENRO') || str_contains((string) $event->to_office, 'CENRO'))->count())->toBe(0);
});
