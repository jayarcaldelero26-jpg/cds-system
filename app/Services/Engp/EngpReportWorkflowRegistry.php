<?php

namespace App\Services\Engp;

use Carbon\CarbonImmutable;
use App\Services\Reports\ReportRequirementRegistry;

final class EngpReportWorkflowRegistry
{
    private const OFFICES = ['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon'];

    /** @var array<string, array<string, mixed>> */
    private const WORKFLOWS = [
        'cbep' => ['label' => 'Community-Based Employment Program (CBEP)', 'activity' => 'Community-Based Employment Program (CBEP)', 'document' => 'Monthly Report', 'period' => 'monthly'],
        'elcac' => ['label' => 'End Local Communist Armed Conflict (ELCAC)', 'activity' => 'End Local Communist Armed Conflict (ELCAC)', 'document' => 'Monthly Report', 'period' => 'monthly'],
        'ngp_staff_accomplishment' => ['label' => 'NGP Staff Monthly Accomplishment', 'activity' => 'Actual Accomplishment of Hired NGP Staff', 'document' => 'Monthly Report', 'period' => 'monthly', 'offices' => ['CENRO Baganga', 'CENRO Mati', 'CENRO Lupon']],
        'forest_disturbance' => ['label' => 'Forest Disturbance', 'activity' => 'Forest Disturbance', 'document' => 'Monthly Report', 'period' => 'monthly'],
        'monthly_accomplishment_pmd_fmb' => ['label' => 'Monthly Accomplishment Reports using PMD and FMB Template', 'activity' => 'Monthly Accomplishment using PMD and FMB Template', 'document' => 'Monthly Report', 'period' => 'monthly'],
        'cenro_nursery_seedling' => ['label' => 'CENRO Nursery Seedling Production and Disposition', 'activity' => 'CENRO Nursery Seedling Production and Disposition', 'document' => 'Monthly Report', 'period' => 'monthly'],
        'tree_replacement' => ['label' => 'Tree Replacement', 'activity' => 'Tree Replacement', 'document' => 'Weekly Report', 'period' => 'monthly'],
        'rims' => ['label' => 'Updating and Operationalization of RIMS for NGP Physical Accomplishments', 'activity' => 'Updating and Operationalization of RIMS for NGP Physical Accomplishments', 'document' => 'Monthly Report', 'period' => 'monthly'],
        'ngp_produce' => ['label' => 'NGP Produce', 'activity' => 'ENGP Produce', 'document' => 'Quarterly Report', 'period' => 'quarterly'],
        'nursery_maintenance' => ['label' => 'P/CENRO Nursery Maintenance', 'activity' => 'CENRO Nursery Maintenance', 'document' => 'Quarterly Report', 'period' => 'quarterly'],
        'site_visit' => ['label' => 'Site Visit', 'activity' => 'ENGP Site Visit Report', 'document' => 'Quarterly Report', 'period' => 'quarterly'],
        'weekly_accomplishment' => ['label' => 'ENGP Weekly Accomplishment', 'activity' => 'Weekly Accomplishment', 'document' => 'Weekly Report', 'period' => 'weekly'],
    ];

    public function find(string $key): ?array
    {
        return app(ReportRequirementRegistry::class)->find(ReportRequirementRegistry::ENGP, $key)
            ?? $this->defaultFind($key);
    }

    /** Static defaults used only when seeding/backfilling canonical definitions. */
    public function defaultFind(string $key): ?array
    {
        $workflow = self::WORKFLOWS[$key] ?? null;
        if (! $workflow) {
            return null;
        }

        return [
            ...$workflow,
            'key' => $key,
            'offices' => $workflow['offices'] ?? self::OFFICES,
            ...($workflow['period'] === 'weekly' ? [
                'weekly' => [
                    'excluded_start_dates' => ['2026-08-31'],
                    'initial_deadline_day' => 20,
                ],
            ] : []),
        ];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->allKeys();
    }

    /** @param list<int|string> $existingYears */
    public function availableYears(array $existingYears = [], ?int $currentYear = null): array
    {
        return app(ReportRequirementRegistry::class)->availableYears(ReportRequirementRegistry::ENGP, $existingYears, $currentYear);
    }

    /** @return list<string> */
    public function defaultKeys(): array
    {
        return array_keys(self::WORKFLOWS);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $definitions = app(ReportRequirementRegistry::class)
            ->definitions(ReportRequirementRegistry::ENGP, true)
            ->all();

        return $definitions !== []
            ? $definitions
            : array_map(fn (string $key): array => $this->defaultFind($key), $this->defaultKeys());
    }

    /** @return list<string> */
    private function allKeys(): array
    {
        try {
            return collect($this->all())->pluck('key')->all();
        } catch (\Throwable) {
            return $this->defaultKeys();
        }
    }

