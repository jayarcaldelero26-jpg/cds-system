# CDS-SMART Office TAT populated ranking demo — verification

**Status:** An isolated synthetic-data preview was verified for owner review. This note contains no local preview URL, credentials, or machine-specific runtime paths.

## Scope

The review exercised the existing Office TAT page and analytics using an isolated SQLite demo database with synthetic records. The normal home database, official assessment setup, organizational mappings, and Conservation source 39 were not changed. The page carried the **DEMO DATA** notice. Results are limited-population demo results, not official 2026 ratings.

## Expected and actual ledger

The independent ledger derived elapsed and allowed days from source facts, then compared identities, pooled scores, eligibility, and ranks with `OfficeTatComplianceAssessmentService` and the authenticated controller response.

| Unit / period | Roster | Activity / received | Due N | On time | TC | SE | OTPS shown |
|---|---:|---:|---:|---:|---:|---:|---:|
| Conservation · H1 2026 | 6 / 6 | 0 / 0 | 0 | N/A | N/A | N/A | N/A |
| Conservation · H2 2026 | 6 / 6 | 14 / 14 | 13 | 12 | 92.307692% | 80.219780% | 89.89 |
| Conservation · Annual 2026 | 6 / 6 | 14 / 14 | 13 | 12 | 92.307692% | 80.219780% | 89.89 |
| Development · H1 2026 | 4 / 4 | 0 / 0 | 0 | N/A | N/A | N/A | N/A |
| Development · H2 2026 | 4 / 4 | 4 / 4 | 4 | 3 | 75% | 30.263158% | 66.05 |
| Development · Annual 2026 | 4 / 4 | 4 / 4 | 4 | 3 | 75% | 30.263158% | 66.05 |

Conservation ranking is **Demo PA A (1), Demo APL (2), Demo PA C (3), Demo PA D (4)**; Demo PA E and F are unranked. PA C remains above PA D because TC is the primary rank key. The underlying pooled OTPS is 89.8901098901 before the service's two-decimal display rounding. PA E has one received activity, one future deadline, and due N = 0.

| Conservation participant | Due N | TC | SE | Raw OTPS | Rank |
|---|---:|---:|---:|---:|---:|
| Demo PA A | 1 | 100% | 85.714286% | 97.142857 | 1 |
| Demo APL | 1 | 100% | 57.142857% | 91.428571 | 2 |
| Demo PA C | 1 | 100% | 0% | 80 | 3 |
| Demo PA D | 10 | 90% | 90% | 90 | 4 |
| Demo PA E | 0 | N/A | N/A | N/A | Unranked |
| Demo PA F | 0 | N/A | N/A | N/A | Unranked |

PA E's synthetic source dates are October 5, 11, and 15 (T/D = 7/3). Its accepted receipt is October 11, before the October 15 deadline.

Development ranking is **CENRO Baganga (1), CENRO Manay (2), CENRO Mati (3), CENRO Lupon (4)**. The live CBEP registry resolves the July 1 to July 20 interval to **19 calendar days**, so the synthetic elapsed samples are 5, 10, 19, and 20 days. No non-working-day exceptions fall in these fixture intervals. The independently calculated pooled SE is 30.2631578947% and raw OTPS is 66.0526315789 (displayed 66.05). No 20-day rule was fabricated. H1 contains no fixture identities; Annual and H2 contain the same source-period identities.

| Development participant | Allowed / elapsed days | TC | SE | Raw OTPS | Rank |
|---|---:|---:|---:|---:|---:|
| CENRO Baganga | 19 / 5 | 100% | 73.684211% | 94.736842 | 1 |
| CENRO Manay | 19 / 10 | 100% | 47.368421% | 89.473684 | 2 |
| CENRO Mati | 19 / 19 | 100% | 0% | 80 | 3 |
| CENRO Lupon | 19 / 20 | 0% | 0% | 0 | 4 |

No official assessment setup, approvals, applicability, schedules, non-receipt declarations, or operational mappings were seeded. The synthetic CENRO aliases existed only in the isolated preview and did not approve MPL, MHRWS, or PBPLS mappings.

## Changes and verification evidence

- A participant whose supported activities are all received and future-due is presented as **“Received — not yet due”**, with the next deadline when available. Incomplete dates/evidence, N/A, score status, due N, and eligibility rules are unchanged.
- PENRO Office TAT viewers holding `office-tat.view` can read their existing both-unit organizational scope through the office analytics categories. They do not receive global configuration access; PAMO preparation detail remains withheld.
- Authenticated HTTP responses matched the expected H1, H2, and Annual values for both units with the `DEMO DATA` notice. The relevant category-filtered H2/Annual views were also checked. The live PA E service row passed through the actual presentation helper and returned the October 15, 2026 deadline.
- Focused JS presentation check: **4 passed**.
- Focused PENRO Office TAT access regression: **1 passed, 10 assertions**, using in-memory SQLite.
- Guarded frontend build: **passed**; asset verification reported 160 ESM assets and 93 reachable generated relative imports.
- The expected-ledger verifier matched service precision, TC-first ranks, future-due exclusion, and H1/H2/Annual identity membership.

These are recorded verification results from October 11, 2026, not fresh checks performed during checkpoint publication.

## Isolation and limits

Synthetic preview data stayed in a task-owned isolated SQLite database. The effective connection was checked before migrations and fixture writes; file sessions/cache and array mail were used, external delivery and archive integrations were disabled, and no queue worker was running. Cleanup was prepared but **not executed**. The preview was left available for the owner's review.

No browser automation was available. Authenticated HTTP and service checks are not visual acceptance; the owner should still inspect the populated table, participant details, and filter controls in a browser. Official assessment configuration, full-period coverage, and official 2026 ratings remain incomplete.
