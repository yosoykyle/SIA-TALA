# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: retain the completed standalone CP-SAT formulation handoff (TAL-96A), establish the guarded client-aligned acceptance baseline (TAL-96B1), complete the representative CP-SAT recovery experiment and capacity-benchmark handoff (TAL-96B2), benchmark CP-SAT capacity and performance with generated growth and stress tiers (revised TAL-96C), prove PayMongo test-mode demo readiness (TAL-96B3), complete cross-role UAT, regression, UI/UX correction, and the final Markdown user manual (revised TAL-96D), then rehearse and prepare the formal client and panel presentation (TAL-97). Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-96 | Planned parent | Final MVP operational-data, integration-demo, system-acceptance, UX-polish, documentation, and capacity-readiness gate. |
| TAL-96A | Done locally | Standalone CP-SAT Technical Formulation and Laravel Validation Pipeline handoff for the project manager. |
| TAL-96B | Revised split approved | Client-Aligned Operational Baseline and Scheduling/PayMongo Integration Demo Readiness through TAL-96B1, TAL-96B2, and TAL-96B3. |
| TAL-96B1 | Done locally | Guarded Client-Aligned Deterministic Acceptance Baseline. |
| TAL-96B2 | Approved; awaiting primary proceed | Complete the bounded CP-SAT recovery, prove one disclosed 54-demand representative experiment locally and through explicit private Cloud Run gates, and hand a reproducible capacity contract to revised TAL-96C without claiming a universal maximum. |
| TAL-96C | Revised planned; depends on TAL-96B2 | CP-SAT Capacity, Performance, and Empirical Evaluation Completion using disclosed proportional-growth and contention tiers. |
| TAL-96B3 | Planned; follows TAL-96C | PayMongo Test-Mode Demo Readiness using the accepted baseline and human-gated dashboard, checkout, and webhook actions. |
| TAL-96D | Revised planned; depends on TAL-96B3 and TAL-96C | Cross-Role UAT, Regression, Evidence-Based UI/UX Correction, and Final Markdown User Manual. |
| TAL-97 | Planned | Rehearsal and Formal Client and Panel Presentation and Defense Readiness built only from verified TAL-96 outputs. |

## Active Approved Plan Contract

### TAL-96B2 — Representative Experimental Baseline and Capacity-Benchmark Handoff

**Status:** Approved; awaiting `Primary proceed TAL-96B2`.

**Goal:** Complete the bounded solver recovery already present in the dirty worktree, instrument one strict and reproducible metrics contract, prove the accepted 54-demand client-aligned synthetic baseline locally, prepare the separately authorized private Cloud Run recovery experiment, and deliver a decision-complete handoff to revised TAL-96C. TAL-96B2 must not portray the representative fixture as the institution's minimum, actual production load, maximum capacity, or a universal sizing guarantee.

**Role and user goal:** The Registrar dispatches, reviews, and publishes a candidate timetable; the Academic Head reviews scheduling evidence; the project and documentation team receives a professional, reproducible experimental baseline and clear capacity-testing definitions.

**Trigger and action:** After all 54 accepted TAL-96B1 Scheduling Demands are `READY_FOR_REVIEW`, Laravel captures the immutable `tal94-demand-v2` snapshot and queues the private solver request. CP-SAT returns a candidate and typed diagnostics; Laravel treats the response as untrusted, validates every assignment, persists only allowlisted evidence, and retains Registrar publication as the authority that creates official `section_meetings`.

**Inputs:** The guarded TAL-96B1 baseline on MySQL `test_tala_db`: 3 programs, 47 contextual students, 40 courses, 41 specifications/components, 54 offerings, 54 sections, 54 delivery groups, 54 ready demands, 12 faculty, 6 rooms, and 156 half-hour time slots across six days. The two-demand sample remains a contract fixture only. The exact snapshot hash, solver source/content identifier, OR-Tools version, worker count, seed, time limit, execution mode, CPU, memory, concurrency, and request timeout must accompany every reported experiment.

**Changed records:** No production or development operational records are changed by normal automated verification. Guarded real-service acceptance may create schedule runs, candidate rows, operational events, and published meetings only inside the proven `testing` / MySQL / `test_tala_db` transaction and must roll them back. Existing `schedule_runs.runtime_ms`, `objective_value`, and JSON `diagnostics` are reused; no migration or benchmark table is allowed.

