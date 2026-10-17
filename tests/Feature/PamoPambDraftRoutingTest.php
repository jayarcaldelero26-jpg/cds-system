<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentArchive;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\FinalDocumentArchiveService;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\OfficeTatAnalyticsService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\EffectiveRoutingGraphResolver;
use App\Services\SubmissionTracking\RoutingPositionSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
});

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

test('catalog-backed non-PAMB reports keep PA scope, source validation and first handoff', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Catalog APL', 'cenro_baganga');
    [$foreignArea, $foreignOffice] = pamoPilotArea($owner, 'OTHER', 'PAMO Catalog Other', 'cenro_mati');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $foreign = pamoPilotActor(OrganizationalAccessService::PAMO, $foreignOffice->name, (int) $foreignArea->getKey());

    foreach ([
        ['maintenance_monuments', ['activity_name' => 'Maintenance of Monuments', 'document_type' => 'Progress Report', 'date_conducted' => '2026-10-01', 'date_accomplished' => '2026-10-02']],
        ['homestay', ['activity_name' => 'Training on Homestay Program', 'document_type' => 'Final Report', 'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']], 'date_accomplished' => '2026-10-03']],
    ] as [$workflow, $payload]) {
        $this->actingAs($pamo)->get(route('conservation-reports.index', $workflow))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('auth.canViewPamoReports', true)
                ->where('pamoWorkspace', true)
                ->where('workflow.key', $workflow)
                ->where('protectedAreas', fn ($areas) => count($areas) === 1 && (int) $areas[0]['id'] === (int) $area->getKey())
                ->where('genericModuleNavigation', fn ($links) => count($links) === 22 && collect($links)->contains(fn (array $link) => $link['href'] === route('conservation-reports.index', $workflow, false))
                    && collect($links)->doesntContain(fn (array $link) => str_starts_with($link['href'], '/bms') || str_starts_with($link['href'], '/aws'))));
        $token = (string) Str::uuid();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
            'start_idempotency_key' => $token, 'mov' => pamoPilotUpload(),
        ])->assertRedirect();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        $this->actingAs($foreign)->get(route('conservation-reports.pamo-drafts.mov', [$workflow, $draft->draft_uuid]))->assertForbidden();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), ['lock_version' => $draft->fresh()->lock_version])->assertSessionHasErrors('activity_name');
        expect($draft->fresh()->status)->toBe('draft');
        $this->actingAs($pamo)->put(route('conservation-reports.pamo-drafts.update', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version, ...$payload,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), ['lock_version' => $draft->fresh()->lock_version])->assertRedirect()->assertSessionHasNoErrors();
        $source = ConservationReportSubmission::query()->findOrFail($draft->fresh()->conservation_report_submission_id);
        $event = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->firstOrFail();
        expect($source->workflow_key)->toBe($workflow)
            ->and((int) $source->protected_area_id)->toBe((int) $area->getKey())
            ->and($source->target_office)->toBe('CENRO Baganga')
            ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL);
        $this->actingAs($pamo)->get(route('conservation-reports.index', $workflow))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('pamoDrafts.0.source_url', route('submission-tracking.index', ['view' => 'outgoing', 'source' => 'conservation', 'source_id' => $source->getKey()]))
                ->where('pamoDrafts.0.source_mov_url', route('conservation-reports.mov', [$workflow, $source->getKey()])));
        $this->actingAs($foreign)->get(route('conservation-reports.pamo-drafts.mov', [$workflow, $draft->draft_uuid]))->assertForbidden();
        $this->actingAs($pamo)->get(route('conservation-reports.mov', [$workflow, $source->getKey()]))->assertOk();
        $this->actingAs($foreign)->get(route('conservation-reports.mov', [$workflow, $source->getKey()]))->assertForbidden();
        $wrongWorkflow = $workflow === 'homestay' ? 'maintenance_monuments' : 'homestay';
        $this->actingAs($pamo)->get(route('conservation-reports.mov', [$wrongWorkflow, $source->getKey()]))->assertNotFound();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$wrongWorkflow, $draft->draft_uuid]), ['lock_version' => $draft->lock_version])->assertNotFound();

        if ($workflow === 'maintenance_monuments') {
            $focal = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
            $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $source->getKey(), 'receive_at_cenro_focal']), ['stage' => 'receive_at_cenro_focal'])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $source->getKey(), 'return_to_pamo_for_correction']), ['stage' => 'return_to_pamo_for_correction', 'remarks' => 'Complete the report.'])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($pamo)->post(route('submission-tracking.transition', ['conservation', $source->getKey(), 'receive_correction']), ['stage' => 'receive_correction'])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($pamo)->get(route('conservation-reports.index', $workflow))
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('pamoDrafts.0.source_url', route('submission-tracking.index', ['view' => 'incoming', 'source' => 'conservation', 'source_id' => $source->getKey()])));
            $this->actingAs($pamo)->put(route('conservation-reports.update', [$workflow, $source->getKey()]), [
                'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Baganga', ...$payload,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($pamo)->post(route('submission-tracking.transition', ['conservation', $source->getKey(), 'forward_from_pamo_to_cenro_focal']), [
                'stage' => 'forward_from_pamo_to_cenro_focal', 'official_document' => pamoPilotUpload(),
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
    }

    $eligible = app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class)->pamoSubmissionKeys();
    expect($eligible)->toHaveCount(22)
        ->and($eligible)->toContain('regular_pamb', 'maintenance_monuments', 'homestay', 'water_quality_monitoring')
        ->and($eligible)->not->toContain('bms', 'bams', 'imea', 'automated_weather_station', 'engp');
    foreach ($eligible as $key) {
        $this->actingAs($pamo)->get(route('conservation-reports.index', $key))->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflow.key', $key));
    }
});

test('the Reports workspace exposes the active PA catalog and only the actor PA drafts', function (): void {
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Workspace APL', 'cenro_baganga');
    [$foreignArea, $foreignOffice] = pamoPilotArea($owner, 'OTHER', 'PAMO Workspace Other', 'cenro_mati');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $foreign = pamoPilotActor(OrganizationalAccessService::PAMO, $foreignOffice->name, (int) $foreignArea->getKey());
    $facts = app(OrganizationalAccessService::class)->pamoAssignmentForProtectedArea((int) $area->getKey());
    expect(app(OrganizationalAccessService::class)->effectiveCategory($pamo))->toBe(OrganizationalAccessService::PAMO)
        ->and(app(OrganizationalAccessService::class)->canViewPamoPilotWorkflow($pamo))->toBeTrue()
        ->and(app(OrganizationalAccessService::class)->canAccessProtectedArea($pamo, (int) $area->getKey()))->toBeTrue()
        ->and(app(OrganizationalAccessService::class)->canAccessUnit($pamo, OrganizationalAccessService::CONSERVATION))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($pamo)->allows('pamo-reports.view'))->toBeTrue();
    $draftUuid = (string) Str::uuid();
    PamoPambDraft::query()->create([
        'draft_uuid' => $draftUuid,
        'start_idempotency_key' => (string) Str::uuid(),
        'protected_area_id' => $area->getKey(),
        'pamo_office_id' => $office->getKey(),
        'pamo_office_name' => $office->name,
        'originator_user_id' => $pamo->getKey(),
        'created_by' => $pamo->getKey(),
        'updated_by' => $pamo->getKey(),
        'workflow_key' => 'regular_pamb',
        'report_payload' => ['activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1'],
        'started_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'Asia/Manila'),
        'status' => 'draft',
        'lock_version' => 1,
    ]);

    $this->withoutVite()->actingAs($pamo)->get(route('pamo-reports.index', ['tab' => 'drafts']))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('PamoReports/Index')
            ->where('assignedProtectedArea.id', (int) $area->getKey())
            ->where('pamoContext.pamo_office', $facts['pamo_office_name'])
            ->where('filters.workflow', '')
            ->where('filters.search', '')
            ->where('rows.total', 1)
            ->where('rows.data.0.id', $draftUuid)
            ->where('rows.data.0.workflow_label', 'Regular PAMB Meetings')
            ->where('workflows', fn ($workflows) => collect($workflows)->contains(fn (array $workflow) => $workflow['key'] === 'regular_pamb')
                && collect($workflows)->contains(fn (array $workflow) => $workflow['key'] === 'automated_weather_station')
                && collect($workflows)->doesntContain(fn (array $workflow) => $workflow['key'] === 'engp')));

    $this->actingAs($pamo)->get(route('pamo-reports.index', ['tab' => 'submitted', 'search' => 'Quarter 1']))
        ->assertRedirect(route('submission-tracking.index', ['view' => 'outgoing', 'search' => 'Quarter 1']));

    $this->actingAs($pamo)->get(route('pamo-reports.index', ['workflow' => 'regular_pamb']))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.workflow', 'regular_pamb')
            ->where('rows.total', 1)
            ->where('rows.data.0.workflow_key', 'regular_pamb'));

    $this->actingAs($foreign)->get(route('pamo-reports.index'))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('assignedProtectedArea.id', (int) $foreignArea->getKey())
            ->where('rows.total', 0)
            ->where('rows.data', []));
    $this->withoutVite()->actingAs($pamo)->get(route('pamo-archives.index'))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('PamoReports/Archives')
            ->where('assignedProtectedArea.id', (int) $area->getKey())
            ->where('archives.total', 0));
    $pamo->forceFill(['is_active' => false])->save();
    $this->actingAs($pamo)->get(route('pamo-reports.index'))->assertRedirect(route('login'));
});

