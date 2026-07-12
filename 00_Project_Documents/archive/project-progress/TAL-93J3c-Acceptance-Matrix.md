# TAL-93J3c Acceptance Matrix

Date: 2026-07-12

Environment: `APP_ENV=testing`, MySQL `test_tala_db`; PHP 8.2; Laravel 12.62.0; Filament 5.6.7. Browser fixtures used the removable `tal93j3c-*` prefix and were deleted after the run.

| ID | Module / role | Test type | Preconditions | Steps | Expected result | Actual result | Status | Severity | Evidence | Finding ID | Retest |
|---|---|---|---|---|---|---|---|---|---|---|---|
| TECH-01 | Seven-role registration | PHPUnit | Canonical seed | Run `TAL93J3cPreIntegrationGateTest` and learner panel boundary tests | Exact 39 staff Resources and 4 staff Pages; staff access/navigation classified for five roles; Applicant panel pinned to 0 Resources, 2 Pages, and 2 navigation entries; Applicant and Student panel boundaries enforced | 50 tests, 99 assertions passed | Pass | Blocker | Focused PHPUnit output | - | Pass |
| TECH-02 | Full application | PHPUnit | No browser fixtures or concurrent DB mutation | Run complete suite sequentially | No failures | 618 tests, 5,286 assertions passed | Pass | Blocker | Clean full-suite output | - | Pass |
| TECH-03 | PHP source | Pint | Current bounded diff | Run `vendor/bin/pint --dirty --format agent` | Clean formatting | Passed | Pass | Major | Pint agent output | - | Pass |
| TECH-04 | PHP source | PHPStan | Current tree | Run analysis with supported 1 GB memory limit | No errors | No errors | Pass | Major | PHPStan `[OK]` | ENV-01 | Pass |
| TECH-05 | PHP dependencies | Security audit | Locked dependencies | Run Composer audit | No advisories | No advisories | Pass | Major | Composer audit output | - | Pass |
| TECH-06 | JS dependencies | Security audit | Installed lockfile | Run npm audit at high threshold | No vulnerabilities | 0 vulnerabilities | Pass | Major | npm audit output | - | Pass |
| TECH-07 | Frontend assets | Production build | Installed dependencies | Run Vite build | Successful production bundle | Built successfully | Pass | Major | Vite build output | - | Pass |
| TECH-08 | Routes | Inventory | Bootable testing app | Count non-vendor routes | Inventory completes | 130 routes | Pass | Major | `route:list --except-vendor --json` | - | Pass |
| TECH-09 | Worktree | Diff hygiene | Bounded changes only | Run `git diff --check` | No whitespace errors | Clean | Pass | Major | Git output | - | Pass |
| REND-01 | Registrar | Rendered acceptance | Active verified registrar fixture | Open every permitted registered index/custom page; open Accounting payment route; exercise account menu/sign-out | 29 render; denied route forbidden; interaction works | 29/29 passed; `Forbidden`; no console errors | Pass | Blocker | In-app Browser sweep | - | Pass |
| REND-02 | Accounting | Rendered acceptance | Active verified accounting fixture | Open every permitted registered index/custom page; open Registrar section route; exercise account menu/sign-out | 12 render; denied route forbidden; interaction works | 12/12 passed; `Forbidden`; no console errors | Pass | Blocker | In-app Browser sweep | HAR-01 | Pass |
| REND-03 | Faculty | Rendered acceptance | Active verified faculty fixture | Open every permitted registered index/custom page; open payment route; exercise account menu/sign-out | 5 render; denied route forbidden; interaction works | 5/5 passed; `Forbidden`; no console errors | Pass | Blocker | In-app Browser sweep | - | Pass |
| REND-04 | Academic Head | Rendered acceptance | Active verified academic-head fixture | Open every permitted registered index/custom page; open payment route; exercise account menu/sign-out | 22 render; denied route forbidden; interaction works | 22/22 passed; `Forbidden`; no console errors | Pass | Blocker | In-app Browser sweep | - | Pass |
| REND-05 | System Super Admin | Rendered acceptance | Active verified system-super-admin fixture | Open every permitted registered index/custom page; open payment route; exercise account menu/sign-out | 18 render; denied route forbidden; interaction works | 18/18 passed; `Forbidden`; no console errors | Pass | Blocker | In-app Browser sweep | - | Pass |
| REND-06 | Public landing | Mobile rendered smoke | 390 x 844 viewport | Open `/`; inspect identity, meaningful content, overflow, console | Landing renders without horizontal overflow or errors | Correct H1; no overflow; no console errors | Pass | Major | In-app Browser DOM/console | - | Pass |
| REND-07 | Student Hub | Mobile rendered acceptance | Active verified student fixture and profile; 390 x 844 viewport | Open dashboard, completion, COR, finance, grades, holds, lifecycle, schedule | Eight surfaces render without overflow or errors | 8/8 passed; no overflow; no console errors | Pass | Blocker | In-app Browser sweep | - | Pass |
| REND-08 | Applicant Workspace | Rendered acceptance plus mobile registration | Verified pending Applicant fixture; 390 x 844 registration viewport | Sign in; open Dashboard and My Application; edit one field without saving; request Student and Admin panels; sign out; open registration | Two registered workspace pages render; form responds; denied panels are forbidden; registration has no overflow or errors | 2/2 passed; field accepted input; both denied routes returned `Forbidden`; registration had no overflow; no console errors | Pass | Blocker | In-app Browser DOM/console/screenshot | - | Pass |

## Findings

| ID | Classification | Observation | Disposition |
|---|---|---|---|
| ENV-01 | Tooling / environment | PHPStan exhausted the default PHP 128 MB limit before analysis. | Reran with PHPStan's supported `--memory-limit=1G`; analysis passed. No product change required. |
| HAR-01 | Harness / data | The first full suite observed one `tal93j3c-*` user because a browser fixture was created concurrently. | Removed all fixtures, proved `SchemaConformanceTest` passes 5/5, and started a clean full-suite rerun with no concurrent DB mutation. |

No product defect, authority conflict, TAL-94/95 dependency failure, or out-of-scope enhancement was identified during the rendered pass. Formal institutional UAT remains TAL-96.
