# Office TAT controller profile - 2026-10-28

## Scope and setup

This isolated follow-up uses checkpoint `ae41dc28ff74badb55b4a03cc5eacf31b014a232`. It supersedes the earlier note that only a 100-row controller route had been measured and that route details were zero. The serving checkout was not modified.

The profile used the real Laravel `/admin/office-tat` controller route, PHP 8.3.30, SQLite `:memory:`, the repository PDO guard, and a 512 MiB PHP limit. It used category `CENRO`, period `2026`, cutoff `2026-10-27`, and an authorized PENRO CDS Chief with `office-tat.view`. The actor was not a global administrator (`globalAssessmentAccess=false`). A deterministic test-only `app.asset_url` supplied the matching Inertia version header; it did not make a network request.

The fixtures came from the application registries. The Development cohort contains 500 ENGP records across configured CENRO offices/workflows, with active registry deadlines through the cutoff and routing/receipt evidence. Conservation includes generic reports plus all seven native PA source types.

| Fixture total | Generic Conservation | Native PA | ENGP Development | Conservation details | Development details |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 493 | 7 | 500 | 500 | 500 |
| 5,000 | 4,493 | 7 | 500 | 4,500 | 500 |

Each fixture had the stated database row total. The route asserted complete report totals and every returned document. No row was reclassified to reach the requested fixture size.

## Measurement method

Each unit/tier ran one initial route request and one warm request against the same fixtures, filters, actor scope, and cutoff. The tables report the single warm observation, not a median or percentile.

The route timings separate:

- `assessment`: `analytics` plus `recordedAssessments`; their sub-times are shown in parentheses.
- `source inventory`: `OfficeTatProcessedDocumentSourceService::forAssessment`.
- `turnaround projection`: merge, route-event filtering, calendar projection, and TAT aggregation after inventory.
- `serialization`: Inertia prop resolution and JSON response construction.

Time sequence is `route / assessment (analytics + recorded) / source inventory / turnaround projection / serialization`, in milliseconds. Route time is the in-process Laravel request wall time. Query count and SQL time cover the request. Memory is PHP allocated peak after a per-request peak reset. Response bytes are the complete Inertia JSON body, not network or browser bytes. The TAT SHA is a SHA-256 of the full `documentTurnaround` prop tree, including totals, participant rows, and details.

Temporary timing hooks are retained under `docs/controller-profile-hook-snapshots/` for reproducibility only. The active controller, turnaround service, and Inertia vendor response file were restored after measurement. The hook snapshots are not candidate source. Raw command logs remain in the temporary lab and are not copied into the permanent archive.

## Matched controller comparison

The baseline uses the checkpoint source-inventory implementation with only the top-level assessment-row fallback required to return the real route roster. Both baseline and candidate share the same route-row fallbacks. The baseline excludes the lazy-year lookup and deadline memoization changes being measured. The candidate contains those two source-service optimizations.

### Conservation

| Fixture total; returned details | Baseline warm stages | Candidate warm stages | Queries / SQL ms | Peak bytes | Response bytes | `documentTurnaround` SHA-256 |
| --- | --- | --- | --- | ---: | ---: | --- |
| 1,000; 500 | 1,770 / 89 (41 + 48) / 1,636 / 10 / 12 | 760 / 94 (42 + 52) / 621 / 10 / 12 | 104 / 11.65 -> 104 / 11.57 | 77,594,624 -> 77,594,624 | 270,240 -> 270,247 | `ef2f9defa94b9fdcbc74a5030559d0b41c10c157208a2ad39775d38a4a6f3586` |
| 5,000; 4,500 | 14,609 / 87 (40 + 47) / 14,329 / 90 / 76 | 5,465 / 89 (41 + 48) / 5,182 / 91 / 77 | 104 / 37.65 -> 104 / 36.78 | 123,731,968 -> 125,829,120 | 2,241,234 -> 2,241,241 | `18933530c9b0b2aa4f26898b32eebca856d115aa6c0c5ef8fcf9f0454bd19a08` |

At 5,000 reports, source inventory fell 63.8% (14.33 s to 5.18 s), and route time fell 62.6% (14.61 s to 5.47 s). Query count and SQL time stayed nearly flat, indicating the measured improvement is PHP-side inventory work. At 1,000 reports, inventory fell 62.0% and route time fell 57.1%.

### Development

