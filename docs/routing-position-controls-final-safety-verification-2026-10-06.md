# Routing Position Controls Final Safety Verification

- Date: 2026-10-06
- Project: `C:\laragon\www\cds-system`
- Branch: `main`
- HEAD: `d759510d89a816d525b9b1d156740ca08839212c`

## Result

MySQL concurrency safety passed after two narrow read-consistency corrections. The final affected MySQL suites passed **43 tests / 3,040 assertions**, including the outer-transaction duplicate-action race regression. The final bounded backend inventory passed **1,209 tests / 21,701 assertions** across **132 files in 14 batches**, with two expected skips for MySQL-only regressions under SQLite.

Full authenticated browser acceptance remains **unverified**. This session had no usable browser surface, so no browser login, clicks, or POSTs were performed here. An isolated synthetic runtime is available at `http://127.0.0.1:8018`; its login page returned HTTP 200. It uses a temporary SQLite database and storage, fake archive provider, disabled Google Drive integration, and synthetic users. The owner-observed Chief interaction is recorded below; a Settings page screenshot remains pending. Office activation was not performed and remains separate from this safety verification.

The owner supplied a screenshot of the synthetic CENRO CDS Chief after Receive. It shows **At CENRO CDS Chief**, **Received by CENRO CDS Chief** at October 6, 2026, 5:57:39 PM, no Receive action, and the Forward and Return for Correction actions. A read-only check of the isolated SQLite database confirms exactly one persisted receipt: two source events total (the original forward and one receipt), one version-1 snapshot, and the current stage `cenro_chief`. The report remains in the Chief's Incoming queue under its next `forward` action. This is one owner-observed interaction, not full routing or Settings UI acceptance.

## Worktree and change

The reviewed base remains `main` at `d759510d89a816d525b9b1d156740ca08839212c`. Existing local routing work and unrelated worktree edits were preserved. No staging, commit, or push occurred. `.env` was not changed; `.recovery/` was absent and remains absent.

The settings race exposed a repeatable-read defect in `RoutingPositionSnapshotService::resolve()`: after the route transaction waited for and locked the settings head, the ordinary lookup for its referenced immutable version could still use an older InnoDB consistent-read view. It then reported an invalid head even though the new head had committed.

That narrow correction reads the referenced version with `FOR UPDATE` whenever the route already holds the head lock. The lock order remains report row, settings head, then referenced version. A permanent MySQL-only regression creates the earlier consistent-read view, commits a new head/version through an independent connection, and verifies that locked resolution returns the complete new pair. Rejected first-action and archive rollback assertions were also added to the focused routing suite.

The follow-up V1 reproduction exposed a separate stale-read path in `DocumentRoutingTransitionService`: the controller's outer transaction performs an ordinary source lookup, then waits on the report row while the transition reads routing events and snapshots with ordinary consistent reads. The correction adds transition-only `FOR UPDATE` reads for authoritative events and the matching snapshot after the report lock. The public projection reads remain ordinary reads. No frontend source, route stages, percentages, authorization rules, correction behavior, MOV gates, archive hierarchy, or Drive integration changed.

## Next Expected Action presentation correction

The current cause was reproduced in `DocumentRoutingPresenter::presentCanonical()`: it picked the first current-stage action that was not internal. At CENRO CDS Chief the effective graph lists the optional `Return for Correction` before the ordinary `Forward to CENRO Records`, so that return was incorrectly shown as the informational Next Expected Action in both the side panel and Full Details. Both surfaces consume the same `routing.next_expected_action` field.

The presenter now prefers a non-correction action from the current resolved stage and effective graph, then falls back to the first current-stage action when no ordinary action exists. That fallback preserves `Receive Correction` while acknowledgement is pending. After acknowledgement the existing `Resubmit Corrected Copy` label remains. The action graph, executable action keys, authorization, owner, percentages, routing events, dates, snapshots, and queue rules are unchanged. Regressions cover the selected-record GET and its no-write behavior, global-observer read-only actions, all ten active generic sources, all four saved position combinations, direct profiles, PAMB MOV separation, correction acknowledgement/resubmission, and terminal release.

## Isolation and providers

The preceding final verification used disposable local schema `cds_routing_uat_20261006_7f31c9a2`; this follow-up used a separate disposable schema, `cds_routing_uat_20261006_a7c9214e`, on local MySQL 8.4.3, port 3306. Both were checked by exact database identity before database work and are now dropped. No office database rows were copied. The owner-ready browser runtime is separate: it uses `C:\Users\jayar\AppData\Local\Temp\cds-routing-browser-20261006-b3ee10\cds-routing-browser.sqlite`, isolated temporary storage and file sessions, and `APP_ENV=testing` on `127.0.0.1:8018`.

Feature migrations and the committed `PermissionSeeder` and `ModuleDefinitionSeeder` ran only against disposable test databases. The browser runtime is seeded with synthetic accounts and a synthetic BMS report. It selects `FakeDocumentArchiveGateway`, disables Google Drive integration, uses a synchronous queue, and makes no Drive calls or folder changes. Automated archive success and rejection paths passed with the fake provider.

The frontend build manifest was inspected without rebuilding: **151 entries**, **152 unique referenced assets**, **0 missing files**, and no `public/hot` file.

## MySQL concurrency results

The race harness used separate PHP worker processes and MySQL connections. It coordinated each waiter through `performance_schema.data_lock_waits`, with bounded waits; it did not use a shared fixture transaction or arbitrary delay as race proof.

| Scenario | Observed coordination | Result |
| --- | --- | --- |
| Route locks first | Settings writer waited on `routing_position_settings`. | Starting at version 12 (Office enabled, TSD disabled), the first route action captured version 12 in its snapshot and event. The new head then committed version 13 (Office disabled, TSD enabled). The event next owner remained CENRO CDS Chief; at PENRO Records the captured graph still exposed Office dispatch and omitted TSD. A fresh PHP application process resolved the same graph. |
| Settings save locks first | First route action waited on `routing_position_settings`. | Starting at version 14 (Office enabled, TSD disabled), the save committed version 15 (Office disabled, TSD enabled). The first event and snapshot both captured version 15; the PENRO Records graph omitted Office and exposed the TSD dispatch. A fresh PHP application process resolved the same graph. |
| Earlier duplicate first action harness | Second transition waited on the same `bms_report_submissions` row. | The earlier worker reported a stale rejection, but its worker path was not preserved. It is not treated as evidence for the controller's outer transaction; V1 below covers that path directly. |
| Concurrent settings saves | Second writer waited on `routing_position_settings` using the same expected version. | One writer created version 16 and one audit row. The other received the existing `expected_version` stale-save validation. No extra version or audit row appeared. |

