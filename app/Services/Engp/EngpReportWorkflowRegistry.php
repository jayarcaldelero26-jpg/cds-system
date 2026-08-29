<?php

namespace App\Services\Engp;

use Carbon\CarbonImmutable;

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
        $workflow = self::WORKFLOWS[$key] ?? null;
        if (! $workflow) {
            return null;
        }

        return [...$workflow, 'key' => $key, 'offices' => $workflow['offices'] ?? self::OFFICES];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::WORKFLOWS);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(fn (string $key): array => $this->find($key), $this->keys());
    }

    /** @return list<array{key: string, label: string}> */
    public function periods(string $workflowKey, int $year): array
    {
        $workflow = $this->find($workflowKey);
        if (! $workflow || $year !== 2026) {
            return [];
        }

        return match ($workflow['period']) {
            'monthly' => array_map(fn (int $month): array => [
                'key' => sprintf('%d-%02d', $year, $month),
                'label' => CarbonImmutable::create($year, $month, 1)->format('F Y'),
            ], range(1, 12)),
            'quarterly' => array_map(fn (int $quarter): array => ['key' => "Q{$quarter}", 'label' => "Quarter {$quarter}"], range(1, 4)),
            'weekly' => $this->weeklyPeriods(),
            default => [],
        };
    }

    public function period(string $workflowKey, int $year, string $periodKey): ?array
    {
        return collect($this->periods($workflowKey, $year))->firstWhere('key', $periodKey);
    }

    public function deadline(string $workflowKey, int $year, string $periodKey): ?string
    {
        if (! $this->period($workflowKey, $year, $periodKey)) {
            return null;
        }

        $workflow = $this->find($workflowKey);
        if ($workflow['period'] === 'monthly') {
            [$periodYear, $month] = array_map('intval', explode('-', $periodKey));
            return CarbonImmutable::create($periodYear, $month, $workflowKey === 'rims' && $month === 1 ? 29 : 20)->toDateString();
        }
        if ($workflow['period'] === 'quarterly') {
            return CarbonImmutable::create($year, ((int) substr($periodKey, 1)) * 3, 10)->toDateString();
        }

        $period = $this->period($workflowKey, $year, $periodKey);
        return $period['deadline'] ?? null;
    }

    /** @return list<array{key: string, label: string}> */
    public function releaseComponents(string $workflowKey, int $year, string $periodKey): array
    {
        $workflow = $this->find($workflowKey);
        if (! $workflow || ! $this->period($workflowKey, $year, $periodKey)) {
            return [];
        }
        if ($workflow['period'] !== 'quarterly') {
            $period = $this->period($workflowKey, $year, $periodKey);
            return [['key' => 'period', 'label' => $period['label']]];
        }

        $quarter = (int) substr($periodKey, 1);
        return array_map(fn (int $month): array => ['key' => CarbonImmutable::create($year, $month, 1)->format('Y-m'), 'label' => CarbonImmutable::create($year, $month, 1)->format('F')], range(($quarter - 1) * 3 + 1, $quarter * 3));
    }

    /** @return list<array{key: string, label: string, deadline: string}> */
    private function weeklyPeriods(): array
    {
        $periods = [];
        $start = CarbonImmutable::create(2026, 1, 5);
        $number = 0;
        while ($start->year === 2026) {
            if ($start->toDateString() !== '2026-08-31') {
                $number++;
                $end = $start->addDays(3);
                $startLabel = $start->format('M j');
                $endLabel = $end->format('M j');
                $labelMonth = $start->format('F').($end->month !== $start->month ? '-'.$end->format('F') : '');
                $weekInMonth = count(array_filter($periods, fn (array $period): bool => str_starts_with($period['label'], $labelMonth.' Week '))) + 1;
                $periods[] = [
                    'key' => sprintf('W%02d', $number),
                    'label' => "{$labelMonth} Week {$weekInMonth} ({$startLabel}-{$endLabel})",
                    'deadline' => in_array($number, [1, 2], true) ? '2026-01-20' : $end->toDateString(),
                ];
            }
            $start = $start->addWeek();
        }

        return $periods;
    }
}
