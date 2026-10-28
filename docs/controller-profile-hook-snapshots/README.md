# Temporary controller profile hooks

These three snapshots add timing-only probes to the isolated checkpoint versions of the Office TAT controller, turnaround service, and Inertia response serializer. They record values in `$GLOBALS['CDS_TAT_PROFILE']`, which is consumed by `tests/Feature/IsolatedOfficeTatControllerProfileTest.php`.

They are retained as reproducibility artifacts only. They are not candidate source files and were removed from the active isolated checkout after profiling. To repeat a timing run, first confirm the isolated Git root and source hashes, then temporarily copy the three snapshots to their matching `app/` and `vendor/` paths in the isolated lab. The profiler uses a SQLite `:memory:` database, a deterministic test-only `app.asset_url`, and `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=`. Restore the clean candidate files afterward. Never copy these hooks to the serving checkout.

The full results and limitations are in `../office-tat-controller-profile-2026-10-28.md`. Raw command logs were kept in the temporary lab, not in the permanent archive.