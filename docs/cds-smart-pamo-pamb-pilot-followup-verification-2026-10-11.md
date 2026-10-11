# CDS-SMART PAMO PAMB Pilot Follow-up Verification

**Date:** 2026-10-11

**Status:** TWC is enabled in the existing PAMO PAMB pilot. The normal application database was not migrated. Registration remains unavailable for PAMO until authoritative office assignments are complete.

## Registration assignment menu

The public registration GET loads `registrationOptions.protectedAreas` from `OrganizationalAccessService::pamoRegistrationProtectedAreas()`. The payload is `{ id, name, short_name, pamo_office_id }`; `Register.jsx` uses `area.id` as the option value and `area.name` as its label. A valid mapped PA is returned by the existing regression, so the selector does not drop valid options.

The ordinary Laravel-resolved local database was inspected read-only. It contains 6 PAs: 3 active and 3 inactive. There are **0 eligible registration options**. The 3 active PAs each lack an explicit PAMO assignment and each lack a supervising-office assignment. The 3 inactive PAs are rejected by status. There are no explicit PAMO assignments pointing to missing, inactive, or wrong-type offices; no active supervisor with the wrong type or inactive status; and no eligible or potential valid backfill candidate. The active PAs have legacy PAMO names, but those names alone do not satisfy the access rule. Rejection categories overlap for the 3 active rows because each fails both required assignments.

The empty menu is therefore an authoritative mapping gap, not a schema or frontend option-shape defect. The new draft/provenance migration is pending locally, but that migration cannot create the missing supervising assignments. The registration page now shows an accessible live status explaining the required active PAMO and supervising-office assignments, displays an explicit unavailable option, and disables PAMO account submission while no eligible PA exists. Backend validation and pending `no_role` registration behavior remain enforced. No live mappings, grants, or accounts were created.

For later readiness, each PA must be active, have an explicit active PAMO office mapping, and have an active supervising CENRO assignment, except MHRWS which requires its active PENRO assignment. In this local dataset, supervision must be established before the safe legacy backfill can create any PAMO identity; otherwise an administrator must map the active PAMO office through the established protected-area workflow. Do not treat applying the migration alone as sufficient.

## TWC coverage

The source catalogue already defines three distinct meeting workflows:

| Key | Label | Existing source contract |
| --- | --- | --- |
| `regular_pamb` | Regular PAMB Meetings | Activity `Regular PAMB`; Minutes, Reso, or Report |
| `special_pamb` | Special PAMB Meetings | Activity `Special PAMB`; Minutes or Reso |
| `twc_meetings` | TWC Meetings | Activity `TWC Meeting`; Report or Minutes |

TWC is not an alias. It already uses the existing quarterly period catalogue and PAMB meeting validation, MOV, route profile, correction, and preparation-analytics paths. The PAMO controller and authorization policy already allowed all three meeting keys, and the shared page and navigation already exposed TWC. The remaining exclusion was the conservation-unit middleware’s Regular/Special-only allowlist. It now uses `PambComplianceCalculator::MEETING_WORKFLOWS`, keeping that gate aligned with the existing catalogue.

The new TWC regression covers shared same-office draft editing, partial persistence outside source rows and custody events, full required-field/MOV Forward validation, promotion through the normal assigned CENRO route, focal Receive, correction return, PAMO Receive/edit/resubmission, and TWC-filtered preparation analytics separate from receipt TAT. A second regression confirms MHRWS TWC promotion goes directly to its assigned PENRO Records and requires receipt there. No report type or deadline rule was added.

## Migration review and isolated rehearsal

Read-only `migrate:status` against the ordinary local application connection reported `2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity` as **Pending**. No deployment or remote database state was inspected or inferred.

The backfill now skips inactive or soft-deleted PAs, preserves any existing explicit PAMO assignment before office creation, and only backfills when the assigned active supervisor matches the existing direct-PENRO policy for MHRWS or the CENRO policy for other PAs. It reuses one unambiguous active PAMO office identity, skips duplicate/inactive/wrong-type name matches, and uses a name hash in generated office codes to avoid slug collisions. New office creation through the administrator service applies the same collision guard. The migration still adds captured origin fields, the office identity on TAT configuration versions, and the draft table; it grants no permissions. Its fail-closed `down()` remains unchanged.

