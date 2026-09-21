<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class DateConductedRangeService
{
    /** @var list<string> */
    private const SUPPORTED_WORKFLOWS = ['additional_bms_site', 'bdfe_terrestrial', 'maintenance_pamo_ecotourism'];

    public function supportsWorkflow(?string $workflow): bool
    {
        return in_array($workflow, self::SUPPORTED_WORKFLOWS, true);
    }

    /** @return list<array{from:string,to:string}>|null */
    public function normalize(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) return null;
        if (! is_array($value)) throw ValidationException::withMessages(['date_conducted_ranges' => 'Date Conducted ranges must be a list.']);
        $ranges = [];
        foreach ($value as $index => $range) {
            if (! is_array($range)) throw ValidationException::withMessages(["date_conducted_ranges.{$index}" => 'Each Date Conducted entry must include From and To dates.']);
            $from = trim((string) ($range['from'] ?? ''));
            $to = trim((string) ($range['to'] ?? ''));
            if ($from === '' && $to === '') continue;
            if ($from === '') throw ValidationException::withMessages(["date_conducted_ranges.{$index}.from" => 'From date is required.']);
            if ($to === '') $to = $from;
            $start = $this->parse($from, "date_conducted_ranges.{$index}.from");
            $end = $this->parse($to, "date_conducted_ranges.{$index}.to");
            if ($end->lessThan($start)) throw ValidationException::withMessages(["date_conducted_ranges.{$index}.to" => 'To date must be on or after From date.']);
            $ranges[] = ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
        }
        return $ranges ?: null;
    }

    /**
     * The legacy date_conducted field remains the first range's From date for backward compatibility;
     * date_conducted_ranges is the structured source for multi-range records.
     *
     * @param array<string, mixed> $validated
     */
    public function applyToPayload(array $validated, mixed $rawRanges): array
    {
        $ranges = $this->normalize($rawRanges);
        $validated['date_conducted_ranges'] = $ranges;
        if ($ranges !== null) $validated['date_conducted'] = $ranges[0]['from'];
        return $validated;
    }

    public function display(mixed $ranges, ?string $legacy = null): ?string
    {
        $normalized = $this->normalizeForDisplay($ranges);
        if ($normalized === []) return $legacy !== null && trim($legacy) !== '' ? $legacy : null;
        $dates = array_map(fn (array $range): array => ['from' => CarbonImmutable::createFromFormat('!Y-m-d', $range['from']), 'to' => CarbonImmutable::createFromFormat('!Y-m-d', $range['to'])], $normalized);
        $sameYear = count(array_unique(array_map(fn (array $range): int => $range['from']->year, $dates))) === 1
            && count(array_unique(array_map(fn (array $range): int => $range['to']->year, $dates))) === 1
            && $dates[0]['from']->year === $dates[0]['to']->year;
        $sameMonth = $sameYear && count(array_unique(array_map(fn (array $range): string => $range['from']->format('Y-m'), $dates))) === 1
            && count(array_unique(array_map(fn (array $range): string => $range['to']->format('Y-m'), $dates))) === 1
            && $dates[0]['from']->format('Y-m') === $dates[0]['to']->format('Y-m');
        if ($sameMonth) return $this->month($dates[0]['from']).' '.$this->joinParts(array_map(fn (array $range): string => $this->dayPart($range['from'], $range['to']), $dates)).', '.$dates[0]['from']->year;
        if ($sameYear) return $this->joinParts(array_map(fn (array $range): string => $this->rangePart($range['from'], $range['to'], false), $dates)).', '.$dates[0]['from']->year;
        return $this->joinParts(array_map(fn (array $range): string => $this->rangePart($range['from'], $range['to'], true), $dates));
    }

    private function parse(string $value, string $key): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) throw ValidationException::withMessages([$key => 'Enter a valid date.']);
        return $date;
    }

    /** @return list<array{from:string,to:string}> */
    private function normalizeForDisplay(mixed $ranges): array
    {
        if (! is_array($ranges)) return [];
        return array_values(array_filter(array_map(function (mixed $range): ?array {
            if (! is_array($range) || empty($range['from'])) return null;
            $from = trim((string) $range['from']);
            $to = trim((string) ($range['to'] ?? $from)) ?: $from;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return null;
            return ['from' => $from, 'to' => $to];
        }, $ranges)));
    }

    private function month(CarbonImmutable $date): string
    {
        return match ($date->month) { 1 => 'Jan.', 2 => 'Feb.', 3 => 'Mar.', 4 => 'Apr.', 5 => 'May', 6 => 'Jun.', 7 => 'Jul.', 8 => 'Aug.', 9 => 'Sep.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Dec.' };
    }

    private function dayPart(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to) ? (string) $from->day : $from->day.'-'.$to->day;
    }

    private function rangePart(CarbonImmutable $from, CarbonImmutable $to, bool $includeYears): string
    {
        $start = $this->month($from).' '.$from->day;
        $end = $this->month($to).' '.$to->day;
        if ($includeYears) { $start .= ', '.$from->year; $end .= ', '.$to->year; }
        if ($from->isSameDay($to)) return $start;
        if ($from->isSameMonth($to)) return $this->month($from).' '.$from->day.'-'.$to->day.($includeYears ? ', '.$from->year : '');
        return $start.'-'.$end;
    }

    /** @param list<string> $parts */
    private function joinParts(array $parts): string
    {
        $count = count($parts);
        if ($count < 2) return $parts[0] ?? '';
        if ($count === 2) return $parts[0].' and '.$parts[1];
        return implode(', ', array_slice($parts, 0, -1)).' and '.$parts[$count - 1];
    }
}
