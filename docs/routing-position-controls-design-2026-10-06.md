# Routing Position Controls — Phase 2 Implementation and Verification

**PHASE 2 IMPLEMENTED IN THE LOCAL WORKTREE — NOT ACTIVATED.** Routing code, settings UI, additive migration files, and focused tests are present. The migrations and permission seeder were not run, so no route snapshot, operational setting, or permission assignment was changed. Do not enable the controls on an office instance until the reviewed rollout steps below are completed.

## First-page decision summary

The current default route is CENRO preparation → CENRO Chief → CENRO Records → PENRO Records → Office of the PENRO → PENRO TSD Chief → PENRO CDS Focal → PENRO CDS Chief → Office of the PENRO final review → final PENRO Records receipt → Regional release. The direct-to-PENRO profile omits the CENRO custody stages and joins at PENRO Records. The current action keys and correction loops are documented below.

The two controls are independent and default to enabled. After PENRO Records, the four initial branches are:

| Office of the PENRO | TSD Chief | Effective path |
|---|---|---|
| Enabled | Enabled | PENRO Records → Office receive/forward → TSD receive/forward → CDS Focal receive |
| Disabled | Enabled | PENRO Records dispatch → TSD receive/forward → CDS Focal receive |
| Enabled | Disabled | PENRO Records → Office receive/dispatch → CDS Focal receive |
| Disabled | Disabled | PENRO Records dispatch → CDS Focal receive |

At the later final review, Office enabled retains CDS Chief recommendation → Office receipt → Office correction return or approval → final PENRO Records receipt → Regional release. Office disabled changes that to CDS Chief recommendation directly to final PENRO Records receipt → Regional release. The direct recommendation is not an approval. Final Records remains the release actor in both cases.

The implementation adds a versioned setting head, immutable setting versions, per-source route snapshots, and source-specific ID cutover watermarks. One effective-graph resolver now supplies server transitions, authorization, queues, timeline, compatibility aliases, notifications, overrides, and client labels. Existing event rows and source report rows are not backfilled or rewritten.

The changed files include the transition/presenter and Submission Tracking services; archive policy/hook/lifecycle; notification and override services; settings routes/controller/UI; authorization provider and permission seeder; new setting/snapshot models and additive migrations; and focused routing tests. The schema and safe rollback limits are in “Snapshot, settings, and rollout”.

The required PENRO Records archive checkpoint follows its initial dispatch to whichever role is next. It stays fail-closed and uses the record’s canonical source, module, archive unit, and originating CENRO resolver. The final recommendation remains distinct from approval.

## Phase 1 review boundary and baseline (before implementation)

- Project: C:\laragon\www\cds-system.
- Branch and HEAD: main, d759510d89a816d525b9b1d156740ca08839212c.
- At the checked origin ref, main and origin/main are synchronized (0 ahead, 0 behind). No pull was run.
- The pre-existing local edits were preserved. The office .env exists; .recovery/ is absent.
- No AGENTS.md was found. The three supplied October 6 readiness/routing notes and the existing consolidated audit were reviewed.
- No application code, migration, seeder, setting, credential, account, report, Drive object, or runtime process was changed. No migration, OAuth/Drive action, browser verification, build, stage, commit, or push was performed in this phase.

The isolated PHPUnit configuration declares APP_ENV=testing, SQLite :memory:, blank DB_URL, array cache/session/mail, sync queue, and Pulse/Telescope/Nightwatch disabled. The focused current-worktree baseline passed **247 tests / 5,030 assertions** across PhaseOneRoutingStandardizationTest, DocumentRoutingTransitionTest, SubmissionTrackingReceiveRoundTripTest, SubmissionTrackingRoutingContractTest, SubmissionTrackingPresentationTest, SubmissionTrackingProgressTest, PambRoutingTimelineTest, PambMovProcessingTest, GenericCorrectionParityTest, RoutingCorrectionCycleIdentityTest, AdminRoutingOverrideTest, SubmissionRoutingAttachmentTest, ArchivePathBuilderTest, GoogleDriveDocumentArchiveGatewayTest, ConservationMeetingSharedRoutingTest, RoutingCorrectionSourceCoverageTest, and ProtectedAttachmentTest. The run used the repository’s testing configuration plus a fixed dummy APP_KEY; archive/provider behavior was exercised through test fixtures, not office credentials or live Drive.

Those are Phase 1 baseline tests for the current checkout, not tests for the position controls. The changed PAMB, attachment, and correction test files contain preserved uncommitted additions, so the count includes the current worktree’s local test edits. No browser click-through is claimed for that baseline.

The focused Node presentation/context/button/rendering baseline also passed **61/61**. It printed a non-fatal WebSocket warning that port 24678 was already in use; the test command still exited successfully, and no process was inspected or changed.

## Current source route and authority

### Regular CENRO-to-PENRO route

The canonical source of current action definitions is DocumentRoutingProfileRegistry::actionProfile (app/Services/SubmissionTracking/DocumentRoutingProfileRegistry.php, lines 39–97). Current operational order:

1. CENRO CDS Focal Person acts from PREPARATION with forward_to_cenro_chief; CENRO CDS Chief receives with receive_at_cenro_chief.
2. CENRO Chief may return_to_cenro_focal for correction or forward_to_cenro_records. CENRO Records receives with receive_at_cenro_records.
3. CENRO Records may return_for_correction_cenro_records while the record is in transit; it returns to the prior accountable sender/Chief. It forwards onward with forward_to_penro_records.
4. PENRO Records receives with receive_at_penro_records. Its return_for_correction_penro_records action returns to CENRO Records as the previous sender. Otherwise it forwards with forward_to_office_penro.
5. Office of the PENRO receives with receive_at_office_penro and forwards/assigns with assign_to_tsd_chief. PENRO TSD Chief receives with receive_at_tsd_chief and forwards with forward_to_cds_focal.
6. PENRO CDS Focal receives with receive_at_cds_focal and forwards with forward_to_cds_chief. PENRO CDS Chief receives with receive_at_cds_chief; it may return_to_penro_cds_focal or recommend onward with recommend_to_office_penro.
7. Office of the PENRO receives the final review with receive_at_office_penro_final. It may return_for_correction with return_from_office_for_correction, or approve with approve_for_regional_release.
8. PENRO Records performs receive_at_penro_records_final and then the terminal custody action release_to_regional.

The profile assigns each action to the existing accountable category: CENRO and PENRO focal/chief/Records actors, Office, TSD Chief, or PAMO for the separate PAMO-origin action. The transition service resolves state, checks the profile action and organizational access, rechecks under a source-row lock, and runs the MOV gate for applicable Regular/Special/TWC PAMB actions (DocumentRoutingTransitionService.php, lines 28–107, 185–272, and 331–353). Correction returns carry cycle metadata; a pending return exposes only a recipient’s Receive Correction acknowledgement before normal forward actions resume.