The final race run had no deadlock, lock timeout, mixed flag pair, duplicate custody event, or divergent fresh-process readback. The new repeatable-read regression passed **1 test / 7 assertions** on MySQL and is included in the full routing result above.

## V1 outer-transaction duplicate-action follow-up

The controller's `transitionWithAttachment()` starts an outer transaction. `SubmissionTrackingService::transition()` performs the ordinary BMS source-row lookup within it; after the report-row lock, the generic transition previously read events and the snapshot with ordinary reads. The new independent-worker test reproduces this ordering, confirms `REPEATABLE-READ` and an active outer transaction, records the earlier event/snapshot view, and observes the second worker waiting on `performance_schema`'s BMS row lock. It exercises both a route already captured and the first action on an uncaptured report. No server-wide isolation setting was changed.

| Scenario and point | Events | Snapshots | Dates and source document | Attachments / archives | Notifications | Queue |
| --- | --- | --- | --- | --- | --- | --- |
| Pre-fix captured route, before winner | 1 (`forward_to_cenro_chief`) | 1, version 1 | `date_accomplished=2026-08-03`; synthetic BMS report, no official file | 0 / 0 | 1 | 0 jobs / 0 failed |
| Pre-fix captured route, after winner | 2, including one `receive_at_cenro_chief` | 1 | No date or document change | 0 / 0 | 2 | 0 / 0 |
| Pre-fix captured route, after duplicate | **3**, including a second `receive_at_cenro_chief` | 1 | No date or document change | 0 / 0 | **3** | 0 / 0 |
| Pre-fix first action, before winner | 0 | 0 | `date_accomplished=2026-08-03`; synthetic BMS report, no official file | 0 / 0 | 0 | 0 / 0 |
| Pre-fix first action, after winner and failed duplicate | 1 (`forward_to_cenro_chief`) | 1 | No date or document change | 0 / 0 | 1 | 0 / 0 |
| Post-fix, both scenarios after winner and duplicate attempt | Captured route: 2; first action: 1; no extra event | Captured route: 1; first action: 1; no second snapshot | Unchanged after the winner; duplicate attempt adds no date or document change | No change | No change after the winner | No change |

Before the fix, the captured-route duplicate committed a second receipt and a second receipt notification instead of rejecting the stale action. On the first action, the duplicate failed with `UniqueConstraintViolationException` on the unique `(source_key, source_id)` snapshot, rather than the normal stale-action validation. After the fix, both duplicates return `This document is no longer awaiting that routing action.` and commit no additional event, snapshot, milestone, attachment, archive, notification, or queued job. Permanent coverage lives in `tests/Feature/DocumentRoutingRepeatableReadRaceTest.php` with its independent worker in `tests/Support/routing_transition_race_worker.php`.

## V2 SQLite inventory reconciliation

The final batch logs were checked against the current test-file revision. The earlier R2 baseline was **131 files / 1,209 passed / 21,677 assertions**. It is historical and is superseded by the current result: **132 files, 1,211 tests, 1,209 passed, 2 expected MySQL-only skips, 0 failed, and 21,701 assertions**. The 24-assertion difference reflects later test revisions. The two skipped tests are the settings-version locking regression and the new outer-transaction race regression, both of which require MySQL and passed there. Batch logs and their pre-run SQLite identity checks were kept under the task temp directory during verification.

| Sequential batch | Test files | Tests | Passed | Skipped | Assertions |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 1–10 | 93 | 93 | 0 | 1,050 |
| 2 | 11–20 | 37 | 37 | 0 | 251 |
| 3 | 21–30 | 57 | 57 | 0 | 715 |
| 4 | 31–40 | 314 | 314 | 0 | 2,295 |
| 5 | 41–50 | 90 | 89 | 1 | 935 |
| 6 | 51–60 | 76 | 76 | 0 | 755 |
| 7 | 61–70 | 57 | 57 | 0 | 708 |
| 8 | 71–80 | 104 | 104 | 0 | 1,597 |
| 9 | 81–90 | 81 | 81 | 0 | 927 |
| 10 | 91–100 | 79 | 78 | 1 | 4,509 |
| 11 | 101–110 | 101 | 101 | 0 | 1,565 |
| 12 | 111–120 | 74 | 74 | 0 | 3,586 |
| 13 | 121–130 | 41 | 41 | 0 | 2,384 |
| 14 | 131–132 | 7 | 7 | 0 | 424 |
| **Total** | **132** | **1,211** | **1,209** | **2** | **21,701** |

## Rejected actions and archive lifecycle

The final MySQL routing suite verified that a wrong actor category, wrong CENRO office, stale first-stage action, and an unmet regular PAMB MOV review gate leave the first route without a snapshot or event. It also verified a failed first PENRO Records archive checkpoint on an eventless report: the attempted event and newly captured snapshot rolled back, the existing milestone and document remained, and no handoff notification was created.

The same suite exercised fake archive rejection after a route snapshot had already committed across the Office, TSD, and CDS Focal dispatch branches. Each rejection left the earlier snapshot intact and did not advance custody or notify the destination. Fake archive success also passed in the four BMS branches and in Regular PAMB, Special PAMB, and TWC automated HTTP coverage. These results verify the local archive lifecycle only; they do not verify live Drive or OAuth.

## Automated checks

| Check | Result |
| --- | --- |
| Affected MySQL suites: routing position controls, transition, override, and V1 race | **PASS** — 43 tests / 3,040 assertions |
| New MySQL repeatable-read race regression | **PASS** — 1 test / 57 assertions; covers captured-route and first-action duplicates with the outer transaction |
| Bounded backend inventory | **PASS** — 132 files, 14 sequential batches; 1,209 passed, 2 MySQL-only tests skipped under SQLite, 21,701 assertions; every batch exited 0 |
| Follow-up affected presentation, routing, queue, correction, MOV, authorization, and settings suites | **PASS** — 102 tests, 101 passed, 1 expected MySQL-only skip, 4,197 assertions |
| New selected-record GET and all-active-source label regressions | **PASS** — 1 test / 25 assertions; 1 test / 80 assertions; both are included in the affected-suite total |
| Settings panel/page rendered and interaction checks | **PASS** — 4 Node SSR tests; this is not browser evidence |
| PHP syntax and `git diff --check` after the label correction | **PASS** |
| PHP syntax for changed services and race regression/worker | **PASS** — `php -l` |
| `git diff --check` | **PASS** |
| Production frontend build | Not rerun: no frontend source changed in this label correction. The previously inspected manifest has 151 entries, 152 unique referenced assets, and 0 missing files. |

