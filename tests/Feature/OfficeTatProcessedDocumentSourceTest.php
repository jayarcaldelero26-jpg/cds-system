<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\OfficeTatProcessedDocumentSourceService;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function officeProcessedSourcesActor(): User
{
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));

    return $actor;
}

function officeProcessedSourcesAssessment(string $unit, string $participantKey, string $participantName): array
{
    return [
        'unit' => $unit,
        'period_key' => '2026-H2',
        'as_of_date' => '2026-10-27',
        'office_tat_assessment' => [
            'as_of_date' => '2026-10-27',
            'period_key' => '2026-H2',
            'rows' => [[
                'participant_key' => $participantKey,
                'name' => $participantName,
                'office_binding' => $unit === 'conservation' ? 'CENRO Baganga' : null,
            ]],
        ],
    ];
}

function officeProcessedSourcesRouteEvent(User $actor, string $source, int $sourceId, string $from, string $to, string $occurredAt, string $action): void
{
    DB::table('document_routing_events')->insert([
        'source_type' => $source, 'source_id' => $sourceId,
        'workflow_key' => $source === 'engp' ? 'cbep' : 'regular_pamb',
        'event_key' => 'forwarded', 'from_stage' => $from, 'to_stage' => $to,
        'from_office' => 'Origin office', 'to_office' => 'Next office',
        'occurred_at' => $occurredAt, 'recorded_by' => $actor->getKey(),
        'metadata' => json_encode(['action_key' => $action], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('processed document inventory includes generic Conservation, native PA, and ENGP sources only when processed', function (): void {
    $actor = officeProcessedSourcesActor();
    $area = ProtectedArea::query()->create([
        'name' => 'Processed Source Inventory Test PA', 'short_name' => 'PSI',
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'pamo' => 'PSI-PAMO',
    ]);

    $genericProcessed = ConservationReportSubmission::query()->create([
        'workflow_key' => 'general_report', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Processed conservation report',
        'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 4',
        'date_accomplished' => '2026-10-20',
    ]);
    $genericUnprocessed = ConservationReportSubmission::query()->create([
        'workflow_key' => 'general_report', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Draft conservation report',
        'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 4',
        'date_accomplished' => '2026-10-21',
    ]);
    $nativeExpected = [];
    $nativeUnprocessedKeys = [];
    foreach (app(PamoNativeSourceRegistry::class)->all() as $workflow => $source) {
        $model = new $source['model'];
        $table = $model->getTable();
        $base = [
            'protected_area_id' => $area->getKey(),
            'target_office' => 'CENRO Baganga',
            'activity_name' => 'Processed '.$source['label'].' report',
            'document_type' => 'Progress Report',
            'date_accomplished' => '2026-10-20',
            'created_at' => now(), 'updated_at' => now(),
        ];
        if (in_array($workflow, ['bms', 'bams', 'imea'], true)) $base['semester'] = '2nd Semester';
        if ($workflow === 'imea_facility_maintenance') $base['quarter'] = 'Quarter 4';
        if ($workflow === 'automated_weather_station') {
            $base += [
                'station_name' => 'PSI weather station', 'location' => 'Mati',
                'reporting_year' => 2026, 'quarter' => 4, 'monitoring_period_start' => '2026-10-01',
            ];
        }
        if ($workflow === 'revenue_collection') {
            $base += ['reporting_month' => 10, 'reporting_year' => 2026, 'total_collected' => '100.00'];
        }
        if ($workflow === 'ipaf_management') $base['date_accomplished'] = '2026-10-20';
        $attributes = array_filter($base, static fn (string $column): bool => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
        $processedId = DB::table($table)->insertGetId($attributes);
        $draftAttributes = $attributes;
        $draftAttributes['activity_name'] = 'Draft '.$source['label'].' report';
        unset($draftAttributes['created_at'], $draftAttributes['updated_at']);
        $draftId = DB::table($table)->insertGetId($draftAttributes);
        $nativeExpected[] = $source['source'].':'.$processedId;
        $nativeUnprocessedKeys[] = $source['source'].':'.$draftId;
        officeProcessedSourcesRouteEvent($actor, $source['source'], (int) $processedId, DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, '2026-10-23 09:00:00', 'forward_from_pamo');
    }
    $engpProcessed = EngpReportSubmission::query()->create([
        'workflow_key' => 'cbep', 'office' => 'CENRO Baganga',
        'activity_name' => 'Processed ENGP report', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'quarter_4', 'period_label' => 'Quarter 4',
        'deadline_submission' => '2026-10-25',
    ]);
    $engpUnprocessed = EngpReportSubmission::query()->create([
        'workflow_key' => 'elcac', 'office' => 'CENRO Baganga',
        'activity_name' => 'Draft ENGP report', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'quarter_4', 'period_label' => 'Quarter 4',
        'deadline_submission' => '2026-10-25',
    ]);

    officeProcessedSourcesRouteEvent($actor, 'conservation', (int) $genericProcessed->getKey(), DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, '2026-10-22 09:00:00', 'forward_from_pamo');
    officeProcessedSourcesRouteEvent($actor, 'engp', (int) $engpProcessed->getKey(), DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF, '2026-10-24 09:00:00', 'forward_to_cenro_chief');

    $conservation = app(OfficeTatProcessedDocumentSourceService::class)->forAssessment(
        $actor,
        officeProcessedSourcesAssessment('conservation', 'pa:'.$area->getKey(), $area->name),
    );
    $development = app(OfficeTatProcessedDocumentSourceService::class)->forAssessment(
        $actor,
        officeProcessedSourcesAssessment('development', 'cenro:CENRO Baganga', 'CENRO Baganga'),
    );
    $conservationKeys = collect($conservation)->flatMap(fn (array $row): array => $row['obligation_details'])
        ->map(fn (array $detail): string => $detail['source_identity']['source_key'].':'.$detail['source_identity']['source_id'])
        ->sort()->values()->all();
    $developmentKeys = collect($development)->flatMap(fn (array $row): array => $row['obligation_details'])
        ->map(fn (array $detail): string => $detail['source_identity']['source_key'].':'.$detail['source_identity']['source_id'])
        ->sort()->values()->all();

    sort($nativeExpected);
    $expectedConservationKeys = [...$nativeExpected, 'conservation:'.$genericProcessed->getKey()];
    sort($expectedConservationKeys);
    expect($conservationKeys)->toBe([
        ...$expectedConservationKeys,
    ])->and($developmentKeys)->toBe([
        'engp:'.$engpProcessed->getKey(),
    ])->and($conservationKeys)->not->toContain('conservation:'.$genericUnprocessed->getKey())
        ->and(array_intersect($conservationKeys, $nativeUnprocessedKeys))->toBe([])
        ->and($developmentKeys)->not->toContain('engp:'.$engpUnprocessed->getKey());
});
