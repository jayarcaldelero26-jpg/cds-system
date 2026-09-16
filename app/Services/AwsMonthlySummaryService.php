<?php

namespace App\Services;

use App\Models\Aws;
use App\Models\AwsObservation;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AwsMonthlySummaryService
{
    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly AwsProtectedAreaScope $awsScope,
        private readonly AwsWeatherConditionService $weather,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function summarize(User $user, int $year, int $month, ?int $protectedAreaId = null): Collection
    {
        return $this->summarizePeriod($user, 'month', ['year' => $year, 'from_month' => $month, 'to_month' => $month], $protectedAreaId);
    }

    /** @param array<string, mixed> $period @return Collection<int, array<string, mixed>> */
    public function summarizePeriod(User $user, string $mode, array $period, ?int $protectedAreaId = null): Collection
    {
        if ($protectedAreaId !== null) $this->awsScope->assertCanAccess($user, $protectedAreaId);

        $mode = strtolower(trim($mode));

        if ($mode === 'one_month') {
            $year = (int) ($period['year'] ?? 0);
            $month = (int) ($period['month'] ?? $period['from_month'] ?? 0);
            $periodStart = CarbonImmutable::createSafe($year, $month, 1);

            abort_unless($periodStart !== false && $year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12, 422, 'Select a valid reporting month.');
            return $this->summarizeDailyMonth($user, $periodStart->startOfMonth(), $protectedAreaId);
        }

        if ($mode === 'custom_range') {
            $dateFrom = (string) ($period['date_from'] ?? '');
            $dateTo = (string) ($period['date_to'] ?? '');
            $fromParts = array_map('intval', explode('-', $dateFrom));
            $toParts = array_map('intval', explode('-', $dateTo));
            $periodStart = CarbonImmutable::createSafe(...$fromParts);
            $periodEnd = CarbonImmutable::createSafe(...$toParts);
            abort_unless($periodStart !== false && $periodEnd !== false, 422, 'Select a valid AWS date range.');
            abort_unless($periodEnd->greaterThanOrEqualTo($periodStart), 422, 'The end date must be on or after the start date.');

            return $this->summarizeCustomRange($user, $periodStart->startOfDay(), $periodEnd->endOfDay(), $protectedAreaId);
        }
        if ($mode === 'month') {
            $year = (int) $period['year'];
            $fromMonth = (int) ($period['from_month'] ?? $period['month']);
            $toMonth = (int) ($period['to_month'] ?? $period['month']);
            $periodStart = CarbonImmutable::createSafe($year, $fromMonth, 1);
            $periodEnd = CarbonImmutable::createSafe($year, $toMonth, 1);

            abort_unless($periodStart !== false && $periodEnd !== false && $fromMonth >= 1 && $fromMonth <= 12 && $toMonth >= 1 && $toMonth <= 12, 422, 'Select a valid reporting month range.');
            abort_unless($periodEnd->greaterThanOrEqualTo($periodStart), 422, 'The ending month must be on or after the starting month.');

            $periodStart = $periodStart->startOfMonth();
            $periodEnd = $periodEnd->endOfMonth();
            $rows = $this->rowsBetween($user, $periodStart, $periodEnd, $protectedAreaId);

            if ($fromMonth === $toMonth) {
                return $this->summarizeAreas($rows, $periodStart, $periodEnd, $periodStart->format('F Y'));
            }

            return $this->summarizeMonthlyRange($user, $rows, $periodStart, $periodEnd, $protectedAreaId);
        }

        if ($mode === 'day') {
            $periodStart = CarbonImmutable::parse((string) $period['date'])->startOfDay();

            return $this->summarizeAreas($this->rowsBetween($user, $periodStart, $periodStart, $protectedAreaId), $periodStart, $periodStart, $periodStart->format('F j, Y'));
        }

        if ($mode === 'range') {
            $periodStart = CarbonImmutable::parse((string) $period['date_from'])->startOfDay();
            $periodEnd = CarbonImmutable::parse((string) $period['date_to'])->endOfDay();
            abort_unless($periodEnd->greaterThanOrEqualTo($periodStart), 422, 'The end date must be on or after the start date.');

            return $this->summarizeAreas($this->rowsBetween($user, $periodStart, $periodEnd, $protectedAreaId), $periodStart, $periodEnd, $this->rangeLabel($periodStart, $periodEnd));
        }

        abort(422, 'Select a valid AWS summary period.');
    }

    private function rowsBetween(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?int $protectedAreaId): Collection
    {
        $legacy = $this->awsScope->query(Aws::query(), $user)
            ->whereNotNull('protected_area_id')
            ->whereNotNull('timestamps')
            ->whereBetween('start_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->when($protectedAreaId !== null, fn ($query) => $query->where('protected_area_id', $protectedAreaId))
            ->with('protectedArea:id,name')
            ->orderBy('protected_area_id')->orderBy('start_date')
            ->get(['id', 'protected_area_id', 'start_date', 'precipitation', 'wind_direction', 'wind_speed', 'air_temperature', 'relative_humidity', 'atmospheric_pressure', 'observation_count', 'expected_observations', 'data_completeness']);
        $observations = $this->awsScope->query(AwsObservation::query(), $user)
            ->whereNotNull('protected_area_id')
            ->whereBetween('start_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->when($protectedAreaId !== null, fn ($query) => $query->where('protected_area_id', $protectedAreaId))
            ->with('protectedArea:id,name')
            ->orderBy('protected_area_id')->orderBy('start_date')
            ->get(['id', 'protected_area_id', 'start_date', 'precipitation', 'wind_direction', 'wind_speed', 'air_temperature', 'relative_humidity', 'atmospheric_pressure', 'observation_count', 'expected_observations', 'data_completeness']);

        return $legacy->concat($observations)->sortBy(fn ($row) => [$row->protected_area_id, $row->start_date?->toDateString() ?? ''])->values();
    }

    private function summarizeAreas(Collection $rows, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $periodLabel): Collection
    {
        return $rows->groupBy('protected_area_id')->map(fn (Collection $areaRows) => $this->aggregateArea($areaRows, $periodStart, $periodEnd, $periodLabel))->values();
    }

    private function summarizeDailyMonth(User $user, CarbonImmutable $periodStart, ?int $protectedAreaId): Collection
    {
        $periodEnd = $periodStart->endOfMonth();
        $rows = $this->rowsBetween($user, $periodStart, $periodEnd, $protectedAreaId);
        $areas = $this->areasFor($user, $protectedAreaId);
        $result = collect();

        foreach ($areas as $area) {
            $areaRows = $rows->where('protected_area_id', $area->id);
            $day = $periodStart->startOfDay();

            while ($day->lessThanOrEqualTo($periodEnd->startOfDay())) {
                $dayRows = $areaRows->filter(fn (Aws $row): bool => $row->start_date->toDateString() === $day->toDateString())->values();
                $result->push($dayRows->isEmpty()
                    ? $this->emptyAreaSummaryForArea((int) $area->id, $area->name, $day, $day, $day->format('F j, Y'))
                    : $this->aggregateArea($dayRows, $day, $day, $day->format('F j, Y')));

                $day = $day->addDay();
            }
        }

        return $result->values();
    }

    private function summarizeCustomRange(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?int $protectedAreaId): Collection
    {
        $rows = $this->rowsBetween($user, $periodStart, $periodEnd, $protectedAreaId);
        $areas = $this->areasFor($user, $protectedAreaId);
        $result = collect();
        $month = $periodStart->startOfMonth();

        foreach ($areas as $area) {
            $areaRows = $rows->where('protected_area_id', $area->id);

            while ($month->lessThanOrEqualTo($periodEnd->startOfMonth())) {
                $monthStart = $month->startOfMonth();
                $monthEnd = $month->endOfMonth();
                $bucketStart = $periodStart->greaterThan($monthStart) ? $periodStart->startOfDay() : $monthStart;
                $bucketEnd = $periodEnd->lessThan($monthEnd) ? $periodEnd->endOfDay() : $monthEnd;
                $periodLabel = $this->bucketLabel($bucketStart, $bucketEnd);
                $monthRows = $areaRows->filter(function (Aws $row) use ($bucketStart, $bucketEnd): bool {
                    $date = CarbonImmutable::parse($row->start_date->toDateString());
                    return $date->betweenIncluded($bucketStart->startOfDay(), $bucketEnd->startOfDay());
                })->values();

                $result->push($monthRows->isEmpty()
                    ? $this->emptyAreaSummaryForArea((int) $area->id, $area->name, $bucketStart, $bucketEnd, $periodLabel)
                    : $this->aggregateArea($monthRows, $bucketStart, $bucketEnd, $periodLabel));

                $month = $month->addMonth();
            }

            $month = $periodStart->startOfMonth();
        }

        return $result->values();
    }

    private function areasFor(User $user, ?int $protectedAreaId): Collection
    {
        return $this->awsScope->query(ProtectedArea::query(), $user, 'id')
            ->when($protectedAreaId !== null, fn ($query) => $query->whereKey($protectedAreaId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function bucketLabel(CarbonImmutable $bucketStart, CarbonImmutable $bucketEnd): string
    {
        $dash = "\u{2013}";
        $fullMonth = $bucketStart->isStartOfMonth() && $bucketEnd->isEndOfMonth();

        if ($fullMonth) return $bucketStart->format('F Y');
        if ($bucketStart->year === $bucketEnd->year && $bucketStart->month === $bucketEnd->month) {
            return $bucketStart->format('F j').$dash.$bucketEnd->format('j, Y');
        }

        return $bucketStart->format('F j, Y').$dash.$bucketEnd->format('F j, Y');
    }

    private function emptyAreaSummaryForArea(int $protectedAreaId, string $protectedAreaName, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $periodLabel): array
    {
        return ['protected_area_id' => $protectedAreaId, 'protected_area_name' => $protectedAreaName, 'period' => $periodLabel, 'average_atmospheric_pressure' => null, 'average_air_temperature' => null, 'average_vapor_pressure_deficit' => null, 'average_vapor_pressure' => null, 'average_relative_humidity' => null, 'mean_wind_direction' => null, 'total_precipitation' => null, 'average_wind_speed' => null, 'observation_count' => 0, 'expected_observations' => $this->expectedObservations($periodStart, $periodEnd), 'data_completeness' => 0.0, 'remarks' => 'No Data'];
    }
    private function summarizeMonthlyRange(User $user, Collection $rows, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?int $protectedAreaId): Collection
    {
        $result = collect();

        foreach ($this->areasFor($user, $protectedAreaId) as $area) {
            $areaRows = $rows->where('protected_area_id', $area->id);
            $month = $periodStart->startOfMonth();

            while ($month->lessThanOrEqualTo($periodEnd->startOfMonth())) {
                $monthStart = $month->startOfMonth();
                $monthEnd = $month->endOfMonth();
                $periodLabel = $month->format('F Y');
                $monthRows = $areaRows->filter(function (Aws $row) use ($monthStart, $monthEnd): bool {
                    $date = CarbonImmutable::parse($row->start_date->toDateString());
                    return $date->betweenIncluded($monthStart, $monthEnd);
                })->values();

                $result->push($monthRows->isEmpty()
                    ? $this->emptyAreaSummaryForArea((int) $area->id, $area->name, $monthStart, $monthEnd, $periodLabel)
                    : $this->aggregateArea($monthRows, $monthStart, $monthEnd, $periodLabel));

                $month = $month->addMonth();
            }
        }

        return $result->values();
    }

    private function rangeLabel(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): string
    {
        $dash = "\u{2013}";
        return $periodStart->year === $periodEnd->year && $periodStart->month === $periodEnd->month
            ? $periodStart->format('F j').$dash.$periodEnd->format('j, Y')
            : $periodStart->format('F j, Y').$dash.$periodEnd->format('F j, Y');
    }

    private function aggregateArea(Collection $rows, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $periodLabel): array
    {
        $summaryConfig = config('aws.monthly_summary');
        $ranges = $summaryConfig['metric_ranges'];
        $decimalPlaces = (int) $summaryConfig['number_decimals'];
        $weighted = [
            'atmospheric_pressure' => ['sum' => 0.0, 'weight' => 0.0], 'air_temperature' => ['sum' => 0.0, 'weight' => 0.0],
            'vapor_pressure_deficit' => ['sum' => 0.0, 'weight' => 0.0], 'vapor_pressure' => ['sum' => 0.0, 'weight' => 0.0],
            'relative_humidity' => ['sum' => 0.0, 'weight' => 0.0],
            'wind_speed' => ['sum' => 0.0, 'weight' => 0.0],
        ];
        $directions = ['sin' => 0.0, 'cos' => 0.0, 'weight' => 0.0];
        $precipitation = 0.0;
        $hasPrecipitation = false;
        $actualObservations = 0;
        $expectedObservations = $this->expectedObservations($periodStart, $periodEnd);
        $integrityIssue = false;
        $dailyRemarks = [];

        foreach ($rows as $row) {
            $rowObservationCount = $this->observationWeight($row, $integrityIssue);
            $actualObservations += $rowObservationCount;
            if ($rowObservationCount > 0) {
                $dailyRemarks[] = $this->weather->classifyDaily($row);
            }

            foreach (['atmospheric_pressure', 'air_temperature', 'relative_humidity', 'wind_speed'] as $metric) {
                $value = $this->metricValue($row->{$metric}, $ranges[$metric], $integrityIssue);
                if ($value !== null) {
                    $weighted[$metric]['sum'] += $value * $rowObservationCount;
                    $weighted[$metric]['weight'] += $rowObservationCount;
                }
            }

            $temperature = $this->metricValue($row->air_temperature, $ranges['air_temperature'], $integrityIssue);
            $humidity = $this->metricValue($row->relative_humidity, $ranges['relative_humidity'], $integrityIssue);
            if ($temperature !== null && $humidity !== null) {
                $vaporPressure = $this->derivedVaporPressure($temperature, $humidity);
                $vpd = $this->derivedVaporPressureDeficit($temperature, $humidity);
                $weighted['vapor_pressure']['sum'] += $vaporPressure * $rowObservationCount;
                $weighted['vapor_pressure']['weight'] += $rowObservationCount;
                $weighted['vapor_pressure_deficit']['sum'] += $vpd * $rowObservationCount;
                $weighted['vapor_pressure_deficit']['weight'] += $rowObservationCount;
            }

            $direction = $this->windDirection($row->wind_direction, $integrityIssue);
            if ($direction !== null) {
                $radians = deg2rad($direction);
                $directions['sin'] += sin($radians) * $rowObservationCount;
                $directions['cos'] += cos($radians) * $rowObservationCount;
                $directions['weight'] += $rowObservationCount;
            }

            $rain = $this->metricValue($row->precipitation, $ranges['precipitation'], $integrityIssue);
            if ($rain !== null) {
                $precipitation += $rain;
                $hasPrecipitation = true;
            }
        }

        $completeness = $expectedObservations > 0 ? min(100.0, round(($actualObservations / $expectedObservations) * 100, 1)) : 0.0;

        $remarks = $actualObservations === 0 ? 'No Data' : $this->weather->summarizeMonthly($dailyRemarks);

        return $this->summaryValues($rows->first(), $periodLabel, $weighted, $directions, $hasPrecipitation ? round($precipitation, $decimalPlaces) : null, $actualObservations, $expectedObservations, $completeness, $integrityIssue, $summaryConfig, $remarks);
    }

    private function emptyAreaSummary(Aws $row, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $periodLabel): array
    {
        return ['protected_area_id' => (int) $row->protected_area_id, 'protected_area_name' => $row->protectedArea?->name, 'period' => $periodLabel, 'average_atmospheric_pressure' => null, 'average_air_temperature' => null, 'average_vapor_pressure_deficit' => null, 'average_vapor_pressure' => null, 'average_relative_humidity' => null, 'mean_wind_direction' => null, 'total_precipitation' => null, 'average_wind_speed' => null, 'observation_count' => 0, 'expected_observations' => $this->expectedObservations($periodStart, $periodEnd), 'data_completeness' => 0.0, 'remarks' => 'No Data'];
    }

    private function expectedObservations(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): int
    {
        $samplingInterval = max(1, min(1440, (int) config('aws.sampling_interval_minutes', 15)));
        return (int) (($periodStart->startOfDay()->diffInDays($periodEnd->startOfDay()) + 1) * intdiv(1440, $samplingInterval));
    }

    private function summaryValues(Aws $first, string $periodLabel, array $weighted, array $directions, ?float $precipitation, int $actualObservations, int $expectedObservations, float $completeness, bool $integrityIssue, array $summaryConfig, string $remarks): array
    {
        $decimalPlaces = (int) $summaryConfig['number_decimals'];
        $vpd = $this->weightedAverage($weighted['vapor_pressure_deficit'], (int) $summaryConfig['vapor_pressure_decimals']);
        $vaporPressure = $this->weightedAverage($weighted['vapor_pressure'], (int) $summaryConfig['vapor_pressure_decimals']);

        return ['protected_area_id' => (int) $first->protected_area_id, 'protected_area_name' => $first->protectedArea?->name, 'period' => $periodLabel, 'average_atmospheric_pressure' => $this->weightedAverage($weighted['atmospheric_pressure'], $decimalPlaces), 'average_air_temperature' => $this->weightedAverage($weighted['air_temperature'], $decimalPlaces), 'average_vapor_pressure_deficit' => $vpd, 'average_vapor_pressure' => $vaporPressure, 'average_relative_humidity' => $this->weightedAverage($weighted['relative_humidity'], $decimalPlaces), 'mean_wind_direction' => $this->circularMean($directions, $decimalPlaces), 'total_precipitation' => $precipitation, 'average_wind_speed' => $this->weightedAverage($weighted['wind_speed'], $decimalPlaces), 'observation_count' => $actualObservations, 'expected_observations' => $expectedObservations, 'data_completeness' => $completeness, 'remarks' => $remarks];
    }

    private function observationWeight(Aws $row, bool &$integrityIssue): int
    {
        $value = $row->observation_count;
        if ($value === null || $value === '') { $integrityIssue = true; return 1; }
        if (! is_numeric($value) || (int) $value < 0) { $integrityIssue = true; return 0; }
        return (int) $value;
    }

    private function metricValue(mixed $raw, array $range, bool &$integrityIssue): ?float
    {
        if ($raw === null || trim((string) $raw) === '') return null;
        if (! is_numeric($raw)) { $integrityIssue = true; return null; }
        $value = (float) $raw;
        if (($range[0] ?? null) !== null && $value < $range[0] || ($range[1] ?? null) !== null && $value > $range[1]) { $integrityIssue = true; return null; }
        return $value;
    }

    private function windDirection(mixed $raw, bool &$integrityIssue): ?float
    {
        if ($raw === null || trim((string) $raw) === '') return null;
        if (is_numeric($raw)) { $value = (float) $raw; if ($value < 0 || $value > 360) { $integrityIssue = true; return null; } return fmod($value, 360.0); }
        $labels = ['N' => 0.0, 'NNE' => 22.5, 'NE' => 45.0, 'ENE' => 67.5, 'E' => 90.0, 'ESE' => 112.5, 'SE' => 135.0, 'SSE' => 157.5, 'S' => 180.0, 'SSW' => 202.5, 'SW' => 225.0, 'WSW' => 247.5, 'W' => 270.0, 'WNW' => 292.5, 'NW' => 315.0, 'NNW' => 337.5];
        $label = strtoupper(trim((string) $raw));
        if (array_key_exists($label, $labels)) return $labels[$label];
        $integrityIssue = true;
        return null;
    }

    private function derivedVaporPressure(float $temperature, float $humidity): float
    {
        $saturation = 0.6108 * exp((17.27 * $temperature) / ($temperature + 237.3));
        return $saturation * ($humidity / 100);
    }

    private function derivedVaporPressureDeficit(float $temperature, float $humidity): float
    {
        $saturation = 0.6108 * exp((17.27 * $temperature) / ($temperature + 237.3));
        return $saturation - $this->derivedVaporPressure($temperature, $humidity);
    }

    private function weightedAverage(array $metric, int $decimals): ?float { return $metric['weight'] > 0 ? round($metric['sum'] / $metric['weight'], $decimals) : null; }

    private function circularMean(array $directions, int $decimals): ?float
    {
        if ($directions['weight'] <= 0) return null;
        $mean = rad2deg(atan2($directions['sin'], $directions['cos']));
        if ($mean < 0) $mean += 360;
        if (abs($mean - 360) < 10 ** -$decimals) $mean = 0.0;
        return round($mean, $decimals);
    }

}
