# Calendar control and scroll follow-up — 2026-10-05

## Status

- **Control corrections: PASS.** The separate Non-Working Day rail `+` is restored to its pre-change primary styling and remains permission-gated. The day-level Calendar `+` remains a utility control; the Holiday form close `X` and corrected legend remain unchanged. All twelve year month headings now have restrained Calendar styling, native button semantics, accessible names, a visible selected-month state, and focus treatment.
- **Live scroll verification: UNVERIFIED.** No browser was available for this follow-up, so no real month/year scroll sequence, frame timing, or visual glitch reproduction was possible. Source tracing and matched component-render counts support narrow reductions in moving/filter effects; they do not prove that office scrolling is smooth.

## Baseline and preservation

- Project `C:\laragon\www\cds-system`, branch `main`, HEAD `104eba3fef628c6723ba3f74e7e1cf834a23e2cd`.
- Preserved the existing lockfile change, dependency/runtime reports, updater, Calendar UI changes, prior scroll fix/report, `.recovery/`, and unrelated worktree files. No staging, commit, push, reset, restore, clean, stash, dependency/runtime change, operational edit, or form submission occurred. The rail control’s old markup was compared with `git show HEAD:resources/js/Components/BusinessCalendarMonth.jsx` and restored in place; no file-wide rollback was used.
- No project or ancestor `AGENTS.md` applies. Read the earlier Calendar UI and scroll reports and this follow-up runbook. CUA again reported no browser apps or browsers. No authenticated runtime diagnostic was available; the owner-reported Apache restart was not repeated and is not treated as proof of OPcache state.

## Exact control corrections

| Control | Result |
| --- | --- |
| Non-Working Day `+` beside the rail label | Restored the exact pre-change 44px green-gradient primary button, accessible label/title, focus treatment, action marker, and `onAdd('')` callback. It is still rendered only when `canManage` is true. This is the rail control, not the day-level Calendar `+`. |
| Day-level Calendar `+` | Retained the compact `CalendarAddButton` utility styling and date callback; no shared action marker was reintroduced. |
| Holiday form close `X` | Retained the neutral 32px utility control, accessible “Close form” name, and processing-disabled behavior. |
| Year-view January–December headings | Removed both shared action opt-ins (`data-cds-action` and `cds-button-interaction`). All twelve remain native `type="button"` month selectors with `Open {Month} {Year}` accessible names, a focus ring, color-only hover transition, and the same click-to-month callback. The month carried by the existing `month` prop is distinguished with `aria-current="date"` and a restrained green surface. Dark and light text/surface classes are explicit. The responsive 1/2/3/4-column layout and date cells are unchanged. |
| Compliance Alerts settings `+ Add Non-Working Day` | Left unchanged; it remains a separate text primary action. |

The restored rail button and year selector are in [BusinessCalendarMonth.jsx](/C:/laragon/www/cds-system/resources/js/Components/BusinessCalendarMonth.jsx:132); the scroll-related card style is at line 178 in the same file. The scroll-surface regression is in [calendarScrollPerformance.test.mjs](/C:/laragon/www/cds-system/tests/js/calendarScrollPerformance.test.mjs:1), and the control regressions are in [calendarRendering.test.mjs](/C:/laragon/www/cds-system/tests/js/calendarRendering.test.mjs:1).

## Scroll surface and findings

The Calendar’s vertical page scroll is the document under the authenticated shell’s sticky header. The month grid’s nested scroller is horizontal only. The year grid grows in the document and retains its responsive columns; neither Calendar view has an internal vertical scroll area. The left navigation has an independent vertical scroller. Event details, the “more events” list, and the holiday form have intentional vertical scrolling while open.

No Calendar wheel/scroll listener, geometry read/write loop, observer, scroll-state React update, or scroll-triggered request was found. `BusinessCalendarMonth` uses memoized month-day and event grouping derived from props. Month/year navigation and filters explicitly use Inertia GET with `preserveScroll: true`; local detail and “more” controls use component state. No Calendar-specific fixed/sticky content or entry animation was found.

**Confirmed source/render facts:**

