# CDS-SMART compliance-first Office TAT verification

**Date:** 2026-10-11
**Scope:** Separate Conservation and Development standings, based on the current protected-area and ENGP catalogues. The existing receipt-based Average TAT, PAMO preparation, and historical date-gap measurements remain separate.

## Implementation

- `app/Services/OfficeTatComplianceAssessmentService.php` builds the two rosters, creates the period duty inventory, computes TC/SE/OTPS, withholds incomplete scores, pools eligible obligations, and ranks only within a unit.
- `app/Services/Reports/ReportRequirementRegistry.php` now exposes the canonical beginning of a monthly, quarterly, semestral, annual, weekly, or explicit source period.
- `app/Http/Controllers/OfficeTatController.php` and `routes/web.php` expose the assessment only to users with global Office TAT view access. Scope, technical-classification, legacy configuration, and tie-decision writes require `office-tat.configure` and are revalidated by the service.
- `database/migrations/2026_10_11_000002_create_office_tat_compliance_assessment_tables.php` defines versioned PA applicability, technical classifications, and auditable tie decisions. Missing tables are detected and displayed as unavailable rather than written around.
- `resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx` renders separate unit summaries, standings, evidence details, configuration controls, and reserved tie positions. `resources/js/Pages/Admin/OfficeTat/Index.jsx` keeps legacy weighted score/configuration secondary and explicitly distinct from the fixed compliance-first OTPS.

## Current roster and source mapping

The following values were read from the local directory on 2026-10-11. IDs are stable source identities; no rows were created, renamed, activated, or rebound.

### Conservation roster

| PA ID | Protected Area | Current legal status | Normalized assigned PAMO |
| ---: | --- | --- | --- |
| 1 | Mati Protected Landscape (MPL) | Active | None recorded |
| 2 | Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS) | Active | None recorded |
| 3 | Pujada Bay Protected Landscape and Seascape (PBPLS) | Active | None recorded |
| 4 | Aliwagwag Protected Landscape (APL) | Proposed | None recorded |
| 5 | Baganga Mangrove Swamp Forest Reserve | Proposed | None recorded |
| 6 | Baganga Proteceted Landscape | Proposed | None recorded |

The existing spelling `Proteceted` is retained as source data. All six records participate in assessment; Proposed is not treated as Inactive. A PA's normalized PAMO binding is shown only when the organizational assignment relation contains one. The three operational PA/PENRO/CENRO mappings remain unresolved and are not inferred here.

The active generic PA catalogue has 22 workflows. Applicability is a per-PA decision; there is no saved PA-to-workflow inventory in the current database. The scope editor stores versioned workflow keys and a completeness flag, but a checked box does not create an official score by itself.

| Workflow key | Catalogue label | Period | Current source allowance and day rule |
| --- | --- | --- | --- |
| `restoration_plan_5_year` | 5 Year Restoration Plan Preparation | Quarterly | 7 Conservation working days, Monday-Thursday |
| `additional_bms_site` | Additional BMS Site | Semestral | 15 Conservation working days, Monday-Thursday |
| `bdfap` | BDFAPs in PAs | Quarterly | 7 Conservation working days, Monday-Thursday |
| `bdfe_terrestrial` | BDFE for Terrestrial PAs | Quarterly | 7 Conservation working days, Monday-Thursday |
| `cepa_plan` | CEPA Plan | Quarterly | 7 Conservation working days, Monday-Thursday; Final CEPA Plan / Final Report has a 15-day source rule |
| `ecotourism_management_plan` | Ecotourism Management Plan | Semestral | 7 Conservation working days, Monday-Thursday |
| `homestay` | Homestay | Quarterly | 15 Conservation working days, Monday-Thursday |
| `maintenance_buoy` | Maintenance of Buoy | Quarterly | 15 Conservation working days, Monday-Thursday |
| `maintenance_monuments` | Maintenance of Monuments | Quarterly | 7 Conservation working days, Monday-Thursday |
| `maintenance_pa_information_system` | Maintenance of PA Information System | Quarterly | 7 Conservation working days, Monday-Thursday |
| `maintenance_pamo_ecotourism` | Maintenance of PAMO or Ecotourism | Semestral | 7 Conservation working days, Monday-Thursday |
| `management_effectiveness_assessment` | Management Effectiveness Assessment | Semestral | 7 Conservation working days, Monday-Thursday |
| `monitoring_mangroves_corals_seagrass` | Monitoring Mangroves, Corals, Seagrass | Quarterly | 15 Conservation working days, Monday-Thursday |
| `mpan` | MPAN | Quarterly | 7 Conservation working days, Monday-Thursday |
| `regular_pamb` | Regular PAMB Meetings | Quarterly | 7 PAMB working days, Monday-Thursday |
| `rehabilitation_pa_office` | Rehabilitation of PA Office | Semestral | 7 Conservation working days, Monday-Thursday |
| `special_pamb` | Special PAMB Meetings | Quarterly | 7 PAMB working days, Monday-Thursday |
| `twc_meetings` | TWC Meetings | Quarterly | 7 PAMB working days, Monday-Thursday |
| `updating_pamb_manual` | Updating of PAMB Manual Operations | Semestral | 7 PAMB working days, Monday-Thursday; Final Updated Manual uses 15 source working days |
| `updating_pamp` | Updating of PAMP | Quarterly | 7 Conservation working days, Monday-Thursday |
| `vtol_operations` | Vertical Take Off and Landing Operations | Quarterly | 7 Conservation working days, Monday-Thursday |
| `water_quality_monitoring` | Water Quality Monitoring within PA | Quarterly | 7 Conservation working days, Monday-Thursday |

