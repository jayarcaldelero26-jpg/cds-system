# Routing correction and role verification — 2026-10-05

## Executive summary

Completed-report date correction did not create or advance custody in the isolated Regular, Special, and TWC fixtures. It changed the business date and correction/audit history while preserving event IDs, action times, actors, completion, workspace membership, receipt and regional dates, and the MOV verdict. The MOV card is a projection of the stored review verdict and CENRO release date; its appearance alone is not evidence of a new handoff.

Five confirmed defects were corrected narrowly: PAMB mixed-history presentation could fail and misclassify canonical milestones as editable; repeated PAMB cycle stage keys could correct the wrong event; ENGP accepted/ignored malformed or unsupported correction identifiers/fields; global administrator roles bypassed the named correction/override abilities; and same-stage override choices could be replayed after the report had advanced to a later cycle. Regressions now cover these cases.

The owner has approved a narrow rule: an existing required routing date cannot be cleared after the report is complete. The guard now rejects that change with a field-level error while allowing valid date replacements and preserving custody, actor/action history, History membership, and MOV state. No reopen behavior was added.

## Baseline and safe scope

- Repository: `C:\laragon\www\cds-system`; HEAD at inspection: `104eba3`.
- The worktree already contained Calendar, npm lockfile, updater, and related report/test changes. They were preserved. This pass did not stage, commit, or push anything.
- No `AGENTS.md` was found. The 2026-10-01 handoff and system-wide audit were read; their receipt, role, and routing contracts were retained.
- The current `SubmissionTrackingService::sources()` registry has ten definitions: `conservation`, `engp`, `bms`, `bams`, `imea`, `imea-maintenance`, `aws`, `ipaf-management`, `revenue`, and `management-plans`. Technical Reports remains retired and is not registered. These are current code definitions; live module activation rows were not queried.
- Backend tests ran with `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and blank `DB_URL`; PHPUnit also sets `APP_ENV=testing`, array cache/session/mail, and sync queue. New fixtures use fake storage, notifications, and archive configuration. No live report, operational database, external archive, or notification provider was read or changed.

## Operations and observed behavior

### Correct routing / date correction

The endpoint remains `PATCH /submission-tracking/{source}/{record}/correction`. It is separate from workflow return actions and administrative override. It requires an authorized global admin role, the named `submission-tracking.correct-routing` ability, a reason, and that user’s current password. Successful changes write `SubmissionRoutingCorrection` and audit entries. The correction does not itself make a routing event or handoff.

The isolated fixture built completed Regular PAMB, Special PAMB, and TWC histories through the PAMB timeline writers, with the corresponding canonical CENRO release, PENRO receipt, internal stages, and regional release. After changing the CENRO release business date through the real PATCH endpoint:

- The corrected date appeared as the CENRO release `business_date`; the stored PAMB event ID, `occurred_at`, and `recorded_by` remained unchanged.
- PENRO receipt and regional endorsement dates remained unchanged. The reports remained routing-complete, had no further actions, and remained in History only, not Incoming or Outgoing.
- The MOV projection remained Released by CENRO to PENRO / Ready for Release / 100%. This is sourced from the corrected release date and the stored review verdict, not a new custody event. The recorded action timestamp was not rewritten.
- Each changed field added correction and audit history. The fixture retained the existing MOV file metadata/path; no archive upload was performed.

Existing correction tests cover chronology rejection, reason/password checks, another user’s password rejection, and a second correction. The new ENGP tests cover unsupported parent fields, malformed event IDs, and event ownership. ENGP corrections are component-event based: parent receipt may be corrected, while each CENRO release is tied to a release-event ID belonging to that ENGP parent report.

**Historical terminal-null reproduction (before the approved guard):** clearing `date_report_released_cenro` had been accepted while canonical routing events still showed completed regional custody. `routing_complete` and History membership remained, while `PambMovProcessingService` derived Ready for Release / 70%. The owner has since approved prevention of that clearing path; the follow-up below records the guard and rejection evidence.

### Needs Correction / return and resubmission

Needs Correction remains a workflow action with stage-specific accountable actors, destination, reason/remarks, receipt, and resubmission; it is not date correction. Existing suites exercise CENRO, PENRO CDS, and Office of the PENRO correction loops, including second cycles, preserved reasons/history, prior-cycle isolation, and terminal release gates. PAMB Regular/Special/TWC flows resume from the real PENRO return event. Direct-to-PENRO MHRWS flows keep correction and receipt within PENRO. Generic correction profiles are registered for all non-PAMB routed families; source-neutral profile parity is asserted.

Normal operational users remain subject to current-stage category and office/PA checks. Existing tests exercise CENRO Focal, CENRO Chief, CENRO Records, PENRO Records, Office of the PENRO, PENRO TSD Chief, PENRO CDS Focal/Chief, and PAMO visibility/scope cases. Wrong-category operations, cross-office/PA access, inactive or incomplete accounts, and legacy PAMO misuse are denied in the applicable suites. This is broad regression evidence, not every actor × source × stage permutation.

### Administrative routing override

Override remains separate from date correction and Needs Correction. The exact global roles are `CDS Admin` and `Super Admin` (`OrganizationalAccessService::isGlobal`). The exact ability is `submission-tracking.admin-override`; it is assigned to those roles by `PermissionSeeder`. The options and execute routes both require the `admin` middleware and that ability. The service further requires an active account, registered non-ENGP source, valid source/record and PA scope, a currently available action, a reason, and WebAuthn passkey verification.

Override history stores the real admin user/category separately from the accountable role/office being overridden. Normal admin visibility does not create an operational owner or a normal action. Existing tests cover current-stage generic and transit choices, passkey enrollment gating, source scope, actor attribution, and attachment/archive lifecycle integration. ENGP is intentionally excluded from override by the service.

An options request previously saved only the current stage in session. The same action key and same stage can recur after a correction cycle, so a stale passkey choice could be submitted against the later cycle. Options now keep a server-side state token with source, record, stage, and the ordered event/cycle identity. The token is not returned to the browser. Execute compares it before passkey verification and checks fresh state again inside the transaction. A repeated `forward_to_cenro_chief` later-cycle fixture proves the old token is rejected without invoking the passkey verifier or writing an override/event. Simultaneous concurrent submissions were not tested.

## Source and actor coverage at the original review cut

| Registered source/profile | Date correction evidence | Needs Correction / cycles evidence | Override evidence and limits |
|---|---|---|---|
| Conservation: Regular, Special, TWC PAMB | Completed three-profile date-only PATCH fixture; mixed canonical/internal history; CENRO release date and terminal-clear behavior | PAMB return/resubmit and Office correction cycles; MHRWS direct-to-PENRO loop; role and wrong-category tests | Conservation generic options/stale-cycle tests and PAMB override implementation; not every PAMB stage/source combination executed as an override |
| Conservation generic workflows | Shared service correction path; correction/password/chronology coverage includes Conservation | Executable Homestay CENRO, PENRO CDS, and Office cycles; wrong-actor/missing-reason rejection | Generic current-stage actions and stale-cycle replay regression |
| BMS, BAMS, IMEA, AWS, IMEA maintenance, IPAF management, revenue, Management Plans | Common registered milestone correction path; not independently endpoint-tested for every source/date column in this pass | `GenericCorrectionParityTest` checks return-action profiles for all nine non-PAMB source families; full executable cycles are not claimed for every family | Source/record resolution is shared; override integration tests cover selected sources, not every registered source |
| ENGP | Parent PENRO receipt and event-owned component release correction path; malformed ID, foreign event, and unsupported parent fields rejected | Generic ENGP routing and office-scope/receipt tests exist; event-based release dates remain distinct from a report-level release date | Explicitly unsupported by admin override service |

The source inventory reflects registered application definitions, not live `module_definitions.is_active` values. Date-field persistence across every listed non-ENGP model and override behavior across every registered source were not exhaustively exercised. No claim is made that every category/source/stage permutation was verified.

## Confirmed defects and changes

1. **Mixed PAMB canonical/internal history could fail details presentation.** `PambRoutingTimelineService::presentCorrectionCycle()` labeled all persisted `PambRoutingEvent` rows internal, and its stage definition lacked canonical CENRO release and PENRO Records receipt keys. A mixed cycle containing those canonical rows could fail timeline assembly and expose canonical business milestones as internal-edit payload. The presenter now resolves internal status from the actual key and includes labels for those canonical keys. Regression checks mixed history and editable flags.

2. **Repeated PAMB event keys could target the wrong cycle.** `RoutingCorrectionService` collapsed events to canonical stage keys, so cycle-suffixed stages were not uniquely addressed and audit identity could lose the suffix. It now indexes/validates the full persisted stage key, updates only that row, preserves the suffix in audit fields, and validates internal chronology cycle by cycle from the prior return event. Regression corrects cycle one while asserting cycle two’s ID, time, and actor are unchanged.

3. **ENGP invalid correction payloads could be silently accepted or misidentified.** Unsupported parent milestone fields were ignored if another field was valid, and an integer cast could turn a malformed release-event key such as `1-invalid` into event `1`. Parent ENGP fields are now allow-listed to PENRO receipt, event IDs must be positive integers, and the selected event must belong to the current parent report. Non-ENGP sources reject event-release payloads. Regression verifies malformed, unsupported, and foreign event requests leave values and correction history unchanged.

4. **Global admin role fallback bypassed the named abilities.** `AppServiceProvider` returned `true` for every otherwise-unhandled Gate ability for either global role. Thus a role-only Super Admin could pass `can:submission-tracking.correct-routing` or `can:submission-tracking.admin-override` without the ability that the seeder and services require. The Gate now lets those two named abilities resolve normally through Spatie permissions before the global fallback. Regression confirms a Super Admin with the correction ability succeeds, a Super Admin without it receives 403, an operational actor with the ability still receives 403, and a global role without override ability is denied both options and direct execute with no event or override write.

5. **Override state was represented by stage alone.** Session context now binds the action selection to a server-side state token and rechecks the fingerprint before/after authentication, preventing sequential stale same-stage cycle reuse while preserving current-stage/action rules.

## Verification run at the original review cut

- Full backend Feature/Unit inventory after the application-code fixes: 127 PHP files, executed in four bounded batches against isolated in-memory SQLite. Later test-only assertion additions were covered by the affected-suite rerun below.
  - Files 1–32: 314 tests, 3,096 assertions — passed.
  - Files 33–64: 377 tests, 2,686 assertions — passed.
  - Files 65–96: 248 tests, 2,863 assertions — passed.
  - Files 97–127: 219 tests, 7,852 assertions — passed.
  - Total: **1,158 tests, 16,497 assertions — passed**.
- Final affected-suite rerun after adding direct override-execute denial and MOV-file preservation assertions: `AdminRoutingOverrideTest`, `RoutingCorrectionCycleIdentityTest`, and `SubmissionTrackingTest` — **34 tests, 310 assertions — passed**.
- PHP syntax checks passed for all changed PHP services/controller/provider/test files. `git diff --check` passed. The final review did not stage or alter the preserved Calendar/npm/updater changes.
- No browser session or authenticated isolated UI was available for click-through. The HTTP feature fixture queried refreshed service projections and workspace queues after PATCH; redirect-driven Inertia refresh and live rendering remain unverified. No correction or override was submitted against an actual owner record.

## Historical status at the original review cut (before the completed-date guard)

- **Date correction — PARTIAL.** Completed Regular/Special/TWC fixtures and validation/identity regressions pass. The terminal-null business rule and exhaustive model-by-model field persistence remain open.
- **Needs Correction / cycles — PASS for the tested backend paths.** PAMB, direct-PENRO, generic Conservation, source-neutral action profiles, category/office boundaries, and cycle history have regression evidence; this is not exhaustive source × role coverage.
- **Admin authority / override — PASS for the tested routes and service paths.** Named abilities now constrain global roles; role-only, operational-actor, and stale-cycle requests reject without successful writes. Per-source completeness and simultaneous race guarantees remain unverified.
- **Live verification — UNVERIFIED.** No live browser correction/override or owner-record inspection was performed.

**Historical owner summary:** the completed-report date correction path preserved custody and History state in isolated fixtures. Named admin permissions and stale-cycle override checks were enforced. The terminal-clear policy was still open at that review cut.

## Owner-approved completed-date guard and coverage closeout — 2026-10-05

### Decision and implementation

The owner-approved rule is enforced in `RoutingCorrectionService` inside a database transaction after locking and freshly loading the target report. Completion comes from the current source routing state (`released_to_regional`); PAMB state is resolved through the existing shared-routing adapter, which also understands legacy and mixed PAMB timelines. The guard applies only when the existing date is non-empty, the submitted value normalizes to blank/null, and the milestone applies to that source/profile.

- Existing CENRO release, PENRO receipt, and regional endorsement dates on completed non-ENGP sources cannot be cleared. Direct-to-PENRO CENRO release stays inapplicable and absent. A null field left null is not treated as a new clear; a request with no actual changes still receives the existing no-change error.
- ENGP parent receipt is guarded when its current routing state is complete. Component CENRO releases remain event-owned and are validated against positive integer IDs belonging to the parent report. Their actual migration column is non-nullable, so an existing component release cannot be cleared through correction. ENGP has no parent `date_report_released_cenro` or `date_endorsed_regional` column; unsupported parent date fields receive field-level validation errors.
- Exposed PAMB internal event timestamps use the exact persisted stage key, including `__cycle_n`. A completed internal timestamp cannot be cleared. Valid date/time replacements still pass through chronology checks. Validation precedes all updates, correction rows, and audit writes; a rejected mixed payload rolls back without changing source rows, events, documents, queues, MOV state, or history.
- The form already serializes empty inputs as empty strings, which Laravel normalizes to null. Existing null values therefore remain unchanged; the ENGP component date picker now renders its server-side field error. Existing business-date and internal-time field errors remain bound to their inputs.

### Current canonical and legacy PAMB evidence

The current-route fixture uses `DocumentRoutingTransitionService` with the designated CENRO and PENRO actors for all 19 transitions in each Regular PAMB, Special PAMB, and TWC workflow. Each completed record has 19 shared `DocumentRoutingEvent` rows and zero `PambRoutingEvent` rows. A valid release-date replacement succeeds; a mixed valid receipt edit plus null release is rejected. Fresh Inertia endpoint props retain the corrected business date, completed state, no remaining actions, and exact source/ID membership in History only. Event IDs, action times, and actors remain unchanged. The MOV remains Released by CENRO / 100%, and its file metadata is preserved.

Separate legacy/mixed-history tests use `PambRoutingTimelineService` and persisted `PambRoutingEvent` rows. They reject clearing an exposed internal timestamp both in the first cycle and at the exact `received_by_cds__cycle_2` key after a completed return/resubmission cycle. The rejection snapshots compare report columns, both event stores, correction/audit rows, workspace queues, and MOV projection. This keeps historical timeline-writer coverage distinct from the current shared canonical route fixture.

### Endpoint and persisted-column matrix

Every registered source was exercised through the actual correction PATCH endpoint using its current model and migration columns. Non-ENGP sources use the registry’s default `date_received_penro` receipt alias; CENRO release and regional endorsement were also verified where the model has those columns. Each completed fixture accepted a valid CENRO date replacement, persisted it on the intended model, and rejected both explicit null and empty-string clearing in a mixed payload.

| Registry source | Model/table | Verified persisted correction columns | Outcome |
|---|---|---|---|
| `conservation` | `ConservationReportSubmission` / `conservation_report_submissions` | `date_report_released_cenro`, `date_received_penro`, `date_endorsed_regional` | PATCH replacement and completed clear rejection passed |
| `bms` | `BmsReportSubmission` / `bms_report_submissions` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `bams` | `BamsReportSubmission` / `bams_report_submissions` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `imea` | `ImeaReportSubmission` / `imea_report_submissions` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `imea-maintenance` | `ImeaFacilityMaintenanceReport` / `imea_facility_maintenance_reports` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `aws` | `Aws` / `aws` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `ipaf-management` | `IpafManagementReport` / `ipaf_management_reports` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `revenue` | `IpafRevenueCollection` / `ipaf_revenue_collections` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `management-plans` | `ManagementPlan` / `management_plans` | Same three nullable milestone columns | PATCH replacement and completed clear rejection passed |
| `engp` | `EngpReportSubmission` and `EngpReportReleaseEvent` / `engp_report_submissions` and `engp_report_release_events` | Parent `date_received_penro`; child `date_report_released_cenro` keyed by child event ID. Parent release and regional columns are absent. | Parent and component replacement passed; parent null/empty, component clear, malformed/foreign ID, and unsupported regional parent field rejected |

The first nine models were also fetched through the Inertia workspace endpoint after valid changes. Selected details reflected the saved date and terminal state, had no actions, and the exact source-plus-ID appeared in History but not Incoming/Outgoing. A BMS in-flight fixture confirms an existing nullable CENRO date can still be cleared before completion. A full direct-to-PENRO route was executed with PENRO actors; its completed correction left the inapplicable CENRO date null while correcting PENRO receipt. A completed report whose old CENRO value was already null therefore remains correctable without inventing that date.

The existing ENGP regressions remain in place for malformed positive-integer identity handling, foreign component-event rejection, and unsupported parent milestones. The new parent receipt and child component tests also compare rejected-state snapshots, including the model row, shared routing events, document archive rows, correction/audit records, and workspace queues.

### Current verification

- All 128 backend Feature/Unit PHP files passed in four bounded, in-memory SQLite batches using fake storage, notifications, and archive configuration:
  - Files 1–32: **314 tests, 3,098 assertions — passed**.
  - Files 33–64: **377 tests, 2,686 assertions — passed**.
  - Files 65–96: **252 tests, 3,243 assertions — passed**.
  - Files 97–128: **221 tests, 7,873 assertions — passed**.
  - Current total: **1,164 tests, 16,900 assertions — passed**.
- Final targeted correction/permission/PAMB/shared-routing/ENGP/override/history/receipt/archive run: **172 tests, 2,812 assertions — passed**, including the final per-source endpoint and atomic-state snapshots.
- `npm.cmd run build` passed with Vite 8.1.4 (1,431 modules transformed). The full JS test inventory reported **111 passed, 1 failed**: the already-known formatter expectation `Aug 29, 2026` versus actual `August 29, 2026`. Other Calendar and UI checks in that run passed. The known formatter mismatch was left unchanged.
- The JavaScript test run printed a Vite WebSocket port `24678` already-in-use warning; the build completed successfully. Existing lockfile changes in the worktree were preserved; this follow-up did not modify the lockfile.
- Explicit `php -l` passed for all changed PHP application and test files. `git diff --check` passed; the final scoped diff review found no whitespace or unintended routing/UI edits.

### Limits and current status

- **Completed-date protection — PASS.** The owner-approved completed clear is rejected at the endpoint with field-level web validation errors and no partial writes. Valid replacements, in-flight clearing, unchanged null, and direct-PENRO applicability are covered.
- **Canonical/source endpoint coverage — PASS for the executed matrix.** Current canonical Regular/Special/TWC routes and all ten registered source endpoints were exercised. This does not prove every role × source × stage combination.
- **Permissions/overrides — PASS for the exercised routes and service paths.** Global admins with the named ability can correct; global roles without it and operational actors even with it are denied by existing tests. Override ability, actor attribution, stale-cycle rejection, and archive safeguards remain covered by the full suite.
- **Live verification — UNVERIFIED.** No authenticated isolated browser session was available. The HTTP/Inertia feature tests verify refreshed props and endpoint errors, but live form error rendering and click-through remain unverified. No live owner record or operational service was accessed.
- **Concurrency — not separately verified.** Correction holds a transaction and parent-row lock while deriving state and writing dates/audit rows. Simultaneous correction-versus-transition requests were not run, so no concurrency guarantee beyond that implementation was tested.

**Current owner summary:** completed required dates cannot be erased through Correct routing. Corrected business dates do not reopen custody or rewrite event identity, attribution, or action time. Source persistence and the distinct current-canonical versus legacy PAMB paths now have endpoint evidence; concurrent races and live browser presentation remain unverified.

## Date-correction rejection trace — 2026-10-05

### Evidence and cause

The supplied screenshot shows the parent dates in the requested order — CENRO Released September 28, PENRO Received September 29, Regional Endorsed September 30 — plus the old generic error and the internal time controls. It does not show the full internal event dates, cycle suffixes, request payload, or the lower controls, so it cannot identify the owner record's exact conflicting event. I did not access that operational record.

The correction modal initializes `internal_events` from every populated `row.routing_timeline` item marked internal, using the persisted `stage_key`, including cycle suffixes. The request submits that whole map with the business dates. `localDateTimeInputValue()` converts ISO timestamps to Asia/Manila and emits `YYYY-MM-DDTHH:mm`, so an unchanged event is submitted at minute precision even if storage has non-zero seconds.

`SubmissionTrackingController::correctRouting()` validates the map and each value as a nullable date-time, then forwards the validated strings to the service. Laravel's empty-string middleware converts blank values to null. `PremiumTimePicker` displays the time portion while retaining the event date in its controlled value. The service now parses date-times explicitly in Asia/Manila; empty/null values remain null and still hit the existing completed-event guard when applicable.

The demonstrated rejection was a date/time precision mismatch in `RoutingCorrectionService::validateInternalChronology()`. It compared the date-only `date_endorsed_regional` value `2026-09-30` as a midnight timestamp against an unchanged `received_by_records_final__cycle_2` event at `2026-09-30 13:00:00`. That made a same-business-day event appear later than Regional Endorsed and caused the generic chronological error. An isolated second-cycle fixture with the same three parent dates asserts that the old midnight comparison returns “earlier,” while the corrected endpoint accepts a CENRO date-only change from September 29 to September 28.

The minute-only request also exposed a related preservation issue: a stored `12:00:37` value round-tripped through the picker as `12:00` and would have been saved as `12:00:00` during an unrelated date correction. The service now treats a submitted value with the same Manila calendar minute as unchanged and retains its stored seconds and audit state.

### Narrow correction and errors

Date-only PENRO receipt and Regional Endorsed boundaries now compare by Manila business date. Same-day internal actions pass those boundaries; an action on a prior receipt date or a later endorsement date still fails. Ordering between internal actions remains an exact datetime comparison. The validator retains the persisted event key and cycle when selecting and reporting the field; the completed-date clearing guard, parent date chronology, routing rules, custody, and actor checks are unchanged.

Chronology failures now have one concise modal summary and an exact field error. The tested earlier-action error reads:

> Received by PENRO Records (cycle 2) (September 30, 2026 at 11:59 AM Asia/Manila) is earlier than Forwarded to PENRO Records (cycle 2) (September 30, 2026 at 12:00:37 PM Asia/Manila). Set this action time to the previous action's time or later.

A tested post-endorsement error reads:

> Received by PENRO Records (cycle 2) (October 1, 2026 at 9:00 AM Asia/Manila) is after Regional Endorsed date (September 30, 2026). Set the action date on or before the endorsement date.

The generic summary contains no duplicate of the action-specific field detail, and neither message uses “chronological.” Rejected submissions retain their form state through the existing Inertia validation flow.

### Verification

- Isolated SQLite/fake-provider coverage exercises the corrected September 29 → 28 date-only submission against completed Regular PAMB, Special PAMB, and TWC second-cycle histories. All unchanged PAMB event IDs, keys, cycles, actors, timestamps (including seconds), and correction rows are compared. The current shared canonical route remains covered by the existing completed-workflow correction test; its path has no legacy internal-event payload.
- Changed-time cases verify rejection before the preceding forwarding action and after the Regional Endorsed date. They assert the exact cycle-two field errors and no changes to source dates, PAMB events, correction rows, or audit rows. Existing authorization and completed-date protection checks ran in the same affected suite.
- A rendered modal test verifies a concise summary does not repeat the field detail. The time-picker helper test verifies offset-to-Manila conversion and its minute-precision payload.
- Affected backend suites (`RoutingCorrectionCycleIdentityTest`, `PambFinalVerdictTest`, `RoutingCorrectionSourceCoverageTest`, and `SubmissionTrackingTest`): **44 tests, 882 assertions — passed**.
- Focused rendered/helper JavaScript suites: **41 tests — passed**. The Vite test server logged the existing port `24678` in-use warning; it did not fail the suite.
- `npm.cmd run build`: **passed**, 1,431 modules transformed. PHP syntax checks and `git diff --check` passed.

These are isolated backend HTTP/Inertia and server-rendered component checks, not live-browser verification. The known August formatter expectation mismatch was not changed or re-audited. No operational record, archive service, notification delivery, migration, or live browser session was used.

**Date-correction trace status: PARTIAL.** The same-date false-rejection class was demonstrated and fixed in isolated fixtures, with specific adjacent-action errors for genuinely invalid internal edits. The owner's exact rejected request remains unverified because the screenshot does not expose the internal event dates/times, selected cycle, or submitted payload.

## Routing correction preservation and Google Drive capacity follow-up — 2026-10-05

### Date-correction preservation

The current `RoutingCorrectionService` diff still contains the previously verified rules: receipt and regional endorsement boundaries compare by Asia/Manila business date; internal events compare by exact timestamp and retain their cycle-specific persisted keys; an unchanged minute-only picker value preserves stored seconds; chronology errors identify the adjacent actions and dates/times; and the completed-date clearing guard remains in place. I made no routing, role, permission, workflow, or operational-record changes in this follow-up.

**Correction-preservation status: PASS.** The earlier isolated test and build results above are historical and were not rerun here. The exact owner-record submission remains **PARTIAL / UNVERIFIED** for the reasons recorded in the preceding trace section.

### Google Drive capacity evidence and cause

The supplied Storage screenshot summary shows the `CDS-SMART Final Reports` root label, `Capacity Unavailable`, `Not reported`, no quota figures, a generic safe-retrieval message, and a 10-minute cache. The root label is static UI copy; by itself it does not prove current root access or a successful quota request.

Read-only local configuration checks found the archive-enabled flag present and true in `.env`; the booted application also resolved the `google-drive` driver and enabled integration. All four required Google configuration fields were present. Only presence/boolean facts were inspected; no credential values, identifiers, OAuth scopes, or provider response bodies were displayed.

I invoked the existing gateway's read-only quota and configured-root validation helpers, with its transient access-token cache held in process memory. The initial sanitized HTTP trace recorded two `POST` requests to the OAuth token endpoint, each returning **HTTP 400**. It recorded no request to `drive/v3/about` and no configured-root metadata request: token exchange failed before either Drive GET could be sent. A single later fresh token refresh was inspected privately and identified the OAuth error code as `invalid_grant`; the status, code, and sanitized explanation are recorded below. The full response and its description were not written to the report. No credential was changed.

The root's live accessibility, granted Drive scopes, and current quota fields remain **unverified** because no authenticated Drive request completed. This result does not establish that archival uploads or checkpoints failed. No archive write, folder lookup/create, file read, sharing change, checkpoint change, or hierarchy change was performed. The existing unit → CENRO office → canonical module hierarchy and `CDS-SMART Final Reports` root remain untouched.

### Narrow capacity parsing correction

The Google Drive About resource documents quota values in bytes and says `storageQuota.limit` is omitted when a user has unlimited storage ([Google Drive About resource](https://developers.google.com/workspace/drive/api/reference/rest/v3/about)). The capacity provider previously treated every omitted limit as a provider failure, even when Google returned valid usage. That is a separate, confirmed parser defect; it did not cause the observed HTTP 400 token failure.

`GoogleDriveStorageCapacityProvider` now reports usage as available when the limit field is absent, while leaving total, free, and percentage values null and explaining that those values were not reported. It continues to fail closed for missing or nonnumeric usage, an explicit null or invalid limit, and a zero/nonpositive reported limit. It never substitutes a zero limit, free-space value, or usage percentage.

### Verification and status

- `php artisan test tests/Feature/StorageCapacityTest.php`: **14 tests, 189 assertions — passed** using the suite's SQLite in-memory database and array cache. Coverage includes an omitted unlimited limit, missing/invalid quota fields, zero and explicit-null limits, provider errors, capacity-cache reuse/refresh, and denial of refresh to a non-Super Admin.
- **One fresh OAuth refresh:** HTTP status **400**; OAuth error code **`invalid_grant`**. Sanitized explanation and next owner action: the existing refresh authorization is no longer accepted. Google directs the application to authenticate the user again and request consent for new tokens; the owner should reauthorize the Google account through the approved OAuth flow and securely replace only the existing refresh-token value with the newly issued one. If `invalid_grant` persists afterward, Google advises verifying the configured client, token, and request parameters and confirming the Google account remains enabled ([Google OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server)).
- The fresh check made exactly one token refresh request and no Drive API calls. The response was parsed in memory for its error code; no token, secret, request body, headers, response body, or account identifier was printed or saved. Quota and configured-root access remain unverified until the owner completes the OAuth action.
- No credentials, routing rules, roles, archive checkpoints, Drive folders, files, or permissions were modified. The preceding date-correction test results remain historical; this follow-up did not rerun them.

**Google Drive capacity status: PARTIAL.** The live failure is localized to OAuth token exchange and identified as `invalid_grant`. Reauthorization is the next owner action; Drive root/quota access remains unverified until it succeeds. The independent omitted-limit parser defect is fixed and covered. The displayed `Capacity Unavailable` state remains accurate while the current token exchange is rejected.

## Reviewed checkpoint — 2026-10-05

### Included scope and exclusions

This checkpoint reviews the current changes based on `104eba3fef628c6723ba3f74e7e1cf834a23e2cd` on `main`. It includes the compatible dependency lockfile updates and audit note; the safe PowerShell updater and isolated test harness; Calendar controls, render/scroll changes, and tests; the narrow routing correction, authority/override protections, completed-date guard, date-precision fixes, and endpoint coverage; the Drive capacity parser correction; and the related consolidated reports. The adopted routing rules, actor authority, handoffs, cycles, MOV gates, PENRO receipt, progress, History, archive checkpoints, and Drive hierarchy were preserved.

`.env`, credentials and secrets, `.recovery/`, temporary probes/logs, generated `public/build/`, `node_modules/`, and unrelated changes were excluded. `.env` remains ignored and untouched; `.recovery/` was absent. The production build output is ignored. No live operational record, migration, seed, notification, Drive object, folder, or archive checkpoint was changed. The updater itself was not run against this dirty checkout; its safety harness ran in disposable repositories.

### Checkpoint verification run

- Targeted PHP feature suites: **165 tests, 5,598 assertions — passed**. PHPUnit used SQLite `:memory:`, array cache, blank `DB_URL`, and test fakes.
- Full JavaScript inventory: **133 passed, 1 failed (134 total)**. Calendar controls and sparse/populated Calendar rendering checks passed. The single failure is the existing `tests/js/dateFormatters.test.mjs` expectation `Aug 29, 2026` versus actual `August 29, 2026`; it was left unchanged. Test workers also reported that WebSocket port `24678` was already in use; this warning did not fail the passing tests.
- Safe updater harness: **21 cases passed**, including dirty/staged rejection, `.recovery/` and `.env` protection, collision/divergence handling, hook-free dependency install, and no migration execution.
- `npm.cmd run build`: **passed**, Vite transformed 1,431 modules. Generated output remained ignored.
- `php -l` on changed PHP application/test files, PowerShell parsing for the updater and harness, and `git diff --check`: **passed**.

The earlier test counts in this report are historical results from their respective investigations. The results in this checkpoint subsection were rerun against the reviewed current tree. The dependency audit still records six unresolved advisory entries through `braces@3.0.3`; no forced Tailwind major-version update was taken.

### Deferred items and evidence limits

Office OAuth remediation is deferred. The latest one-request token refresh returned **HTTP 400**, OAuth code **`invalid_grant`**. The supported next owner action remains reauthorization through the approved OAuth flow and secure replacement of the existing refresh-token value with the newly issued one. The owner reports that updated authorization is on the home machine; office credentials are not synchronized or verified. This reported difference does **not** establish the cause of `invalid_grant`. No credential was changed. Drive root access and quota remain unverified until a Drive request succeeds.

The Calendar has no live-browser scroll or click-through verification. The exact owner date-correction submission remains unverified without the internal event dates/times, selected cycle, and submitted payload; simultaneous correction-versus-transition requests were not separately exercised. Office OPcache ini/backup work is local and outside Git; restart and web-process activation were not verified by this checkpoint.
