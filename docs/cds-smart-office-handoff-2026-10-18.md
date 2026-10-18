# CDS-SMART office handoff - 2026-10-18

This checkpoint is based on reviewed commit `b5c29999a854cddc6264c4d60438321215d2a0aa` on branch `checkpoint/cds-smart-office-handoff-2026-10-18`. It is a source checkpoint, not a deployment or a claim that every workflow or provider path is verified.

## Included behavior

- MHRWS #44 correction returned to its exact captured PAMO after the legitimate PENRO Focal correction receipt. Working version 2 was replaced and resubmitted; the normal direct route reached Regional release and final archive.
- Eligible PAMO-origin final copies use a verified canonical PA grouping label while receiving-office custody stays separate. Other office-origin paths retain office grouping.
- Existing bound archive destinations and reserved IDs are preserved and reconciled. The grouping policy must not create duplicate uploads.

## Existing #44 reference

Conservation #44, PA 2 / MHRWS, profile `pamo_direct` / `pamo-origin-v2`.

Receipt 9, manifest 9, revision 1, final-file row 12, archive row 30. The final PDF is synthetic, 904 bytes, SHA-256 `34C3BE3174D24495157B7B6705436C441CD61CCE5ED181BCAF14A4B0CD3EAA8B`.

Do not resubmit, re-receive, retry archive, or upload this completed record again.

## Pending office work, in order

1. Continue in an isolated office checkout or worktree. Read this handoff, local `AGENTS.md` instructions, and the office resume; inventory office changes before integration. Git transfers code and docs, not the home database, ignored `.env`, private staged uploads, receipts, accounts, or provider state. Inspect the office's existing trusted configuration and database schema read-only first. Do not restore, migrate, change keys, or alter configuration because code was fetched. If these QA rows or files are absent at the office, record that distinction; any needed development data/files require a separate owner-controlled private transfer.
2. #44's existing Drive location is unchanged and repair remains blocked. The first metadata GET returned status 0 with no HTTP response and no OAuth category. Parent/ancestry, shared-folder inventory, current hash, and duplicate count were not obtained; there is no evidence that the token expired. Run a bounded read-only inspection in an authorized runtime. Do not rename the shared PENRO folder, copy, re-upload, delete, or change records before the actual metadata and exclusivity are verified. The new PA grouping passed fake-provider tests; a fresh real provider upload into the new grouping remains unverified.
3. Both authorized #44 previews currently return HTTP 200. The earlier MHRWS preview 500 was not reproduced. The prior causal-correlation claim is retracted: the `MissingAppKeyException` logs have no matching request IDs, so the cause is unresolved. The separate historical BMS #8 preview 500 is also unresolved. If it recurs, capture reliable request correlation and fix only the demonstrated cause. Do not generate or rotate `APP_KEY`, alter OAuth, or weaken security speculatively.
4. MPL and PBPLS each retain one unchanged, unsubmitted scope-only draft. Do not submit incomplete drafts or create duplicates. A positive CENRO Mati Incoming case is unavailable. TWC, separate ENGP, IMEA, AWS, other jurisdictions, and provider/MySQL concurrency remain unverified. Continue these scoped cases only when their dependencies are available; do not claim whole-system readiness.

## Exact checkpoint validation

The candidate was based on `b5c29999a854cddc6264c4d60438321215d2a0aa`. Candidate application and test classes were preflighted to resolve from the isolated checkout. Its existing `tests/bootstrap.php` guard and same-process `TestCase` check verified Laravel's opened SQLite `:memory:` PDO and the empty main filename from `PRAGMA database_list` before migration-capable test traits. The operational `.env` was not copied. No operational MySQL or live Drive request was used; archive tests used fake or mocked providers.

- Focused PHP suites: `PamoPambDraftRoutingTest`, `DocumentRoutingTransitionTest`, `ArchivePathBuilderTest`, `PamoArchiveFolderGroupingTest`, `FinalDocumentArchiveLifecycleTest`, and `GoogleDriveDocumentArchiveGatewayTest` - **115 passed, 0 failed, 0 errors** (exit 0).
- Dotenv emitted 115 suppressed PHP warnings while probing for the intentionally absent candidate `.env`; no `.env` file was added.
- Repaired activity harness and direct frontend contracts - **15 passed, 0 failed**.
- PHP syntax check - **15 changed PHP files passed**.
- `git diff --check` reported no whitespace errors.
- The existing guarded Vite build ran only in the isolated candidate. It built 1,500 modules and the verifier accepted 172 ESM assets, 101 generated relative imports, and all 7 matching `pdfjs-dist` decoder assets. Generated output remains candidate-only and is not part of the commit.

Before the candidate build, view-rendering tests correctly failed because the source-only candidate had no Vite manifest. One focused MHRWS regression fixture also needed the explicit fake archive configuration; after adding that test-only fake setup, the focused case passed and the complete suite passed. These were isolated test setup issues; no live application files or provider operations were changed.

The candidate has not been deployed. Browser and provider checks remain limited to the acceptance findings above.