# Office OPcache setup and safe project updater — 2026-10-05

## Status

- **Office OPcache: PARTIAL.** The Apache PHP ini is backed up and contains only the requested OPcache extension/settings changes; Apache configuration preflight passes. Restarting Apache and verifying the running web SAPI remain pending because this shared server could not be confirmed idle or in a maintenance window.
- **Project updater: PASS.** `scripts/update.ps1` and its isolated test harness are implemented. PowerShell parsing passed and all 21 disposable-repository cases passed. The real checkout's `-CheckOnly` correctly refuses its pre-existing tracked lockfile change without fetching or changing anything.

## Verified baseline

- Project: `C:\laragon\www\cds-system`; branch `main` tracking `origin/main`; HEAD `104eba3fef628c6723ba3f74e7e1cf834a23e2cd`.
- At task start the worktree already had modified `package-lock.json` and untracked `docs/npm-dependency-audit-2026-10-05.md` from the prior dependency task. Both were preserved. `.recovery/` was preserved. No staging, commit, push, or normal update-mode run occurred.
- Files added by this task are `scripts/update.ps1`, `tests/scripts/update-script.tests.ps1`, and this report. The pre-existing dependency lockfile and its earlier audit report remain unchanged by this task.
- No `AGENTS.md` was found in the project or ancestor directories. Read `docs/handoff-2026-10-01.md`, relevant sections of `docs/system-wide-audit-2026-10-01.md`, and `docs/npm-dependency-audit-2026-10-05.md`.
- Tool versions: Node `v24.18.0`, npm `11.16.0`, PHP CLI `8.3.30`, Composer `2.9.4`, Git `2.55.0.windows.2`.
- The dependency manifest and application build inputs were not changed in this task. The dependency report's latest result remains the applicable one: eight compatible lockfile updates; full npm audit 25 → 6 affected package entries, production-filtered audit 12 → 0; the remaining entries follow the Tailwind 3 `braces@3.0.3` path. No new npm audit, frontend suite, or production build was run here.

## Office Apache/PHP OPcache

Apache's active config at `C:\laragon\bin\apache\httpd-2.4.66-260223-Win64-VS18\conf\httpd.conf` includes `C:\laragon\etc\apache2\mod_php.conf`. That module config names `php8apache2_4.dll` and `PHPIniDir` under `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64`. The PHP ini's `extension_dir` points to that installation's `ext` directory, where the matching `php_opcache.dll` exists. This is the ini named by this host's Apache module config; no other machine's ini was copied.

Before editing, active OPcache load/settings directives were absent. A unique sibling backup was created and verified byte-for-byte by SHA-256:

| Item | Path / SHA-256 |
| --- | --- |
| Original and verified backup | `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini.pre-opcache-office-20261005-121555-13300621.bak` — `7A0E4369A7789217CABE455E54556D22F578D9D7A3D5B3703338622666594472` |
| Updated ini | `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini` — `332809F95CD037A31868EC2D09FA14ED70D0F25A174B859765A02ABAC72E2C0B` |

The ini now has exactly one active matching extension and one active instance of each requested setting:

