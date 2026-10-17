# CDS-SMART PC browser acceptance verification - 2026-10-17

## Current result

**PARTIAL PASS.** The prior nine active, approved non-admin role sign-ins all succeeded. On the focused follow-up, the authorized BMS archive 8 preview returned HTTP 200 twice and the 54-page PDF rendered; Conservation 40’s authorized file requests returned HTTP 200 and its 19-page PDF rendered. The matching worker and all seven locally emitted PDF.js decoder/fallback assets returned HTTP 200. The earlier BMS preview HTTP 500 remains unresolved: a same-second Laravel `MissingAppKeyException` was found, but its log has no route/request correlation, so the failure is not demonstrably fixed. PAMO’s ordinary Tracking preview remains unavailable under role/stage policy, and PDF semantic contents remain unverified. One normal CENRO CDS Focal login succeeded: POST /login returned 302 and GET /dashboard returned 200. Browser Resource Timing measured the POST at 1,608.5 ms; the precise first-usable time and direct dashboard network timing were not exposed in this soft-navigation flow.

This supersedes the earlier blocked result in this report, which described the period before the connected browser tools became available.

## Scope and method

- After the PC restart, the browser was at about:blank. The local login page was reopened and rendered normally.
- A bounded read-only lookup returned only active, approved users in the PAMO, CENRO and PENRO non-admin categories. No administrator categories were queried. The query selected identity, role and scope fields only; it did not select password fields and ran in a read-only transaction that was rolled back.
- The user-supplied shared development password was entered only in the normal login form. No credentials or login identifiers are included in this report or repository files.
- Each account was logged out using the application's Log out control before the next account. Chrome is left at /login with no account signed in.
- No draft, routing, receipt, archive, upload, retry or other application mutation was performed.

## Role and scope results

| Role | Observed scope | Result |
|---|---|---|
| PAMO | Baganga Mangrove Swamp Forest Reserve (BMSFR); dashboard reported 1 authorized office and 3 normalized records | **Partial pass.** Login and assigned-area scope passed. The New draft dialog was cancelled without saving. The alternate MPL query/filter remained clamped to BMSFR; a second MPL-assigned PAMO login was unavailable. Original-document preview is policy-blocked for this actor; BMS archive reader failure is recorded below. |
| CENRO CDS Focal | CENRO Baganga; dashboard and archive filters showed APL and BMSFR, 4 normalized records | **Pass.** Login, dashboard scope, queue/history views and 4-entry Archives view rendered. |
| CENRO CDS Chief | CENRO Baganga; APL and BMSFR, 4 normalized records | **Pass.** Login, dashboard scope, queue/history views and 4-entry Archives view rendered. |
| CENRO Records Unit | CENRO Baganga; APL and BMSFR, 4 normalized records | **Pass.** Login, dashboard scope, queue/history views and 4-entry Archives view rendered. |
| PENRO Records Unit | PENRO Davao Oriental; dashboard showed the APL and BMSFR source/area entries and 4 normalized records | **Pass.** Login, dashboard scope, queue/history views and 4-entry Archives view rendered. |
| PENRO CDS Focal | PENRO Davao Oriental; APL and BMSFR entries, 4 normalized records | **Pass.** Login, dashboard scope, queue/history views and 4-entry Archives view rendered. |
| PENRO CDS Chief | PENRO Davao Oriental; APL and BMSFR entries, 4 normalized records | **Pass.** Dashboard rendered; the signed-in account label displayed “PENRO CDS Chief.” The earlier “Ccief” observation was not reproduced. |
| PENRO TSD Chief | PENRO Davao Oriental; APL and BMSFR entries, 4 normalized records | **Pass.** Login, dashboard scope, queue/history views and 4-entry Archives view rendered. |
| Office of the PENRO | PENRO Davao Oriental; APL and BMSFR entries, 4 normalized records | **Pass.** Login, dashboard scope, queue/history views and 4-entry Archives view rendered. |

For all CENRO and PENRO roles, For Action, Sent Reports and Submission History showed empty queues; each showed 4 Archives entries. PAMO showed empty For Action, Sent Reports and Completed Reports views, and 3 Archives entries. The role sign-ins all succeeded, and normal logout was confirmed between accounts.

## Read-only checks performed

