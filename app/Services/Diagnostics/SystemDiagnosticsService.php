<?php

namespace App\Services\Diagnostics;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class SystemDiagnosticsService
{
    public function run(): array
    {
        $started = microtime(true);
        $checks = [];
        foreach ($this->checks() as $check => $callback) {
            $tick = microtime(true);
            try {
                $result = $callback();
                $checks[] = [...$result->toArray(), 'duration_ms' => round((microtime(true) - $tick) * 1000, 2)];
            } catch (Throwable) {
                $checks[] = (new DiagnosticResult('fail', $check, 'The diagnostic check could not be completed safely.', [], 'cheap'))->toArray() + ['duration_ms' => round((microtime(true) - $tick) * 1000, 2)];
            }
        }
        $summary = collect($checks)->countBy('status')->all();
        $overall = ($summary['fail'] ?? 0) > 0 ? 'critical' : ((($summary['warning'] ?? 0) + ($summary['not_verified'] ?? 0)) > 0 ? 'warning' : 'healthy');
        return ['schema_version' => '1.0', 'generated_at' => now()->toIso8601String(), 'overall' => $overall, 'duration_ms' => round((microtime(true) - $started) * 1000, 2), 'summary' => ['pass' => $summary['pass'] ?? 0, 'warning' => $summary['warning'] ?? 0, 'fail' => $summary['fail'] ?? 0, 'not_verified' => $summary['not_verified'] ?? 0], 'groups' => $this->groups($checks), 'checks' => $checks, 'snapshot' => $this->snapshot($checks)];
    }

    private function checks(): array
    {
        return [
            'application.environment' => fn () => new DiagnosticResult('pass', 'application.environment', 'Application environment is configured.', ['environment' => app()->environment()]),
            'application.runtime' => fn () => new DiagnosticResult('pass', 'application.runtime', 'Runtime versions and timezone are available.', ['laravel_version' => app()->version(), 'php_version' => PHP_VERSION, 'timezone' => config('app.timezone')]),
            'application.debug' => fn () => new DiagnosticResult($this->productionLike() && config('app.debug') ? 'fail' : (config('app.debug') ? 'warning' : 'pass'), 'application.debug', config('app.debug') ? ($this->productionLike() ? 'Debug mode is enabled in a production-like environment.' : 'Debug mode is enabled for local development.') : 'Debug mode is disabled.', ['enabled' => (bool) config('app.debug')]),
            'frontend.manifest' => fn () => $this->manifestCheck(),
            'database.connection' => fn () => $this->databaseCheck(),
            'database.migrations' => fn () => $this->migrationCheck(),
            'cache.configuration' => fn () => new DiagnosticResult('not_verified', 'cache.configuration', 'Cache configuration is readable; Phase 1 does not mutate cache storage.', ['driver' => (string) config('cache.default')]),
            'session.configuration' => fn () => $this->sessionCheck(),
            'storage.private' => fn () => $this->storageCheck(),
            'mail.readiness' => fn () => $this->mailCheck(),
            'queue.configuration' => fn () => $this->queueCheck(),
            'scheduler.configuration' => fn () => $this->schedulerCheck(),
            'logging.configuration' => fn () => $this->loggingCheck(),
            'security.https' => fn () => $this->httpsCheck(),
            'security.session_cookie' => fn () => $this->cookieCheck('secure', (bool) config('session.secure'), 'Secure session cookies are enabled.', 'Secure session cookies are disabled.'),
            'security.http_only_cookie' => fn () => $this->cookieCheck('http_only', (bool) config('session.http_only'), 'HTTP-only session cookies are enabled.', 'HTTP-only session cookies are disabled.'),
            'security.same_site_cookie' => fn () => new DiagnosticResult(in_array(config('session.same_site'), ['lax', 'strict'], true) ? 'pass' : 'warning', 'security.same_site_cookie', 'SameSite session cookie policy is configured.', ['same_site' => config('session.same_site')]),
        ];
    }

