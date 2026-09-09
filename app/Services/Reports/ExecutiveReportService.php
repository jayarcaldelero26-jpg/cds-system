<?php

namespace App\Services\Reports;

use App\Models\EngpReportSubmission;
use App\Models\ProtectedArea;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Management-level report aggregation over canonical requirements and
 * operational submission-tracking records.
 */
final class ExecutiveReportService
{
    private const TIMEZONE = 'Asia/Manila';

    public function __construct(
        private readonly ReportRequirementRegistry $requirements,
        private readonly SubmissionTrackingService $tracking,
        private readonly OrganizationalAccessService $organization,
    ) {}

    /** @return array<string,mixed> */
    public function report(array $input = []): array
    {
        $filters = $this->normalizeFilters($input);
        $user = auth()->user();
        $targets = $this->targets($user);
        $actualFilters = $this->trackingFilters($filters);
        $actual = $this->tracking->records($actualFilters);
        $actual = $this->filterActual($actual, $filters);

        $expected = $this->expected($filters, $targets);
        $entries = $this->matchExpected($expected, $actual, $filters);
        $summary = $this->summary($entries, $actual);
        $timeliness = $this->timeliness($actual);
        $attention = $this->attention($entries);
        $filters['scope_label'] = $this->scopeLabel($filters, $targets);

        return [
            'title' => 'Executive Report Monitoring Summary',
            'generated_at' => CarbonImmutable::now(self::TIMEZONE)->toIso8601String(),
            'filters' => $filters,
            'filter_options' => $this->filterOptions($actual, $targets, $filters['year'], $filters['domain']),
            'coverage' => [
                'pa' => $filters['domain'] !== 'engp' ? 'registry' : 'not_selected',
                'engp' => $filters['domain'] !== 'pa' ? 'registry' : 'not_selected',
                'note' => 'Expected counts use active effective canonical requirements. Actual-only records outside the generated universe remain visible in operational drill-downs.',
            ],
            'summary' => $summary,
            'timeliness' => $timeliness,
            'pa_performance' => $this->groupPerformance($entries, 'pa'),
            'office_performance' => $this->groupPerformance($entries, 'office'),
            'family_performance' => $this->groupPerformance($entries, 'family'),
            'period_trend' => $this->periodTrend($entries),
            'attention' => $attention,
            'interpretation' => $this->interpretation($summary, $attention, $timeliness),
        ];
    }

