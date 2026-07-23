# TAL-96D Full-System Refinement Charter

**Status:** Active governing charter for the remaining TAL-96D plans

**Applies to:** `TAL-96D2B -> TAL-96D2C -> TAL-96D3 -> TAL-96D4 -> TAL-96D5`

**Next planning boundary:** `Plan TAL-96D2B`

## 1. Purpose

TAL-96D completes the production-level MVP acceptance and defense-readiness pass without discarding verified work or rebuilding the system by preference. Its purpose is to:

1. verify that every PRD-supported user journey behaves correctly and understandably;
2. preserve implementation that already meets the product authorities and genuine Student Information System expectations;
3. correct only reproducible defects, unsafe behavior, missing workflow controls, and materially confusing user experiences;
4. exercise representative states rather than a single happy path;
5. produce defensible evidence for the demonstration, panel defense, and later production deployment; and
6. consolidate verified operating instructions and explanations into the existing system guide.

This charter preserves the agreed TAL-96D intent across planning turns and conversation compaction. It is not an implementation plan and does not authorize work outside an approved slice.

## 2. Authority and Conflict Rule

Every TAL-96D plan must use the following authority order:

1. `AGENTS.md` and `TALA-Orchestrator-Protocol.md` own execution and safety rules.
2. `TALA-Rescue-Next-Steps.md` owns issue order and the one active approved slice contract.
3. The active PRD modules own product behavior.
4. `ui_surface_blueprint.md` owns UI-surface mapping.
5. `architecture_specification.md` owns integration and deployment boundaries.
6. Client business evidence clarifies institutional facts but does not silently override product authority.
7. Git history, the live schema, code, tests, and observed behavior supply implementation evidence.
8. Official framework documentation and qualified Student Information System references inform technical correctness and common practice.

The PRD is authoritative by default, but it is not assumed infallible. A verified conflict, client authority correction, or approved product decision must be reconciled through the protocol before implementation. Conversation history and memory must never be the sole durable authority for an active slice.

This charter does not replace the PRD, UI blueprint, architecture specification, CP-SAT formulation, or an approved slice contract.

## 3. Preservation-First Change Control

Every audited element must first be classified:

| Classification | Meaning | Required disposition |
| --- | --- | --- |
| Aligned | It meets the governing product rule and behaves appropriately for a real SIS. | Preserve it. Do not change it merely because another design is possible. |
| Defect or real gap | It is broken, unsafe, inconsistent with authority, materially unclear, unusable, or demonstrably divergent from an applicable SIS norm. | Correct it with cited or reproducible evidence inside an approved slice. |
| Cosmetic preference | It works and meets authority, but a different style or arrangement is preferred. | Do not change it. Record it only as an optional suggestion when useful. |

Trivial, low-risk corrections may be included in an approved remediation loop. Structural behavior, data-model changes, solver-contract changes, dependencies, external-service changes, and material scope expansion require a human-gated plan revision.

## 4. Cost-Conscious Execution Method

TAL-96D is code-first and journey-based. Browser automation is used only for a small representative acceptance path after code and programmatic evidence are stable. The user performs the final scripted visual and interactive checks.

Each journey is examined vertically in this order:

1. cold-start and prerequisite state;
2. role, policy, and authorization;
3. Filament or public surface;
4. input rules and validation messages;
5. action, service, and transaction boundary;
6. resulting records and state transitions;
7. audit and notification effects;
8. downstream projection to other roles;
9. invalid, out-of-order, duplicate, failure, and recovery paths; and
10. the authoritative output visible to the user.

The plan for each slice must map existing coverage before proposing changes. Missing cases should first become named PHPUnit or Livewire scenarios. Native Laravel, Livewire, and Filament v5 behavior is preferred. New dependencies and broad redesigns remain out of scope unless separately approved.

## 5. Required Coverage

The complete TAL-96D chain must cover the roles and states that materially alter behavior, including:

- applicant, student, Registrar, Accounting, faculty, academic head, and system administrator;
- first-time, transferee, returning, regular, irregular, probationary, deficient, completion, and graduation contexts where the PRD supports them;
- document-complete, document-deficient, accepted, rejected, re-upload, duplicate, and handover states;
- active, unavailable, unpublished, closed, full, and out-of-order academic configurations;
- unpaid, partially paid, paid, failed, expired, duplicated, and recovered finance or payment states;
- `FACE_TO_FACE` and `ONLINE` subject offerings;
- enrollment, section-capacity, room-capacity, schedule-conflict, prerequisite, unit-limit, and lifecycle variations; and
- empty, loading, success, failure, blocked, retry, and recovery UI states.