    private function productionLike(): bool { return app()->environment('production', 'staging') || (bool) config('app.url') && ! app()->environment('local', 'testing'); }
    private function manifestCheck(): DiagnosticResult { $file = public_path('build/manifest.json'); return is_readable($file) ? new DiagnosticResult('pass', 'frontend.manifest', 'Frontend build manifest is available.') : new DiagnosticResult('fail', 'frontend.manifest', 'Frontend build manifest is missing or unreadable.'); }
    private function databaseCheck(): DiagnosticResult { $start = microtime(true); DB::select('SELECT 1'); return new DiagnosticResult('pass', 'database.connection', 'Database connectivity check succeeded.', ['driver' => DB::connection()->getDriverName(), 'latency_ms' => round((microtime(true) - $start) * 1000, 2)]); }
    private function migrationCheck(): DiagnosticResult { $files = app('migrator')->getMigrationFiles(database_path('migrations')); $ran = app('migrator')->getRepository()->getRan(); $pending = array_values(array_diff(array_keys($files), $ran)); return new DiagnosticResult($pending ? 'warning' : 'pass', 'database.migrations', $pending ? count($pending).' migration(s) are pending.' : 'No pending migrations.', ['pending_count' => count($pending), 'pending' => array_slice($pending, 0, 20)]); }
    private function sessionCheck(): DiagnosticResult { $driver = (string) config('session.driver'); $path = config('session.files'); $usable = $driver !== 'file' || (is_string($path) && is_dir($path) && is_readable($path)); return new DiagnosticResult($usable ? 'pass' : 'warning', 'session.configuration', $usable ? 'Session configuration appears available.' : 'Session storage configuration could not be verified.', ['driver' => $driver, 'lifetime_minutes' => (int) config('session.lifetime'), 'expire_on_close' => (bool) config('session.expire_on_close')]); }
    private function storageCheck(): DiagnosticResult { $disk = (string) config('filesystems.default'); $config = config('filesystems.disks.'.$disk, []); $root = data_get($config, 'root'); $ready = ! is_string($root) || (is_dir($root) && is_readable($root)); return new DiagnosticResult($ready ? 'pass' : 'warning', 'storage.private', $ready ? 'Private storage configuration appears readable.' : 'Private storage root is unavailable or unreadable.', ['disk' => $disk, 'root_present' => filled($root)]); }
    private function mailCheck(): DiagnosticResult { $mailer = (string) config('mail.default'); $smtp = config('mail.mailers.smtp', []); $details = ['mailer' => $mailer, 'smtp_structural' => filled(data_get($smtp, 'host')) && filled(data_get($smtp, 'port')), 'host_configured' => filled(data_get($smtp, 'host')), 'port_configured' => filled(data_get($smtp, 'port')), 'sender_address_configured' => filled(config('mail.from.address')), 'sender_name_configured' => filled(config('mail.from.name'))]; return new DiagnosticResult($details['sender_address_configured'] ? 'pass' : 'warning', 'mail.readiness', $details['sender_address_configured'] ? 'Mail configuration is structurally present; no connection or delivery was attempted.' : 'Mail sender address is not configured.', $details); }
    private function queueCheck(): DiagnosticResult { $driver = (string) config('queue.default'); $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null; return new DiagnosticResult('pass', 'queue.configuration', $driver === 'sync' ? 'Synchronous queue execution is configured.' : 'Queue backend configuration is available.', ['driver' => $driver, 'asynchronous' => $driver !== 'sync', 'failed_jobs_count' => $failed]); }
    private function schedulerCheck(): DiagnosticResult { $file = base_path('routes/console.php'); $registered = is_readable($file) && str_contains((string) file_get_contents($file), 'compliance'); return new DiagnosticResult($registered ? 'not_verified' : 'warning', 'scheduler.configuration', $registered ? 'Schedule is configured; recent execution cannot currently be verified.' : 'Compliance schedule registration was not found.', ['registered' => $registered]); }
    private function loggingCheck(): DiagnosticResult { $channel = (string) config('logging.default'); $path = config('logging.channels.'.$channel.'.path'); return new DiagnosticResult(filled($channel) ? 'pass' : 'warning', 'logging.configuration', 'Default logging channel configuration is available.', ['channel' => $channel, 'facility_configured' => filled($path) || in_array(config('logging.channels.'.$channel.'.driver'), ['errorlog', 'syslog', 'stack'], true)]); }
    private function httpsCheck(): DiagnosticResult { if (! $this->productionLike()) return new DiagnosticResult('not_verified', 'security.https', 'HTTPS enforcement is not evaluated as a local development failure.', ['expected' => false]); return new DiagnosticResult(str_starts_with((string) config('app.url'), 'https://') ? 'pass' : 'not_verified', 'security.https', str_starts_with((string) config('app.url'), 'https://') ? 'Application URL expects HTTPS.' : 'HTTPS expectation could not be proven from safe runtime configuration.'); }
    private function cookieCheck(string $key, bool $enabled, string $pass, string $warning): DiagnosticResult { $fail = $key === 'secure' && $this->productionLike() && str_starts_with((string) config('app.url'), 'https://') && ! $enabled; return new DiagnosticResult($fail ? 'fail' : ($enabled ? 'pass' : 'warning'), 'security.'.$key.'_cookie', $fail ? 'Secure cookies are disabled for an HTTPS production-like deployment.' : ($enabled ? $pass : $warning), ['enabled' => $enabled]); }
    private function groups(array $checks): array { $map = ['SYSTEM HEALTH' => ['application.environment', 'application.runtime', 'application.debug', 'frontend.manifest', 'database.connection', 'database.migrations'], 'SECURITY' => ['application.debug', 'security.https', 'security.session_cookie', 'security.http_only_cookie', 'security.same_site_cookie'], 'SERVICES' => ['cache.configuration', 'session.configuration', 'storage.private', 'mail.readiness', 'queue.configuration', 'scheduler.configuration', 'logging.configuration']]; $by = collect($checks)->keyBy('check'); return collect($map)->map(fn ($ids) => collect($ids)->map(fn ($id) => $by->get($id))->filter()->values()->all())->all(); }
    private function snapshot(array $checks): array { return ['schema_version' => '1.0', 'generated_at' => now()->toIso8601String(), 'application' => ['name' => config('app.name'), 'environment' => app()->environment(), 'laravel_version' => app()->version(), 'php_version' => PHP_VERSION, 'timezone' => config('app.timezone'), 'frontend_manifest_present' => data_get(collect($checks)->firstWhere('check', 'frontend.manifest'), 'status') === 'pass'], 'runtime' => ['debug_enabled' => (bool) config('app.debug'), 'database_driver' => config('database.default'), 'cache_driver' => config('cache.default'), 'session_driver' => config('session.driver'), 'queue_driver' => config('queue.default'), 'mail_driver' => config('mail.default'), 'storage_disk' => config('filesystems.default')], 'summary' => collect($checks)->countBy('status')->all(), 'checks' => $checks]; }
}