The generic profile has no correction-return action from final Records after its final receipt. This design does not add one or transfer correction authority to another role.

### Direct-to-PENRO compatibility

ProtectedAreaRoutingPolicy identifies the Mt. Hamiguitan Range Wildlife Sanctuary canonical name and aliases as direct PENRO routing. The transition service deliberately excludes ENGP from this direct-profile decision. DocumentRoutingProfileRegistry filters the CENRO stages from direct profiles; source-specific milestones and archive ownership remain on the record.

The profile retains forward_from_penro_origin and the separate forward_from_pamo entry, both addressed to PENRO Records. For an eventless direct-PENRO record, legacyStage currently bootstraps at TRANSIT_PENRO_RECORDS, so PENRO Records can perform its receipt rather than fabricating an origin event (DocumentRoutingTransitionService.php, lines 413–434). After that receipt, the direct route shares the PENRO Records, Office/TSD/CDS, final Records, and Regional stages above. Preserve this behavior and direct-profile identity in the snapshot.

### Historical PAMB and legacy action URLs

Regular, Special, and TWC meeting routes use the shared generic custody graph; ConservationMeetingRoutingCompatibilityAdapter projects existing PambRoutingEvent history into canonical events and maps old stage URLs to shared action keys. SubmissionTrackingController routes both the normal transition and applicable internal-routing aliases through shared validation and the same attachment/archive lifecycle (SubmissionTrackingController.php, lines 107–205 and 209–277; ConservationMeetingRoutingCompatibilityAdapter.php, lines 18–160). Keep old PAMB event history intact. The older PambRoutingTimelineService remains a compatibility projection for its non-shared/manual history; do not apply new graph controls by editing its date-only timeline semantics.

## Effective graph for the controls

The base profile remains the locked all-enabled contract. The effective graph resolver overlays only the two Office and TSD positions for routes captured under a new version. For the new branches, use explicit dispatch edges rather than making the client infer a different meaning from an old action key:

- Keep existing keys and event semantics when the all-enabled route is selected.
- Add a PENRO Records → TSD dispatch edge and a PENRO Records → CDS Focal dispatch edge for the disabled-Office branches.
- Add an Office → CDS Focal dispatch edge for the enabled-Office/disabled-TSD branch.
- Add a CDS Chief recommendation → final PENRO Records transit edge when Office is disabled. Its event remains recommended, its accountable actor remains CDS Chief, and it must not be labelled or stored as approved.
- Keep separate receive actions at TSD, CDS Focal, Office, and final Records wherever a destination is enabled. Do not turn dispatch into a receipt.

All four initial branches terminate at the same CDS Focal stage. Both enabled flags false therefore have no dead end or self-handoff. The direct profile joins at PENRO Records and uses the same four branches. Existing corrections to CENRO Focal, CENRO Chief, CENRO Records, and PENRO CDS Focal remain routed to those enabled recipients. Office’s final correction return exists only on routes whose captured graph includes Office. TSD has no correction edge to resurrect when its flag is false.

A skipped Office or TSD row is presentation-only with a readable “Skipped by Routing Workflow Settings” status and configuration-version label. If the page previews a route before its first action, identify the displayed version as a preview rather than a committed snapshot. A skip has no actor, timestamp, event ID, current badge, queue ownership, action, or completed-step credit. It is not a replacement receipt/approval. Direct profiles should mark CENRO stages not applicable through the existing direct-profile rule, not as a position-toggle skip.

Every transition endpoint must resolve the same graph. In particular, genericTransitionKeys and the normal POST stage must validate against it; the Conservation adapter and internal-routing aliases may translate only to the same currently effective edge. A bookmarked forward_to_office_penro URL when Office is skipped must be rejected as stale/unavailable, not silently redirected to a new target. Apply the same rule to stale duplicate posts and admin override options/execute. Re-resolve under lock before writing.

## Snapshot, settings, and rollout

### Proposed additive schema

1. routing_position_setting_versions: immutable, monotonic version number; office_penro_enabled; penro_tsd_chief_enabled; saved_by; created_at; and optional reason. Version 1 is true/true.
2. routing_position_settings: singleton head row (fixed key 1) pointing to the current version.
3. submission_routing_snapshots: unique source_key + source_id; immutable setting_version_id; captured profile key (regular/direct/PAMO-compatible as applicable); captured_at; and captured_by. This identity prevents numeric ID collisions among the ten source models.
4. routing_position_cutover_watermarks: source_key, source table, and max source ID at the feature cutover. Capture one watermark per active registered source without changing report rows.

The active SubmissionTrackingService::sources registry has ten entries: conservation, engp, bms, bams, imea, aws, ipaf-management, imea-maintenance, revenue, and management-plans (SubmissionTrackingService.php, lines 974–986). Technical Reports is retired and is not a routing source. Preserve ENGP’s Development Unit, office field, workflow-derived archive module, actor policy, and correction contract; the direct-area exception remains. Regular/Special/TWC PAMB stays on its compatibility-to-canonical route and MOV gates. MHRWS keeps its direct profile and omits CENRO stages. Do not make one source’s document or archive fields stand in for another source.

### Which version a route uses

- Existing rows at or below each cutover watermark resolve to virtual version 1, all enabled. Existing routes with routing events but no sidecar snapshot also resolve to version 1. Do not backfill or rewrite their events, report fields, dates, or attachments.
- A post-cutover report with no routing event or snapshot may preview the current head. Its first fully successful authorized custody transition captures the then-current version and its regular/direct profile in the same transaction as that first event.
- A route with a snapshot always uses that immutable snapshot for transitions, owners, corrections, queues, notifications, timeline, and labels. Later setting changes do not reassign in-flight work.
- A missing settings head before the feature migration is available falls back to all-enabled version 1. A snapshot pointing to a missing/invalid version is an integrity error for action execution; never replace it with the live head.
- Completed routes remain terminal and in History. A later enable/disable change must never give them a new action.

Use additive migrations only. Deploy/execute them later under a brief routing-write gate, after old application instances are drained: seed version 1 and head, capture watermarks, then release the resolver-aware code and expose settings. Keep the settings page hidden until every serving instance enforces snapshots. Do not run these migrations in this design phase.

### Save and first-transition concurrency

The new settings controller validates both values as required booleans. A save transaction locks the singleton head, appends an immutable version, moves the head, and records an AuditLogService entry with actor, prior version/values, new version/values, and timestamp. Validation or authorization failure writes no version, head change, or audit entry. GET is read-only.

