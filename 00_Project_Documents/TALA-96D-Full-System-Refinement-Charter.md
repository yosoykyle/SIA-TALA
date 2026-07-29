# TAL-96D Full-System Refinement Charter

**Status:** Active governing charter for the remaining TAL-96D plans

**Applies to remaining work:** `TAL-96D5E1` through `TAL-96D5E2`

**Next execution boundary:** TAL-96D5E1A has completed its browser-free reconciliation, independent verification, and local Cleanup. Its bounded result does not certify usability or authorize unplanned remediation: it preserves the domain model and identifies the evidence-backed recovery order for Registrar-centered operations, Accounting and PayMongo, remaining roles and shared presentation contracts, and concise human acceptance. `Plan TAL-96D5E1B` is next. No capacity rerun, deployment, database scenario switch, production promotion, equation, fixture, schema, external-provider action, or live-surface deletion or merge is implied.

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

### 4.1 Comprehensibility and Responsive Acceptance Contract

Programmatic verification is necessary but cannot by itself prove that a user can understand or operate a surface. Each remaining slice must therefore define and maintain acceptance scenarios while implementing the journey. TAL-96D5B first executes those scenarios agent-led through code, tests, database evidence, and minimal representative rendering, then gives the user one bounded final smoke review after the implementation is stable. The user is not the primary exploratory defect finder.

For each retained list, record, form, action, and cross-role projection, the implementation and its deferred acceptance scenario must establish that the intended user can determine:

1. what record or process is being shown;
2. its current plain-language status;
3. what must happen next;
4. which role or office is responsible;
5. why progress is blocked, when applicable; and
6. how the user can recover or obtain help.

Primary and record actions must remain discoverable and operable at representative phone, tablet, and desktop widths. Native Filament responsive labels, tooltips, action groups, column visibility, and table layouts are preferred. An icon-only action must retain an accessible name and tooltip. Technical codes, timestamps, and diagnostic evidence remain available but must not displace the primary status, next action, responsible office, and recovery explanation.

Focused automated verification remains mandatory in the slice that changes the behavior. User-led execution is required only when an unresolved visual decision, product-authority question, external-provider interaction, or other human-only gate cannot be settled safely from code and programmatic evidence, or when the user explicitly requests an early sample. Otherwise, TAL-96D5B records programmatic evidence first and defers only the smallest genuinely visual or external path to the final bounded smoke review.

TAL-96D4 owns the completed cross-role UI/UX foundation. TAL-96D5B owns accelerated full-system convergence: programmatic adversarial acceptance, deterministic operational-state overlays on `MIDDLE`, evidence-backed remediation, and the bounded final smoke review. A TAL-96D5B failure opens a bounded remediation for the failed journey and repeats only the affected evidence; it does not automatically repeat every completed case.

TAL-96D5B is bounded evidence, not a claim that every role, navigation item, record page, form, action, and cross-role projection has received final visual and workflow certification. Completion of TAL-96D5B therefore does not waive the remaining role-by-role closure required by Section 4.3.

### 4.2 TAL-96D4 UX Scope Amendment

TAL-96D4 is divided into four sequential sub-slices so that shared presentation rules, domain workflows, generated outputs, and the public landing page can be verified without mixing unrelated responsibilities:

1. `TAL-96D4A` establishes the system-wide UX foundation: plain-language hierarchy, responsive Filament actions and tables, accessible loading/empty/success/failure/recovery states, validation feedback, and branded browser error pages that preserve API and framework response behavior.
2. `TAL-96D4B` hardens the Grades and Student Lifecycle journeys from faculty or staff action through student-facing projection.
3. `TAL-96D4C` hardens Student Hub, reports, CSV semantics, authenticated generated outputs, and code-defined notification presentation without reopening aligned source-of-truth workflows.
4. `TAL-96D4D` refines the isolated Bootstrap landing page, closes first-party template and static-diagnostic defects, and performs the final cross-role presentation-consistency closure while preserving the approved landing sections, navigation style, bottom blur strip, role boundaries, and aligned domain workflows.