| Fixture total; returned details | Baseline warm stages | Candidate warm stages | Queries / SQL ms | Peak bytes | Response bytes | `documentTurnaround` SHA-256 |
| --- | --- | --- | --- | ---: | ---: | --- |
| 1,000; 500 | 2,059 / 1,823 (41 + 1,782) / 109 / 64 / 35 | 2,058 / 1,824 (41 + 1,783) / 106 / 64 / 35 | 47 / 13.72 -> 47 / 13.51 | 92,274,688 -> 92,274,688 | 938,242 -> 938,249 | `dc142d24aa7ea51173441e1c1fd6f1c82b0f39f6f8fd983007595b24dc57115f` |
| 5,000; 500 | 2,184 / 1,949 (40 + 1,908) / 106 / 65 / 34 | 2,087 / 1,851 (41 + 1,810) / 107 / 64 / 35 | 47 / 13.45 -> 47 / 13.84 | 117,440,512 -> 119,537,664 | 938,242 -> 938,249 | `dc142d24aa7ea51173441e1c1fd6f1c82b0f39f6f8fd983007595b24dc57115f` |

Development returns the same 500 ENGP details at both fixture totals. Its measured time is dominated by the existing recorded-assessment/scoring stage (about 1.8-1.9 s); inventory and turnaround projection are about 0.1 s and 0.06 s. That path was left unchanged to preserve scoring and assessment behavior. The full-page response sizes differed by seven bytes while the complete TAT prop hash matched; that earlier matrix did not retain a parsed page-prop comparison, so it does not establish page-level business parity.

## Change under test

In the earlier matched matrix, `OfficeTatProcessedDocumentSourceService.php` contains the measured performance optimizations: it checks year-date fallbacks lazily and memoizes the existing computed deadline within one source-inventory call. The focused Conservation follow-up below keeps those two service changes as its baseline and measures an additional accessor optimization.

All four baseline/candidate route pairs returned the same complete `documentTurnaround` hash, report count, detail count, and filters. No calendar, TAT formula, ranking, assessment score, permission, or routing logic was changed.

## Focused verification

- Baseline controller profile: 1 test, 60 assertions, passed.
- Candidate controller profile: 1 test, 60 assertions, passed.
- Turnaround, processed-source, and business-calendar suites: 18 tests, 209 assertions, passed.
- Cross-unit Office TAT access regression: 1 test, 31 assertions, passed.
- Office TAT route roster regression: 1 test, 37 assertions, passed.
- PHP lint and `git diff --check`: passed after temporary timing hooks were removed.

This is a synthetic SQLite in-process profile with one warm observation per case. It does not establish production throughput, MySQL behavior, concurrency, browser latency, or live-office data distribution. The Development assessment stage remains a separate measured cost and would need its own scope if considered for future optimization.

## Candidate paths

The original matrix candidate changed these tracked application paths from the checkpoint:

- `app/Services/OfficeTatDocumentTurnaroundService.php`
- `app/Services/OfficeTatProcessedDocumentSourceService.php`

The follow-up candidate retains those two paths and adds `app/Models/ConservationReportSubmission.php`. The original matrix fixture is `tests/Feature/IsolatedOfficeTatControllerProfileTest.php`; the focused follow-up fixture is `tests/Feature/IsolatedOfficeTatConservationHotspotTest.php`. Source and artifact hashes are in `SHA256SUMS.txt` in the permanent archive.

## Follow-up: focused Conservation source-inventory hot spot

This comparison starts from the tested two-service-file candidate above and changes only `ConservationReportSubmission.php`. It profiles the real Conservation controller route once to warm, then records five warm requests before and five after. Both sides use the same isolated SQLite `:memory:` fixtures, authorized PENRO CDS Chief, `CENRO` filter, Conservation unit, period `2026`, cutoff `2026-10-27`, PHP 8.3.30, and 512 MiB limit. Each side has 5,000 seeded records: 4,493 generic Conservation, seven native PA, and 500 Development ENGP. The Conservation route returns 4,500 matching document details on every request.

The warm medians below are over five requests. Values in brackets show the five-request range. The timings include the same temporary profiling hooks on both sides; nested operation timers overlap and must not be added together.

