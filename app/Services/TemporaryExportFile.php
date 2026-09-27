<?php

namespace App\Services;

use Closure;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

final class TemporaryExportFile
{
    public static function zipDownload(
        string $prefix,
        string $filename,
        string $contentType,
        string $createError,
        string $openError,
        Closure $populate,
    ): BinaryFileResponse {
        $path = tempnam(storage_path('app'), $prefix);
        abort_unless(is_string($path), 500, $createError);

        $zip = new ZipArchive();
        $opened = false;

        try {
            $opened = $zip->open($path, ZipArchive::OVERWRITE) === true;
            abort_unless($opened, 500, $openError);

            $populate($zip);

            if (! $zip->close()) {
                $opened = false;
                throw new RuntimeException($openError);
            }
            $opened = false;

            return response()->download($path, $filename, ['Content-Type' => $contentType])
                ->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if ($opened) {
                $zip->close();
            }

            if (is_file($path)) {
                @unlink($path);
            }

            throw $exception;
        }
    }
}
