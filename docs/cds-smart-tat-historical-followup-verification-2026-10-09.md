# CDS Smart TAT and Historical Reports — follow-up verification

**Date:** 2026-10-09
**Scope:** PAMO/CENRO office TAT and historical report encoding across active report workflows.

## Result and scope

**PAMO/CENRO only ang TAT; existing PENRO routing ug required receipt dates nagpabilin. Ang past report naa sa iyang module, original dates ang basehan, ug wala sa Incoming/Outgoing/History.**

PA records are attributed to their authorized PAMO; ENGP records are attributed to their assigned CENRO. Analytics and configuration accept only `PAMO` and `CENRO`, including server-side validation. A forged PENRO category is rejected. PENRO roles, normal custody events, routing settings, direct-to-PENRO exceptions, and PENRO receipt/release milestones remain in place for established source timeliness calculations.

Historical rows are written to the existing source module with historical provenance and original milestones. They appear in that module's list/detail and protected attachment preview. Tracking queues, counts, filters, options, and details exclude them from Incoming, Outgoing, and History. Encoding does not create routing events/snapshots/references, notifications, jobs, or a live ENGP release event. Ordinary update, transition, and internal-routing endpoints reject a historical row. A repeated historical submission is idempotent and does not replace an existing live same-period ENGP report.

Missing source evidence, deadline, office SLA, or positive volume target remains N/A where applicable. The draft 50/35/15 weights are retained without redistribution. No SLA or production volume target was invented. Live office TAT uses accepted event intervals; historical intervals are labelled manual evidence and excluded from the event-derived TAT totals and average.

## Active workflow coverage matrix

The current registry contains **42 encodable workflow keys**: 22 conservation/PA workflows, 12 ENGP workflows, and 8 other module workflows. For each row, the historical catalog/encoder/detail path uses the source module's list and protected attachment mapping. Dates below are original report inputs; deadlines are computed from the source workflow rules, not the encoding date. `Standard` working days use the conservation calendar; PAMB working days use the PAMB calendar.

### Conservation and PA workflows (22)

| Workflow key | Reporting period | Date basis and historical TAT rule |
| --- | --- | --- |
| `restoration_plan_5_year` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |
| `additional_bms_site` | 1st/2nd Semester | Required Date Conducted From; To optional and defaults to From; accomplishment date; 15 Standard working days, Standard A. |
| `bdfap` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |
| `bdfe_terrestrial` | Q1–Q4 | Required Date Conducted From; To optional and defaults to From; accomplishment date; 7 Standard working days, Standard B. |
| `cepa_plan` | Q1–Q4 | Original accomplishment date; progress: 7 Standard working days, Standard B; final: 15 Standard working days, Standard A. |
| `ecotourism_management_plan` | 1st/2nd Semester | Original accomplishment date; 7 Standard working days, Standard B. |
| `homestay` | Q1–Q3 | Required Date Conducted From; To optional and defaults to From; accomplishment date; 15 Standard working days, Standard B. |
| `maintenance_buoy` | Q1–Q4 | Original accomplishment date; 15 Standard working days, Standard B. |
| `maintenance_monuments` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |
| `maintenance_pa_information_system` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |
| `maintenance_pamo_ecotourism` | 1st/2nd Semester | Required Date Conducted From; To optional and defaults to From; accomplishment date; 7 Standard working days, Standard B. |
| `management_effectiveness_assessment` | 1st/2nd Semester | Original accomplishment date; 7 Standard working days, Standard B. |
| `monitoring_mangroves_corals_seagrass` | Q1–Q4 | Original accomplishment date; 15 Standard working days, Standard A. |
| `mpan` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |
| `regular_pamb` | Q1–Q4 | Date Conducted required; use original Date Accomplished when present, otherwise Date Conducted; 7 PAMB working days, Standard B. |
| `rehabilitation_pa_office` | 1st/2nd Semester | Original accomplishment date; 7 Standard working days, Standard B. |
| `special_pamb` | Q1–Q4 | Date Conducted required; use original Date Accomplished when present, otherwise Date Conducted; 7 PAMB working days, Standard B. |
| `twc_meetings` | Q1–Q4 | Date Conducted required; use original Date Accomplished when present, otherwise Date Conducted; 7 PAMB working days, Standard B. |
| `updating_pamb_manual` | 1st/2nd Semester | Original accomplishment date; progress: 7 PAMB working days, Standard B; final updated manual: 15 PAMB working days, Standard A. |
| `updating_pamp` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |
| `vtol_operations` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |
| `water_quality_monitoring` | Q1–Q4 | Original accomplishment date; 7 Standard working days, Standard B. |

