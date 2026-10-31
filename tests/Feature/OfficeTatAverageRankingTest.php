<?php

use App\Models\DocumentRoutingEvent;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\OrganizationalOffice;
use App\Models\OfficeTatConfigurationVersion;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\HistoricalReports\ConservationHistoricalEvidenceProjection;
use App\Services\HistoricalReports\HistoricalDateGapProjector;
use App\Services\OfficeTatAnalyticsService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->actor = User::factory()->create(['unit_assignment' => 'development', 'section' => 'CDS']);
    $this->actor->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $this->actor->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    $office = OrganizationalOffice::query()
        ->where('name', 'CENRO Baganga')
        ->orWhere('code', 'cenro_baganga')
        ->first();
    if ($office) {
        $office->update(['name' => 'CENRO Baganga', 'office_type' => 'CENRO', 'is_active' => true]);
    } else {
        OrganizationalOffice::query()->create([
            'code' => 'cenro_baganga_office_tat_test',
            'name' => 'CENRO Baganga',
            'office_type' => 'CENRO',
            'is_active' => true,
        ]);
    }
});

function officeTatEngpRecord(User $actor, string $periodKey): EngpReportSubmission
{
    return EngpReportSubmission::query()->create([
        'workflow_key' => 'cbep',
        'office' => 'CENRO Baganga',
        'section_name' => 'NGP',
        'activity_name' => 'Community-Based Employment Program (CBEP)',
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => $periodKey,
        'period_label' => $periodKey,
        'deadline_submission' => substr($periodKey, 0, 7).'-20',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ]);
}

function officeTatEvent(User $actor, EngpReportSubmission $record, string $key, string $at, string $from, string $to): DocumentRoutingEvent
{
    return DocumentRoutingEvent::query()->create([
        'source_type' => 'engp',
        'source_id' => $record->getKey(),
        'workflow_key' => 'cbep',
        'event_key' => $key,
        'from_stage' => $key === 'received' || $key === 'correction_received' ? 'penro' : 'cenro',
        'to_stage' => $key === 'received' || $key === 'correction_received' ? 'cenro' : 'penro',
        'from_office' => $from,
        'to_office' => $to,
        'occurred_at' => $at,
        'recorded_by' => $actor->getKey(),
    ]);
}

function officeTatAnalytics(User $actor): array
{
    return app(OfficeTatAnalyticsService::class)->analytics($actor, [
        'category' => 'CENRO',
        'processing_period' => '2026-H1',
        'as_of_date' => '2026-03-31',
        'workflow' => 'engp:cbep',
        'evidence_type' => 'live',
    ]);
}

function officeTatAnalyticsWithChunks(User $actor, int $documentBatchSize, int $eventChunkSize, array $filters): array
{
    return app()->makeWith(OfficeTatAnalyticsService::class, [
        'documentBatchSize' => $documentBatchSize,
        'eventChunkSize' => $eventChunkSize,
    ])->analytics($actor, $filters);
}

function officeTatInsertRows(string $table, iterable $rows, int $chunkSize = 60): void
{
    $chunk = [];
    foreach ($rows as $row) {
        $chunk[] = $row;
        if (count($chunk) < $chunkSize) continue;
        DB::table($table)->insert($chunk);
        $chunk = [];
    }
    if ($chunk !== []) DB::table($table)->insert($chunk);
}