- PAMO Reports was scoped to Baganga Mangrove Swamp Forest Reserve and showed 0 private drafts. The New draft dialog was opened, its activity text was cleared and retyped, then Cancel closed the dialog; the count remained 0.
- Mati Protected Landscape (MPL) was the available alternate PAMO area. No active, approved PAMO login was assigned to MPL. While signed in to the BMSFR PAMO account, /pamo/reports?protected_area_id=1 remained scoped to BMSFR, and its Protected Area filter offered only BMSFR. This passes the available UI-level scope-isolation check; a second PAMO account could not be tested.
- PAMO Archives Grid and List views both rendered. Its filter panel exposed module, area, office and year filters, restricted to BMSFR. The CENRO Baganga archive filter exposed APL and BMSFR.
- The BMS archive card resolved to /submission-tracking/archives?record=8&source=bms; its Tracking record route was /submission-tracking?source=bms&source_id=8. The current final-archive reader later returned HTTP 500 with a blank document pane, superseding the earlier 54-page observation. A separate Conservation archive (record 40) loaded 19 pages; page 1 was visually inspected, and Fit page, zoom, Expand, Restore and Close responded. No download was used.
- The read-only Tracking selection for BMS record 8 showed the APL-named current official document and “Preview unavailable for this account.” This is separate from View Full Details and the archive reader. The ordinary preview is unavailable under the current role/stage policy; separate archive-reader authorization succeeded, but its file endpoint returned 500. No download was used.
- PAMO Dashboard was inspected at 1366×900 and 390×844. At both sizes, document scrollWidth equaled clientWidth; no page-level horizontal overflow was detected.

## Failures, blockers and diagnostics

1. **No archive association defect was proven.** BMS record 8 is a BMSFR BMS Final Report. Its APL-named current official MOV is readable (application/pdf, 18,378,429 bytes). The source contract is bms-report / slot mov / identity regional-return-mov. Receipt 1, the regional_return_final / ARCHIVED finalized manifest revision 1, and archive 19 resolve to the same source record and expected slot. Source, manifest and archive sizes and hashes match; manifest reader authorization succeeded. The filename is preserved; file contents/provenance remain unverified.
2. **Tracking original preview is unavailable by policy.** The PAMO actor can view record 8 but lacks bms.view; its stage is released_to_regional and current_document.can_preview is false. The service, UI and protected attachment route use the same authorization rule.
3. **Historical BMS final-archive HTTP 500 remains unresolved.** The pre-build GET /submission-tracking/final-archives/files/1?preview=1 returned HTTP 500. A same-second `Illuminate\Encryption\MissingAppKeyException` was logged, but there was no request ID, route path or controller frame to correlate it. Two post-build requests returned HTTP 200 and the reader rendered; this does not prove the earlier failure is fixed. Details and exact limits are in the focused hotfix verification section below.
4. **PENRO CDS Chief label passes.** A fresh normal login displayed “PENRO CDS Chief”; the earlier “Ccief” observation was not reproduced.
5. **Second PAMO login remains blocked.** No active, approved MPL PAMO login exists. The alternate UI query/filter remained clamped to BMSFR, passing available UI-level isolation but not account-to-account isolation.
6. **History comparison remains blocked.** Submission History/Completed Reports was empty for all roles, preventing row-level archive comparison.
7. **Mutation feedback remains untested.** Saving a report, route, receipt or archive was excluded. The draft dialog was edited locally and cancelled.

Diagnostics and the current CENRO timing sample are detailed in the focused follow-up section below. The BMS archive HTTP 500 and the earlier 54-page observation are kept distinct; no failure cause is inferred.

## Preservation

No application, configuration, database, account, Git index/history or archive state was changed. No tests, migrations, builds, uploads or provider calls were run. Only this acceptance report and the home resume checkpoint were updated. The report contains no passwords or login identifiers.

## Focused read-only browser follow-up - 2026-10-17

This latest section supersedes the earlier archive-association, BMS preview, PENRO label, draft-control, PDF preview and CENRO timing statements above.

### BMS source, receipt, manifest and archive

