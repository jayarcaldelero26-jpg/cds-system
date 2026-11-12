<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public User $user;

    private ?string $isolatedStorageRoot = null;

    /**
     * Prove the opened Laravel connection is isolated before a database trait
     * can migrate, transact, or otherwise touch its configured database.
     */
    protected function setUpTraits()
    {
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getConfig('database'));

        $pdo = $connection->getPdo();
        $this->assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $main = collect($pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC))
            ->first(fn (array $entry): bool => ($entry['name'] ?? null) === 'main');
        $this->assertIsArray($main);
        $this->assertSame('', (string) ($main['file'] ?? ''));

        $migrationPath = $this->verifiedIsolatedMigrationPath();
        if ($migrationPath !== null) {
            $this->app->useDatabasePath(dirname($migrationPath));
        }

        return parent::setUpTraits();
    }

    protected function verifiedIsolatedMigrationPath(): ?string
    {
        $migrationPath = getenv('CDS_TEST_MIGRATIONS_PATH');
        if (! is_string($migrationPath) || $migrationPath === '') return null;

        $resolvedMigrationPath = realpath($migrationPath);
        $temporaryRoot = realpath(sys_get_temp_dir());
        $expectedPrefix = strtolower(rtrim((string) $temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'cds-smart-migrations-without-sep9-');

        $this->assertNotFalse($resolvedMigrationPath, 'The optional isolated test migration path must exist.');
        $this->assertStringStartsWith($expectedPrefix, strtolower((string) $resolvedMigrationPath));
        $this->assertFileDoesNotExist($resolvedMigrationPath.DIRECTORY_SEPARATOR.'2026_09_09_010000_remove_report_reconciliation_and_completeness_metadata.php');

        return (string) $resolvedMigrationPath;
    }

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
