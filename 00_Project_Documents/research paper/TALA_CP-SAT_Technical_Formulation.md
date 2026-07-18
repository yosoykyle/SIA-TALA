# TALA Scheduling Optimization: CP-SAT Formulation, Laravel Validation, and Empirical Capacity

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
11. [Experimental evaluation and operating envelope](#11-experimental-evaluation-and-operating-envelope)
12. [Equation-to-implementation traceability](#12-equation-to-implementation-traceability)
13. [References](#13-references)

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

Laravel sends the immutable requirements; Python enumerates only combinations that satisfy deterministic single-candidate rules. This means room suitability, fixed values, faculty availability, recurring blocks, and the time grid narrow the candidate set before Boolean selection begins. CP-SAT then represents every candidate as a Boolean selection decision and a corresponding optional fixed-size interval. Resource/day `NoOverlap` constraints enforce faculty, room, and delivery-group exclusivity over the selected intervals, while aggregate constraints enforce linked-component faculty and faculty load.

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
| F4 | `section_delivery_group_no_overlap` | H8 | `NoOverlap` over selected delivery-group/day intervals | Section/delivery-group conflict validation |
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

The implementation creates this interval with OR-Tools [`new_optional_fixed_size_interval_var`](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html). An unselected candidate remains absent from interval constraints.

For faculty load, define $y_{f,o,g} \in \{0,1\}$ to indicate whether faculty member $f$ is selected for at least one component in offering $o$ and delivery group $g$. Let $L_f$ be the resulting scaled load. For the objective, $\Delta_{ff'}$ represents an absolute load difference, while the faculty/day compactness variables are defined in Section 6.2.

### 5.4 Exact demand coverage

Every ready demand must be assigned exactly once:

**H1 — Exact demand coverage**

$$
\sum_{c \in C_d} x_c = 1
\qquad \forall d \in D.
$$

If candidate filtering leaves $C_d = \varnothing$, the service returns an `infeasible` result with a source-oriented reason instead of constructing a misleading partial schedule. This equation is the core completeness rule: the optimizer cannot silently omit a ready demand or assign it twice.

### 5.5 Duration, time grid, and consecutive block

Each candidate represents one uninterrupted meeting:

**H2a — Required contiguous duration**

$$
e_c = s_c + p_{d(c)}.
$$

A candidate is admitted only when its start is an allowed time-grid point and its complete duration fits within the configured end of the institutional day:

**H2b — Institutional time-grid and day-boundary compliance**

$$
s_c \in \mathcal{S}_{\delta_c},
\qquad
e_c \leq E_{\delta_c}.
$$

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

whenever the corresponding value is fixed. A conflicting fixed value therefore produces no admissible candidate rather than being treated as a soft preference.

The same candidate-set rule excludes a candidate when its faculty member is not eligible, its time lies outside the faculty's captured availability, or its interval intersects a matching existing commitment or recurring calendar block:

**H4 — Qualified, available, unblocked, grid-valid, and room-suitable candidate admissibility**

$$
C_d = \{c : \operatorname{eligible}(c) \land
\neg\operatorname{blocked}(c,\mathcal{B}) \land
\operatorname{fitsGrid}(c) \land
\operatorname{roomSuitable}(c)\}.
$$

### 5.7 Room suitability, features, and capacity

For a physical-room demand, candidate $c$ is admissible only when

**H5a — Physical-room capacity**

$$
\kappa_{r(c)} \geq q_{d(c)},
$$

**H5b — Required room type**

$$
T_{d(c)} = \varnothing
\quad\lor\quad
T_{d(c)} = T_{r(c)},
$$

and

**H5c — Required room features**

$$
A_{d(c)} \subseteq A_{r(c)}.
$$

The expected regular cohort must also fit its section capacity, but Laravel enforces that readiness condition before snapshot capture. The solver's room predicate then enforces the second physical-capacity boundary. A no-room modality uses $r(c)=\varnothing$ and does not consume a physical room.

### 5.8 Faculty, room, and delivery-group non-overlap

Partition the candidate intervals into resource/day buckets:

$$
C^F_{f,\delta}
=
\{c\in C : f(c)=f \land \delta_c=\delta\},
$$

$$
C^R_{r,\delta}
=
\{c\in C : r(c)=r \land \delta_c=\delta\},
$$

and

$$
C^G_{g,\delta}
=
\{c\in C : g(c)=g \land \delta_c=\delta\}.
$$

These bucket equations define the inputs to the following global constraints; they are not additional hard rules.

**H6 — Faculty non-overlap**

$$
\operatorname{NoOverlap}
\left(\{\mathcal{I}_c : c\in C^F_{f,\delta}\}\right)
\qquad \forall f\in F,\ \forall \delta,
$$

**H7 — Physical-room non-overlap**

$$
\operatorname{NoOverlap}
\left(\{\mathcal{I}_c : c\in C^R_{r,\delta}\}\right)
\qquad \forall r\in R,\ \forall \delta,
$$

**H8 — Section-delivery-group non-overlap**

$$
\operatorname{NoOverlap}
\left(\{\mathcal{I}_c : c\in C^G_{g,\delta}\}\right)
\qquad \forall g\in G,\ \forall \delta.
$$

OR-Tools considers only present intervals in each `NoOverlap` constraint. Therefore two selected candidates cannot overlap when they share a faculty member, physical room, or regular delivery group. This is semantically equivalent to excluding every conflicting selected pair, but it avoids materializing a separate linear inequality for every candidate pair.

### 5.9 Same-faculty rule for linked components

Let $D_{o,g}^{\mathrm{same}}$ be linked demands for offering $o$ and delivery group $g$ whose source rule requires one faculty member. For every pair $d,d' \in D_{o,g}^{\mathrm{same}}$ and every eligible faculty member $f$,

**H9 — Configured same-faculty requirement for linked components**

$$
\sum_{\substack{c \in C_d\\ f(c)=f}} x_c
=
\sum_{\substack{c \in C_{d'}\\ f(c)=f}} x_c.
$$

Combined with exact demand coverage, these equalities force all configured linked components to select the same faculty member. When the source rule is false, lecture and laboratory demands may select different qualified faculty.

### 5.10 Faculty-load accounting

The implementation counts the load of one offering/delivery-group combination once even when linked components produce multiple demand rows. For every candidate in a faculty/offering/group bucket,

**H10a — Selected-candidate activation of the deduplicated load bucket**

$$
x_c \leq y_{f,o,g},
$$

and

**H10b — Exact activation of the deduplicated load bucket**

$$
y_{f,o,g}
\leq
\sum_{\substack{c \in C:\\ f(c)=f,\,o(c)=o,\,g(c)=g}} x_c.
$$

Together, these constraints make $y_{f,o,g}=1$ exactly when at least one candidate in that bucket is selected. If $U_{f,o,g}$ is the maximum scaled unit value in the bucket, then

**H10c — Faculty load aggregation without linked-component double counting**

$$
L_f = \sum_{(o,g)} U_{f,o,g}y_{f,o,g},
$$

subject to

**H10d — Maximum permitted faculty load**

$$
L_f \leq M_f
\qquad \forall f \in F.
$$

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

For `balanced_v1`, version 1,

$$
w_E=w_I=w_B=w_R=1.
$$

The profile is code-defined and rejected if any key, hard-constraint order, version, or weight is changed.

### 6.1 Earlier institutional time blocks

With day index $\delta_c$ and start minute $s_c$, define the supporting candidate score

$$
a_c = \max\bigl(0, 10000-(1000\delta_c+s_c)\bigr),
$$

and the first implemented soft term:

**S1 — Earlier institutional time-block score**

$$
E = \sum_{c \in C} a_c x_c.
$$

This favors earlier days and start times among otherwise feasible choices. Late/weekend placement is therefore managed primarily by the configured operating grid and recurring blocking records, with this term giving an additional ranking preference toward earlier institutional blocks.

### 6.2 Faculty idle-gap penalty

For every faculty/day bucket $C^F_{f,\delta}$, define the activity indicator

$$
z_{f,\delta}
=
\max_{c\in C^F_{f,\delta}} x_c.
$$

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

The first selected start, last selected end, and total selected teaching duration are

$$
S^{\mathrm{first}}_{f,\delta}
=
\min_{c\in C^F_{f,\delta}} \widetilde{s}_c,
$$

$$
E^{\mathrm{last}}_{f,\delta}
=
\max_{c\in C^F_{f,\delta}} \widetilde{e}_c,
$$

and

$$
P_{f,\delta}
=
\sum_{c\in C^F_{f,\delta}} p_{d(c)}x_c.
$$

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

The implemented raw objective term is

**S2 — Faculty internal idle-gap score**

$$
I = -\sum_{f\in F}\sum_{\delta} G_{f,\delta}.
$$

Because the selected intervals for a faculty/day bucket cannot overlap, $G_{f,\delta}$ equals the sum of the gaps between consecutive selected meetings. A single meeting contributes zero; an inactive bucket is explicitly forced to zero. The term is neither pairwise nor capped.

### 6.3 Faculty-load balance penalty

For each unordered faculty pair, OR-Tools enforces

$$
\Delta_{ff'} = |L_f-L_{f'}|,
$$

using the official [`add_abs_equality`](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html) model operation. The raw balance term is

**S3 — Faculty-load balance score**

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

**S4 — Efficient suitable-room score**

$$
R = \sum_{c\in C} h_c x_c.
$$

Among suitable physical rooms, this prefers a smaller room over a larger room. It is intentionally a simple MVP proxy; it is not an occupancy percentage or a direct seat-slack equation.

### 6.5 Objective reconciliation

The service returns `objective_details` containing each term's raw value, fixed weight, weighted value, and total. Laravel independently verifies the equivalent reconciliation of O1:

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
- the exact typed `solver_statistics` allowlist, including the one-worker and fixed-seed settings;
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

## 11. Experimental evaluation and operating envelope

### 11.1 Evaluation purpose and scale basis

The evaluation answers three bounded questions: whether the implemented TALA pipeline produces valid client-scale schedules; which Cloud Run profile gives the strongest client-scale solution quality without unnecessary resources; and what larger synthetic workload is repeatably accepted before a time, model, or memory boundary is observed. It does not position TALA as a direct replacement for enterprise whole-university timetabling and does not treat optimization as predictive classification.

The client reports 47 students across BM, IT, and THM: 35 first-year and 12 second-year students. One section is currently accommodated for each program-year combination, producing six cohorts. The accepted operational fixture converts the disclosed curricula and resources into 54 Scheduling Demands, 12 faculty records, 6 rooms, and 156 half-hour slots. Scheduling Demand count, candidate count, resources, and slots—not raw student headcount—govern this CP-SAT model because TALA schedules cohort meetings rather than individual students.

Published university-timetabling studies provide context rather than equivalent datasets. ITC 2019 includes problems from roughly 500 classes at school or faculty scope to thousands of classes across broad university scope, while UniTime models classes, students, rooms, instructors, and group constraints under a different formulation. TALA therefore derives its experimental tiers from its own accepted workload instead of copying external headcounts:

| Tier | Demands | Faculty | Rooms | Slots | Meaning |
| --- | ---: | ---: | ---: | ---: | --- |
| Reduced technical | 27 | 6 | 3 | 156 | Harness and monotonic-growth check; not an institutional minimum |
| Client-representative | 54 | 12 | 6 | 156 | Current client-scale baseline associated with 47 students and six cohorts |
| Proportional 2× | 108 | 24 | 12 | 156 | Doubled work and resources with the original ratio retained |
| Contention 2× | 108 | 12 | 6 | 156 | Doubled work without additional faculty or rooms |
| Proportional 4× | 216 | 48 | 24 | 156 | Exploratory upper model-growth tier; not a promised maximum |

### 11.2 Experimental controls and measures

All Cloud Run profiles used the same immutable solver image, OR-Tools 9.15.6755, `tal94-demand-v2`, `balanced_v1`, random seed `20260718`, concurrency one, minimum instances zero, maximum instances three, and a 300-second HTTP timeout. The representative tier was executed ten times on each profile. Larger tiers used bounded 30-, 120-, and 240-second solver windows. Python verification ran in Cloud Build; Laravel performed environment guards, snapshot capture, authenticated dispatch, typed-response validation, independent assignment validation, publication, and rollback checks.

| Profile | vCPU | Memory | Workers | Experimental role |
| --- | ---: | ---: | ---: | --- |
| A | 1 | 2 GiB | 1 | Smallest client-scale comparison |
| B | 2 | 4 GiB | 2 | Client-production selection candidate and 2× comparison |
| C | 4 | 8 GiB | 4 | Upper research profile for 2× and 4× boundaries |

For incumbent objective $Z_i$, CP-SAT bound $B_i$, and same-tier best observed objective $Z^*$,

$$
\operatorname{gap}_i = \frac{|Z_i-B_i|}{\max(1,|Z_i|)},
$$

$$
\operatorname{RPD}_i = \frac{|Z^*-Z_i|}{\max(1,|Z^*|)}\times 100.
$$

A run is accepted only when its status is `optimal` or `feasible`, all demands are assigned, solver and Laravel hard-violation counts are zero, typed telemetry is complete, and no authentication, transport, or container failure occurs. The relative gap and same-tier RPD describe optimization evidence; neither is an accuracy score. Complete coverage and zero independently validated hard violations establish correctness, while objective, bound, gap, RPD, and runtime describe solution quality.

### 11.3 Client-production profile selection

All 30 client-representative comparison runs assigned 54 of 54 demands with zero solver and Laravel hard violations.

| Measure | Profile A | Profile B | Profile C |
| --- | ---: | ---: | ---: |
| Accepted runs | 10/10 | 10/10 | 10/10 |
| Objective range | 411,830–411,890 | 428,590–454,120 | 427,640–445,900 |
| Median relative gap | 12.9945% | **4.4632%** | 7.4411% |
| p95 relative gap | 13.0110% | **8.5408%** | 8.7747% |
| Median runtime | 31.359 s | **31.017 s** | 31.402 s |
| p95 runtime | 31.879 s | **31.380 s** | 31.866 s |
| Maximum minute p99 CPU | 91.98% | 77.98% | 64.98% |
| Maximum minute p99 memory | 59.98% | 39.98% | 25.99% |

Profile B was selected because validity was equal but its median and p95 gaps were smallest and its median and p95 runtimes were fastest. It retained substantial memory headroom, while profile C doubled B's resources without improving the representative distribution. Profile A was cheaper and smaller but produced materially weaker bound evidence. This selection follows the approved order of validity, solution quality, performance, resource headroom, and then cost.

### 11.4 Larger-workload evidence and observed boundary

At the 30-second window, profiles B and C returned `unknown` without an incumbent for proportional 2×, with complete model telemetry and no infrastructure failure. The next approved window therefore increased search time rather than changing the mathematical model.

| Tier and profile | Accepted | Result | Gap range | Runtime | Model scale |
| --- | ---: | --- | ---: | ---: | --- |
| Proportional 2×, B, 120 s | 3/3 | `feasible` | 10.8929%–11.9615% | median 123.947 s | 35,712 candidates; 108,120 variables; 216,384 constraints |
| Proportional 2×, C, 120 s | 3/3 | `feasible` | 8.4125%–9.8023% | median 124.893 s | same model scale |
| Contention 2×, C, 120 s | 0/1 | diagnostic `infeasible` | — | 58.131 s | 20,712 candidates; 62,610 variables; 125,688 constraints |
| Proportional 4×, C, 120 s | 0/1 | `unknown`; compute boundary | — | 140.689 s | 131,424 candidates; 396,816 variables; 793,344 constraints |
| Proportional 4×, C, 240 s | 2/3 | two `feasible`; one OOM/503 | 5.2694%–29.6280% | 258.508–260.196 s for accepted runs | same 4× model scale |

Proportional 2× is the largest **repeatably accepted** tier under the tested controls. Proportional 4× is only the largest tier with an accepted observation: one confirmation consumed 8,197 MiB, exceeded the approved 8 GiB Cloud Run limit, and caused instance termination and HTTP 503. It is therefore an observed resource boundary, not a supported maximum. The contention result applies only to the disclosed synthetic transformation and does not prove that every real 108-demand workload is infeasible.

### 11.5 Production acceptance, cost, and applicability

Profile B was promoted to 100% canonical traffic with private IAM and concurrency one. Two post-promotion authenticated solves each assigned 54 of 54 demands with zero hard violations. Laravel independently validated the responses, ingested 54 candidate rows, exercised Registrar publication of 54 official meetings, and rendered the affected Faculty schedule inside a rolled-back database transaction. No schedule run, candidate row, official meeting, or queued job survived, and the scheduling queue was resumed.

Using observed elapsed time as a billable-time proxy and the dated Singapore request-based list rates of US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, and US$0.40 per million requests, the 80-request experiment is estimated at US$0.214198 before free-tier credits. This is a bounded experiment estimate rather than a monthly forecast or invoice; it excludes billing-rounding differences, networking, registry and build charges, taxes, discounts, and unrelated project usage.

The empirical findings do not alter any equation in Sections 5 and 6. They establish that the current formulation and Laravel validation pipeline are operationally accepted at the disclosed client scale; identify profile B as the production baseline; establish proportional 2× at 120 seconds as repeatable larger-workload evidence; and disclose 4×/8 GiB as an observed limit requiring future optimization or a separately approved resource study before any operational promise.

## 12. Equation-to-implementation traceability

| Formulation or pipeline claim | Product/architecture authority | Current implementation evidence | Focused test evidence |
| --- | --- | --- | --- |
| Scheduling Demand is the canonical unit; candidate before official schedule | [`prd_modules/06_cpsat_scheduling.md`](../prd_modules/06_cpsat_scheduling.md), [`architecture_specification.md`](../architecture_specification.md) | `GenerateSchedulingDemand`, `ScheduleSolverSnapshotService`, `CandidateScheduleRow`, `SchedulePublishService` | Scheduling generation and publication feature tests |
| `tal94-demand-v2` differs from `balanced_v1` v1 | PRD product-level solver contract and code-defined-profile rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `CONTRACT_VERSION`, profile checks | [`test_solver.py`](../../cloud/scheduler-solver/tests/test_solver.py): unsupported contract and tampered profile cases |
| H1 — exact coverage $\sum_{c\in C_d}x_c=1$ | PRD assignment coverage | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): Boolean variables and equality per demand | `test_accepts_v2_demands...`, conflicting fixed-demand test |
| H2a-H4 — duration/grid, fixed values, and candidate admissibility | PRD fixed assignment, calendar, qualification, and consecutive-block rules | `ScheduleSolverSnapshotService`; [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_faculty_ids`, `_room_ids`, `_slots_for_demand`, availability/commitment/calendar filters | Fixed assignment and recurring calendar-block solver tests; snapshot feature tests |
| H5a-H5c — room capacity, type, and features | PRD room suitability and capacity rules | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_room_suits_demand`; Laravel independent validator | Required-features and no-suitable-room solver tests; assignment-validation feature tests |
| H6-H8 — faculty, room, and delivery-group `NoOverlap` | PRD hard constraint source map | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_no_overlap_constraints`; Laravel validation/revalidation services | `test_model_growth_uses_resource_no_overlap_instead_of_candidate_pair_constraints`; same-group-and-room solver test; assignment-validation feature tests |
| H9 — configured same-faculty equality | PRD linked-component rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_same_faculty_constraints`; Laravel validator | Linked-component and validation cases |
| H10a-H10d — deduplicated load and maximum $L_f\le M_f$ | PRD faculty load rule | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_add_faculty_load_constraints`; Laravel validator | Faculty-load and linked-component load tests |
| S2 — faculty/day internal idle-gap term $I=-\sum G_{f,\delta}$ | PRD faculty idle-gap preference; approved `balanced_v1` profile | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_idle_gap_objective_terms`, `_objective_details`; Laravel objective reconciliation | `test_faculty_idle_gap_counts_only_time_between_adjacent_meetings`; objective-details validation tests |
| S1-S4 and O1 — four-term fixed objective and reconciliation | PRD soft-preference rules; approved `balanced_v1` profile | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): objective builders and `_objective_details`; Laravel assignment validator | Objective-details solver test; TAL-94B1 validation tests |
| Typed experimental statistics and fixed search configuration | Approved TAL-96B2 contract | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py): `_solver_statistics`; Laravel strict response validation and allowlisted diagnostics persistence | `test_solver_statistics_are_typed_allowlisted_and_reproducible`; TAL-94B1 statistics rejection cases; guarded TAL-96B2 real-service acceptance |
| Solver statuses are distinct from queue, transport, and container-runtime failure | Architecture queue and external solver boundary | `ScheduleSolverDispatchJob`, `ScheduleSolverDispatchLifecycleService`, `ScheduleCloudResultIngestor`, [`server.py`](../../cloud/scheduler-solver/tala_solver/server.py) | [`test_server.py`](../../cloud/scheduler-solver/tests/test_server.py); TAL-94E2a queue operations tests |
| Immutable input, after-commit dispatch, independent ingestion | Architecture transaction and source-of-truth boundary | `ScheduleGenerationService`, `ScheduleSolverSnapshotService`, `ScheduleSolverDispatchJob`, `ScheduleCloudResultIngestor` | TAL-62 dispatch and TAL-94 queue/validation tests |
| Registrar correction, revalidation, and publication authority | PRD manual override/publication rules; UI blueprint | `CandidateScheduleRowReviewService`, `ScheduleAssignmentRevalidationService`, `ScheduleGenerationRunPolicy`, `SchedulePublishService` | Candidate-review, assignment-validation, and TAL-94D1 publication tests |
| Worked example and 18,900 objective | Existing deterministic fixture | [`minimal_snapshot.json`](../../cloud/scheduler-solver/samples/minimal_snapshot.json), [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py) | All Python solver tests; direct fixture execution with pinned requirements |

## 13. References

### Internal authorities, implementation, and presentation references

1. TALA. [CP-SAT Scheduling Subsystem PRD](../prd_modules/06_cpsat_scheduling.md).
2. TALA. [Architecture Specification](../architecture_specification.md).
3. TALA. [UI Surface Blueprint](../ui_surface_blueprint.md).
4. TALA. [Python CP-SAT solver](../../cloud/scheduler-solver/tala_solver/solver.py) and [deterministic sample snapshot](../../cloud/scheduler-solver/samples/minimal_snapshot.json).
5. PyJobShop article copy in the repository, used only as a reference for organizing constraint-programming equations by logical implementation category: [Solving scheduling problems with constraint programming in Python](how%20the%20eqautions%20should%20look/PyJobShop-Solving%20scheduling%20problems%20with%20constraint%20programming%20in%20Python.md).
6. Han, X.; Wang, D. (2025). *Gradual Optimization of University Course Scheduling Problem Using Genetic Algorithm and Dynamic Programming*. *Algorithms*, 18(3), 158. [Repository copy](how%20the%20eqautions%20should%20look/Gradual%20Optimization%20of%20University%20Course%20Scheduling%20Problem%20Using%20Genetic%20Algorithm%20and%20Dynamic); [DOI](https://doi.org/10.3390/a18030158). Used only as a reference for presenting hard and soft constraints as individually explained equations. Its GA/DP model, fitness functions, datasets, comparative results, and claims are not part of TALA's CP-SAT formulation or evidence.

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
