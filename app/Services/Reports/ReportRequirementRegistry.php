<?php

namespace App\Services\Reports;

use App\Models\ModuleDefinition;
use App\Services\BusinessCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The single runtime source for report requirement definitions and schedules.
 *
 * ModuleDefinition stores the editable identity/activation fields while
 * requirement_metadata stores the existing workflow-specific schedule and
 * document rules. Submission rows are deliberately not part of this service.
 */
final class ReportRequirementRegistry
{
    public const PA = 'pa';
    public const ENGP = 'engp';

    /** @var array<string,Collection<int,ModuleDefinition>> */
    private array $models = [];

    public function forgetCache(): void
    {
        $this->models = [];
    }

    public function definitions(?string $domain = null, bool $activeOnly = false, ?string $implementation = null): Collection
    {
        return ($domain !== null ? $this->modelsForDomain($domain) : ModuleDefinition::query()->notRetired()->get())
            ->when($implementation, fn ($definitions) => $definitions->where('implementation_type', $implementation))
            ->map(fn (ModuleDefinition $module): array => $this->definitionFromModel($module))
            ->filter(fn (array $definition): bool => ! $activeOnly || $this->isActiveForYear($definition, null))
            ->values();
    }

    public function find(string $domain, string $key, bool $activeOnly = false): ?array
    {
        $module = $this->modelsForDomain($domain)->first(function (ModuleDefinition $candidate) use ($key, $domain): bool {
            return $candidate->requirement_key === $key
                || $candidate->code === $key
                || ($domain === self::ENGP && $candidate->code === 'engp_'.$key);
        });

        if (! $module) {
            return null;
        }

        $definition = $this->definitionFromModel($module);

        return ! $activeOnly || $this->isActiveForYear($definition, null) ? $definition : null;
    }

    /**
     * Return year choices from the canonical definition horizon, existing
     * records, and the current year. The short forward horizon keeps future
     * planning usable without embedding a calendar year in application code.
     *
     * @param list<int|string> $existingYears
     * @return list<int>
     */
    public function availableYears(string $domain, array $existingYears = [], ?int $currentYear = null): array
    {
        $currentYear ??= CarbonImmutable::now('Asia/Manila')->year;
        $years = collect($existingYears)
            ->map(fn ($year): int => (int) $year)
            ->filter(fn (int $year): bool => $year >= 2000 && $year <= 2100)
            ->merge(range($currentYear, min(2100, $currentYear + 2)));

        foreach ($this->definitions($domain, true) as $definition) {
            foreach ([$definition['first_applicable_year'] ?? null, $definition['effective_from'] ? CarbonImmutable::parse($definition['effective_from'])->year : null] as $year) {
                if (is_int($year) && $year >= 2000 && $year <= 2100) {
                    $years->push($year);
                }
            }
        }

        return $years->unique()->sortDesc()->values()->all();
    }

    public function model(string $domain, string $key): ?ModuleDefinition
    {
        return $this->modelsForDomain($domain)->first(function (ModuleDefinition $candidate) use ($key, $domain): bool {
            return $candidate->requirement_key === $key
                || $candidate->code === $key
                || ($domain === self::ENGP && $candidate->code === 'engp_'.$key);
        });
    }

    /** @return Collection<int,ModuleDefinition> */
    private function modelsForDomain(string $domain): Collection
    {
        return $this->models[$domain] ??= ModuleDefinition::query()
            ->notRetired()
            ->requirementDomain($domain)
            ->orderByRaw('display_order IS NULL')->orderBy('display_order')->orderBy('name')
            ->get();
    }

    /** @return list<array{key:string,label:string,deadline?:string}> */
    public function periods(string $domain, string $key, int $year): array
    {
        $definition = $this->find($domain, $key);
        if (! $definition || ! $this->isActiveForYear($definition, $year)) {
            return [];
        }

        $frequency = strtolower((string) ($definition['reporting_frequency'] ?? $definition['period'] ?? 'custom'));
        $metadata = $definition['requirement_metadata'] ?? [];
        $periods = $domain === self::PA
            && is_array($metadata['periods'] ?? null)
            && ($metadata['canonical_frequency'] ?? $frequency) === $frequency
            ? $this->explicitPeriods($definition, $year)
            : match ($frequency) {
            'monthly' => array_map(fn (int $month): array => [
                'key' => sprintf('%d-%02d', $year, $month),
                'label' => CarbonImmutable::create($year, $month, 1)->format('F Y'),
            ], range(1, 12)),
            'quarterly' => array_map(fn (int $quarter): array => ['key' => "Q{$quarter}", 'label' => "Quarter {$quarter}"], range(1, 4)),
            'weekly' => $this->weeklyPeriods($year, $metadata),
            'semestral', 'semester' => array_map(fn (int $semester): array => ['key' => "S{$semester}", 'label' => "Semester {$semester}"], [1, 2]),
            'annual' => [['key' => (string) $year, 'label' => (string) $year]],
            default => $this->explicitPeriods($definition, $year),
        };

        return array_values(array_filter($periods, fn (array $period): bool => $this->periodIsEffective($definition, $year, $period['key'])));
    }

