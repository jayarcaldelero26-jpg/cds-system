# CDS-SMART: Office TAT native MySQL installation readiness

**Date:** 2026-10-11<br>
**Status:** The two scoring/setup migrations passed native MySQL proof in an owned disposable schema. A scoring-only installation package is ready for review. The ordinary database was not migrated, no backup or restore was performed, and browser acceptance remains outstanding.

## Native MySQL proof

The existing Laravel connection resolved to `cds_system` on MySQL 8.4.3. Before any target DDL, the harness checked that its unpredictable candidate was absent, distinct from `cds_system` and visible test/demo targets, and on the same server. It created and verified a task ownership marker, then switched the writer process to `APP_ENV=testing` and that exact schema. Each writer rechecked the selected schema and marker. Storage, archive, notification, mail, cache, session, and queue behavior was isolated or faked; no external delivery ran.

The native target applied only the explicit prerequisites needed for the synthetic exercise, followed by the scoring/setup migrations in order:

1. `database/migrations/2026_10_11_000002_create_office_tat_compliance_assessment_tables.php`
2. `database/migrations/2026_10_11_000003_create_office_tat_obligation_evidence_setup_tables.php`

Migration 002 created `office_tat_assessment_scope_versions`, `office_tat_technical_classification_versions`, and `office_tat_tie_decisions`. Migration 003 created `office_tat_approval_evidence`, `office_tat_applicability_versions`, `office_tat_obligation_schedule_versions`, and `office_tat_non_receipt_register_versions`; it added `approving_authority`, `approval_reference`, `rationale`, `effective_from`, `effective_to`, and `evidence_id` to technical-classification versions. Both migrations left business setup records empty and added no permission grants. The separate `2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php` migration was absent from the target.

The schema inspection confirmed all seven tables, six classification columns, three JSON columns, three DATE columns, ten expected indexes, and three foreign-key checks. Maximum table, index, and constraint identifier lengths were 44, 62, and 62 characters respectively, within MySQL's 64-character limit. The only compatibility change was to replace the generated 65-character obligation-schedule PA foreign-key name with `office_tat_schedule_pa_fk`; it retains the same protected-area reference and cascade-delete behavior.

With 002 installed alone, the assessment read completed with `configuration_schema_ready=false`; the four 003 setup tables and classification evidence column were absent, and a setup write was rejected with HTTP 409. No missing-table/column exception escaped that read path.

Using synthetic offices, participants, approver text, and private fake files, the production setup services accepted an applicability window, an event schedule, a technical classification, and a readable non-receipt manifest. Checks covered date/JSON round trips, exact evidence scope, digest verification and tamper detection, version history, invalid scope/reference, conflicting historical receipt, overlapping applicability, and stale-version rejection. No real authority or approval was entered.

Two independent MySQL workers were held at the same pre-transaction point and attempted an applicability append using expected version 1. One appended version 2; the other returned a validation error on `expected_version`. The stored history contained one new version (maximum version 2); there was no duplicate version, raw unique-key error, or uncaught worker failure. This exposed and verified the fix that locks the stable protected-area row before reading the latest applicability version.

The migration records in the target contained exactly 002 then 003; 000001 was absent. Dependency-ordered rollback removed 003's four setup tables and six added columns, then 002's three assessment tables. The base classification row remained after 003 down. The private fake evidence file survived database rollback and would have been orphaned. Forward reapplication recreated empty tables and did not recover discarded setup facts. The owned schema, fake evidence files, and worker processes were cleaned; a final catalog check found none of the task's candidate schemas present.

## Ordinary database state and installation boundary

A bounded, read-only check of the ordinary connection confirmed database `cds_system` on MySQL 8.4.3. The October 9 provenance/configuration migration and October 9 permission migration are recorded in batch 41. The October 11 PAMO pilot (000001), scoring migration (000002), and evidence/setup migration (000003) are not recorded. The `users`, `protected_areas`, `organizational_offices`, `permissions`, `role_has_permissions`, and `migrations` tables exist. The existing `office-tat.view` permission has 12 role grants and `office-tat.configure` has 2; the new migrations do not alter these. The ordinary state received no migration, seed, fixture, approval, routing, report, or configuration write.

For a later scoring-only installation, authorize and run only the two paths above, in that order, through a reviewed change window with an explicit connection-identity check. Do not run blanket migrations or include the separate 000001 PAMO origin-account pilot. Before installation, take a protected full database backup and verify a restore into an isolated recovery target. Validate the restored database identity, critical table/count baselines, migration history, and the seven new table/six-column expectations before approving the production change.

