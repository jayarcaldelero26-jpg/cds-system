<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request, local-only timing for authorized Inertia page GETs.
 * No query text, bindings, response data, or user identity is retained.
 */
final class LocalNavigationTrace
{
    private const ATTRIBUTE = '_cds_local_navigation_trace';

    private const ROUTES = [
        'dashboard',
        'protected-areas.index',
        'reports.index',
        'engp-reports.index',
        'engp-reports.summary',
        'submission-tracking.index',
        'compliance-alerts.index',
        'business-calendar.index',
        'compliance-alert-recipients.index',
        'admin.users.index',
        'audit-logs.index',
        'settings.index',
        'settings.system-diagnostics.index',
        'settings.storage.index',
        'settings.compliance-alerts',
        'bms.index',
        'bams.index',
        'bams.report-submissions.index',
        'imea.index',
        'imea.report-submissions.index',
        'imea.maintenance-reports.index',
        'aws.index',
        'aws.data',
        'management-plans.index',
        'management-plans.summary',
        'management-plans.types.show',
        'ipaf.index',
        'conservation-reports.index',
    ];

    public static function eligible(Request $request): bool
    {
        if (! app()->environment('local')
            || app()->bound('octane')
            || $request->getHost() !== 'cds-system.test'
            || ! in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            || ! $request->isMethod('GET')
            || $request->query('__cds_perf') !== '1') {
            return false;
        }

        return in_array($request->route()?->getName(), self::ROUTES, true);
    }

    public static function begin(Request $request): bool
    {
        if (! self::eligible($request)) {
            return false;
        }

        $started = hrtime(true);

        $state = (object) [
            'id' => bin2hex(random_bytes(8)),
            'started' => $started,
            'pre_trace_ms' => defined('LARAVEL_START')
                ? max(0.0, (microtime(true) - LARAVEL_START) * 1000)
                : null,
            'queries' => 0,
            'sql_ms' => 0.0,
            'phase_stack' => [],
            'phase_queries' => [],
            'phase_sql_ms' => [],
            'counters' => [],
            'context' => [],
            'phases' => self::bootstrapPhases($started),
            'original_events' => null,
            'activated' => false,
        ];
        $request->attributes->set(self::ATTRIBUTE, $state);

        return true;
    }

