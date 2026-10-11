<?php

use App\Models\BmsReportSubmission;
use App\Models\BamsReportSubmission;
use App\Models\Aws;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\IpafRevenueCollection;
use App\Models\IpafManagementReport;
use App\Models\ImeaReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ManagementPlan;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\CalendarMovEventService;
use App\Services\OfficeTatAnalyticsService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

function historicalEncodingActor(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    return $user;
}

function historicalEncodingArea(User $user): ProtectedArea
{
    $area = ProtectedArea::create([
        'name' => 'Historical Encoding Test PA',
        'short_name' => 'HETPA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $officeId = OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id');
    if ($officeId) {
        ProtectedAreaOfficeAssignment::query()->create([
            'protected_area_id' => $area->id,
            'organizational_office_id' => $officeId,
            'assignment_type' => 'supervising',
            'assigned_by' => $user->id,
        ]);
    }

    return $area;
}

function historicalBmsPayload(ProtectedArea $area, string $idempotencyKey): array
{
    return [
        'workflow_id' => 'bms:bms',
        'idempotency_key' => $idempotencyKey,
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'BMS historical monitoring',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_conducted_ranges' => [['from' => '2026-01-10', 'to' => '']],
        'date_accomplished' => '2026-01-15',
        'original_date_report_released_cenro' => '2026-01-20',
        'original_date_received_penro' => '2026-01-22',
        'original_date_endorsed_regional' => '',
        'mov' => UploadedFile::fake()->create('historical-bms.pdf', 32, 'application/pdf'),
    ];
}

function historicalEngpPayload(string $idempotencyKey): array
{
    return [
        'workflow_id' => 'engp:ngp_produce',
        'idempotency_key' => $idempotencyKey,
        'office' => 'CENRO Mati',
        'reporting_year' => 2025,
        'period_key' => 'Q1',
        'original_date_report_released_cenro' => '2025-03-08',
        'original_date_received_penro' => '2025-03-18',
        'mov' => UploadedFile::fake()->create('historical-engp.pdf', 32, 'application/pdf'),
    ];
}

test('historical Open Source destinations reuse the existing source module resolver', function (): void {
    $resolver = app(SubmissionTrackingService::class);
    $routes = [
        'conservation' => [new ConservationReportSubmission(['workflow_key' => 'regular_pamb']), 'conservation-reports.index', ['workflow' => 'regular_pamb']],
        'engp' => [new EngpReportSubmission(['workflow_key' => 'cbep']), 'engp-reports.index', ['workflow' => 'cbep']],
        'bms' => [new BmsReportSubmission, 'bms.index', ['tracker' => 1]],
        'bams' => [new BamsReportSubmission, 'bams.report-submissions.index', []],
        'imea' => [new ImeaReportSubmission, 'imea.report-submissions.index', []],
        'imea-maintenance' => [new ImeaFacilityMaintenanceReport, 'imea.maintenance-reports.index', []],
        'aws' => [new Aws, 'aws.index', []],
        'ipaf-management' => [new IpafManagementReport, 'ipaf.index', ['ipaf_tab' => 'management']],
        'revenue' => [new IpafRevenueCollection, 'ipaf.index', ['ipaf_tab' => 'revenue']],
        'management-plans' => [new ManagementPlan, 'management-plans.index', []],
    ];

    foreach ($routes as $source => [$record, $routeName, $parameters]) {
        $sourceDefinition = $resolver->source($source);
        expect($sourceDefinition)->toBeArray()->and($sourceDefinition['url'] ?? null)->toBeCallable();
        expect(($sourceDefinition['url'])($record))->toBe(route($routeName, $parameters));
    }
});

test('historical encoding saves a source record once, records manual dates separately, and skips live routing', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $user = historicalEncodingActor();
    $area = historicalEncodingArea($user);
    $payload = historicalBmsPayload($area, (string) str()->uuid());
    $tracking = app(SubmissionTrackingService::class);
    $queueCountsBefore = collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all();
    $filterOptionsBefore = $tracking->filterOptions([]);
    $trackingTotalBefore = $tracking->pagination([])['total'];
    $sideEffectCountsBefore = collect(['document_routing_events', 'pamb_routing_events', 'submission_routing_snapshots', 'notifications', 'jobs', 'engp_report_release_events', 'document_archives'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

    $this->actingAs($user)->post(route('settings.historical-reports.store'), $payload)
        ->assertRedirect(route('settings.historical-reports.index'))
        ->assertSessionHasNoErrors();

    $record = BmsReportSubmission::query()->sole();
    $this->assertDatabaseHas('historical_report_origins', [
        'source' => 'bms',
        'source_record_id' => $record->id,
        'workflow_key' => 'bms',
        'idempotency_key' => $payload['idempotency_key'],
    ]);
    expect((bool) $record->is_historical)->toBeTrue()
        ->and($record->semester)->toBe('1st Semester')
        ->and($record->date_conducted_ranges)->toBe([['from' => '2026-01-10', 'to' => '2026-01-10']])
        ->and($record->date_accomplished->toDateString())->toBe('2026-01-15')
        ->and($record->date_received_penro)->toBeNull()
        ->and($record->date_endorsed_regional)->toBeNull();
    $origin = HistoricalReportOrigin::query()->where('source', 'bms')->where('source_record_id', $record->id)->firstOrFail();
    expect(data_get($origin->original_milestones, 'milestones'))->toBe([
        'date_report_released_cenro' => '2026-01-20',
        'date_received_penro' => '2026-01-22',
    ]);
    $this->assertDatabaseMissing('document_routing_events', ['source_type' => 'bms', 'source_id' => $record->id]);
    $this->assertDatabaseMissing('submission_routing_snapshots', ['source_key' => 'bms', 'source_id' => $record->id]);
    $this->assertDatabaseMissing('report_tracking_references', ['source_type' => 'bms', 'source_id' => $record->id]);
    expect($tracking->records([], 100)->contains(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $record->id))->toBeFalse()
        ->and($tracking->pagination([])['total'])->toBe($trackingTotalBefore)
        ->and(collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all())->toBe($queueCountsBefore)
        ->and($tracking->filterOptions([]))->toBe($filterOptionsBefore);

    foreach (['incoming', 'outgoing', 'history'] as $view) {
        $this->get(route('submission-tracking.index', [
            'view' => $view,
            'source' => 'bms',
            'source_id' => $record->id,
        ]))->assertOk()->assertInertia(fn ($page) => $page
            ->component('SubmissionTracking/Index')
            ->where('trackingContext.view', $view)
            ->where('trackingContext.selected_record', null)
            ->where('pagination.total', $trackingTotalBefore)
            ->where('filterOptions.targetOffices', $filterOptionsBefore['targetOffices'])
            ->where('filterOptions.periods', $filterOptionsBefore['periods'])
            ->where('workspaceQueues.incoming', fn ($rows): bool => collect($rows)->doesntContain(fn ($row): bool => ($row['source'] ?? null) === 'bms' && (int) ($row['source_id'] ?? 0) === $record->id))
            ->where('workspaceQueues.outgoing', fn ($rows): bool => collect($rows)->doesntContain(fn ($row): bool => ($row['source'] ?? null) === 'bms' && (int) ($row['source_id'] ?? 0) === $record->id))
            ->where('workspaceQueues.history', fn ($rows): bool => collect($rows)->doesntContain(fn ($row): bool => ($row['source'] ?? null) === 'bms' && (int) ($row['source_id'] ?? 0) === $record->id)));
    }

    $this->get(route('settings.historical-reports.show', ['source' => 'bms', 'record' => $record->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/HistoricalReportDetail')
            ->where('record.source', 'bms')
            ->where('record.source_id', $record->id)
            ->where('record.workflow_key', 'bms')
            ->where('record.module_url', route('bms.index', ['tracker' => 1]))
            ->where('record.timeline.1.date', '2026-01-22')
            ->where('record.historical_date_gaps.handoff.elapsed_calendar_days', 2)
            ->where('record.timeliness.receipt_date', '2026-01-22')
            ->where('record.attachments.0.can_preview', true));

    $this->getJson(route('settings.historical-reports.show', ['source' => 'bms', 'record' => $record->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('record.source', 'bms')
        ->assertJsonPath('record.source_id', $record->id);
    $this->get(route('settings.historical-reports.show', ['source' => 'revenue', 'record' => $record->id]))->assertNotFound();

    $sourceUnauthorized = User::factory()->create(['section' => 'CDS']);
    $sourceUnauthorized->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('historical-reports.view', 'web'));
    $this->actingAs($sourceUnauthorized)
        ->get(route('settings.historical-reports.show', ['source' => 'bms', 'record' => $record->id]))
        ->assertForbidden();
    $this->actingAs($user);

    $attachment = app(\App\Services\Attachments\ProtectedAttachmentService::class)->previewDescriptor('bms-report', $record, 'mov');
    expect($attachment)->not->toBeNull();
    $this->get($attachment['preview_url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get(route('settings.historical-reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/HistoricalReports')
            ->where('records.0.source', 'bms')
            ->where('records.0.id', $record->id));

    $this->get(route('bms.index', ['tracker' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Bms/Index')
            ->where('reportSubmissions.data.0.id', $record->id)
            ->where('reportSubmissions.data.0.is_historical', 1));

    $this->actingAs($user)->post(route('settings.historical-reports.store'), $payload)
        ->assertRedirect(route('settings.historical-reports.index'))
        ->assertSessionHasNoErrors();
    expect(BmsReportSubmission::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('bms-report-movs'))->toHaveCount(1);

    $this->actingAs($user)->put(route('bms.report-submissions.update', $record), [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
    ])->assertSessionHasErrors('submission');
    $this->actingAs($user)->post(route('submission-tracking.transition', [
        'source' => 'bms', 'record' => $record->id, 'stage' => 'receive_at_cenro',
    ]), ['date' => '2026-01-25'])->assertSessionHasErrors('submission');
    $this->actingAs($user)->post(route('submission-tracking.internal-routing', [
        'source' => 'bms', 'record' => $record->id, 'stage' => 'release_to_penro',
    ]), ['date' => '2026-01-25'])->assertSessionHasErrors('submission');

    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->exists())->toBeFalse();
    $this->assertDatabaseMissing('submission_routing_snapshots', ['source_key' => 'bms', 'source_id' => $record->id]);
    expect(collect($sideEffectCountsBefore)->map(fn (int $count, string $table): int => DB::table($table)->count())->all())->toBe($sideEffectCountsBefore);
});

test('older-quarter PA encoding preserves source policy and does not count CENRO/PENRO dates as PAMO processing', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $user = historicalEncodingActor();
    $user->update(['unit_assignment' => 'conservation']);
    $area = historicalEncodingArea($user);
    $area->forceFill(['pamo' => 'Historical Test PAMO'])->save();
    $tracking = app(SubmissionTrackingService::class);
    $queueCountsBefore = collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all();
    $filterOptionsBefore = $tracking->filterOptions([]);
    $trackingTotalBefore = $tracking->pagination([])['total'];
    $sideEffectCountsBefore = collect(['document_routing_events', 'pamb_routing_events', 'submission_routing_snapshots', 'notifications', 'jobs', 'engp_report_release_events', 'document_archives'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    $encodedBefore = CarbonImmutable::now('Asia/Manila')->subSeconds(2);
    $payload = [
        'workflow_id' => 'conservation:restoration_plan_5_year',
        'idempotency_key' => (string) str()->uuid(),
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Preparation of 5-Year Restoration Plan',
        'document_type' => 'Final Report',
        'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2025-01-15',
        'original_date_report_released_cenro' => '2025-01-20',
        'original_date_received_penro' => '2025-01-22',
        'mov' => UploadedFile::fake()->create('older-quarter-restoration.pdf', 32, 'application/pdf'),
    ];

    $this->actingAs($user)->post(route('settings.historical-reports.store'), $payload)
        ->assertRedirect(route('settings.historical-reports.index'))
        ->assertSessionHasNoErrors();
    $encodedAfter = CarbonImmutable::now('Asia/Manila')->addSeconds(2);
    $record = ConservationReportSubmission::query()->sole();
    $origin = HistoricalReportOrigin::query()->sole();
    $originEncodedAt = CarbonImmutable::parse($origin->getRawOriginal('encoded_at'), 'Asia/Manila');

    expect((bool) $record->is_historical)->toBeTrue()
        ->and($record->workflow_key)->toBe('restoration_plan_5_year')
        ->and($record->reporting_period)->toBe('Quarter 1')
        ->and($record->date_accomplished->toDateString())->toBe('2025-01-15')
        ->and($record->deadline_submission)->toBe('2025-01-28')
        ->and($record->date_received_penro)->toBeNull()
        ->and($originEncodedAt->greaterThanOrEqualTo($encodedBefore))->toBeTrue()
        ->and($originEncodedAt->lessThanOrEqualTo($encodedAfter))->toBeTrue()
        ->and(data_get($origin->original_milestones, 'milestones'))->toBe([
            'date_report_released_cenro' => '2025-01-20',
            'date_received_penro' => '2025-01-22',
        ]);

    $this->get(route('settings.historical-reports.show', ['source' => 'conservation', 'record' => $record->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/HistoricalReportDetail')
            ->where('record.period', 'Quarter 1')
            ->where('record.encoded_at', fn ($value): bool => CarbonImmutable::parse($value)->equalTo($origin->encoded_at))
            ->where('record.timeliness.deadline', '2025-01-28')
            ->where('record.timeliness.receipt_date', '2025-01-22')
            ->where('record.timeliness.status', 'Outstanding')
            ->where('record.attachments.0.can_preview', true));

    $this->get(route('conservation-reports.index', ['workflow' => 'restoration_plan_5_year']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ConservationReports/Index')
            ->where('submissions.data.0.id', $record->id)
            ->where('submissions.data.0.is_historical', 1)
            ->where('submissions.data.0.reporting_period', 'Quarter 1'));

    $attachment = app(\App\Services\Attachments\ProtectedAttachmentService::class)->previewDescriptor('conservation-report', $record, 'mov');
    expect($attachment)->not->toBeNull();
    $this->get($attachment['preview_url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $tat = app(OfficeTatAnalyticsService::class)->analytics($user, [
        'category' => 'PAMO',
        'processing_period' => '2025-H1',
        'as_of_date' => '2025-03-31',
        'evidence_type' => 'historical',
    ]);
    $pamo = collect($tat['rows'])->firstWhere('office', 'Historical Test PAMO');
    expect($pamo['manual_historical_tat_completed_documents'])->toBe(0)
        ->and($pamo['manual_historical_tat_average_elapsed_days'])->toBeNull()
        ->and($pamo['manual_timeliness_reports'])->toBe(1)
        ->and($pamo['timeliness_eligible'])->toBe(1)
        ->and($pamo['timeliness_on_time'])->toBe(1)
        ->and($pamo['event_derived_tat_completed_documents'])->toBe(0);

    expect($tracking->records([], 100)->contains(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $record->id))->toBeFalse()
        ->and($tracking->pagination([])['total'])->toBe($trackingTotalBefore)
        ->and(collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all())->toBe($queueCountsBefore)
        ->and($tracking->filterOptions([]))->toBe($filterOptionsBefore);

    foreach (['incoming', 'outgoing', 'history'] as $view) {
        $this->get(route('submission-tracking.index', [
            'view' => $view,
            'source' => 'conservation',
            'source_id' => $record->id,
        ]))->assertOk()->assertInertia(fn ($page) => $page
            ->component('SubmissionTracking/Index')
            ->where('trackingContext.view', $view)
            ->where('trackingContext.selected_record', null)
            ->where('pagination.total', $trackingTotalBefore));
    }

    $this->actingAs($user)->put(route('conservation-reports.update', ['workflow' => 'restoration_plan_5_year', 'submission' => $record->id]), [])
        ->assertSessionHasErrors('submission');
    $this->actingAs($user)->post(route('submission-tracking.transition', [
        'source' => 'conservation', 'record' => $record->id, 'stage' => 'receive_at_cenro',
    ]), ['date' => '2025-01-23'])->assertSessionHasErrors('submission');
    $this->actingAs($user)->post(route('submission-tracking.internal-routing', [
        'source' => 'conservation', 'record' => $record->id, 'stage' => 'release_to_penro',
    ]), ['date' => '2025-01-23'])->assertSessionHasErrors('submission');

    $this->assertDatabaseMissing('document_routing_events', ['source_type' => 'conservation', 'source_id' => $record->id]);
    $this->assertDatabaseMissing('pamb_routing_events', ['conservation_report_submission_id' => $record->id]);
    $this->assertDatabaseMissing('submission_routing_snapshots', ['source_key' => 'conservation', 'source_id' => $record->id]);
    $this->assertDatabaseMissing('report_tracking_references', ['source_type' => 'conservation', 'source_id' => $record->id]);
    expect(collect($sideEffectCountsBefore)->map(fn (int $count, string $table): int => DB::table($table)->count())->all())->toBe($sideEffectCountsBefore);
});

test('Philippines actual PA dates stay capped while the Revenue module deadline may be future-dated', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $user = historicalEncodingActor();
    $area = historicalEncodingArea($user);
    $fixedNow = CarbonImmutable::parse('2026-10-08 16:30:00', 'UTC');
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow($fixedNow);

    try {
        $futurePa = historicalBmsPayload($area, (string) str()->uuid());
        $futurePa['date_accomplished'] = '2026-10-10';
        $this->actingAs($user)->post(route('settings.historical-reports.store'), $futurePa)
            ->assertSessionHasErrors('date_accomplished');

        $revenuePayload = [
            'workflow_id' => 'revenue:revenue',
            'idempotency_key' => (string) str()->uuid(),
            'protected_area_id' => $area->id,
            'target_office' => 'CENRO Mati',
            'activity_name' => 'Revenue Collection',
            'document_type' => 'Final Report',
            'reporting_month' => 10,
            'reporting_year' => 2026,
            'total_collected' => '125.00',
            'deadline_submission' => '2026-11-15',
            'mov' => UploadedFile::fake()->create('future-revenue-deadline.pdf', 32, 'application/pdf'),
        ];
        $this->actingAs($user)->post(route('settings.historical-reports.store'), $revenuePayload)
            ->assertRedirect(route('settings.historical-reports.index'))
            ->assertSessionHasNoErrors();

        $revenue = IpafRevenueCollection::query()->sole();
        expect((bool) $revenue->is_historical)->toBeTrue()
            ->and($revenue->reporting_year)->toBe(2026)
            ->and($revenue->reporting_month)->toBe(10)
            ->and($revenue->deadline_submission->toDateString())->toBe('2026-11-15');
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('historical ENGP quarterly encoding keeps the source period deadline and original receipt evidence', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $user = historicalEncodingActor();
    $tracking = app(SubmissionTrackingService::class);
    $queueCountsBefore = collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all();
    $payload = historicalEngpPayload((string) str()->uuid());

    $this->actingAs($user)->post(route('settings.historical-reports.store'), $payload)
        ->assertRedirect(route('settings.historical-reports.index'))
        ->assertSessionHasNoErrors();

    $record = EngpReportSubmission::query()->sole();
    expect((bool) $record->is_historical)->toBeTrue()
        ->and($record->reporting_year)->toBe(2025)
        ->and($record->period_key)->toBe('Q1')
        ->and($record->deadline_submission->toDateString())->toBe('2025-03-10')
        ->and($record->date_received_penro)->toBeNull();
    $this->assertDatabaseHas('historical_report_origins', [
        'source' => 'engp', 'source_record_id' => $record->id, 'workflow_key' => 'ngp_produce',
    ]);
    $this->assertDatabaseMissing('document_routing_events', ['source_type' => 'engp', 'source_id' => $record->id]);
    $this->assertDatabaseMissing('submission_routing_snapshots', ['source_key' => 'engp', 'source_id' => $record->id]);
    $this->assertDatabaseMissing('engp_report_release_events', ['engp_report_submission_id' => $record->id]);
    $this->assertDatabaseMissing('report_tracking_references', ['source_type' => 'engp', 'source_id' => $record->id]);
    expect($tracking->records([], 100)->contains(fn (array $row): bool => $row['source'] === 'engp' && (int) $row['source_id'] === $record->id))->toBeFalse()
        ->and(collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all())->toBe($queueCountsBefore);

    $this->get(route('engp-reports.index', ['workflow' => 'ngp_produce', 'year' => 2025]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Engp/Index')
            ->where('submissions.data.0.id', $record->id)
            ->where('submissions.data.0.is_historical', 1)
            ->where('submissions.data.0.deadline_submission', '2025-03-10'));

    $this->get(route('settings.historical-reports.show', ['source' => 'engp', 'record' => $record->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/HistoricalReportDetail')
            ->where('record.period', 'Quarter 1')
            ->where('record.timeline.1.date', '2025-03-18')
            ->where('record.timeliness.deadline', '2025-03-10')
            ->where('record.timeliness.receipt_date', '2025-03-18')
            ->where('record.attachments.0.can_preview', true));

    $tat = app(\App\Services\OfficeTatAnalyticsService::class)->analytics($user, [
        'category' => 'CENRO',
        'processing_period' => '2025-H1',
        'as_of_date' => '2025-03-18',
        'workflow' => 'engp:ngp_produce',
        'evidence_type' => 'historical',
    ]);
    $mati = collect($tat['rows'])->firstWhere('office', 'CENRO Mati');
    expect($mati['manual_received'])->toBe(1)
        ->and($mati['event_derived_tat_completed_documents'])->toBe(0)
        ->and($mati['tat_average_elapsed_days'])->toBeNull()
        ->and($mati['manual_historical_tat_completed_documents'])->toBe(1)
        ->and($mati['manual_historical_tat_average_elapsed_days'])->toBe(10.0);

    $this->actingAs($user)->put(route('engp-reports.update', ['workflow' => 'ngp_produce', 'engpReportSubmission' => $record->id]), [])
        ->assertSessionHasErrors('submission');
    $this->actingAs($user)->post(route('submission-tracking.transition', [
        'source' => 'engp', 'record' => $record->id, 'stage' => 'release_to_penro',
    ]), ['date' => '2025-03-19'])->assertSessionHasErrors('submission');
});

test('ENGP source and historical detail project immutable original evidence without changing live routing', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $user = historicalEncodingActor();
    $registry = app(EngpReportWorkflowRegistry::class);
    $tracking = app(SubmissionTrackingService::class);

    $makeRecord = function (string $office, string $periodKey, bool $historical, ?string $received = null) use ($user, $registry): EngpReportSubmission {
        $period = $registry->period('cbep', 2026, $periodKey);
        $record = EngpReportSubmission::query()->create([
            'workflow_key' => 'cbep',
            'office' => $office,
            'section_name' => 'PENRO TSD',
            'activity_name' => 'Community-Based Employment Program (CBEP)',
            'document_type' => 'Monthly Report',
            'reporting_year' => 2026,
            'period_key' => $periodKey,
            'period_label' => $period['label'],
            'deadline_submission' => $registry->deadline('cbep', 2026, $periodKey),
            'date_received_penro' => $received,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        if ($historical) $record->forceFill(['is_historical' => true])->save();

        return $record->fresh();
    };

    $july = $makeRecord('CENRO Demo B', '2026-07', true);
    $partial = $makeRecord('CENRO Demo A', '2026-06', true);
    $missing = $makeRecord('CENRO Demo C', '2026-05', true);
    $live = $makeRecord('CENRO Demo B', '2026-08', false, '2026-08-18');
    $encodedAt = CarbonImmutable::parse('2026-10-10 09:30:00', 'Asia/Manila');

    $julyOrigin = HistoricalReportOrigin::query()->create([
        'source' => 'engp',
        'source_record_id' => $july->id,
        'workflow_key' => 'cbep',
        'encoded_by' => $user->id,
        'idempotency_key' => (string) str()->uuid(),
        'original_milestones' => ['milestones' => [
            'date_report_released_cenro' => '2026-07-13',
            'date_received_penro' => '2026-07-15',
        ]],
        'encoded_at' => $encodedAt,
    ]);
    HistoricalReportOrigin::query()->create([
        'source' => 'engp',
        'source_record_id' => $partial->id,
        'workflow_key' => 'cbep',
        'encoded_by' => $user->id,
        'idempotency_key' => (string) str()->uuid(),
        'original_milestones' => ['milestones' => [
            'date_report_released_cenro' => '2026-06-13',
        ]],
        'encoded_at' => $encodedAt,
    ]);

    $sourceIds = [$july->id, $partial->id, $missing->id, $live->id];
    $queueCountsBefore = collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all();
    $sideEffectsBefore = [
        'routing_events' => DB::table('document_routing_events')->where('source_type', 'engp')->whereIn('source_id', $sourceIds)->count(),
        'release_events' => DB::table('engp_report_release_events')->whereIn('engp_report_submission_id', $sourceIds)->count(),
        'snapshots' => DB::table('submission_routing_snapshots')->where('source_key', 'engp')->whereIn('source_id', $sourceIds)->count(),
        'tracking_references' => DB::table('report_tracking_references')->where('source_type', 'engp')->whereIn('source_id', $sourceIds)->count(),
        'archives' => DB::table('document_archives')->where('source_type', 'engp')->whereIn('source_id', $sourceIds)->count(),
        'notifications' => DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->count(),
    ];
    $originSnapshot = [
        'original_milestones' => $julyOrigin->fresh()->original_milestones,
        'encoded_at' => $julyOrigin->fresh()->encoded_at?->toIso8601String(),
    ];

    expect($registry->deadline('cbep', 2026, '2026-07'))->toBe('2026-07-20')
        ->and(CarbonImmutable::parse('2026-07-13')->diffInDays(CarbonImmutable::parse('2026-07-15'), false))->toBe(2.0);

    $this->actingAs($user)->get(route('engp-reports.index', ['workflow' => 'cbep', 'year' => 2026]))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('Engp/Index')
            ->where('submissions.data', function ($rows) use ($july, $partial, $missing, $live): bool {
                $byId = collect($rows)->keyBy('id');
                $julyRow = $byId->get($july->id);
                $partialRow = $byId->get($partial->id);
                $missingRow = $byId->get($missing->id);
                $liveRow = $byId->get($live->id);

                expect($julyRow['period_label'])->toBe('July 2026')
                    ->and($julyRow['date_released_cenro'])->toBe('2026-07-13')
                    ->and($julyRow['date_received_penro'])->toBe('2026-07-15')
                    ->and($julyRow['deadline_submission'])->toBe('2026-07-20')
                    ->and($julyRow['days_complied'])->toBe(5)
                    ->and($julyRow['timeliness_rating'])->toBe('Outstanding')
                    ->and($julyRow['monitoring_status'])->toBe('Report Submitted')
                    ->and($julyRow['submission_status'])->toBe('Pending Regional Endorsement')
                    ->and($julyRow['release_events'])->toBeEmpty()
                    ->and($partialRow['date_received_penro'])->toBeNull()
                    ->and($partialRow['days_complied'])->toBe('Missing historical evidence')
                    ->and($partialRow['monitoring_status'])->toBe('Missing historical evidence')
                    ->and($partialRow['submission_status'])->toBe('Pending Receipt by PENRO')
                    ->and($missingRow['date_released_cenro'])->toBeNull()
                    ->and($missingRow['date_received_penro'])->toBeNull()
                    ->and($missingRow['days_complied'])->toBe('Missing historical evidence')
                    ->and($missingRow['monitoring_status'])->toBe('Missing historical evidence')
                    ->and($missingRow['submission_status'])->toBe('Missing historical evidence')
                    ->and($liveRow['date_received_penro'])->toBe('2026-08-18')
                    ->and($liveRow['days_complied'])->toBe(2)
                    ->and($liveRow['timeliness_rating'])->toBe('Very Satisfactory')
                    ->and($liveRow['monitoring_status'])->toBe('Report Submitted')
                    ->and($liveRow['submission_status'])->toBe($live->fresh()->submission_status);

                return true;
            }));

    $this->get(route('settings.historical-reports.show', ['source' => 'engp', 'record' => $july->id]))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/HistoricalReportDetail')
            ->where('record.source', 'engp')
            ->where('record.source_id', $july->id)
            ->where('record.workflow_key', 'cbep')
            ->where('record.module_url', route('engp-reports.index', 'cbep'))
            ->where('record.office', 'CENRO Demo B')
            ->where('record.period', 'July 2026')
            ->where('record.encoded_at', $encodedAt->toIso8601String())
            ->where('record.timeline.0.date', '2026-07-13')
            ->where('record.timeline.1.date', '2026-07-15')
            ->where('record.timeliness.deadline', '2026-07-20')
            ->where('record.timeliness.receipt_date', '2026-07-15')
            ->where('record.timeliness.status', 'Outstanding')
            ->where('record.detail.days_complied', 5)
            ->where('record.historical_date_gaps.processing.status', 'unsupported')
            ->where('record.historical_date_gaps.handoff.elapsed_calendar_days', 2)
            ->where('record.detail.timeliness_rating', 'Outstanding')
            ->where('record.detail.submission_status', 'Pending Regional Endorsement'));

    $this->get(route('settings.historical-reports.show', ['source' => 'engp', 'record' => $july->id]), ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('record.source', 'engp')
        ->assertJsonPath('record.timeline.0.date', '2026-07-13')
        ->assertJsonPath('record.timeline.1.date', '2026-07-15')
        ->assertJsonPath('record.timeliness.status', 'Outstanding')
        ->assertJsonMissingPath('record.detail.mov_file_path');

    $notHistoricalViewer = User::factory()->create(['section' => 'CDS']);
    $notHistoricalViewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('technical-reports.view', 'web'));
    $this->actingAs($notHistoricalViewer)
        ->get(route('settings.historical-reports.show', ['source' => 'engp', 'record' => $july->id]))
        ->assertForbidden();
    $this->actingAs($notHistoricalViewer)
        ->get(route('settings.historical-reports.show', ['source' => 'engp', 'record' => $july->id]), ['Accept' => 'application/json'])
        ->assertForbidden();

    expect($july->fresh()->date_received_penro)->toBeNull()
        ->and([
            'original_milestones' => $julyOrigin->fresh()->original_milestones,
            'encoded_at' => $julyOrigin->fresh()->encoded_at?->toIso8601String(),
        ])->toBe($originSnapshot)
        ->and(collect($tracking->workspaceQueues([]))->map(fn ($rows): int => $rows->count())->all())->toBe($queueCountsBefore)
        ->and(DB::table('document_routing_events')->where('source_type', 'engp')->whereIn('source_id', $sourceIds)->count())->toBe($sideEffectsBefore['routing_events'])
        ->and(DB::table('engp_report_release_events')->whereIn('engp_report_submission_id', $sourceIds)->count())->toBe($sideEffectsBefore['release_events'])
        ->and(DB::table('submission_routing_snapshots')->where('source_key', 'engp')->whereIn('source_id', $sourceIds)->count())->toBe($sideEffectsBefore['snapshots'])
        ->and(DB::table('report_tracking_references')->where('source_type', 'engp')->whereIn('source_id', $sourceIds)->count())->toBe($sideEffectsBefore['tracking_references'])
        ->and(DB::table('document_archives')->where('source_type', 'engp')->whereIn('source_id', $sourceIds)->count())->toBe($sideEffectsBefore['archives'])
        ->and(DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->count())->toBe($sideEffectsBefore['notifications']);
});

test('historical ENGP encoding never overwrites a live office-period submission', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $user = historicalEncodingActor();
    $live = EngpReportSubmission::query()->create([
        'workflow_key' => 'ngp_produce',
        'office' => 'CENRO Mati',
        'section_name' => 'PENRO TSD',
        'activity_name' => 'ENGP Produce',
        'document_type' => 'Quarterly Report',
        'reporting_year' => 2025,
        'period_key' => 'Q1',
        'period_label' => 'Quarter 1',
        'deadline_submission' => '2025-03-10',
        'date_received_penro' => '2025-03-09',
        'remarks' => 'Keep this live submission unchanged.',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $payload = historicalEngpPayload((string) str()->uuid());

    $this->actingAs($user)->post(route('settings.historical-reports.store'), $payload)
        ->assertSessionHasErrors('period_key');

    $live->refresh();
    expect(EngpReportSubmission::query()->count())->toBe(1)
        ->and($live->remarks)->toBe('Keep this live submission unchanged.')
        ->and($live->date_received_penro->toDateString())->toBe('2025-03-09')
        ->and((bool) $live->is_historical)->toBeFalse();
    $this->assertDatabaseCount('historical_report_origins', 0);
});

test('Office TAT is limited to PAMO and CENRO and versioned targets leave SLA compliance unavailable', function (): void {
    $this->withoutVite();
    $user = historicalEncodingActor();
    $payload = [
        'office_category' => 'CENRO',
        'office' => 'CENRO Mati',
        'period_key' => '2026-H2',
        'volume_target' => 12,
        'weights' => ['timeliness' => 0.50, 'tat_compliance' => 0.35, 'volume' => 0.15],
    ];

    $this->actingAs($user)->post(route('office-tat.configuration.store'), $payload)->assertRedirect();
    $this->assertDatabaseHas('office_tat_configuration_versions', [
        'office_category' => 'CENRO', 'office' => 'CENRO Mati', 'period_key' => '2026-H2', 'version' => 1, 'volume_target' => 12,
    ]);

    $this->actingAs($user)->post(route('office-tat.configuration.store'), [...$payload, 'volume_target' => null])
        ->assertSessionHasErrors('volume_target');
    expect(DB::table('office_tat_configuration_versions')->count())->toBe(1);

    $this->actingAs($user)->get(route('office-tat.index', [
        'category' => 'CENRO', 'processing_period' => '2026-H2', 'as_of_date' => '2026-10-09',
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/OfficeTat/Index')
        ->where('analytics.category', 'CENRO')
        ->where('analytics.options.offices', function ($offices): bool {
            expect(collect($offices))->toContain('CENRO Mati')->not->toContain('PENRO Davao Oriental');
            return true;
        })
        ->where('analytics.rows', function ($rows): bool {
            $mati = collect($rows)->firstWhere('office', 'CENRO Mati');
            expect($mati)->not->toBeNull()
                ->and($mati['volume_target'])->toBe(12)
                ->and($mati['configuration_version'])->toBe(1)
                ->and($mati['tat_compliance_percent'])->toBeNull()
                ->and($mati['score'])->toBeNull();
            return true;
        }));

    $this->actingAs($user)->post(route('office-tat.configuration.store'), [...$payload, 'volume_target' => 18])->assertRedirect();
    $this->assertDatabaseHas('office_tat_configuration_versions', [
        'office_category' => 'CENRO', 'office' => 'CENRO Mati', 'period_key' => '2026-H2', 'version' => 2, 'volume_target' => 18,
    ]);

    $this->actingAs($user)->get(route('office-tat.index', ['category' => 'PENRO', 'processing_period' => '2026-H2']))->assertUnprocessable();
    $this->actingAs($user)->post(route('office-tat.configuration.store'), [...$payload, 'office_category' => 'PENRO'])
        ->assertSessionHasErrors('office_category');
    expect(DB::table('office_tat_configuration_versions')->count())->toBe(2);
});

test('Conservation source views project original historical dates without changing live routing status', function (): void {
    $this->withoutVite();
    $user = historicalEncodingActor();
    $area = historicalEncodingArea($user);

    $makeRecord = function (bool $historical, string $activity) use ($user, $area): ConservationReportSubmission {
        $record = ConservationReportSubmission::query()->create([
            'workflow_key' => 'regular_pamb',
            'protected_area_id' => $area->id,
            'target_office' => 'CENRO Mati',
            'activity_name' => $activity,
            'document_type' => 'Minutes',
            'reporting_period' => 'Quarter 1',
            'date_conducted' => '2026-01-05',
            'date_accomplished' => '2026-01-05',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        if ($historical) $record->forceFill(['is_historical' => true])->save();

        return $record->fresh();
    };

    $completed = $makeRecord(true, 'Completed historical PAMB');
    $incomplete = $makeRecord(true, 'Incomplete historical PAMB');
    $live = $makeRecord(false, 'Live PAMB without a receipt');
    $encodedAt = CarbonImmutable::parse('2026-10-10 02:55:00', 'Asia/Manila');

    foreach ([
        [$completed, [
            'date_report_released_cenro' => '2026-01-06',
            'date_received_penro' => '2026-01-07',
            'date_endorsed_regional' => '2026-01-08',
        ]],
        [$incomplete, [
            'date_report_released_cenro' => '2026-01-06',
        ]],
    ] as [$record, $milestones]) {
        HistoricalReportOrigin::query()->create([
            'source' => 'conservation',
            'source_record_id' => $record->id,
            'workflow_key' => 'regular_pamb',
            'encoded_by' => $user->id,
            'idempotency_key' => (string) str()->uuid(),
            'original_milestones' => ['milestones' => $milestones],
            'encoded_at' => $encodedAt,
        ]);
    }

    $this->actingAs($user)->get(route('conservation-reports.index', ['workflow' => 'regular_pamb']))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('ConservationReports/Index')
            ->where('submissions.data', function ($rows) use ($completed, $incomplete, $live): bool {
                $byId = collect($rows)->keyBy('id');
                $completeRow = $byId->get($completed->id);
                $incompleteRow = $byId->get($incomplete->id);
                $liveRow = $byId->get($live->id);

                expect($completeRow['date_report_released_cenro'])->toBe('2026-01-06')
                    ->and($completeRow['date_received_penro'])->toBe('2026-01-07')
                    ->and($completeRow['date_endorsed_regional'])->toBe('2026-01-08')
                    ->and($completeRow['submission_status'])->toBe('Completed')
                    ->and($completeRow['timeliness'])->toBe('Outstanding')
                    ->and($completeRow['historical_evidence']['basis'])->toBe('Manual historical evidence')
                    ->and($incompleteRow['date_report_released_cenro'])->toBe('2026-01-06')
                    ->and($incompleteRow['date_received_penro'])->toBeNull()
                    ->and($incompleteRow['submission_status'])->toBe('Historical evidence incomplete')
                    ->and($incompleteRow['days_complied'])->toBe('Missing historical evidence')
                    ->and($liveRow['submission_status'])->toBe('Pending Submission by CENRO')
                    ->and($liveRow['historical_evidence'] ?? null)->toBeNull();

                return true;
            }));

    $this->get(route('settings.historical-reports.show', ['source' => 'conservation', 'record' => $completed->id]))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/HistoricalReportDetail')
            ->where('record.source_id', $completed->id)
            ->where('record.workflow_key', 'regular_pamb')
            ->where('record.module_url', route('conservation-reports.index', 'regular_pamb'))
            ->where('record.timeliness.status', 'Outstanding')
            ->where('record.timeliness.receipt_date', '2026-01-07')
            ->where('record.historical_date_gaps.processing.elapsed_calendar_days', 1)
            ->where('record.activity_timeline.0.date', '2026-01-05')
            ->where('record.detail.submission_status', 'Completed')
            ->where('record.detail.date_endorsed_regional', '2026-01-08'));

    $this->get(route('settings.historical-reports.show', ['source' => 'conservation', 'record' => $incomplete->id]))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('record.timeline.1.date', null)
            ->where('record.historical_date_gaps.handoff.status', 'missing')
            ->where('record.historical_date_gaps.handoff.elapsed_calendar_days', null));

    $this->get(route('settings.historical-reports.show', ['source' => 'conservation', 'record' => $completed->id]), ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('record.source', 'conservation')
        ->assertJsonPath('record.timeline.1.date', '2026-01-07')
        ->assertJsonPath('record.timeliness.status', 'Outstanding')
        ->assertJsonPath('record.detail.submission_status', 'Completed');

    expect($completed->fresh()->date_received_penro)->toBeNull()
        ->and($incomplete->fresh()->date_report_released_cenro)->toBeNull()
        ->and(DB::table('document_routing_events')->where('source_type', 'conservation')->whereIn('source_id', [$completed->id, $incomplete->id, $live->id])->count())->toBe(0)
        ->and(DB::table('pamb_routing_events')->whereIn('conservation_report_submission_id', [$completed->id, $incomplete->id, $live->id])->count())->toBe(0);
});

test('calendar projects protected historical evidence dates without routing writes or source ID collisions', function (): void {
    $this->withoutVite();
    Storage::fake('local');
    $user = historicalEncodingActor();
    $user->givePermissionTo(
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
        Permission::findOrCreate('bams.view', 'web'),
        Permission::findOrCreate('technical-reports.view', 'web'),
    );
    $area = historicalEncodingArea($user);

    $this->actingAs($user)->post(route('settings.historical-reports.store'), historicalBmsPayload($area, (string) str()->uuid()))
        ->assertRedirect(route('settings.historical-reports.index'))
        ->assertSessionHasNoErrors();
    $historicalBms = BmsReportSubmission::query()->sole();
    $bmsOrigin = HistoricalReportOrigin::query()->where('source', 'bms')->where('source_record_id', $historicalBms->id)->firstOrFail();
    $liveBms = BmsReportSubmission::query()->create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Live calendar report',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_conducted' => '2026-01-20',
        'date_accomplished' => '2026-01-22',
        'date_received_penro' => '2026-01-25',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $historicalBams = new BamsReportSubmission;
    $historicalBams->forceFill([
        'id' => $historicalBms->id,
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Second source with same numeric ID',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_conducted_ranges' => [['from' => '2026-01-11', 'to' => '2026-01-13']],
        'date_conducted' => '2026-01-11',
        'date_accomplished' => '2026-01-14',
        'is_historical' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ])->saveOrFail();
    HistoricalReportOrigin::query()->create([
        'source' => 'bams',
        'source_record_id' => $historicalBams->id,
        'workflow_key' => 'bams',
        'encoded_by' => $user->id,
        'idempotency_key' => (string) str()->uuid(),
        'original_milestones' => ['milestones' => ['date_received_penro' => '2026-01-16']],
        'encoded_at' => CarbonImmutable::parse('2026-10-10 10:00:00', 'Asia/Manila'),
    ]);

    $otherArea = historicalEncodingArea($user);
    $otherHistorical = BmsReportSubmission::query()->create([
        'protected_area_id' => $otherArea->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Historical report outside the viewer PA scope',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_conducted' => '2026-01-12',
        'date_accomplished' => '2026-01-14',
        'is_historical' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    HistoricalReportOrigin::query()->create([
        'source' => 'bms',
        'source_record_id' => $otherHistorical->id,
        'workflow_key' => 'bms',
        'encoded_by' => $user->id,
        'idempotency_key' => (string) str()->uuid(),
        'original_milestones' => ['milestones' => ['date_received_penro' => '2026-01-17']],
        'encoded_at' => CarbonImmutable::parse('2026-10-10 10:00:00', 'Asia/Manila'),
    ]);

    $unplaced = new BmsReportSubmission;
    $unplaced->forceFill([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Historical report without a date anchor',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'is_historical' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ])->saveOrFail();
    HistoricalReportOrigin::query()->create([
        'source' => 'bms',
        'source_record_id' => $unplaced->id,
        'workflow_key' => 'bms',
        'encoded_by' => $user->id,
        'idempotency_key' => (string) str()->uuid(),
        'original_milestones' => null,
        'encoded_at' => CarbonImmutable::parse('2026-10-10 10:01:00', 'Asia/Manila'),
    ]);

    $this->actingAs($user)->post(route('settings.historical-reports.store'), historicalEngpPayload((string) str()->uuid()))
        ->assertRedirect(route('settings.historical-reports.index'))
        ->assertSessionHasNoErrors();
    $historicalEngp = EngpReportSubmission::query()->sole();

    $sideEffectTables = ['document_routing_events', 'pamb_routing_events', 'submission_routing_snapshots', 'notifications', 'jobs', 'engp_report_release_events', 'document_archives'];
    $sideEffectsBefore = collect($sideEffectTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    $historicalBmsBefore = $historicalBms->fresh()->getAttributes();
    $bmsOriginBefore = $bmsOrigin->fresh()->getAttributes();
    $calendar = app(CalendarMovEventService::class);

    $januaryEvents = $calendar->events($user, CarbonImmutable::parse('2026-01-01', 'Asia/Manila'), 'bms', $area->id);
    $bmsEvent = $januaryEvents->firstWhere('source_key', 'historical:bms:'.$historicalBms->id);
    $liveEvent = $januaryEvents->firstWhere('source_key', 'bms:'.$liveBms->id);
    $sharedIdEvents = $calendar->events($user, CarbonImmutable::parse('2026-01-01', 'Asia/Manila'), null, $area->id)
        ->filter(fn (array $event): bool => (int) ($event['source_record_id'] ?? 0) === (int) $historicalBms->id);
    $januarySummary = $calendar->yearSummary($user, CarbonImmutable::parse('2026-01-01', 'Asia/Manila'), 'bms', $area->id);
    $pamoViewer = User::factory()->create(['section' => 'PAMO', 'protected_area_id' => $area->id]);
    $pamoViewer->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $pamoEvents = $calendar->events($pamoViewer, CarbonImmutable::parse('2026-01-01', 'Asia/Manila'), 'bms');
    $pamoSummary = $calendar->yearSummary($pamoViewer, CarbonImmutable::parse('2026-01-01', 'Asia/Manila'), 'bms');
    $engpEvent = $calendar->events($user, CarbonImmutable::parse('2025-03-01', 'Asia/Manila'), 'engp')
        ->firstWhere('source_key', 'historical:engp:'.$historicalEngp->id);

    expect($bmsEvent['event_date'])->toBe('2026-01-10')
        ->and($bmsEvent['calendar_basis'])->toBe('Date Conducted (earliest recorded range start)')
        ->and($bmsEvent['activity_ranges'])->toBe([['from' => '2026-01-10', 'to' => '2026-01-10']])
        ->and($bmsEvent['is_historical'])->toBeTrue()
        ->and($bmsEvent['original_source'])->toBe('bms')
        ->and($bmsEvent['source_record_id'])->toBe($historicalBms->id)
        ->and($bmsEvent['detail_url'])->toBe(route('settings.historical-reports.show', ['source' => 'bms', 'record' => $historicalBms->id]))
        ->and($bmsEvent['attachment']['url'])->toBeNull()
        ->and($liveEvent['event_date'])->toBe('2026-01-25')
        ->and($liveEvent['is_historical'])->toBeFalse()
        ->and($sharedIdEvents->pluck('source_key')->all())->toContain('historical:bms:'.$historicalBms->id, 'historical:bams:'.$historicalBams->id)
        ->and($sharedIdEvents->pluck('source_key')->unique())->toHaveCount(2)
        ->and($pamoEvents->pluck('source_key')->all())->toContain('historical:bms:'.$historicalBms->id, 'bms:'.$liveBms->id)
        ->and($pamoEvents->pluck('source_record_id')->all())->not->toContain($otherHistorical->id)
        ->and($pamoSummary['overview']['historical_reports'])->toBe(1)
        ->and($pamoSummary['months']['01']['submitted_movs'])->toBe(1)
        ->and($januaryEvents->pluck('source_key')->all())->not->toContain('historical:bms:'.$unplaced->id)
        ->and($januarySummary['months']['01']['historical_reports'])->toBe(1)
        ->and($januarySummary['months']['01']['submitted_movs'])->toBe(1)
        ->and($engpEvent['event_date'])->toBe('2025-03-18')
        ->and($engpEvent['calendar_basis'])->toBe('Original PENRO receipt');

    $this->get(route('business-calendar.index', ['view' => 'month', 'month' => '2026-01', 'module' => 'bms', 'protected_area_id' => $area->id]))
        ->assertOk()->assertInertia(fn ($page) => $page
            ->component('Calendar/Index')
            ->where('movEvents', fn ($events): bool => collect($events)->contains(fn ($event): bool => ($event['source_key'] ?? null) === 'historical:bms:'.$historicalBms->id && ($event['event_date'] ?? null) === '2026-01-10')));

    $this->get(route('settings.historical-reports.show', ['source' => 'bms', 'record' => $historicalBms->id]))->assertOk();
    $this->actingAs($user)->post(route('submission-tracking.transition', [
        'source' => 'bms', 'record' => $historicalBms->id, 'stage' => 'receive_at_cenro',
    ]), ['date' => '2026-01-25'])->assertSessionHasErrors('submission');

    expect($historicalBms->fresh()->getAttributes())->toBe($historicalBmsBefore)
        ->and($bmsOrigin->fresh()->getAttributes())->toBe($bmsOriginBefore)
        ->and(collect($sideEffectTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all())->toBe($sideEffectsBefore);
});
