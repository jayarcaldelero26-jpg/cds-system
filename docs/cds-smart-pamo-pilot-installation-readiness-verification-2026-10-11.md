# CDS-SMART PAMO pilot installation readiness verification

**Date:** 2026-10-11
**Status:** Native MySQL migration and PAMO concurrency checks passed in an owned disposable schema. The ordinary application database remains unmigrated. PAMO registration still has zero eligible protected areas until the owner resolves three supervisor assignments.

## Current mapping state

The normal Laravel-resolved local application connection is MySQL. Its only pending migration is `2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity`. It was not applied to this database. Remote or deployment database state was not inspected.

There are six protected-area records: **3 Active, 3 Proposed, and 0 with literal status `Inactive`**. The prior follow-up's phrase “3 inactive PAs” was imprecise: those three records are Proposed. The three Active rows below have legacy PAMO labels but no explicit PAMO assignment and no supervising assignment. There are no PAMO office records today, and the real registration option query returns zero rows.

| PA identity | PAMO office | Supervisor | Proposed field effects | First recipient | Readiness |
| --- | --- | --- | --- | --- | --- |
| **ID 1 — Mati Protected Landscape (MPL), Active** | No office row or explicit assignment. Legacy label: `PAMO-MPL`. Candidate new identity: active PAMO office, code `pamo_pamo-mpl_b1600c8e069d97b1b3ec84eb005b60f7`; ID allocated at installation. | No assignment. Active candidate `CENRO Mati` is office **ID 4**, but no PA-to-office evidence currently binds them. Confirm the authoritative office ID. | Add the approved `supervising` assignment; insert/reuse the PAMO office and add the `pamo` assignment. Keep the Active status and existing legacy label. | CENRO CDS Focal at the approved CENRO. If ID 4 is confirmed, CENRO CDS Focal at CENRO Mati. | **Not eligible.** Supervisor ID and explicit PAMO link are missing. |
| **ID 2 — Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS), Active** | No office row or explicit assignment. Legacy label: `PAMO-MHRWS`. Candidate new identity: active PAMO office, code `pamo_pamo-mhrws_4f2fee6d4b9d43000b9d51500263720f`; ID allocated at installation. | No assignment. Existing active PENRO offices are **ID 5 — PENRO Davao Oriental** and **ID 6 — PENRO Mati**. The current data does not identify which is MHRWS's supervising office; do not choose by geography. | Add the owner-selected active PENRO `supervising` assignment; insert/reuse the PAMO office and add the `pamo` assignment. Keep Active status and the existing legacy label. | **PENRO Records**, directly, with no CENRO hop, under the selected supervising PENRO. | **Not eligible.** The authoritative PENRO ID and explicit PAMO link are missing. |
| **ID 3 — Pujada Bay Protected Landscape and Seascape (PBPLS), Active** | No office row or explicit assignment. Legacy label: `PAMO-PBPLS`. Candidate new identity: active PAMO office, code `pamo_pamo-pbpls_d35dccc60cbdaa51f750d9f76ed07c2e`; ID allocated at installation. | No assignment. Active CENRO candidates are **ID 1 Baganga, ID 2 Manay, ID 3 Lupon, and ID 4 Mati**. The current records do not settle the PA's CENRO; do not infer it from the PA name or location. | Add the owner-selected active CENRO `supervising` assignment; insert/reuse the PAMO office and add the `pamo` assignment. Keep Active status and the existing legacy label. | CENRO CDS Focal at the approved CENRO. | **Not eligible.** The authoritative CENRO ID and explicit PAMO link are missing. |

The complete active office catalogue currently contains CENRO Baganga (ID 1), CENRO Manay (ID 2), CENRO Lupon (ID 3), CENRO Mati (ID 4), PENRO Davao Oriental (ID 5), and PENRO Mati (ID 6). All six are active; no current office is of type PAMO.

### APL and preserved source records

Aliwagwag Protected Landscape (APL) is PA **ID 4**, currently **Proposed**, with legacy label `APL-PAMO`. Its PA master row currently has no supervisor or PAMO assignment. The owner-established route to CENRO Baganga is corroborated by live Conservation39's `target_office`; CENRO Baganga is organizational-office **ID 1**, and the route's first recipient is CENRO CDS Focal there. Do not activate APL as part of this installation. If its PA master assignment is later maintained, use the owner-established ID 1 relationship and keep its status Proposed unless separately authorized.