**Outputs:** A validated candidate schedule, complete coverage and hard-constraint evidence, typed solver/model/search statistics, a disclosed three-run representative result table, corrected solver/deployment/runbook documentation, a concise empirical-validation addition to the standalone CP-SAT formulation, architecture synchronization after an accepted runtime configuration, and a reproducible TAL-96C capacity handoff.

**UI surface:** Retain the existing Registrar/Admin Solver Runs list, five-second polling notice, run detail, candidate review, publication, and Faculty official-schedule projection. These are operational scheduling surfaces, not a benchmark dashboard. Do not add an “accuracy score”; current status, coverage, hard violations, runtime, objective score, warnings, and diagnostics remain the appropriate evidence. Any future presentation refinement belongs to revised TAL-96D.

**Related modules and downstream consumers:** PRD Module 06 owns scheduling behavior. Published `section_meetings` remain the only official downstream source for Registrar review, Faculty schedules, Student schedules/COR, room views, revisions, and operational evidence. TAL-96B2 does not change those ownership rules.

**Integration boundary:** Laravel owns snapshots, queue dispatch, validation, persistence, review, publication, and official records. The private Python/OR-Tools service owns only deterministic candidate optimization. Keep the existing `SchedulingSolverClient` dependency boundary, audience-bound private Cloud Run invocation, one dedicated invoker, no public invoker, and no credentials or unrestricted payloads in diagnostics or logs.

**Purposeful simplification:** Use one allowlisted `solver_statistics` response object and the existing diagnostics JSON instead of new schema. Use the generated immutable B1 snapshot instead of committing a second large representative JSON fixture. Keep one representative experiment in B2; generated multi-tier capacity search remains a separate slice.

**Manual/digital boundary:** Automation proposes and revalidates; staff review and publication remain human gates. Docker is optional locally. Cloud mutation is never implied by primary implementation, verification, cleanup, or approval.

**Benchmark and terminology decision:**

- Minimal contract fixture: 2 demands, 2 faculty, 1 room, 4 time slots; functional evidence only.
- Representative experimental baseline: 54 demands, 12 faculty, 6 rooms, 156 time slots; client-aligned synthetic workflow evidence only.
- Maximum supported tier: unknown until revised TAL-96C identifies the highest passing and first failing disclosed tiers.
- Do not use ML-style “accuracy.” Report coverage, constraint-satisfaction rate, CP-SAT status, objective value, best objective bound, optimality gap, solver runtime, end-to-end dispatch duration, model/search counts, and infrastructure utilization.
- Do not report relative percentage deviation without a defensible best-known solution under the identical objective and constraints.

**Qualified-reference result:** The local Academico checkout has no CP-SAT scheduling overlap at either business-logic or implementation-pattern depth, so there is nothing to transplant. Keep TALA's existing solver boundary. Use the local PyJobShop paper only as the professional format reference for disclosed datasets, computational conditions, equations, and results; use official OR-Tools and Google Cloud documentation for solver and infrastructure behavior.

**Implementation sequence:**

1. Preserve and finish the current `NoOverlap` model-growth and adjacent idle-gap correction; prove unchanged hard constraints and `balanced_v1` semantics.
2. Replace the fixed four-worker setting with a bounded, explicit one-worker configuration for the one-vCPU representative environment and fix the representative seed.
3. Add one stable typed `solver_statistics` object containing allowlisted input, candidate, variable, constraint, `NoOverlap`, best-bound/gap, Boolean, branch, conflict, deterministic-time, wall-time, worker, and seed fields. Never return or persist raw solver logs.
4. Extend Laravel's strict response keys and nested validation, persist only the typed statistics under `diagnostics.solver_result`, and keep solver runtime separate from transport/queue duration.
5. Correct the guarded acceptance's stale loopback-only wording; keep ordinary tests on fake HTTP and describe the guarded test accurately as real-service transport/ingestion/publication evidence unless a separate bounded real queue-worker assertion is added.
6. Run the two-demand contract fixture, then run the exact B1 representative snapshot three times locally with OR-Tools 9.15.6755, a 30-second solver budget, one worker, fixed seed, and identical solver source. Every run must achieve `optimal` or `feasible`, 54/54 coverage, zero solver and Laravel hard violations, persisted typed metrics, publishability, and correct Faculty projection.
7. Produce the representative evidence tables and capacity handoff. Append only measured representative evidence and explicit limitations to the standalone formulation; do not change its equations or claim completed capacity evaluation.
8. Synchronize the solver README, demo runbook, and—only after the runtime configuration is accepted—the architecture runtime/cost basis. Cloud Run cost remains usage-based and must use disclosed metering and dated rates rather than a fixed-price guarantee.

