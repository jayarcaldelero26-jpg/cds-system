<?php

namespace App\Http\Middleware;

use App\Support\ArchiveRequestTrace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Timings for the synchronous PENRO Records archive dispatch only. */
final class ArchiveActionTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route()?->getName() !== 'submission-tracking.transition'
            || (string) $request->route('stage') !== 'dispatch_penro_records_to_cds_focal') {
            return $next($request);
        }

        $providedId = $request->header('X-CDS-Trace-ID');
        $clientProvidedId = ArchiveRequestTrace::validId($providedId);
        $traceId = $clientProvidedId ? strtolower($providedId) : bin2hex(random_bytes(16));
        ArchiveRequestTrace::attach($request, $traceId);
        $startedAt = hrtime(true);
        $status = 'exception';

        try {
            $response = $next($request);
            $status = (string) $response->getStatusCode();
            $response->headers->set('X-CDS-Trace-ID', $traceId);

            if ($clientProvidedId && $response->isRedirection() && $this->localPerfOptedIn($request)) {
                $location = $response->headers->get('Location');
                if (is_string($location) && $location !== '') {
                    $fragmentPosition = strpos($location, '#');
                    $fragment = $fragmentPosition === false ? '' : substr($location, $fragmentPosition);
                    $base = $fragmentPosition === false ? $location : substr($location, 0, $fragmentPosition);
                    $base = preg_replace('/([?&])__cds_trace=[^&]*/', '$1__cds_trace='.$traceId, $base, -1, $replaced);
                    if ($replaced === 0) {
                        $joiner = str_contains($base, '?') ? '&' : '?';
                        $base .= $joiner.'__cds_trace='.$traceId;
                    }
                    $response->headers->set('Location', $base.$fragment);
                }
            }

            return $response;
        } finally {
            Log::debug('PENRO Records dispatch request completed.', [
                'request_id' => $traceId,
                'phase' => 'action_post',
                'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
                'status' => $status,
            ]);
        }
    }

    private function localPerfOptedIn(Request $request): bool
    {
        if (! app()->environment('local')
            || $request->getHost() !== 'cds-system.test'
            || ! in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
            return false;
        }

        $referer = $request->headers->get('referer');
        $query = is_string($referer) ? parse_url($referer, PHP_URL_QUERY) : null;
        if (! is_string($query)) return false;

        parse_str($query, $parameters);

        return ($parameters['__cds_perf'] ?? null) === '1';
    }
}
