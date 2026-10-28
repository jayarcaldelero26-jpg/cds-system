# Isolated capacity check - 2026-10-27

## Scope and isolation

This is a first bounded synthetic run against checkpoint `ae41dc28ff74badb55b4a03cc5eacf31b014a232` (parent `3c1d4e4cfb9774b39b493d6cb499fcef161598bf`). The independent lab Git root is `%LOCALAPPDATA%\Temp\cds-isolated-capacity-lab-3f8209c5822c4e5b9552c0cdba32c630`; it has no remote or object alternates. The checkpoint tree stayed pinned and clean apart from the lab-only profiler and Composer cache. The serving checkout stayed at `c418d2d3f11afb50f17e5983bc53e5758c733e99`; its index remained clean.

PHP 8.3.30 used SQLite `:memory:` with the repository PDO guard active and a 512 MiB process limit for the profiler. There was no `.env`, config cache, browser, live Office request, operational database connection, or real provider call. The prior isolated Vite output was reused; its manifest contains `DocumentTurnaround-B4yWEfjF.js`. No build or promotion was run in this check.

Fixtures used one synthetic Protected Area and CENRO Mati. Each load tier had exactly the stated number of existing report rows, all in the selected 2026 H2 period and with processing evidence: `N-8` generic Conservation rows, one row in each of the seven native PA tables, and one ENGP row. All source IDs were distinct. There were no extra PAs or duplicated source identities.

## Document TAT projection

Each component case is one initial sample followed by five warm samples. Warm medians and ranges below are in-process service measurements. Query counts and SQL duration are for the projection call; response bytes are the JSON-serialized service payload. Memory is PHP allocated peak, rounded to MiB.

| Total / processed | Unit | Returned | First ms | Warm median (range) ms | Warm queries / SQL ms | Peak MiB | Payload bytes |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 100 / 100 | Conservation | 99 | 323.60 | 314.83 (307.35-347.02) | 67 / 3.95 | 58 | 45,485 |
| 100 / 100 | Development | 1 | 2.80 | 2.17 (2.04-2.40) | 9 / 0.40 | 58 | 1,075 |
| 1,000 / 1,000 | Conservation | 999 | 2,227.38 | 2,224.49 (2,219.78-2,304.27) | 67 / 9.22 | 80 | 448,677 |
| 1,000 / 1,000 | Development | 1 | 2.34 | 2.15 (2.13-2.28) | 9 / 0.36 | 80 | 1,075 |
| 5,000 / 5,000 | Conservation | 4,999 | 11,180.30 | 11,221.18 (11,117.10-11,508.00) | 67 / 35.01 | 100 | 2,248,669 |
| 5,000 / 5,000 | Development | 1 | 2.52 | 2.16 (2.15-2.16) | 9 / 0.36 | 100 | 1,075 |

The 5,000-row Conservation projection is the main result: about 11.2 seconds warm while measured SQL totals about 35 ms. This points to application-side projection work in this synthetic case. Development had one valid ENGP obligation at every tier, so these numbers do not stress a large Development cohort.

## Office TAT controller

The actual `/admin/office-tat` route returned successful rendered responses for both units at the 100-report tier (one initial plus five warm samples each). Conservation warm median was 2,087.98 ms (2,059.48-2,142.71), 1,556 queries / 61.27 ms SQL, 74 MiB peak, and 506,542 response-body bytes. Development was 3,592.14 ms (3,560.20-3,691.09), 1,555 queries / 59.93 ms SQL, 76 MiB peak, and 508,738 bytes.

The route payload's `documentTurnaround.reports` was zero in this fixture. The standalone service projection above did return the synthetic source cohort, but the controller's recorded assessment roster did not attach it. Treat the controller timings as page baseline only; they are not a 100/1,000/5,000 source-detail controller benchmark. The focused Office TAT roster route test passed separately (1 test, 37 assertions).

## Submission Tracking

The service snapshot returned 25 paginated rows from totals of 100, 1,000, and 5,000. Search matched exactly one synthetic generic report at each tier. These are service payload measurements, not browser or HTTP wire sizes.