For each PA workflow, the activity/document pairs come from `requirement_metadata.activity_documents`. Starts and deadlines for *submitted* Conservation rows follow the existing source model: most use `date_accomplished`; the PAMB meeting workflows (`regular_pamb`, `special_pamb`, `twc_meetings`) use `date_accomplished`, falling back to `date_conducted`. The model's `deadline_submission` accessor applies the existing module or PAMB deadline helper. The accepted receipt endpoint is `date_received_penro`. Source dates use Asia/Manila date semantics and the shared calendar. Submitted rows now use that event-specific start and source deadline in the detail calculation; duplicate live rows resolve to the earliest dated PENRO receipt and remain one obligation.

For a *missing* Conservation report, the source event start and event-derived deadline do not exist. A period boundary from the catalogue is not substituted as proof of an event start. Before a due miss can be scored, an approved per-PA obligation/start/deadline inventory and complete applicability map are required. Those per-obligation dates are not currently stored by the new scope table. Conservation coverage therefore stays incomplete and its official TC, SE, OTPS, due counts, volume, and ranks stay N/A until the source inventory exists.

### Development roster and ENGP catalogue

| Office ID | CENRO | Active |
| ---: | --- | --- |
| 1 | CENRO Baganga | Yes |
| 2 | CENRO Manay | Yes |
| 3 | CENRO Lupon | Yes |
| 4 | CENRO Mati | Yes |

All 12 current ENGP workflows apply to the four listed offices except `ngp_staff_accomplishment`, which applies to Baganga, Lupon, and Mati and not Manay.

| Workflow key | Catalogue label | Period | CENRO scope |
| --- | --- | --- | --- |
| `cenro_nursery_seedling` | CENRO Nursery Seedling Production and Disposition | Monthly | All four |
| `cbep` | Community-Based Employment Program (CBEP) | Monthly | All four |
| `elcac` | End Local Communist Armed Conflict (ELCAC) | Monthly | All four |
| `weekly_accomplishment` | ENGP Weekly Accomplishment | Weekly | All four |
| `forest_disturbance` | Forest Disturbance | Monthly | All four |
| `monthly_accomplishment_pmd_fmb` | Monthly Accomplishment Reports using PMD and FMB Template | Monthly | All four |
| `ngp_produce` | NGP Produce | Quarterly | All four |
| `ngp_staff_accomplishment` | NGP Staff Monthly Accomplishment | Monthly | Baganga, Lupon, Mati |
| `nursery_maintenance` | P/CENRO Nursery Maintenance | Quarterly | All four |
| `site_visit` | Site Visit | Quarterly | All four |
| `tree_replacement` | Tree Replacement | Monthly reporting periods | All four |
| `rims` | Updating and Operationalization of RIMS for NGP Physical Accomplishments | Monthly | All four |

