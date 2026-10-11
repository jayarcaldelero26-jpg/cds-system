# CDS-SMART: Office TAT assessment setup and coverage readiness

**Date:** 2026-10-11
**Status:** Source inspection and bounded read-only assessment only. No configuration or migration was installed.

## Owner decision summary

The compliance-first scoring implementation and the prior verification report agree on the six-person Conservation roster, four-CENRO Development roster, effective-date rule, and incomplete official standings. Both units remain ineligible for an official H1, H2, or Annual 2026 score as of the Manila cutoff on 2026-10-11.

The setup work needs the owner to approve Conservation workflow applicability and source schedules PA by PA, produce historical Development evidence, and identify the authorized source for technical classifications. The existing scope screen records workflow keys and a completeness checkbox, but it cannot record effective dates, activity/document-level obligations, meeting occurrences, or per-obligation start/deadline evidence. Do not use its checkbox to claim those missing facts.

The owner should not approve a generic “complete coverage” change. The approval sheet below names each participant and registry workflow that needs a concrete decision. The proposed 2026 assessment bounds remain 2026-01-01 through 2026-12-31, with the current policy effective date unchanged at 2026-10-11. Historical gaps before that date remain unknown unless source evidence verifies them.

The three PAMO operational mappings remain separate and unresolved: MPL’s office, MHRWS’s supervising PENRO, and PBPLS’s supervising CENRO. This assessment worksheet does not choose or change any mapping.

## Inspection basis and comparison with the prior report

The prior verification report was read before source inspection. Its core conclusions are confirmed. This report adds bounded 2026 Development duty counts and records the one existing historical provenance row; these details do not change the prior conclusion that scores are withheld. One migration-state clarification is needed: the prior report says the October 9 provenance/permission migrations “were not applied to the ordinary database during this task.” That accurately describes that task’s actions, but could be read as saying they are still pending. The current read-only migration table shows both October 9 migrations already applied; only the October 11 assessment migration and separate PAMO pilot migration are pending.

The read-only Laravel assessment was called for 2026-H1, 2026-H2, and 2026 Annual with as-of date 2026-10-11. Laravel resolved the ordinary local environment and MySQL connection internally. The connection check printed no database name, host, or credentials. Directory, migration-history, schema, and provenance reads used that same connection. No migrations, seeders, application writes, tests, builds, or browser actions were run.

No applicable AGENTS.md project instructions were found in the repository or checked parent locations. Test/build evidence and the unavailable browser review are reused from the prior report; they were not rerun.

## Migration state, effects, and dependencies

The ordinary database migration history reports the office-assignment migration and both October 9 provenance/permission migrations as applied. The PAMO pilot migration and the new assessment migration are pending. The assessment page reports configuration_schema_ready=false.

| Migration | Current ordinary database state | Source effects and data behavior | Actual down() behavior |
| --- | --- | --- | --- |
| database/migrations/2026_09_03_140000_create_organizational_offices_and_pa_assignments.php | Applied | Creates organizational_offices and protected_area_office_assignments. Seeds four CENRO and two PENRO office identities. It does not assign the six Protected Areas to PAMOs. The current PA-to-office relation used for display is supplied by this schema. | Drops protected_area_office_assignments and organizational_offices, including assignment and office data. |
| database/migrations/2026_10_09_000001_add_historical_report_provenance_and_office_tat_configuration.php | Applied | Adds is_historical, default false, to ten source tables; creates historical_report_origins and office_tat_configuration_versions. It does not backfill historical submissions. Existing rows receive the default live value unless separately encoded through the historical-report flow. | Drops office_tat_configuration_versions and historical_report_origins, then drops is_historical from every source table where the column exists. That erases provenance markers and origin records. |
| database/migrations/2026_10_09_000002_grant_historical_reports_and_office_tat_permissions.php | Applied | Creates historical-reports.view, historical-reports.encode, office-tat.view, and office-tat.configure. Grants Office TAT view to roles with reports.view; historical view to selected reporting viewers and admins; historical encode to selected report creators and admins; configure to CDS Admin and Super Admin. | Deletes role_has_permissions rows for all four permissions and deletes those permission rows. Later direct grants to the same permissions would be deleted too. |
| database/migrations/2026_10_11_000002_create_office_tat_compliance_assessment_tables.php | Pending; exact scoring/setup migration | Creates office_tat_assessment_scope_versions, office_tat_technical_classification_versions, and office_tat_tie_decisions. The foreign keys require existing protected_areas and users. It seeds or backfills no records and adds no permission grants. | Drops tie decisions, technical classifications, and assessment scopes. Any approved setup rows saved after installation would be lost. |
| database/migrations/2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php | Pending; separate pilot/identity work | Can add explicit PAMO office identities from qualifying legacy PA labels only where its supervisor and normalized office checks are unambiguous; adds captured origin identity to routing snapshots, adds an office identity reference to legacy TAT configuration, and creates pamo_pamb_drafts. OfficeTatComplianceAssessmentService does not read draft rows for scoring. The scoring roster keys remain PA IDs and CENRO IDs. | Throws RuntimeException and requires a forward-compatible fix; it is not an ordinary reversible migration. |

