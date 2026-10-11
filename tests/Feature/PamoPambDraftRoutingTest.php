<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\OfficeTatAnalyticsService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\EffectiveRoutingGraphResolver;
use App\Services\SubmissionTracking\RoutingPositionSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function pamoPilotActor(string $category, ?string $office, ?int $protectedAreaId = null): User
{
    $user = User::factory()->create([
        'section' => $category,
        'unit_assignment' => null,
        'office_designated' => $office,
        'protected_area_id' => $protectedAreaId,
    ]);

    foreach (['technical-reports.view', 'submission-tracking.view'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    return $user;
}

/** @return array{ProtectedArea,OrganizationalOffice} */
function pamoPilotArea(User $creator, string $shortName, string $pamoName, string $supervisingCode): array
{
    $pamoOffice = OrganizationalOffice::query()->create([
        'code' => 'pamo_pilot_'.Str::lower(Str::random(12)),
        'name' => $pamoName,
        'office_type' => 'pamo',
        'is_active' => true,
    ]);
    $area = ProtectedArea::query()->create([
        'name' => $shortName === 'MHRWS' ? 'Mt. Hamiguitan Range Wildlife Sanctuary' : 'PAMO Pilot '.$shortName,
        'short_name' => $shortName,
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'pamo' => $pamoName,
        'created_by' => $creator->getKey(),
        'updated_by' => $creator->getKey(),
    ]);
    $supervisingOffice = OrganizationalOffice::query()->where('code', $supervisingCode)->firstOrFail();

    foreach ([['pamo', $pamoOffice], ['supervising', $supervisingOffice]] as [$type, $office]) {
        ProtectedAreaOfficeAssignment::query()->create([
            'protected_area_id' => $area->getKey(),
            'organizational_office_id' => $office->getKey(),
            'assignment_type' => $type,
            'assigned_by' => $creator->getKey(),
        ]);
    }

    return [$area, $pamoOffice];
}

function pamoPilotUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('pamb-minutes.pdf', "%PDF-1.4\nPAMB test fixture\n%%EOF");
}

test('PAMO registration stays pending and derives its stable office identity from the approved PA mapping', function (): void {
    Role::findOrCreate('no_role', 'web');
    $creator = User::factory()->create();
    [$area, $office] = pamoPilotArea($creator, 'APL', 'PAMO Aliwagwag', 'cenro_baganga');

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('registrationOptions.operationalGroups.2.value', 'pamo')
            ->where('registrationOptions.protectedAreas.0.id', $area->getKey())
            ->where('registrationOptions.protectedAreas.0.name', $area->name)
            ->where('registrationOptions.protectedAreas.0.pamo_office_id', $office->getKey()));

    $this->post(route('register'), [
        'name' => 'Pending PAMO Operator',
        'email' => 'pamo-pilot-registration@example.test',
        'password' => 'Valid-Pilot-Password-2026',
        'password_confirmation' => 'Valid-Pilot-Password-2026',
        'operational_group' => 'pamo',
        'section' => 'PAMO',
        'protected_area_id' => $area->getKey(),
        'office_designated' => 'forged client office',
    ])->assertSessionHasErrors('office_designated');

    $this->post(route('register'), [
        'name' => 'Pending PAMO Operator',
        'email' => 'pamo-pilot-registration@example.test',
        'password' => 'Valid-Pilot-Password-2026',
        'password_confirmation' => 'Valid-Pilot-Password-2026',
        'operational_group' => 'pamo',
        'section' => 'PAMO',
        'protected_area_id' => $area->getKey(),
    ])->assertRedirect(route('login'));

    $pending = User::query()->where('email', 'pamo-pilot-registration@example.test')->firstOrFail();
    expect($pending->section)->toBe(OrganizationalAccessService::PAMO)
        ->and($pending->unit_assignment)->toBeNull()
        ->and($pending->protected_area_id)->toBe($area->getKey())
        ->and($pending->office_designated)->toBe($office->name)
        ->and($pending->is_approved)->toBeFalse()
        ->and($pending->is_active)->toBeFalse()
        ->and($pending->roles()->pluck('name')->all())->toBe(['no_role'])
        ->and($pending->getAllPermissions()->pluck('name')->all())->toBe([]);

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $this->actingAs($admin)->patch(route('admin.users.approve', $pending))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success', 'User account approved successfully.');
    expect($pending->fresh()->is_approved)->toBeTrue()
        ->and($pending->fresh()->is_active)->toBeTrue()
        ->and($pending->fresh()->office_designated)->toBe($office->name)
        ->and($pending->fresh()->getRoleNames()->all())->toBe(['no_role'])
        ->and($pending->fresh()->getAllPermissions()->pluck('name')->all())->toBe([]);
    $this->actingAs($pending->fresh())->get(route('conservation-reports.index', 'regular_pamb'))->assertOk();
});

