<?php

namespace App\Support;

final class OfficeProtectedAreaPresenter
{
    private const SEPARATOR = "\u{2014}";

    public static function combine(?string $office, ?string $protectedArea): string
    {
        $office = trim((string) $office);
        $protectedArea = trim((string) $protectedArea);

        if ($office === '') return $protectedArea !== '' ? $protectedArea : self::SEPARATOR;
        if ($protectedArea === '') return $office;
        if (self::isAliasOfProtectedArea($office, $protectedArea)) return $protectedArea;

        return $office.' '.self::SEPARATOR.' '.$protectedArea;
    }

    private static function isAliasOfProtectedArea(string $office, string $protectedArea): bool
    {
        if (preg_match('/^(?:cenro|penro|pamo|pasu)\b/i', $office) === 1) return false;

        $officeTokens = self::tokens($office);
        $protectedAreaTokens = self::tokens($protectedArea);

        if ($officeTokens === [] || $protectedAreaTokens === [] || count($officeTokens) > 4) return false;

        return count(array_diff($officeTokens, $protectedAreaTokens)) === 0;
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower($value));

        return array_values(array_filter(explode(' ', trim((string) $normalized))));
    }
}
