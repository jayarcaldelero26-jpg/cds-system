<?php

use App\Models\DocumentRoutingEvent;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\OfficeTatAnalyticsService;
use App\Services\OfficeTat\LiveReportSourceRegistry;
use App\Services\OfficeTatComplianceAssessmentService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Database\Seeders\ModuleDefinitionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function officeTatCoverageActor(bool $grantAllSourceViews = true): User
{
    $actor = User::factory()->create(['unit_assignment' => 'development', 'section' => 'CDS']);
    $actor->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    $permissions = ['office-tat.view'];
    if ($grantAllSourceViews) {
        $tracking = app(SubmissionTrackingService::class);
        foreach ($tracking->reportSourceModels() as $source => $_model) {
            $ability = $tracking->source($source)['ability'] ?? null;
            if (is_string($ability) && str_ends_with($ability, '.update')) {
                $permissions[] = substr($ability, 0, -strlen('.update')).'.view';
            }
        }
    }

    $actor->givePermissionTo(collect($permissions)->unique()->map(fn (string $name) => Permission::findOrCreate($name, 'web')));

    return $actor;
}

function officeTatCoverageArea(User $actor): ProtectedArea
{
    return ProtectedArea::query()->create([
        'name' => 'Office TAT Coverage Fixture Area',
        'short_name' => 'OTCOV',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'pamo' => 'PAMO TAT Coverage Fixture',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ]);
}

function officeTatCoverageEnsureOffice(string $name): void
{
    $code = match ($name) {
        'CENRO Baganga' => 'office_tat_coverage_baganga',
        'CENRO Mati' => 'office_tat_coverage_mati',
        default => 'office_tat_coverage_'.Str::slug($name, '_'),
    };
    $existing = OrganizationalOffice::query()
        ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
        ->whereRaw('LOWER(TRIM(office_type)) = ?', ['cenro'])
        ->orderBy('id')
        ->first();
    if ($existing) {
        $existing->forceFill(['is_active' => true])->save();
        return;
    }

    OrganizationalOffice::query()->updateOrCreate(
        ['code' => $code],
        ['name' => $name, 'office_type' => 'CENRO', 'is_active' => true],
    );
}

function officeTatCoverageInsertSource(User $actor, int $protectedAreaId, string $source, string $workflow, int $id, int $periodNumber = 1): int
{
    $modelClass = app(SubmissionTrackingService::class)->source($source)['model'];
    $table = (new $modelClass)->getTable();
    $attributes = [
        'id' => $id,
        'workflow_key' => $workflow,
        'protected_area_id' => $protectedAreaId,
        'target_office' => 'CENRO Baganga',
        'office' => 'CENRO Baganga',
        'section_name' => 'NGP',
        'activity_name' => 'Office TAT coverage fixture',
        'document_type' => 'Monthly Report',
        'reporting_period' => 'Quarter 1',
        'reporting_year' => 2026,
        'period_key' => sprintf('2026-%02d', $periodNumber),
        'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-01-20',
        'semester' => '1st Semester',
        'quarter' => 'Quarter 1',
        'date_conducted' => '2026-01-02',
        'date_accomplished' => '2026-01-03',
        'date_report_released_cenro' => null,
        'date_received_penro' => null,
        'date_endorsed_regional' => null,
        'station_name' => 'Office TAT coverage fixture station',
        'location' => 'CENRO Baganga',
        'timestamps' => null,
        'reporting_month' => 1,
        'total_collected' => '0.00',
        'plan_type' => 'Protected Area Management Plan',
        'title' => 'Office TAT coverage fixture plan',
        'version' => '1.0',
        'prepared_year' => 2026,
        'status' => 'Active',
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
        'is_historical' => false,
        'deleted_at' => null,
        'created_at' => '2026-01-03 09:00:00',
        'updated_at' => '2026-01-03 09:00:00',
    ];
    $attributes = array_intersect_key($attributes, array_flip(Schema::getColumnListing($table)));
    DB::table($table)->insert($attributes);

    return $id;
}

function officeTatCoverageEvent(User $actor, string $source, int $sourceId, string $workflow, string $eventKey, string $fromOffice, string $toOffice, string $occurredAt): void
{
    $received = in_array($eventKey, ['received', 'correction_received'], true);
    DocumentRoutingEvent::query()->create([
        'source_type' => $source,
        'source_id' => $sourceId,
        'workflow_key' => $workflow,
        'event_key' => $eventKey,
        'from_stage' => $received ? 'penro' : 'cenro',
        'to_stage' => $received ? 'cenro' : 'penro',
        'from_office' => $fromOffice,
        'to_office' => $toOffice,
        'occurred_at' => $occurredAt,
        'recorded_by' => $actor->getKey(),
    ]);
}

