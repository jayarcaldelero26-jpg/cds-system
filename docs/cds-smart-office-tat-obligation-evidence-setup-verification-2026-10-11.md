# CDS-SMART: Office TAT obligation and approval evidence setup

**Date:** 2026-10-11
**Status:** Implemented and verified in isolated tests. No owner approvals were entered and no normal-database migration was run.

## Result

Office TAT now has an append-only evidence contract for Conservation applicability, recurring duties and meeting occurrences, Development historical non-receipt inventories, and technical-classification approvals. The assessment expands Conservation duties only from effective-dated Applicable decisions and supported schedule versions. The former `coverage_complete` attestation cannot make an assessment complete or publish scores by itself.

The shared Office TAT screen provides the setup editors, a derived coverage/blocker view, assessment evidence details, and a read-only approval ledger with links to stored source files. The forms accept owner-supplied facts; they contain no seeded business approvals or schedules. The six-PA assessment roster still includes Proposed PAs and remains separate from PAMO registration or office assignment. Development participants continue to come from active directory rows whose `office_type` is `cenro`; stable keys are CENRO office IDs, not PA IDs.

This does not make either unit eligible for an official 2026 rating. The pre-effective Development gaps still require complete approved historical evidence, and Conservation still requires owner-approved applicability and occurrence/start/deadline inventories.

## Roster identities preserved

| PA ID | Assessment participant | Directory status |
| ---: | --- | --- |
| 1 | Mati Protected Landscape (MPL) | Active |
| 2 | Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS) | Active |
| 3 | Pujada Bay Protected Landscape and Seascape (PBPLS) | Active |
| 4 | Aliwagwag Protected Landscape (APL) | Proposed |
| 5 | Baganga Mangrove Swamp Forest Reserve | Proposed |
| 6 | Baganga Proteceted Landscape | Proposed; source spelling retained |

The Development roster remains active organizational-office IDs **1 Baganga, 2 Manay, 3 Lupon, and 4 Mati**. The assessment reads offices by `office_type=cenro` and keys them as `cenro:{office_id}`; the Conservation roster uses `pa:{protected_area_id}`. The source-roster test confirms six PA participants and four CENRO participants. Proposed status does not remove a PA from the assessment and no status or PAMO eligibility was changed.

## Persisted setup contract

- **Approval files:** `office_tat_approval_evidence` stores a random public UUID, exact setup `scope_key`, private `local` storage path, original filename, media type, byte size, SHA-256 digest, uploader, and timestamps. Applicability, schedule, classification, and non-receipt writes accept only a stored UUID scoped to that exact identity. Reads/downloads verify the digest; the download route requires global Office TAT access or the original uploader.
- **Applicability:** Each version records PA ID, active workflow/activity/document tuple, `Unknown` / `Applicable` / `Not applicable`, inclusive effective-from/to dates, approval authority/reference/rationale, evidence ID, actor, version, and timestamps. Effective windows may not overlap. Unknown remains a blocker; Not applicable is an explicit approved exemption.
- **Schedules and occurrences:** A schedule version records PA/workflow/activity/document/year/source-period, recurring or event kind, complete inventory, authority/reference/rationale, source-file reference, actor, version, and timestamps. Occurrences retain their stable key, original start, and supported deadline. Recurring inventories cannot be empty. A PAMB meeting workflow may have an explicitly approved zero-event inventory; no quarterly meeting is inferred from cadence. Source reports without a matching approved occurrence block coverage.
- **Historical non-receipt:** A version stores participant, cutoff, the exact obligation keys, an immutable readable manifest of each key's workflow/activity/document/year/period/occurrence/start/deadline, approval citation, source file, actor, version, and timestamps. It only resolves selected pre-effective duties whose deadline is covered by the cutoff and which have no accepted or contradicting historical receipt. Positive historical provenance blocks a non-receipt declaration.
- **Technical classification:** Existing classification versions gain approving authority, written reference, rationale, effective bounds, and a scoped evidence-file reference. The configure permission records who entered a value; the assessment uses only a classification whose authority citation and file digest are supported for the assessment cutoff. Append versions use stale-version checks.

All setup changes append versions; none rewrites earlier approval facts. Schedule, applicability, non-receipt, and classification versions are included in the assessment evidence/fingerprints where they affect the cohort or score. A previous tie decision therefore cannot silently authorize a changed group.

## Assessment behavior and source boundaries

Conservation requirements are resolved from the active `ReportRequirementRegistry` catalogue and its actual activity/document pairs. Its catalogue frequency is not used as evidence that every pair is applicable. The server checks for effective-date gaps, missing due-period schedules, invalid dates, source rows that do not map to approved occurrences, unsupported schedule evidence, and unsupported technical-classification authority. The old checkbox is retained only as legacy scope metadata; the new assessment does not consult it as a completeness decision.