The ENGP duty catalogue is `EngpReportWorkflowRegistry` backed by `ReportRequirementRegistry`; its period/activity/document identity is resolved before submissions are joined. For the current local definitions, the source deadline mode is `custom`, and the generated deadline is authoritative: monthly reports are due on the 20th (RIMS January uses the 29th); quarterly reports are due on the 10th of the quarter-ending month; weekly periods start Monday, end Thursday, and use the registry's week-specific deadline (the first two generated weeks use the configured day 20, later weeks end Thursday; 2026 excludes the configured 2026-08-31 start). The catalogued period start supplies the ENGP start. The current custom rule measures T and D as elapsed calendar dates; if a future active workflow selects standard working days, the shared Monday-Friday calendar is used. `date_received_penro` is the authoritative submission endpoint. The stored row's `deadline_submission` must equal the registry deadline. All date comparisons/cutoffs use Asia/Manila date semantics.

The source identity is `workflow_key + office + reporting_year + period_key`. ENGP has a database uniqueness constraint on that identity; its release/correction events do not add another duty or another submission count. PA rows are grouped by PA/workflow/period/activity/document. Where multiple live rows match one PA obligation, only the earliest non-null `date_received_penro` is selected and `source_rows_matched` exposes the duplicate count. Historical-origin rows are counted separately and excluded from live matching. An unfinished row without a PENRO receipt is not fulfillment.

## Formula, pooling, and ranking

For each participant, N is the verified number of obligations whose source deadline is on or before the Manila assessment cutoff.

```text
TC = 100 * on-time obligations / N
SE_i = max(0, (T_i - D_i) / T_i) * 100
SE = sum(SE_i for all N due obligations) / N
OTPS = 0.80 * TC + 0.20 * SE
Early % = 100 * obligations received strictly before deadline / N
```

A verified miss contributes zero SE and stays in N. The deadline date is on time, but not early. A valid zero-duration receipt earns 100 SE; receipt at T or later earns zero. Nonpositive T, negative chronology, unavailable endpoint/deadline, or unverified historical absence blocks the affected official score. No-due is N/A, not 100. Future duties are excluded from N; an eligible receipt before its future deadline can contribute verified volume only. Unique valid receipt volume is not an OTPS component.

Unit TC and SE pool their eligible obligations (`sum(on-time)/sum(N)` and `sum(per-obligation SE)/sum(N)`); they are not office averages. The isolated regression fixture produces TC 60%, SE 36%, OTPS 55.20 from one participant at N=2/TC=100/SE=60 and another at N=8/TC=50/SE=30.

Ranking is within each unit and compares unrounded ratios in this priority: TC, SE, Early %, unique valid submitted volume, highly technical on-time count, then an authorized recorded manual order. OTPS is display only. Exact ties reserve their places; a tie with unknown technical classification is reserved until that classification is known. Final approved tie decisions record the sorted membership, exact order, reason, approver, timestamp, period, unit, and server-computed metric fingerprint. The server rejects missing permission, stale fingerprints, changed/forged membership, duplicate positions, and decisions over a non-tied group. The final returned list is ordered by official or reserved position; search hides rows without recomputing rank.

The reference test uses the supervisor's sample and asserts ranks **PAMO B, APL, PAMO C, PAMO D**. APL remains ahead of PAMO C because TC has priority even though PAMO C's OTPS is higher.

## Current data state and unresolved prerequisites

Read-only assessment calls against the current database used `as_of_date=2026-10-11` and selections `2026-H1`, `2026-H2`, and `2026`:

- All three periods show both expected rosters (6 PAs and 4 CENROs) but both units report **Coverage incomplete**. Unit TC, SE, OTPS, and due totals are withheld as N/A; no official ranking is published from partial coverage.
- Conservation has no saved PA applicability scopes and no normalized PAMO binding on any of the six PAs. Its scope/classification/tie schema is not installed in the ordinary local database. Even after installation, the source-approved per-obligation Conservation start/deadline inventory is still needed to verify missing duties.
- Development's source registry provides its duty schedule, but the 2026 H1/H2/annual live inventory lacks verified evidence for pre-2026-10-11 obligations that are absent from the encoded rows. For example, 2026-H2's due July-September ENGP periods cannot be called missed solely because no historical row exists. That historical gap blocks the whole unit score. The service therefore does not report a tempting October-only partial rate.
- No normalized PAMO assignment, unresolved MPL/MHRWS/PBPLS operational mapping, proposed PA status, or account-access rule was changed. These stay outside this task.

