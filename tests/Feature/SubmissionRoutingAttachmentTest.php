<?php

use App\Models\ConservationReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\ManagementPlan;
use App\Models\ManagementPlanProfile;
use App\Models\SubmissionRoutingAttachment;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Archive\GoogleDriveArchiveException;
use App\Models\DocumentArchive;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\Authorization\OrganizationalAccessService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Services\SubmissionTracking\RoutingAttachmentService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
});

final class FinalArchiveWorkflowGateway implements GoogleDriveArchiveGateway
{
    public array $objects = [];
    public array $archiveContexts = [];
    public int $lookups = 0;
    public int $uploads = 0;
    public bool $failVerify = false;
    public ?int $retrieveFailureStatus = null;
    public int $retrievals = 0;

    public function findByIdentityAndHash(array $identity, string $sha256): ?array
    {
        $this->lookups++;
        foreach ($this->objects as $id => $object) {
            if ($object['identity'] === $identity && $object['sha256'] === $sha256) return ['file_id' => $id, 'folder_id' => $object['folder_id']];
        }
        return null;
    }

    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $id = 'workflow-archive-'.(++$this->uploads);
        $this->objects[$id] = ['identity' => $identity, 'sha256' => $sha256, 'size' => filesize($localPath), 'folder_id' => $folderId, 'bytes' => file_get_contents($localPath), 'filename' => $filename];
        $this->archiveContexts[] = $archiveContext;
        return ['file_id' => $id, 'folder_id' => $folderId];
    }

    public function verify(string $fileId, string $sha256, int $size): bool
    {
        return $this->verifyAvailability($fileId, $sha256, $size) === 'verified';
    }

    public function verifyAvailability(string $fileId, string $sha256, int $size): string
    {
        if ($this->failVerify) return 'unknown';
        return ($this->objects[$fileId]['sha256'] ?? null) === $sha256 && ($this->objects[$fileId]['size'] ?? null) === $size ? 'verified' : 'content_mismatch';
    }

    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $this->objects[$fileId] = ['identity' => $identity, 'sha256' => $sha256, 'size' => filesize($localPath), 'folder_id' => $folderId, 'bytes' => file_get_contents($localPath)];
        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    public function retrieve(string $fileId)
    {
        $this->retrievals++;
        if ($this->retrieveFailureStatus !== null) throw new GoogleDriveArchiveException('Provider request failed.', $this->retrieveFailureStatus);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) ($this->objects[$fileId]['bytes'] ?? ''));
        rewind($stream);
        return $stream;
    }
}

function attachmentRoutingActor(string $category, string $office): User
{
    $user = User::factory()->create(['section' => $category, 'office_designated' => $office, 'unit_assignment' => OrganizationalAccessService::CONSERVATION]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
    return $user;
}

function attachmentRoutingReport(string $name): BmsReportSubmission
{
    \App\Models\ModuleDefinition::query()->firstOrCreate(['code' => 'bms'], [
        'name' => 'BMS', 'program_area' => 'protected_area_management_and_development',
        'implementation_type' => 'specialized', 'module_type' => 'regular_target',
        'deadline_mode' => 'standard_working_days', 'is_active' => true,
    ]);
    $creator = User::factory()->create();
    $area = ProtectedArea::create(['name' => $name, 'short_name' => strtoupper(substr($name, 0, 3)), 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $creator->id, 'updated_by' => $creator->id]);
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id')]);
    return BmsReportSubmission::create(['protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'Routing report', 'document_type' => 'Report', 'semester' => '1st Semester', 'date_accomplished' => '2026-08-03']);
}

function archiveOverrideFixture(bool $enabled, bool $receiveAtPenro = true): array
{
    config(['services.google_drive_archive.enabled' => $enabled, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $report = attachmentRoutingReport('Administrative archive checkpoint');
    $path = 'bms-report-movs/admin-checkpoint-'.$report->id.'.pdf';
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'Current official MOV.pdf']);
    Storage::disk('local')->put($path, "%PDF-1.4\nadmin checkpoint");
    $actors = [
        'focal' => attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        'chief' => attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        'cenro_records' => attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        'penro_records' => attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
    ];
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $steps = [
        [$actors['focal'], 'forward_to_cenro_chief'], [$actors['chief'], 'receive_at_cenro_chief'],
        [$actors['chief'], 'forward_to_cenro_records'], [$actors['cenro_records'], 'receive_at_cenro_records'],
        [$actors['cenro_records'], 'forward_to_penro_records'],
    ];
    if ($receiveAtPenro) $steps[] = [$actors['penro_records'], 'receive_at_penro_records'];
    foreach ($steps as [$actor, $action]) $routing->transition($report->fresh(), 'bms', $action, $actor->id);

    $admin = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'CDS']);
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.admin-override', 'web'));
    $passkey = \Laravel\Passkeys\Passkey::query()->forceCreate([
        'user_id' => $admin->id, 'name' => 'UAT admin passkey', 'credential_id' => 'synthetic-admin-credential', 'credential' => [],
    ]);
    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    return compact('report', 'actors', 'admin', 'passkey', 'gateway');
}

function attachmentPambReport(): ConservationReportSubmission
{
    $user = User::factory()->create();
    return ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'Attachment PAMB',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
}

function effectiveDocumentAuditUser(string $category, string $office = 'CENRO Mati'): User
{
    $user = User::factory()->create(['unit_assignment' => $category === 'Super Admin' ? null : 'conservation', 'section' => $category === 'Super Admin' ? 'CDS' : $category, 'office_designated' => $office]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    if ($category === 'Super Admin') $user->assignRole(Role::findOrCreate('Super Admin', 'web'));

    return $user;
}

function officialSlotManagementPlan(User $creator, string $name = 'Official Slot Plan'): ManagementPlan
{
    $area = ProtectedArea::create([
        'name' => $name, 'short_name' => strtoupper(substr($name, 0, 3)), 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $creator->id, 'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id'),
    ]);

    $plan = ManagementPlan::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'plan_type' => 'PAMP',
        'title' => $name, 'version' => '1.0', 'prepared_year' => 2026, 'status' => 'Active',
        'activity_name' => $name, 'document_type' => 'Final Report', 'semester' => '1st Semester',
        'date_accomplished' => '2026-09-01', 'created_by' => $creator->id, 'updated_by' => $creator->id,
        'attachments' => [
            0 => ['path' => 'management-plans/official-current.pdf', 'original_name' => 'official-current.pdf', 'mime_type' => 'application/pdf', 'size' => 19],
            1 => ['path' => 'management-plans/resolution.pdf', 'original_name' => 'resolution.pdf', 'mime_type' => 'application/pdf', 'size' => 17],
            2 => ['path' => 'management-plans/attendance.pdf', 'original_name' => 'attendance.pdf', 'mime_type' => 'application/pdf', 'size' => 18],
        ],
    ]);
    Storage::disk('local')->put('management-plans/official-current.pdf', '%PDF official current');
    Storage::disk('local')->put('management-plans/resolution.pdf', '%PDF resolution');
    Storage::disk('local')->put('management-plans/attendance.pdf', '%PDF attendance');

    return $plan;
}

function officialSlotManagementPlanActor(string $category, string $office = 'CENRO Mati'): User
{
    $actor = User::factory()->create([
        'section' => $category, 'office_designated' => $office, 'unit_assignment' => OrganizationalAccessService::CONSERVATION,
    ]);
    $actor->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('management-plans.update', 'web'));

    return $actor;
}

function prepareBmsReportForFinalRelease(BmsReportSubmission $report): array
{
    $actors = [
        'focal' => attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        'chief' => attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        'cenro_records' => attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        'penro_records' => attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
        'office' => attachmentRoutingActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental'),
        'tsd' => attachmentRoutingActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'),
        'penro_focal' => attachmentRoutingActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental'),
        'penro_chief' => attachmentRoutingActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental'),
    ];
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);

    foreach ([
        ['focal', 'forward_to_cenro_chief'], ['chief', 'receive_at_cenro_chief'],
        ['chief', 'forward_to_cenro_records'], ['cenro_records', 'receive_at_cenro_records'],
        ['cenro_records', 'forward_to_penro_records'], ['penro_records', 'receive_at_penro_records'], ['penro_records', 'forward_to_office_penro'],
        ['office', 'receive_at_office_penro'], ['office', 'assign_to_tsd_chief'],
        ['tsd', 'receive_at_tsd_chief'], ['tsd', 'forward_to_cds_focal'],
        ['penro_focal', 'receive_at_cds_focal'], ['penro_focal', 'forward_to_cds_chief'],
        ['penro_chief', 'receive_at_cds_chief'], ['penro_chief', 'recommend_to_office_penro'],
        ['office', 'receive_at_office_penro_final'], ['office', 'approve_for_regional_release'],
    ] as [$actorKey, $action]) {
        $routing->transition($report->fresh(), 'bms', $action, $actors[$actorKey]->id);
    }

    return $actors;
}