The SQLite inventory used `:memory:`, a blank `DB_URL`, temp storage, and the fake archive gateway. Its two skips are intentional: both locking regressions require MySQL and passed on MySQL separately.

An earlier partial inventory attempt had the archive gate disabled and therefore stopped on archive-checkpoint expectations. That run was halted; the complete 14-batch inventory above was rerun from the beginning with the checkpoint enabled and the fake gateway bound. The reported counts are from that complete passing run.

## Browser acceptance matrix

No agent-driven browser result is claimed. The in-app browser reported `Browser is not available: iab`; separate Chrome and Edge contexts reported unavailable as well. The Windows Computer Use helper could not connect its native pipe (`The system cannot find the file specified`); those failed browser launches were not repeated. The isolated app server is listening only on `127.0.0.1:8018`; its login page returned HTTP 200. The earlier Chief fixture is BMS ID 1 at `cenro_chief`, with two events (one `received` event), one version-1 snapshot, and remains in Incoming under `forward`. The server selects the fake archive gateway, has blank Drive credentials, keeps the archive checkpoint gate enabled, and made no live Drive calls.

The isolated database has synthetic CDS Admin, CENRO Focal, CENRO CDS Chief, and CENRO Records view-only accounts. The earlier read-only check saw the initial version-1 settings with both positions enabled; the owner later reported saving the positions off through the Settings page. The current version-4 flags were independently checked for the disabled-position preparation below. Read-only checks confirmed the Admin account has both `submission-tracking.routing-settings.view` and `.update`; the Chief has `bms.update`; and the view-only account is denied `bms.update`. Passwords are kept out of this report.

| Owner scenario | Steps and expected result | Status |
| --- | --- | --- |
| Synthetic CENRO CDS Chief, synthetic BMS report | The owner screenshot shows **At CENRO CDS Chief**, one visible receipt at October 6, 2026, 5:57:39 PM, no Receive action, and Forward / Return for Correction available. The isolated database independently confirms one persisted receipt. The report correctly remains in Incoming under `forward`; it need not leave the full Incoming workspace immediately after Receive. | **OWNER OBSERVED** — this one interaction only. |
| CDS Admin, Routing Workflow Settings | The owner reports the page rendered in light and dark mode; initial v1 had both positions enabled and the full route preview; turning both off previewed PENRO Records → CDS Focal; Save showed Success/v2, a later save reported v4, and v4 persisted after refresh. | **OWNER OBSERVED** — Settings scenario only; current v4/off/off separately verified by HTTP and SQLite. |

## Routing Workflow Settings UI review

The Settings route, page, panel, and existing Settings shell were reviewed in source. The page uses the shared navigation, labelled checkboxes, responsive two-column toggle layout, dark-mode color classes, visible focus rings, version/last-saved context, field-level error roles, and a disabled Save button while unauthorized, unavailable, or processing. The authenticated layout provides the shared success dialog; on save, the page refreshes form defaults and the acknowledged version from the returned settings props. The existing Node rendered/interactions suite passed **4/4**, covering permission display, four path previews, successive saves, validation recovery, and submitting-state disabling. SSR checks do not count as browser acceptance.

The owner has reported light/dark rendering, the disabled-position preview, successful saves, and persistence after refresh. A separate read-only authenticated HTTP request returned the Settings page at version 4 with both positions disabled. Desktop and narrow-window spacing, contrast, keyboard traversal, focus appearance, and rendered error feedback remain unverified. No Settings UI code was changed.

| Actor / source / profile | Settings | Expected path | Browser outcome |
| --- | --- | --- | --- |
| CDS Admin, Super Admin, view-only, and role-only observers | Settings authorization | Named abilities permit the two administrators, view-only cannot save, and global role alone is denied. | **UNVERIFIED** — no approved browser context. Automated settings-route tests passed; no browser form submission was made. |
| Synthetic CDS Admin, Routing Workflow page | Settings UI | Render in light/dark mode, inspect labels and preview, turn both positions off, save, and confirm the saved version after refresh. | **OWNER OBSERVED** — the owner reports these interactions; HTTP returned current v4 with both positions disabled. |
| CENRO Focal, CENRO Chief, CENRO Records, PENRO Records, Office, TSD Chief, PENRO CDS Focal, PENRO CDS Chief; regular BMS | Office on / TSD on | Records explicitly dispatches to Office; Office Receive remains separate from its TSD handoff; TSD Receive remains separate from forwarding. | **UNVERIFIED** — automated HTTP routing tests passed; no browser clicks. |
| Same routing actors; regular BMS | Office off / TSD on | PENRO Records dispatches to TSD; TSD Receive and forward remain separate. | **UNVERIFIED** — MySQL concurrency readback verified the captured graph; no browser clicks. |
| Same routing actors; regular BMS | Office on / TSD off | PENRO Records dispatches to Office; Office receives and then explicitly dispatches to CDS Focal. | **UNVERIFIED** — automated branch and archive tests passed; no browser clicks. |
| Synthetic CENRO/PENRO custody actors; regular BMS ID 2 | Office off / TSD off | PENRO Records dispatches directly to CDS Focal; focal Receive and Forward, Chief Receive and Recommend, final PENRO Records Receive, and Regional release remain separate steps. | **COMPLETED THROUGH REGIONAL RELEASE** -- the owner completed the isolated browser route. Read-only verification confirms 13 custody events, exactly one release_to_regional (event 15), terminal stage released_to_regional, and History membership. Snapshot 2/version 4 remains regular with Office and TSD disabled; the verified fake archive entry for this BMS MOV document is unchanged. |
| CENRO/PENRO actors, appropriate PAMO/direct-origin actors; Regular PAMB, Special PAMB, TWC, direct-to-PENRO | All enabled and both disabled, with MOV gates and correction cycles | Captured route, explicit receipts, required predecessor actions, MOV review/release, correction receipt/resubmission, and archive checkpoint remain intact. | **UNVERIFIED** — automated HTTP coverage passed for its listed cases; no browser clicks. No complete browser cross-product is claimed. |
| Position actors and observers across the ten active sources | Captured and preview profiles | Queues, selected details, filters, pagination, histories, skipped positions, permissions, and read-only GET behavior match the captured route. | **UNVERIFIED** — automated backend coverage passed; no browser list/detail selection. |

