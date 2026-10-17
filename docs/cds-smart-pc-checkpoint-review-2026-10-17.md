# CDS-SMART selective checkpoint review — 2026-10-17

## Review status

This is a selective checkpoint review. The live checkout was inspected read-only. No paths were staged, committed, reset, cleaned, or rewritten. The only live-checkout changes made for this review are this new, untracked review file and the updated resume checkpoint; the disposable source-only candidate is created separately under the system temp directory. Git metadata does not identify who authored the pre-existing dirty paths, so the proposed groups are candidates for owner review rather than an assertion of ownership.

## Current Git inventory

| Item | Observed state |
|---|---|
| Branch | `checkpoint/cds-smart-archives-2026-10-15` |
| HEAD | `c418d2d3f11afb50f17e5983bc53e5758c733e99` |
| Upstream | `origin/checkpoint/cds-smart-archives-2026-10-15`; local refs show 0 ahead / 0 behind. No fetch or remote check was made. |
| Worktree before this report | 98 modified tracked paths; 96 untracked paths; 0 staged paths; 0 tracked deletions. |
| Recovery files | 11 untracked `.recovery` files. Names were inventoried; contents were not opened. |
| Expected inventory after adding this review | 98 modified tracked paths; 97 untracked paths; 0 staged paths; 0 tracked deletions, including the same 11 `.recovery` files. |

Read-only commands included `git status`, branch/HEAD/upstream queries, `git diff --name-status/--stat/--numstat`, `git diff --cached --name-status`, `git ls-files --others --exclude-standard`, and `git ls-files --deleted`. The checkout's visible untracked set includes 38 documentation files, 22 test files, two application files, 22 frontend files, and the 11 recovery files; the remaining entries include scripts and fixtures. No migration path is changed or untracked.

## Proposed include set

Keep the functional checkpoint in separate, reviewable groups. Include a path only with the matching source, UI, and retained regression evidence; do not combine the local diagnostic work below with product changes.

### A. Archive lifecycle, protected preview, and archive UI

**Propose:**

- `app/Http/Controllers/FinalDocumentArchiveController.php`, `app/Http/Controllers/PamoArchiveController.php`, and the archive-related portions of `app/Http/Controllers/SubmissionTrackingController.php`.
- `app/Services/Archive/FakeDocumentArchiveGateway.php`, `FinalDocumentArchiveService.php`, `FinalDocumentArchiver.php`, `GoogleDriveArchiveException.php`, `GoogleDriveDocumentArchiveGateway.php`, `RegionalReceivingOfficeResolver.php`, and new `app/Services/Archive/PreGeneratedIdGoogleDriveArchiveGateway.php`.
- `app/Services/Attachments/ProtectedAttachmentService.php` and `CurrentDocumentReplacementService.php` where needed by protected file identity and replacement behavior.
- `routes/web.php`: the new `GET submission-tracking/dispatch-archives/files/{archive}` route hunk, with its existing authorization gates. See the hunk exclusions below for diagnostic middleware additions to other routes.
- `resources/js/Pages/SubmissionTracking/Archives.jsx` and `Index.jsx`; `resources/js/Layouts/AuthenticatedLayout.jsx` and `navigation.mjs` for the global Archives entry; the archive/preview components under `resources/js/Components/SubmissionTracking/` (`DocumentPreviewDialog.jsx`, `DocumentRoutingTimeline.jsx`, `PambRoutingTimeline.jsx`, `PdfDocumentViewer.jsx`, `RoutingAttachmentField.jsx`, `RoutingAttachmentLink.jsx`, `SubmissionReviewHistory.jsx`, `ArchiveThumbnail.jsx`, `FinalArchiveCard.jsx`, `FinalArchiveDisplayToggle.jsx`, `FinalArchiveReader.jsx`, `FinalArchiveRecordActions.jsx`, `FinalArchiveRecordLink.jsx`, `FinalArchiveResults.jsx`, `FinalArchiveStatusTabs.jsx`, `FinalArchivesFilterFields.jsx`, and `SubmissionTrackingReaderDialog.jsx`).
- The related helpers: `resources/js/Utils/archiveThumbnailLoader.mjs`, `archiveThumbnailRuntime.mjs`, `compliancePreview.mjs`, `finalArchiveActions.mjs`, `finalArchiveFilters.mjs`, `finalArchiveNavigation.mjs`, `finalArchivePresentation.mjs`, `finalArchiveReceiptTime.mjs`, `finalArchiveStatusPolling.mjs`, `pdfViewerGeometry.mjs`, `pdfjsWasmAssets.mjs`, and `protectedDocumentPreview.mjs`.
- `scripts/pdfjs-wasm-assets.mjs`, `scripts/verify-vite-build.mjs`, and `vite.config.js` with the decoder asset allowlist/emission hunks.
- Matching retained tests: `tests/Feature/FinalDocumentArchiveLifecycleTest.php`, `GoogleDriveDocumentArchiveGatewayTest.php`, `tests/frontend/SubmissionTrackingPreviewContract.test.mjs`, `tests/frontend/SubmissionTrackingSharedReaderIntegration.test.mjs`, `tests/frontend/ArchiveThumbnailVisibility.test.mjs`, `tests/frontend/FinalArchivesPresentation.test.mjs`, `tests/js/finalArchivesPageContract.test.mjs`, `archiveThumbnailLoader.test.mjs`, `archiveThumbnailPdfRender.test.mjs`, `finalArchiveActions.test.mjs`, `finalArchiveFilters.test.mjs`, `finalArchiveReceiptTime.test.mjs`, `finalArchiveStatusPolling.test.mjs`, `pdfViewerGeometry.test.mjs`, and `protectedDocumentPreview.test.mjs`, plus `tests/Fixtures/protected-preview-valid.pdf`.

