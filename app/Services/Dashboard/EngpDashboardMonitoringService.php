<?php

namespace App\Services\Dashboard;

use App\Models\ComplianceNotificationRun;
use App\Models\EngpReportSubmission;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Compliance\OverdueReportService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Engp\EngpMonitoringStatusResolver;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Server-side ENGP monitoring presentation and aggregation.
 *
 * Expected requirements come from the active ENGP workflow registry. Actual
 * submissions are joined to those requirements by the database uniqueness
 * dimensions (workflow, office, year, period), so a missing row is a real
 * pending requirement rather than an omitted row in the dashboard.
 */
final class EngpDashboardMonitoringService
{
    private const TIMEZONE = 'Asia/Manila';
    private const PER_PAGE = 25;
    private const FREQUENCIES = ['weekly', 'monthly', 'quarterly'];

    public function __construct(
        private readonly EngpReportWorkflowRegistry $workflows,
        private readonly OrganizationalAccessService $organization,
        private readonly RoutingStatusPresenter $statuses,
        private readonly OverdueReportService $alerts,
        private readonly EngpMonitoringStatusResolver $monitoringStatuses,
    ) {}

    /** @return array<string, mixed> */
    public function overview(array $filters = []): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $year = $this->year($filters['year'] ?? $today->year);
        $frequency = $this->frequency($filters['frequency'] ?? '');
        $office = trim((string) ($filters['office'] ?? ''));
        $period = trim((string) ($filters['period'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $allowedOffices = $this->allowedOffices();
        $authorized = $this->canView();

        $requirements = $authorized
            ? $this->requirements($year, $frequency, $office, $period, $search, $allowedOffices)
            : collect();

        $submissions = $authorized && $requirements->isNotEmpty()
            ? $this->submissions($year, $requirements, $office, $search)
            : collect();
        $submissionByKey = $submissions->keyBy(fn (EngpReportSubmission $submission): string => $this->key(
            (string) $submission->workflow_key,
            (string) $submission->office,
            (string) $submission->period_key,
        ));

        $rows = $requirements->map(function (array $requirement) use ($submissionByKey, $today): array {
            $submission = $submissionByKey->get($this->key($requirement['workflow_key'], $requirement['office'], $requirement['period_key']));
            $deadline = $submission?->deadline_submission?->toDateString() ?: $requirement['deadline'];
            $received = $submission?->date_received_penro?->toDateString();
            $monitoringStatus = $this->monitoringStatuses->resolve($deadline, $received, $today);
            $submitted = $monitoringStatus['submitted'];
            $overdue = $monitoringStatus['not_yet_submitted'];
            $dateReleased = $submission?->releaseEvents
                ? $submission->releaseEvents->map(fn ($event) => $event->date_report_released_cenro?->toDateString())->filter()->sort()->last()
                : null;
            $movRecorded = $submission !== null && (filled($submission->mov_file_path) || filled($submission->mov_external_url));
            $workflow = $requirement['workflow'];

            return [
                'id' => $submission ? 'engp-'.$submission->id : 'requirement-'.$this->key($requirement['workflow_key'], $requirement['office'], $requirement['period_key']),
                'source' => 'engp',
                'source_id' => $submission?->id,
                'workflow_key' => $requirement['workflow_key'],
                'office' => $requirement['office'],
                'report' => $workflow['label'],
                'activity' => $workflow['activity'],
                'document_type' => $workflow['document'],
                'frequency' => ucfirst((string) $workflow['period']),
                'frequency_key' => $workflow['period'],
                'reporting_period' => $requirement['period_label'],
                'period_key' => $requirement['period_key'],
                'deadline' => $deadline,
                'date_received' => $received,
                'date_released_cenro' => $dateReleased,
                'days_complied' => $submission?->days_complied,
                'timeliness' => $submission?->timeliness_rating,
                'monitoring_status_key' => $monitoringStatus['key'],
                'monitoring_status' => $monitoringStatus['label'],
                'status' => $monitoringStatus['label'],
                'submission_status' => $submission ? $this->statuses->status($submission, 'engp') : 'Pending Submission by CENRO',
                'record_source' => $submission ? 'Actual encoded submission' : 'Scheduled requirement',
                'submission_matched' => $submission !== null,
                'mov_status' => $submission === null ? null : ($movRecorded ? 'Recorded' : 'Not yet recorded'),
                'submitted' => $submitted,
                'pending' => $monitoringStatus['within_preparation'] || $monitoringStatus['ongoing_preparation'],
                'within_preparation_period' => $monitoringStatus['within_preparation'],
                'ongoing_preparation' => $monitoringStatus['ongoing_preparation'],
                'not_yet_submitted' => $monitoringStatus['not_yet_submitted'],
                'overdue' => $overdue,
                'source_url' => route('engp-reports.index', ['workflow' => $requirement['workflow_key'], 'office' => $requirement['office'], 'period_key' => $requirement['period_key'], 'year' => $requirement['year']]),
            ];
        });

        // The sort key is internal and never serialized to the client.
        $rows = $rows->map(fn (array $row): array => [...$row, 'deadline_sort' => $row['deadline'] ?: '9999-12-31'])
            ->sortBy([
                ['overdue', 'desc'],
                ['deadline_sort', 'asc'],
                ['office', 'asc'],
                ['report', 'asc'],
            ])
            ->map(fn (array $row): array => collect($row)->except('deadline_sort')->all())
            ->values();

        $summary = $this->summary($rows);
        $officePerformance = $this->groupPerformance($rows, 'office');
        $reportTypeCompliance = $this->groupPerformance($rows, 'workflow_key');
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);

        return [
            'summary' => $summary,
            'officePerformance' => $officePerformance->values()->all(),
            'reportTypeCompliance' => $reportTypeCompliance->values()->all(),
            'rows' => $rows->forPage($page, self::PER_PAGE)->values()->all(),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => self::PER_PAGE,
                'total' => $total,
            ],
            'alerts' => $authorized ? $this->alertSummary($allowedOffices) : $this->emptyAlerts(),
            'filterOptions' => $this->filterOptions($year, $allowedOffices, $frequency),
            'filters' => [
                'year' => $year,
                'period' => $period,
                'office' => $office,
                'frequency' => $frequency ?: 'all',
                'search' => $search,
                'page' => $page,
            ],
            'formulas' => [
                'expected' => 'Applicable active registry requirements for the selected year, period, frequency, and authorized offices.',
                'submitted' => 'Scheduled requirements with a non-null PENRO receipt date.',
                'pending' => 'Scheduled requirements not submitted whose authoritative deadline is today or later.',
                'overdue' => 'Scheduled requirements not submitted whose authoritative deadline is before today.',
                'monitoring_statuses' => 'Presentation-only labels resolved from the authoritative deadline, receipt date, and configured reminder window.',
                'compliance_rate' => 'Submitted applicable requirements / expected applicable requirements * 100.',
            ],
            'canViewComplianceAlerts' => (bool) auth()->user()?->can('compliance-alerts.manage'),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function requirements(int $year, string $frequency, string $office, string $period, string $search, array $allowedOffices): Collection
    {
        $requirements = collect();
        foreach ($this->workflows->all() as $workflow) {
            if ($frequency !== '' && $workflow['period'] !== $frequency) continue;
            $offices = collect($workflow['offices'] ?? [])->filter(fn (string $candidate): bool => in_array($candidate, $allowedOffices, true));
            foreach ($this->workflows->periods($workflow['key'], $year) as $periodDefinition) {
                if ($period !== '' && $period !== $periodDefinition['key'] && mb_strtolower($period) !== mb_strtolower($periodDefinition['label'])) continue;
                $deadline = $this->workflows->deadline($workflow['key'], $year, $periodDefinition['key']);
                if ($deadline === null) continue;
                foreach ($offices as $candidate) {
                    if ($office !== '' && $candidate !== $office) continue;
                    $searchText = mb_strtolower(implode(' ', [$candidate, $workflow['label'], $workflow['activity'], $periodDefinition['label']]));
                    if ($search !== '' && ! str_contains($searchText, mb_strtolower($search))) continue;
                    $requirements->push([
                        'workflow_key' => $workflow['key'],
                        'workflow' => $workflow,
                        'office' => $candidate,
                        'year' => $year,
                        'period_key' => $periodDefinition['key'],
                        'period_label' => $periodDefinition['label'],
                        'deadline' => $deadline,
                    ]);
                }
            }
        }

        return $requirements;
    }

    /** @return Collection<int, EngpReportSubmission> */
    private function submissions(int $year, Collection $requirements, string $office, string $search): Collection
    {
        $query = EngpReportSubmission::query()
            ->with('releaseEvents')
            ->select(['id', 'workflow_key', 'office', 'activity_name', 'document_type', 'reporting_year', 'period_key', 'period_label', 'deadline_submission', 'date_received_penro', 'mov_file_path', 'mov_external_url'])
            ->where('reporting_year', $year)
            ->whereIn('workflow_key', $requirements->pluck('workflow_key')->unique()->all())
            ->whereIn('period_key', $requirements->pluck('period_key')->unique()->all())
            ->whereIn('office', $requirements->pluck('office')->unique()->all());

        if ($office !== '') $query->where('office', $office);
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $needle = '%'.$search.'%';
                $searchQuery->where('office', 'like', $needle)->orWhere('activity_name', 'like', $needle)->orWhere('period_label', 'like', $needle);
            });
        }

