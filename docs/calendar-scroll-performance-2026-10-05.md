# Calendar scrolling performance investigation — 2026-10-05

## Status

**PARTIAL.** Removed a repeated paint effect from each month day card and added a regression test against rendered sparse and populated months. The real component now renders 42 fewer per-card backdrop filters, while preserving the existing calendar grid, event visibility, controls, and shared card/legend styling. A browser was not available, so live scroll smoothness and frame timing remain **UNVERIFIED**; this report does not claim the office scrolling symptom is resolved.

## Baseline and preserved work

- Project: `C:\laragon\www\cds-system`; branch `main`; HEAD `104eba3fef628c6723ba3f74e7e1cf834a23e2cd`.
- The starting worktree already contained the lockfile update, earlier Calendar UI changes, OPcache/updater artifacts, and the previous reports/tests. I preserved these and `.recovery/`. No staging, commit, push, reset, restore, clean, stash, or updater run occurred. This task added only the scroll report and rendered regression test, and removed one repeated Calendar style class.
- No applicable project or ancestor `AGENTS.md` was present. Installed dependency `AGENTS.md` files were not relevant.
- The earlier SQLite query profile measures server-side data projection, not scrolling; it is not used as evidence here. This correction does not change backend or database code.
- CUA reported no available browser apps or browsers. Browser version, viewport, theme, office hardware, long tasks, dropped frames, paint/raster/compositing timings, forced layout, and a real scroll sequence could not be recorded.

## Scroll surface and source findings

The authenticated shell has a sticky top header and a normal document page (`min-h-screen` / `<main>`). In the month view, the date grid has a separate **horizontal** `overflow-x-auto` wrapper with an 840px minimum width; it is not the page's vertical scroller. The left navigation has its own vertical scroller. Event details, the “more events” list, and the holiday form have vertical modal scroll areas when open. No Calendar wheel/scroll handler, scroll-state update, geometry observer, or scroll-triggered request was found. Month/filter navigation uses an explicit Inertia GET; opening details and the “more events” modal uses local state.

`MonthView` creates 42 day cards for August 2026. Each card had `backdrop-blur-sm`, in addition to the already styled outer Calendar section, content card, filter rail, and legend. The card backgrounds are translucent over the Calendar's plain surface, so the per-day blur repeatedly filters an uncomplicated backdrop. This is a confirmed, unnecessary per-cell effect. It is a plausible scroll paint/compositing cost, but without a browser trace its contribution to the reported lag is **not confirmed**.

The component groups event props when they change; it does not regroup them on scroll. Each day shows up to three MOV chips and retains the existing “more” control for additional events. The rendered fixture verified those counts at both 12 and 240 events. No events are truncated from the data. The cell shadow and hover transition, shared card surfaces, navigation, date math, permission checks, filters, event details, and modal behaviors were not changed.

## Correction and comparable rendered measurements

Removed `backdrop-blur-sm` only from the repeated `CalendarDay` wrapper in [BusinessCalendarMonth.jsx](/C:/laragon/www/cds-system/resources/js/Components/BusinessCalendarMonth.jsx:177). The broader four Calendar surface/legend filters remain. Added [calendarScrollPerformance.test.mjs](/C:/laragon/www/cds-system/tests/js/calendarScrollPerformance.test.mjs:1), which renders the actual component through Vite's SSR loader with identical August 2026 fixtures before/after the change and checks day geometry, visible MOV chips, “more” buttons, and retained shared surfaces.

| Actual component fixture | Day cards | Day-card backdrop filters | All Calendar backdrop filters | MOV chips / “more” buttons | Rendered markup bytes |
| --- | ---: | ---: | ---: | ---: | ---: |
| 12 events, before | 42 | 42 | 46 | 12 / 0 | 33,384 |
| 12 events, after | 42 | 0 | 4 | 12 / 0 | 32,670 |
| 240 events, before | 42 | 42 | 46 | 84 / 28 | 68,356 |
| 240 events, after | 42 | 0 | 4 | 84 / 28 | 67,642 |

The matching fixtures use synthetic BMS events spread over 28 August dates; the populated month therefore retains three visible chips and a “more” control on each populated date. Both cases reduce rendered markup by 714 bytes because the removed class was repeated once for each of the 42 day cards. These are **static rendered-markup counts**, from Node 24.18.0 and Vite 8.1.4 in middleware/SSR mode. They are not browser frame, scroll, layout, or paint measurements and do not establish a live speedup.

## Runtime diagnostic

The owner reports that Apache was stopped and started before this investigation. I made no Apache or PHP setting changes and did not restart it. A read-only request to the configured `/login` URL returned HTTP 200 but did not include the guarded `X-CDS-Perf-Runtime` header. With no authenticated browser request available, the running web SAPI's OPcache flags and authenticated Calendar response could not be verified. The login response is not evidence of OPcache activation or Calendar responsiveness.

## Verification

- Targeted Calendar render checks, including the existing controls/legend tests and the new month-surface regression: **4 passed**. The new test verifies 42 cells, 0 per-cell filters, 4 retained shared filters, and unchanged chip/“more” membership for both fixtures.
- Full frontend inventory (`node --test tests/js/*.test.mjs tests/frontend/*.test.mjs`): **130 passed, 1 failed**. The sole failure is the known unchanged `tests/js/dateFormatters.test.mjs:10` mismatch: expected `Aug 29, 2026`, got `August 29, 2026`. Existing Vite mixed-export and WebSocket port `24678`-in-use warnings appeared; they did not fail the Calendar tests.
- Production build (`npm.cmd run build`): **PASS**, Vite 8.1.4 transformed 1,431 modules and completed. `public/hot` was absent before the build.
- `node --check tests/js/calendarScrollPerformance.test.mjs` and `git diff --check`: **PASS**. The Vite SSR test and production build parsed the changed JSX.
- No browser verification of vertical page scroll, horizontal grid scroll, navigation, filters, modals, themes, or narrow widths was possible. No server/database test was rerun because no server-side code changed.

## One short remaining check

In the normal office browser, scroll once through the same populated month and note whether it feels smoother. If lag persists, a brief DevTools Performance capture of that same document scroll is needed to distinguish main-thread work from paint/compositing; no screenshot set is needed.

**Owner summary — PARTIAL:** the repeated per-day blur is removed and the real rendered component regression checks pass, but live office scrolling was unavailable for verification. The existing Aug/August test failure remains unchanged.