Active sources retained in the automated source checks: `conservation`, `engp`, `bms`, `bams`, `imea`, `imea-maintenance`, `aws`, `ipaf-management`, `revenue`, and `management-plans`. Retired Technical Reports were excluded.

## Disabled-position BMS browser preparation

**Owner-observed Settings evidence:** The owner reports that the Settings page rendered in light and dark mode. It initially showed version 1, both positions enabled, and the full route preview. Turning both positions off previewed `PENRO Records → CDS Focal`. Save showed Success and version 2; the owner subsequently saved again and reported version 4, which remained after refresh. These observations cover that Settings interaction only; they do not establish all four settings combinations or office readiness.

**Independent runtime and settings checks:** Before the fixture database was queried, the portal runtime check confirmed `APP_ENV=testing`, the exact task-local SQLite database, empty `DB_URL`, isolated uncached configuration, temporary storage and file sessions, array mail, sync queue, and `FakeDocumentArchiveGateway`. The initial PENRO Records checkpoint gate remains enabled, while Drive credentials are empty; no live Drive request was made. The server remains on loopback port 8018. Login returned HTTP 200, and an authenticated read-only GET of `/settings/routing-workflow` returned the Settings component with version 4, Office of the PENRO disabled, and PENRO TSD Chief disabled. The independently read SQLite settings head also reports version 4 and both flags false.

**Synthetic report and custody preparation:** The report is `Synthetic disabled-position routing check — BMS`, source BMS, ID 2, attached to the synthetic CENRO-supervised Protected Landscape assigned to CENRO Mati. ID 2 is above the stored BMS cutover watermark of 0. Its 622-byte synthetic PDF is in the temporary runtime storage. Before routing, the record had no custody events or snapshot. Five dedicated active, approved, verified synthetic accounts used their own CENRO/PENRO categories, matching office scopes, BMS abilities, and protected-area access. Through authenticated HTTP requests to the application's normal transition route, they completed the six required predecessors in order: CENRO Focal forwarded to CENRO Chief; the Chief received and forwarded to CENRO Records; CENRO Records received and forwarded to PENRO Records; PENRO Records received.

The isolated database now contains exactly those six canonical events and one report-bound snapshot. Every event and the snapshot resolve to captured settings version 4, both positions disabled, and the regular profile. The current stage and custody holder are PENRO Records. The earlier BMS ID 1 Chief fixture remains at `cenro_chief` with its two events and version-1 snapshot unchanged.

**Selected workspace check before dispatch:** Authenticated as the synthetic PENRO Records account, the isolated portal returned HTTP 200 for `/submission-tracking?source=bms&source_id=2`. The page selected BMS ID 2 by source and ID, showed it in Incoming at `penro_records`, and exposed `dispatch_penro_records_to_cds_focal` in the selected record's action list. No Office of the PENRO or TSD dispatch was offered. The timeline's Office/TSD skipped-position rows have no event IDs; they are presentation rows, not fabricated custody events.

**Owner dispatch readback (read-only, before Receive):** At that readback, the isolated SQLite database had seven custody events. Event 9 was the only `dispatch_penro_records_to_cds_focal` event; it was a forwarded transition from `penro_records` to `transit_to_cds_focal`, recorded by PENRO Records and bound to snapshot 2/version 4. The single source-bound snapshot remained unchanged: regular profile, version 4, both positions disabled. At that point the report awaited CDS Focal receipt.

The archive entry for BMS ID 2's MOV document (source_type=bms, source_id=2, logical_slot=mov) reports `ARCHIVED`, `remote_availability=verified`, and the fake provider ID `fake-archive-1`. Its archived size is 622 bytes and its SHA-256 matches the current synthetic PDF. The runtime still selects `FakeDocumentArchiveGateway` with the checkpoint gate enabled; no live Drive call was made. This confirms the fake archive checkpoint succeeded for the dispatch.

**Owner Receive readback (owner report and independent database check):** The owner reports that clicking Receive succeeded in the browser and that the sidebar and Full Details showed matching routing information. The isolated SQLite database independently confirms eight total custody events and exactly one `receive_at_cds_focal` receipt: event 10, from `transit_to_cds_focal` to the canonical stored stage `penro_cds_focal` (CDS Focal), recorded by the synthetic PENRO CDS Focal. This is the current stage. No Forward to CDS Chief event is recorded.

The single snapshot remains snapshot 2, version 4, regular profile, with Office of the PENRO and PENRO TSD Chief disabled. The archive entry for BMS ID 2's MOV document (logical_slot=mov) is unchanged from the dispatch readback: the same `fake-archive-1` identifier, `ARCHIVED` status, verified availability, size, SHA-256, and verification timestamps; the archived file still matches the synthetic PDF.

**Owner Chief Forward readback (read-only):** After the owner reported Forward, the isolated database contains exactly nine custody events for BMS ID 2. Event 11 is the only forward_to_cds_chief event, from penro_cds_focal to transit_to_cds_chief; there is no receive_at_cds_chief event. The current stage is transit_to_cds_chief.

Snapshot 2 remains the sole source-bound snapshot: regular profile, settings version 4, with Office of the PENRO and PENRO TSD Chief disabled. The archive entry for BMS ID 2's MOV document (logical_slot=mov) is unchanged from the successful fake checkpoint: provider fake-archive-1, status ARCHIVED, verified availability, 622-byte size, and the same SHA-256 matching the synthetic PDF. No archive transition or provider call was made for this readback.

The isolated PENRO CDS Chief account was absent, so one synthetic account was created in the disposable SQLite database only: user ID 10, routing-disabled-penro-chief@example.test, active/approved/verified, roleless, categorized as PENRO_CDS_CHIEF, scoped to PENRO Davao Oriental, and assigned existing bms.update, reports.view, and submission-tracking.view permissions. Its test-only password is kept in the temporary credential file and omitted from this report. An authenticated login succeeded; a read-only selected-workspace GET returned HTTP 200, selected BMS ID 2 at transit_to_cds_chief, and exposed the pending Receive action. No Receive or Recommend action was submitted. The owner should sign in with this account and perform the single pending Chief Receive to continue the browser check.
At this earlier readback, disabled timeline-row UI cleanup remained deferred; it is completed in the final closeout below. This covers one regular BMS route scenario only. It is not full browser coverage of sources, direct routes, settings combinations, or every display size. The application source, project .env, permission definitions, and routing settings were not changed; only the explicitly authorized synthetic account and its existing permission assignments were added to the isolated SQLite database, and no migrations or test suites were run. Temporary resources are retained under `C:\Users\jayar\AppData\Local\Temp\cds-routing-browser-20261006-b3ee10`: the SQLite database, `portal.env`, loopback router/server logs, synthetic credentials, helper scripts, temporary storage, and the synthetic PDF. The runtime was retained for the owner check and later stopped and removed as recorded in the final portal cleanup below.