### ENGP workflows (12)

ENGP uses its original CENRO, reporting year, and reporting period key. It has no PA activity-date input. Deadlines and allowed office choices come from the active ENGP registry for each period.

| Workflow key | Periods / deadline rule | Historical timeliness evidence |
| --- | --- | --- |
| `cenro_nursery_seedling` | Monthly; due on day 20. | Original `date_received_penro` compared with the active period deadline. |
| `cbep` | Monthly; due on day 20. | Same original receipt/deadline comparison. |
| `elcac` | Monthly; due on day 20. | Same original receipt/deadline comparison. |
| `weekly_accomplishment` | Weekly; 2026 registry emits 51 periods. First two due on day 20 of the start month; later periods due on the Thursday period end. The 2026-08-31 period is absent in the active registry. | Same original receipt/deadline comparison. |
| `forest_disturbance` | Monthly; due on day 20. | Same original receipt/deadline comparison. |
| `monthly_accomplishment_pmd_fmb` | Monthly; due on day 20. | Same original receipt/deadline comparison. |
| `ngp_produce` | Quarterly; due on day 10 of the quarter's final month; 3 release components. | Original PENRO receipt and CENRO release are retained; release-to-receipt interval is reported only as manual historical CENRO evidence. |
| `ngp_staff_accomplishment` | Monthly; due on day 20; registry allows 3 offices. | Original receipt/deadline comparison. |
| `nursery_maintenance` | Quarterly; due on day 10 of the quarter's final month; 3 release components. | Original receipt/deadline comparison; original release milestones are preserved. |
| `site_visit` | Quarterly; due on day 10 of the quarter's final month; 3 release components. | Original receipt/deadline comparison; original release milestones are preserved. |
| `tree_replacement` | Monthly period key; the active document label says “Weekly Report.” | Original receipt/deadline comparison; source label is preserved. |
| `rims` | Monthly; January due on January 29, other months due on day 20. | Original receipt/deadline comparison. |

ENGP timeliness compares the original PENRO receipt against the source `deadline_submission` using the existing calendar-day rule and rating. The CENRO interval evidence requires an original CENRO release date no later than the original PENRO receipt; its elapsed days are displayed separately from live event TAT and never attributed to PENRO custody. Missing dates remain missing evidence. The ENGP “Summary Monitoring” page is a rollup of 11 active workflow summaries, excludes `weekly_accomplishment`, and is not a separate historical workflow.

### Other module workflows (8)

