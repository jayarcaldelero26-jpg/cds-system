<?php

use Illuminate\Support\Facades\File;

function isolateGeneratedExportStorage(): void
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cds-export-tests-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'app');
    app()->instance('test.export_storage_root', $root);
    app()->useStoragePath($root);
}

function removeIsolatedGeneratedExportStorage(): void
{
    $root = app()->bound('test.export_storage_root') ? app('test.export_storage_root') : null;
    if (is_string($root) && str_starts_with($root, sys_get_temp_dir().DIRECTORY_SEPARATOR.'cds-export-tests-')) {
        File::deleteDirectory($root);
    }
}
