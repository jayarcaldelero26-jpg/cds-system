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

## All active report activity and routing date audit — 2026-10-05

**Investigation status: PARTIAL.** This is a read-only audit at `f9602791ff1305a48d52632ec892a186704e71dc`. Current source, routes, 42 active/nonretired module-definition codes, selected isolated tests, and one narrowly scoped operational record were inspected. The current worktree's separate `ConservationReportSubmissionController.php` edit only assigns the Inertia response to a variable before returning it; it changes no date rule. `.recovery/`, the home `.env`, routing records, credentials, and application code were not changed in this pass. Historical test claims elsewhere in this document are not current reruns.

### Scope and contract legend

The ten Submission Tracking sources are `conservation`, `engp`, `bms`, `bams`, `imea`, `imea-maintenance`, `aws`, `ipaf-management`, `revenue`, and `management-plans` (`SubmissionTrackingService::sources()`, lines 974–986). A read-only query of `module_definitions` found **42 active, nonretired definitions**: 22 Conservation workflow codes, 12 ENGP codes, and eight other tracked modules. Route existence and active module rows were both checked. Technical Reports and the retired/unrouted codes in `ModuleDefinition::RETIRED_CODES` are excluded.

The compact matrix below uses these traced contracts, rather than assuming that a shared component proves each controller:

| Contract | Input, persistence, create/edit rule | Presentation and routing source |
|---|---|---|
| **C** Conservation (`ConservationReportSubmissionController`, `ConservationReportSubmission`, `Bms/ReportSubmissionTracker.jsx`) | Meeting PAMB requires manual `date_conducted`; `date_accomplished` is optional and cannot precede conducted. Other workflows require manual accomplished. Four workflows (`homestay`, `additional_bms_site`, `bdfe_terrestrial`, `maintenance_pamo_ecotourism`) require structured conducted ranges; the range service stores the first `from` as legacy `date_conducted`. Create requires MOV; update validates dates and is denied after routing completion. The local controller edit does not alter this. | Module list/details show the entered dates and derived deadline/timeliness; Submission Tracking normalizes real date columns and preserves descriptive conducted text. PAMB deadline uses `date_accomplished`, falling back to `date_conducted`, per `PambComplianceCalculator::authoritativeDate`; other deadlines use accomplished. Canonical routing actions set release, receipt, and regional business dates from the Asia/Manila server clock; `DocumentRoutingEvent.occurred_at` keeps the action time. |
| **E** ENGP (`EngpReportController`, `EngpReportSubmission`, `Engp/Index.jsx`) | Manual reporting year/period and office; no parent actual-activity date. Deadline is derived by registry, not accepted from the form. Create may update an existing same-period record but checks mutability; ordinary update rejects routing fields. MOV is optional. | ENGP module list/details show period, derived deadline, component release events (where present), and parent PENRO receipt. New canonical custody uses shared routing events; component releases remain event-owned legacy/specialized dates, never parent `date_report_released_cenro`. Regional completion is a routing event projection, not an ENGP parent column. |
| **B** BMS (`BmsReportSubmissionController`, `BmsReportSubmission`, `Bms/ReportSubmissionTracker.jsx`) | Manual conducted ranges and required accomplished date; semester/quarter and optional monitoring coverage are different period fields. Create requires MOV; update checks mutability and uses the same validation/range normalization. | List/details use `date_conducted_display` and accomplished; tracking projects separate automatic routing dates and calculated 15-working-day deadline. |
| **A** BAMS/IMEA (`StandardAReportSubmissionController`, source-specific subclasses/models, shared tracker) | Both subclasses inherit actual controller store/update. Manual conducted ranges and required accomplished date; create requires MOV, update checks mutability; range service retains first `from` as legacy conducted. | Shared list/details show ranges and accomplished; tracking uses each exact source/model and automatic canonical milestones. Inheritance was checked in both subclasses, not inferred solely from UI reuse. |
| **M** IMEA maintenance (`ImeaFacilityMaintenanceReportController`, `ImeaFacilityMaintenanceReport`, `Imea/MaintenanceReports.jsx`) | Manual conducted and accomplished dates required; quarter is a period, not an activity date. MOV required on create, optional replacement on edit; update checks mutability. | Specialized list/details show both dates and calculated deadline; tracking supplies automatic routing dates. |
| **W** AWS (`AwsController`, `Aws`, `AWS/AwsReportSubmissionTracker.jsx`) | Manual conducted text/date and optional accomplished; monitoring start/end and observational start/end are separate coverage fields. `deriveCanonicalPeriod()` fills missing start/end from a canonical conducted date, retains existing period on edit, then derives year/quarter. Report file rules differ from MOV modules. | Module report list and tracking show conducted/optional accomplished and canonical period. An absent accomplished date is not fabricated; tracking eligibility and projections follow the AWS source contract. |
| **I** IPAF management (`IpafController`, `IpafManagementReport`, `Ipaf/Index.jsx`) | Manual conducted and accomplished required; create requires MOV; edit checks mutability and preserves attachment unless replaced. | Specialized list/details and tracking show actual dates and derived seven-working-day deadline; routing dates are automatic. |
| **R** Revenue (`IpafController`, `IpafRevenueCollection`, `Ipaf/Index.jsx`) | Reporting month/year and manual `deadline_submission` are financial period/deadline inputs, not actual activity dates. Create requires MOV; edit checks mutability. | List/details show period, deadline, receipt, and financial compliance; tracking does not require `date_accomplished`. Routing dates are automatic. |
| **P** Management Plans report (`ManagementPlanController`, `ManagementPlan`, `ManagementPlans/Form.jsx`) | Manual conducted and accomplished required; semester is a period. Create requires attachment; edit retains/removes attachments by its explicit document flow and rejects posted routing fields. | Form/list/details show actual dates and derived compliance. Tracking uses separate automatic routing dates. Profile planning/approval dates belong to the adjacent plan-profile form, not this report's custody milestones. |