test('PAMO activity titles remain free text from draft save through restoration report promotion', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Free Title APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    [$foreignArea, $foreignOffice] = pamoPilotArea($owner, 'OTHER', 'PAMO Free Title Other', 'cenro_mati');
    $foreign = pamoPilotActor(OrganizationalAccessService::PAMO, $foreignOffice->name, (int) $foreignArea->getKey());
    $token = (string) Str::uuid();
    $workflow = 'restoration_plan_5_year';
    $activity = 'Restoration Plan Workshop';

    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
        'start_idempotency_key' => $token,
        'activity_name' => $activity,
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 3',
        'date_accomplished' => '2026-10-02',
        'mov' => pamoPilotUpload(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
    $initialLockVersion = (int) $draft->lock_version;
    expect(data_get($draft->report_payload, 'activity_name'))->toBe($activity)
        ->and($draft->status)->toBe('draft');

    $forwardRoute = route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]);
    $forwardBase = ['lock_version' => $draft->lock_version];
    $this->actingAs($foreign)->post($forwardRoute, $forwardBase + ['activity_name' => $activity])->assertForbidden();
    $this->actingAs($pamo)->post($forwardRoute, $forwardBase + ['activity_name' => ''])
        ->assertRedirect()->assertSessionHasErrors('activity_name');
    $this->actingAs($pamo)->post($forwardRoute, $forwardBase + ['activity_name' => ['invalid' => 'array']])
        ->assertRedirect()->assertSessionHasErrors('activity_name');
    $this->actingAs($pamo)->post($forwardRoute, $forwardBase + ['activity_name' => str_repeat('x', 256)])
        ->assertRedirect()->assertSessionHasErrors('activity_name');

    expect(ConservationReportSubmission::query()->count())->toBe(0)
        ->and($draft->fresh()->status)->toBe('draft')
        ->and((int) $draft->fresh()->lock_version)->toBe($initialLockVersion);

    $this->actingAs($pamo)->post($forwardRoute, [
        ...$forwardBase,
        'activity_name' => $activity,
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 3',
        'date_accomplished' => '2026-10-02',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $draft->refresh();
    $source = ConservationReportSubmission::query()->findOrFail($draft->conservation_report_submission_id);
    $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $source->getKey())->firstOrFail();
    $event = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->firstOrFail();

    expect($draft->status)->toBe('forwarded')
        ->and($draft->workflow_key)->toBe($workflow)
        ->and($draft->report_payload['activity_name'])->toBe($activity)
        ->and($source->workflow_key)->toBe($workflow)
        ->and($source->activity_name)->toBe($activity)
        ->and($source->document_type)->toBe('Progress Report')
        ->and((int) $source->protected_area_id)->toBe((int) $area->getKey())
        ->and($source->target_office)->toBe('CENRO Baganga')
        ->and(Storage::disk('local')->exists($source->mov_file_path))->toBeTrue()
        ->and($snapshot->graph_version)->toBe(RoutingPositionSnapshotService::PAMO_GRAPH_VERSION)
        ->and($snapshot->profile)->toBe('pamo_regular')
        ->and($snapshot->origin_kind)->toBe('pamo_pamb')
        ->and((int) $snapshot->protected_area_id)->toBe((int) $area->getKey())
        ->and((int) $snapshot->pamo_office_id)->toBe((int) $office->getKey())
        ->and((int) $snapshot->originator_user_id)->toBe((int) $pamo->getKey())
        ->and($event->event_key)->toBe('forwarded')
        ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL)
        ->and(ConservationReportSubmission::query()->count())->toBe(1)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->count())->toBe(1);

    $this->actingAs($pamo)->post($forwardRoute, ['lock_version' => $draft->lock_version, 'activity_name' => $activity])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(ConservationReportSubmission::query()->count())->toBe(1)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->count())->toBe(1);

    $unicodeToken = (string) Str::uuid();
    $unicodeTitle = 'Restoration Plan Workshop – Davao Oriental';
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
        'start_idempotency_key' => $unicodeToken,
        'activity_name' => $unicodeTitle,
        'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 4',
        'date_accomplished' => '2026-10-03',
        'mov' => pamoPilotUpload(),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $unicodeDraft = PamoPambDraft::query()->where('start_idempotency_key', $unicodeToken)->firstOrFail();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $unicodeDraft->draft_uuid]), [
        'lock_version' => $unicodeDraft->lock_version,
        'activity_name' => $unicodeTitle,
        'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 4',
        'date_accomplished' => '2026-10-03',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $unicodeSource = ConservationReportSubmission::query()->findOrFail($unicodeDraft->fresh()->conservation_report_submission_id);
    expect($unicodeSource->workflow_key)->toBe($workflow)
        ->and($unicodeSource->activity_name)->toBe($unicodeTitle)
        ->and($unicodeSource->document_type)->toBe('Final Report')
        ->and(ConservationReportSubmission::query()->count())->toBe(2);
});

test('Forward validates current Homestay modal dates and promotes once without a separate draft save', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Forward Dates APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $token = (string) Str::uuid();

    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'homestay'), [
        'start_idempotency_key' => $token,
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 3',
        'mov' => pamoPilotUpload(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
    $initialLockVersion = (int) $draft->lock_version;
    $startedAt = $draft->started_at?->toISOString();
    $draftPath = $draft->mov_path;
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['homestay', $draft->draft_uuid]), [
        'lock_version' => $draft->lock_version,
    ])->assertSessionHasErrors(['date_conducted_ranges', 'date_accomplished']);
    expect(ConservationReportSubmission::query()->count())->toBe(0)
        ->and(DocumentRoutingEvent::query()->count())->toBe(0)
        ->and($draft->fresh()->status)->toBe('draft')
        ->and((int) $draft->fresh()->lock_version)->toBe($initialLockVersion)
        ->and($draft->fresh()->started_at?->toISOString())->toBe($startedAt)
        ->and(Storage::disk('local')->exists($draftPath))->toBeTrue();

    $currentFormState = [
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 3',
        'date_conducted_ranges' => [['from' => '2026-09-21', 'to' => '2026-09-25']],
        'date_accomplished' => '2026-10-13',
        'remarks' => 'Current modal values submitted with Forward.',
    ];
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['homestay', $draft->draft_uuid]), [
        'lock_version' => $draft->lock_version,
        ...$currentFormState,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $draft->refresh();
    $source = ConservationReportSubmission::query()->findOrFail($draft->conservation_report_submission_id);
    $event = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->firstOrFail();
    expect($draft->status)->toBe('forwarded')
        ->and((int) $draft->lock_version)->toBe($initialLockVersion + 1)
        ->and($draft->started_at?->toISOString())->toBe($startedAt)
        ->and($draft->report_payload['date_conducted_ranges'])->toBe($currentFormState['date_conducted_ranges'])
        ->and($draft->report_payload['date_accomplished'])->toBe('2026-10-13')
        ->and($source->activity_name)->toBe('Training on Homestay Program')
        ->and($source->reporting_period)->toBe('Quarter 3')
        ->and($source->date_conducted_ranges)->toBe($currentFormState['date_conducted_ranges'])
        ->and($source->date_accomplished?->format('Y-m-d'))->toBe('2026-10-13')
        ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->count())->toBe(1)
        ->and(ConservationReportSubmission::query()->count())->toBe(1);

    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['homestay', $draft->draft_uuid]), [
        'lock_version' => $draft->lock_version,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(ConservationReportSubmission::query()->count())->toBe(1)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->count())->toBe(1);
});

test('non-PAMB MHRWS draft routes directly to PENRO Records and inactive definitions stay closed', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'MHRWS', 'PAMO Catalog MHRWS', 'penro_davao_oriental');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());

    \App\Models\ModuleDefinition::query()->where('code', 'water_quality_monitoring')->update(['is_active' => false]);
    $this->actingAs($pamo)->get(route('conservation-reports.index', 'water_quality_monitoring'))->assertForbidden();
    $this->actingAs($pamo)->get(route('conservation-reports.index', 'maintenance_monuments'))
        ->assertOk()->assertInertia(fn ($page) => $page->where('genericModuleNavigation', fn ($links) => count($links) === 21
            && collect($links)->doesntContain(fn (array $link) => str_ends_with($link['href'], '/water_quality_monitoring'))));
    \App\Models\ModuleDefinition::query()->where('implementation_type', 'generic')->update(['is_active' => false]);
    $this->actingAs($pamo)->get(route('dashboard'))
        ->assertOk()->assertInertia(fn ($page) => $page->where('genericModuleNavigation', fn ($links) => count($links) === 0));
    \App\Models\ModuleDefinition::query()->where('code', 'maintenance_monuments')->update(['is_active' => true]);

    $workflow = 'maintenance_monuments';
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
        'start_idempotency_key' => (string) Str::uuid(),
        'activity_name' => 'Maintenance of Monuments',
        'document_type' => 'Final Report',
        'date_accomplished' => '2026-10-02',
        'mov' => pamoPilotUpload(),
    ])->assertRedirect();
    $draft = PamoPambDraft::query()->firstOrFail();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), ['lock_version' => $draft->fresh()->lock_version])->assertRedirect()->assertSessionHasNoErrors();
    $source = ConservationReportSubmission::query()->findOrFail($draft->fresh()->conservation_report_submission_id);
    $event = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->firstOrFail();
    expect($source->target_office)->toBe('PENRO Davao Oriental')
        ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->count())->toBe(1);
});

