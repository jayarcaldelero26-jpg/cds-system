# Isolated Document TAT source candidate

Base source SHA: `3c1d4e4cfb9774b39b493d6cb499fcef161598bf`.

This candidate is a source snapshot at that base plus the selected Document TAT changes below. It has no Git metadata and is not a commit. Candidate validation did not write to the serving checkout or Git index.

## Selected paths

New source and UI files:

- `app/Services/OfficeTatDocumentTurnaroundService.php`
- `app/Services/OfficeTatProcessedDocumentSourceService.php` - read-only, period-bounded inventory of processed generic Conservation, registered native PA, and ENGP source records, limited to participant rows already present in the authorized assessment.
- `resources/js/Pages/Admin/OfficeTat/DocumentTurnaround.jsx`
- `resources/js/Pages/Admin/OfficeTat/documentTurnaroundPresentation.mjs`
- `resources/js/Pages/Admin/OfficeTat/reportPresentation.mjs`

Selected integration hunks:

- `app/Http/Controllers/OfficeTatController.php` - inject the projection, reuse the existing recorded assessment, and pass its projection as a page prop.
- `app/Services/OfficeTatComplianceAssessmentService.php` - add source key/ID, source IDs, office calendar scope, and source identity metadata to the existing recorded-detail output. The scoring, ranking, assessment roster, route definitions, and source queries are not replaced.
- `resources/js/Pages/Admin/OfficeTat/Index.jsx` - add the Document TAT import/prop and render its panel alongside the existing Report Performance content. Existing Average TAT and report-performance code is retained.
- `resources/js/Components/Crud/CrudTable.jsx` - add opt-in `focusRowOnClick`; default behavior remains unchanged for consumers that do not opt in.
- `resources/js/Components/ModalMotion/modalLayer.mjs` - let the existing modal handle Escape for the explicitly marked in-modal disclosure button.
- `scripts/build-vite-guarded.mjs` - retain the reviewed verified-asset fallback for Windows active-directory rename failures.

Tests and handoff:

- `tests/Feature/OfficeTatDocumentTurnaroundTest.php`
- `tests/Feature/OfficeTatProcessedDocumentSourceTest.php` - processed/unprocessed coverage for generic Conservation, all registered native PA adapters, and ENGP.
- `tests/js/officeTatDocumentTurnaround.test.mjs`
- `tests/js/officeTatClickableRows.test.mjs`
- `tests/js/modalLayer.test.mjs` - one added disclosure/Escape regression case.
- `docs/document-tat-selective-checkpoint-review-2026-10-27.md`
- `docs/document-tat-candidate-scope-2026-10-27.md` - this manifest.

## Explicit exclusions

The selected source changes contain no `.env`, config cache, private storage files, generated build assets, output artifacts, or private documents. The base `docs/` directory was excluded; it now contains only these two sanitized candidate/review documents. The base snapshot retains unrelated tracked support files outside the selected path list; they are unchanged. The tracked `bootstrap/cache/.gitignore` housekeeping file was excluded with that cache directory; it is not a feature dependency. The optional debug-toolbar modal sizing helper is excluded; the candidate modal uses the existing CrudDetailsModal sizing and focus-return behavior. No route definitions, database schema, persisted data, accounts, config source, or encoding were changed.

## Candidate validation record

The source inventory sidecar is necessary to cover real generic Conservation, all registered native PA, and ENGP records while excluding rows without supported processing evidence. It uses the participant roster from the existing assessment and does not modify score, rank, calendar, routing, or permission code. The focused PHP regression creates one processed and one unprocessed row per source family/adapter and checks only processed keys are returned.

Candidate-only results:

- PHP: `php artisan test --compact tests/Feature/OfficeTatDocumentTurnaroundTest.php tests/Feature/OfficeTatProcessedDocumentSourceTest.php tests/Feature/BusinessCalendarServiceTest.php` passed, 18/18 tests and 209 assertions. The test harness verified `sqlite`, PDO SQLite, `DB_DATABASE=:memory:`, and an empty main database filename before database traits ran. It covered the feature service, working calendar, and processed/unprocessed records for generic Conservation, every registered native PA adapter, and ENGP.
- PHP lint: passed for `OfficeTatController.php`, `OfficeTatComplianceAssessmentService.php`, both Document TAT services, and both focused feature tests.
- JS: `node --test tests/js/officeTatDocumentTurnaround.test.mjs tests/js/officeTatClickableRows.test.mjs tests/js/modalLayer.test.mjs` passed, 9/9.
- Diff checks: `git diff --check` against the base SHA reported no whitespace errors for selected tracked files; the 11 added source/test/handoff files were checked for trailing whitespace and all selected paths were present.
- Build: one direct Vite 8.1.4 production build from the candidate transformed 1,503 modules and emitted a manifest containing DocumentTurnaround. Output went to `%TEMP%\cds-document-tat-candidate-build-906dd3fce81b462a93b951eff534868f`; `public/build` was not created and nothing was promoted to the serving checkout.
- The first focused PHP run exposed a missing assessment-date parser; a second exposed numeric quarters being interpreted as months. Both candidate-side defects were fixed, then the final focused PHP set passed. No scoring, ranking, calendar, route definition, or permission logic was changed.

Dependency setup: candidate and workspace `composer.lock` files were byte-identical (SHA-256 `DC6211C40E934FAF6E45A02D550F454CA31F7E8089D6DFC10DB99D2FD3155560`). A fresh Composer install could not write its cache/archive files in this environment. The matching installed dependency tree was copied into candidate `vendor/`, and candidate autoload metadata was generated with `composer dump-autoload --no-interaction --no-scripts --no-plugins -o`. Candidate and workspace `package-lock.json` were byte-identical (SHA-256 `A3ACA07F81E5BD4AA07185A969319A808C6037E24628F03F829CD42CC3747F46`); candidate `node_modules` is a junction to the matching workspace install. These are validation dependencies, not selected source paths. Test bootstrap generated candidate framework package/service cache files; no `bootstrap/cache/config.php` exists. No manual candidate or workspace cleanup was performed.

An additional filtered controller route test was attempted with a process-only test key, but it stopped while rendering the Blade shell because there is no candidate-local `public/build/manifest.json`. The isolated build stayed in `%TEMP%`; this route test did not reach its roster assertions. Serving-checkout tests/build/live results in the companion handoff remain historical and are not evidence that this candidate passed.

The candidate uses base commit `3c1d4e4cfb9774b39b493d6cb499fcef161598bf`; source changes from the mutable worktree were not included in the candidate.