## Safety note and cleanup

One early, failed isolation-probe attempt inherited the project `.env` and issued only the read-only identity query `SELECT DATABASE(), @@port`, which identified `cds_system`. It did not read application rows, run a migration/seeder, or write data. All later database-affecting commands, including test database cleanup, used the verified disposable schemas or isolated SQLite runtime described above. No project credentials were printed or changed.

The two disposable MySQL schemas and the concurrency-test temp files were removed after verification. The temporary isolation probe file was removed. At the earlier browser-check closeout, the isolated SQLite database, synthetic credentials, temporary storage, fake PDF, and loopback server on port 8018 were retained for the owner check; the later cleanup is recorded below. No operational account, setting, report, document, project credential, Drive object, Apache service, or office runtime was changed.

## Final route status (supersedes earlier next step)

The earlier instruction to sign in as the synthetic PENRO CDS Chief and click Receive applied at the Chief-forward readback. It is superseded by the completed browser route: the owner completed Chief Receive, Recommend, final PENRO Records Receive, and Regional release. The read-only closeout confirms BMS ID 2 is terminal at released_to_regional, recorded in History, and has exactly one release event. No further routing action is pending. The timeline-row UI cleanup is complete, and the isolated portal was subsequently stopped and its disposable runtime removed as recorded below.

## Final disabled-position routing closeout

**Read-only release and History verification:** In the isolated testing SQLite database, BMS ID 2 has 13 custody events and exactly one `release_to_regional` action: event 15, from `penro_records_final` to `released_to_regional`. The report has `date_endorsed_regional=2026-10-07`. Query-only reads through the application workspace service as both synthetic PENRO Records (ID 8) and synthetic PENRO CDS Chief (ID 10) place BMS ID 2 in History with `routing_complete=true` and exclude it from Incoming. No mismatch was found.

**Snapshot and archive:** Snapshot 2 remains the regular profile at settings version 4, with Office of the PENRO and PENRO TSD Chief disabled. The sole archive entry for BMS ID 2's MOV document (logical_slot=mov) remains `fake-archive-1`, `ARCHIVED`, and verified. Its 622-byte size and SHA-256 (`e5e7ed14a56dce0e0328f2f0e6c303b1ec273f188515ff3c358fea5e1dc4a556`) still match the synthetic PDF. No archive, settings, or routing data was changed for this review.

**Timeline presentation correction:** The Submission Tracking sidebar, Full Details, and Full Timeline now filter only synthetic skipped-position placeholders using each report's captured route-position flags. Filtering happens before the sidebar progress window and before full-timeline expansion/count calculation. Actual historical events remain visible. The routing-version explanation remains in Full Details, and all-enabled captured routes, including older version-1 snapshots, retain their Office and TSD stages. Routing actions, permissions, progress calculations, snapshots, archive rules, and Drive hierarchy were not changed.

**Verification:** The affected rendered frontend suites passed 29 tests with zero failures, including all four Office/TSD flag combinations, an older all-enabled snapshot, and preservation of actual Office/TSD historical events. `npm.cmd run build` succeeded. The 151-entry generated manifest references 152 unique assets; all are present. `/login` and the isolated runtime check both returned HTTP 200. Port 8018 was confirmed to serve the isolated testing SQLite database through `FakeDocumentArchiveGateway` before shutdown. The portal and its disposable runtime were then stopped and removed as recorded below.
**Portal cleanup (2026-10-07):** Port 8018 was confirmed as the isolated portal: `APP_ENV=testing`, SQLite at `C:\Users\jayar\AppData\Local\Temp\cds-routing-browser-20261006-b3ee10\cds-routing-browser.sqlite`, `FakeDocumentArchiveGateway`, checkpoint gate enabled, and empty Google Drive credentials. Two PHP listeners were present. After stopping the first, the remaining listener returned the same isolated runtime identity; it too was stopped. Port 8018 had no listener and the runtime check was unreachable before cleanup. Removed only `C:\Users\jayar\AppData\Local\Temp\cds-routing-browser-20261006-b3ee10`; the directory is absent. No runtime identity mismatch occurred.

## Office routing-controls activation preflight (2026-10-07)

**Target identity:** The project is on `CDS-SERVER`, branch `main`, at `c1e5e6af6f75f8bc232147542559f9bf9ea9303a`, equal to `origin/main` and the reviewed feature commit. The office Apache vhost `cds-system.test` serves this project (`Apache/2.4.66`, web PHP 8.3.30); the read-only application database identity is `cds_system` on `CDS-SERVER:3306` (MySQL 8.4.3). Laravel reports `APP_ENV=local`; its existing runtime configuration was preserved. `.env` was not read into output or changed. The seven pre-existing unrelated tracked edits remain untouched; no staged or untracked files were present before this report update.

**Schema and pre-change aggregates:** `php artisan migrate:status` showed exactly the two reviewed migrations pending: `2026_10_06_000001_create_routing_position_settings` and `2026_10_06_000002_create_submission_routing_snapshots`. All four resulting routing tables were absent; no partial schema was found. The ten active source tables were present (retired Technical Reports excluded):

| Source table | Rows | Maximum ID |
| --- | ---: | ---: |
| `conservation_report_submissions` | 4 | 103 |
| `engp_report_submissions` | 1 | 70 |
| `bms_report_submissions` | 0 | 0 |
| `bams_report_submissions` | 0 | 0 |
| `imea_report_submissions` | 0 | 0 |
| `imea_facility_maintenance_reports` | 0 | 0 |
| `aws` | 0 | 0 |
| `ipaf_management_reports` | 0 | 0 |
| `ipaf_revenue_collections` | 0 | 0 |
| `management_plans` | 0 | 0 |

The current `document_routing_events` aggregate is 32 rows, maximum ID 366. No report rows or credentials were printed. Because the routing tables are absent, no version, settings head, cutover watermarks, or captured snapshots are established in the operational database.

**Permission delta:** A read-only comparison against `PermissionSeeder` found each of the eight operational role profiles already exactly matches its target. CDS Admin and Super Admin each have 70 of 72 target permissions; the only additions are `submission-tracking.routing-settings.view` and `.update`. All seven legacy permission names that the seeder removes are absent. The seeder was not run, so no permission or role data changed.

