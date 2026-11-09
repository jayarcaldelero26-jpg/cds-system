<?php

namespace App\Services\Reports;

use App\Models\ProtectedArea;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Support\DatePresentationNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Management reporting over canonical requirements and recorded PENRO receipts. */
final class ExecutiveReportService
{
    private const TIMEZONE = 'Asia/Manila';

    private const OUTCOMES = [
        'on_time' => 'Received on time',
        'late' => 'Received late',
        'awaiting_receipt' => 'Awaiting PENRO receipt',
        'unverified' => 'Submission evidence missing',
        'missing' => 'No submission record',
        'no_deadline' => 'Deadline unavailable',
        'not_due' => 'Not due by cutoff',
    ];

    public function __construct(
        private readonly ReportRequirementRegistry $requirements,
        private readonly SubmissionTrackingService $tracking,
        private readonly OrganizationalAccessService $organization,
    ) {}

    /** @return array<string,mixed> */
    public function report(array $input = [], bool $includeDetails = false): array
    {
        $filters = $this->normalizeFilters($input);
        $user = auth()->user();
        $targets = $this->targets($user);
        $records = $this->tracking->records($this->trackingFilters($filters));
        $catalog = $this->expected($filters, $targets);
        $matches = $this->matchExpected($catalog, $records);
        $requirements = $this->forPeriod($catalog, $filters['period']);
        $periodKeys = $requirements->pluck('period_key')->unique();
        if ($filters['period'] !== '' && $periodKeys->count() === 1) {
            $filters['period'] = $periodKeys->first();
        }
        $canTrack = $user?->can('submission-tracking.view') ?? false;
        $rows = $this->rows($requirements, $matches, $filters['as_of_date'], $canTrack);
        $summary = $this->summary($rows);
        $comparison = null;

        if ($previous = $this->previousFilters($filters, $requirements)) {
            $sameYear = $previous['year'] === $filters['year'];
            $previousCatalog = $sameYear ? $catalog : $this->expected($previous, $targets);
            $previousRequirements = $this->forPeriod($previousCatalog, $previous['period']);
            if ($previousRequirements->isNotEmpty()) {
                $previousMatches = $sameYear ? $matches : $this->matchExpected(
                    $previousCatalog,
                    $this->tracking->records($this->trackingFilters($previous)),
                );
                $previousSummary = $this->summary($this->rows($previousRequirements, $previousMatches, $previous['as_of_date']));
                $comparison = [
                    'period_label' => $this->periodLabel($previous, $previousRequirements),
                    'as_of_date' => $previous['as_of_date'],
                    'due' => $previousSummary['due'],
                    'received' => $previousSummary['received'],
                    'receipt_rate' => $previousSummary['receipt_rate'],
                    'change_pp' => $summary['receipt_rate'] === null || $previousSummary['receipt_rate'] === null
                        ? null
                        : round($summary['receipt_rate'] - $previousSummary['receipt_rate'], 1),
                ];
            }
        }

        $details = $filters['status'] === '' ? $rows : $rows->filter(
            fn (array $row): bool => in_array($filters['status'], [$row['status'], $row['routing_status']], true),
        );
        $actions = $details->filter(fn (array $row): bool => $row['next_action'] !== null)
            ->sortBy(fn (array $row): array => [$row['due_date'] ?? '9999-12-31', $row['id']])
            ->values();
        $options = $this->filterOptions($records, $targets, $filters['year'], $filters['domain']);
        $filters['scope_label'] = $this->scopeLabel($filters, $targets, $options);
        $now = CarbonImmutable::now(self::TIMEZONE);

        return [
            'title' => 'Period Compliance Summary',
            'generated_at' => $now->toIso8601String(),
            'today' => $now->toDateString(),
            'period_label' => $this->periodLabel($filters, $requirements),
            'filters' => $filters,
            'filter_options' => $options,
            'summary' => $summary,
            'comparison' => $comparison,
            'breakdowns' => [
                'pa' => $this->breakdown($rows->where('domain', 'pa'), 'scope'),
                'office' => $this->breakdown($rows, 'office'),
                'family' => $this->breakdown($rows, 'report'),
            ],
            'actions' => $actions->take(5)->all(),
            'action_count' => $actions->count(),
            'details' => $includeDetails ? $details->values()->all() : [],
        ];
    }