Native Filament v5 and the existing isolated Bootstrap assets remain the default. No UI dependency or plugin is added without a proven capability gap and a separately approved plan revision. Confirmation is required for destructive or consequential actions, but undo or restore is offered only where the existing domain model supports a safe reversal. CSV coherence concerns headings, field order, formatting, encoding, filenames, authorization, and audit evidence rather than visual branding. Database-editable notification templates remain deferred to `TAL-100`.

### 4.3 Role-by-Role Implementation and Experience Closure

Before TAL-96D can be treated as fully accepted, the remaining work must produce a complete, code-first inventory of every authenticated role and each navigation item, page, resource, form, table, action, generated output, and downstream projection visible to that role. This inventory is a verification obligation, not an assumption that the current implementation is defective.

For every retained surface, the audit must identify:

1. the intended role and real user goal;
2. the surface's plain-language purpose and owning office;
3. its source record, prerequisites, and permitted state transitions;
4. whether it is editable, read-only, an office-result record, or an integration input/output;
5. the producing role or configuration source and every consuming role or surface;
6. validation, authorization, duplicate prevention, and out-of-order guardrails;
7. empty, loading, success, failure, blocked, retry, recovery, and terminal states that materially apply;
8. the expected visible status, next action, responsible office, and help or recovery path;
9. responsive behavior, keyboard and assistive-technology risks, and plain-language labels; and
10. the automated, database, rendered, or human evidence that supports the verdict.

The audit must specifically test producer-to-consumer consistency. A value or rule configured by one authorized role must appear with the same meaning, scope, status, and effective period wherever applicants, students, faculty, Accounting, the Registrar, academic heads, or system administrators consume it. A mismatch, stale projection, hidden dependency, or contradictory label is a defect or real gap; a different but understandable presentation is not automatically a defect.

Forms and actions must be assessed at the point of use. Validation must identify the affected field or prerequisite and explain recovery without waiting until an unrelated final step when the framework can safely report it earlier. Destructive, irreversible, externally consequential, official-publication, financial, identity, and lifecycle-changing actions require authorization and a clear consequence statement, confirmation when it materially prevents mistakes, audit evidence, and idempotency or duplicate protection where applicable. Confirmation must not be added indiscriminately to harmless actions, because confirmation fatigue weakens rather than improves protection. Reversal or undo is offered only when the domain model supports a safe, authorized recovery.

System Settings and integration-monitoring surfaces require an explicit purpose audit. A retained setting must identify who owns it, what behavior it changes, when the change takes effect, who consumes it, how unsafe values are prevented, and how the operator can verify the result. Environment-owned or secret-backed configuration must not be presented as an ordinary editable database setting. An ambiguous, inert, duplicate, or authority-less setting must be classified and then renamed, regrouped, made clearly read-only, deferred, or retired through the normal change-control gate; it must not be removed merely because its current presentation is confusing.

The scheduling review experience requires a dedicated closure check without changing the solver contract by assumption. The authorized reviewer must be able to understand:

- the solver outcome and what that status means;
- the generated timetable and assignment completeness;
- the Laravel hard-constraint revalidation result;
- each applicable hard-constraint category and whether it passed or has a finding;
- the applicable soft objectives, warnings, penalties, or trade-offs actually supported by recorded evidence;
- solve duration and available solution-quality measures; and
- whether the candidate is provisional, accepted, published, superseded, or blocked.

Any manual schedule correction must remain role-authorized, reasoned, audited, and revalidated against the complete candidate before acceptance or publication. The UI must explain the affected assignment and consequences and must never imply that an authorized user may silently bypass a hard constraint. A checklist may display only constraints and results that the solver response or Laravel validation pipeline can factually prove; this charter does not pre-judge that the current UI already captures every required item.

The closure method remains cost-conscious:

1. derive the role/surface inventory and producer-consumer map from routes, panel registration, policies, resources, services, schema, PRD, and blueprint;
2. classify aligned, defect-gap, and preference findings before editing;
3. express behavioral gaps as focused PHPUnit or Livewire scenarios and apply only bounded, evidence-backed remediation;
4. use representative rendered inspection for each role and each distinct interaction pattern after programmatic behavior is stable, rather than making the user explore every page;
5. reserve the user's final review for a concise, role-organized cherry-pick checklist; and
6. record unresolved policy, structural, solver-contract, dependency, cost, and external-service decisions at their human gates.

TAL-96D5C planning must reconcile this closure requirement before running the final regression gate. If the Ground-Truth Gate identifies material remediation, the plan must split D5C into a role/surface closure sub-slice followed by a separate full regression, security, and integration-readiness sub-slice. TAL-96D5D remains the owner of paid population/configuration benchmarking, and TAL-96D5E remains the owner of final evidence consolidation and charter retirement.

### 4.4 Systemic Client-Acceptance Recovery

The client-acceptance findings reopened the earlier D5C1 claim that registered purpose, authorization, and focused tests were sufficient evidence of complete role/surface closure. D5C1 remains valid evidence for registration, policy, service, and regression facts; it is not final proof that the operating order, information hierarchy, terminology, or cross-role workflow is understandable.

The remaining recovery is divided so that the system is not broadly redesigned or retested all at once:

1. `TAL-96D5E1A` reconciles product authority, registered surfaces, source records, code, and tests without browser use or behavior changes.
2. `TAL-96D5E1B` implements the smallest approved Registrar-centered recovery across setup, admissions, offerings, scheduling, enrollment, lifecycle, and student-record projections.
3. `TAL-96D5E1C` implements the smallest approved Accounting and PayMongo recovery while preserving Assessment, Payment Attempt, Payment, Ledger Entry, adjustment, and reconciliation as separate authoritative records.
4. `TAL-96D5E1D` closes remaining-role and shared presentation contracts, including responsive tables, business-question filters, status language, action hierarchy, date meaning, empty/blocked/recovery states, and appropriate task navigation.
5. `TAL-96D5E1E` uses the preserved MIDDLE personas for code-first evidence and a concise role-organized human acceptance pass. It repeats only failed or corrected journeys.

Separate authoritative records are not merged merely because their current navigation is confusing. The recovery may use Filament clusters, resource subnavigation, relation managers, tabs, infolists, action groups, and responsive table layouts to present one task-centered workflow while keeping its normalized records and existing service boundaries. A schema change, record merge, surface deletion, or transfer of office ownership remains a human gate.

The shared recovery contract is:

- navigation describes a task or operating stage rather than exposing every table as an equal destination;
- list pages answer the role's immediate business question with prioritized columns and filters;
- record pages lead with identity, current status, next action, responsible office, and blockers, then expose chronology and technical evidence;
- raw IDs, class names, enum codes, and audit keys remain secondary traceability unless they are the user's actual decision input;
- consequential actions show impact, authority, confirmation, result, and recovery;
- true timestamps use UTC storage and Asia/Manila presentation, while recurring institutional wall-clock values are not timezone-shifted; and
- programmatic evidence precedes the smallest representative human acceptance pass.

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
- Applicant intake does not ask for a personal modality choice. Admissions records the selected term and program; authorized academic setup assigns modality per offering.
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
| `MIN` | 47 students in the six current program-year sections and 9 faculty reported in `business-evidence/currentpopulation.md` | Current-client acceptance and demonstration baseline | Smallest currently reported client population, not the minimum population TALA can support |
| `MIDDLE` | 270 students: three programs x three year levels x one 30-student section per program-year | Representative target operating and defense scenario | Chosen intermediate scenario for a complete three-year institutional picture, not a client census |
| `MAX` | 600 students in 20 sections at about 30 students per section; the historical report also names 14 faculty | Historical client-scale expansion scenario | Largest historical client context supplied by product authority, not the solver's maximum capacity; the reported faculty count is evidence, not an automatically sufficient scheduling roster |

The client-reported ability to operate up to two sections per program and year level informs structural expansion planning. It does not establish an institution-wide maximum.