The scoring migration depends directly on the already-present users and protected_areas tables. Runtime also relies on the organizational office schema, historical flags, and Office TAT permissions, which the read-only migration-history check found already applied. The pending PAMO draft/identity migration is not a scoring prerequisite and must remain outside the targeted scoring install.

The source-approved scoring-only path for a later, separately authorized install is:

1. Take a protected full backup and verify a restore into a separate disposable target.
2. Confirm the intended normal target, current migration history, required tables, and the business approval package.
3. Apply only database/migrations/2026_10_11_000002_create_office_tat_compliance_assessment_tables.php through a targeted migration path. Do not use a blanket migrate command that would also apply the pending PAMO pilot migration.
4. Verify the three tables and migration record read-only before any approved configuration is entered.

Do not use rollback of the October 9 provenance migration as a recovery shortcut. Its down() removes source is_historical columns and immutable origin rows. The scoring migration’s down() also discards approved scope, classification, and tie data. Preserve a verified backup and export/retain approved configuration before any later rollback decision.

## Effective-date and source-evidence rules

The implemented policy effective date is 2026-10-11 in OfficeTatComplianceAssessmentService.

- For a due obligation whose deadline is before 2026-10-11, a live, source-matched submission with a valid source start, authoritative deadline, and PENRO receipt by cutoff can be evaluated. If the report/receipt is absent or arrives after cutoff, the service marks its historical inventory unverified; absence is not treated as a missed report.
- For a complete registered obligation due on or after 2026-10-11, an absent accepted receipt by cutoff can be counted as a verified miss. A deadline equal to the effective date is in this post-effective group.
- A deadline after the selected Manila cutoff is not due in that assessment. It is not a failure; a valid receipt before that later deadline can contribute volume only.
- Historical rows marked is_historical are separated from live obligation matching. The provenance store preserves original milestones, but those rows do not satisfy the live compliance numerator under the current service. Do not toggle the historical flag or rewrite original milestone data to make a row count.

The assessment windows are H1: 2026-01-01 through 2026-06-30; H2: 2026-07-01 through 2026-12-31; Annual: 2026-01-01 through 2026-12-31. At the 2026-10-11 as-of date, H1 is cut off at June 30 and H2/Annual at October 11. Dates use Asia/Manila semantics. The policy date is not changed by approving or reconstructing a 2026 obligation schedule.

## Existing permission-gated setup and its limits

The global Office TAT view is served at GET /admin/office-tat behind office-tat.view. The controller supplies full compliance assessments only to a globally scoped Office TAT viewer. Configuration writes are guarded by office-tat.configure; the October 9 permission migration grants that permission to CDS Admin and Super Admin.

| Existing route and screen | Fields and validation supported now | What it does not establish |
| --- | --- | --- |
| POST /admin/office-tat/assessment-scope; ComplianceFirstAssessments.jsx | protected_area_id must exist; workflow_keys must be present, an array of up to 100 active generic PA catalogue keys; coverage_complete is a required boolean. The service deduplicates keys and appends a version for that PA. | No effective-from/to dates, evidence reference, activity/document selection, recurring event count, actual meeting occurrence, or per-obligation start/deadline. A checked completeness flag is not evidence that those facts exist. |
| POST /admin/office-tat/technical-classification | domain is conservation or engp; workflow_key must be in the active catalogue; is_highly_technical is a required boolean. Appends a version and configured_by actor. | No business-source citation or approval rationale is captured. The permission identifies who may enter a value, not who approved its technical basis. |
| POST /admin/office-tat/tie-decision | unit, period, as-of date, 64-character metric fingerprint, exact participant membership/order, and reason of at least 10 characters. The service rechecks current membership and the tie before saving. | Cannot resolve a tie while coverage or ranking measures are incomplete; no tie should be created in this setup task. |
| POST /admin/office-tat/configuration | Legacy Average TAT settings: PAMO/CENRO, office, period, volume target, three weights totaling 1.0. | These settings are not compliance-first applicability, source duty dates, technical classifications, or a reason to reapprove the settled compliance formula. |

