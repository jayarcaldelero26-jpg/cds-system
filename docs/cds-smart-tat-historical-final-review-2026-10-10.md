# CDS-SMART TAT and Historical Reports — final review

> Historical cumulative review. Its checkpoint inventories and repository-state counts are superseded by the October 11 office checkpoint handoff at docs/cds-smart-office-checkpoint-handoff-2026-10-11.md; use current Git state for publication scope.

**Review date:** 2026-10-10
**Scope:** accumulated Office TAT and Historical Reports changes in this checkout, including the October 10 screenshot follow-up.
**Disposition:** the two Office TAT timeliness defects and the capped event-aggregation defect are corrected and covered by focused regression checks. Browser acceptance fixed two Historical Reports defects, and this focused follow-up corrected the filter layout and selector Escape handling. No migration, live report, routing-setting change, commit, or push was made.

## Checkpoint state

- Branch: `main`.
- `HEAD`: `fda098eddaef1a91d6fce1997cc48cfa437d4948`, matching the continuation checkpoint named in the review brief.
- No staged changes. The initial technical review recorded 15 tracked modifications and 25 untracked files. The 01:59 browser follow-up expanded that proposal to 44 actual paths: 17 tracked modifications and 27 untracked files. This focused filter follow-up adds two tracked modifications, so the current intended feature inventory is 46 actual paths: 19 tracked modifications and 27 untracked files. The local `origin/main` ref resolves to the same commit; the local `HEAD...origin/main` comparison is `0 0`. No remote was contacted, so this is not a fresh synchronization claim.
- No applicable `AGENTS.md` was present in the repository or its parent directories.
- The initial technical-review checkpoint contained 40 actual files: 15 tracked modifications and 25 untracked files, including this review note. Normal `git status --short` groups two untracked feature directories into one entry apiece, so it reported 38 feature entries; expanding those directories yielded the original 40-file inventory below. The 01:59 browser follow-up added four paths, and this filter follow-up adds two tracked paths for the current 46-file proposal. `.recovery/` is separately listed and excluded; it was not inspected. No other unrelated working-tree change was identified.

## Findings and corrections

1. **Timeliness could use the wrong receipt.** The first accepted `received` event was used for a report's source deadline even when that event was an earlier CENRO intake. Timeliness now waits for the authoritative PENRO receipt event. Earlier office receipts can still start that office's live TAT interval. The Office TAT average and interval rules were not changed. See `app/Services/OfficeTatAnalyticsService.php:335`.
2. **Date-cast deadlines were counted as missing evidence.** Eloquent returns `deadline_submission` as a date object for these source models, while the timeliness evaluator accepted strings only. Removing that type rejection lets Carbon compare the stored date with the receipt. Live and manual historical timeliness now share the corrected path. The focused ENGP and older-quarter conservation checks cover a late PENRO receipt and an on-time original receipt respectively.

The review checked the surrounding boundaries against current routes, source registries, controllers/services, models, UI consumers, migrations, and tests:

3. **Receipt-event caps could understate the selected period.** The 10,000-row general receipt query fed directly into authorized source loading, distinct received counts, authoritative PENRO timeliness, and the receipt cohort for live TAT. The 5,000-row PAMB receipt query fed source loading and PENRO-receipt timeliness. Events after either cutoff could therefore change totals, completed samples, duration means, and any score/rank that consumes those values. This was a real completeness defect in the query path; no live inventory was inspected, so whether current operational data reaches either boundary remains unverified.

   Both capped collections are removed. The service now counts source-evidence rows with scalar queries, pages distinct source-document IDs, hydrates at most 300 authorized records per batch, and reads receipt rows in primary-key pages of at most 500. Live timelines are read in stable `(source document, occurred_at, id)` keyset pages of at most 500; each document's active interval, correction segments, duplicate handling, and pending/invalid state persists across page boundaries. Internal page sizes are bounded (500 documents and 1,000 timeline events maximum) and pages continue until the selected eligible set is exhausted; these are memory bounds, not result caps. Conservation documents with recognized PAMB timeline evidence continue to use that timeline in place of generic routing events. Period/cohort and as-of bounds, source authorization/filtering, formulas, and historical/live separation remain unchanged.

- Server-side Office TAT/configuration accepts only PAMO and CENRO and scopes offices through current organizational access. PENRO routes and source receipt/release milestones remain intact.
- Average TAT ranks exact completed-document mean durations, shares exact ties, weights the overall mean by documents, accepts a measured zero, and leaves missing, invalid, or pending intervals outside completed averages. Correction cycles combine once per document; an unfinished later cycle remains pending.
- PA historical evidence is attributed to its assigned PAMO without treating CENRO/PENRO business dates as PAMO handling time. ENGP evidence is attributed to its assigned CENRO and labels the original CENRO-release-to-PENRO-receipt interval separately from live event TAT.
- Historical encoding writes to the selected existing source module with immutable provenance, original business-date evidence, and factual encoding audit time. Idempotency and the ENGP same-office/period duplicate guard prevent replacing an existing report. Historical rows remain visible in their source module but are filtered from tracking queues/counts/options and blocked from ordinary update/routing actions.
- Protected attachment descriptors use existing permission-checked source routes. Settings navigation remains capability-aware with one main Settings entry; Office TAT remains under Administration and Historical Reports inside Settings.
- The migration and permission source was reviewed, but not rerun. The 42 workflow definitions retain module-specific period, date, calendar, and deadline rules. End-to-end populated fixtures cover BMS, ENGP, and conservation; other workflow mappings were checked against their source registries/calculators rather than each being populated in a separate test.

## Validation evidence by phase

| Phase | Recorded checks | Use in this review |
| --- | --- | --- |
| Original installation/workflow pass, 2026-10-09 | 178 PHP tests / 965 assertions, 139 JavaScript tests, and a 1,443-module Vite build. | Historical evidence for that installation revision only; not rerun here. |
| Average-ranking/shared-UI pass, 2026-10-09 | 57 JavaScript checks, 5 focused PHP tests, and a 1,445-module build. | Historical evidence for that UI revision; overlaps other JavaScript/PHP inventories. |
| Screenshot follow-up, 2026-10-10 | 42 focused JavaScript checks, 3 PHP tests / 267 assertions, and a 1,445-module build. | Historical evidence for the icon, attribution copy, and encoding follow-up. The report's older installation and runtime observations are now explicitly dated and generalized. |
| Final review fixes, 2026-10-10 | `OfficeTatAverageRankingTest.php`: 4/4 passed, 22 assertions. Older-quarter conservation fixture: 1/1 passed, 113 assertions. PHP syntax checks passed for the service and focused feature test. Vite 8.1.4 production build passed with 1,445 modules. | Fresh after the review fixes. The older-quarter test is one of the three tests already counted in the prior October 10 follow-up; these totals must not be added together. |
| Event-cap correction, 2026-10-10 | `OfficeTatAverageRankingTest.php`: 7/7 passed, 48 assertions. The general fixture has 10,002 receipts: 10,001 CENRO Baganga documents plus a CENRO Mati comparison. The Baganga sample beyond the former 10,000-row cutoff adds a two-day duration; the complete mean ranks slower than Mati's one-day-plus-five-second mean, while the truncated mean would rank faster. The PAMB fixture has 5,001 receipts and retains one late report beyond the former 5,000-row cutoff. Both cases produce independently specified totals and were repeated with different document/event page sizes. Existing correction-cycle, duplicate, unfinished later cycle, zero-duration, malformed-handoff, authoritative-receipt, deadline-cast, and CENRO-scope checks pass. | Fresh after the bounded aggregation change. The old-quarter manual-history behavior was not changed, so its separately dated earlier result was not rerun. |
| Browser acceptance correction, 2026-10-10 | Four focused JavaScript files passed 38/38 tests; Vite 8.1.4 production build passed with 1,446 modules. The local authenticated browser confirmed the encode modal opens on an insecure HTTP context, focuses its workflow chooser, traps forward Tab, closes the chooser before the modal on Escape, and returns focus to the trigger. | Fresh after the UUID compatibility and shared modal keyboard fixes. No report was saved; a populated ranking and saved historical-record visibility remain unavailable in the current empty dataset. |

Alongside the PHP logic/tests and documentation, the review removed an unintended UTF-8 BOM from the BMS tracker and restored indentation on the Settings sidebar link; neither changes frontend behavior. The recorded Vite production build followed those frontend cleanups. The event-cap correction is backend-only, so no new build was run. The final PHP syntax, `git diff --check`, and fresh proposed-file scan are recorded below.

## Proposed checkpoint inventory

The initial technical-review checkpoint contained **40 actual file paths**: 15 tracked modifications and 25 untracked files, with this review note counted among the untracked files. The status-entry grouping described above accounts for the difference between 38 normal status entries and 40 actual files. The 01:59 browser follow-up added four paths for 44 total; this focused filter follow-up adds two tracked paths for **46 actual paths**. Every path is included because it implements, verifies, or documents the feature contracts above.

### Application code — routes, authorization, persistence, analytics, tracking isolation, and source-module UI (28)

- `app/Http/Controllers/ConservationReportSubmissionController.php`
- `app/Http/Controllers/HistoricalReportController.php`
- `app/Http/Controllers/OfficeTatController.php`
- `app/Http/Controllers/SubmissionTrackingController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Models/HistoricalReportOrigin.php`
- `app/Models/OfficeTatConfigurationVersion.php`
- `app/Services/HistoricalReports/HistoricalReportCatalog.php`
- `app/Services/HistoricalReports/HistoricalReportEncoder.php`
- `app/Services/OfficeTatAnalyticsService.php`
- `app/Services/SubmissionTracking/SubmissionTrackingService.php`
- `routes/web.php`
- `resources/js/Components/Admin/SettingsShell.jsx`
- `resources/js/Components/HistoricalRecordBadge.jsx`
- `resources/js/Components/StandardAReportSubmissionTracker.jsx`
- `resources/js/Layouts/AuthenticatedLayout.jsx`
- `resources/js/Layouts/settingsNavigation.mjs`
- `resources/js/Pages/Admin/OfficeTat/Index.jsx`
- `resources/js/Pages/Admin/OfficeTat/ranking.mjs`
- `resources/js/Pages/Admin/Settings/HistoricalReportDetail.jsx`
- `resources/js/Pages/Admin/Settings/HistoricalReports.jsx`
- `resources/js/Pages/AWS/AwsReportSubmissionTracker.jsx`
- `resources/js/Pages/Bms/ReportSubmissionTracker.jsx`
- `resources/js/Pages/ConservationReports/Index.jsx`
- `resources/js/Pages/Engp/Index.jsx`
- `resources/js/Pages/Imea/MaintenanceReports.jsx`
- `resources/js/Pages/Ipaf/Index.jsx`
- `resources/js/Pages/ManagementPlans/Index.jsx`

### Migrations and permissions — required schema/provenance/configuration and capability definitions (2)

- `database/migrations/2026_10_09_000001_add_historical_report_provenance_and_office_tat_configuration.php`
- `database/migrations/2026_10_09_000002_grant_historical_reports_and_office_tat_permissions.php`

### Tests — source behavior, access/isolation, ranking, and UI contracts (7)

- `tests/Feature/HistoricalReportEncodingTest.php`
- `tests/Feature/OfficeTatAverageRankingTest.php`
- `tests/js/historicalReportEntry.test.mjs`
- `tests/js/officeTatEvidence.test.mjs`
- `tests/js/officeTatRanking.test.mjs`
- `tests/js/officeTatScope.test.mjs`
- `tests/js/settingsSidebar.test.mjs`

### Documentation — historical evidence and this final review (3)

- `docs/cds-smart-average-tat-ranking-shared-ui-verification-2026-10-09.md`
- `docs/cds-smart-tat-historical-followup-verification-2026-10-09.md`
- `docs/cds-smart-tat-historical-final-review-2026-10-10.md`

**Explicit exclusions:** `.recovery/`, `.env`, protected backups, external owner screenshots/briefs, unchanged files, and generated build output. None was staged. Credential screening did not inspect `.env`, `.recovery/`, backups, or remote URLs.

## Screening and remaining acceptance at the technical-review checkpoint

The exact proposed feature files were scanned after the event-cap changes for credential assignments/private-key material, named local host/schema/user-path identifiers, encoding artifacts, and trailing whitespace. That scan found no credential pattern, local identifier, encoding artifact, or trailing whitespace across the then-current 40 intended files, including untracked files. One pre-existing trailing-space line in the ranking verification note had already been removed in the earlier review. Historical install facts remain dated and generalized; local schema aliases and machine paths were removed. The later four-path delta was checked separately and is described below.