| Total | Page warm median (range) ms | Page queries / SQL ms | Search warm median (range) ms | Search queries / SQL ms | Peak MiB | Page / search payload bytes |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 100 | 371.04 (370.37-395.48) | 123 / 5.41 | 29.85 (29.43-30.07) | 63 / 4.85 | 76 | 505,885 / 20,429 |
| 1,000 | 380.79 (378.42-383.09) | 123 / 6.00 | 33.50 (32.98-33.73) | 63 / 6.89 | 80 | 505,887 / 20,431 |
| 5,000 | 384.06 (383.80-428.06) | 123 / 8.78 | 43.56 (43.54-43.95) | 63 / 16.99 | 100 | 505,888 / 20,433 |

The existing route trace also passed for the actual Submission Tracking page at 1, 10, and 60 synthetic rows (125 assertions). It captured five samples total (one initial and four warm), so it is supplemental rather than a six-sample route profile; its warm medians were about 50.16 ms, 153.00 ms, and 775.80 ms respectively. It does not capture response bytes or peak memory.

## Archives and files

- The authorized Archives page test passed with one pending receipt (1 test, 23 assertions). No large manifest-list scale was measured.
- The protected private PDF route test passed (1 test, 41 assertions) using the 600-byte valid PDF fixture. It verified the manifest-bound size/hash, private response headers, complete streamed bytes, and no provider retrieval.
- Archive source cap tests passed for the declared over-cap boundary across all source contracts (1 test, 23 assertions). Actual BMS contract validation also accepted generated 1 MiB, 10 MiB, and 25 MiB payloads, computed a 64-character SHA-256 for each, and identified `application/pdf`. The configured BMS cap is 102,400 KiB. Large files were not uploaded through the archive workflow or streamed from the protected route; the large synthetic payloads were not parsed as renderable PDFs.
- No PDF rendering claim is made. The protected route served PDF bytes; it did not prove client rendering.

## Focused results and limits

Passed: processed-only matrix (1 test, 10 assertions: 18 total reports, 9 processed and returned across Conservation/Development); Document TAT projection and adapter tests (4 tests, 56 assertions); Office TAT roster route (1, 37); Submission Tracking route trace (1, 125); Archives list (1, 23); protected file read (1, 41); source size-cap boundary (1, 23); isolated 100/1,000/5,000 profiler (1, 161; about 195 seconds); corrected 100-tier controller rerun (1, 57; about 40 seconds).

Pending: a safely disposable MySQL concurrency run; controller measurements with its roster actually carrying the synthetic details; dense Development source tiers; 100/1,000/5,000 Archives lists; end-to-end 10/25 MiB archive upload/storage/streaming; live browser verification. SQLite memory results do not prove MySQL locking, concurrency, disk storage, network behavior, or production capacity. There was no load concurrency, p95 calculation, browser wall-time, operational data, or production PA mix.

No TAT calculations, ranking, scoring, calendar, routing, permissions, database, or source files were changed. No commit, push, promotion, deployment, data cleanup, or live provider action occurred.
## Focused TAT follow-up - 2026-10-28

This follow-up used the same isolated Git root and checkpoint `ae41dc28ff74badb55b4a03cc5eacf31b014a232`. The source edits and verification remained in this lab; the serving checkout and index were not changed.

### Cohort reconciliation

The 5,000 fixture rows comprise 4,992 generic Conservation reports, seven native PA reports, and one ENGP report. The Conservation projection correctly returns 4,999 (4,992 generic plus seven native). The remaining row is the ENGP report and belongs to Development, where it is returned as the single report. Combined returned rows remain 5,000; no report was forced into the wrong unit.

### Profile finding and narrow changes

Before optimization, the 5,000 Conservation source inventory had a 16.27 s isolated sample. Fetching source rows and processing evidence together took about 0.19 s, document normalization 51 ms, route-event load 22 ms, and per-document projection 13 ms. Decomposition of the generic source loop measured 5.35 s in period filtering and 10.26 s in detail append. The year helper eagerly evaluated its later computed-deadline fallback even when an earlier accomplished date already supplied the year; detail append then evaluated the computed deadline again.

