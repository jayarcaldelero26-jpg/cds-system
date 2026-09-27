<?php

namespace App\Services\Storage;

final class StorageCapacityFormatter
{
    public static function bytes(?int $bytes): ?string
    {
        if ($bytes === null || $bytes < 0) return null;
        if ($bytes < 1024 ** 2) return max(1, (int) round($bytes / 1024)).' KB';
        $value = (float) $bytes / (1024 ** 2);
        foreach (['MB', 'GB', 'TB', 'PB'] as $unit) {
            if ($value < 1024 || $unit === 'PB') return number_format($value, $value >= 10 ? 0 : 1).' '.$unit;
            $value /= 1024;
        }
        return null;
    }
}