The current client-aligned curriculum authority produces 77 distinct Second Semester course offerings in `MIDDLE` and 172 section demands in `MAX`. It uses the 23 actual Third Year / Second Semester source rows: eight DBM, seven DIT, and eight DTHM. Course-row units are authoritative, so DBM computes to 25 units although its source prints 28, and DTHM computes to 29 although its source prints 23. TALA records both discrepancies and does not invent a missing DBM course. The completed TAL-96D5D 80-demand MIDDLE and 178-demand MAX experiment remains immutable historical synthetic V1 evidence; its measured solver results are not silently relabeled as results for the corrected fixture.

Before the `MAX` values are used as a formal research claim, TAL-96D2C must place or cite the supporting business evidence, or label them explicitly as client-reported figures.

### 8.1 Faculty evidence and generated scheduling capacity

The scenario fixtures distinguish a reported headcount from a synthetic roster that is sufficient for the constructed teaching load. The bounded calculation uses the configured 21-unit ceiling and the fixture's course qualifications. It does not run CP-SAT and does not prove that a timetable is feasible.

| Scenario | Teaching units | Arithmetic lower bound, `ceil(units / 21)` | Client-reported faculty | Generated scheduling faculty | Maximum constructed load | Bounded result |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| `MIN` | 162 | 8 | 9 | 9 | 19 | Pass |
| `MIDDLE` | 241 | 12 | Not reported for this synthetic tier | 14 | 18 | Pass with operating headroom |
| `MAX` | 534 | 26 | 14 | 26 | 21 | Pass for the synthetic roster; the reported 14 are insufficient for this constructed load |

The arithmetic lower bound is a capacity calculation, not proof of the minimum workable roster. MIN deliberately uses the client's nine reported faculty. MIDDLE retains fourteen synthetic faculty as operating headroom rather than treating twelve as proven sufficient under every qualification and availability pattern. MAX preserves the historical fourteen-faculty fact, but `14 x 21 = 294` units cannot carry the corrected 534-unit workload; the fixture therefore uses a separately identified 26-faculty synthetic roster. The fixtures define no faculty-specific unavailability rows, so this bounded evidence assumes every synthetic faculty record may use the full Monday-to-Saturday operating grid. Real availability restrictions can require more faculty. Each scenario manifest also exposes `unassignable_workloads`: an empty list means every constructed workload found a qualified faculty record within the 21-unit ceiling, while a nonempty list identifies the workload keys that failed this bounded readiness check.

The client evidence also contains categories that must not be copied into unrelated fields. `Freshman` is a year-level description, while `Regular` is an academic-standing value; the acceptance personas use TALA's actual standing model. Likewise, client modality headcounts describe students, while TALA schedules modality per subject offering. Applicant intake therefore does not ask for or write a personal modality choice. The fixture uses only `ONLINE` and `FACE_TO_FACE` offerings and does not convert client headcounts into per-student modality records.

### 8.2 Seeder requirements

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

### 8.3 Slice placement

- TAL-96D2B stabilizes academic periods, program identity, three-year curricula, catalog, and import behavior needed by every scenario.
- TAL-96D2C owns final `MIN`, `MIDDLE`, and `MAX` scenario construction, resource/section/offering completeness, and workload manifests.
- TAL-96D3 and TAL-96D4 use those fixtures to verify functional and cross-role behavior.
- TAL-96D5A reconciles completion readiness and the consolidated acceptance matrix without loading a scenario.
- TAL-96D5B uses `MIDDLE` for agent-led programmatic adversarial acceptance, deterministic downstream-state overlays, and one bounded final human smoke review.
- TAL-96D5C first closes the role-by-role implementation, producer-consumer, Settings-purpose, critical-action, and scheduling-review experience audit, then owns the full regression, security, and integration-readiness gate after any resulting remediation stabilizes.
- TAL-96D5D uses the stable workload manifests for the deferred capacity and resource-selection study.
- TAL-96D5E1 makes `MIDDLE` exploration-ready with deterministic role/state personas, email and PayMongo acceptance, developer spin-up, a first-time journey guide, and guided remediation of evidence-backed findings.
- TAL-96D5E2 consolidates only the resulting verified evidence, records the final deployment-readiness disposition, prepares the TAL-97 claim handoff, and retires this charter.

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