### Full active-module coverage matrix

Every row below was matched to an active `module_definitions.code`, its actual route/controller contract above, and the indicated source. **S** means current source trace; **T** means current isolated test coverage for at least the named family, not an individual end-to-end payload for every row. **G** means a per-workflow create/edit/browser fixture remains a gap. The shared display mappings are stated in the contract table; this matrix does not claim each row was independently clicked through.

The 22 Conservation codes share the CENRO-origin profile except where `ProtectedAreaRoutingPolicy` identifies Mt. Hamiguitan Range Wildlife Sanctuary (including its MHRWS alias); those records use the direct-to-PENRO origin profile and omit CENRO release. This applies to Regular/Special/TWC PAMB and generic Conservation workflows by protected-area identity, not by an extra workflow key. `ConservationMeetingSharedRoutingTest` covers the direct-to-PENRO meeting route; the generic direct profile is source-traced and has historical parity evidence, but was not independently rerun for every workflow here. ENGP has monthly, quarterly, and weekly period definitions: quarterly releases may have monthly component identities, while other legacy component definitions use the period identity. Current canonical ENGP custody can proceed with zero legacy component-release rows, as `EngpReportTest` exercises; component correction must still address an existing event belonging to the exact parent.

| Active code / workflow | Source; contract | Current evidence and remaining gap |
|---|---|---|
| `regular_pamb` | conservation; C | S, T: meeting input, shared routing, date correction; G: browser date entry |
| `special_pamb` | conservation; C | S, T: meeting input and shared routing; G: browser date entry |
| `twc_meetings` | conservation; C | S, T: meeting input and shared routing; G: browser date entry |
| `homestay` | conservation; C, ranges | S, T: period/deadline, update and range path; G: browser range entry |
| `maintenance_monuments` | conservation; C | S, T: workflow/deadline; G: own create/edit date fixture |
| `maintenance_buoy` | conservation; C | S, T: registry; G: own create/edit date fixture |
| `updating_pamp` | conservation; C | S, T: registry; G: own create/edit date fixture |
| `restoration_plan_5_year` | conservation; C | S, T: registry; G: own create/edit date fixture |
| `additional_bms_site` | conservation; C, ranges | S, T: registry/deadline; G: own range payload fixture |
| `cepa_plan` | conservation; C | S, T: activity/document-specific deadline rules; G: own date payload fixture |
| `vtol_operations` | conservation; C | S, T: registry/deadline; G: own date payload fixture |
| `bdfe_terrestrial` | conservation; C, ranges | S, T: registry/deadline; G: own range payload fixture |
| `bdfap` | conservation; C | S, T: registry/deadline; G: own date payload fixture |
| `maintenance_pamo_ecotourism` | conservation; C, ranges | S, T: registry; G: own range payload fixture |
| `rehabilitation_pa_office` | conservation; C | S, T: registry; G: own date payload fixture |
| `ecotourism_management_plan` | conservation; C | S, T: registry; G: own date payload fixture |
| `updating_pamb_manual` | conservation; C | S, T: final-document deadline; G: own date payload fixture |
| `management_effectiveness_assessment` | conservation; C | S, T: registry; G: own date payload fixture |
| `maintenance_pa_information_system` | conservation; C | S, T: registry; G: own date payload fixture |
| `monitoring_mangroves_corals_seagrass` | conservation; C | S, T: registry; G: own date payload fixture |
| `water_quality_monitoring` | conservation; C | S, T: registry; G: own date payload fixture |
| `mpan` | conservation; C | S, T: registry; G: own date payload fixture |
| `engp_cbep` / `cbep` | engp; E | S, T: registry/ENGP family; G: own period/update fixture |
| `engp_elcac` / `elcac` | engp; E | S, T: registry/ENGP family; G: own period/update fixture |
| `engp_ngp_staff_accomplishment` / `ngp_staff_accomplishment` | engp; E | S, T: office exception; G: own period/update fixture |
| `engp_forest_disturbance` / `forest_disturbance` | engp; E | S, T: registry/ENGP family; G: own period/update fixture |
| `engp_monthly_accomplishment_pmd_fmb` / `monthly_accomplishment_pmd_fmb` | engp; E | S, T: registry/ENGP family; G: own period/update fixture |
| `engp_cenro_nursery_seedling` / `cenro_nursery_seedling` | engp; E | S, T: registry/ENGP family; G: own period/update fixture |
| `engp_tree_replacement` / `tree_replacement` | engp; E | S, T: registry/ENGP family; G: own period/update fixture |
| `engp_rims` / `rims` | engp; E | S, T: January deadline exception; G: own update fixture |
| `engp_ngp_produce` / `ngp_produce` | engp; E | S, T: quarterly component contract; G: event UI payload fixture |
| `engp_nursery_maintenance` / `nursery_maintenance` | engp; E | S, T: quarterly registry; G: own update fixture |
| `engp_site_visit` / `site_visit` | engp; E | S, T: quarterly registry; G: own update fixture |
| `engp_weekly_accomplishment` / `weekly_accomplishment` | engp; E | S, T: weekly registry and summary exclusion; G: own update fixture |
| `bms` | bms; B | S, T: isolated BMS date/import coverage; G: full browser create/edit |
| `bams` | bams; A | S: subclass/controller/model traced; G: own date payload fixture |
| `imea` | imea; A | S: subclass/controller/model traced; G: own date payload fixture |
| `imea_facility_maintenance` | imea-maintenance; M | S, T: maintenance family; G: full browser create/edit |
| `automated_weather_station` | aws; W | S, T: reporting-period path; G: date entry/replacement browser fixture |
| `ipaf_management` | ipaf-management; I | S: own controller/form/model traced; G: date payload fixture |
| `revenue_collection` | revenue; R | S: own controller/form/model traced; G: deadline edit fixture |
| `management_plans` | management-plans; P | S, T: report and profile family; G: browser attachment/date edit |

