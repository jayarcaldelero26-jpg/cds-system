<?php

use App\Models\ConservationReportSubmission;
use App\Models\ModuleDefinition;
use App\Services\Compliance\OverdueReportService;
use App\Domain\Modules\ProgramArea;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

test('OverdueReportService batches generic ModuleDefinition metadata for multiple rows', function () {
    foreach ([
        ['name' => 'Batch Module A', 'code' => 'batch_module_a'],
        ['name' => 'Batch Module B', 'code' => 'batch_module_b'],
    ] as $definition) {
        ModuleDefinition::query()->create([
            ...$definition,
            'program_area' => ProgramArea::DEVELOPMENT->value,
            'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
            'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
            'reporting_frequency' => 'quarterly',
            'deadline_mode' => ModuleDefinition::DEADLINE_NONE,
            'is_active' => true,
        ]);
    }

    foreach ([
        ['workflow_key' => 'batch_module_a', 'activity_name' => 'A1', 'date_accomplished' => '2026-08-01'],
        ['workflow_key' => 'batch_module_a', 'activity_name' => 'A2', 'date_accomplished' => '2026-08-02'],
        ['workflow_key' => 'batch_module_b', 'activity_name' => 'B1', 'date_accomplished' => '2026-08-03'],
    ] as $payload) {
        ConservationReportSubmission::query()->create($payload);
    }

    $moduleDefinitionQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$moduleDefinitionQueries): void {
        if (str_contains(strtolower($query->sql), 'module_definitions')) {
            $moduleDefinitionQueries++;
        }
    });

    $references = app(OverdueReportService::class)->destinationReferences();

    expect($moduleDefinitionQueries)->toBeLessThanOrEqual(1)
        ->and($references->where('module_name', 'Batch Module A'))->toHaveCount(2)
        ->and($references->where('module_name', 'Batch Module B'))->toHaveCount(1);
});