        return $query->get();
    }

    /** @return array<string, int|float> */
    private function summary(Collection $rows): array
    {
        $expected = $rows->count();
        $submitted = $rows->where('submitted', true)->count();
        return [
            'expected' => $expected,
            'scheduled_requirements' => $expected,
            'submitted' => $submitted,
            'reports_submitted' => $submitted,
            'pending' => $rows->where('pending', true)->count(),
            'within_preparation_period' => $rows->where('within_preparation_period', true)->count(),
            'ongoing_preparation' => $rows->where('ongoing_preparation', true)->count(),
            'not_yet_submitted' => $rows->where('not_yet_submitted', true)->count(),
            'overdue' => $rows->where('overdue', true)->count(),
            'compliance_rate' => $this->rate($submitted, $expected),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function groupPerformance(Collection $rows, string $groupKey): Collection
    {
        return $rows->groupBy($groupKey)->map(function (Collection $items, string $key) use ($groupKey): array {
            $expected = $items->count();
            $submitted = $items->where('submitted', true)->count();
            $first = $items->first();
            return [
                ($groupKey === 'office' ? 'office' : 'workflow_key') => $key,
                'label' => $groupKey === 'office' ? $key : $first['report'],
                'activity' => $groupKey === 'office' ? null : $first['activity'],
                'frequency' => $groupKey === 'office' ? null : $first['frequency'],
                'expected' => $expected,
                'scheduled_requirements' => $expected,
                'submitted' => $submitted,
                'reports_submitted' => $submitted,
                'pending' => $items->where('pending', true)->count(),
                'within_preparation_period' => $items->where('within_preparation_period', true)->count(),
                'ongoing_preparation' => $items->where('ongoing_preparation', true)->count(),
                'not_yet_submitted' => $items->where('not_yet_submitted', true)->count(),
                'overdue' => $items->where('overdue', true)->count(),
                'compliance_rate' => $this->rate($submitted, $expected),
            ];
        })->sortByDesc(fn (array $row): array => [$row['overdue'], $row['compliance_rate']])->values();
    }

    /** @return array<string, mixed> */
    private function alertSummary(array $allowedOffices): array
    {
        $alertCounts = $this->alerts->engpDashboardAlertSummary($allowedOffices);
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $runs = ComplianceNotificationRun::query()
            ->whereDate('run_date', $today)
            ->whereIn('run_type', [ComplianceNotificationRun::TYPE_AUTOMATIC, ComplianceNotificationRun::TYPE_MANUAL])
            ->where('status', ComplianceNotificationRun::STATUS_SENT)
            ->latest('sent_at')
            ->get(['id', 'run_date', 'sent_at', 'recipients', 'payload']);
        $visibleRuns = $runs->filter(fn (ComplianceNotificationRun $run): bool => $this->runContainsOffice($run, $allowedOffices));
        $last = $visibleRuns->first();

        return [
            'due_within_3_days' => $alertCounts['due_within_3_days'],
            'due_today' => $alertCounts['due_today'],
            'overdue' => $alertCounts['overdue'],
            'alerts_sent_today' => $visibleRuns->count(),
            'last_memorandum_sent' => $last?->sent_at?->toIso8601String(),
            'recent_recipient_offices' => $visibleRuns->flatMap(fn (ComplianceNotificationRun $run): Collection => collect(data_get($run->payload, 'groups', []))->pluck('target_office')->merge($run->recipients ?? []))
                ->filter(fn ($value): bool => is_string($value) && in_array($value, $allowedOffices, true))
                ->unique()->values()->all(),
            'view_url' => auth()->user()?->can('compliance-alerts.manage') ? route('compliance-alerts.index') : null,
        ];
    }

    private function runContainsOffice(ComplianceNotificationRun $run, array $allowedOffices): bool
    {
        $offices = collect(data_get($run->payload, 'groups', []))->pluck('target_office')->merge($run->recipients ?? []);
        return $offices->contains(fn ($office): bool => in_array($office, $allowedOffices, true));
    }

    /** @return array<string, mixed> */
    private function filterOptions(int $year, array $allowedOffices, string $frequency = ''): array
    {
        $periods = collect();
        foreach ($this->workflows->all() as $workflow) {
            if ($frequency !== '' && $workflow['period'] !== $frequency) continue;
            foreach ($this->workflows->periods($workflow['key'], $year) as $period) {
                $periods->push(['value' => $period['key'], 'label' => $period['label'], 'frequency' => ucfirst($workflow['period'])]);
            }
        }

        $existingYears = $this->organization->scopeDevelopmentQuery(EngpReportSubmission::query(), auth()->user())
            ->select('reporting_year')->distinct()->pluck('reporting_year')->all();
        $years = $this->workflows->availableYears($existingYears, $year);

        return [
            'years' => $years,
            'periods' => $periods->unique('value')->sortBy('value')->values()->all(),
            'offices' => array_values($allowedOffices),
            'frequencies' => [
                ['value' => 'all', 'label' => 'All Frequencies'],
                ['value' => 'weekly', 'label' => 'Weekly'],
                ['value' => 'monthly', 'label' => 'Monthly'],
                ['value' => 'quarterly', 'label' => 'Quarterly'],
            ],
        ];
    }

    /** @return list<string> */
    private function allowedOffices(): array
    {
        $offices = collect($this->workflows->all())->flatMap(fn (array $workflow): array => $workflow['offices'] ?? [])->unique()->values();
        $user = auth()->user();
        if (! $user) return $offices->all();
        return $offices->filter(fn (string $office): bool => $this->organization->canUseDevelopmentOffice($user, $office))->values()->all();
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $this->organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT)
            && ($this->organization->isGlobal($user) || $user->getAllPermissions()->contains(fn ($permission): bool => $permission->name === 'technical-reports.view'));
    }

    private function year(mixed $value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT);
        return $year && $year >= 2000 && $year <= 2100 ? (int) $year : CarbonImmutable::now(self::TIMEZONE)->year;
    }

    private function frequency(mixed $value): string
    {
        $frequency = strtolower(trim((string) $value));
        return in_array($frequency, self::FREQUENCIES, true) ? $frequency : '';
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    private function key(string $workflow, string $office, string $period): string
    {
        return implode('|', [$workflow, mb_strtolower(trim($office)), $period]);
    }

    /** @return array<string, mixed> */
    private function emptyAlerts(): array
    {
        return ['due_within_3_days' => 0, 'due_today' => 0, 'overdue' => 0, 'alerts_sent_today' => 0, 'last_memorandum_sent' => null, 'recent_recipient_offices' => [], 'view_url' => null];
    }
}