At that checkpoint, the supplied owner images were October 9 desktop/light captures of empty or zero-sample states; no browser tool was available. The authenticated browser follow-up and its remaining limits are recorded below.

The initial review made no local installation changes. Existing verification notes and `.recovery/` remain preserved. No file was staged, committed, or pushed.

## Authenticated browser acceptance follow-up — 2026-10-10, 01:59 Asia/Manila

### Observed defects and corrections

- The historical encode button was enabled but did not open its modal on the local HTTP origin. The browser reported `isSecureContext=false`, `crypto.randomUUID` unavailable, and `crypto.getRandomValues` available. The click handler called `crypto.randomUUID()` before opening the dialog, so it threw in this environment. Added `createUuid.mjs` to prefer the native API and otherwise create a UUID v4 using `getRandomValues`.
- The shared form modal left keyboard focus on the trigger behind its `aria-modal` dialog. `CrudFormModal` now supports a requested initial-focus selector, keeps Tab navigation within the dialog, and returns focus when it closes. Historical Reports focuses its searchable Report / Activity field. Escape closes an open selector list first; a later Escape closes the modal.

### Browser evidence and limits

- Settings → Historical Reports loaded and showed the intended empty state, with copy that distinguishes historical records from live Submission Tracking. No historical record existed, so source-module visibility and tracking exclusion could not be checked against an actual encoded row.
- The rebuilt encode modal opened on the same insecure HTTP origin. Its Report / Activity search returned the ENGP Weekly Accomplishment workflow; the unsaved form exposed its CENRO, reporting year/period, original milestone dates, and required original file controls. Selecting the workflow invoked only the read-only ENGP period-options and deadline-preview endpoints. The form was canceled; no historical record was submitted.
- Keyboard checks confirmed initial focus on the workflow field, first Escape closing its options while leaving the modal open, Tab wrapping from the last control to the first, reverse-Tab trap handling, second Escape closing the modal, and focus returning to the encode trigger. The reverse-Tab branch was verified with a synthetic keyboard event; BrowserAct's chord input did not simulate Shift+Tab directly.
- Office TAT showed only PAMO and CENRO tabs. The existing Q4 view had zero completed live samples and N/A averages, so a populated ranking could not be reviewed without introducing records. The date calendar and month/year picker opened; the office-name search matched the existing CENRO row locally. Dark mode was visually inspected and then restored. Screenshots were taken at a 1920-pixel desktop viewport.
- Validation errors after submitting incomplete data, pending/save progress, protected preview of an existing attachment, responsive narrow layout, and populated ranking remain unverified. No historical save, configuration change, custody/routing action, or Drive operation was performed.

### Fresh checks and updated path inventory

- Focused JavaScript checks passed **38/38** across `createUuid.test.mjs`, `historicalReportEntry.test.mjs`, `globalControlRendering.test.mjs`, and `sharedFilterPresentation.test.mjs`. The production build passed with **1,446 modules**. These are separate from the earlier 7/7 PHP event-cap result.
- The original 40-file proposal above now has four additional paths, for **44 actual paths = 17 tracked modifications + 27 untracked files**:
  - `resources/js/Components/Crud/CrudFormModal.jsx`
  - `resources/js/Components/Form/ScopedOptionSelect.jsx`
  - `resources/js/Utils/createUuid.mjs`
  - `tests/js/createUuid.test.mjs`
- No file is staged. No commit or push was requested or made. Generated build output and `.recovery/` remain excluded.

## Historical Reports filter layout follow-up — 2026-10-10, 02:41 Asia/Manila

### Focused correction

- The Historical Reports page passed `grid gap-4 sm:grid-cols-2` as `FilterToolbar.panelClassName`. That styles the whole portal panel, including its title and close button, so the heading occupied the first grid cell, Search sat beside it, and Module wrapped to the next row. The controls now use a grid wrapper inside the toolbar content. Search takes the larger column; Module aligns beside it at desktop widths and stacks below Search under the 640px `sm` breakpoint. Shared floating fields, dark-mode surfaces, the Settings shell, page header, and Encode action are unchanged.
- Escape in the Module picker also closed the outer filter panel because the toolbar document handler ignored the picker’s `preventDefault()`. `FilterToolbar` now yields an already-handled Escape to its child control. The first Escape closes Module options and returns focus to Module; the next closes the filter panel and returns focus to its trigger. A regression check covers this shared behavior.
- Search and Module remain bound to the same `filters` values and route query keys. Search uses the same read-only Inertia GET; the source selector keeps its existing option keys. Clear all and removable chips are unchanged.

### Browser evidence and limits

- In the authenticated desktop browser, the corrected popover was inspected and captured at **1920px `window.innerWidth`** (document scroll width 1910px) in light and dark modes. The Search field is wider than Module, both controls align, and the shared Module options portal remains visible over the table without clipping. Theme was restored to light mode and the page query was cleared.
- Read-only browser checks retained `search=filter-layout-proof`, then selected ENGP and retained both `search=filter-layout-proof&source=engp`. Clear all removed both query values and returned the existing empty result state. The dataset has no historical rows, so result pagination and page changes could not be exercised; no records were created.
- The Encode modal still opened and the chooser returned the existing ENGP Weekly Accomplishment workflow. The selection invoked the existing deadline-preview request; the form was canceled without saving.
- A narrow viewport could not be set through this BrowserAct direct-browser session: `window.resizeTo` left the viewport at 1920px, the available CDP relay rejected `Emulation.setDeviceMetricsOverride`, and its page-metrics attempt timed out without changing the viewport. The source layout uses a one-column grid below `sm` and the shared portal caps its width at the viewport minus 24px, with 12px side margins; this narrow behavior is **not browser-verified**. Existing acceptance limits remain: saved-record visibility, protected attachment preview, and populated ranking were not exercised.
- Screenshots are in `%TEMP%`: `cds-historical-report-filter-layout-desktop-light-20261010.png`, `cds-historical-filters-corrected-dark-20261010.png`, and `cds-historical-filter-module-menu-desktop-20261010.png`.

### Fresh checks and current inventory

- **39/39 JavaScript checks passed** across `createUuid.test.mjs`, `historicalReportEntry.test.mjs`, `globalControlRendering.test.mjs`, and `sharedFilterPresentation.test.mjs`. The production build passed with **1,446 modules**. `git diff --check` passed after this follow-up.
- The current proposed inventory is **46 actual paths = 19 tracked modifications + 27 untracked files**. Compared with the 44-path 01:59 browser follow-up, this task adds these two tracked paths:
  - `resources/js/Components/Form/FilterToolbar.jsx`
  - `tests/js/sharedFilterPresentation.test.mjs`
- `HistoricalReports.jsx` and this review note were already present in the untracked feature inventory. No path was staged, committed, or pushed. Generated build output and `.recovery/` remain excluded.

## Historical source display correction and isolated Office TAT demo — 2026-10-10, 03:48 Asia/Manila

### Historical source-module presentation

- Conservation source list and detail now use the shared `ConservationHistoricalEvidenceProjection` for rows with immutable historical provenance. It reads original milestone evidence, uses the existing source timeliness calculator, and projects values onto a read-only model copy; it creates no custody/routing events or source writes.
- A fully evidenced historical PAMB row presents its original office dates, source timeliness, and historical completion label consistently with Historical Reports. Incomplete provenance presents missing historical evidence instead of a current pending handoff. Live rows retain the existing status and timeline path.
- The focused `HistoricalReportEncodingTest.php` source-view regression passed: **1 test, 42 assertions**, covering completed historic evidence, incomplete historic evidence, and an unaffected live row. This did not save or modify the owner’s Conservation 38 record or file.

### Temporary demo runtime and outcomes

- A separate file-backed SQLite database, isolated Laravel storage/cache/session/config paths, and a local PHP server were created in the task-owned temp directory named by the ownership marker. Laravel’s resolved connection was checked against the exact SQLite target before migrations and fixture writes. The temporary database contains only synthetic fixtures; the normal home database and its Incoming/Outgoing/History were not used. Conservation 38 is explicitly excluded from the fixture manifest.
- Preview endpoint details are intentionally omitted. A synthetic approved account with the existing CDS Admin role has the Office TAT, Technical Reports, and Historical Reports view permissions. The isolated runtime uses a distinct session cookie, local file sessions/cache, array mail, and a null queue. Its shared notice reads **DEMO DATA**; the normal runtime notice flag defaults off.
- The independent expected ledger was written before querying the analytics service. Per-office counts, elapsed seconds, averages, unconfigured N/A metrics, authoritative receipt timeliness, historical separation, and the August 2026 / CBEP filter were then checked against the actual service response. The production client ranking helper was also run against that response to check shared ranks and document-weighted averages.

| Group | Office | Received | Completed | Pending | Mean TAT | Shared rank |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| PAMO | Demo PAMO A | 3 | 2 | 1 | 2 days | 1 |
| PAMO | Demo PAMO B | 2 | 2 | 0 | 4 days | 3 |
| PAMO | Demo PAMO C | 4 | 3 | 1 | 2 days | 1 |
| **PAMO total** |  | **9** | **7** | **2** | **18/7 = 2.57143 days** |  |
| CENRO | CENRO Demo A | 3 | 2 | 1 | 2 days | 3 |
| CENRO | CENRO Demo B | 2 | 2 | 0 | 1 day | 1 |
| CENRO | CENRO Demo C | 3 | 2 | 1 | 1 day | 1 |
| **CENRO total** |  | **8** | **6** | **2** | **8/6 = 1.33333 days** |  |

- The seven completed PAMO samples total 18 days. For example, PAMO A’s 1-day and 3-day completed samples average 2 days; its pending sample is excluded. Overall PAMO therefore uses 18/7 completed documents, not nine received documents. CENRO uses eight completed days divided by six completed documents. Equal means share rank; the next distinct rank follows the number of items already ranked (1, 1, 3).
- Genuine zero-day samples have equal-time receipt and handoff events with receipt ordered first. CENRO C’s completed correction record has two half-day segments and counts once as a one-day completed document. Its other correction example contains a completed one-day segment followed by a new unfinished correction cycle; it remains pending and contributes no completed duration.
- Authoritative PENRO receipt and source-calculator deadlines yield **PAMO 4 on time / 3 late of 7 eligible** and **CENRO 3 on time / 3 late of 6 eligible**. The two pending documents in each group have no authoritative PENRO receipt and are outside this timeliness denominator. No intake event was used as a substitute PENRO receipt.
- No demo office has a TAT limit or volume target configured. Average TAT and ranks remain available. TAT compliance and volume are N/A because their respective limit/target is missing; the composite score is N/A because it requires all three configured components. Those are intentionally unconfigured demo results, not zero performance.
- Separate Q3 manual historical evidence returns a **3.5-day CENRO mean** from two original release-to-receipt intervals of 2 and 5 days. A historical PA record is attributed to its PAMO but carries no measured PAMO interval, so the PAMO historic average remains N/A. These samples have provenance only, no live routing events, and are excluded from Q4 live rankings and means.
- Authenticated local HTTP checks used the synthetic account’s normal login form and confirmed the rendered page component’s Inertia data for both live office groups, the Q3 historic group, and the DEMO DATA notice. The CENRO August 2026 / ENGP CBEP source filter returned exactly two received and completed samples totaling two days.

### Browser evidence and remaining visual acceptance

- The requested visual browser review is **pending**. No demo screenshot is recorded. BrowserAct was not installed in this session; the package-feed request was blocked by the network tunnel. A fresh isolated Chrome DevTools process terminated with Windows `Access is denied` while starting its crashpad/Mojo channel, and the existing Chrome processes expose no DevTools port. I did not claim the authenticated HTTP payload checks as browser rendering evidence.
- Light/dark visual comparison, screenshot capture of each populated category, browser filter interaction/reset, and narrow viewport review remain pending owner/browser access. The preview itself remains available at the URL above for direct review.

### Runtime lifecycle, verification, and checkpoint