function prepareOfficialSlotManagementPlanForRelease(ManagementPlan $plan): array
{
    $focal = officialSlotManagementPlanActor(OrganizationalAccessService::CENRO_FOCAL);
    $chief = officialSlotManagementPlanActor(OrganizationalAccessService::CENRO_CHIEF);
    $records = officialSlotManagementPlanActor(OrganizationalAccessService::CENRO_RECORDS);
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($plan, 'management-plans', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($plan, 'management-plans', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($plan, 'management-plans', 'forward_to_cenro_records', $chief->id);
    $routing->transition($plan, 'management-plans', 'receive_at_cenro_records', $records->id);

    return [$focal, $chief, $records];
}

test('canonical PAMB attachment is linked to the exact canonical routing event', function (): void {
    Storage::fake('local');
    $report = attachmentPambReport(); $actor = User::query()->firstOrFail();
    $timeline = app(PambRoutingTimelineService::class); $attachments = app(RoutingAttachmentService::class);
    $event = $timeline->recordCanonical($report, SubmissionTrackingService::CENRO_RELEASE, '2026-08-04', $actor->id);
    $file = UploadedFile::fake()->create('signed-release.pdf', 24, 'application/pdf'); $path = $attachments->store($file);
    $attachment = $attachments->create('conservation', $report->id, $file, $path, $actor, SubmissionTrackingService::CENRO_RELEASE, SubmissionTrackingService::CENRO_RELEASE, null, null, $event);
    expect($attachment->pamb_routing_event_id)->toBe($event->id)->and($attachment->document_routing_event_id)->toBeNull();
    expect(Storage::disk('local')->exists($attachment->stored_path))->toBeTrue();
    $stage = collect($timeline->present($report->fresh())['timeline'])->firstWhere('routing_event_id', $event->id);
    expect($stage['attachment']['id'])->toBe($attachment->id);
});

test('cycle-qualified PAMB occurrences retain distinct exact attachment event identities', function (): void {
    Storage::fake('local');
    $report = attachmentPambReport(); $actor = User::query()->firstOrFail(); $timeline = app(PambRoutingTimelineService::class); $attachments = app(RoutingAttachmentService::class);
    $first = $report->routingEvents()->create(['workflow_key' => $report->workflow_key, 'stage_key' => PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, 'occurred_at' => '2026-08-10 09:00:00', 'recorded_by' => $actor->id]);
    $second = $report->routingEvents()->create(['workflow_key' => $report->workflow_key, 'stage_key' => PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL.'__cycle_2', 'occurred_at' => '2026-08-11 09:00:00', 'recorded_by' => $actor->id]);
    $fileA = UploadedFile::fake()->create('cycle-one.pdf', 10, 'application/pdf'); $fileB = UploadedFile::fake()->create('cycle-two.pdf', 10, 'application/pdf');
    $a = $attachments->create('conservation', $report->id, $fileA, $attachments->store($fileA), $actor, $first->stage_key, $first->stage_key, null, null, $first);
    $b = $attachments->create('conservation', $report->id, $fileB, $attachments->store($fileB), $actor, $second->stage_key, $second->stage_key, null, null, $second);
    expect($a->pamb_routing_event_id)->not->toBe($b->pamb_routing_event_id)->and(PambRoutingEvent::findOrFail($first->id)->stage_key)->toBe(PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL);
});

test('all generic routing actions remain valid without optional attachments', function (): void {
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = attachmentRoutingActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = attachmentRoutingActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = attachmentRoutingActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = attachmentRoutingActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $report = attachmentRoutingReport('Attachment Optional Route');
    $service = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);

    foreach ([
        [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records'],
        [$records, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'], [$penroRecords, 'forward_to_office_penro'],
        [$office, 'receive_at_office_penro'],
        [$office, 'assign_to_tsd_chief'], [$tsd, 'receive_at_tsd_chief'],
        [$tsd, 'forward_to_cds_focal'], [$penroFocal, 'receive_at_cds_focal'],
        [$penroFocal, 'forward_to_cds_chief'], [$penroChief, 'receive_at_cds_chief'],
        [$penroChief, 'recommend_to_office_penro'], [$office, 'receive_at_office_penro_final'],
        [$office, 'approve_for_regional_release'], [$penroRecords, 'receive_at_penro_records_final'],
        [$penroRecords, 'release_to_regional'],
    ] as [$actor, $action]) $service->transition($report, 'bms', $action, $actor->id);

    expect(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe(19)
        ->and(SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->count())->toBe(0);
});

test('generic attachment links to the exact event and history presentation', function (): void {
    Storage::fake('local');
    $actor = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = attachmentRoutingReport('Generic Attachment Presentation');
    $event = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->transition($report, 'bms', 'forward_to_cenro_chief', $actor->id);
    $file = UploadedFile::fake()->create('generic-signed.pdf', 24, 'application/pdf');
    $attachments = app(RoutingAttachmentService::class);
    $attachment = $attachments->create('bms', $report->id, $file, $attachments->store($file), $actor, 'forward_to_cenro_chief', 'forward_to_cenro_chief', null, $event);
    $presentation = app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)->present($report->fresh(), 'bms', null, \App\Models\DocumentRoutingEvent::query()->whereKey($event->id)->get());
    $historyEvent = collect($presentation['routing_history'])->firstWhere('id', $event->id);

    expect(SubmissionRoutingAttachment::query()->where('document_routing_event_id', $event->id)->count())->toBe(1)
        ->and($attachment->pamb_routing_event_id)->toBeNull()
        ->and(Storage::disk('local')->exists($attachment->stored_path))->toBeTrue()
        ->and($historyEvent['attachment']['id'])->toBe($attachment->id)
        ->and($historyEvent['attachment']['name'])->toBe('generic-signed.pdf')
        ->and($historyEvent['attachment']['download_url'])->toContain((string) $attachment->id);
});

test('PAMB Forward keeps supporting attachment separate and archives the resulting official document', function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $user = User::factory()->create(['section' => 'PENRO_RECORDS', 'unit_assignment' => 'conservation', 'office_designated' => 'PENRO Davao Oriental']);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    $user->assignRole(Role::findOrCreate('PENRO Records', 'web'));
    $currentPath = 'conservation-report-movs/pamb-current-'.$user->id.'.pdf';
    $report = ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'Attachment PAMB Internal', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03', 'created_by' => $user->id, 'updated_by' => $user->id, 'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05', 'mov_file_path' => $currentPath, 'mov_file_name' => 'pamb-current.pdf']);
    Storage::disk('local')->put($currentPath, '%PDF old PAMB current');
    $file = UploadedFile::fake()->create('internal-forward.pdf', 12, 'application/pdf');
    $updated = "%PDF-1.4\nupdated PAMB official";
    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $this->actingAs($user)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ]), [
        'stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        'remarks' => 'Routing slip attached.',
        'attachment' => $file,
        'official_document' => UploadedFile::fake()->createWithContent('pamb-updated.pdf', $updated),
    ])->assertSessionHasNoErrors();

    $event = $report->fresh()->routingEvents()->latest('id')->firstOrFail();
    $attachment = SubmissionRoutingAttachment::query()->where('pamb_routing_event_id', $event->id)->firstOrFail();

    expect($attachment->source)->toBe('conservation')
        ->and($attachment->source_id)->toBe($report->id)
        ->and($attachment->stage_key)->toBe(PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO)
        ->and($attachment->action_key)->toBe(PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO)
        ->and(Storage::disk('local')->exists($attachment->stored_path))->toBeTrue()
        ->and($report->fresh()->mov_file_name)->toBe('pamb-updated.pdf')
        ->and(Storage::disk('local')->exists($currentPath))->toBeFalse()
        ->and(DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->value('archived_sha256'))->toBe(hash('sha256', $updated))
        ->and($gateway->uploads)->toBe(1);
});

