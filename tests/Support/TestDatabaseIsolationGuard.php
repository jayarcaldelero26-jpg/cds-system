<?php

declare(strict_types=1);

namespace Tests\Support;

final class TestDatabaseIsolationGuard
{
    /**
     * Return safe, non-secret reasons why a test process must not continue.
     *
     * The environment can be supplied explicitly so this check can be verified
     * without booting Laravel or opening a database connection.
     *
     * @param  array<string, mixed>|null  $environment
     * @return list<string>
     */
    public static function violations(string $projectRoot, ?array $environment = null): array
    {
        $environment ??= self::currentEnvironment();
        $violations = [];

        if (strtolower(trim((string) ($environment['APP_ENV'] ?? ''))) !== 'testing') {
            $violations[] = 'app_environment_not_testing';
        }

        if (strtolower(trim((string) ($environment['DB_CONNECTION'] ?? ''))) !== 'sqlite') {
            $violations[] = 'database_connection_not_sqlite';
        }

        if (trim((string) ($environment['DB_DATABASE'] ?? '')) !== ':memory:') {
            $violations[] = 'database_name_not_in_memory';
        }

        foreach (['DB_URL', 'DATABASE_URL'] as $urlKey) {
            if (trim((string) ($environment[$urlKey] ?? '')) !== '') {
                $violations[] = strtolower($urlKey).'_must_be_empty';
            }
        }

        foreach ($environment as $key => $value) {
            $key = strtoupper((string) $key);

            if ((str_starts_with($key, 'DB_READ_') || str_starts_with($key, 'DB_WRITE_'))
                && trim((string) $value) !== '') {
                $violations[] = 'database_read_write_override_present';
                break;
            }
        }

        if (trim((string) ($environment['APP_CONFIG_CACHE'] ?? '')) !== '') {
            $violations[] = 'custom_config_cache_path_present';
        }

        if (is_file($projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php')) {
            $violations[] = 'configuration_cache_file_present';
        }

        return array_values(array_unique($violations));
    }

    /** @return array<string, string> */
    private static function currentEnvironment(): array
    {
        $environment = array_merge($_SERVER, $_ENV);
        $processEnvironment = getenv();

        if (is_array($processEnvironment)) {
            $environment = array_merge($environment, $processEnvironment);
        }

        $scalarEnvironment = [];

        foreach ($environment as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $scalarEnvironment[(string) $key] = (string) $value;
            }
        }

        return $scalarEnvironment;
    }
}