- The exact runtime/database path, run ID, source fixture IDs, aggregate manifest, and PHP server process identity are recorded in `.cds-office-tat-demo-ownership.json`, `fixture-ids.json`, `expected-ledger.json`, `actual-analytics.json`, `seed-summary.json`, and `server-process.json` in the temp directory. These files contain no demo password. The synthetic login password is supplied separately to the owner.
- The isolated preview had task-owned restart and cleanup tooling, guarded by ownership, the exact SQLite target, and listener identity. **Cleanup has not been run.** It preserves repository changes and excludes the normal database, public files, .recovery/, .env, and backups.
- The required production build passed with **1,446 modules**. `git diff --check` passed after this update. Current proposal inventory: **48 paths = 20 tracked modifications + 28 untracked feature/documentation files**, with **0 staged**; `.recovery/` and ignored generated build assets are excluded. No commit or push was made.

## Historical indicator and Office TAT modal cleanup - 2026-10-10

### Conservation source table and historical navigation

- Conservation historical rows keep their evidence-based timeliness and status badges, with the compact Historical badge in the record type cell. The redundant original-date and manual-evidence sublines are hidden for Conservation only; live rows and other modules' historical table displays are unchanged.
- The standard tracker now forwards the caller's historical source. This corrects the Conservation page fallback that could send a Conservation record to the IMEA detail route.
- The shared Historical badge uses the protected named settings.historical-reports.show route with its source and record id only when auth.canViewHistoricalReports is true. Users without that permission see a static badge. Pointer and keyboard events on the link stop before the source row can open its details modal.
- The Office TAT settings modal now receives an Iconify React Icon element, following the existing Historical Reports modal pattern. No configuration was saved.

### Verification and runtime

- historicalReportEntry.test.mjs: 2 tests passed. officeTatScope.test.mjs: 4 tests passed. Each Node test module was run directly after the multi-file Node runner hit Windows spawn EPERM.
- HistoricalReportEncodingTest.php focused Conservation source-view regression: 1 test and 42 assertions passed using SQLite :memory:.
- Production frontend build passed with 1,447 modules. The first build found and exposed a same-basename helper resolution issue; the helper was renamed and the build then passed.
- git diff --check passed. No paths were staged. The temporary isolated Office TAT preview remained available for owner review. No office configuration, normal database, demo fixture, or Conservation 38 record was written by this phase.
- No new browser screenshot or visual browser acceptance was recorded; the previous browser-access limitation and pending visual review remain as documented above.
- Current Git status reports 20 tracked modifications and 26 untracked entries outside .recovery, with 0 staged. .recovery and ignored build output remain excluded from the review inventory.

## ENGP historical display correction — 2026-10-10

### Cause and correction

- The ENGP source list serialized the stored live submission fields and actual CENRO release events. The historical detail page separately projected original dates from `HistoricalReportOrigin` onto a model copy. Because manually entered historical ENGP dates are held in the immutable origin, the list showed dashes for release, receipt, days complied, and timeliness. Its monitoring resolver also received the empty live receipt and evaluated the row as not yet submitted; its routing status came from the same empty live fields.
- Added `EngpHistoricalEvidenceProjection` and used it in the ENGP source serializer and canonical historical detail. The projection reads the origin at request time, applies original evidence to a clone for existing ENGP deadline/timeliness calculations, and marks missing evidence explicitly. It does not write source or origin fields or create routing events. Historical detail now distinguishes original routing evidence from live CENRO release events. Live ENGP rows stay on their existing path.
- July 2026 / CENRO Demo B now displays original CENRO release **July 13**, PENRO receipt **July 15**, source deadline **July 20**, **5 days complied**, and **Outstanding**, matching Historical Reports detail 9. The October 10 encoding timestamp remains audit metadata. The 2 calendar days from release to receipt is a separate interval and is not substituted for the source's 5 days-complied value. With no recorded Regional endorsement, routing remains **Pending Regional Endorsement**.
- Rows with no receipt show “Missing historical evidence” for days and monitoring instead of a current-date result or zero. A release-only row remains “Pending Receipt by PENRO.” Rows without any origin remain explicitly missing. A live comparison row retains its stored receipt, source-calculated days/rating, and current routing status.

### Focused verification

- Before the PHP feature checks, the test connection was confirmed as SQLite `:memory:` with `DB_URL` empty. The ENGP projection feature passed **1 test / 75 assertions**, including the July source/detail match, October audit metadata, sparse evidence, live-row behavior, forbidden detail access, historical queue-count stability, and unchanged source/origin, routing events, release events, snapshots, tracking references, notifications, and archives.
- The focused Conservation source-view regression passed **1 test / 42 assertions**. The direct ENGP JavaScript regression passed **1 test**. PHP syntax checks passed for the projector, both affected controllers, and the feature test; `git diff --check` passed.
- The production build passed with **1,447 modules**. The sandbox build first failed because Vite could not spawn a Windows helper (`EPERM`); the same build completed successfully with the approved elevated retry.
- No new browser screenshot or visual browser review was obtained. Owner check: open ENGP → CBEP, find CENRO Demo B / July 2026, compare its two original dates and Outstanding value with Historical Reports detail 9, and check one live row. The earlier notes about the activity label and June/July historical analytics eligibility were not changed or used to infer a Q3 total.

### Preview and worktree

- A fresh listener check initially found no process matching the isolated preview. The recorded ownership marker identified an isolated SQLite demo that excluded both the normal home database and Conservation 38, so the prepared preview was restarted. A fresh check confirmed the preview was available again. No demo fixtures or normal database records were changed.
- Current Git status shows **21 tracked modifications** and **28 untracked status entries**, with **0 staged**. Some untracked entries represent directories (including `.recovery/`), so this is a status-entry count, not an exact source-file inventory. Existing feature work and `.recovery/` remain intact; build output is ignored. No commit or push was made.

## Semestral/annual Office TAT and Historical Reports row details — 2026-10-10

### Assessment periods and evidence

- Office TAT now selects **Semestral** or **Annual**. Canonical keys are `YYYY-H1`, `YYYY-H2`, and `YYYY`; the omitted-period default is the current Philippine calendar year and semester. H1 is Jan 1–Jun 30, H2 is Jul 1–Dec 31, and Annual is Jan 1–Dec 31, bounded by the results-as-of date.
- Cohort queries use Asia/Manila calendar bounds with an inclusive start and exclusive next-period midnight. Legacy `YYYY-Q1/Q2` keys normalize to H1, `Q3/Q4` to H2, and `YYYY-MM` to its containing semester; noncanonical links redirect to the canonical query. Invalid values fall back to the current semester. Annual keys and response descriptors have no semester value.
- Existing source requirement year/period, workflow, protected area, evidence, category, and as-of filters remain available. The period window does not filter source reports by their own cadence; monthly, quarterly, semestral, and annual source records stay eligible when their receipt cohort falls in the selected range.
- Live membership and timeliness remain anchored to accepted receipt events; historical membership and timeliness remain anchored to the immutable original receipt date. October `encoded_at` remains audit metadata. Live correction cycles are reconstructed through the as-of date, including handoffs after a semester boundary, and their accumulated document durations are not truncated at period end.
- Annual means are computed from eligible per-document totals/counts. Verified example: H1 durations 1 and 3 days produce a 2-day mean; H2 duration 5 days produces a 5-day mean; Annual produces `(1 + 3 + 5) / 3 = 3 days`. A preserved old quarter-key configuration does not get rewritten or inherited by a new half-year/year key. Without matching configuration, secondary target/score values stay N/A while measured TAT and ranking remain available.

### Historical Reports interaction

- The Settings table now opens a selected record from row click, Enter, or Space. The redundant View action column is removed; the nested Historical badge remains a direct, authorized canonical detail link and does not trigger the row action.
- The row modal fetches JSON from the existing protected show route. The same controller authorization and source scope checks run before details or attachment descriptors are returned. Direct show links continue to receive the original Inertia detail page.
- `HistoricalReportDetail` shares its badges, original-date timeline, timeliness evaluation, source facts, protected attachments/preview, audit, and Open source module action with the embedded modal. The shared `CrudFormModal` provides loading/error/retry/close states, overlay and Escape close, scrollable detail content, and focus return to the selected row. Aborted requests and request guards prevent stale results from replacing a new selection.
- `CrudTable` ignores nested links and controls for row activation. The protected document preview is portaled above the detail modal, traps and restores focus, and consumes Escape while the parent modal suspends its key handling.

### Verification and runtime

- The read-only database preflight reported SQLite `:memory:` with `DB_URL` empty. Focused `OfficeTatAverageRankingTest.php` and `HistoricalReportEncodingTest.php` passed: **19 tests / 584 assertions**. Coverage includes canonical/default/legacy periods, H1/H2/year boundary membership, leap day, annual document weighting, old configuration isolation, a correction cycle crossing June/July, Conservation/ENGP JSON detail payloads, denied JSON access, and unchanged source/routing data.
- Seven focused JavaScript modules passed: **16 tests** for assessment selection, row click/keyboard activation and nested controls, historical detail links/modal state, ranking/evidence/scope helpers, and ENGP original-evidence presentation. Node's default runner hit Windows `spawn EPERM`; the direct test run passed with `--test-isolation=none`.
- PHP syntax checks and `git diff --check` passed. The single combined production build passed with **1,449 modules**. The sandbox build failed at Vite's Windows path-resolution helper with `spawn EPERM`; the elevated retry passed.
- A fresh listener check confirmed the isolated preview was available for owner review. No demo fixture or normal database records were changed in that check.
- No new browser screenshot or visual browser acceptance was obtained. Owner visual check: inspect H1, H2, and Annual for PAMO and CENRO; confirm Annual hides Semester and has no semester query state; open Conservation and ENGP historical rows by pointer and keyboard; close/reopen while checking list position and focus; and open/close an available protected attachment preview. Do not save Office TAT settings during review.
- Current `git status --short` reports **23 modified tracked paths and 31 untracked status entries**, with **0 staged**. Some untracked entries are grouped directories, so this is a status-entry count rather than a source-file inventory; `.recovery/` and generated build output remain excluded from any exact file count. No commit or push was made.

## Modal motion and separate historical report counts — 2026-10-10 follow-up

### Changes and findings

- Historical Reports details now suppress the duplicate header close button while retaining the footer Close details action. The shared modal keeps its existing defaults; only this details dialog opts into the soft entrance/exit motion. Reduced-motion preference opens immediately and closes without the animation delay. Close, switch, and retry invalidate or abort stale protected detail requests; a repeated click on the already active row does not start another request.
- Historical Reports row focus styling is now visible for keyboard focus while avoiding a persistent ring after pointer-driven open and focus restoration. Nested document preview behavior and other modal callers retain their prior defaults.
- Office TAT now counts eligible historical report records separately from measured PAMO TAT samples. Conservation record 38 has an authorized historical origin and an original PENRO receipt date of 2026-03-25, so it is included in the Annual 2026 and H1 2026 cohorts with the recorded filters. It contributes 1 historical report and 0 measured PAMO samples; its average and rank remain N/A. It is excluded from H2 and Quarter 2, and an unauthorized PAMO scope returns 0. Its CENRO/PENRO dates are evidence for cohort membership, not a PAMO receipt-to-handoff measurement.
- The related display now labels historical report volume separately from live documents, and ranking details show historical reports and measured samples in separate columns. The production scoring calculation was left unchanged.
- The record inspection was read-only. It confirmed workflow regular_pamb, activity Regular PAMB, document Minutes, reporting period Quarter 1, protected area Aliwagwag Protected Landscape (APL), assigned PAMO APL-PAMO, target office CENRO Baganga, and encoded_at 2026-10-10 02:51:13 +08:00. The original regional endorsement date is 2026-03-31. No PAMO interval was inferred from these events.

### Verification retained for this follow-up

- Focused PHP feature run: `tests/Feature/OfficeTatAverageRankingTest.php` passed 12 tests and 99 assertions. The isolated regression covers Annual/H1 inclusion, H2/Quarter 2 exclusion, separate report/sample counts, and unauthorized scope.
- Focused JavaScript runs passed: `historicalDetailRequest.test.mjs` 5/5, `officeTatRanking.test.mjs` 5/5, `officeTatEvidence.test.mjs` 1/1, and `crudRowActivation.test.mjs` 2/2.
- PHP syntax checks passed for `app/Services/OfficeTatAnalyticsService.php` and `tests/Feature/OfficeTatAverageRankingTest.php`. `git diff --check` completed without output; the index was empty at this check.
- The production build had already succeeded after an elevated retry: 1,450 modules, 21.28 seconds, exit code 0. The initial sandbox attempt failed during Vite startup with Windows `spawn EPERM`. No source edits were made after the successful build; this follow-up appends review documentation only, so the build was not repeated.
- Browser automation was unavailable in this environment, so no fresh screenshot, visual acceptance, or measured click-to-detail timing is claimed. A fresh listener check found no process matching the preview ownership record. The preview was not started or stopped, and its demo marker was not inspected.

