<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Filesystem\Filesystem;

abstract class TestCase extends BaseTestCase
{
    public User $user;

    private ?string $isolatedStorageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedStorageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cds-smart-test-storage-'.bin2hex(random_bytes(8));
        (new Filesystem())->ensureDirectoryExists($this->isolatedStorageRoot);
        $this->app->useStoragePath($this->isolatedStorageRoot);
    }

    protected function tearDown(): void
    {
        try {
            if (is_string($this->isolatedStorageRoot)
                && str_starts_with($this->isolatedStorageRoot, sys_get_temp_dir().DIRECTORY_SEPARATOR.'cds-smart-test-storage-')) {
                (new Filesystem())->deleteDirectory($this->isolatedStorageRoot);
            }
        } finally {
            parent::tearDown();
        }
    }
}