    public function period(string $domain, string $key, int $year, string $periodKey): ?array
    {
        return collect($this->periods($domain, $key, $year))->firstWhere('key', $periodKey);
    }

    public function deadline(string $domain, string $key, int $year, string $periodKey): ?string
    {
        $period = $this->period($domain, $key, $year, $periodKey);
        $definition = $this->find($domain, $key);
        if (! $period || ! $definition) {
            return null;
        }

        $frequency = strtolower((string) ($definition['reporting_frequency'] ?? $definition['period'] ?? 'custom'));
        $metadata = $definition['requirement_metadata'] ?? [];

        if ($domain === self::ENGP) {
            $mode = $definition['deadline_mode'] ?? ModuleDefinition::DEADLINE_CUSTOM;
            if ($mode !== ModuleDefinition::DEADLINE_CUSTOM) {
                if ($mode === ModuleDefinition::DEADLINE_NONE) return null;
                $range = $this->periodRange($year, $periodKey, $frequency);
                $days = (int) ($definition['default_deadline_days'] ?? 0);
                if (! $range || $days <= 0) return null;
                if ($mode === ModuleDefinition::DEADLINE_CALENDAR_DAYS) return $range[1]->addDays($days)->toDateString();
                if ($mode === ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS) {
                    return app(BusinessCalendarService::class)->addWorkingDays($range[1], $days, null, BusinessCalendarService::STANDARD_WORKING_WEEKDAYS)->toDateString();
                }
            }
            if ($frequency === 'monthly') {
                [$periodYear, $month] = array_map('intval', explode('-', $periodKey));
                return CarbonImmutable::create($periodYear, $month, (($definition['requirement_key'] ?? '') === 'rims' && $month === 1) ? 29 : 20)->toDateString();
            }
            if ($frequency === 'quarterly') {
                return CarbonImmutable::create($year, ((int) substr($periodKey, 1)) * 3, 10)->toDateString();
            }

            return $period['deadline'] ?? null;
        }

        $range = $this->periodRange($year, $periodKey, $frequency);
        if (! $range) {
            return null;
        }

        $days = (int) ($definition['default_deadline_days'] ?? 0);
        if ($days <= 0) {
            return null;
        }

        $mode = $definition['deadline_mode'] ?? ModuleDefinition::DEADLINE_NONE;
        if ($mode === ModuleDefinition::DEADLINE_CALENDAR_DAYS) {
            return $range[1]->addDays($days)->toDateString();
        }
        if ($mode === ModuleDefinition::DEADLINE_PAMB_WORKING_DAYS) {
            return app(BusinessCalendarService::class)->addWorkingDays($range[1], $days, null, BusinessCalendarService::PAMB_WORKING_WEEKDAYS)->toDateString();
        }
        if ($mode === ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS) {
            return app(BusinessCalendarService::class)->addConservationWorkingDays($range[1], $days)->toDateString();
        }

        return $metadata['deadline_policy']['fixed_date'] ?? null;
    }

    /** @return list<array{key:string,label:string}> */
    public function releaseComponents(string $domain, string $key, int $year, string $periodKey): array
    {
        $definition = $this->find($domain, $key);
        $period = $this->period($domain, $key, $year, $periodKey);
        if (! $definition || ! $period) {
            return [];
        }

        if (($definition['reporting_frequency'] ?? $definition['period'] ?? '') !== 'quarterly') {
            return [['key' => 'period', 'label' => $period['label']]];
        }

        $quarter = (int) substr($periodKey, 1);
        return array_map(fn (int $month): array => [
            'key' => CarbonImmutable::create($year, $month, 1)->format('Y-m'),
            'label' => CarbonImmutable::create($year, $month, 1)->format('F'),
        ], range(($quarter - 1) * 3 + 1, $quarter * 3));
    }