The new assessment migration’s scope table holds unit, nullable PA ID, workflow key array, coverage_complete, version, configured_by, and timestamps. It has no obligation schedule. Its technical table holds domain, workflow key, boolean classification, version, configured_by, and timestamps; it has no authority/document field. Therefore a complete historical PA setup cannot be represented by the current screen and schema alone.

## Participant and applicability approval worksheet

### Conservation participants

These six rows were read from the Protected Area directory and match the assessment roster. All six participate under the settled six-PA roster rule. Proposed legal status remains Proposed and must not be changed to Active to make assessment membership work.

| PA ID | Participant | Current status | Current normalized PAMO binding | Current scope value | Owner decision |
| ---: | --- | --- | --- | --- | --- |
| 1 | Mati Protected Landscape (MPL) | Active | None | No scope row; assessment tables are not installed | For each of the 22 keys below, approve Applicable or Not applicable and its source-effective bounds. |
| 2 | Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS) | Active | None | No scope row; assessment tables are not installed | Same 22 key-by-key decisions; identify the source-approved workflow schedule and effective bounds. |
| 3 | Pujada Bay Protected Landscape and Seascape (PBPLS) | Active | None | No scope row; assessment tables are not installed | Same 22 key-by-key decisions; identify the source-approved workflow schedule and effective bounds. |
| 4 | Aliwagwag Protected Landscape (APL) | Proposed | None | No scope row; assessment tables are not installed | Same 22 key-by-key decisions; preserve Proposed status and provide its source-approved workflow schedule and bounds. |
| 5 | Baganga Mangrove Swamp Forest Reserve | Proposed | None | No scope row; assessment tables are not installed | Same 22 key-by-key decisions; preserve Proposed status and provide its source-approved workflow schedule and bounds. |
| 6 | Baganga Proteceted Landscape | Proposed; spelling retained from source | None | No scope row; assessment tables are not installed | Same 22 key-by-key decisions; preserve source spelling/status and provide its source-approved workflow schedule and bounds. |

For each of the six participants, every workflow row in the following catalogue is currently Unknown for applicability. The owner’s approval sheet must contain one Applicable/Not applicable decision per PA and key, not one blanket “all workflows” approval. For a full-period 2026 assessment, the proposed assessment window is 2026-01-01 through 2026-12-31; the owner must cite the effective bounds supported by the actual PA work plan, mandate, meeting/event calendar, or other authoritative record. The application cannot currently save those bounds.

The active generic PA source catalogue contains these 22 keys:

| Workflow key | Source catalogue cadence | Source T / deadline basis | Applicability trigger and remaining decision |
| --- | --- | --- | --- |
| restoration_plan_5_year | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm for each PA and identify the plan milestones/activity-document pairs due in each period. |
| additional_bms_site | Semestral | 15 Conservation working days, Monday-Thursday calendar | Confirm each PA and whether the approved plan calls for a site/report in each semester. |
| bdfap | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and the source inventory/period trigger. |
| bdfe_terrestrial | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and whether its terrestrial-PA trigger applies. |
| cepa_plan | Quarterly | 7 Conservation working days; Final CEPA Plan/Final Report uses 15 | Approve the applicable activity/document pairs and timing for each PA; the catalogue contains several CEPA milestones. |
| ecotourism_management_plan | Semestral | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and whether its approved plan has work due in each semester. |
| homestay | Quarterly | 15 Conservation working days, Monday-Thursday calendar | Confirm each PA and its programme/activity schedule. |
| maintenance_buoy | Quarterly | 15 Conservation working days, Monday-Thursday calendar | Confirm each PA with a buoy and scheduled maintenance/report trigger. |
| maintenance_monuments | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA with monuments and scheduled maintenance/report trigger. |
| maintenance_pa_information_system | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and the approved maintenance/report schedule. |
| maintenance_pamo_ecotourism | Semestral | 7 Conservation working days, Monday-Thursday calendar | Confirm PAMO or ecotourism facility applicability and semester trigger for each PA. |
| management_effectiveness_assessment | Semestral | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and the approved assessment cycle/activity pairs. |
| monitoring_mangroves_corals_seagrass | Quarterly | 15 Conservation working days, Monday-Thursday calendar | Confirm relevant ecosystem/site and source-approved monitoring quarters for each PA. |
| mpan | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and its source-approved MPAN activity/period. |
| regular_pamb | Quarterly catalogue, event-triggered | 7 PAMB working days, Monday-Thursday calendar | Do not create a duty merely because a quarter exists. Confirm whether and when a regular PAMB meeting actually occurred or was scheduled for each PA. |
| rehabilitation_pa_office | Semestral | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and whether approved rehabilitation work/report was due in the semester. |
| special_pamb | Quarterly catalogue, event-triggered | 7 PAMB working days, Monday-Thursday calendar | Do not create a duty merely because a quarter exists. Confirm the actual special meeting/event per PA. |
| twc_meetings | Quarterly catalogue, event-triggered | 7 PAMB working days, Monday-Thursday calendar | Do not create a duty merely because a quarter exists. Confirm the actual TWC meeting/event per PA. |
| updating_pamb_manual | Semestral catalogue; workshop/adoption milestones | 7 PAMB working days; Final Updated Manual uses 15 | Confirm PA applicability, actual workshop/adoption milestones, and which document is due. |
| updating_pamp | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and the source-approved plan revision milestone/period. |
| vtol_operations | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA with applicable VTOL activity and its insurance/maintenance reporting trigger. |
| water_quality_monitoring | Quarterly | 7 Conservation working days, Monday-Thursday calendar | Confirm each PA and the approved monitoring quarters/sites. |

The exact activity/document pairs are from requirement_metadata.activity_documents in ConservationReportWorkflowRegistry and ReportRequirementRegistry. The current scope screen selects a workflow key and then the service expands the catalogue’s activity/document pairs across its periods. The owner must approve which pairs and event occurrences are real obligations; the current screen cannot select pairs or enter their schedule.

For a submitted Conservation report, the existing source start is date_accomplished except for regular_pamb, special_pamb, and twc_meetings. For those three meeting workflows, PambComplianceCalculator uses date_accomplished when present and otherwise falls back to date_conducted. If neither field is present, the source event start is unavailable. The deadline is the existing module/workflow deadline helper; meeting deadlines use the PAMB calculator and PAMB working-day calendar. The accepted receipt endpoint is date_received_penro. This fallback proves an event date only for a source row that carries it; it does not establish that an unrecorded meeting occurred or that a missing report was due. A signed meeting calendar/minutes or equivalent event inventory is needed for missing PAMB obligations.

The one existing historical origin row is Conservation source record 38: PA ID 4 (APL), regular_pamb, Quarter 1, “Regular PAMB” / “Minutes”; the source event dates are 2026-03-18 and date_received_penro is null. The row is marked historical and its immutable origin record exists. It can support event/source-history review, but it is not a verified receipt and the current compliance service does not count historical rows as live submissions. It was not matched into the current assessment because no PA workflow scopes exist.

### Development participants and source workflow rules

The active office directory contains CENRO IDs 1 Baganga, 2 Manay, 3 Lupon, and 4 Mati. The current ENGP registry itself determines applicability: all 12 workflows apply to all four offices except ngp_staff_accomplishment, which applies only to Baganga, Lupon, and Mati. There is no separate CENRO scope editor.