### Owner visual check

- Open and close a Historical Reports detail with the pointer and confirm the row has no persistent focus ring; repeat with Enter and Space and confirm keyboard focus remains visible and returns to the selected row on close.
- While a detail request is pending, close and reopen it, switch to another row, and retry a failed request; confirm stale content does not replace the current selection.
- Open an available attachment preview and check Escape and focus return, then inspect light/dark themes, a narrow viewport, and reduced-motion behavior.
- For Conservation 38, confirm the selected period reports one historical report and zero measured PAMO samples, with average and rank shown as N/A. Do not save Office TAT settings during this review.

### Follow-up file inventory and repository state

Follow-up implementation and documentation paths:

- `app/Services/OfficeTatAnalyticsService.php`
- `resources/css/app.css`
- `resources/js/Components/Crud/CrudFormModal.jsx`
- `resources/js/Components/Crud/CrudTable.jsx`
- `resources/js/Pages/Admin/OfficeTat/Index.jsx`
- `resources/js/Pages/Admin/OfficeTat/ranking.mjs`
- `resources/js/Pages/Admin/Settings/HistoricalReports.jsx`
- `resources/js/Pages/Admin/Settings/historicalDetailRequest.mjs`
- `tests/Feature/OfficeTatAverageRankingTest.php`
- `tests/js/crudRowActivation.test.mjs`
- `tests/js/historicalDetailRequest.test.mjs`
- `tests/js/officeTatRanking.test.mjs`
- `docs/cds-smart-tat-historical-final-review-2026-10-10.md`

- At this follow-up check, `git status --short` showed 24 modified tracked paths and 33 untracked status entries, including grouped directories. There were 0 staged paths. `.recovery/` was not inspected. No commit or push was made, and no database, attachment, environment, or demo data was changed.

## Runtime recovery: corrupted Vite JavaScript assets - 2026-10-10

### Confirmed failure and scope

- The reported `ReportDetailsContext-xUDM7kpp.js` manifest asset was 183 bytes of NUL bytes (SHA-256 `F147DFC00BC88266987B75F69065432C7C9730DBECE276E2C913B47080C7C0BA`). The matching React source `resources/js/Components/Crud/ReportDetailsContext.jsx` is valid. Node reproduced `SyntaxError: Invalid or unexpected token` on the emitted file at byte 1.
- The normal Apache host served the same manifest and the same 183-byte asset as the checkout: GET 200, `Content-Type: text/javascript`, no `Content-Encoding`, and identical served/disk SHA-256. This rules out a wrong host or transport encoding; cache clearing was not needed to establish the failure because the server itself returned malformed bytes. The Apache access log shows the chunk request returned 200 while the corrupted artifact was present.
- Eight additional manifest JavaScript assets were also entirely NUL-filled: `PrimaryButton-CoYVh67H.js` (167 bytes), `ProtectedAreaSelect-1vw_K8Ik.js` (383), `StatusBadge-CCYO_D6I.js` (642), `TargetOfficeSelect-D5CYTC-O.js` (278), `dateConductedRanges-C0CmreOp.js` (560), `dateInput-CQHKoIy_.js` (134), `moneyFormatters-BtSVEzeg.js` (123), and `ReportSubmissions-DlTqxd4r.js` (209). This confirms a broader damaged build output and explains failures across consumers of shared UI/report chunks. The process that zero-filled the files was not identifiable from the available logs.
- Apache recorded GET `/conservation-reports/regular_pamb` as 409 then 200 at 15:06:16-17 +0800, followed by the context chunk as 200 at 15:06:18 and the route as 200 at 15:06:23. The route is inside the `auth` group and its controller returns Inertia component `ConservationReports/Index`. No Laravel error block was recorded at 15:06 on 2026-10-10; the evidence points to the client failing to parse the delivered chunk after the server returned the page. The available evidence identifies the frontend parse error as a cause; no separate server exception was confirmed in the Laravel log.
- `ReportDetailsContext` is a shared chunk. Its source is used by `CrudDetailsModal`, `CrudSection`, and `FilePreviewPanel`; `CrudDetailsModal` has 16 page importers, including ENGP, IPAF, BMS, Technical Reports, Management Plans, IMEA, and Submission Tracking. The Vite manifest graph connected the chunk to 83 page entries. This is graph reachability, not a claim that 83 routes were browser-tested. The separate zero-filled `ReportSubmissions` page chunk also affected BAMS report loading.

### Repair and regression guard

- Regenerated the existing production build without changing report/UI behavior, permissions, server routing, or the opt-in historical modal motion. All nine previously corrupted files retained their expected lengths but now contain non-NUL JavaScript with changed hashes.
- Added `scripts/verify-vite-build.mjs` to the existing `npm run build` chain. It follows imports and dynamic imports from `resources/js/app.jsx`, checks manifest targets and relative generated imports, rejects empty/NUL JavaScript, and parses each reachable asset as an ESM module with the installed Babel parser. This closes the gap that allowed a successful build command to leave unusable output.
- A pre-rebuild copy of the reported chunk and its hash were retained under `%TEMP%` as `cds-reportdetails-prebuild-20261010-152102-518.js`; hashes for all nine malformed assets are in `%TEMP%\cds-zero-js-prebuild-20261010.json`.

### Verification after repair

- The final elevated `npm run build` passed after the sandbox attempt hit the known Windows `spawn EPERM`. Vite transformed 1,450 modules, completed in 3.03 seconds, and the integrated guard verified 152 ESM assets and 85 generated relative imports reachable from the app entry.
- Production ESM imports passed for the rebuilt context chunk and 10 report/page entries: Conservation Reports, ENGP, Technical Reports, IMEA Maintenance Reports, IPAF, Management Plans, Submission Tracking, Historical Reports, Office TAT, and BAMS Report Submissions. The BMS Index import in bare Node stopped at its expected browser-only `window` reference; that was not treated as a syntax failure. The first Vite SSR harness also encountered React CommonJS interop (`module is not defined`), so it was not counted as a page failure.
- Apache now serves all nine formerly NUL-filled assets with HTTP 200, JavaScript MIME type, and bytes whose SHA-256 matches the rebuilt disk files. The served manifest also matches `public/build/manifest.json`. The context asset ETag changed; a conditional request using the old ETag returned 200 with the corrected body. No broad browser cache clearing was performed.
- A fresh Regular PAMB request without an authenticated browser session correctly redirects to Login (302). The existing Apache log records the earlier protected route response as 200, and source maps that route to `ConservationReports/Index`; no response body or authorized props were captured.

### Browser limitation and owner smoke check

- Browser automation tools and an existing Chrome/Edge process with a debugging port were unavailable. I could not verify the authenticated Inertia component/props, a fresh browser Console/Network panel, or the generic fallback disappearing visually after rebuild. No browser was installed or launched, and the authenticated session was not accessed or altered.
- In the existing authenticated browser, reload Regular PAMB and confirm `ConservationReports/Index` renders without the parse error or generic fallback. Then open the sidebar destinations available to that account that share report/UI chunks (Conservation, ENGP, Technical Reports, BMS, IMEA, IPAF, Management Plans, Submission Tracking, Historical Reports, and Office TAT); confirm page loads and one normal read-only details/preview interaction. Keep disabled/Coming Soon entries unchanged and do not save, receive, forward, release, or archive records.

### Recovery worktree state

- Recovery source changes are limited to `package.json` and `scripts/verify-vite-build.mjs`; this dated review entry records the evidence. The `public/build` output is generated/ignored. Existing modal motion remains opt-in; no modal expansion, PHP behavior, role/grant, database, route, attachment, `.env`, `.recovery/`, or demo-data changes were made.
- At the final check, `git status --short` showed 25 modified tracked paths and 34 untracked status entries (including grouped directories), with 0 staged and 0 cached paths. The recovery implementation paths are `package.json` and `scripts/verify-vite-build.mjs`; this review document was appended as well. Generated `public/build` remains ignored. `.recovery/` was left untouched and uninspected. No paths were staged, committed, or pushed.

## Historical badge navigation and shared modal motion - 2026-10-10

### Scope and implementation

- Historical badges shown from module/source records now navigate to the named `settings.historical-reports.index` route when the viewer has the Historical Reports view capability. The badge stops click and keyboard bubbling so it does not activate its source row; native link behavior remains available for modifier, middle-click, and back navigation. In the Settings table the badge is rendered as passive text, leaving row click/Enter/Space to open the existing details modal. The protected per-record show route still supports its HTML page and JSON response.
- Added shared modal motion tokens and lifecycle: 240 ms fade with a 5 px upward offset and 0.99 starting scale, reduced-motion immediate completion, mounted exit state, transition plus timer completion, generation checks for close/reopen races, focus trap/restoration, body scroll locking, topmost visible modal Escape handling, and native keyboard handling for nested selectors.
- Applied the shared lifecycle across CRUD form/detail dialogs, confirmations, password and success dialogs, document previews, AWS import/view/edit/export, IMEA assessment and facility dialogs, IMEA report detail dialogs, Calendar dialogs, Protected Areas, Module Management, BMS annex-header editing, and the shared calendar report detail. Non-dialog page overlays such as the mobile navigation backdrop and the Protected Areas standalone edit page are outside the modal layer.

### Backend and integration contract audit

- No additional backend defect was demonstrated by this follow-up, so no speculative server change was made. The review traced source-specific create permissions and organizational scope through the historical workflow catalog/encoder; source models and origin rows are written transactionally, origin evidence is separate from live routing events, same-key retry returns one record, and ENGP same-office/period conflict does not update an existing report.
- The direct show route checks the origin, source model, historical flag, source permission or encoder ownership, and organization scope. Attachment previews use `ProtectedAttachmentService` and its source-specific authorization/record resolver. Existing source update/delete controllers call `assertMutable`; submission tracking lists and action endpoints exclude or reject historical records. Office TAT includes manually entered historical intervals in separate evidence fields, not observed PAMO custody samples. These were audited against source code and exercised by the focused feature run below; not every workflow was separately populated in a fixture.

### Fresh verification

- `php artisan test --compact` with process-local `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and empty `DB_URL`: 63 tests passed, 1,682 assertions across Historical Report Encoding, Office TAT Average Ranking, and five Submission Tracking feature files.
- Serial Node test run (`--test-isolation=none`): 44/44 passed across modal-layer, historical badge/detail, row activation, protected preview, ENGP historical display, Settings navigation, and Office TAT presentation/evidence/ranking checks.
- `npm run build`: passed after the sandboxed attempt hit Windows `spawn EPERM`; Vite transformed 1,452 modules. The integrated build guard passed with 152 reachable ESM assets and 85 generated relative imports.
- The normal Apache host served a manifest byte-for-byte equal to `public/build/manifest.json`. SHA-256 matched local and served `HistoricalReports-BLXKvCGk.js` (29,492 bytes, `7EFEFE2E61FC683F9E78BC329B3E7FE12B1DD1F9669054F278EF25F078E02406`) and `app-BSCo673n.css` (170,631 bytes, `BD75CC0260566D82861F668308E76528605852B21E3C7E89882175CC8A0A99DD`).
- `git diff --check` passed with only Git's line-ending normalization notices for three existing JSX files. At this check `git status --short` had 42 modified tracked paths and 36 untracked entries (including grouped directories), with 0 staged paths. `.recovery/` was not inspected. No commit, push, reset, or clean was performed; no database, attachments, environment values, grants, settings, demo records, or browser caches were changed.

### Browser verification still pending

- No browser automation or authorized existing browser session was available. The production assets were verified over the normal Apache host, but the authenticated Historical Reports row/badge interactions and visual modal motion/reduced-motion behavior were not exercised in a browser. Owner verification should cover mouse and Enter/Space activation from both surfaces, modifier/middle-click/back behavior, focus return, open/close/reopen, stacked Escape, dropdown-first Escape, narrow/light/dark layouts, and reduced motion.

## CRUD form initial focus and Close modal tooltip - 2026-10-10

### Source trace and fix

- The Regular PAMB page composes `ConservationReports/Index` → `StandardAReportSubmissionTracker` → the shared `Bms/ReportSubmissionTracker` → `CrudFormModal`. The create/edit form did not pass an initial-focus selector. Its header X precedes the form controls in DOM order, and the shared modal hook selected and focused the first focusable element when the dialog opened.
- `CrudModalHeader` wraps the X in the shared `Tooltip`. `Tooltip` starts its 240 ms show timer on both focus and mouse enter; it has no native `title`. That means programmatic focus on the X is sufficient to show the tooltip and matches the screenshot's focus ring. A pointer hovering the newly mounted X can independently show it; without an authorized browser session I could not determine whether that also happened in the screenshot.
- The first report field is Target Office, a `ScopedOptionSelect` input whose focus handler opens its list. Focusing that field would also be disruptive. `CrudFormModal` now defaults to the labelled dialog panel (`:scope`, already `tabIndex=-1`) and calls focus with `preventScroll`; explicit selectors remain authoritative. The shared focus helper skips disabled, hidden, and aria-hidden candidates when resolving requested/fallback controls. Report values, defaults, dates, calendars, attachment handling, and submit callbacks were not changed.
- This default applies to the existing `CrudFormModal` caller families: Admin Office TAT and Historical Reports, BMS report/threat forms, Compliance Alerts, ENGP, IMEA maintenance reports, IPAF, Management Plans, and Submission Tracking. Conservation Reports, AWS report forms, and BAMS reuse the shared report tracker. Historical Reports' explicit `#historical-workflow` initial-focus selector remains in place. Details, confirmations, and document previews do not use the `CrudFormModal` default; their modal-layer, Escape, and topmost behavior was not changed. The close X keeps its accessible name, mouse-hover tooltip, keyboard operation, and visible focus styling.

