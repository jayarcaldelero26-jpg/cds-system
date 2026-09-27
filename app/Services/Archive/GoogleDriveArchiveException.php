<?php

namespace App\Services\Archive;

use RuntimeException;

/** Contains only a safe message and an HTTP status; never retains a Google response body. */
final class GoogleDriveArchiveException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