| Workflow key | Catalogue cadence | Current office applicability | Start, deadline, receipt, and owner action |
| --- | --- | --- | --- |
| cenro_nursery_seedling | Monthly | All four CENROs | Registry period start; due on day 20; date_received_penro is the receipt. Preserve current rule; verify official 2026 completeness evidence. |
| cbep | Monthly | All four CENROs | Registry period start; due on day 20; date_received_penro. Preserve rule; verify source archive. |
| elcac | Monthly | All four CENROs | Registry period start; due on day 20; date_received_penro. Preserve rule; verify source archive. |
| weekly_accomplishment | Weekly; generated work period Monday-Thursday | All four CENROs | Registry period start; first two generated weeks use configured day 20, later generated weeks are due Thursday. The 2026-08-31 start is excluded. Verify each office’s full weekly receipt/missing register. |
| forest_disturbance | Monthly | All four CENROs | Registry period start; due on day 20; date_received_penro. Preserve rule; verify source archive. |
| monthly_accomplishment_pmd_fmb | Monthly | All four CENROs | Registry period start; due on day 20; date_received_penro. Preserve rule; verify source archive. |
| ngp_produce | Quarterly | All four CENROs | Registry quarter start; due on day 10 of the quarter-ending month; date_received_penro. Verify source archive. |
| ngp_staff_accomplishment | Monthly | Baganga, Lupon, Mati; not Manay | Registry period start; due on day 20; date_received_penro. Preserve the Manay exclusion. |
| nursery_maintenance | Quarterly | All four CENROs | Registry quarter start; due on day 10 of the quarter-ending month; date_received_penro. Verify source archive. |
| site_visit | Quarterly | All four CENROs | Registry quarter start; due on day 10 of the quarter-ending month; date_received_penro. Verify source archive. |
| tree_replacement | Monthly reporting periods | All four CENROs | Registry monthly period start; due on day 20; the source document label is Weekly Report. Preserve the current monthly reporting cadence. |
| rims | Monthly | All four CENROs | Registry period start; due on day 20, except January 2026 due on January 29; date_received_penro. Verify source archive. |

The current ENGP definitions use custom source deadlines and elapsed calendar days for T and D. The stored deadline_submission must agree with the registry deadline. Do not change period identity, dates, office applicability, or deadline to fill missing history. For every absent pre-effective duty, provide either the original source submission/receipt record with date_received_penro or an authorized, period-complete inventory confirming the submission was not received. An absent database row by itself is not a verified miss.

### Technicality and approval source

No technical-classification table is installed and no approved classification catalogue is present in the current assessment. Each of the 22 Conservation keys and 12 ENGP keys therefore requires one owner decision: Highly technical = Yes or No. The decision must cite the program/workflow authority that supports it. Do not infer technicality from a filename, attachment, subject line, workflow complexity, or encoder claim.

The authorized manager with office-tat.configure may enter a versioned Yes/No value after the schema is installed, but the current table and UI do not record the business approval document or rationale. Before any classification write, the owner must name the approving program authority and preserve its written reference alongside the proposed setup package.

## Verified coverage at the 2026-10-11 Manila cutoff

“Applicable obligations known” below means registry-generated scheduled obligations whose deadline is within that assessment period and on or before its cutoff. It is a source-catalogue count, not permission to call absent pre-effective submissions missed. Conservation uses Unknown rather than a zero because no per-PA scope or event inventory is approved.

| Unit / period | Applicable obligations known | Verified submissions | Verified missed obligations | Unverified historical gaps | Future not-yet-due items | Official-score eligibility / exact blocker |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Conservation / 2026-H1 | Unknown | Unknown | Unknown | Unknown | Unknown | No. Each of six PA rows returns “approved applicable Conservation workflow inventory is missing or incomplete”; no scope schedule exists. |
| Conservation / 2026-H2 | Unknown | Unknown | Unknown | Unknown | Unknown | No. Same six per-PA applicability blockers; source-event and per-obligation schedule are also not represented in the current setup UI/schema. |
| Conservation / 2026 Annual | Unknown | Unknown | Unknown | Unknown | Unknown | No. Same six per-PA applicability blockers; no defensible denominator or historical gap count is available. |
| Development / 2026-H1 | 310 | 0 | 0 | 310 | 0 | No. Every generated due obligation is before the policy effective date and lacks verified submission or complete missing-submission evidence. |
| Development / 2026-H2 | 161 | 0 | 0 | 161 | 153 | No. All 161 due obligations are pre-effective historical gaps; 153 additional registry duties are later than the October 11 cutoff and remain future. |
| Development / 2026 Annual | 471 | 0 | 0 | 471 | 153 | No. All due 2026 registry duties through the cutoff remain unverified historical gaps; 153 later duties are not yet due. |

Development counts were read from the active ENGP registry and current four-CENRO roster. The service’s unit-level coverage_reasons array is empty for Development; each participant row instead carries historical submission coverage is unverified; absence is not a verified missed report. The service withholds all unit and participant counts/rates/ranks when coverage is incomplete. These observations do not establish a partial October-only rating or official score.

### Development gap inventory by workflow and participant

