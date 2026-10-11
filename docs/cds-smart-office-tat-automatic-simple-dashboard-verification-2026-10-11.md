# Office TAT automatic dashboard verification

Date: 2026-10-11

## Dashboard behavior

The Office TAT page now defaults to the shared **Report Performance** and **Average TAT** views. The period picker remains semestral or annual, keeps the existing processing_period query contract and as_of_date cutoff, and uses the existing Asia/Manila date rules. It does not add monthly or quarterly assessment periods.

Report Performance shows one authorized unit at a time. Its recorded population comes from live Conservation and ENGP source identities matched to the active reporting registries:

- A Conservation identity is matched by PA, workflow, source reporting period, activity and document. Its actual event/accomplishment date scopes the source to the selected calendar year. Duplicate rows for one identity count once, using the earliest accepted PENRO receipt.
- A Development identity is matched by CENRO, ENGP workflow, reporting year and registry period. The stored deadline must match the active registry deadline. Office scope is applied before participants and records are returned.
- Future-due source identities remain visible as activity but do not enter due N. Missing dates, unsupported identities and conflicting evidence are counted separately and are not scored. No absent source row or synthetic meeting occurrence is added to this population.
- Historical rows are excluded from live credit. An original historical receipt that conflicts with a live no-receipt identity is shown as incomplete evidence, not as a credit or verified miss.

The recorded population and the official all-required-obligations assessment have distinct result identities and are presented separately. The official metric helper, obligation inventory, 80/20 formula, pooled weighting and tie rules remain in use. An unknown technical classification no longer withholds complete TC, SE or OTPS results; it limits ranking only if that final tie-break is needed.

Average TAT calculations continue to use the existing analytics service and ranking helpers. Live routing durations, historical date gaps, PA preparation samples, source attribution, and weight-by-document-count behavior remain separate.

Routine assessment setup is behind the optional configure-gated **Assessment settings** action. Setup offers selected PA workflow, historical non-receipt, classification, and tie editors. Participant rows open accessible details with source dates, accepted receipt status, denominator and evidence notes.

## Verification

- OfficeTatComplianceAssessmentService.php and OfficeTatController.php: PHP syntax checks passed.
- OfficeTatAutomaticDashboardTest.php: isolated SQLite and fake local storage checks cover recorded-versus-official populations, future work, out-of-period unsupported ENGP records, PA duplicate rows spanning source years, missing dates, historical receipt conflicts, and designated-office/unit access.
- The targeted Laravel suite passed 35 tests and 386 assertions across the automatic-dashboard, compliance assessment, compliance metrics, and Average TAT tests.
- The Office TAT period, evidence, ranking, scope and automatic-dashboard JS contracts passed: 17 tests, 0 failures, using Node's single-process test isolation mode.
- The guarded npm run build passed, verified 160 ESM assets and 93 generated relative imports, and promoted the verified build to public/build. The first sandbox attempt stopped at Windows spawn EPERM before Vite emitted assets; the same guarded build then completed with subprocess access.

No migration or install command was run. Feature tests assert SQLite :memory: and fake the local storage disk; no application database, source submission, historical origin, environment file, or database backup was written. The existing worktree state was preserved.

## Visual verification limit

No authorized browser session was available for this run. The page was verified through source contracts and the production build, not through a live browser interaction or screenshot.
