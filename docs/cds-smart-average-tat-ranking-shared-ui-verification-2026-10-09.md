# CDS Smart Average TAT ranking and shared UI verification

**Date:** 2026-10-09
**Scope:** Average TAT ranking, shared Settings navigation, historical report encoding UI, and previously authorized local installation follow-up.

## Result

Average TAT is the primary Office TAT view. Offices rank by ascending mean completed-document duration; exact duration ties share a rank. A document counts once after all its correction intervals are combined. For example, completed intervals of one day and three days produce a two-day average when each represents one document. Pending and missing evidence remain separate, and rows without a completed sample show N/A and receive no rank. The overall average is weighted by completed documents. The existing productivity score remains a distinct secondary measure; no SLA or target was inferred.

Live evidence remains separate from manual historical evidence. Live intervals run from an accepted office receipt to its next accepted handoff, combining correction cycles for the same document. An unfinished later cycle stays pending. Historical CENRO evidence uses the original release-to-PENRO-receipt dates and is displayed in its own ranking. A handoff that predates its corresponding receipt is invalid evidence, not a zero-duration completion. PAMO/CENRO remain the only TAT categories; established PENRO routing and receipt milestones remain intact.

The shared Settings sidebar has one main Settings entry. Its destination resolves to the first page the user is authorized to access, including Historical Reports; the Settings shell retains its own authorized links. The historical encoder uses the existing shared controls and protected preview while preserving original dates, module deadlines, attribution, and the optional end of conducted-date ranges.

## Shared UI mapping

| Screen | Shared components used |
| --- | --- |
| Office TAT | `ComplianceTabList`, `FilterToolbar`, shared floating selectors and date filters, cards, tables, and existing Office TAT configuration/detail controls. |
| Historical Reports | `Button`, `Card`, `CrudFormModal` (including `SaveProgressIndicator`), `CrudTable`, `DatePicker`, `FilterToolbar`, `FloatingInput`, `FloatingSelect`, `FloatingTextarea`, `ScopedOptionSelect`, `AttachmentDropzone`, and `HistoricalRecordBadge`. |
| Settings navigation | Existing authenticated sidebar, with one leaf Settings item and capability-based destination selection. |

Historical actual-date fields use the shared date picker with the actual-date limit. The Revenue module's planned deadline permits a future date. Conducted-date ranges preserve a required start and optional end.

## Verification evidence at the ranking/shared-UI revision (2026-10-09; historical, not refreshed in the final review)

- JavaScript: **57/57 passed** across `officeTatRanking`, `officeTatScope`, `officeTatEvidence`, `historicalReportEntry`, `settingsSidebar`, `sharedFilterPresentation`, `settingsNavigation`, `calendarRendering`, `globalControlRendering`, and `reportTypeSelection` test files. The calendar test file completed 6/6 on the full rerun. Its earlier exit code 1 was caused by an interrupted run; the complete rerun passed.
- PHP: **5 focused tests passed** (3 `OfficeTatAverageRankingTest`, 1 PAMO/CENRO scope test, and 1 historical ENGP encoding test). The ranking test set reported 17 assertions; the existing scope and ENGP tests reported 32 and 56 assertions respectively.
- PHP syntax checks passed for `OfficeTatAnalyticsService.php` and `OfficeTatAverageRankingTest.php`.
- `git diff --check` passed for tracked changes.
- Production build passed after the final ranking precision change: Vite 8.1.4, 1,445 modules transformed. During build verification, a bad `FilterToolbar` barrel import was found and corrected to the shared component's direct module path.
- The aggregate Node test runner could not spawn child processes in this Windows sandbox (`EPERM`); each focused test file was run serially and passed.

## Visual review and installation limits

Browser automation was unavailable during the October 9 review, so the supplied report reference had not been compared against a rendered application at that point. Desktop and narrow layouts, dark mode, keyboard/focus behavior, selected filter/popup/modal states, and empty/error/pending states remained visually unverified. The October 10 owner screenshot follow-up is documented separately; it did not include a fresh browser capture or interaction check.

The previously authorized local installation and migrations were not repeated for this UI correction. No commit or push was made. Existing unrelated working-tree changes and `.recovery/` were preserved.