Regular, Special, and TWC PAMB rows use the existing authoritative source start (`date_accomplished`, falling back to `date_conducted`) and existing deadline/calendar helpers. A live row maps to an approved meeting occurrence by that start; duplicate live rows count at most once, using the earliest accepted PENRO receipt. ENGP uses its active workflow period/start/deadline registry and its existing calendar-day or configured day convention. `date_received_penro` remains the accepted receipt endpoint. Future deadlines are excluded at the selected Manila cutoff; an approved zero-duration receipt remains valid.

An empty due cohort is not treated as a 100% score. Incomplete evidence withholds official TC/SE/OTPS and ranks. The existing 80/20 TC/SE policy, all-due denominators, pooled unit arithmetic, and TC-first ranking are unchanged. A focused metrics test confirms that three on-time obligations and one approved miss produce TC 75%; unknown evidence for the fourth obligation withholds the score.

Historical positive reports remain outside the live numerator. The new setup makes original receipt provenance visible and can use it to block an erroneous missed-report declaration, but does not change official positive-historical-row counting. If full 2026 scoring needs a verified pre-effective historical receipt to count as an on-time numerator, that remains a separate business-policy decision. For example, a report with an immutable original PENRO receipt of 2026-03-25 can prevent the same obligation from being certified as non-received, while the current live scoring path still excludes that historical row from the live numerator.

## Conservation source row 38 (read-only reconciliation)

The ordinary source row was not modified. Its live lifecycle has `date_conducted=2026-03-18` and `date_received_penro=NULL`. Its immutable `regular_pamb` origin contains CENRO release **2026-03-25**, original PENRO receipt **2026-03-25**, and regional endorsement **2026-03-31**. The existing historical projection supports those dates; the existing projected deadline is **2026-03-31**, with timeliness **Outstanding** and submission status **Completed**. These are original-provenance facts, not live lifecycle-field values.

An isolated SQLite fixture reproduces the same split without touching the ordinary row. The assessment surfaces the projected 2026-03-25 receipt as conflicting evidence and rejects a non-receipt declaration. The test confirms that live `date_received_penro` stays NULL, the historical marker stays set, and the attachment path stays NULL. No routing or attachment operation is invoked.

## New migration and dependencies

`database/migrations/2026_10_11_000003_create_office_tat_obligation_evidence_setup_tables.php` is additive. It creates:

1. `office_tat_approval_evidence`;
2. `office_tat_applicability_versions`;
3. `office_tat_obligation_schedule_versions`; and
4. `office_tat_non_receipt_register_versions`, including the exact keys and readable obligation manifest.

It also adds approval authority/reference/rationale/effective bounds/evidence ID columns to the technical-classification table created by the scoring migration. It seeds no schedules, classifications, registers, or approvals. Its `down()` removes the new tables and those classification columns, so rolling it back after approvals have been entered would discard the saved setup evidence.

The new migration depends on `2026_10_11_000002_create_office_tat_compliance_assessment_tables.php` being installed first, as that migration creates the classification table it extends. The assessment schema in turn relies on `users` and `protected_areas`, plus the already-applied October 9 historical-provenance and Office TAT permission migrations. The separate `2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php` migration is not a scoring/setup dependency and remains separate. The readiness snapshot reported the scoring migration and PAMO pilot migration pending; this task did not migrate the ordinary database or run a blanket migration.

With the new schema absent, `configuration_schema_ready` remains false, read paths guard the new tables/columns, the UI explains that setup is read-only, and configuration controls are disabled. No permission grant, PA activation, PAMO/CENRO mapping, User Management rule, Average TAT configuration, report, or tie decision was added.

## Routes and changed paths

New configure-gated endpoints are `POST /admin/office-tat/approval-evidence`, `POST /admin/office-tat/applicability`, `POST /admin/office-tat/obligation-schedule`, and `POST /admin/office-tat/non-receipt-register`. Approval files are downloaded through `GET /admin/office-tat/approval-evidence/{reference}` under `office-tat.view` with the uploader/global-access check.

Files changed for this setup:

- `app/Http/Controllers/OfficeTatController.php`
- `app/Models/OfficeTatApplicabilityVersion.php`
- `app/Models/OfficeTatApprovalEvidence.php`
- `app/Models/OfficeTatNonReceiptRegisterVersion.php`
- `app/Models/OfficeTatObligationScheduleVersion.php`
- `app/Models/OfficeTatTechnicalClassificationVersion.php`
- `app/Services/OfficeTatComplianceAssessmentService.php`
- `app/Services/OfficeTatObligationEvidenceSetupService.php`
- `database/migrations/2026_10_11_000003_create_office_tat_obligation_evidence_setup_tables.php`
- `resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx`
- `routes/web.php`
- `tests/Feature/OfficeTatComplianceAssessmentTest.php`
- `tests/Unit/OfficeTatComplianceMetricsTest.php`
- `docs/cds-smart-office-tat-obligation-evidence-setup-verification-2026-10-11.md`