- Browser context: BMS Report card for Conduct 1st Semester Biodiversity Monitoring System, Baganga Mangrove Swamp Forest Reserve; route /submission-tracking/archives?record=8&source=bms. Tracking route: /submission-tracking?source=bms&source_id=8.
- Exact source: BMS record 8, BMSFR, BMS module, Final Report. Current official filename: Regular_PAMB_Meetings_Minutes_APL_Mar2026 (2).pdf. Source path/file readable, MIME application/pdf, 18,378,429 bytes.
- Registered contract: source bms maps to attachment source bms-report, official slot mov, identity regional-return-mov. Receipt 1 and the latest manifest belong to the same source, record and workflow. Manifest phase regional_return_final, status ARCHIVED, revision 1, finalized. DocumentArchive record 19 is ARCHIVED and remote availability was verified.
- Source, manifest file, archive file, expected slot, sizes and hashes match. Digest values are omitted. PAMO manifest reader authorization succeeded. This is **not a proven wrong archive binding**. The filename alone cannot establish semantic contents/provenance; no rename, replacement or archive action occurred.

### Preview policy, reader error and other viewer

- BMSFR PAMO could view source record 8 but lacked bms.view. Stage was released_to_regional and current_document.can_preview false. SubmissionTrackingService, ProtectedAttachmentController and the UI share this authorization decision. Thus ordinary Tracking Preview is unavailable under current role/stage policy; View Full Details is separate.
- The authorized manifest reader’s GET /submission-tracking/final-archives/files/1?preview=1 returned HTTP 500. UI message: “The server did not return a supported document. Refresh the submission details and retry.” No retry was made, no server exception was captured, and the cause is unresolved. This supersedes the earlier claim that this BMS reader currently exposed 54 pages.
- Separate Conservation archive /submission-tracking/archives?record=40&source=conservation rendered 19 pages. Page 1 was visually inspected; it appeared complete with no obvious missing object. Fit page, zoom in/out, Expand/Restore and Close responded. Archives Grid/List, selection context, filenames and lazy thumbnails were observed; no download was used.

### Draft, responsive UI and diagnostics

- PAMO New draft dialog: activity text entered, cleared, retyped and Cancelled. Dialog closed and private draft count remained 0; no save/request occurred.
- Reports, Submission Tracking and Archives were checked at 1366×900 and 390×844. Desktop filters were opened on all three pages: Reports showed Report module; Tracking showed Module, Protected Area and Status; Archives showed Report module, Protected Area, Originating office and Reporting year. The panels fit within the desktop viewport. Mobile filters also opened as overlays with expected fields. No horizontal overflow: Reports document widths 1366/1366 desktop and 390/390 mobile; Tracking and Archives 1356/1356 desktop and 380/380 mobile (viewport difference is the 10-pixel vertical scrollbar). Page ends were reached by scrolling. No semantic footer or role=contentinfo element was present. Viewport restored to 1366×900.
- Exact local Debugbar diagnostic: GET /_debugbar/open?op=get&id=01M552ME6NB475Z6XMKJ8FN3VM returned 404.
- PDF.js logged missing wasmUrl, unresolved nulljbig2_nowasm_fallback.js, and repeated JBig2Error initialization warnings. Worker assets returned 200: /build/assets/pdf.worker.min-Dswkl-cV.mjs (1,265,413 encoded bytes) and /build/assets/pdf.worker.min-CK4CYzl5.js (430,013 bytes). No separate WASM/JBIG2 entry was observed. The warnings did not identify an affected page; page 1 of the separate 19-page PDF showed no obvious missing object. Broader impact is unconfirmed.

### CENRO CDS Focal login and warm dashboard request

Same account, host, 1366×900 viewport and CENRO Baganga scope: header showed CENRO Baganga CDS-Focal, 2 authorized offices and 4 normalized records. Normal login reached dashboard in 36,056 ms from form fill/submit through the browser dashboard wait; this is tool wall time, not server duration.

| Request | Result | Browser timing |
|---|---|---|
| First GET /dashboard after login | 200 | TTFB 1,757.6 ms; download 3.5 ms; DOMContentLoaded 1,846.9 ms; load 1,848.2 ms; transfer 594,768 bytes; encoded/decoded body 594,468 bytes |
| One warm repeat GET /dashboard, same account/scope | 200 | TTFB 1,149.9 ms; download 2.7 ms; DOMContentLoaded 1,236.6 ms; load 1,237.6 ms; transfer 396,617 bytes; encoded/decoded body 396,317 bytes |