test('public registration returns no PAMO option when an active PA lacks an explicit active office mapping', function (): void {
    $creator = User::factory()->create();
    [$area] = pamoPilotArea($creator, 'APL', 'PAMO Unmapped Pilot', 'cenro_baganga');
    ProtectedAreaOfficeAssignment::query()->where('protected_area_id', $area->getKey())->where('assignment_type', 'pamo')->delete();

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('registrationOptions.protectedAreas', [])
            ->where('registrationOptions.operationalGroups.2.value', 'pamo'));

    expect(app(OrganizationalAccessService::class)->pamoRegistrationProtectedAreas())->toBe([])
        ->and(ConservationReportSubmission::query()->count())->toBe(0)
        ->and(PamoPambDraft::query()->count())->toBe(0);
});

test('new PAMO office identity does not reuse a different name with the same legacy slug', function (): void {
    $creator = User::factory()->create();
    [$area] = pamoPilotArea($creator, 'APL', 'PAMO Existing Pilot', 'cenro_baganga');
    $slugCollision = OrganizationalOffice::query()->create([
        'code' => 'pamo_pamo-lake-site', 'name' => 'PAMO Lake Site Other Identity', 'office_type' => 'pamo', 'is_active' => true,
    ]);
    $admin = User::factory()->create();
    $admin->givePermissionTo(Permission::findOrCreate('protected-areas.update', 'web'));

    $created = app(OrganizationalAccessService::class)->assignPamoOfficeByName($area, 'PAMO Lake/Site', $admin);
    $mapping = $area->pamoOfficeAssignment()->with('office')->firstOrFail();

    expect($created->getKey())->not->toBe($slugCollision->getKey())
        ->and($mapping->organizational_office_id)->toBe($created->getKey())
        ->and($mapping->office->name)->toBe('PAMO Lake/Site')
        ->and($area->fresh()->pamo)->toBe('PAMO Lake/Site');
});