test('disabled PAMB checkpoint rejects Forward and rolls back an optional document replacement', function (): void {
    config(['services.google_drive_archive.enabled' => false, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $user = User::factory()->create(['section' => 'PENRO_RECORDS', 'unit_assignment' => 'conservation', 'office_designated' => 'PENRO Davao Oriental']);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    $user->assignRole(Role::findOrCreate('PENRO Records', 'web'));
    $path = 'conservation-report-movs/pamb-disabled-current.pdf';
    $report = ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'PAMB Disabled Checkpoint', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03', 'created_by' => $user->id, 'updated_by' => $user->id, 'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05', 'mov_file_path' => $path, 'mov_file_name' => 'pamb-current.pdf']);
    $current = "%PDF-1.4\nPAMB current";
    Storage::disk('local')->put($path, $current);
    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $this->actingAs($user)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO]), [
        'stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        'official_document' => UploadedFile::fake()->createWithContent('replacement-must-rollback.pdf', "%PDF-1.4\nreplacement"),
    ])->assertSessionHasErrors('archive');

    expect($report->fresh()->mov_file_path)->toBe($path)
        ->and($report->fresh()->mov_file_name)->toBe('pamb-current.pdf')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and($report->fresh()->routingEvents()->count())->toBe(0)
        ->and(DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(0)
        ->and($gateway->uploads)->toBe(0)
        ->and($gateway->lookups)->toBe(0);
});

// Attachment validation matrix is covered by the existing endpoint and policy suites.
test('failed transition cleans the newly stored routing file and preserves prior copies', function (): void {
    Storage::fake('local');
    $actor = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = attachmentRoutingReport('Failed Attachment Transition');
    $attachments = app(RoutingAttachmentService::class);
    $old = UploadedFile::fake()->create('prior-copy.pdf', 6, 'application/pdf');
    $oldAttachment = $attachments->create('bms', $report->id, $old, $attachments->store($old), $actor, 'prior-stage', 'prior-action');

    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'document_routing_events') && str_starts_with(ltrim($sql), 'insert')) {
            throw new RuntimeException('Forced transition failure for cleanup regression');
        }
    });
    $new = UploadedFile::fake()->create('failed-copy.pdf', 8, 'application/pdf');
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($actor)->post(route('submission-tracking.transition', [
        'bms', $report->id, 'forward_to_cenro_chief',
    ]), ['stage' => 'forward_to_cenro_chief', 'attachment' => $new]))
        ->toThrow(RuntimeException::class);

    expect(SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->pluck('id')->all())
        ->toBe([$oldAttachment->id])
        ->and(Storage::disk('local')->exists($oldAttachment->stored_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('submission-routing-attachments'))
        ->toHaveCount(1)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())
        ->toBe(0);
});

test('outer routing transaction rollback does not remove the prior current binary', function (): void {
    Storage::fake('local');
    $report = attachmentRoutingReport('Outer Transaction Attachment Rollback');
    $actor = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $attachments = app(RoutingAttachmentService::class);

    $priorFile = UploadedFile::fake()->create('prior-current.pdf', 6, 'application/pdf');
    $prior = $attachments->create('bms', $report->id, $priorFile, $attachments->store($priorFile), $actor, 'prior-stage', 'prior-action');
    $replacementFile = UploadedFile::fake()->create('replacement-current.pdf', 8, 'application/pdf');
    $replacementPath = $attachments->store($replacementFile);

    expect(fn () => DB::transaction(function () use ($attachments, $actor, $report, $replacementFile, $replacementPath): never {
        $attachments->create('bms', $report->id, $replacementFile, $replacementPath, $actor, 'replacement-stage', 'replacement-action');
        throw new RuntimeException('Forced outer routing transaction rollback');
    }))->toThrow(RuntimeException::class);

    expect(SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->pluck('id')->all())
        ->toBe([$prior->id])
        ->and(Storage::disk('local')->exists($prior->stored_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($replacementPath))->toBeTrue();

    $attachments->discard($replacementPath);
});

test('original MOV is the current document when no routed copy exists', function (): void {
    Storage::fake('local');
    $actor = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = attachmentRoutingReport('Original MOV Fallback');
    $actor->update(['protected_area_id' => $report->protected_area_id]);
    $report->update(['mov_file_path' => 'bms-reports/original-mov.pdf', 'mov_file_name' => 'original-mov.pdf']);
    Storage::disk('local')->put('bms-reports/original-mov.pdf', "%PDF-1.4\ncurrent official document");
    $actor->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));

    $this->actingAs($actor);
    $row = app(SubmissionTrackingService::class)->records([], null)
        ->first(fn (array $item): bool => $item['source'] === 'bms' && (int) $item['source_id'] === $report->id);

    expect($row)->not->toBeNull()
        ->and(SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and($row['current_document']['source'])->toBe('Original MOV / report')
        ->and($row['current_document']['download_url'])->not->toBeEmpty()
        ->and($row['current_document']['preview_url'])->toBe($row['current_document']['url'].'?preview=1')
        ->and($row['current_document']['download_url'])->toEndWith('?download=1')
        ->and($row['current_document']['mime_type'])->toBe('application/pdf')
        ->and($row['current_document']['name'])->not->toBeEmpty();

    $response = $this->get($row['current_document']['preview_url']);
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->has('X-Inertia'))->toBeFalse();
});

test('Full Details does not fall back to the source MOV URL when the protected current file is unavailable', function (): void {
    Storage::fake('local');
    $actor = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = attachmentRoutingReport('Missing current document');
    $actor->update(['protected_area_id' => $report->protected_area_id]);
    $report->update(['mov_file_path' => 'bms-reports/missing-current.pdf', 'mov_file_name' => 'missing-current.pdf']);
    $this->actingAs($actor);

    $row = app(SubmissionTrackingService::class)->records([], null)
        ->first(fn (array $item): bool => $item['source'] === 'bms' && (int) $item['source_id'] === $report->id);

    expect($row)->not->toBeNull()
        ->and($row['mov_url'])->not->toBeEmpty()
        ->and($row['current_document'])->toBeNull();
});

test('Submission Tracking resolves current official-document metadata through each active source registry entry', function (): void {
    $attachments = app(ProtectedAttachmentService::class);
    Storage::fake('local');
    $expected = [
        'conservation' => 'conservation-report',
        'engp' => 'engp-report',
        'bms' => 'bms-report',
        'bams' => 'bams-report',
        'imea' => 'imea-report',
        'imea-maintenance' => 'imea-maintenance',
        'aws' => 'aws',
        'ipaf-management' => 'ipaf-management',
        'revenue' => 'ipaf-revenue',
        'management-plans' => 'management-plan',
    ];

    $index = 0;
    foreach ($expected as $routingSource => $attachmentSource) {
        $official = $attachments->officialDefinitionForRoutingSource($routingSource);
        expect($official['source'] ?? null)->toBe($attachmentSource);

        $definition = $official['definition'];
        $modelClass = $definition['model'];
        $record = new $modelClass;
        $recordId = 700 + $index++;
        $record->setAttribute($record->getKeyName(), $recordId);
        $key = (string) $definition['official_key'];
        $path = 'preview-regression/'.$routingSource.'.pdf';
        if ($definition['kind'] === 'scalar') {
            $record->setAttribute($definition['path'], $path);
            if (isset($definition['name'])) $record->setAttribute($definition['name'], $routingSource.'.pdf');
        } else {
            $record->setAttribute($definition['field'], [(int) $key => ['path' => $path, 'name' => $routingSource.'.pdf']]);
        }
        Storage::disk('local')->put($path, "%PDF-1.4\nsynthetic preview fixture");

        $descriptor = $attachments->previewDescriptor($attachmentSource, $record, $key);
        $protectedUrl = route('attachments.show', ['source' => $attachmentSource, 'record' => $recordId, 'attachment' => $key]);
        expect($descriptor['url'] ?? null)->toBe($protectedUrl)
            ->and($descriptor['preview_url'] ?? null)->toBe($protectedUrl.'?preview=1');
    }
});

