# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: complete cross-role UAT, regression, UI/UX correction, and the final Markdown user manual (revised TAL-96D), then prepare the formal client and panel presentation (TAL-97). The completed scheduling and PayMongo readiness work is retained in bounded commits and pending-sync tracker rows. Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-96 | Planned parent | Final MVP operational-data, integration-demo, system-acceptance, UX-polish, documentation, and capacity-readiness gate. |
| TAL-96A | Done locally | Standalone CP-SAT Technical Formulation and Laravel Validation Pipeline handoff for the project manager. |
| TAL-96C | Done locally | Client-baseline PayMongo demo readiness and Student Finance checkout acceptance. |
| TAL-96D | Approved revised split and master charter; depends on TAL-96C | Production-Level MVP Defense Readiness parent governed by [`TALA-96D-Full-System-Refinement-Charter.md`](TALA-96D-Full-System-Refinement-Charter.md): preservation-first vertical hardening, diverse scenario evidence, adversarial cross-role acceptance, targeted capacity readiness, and one consolidated operations and defense guide. |
| TAL-96D1 | Done locally | Client-Corrected Capacity Authority, Representative Scenario Catalogue, Operating-Order Map, Implementation-Validity Audit, Baseline Correction, and Required-Gap Routing. |
| TAL-96D2 | Approved revised split; depends on TAL-96D1 | Identity, Admissions, Academic Setup, and Offering-State Hardening parent. |
| TAL-96D2A | Done locally | Identity, Applicant Intake, Review, and Handover Hardening. |
| TAL-96D2B | Done locally | Academic Period, Catalog, Curriculum, and Import Hardening. |
| TAL-96D2C | Done locally; approved standalone faculty-evidence reconciliation pending | Offering, Section, Resource, and Scheduling-Readiness Hardening with guarded `MIN`, `MIDDLE`, and `MAX` acceptance scenarios; reconcile reported and synthetic faculty capacity before resuming D3B manual acceptance. |
| TAL-96D3 | Approved revised split; depends on TAL-96D2C | Scheduling, Enrollment, Finance, COR, and Integration-State Hardening parent. |
| TAL-96D3A | Done locally | Master Schedule Functional Hardening: readiness, dispatch and retry, candidate review and correction, publication and revision, and official faculty/student schedule projections. |
| TAL-96D3B | Done locally; manual acceptance deferred | Enrollment Window, Proposal, and Placement Hardening: canonical calendar enforcement, non-capacity-holding section proposals, regular and irregular placement, academic and conflict gates, capacity, reservations, cancellation, and recovery. Manual acceptance resumes only after the refreshed `MIN` baseline is rebuilt. |
| TAL-96D3C | Planned; depends on TAL-96D3B | Assessment, PayMongo, Ledger, and Finance-Gate Hardening: assessment activation, checkout, verified webhook processing, reconciliation, queue recovery, ledger effects, notifications, and operator visibility. |
| TAL-96D3D | Planned; depends on TAL-96D3C | Official Enrollment, COR, and Cross-Role Convergence: final gate recheck, official enrollment, current COR and schedule consistency, output logging, holds, and modality-authority reconciliation. |
| TAL-96D4 | Planned; depends on TAL-96D3D | Grades, Lifecycle, Student Hub, Reports, and Cross-Role UX Hardening. |
| TAL-96D5 | Planned; depends on TAL-96D4 | Final Adversarial Acceptance, targeted population/configuration and cost evaluation, deployment-readiness gate, CP-SAT evidence synchronization, and the consolidated System Operations and Defense Guide. |
| TAL-97 | Planned; depends on TAL-96D5 | Formal Client and Panel Presentation and Defense Readiness built only from verified TAL-96D evidence. |

Approved TAL-96D order: `TAL-96D1 -> TAL-96D2A -> TAL-96D2B -> TAL-96D2C -> TAL-96D3A -> TAL-96D3B implementation/automated verification -> standalone TAL-96D2C faculty-evidence reconciliation -> refreshed MIN baseline -> TAL-96D3B manual acceptance -> TAL-96D3C -> TAL-96D3D -> TAL-96D4 -> TAL-96D5 -> TAL-97`. TAL-96D remains primary-only; subagents require explicit user approval. Production deployment and cutover remain outside this split.