Read-only closeout confirmed Conservation39 remains live, targets CENRO Baganga, and retains its attachment reference. Conservation38 remains historical and separate, with its attachment reference populated. The previously verified **22,682-second CENRO Baganga interval** is retained as the baseline; this readiness pass did not recalculate it. No source rows, events, snapshots, files, office mappings, routing settings, or watermarks in the ordinary database were changed.

## Owner decisions required

Before any operational mapping write, the owner must provide or confirm:

1. The authoritative supervising-office ID for MPL (ID 1). CENRO Mati ID 4 is only a candidate, not a current assignment.
2. Which active PENRO office ID supervises MHRWS (ID 5 or 6, or another authoritative ID), while keeping its direct PENRO Records route.
3. The authoritative active CENRO office ID for PBPLS (ID 1–4, or another authoritative ID).

No PAMO office IDs exist yet. The names above are exact candidates from the existing `protected_areas.pamo` values; IDs should be generated by the supported PAMO office mapping action only after the supervisor choices are approved.

## Supported mapping method and dependency order

The existing Protected Areas management form and `ProtectedAreaController@update` are sufficient; no new installation endpoint or broad seeder is needed. The edit request accepts `supervising_office_id`, `pamo_office_id`, and the existing `pamo` label. The controller saves the PA and calls `assignSupervisingOffice` followed by `assignPamoOfficeByName` in one transaction. With no PAMO office selected, the current service creates or reuses a stable active `pamo` office identity from the entered legacy name and records its explicit PA assignment. The form is available through the existing protected-area edit workflow and requires the existing `protected-areas.update` authority.

For each of the three Active PAs, the exact intended effects are:

- Insert or update one `protected_area_office_assignments` row with `assignment_type = supervising`, the owner-approved active CENRO/PENRO office ID, the acting administrator as `assigned_by`, and current timestamps.
- Insert one `organizational_offices` row per PA with the table's candidate `code`, exact legacy `name`, `office_type = pamo`, `is_active = true`, and timestamps; then insert one `protected_area_office_assignments` row with `assignment_type = pamo`, the allocated PAMO office ID, the acting administrator as `assigned_by`, and timestamps. No current explicit PAMO value is available to preserve, and no PAMO office ID can yet be stated.
- Keep `protected_areas.status` unchanged and retain the exact existing `pamo` label. The supported update sets `updated_by` to the authorized updater and advances `updated_at`; no user, role, permission, report, custody event, or notification is created by this mapping step.

The supervisor assignment is a pre-migration dependency for legacy backfill: the feature migration only backfills an active PA when its authoritative active supervisor has the required type (CENRO except direct-route MHRWS, which requires PENRO). The supported edit action can create and attach the PAMO identity in the same transaction, so the reviewed mapping changes should precede this migration. That makes the migration preserve the explicit PAMO mappings rather than create new identities from incomplete legacy data.

## Installation runbook for a later authorized cutover

1. Confirm the intended target through the normal Laravel-resolved runtime, verify that only this feature migration is pending, and review the three supervisor IDs above. Do not infer IDs or rely on a source report's captured route as a substitute for a missing PA master assignment.
2. Before operational writes, take a protected full backup and verify its restore into a separate disposable schema. Never restore over the live schema. Follow the established service and cutover process when installation is authorized.
3. During that approved cutover, use the existing Protected Areas edit workflow to apply the three reviewed supervisor/PAMO mappings. Preserve each PA's status and all unrelated PA fields. Then run `php artisan migrate --path=database/migrations/2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php --force` against the verified target. Do not run broad seeders, unrelated pending migrations, `migrate:fresh`, or rollback as an installation/recovery procedure. The migration grants no permissions, and its `down()` fails closed.
4. Re-run the actual `pamoRegistrationProtectedAreas()` option query and confirm that exactly the three approved Active PAs are eligible. Test public registration and staff approval through the existing workflow; registrations remain pending `no_role` and scoped to the approved PA/PAMO identity. Do not grant system roles or administrative permissions.
5. Owner browser acceptance must cover draft partial save/reopen, complete validation and Forward, CENRO Focal versus direct MHRWS PENRO Records first recipient, Receive-first correction handling, and distinct preparation versus receipt analytics. A check needing a genuinely new report is a separately authorized pilot action; do not make dummy reports in the ordinary database.
6. Compare pre/post source counts, custody events, snapshots, routing settings and watermarks, notifications, and protected-file references. Conservation38 and its attachment must remain historical and separate; Conservation39 keeps its valid CENRO Baganga interval. If installation fails, inspect partial DDL/backfill before retry and recover from the verified backup through the established cutover process; do not rely on `down()`.