**Drive preflight:** Effective CLI configuration is uncached, selects `google-drive`, and reports Drive enabled with client ID, secret, refresh token, and root configured; values were kept private. A fresh OAuth refresh returned HTTP 400 `invalid_grant`. No access token was obtained, so configured root and destination-folder metadata could not be checked. The failed request made no Drive folder or document changes. The sandbox first blocked the outbound request; the authorized elevated local retry reached Google and returned the same sanitized provider failure.

**Backup, maintenance, and stop:** The latest existing dump found is `the prior shared-directory dump (local path withheld)` (1,649,984 bytes); it predates this activation and was not treated as a current verified backup. No office backup/restore procedure was found in the project instructions or deployment scripts. No fresh backup or disposable restore test was made. The shared Apache instance could not be confirmed idle or inside a maintenance window, and process inspection was denied; therefore no routing-write gate or worker drain was established. These gates, together with `invalid_grant`, failed the activation preconditions.

No migration, seeder, build replacement, maintenance-mode change, routing setting save, report creation, routing action, or Drive mutation was performed. Office and TSD were not disabled. The prior generated manifest still has 151 entries and 152 unique referenced assets, all present, but the production build was not rerun because the office web server remained live without a confirmed maintenance window. The activation is **PARTIAL / NOT ACTIVATED**; existing reports, events, permissions, settings, Drive objects, and application source remain unchanged. Resume only after the office owner restores Google authorization and schedules a verified backup/restore plus a routing-write maintenance window.

## Office database backup and restore verification (2026-10-07)

**Database and engine check:** Immediately before backup, the effective database connection resolved to `cds_system` on `CDS-SERVER:3306`, MySQL 8.4.3, `utf8mb4` / `utf8mb4_0900_ai_ci`. All 72 base tables use InnoDB; there are no views, two triggers, no routines, and no events. This permits a consistent `mysqldump --single-transaction` snapshot without table-write locks. The read-only pre-backup inventory contained 638 rows across the 72 tables. The source count and schema fingerprints remained unchanged across the dump.

**Protected destination:** The existing shared MySQL backup directory grants `Authenticated Users` Modify and was not used. The dump and manifest are in a separate restricted location outside the web root. Its directory has inheritance disabled and grants Full Control only to the current user, SYSTEM, and local Administrators; both files inherit those same entries. No temporary MySQL option file remains. BitLocker status could not be queried without administrative rights, so volume encryption is unverified; this backup relies on its restricted NTFS ACL and is not separately encrypted.

**Backup artifact:** A 294,613-byte office dump is retained offline; its filename and SHA-256 are recorded only in the protected manifest. That manifest records sanitized database identity, fingerprints, restore verification, and cleanup result. The dump used the MySQL 8.4.3 client with `--single-transaction`, `--quick`, `--routines`, `--triggers`, `--events`, `--hex-blob`, `--no-tablespaces`, and `--set-gtid-purged=OFF`; it omits a source `CREATE DATABASE` / `USE` directive so it can restore into an explicitly selected schema. Credentials came from the existing Laravel database configuration into a short-lived option file inside the protected folder; they were not printed and that file was deleted after use.

**Tested recovery procedure:** Create a uniquely named empty disposable schema with the source charset/collation, then pipe the verified SQL dump through the MySQL 8.4.3 client with that schema explicitly selected. Compare base-table count, engine inventory, schema fingerprint (including columns, indexes, constraints, views, triggers, routines, and events), and exact per-table row-count fingerprint. The test restored to `cds_restore_verify_20261007_062937_b322de72`: all 72 tables were InnoDB; all 638 aggregate rows matched; the schema fingerprint matched; and both triggers were present. Source inventory before and after the dump also matched (row-count fingerprint `019c69f67ff095b41244496d0ef02b7d31fa2e341587373c69eef0023e253de6`; schema fingerprint `f2fde123998c66169b03ea24953aea407749fc53e64337a16e973dc17fb6ec67`). The disposable schema was dropped after verification and independently confirmed absent. The operational database was only read; no report rows or contents were printed.

For an actual recovery, first identify and authorize the exact destination database and maintenance window, verify the backup SHA-256, create or select the approved recovery target, and restore with the same restricted transient option-file method while explicitly selecting that target. Validate the target identity, schema, engines, and aggregate counts before reopening service. Only the disposable restore path was exercised here; no operational restore was performed. No routing migrations, seeders, settings saves, custody actions, or Apache restarts occurred.
## Fresh office Google Drive metadata verification (2026-10-07)

Using the current uncached Laravel configuration from the updated environment, a direct fresh OAuth token exchange passed. Read-only GET metadata checks for the configured archive root and one existing verified archive destination both passed. The check did not use the application token cache, expose or log credentials/tokens or folder IDs, or modify Drive, application data, or settings. No upload, folder creation, migration, seeder, routing action, or Apache restart occurred.

## Routing-position activation preflight and controlled cutover (2026-10-07)

The owner confirmed the active maintenance window in this session. The fresh OAuth exchange and read-only archive-root and existing-destination metadata checks are recorded as PASS above.

### Write pause, drain, and serving-code gate

The shared Laravel application was placed in file-based maintenance mode with `php artisan down`. Login, routing-workflow, and the cds-smart alias returned HTTP 503. Apache connections reached zero non-listener connections in three consecutive two-second samples. The inspected CDS-SMART vhosts all point to this checkout's public directory. The serving stack is one Apache mod_php process group (two httpd processes); no php-cgi or php-fpm process was present.

Branch main was at HEAD c1e5e6af6f75f8bc232147542559f9bf9ea9303a, with no local changes under app, routes, database, config, or resources. Apache's active module configuration points to the PHP 8.3.30 installation whose php.ini has opcache enabled, timestamp validation enabled, and revalidation frequency zero. That ini predates the current Apache process start. PHP therefore checks source timestamps on each request against the shared checkout. The optional X-CDS-Perf-Runtime header was not returned for the unauthenticated diagnostic request; runtime confirmation used the active Apache module configuration, process identity, ini settings, and common document root. Apache was not restarted.

### Fresh protected backup and tested restore

- Source identity: CDS-SERVER:3306, database cds_system, MySQL 8.4.3, utf8mb4 / utf8mb4_0900_ai_ci.
- Backup: verified and retained offline; its filename and local path are withheld.
- Manifest: the offline activation manifest (local path withheld)
- Backup size: 294,590 bytes. SHA-256: 6a5f9cc24305f9e7827e9a526030b03d55deb481cda5d1e8f9e96ad3775ca1a8.
- The dump used a single transaction. Source schema and exact row-count fingerprints did not change across the dump. The protected directory has inheritance disabled and grants Full Control to the current user, SYSTEM, and Administrators. The transient credential option file was removed. Volume encryption could not be verified.
- Restore to a unique disposable schema matched all 72 InnoDB tables, schema fingerprint, triggers, and exact per-table row counts (638 aggregate rows). The disposable schemas were removed and verified absent. No report contents or credentials were recorded.

