# CDS-SMART Office Core Feature Installation Verification

**Date:** 2026-10-12
**Result:** Installed and verified in the office environment.

## Migration status

The four authorized migrations completed in order and are recorded as `Ran`:

- `2026_10_09_000001_add_historical_report_provenance_and_office_tat_configuration`
- `2026_10_09_000002_grant_historical_reports_and_office_tat_permissions`
- `2026_10_11_000002_create_office_tat_compliance_assessment_tables`
- `2026_10_11_000003_create_office_tat_obligation_evidence_setup_tables`

`2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity` remains `Pending`. It is deferred until MPL’s office, MHRWS’s supervising PENRO, and PBPLS’s supervising CENRO mappings are settled.

## Recovery and preservation

A protected full logical backup of 76 InnoDB tables (320,852 bytes) was verified against its private SHA-256 receipt and retained. A marker-owned disposable restore on the same MySQL server matched the 76-table, 696-row baseline, original-column fingerprints, indexes, foreign keys, triggers, and other applicable schema metadata. The clone was removed after its ownership marker was rechecked; the backup and receipts remain retained.

Restore metadata normalized explicit `utf8mb4` character-set annotations. Two empty tables whose source `AUTO_INCREMENT` metadata was unset appeared as `AUTO_INCREMENT=1` in the restore; both still generate the same next ID, `1`. These are equivalent restore normalizations, not byte-identical DDL.

The four migrations were rehearsed on the verified restore with foreign-key checks enabled. On the office database, all 696 pre-existing rows were preserved on their original columns. The 73 unaffected original tables remained fingerprint-identical; the other permitted changes were the four migration records, four new permission records, 28 role-permission links, and the ten default-false historical flags. Seventy-four existing table sequences were unchanged. Existing permission rows and role links were preserved.

## Installed schema and permissions

All ten historical source flags are indexed, non-null, and `false`. The two historical provenance/configuration tables and all seven Office TAT assessment/evidence setup tables are empty; no historical records, settings, obligations, approvals, evidence, or assessment facts were created.

The live schema audit checked the exact column lists/order for all nine new tables and ten historical source tables, 20 required defaults/nullability settings, 25 named index definitions, and all 15 foreign-key targets/delete actions. The technical-classification `evidence_id` column intentionally has no foreign key, as defined by the migration.

The permission delta is exactly four `web` permissions and 28 role links, matching the office-derived migration plan:

| Permission | New role links |
| --- | ---: |
| `historical-reports.view` | 10 |
| `historical-reports.encode` | 6 |
| `office-tat.view` | 10 |
| `office-tat.configure` | 2 |

All four abilities passed `User::can()` for two active, approved actors each. The exact role-link sets and unchanged direct-permission assignments were checked. No active, approved unassigned account was available for a separate negative actor check.

## Readiness and release

The assessment service reported its configuration schema ready, and the obligation-evidence service reported its schema ready. Read-only controller GET checks returned HTTP 200 for Historical Reports and Office TAT using eligible actors. No authenticated browser session or visual review was performed; these checks are not browser acceptance.

Before each live migration, the task-owned maintenance gate returned HTTP 503 and three quiet samples showed zero other database sessions, transactions, and queued jobs. The queue driver was `sync`. Only the Spatie permission-cache key changed during post-install authorization checks; the remaining file-cache and archive/dispatch lock fingerprints were preserved. No services were restarted.

The task-owned gate was released after checks passed. Root and login returned HTTP 200 through loopback with the `cds-smart` Host header, and the maintenance markers are absent. A later direct probe to the `cds-smart` hostname timed out, so that hostname route is not confirmed by the final check.

This installation does not approve an official assessment or certify 2026 ratings. Empty setup tables and incomplete evidence remain unconfigured for office owners to address separately.
