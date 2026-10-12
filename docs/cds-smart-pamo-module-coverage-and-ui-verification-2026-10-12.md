# CDS-SMART PAMO Generic Conservation Workflow Coverage and UI Verification

Date: 2026-10-12
Checkout baseline: `7279fcf0964cd90e45581c668969be74962230a6` (`HEAD` remains at this commit)
Installation state: the PAMO origin/draft migration is already Ran in batch 12; the four core feature migrations were already installed. No production migration, broad seeder, installation command, or data mutation was performed. Feature tests invoke ModuleDefinitionSeeder only inside the isolated in-memory SQLite database.

## Result

PAMO can use the existing shared Conservation report page for active generic PA Conservation definitions. The live registry derives eligibility from active, non-retired, generic `pa` definitions in the PA Management and Development, Wildlife Conservation and Protection, or Conservation program areas. The seeder currently defines 22 generic workflows, and the fresh integration test promoted every one through the native `ConservationReportSubmission` source.

PAMO access stays limited to an active, approved actor whose assigned office still matches the current PAMO office mapping for their PA. The UI lists only that actor’s PA. The server derives PA, PAMO office, current destination, and captured route facts from the authenticated actor and current mappings; posted identity overrides are rejected. The user remains outside global technical-report and administrative access.

## Delivered workflow/source matrix

| Workflow key | Catalog/source | Delivery |
|---|---|---|
| `homestay` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Draft, native full validation, atomic promotion, route snapshot, protected MOV, correction/resubmit |
| `regular_pamb` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; default activity is the page workflow, so the user does not select Regular PAMB again |
| `special_pamb` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; keeps the Special PAMB activity contract |
| `maintenance_monuments` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; correction Receive/edit/resubmit exercised |
| `maintenance_buoy` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Draft, native full validation, atomic promotion, route snapshot, protected MOV, correction/resubmit |
| `twc_meetings` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; coworker completion, correction receipt/edit/resubmit, and separate preparation timing remain covered |
| `updating_pamp` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Draft, native full validation, atomic promotion, route snapshot, protected MOV, correction/resubmit |
| `restoration_plan_5_year` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain |
| `additional_bms_site` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; distinct from the specialized BMS source below |
| `cepa_plan` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; activity/document-specific rules retained |
| `vtol_operations` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; configured activity/document rules retained |
| `bdfe_terrestrial` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain |
| `bdfap` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain |
| `maintenance_pamo_ecotourism` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; legitimate activity choices remain |
| `rehabilitation_pa_office` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; legitimate activity choices remain |
| `ecotourism_management_plan` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; legitimate activity choices remain |
| `updating_pamb_manual` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; legitimate activity choices remain |
| `management_effectiveness_assessment` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; legitimate activity choices remain |
| `maintenance_pa_information_system` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain |
| `monitoring_mangroves_corals_seagrass` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; date-range validation retained |
| `water_quality_monitoring` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain; deactivation closes PAMO access |
| `mpan` | Active generic PA definition → `ConservationReportSubmission` (`conservation`) | Same chain |

For all 22, the prepared draft is stored only in `pamo_pamb_drafts`. It does not create an operational source row, routing event, or receipt. Forward validates against the existing report rules and commits the source row, protected attachment, captured routing position, first routing event, and forwarded-draft link in one transaction. A replay returns the same source and does not add another row or handoff. Lock versions and the immutable first persisted preparation timestamp remain enforced.

## Source-family boundaries and exceptions

The PAMO draft schema and promotion path target the `ConservationReportSubmission` source. Other PA-owned modules have separate native source contracts, tables, validators, and attachments; a similarly named Conservation workflow does not stand in for those systems.

| Catalog module/source family | Existing contract | PAMO generic-draft status |
|---|---|---|
| BMS (`bms`) | `BmsReportSubmissionController` and BMS submission records | Not exposed through the generic Conservation draft route |
| BAMS (`bams`) | `StandardAReportSubmissionController` backed by BAMS report records | Not exposed through the generic Conservation draft route |
| IMEA (`imea`) | `StandardAReportSubmissionController` backed by IMEA report records | Not exposed through the generic Conservation draft route |
| IMEA facility maintenance | Separate facility-maintenance controller and report model | Not exposed through the generic Conservation draft route |
| Automated Weather Station (`automated_weather_station` / `aws`) | `AwsController`; formal `Aws` reports are distinct from timestamped imports and `AwsObservation` records, with AWS-specific fields and report-file rules | Not exposed through the generic Conservation draft route |
| IPAF management / revenue collection | `IpafController` and separate report models | Not exposed through the generic Conservation draft route |
| Management Plans | Development program area and separate plan/report workflow | Excluded from PAMO Conservation scope |
| ENGP | Development program area and ENGP report controller | Excluded; remains CENRO Development work |