test('partial PAMO drafts remain private until exact-once APL promotion and CENRO focal receipt', function (): void {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:00:00', 'Asia/Manila'));
    Storage::fake('local');
    Notification::fake();

    try {
        $owner = User::factory()->create();
        [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Aliwagwag', 'cenro_baganga');
        $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
        $coworker = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
        [$foreignArea, $foreignOffice] = pamoPilotArea($owner, 'OTHER', 'Other PAMO', 'cenro_baganga');
        $foreign = pamoPilotActor(OrganizationalAccessService::PAMO, $foreignOffice->name, (int) $foreignArea->getKey());
        $unapproved = User::factory()->create([
            'section' => OrganizationalAccessService::PAMO, 'office_designated' => $office->name,
            'protected_area_id' => $area->getKey(), 'is_active' => false, 'is_approved' => false,
        ]);
        $token = (string) Str::uuid();

        $this->actingAs($pamo)->from(route('conservation-reports.index', 'regular_pamb'))
            ->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
                'start_idempotency_key' => $token,
                'mov' => pamoPilotUpload(),
            ])->assertRedirect(route('conservation-reports.index', 'regular_pamb'));

        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        $startedAt = $draft->started_at?->toIso8601String();
        expect($startedAt)->toBe('2026-10-11T10:00:00+08:00')
            ->and(ConservationReportSubmission::query()->count())->toBe(0)
            ->and(DocumentRoutingEvent::query()->count())->toBe(0);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:30:00', 'Asia/Manila'));
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
            'start_idempotency_key' => $token,
            'remarks' => 'Same start retried safely',
        ])->assertRedirect();
        expect(PamoPambDraft::query()->count())->toBe(1)
            ->and(PamoPambDraft::query()->firstOrFail()->started_at?->toIso8601String())->toBe($startedAt);

        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
            'start_idempotency_key' => (string) Str::uuid(),
            'started_at' => '2026-10-01 00:00:00',
        ])->assertSessionHasErrors('started_at');
        $this->actingAs($pamo)->get(route('conservation-reports.pamo-drafts.mov', ['regular_pamb', $draft->draft_uuid]))->assertOk();
        $this->actingAs($foreign)->get(route('conservation-reports.pamo-drafts.mov', ['regular_pamb', $draft->draft_uuid]))->assertForbidden();
        $this->actingAs($unapproved)->get(route('conservation-reports.index', 'regular_pamb'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('pending_approval');
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'engp'), [
            'start_idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->actingAs($pamo)->from(route('conservation-reports.index', 'regular_pamb'))
            ->post(route('conservation-reports.pamo-drafts.forward', ['regular_pamb', $draft->draft_uuid]), [
                'lock_version' => $draft->fresh()->lock_version,
            ])->assertSessionHasErrors(['activity_name', 'date_conducted']);
        expect(ConservationReportSubmission::query()->count())->toBe(0)
            ->and(DocumentRoutingEvent::query()->count())->toBe(0)
            ->and($draft->fresh()->status)->toBe('draft');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:40:00', 'Asia/Manila'));
        $this->actingAs($coworker)->from(route('conservation-reports.index', 'regular_pamb'))
            ->put(route('conservation-reports.pamo-drafts.update', ['regular_pamb', $draft->draft_uuid]), [
                'lock_version' => $draft->fresh()->lock_version,
                'activity_name' => 'Regular PAMB',
                'document_type' => 'Minutes',
                'reporting_period' => 'Quarter 4',
                'date_conducted' => '2026-10-01',
            ])->assertRedirect();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:45:00', 'Asia/Manila'));
        $this->actingAs($coworker)->post(route('conservation-reports.pamo-drafts.forward', ['regular_pamb', $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        $submission = ConservationReportSubmission::query()->findOrFail($draft->conservation_report_submission_id);
        $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $submission->getKey())->firstOrFail();
        $initialEvents = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $submission->getKey())->get();
        expect($draft->status)->toBe('forwarded')
            ->and($draft->started_at?->toIso8601String())->toBe($startedAt)
            ->and($snapshot->profile)->toBe('pamo_regular')
            ->and($snapshot->origin_kind)->toBe('pamo_pamb')
            ->and((int) $snapshot->protected_area_id)->toBe((int) $area->getKey())
            ->and((int) $snapshot->pamo_office_id)->toBe((int) $office->getKey())
            ->and((int) $snapshot->originator_user_id)->toBe((int) $pamo->getKey())
            ->and($initialEvents)->toHaveCount(1)
            ->and($initialEvents->first()->from_stage)->toBe(DocumentRoutingProfileRegistry::PAMO_ORIGIN)
            ->and($initialEvents->first()->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL)
            ->and(data_get($initialEvents->first()->metadata, 'action_key'))->toBe('forward_from_pamo_to_cenro_focal');

        // A replay returns the same published record and does not create another report or handoff.
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['regular_pamb', $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
        ])->assertRedirect();
        expect($draft->fresh()->updated_by)->toBe($coworker->getKey())
            ->and($draft->fresh()->originator_user_id)->toBe($pamo->getKey());
        expect(ConservationReportSubmission::query()->count())->toBe(1)
            ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $submission->getKey())->count())->toBe(1);

        $focal = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:50:00', 'Asia/Manila'));
        $receiveFocal = $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'receive_at_cenro_focal']), [
            'stage' => 'receive_at_cenro_focal',
        ]);
        expect($receiveFocal->status())->toBe(302, 'CENRO CDS Focal receipt must be accepted.');
        $receiveFocal->assertSessionHasNoErrors();
        expect(app(DocumentRoutingTransitionService::class)->state($submission->fresh(), 'conservation')['stage'])
            ->toBe(DocumentRoutingProfileRegistry::PREPARATION);

        $this->actingAs($pamo)->put(route('conservation-reports.update', ['regular_pamb', $submission->getKey()]), [
            'protected_area_id' => $area->getKey(), 'activity_name' => 'Regular PAMB',
            'document_type' => 'Minutes', 'reporting_period' => 'Quarter 4', 'date_conducted' => '2026-10-01',
        ])->assertForbidden();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 11:00:00', 'Asia/Manila'));
        $returnToPamo = $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'return_to_pamo_for_correction']), [
            'stage' => 'return_to_pamo_for_correction', 'remarks' => 'Please complete the missing PAMB detail.',
        ]);
        expect($returnToPamo->status())->toBe(302, 'CENRO focal return to the captured PAMO must be accepted.');
        $returnToPamo->assertSessionHasNoErrors();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 11:10:00', 'Asia/Manila'));
        $receiveCorrection = $this->actingAs($pamo)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'receive_correction']), [
            'stage' => 'receive_correction',
        ]);
        expect($receiveCorrection->status())->toBe(302, 'PAMO must be able to receive correction at its captured origin.');
        $receiveCorrection->assertSessionHasNoErrors();

        $this->actingAs($pamo)->get(route('conservation-reports.index', 'regular_pamb'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('submissions.data.0.pamo_correction_ready', true));
        $correctionUpdate = $this->actingAs($pamo)->put(route('conservation-reports.update', ['regular_pamb', $submission->getKey()]), [
            'protected_area_id' => $area->getKey(), 'activity_name' => 'Regular PAMB',
            'document_type' => 'Minutes', 'reporting_period' => 'Quarter 4', 'date_conducted' => '2026-10-01',
            'remarks' => 'Completed after PAMO received correction.',
        ]);
        expect($correctionUpdate->status())->toBe(302, 'PAMO correction edits are allowed after Receive Correction: '.(string) $correctionUpdate->exceptions->last()?->getMessage());
        $correctionUpdate->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 11:40:00', 'Asia/Manila'));
        $resubmit = $this->actingAs($pamo)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'forward_from_pamo_to_cenro_focal']), [
            'stage' => 'forward_from_pamo_to_cenro_focal', 'official_document' => pamoPilotUpload(),
        ]);
        expect($resubmit->status())->toBe(302, 'PAMO must be able to resubmit the corrected report.');
        $resubmit->assertSessionHasNoErrors();

        $tat = app(OfficeTatAnalyticsService::class)->analytics($pamo, [
            'category' => 'PAMO', 'processing_period' => '2026-H2', 'as_of_date' => '2026-10-11',
            'workflow' => 'conservation:regular_pamb', 'protected_area_id' => $area->getKey(),
            'reporting_period' => 'Quarter 4', 'evidence_type' => 'live',
        ]);
        $preparation = collect($tat['pamo_preparation']['rows'])->firstWhere('office_id', $office->getKey());
        expect($preparation['documents_started'])->toBe(1)
            ->and($preparation['completed_documents'])->toBe(1)
            ->and($preparation['elapsed_seconds_total'])->toBe(4500.0)
            ->and(collect($tat['rows'])->firstWhere('office', $office->name)['tat_completed_documents'])->toBe(0);
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('MHRWS PAMO uses its assigned PENRO without a CENRO node and PENRO Records receives first', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'MHRWS', 'PAMO MHRWS', 'penro_davao_oriental');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());

    $facts = app(OrganizationalAccessService::class)->pamoAssignmentForProtectedArea((int) $area->getKey());
    expect($facts['profile'])->toBe('pamo_direct')
        ->and($facts['responsible_cenro_office_id'])->toBeNull()
        ->and($facts['target_office'])->toBe('PENRO Davao Oriental');

    $this->actingAs($pamo)->from(route('conservation-reports.index', 'regular_pamb'))
        ->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
            'start_idempotency_key' => (string) Str::uuid(),
            'activity_name' => 'Regular PAMB',
            'document_type' => 'Minutes',
            'date_conducted' => '2026-10-01',
            'mov' => pamoPilotUpload(),
        ])->assertRedirect();
    $draft = PamoPambDraft::query()->firstOrFail();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['regular_pamb', $draft->draft_uuid]), [
        'lock_version' => $draft->fresh()->lock_version,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $submission = ConservationReportSubmission::query()->findOrFail($draft->fresh()->conservation_report_submission_id);
    $state = app(DocumentRoutingTransitionService::class)->state($submission, 'conservation');
    expect($submission->target_office)->toBe('PENRO Davao Oriental')
        ->and($state['route_profile'])->toBe('direct')
        ->and($state['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(collect($state['actions'])->pluck('key'))->toContain('receive_at_penro_records')
        ->and(collect($state['actions'])->pluck('key'))->not->toContain('receive_at_cenro_focal', 'forward_to_cenro_chief');

    $records = pamoPilotActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    app(DocumentRoutingTransitionService::class)->transition($submission, 'conservation', 'receive_at_penro_records', (int) $records->getKey());
    $received = app(DocumentRoutingTransitionService::class)->state($submission->fresh(), 'conservation');
    expect($received['stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($received['events']->last()->event_key)->toBe('received');
});

test('PAMO preparation averages only accepted start-to-handoff and correction segments, separately from received TAT', function (): void {
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Aliwagwag', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $submission = ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 4', 'date_conducted' => '2026-10-01',
        'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
    ]);
    $draft = PamoPambDraft::query()->create([
        'draft_uuid' => (string) Str::uuid(), 'protected_area_id' => $area->getKey(),
        'pamo_office_id' => $office->getKey(), 'pamo_office_name' => $office->name,
        'originator_user_id' => $pamo->getKey(), 'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
        'workflow_key' => 'regular_pamb', 'report_payload' => ['reporting_period' => 'Quarter 4', 'date_conducted' => '2026-10-01'],
        'started_at' => '2026-10-11 09:00:00', 'status' => 'forwarded',
        'conservation_report_submission_id' => $submission->getKey(), 'forwarded_at' => '2026-10-11 10:00:00',
    ]);
    foreach ([
        ['forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, '2026-10-11 10:00:00', 'forward_from_pamo_to_cenro_focal'],
        ['returned_for_correction', DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::PAMO_ORIGIN, '2026-10-11 11:00:00', 'return_to_pamo_for_correction'],
        ['correction_received', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::PAMO_ORIGIN, '2026-10-11 12:00:00', 'receive_correction'],
        ['forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, '2026-10-11 13:00:00', 'forward_from_pamo_to_cenro_focal'],
    ] as [$key, $from, $to, $at, $action]) {
        DocumentRoutingEvent::query()->create([
            'source_type' => 'conservation', 'source_id' => $submission->getKey(), 'workflow_key' => 'regular_pamb',
            'event_key' => $key, 'from_stage' => $from, 'to_stage' => $to,
            'from_office' => $office->name, 'to_office' => 'CENRO CDS Focal Person',
            'occurred_at' => $at, 'recorded_by' => $pamo->getKey(), 'metadata' => ['action_key' => $action],
        ]);
    }

    $openSubmission = ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 4', 'date_conducted' => '2026-10-02',
        'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
    ]);
    PamoPambDraft::query()->create([
        'draft_uuid' => (string) Str::uuid(), 'protected_area_id' => $area->getKey(),
        'pamo_office_id' => $office->getKey(), 'pamo_office_name' => $office->name,
        'originator_user_id' => $pamo->getKey(), 'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
        'workflow_key' => 'regular_pamb', 'report_payload' => ['reporting_period' => 'Quarter 4'],
        'started_at' => '2026-10-11 09:00:00', 'status' => 'forwarded',
        'conservation_report_submission_id' => $openSubmission->getKey(), 'forwarded_at' => '2026-10-11 10:00:00',
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation', 'source_id' => $openSubmission->getKey(), 'workflow_key' => 'regular_pamb',
        'event_key' => 'forwarded', 'from_stage' => DocumentRoutingProfileRegistry::PAMO_ORIGIN,
        'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL,
        'from_office' => $office->name, 'to_office' => 'CENRO CDS Focal Person',
        'occurred_at' => '2026-10-11 10:00:00', 'recorded_by' => $pamo->getKey(),
        'metadata' => ['action_key' => 'forward_from_pamo_to_cenro_focal'],
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation', 'source_id' => $openSubmission->getKey(), 'workflow_key' => 'regular_pamb',
        'event_key' => 'returned_for_correction', 'from_stage' => DocumentRoutingProfileRegistry::PREPARATION,
        'to_stage' => DocumentRoutingProfileRegistry::PAMO_ORIGIN,
        'from_office' => 'CENRO CDS Focal Person', 'to_office' => $office->name,
        'occurred_at' => '2026-10-11 14:00:00', 'recorded_by' => $pamo->getKey(),
        'metadata' => ['action_key' => 'return_to_pamo_for_correction'],
    ]);

    $data = app(OfficeTatAnalyticsService::class)->analytics($pamo, [
        'category' => 'PAMO', 'processing_period' => '2026-H2', 'as_of_date' => '2026-10-11',
        'workflow' => 'conservation:regular_pamb', 'protected_area_id' => $area->getKey(),
        'reporting_period' => 'Quarter 4', 'evidence_type' => 'live',
    ]);
    $preparation = $data['pamo_preparation'];
    $row = collect($preparation['rows'])->firstWhere('office_id', $office->getKey());
    $receiptRow = collect($data['rows'])->firstWhere('office', $office->name);

    expect($row['documents_started'])->toBe(2)
        ->and($row['completed_documents'])->toBe(1)
        ->and($row['pending_documents'])->toBe(1)
        ->and($row['elapsed_seconds_total'])->toBe(7200.0)
        ->and($row['average_elapsed_seconds'])->toBe(7200.0)
        ->and($preparation['overall']['completed_documents'])->toBe(1)
        ->and($receiptRow['tat_completed_documents'])->toBe(0)
        ->and($receiptRow['tat_average_elapsed_seconds'])->toBeNull()
        ->and($draft->pamo_office_id)->toBe($office->getKey());
});

test('new PAMO direct graph keeps each captured Office and TSD downstream combination', function (): void {
    $resolver = app(EffectiveRoutingGraphResolver::class);
    $cases = [
        [true, true, 'forward_to_office_penro'],
        [false, true, 'dispatch_penro_records_to_tsd'],
        [true, false, 'forward_to_office_penro'],
        [false, false, 'dispatch_penro_records_to_cds_focal'],
    ];

    foreach ($cases as [$officeEnabled, $tsdEnabled, $expected]) {
        $graph = $resolver->resolve('conservation', true, [
            'graph_version' => RoutingPositionSnapshotService::PAMO_GRAPH_VERSION,
            'origin_kind' => 'pamo_pamb', 'profile' => 'pamo_direct',
            'pamo_office_name' => 'PAMO MHRWS', 'office_penro_enabled' => $officeEnabled,
            'penro_tsd_chief_enabled' => $tsdEnabled,
        ]);
        $afterRecordsReceipt = collect($graph['actions'])->filter(fn (array $action): bool => $action['from'] === DocumentRoutingProfileRegistry::PENRO_RECORDS)->pluck('key');
        expect($afterRecordsReceipt)->toContain($expected);
    }
});

test('TWC meeting drafts support coworker completion, validated forwarding, correction receipt and separate preparation analytics', function (): void {
    Storage::fake('local');
    Notification::fake();
    $previousNow = CarbonImmutable::now();

    try {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 09:00:00', 'Asia/Manila'));
        $owner = User::factory()->create();
        [$area, $office] = pamoPilotArea($owner, 'TWC', 'PAMO TWC Pilot', 'cenro_baganga');
        $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
        $coworker = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());

        foreach ([$pamo, $coworker] as $actor) {
            $this->actingAs($actor)->get(route('conservation-reports.index', 'twc_meetings'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('workflow.key', 'twc_meetings')
                    ->where('workflow.label', 'TWC Meetings')
                    ->where('pamoWorkspace', true));
        }

        $token = (string) Str::uuid();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'twc_meetings'), [
            'start_idempotency_key' => $token,
        ])->assertRedirect();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        expect($draft->workflow_key)->toBe('twc_meetings')
            ->and(ConservationReportSubmission::query()->count())->toBe(0)
            ->and(DocumentRoutingEvent::query()->count())->toBe(0);

        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['twc_meetings', $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
        ])->assertSessionHasErrors('mov');
        expect(ConservationReportSubmission::query()->count())->toBe(0)
            ->and($draft->fresh()->status)->toBe('draft');

        $this->actingAs($coworker)->put(route('conservation-reports.pamo-drafts.update', ['twc_meetings', $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version,
            'mov' => pamoPilotUpload(),
        ])->assertRedirect();

        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['twc_meetings', $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version,
        ])->assertSessionHasErrors(['activity_name', 'date_conducted']);
        expect(ConservationReportSubmission::query()->count())->toBe(0)
            ->and($draft->fresh()->status)->toBe('draft');

        $this->actingAs($coworker)->put(route('conservation-reports.pamo-drafts.update', ['twc_meetings', $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version,
            'activity_name' => 'TWC Meeting',
            'document_type' => 'Minutes',
            'reporting_period' => 'Quarter 4',
            'date_conducted' => '2026-10-01',
        ])->assertRedirect();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 09:30:00', 'Asia/Manila'));
        $this->actingAs($coworker)->post(route('conservation-reports.pamo-drafts.forward', ['twc_meetings', $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        $submission = ConservationReportSubmission::query()->findOrFail($draft->conservation_report_submission_id);
        $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $submission->getKey())->firstOrFail();
        $initialEvent = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $submission->getKey())->firstOrFail();
        expect($submission->workflow_key)->toBe('twc_meetings')
            ->and($submission->activity_name)->toBe('TWC Meeting')
            ->and($submission->document_type)->toBe('Minutes')
            ->and($snapshot->profile)->toBe('pamo_regular')
            ->and($snapshot->origin_kind)->toBe('pamo_pamb')
            ->and($submission->target_office)->toBe('CENRO Baganga')
            ->and($initialEvent->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL)
            ->and(data_get($initialEvent->metadata, 'action_key'))->toBe('forward_from_pamo_to_cenro_focal');

        $focal = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:00:00', 'Asia/Manila'));
        $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'receive_at_cenro_focal']), [
            'stage' => 'receive_at_cenro_focal',
        ])->assertRedirect()->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:10:00', 'Asia/Manila'));
        $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'return_to_pamo_for_correction']), [
            'stage' => 'return_to_pamo_for_correction', 'remarks' => 'Please complete the TWC record.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:20:00', 'Asia/Manila'));
        $this->actingAs($pamo)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'receive_correction']), [
            'stage' => 'receive_correction',
        ])->assertRedirect()->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:30:00', 'Asia/Manila'));
        $this->actingAs($pamo)->put(route('conservation-reports.update', ['twc_meetings', $submission->getKey()]), [
            'protected_area_id' => $area->getKey(),
            'activity_name' => 'TWC Meeting',
            'document_type' => 'Minutes',
            'reporting_period' => 'Quarter 4',
            'date_conducted' => '2026-10-01',
            'remarks' => 'Completed after PAMO received correction.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:50:00', 'Asia/Manila'));
        $this->actingAs($pamo)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'forward_from_pamo_to_cenro_focal']), [
            'stage' => 'forward_from_pamo_to_cenro_focal', 'official_document' => pamoPilotUpload(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $analytics = app(OfficeTatAnalyticsService::class)->analytics($pamo, [
            'category' => 'PAMO', 'processing_period' => '2026-H2', 'as_of_date' => '2026-10-11',
            'workflow' => 'conservation:twc_meetings', 'protected_area_id' => $area->getKey(),
            'reporting_period' => 'Quarter 4', 'evidence_type' => 'live',
        ]);
        $preparation = collect($analytics['pamo_preparation']['rows'])->firstWhere('office_id', $office->getKey());
        $receipt = collect($analytics['rows'])->firstWhere('office', $office->name);
        expect($preparation['documents_started'])->toBe(1)
            ->and($preparation['completed_documents'])->toBe(1)
            ->and($preparation['elapsed_seconds_total'])->toBe(3600.0)
            ->and($receipt['tat_completed_documents'])->toBe(0)
            ->and(ConservationReportSubmission::query()->count())->toBe(1);
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('TWC MHRWS origin goes directly to assigned PENRO Records and requires its receipt', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'MHRWS', 'PAMO MHRWS TWC', 'penro_davao_oriental');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());

    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'twc_meetings'), [
        'start_idempotency_key' => (string) Str::uuid(),
        'activity_name' => 'TWC Meeting',
        'document_type' => 'Report',
        'reporting_period' => 'Quarter 4',
        'date_conducted' => '2026-10-01',
        'mov' => pamoPilotUpload(),
    ])->assertRedirect();
    $draft = PamoPambDraft::query()->firstOrFail();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['twc_meetings', $draft->draft_uuid]), [
        'lock_version' => $draft->fresh()->lock_version,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $submission = ConservationReportSubmission::query()->findOrFail($draft->fresh()->conservation_report_submission_id);
    $state = app(DocumentRoutingTransitionService::class)->state($submission, 'conservation');
    expect($state['route_profile'])->toBe('direct')
        ->and($submission->target_office)->toBe('PENRO Davao Oriental')
        ->and($state['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(collect($state['actions'])->pluck('key'))->toContain('receive_at_penro_records')
        ->and(collect($state['actions'])->pluck('key'))->not->toContain('receive_at_cenro_focal');

    $records = pamoPilotActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'receive_at_penro_records']), [
        'stage' => 'receive_at_penro_records',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(DocumentRoutingTransitionService::class)->state($submission->fresh(), 'conservation')['stage'])
        ->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS);
});
