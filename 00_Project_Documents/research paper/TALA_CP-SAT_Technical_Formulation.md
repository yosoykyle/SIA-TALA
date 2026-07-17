# TALA CP-SAT Technical Formulation and Laravel Validation Pipeline

**Document type:** Standalone technical specification

**System scope:** CP-SAT candidate timetable generation with Laravel-controlled validation, human review, and publication

**Technical baseline date:** 17 July 2026

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
11. [Equation-to-implementation traceability](#11-equation-to-implementation-traceability)
12. [References](#12-references)

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

The following abbreviated input illustrates how the data-contract version and optimization profile are transmitted with a Scheduling Demand. The complete immutable snapshot additionally contains term settings, time slots, faculty and room records, qualifications, availability, commitments, calendar blocks, source identifiers, and run metadata.

```json
{
  "contract_version": "tal94-demand-v2",
  "scheduling_demands": [
    {
      "scheduling_demand_id": 5001,
      "required_duration_minutes": 60,
      "eligible_faculty_user_ids": [200],
      "room_required": true
    },
    {
      "scheduling_demand_id": 5002,
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

The solver response repeats the model identity and provides its native status, candidate assignments, diagnostics, and reconciled objective details:

```json
{
  "model_version": "tal94-demand-v2",
  "solver_status": "optimal",
  "assignments": [
    {
      "scheduling_demand_id": 5001,
      "faculty_id": 200,
      "room_id": 301,
      "day_of_week": 1,
      "starts_at": "09:00:00",
      "ends_at": "10:00:00"
    },
    {
      "scheduling_demand_id": 5002,
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
  }
}
```

The returned model identity binds the result to the submitted contract. The profile identity and objective total provide an auditable connection between the selected assignments and the fixed ranking policy. Laravel accepts the response only after validating these values and independently rechecking the assignment set. The complete representation is defined in Section 4, while Section 11 maps the formulation to its implementation and tests.

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

A Scheduling Demand represents one required course component for one term offering and one section delivery group. Lecture and laboratory components can therefore become separate but linked demands when their duration, room, modality, or faculty requirements differ.

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

Laravel sends the immutable requirements; Python enumerates only combinations that satisfy deterministic single-candidate rules. This means room suitability, fixed values, faculty availability, recurring blocks, and the time grid narrow the candidate set before Boolean selection begins. Pairwise and aggregate relationships—such as overlaps, linked-component faculty, and faculty load—are then enforced inside CP-SAT.

## 5. Mathematical formulation

### 5.1 Sets and indices

Let:

- $D$ be the set of ready Scheduling Demands;
- $F$ be the set of faculty members present in the snapshot;
- $R$ be the set of active physical rooms;
- $O$ be the set of term offerings;
- $G$ be the set of section delivery groups;
- $C_d$ be the set of admissible candidate assignments generated for demand $d \in D$; and
- $C = \bigcup_{d \in D} C_d$ be the complete candidate set.

A candidate $c \in C$ is the tuple

$$
c = \bigl(d(c), f(c), r(c), g(c), o(c), \delta_c, s_c, e_c\bigr),
$$

where $d(c)$ is its demand, $f(c)$ its faculty member, $r(c)$ its room or $\varnothing$, $g(c)$ its delivery group, $o(c)$ its term offering, $\delta_c$ its day, and $s_c$ and $e_c$ its start and end minute.

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

The implementation creates each $x_c$ with OR-Tools [`new_bool_var`](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html), whose domain is $\{0,1\}$.

For faculty load, define $y_{f,o,g} \in \{0,1\}$ to indicate whether faculty member $f$ is selected for at least one component in offering $o$ and delivery group $g$. Let $L_f$ be the resulting scaled load. For objective linearization, $b_{cc'}$ indicates that a same-faculty, same-day candidate pair is jointly selected, and $\Delta_{ff'}$ represents an absolute load difference.

### 5.4 Exact demand coverage

Every ready demand must be assigned exactly once:

$$
\sum_{c \in C_d} x_c = 1
\qquad \forall d \in D.
$$

If candidate filtering leaves $C_d = \varnothing$, the service returns an `infeasible` result with a source-oriented reason instead of constructing a misleading partial schedule. This equation is the core completeness rule: the optimizer cannot silently omit a ready demand or assign it twice.

### 5.5 Duration, time grid, and consecutive block

Each candidate represents one uninterrupted meeting:

$$
e_c = s_c + p_{d(c)}.
$$

A candidate is admitted only when its start is an allowed time-grid point and its complete duration fits within the configured end of the institutional day:

$$
s_c \in \mathcal{S}_{\delta_c},
\qquad
e_c \leq E_{\delta_c}.
$$

Because the current contract requires `meeting_count = 1`, a six-hour laboratory demand is modeled as one six-hour candidate interval, not twelve independently selectable half-hour decisions. All overlap rules apply to that complete interval. Thus the implementation satisfies the MVP's single-day consecutive-block requirement without introducing interval variables for each sub-slot.

### 5.6 Fixed assignments and admissibility

Let $\bar f_d$, $\bar r_d$, $\bar\delta_d$, and $\bar s_d$ denote optional fixed values. Candidate construction enforces

$$
f(c)=\bar f_d,\quad
r(c)=\bar r_d,\quad
\delta_c=\bar\delta_d,\quad
s_c=\bar s_d
$$

whenever the corresponding value is fixed. A conflicting fixed value therefore produces no admissible candidate rather than being treated as a soft preference.

The same candidate-set rule excludes a candidate when its faculty member is not eligible, its time lies outside the faculty's captured availability, or its interval intersects a matching existing commitment or recurring calendar block:

$$
C_d = \{c : \operatorname{eligible}(c) \land
\neg\operatorname{blocked}(c,\mathcal{B}) \land
\operatorname{fitsGrid}(c) \land
\operatorname{roomSuitable}(c)\}.
$$

### 5.7 Room suitability, features, and capacity

For a physical-room demand, candidate $c$ is admissible only when

$$
\kappa_{r(c)} \geq q_{d(c)},
$$

$$
T_{d(c)} = \varnothing
\quad\lor\quad
T_{d(c)} = T_{r(c)},
$$

and

$$
A_{d(c)} \subseteq A_{r(c)}.
$$

The expected regular cohort must also fit its section capacity, but Laravel enforces that readiness condition before snapshot capture. The solver's room predicate then enforces the second physical-capacity boundary. A no-room modality uses $r(c)=\varnothing$ and does not consume a physical room.

### 5.8 Faculty, room, and delivery-group non-overlap

For every overlapping pair $c,c' \in C$ representing different assignment choices, the implementation adds

$$
x_c + x_{c'} \leq 1
$$

whenever at least one shared-resource condition is true:

$$
f(c)=f(c'),
\quad
r(c)=r(c')\neq\varnothing,
\quad\text{or}\quad
g(c)=g(c').
$$

The same inequality therefore protects a faculty member, a physical room, and a regular section/cohort delivery group from simultaneous meetings.

### 5.9 Same-faculty rule for linked components

Let $D_{o,g}^{\mathrm{same}}$ be linked demands for offering $o$ and delivery group $g$ whose source rule requires one faculty member. For every pair $d,d' \in D_{o,g}^{\mathrm{same}}$ and every eligible faculty member $f$,

$$
\sum_{\substack{c \in C_d\\ f(c)=f}} x_c
=
\sum_{\substack{c \in C_{d'}\\ f(c)=f}} x_c.
$$

Combined with exact demand coverage, these equalities force all configured linked components to select the same faculty member. When the source rule is false, lecture and laboratory demands may select different qualified faculty.

### 5.10 Faculty-load accounting

The implementation counts the load of one offering/delivery-group combination once even when linked components produce multiple demand rows. For every candidate in a faculty/offering/group bucket,

$$
x_c \leq y_{f,o,g},
$$

and

$$
y_{f,o,g}
\leq
\sum_{\substack{c \in C:\\ f(c)=f,\,o(c)=o,\,g(c)=g}} x_c.
$$

Together, these constraints make $y_{f,o,g}=1$ exactly when at least one candidate in that bucket is selected. If $U_{f,o,g}$ is the maximum scaled unit value in the bucket, then

$$
L_f = \sum_{(o,g)} U_{f,o,g}y_{f,o,g},
$$

subject to

$$
L_f \leq M_f
\qquad \forall f \in F.
$$

This prevents lecture/laboratory components belonging to one enrollment line from double-counting the same offering load while still enforcing the configured default or approved faculty load limit.

## 6. Implemented objective function

Hard constraints first define the valid region. The solver then maximizes a transparent weighted score over only those valid schedules:

$$
\max Z =
w_E E + w_I I + w_B B + w_R R.
$$

For `balanced_v1`, version 1,

$$
w_E=w_I=w_B=w_R=1.
$$

The profile is code-defined and rejected if any key, hard-constraint order, version, or weight is changed.

### 6.1 Earlier institutional time blocks

With day index $\delta_c$ and start minute $s_c$, the selected-candidate score is

$$
a_c = \max\bigl(0, 10000-(1000\delta_c+s_c)\bigr),
$$

and

$$
E = \sum_{c \in C} a_c x_c.
$$

This favors earlier days and start times among otherwise feasible choices. Late/weekend placement is therefore managed primarily by the configured operating grid and recurring blocking records, with this term giving an additional ranking preference toward earlier institutional blocks.

### 6.2 Faculty idle-gap penalty

For each non-overlapping candidate pair for different demands assigned to the same faculty member on the same day, define the capped gap

$$
\gamma_{cc'} =
\min\left(240,
\max\left(0,
\max(s_c,s_{c'})-\min(e_c,e_{c'})
\right)\right).
$$

The auxiliary Boolean is linearized by

$$
b_{cc'} \leq x_c,\qquad
b_{cc'} \leq x_{c'},\qquad
b_{cc'} \geq x_c+x_{c'}-1.
$$

The implemented raw term is

$$
I = -\sum_{(c,c')\in P_I} \gamma_{cc'}b_{cc'}.
$$

It is a pairwise, capped penalty over jointly selected assignments. It is not a claim to compute only consecutive gaps in a faculty member's final chronological sequence.

### 6.3 Faculty-load balance penalty

For each unordered faculty pair, OR-Tools enforces

$$
\Delta_{ff'} = |L_f-L_{f'}|,
$$

using the official [`add_abs_equality`](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html) model operation. The raw balance term is

$$
B = -\sum_{\{f,f'\}\subseteq F}\Delta_{ff'}.
$$

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

and

$$
R = \sum_{c\in C} h_c x_c.
$$

Among suitable physical rooms, this prefers a smaller room over a larger room. It is intentionally a simple MVP proxy; it is not an occupancy percentage or a direct seat-slack equation.

### 6.5 Objective reconciliation

The service returns `objective_details` containing each term's raw value, fixed weight, weighted value, and total. Laravel independently verifies that

$$
Z = \sum_{k\in\{E,I,B,R\}} w_k z_k
$$

and that the returned `objective_score` matches the reconciled total. A solver label without consistent counters and objective arithmetic is not accepted.

## 7. Solver outcomes and operational failures

[OR-Tools defines five CP-SAT outcome categories](https://developers.google.com/optimization/cp/cp_solver), which the service maps to lowercase values:

| Solver status | Meaning in TALA |
| --- | --- |
| `optimal` | A valid solution was found and no better objective value exists within the solved model. |
| `feasible` | A valid solution was found, but optimality was not proven before the search stopped. |
| `infeasible` | No assignment satisfies all hard constraints, or candidate construction found a demand with no admissible choice. |
| `model_invalid` | The model/contract is invalid or unsupported, including a changed profile, unsupported contract, unsupported meeting count, or non-numeric load. |
| `unknown` | No conclusive feasible/infeasible result was obtained, including a bounded search that ends without a solution; the service also marks the timeout flag when CP-SAT returns this status. |

These outcomes differ from **operational failures**. Network errors, authentication failures, invalid HTTP payloads, service unavailability, and queue timeouts occur outside the mathematical model. Laravel classifies those transport failures, records operational evidence, retries only bounded retryable failures, and ultimately marks the run `failed` when processing cannot complete. A structurally or mathematically unacceptable returned result becomes `blocked` during ingestion. Only an independently valid usable result becomes `under_review`.

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

### 9.1 Why this is a usable MVP scheduler

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

| Demand | Offering | Delivery group | Eligible faculty | Room | Candidate starts |
| --- | ---: | ---: | ---: | --- | --- |
| 5001 (`IT101`) | 300 | 110 | 200 | R-101, capacity 40 | Monday 08:00, 08:30, 09:00, 09:30 |
| 5002 (`IT102`) | 301 | 110 | 201 | R-101, capacity 40 | Monday 08:00, 08:30, 09:00, 09:30 |

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

Idle-gap penalty:

$$
I=0,
$$

because the assignments use different faculty members.

Load-balance penalty, with both three-unit loads scaled to 300:

$$
B=-|300-300|=0.
$$

Room score:

$$
R=(1000-40)+(1000-40)=960+960=1920.
$$

With all weights equal to one,

$$
Z=16980+0+0+1920=18900.
$$

The returned `objective_score` and `objective_details.total` are both `18900`. The exact selection between the two symmetric subject-to-time assignments is not institutionally significant; exact coverage, non-overlap, and the reconciled total are the material properties.

## 11. Equation-to-implementation traceability

| Formulation or pipeline claim | Product/architecture authority | Current implementation evidence | Focused test evidence |
| --- | --- | --- | --- |
| Scheduling Demand is the canonical unit; candidate before official schedule | [`prd_modules/06_cpsat_scheduling.md`](../prd_modules/06_cpsat_scheduling.md), [`architecture_specification.md`](../architecture_specification.md) | `GenerateSchedulingDemand`, `ScheduleSolverSnapshotService`, `CandidateScheduleRow`, `SchedulePublishService` | Scheduling generation and publication feature tests |
| `tal94-demand-v2` differs from `balanced_v1` v1 | PRD product-level solver contract and code-defined-profile rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `CONTRACT_VERSION`, profile checks | [`test_solver.py`](../../cloud/scheduler-solver/tests/test_solver.py): unsupported contract and tampered profile cases |
| Exact coverage $\sum_{c\in C_d}x_c=1$ | PRD assignment coverage | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): Boolean variables and equality per demand | `test_accepts_v2_demands...`, conflicting fixed-demand test |
| Candidate admissibility, fixed values, duration/grid, recurring blocks | PRD fixed assignment, calendar, and consecutive-block rules | `ScheduleSolverSnapshotService`; [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_faculty_ids`, `_room_ids`, `_slots_for_demand`, availability/commitment/calendar filters | Fixed assignment and recurring calendar-block solver tests; snapshot feature tests |
| Room capacity, type, and features | PRD room suitability and capacity rules | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_room_suits_demand`; Laravel independent validator | Required-features and no-suitable-room solver tests; assignment-validation feature tests |
| Faculty/room/delivery-group non-overlap | PRD hard constraint source map | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_no_overlap_constraints`; Laravel validation/revalidation services | Same-group-and-room solver test; assignment-validation feature tests |
| Same-faculty equality | PRD linked-component rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_same_faculty_constraints`; Laravel validator | Linked-component and validation cases |
| Deduplicated load and maximum $L_f\le M_f$ | PRD faculty load rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_faculty_load_constraints`; Laravel validator | Faculty-load and linked-component load tests |
| Four-term fixed objective and reconciliation | PRD soft-preference rules; approved `balanced_v1` profile | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): objective builders and `_objective_details`; Laravel assignment validator | Objective-details solver test; TAL-94B1 validation tests |
| Solver statuses are distinct from queue/transport failure | Architecture queue and external solver boundary | `ScheduleSolverDispatchJob`, `ScheduleSolverDispatchLifecycleService`, `ScheduleCloudResultIngestor`, [`server.py`](../../cloud/scheduler-solver/tala_solver/server.py) | [`test_server.py`](../../cloud/scheduler-solver/tests/test_server.py); TAL-94E2a queue operations tests |
| Immutable input, after-commit dispatch, independent ingestion | Architecture transaction and source-of-truth boundary | `ScheduleGenerationService`, `ScheduleSolverSnapshotService`, `ScheduleSolverDispatchJob`, `ScheduleCloudResultIngestor` | TAL-62 dispatch and TAL-94 queue/validation tests |
| Registrar correction, revalidation, and publication authority | PRD manual override/publication rules; UI blueprint | `CandidateScheduleRowReviewService`, `ScheduleAssignmentRevalidationService`, `ScheduleGenerationRunPolicy`, `SchedulePublishService` | Candidate-review, assignment-validation, and TAL-94D1 publication tests |
| Worked example and 18,900 objective | Existing deterministic fixture | [`minimal_snapshot.json`](../../cloud/scheduler-solver/samples/minimal_snapshot.json), [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py) | All Python solver tests; direct fixture execution with pinned requirements |

## 12. References

### Internal authorities and implementation

1. TALA. [CP-SAT Scheduling Subsystem PRD](../prd_modules/06_cpsat_scheduling.md).
2. TALA. [Architecture Specification](../architecture_specification.md).
3. TALA. [UI Surface Blueprint](../ui_surface_blueprint.md).
4. TALA. [Python CP-SAT solver](../../cloud/scheduler-solver/tala_solver/solver.py) and [deterministic sample snapshot](../../cloud/scheduler-solver/samples/minimal_snapshot.json).
5. PyJobShop article copy in the repository, used only as a reference for equation presentation: [Solving scheduling problems with constraint programming in Python](how%20the%20eqautions%20should%20look/PyJobShop-Solving%20scheduling%20problems%20with%20constraint%20programming%20in%20Python.md).

### Official external sources

6. Google for Developers. [CP-SAT Solver](https://developers.google.com/optimization/cp/cp_solver). Official integer-model and solver-status semantics.
7. Google OR-Tools. [Python `CpModel` API](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html). Official Boolean-variable, exactly-one/at-most-one, absolute-equality, and maximization APIs.
8. Google for Developers. [The Job Shop Problem](https://developers.google.com/optimization/scheduling/job_shop). General official scheduling-model context; TALA's implemented baseline uses enumerated Boolean candidates rather than copying the example's interval-variable model.
9. Laravel. [Database Transactions](https://laravel.com/docs/12.x/database#database-transactions). Transaction commit, rollback, and deadlock-retry semantics.
10. Laravel. [Queues and Jobs](https://laravel.com/docs/12.x/queues). After-commit dispatch and the timeout/`retry_after` relationship.
11. Laravel. [Authorization](https://laravel.com/docs/12.x/authorization). Gate and policy semantics used to enforce action authority.

---

**Version applicability.** This formulation applies to the implemented and verified `tal94-demand-v2` contract and `balanced_v1` version-1 profile as of 17 July 2026. Any approved change to the contract, optimization profile, or solver model requires a corresponding revision of the formulation and traceability evidence.
