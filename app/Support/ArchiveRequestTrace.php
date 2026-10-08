<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Opaque request correlation for one synchronous archive checkpoint. */
final class ArchiveRequestTrace
{
    public const ATTRIBUTE = '_cds_archive_request_id';

    public static function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{32}\z/i', $value) === 1;
    }

    public static function attach(Request $request, string $id): void
    {
        if (self::validId($id)) $request->attributes->set(self::ATTRIBUTE, strtolower($id));
    }

    public static function currentId(): ?string
    {
        $request = app()->resolved('request') ? app('request') : null;
        if (! $request instanceof Request) return null;

        $id = $request->attributes->get(self::ATTRIBUTE);

        return self::validId($id) ? strtolower($id) : null;
    }
}