```ini
zend_extension="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/ext/php_opcache.dll"
opcache.enable=1
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

No CLI setting, unrelated PHP directive, application file, `.env`, or Apache module setting was changed. Apache preflight was run after the edit with:

```powershell
& 'C:\laragon\bin\apache\httpd-2.4.66-260223-Win64-VS18\bin\httpd.exe' -d 'C:\laragon\bin\apache\httpd-2.4.66-260223-Win64-VS18' -f conf/httpd.conf -t
```

Result: `Syntax OK`. The latest `error_log` startup is from 07:28, before this ini edit; no post-edit Apache startup occurred, so the post-restart log and web SAPI state cannot yet be verified. Process inspection confirmed the Apache master and child are running. `mod_status` is disabled, no Apache Windows service was registered, and this session could not establish worker idleness or a maintenance window. I therefore left Apache running; an ini change takes effect in Apache only after restart.

**One owner action:** during a suitable maintenance window, use Laragon tray **Menu → Apache → Stop**, then **Start**. Refresh the existing authenticated CDS-SMART page once and confirm its guarded `X-CDS-Perf-Runtime` value reports `opcache_loaded=1;opcache_enabled=1`. No additional timing screenshot set is requested. No latency benefit is claimed here.

To roll back, copy the verified backup over the same `php.ini`, then perform that controlled Apache Stop/Start. This configuration persists across Git updates and ordinary Apache restarts; recheck it after changing the PHP installation or version.

## Reusable `scripts/update.ps1`

The script resolves the repository from its own location and documents `-CheckOnly` in comment-based help. Normal mode validates the repository root, attached branch, configured `origin` and branch upstream, required tools, and tracked/staged cleanliness. It fetches only the configured upstream remote, suppresses fetch output so credential-bearing remote URLs are not printed, compares commit ancestry, checks untracked collisions and protected paths, and applies only `git merge --ff-only` to the fetched commit. It never resets, cleans, restores, stashes, rebases, stages, commits, or pushes.

Before merging, it blocks incoming conflicts with untracked files, `.recovery/`, `.env`, `storage/app/`, and `bootstrap/cache/`. It also stops before a required build if `public/hot` indicates a Vite dev server, because the existing build script removes that runtime marker. `-CheckOnly` checks local repository/tool/dependency/output state, performs no fetch/install/merge/build, and states that it did not verify remote freshness.

Dependency handling is lockfile-only. When Composer files change or `vendor` is absent, it runs `composer install --no-interaction --prefer-dist --no-scripts --no-plugins`. The existing `composer.json` scripts include `setup` migrations, `post-autoload-dump` package discovery, and a `post-update-cmd` forced Laravel asset publish. The updater suppresses all Composer hooks/plugins so none of those run implicitly. It then removes only generated `bootstrap/cache/packages.php` and `services.php` manifests and runs the supported `php artisan package:discover --ansi`; cached `config.php` is preserved. Composer's forced asset publishing is skipped and the script asks for review if updated packages require an explicit publish. No migration, seeder, notification, schedule, database operation, or external archive/provider action is invoked.

When npm files change or `node_modules` is absent, it runs `npm.cmd ci --ignore-scripts`; package lifecycle scripts are not run. The production build uses `npm.cmd run build` when resources/build config change, dependencies were installed, or `public/build/manifest.json` is missing. Otherwise it reports why install/build steps were skipped. It detects changed migration files and lists them for separate review without running them. An install/build failure reports its phase and current HEAD and leaves the fast-forward state untouched.

### Isolated verification

PowerShell's system `ExecutionPolicy` is `Restricted`; a direct `.\scripts\update.ps1` attempt in this shell is denied. Tests were run in short-lived processes using `RemoteSigned` only for those commands; no machine or user execution policy was changed. The script and harness both pass the PowerShell parser.

`tests/scripts/update-script.tests.ps1` used disposable local bare remotes/clones and harmless `npm.cmd`, `composer.cmd`, and `php.cmd` stubs. **21 passed, 0 failed.** Coverage includes clean fast-forward, already current, CheckOnly non-mutation, dirty/staged work, harmless and conflicting untracked files, `.recovery`, `.env`, uploads, unpublished commits/divergence, detached HEAD, fetch failure with URL suppression, Composer/npm install and build failure without rollback, Composer hook suppression/package discovery, changed-file detection, missing dependencies/build output, migration reporting, and `public/hot` preservation. Test repositories were removed from the uniquely named temporary directory after completion.

The real checkout was checked with:

```powershell
powershell.exe -NoProfile -ExecutionPolicy RemoteSigned -File .\scripts\update.ps1 -CheckOnly
```

It returned: `Tracked or staged changes are present: package-lock.json. Commit or otherwise resolve them before updating; nothing was fetched or changed.` This is the expected safety stop. Normal update mode was not run against this dirty project. The script and test harness remain local, uncommitted files; they are not available on another computer until later committed and synced with separate owner authorization.

## Preserved state and remaining actions

- No application source, test, frontend styling, route, workflow, business rule, `.env`, upload, runtime setting other than the requested PHP ini, operational record, database, Drive hierarchy, archive checkpoint, notification, or job was changed. Regular/Special/TWC routing and handoffs, actor/office/PA authorization, correction/MOV gates, PENRO receipt, progress/history/attachments, PDF controls, and the retired Technical Reports state remain untouched.
- The Aug/August formatter mismatch remains unchanged.
- The browser inventory had no available sessions. Authenticated live `apache2handler` flags remain unverified until the owner Stop/Starts Apache and performs the one refresh above.
- Before a routine update, resolve/commit the tracked lockfile change and use a PowerShell session that permits local scripts. For Composer updates, review the script's asset-publish note before deciding whether a specific package asset must be published.
- OPcache activation remains pending the controlled restart and web-header check. No performance comparison is needed for this setup step.

**Office OPcache: PARTIAL** — ini and verified backup are ready; Apache restart and web activation remain pending.

**Project updater: PASS** — safe fast-forward behavior and isolated safeguards passed; current dirty work is correctly rejected, and the current shell's Restricted policy prevents direct script invocation.
