# PAMO PAMB Drafts and Routing Verification

**Date:** 2026-10-11

**Status:** Implementation and isolated verification complete. The migration has not been applied to the normal application database.

## Delivered behavior

- Public registration offers PAMO only with an active Protected Area that has an explicit active PAMO office mapping and a valid supervising CENRO or PENRO. The selected mapping supplies the stable office identity; a client-submitted office name is rejected. New PAMO accounts remain pending with `no_role` and no added permissions. Administrator approval activates the account without assigning elevated access.
- Approved PAMO operators can use only Regular PAMB and Special PAMB. Drafts are shared with approved operators from the same mapped PAMO office and PA. Start is idempotent, each save checks the draft lock version, incomplete drafts can be saved, and MOV files remain private behind an ownership/scope-checked endpoint. Forwarding requires the existing PAMB validation rules.
- Forward promotes the draft once into the official conservation report and creates its captured route origin. Replaying Forward returns the existing submission without adding another report or handoff. Ordinary report creation and deletion remain unavailable to PAMO.
- APL-style areas route from the captured PAMO to the assigned CENRO CDS Focal Person, who receives before the existing CENRO preparation flow. MHRWS uses its assigned PENRO directly; there is no CENRO hop, and PENRO Records receives first. The route snapshot stores origin PA, PAMO office, responsible office, originator, and original draft start time.
- On a correction return, PAMO can edit the existing report only after receiving that correction at the captured origin. The corrected document can then be resubmitted through the captured route. The user interface exposes correction editing only while that state is ready.
- PAMO preparation timing is reported separately from receipt-based Office TAT. The preparation cohort starts at the first persisted draft start, completes on the first accepted PAMO handoff, and adds completed correction intervals from PAMO correction receipt to resubmission. Open drafts and correction cycles remain pending; existing receipt TAT fields are not populated from draft timing.

## Implementation areas

- Registration, admin approval, and mapped-office authorization: `RegisteredUserController`, user requests, `Admin/UserController`, `OrganizationalAccessService`, and `AppServiceProvider`.
- Draft workspace and promotion: `PamoPambDraftController`, `PamoPambDraft`, `PamoPambDraftWorkspace.jsx`, and the conservation report page.
- Captured graph and routing access: `DocumentRoutingAccessService`, `DocumentRoutingProfileRegistry`, `EffectiveRoutingGraphResolver`, `DocumentRoutingTransitionService`, and `RoutingPositionSnapshotService`.
- PAMO preparation analytics and presentation: `OfficeTatAnalyticsService` and the Office TAT pages.
- Schema and data compatibility: `2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php` adds draft/provenance fields and backfills explicit PAMO assignments from existing PA PAMO names where a mapping is absent. It adds no permission grants. Its `down()` intentionally fails closed because deleting captured provenance would lose routing history.
- Production assets: `npm run build` now builds to a temporary directory under `public/`, validates the Vite manifest and reachable generated imports, then promotes the output. A failed build or verification leaves the existing `public/build` intact.

## Verification

- Feature coverage: PAMO registration and approval, fake office rejection, same-office draft collaboration, private MOV access, foreign PA denial, incomplete-forward validation, exact-once promotion, APL and MHRWS routing, correction receive/edit/resubmit, separate preparation analytics, existing PAMO scope, receipt TAT, historical encoding, and the four route-position combinations. The consolidated run passed 55 tests with 3,909 assertions; one suite test was skipped.
- PHP feature tests ran with `APP_ENV=testing`, SQLite `:memory:`, blank `DB_URL`, and `phpunit.xml`. They did not migrate or write to the normal application database.
- JavaScript Office TAT checks passed: 12 tests with Node's in-process test isolation.
- PHP syntax checks passed for the changed controllers, middleware, requests, models, services, migration, and feature tests. `git diff --check` passed; Git reported only existing CRLF-to-LF normalization warnings in unrelated JSX files.
- Production build passed: 1,461 modules transformed; manifest verification covered 157 ESM assets and 90 generated relative imports; verified assets were promoted to `public/build`.
- No browser acceptance run or MySQL concurrency run was performed. SQLite verifies the feature flow and transaction behavior used by tests but does not establish production MySQL locking behavior.

## Installation and acceptance still required

1. Review the PA-to-PAMO and supervising-office mappings in the target environment, then apply the dated migration there. It was deliberately not run against the normal database during this implementation.
2. Confirm the target PAMO accounts are approved and active with the correct PA and office mapping. The migration grants no roles or permissions.
3. Complete a browser acceptance pass with an approved pilot account: save and reopen a partial draft, forward a complete report, receive it at the correct first destination, then exercise a correction return and resubmission. Confirm the Office TAT view reports draft preparation independently of source receipt TAT.
4. Validate the promoted assets and private upload storage in the deployment environment.

The working tree remains unstaged and uncommitted. Existing unrelated work and `.recovery/` were left in place.