`PamoPambOriginMigrationTest` rehearsed the migration on a separately named SQLite `:memory:` connection with pre-migration fixtures. Valid CENRO and MHRWS/PENRO mappings were created; exact repeated identity reused one office; missing, wrong-type, inactive, ambiguous, soft-deleted, and conflicting-code rows were skipped; an existing explicit mapping was preserved. The draft/provenance columns were present afterward. Role/permission counts, the old source row, captured route graph version, custody event, and existing TAT configuration values were unchanged. No normal-database migration or seeder ran.

Installation remains gated on target-specific mapping review, a protected full backup with restore verification in a separate disposable schema, and the established cutover process. The current local active PAs have no supervisors, so this backfill would create no PAMO mappings for them as-is.

## Skipped test and MySQL boundary

The isolated route-position case is `RoutingPositionControlsTest::locked route position resolution sees a committed version newer than its consistent read under mysql repeatable read`. Its intentional skip reason is: “This regression only runs against the isolated disposable MySQL safety database.” The focused rerun used the configured testing SQLite `:memory:` database and reported one skipped test with zero assertions; it is not a MySQL pass.

The existing `DispatchInProgressMysqlConcurrencyTest` requires `CDS_ROUTING_MYSQL_INTEGRATION=1` and an exact `CDS_ROUTING_MYSQL_TEST_DB` name under the `cds_dispatch_guard_` prefix before it calls `migrate:fresh` or starts workers. Neither explicit opt-in variable was present. The phpunit configuration uses `APP_ENV=testing`, SQLite `:memory:`, and blank `DB_URL`. The existing repeatable-read route-position case uses a distinct `cds_routing_uat_` database guard. No MySQL connection, migration, or worker was started.

The available MySQL workers exercise BMS routing/dispatch; they do not yet exercise PAMO draft Forward or stale-save-versus-Forward. Both requested PAMO races remain **unverified**: no authorized disposable MySQL schema was configured, so source, handoff-event, and snapshot exact-once counts have no MySQL result. No fallback to the normal database was attempted.

## Fresh verification and limits

- `PamoPambDraftRoutingTest.php`: 9 passed, 223 assertions.
- `P2PamoPambScopeTest.php`: 3 passed, 63 assertions.
- `PamoPambOriginMigrationTest.php`: 1 passed, 55 assertions, isolated SQLite rehearsal.
- Route-position repeatable-read guard: 1 deliberate skip, 0 assertions.
- Shared filter/select JavaScript checks: 5 passed. There is no dedicated registration-page JS test; the registration payload is covered by the PHP feature tests.
- Guarded production build: 1,461 modules transformed; manifest verifier checked 157 ESM assets and 90 reachable relative imports before promoting the verified build.
- PHP syntax checks passed for the touched middleware, authorization service, migration, and feature/unit tests. `git diff --check` found no whitespace errors; Git emitted only existing CRLF-to-LF normalization warnings for unrelated JSX files.

The all-files JavaScript runner was not counted as passing: its default worker mode hit the sandbox process-spawn restriction, and the in-process fallback ran Vite-backed fixtures together, causing a WebSocket port `24678` collision. The focused shared-select checks and guarded production build completed. No authorized isolated browser preview/account fixture was supplied, so there was no browser acceptance run. No real user, registration, report, event, setting, or mapping was created.

## Working-tree scope

This follow-up changes the PAMO registration empty state, aligns the unit middleware with the existing three-workflow allowlist, prevents ambiguous name/slug collisions in PAMO office identity backfills and administrator-created identities, and adds isolated regressions and this report. Fresh `git status --short --untracked-files=all` reports 154 changed/untracked paths (77 tracked modified and 77 untracked), with **0 staged files**. The 11 existing `.recovery/` entries remain untracked and untouched; the accumulated unrelated work remains in place. Nothing was committed or pushed.