| Workflow / source module | Original inputs and deadline/timeliness rule |
| --- | --- |
| `bms:bms` / BMS | Required semester, conducted range, and accomplishment date. To date is optional and defaults to From. Existing Standard A calculator: 15 conservation working days from accomplishment; original PENRO receipt drives the result. |
| `bams:bams` / BAMS | Same semester and conducted-range inputs; same 15 conservation working days, Standard A calculation from accomplishment and original receipt. |
| `imea:imea` / IMEA | Same semester and conducted-range inputs; same 15 conservation working days, Standard A calculation from accomplishment and original receipt. |
| `imea-maintenance:imea-maintenance` / IMEA Maintenance | Required quarter, conducted date, and accomplishment date; 7 conservation working days from accomplishment, Standard B. |
| `aws:aws` / AWS | Original year, quarter, report-period type/value, document type, monitoring range, and optional accomplishment date. Existing AWS limit is 7 conservation working days from accomplishment; without accomplishment date the deadline is N/A. |
| `ipaf-management:ipaf-management` / IPAF Management | Required conducted/accomplishment dates and document type; 7 conservation working days from accomplishment, Standard B. |
| `revenue:revenue` / IPAF Revenue | Original reporting month/year, amount collected, and explicitly entered source deadline. Existing signed working-day difference compares the original deadline and PENRO receipt by office; the rating also requires original CENRO release and PENRO receipt dates. No dates are inferred. |
| `management-plans:management_plans` / Management Plans | Active management-plan type, semester, conducted/accomplishment dates, and multiple source attachments. Existing plan rule: CEPA final is 15 conservation working days, Standard A; CEPA progress, updating PAMP, restoration, ecotourism and general plans use their configured 7-day Standard B rules. |

## Verification evidence and limits

**Code paths reviewed during the original feature pass (2026-10-09):** historical catalog, encoder, controller, source list/detail views, protected attachment descriptors, tracking filters, Office TAT analytics/configuration validation, current workflow registries, and the two feature migrations. Ten source families are represented. The runtime registry was enumerated for all 42 keys. The matrix's source calculations were checked against the active registry/calculator paths. Isolated end-to-end historical encode/list/detail/attachment and tracking-isolation fixtures cover BMS and ENGP; the remaining workflow entries were registry/source-path checked, not each populated by an individual fixture.

**Automated checks at the original installation revision (2026-10-09; historical evidence, not rerun on October 10):**

- PHP: 178 tests passed, 965 assertions across `HistoricalReportEncodingTest`, `SubmissionTrackingTest`, `BmsSubmissionTrackingQueueTest`, `ConservationReportWorkflowTest`, and `EngpReportTest`.
- JavaScript: all 139 tests in the current complete `tests/js/*.test.mjs` inventory passed in serial mode (0 failed, 0 skipped). The runner emitted non-failing WebSocket “port already in use” messages during UI cases.
- PHP syntax checks passed for changed PHP files; `git diff --check` passed.
- Production Vite build passed (1,443 modules transformed).
- Permission cache reset ran after installation.

**Historical local installation observations (2026-10-09; not current-state verification):** The Laravel runtime was confirmed to target the intended local relational schema. A protected pre-install backup was restored into an isolated scratch schema and checked against the source before migration. The two feature migrations were applied in that installation. At that time, the resulting schema had 78 tables, including `historical_report_origins` and `office_tat_configuration_versions`; both feature tables were empty. All ten source tables had the `is_historical` column, and their row counts/max IDs matched the pre-install inventory. Other table counts were unchanged apart from the expected migration, permission, and feature-table changes. The v2 routing settings, disabled switches, and ten cutover watermarks matched the pre-install snapshot. Routing-event, snapshot, and notification counts were unchanged. The four feature permissions and role links matched the migration policy; configure permission was limited to CDS Admin and Super Admin. User and role assignments were not changed.

The installation check recorded that its database service was available and no application web server had been started. A brief maintenance-mode change was cleared and verified at that time. No report or real routing action was created. These runtime observations describe October 9 only. Browser automation was unavailable during the October 10 follow-up, so appearance, responsive layout, dark mode, keyboard/focus, loading/error/empty states, and preview behavior remain **visually unverified**. Owner check after opening the app: inspect Administration → Office TAT and Settings → Historical Reports at desktop and narrow widths; try keyboard focus, dark mode, empty/error states, and a protected attachment preview; confirm only PAMO/CENRO TAT tabs and that an older-quarter historical record appears only in its module.

The work remains uncommitted and unpublished. Existing unrelated working-tree changes and `.recovery/` were preserved.

## Owner screenshot review follow-up (2026-10-10)