test('eligible PA historical report counts separately from measured PAMO TAT using its original March receipt', function (): void {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Manila'));

    try {
        $area = ProtectedArea::query()->create([
            'name' => 'Office TAT Historical Report Test Area', 'short_name' => 'OTHTA',
            'category' => 'Protected Landscape', 'municipality' => 'Baganga',
            'province' => 'Davao Oriental', 'region' => 'Region XI', 'pamo' => 'PAMO Historical Test',
            'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(),
        ]);
        $recordId = DB::table('conservation_report_submissions')->insertGetId([
            'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
            'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB',
            'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
            'date_conducted' => '2026-03-18', 'date_accomplished' => '2026-03-18',
            'is_historical' => true, 'created_by' => $this->actor->getKey(),
            'updated_by' => $this->actor->getKey(), 'created_at' => '2026-10-10 12:00:00',
            'updated_at' => '2026-10-10 12:00:00',
        ]);
        HistoricalReportOrigin::query()->create([
            'source' => 'conservation', 'source_record_id' => $recordId,
            'workflow_key' => 'regular_pamb', 'encoded_by' => $this->actor->getKey(),
            'idempotency_key' => 'office-tat-pamo-historical-'.$recordId,
            'original_milestones' => ['provenance' => 'manually_recorded_original_dates', 'milestones' => [
                'date_report_released_cenro' => '2026-03-25',
                'date_received_penro' => '2026-03-25',
                'date_endorsed_regional' => '2026-03-31',
            ]],
            'encoded_at' => '2026-10-10 12:00:00',
        ]);
        $sourceRecord = ConservationReportSubmission::query()->with('protectedArea')->findOrFail($recordId);
        $origin = HistoricalReportOrigin::query()->where('source', 'conservation')->where('source_record_id', $recordId)->firstOrFail();
        $sourceProjection = app(ConservationHistoricalEvidenceProjection::class)->project($sourceRecord, $origin);
        expect($sourceProjection['days_complied'])->toBe(4)
            ->and($sourceProjection['penro_delay'])->toBe(6);

        $filters = [
            'category' => 'PAMO', 'processing_period' => '2026', 'as_of_date' => '2026-10-10',
            'workflow' => 'conservation:regular_pamb', 'protected_area_id' => $area->getKey(),
            'reporting_period' => 'Quarter 1', 'evidence_type' => 'historical',
        ];
        $annual = app(OfficeTatAnalyticsService::class)->analytics($this->actor, $filters);
        $semester = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$filters, 'processing_period' => '2026-H1']);
        $secondSemester = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$filters, 'processing_period' => '2026-H2']);
        $otherSourcePeriod = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$filters, 'reporting_period' => 'Quarter 2']);

        $annualRow = collect($annual['rows'])->firstWhere('office', 'PAMO Historical Test');
        $semesterRow = collect($semester['rows'])->firstWhere('office', 'PAMO Historical Test');
        $secondSemesterRow = collect($secondSemester['rows'])->firstWhere('office', 'PAMO Historical Test');
        $filteredRow = collect($otherSourcePeriod['rows'])->firstWhere('office', 'PAMO Historical Test');

        expect($annualRow['historical_report_count'])->toBe(1)
            ->and($annualRow['manual_received'])->toBe(1)
            ->and($annualRow['manual_historical_tat_completed_documents'])->toBe(0)
            ->and($annualRow['manual_historical_tat_average_elapsed_seconds'])->toBeNull()
            ->and($annualRow['manual_historical_preparation_completed_documents'])->toBe(1)
            ->and($annualRow['manual_historical_preparation_average_elapsed_calendar_days'])->toBe(0.0)
            ->and($annualRow['manual_historical_processing_completed_documents'])->toBe(1)
            ->and($annualRow['manual_historical_processing_average_elapsed_calendar_days'])->toBe(7.0)
            ->and($annualRow['manual_historical_handoff_completed_documents'])->toBe(1)
            ->and($annualRow['manual_historical_handoff_average_elapsed_calendar_days'])->toBe(0.0)
            ->and($semesterRow['historical_report_count'])->toBe(1)
            ->and($semesterRow['manual_historical_tat_completed_documents'])->toBe(0)
            ->and($semesterRow['manual_historical_processing_average_elapsed_calendar_days'])->toBe(7.0)
            ->and($secondSemesterRow['historical_report_count'])->toBe(0)
            ->and($secondSemesterRow['manual_historical_processing_completed_documents'])->toBe(0)
            ->and($filteredRow['historical_report_count'])->toBe(0);

        $pamoActor = User::factory()->create([
            'unit_assignment' => 'conservation', 'section' => 'PAMO',
            'protected_area_id' => $area->getKey(), 'office_designated' => 'PAMO Historical Test',
        ]);
        expect(fn () => app(OfficeTatAnalyticsService::class)->analytics($pamoActor, $filters))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('historical gap reads leave live event averages and persisted routing evidence unchanged', function (): void {
    $live = officeTatEngpRecord($this->actor, '2026-01');
    officeTatEvent($this->actor, $live, 'received', '2026-01-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $live, 'forwarded', '2026-01-05 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');

    $historical = officeTatEngpRecord($this->actor, '2026-02');
    $historical->forceFill(['is_historical' => true])->save();
    HistoricalReportOrigin::query()->create([
        'source' => 'engp', 'source_record_id' => $historical->id, 'workflow_key' => 'cbep',
        'encoded_by' => $this->actor->id, 'idempotency_key' => 'live-contract-historical-'.$historical->id,
        'original_milestones' => ['milestones' => [
            'date_report_released_cenro' => '2026-02-04', 'date_received_penro' => '2026-02-07',
        ]],
        'encoded_at' => '2026-10-10 12:00:00',
    ]);
    $originBefore = HistoricalReportOrigin::query()->where('source', 'engp')->where('source_record_id', $historical->id)->firstOrFail()->original_milestones;

    $sideEffectTables = ['document_routing_events', 'pamb_routing_events', 'submission_routing_snapshots', 'notifications', 'jobs', 'engp_report_release_events', 'document_archives'];
    $beforeCounts = collect($sideEffectTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    $liveFilters = ['category' => 'CENRO', 'processing_period' => '2026-H1', 'as_of_date' => '2026-03-31', 'workflow' => 'engp:cbep'];
    $liveOnly = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$liveFilters, 'evidence_type' => 'live']);
    $withHistorical = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$liveFilters, 'evidence_type' => 'all']);
    $liveRow = collect($liveOnly['rows'])->firstWhere('office', 'CENRO Baganga');
    $combinedRow = collect($withHistorical['rows'])->firstWhere('office', 'CENRO Baganga');

    expect([
        'average_seconds' => $combinedRow['tat_average_elapsed_seconds'],
        'average_days' => $combinedRow['tat_average_elapsed_days'],
        'duration_total' => $combinedRow['tat_elapsed_seconds_total'],
        'completed_documents' => $combinedRow['tat_completed_documents'],
        'pending' => $combinedRow['pending'],
        'missing' => $combinedRow['tat_missing_evidence'],
    ])->toBe([
        'average_seconds' => $liveRow['tat_average_elapsed_seconds'],
        'average_days' => $liveRow['tat_average_elapsed_days'],
        'duration_total' => $liveRow['tat_elapsed_seconds_total'],
        'completed_documents' => $liveRow['tat_completed_documents'],
        'pending' => $liveRow['pending'],
        'missing' => $liveRow['tat_missing_evidence'],
    ])
        ->and($historical->fresh()->date_received_penro)->toBeNull()
        ->and(HistoricalReportOrigin::query()->where('source', 'engp')->where('source_record_id', $historical->id)->firstOrFail()->original_milestones)->toBe($originBefore)
        ->and(collect($sideEffectTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all())->toBe($beforeCounts);
});

