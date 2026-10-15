# CDS-SMART home continuation checkpoint

## Checkpoint

- Branch: `checkpoint/cds-smart-archives-2026-10-15`
- Base commit: `de365e666f01ce971f593efd36263afd2a60c2c0`
- Selected work: PAMO Reports native-source drafts and workspace UI; source-scoped PAMO and global Archives; Regional-return receipt and archive lifecycle; protected file presentation; captured-route and correction safeguards; and the fail-closed test database guard.
- Commit and push proof will be reported separately after the checkpoint is committed and verified on the remote branch.

This is a source and documentation checkpoint. It does not transport local database state, `.env` values, maintenance state, archive runtime flags, or private files.

## Work-PC containment and incident state

Maintenance remains on. The local `.env` still targets `cds_system`, and `FINAL_RETURN_ARCHIVE_ENABLED=false`. An unsafe test invocation reached operational MySQL and reset data. The last inspected operational state was 89 tables and 141 rows, with zero users and roles and all 116 migration records in batch 1. No operational recovery has happened.

The test bootstrap now blocks Laravel startup unless the environment is positively isolated to in-memory SQLite and has no database URL, read/write overrides, or configuration-cache override. File-only mocked guard checks and PHP lint passed. No application test suite has run after the guard. The failed post-cleanup run had 22 tests: 11 passed and 11 errored against the wrong database. Do not describe this checkpoint as fully tested or the database as recovered.

Archive worker and page acceptance passed before the incident. Current archive availability and database health are unverified. Source changes remain in this checkpoint, but runtime flags and database state do not travel with Git.

## Recovery boundary

Point-in-time recovery is not established. The Oct 14 backup is a backup-only state; legitimate changes after its snapshot are unverified. A new isolated rehearsal remains blocked because the work host denied the runner's attempt to create a child under the existing protected workspace, and the protected temporary location had a prior effective-write denial. No new private workspace or MySQL instance was created. Do not retry permissions or restore/replay on the work database.

The next recovery dependency is a host administrator provisioning a unique private workspace outside the Git checkout and shared temporary directories, with limited access for the actual runner and write access through its execution sandbox. Only after the runner can verify that workspace and an empty write probe should a DBA provide a disposable MySQL 8.4.3 instance with its own datadir, endpoint, private credentials, and destination-restricted access. See:

- `cds-smart-database-recovery-candidate-and-rehearsal-verification-2026-10-15.md`
- `cds-smart-database-test-isolation-incident-assessment-2026-10-15.md`
- `cds-smart-archive-worker-freshness-and-activation-verification-2026-10-15.md`

The two protected backups must remain separate from Git and chat:

1. `cds_system-full.sql` — 319,849 bytes; SHA-256 `6b0a596f04f61a5df770841c46598da7aa73b859b86183366b928eca316e55cc`.
2. `cds_system-post-incident-20261015T061058Z-b2881ddc.sql` — 183,920 bytes; SHA-256 `B15162696FDBC7F36BB934F8A19C0BCF6FD202C7CFCBD64A2BDDA103EBD77613`.

Keep both originals protected. Carry them and any needed private recovery evidence only through an owner-controlled private transfer, then verify both hashes at the destination. Do not weaken permissions, guess a removable-drive path, upload the files, or restore the Oct 14 backup blindly.

## Home-PC continuation

Inspect the home checkout, its working tree, and its database independently before switching branches. A different PC's database may be intact; the work-PC incident does not establish that it was reset. Preserve home edits and data. Fetch the actual checkpoint branch and switch only when doing so cannot overwrite local work. Never restore the Oct 14 dump blindly to the home database.

Do not merge, deploy, or activate the archive runtime as part of this checkpoint. Preserve the existing routing and source data, and keep final archive availability separate from the Git source state. The full recovery report remains the authority for the blocked work-PC rehearsal and its host-administrator handoff.