**Likely files and surfaces:** `cloud/scheduler-solver/tala_solver/solver.py`, `server.py` only if bounded configuration routing requires it, Python solver/server tests, `ScheduleAssignmentValidationService`, `ScheduleCloudResultIngestor`, focused dispatch/queue/guarded acceptance tests, the existing Solver Runs polling files, `cloud/scheduler-solver/README.md`, the TAL-96B2 demo runbook, a focused representative experimental evidence document, `TALA_CP-SAT_Technical_Formulation.md`, `architecture_specification.md` after accepted runtime evidence, and this controller document. Preserve unrelated dirty work and do not broaden dependencies.

**Verification:** Run the complete Python solver/server suite; focused PHPUnit for the B1 baseline, strict response validation and persistence, dispatch, queue operations, polling, and guarded real-service acceptance; `vendor/bin/pint --dirty --format agent`; focused PHPStan for changed PHP surfaces; and `git diff --check`. Require malformed, missing, and unknown statistics rejection tests. Independent primary verification remains mandatory before cleanup. The full PHPUnit suite is a separate follow-up choice after focused checks pass.

**Human-only cloud gates:**

- `Primary proceed TAL-96B2` authorizes local code, tests, local representative evidence, and documentation only.
- `Deploy TAL-96B2` separately authorizes read-only cloud/IAM preflight, Cloud Build, image publication, and one private zero-traffic candidate at 1 vCPU, 2 GiB, concurrency 1, 300-second request timeout, 30-second solver budget, and one solver worker, followed by three tagged representative runs and Cloud Monitoring/log evidence.
- `Promote TAL-96B2` separately authorizes proving no active work, pausing scheduling, moving the accepted candidate to 100%, canonical representative acceptance, private-IAM/log verification, recovery if needed, and resuming scheduling only after all checks pass.

Two GiB is a bounded recovery candidate based on the measured 1,045 MiB and 1,154 MiB failures, not a final capacity recommendation. Stop rather than automatically increasing resources if the 2 GiB candidate fails, any representative run is not usable, coverage or Laravel validation fails, peak memory exceeds the project safety ceiling of 80%, IAM becomes public or drifts, secret-like material appears, the database target is not exactly `test_tala_db`, or the active source/contract differs from this plan.

**Explicit exclusions:** No universal/minimum/maximum capacity claim; no multi-tier growth/stress benchmark; no database or backend-domain redesign; no benchmark UI or accuracy field; no actual student PII; no algorithm-versus-algorithm comparison; no RPD without a best-known solution; no approved equation, constraint, or objective-policy change; no PayMongo, broad UAT, or unrelated UI/UX work; no dependency addition; and no commit, Linear mutation, push, PR, deployment, promotion, or traffic/IAM change without its exact later command.

**Next slice after completed TAL-96B2 cleanup:** `Plan revised TAL-96C` for CP-SAT capacity, performance, and empirical evaluation completion using disclosed proportional-growth and contention tiers. TAL-96B3 follows that result; revised TAL-96D then owns cross-role UAT, regression, UI/UX correction, and the final user manual.

## Post-MVP Deferrals

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-98 | Future; nonblocking | Archival, offline-storage management, and disposal automation deferred from TAL-92E and PRD §13.7. |
| TAL-99 | Future; nonblocking | DPO-owned privacy-request intake and logging deferred from TAL-92F and PRD §13.3.4. |
| TAL-100 | Future; nonblocking | Database-backed configurable notification templates deferred from TAL-92F and PRD §13.1.1. |
| TAL-101 | Future; nonblocking | Database-level audit tamper-evidence hardening deferred from TAL-93A and PRD §13.6. |

### Unapproved Proposals

No work outside the listed issues is approved or implied. Any additional institutional feature, UI plugin, or infrastructure enhancement must pass the protocol gates and receive an explicit Next Steps issue before implementation.

### Next Boundary

Next primary boundary: **Primary proceed TAL-96B2** against the approved active contract above. This authorizes only its local implementation, tests, representative experiment, and documentation work; deployment, promotion, Linear mutation, push, and PR creation still require their exact later commands.