Recovery procedure: verify the manifest SHA-256; create a uniquely named empty disposable schema with the source charset and collation; restore the SQL dump using a short-lived option file in the protected directory; compare table engines, schema fingerprint, and exact row-count fingerprint; then drop only the disposable schema. This restore procedure was exercised successfully.

### Migrations, permissions, and post-checks

After all gates passed, only these two pending additive migrations were run by explicit path: 2026_10_06_000001_create_routing_position_settings and 2026_10_06_000002_create_submission_routing_snapshots. The approved Database\Seeders\PermissionSeeder then completed. No other migration or seeder was run.

Verification passed: version 1 is current, with Office and TSD enabled; both routing-settings permissions exist and are assigned to CDS Admin and Super Admin. All ten cutover watermarks match their active source/table mappings and cover the current maximum IDs:

| Source | Table | max_id |
| --- | --- | ---: |
| aws | aws | 0 |
| bams | bams_report_submissions | 0 |
| bms | bms_report_submissions | 0 |
| conservation | conservation_report_submissions | 103 |
| engp | engp_report_submissions | 70 |
| imea | imea_report_submissions | 0 |
| imea-maintenance | imea_facility_maintenance_reports | 0 |
| ipaf-management | ipaf_management_reports | 0 |
| management-plans | management_plans | 0 |
| revenue | ipaf_revenue_collections | 0 |

The snapshot table is empty; existing source IDs remain covered by the all-enabled baseline and no live report was routed. No routing setting was saved, Office and TSD were not disabled, and no custody action was executed. The application was returned to live mode after verification; login returned HTTP 200 and the unauthenticated routing-workflow request redirected to login.

The seven pre-existing local edits, .env, and .recovery were preserved. No application source was edited, and nothing was staged, committed, or pushed.

## Controlled archive probe and pending position cutover (2026-10-07)

**Synthetic archive write:** The configured Google Drive integration passed a forced fresh OAuth exchange. The configured root and an existing destination folder were verified read-only. A small non-sensitive synthetic text probe was uploaded directly into that already-existing destination using the existing archive provider; no folders were created. Drive metadata matched the probe's name, MIME type, size, parent, and synthetic marker. A readback matched the local bytes and SHA-256, and the provider reported the object verified. The exact returned object was deleted and a follow-up metadata read confirmed it was gone. All archive checks passed; no report document, hierarchy, or operational record was changed.

**Pre-save routing baseline:** The current settings remain version 1 with Office of the PENRO and PENRO TSD Chief enabled. All ten cutover watermarks remain present and valid. Each of the five existing source reports resolves to the all-enabled version-1 route, and there are no captured snapshots.

**Settings update status:** No settings save was submitted. The computer-control inventory exposed no browser session, and both the in-app browser and Chrome targets were unavailable, so an authenticated Admin settings session could not be reached. The current settings version is still 1; neither flag was changed. No route preview under a saved disabled-position version was available to verify. No live custody action was performed. Application code, `.env`, `.recovery`, the seven pre-existing edits, Drive hierarchy, and verified backup remain untouched.

## Report Routing editor-conflict and trial-data cleanup closeout (2026-10-07)

### On-disk review

The current worktree is on `main` at `c1e5e6af6f75f8bc232147542559f9bf9ea9303a`. `routes/web.php`, `.env.example`, and `tests/Feature/SubmissionTrackingDateInputTest.php` have no worktree diff from HEAD. The Report Routing label, settings panel, registration filtering/validation, and their tests are present in the on-disk diffs. No conflict markers were found in modified or untracked files. No missing on-disk edit was identified to reconstruct; unsaved editor-buffer contents cannot be recovered from the filesystem. No code repair was made.

### Settings and registration checks

The rendered controls show “Report Routing,” explicit include labels, unchecked skip descriptions, both route profiles, and the revision as secondary context. Registration and Admin account creation filter disabled Office/TSD categories, reject a disabled category submitted directly, preserve an existing user’s saved category while editing, and restore the category choices when settings are re-enabled.

- `php artisan test tests/Feature/OperationalGroupRegistrationTest.php`: 10 passed, 127 assertions.
- `node --test --test-concurrency=1 tests/js/routingPositionControls.test.mjs tests/js/settingsNavigation.test.mjs`: 6 passed, 0 failed.
- PHP syntax checks on the seven changed controller/request/service files passed.
- `git diff --check` passed; Git emitted only existing CRLF-to-LF warnings for this report and `resources/js/Pages/ComplianceAlerts/Index.jsx`.
- No production build was run: the frontend disk content had not changed since the previously passing build. The settings pages were rendered in the focused Node tests, but no live browser session was available in this run.

### Cleanup gate and database-target blocker

`php artisan about` reported `APP_ENV=local` and maintenance mode OFF. The effective Laravel connection is MySQL 8.4.3, database `cds_system`, on loopback `127.0.0.1:3306`, with 76 tables. The previously verified protected office backup and its manifest identify `cds_system` on `CDS-SERVER:3306` with 72 tables and 638 rows. The current connection therefore does not match the office database identity recorded for the backup. The existing backup is retained but is not a valid recovery point for the current connection.

Because the source identity did not match, cleanup stopped before a maintenance gate, fresh backup, disposable restore, or database write. The app remained out of maintenance; no partial deletion occurred. No trial record or dependency was deleted (deleted IDs: none). No after-count change applies. The earlier five-report roster was not treated as a current deletion candidate list, and the current connection’s ownership/provenance and report-linked archive objects were not accepted as the office inventory.

For transparency, the read-only `db:show --counts` result from the mismatched loopback connection listed the ten active routing sources as: Conservation 4; ENGP 1; BMS 0; BAMS 0; AWS 0; IMEA 0; IMEA maintenance 0; IPAF Management 0; Revenue 0; Management Plans 0. Other nonzero potentially related tables included 19 PAMB routing events, 6 MOV review events, 2 routing corrections, 3 archive metadata rows, 6 attachment-history rows, 71 notifications, and 2 report-compliance confirmations. These counts are diagnostic only; their provenance and linkage were not revalidated against the office database and they are not deletion authorization. The same read-only overview showed 10 users, 11 roles, 73 permissions, 6 organizational offices, 6 protected areas, 6 protected-area office assignments, 42 module definitions, 2 routing-setting versions, 1 settings head, and 10 cutover watermarks. No settings values were changed. The v2 off/off flags and watermark validity were not re-certified against the mismatched connection.