Every due item in the following rows is currently an unverified pre-effective gap, not a verified miss. Counts are per listed office; the final column gives the combined count across the listed offices. The reporting windows are: H1 due by June 30, H2 due July 1 through the October 11 cutoff, and Annual due January 1 through the cutoff.

| Source workflow(s) | Participant coverage | Due dates represented in H1 / H2 / Annual | Gap count per participant H1 / H2 / Annual | Combined gaps H1 / H2 / Annual |
| --- | --- | --- | ---: | ---: |
| cenro_nursery_seedling, cbep, elcac, forest_disturbance, monthly_accomplishment_pmd_fmb, tree_replacement | Baganga, Manay, Lupon, Mati | January 20-June 20 / July 20-September 20 / January 20-September 20, 2026 | 6 / 3 / 9 per workflow | 144 / 72 / 216 |
| rims | Baganga, Manay, Lupon, Mati | January 29, then monthly due dates through June 20 / July 20-September 20 / January 29-September 20, 2026 | 6 / 3 / 9 | 24 / 12 / 36 |
| ngp_staff_accomplishment | Baganga, Lupon, Mati only; no Manay duty | January 20-June 20 / July 20-September 20 / January 20-September 20, 2026 | 6 / 3 / 9 per applicable office | 18 / 9 / 27 |
| weekly_accomplishment | Baganga, Manay, Lupon, Mati | January 20-June 25 / July 2-October 8 / January 20-October 8, 2026; 2026-08-31 start excluded | 25 / 14 / 39 per office | 100 / 56 / 156 |
| ngp_produce, nursery_maintenance, site_visit | Baganga, Manay, Lupon, Mati | March 10 and June 10 / September 10 / March 10-September 10, 2026 | 2 / 1 / 3 per workflow | 24 / 12 / 36 |

Minimum Development evidence is an authoritative complete obligation register for each applicable office/workflow/period, paired with original submitted report and PENRO receipt date evidence where received. For each due key without a receipt, the minimum is a signed or otherwise approved period-complete missing-submission inventory that confirms no accepted receipt by the cutoff. The register must cover the whole assessed period; partial row recovery cannot establish the denominator or an official full-period score.

The historical_report_origins table currently has one Conservation origin row and no Development origin rows. The Conservation origin above preserves its source provenance but has no receipt date. The service’s historical-row exclusion means that provenance can support audit and event review but cannot alone count as a live on-time submission. If the owner wants historically encoded reports included in compliance numerators, that needs a separately approved policy and implementation decision; do not mutate the historical flag or original milestones here.

## Exact configuration package for later approval

| Setup item | Existing value | Proposed value to approve | Authority / effective bounds required | Supported management method and blocker |
| --- | --- | --- | --- | --- |
| Conservation roster | Six PA IDs and names above; three Active, three Proposed; no normalized PAMO bindings | Retain the six existing participant identities and statuses | Current policy includes all six; for each PA/key provide the source-approved applicability bounds relevant to 2026 | After the assessment migration, append a version using the scope screen. That screen cannot store bounds or per-obligation schedules, so do not set coverage_complete until an additive schedule/evidence capability exists. |
| PA workflow applicability | No saved scope rows; all 22 workflow keys are unapproved for each of six PAs | For each PA and each of the 22 catalogue keys, approve Applicable or Not applicable; document applicable activity/document pairs and any event/no-event condition | PA work plan, mandate, approved calendar, meeting record, or other owner-designated official source; bounds supported by that source, without changing policy effective date | Current screen supports workflow-key arrays only. Separate source schedule evidence is required; the UI cannot record it. |
| Development source schedule | Current ENGP registry rules and office lists above | Preserve the 12 registry rules and the Manay exclusion for ngp_staff_accomplishment unless the business owner provides a separately approved source change | Owner confirms official 2026 source schedule and full archive/missing register through October 11 | No scope write is required. Do not modify registry rows or office assignments as a way to reconstruct missing history. |
| Technical classification | No installed assessment table or approved Yes/No values | One signed Yes/No per active key: 22 Conservation plus 12 ENGP | Owner names the authorized technical/program approver and cites the controlling approval document and effective bounds | After migration, append via the technical-classification screen. Current storage cannot retain the authority citation; preserve it in the owner approval package. |
| Tie decisions | No supported scoring results or eligible ties while coverage is incomplete | None now. Record an order only for an exact five-measure tie after full evidence and classification are complete. | Authorized reviewer, current metric fingerprint, exact membership and reason | Existing tie screen/service revalidates a complete tied group. Do not create a placeholder decision. |
| PAMO operational mappings | No normalized PAMO binding for the six PAs | No mapping proposed in this assessment | Keep MPL office, MHRWS supervising PENRO, and PBPLS supervising CENRO unresolved for their separate routing task | Resolve with directory evidence in that later task; do not install or run the pending identity migration here. |

