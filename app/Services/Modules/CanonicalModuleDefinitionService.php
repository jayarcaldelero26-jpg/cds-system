<?php

namespace App\Services\Modules;

use App\Domain\Modules\ProgramArea;
use App\Models\ModuleDefinition;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Pamo\PamoNativeSourceRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Narrow, idempotent creation of a missing code-owned module definition. */
final class CanonicalModuleDefinitionService
{
    /** @return array{0:ModuleDefinition,1:bool} */
    public function ensureDevelopmentCbep(): array
    {
        $existing = ModuleDefinition::query()->where('code', 'engp_cbep')->first();
        if ($existing) return [$existing, false];

        $workflow = app(EngpReportWorkflowRegistry::class)->defaultFind('cbep');
        if (! $workflow) throw new \LogicException('The canonical CBEP workflow is unavailable.');

        $attributes = [
            'name' => $workflow['label'],
            'code' => 'engp_cbep',
            'program_area' => ProgramArea::ENGP,
            'requirement_domain' => 'engp',
            'requirement_key' => 'cbep',
            'implementation_type' => ModuleDefinition::IMPLEMENTATION_SPECIALIZED,
            'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
            'reporting_frequency' => $workflow['period'],
            'plan_duration_years' => null,
            'deadline_mode' => ModuleDefinition::DEADLINE_CUSTOM,
            'default_deadline_days' => null,
            'allow_deadline_override' => true,
            'is_active' => true,
            'existing_route_name' => 'engp-reports.index',
            'existing_source_key' => 'engp',
            'requirement_metadata' => $workflow,
        ];

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'in:engp_cbep'],
            'program_area' => ['required', Rule::enum(ProgramArea::class)],
            'requirement_domain' => ['required', 'in:engp'],
            'requirement_key' => ['required', 'in:cbep'],
            'implementation_type' => ['required', 'in:'.ModuleDefinition::IMPLEMENTATION_SPECIALIZED],
            'module_type' => ['required', 'in:'.ModuleDefinition::TYPE_REGULAR_TARGET],
            'reporting_frequency' => ['required', Rule::in(ModuleDefinition::FREQUENCIES)],
            'deadline_mode' => ['required', 'in:'.ModuleDefinition::DEADLINE_CUSTOM],
            'allow_deadline_override' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'existing_route_name' => ['required', 'in:engp-reports.index'],
            'existing_source_key' => ['required', 'in:engp'],
            'requirement_metadata' => ['required', 'array'],
        ])->validate();

        $definition = ModuleDefinition::query()->firstOrCreate(['code' => 'engp_cbep'], $validated);
        return [$definition, $definition->wasRecentlyCreated];
    }

    /** @return array{created:list<ModuleDefinition>,present:list<string>,inactive:list<string>,conflicts:array<string,string>} */
    public function ensureCanonicalEngpDefinitions(): array
    {
        $keys = app(EngpReportWorkflowRegistry::class)->defaultKeys();
        $codes = array_map(fn (string $key): string => 'engp_'.$key, $keys);
        $existing = ModuleDefinition::query()->whereIn('code', $codes)->get()->keyBy('code');
        $created = [];
        $present = [];
        $inactive = [];
        $conflicts = [];

        foreach ($keys as $key) {
            $attributes = $this->canonicalEngpDefinition($key);
            $code = $attributes['code'];
            $definition = $existing->get($code);

            if (! $definition) {
                $validated = $this->validateCanonicalEngpDefinition($attributes, $codes, $keys);
                $definition = ModuleDefinition::query()->firstOrCreate(['code' => $code], $validated);
                if ($definition->wasRecentlyCreated) {
                    $created[] = $definition;
                    continue;
                }
            }

            $mismatch = $this->canonicalEngpIdentityMismatch($definition, $attributes);
            if ($mismatch !== null) {
                $conflicts[$code] = $mismatch;
            } elseif (! $definition->is_active) {
                $inactive[] = $code;
            } else {
                $present[] = $code;
            }
        }

        return compact('created', 'present', 'inactive', 'conflicts');
    }

    public function missingCanonicalEngpDefinitionCount(): int
    {
        $codes = array_map(
            fn (string $key): string => 'engp_'.$key,
            app(EngpReportWorkflowRegistry::class)->defaultKeys(),
        );
        $present = ModuleDefinition::query()->whereIn('code', $codes)->distinct()->count('code');

        return max(0, count($codes) - $present);
    }

    /** @return array<string,mixed> */
    private function canonicalEngpDefinition(string $key): array
    {
        $workflow = app(EngpReportWorkflowRegistry::class)->defaultFind($key);
        if ($workflow === null) {
            throw new \LogicException('The requested ENGP workflow is not in the canonical registry.');
        }

        return [
            'name' => $workflow['label'],
            'code' => 'engp_'.$key,
            'program_area' => ProgramArea::ENGP,
            'requirement_domain' => 'engp',
            'requirement_key' => $key,
            'implementation_type' => ModuleDefinition::IMPLEMENTATION_SPECIALIZED,
            'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
            'reporting_frequency' => $workflow['period'] ?? 'custom',
            'plan_duration_years' => null,
            'deadline_mode' => ModuleDefinition::DEADLINE_CUSTOM,
            'default_deadline_days' => null,
            'allow_deadline_override' => true,
            'is_active' => true,
            'existing_route_name' => 'engp-reports.index',
            'existing_source_key' => 'engp',
            'requirement_metadata' => $workflow,
        ];
    }

    /** @param list<string> $codes @param list<string> $keys @return array<string,mixed> */
    private function validateCanonicalEngpDefinition(array $attributes, array $codes, array $keys): array
    {
        return Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', Rule::in($codes)],
            'program_area' => ['required', Rule::enum(ProgramArea::class)],
            'requirement_domain' => ['required', 'in:engp'],
            'requirement_key' => ['required', 'string', Rule::in($keys)],
            'implementation_type' => ['required', 'in:'.ModuleDefinition::IMPLEMENTATION_SPECIALIZED],
            'module_type' => ['required', 'in:'.ModuleDefinition::TYPE_REGULAR_TARGET],
            'reporting_frequency' => ['required', Rule::in(ModuleDefinition::FREQUENCIES)],
            'plan_duration_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'deadline_mode' => ['required', 'in:'.ModuleDefinition::DEADLINE_CUSTOM],
            'default_deadline_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'allow_deadline_override' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'existing_route_name' => ['required', 'in:engp-reports.index'],
            'existing_source_key' => ['required', 'in:engp'],
            'requirement_metadata' => ['required', 'array'],
        ])->validate();
    }

    private function canonicalEngpIdentityMismatch(ModuleDefinition $existing, array $canonical): ?string
    {
        foreach ([
            'program_area', 'requirement_domain', 'requirement_key', 'implementation_type',
            'module_type', 'reporting_frequency', 'plan_duration_years', 'deadline_mode',
            'default_deadline_days', 'allow_deadline_override', 'existing_route_name', 'existing_source_key',
        ] as $field) {
            $actual = $existing->getAttribute($field);
            if ($actual instanceof ProgramArea) $actual = $actual->value;
            $expected = $canonical[$field] ?? null;
            if ($expected instanceof ProgramArea) $expected = $expected->value;
            if ($actual !== $expected) return "stored {$field} differs from canonical registry metadata";
        }

        $expectedMetadata = $canonical['requirement_metadata'] ?? [];
        $actualMetadata = $existing->requirement_metadata ?? [];
        foreach (['key', 'label', 'activity', 'document', 'period', 'offices', 'weekly'] as $field) {
            if (array_key_exists($field, $expectedMetadata) && ($actualMetadata[$field] ?? null) !== $expectedMetadata[$field]) {
                return "stored workflow {$field} differs from canonical registry metadata";
            }
        }

        return null;
    }

    /** @return array{created:list<ModuleDefinition>,present:list<string>,inactive:list<string>,conflicts:array<string,string>} */
    public function ensureCanonicalConservationDefinitions(): array
    {
        $codes = $this->canonicalConservationCodes();
        $existing = ModuleDefinition::query()->whereIn('code', $codes)->get()->keyBy('code');
        $created = [];
        $present = [];
        $inactive = [];
        $conflicts = [];

        foreach ($codes as $code) {
            $attributes = $this->canonicalConservationDefinition($code);
            $definition = $existing->get($code);

            if (! $definition) {
                $validated = $this->validateCanonicalConservationDefinition($attributes);
                $definition = ModuleDefinition::query()->firstOrCreate(['code' => $code], $validated);
                if ($definition->wasRecentlyCreated) {
                    $created[] = $definition;
                    continue;
                }
            }

            $mismatch = $this->canonicalIdentityMismatch($definition, $attributes);
            if ($mismatch !== null) {
                $conflicts[$code] = $mismatch;
            } elseif (! $definition->is_active) {
                $inactive[] = $code;
            } else {
                $present[] = $code;
            }
        }

        return compact('created', 'present', 'inactive', 'conflicts');
    }

    public function missingCanonicalConservationDefinitionCount(): int
    {
        $codes = $this->canonicalConservationCodes();
        $present = ModuleDefinition::query()->whereIn('code', $codes)->distinct()->count('code');

        return max(0, count($codes) - $present);
    }

    /** @return list<string> */
    private function canonicalConservationCodes(): array
    {
        return array_values(array_unique([
            ...app(ConservationReportWorkflowRegistry::class)->defaultKeys(),
            ...array_keys(app(PamoNativeSourceRegistry::class)->all()),
        ]));
    }

    /** @return array<string,mixed> */
    private function canonicalConservationDefinition(string $code): array
    {
        $workflows = app(ConservationReportWorkflowRegistry::class);
        $workflow = $workflows->defaultFind($code);
        if ($workflow) {
            $activity = $workflow['default_activity'] ?? null;
            $document = $workflow['activity_documents'][$activity ?? ''][0] ?? null;
            $canonicalDeadline = $workflows->defaultDeadlineRule($code, $activity, $document);
            $periods = $workflow['periods'] ?? [];
            $frequency = $this->frequencyFromPeriods($periods);

            return [
                'name' => $workflow['label'],
                'code' => $workflow['key'],
                'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT,
                'requirement_domain' => 'pa',
                'requirement_key' => $workflow['key'],
                'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
                'module_type' => str_contains($workflow['key'], 'plan') ? ModuleDefinition::TYPE_PLAN : ModuleDefinition::TYPE_REGULAR_TARGET,
                'reporting_frequency' => $frequency,
                'plan_duration_years' => str_contains($workflow['key'], '5_year') ? 5 : null,
                'deadline_mode' => $canonicalDeadline['deadline_mode'],
                'default_deadline_days' => $canonicalDeadline['deadline_days'],
                'allow_deadline_override' => false,
                'is_active' => true,
                'description' => $workflow['description'] ?? null,
                'existing_route_name' => 'conservation-reports.index',
                'existing_source_key' => 'conservation-reports',
                'requirement_metadata' => [
                    ...$workflow,
                    'canonical_frequency' => $frequency,
                    'canonical_deadline_mode' => $canonicalDeadline['deadline_mode'],
                    'canonical_deadline_days' => $canonicalDeadline['deadline_days'],
                ],
            ];
        }

        $native = $this->nativeConservationDefinition($code);
        if ($native === null) {
            throw new \LogicException('The requested Conservation catalog code is not registered.');
        }

        return $native;
    }

    /** @return array<string,mixed>|null */
    private function nativeConservationDefinition(string $code): ?array
    {
        $definitions = [
            'bms' => ['name' => 'BMS', 'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'source' => 'bms', 'route' => 'bms.index', 'frequency' => 'semestral', 'days' => 15, 'deadline' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'override' => false],
            'bams' => ['name' => 'BAMS', 'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'source' => 'bams', 'route' => 'bams.index', 'frequency' => 'semestral', 'days' => 15, 'deadline' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'override' => false],
            'imea' => ['name' => 'IMEA', 'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'source' => 'imea', 'route' => 'imea.index', 'frequency' => 'semestral', 'days' => 15, 'deadline' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'override' => false],
            'imea_facility_maintenance' => ['name' => 'IMEA Facility Maintenance', 'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'source' => 'imea-maintenance', 'route' => 'imea.maintenance-reports.index', 'frequency' => 'quarterly', 'days' => 7, 'deadline' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'override' => false],
            'automated_weather_station' => ['name' => 'Automated Weather Station', 'program_area' => ProgramArea::CONSERVATION, 'source' => 'aws', 'route' => 'aws.index', 'frequency' => 'semestral', 'days' => 7, 'deadline' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'override' => false],
            'ipaf_management' => ['name' => 'Management of IPAF', 'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'source' => 'ipaf', 'route' => 'ipaf.index', 'frequency' => 'custom', 'days' => 7, 'deadline' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'override' => false],
            'revenue_collection' => ['name' => 'Revenue Collection', 'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT, 'source' => 'revenue', 'route' => 'ipaf.index', 'frequency' => 'monthly', 'days' => null, 'deadline' => ModuleDefinition::DEADLINE_CUSTOM, 'override' => true],
        ];
        $definition = $definitions[$code] ?? null;
        if ($definition === null || app(PamoNativeSourceRegistry::class)->find($code) === null) return null;

        return [
            'name' => $definition['name'],
            'code' => $code,
            'program_area' => $definition['program_area'],
            'requirement_domain' => 'pa',
            'requirement_key' => $code,
            'implementation_type' => ModuleDefinition::IMPLEMENTATION_SPECIALIZED,
            'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
            'reporting_frequency' => $definition['frequency'],
            'plan_duration_years' => null,
            'deadline_mode' => $definition['deadline'],
            'default_deadline_days' => $definition['days'],
            'allow_deadline_override' => $definition['override'],
            'is_active' => true,
            'existing_route_name' => $definition['route'],
            'existing_source_key' => $definition['source'],
            'requirement_metadata' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function validateCanonicalConservationDefinition(array $attributes): array
    {
        return Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', Rule::in($this->canonicalConservationCodes())],
            'program_area' => ['required', Rule::enum(ProgramArea::class)],
            'requirement_domain' => ['required', 'in:pa'],
            'requirement_key' => ['required', 'string', 'max:100'],
            'implementation_type' => ['required', Rule::in([ModuleDefinition::IMPLEMENTATION_GENERIC, ModuleDefinition::IMPLEMENTATION_SPECIALIZED])],
            'module_type' => ['required', Rule::in([ModuleDefinition::TYPE_REGULAR_TARGET, ModuleDefinition::TYPE_PLAN])],
            'reporting_frequency' => ['nullable', Rule::in(ModuleDefinition::FREQUENCIES)],
            'plan_duration_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'deadline_mode' => ['required', Rule::in([ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, ModuleDefinition::DEADLINE_PAMB_WORKING_DAYS, ModuleDefinition::DEADLINE_CALENDAR_DAYS, ModuleDefinition::DEADLINE_CUSTOM, ModuleDefinition::DEADLINE_NONE])],
            'default_deadline_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'allow_deadline_override' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'existing_route_name' => ['required', 'string', 'max:255'],
            'existing_source_key' => ['required', 'string', 'max:255'],
            'requirement_metadata' => ['nullable', 'array'],
        ])->validate();
    }

    private function canonicalIdentityMismatch(ModuleDefinition $existing, array $canonical): ?string
    {
        $fields = [
            'program_area', 'requirement_domain', 'requirement_key', 'implementation_type',
            'module_type', 'reporting_frequency', 'plan_duration_years', 'deadline_mode',
            'default_deadline_days', 'allow_deadline_override', 'existing_route_name', 'existing_source_key',
        ];
        foreach ($fields as $field) {
            $actual = $existing->getAttribute($field);
            if ($actual instanceof ProgramArea) $actual = $actual->value;
            $expected = $canonical[$field] ?? null;
            if ($expected instanceof ProgramArea) $expected = $expected->value;
            if ($actual !== $expected) return 'stored canonical identity/configuration differs';
        }

        $expectedMetadata = $canonical['requirement_metadata'] ?? null;
        if ($expectedMetadata !== null) {
            $actualMetadata = $existing->requirement_metadata ?? [];
            foreach (['key', 'period_field', 'period_label', 'periods', 'activities', 'documents', 'activity_documents', 'submission_rules', 'default_activity', 'canonical_frequency', 'canonical_deadline_mode', 'canonical_deadline_days'] as $field) {
                if (array_key_exists($field, $expectedMetadata) && ($actualMetadata[$field] ?? null) !== $expectedMetadata[$field]) {
                    return 'stored workflow metadata differs';
                }
            }
        }

        return null;
    }

    private function frequencyFromPeriods(array $periods): ?string
    {
        $first = strtolower((string) ($periods[0] ?? ''));
        return str_contains($first, 'quarter') ? 'quarterly' : (str_contains($first, 'semester') ? 'semestral' : null);
    }
}