The full execution method, accepted product directions, state coverage, population scenarios, capacity-study timing, documentation ownership, and human gates are durable in [`TALA-96D-Full-System-Refinement-Charter.md`](TALA-96D-Full-System-Refinement-Charter.md). Every remaining TAL-96D plan must cite and reconcile that charter through the Ground-Truth Gate. It supplements rather than replaces the PRD, blueprint, architecture, master protocol, or the one active approved slice contract recorded here.

Approved compact dispositions: preserve aligned implementation; fix only evidence-backed defects or real gaps; leave cosmetic preferences unchanged or optional. The product supports only `FACE_TO_FACE` and `ONLINE` per offering. D2B stabilizes three-year academic inputs. D2C owns the 21:00 operating grid, realistic offering/resource readiness, and executable `MIN`/`MIDDLE`/`MAX` fixtures without invoking Cloud capacity tests. Its approved standalone correction must distinguish client-reported faculty headcount from generated synthetic scheduling capacity and must not treat demographic status or student modality counts as equivalent to TALA academic-standing or per-offering modality dimensions. D3 and D4 use the stable scenarios for functional and cross-role hardening. D5 owns the targeted population/configuration and cost study after workload manifests are final. Earlier Profile A/B/C and proportional experiments remain historical solver-scaling evidence rather than population tiers. Shared cross-program common classes remain routed to TAL-175. Any solver-contract or constraint-model change is a human-gated plan revision.

## Post-MVP Deferrals

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-98 | Future; nonblocking | Archival, offline-storage management, and disposal automation deferred from TAL-92E and PRD §13.7. |
| TAL-99 | Future; nonblocking | DPO-owned privacy-request intake and logging deferred from TAL-92F and PRD §13.3.4. |
| TAL-100 | Future; nonblocking | Database-backed configurable notification templates deferred from TAL-92F and PRD §13.1.1. |
| TAL-101 | Future; nonblocking | Database-level audit tamper-evidence hardening deferred from TAL-93A and PRD §13.6. |
| TAL-175 | Future; nonblocking | Shared cross-program common-class modeling with multi-cohort conflict protection, solver-contract review, and synchronized formulation evidence. |

### Unapproved Proposals

No work outside the listed issues is approved or implied. Any additional institutional feature, UI plugin, or infrastructure enhancement must pass the protocol gates and receive an explicit Next Steps issue before implementation.

### Next Boundary

Next primary boundary: **`Primary proceed standalone TAL-96D2C faculty-evidence reconciliation`**. TAL-96D3B manual acceptance remains deferred until the corrected `MIN` baseline is rebuilt through a separately approved destructive database gate. Linear sync, push, PR creation, deployment, subagent use, external-service changes, destructive database work, persistent scenario replacement, Cloud solver execution, and full-suite rebuilds remain unauthorized until their corresponding explicit commands or human gates.

## Active Approved Plan Contract

### Standalone TAL-96D2C — Faculty-Evidence Reconciliation

**Goal:** Reconcile the `MIN`, `MIDDLE`, and `MAX` acceptance-scenario faculty counts with updated client evidence and TALA's configured teaching-load, qualification, availability, and operating-grid rules before the scenarios are reused for TAL-96D3B manual acceptance or later capacity evaluation.

**Ground truth and disposition:**

1. The acceptance baseline is the complete synthetic school state; `MIN` is its current-client-scale scheduling workload. They are related but not synonymous.
2. Updated client evidence reports nine faculty. The current `MIN` fixture uses 12 synthetic faculty for 54 demands and 162 teaching units.
3. A bounded readiness calculation shows that nine synthetic faculty can carry the `MIN` workload under the fixture's existing qualification construction and 21-unit limit: aggregate capacity is 189 units and the deterministic allocation's maximum individual load is 19 units.
4. This is qualification/load/grid readiness evidence, not a CP-SAT timetable-feasibility or optimality result. Faculty-specific availability is not reproduced from client records; absent fixture rows currently mean unrestricted availability inside the Monday-Saturday, 07:00-21:00 institutional grid.
5. `MIDDLE` contains 80 demands and 240 teaching units. Twelve faculty is only the arithmetic lower bound; the current qualification-aware construction does not place the workload with 12, places it with 13, and passes with 14. Retain 14 only as a justified sufficient synthetic operating count with headroom, not as a midpoint or mathematically proven minimum.
6. `MAX` contains 178 demands and 532 teaching units. The client-reported historical 14 faculty provide only 294 units of aggregate capacity and cannot support that constructed workload under the 21-unit rule. Preserve 14 as reported evidence, but derive and label a separate sufficient synthetic scheduling roster; its arithmetic lower bound is 26, not an automatically accepted executable count.
7. The client table that combines `Regular` and `Freshman` must not be mapped directly because those values describe different TALA dimensions: academic standing and year level. Client Online/Face-to-Face student counts must not be treated as per-offering modality counts.
8. Preserve historical Cloud benchmark results that actually used 12 faculty as dated pre-reconciliation evidence. Do not rewrite past results or invoke Cloud Run in this correction.

