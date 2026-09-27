<?php

namespace App\Support;

final class CsvCellSanitizer
{
    public static function text(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') return $value;

        // Spreadsheet applications may ignore leading whitespace/control/format
        // characters before formula markers. Prefix only user-controlled text.
        $dangerous = preg_match('/\A[\p{Z}\p{Cc}\p{Cf}]*[=+\-@]/u', $value);
        if ($dangerous === false) $dangerous = preg_match('/\A[\x00-\x20]*[=+\-@]/', $value);

        return $dangerous === 1
            ? "'".$value
            : $value;
    }
}
