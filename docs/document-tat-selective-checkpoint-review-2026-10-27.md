# Document TAT selective checkpoint review

Date: 2026-10-27

## Review boundary

This is a sanitized, selective handoff for the completed Document TAT work. Review was read-only in the serving checkout. Its staged index was empty and remains untouched. No commit, push, cleanup, encoding conversion, routing change, test run, or build was performed during this review. Verification below is carried forward from the completed implementation run.

## Processing rule

- The assessment supplies the authorized participant roster. `OfficeTatProcessedDocumentSourceService` queries generic Conservation, all registered native PA adapters, and ENGP records for those participants and the selected period, returning only rows with supported processing evidence. The projection checks the existing `office-tat.view` permission and authorized unit; it does not create obligations or widen the participant roster.
- Conservation timing starts at the earliest supported successful forward from the PAMO origin. Development timing starts at the supported ENGP handoff from preparation to the CENRO Chief transit stage. The endpoint is the first supported PENRO Records receipt; native PA routing receipt evidence is also recognized.
- Returned, corrected, or re-forwarded reports retain the first valid start. Imported state-only milestones are not treated as proof of a successful submission action.
- Elapsed time uses the existing `BusinessCalendarService::workingDaysBetween` rule with `after_through` boundaries and the shared PAMB Monday-to-Thursday weekdays. National holidays and the source record's office-scoped holidays are excluded. The source record's office is used when present, falling back to the participant office binding.
- Ongoing reports run from first supported start through the selected Manila cutoff date at end of day. Only completed reports with valid start and receipt timestamps contribute to the average. Date-level working days are counted, so a same-day receipt is zero days; this is not an hours-based SLA measure.
- Existing report counts, compliance calculations, rankings, and source data are not changed by the new projection service itself. Legacy received dates can support receipt status but cannot supply an elapsed-time sample without route timestamps.

## Selective checkpoint candidates

### Document TAT implementation

- `app/Services/OfficeTatDocumentTurnaroundService.php` - new read-only projection and access/source filtering.
- `app/Services/OfficeTatProcessedDocumentSourceService.php` - period-bounded processed-source sidecar for generic Conservation, all registered native PA sources, and ENGP; source inventory remains constrained by assessment participants and existing access checks.
- `resources/js/Pages/Admin/OfficeTat/DocumentTurnaround.jsx` - new summary, participant table, and report-details modal.
- `resources/js/Pages/Admin/OfficeTat/documentTurnaroundPresentation.mjs` - working-day and display formatting.
- `resources/js/Pages/Admin/OfficeTat/reportPresentation.mjs` - shared report/workflow labels imported by the new component.
- `app/Http/Controllers/OfficeTatController.php` - only the `index` data construction and `documentTurnaround` prop are directly needed. The new PAMO preparation and live routing handlers in this same file are separate work; do not include the whole file without hunk selection and review.
- `resources/js/Pages/Admin/OfficeTat/Index.jsx` - the import, prop, and DocumentTurnaround rendering are integration points. The file also replaces/restructures other Office TAT UI, so treat the complete diff as mixed scope and do not include it wholesale under this checkpoint.
- `app/Services/OfficeTatComplianceAssessmentService.php` - only source-identity fields consumed by the projection, including source key/ID and calendar office, are relevant. This file also has extensive unrelated assessment, scoring, evidence, and ranking edits; do not include its full diff in the Document TAT checkpoint.

### Tests to retain, with hunk selection where noted

- `tests/Feature/OfficeTatDocumentTurnaroundTest.php` - new service tests for route starts, receipt evidence, corrections, cutoff behavior, calendar holidays, office scope, unit authorization, and registered source adapters.
- `tests/Feature/OfficeTatProcessedDocumentSourceTest.php` - processed/unprocessed coverage for generic Conservation, all seven registered native PA adapters, and ENGP.
- `tests/js/officeTatDocumentTurnaround.test.mjs` - new presentation and component-contract checks, including clickable participant rows and the details modal.
- `tests/js/officeTatClickableRows.test.mjs` - nested-control suppression, keyboard row activation, and opt-in behavior.
- `tests/Feature/OfficeTatComplianceAssessmentTest.php` - retain only assertions that verify source identities and calendar-office metadata needed by Document TAT. The file has a large body of separate assessment tests and must not be included wholesale.
- `tests/js/officeTatAutomaticDashboard.test.mjs` and `tests/js/officeTatUnitPresentation.test.mjs` - retain only assertions that verify page integration and passing the Document TAT data prop; both are mixed with other UI-contract changes.
- `tests/js/officeTatProcessedReports.test.mjs` - supporting shared-row and modal focus regression checks. It also covers the separate processed-report details view, so keep it separate from the Document TAT feature tests.

### Shared UI and build support