### Focused verification and limits

- New `modalInitialFocus.test.mjs` exercises the shared focus helper used by `useModalMotion`: default form-shell focus avoids the header focus tooltip and Target Office list, keeps fixture field values unchanged, explicit workflow focus is honored, and an unavailable requested target falls back to an enabled visible control.
- Focused Node checks passed **31/31** across the new focus regression, modal-layer behavior, historical detail request lifecycle, and protected preview request/disposal behavior. The Regular PAMB/historical entry checks passed **2/2**. These are headless checks; they do not prove the browser's `document.activeElement`, hover hit testing, or visual tooltip state.
- Existing shared CRUD form/details presentation checks passed **2/2**, and the danger delete-confirmation presentation check passed **1/1**. Details and confirmations retain their separate components and initial-focus paths.
- An additional `SubmissionTrackingPreviewContract.test.mjs` run passed 22 of 25 tests; three existing server-rendered `DocumentPreviewDialog` assertions received empty markup because the portal-only dialog returns no markup without a browser `document`. Its relevant preview request checks are covered by the passing `protectedDocumentPreview.test.mjs`; no source change was made for those SSR-only failures.
- `git diff --check` passed. The required final `npm run build` passed after the sandbox attempt hit the known Windows `spawn EPERM`: Vite transformed 1,453 modules and the integrated guard verified 152 reachable ESM assets and 85 generated relative imports. Apache served the new `CrudFormModal-BU9Y0UDY.js` and `ReportSubmissionTracker-BTnxcj50.js` at their generated paths with exact local/served SHA-256 matches (`23FEC867D35788E55695439DFCBF7BAF14FD5F6C982D58C362DF5F7A36DB2791` and `91A63F689637A921473EB1BBFE48161BC8AC1F506E55D5E66E998DD59B935D8B`).
- Browser verification remains pending: browser automation, an installed browser executable, and an authorized existing browser session were unavailable. Therefore the actual pointer/focus sequence, mouse hover, keyboard tooltip timing, close/reopen focus return, and reduced-motion visual result still need an authenticated owner smoke check. The source-level focus trigger is identified; stationary-pointer hover is not claimed as observed.

## Open Source route and historical date-gap closeout - 2026-10-10

### Open Source diagnosis and repair

- The detail controller previously maintained its own source-to-module route mapping. That duplicated the source URL resolver already used by Submission Tracking and could drift from workflow-specific module destinations. The controller now asks `SubmissionTrackingService::source($source)['url']` for the existing destination. The detail UI only renders the action when both the source/record identity and a resolved URL are present. No Tracking route or guessed record query parameter was added.
- The detail response still carries the original `source` and `source_id`; the protected detail request resolves the origin and source record together, verifies `is_historical`, then enforces the source permission and organization scope. Feature coverage rejects a forged source/record pair with 404 and a valid but unauthorized source with 403. Ten source resolver families are checked against their registered module URLs. Conservation and ENGP workflow destinations use only parameters returned by that existing resolver.
- The resolver destinations are existing module/index pages. A record-specific module URL is not available for every source, so this repair does not claim to reopen a selected row where the module has no supported deep-link contract. Historical badge navigation remains on the authorized Historical Reports index route.
- The earlier historical detail 500 investigation found a concrete null-date failure in the activity-timeline formatter: a nullable original milestone reached a string-only callback. The formatter now accepts mixed date values and returns null for blank milestones. A separate production log contains `MissingAppKeyException` at 2026-10-10 17:26:30-31, but the log has no request URL or correlation identifier. The supplied screenshot's request URL and HTTP status were not available, so neither log entry can be tied conclusively to that image. If it corresponds to `MissingAppKeyException`, the deployment's application key configuration still needs attention; no `.env` or key material was changed.

### Historical date-gap evidence and live isolation

- The read-only projector presents three separate Asia/Manila calendar-day bases: conducted to accomplished (preparation); source-authoritative accomplished to original CENRO release (PA processing, with the supported PAMB conducted-date fallback only when accomplished is absent); and original CENRO release to original PENRO receipt (handoff, including ENGP). ENGP has no parent conducted/accomplished dates. A direct-to-PENRO PA route receives no synthetic CENRO-release interval. Missing, invalid, reversed, unsupported, and not-applicable endpoints remain distinguishable; a valid same-day interval is zero.
- The Conservation 38 equivalent fixture uses March 18 conducted/accomplished, March 25 original release and receipt, and March 31 endorsement. It produces 7 processing days and 0 handoff days. Source `Days Complied` remains 4 and `PENRO Delay` remains 6; neither is used as elapsed duration. The fixture also checks the original receipt cohort and semester bounds, source-date projection, and PAMO access scope. No real source row was updated.
- The feature check compares live event-derived average, total, completed/pending/missing counts with and without historical inclusion, and confirms unchanged source/origin values and no new routing events, PAMB events, snapshots, notifications, jobs, ENGP release events, or archives. The live average-TAT, performance-score, and routing calculations were not changed by this date-gap projection.

### Final verification and limits

- Process-local SQLite PHP checks previously passed on the current PHP source: `HistoricalDateGapProjectorTest` 4/4 (21 assertions), `OfficeTatAverageRankingTest` 14/14 (118 assertions), and `HistoricalReportEncodingTest` 9/9 (569 assertions). The final edits after those runs were JavaScript test assertions and this note; PHP source did not change afterward. Changed projector/controller/service and focused test files pass `php -l`.
- The final serial JavaScript rerun passed 55/55 across the 14 focused historical detail/request/entry, protected preview, modal, Office TAT ranking/evidence/period/scope, ENGP display, and Settings navigation test files. The build guard passed with 152 reachable ESM assets and 85 relative generated imports. The successful staged production build was run after the final production-source edits (Vite transformed 1,454 modules); only test files changed afterward. The prior build remained in place until the guarded candidate passed, then the candidate was promoted. `git diff --check` passed with Git's existing CRLF-normalization notices for three JSX files.
- This is not a claim that the whole frontend suite or an authenticated browser flow passed. The earlier `SubmissionTrackingPreviewContract.test.mjs` run remains 22/25: three portal-only `DocumentPreviewDialog` SSR assertions get empty output without `document`. The focused protected preview request suite passes 20/20, but no DOM/portal harness (`jsdom`, `happy-dom`, Playwright, or Puppeteer) or authorized browser session is installed/available here, so rendered preview markup and visual navigation were not verified in a browser. No new harness was installed. Read-only GETs from `cds-system.test` served `HistoricalReportDetail-ddJTNIo8.js` (HTTP 200, 14,331 bytes) and the Office TAT `Index-CAtgG9ay.js` (HTTP 200, 27,277 bytes), both with JavaScript MIME and exact byte matches to `public/build`. This verifies the built assets, not authenticated page rendering.
- Owner smoke check: in an authenticated browser, open a historical detail, confirm the source label and record identity, select Open source module, and confirm it lands on the matching existing source module. Check a PAMB record with original dates, including Conservation 38 if available, and confirm preparation/processing/handoff labels and missing evidence; verify Office TAT live values and ordinary Tracking queues are unchanged. Do not save or route a record for this check.

### Worktree state at closeout

- `git status --short` reports 0 staged paths, 42 unstaged tracked paths, and 41 untracked entries. This is the accumulated workspace state, not a task-only diff. The `.recovery/` entry appears as untracked and was not opened or modified. No path was staged, committed, pushed, reset, or cleaned.

## Historical alert, calendar, badge and pending-status follow-up - 2026-10-10

### Operational Compliance Alerts

- `OverdueReportService` now filters concrete source rows by the authoritative historical origin pair (`source` plus `source_record_id`) before it builds alert candidates and their aggregates. The same exclusion is applied when resolving a candidate for confirmation. It covers PA and ENGP source models as well as the other registered alert sources; blank live receipt fields or a frontend badge are not used to decide eligibility.
- `ComplianceAlertDeliveryService` filters historical origins from delivery planning and recipient readiness, then rechecks each delivery immediately before creating a memorandum/run or reaching the mail transport. This also rejects a candidate assembled before its origin was created. The test uses `Mail::fake`; no message, delivery setting, recipient or audit history was changed.
- Scheduled ENGP obligations still compare identities against all persisted ENGP periods, including origin-backed history. The historical August identity is not emitted as either a source candidate or a synthetic missing obligation, while a live overdue July record in the same office/workflow remains eligible. A separate BMS fixture proves a live overdue source remains in summaries and delivery candidates alongside an excluded historical source.
- The Compliance Alerts feature check confirms the remaining live source is still counted, the historical row is absent from candidates, source groups, detail confirmation and the fake delivery plan, and no historical confirmation or mail is created. Historical source records and Historical Reports remain available through their existing workflows.

### Calendar evidence projection

- Historical origin-backed rows are added to the existing month/year business calendar and kept separate from Submitted MOVs. Live calendar queries exclude origin-backed rows, so each historical report appears once. Module, protected-area, year/month and authorization scopes are applied before projection; a PAMO fixture sees only its assigned protected area.
- Activity reports use the earliest recorded `date_conducted_ranges` start, then the source `date_conducted` or `date_accomplished`. When those are absent, the calendar uses an original PENRO receipt, CENRO release or regional endorsement milestone. ENGP can fall back to its original report deadline. Each entry carries an explicit date-basis label, ranges, original milestones and the source-plus-record identity. An entry with no supported date anchor is omitted, and the calendar explains that omission; encoding time is never used.
- Historical entries use a `historical:{source}:{id}` key, a separate Historical reports filter/count and a Historical indicator. Selecting one opens a read-only evidence dialog that identifies the source record and date basis and links to the protected Historical Detail route. Direct attachment URLs and ordinary submit/receive/forward controls are not exposed by the historical dialog.
- The feature check covers a BMS activity range, an ENGP original receipt anchor, a missing-date row, equal numeric IDs in two source tables, a PAMO scope, an unchanged live receipt-date event, protected detail access and rejected ordinary routing. Calendar reads leave source/origin values and the routing, snapshot, notification, job, release and archive tables unchanged.

### Shared record and status presentation

- Live and Historical now use the same shared pill geometry. Live keeps its label and accessible name with a restrained green CSS pulse; `prefers-reduced-motion` makes the indicator static. Existing Historical navigation and the passive Settings-table badge continue using their prior behavior.
- A shared status presenter shortens only the canonical long pending route labels and `Awaiting Forward to ...` / `Awaiting Receipt by ...` labels to an amber `Pending` pill. It leaves the complete stage/office wording as visible, accessible text next to the pill. Completed, Released, correction, missing-report and other statuses keep their existing values and renderers.
- Submission Tracking and the existing module tables/details use the shared presenter. The table still reads the same routing status, deadline and required-action functions; no custody state, deadline, action payload or route policy changed. The focused routing contract retains `Awaiting Forward to CENRO CDS Chief` and its normal forward/receipt transition; the MOV action helper still returns `Submit for Review` when that prerequisite is available.

### Fresh verification and limits

