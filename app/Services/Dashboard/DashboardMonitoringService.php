<?php

namespace App\Services\Dashboard;

use App\Support\OfficeProtectedAreaPresenter;
use App\Support\DatePresentationNormalizer;
use App\Models\OrganizationalOffice;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/** Read-only presentation aggregation for the main CDS-SMART monitoring dashboard. */
final class DashboardMonitoringService
{
    private const TIMEZONE = 'Asia/Manila';

    public function __construct(private readonly SubmissionTrackingService $tracking, private readonly OrganizationalAccessService $organization) {}

    /** @return array<string, mixed> */
    public function overview(array $filters = [], bool $assignTrackingNumbers = true): array
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
            'reporting_year' => $year,
            'target_office' => $office,
            'protected_area_id' => $protectedAreaId,
            'module' => $reportType,
            'reporting_period' => $period,
        ], fn (mixed $value): bool => filled($value));
        $all = $this->tracking->records($trackingFilters, null, $assignTrackingNumbers)->map(fn (array $row): array => $this->present($row, $today));
        $available = $this->tracking->filterOptions(['program' => $program]);

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
                'years' => $available['years'],
                'programs' => [
                    ['value' => 'conservation', 'label' => 'Conservation / Protected Area'],
                ],
                'offices' => $available['targetOffices'],
                'protectedAreas' => $all->filter(fn (array $row): bool => filled($row['protected_area_id']) && filled($row['protected_area']))
                    ->map(fn (array $row): array => ['id' => (int) $row['protected_area_id'], 'label' => $row['protected_area']])
                    ->unique('id')->sortBy('label')->values()->all(),
                'reportTypes' => $available['modules'],
                'periods' => $available['periods'],
            ],
            'paMatrix' => $paMatrix->values()->all(),
            'topOverdueReports' => $overdue->take(5)->map(fn (array $row): array => $this->overduePresentation($row, $today))->values()->all(),
            'complianceSnapshot' => [
                'tracked_reports' => $rows->count(),
                'submitted' => $submitted->count(),
                'pending' => $pending->count(),
                'overdue' => $overdue->count(),
                'compliance_rate' => $complianceRate,
            ],
            'executiveInterpretation' => $this->interpretation($rows, $paMatrix, $complianceRate),
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

    /**
     * Return a public-safe monthly submission trend. Only month labels and
     * aggregate counts leave the service; report rows remain internal.
     *
     * @return list<array{label:string,count:int}>
     */
    public function publicSubmissionTrend(int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $submittedDates = $this->tracking->records()
            ->map(fn (array $row): ?CarbonImmutable => $this->date($row['date_received_penro'] ?? null))
            ->filter()
            ->values();

        if ($submittedDates->isEmpty()) {
            return [];
        }

        $latestMonth = $submittedDates->max()->startOfMonth();
        $firstMonth = $latestMonth->subMonths($months - 1);

        return collect(range(0, $months - 1))
            ->map(function (int $offset) use ($firstMonth, $submittedDates): array {
                $month = $firstMonth->addMonths($offset);

                return [
                    'label' => $month->format('M y'),
                    'count' => $submittedDates->filter(fn (CarbonImmutable $date): bool => $date->year === $month->year && $date->month === $month->month)->count(),
                ];
            })
            ->all();
    }

    /**
     * Public-safe province-wide aggregates. This intentionally returns no
     * normalized rows, identities, routing metadata, documents, or remarks.
     * The classifications are derived from the same presented tracking rows
     * used by the authenticated dashboard.
     *
     * @return array<string, mixed>
     */
    public function publicSummary(): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $rows = $this->tracking->records(['reporting_year' => $today->year], null, false)
            ->map(fn (array $row): array => $this->present($row, $today));
        $programs = collect(['engp' => 'ENGP reports', 'conservation' => 'Protected Area reports'])
            ->map(function (string $label, string $key) use ($rows): array {
                $items = $rows->where('program_key', $key);
                $received = $items->where('submitted', true);
                $overdue = $items->where('is_overdue', true);
                $upcoming = $items->where('submitted', false)->filter(fn (array $row): bool => ! $row['is_overdue'] && filled($row['deadline_submission']));
                $due = $upcoming->count() + $overdue->count();
                return [
                    'key' => $key,
                    'label' => $label,
                    'office_label' => $key === 'engp' ? 'CENROs' : 'PAMOs / PA offices',
                    'office_count' => $items->pluck('target_office')->filter()->unique()->count(),
                    'due' => $due,
                    'received' => $received->count(),
                    'overdue' => $overdue->count(),
                    'upcoming' => $upcoming->count(),
                    'on_time' => $received->where('is_on_time', true)->count(),
                    'late' => $received->where('is_on_time', false)->count(),
                    'on_time_rate' => $this->rate($received->where('is_on_time', true)->count(), $received->count()),
                ];
            })->values()->all();
        $received = $rows->where('submitted', true);
        return [
            'as_of' => $today->toIso8601String(),
            'year' => $today->year,
            'totals' => [
                'due' => $rows->where('is_overdue', true)->count() + $rows->where('submitted', false)->filter(fn (array $row): bool => ! $row['is_overdue'] && filled($row['deadline_submission']))->count(),
                'received' => $received->count(),
                'overdue' => $rows->where('is_overdue', true)->count(),
                'on_time' => $received->where('is_on_time', true)->count(),
                'late' => $received->where('is_on_time', false)->count(),
            ],
            'programs' => $programs,
            'comparison' => $programs,
            'legend' => [
                ['label' => 'On time', 'description' => 'Officially received on or before the authoritative deadline.'],
                ['label' => 'Submitted late', 'description' => 'Officially received after the authoritative deadline.'],
                ['label' => 'Overdue', 'description' => 'Deadline passed without an official receipt.'],
                ['label' => 'Upcoming', 'description' => 'Deadline has not yet arrived.'],
            ],
        ];
    }

    /**
     * Read-only projection for the authenticated report-submission overview.
     * It deliberately starts with SubmissionTrackingService::records(), so
     * authorization, source registration, routing state, and AWS's report-only
     * scope remain the same as Submission Tracking.
     *
     * @return array<string, mixed>
     */
    public function submissionOverview(array $filters = [], bool $assignTrackingNumbers = false): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $year = (int) ($filters['year'] ?? $today->year);
        $program = in_array($filters['program'] ?? 'all', ['all', 'engp', 'pa'], true) ? ($filters['program'] ?? 'all') : 'all';
        $office = trim((string) ($filters['office'] ?? ''));
        $frequency = trim((string) ($filters['frequency'] ?? ''));

        $rows = $this->tracking->records(['reporting_year' => $year], null, $assignTrackingNumbers)
            ->map(fn (array $row): array => $this->overviewRow($this->present($row, $today), $today))
            ->when($program !== 'all', fn (Collection $items) => $items->where('program_key', $program === 'pa' ? 'conservation' : $program))
            ->when($office !== '', fn (Collection $items) => $items->where('office_or_pa', $office))
            ->when($frequency !== '', fn (Collection $items) => $items->where('frequency', $frequency))
            ->sortBy([['priority_rank', 'asc'], ['deadline_sort', 'asc'], ['source_label', 'asc']])
            ->values();

        $developmentOffices = $program === 'engp' ? $this->authorizedDevelopmentOffices() : [];
        $programs = collect([
            'engp' => ['title' => 'ENGP REPORTS', 'office_label' => 'CENROs'],
            'conservation' => ['title' => 'PA REPORTS', 'office_label' => 'PAMOs'],
        ])->map(function (array $definition, string $key) use ($rows, $today, $developmentOffices): array {
            $items = $rows->where('program_key', $key)->values();
            $received = $items->where('submitted', true);
            $eligibleReceived = $received->filter(fn (array $row): bool => $row['deadline_submission'] !== null);
            $early = $eligibleReceived->filter(fn (array $row): bool => ($row['day_delta'] ?? 0) > 0);
            $late = $eligibleReceived->filter(fn (array $row): bool => ($row['day_delta'] ?? 0) < 0);
            $pending = $items->where('dashboard_state', 'pending');
            $inProgress = $items->where('dashboard_state', 'in_progress');
            $overdue = $items->where('is_overdue', true);

            return [
                ...$definition,
                'key' => $key === 'conservation' ? 'pa' : $key,
                'office_count' => $key === 'engp' ? count($developmentOffices) : $items->pluck('office_or_pa')->filter()->unique()->count(),
                'metrics' => [
                    'pending' => $pending->count(),
                    'in_progress' => $inProgress->count(),
                    'overdue' => $overdue->count(),
                    'on_time_rate' => $this->rate($eligibleReceived->where('is_on_time', true)->count(), $eligibleReceived->count()),
                    'average_early' => $early->isEmpty() ? null : round($early->avg('day_delta'), 1),
                    'average_late' => $late->isEmpty() ? null : round($late->avg(fn (array $row): int => abs((int) $row['day_delta'])), 1),
                    'received' => $received->count(),
                    'on_time' => $eligibleReceived->where('is_on_time', true)->count(),
                    'late' => $late->count(),
                ],
                'pending_ongoing' => $items->whereIn('dashboard_state', ['pending', 'in_progress'])->take(5)->map(fn (array $row): array => $this->workRow($row))->values()->all(),
                'overdue_unreceived' => $overdue->sortByDesc('days_overdue')->take(5)->map(fn (array $row): array => $this->workRow($row))->values()->all(),
                'comparison' => $this->officeComparison($items),
                'timeliness_summary' => $this->timelinessSummary($items),
            ];
        })->values()->all();

        $offices = $program === 'engp'
            ? $developmentOffices
            : $rows->pluck('office_or_pa')->filter()->unique()->sort()->values()->all();
        $frequencies = $rows->pluck('frequency')->filter(fn (string $value): bool => $value !== 'Unclassified')->unique()->sort()->values()->all();
        $years = $this->tracking->filterOptions()['years'] ?? [$year];

        return [
            'as_of' => $today->toIso8601String(),
            'filters' => ['year' => $year, 'program' => $program, 'office' => $office, 'frequency' => $frequency],
            'filterOptions' => [
                'years' => $years,
                'offices' => $offices,
                'frequencies' => $frequencies,
            ],
            'programs' => $programs,
            'trackingRows' => $rows->take(50)->map(fn (array $row): array => $this->trackingRow($row))->values()->all(),
            'trackingTotal' => $rows->count(),
            'formulas' => [
                'pending' => 'Unreceived, not overdue, and still at the initial report-preparation stage.',
                'in_progress' => 'Unreceived, not overdue, and in a canonical active routing or correction stage.',
                'overdue' => 'Authoritative deadline is before the as-of date and the official PENRO receipt is absent.',
                'on_time' => 'Official PENRO receipt is on or before the authoritative deadline.',
                'days' => 'Days early and late use the authoritative deadline and official PENRO receipt; overdue days use the as-of date while unreceived.',
            ],
        ];
    }

    /** @return list<string> */
    private function authorizedDevelopmentOffices(): array
    {
        $user = auth()->user();
        if (! $user || ! $this->organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT)) return [];

        return OrganizationalOffice::query()
            ->where('is_active', true)
            ->where('office_type', OrganizationalAccessService::OPERATIONAL_GROUP_CENRO)
            ->whereIn('name', $this->organization->cenroOffices())
            ->orderBy('name')
            ->pluck('name')
            ->filter(fn (string $office): bool => $this->organization->canUseDevelopmentOffice($user, $office))
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function overviewRow(array $row, CarbonImmutable $today): array
    {
        $deadline = $this->date($row['deadline_submission'] ?? null);
        $received = $this->date($row['date_received_penro'] ?? null);
        $dayDelta = $deadline && $received ? $received->diffInDays($deadline, false) : null;
        $routing = $row['routing'] ?? [];
        $stage = (string) ($routing['current_stage'] ?? $row['stage'] ?? '');
        $activeRouting = ! ($row['routing_complete'] ?? false)
            && ($stage !== '' && $stage !== SubmissionTrackingService::CENRO_RELEASE || (bool) ($routing['correction'] ?? false) || filled($routing['last_action'] ?? null));
        $state = $row['submitted'] ? 'received' : ($row['is_overdue'] ? 'overdue' : ($activeRouting ? 'in_progress' : 'pending'));
        $status = $row['is_overdue'] ? 'Overdue' : ($row['submitted'] ? ($row['is_on_time'] ? 'On time' : 'Late') : ($state === 'in_progress' ? (string) ($routing['current_status'] ?? 'In progress') : 'Pending'));

        return [...$row,
            'frequency' => $this->frequency($row),
            'day_delta' => $dayDelta,
            'days_overdue' => $row['is_overdue'] && $deadline ? $deadline->diffInDays($today) : null,
            'dashboard_state' => $state,
            'dashboard_status' => $status,
            'next_action' => $routing['next_expected_action'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function frequency(array $row): string
    {
        $periodKey = strtolower((string) ($row['period_key'] ?? ''));
        $period = strtolower((string) ($row['reporting_period'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $periodKey)) return 'Monthly';
        if (preg_match('/^(q[1-4]|quarter\s*[1-4])/', $periodKey) || preg_match('/^(q[1-4]|quarter\s*[1-4])/', $period)) return 'Quarterly';
        if (str_contains($period, 'annual') || str_contains($periodKey, 'annual')) return 'Annual';
        if (str_contains($period, 'semester') || str_contains($period, 'semestral')) return 'Semestral';
        return 'Unclassified';
    }

    /** @param Collection<int,array<string,mixed>> $items @return list<array<string,mixed>> */
    private function officeComparison(Collection $items): array
    {
        return $items->filter(fn (array $row): bool => $row['frequency'] !== 'Unclassified')
            ->groupBy('office_or_pa')->map(function (Collection $officeRows, string $office): array {
                $series = $officeRows->groupBy('frequency')->map(function (Collection $frequencyRows): array {
                    $eligible = $frequencyRows->where('submitted', true)->filter(fn (array $row): bool => $row['deadline_submission'] !== null);
                    return ['rate' => $this->rate($eligible->where('is_on_time', true)->count(), $eligible->count()), 'eligible' => $eligible->count()];
                });
                return ['office' => $office, 'series' => $series->all()];
            })->sortBy('office')->values()->all();
    }

    /** @param Collection<int,array<string,mixed>> $items @return list<array<string,mixed>> */
    private function timelinessSummary(Collection $items): array
    {
        return $items->filter(fn (array $row): bool => $row['frequency'] !== 'Unclassified')
            ->groupBy('frequency')->map(function (Collection $frequencyRows, string $frequency): array {
                $ranked = $frequencyRows->groupBy('office_or_pa')->map(function (Collection $officeRows, string $office): array {
                    $eligible = $officeRows->where('submitted', true)->filter(fn (array $row): bool => $row['deadline_submission'] !== null);
                    return ['office' => $office, 'rate' => $this->rate($eligible->where('is_on_time', true)->count(), $eligible->count()), 'eligible' => $eligible->count()];
                })->filter(fn (array $item): bool => $item['eligible'] > 0)->sortByDesc('rate')->values();
                return ['frequency' => $frequency, 'most_timely' => $ranked->first(), 'needs_follow_up' => $ranked->count() > 1 ? $ranked->sortBy('rate')->first() : null];
            })->sortBy('frequency')->values()->all();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function workRow(array $row): array
    {
        return [
            'id' => $row['id'], 'office' => $row['office_or_pa'] ?: '—', 'report' => $row['source_label'],
            'period' => $row['reporting_period'] ?: '—', 'due' => $row['deadline_submission'],
            'status' => $row['dashboard_status'], 'context' => $row['next_action'] ?: ($row['routing']['current_location'] ?? 'Awaiting report preparation'),
            'days_overdue' => $row['days_overdue'], 'timeliness' => $row['timeliness'] ?? null,
            'source_url' => route('submission-tracking.index', ['source' => $row['source'], 'source_id' => $row['source_id']]),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function trackingRow(array $row): array
    {
        return [...$this->workRow($row),
            'program' => $row['program_key'] === 'engp' ? 'ENGP' : 'PA', 'received' => $row['date_received_penro'],
            'days' => $row['days_overdue'] !== null ? $row['days_overdue'].' overdue' : ($row['day_delta'] === null ? '—' : ($row['day_delta'] > 0 ? $row['day_delta'].' early' : ($row['day_delta'] < 0 ? abs($row['day_delta']).' late' : '0 early'))),
            'next_action' => $row['next_action'] ?: ($row['routing']['current_location'] ?? '—'),
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
    private function interpretation(Collection $rows, Collection $matrix, float $complianceRate): array
    {
        if ($rows->isEmpty()) return [['label' => 'PA monitoring', 'text' => 'No tracked PA report records match the selected filters.']];

        $office = $rows->groupBy('office_or_pa')->map(function (Collection $items, string $label): array {
            $submitted = $items->where('submitted', true)->count();
            return ['label' => $label, 'rate' => $this->rate($submitted, $items->count())];
        })->filter(fn (array $item): bool => $item['rate'] > 0)->sortByDesc(fn (array $item): array => [$item['rate'], $item['label']])->first();
        $delayed = $matrix->sortByDesc(fn (array $item): array => [$item['overdue'], $item['pending'], $item['module']])->first();
        return array_values(array_filter([
            $office ? ['label' => 'Most compliant PA / office', 'text' => $office['label'].' at '.$this->formatRate($office['rate']).' submitted.'] : ['label' => 'Most compliant PA / office', 'text' => 'Insufficient submitted records to determine the most compliant PA.'],
            ['label' => 'Current PA compliance', 'text' => $this->formatRate($complianceRate).' of tracked PA reports submitted.'],
            $delayed && ($delayed['overdue'] > 0 || $delayed['pending'] > 0) ? ['label' => 'Most delayed workflow', 'text' => $delayed['module'].' has '.$delayed['overdue'].' overdue and '.$delayed['pending'].' pending.'] : null,
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
