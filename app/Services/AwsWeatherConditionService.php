<?php

namespace App\Services;

use App\Models\Aws;

final class AwsWeatherConditionService
{
    /** @param Aws|array<string, mixed> $record */
    public function classifyDaily(Aws|array $record): string
    {
        $values = $record instanceof Aws ? $record->toArray() : $record;
        $config = config('aws.weather_conditions');
        $thresholds = $config['thresholds'];
        $labels = $config['labels'];
        $remarks = [];
        $hasUsableWeatherData = false;

        $precipitation = $this->number($values['precipitation'] ?? null, 'precipitation');
        if ($precipitation !== null) {
            $hasUsableWeatherData = true;
            if ($precipitation > $thresholds['heavy_rain_above_mm']) {
                $remarks[] = $labels['heavy_rain'].' (Total: '.$this->formatNumber($precipitation).'mm)';
            } elseif ($precipitation > $thresholds['moderate_rain_above_mm']) {
                $remarks[] = $labels['moderate_rain'];
            }
        }

        $windSpeed = $this->number($values['wind_speed'] ?? null, 'wind_speed');
        if ($windSpeed !== null) {
            $hasUsableWeatherData = true;
            if ($windSpeed > $thresholds['strong_wind_above_mps']) {
                $remarks[] = $labels['strong_wind'].' ('.$this->formatNumber($windSpeed).' m/s)';
            }
        }

        $temperature = $this->number($values['air_temperature'] ?? null, 'air_temperature');
        if ($temperature !== null) {
            $hasUsableWeatherData = true;
            if ($temperature > $thresholds['high_temperature_above_c']) {
                $remarks[] = $labels['high_temperature'].' ('.$this->formatNumber($temperature)."\u{00B0}C)";
            } elseif ($temperature < $thresholds['cool_temperature_below_c']) {
                $remarks[] = $labels['cool_temperature'].' ('.$this->formatNumber($temperature)."\u{00B0}C)";
            }
        }

        if ($remarks !== []) return implode(' | ', $remarks);

        return $hasUsableWeatherData ? $labels['normal'] : $labels['unavailable'];
    }

    /** @param iterable<string> $dailyRemarks */
    public function summarizeMonthly(iterable $dailyRemarks): string
    {
        $labels = config('aws.weather_conditions.labels');
        $present = [];

        foreach ($dailyRemarks as $remark) {
            $group = $this->group($remark, $labels);
            if ($group !== null) $present[$group] = true;
        }

        if ($present === []) return $labels['unavailable'];

        foreach ($this->priority($labels) as $label) {
            if (isset($present[$label])) return $label;
        }

        return $labels['unavailable'];
    }

    private function number(mixed $value, string $metric): ?float
    {
        if ($value === null || trim((string) $value) === '' || ! is_numeric($value)) return null;

        $number = (float) $value;
        if (! is_finite($number)) return null;

        $range = config('aws.monthly_summary.metric_ranges.'.$metric, []);
        if (($range[0] ?? null) !== null && $number < $range[0]) return null;
        if (($range[1] ?? null) !== null && $number > $range[1]) return null;

        return $number;
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** @param array<string, string> $labels */
    private function group(string $remark, array $labels): ?string
    {
        if (str_starts_with($remark, $labels['heavy_rain'])) return $labels['heavy_rain'];
        if (str_starts_with($remark, $labels['moderate_rain'])) return $labels['moderate_rain'];
        if (str_starts_with($remark, $labels['strong_wind'])) return $labels['strong_wind'];
        if (str_starts_with($remark, $labels['high_temperature'])) return $labels['high_temperature'];
        if (str_starts_with($remark, $labels['cool_temperature'])) return $labels['cool_temperature'];
        if ($remark === $labels['normal']) return $labels['normal'];
        if ($remark === $labels['unavailable']) return $labels['unavailable'];

        return null;
    }

    /** @param array<string, string> $labels @return list<string> */
    private function priority(array $labels): array
    {
        return [
            $labels['heavy_rain'],
            $labels['strong_wind'],
            $labels['high_temperature'],
            $labels['moderate_rain'],
            $labels['cool_temperature'],
            $labels['normal'],
            $labels['unavailable'],
        ];
    }
}
