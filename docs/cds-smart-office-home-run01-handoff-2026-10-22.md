# CDS-SMART Office Run01 HOME handoff

Date: 2026-10-22

## Source checkpoint

This branch is a source-only checkpoint based directly on `6fd60c843c0da86a71bcd1c1304ed53d12c5e983`. It carries selected Office TAT source and interface changes for canonical module definitions, recorded-source identity and exclusions, and source details in Submission Tracking. It does not contain operational database rows, files, accounts, receipts, archives, or live-office configuration.

The changes preserve the current routing policy and access gates, existing deadline sources, recorded-report eligibility and scoring calculations, and the separate Average Office Processing Time calculation. No routing stages or Compliance flow were changed in this checkpoint.

SHA-256 hashes below identify each selected source and test file in this checkpoint.

| File | SHA-256 |
| --- | --- |
| `app/Http/Controllers/EngpReportController.php` | `ddb9042b261f23a6c3df69512e9bedce6efbaea5895cbe26271ba0bc0e93c0e2` |
| `app/Http/Controllers/ModuleDefinitionController.php` | `aa1cc309bbb9fdba0391e48557c156ba8cd0b55d2cd17b2580ae257a940d258c` |
| `app/Http/Controllers/PamoReportWorkspaceController.php` | `628323c68963c12ddf5e2f27bb3227a46783b2d6005008fb651540cc03fdaeca` |
| `app/Services/Modules/CanonicalModuleDefinitionService.php` | `629d86c82d47145b77e7fca23dac725e3f82cfa2a584117629d0fdb029165562` |
| `app/Services/OfficeTatComplianceAssessmentService.php` | `f9bd4d849de2200face1d6a3cafac72e64bebe7c3e5b459e9b99df0690f4957a` |
| `resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx` | `4f80cc9414ec1bfde7065d08d1405ed6eafa2d57df1c5a2a07f9fc106850646e` |
| `resources/js/Pages/Admin/OfficeTat/Index.jsx` | `23190961d4eef8e2454c63f58a223a07e8b0e055e424f77131df25aff4c915ff` |
| `resources/js/Pages/Admin/OfficeTat/OfficeTatAssessmentSettings.jsx` | `a159c212953f679c4f6033588ad6028b221679a923b93204ac071ea3f5820cea` |
| `resources/js/Pages/Admin/OfficeTat/ranking.mjs` | `f34fc41b691046ac3d84ebe11c9b399a9849489662bf86512f546187dfdce01d` |
| `resources/js/Pages/Admin/Settings/ModuleManagement.jsx` | `70269818df852306c2907dd0e1f002361d7955b16ddfaa946dd6be85b8497d26` |
| `resources/js/Pages/Engp/Index.jsx` | `89531fa8bd1aa1e83d4c1eabf93734d08c1b6ff021193568baad8e225c054e13` |
| `resources/js/Pages/SubmissionTracking/Index.jsx` | `de8c40d878f134b03bbe6df52d515743eda37bfed58ef36a8ab14dd75bad20e7` |
| `resources/js/Utils/submissionDetailContext.js` | `655ba25c2710f969716cd42c023533775c91475865edd6a2c2d7b0f6a33289d8` |
| `routes/web.php` | `6d4762f095c68bd03f2aef600b3c1edd6165e4a890586a18c3ac9f62aa32eed3` |
| `tests/Feature/EngpReportTest.php` | `b6d1e1ce5c0b56a9c83c7c0e2a8a8761ee5eddc9c2c042ba1f93ca294ac0d852` |
| `tests/Feature/EngpWorkflowScoringBoundaryTest.php` | `34eb4ff4ae854cb1e87d060fd4acb9c47605ecd0d4383152348f3be9cc7cf830` |
| `tests/Feature/ModuleDefinitionManagementTest.php` | `c0cc7fb61b68826781d5da79706ef8a2c1838cc2db1adf1fd31a399b15d9c40b` |
| `tests/Feature/OfficeTatAutomaticDashboardTest.php` | `642b9924dddd5d7e481e87c230fc088e401d2e90cb1223adbdde77a39e38d94c` |
| `tests/Feature/OfficeTatComplianceAssessmentTest.php` | `dd0e1f1fe96de8af58e76cff6fc79e2853737fab8b1e72600926df84fc7206bb` |
| `tests/Feature/PamoPambDraftRoutingTest.php` | `84220538bddb8bef1e85577549cd69e50f3197ee1037ddb8a7bd20aa16a87394` |
| `tests/js/moduleManagementCanonicalSetup.test.mjs` | `485185fbd4c73e502e3bdcf6eb59f4605a6ab6e2d0a417c0d287bb6aa2263a73` |
| `tests/js/officeTatAutomaticDashboard.test.mjs` | `8e4bc47e241cde2998ea34e28335802690a25abb4cd1bac86b699197e0431c73` |
| `tests/js/officeTatRanking.test.mjs` | `8f1677a0fc8edfccd5e6cb03cdef5458cadf60aab2ce78b9fcfc312989d5d341` |
| `tests/js/submissionDetailContext.test.mjs` | `02f1a3c0f1fef8861579a8480dcfd49cfa26294692dfed8f3d3c18081c180fd0` |

## Run01 progress to carry by reference

Report-level statuses and exact OFFICE verification evidence remain in the private Run01 ledger and resume. They are intentionally omitted here. The source checkpoint cannot transfer those live records to HOME.

The aggregate module matrix last recorded:

- 12 of 47 mapped ENGP office/workflow cells verified complete; 35 remain for Manay, Lupon, and Mati.
- Six generic Conservation workflow families have routed acceptance evidence; 16 registered families have no routed acceptance evidence yet. The full Protected Area-by-document applicability matrix remains open.
- Seven native PAMO report families are active, with no routed acceptance evidence recorded.
- The QA cleanup gate is closed. Keep existing sample data, accounts, reports, receipts, and archives intact.

## HOME continuation

1. Fetch this branch into a separate HOME checkout and verify the full commit SHA and parent before reviewing or applying source changes.
2. Keep the HOME serving checkout and its existing edits untouched. Do not merge this checkpoint into a serving branch or infer that HOME and OFFICE runtime configuration or database contents match.
3. Continue report-specific verification from the private Run01 ledger and resume. Do not recreate, resubmit, re-receive, or re-archive existing OFFICE reports to make HOME match.
4. Keep QA paused until a fresh encrypted OFFICE snapshot can be created and verified with the existing owner recovery secret. That secret was unavailable to the offline checkpoint environment, and no usable GPG private key was present. The older encrypted backup is not a substitute for a fresh snapshot.
5. Restore operational data only through the separately authorized, verified backup workflow. Keep all database, account, report, receipt, archive, routing, and Compliance actions out of this source checkpoint.

## Isolated verification

- Focused PHP feature set: 95/95 passed using the repository SQLite `:memory:` guard, blank database URLs, process-local test key, and isolated storage.
- Focused frontend checks: 34/34 passed.
- PHP lint: 12 changed PHP files passed; `git diff --check` passed.
- Guarded Vite build: passed; 173 ESM assets, 101 generated relative imports, and seven matching PDF.js decoder assets were verified in the isolated checkout.
- Two existing PAMO routing scenarios still fail their final Average Office Processing Time assertion: the projection reports one completed interval where the assertion expects zero after a correction-and-resubmission sequence. Their routing steps pass. The Average Office Processing Time service was not changed here; track that assertion/behavior separately before treating the whole routing file as green.

Build output and test storage are local verification artifacts and are not part of this source handoff.