Other active forms outside the ten tracking sources were not silently treated as routed reports: BMS monitoring records and Threats (`BmsController`, `BmsThreatController`), BAMS flora/fauna assessment (`BamsAssessmentController`), IMEA assessments/facility inventory (`ImeaAssessmentController`), AWS observations/import, Management Plan profiles (`ManagementPlanProfileController`), and IPAF targets/accounting. Their dates represent observations, assessments, inventory, planning/approval, financial periods, or related evidence. They have distinct routes and fields; none should inherit a blanket routed-report actual-date rule. Their full field-by-field UI audit is a remaining gap outside the 42 tracked report definitions.

### Date flow and provenance findings

1. **Confirmed correction-form contract defect, high confidence, ENGP only; fixed in the follow-up below.** `SubmissionTrackingService.php:1003,1078-1080` exposes an ENGP aggregate CENRO release and computed regional completion as display fields. Before the follow-up, `SubmissionTracking/Index.jsx` copied all three display routing fields into `correctionForm.data.dates`; `SubmissionTrackingController.php:333-354` passed the validated array through, while `RoutingCorrectionService.php:45-49` rejected every ENGP parent field except `date_received_penro`, even when unchanged or blank. The focused follow-up now submits only the parent PENRO receipt, filters component release keys to event IDs on the selected ENGP report, and displays aggregate release/regional dates read-only. The backend allow-list and event ownership checks remain unchanged; verification is recorded below. No parent release date is invented.
2. **Owner record discrepancy confirmed; supply path unknown.** The read-only, exact-source inspection of `conservation-37` found `regular_pamb`, conducted and accomplished **2026-10-07**, created **2026-10-03 14:34:05** Asia/Manila, with current CENRO release **2026-10-02**, PENRO receipt and regional endorsement **2026-10-03**. Nineteen shared routing events span October 3, ending at 18:08:15; no legacy PAMB event rows were found. The sole `SubmissionRoutingCorrection` row changed CENRO release **2026-10-03 → 2026-10-02** on October 5. Six record audit entries cover creation, attachments, and MOV review but do not contain the original conducted/accomplished payload. The activity-date discrepancy therefore predates the routing-date correction. `ConservationReportSubmissionController.php:164-169` accepts future actual dates and only checks accomplished >= conducted for meeting workflows; `DatePicker.jsx:23-34` opens on today's month but does not select today without a click. This establishes an accepted future-date behavior and the mismatch, **not who selected/supplied October 7**. No operational row was edited.
3. **Accepted contract, not a defect finding:** ordinary Release/Receive/Forward/Endorse actions take the Asia/Manila server date (`DocumentRoutingTransitionService.php:439-441`), while their event rows keep `occurred_at` (`:241-250`). Administrative Correct routing is separate and may replace a business date without rewriting the original event time. The current CLI bootstrap reported app/PHP timezone `Asia/Manila`; the Windows clock read `2026-10-05 19:51 +08:00`. ENGP deadlines are derived from period/year; Revenue's deadline is a user-entered financial target. Neither is an already-performed activity date.
4. **Projection/selection checks:** `SubmissionTrackingService.php:1005-1113` maps source dates and distinguishes routing-complete `completed_at` from activity dates; descriptive conducted strings are preserved by `DatePresentationNormalizer`. `submissionDetailContext.js:19-35` matches selection by exact `source:source_id`, preventing numeric-ID cross-source reuse in the tested refresh path. `Index.jsx:1168-1201` resets correction dates/reason/password on open. Date and range pickers show today's month when empty but do not commit a value merely on open; a range requires deliberate Apply. The time-picker utility retains an existing date while refreshing draft time, and the existing completed-date guard/cycle identity tests passed. A live authenticated click-through, every module's side panel/full-details mapping, simultaneous transition/correction race, and every alias/fallback path remain unverified.
5. **Semantic risk needing owner decision, not an automatic rule change:** Required Conservation/PAMB, BMS, BAMS, IMEA, IMEA maintenance, IPAF management, and Management Plans actual-activity fields accept future valid dates; AWS accomplished is optional and ENGP/Revenue have no such field. If an actual date must mean already performed, compare its calendar date to the Asia/Manila server date at create/update, including edits after partial routing, but only after the owner confirms how advance reporting/planned activity is represented. Keep planned periods, ENGP deadlines, Revenue deadlines, and optional blanks outside that rule. The tested record demonstrates why routing chronology and activity-date semantics must be considered separately. Do not infer mistaken user input from the audit gap.
6. **Known test drift, lower priority:** Current `tests/js/dateFormatters.test.mjs:10` expects `Aug 29, 2026` while `resources/js/Utils/dateFormatters.js:30` intentionally formats a long month (`August 29, 2026`). This is a test/format contract mismatch, previously recorded in the reviewed checkpoint; it does not establish incorrect date persistence. Decide the desired display label before changing either side.