function officeTatCoverageAddCompletedVisit(User $actor, string $source, int $sourceId, string $workflow, string $office, string $receivedAt, string $forwardedAt): void
{
    officeTatCoverageEvent($actor, $source, $sourceId, $workflow, 'received', 'PENRO Davao Oriental', $office, $receivedAt);
    officeTatCoverageEvent($actor, $source, $sourceId, $workflow, 'forwarded', $office, 'PENRO Davao Oriental', $forwardedAt);
}

function officeTatCoverageAnalytics(User $actor, array $filters = []): array
{
    return app(OfficeTatAnalyticsService::class)->analytics($actor, [
        'category' => 'CENRO',
        'processing_period' => '2026-H1',
        'as_of_date' => '2026-06-30',
        'evidence_type' => 'live',
        'workflow' => 'all',
        ...$filters,
    ]);
}

function officeTatCoverageMeasureQueries(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $result = $callback();
        return [$result, count(DB::getQueryLog())];
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

test('recorded Report Performance covers current native sources and excludes legacy Management Plans', function (): void {
    $actor = officeTatCoverageActor();
    $area = officeTatCoverageArea($actor);
    officeTatCoverageEnsureOffice('CENRO Baganga');
    $this->seed(ModuleDefinitionSeeder::class);

    $tracking = app(SubmissionTrackingService::class);
    $nativeSources = app(LiveReportSourceRegistry::class)->native();
    $expectedConservationSources = [];
    $sequence = 0;
    foreach ($nativeSources as $source) {
        $definition = $tracking->source($source);
        $modelClass = $tracking->reportSourceModels()[$source];
        $id = 24000 + ++$sequence;
        officeTatCoverageInsertSource($actor, (int) $area->getKey(), $source, $source, $id, $sequence);
        $table = (new $modelClass)->getTable();
        if (Schema::hasColumn($table, 'date_received_penro')) {
            DB::table($table)->where('id', $id)->update(['date_received_penro' => '2026-01-14']);
        }
        if (($definition['archive_unit'] ?? null) === 'Conservation Unit') $expectedConservationSources[] = $source;
    }

    $legacyPlanId = 24999;
    officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'management-plans', 'management_plans', $legacyPlanId, 99);
    officeTatCoverageEvent($actor, 'management-plans', $legacyPlanId, 'management_plans', 'received', 'PENRO Davao Oriental', 'CENRO Baganga', '2026-01-14 09:00:00');

    $service = app(OfficeTatComplianceAssessmentService::class);
    $conservation = $service->recordedAssessments($actor, 'conservation', '2026-H1', '2026-06-30');
    $paRow = collect($conservation['rows'])->firstWhere('participant_key', 'pa:'.$area->getKey());
    $conservationCoverage = collect($conservation['module_coverage'])->keyBy('source_key');
    $development = $service->recordedAssessments($actor, 'development', '2026-H1', '2026-06-30');
    $cenroRow = collect($development['rows'])->firstWhere('name', 'CENRO Baganga');
    $developmentCoverage = collect($development['module_coverage'])->keyBy('source_key');

    expect($nativeSources)->toHaveCount(7)
        ->and($nativeSources)->not->toContain('management-plans')
        ->and($expectedConservationSources)->toHaveCount(7)
        ->and($conservation['activity_count'])->toBe(7)
        ->and($conservation['received_activity_count'])->toBe(7)
        ->and($paRow['due_count'])->toBe(7)
        ->and($development['activity_count'])->toBe(0)
        ->and($development['received_activity_count'])->toBe(0)
        ->and($cenroRow['due_count'])->toBe(0)
        ->and($developmentCoverage->has('management-plans'))->toBeFalse();

    foreach ($expectedConservationSources as $source) {
        $item = $conservationCoverage->get($source);
        expect($item)->not->toBeNull()
            ->and($item['live_record_count'])->toBe(1)
            ->and($item['eligible_recorded_count'])->toBe(1)
            ->and($item['received_count'])->toBe(1)
            ->and($item['due_count'])->toBe(1);
    }
});
test('Office TAT covers every source family and generic workflow registered for the viewer', function (): void {
    $actor = officeTatCoverageActor();
    $area = officeTatCoverageArea($actor);
    officeTatCoverageEnsureOffice('CENRO Baganga');
    $this->seed(ModuleDefinitionSeeder::class);

    $tracking = app(SubmissionTrackingService::class);
    $sourceModels = $tracking->reportSourceModels();
    $conservationWorkflows = app(ConservationReportWorkflowRegistry::class)->all();
    $engpWorkflows = app(EngpReportWorkflowRegistry::class)->all();
    $nativeSourceKeys = app(LiveReportSourceRegistry::class)->native();

    expect(array_keys($sourceModels))->toBe($tracking->archiveSourceKeys())
        ->and(array_diff($nativeSourceKeys, array_keys($sourceModels)))->toBe([])
        ->and(array_keys($sourceModels))->toContain('conservation', 'engp');

    $fixtures = [];
    $sequence = 1;
    foreach ($nativeSourceKeys as $source) {
        $_modelClass = $sourceModels[$source];
        $id = 8000 + $sequence;
        officeTatCoverageInsertSource($actor, (int) $area->getKey(), $source, $source, $id, $sequence);
        officeTatCoverageAddCompletedVisit($actor, $source, $id, $source, 'CENRO Baganga', '2026-01-05 09:00:00', '2026-01-06 09:00:00');
        $fixtures[] = ['source' => $source, 'workflow' => $source, 'id' => $id, 'period' => $sequence];
        $sequence++;
    }

    foreach ($conservationWorkflows as $workflow) {
        $id = 8000 + $sequence;
        officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'conservation', $workflow['key'], $id, $sequence);
        officeTatCoverageAddCompletedVisit($actor, 'conservation', $id, $workflow['key'], 'CENRO Baganga', '2026-01-05 09:00:00', '2026-01-06 09:00:00');
        $fixtures[] = ['source' => 'conservation', 'workflow' => $workflow['key'], 'id' => $id, 'period' => $sequence];
        $sequence++;
    }

    foreach ($engpWorkflows as $workflow) {
        $id = 8000 + $sequence;
        officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'engp', $workflow['key'], $id, $sequence);
        officeTatCoverageAddCompletedVisit($actor, 'engp', $id, $workflow['key'], 'CENRO Baganga', '2026-01-05 09:00:00', '2026-01-06 09:00:00');
        $fixtures[] = ['source' => 'engp', 'workflow' => $workflow['key'], 'id' => $id, 'period' => $sequence];
        $sequence++;
    }

    $legacyPlanId = 18999;
    officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'management-plans', 'management_plans', $legacyPlanId, 99);
    officeTatCoverageAddCompletedVisit($actor, 'management-plans', $legacyPlanId, 'management_plans', 'CENRO Baganga', '2026-01-05 09:00:00', '2026-01-06 09:00:00');

    $data = officeTatCoverageAnalytics($actor);
    $row = collect($data['rows'])->firstWhere('office', 'CENRO Baganga');
    $expectedSamples = count($fixtures);
    $options = collect($data['options']['workflows'])->keyBy('value');

    expect($nativeSourceKeys)->toHaveCount(7)
        ->and($nativeSourceKeys)->not->toContain('management-plans')
        ->and($options->has('management-plans:management-plans'))->toBeFalse()
        ->and($row['documents_received'])->toBe($expectedSamples)
        ->and($row['event_derived_received'])->toBe($expectedSamples)
        ->and($row['tat_completed_documents'])->toBe($expectedSamples)
        ->and($row['tat_elapsed_seconds_total'])->toBe($expectedSamples * 86400.0)
        ->and($row['tat_average_elapsed_seconds'])->toBe(86400.0)
        ->and($row['pending'])->toBe(0)
        ->and($row['tat_missing_evidence'])->toBe(0)
        ->and($data['source_evidence_rows'])->toBe($expectedSamples)
        ->and(array_sum(array_column($data['rows'], 'tat_completed_documents')))->toBe($expectedSamples);

    foreach ($fixtures as $fixture) {
        $selector = $fixture['source'].':'.$fixture['workflow'];
        expect($options->has($selector))->toBeTrue();
        $selected = officeTatCoverageAnalytics($actor, ['workflow' => $selector]);
        $selectedRow = collect($selected['rows'])->firstWhere('office', 'CENRO Baganga');
        expect($selectedRow['documents_received'])->toBe(1)
            ->and($selectedRow['tat_completed_documents'])->toBe(1)
            ->and($selectedRow['tat_average_elapsed_seconds'])->toBe(86400.0);
    }

    $selectedWorkflow = collect($conservationWorkflows)->firstWhere('key', 'twc_meetings');
    if ($selectedWorkflow) {
        expect($options['conservation:twc_meetings']['label'])->toBe($selectedWorkflow['label']);
        $filtered = officeTatCoverageAnalytics($actor, [
            'workflow' => 'conservation:twc_meetings',
            'protected_area_id' => $area->getKey(),
            'reporting_period' => 'Quarter 1',
        ]);
        $filteredRow = collect($filtered['rows'])->firstWhere('office', 'CENRO Baganga');
        expect($filteredRow['documents_received'])->toBe(1)
            ->and($filteredRow['tat_completed_documents'])->toBe(1);
    }

    [$singlePopulation, $singleQueries] = officeTatCoverageMeasureQueries(fn (): array => officeTatCoverageAnalytics($actor));
    foreach ($fixtures as $index => $fixture) {
        $id = 50000 + $index;
        officeTatCoverageInsertSource($actor, (int) $area->getKey(), $fixture['source'], $fixture['workflow'], $id, 100 + $fixture['period']);
        officeTatCoverageAddCompletedVisit($actor, $fixture['source'], $id, $fixture['workflow'], 'CENRO Baganga', '2026-01-05 09:00:00', '2026-01-06 09:00:00');
    }
    [$doublePopulation, $doubleQueries] = officeTatCoverageMeasureQueries(fn (): array => officeTatCoverageAnalytics($actor));
    $doubleRow = collect($doublePopulation['rows'])->firstWhere('office', 'CENRO Baganga');

    expect($singlePopulation['source_evidence_rows'])->toBe($expectedSamples)
        ->and($doubleRow['tat_completed_documents'])->toBe($expectedSamples * 2)
        ->and($doubleQueries)->toBe($singleQueries);
});