test('routing copies remain event-linked and do not replace the official current document', function (): void {
    Storage::fake('local');
    $report = attachmentPambReport();
    $actor = User::query()->firstOrFail();
    $report->update(['mov_file_path' => 'conservation-report-movs/original.pdf', 'mov_file_name' => 'original.pdf']);
    Storage::disk('local')->put($report->mov_file_path, '%PDF original');
    $original = app(ProtectedAttachmentService::class)->descriptor('conservation-report', $report, 'mov');
    $attachments = app(RoutingAttachmentService::class);
    $firstEvent = $report->routingEvents()->create(['workflow_key' => $report->workflow_key, 'stage_key' => 'routing-copy-one', 'occurred_at' => '2026-09-01 09:00:00', 'recorded_by' => $actor->id]);
    $secondEvent = $report->routingEvents()->create(['workflow_key' => $report->workflow_key, 'stage_key' => 'routing-copy-two', 'occurred_at' => '2026-09-02 09:00:00', 'recorded_by' => $actor->id]);
    $firstFile = UploadedFile::fake()->create('signed-one.pdf', 12, 'application/pdf');
    $secondFile = UploadedFile::fake()->create('signed-final.pdf', 14, 'application/pdf');
    $first = $attachments->create('conservation', $report->id, $firstFile, $attachments->store($firstFile), $actor, $firstEvent->stage_key, $firstEvent->stage_key, null, null, $firstEvent);
    $latest = $attachments->create('conservation', $report->id, $secondFile, $attachments->store($secondFile), $actor, $secondEvent->stage_key, $secondEvent->stage_key, null, null, $secondEvent);

    $current = $attachments->currentDescriptor('conservation', $report->id, $original);

    expect($current['name'])->toBe('original.pdf')
        ->and($current['is_routing_copy'])->toBeFalse()
        ->and($current['version_source'])->toBe('original')
        ->and(SubmissionRoutingAttachment::query()->where('source', 'conservation')->where('source_id', $report->id)->count())->toBe(2)
        ->and(SubmissionRoutingAttachment::findOrFail($first->id)->pamb_routing_event_id)->toBe($firstEvent->id)
        ->and(SubmissionRoutingAttachment::findOrFail($latest->id)->pamb_routing_event_id)->toBe($secondEvent->id)
        ->and(Storage::disk('local')->exists($first->stored_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($latest->stored_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($report->mov_file_path))->toBeTrue()
        ->and($report->fresh()->mov_file_path)->toBe('conservation-report-movs/original.pdf');
});

test('routing document replacement retains one current binary per logical slot and cleans failed uploads', function (): void {
    Storage::fake('local');
    $report = attachmentRoutingReport('Routing Replacement Safety');
    $actor = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $attachments = app(RoutingAttachmentService::class);

    $firstFile = UploadedFile::fake()->create('version-one.pdf', 8, 'application/pdf');
    $first = $attachments->create('bms', $report->id, $firstFile, $attachments->store($firstFile), $actor, 'stage-one', 'stage-one');
    expect(Storage::disk('local')->allFiles('submission-routing-attachments'))->toHaveCount(1);

    $secondFile = UploadedFile::fake()->create('version-two.pdf', 9, 'application/pdf');
    $second = $attachments->create('bms', $report->id, $secondFile, $attachments->store($secondFile), $actor, 'stage-two', 'stage-two');
    expect(Storage::disk('local')->exists($first->stored_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($second->stored_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('submission-routing-attachments'))->toHaveCount(1);

    $thirdPath = $attachments->store(UploadedFile::fake()->create('version-three.pdf', 10, 'application/pdf'));
    $third = $attachments->create('bms', $report->id, UploadedFile::fake()->create('version-three.pdf', 10, 'application/pdf'), $thirdPath, $actor, 'stage-three', 'stage-three');
    expect(Storage::disk('local')->exists($second->stored_path))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('submission-routing-attachments'))->toHaveCount(1);

    $current = SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->latest('id')->firstOrFail();
    expect($current->stage_key)->toBe('stage-three');
});

test('routing replacement preserves distinct correction attachment slots', function (): void {
    Storage::fake('local');
    $report = attachmentRoutingReport('Distinct Attachment Slots');
    $actor = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $attachments = app(RoutingAttachmentService::class);

    $routingOne = UploadedFile::fake()->create('routing-one.pdf', 8, 'application/pdf');
    $first = $attachments->create('bms', $report->id, $routingOne, $attachments->store($routingOne), $actor, 'stage-one', 'stage-one');
    $correction = UploadedFile::fake()->create('correction.pdf', 8, 'application/pdf');
    $correctionAttachment = $attachments->create('bms', $report->id, $correction, $attachments->store($correction), $actor, 'correction', 'correction', null, null, null, 'correction_reference');
    $routingTwo = UploadedFile::fake()->create('routing-two.pdf', 8, 'application/pdf');
    $second = $attachments->create('bms', $report->id, $routingTwo, $attachments->store($routingTwo), $actor, 'stage-two', 'stage-two');

    expect(Storage::disk('local')->exists($first->stored_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($second->stored_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($correctionAttachment->stored_path))->toBeTrue()
        ->and(SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->count())->toBe(3)
        ->and(Storage::disk('local')->allFiles('submission-routing-attachments'))->toHaveCount(2);
});

test('explicit official document release uses shared safe replacement within the existing transition', function (): void {
    Storage::fake('local');
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $report = attachmentRoutingReport('Official Document Release');
    $report->update(['mov_file_path' => 'bms-report-movs/current.pdf', 'mov_file_name' => 'current.pdf']);
    Storage::disk('local')->put($report->mov_file_path, '%PDF old official');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report, 'bms', 'forward_to_cenro_records', $chief->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_records', $records->id);
    $priorEvents = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count();
    $replacement = UploadedFile::fake()->createWithContent('released.pdf', '%PDF released official');

    $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'official_document' => $replacement,
    ])->assertSessionHasNoErrors();

    $fresh = $report->fresh();
    expect($fresh->mov_file_path)->not->toBe('bms-report-movs/current.pdf')
        ->and($fresh->mov_file_name)->toBe('released.pdf')
        ->and(Storage::disk('local')->exists('bms-report-movs/current.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists($fresh->mov_file_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('current-documents/bms/'.$report->id.'/mov'))->toHaveCount(1)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe($priorEvents + 1)
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'bms')->where('source_id', $report->id)->where('logical_slot', 'mov')->where('action', 'RELEASE')->count())->toBe(1)
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($fresh, 'bms')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
});

test('normal Receive rejects official document replacement and preserves current document', function (): void {
    Storage::fake('local');
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = attachmentRoutingReport('Official Document Receive');
    $report->update(['mov_file_path' => 'bms-report-movs/release-current.pdf', 'mov_file_name' => 'release-current.pdf']);
    Storage::disk('local')->put($report->mov_file_path, '%PDF release');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report, 'bms', 'forward_to_cenro_records', $chief->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_records', $records->id);
    $routing->transition($report, 'bms', 'forward_to_penro_records', $records->id);
    $priorEvents = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count();

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['bms', $report->id, 'receive_at_penro_records']), [
        'stage' => 'receive_at_penro_records', 'official_document' => UploadedFile::fake()->createWithContent('received.pdf', '%PDF received'),
    ])->assertSessionHasErrors('official_document');

    $fresh = $report->fresh();
    expect($fresh->mov_file_name)->toBe('release-current.pdf')
        ->and($fresh->mov_file_path)->toBe('bms-report-movs/release-current.pdf')
        ->and(Storage::disk('local')->exists($fresh->mov_file_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe($priorEvents)
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($fresh, 'bms')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'bms')->where('source_id', $report->id)->where('action', 'RECEIVE')->count())->toBe(0);
});

test('forward without an official replacement preserves the single current document and creates no version', function (): void {
    Storage::fake('local');
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $report = attachmentRoutingReport('Forward Without Replacement');
    $path = 'bms-report-movs/forward-no-replacement.pdf';
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'current.pdf']);
    Storage::disk('local')->put($path, '%PDF current unchanged');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    foreach ([[$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'], [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records']] as [$actor, $action]) {
        $routing->transition($report->fresh(), 'bms', $action, $actor->id);
    }
    $historyBefore = \App\Models\DocumentAttachmentHistory::query()->where('source_type', 'bms')->where('source_id', $report->id)->count();
    $forwardAction = collect($routing->presentation($report->fresh(), 'bms', null, $records)['allowed_actions'])->firstWhere('key', 'forward_to_penro_records');
    expect($forwardAction['can_replace_document'] ?? null)->toBeTrue();

    $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_penro_records']), ['stage' => 'forward_to_penro_records'])->assertSessionHasNoErrors();

    expect($report->fresh()->mov_file_path)->toBe($path)
        ->and($report->fresh()->mov_file_name)->toBe('current.pdf')
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe($historyBefore)
        ->and(Storage::disk('local')->allFiles('bms-report-movs'))->toBe([$path]);
});