test('native source-link migration prevents draft workflow and PA reassignment', function (): void {
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Link Guard APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $draft = PamoPambDraft::query()->create([
        'draft_uuid' => (string) Str::uuid(), 'protected_area_id' => $area->getKey(),
        'pamo_office_id' => $office->getKey(), 'pamo_office_name' => $office->name,
        'originator_user_id' => $pamo->getKey(), 'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
        'workflow_key' => 'bms', 'report_payload' => [], 'started_at' => now(), 'status' => 'draft',
    ]);

    expect(fn () => DB::table('pamo_pamb_drafts')->where('id', $draft->getKey())->update(['workflow_key' => 'bams']))
        ->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('pamo_pamb_drafts')->where('id', $draft->getKey())->update(['protected_area_id' => $area->getKey() + 1000]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('native source-link migration rejects references to nonexistent native report rows', function (): void {
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Native FK APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());

    expect(fn () => DB::table('pamo_pamb_drafts')->insert([
        'draft_uuid' => (string) Str::uuid(), 'start_idempotency_key' => (string) Str::uuid(),
        'protected_area_id' => $area->getKey(), 'pamo_office_id' => $office->getKey(), 'pamo_office_name' => $office->name,
        'originator_user_id' => $pamo->getKey(), 'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
        'workflow_key' => 'bms', 'report_payload' => json_encode([]), 'started_at' => now(), 'status' => 'forwarded',
        'bms_report_submission_id' => 2147483647, 'forwarded_at' => now(), 'lock_version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('native module lists are bound to the authenticated PAMO PA and omit finance administration', function (): void {
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Native List APL', 'cenro_baganga');
    [$foreignArea, $foreignOffice] = pamoPilotArea($owner, 'MPL', 'PAMO Native List MPL', 'cenro_mati');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    pamoPilotActor(OrganizationalAccessService::PAMO, $foreignOffice->name, (int) $foreignArea->getKey());

    $this->actingAs($pamo)->get(route('pamo-native-reports.index', ['bms', 'protected_area_id' => $foreignArea->getKey()]))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('PamoNativeReports/Index')
            ->where('workflow.key', 'bms')
            ->where('assignedProtectedArea.id', $area->getKey())
            ->where('reports.data', [])
            ->where('pamoDrafts', [])
            ->where('genericModuleNavigation', fn ($links) => count($links) === 22)
            ->where('pamoNativeModuleNavigation', fn ($links) => count($links) === 7
                && collect($links)->contains(fn (array $link) => $link['href'] === route('pamo-native-reports.index', 'bms', false)))
            ->missing('revenueTargets')
            ->missing('provincialAccountingRows')
            ->missing('bankBalances'));

    $expectedGenericPaths = collect(app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class)->pamoSubmissionKeys())
        ->map(fn (string $key) => route('conservation-reports.index', $key, false))->all();
    $nativeDefinitions = \App\Models\ModuleDefinition::query()->active()->notRetired()
        ->where('implementation_type', \App\Models\ModuleDefinition::IMPLEMENTATION_SPECIALIZED)
        ->where('requirement_domain', 'pa')->whereIn('code', array_keys(app(\App\Services\Pamo\PamoNativeSourceRegistry::class)->all()))
        ->orderByRaw('display_order IS NULL')->orderBy('display_order')->orderBy('name')->get(['name', 'code']);
    $expectedNativePaths = $nativeDefinitions->map(fn ($definition) => route('pamo-native-reports.index', $definition->code, false))->all();
    $expectedNativeLabels = $nativeDefinitions->pluck('name')->all();
    $awsPage = $this->actingAs($pamo)->get(route('pamo-native-reports.index', 'automated_weather_station'));
    $awsPage->assertOk()->assertInertia(fn ($page) => $page
        ->where('workflow.form', 'aws_submission')
        ->where('workflow.default_activity', 'Monitoring and Maintenance of AWS')
        ->where('workflow.period_field', 'quarter')
        ->where('workflow.period_options.0.label', 'Quarter 1')
        ->where('workflow.coverage_period', true)
        ->where('pamoContext.protected_area', $area->name)
        ->where('pamoContext.target_office', 'CENRO Baganga')
        ->where('pamoNativeModuleNavigation', fn ($links) => count($links) === 7
            && collect($links)->pluck('href')->all() === $expectedNativePaths
            && collect($links)->pluck('label')->all() === $expectedNativeLabels
            && collect($links)->every(fn (array $link) => str_contains($link['href'], '/pamo-native-reports/')))
        ->where('genericModuleNavigation', fn ($links) => count($links) === 22
            && collect($links)->pluck('href')->all() === $expectedGenericPaths
            && collect($links)->every(fn (array $link) => str_contains($link['href'], '/conservation-reports/'))));
    $this->actingAs($pamo)->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('genericModuleNavigation', fn ($links) => count($links) === 22 && collect($links)->pluck('href')->all() === $expectedGenericPaths)
        ->where('pamoNativeModuleNavigation', fn ($links) => count($links) === 7
            && collect($links)->pluck('href')->all() === $expectedNativePaths
            && collect($links)->pluck('label')->all() === $expectedNativeLabels));
    foreach ($nativeDefinitions->pluck('code') as $code) {
        $this->actingAs($pamo)->get(route('pamo-native-reports.index', $code))->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflow.key', $code));
    }

    $pending = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $pending->update(['is_approved' => false]);
    $this->actingAs($pending)->get(route('pamo-native-reports.index', 'bms'))->assertRedirect(route('login'));
    $inactive = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $inactive->update(['is_active' => false]);
    $this->actingAs($inactive)->get(route('pamo-native-reports.index', 'bms'))->assertRedirect(route('login'));

    \App\Models\ModuleDefinition::query()->where('code', 'bms')->firstOrFail()->update(['is_active' => false]);
    $this->actingAs($pamo)->get(route('pamo-native-reports.index', 'bms'))->assertForbidden();
});

test('empty generic and native PAMO drafts save without content or attachments and reopen for partial edits', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Empty Draft APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());

    $workflows = [
        'homestay' => false,
        'regular_pamb' => false,
        'bms' => true,
        'bams' => true,
        'imea' => true,
        'imea_facility_maintenance' => true,
        'automated_weather_station' => true,
        'ipaf_management' => true,
        'revenue_collection' => true,
    ];

    foreach ($workflows as $workflow => $native) {
        $token = (string) Str::uuid();
        $payload = ['start_idempotency_key' => $token];
        if (in_array($workflow, ['bms', 'bams', 'imea'], true)) $payload['date_conducted_ranges'] = [];
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        $initialContent = collect($draft->report_payload ?? [])->except('date_conducted_ranges')
            ->filter(fn ($value) => is_array($value) ? $value !== [] : filled($value))->all();
        expect($draft->status)->toBe('draft')
            ->and($draft->getAttribute('protected_area_id'))->toBe($area->getKey())
            ->and($initialContent)->toBe([])
            ->and($draft->getAttribute('mov_path'))->toBeNull();

        $this->actingAs($pamo)->put(route('conservation-reports.pamo-drafts.update', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
            'remarks' => 'Saved as a partial private draft.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft->refresh();
        expect($draft->status)->toBe('draft')
            ->and($draft->report_payload['remarks'])->toBe('Saved as a partial private draft.')
            ->and($draft->getAttribute('mov_path'))->toBeNull();

        if ($native) {
            $this->actingAs($pamo)->get(route('pamo-native-reports.index', $workflow))
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('workflow.key', $workflow)
                    ->where('pamoDrafts.0.id', $draft->draft_uuid)
                    ->where('pamoDrafts.0.report_payload.remarks', 'Saved as a partial private draft.'));
        } else {
            $this->actingAs($pamo)->get(route('conservation-reports.index', $workflow))
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('workflow.key', $workflow)
                    ->where('pamoDrafts.0.id', $draft->draft_uuid)
                    ->where('pamoDrafts.0.report_payload.remarks', 'Saved as a partial private draft.'));
        }
    }

    foreach (['bms_report_submissions', 'bams_report_submissions', 'imea_report_submissions', 'imea_facility_maintenance_reports', 'aws', 'ipaf_management_reports', 'ipaf_revenue_collections', 'conservation_report_submissions'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(DocumentRoutingEvent::query()->count())->toBe(0)
        ->and(\App\Models\DocumentArchive::query()->count())->toBe(0)
        ->and(PamoPambDraft::query()->count())->toBe(count($workflows));
    expect(Storage::disk('local')->allFiles('pamo-pamb-drafts'))->toBe([]);
    Notification::assertNothingSent();
});

test('all seven native families save private partial drafts and reject incomplete Forward without source side effects', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Native Draft APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $coworker = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());

    $forgedToken = (string) Str::uuid();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'bms'), [
        'start_idempotency_key' => $forgedToken, 'protected_area_id' => $area->getKey() + 999, 'mov' => pamoPilotUpload(),
    ])->assertSessionHasErrors('protected_area_id');
    expect(PamoPambDraft::query()->where('start_idempotency_key', $forgedToken)->exists())->toBeFalse();

    $families = [
        'bms' => ['bms_report_submissions', 'activity_name'],
        'bams' => ['bams_report_submissions', 'date_conducted_ranges.0.from'],
        'imea' => ['imea_report_submissions', 'date_conducted_ranges.0.from'],
        'imea_facility_maintenance' => ['imea_facility_maintenance_reports', 'quarter'],
        'automated_weather_station' => ['aws', 'document_type'],
        'ipaf_management' => ['ipaf_management_reports', 'date_conducted'],
        'revenue_collection' => ['ipaf_revenue_collections', 'reporting_month'],
    ];

    foreach ($families as $workflow => [$table, $requiredField]) {
        $token = (string) Str::uuid();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
            'start_idempotency_key' => $token,
            ...(in_array($workflow, ['bms', 'bams', 'imea'], true) ? ['date_conducted_ranges' => [['from' => '', 'to' => '']]] : []),
            'mov' => pamoPilotUpload(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        if ($workflow === 'automated_weather_station') {
            $this->actingAs($pamo)->get(route('pamo-native-reports.index', $workflow))->assertOk()->assertInertia(fn ($page) => $page
                ->where('pamoDrafts.0.report_payload', fn ($payload) => empty(data_get($payload, 'monitoring_period_start'))
                    && empty(data_get($payload, 'monitoring_period_end'))
                    && empty(data_get($payload, 'activity_name'))
                    && ! collect($payload)->has('status')
                    && ! collect($payload)->has('station_name')));
        }
        $this->actingAs($coworker)->put(route('conservation-reports.pamo-drafts.update', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version, 'remarks' => 'Saved by same-office coworker',
            ...($workflow === 'automated_weather_station' ? ['quarter' => 4, 'monitoring_period_start' => '2026-10-01', 'monitoring_period_end' => '2026-10-02'] : []),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft->refresh();
        expect($draft->status)->toBe('draft')
            ->and($draft->getAttribute('protected_area_id'))->toBe($area->getKey())
            ->and($draft->report_payload)->not->toBeEmpty()
            ->and($draft->getAttribute('mov_path'))->not->toBeNull();
        if ($workflow === 'automated_weather_station') {
            expect($draft->report_payload['quarter'])->toBe(4)
                ->and($draft->report_payload['monitoring_period_start'])->toBe('2026-10-01')
                ->and($draft->report_payload['monitoring_period_end'])->toBe('2026-10-02');
            $this->actingAs($pamo)->get(route('pamo-native-reports.index', $workflow))->assertOk()->assertInertia(fn ($page) => $page
                ->where('pamoDrafts.0.report_payload.quarter', 4)
                ->where('pamoDrafts.0.report_payload.monitoring_period_start', '2026-10-01')
                ->where('pamoDrafts.0.report_payload.monitoring_period_end', '2026-10-02'));
        }

        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
        ])->assertSessionHasErrors($requiredField);

        expect(DB::table($table)->count())->toBe(0)
            ->and(DocumentRoutingEvent::query()->count())->toBe(0)
            ->and($draft->fresh()->status)->toBe('draft');
    }
});

