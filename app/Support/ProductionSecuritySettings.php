<?php

namespace App\Support;

final class ProductionSecuritySettings
{
    public static function debugEnabled(string $environment, mixed $configured): bool
    {
        return $environment === 'production' ? false : (bool) $configured;
    }

    public static function debugbarEnabled(string $environment, mixed $configured): mixed
    {
        return $environment === 'production' ? false : $configured;
    }

    public static function secureCookie(string $environment, mixed $configured): bool
    {
        return $environment === 'production' ? true : (bool) $configured;
    }

    public static function httpOnlyCookie(string $environment, mixed $configured): bool
    {
        return $environment === 'production' ? true : (bool) $configured;
    }

    public static function sameSite(string $environment, mixed $configured): mixed
    {
        if ($environment !== 'production') {
            return $configured;
        }

        $value = strtolower((string) ($configured ?? 'lax'));

        return in_array($value, ['lax', 'strict'], true) ? $value : 'lax';
    }
}