No backup/restore rehearsal or operational cutover was performed in this task. No account, office mapping, permission, or test report was created in the ordinary database.

## Native MySQL verification

The prior repeatable-read regression had skipped because no disposable target was configured. This task created one uniquely named schema with the existing Laravel-resolved credentials, verified its live `DATABASE()` identity differed from the ordinary database, confirmed `APP_ENV=testing`, explicitly selected MySQL, cleared the database URL, and checked `REPEATABLE-READ` before migrations, fixtures, and workers. Worker credentials were passed only through the protected process environment; they were not placed in arguments, output, or this report. No grants or server settings were changed.

The first full migration attempt exposed a MySQL identifier-limit defect: Laravel's generated foreign-key name for the TAT office identity exceeded MySQL's 64-character limit. The partial DDL was inspected only in the owned schema; the feature migration row and draft table were absent. A focused change gave that same foreign key the explicit name `office_tat_org_office_fk`. Only this run's identified partial schema was recreated, after verifying its identity and expected partial state. All **112 migrations** then completed in the fresh owned schema (79 tables), including the feature migration. The constraint's target column, referenced table, and delete restriction are unchanged.

The following native MySQL checks passed on the owned schema:

- `RoutingPositionControlsTest::locked route position resolution sees a committed version newer than its consistent read under mysql repeatable read`: **1 passed, 7 assertions**.
- `PamoPambDraftMysqlConcurrencyTest`: **1 passed, 89 assertions**. Separate PHP workers used separate MySQL connections and bounded lock barriers. Same-office coworkers forwarded the same complete draft concurrently; both responses identified the same promoted source, with exactly **1 source, 1 accepted origin handoff event, and 1 origin snapshot**. A later Forward replay returned that source with no additional side effects.
- A stale partial save overlapped a Forward. The save received the expected validation conflict after waiting on the draft row lock; the forwarded payload, immutable start, and originator were unchanged. A later Forward replay again returned the existing source. This scenario also retained exactly **1 source, 1 event, and 1 snapshot**.

Both race cases used synthetic PA/office/user/draft data, an explicit fake archive gateway, fake notifications, and private temporary storage under the workspace. The overlap used independent worker processes; the contender's lock attempt was observed before the first transaction was released. The owned test schema and this run's private temporary directory were removed after verification. No application service was stopped and no other test/demo schema was removed.

## Fresh checks and limits

Fresh in this task:

- `PamoPambDraftRoutingTest.php`: **9 passed, 223 assertions**.
- `PamoPambOriginMigrationTest.php`: **1 passed, 55 assertions**.
- Native MySQL repeatable-read and PAMO race checks above: **2 tests passed, 96 assertions total**.
- PHP syntax checks passed for the migration, MySQL race test, and worker. `git diff --check` passed; Git emitted only existing CRLF-normalization warnings for unrelated JSX files.

Historical checks from the prior follow-up report, not repeated here: P2 PAMO scope (3/63), shared-select JavaScript checks (5 passed), and the guarded candidate build. The earlier MySQL skip is superseded by the native MySQL pass above. The broad JavaScript fallback previously hit a Vite WebSocket port collision and remains unclaimed; it was not repeated. No authorized browser preview or staff-account fixture was supplied, so owner browser acceptance remains pending.

No frontend source changed, so no build or broad JavaScript inventory was rerun. The ordinary application migration remains pending, PAMO registration still has zero eligible options, and all three owner supervisor decisions remain outstanding.

## Working tree and cleanup

Fresh local Git status contains **157 rows**: 77 tracked modified files, 80 untracked files, and **0 staged files**. The existing 11 `.recovery/` entries remain untouched. The readiness report and two MySQL race-harness files are untracked; nothing was staged, committed, or pushed. The owned disposable schema, this run's private temporary storage, read-only helper scripts, and temporary PHPUnit configuration were removed after closeout.