The initial evidence-backed candidate is `TARGET-CFG-01`: `4 vCPU / 8 GiB / 4 solver workers / concurrency 1 / 120-second solver limit / 300-second HTTP timeout`, using the existing immutable solver image and fixed disclosed seed. This is an approved experimental starting point, not a selected production result. Exact accepted configuration, utilization, duration, operating envelope, scaling trigger, and cost remain TAL-96D5D results.

### 9.1 Targeted evaluation method

The study must avoid an expensive full cross-product of every configuration and scenario:

1. derive each scenario's actual workload manifest, including cohorts, sections, offerings, demands, faculty, rooms, candidates, variables, and constraints;
2. confirm local model construction and Laravel readiness without a capacity Cloud run;
3. use the historical Profile B/C evidence and the inconclusive current-profile MIDDLE screen to exclude a wasteful full profile cross-product;
4. use MIDDLE to select one evidence-backed candidate because it is the representative demonstration and operating target, not because it is an arithmetic midpoint;
5. evaluate that same selected configuration against MIDDLE, MIN, and MAX, with one authorized screen plus two approved confirmations for each accepted scenario;
6. test only one evidence-triggered adjacent branch for the whole study: longer search time when memory is stable, or additional memory when OOM/near-exhaustion is observed;
7. cap the study at 12 solver requests unless an approved plan revision supplies new evidence and authority; and
8. stop when validity, repeatability, duration, resource use, and cost are sufficiently answered.

Every new Cloud run requires the protocol's external-service and cost gate. TAL-96D2B through TAL-96D4 must not perform capacity benchmarking merely to continue feature hardening.

The base study stages a private zero-traffic candidate revision and never changes canonical traffic. If MIDDLE requires the single adjacent branch and that branch is accepted, the adjacent configuration becomes the selected candidate evaluated against MIN and MAX. If the selected configuration reaches MAX with repeatable accepted results, the claim is only “verified through the disclosed MAX fixture.” If MAX remains unknown, infeasible, invalid, or infrastructure-bound after the permitted branch, MIDDLE remains the supported operating target and MAX is reported as an observed boundary. Neither outcome establishes an absolute ceiling.

Scaling guidance must use scheduling demands, candidate assignments, model variables and constraints, memory utilization or OOM, duration against the search budget, repeated acceptance, solver status, and optimality gap. Student population is contextual input and must not be the sole configuration trigger. Cost reporting must separate measured or explicitly proxied request cost, total experiment cost, disclosed solve-frequency examples, free-tier treatment, and excluded charges.

The MIDDLE screen and confirmation pair on `TARGET-CFG-01` produced `3/3` accepted feasible results for all `80` demands with zero hard violations. Relative optimality gap ranged from `16.8320877%` to `19.8179851%`, median end-to-end duration was `128.939737` seconds, and the corrected gross three-run request-based cost proxy is `$0.0211756160`. Canonical scheduling-input SHA-256 is `4d38d36e68df40a4482a3b23771275d75f41c56047dc261f9cd67d293e2e91b7`; earlier per-report hashes retained volatile capture time and are not canonical. This establishes repeatable accepted feasibility for the disclosed MIDDLE fixture and immutable revision, not optimality, an absolute ceiling, or production promotion.

The MIN screen and confirmation pair on the same `TARGET-CFG-01` revision produced `3/3` accepted feasible results for all `54` demands with zero hard violations and complete telemetry. Canonical scheduling-input SHA-256 is `837a8bb897dd5883d6558d8b024cd93e9e7f418a45307111c4d21749659c526b`; relative optimality gap ranged from `3.5256988%` to `4.1487866%`, median end-to-end duration was `122.619782` seconds, and the corrected gross three-run request-based cost proxy is `$0.0201717512`. This establishes repeatable accepted feasibility for the disclosed MIN fixture and immutable revision, not optimality, an absolute ceiling, or production promotion.