test('TAT retains composite source identity and counts each CENRO office that received the same report', function (): void {
    $actor = officeTatCoverageActor();
    $area = officeTatCoverageArea($actor);
    officeTatCoverageEnsureOffice('CENRO Baganga');
    officeTatCoverageEnsureOffice('CENRO Mati');

    $conservationId = officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'conservation', 'homestay', 777, 1);
    $engpId = officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'engp', 'cbep', 777, 2);
    officeTatCoverageAddCompletedVisit($actor, 'conservation', $conservationId, 'homestay', 'CENRO Baganga', '2026-01-02 09:00:00', '2026-01-03 09:00:00');
    officeTatCoverageAddCompletedVisit($actor, 'conservation', $conservationId, 'homestay', 'CENRO Mati', '2026-01-04 09:00:00', '2026-01-06 09:00:00');
    officeTatCoverageAddCompletedVisit($actor, 'engp', $engpId, 'cbep', 'CENRO Baganga', '2026-02-02 09:00:00', '2026-02-03 09:00:00');

    $data = officeTatCoverageAnalytics($actor);
    $baganga = collect($data['rows'])->firstWhere('office', 'CENRO Baganga');
    $mati = collect($data['rows'])->firstWhere('office', 'CENRO Mati');

    expect($conservationId)->toBe($engpId)
        ->and($baganga['documents_received'])->toBe(2)
        ->and($baganga['tat_completed_documents'])->toBe(2)
        ->and($baganga['tat_elapsed_seconds_total'])->toBe(2 * 86400.0)
        ->and($mati['documents_received'])->toBe(1)
        ->and($mati['tat_completed_documents'])->toBe(1)
        ->and($mati['tat_average_elapsed_seconds'])->toBe(2 * 86400.0)
        ->and(array_sum(array_column($data['rows'], 'tat_completed_documents')))->toBe(3);
});