test('live CENRO routing average is exposed separately and uses accepted event intervals', function (): void {
    $record = officeTatEngpRecord($this->actor, '2026-01');
    officeTatEvent($this->actor, $record, 'received', '2026-01-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $record, 'forwarded', '2026-01-05 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
    $this->actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));

    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-31 12:00:00', 'Asia/Manila'));

    try {
        $this->withoutVite()->actingAs($this->actor)
            ->get('/admin/office-tat/routing-average?processing_period=2026-H1&as_of_date=2026-04-30&return_unit=development&unit=conservation&category=PAMO&workflow=conservation%3Aother&evidence_type=historical')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/OfficeTat/LiveRoutingAverage')
                ->where('liveAnalytics.category', 'CENRO')
                ->where('liveAnalytics.evidence_type', 'live')
                ->where('liveAnalytics.processing_period', '2026-H1')
                ->where('liveAnalytics.as_of_date', '2026-03-31')
                ->where('filters.processing_period', '2026-H1')
                ->where('filters.as_of_date', '2026-03-31')
                ->where('filters.return_unit', 'development')
                ->where('liveAnalytics.rows', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool =>
                    $row['office'] === 'CENRO Baganga'
                        && $row['documents_received'] === 1
                        && $row['tat_completed_documents'] === 1
                        && (float) $row['tat_elapsed_seconds_total'] === 172800.0
                        && (float) $row['tat_average_elapsed_seconds'] === 172800.0
                ))
                ->missing('liveAnalytics.rows.0.historical_report_count')
                ->missing('liveAnalytics.rows.0.score'));
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('visible live routing navigation and route use the same CENRO authorization scope', function (): void {
    $reader = User::factory()->create([
        'section' => 'CENRO_CDS_CHIEF',
        'office_designated' => 'CENRO Baganga',
        'unit_assignment' => null,
        'is_approved' => true,
        'is_active' => true,
    ]);
    $reader->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));

    $this->withoutVite()->actingAs($reader)
        ->get('/admin/office-tat?unit=development&processing_period=2026-H2&as_of_date=2026-10-26')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('selectedUnit', 'development')
            ->where('canViewLiveRoutingAverage', true));

    $this->actingAs($reader)
        ->get('/admin/office-tat/routing-average?processing_period=2026-H2&as_of_date=2026-10-26&return_unit=development')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('liveAnalytics.category', 'CENRO')
            ->where('liveAnalytics.evidence_type', 'live')
            ->where('filters.return_unit', 'development'));

    $pamo = User::factory()->create(['section' => 'PAMO', 'unit_assignment' => null, 'is_approved' => true, 'is_active' => true]);
    $pamo->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    $this->actingAs($pamo)
        ->get('/admin/office-tat/routing-average?processing_period=2026-H2&as_of_date=2026-10-26')
        ->assertForbidden();
});

test('direct-to-PENRO historical reports do not receive a synthetic CENRO release interval', function (): void {
    $area = ProtectedArea::query()->create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS',
        'category' => 'Protected Landscape', 'municipality' => 'San Isidro',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'pamo' => 'PAMO Direct Route Test',
        'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(),
    ]);
    $record = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
        'date_conducted' => '2026-03-18', 'date_accomplished' => '2026-03-18',
    ]);
    $record->setRelation('protectedArea', $area);
    $origin = new HistoricalReportOrigin([
        'workflow_key' => 'regular_pamb',
        'original_milestones' => ['milestones' => [
            'date_report_released_cenro' => null, 'date_received_penro' => '2026-03-25',
        ]],
    ]);

    $gaps = app(HistoricalDateGapProjector::class)->project('conservation', $record, $origin);

    expect($gaps['processing']['status'])->toBe('not_applicable')
        ->and($gaps['processing']['elapsed_calendar_days'])->toBeNull()
        ->and($gaps['handoff']['status'])->toBe('not_applicable')
        ->and($gaps['handoff']['elapsed_calendar_days'])->toBeNull();
});