The corrected MAX fixture contains `178` demands and, in its final captured form, constructs `192492` candidates, `579437` model variables, and `1157585` constraints. Its canonical scheduling-input SHA-256 is `576a5f4ce5e6e5988eb7edd64ce59a20ba61fdc972f7cf57d85dbef1aa48ce38`. A private, independently replayed, non-optimizing witness satisfies candidate membership and Laravel hard-constraint validation for `178/178` demands. This proves the disclosed fixture is feasible; it does not prove that CP-SAT found an incumbent or optimal solution.

The corrected fixture's exploratory `TARGET-CFG-01` and `TARGET-CFG-01-TIME` runs both returned `unknown_timed_out` without an incumbent. The earlier `infeasible` time-extension result belonged to the superseded pre-correction construction and must not be cited against the corrected fixture. `FINAL-CFG-01` then terminated at the 8-GiB memory limit. Its controlled successor `FINAL-CFG-02-MEM` changed only memory to 16 GiB; its earlier image avoided the infrastructure failure but returned `UNKNOWN` without an incumbent inside the unchanged 300-second solver limit.

The approved completion branch preserved every equation and fixture while changing search order. CP-SAT first searched only the existing hard constraints. After finding a complete timetable, the service used that assignment as a complete hint, added the unchanged four-term objective, and optimized with the remaining budget. The one authorized corrected-MAX request returned `FEASIBLE` with 178/178 assignments, zero unassigned demands, zero Python or Laravel hard-constraint violations, objective `1115910`, best bound `0`, relative gap `1.0`, reported runtime `307.819849` seconds, and client elapsed time `314.471862` seconds. This is accepted operational success and places the disclosed corrected-MAX fixture inside the observed envelope of the 8-vCPU, 16-GiB, eight-worker, 300-second staged-search configuration for this run. It does not prove optimality, repeatability, or an absolute population ceiling.

The immutable final report predates the bounded telemetry-persistence correction and therefore does not contain its nested `result_source` and `search_stages` fields. Their missing values are not reconstructed. The runner now retains those validated typed fields for future reports, and focused regression coverage proves the persistence behavior without changing the solver response, equations, fixtures, or final captured assignments.

The original private D5D reports retain their captured evidence unchanged, but their embedded dollar fields are superseded where the wrong rate class was used. The corrected calculation uses the 27 July 2026 Singapore request-based rates of `$0.000011244` per vCPU-second, `$0.000001235` per GiB-second, `$0.40` per million requests, client elapsed time rounded up to 100 milliseconds, and no free-tier credit. The retained eight-run exploratory series totals `$0.0624073856`; the corrected `FINAL-CFG-01` probe-plus-request proxy is `$0.0203565448`; the earlier `FINAL-CFG-02-MEM` probe-plus-request proxy is `$0.0378624112`; and the accepted staged-search probe-plus-request proxy is `$0.03593148`, all before free tier and excluded charges. The earlier immutable reports' embedded `$0.06051832` and `$0.11208928` fields are superseded only as cost estimates. These corrections do not alter any solver status, timing, model count, canonical hash, assignment, or validation result.

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
| TAL-96D4 | Cross-role UI/UX hardening parent for the four approved D4 sub-slices | No population capacity study |
| TAL-96D4A | System-wide UX foundation, responsive interaction controls, validation and feedback states, and branded browser error handling | No population capacity study |
| TAL-96D4B | Grades and Student Lifecycle vertical hardening, including faculty/staff action and student-facing projection | No population capacity study |
| TAL-96D4C | Student Hub, reports, CSV semantics, authenticated generated outputs, and code-defined notification presentation | No population capacity study |
| TAL-96D4D | Isolated Bootstrap landing-page refinement and final cross-role presentation-consistency closure | No population capacity study |
| TAL-96D5A | Completion-readiness and acceptance-matrix reconciliation | No scenario replacement, browser walkthrough, full-suite gate, or Cloud capacity evaluation |
| TAL-96D5B | Accelerated full-system convergence against representative `MIDDLE`: programmatic adversarial acceptance, deterministic operational-state overlays, bounded remediation, and one final human smoke review | Functional acceptance only; no population capacity study |
| TAL-96D5C | Role-by-role implementation and experience closure, including producer-consumer consistency, Settings purpose, critical-action guardrails, scheduling-review evidence, followed by the full regression, security, and integration-readiness gate | No population capacity study |
| TAL-96D5D | Targeted `MIN-CFG` / `TARGET-CFG` / `MAX-CFG` population, resource, solution-quality, and cost evaluation | Owns authorized population capacity and cost evaluation |
| TAL-96D5E | Parent split for exploration readiness followed by evidence consolidation and retirement | No new capacity study |
| TAL-96D5E1 | Exploration-ready `MIDDLE` personas and compatible operational states, email and PayMongo acceptance, developer spin-up, first-time role journey guide, and guided exploration/remediation | No capacity study; at most one separately authorized functional `MIDDLE` solve when no accepted candidate can be restored |
| TAL-96D5E1A | Browser-free system-truth, workflow, surface, and authority reconciliation; reopens overstated D5C1 comprehension claims and routes evidence-backed recovery | Read-only; no browser acceptance, database mutation, external request, or implementation change |
| TAL-96D5E1B | Registrar-centered operational recovery and affected-role projections | No schema redesign, capacity study, Cloud solve, or external-provider request |
| TAL-96D5E1C | Accounting and PayMongo operational recovery while preserving ledger and evidence boundaries | No live payment, webhook, or provider request without a separate human gate |
| TAL-96D5E1D | Remaining-role and shared presentation-contract closure | No broad visual rewrite or dependency addition |
| TAL-96D5E1E | Exploration evidence, first-time guide, and concise human acceptance over the stable MIDDLE fixture | No capacity study; repeat only failed or corrected representative journeys |
| TAL-96D5E2 | Final evidence consolidation, deployment-readiness disposition, TAL-97 handoff, and charter retirement after D5E1 verification and Cleanup | No implementation or external run unless a newly proven material gap receives an approved plan revision |