Neither navigation had Server-Timing data. The first page’s Debugbar had moved to asynchronous /notifications/recent, so its query count is not attributed to the dashboard. The warm repeat’s Debugbar explicitly showed GET /dashboard: 129 query events, 189 models, 64 gate checks, 1 view, 1 Inertia and 1 cache; request summary 1.14 s and approximately 7 MB. This is one-account evidence, not a comparative or causal conclusion.

### Final status

- PAMO/BMSFR: login/dashboard/Reports and available MPL UI isolation passed; second MPL-assigned PAMO login blocked. Tracking Preview policy-blocked; authorized archive file reader failed 500.
- CENRO CDS Focal/Baganga: login, scope, first dashboard request and one warm repeat passed with HTTP 200. Previously checked CENRO CDS Chief and Records roles remain pass for read-only navigation.
- PENRO CDS Chief/Davao Oriental: fresh normal login/dashboard passed; label was exactly PENRO CDS Chief. Previously checked PENRO roles remain pass.
- All nine prior non-admin roles logged in successfully; no admin session was used. Normal logout between accounts. Chrome left at http://cds-system.test/login, 1366×900, signed out.
- No application/configuration/data/account/Git changes, report/routing/archive mutations, uploads, retries, tests, builds or reinstall. Only this report and the resume checkpoint were updated. No credential, login identifier or raw hash is included.

## Focused archive preview hotfix verification — 2026-10-17

This section supersedes the earlier statement above that the final-archive request had no captured server exception and that no test or build had run. The findings below are limited to the focused hotfix work.

### BMS final-archive HTTP 500 and status

The earlier BMSFR PAMO request was `GET /submission-tracking/final-archives/files/1?preview=1`, logged by Apache at 22:35:25 +08 with HTTP 500 (74,021 response bytes). A fresh Laravel log entry at the same second reported `Illuminate\Encryption\MissingAppKeyException: No application encryption key has been specified.` at `vendor/laravel/framework/src/Illuminate/Encryption/EncryptionServiceProvider.php:83`. Its trace included `LocalNavigationTiming` and the Laravel middleware/router pipeline, but no `FinalDocumentArchiveController` frame, request ID or route path. This timing makes the missing-key runtime error the best available blocker evidence; it does not uniquely correlate that exception to this request.

The route/controller path was inspected without modification. It retains the archive-view gates, manifest and exact source/file binding checks, private staged-file path validation, size/hash/MIME checks, inline allowlist, and safe range handling. The fresh evidence points outside that controller, and the key/configuration is outside the authorized scope, so no speculative PHP or key/configuration change was made.

After the guarded frontend build, reopening BMS archive 8 produced two browser requests to the exact protected file preview URL, both HTTP 200; Apache logged both as 200 with 18,378,429 response bytes each. Page 1 and page 2 rendered in the reader, with page 1 visually inspected. The earlier 500 did not recur during this check, but it is **not demonstrably fixed**: the same-second server exception lacks request-level correlation, and no application correction targeted it. The issue is therefore classified as **not reproduced in the follow-up; root cause remains unconfirmed / runtime MissingAppKey evidence unresolved**.

The ordinary PAMO Tracking original preview remained unavailable under the existing role/stage policy. The separate “View Full Details” action remained distinct and was not invoked. No permission or archive lifecycle behavior was changed.

### PDF.js decoder asset configuration

The affected renderer and worker use installed `pdfjs-dist` 6.3.289. Its installed API/types support `wasmUrl` as a trailing-slash decoder asset base. The viewer and archive-thumbnail loading paths now pass the local `/build/pdfjs/wasm/` base. A Vite plugin serves/emits the seven allowlisted decoder/fallback assets from the installed package; the build verifier checks their bytes against `node_modules`.

The single guarded Vite build completed: 172 ESM assets, 101 generated relative imports and all 7 matching `pdfjs-dist` 6.3.289 decoder assets were verified. The worker returned HTTP 200 at `/build/assets/pdf.worker.min-Dswkl-cV.mjs`. Direct same-origin requests to all seven emitted decoder/fallback URLs returned HTTP 200 with the expected asset types and byte lengths. The inspected BMS and Conservation PDFs did not request a decoder asset during rendering, so these checks prove delivery, not that these documents exercised the decoder code. No worker version mismatch was found or inferred. The client-side fix is separate from the server 500 and is not claimed to resolve it.