test('an office visit outside the receipt cohort does not enter that period TAT', function (): void {
    $actor = officeTatCoverageActor();
    $area = officeTatCoverageArea($actor);
    officeTatCoverageEnsureOffice('CENRO Baganga');
    officeTatCoverageEnsureOffice('CENRO Mati');

    $id = officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'conservation', 'homestay', 8801, 1);
    officeTatCoverageAddCompletedVisit($actor, 'conservation', $id, 'homestay', 'CENRO Baganga', '2026-06-29 09:00:00', '2026-06-30 09:00:00');
    officeTatCoverageAddCompletedVisit($actor, 'conservation', $id, 'homestay', 'CENRO Mati', '2026-07-01 09:00:00', '2026-07-02 09:00:00');

    $data = officeTatCoverageAnalytics($actor, ['as_of_date' => '2026-07-02']);
    $baganga = collect($data['rows'])->firstWhere('office', 'CENRO Baganga');
    $mati = collect($data['rows'])->firstWhere('office', 'CENRO Mati');

    expect($baganga['documents_received'])->toBe(1)
        ->and($baganga['tat_completed_documents'])->toBe(1)
        ->and($mati['documents_received'])->toBe(0)
        ->and($mati['tat_completed_documents'])->toBe(0)
        ->and($mati['pending'])->toBe(0);
});

