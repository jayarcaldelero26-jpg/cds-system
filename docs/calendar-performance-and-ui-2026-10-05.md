# Calendar performance and targeted UI corrections — 2026-10-05

## Status

- **Performance investigation/fixes — PARTIAL.** Traced the real month/year request path and reproduced its response cost on matched isolated SQLite fixtures. The month query count stayed flat as authorized events grew from 12 to 240. Stabilized the Holiday form callbacks to stop needless Escape-listener reattachment on form rerenders. Authenticated click-to-visible timing and office MySQL behavior remain unmeasured, so this does not claim that all Calendar lag is fixed.
- **Calendar plus and Holiday form close — PASS.** The Calendar’s rail/day plus controls and the primary Calendar form’s close button now use utility icon styling without shared action styling. Rendered regression checks cover labels, handlers, dimensions, focus/hover classes, and disabled state.
- **Legend — PASS.** Reworked into a compact, wrapping list with text labels and the same color map used by event chips and year markers.
- **Live verification — PARTIAL.** The available browser inventory was empty. No authenticated live clicks, real save/refresh timing, light/dark appearance, or narrow viewport appearance could be observed. Apache was not restarted and no PHP setting was changed.

## Baseline and preservation

- Project C:\laragon\www\cds-system; branch main; HEAD 104eba3fef628c6723ba3f74e7e1cf834a23e2cd.
- At task start, package-lock.json was already modified and the dependency report, office runtime report, scripts/update.ps1, and updater tests were already untracked from the previous authorized tasks. They were preserved. No file was staged or committed, and the updater was not run.
- No root/ancestor AGENTS.md was found; the other AGENTS.md results were inside installed dependencies. Read the handoff, the Business Calendar and scope sections of the system-wide audit, the npm dependency report, and the prior office runtime report.
- Installed versions: Node v24.18.0, npm 11.16.0, PHP CLI 8.3.30, Composer 2.9.4, Git 2.55.0.windows.2, Vite 8.1.4. package.json has a build script and no frontend test script. PHPUnit’s test environment uses SQLite :memory:, array cache/session/mail, and a blank DB URL.
- public/hot was absent before the production build. .recovery/ was not touched. No dependency, .env, runtime, production database, operational calendar record, external notification, archive, job, migration, or stored holiday was changed.
- The current task instructions report that office OPcache is configured but Apache has not been restarted. I did not restart Apache or combine its pending state with the separate OPcache capture recorded in the historical system-wide audit.

## Actual Calendar and Holiday paths

| Surface | Route and implementation | Observed behavior |
| --- | --- | --- |
| Primary Calendar | GET /admin/business-calendar, business-calendar.index, reports.view; ComplianceAlertController::businessCalendar() returns Calendar/Index. | Month/year, Today, module and protected-area filters all call one Inertia router.get() through BusinessCalendarMonth.navigate(). A request returns only the selected month’s MOVs or a year summary, plus non-working dates for that month/year. The month/year and date boundaries use Asia/Manila. |
| Month MOV projection | CalendarMovEventService::events() → eventsBetween(). | Filters authorized sources and the selected module/PA before loading records. Each source query is bounded by whereBetween() on its configured submission date; protected-area and management-plan relations are eager-loaded. It normalizes the full event details used by the detail modal, then sorts them. No recurrence or multi-day expansion exists in this event schema; holidays are individual dates. |
| Holiday create/edit/delete from Calendar | POST /compliance-alerts/non-working-days, PUT /compliance-alerts/non-working-days/{id}, DELETE /compliance-alerts/non-working-days/{id}, gated by compliance-alerts.manage. | Calendar/Index opens the local form; submit uses the existing POST/PUT contract and resets on success. Closing the form and Escape still call the reset handler; the close control remains disabled while processing. No live save was attempted. |
| Compliance Alerts holiday settings | /compliance-alerts → ComplianceAlerts/Index, separate settings/calendar tab. | This page’s text + Add Non-Working Day is a legitimate primary action and remains one. Its shared CrudModalHeader close control was already a utility control; it was not restyled. |

The primary Calendar’s show MOVs switch, event details, and + more modal are local state changes; they do not initiate a request. The source contains one navigation call per toolbar/filter selection and no Calendar effect that repeats it. The year view queries a selected year for each allowed source and summarizes it in memory; this is bounded to one year but was not performance-profiled here.

## Confirmed issues and corrections

1. The left-rail plus and each in-month day plus were marked data-cds-action="true" as primary actions. They are utility controls. Both now use CalendarAddButton/UtilityIconButton without the action marker, while keeping their handlers and accessible labels. The rail remains 44 × 44 px; the day control keeps its compact 32 × 20 px target and green hover treatment. Keyboard focus remains visible.
2. The primary Calendar form close × was also tagged as a primary action. It now uses the compact utility control at 32 × 32 px. Its Close form accessible name, close/reset handler, focus and hover treatment, and processing-disabled behavior are retained. Cancel and Save remain legitimate action buttons.
3. The modal’s Escape effect depended on onClose, while CalendarIndex recreated resetForm on each render. Form typing therefore cleaned up and reattached the document key listener repeatedly. resetForm and openCreate now use useCallback with the stable useForm method references, retaining the existing cleanup and processing guard.
4. The legend used 10 px text and long labels. It now uses 11 px readable labels, compact spacing, dark-mode contrast, and wrapping. It still renders the same nine event/holiday presentation groups and uses their existing chip gradients. “Other non-working day” is the amber presentation bucket; its accessible description names local holidays, special non-working days, office-declared days, and other configured days that currently share that color. No saved type, scope, or status value changed.