    /** @return array<string,mixed> */
    public function normalizeFilters(array $input): array
    {
        $year = filter_var($input['year'] ?? null, FILTER_VALIDATE_INT);
        $year = $year !== false && $year >= 2000 && $year <= 2100 ? (int) $year : CarbonImmutable::now(self::TIMEZONE)->year;
        $domain = strtolower(trim((string) ($input['domain'] ?? 'all')));
        if (! in_array($domain, ['all', 'pa', 'engp'], true)) $domain = 'all';

        $filters = [
            'year' => $year,
            'period' => trim((string) ($input['period'] ?? '')),
            'domain' => $domain,
            'office' => trim((string) ($input['office'] ?? '')),
            'protected_area_id' => trim((string) ($input['protected_area_id'] ?? '')),
            'workflow' => trim((string) ($input['workflow'] ?? '')),
            'status' => trim((string) ($input['status'] ?? '')),
        ];

        if ($filters['protected_area_id'] !== '') {
            $this->organization->assertCanAccessProtectedArea(auth()->user(), $filters['protected_area_id']);
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
            'status' => $filters['status'] ?: null,
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
        if ($filters['domain'] !== 'engp' && $targets->isNotEmpty()) {
            $rows = $rows->merge($this->requirements->generate(ReportRequirementRegistry::PA, $filters['year'], $targets->map(fn (ProtectedArea $area): array => ['id' => $area->id, 'name' => $area->name])->all())
                ->map(function (array $row) use ($targets): array {
                    $area = $targets->firstWhere('id', $row['protected_area_id']);
                    return [...$row, 'family' => $row['definition']['label'] ?? $row['workflow_key'], 'office' => $area?->supervisingOfficeAssignment?->office?->name];
                }));
        }
        if ($filters['domain'] !== 'pa') {
            $offices = $this->developmentOffices();
            if ($filters['office'] !== '') $offices = $offices->filter(fn (string $office): bool => $office === $filters['office']);
            if ($offices->isNotEmpty()) {
                $rows = $rows->merge($this->requirements->generate(ReportRequirementRegistry::ENGP, $filters['year'], $offices->all())
                    ->map(fn (array $row): array => [...$row, 'family' => $row['definition']['label'] ?? $row['workflow_key'], 'office' => $row['target_label']]));
            }
        }

        return $rows
            ->filter(fn (array $row): bool => $this->matchesExpectedFilters($row, $filters))
            ->values();
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

    /** @return Collection<int,array<string,mixed>> */
    private function filterActual(Collection $actual, array $filters): Collection
    {
        return $actual->filter(function (array $row) use ($filters): bool {
            if (($row['reporting_year'] ?? null) !== null && (int) $row['reporting_year'] !== $filters['year']) return false;
            if ($filters['workflow'] !== '' && (string) ($row['workflow_key'] ?? '') !== $filters['workflow']) return false;
            if ($filters['period'] !== '' && ! $this->periodMatches($filters['period'], $row['reporting_period'] ?? null, $row['period_key'] ?? null)) return false;
            return true;
        })->values();
    }

    private function matchesExpectedFilters(array $row, array $filters): bool
    {
        if ($filters['workflow'] !== '' && $row['workflow_key'] !== $filters['workflow']) return false;
        if ($filters['office'] !== '' && ($row['office'] ?? null) !== $filters['office']) return false;
        return $filters['period'] === '' || $this->periodMatches($filters['period'], $row['period_label'] ?? null, $row['period_key'] ?? null);
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

    /** @return Collection<int,array<string,mixed>> */
    private function matchExpected(Collection $expected, Collection $actual, array $filters): Collection
    {
        $used = [];
        $entries = $expected->map(function (array $requirement) use ($actual, &$used): array {
            $candidate = $actual->first(function (array $row) use ($requirement): bool {
                if (($row['workflow_key'] ?? null) !== $requirement['workflow_key']) return false;
                if ((int) ($row['reporting_year'] ?? 0) !== (int) $requirement['reporting_year']) return false;
                if (($requirement['domain'] ?? null) === ReportRequirementRegistry::ENGP) {
                    if (($row['target_office'] ?? null) !== ($requirement['office'] ?? null)) return false;
                } elseif ((string) ($row['protected_area_id'] ?? '') !== (string) ($requirement['protected_area_id'] ?? '')) {
                    return false;
                }
                return $this->periodMatches($requirement['period_key'], $row['reporting_period'] ?? null, $row['period_key'] ?? null)
                    || $this->periodMatches($requirement['period_label'], $row['reporting_period'] ?? null, $row['period_key'] ?? null);
            });
            if ($candidate) $used[$this->recordKey($candidate['source'] ?? null, $candidate['source_id'] ?? null)] = true;
            return ['expected' => $requirement, 'actual' => $candidate];
        });

        $actualOnly = $actual->filter(fn (array $row): bool => ! isset($used[$this->recordKey($row['source'] ?? null, $row['source_id'] ?? null)]))
            ->map(fn (array $row): array => ['expected' => null, 'actual' => $row]);
        return $entries->merge($actualOnly)->values();
    }

    /** @return array<string,mixed> */
    private function summary(Collection $entries, Collection $actual): array
    {
        $expected = $entries->filter(fn (array $entry): bool => $entry['expected'] !== null);
        $submitted = $expected->filter(fn (array $entry): bool => $entry['actual'] !== null && $this->isSubmitted($entry['actual']))->count();
        $expectedCount = $expected->count();
        $overdue = $expected->filter(fn (array $entry): bool => $this->isOverdue($entry))->count();
        $pendingReceipt = $actual->filter(fn (array $row): bool => ($row['submission_status'] ?? null) === RoutingStatusPresenter::PENDING_PENRO)->count();
        return [
            'expected' => $expectedCount,
            'submitted' => $submitted,
            'compliance_rate' => $expectedCount > 0 ? round(($submitted / $expectedCount) * 100, 1) : null,
            'overdue' => $overdue,
            'pending_receipt' => $pendingReceipt,
            'coverage' => $expectedCount > 0 ? 'registry' : ($actual->isNotEmpty() ? 'actual_only' : 'no_data'),
        ];
    }

    /** @return array<string,mixed> */
    private function timeliness(Collection $actual): array
    {
        $onTimeLabels = ['Outstanding', 'Very Satisfactory', 'Satisfactory'];
        $values = $actual->pluck('timeliness')->filter()->values();
        return ['on_time' => $values->filter(fn ($value): bool => in_array($value, $onTimeLabels, true))->count(), 'late' => $values->reject(fn ($value): bool => in_array($value, $onTimeLabels, true) || in_array($value, ['No Data', 'Pending Submission by CENRO'], true))->count(), 'rated' => $values->count(), 'average_days_complied' => $actual->pluck('days_complied')->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): float => (float) $value)->avg()];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function groupPerformance(Collection $entries, string $group): Collection
    {
        return $entries->filter(fn (array $entry): bool => $entry['expected'] !== null || $entry['actual'] !== null)->groupBy(function (array $entry) use ($group): string {
            $row = $entry['expected'] ?? $entry['actual'];
            return match ($group) {
                'pa' => (string) (($row['target_label'] ?? null) ?: ($entry['actual']['protected_area'] ?? null) ?: 'Unassigned'),
                'office' => (string) (($row['office'] ?? null) ?: ($entry['actual']['target_office'] ?? null) ?: 'Unassigned'),
                default => (string) (($row['family'] ?? null) ?: ($entry['actual']['module'] ?? 'Report')),
            };
        })->map(function (Collection $items, string $label): array {
            $expected = $items->filter(fn (array $entry): bool => $entry['expected'] !== null);
            $submitted = $expected->filter(fn (array $entry): bool => $entry['actual'] !== null && $this->isSubmitted($entry['actual']))->count();
            return ['label' => $label, 'expected' => $expected->count(), 'submitted' => $submitted, 'compliance_rate' => $expected->count() ? round(($submitted / $expected->count()) * 100, 1) : null, 'overdue' => $items->filter(fn (array $entry): bool => $this->isOverdue($entry))->count()];
        })->sortByDesc(fn (array $row): array => [$row['overdue'], $row['compliance_rate'] ?? -1])->values();
    }

    /** @return list<array<string,mixed>> */
    private function periodTrend(Collection $entries): array
    {
        return $entries->groupBy(function (array $entry): string {
            $row = $entry['expected'] ?? $entry['actual'];
            $key = (string) ($row['period_key'] ?? '');
            if (preg_match('/^(\d{4})-(\d{2})$/', $key, $match)) return $match[1].'-'.$match[2];
            if (preg_match('/^(Q[1-4])$/i', $key, $match)) return strtoupper($match[1]);
            return (string) (($row['period_label'] ?? null) ?: ($entry['actual']['reporting_period'] ?? 'Unknown'));
        })->map(function (Collection $items, string $period): array {
            $expected = $items->filter(fn (array $entry): bool => $entry['expected'] !== null);
            $submitted = $expected->filter(fn (array $entry): bool => $entry['actual'] !== null && $this->isSubmitted($entry['actual']))->count();
            return ['period' => $period, 'expected' => $expected->count(), 'submitted' => $submitted, 'compliance_rate' => $expected->count() ? round(($submitted / $expected->count()) * 100, 1) : null, 'overdue' => $items->filter(fn (array $entry): bool => $this->isOverdue($entry))->count()];
        })->sortKeys()->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function attention(Collection $entries): array
    {
        return $entries->filter(fn (array $entry): bool => $this->isOverdue($entry) || ($entry['actual'] && in_array($entry['actual']['submission_status'] ?? null, [RoutingStatusPresenter::PENDING_PENRO, RoutingStatusPresenter::PENDING_CENRO], true)))
            ->sortByDesc(fn (array $entry): int => (int) ($this->isOverdue($entry)))
            ->take(20)->map(function (array $entry): array {
                $row = $entry['actual'] ?? $entry['expected'];
                $reason = $this->isOverdue($entry) ? 'Overdue / not submitted' : ($entry['actual']['submission_status'] ?? 'Requires attention');
                return ['tracking_number' => $entry['actual']['tracking_number'] ?? null, 'report' => $entry['actual']['module'] ?? $entry['expected']['family'] ?? 'Report', 'scope' => $entry['actual']['protected_area'] ?? $entry['expected']['target_label'] ?? $entry['actual']['target_office'] ?? $entry['expected']['office'] ?? 'Unassigned', 'period' => $entry['actual']['reporting_period'] ?? $entry['expected']['period_label'] ?? null, 'status' => $entry['actual']['submission_status'] ?? 'Not Submitted', 'deadline' => $entry['actual']['deadline_submission'] ?? $entry['expected']['deadline'], 'reason' => $reason, 'source_url' => $entry['actual']['source_url'] ?? null];
            })->values()->all();
    }

    private function isSubmitted(array $row): bool
    {
        // Submission compliance follows the existing monitoring convention:
        // the authoritative receipt milestone proves that a report reached
        // the tracked submission endpoint; the presence of a source row
        // remains a separate dimension.
        return filled($row['date_received_penro'] ?? null);
    }

    private function isOverdue(array $entry): bool
    {
        if ($entry['expected'] === null) return false;
        $deadline = $entry['actual']['deadline_submission'] ?? $entry['expected']['deadline'] ?? null;
        return ! $entry['actual'] || ! $this->isSubmitted($entry['actual']) ? filled($deadline) && CarbonImmutable::parse($deadline, self::TIMEZONE)->startOfDay()->isBefore(CarbonImmutable::now(self::TIMEZONE)->startOfDay()) : false;
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
        return ['years' => $years, 'offices' => $offices, 'protected_areas' => $targets->map(fn (ProtectedArea $area): array => ['id' => $area->id, 'name' => $area->name])->values()->all(), 'families' => $families->all(), 'periods' => $periods, 'statuses' => [RoutingStatusPresenter::COMPLETED, RoutingStatusPresenter::PENDING_CENRO, RoutingStatusPresenter::PENDING_PENRO, RoutingStatusPresenter::PENDING_REGIONAL, RoutingStatusPresenter::NO_ACTIVITY]];
    }

    private function scopeLabel(array $filters, Collection $targets): string
    {
        if ($filters['protected_area_id'] !== '') return (string) ($targets->firstWhere('id', (int) $filters['protected_area_id'])?->name ?? 'Protected Area');
        if ($filters['office'] !== '') return $filters['office'];
        return $filters['domain'] === 'pa' ? 'All authorized Protected Areas' : ($filters['domain'] === 'engp' ? 'All authorized ENGP offices' : 'All authorized PA and ENGP reports');
    }

    private function interpretation(array $summary, array $attention, array $timeliness): string
    {
        if (($summary['expected'] ?? 0) === 0 && ($summary['submitted'] ?? 0) === 0) return 'No applicable report requirements or actual submissions were found for the selected scope.';
        $text = $summary['compliance_rate'] === null ? 'Submission coverage is actual-only for the selected scope.' : 'Submission compliance is '.$summary['compliance_rate'].'% ('.$summary['submitted'].' of '.$summary['expected'].' expected).';
        if (($summary['overdue'] ?? 0) > 0) $text .= ' '.$summary['overdue'].' report'.($summary['overdue'] === 1 ? ' is' : 's are').' overdue.';
        if (count($attention) > 0) $text .= ' '.$attention[0]['reason'].' is the leading attention item.';
        if (($timeliness['rated'] ?? 0) === 0) $text .= ' Timeliness is not available for the selected records.';
        return $text;
    }

    private function recordKey(mixed $source, mixed $id): string { return (string) $source.':'.(string) $id; }

}