test('all seven native adapters promote into their own source rows with protected MOV and the mapped first handoff', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Native Promote APL', 'cenro_baganga');
    [$foreignArea, $foreignOffice] = pamoPilotArea($owner, 'MPL', 'PAMO Native Promote MPL', 'cenro_mati');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $coworker = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $foreign = pamoPilotActor(OrganizationalAccessService::PAMO, $foreignOffice->name, (int) $foreignArea->getKey());
    $sourceColumns = [
        'bms' => ['bms_report_submissions', 'bms_report_submission_id', 'bms'],
        'bams' => ['bams_report_submissions', 'bams_report_submission_id', 'bams'],
        'imea' => ['imea_report_submissions', 'imea_report_submission_id', 'imea'],
        'imea_facility_maintenance' => ['imea_facility_maintenance_reports', 'imea_facility_maintenance_report_id', 'imea-maintenance'],
        'automated_weather_station' => ['aws', 'aws_report_id', 'aws'],
        'ipaf_management' => ['ipaf_management_reports', 'ipaf_management_report_id', 'ipaf-management'],
        'revenue_collection' => ['ipaf_revenue_collections', 'ipaf_revenue_collection_id', 'revenue'],
    ];
    $base = [
        'activity_name' => 'PAMO native pilot report', 'document_type' => 'Final Report',
        'semester' => '2nd Semester', 'date_conducted' => '2026-10-01',
        'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']],
        'date_accomplished' => '2026-10-03', 'remarks' => 'Native pilot fixture',
    ];
    $payloads = [
        'bms' => [...$base, 'activity_name' => 'Conduct 1st Semester Biodiversity Monitoring System'],
        'bams' => $base,
        'imea' => $base,
        'imea_facility_maintenance' => [...$base, 'quarter' => 'Quarter 4'],
        'automated_weather_station' => [
            'activity_name' => 'Monitoring and Maintenance of AWS', 'document_type' => 'Final Report',
            'quarter' => 4, 'date_conducted' => '2026-10-01',
            'monitoring_period_start' => '2026-10-01', 'monitoring_period_end' => '2026-10-02',
            'date_accomplished' => '2026-10-03', 'remarks' => 'Native AWS submission fixture',
        ],
        'ipaf_management' => ['document_type' => 'Final Report', 'date_conducted' => '2026-10-01', 'date_accomplished' => '2026-10-03', 'remarks' => 'Native pilot fixture'],
        'revenue_collection' => ['document_type' => 'Final Report', 'reporting_month' => 10, 'reporting_year' => 2026, 'total_collected' => '250.00', 'deadline_submission' => '2026-10-30', 'remarks' => 'Native pilot fixture'],
    ];

    foreach ($sourceColumns as $workflow => [$table, $linkColumn, $sourceKey]) {
        $token = (string) Str::uuid();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
            'start_idempotency_key' => $token, 'mov' => pamoPilotUpload(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version, ...$payloads[$workflow],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        $recordId = (int) $draft->getAttribute($linkColumn);
        $record = DB::table($table)->where('id', $recordId)->first();
        expect($draft->status)->toBe('forwarded')
            ->and($record)->not->toBeNull()
            ->and((int) $record->protected_area_id)->toBe((int) $area->getKey())
            ->and($record->target_office)->toBe('CENRO Baganga')
            ->and(DB::table($table)->count())->toBe(1);
        if ($workflow === 'automated_weather_station') {
            expect($record->status)->toBe('Approve')
                ->and($record->start_date)->toBeNull()
                ->and($record->end_date)->toBeNull()
                ->and($record->monitoring_period_start)->toBe('2026-10-01')
                ->and($record->monitoring_period_end)->toBe('2026-10-02')
                ->and($record->quarter)->toBe(4)
                ->and($record->activity_name)->toBe('Monitoring and Maintenance of AWS')
                ->and($record->station_name)->toBe('Automated Weather Station')
                ->and($record->timestamps)->toBeNull();
        }
        $pathColumn = $workflow === 'automated_weather_station' ? 'report_file_path' : 'mov_file_path';
        expect($record->{$pathColumn})->not->toBeNull()
            ->and(Storage::disk('local')->exists($record->{$pathColumn}))->toBeTrue();

        expect(fn () => PamoPambDraft::query()->create([
            'draft_uuid' => (string) Str::uuid(), 'start_idempotency_key' => (string) Str::uuid(),
            'protected_area_id' => $area->getKey(), 'pamo_office_id' => $office->getKey(), 'pamo_office_name' => $office->name,
            'originator_user_id' => $pamo->getKey(), 'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
            'workflow_key' => $workflow, 'report_payload' => [], 'started_at' => now(), 'status' => 'forwarded',
            'forwarded_at' => now(), $linkColumn => $recordId,
        ]))->toThrow(\Illuminate\Database\QueryException::class);

        $event = DocumentRoutingEvent::query()->where('source_type', $sourceKey)->where('source_id', $recordId)->firstOrFail();
        $snapshotId = (int) SubmissionRoutingSnapshot::query()->where('source_key', $sourceKey)->where('source_id', $recordId)->value('id');
        expect($event->event_key)->toBe('forwarded')
            ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL)
            ->and(SubmissionRoutingSnapshot::query()->where('source_key', $sourceKey)->where('source_id', $recordId)->value('origin_kind'))->toBe('pamo_pamb');

        $this->actingAs($pamo)->get(route('pamo-native-reports.index', $workflow))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('reports.data.0.source_url', route('submission-tracking.index', ['view' => 'outgoing', 'source' => $sourceKey, 'source_id' => $recordId]))
                ->where('reports.data.0.mov_url', route('pamo-native-reports.mov', [$workflow, $recordId]))
                ->where('reports.data.0.mov_available', true));

        $this->actingAs($pamo)->get(route('pamo-native-reports.mov', [$workflow, $recordId]))->assertOk();
        $this->actingAs($coworker)->get(route('pamo-native-reports.mov', [$workflow, $recordId]))->assertOk();
        $this->actingAs($foreign)->get(route('pamo-native-reports.mov', [$workflow, $recordId]))->assertNotFound();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect(DB::table($table)->count())->toBe(1)
            ->and(DocumentRoutingEvent::query()->where('source_type', $sourceKey)->where('source_id', $recordId)->count())->toBe(1);

        $cenro = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
        $this->actingAs($cenro)->post(route('submission-tracking.transition', [$sourceKey, $recordId, 'receive_at_cenro_focal']), ['stage' => 'receive_at_cenro_focal'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($cenro)->post(route('submission-tracking.transition', [$sourceKey, $recordId, 'return_to_pamo_for_correction']), [
            'stage' => 'return_to_pamo_for_correction', 'remarks' => 'Correct the report fields.', 'correction_reason_key' => 'incomplete_document',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $focusedIncoming = $this->actingAs($pamo)->get(route('submission-tracking.index', [
            'view' => 'incoming', 'focus_source' => $sourceKey, 'focus_id' => $recordId,
            'source' => $sourceKey, 'source_id' => $recordId,
        ]))->assertOk()->inertiaPage();
        $pamoIncomingRow = collect(data_get($focusedIncoming, 'props.workspaceQueues.incoming'))
            ->first(fn (array $row): bool => ($row['source'] ?? null) === $sourceKey && (int) ($row['source_id'] ?? 0) === $recordId);
        $selectedRow = data_get($focusedIncoming, 'props.trackingContext.selected_record');
        expect($pamoIncomingRow)->not->toBeNull()
            ->and(collect(data_get($pamoIncomingRow, 'routing.actions'))->pluck('key'))->toContain('receive_correction')
            ->and(data_get($pamoIncomingRow, 'incoming_action_category'))->toBe('correction')
            ->and(data_get($selectedRow, 'source'))->toBe($sourceKey)
            ->and((int) data_get($selectedRow, 'source_id'))->toBe($recordId)
            ->and(collect(data_get($selectedRow, 'routing.actions'))->pluck('key'))->toContain('receive_correction');

        $foreignIncoming = $this->actingAs($foreign)->get(route('submission-tracking.index', [
            'view' => 'incoming', 'focus_source' => $sourceKey, 'focus_id' => $recordId,
            'source' => $sourceKey, 'source_id' => $recordId,
        ]))->assertOk()->inertiaPage();
        expect(collect(data_get($foreignIncoming, 'props.workspaceQueues.incoming'))
            ->first(fn (array $row): bool => ($row['source'] ?? null) === $sourceKey && (int) ($row['source_id'] ?? 0) === $recordId))
            ->toBeNull()
            ->and(data_get($foreignIncoming, 'props.trackingContext.selected_record'))->toBeNull();

        if ($workflow === 'bms') {
            $unrelatedOffice = pamoPilotActor(OrganizationalAccessService::PAMO, 'PAMO Unassigned Office', (int) $area->getKey());
            $pendingPamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
            $pendingPamo->update(['is_approved' => false]);
            $inactivePamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
            $inactivePamo->update(['is_active' => false]);

            foreach ([$unrelatedOffice, $pendingPamo, $inactivePamo] as $ineligiblePamo) {
                $ineligibleResponse = $this->actingAs($ineligiblePamo)->get(route('submission-tracking.index', [
                    'view' => 'incoming', 'focus_source' => $sourceKey, 'focus_id' => $recordId,
                    'source' => $sourceKey, 'source_id' => $recordId,
                ]));
                if (! $ineligiblePamo->is_active || ! $ineligiblePamo->is_approved) {
                    $ineligibleResponse->assertRedirect(route('login'));
                    continue;
                }
                $ineligibleIncoming = $ineligibleResponse->assertOk()->inertiaPage();
                expect(collect(data_get($ineligibleIncoming, 'props.workspaceQueues.incoming'))
                    ->first(fn (array $row): bool => ($row['source'] ?? null) === $sourceKey && (int) ($row['source_id'] ?? 0) === $recordId))
                    ->toBeNull()
                    ->and(data_get($ineligibleIncoming, 'props.trackingContext.selected_record'))->toBeNull();
            }
        }

        $nativeModel = $this->app->make(\App\Services\Pamo\PamoNativeSourceRegistry::class)->find($workflow)['model'];
        $beforeReceipt = $nativeModel::query()->findOrFail($recordId);
        $this->actingAs($pamo)->put(route('pamo-native-reports.correction.update', [$workflow, $recordId]), [
            ...$payloads[$workflow], 'source_updated_at' => $beforeReceipt->updated_at->toISOString(),
        ])->assertForbidden();
        expect($nativeModel::query()->findOrFail($recordId)->updated_at->equalTo($beforeReceipt->updated_at))->toBeTrue();

        $this->actingAs($pamo)->post(route('submission-tracking.transition', [$sourceKey, $recordId, 'receive_correction']), ['stage' => 'receive_correction'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($pamo)->post(route('submission-tracking.transition', [$sourceKey, $recordId, 'receive_correction']), ['stage' => 'receive_correction'])
            ->assertRedirect()->assertSessionHasErrors('stage');
        expect(DocumentRoutingEvent::query()->where('source_type', $sourceKey)->where('source_id', $recordId)->count())->toBe(4);
        $this->actingAs($pamo)->get(route('pamo-native-reports.index', $workflow))->assertOk()->assertInertia(fn ($page) => $page
            ->where('reports.data.0.id', $recordId)
            ->where('reports.data.0.can_edit_correction', true)
            ->where('reports.data.0.source_url', route('submission-tracking.index', ['view' => 'incoming', 'source' => $sourceKey, 'source_id' => $recordId])));
        $freshNative = $nativeModel::query()->findOrFail($recordId);
        $this->actingAs($pamo)->put(route('pamo-native-reports.correction.update', [$workflow, $recordId]), [
            ...$payloads[$workflow], 'source_id' => $recordId + 100, 'record_id' => $recordId + 100,
            'source_updated_at' => $freshNative->updated_at->toISOString(),
        ])->assertSessionHasErrors(['source_id', 'record_id']);
        expect($nativeModel::query()->findOrFail($recordId)->updated_at->equalTo($freshNative->updated_at))->toBeTrue();
        $correction = [...$payloads[$workflow], 'source_updated_at' => $freshNative->updated_at->toISOString()];
        $this->actingAs($pamo)->put(route('pamo-native-reports.correction.update', [$workflow, $recordId]), $correction)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($pamo)->post(route('submission-tracking.transition', [$sourceKey, $recordId, 'forward_from_pamo_to_cenro_focal']), [
            'stage' => 'forward_from_pamo_to_cenro_focal',
        ])->assertRedirect()->assertSessionHasNoErrors();
        if ($workflow === 'bms') {
            $correctedBms = $nativeModel::query()->findOrFail($recordId);
            expect($correctedBms->activity_name)->toBe('Conduct 1st Semester Biodiversity Monitoring System')
                ->and((int) $correctedBms->protected_area_id)->toBe((int) $area->getKey())
                ->and($correctedBms->target_office)->toBe('CENRO Baganga')
                ->and((int) SubmissionRoutingSnapshot::query()->where('source_key', $sourceKey)->where('source_id', $recordId)->value('id'))->toBe($snapshotId)
                ->and(SubmissionRoutingSnapshot::query()->where('source_key', $sourceKey)->where('source_id', $recordId)->value('origin_kind'))->toBe('pamo_pamb');
        }
        expect(DocumentRoutingEvent::query()->where('source_type', $sourceKey)->where('source_id', $recordId)->count())->toBe(5)
            ->and(DocumentRoutingEvent::query()->where('source_type', $sourceKey)->where('source_id', $recordId)->latest('id')->value('event_key'))->toBe('forwarded');

        $analytics = app(OfficeTatAnalyticsService::class)->analytics($pamo, [
            'category' => 'PAMO', 'processing_period' => '2026-H2', 'as_of_date' => CarbonImmutable::now('Asia/Manila')->toDateString(),
            'workflow' => $sourceKey.':'.$sourceKey, 'protected_area_id' => $area->getKey(), 'evidence_type' => 'none',
        ]);
        $preparation = collect($analytics['pamo_preparation']['rows'])->firstWhere('office_id', $office->getKey());
        expect($preparation['documents_started'])->toBe(1)
            ->and($preparation['completed_documents'])->toBe(1)
            ->and($preparation['elapsed_seconds_total'])->toBeGreaterThanOrEqual(0)
            ->and(collect($analytics['options']['workflows'])->pluck('value')->contains($sourceKey.':'.$sourceKey))->toBeTrue()
            ->and(collect($analytics['rows'])->firstWhere('office', $office->name)['tat_completed_documents'])->toBe(0);
    }
});

test('native PAMO direct route captures the MHRWS PENRO Records first recipient', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'MHRWS', 'PAMO Native MHRWS', 'penro_davao_oriental');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $token = (string) Str::uuid();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'bms'), [
        'start_idempotency_key' => $token, 'mov' => pamoPilotUpload(), 'activity_name' => 'Direct PAMO BMS',
        'document_type' => 'Final Report', 'semester' => '2nd Semester',
        'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']], 'date_accomplished' => '2026-10-03',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['bms', $draft->draft_uuid]), ['lock_version' => $draft->lock_version])
        ->assertRedirect()->assertSessionHasNoErrors();
    $draft->refresh();
    $report = \App\Models\BmsReportSubmission::query()->findOrFail($draft->bms_report_submission_id);
    $event = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->firstOrFail();
    expect($report->target_office)->toBe('PENRO Davao Oriental')
        ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $report->getKey())->value('profile'))->toBe('pamo_direct');

    $records = pamoPilotActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->getKey(), 'receive_at_penro_records']), ['stage' => 'receive_at_penro_records'])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($records)->post(route('submission-tracking.transition', ['bms', $report->getKey(), 'return_for_correction_penro_records']), [
        'stage' => 'return_for_correction_penro_records', 'remarks' => 'Correct the report.', 'correction_reason_key' => 'incomplete_document',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($pamo)->post(route('submission-tracking.transition', ['bms', $report->getKey(), 'receive_correction']), ['stage' => 'receive_correction'])->assertRedirect()->assertSessionHasNoErrors();
    $report->refresh();
    $this->actingAs($pamo)->put(route('pamo-native-reports.correction.update', ['bms', $report->getKey()]), [
        'activity_name' => 'Corrected direct PAMO BMS', 'document_type' => 'Final Report', 'semester' => '2nd Semester',
        'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']], 'date_accomplished' => '2026-10-03',
        'source_updated_at' => $report->updated_at->toISOString(),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($pamo)->post(route('submission-tracking.transition', ['bms', $report->getKey(), 'forward_from_pamo']), [
        'stage' => 'forward_from_pamo',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->count())->toBe(5)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->latest('id')->value('event_key'))->toBe('forwarded');
});