The scope editor can version which workflows apply to each PA and whether that workflow list is complete. It is not a substitute for the still-missing approved per-obligation Conservation schedule/start inventory. The owner/source office must provide that inventory before Conservation can receive an official rating.

## Registration and User Management

No public registration, account creation/edit/approval form, role, organizational-unit assignment, or User Management rule was changed for this scoring work. Assessment participation is independent of authorization. The existing pending `no_role` registration policy and office/supervisor validation are preserved. No CENRO user was reassigned to Development; a CENRO's Conservation custody work does not enter its Development ENGP rating.

## Fresh verification on 2026-10-11

The PHP test commands set `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and `DB_URL=''`; the two PHP test files also assert SQLite `:memory:`. `ModuleDefinitionSeeder` runs only inside those isolated feature tests. The separately reported roster/current-status snapshot was a read-only query against the local application database; it performed no writes or migrations.

| Check | Result |
| --- | --- |
| `tests/Unit/OfficeTatComplianceMetricsTest.php` + `tests/Feature/OfficeTatComplianceAssessmentTest.php` | Passed: 12 tests, 170 assertions. Covers formulas/boundaries, pooled math, reference order, rational ranking, tie reservation/authorization, six/four rosters, access, versioned configuration, source-day conventions, a 125-obligation ENGP inventory, PAMB date fallback, and duplicate source rows. |
| `tests/Feature/OfficeTatAverageRankingTest.php` + `tests/Feature/TimelinessPresentationTest.php` | Passed: 18 tests, 164 assertions. Existing Average TAT contracts remain intact. |
| Office TAT JS period/evidence/historical-ranking/ranking/scope tests | Passed: 14/14 with Node's serial in-process mode. Default worker mode first failed to spawn with sandbox `EPERM`; rerunning with `--test-isolation=none --test-concurrency=1` passed. |
| PHP syntax on compliance service, controller, migration and the two PHP test files | Passed. |
| `git diff --check` | Passed. Git printed existing CRLF-to-LF notices for several unrelated accumulated JS files. |
| `npm run build` | Passed through `scripts/build-vite-guarded.mjs`: verifier found 158 ESM assets and 91 reachable relative imports; guarded candidate was promoted to `public/build`. The first sandboxed attempt failed at Vite config loading with `spawn EPERM` before candidate creation; the authorized retry succeeded. |
| Browser/screenshot acceptance | Not performed. No authorized browser-preview tool was available, so this note makes no visual/browser acceptance claim. Build and source/static checks are not a substitute for keyboard, reduced-motion, or screenshot review. |

No ordinary database migration, real account operation, report submission/forward, archive action, notification-provider call, or broad seeder was run. The protected owner demo, `.recovery/`, and system drive were not inspected or changed. Fresh `git status --porcelain=v1` after writing this note showed 148 worktree entries (78 tracked paths and 70 untracked paths); `git diff --cached --name-only` showed 0 staged paths. Existing accumulated worktree changes were preserved; nothing was staged, committed, or pushed.

## Installation dependencies

The ordinary local schema was left unchanged; `configuration_schema_ready` read false. The new `2026_10_11_000002_create_office_tat_compliance_assessment_tables.php` must be included in the planned deployment migration set. Its foreign keys depend on the existing `protected_areas` and `users` tables. Global assessment viewing/configuration also depends on the existing Office TAT permission setup in `2026_10_09_000002_grant_historical_reports_and_office_tat_permissions.php`; historical/live separation depends on `2026_10_09_000001_add_historical_report_provenance_and_office_tat_configuration.php` and its `is_historical` source columns. These were not applied to the ordinary database during this task. Do not use unverified PA scope settings as a deployment readiness signal; the Conservation source schedule blocker remains after table installation.

The pending PAMO pilot/identity migration and its installation readiness are separate work. This scoring task did not modify or install them.
