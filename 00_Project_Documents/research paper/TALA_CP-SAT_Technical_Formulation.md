# TALA Scheduling Optimization: Constraint Programming–Satisfiability (CP-SAT) Formulation, Laravel Validation, and Empirical Capacity

**Document type:** Standalone technical and empirical specification

**System scope:** CP-SAT candidate timetable generation with Laravel-controlled validation, human review, and publication

**Technical baseline date:** 18 July 2026

**Empirical revision date:** 18 July 2026 — Cloud Run profile selection, capacity evidence, deployment outcome, and cost basis; solver equations are unchanged

## Contents

1. [Technical summary](#1-technical-summary)
2. [Scope and delimitations](#2-scope-and-delimitations)
3. [End-to-end system pipeline](#3-end-to-end-system-pipeline)
4. [Contract, profile, and data representation](#4-contract-profile-and-data-representation)
5. [Mathematical formulation](#5-mathematical-formulation)
6. [Implemented objective function](#6-implemented-objective-function)
7. [Solver outcomes and operational failures](#7-solver-outcomes-and-operational-failures)
8. [Laravel validation and human authority](#8-laravel-validation-and-human-authority)
9. [MVP justification and current gaps](#9-mvp-justification-and-current-gaps)
10. [Worked example from the implemented fixture](#10-worked-example-from-the-implemented-fixture)
11. [Controlled benchmark experiment and operating envelope](#11-controlled-benchmark-experiment-and-operating-envelope)
12. [Equation-to-implementation traceability](#12-equation-to-implementation-traceability)
13. [References](#13-references)

### Equation-label convention

Every displayed equation includes an **Interpretation** defining its symbols and scheduling purpose. Labels beginning with **H** denote mandatory hard constraints, **S** denotes soft ranking terms, and **O** denotes the combined optimization objective.

### Terminology

| Term | Meaning in this document |
| --- | --- |
| Candidate timetable | A proposed schedule that still requires Laravel validation and authorized human review before publication. |
| Constraint programming | A method that searches for values satisfying stated rules instead of trying every timetable manually. |
| CP-SAT | The OR-Tools Constraint Programming–Satisfiability solver used to select and rank candidate assignments. |
| OR-Tools | Google's open-source Operations Research toolkit; TALA uses its CP-SAT component. |
| Scheduling Demand | One required course component, such as one lecture or laboratory, for one offering and one student group. It is the unit assigned by the solver. |
| Offering | A course made available for a particular academic term. |
| Course-specific delivery group | The database record connecting one course offering to the section that receives it. Different subjects for the same students have different delivery-group records so each assignment remains traceable. |
| Logical cohort | The students who attend all subjects together, such as `DTBM-1A`. Every course-specific delivery group for that cohort shares one conflict identity so its subjects cannot overlap. |
| Faculty eligibility | Evidence that a faculty member is qualified and permitted to teach the demand. |
| Candidate assignment | One allowed combination of a demand, faculty member, room or no-room option, day, and start time. |
| Hard constraint | A mandatory rule. A timetable violating it is rejected. |
| Soft objective | A preference used to rank timetables that already satisfy every hard constraint. |
| Feasible | All mandatory rules are satisfied, although the search has not proved that no better-ranked timetable exists. |
| Optimal | All mandatory rules are satisfied and CP-SAT has proved that no better objective value exists for the tested model and input. |
| Snapshot | The immutable copy of the exact scheduling input sent to the solver for one run. |
| Profile | The approved list of hard-rule identifiers and soft-objective weights. It is separate from the input/output data-contract version. |
| Slot | One permitted 30-minute start-grid unit. A multi-hour meeting occupies several consecutive units but remains one assignment. |
| Incumbent | The best valid timetable CP-SAT has found so far during a run. |
| Bound | CP-SAT's mathematical limit on how good an undiscovered solution could still be. |
| Relative optimality gap | The normalized distance between the incumbent objective and the bound; smaller is stronger evidence, and zero with `optimal` status proves optimality. |
| Relative percentage deviation (RPD) | The percentage distance between one run's objective and the best objective observed within the same experimental tier. It is not an accuracy score. |
| Worker | One CP-SAT search thread used inside a solver request. |
| Concurrency | The number of HTTP requests a Cloud Run instance is permitted to process simultaneously; it is not the same as CP-SAT worker count. |
| Telemetry | Typed measurements returned or monitored for a run, such as model size, runtime, objective, bound, CPU, and memory use. |
| IAM | Identity and Access Management, the Cloud Run access control that keeps the solver private to its authorized service identity. |
| HTTP | Hypertext Transfer Protocol, the request/response protocol used between Laravel and the private solver service. |

## 1. Technical summary

TALA uses constraint programming to generate a **candidate** academic timetable. It does not allow the optimization service to create official meetings directly. Laravel remains the authoritative application: it validates source records, captures an immutable run snapshot, dispatches the computation, independently validates the returned result, stores candidate rows, requires authorized human review, revalidates mutable records, and only then publishes official `section_meetings`.

The Python service uses Google OR-Tools CP-SAT to select one feasible candidate assignment for every ready Scheduling Demand. Each candidate combines a demand with a qualified faculty member, a suitable room or no-room option, a day, and a start time. Boolean variables represent whether those candidates are selected. Hard constraints make invalid combinations impossible; a fixed four-term objective ranks the remaining valid schedules.

### 1.1 Data Contract and Optimization Profile

Two independently versioned components govern the Laravel-to-Python integration. The `tal94-demand-v2` **data contract** specifies the structure and semantics of the immutable scheduling snapshot and solver response. It defines the required identifiers, Scheduling Demand fields, resource and calendar inputs, assignment fields, counters, warnings, infeasibility reasons, objective details, and model metadata exchanged by both services. Laravel writes the value to `contract_version`; the solver rejects an incompatible value; and Laravel verifies the returned `model_version` before accepting assignments. The contract version identifies the integration interface and does not represent the release version of the complete TALA application.

The `balanced_v1` **optimization profile** specifies the active rule configuration carried within that contract. It fixes the accepted hard-constraint identifiers and assigns a weight of one to each implemented soft objective. The solver rejects any change to the profile key, profile version, ordered hard-constraint list, or weight set. Profile version 1 therefore denotes the first approved optimization preset for this interface; it does not identify the solver or application release.

| Identifier | Meaning | Current value |
| --- | --- | --- |
| Model input/output contract | The shape and meaning of the complete Laravel-to-Python request and Python-to-Laravel response | `tal94-demand-v2` |
| Optimization profile | The approved rule preset carried inside the request: hard-constraint catalog plus soft-objective weights | `balanced_v1`, version `1` |

Independent versioning separates interface compatibility from optimization policy. A later profile may revise ranking priorities without changing the exchanged schema, whereas a later data contract may introduce fields or semantics that require coordinated Laravel and Python changes.

### 1.2 Representative Solver Exchange

This section presents an abbreviated Laravel-to-solver exchange using a two-demand illustrative fixture. Its purpose is to document the contract fields without reproducing the substantially larger 54-demand client-representative benchmark dataset reported in Section 11.

The fixture represents two separate one-hour classes. Demand `5001` is eligible for faculty record `200`, demand `5002` is eligible for faculty record `201`, and both require physical rooms. The hard-constraint list defines mandatory validity conditions, while the four weights rank timetables that already satisfy those conditions.

The following abbreviated input illustrates how the data-contract version and optimization profile are transmitted with those Scheduling Demands. The complete immutable snapshot additionally contains term settings, time slots, faculty and room records, qualifications, availability, commitments, calendar blocks, source identifiers, and run metadata.

```json
{
  "contract_version": "tal94-demand-v2",
  "student_cohort_groups": [
    {
      "section_delivery_group_id": 1101,
      "cohort_or_student_group_id": 110
    },
    {
      "section_delivery_group_id": 1102,
      "cohort_or_student_group_id": 110
    }
  ],
  "scheduling_demands": [
    {
      "scheduling_demand_id": 5001,
      "section_delivery_group_id": 1101,
      "cohort_or_student_group_id": 110,
      "required_duration_minutes": 60,
      "eligible_faculty_user_ids": [200],
      "room_required": true
    },
    {
      "scheduling_demand_id": 5002,
      "section_delivery_group_id": 1102,
      "cohort_or_student_group_id": 110,
      "required_duration_minutes": 60,
      "eligible_faculty_user_ids": [201],
      "room_required": true
    }
  ],
  "constraint_profile": {
    "key": "balanced_v1",
    "version": 1,
    "hard_constraints": [
      "assign_every_ready_scheduling_demand_once",
      "faculty_no_overlap",
      "room_no_overlap",
      "section_delivery_group_no_overlap",
      "respect_fixed_assignments",
      "respect_calendar_blocks",
      "respect_room_capacity_type_and_features",
      "respect_faculty_qualification_and_load"
    ],
    "soft_weights": {
      "prefer_earlier_time_blocks": 1,
      "reduce_faculty_idle_gaps": 1,
      "balance_faculty_load": 1,
      "use_rooms_efficiently": 1
    }
  }
}
```

| Input field | Definition | Value in the illustrative exchange |
| --- | --- | --- |
| `contract_version` | Identifies the agreed request/response structure. | Laravel and Python both expect `tal94-demand-v2`. |
| `scheduling_demands` | Lists the required class components that must each receive one assignment. | Two one-hour classes must be scheduled. |
| `scheduling_demand_id` | Stable internal identifier used to match a result to its source requirement. | `5001` and `5002` identify the two requirements; they are not student counts. |
| `section_delivery_group_id` | Preserves the course-specific delivery record used for reconciliation and publication. | `1101` and `1102` are different because the demands belong to different subjects. |
| `cohort_or_student_group_id` | Identifies the students who attend together across subjects and therefore share one no-overlap rule. | Both demands use `110`, so their selected meetings cannot overlap. |
| `student_cohort_groups` | States the authoritative delivery-group-to-cohort mapping captured by Laravel. | Delivery groups `1101` and `1102` both map to cohort `110`. |
| `required_duration_minutes` | Length of the uninterrupted meeting. | Each class needs 60 minutes. |
| `eligible_faculty_user_ids` | Faculty records already proven qualified for that demand. | Demand `5001` may use faculty `200`; demand `5002` may use faculty `201`. |
| `room_required` | States whether the meeting consumes a physical room. | Both values are `true`, so a suitable room is mandatory. |
| `constraint_profile` | Names the approved hard rules and soft weights used for this run. | `balanced_v1`, version 1 is required without user editing. |
| `hard_constraints` | Mandatory rule families. | Coverage, non-overlap, fixed values, calendar, room, qualification, and load rules must pass. |
| `soft_weights` | Fixed importance multipliers used only to rank valid timetables. | Each of the four implemented terms has weight 1. |

The solver response repeats the model identity and provides its native status, candidate assignments, diagnostics, and reconciled objective details:

```json
{
  "model_version": "tal94-demand-v2",
  "solver_status": "optimal",
  "assignments": [
    {
      "scheduling_demand_id": 5001,
      "section_delivery_group_id": 1101,
      "cohort_or_student_group_id": 110,
      "faculty_id": 200,
      "room_id": 301,
      "day_of_week": 1,
      "starts_at": "09:00:00",
      "ends_at": "10:00:00"
    },
    {
      "scheduling_demand_id": 5002,
      "section_delivery_group_id": 1102,
      "cohort_or_student_group_id": 110,
      "faculty_id": 201,
      "room_id": 301,
      "day_of_week": 1,
      "starts_at": "08:00:00",
      "ends_at": "09:00:00"
    }
  ],
  "objective_details": {
    "profile_key": "balanced_v1",
    "profile_version": 1,
    "total": 18900
  },
  "solver_statistics": {
    "ortools_version": "9.15.6755",
    "candidate_count": 6,
    "model_variable_count": 31,
    "model_constraint_count": 59,
    "no_overlap_constraint_count": 4,
    "best_objective_bound": 18900.0,
    "relative_optimality_gap": 0.0,
    "worker_count": 1,
    "random_seed": 20260718
  }
}
```

| Output field | Definition | Value in the illustrative exchange |
| --- | --- | --- |
| `model_version` | Confirms which data contract the solver actually processed. | It matches the submitted `tal94-demand-v2` request. |
| `solver_status` | States the mathematical search outcome. | `optimal` means the returned timetable is valid and no better objective value exists for this small fixture. |
| `assignments` | Gives the source delivery group, shared cohort, selected faculty, room, day, start, and end for each demand. | The two classes share cohort `110` and room `301` but use adjacent, non-overlapping times. |
| `objective_details.total` | Gives the reconciled four-term ranking score. | The total is `18900`; it is a score, not an accuracy percentage. |
| `solver_statistics` | Gives typed evidence about model size, search quality, runtime configuration, and reproducibility. | Six candidates became a 31-variable, 59-constraint model with zero relative gap. |
| `worker_count` | Number of CP-SAT search threads used inside this request. | This small default fixture uses one worker; current production profile B uses two workers. |
| `random_seed` | Integer used to control randomized search choices. | The disclosed seed is `20260718`. |

The returned model identity binds the result to the submitted contract. The profile identity and objective total provide an auditable connection between the selected assignments and the fixed ranking policy. The abbreviated statistics above show the categories returned by the implementation; the complete allowlist additionally records input counts, presolved Boolean variables, branches, conflicts, deterministic time, wall time, and other typed search evidence. Laravel rejects missing, malformed, or unknown statistics fields and never persists raw solver logs. It accepts the response only after validating these values and independently rechecking the assignment set. The complete representation is defined in Section 4, while Section 12 maps the formulation to its implementation and tests.

## 2. Scope and delimitations

### 2.1 In scope

The implemented baseline schedules regular section delivery groups by assigning each ready Scheduling Demand to:

- one eligible faculty member;
- one suitable physical room, or no room for a demand that does not require one;
- one institutional day and start time; and
- one uninterrupted block whose duration comes from the demand.

It enforces exact assignment coverage, faculty/room/delivery-group non-overlap, fixed assignments, recurring calendar restrictions, room capacity/type/features, faculty qualification/load, and configured same-faculty links. It ranks valid solutions using earlier placement, faculty idle-gap reduction, faculty-load balance, and efficient use of already-suitable rooms.

### 2.2 Delimitations

The baseline deliberately does not perform the following tasks:

- It does not schedule raw subjects. Laravel first converts approved academic records into canonical Scheduling Demands.
- It does not assign individual irregular students. Irregular-student conflict checks occur during post-publication enrollment placement.
- It does not treat absolute holidays or dated exceptions as weekly CP-SAT variables. The weekly solver receives recurring blocks; dated occurrences remain under Laravel's calendar and operational revision handling.
- It does not independently optimize generalized student/section compactness, similarity to a previous published version, or requested faculty time preferences.
- It does not split one Scheduling Demand into multiple weekly meetings. The current contract requires `meeting_count = 1`; a large component is represented as one uninterrupted block of its required duration.
- It does not approve, publish, or revise the official schedule. Those decisions remain authorized Laravel workflows.
- It does not expose user-editable hard constraints or weights. The solver accepts only the unchanged `balanced_v1` profile.

These delimitations do not make the scheduler unusable. The hard constraints necessary for a feasible MVP master schedule are implemented. The omitted preferences concern further refinement or different downstream problems, not the core validity of a candidate schedule.

## 3. End-to-end system pipeline

The integration follows a controlled pipeline rather than a single “run solver” action.

1. **Authoritative record preparation.** Laravel uses terms, course specification revisions, curricula, term offerings, sections, delivery groups, rooms, faculty qualifications and loads, and recurring calendar blocks as source records.
2. **Scheduling Demand generation and readiness.** Laravel converts the approved records into `scheduling_demands`. Readiness checks reject missing or contradictory inputs before optimization, including section-capacity, contact-time, eligibility, load, room, calendar-grid, and expected-count requirements.
3. **Authorized run creation.** The generation service authorizes the user, locks the term, prevents a competing active run, and creates a `schedule_runs` record in the `queued` state.
4. **Immutable snapshot capture.** Within a database transaction, Laravel locks the run and captures the exact ready demands, source identifiers, recurring constraints, time grid, and `balanced_v1` profile. The snapshot and its hash preserve what the solver actually received even if live records later change.
5. **After-commit queue dispatch.** Laravel dispatches the scheduling job only after the run transaction commits. The job uses the dedicated `scheduling` queue, a 360-second job timeout, at most three attempts, and bounded backoff.
6. **CP-SAT computation.** The Python service validates the `tal94-demand-v2` contract and unchanged profile, constructs admissible candidates, creates Boolean selection variables and hard constraints, maximizes the fixed objective, and returns structured assignments and diagnostics.
7. **Independent result ingestion.** Laravel does not trust a returned “optimal” label by itself. In a locking transaction, it checks run/model identity, counters, solver status, objective arithmetic, assignment fields, exact coverage, and all implemented hard constraints. Invalid results block the run and do not replace previously preserved candidates.
8. **Candidate review and correction.** A valid result becomes `under_review` and is stored in `candidate_schedule_rows`, not `section_meetings`. The Registrar can review the table, propose a correction, or perform an evidenced manual replacement. Laravel revalidates the whole candidate set before applying a change.
9. **Live-record revalidation and impact check.** Before publication, Laravel rebuilds validation inputs from current mutable records. It also prevents unsafe whole-version replacement when active student bindings already depend on the published version.
10. **Authorized publication.** Only an authorized Registrar can publish. Laravel atomically creates the official `section_meetings`, marks the run published with its version, actor, note, and provenance, supersedes the previous version where allowed, and records post-transaction notifications.

This separation preserves a practical middle ground between automation and institutional control: CP-SAT handles the combinatorial search, while Laravel and authorized personnel retain validation and approval authority.

## 4. Contract, profile, and data representation

### 4.1 Scheduling Demand as the schedulable unit

A Scheduling Demand represents one required course component for one term offering and one course-specific delivery group. Lecture and laboratory components can therefore become separate but linked demands when their duration, room, modality, or faculty requirements differ.

The course-specific delivery-group identifier preserves the exact source record used for publication. A second field, `cohort_or_student_group_id`, identifies the logical attendance cohort across subjects. Laravel builds that mapping from the exact term, program, curriculum year level, and cohort code. For example, ten different first-year Business Management subjects may have ten delivery-group records, but all ten map to the single logical cohort `DTBM-1A`. This distinction preserves traceability without weakening the rule that the same students cannot attend overlapping classes.

The solver receives stable TALA identifiers rather than re-deriving institutional meaning. This allows Laravel to reconcile every returned row with its source demand and preserves auditability from input through publication.

### 4.2 Integer representation

CP-SAT operates on integer-valued expressions. The implementation therefore represents:

- selection and linking decisions as Boolean variables;
- day as an integer institutional day index;
- time as minutes from midnight and stable time-block identifiers;
- duration as an integer number of minutes; and
- faculty load units multiplied by 100, so `3.00` units becomes `300`.

This scaling avoids using floating-point variables in the optimization model. The [official OR-Tools CP-SAT documentation](https://developers.google.com/optimization/cp/cp_solver) likewise describes CP-SAT as an integer programming solver and requires non-integer constraints to be converted to integer form.

### 4.3 Candidate-based formulation

Laravel sends the immutable requirements; Python enumerates only combinations that satisfy deterministic single-candidate rules. This means room suitability, fixed values, faculty availability, recurring blocks, and the time grid narrow the candidate set before Boolean selection begins. CP-SAT then represents every candidate as a Boolean selection decision and a corresponding optional fixed-size interval. Resource/day `NoOverlap` constraints enforce faculty, room, and logical-cohort exclusivity over the selected intervals, while aggregate constraints enforce linked-component faculty and faculty load.

### 4.4 Illustrative formulation example

Consider a cohort that must attend a one-hour IT101 lecture. Laravel represents that requirement as one Scheduling Demand. Two qualified faculty members, two suitable rooms, and four permitted start times produce sixteen possible candidate assignments. CP-SAT creates one Boolean decision variable for each candidate and selects exactly one. Candidates that overlap another meeting for the same faculty member, room, or logical cohort cannot be selected together. The four soft terms then rank the remaining valid timetables according to earlier placement, reduced faculty idle gaps, balanced faculty load, and efficient use of suitable rooms.

The symbols in Sections 5–6 describe this process compactly. For example, $d$ means a demand, $c$ means one candidate assignment for that demand, and $x_c=1$ means that candidate is selected. The complete two-demand numerical example appears in Section 10.

## 5. Mathematical formulation

### Constraint taxonomy and implemented family index

The formulation distinguishes four related concepts that must not be conflated:

1. A **hard constraint** is mandatory. A selected assignment that violates it is not an acceptable schedule.
2. A **candidate-admissibility rule** enforces a hard requirement before optimization by excluding invalid faculty, room, time, or fixed-value combinations from the candidate set.
3. A **soft objective** ranks schedules that already satisfy every hard requirement. A lower soft score does not make an otherwise valid schedule infeasible.
4. **Laravel revalidation** is an independent acceptance boundary, not another solver constraint. Laravel treats the solver response as untrusted and rejects inconsistent or institutionally invalid assignments before candidate rows can be reviewed or published.

The `balanced_v1` profile carries eight versioned hard-constraint family identifiers. A family may require several mathematical statements, so the identifiers are not forced into an artificial one-family/one-equation correspondence. The `F` labels below are document navigation labels; the exact runtime identifiers remain unchanged.

| Family | Exact `balanced_v1` identifier | Mathematical rules | CP-SAT enforcement | Laravel revalidation |
| --- | --- | --- | --- | --- |
| F1 | `assign_every_ready_scheduling_demand_once` | H1, supported by H2a-H2b | Equality for each demand over admissible Boolean candidates | Exact demand coverage, duration, time-grid, and assignment-field checks |
| F2 | `faculty_no_overlap` | H6 | `NoOverlap` over selected faculty/day intervals | Faculty-time conflict validation |
| F3 | `room_no_overlap` | H7 | `NoOverlap` over selected room/day intervals | Room-time conflict validation |
| F4 | `section_delivery_group_no_overlap` | H8 | `NoOverlap` over selected shared-cohort/day intervals | Shared-cohort conflict validation while retaining course-specific delivery-group traceability |
| F5 | `respect_fixed_assignments` | H3 | Candidate filtering against every supplied fixed value | Fixed faculty, room, day, and start-time comparison |
| F6 | `respect_calendar_blocks` | H4 | Candidate filtering against captured recurring blocks and commitments | Calendar, availability, and existing-commitment overlap checks |
| F7 | `respect_room_capacity_type_and_features` | H4, H5a-H5c | Candidate filtering through the room-suitability predicate | Physical-room requirement, capacity, type, and feature checks |
| F8 | `respect_faculty_qualification_and_load` | H4, H9, H10a-H10d | Candidate filtering plus linked-faculty and aggregate-load constraints | Qualification, linked-component faculty, deduplicated load, and maximum-load checks |

This family index describes the implemented profile; it does not add a new constraint, profile version, or optimization policy.

### 5.1 Sets and indices

Let:

- $D$ be the set of ready Scheduling Demands;
- $F$ be the set of faculty members present in the snapshot;
- $R$ be the set of active physical rooms;
- $O$ be the set of term offerings;
- $G$ be the set of course-specific delivery-group records;
- $H$ be the set of logical attendance cohorts;
- $C_d$ be the set of admissible candidate assignments generated for demand $d \in D$; and
- $C = \bigcup_{d \in D} C_d$ be the complete candidate set.

A candidate $c \in C$ is the tuple

$$
c = \bigl(d(c), f(c), r(c), g(c), h(c), o(c), \delta_c, s_c, e_c\bigr),
$$

**Interpretation.** The tuple is one complete candidate assignment. Here $c$ is the candidate; $d(c)$, $f(c)$, $r(c)$, $g(c)$, $h(c)$, and $o(c)$ identify its demand, faculty member, room, course-specific delivery group, shared logical cohort, and offering; $\delta_c$ is its day; and $s_c$ and $e_c$ are its start and end minutes. The separate $g(c)$ and $h(c)$ values preserve both source-record traceability and cross-course student-conflict enforcement.

The mapping $h(c)=\gamma(g(c))$ is captured by Laravel in `student_cohort_groups`; the solver rejects a demand whose direct cohort field conflicts with that mapping.

### 5.2 Parameters

For every demand $d$ and candidate $c$:

- $p_d$ is the required duration in minutes;
- $q_d$ is the expected regular cohort count;
- $\kappa_r$ is room $r$'s capacity;
- $T_d$ is the required room type;
- $A_d$ is the set of required room feature keys;
- $A_r$ is room $r$'s feature-key set;
- $u_d$ is the demand's faculty load multiplied by 100;
- $M_f$ is faculty member $f$'s maximum allowed load multiplied by 100; and
- $\mathcal{B}$ contains recurring calendar, availability, and existing-commitment blocks captured in the snapshot.

Define the strict interval-overlap predicate

$$
\operatorname{overlap}(c,c') =
[\delta_c = \delta_{c'}]
\land [s_c < e_{c'}]
\land [s_{c'} < e_c].
$$

**Interpretation.** This predicate returns true only when candidates $c$ and $c'$ occur on the same day and each starts before the other ends. The square brackets denote true-or-false comparisons, while $\land$ means “and.” TALA uses the strict comparisons to detect real time collisions while allowing one meeting to begin exactly when another ends.

Adjacent assignments, for example 08:00–09:00 and 09:00–10:00, therefore do not overlap.

### 5.3 Decision and auxiliary variables

For each candidate $c \in C$, define

$$
x_c =
\begin{cases}
1, & \text{if candidate } c \text{ is selected},\\
0, & \text{otherwise.}
\end{cases}
$$

**Interpretation.** $x_c$ is the Boolean decision for candidate $c$: one means selected and zero means not selected. This is the model's basic choice variable. Every hard rule and soft score refers to these decisions so CP-SAT can build one coherent timetable.

The implementation creates each $x_c$ with OR-Tools [`new_bool_var`](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html), whose domain is $\{0,1\}$.

Each candidate also has an optional fixed-size interval $\mathcal{I}_c$ whose presence is controlled by $x_c$:

$$
\operatorname{present}(\mathcal{I}_c) \iff x_c=1,
\qquad
\operatorname{start}(\mathcal{I}_c)=s_c,
\qquad
\operatorname{size}(\mathcal{I}_c)=p_{d(c)},
\qquad
\operatorname{end}(\mathcal{I}_c)=e_c.
$$

**Interpretation.** $\mathcal{I}_c$ is candidate $c$'s optional time interval. It exists only when $x_c=1$, begins at $s_c$, lasts $p_{d(c)}$ minutes, and ends at $e_c$. This lets OR-Tools apply global non-overlap rules only to selected meetings rather than to every possible candidate.

The implementation creates this interval with OR-Tools [`new_optional_fixed_size_interval_var`](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html). An unselected candidate remains absent from interval constraints.

For faculty load, define $y_{f,o,g} \in \{0,1\}$ to indicate whether faculty member $f$ is selected for at least one component in offering $o$ and delivery group $g$. Let $L_f$ be the resulting scaled load. For the objective, $\Delta_{ff'}$ represents an absolute load difference, while the faculty/day compactness variables are defined in Section 6.2.

### 5.4 Exact demand coverage

Every ready demand must be assigned exactly once:

**H1 — Exact demand coverage**

$$
\sum_{c \in C_d} x_c = 1
\qquad \forall d \in D.
$$

**Interpretation.** For each demand $d$ in the ready-demand set $D$, $C_d$ is its candidate set and the selected indicators $x_c$ must sum to exactly one. The rule calculates coverage and prevents both omission and duplication. In the running example, exactly one of IT101's sixteen candidates must be selected.

If candidate filtering leaves $C_d = \varnothing$, the service returns an `infeasible` result with a source-oriented reason instead of constructing a misleading partial schedule. This equation is the core completeness rule: the optimizer cannot silently omit a ready demand or assign it twice.

### 5.5 Duration, time grid, and consecutive block

Each candidate represents one uninterrupted meeting:

**H2a — Required contiguous duration**

$$
e_c = s_c + p_{d(c)}.
$$

**Interpretation.** A candidate's end minute $e_c$ equals its start minute $s_c$ plus the required duration $p_{d(c)}$ of its demand. This keeps the meeting contiguous and ensures a one-hour demand occupies one uninterrupted 60-minute interval.

A candidate is admitted only when its start is an allowed time-grid point and its complete duration fits within the configured end of the institutional day:

**H2b — Institutional time-grid and day-boundary compliance**

$$
s_c \in \mathcal{S}_{\delta_c},
\qquad
e_c \leq E_{\delta_c}.
$$

**Interpretation.** $\mathcal{S}_{\delta_c}$ is the allowed start-time set for day $\delta_c$, and $E_{\delta_c}$ is that day's closing minute. The candidate must begin on the institutional grid and finish no later than closing time. This prevents off-grid or partly out-of-hours meetings before CP-SAT ranks them.

Because the current contract requires `meeting_count = 1`, a six-hour laboratory demand is modeled as one six-hour candidate interval, not twelve independently selectable half-hour decisions. All overlap rules apply to that complete interval. Thus the implementation satisfies the MVP's single-day consecutive-block requirement with one optional interval per candidate rather than separate decision variables for each sub-slot.

### 5.6 Fixed assignments and admissibility

Let $\bar f_d$, $\bar r_d$, $\bar\delta_d$, and $\bar s_d$ denote optional fixed values. Candidate construction enforces

**H3 — Fixed assignment preservation**

$$
f(c)=\bar f_d,\quad
r(c)=\bar r_d,\quad
\delta_c=\bar\delta_d,\quad
s_c=\bar s_d
$$

**Interpretation.** A barred symbol is a preassigned value: $\bar f_d$ for faculty, $\bar r_d$ for room, $\bar\delta_d$ for day, and $\bar s_d$ for start time. When Laravel supplies one of these values for demand $d$, every retained candidate $c$ must match it. A fixed institutional decision is therefore mandatory, not merely preferred.

whenever the corresponding value is fixed. A conflicting fixed value therefore produces no admissible candidate rather than being treated as a soft preference.

The same candidate-set rule excludes a candidate when its faculty member is not eligible, its time lies outside the faculty's captured availability, or its interval intersects a matching existing commitment or recurring calendar block:

**H4 — Qualified, available, unblocked, grid-valid, and room-suitable candidate admissibility**

$$
C_d = \{c : \operatorname{eligible}(c) \land
\neg\operatorname{blocked}(c,\mathcal{B}) \land
\operatorname{fitsGrid}(c) \land
\operatorname{roomSuitable}(c)\}.
$$

**Interpretation.** This set definition says $C_d$ contains only candidates that pass all single-candidate checks. `eligible` covers faculty qualification; `blocked` checks captured availability, commitments, and calendar blocks $\mathcal{B}$; `fitsGrid` checks time; and `roomSuitable` checks the room. Removing invalid choices here reduces model size and stops CP-SAT from ever selecting them.

### 5.7 Room suitability, features, and capacity

For a physical-room demand, candidate $c$ is admissible only when

**H5a — Physical-room capacity**

$$
\kappa_{r(c)} \geq q_{d(c)},
$$

**Interpretation.** $\kappa_{r(c)}$ is the capacity of candidate $c$'s room, and $q_{d(c)}$ is the expected cohort count for its demand. The room must hold at least that many students. This is needed even though TALA schedules cohorts, because a valid cohort meeting still requires enough seats.

**H5b — Required room type**

$$
T_{d(c)} = \varnothing
\quad\lor\quad
T_{d(c)} = T_{r(c)},
$$

**Interpretation.** $T_{d(c)}$ is the demand's required room type and $T_{r(c)}$ is the room's recorded type. $\varnothing$ means no specific type is required; otherwise the types must match. This prevents, for example, a laboratory requirement from being assigned to an unsuitable ordinary room.

and

**H5c — Required room features**

$$
A_{d(c)} \subseteq A_{r(c)}.
$$

**Interpretation.** $A_{d(c)}$ is the set of required feature keys and $A_{r(c)}$ is the room's feature set. The subset symbol means every required feature must be present. A room may have additional features, but it cannot lack a required one.

The expected regular cohort must also fit its section capacity, but Laravel enforces that readiness condition before snapshot capture. The solver's room predicate then enforces the second physical-capacity boundary. A no-room modality uses $r(c)=\varnothing$ and does not consume a physical room.

### 5.8 Faculty, room, and logical-cohort non-overlap

Partition the candidate intervals into resource/day buckets:

$$
C^F_{f,\delta}
=
\{c\in C : f(c)=f \land \delta_c=\delta\},
$$

**Interpretation.** $C^F_{f,\delta}$ groups all candidates using faculty member $f$ on day $\delta$. It does not select anything; it prepares the interval list used by H6 so one faculty member cannot be double-booked.

$$
C^R_{r,\delta}
=
\{c\in C : r(c)=r \land \delta_c=\delta\},
$$

**Interpretation.** $C^R_{r,\delta}$ groups all candidates using physical room $r$ on day $\delta$. This bucket becomes the input to H7 and makes room conflicts explicit.

and

$$
C^H_{h,\delta}
=
\{c\in C : h(c)=h \land \delta_c=\delta\}.
$$

**Interpretation.** $C^H_{h,\delta}$ groups all candidates attended by logical cohort $h$ on day $\delta$. Course-specific delivery groups that serve the same students therefore enter the same bucket. H8 uses this bucket to stop those students from being assigned simultaneous subjects.

These bucket equations define the inputs to the following global constraints; they are not additional hard rules.

**H6 — Faculty non-overlap**

$$
\operatorname{NoOverlap}
\left(\{\mathcal{I}_c : c\in C^F_{f,\delta}\}\right)
\qquad \forall f\in F,\ \forall \delta,
$$

**Interpretation.** `NoOverlap` requires the selected intervals $\mathcal{I}_c$ in each faculty/day bucket to be disjoint. $F$ is the faculty set and $\delta$ ranges over institutional days. This is the direct mathematical statement that one faculty member cannot teach two meetings at the same time.

**H7 — Physical-room non-overlap**

$$
\operatorname{NoOverlap}
\left(\{\mathcal{I}_c : c\in C^R_{r,\delta}\}\right)
\qquad \forall r\in R,\ \forall \delta,
$$

**Interpretation.** The same global interval rule is applied to every physical room $r$ in room set $R$ on every day. It prevents two selected meetings from occupying the same room simultaneously.

**H8 — Logical-cohort non-overlap**

$$
\operatorname{NoOverlap}
\left(\{\mathcal{I}_c : c\in C^H_{h,\delta}\}\right)
\qquad \forall h\in H,\ \forall \delta.
$$

**Interpretation.** The same rule is applied to every logical cohort $h$ in cohort set $H$. It prevents the students in one regular cohort from being required in two meetings at once, even when the meetings originate from different course-specific delivery-group rows.

OR-Tools considers only present intervals in each `NoOverlap` constraint. Therefore two selected candidates cannot overlap when they share a faculty member, physical room, or logical cohort. This is semantically equivalent to excluding every conflicting selected pair, but it avoids materializing a separate linear inequality for every candidate pair.

### 5.9 Same-faculty rule for linked components

Let $D_{o,g}^{\mathrm{same}}$ be linked demands for offering $o$ and delivery group $g$ whose source rule requires one faculty member. For every pair $d,d' \in D_{o,g}^{\mathrm{same}}$ and every eligible faculty member $f$,

**H9 — Configured same-faculty requirement for linked components**

$$
\sum_{\substack{c \in C_d\\ f(c)=f}} x_c
=
\sum_{\substack{c \in C_{d'}\\ f(c)=f}} x_c.
$$

**Interpretation.** For linked demands $d$ and $d'$ in the same offering/group rule, both sides count whether faculty member $f$ was selected. Equality for every eligible $f$ forces the linked components to use the same faculty member. This applies only when the source record explicitly requires that linkage.

Combined with exact demand coverage, these equalities force all configured linked components to select the same faculty member. When the source rule is false, lecture and laboratory demands may select different qualified faculty.

### 5.10 Faculty-load accounting

The implementation counts the load of one offering/delivery-group combination once even when linked components produce multiple demand rows. For every candidate in a faculty/offering/group bucket,

**H10a — Selected-candidate activation of the deduplicated load bucket**

$$
x_c \leq y_{f,o,g},
$$

**Interpretation.** $y_{f,o,g}$ is the yes/no indicator that faculty $f$ teaches at least one selected component for offering $o$ and delivery group $g$. If any candidate $x_c$ in that bucket is selected, this inequality forces $y_{f,o,g}$ to one. It begins the protection against counting linked lecture/laboratory components twice.

and

**H10b — Exact activation of the deduplicated load bucket**

$$
y_{f,o,g}
\leq
\sum_{\substack{c \in C:\\ f(c)=f,\,o(c)=o,\,g(c)=g}} x_c.
$$

**Interpretation.** The reverse inequality prevents $y_{f,o,g}$ from becoming one when no candidate in the bucket is selected. Together with H10a, it makes the indicator exactly match actual assignment activity.

Together, these constraints make $y_{f,o,g}=1$ exactly when at least one candidate in that bucket is selected. If $U_{f,o,g}$ is the maximum scaled unit value in the bucket, then

**H10c — Faculty load aggregation without linked-component double counting**

$$
L_f = \sum_{(o,g)} U_{f,o,g}y_{f,o,g},
$$

**Interpretation.** $L_f$ is faculty member $f$'s total scaled load. $U_{f,o,g}$ is the load value for one offering/group bucket, and $y_{f,o,g}$ includes it once when active. This aggregation preserves institutional load accounting without double-counting linked components.

subject to

**H10d — Maximum permitted faculty load**

$$
L_f \leq M_f
\qquad \forall f \in F.
$$

**Interpretation.** $M_f$ is faculty member $f$'s permitted maximum scaled load. Requiring $L_f\leq M_f$ for every faculty member prevents CP-SAT from producing a timetable that is conflict-free but exceeds an approved teaching load.

This prevents lecture/laboratory components belonging to one enrollment line from double-counting the same offering load while still enforcing the configured default or approved faculty load limit.

## 6. Implemented objective function

Hard constraints first define the valid region. The four implemented soft terms then rank only schedules inside that valid region.

| Soft label | Exact `balanced_v1` identifier | Solver expression | Quality represented | Laravel acceptance check |
| --- | --- | --- | --- | --- |
| S1 | `prefer_earlier_time_blocks` | Linear selected-candidate reward $E=\sum a_cx_c$ | Earlier institutional day/time placement | Returned raw value, fixed weight, weighted value, and total reconciliation |
| S2 | `reduce_faculty_idle_gaps` | Auxiliary faculty/day span and duration variables produce $I=-\sum G_{f,\delta}$ | Less internal idle time between a faculty member's meetings | Returned raw value, fixed weight, weighted value, and total reconciliation |
| S3 | `balance_faculty_load` | Absolute-equality variables produce $B=-\sum\Delta_{ff'}$ | Smaller pairwise differences in deduplicated faculty load | Returned raw value, fixed weight, weighted value, and total reconciliation |
| S4 | `use_rooms_efficiently` | Linear selected-candidate reward $R=\sum h_cx_c$ | Preference for a smaller room after suitability already passes | Returned raw value, fixed weight, weighted value, and total reconciliation |

Laravel does not reinterpret these preferences as hard constraints. It verifies the captured profile identity, the expected term set and weights, each weighted calculation, the returned total, and equality between that total and `objective_score`.

**O1 — Implemented weighted objective**

$$
\max Z =
w_E E + w_I I + w_B B + w_R R.
$$

**Interpretation.** $Z$ is the total timetable-ranking score to maximize. $E$, $I$, $B$, and $R$ are the earlier-time, idle-gap, load-balance, and room-efficiency terms; $w_E$, $w_I$, $w_B$, and $w_R$ are their approved weights. This equation ranks only schedules that already satisfy every hard rule; it cannot make an invalid timetable acceptable.

For `balanced_v1`, version 1,

$$
w_E=w_I=w_B=w_R=1.
$$

**Interpretation.** The current `balanced_v1` profile assigns weight one to all four terms. The equation records the implemented policy and allows Laravel to recompute the returned weighted total. It does not mean the raw terms have identical numerical ranges.

The profile is code-defined and rejected if any key, hard-constraint order, version, or weight is changed.

### 6.1 Earlier institutional time blocks

With day index $\delta_c$ and start minute $s_c$, define the supporting candidate score

$$
a_c = \max\bigl(0, 10000-(1000\delta_c+s_c)\bigr),
$$

**Interpretation.** $a_c$ is candidate $c$'s nonnegative earlier-time reward. The day index $\delta_c$ is weighted by 1,000 and the start minute $s_c$ is added, so later days and times receive smaller values; `max` prevents a negative reward. The constants are ranking parameters, not minutes or percentages.

and the first implemented soft term:

**S1 — Earlier institutional time-block score**

$$
E = \sum_{c \in C} a_c x_c.
$$

**Interpretation.** $E$ adds the earlier-time reward $a_c$ for every selected candidate, identified by $x_c=1$. Maximizing $Z$ therefore prefers earlier institutional blocks when all mandatory rules and other weighted terms permit it.

This favors earlier days and start times among otherwise feasible choices. Late/weekend placement is therefore managed primarily by the configured operating grid and recurring blocking records, with this term giving an additional ranking preference toward earlier institutional blocks.

### 6.2 Faculty idle-gap penalty

For every faculty/day bucket $C^F_{f,\delta}$, define the activity indicator

$$
z_{f,\delta}
=
\max_{c\in C^F_{f,\delta}} x_c.
$$

**Interpretation.** $z_{f,\delta}$ equals one when faculty member $f$ has at least one selected candidate on day $\delta$, otherwise zero. It lets the model distinguish a working day from an inactive day so an empty faculty/day bucket receives no artificial gap.

Let $H^{\min}_{f,\delta}$ and $H^{\max}_{f,\delta}$ be the earliest candidate start and latest candidate end in the bucket. For each candidate, define effective endpoints

$$
\widetilde{s}_c =
\begin{cases}
s_c, & x_c=1,\\
H^{\max}_{f,\delta}, & x_c=0,
\end{cases}
\qquad
\widetilde{e}_c =
\begin{cases}
e_c, & x_c=1,\\
H^{\min}_{f,\delta}, & x_c=0.
\end{cases}
$$

**Interpretation.** $\widetilde{s}_c$ and $\widetilde{e}_c$ are effective endpoints used only to compute a faculty member's occupied span. Selected candidates keep their real start $s_c$ and end $e_c$; unselected candidates receive harmless boundary values $H^{\max}_{f,\delta}$ and $H^{\min}_{f,\delta}$ so they cannot become the minimum start or maximum end.

The first selected start, last selected end, and total selected teaching duration are

$$
S^{\mathrm{first}}_{f,\delta}
=
\min_{c\in C^F_{f,\delta}} \widetilde{s}_c,
$$

**Interpretation.** $S^{\mathrm{first}}_{f,\delta}$ is the earliest selected start for faculty $f$ on day $\delta$. The minimum is taken over the effective starts, so unselected candidates do not affect it.

$$
E^{\mathrm{last}}_{f,\delta}
=
\max_{c\in C^F_{f,\delta}} \widetilde{e}_c,
$$

**Interpretation.** $E^{\mathrm{last}}_{f,\delta}$ is the latest selected end for that faculty/day bucket. Together with the earliest start, it defines the outer span of the teaching day.

and

$$
P_{f,\delta}
=
\sum_{c\in C^F_{f,\delta}} p_{d(c)}x_c.
$$

**Interpretation.** $P_{f,\delta}$ sums the duration $p_{d(c)}$ of each selected candidate taught by faculty $f$ on day $\delta$. This is actual teaching time, not the full elapsed span from first class to last class.

The internal idle gap is

$$
G_{f,\delta}
=
\begin{cases}
E^{\mathrm{last}}_{f,\delta}
-S^{\mathrm{first}}_{f,\delta}
-P_{f,\delta}, & z_{f,\delta}=1,\\
0, & z_{f,\delta}=0.
\end{cases}
$$

**Interpretation.** $G_{f,\delta}$ is internal idle time. On an active day, it subtracts total teaching duration $P_{f,\delta}$ from the span between the first start and last end; on an inactive day it is zero. For meetings 08:00–09:00 and 10:00–11:00, the span is 180 minutes, teaching is 120 minutes, and the internal gap is 60 minutes.

The implemented raw objective term is

**S2 — Faculty internal idle-gap score**

$$
I = -\sum_{f\in F}\sum_{\delta} G_{f,\delta}.
$$

**Interpretation.** $I$ is the negative total of all faculty/day idle gaps. Because the overall objective is maximized, a schedule with fewer gap minutes has a less-negative and therefore better score. This encourages compact faculty timetables without turning compactness into a mandatory rule.

Because the selected intervals for a faculty/day bucket cannot overlap, $G_{f,\delta}$ equals the sum of the gaps between consecutive selected meetings. A single meeting contributes zero; an inactive bucket is explicitly forced to zero. The term is neither pairwise nor capped.

### 6.3 Faculty-load balance penalty

For each unordered faculty pair, OR-Tools enforces

$$
\Delta_{ff'} = |L_f-L_{f'}|,
$$

**Interpretation.** $\Delta_{ff'}$ is the absolute difference between the scaled loads of faculty members $f$ and $f'$. Absolute value makes both “A has more” and “B has more” contribute the same imbalance amount.

using the official [`add_abs_equality`](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html) model operation. The raw balance term is

**S3 — Faculty-load balance score**

$$
B = -\sum_{\{f,f'\}\subseteq F}\Delta_{ff'}.
$$

**Interpretation.** $B$ is the negative sum of load differences across every unordered faculty pair. Maximization favors smaller total disparity. It is a ranking preference and does not override qualification or maximum-load rules.

The term compares all faculty rows in the snapshot, including a faculty member with zero selected load. Because loads are scaled by 100, a one-unit difference contributes 100 penalty points.

### 6.4 Efficient use of suitable rooms

Candidate filtering already guarantees capacity, type, and feature suitability. The ranking term then uses the coarse score

$$
h_c =
\begin{cases}
100, & r(c)=\varnothing \text{ or the recorded capacity is non-positive},\\
\max(0,1000-\kappa_{r(c)}), & \text{otherwise},
\end{cases}
$$

**Interpretation.** $h_c$ is candidate $c$'s room-efficiency reward. A no-room candidate or nonpositive recorded capacity receives 100; otherwise a smaller suitable room receives a larger value through $1000-\kappa_{r(c)}$, never below zero. Suitability is already mandatory, so this equation only ranks rooms that have passed the hard checks.

and

**S4 — Efficient suitable-room score**

$$
R = \sum_{c\in C} h_c x_c.
$$

**Interpretation.** $R$ adds the room reward $h_c$ for selected candidates. This gives the objective a simple preference for using smaller suitable rooms rather than occupying unnecessarily large rooms.

Among suitable physical rooms, this prefers a smaller room over a larger room. It is intentionally a simple MVP proxy; it is not an occupancy percentage or a direct seat-slack equation.

### 6.5 Objective reconciliation

The service returns `objective_details` containing each term's raw value, fixed weight, weighted value, and total. Laravel independently verifies the equivalent reconciliation of O1:

$$
Z = \sum_{k\in\{E,I,B,R\}} w_k z_k
$$

**Interpretation.** This is the generic reconciliation form of O1. $k$ ranges over the four objective terms, $z_k$ is the returned raw value for term $k$, and $w_k$ is its fixed profile weight. Laravel repeats this arithmetic and rejects a response whose term values do not reproduce the returned total $Z$.

and that the returned `objective_score` matches the reconciled total. A solver label without consistent counters and objective arithmetic is not accepted.

## 7. Solver outcomes and operational failures

[OR-Tools defines five CP-SAT outcome categories](https://developers.google.com/optimization/cp/cp_solver), which the service maps to lowercase values:

| Result word | What it means | Is there a candidate timetable? | What TALA does next |
| --- | --- | --- | --- |
| `optimal` | CP-SAT found a timetable satisfying every hard rule and proved that no better objective value exists for the tested model and input. | Yes. | Laravel independently validates it before human review. |
| `feasible` | CP-SAT found a timetable satisfying every hard rule, but search stopped before proving that it was the best-ranked possible timetable. | Yes. | Laravel independently validates it; the Registrar may review or reject it. |
| `infeasible` | CP-SAT proved that no assignment can satisfy all hard rules for the exact tested input, or deterministic filtering left a demand with no allowed candidate. | No. | Staff inspect the source-oriented reason, correct inputs or use the controlled manual path, then rerun or revalidate. |
| `model_invalid` | The request, model, or approved profile was malformed or unsupported. This is an input/contract problem, not a difficult timetable. | No. | TALA blocks ingestion and records the specific validation failure. |
| `unknown` | Search ended without finding a valid timetable and without proving infeasibility. It commonly means the time limit ended before CP-SAT reached a conclusion. | No. | Treat it as inconclusive; do not call it infeasible. A separately approved run may use more time or resources. |
| Operational failure | The request failed outside the mathematical search, for example authentication, network, queue, service, or out-of-memory failure. | Not reliably. | TALA records and classifies the failure; it does not infer a solver conclusion. |

These outcomes differ from **operational failures**. Network errors, authentication failures, invalid HTTP payloads, service unavailability, queue timeouts, and container termination after exceeding a runtime memory limit occur outside the mathematical model. A terminated container may return no CP-SAT status at all and must not be interpreted as mathematical infeasibility. Laravel classifies transport and runtime failures, records operational evidence, retries only bounded retryable failures, and ultimately marks the run `failed` when processing cannot complete. A structurally or mathematically unacceptable returned result becomes `blocked` during ingestion. Only an independently valid usable result becomes `under_review`.

## 8. Laravel validation and human authority

### 8.1 Readiness and immutable evidence

Laravel validates the institutional records before dispatch and captures only ready demands. The immutable JSON snapshot, input hash, contract version, profile, source IDs, recurring blocks, and requested run metadata provide reproducible evidence. Later record edits do not rewrite that historical input.

### 8.2 Queue and transaction boundary

Run creation and snapshot capture use database transactions and row locks. Laravel's [database transaction](https://laravel.com/docs/12.x/database#database-transactions) behavior rolls back the enclosed database changes if an exception occurs. Dispatch uses [`afterCommit`](https://laravel.com/docs/12.x/queues#jobs-and-database-transactions), so the worker does not receive a run that the database has not committed. The job timeout is 360 seconds while the database queue's `retry_after` is 420 seconds, preserving Laravel's documented requirement that the [job timeout remain shorter than the retry visibility window](https://laravel.com/docs/12.x/queues#worker-timeouts).

### 8.3 Independent output validation

Laravel rechecks, rather than assumes, the following result properties:

- expected contract/model/run identity;
- accepted solver status (`optimal` or `feasible` for candidate ingestion);
- assigned, unassigned, warning, and violation counters;
- objective-detail arithmetic;
- the exact typed `solver_statistics` allowlist, including the worker count approved for that invocation and the fixed seed;
- one assignment for every snapshot demand and no duplicates;
- returned faculty, room, day, time, duration, fixed assignment, and source relationships;
- faculty, room, and delivery-group conflicts;
- qualifications, room suitability, recurring blocks, same-faculty links, and deduplicated faculty load; and
- persistence-ready candidate fields.

Candidate replacement occurs atomically only after all checks pass. A failed validation preserves existing candidate rows and blocks the run.

### 8.4 Revalidation against mutable records

The immutable snapshot proves what was solved, but publication must also be correct now. Laravel therefore rebuilds a validation context from current authoritative records before accepting a manual correction or publishing. The live path also considers conflicts with already official meetings. This closes the gap between a historically valid solver response and a current institutional decision.

### 8.5 Human correction and publication

The Registrar can reject an institutionally poor but mathematically valid result, correct a candidate, or provide an evidenced manual schedule replacement. The action records authority and reason, and Laravel validates the whole proposed set before saving it. Authorization uses Laravel [policies and Gates](https://laravel.com/docs/12.x/authorization) in addition to navigation visibility.

The solver never grants publication authority. Only the authorized publication service may copy validated candidate rows into official meetings. Academic Head review supports scheduling exceptions, while the Registrar is the MVP publisher. System-administration access does not imply academic publication authority.

## 9. MVP justification and current gaps

### 9.1 MVP scheduling sufficiency

Usability depends first on correctness: every ready demand must receive one valid block without violating faculty, room, delivery-group, calendar, qualification, capacity, fixed-assignment, load, or linked-faculty rules. Those rules are hard constraints or deterministic admissibility checks. Therefore a returned `optimal` or `feasible` candidate that also passes Laravel's independent validation is operationally usable for human review and publication.

The four fixed soft terms are appropriate for the MVP because they are measurable from the immutable snapshot, repeatable across runs, explainable to the Registrar, and reconcilable by Laravel:

- earlier placement discourages unnecessarily late or later-week assignments;
- idle-gap reduction improves faculty timetable continuity;
- load balancing avoids concentrating assigned load when alternatives exist; and
- the room proxy avoids occupying an unnecessarily large already-suitable room.

Equal weights remove arbitrary user tuning from the first approved baseline. Human review remains the safeguard when institutional judgment should outweigh the mathematical ranking.

### 9.2 Relationship to the broader PRD preference list

The broader PRD lists seven desired preferences. The current solver implements four as explicit objective terms: earlier blocks, reduced faculty idle gaps, balanced faculty load, and efficient room use. It does **not** claim seven independently implemented objectives.

- **Reduce late/weekend scheduling:** handled through the allowed operating grid, recurring unavailability/break blocks, and the earlier-day/time score; it is not a separately reported objective term.
- **Compact student/section schedules:** regular delivery-group overlap is a hard constraint, but generalized compactness is not independently optimized.
- **Minimize change from a previous published version:** not independently optimized in this baseline. Publication impact checks and controlled revisions protect live operations, but they are not a CP-SAT similarity term.
- **Faculty requested-time preference:** explicitly outside approved scope; mandatory unavailable blocks are enforced instead.

These are refinement boundaries, not evidence that the completed baseline failed. Any future objective must be separately approved, represented in a new versioned profile, made transparent in the returned objective details and related result evidence, and revalidated by Laravel.

## 10. Worked example from the implemented fixture

The repository's deterministic `minimal_snapshot.json` contains two one-hour lecture demands:

In this example, a **demand** is one required lecture. An **offering** is the record saying that a course is available in the selected term. A **delivery group** is the student cohort that attends the meeting together; using the same group identifier means the two demands must not overlap for those students. **Eligible faculty** identifies the qualified person the solver is allowed to assign. **Candidate starts** are the possible start times remaining after time-grid and other single-candidate checks. The numbers are stable internal identifiers, not headcounts or scores.

| Required meeting (demand) | Course-in-term record (offering) | Cohort attending together (delivery group) | Faculty allowed to teach | Suitable room | Possible start times |
| --- | ---: | ---: | ---: | --- | --- |
| `5001`: IT101 lecture | `300`: IT101 offered in the test term | `110`: the shared test cohort | `200`: one qualified faculty record | R-101, capacity 40 | Monday 08:00, 08:30, 09:00, 09:30 |
| `5002`: IT102 lecture | `301`: IT102 offered in the test term | `110`: the same test cohort | `201`: another qualified faculty record | R-101, capacity 40 | Monday 08:00, 08:30, 09:00, 09:30 |

Both demands use the same room and delivery group, so overlapping one-hour candidates cannot both be selected. Running the implemented fixture with OR-Tools 9.15.6755 produces an `optimal` result:

| Demand | Faculty | Room | Day | Time |
| --- | ---: | --- | ---: | --- |
| 5001 | 200 | R-101 | 1 (Monday) | 09:00–10:00 |
| 5002 | 201 | R-101 | 1 (Monday) | 08:00–09:00 |

The intervals are adjacent and therefore non-overlapping. The objective is reconciled as follows.

Earlier-time score:

$$
E = [10000-(1000+540)] + [10000-(1000+480)]
=8460+8520=16980.
$$

**Interpretation.** $E$ is the earlier-time score. Monday is day index 1, while 540 and 480 are 09:00 and 08:00 expressed as minutes after midnight. Applying the S1 candidate formula to both selected meetings produces 16,980 points; this is a ranking value, not a percentage.

Idle-gap penalty:

$$
I=0,
$$

**Interpretation.** $I$ is the faculty idle-gap score. Each meeting uses a different faculty member, so neither faculty/day bucket contains time between two meetings; the total gap and its negative score are both zero.

because the assignments use different faculty members.

Load-balance penalty, with both three-unit loads scaled to 300:

$$
B=-|300-300|=0.
$$

**Interpretation.** $B$ is the load-balance score. Each three-unit load is stored as 300, their absolute difference is zero, and the negative penalty is therefore zero.

Room score:

$$
R=(1000-40)+(1000-40)=960+960=1920.
$$

**Interpretation.** $R$ is the room-efficiency score. R-101 has capacity 40, so each selected suitable-room candidate contributes $1000-40=960$, for a total of 1,920.

With all weights equal to one,

$$
Z=16980+0+0+1920=18900.
$$

**Interpretation.** $Z$ is the complete `balanced_v1` objective. With every weight equal to one, the four raw terms add to 18,900. Laravel independently repeats this calculation before it accepts the candidate result.

The returned `objective_score` and `objective_details.total` are both `18900`. The exact selection between the two symmetric subject-to-time assignments is not institutionally significant; exact coverage, non-overlap, and the reconciled total are the material properties.

## 11. Controlled benchmark experiment and operating envelope

### 11.1 Evaluation purpose and scale basis

The empirical evaluation is a **controlled benchmark experiment**. Defined workloads and measures provide a consistent basis for comparing Cloud Run profiles, while deliberate variation of resource settings, workload size, and search duration permits observation of their effects. The evaluation is neither a survey of institutions, an algorithm competition, nor a predictive-accuracy test.

The experiment answers three bounded questions: whether the implemented TALA pipeline produces valid client-scale schedules; which Cloud Run profile gives the strongest client-scale solution quality without unnecessary resources; and what larger synthetic workload is repeatably accepted before a time, model, or memory boundary is observed. It does not position TALA as a direct replacement for enterprise whole-university timetabling and does not treat optimization as predictive classification.

#### Representative dataset and timetable output

| Program | First-year cohort | Second-year cohort | Contextual students |
| --- | ---: | ---: | ---: |
| Business Management (BM) | 10 | 2 | 12 |
| Information Technology (IT) | 10 | 3 | 13 |
| Tourism and Hospitality Management (THM) | 15 | 7 | 22 |
| **Total** | **35** | **12** | **47** |

The client reports 47 students in six program-year cohorts. These cohort sizes determine expected attendance and room-capacity requirements; the solver schedules cohort meetings rather than creating individual decision variables for every student.

The representative baseline is structurally complete scheduling input stored through the application's normal tables and relationships. It is not a preconstructed timetable. The distinction between inputs and outputs is as follows:

| Data layer | Composition | Function |
| --- | --- | --- |
| Client-derived academic context | BM, IT, and THM curricula; six program-year cohorts; cohort sizes totaling 47 students | Establishes which cohort-course requirements must be represented and the expected class sizes. |
| Seeded scheduling inputs | 47 synthetic student profiles; 54 term offerings, course-specific sections, delivery groups, and Scheduling Demands; 12 synthetic faculty records with qualifications and 21-unit load limits; 6 synthetic rooms with types and capacities; course modality and room requirements; and the weekly scheduling grid | Exercises the complete curriculum-to-demand and demand-to-solver pipeline without using live personal or operational records. |
| Solver candidate output | One assignment per demand containing the subject and section identifiers, selected eligible faculty, weekday, start and end time, and room when required | Forms a complete proposed timetable. Laravel rejects missing, duplicate, overlapping, or otherwise invalid assignments. |
| Published application output | Validated candidate assignments copied into official meeting records after authorized review | Produces the normal user-facing timetable by subject and section, with faculty, day, time, and room information. |

For example, the first-year Business Management cohort `DTBM-1A` contains 10 students and 10 curriculum-derived course requirements. Each requirement becomes a term offering, a course-specific section, a course-specific delivery group with expected count 10, and a Scheduling Demand. Those ten delivery groups share the logical cohort identity for `DTBM-1A`. For BME05 (Retail Management), the seed supplies the course, cohort, duration, modality, eligible faculty qualification, room type, expected count, and permissible time grid. CP-SAT supplies the selected faculty, room, weekday, and start and end times. The same process applies to the other subjects, producing a conventional timetable rather than an aggregate workload count.

The fixture assigns one active synthetic faculty qualification per distinct subject and uses a common weekly scheduling grid. It therefore exercises complete assignment generation, faculty and room non-overlap, room suitability, cross-course logical-cohort conflicts, and faculty-load validation, but it does not reproduce the client's actual faculty roster, individual availability, room inventory, or alternative qualified-faculty pools. The benchmark establishes end-to-end capability for the disclosed client-shaped input; it does not represent every possible institutional constraint pattern.

The corrected post-promotion acceptance published 54 official meetings inside a rolled-back transaction. The following cohort totals prove that all six logical cohorts received every required subject.

| Logical cohort | Program and year | Required and published meetings |
| --- | --- | ---: |
| `DTBM-1A` | Business Management, first year | 10 |
| `DTBM-2A` | Business Management, second year | 9 |
| `DIT-1A` | Information Technology, first year | 8 |
| `DIT-2A` | Information Technology, second year | 8 |
| `DTHM-1A` | Tourism and Hospitality Management, first year | 10 |
| `DTHM-2A` | Tourism and Hospitality Management, second year | 9 |
| **Total** | **Six logical cohorts** | **54** |

The next three tables show one complete first-year timetable produced by the promoted solver revision. “Not required” means the online meeting does not consume a physical room. Every row is one official meeting after Laravel validation; no two rows within the same cohort overlap.

**Business Management — `DTBM-1A`**

| Course | Component | Assigned faculty | Day | Time | Room | Modality |
| --- | --- | --- | --- | --- | --- | --- |
| PE02 — Physical Education (Rhythmic Activities) | Lecture | Faculty 05 | Monday | 07:00–09:00 | Not required | Online |
| GE06 — Reading in Philippine History | Lecture | Faculty 03 | Monday | 12:30–15:30 | Not required | Online |
| FOSNCII — Front Office Services NC II | Laboratory | Faculty 07 | Monday | 17:00–20:00 | LAB-101 | Face-to-Face |
| NSTP02 — Civic Welfare Training Service 2 | Lecture | Faculty 04 | Tuesday | 10:00–12:00 | Not required | Online |
| BME06 — Product Management | Lecture | Faculty 08 | Tuesday | 14:00–17:00 | LEC-101 | Face-to-Face |
| BME05 — Retail Management | Lecture | Faculty 05 | Wednesday | 10:30–13:30 | LEC-102 | Face-to-Face |
| GE05 — Science, Technology and Society | Lecture | Faculty 02 | Friday | 08:30–11:30 | Not required | Online |
| GE04 — Contemporary World | Lecture | Faculty 01 | Friday | 16:30–19:30 | Not required | Online |
| CSNCII — Customer Service NC II | Laboratory | Faculty 09 | Saturday | 08:30–12:30 | LAB-101 | Face-to-Face |
| BME04 — Advertising | Lecture | Faculty 06 | Saturday | 15:00–18:00 | LEC-101 | Face-to-Face |

**Information Technology — `DIT-1A`**

| Course | Component | Assigned faculty | Day | Time | Room | Modality |
| --- | --- | --- | --- | --- | --- | --- |
| PE02 — Physical Education (Rhythmic Activities) | Lecture | Faculty 05 | Monday | 09:30–11:30 | Not required | Online |
| GE05 — Science, Technology and Society | Lecture | Faculty 02 | Tuesday | 07:00–10:00 | Not required | Online |
| CC103 — Computer Programming 2 (.NET Console) | Laboratory | Faculty 10 | Tuesday | 10:30–13:30 | COMP-101 | Face-to-Face |
| GE04 — Contemporary World | Lecture | Faculty 01 | Tuesday | 17:00–20:00 | Not required | Online |
| CC102 — Computer Programming 1 (Java) | Laboratory | Faculty 04 | Wednesday | 07:30–10:30 | COMP-101 | Face-to-Face |
| NSTP02 — Civic Welfare Training Service 2 | Lecture | Faculty 04 | Wednesday | 10:30–13:30 | Not required | Online |
| PHY101 — Calculus-Based Physics | Laboratory | Faculty 09 | Saturday | 12:30–15:30 | LAB-101 | Face-to-Face |
| GE06 — Reading in Philippine History | Lecture | Faculty 03 | Saturday | 17:00–20:00 | Not required | Online |

**Tourism and Hospitality Management — `DTHM-1A`**

| Course | Component | Assigned faculty | Day | Time | Room | Modality |
| --- | --- | --- | --- | --- | --- | --- |
| NSTP02 — Civic Welfare Training Service 2 | Lecture | Faculty 04 | Monday | 07:00–10:00 | Not required | Online |
| THC04 — Professional Development and Applied Ethics | Lecture | Faculty 12 | Monday | 11:00–14:00 | LEC-102 | Face-to-Face |
| PE02 — Physical Education (Rhythmic Activities) | Lecture | Faculty 05 | Monday | 14:00–16:00 | Not required | Online |
| GE06 — Reading in Philippine History | Lecture | Faculty 03 | Monday | 16:00–19:00 | Not required | Online |
| THC03 — Quality Service Management in Tourism and Hospitality | Laboratory | Faculty 11 | Tuesday | 07:00–11:00 | LAB-101 | Face-to-Face |
| GE05 — Science, Technology and Society | Lecture | Faculty 02 | Tuesday | 11:00–14:00 | Not required | Online |
| GE04 — Contemporary World | Lecture | Faculty 01 | Tuesday | 14:00–17:00 | Not required | Online |
| THC05 — Micro Perspective of Tourism and Hospitality | Lecture | Faculty 11 | Tuesday | 17:00–20:00 | LEC-101 | Face-to-Face |
| HSKPNCII — Housekeeping NC II | Laboratory | Faculty 10 | Wednesday | 07:00–11:00 | LAB-101 | Face-to-Face |
| HPC07 — Front Office Services NC II | Laboratory | Faculty 12 | Saturday | 16:00–20:00 | LAB-101 | Face-to-Face |

The Registrar projection contained all 54 published meetings. The 12 Faculty projections collectively contained those same 54 assignments. Representative Student projections contained 10 meetings for `DTBM-1A`, 8 for `DIT-1A`, and 10 for `DTHM-1A`. These cross-role counts verify that the candidate was not only mathematically valid but also consumable by the existing application views.

The 156 slots are derived from the configured six-day grid: Monday through Saturday provides 6 days; each day spans 07:00–20:00, or 13 hours; and two 30-minute grid units fit in each hour. Therefore $6\times13\times2=156$ permitted half-hour start-grid units. “Slot” describes the time grid, not a room, class, or independently split meeting.

#### Benchmark design and instance composition

Scheduling research commonly identifies every test instance by the number and type of entities it contains. The International Timetabling Competition 2019 publishes course, class, room, student, and constraint counts for each instance and accepts only schedules satisfying every hard constraint. PyJobShop reports the minimum, average, and maximum task and resource counts across its benchmark collections, then compares objective quality, optimality gap, and runtime under fixed computational controls. Han and Wang's university-course-scheduling experiment lists the combined courses, independent courses, teachers, and total courses for each small and large instance, repeats each instance ten times, and reports fitness and resource-utilization results. TALA follows that reporting pattern for its own implemented model; it does not copy those studies' datasets or compare their algorithms with CP-SAT.

TALA derives its tiers from the accepted client-aligned workload instead of treating an external institution's population as equivalent. The **reduced technical** tier verifies deterministic smaller-model construction and is not a minimum institution size. The **client-representative** tier is the unscaled baseline. **Proportional 2×** and **proportional 4×** create two and four identifier-remapped copies of the baseline demand and resource structure. **Contention 2×** doubles the demand structure while retaining the baseline faculty and rooms to isolate resource pressure. All tiers retain the same weekly time grid.

| Tier | Subjects | Offerings | Course-specific delivery groups | Logical cohorts | Demands | Faculty | Rooms | Experimental purpose |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| Reduced technical | 18 | 27 | 27 | — | 27 | 6 | 3 | Verify the harness with a coherent smaller model; the corrected replacement benchmark did not report a final Cloud result or logical-cohort count for this tier. |
| Client-representative | 40 | 54 | 54 | 6 | 54 | 12 | 6 | Validate the current client-scale workload and compare production resource profiles. |
| Proportional 2× | 80 | 108 | 108 | 12 | 108 | 24 | 12 | Evaluate proportional growth in work and resources. |
| Contention 2× | 80 | 108 | 108 | 12 | 108 | 12 | 6 | Diagnose demand growth without proportional faculty and room growth. |
| Proportional 4× | 160 | 216 | 216 | 24 | 216 | 48 | 24 | Explore an upper computational boundary; no supported maximum is claimed. |

The client has six logical attendance cohorts, while the baseline contains 54 course-specific sections and delivery groups because each cohort-course requirement receives its own offering, section, delivery-group record, and demand. Forty distinct subjects produce 54 offerings because some subjects occur in more than one cohort. The proportional tier labels describe copied scheduling structures, not doubled or quadrupled student populations.

CP-SAT expands those business inputs into a larger mathematical model. A **candidate assignment** is one permitted demand/faculty/room/time combination. **Model variables** include the candidate-selection variables and auxiliary variables used to express the constraints and objective; therefore, variable count is much larger than demand count.

| Experimental tier | Candidate assignments | CP-SAT model variables | CP-SAT model constraints | Interpretation |
| --- | ---: | ---: | ---: | --- |
| Reduced technical | — | — | — | Harness-only tier; corrected model-scale counts were not part of the replacement Cloud evidence. |
| Client-representative | 10,356 | 31,488 | 62,832 | Current client-aligned model used to select the production resource profile. |
| Proportional 2× | 35,712 | 108,120 | 215,808 | Largest tier accepted on every approved repeated Profile C run under the disclosed 120-second controls. |
| Contention 2× | 20,712 | 62,610 | 125,112 | Diagnostic model with doubled work but shared faculty and rooms. |
| Proportional 4× | 131,424 | 396,816 | 792,192 | Largest attempted model and observed 8-GiB resource boundary; not a supported maximum. |

The benchmark establishes neither a universal minimum nor a maximum institution size. Its smallest workload is a technical harness tier, its current production baseline is profile B on the 54-demand client-representative workload, its largest repeatably accepted workload is proportional 2×, and proportional 4× is the largest attempted tier and an observed resource boundary. These terms describe tested scheduling models and Cloud configurations rather than school populations.

#### Data persistence and reproducibility

The experiment did not preserve temporary database rows or published schedules. They were created only in the guarded `test_tala_db` environment and were rolled back or removed after evidence capture. This prevents synthetic benchmark data from polluting operational records.

The reproducibility mechanism was **not** deleted. The versioned project retains the deterministic client-aligned baseline definitions, the snapshot-capture logic, the tier-construction factory, the benchmark runner, and automated tests for the disclosed counts and transformations. A future authorized rerun can recreate the test baseline, capture the same logical 54-demand snapshot, generate the selected tiers in memory, submit sequential requests to a pinned solver image and profile, save a sanitized report, and then roll back the database again. Exact result distributions must be measured again if the solver image, resource profile, search limit, constraints, or institutional inputs change.

### 11.2 Experimental controls and measures

All Cloud Run profiles used the same immutable solver image, OR-Tools 9.15.6755, `tal94-demand-v2`, `balanced_v1`, random seed `20260718`, concurrency one, minimum instances zero, maximum instances three, and a 300-second HTTP timeout. The representative tier was executed ten times on each profile. Larger tiers used bounded 30-, 120-, and 240-second solver windows. Python verification ran in Cloud Build; Laravel performed environment guards, snapshot capture, authenticated dispatch, typed-response validation, independent assignment validation, publication, and rollback checks.

The **random seed** is an integer that initializes CP-SAT's randomized search choices; `20260718` is a reproducibility control derived from the experiment date, 18 July 2026. Using the same seed and configuration reduces uncontrolled variation but does not guarantee identical time-bounded multi-worker results. A **worker** is one CP-SAT search thread inside a request. **Concurrency one** means one HTTP request at a time per Cloud Run instance. **vCPU** is allocated virtual processor capacity, **GiB** is gibibytes of memory, and the **solver window** is the time allowed for CP-SAT search inside the longer 300-second HTTP request limit.

| Profile | vCPU | Memory | Workers | Experimental role |
| --- | ---: | ---: | ---: | --- |
| A | 1 | 2 GiB | 1 | Smallest client-scale comparison |
| B | 2 | 4 GiB | 2 | Client-production selection candidate and 2× comparison |
| C | 4 | 8 GiB | 4 | Upper research profile for 2× and 4× boundaries |

For incumbent objective $Z_i$, CP-SAT bound $B_i$, and same-tier best observed objective $Z^*$,

$$
\operatorname{gap}_i = \frac{|Z_i-B_i|}{\max(1,|Z_i|)},
$$

**Interpretation.** $Z_i$ is run $i$'s incumbent objective and $B_i$ is CP-SAT's best bound for that run. The absolute difference is divided by at least one so the measure is normalized and division by zero is avoided. A smaller gap means stronger evidence that the returned feasible timetable is near the best value CP-SAT could still prove; it is not an accuracy percentage.

$$
\operatorname{RPD}_i = \frac{|Z^*-Z_i|}{\max(1,|Z^*|)}\times 100.
$$

**Interpretation.** $Z^*$ is the best objective observed among comparable runs in the same tier and $Z_i$ is one run's objective. Relative percentage deviation (RPD) expresses their percentage difference. It describes repeat-run dispersion only; it does not compare the timetable with a hidden “correct answer.”

A run is accepted only when its status is `optimal` or `feasible`, all demands are assigned, solver and Laravel hard-violation counts are zero, typed telemetry is complete, and no authentication, transport, or container failure occurs. The relative gap and same-tier RPD describe optimization evidence; neither is an accuracy score. Complete coverage and zero independently validated hard violations establish correctness, while objective, bound, gap, RPD, and runtime describe solution quality.

#### Applicability of predictive-accuracy terminology

Accuracy normally measures how often a predictive model's outputs match known correct labels. TALA is not a classifier, forecaster, or machine-learning model, and a scheduling instance generally has many valid timetables rather than one hidden answer key. A single predictive-accuracy percentage would therefore be technically misleading. TALA separates correctness, optimization quality, repeatability, speed, and infrastructure capacity:

| Evaluation question | Correct TALA measure | Interpretation |
| --- | --- | --- |
| Was every required meeting scheduled? | Demand coverage | `assigned demands / total demands`; all accepted representative and proportional 2× runs achieved 100% coverage. |
| Is the timetable valid? | Solver hard-violation count, Laravel independent hard-constraint validation, and acceptance result | Zero hard violations and a Laravel pass establish schedule correctness under the implemented rules. |
| Did the solver prove the best possible ranking? | Solver status | `optimal` means the best objective was proved; `feasible` means a valid result was found without completing that proof. |
| How strong is a time-limited feasible result? | Relative optimality gap | Smaller is stronger; zero together with `optimal` proves optimality. It is not an accuracy percentage. |
| How consistent are repeated runs? | Acceptance rate and same-tier RPD | Acceptance rate reports successful runs such as 10/10 or 3/3; RPD reports objective dispersion from the same-tier best observed result. |
| How quickly does it solve? | Median and p95 runtime | Reports typical and slower-tail response time under the stated controls. |
| Can the deployment sustain the workload? | CPU, memory, headroom, OOM/503, and repeatably accepted tier | Separates solver/model outcomes from Cloud Run resource failure. |

Consequently, the evaluation reports the measures in the table rather than a single predictive-accuracy score.

For the result tables, **accepted runs** count requests meeting all those conditions; **objective range** reports the smallest and largest ranking score; **median** is the middle observation after sorting; **p95** is the 95th-percentile tail indicator; and **p99** is the 99th-percentile monitoring indicator. **CPU** and **memory utilization** are the used shares of allocated resources. **Headroom** is the unused share available for variation. **Model scale** reports generated candidates, solver variables, and constraints. A dash means the measure does not apply or no accepted incumbent existed.

### 11.3 Stage 1: client-production profile selection

Profiles B and C accepted all ten client-representative comparison runs. Profile A accepted four; six requests reached a compute boundary without returning an incumbent. Every accepted run assigned 54 of 54 demands and passed zero solver and Laravel hard violations.

| Measure | Profile A | Profile B | Profile C |
| --- | ---: | ---: | ---: |
| Accepted runs | 4/10 | **10/10** | **10/10** |
| Accepted objective range | 393,460 | 388,620–406,570 | 385,300–404,140 |
| Median relative gap | 14.8274% | 14.1544% | **13.3831%** |
| p95 relative gap | 14.8274% | **15.8039%** | 17.3505% |
| Median runtime | 31.182 s | **31.152 s** | 31.439 s |
| p95 runtime | 31.471 s | **31.380 s** | 31.849 s |

Profile B was selected through five ordered gates:

1. **Validity:** a profile first had to assign 54/54 demands with zero hard violations in every repetition. Profile A failed this gate in six of ten requests.
2. **Solution quality:** B and C both passed validity. C had a slightly lower median gap, while B had the stronger p95 gap; the complete distribution therefore did not justify C solely on median quality.
3. **Runtime:** B had the fastest median and p95 representative runtime, although the differences were small.
4. **Resource proportionality:** C doubled B's CPU and memory but did not provide a consistently stronger client-representative distribution.
5. **Cost:** cost was considered only after correctness and quality. B was therefore the justified middle profile for the actual 54-demand client workload.

### 11.4 Stages 2 and 3: proportional growth and stress-boundary evidence

Stages 2 and 3 evaluate proportional growth and resource pressure after profile B's client-scale selection; they do not repeat the production-profile comparison.

At the 30-second window, profiles B and C returned `unknown` without an incumbent for proportional 2×, with complete model telemetry and no infrastructure failure. The next approved window therefore increased search time rather than changing the mathematical model.

Solver-status definitions follow Section 7. Capacity evidence accepts only `optimal` or `feasible` results that also pass Laravel validation. `Unknown` is inconclusive, diagnostic `infeasible` applies only to the exact stress input, OOM/HTTP 503 is an infrastructure failure, and an untested profile-tier combination supports no conclusion.

The staged execution matrix records both tested and untested profile-tier combinations:

| Workload tier | Profile A: 1 vCPU / 2 GiB / 1 worker | Profile B: 2 vCPU / 4 GiB / 2 workers | Profile C: 4 vCPU / 8 GiB / 4 workers |
| --- | --- | --- | --- |
| Reduced technical | No final Cloud profile result reported | No final Cloud profile result reported | No final Cloud profile result reported |
| Client-representative, 30-second search | 4/10 accepted; six compute-boundary results | 10/10 accepted | 10/10 accepted |
| Proportional 2×, 30-second screen | Not tested | One `unknown` screening run | One `unknown` screening run |
| Proportional 2×, 120-second confirmation | Not tested | 2/3 `feasible`; one 4-GiB OOM/503 | 3/3 `feasible` and accepted |
| Contention 2×, 120 seconds | Not tested | Not tested | One diagnostic `infeasible` run |
| Proportional 4×, 120-second screen | Not tested | Not tested | One `unknown` screening run |
| Proportional 4×, 240-second confirmation | Not tested | Not tested | Three attempts: all three OOM/503 |

`3/3` denotes three successful requests, not three internal solver trials. The reduced tier supports harness verification but no Cloud-capacity claim. Profile A was not advanced after it failed six client-scale repetitions. Profiles B and C were compared at proportional 2×, while proportional 4× was tested only on profile C to locate an upper resource boundary. The single contention result is diagnostic rather than repeatable-capacity evidence. The benchmark is therefore bounded rather than a full-factorial comparison, and every untested cell remains outside the stated conclusions.

| Tier and profile | Accepted | Result | Gap range | Runtime | Model scale |
| --- | ---: | --- | ---: | ---: | --- |
| Proportional 2×, B, 120 s | 2/3 | two `feasible`; one OOM/503 | 12.5155%–12.5212% | 124.745–124.965 s for accepted runs | 35,712 candidates; 108,120 variables; 215,808 constraints |
| Proportional 2×, C, 120 s | 3/3 | `feasible` | 6.8713%–9.7205% | median 125.651 s | same model scale |
| Contention 2×, C, 120 s | 0/1 | diagnostic `infeasible` | — | 63.227 s | 20,712 candidates; 62,610 variables; 125,112 constraints |
| Proportional 4×, C, 120 s | 0/1 | `unknown`; compute boundary | — | 146.428 s | 131,424 candidates; 396,816 variables; 792,192 constraints |
| Proportional 4×, C, 240 s | 0/3 | three OOM/503 infrastructure failures | — | instances terminated after 170–225 s | same 4× model scale |

Proportional 2× on Profile C is the largest **repeatably accepted** tier: every approved Profile C repeat returned a validated 108-demand candidate with complete evidence under the stated controls. Profile B accepted two of three but one request exceeded 4 GiB. Proportional 4× is the largest attempted tier and an **observed boundary**: all three 240-second attempts consumed approximately the full 8 GiB allocation, terminated the instance, and produced HTTP 503. The contention result applies only to its disclosed synthetic transformation and does not imply that every real 108-demand workload is infeasible.

### 11.5 Production acceptance, cost, and applicability

Profile B revision `tala-scheduler-solver-b4f-ad9177e472f8` was promoted to 100% canonical traffic with private IAM and concurrency one. Two post-promotion authenticated solves each assigned 54 of 54 demands with zero hard violations. Laravel independently validated the responses, ingested 54 candidate rows, exercised Registrar publication of 54 official meetings, and rendered the Registrar, Faculty, and representative Student schedules inside a rolled-back database transaction. No schedule run, candidate row, official meeting, queued job, or failed job survived, and the scheduling queue was resumed.

Using observed elapsed time as a billable-time proxy and the dated Singapore request-based list rates of US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, and US$0.40 per million requests, the corrected retained experiment's 59 requests are estimated at US$0.196810 before free-tier credits. This is a bounded experiment estimate rather than a monthly forecast or invoice; it excludes billing-rounding differences, networking, registry and build charges, taxes, discounts, invalid deployment attempts, and unrelated project usage.

The empirical findings do not alter any equation in Sections 5 and 6. They establish that the corrected shared-cohort formulation and Laravel validation pipeline are operationally accepted at the disclosed client scale; identify Profile B as the production baseline; establish proportional 2× on Profile C at 120 seconds as repeatable larger-workload evidence; and disclose proportional 4× at 8 GiB as an observed limit requiring future model optimization or a separately approved resource study before any operational promise.

## 12. Equation-to-implementation traceability

This section provides implementation traceability for the mathematical and operational claims. Each row identifies the applicable product or architecture authority, the component responsible for implementation, and the focused verification evidence. The formulation, experimental findings, and limitations are stated independently in the preceding sections and do not require access to the referenced repository files for interpretation.

| Formulation or pipeline claim | Product/architecture authority | Current implementation evidence | Focused test evidence |
| --- | --- | --- | --- |
| Scheduling Demand is the canonical unit; candidate before official schedule | [`prd_modules/06_cpsat_scheduling.md`](../prd_modules/06_cpsat_scheduling.md), [`architecture_specification.md`](../architecture_specification.md) | `GenerateSchedulingDemand`, `ScheduleSolverSnapshotService`, `CandidateScheduleRow`, `SchedulePublishService` | Scheduling generation and publication feature tests |
| `tal94-demand-v2` differs from `balanced_v1` v1 | PRD product-level solver contract and code-defined-profile rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `CONTRACT_VERSION`, profile checks | [`test_solver.py`](../../cloud/scheduler-solver/tests/test_solver.py): unsupported contract and tampered profile cases |
| H1 — exact coverage $\sum_{c\in C_d}x_c=1$ | PRD assignment coverage | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): Boolean variables and equality per demand | `test_accepts_v2_demands...`, conflicting fixed-demand test |
| H2a-H4 — duration/grid, fixed values, and candidate admissibility | PRD fixed assignment, calendar, qualification, and consecutive-block rules | `ScheduleSolverSnapshotService`; [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_faculty_ids`, `_room_ids`, `_slots_for_demand`, availability/commitment/calendar filters | Fixed assignment and recurring calendar-block solver tests; snapshot feature tests |
| H5a-H5c — room capacity, type, and features | PRD room suitability and capacity rules | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_room_suits_demand`; Laravel independent validator | Required-features and no-suitable-room solver tests; assignment-validation feature tests |
| H6-H8 — faculty, room, and logical-cohort `NoOverlap` | PRD hard constraint source map | `ScheduleSolverSnapshotService` shared-cohort mapping; [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_no_overlap_constraints`; Laravel validation/revalidation services | Cross-delivery-group shared-cohort solver and Laravel tests; model-growth test; assignment-validation and live-revalidation feature tests |
| H9 — configured same-faculty equality | PRD linked-component rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_same_faculty_constraints`; Laravel validator | Linked-component and validation cases |
| H10a-H10d — deduplicated load and maximum $L_f\le M_f$ | PRD faculty load rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_faculty_load_constraints`; Laravel validator | Faculty-load and linked-component load tests |
| S2 — faculty/day internal idle-gap term $I=-\sum G_{f,\delta}$ | PRD faculty idle-gap preference; approved `balanced_v1` profile | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_idle_gap_objective_terms`, `_objective_details`; Laravel objective reconciliation | `test_faculty_idle_gap_counts_only_time_between_adjacent_meetings`; objective-details validation tests |
| S1-S4 and O1 — four-term fixed objective and reconciliation | PRD soft-preference rules; approved `balanced_v1` profile | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): objective builders and `_objective_details`; Laravel assignment validator | Objective-details and response-validation tests |
| Typed experimental statistics and fixed search configuration | Controlled benchmark specification | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_solver_statistics`; Laravel strict response validation and allowlisted diagnostics persistence | Typed-statistics, rejection, and guarded real-service acceptance tests |
| Solver statuses are distinct from queue, transport, and container-runtime failure | Architecture queue and external solver boundary | `ScheduleSolverDispatchJob`, `ScheduleSolverDispatchLifecycleService`, `ScheduleCloudResultIngestor`, [`server.py`](../../cloud/scheduler-solver/tala_solver/server.py) | Server and queue-operations tests |
| Immutable input, after-commit dispatch, independent ingestion | Architecture transaction and source-of-truth boundary | `ScheduleGenerationService`, `ScheduleSolverSnapshotService`, `ScheduleSolverDispatchJob`, `ScheduleCloudResultIngestor` | Dispatch, ingestion, and validation feature tests |
| Registrar correction, revalidation, and publication authority | PRD manual override/publication rules; UI blueprint | `CandidateScheduleRowReviewService`, `ScheduleAssignmentRevalidationService`, `ScheduleGenerationRunPolicy`, `SchedulePublishService` | Candidate-review, assignment-validation, and publication tests |
| Worked example and 18,900 objective | Existing deterministic fixture | [`minimal_snapshot.json`](../../cloud/scheduler-solver/samples/minimal_snapshot.json), [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py) | All Python solver tests; direct fixture execution with pinned requirements |

## 13. References

### Internal authorities, implementation, and presentation references

1. TALA. [CP-SAT Scheduling Subsystem PRD](../prd_modules/06_cpsat_scheduling.md).
2. TALA. [Architecture Specification](../architecture_specification.md).
3. TALA. [UI Surface Blueprint](../ui_surface_blueprint.md).
4. TALA. [Python CP-SAT solver](../../cloud/scheduler-solver/tala_solver/solver.py) and [deterministic sample snapshot](../../cloud/scheduler-solver/samples/minimal_snapshot.json).
5. PyJobShop article copy in the repository, used only as a presentation reference for organizing constraint-programming equations and reporting instance composition, optimality gap, RPD, and controlled runtime: [Solving scheduling problems with constraint programming in Python](how%20the%20eqautions%20should%20look/PyJobShop-Solving%20scheduling%20problems%20with%20constraint%20programming%20in%20Python.md). Its job-shop datasets and solver-comparison results are not TALA evidence.
6. Han, X.; Wang, D. (2025). *Gradual Optimization of University Course Scheduling Problem Using Genetic Algorithm and Dynamic Programming*. *Algorithms*, 18(3), 158. [Repository copy](how%20the%20eqautions%20should%20look/Gradual%20Optimization%20of%20University%20Course%20Scheduling%20Problem%20Using%20Genetic%20Algorithm%20and%20Dynamic); [DOI](https://doi.org/10.3390/a18030158). Used only as a presentation reference for individually explained hard and soft constraints, disclosed instance composition, repeated runs, and separated quality/resource measures. Its GA/DP model, fitness functions, datasets, comparative results, and claims are not part of TALA's CP-SAT formulation or evidence.

### Official external sources

7. Google for Developers. [CP-SAT Solver](https://developers.google.com/optimization/cp/cp_solver). Official integer-model and solver-status semantics.
8. Google OR-Tools. [Python `CpModel` API](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html). Official Boolean-variable, optional fixed-size interval, `NoOverlap`, absolute-equality, and maximization APIs.
9. Google for Developers. [The Job Shop Problem](https://developers.google.com/optimization/scheduling/job_shop). Official interval and disjunctive-resource scheduling context. TALA applies the same `NoOverlap` principle to fixed-time optional candidate intervals rather than using variable-start tasks from the example.
10. Laravel. [Database Transactions](https://laravel.com/docs/12.x/database#database-transactions). Transaction commit, rollback, and deadlock-retry semantics.
11. Laravel. [Queues and Jobs](https://laravel.com/docs/12.x/queues). After-commit dispatch and the timeout/`retry_after` relationship.
12. Laravel. [Authorization](https://laravel.com/docs/12.x/authorization). Gate and policy semantics used to enforce action authority.
13. Müller, T.; Rudová, H.; Müllerová, Z. (2024). [Real-world university course timetabling at the International Timetabling Competition 2019](https://link.springer.com/article/10.1007/s10951-023-00801-w). *Journal of Scheduling*, 27, 1–24. External problem-scale context; its class and student counts are not treated as equivalent TALA demands.
14. UniTime. [University Course Timetabling Benchmark Datasets](https://www.unitime.org/uct_datasets.php) and [Data Format v2.4](https://www.unitime.org/uct_dataformat_v24.php). External instance-composition context for rooms, instructors, classes, students, and group constraints.
15. Lan, L.; Berkhout, J.; De Causmaecker, P.; Vansteenwegen, P. (2025). [PyJobShop: Solving scheduling problems with constraint programming in Python](https://arxiv.org/abs/2502.13483). Constraint-programming modeling and reproducible scheduling-software context; its job-shop models are not TALA's university-timetabling model.
16. Google Cloud. [Cloud Run pricing](https://cloud.google.com/run/pricing). Dated request-based CPU, memory, request, free-tier, and billable-time basis.
17. Google Cloud. [Configure CPU limits for services](https://docs.cloud.google.com/run/docs/configuring/services/cpu). CPU, memory, threading, and concurrency sizing considerations.
18. Google Cloud. [Cloud Run monitoring](https://docs.cloud.google.com/run/docs/monitoring). Revision-scoped CPU and memory evidence.

---

**Version applicability.** This formulation applies to the implemented and verified `tal94-demand-v2` contract, `balanced_v1` version-1 profile, and dated Cloud Run experiment as of 18 July 2026. Runtime-resource changes and semantics-preserving implementation optimizations do not by themselves change the equations. Any approved change to the data contract, optimization profile, hard-constraint semantics, objective semantics, or material workload requires corresponding formulation or empirical-evidence revision.
