# CDS-SMART home navigation and Debugbar verification

Date: 2026-10-09 (Asia/Manila)

## Result

**PARTIAL.** Both local aliases currently serve this checkout and the current Debugbar asset request succeeds on each. The reported 404 could not be reproduced, so its historical cause is not established and no asset change was justified. No authenticated browser session was available to measure the three real Submission Tracking views or inspect the old network entries' full URLs and initiators. No application source, configuration, or database change was made.

## Runtime and host mapping

Evidence collected read-only:

- Git is on `main` at `f96bd0c4e60230b8bf7562037550f4658726a992`, tracking `origin/main`.
- The active Apache 2.4.66 vhost for the second local alias maps HTTP port 80 to the project public directory. The trace-enabled local alias HTTP and HTTPS vhosts resolve to the same document root. Both aliases resolve to loopback (127.0.0.1); the second alias has only the HTTP vhost in the active vhost listing.
- Laravel reports local, Laravel 13.31.0, and a URL host matching the trace-enabled local alias. The effective Laravel MySQL connection was queried without reading or printing credentials; its database and server identity matched the supplied home database identity. Local database and server identifiers are omitted. The server version is MySQL 8.4.3.
- CLI PHP is 8.3.30 and loads Laragon's active php.ini. Apache loads php_module and responds with PHP 8.3.30. The CLI SAPI is cli; the authenticated web trace header that would directly report Apache's PHP_SAPI was not available, so the exact web SAPI value was not recorded.
- The locked and installed packages are `barryvdh/laravel-debugbar` 4.4.0 and `php-debugbar/php-debugbar` 3.8.0. There is no published `public/vendor` Debugbar bundle; this package serves assets through its registered `/_debugbar/assets` route and `AssetController`.

Checks through both local aliases target the same current application root and database. This does not prove they targeted the same state at the time of the old capture.

## Debugbar asset check

The exact prior Debugbar asset path, /_debugbar/assets?type=js&mtime=1783099856, was requested over HTTP through each local alias. Both returned 200, Content-Type: text/javascript; charset=utf-8, and 192,707 bytes. The response begins with JavaScript, rather than an HTML error page or an empty response. The observed curl durations were about 86 ms via the trace-enabled alias and 76 ms via the second alias; these are single local HTTP transfer timings, not browser timings.

The asset route is registered and its `DebugbarEnabled` middleware allowed these web requests. In this local app, `APP_DEBUG` is enabled, Debugbar injection is configured, and production restrictions remain in place. A console-only `isEnabled()` probe is not a valid web check because the package disables itself while Laravel runs in console mode; the successful HTTP route request is the web evidence.

**Cause and repair:** the old 404 is not reproducible now, and the old capture supplied no response body or additional request context. The current evidence does not identify whether the earlier 404 came from a transient route/configuration state, browser cache, or another cause. No repair was made; changing diagnostics or suppressing the old error would not be justified by this evidence.

## Navigation source review and measurements

The current home database has zero rows in the report submission tables, zero rows in `document_routing_events` and `pamb_routing_events`, and zero notifications. These post-cleanup counts cannot represent the earlier populated dataset or establish production-volume performance.

The navigation source has one notification refresh request: GET /notifications/recent, with same-origin credentials and an Accept: application/json header. The component schedules it every 60 seconds; it also calls refresh when the bell is clicked, on the cds:notifications-updated event, and on an explicit retry. The event is dispatched after notification actions in the Notifications page. The component does not immediately fetch on mount. Source review found no second implementation of this endpoint, but source review alone cannot identify which trigger produced the old network rows.

The previous capture only labels recent resources and gives 113–150 ms durations. It does not provide their complete URLs, initiators, request mode, or lifecycle trigger. No duplication was inferred from those names. Current source shows the Incoming, Outgoing, and History tabs each link to `submission-tracking` with the corresponding `view` parameter. The controller has request-scoped phases for pagination, workspace queue projection, filters, context, and response construction; no duplicated browser operation was demonstrated.

The old page timings (1,131 ms for History and 1,015 ms for Incoming) preceded cleanup. They are not a valid before baseline for today's empty data and cannot be compared to the curl asset timings.

### Supplemental isolated CLI profiles

Because authenticated browser measurement was unavailable, two existing focused profiles were run with process-level `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and `APP_ENV=testing`. `phpunit.xml` independently configures the same in-memory SQLite database, and `bootstrap/cache/config.php` is absent. These tests wrote fixtures only to their disposable in-memory database; they did not write to the application database.

- Synthetic Outgoing, 60 rows, five warm in-process requests: median elapsed **1,117.83 ms**, median SQL **5.42 ms** across 72 queries, and median routing-presentation phase **947.42 ms**.
- Synthetic History, CENRO focal actor, 60 rows with one terminal and one intermediate record and fake archive/storage/notification providers, five warm in-process requests: median elapsed **765.31 ms**, median SQL **10.58 ms** across 156 queries, median complete workspace queue phase **639.91 ms**, and median routing-presentation phase **602.92 ms**.

These are simulated Laravel feature-test timings over SQLite within the PHP test process. They omit Apache, network transfer, browser rendering, and the owner's actual actor/data scope. They are not before/after measurements and do not justify a production optimization. No comparable Incoming profile was available in the existing focused tests.

### Browser limitation and guarded capture steps

Chrome processes were present, but no DevTools/WebDriver listener was available and the BrowserAct CLI is not installed. There was no supported way in this session to attach to the owner's existing authenticated browser. No new browser profile, credentials, or authentication bypass was used.

For a real capture, use the owner's existing authorized local session on the exact allowlisted trace-enabled local alias, not the second local alias, which the trace guard does not allow. Keep the same actor, filters, cache state, and window open. In DevTools Network, record the full request URL, whether X-Inertia: true is present, status, transfer size, initiator, and the browser's waiting/TTFB, transfer, and total times. Record a first request separately, then five warm requests for each view without summing overlapping requests. Use the relative guarded paths below on the trace-enabled alias to receive the local server phase/query headers:

```text
/submission-tracking?view=incoming&__cds_perf=1
/submission-tracking?view=outgoing&__cds_perf=1
/submission-tracking?view=history&__cds_perf=1
```

The trace only activates for local, authenticated, loopback GET requests to the exact trace-enabled alias with __cds_perf=1 and an allowlisted route. It reports aggregate Server-Timing, low-cardinality queue counts/context, runtime flags, and an actor category, not the account identity. Direct path entry is a full-page GET; capture normal tab clicks separately as Inertia requests and inspect their X-Inertia request header and initiator. Do not export response bodies, cookies, or authentication headers.

## Changes and preservation

- This verification report was added after the original read-only checks and later sanitized to remove machine-specific identifiers. The follow-up changed documentation only; no application PHP, frontend, vhost, .env, cache, routing setting, report-submission data, event, snapshot, watermark, or ID sequence was changed.
- The existing unstaged `ConservationReportSubmissionController.php` edit remains untouched. `.recovery/` and protected backups remain untouched.
- No migrations, seeders, dependency installs, builds, service restarts, commits, or pushes were run.
- The targeted SQLite profiles passed: 1 test / 120 assertions for Outgoing and 1 test / 142 assertions for History.