test('native source adapters retain the assigned Baganga and Mati first recipients across PA families', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();

    foreach ([
        ['BMSFR', 'PAMO Native BMSFR', 'cenro_baganga', 'CENRO Baganga'],
        ['BPL', 'PAMO Native BPL', 'cenro_baganga', 'CENRO Baganga'],
        ['MPL', 'PAMO Native MPL Recipient', 'cenro_mati', 'CENRO Mati'],
        ['PBPLS', 'PAMO Native PBPLS', 'cenro_mati', 'CENRO Mati'],
    ] as [$shortName, $officeName, $supervisingCode, $recipient]) {
        [$area, $office] = pamoPilotArea($owner, $shortName, $officeName, $supervisingCode);
        $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
        $token = (string) Str::uuid();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'bms'), [
            'start_idempotency_key' => $token, 'mov' => pamoPilotUpload(),
            'activity_name' => 'Native recipient fixture', 'document_type' => 'Final Report',
            'semester' => '2nd Semester', 'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']],
            'date_accomplished' => '2026-10-03',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['bms', $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        $report = \App\Models\BmsReportSubmission::query()->findOrFail($draft->bms_report_submission_id);
        $event = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->firstOrFail();
        expect($report->target_office)->toBe($recipient)
            ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL)
            ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $report->getKey())->value('pamo_office_id'))->toBe($office->getKey());
    }
});