    /** @return array<string,mixed> */
    public function normalizeFilters(array $input = []): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $year = filter_var($input['year'] ?? null, FILTER_VALIDATE_INT);
        $year = $year !== false && $year >= 2000 && $year <= 2100 ? (int) $year : $now->year;
        $domain = strtolower(trim((string) ($input['domain'] ?? 'all')));
        if (! in_array($domain, ['all', 'pa', 'engp'], true)) $domain = 'all';

        $areaId = $domain === 'engp' ? '' : trim((string) ($input['protected_area_id'] ?? ''));
        if ($areaId !== '') {
            $this->organization->assertCanAccessProtectedArea(auth()->user(), $areaId);
            $domain = 'pa';
        }
        $asOf = trim((string) ($input['as_of_date'] ?? '')) ?: $now->toDateString();
        if (DatePresentationNormalizer::toDateString($asOf) !== $asOf || $asOf > $now->toDateString()) {
            throw ValidationException::withMessages(['as_of_date' => 'Select a valid cutoff date on or before today in Manila.']);
        }

        $filters = [
            'year' => $year,
            'period' => trim((string) ($input['period'] ?? '')),
            'domain' => $domain,
            'office' => trim((string) ($input['office'] ?? '')),
            'protected_area_id' => $areaId,
            'workflow' => trim((string) ($input['workflow'] ?? '')),
            'status' => trim((string) ($input['status'] ?? '')),
            'as_of_date' => $asOf,
        ];
        if ($filters['status'] !== '' && ! in_array($filters['status'], $this->statusOptions(), true)) {
            throw ValidationException::withMessages(['status' => 'Select a valid detail status.']);
        }
        if ($filters['office'] !== '' && $domain !== 'pa' && auth()->user() && ! $this->organization->canUseDevelopmentOffice(auth()->user(), $filters['office'])) {
            throw ValidationException::withMessages(['office' => 'You are not authorized to view this office.']);
        }