### Checks run and next correction

- Current `phpunit.xml` specifies `APP_ENV=testing`, SQLite `:memory:`, blank `DB_URL`, array cache/session, and sync queue. Selected suites use fake local/public storage and fake uploads; no operational fixture writes or provider writes were made. Eight selected PHP files (Conservation workflows, ENGP, BMS import dates, AWS periods, IMEA facilities, Management Plans, date normalization, date-input contract): **166 tests / 690 assertions passed**. Three further files (routing correction cycle identity, tracking details projection, tracking presentation): **23 tests / 529 assertions passed**. These are current isolated runs, not exhaustive source × workflow × action tests.
- Current Node checks for source/ID selection and premium time picker passed **22 of 23** through the ordinary runner; the one failure is the known long-month expectation above. The restricted runner first returned `spawn EPERM` before tests executed. No browser click-through was run.
- Priority 1, ENGP correction-form field mapping: **implemented and covered by the follow-up checks below**. Priority 2 remains: resolve whether actual activity dates can validly be future dates and, if needed, add source/field-specific validation without changing planned dates or automatic routing. Priority 3 remains: close missing per-workflow create/edit and browser display fixtures, and reconcile the date-format test expectation. Keep canonical Conservation/BMS routing, direct-to-PENRO profiles, named actor/office/PA checks, correction cycles, MOV gates, PENRO receipt authority, progress, terminal History, completed-date guard, archive checkpoints, and Drive hierarchy unchanged.