The retained evidence supports this as a coherent candidate: the focused protected-route regression passed 1 test / 41 assertions; the existing viewer/archive JS checks passed 16/16; the prior guarded build verified 172 reachable ESM assets and 101 relative imports, and all seven decoder/fallback asset URLs returned HTTP 200. The latest browser report records two successful BMS preview responses and a rendered Conservation preview. These results do **not** close the earlier BMS HTTP 500: a same-second `MissingAppKeyException` lacks request correlation, so that historical failure remains unresolved.

### B. PAMO drafts, assignment scope, and submission routing

**Propose as a separate group:**

- `app/Http/Controllers/ConservationReportSubmissionController.php`, `PamoNativeReportsController.php`, `PamoPambDraftController.php`, `PamoReportWorkspaceController.php`, and the routing portions of `SubmissionTrackingController.php`.
- `app/Services/Pamo/PamoOriginSchemaReadiness.php`; `app/Services/SubmissionTracking/DocumentRoutingAccessService.php`, `RoutingPositionSnapshotService.php`, `RoutingStatusPresenter.php`, and `SubmissionTrackingService.php`.
- The two relevant scoped bindings in `app/Providers/AppServiceProvider.php` for `PamoOriginSchemaReadiness` and `RoutingPositionSnapshotService`.
- PAMO and routing UI changes in `resources/js/Components/PamoGenericSubmissionFields.jsx`, `PamoPambDraftWorkspace.jsx`, `PamoCompactToolbar.jsx`, `resources/js/Pages/PamoReports/Index.jsx`, and the reviewed submission-tracking components/pages.
- Matching tests, including `tests/Feature/PamoPambDraftRoutingTest.php`, `SubmissionTrackingCategoryAccessTest.php`, `SharedAccessProjectionRegressionTest.php`, `PamoOriginSchemaReadinessTest.php`, `tests/js/pamoGenericNavigation.test.mjs`, `pamoSharedTrackerAndGenericFormRendering.test.mjs`, `pamoActivityInputHotfix.test.mjs`, and `tests/frontend/PamoReportsRenderContract.test.mjs`.
- Include `resources/js/Pages/Bms/Index.jsx` and `ReportSubmissionTracker.jsx` with the BMS/native correction routing tests; the retained final closeout specifically covers native BMS correction queue/receipt behavior.
- Include the new pre-trait guard hunk in `tests/TestCase.php`. It checks the opened Laravel connection and PDO driver, `:memory:` config, and an empty SQLite `PRAGMA database_list` main filename before parent `setUpTraits()` can run migration-capable traits.