For a first route action, lock the report row first (as DocumentRoutingTransitionService already does), determine that there is no route event/snapshot, then lock/read the settings head and persist the snapshot plus effective event in one transaction. A save locks only the settings head, never a report row. Thus either the route transaction gets version N before the save commits, or it gets N+1 after; the route does not combine flags from two versions. Existing snapshots do not need the head lock. Preserve source-row and active correction-cycle revalidation.

Do not persist the snapshot before actor authorization, stale-action validation, PAMB MOV checks, official-document validation, and other transition gates pass. A rejected first action creates no route event or snapshot. If a required archive checkpoint fails, the event and a snapshot first captured by that same attempted transition roll back together. If the route snapshot was committed on an earlier valid action, a later failed archive attempt leaves that established snapshot unchanged.

### Authorization and audit

Add named abilities submission-tracking.routing-settings.view and submission-tracking.routing-settings.update. Use them on the settings GET and update routes, add them to the existing permission catalog for the approved CDS Admin and Super Admin roles, and add both names to AppServiceProvider’s explicit global-role Gate bypass exception alongside routing correction and override. The broad global-role fallback otherwise grants arbitrary abilities. Do not change account activation or operational memberships; no seeder or role update is run in this phase.

Expose the controls on the existing Admin Settings page with explanatory copy: “Changes apply to routes when they start. They do not remove pending work from reports already in progress.” Show the current version and last saved actor/time. Audit each accepted version. A settings-view permission alone must not save; route actions do not require settings permission.

## Archive checkpoint and notifications

Today ArchiveCheckpointPolicy recognizes only forward_to_office_penro from penro_records to transit_to_office_of_penro. CompletedReportArchiveHook rechecks the persisted event, requires the authenticated actor and source scope, resolves the official-document adapter, active source module, archive unit, and stable archive office, then calls FinalDocumentArchiver as mandatory. The controller wraps transition, attachment changes, and RoutingTransitionLifecycle::afterTransition in an outer transaction; missing or unverifiable official documents fail the handoff.

Make the checkpoint a semantic property of the effective PENRO Records initial-dispatch edge, not a special property of the Office destination. Persist the route snapshot/version, effective action key, actual destination stage/office, and a checkpoint marker in event metadata. ArchiveCheckpointPolicy and CompletedReportArchiveHook must validate that marker against the persisted source + source ID + event identity + initial Records dispatch edge and the same captured graph. Retain the old exact match for historical Office events. Run the mandatory archive before the outgoing route can commit or the next holder can receive; any timeout, provider error, missing official document, scope mismatch, stale event, or verification failure rolls back the transition and supporting attachment writes.

The notification target comes from the resolver’s actual enabled recipient. No skipped-position notification is generated. The current generic transition service calls notifyGenericTransition after its nested transaction, while the controller’s outer transaction invokes the archive lifecycle afterward. Make ordering explicit in implementation: no downstream notification becomes visible or dispatches before archive verification and successful route commit. Keep database notification writes in the transaction or queue them after commit; test that an archive rejection leaves no recipient notification. Receipt notifications may still inform the prior sender after a real receipt.

Do not change ArchiveOfficeResolver, ArchivePathBuilder, configured Drive root, or the source/module/archive-unit mapping. Preserve the source’s stable originating CENRO and existing unit/CENRO office/canonical module folder hierarchy even when the operational recipient is TSD or CDS Focal. No Office OAuth repair, Drive request/upload, folder creation, root substitution, or skipped-position folder ownership belongs in this feature.

## History, documents, corrections, and display

DocumentRoutingPresenter currently builds its route timeline from the static base profile and marks stages current/completed/pending; SubmissionTrackingService has static action-key queue maps; the Conservation adapter has static legacy-key mappings; Index.jsx reads server-provided routing actions/timeline and calls the server action callback. Replace these independent decisions with resolver output:

- TransitionService state/presentation and both normal/override writes use effective actions and recorded profile/version.
- SubmissionTrackingService queue membership uses the current effective accountable action/owner, so a skipped Office/TSD queue is empty for those routes and the next enabled actor appears in the correct queue.
- Timeline presents real events exactly as stored. Add only display-only skipped markers from the snapshot; do not create synthetic DocumentRoutingEvent/PambRoutingEvent rows or count a skip as a real completion.
- PAMB compatibility labels and aliases resolve against the same graph; no legacy endpoint may bypass a disabled edge.
- Notification recipients and correction acknowledgements use persisted route identity and the actual graph recipient, not a hard-coded Office/TSD string.
- AdminRoutingOverrideService available/execute offers only current effective actions and includes snapshot/version/graph identity in its stale-state token in addition to source, record, stage, event, and cycle identity.
- RoutingCorrectionService remains limited to its allow-listed custody business dates and PAMB internal event timestamps, with chronology checks and audit history. It does not change route configuration, event action, actor, correction-cycle identity, or attachment ownership. An authorized date/time correction does not start a new route; correction and resubmission continue under the established snapshot.
- Preserve official document replacement semantics and its source-specific slot; routing copy and correction-reference attachments remain attached to the selected source + record + actual event. A route snapshot is never keyed on numeric record ID alone.

Keep DocumentRoutingPresenter::processingPercentage’s existing values: CENRO Chief 20, CENRO Records 50, PENRO Records 80, Office 85, TSD 88/90, CDS Focal 92/94, CDS Chief and final stages 96/100. A skipped marker adds no percentage and is not completed work. Preserve the distinction between processing at 100% and terminal routing; release_to_regional alone completes custody. Do not create a false current badge on a skipped stage.

Frontend work belongs in the existing Admin Settings index plus a Routing Workflow Settings page/component, and resources/js/Pages/SubmissionTracking/Index.jsx for readable skipped labels and consistent Side Panel, Full Details, Incoming, Outgoing, and History context. The UI submits server-issued action keys. Client filtering is presentation only, never security enforcement.

## Phase 1 implementation map

**Routing graph and APIs**

- app/Services/SubmissionTracking/DocumentRoutingProfileRegistry.php — preserve the default graph and define the new semantic dispatch edges.
- New app/Services/SubmissionTracking/EffectiveRoutingGraphResolver.php and RoutingPositionSnapshotService.php — immutable effective graph, current head, cutover fallback, capture, stage/owner resolution.
- app/Services/SubmissionTracking/DocumentRoutingTransitionService.php — state, lock/revalidation, actions, snapshot capture, correction receipt, override transition, and archive marker.
- app/Http/Controllers/SubmissionTrackingController.php — validate both regular and legacy PAMB action URLs against the resolver.
- app/Services/SubmissionTracking/ConservationMeetingRoutingCompatibilityAdapter.php and app/Services/SubmissionTracking/PambRoutingTimelineService.php — shared meeting projection/legacy aliases; leave non-shared historical timeline semantics separate.
- app/Services/SubmissionTracking/SubmissionTrackingService.php and app/Services/SubmissionTracking/DocumentRoutingPresenter.php — effective queues, route timeline, owner, progress and labels.
- app/Services/SubmissionTracking/AdminRoutingOverrideService.php — effective choices and version-aware stale token.
- app/Services/Notifications/EdatsInAppNotificationService.php — actual enabled recipient and archive/commit ordering.
- app/Services/Archive/ArchiveCheckpointPolicy.php, app/Services/Archive/CompletedReportArchiveHook.php, and app/Services/SubmissionTracking/RoutingTransitionLifecycle.php — semantic checkpoint across variable dispatch targets.

