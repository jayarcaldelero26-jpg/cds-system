# PAMO office assignment label cleanup — 2026-10-12

## Result

- The Protected Area form now shows canonical office names. Before: `CENRO Baganga · cenro_baganga · CENRO` and `PENRO Davao Oriental · penro_davao_oriental · PENRO`. After: `CENRO Baganga` and `PENRO Davao Oriental` in both the selected field and its open options. PAMO office options likewise use their display names without slugs.
- The two separate fields read **PAMO office** and **Supervising office**. The creation option reads **Add PAMO office**; selecting it exposes **Office name**. The brief helper explains that staff accounts and drafts are grouped under the PAMO office. Selecting this option only enters the existing inline creation mode. The existing Save action performs the validated creation and PA mapping; for an existing PA such as MHRWS, use its Edit form rather than creating another PA.
- The supervisor helper uses the server-provided required type on Edit: CENRO for ordinary PAs, PENRO for MHRWS. It is neutral on Create until that type is available. The current server eligibility issue is shortened and placed beside the relevant field: status, PAMO office, or supervisor. Server validation messages remain available.
- User Management and public registration CENRO option text use the same office-name formatter. The User Management eligibility table no longer appends a category already expressed by the supervisor's name.

## Identity and policy evidence

- `ScopedOptionSelect` still resolves options by `id`, displays `label`, searches those labels, returns `String(option.id)`, and retains Arrow, Enter, and Escape handling. The form still submits `pamo_office_id` and `supervising_office_id`; choosing an existing PAMO office still copies its `name` to the legacy `pamo` field. The blank creation sentinel, conditional name field, explicit Save, duplicate/slug-collision guards, and server-derived identity were not changed.
- The PHP `pamoOfficeOptions()` payload retains each office's `id`, `code`, and `name`; only its visible `label` changed to `name`. The focused PHP assertion checks this exact payload. Office authorization, canonical type validation, and PAMO eligibility policy were not changed. The same server issue continues to supply the specific registration-blocking reason.
- No PA, office, user, assignment, status, approval, report, or routing data was changed.

## Files changed for this cleanup

- `app/Services/Authorization/OrganizationalAccessService.php`
- `resources/js/Components/Form/officePresentation.mjs` (new)
- `resources/js/Pages/ProtectedAreas/Form.jsx`
- `resources/js/Pages/Admin/Users/Form.jsx`
- `resources/js/Pages/Admin/Users/Index.jsx`
- `resources/js/Pages/Auth/Register.jsx`
- `tests/Feature/ProtectedAreaManagementTest.php`
- `tests/js/pamoOfficeLabelPresentation.test.mjs` (new)
- This verification note (new)

The tracked files above also contain earlier uncommitted PAMO work. Other pre-existing changes and untracked paths were preserved.

## Fresh checks

- `node --test tests/js/pamoOfficeLabelPresentation.test.mjs tests/js/sharedFilterPresentation.test.mjs`: 9 passed. Checks canonical CENRO/PENRO/PAMO names, absence of slug/category repetition, distinct stable IDs, search/keyboard/Escape behavior, preserved form values, registration/User Management callers, and specific missing-assignment/status guidance.
- `php artisan test tests/Feature/ProtectedAreaManagementTest.php tests/Feature/OperationalGroupRegistrationTest.php tests/Feature/UserManagementPresentationTest.php`: 25 passed, 345 assertions. Covers ordinary and MHRWS supervisor IDs, active options, the PAMO label payload, registration eligibility, forged supervisor rejection, and User Management presentation.
- `npm run build`: passed after the final edit. Guarded candidate verifier checked 161 ESM assets and 94 generated relative imports, then promoted `public/build`.
- `node scripts/verify-vite-build.mjs`: passed on the promoted build before the final wording edit; that edit was subsequently covered by the guarded build's embedded verifier.
- `git diff --check`: passed.

The sandbox initially blocked Node child-process spawning (`spawn EPERM`). Focused JavaScript checks and the guarded build passed when run with authorized elevated execution. The browser inventory returned no apps or browsers, so no read-only visual form inspection was possible. Owner check: open an ordinary Baganga PA Edit form and MHRWS Edit form, confirm the **PAMO office** and **Supervising office** selected values and open menus show full names only, confirm Baganga asks for CENRO and MHRWS for PENRO, then cancel without saving.

## Git state

At handoff: 17 modified tracked files, 9 untracked paths, 0 staged. No commit or push. Existing `.recovery/`, literal `%SystemDrive%/`, private backups, and owner demos were left untouched.