- `resources/js/Components/Crud/CrudTable.jsx` - `focusRowOnClick` is opt-in and defaults to false. Tables without a row handler keep their existing behavior; the shared row-activation helper suppresses nested controls from invoking the row action. This is the shared participant-row behavior used by Document TAT.
- The candidate uses the existing modal close and focus-return behavior in the base `CrudDetailsModal`/`useModalMotion` implementation; the optional new focus-return helper is not included.
- `resources/js/Components/ModalMotion/modalLayer.mjs` - supports the details disclosure control inside the modal.
- `resources/js/Components/Crud/CrudDetailsModal.jsx` and `resources/js/Utils/fixedBottomOverlayInset.mjs` are unchanged; the optional debug-toolbar sizing support is not needed by the candidate details modal.
- `scripts/build-vite-guarded.mjs` - separate build-tool support for a Windows active-directory rename failure; it is not runtime Document TAT logic. The fallback runs only after the staging build passes the verifier, checks any same-name active assets byte-for-byte, copies absent assets exclusively and verifies them, then switches the manifest. A failure before manifest replacement leaves the old manifest active; unreferenced copied assets may remain. No guard bypass was found.

The new `resources/js/Pages/Admin/OfficeTat/participantRowActivation.mjs` and its `officeTatScope` assertions serve the separate Compliance First Assessments table; DocumentTurnaround uses CrudTable directly, so exclude those from this feature checkpoint.

## Candidate-only validation

The following checks were run against the isolated candidate source, not carried forward from the serving checkout:

- Focused PHP command: `php artisan test --compact tests/Feature/OfficeTatDocumentTurnaroundTest.php tests/Feature/OfficeTatProcessedDocumentSourceTest.php tests/Feature/BusinessCalendarServiceTest.php` passed 18/18 tests with 209 assertions. The database isolation guard and `TestCase::setUpTraits` verified SQLite, PDO SQLite, `:memory:`, and an empty main database filename. The new inventory test returned only processed keys for generic Conservation, every registered native PA adapter, and ENGP.
- One filtered controller route test was attempted with a process-only test key, then stopped during Blade rendering because the candidate has no local `public/build/manifest.json`. Its roster assertions were not reached.
- PHP lint passed for the candidate controller, assessment service, both Document TAT services, and both focused PHP tests.
- Affected JS command: `node --test tests/js/officeTatDocumentTurnaround.test.mjs tests/js/officeTatClickableRows.test.mjs tests/js/modalLayer.test.mjs` passed 9/9.
- `git diff --check` against base SHA `3c1d4e4cfb9774b39b493d6cb499fcef161598bf` found no whitespace errors in selected tracked files; all selected added paths were present and had no trailing whitespace.
- One isolated Vite 8.1.4 build from the candidate transformed 1,503 modules and emitted a manifest containing DocumentTurnaround. Output was written under `%TEMP%` and was not promoted to either the candidate `public/build` or the serving checkout. The guarded promotion path was therefore not exercised by this candidate validation.
- Composer and npm lockfiles matched the workspace lockfiles byte-for-byte. The fresh Composer download could not write its archive/cache files in this environment, so the matching installed dependency tree was copied into candidate `vendor/` and candidate autoload metadata was generated. Candidate `node_modules` is a junction to the matching workspace install. These runtime dependencies and candidate framework package/service cache files are outside the selected source paths; no config cache exists. No cleanup was run.
- The first PHP run found a missing strict assessment-date parser; the next exposed numeric quarter handling. Both issues were fixed in the candidate and the final focused PHP set passed.

## Retained serving-checkout evidence (historical)

The results below are from the completed serving implementation run and are historical; they do not establish that the isolated candidate is deployed or live-tested.

These results are from the completed implementation run and were not repeated here:

- Focused PHP checks: 17/17 tests, 199 assertions, including `OfficeTatDocumentTurnaroundTest.php` and `BusinessCalendarServiceTest.php`.
- PHP lint passed for the two Document TAT services and the new feature test.
- Frontend checks: 8/8 across Document TAT and processed-report checks before the average precision adjustment; the affected Document TAT check then passed 2/2. The focused row-activation regression passed 1/1.
- Guarded Vite verification passed for the staged build. Windows denied the active build-directory rename, so the verified-asset fallback promoted assets and atomically switched the manifest. The serving manifest and relevant page entrypoints returned HTTP 200. No generated asset names are included in this handoff.
- Authenticated live read-only check at the 2026-10-27 cutoff: Conservation showed 30 processed reports, 30 timestamp-supported receipts, and a 0.03 working-day average; Development showed 12 processed reports, 12 timestamp-supported receipts, and a 0 working-day average. Clicking a participant row opened the matching details modal; Escape closed it and returned focus to the row. At a 390 px viewport, the page had no horizontal overflow.

## Limitations and exclusions

- Live verification was a spot check of the current authenticated dataset and cutoff, not a broad production audit. No operational action was taken.
- Reports without supported route timestamps remain visible in report/status counts but are excluded from the completed-duration average.
- `OfficeTatController.php`, `OfficeTatComplianceAssessmentService.php`, and `OfficeTat/Index.jsx` are mixed-scope files in the serving worktree. The isolated candidate contains only the selected hunks described in its scope manifest.
- Candidate route rendering has not passed because its compiled manifest was intentionally kept in `%TEMP%`; the direct isolated build verifies imports but does not exercise the serving manifest or browser page.
- The filtered controller route test did not reach its roster assertions because the candidate has no local build manifest. No browser/live check or guarded promotion was performed against the candidate.
- Separate analytics/ranking work, new average pages and routes, other module changes, private files, logs, cached configuration, generated assets, and output artifacts are excluded.