## Example owner inputs (templates only; not entered)

**PA applicability:** Select a PA by its PA ID, then select the exact active workflow/activity/document pair. Choose `Applicable` or `Not applicable`, enter the owner's effective-from/to bounds, approving board/office, written resolution/reference, a specific rationale, and upload that decision. For `Unknown`, save the unknown window and reason without attaching or implying an approval.

**Meeting inventory:** For a real `regular_pamb` occurrence, enter the exact PA and `Minutes` pair, source period, a stable occurrence ID from the approved minutes/register, the source-supported original start, and the authorized deadline. Attach the approved occurrence register and cite its authority/reference. To assert zero meetings, submit an empty event list with the signed inventory that explicitly verifies zero events. These examples describe form fields; they do not approve PA ID 1 or any actual meeting.

**Development non-receipt:** Choose a directory participant such as `cenro:1`, the assessment period and cutoff, then select exact visible obligation keys (for example, an ENGP key shaped like `cenro:1|cbep|2026|2026-01`). Attach the signed historical inventory, approving authority, written reference, and rationale. The saved manifest snapshots the human-readable workflow/activity/document and source period. A positive receipt or original historical receipt disables/rejects that duty.

**Technical classification:** Select a catalogue workflow, choose the approved classification value, enter the authority, written reference, rationale, and effective-from/to bounds, and upload the supporting program/technical determination. The value is not inferred from the user's configure permission.

No sample identity, date, authority, or reference above was stored as owner approval.

## Fresh verification

The feature and metric tests positively assert `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. `phpunit.xml` sets `APP_ENV=testing`, `DB_URL=''`, `MAIL_MAILER=array`, and the in-memory SQLite database. The focused feature setup also uses `Storage::fake('local')`, `Notification::fake()`, `Mail::fake()`, and mocks the archive and compliance-delivery services.

| Check | Result |
| --- | --- |
| `php artisan test tests/Feature/OfficeTatComplianceAssessmentTest.php tests/Unit/OfficeTatComplianceMetricsTest.php` | Passed: 17 tests, 221 assertions. Includes six PA/four CENRO roster identities and view access, stale classification/tie fingerprints, old-checkbox rejection, effective-boundary applicability, no manufactured meeting duties, two distinct occurrences, duplicate live-source identity, exact pre-effective non-receipt manifest, conflicting receipt rejection, row 38 provenance, source date conventions, and the 75%/unknown-evidence formula case. |
| `php artisan test tests/Feature/OfficeTatAverageRankingTest.php tests/Feature/TimelinessPresentationTest.php` | Passed: 18 tests, 164 assertions. Existing Average TAT and timeliness presentation contracts remain intact. |
| Selected Node shared-UI tests (`officeTatScope`, `officeTatEvidence`, Office TAT ranking/historical ranking, shared filter presentation, modal layer) | Passed: 20/20 using `--test-isolation=none --test-concurrency=1`. |
| `npm run build` | Passed with the guarded candidate workflow. The verifier found 158 ESM assets and 91 reachable relative imports; only the verified candidate was promoted to `public/build`. |
| `php -l` on the changed Office TAT controller, models, services, migration, routes, and PHP tests | Passed; no syntax errors. |
| `git diff --check` | Passed. Git printed CRLF-to-LF notices for unrelated accumulated JS files; no whitespace error was reported. |
| Authenticated browser/screenshot review | Not performed. The build and shared component contract tests are not browser acceptance evidence. |

No ordinary database migration, report/configuration write, business approval, account/grant/mapping change, tie resolution, routing, mail/Drive operation, server restart, staging, commit, push, or demo cleanup was performed. The accumulated worktree was preserved. `.env`, `.recovery/`, the system drive, and protected backups were not opened or changed.

## Remaining owner evidence and decisions

- Obtain owner-approved applicability and effective bounds for the actual PA/workflow/activity/document pairs; then enter supported recurring duties and actual meeting occurrences (or explicit zero-event inventories) with original dates and source deadlines.
- Obtain complete historical Development receipts/non-receipt inventories for the pre-effective gap. The current permitted non-receipt register can attest only to its exact selected obligations and cutoff; it does not turn an empty table into evidence.
- Obtain the authorized technical/program classification source and effective bounds for workflows whose classifications are needed for complete scoring/tie-breaks.
- Decide separately whether positive historical rows with verified original receipts should ever count in the official live numerator. This implementation preserves the current exclusion.
- Keep MPL's office, MHRWS's supervising PENRO, and PBPLS's supervising CENRO routing mappings unresolved until separately approved.

Until these facts are supplied and the ordered migrations are separately authorized and installed, the application must continue to withhold official 2026 ratings. No browser acceptance or installation approval is claimed by this report.