**Required implementation behavior:**

1. Add or extract one acceptance-only deterministic faculty-capacity assessment that consumes generated demand loads, qualifications, availability assumptions, and the configured per-faculty load limit.
2. Report total teaching units, arithmetic faculty lower bound, generated scheduling faculty, client-reported faculty where applicable, maximum assigned load, unassignable demand evidence, and qualification/load readiness separately in each scenario manifest.
3. Change `MIN` to nine generated synthetic faculty only after focused tests prove all 54 demands remain qualification/load ready and no faculty exceeds 21 units.
4. Keep `MIDDLE` at 14 only when the synchronized calculation and tests reproduce the stated sufficient-capacity result and its operational-headroom rationale.
5. Preserve `MAX`'s reported historical count of 14 while deriving a separately named sufficient synthetic scheduling count through the deterministic qualification/load construction. Do not label the first passing construction as a proven minimum.
6. Keep the scenario tooling CLI-only and guarded for test/UAT. Add no production UI, schema, dependency, solver-contract, or CP-SAT model change.
7. Correct current documentation claims while keeping `currentpopulation.md` as raw client evidence. Clearly distinguish current scenario readiness, historical benchmark evidence, and the later TAL-96D5 capacity study.

**Likely code and documentation surfaces:**

- `app/Actions/SystemAdministration/SchedulingAcceptanceScenarioCatalog.php`
- a focused acceptance-only capacity assessment under the existing System Administration action namespace if extraction is warranted
- `database/seeders/ClientAlignedAcceptanceBaselineSeeder.php`
- `database/seeders/SchedulingAcceptanceScenarioSeeder.php`
- `app/Console/Commands/SeedSchedulingAcceptanceScenario.php`
- `tests/Feature/TAL96D2COfferingAndScenarioHardeningTest.php`
- `tests/Feature/TAL96B1ClientAlignedAcceptanceBaselineTest.php`
- `00_Project_Documents/TALA-96D-Full-System-Refinement-Charter.md`
- `00_Project_Documents/TALA-System-Operations-and-Defense-Guide.md`
- `00_Project_Documents/research paper/TALA_CP-SAT_Technical_Formulation.md`
- historical benchmark documentation only where a dated pre-reconciliation clarification is necessary

**Verification:**

1. Write focused failing tests before implementation for `MIN` nine-faculty readiness, `MIDDLE` sufficient-capacity justification, `MAX` reported-versus-generated separation, overload rejection, qualification gaps, and manifest semantics.
2. Before DB-backed checks, prove `APP_ENV=testing`, MySQL, and database `test_tala_db`.
3. Run the focused TAL-96D2C and client-aligned baseline tests, plus only directly affected scheduling-readiness tests.
4. Run Pint after PHP changes, scoped PHPStan, and `git diff --check`.
5. Do not run Cloud Run, CP-SAT capacity benchmarks, the full suite, or destructive scenario replacement within ordinary verification.
6. After programmatic verification, stop for explicit approval before snapshotting and rebuilding `test_tala_db` with the refreshed `MIN` fixture.

**Human gates and exclusions:**

- Preserve all current TAL-96D3B implementation changes. This correction does not reopen enrollment behavior; only its manual acceptance is deferred.
- Stop before destructive database replacement, Cloud or external-service execution, cost-bearing work, a solver-contract or formulation change, a new dependency, production deployment, Linear mutation, push, PR, subagent use, or an unresolved client/product-authority decision.
- Commit only through an explicit Cleanup command. After the refreshed baseline is approved and rebuilt, resume the retained TAL-96D3B manual acceptance table before advancing to TAL-96D3C.
