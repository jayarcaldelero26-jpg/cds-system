<?php

use App\Models\BmsReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\CalendarMovEventService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

test('calendar month preserves selected-scope membership with constant request query count', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
    ]);
    $owner = User::factory()->create();
    $area = ProtectedArea::query()->create([
        'name' => 'Calendar performance fixture area',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    $otherArea = ProtectedArea::query()->create([
        'name' => 'Calendar excluded fixture area',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $makeRows = static function (ProtectedArea $target, int $count, string $month = '2026-08'): array {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $record = BmsReportSubmission::query()->create([
                'protected_area_id' => $target->id,
                'semester' => '1st Semester',
                'activity_name' => 'Calendar isolated performance fixture '.$index,
                'date_received_penro' => $month.'-'.str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
            ]);
            $ids[] = $record->id;
        }
        return $ids;
    };

    $smallIds = $makeRows($area, 12);
    $makeRows($otherArea, 1);
    $makeRows($area, 1, '2026-07');

    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->time;
    });

    $measure = function (string $label, array $expectedIds) use (&$queries, $viewer, $area): array {
        $request = function () use (&$queries, $viewer, $area): array {
            $queryStart = count($queries);
            $started = hrtime(true);
            $response = $this->actingAs($viewer)->get(route('business-calendar.index', [
                'month' => '2026-08',
                'module' => 'bms',
                'protected_area_id' => $area->id,
            ]));
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $response->assertOk();
            $page = $response->inertiaPage();
            return [
                'response' => $response,
                'events' => $page['props']['movEvents'],
                'elapsed_ms' => $elapsed,
                'query_count' => count($queries) - $queryStart,
                'sql_time_ms' => array_sum(array_slice($queries, $queryStart)),
                'payload_bytes' => strlen($response->getContent()),
            ];
        };

        $request(); // warm the application path; excluded from recorded samples
        $samples = [];
        $last = null;
        for ($index = 0; $index < 5; $index++) {
            $last = $request();
            $samples[] = $last['elapsed_ms'];
        }
        $actualIds = collect($last['events'])->pluck('id')->all();
        sort($actualIds);
        sort($expectedIds);
        expect($actualIds)->toBe($expectedIds);

        sort($samples);
        $project = function () use (&$queries, $viewer, $area): array {
            $queryStart = count($queries);
            $started = hrtime(true);
            $events = app(CalendarMovEventService::class)->events(
                $viewer,
                CarbonImmutable::create(2026, 8, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE),
                'bms',
                $area->id,
            );
            return [
                'events' => $events->all(),
                'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
                'query_count' => count($queries) - $queryStart,
                'sql_time_ms' => array_sum(array_slice($queries, $queryStart)),
                'projection_bytes' => strlen(json_encode($events->all(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ];
        };
        $project();
        $projectionSamples = [];
        $lastProjection = null;
        for ($index = 0; $index < 5; $index++) {
            $lastProjection = $project();
            $projectionSamples[] = $lastProjection['elapsed_ms'];
        }
        $projectionIds = collect($lastProjection['events'])->pluck('id')->all();
        sort($projectionIds);
        $projectionExpectedIds = $expectedIds;
        sort($projectionExpectedIds);
        expect($projectionIds)->toBe($projectionExpectedIds);
        sort($projectionSamples);

        return [
            'fixture' => $label,
            'event_count' => count($expectedIds),
            'actor' => 'reports viewer; BMS permission; selected protected area',
            'month' => '2026-08',
            'filter' => 'module=bms; protected_area_id='.$area->id,
            'runtime' => 'PHP CLI 8.3.30; Laravel test kernel; isolated SQLite :memory:',
            'temperature' => 'warm application; one unmeasured warm-up plus five samples',
            'median_ms' => round($samples[2], 2),
            'samples_ms' => array_map(static fn (float $sample): float => round($sample, 2), $samples),
            'queries_per_request' => $last['query_count'],
            'sql_time_ms' => round($last['sql_time_ms'], 2),
            'payload_bytes' => $last['payload_bytes'],
            'service_projection' => [
                'median_ms' => round($projectionSamples[2], 2),
                'samples_ms' => array_map(static fn (float $sample): float => round($sample, 2), $projectionSamples),
                'queries_per_call' => $lastProjection['query_count'],
                'sql_time_ms' => round($lastProjection['sql_time_ms'], 2),
                'json_bytes' => $lastProjection['projection_bytes'],
            ],
        ];
    };

    $small = $measure('small', $smallIds);
    $largeIds = [...$smallIds, ...$makeRows($area, 228)];
    $large = $measure('large', $largeIds);

    expect($large['queries_per_request'])->toBe($small['queries_per_request'])
        ->and($large['payload_bytes'])->toBeGreaterThan($small['payload_bytes']);

    $outputPath = getenv('CDS_CALENDAR_PERF_OUTPUT');
    if (is_string($outputPath) && $outputPath !== '') {
        file_put_contents($outputPath, json_encode([$small, $large], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
});
