<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\OfficeTatDocumentTurnaroundService;
use App\Services\OfficeTatProcessedDocumentSourceService;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function isolatedCapacityEvent(User $actor, string $source, int $sourceId, string $workflow, string $from, string $to, string $action): void
{
    DB::table('document_routing_events')->insert([
        'source_type' => $source,
        'source_id' => $sourceId,
        'workflow_key' => $workflow,
        'event_key' => 'forwarded',
        'from_stage' => $from,
        'to_stage' => $to,
        'from_office' => 'Synthetic source office',
        'to_office' => 'Synthetic receiving office',
        'occurred_at' => '2026-10-22 09:00:00',
        'recorded_by' => $actor->getKey(),
        'metadata' => json_encode(['action_key' => $action], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function isolatedCapacityWarmSummary(array $samples): array
{
    $warm = array_slice($samples, 1);
    $summary = ['first' => $samples[0], 'warm_median' => [], 'warm_range' => []];
    foreach (['elapsed_ms', 'sql_queries', 'sql_ms', 'peak_php_bytes', 'payload_bytes'] as $key) {
        $values = array_map(static fn (array $sample): float => (float) $sample[$key], $warm);
        sort($values, SORT_NUMERIC);
        $middle = count($values) >> 1;
        $median = count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
        $summary['warm_median'][$key] = round($median, 2);
        $summary['warm_range'][$key] = [round($values[0], 2), round($values[count($values) - 1], 2)];
    }

    return $summary;
}

test('isolated capacity profile samples Document TAT and Submission Tracking at 100 1000 and 5000 reports', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    $actor = User::factory()->create([
        'section' => 'CDS', 'unit_assignment' => null, 'is_approved' => true, 'is_active' => true,
    ]);
    $actor->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    $area = ProtectedArea::query()->create([
        'name' => 'Isolated Capacity Test PA', 'short_name' => 'ICTPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active',
        'pamo' => 'ICTPA-PAMO', 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
    ]);
    $cenroOffice = OrganizationalOffice::query()->firstOrCreate(['name' => 'CENRO Mati'], [
        'code' => 'capacity-cenro-mati', 'office_type' => 'cenro', 'is_active' => true,
    ]);

    $nativeCounts = [];
    foreach (app(PamoNativeSourceRegistry::class)->all() as $workflow => $source) {
        $modelClass = $source['model'];
        $model = new $modelClass;
        $table = $model->getTable();
        $base = [
            'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Mati',
            'activity_name' => 'Capacity native '.$source['label'], 'document_type' => 'Progress Report',
            'date_accomplished' => '2026-10-20', 'date_received_penro' => '2026-10-23',
            'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (in_array($workflow, ['bms', 'bams', 'imea'], true)) $base['semester'] = '2nd Semester';
        if ($workflow === 'imea_facility_maintenance') $base['quarter'] = 'Quarter 4';
        if ($workflow === 'automated_weather_station') {
            $base += ['station_name' => 'ICTPA synthetic station', 'location' => 'Mati', 'reporting_year' => 2026, 'quarter' => 4, 'monitoring_period_start' => '2026-10-01'];
        }
        if ($workflow === 'revenue_collection') $base += ['reporting_month' => 10, 'reporting_year' => 2026, 'total_collected' => '100.00'];
        $attributes = array_filter($base, static fn (string $column): bool => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
        $id = (int) DB::table($table)->insertGetId($attributes);
        isolatedCapacityEvent($actor, (string) $source['source'], $id, (string) $workflow,
            DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, 'forward_from_pamo');
        $nativeCounts[$source['source']] = 1;
    }

    $engp = EngpReportSubmission::query()->create([
        'workflow_key' => 'cbep', 'office' => 'CENRO Mati', 'section_name' => 'ENGP',
        'activity_name' => 'Capacity ENGP quarter 4 2026', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'quarter_4', 'period_label' => 'Quarter 4',
        'deadline_submission' => '2026-10-25', 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
    ]);
    isolatedCapacityEvent($actor, 'engp', (int) $engp->getKey(), 'cbep',
        DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF, 'forward_to_cenro_chief');

    $genericTable = (new ConservationReportSubmission)->getTable();
    $genericCount = 0;
    $capture = null;
    DB::listen(static function (QueryExecuted $query) use (&$capture): void {
        if ($capture === null) return;
        $capture['sql_queries']++;
        $capture['sql_ms'] += $query->time;
    });
    $measure = static function (callable $operation, callable $bytes) use (&$capture): array {
        $capture = ['sql_queries' => 0, 'sql_ms' => 0.0];
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $started = hrtime(true);
        $result = $operation();
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $peak = memory_get_peak_usage(true);
        $queries = $capture;
        $capture = null;
        return [
            'elapsed_ms' => round($elapsed, 2), 'sql_queries' => $queries['sql_queries'],
            'sql_ms' => round($queries['sql_ms'], 2), 'peak_php_bytes' => $peak,
            'payload_bytes' => $bytes($result),
        ];
    };

    $turnaround = app(OfficeTatDocumentTurnaroundService::class);
    $tracking = app(SubmissionTrackingService::class);
    $conservationAssessment = [
        'unit' => 'conservation', 'period_key' => '2026-H2', 'as_of_date' => '2026-10-27',
        'office_tat_assessment' => ['period_key' => '2026-H2', 'as_of_date' => '2026-10-27', 'rows' => [[
            'participant_key' => 'pa:'.$area->getKey(), 'name' => $area->name, 'office_binding' => 'CENRO Mati',
        ]]],
    ];
    $developmentAssessment = [
        'unit' => 'development', 'period_key' => '2026-H2', 'as_of_date' => '2026-10-27',
        'office_tat_assessment' => ['period_key' => '2026-H2', 'as_of_date' => '2026-10-27', 'rows' => [[
            'participant_key' => 'cenro:CENRO Mati', 'name' => 'CENRO Mati', 'office_binding' => null,
        ]]],
    ];

    $output = [
        'database' => 'sqlite :memory:', 'targets' => [], 'note' => 'first sample then five warm; peak_php_bytes is process allocation peak; payload_bytes is serialized service JSON for service cases and rendered response body for controller cases; no browser or wire timing is claimed',
    ];
    $targets = array_values(array_filter(array_map('intval', explode(',', (string) (getenv('CDS_CAPACITY_PROFILE_TARGETS') ?: '100,1000,5000'))), static fn (int $value): bool => in_array($value, [100, 1000, 5000], true)));
    foreach ($targets as $target) {
        $genericTarget = $target - 8;
        $rows = [];
        while ($genericCount < $genericTarget) {
            $genericCount++;
            $rows[] = [
                'workflow_key' => 'general_report', 'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Mati',
                'activity_name' => 'Capacity report '.str_pad((string) $genericCount, 5, '0', STR_PAD_LEFT),
                'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 4',
                'date_accomplished' => '2026-10-20', 'date_received_penro' => '2026-10-23',
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($rows) === 500) {
                DB::table($genericTable)->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) DB::table($genericTable)->insert($rows);

        $nativeReportCount = 0;
        foreach (app(PamoNativeSourceRegistry::class)->all() as $source) {
            $nativeReportCount += DB::table((new $source['model'])->getTable())->count();
        }
        $engpCount = EngpReportSubmission::query()->count();
        $total = ConservationReportSubmission::query()->count() + $nativeReportCount + $engpCount;
        $processed = $genericCount + DB::table('document_routing_events')->select('source_type', 'source_id')->distinct()->get()->count();
        expect($total)->toBe($target)->and($processed)->toBe($target);

        $testCase = $this;
        $tatSamples = [];
        foreach (['conservation' => $conservationAssessment, 'development' => $developmentAssessment] as $unit => $assessment) {
            $samples = [];
            for ($sample = 0; $sample < 6; $sample++) {
                $projected = null;
                $result = $measure(
                    function () use (&$projected, $turnaround, $actor, $assessment): array {
                        $projected = $turnaround->forAssessment($actor, $assessment);
                        return $projected;
                    },
                    static fn (array $value): int => strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                );
                $result['reports'] = (int) ($projected['reports'] ?? 0);
                $result['returned_documents'] = array_sum(array_map(static fn (array $row): int => count($row['documents'] ?? []), $projected['rows'] ?? []));
                $result['payload_sha256'] = hash('sha256', json_encode($projected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                expect($result['reports'])->toBe($unit === 'conservation' ? $target - 1 : 1);
                $samples[] = $result;
            }
            $tatSamples[$unit] = isolatedCapacityWarmSummary($samples);
            $tatSamples[$unit]['reports'] = $samples[0]['reports'];
            $tatSamples[$unit]['returned_documents'] = $samples[0]['returned_documents'];
            $tatSamples[$unit]['payload_sha256'] = $samples[0]['payload_sha256'];
        }

        if ($target === 5000 && filter_var(getenv('CDS_CAPACITY_PROFILE_PHASES') ?: 'false', FILTER_VALIDATE_BOOL)) {
            $phaseAssessment = $conservationAssessment;
            $phaseTimer = static function (callable $operation): array {
                $started = hrtime(true);
                $value = $operation();
                return ['elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2), 'value' => $value];
            };
            $sourcePhase = $phaseTimer(fn () => app(OfficeTatProcessedDocumentSourceService::class)->forAssessment($actor, $phaseAssessment));
            $projector = app(OfficeTatDocumentTurnaroundService::class);
            $reflect = static function (string $method) use ($projector): ReflectionMethod {
                $reflection = new ReflectionMethod($projector, $method);
                $reflection->setAccessible(true);
                return $reflection;
            };
            $projectMethod = $reflect('projectDocument');
            $documentsPhase = $phaseTimer(fn () => $reflect('documentsFromRows')->invoke($projector, $sourcePhase['value'], 'conservation'));
            $eventsPhase = $phaseTimer(fn () => $reflect('loadRouteEvents')->invoke($projector, $documentsPhase['value'], 'conservation'));
            $labels = $reflect('routeActionLabels')->invoke($projector);
            $projectionCutoff = CarbonImmutable::parse('2026-10-27', 'Asia/Manila')->endOfDay();
            $projectionPhase = $phaseTimer(function () use ($projector, $documentsPhase, $eventsPhase, $projectMethod, $projectionCutoff, $labels): int {
                $count = 0;
                foreach ($documentsPhase['value'] as $key => $document) {
                    $projectMethod->invoke($projector, $document, $eventsPhase['value'][$key] ?? [], $projectionCutoff, $labels);
                    $count++;
                }
                return $count;
            });
            $sourceService = app(OfficeTatProcessedDocumentSourceService::class);
            $genericTable = (new ConservationReportSubmission)->getTable();
            $sourceQuery = ConservationReportSubmission::query()->whereIn('protected_area_id', [(int) $area->getKey()]);
            if (Schema::hasColumn($genericTable, 'is_historical')) {
                $sourceQuery->where(fn ($query) => $query->whereNull('is_historical')->orWhere('is_historical', false));
            }
            $rawRowsPhase = $phaseTimer(fn () => $sourceQuery->get());
            $sourceReflection = static function (string $method) use ($sourceService): ReflectionMethod {
                $reflection = new ReflectionMethod($sourceService, $method);
                $reflection->setAccessible(true);
                return $reflection;
            };
            $evidenceMethod = $sourceReflection('processedSourceIds');
            $evidencePhase = $phaseTimer(fn () => $evidenceMethod->invoke($sourceService, 'conservation', $rawRowsPhase['value'], $projectionCutoff));
            $periodMethod = $sourceReflection('belongsToAssessmentPeriod');
            $periodBounds = [CarbonImmutable::parse('2026-07-01', 'Asia/Manila')->startOfDay(), CarbonImmutable::parse('2026-12-31', 'Asia/Manila')->endOfDay()];
            $periodPhase = $phaseTimer(function () use ($sourceService, $rawRowsPhase, $periodMethod, $periodBounds): int {
                $count = 0;
                foreach ($rawRowsPhase['value'] as $record) {
                    if ($periodMethod->invoke($sourceService, $record, '2026-H2', ...$periodBounds)) $count++;
                }
                return $count;
            });
            $appendMethod = $sourceReflection('appendRecord');
            $appendPhase = $phaseTimer(function () use ($sourceService, $appendMethod, $rawRowsPhase, $area, $projectionCutoff): int {
                $participant = ['participant_key' => 'pa:'.$area->getKey(), 'name' => $area->name, 'office_binding' => 'CENRO Mati', 'details' => []];
                foreach ($rawRowsPhase['value'] as $record) {
                    $workflow = (string) $record->workflow_key;
                    $activity = trim((string) ($record->activity_name ?: $workflow));
                    $appendMethod->invokeArgs($sourceService, [&$participant, 'conservation', (int) $record->getKey(), $workflow,
                        $activity, (string) $record->activity_name, (string) $record->document_type,
                        $record->target_office, $record, '2026-H2', $projectionCutoff]);
                }
                return count($participant['details']);
            });
            fwrite(STDERR, '[CDS_TAT_PHASES] '.json_encode([
                'target' => $target, 'source_rows' => count($sourcePhase['value']),
                'documents' => count($documentsPhase['value']), 'route_events' => array_sum(array_map('count', $eventsPhase['value'])),
                'processed_source_inventory_ms' => $sourcePhase['elapsed_ms'],
                'document_normalization_ms' => $documentsPhase['elapsed_ms'],
                'route_event_load_ms' => $eventsPhase['elapsed_ms'],
                'per_document_projection_ms' => $projectionPhase['elapsed_ms'],
                'projected_documents' => $projectionPhase['value'],
                'generic_raw_rows' => count($rawRowsPhase['value']), 'generic_processed_rows' => count($evidencePhase['value']),
                'generic_period_rows' => $periodPhase['value'], 'generic_appended_rows' => $appendPhase['value'],
                'generic_fetch_ms' => $rawRowsPhase['elapsed_ms'], 'generic_processing_evidence_ms' => $evidencePhase['elapsed_ms'],
                'generic_period_filter_ms' => $periodPhase['elapsed_ms'], 'generic_detail_append_ms' => $appendPhase['elapsed_ms'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        }

        $routeSamples = [];
        foreach (filter_var(getenv('CDS_CAPACITY_PROFILE_CONTROLLER') ?: 'true', FILTER_VALIDATE_BOOL) ? ['conservation', 'development'] : [] as $unit) {
            $samples = [];
            $url = '/admin/office-tat?category=CENRO&unit='.$unit.'&processing_period=2026-H2&as_of_date=2026-10-27';
            for ($sample = 0; $sample < 6; $sample++) {
                $page = null;
                $metric = $measure(
                    function () use ($testCase, $actor, $url, &$page) {
                        $page = $testCase->actingAs($actor)->get($url);
                        $page->assertOk();
                        return $page;
                    },
                    static fn ($response): int => strlen((string) $response->getContent()),
                );
                if ($sample === 0) {
                    $props = $page->inertiaProps();
                    expect($props)->toHaveKey('documentTurnaround');
                    $metric['document_tat_reports'] = (int) ($props['documentTurnaround']['reports'] ?? 0);
                    $expectedParticipant = $unit === 'conservation' ? 'pa:'.$area->getKey() : 'cenro:'.$cenroOffice->getKey();
                    $recordedRows = $props['recordedAssessment']['rows'] ?? [];
                    $rosterContainsFixture = collect($recordedRows)->contains(
                        static fn (array $row): bool => ($row['participant_key'] ?? null) === $expectedParticipant,
                    );
                    expect($rosterContainsFixture)->toBeTrue()
                        ->and($metric['document_tat_reports'])->toBe($unit === 'conservation' ? $target - 1 : 1);
                }
                $samples[] = $metric;
            }
            $routeSamples[$unit] = isolatedCapacityWarmSummary($samples);
        }

        $pageSummary = null;
        $searchSummary = null;
        if (! filter_var(getenv('CDS_CAPACITY_PROFILE_TAT_ONLY') ?: 'false', FILTER_VALIDATE_BOOL)) {
        $pageSamples = [];
        $lastPage = null;
        for ($sample = 0; $sample < 6; $sample++) {
            $metric = $measure(
                function () use (&$lastPage, $tracking): array {
                    $lastPage = $tracking->snapshot([], 1, 25);
                    return $lastPage;
                },
                static fn (array $value): int => strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            );
            $metric['total'] = (int) ($lastPage['pagination']['total'] ?? 0);
            $metric['returned_rows'] = count($lastPage['records'] ?? []);
            expect($metric['returned_rows'])->toBeLessThanOrEqual(25);
            $pageSamples[] = $metric;
        }
        $pageSummary = isolatedCapacityWarmSummary($pageSamples);
        $pageSummary['total'] = $pageSamples[0]['total'];
        $pageSummary['returned_rows'] = $pageSamples[0]['returned_rows'];

        $searchTerm = 'Capacity report '.str_pad((string) $genericTarget, 5, '0', STR_PAD_LEFT);
        $searchSamples = [];
        $lastSearch = null;
        for ($sample = 0; $sample < 6; $sample++) {
            $metric = $measure(
                function () use (&$lastSearch, $tracking, $searchTerm): array {
                    $lastSearch = $tracking->snapshot(['search' => $searchTerm], 1, 25);
                    return $lastSearch;
                },
                static fn (array $value): int => strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            );
            $metric['total'] = (int) ($lastSearch['pagination']['total'] ?? 0);
            $metric['returned_rows'] = count($lastSearch['records'] ?? []);
            $searchSamples[] = $metric;
        }
        $searchSummary = isolatedCapacityWarmSummary($searchSamples);
        $searchSummary['total'] = $searchSamples[0]['total'];
        $searchSummary['returned_rows'] = $searchSamples[0]['returned_rows'];
        }
        $output['targets'][(string) $target] = [
            'database_reports' => $total, 'processed_cohort' => $processed,
            'sources' => ['generic_conservation' => $genericCount, 'native_pa' => $nativeCounts, 'engp' => $engpCount],
            'tat' => $tatSamples,
            'office_tat_controller' => $routeSamples,
            'submission_tracking_page' => $pageSummary,
            'submission_tracking_exact_search' => $searchSummary,
        ];
    }
    fwrite(STDERR, '[CDS_CAPACITY_LAB] '.json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
});