The lab candidate now checks year-date fallbacks lazily and memoizes a computed deadline for identical source inputs within one inventory call. The existing model deadline accessor, holiday-aware calendar functions, and result fields remain in use. The controller and source inventory also accept top-level recorded-assessment rows when the nested assessment rows are absent. The controller still passes only its recorded, authorized roster; the existing permission and effective-unit checks remain in both services.

### Matched 5,000-row service comparison

Each run used the same in-memory fixture, `2026-H2`, cutoff `2026-10-27`, Conservation and Development units, and one first sample plus five warm samples.

| Unit | Before warm median (range) | After warm median (range) | Warm queries | Warm SQL time | Peak PHP memory | Payload bytes |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Conservation | 15,870.65 ms (15,709.44-16,203.95) | 5,940.80 ms (5,795.55-6,010.97) | 67 | 34.90 / 35.60 ms | 90,177,536 B (86 MiB) | 2,248,669 |
| Development | 1.97 ms (1.97-2.06) | 1.98 ms (1.98-2.01) | 9 | 0.36 / 0.36 ms | 90,177,536 B (86 MiB) process peak | 1,075 |

Conservation warm service time fell by 62.6% (about 9.93 s). The baseline and post-fix payload SHA-256 values match exactly: `34e205e3ee42ffb1c5b3f55d687f2988c8eb76652a61ac0ba952156cd9ac635d`. Both runs returned 4,999 reports. Development also remained at one report with identical payload hash `c7997ee064106e799d62744b8b301c259de19007c8b9c9ade231400860ed79c8`. Query counts and SQL time remained effectively unchanged, so the improvement is in PHP-side source inventory work.

### Corrected controller profile

The actual `/admin/office-tat` route was profiled at the 100-row tier using an authorized global test actor and the route's real recorded assessment. The first response and five warm responses returned 99 Conservation details and one Development detail.

| Unit | Warm median (range) | Warm queries / SQL time | Peak PHP memory | Response-body bytes |
| --- | ---: | ---: | ---: | ---: |
| Conservation | 2,176.22 ms (2,164.32-2,177.58) | 1,623 / 66.20 ms | 79,691,776 B (76 MiB) | 551,772 |
| Development | 3,648.92 ms (3,522.49-3,678.55) | 1,564 / 63.77 ms | 79,691,776 B (76 MiB) | 509,558 |

These are Laravel in-process response-body measurements, not browser or wire times. The controller was not scaled to 5,000 rows.

### Focused verification

- 5,000-row matched TAT baseline: 1 test, 19 assertions, passed.
- 5,000-row post-fix TAT profile: 1 test, 19 assertions, passed; returned payload hashes match the baseline.
- 100-row controller fixture and profile: 1 test, 49 assertions, passed; roster identity and 99/1 detail counts asserted.
- `OfficeTatDocumentTurnaroundTest.php`: 4 tests, 56 assertions, passed.
- `OfficeTatProcessedDocumentSourceTest.php`: 1 test, 10 assertions, passed.
- `BusinessCalendarServiceTest.php`: 13 tests, 143 assertions, passed.
- Focused Office TAT cross-unit access test: 1 test, 31 assertions, passed.
- Focused Office TAT route roster test: 1 test, 37 assertions, passed.
- PHP lint on the two changed services and lab profiler, and `git diff --check`: passed.

All database-backed checks used PHP 8.3.30, SQLite `:memory:`, the repository PDO guard, and a 512 MiB memory limit. No live data, browser, MySQL, provider, deployment, promotion, build, commit, or push was used. The 5,000-row result is a synthetic SQLite service profile, and the controller result covers only the 100-row tier. Memoization gains depend on reports sharing deadline inputs; a cohort with mostly unique inputs may improve less.
Per-case first-sample detail: Conservation was 15,721.46 ms before (71 queries, 35.19 ms SQL, 88,080,384 B peak, 2,248,669 payload bytes) and 5,773.68 ms after (71 queries, 35.27 ms SQL, 90,177,536 B peak, 2,248,669 bytes). Development was 2.71 ms before and 2.85 ms after (9 queries; 0.50 / 0.47 ms SQL; 1,075 bytes). The Development warm peak was 90,177,536 B before and 88,080,384 B after; the 90,177,536 B figure in the table reflects the before/whole-run peak, not the post-fix Development per-case peak.