test('corrected official document replacement preserves the existing correction cycle and handoff', function (): void {
    Storage::fake('local');
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $report = attachmentRoutingReport('Official Document Correction');
    $report->update(['mov_file_path' => 'bms-report-movs/before-correction.pdf', 'mov_file_name' => 'before-correction.pdf']);
    Storage::disk('local')->put($report->mov_file_path, '%PDF before correction');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report, 'bms', 'forward_to_cenro_records', $chief->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_records', $records->id);
    $routing->transition($report, 'bms', 'forward_to_penro_records', $records->id);
    $routing->transition($report, 'bms', 'return_for_correction_penro_records', $penroRecords->id, null, 'missing_received_copy');
    $routing->transition($report, 'bms', 'receive_correction', $records->id);

    $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records',
        'official_document' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF corrected document'),
    ])->assertSessionHasNoErrors();

    $fresh = $report->fresh();
    $last = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->latest('id')->firstOrFail();
    expect($fresh->mov_file_name)->toBe('corrected.pdf')
        ->and(Storage::disk('local')->exists('bms-report-movs/before-correction.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('current-documents/bms/'.$report->id.'/mov'))->toHaveCount(1)
        ->and($last->event_key)->toBe('forwarded')
        ->and(data_get($last->metadata, 'correction_cycle'))->toBeTrue()
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($fresh, 'bms')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'bms')->where('source_id', $report->id)->where('action', 'CORRECTION')->count())->toBe(1);
});

test('official document validation failure leaves Release routing and current file unchanged', function (): void {
    Storage::fake('local');
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $report = attachmentRoutingReport('Failed Official Document Release');
    $report->update(['mov_file_path' => 'bms-report-movs/current-failure.pdf', 'mov_file_name' => 'current-failure.pdf']);
    Storage::disk('local')->put($report->mov_file_path, '%PDF current');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report, 'bms', 'forward_to_cenro_records', $chief->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_records', $records->id);
    $priorEvents = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count();

    $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'official_document' => UploadedFile::fake()->create('invalid.txt', 3, 'text/plain'),
    ])->assertSessionHasErrors('official_document');

    expect($report->fresh()->mov_file_path)->toBe('bms-report-movs/current-failure.pdf')
        ->and(Storage::disk('local')->exists('bms-report-movs/current-failure.pdf'))->toBeTrue()
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe($priorEvents)
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([]);
});

test('official document persistence failure rolls back the validated routing transition', function (): void {
    Storage::fake('local');
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $report = attachmentRoutingReport('Atomic Official Document Release');
    $report->update(['mov_file_path' => 'bms-report-movs/atomic-current.pdf', 'mov_file_name' => 'atomic-current.pdf']);
    Storage::disk('local')->put($report->mov_file_path, '%PDF current official');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report, 'bms', 'forward_to_cenro_records', $chief->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_records', $records->id);
    $priorEvents = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count();
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
        $sql = strtolower($query->sql);
        if (str_starts_with(ltrim($sql), 'update') && str_contains($sql, 'bms_report_submissions') && str_contains($sql, 'mov_file_path')) {
            throw new RuntimeException('Injected official document persistence failure');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'official_document' => UploadedFile::fake()->createWithContent('failed-release.pdf', '%PDF new release'),
    ]))->toThrow(RuntimeException::class);

    expect($report->fresh()->mov_file_path)->toBe('bms-report-movs/atomic-current.pdf')
        ->and(Storage::disk('local')->exists('bms-report-movs/atomic-current.pdf'))->toBeTrue()
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe($priorEvents)
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([]);
});

test('event-linked support attachment does not replace official report document', function (): void {
    Storage::fake('local');
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $report = attachmentRoutingReport('Support Copy Separate');
    $report->update(['mov_file_path' => 'bms-report-movs/official.pdf', 'mov_file_name' => 'official.pdf']);
    Storage::disk('local')->put($report->mov_file_path, '%PDF official');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report, 'bms', 'forward_to_cenro_records', $chief->id);
    $routing->transition($report, 'bms', 'receive_at_cenro_records', $records->id);
    $support = UploadedFile::fake()->createWithContent('routing-slip.pdf', '%PDF routing slip');

    $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'attachment' => $support,
    ])->assertSessionHasNoErrors();

    $event = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->latest('id')->firstOrFail();
    expect($report->fresh()->mov_file_path)->toBe('bms-report-movs/official.pdf')
        ->and(Storage::disk('local')->exists($report->mov_file_path))->toBeTrue()
        ->and(SubmissionRoutingAttachment::query()->where('document_routing_event_id', $event->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([]);
});

test('official document resolver retains scalar identity and resolves the canonical structured Management Plan MOV slot', function (): void {
    Storage::fake('local');
    $scalar = attachmentRoutingReport('Scalar Slot Resolution');
    $scalarResolution = app(\App\Services\Attachments\ReportDocumentAdapterResolver::class)->resolveOfficialDocumentSlot('bms', $scalar);
    $planActor = User::factory()->create();
    $plan = officialSlotManagementPlan($planActor, 'Structured Slot Resolution');
    $structuredResolution = app(\App\Services\Attachments\ReportDocumentAdapterResolver::class)->resolveOfficialDocumentSlot('management-plans', $plan);
    $unresolved = app(\App\Services\Attachments\ReportDocumentAdapterResolver::class)->resolveRegisteredOfficialDocumentSlot('management-plan-profile', new ManagementPlanProfile());

    expect($scalarResolution['status'])->toBe(\App\Services\Attachments\ReportDocumentAdapterResolver::SUPPORTED)
        ->and($scalarResolution['slot'])->toBe('mov')
        ->and($scalarResolution['adapter'])->toBeInstanceOf(\App\Services\Attachments\ScalarDocumentReferenceAdapter::class)
        ->and($structuredResolution['status'])->toBe(\App\Services\Attachments\ReportDocumentAdapterResolver::SUPPORTED)
        ->and($structuredResolution['slot'])->toBe('0')
        ->and($structuredResolution['adapter'])->toBeInstanceOf(\App\Services\Attachments\StructuredDocumentReferenceAdapter::class)
        ->and($unresolved['status'])->toBe(\App\Services\Attachments\ReportDocumentAdapterResolver::UNRESOLVED)
        ->and($unresolved['slot'])->toBeNull();
});

