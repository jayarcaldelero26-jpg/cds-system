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
