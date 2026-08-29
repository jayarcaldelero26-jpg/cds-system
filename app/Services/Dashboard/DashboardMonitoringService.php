<?php

namespace App\Services\Dashboard;

use App\Support\OfficeProtectedAreaPresenter;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/** Read-only presentation aggregation for the main eDATS monitoring dashboard. */
final class DashboardMonitoringService
{
    private const TIMEZONE = 'Asia/Manila';

    public function __construct(private readonly SubmissionTrackingService $tracking) {}

    /** @return array<string, mixed> */
    public function overview(array $filters = []): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $all = $this->tracking->records()->map(fn (array $row): array => $this->present($row, $today));
        $year = (int) ($filters['year'] ?? $today->year);
        $program = in_array($filters['program'] ?? 'all', ['all', 'conservation', 'engp'], true) ? $filters['program'] ?? 'all' : 'all';
        $office = trim((string) ($filters['office'] ?? ''));
        $period = trim((string) ($filters['period'] ?? ''));

        $rows = $all
            ->filter(fn (array $row): bool => $row['reporting_year'] === $year)
            ->when($program !== 'all', fn (Collection $items) => $items->where('program_key', $program))
            ->when($office !== '', fn (Collection $items) => $items->where('office_or_pa', $office))
            ->when($period !== '', fn (Collection $items) => $items->where('reporting_period', $period))
            ->sortBy([
                ['priority_rank', 'asc'],
                ['deadline_sort', 'asc'],
                ['source_label', 'asc'],
            ])
            ->values();

        $submitted = $rows->where('submitted', true);
        $overdue = $rows->where('is_overdue', true);
        $onTime = $submitted->where('is_on_time', true);
        $late = $submitted->where('is_on_time', false);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 20;

        return [
            'summary' => [
                'tracked_reports' => $rows->count(),
                'reports_due' => $rows->where('submitted', false)->filter(fn (array $row): bool => $row['deadline_submission'] !== null)->count(),
                'submitted' => $submitted->count(),
                'overdue' => $overdue->count(),
                'compliant' => $onTime->count(),
            ],
            'rows' => $rows->forPage($page, $perPage)->values()->all(),
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $rows->count(),
            ],
            'timeliness' => [
                ['name' => 'On Time / Compliant', 'value' => $onTime->count(), 'color' => '#15803d'],
                ['name' => 'Late', 'value' => $late->count(), 'color' => '#f59e0b'],
                ['name' => 'Overdue', 'value' => $overdue->count(), 'color' => '#dc2626'],
            ],
            'upcomingDeadlines' => $rows
                ->where('submitted', false)
                ->filter(fn (array $row): bool => $row['deadline_submission'] !== null && ! $row['is_overdue'])
                ->sortBy('deadline_sort')
                ->take(6)
                ->values()
                ->all(),
            'filterOptions' => [
                'years' => $all->pluck('reporting_year')->filter()->push($today->year)->unique()->sortDesc()->values()->all(),
                'programs' => [
                    ['value' => 'all', 'label' => 'All Programs'],
                    ['value' => 'conservation', 'label' => 'Conservation / Protected Area'],
                    ['value' => 'engp', 'label' => 'ENGP'],
                ],
                'offices' => $all->pluck('office_or_pa')->filter(fn (string $value): bool => $value !== '—')->unique()->sort()->values()->all(),
                'periods' => $all->pluck('reporting_period')->filter()->unique()->sort()->values()->all(),
            ],
            'filters' => ['year' => $year, 'program' => $program, 'office' => $office, 'period' => $period, 'page' => $page],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row, CarbonImmutable $today): array
    {
        $deadline = $this->date($row['deadline_submission'] ?? null);
        $accomplished = $this->date($row['date_accomplished'] ?? null);
        $released = $this->date($row['date_report_released_cenro'] ?? null);
        $received = $this->date($row['date_received_penro'] ?? null);
        $endorsed = $this->date($row['date_endorsed_regional'] ?? null);
        $isEngp = ($row['source'] ?? '') === 'engp';
        $submitted = ! empty($row['date_received_penro']);
        $daysComplied = $row['days_complied'] ?? null;
        $onTime = $submitted && ($isEngp
            ? is_numeric($daysComplied) && (int) $daysComplied >= 0
            : in_array($row['timeliness'] ?? null, ['Outstanding', 'Very Satisfactory', 'Satisfactory'], true));
        $overdue = ! $submitted && $deadline?->lessThan($today);
        $dueInDays = $deadline ? $today->diffInDays($deadline, false) : null;
        $officeOrPa = OfficeProtectedAreaPresenter::combine($row['target_office'] ?? null, $row['protected_area'] ?? null);
        $year = $isEngp
            ? (int) ($row['reporting_year'] ?? 0)
            : (int) ($accomplished?->year ?? $deadline?->year ?? 0);

        return [
            ...$row,
            // Actual date fields are normalized to nullable ISO strings so the
            // client never needs to parse legacy or semantic display values.
            'deadline_submission' => $deadline?->toDateString(),
            'date_accomplished' => $accomplished?->toDateString(),
            'date_report_released_cenro' => $released?->toDateString(),
            'date_received_penro' => $received?->toDateString(),
            'date_endorsed_regional' => $endorsed?->toDateString(),
            'release_events' => collect($row['release_events'] ?? [])->map(fn (array $event): array => [
                ...$event,
                'date_report_released_cenro' => $this->date($event['date_report_released_cenro'] ?? null)?->toDateString(),
            ])->values()->all(),
            'id' => ($row['source'] ?? 'report').'-'.($row['source_id'] ?? '0'),
            'source_label' => $row['module'] ?? 'Report',
            'program_key' => $isEngp ? 'engp' : 'conservation',
            'program' => $isEngp ? 'ENGP' : 'Conservation / Protected Area',
            'office_or_pa' => $officeOrPa,
            'reporting_year' => $year,
            'submitted' => $submitted,
            'is_overdue' => (bool) $overdue,
            'is_on_time' => (bool) $onTime,
            'due_in_days' => $dueInDays,
            'deadline_sort' => $deadline?->toDateString() ?? '9999-12-31',
            'priority_rank' => $overdue ? 1 : (($dueInDays !== null && $dueInDays >= 0 && $dueInDays <= 7) ? 2 : (! $submitted ? 3 : (($row['stage'] ?? '') === SubmissionTrackingService::REGIONAL_ENDORSEMENT ? 4 : 5))),
        ];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || $value === 'N/A' || $value === '—') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::TIMEZONE)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