**Settings, persistence, and UI**

- New app/Models/RoutingPositionSettingVersion.php, RoutingPositionSetting.php, and SubmissionRoutingSnapshot.php (or equivalent models).
- New additive migrations for setting versions/head, route snapshots, and cutover watermarks.
- New app/Http/Controllers/Admin/RoutingWorkflowSettingsController.php and routes/web.php view/update routes.
- app/Providers/AppServiceProvider.php and database/seeders/PermissionSeeder.php for explicit named permission enforcement.
- resources/js/Pages/Admin/Settings/Index.jsx plus a new Admin settings Routing Workflow component; resources/js/Pages/SubmissionTracking/Index.jsx for server-driven labels.

**Focused tests**

- New Feature tests for effective graph/action authorization, settings permissions/audit, snapshots and concurrency, and archive checkpoint destinations.
- Extend DocumentRoutingTransitionTest, SubmissionTrackingRoutingContractTest, SubmissionTrackingPresentationTest, SubmissionTrackingProgressTest, ConservationMeetingSharedRoutingTest, PambRoutingTimelineTest, PambMovProcessingTest, GenericCorrectionParityTest, RoutingCorrectionCycleIdentityTest, AdminRoutingOverrideTest, SubmissionRoutingAttachmentTest, ProtectedAttachmentTest, and archive checkpoint/path tests as applicable.
- Do not alter RoutingCorrectionService business rules unless a regression shows it mutating snapshot state; add assertions that it does not.

## Implementation acceptance cases

1. Characterize all-enabled Regular, direct MHRWS, and Regular/Special/TWC PAMB routes: identical current action keys, actors, labels, correction loops, MOV gates, archive behavior, and terminal History state.
2. Exercise all four initial combinations through real HTTP endpoints, not by inserting a later-stage fixture. Assert Records dispatch, separate next-holder receipt/forwarding, actual queue owner, timeline, labels, and no dead end/self-handoff.
3. Exercise both final paths. With Office disabled, CDS Chief can recommend to final Records, final Records can receive/release, and there is no Office receipt, correction, or approval event and no Records/Chief approval action.
4. Reject wrong actors, disabled-position actions, stale old URL aliases, duplicates, stale override tokens, and wrong-office/PA actors with no event or unintended snapshot. Revalidate under the source lock.
5. Run two correction/resubmission cycles under each relevant graph; verify the snapshot persists, the correction acknowledgement recipient is enabled and correct, and MOV gates remain enforced.
6. Exercise all ten active sources, including colliding numeric IDs, ENGP-specific permissions/remarks/archive module, direct and regular profiles, and source-specific official-document slots. Include Regular/Special/TWC PAMB and MHRWS. Confirm retired Technical Reports is not exposed as a route source.
7. For each effective PENRO Records initial dispatch destination (Office, TSD, CDS Focal), test archive success and fake-provider failure. Verify failure leaves no dispatch event or downstream notification, preserves an already-committed route snapshot, and preserves the source/module/unit/originating-CENRO archive path. Verify first-action snapshot and event roll back together if that same transition is the failed checkpoint.
8. Use two database connections with a deterministic barrier to race a settings save against first route start; assert the route captures exactly one committed version. Repeat reads after a fresh application lifecycle. Also test actor office/PA switching without changing route authorization scope.
9. Test settings GET/update permissions, global-role Gate exceptions, required boolean validation, unchanged values, rejected-save no-write behavior, version/audit identity, all-enabled missing-head fallback, cutover-watermark fallback, and re-enable semantics.
10. Verify side panel, Full Details, Incoming, Outgoing, History, no-action terminal display, readable skip markers, callbacks, existing progress values, and processing-versus-terminal display. Use component/SSR assertions and an authorized browser UAT later; SSR is not a browser check.
11. Verify no duplicate assignment/notification for skipped Office/TSD, no synthetic event or configuration-driven historical rewrite, no changed event action/actor/cycle or attachment association, and no action resurrected for completed History records after toggles change. Preserve the separately authorized date/time correction behavior.

### Phase 2 implementation evidence — 2026-10-06

- The effective resolver implements all four independent Office/TSD paths, preserves the all-enabled action keys, adds explicit Records/Office dispatch actions, and sends an Office-disabled CDS Chief recommendation to final PENRO Records as `recommended`. It does not create an approval or Office event for that branch.
- The actual HTTP transition matrix in `RoutingPositionControlsTest` exercised the four initial combinations on BMS, including separate destination receipts/forwards, the current receiving queue owner, stale disabled Office action rejection, two PENRO Records correction/resubmission cycles per route, final Records receive/release, terminal History, and persistence of the original snapshot after later settings saves. A direct-profile graph check confirms the CENRO stages remain absent.
- The fake archive gateway passed for the Office, TSD, and CDS Focal initial dispatch destinations. The verified path retained `Conservation Unit / CENRO Mati / BMS`; the failed archive request left no event, first-transition snapshot, or notification. Provider fakes do not verify live Drive OAuth or writes.
- Settings use append-only versions, optimistic expected-version checks, a locked singleton head, named view/update abilities, audit entries, and explicit global-role exceptions. Tests covered view-only denial, role-only denial, update by an authorized CDS Admin, validation, stale saves, source-specific cutover watermarks, colliding numeric source IDs, historical all-enabled fallback, and invalid snapshot rejection.
- The all-enabled and all four branch behavior, timeline skip markers, settings panel, transition endpoint, and queue ownership were covered by isolated tests. The source coverage and existing regressions ran in the same bounded PHP suite. Final result: **358 PHP tests / 6,704 assertions passed**. Focused rendered Node tests: **8/8 passed**. PHP syntax checks and `git diff --check` passed.
- `npm.cmd run build` succeeded and transformed 1,434 modules. The manifest has 151 entries; every referenced JS, CSS, and asset file exists, and `public/hot` is absent. Package manifests were unchanged, so `npm ci` was not needed.
- The feature migrations and permission seeder were not executed. The settings therefore remain unavailable in the current database, and no setting or account permission was activated. No office `.env`, operational report, credential, Drive object, or runtime process was changed.