The specialized modules have genuine native report contracts, but they do not have a PAMO-compatible generic Conservation source contract. `pamo_pamb_drafts.conservation_report_submission_id` is a foreign key to the Conservation source. Supporting the separate families would require source-specific draft-to-source linkage, native validation and attachment adapters, and routing coverage; that schema and integration were not fabricated here. No specialized link or operational page was exposed to PAMO.

## Authorization, routing, and UI

- The PAMO eligibility method now has one allowlisted source for the index gate, list/detail scope, corrections, protected MOV, and navigation. It requires active, non-retired generic PA catalog entries in eligible PA program areas. Empty eligibility fails closed.
- Own-PA checks remain on the submitted actor and record. Foreign PA, workflow mismatch, inactive definition, pending/inactive actor, and forged `protected_area_id` / `pamo_office_id` are rejected. The test also checks the index presents exactly the actor’s assigned PA and the PAMO navigation does not include BMS/AWS links.
- The `conservation-reports.mov` gate still checks actor, workflow, record, and PA through the protected attachment authorization path.
- First destinations remain driven by the completed PA setup: APL, BMSFR, and BPL → CENRO Baganga CDS Focal; MPL and PBPLS → CENRO Mati CDS Focal; MHRWS → PENRO Davao Oriental Records directly. No PA, user, office, or mapping was created or edited. Fresh integration coverage verifies the APL/CENRO Baganga and MHRWS/direct-PENRO cases; the existing route tests cover captured Office/TSD combinations, Receive gates, correction return/receive/resubmit, and CENRO/PENRO/ENGP behavior.
- The restored module layout is heading → shared filter/action toolbar → existing report table → compact saved-draft table. New/edit/reopen happens in the existing animated `CrudFormModal`, with shared selectors, date pickers, protected file panel/preview, focus/Escape behavior, and reduced-motion support inherited from shared components. New Draft is sized to the toolbar. The form resets when workflow or assigned PA changes. Opening the editor and its effects make no request and do not start preparation timing; the first Save persists the start time.
- Average TAT stays receipt-based. Draft preparation timing remains separate. No TC/SE/OTPS, rankings, deadlines, date conventions, or Development rules were changed.

## Verification

| Check | Fresh result |
|---|---|
| PAMO draft feature suite | Passed: 12 tests, 721 assertions. Includes a catalog-driven save/reopen/update/validate/promote/replay pass across all 22 eligible workflows. |
| Combined affected PHP regression batch | Passed: 232 tests, 2,186 assertions. Files: `PamoPambDraftRoutingTest`, `P2PamoPambScopeTest`, Conservation report/workflow authorization and routing tests, `PambRoutingTimelineTest`, `SubmissionTrackingRoutingContractTest`, `EngpReportTest`, `TechnicalReportRoutingProtectionTest`, `BmsSubmissionTrackingQueueTest`, and `AwsDomainSeparationTest`. |
| Test isolation | `APP_ENV=testing`, SQLite `:memory:`, empty `DB_URL`, array cache/session/queue/mail, local test storage, and `DOCUMENT_ARCHIVE_DRIVER=fake`; the PAMO integration tests use `Notification::fake()` and `Storage::fake('local')`. No ordinary database was used. |
| PHP syntax | Passed `php -l` for all 20 changed PHP files. |
| Whitespace | `git diff --check` passed. |
| JavaScript checks | The Node multi-file runner hit Windows `spawn EPERM`; direct serial execution then ran 42 test files: 40 passed, 2 failed. `calendarScrollPerformance.test.mjs` observed 0 cells where 12 were expected. `officeTatAutomaticDashboard.test.mjs` still expects the old `/selectedUnit, periodKey, asOfDate/` source pattern. Both are unrelated to the PAMO source changes. The PAMO UI has no standalone JavaScript unit file; its JSX was compiled by the successful guarded build. |
| Guarded production build | First sandbox attempt failed before Vite config loading with `spawn EPERM`; the same guarded command was retried with subprocess access. Vite transformed 1,468 modules; the verifier passed 161 ESM assets and 94 generated relative imports, then promoted the verified build to `public/build`. A failed candidate would leave the prior build in place. |
| Browser review | No authorized browser session was available (`apps=[]`, `browsers=[]`), so no visual browser review or acceptance is claimed. |
| Schema / routing concurrency | No schema or routing uniqueness/transaction boundary changed; the existing forward transaction was retained, so the MySQL overlap harness was not required. No migrations were run. |