- With the test process explicitly configured and confirmed as `sqlite / :memory:` and mail faked, the three focused Compliance Alert and historical calendar feature cases passed: **3 tests, 75 assertions**. The separate Submission Tracking route-contract case passed: **1 test, 14 assertions**.
- Serial Node checks passed **73 tests across 11 focused files**, including historical calendar presentation, source/detail/preview access, modal lifecycle, row activation, Live/Historical and pending-status presentation, Submission Tracking presentation, and the existing `Submit for Review` action helper. The default worker-based Node runner hit the known Windows `spawn EPERM`; serial `--test-isolation=none` runs passed. The first row-activation run exposed an obsolete test expectation for `record.module_url`; the assertion now checks the current protected `historicalSourceModuleHref` resolver and exact embedded-link guard, and the rerun passed.
- One `npm.cmd run build` completed successfully after all frontend source changes: Vite 8.1.4 transformed 1,458 modules and the integrated guard verified 154 reachable ESM assets and 87 generated relative imports. Later edits only changed tests and this review note, so the successful production-source build still covers the final frontend implementation.
- Changed PHP services and feature tests pass `php -l`; `git diff --check` passes. Git reports existing CRLF normalization notices in JSX files. No normal report/provenance writes, migrations, settings/permissions changes, delivery calls, routing actions, recovery/backup changes, staging, commit or push occurred. Test fixtures used process-local in-memory SQLite.
- No authenticated browser automation tool is available in this session, so the visual state is not claimed as browser-verified. Owner smoke check: open January 2026 in the business calendar, select a Historical entry and confirm its source/date basis and protected detail; compare Live and Historical pills in light/dark and reduced-motion settings; inspect the incoming row and confirm the full CENRO stage, October 15 deadline and `Submit for Review` remain visible; review a known historical plus live overdue case in Compliance Alerts without sending anything.

### Worktree state after this follow-up

- `git status --short` reports 0 staged paths, 48 unstaged tracked paths and 47 untracked entries. This is the accumulated workspace, not a task-only diff. The `.recovery/` entry remains untracked and was not opened or modified. No path was staged, committed, pushed, reset or cleaned.

## Redundant Pending Submission caption - 2026-10-10

- The owner's clarified wording keeps `Submission by CENRO` and removes only the redundant `Pending` from the caption beside the separate Pending badge. The shared status presenter now derives that caption from the original `Pending Submission by {office}` value, retaining its office attribution. Repository search found CENRO as the actual submission-caption variant; other actual pending stages, including `Pending Receipt by PENRO`, `Pending Regional Endorsement`, `Awaiting Receipt by Office of the PENRO` and `Awaiting Receipt by PENRO Records`, retain their full captions. A bare `Pending Submission` value remains unchanged because no current caller emits it.
- The change is in the shared presenter used by Submission Tracking's Deadline and detail Compliance fields and by BMS, Management Plans, IPAF, IMEA Maintenance, ENGP and Technical Reports table/detail badges. The Pending pill, canonical source values, deadline, responsible office/category, current routing stage, Receive/Forward actions, `Submit for Review`, progress, filters and calculations are unchanged.
- Focused serial checks passed **31 tests across 3 files**: shared status/record-type presentation (4), Submission Tracking presentation (6), and submission detail/action context (21). They verify the shorter caption, retained Pending badge and office, unchanged Awaiting variants, unchanged deadline/compliance/action render paths, and distinct late/overdue/completed/correction/missing states.
- The single guarded `npm.cmd run build` passed after the frontend change: Vite transformed 1,458 modules and the asset guard verified 154 reachable ESM assets and 87 generated relative imports. `git diff --check` passed with the existing JSX CRLF normalization notices.
- No authorized browser automation was available, so light/dark and narrow-screen visual acceptance remains pending. No data, routing or provider action was performed; work remains unstaged.

## Awaiting status, PAMB zero leak and historical detail loading - 2026-10-10

### Shared status typography and PAMB cells

- The Awaiting context now uses `font-semibold`, matching the existing Submission Tracking `Required Action` row token. This is only a weight change in the shared `OperationalStatus` context span; color, text size, spacing, wrapping, accessible group/badge labels, and the full stage wording remain as before. The shared renderer covers Submission Tracking, BMS/conservation, Technical Reports, IPAF, Management Plans, IMEA Maintenance and ENGP callers. Row #39's Receive action and October 15 deadline were not changed.
- Both unexplained `0` values came from the `is_historical && ...` branches immediately after the Timeliness and Status of Submission badges in `ReportSubmissionTracker.jsx`. For a live PAMB record, the serialized database boolean can be numeric `0`; React renders the numeric left operand of `&&`. Both branches now use `Boolean(row.is_historical)`, so a live record renders neither historical evidence block nor a stray zero. Historical records in the supported non-conservation callers still show their original date evidence. The two badge values and their calculations are untouched.

### Historical detail request lifecycle

- The page opens the dialog in its loading state and fetches the selected row URL with a same-origin JSON GET. The protected controller returns JSON for `expectsJson`; its detail object includes source/id, label, detail, timeline, historical date gaps, timeliness and attachment fields. The helper now rejects non-JSON success responses and incomplete record shapes, and translates malformed JSON into the same readable incomplete-response error. The existing dialog shows Retry and Close on failures.
- A valid response sets the detail record and moves the state to ready. HTTP 401/403/404/500, a followed login redirect ending in HTML, malformed JSON and `{ record: {} }` are covered as errors. Closing aborts and invalidates the request; switching source/id invalidates the old token; reopening the same row while still loading requests again. These guards prevent an older response from replacing the new selection.
- The 20:07 screenshot records only the initial loading frame. No authorized browser session/tool was available to measure the actual GET duration, so it is not possible to classify that observation as transient or stuck. Source inspection and isolated fakes verify the success/error/close/reopen paths; no row ID was inferred from the screenshot, and no permission or attachment route was bypassed.

### Fresh checks and limits

- Focused serial Node checks passed **10/10** across `statusAndRecordTypePresentation.test.mjs` and `historicalDetailRequest.test.mjs`, including both numeric-zero branches, shared context weight, valid response, session/permission/not-found/server errors, login HTML, malformed/incomplete responses, and request invalidation/retry lifecycle.
- The final `npm.cmd run build` passed: Vite 8.1.4 transformed 1,458 modules and the asset guard verified 154 reachable ESM assets and 87 generated relative imports. A final `git diff --check` passed with CRLF-normalization notices in six existing JSX files. Browser rendering remains unverified; no live authenticated GET or visual acceptance is claimed. Live calculations, legitimate numeric zero values, PAMB dates, source status, protected authorization and the accumulated unstaged worktree were preserved.

## Timeliness presentation and historical detail loading layout - 2026-10-10

- The shared `TimelinessBadge` now accepts an opt-in unrated-pending presentation. Only the two canonical Conservation values `Pending Submission by CENRO` and `Pending Receipt by PENRO` map to a compact neutral `Not yet rated` label, and only for live Conservation rows/details. The Conservation calculator emits those values when its date basis exists but authoritative PENRO receipt is missing; a missing date basis remains `No Data`. Completed ratings, missing evidence, other pending stages and historical rows keep their prior display. The `Submission by CENRO` context and amber `Pending` submission badge, current Awaiting stage and deadline remain separate and unchanged.
- Source tracing found no explicit timer, awaited entrance animation or lazy detail-module import before the protected GET: row activation opens the dialog and sets loading synchronously, then the page effect fetches the selected URL with the existing source/id guard. The dialog header title and period are already available from the list row. The details controller still requires an authenticated user, historical provenance and source/organizational authorization; attachment descriptors remain metadata for the explicit protected preview path.
- No browser automation/session was available, so the provided loading screenshot cannot establish current HTTP status, content type, TTFB/request duration or time to visible detail, and it cannot be matched to a current request. Source inspection did not identify a demonstrated backend or rendering bottleneck. The prior response validation guards improve error handling but do not prove or resolve latency. The new two-column skeleton follows the detail cards, timeline, source facts, date gaps, attachments and audit layout, says `Loading report details…`, and uses the shared reduced-motion-aware Skeleton component. This is a loading presentation improvement only; live delay remains unmeasured.
- The focused serial Node run passed **11/11** across status/rating presentation and historical request lifecycle checks. The affected PHP `TimelinessPresentationTest` passed **3 tests, 37 assertions**. The final guarded build passed: Vite 8.1.4 transformed 1,460 modules and the asset guard verified 156 reachable ESM assets and 89 generated relative imports. `git diff --check` passed with six existing CRLF-normalization warnings; the two newly added source files have no trailing whitespace.

## Historical modal trace and response identity - 2026-10-10

- The owner's screenshots establish a `GET /settings/historical-reports/conservation/38` returning **200**. Separate Network captures show **246 ms / 5.7 KB** and **296.05 ms total**, including **288.46 ms Waiting for server response** and **2.93 ms download**. These are not a controlled comparison; the status capture does not show Content-Type or body, and neither capture measures input-to-request, body parsing, React commit or a painted frame. The separate index request `/settings/historical-reports` shows **445.72 ms total / 429.81 ms waiting**. A different red request reports **5.63 s in Initial connection** only. Those page/connection observations cannot be assigned to the details PHP action or SQL.
- Source tracing: CrudTable passes pointer/keyboard activation into `openDetails`, which selects the row and opens the modal in the same handler. The page effect dispatches one same-origin JSON GET for the selected URL; no timer, duplicate effect, lazy detail import, modal transition wait, or attachment-byte/Drive request was found. The existing modal motion's RAF and 240 ms transform/opacity transition run independently. The controller checks authentication, historical provenance, source authorization and organizational scope before building the record; its JSON contract contains `source`, `source_id`, label, detail, timeline, date gaps, timeliness and attachment descriptors. Descriptor generation reads local file metadata only; actual file bytes remain behind the explicit protected preview route.
- A shape-valid 200 could previously contain another row's `source`/`source_id` and still be accepted. `fetchHistoricalDetail` now requires the clicked identity and a JSON Content-Type, and rejects a mismatched or incomplete response before it reaches ready state. This is a reproduced response-contract correctness fix; it is not evidence of the cause of the perceived delay.
- The existing `LocalNavigationTrace` is now opt-in for the protected details route. The server emits its request/controller/SQL `Server-Timing` only for local `cds-system.test` loopback GETs carrying `__cds_perf=1`; the controller activates SQL timing after authentication and before source reads. The client trace is off by default and activates only on that local host with the same query flag. It automatically emits one console summary with safe route/source/id, status, Content-Type, server trace/timing headers, response identity, JSON/contract outcome, request/body/parse timings, modal commit/transition timing, details commit and two animation-frame callbacks. It records no report text, attachment bytes, cookie, token or authorization value. Frame callbacks are not proof that pixels were painted.
- No authenticated browser session with a debugging interface was available: Chrome is running, but no remote-debugging port is exposed and no browser automation tool is connected. Therefore no current response body, live server timing, real click-to-commit, or paint timing was measured. The supplied ~0.3 s details request is not evidence for a multi-second PHP bottleneck. The separate Debugbar JS 404 is not injected into JSON responses (the installed Debugbar injects its assets into HTML responses); its effect on the index page is unproven. No speculative asset, connection, HTTPS, key, or configuration change was made. To capture one comparable local opening, load Historical Reports with `?__cds_perf=1` and select Conservation 38 once; the `[CDS historical detail trace]` console record will collect the stages automatically.
- Changed paths in this trace follow-up: `resources/js/Pages/Admin/Settings/HistoricalReports.jsx`, `resources/js/Pages/Admin/Settings/historicalDetailRequest.mjs`, `app/Support/LocalNavigationTrace.php`, `app/Http/Controllers/HistoricalReportController.php`, `tests/js/historicalDetailRequest.test.mjs`, `tests/Unit/LocalNavigationTraceTest.php`, and this review note.
- Fresh checks passed: **7/7** serial Node historical detail request/trace tests, including strict content type, wrong source/id, local-only opt-in and lifecycle guards; **4 PHP LocalNavigationTrace unit tests / 66 assertions** under the configured `APP_ENV=testing`, SQLite `:memory:` setup. The final guarded Vite build passed with **1,460 modules**, **156 reachable ESM assets** and **89 generated relative imports**. `git diff --check` passed with six CRLF-normalization warnings. Current status is **53 tracked paths modified, 49 untracked entries, 0 staged**; `.recovery/` remains untracked and untouched, and no changes were committed or pushed.

## Historical trace boundaries and long server wait - 2026-10-10