### Verification limits and safe rollout

The database feature tests use SQLite `:memory:`. No separate disposable MySQL instance was configured, so the settings-head versus first-route lock race has not been proven under MySQL row locking. No browser/app surface was available for an authenticated click-through; the Node evidence is rendered/component coverage. Office archive integration remains unverified against live credentials and Drive. Preserve the existing readiness audit’s OAuth finding and other unrelated open items.

Before an office rollout, review the code and perform an authorized maintenance window: pause routing writes and drain old application instances; deploy the resolver-aware code; run the two additive migrations once so version 1 and all ten source cutover watermarks are captured together; apply the approved permission-seeding process and verify the named abilities; then release routing writes and verify settings access and the all-enabled default with authorized accounts. Do not seed or toggle a disabled route until every serving instance uses the snapshot-aware code. Verify the Office archive checkpoint against the authorized live integration before relying on real routing. Keep existing snapshot/version/watermark rows on rollback; after any disabled snapshot is captured, a binary rollback to code that ignores snapshots is unsafe.

## Rollback and open items

Before any disabled-version snapshot is committed, the release may be rolled back to the prior application code while leaving the additive migration in place. Once a route has captured Office or TSD disabled, rolling back to code that ignores snapshots would silently restore those positions mid-route; that binary rollback is unsafe. From that point, rollback must use a compatibility-aware build that continues reading immutable snapshots and can complete the existing graph. The safest response to a defect is a forward fix. Retain version/snapshot/watermark rows; do not drop populated tables or rewrite them to “repair” a route. Re-enabling both controls affects only future route starts.

No routing-policy decision blocks this implementation. The separate August/Aug mismatch and other findings in the existing readiness audit remain unchanged.

## Source references

- app/Services/SubmissionTracking/DocumentRoutingProfileRegistry.php — stage constants and canonical action graph.
- app/Services/SubmissionTracking/DocumentRoutingTransitionService.php — state projection, actor authorization, locked transitions, correction cycle, MOV gate, legacy bootstrap, and compatibility dates.
- app/Services/SubmissionTracking/ProtectedAreaRoutingPolicy.php — MHRWS direct-profile identity and aliases.
- app/Services/SubmissionTracking/SubmissionTrackingService.php — active source registry, effective queue selection, and shared Conservation selection.
- app/Services/SubmissionTracking/ConservationMeetingRoutingCompatibilityAdapter.php and PambRoutingTimelineService.php — PAMB historical projection and old stage aliases.
- app/Services/SubmissionTracking/DocumentRoutingPresenter.php — effective timeline, skip display, and existing processing percentages.
- app/Http/Controllers/SubmissionTrackingController.php — route source authorization, compatibility URLs, action validation, attachments, and lifecycle transaction.
- app/Services/SubmissionTracking/AdminRoutingOverrideService.php — current effective choices and event/cycle stale-state token.
- app/Services/SubmissionTracking/RoutingCorrectionService.php — allowed date fields, chronology, and separate audit trail.
- app/Services/Notifications/EdatsInAppNotificationService.php — recipient category from action/event destination.
- app/Services/Archive/ArchiveCheckpointPolicy.php, CompletedReportArchiveHook.php, RoutingTransitionLifecycle.php, ArchiveOfficeResolver.php, ArchivePathBuilder.php, and FinalDocumentArchiver.php — semantic dispatch checkpoint, mandatory verification, stable archive office, and path builder.
- routes/web.php, app/Providers/AppServiceProvider.php, database/seeders/PermissionSeeder.php, app/Services/AuditLogService.php, and resources/js/Pages/Admin/Settings/Index.jsx — settings, named authorization, audit, and UI conventions.
- resources/js/Pages/SubmissionTracking/Index.jsx — detail panel, callbacks, Incoming/Outgoing/History, and timeline consumers.
- phpunit.xml — isolated baseline test environment.

## Phase 2 regression closeout — 2026-10-06

**Implementation closeout: PARTIAL. All-enabled routing parity: PASS. Operational activation: NOT PERFORMED.** The full isolated PHP Feature/Unit inventory passed. The frontend inventory had one existing date-format expectation mismatch in unchanged files; browser, MySQL locking, and live Office Drive checks remain open.

The regression tests reproduced a disabled-TSD graph defect: Office still offered `assign_to_tsd_chief` after TSD was disabled. `EffectiveRoutingGraphResolver` now removes that action whenever TSD is disabled, and the HTTP regression confirms a stale request creates no routing event while the enabled Office-to-CDS-Focal dispatch remains available.

Submission Tracking list projection previously resolved snapshots and setting data once per source record. `RoutingPositionSnapshotService::prime()` now batch-loads route snapshots, referenced setting versions, watermarks, and the current head for each list projection. `SubmissionTrackingService::records()` clears the projection cache in `finally`; transition-time resolution bypasses that cache and locks the setting head afresh. A five-record query-budget regression verifies at most one snapshot, head, and watermark query and two version queries.

Coverage now includes actual HTTP predecessor and initial-dispatch actions for all ten active sources; all four saved graphs for Regular PAMB, Special PAMB, and TWC on regular and direct-to-MHRWS routes; destination queues and source-qualified selection; archive identity and originating CENRO path; two correction cycles; MOV Needs Correction, explicit custody return, MOV replacement, resubmission and Ready gate; snapshot preservation after re-enabling positions; new-route use of the current setting version; current-actor office revalidation; and successful/failing archive checkpoints for Office, TSD, and CDS Focal. The all-enabled resolver action payload is compared to the base profile. No live database or provider was used.

### Complete PHP Feature/Unit batches

All 131 enumerated files ran in fourteen sequential batches. Each batch exited 0 with no failed tests.

