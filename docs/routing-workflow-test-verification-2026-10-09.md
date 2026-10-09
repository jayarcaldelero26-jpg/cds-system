# Routing Workflow test verification — 2026-10-09

**Scope status: PASS** for the Routing Workflow test-harness correction. The page and registered endpoint contract did not need a production change. This is not a live Settings save or browser acceptance result.

## Reproduced failure and diagnosis

Before editing, the isolated command `node tests/js/routingPositionControls.test.mjs` reproduced the existing failure: 3 of 4 cases passed; the save-state case failed with `TypeError: Cannot read properties of undefined (reading 'toString')` in Inertia's `hrefToUrl`, through `transformUrlAndData`, `Router.getPendingVisit`, and `Router.put`.

The page's `useForm.put` call supplies the valid string `'/settings/routing-workflow'`. Laravel registers that exact path as `PUT settings/routing-workflow`, named `settings.routing-workflow.update`, behind `submission-tracking.routing-settings.update` authorization. This matches the frontend endpoint; it is not an application route-construction defect.

Two test-fixture defects let the request reach Inertia's URL parser instead of the intended spy:

1. The installed Inertia Core and React packages are version **3.6.1**. Its `hrefToUrl` converts the supplied href and uses `window.location.toString()` as the base. The test created `window` with timer functions only, so `window.location` was undefined.
2. The test loaded the page through Vite SSR but imported `router` directly through Node. A diagnostic comparison confirmed those router objects were different (`sameRouter: false`), so changing the Node-side `router.put` did not intercept the instance used by the page's Vite-loaded `useForm`.

The URL input and browser base are therefore separate from the broken mock binding: the page URL was valid; the fixture lacked a base location; and its spy targeted another Inertia singleton.

## Correction

Only `tests/js/routingPositionControls.test.mjs` changed. It now:

- exposes the Inertia router from the same Vite SSR module graph as the page;
- supplies a valid `URL` as `window.location`;
- captures the three test PUTs and guards `router.visit` against an uncaptured request;
- checks each method and endpoint, all three expected versions, both Office/TSD flags, and the reason payload.

The success callback still acknowledges returned settings versions 2 and 3. The validation-error path still preserves the user's edits and reason. Existing view-only/no-save and disabled-control assertions remain in place. No request was sent to the application; the saves were captured by the test spy.

## Verification

- Before correction: isolated file reproduced 3 passing cases and the failing save-state case.
- After correction: `node tests/js/routingPositionControls.test.mjs` passed **4/4** cases.
- Sequential frontend inventory: **24/24 files passed** across `tests/js` and `tests/frontend`. There are 145 `node:test` call sites in the inventory and two standalone assertion scripts; every file exited successfully. The focused file contributes 4 of those test cases.
- The aggregate Node runner had previously failed with `spawn EPERM`; this inventory used the working sequential per-file execution.
- No production build was needed because application source did not change. No live settings save, database write, migration, seeder, permission change, dependency update, or browser action was performed.
- `git diff --check` passed.

## Changed files

- `tests/js/routingPositionControls.test.mjs` — corrected the router mock boundary, browser-location fixture, and save-contract assertions.
- `docs/routing-workflow-test-verification-2026-10-09.md` — this result record.