    public static function activate(Request $request): void
    {
        $state = self::state($request);
        if (! $state || $state->activated || ! $request->user()) {
            return;
        }

        $state->original_events = app('events');
        $traceEvents = clone $state->original_events;
        $traceEvents->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($state): void {
            $state->queries++;
            $state->sql_ms += max(0.0, (float) $query->time);
            $phase = end($state->phase_stack) ?: 'unattributed';
            $state->phase_queries[$phase] = ($state->phase_queries[$phase] ?? 0) + 1;
            $state->phase_sql_ms[$phase] = ($state->phase_sql_ms[$phase] ?? 0.0) + max(0.0, (float) $query->time);
        });
        app()->instance('events', $traceEvents);
        foreach (DB::getConnections() as $connection) {
            $connection->setEventDispatcher($traceEvents);
        }
        $state->activated = true;
    }

    public static function measure(Request $request, string $name, Closure $callback): mixed
    {
        $state = self::state($request);
        if (! $state) {
            return $callback();
        }

        $started = hrtime(true);
        $state->phase_stack[] = $name;
        try {
            return $callback();
        } finally {
            array_pop($state->phase_stack);
            self::addPhase($state, $name, (hrtime(true) - $started) / 1_000_000);
        }
    }

    /** Trace callers that also run in CLI/unit contexts without an HTTP request. */
    public static function measureCurrent(string $name, Closure $callback): mixed
    {
        $request = app()->resolved('request') ? app('request') : null;

        return $request instanceof Request ? self::measure($request, $name, $callback) : $callback();
    }

    public static function markStarted(Request $request, string $name): ?int
    {
        $state = self::state($request);
        if (! $state) {
            return null;
        }

        $state->phase_stack[] = $name;

        return hrtime(true);
    }

    public static function markCurrentStarted(string $name): ?object
    {
        $request = app()->resolved('request') ? app('request') : null;
        if (! $request instanceof Request) {
            return null;
        }

        $started = self::markStarted($request, $name);

        return $started === null ? null : (object) ['request' => $request, 'started' => $started];
    }

    public static function markCurrentFinished(string $name, ?object $mark): void
    {
        if ($mark && $mark->request instanceof Request && is_int($mark->started ?? null)) {
            self::markFinished($mark->request, $name, $mark->started);
        }
    }

    public static function markFinished(Request $request, string $name, ?int $started): void
    {
        $state = self::state($request);
        if ($state && $started !== null) {
            array_pop($state->phase_stack);
            self::addPhase($state, $name, (hrtime(true) - $started) / 1_000_000);
        }
    }

    /** Add only low-cardinality aggregate counters to the opt-in response. */
    public static function increment(Request $request, string $name, int $by = 1): void
    {
        $state = self::state($request);
        if (! $state || ! preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $name)) {
            return;
        }

        $state->counters[$name] = ($state->counters[$name] ?? 0) + max(0, $by);
    }

    public static function incrementCurrent(string $name, int $by = 1): void
    {
        $request = app()->resolved('request') ? app('request') : null;
        if ($request instanceof Request) {
            self::increment($request, $name, $by);
        }
    }

    /** Context values are deliberately limited to small enum/boolean/integer facts. */
    public static function context(Request $request, array $values): void
    {
        $state = self::state($request);
        if (! $state) {
            return;
        }

        foreach ($values as $name => $value) {
            if (! in_array($name, ['view', 'selected', 'rows', 'incoming', 'outgoing', 'history'], true)) {
                continue;
            }
            if ($name === 'view' && in_array($value, ['incoming', 'outgoing', 'history'], true)) {
                $state->context[$name] = $value;
            } elseif ($name === 'selected' && is_bool($value)) {
                $state->context[$name] = $value ? 'yes' : 'no';
            } elseif ($name !== 'view' && $name !== 'selected' && is_int($value) && $value >= 0) {
                $state->context[$name] = $value;
            }
        }
    }

    public static function finish(Request $request, Response $response): void
    {
        $state = self::state($request);
        $user = $request->user();
        if (! $state || ! $user || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return;
        }

        $category = app(\App\Services\Authorization\OrganizationalAccessService::class)->effectiveCategory($user);
        $category = in_array($category, [
            \App\Services\Authorization\OrganizationalAccessService::CENRO_RECORDS,
            \App\Services\Authorization\OrganizationalAccessService::CENRO_CHIEF,
            \App\Services\Authorization\OrganizationalAccessService::CENRO_FOCAL,
            \App\Services\Authorization\OrganizationalAccessService::PENRO_RECORDS,
            \App\Services\Authorization\OrganizationalAccessService::OFFICE_PENRO,
            \App\Services\Authorization\OrganizationalAccessService::PENRO_TSD_CHIEF,
            \App\Services\Authorization\OrganizationalAccessService::PENRO_CHIEF,
            \App\Services\Authorization\OrganizationalAccessService::PENRO_FOCAL,
            \App\Services\Authorization\OrganizationalAccessService::PAMO,
        ], true) ? $category : 'GLOBAL_OR_OTHER';

        $route = (string) $request->route()?->getName();
        $pipelineMs = (hrtime(true) - $state->started) / 1_000_000;
        $serverTiming = [
            'app_pipeline;dur='.self::duration($pipelineMs),
            'sql;dur='.self::duration($state->sql_ms).';desc="queries '.$state->queries.'"',
        ];

        foreach ($state->phases as $name => $duration) {
            $queries = $state->phase_queries[$name] ?? 0;
            $sqlMs = $state->phase_sql_ms[$name] ?? 0.0;
            $description = $queries > 0 ? ';desc="queries '.$queries.'; sql_ms '.self::duration($sqlMs).'"' : '';
            $serverTiming[] = $name.';dur='.self::duration($duration).$description;
        }

        foreach ($state->phase_queries as $name => $queries) {
            if (! isset($state->phases[$name])) {
                $serverTiming[] = $name.'_sql;dur='.self::duration($state->phase_sql_ms[$name] ?? 0.0).';desc="queries '.$queries.'"';
            }
        }

        if ($state->pre_trace_ms !== null) {
            $serverTiming[] = 'to_trace_start;dur='.self::duration($state->pre_trace_ms);
        }

        $response->headers->set('Server-Timing', implode(', ', $serverTiming));
        $response->headers->set('X-CDS-Perf-Id', $state->id);
        $response->headers->set('X-CDS-Perf-Route', $route);
        $opcacheLoaded = extension_loaded('Zend OPcache');
        $response->headers->set('X-CDS-Perf-Runtime', implode(';', [
            'php='.PHP_VERSION,
            'sapi='.PHP_SAPI,
            'app_debug='.(config('app.debug') ? '1' : '0'),
            'config_cached='.(app()->configurationIsCached() ? '1' : '0'),
            'opcache_loaded='.($opcacheLoaded ? '1' : '0'),
            'opcache_enabled='.($opcacheLoaded && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL) ? '1' : '0'),
            'xdebug_loaded='.(extension_loaded('xdebug') ? '1' : '0'),
            'debugbar_configured='.(config('debugbar.enabled') ? '1' : '0'),
        ]));

        $response->headers->set('X-CDS-Perf-Actor-Category', $category);
        if ($state->context !== []) {
            $parts = [];
            foreach ($state->context as $name => $value) {
                $parts[] = $name.'='.$value;
            }
            $response->headers->set('X-CDS-Perf-Context', implode(';', $parts));
        }
        if ($state->counters !== []) {
            $parts = [];
            foreach ($state->counters as $name => $value) {
                $parts[] = $name.'='.$value;
            }
            $response->headers->set('X-CDS-Perf-Counts', implode(';', $parts));
        }
    }

    public static function end(Request $request): void
    {
        $state = self::state($request);
        if (! $state) {
            unset($GLOBALS['cds_local_navigation_bootstrap_timing']);

            return;
        }

        if ($state->activated) {
            foreach (DB::getConnections() as $connection) {
                $connection->setEventDispatcher($state->original_events);
            }
            app()->instance('events', $state->original_events);
        }
        $request->attributes->remove(self::ATTRIBUTE);
        unset($GLOBALS['cds_local_navigation_bootstrap_timing']);
    }

    private static function state(Request $request): ?object
    {
        $state = $request->attributes->get(self::ATTRIBUTE);

        return is_object($state) ? $state : null;
    }

    private static function addPhase(object $state, string $name, float $duration): void
    {
        if (! preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $name)) {
            return;
        }

        $state->phases[$name] = ($state->phases[$name] ?? 0.0) + max(0.0, $duration);
    }

    /** Consume only opt-in loopback bootstrap marks created by public/index.php. */
    private static function bootstrapPhases(int $traceStarted): array
    {
        $marks = $GLOBALS['cds_local_navigation_bootstrap_timing'] ?? null;
        if (! is_array($marks)) {
            return [];
        }

        $boundaries = [
            'front_controller_pre_autoload' => ['front_controller_started', 'autoload_started'],
            'composer_autoload' => ['autoload_started', 'autoload_finished'],
            'app_construction' => ['autoload_finished', 'app_constructed'],
            'framework_provider_boot' => ['app_constructed', 'framework_booted'],
        ];
        $phases = [];
        foreach ($boundaries as $name => [$start, $end]) {
            if (is_int($marks[$start] ?? null) && is_int($marks[$end] ?? null)) {
                $phases[$name] = max(0.0, ($marks[$end] - $marks[$start]) / 1_000_000);
            }
        }

        if (is_int($marks['framework_booted'] ?? null)) {
            $phases['framework_boot_to_web_trace'] = max(0.0, ($traceStarted - $marks['framework_booted']) / 1_000_000);
        }

        return $phases;
    }

    private static function duration(float $milliseconds): string
    {
        return number_format(max(0.0, $milliseconds), 2, '.', '');
    }
}