There are no real approval files from this feature in the disposable proof. If owners later register them, back up the private file store separately from the database and coordinate both snapshots at the same point (for example, quiesce uploads during capture). Restore both into an isolated target, verify each stored SHA-256 against its file, and confirm the database evidence rows and files resolve together before installation. A database-only restore or rollback can leave files orphaned or database references without files.

## Read-only post-install acceptance

After a separately authorized installation, verify without entering business facts:

- Both migration records are present in order; all seven expected tables and six classification columns exist; keys, indexes, dates, JSON, and foreign keys match this proof.
- `configuration_schema_ready=true`; assessment reads work; setup controls are enabled only for already authorized configurators. Existing view/configure permissions and grants remain unchanged.
- No applicability approvals, schedules, occurrence inventories, technical classifications, non-receipt declarations, evidence files, or tie decisions were seeded by installation.
- Compare pre-install baselines to confirm report/custody rows and counts, receipt values, routing assignments, settings, and user/access state are unchanged. Keep the official score withheld until required owner evidence is supplied.

The tested `down()` operations are destructive to saved assessment/setup facts. Migration 003 down drops its four setup tables and its six added columns; migration 002 down drops its three assessment tables. Reapplying the migrations only restores empty schema. Do not use these rollbacks as a production recovery strategy, and never roll back the October 9 provenance migrations as a shortcut.

## Owner browser acceptance checklist

When the package is installed in an authorized review environment, have an editor and a read-only user confirm:

- Editors can select only supported PA/workflow/activity/document identities and can save valid applicability, occurrence, classification, and non-receipt evidence with clear effective dates and citations.
- Private evidence preview/download is limited to authorized viewers or the uploader; wrong-scope references, digest tampering, overlapping windows, incomplete inventories, and stale versions show useful errors.
- Read-only users cannot change setup. Coverage and blockers are derived from the approved source facts rather than the legacy completeness checkbox.
- Tables, forms, evidence detail, dark mode, responsive widths, keyboard focus, and validation states work in the browser; displayed source identity and dates match the underlying report/provenance.

No authenticated browser or screenshot review was performed for this readiness task.

## Remaining owner evidence and policy

Before any official score, owners still need to supply the applicable workflow/activity/document decisions and effective bounds; complete supported occurrence inventories, including signed explicit zero-meeting inventories where appropriate; complete historical prior-receipt and non-receipt evidence; and the authorized technical-classification authority, reference, and effective bounds. Unknown periods remain unknown, not assumed misses. The 2026-10-11 effective policy date remains unchanged, and no full-2026 score is manufactured.

Conservation source row 38 demonstrates the historical-credit boundary. Its immutable original provenance shows a PENRO receipt on 2026-03-25, while the live lifecycle `date_received_penro` remains NULL. The verified original receipt blocks a false missed/non-receipt declaration, but the current live numerator still gives the historical row no positive credit. The historical-positive-credit policy remains open; row 38's source dates, live fields, attachment, routing, and Average TAT were not changed.

## Prior evidence reused and task edits

The earlier 2026-10-11 verification remains prior evidence, not a rerun: 17 compliance/metrics tests with 221 assertions; 18 Average TAT/timeliness tests with 164 assertions; 20 selected UI checks; guarded build verification of 158 assets and 91 relative imports; and prior PHP syntax/diff checks. These SQLite/UI/build results are not native MySQL or browser acceptance evidence. For this task, the native MySQL contract above passed and PHP syntax checks passed for the service and migration. The working-tree `git diff --check` completed without whitespace errors; Git emitted only CRLF-to-LF notices for unrelated accumulated JavaScript files. The task-edited source and report were also checked for trailing whitespace. No frontend build was needed.

Paths edited for this task:

- `app/Services/OfficeTatObligationEvidenceSetupService.php` — serialize applicability version checks by locking the protected-area row.
- `database/migrations/2026_10_11_000003_create_office_tat_obligation_evidence_setup_tables.php` — give the schedule PA foreign key a MySQL-compatible explicit name.
- `docs/cds-smart-office-tat-native-installation-readiness-verification-2026-10-11.md` — this installation-readiness report.

The temporary native runner was removed. Accumulated unrelated worktree changes and recovery files were preserved. No ordinary backup/install, owner approval, PA activation, routing/account/grant change, service restart, staging, commit, push, or demo cleanup was performed.