One representative record cannot prove this coverage. Each slice must define the smallest diverse synthetic scenario set needed to exercise its decisions and edge cases.

## 6. Accepted Product Directions

The following decisions govern remaining TAL-96D planning unless later evidence triggers an approved authority correction:

### 6.1 Modality

- The supported modalities are `FACE_TO_FACE` and `ONLINE`.
- Modality belongs to a subject offering, not to an individual student's scheduling track.
- A student's timetable may naturally contain both modalities.
- Face-to-face meetings consume physical rooms; online meetings do not.
- Synthetic schedules may be online-heavy; no equal-modality ratio is required.

### 6.2 Scheduling and sections

- The weekly operating direction is Monday through Saturday, 07:00 to 21:00.
- The current MVP retains separate program cohorts and offerings.
- Shared cross-program common-class modeling remains the post-MVP `TAL-175` boundary because it requires multi-cohort conflict protection and solver-contract review.
- One contiguous weekly meeting block per scheduling demand remains the accepted model.
- No automatic minimum-viable-class-size rule is introduced.
- Tiny cohorts remain valid and must be represented honestly.
- Capacity is not governed by a universal institutional student ceiling. It is governed through configured sections, applicable physical-room capacity, published offerings, and Registrar-confirmed seat reservations.

### 6.3 Programs and academic structure

- Program codes and labels must consistently reconcile DTHM, DIT, and DBM with the approved client-facing names.
- The represented programs use a three-year duration.
- Curriculum and import behavior must preserve data supplied by an accepted curriculum source rather than translate it into an invented parallel shorthand.

### 6.4 Applicant intake

- The completed TAL-96D2A intake uses a Filament Wizard and policy-driven multi-document digital uploads.
- Digital requirements are uploaded and verified per checklist item.
- Physical and metadata-only requirements remain Registrar-tracked.
- Existing private storage, checksums, evidence versioning, audit, duplicate resolution, and transactional handover protections remain preserved.

### 6.5 Human authority

- Scheduling remains human-in-the-loop: CP-SAT produces a candidate, Laravel validates it, and authorized staff control publication.
- The application database and ledger remain authoritative for finance state. A provider redirect alone is not proof of payment.
- Solver output quality is not described as machine-learning accuracy.

## 7. Operating-Order and Defense Questions

Every slice must clarify what must exist before a user can continue and how the system blocks invalid order. The consolidated guide must ultimately answer questions such as:

- What must be configured before applications, curriculum import, offerings, scheduling, enrollment, assessment, payment, grading, progression, and graduation can proceed?
- What happens when the academic year, term, calendar, curriculum, offering, section, or published schedule is missing?
- When and where does an applicant become a student?
- How do regular and irregular enrollment differ?
- Where does an irregular student remain while waiting for published offerings, and what prevents an invalid placement?
- How are additional sections created and how is available capacity determined?
- What does each role see before and after a state change?
- What happens after invalid input, rejection, payment failure, duplicate delivery, queue delay, solver timeout, or downstream transaction failure?
- Which result is provisional, which is official, and who may finalize it?

An unanswered material question is either a documentation gap, an implementation gap, or an unresolved authority decision. The applicable slice must classify and route it instead of silently assuming an answer.

## 8. Population Scenario Contract

TAL-96D will maintain three distinct, executable, synthetic institutional scenarios. The labels describe evidence scenarios, not universal system limits.

| Scenario | Population and structure | Purpose | Claim boundary |
| --- | --- | --- | --- |
| `MIN` | 47 students in the six current program-year sections reported in `business-evidence/currentpopulation.md` | Current-client acceptance and demonstration baseline | Smallest currently reported client population, not the minimum population TALA can support |
| `MIDDLE` | 270 students: three programs x three year levels x one 30-student section per program-year | Representative target operating and defense scenario | Chosen intermediate scenario for a complete three-year institutional picture, not a client census |
| `MAX` | 600 students in 20 sections at about 30 students per section, with 14 faculty | Historical client-scale expansion scenario | Largest historical client context supplied by product authority, not the solver's maximum capacity |

