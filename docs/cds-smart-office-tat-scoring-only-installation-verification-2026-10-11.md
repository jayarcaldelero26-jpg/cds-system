# CDS-SMART: scoring-only Office TAT installation verification

**Date:** 2026-10-11
**Status:** Installed and read-only acceptance passed. Coverage remains incomplete, so official ratings remain withheld.

## Authorized schema change

Only these migration paths were applied to the ordinary database, separately and in order:

1. database/migrations/2026_10_11_000002_create_office_tat_compliance_assessment_tables.php
2. database/migrations/2026_10_11_000003_create_office_tat_obligation_evidence_setup_tables.php

Both completed successfully. The PAMO/PAMB pilot migration, 2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php, remains unapplied. The ordinary migration history grew from 112 to 114 entries; the original migration rows are unchanged. No seeder, blanket migration, business configuration save, permission grant, historical-credit change, or report action was performed.

## Protected backup and restore rehearsal

A full MySQL 8.4.3 schema-and-data dump was created outside the repository with triggers, routines, events, and migration history included. It is retained in an account-restricted location. The protected local recovery receipt contains its private location and server identity, the ownership marker record, and detailed baseline, restore, and installation fingerprints.

- Dump size: **276,784 bytes**
- SHA-256: **a25337683086f82186fc36c5938a6638a316163ec3591861683289f6e6eeba66**
- The exact dump was imported into a newly generated, marker-owned recovery schema. Its pre-install table data, row counts, migration history, table definitions, and database-object definitions matched the ordinary database. A canonical comparison treated MySQL’s redundant explicit CHARACTER SET utf8mb4 rendering as equivalent where the same utf8mb4 collation was present.
- The same two migration paths then passed against the restored copy. Existing data still matched; seven setup tables were created empty; the assessment service reported configuration_schema_ready=true and incomplete coverage.
- The recovery schema was removed only after verifying its ownership marker. The backup and protected receipt remain.
- The feature’s private approval-evidence folder contained no files at capture time, so no feature evidence copy was needed. Existing attachments and archives were left in place.

## Ordinary database acceptance

The seven expected tables now exist:

- office_tat_assessment_scope_versions
- office_tat_technical_classification_versions
- office_tat_tie_decisions
- office_tat_approval_evidence
- office_tat_applicability_versions
- office_tat_obligation_schedule_versions
- office_tat_non_receipt_register_versions

The technical-classification table has all six required fields: approving_authority, approval_reference, rationale, effective_from, effective_to, and evidence_id. Required JSON/date types, ten expected named indexes, and foreign-key definitions match the verified restore rehearsal. The assessment read works and configuration_schema_ready=true.

All seven new tables are empty. No scope, applicability, schedule, occurrence, classification, non-receipt, evidence, or tie-decision facts were entered. No unit has complete coverage.

Fingerprints for all 78 pre-existing tables, their rows and definitions, the migration history, and database objects match the pre-install baseline, aside from the two intended migration records and seven new tables. This includes the historical provenance, Conservation submission, receipt/custody, routing, settings, user, role, and permission data. Conservation 38’s original March 25 receipt and null live PENRO receipt remain as captured; Conservation 39’s CENRO interval and custody history are unchanged. Existing grants are unchanged: office-tat.view has 12 role grants and office-tat.configure has 2. The routes continue to require office-tat.view for viewing and office-tat.configure for setup writes; the migrations added no permissions.

Average TAT, preparation clocks, historical date gaps, and historical-credit policy were not changed. In particular, historical positive credit remains undecided. Historical rows stay outside the current live numerator while verified original receipts continue to prevent false non-receipt declarations.

## Maintenance window and app availability

Before capture, the task confirmed a synchronous queue with no pending or reserved jobs, no active database transaction, and no other active application connection. Ordinary application routes returned HTTP 503 during the task-owned maintenance window. Laravel’s /up health route bypasses maintenance mode; it returned HTTP 200 during the window and was not used as the gate check.

The task released only its maintenance file after verifying its recorded hash. The local /up and /login routes then returned HTTP 200. No web server or database service was restarted or reconfigured.

Browser-level acceptance is **pending** because no authorized existing browser session was available. Owner checklist:

1. Sign in with an existing account that already has office-tat.view and, for setup changes, office-tat.configure.
2. Open Admin > Office TAT and confirm the assessment and setup controls are visible.
3. Review the empty setup state. Do not save any values until their real source documents and approvals are available.

## Owner evidence still required

The installed schema provides storage and authorized setup routes for the controls; it does not create approved business facts. Before relying on a score, owners still need to provide and verify:

- The actual Conservation workflow scope and completeness inventory for each protected area.
- Approved applicability intervals and obligation schedules, including occurrences and supporting authority, reference, rationale, and evidence.
- Verified prior receipt and non-receipt registers; missing evidence must not be inferred.
- Approved technical classifications and effective dates for the relevant report workflows, plus approved tie decisions if needed.
- The unresolved MPL office, MHRWS PENRO, and PBPLS CENRO mapping decisions.

Until those records support complete coverage, no official 2026 rating is publishable. Do not backdate the current policy, assign historical positive credit, or alter live Average TAT to make coverage appear complete.

## Evidence boundary

The native MySQL readiness, SQLite, UI, and guarded-build evidence in the [prior readiness report](cds-smart-office-tat-native-installation-readiness-verification-2026-10-11.md) was reused as dated evidence. Tests and builds were not rerun for this schema-only installation. Fresh evidence for this phase is the protected backup and restore comparison, the two exact migrations, read-only service/schema/data acceptance, owned maintenance-gate release, and local HTTP availability.
