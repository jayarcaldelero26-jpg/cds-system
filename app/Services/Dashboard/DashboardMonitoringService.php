<?php

namespace App\Services\Dashboard;

use App\Support\OfficeProtectedAreaPresenter;
use App\Support\DatePresentationNormalizer;
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
        $year = (int) ($filters['year'] ?? $today->year);
        $program = in_array($filters['program'] ?? 'all', ['all', 'conservation', 'engp'], true) ? $filters['program'] ?? 'all' : 'all';
        $office = trim((string) ($filters['office'] ?? ''));
        $protectedAreaId = trim((string) ($filters['protected_area_id'] ?? ''));
        $reportType = trim((string) ($filters['report_type'] ?? ''));
        $period = trim((string) ($filters['period'] ?? ''));
        $trackingFilters = array_filter([
            'program' => $program,
        ], fn (mixed $value): bool => filled($value));
        $all = $this->tracking->records($trackingFilters)->map(fn (array $row): array => $this->present($row, $today));

        $rows = $all
            ->filter(fn (array $row): bool => $row['reporting_year'] === $year)
            ->when($program !== 'all', fn (Collection $items) => $items->where('program_key', $program))
            ->when($office !== '', fn (Collection $items) => $items->where('target_office', $office))
            ->when($protectedAreaId !== '', fn (Collection $items) => $items->where('protected_area_id', (int) $protectedAreaId))
            ->when($reportType !== '', fn (Collection $items) => $items->where('module', $reportType))
            ->when($period !== '', fn (Collection $items) => $items->where('reporting_period', $period))
            ->sortBy([
                ['priority_rank', 'asc'],
                ['deadline_sort', 'asc'],
                ['source_label', 'asc'],
            ])
            ->values();

        $submitted = $rows->where('submitted', true);
        $overdue = $rows->where('is_overdue', true);
        $pending = $rows->where('submitted', false)->filter(fn (array $row): bool => $row['deadline_submission'] !== null && ! $row['is_overdue']);
        $onTime = $submitted->where('is_on_time', true);
        $late = $submitted->where('is_on_time', false);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 10;
        $complianceRate = $this->rate($submitted->count(), $rows->count());
        $paMatrix = $this->groupByModule($rows);
        $routing = $this->routingBottlenecks($rows);

        return [
            'summary' => [
                'tracked_reports' => $rows->count(),
                'reports_due' => $pending->count() + $overdue->count(),
                'submitted' => $submitted->count(),
                'pending' => $pending->count(),
                'overdue' => $overdue->count(),
                'compliant' => $onTime->count(),
                'compliance_rate' => $complianceRate,
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
                    ['value' => 'conservation', 'label' => 'Conservation / Protected Area'],
                ],
                'offices' => $all->pluck('target_office')->filter()->unique()->sort()->values()->all(),
                'protectedAreas' => $all->filter(fn (array $row): bool => filled($row['protected_area_id']) && filled($row['protected_area']))
                    ->map(fn (array $row): array => ['id' => (int) $row['protected_area_id'], 'label' => $row['protected_area']])
                    ->unique('id')->sortBy('label')->values()->all(),
                'reportTypes' => $all->pluck('module')->filter()->unique()->sort()->values()->all(),
                'periods' => $all->pluck('reporting_period')->filter()->unique()->sort()->values()->all(),
            ],
            'paMatrix' => $paMatrix->values()->all(),
            'routingBottlenecks' => $routing,
            'topOverdueReports' => $overdue->take(5)->map(fn (array $row): array => $this->overduePresentation($row, $today))->values()->all(),
            'complianceSnapshot' => [
                'tracked_reports' => $rows->count(),
                'submitted' => $submitted->count(),
                'pending' => $pending->count(),
                'overdue' => $overdue->count(),
                'compliance_rate' => $complianceRate,
            ],
            'executiveInterpretation' => $this->interpretation($rows, $paMatrix, $routing, $complianceRate),
            'formulas' => [
                'tracked_reports' => 'Actual authorized PA report-tracking records matching the selected filters.',
                'submitted' => 'Tracked PA records with a non-null PENRO receipt date.',
                'pending' => 'Tracked PA records without PENRO receipt whose authoritative deadline is today or later.',
                'overdue' => 'Tracked PA records without PENRO receipt whose authoritative deadline is before today.',
                'compliance_rate' => 'Submitted tracked PA records / tracked PA records * 100.',
            ],
            'filters' => ['year' => $year, 'program' => $program, 'office' => $office, 'protected_area_id' => $protectedAreaId, 'report_type' => $reportType, 'period' => $period, 'page' => $page],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function groupByModule(Collection $rows): Collection
    {
        return $rows->groupBy('module')->map(function (Collection $items, string $module): array {
            $tracked = $items->count();
            $submitted = $items->where('submitted', true)->count();
            return [
                'module' => $module,
                'tracked' => $tracked,
                'submitted' => $submitted,
                'pending' => $items->where('submitted', false)->filter(fn (array $row): bool => $row['deadline_submission'] !== null && ! $row['is_overdue'])->count(),
                'overdue' => $items->where('is_overdue', true)->count(),
                'compliance_rate' => $this->rate($submitted, $tracked),
            ];
        })->sortByDesc(fn (array $row): array => [$row['overdue'], $row['compliance_rate'], $row['module']])->values();
    }

    /** @return array<string, mixed> */
    private function routingBottlenecks(Collection $rows): array
    {
        $active = $rows->filter(fn (array $row): bool => ! ($row['routing_complete'] ?? false));
        $stages = $active->map(function (array $row): ?array {
            $label = data_get($row, 'routing.current_location')
                ?: ($row['current_document_location'] ?? null)
                ?: data_get($row, 'routing.current_status');
            if (! $label || $label === '—') return null;
            $pendingDays = data_get($row, 'routing.working_days_pending');
            if (! is_numeric($pendingDays)) $pendingDays = data_get($row, 'routing_summary.working_days_pending');
            return ['label' => $label, 'pending_days' => is_numeric($pendingDays) ? (float) $pendingDays : null];
        })->filter()->groupBy('label')->map(function (Collection $items, string $label): array {
            $days = $items->pluck('pending_days')->filter(fn (mixed $value): bool => is_numeric($value));
            return [
                'stage' => $label,
                'active_documents' => $items->count(),
                'average_pending_days' => $days->isNotEmpty() ? round($days->avg(), 1) : null,
                'maximum_pending_days' => $days->isNotEmpty() ? round($days->max(), 1) : null,
            ];
        })->sortByDesc(fn (array $row): array => [$row['active_documents'], $row['maximum_pending_days'] ?? -1])->values();

        $durations = $rows->flatMap(function (array $row): array {
            $metrics = $row['routing_summary_metrics'] ?? [];
            foreach (['cenro_to_regional', 'penro_to_regional', 'cenro_to_penro'] as $key) {
                if (($metrics[$key]['status'] ?? null) === 'ready' && is_numeric($metrics[$key]['value'] ?? null)) return [(float) $metrics[$key]['value']];
            }
            return [];
        });

        return [
            'stages' => $stages->take(6)->values()->all(),
            'average_routing_time' => $durations->isNotEmpty() ? round($durations->avg(), 1) : null,
            'routing_time_unit' => 'working days',
        ];
    }

    /** @return array<string, mixed> */
    private function overduePresentation(array $row, CarbonImmutable $today): array
    {
        $deadline = $this->date($row['deadline_submission'] ?? null);
        return [
            'id' => $row['id'],
            'report' => $row['module'] ?? $row['activity_name'] ?? 'PA Report',
            'activity' => $row['activity_name'] ?? null,
            'office_or_pa' => $row['office_or_pa'] ?? '—',
            'deadline' => $deadline?->toDateString(),
            'days_overdue' => $deadline ? max(0, $deadline->diffInDays($today)) : null,
            'current_stage' => data_get($row, 'routing.current_location') ?: ($row['current_document_location'] ?? $row['submission_status'] ?? '—'),
            'source_url' => $row['source_url'] ?? null,
        ];
    }

    /** @return list<array{label:string,text:string}> */
    private function interpretation(Collection $rows, Collection $matrix, array $routing, float $complianceRate): array
    {
        if ($rows->isEmpty()) return [['label' => 'PA monitoring', 'text' => 'No tracked PA report records match the selected filters.']];

        $office = $rows->groupBy('office_or_pa')->map(function (Collection $items, string $label): array {
            $submitted = $items->where('submitted', true)->count();
            return ['label' => $label, 'rate' => $this->rate($submitted, $items->count())];
        })->filter(fn (array $item): bool => $item['rate'] > 0)->sortByDesc(fn (array $item): array => [$item['rate'], $item['label']])->first();
        $delayed = $matrix->sortByDesc(fn (array $item): array => [$item['overdue'], $item['pending'], $item['module']])->first();
        $largestBottleneck = collect($routing['stages'] ?? [])->sortByDesc(fn (array $item): array => [$item['active_documents'], $item['maximum_pending_days'] ?? -1])->first();

        return array_values(array_filter([
            $office ? ['label' => 'Most compliant PA / office', 'text' => $office['label'].' at '.$this->formatRate($office['rate']).' submitted.'] : ['label' => 'Most compliant PA / office', 'text' => 'Insufficient submitted records to determine the most compliant PA.'],
            ['label' => 'Current PA compliance', 'text' => $this->formatRate($complianceRate).' of tracked PA reports submitted.'],
            $delayed && ($delayed['overdue'] > 0 || $delayed['pending'] > 0) ? ['label' => 'Most delayed workflow', 'text' => $delayed['module'].' has '.$delayed['overdue'].' overdue and '.$delayed['pending'].' pending.'] : null,
            $largestBottleneck ? ['label' => 'Largest routing bottleneck', 'text' => $largestBottleneck['stage'].' currently holds '.$largestBottleneck['active_documents'].' active document(s).'] : null,
        ]));
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    private function formatRate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
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
            return ($date = DatePresentationNormalizer::toDateString($value)) ? CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