Each slice must inherit this charter but receive its own Ground-Truth Gate, approved contract, focused verification, maintained acceptance scenarios, and Cleanup. TAL-96D5B proves scenarios programmatically wherever possible and routes only genuinely visual, policy-authority, destructive, credentialed, cost-bearing, or external-provider interactions to a human gate. TAL-96D5E1 performs code-first and programmatic preflight before the user follows the guide as a first-time operator; it classifies and corrects only evidence-backed defects or real gaps and repeats only failed or corrected journeys. TAL-96D5E2 cannot consolidate or retire this charter while a material D5E1 finding remains unresolved.

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
9. additions to the consolidated acceptance table with role, credential, prerequisites, steps, inputs, expected visible output, expected state change, invalid cases, evidence source, pass/fail, and observations; TAL-96D5B executes these programmatically first and retains only bounded human-smoke steps that cannot be proven otherwise;
10. likely panel questions with honest answers; and
11. exact documentation updates warranted by verified results; and
12. for TAL-96D5C, a complete role/surface inventory and producer-consumer traceability matrix with an explicit purpose and disposition for Settings and other unclear administrative surfaces; and
13. for TAL-96D5E1A, a corrected system-truth report that distinguishes registered purpose and backend correctness from proven workflow comprehensibility, then routes every material finding to D5E1B–E.

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
- every retained role surface has a verified purpose, understandable operating state, appropriate validation and action guardrails, and consistent producer-to-consumer behavior;
- Settings and scheduling-review surfaces have explicit evidence-backed dispositions rather than assumed acceptance;
- remaining limitations are explicitly defended or routed;
- the three scenario fixtures and their safe operator procedures are verified;
- the targeted capacity study is complete or honestly bounded by an approved limitation;
- the formulation and architecture reflect verified scheduling facts;
- the consolidated operations and defense guide is complete; and
- TAL-97 can rehearse and present only verified claims.

During TAL-96D5E2 Cleanup, this charter must be marked completed and either retained as historical governance evidence or archived according to the project documentation rules. It must not remain as a competing live product authority after its verified outcomes have been consolidated into their owning documents.