| Measure | Before | After |
| --- | ---: | ---: |
| Route wall time (ms) | 6,143.19 [6,019.41–6,309.07] | 5,529.46 [5,496.23–5,634.10] |
| Assessment (ms) | 89.22 [88.85–91.99] | 103.66 [89.23–123.12] |
| Source inventory (ms) | 5,832.60 [5,738.09–6,000.06] | 5,219.16 [5,160.83–5,324.59] |
| Turnaround projection (ms) | 85.03 [83.76–104.82] | 98.14 [85.92–132.16] |
| Response serialization (ms) | 79.04 [77.44–87.73] | 90.47 [81.52–102.25] |
| Query count | 104 | 104 |
| SQL time (ms) | 37.22 | 37.19 |
| PHP allocated peak bytes | 130,023,424 | 130,023,424 |
| Full response body bytes | 2,241,233 | 2,241,234 |
| Returned details | 4,500 | 4,500 |
| Complete `documentTurnaround` SHA-256 | `18933530c9b0b2aa4f26898b32eebca856d115aa6c0c5ef8fcf9f0454bd19a08` | `18933530c9b0b2aa4f26898b32eebca856d115aa6c0c5ef8fcf9f0454bd19a08` |

Source inventory median improved by 10.5%; route median improved by 10.0%. The complete `documentTurnaround` tree hash and returned detail count match across all five warm requests on both sides. The full Inertia response body differs by one byte between runs, outside the hashed `documentTurnaround` tree.

### Identified work and narrow change

Before changing source, subcall profiling showed `getDeadlineSubmissionAttribute()` resolving the same generic module definition that `deadlineRule()` immediately resolves again. For 4,493 eligible generic Conservation records, `ModuleMetadataResolver::genericDefinition()` ran 8,986 times per warm request and consumed a 1,490.29 ms median. `ConservationReport.deadline_rule` took 2,268.04 ms inclusive. The source service's deadline projection took 5,054.56 ms inclusive over 4,500 calls. Cache-key serialization was only about 27 ms; the calendar was not the measured optimization target.

The accessor now passes its already-resolved module definition into the existing deadline-rule calculation. The public `deadlineRule()` method still resolves and applies the same module/rule policy for its other callers. After the change, generic-definition calls fell to 4,493 per warm request (one per generic record), with a 771.99 ms median. Inclusive deadline-rule time fell to 1,573.15 ms and `deadlineForRecord` time to 4,406.28 ms.

Calendar calls stayed the same: `PaMonitoringDeadlineService::deadline()` ran 4,493 times (1,680.04 ms before; 1,699.26 ms after); `addConservationWorkingDays()` ran 4,499 times (1,345.90 ms; 1,361.11 ms); and `isWorkingDay()` ran 58,529 times (470.15 ms; 473.38 ms). The calls are unchanged, so this patch does not alter calendar weekdays, holidays, or date boundaries. Activity-specific deadline-rule evaluation, eligibility, totals, report details, routing, permissions, Development assessment/scoring, and the two baseline service fixes are preserved.

### Follow-up verification and limits

- Focused hotspot route profile: 1 test, 51 assertions, passed on each side; every route sample returned 4,500 details and the same complete `documentTurnaround` hash.
- Processed-source, document-turnaround, and business-calendar regressions: 18 tests, 209 assertions, passed against the final candidate bytes.
- PHP lint and `git diff --check`: passed after temporary profiling hooks were removed.
- This is a synthetic, instrumented SQLite profile at 5,000 reports. It does not establish production database behavior, concurrency, browser latency, or live office data distribution. No Development route or broad capacity matrix was rerun for this focused change.

The final isolated candidate changes exactly these three tracked application paths from checkpoint `ae41dc28ff74badb55b4a03cc5eacf31b014a232`:

- `app/Models/ConservationReportSubmission.php`
- `app/Services/OfficeTatDocumentTurnaroundService.php`
- `app/Services/OfficeTatProcessedDocumentSourceService.php`

## Checkpoint review follow-up (2026-10-28)

### Controlled Inertia response comparison

No before/after response captures were retained in the archive. A single controlled comparison was run in disposable lab copies based on checkpoint `ae41dc28ff74badb55b4a03cc5eacf31b014a232`. Both sides had the two tested service changes; only the final `ConservationReportSubmission` accessor optimization differed. Each side used the same SQLite `:memory:` schema and fixture, fixed test clock (`2026-10-28 12:00 Asia/Manila`), authorized synthetic PENRO CDS Chief, CENRO Conservation filter, period `2026`, cutoff `2026-10-27`, application settings, and Inertia version context. Each route response was JSON with `X-Inertia: true`; each side returned 4,500 Conservation details from the 5,000-row mixed fixture.