Retained reports cover draft validation and cancellation, scope projection, and route snapshots. The final closeout records the shared-access batch as 46 tests / 3,240 assertions with no reported failures, while noting the runner had no separate risky counter. These are retained results, not new runs.

### C. Dashboard projection and measured query reduction

**Propose as a separate complete change:** `app/Http/Controllers/DashboardController.php`, `app/Services/Dashboard/DashboardMonitoringService.php`, `resources/js/Pages/Dashboard.jsx`, `tests/Feature/DashboardMonitoringTest.php`, and `tests/Feature/EngpDashboardMonitoringTest.php`. The controller diff replaces a large legacy body; include the full compatible projection and its test changes together. The retained dashboard measurements are fixture-only and do not prove production-scale performance or explain the historical login delay.

### D. Mutation-bound success feedback

**Propose separately:** the success-message bridge hunk in `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Components/FlashSuccessDialog.jsx`, `tests/Feature/InertiaSuccessFlashLifecycleTest.php`, `tests/frontend/FlashSuccessDialogInteraction.test.mjs`, and `tests/js/documentMutationAnimationContract.test.mjs`. The final closeout reports these retained tests cover mutation-bound feedback and prevent GET navigation/archive open-close actions from replaying success popups. Keep the middleware hunk paired with these UI and test paths.

### E. Minimal supporting records

Propose `docs/cds-smart-pc-checkpoint-review-2026-10-17.md`, the updated `docs/cds-smart-home-resume-checkpoint-2026-10-15.md`, and the latest `docs/cds-smart-pc-browser-acceptance-verification-2026-10-17.md`. For technical evidence, the candidate support set is `docs/cds-smart-pc-verified-archive-pdf-preview-hotfix-verification-2026-10-16.md`, `docs/cds-smart-pc-final-audit-closeout-verification-2026-10-17.md`, `docs/cds-smart-pc-overall-system-audit-and-performance-verification-2026-10-17.md`, and `docs/cds-smart-pc-shared-access-regression-verification-2026-10-17.md`. Other untracked verification notes can remain local until an owner selects a historical-document set; they need not all be bundled with application code.

## Partial-file hunk instructions

| Path | Selective treatment |
|---|---|
| `app/Providers/AppServiceProvider.php` | Include only the request-scoped `PamoOriginSchemaReadiness` and `RoutingPositionSnapshotService` registrations (and their imports/comments). Keep `boot()`'s existing production `URL::forceScheme('https')` block intact. That production block is present in the current source and has no diff hunk. |
| `routes/web.php` | Include the new dispatch-file GET route with the existing `submission-tracking.view` and `final-archives.view` gates. Do not include the added `ArchiveActionTiming` middleware entries on the existing Archives GET or receipt/archive/retry POST routes as part of the feature hunk; those are local diagnostics. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Split by purpose if selected: relative route URLs and PAMO scope/navigation props belong with navigation/access tests; the mutation-bound Inertia flash bridge and archive status props belong with their flash/archive tests. Do not include a `LocalNavigationTrace::measure` wrapper as an incidental part of either feature. |
| `app/Support/LocalNavigationTrace.php` | Do not take the whole file as a product change. It mixes prior opt-in local page tracing with additional login-flow eligibility, anonymous trace activation, login timing phases, and response headers. If the local diagnostic is retained, isolate it as its own explicitly local-only change; otherwise exclude the full path and its paired login-trace tests. |
| `tests/TestCase.php`, `tests/bootstrap.php`, `tests/Support/TestDatabaseIsolationGuard.php` | Include the `tests/TestCase.php` PDO preflight hunk. Keep `tests/bootstrap.php` and `tests/Support/TestDatabaseIsolationGuard.php` at HEAD: both are unchanged and the existing fail-closed pre-bootstrap checks remain in them. Do not replace or weaken either layer. |

