# PAMO Registration and User Management Verification — 2026-10-12

## Registration and administrator flow

A public applicant selects the existing PAMO category, searches the eligible Protected Area list, and sees its canonical PAMO and supervising offices supplied by the server. A successful application remains pending with `no_role`. The same assignment review is used by administrator create/edit and approval; client-supplied PAMO or supervisor identities are rejected, and eligibility is checked again on submission and approval.

User Management now shows category, PA, canonical offices, roles, approval and activity state, and unresolved mapping reasons. Administrators can correct a legacy assignment through the existing form. The PA edit surface has searchable explicit PAMO and supervisor selectors and is linked from the mapping review. Saves remain explicit administrator actions; no account, role, PA status, PAMO mapping, approval, or report was changed for this verification.

`OrganizationalAccessService::pamoAssignmentReviewForProtectedArea()` is the shared eligibility policy. It requires an Active PA, an active explicitly bound PAMO office, and an active supervising office of the canonical type from `ProtectedAreaRoutingPolicy`. Public registration receives only eligible PAs; administrators also see ineligible rows and their reasons. The accepted routing remains intact: affiliated PAMO origins hand off first to their assigned CENRO CDS Focal, while MHRWS routes directly to its assigned PENRO Records. Existing ENGP registration and routing are unchanged.

## Current PA eligibility

All six PAs have an explicit active supervisor assignment in the current office-assignment records. Those stable records resolve the earlier supervisor questions: MPL and PBPLS use CENRO Mati; MHRWS uses PENRO Davao Oriental; Baganga-area PAs use CENRO Baganga. No supervisor mapping was inferred or changed. None has an explicit PAMO office identity, and the public eligible-PAMO option count is zero.

| Protected Area | Current status | Existing active supervisor | PAMO eligibility |
| --- | --- | --- | --- |
| Aliwagwag Protected Landscape | Proposed | CENRO Baganga (`cenro_baganga`), required type CENRO | Blocked: only Active PAs can register; no PAMO identity is mapped. |
| Baganga Mangrove Swamp Forest Reserve | Proposed | CENRO Baganga (`cenro_baganga`), required type CENRO | Blocked: only Active PAs can register; no PAMO identity is mapped. |
| Baganga Protected Landscape | Proposed | CENRO Baganga (`cenro_baganga`), required type CENRO | Blocked: only Active PAs can register; no PAMO identity is mapped. |
| Mati Protected Landscape | Proposed | CENRO Mati (`cenro_mati`), required type CENRO | Blocked: only Active PAs can register; no PAMO identity is mapped. |
| Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS) | Active | PENRO Davao Oriental (`penro_davao_oriental`), required type PENRO | Blocked: no stable PAMO office identity is mapped. |
| Pujada Bay Protected Landscape and Seascape (PBPLS) | Proposed | CENRO Mati (`cenro_mati`), required type CENRO | Blocked: only Active PAs can register; no PAMO identity is mapped. |

The five Proposed statuses were left unchanged. For live PAMO registration, an administrator must map the real PAMO identity for MHRWS; Proposed PAs also require separate authorized activation before they can become eligible, followed by an explicit PAMO mapping. No mappings were guessed or saved.

## Migration and data preservation

The sole pending migration, `2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity`, is now **Ran** (batch 12). A fresh protected full backup was hash-verified, restored to a distinct marker-owned clone, and rehearsed before cutover. The rehearsal passed with foreign key checks enabled; its clone was removed only after its owner marker and completed migration row were verified. The temporary MySQL credential and restore scripts were removed. The protected backup and receipts remain in a private ACL directory.

The live install ran only that migration under Laravel maintenance mode. The ordinary login route returned 503 during the gate; three quiet samples showed no other client sessions, active queries, transactions, enabled database events, or queued jobs. The queue driver was `sync`; internal MySQL daemon threads were excluded from client-session counts. After verification, only this task's gate was released; maintenance files are absent and loopback login returned HTTP 200. No service was restarted.

The migration added eight nullable origin columns to `submission_routing_snapshots`, `organizational_office_id` to `office_tat_configuration_versions`, and the `pamo_pamb_drafts` table. Exactly 11 new foreign-key references were verified. The new table is empty; all new provenance columns are null. There were zero active legacy PAMO labels and zero PAMO assignment rows before and after, so no office or PA mapping was backfilled. All 85 pre-existing tables' data and existing sequence values matched the pre-install baseline; the migration ledger gained only this migration row. User/grant, PA/supervisor assignment, report, routing snapshot, TAT configuration, settings, and other existing data remained unchanged.

## TAT unit display correction

The Office TAT page no longer optimistically changes its unit label while retaining the previous roster. The selected unit, heading, row identity prefix, and displayed assessment now follow the server-returned assessment unit after switching. The regression covers sequential Conservation/Development responses. Scoring formulas, deadlines, obligations, report attribution, and routing were not changed.

## Verification

Fresh checks for this task:

- PHP syntax checks passed for the changed PHP files; `git diff --check` passed.
- Focused feature run: 35 tests / 476 assertions passed. After the final legacy-assignment case was added, `OperationalGroupRegistrationTest` passed 12 tests / 180 assertions.
- PAMO routing and migration unit suites passed 10 tests / 280 assertions.
- `node tests/js/officeTatUnitPresentation.test.mjs` passed 2 tests. The `node --test` launcher hit sandbox `spawn EPERM`; direct execution passed.
- Guarded frontend build passed: 1,467 modules transformed, 160 ESM assets and 93 generated relative imports verified before promotion.
- Laravel reports the single PAMO migration as Ran. Live post-install verification reported zero table-data, schema, or sequence mismatches.

No browser was available in the existing CUA inventory (`apps: []`, `browsers: []`), so there was no live visual acceptance. Owner checklist: open Register and confirm the PAMO unavailable-help state; inspect Admin > Users and the PA mapping review; after authoritative mappings are saved, confirm a valid applicant remains pending `no_role`; switch Office TAT between Conservation and Development and confirm each population and label follows the returned unit. No dummy account or report should be created for these checks.

## Changed paths and Git state

Application and test paths changed:

- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Controllers/Auth/RegisteredUserController.php`
- `app/Http/Controllers/ProtectedAreaController.php`
- `app/Http/Requests/StoreUserRequest.php`
- `app/Http/Requests/UpdateUserRequest.php`
- `app/Services/Authorization/OrganizationalAccessService.php`
- `resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx`
- `resources/js/Pages/Admin/OfficeTat/Index.jsx`
- `resources/js/Pages/Admin/OfficeTat/unitPresentation.mjs`
- `resources/js/Pages/Admin/Users/Form.jsx`
- `resources/js/Pages/Admin/Users/Index.jsx`
- `resources/js/Pages/Auth/Register.jsx`
- `resources/js/Pages/ProtectedAreas/Form.jsx`
- `tests/Feature/OfficeTatComplianceAssessmentTest.php`
- `tests/Feature/OperationalGroupRegistrationTest.php`
- `tests/Feature/PamoPambDraftRoutingTest.php`
- `tests/Feature/ProtectedAreaManagementTest.php`
- `tests/Feature/UserManagementPresentationTest.php`
- `tests/js/officeTatUnitPresentation.test.mjs`
- `docs/cds-smart-office-pamo-registration-user-management-verification-2026-10-12.md`

The worktree has 17 modified tracked files, 6 untracked paths (including the two new source/test files and this note), and 0 staged files. The pre-existing `%SystemDrive%/` and the two earlier verification notes remain untouched. Nothing was committed or pushed; remote synchronization was not checked.