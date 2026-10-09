# Date-format contract verification — 2026-10-09

**Scope status: PASS** for the date-format contract correction. The full frontend inventory remains **PARTIAL** because of an unrelated Routing Workflow test failure described below. No application formatter or date values were changed, so this does not establish browser visual acceptance or a system-wide pass.

## Diagnosis and intended display contract

Before editing, `node tests/js/dateFormatters.test.mjs` failed at the assertion for `2026-08-29`: the test expected `Aug 29, 2026`, while `formatReportDate` returned `August 29, 2026`.

The current formatter explicitly uses `Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' })`. Git history establishes the order of the contract and the stale assertion:

- Commit `353e099b` added the test on 2026-08-30 with the abbreviated expectation.
- Commit `29ce0df1` changed the shared report-date formatter on 2026-08-31 from `month: 'short', day: '2-digit'` to `month: 'long', day: 'numeric'`.
- The existing review note in `docs/routing-correction-and-role-verification-2026-10-05.md` describes the long-month formatter as intentional and flags the test as drift.

`formatReportDate` is used by shared report, dashboard, calendar event, and Submission Tracking labels. It has a fallback argument but no month-style option. Compact date-picker/range labels use the separate `formatDisplayDate` helper (`Aug 29, 2026`), calendar headings use a long month, and native date inputs use local `YYYY-MM-DD` values. Those are distinct display/input contracts and were left intact. `formatReportDateTime` separately uses a long month and `Asia/Manila` timezone.

## Correction

Updated only the stale assertion in `tests/js/dateFormatters.test.mjs`:

- Before: expected `Aug 29, 2026`.
- After: expected `August 29, 2026`.

The formatter, parsing rules, fallback behavior, timezone handling, date inputs, stored fields, and routing timestamps were not modified.

## Verification

- Pre-edit reproduction: `node tests/js/dateFormatters.test.mjs` failed with expected `Aug 29, 2026`, actual `August 29, 2026`.
- After correction: the focused formatter test passed.
- Sequential frontend fallback: 23 of 24 files under `tests/js` and `tests/frontend` passed, including `dateFormatters.test.mjs` and `reportTypeSelection.test.mjs`.
- The one unrelated failure was `tests/js/routingPositionControls.test.mjs`, test “RoutingWorkflow page keeps the acknowledged version across consecutive successful saves and preserves edits on validation errors”: 3 of 4 cases passed; the remaining case threw `TypeError: Cannot read properties of undefined (reading 'toString')` from Inertia `hrefToUrl` while resolving the test's PUT URL.
- The aggregate `node --test --test-concurrency=1 tests/js/*.test.mjs tests/frontend/*.test.mjs` runner could not spawn its file workers (`spawn EPERM`). The sequential fallback ran the test files directly.
- No production build was run because application source was unchanged. No live database, report, routing setting, `.env`, or recovery data was changed.

## Changed files

- `tests/js/dateFormatters.test.mjs` — corrected the expected shared report-date label.
- `docs/date-format-contract-verification-2026-10-09.md` — this result record.
