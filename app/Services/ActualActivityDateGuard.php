<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Prevent newly supplied actual activity dates from being set in the future. */
final class ActualActivityDateGuard
{
    private const BUSINESS_TIMEZONE = 'Asia/Manila';

    public function assertNotFuture(mixed $value, string $attribute, string $label, mixed $existingValue = null): void
    {
        $date = $this->dateKey($value);
        if ($date === null || $date <= CarbonImmutable::now(self::BUSINESS_TIMEZONE)->toDateString()) return;

        // Preserve legacy future values when an edit leaves that field unchanged.
        if ($this->dateKey($existingValue) === $date) return;

        throw ValidationException::withMessages([
            $attribute => $label.' cannot be later than today (Philippines time).',
        ]);
    }

    /**
     * Validate only submitted range endpoints. Existing future endpoints remain
     * valid when they are unchanged at the same position in the range list.
     *
     * @param mixed $ranges Submitted date_conducted_ranges
     * @param mixed $existingRanges Existing date_conducted_ranges cast
     */
    public function assertDateConductedRanges(mixed $ranges, mixed $existingRanges = null, mixed $legacyDate = null): void
    {
        if (! is_array($ranges)) return;

        foreach ($ranges as $index => $range) {
            if (! is_array($range)) continue;
            foreach (['from', 'to'] as $endpoint) {
                $value = $range[$endpoint] ?? null;
                if (! is_string($value) || trim($value) === '') continue;

                $old = is_array($existingRanges) ? ($existingRanges[$index][$endpoint] ?? null) : null;
                if ($old === null && $index === 0) $old = $legacyDate;

                $label = $endpoint === 'from' ? 'Date Conducted start' : 'Date Conducted end';
                $this->assertNotFuture($value, "date_conducted_ranges.{$index}.{$endpoint}", $label, $old);
            }
        }
    }

    /**
     * Convert a date-only value to its entered calendar date. Date-cast model
     * values are kept as dates rather than shifted across timezones.
     */
    private function dateKey(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) return $value->format('Y-m-d');
        if (! is_string($value) && ! is_numeric($value)) return null;

        $value = trim((string) $value);
        if ($value === '') return null;

        try {
            return CarbonImmutable::parse($value, self::BUSINESS_TIMEZONE)->setTimezone(self::BUSINESS_TIMEZONE)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