The event membership, sorting, scope/permission rules, detail fields, attachment links, and save contracts were left intact. No stale-response or rapid-navigation defect was reproduced; navigation semantics were not changed.

## Matched isolated performance measurements

Measurements ran in a single warmed PHPUnit process on PHP CLI 8.3.30 with an in-memory SQLite database. Each fixture had one reports viewer with reports.view and bms.view, the same August 2026 month, module=bms, and a selected protected-area filter. The factory user had no CENRO/PAMO organizational category, so this specifically measures a module-and-PA-filtered request, not an office-category impersonation. The small fixture returned 12 selected-area events; the larger returned all 240. One same-month event for another PA and one selected-area July event were excluded, and returned IDs were checked exactly. Each measurement had one unmeasured warm-up followed by five samples; setup and fixture insertion were outside the timed windows.

| Measurement | 12-event fixture: before → after UI changes | 240-event fixture: before → after UI changes |
| --- | --- | --- |
| CalendarMovEventService::events() projection, median ms (five sorted samples) | 3.28 (3.25, 3.26, 3.28, 3.33, 3.41) → 4.77 (3.31, 3.36, 4.77, 4.80, 4.98) | 51.08 (50.35, 50.52, 51.08, 51.46, 52.02) → 53.87 (52.06, 52.55, 53.87, 54.77, 57.34) |
| Direct service SQL: calls / summed SQL ms | 3 / 0.13 → 3 / 0.14 | 3 / 0.70 → 3 / 0.78 |
| Direct event projection JSON bytes | 6,177 → 6,177 | 124,457 → 124,457 |
| Full Laravel test request, median ms (five sorted samples) | 15.69 (15.64, 15.67, 15.69, 15.77, 15.84) → 16.21 (15.74, 15.91, 16.21, 16.31, 16.54) | 68.10 (67.36, 67.72, 68.10, 69.98, 70.32) → 72.47 (68.35, 69.34, 72.47, 72.78, 80.50) |
| Request DB queries / summed SQL ms | 7 / 0.37 → 7 / 0.35 | 7 / 0.89 → 7 / 0.89 |
| Full uncompressed response body bytes | 34,378 → 34,371 | 152,886 → 152,879 |

The small/large request kept the same seven queries and direct service calls kept the same three queries; SQL time was under 1 ms in these fixtures. The authorized 240-event result was not truncated: its larger response reflects all requested event details. The post-change timing samples are close but somewhat noisier/slower, and the UI-only edits made no backend projection or query change, so these numbers show no measured server improvement or regression attributable to the UI corrections. The service projection includes SQL, hydration, normalization, URL/detail construction, and sorting; its internal subtimings were not isolated. Test-request duration is an in-process Laravel response time, not Apache wait/TTFB. Response bytes are uncompressed test bodies, not browser transfer sizes. These SQLite measurements do not predict office MySQL.

## Verification

- CalendarManagementTest.php, BusinessCalendarServiceTest.php, and the new CalendarPerformanceTest.php: 24 passed, 405 assertions, on isolated SQLite. The added regression verifies selected month/module/PA membership, exclusion of other-scope and out-of-month rows, exact results at 12 and 240 events, and constant query count. The existing Calendar suite continues to cover create/update/delete, PA/office scopes, year/month filters, and business-calendar cache lifecycle.
- tests/js/calendarRendering.test.mjs: 3 passed. It renders the actual Calendar month controls, create modal, and legend; verifies callbacks, labels, action-style exemption, target dimensions, focus/hover classes, disabled states, legend text, and the current gradient colors.
- Full frontend inventory, node --test tests/js/*.test.mjs tests/frontend/*.test.mjs: 129 passed, 1 known failure. The unchanged failure is tests/js/dateFormatters.test.mjs:10, expected Aug 29, 2026, actual August 29, 2026. The run also emitted existing Vite mixed-export warnings and WebSocket port 24678 in-use warnings; no Calendar test failed and no Windows spawn EPERM occurred.
- npm.cmd run build: PASS, Vite 8.1.4 transformed 1,431 modules and completed the production build.
- node --check tests/js/calendarRendering.test.mjs, php -l tests/Feature/CalendarPerformanceTest.php, and git diff --check: PASS. The Vite build parses the changed JSX. The existing frontend formatter mismatch was not changed or suppressed.
- Browser inventory returned no available apps or browsers. Static rendering does not prove live positioning or appearance at narrow widths or in both themes.

## Remaining live gap and owner walkthrough

No authenticated browser request was available, and this task did not restart Apache. Therefore click-to-visible, live Network request count/TTFB, live save/refresh, running web-SAPI OPcache flags, and visual appearance across desktop/mobile and light/dark themes remain UNVERIFIED. One short check when an authenticated browser and the normal office runtime are available: open the same Calendar month and filter state, exercise previous/next/Today, month/year, filter/reset, open/close both holiday and event details without saving, then inspect one navigation request in Network and check the controls/legend at a narrow width in both themes. A live before/after performance claim would still require a matched baseline under the same Apache/OPcache state.

No data was truncated and no permission/action cache, date-boundary change, notification change, or save-path change was introduced. No stage, commit, or push occurred.

## Owner summary

The targeted utility-control and legend corrections are implemented and tested. Isolated fixtures show flat query counts through 240 authorized month events, but live Calendar latency and browser appearance remain unverified; no claim is made that all lag is fixed.

Report: docs/calendar-performance-and-ui-2026-10-05.md