The client-reported ability to operate up to two sections per program and year level informs structural expansion planning. It does not establish an institution-wide maximum.

Before the `MAX` values are used as a formal research claim, TAL-96D2C must place or cite the supporting business evidence, or label them explicitly as client-reported figures.

### 8.1 Seeder requirements

The scenarios must be real, rerunnable data fixtures rather than report-only tables. One guarded parameterized seeder with three scenario definitions is preferred over duplicated implementations.

Each scenario must be:

- deterministic and identifiable;
- structurally complete for its intended journeys;
- safely replaceable without retaining incompatible data from another scenario;
- refused when the runtime is not `APP_ENV=testing`, MySQL, and `test_tala_db`;
- independent from any automatic CP-SAT invocation, Cloud deployment, or external-service mutation; and
- documented with expected record counts and a manifest of scheduling scale.

As applicable to the scenario, structural completeness includes programs, curricula, academic periods, sections, offerings, faculty qualifications and availability, rooms, offering modalities, scheduling demands, applicant and student personas, financial and lifecycle states, and stable demonstration accounts.

Snapshot, destructive rebuild, restoration, and scenario replacement remain human-gated. No seed command may target `tala_db`.

### 8.2 Slice placement

- TAL-96D2B stabilizes academic periods, program identity, three-year curricula, catalog, and import behavior needed by every scenario.
- TAL-96D2C owns final `MIN`, `MIDDLE`, and `MAX` scenario construction, resource/section/offering completeness, and workload manifests.
- TAL-96D3 and TAL-96D4 use those fixtures to verify functional and cross-role behavior.
- TAL-96D5 uses the stable workload manifests for the deferred capacity and resource-selection study.

## 9. Capacity and Configuration Evaluation Contract

The earlier Profile A/B/C and proportional experiments remain valid historical calibration for the corrected solver revision. They must not be relabeled as population scenarios:

- the 54-demand client baseline represents the 47-student client-aligned structure;
- proportional 2x and 4x copied and identifier-remapped scheduling structures;
- those proportional workloads did not represent verified 94-student or 188-student institutions;
- Profile B remains the currently promoted client-baseline configuration unless an explicit later deployment and promotion command changes it; and
- the historical proportional runs should not be repeated automatically.

The final population-based study must use new labels to prevent confusion:

- `MIN-CFG` for the selected configuration evaluated against `MIN`;
- `TARGET-CFG` for the selected configuration evaluated against `MIDDLE`; and
- `MAX-CFG` for the selected configuration evaluated against `MAX`.

Exact CPU, memory, solver-worker, timeout, and cost values are TAL-96D5 results, not assumptions to encode now.

### 9.1 Targeted evaluation method

The study must avoid an expensive full cross-product of every configuration and scenario:

1. derive each scenario's actual workload manifest, including cohorts, sections, offerings, demands, faculty, rooms, candidates, variables, and constraints;
2. confirm local model construction and Laravel readiness without a capacity Cloud run;
3. use the historical Profile B/C evidence to exclude configurations already known to be insufficient;
4. choose one evidence-backed candidate configuration for the scenario;
5. run one authorized screening solve;
6. when the screen is accepted, run the approved confirmation repetitions;
7. test one adjacent configuration only when needed to resolve reliability, cost, or scaling uncertainty; and
8. stop when validity, repeatability, duration, resource use, and cost are sufficiently answered.

Every new Cloud run requires the protocol's external-service and cost gate. TAL-96D2B through TAL-96D4 must not perform capacity benchmarking merely to continue feature hardening.

### 9.2 Required result classification

The evaluation must distinguish:

- infeasible input, where the disclosed constraints genuinely cannot all be satisfied;
- unknown or timed out, where the search ended without enough proof;
- infrastructure failure, such as out-of-memory termination or service error;
- feasible, where a valid schedule is found but optimality is not proven; and
- optimal, where the solver proves no better objective value exists.

An accepted schedule must also pass Laravel's independent hard-constraint validation and publication checks.

### 9.3 Measures and “accuracy”

TALA must report scheduling correctness and quality through measures appropriate to optimization:

- assigned demands divided by total demands;
- zero hard-constraint violations;
- solver status;
- objective value, best bound, and optimality gap where available;
- solve duration and end-to-end duration;
- repeated-run acceptance rate;
- CPU, memory, worker, timeout, and concurrency settings;
- infrastructure failures; and
- estimated cost under the disclosed pricing assumptions.

