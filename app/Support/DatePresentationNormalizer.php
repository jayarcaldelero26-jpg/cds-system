<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeImmutable;

/**
 * Converts only storage-safe date values to their transport representation.
 *
 * This intentionally does not use a natural-language parser: descriptive
 * coverage values such as "August 1, 2, 3, 2026" are not single dates.
 */
final class DatePresentationNormalizer
{
    /** @var list<string> */
    private const DATABASE_FORMATS = [
        '!Y-m-d',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i:s.u',
        '!Y-m-d\TH:i:s',
        '!Y-m-d\TH:i:s.u',
        '!Y-m-d\TH:i:sP',
        '!Y-m-d\TH:i:s.uP',
    ];

    public static function toDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (self::DATABASE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
