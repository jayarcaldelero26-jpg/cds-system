# CDS-SMART Office TAT performance checkpoint review

Base SHA: `ae41dc28ff74badb55b4a03cc5eacf31b014a232`
Isolated Git root: `C:\Users\jayar\development\cds-smart-office-tat-controller-profile-2026-10-28`

## Full Inertia response comparison

There were no saved response-body captures in the archive, so one paired controller comparison was run in disposable copies. Both sides used SQLite `:memory:`, the same synthetic 5,000-report fixture (4,493 generic Conservation, seven native PA, 500 ENGP), an authorized synthetic PENRO CDS Chief, CENRO/Conservation, period `2026`, cutoff `2026-10-27`, fixed Asia/Manila clock and application/serialization settings. The before and after copies had identical service code and differed only in the final `ConservationReportSubmission` accessor optimization. Each rendered the `Admin/OfficeTat/Index` Inertia JSON response and returned 4,500 Conservation details.

The complete parsed page envelope (component, URL, version, and recursively scrubbed props) had no differing paths. Assessment/count, source identity, date, score/rank, TAT, and permission/scope values were compared wherever present. Sensitive keys and the synthetic account email were removed from the page-prop comparison. Both pair tests passed with 1 test and 18 assertions.

| Measure | Before | After |
| --- | ---: | ---: |
| Response bytes | 2,241,246 | 2,241,246 |
| Full body SHA-256 | `f7abddcff651b0151b7906ec03063b369a7184afbe6b01e1b6ab45b5f6ff9d8a` | `f7abddcff651b0151b7906ec03063b369a7184afbe6b01e1b6ab45b5f6ff9d8a` |
| Sanitized page SHA-256 | `0d0370f72cff53110da12fa01be993808698c95a5609b5ed18d0132b7f9bef5d` | `0d0370f72cff53110da12fa01be993808698c95a5609b5ed18d0132b7f9bef5d` |
| `documentTurnaround` SHA-256 | `18933530c9b0b2aa4f26898b32eebca856d115aa6c0c5ef8fcf9f0454bd19a08` | `18933530c9b0b2aa4f26898b32eebca856d115aa6c0c5ef8fcf9f0454bd19a08` |
| Differing page-prop paths | none | none |

The previous profile's 2,241,233 versus 2,241,234 byte lengths prove a one-byte size delta and unequal body bytes. The old body captures and hashes are unavailable, so the precise byte-level change and any associated business field cannot be identified. That earlier difference was not reproduced in the fixed-context pair. The pair ran before the separate stored-deadline cache-key correction; the Conservation fixture contained no distinct raw stored deadlines, and the correction's affected Development behavior was checked separately.

## Diff and cache-key review

The three application diffs against the stated base contain the top-level assessment-roster fallback in both TAT services, lazy source-year fallback, per-inventory-call deadline memoization, and reuse of the resolved generic module definition in the Conservation accessor. Permission checks and Development roster scoping remain in place. Calendar weekdays/holidays, `after_through`, TAT computations, routing, scoring, and ranking are not changed.

The review found that the memoization key omitted raw stored `deadline_submission`. A focused Development fixture with two processed ENGP reports for one authorized participant, quarter 3 and quarter 4, used identical prior key inputs but stored deadlines `2026-09-15` and `2026-09-30`. The regression failed before the key correction. Adding the raw stored deadline to the key prevents the first row's cached value from being reused for the second. The final key covers the inputs used by the current computed deadlines (model/workflow, activity/document, reference dates, and target office) and the direct stored deadline. The cache resets on every assessment call; participant details and source identities are assembled independently for each row, while a shared cached scalar is reused only when those deadline inputs match.

The affected processed-source feature file passed after the fix: 2 tests, 16 assertions. It covers processed-only generic Conservation, all registered native PA sources, ENGP eligibility, source identity, and distinct stored ENGP deadlines. PHP lint for the changed service and test passed, as did `git diff --check`. The previous 18-test/209-assertion calendar/turnaround/source result is retained, not newly rerun.

## Exact selected paths

These are the exact 18 paths proposed for the selective checkpoint. The SHA manifest contains hashes for all listed content paths other than itself.

- `app/Models/ConservationReportSubmission.php`
- `app/Services/OfficeTatDocumentTurnaroundService.php`
- `app/Services/OfficeTatProcessedDocumentSourceService.php`
- `tests/Feature/OfficeTatProcessedDocumentSourceTest.php`
- `tests/Feature/IsolatedCapacityProfileTest.php`
- `tests/Feature/IsolatedOfficeTatConservationHotspotTest.php`
- `tests/Feature/IsolatedOfficeTatControllerProfileTest.php`
- `docs/OfficeTatProcessedDocumentSourceService.baseline.php`
- `docs/controller-profile-hook-snapshots/app/Http/Controllers/OfficeTatController.php`
- `docs/controller-profile-hook-snapshots/app/Services/OfficeTatDocumentTurnaroundService.php`
- `docs/controller-profile-hook-snapshots/README.md`
- `docs/controller-profile-hook-snapshots/vendor/inertiajs/inertia-laravel/src/Response.php`
- `docs/isolated-capacity-check-2026-10-27.md`
- `docs/isolated-capacity-harness-2026-10-27.md`
- `docs/office-tat-controller-profile-2026-10-28.md`
- `docs/cds-smart-tat-performance-checkpoint-review-2026-10-28.md`
- `README-profile-candidate.md`
- `SHA256SUMS.txt`

The isolated Git index remains clean at the base SHA. The selected scope excludes `.env`, configuration cache, runtime logs, generated assets, Composer runtime dependencies/cache, disposable pair copies, private files, and credentials. The `docs/controller-profile-hook-snapshots/vendor/inertiajs/inertia-laravel/src/Response.php` entry is a documentation-only source snapshot used to explain response profiling; no runtime `vendor/` tree is selected. The serving checkout and serving index were not changed during this review. No build, promotion, commit, push, deployment, operational data change, or cleanup was performed.

## Remaining limits

The controller profile is synthetic and SQLite-only. It does not certify MySQL behavior or concurrency, browser latency, real Drive behavior, production data distribution, or rollout readiness. The 5,000-row Conservation route median near 5.53 seconds remains an open performance finding. No Development scoring/assessment optimization was attempted.

**Ready for selective checkpoint**