    /** @return list<array{key: string, label: string}> */
    public function periods(string $workflowKey, int $year): array
    {
        $periods = app(ReportRequirementRegistry::class)->periods(ReportRequirementRegistry::ENGP, $workflowKey, $year);
        if ($periods !== [] || app(ReportRequirementRegistry::class)->find(ReportRequirementRegistry::ENGP, $workflowKey)) {
            return $periods;
        }

        $workflow = $this->defaultFind($workflowKey);
        if (! $workflow) return [];
        return match ($workflow['period']) {
            'monthly' => array_map(fn (int $month): array => ['key' => sprintf('%d-%02d', $year, $month), 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y')], range(1, 12)),
            'quarterly' => array_map(fn (int $quarter): array => ['key' => "Q{$quarter}", 'label' => "Quarter {$quarter}"], range(1, 4)),
            'weekly' => $this->weeklyPeriods($year),
            default => [],
        };
    }

    public function period(string $workflowKey, int $year, string $periodKey): ?array
    {
        $period = app(ReportRequirementRegistry::class)->period(ReportRequirementRegistry::ENGP, $workflowKey, $year, $periodKey);
        if ($period !== null || app(ReportRequirementRegistry::class)->find(ReportRequirementRegistry::ENGP, $workflowKey)) {
            return $period;
        }

        return collect($this->periods($workflowKey, $year))->firstWhere('key', $periodKey);
    }

    public function deadline(string $workflowKey, int $year, string $periodKey): ?string
    {
        $canonical = app(ReportRequirementRegistry::class);
        if ($canonical->find(ReportRequirementRegistry::ENGP, $workflowKey)) {
            return $canonical->deadline(ReportRequirementRegistry::ENGP, $workflowKey, $year, $periodKey);
        }

        if (! $this->period($workflowKey, $year, $periodKey)) return null;
        $workflow = $this->defaultFind($workflowKey);
        if ($workflow['period'] === 'monthly') {
            [$periodYear, $month] = array_map('intval', explode('-', $periodKey));
            return CarbonImmutable::create($periodYear, $month, $workflowKey === 'rims' && $month === 1 ? 29 : 20)->toDateString();
        }
        if ($workflow['period'] === 'quarterly') return CarbonImmutable::create($year, ((int) substr($periodKey, 1)) * 3, 10)->toDateString();
        return data_get(collect($this->periods($workflowKey, $year))->firstWhere('key', $periodKey), 'deadline');
    }

    /** @return list<array{key: string, label: string}> */
    public function releaseComponents(string $workflowKey, int $year, string $periodKey): array
    {
        $canonical = app(ReportRequirementRegistry::class);
        if ($canonical->find(ReportRequirementRegistry::ENGP, $workflowKey)) {
            return $canonical->releaseComponents(ReportRequirementRegistry::ENGP, $workflowKey, $year, $periodKey);
        }

        $workflow = $this->defaultFind($workflowKey);
        if (! $workflow || ! $this->period($workflowKey, $year, $periodKey)) return [];
        if ($workflow['period'] !== 'quarterly') {
            $period = $this->period($workflowKey, $year, $periodKey);
            return [['key' => 'period', 'label' => $period['label']]];
        }
        $quarter = (int) substr($periodKey, 1);
        return array_map(fn (int $month): array => ['key' => CarbonImmutable::create($year, $month, 1)->format('Y-m'), 'label' => CarbonImmutable::create($year, $month, 1)->format('F')], range(($quarter - 1) * 3 + 1, $quarter * 3));
    }

    /** @return list<array{key: string, label: string, deadline: string}> */
    private function weeklyPeriods(int $year): array
    {
        $periods = [];
        $start = CarbonImmutable::create($year, 1, 1);
        while ($start->dayOfWeekIso !== 1) {
            $start = $start->addDay();
        }
        $number = 0;
        $excludedStartDates = $year === 2026 ? ['2026-08-31'] : [];
        while ($start->year === $year) {
            if (! in_array($start->toDateString(), $excludedStartDates, true)) {
                $number++;
                $end = $start->addDays(3);
                $startLabel = $start->format('M j');
                $endLabel = $end->format('M j');
                $labelMonth = $start->format('F').($end->month !== $start->month ? '-'.$end->format('F') : '');
                $weekInMonth = count(array_filter($periods, fn (array $period): bool => str_starts_with($period['label'], $labelMonth.' Week '))) + 1;
                $periods[] = [
                    'key' => sprintf('W%02d', $number),
                    'label' => "{$labelMonth} Week {$weekInMonth} ({$startLabel}-{$endLabel})",
                    'deadline' => in_array($number, [1, 2], true)
                        ? $start->setDay(min(20, $start->daysInMonth))->toDateString()
                        : $end->toDateString(),
                ];
            }
            $start = $start->addWeek();
        }

        return $periods;
    }
}