The recursively compared page included its component, URL, version, and all props. Sensitive keys and the synthetic account email were removed before page comparison. The full page comparison found no differing prop paths. The exact body lengths and hashes also matched:

| Measure | Before | After |
| --- | ---: | ---: |
| Test result | 1 test, 18 assertions, passed | 1 test, 18 assertions, passed |
| Full response bytes | 2,241,246 | 2,241,246 |
| Full response SHA-256 | `f7abddcff651b0151b7906ec03063b369a7184afbe6b01e1b6ab45b5f6ff9d8a` | `f7abddcff651b0151b7906ec03063b369a7184afbe6b01e1b6ab45b5f6ff9d8a` |
| Sanitized Inertia page SHA-256 | `0d0370f72cff53110da12fa01be993808698c95a5609b5ed18d0132b7f9bef5d` | `0d0370f72cff53110da12fa01be993808698c95a5609b5ed18d0132b7f9bef5d` |
| `documentTurnaround` SHA-256 | `18933530c9b0b2aa4f26898b32eebca856d115aa6c0c5ef8fcf9f0454bd19a08` | `18933530c9b0b2aa4f26898b32eebca856d115aa6c0c5ef8fcf9f0454bd19a08` |
| Differing page prop paths | none | none |

The earlier recorded body lengths, 2,241,233 and 2,241,234, establish a one-byte size delta and necessarily unequal byte strings. The original bodies and hashes were not retained, so the specific byte change and any corresponding page field cannot be identified. Do not infer that the earlier difference was only serialization metadata. The controlled comparison above did not reproduce it.

### Deadline memoization correctness review

The initial per-inventory deadline key included the model, workflow/activity/document, date fields, and target office, but omitted the raw stored `deadline_submission`. ENGP and IPAF revenue sources can carry that field directly. A processed-source regression with two authorized Development/ENGP details in quarter 3 and quarter 4 reproduced the omitted-input case: both rows shared all previous key inputs but had deadlines `2026-09-15` and `2026-09-30`. The added detail deadline field in the source key prevents the cached value from crossing rows. The final key covers the inputs used by the current computed deadlines (model/workflow, activity/document, reference dates, and target office) plus raw stored `deadline_submission`. The cache resets at the start of every `forAssessment` call; participant details and source identities are assembled independently per row.

The source service continues to authorize `office-tat.view`, check the requested unit against the actor's effective units, build its participant roster from the assessment, and scope Development source queries through the existing organizational access service. The roster fallback, lazy year lookup, and computed deadline memoization remain as reviewed. The Conservation model change still reuses the already-resolved module definition and preserves the public `deadlineRule()` behavior. No calendar, TAT calculation, rank, assessment/scoring, permission, or routing code changed.

The Conservation page comparison ran before the stored-deadline key addition. That fixture's Conservation and native PA deadline inputs were computed or null rather than distinct stored values; the key extension does not change that fixture's cache equivalence. The final source bytes were separately covered by the focused Development regression below; no second controller comparison or capacity matrix was run.

### New focused verification

- Before the cache-key correction, the new stored-deadline case failed (1 focused test, 6 assertions).
- After the correction, `OfficeTatProcessedDocumentSourceTest.php` passed: 2 tests, 16 assertions. It covers processed-only generic Conservation, native PA, and ENGP detail identities, plus distinct stored ENGP deadlines.
- PHP lint passed for `OfficeTatProcessedDocumentSourceService.php` and `OfficeTatProcessedDocumentSourceTest.php`.
- `git diff --check` passed. Final service/test file hashes matched the files tested in the disposable candidate copy.
- The retained 18-test/209-assertion calendar, turnaround, and processed-source result was not rerun; no broad suite or capacity profile was repeated.

The matching route pair compares the model accessor change under the controlled Conservation fixture. It does not certify MySQL behavior, concurrency, browser timing, real Drive behavior, production data distribution, or rollout readiness. The large-cohort route latency remains open.

### Current candidate scope

The exact selected source, test, and documentation paths are listed in `cds-smart-tat-performance-checkpoint-review-2026-10-28.md`. `SHA256SUMS.txt` contains hashes for the selected files other than the manifest itself. Disposable response copies, runtime dependencies, caches, logs, environment files, generated assets, and credentials are outside the selected scope.