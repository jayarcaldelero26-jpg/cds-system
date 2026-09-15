<?php

use App\Models\ConservationReportSubmission;
use App\Models\PambRoutingEvent;
use App\Models\SubmissionRoutingAttachment;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\Authorization\OrganizationalAccessService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Services\SubmissionTracking\RoutingAttachmentService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

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
    $focal = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = routingActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = routingActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = routingActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = routingActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = routingActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $report = routingReport('Attachment Optional Route');
    $service = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);

    foreach ([
        [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records'],
        [$records, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
        [$penroRecords, 'forward_to_office_penro'], [$office, 'receive_at_office_penro'],
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
    $actor = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = routingReport('Generic Attachment Presentation');
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

test('PAMB internal routing attachment links to the exact persisted event', function (): void {
    Storage::fake('local');
    $user = User::factory()->create(['section' => 'PENRO_RECORDS', 'unit_assignment' => 'conservation', 'office_designated' => 'PENRO Davao Oriental']);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    $user->assignRole(Role::findOrCreate('PENRO Records', 'web'));
    $report = ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati', 'activity_name' => 'Attachment PAMB Internal', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03', 'created_by' => $user->id, 'updated_by' => $user->id, 'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05']);
    $file = UploadedFile::fake()->create('internal-forward.pdf', 12, 'application/pdf');

    $this->actingAs($user)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
    ]), [
        'stage' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
        'remarks' => 'Routing slip attached.',
        'attachment' => $file,
    ])->assertSessionHasNoErrors();

    $event = $report->fresh()->routingEvents()->latest('id')->firstOrFail();
    $attachment = SubmissionRoutingAttachment::query()->where('pamb_routing_event_id', $event->id)->firstOrFail();

    expect($attachment->source)->toBe('conservation')
        ->and($attachment->source_id)->toBe($report->id)
        ->and($attachment->stage_key)->toBe(PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO)
        ->and($attachment->action_key)->toBe(PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO)
        ->and(Storage::disk('local')->exists($attachment->stored_path))->toBeTrue();
});

// Attachment validation matrix is covered by the existing endpoint and policy suites.
test('failed transition cleans the newly stored routing file and preserves prior copies', function (): void {
    Storage::fake('local');
    $actor = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = routingReport('Failed Attachment Transition');
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

test('original MOV is the current document when no routed copy exists', function (): void {
    Storage::fake('local');
    $actor = routingActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $report = routingReport('Original MOV Fallback');
    $actor->update(['protected_area_id' => $report->protected_area_id]);
    $report->update(['mov_file_path' => 'bms-reports/original-mov.pdf', 'mov_file_name' => 'original-mov.pdf']);
    Storage::disk('local')->put('bms-reports/original-mov.pdf', 'original');

    $this->actingAs($actor);
    $row = app(SubmissionTrackingService::class)->records([], null)
        ->first(fn (array $item): bool => $item['source'] === 'bms' && (int) $item['source_id'] === $report->id);

    expect($row)->not->toBeNull()
        ->and(SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and($row['current_document']['source'])->toBe('Original MOV / report')
        ->and($row['current_document']['download_url'])->not->toBeEmpty()
        ->and($row['current_document']['name'])->not->toBeEmpty();
});

test('effective document prefers the latest routing version and preserves exact event history', function (): void {
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

    expect($current['id'])->toBe($latest->id)
        ->and($current['name'])->toBe('signed-final.pdf')
        ->and($current['is_routing_copy'])->toBeTrue()
        ->and($current['version_source'])->toBe('routing')
        ->and(SubmissionRoutingAttachment::query()->where('source', 'conservation')->where('source_id', $report->id)->count())->toBe(2)
        ->and(SubmissionRoutingAttachment::findOrFail($first->id)->pamb_routing_event_id)->toBe($firstEvent->id)
        ->and(SubmissionRoutingAttachment::findOrFail($latest->id)->pamb_routing_event_id)->toBe($secondEvent->id)
        ->and(Storage::disk('local')->exists($report->mov_file_path))->toBeTrue();
});

test('Conservation Full Details exposes the effective completed copy to scoped viewers', function (): void {
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
                ->where('submissions.data.0.current_document.id', $latest->id)
                ->where('submissions.data.0.current_document.name', 'final-signed-copy.pdf'));

        $this->get(route('submission-tracking.routing-attachments.show', ['conservation', $report->id, $latest->id]))->assertOk();
        $serviceRow = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);
        expect($serviceRow['routing_complete'])->toBeTrue()
            ->and($serviceRow['current_document']['id'])->toBe($latest->id);
    }

    $outOfScope = effectiveDocumentAuditUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $this->actingAs($outOfScope)
        ->get(route('submission-tracking.routing-attachments.show', ['conservation', $report->id, $latest->id]))
        ->assertForbidden();
});