| Batch | Files | Sorted first to last | Tests | Assertions | Failures | Exit |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| 1 | 10 | `AccountArchitectureTest.php` to `Auth/EmailVerificationTest.php` | 93 | 1,050 | 0 | 0 |
| 2 | 10 | `Auth/PasswordConfirmationTest.php` to `AwsReportingPeriodTest.php` | 37 | 251 | 0 | 0 |
| 3 | 10 | `AwsSummaryPeriodModesTest.php` to `CenroJurisdictionScopeTest.php` | 57 | 715 | 0 | 0 |
| 4 | 10 | `ComplianceAlertTest.php` to `CurrentDocumentReplacementServiceTest.php` | 314 | 2,295 | 0 | 0 |
| 5 | 10 | `DashboardMonitoringTest.php` to `GenericCorrectionParityTest.php` | 92 | 990 | 0 | 0 |
| 6 | 10 | `GenericQueueOwnershipTest.php` to `ManagementPlanManagementTest.php` | 79 | 760 | 0 | 0 |
| 7 | 10 | `MhrwsSubmissionTrackingTest.php` to `P2PamoPambScopeTest.php` | 54 | 711 | 0 | 0 |
| 8 | 10 | `P2Runtime006SpecialPambRecommendationTest.php` to `PhaseOneRoutingStandardizationTest.php` | 119 | 1,784 | 0 | 0 |
| 9 | 10 | `ProcessingHistoryTest.php` to `ReportDetailsPresentationTest.php` | 72 | 725 | 0 | 0 |
| 10 | 10 | `ReportEmptyStatePresentationTest.php` to `StorageCapacityTest.php` | 75 | 3,970 | 0 | 0 |
| 11 | 10 | `SubmissionFormScopeTest.php` to `SubmissionTrackingPerformanceTest.php` | 91 | 1,407 | 0 | 0 |
| 12 | 10 | `SubmissionTrackingPresentationTest.php` to `TemporaryExportFileTest.php` | 71 | 3,558 | 0 | 0 |
| 13 | 10 | `TimelinessPresentationTest.php` to `SubmissionTrackingPambCompletedFilterTest.php` | 42 | 2,389 | 0 | 0 |
| 14 | 1 | `SubmissionTrackingProgressMappingTest.php` only | 5 | 416 | 0 | 0 |
| **Total** | **131** | — | **1,201** | **21,021** | **0** | **0** |

The full Node inventory ran all 20 frontend test files: **143 passed, 1 failed (144 total)**. The remaining assertion is `tests/js/dateFormatters.test.mjs` expecting `Aug 29, 2026`; the unchanged formatter in `resources/js/Utils/dateFormatters.js` returns `August 29, 2026`. This does not fail because of worker spawning, so no serial retry was made and no unrelated date-format behavior was changed. The runner also printed nonfatal WebSocket port-in-use messages and mixed-export warnings.

The preceding R4-R6 continuation built successfully (1,434 modules); its verified manifest had 151 entries and 152 referenced files, with all references resolved and public/hot absent. This follow-up changed PHP only, so no frontend rebuild was needed. package.json and package-lock.json remain unchanged; npm ci was not needed.

### Remaining verification and activation status

- No separate disposable MySQL instance was available. Settings-head versus first-transition locking is **UNVERIFIED** under MySQL row locking; current PHP tests use isolated SQLite `:memory:`.
- No authenticated browser surface was available, so settings UI, receiving queues, skipped markers, correction UI and terminal History remain **UNVERIFIED** in a real browser. Node rendered/component coverage is not browser UAT.
- Fake archive success and rejection cover all three initial destinations. Office OAuth and Drive writes remain **UNVERIFIED** against authorized live credentials; no credential or provider request was used.
- Migrations and the permission seeder were **NOT RUN**. No office setting, permission, route snapshot, operational report, `.env`, `.recovery/`, live notification, runtime process, or Drive object was changed. Settings remain operationally unavailable until the approved rollout.

Before office activation: complete the MySQL lock race with a disposable two-connection database; perform authenticated browser UAT for both defaults and disabled graph previews/flows; validate archive upload and verification against the authorized Drive integration; review and schedule the maintenance window; deploy snapshot-aware code to every serving instance before running the additive migrations and approved permission seeder; verify authorized Settings access and the all-enabled default; then release routing writes. Once a disabled route snapshot exists, rollback must retain snapshot-aware routing code and its setting/snapshot/watermark rows.

## Regression review follow-up and verification - 2026-10-06

**Review status: R1-R6 corrected in the current worktree; operational activation: NOT PERFORMED.** R1-R3 and their permanent regressions were already present before the R4-R6 continuation. The original sanitized review ZIP at `C:\Users\jayar\AppData\Local\Temp\cds-routing-position-controls-review-d759510d-20261006.zip` predates R1-R3; the postfix review ZIP at `C:\Users\jayar\AppData\Local\Temp\cds-routing-controls-postfix-review-d759510d-20261006-132415-c41132d6.zip` contains R1-R3 but predates R4-R6. Byte comparison with its `current/` directory identifies the seven source/test files changed in this continuation. Application edits are limited to the reviewed routing profile, correction, authorization, and PAMB consumers. No broad workflow redesign was made.

### Exact changes and evidence