The owner-facing applicability approval sheet should return the following for each PA ID: all 22 workflow keys marked Applicable/Not applicable; source authority and reference; effective-from/to dates; each applicable activity/document pair; actual recurring schedule or event trigger; approved source start and deadline for missing obligations; and evidence source for the receipt endpoint or a verified non-receipt. For PAMB meeting workflows, include the meeting/event register, not a blanket quarterly checkbox.

## Fairness check

If four obligations are verified, three were submitted on time, and one was positively verified as missed, TC is 3 / 4 = 75%. Three visible submitted rows alone cannot prove TC=100% while the obligation inventory is unknown: the missing fourth duty may be a verified miss or an unresolved historical gap.

## Later installation and acceptance checklist

1. Obtain the signed PA-by-workflow applicability sheet, Development 2026 evidence package, and written technical classifications. Resolve the missing per-obligation Conservation schedule/evidence capability before representing Conservation scope as complete.
2. Protect a full backup and verify its restore into a separate disposable target. Keep the disposable target separate from the ordinary application database.
3. Read-only confirm the target’s current migration history, required source tables, PA/CENRO identities, and applied provenance/permission dependencies. Preserve existing accounts, roles, routing, report rows, receipt events, archives, attachments, and status values.
4. In a separate authorized install task, run only the exact scoring migration path after backup/restore verification. Do not apply the pending PAMO draft/identity migration as part of scoring.
5. Verify read-only that the three assessment tables exist and configuration_schema_ready is true. Confirm the six/four roster identities, the approved scope/classification versions, and that no unintended routing, role, account, or source-report change occurred.
6. Enter only approved additive configuration through the validated Office TAT services/UI. Do not rewrite report dates, custody events, snapshots, routing settings, roles, or existing historical records. Do not mark a scope complete while start/deadline or event evidence remains absent.
7. Owner browser review: open the global Office TAT page; confirm separate Conservation and Development panels and cutoff label; inspect per-participant coverage reasons and an obligation’s source start/deadline/receipt; verify historical unknowns are not labeled missed; verify a future duty is excluded from N; verify rankings stay within the unit; and confirm a genuine unresolved five-measure tie reserves positions until a decision is authorized. Confirm an ordinary local office user does not gain global metrics from the configuration permission alone.
8. Re-run read-only service checks and retain the approval/backup/install record. Publish no official full-period score until each unit’s full assessed inventory is complete and the service reports complete coverage.

The previous verification report’s test/build evidence remains the evidence record for code checks. No new test/build or browser acceptance claim is made here.

## Source references

- app/Services/OfficeTatComplianceAssessmentService.php: effective date, roster construction, source joining, historical gap gate, completeness gate, assessment bounds, and technicality/tie handling.
- app/Services/Reports/ReportRequirementRegistry.php: active source catalogue, period start, generated periods, and source deadlines.
- app/Services/Conservation/ConservationReportWorkflowRegistry.php and app/Services/Conservation/PambComplianceCalculator.php: PA workflow/activity-document catalogue and PAMB event date/deadline rules.
- app/Services/Engp/EngpReportWorkflowRegistry.php: current ENGP workflow list, office applicability, weekly exception, and source deadline generation.
- app/Models/ConservationReportSubmission.php and app/Models/EngpReportSubmission.php: submitted source dates, authoritative deadline, and PENRO receipt fields.
- app/Http/Controllers/OfficeTatController.php, routes/web.php, and resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx: route permissions, input validation, versioned configuration controls, and displayed evidence limits.
- database/migrations/2026_09_03_140000_create_organizational_offices_and_pa_assignments.php, database/migrations/2026_10_09_000001_add_historical_report_provenance_and_office_tat_configuration.php, database/migrations/2026_10_09_000002_grant_historical_reports_and_office_tat_permissions.php, database/migrations/2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php, and database/migrations/2026_10_11_000002_create_office_tat_compliance_assessment_tables.php: actual migration effects and rollback behavior.