### ENGP correction-form follow-up — 2026-10-05

The confirmed ENGP form defect was corrected in the existing Submission Tracking form. Opening a correction now initializes only `date_received_penro` as an editable parent date for ENGP. The aggregate CENRO release and computed regional endorsement remain visible as read-only values. On submit, the form keeps only the supported ENGP parent date, limits release-date payload keys to component-event IDs present on the selected report, and clears unsupported internal-event payloads. The server's source allow-list, per-report event ownership validation, date chronology, authorization, reason/password checks, and completed-receipt clear guard were not loosened. Other source correction payloads retain their prior mapping.

Coverage added or extended:

- The form helper tests selected and blank ENGP projections, filtering of stale component IDs, preservation of reason/password, no synthetic event creation, source switching, unchanged non-ENGP payload identity, and wiring from the real submit callback.
- Isolated HTTP feature tests cover an authorized parent receipt correction when there are no legacy component events; unauthorized actor rejection; invalid-date rejection; completed receipt clear rejection; and unchanged fields on rejected requests.
- The source-coverage fixture now has a second component release and verifies that correcting one event and the parent receipt preserves both event identities, the unrelated event date, and the routing action timestamp/history.
- Existing tests continue to reject unsupported parent milestones and malformed, foreign, or unauthorized component event IDs. No browser click-through was run; the UI path is covered by helper tests, source wiring assertions, and production compilation, while server behavior is covered by the isolated HTTP feature tests.

Verification on the current worktree:

- `php artisan test tests/Feature/RoutingCorrectionCycleIdentityTest.php tests/Feature/RoutingCorrectionSourceCoverageTest.php tests/Feature/EngpReportTest.php --compact`: **43 tests / 681 assertions passed**.
- `node --test tests/js/routingCorrectionForm.test.mjs`: **4 tests passed**.
- Full JavaScript/frontend inventory: **137 passed, 1 failed of 138**. The sole failure remains the known `dateFormatters.test.mjs` expectation `Aug 29, 2026` versus actual `August 29, 2026`; it was not changed. Vite also warned that WebSocket port `24678` was already in use and reported an `EPERM` while renaming its dependency-optimization temporary directory during the Calendar test; the inventory completed, with the date-format assertion as the test failure.
- `npm.cmd run build`: **passed** through the package build script, including its existing pre/post `clean-vite-hot.js` steps; Vite transformed 1,432 modules. `public/build/manifest.json` contains the Submission Tracking entry and its emitted asset exists. Generated build output remains ignored.
- PHP syntax checks on both changed feature-test files and `git diff --check`: **passed**.

