# Isolated Office TAT performance candidate

Base Git SHA: `ae41dc28ff74badb55b4a03cc5eacf31b014a232`.

This uncommitted, isolated candidate contains a narrow source-inventory performance change and a correctness correction to its per-call deadline memoization. Its changed application paths are:

- `app/Models/ConservationReportSubmission.php`
- `app/Services/OfficeTatDocumentTurnaroundService.php`
- `app/Services/OfficeTatProcessedDocumentSourceService.php`

Its focused source regression is `tests/Feature/OfficeTatProcessedDocumentSourceTest.php`. The regression confirms that distinct stored ENGP deadlines remain attached to their own report details. The existing controller profile fixtures, capacity harness, baseline source snapshot, and profiling hook snapshots are retained for review and reproducibility. The full report and exact selected path list are in `docs/office-tat-controller-profile-2026-10-28.md` and `docs/cds-smart-tat-performance-checkpoint-review-2026-10-28.md`.

The focused Inertia comparison found identical full response bytes, sanitized page props, and `documentTurnaround` hashes across the Conservation model comparison. Its older one-byte response-size difference was not reproduced; the old captures were unavailable, so its exact byte and prop-level cause remain unknown. The Development source fix was tested separately with the SQLite `:memory:` PDO guard.

The selected candidate scope excludes `.env`, configuration cache, runtime logs, generated assets, Composer runtime dependencies/cache, disposable response copies, private files, and credentials. The archived Inertia `Response.php` under `docs/controller-profile-hook-snapshots/` is a text-only profiling reference, not an installed dependency. The lab has its own Git root at the stated base SHA and its index is clean. All changes remain local and uncommitted; nothing was pushed or deployed.

The retained measurements are synthetic SQLite in-process results. They do not certify MySQL concurrency, browser performance, real Drive behavior, production data distribution, or rollout readiness. The large-cohort route latency remains open.