        return $filters;
    }

    /** @return array<string,mixed> */
    private function trackingFilters(array $filters): array
    {
        return array_filter([
            'program' => $filters['domain'] === 'pa' ? 'conservation' : ($filters['domain'] === 'engp' ? 'engp' : null),
            'reporting_year' => $filters['year'],
            'target_office' => $filters['office'] ?: null,
            'protected_area_id' => $filters['protected_area_id'] ?: null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /** @return Collection<int,ProtectedArea> */
    private function targets(?object $user): Collection
    {
        if (! $user) return collect();

        return $this->organization
            ->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')
            ->with('supervisingOfficeAssignment.office:id,code,name')
            ->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int,array<string,mixed>> */
    private function expected(array $filters, Collection $targets): Collection
    {
        $rows = collect();
        if ($filters['domain'] !== 'engp') {
            $areas = $targets->filter(fn (ProtectedArea $area): bool =>
                ($filters['protected_area_id'] === '' || (string) $area->id === $filters['protected_area_id'])
                && ($filters['office'] === '' || $area->supervisingOfficeAssignment?->office?->name === $filters['office'])
            )->keyBy('id');
            if ($areas->isNotEmpty()) {
                $rows = $this->requirements->generate(ReportRequirementRegistry::PA, $filters['year'], $areas
                    ->map(fn (ProtectedArea $area): array => ['id' => $area->id, 'name' => $area->name])->values()->all())
                    ->map(fn (array $row): array => [
                        ...$row,
                        'family' => $row['definition']['label'] ?? $row['workflow_key'],
                        'office' => $areas->get($row['protected_area_id'])?->supervisingOfficeAssignment?->office?->name,
                    ]);
            }
        }

        if ($filters['domain'] !== 'pa') {
            $offices = $this->developmentOffices();
            if ($filters['office'] !== '') {
                $offices = $offices->filter(fn (string $office): bool => $office === $filters['office']);
            }
            if ($offices->isNotEmpty()) {
                $rows = $rows->merge($this->requirements->generate(ReportRequirementRegistry::ENGP, $filters['year'])
                    ->filter(fn (array $row): bool => $offices->containsStrict($row['office']))
                    ->map(fn (array $row): array => [...$row, 'family' => $row['definition']['label'] ?? $row['workflow_key']]));
            }
        }

        return $rows->filter(fn (array $row): bool => $filters['workflow'] === '' || $row['workflow_key'] === $filters['workflow'])
            ->unique('identity')->values();
    }

    private function forPeriod(Collection $catalog, string $period): Collection
    {
        return $period === '' ? $catalog : $catalog->filter(
            fn (array $row): bool => $this->periodMatches($period, $row['period_label'], $row['period_key']),
        )->values();
    }

    /** @return Collection<int,string> */
    private function developmentOffices(): Collection
    {
        $offices = $this->requirements->definitions(ReportRequirementRegistry::ENGP, true)
            ->flatMap(fn (array $definition): array => $definition['offices'] ?? [])
            ->filter(fn ($office): bool => is_string($office))->unique()->values();
        $user = auth()->user();
        if (! $user) return collect();
        return $offices->filter(fn (string $office): bool => $this->organization->canUseDevelopmentOffice($user, $office))->values();
    }

    private function periodMatches(string $selected, mixed $label, mixed $key): bool
    {
        $needle = strtolower(trim($selected));
        $values = [strtolower(trim((string) $label)), strtolower(trim((string) $key))];
        if (str_contains($needle, 'semester') || str_contains($needle, 'sem')) {
            $number = preg_match('/(?:semester|sem|s)[ _-]*([12])/', $needle, $match) ? $match[1] : (str_contains($needle, '2nd') ? '2' : '1');
            foreach ($values as $value) {
                if (str_contains($value, 'semester') || str_contains($value, 'sem')) {
                    $valueNumber = preg_match('/(?:semester|sem|s)[ _-]*([12])/', $value, $match) ? $match[1] : (str_contains($value, '2nd') ? '2' : '1');
                    if ($number === $valueNumber) return true;
                }
            }
        }
        return in_array($needle, $values, true)
            || str_replace([' ', '_', '-'], '', $needle) === str_replace([' ', '_', '-'], '', strtolower(trim((string) $label)));
    }

    /** Match each record to at most one requirement, preferring an exact registry key. */
    private function matchExpected(Collection $expected, Collection $actual): array
    {
        $groups = $expected->groupBy(fn (array $row): string => $this->scopeKey(
            $row['domain'],
            $row['reporting_year'],
            $row['workflow_key'],
            $row['domain'] === 'engp' ? $row['office'] : $row['protected_area_id'],
        ));
        $matches = [];
        foreach ($actual as $record) {
            $engp = ($record['source'] ?? null) === 'engp';
            $group = $groups->get($this->scopeKey(
                $engp ? 'engp' : 'pa',
                $record['reporting_year'] ?? null,
                $record['workflow_key'] ?? null,
                $engp ? ($record['target_office'] ?? null) : ($record['protected_area_id'] ?? null),
            ));
            if ($group === null) continue;

            $requirement = $group->firstWhere('period_key', $record['period_key'] ?? null);
            if ($requirement === null) {
                $candidates = $group->filter(fn (array $row): bool =>
                    $this->periodMatches($row['period_key'], $record['reporting_period'] ?? null, $record['period_key'] ?? null)
                    || $this->periodMatches($row['period_label'], $record['reporting_period'] ?? null, $record['period_key'] ?? null)
                );
                $requirement = $candidates->count() === 1 ? $candidates->first() : null;
            }
            if ($requirement !== null) $matches[$requirement['identity']][] = $record;
        }

        return $matches;
    }

    private function scopeKey(string $domain, mixed $year, mixed $workflow, mixed $target): string
    {
        return serialize([$domain, (int) $year, (string) $workflow, (string) $target]);
    }

    /** @return Collection<int,array<string,mixed>> */
    private function rows(Collection $requirements, array $matches, string $cutoff, bool $canTrack = false): Collection
    {
        return $requirements->map(function (array $requirement) use ($matches, $cutoff, $canTrack): array {
            $record = collect($matches[$requirement['identity']] ?? [])->sortBy(function (array $row) use ($cutoff): array {
                $receipt = $this->dateByCutoff($row['date_received_penro'] ?? null, $cutoff);
                $release = $this->dateByCutoff($row['date_report_released_cenro'] ?? null, $cutoff);

                return [$receipt !== null ? 0 : ($release !== null ? 1 : 2), $receipt ?? $release ?? '', $row['source'], $row['source_id']];
            })->first();
            $receipt = $this->dateByCutoff($record['date_received_penro'] ?? null, $cutoff);
            $release = $this->dateByCutoff($record['date_report_released_cenro'] ?? null, $cutoff);
            $deadline = DatePresentationNormalizer::toDateString($record['deadline_submission'] ?? null)
                ?? DatePresentationNormalizer::toDateString($requirement['deadline']);

            $state = match (true) {
                $deadline === null => 'no_deadline',
                $deadline > $cutoff => 'not_due',
                $receipt !== null && $receipt <= $deadline => 'on_time',
                $receipt !== null => 'late',
                $release !== null => 'awaiting_receipt',
                $record !== null => 'unverified',
                default => 'missing',
            };
            $nextAction = match ($state) {
                'awaiting_receipt' => 'Verify receipt with PENRO Records.',
                'unverified' => 'Verify the submission or release date.',
                'missing' => 'Follow up on the required submission.',
                'no_deadline' => 'Verify the reporting deadline.',
                default => null,
            };

            return [
                'id' => $requirement['identity'],
                'domain' => $requirement['domain'],
                'report' => $requirement['family'],
                'scope' => $requirement['target_label'],
                'office' => $requirement['office'] ?? $record['target_office'] ?? null,
                'period' => $requirement['period_label'],
                'due_date' => $deadline,
                'receipt_date' => $receipt,
                'release_date' => $release,
                'state' => $state,
                'status' => self::OUTCOMES[$state],
                'routing_status' => $record['submission_status'] ?? null,
                'tracking_number' => $record['tracking_number'] ?? null,
                'next_action' => $nextAction,
                'action_office' => $state === 'awaiting_receipt' ? 'PENRO Records' : ($requirement['office'] ?? $record['target_office'] ?? $requirement['target_label']),
                'tracking_url' => $canTrack && isset($record['source'], $record['source_id'])
                    ? route('submission-tracking.index', ['source' => $record['source'], 'source_id' => $record['source_id']])
                    : null,
            ];
        });
    }

    private function dateByCutoff(mixed $value, string $cutoff): ?string
    {
        $date = DatePresentationNormalizer::toDateString($value);

        return $date !== null && $date <= $cutoff ? $date : null;
    }

    /** @return array<string,int|float|null> */
    private function summary(Collection $rows): array
    {
        $counts = $rows->countBy('state');
        $onTime = $counts->get('on_time', 0);
        $late = $counts->get('late', 0);
        $received = $onTime + $late;
        $awaitingReceipt = $counts->get('awaiting_receipt', 0);
        $unverified = $counts->get('unverified', 0);
        $missing = $counts->get('missing', 0);
        $due = $received + $awaitingReceipt + $unverified + $missing;

        return [
            'due' => $due,
            'received' => $received,
            'on_time' => $onTime,
            'late' => $late,
            'awaiting_receipt' => $awaitingReceipt,
            'unverified' => $unverified,
            'missing' => $missing,
            'unresolved' => $due - $received,
            'no_deadline' => $counts->get('no_deadline', 0),
            'not_due' => $counts->get('not_due', 0),
            'receipt_rate' => $due > 0 ? round($received / $due * 100, 1) : null,
        ];
    }

    private function breakdown(Collection $rows, string $field): array
    {
        return $rows->groupBy(fn (array $row): string => $row[$field] ?: 'Unassigned')
            ->map(fn (Collection $items, string|int $label): array => ['label' => (string) $label, ...$this->summary($items)])
            ->sortBy([['unresolved', 'desc'], ['label', 'asc']])->values()->all();
    }

    /** Use the same elapsed cutoff against the preceding registry period. */
    private function previousFilters(array $filters, Collection $requirements): ?array
    {
        $cutoff = CarbonImmutable::parse($filters['as_of_date'], self::TIMEZONE);
        if ($filters['period'] === '') {
            return $filters['year'] > 2000 ? [
                ...$filters,
                'year' => $filters['year'] - 1,
                'as_of_date' => $cutoff->subYearNoOverflow()->toDateString(),
            ] : null;
        }

        if ($requirements->pluck('period_key')->unique()->count() !== 1) return null;
        $comparison = null;
        foreach ($requirements->unique('definition_id') as $row) {
            $domain = $row['domain'];
            $workflow = $row['workflow_key'];
            $year = $row['reporting_year'];
            $periods = $this->requirements->periods($domain, $workflow, $year);
            $index = array_search($row['period_key'], array_column($periods, 'key'), true);
            if ($index === false) return null;
            $previousYear = $index > 0 ? $year : $year - 1;
            if ($previousYear < 2000) return null;
            $previous = $index > 0 ? $periods[$index - 1] : collect($this->requirements->periods($domain, $workflow, $previousYear))->last();
            if ($previous === null) return null;

            $start = $this->requirements->periodStartDate($domain, $workflow, $year, $row['period_key']);
            $previousStart = $this->requirements->periodStartDate($domain, $workflow, $previousYear, $previous['key']);
            if ($start === null || $previousStart === null || $previousStart >= $start) return null;
            $days = (int) CarbonImmutable::parse($previousStart, self::TIMEZONE)->diffInDays(CarbonImmutable::parse($start, self::TIMEZONE));
            $candidate = [...$filters, 'year' => $previousYear, 'period' => $previous['key'], 'as_of_date' => $cutoff->subDays($days)->toDateString()];
            if ($comparison !== null && $comparison !== $candidate) return null;
            $comparison = $candidate;
        }

        return $comparison;
    }

    private function periodLabel(array $filters, Collection $requirements): string
    {
        if ($filters['period'] === '') return 'All periods in '.$filters['year'];
        $label = $requirements->first()['period_label'] ?? $filters['period'];

        return str_contains($label, (string) $filters['year']) ? $label : $label.' '.$filters['year'];
    }

    /** @return array<string,mixed> */
    private function filterOptions(Collection $actual, Collection $targets, int $year, string $domain = 'all'): array
    {
        $years = $this->requirements->availableYears('pa', $actual->pluck('reporting_year')->all(), $year);
        $years = collect($years)->merge($this->requirements->availableYears('engp', $actual->pluck('reporting_year')->all(), $year))->unique()->sortDesc()->values()->all();
        $definitions = $this->requirements->definitions(null, true)->when($domain !== 'all', fn (Collection $items): Collection => $items->where('domain', $domain));
        $families = $definitions->map(fn (array $definition): array => ['value' => $definition['key'], 'label' => $definition['label'], 'domain' => $definition['domain']])->values();
        $periods = $definitions->flatMap(fn (array $definition): Collection => collect($this->requirements->periods($definition['domain'], $definition['key'], $year))->map(fn (array $period): array => ['value' => $period['key'], 'label' => $period['label']]))->unique('value')->values()->all();
        $offices = $this->developmentOffices()->merge($targets->map(fn (ProtectedArea $area) => $area->supervisingOfficeAssignment?->office?->name))->filter()->unique()->sort()->values()->all();
        return ['years' => $years, 'offices' => $offices, 'protected_areas' => $targets->map(fn (ProtectedArea $area): array => ['id' => $area->id, 'name' => $area->name])->values()->all(), 'families' => $families->all(), 'periods' => $periods, 'statuses' => $this->statusOptions()];
    }

    /** @return list<string> */
    private function statusOptions(): array
    {
        return [
            ...array_values(self::OUTCOMES),
            RoutingStatusPresenter::COMPLETED,
            RoutingStatusPresenter::PENDING_CENRO,
            RoutingStatusPresenter::PENDING_PENRO,
            RoutingStatusPresenter::PENDING_REGIONAL,
            RoutingStatusPresenter::NO_ACTIVITY,
        ];
    }

    private function scopeLabel(array $filters, Collection $targets, array $options): string
    {
        $domain = match ($filters['domain']) {
            'pa' => 'Protected Areas',
            'engp' => 'ENGP',
            default => 'PA and ENGP',
        };
        $area = $targets->firstWhere('id', $filters['protected_area_id'])?->name;
        $family = collect($options['families'])->firstWhere('value', $filters['workflow'])['label'] ?? $filters['workflow'];

        return implode(' · ', array_filter([$domain, $area, $filters['office'], $family]));
    }
}