| Finding | File and function/component | Previous behavior | Current behavior | Changed in this continuation or already present | Regression name and result |
| --- | --- | --- | --- | --- | --- |
| R1 - normal transition profile drift | `app/Services/SubmissionTracking/RoutingPositionSnapshotService.php`, `resolve`; `app/Services/SubmissionTracking/DocumentRoutingTransitionService.php`, `state`, `transition`, `presentation`, `pambMovAllowsAction` | `state()` already selected the captured route profile. The defect was later in normal dispatch: the action-filter closure and event/archive metadata could derive profile from the current PA classification. After reclassification, the dispatched event profile could disagree with the immutable snapshot, so the archive identity check rejected the initial dispatch. This did not mean the canonical graph removed Office or reinstated CENRO stages. | The locked transition resolves the snapshot through `RoutingPositionSnapshotService`; normal transition state, event metadata, snapshot capture input, and the action-filter closure’s PAMB MOV gate all use that same `regular`/`direct` fact. The archive identity predicate is unchanged; matching captured profile lets the valid archive checkpoint proceed. | R1 was already present before this continuation; implemented in the earlier authorized R1-R3 work. | `captured regular and direct profiles survive PA reclassification in normal and override archive dispatches` - PASS, both reclassification directions in normal and override paths with fake archive. Archive failure rollback/no-notification regression also passes. |
| R1 - administrative override profile drift | `app/Services/SubmissionTracking/DocumentRoutingTransitionService.php`, `transitionAsOverride`; `app/Services/SubmissionTracking/AdminRoutingOverrideService.php` | The override dispatch could use current PA classification after the snapshot had fixed the route profile, producing the same snapshot/event identity mismatch. | Override state resolution, event metadata, snapshot ID/profile and archive checkpoint use the captured route position. Existing passkey/admin authorization and stale-state checks remain. The archive predicate is not weakened. | R1 was already present before this continuation; earlier authorized R1-R3 work. | Both override directions are covered by `captured regular and direct profiles survive PA reclassification in normal and override archive dispatches`. `archive rejection rolls back each initial dispatch destination without losing the prior snapshot or sending its handoff notice` - PASS for Office, TSD and CDS Focal destinations. |
| R2 - acknowledged settings version | `resources/js/Pages/Admin/Settings/RoutingWorkflow.jsx`, `onSave` success callback | After a successful save, the mounted form could keep the old `expected_version`, making a second save from the same page stale. | Successful `onSuccess` refreshes form defaults/data with the server-acknowledged version and both returned flags, then clears the reason. Validation errors do not run `onSuccess`, so unsaved choices and the note remain. The persistent hook test submits version 1, then version 2 from the same page instance; a separate stale session still fails without version/audit writes. | R2 was already present before this continuation; earlier authorized R1-R3 work. | `RoutingWorkflow page keeps the acknowledged version across consecutive successful saves and preserves edits on validation errors` - PASS, 4 Node tests. It uses the installed Inertia `useForm` hook in a persistent harness, not a mounted DOM or browser. Backend stale-session regression passes. |
| R3 - skipped row order | `app/Services/SubmissionTracking/DocumentRoutingPresenter.php`, `presentCanonical` | Skip rows were inserted immediately after PENRO Records. With Office enabled/TSD disabled, the TSD skip appeared before Office; with both disabled there was no explicit Office-then-TSD placement. | Exact timeline keys by initial Office/TSD setting: both enabled: `penro_records -> transit_to_office_of_penro -> office_of_penro -> transit_to_tsd_chief`; Office disabled/TSD enabled: `penro_records -> office_initial_skipped -> transit_to_tsd_chief`; Office enabled/TSD disabled: `penro_records -> transit_to_office_of_penro -> office_of_penro -> tsd_initial_skipped -> transit_to_cds_focal`; both disabled: `penro_records -> office_initial_skipped -> tsd_initial_skipped -> transit_to_cds_focal`. Final Office skip anchor when Office is disabled: `penro_cds_chief -> office_final_skipped -> transit_to_penro_records_final`. These four combinations run for both regular and direct profiles. | R3 was already present before this continuation; earlier authorized R1-R3 work. | `initial routing skip rows follow Office and TSD order for both regular and direct profiles` - PASS. Skip rows keep null actor/time, `skipped` status, no executable action and no completion credit. |
| R4 - captured PAMB profile in access, status filters and MOV | RoutingPositionSnapshotService::effectiveProfile and scopeEffectiveProfileQuery; PambSubmissionAccessService::scopeQuery; RoutingStatusPresenter::status/stage; SubmissionTrackingService::applyStatusFilter, normalize and matchesFilters | The CENRO base scope honored snapshots, but Pending CENRO and Pending PENRO SQL predicates plus normalized status still used current PA identity. A captured regular report reclassified as direct disappeared from Pending CENRO and could inflate Pending PENRO pagination counts. | Captured regular/direct snapshots now drive base access scope, both pending-status SQL filters and normalized status/stage. Unsnapshotted records retain current PA behavior. Database count, filtered rows, workspace queue and selected record now agree. Actor office, PA, category, active status and permission checks remain live. | Initial R4 access/MOV correction was followed by this independent-review status-filter fix. Five source/test files changed after the R4-R6 package. | HTTP regressions cover regular-to-direct reclassification for Regular PAMB, Special PAMB and TWC, both Pending CENRO and Pending PENRO filters, selected rows, pagination totals, direct-to-regular history, unsnapshotted direct control, wrong-office/PA/permission rejection and no-write GETs. Final routing-controls file PASS: 20 tests / 2,772 assertions. |
| R5 - unsupported first direct correction | `DocumentRoutingTransitionService::state`, `transition`, `supportedIncomingSender`, `categoryForStage` | At initial direct PENRO Records transit with no incoming event, Return for Correction defaulted to the CENRO Records destination, which has no supported direct resubmission action. | The correction action is hidden for direct initial transit unless the latest incoming event’s `from`/`to` pair exists in the captured route graph. POST repeats the check before mutation and returns a useful stage validation if no verified sender exists. When a supported PAMO/PENRO origin handoff exists, the return uses that origin and its category, then allows Receive Correction and the graph’s origin resubmission action. No route event/date/bridge is fabricated. | Implemented in this continuation; transition service and permanent routing-controls test changed versus the preceding postfix ZIP. | `direct initial Records correction rejects a missing sender before writing an incompatible handoff` - PASS for PAMB and BMS; wrong-category actor gets 403, authorized missing-sender attempt leaves events, snapshot, dates, document, archive, attachments, audit, notifications and queues unchanged. `direct Records correction returns only to a sender verified by the captured route graph` - PASS through PENRO-origin return, receipt and resubmission. Existing regular correction-cycle suites pass. |
| R6 - effective action auth before stale rejection | `DocumentRoutingTransitionService::transition` | Early authorization searched only the base action profile. Four custom position-dependent actions existed solely in the effective graph, so an unauthorized stale request could reach stage validation first. | After locking the row and resolving the captured position, known actions are authorized from `state['route_actions']` with current actor scope before current-stage validation. Current requests still use stage-filtered `state['actions']`; no permission decision is cached. | Implemented in this continuation; transition service and permanent routing-controls test changed versus the preceding postfix ZIP. | `stale position-dependent actions use effective-graph authorization before stage validation` - PASS for `dispatch_penro_records_to_tsd`, `dispatch_penro_records_to_cds_focal`, `dispatch_office_to_cds_focal`, and `recommend_to_penro_records_final`: wrong category, office, and PA/source scope get 403; authorized stale requests get stage validation. Assertions show no event, snapshot, date, document/archive, attachment, audit, notification or queue change. Existing HTTP routing matrix passes current dispatches. |

### Independent-review R4 status-filter follow-up

Pre-fix HTTP reproduction: php artisan test --compact tests/Feature/RoutingPositionControlsTest.php --filter="captured regular PAMB profile keeps CENRO visibility and MOV review after PA reclassification for every meeting workflow" exited 1 with 1 test, 0 passed and 17 assertions. The request expected the captured report in the Pending CENRO incoming queue, but the matching row was null (the new assertion at RoutingPositionControlsTest.php:1246). This was a real isolated HTTP GET using SQLite :memory: after the report captured the regular profile through focal-to-Chief handoff and Chief receipt, then its PA was reclassified as MHRWS/direct.

Correction: RoutingPositionSnapshotService now applies one SQL profile scope that prefers a captured regular/direct snapshot and uses current PA classification only where no snapshot exists. PambSubmissionAccessService reuses that scope for the CENRO base query. SubmissionTrackingService uses it for both Pending CENRO and Pending PENRO candidate filters, so SQL pagination counts and hydrated rows select the same route class. RoutingStatusPresenter receives the already resolved profile during normalization, keeping submission_status, stage and matchesFilters aligned with the route snapshot.

The permanent HTTP regression covers Regular PAMB, Special PAMB and TWC, status requests with and without source/source_id, queue row identity, selected details, pagination total/from/to/last_page, the sibling Pending PENRO count, captured direct-to-regular history, and an unsnapshotted direct control. Wrong-office and wrong-PA users cannot select the captured regular report; a PAMO user without submission-tracking.view gets 403. GET requests leave routing events, milestone dates, snapshots, MOV status and archive rows unchanged.

