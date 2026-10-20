# CDS-SMART Office checkpoint handoff (2026-10-20)

## Scope

Checkpoint branch checkpoint/cds-smart-office-alerts-engp-2026-10-20, based on published preview checkpoint 4ee6560e3fa1cd236f6ef8360d80a1bd62588216. This is a reviewed WEB source checkpoint, not a deployment or live workflow acceptance.

The selected changes cover Office TAT source/cohort analytics and compliance assessment; Development/CBEP scoring and release-date projection; ENGP Receive Correction-before-MOV replacement and per-key scoring; Archives reader labels/layout; routing and document presentation; in-app alert wiring and authorization. Production HTTPS enforcement and separate Management Plans archive exclusion remain intact. Runtime catalog additions, QA reports, uploaded/private file bytes, and remote Drive archives are not supplied by source checkout.

## Behavior and limits

- Office TAT uses registered source/workflow coverage and receipt cohorts, including office-specific CENRO receipts. Query-growth coverage compares bounded 42- and 84-sample fixtures; it is not a production latency claim.
- ENGP keeps 12/12 isolated workflow coverage. The scorer handles each key independently, preserves the deadline-day rule, and the source-specific release date remains distinct from final archive verification. Receive Correction evidence must be acknowledged before replacing MOV evidence. Live CBEP is 1/12; the other 11 workflows remain deferred.
- Archives/Development history labels preserve source identity and distinguish dispatch, regional return, and final verified receipt. The reader reserves measured fixed-toolbar space only where it overlaps.
- Alerts preserve the validated Tracking tab and selected source row after a refreshed notification. Supported PAMO deadline readers are scoped to the active workflow and PA; foreign-area access is denied. A deadline-day ENGP report is not late solely because Days Complied is zero and the rating is Unsatisfactory.
- The weekday 08:00 Manila alert command is registered. Live UI acceptance and actual scheduler, queue, email, and provider delivery are unverified. Full-period performance coverage is incomplete.

## Selected source and tests

The commit contains 36 selected changed source/test paths plus this handoff. It includes the reviewed Office TAT, ENGP, Archives, alert/notification, routing/presentation, and associated PHP/Node regression files. The isolated candidate deliberately omits an unrelated CENRO reassignment test block from EngpReportTest.php; its ENGP correction/queue matrix remains selected. No private resume, operational report, DB dump, recovery secret, .env, OAuth/APP_KEY configuration, dependencies, runtime storage/config cache, generated build output, or browser artifact is included.

## Retained evidence

- ENGP scoring/correction/verified archive: 38 tests / 3,600 assertions. Live acceptance remains 1/12 CBEP; 11 workflows deferred.
- Compliance alerts: 179 / 4,082; notification/flash: 21 / 315; ENGP correction/queue: 12 / 804. These are separate retained batches and may overlap.
- Office TAT/CBEP focused PHP: 77 / 1,303. Affected frontend suites: 83 / 83.
- Current candidate Node regressions: 43 / 43 passed. Current PHP candidate direct invocation did not bootstrap the app (146 tests failed before assertions with Facade root has not been set); therefore it supplies no new PHP pass claim. Retained PHP results above were run in the separate guarded SQLite :memory: vendor-copy runtime recorded in the private resume. No operational MySQL tests, migrations, seeders, or restores were run for this checkpoint.
- A fresh production build/browser acceptance was not repeated for this source-only publication. Scheduler and external delivery remain unverified.

## Acceptance and recovery boundary

This commit does not merge into master, deploy, or restore a database. The separately encrypted private operational backup has its own private-repository branch and must be fetched separately. Database rows, runtime file storage, application configuration and keys, Drive-hosted archive bytes, and the eleven deferred workflows need separate HOME-side handling. Verify the private ciphertext receipt and carry its recovery secret separately before any independently scoped restore. Back up and verify HOME's current DB/files and confirm its target first; do not overwrite .env or replace the existing key.