The four owner captures were taken on 2026-10-09 and reviewed as supplied: `screencapture-cds-system-test-admin-office-tat-2026-10-09-23_19_09.png` (PAMO Office TAT), `screencapture-cds-system-test-admin-office-tat-2026-10-09-23_20_24.png` (CENRO Office TAT), `screencapture-cds-system-test-settings-historical-reports-2026-10-09-23_23_05.png` (Historical Reports), and `screencapture-cds-system-test-settings-historical-reports-2026-10-09-23_25_17.png` (encoder modal). These are desktop/light screenshots; no fresh browser capture or live interaction was available on October 10.

| Step | Captured state and health | Follow-up |
| --- | --- | --- |
| 1. PAMO Office TAT | The live and manual tables both show zero completed samples, N/A averages, and no ranks. This matches the displayed counts; it does not show a populated ranking. The report group and interval basis needed a clearer distinction. | Copy now says PA reports are grouped by assigned PAMO and that CENRO/PENRO dates are not PAMO processing time. PAMO rows say no PAMO receipt-to-handoff interval is recorded. |
| 2. CENRO Office TAT | Four CENRO rows and separate live/manual tables are visible, with no completed samples. The screenshot cannot verify durations, order, ties, or comparison bars. | The CENRO historical table now identifies ENGP report grouping by assigned CENRO and the original CENRO-release-to-PENRO-receipt interval. |
| 3. Historical Reports | Settings placement, encode action, filters, policy cards, and the empty recent-record state are visible. This capture does not prove that a report was saved or appears in its source module. | No change required from this capture. Save behavior was covered in isolated backend tests below. |
| 4. Encoder modal | The screenshot shows shared fields, blank date pickers, original milestones, and the clipped `lucide:archive` text defect. It does not show popup, validation, save, upload, or preview behavior. | The page now passes an actual Iconify React element to `CrudFormModal`, whose header accepts a React node. The shared modal API was not changed. This correction has not been visually recaptured in a browser. |

### Attribution and interval evidence

Source inspection confirmed that PA records are grouped under their assigned PAMO, while ENGP records are grouped under their assigned CENRO. A PA report's original CENRO release and PENRO receipt do not establish PAMO handling time. The isolated PA regression fixture supplied both dates and confirmed that the PAMO completed-interval count remains zero. No backend mapping or duration formula was changed; the interface now describes the distinction explicitly. The CENRO historical measurement remains the original release-to-receipt interval for reports assigned to that CENRO.

### October 10 checks

- PHP: three focused historical tests passed on October 10 (267 assertions total): the updated BMS historical-encoding fixture (optional conducted-range end, blank optional milestone, protected attachment response), an older-quarter Q1 conservation record (original period/dates, 7-working-day source deadline, original-receipt timeliness, actual `encoded_at`, source-module visibility, protected attachment response, PAMO attribution boundary, and tracking/action/side-effect isolation), and future actual-date rejection versus an allowed future Revenue deadline. The final review later reran the older-quarter fixture after fixing live and manual timeliness date-cast handling; see the October 10 final review note.
- JavaScript: 42 focused checks passed serially: `officeTatScope` 4/4, `officeTatEvidence` 1/1, `historicalReportEntry` 2/2, `globalControlRendering` 29/29, and `calendarRendering` 6/6. The calendar check verifies the Philippines date cap and leaves an empty date unselected.
- PHP syntax check passed for `HistoricalReportEncodingTest.php`.
- Production Vite build passed on October 10 (1,445 modules transformed).
- The tests used PHPUnit's in-memory SQLite database and fake local storage. Archive-table, routing-event, snapshot, notification, job, and release-event counts stayed unchanged. No migration or live report was created.

The supplied screenshots remain the only visual evidence. Modal calendar/search popups, long-label behavior, scroll/footer behavior, validation retention, pending/disabled controls, visible attachment preview, narrow layout, dark mode, and a populated ranking remain **visually unverified**. Browser automation was unavailable in this session. The owner should inspect the corrected screens in the app before treating those visual and interaction checks as complete.

The work remains uncommitted and unpublished. No installation, migration, routing-setting change, or `.recovery/` change was made for this follow-up.