### Isolated regression and browser results

- The route regression test passed: 1 test, 41 assertions, using the real protected archive route/controller. The actor could browse a final archive while lacking `bms.view`; the test covers scope denial, source/manifest/file integrity, inline and attachment behavior, valid 206 range and invalid 416 range, and staged-file tampering. The provider was not contacted.
- The test bootstrap retained its fail-closed guard. Before migrations/fixtures, the same process verified Laravel's opened PDO used SQLite `:memory:` and `PRAGMA database_list` reported an empty main filename. No operational database or operational PDF was used.
- Existing viewer/archive JS checks passed 16/16. PHP lint and JavaScript syntax checks passed; `git diff --check` passed (with existing CRLF normalization warnings).
- BMS archive 8: the PDF reader displayed 54 pages; pages 1 and 2 were present, with page 1 visually inspected. Zoom, fit, expand/restore and close worked in the earlier part of this focused check. Both protected file requests returned 200 after the build; no browser console errors were reported.
- Conservation archive 40: authorized file requests for file IDs 2 and 3 returned 200. The separate 19-page PDF rendered; pages 1 and 2 were visually inspected, and page 2 showed image-bearing scanned content. Zoom changed 75% to 90% and back to 75%; Fit page activated at 75%; Close reader worked. No current console errors were reported.
- The existing Tracking Preview policy result remained unchanged. No report, routing or archive mutation, upload, Retry Archive, provider write, or other state-changing action was performed.

### Changed paths and remaining limits

Focused code paths: `vite.config.js`; `scripts/pdfjs-wasm-assets.mjs`; `scripts/verify-vite-build.mjs`; `resources/js/Utils/pdfjsWasmAssets.mjs`; `resources/js/Utils/archiveThumbnailRuntime.mjs`; `resources/js/Components/SubmissionTracking/PdfDocumentViewer.jsx`; `tests/Feature/FinalDocumentArchiveLifecycleTest.php`. This report and the local resume checkpoint were also updated. Existing dirty checkout changes and all eleven `.recovery` files were preserved; no Git operation was performed.

The two successful BMS preview responses show the route worked on follow-up, but do not establish that the prior 500 is fixed. The same-second MissingAppKey log is not request-correlated, and its runtime/configuration condition remains outside scope. PDF semantic contents/provenance were not verified; visual inspection is credited only for BMS page 1 and Conservation pages 1–2. No claim is made that every page was visually audited or that the sample PDFs exercised WASM decoders.

Chrome was normally logged out and left at `http://cds-system.test/login`, 1366×900. No credentials or login identifiers were added to this report.

## CENRO CDS Focal login timing capture — 2026-10-17

One normal sign-in succeeded for the non-admin CENRO Baganga CDS-Focal account. The current dashboard showed the expected role label and Report Submission Overview content. The supplied login identifier and password are intentionally omitted.

| Request | Result | Browser timing |
|---|---|---|
| POST /login | 302 Found | Resource Timing duration 1,608.5 ms; requestStart to responseStart 1,160.4 ms; responseStart to responseEnd 4.8 ms |
| Redirect follow-up: GET /dashboard | 200 OK | Current Inertia soft-navigation had no matching Resource Timing entry. The visible Laravel Debugbar summary showed 1.15 s for GET /dashboard; this is the displayed application request duration, not an end-to-end browser measurement. |
| Dashboard build assets | No new requests after this capture’s pre-login network baseline | Dashboard and chart bundles had already returned HTTP 200 earlier in this same browser page session. They were already loaded for this navigation; no fresh per-asset timing was available. |

The dashboard heading and content were present when checked. A precise first-usable time was not captured because the existing browser wait helper inserted a fixed 30-second delay before checking the page, and the login flow kept the original document’s Navigation Timing entry. Tool wall time is not used as a request or server duration. The request list exposed only safe method/path/status metadata; no request or response headers or bodies were inspected or recorded.

This is one browser capture. No second login, dashboard reload, route mutation, test, build or instrumentation was performed. Normal sign-out returned `POST /logout` → 302 Found and `GET /login` → 200 OK; Chrome ended at `http://cds-system.test/login` at 1366×900. No credentials were added to this report.