Post-fix results: RoutingPositionControlsTest.php passed 20 tests / 2,772 assertions; the focused routing/scope/status/queue/query set passed 126 tests / 1,858 assertions; the bounded backend inventory passed 1,209 tests / 21,677 assertions across 131 files in 14 batches. No frontend source changed, and the prior final frontend log remains included with the unchanged single Aug/August assertion failure. MySQL two-connection locking and authenticated browser UAT remain outstanding; no migrations, seeders, settings changes or operational activation were performed.

The regression suite also retains protection for the workflow boundaries: captured snapshots survive settings changes; archive rejection rolls back an uncommitted first snapshot/event and sends no handoff notice; the existing PAMB MOV return gate remains tied to the captured profile; disabled destinations do not acquire actions; and correction-cycle, authorization, stage, percentage, final Records release, and archive hierarchy behavior remain covered by the bounded inventory. No broad workflow redesign was made.

### Frontend inventory reconciliation

The original report's full 20-file inventory was **143 passed / 1 failed (144 total)**. After adding the R2 page-hook regression, the latest recorded 18-file `tests/js` run is **121 passed / 1 failed (122 total)**. The two `tests/frontend` files were still present and were omitted from that command; they were run individually during this verification turn and passed **21/21** and **2/2**. Reconciled current inventory: **20 files, 144 passed / 1 failed (145 total)**. The one failure is unchanged: `tests/js/dateFormatters.test.mjs` expects `Aug 29, 2026`, while `resources/js/Utils/dateFormatters.js` returns `August 29, 2026`.

The 18 `tests/js` files were run sequentially with Node's test runner (`--test-concurrency=1`) in the recorded run. Their per-file outcomes were 17 passing files and the one date formatter failure; the recorded group summary is 121 passing test cases and one failing case. The two omitted suites were run with these exact commands:

- `node --test --test-concurrency=1 tests/frontend/SubmissionTrackingPreviewContract.test.mjs` - **21 passed, 0 failed, exit 0** (non-fatal Vite mixed-export warnings).
- `node --test --test-concurrency=1 tests/frontend/SubmissionTrackingIncomingVisibility.test.mjs` - **2 passed, 0 failed, exit 0**.

| Frontend test file | Invocation/result |
| --- | --- |
| `tests/js/AuthenticatedNavigation.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/calendarRendering.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/calendarScrollPerformance.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/dateFormatters.test.mjs` | Recorded 18-file sequential run - FAIL, 1 failing expectation |
| `tests/js/globalControlRendering.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/modalFileDrop.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/pdfViewerGeometry.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/pdfViewerRequests.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/premiumTimePicker.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/protectedDocumentPreview.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/reportTypeSelection.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/routingCorrectionForm.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/routingPositionControls.test.mjs` | Recorded 18-file sequential run - PASS; includes R2 acknowledged-version regression |
| `tests/js/sharedButtonPresentation.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/sharedFilterPresentation.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/submissionDetailContext.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/submissionTrackingPresentation.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/js/targetedUiCorrections.test.mjs` | Recorded 18-file sequential run - PASS |
| `tests/frontend/SubmissionTrackingPreviewContract.test.mjs` | Run in this verification turn - 21 passed, 0 failed, exit 0 |
| `tests/frontend/SubmissionTrackingIncomingVisibility.test.mjs` | Run in this verification turn - 2 passed, 0 failed, exit 0 |

The final bounded 20-file frontend Node run occurred during the preceding R4-R6 continuation, before this PHP-only status-filter follow-up. Its complete log is included in the updated review package: 144 subtests passed, one test file failed, runner exit 1. The failure is tests/js/dateFormatters.test.mjs expecting Aug 29, 2026 while the unchanged helper returns August 29, 2026. The other 19 files passed. No frontend source changed in this follow-up, so that result remains applicable; the formatter mismatch was left unchanged.

### Backend, build, and isolation evidence

After the status-filter correction, the focused RoutingPositionControlsTest.php suite passed 20 tests / 2,772 assertions. The affected routing, scope, status, queue and query suites passed 126 tests / 1,858 assertions. The final bounded PHP Feature/Unit inventory passed 131 files in 14 sequential batches: 1,209 tests / 21,677 assertions, 0 failures, every batch exit 0. Runs use phpunit.xml with isolated SQLite :memory:, blank DB_URL, array cache/session/mail, sync queue, and fake archive/storage providers. Final logs and batch summary are included in the updated export.

The preceding R4-R6 continuation built successfully (1,434 modules); its verified manifest had 151 entries and 152 referenced files, with all references resolved and public/hot absent. This follow-up changed PHP only, so no frontend rebuild was needed. package.json and package-lock.json remain unchanged; npm ci was not needed.

### Updated source review package

This documentation-only r2 package incorporates the independent closeout review cleanup of verification/verification-results.txt and includes that closeout review; application source is unchanged from r1.

The sanitized status-filter follow-up review export is C:\Users\jayar\AppData\Local\Temp\cds-routing-controls-status-filter-followup-d759510d-20261006-r2.zip. Its manifest is metadata/manifest.json; the identical sidecar is C:\Users\jayar\AppData\Local\Temp\cds-routing-controls-status-filter-followup-d759510d-20261006-r2-manifest.json. It includes current feature source, unchanged HEAD baselines, the final HEAD diff and a separate R4-R6-to-follow-up patch, the relevant verification and review prompts, the updated Phase 2 report, the exact pre-fix HTTP failure, focused suite results and all final backend batch logs. It excludes generated build output, .env, secrets, .recovery/, vendor, node_modules, operational data/documents and Git internals.

### Provenance, branch, and activation

- Branch: `main`; HEAD: `d759510d89a816d525b9b1d156740ca08839212c`; no staged changes. The worktree remains dirty with pre-existing tracked and untracked feature edits plus unrelated local edits preserved.
- The original review ZIP predates R1-R3, and the earlier postfix ZIP predates R4-R6. The independent R4-R6 package is the baseline for this continuation. Exactly five files changed after it: PambSubmissionAccessService.php, RoutingPositionSnapshotService.php, RoutingStatusPresenter.php, SubmissionTrackingService.php and RoutingPositionControlsTest.php. The follow-up ZIP includes the updated report, final source, unchanged HEAD baselines, the final tracked-source HEAD diff, the five-file follow-up comparison and verification evidence.
- No migrations or permission seeder were run. No operational settings, data, credentials, runtime, `.env`, `.recovery/`, or live Drive objects were changed. No staging, commit, or push occurred.
- MySQL two-connection lock-race validation and authorized authenticated-browser UAT remain outstanding. Fake archive providers do not verify live Drive OAuth or upload behavior. Settings remain operationally unavailable until the approved rollout steps above are completed.