- After the previous pass removed month-cell backdrop filters, 31 August in-month cards still had a `hover:-translate-y-px` transform. If hover state changes as document content passes under a stationary pointer, those cards can visibly move by one pixel. The transform was removed while retaining the existing border/shadow hover feedback.
- Before this pass, all twelve year mini-cards still had `backdrop-blur-sm`. The repeated effect was removed; the three shared shell/rail/content filters remain.
- Each year month heading had both shared action styling hooks. The shared CSS applies hover/active translate and heavy shadow behavior to those hooks. They are now local color/focus styles without positional transforms.
- The year mini-cards have natural month-dependent heights, and the grid equalizes each row; no fixed-height or max-height year scroller was found. The root Calendar section still uses `overflow-hidden` for its rounded surface. The event “more” modal is nested below that section; fixed-overlay clipping is a browser-specific possibility that could not be reproduced here, so its placement was left unchanged.

The first three facts identify unnecessary, scroll-sensitive CSS effects. Their exact DOM/class presence is verified below. Whether these effects caused the owner’s remaining visual glitch is still a hypothesis without a browser trace. No claim is made that the live issue is fixed. No event data, dates, timezone rules, holiday membership, filters, permissions, or click targets changed.

## Comparable rendered fixtures

The actual `BusinessCalendarMonth` component was rendered through the same Vite SSR middleware test harness before and after this follow-up. Both month fixtures use August 2026 with synthetic BMS events; the populated fixture has 240 events distributed over 28 dates. The year fixture renders all twelve 2026 cards with the same August selected-month context. These are matched static markup counts, not scroll/frame measurements.

| View and fixture | Metric | Before follow-up | After follow-up |
| --- | --- | ---: | ---: |
| Month, 12 events | Day cards / per-day backdrop filters | 42 / 0 | 42 / 0 |
| Month, 12 events | In-month hover-translate cards | 31 | 0 |
| Month, 12 events | Shared Calendar backdrop filters | 4 | 4 |
| Month, 12 events | MOV chips / “more” controls | 12 / 0 | 12 / 0 |
| Month, 240 events | Day cards / per-day backdrop filters | 42 / 0 | 42 / 0 |
| Month, 240 events | In-month hover-translate cards | 31 | 0 |
| Month, 240 events | Shared Calendar backdrop filters | 4 | 4 |
| Month, 240 events | MOV chips / “more” controls | 84 / 28 | 84 / 28 |
| Year, 12 month cards | Per-card / all Calendar backdrop filters | 12 / 15 | 0 / 3 |
| Year, 12 month selectors | Shared action-styled selectors | 12 | 0 |
| Year, selected month | `aria-current="date"` markers | 0 | 1 |

The year headings now include explicit accessible names and focus classes, so their serialized HTML is larger; byte size is not used as a performance claim. Event visibility and the existing “more” affordance are unchanged in both month fixtures. The previous correction remains intact: month day cards have zero repeated backdrop filters.

## Verification and remaining gaps

- Targeted Calendar rendering checks: **6 passed**, including the restored rail control/callback/authorization, the utility day-level `+`, neutral Holiday `X`, legend, all twelve accessible year selectors, selected-month styling, native button activation, preserved active date cells, and month/year surface counts.
- Full frontend inventory (`node --test tests/js/*.test.mjs tests/frontend/*.test.mjs`): **132 passed, 1 failed**. The only failure remains the known, unchanged `tests/js/dateFormatters.test.mjs:10` mismatch: expected `Aug 29, 2026`, actual `August 29, 2026`. Existing Vite mixed-export and WebSocket port `24678`-in-use warnings appeared; Calendar tests passed.
- Production build (`npm.cmd run build`): **PASS**, Vite 8.1.4 transformed 1,431 modules and completed. `public/hot` was absent before the build.
- The changed JSX was parsed by the production build and Vite SSR tests. Changed test files passed `node --check`; `git diff --check` passed.
- No browser version, viewport, light/dark live view, pointer/scroll sequence, Network recording, dropped-frame/long-task trace, paint/compositing trace, or live office scroll improvement was available. No backend code changed, so backend tests were not rerun.

## One short remaining check

In the normal office browser, scroll once through a populated month and the year view and note whether either still jumps or flickers. If it does, one brief DevTools Performance capture of that same scroll is enough to locate the remaining work; no screenshot set is needed.

**Owner summary:** the control styling is corrected and rendered regressions pass. Scroll-sensitive transforms and repeated year-card filters were removed, but live month/year scroll verification remains unavailable.