test('average uses one summed correction-cycle sample per document without SLA or target gating', function (): void {
    $oneDay = officeTatEngpRecord($this->actor, '2026-01');
    officeTatEvent($this->actor, $oneDay, 'received', '2026-01-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $oneDay, 'forwarded', '2026-01-04 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');

    $correctionCycles = officeTatEngpRecord($this->actor, '2026-02');
    officeTatEvent($this->actor, $correctionCycles, 'received', '2026-02-02 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $correctionCycles, 'returned_for_correction', '2026-02-03 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
    officeTatEvent($this->actor, $correctionCycles, 'correction_received', '2026-02-04 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $correctionCycles, 'forwarded', '2026-02-06 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
    officeTatEvent($this->actor, $correctionCycles, 'forwarded', '2026-02-06 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');

    $laterPendingCycle = officeTatEngpRecord($this->actor, '2026-03');
    officeTatEvent($this->actor, $laterPendingCycle, 'received', '2026-03-02 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $laterPendingCycle, 'forwarded', '2026-03-03 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
    officeTatEvent($this->actor, $laterPendingCycle, 'correction_received', '2026-03-04 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');

    $row = collect(officeTatAnalytics($this->actor)['rows'])->firstWhere('office', 'CENRO Baganga');

    expect($row['event_derived_tat_completed_documents'])->toBe(2)
        ->and($row['tat_elapsed_seconds_total'])->toBe(4 * 86400.0)
        ->and($row['tat_average_elapsed_seconds'])->toBe(2 * 86400.0)
        ->and($row['tat_average_elapsed_days'])->toBe(2.0)
        ->and($row['pending'])->toBe(1)
        ->and($row['tat_compliance_percent'])->toBeNull()
        ->and($row['volume_target'])->toBeNull()
        ->and($row['volume_percent'])->toBeNull()
        ->and($row['score'])->toBeNull();
});

test('timeliness uses the authoritative PENRO receipt instead of an earlier CENRO intake', function (): void {
    $record = officeTatEngpRecord($this->actor, '2026-01');
    officeTatEvent($this->actor, $record, 'received', '2026-01-05 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $record, 'received', '2026-01-25 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');

    $row = collect(officeTatAnalytics($this->actor)['rows'])->firstWhere('office', 'CENRO Baganga');

    expect($row['documents_received'])->toBe(1)
        ->and($row['timeliness_eligible'])->toBe(1)
        ->and($row['timeliness_on_time'])->toBe(0)
        ->and($row['missing_required_evidence'])->toBe(0)
        ->and($row['overdue'])->toBe(1);
});

test('a measured zero-second handoff is completed while an open receipt remains pending', function (): void {
    $instant = officeTatEngpRecord($this->actor, '2026-01');
    officeTatEvent($this->actor, $instant, 'received', '2026-01-05 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $instant, 'forwarded', '2026-01-05 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');

    $pending = officeTatEngpRecord($this->actor, '2026-02');
    officeTatEvent($this->actor, $pending, 'received', '2026-02-05 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');

    $row = collect(officeTatAnalytics($this->actor)['rows'])->firstWhere('office', 'CENRO Baganga');

    expect($row['event_derived_tat_completed_documents'])->toBe(1)
        ->and($row['tat_elapsed_seconds_total'])->toBe(0.0)
        ->and($row['tat_average_elapsed_seconds'])->toBe(0.0)
        ->and($row['pending'])->toBe(1)
        ->and($row['tat_average_elapsed_days'])->toBe(0.0);
});

test('a malformed handoff before the cohort receipt is counted as missing, not as a zero or completed sample', function (): void {
    $record = officeTatEngpRecord($this->actor, '2026-01');
    officeTatEvent($this->actor, $record, 'forwarded', '2026-01-03 08:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
    officeTatEvent($this->actor, $record, 'received', '2026-01-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');

    $row = collect(officeTatAnalytics($this->actor)['rows'])->firstWhere('office', 'CENRO Baganga');

    expect($row['event_derived_tat_completed_documents'])->toBe(0)
        ->and($row['tat_average_elapsed_seconds'])->toBeNull()
        ->and($row['tat_missing_evidence'])->toBe(1)
        ->and($row['pending'])->toBe(1);
});

test('chunked Office TAT source loading preserves a CENRO users development-office scope', function (): void {
    $allowed = officeTatEngpRecord($this->actor, '2026-01');
    officeTatEvent($this->actor, $allowed, 'received', '2026-01-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
    officeTatEvent($this->actor, $allowed, 'forwarded', '2026-01-04 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');

    $outside = officeTatEngpRecord($this->actor, '2026-02');
    $outside->update(['office' => 'CENRO Mati']);
    officeTatEvent($this->actor, $outside, 'received', '2026-02-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Mati');
    officeTatEvent($this->actor, $outside, 'forwarded', '2026-02-04 09:00:00', 'CENRO Mati', 'PENRO Davao Oriental');

    $limited = User::factory()->create([
        'unit_assignment' => null,
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $limited->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    $analytics = officeTatAnalyticsWithChunks($limited, 1, 1, [
        'category' => 'CENRO', 'processing_period' => '2026-H1', 'as_of_date' => '2026-03-31',
        'workflow' => 'engp:cbep', 'evidence_type' => 'live',
    ]);
    $row = collect($analytics['rows'])->firstWhere('office', 'CENRO Baganga');

    expect(collect($analytics['rows'])->pluck('office')->all())->toBe(['CENRO Baganga'])
        ->and($row['documents_received'])->toBe(1)
        ->and($row['event_derived_tat_completed_documents'])->toBe(1)
        ->and($row['tat_elapsed_seconds_total'])->toBe(86400.0);
});

test('general receipt aggregation is complete beyond 10000 events and keeps interval state across event pages', function (): void {
    $count = 10001;
    $firstRecordId = ((int) DB::table('engp_report_submissions')->max('id')) + 1;
    $timestamp = '2026-10-10 00:00:00';
    $actorId = $this->actor->getKey();

    officeTatInsertRows('engp_report_submissions', (function () use ($count, $actorId, $timestamp): Generator {
        for ($index = 0; $index < $count; $index++) {
            $period = 'bulk-'.str_pad((string) $index, 5, '0', STR_PAD_LEFT);
            yield [
                'workflow_key' => 'cbep',
                'office' => 'CENRO Baganga',
                'section_name' => 'NGP',
                'activity_name' => 'Community-Based Employment Program (CBEP)',
                'document_type' => 'Monthly Report',
                'reporting_year' => 2026,
                'period_key' => $period,
                'period_label' => $period,
                'deadline_submission' => '2026-01-20',
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
    })());

    officeTatInsertRows('document_routing_events', (function () use ($count, $firstRecordId, $actorId, $timestamp): Generator {
        for ($index = 0; $index < $count; $index++) {
            yield [
                'source_type' => 'engp', 'source_id' => $firstRecordId + $index, 'workflow_key' => 'cbep',
                'event_key' => 'received', 'from_stage' => 'penro', 'to_stage' => 'cenro',
                'from_office' => 'PENRO Davao Oriental', 'to_office' => 'CENRO Baganga',
                'occurred_at' => $index === $count - 1 ? '2026-01-05 09:00:00' : '2026-01-03 09:00:00',
                'recorded_by' => $actorId, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ];
        }
        for ($index = 0; $index < $count; $index++) {
            yield [
                'source_type' => 'engp', 'source_id' => $firstRecordId + $index, 'workflow_key' => 'cbep',
                'event_key' => 'forwarded', 'from_stage' => 'cenro', 'to_stage' => 'penro',
                'from_office' => 'CENRO Baganga', 'to_office' => 'PENRO Davao Oriental',
                'occurred_at' => $index === $count - 1 ? '2026-01-07 09:00:00' : '2026-01-04 09:00:00',
                'recorded_by' => $actorId, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ];
        }
    })());

    $comparisonRecord = officeTatEngpRecord($this->actor, '2026-03');
    $comparisonRecord->update(['office' => 'CENRO Mati']);
    officeTatEvent($this->actor, $comparisonRecord, 'received', '2026-01-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Mati');
    officeTatEvent($this->actor, $comparisonRecord, 'forwarded', '2026-01-04 09:00:05', 'CENRO Mati', 'PENRO Davao Oriental');

    $filters = [
        'category' => 'CENRO', 'processing_period' => '2026-H1', 'as_of_date' => '2026-03-31',
        'workflow' => 'engp:cbep', 'evidence_type' => 'live',
    ];
    $first = officeTatAnalyticsWithChunks($this->actor, 300, 499, $filters);
    $second = officeTatAnalyticsWithChunks($this->actor, 137, 127, $filters);
    $firstRow = collect($first['rows'])->firstWhere('office', 'CENRO Baganga');
    $secondRow = collect($second['rows'])->firstWhere('office', 'CENRO Baganga');
    $comparisonRow = collect($first['rows'])->firstWhere('office', 'CENRO Mati');
    $expectedSeconds = (10000 + 2) * 86400.0;

    expect($first['source_evidence_rows'])->toBe(10002)
        ->and($firstRow['documents_received'])->toBe(10001)
        ->and($firstRow['event_derived_received'])->toBe(10001)
        ->and($firstRow['event_derived_tat_completed_documents'])->toBe(10001)
        ->and($firstRow['tat_elapsed_seconds_total'])->toBe($expectedSeconds)
        ->and($firstRow['tat_average_elapsed_seconds'])->toBe($expectedSeconds / 10001)
        ->and($comparisonRow['tat_average_elapsed_seconds'])->toBe(86405.0)
        ->and($firstRow['tat_average_elapsed_seconds'])->toBeGreaterThan($comparisonRow['tat_average_elapsed_seconds'])
        ->and($firstRow['tat_missing_evidence'])->toBe(0)
        ->and($firstRow['pending'])->toBe(0)
        ->and($secondRow['documents_received'])->toBe($firstRow['documents_received'])
        ->and($secondRow['event_derived_tat_completed_documents'])->toBe($firstRow['event_derived_tat_completed_documents'])
        ->and($secondRow['tat_elapsed_seconds_total'])->toBe($firstRow['tat_elapsed_seconds_total']);
});

test('PAMB receipt aggregation is complete beyond 5000 events and retains the late eligible record', function (): void {
    $count = 5001;
    $timestamp = '2026-10-10 00:00:00';
    $area = ProtectedArea::query()->create([
        'name' => 'Office TAT PAMB Cap Test Area', 'category' => 'Protected Landscape',
        'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'pamo' => 'PAMO Cap Test Area', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(),
    ]);
    $firstRecordId = ((int) DB::table('conservation_report_submissions')->max('id')) + 1;
    $actorId = $this->actor->getKey();

    officeTatInsertRows('conservation_report_submissions', (function () use ($count, $area, $actorId, $timestamp): Generator {
        for ($index = 0; $index < $count; $index++) {
            yield [
                'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
                'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB',
                'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
                'date_accomplished' => '2026-01-01', 'created_by' => $actorId,
                'updated_by' => $actorId, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ];
        }
    })());

    officeTatInsertRows('pamb_routing_events', (function () use ($count, $firstRecordId, $actorId, $timestamp): Generator {
        for ($index = 0; $index < $count; $index++) {
            yield [
                'conservation_report_submission_id' => $firstRecordId + $index,
                'workflow_key' => 'regular_pamb', 'stage_key' => PambRoutingTimelineService::RECORDS_RECEIVED,
                'occurred_at' => $index === $count - 1 ? '2026-02-15 09:00:00' : '2026-01-05 09:00:00',
                'recorded_by' => $actorId, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ];
        }
    })());

    $filters = [
        'category' => 'PAMO', 'processing_period' => '2026-H1', 'as_of_date' => '2026-03-31',
        'workflow' => 'conservation:regular_pamb', 'evidence_type' => 'live',
    ];
    $first = officeTatAnalyticsWithChunks($this->actor, 300, 499, $filters);
    $second = officeTatAnalyticsWithChunks($this->actor, 113, 73, $filters);
    $firstRow = collect($first['rows'])->firstWhere('office', 'PAMO Cap Test Area');
    $secondRow = collect($second['rows'])->firstWhere('office', 'PAMO Cap Test Area');

    expect($first['source_evidence_rows'])->toBe(5001)
        ->and($firstRow['timeliness_eligible'])->toBe(5001)
        ->and($firstRow['timeliness_on_time'])->toBe(5000)
        ->and($firstRow['overdue'])->toBe(1)
        ->and($firstRow['timeliness_percent'])->toBe(100 * 5000 / 5001)
        ->and($secondRow['timeliness_eligible'])->toBe($firstRow['timeliness_eligible'])
        ->and($secondRow['timeliness_on_time'])->toBe($firstRow['timeliness_on_time'])
        ->and($secondRow['overdue'])->toBe($firstRow['overdue']);
});

test('live Regular PAMB TAT follows accepted office custody, separates review verdicts, and applies the Manila as-of day', function (): void {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 01:00:00', 'Asia/Manila'));

    try {
        $area = ProtectedArea::query()->create([
            'name' => 'Office TAT Live PAMB Boundary Test Area', 'short_name' => 'OTLPB',
            'category' => 'Protected Landscape', 'municipality' => 'Baganga',
            'province' => 'Davao Oriental', 'region' => 'Region XI', 'pamo' => 'PAMO APL Test',
            'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(),
        ]);
        $recordId = DB::table('conservation_report_submissions')->insertGetId([
            'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
            'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB',
            'document_type' => 'Minutes', 'reporting_period' => 'Quarter 4',
            'date_conducted' => '2026-10-05', 'date_accomplished' => '2026-10-05',
            'is_historical' => false, 'created_by' => $this->actor->getKey(),
            'updated_by' => $this->actor->getKey(), 'created_at' => '2026-10-05 12:00:00',
            'updated_at' => '2026-10-05 12:00:00',
        ]);

        // Current Regular PAMB custody transitions are stored in the shared event table.
        // Their office identities mirror the production route profile; activity dates and
        // review verdicts remain separate from the accepted receipt/handoff interval.
        foreach ([
            ['forwarded', 'cenro_preparation', 'transit_to_cenro_chief', 'CENRO CDS Focal Person', 'CENRO CDS Chief', '2026-10-11 00:02:00'],
            ['received', 'transit_to_cenro_chief', 'cenro_chief', 'CENRO CDS Focal Person', 'CENRO CDS Chief', '2026-10-11 00:05:00'],
            ['endorsed', 'cenro_chief', 'transit_to_cenro_records', 'CENRO CDS Chief', 'CENRO Records Unit', '2026-10-11 00:13:00'],
            ['received', 'transit_to_cenro_records', 'cenro_records', 'CENRO CDS Chief', 'CENRO Records Unit', '2026-10-11 00:15:00'],
            ['forwarded', 'cenro_records', 'transit_to_penro_records', 'CENRO Records Unit', 'PENRO Records Unit', '2026-10-11 00:16:00'],
        ] as [$eventKey, $fromStage, $toStage, $fromOffice, $toOffice, $occurredAt]) {
            DocumentRoutingEvent::query()->create([
                'source_type' => 'conservation', 'source_id' => $recordId,
                'workflow_key' => 'regular_pamb', 'event_key' => $eventKey,
                'from_stage' => $fromStage, 'to_stage' => $toStage,
                'from_office' => $fromOffice, 'to_office' => $toOffice,
                'occurred_at' => $occurredAt, 'recorded_by' => $this->actor->getKey(),
            ]);
        }

        PambRoutingEvent::query()->create([
            'conservation_report_submission_id' => $recordId,
            'workflow_key' => 'regular_pamb',
            'stage_key' => PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL,
            'occurred_at' => '2026-10-11 00:20:00', 'recorded_by' => $this->actor->getKey(),
        ]);

        $baseFilters = [
            'processing_period' => '2026', 'workflow' => 'conservation:regular_pamb',
            'protected_area_id' => $area->getKey(), 'reporting_period' => 'Quarter 4',
            'evidence_type' => 'live',
        ];
        $service = app(OfficeTatAnalyticsService::class);
        $beforeMidnightCutoff = $service->analytics($this->actor, [
            ...$baseFilters, 'category' => 'CENRO', 'as_of_date' => '2026-10-10',
        ]);
        $cenro = $service->analytics($this->actor, [
            ...$baseFilters, 'category' => 'CENRO', 'as_of_date' => '2026-10-11',
        ]);
        $pamo = $service->analytics($this->actor, [
            ...$baseFilters, 'category' => 'PAMO', 'as_of_date' => '2026-10-11',
        ]);
        $beforeRow = collect($beforeMidnightCutoff['rows'])->firstWhere('office', 'CENRO Baganga');
        $cenroRow = collect($cenro['rows'])->firstWhere('office', 'CENRO Baganga');
        $pamoRow = collect($pamo['rows'])->firstWhere('office', 'PAMO APL Test');

        expect($beforeRow['documents_received'])->toBe(0)
            ->and($beforeRow['tat_completed_documents'])->toBe(0)
            ->and($beforeRow['tat_average_elapsed_seconds'])->toBeNull()
            ->and($cenroRow['documents_received'])->toBe(1)
            ->and($cenroRow['tat_completed_documents'])->toBe(1)
            ->and($cenroRow['tat_average_elapsed_seconds'])->toBe(660.0)
            ->and($cenroRow['tat_missing_evidence'])->toBe(0)
            ->and($pamoRow['documents_received'])->toBe(0)
            ->and($pamoRow['tat_completed_documents'])->toBe(0)
            ->and($pamoRow['tat_average_elapsed_seconds'])->toBeNull();
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('assessment keys default to the Philippine semester and normalize legacy periods', function (): void {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 09:00:00', 'Asia/Manila'));

    try {
        $service = app(OfficeTatAnalyticsService::class);
        expect($service->canonicalPeriodKey(null))->toBe('2026-H2')
            ->and($service->canonicalPeriodKey('2026-Q1'))->toBe('2026-H1')
            ->and($service->canonicalPeriodKey('2026-Q2'))->toBe('2026-H1')
            ->and($service->canonicalPeriodKey('2026-Q3'))->toBe('2026-H2')
            ->and($service->canonicalPeriodKey('2026-Q4'))->toBe('2026-H2')
            ->and($service->canonicalPeriodKey('2026-02'))->toBe('2026-H1')
            ->and($service->canonicalPeriodKey('2026-11'))->toBe('2026-H2')
            ->and($service->canonicalPeriodKey('2026-13'))->toBe('2026-H2');

        $data = $service->analytics($this->actor, ['category' => 'CENRO', 'as_of_date' => '2026-10-10', 'evidence_type' => 'live']);
        expect($data['processing_period'])->toBe('2026-H2')
            ->and($data['processing_period_label'])->toBe('2nd Semester 2026 · Jul–Dec')
            ->and($data['processing_period_start'])->toBe('2026-07-01')
            ->and($data['processing_period_end'])->toBe('2026-12-31')
            ->and($data['assessment_period']['type'])->toBe('semestral')
            ->and($data['assessment_period']['semester'])->toBe('H2');

        $this->actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
        $this->actingAs($this->actor)->get(route('office-tat.index', ['unit' => 'development', 'processing_period' => '2026-Q4']))
            ->assertOk()->assertInertia(fn ($page) => $page->where('recordedAssessment.period_key', '2026-H2'));
        $this->actingAs($this->actor)->get(route('office-tat.index', ['unit' => 'development', 'processing_period' => '2026-11']))
            ->assertOk()->assertInertia(fn ($page) => $page->where('recordedAssessment.period_key', '2026-H2'));
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('half-year and annual receipt windows are half-open Manila calendar periods, including leap day', function (): void {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-02 12:00:00', 'Asia/Manila'));

    try {
        $at = [
            ['2026-01', '2026-01-01 00:00:00', '2026-01-02 00:00:00'],
            ['2026-06', '2026-06-30 23:59:59.999999', '2026-07-01 01:00:00'],
            ['2026-07', '2026-07-01 00:00:00', '2026-07-02 00:00:00'],
            ['2026-12', '2026-12-31 23:59:59.999999', '2027-01-01 01:00:00'],
            ['2027-01', '2027-01-01 00:00:00', '2027-01-02 00:00:00'],
        ];
        foreach ($at as [$period, $receipt, $handoff]) {
            $record = officeTatEngpRecord($this->actor, $period);
            officeTatEvent($this->actor, $record, 'received', $receipt, 'PENRO Davao Oriental', 'CENRO Baganga');
            officeTatEvent($this->actor, $record, 'forwarded', $handoff, 'CENRO Baganga', 'PENRO Davao Oriental');
        }

        $filters = ['category' => 'CENRO', 'as_of_date' => '2027-01-02', 'workflow' => 'engp:cbep', 'evidence_type' => 'live'];
        $h1 = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$filters, 'processing_period' => '2026-H1']);
        $h2 = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$filters, 'processing_period' => '2026-H2']);
        $annual = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$filters, 'processing_period' => '2026']);
        $rowFor = static fn (array $data): array => collect($data['rows'])->firstWhere('office', 'CENRO Baganga');

        expect($rowFor($h1)['documents_received'])->toBe(2)
            ->and($rowFor($h2)['documents_received'])->toBe(2)
            ->and($rowFor($annual)['documents_received'])->toBe(4)
            ->and($rowFor($annual)['event_derived_tat_completed_documents'])->toBe(4)
            ->and($annual['assessment_period']['type'])->toBe('annual')
            ->and($annual['assessment_period']['semester'])->toBeNull();

        $leap = officeTatEngpRecord($this->actor, '2024-02');
        officeTatEvent($this->actor, $leap, 'received', '2024-02-29 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
        officeTatEvent($this->actor, $leap, 'forwarded', '2024-03-01 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
        $leapYear = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$filters, 'processing_period' => '2024']);
        expect($rowFor($leapYear)['documents_received'])->toBe(1)
            ->and($rowFor($leapYear)['tat_elapsed_seconds_total'])->toBe(86400.0)
            ->and($leapYear['processing_period_start'])->toBe('2024-01-01')
            ->and($leapYear['processing_period_end'])->toBe('2024-12-31');
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('annual TAT recomputes from document durations and ignores old quarter configuration', function (): void {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-02 12:00:00', 'Asia/Manila'));

    try {
        OfficeTatConfigurationVersion::query()->create([
            'office_category' => 'CENRO', 'office' => 'CENRO Baganga', 'period_key' => '2026-Q1',
            'volume_target' => 25, 'score_weights' => ['timeliness' => 0.5, 'tat_compliance' => 0.35, 'volume' => 0.15],
            'version' => 1, 'configured_by' => $this->actor->getKey(),
        ]);
        foreach ([
            ['2026-01', '2026-01-02 09:00:00', '2026-01-03 09:00:00'],
            ['2026-02', '2026-02-02 09:00:00', '2026-02-05 09:00:00'],
            ['2026-07', '2026-07-02 09:00:00', '2026-07-07 09:00:00'],
        ] as [$period, $receipt, $handoff]) {
            $record = officeTatEngpRecord($this->actor, $period);
            officeTatEvent($this->actor, $record, 'received', $receipt, 'PENRO Davao Oriental', 'CENRO Baganga');
            officeTatEvent($this->actor, $record, 'forwarded', $handoff, 'CENRO Baganga', 'PENRO Davao Oriental');
        }
        $base = ['category' => 'CENRO', 'as_of_date' => '2027-01-01', 'workflow' => 'engp:cbep', 'evidence_type' => 'live'];
        $h1 = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$base, 'processing_period' => '2026-H1']);
        $h2 = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$base, 'processing_period' => '2026-H2']);
        $annual = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [...$base, 'processing_period' => '2026']);
        $h1Row = collect($h1['rows'])->firstWhere('office', 'CENRO Baganga');
        $h2Row = collect($h2['rows'])->firstWhere('office', 'CENRO Baganga');
        $annualRow = collect($annual['rows'])->firstWhere('office', 'CENRO Baganga');

        expect($h1Row['tat_average_elapsed_days'])->toBe(2.0)
            ->and($h2Row['tat_average_elapsed_days'])->toBe(5.0)
            ->and($annualRow['tat_average_elapsed_days'])->toBe(3.0)
            ->and($annualRow['tat_elapsed_seconds_total'])->toBe(9 * 86400.0)
            ->and($annualRow['configuration_version'])->toBeNull()
            ->and($annualRow['volume_target'])->toBeNull()
            ->and($annualRow['score'])->toBeNull()
            ->and(OfficeTatConfigurationVersion::query()->count())->toBe(1)
            ->and(OfficeTatConfigurationVersion::query()->value('period_key'))->toBe('2026-Q1');
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('PENRO Office TAT viewers can review both office categories without global admin access', function (): void {
    $viewer = User::factory()->create([
        'section' => 'PENRO_CDS_CHIEF',
        'office_designated' => 'PENRO Davao Oriental',
        'unit_assignment' => null,
        'is_approved' => true,
        'is_active' => true,
    ]);
    $viewer->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));

    foreach (['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon'] as $index => $name) {
        $office = OrganizationalOffice::query()->where('name', $name)->first();
        if ($office) {
            $office->update(['office_type' => 'CENRO', 'is_active' => true]);
        } else {
            OrganizationalOffice::query()->create([
                'code' => 'office_tat_penro_scope_'.$index,
                'name' => $name,
                'office_type' => 'CENRO',
                'is_active' => true,
            ]);
        }
    }

    ProtectedArea::query()->create([
        'name' => 'Office TAT PENRO Review Scope Test Area',
        'short_name' => 'OTPRST',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'pamo' => 'PAMO PENRO Review Scope Test',
        'created_by' => $this->actor->getKey(),
        'updated_by' => $this->actor->getKey(),
    ]);

    $organization = app(\App\Services\Authorization\OrganizationalAccessService::class);
    expect($organization->isGlobal($viewer))->toBeFalse()
        ->and($viewer->can('office-tat.view'))->toBeTrue()
        ->and($viewer->can('office-tat.configure'))->toBeFalse()
        ->and($organization->effectiveUnits($viewer))->toBe(['conservation', 'development']);

    $service = app(OfficeTatAnalyticsService::class);
    $filters = ['processing_period' => '2026-H2', 'as_of_date' => '2026-10-11', 'evidence_type' => 'live'];
    $pamo = $service->analytics($viewer, [...$filters, 'category' => 'PAMO']);
    $cenro = $service->analytics($viewer, [...$filters, 'category' => 'CENRO']);

    expect(array_column($pamo['rows'], 'office'))->toContain('PAMO PENRO Review Scope Test')
        ->and($pamo['pamo_preparation']['available'])->toBeFalse()
        ->and(array_column($cenro['rows'], 'office'))
        ->toContain('CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon');
});

test('H1 receipt cohort can complete a correction cycle after June without truncating its duration', function (): void {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-02 12:00:00', 'Asia/Manila'));

    try {
        $record = officeTatEngpRecord($this->actor, '2026-06');
        officeTatEvent($this->actor, $record, 'received', '2026-06-29 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
        officeTatEvent($this->actor, $record, 'returned_for_correction', '2026-07-01 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
        officeTatEvent($this->actor, $record, 'correction_received', '2026-07-03 09:00:00', 'PENRO Davao Oriental', 'CENRO Baganga');
        officeTatEvent($this->actor, $record, 'forwarded', '2026-07-05 09:00:00', 'CENRO Baganga', 'PENRO Davao Oriental');
        $data = app(OfficeTatAnalyticsService::class)->analytics($this->actor, [
            'category' => 'CENRO', 'processing_period' => '2026-H1', 'as_of_date' => '2026-07-05',
            'workflow' => 'engp:cbep', 'evidence_type' => 'live',
        ]);
        $row = collect($data['rows'])->firstWhere('office', 'CENRO Baganga');

        expect($row['documents_received'])->toBe(1)
            ->and($row['event_derived_tat_completed_documents'])->toBe(1)
            ->and($row['tat_elapsed_seconds_total'])->toBe(4 * 86400.0)
            ->and($row['pending'])->toBe(0)
            ->and($row['tat_missing_evidence'])->toBe(0);
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});