The broader active-module audit remains **PARTIAL**: its explicit module coverage and remaining browser/workflow evidence gaps above still apply. This ENGP correction changes only the administrative correction form and does not change canonical routing behavior or actual-activity date rules.

### PAMB screenshot date and terminal-presentation follow-up — 2026-10-05

The attached screenshot shows a Regular PAMB report with activity dates of **2026-10-07**, CENRO release on **2026-10-02**, PENRO receipt and regional endorsement on **2026-10-03**, and an overall **Completed** state. Its final regional row was nevertheless shown as **Current** with **Pending Since Oct 3 · 1 working days**. The recorded activity-date values remain unexplained: available record audit entries do not contain the original create/edit payload or identify who selected or supplied October 7. The date picker's initial view of today's month is not a date selection; it changes the value only after an explicit date choice. No operational record was edited and no cause is attributed to the routing-date correction.

The PAMB date basis in the source is `date_accomplished`, falling back to `date_conducted` (`PambComplianceCalculator::authoritativeDate`). The earlier matrix description above is corrected accordingly. The PAMB date-entry trace found that a new form starts with blank actual-date values, edit loads persisted values, opening the picker does not commit a date, and submit sends the selected values. The screenshot therefore confirms a stored future activity date, not how it entered storage. Future-date validation remains unchanged pending an owner decision about planned or advance reporting.

The narrow presentation correction keeps routing state and history intact. For a terminal **Released / Endorsed to Regional** row, the shared presenter now supplies a completed display status and label and omits pending-since/working-day age. The original event timestamp, stage, current location, event ordering, and internal route-resolution status remain available and unchanged; route actions were already absent at completion, and the administrative override card is hidden when no action is available. Active custody stages continue to show **Current**, pending age, and actions even when progress is 100%.

The MOV turnaround display now stops its live countdown at the applicable terminal milestone: CENRO release for the non-direct route, or PENRO receipt for the direct-to-PENRO route. It retains the actual milestone and review verdict. **Ready for Release** alone remains active and does not complete MOV. Coverage explicitly includes Regular PAMB, Special PAMB, TWC, and direct-PENRO routing; the shared terminal presentation contract applies to all ten Submission Tracking sources in the matrix above: Conservation, ENGP, BMS, BAMS, IMEA, IMEA maintenance, AWS, IPAF management, Revenue, and Management Plans.

The authorized routing and correction boundaries were exercised without changing server authority. A stale custody release by the former actor and the same attempted release by a global administrator are rejected without writes; the completed record remains in History and has no routing action available. The authorized date-only CENRO business-date correction remains available, changes no activity date or event timestamp, and preserves the completed History entry. No routing guard, credential, operational setting, migration, or seed data was changed.

Verification on the current worktree:

- PAMB screenshot/MOV/routing timeline feature suites: **64 tests / 916 assertions passed**. Submission Tracking routing contract suite: **6 tests / 92 assertions passed**. Combined: **70 tests / 1,008 assertions passed** on SQLite in-memory fixtures.
- Rendered Submission Tracking preview contracts: **21 tests passed**, including terminal-completed and active-at-100%-progress cases. PHP syntax checks and `git diff --check`: **passed**.
- `npm.cmd run build`: **passed** using the package build script `node scripts/clean-vite-hot.js && vite build && node scripts/clean-vite-hot.js`; Vite transformed 1,432 modules. `public/build/manifest.json` and the Submission Tracking entry's emitted asset exist, and `public/hot` is absent after the post-build cleanup.

These checks verify code projections and isolated request behavior, not a live authenticated browser workflow or the provenance of the screenshot's activity-date values. Keep the broader audit status **PARTIAL** until its listed browser/per-workflow gaps and the actual-date semantics question are resolved.

### Video Project 11 Receive-wait follow-up — 2026-10-05

**Finding: the visible wait is confirmed in the recording, but its original request-phase cause is not reproduced.** The trace below covers the exact route key and callback for the PENRO CDS Chief Receive. The written video observations identify the later completed report as Maintenance of Monuments but do not record its submitted `source_id`; the isolated fixture used `conservation:1`, a synthetic test identity, and is not asserted to be the recording's live record.