## Hold for separate owner review

Git cannot distinguish owner edits from earlier agent edits. Do not label the following as unwanted; keep them out of the selective feature proposal until their intended scope is confirmed and the relevant reports are reviewed as a set:

- Local login/startup diagnostics: `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Support/LocalNavigationTrace.php`, `app/Http/Middleware/ArchiveActionTiming.php`, `public/index.php`, `resources/js/Pages/Auth/Login.jsx`, `resources/js/Utils/localLoginTrace.mjs`, `tests/Feature/LocalLoginTraceFlowTest.php`, `tests/FrontControllerStartupRegression.php`, `tests/Unit/LocalNavigationTraceTest.php`, `tests/Unit/LocalLoginTraceTest.php`, and `tests/js/localLoginTrace.test.mjs`. Retained reports say the startup regression and focused login profile passed; the current browser capture does not need or validate these startup changes. Keep this as a separate local-diagnostics decision.
- Cross-module UI and admin work whose ownership/scope is not established by Git metadata: `app/Http/Controllers/Admin/UserController.php`, `AwsController.php`, `resources/js/Components/Crud/*`, `resources/js/Components/Form/*`, `resources/js/Pages/AWS/*`, `Admin/*`, `Bams/Index.jsx`, `ComplianceAlerts/Index.jsx`, `Imea/*`, plus corresponding tests. Some have verification notes, but they are independent of the archive/PAMO checkpoint and should be selected as their own owner-reviewed group.
- Historical or superseded verification notes among the untracked `docs/` files. Keep them locally. Prefer the current acceptance and focused closeout records over older records whose own text says a result was later superseded.

## Local-only HTTPS and private exclusions

- No tracked Apache/vhost, certificate, `.env`, or HTTPS configuration file appears in the current changed-path inventory. Machine-local alias/certificate/environment state was not inspected and is outside the proposed checkpoint. Preserve any such local-only HTTPS setup in place; do not copy it into Git.
- `app/Providers/AppServiceProvider.php` still forces HTTPS when `app()->environment('production')`. This line is unchanged in the current diff and must remain in production. The proposed provider hunk above is only for scoped service registration.
- Exclude and preserve locally: credentials and secret files; `.env` files; local backups; SQL/database dumps; temporary files; browser profiles, captures, logs, and generated browser output; and every `.recovery` file. The visible inventory contains 11 `.recovery` files; none were opened or changed. No private file content was copied into this report.
- The three untracked docs whose names mention browser setup/acceptance are documentation, not browser artifacts. Only the latest acceptance report is proposed above; setup diagnostics remain historical/local unless separately selected.

## Existing evidence and remaining limits

This review reuses the latest acceptance report and resume, the final audit closeout, the overall system audit, the startup hotfix report, and focused archive/shared-access reports. The 2026-10-09 checkpoint diff review covered a different branch/HEAD and a small, separate path set; its path-specific PASS is not carried forward to this inventory. No tests, builds, migrations, browser actions, or instrumentation were run for this review. Previously reported test batches overlap and must not be added together. The final closeout reused PHP lint and the current build verifier (172 ESM assets / 101 relative imports); its retained archive batch had 33 tests / 806 assertions with PHPUnit risky teardown warnings, and its shared-access batch had 46 tests / 3,240 assertions without separate risky-count reporting.

`tests/bootstrap.php` and `tests/Support/TestDatabaseIsolationGuard.php` match their HEAD blobs. The bootstrap guard checks the environment and refuses before Laravel bootstrap when configuration is unsafe; the current `TestCase.php` adds a second, same-process check against Laravel's actual opened PDO before traits can migrate. The candidate includes the HEAD bootstrap/guard plus the current TestCase hunk. This is required because environment-only checks do not prove which PDO connection Laravel actually opened.

Open items for any later release decision:

- Historical BMS final-archive preview HTTP 500 is not demonstrably fixed. The same-second `MissingAppKeyException` is not correlated to that request; follow-up previews succeeded.
- PDF semantic content/provenance is unverified; visual inspection covered only the pages listed in the acceptance report. Decoder assets were delivered successfully, but the inspected PDFs did not exercise them.
- A second PAMO account for Mati Protected Landscape was unavailable, so account-to-account PAMO isolation remains blocked; only the UI-level clamp was observed. Submission History had no rows for comparison.
- The latest CENRO login capture measured POST `/login` at 1,608.5 ms Resource Timing duration and observed GET `/dashboard` 200, but no direct dashboard Resource Timing or precise first-usable time was available. The older roughly 78-second login remains unattributed.
- Live Drive behavior, production MySQL concurrency/races, broad accessibility acceptance, and full PDF semantics remain outside retained evidence.

## Recommendation

Treat sections A–D as a proposed selective checkpoint, split into the named feature groups. Keep the diagnostic, cross-module owner-uncertain, historical-doc, and private/local paths out of that proposal unless separately selected. This report does not authorize or perform staging or commit operations.

## Candidate completeness validation - 2026-10-18

This addendum supersedes the earlier broad exclusion of shared Form/CRUD components only for the specific supporting hunks below. It also narrows the proposed test set where a new test reads excluded dirty cross-module files. The live checkout remains unchanged outside this review and the resume checkpoint. No tests, builds, migrations, Laravel bootstrap, or application session were run.

### Disposable source-only candidate

