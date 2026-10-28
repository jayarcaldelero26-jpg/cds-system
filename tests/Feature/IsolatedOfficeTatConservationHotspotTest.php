<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\Reports\ReportRequirementRegistry;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

function isolatedOfficeTatHotspotEvent(User $actor, string $source, int $sourceId, string $workflow, string $eventKey, string $from, string $to, string $occurredAt, ?string $action = null): array
{
    return [
        'source_type' => $source, 'source_id' => $sourceId, 'workflow_key' => $workflow,
        'event_key' => $eventKey, 'from_stage' => $from, 'to_stage' => $to,
        'from_office' => 'Synthetic origin office', 'to_office' => 'Synthetic receiving office',
        'occurred_at' => $occurredAt, 'recorded_by' => $actor->getKey(),
        'metadata' => json_encode($action ? ['action_key' => $action] : [], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ];
}

function isolatedOfficeTatHotspotMeasure(string $name, callable $callback): mixed
{
    if (($GLOBALS['CDS_TAT_PROFILE']['stage'] ?? null) !== 'source_inventory'
        || ! is_array($GLOBALS['CDS_TAT_INVENTORY_PROFILE']['operations'] ?? null)) return $callback();
    $started = hrtime(true);
    $operation = &$GLOBALS['CDS_TAT_INVENTORY_PROFILE']['operations'][$name];
    $operation['calls'] = (int) ($operation['calls'] ?? 0) + 1;
    $operation['total_ms'] = (float) ($operation['total_ms'] ?? 0.0);
    try {
        return $callback();
    } finally {
        $operation['total_ms'] += (hrtime(true) - $started) / 1_000_000;
    }
}
function isolatedOfficeTatHotspotWarmSummary(array $samples, array $keys): array
{
    $warm = array_slice($samples, 1);
    $summary = ['first' => $samples[0], 'warm_median' => [], 'warm_range' => []];
    foreach ($keys as $key) {
        $values = array_map(static fn (array $sample): float => (float) $sample[$key], $warm);
        sort($values, SORT_NUMERIC);
        $middle = count($values) >> 1;
        $median = count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
        $summary['warm_median'][$key] = round($median, 2);
        $summary['warm_range'][$key] = [round($values[0], 2), round($values[count($values) - 1], 2)];
    }

    return $summary;
}

test('isolated 5000-report Conservation source-inventory hotspot profile with five warm route requests', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    $actor = User::factory()->create([
        'section' => OrganizationalAccessService::PENRO_CHIEF,
        'office_designated' => 'PENRO Davao Oriental',
        'unit_assignment' => null, 'is_active' => true, 'is_approved' => true,
    ]);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));

    $officeNames = ['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon'];
    foreach ($officeNames as $index => $officeName) {
        OrganizationalOffice::query()->firstOrCreate(['name' => $officeName], [
            'code' => 'office-tat-profile-cenro-'.($index + 1), 'office_type' => 'cenro', 'is_active' => true,
        ]);
    }
    $area = ProtectedArea::query()->create([
        'name' => 'Office TAT Controller Profile PA', 'short_name' => 'OTCP',
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'Region XI', 'status' => 'Active', 'pamo' => 'OTCP-PAMO',
        'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
    ]);

    $nativeCount = 0;
    $nativeEvents = [];
    foreach (app(PamoNativeSourceRegistry::class)->all() as $workflow => $source) {
        $modelClass = $source['model'];
        $model = new $modelClass;
        $table = $model->getTable();
        $attributes = [
            'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Mati',
            'activity_name' => 'Controller profile '.$source['label'], 'document_type' => 'Progress Report',
            'date_accomplished' => '2026-10-20', 'date_received_penro' => '2026-10-23',
            'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (in_array($workflow, ['bms', 'bams', 'imea'], true)) $attributes['semester'] = '2nd Semester';
        if ($workflow === 'imea_facility_maintenance') $attributes['quarter'] = 'Quarter 4';
        if ($workflow === 'automated_weather_station') $attributes += [
            'station_name' => 'OTCP synthetic station', 'location' => 'Mati', 'reporting_year' => 2026,
            'quarter' => 4, 'monitoring_period_start' => '2026-10-01',
        ];
        if ($workflow === 'revenue_collection') $attributes += [
            'reporting_month' => 10, 'reporting_year' => 2026, 'total_collected' => '100.00',
        ];
        $attributes = array_filter($attributes, static fn (string $column): bool => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
        $sourceId = (int) DB::table($table)->insertGetId($attributes);
        $nativeEvents[] = isolatedOfficeTatHotspotEvent(
            $actor, (string) $source['source'], $sourceId, (string) $workflow, 'forwarded',
            DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
            '2026-10-22 09:00:00', 'forward_from_pamo',
        );
        $nativeCount++;
    }
    foreach (array_chunk($nativeEvents, 30) as $chunk) DB::table('document_routing_events')->insert($chunk);

    $asOfDate = '2026-10-27';
    $year = 2026;
    $engpRegistry = app(EngpReportWorkflowRegistry::class);
    $requirements = app(ReportRequirementRegistry::class);
    $engpCandidates = [];
    foreach ($engpRegistry->all() as $definition) {
        $workflow = (string) $definition['key'];
        foreach (($definition['offices'] ?? $officeNames) as $officeName) {
            foreach ($engpRegistry->periods($workflow, $year) as $period) {
                $periodKey = (string) $period['key'];
                $deadline = $engpRegistry->deadline($workflow, $year, $periodKey);
                $startDate = $requirements->periodStartDate(ReportRequirementRegistry::ENGP, $workflow, $year, $periodKey);
                if (! $deadline || ! $startDate || $deadline > $asOfDate) continue;
                if ($startDate >= $deadline) $startDate = \Carbon\CarbonImmutable::parse($deadline)->subDay()->toDateString();
                $engpCandidates[] = [
                    'workflow_key' => $workflow, 'office' => (string) $officeName, 'section_name' => 'ENGP',
                    'activity_name' => (string) ($definition['activity'] ?? $definition['label']),
                    'document_type' => (string) $definition['document'], 'reporting_year' => $year,
                    'period_key' => $periodKey, 'period_label' => (string) $period['label'],
                    'deadline_submission' => $deadline, 'date_received_penro' => $deadline,
                    'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                    'created_at' => now(), 'updated_at' => now(),
                    '_route_start' => $startDate,
                ];
            }
        }
    }
    expect(count($engpCandidates))->toBeGreaterThanOrEqual(500);
    $engpRows = array_slice($engpCandidates, 0, 500);
    foreach (array_chunk($engpRows, 25) as $chunk) {
        foreach ($chunk as &$row) unset($row['_route_start']);
        unset($row);
        EngpReportSubmission::query()->insert($chunk);
    }
    $engpReports = EngpReportSubmission::query()->where('reporting_year', $year)->orderBy('id')->get();
    expect($engpReports)->toHaveCount(500);
    $engpEvents = [];
    foreach ($engpReports as $record) {
        $candidate = collect($engpCandidates)->first(fn (array $row): bool =>
            $row['workflow_key'] === $record->workflow_key && $row['office'] === $record->office && $row['period_key'] === $record->period_key,
        );
        $startDate = $candidate['_route_start'];
        $engpEvents[] = isolatedOfficeTatHotspotEvent(
            $actor, 'engp', (int) $record->getKey(), (string) $record->workflow_key, 'forwarded',
            DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF,
            $startDate.' 09:00:00', 'forward_to_cenro_chief',
        );
        $engpEvents[] = isolatedOfficeTatHotspotEvent(
            $actor, 'engp', (int) $record->getKey(), (string) $record->workflow_key, 'received',
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS,
            $record->deadline_submission->toDateString().' 09:00:00',
        );
    }
    foreach (array_chunk($engpEvents, 25) as $chunk) DB::table('document_routing_events')->insert($chunk);

    $genericTable = (new ConservationReportSubmission)->getTable();
    $genericCount = 0;
    $insertGenericUntil = static function (int $targetCount) use (&$genericCount, $genericTable, $area, $actor): void {
        while ($genericCount < $targetCount) {
            $batch = [];
            $limit = min($targetCount, $genericCount + 250);
            while ($genericCount < $limit) {
                $genericCount++;
                $batch[] = [
                    'workflow_key' => 'general_report', 'protected_area_id' => $area->getKey(),
                    'target_office' => 'CENRO Mati',
                    'activity_name' => 'Controller profile conservation '.str_pad((string) $genericCount, 5, '0', STR_PAD_LEFT),
                    'document_type' => 'Progress Report', 'reporting_period' => '2026',
                    'date_accomplished' => '2026-10-20', 'date_received_penro' => '2026-10-23',
                    'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table($genericTable)->insert($batch);
        }
    };

    $activeQueries = [];
    DB::listen(static function (QueryExecuted $query) use (&$activeQueries): void {
        if (! is_array($activeQueries)) return;
        $stage = (string) ($GLOBALS['CDS_TAT_PROFILE']['stage'] ?? 'route_middleware');
        $activeQueries[$stage] ??= ['queries' => 0, 'sql_ms' => 0.0];
        $activeQueries[$stage]['queries']++;
        $activeQueries[$stage]['sql_ms'] += $query->time;
    });

    config(['app.asset_url' => 'https://office-tat-profile.invalid']);
    $inertiaVersion = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $profileRoutes = function (int $target, string $unit, int $expectedDetails) use ($actor, &$activeQueries, $inertiaVersion): array {
        $url = '/admin/office-tat?category=CENRO&unit='.$unit.'&processing_period=2026&as_of_date=2026-10-27';
        $samples = [];
        for ($sample = 0; $sample < 6; $sample++) {
            $activeQueries = [];
            $GLOBALS['CDS_TAT_PROFILE'] = ['active' => true, 'stage' => 'route_middleware'];
            if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
            $started = hrtime(true);
            $response = $this->actingAs($actor)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $inertiaVersion ?? ''])->get($url);
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $peak = memory_get_peak_usage(true);
            $body = (string) $response->getContent();
            $response->assertOk();
            $page = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $props = $page['props'] ?? [];
            expect($response->headers->get('X-Inertia'))->toBe('true');
            $reports = (int) ($props['documentTurnaround']['reports'] ?? 0);
            $returned = array_sum(array_map(static fn (array $row): int => count($row['documents'] ?? []), $props['documentTurnaround']['rows'] ?? []));
            expect($props['selectedUnit'])->toBe($unit)
                ->and($props['globalAssessmentAccess'])->toBeFalse()
                ->and($reports)->toBe($expectedDetails)
                ->and($returned)->toBe($expectedDetails);
            $profile = $GLOBALS['CDS_TAT_PROFILE'];
            $queries = $activeQueries;
            $queryCount = array_sum(array_column($queries, 'queries'));
            $sqlMs = array_sum(array_column($queries, 'sql_ms'));
            $turnaroundHash = hash('sha256', json_encode($props['documentTurnaround'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($samples !== []) expect($turnaroundHash)->toBe($samples[0]['turnaround_sha256']);
            $samples[] = [
                'route_ms' => round($elapsed, 2), 'assessment_ms' => round((float) ($profile['assessment_ms'] ?? 0), 2),
                'analytics_ms' => round((float) ($profile['analytics_ms'] ?? 0), 2),
                'recorded_assessment_ms' => round((float) ($profile['recorded_assessment_ms'] ?? 0), 2),
                'source_inventory_ms' => round((float) ($profile['source_inventory_ms'] ?? 0), 2),
                'turnaround_projection_ms' => round((float) ($profile['turnaround_projection_ms'] ?? 0), 2),
                'controller_action_ms' => round((float) ($profile['controller_action_ms'] ?? 0), 2),
                'response_serialization_ms' => round((float) ($profile['response_serialization_ms'] ?? 0), 2),
                'queries' => $queryCount, 'sql_ms' => round($sqlMs, 2),
                'peak_php_bytes' => $peak, 'response_body_bytes' => strlen($body),
                'matching_details' => $reports, 'returned_documents' => $returned,
                'turnaround_sha256' => $turnaroundHash,
                'inventory_profile' => $profile['inventory_operations'] ?? [],
                'phase_sql' => $queries,
            ];
            $GLOBALS['CDS_TAT_PROFILE'] = ['active' => false, 'stage' => null];
        }
        $summary = isolatedOfficeTatHotspotWarmSummary($samples, [
            'route_ms', 'assessment_ms', 'analytics_ms', 'recorded_assessment_ms',
            'source_inventory_ms', 'turnaround_projection_ms', 'controller_action_ms',
            'response_serialization_ms', 'queries', 'sql_ms', 'peak_php_bytes', 'response_body_bytes',
        ]);
        $summary['matching_details'] = $samples[0]['matching_details'];
        $summary['returned_documents'] = $samples[0]['returned_documents'];
        $summary['turnaround_sha256'] = $samples[0]['turnaround_sha256'];
        $summary['phase_sql'] = $samples[0]['phase_sql'];
        $summary['warm_samples'] = array_slice($samples, 1);

        return $summary;
    };

    $output = [
        'database' => 'sqlite :memory:', 'checkpoint' => 'ae41dc28ff74badb55b4a03cc5eacf31b014a232',
        'actor_scope' => 'authorized PENRO CDS Chief; no global admin role', 'period' => '2026',
        'as_of_date' => $asOfDate, 'sample_count' => 'one initial plus five warm',
        'development_reports' => 500, 'native_pa_reports' => $nativeCount, 'tiers' => [],
    ];
    foreach ([5000] as $target) {
        $conservationTarget = $target - 500;
        $insertGenericUntil($conservationTarget - $nativeCount);
        $databaseReports = ConservationReportSubmission::query()->count() + $nativeCount + EngpReportSubmission::query()->count();
        $processedCohort = $genericCount + $nativeCount + EngpReportSubmission::query()->count();
        expect($databaseReports)->toBe($target)->and($processedCohort)->toBe($target);
        $output['tiers'][(string) $target] = [
            'database_reports' => $databaseReports, 'processed_reports' => $processedCohort,
            'source_counts' => ['generic_conservation' => $genericCount, 'native_pa' => $nativeCount, 'engp' => EngpReportSubmission::query()->count()],
            'controller' => [
                'conservation' => $profileRoutes($target, 'conservation', $conservationTarget),
            ],
        ];
        fwrite(STDERR, '[CDS_OFFICE_TAT_CONSERVATION_HOTSPOT_PROFILE] '.json_encode([
            'target' => $target, 'source_counts' => $output['tiers'][(string) $target]['source_counts'],
            'conservation_matching_details' => $output['tiers'][(string) $target]['controller']['conservation']['matching_details'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
    fwrite(STDERR, '[CDS_OFFICE_TAT_CONSERVATION_HOTSPOT_RESULTS] '.json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
});