The interrupted session’s filtered results remain separate historical evidence: one filtered test with 38 assertions and a second filtered run with 2 tests / 45 assertions. Their filters may overlap, so those numbers are not added to the fresh totals above.

## Changed files and worktree

PAMO implementation and test files changed for this completion:

- `app/Http/Controllers/ConservationReportSubmissionController.php`
- `app/Http/Controllers/PamoPambDraftController.php`
- `app/Http/Middleware/EnsureOrganizationalUnit.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Authorization/OrganizationalAccessService.php` (the file also contains preserved work from other changes)
- `app/Services/Conservation/ConservationReportWorkflowRegistry.php`
- `app/Services/SubmissionTracking/DocumentRoutingAccessService.php`
- `app/Services/SubmissionTracking/PambSubmissionAccessService.php`
- `resources/js/Components/PamoPambDraftWorkspace.jsx`
- `resources/js/Components/StandardAReportSubmissionTracker.jsx`
- `resources/js/Layouts/AuthenticatedLayout.jsx`
- `resources/js/Pages/Bms/ReportSubmissionTracker.jsx`
- `resources/js/Pages/ConservationReports/Index.jsx`
- `tests/Feature/P2PamoPambScopeTest.php`
- `tests/Feature/PamoPambDraftRoutingTest.php`

Other modified user work was preserved: Admin user, registration, protected-area, and Office TAT controllers/pages/requests; their related feature tests; and existing office-label presentation helpers/tests. Existing untracked files and the literal `%SystemDrive%/` directory were not opened, changed, or removed. No file was staged, committed, pushed, or deleted.

At closeout, `HEAD` is still `7279fcf0964cd90e45581c668969be74962230a6` on `main...origin/main`. The worktree is intentionally dirty with the above modified files, the verification note itself, the prior untracked Office TAT/registration/installation verification notes and helpers/tests, and the existing `%SystemDrive%/` entry. `public/build` is ignored by Git and contains the verified build.

## Exact Git status at closeout

```text
## main...origin/main
 M app/Http/Controllers/Admin/UserController.php
 M app/Http/Controllers/Auth/RegisteredUserController.php
 M app/Http/Controllers/ConservationReportSubmissionController.php
 M app/Http/Controllers/PamoPambDraftController.php
 M app/Http/Controllers/ProtectedAreaController.php
 M app/Http/Middleware/EnsureOrganizationalUnit.php
 M app/Http/Middleware/HandleInertiaRequests.php
 M app/Http/Requests/StoreUserRequest.php
 M app/Http/Requests/UpdateUserRequest.php
 M app/Providers/AppServiceProvider.php
 M app/Services/Authorization/OrganizationalAccessService.php
 M app/Services/Conservation/ConservationReportWorkflowRegistry.php
 M app/Services/SubmissionTracking/DocumentRoutingAccessService.php
 M app/Services/SubmissionTracking/PambSubmissionAccessService.php
 M resources/js/Components/PamoPambDraftWorkspace.jsx
 M resources/js/Components/StandardAReportSubmissionTracker.jsx
 M resources/js/Layouts/AuthenticatedLayout.jsx
 M resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx
 M resources/js/Pages/Admin/OfficeTat/Index.jsx
 M resources/js/Pages/Admin/Users/Form.jsx
 M resources/js/Pages/Admin/Users/Index.jsx
 M resources/js/Pages/Auth/Register.jsx
 M resources/js/Pages/Bms/ReportSubmissionTracker.jsx
 M resources/js/Pages/ConservationReports/Index.jsx
 M resources/js/Pages/ProtectedAreas/Form.jsx
 M tests/Feature/OfficeTatComplianceAssessmentTest.php
 M tests/Feature/OperationalGroupRegistrationTest.php
 M tests/Feature/P2PamoPambScopeTest.php
 M tests/Feature/PamoPambDraftRoutingTest.php
 M tests/Feature/ProtectedAreaManagementTest.php
 M tests/Feature/UserManagementPresentationTest.php
?? %SystemDrive%/
?? docs/cds-smart-office-core-feature-installation-verification-2026-10-12.md
?? docs/cds-smart-office-hostname-browser-verification-2026-10-12.md
?? docs/cds-smart-office-pamo-registration-user-management-verification-2026-10-12.md
?? docs/cds-smart-pamo-module-coverage-and-ui-verification-2026-10-12.md
?? docs/cds-smart-pamo-office-assignment-label-cleanup-verification-2026-10-12.md
?? resources/js/Components/Form/officePresentation.mjs
?? resources/js/Pages/Admin/OfficeTat/unitPresentation.mjs
?? tests/js/officeTatUnitPresentation.test.mjs
?? tests/js/pamoOfficeLabelPresentation.test.mjs
```
