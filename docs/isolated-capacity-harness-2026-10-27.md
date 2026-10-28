# Isolated capacity harness - 2026-10-27

The lab-only Pest test `tests/Feature/IsolatedCapacityProfileTest.php` builds synthetic rows in the test suite's SQLite `:memory:` PDO, then measures Document TAT projection, the Office TAT controller route, and Submission Tracking page/search service snapshots. It accepts `CDS_CAPACITY_PROFILE_TARGETS` as a comma-separated subset of `100,1000,5000` for shorter reruns. Default is all three.

Run only inside the isolated checkpoint lab:

```powershell
$env:APP_ENV = 'testing'
$env:APP_DEBUG = 'false'
$env:APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
$env:DATABASE_URL = ''
$env:APP_CONFIG_CACHE = ''
$env:MAIL_MAILER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'
$env:COMPLIANCE_ALERTS_SCHEDULED_ENGP_OBLIGATIONS = 'false'
php -d memory_limit=512M artisan test --compact tests/Feature/IsolatedCapacityProfileTest.php
```

The repository `TestCase` checks the opened PDO is SQLite `:memory:` before migrations. The explicit preflight should also be run before the test; remove any process `DB_READ_*` and `DB_WRITE_*` overrides first. Do not set `APP_CONFIG_CACHE`, add `.env`, or use a persistent database. Do not run this profiler from the serving checkout.

Each service/route case records one first sample plus five warm samples, query count and total SQL time, PHP allocated peak, and serialized payload/body bytes. It emits aggregate JSON with exact fixture counts. The controller uses Laravel's in-process HTTP test kernel; no browser or network request is measured. Archive file-size validation is documented in the result report and is not part of this profiler.

Related focused tests used in the report are:

```powershell
php artisan test --compact tests/Feature/OfficeTatComplianceAssessmentTest.php --filter='Office TAT route returns a roster'
php artisan test --compact tests/Feature/OfficeTatProcessedDocumentSourceTest.php --filter='processed document inventory includes generic Conservation'
php artisan test --compact tests/Feature/OfficeTatDocumentTurnaroundTest.php
php artisan test --compact tests/Feature/SubmissionTrackingQueryPerformanceTest.php --filter='isolated Submission Tracking navigation trace'
php artisan test --compact tests/Feature/FinalDocumentArchiveLifecycleTest.php --filter='eligible PENRO focal sees released records awaiting receipt'
php artisan test --compact tests/Feature/FinalDocumentArchiveLifecycleTest.php --filter='protected final previews serve the complete hash-bound private PDF without provider reads'
php artisan test --compact tests/Feature/FinalDocumentArchiveLifecycleTest.php --filter='every archive source enforces its declared upload size cap'
```

These are focused checks, not a substitute for the pending items listed in the result report.
## Focused TAT performance follow-up

The 2026-10-28 run used `CDS_CAPACITY_PROFILE_TARGETS=5000`, `CDS_CAPACITY_PROFILE_TAT_ONLY=true`, `CDS_CAPACITY_PROFILE_CONTROLLER=false`, and `CDS_CAPACITY_PROFILE_PHASES=false` for the matched service comparison. The controller-only fixture check used `CDS_CAPACITY_PROFILE_TARGETS=100`, `CDS_CAPACITY_PROFILE_TAT_ONLY=true`, `CDS_CAPACITY_PROFILE_CONTROLLER=true`, and `CDS_CAPACITY_PROFILE_PHASES=false`. The controller profile asserts that the actual route's recorded roster contains the synthetic participant and returns 99 Conservation reports or one Development report. The result and limitations are appended to `isolated-capacity-check-2026-10-27.md`.