test('an archived final report remains in event-derived Office TAT', function (): void {
    $actor = officeTatCoverageActor();
    $area = officeTatCoverageArea($actor);
    officeTatCoverageEnsureOffice('CENRO Baganga');
    $id = officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'conservation', 'homestay', 9901, 1);
    officeTatCoverageAddCompletedVisit($actor, 'conservation', $id, 'homestay', 'CENRO Baganga', '2026-03-01 09:00:00', '2026-03-02 09:00:00');

    $receipt = FinalDocumentReceipt::query()->create([
        'source_type' => 'conservation', 'source_id' => $id, 'workflow_key' => 'homestay',
        'release_evidence_type' => 'regional_endorsement', 'release_evidence_key' => 'fixture-release-9901',
        'received_by' => $actor->getKey(), 'receiving_category' => 'PENRO_CDS_FOCAL',
        'receiving_office' => 'PENRO Davao Oriental', 'physically_received_at' => '2026-03-03 09:00:00',
        'recorded_at' => '2026-03-03 09:00:00',
    ]);
    FinalDocumentArchiveManifest::query()->create([
        'receipt_id' => $receipt->getKey(), 'source_type' => 'conservation', 'source_id' => $id,
        'workflow_key' => 'homestay', 'archive_phase' => FinalDocumentArchiveManifest::PHASE,
        'revision' => 1, 'intent_key' => (string) Str::uuid(), 'archive_status' => 'ARCHIVED',
        'signature_confirmation' => true, 'signature_set_sha256' => str_repeat('a', 64),
        'signature_confirmed_by' => $actor->getKey(), 'signature_confirmed_at' => '2026-03-03 09:01:00',
        'created_by' => $actor->getKey(), 'finalized_at' => '2026-03-03 09:02:00',
    ]);

    $data = officeTatCoverageAnalytics($actor);
    $row = collect($data['rows'])->firstWhere('office', 'CENRO Baganga');

    expect($receipt->source_id)->toBe($id)
        ->and($row['documents_received'])->toBe(1)
        ->and($row['tat_completed_documents'])->toBe(1)
        ->and($row['tat_average_elapsed_seconds'])->toBe(86400.0);
});

