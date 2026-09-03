<?php

namespace Database\Seeders;

use App\Domain\Modules\ProgramArea;
use App\Models\ModuleDefinition;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Illuminate\Database\Seeder;

class ModuleDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            $this->specialized('bms', 'BMS', ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'bms', 'bms.index', 'semestral', 15),
            $this->specialized('bams', 'BAMS', ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'bams', 'bams.index', 'semestral', 15),
            $this->specialized('imea', 'IMEA', ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'imea', 'imea.index', 'semestral', 15),
            $this->specialized('imea_facility_maintenance', 'IMEA Facility Maintenance', ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'imea-maintenance', 'imea.maintenance-reports.index', 'quarterly', 7),
            $this->specialized('automated_weather_station', 'Automated Weather Station', ProgramArea::CONSERVATION, 'aws', 'aws.index', 'semestral', 7),
            $this->specialized('ipaf_management', 'Management of IPAF', ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'ipaf', 'ipaf.index', 'custom', 7),
            $this->specialized('revenue_collection', 'Revenue Collection', ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'revenue', 'ipaf.index', 'monthly', null, ModuleDefinition::DEADLINE_CUSTOM, true),
            $this->specialized('management_plans', 'Management Plans', ProgramArea::DEVELOPMENT, 'management-plans', 'management-plans.index', null, null, ModuleDefinition::DEADLINE_CUSTOM, true, ModuleDefinition::TYPE_PLAN),
        ];

        foreach (app(ConservationReportWorkflowRegistry::class)->all() as $workflow) {
            $definitions[] = [
                'name' => $workflow['label'], 'code' => $workflow['key'],
                'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT,
                'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
                'module_type' => str_contains($workflow['key'], 'plan') ? ModuleDefinition::TYPE_PLAN : ModuleDefinition::TYPE_REGULAR_TARGET,
                'reporting_frequency' => $this->frequencyFromPeriods($workflow['periods'] ?? []),
                'plan_duration_years' => str_contains($workflow['key'], '5_year') ? 5 : null,
                'deadline_mode' => app(ConservationReportWorkflowRegistry::class)->deadlineRule($workflow['key'], $workflow['default_activity'] ?? null, ($workflow['activity_documents'][$workflow['default_activity'] ?? ''][0] ?? null))['deadline_mode'],
                'default_deadline_days' => app(ConservationReportWorkflowRegistry::class)->deadlineRule($workflow['key'], $workflow['default_activity'] ?? null, ($workflow['activity_documents'][$workflow['default_activity'] ?? ''][0] ?? null))['deadline_days'], 'allow_deadline_override' => false,
                'is_active' => true, 'description' => $workflow['description'] ?? null,
                'existing_route_name' => 'conservation-reports.index', 'existing_source_key' => 'conservation-reports',
            ];
        }

        foreach (app(EngpReportWorkflowRegistry::class)->all() as $workflow) {
            $definitions[] = $this->specialized(
                'engp_'.$workflow['key'], $workflow['label'], ProgramArea::ENGP, 'engp', 'engp-reports.index',
                $workflow['period'] ?? 'custom', null, ModuleDefinition::DEADLINE_CUSTOM, true,
            );
        }

        foreach ($definitions as $definition) {
            // Preserve administrator changes on repeat seeding; the code is the
            // stable integration key for existing workflows and history.
            ModuleDefinition::query()->firstOrCreate(['code' => $definition['code']], $definition);
        }
    }

    /** @return array<string,mixed> */
    private function specialized(string $code, string $name, ProgramArea $area, string $source, string $route, ?string $frequency, ?int $days, string $deadline = ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, bool $override = false, string $type = ModuleDefinition::TYPE_REGULAR_TARGET): array
    {
        return [
            'name' => $name, 'code' => $code, 'program_area' => $area,
            'implementation_type' => ModuleDefinition::IMPLEMENTATION_SPECIALIZED,
            'module_type' => $type, 'reporting_frequency' => $type === ModuleDefinition::TYPE_PLAN ? null : $frequency,
            'plan_duration_years' => null, 'deadline_mode' => $deadline, 'default_deadline_days' => $days,
            'allow_deadline_override' => $override, 'is_active' => true,
            'existing_route_name' => $route, 'existing_source_key' => $source,
        ];
    }

    private function frequencyFromPeriods(array $periods): ?string
    {
        $first = strtolower((string) ($periods[0] ?? ''));
        return str_contains($first, 'quarter') ? 'quarterly' : (str_contains($first, 'semester') ? 'semestral' : null);
    }
}
