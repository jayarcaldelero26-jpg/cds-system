<?php

namespace Tests\Unit;

use App\Support\LocalNavigationTrace;
use App\Models\User;
use App\Http\Middleware\LocalNavigationTiming;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Tests\TestCase;

class LocalNavigationTraceTest extends TestCase
{
    public function test_web_timing_middleware_emits_trace_headers_on_full_html_and_cleans_up(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);

        $request = $this->request('GET', '127.0.0.1', false, 'submission-tracking.index');
        $request->setUserResolver(static fn () => new User(['section' => 'CENRO_CDS_FOCAL']));
        $originalEvents = app('events');
        $middleware = new LocalNavigationTiming;

        $response = $middleware->handle($request, function (Request $request): HttpResponse {
            LocalNavigationTrace::activate($request);

            return new HttpResponse('<html>document</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->headers->get('Server-Timing'));
        $this->assertSame('submission-tracking.index', $response->headers->get('X-CDS-Perf-Route'));
        $this->assertStringContainsString('php='.PHP_VERSION.';sapi='.PHP_SAPI, $response->headers->get('X-CDS-Perf-Runtime'));
        $this->assertSame($originalEvents, app('events'));
        $this->assertFalse($request->attributes->has('_cds_local_navigation_trace'));
    }

    public function test_opt_in_trace_allows_full_and_partial_page_gets_and_emits_headers_for_authenticated_success(): void
    {
        config(['app.env' => 'local']);
        $this->app['env'] = 'local';

        $request = $this->request('GET', '127.0.0.1', false, 'submission-tracking.index');
        $this->assertTrue(LocalNavigationTrace::eligible($request));
        $this->assertTrue(LocalNavigationTrace::eligible($this->request('GET', '127.0.0.1', true, 'submission-tracking.index')));

        $routeMiddleware = app('router')->getRoutes()->getByName('submission-tracking.index')->gatherMiddleware();
        $this->assertContains('web', $routeMiddleware);
        $this->assertContains('auth', $routeMiddleware);
        $this->assertContains('can:submission-tracking.view', $routeMiddleware);
        app(HttpKernelContract::class);
        $resolvedMiddleware = app('router')->gatherRouteMiddleware(app('router')->getRoutes()->getByName('submission-tracking.index'));
        $this->assertContains(LocalNavigationTiming::class, $resolvedMiddleware);
        $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $resolvedMiddleware);
        $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $resolvedMiddleware);
        $this->assertLessThan(
            array_search(\Illuminate\Session\Middleware\StartSession::class, $resolvedMiddleware, true),
            array_search(LocalNavigationTiming::class, $resolvedMiddleware, true),
        );
        $this->assertLessThan(
            array_search(\Illuminate\Auth\Middleware\Authenticate::class, $resolvedMiddleware, true),
            array_search(LocalNavigationTiming::class, $resolvedMiddleware, true),
        );

        $originalEvents = app('events');
        $this->assertTrue(LocalNavigationTrace::begin($request));
        $this->assertSame($originalEvents, app('events'));
        $request->setUserResolver(static fn () => new User(['section' => 'CENRO_CDS_FOCAL']));
        LocalNavigationTrace::activate($request);
        $this->assertNotSame($originalEvents, app('events'));
        $response = new HttpResponse('<html>document</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        LocalNavigationTrace::finish($request, $response);
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('submission-tracking.index', $response->headers->get('X-CDS-Perf-Route'));
        $this->assertSame('CENRO_CDS_FOCAL', $response->headers->get('X-CDS-Perf-Actor-Category'));
        $this->assertStringContainsString('config_cached=', $response->headers->get('X-CDS-Perf-Runtime'));
        $this->assertStringContainsString('app_pipeline;dur=', $response->headers->get('Server-Timing'));
        $this->assertStringContainsString('sql;dur=', $response->headers->get('Server-Timing'));
        LocalNavigationTrace::end($request);
        $this->assertSame($originalEvents, app('events'));
        $this->assertFalse($request->attributes->has('_cds_local_navigation_trace'));

        $unauthenticated = $this->request('GET', '127.0.0.1', false, 'submission-tracking.index');
        $this->assertTrue(LocalNavigationTrace::begin($unauthenticated));
        $unauthenticatedResponse = new HttpResponse('', 200);
        LocalNavigationTrace::finish($unauthenticated, $unauthenticatedResponse);
        $this->assertNull($unauthenticatedResponse->headers->get('X-CDS-Perf-Id'));
        $this->assertNull($unauthenticatedResponse->headers->get('X-CDS-Perf-Runtime'));
        LocalNavigationTrace::end($unauthenticated);
        $this->assertFalse($unauthenticated->attributes->has('_cds_local_navigation_trace'));

        $rejectedResponse = new HttpResponse('denied', 403);
        $rejected = $this->request('GET', '127.0.0.1', false, 'submission-tracking.index');
        $this->assertTrue(LocalNavigationTrace::begin($rejected));
        $rejected->setUserResolver(static fn () => new User(['section' => 'CENRO_CDS_FOCAL']));
        LocalNavigationTrace::activate($rejected);
        LocalNavigationTrace::finish($rejected, $rejectedResponse);
        $this->assertNull($rejectedResponse->headers->get('X-CDS-Perf-Id'));
        $this->assertNull($rejectedResponse->headers->get('X-CDS-Perf-Runtime'));
        LocalNavigationTrace::end($rejected);
        $this->assertSame($originalEvents, app('events'));

        $this->assertFalse(LocalNavigationTrace::eligible($this->request('GET', '192.0.2.4', true, 'submission-tracking.index')));
        $this->assertFalse(LocalNavigationTrace::eligible($this->request('POST', '127.0.0.1', true, 'submission-tracking.index')));
        $this->assertFalse(LocalNavigationTrace::eligible($this->request('GET', '127.0.0.1', true, 'submission-tracking.transition')));
        $this->assertFalse(LocalNavigationTrace::eligible($this->request('GET', '127.0.0.1', true, 'submission-tracking.index', 'other.local')));
        $this->assertFalse(LocalNavigationTrace::eligible($this->request('GET', '127.0.0.1', true, 'submission-tracking.index', 'cds-system.test', false)));

        $this->app['env'] = 'testing';
        $this->assertFalse(LocalNavigationTrace::eligible($request));
    }

    public function test_opt_in_trace_reports_phase_queries_and_aggregate_context_only(): void
    {
        config(['app.env' => 'local']);
        $this->app['env'] = 'local';
        $request = $this->request('GET', '127.0.0.1', false, 'submission-tracking.index');
        $request->setUserResolver(static fn () => new User(['section' => 'CENRO_CDS_FOCAL']));
        $originalEvents = app('events');

        $this->assertTrue(LocalNavigationTrace::begin($request));
        LocalNavigationTrace::activate($request);
        LocalNavigationTrace::context($request, ['view' => 'outgoing', 'selected' => false, 'incoming' => 3]);
        LocalNavigationTrace::increment($request, 'projected_rows', 12);
        LocalNavigationTrace::measure($request, 'st_source_load', static fn () => DB::select('select 1'));

        $response = new HttpResponse('', 200);
        LocalNavigationTrace::finish($request, $response);
        $timing = $response->headers->get('Server-Timing');

        $this->assertStringContainsString('st_source_load;dur=', $timing);
        $this->assertStringContainsString('queries 1', $timing);
        $this->assertSame('view=outgoing;selected=no;incoming=3', $response->headers->get('X-CDS-Perf-Context'));
        $this->assertSame('projected_rows=12', $response->headers->get('X-CDS-Perf-Counts'));
        $this->assertStringNotContainsString('select 1', $timing);

        LocalNavigationTrace::end($request);
        $this->assertSame($originalEvents, app('events'));
        $this->assertFalse($request->attributes->has('_cds_local_navigation_trace'));
    }

    public function test_opt_in_trace_reports_bootstrap_boundaries_and_cleans_up_marks(): void
    {
        config(['app.env' => 'local']);
        $this->app['env'] = 'local';
        $base = hrtime(true) - 6_000_000;
        $GLOBALS['cds_local_navigation_bootstrap_timing'] = [
            'front_controller_started' => $base,
            'autoload_started' => $base + 1_000_000,
            'autoload_finished' => $base + 2_000_000,
            'app_constructed' => $base + 3_000_000,
            'framework_booted' => $base + 4_000_000,
        ];

        $request = $this->request('GET', '127.0.0.1', true, 'submission-tracking.index');
        $request->setUserResolver(static fn () => new User(['section' => 'CENRO_CDS_FOCAL']));
        $originalEvents = app('events');
        $this->assertTrue(LocalNavigationTrace::begin($request));
        LocalNavigationTrace::activate($request);

        $response = new HttpResponse('{"component":"SubmissionTracking/Index"}', 200, [
            'Content-Type' => 'application/json',
            'X-Inertia' => 'true',
        ]);
        LocalNavigationTrace::finish($request, $response);
        $timing = $response->headers->get('Server-Timing');

        foreach ([
            'front_controller_pre_autoload',
            'composer_autoload',
            'app_construction',
            'framework_provider_boot',
            'framework_boot_to_web_trace',
        ] as $phase) {
            $this->assertStringContainsString($phase.';dur=', $timing);
        }

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('true', $response->headers->get('X-Inertia'));
        $this->assertSame('{"component":"SubmissionTracking/Index"}', $response->getContent());
        LocalNavigationTrace::end($request);
        $this->assertSame($originalEvents, app('events'));
        $this->assertArrayNotHasKey('cds_local_navigation_bootstrap_timing', $GLOBALS);
        $this->assertFalse($request->attributes->has('_cds_local_navigation_trace'));
    }

    private function request(string $method, string $remoteAddress, bool $inertia, string $routeName, string $host = 'cds-system.test', bool $optIn = true): Request
    {
        $request = Request::create(
            'http://'.$host.'/submission-tracking'.($optIn ? '?__cds_perf=1' : ''),
            $method,
            [],
            [],
            [],
            ['REMOTE_ADDR' => $remoteAddress] + ($inertia ? ['HTTP_X_INERTIA' => 'true'] : []),
        );
        $route = new Route(['GET'], 'submission-tracking', ['as' => $routeName, 'uses' => 'TestController@index']);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }
}