- The supplied details capture correlates request id `fa241cf5d32ff88c` with route `settings.historical-reports.show`: **22.25 s total / 22.24 s Waiting**, and `app_pipeline=19,190.92 ms`, `inertia_route_pipeline=342.23 ms`, `controller_action=158.80 ms`, `inertia_share_eager=142.40 ms`, and SQL **23.99 ms / 10 queries**. The index request separately waited **39.42 s** of **39.44 s**; no index request id or matching index Server-Timing phases were supplied, so the details phase values are not assigned to that request. These are overlapping wall intervals, not additive exclusive costs.
- Source boundaries explain why that response does not identify the slow operation. `LocalNavigationTiming` starts `app_pipeline` at entry to the web middleware group and stops it after the full middleware unwind and actor-category lookup. `inertia_route_pipeline` starts later in `HandleInertiaRequests` and covers shared Inertia data, route middleware/controller, and the response up to that handler's return. The SQL listener is activated from `share()`, after session startup and after `Auth::user()` is resolved. Consequently the supplied SQL total does not cover earlier session reads or auth-user resolution. `app_pipeline` also ends before diagnostic header assembly/cleanup and `Response::send()`; Browser Waiting includes work outside that interval too. No supplied `to_trace_start` value closes that remainder.
- No slow blocking operation is established yet. The trace source has no remote call, report-body/attachment inspection, file-tree scan, or historical-detail logging on this route. Trace activation clones the current event dispatcher and attaches a query listener; it occurs only on an eligible opt-in request. The supplied controller and inner route spans are short, but this does not prove that the diagnostic caused the wait or that session middleware did.
- The local trace now measures `web_before_inertia` (earlier web middleware), `auth_user_resolution`, `trace_event_hook_setup` (dispatcher clone/connection hookup), and `web_after_inertia` (outer middleware unwind, including any session persistence). These use `hrtime()` and fixed per-request phase names. They are intended to locate the next wait; the new phase values were not present in the supplied screenshots. The trace remains default-off, exact-host/loopback/local-environment/GET/allowlisted-route gated, and retains no report data or credentials. The unflagged path creates no trace state, swaps no event dispatcher, and emits no trace headers.
- The authenticated current-build flag-off/on comparison is unavailable in this environment: no attached authenticated browser session or browser automation interface is present. The new **22–39 s** captures are opt-in network timings only; the earlier **246 ms / 5.7 KB** and **296.05 ms** details observations were not same-build controlled comparisons. The supplied screenshots do not include the console commit/frame trace or a settled modal, so they do not measure visible paint. The actual wait remains unresolved and is not declared fixed.
- Changed files in this follow-up: `app/Support/LocalNavigationTrace.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `tests/Unit/LocalNavigationTraceTest.php`, `tests/Feature/HistoricalReportEncodingTest.php`, and this review note. The server JSON feature assertion checks `application/json` and matching `record.source` / `record.source_id`; existing protected route/source authorization remains covered.
- Fresh checks passed under `APP_ENV=testing`, SQLite `:memory:` from `phpunit.xml`: `LocalNavigationTraceTest` **5 tests / 74 assertions** and `HistoricalReportEncodingTest` **10 tests / 619 assertions**. PHP syntax checks passed for the trace, middleware, and unit test; `git diff --check` passed. No frontend source changed, so no build was run. Current status remains **53 tracked paths modified, 49 untracked entries, 0 staged**; `.recovery/` was left untouched and no changes were committed or pushed.

## Final feature integration review and checkpoint preparation - 2026-10-10

### Owner acceptance and scope result

- At **23:29 Asia/Manila on October 10**, the owner accepted the historical modal's current opening behavior. The latest owner Network observations were **218.62 ms total / 213.17 ms Waiting** without the trace flag and **261.03 ms total / 252.92 ms Waiting** with it. The traced details request reported **107.29 ms app_pipeline** and **9.62 ms SQL**. The previously reported 22-39 second waits were not reproduced; their cause remains unestablished, and one observation in each mode is not a trace-overhead comparison. This closes the active latency investigation unless the long wait recurs. These remain owner Network measurements, not agent click-to-paint measurements.
- Focused review covered Office TAT, historical provenance and read-only details, separation from live workflows, source navigation, table activation, shared modal lifecycle, and protected attachment preview. The current controller/encoder, request identity guards, shared UI and registry contracts are consistent with those boundaries in the inspected code and passing checks below. No normal-database data, Office configuration, deployed migration, archive/provider, notification, routing, or environment operation was performed; Pest used process-local SQLite memory.
- No correctness blocker was reproduced. The historical owner-accepted modal behavior is recorded; the older long-wait cause is not claimed to be fixed.

### Fresh checks in this review

- With `phpunit.xml` configuring `APP_ENV=testing` and SQLite `:memory:`, Pest passed **28 tests / 758 assertions** across `HistoricalReportEncodingTest`, `OfficeTatAverageRankingTest`, and `HistoricalDateGapProjectorTest`.
- Serial Node runs passed **137 tests**: the 25-test `SubmissionTrackingPreviewContract` suite plus 112 tests across 17 focused files covering protected-preview validation, shared-control rendering, row activation, modal focus/layers, historical detail request/presentation, historical navigation, Settings navigation, ENGP correction forms, PAMB/ENGP details, and Office TAT scope, periods, evidence and rankings.
- PHP syntax checks passed for all **29 proposed PHP files**. `git diff --check` passed. Git emitted its existing CRLF normalization notices for six JSX paths.
- `node scripts/verify-vite-build.mjs` passed and verified **156 ESM assets** and **89 generated relative imports** reachable from `resources/js/app.jsx`. The prior guarded 1,460-module production build remains dated evidence in this note; it was not rerun because this integration step changed only test files and this review note, not production frontend/configuration code.

### Portal preview coverage limit

- The earlier three empty-markup failures came from calling `renderToStaticMarkup` on a component that renders through `createPortal`; this Node run has no `document`, so the component correctly returns empty markup. No connected browser or DOM test harness is available here.
- The three failing portal cases now state that SSR boundary explicitly and retain checks for the component's loading/ready gates, request identity, supported and unsupported MIME handling, download condition, missing-current-descriptor copy, and the absence of an iframe. `globalControlRendering` had one more empty-SSR assertion for a projected denial; it now checks the permission branch and download guard in source while asserting the expected empty server output. The same suite directly invoked a hooks-based component to inspect a click callback; it now renders the markup and checks the close-handler wiring without calling a hook outside React. All **29/29** tests pass. The separate protected-preview unit suite passed **20/20**, including response MIME/signature validation, authorization failures, URL rejection, abort handling and blob URL cleanup. This is source-contract plus helper coverage; it does not establish how the portal looks or behaves in a real browser DOM.
- No authenticated browser smoke check was available. This is the remaining verification limit, not a reproduced application defect.

### Safe screening

- The proposed 111 files were scanned without opening `.env`, `.recovery/`, protected backups or recovery copies. The credential screen found **0** private-key markers, common live-key formats, or secret-assignment candidates. The infrastructure screen found **0** user-home paths, local installation paths, UNC paths, or private IPv4 addresses.
- The encoding scan found pre-existing mojibake markers in five proposed source files. None of those markers appears on a line added by the current diff; no out-of-scope copy cleanup was made. Counts and current line locations are: `app/Services/Compliance/OverdueReportService.php` (**2**, line 814); `resources/js/Pages/Bams/Index.jsx` (**1**, line 713); `resources/js/Pages/Bms/Index.jsx` (**12**, lines 701, 876, 1354-1358); `resources/js/Pages/Imea/FacilitiesReport.jsx` (**10**, lines 260, 261, 269, 294, 308, 325-326, 329-331); and `resources/js/Pages/Imea/Index.jsx` (**16**, lines 419-420, 449, 478-482, 794, 855, 868, 885, 888, 891-892). The standalone asset guard passed; no generated build output is proposed.

### Checkpoint state

- The checkout is on `main` at `fda098eddaef1a91d6fce1997cc48cfa437d4948`. The local `origin/main` ref has the same value; it was not refreshed from a remote during this review. There are **55 modified tracked paths**, **56 individual intended new files**, and **0 staged paths**. The `.recovery/` status entry is excluded from the inventory and was not opened, enumerated, or modified.
- No staging, commit, push, reset, cleanup, demo restart, migration, or checkpoint publication occurred. The review is ready for a later explicit publication instruction.

### Exact proposed checkpoint paths

Tracked modifications:

```text
app/Http/Controllers/ConservationReportSubmissionController.php
app/Http/Controllers/EngpReportController.php
app/Http/Controllers/SubmissionTrackingController.php
app/Http/Middleware/HandleInertiaRequests.php
app/Services/CalendarMovEventService.php
app/Services/Compliance/ComplianceAlertDeliveryService.php
app/Services/Compliance/OverdueReportService.php
app/Services/SubmissionTracking/SubmissionTrackingService.php
app/Support/LocalNavigationTrace.php
config/app.php
package.json
resources/css/app.css
resources/js/Components/Admin/SettingsShell.jsx
resources/js/Components/BusinessCalendarMonth.jsx
resources/js/Components/ConfirmDialog.jsx
resources/js/Components/Crud/CrudDetailsModal.jsx
resources/js/Components/Crud/CrudFormModal.jsx
resources/js/Components/Crud/CrudTable.jsx
resources/js/Components/CurrentPasswordConfirmDialog.jsx
resources/js/Components/Form/FilterToolbar.jsx
resources/js/Components/Form/ScopedOptionSelect.jsx
resources/js/Components/Modal.jsx
resources/js/Components/Skeleton.jsx
resources/js/Components/StandardAReportSubmissionTracker.jsx
resources/js/Components/SubmissionTracking/DocumentPreviewDialog.jsx
resources/js/Components/SuccessDialog.jsx
resources/js/Components/TimelinessBadge.jsx
resources/js/Layouts/AuthenticatedLayout.jsx
resources/js/Pages/AWS/Aws.jsx
resources/js/Pages/AWS/AwsReportSubmissionTracker.jsx
resources/js/Pages/AWS/AwsSummaryExportModal.jsx
resources/js/Pages/Admin/Settings/ModuleManagement.jsx
resources/js/Pages/Bams/Index.jsx
resources/js/Pages/Bms/Index.jsx
resources/js/Pages/Bms/ReportSubmissionTracker.jsx
resources/js/Pages/Calendar/Index.jsx
resources/js/Pages/ConservationReports/Index.jsx
resources/js/Pages/Engp/Index.jsx
resources/js/Pages/Imea/FacilitiesReport.jsx
resources/js/Pages/Imea/Index.jsx
resources/js/Pages/Imea/MaintenanceReports.jsx
resources/js/Pages/Imea/Report.jsx
resources/js/Pages/Ipaf/Index.jsx
resources/js/Pages/ManagementPlans/Index.jsx
resources/js/Pages/ProtectedAreas/Form.jsx
resources/js/Pages/ProtectedAreas/Index.jsx
resources/js/Pages/SubmissionTracking/Index.jsx
resources/js/Pages/TechnicalReports/Index.jsx
routes/web.php
tests/Feature/ComplianceAlertTest.php
tests/Feature/TimelinessPresentationTest.php
tests/Unit/LocalNavigationTraceTest.php
tests/frontend/SubmissionTrackingPreviewContract.test.mjs
tests/js/globalControlRendering.test.mjs
tests/js/sharedFilterPresentation.test.mjs
```

Intended new files:

```text
app/Http/Controllers/HistoricalReportController.php
app/Http/Controllers/OfficeTatController.php
app/Models/HistoricalReportOrigin.php
app/Models/OfficeTatConfigurationVersion.php
app/Services/HistoricalReports/ConservationHistoricalEvidenceProjection.php
app/Services/HistoricalReports/EngpHistoricalEvidenceProjection.php
app/Services/HistoricalReports/HistoricalDateGapProjector.php
app/Services/HistoricalReports/HistoricalReportCatalog.php
app/Services/HistoricalReports/HistoricalReportEncoder.php
app/Services/OfficeTatAnalyticsService.php
database/migrations/2026_10_09_000001_add_historical_report_provenance_and_office_tat_configuration.php
database/migrations/2026_10_09_000002_grant_historical_reports_and_office_tat_permissions.php
docs/cds-smart-average-tat-ranking-shared-ui-verification-2026-10-09.md
docs/cds-smart-tat-historical-final-review-2026-10-10.md
docs/cds-smart-tat-historical-followup-verification-2026-10-09.md
resources/js/Components/Crud/rowActivation.mjs
resources/js/Components/HistoricalRecordBadge.jsx
resources/js/Components/ModalMotion/initialFocus.mjs
resources/js/Components/ModalMotion/modalLayer.mjs
resources/js/Components/ModalMotion/useModalMotion.js
resources/js/Components/OperationalStatus.jsx
resources/js/Components/RecordTypeBadge.jsx
resources/js/Components/historicalRecordBadgeBehavior.mjs
resources/js/Components/recordTypeBadgeStyles.mjs
resources/js/Components/timelinessPresentation.mjs
resources/js/Layouts/settingsNavigation.mjs
resources/js/Pages/Admin/OfficeTat/Index.jsx
resources/js/Pages/Admin/OfficeTat/assessmentPeriodSelection.mjs
resources/js/Pages/Admin/OfficeTat/ranking.mjs
resources/js/Pages/Admin/Settings/HistoricalReportDetail.jsx
resources/js/Pages/Admin/Settings/HistoricalReportDetailSkeleton.jsx
resources/js/Pages/Admin/Settings/HistoricalReports.jsx
resources/js/Pages/Admin/Settings/historicalDetailPresentation.mjs
resources/js/Pages/Admin/Settings/historicalDetailRequest.mjs
resources/js/Utils/createUuid.mjs
resources/js/Utils/statusPresentation.mjs
scripts/verify-vite-build.mjs
tests/Feature/HistoricalReportEncodingTest.php
tests/Feature/OfficeTatAverageRankingTest.php
tests/Unit/HistoricalDateGapProjectorTest.php
tests/js/createUuid.test.mjs
tests/js/crudRowActivation.test.mjs
tests/js/engpHistoricalDisplay.test.mjs
tests/js/historicalCalendarPresentation.test.mjs
tests/js/historicalDetailPresentation.test.mjs
tests/js/historicalDetailRequest.test.mjs
tests/js/historicalReportEntry.test.mjs
tests/js/modalInitialFocus.test.mjs
tests/js/modalLayer.test.mjs
tests/js/officeTatAssessmentPeriod.test.mjs
tests/js/officeTatEvidence.test.mjs
tests/js/officeTatHistoricalRanking.test.mjs
tests/js/officeTatRanking.test.mjs
tests/js/officeTatScope.test.mjs
tests/js/settingsSidebar.test.mjs
tests/js/statusAndRecordTypePresentation.test.mjs
```

### Overall rating: PARTIAL

The offline integration contracts and focused checks pass, and no correctness blocker was reproduced. The practical limitation is that an authenticated browser/DOM pass is unavailable, so interactive appearance and live portal behavior remain unverified here. The owner has accepted the current modal opening behavior; the old long-wait investigation is closed unless it recurs.

## October 11, 2026 - Live Regular PAMB and Office TAT follow-up

### Production evidence limits

- The supplied owner note describes a live APL / Regular PAMB / Minutes / Quarter 4 source row, conducted and accomplished October 5, plus an Annual 2026 Office TAT view whose PAMO rows show no completed live samples. The screenshot descriptions do not include the unique source record ID, full Office TAT query, selected As of date, source-year selector, complete event timeline, or CENRO category result.
- I could not confirm a read-only normal application database connection without opening protected runtime configuration. `.env`, `.recovery/`, system-drive paths, and protected backups remain unopened and untouched. No actual production source ID, assignment, event row, database timestamp representation, or exact UI filter set is claimed here. The observation's exact source-specific exclusion therefore remains unconfirmed.
- The filters shown or described are Annual 2026 and PAMO. The scenario's workflow (`conservation:regular_pamb`), APL Protected Area, Quarter 4, live evidence selection, empty source-year filter, and As of values October 10 versus October 11 are controlled fixture assumptions, not recovered browser query state.

### Eligibility trace and scoped correction

- `OfficeTatAnalyticsService` uses Asia/Manila calendar dates. An explicit As of date is preserved; the event window ends exclusively at midnight after that date. The source assessment year and the conducted/accomplished dates do not substitute for accepted routing-event timestamps.
- The live cohort starts from accepted `DocumentRoutingEvent` rows with `event_key=received`, or the legacy PAMB `RECORDS_RECEIVED` routing event. A PAMO sample still requires an accepted receipt to the PAMO office and a later handoff out of that office. A PA's PAMO assignment, PAMB review verdict, completed source label, or PENRO receipt does not create that PAMO interval.
- The current shared Regular PAMB route records custody in `document_routing_events`; MOV review and verdict records are separate. The route's CENRO focal-to-chief forwarding event stays inside the CENRO category. Previously, the analytics treated that internal step as an office handoff before the later CENRO receipt, marked the document as missing evidence, and discarded its completed CENRO interval.
- The analytics now closes or invalidates an office interval only when an accepted handoff leaves the selected office category. Same-category CENRO routing steps remain inside the CENRO interval. Routing writers and event persistence were not changed.

### Controlled fixture ledger

- The isolated fixture is live (`is_historical=false`) and uses the Annual 2026 / Regular PAMB / APL / Quarter 4 / live filters above. Its routing events use the production shared-event fields and office identities; their stored test values are timezone-naive local datetimes, with the application using Asia/Manila. These are fixture events, not the owner's report events.
- Fixture custody sequence: CENRO focal forwarded to CENRO chief at `2026-10-11 00:02`; accepted CENRO chief receipt at `00:05`; CENRO chief endorsed to CENRO Records at `00:13`; CENRO Records receipt at `00:15`; handoff to PENRO Records at `00:16`. A separate PAMB final-approval event at `00:20` is verdict evidence and is not used as custody evidence.
- At As of `2026-10-10`, the exclusive cutoff is Manila midnight at the start of October 11, so the fixture has 0 selected-office receipts, 0 completed samples, and a null average. At As of `2026-10-11`, CENRO has 1 distinct received document and 1 completed sample averaging 660 seconds (11 minutes); PAMO has 0 received documents, 0 completed samples, and a null average. The source's October 5 activity dates do not alter those counts.
- This establishes a real analytics defect for the supported shared CENRO route shape and its correction. It does not establish that the owner's specific APL source followed this sequence, that October 10 was selected, or that this change creates a PAMO sample. If that report has only CENRO custody receipts, PAMO N/A is correct under the existing measurement contract; the appropriate measured result belongs in the CENRO view.

### Verification and checkpoint

- `OfficeTatAverageRankingTest.php`: **15/15 tests passed** (128 assertions), including the new Manila-midnight, office-attribution, and separate-verdict regression.
- `ConservationMeetingSharedRoutingTest.php`: **15/15 tests passed** (489 assertions), covering the current production transition path. PHP syntax checks passed for the changed service and Office TAT test. No frontend build was needed.
- No authenticated browser or source-specific production event access was available, so the report ID, actual event timestamps and stored timezone, exact selected filters, and actual PAMO-versus-CENRO ledger remain unresolved. Do not treat the fixture average as the owner's report average.
- The changed paths (`app/Services/OfficeTatAnalyticsService.php`, `tests/Feature/OfficeTatAverageRankingTest.php`, and this review note) were already present in the proposed checkpoint inventory; the inventory path set is unchanged. The staged set remains empty. No staging, commit, push, or publication occurred.

## October 11, 2026 - actual APL Regular PAMB row and live Office TAT ledger

### Read-only application verification

- Reused the ordinary Laravel bootstrap and its resolved MySQL connection. Internally confirmed the app was not in the testing environment and the connection was neither an in-memory database nor a database identity marked test/demo. Source and event queries and the service comparisons ran in a read-only transaction that was rolled back. No `.env`, credentials, configuration dump, report text, attachment bytes, user names, or email addresses were included in the result. No application data was changed.
- The supplied APL / Regular PAMB / Minutes / Quarter 4 / October 5 match is unique in the connected application data: source `conservation_report_submissions.id=39`, Protected Area `Aliwagwag Protected Landscape (APL)` (protected-area ID 4), `workflow_key=regular_pamb`, assigned PAMO `APL-PAMO`, assigned CENRO `CENRO Baganga`, `date_conducted=2026-10-05`, `date_accomplished=2026-10-05`, and `is_historical=0`. Its `historical_report_origins` count is 0. This verifies the source identity; the screenshot's displayed `Outstanding / Completed` remains a separate browser observation.
- The actual accepted custody timeline is in `document_routing_events` (13 events for this source); `pamb_routing_events` has 0 events for it. Both `occurred_at` columns are MySQL `DATETIME`, so the stored values have no timezone offset. Routing writers and Office TAT parsing use `BusinessCalendarService::TIMEZONE` (`Asia/Manila`); the values below are therefore reported as Manila wall-clock times.

### Actual accepted custody anchors

- October 10, 17:48:36: `forwarded`, CENRO CDS Focal Person to CENRO CDS Chief (event 213). This is a team-routing step, not the accepted receipt anchor.
- October 10, 17:49:36: `received` by CENRO CDS Chief from CENRO CDS Focal Person (event 214). This starts the measured CENRO Baganga interval.
- October 10, 17:49:57: `endorsed`, CENRO CDS Chief to CENRO Records Unit (event 215), followed by October 11, 00:07:26 `received` by CENRO Records Unit (event 216). These remain within the same assigned CENRO office and do not close or restart its interval.
- October 11, 00:07:38: `forwarded`, CENRO Records Unit to PENRO Records Unit (event 217). This is the accepted handoff out of CENRO Baganga and ends its interval. PENRO's later receipts and release are outside the CENRO sample. No event for this source has a PAMO receipt destination.
- The selected TAT source is the shared `document_routing_events` stream, not the empty legacy PAMB timeline. The source is present in the live report cohort; PAMO's zero is missing PAMO custody evidence, not missing report membership. The source's completed status and the office interval's completed status are separate facts.

### Controlled service comparisons

The owner's exact Office TAT query state was not recovered from a browser session. The following are controlled `OfficeTatAnalyticsService` comparisons using the known Annual 2026 period and live evidence, plus the matched source's `conservation:regular_pamb`, APL (protected-area ID 4), and Quarter 4 filters. The as-of values are Manila calendar dates, inclusive through that date. They are not claims about the screenshot's selected as-of date or any unobserved UI filter.

| As of (Manila) | Office | Received documents | Completed TAT samples | Pending | Missing evidence | Duration / average |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| 2026-10-10 | APL-PAMO | 0 | 0 | 0 | 0 | N/A: no accepted PAMO receipt |
| 2026-10-10 | CENRO Baganga | 1 | 0 | 1 | 0 | N/A completed average; pending from 17:49:36 to Manila end-of-day (~6h 10m 24s) |
| 2026-10-11 | APL-PAMO | 0 | 0 | 0 | 0 | N/A: no accepted PAMO receipt |
| 2026-10-11 | CENRO Baganga | 1 | 1 | 0 | 0 | 22,682 seconds (6h 18m 02s); average 22,682 seconds / 0.26 calendar days |

At the October 10 cutoff, the accepted CENRO receipt is inside the window, but the October 11 handoff is after its exclusive midnight cutoff, so the interval is pending. At the October 11 cutoff, the handoff is included and the interval completes. The accepted-event gate starts the office interval at event 214 and closes it at event 217; the conduct/accomplishment dates and report-level completion label do not supply either anchor. There is no PAMO sample to average, and the separate historical 7-day sample is not used.

### Office identity check and owner-facing result

- For this supported Regular PAMB flow, `eventOffice()` resolves CENRO team-role labels through the source's assigned `target_office` (`CENRO Baganga`); per-document interval state is keyed by source and resolved office. The actual handoffs are among CENRO teams within Baganga, then from Baganga to PENRO. The existing route does not transfer this source between two different CENRO offices, and distinct reports assigned to different CENRO offices retain distinct office keys. The same-category handoff rule therefore preserves the actual internal transfers without merging offices in this supported route. No second analytics defect was demonstrated, so production code and the already-passing suites were left unchanged.
- Under PAMO, the owner should see 0 completed live samples and N/A because this source has no accepted PAMO receipt. Under CENRO, the owner should see CENRO Baganga with a pending sample at As of October 10, and one completed 6h 18m 02s sample at As of October 11. To include the actual October 11, 00:07 handoff, select **As of October 11, 2026 (Asia/Manila)**; October 10 is the correct historical snapshot only if the intent is to exclude all October 11 events.
- Actual-row verification: **PASS**. The live row, historical provenance, assigned offices, accepted events, timestamp column type, and corrected service outputs were inspected. Exact screenshot filters beyond those stated above remain unverified.