- Base: `HEAD c418d2d3f11afb50f17e5983bc53e5758c733e99`, with a source-root allowlist from that tree and the exact overlay manifest below.
- Candidate source root: `[local disposable candidate directory]\source`.
- Standalone overlay manifest: `[local disposable candidate directory]\overlay-manifest.txt` (SHA-256 `761004A7BB21C34BECD9197C76B5DA0209EA8FF71AD2079FAED4994B839F5C9F`; 113 unique paths; `vite.config.js` is included once as an overlay change. Candidate source was unchanged by this metadata correction.)
- Candidate contains 958 files / 20,391,667 bytes. It includes application/bootstrap/config source, migrations and factories, frontend source and static images, routes/scripts/tests, and Composer/npm/PHPUnit/Vite source manifests from HEAD. It omits docs, `.env*`, `.git`, `vendor`, `node_modules`, storage/runtime files, bootstrap cache, database seeders/data, `public/build.zip`, generated build/browser output, backups/dumps and `.recovery` files. The checkout’s recovery files were not opened, copied, or changed.
- Every manifest path exists. All whole-file overlays match their current worktree source by SHA-256; the three deliberate partial-file selections are `routes/web.php`, `FloatingField.jsx`, and `FloatingSelect.jsx`. There are zero unresolved local JavaScript imports and zero unresolved `App\`/`Tests\` PHP imports in the selected overlay sources under static path resolution.
- This is a source snapshot for review, not a runnable checkout: installed dependency trees and local environment secrets are absent by design. No execution result is claimed for this candidate.

### Required supporting hunks and HEAD-retained files

| Path | Candidate treatment and dependency evidence |
|---|---|
| `routes/web.php` | Add only the dispatch-archive file GET route with both existing view gates. Omit the four new `ArchiveActionTiming` additions on archive routes. Keep the separate transition-route timing middleware because that line is already in HEAD. |
| `app/Providers/AppServiceProvider.php` | Include the readiness and routing-position scoped registrations and imports. The production `URL::forceScheme('https')` block remains present. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Include the chosen PAMO/navigation, archive status, and mutation-bound Inertia flash feature hunks; no newly dirty login instrumentation is included. |
| `tests/TestCase.php` | Include the current pre-trait safety hunk: Laravel connection driver is SQLite, configured database is `:memory:`, opened PDO driver is SQLite, and `PRAGMA database_list` reports an empty main filename before `parent::setUpTraits()`. |
| `tests/bootstrap.php`; `tests/Support/TestDatabaseIsolationGuard.php` | Keep both byte-identical to HEAD. The existing fail-closed environment checks remain before Laravel bootstrap; the new `TestCase` check verifies the actual opened connection in the same process. |
| `resources/js/Components/Form/FilterToolbar.jsx` | Include `clearClassName`, used by `PamoCompactToolbar`. |
| `resources/js/Components/Form/FloatingField.jsx` | Include only `labelSrOnly` and the label-class branch, used by `PamoCompactToolbar`; omit the unrelated `aria-describedby` merge hunk. |
| `resources/js/Components/Form/FloatingInput.jsx` | Include explicit id propagation plus `labelSrOnly` and always-visible search placeholder support, all used by `PamoCompactToolbar`. |
| `resources/js/Components/Form/FloatingSelect.jsx` | Include only `id={fieldId}` on the `FloatingField`, so its label targets the selected PAMO/routing controls. Omit the separate hint-description hunk. Keep dirty `ScopedOptionSelect.jsx` at HEAD; selected callers use only its existing API. |
| `resources/js/Components/Crud/CrudTable.jsx` | Include `rowAriaLabel`, used by `FinalArchiveResults`; compact-empty and frame props already exist at HEAD. |
| `resources/js/Components/Crud/CrudDetailsModal.jsx` | Include its busy-overlay support, focus suspension/restoration and close blocking, used by the selected tracking detail flow. |
| `resources/js/Components/Crud/FilePreviewPanel.jsx` | Include `bare` and `viewerOptions` forwarding/rendering, used by `FinalArchiveReader` for the archive-reader viewer and download options. |
| `resources/js/Components/Crud/LaravelPaginationLinks.jsx` | Include `visitOptions` forwarding, used by archive pagination error handling. |
| `resources/js/Components/PamoReportPendingAnimation.jsx` | Include named operation phases; selected archive, retry, routing, and review flows call those intents. Its CSS dependency is already in the HEAD source base. |
| `resources/js/Components/Form/ScopedOptionSelect.jsx`; `resources/js/Components/Crud/CrudFormModal.jsx` | Retain HEAD. Selected callers do not require the dirty selector hint/disabled-option behavior, and the form modal already has the busy-overlay API used by these pages. |

The untracked `tests/js/documentMutationAnimationContract.test.mjs` is omitted from this candidate: its assertions read dirty AWS and IMEA pages excluded from this checkpoint, so those assertions would be evaluated against their HEAD versions here. Keep that test and those owner-uncertain changes local for separate selection. The focused selected flash, archive, PAMO and tracking tests remain in the manifest.

### Dependency and safety findings

- The new `PreGeneratedIdGoogleDriveArchiveGateway` interface resolves in the candidate; both the Google Drive and fake archive gateways implement it, and `FinalDocumentArchiver` selects that capability optionally. `GoogleDriveArchiveGateway` retains its existing provider binding and unconfigured fallback.
- `PamoOriginSchemaReadiness` resolves from the archive/routing services and has a scoped provider binding; `RoutingPositionSnapshotService` is also scoped. The snapshot and origin migrations required by these services already exist at HEAD; no migration diff is proposed.
- `pdfjsWasmAssets.mjs` resolves from the viewer, thumbnail runtime, Vite plugin and verifier. `pdfjs-dist` remains pinned in the HEAD package manifest. `protected-preview-valid.pdf` is present and is referenced by the selected archive lifecycle regression test.
- Selected JavaScript imports for `@inertiajs/core`, `@babel/parser`, `rolldown`, and `@napi-rs/canvas` have entries in the retained lockfile; these are not all direct top-level declarations. No `node_modules` tree was copied. Composer/npm runtime resolution is therefore not claimed or tested.
- The bootstrap and safety-guard Git blob IDs match HEAD. The candidate `public/index.php` matches HEAD byte-for-byte and uses the standard front-controller request handling. The candidate provider retains production HTTPS enforcement.

### Current acceptance evidence and remaining blockers

The latest browser report remains **PARTIAL PASS**: the post-build BMS archive reader returned HTTP 200 twice and rendered the 54-page PDF; Conservation archive 40 returned HTTP 200 and rendered 19 pages. The worker and all seven emitted PDF.js decoder/fallback asset URLs returned HTTP 200. The earlier BMS HTTP 500 remains unresolved; the same-second `MissingAppKeyException` has no request/route correlation, so the successful follow-up does not prove a fix. The inspected PDFs did not request decoder assets, and their semantic contents/provenance remain unverified.

Other open limits remain: no second MPL-assigned PAMO account was available (only the BMSFR UI clamp passed); Submission History was empty; the precise CENRO dashboard Resource Timing and first-usable time were unavailable; live Drive behavior, production MySQL races/concurrency, and broad accessibility checks are unverified. Existing recorded test/build results came from the prior working tree and are reused as evidence only; the candidate itself was not tested or built, as instructed. PHPUnit archive closeout also recorded risky teardown warnings.

### Exact overlay manifest

The following source/test paths are overlaid from the current worktree onto the HEAD-only source base. Hunk-level treatments are listed above.

```text
app/Http/Controllers/ConservationReportSubmissionController.php
app/Http/Controllers/DashboardController.php
app/Http/Controllers/FinalDocumentArchiveController.php
app/Http/Controllers/PamoArchiveController.php
app/Http/Controllers/PamoNativeReportsController.php
app/Http/Controllers/PamoPambDraftController.php
app/Http/Controllers/PamoReportWorkspaceController.php
app/Http/Controllers/SubmissionTrackingController.php
app/Http/Middleware/HandleInertiaRequests.php
app/Providers/AppServiceProvider.php
app/Services/Archive/FakeDocumentArchiveGateway.php
app/Services/Archive/FinalDocumentArchiver.php
app/Services/Archive/FinalDocumentArchiveService.php
app/Services/Archive/GoogleDriveArchiveException.php
app/Services/Archive/GoogleDriveDocumentArchiveGateway.php
app/Services/Archive/PreGeneratedIdGoogleDriveArchiveGateway.php
app/Services/Archive/RegionalReceivingOfficeResolver.php
app/Services/Attachments/CurrentDocumentReplacementService.php
app/Services/Attachments/ProtectedAttachmentService.php
app/Services/Dashboard/DashboardMonitoringService.php
app/Services/Pamo/PamoOriginSchemaReadiness.php
app/Services/SubmissionTracking/DocumentRoutingAccessService.php
app/Services/SubmissionTracking/RoutingPositionSnapshotService.php
app/Services/SubmissionTracking/RoutingStatusPresenter.php
app/Services/SubmissionTracking/SubmissionTrackingService.php
resources/js/Components/Crud/CrudDetailsModal.jsx
resources/js/Components/Crud/CrudTable.jsx
resources/js/Components/Crud/FilePreviewPanel.jsx
resources/js/Components/Crud/LaravelPaginationLinks.jsx
resources/js/Components/FlashSuccessDialog.jsx
resources/js/Components/Form/FilterToolbar.jsx
resources/js/Components/Form/FloatingField.jsx
resources/js/Components/Form/FloatingInput.jsx
resources/js/Components/Form/FloatingSelect.jsx
resources/js/Components/PamoCompactToolbar.jsx
resources/js/Components/PamoGenericSubmissionFields.jsx
resources/js/Components/PamoPambDraftWorkspace.jsx
resources/js/Components/PamoReportPendingAnimation.jsx
resources/js/Components/SubmissionTracking/ArchiveThumbnail.jsx
resources/js/Components/SubmissionTracking/DocumentPreviewDialog.jsx
resources/js/Components/SubmissionTracking/DocumentRoutingTimeline.jsx
resources/js/Components/SubmissionTracking/FinalArchiveCard.jsx
resources/js/Components/SubmissionTracking/FinalArchiveDisplayToggle.jsx
resources/js/Components/SubmissionTracking/FinalArchiveReader.jsx
resources/js/Components/SubmissionTracking/FinalArchiveRecordActions.jsx
resources/js/Components/SubmissionTracking/FinalArchiveRecordLink.jsx
resources/js/Components/SubmissionTracking/FinalArchiveResults.jsx
resources/js/Components/SubmissionTracking/FinalArchivesFilterFields.jsx
resources/js/Components/SubmissionTracking/FinalArchiveStatusTabs.jsx
resources/js/Components/SubmissionTracking/PambRoutingTimeline.jsx
resources/js/Components/SubmissionTracking/PdfDocumentViewer.jsx
resources/js/Components/SubmissionTracking/RoutingAttachmentField.jsx
resources/js/Components/SubmissionTracking/RoutingAttachmentLink.jsx
resources/js/Components/SubmissionTracking/SubmissionReviewHistory.jsx
resources/js/Components/SubmissionTracking/SubmissionTrackingReaderDialog.jsx
resources/js/Layouts/AuthenticatedLayout.jsx
resources/js/Layouts/navigation.mjs
resources/js/Pages/Bms/Index.jsx
resources/js/Pages/Bms/ReportSubmissionTracker.jsx
resources/js/Pages/Dashboard.jsx
resources/js/Pages/PamoReports/Index.jsx
resources/js/Pages/SubmissionTracking/Archives.jsx
resources/js/Pages/SubmissionTracking/Index.jsx
resources/js/Utils/archiveThumbnailLoader.mjs
resources/js/Utils/archiveThumbnailRuntime.mjs
resources/js/Utils/compliancePreview.mjs
resources/js/Utils/finalArchiveActions.mjs
resources/js/Utils/finalArchiveFilters.mjs
resources/js/Utils/finalArchiveNavigation.mjs
resources/js/Utils/finalArchivePresentation.mjs
resources/js/Utils/finalArchiveReceiptTime.mjs
resources/js/Utils/finalArchiveStatusPolling.mjs
resources/js/Utils/pdfjsWasmAssets.mjs
resources/js/Utils/pdfViewerGeometry.mjs
resources/js/Utils/protectedDocumentPreview.mjs
routes/web.php
scripts/pdfjs-wasm-assets.mjs
scripts/verify-vite-build.mjs
tests/Feature/ConservationCategoryNavigationTest.php
tests/Feature/CurrentDocumentReplacementServiceTest.php
tests/Feature/DashboardMonitoringTest.php
tests/Feature/EngpDashboardMonitoringTest.php
tests/Feature/FinalDocumentArchiveLifecycleTest.php
tests/Feature/GoogleDriveDocumentArchiveGatewayTest.php
tests/Feature/InertiaSuccessFlashLifecycleTest.php
tests/Feature/PamoPambDraftRoutingTest.php
tests/Feature/SharedAccessProjectionRegressionTest.php
tests/Feature/SubmissionTrackingCategoryAccessTest.php
tests/Fixtures/protected-preview-valid.pdf
tests/frontend/ArchiveThumbnailVisibility.test.mjs
tests/frontend/FilterToolbarInteraction.test.mjs
tests/frontend/FinalArchivesPresentation.test.mjs
tests/frontend/FlashSuccessDialogInteraction.test.mjs
tests/frontend/PamoReportsRenderContract.test.mjs
tests/frontend/SubmissionTrackingPreviewContract.test.mjs
tests/frontend/SubmissionTrackingSharedReaderIntegration.test.mjs
tests/js/archiveThumbnailLoader.test.mjs
tests/js/archiveThumbnailPdfRender.test.mjs
tests/js/AuthenticatedNavigation.test.mjs
tests/js/compliancePreview.test.mjs
tests/js/finalArchiveActions.test.mjs
tests/js/finalArchiveFilters.test.mjs
tests/js/finalArchiveReceiptTime.test.mjs
tests/js/finalArchivesPageContract.test.mjs
tests/js/finalArchiveStatusPolling.test.mjs
tests/js/pamoActivityInputHotfix.test.mjs
tests/js/pamoGenericNavigation.test.mjs
tests/js/pamoSharedTrackerAndGenericFormRendering.test.mjs
tests/js/pdfViewerGeometry.test.mjs
tests/js/protectedDocumentPreview.test.mjs
tests/TestCase.php
tests/Unit/PamoOriginSchemaReadinessTest.php
vite.config.js
```