test('structured official document Release replaces only the canonical MOV slot', function (): void {
    Storage::fake('local');
    $creator = User::factory()->create();
    $plan = officialSlotManagementPlan($creator, 'Structured Release');
    [, , $records] = prepareOfficialSlotManagementPlanForRelease($plan);
    $originalSiblings = array_slice($plan->attachments, 1, null, true);
    $replacement = UploadedFile::fake()->createWithContent('released-plan.pdf', '%PDF released plan');

    $this->actingAs($records)->post(route('submission-tracking.transition', ['management-plans', $plan->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'official_document' => $replacement,
    ])->assertSessionHasNoErrors();

    $fresh = $plan->fresh();
    $officialPath = data_get($fresh->attachments, '0.path');
    expect(data_get($fresh->attachments, '0.original_name'))->toBe('released-plan.pdf')
        ->and(array_slice($fresh->attachments, 1, null, true))->toBe($originalSiblings)
        ->and(Storage::disk('local')->exists('management-plans/official-current.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists($officialPath))->toBeTrue()
        ->and(Storage::disk('local')->get(data_get($fresh->attachments, '1.path')))->toBe('%PDF resolution')
        ->and(Storage::disk('local')->get(data_get($fresh->attachments, '2.path')))->toBe('%PDF attendance')
        ->and(Storage::disk('local')->allFiles('current-documents/management-plans/'.$plan->id.'/0'))->toHaveCount(1)
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->where('logical_slot', '0')->where('action', 'RELEASE')->exists())->toBeTrue()
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($fresh, 'management-plans')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
});

test('structured official document Receive rejects replacement and preserves the canonical MOV slot', function (): void {
    Storage::fake('local');
    $creator = User::factory()->create();
    $plan = officialSlotManagementPlan($creator, 'Structured Receive');
    [, , $records] = prepareOfficialSlotManagementPlanForRelease($plan);
    app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->transition($plan, 'management-plans', 'forward_to_penro_records', $records->id);
    $penroRecords = officialSlotManagementPlanActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $originalSiblings = array_slice($plan->fresh()->attachments, 1, null, true);

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['management-plans', $plan->id, 'receive_at_penro_records']), [
        'stage' => 'receive_at_penro_records', 'official_document' => UploadedFile::fake()->createWithContent('received-plan.pdf', '%PDF received plan'),
    ])->assertSessionHasErrors('official_document');

    $fresh = $plan->fresh();
    expect(data_get($fresh->attachments, '0.original_name'))->toBe('official-current.pdf')
        ->and(array_slice($fresh->attachments, 1, null, true))->toBe($originalSiblings)
        ->and(Storage::disk('local')->exists('management-plans/official-current.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->get(data_get($fresh->attachments, '1.path')))->toBe('%PDF resolution')
        ->and(Storage::disk('local')->get(data_get($fresh->attachments, '2.path')))->toBe('%PDF attendance')
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->where('logical_slot', '0')->where('action', 'RECEIVE')->doesntExist())
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($fresh, 'management-plans')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
});

test('structured official document correction resubmission replaces only the canonical MOV slot', function (): void {
    Storage::fake('local');
    $creator = User::factory()->create();
    $plan = officialSlotManagementPlan($creator, 'Structured Correction');
    [, , $records] = prepareOfficialSlotManagementPlanForRelease($plan);
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    $routing->transition($plan, 'management-plans', 'forward_to_penro_records', $records->id);
    $penroRecords = officialSlotManagementPlanActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $routing->transition($plan, 'management-plans', 'return_for_correction_penro_records', $penroRecords->id, null, 'missing_received_copy');
    $routing->transition($plan, 'management-plans', 'receive_correction', $records->id);
    $originalSiblings = array_slice($plan->fresh()->attachments, 1, null, true);

    $this->actingAs($records)->post(route('submission-tracking.transition', ['management-plans', $plan->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'official_document' => UploadedFile::fake()->createWithContent('corrected-plan.pdf', '%PDF corrected plan'),
    ])->assertSessionHasNoErrors();

    $fresh = $plan->fresh();
    $lastEvent = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->latest('id')->firstOrFail();
    expect(data_get($fresh->attachments, '0.original_name'))->toBe('corrected-plan.pdf')
        ->and(array_slice($fresh->attachments, 1, null, true))->toBe($originalSiblings)
        ->and(Storage::disk('local')->exists('management-plans/official-current.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->get(data_get($fresh->attachments, '1.path')))->toBe('%PDF resolution')
        ->and(Storage::disk('local')->get(data_get($fresh->attachments, '2.path')))->toBe('%PDF attendance')
        ->and(Storage::disk('local')->allFiles('current-documents/management-plans/'.$plan->id.'/0'))->toHaveCount(1)
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->where('logical_slot', '0')->where('action', 'CORRECTION')->exists())->toBeTrue()
        ->and(data_get($lastEvent->metadata, 'correction_cycle'))->toBeTrue()
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($fresh, 'management-plans')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
});

test('client-selected sibling slot is rejected before Management Plan routing or document mutation', function (): void {
    Storage::fake('local');
    $creator = User::factory()->create();
    $plan = officialSlotManagementPlan($creator, 'Arbitrary Slot Rejected');
    [, , $records] = prepareOfficialSlotManagementPlanForRelease($plan);
    $eventsBefore = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->count();
    $siblingsBefore = $plan->fresh()->attachments;

    $this->actingAs($records)->post(route('submission-tracking.transition', ['management-plans', $plan->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'official_document_slot' => '1',
        'official_document' => UploadedFile::fake()->createWithContent('wrong-sibling.pdf', '%PDF must not replace resolution'),
    ])->assertSessionHasErrors('official_document_slot');

    expect($plan->fresh()->attachments)->toBe($siblingsBefore)
        ->and(Storage::disk('local')->exists('management-plans/official-current.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->count())->toBe($eventsBefore)
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($plan->fresh(), 'management-plans')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CENRO_RECORDS);
});

test('failed structured official document persistence rolls back the routing event and preserves every sibling', function (): void {
    Storage::fake('local');
    $creator = User::factory()->create();
    $plan = officialSlotManagementPlan($creator, 'Structured Update Failure');
    [, , $records] = prepareOfficialSlotManagementPlanForRelease($plan);
    $originalAttachments = $plan->fresh()->attachments;
    $eventCount = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->count();
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
        $sql = strtolower($query->sql);
        if (str_starts_with(ltrim($sql), 'update') && str_contains($sql, 'management_plans') && str_contains($sql, 'attachments')) {
            throw new RuntimeException('Injected structured document reference failure');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($records)->post(route('submission-tracking.transition', ['management-plans', $plan->id, 'forward_to_penro_records']), [
        'stage' => 'forward_to_penro_records', 'official_document' => UploadedFile::fake()->createWithContent('failed-structured-release.pdf', '%PDF replacement that must roll back'),
    ]))->toThrow(RuntimeException::class);

    expect($plan->fresh()->attachments)->toBe($originalAttachments)
        ->and(Storage::disk('local')->exists('management-plans/official-current.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->exists(data_get($originalAttachments, '1.path')))->toBeTrue()
        ->and(Storage::disk('local')->exists(data_get($originalAttachments, '2.path')))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'management-plans')->where('source_id', $plan->id)->count())->toBe($eventCount)
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($plan->fresh(), 'management-plans')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CENRO_RECORDS);
});

test('Conservation Full Details exposes the official document while routing copies remain separately authorized', function (): void {
    Storage::fake('local');
    $creator = effectiveDocumentAuditUser(OrganizationalAccessService::CENRO_FOCAL);
    $report = attachmentPambReport();
    $report->update([
        'target_office' => 'CENRO Mati',
        'date_report_released_cenro' => '2026-09-10',
        'date_received_penro' => '2026-09-11',
        'date_endorsed_regional' => '2026-09-12',
        'mov_file_path' => 'conservation-report-movs/module-original.pdf',
        'mov_file_name' => 'module-original.pdf',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
    Storage::disk('local')->put($report->mov_file_path, '%PDF original module MOV');
    $actor = User::factory()->create();
    $attachments = app(RoutingAttachmentService::class);
    $event = $report->routingEvents()->create(['workflow_key' => $report->workflow_key, 'stage_key' => 'signed-current-document', 'occurred_at' => '2026-09-13 09:00:00', 'recorded_by' => $actor->id]);
    $file = UploadedFile::fake()->create('final-signed-copy.pdf', 18, 'application/pdf');
    $latest = $attachments->create('conservation', $report->id, $file, $attachments->store($file), $actor, $event->stage_key, $event->stage_key, null, null, $event);

    foreach ([
        $creator,
        effectiveDocumentAuditUser('Super Admin', 'PENRO Davao Oriental'),
        effectiveDocumentAuditUser(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental'),
        effectiveDocumentAuditUser(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental'),
    ] as $viewer) {
        $this->actingAs($viewer)
            ->get(route('conservation-reports.index', 'regular_pamb'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ConservationReports/Index')
                ->where('submissions.data.0.current_document.name', 'module-original.pdf'));

        $this->get(route('submission-tracking.routing-attachments.show', ['conservation', $report->id, $latest->id]))->assertOk();
        $serviceRow = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
        // Legacy source dates do not establish a complete canonical routing cycle.
        expect($serviceRow['routing_complete'])->toBeFalse()
            ->and($serviceRow['current_document']['name'])->toBe('module-original.pdf');
    }

    $outOfScope = effectiveDocumentAuditUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $this->actingAs($outOfScope)
        ->get(route('submission-tracking.routing-attachments.show', ['conservation', $report->id, $latest->id]))
        ->assertForbidden();
});

test('final PENRO Records receipt does not rearchive and later regional release does not upload', function (): void {
    Storage::fake('local');
    $report = attachmentRoutingReport('Final Archive Retrieval');
    $path = 'bms-report-movs/final-archive-'.$report->id.'.pdf';
    $bytes = "%PDF-1.4\nfinal official MOV";
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'Final official MOV.pdf']);
    Storage::disk('local')->put($path, $bytes);
    $actors = prepareBmsReportForFinalRelease($report);
    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $eventsBeforeFinal = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count();

    expect(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and($gateway->uploads)->toBe(0);

    $this->actingAs($actors['penro_records'])->post(route('submission-tracking.transition', ['bms', $report->id, 'receive_at_penro_records_final']), [
        'stage' => 'receive_at_penro_records_final',
    ])->assertSessionHasNoErrors();

    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->where('logical_slot', 'mov')->first();
    $tracking = app(\App\Services\SubmissionTracking\SubmissionTrackingService::class);
    $routingState = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($report->fresh(), 'bms');
    $finalRecord = $report->fresh();
    expect($archive)->toBeNull()
        ->and($gateway->uploads)->toBe(0)
        ->and($tracking->isRoutingComplete($finalRecord))->toBeFalse()
        ->and($routingState['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe($eventsBeforeFinal + 1)
        ->and($gateway->archiveContexts)->toBe([]);
    Storage::disk('local')->assertExists($path);

    $this->actingAs($actors['penro_records'])->post(route('submission-tracking.transition', ['bms', $report->id, 'release_to_regional']), [
        'stage' => 'release_to_regional',
    ])->assertSessionHasNoErrors();
    $finalRecord = $report->fresh();
    expect($tracking->isRoutingComplete($finalRecord))->toBeTrue()
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($finalRecord, 'bms')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($gateway->uploads)->toBe(0)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe($eventsBeforeFinal + 2);

    $viewer = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $viewer->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $url = route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']);
    $currentFilename = 'Final official MOV.pdf';
    $this->actingAs($viewer)->get($url)->assertOk()->assertHeader('Content-Disposition', 'inline; filename="'.$currentFilename.'"')->assertStreamedContent($bytes);
    $this->get($url.'?download=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="'.$currentFilename.'"')->assertStreamedContent($bytes);

    $wrongOffice = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $wrongOffice->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $this->actingAs($wrongOffice)->get($url)->assertForbidden();
    $foreignArea = ProtectedArea::create([
        'name' => 'Archive Foreign PA', 'category' => 'Protected Landscape', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active',
        'created_by' => $viewer->id, 'updated_by' => $viewer->id,
    ]);
    $wrongPa = User::factory()->create([
        'section' => OrganizationalAccessService::PAMO,
        'office_designated' => 'PAMO',
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'protected_area_id' => $foreignArea->id,
    ]);
    $wrongPa->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $this->actingAs($wrongPa)->get($url)->assertForbidden();
    $this->actingAs($viewer)->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'workflow-archive-1']))->assertNotFound();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('PENRO Records receipt stops locally and explicit forward performs the checkpoint archive once', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $report = attachmentRoutingReport('Intermediate Receipt Must Not Archive');
    $path = 'bms-report-movs/intermediate-receipt-'.$report->id.'.pdf';
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'Current working MOV.pdf']);
    Storage::disk('local')->put($path, "%PDF-1.4\nintermediate current MOV");
    $focal = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);

    foreach ([
        [$focal, 'forward_to_cenro_chief'],
        [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'],
        [$cenroRecords, 'receive_at_cenro_records'],
        [$cenroRecords, 'forward_to_penro_records'],
    ] as [$actor, $action]) {
        $routing->transition($report->fresh(), 'bms', $action, $actor->id);
    }

    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $receiveAction = collect($routing->presentation($report->fresh(), 'bms', null, $penroRecords)['allowed_actions'])->firstWhere('key', 'receive_at_penro_records');
    expect($receiveAction['can_replace_document'] ?? null)->toBeFalse();
    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['bms', $report->id, 'receive_at_penro_records']), [
        'stage' => 'receive_at_penro_records',
    ])->assertSessionHasNoErrors();

    $eventsAfterReceive = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->get();
    expect($eventsAfterReceive)->toHaveCount(6)
        ->and($eventsAfterReceive->last()->event_key)->toBe('received')
        ->and($eventsAfterReceive->last()->to_stage)->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and($gateway->uploads)->toBe(0)
        ->and($gateway->lookups)->toBe(0);

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_office_penro']), [
        'stage' => 'forward_to_office_penro',
    ])->assertSessionHasNoErrors();

    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->first();
    expect($archive)->not->toBeNull()
        ->and($archive->archive_status)->toBe('ARCHIVED')
        ->and($archive->archived_sha256)->toBe(hash('sha256', "%PDF-1.4\nintermediate current MOV"))
        ->and($gateway->uploads)->toBe(1)
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($report->fresh(), 'bms')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO);
    app(\App\Services\SubmissionTracking\RoutingTransitionLifecycle::class)->afterTransition(
        DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->latest('id')->firstOrFail(),
        $penroRecords,
    );
    expect($gateway->uploads)->toBe(1)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe(1);
    $office = attachmentRoutingActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $this->actingAs($office)->post(route('submission-tracking.transition', ['bms', $report->id, 'receive_at_office_penro']), [
        'stage' => 'receive_at_office_penro',
    ])->assertSessionHasNoErrors();
    expect($gateway->uploads)->toBe(1)->and($gateway->lookups)->toBe(1);
    Storage::disk('local')->assertExists($path);
});

test('PENRO checkpoint archives the replacement document supplied on the same optional Forward', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $report = attachmentRoutingReport('Checkpoint Replacement Uses New Copy');
    $oldPath = 'bms-report-movs/checkpoint-old-'.$report->id.'.pdf';
    $oldBytes = "%PDF-1.4\nold current copy";
    $newBytes = "%PDF-1.4\nnew signed checkpoint copy";
    $report->update(['mov_file_path' => $oldPath, 'mov_file_name' => 'old-current.pdf']);
    Storage::disk('local')->put($oldPath, $oldBytes);
    $actors = [
        'focal' => attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        'chief' => attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        'cenro_records' => attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        'penro_records' => attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
    ];
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    foreach ([['focal', 'forward_to_cenro_chief'], ['chief', 'receive_at_cenro_chief'], ['chief', 'forward_to_cenro_records'], ['cenro_records', 'receive_at_cenro_records'], ['cenro_records', 'forward_to_penro_records'], ['penro_records', 'receive_at_penro_records']] as [$actor, $action]) {
        $routing->transition($report->fresh(), 'bms', $action, $actors[$actor]->id);
    }
    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $this->actingAs($actors['penro_records'])->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_office_penro']), [
        'stage' => 'forward_to_office_penro',
        'official_document' => UploadedFile::fake()->createWithContent('new-signed.pdf', $newBytes),
    ])->assertSessionHasNoErrors();

    $fresh = $report->fresh();
    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->firstOrFail();
    expect($fresh->mov_file_name)->toBe('new-signed.pdf')
        ->and($fresh->mov_file_path)->not->toBe($oldPath)
        ->and(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($fresh->mov_file_path))->toBeTrue()
        ->and($archive->archived_sha256)->toBe(hash('sha256', $newBytes))
        ->and($gateway->uploads)->toBe(1)
        ->and($gateway->objects)->toHaveCount(1)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe(1)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe(7);
});