    /**
     * Generate deterministic expected requirement identities. Targets are
     * supplied by the caller because organizational PA scope belongs to the
     * caller, not to the definition registry.
     *
     * @param list<int|string|array<string,mixed>> $targets
     * @return Collection<int,array<string,mixed>>
     */
    public function generate(string $domain, int $year, array $targets = []): Collection
    {
        return $this->definitions($domain, true)
            ->flatMap(function (array $definition) use ($domain, $year, $targets): array {
                $periods = $this->periods($domain, $definition['key'], $year);
                $candidateTargets = $targets !== [] ? $targets : $this->defaultTargets($domain, $definition);
                if ($candidateTargets === []) {
                    $candidateTargets = [null];
                }

                $generated = [];
                foreach ($candidateTargets as $target) {
                    [$targetType, $targetId, $targetLabel] = $this->target($domain, $target);
                    foreach ($periods as $period) {
                        $generated[] = [
                            'domain' => $domain,
                            'workflow_key' => $definition['key'],
                            'definition_id' => $definition['id'],
                            'target_type' => $targetType,
                            'target_id' => $targetId,
                            'target_label' => $targetLabel,
                            'protected_area_id' => $domain === self::PA ? $targetId : null,
                            'office' => $domain === self::ENGP ? $targetLabel : null,
                            'reporting_year' => $year,
                            'period_key' => $period['key'],
                            'period_label' => $period['label'],
                            'deadline' => $this->deadline($domain, $definition['key'], $year, $period['key']),
                            'identity' => $this->identity($domain, $definition['key'], $targetType, $targetId, $year, $period['key']),
                            'definition' => $definition,
                        ];
                    }
                }

                return $generated;
            })->values();
    }

    public function identity(string $domain, string $key, ?string $targetType, int|string|null $targetId, int $year, string $periodKey): string
    {
        return implode('|', [$domain, $key, $targetType ?? 'none', (string) ($targetId ?? 'none'), $year, $periodKey]);
    }

    /** @return array<string,mixed> */
    private function definitionFromModel(ModuleDefinition $module): array
    {
        $metadata = is_array($module->requirement_metadata) ? $module->requirement_metadata : [];
        $domain = $module->requirement_domain ?: ($module->program_area?->value === 'engp' ? self::ENGP : self::PA);
        $key = $module->requirement_key ?: ($domain === self::ENGP && str_starts_with((string) $module->code, 'engp_') ? substr((string) $module->code, 5) : (string) $module->code);
        $frequency = $module->reporting_frequency ?: ($metadata['period'] ?? null);

        return [
            ...$metadata,
            'id' => $module->id,
            'key' => $key,
            'code' => $module->code,
            'label' => $module->name,
            'description' => $module->description ?? ($metadata['description'] ?? null),
            'domain' => $domain,
            'program_area' => $module->program_area?->value,
            'implementation_type' => $module->implementation_type,
            'module_type' => $module->module_type,
            'existing_route_name' => $module->existing_route_name,
            'existing_source_key' => $module->existing_source_key,
            'reporting_frequency' => $frequency,
            'deadline_mode' => $module->deadline_mode,
            'default_deadline_days' => $module->default_deadline_days,
            'allow_deadline_override' => $module->allow_deadline_override,
            'is_active' => $module->is_active,
            'effective_from' => $module->effective_from?->toDateString(),
            'effective_to' => $module->effective_to?->toDateString(),
            'first_applicable_year' => $module->first_applicable_year,
            'requirement_metadata' => $metadata,
        ];
    }

    private function isActiveForYear(array $definition, ?int $year): bool
    {
        if (! ($definition['is_active'] ?? false)) {
            return false;
        }
        if ($year !== null && isset($definition['first_applicable_year']) && $definition['first_applicable_year'] > $year) {
            return false;
        }
        if ($year !== null && filled($definition['effective_to']) && $definition['effective_to'] < sprintf('%d-01-01', $year)) {
            return false;
        }
        return ! ($year !== null && filled($definition['effective_from']) && $definition['effective_from'] > sprintf('%d-12-31', $year));
    }

    private function periodIsEffective(array $definition, int $year, string $periodKey): bool
    {
        $range = $this->periodRange($year, $periodKey, strtolower((string) ($definition['reporting_frequency'] ?? $definition['period'] ?? 'custom')));
        $date = $range[0] ?? CarbonImmutable::create($year, 1, 1);
        if (filled($definition['effective_from']) && $date->lessThan(CarbonImmutable::parse($definition['effective_from']))) return false;
        if (filled($definition['effective_to']) && $date->greaterThan(CarbonImmutable::parse($definition['effective_to']))) return false;
        return true;
    }