| Phase | Source trace and result |
|---|---|
| Displayed action and submit | `SubmissionTracking/Index.jsx` posts `form.data.stage` to `submission-tracking.transition` using `selected.source` and `selected.source_id`. For the depicted receive, the route stage is `receive_at_cds_chief`. The success message shown after it is a separate later `recommend_to_office_penro` submission. |
| Controller and state mutation | `SubmissionTrackingController::transition` checks source access and the current generic action list, validates the exact stage, and calls `transitionWithAttachment`. With no file selected, there is no attachment storage write. The transition runs inside a database transaction, locks the row, rechecks actor authorization and current stage, appends one `DocumentRoutingEvent`, and rejects stale/repeated actions before writing. |
| Lifecycle and provider boundary | `RoutingTransitionLifecycle` calls the archive hook for the event, but the hook returns `not_records_checkpoint` unless it is the specific `forward_to_office_penro` event from PENRO Records to the Office of the PENRO. `receive_at_cds_chief` and `recommend_to_office_penro` do not match that checkpoint. Neither calls the Google Drive gateway. The two successful events can still issue their normal in-app database notification. |
| Redirect, refresh, and busy UI | The controller responds with a back redirect. Inertia follows it with a GET that rebuilds the tracking page. `useForm.processing` begins at visit start and is cleared on finish; `CrudFormModal` shows **Preparing / Validating** and **Updating**, disables save/back/close, and suppresses Escape while processing. Thus the recorded busy state spans the POST and redirected GET; it is not a timer for the database mutation alone. Errors remain visible after finish. There is no modal cancel control while processing. |
| Selection continuation | After a successful visit, the page finds the fresh incoming row by both `source` and `source_id`. The isolated GET confirmed that `conservation:1` remained selected at PENRO CDS Chief with separate Return and Recommend actions. Existing selection tests also cover colliding numeric IDs across sources and closing revoked selections. |

The isolated HTTP replay used the Pest `RefreshDatabase` SQLite fixture, fake local/public storage, and a `GoogleDriveArchiveGateway` mock configured to fail the test if any provider method was called. It seeded only the preceding forward-to-CDS-Chief event; the Receive and Recommend steps themselves used separate requests to the real controller route. The initial response exposed only `receive_at_cds_chief`. Receive returned a redirect and appended one event; repeating that POST returned a `stage` validation error and did not append an event. A separate selected-row GET returned the PENRO CDS Chief state with `return_to_penro_cds_focal` and `recommend_to_office_penro`. Recommend then returned its own redirect and appended a second event; repeating it was also rejected without another event. No archive provider call occurred across the requests or page GET.

For one warmed local test run, the sanitized opt-in `CDS_RECEIVE_REPLAY_TIMINGS=1` output measured **104.73 ms** for the Receive POST, **85.06 ms** for the redirect-refresh GET, **49.60 ms** for the separate Recommend POST, and 58.32 / 44.64 ms for duplicate rejected POSTs. These timings are Laravel feature-test/kernel measurements on a one-record SQLite fixture; they exclude a real web server, browser network, and production data volume. They do not reproduce or explain the estimated roughly 41-second original wait in the accelerated recording. The route/UI code was not changed.

Checks: `php artisan test tests/Feature/SubmissionTrackingReceiveRoundTripTest.php --compact` **1 test / 69 assertions passed**; `node --test tests/js/submissionDetailContext.test.mjs` **21 passed**; `node --test tests/frontend/SubmissionTrackingPreviewContract.test.mjs` **21 passed**. PHP syntax checks and `git diff --check` passed. The Submission Tracking callbacks did not change, so no new frontend build was required. Existing `LocalNavigationTrace` can measure the redirected GET only when explicitly enabled for an authorized loopback local request; no production trace, browser interaction, or operational request was made.

**Narrow next evidence:** if an independently needed owner action encounters this wait again, capture its browser POST and redirected GET as separate Network entries and correlate the POST with the existing sanitized routing-transaction duration log. Use the loopback-only `__cds_perf=1` option for the GET if the application is running locally. Until that capture exists, do not attribute the wait to notification delivery, Drive, database mutation, or client rendering. Preview-availability policy, the BMS filename concern, and the other coverage gaps in this audit remain open; the Aug/August formatter mismatch is unchanged.