test('an archived PAMO correction receipt starts one in-period cohort after an earlier handoff', function (): void {
    $actor = officeTatCoverageActor();
    $area = officeTatCoverageArea($actor);
    $id = officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'conservation', 'regular_pamb', 9902, 7);
    $reportTable = 'conservation_report_submissions';
    $reportMetadata = [
        'reporting_period' => 'Quarter 4',
        'period_label' => 'Quarter 4',
        'date_conducted' => '2026-10-19',
        'date_received_penro' => '2026-10-19',
        'date_endorsed_regional' => '2026-10-19',
        'is_historical' => false,
    ];
    DB::table($reportTable)->where('id', $id)->update(array_intersect_key($reportMetadata, array_flip(Schema::getColumnListing($reportTable))));

    $addEvent = function (string $eventKey, string $fromStage, string $toStage, string $fromOffice, string $toOffice, string $occurredAt) use ($actor, $id): DocumentRoutingEvent {
        return DocumentRoutingEvent::query()->create([
            'source_type' => 'conservation', 'source_id' => $id, 'workflow_key' => 'regular_pamb',
            'event_key' => $eventKey, 'from_stage' => $fromStage, 'to_stage' => $toStage,
            'from_office' => $fromOffice, 'to_office' => $toOffice, 'occurred_at' => $occurredAt,
            'recorded_by' => $actor->getKey(),
        ]);
    };

    $addEvent('forwarded', 'pamo_origin', 'transit_to_penro_records', $area->pamo, 'PENRO Records Unit', '2026-10-19 09:03:06');
    $addEvent('received', 'transit_to_penro_records', 'penro_records', 'CENRO Records Unit', 'PENRO Records Unit', '2026-10-19 09:05:24');
    $addEvent('returned_for_correction', 'penro_records', 'pamo_origin', 'PENRO Records Unit', $area->pamo, '2026-10-19 09:05:25');
    $addEvent('correction_received', 'pamo_origin', 'pamo_origin', $area->pamo, $area->pamo, '2026-10-19 09:05:27');
    $addEvent('forwarded', 'pamo_origin', 'transit_to_penro_records', $area->pamo, 'PENRO Records Unit', '2026-10-19 09:05:30');
    $release = $addEvent('released', 'penro_records_final', 'released_to_regional', 'PENRO Records Unit', 'Regional Office', '2026-10-19 09:54:06');

    $receipt = FinalDocumentReceipt::query()->create([
        'source_type' => 'conservation', 'source_id' => $id, 'workflow_key' => 'regular_pamb',
        'release_evidence_type' => 'document_routing_events', 'release_evidence_key' => 'document_routing_events:'.$release->getKey(),
        'received_by' => $actor->getKey(), 'receiving_category' => 'PENRO_CDS_FOCAL',
        'receiving_office' => 'PENRO Davao Oriental', 'physically_received_at' => '2026-10-19 15:30:00',
        'recorded_at' => '2026-10-19 15:30:02',
    ]);
    FinalDocumentArchiveManifest::query()->create([
        'receipt_id' => $receipt->getKey(), 'source_type' => 'conservation', 'source_id' => $id,
        'workflow_key' => 'regular_pamb', 'archive_phase' => FinalDocumentArchiveManifest::PHASE,
        'revision' => 1, 'intent_key' => (string) Str::uuid(), 'archive_status' => 'ARCHIVED',
        'signature_confirmation' => true, 'signature_set_sha256' => str_repeat('a', 64),
        'signature_confirmed_by' => $actor->getKey(), 'signature_confirmed_at' => '2026-10-19 15:30:03',
        'created_by' => $actor->getKey(), 'finalized_at' => '2026-10-19 15:31:04',
    ]);

    $data = app(OfficeTatAnalyticsService::class)->analytics($actor, [
        'category' => 'PAMO', 'processing_period' => '2026-H2', 'as_of_date' => '2026-10-20',
        'reporting_year' => '', 'reporting_period' => '', 'protected_area_id' => '',
        'evidence_type' => 'all', 'workflow' => 'conservation:regular_pamb',
    ]);
    $row = collect($data['rows'])->firstWhere('office', $area->pamo);

    expect($receipt->source_id)->toBe($id)
        ->and($data['processing_period_start'])->toBe('2026-07-01')
        ->and($data['processing_period_end'])->toBe('2026-12-31')
        ->and($data['as_of_date'])->toBe('2026-10-20')
        ->and($row['documents_received'])->toBe(1)
        ->and($row['event_derived_received'])->toBe(1)
        ->and($row['tat_completed_documents'])->toBe(1)
        ->and($row['tat_elapsed_seconds_total'])->toBe(3.0)
        ->and($row['tat_average_elapsed_seconds'])->toBe(3.0)
        ->and($row['pending'])->toBe(0)
        ->and($row['tat_missing_evidence'])->toBe(0);
});

test('source events and module options stay hidden without that source view permission', function (): void {
    $actor = User::factory()->create([
        'unit_assignment' => null,
        'section' => OrganizationalAccessService::PENRO_RECORDS,
        'office_designated' => 'PENRO Davao Oriental',
        'protected_area_id' => null,
    ]);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    $area = officeTatCoverageArea($actor);
    officeTatCoverageEnsureOffice('CENRO Baganga');
    $id = officeTatCoverageInsertSource($actor, (int) $area->getKey(), 'engp', 'cbep', 12001, 1);
    officeTatCoverageAddCompletedVisit($actor, 'engp', $id, 'cbep', 'CENRO Baganga', '2026-01-05 09:00:00', '2026-01-06 09:00:00');

    $data = officeTatCoverageAnalytics($actor);
    $row = collect($data['rows'])->firstWhere('office', 'CENRO Baganga');

    expect($row['documents_received'])->toBe(0)
        ->and($row['tat_completed_documents'])->toBe(0)
        ->and($data['source_evidence_rows'])->toBe(0)
        ->and($data['options']['workflows'])->toBe([]);
});