    /** @return list<array{key:string,label:string}> */
    private function explicitPeriods(array $definition, int $year): array
    {
        $periods = $definition['periods'] ?? ($definition['requirement_metadata']['periods'] ?? []);
        return array_map(fn (string $label): array => ['key' => Str::slug($label, '_'), 'label' => $label], array_values($periods));
    }

    /** @return list<array{key:string,label:string,deadline?:string}> */
    private function weeklyPeriods(int $year, array $metadata): array
    {
        $start = CarbonImmutable::create($year, 1, 1);
        while ($start->dayOfWeekIso !== 1) {
            $start = $start->addDay();
        }
        $periods = [];
        $number = 0;
        $excluded = collect($metadata['weekly']['excluded_start_dates'] ?? [])->map(fn ($date): string => (string) $date)->all();
        while ($start->year === $year) {
            if (! in_array($start->toDateString(), $excluded, true)) {
                $number++;
                $end = $start->addDays(3);
                $labelMonth = $start->format('F').($end->month !== $start->month ? '-'.$end->format('F') : '');
                $weekInMonth = count(array_filter($periods, fn (array $period): bool => str_starts_with($period['label'], $labelMonth.' Week '))) + 1;
                $initialDeadline = $metadata['weekly']['initial_deadline_day'] ?? 20;
                $deadline = $number <= 2
                    ? $start->setDay(min($initialDeadline, $start->daysInMonth))->toDateString()
                    : $end->toDateString();
                $periods[] = ['key' => sprintf('W%02d', $number), 'label' => "{$labelMonth} Week {$weekInMonth} ({$start->format('M j')}-{$end->format('M j')})", 'deadline' => $deadline];
            }
            $start = $start->addWeek();
        }
        return $periods;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable}|null */
    private function periodRange(int $year, string $periodKey, string $frequency): ?array
    {
        if ($frequency === 'monthly' && preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $match)) {
            $start = CarbonImmutable::create((int) $match[1], (int) $match[2], 1);
            return [$start, $start->endOfMonth()];
        }
        if ($frequency === 'quarterly' && preg_match('/^Q([1-4])$/', $periodKey, $match)) {
            $start = CarbonImmutable::create($year, (((int) $match[1]) - 1) * 3 + 1, 1);
            return [$start, $start->addMonths(2)->endOfMonth()];
        }
        if ($frequency === 'quarterly' && preg_match('/^Quarter\s+([1-4])$/i', $periodKey, $match)) {
            $start = CarbonImmutable::create($year, (((int) $match[1]) - 1) * 3 + 1, 1);
            return [$start, $start->addMonths(2)->endOfMonth()];
        }
        if ($frequency === 'quarterly' && preg_match('/^quarter_([1-4])$/i', $periodKey, $match)) {
            $start = CarbonImmutable::create($year, (((int) $match[1]) - 1) * 3 + 1, 1);
            return [$start, $start->addMonths(2)->endOfMonth()];
        }
        if (in_array($frequency, ['semestral', 'semester'], true) && preg_match('/^S([12])$/', $periodKey, $match)) {
            $start = CarbonImmutable::create($year, ((int) $match[1] - 1) * 6 + 1, 1);
            return [$start, $start->addMonths(5)->endOfMonth()];
        }
        if (in_array($frequency, ['semestral', 'semester'], true) && preg_match('/^(?:1st|2nd)_semester$/i', $periodKey, $match)) {
            $semester = str_starts_with(strtolower($match[0]), '1st') ? 1 : 2;
            $start = CarbonImmutable::create($year, ($semester - 1) * 6 + 1, 1);
            return [$start, $start->addMonths(5)->endOfMonth()];
        }
        if ($frequency === 'annual') {
            return [CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)];
        }
        return null;
    }

    /** @return list<mixed> */
    private function defaultTargets(string $domain, array $definition): array
    {
        if ($domain === self::ENGP) {
            return $definition['offices'] ?? [];
        }
        return [];
    }

    /** @return array{0:?string,1:int|string|null,2:?string} */
    private function target(string $domain, mixed $target): array
    {
        if ($target === null) return [null, null, null];
        if (is_array($target)) {
            $label = $target['label'] ?? $target['name'] ?? $target['office'] ?? null;
            $id = $domain === self::PA
                ? ($target['id'] ?? $target['protected_area_id'] ?? null)
                : ($target['target_office_key'] ?? $target['id'] ?? (is_string($label) ? Str::slug($label, '_') : null));
            return [$domain === self::PA ? 'protected_area' : 'development_office', $id, $label];
        }
        return [$domain === self::PA ? 'protected_area' : 'development_office', $domain === self::PA ? $target : Str::slug((string) $target, '_'), (string) $target];
    }
}