test('archive verification failure at the records checkpoint preserves routing state and the private working document', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $report = attachmentRoutingReport('Failed Final Archive');
    $path = 'bms-report-movs/final-archive-failure-'.$report->id.'.pdf';
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'Final official MOV.pdf']);
    Storage::disk('local')->put($path, "%PDF-1.4\nfinal official MOV");
    $actors = [
        'focal' => attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        'chief' => attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        'cenro_records' => attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        'penro_records' => attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
    ];
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    foreach ([['focal', 'forward_to_cenro_chief'], ['chief', 'receive_at_cenro_chief'], ['chief', 'forward_to_cenro_records'], ['cenro_records', 'receive_at_cenro_records'], ['cenro_records', 'forward_to_penro_records']] as [$actor, $action]) {
        $routing->transition($report->fresh(), 'bms', $action, $actors[$actor]->id);
    }
    $gateway = new FinalArchiveWorkflowGateway();
    $gateway->failVerify = true;
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $this->actingAs($actors['penro_records'])->post(route('submission-tracking.transition', ['bms', $report->id, 'receive_at_penro_records']), [
        'stage' => 'receive_at_penro_records',
    ])->assertSessionHasNoErrors();
    expect(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and($gateway->uploads)->toBe(0);

    $this->actingAs($actors['penro_records'])->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_office_penro']), [
        'stage' => 'forward_to_office_penro',
        'official_document' => UploadedFile::fake()->createWithContent('must-not-persist.pdf', '%PDF must not persist'),
    ])->assertSessionHasErrors('archive');

    $finalRecord = $report->fresh();
    expect(app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->isRoutingComplete($finalRecord))->toBeFalse()
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->state($finalRecord, 'bms')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and($finalRecord->mov_file_path)->toBe($path)
        ->and($finalRecord->mov_file_name)->toBe('Final official MOV.pdf')
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and(\App\Models\DocumentAttachmentHistory::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe(0)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count())->toBe(6);
    Storage::disk('local')->assertExists($path);

    $viewer = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $viewer->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $this->actingAs($viewer)->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']))
        ->assertOk()->assertStreamedContent("%PDF-1.4\nfinal official MOV");
});