test('every active generic PA Conservation workflow saves reopens validates and promotes once', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Catalog Matrix', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $registry = app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class);
    $keys = $registry->pamoSubmissionKeys();
    $promoted = 0;

    foreach ($keys as $workflow) {
        $definition = $registry->find($workflow);
        $activity = $definition['default_activity'] ?? $definition['activities'][0];
        $document = ($definition['activity_documents'][$activity] ?? $definition['documents'])[0];
        $payload = [
            'activity_name' => $activity,
            'document_type' => $document,
            'reporting_period' => $definition['periods'][0],
            'date_conducted' => '2026-10-01',
            'date_accomplished' => '2026-10-02',
        ];
        if (app(\App\Services\DateConductedRangeService::class)->supportsWorkflow($workflow)) {
            $payload['date_conducted_ranges'] = [['from' => '2026-10-01', 'to' => '2026-10-01']];
        }

        $token = (string) Str::uuid();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
            'start_idempotency_key' => $token,
            'mov' => pamoPilotUpload(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        expect(ConservationReportSubmission::query()->count())->toBe($promoted)
            ->and(DocumentRoutingEvent::query()->count())->toBe($promoted);

        $this->actingAs($pamo)->put(route('conservation-reports.pamo-drafts.update', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version,
            ...$payload,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->fresh()->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $draft->refresh();
        $source = ConservationReportSubmission::query()->findOrFail($draft->conservation_report_submission_id);
        $events = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->get();
        expect($draft->status)->toBe('forwarded')
            ->and($source->workflow_key)->toBe($workflow)
            ->and((int) $source->protected_area_id)->toBe((int) $area->getKey())
            ->and($source->target_office)->toBe('CENRO Baganga')
            ->and($events)->toHaveCount(1)
            ->and($events->first()->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL);
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect(ConservationReportSubmission::query()->where('workflow_key', $workflow)->count())->toBe(1)
            ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->count())->toBe(1);
        $promoted++;
    }

    expect($keys)->toHaveCount(22)
        ->and(ConservationReportSubmission::query()->count())->toBe(22)
        ->and(DocumentRoutingEvent::query()->count())->toBe(22);
});

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
            ->where('registrationOptions.protectedAreas.0.pamo_office_name', $office->name)
            ->where('registrationOptions.protectedAreas.0.supervising_office_name', 'CENRO Baganga'));

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
        $overrideToken = (string) Str::uuid();
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
            'start_idempotency_key' => $overrideToken,
            'protected_area_id' => $foreignArea->getKey(),
            'pamo_office_id' => $foreignOffice->getKey(),
        ])->assertSessionHasErrors(['protected_area_id', 'pamo_office_id']);
        expect(PamoPambDraft::query()->where('start_idempotency_key', $overrideToken)->exists())->toBeFalse();
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

        $initialRoute = app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)
            ->present($submission, 'conservation', null, $initialEvents);
        $originMarker = collect($initialRoute['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::PAMO_ORIGIN);
        expect($originMarker['label'])->toBe('Report prepared by PAMO')
            ->and($originMarker['event_type'])->toBe('origin')
            ->and($originMarker['display_status_label'])->toBe('Origin')
            ->and($originMarker['from'])->toBeNull()
            ->and($originMarker['to'])->toBeNull()
            ->and($originMarker['office'])->toBe($office->name);

        $pamoPage = $this->actingAs($pamo)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk()->inertiaPage();
        $promotedView = collect(data_get($pamoPage, 'props.submissions.data'))->firstWhere('id', $submission->getKey());
        expect($promotedView['pamo_origin'])->toBeTrue()
            ->and($promotedView['pamo_origin_office'])->toBe($office->name)
            ->and($promotedView['submission_status'])->toBe('Awaiting receipt by CENRO CDS Focal Person')
            ->and($promotedView['current_document']['preview_url'])->toContain('/attachments/conservation-report/'.$submission->getKey().'/mov?preview=1')
            ->and($promotedView['current_document']['protected'])->toBeTrue();

        $preview = $this->actingAs($pamo)->get($promotedView['current_document']['preview_url']);
        $preview->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($preview->baseResponse->getFile()->getContent())->toBe("%PDF-1.4\nPAMB test fixture\n%%EOF");

        $admin = User::factory()->create(['is_active' => true, 'is_approved' => true]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $adminPage = $this->actingAs($admin)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk()->inertiaPage();
        $adminView = collect(data_get($adminPage, 'props.submissions.data'))->firstWhere('id', $submission->getKey());
        expect($adminView['submission_status'])->toBe($promotedView['submission_status']);

        $this->actingAs($foreign)->get($promotedView['current_document']['preview_url'])->assertForbidden();
        $this->actingAs($unapproved)->get($promotedView['current_document']['preview_url'])
            ->assertRedirect(route('login'))->assertSessionHas('pending_approval');
        $this->actingAs($pamo)->get(route('attachments.show', ['source' => 'unknown-source', 'record' => $submission->getKey(), 'attachment' => 'mov']))->assertNotFound();
        $this->actingAs($pamo)->get(route('attachments.show', ['source' => 'bms-report', 'record' => 999999999, 'attachment' => 'mov']))->assertNotFound();

        // A replay returns the same published record and does not create another report or handoff.
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['regular_pamb', $draft->draft_uuid]), [
            'lock_version' => $draft->lock_version,
        ])->assertRedirect();
        expect($draft->fresh()->updated_by)->toBe($coworker->getKey())
            ->and($draft->fresh()->originator_user_id)->toBe($pamo->getKey());
        expect(ConservationReportSubmission::query()->count())->toBe(1)
            ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $submission->getKey())->count())->toBe(1);

        $focal = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
        $focalSourcePage = $this->actingAs($focal)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk()->inertiaPage();
        $focalSourceRow = collect(data_get($focalSourcePage, 'props.submissions.data'))->firstWhere('id', $submission->getKey());
        $focalIncomingPage = $this->actingAs($focal)->get(route('submission-tracking.index', ['view' => 'incoming']))->assertOk()->inertiaPage();
        $focalIncomingRow = collect(data_get($focalIncomingPage, 'props.workspaceQueues.incoming'))->firstWhere('source_id', $submission->getKey());
        expect($focalSourceRow)->not->toBeNull()
            ->and($focalIncomingRow)->not->toBeNull()
            ->and(data_get($focalIncomingRow, 'routing.responsible_user_category'))->toBe('CENRO CDS Focal Person')
            ->and(data_get($focalIncomingRow, 'routing.responsible_office'))->toBe('CENRO Baganga')
            ->and(collect(data_get($focalIncomingRow, 'routing.actions'))->pluck('key'))->toContain('receive_at_cenro_focal')
            ->and(data_get($focalIncomingRow, 'routing.originating_office'))->toBe($office->name)
            ->and(data_get($focalIncomingRow, 'routing.route_position.origin_kind'))->toBe('pamo_pamb');

        $wrongOffice = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
        $wrongSourcePage = $this->actingAs($wrongOffice)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk()->inertiaPage();
        $wrongIncomingPage = $this->actingAs($wrongOffice)->get(route('submission-tracking.index', ['view' => 'incoming']))->assertOk()->inertiaPage();
        expect(collect(data_get($wrongSourcePage, 'props.submissions.data'))->firstWhere('id', $submission->getKey()))->toBeNull()
            ->and(collect(data_get($wrongIncomingPage, 'props.workspaceQueues.incoming'))->firstWhere('source_id', $submission->getKey()))->toBeNull();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 10:50:00', 'Asia/Manila'));
        $receiveFocal = $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'receive_at_cenro_focal']), [
            'stage' => 'receive_at_cenro_focal',
        ]);
        expect($receiveFocal->status())->toBe(302, 'CENRO CDS Focal receipt must be accepted.');
        $receiveFocal->assertSessionHasNoErrors();
        expect(app(DocumentRoutingTransitionService::class)->state($submission->fresh(), 'conservation')['stage'])
            ->toBe(DocumentRoutingProfileRegistry::PREPARATION);

        $receivedRoute = app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)
            ->present($submission->fresh(), 'conservation', null, app(DocumentRoutingTransitionService::class)->events($submission->fresh(), 'conservation'));
        $receivedOrigin = collect($receivedRoute['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::PAMO_ORIGIN);
        $receivedHandoff = collect($receivedRoute['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL);
        expect($receivedOrigin['label'])->toBe('Report prepared by PAMO')
            ->and($receivedOrigin['from'])->toBeNull()
            ->and($receivedOrigin['to'])->toBeNull()
            ->and($receivedOrigin['office'])->toBe($office->name)
            ->and($receivedHandoff['from'])->toBe($office->name)
            ->and($receivedHandoff['to'])->toBe('CENRO Baganga CDS Focal Person')
            ->and($receivedHandoff['office'])->toBe('CENRO Baganga');

        $receivedPamo = $this->actingAs($pamo)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk()->inertiaPage();
        $receivedAdmin = $this->actingAs($admin)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk()->inertiaPage();
        $receivedPamoStatus = collect(data_get($receivedPamo, 'props.submissions.data'))->firstWhere('id', $submission->getKey())['submission_status'];
        $receivedAdminStatus = collect(data_get($receivedAdmin, 'props.submissions.data'))->firstWhere('id', $submission->getKey())['submission_status'];
        expect($receivedPamoStatus)->toBe('Awaiting forward to CENRO Chief')
            ->and($receivedAdminStatus)->toBe($receivedPamoStatus);

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
        $returnedRoute = app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)
            ->present($submission->fresh(), 'conservation', null, app(DocumentRoutingTransitionService::class)->events($submission->fresh(), 'conservation'));
        $returnedOrigin = collect($returnedRoute['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::PAMO_ORIGIN);
        expect($returnedOrigin['label'])->toBe('Returned to PAMO for Correction')
            ->and($returnedOrigin['event_type'])->toBe('returned_for_correction')
            ->and($returnedOrigin['from'])->toBe('CENRO CDS Focal Person')
            ->and($returnedOrigin['to'])->toBe($office->name)
            ->and($returnedOrigin['office'])->toBe($office->name);
        $pamoIncomingPage = $this->actingAs($pamo)
            ->get(route('submission-tracking.index', ['view' => 'incoming']))
            ->assertOk()
            ->inertiaPage();
        $pamoIncomingRow = collect(data_get($pamoIncomingPage, 'props.workspaceQueues.incoming'))
            ->firstWhere('source_id', $submission->getKey());
        expect($pamoIncomingRow)->not->toBeNull()
            ->and(data_get($pamoIncomingRow, 'routing.responsible_user_category'))->toBe(OrganizationalAccessService::PAMO)
            ->and(data_get($pamoIncomingRow, 'routing.responsible_office'))->toBe($office->name)
            ->and(collect(data_get($pamoIncomingRow, 'routing.actions'))->pluck('key'))->toContain('receive_correction')
            ->and(data_get($pamoIncomingRow, 'incoming_action_category'))->toBe('correction');
        $foreignIncomingPage = $this->actingAs($foreign)
            ->get(route('submission-tracking.index', ['view' => 'incoming']))
            ->assertOk()
            ->inertiaPage();
        expect(collect(data_get($foreignIncomingPage, 'props.workspaceQueues.incoming'))
            ->firstWhere('source_id', $submission->getKey()))->toBeNull();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 11:10:00', 'Asia/Manila'));
        $receiveCorrection = $this->actingAs($pamo)->post(route('submission-tracking.transition', ['conservation', $submission->getKey(), 'receive_correction']), [
            'stage' => 'receive_correction',
        ]);
        expect($receiveCorrection->status())->toBe(302, 'PAMO must be able to receive correction at its captured origin.');
        $receiveCorrection->assertSessionHasNoErrors();

        $activeCorrectionRoute = app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)
            ->present($submission->fresh(), 'conservation', null, app(DocumentRoutingTransitionService::class)->events($submission->fresh(), 'conservation'));
        $activeCorrectionOrigin = collect($activeCorrectionRoute['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::PAMO_ORIGIN);
        expect($activeCorrectionOrigin['label'])->toBe('Returned to PAMO for Correction')
            ->and($activeCorrectionOrigin['from'])->toBe('CENRO CDS Focal Person')
            ->and($activeCorrectionOrigin['to'])->toBe($office->name);

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

    $directRoute = app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)
        ->present($submission, 'conservation', null, $state['events']);
    $directOrigin = collect($directRoute['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::PAMO_ORIGIN);
    $directRecipient = collect($directRoute['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
    expect($directOrigin['label'])->toBe('Report prepared by PAMO')
        ->and($directOrigin['from'])->toBeNull()
        ->and($directOrigin['to'])->toBeNull()
        ->and($directOrigin['office'])->toBe($office->name)
        ->and($directRecipient['to'])->toBe('PENRO Records Unit')
        ->and($directRecipient['office'])->toBe('PENRO Davao Oriental');

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

test('PAMO preparation analytics includes active generic workflows outside the PAMB meeting subset', function (): void {
    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Homestay Analytics', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $submission = ConservationReportSubmission::query()->create([
        'workflow_key' => 'homestay', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Final Report', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-09-21',
        'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
    ]);
    PamoPambDraft::query()->create([
        'draft_uuid' => (string) Str::uuid(), 'protected_area_id' => $area->getKey(),
        'pamo_office_id' => $office->getKey(), 'pamo_office_name' => $office->name,
        'originator_user_id' => $pamo->getKey(), 'created_by' => $pamo->getKey(), 'updated_by' => $pamo->getKey(),
        'workflow_key' => 'homestay', 'report_payload' => ['reporting_period' => 'Quarter 3'],
        'started_at' => '2026-10-13 11:34:35', 'status' => 'forwarded',
        'conservation_report_submission_id' => $submission->getKey(), 'forwarded_at' => '2026-10-13 12:56:22',
    ]);
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation', 'source_id' => $submission->getKey(), 'workflow_key' => 'homestay',
        'event_key' => 'forwarded', 'from_stage' => DocumentRoutingProfileRegistry::PAMO_ORIGIN,
        'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL,
        'from_office' => $office->name, 'to_office' => 'CENRO CDS Focal Person',
        'occurred_at' => '2026-10-13 12:56:22', 'recorded_by' => $pamo->getKey(),
        'metadata' => ['action_key' => 'forward_from_pamo_to_cenro_focal'],
    ]);

    $baseFilters = [
        'category' => 'PAMO', 'processing_period' => '2026-H2', 'as_of_date' => '2026-10-13',
        'protected_area_id' => $area->getKey(), 'evidence_type' => 'none',
    ];
    $allModules = app(OfficeTatAnalyticsService::class)->analytics($pamo, [...$baseFilters, 'workflow' => 'all']);
    $allRow = collect($allModules['pamo_preparation']['rows'])->firstWhere('office_id', $office->getKey());
    $selectedWorkflow = app(OfficeTatAnalyticsService::class)->analytics($pamo, [...$baseFilters, 'workflow' => 'conservation:homestay']);
    $selectedRow = collect($selectedWorkflow['pamo_preparation']['rows'])->firstWhere('office_id', $office->getKey());

    expect($allRow['documents_started'])->toBe(1)
        ->and($allRow['completed_documents'])->toBe(1)
        ->and($allRow['elapsed_seconds_total'])->toBe(4907.0)
        ->and($selectedRow['documents_started'])->toBe(1)
        ->and($selectedRow['completed_documents'])->toBe(1)
        ->and($selectedRow['elapsed_seconds_total'])->toBe(4907.0);
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

test('PAMO-origin route keeps its captured PENRO Records dispatch while skipping the early archive', function (): void {
    config([
        'services.google_drive_archive.enabled' => true,
        'services.document_archive.driver' => 'fake',
        'services.document_archive.final_return_archive_enabled' => true,
    ]);
    Storage::fake('local');
    Notification::fake();

    $owner = User::factory()->create();
    [$area, $office] = pamoPilotArea($owner, 'APL', 'PAMO Checkpoint APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $office->name, (int) $area->getKey());
    $settingsAdmin = User::factory()->create(['section' => 'CDS', 'office_designated' => 'PENRO Davao Oriental']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $settings = app(\App\Services\SubmissionTracking\RoutingPositionSettingsService::class);
    $currentSettings = $settings->current();
    $settings->save($currentSettings['version'], false, false, 'PAMO checkpoint route fixture', $settingsAdmin);

    $gateway = new \App\Services\Archive\FakeDocumentArchiveGateway();
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, $gateway);

    $token = (string) Str::uuid();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'homestay'), [
        'start_idempotency_key' => $token,
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 3',
        'mov' => pamoPilotUpload(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['homestay', $draft->draft_uuid]), [
        'lock_version' => $draft->lock_version,
        'date_conducted_ranges' => [['from' => '2026-09-21', 'to' => '2026-09-25']],
        'date_accomplished' => '2026-10-13',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $record = ConservationReportSubmission::query()->findOrFail($draft->fresh()->conservation_report_submission_id);
    $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $record->getKey())->firstOrFail();
    expect($snapshot->graph_version)->toBe(RoutingPositionSnapshotService::PAMO_GRAPH_VERSION)
        ->and($snapshot->profile)->toBe('pamo_regular');

    $focal = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $chief = pamoPilotActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga');
    $cenroRecords = pamoPilotActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Baganga');
    $penroRecords = pamoPilotActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $routing = app(DocumentRoutingTransitionService::class);
    foreach ([
        [$focal, 'receive_at_cenro_focal'],
        [$focal, 'forward_to_cenro_chief'],
        [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'],
        [$cenroRecords, 'receive_at_cenro_records'],
        [$cenroRecords, 'forward_to_penro_records'],
        [$penroRecords, 'receive_at_penro_records'],
    ] as [$actor, $action]) {
        $routing->transition($record->fresh(), 'conservation', $action, $actor->getKey());
    }

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', [
        'conservation', $record->getKey(), 'dispatch_penro_records_to_cds_focal',
    ]), ['stage' => 'dispatch_penro_records_to_cds_focal'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $checkpoint = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $record->getKey())->latest('id')->firstOrFail();
    expect($checkpoint->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL)
        ->and(data_get($checkpoint->metadata, 'route_snapshot_id'))->toBe($snapshot->getKey())
        ->and(data_get($checkpoint->metadata, 'route_graph_version'))->toBe(RoutingPositionSnapshotService::PAMO_GRAPH_VERSION)
        ->and(data_get($checkpoint->metadata, 'route_profile'))->toBe('regular')
        ->and(data_get($checkpoint->metadata, 'routing_checkpoint'))->toBeNull()
        ->and(\App\Models\DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $record->getKey())->exists())->toBeFalse()
        ->and($gateway->uploadAttempts())->toBe(0);

    $this->withoutVite()->actingAs($pamo)->get(route('pamo-archives.index'))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('PamoReports/Archives')
            ->where('archives.total', 0)
            ->where('archives.data', []));

    [$foreignArea, $foreignOffice] = pamoPilotArea($owner, 'OTHER', 'PAMO Archive Foreign', 'cenro_mati');
    $foreignPamo = pamoPilotActor(OrganizationalAccessService::PAMO, $foreignOffice->name, (int) $foreignArea->getKey());
    $this->actingAs($foreignPamo)->get(route('pamo-archives.index'))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('assignedProtectedArea.id', (int) $foreignArea->getKey())
            ->where('archives.total', 0));
    $this->actingAs($foreignPamo)->get(route('pamo-archives.file', $checkpoint->getKey()))->assertNotFound();
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

test('PAMO-origin Regular PAMB keeps its captured route through Regional receipt and protected final archive', function (): void {
    config([
        'services.google_drive_archive.enabled' => true,
        'services.document_archive.driver' => 'fake',
        'services.document_archive.final_return_archive_enabled' => true,
    ]);
    Storage::fake('local');
    Notification::fake();
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $owner = User::factory()->create();
    [$area, $pamoOffice] = pamoPilotArea($owner, 'APL', 'PAMO Archive APL', 'cenro_baganga');
    $pamo = pamoPilotActor(OrganizationalAccessService::PAMO, $pamoOffice->name, (int) $area->getKey());
    $token = (string) Str::uuid();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
        'start_idempotency_key' => $token,
        'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-10-01', 'date_accomplished' => '2026-10-02', 'mov' => pamoPilotUpload(),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', ['regular_pamb', $draft->draft_uuid]), [
        'lock_version' => $draft->lock_version,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->findOrFail($draft->fresh()->conservation_report_submission_id);
    $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->getKey())->firstOrFail();
    expect($snapshot->origin_kind)->toBe('pamo_pamb')->and($snapshot->profile)->toBe('pamo_regular');

    $focal = pamoPilotActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $chief = pamoPilotActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga');
    $cenroRecords = pamoPilotActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Baganga');
    $penroRecords = pamoPilotActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = pamoPilotActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = pamoPilotActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = pamoPilotActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = pamoPilotActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $routing = app(DocumentRoutingTransitionService::class);
    $mov = app(PambMovProcessingService::class);

    $routing->transition($report->fresh(), 'conservation', 'receive_at_cenro_focal', $focal->getKey());
    $mov->submit($report->fresh(), $focal);
    $routing->transition($report->fresh(), 'conservation', 'forward_to_cenro_chief', $focal->getKey());
    $routing->transition($report->fresh(), 'conservation', 'receive_at_cenro_chief', $chief->getKey());
    $mov->review($report->fresh(), $chief, PambMovProcessingService::READY_FOR_RELEASE);
    foreach ([
        [$chief, 'forward_to_cenro_records'], [$cenroRecords, 'receive_at_cenro_records'],
        [$cenroRecords, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
        [$penroRecords, 'forward_to_office_penro'], [$office, 'receive_at_office_penro'],
        [$office, 'assign_to_tsd_chief'], [$tsd, 'receive_at_tsd_chief'],
        [$tsd, 'forward_to_cds_focal'], [$penroFocal, 'receive_at_cds_focal'],
        [$penroFocal, 'forward_to_cds_chief'], [$penroChief, 'receive_at_cds_chief'],
        [$penroChief, 'recommend_to_office_penro'], [$office, 'receive_at_office_penro_final'],
        [$office, 'approve_for_regional_release'], [$penroRecords, 'receive_at_penro_records_final'],
        [$penroRecords, 'release_to_regional'],
    ] as [$actor, $action]) {
        $routing->transition($report->fresh(), 'conservation', $action, $actor->getKey());
    }

    expect($gateway->uploadAttempts())->toBe(0)
        ->and(DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->getKey())->exists())->toBeFalse();
    $eventsBeforeReceipt = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->getKey())->count();
    $providerAttemptsBeforeReceipt = $gateway->uploadAttempts();
    $receipt = app(FinalDocumentArchiveService::class)->recordReceipt(
        $penroFocal, 'conservation', (int) $report->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'),
    );
    expect($receipt->release_evidence_type)->toBe('document_routing_events')
        ->and($gateway->uploadAttempts())->toBe($providerAttemptsBeforeReceipt)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->getKey())->count())->toBe($eventsBeforeReceipt);

    $result = app(FinalDocumentArchiveService::class)->createOrRetryManifest(
        $penroFocal, 'conservation', (int) $report->getKey(), (string) Str::uuid(),
        [UploadedFile::fake()->createWithContent('pamo-pamb-final-return.pdf', "%PDF-1.7\nPAMO-origin Regional signed packet")], true,
    );
    $manifest = FinalDocumentArchiveManifest::query()->with('files.archive')->where('receipt_id', $receipt->getKey())->firstOrFail();
    $file = $manifest->files->firstOrFail();
    $archives = app(FinalDocumentArchiveService::class);
    expect($result['status'])->toBe('archived')
        ->and($manifest->archive_status)->toBe('ARCHIVED')
        ->and($archives->authorizeManifestFileReader($pamo, $file)->getKey())->toBe($file->document_archive_id);
});