These measures answer whether the timetable is complete, valid, repeatable, efficient, and cost-proportionate. They must not be combined into an invented machine-learning-style accuracy percentage.

## 10. Slice Responsibilities

| Slice | Primary responsibility | Explicit capacity boundary |
| --- | --- | --- |
| TAL-96D2B | Academic period, program, course catalog, curriculum, and import correctness; establish stable three-year academic inputs | No Cloud capacity benchmark |
| TAL-96D2C | Offerings, sections, faculty, rooms, operating grid, scheduling readiness, and executable `MIN`/`MIDDLE`/`MAX` scenarios with workload manifests | No Cloud capacity benchmark |
| TAL-96D3 | Candidate scheduling, publication, enrollment, finance, COR, PayMongo, queue, ledger, and integration-state hardening | At most a separately authorized functional solve when needed; no population capacity study |
| TAL-96D4 | Grades, lifecycle, Student Hub, reports, cross-role projection, UI/UX consistency, and adversarial scenario coverage | No population capacity study |
| TAL-96D5 | Final adversarial acceptance, targeted population/configuration evaluation, deployment-readiness review, research evidence synchronization, and consolidated guide | Owns authorized population capacity and cost evaluation |

Each slice must inherit this charter but receive its own Ground-Truth Gate, approved contract, focused verification, manual acceptance table, and Cleanup.

## 11. Documentation Ownership

Verified results must be consolidated, not scattered:

- `TALA-System-Operations-and-Defense-Guide.md` owns operating order, user journeys, role-based demonstrations, scenario switching, expected outputs, troubleshooting, recovery, deployment operations, and panel questions and answers.
- `research paper/TALA_CP-SAT_Technical_Formulation.md` owns the mathematical formulation, Laravel validation pipeline, disclosed experimental method, scenario composition, empirical results, resource configuration, cost, scaling evidence, and limitations.
- PRD modules own accepted product behavior.
- `ui_surface_blueprint.md` owns final UI mapping.
- `architecture_specification.md` owns accepted integration and deployment boundaries.
- Slice-specific evidence files may retain reproducibility detail, but the final guide and formulation must remain understandable without directing the reader to internal task chatter.

The mathematical equations do not change merely because population size, Cloud resources, or timeout values change. A solver-contract or constraint-model change is a human-gated correction that requires synchronized code, tests, architecture, and formulation evidence.

No secret, credential value, private applicant data, or real personally identifiable information may appear in committed documentation or fixtures.

## 12. Per-Slice Deliverables

Each TAL-96D slice must produce:

1. intended behavior and authority citations;
2. mapped current implementation;
3. verified behavior;
4. an aligned / defect-gap / preference classification table;
5. evidence-backed corrections for defects or real gaps only;
6. unresolved decisions and routed future boundaries;
7. synthetic data needed to exercise the slice;
8. named programmatic scenarios;
9. one manual acceptance table with role, credential, prerequisites, steps, inputs, expected visible output, expected state change, invalid cases, pass/fail, and observations;
10. likely panel questions with honest answers; and
11. exact documentation updates warranted by verified results.

## 13. Safety and Human Gates

The following remain human-gated:

- destructive database snapshot, rebuild, restoration, or scenario replacement;
- unresolved product authority;
- credentials or secret handling;
- Cloud execution, resource change, deployment, promotion, rollback, or other cost-bearing external mutation;
- dependency addition, structural data-model change, or solver-contract change;
- scope expansion beyond the approved slice;
- subagent use; and
- mandatory-tool failure.

No TAL-96D command implicitly authorizes Linear synchronization, push, pull request creation, production deployment, or external-service mutation.

## 14. Completion and Retirement

TAL-96D is complete only when:

- every scheduled slice has passed independent verification and cleanup;
- material journeys and state variations have programmatic and manual acceptance evidence;
- remaining limitations are explicitly defended or routed;
- the three scenario fixtures and their safe operator procedures are verified;
- the targeted capacity study is complete or honestly bounded by an approved limitation;
- the formulation and architecture reflect verified scheduling facts;
- the consolidated operations and defense guide is complete; and
- TAL-97 can rehearse and present only verified claims.

During TAL-96D5 Cleanup, this charter must be marked completed and either retained as historical governance evidence or archived according to the project documentation rules. It must not remain as a competing live product authority after its verified outcomes have been consolidated into their owning documents.