test('disabled archive blocks the normal checkpoint and rolls back the forward event', function (): void {
    config(['services.google_drive_archive.enabled' => false, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    $report = attachmentRoutingReport('Disabled archive checkpoint');
    $path = 'bms-report-movs/disabled-checkpoint-'.$report->id.'.pdf';
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'Current official MOV.pdf']);
    Storage::disk('local')->put($path, "%PDF-1.4\ndisabled checkpoint");
    $actors = [
        'focal' => attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        'chief' => attachmentRoutingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        'cenro_records' => attachmentRoutingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        'penro_records' => attachmentRoutingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
    ];
    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $events = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id);
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    foreach ([
        [$actors['focal'], 'forward_to_cenro_chief'], [$actors['chief'], 'receive_at_cenro_chief'],
        [$actors['chief'], 'forward_to_cenro_records'], [$actors['cenro_records'], 'receive_at_cenro_records'],
        [$actors['cenro_records'], 'forward_to_penro_records'], [$actors['penro_records'], 'receive_at_penro_records'],
    ] as [$actor, $action]) $routing->transition($report->fresh(), 'bms', $action, $actor->id);

    expect($events->count())->toBe(6);
    $this->actingAs($actors['penro_records'])->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_office_penro']), [
        'stage' => 'forward_to_office_penro',
        'official_document' => UploadedFile::fake()->createWithContent('must-not-persist.pdf', '%PDF must not persist'),
    ])->assertSessionHasErrors('archive');

    expect($events->count())->toBe(6)
        ->and($routing->state($report->fresh(), 'bms')['stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and($report->fresh()->mov_file_path)->toBe($path)
        ->and($report->fresh()->mov_file_name)->toBe('Current official MOV.pdf')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
        ->and($gateway->uploads)->toBe(0)
        ->and($gateway->lookups)->toBe(0);
});

test('disabled checkpoint is presented as a system blocker instead of ordinary form validation', function (): void {
    $trackingPage = file_get_contents(resource_path('js/Pages/SubmissionTracking/Index.jsx'));
    $formModal = file_get_contents(resource_path('js/Components/Crud/CrudFormModal.jsx'));
    expect($trackingPage)->toContain('Archive checkpoint unavailable')
        ->and($trackingPage)->toContain('Google Drive archiving is currently disabled.')
        ->and($formModal)->toContain('systemNotice &&')
        ->and($formModal)->toContain('dark:bg-amber-950/40');
});

test('administrative override uses the same archive checkpoint lifecycle and preserves passkey audit', function (): void {
    $fixture = archiveOverrideFixture(true);
    $verifier = \Mockery::mock(\Laravel\Passkeys\Actions\VerifyPasskey::class);
    $verifier->shouldReceive('__invoke')->once()->andReturn($fixture['passkey']);
    app()->instance(\Laravel\Passkeys\Actions\VerifyPasskey::class, $verifier);
    $credential = (new \ReflectionClass(\Webauthn\PublicKeyCredential::class))->newInstanceWithoutConstructor();
    $options = (new \ReflectionClass(\Webauthn\PublicKeyCredentialRequestOptions::class))->newInstanceWithoutConstructor();

    $override = app(\App\Services\SubmissionTracking\AdminRoutingOverrideService::class)->execute(
        'bms', $fixture['report']->id, 'forward_to_office_penro', $fixture['admin'], 'FINAL-UAT archive parity', $credential, $options,
    );

    $event = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->latest('id')->firstOrFail();
    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->firstOrFail();
    expect($event->from_stage)->toBe('penro_records')
        ->and($event->to_stage)->toBe('transit_to_office_of_penro')
        ->and(data_get($event->metadata, 'administrative_override'))->toBeTrue()
        ->and($override->authentication_method)->toBe('webauthn_passkey')
        ->and($override->passkey_id)->toBe($fixture['passkey']->id)
        ->and($archive->archive_status)->toBe('ARCHIVED')
        ->and($archive->original_filename)->toBe('Routing report.pdf')
        ->and($fixture['gateway']->objects['workflow-archive-1']['filename'])->toBe('Routing report.pdf')
        ->and($fixture['gateway']->uploads)->toBe(1)
        ->and(\App\Models\AuditLog::query()->where('action', 'Submission Tracking Administrative Override')->where('entity_id', (string) $fixture['report']->id)->exists())->toBeTrue();

    app(\App\Services\SubmissionTracking\RoutingTransitionLifecycle::class)->afterTransition($event, $fixture['admin']);
    expect($fixture['gateway']->uploads)->toBe(1)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count())->toBe(1);
});

test('administrative PENRO Records Receive remains outside the archive checkpoint', function (): void {
    $fixture = archiveOverrideFixture(true, false);
    $verifier = \Mockery::mock(\Laravel\Passkeys\Actions\VerifyPasskey::class);
    $verifier->shouldReceive('__invoke')->once()->andReturn($fixture['passkey']);
    app()->instance(\Laravel\Passkeys\Actions\VerifyPasskey::class, $verifier);
    $credential = (new \ReflectionClass(\Webauthn\PublicKeyCredential::class))->newInstanceWithoutConstructor();
    $options = (new \ReflectionClass(\Webauthn\PublicKeyCredentialRequestOptions::class))->newInstanceWithoutConstructor();

    $override = app(\App\Services\SubmissionTracking\AdminRoutingOverrideService::class)->execute(
        'bms', $fixture['report']->id, 'receive_at_penro_records', $fixture['admin'], 'Receive parity', $credential, $options,
    );
    $event = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->latest('id')->firstOrFail();

    expect($event->event_key)->toBe('received')
        ->and($event->from_stage)->toBe('transit_to_penro_records')
        ->and($event->to_stage)->toBe('penro_records')
        ->and($override->authentication_method)->toBe('webauthn_passkey')
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->exists())->toBeFalse()
        ->and($fixture['gateway']->uploads)->toBe(0)
        ->and($fixture['gateway']->lookups)->toBe(0);
});

test('disabled archive rolls back an administrative checkpoint override without provider calls', function (): void {
    $fixture = archiveOverrideFixture(false);
    $verifier = \Mockery::mock(\Laravel\Passkeys\Actions\VerifyPasskey::class);
    $verifier->shouldReceive('__invoke')->once()->andReturn($fixture['passkey']);
    app()->instance(\Laravel\Passkeys\Actions\VerifyPasskey::class, $verifier);
    $credential = (new \ReflectionClass(\Webauthn\PublicKeyCredential::class))->newInstanceWithoutConstructor();
    $options = (new \ReflectionClass(\Webauthn\PublicKeyCredentialRequestOptions::class))->newInstanceWithoutConstructor();

    try {
        app(\App\Services\SubmissionTracking\AdminRoutingOverrideService::class)->execute(
            'bms', $fixture['report']->id, 'forward_to_office_penro', $fixture['admin'], 'Disabled archive guard', $credential, $options,
        );
        test()->fail('Disabled required archive should reject the override transition.');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKey('archive');
    }

    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count())->toBe(6)
        ->and(\App\Models\SubmissionRoutingOverride::query()->where('source', 'bms')->where('source_record_id', $fixture['report']->id)->exists())->toBeFalse()
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->exists())->toBeFalse()
        ->and($fixture['gateway']->uploads)->toBe(0)
        ->and($fixture['gateway']->lookups)->toBe(0);
});

test('protected archive 404 records unavailable while transient provider errors remain unknown', function (): void {
    Storage::fake('local');
    $report = attachmentRoutingReport('Archive Availability');
    $path = 'bms-report-movs/archive-availability-'.$report->id.'.pdf';
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'Official MOV.pdf']);
    $archive = DocumentArchive::query()->create([
        'source_type' => 'bms', 'source_id' => $report->id, 'logical_slot' => 'mov',
        'archive_status' => 'ARCHIVED', 'google_drive_file_id' => 'missing-drive-object',
        'archived_sha256' => hash('sha256', "%PDF-1.4\narchive"), 'archived_size' => strlen("%PDF-1.4\narchive"),
    ]);
    $viewer = attachmentRoutingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $viewer->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $gateway = new FinalArchiveWorkflowGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $gateway->retrieveFailureStatus = 503;
    $this->actingAs($viewer)->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']))->assertNotFound();
    expect($archive->fresh()->archive_status)->toBe('ARCHIVED')
        ->and($archive->fresh()->remote_availability)->toBe('unknown')
        ->and($archive->fresh()->last_verification_error_class)->toBe('PROVIDER_ERROR');

    $gateway->retrieveFailureStatus = 404;
    $this->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']))->assertNotFound();
    expect($archive->fresh()->archive_status)->toBe('ARCHIVED')
        ->and($archive->fresh()->remote_availability)->toBe('unavailable')
        ->and($archive->fresh()->last_verification_error_class)->toBe('NOT_FOUND');

    $gateway->retrieveFailureStatus = 503;
    $this->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']))->assertNotFound();
    expect($archive->fresh()->remote_availability)->toBe('unavailable')
        ->and($gateway->retrievals)->toBe(2);
});