The Drive hierarchy and external archive objects were not accessed or changed. The protected backup remained untouched. No migration, seeder, settings save, custody action, report creation, or Apache restart occurred. To resume cleanup safely, first restore/confirm the exact office database connection, then take and verify a fresh protected backup of that exact target before opening a short write gate and proceeding with the per-module provenance review.

### Final worktree paths

The following 27 modified and 3 untracked paths remain preserved; nothing was staged, committed, or pushed:

```text
M app/Http/Controllers/Admin/RoutingWorkflowSettingsController.php
M app/Http/Controllers/Admin/UserController.php
M app/Http/Controllers/Auth/RegisteredUserController.php
M app/Http/Middleware/HandleInertiaRequests.php
M app/Http/Requests/StoreUserRequest.php
M app/Http/Requests/UpdateUserRequest.php
M app/Services/Authorization/OrganizationalAccessService.php
M app/Services/SubmissionTracking/RoutingPositionSettingsService.php
M docs/routing-correction-and-role-verification-2026-10-05.md
M docs/routing-position-controls-final-safety-verification-2026-10-06.md
M resources/js/Components/Admin/RoutingPositionControlsPanel.jsx
M resources/js/Components/Admin/SettingsShell.jsx
M resources/js/Pages/Admin/Settings/Index.jsx
M resources/js/Pages/Admin/Settings/ModuleManagement.jsx
M resources/js/Pages/Admin/Settings/RoutingWorkflow.jsx
M resources/js/Pages/Admin/Settings/Storage.jsx
M resources/js/Pages/Admin/Settings/SystemDiagnostics.jsx
M resources/js/Pages/ComplianceAlerts/Index.jsx
M tests/Feature/ConservationMeetingSharedRoutingTest.php
M tests/Feature/DocumentRoutingTransitionTest.php
M tests/Feature/OperationalGroupRegistrationTest.php
M tests/Feature/PambMovProcessingTest.php
M tests/Feature/ProtectedAttachmentTest.php
M tests/Feature/RoutingCorrectionSourceCoverageTest.php
M tests/Feature/StorageCapacityTest.php
M tests/js/calendarRendering.test.mjs
M tests/js/routingPositionControls.test.mjs
?? tests/js/helpers/authenticatedLayoutStub.jsx
?? tests/js/helpers/inertiaSettingsStub.jsx
?? tests/js/settingsNavigation.test.mjs
```

**Conclusion: PARTIAL / CLEANUP BLOCKED.** The editor-conflict review and focused settings/registration checks passed. The active database target did not match the previously verified office backup identity, so the office-wide trial-data cleanup, fresh backup/restore, per-module before/after inventory, and remote-object disposition remain pending. `.env`, `.recovery`, application data, and all existing local edits were preserved.

## Final office trial-data cleanup execution (2026-10-07)

This section supersedes the earlier “Cleanup gate and database-target blocker” entry.

### Database identity and recovery point

- PASS — Laravel’s effective connection is database cds_system, backend CDS-SERVER:3306, MySQL 8.4.3. The server UUID hash prefix matched the fresh backup manifest. A direct connection attempt to CDS-SERVER from the local client was rejected with sanitized MySQL error 1130; the effective connection’s backend identity, the older backup’s cds_system / CDS-SERVER:3306 identity, the matching 72 shared table definitions, and the current report/settings roster reconciled the client-host difference.
- The older protected backup contains 72 tables and predates the additive routing migrations. The current office database has 76 tables and 660 rows before cleanup. Its four additional tables are routing_position_cutover_watermarks, routing_position_setting_versions, routing_position_settings, and submission_routing_snapshots, created by 2026_10_06_000001_create_routing_position_settings.php and 2026_10_06_000002_create_submission_routing_snapshots.php.
- PASS — Fresh protected database backup retained offline; its filename, local path, and SHA-256 are recorded only in the protected manifest. All 76 tables are InnoDB. The restore matched all table definitions, per-table row counts, and two triggers; settings v1/v2 and all ten watermarks matched. The exact-ID cleanup passed in the disposable restore, and that disposable schema and its temporary credentials file were removed and verified absent.
- The protected database backup location and its files inherit the verified owner-only ACL. Five private MOV files were copied to that protected offline location; each copy size and hash matched its source and attachment-history metadata.

### Cleanup and retained data

- PASS — The five owner-entered report records were matched to their creator accounts and five corresponding creation audit entries. The cleanup ran in one transaction while Laravel maintenance mode was active, with no other active non-sleep application database sessions.
- Module source counts before → after: Conservation 4 → 0; ENGP 1 → 0; BMS 0 → 0; BAMS 0 → 0; IMEA 0 → 0; IMEA facility maintenance 0 → 0; AWS 0 → 0; IPAF management 0 → 0; IPAF revenue 0 → 0; Management Plans 0 → 0. All remaining active module-specific business tables were empty before and after.
- Removed 119 exact report-exclusive database rows: five source reports; 32 generic document-routing events; 19 PAMB routing events; six PAMB MOV review events; two routing corrections; six attachment-history rows; three archive metadata rows; two compliance confirmations; and 44 explicitly linked notifications. Submission-routing attachments, routing snapshots, report-tracking references, and ENGP release events were zero and remained zero.
- Kept all 55 audit-log rows. Kept 27 notifications whose provenance points to absent source IDs and could not be established as part of these trial reports. No ambiguous notification was deleted.
- Removed the five local originals only after their protected copies were verified. The three verified remote Drive archive objects remain; their metadata is recorded separately in the protected files.json manifest. No Drive calls or object deletions occurred, and the Drive folder hierarchy is unchanged.
- PASS — Final database: 76 InnoDB tables, two triggers, 541 aggregate rows. All table counts outside the approved deletion set are unchanged. Routing settings v1 remains all-enabled; current head v2 remains Office/TSD off. All ten cutover watermarks remain valid, and each corresponding next ID remains above its watermark. No migrations, seeders, settings changes, route actions, or application-code edits were made.

### Service restoration

- PASS — php artisan up restored service; Laravel maintenance mode is OFF and GET /login returned HTTP 200. Apache was not restarted.
- The protected offline cleanup manifest records the restore, cleanup counts, retained remote archive inventory, and service result. The seven existing local edits, .env, and .recovery/ were preserved. No staging, commit, or push occurred.
