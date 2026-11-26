# TALA Scheduling Optimization: Constraint Programming–Satisfiability (CP-SAT) Formulation, Independent Validation, and Empirical Capacity

> **Supporting Technical and Research Formulation — Not Governing Authority.** Product policy and scheduling semantics are governed by [`PRD 03`](../prd_modules/03_academic_setup_offerings_published_timetable.md) (`00_Project_Documents/prd_modules/03_academic_setup_offerings_published_timetable.md`), system boundaries and runtime configuration by the [`Architecture Specification`](../architecture_specification.md) (§9.1), and active execution by current repository source on `main`. Dated July 2026 empirical measurements in Section 11 are preserved verbatim as historical research evidence.

---

## Authority, Implementation State, and Deployment Distinction

Claims about TALA timetable scheduling span four distinct evidentiary layers that must never be conflated:

1. **Current repository source (`issue-93-academic-operations` / post-factored CP-SAT refactor)**: Both [`cloud/scheduler-solver/tala_solver/solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py) and Laravel's scheduling services implement contract `tala-timetable-v2`, solver version `cloud-cp-sat-tala-timetable-v2-lexicographic-v1-deadline-v2`, OR-Tools `9.15.6755`, Python `3.12`, and the six-level lexicographic hierarchy `lexicographic_v1`. `solver.py` uses a factored per-meeting interval formulation (`_prepare_meetings`, `_build_factored_hard_model`) with P1 pre-solve feasibility guards (`_presolve_feasibility_guards`) and P2 Stage-2 large-problem controls (`_solve_lexicographic`, `_Stage2EarlyStopCallback`), while Level 3 (`faculty_load_imbalance`) spans every faculty member declared in the snapshot, including unassigned faculty ($L_f = 0$).
2. **Local automated verification**: Python unit tests (`cloud/scheduler-solver/tests/`, `60` passing tests including [`test_scale_and_feasibility_guards.py`](../../cloud/scheduler-solver/tests/test_scale_and_feasibility_guards.py)) verify P1 pre-solve feasibility guards, factored `start_var` admissibility, unified week-timeline resource `AddNoOverlap`, buffered cross-modality intervals, structural `same_faculty_required`, distinct-day spread, differential equational parity with a reference evaluator (`evaluate_candidate_membership`), repair semantics, Stage-2 budget/early-stop controls, and 200-demand scale benchmarks. Laravel feature tests (`tests/Feature/AcademicScheduling/`) verify snapshot capture, HTTP-faked ingestion, independent validation/revalidation, adjustment/repair, and publication.
3. **Recorded Cloud Run architecture baseline (`Architecture Specification §9.1`)**: On August 19, 2026 (Issue `#22`), an 8-vCPU / 16-GiB Cloud Run revision (`tala-scheduler-solver-i22-3f461a46`, concurrency `1`, max `2` instances, `SOLVER_WORKER_COUNT = 8`, `360`s Cloud Run request timeout) was recorded as activated in the architecture specification.
4. **Outdated live Cloud Run deployment and historical July 2026 benchmarks**: As confirmed by the project owner during reconciliation, the live Cloud Run deployment has **not** been redeployed to the current refactored `solver.py`, and Sections 11.1–11.6 preserve July 2026 Cloud Run benchmark measurements executed against the superseded `tal94-demand-v2` / `balanced_v1` revisions (`b4f-ad9177e472f8` and `d5dstage2-665963443cc0`), supplemented by a dated November 2026 local benchmark addendum (Section 11.7). Neither repository source correctness nor local test suites establish current Cloud Run deployment state.

---

## 1. Purpose, Governance Boundary, and Division of Responsibilities

TALA treats whole-Term academic timetabling as **exact-Term constraint optimization over discrete pre-validated meetings**, not machine learning and not autonomous schedule publication. The pipeline separates responsibilities into four strict trust boundaries:

| Stage | Component | Responsibility | Non-Responsibility |
| :--- | :--- | :--- | :--- |
| **1. Snapshot Capture** | `ScheduleSolverSnapshotService`, `ReadyTermPlanningProjection` | Resolves one exact-Term ready planning dataset (`tala-timetable-v2`), delivery groups, shared cohorts, rooms, qualified faculty, and recurring calendar blocks; signs body with SHA-256. | Does not enumerate solver candidates or solve intervals. |
| **2. Meeting Preparation, Pre-Solve Guards & CP-SAT Search** | `tala_solver/solver.py` (`solve_snapshot`, `_prepare_meetings`, `_presolve_feasibility_guards`, `_build_factored_hard_model`, `_solve_lexicographic`) | Resolves per-meeting admissible slots/faculty/rooms; fails fast on P1 resource/load/grid impossibilities; enforces hard non-overlap, buffered modality transitions, structural same-faculty linkage, distinct-day spread, deduplicated load, and repair rules via factored OR-Tools CP-SAT; optimizes `lexicographic_v1`. | Never mutates the database, reads live state outside the snapshot, or publishes official schedules. |
| **3. Independent Validation & Revalidation** | `ScheduleCloudResultIngestor`, `ScheduleAssignmentValidationService`, `ScheduleAssignmentRevalidationService` | Re-validates every solver assignment independently against the immutable snapshot *and* current live Term entities/official `SectionMeeting`s before creating `CandidateScheduleRow` records. | Never repairs invalid solver output silently; rejects inconsistent results to `StatusBlocked`. |
| **4. Human Review, Repair & Publication** | `AdjustCandidateMeeting`, `RequestTimetableRepair`, `SchedulePublishService`, `PublishedScheduleRevisionService` | Authorized Registrar review: single-meeting local adjustment (synchronously revalidated) or full CP-SAT repair run, explicit atomic publication (`PublishTimetableVersion`), and post-publish revisions. | Solver has zero write access to review decisions or official `SectionMeeting` state. |

> **Consolidated active scope.** Superseded active sections from earlier versions of this document—the duplicate preliminary exchange summary, outdated demo UI click-path walkthroughs and credentials, retired `MIN`/`MIDDLE`/`MAX` dataset selectors, and `tal94-demand-v2` / `balanced_v1` weighted-sum equations—have been removed from the active formulation. Per [`cloud/scheduler-solver/README.md`](../../cloud/scheduler-solver/README.md), the active canonical journey uses the single Canonical TALA Scheduling Dataset (`47` Students, `6` cohorts, `9` Faculty, `10` rooms, and `54` scheduling demands), while historical Cloud Run benchmark tables remain intact in Sections 11.1–11.6.

---

## 2. Snapshot Contract (`tala-timetable-v2`), Demand Decomposition, and Repair Mode

### 2.1 Immutable Snapshot and Integrity Boundary

Laravel dispatches a frozen JSON snapshot (`contract_version = "tala-timetable-v2"`, `constraint_profile.key = "lexicographic_v1"`, `version = 1`) computed for a single `term_id` and `solver_run_id`. `CloudRunSchedulingSolverClient` attaches `X-TALA-Request-ID` and `X-TALA-Snapshot-SHA256`; `tala_solver/server.py` hashes raw request bytes and rejects mismatched payloads with HTTP `400` (`snapshot_hash_mismatch`). A monotonic `SolverRequestContext` enforces a single deadline covering JSON parsing through response serialization.

> **Retained legacy compatibility branch.** `solver.py` and `ScheduleAssignmentValidationService` still retain guarded compatibility code for `LEGACY_CONTRACT_VERSION = "tal94-demand-v2"` (`balanced_v1`), where each demand has `meeting_count = 1` and a four-term weighted sum (`prefer_earlier_time_blocks`, `reduce_faculty_idle_gaps`, `balance_faculty_load`, `use_rooms_efficiently`). Active Laravel snapshots (`ScheduleGenerationRun::ContractVersion`) always emit `tala-timetable-v2` and `lexicographic_v1`.

### 2.2 Multi-Meeting Decomposition (`(d, q)`) and Shared Cohorts ($\mathcal{H}(d)$)

Unlike legacy one-slot-per-demand models, `tala-timetable-v2` decomposes each scheduling demand $d \in \mathcal{D}$ into $K_d \in \{1, 2, 3\}$ equal-duration weekly meeting units:

$$
\mathcal{U} = \bigl\{\, (d, q) : d \in \mathcal{D},\; q \in \{1, \dots, K_d\} \,\bigr\}, \qquad K_d \in \{1, 2, 3\},\; p_d \ge 30\text{ min}.
$$

Common patterns include `1x60`, `1x120`, `2x90`, and `3x60` depending on component weekly duration and `meeting_count`. Each unit $(d, q)$ receives its own continuous contiguous block of length $p_d$. Because all $K_d$ units share the demand's resolved cohort tuple and cannot overlap each other (Section 5.2)—and additionally receive an `AddAllDifferent` weekday spread when sufficient distinct days exist (Section 5.4)—a $2\times90$ or $3\times60$ course produces separate non-overlapping meetings without duplicating faculty load.

Shared or merged-section deliveries map a `section_delivery_group_id` to one or more `cohort_or_student_group_id` entries via `student_cohort_groups`. During normalization (`_cohort_ids_by_delivery_group`), `solver.py` resolves a consistent, non-empty sorted tuple $\mathcal{H}(d) \subseteq \mathcal{H}$ for every demand; mismatched explicit vs. declared mappings terminate immediately as `model_invalid` (`invalid_student_cohort_mapping`).

### 2.3 Generation vs. Repair Operations

The optional `operation` object distinguishes two solver workflows:

- **`operation.kind == "generation"`** (or omitted): solves all meeting specs $m \in \mathcal{M}_{\text{meet}}$ from scratch over their factored `(valid_slots, valid_faculty, valid_rooms)` domains.
- **`operation.kind == "repair"`** (`RequestTimetableRepair`): supplies an immutable baseline `source_candidate.assignments` covering every key in $\mathcal{U}$ plus a single `requested_assignment` $(d^*, q^*)$ edited by the Registrar. CP-SAT fixes $(d^*, q^*)$ to the requested placement (if admissible via `_matching_assignment_spec`) as a hard constraint, adds a priority-0 objective minimizing deviations from the remaining baseline meetings (`changed_non_requested_meetings`), and then optimizes Levels 1–6 subject to preserving the repaired baseline where possible.

---

## 3. Mathematical Notation

### 3.1 Sets, Constants, and Indices

| Symbol | Code Mapping | Definition |
| :--- | :--- | :--- |
| $\mathcal{D}$ | `snapshot["scheduling_demands"]` | Set of ready scheduling demands $d$ in the snapshot Term. |
| $\mathcal{Q}_d$ | `range(1, meeting_count + 1)` | Meeting sequence indices $\{1, \dots, K_d\}$ for demand $d$, with $K_d \in \{1, 2, 3\}$. |
| $\mathcal{U}$ | `by_assignment.keys()` | Schedulable demand-sequence units $u = (d, q)$, with $\lvert \mathcal{U} \rvert = \sum_{d \in \mathcal{D}} K_d$. |
| $\mathcal{M}_{\text{meet}}$ | `meetings` from `_prepare_meetings` | Ordered list of factored `MeetingSpec` objects $m$ (one per $u \in \mathcal{U}$, indexed `m.index`). |
| $\mathcal{F}$ | `snapshot["faculty"]` | All faculty IDs $f$ present in the snapshot (including unassigned faculty). |
| $\mathcal{R}$ | `snapshot["rooms"]` | Active physical room IDs $r$; extended set $\mathcal{R}^0 = \mathcal{R} \cup \{\varnothing\}$ allows `room_id = None` when unroomed. |
| $\mathcal{H}$ | Resolved `cohort_or_student_group_ids` | Distinct cohort / student-group IDs $h$. |
| $\mathcal{T}$ | `parsed_slots` (`_parsed_time_slots`) | Active discrete Term schedule grid start slots $t$. |
| $\mathcal{W}$ | `day_of_week` values in $\mathcal{T}$ | Scheduling days of week $w \in \{1, \dots, 7\}$. |
| $\mathcal{M}_{\text{mod}}$ | Distinct `modality` strings | Normalized uppercase delivery modalities (e.g., `FACE_TO_FACE`, `ONLINE`). |
| $\Delta_{\text{stride}}$ | `DAY_STRIDE_MINUTES = 3_000` | Unified week-timeline stride separating weekday $w$ starts from $w+1$ (`w * 3000 + starts_minute`). |
| $B_{\text{mod}}$ | `MODALITY_BUFFER_MINUTES = 30` | Mandatory transition gap (minutes) between different modalities of distinct demands on a cohort-day. |
| $N_{\text{bool}}$ | `SLOT_BOOL_OPTION_LIMIT = 1_000` | Total-option threshold (`option_count <= 1000`) below which per-slot Boolean mirrors (`slot_vars`) are materialized. |

### 3.2 Demand, Resource, and Meeting Parameters

| Symbol | Code / Helper | Definition |
| :--- | :--- | :--- |
| $p_m$ | `meeting.duration_minutes` (`_duration_minutes`) | Required contiguous duration in minutes ($p_m \ge 30$). |
| $q_m$ | `meeting.expected_count` | Confirmed seat headcount ($q_m \in \mathbb{Z}_{>0}$ required in `tala-timetable-v2`). |
| $o_m, g_m$ | `term_offering_id`, `section_delivery_group_id` | Term offering and delivery group identifiers for load deduplication and same-faculty grouping. |
| $\mathcal{H}(m)$ | `meeting.cohort_or_student_group_ids` | Non-empty sorted tuple of cohorts attached to meeting $m$. |
| $\text{same}_m$ | `meeting.same_faculty_required` | Boolean indicating all units in $(o_m, g_m)$ with this flag share one faculty member. |
| $\lambda_m$ | `meeting.scaled_load_units` (`_decimal_scaled`) | Demand teaching load in scaled hundredths ($\text{round}(100 \times \text{load\_units})$). |
| $\Lambda_f$ | `_decimal_scaled(max_allowed_units)` | Maximum allowed Term load for faculty $f \in \mathcal{F}$ in scaled hundredths. |
| $\kappa_r$ | `room["capacity"]` | Seat capacity of physical room $r \in \mathcal{R}$ ($\kappa_\varnothing = 0$). |
| $F^{\text{room}}_r, F^{\text{req}}_d$ | `feature_keys`, `required_room_feature_keys` | Uppercase-normalized room feature sets. |
| $w(t), s_t$ | `ParsedSlot.day_of_week`, `starts_minute` | Day of week and start minute from midnight for slot $t \in \mathcal{T}$. |
| $\tau(t)$ | `w(t) * DAY_STRIDE_MINUTES + s_t` | Unified week-timeline start coordinate ($\Delta_{\text{stride}} = 3000 > 1440 + \max p_m + B_{\text{mod}}$ so adjacent days never collide). |
| $E^{\text{grid}}_w$ | `_parsed_day_ends(parsed_slots)[w]` | Latest slot end minute present on day $w \in \mathcal{W}$. |
| $\mathcal{S}_m$ | `meeting.valid_slots` | Pre-filtered admissible `ParsedSlot` options for meeting $m$. |
| $\mathcal{F}_m$ | `meeting.valid_faculty` | Pre-filtered eligible faculty IDs for meeting $m$ (intersected across `same_fac_groups`). |
| $\mathcal{R}_m$ | `meeting.valid_rooms` | Pre-filtered admissible physical rooms (`()` when unroomed, `room_required == False`). |
| $\mathcal{B}^{\text{fac}}_{m,f}, \mathcal{B}^{\text{room}}_{m,r}$ | `faculty_blocked_slot_indices`, `room_blocked_slot_indices` | Slot indices in $\mathcal{S}_m$ whose `[day, s_t, s_t + p_m)` conflicts with fixed commitments or calendar blocks on $f$ or $r$. |

For factored resource and group indexing:
- $\mathcal{G}_{\text{same}}$: groups $(o, g)$ having $\ge 2$ meetings with `same_faculty_required = true`,
- $\mathcal{M}_{d}$: ordered meetings $(m_1, \dots, m_{K_d})$ belonging to demand $d$,
- $\mathcal{M}_{f, o, g} = \{m \in \mathcal{M}_{\text{meet}} : f \in \mathcal{F}_m,\; o_m = o,\; g_m = g\}$.

---

## 4. Meeting Preparation, P1 Pre-Solve Feasibility Guards, and P0 Factored Variables

### 4.1 Per-Meeting Domain Resolution (`_prepare_meetings`, `_filter_viable_options`)

Rather than materializing the Cartesian product $(d, q, f, r, t)$ upfront (`Candidate` tuples are only reconstructed post-solve in `_selected_candidates`), `solver.py` resolves three factored option domains per meeting unit $m = (d, q) \in \mathcal{M}_{\text{meet}}$:

1. **Snapshot Structural & Pattern Pre-Checks (`solve_snapshot`)**: Requires `contract_version = "tala-timetable-v2"`, exact `lexicographic_v1` profile with no `soft_weights`, consistent non-empty cohort map $\mathcal{H}(d)$, valid meeting counts $K_d \in \{1, 2, 3\}$ with $p_d \ge 30$, numeric unit loads, and $q_d > 0$. Any failure returns `model_invalid` prior to preparation.
2. **Faculty Eligibility & Fixed Faculty (`_faculty_ids`, `_prepare_meetings`)**: $\mathcal{F}_m \subseteq \mathcal{F}^{\text{elig}}_{d,q}$; if `fixed_faculty_user_id` is non-null, only that faculty is kept provided it is eligible (`missing_faculty` otherwise). When `same_faculty_required` links multiple meetings in $(o, g)$, `_prepare_meetings` intersects $\mathcal{F}_m$ across every meeting in the group (`_intersected_faculty`) before CP-SAT construction; an empty intersection triggers `solver_unassigned`.
3. **Room Requirement, Type, Features, Capacity, and Fixed Room (`_room_ids`, `_room_suits_demand`)**:
   - Room is mandatory if `bool(room_required) or modality == "FACE_TO_FACE"`.
   - When unroomed (`_room_required(d) == False`), $\mathcal{R}_m = \varnothing$ (yielding `room_id = None` at extraction).
   - When roomed, every $r \in \mathcal{R}_m$ must match `room_type_requirement` (if non-empty), satisfy $F^{\text{req}}_d \subseteq F^{\text{room}}_r$, and satisfy $\kappa_r \ge q_d$. If `fixed_room_id` is specified, only that room is kept if suitable (`missing_room` otherwise).
4. **Time-Slot Alignment & Global/Delivery-Group Exclusions (`_slots_for_demand_parsed`, `_slot_conflicts_global_or_dg`)**: Slot $t \in \mathcal{T}$ enters $\mathcal{S}_m$ if it matches `fixed_day_of_week` (if non-null), matches `fixed_start_time` (if non-null), satisfies $s_t + p_d \le E^{\text{grid}}_{w(t)}$, respects legacy `faculty_availability` `"available"` windows (when non-empty), and avoids term-wide calendar blocks ($r_b = \text{null} \land f_b = \text{null}$) as well as delivery-group or cohort `existing_commitments`.
5. **Arc-Consistency Pruning (`_filter_viable_options`)**: For each meeting $m$, slot $t \in \mathcal{S}_m$ is kept only if at least one $(f, r)$ pair exists with $t$ unblocked on both $f$ and $r$; likewise $\mathcal{F}_m$ and $\mathcal{R}_m$ keep only resources with at least one unblocked slot in $\mathcal{S}_m$ (propagated alongside per-resource blocked-slot lists $\mathcal{B}^{\text{fac}}_{m,f}$ and $\mathcal{B}^{\text{room}}_{m,r}$).

If any meeting hits an empty faculty, room, or slot domain (`missing_faculty`, `missing_room`, `missing_time_slot`, or post-pruning `solver_unassigned`), `solve_snapshot` returns `solver_status = "infeasible"` immediately without building `CpModel`.

### 4.2 P1 Pre-Solve Feasibility Guards (`_presolve_feasibility_guards`)

After `_prepare_meetings` and before constructing `CpModel`, `_presolve_feasibility_guards` evaluates deterministic necessary capacity, load, and grid conditions across the prepared meetings and returns `solver_status = "infeasible"` immediately with typed `reasons` and `conflict_assignments` whenever any necessary condition fails:

1. **`faculty_exclusive_load_exceeded`**: Over meetings assigned exclusively to a single faculty (`len(m.valid_faculty) == 1`), deduplicates `(f, o_m, g_m)` groups and verifies $\sum_{(o, g)} \max_{m \in \mathcal{M}^{\text{excl}}_{f,o,g}} \lambda_m \le \Lambda_f$.
2. **`faculty_exclusive_hours_exceeded` & `faculty_exclusive_block_too_long`**: Computes each faculty's usable open minutes per weekday by subtracting global and faculty-specific recurring calendar blocks and existing commitments from the daily grid span (`_subtract_intervals`), then checks that (a) total exclusive meeting minutes on $f$ do not exceed total weekly open minutes, and (b) the longest exclusive meeting duration does not exceed the longest single uninterrupted open block across all days.
3. **`room_type_capacity_exceeded`**: Checks every distinct eligible physical room subset $\mathcal{P}$ (partitioned by `room_type`, distinct meeting `valid_rooms` pools, and the full active room pool) to ensure that total demanded duration across roomed meetings whose $\mathcal{R}_m \subseteq \mathcal{P}$ does not exceed the sum of usable open minutes across rooms $r \in \mathcal{P}$ (net of global and per-room blocks/commitments).
4. **`cohort_weekly_minutes_exceeded`**: Sums required durations across all meetings touching cohort $h \in \mathcal{H}$ and rejects if that total exceeds total usable weekly grid open minutes (net of global calendar blocks).

### 4.3 P0 Factored CP-SAT Decision and Interval Variables (`_build_factored_hard_model`, `FactoredModelState`)

Instead of $O(|\mathcal{M}_{\text{meet}}| \cdot |\mathcal{S}| \cdot |\mathcal{F}| \cdot |\mathcal{R}|)$ candidate tuples, `FactoredModelState` introduces independent time, faculty, and room decision variables linked on a unified week timeline ($\Delta_{\text{stride}} = 3000$):

- **Unified week-start, day, and minute variables** (per $m \in \mathcal{M}_{\text{meet}}$):
  $$
  \text{start}_m \in \bigl\{\, \tau(t) : t \in \mathcal{S}_m \,\bigr\}, \qquad
  \text{day}_m = \left\lfloor \frac{\text{start}_m}{3000} \right\rfloor \quad (\text{via } \texttt{AddDivisionEquality}), \qquad
  \text{min}_m = \text{start}_m - 3000\,\text{day}_m.
  $$
- **Conditional per-slot Boolean mirror (`slot_vars`)**: Let $\text{option\_count} = \sum_{m \in \mathcal{M}_{\text{meet}}} |\mathcal{S}_m| \cdot \max(1, |\mathcal{F}_m|) \cdot \max(1, |\mathcal{R}_m|)$. Only when $\text{option\_count} \le N_{\text{bool}} = 1000$ (`use_slot_bools = True`) does `_build_factored_hard_model` allocate $x_{m,t} \in \{0, 1\}$ with $\sum_{t \in \mathcal{S}_m} x_{m,t} = 1$ and $\text{start}_m = \sum_{t \in \mathcal{S}_m} \tau(t)\, x_{m,t}$. Above `1,000` options, slot booleans are omitted and Stage-2 objectives project directly from `start_m`, `day_m`, and `min_m`.
- **Factored faculty selectors (`fac_vars`)**: If $|\mathcal{F}_m| = 1$, faculty selection is fixed (`fac_vars[m] = {}`). If `same_faculty_required = true`, meetings in $(o_m, g_m)$ share one common `BoolVar` tuple $\text{fac\_grp}_{o,g,f} \in \{0, 1\}$; otherwise each meeting creates $y^{\text{fac}}_{m,f} \in \{0, 1\}$ (`fac_{m}_{f}`).
- **Factored room selectors (`room_vars`)**: Unroomed (`room_required == False`) or singleton-room ($|\mathcal{R}_m| = 1$) meetings create zero room booleans (`room_vars[m] = {}`); multi-room meetings create $y^{\text{room}}_{m,r} \in \{0, 1\}$.
- **Unified week-timeline resource intervals**:
  - **Cohort fixed-size interval**: $I_m = \text{NewFixedSizeIntervalVar}(\text{start}_m, p_m)$, attached unconditionally to every cohort $h \in \mathcal{H}(m)$.
  - **Faculty intervals**: reuses fixed $I_m$ if $|\mathcal{F}_m| = 1$; otherwise creates $I^{\text{fac}}_{m,f} = \text{NewOptionalFixedSizeIntervalVar}(\text{start}_m, p_m, y^{\text{fac}}_{m,f})$.
  - **Room intervals** (when `room_required`): reuses fixed $I_m$ if $|\mathcal{R}_m| = 1$; otherwise creates $I^{\text{room}}_{m,r} = \text{NewOptionalFixedSizeIntervalVar}(\text{start}_m, p_m, y^{\text{room}}_{m,r})$.
  - **Fixed blocking intervals**: Recurring faculty/room calendar blocks and existing commitments are added once per resource as `NewFixedSizeIntervalVar(w \cdot 3000 + a_b, b_b - a_b)` inside the faculty or room interval bucket, enforcing blocked-window exclusions natively via `AddNoOverlap` rather than enumerating `(slot, resource)` exclusion clauses.
- **Deduplicated load-group indicator and faculty load**:
  - $z_{f,o,g}$ is resolved per `(f, o, g)` triple: constant `1` if a meeting in $\mathcal{M}_{f,o,g}$ has $|\mathcal{F}_m| = 1$, the shared Boolean itself if $|\{y^{\text{fac}}_{m,f}\}| = 1$, or an auxiliary `BoolVar` `load_group_{f}_{o}_{g}` linked across distinct selectors.
  - $L_f \in \mathbb{Z}_{\ge 0}$ for each $f \in \mathcal{F}$ (fixed constant `0` if faculty $f$ appears in zero $\mathcal{F}_m$).

---

## 5. CP-SAT Hard Constraint Formulation (`CpModel`)

Given `FactoredModelState` constructed in `_build_factored_hard_model`, one hard-constraint `CpModel` governs both Phase 1 feasibility search and every stage of Phase 2 lexicographic optimization:

### (5.1) Complete Exact Domain Coverage

Every meeting $m \in \mathcal{M}_{\text{meet}}$ must take a valid start coordinate (`start_m` $\in \{\tau(t) : t \in \mathcal{S}_m\}$), **exactly one** eligible faculty whenever $|\mathcal{F}_m| > 1$, and **exactly one** eligible physical room whenever `room_required = true` and $|\mathcal{R}_m| > 1$:

$$
\begin{aligned}
\sum_{f \in \mathcal{F}_m} y^{\text{fac}}_{m,f} &= 1 && \forall m \in \mathcal{M}_{\text{meet}} : |\mathcal{F}_m| > 1, \\
\sum_{r \in \mathcal{R}_m} y^{\text{room}}_{m,r} &= 1 && \forall m \in \mathcal{M}_{\text{meet}} : \text{room\_req}_m \land |\mathcal{R}_m| > 1, \\
\sum_{t \in \mathcal{S}_m} x_{m,t} &= 1 && \forall m \in \mathcal{M}_{\text{meet}} \text{ when } \text{option\_count} \le 1000.
\end{aligned}
\tag{C1}
$$

When a meeting selects faculty $f$ or room $r$ while $t \in \mathcal{B}^{\text{fac}}_{m,f}$ or $t \in \mathcal{B}^{\text{room}}_{m,r}$, fixed blocking intervals added into the resource's `AddNoOverlap` timeline forbid simultaneous placement at $\tau(t)$.

### (5.2) Unified Week-Timeline Cohort, Faculty, and Room Non-Overlap (`_build_factored_hard_model`)

Because weekday coordinates are spaced by $\Delta_{\text{stride}} = 3000$ minutes, one `AddNoOverlap` constraint per resource across the entire week enforces temporal disjointness (`end_1 <= start_2` or `end_2 <= start_1`) simultaneously across all days, including fixed calendar blocks and existing commitments on faculty and rooms:

$$
\begin{aligned}
\text{AddNoOverlap}\bigl(\{ I_m : h \in \mathcal{H}(m) \}\bigr) & \qquad \forall h \in \mathcal{H}, \tag{C2a}\\
\text{AddNoOverlap}\bigl(\{ I^{\text{fac}}_{m,f} : f \in \mathcal{F}_m \} \cup \mathcal{I}^{\text{block}}_f\bigr) & \qquad \forall f \in \mathcal{F}, \tag{C2b}\\
\text{AddNoOverlap}\bigl(\{ I^{\text{room}}_{m,r} : r \in \mathcal{R}_m \land \text{room\_req}_m \} \cup \mathcal{I}^{\text{block}}_r\bigr) & \qquad \forall r \in \mathcal{R}. \tag{C2c}
\end{aligned}
$$

Fixed cohort intervals $I_m$ are appended to every $h \in \mathcal{H}(m)$, so merged classes automatically block overlap across all attached cohorts, and multi-meeting units $(d, q), (d, q')$ sharing $\mathcal{H}(d)$ cannot collide.

### (5.3) Cross-Modality Cohort Transition Buffer (`_add_factored_modality_transition_buffer`)

Whenever a cohort $h \in \mathcal{H}$ participates in meetings spanning at least two distinct modalities ($|\{\text{mod}(m) : h \in \mathcal{H}(m)\}| \ge 2$), `_add_factored_modality_transition_buffer` avoids quadratic slot-pair enumeration by creating one buffered fixed-size interval of length $p_m + B_{\text{mod}}$ ($B_{\text{mod}} = 30$) per meeting in that cohort:

$$
I^{\text{buf}}_m = \text{NewFixedSizeIntervalVar}\bigl(\text{start}_m,\; p_m + 30\bigr),
$$

and adding two-interval `AddNoOverlap` across every opposing-modality pair $(m_1, m_2)$ from distinct scheduling demands (`d(m_1) != d(m_2)`):

$$
\text{AddNoOverlap}\bigl([I^{\text{buf}}_{m_1},\; I^{\text{buf}}_{m_2}]\bigr) \qquad \forall h \in \mathcal{H},\; \forall \{m_1, m_2\} \text{ with } h \in \mathcal{H}(m_1) \cap \mathcal{H}(m_2),\; \text{mod}(m_1) \ne \text{mod}(m_2),\; d(m_1) \ne d(m_2).
\tag{C3}
$$

Because $\Delta_{\text{stride}} = 3000$, adding $+30$ minutes to $p_m$ never bleeds into day $w + 1$, while preventing any adjacent gap $< 30$ minutes on the same day. Same-modality meetings remain permitted back-to-back with zero gap.

### (5.4) Structural Same-Faculty Linkage & Distinct-Day Spread (`_add_factored_distinct_day_spread`)

Same-faculty linkage across $(o, g)$ groups with `same_faculty_required = true` is **structural** rather than equationally duplicated:
- `_prepare_meetings` intersects $\mathcal{F}_m$ across all meetings in group $(o, g)$, and
- `_build_factored_hard_model` aliases $y^{\text{fac}}_{m,f} \equiv \text{fac\_grp}_{o,g,f}$ for every meeting $m$ in that group, guaranteeing identical faculty choice across both multi-meeting patterns (`2x90`, `3x60`) and linked course components (`LECTURE` + `LAB`):

$$
y^{\text{fac}}_{m, f} \equiv \text{fac\_grp}_{o, g, f} \qquad \forall m \in \mathcal{M}^{\text{same}}_{o,g},\; \forall f \in \mathcal{F}_{o,g}.
\tag{C4a}
$$

In addition, for every multi-meeting demand $d$ ($|\mathcal{M}_d| > 1$) whose union of admissible days satisfies $|\bigcup_{m \in \mathcal{M}_d} \{w(t) : t \in \mathcal{S}_m\}| \ge |\mathcal{M}_d|$, `_add_factored_distinct_day_spread` enforces weekday separation:

$$
\text{AddAllDifferent}\bigl([\text{day}_m : m \in \mathcal{M}_d]\bigr).
\tag{C4b}
$$

### (5.5) Deduplicated Faculty Load Accounting and Term Cap (`_add_factored_faculty_load_constraints`)

Academic load for faculty $f$ is counted **once per active `(faculty_id, term_offering_id, section_delivery_group_id)` assignment**, not multiplied by `meeting_count` or linked course components. Let $V_{f,o,g}$ be the deduplicated set of active selection variables (`y^{\text{fac}}_{m,f}`) in $\mathcal{M}_{f,o,g}$:
- if any $m \in \mathcal{M}_{f,o,g}$ has $|\mathcal{F}_m| = 1$, then $z_{f,o,g} = 1$ (fixed constant),
- if $|V_{f,o,g}| = 1$, then $z_{f,o,g} \equiv v$ (zero extra variable),
- otherwise $z_{f,o,g} \in \{0, 1\}$ satisfies:

$$
v \le z_{f,o,g} \quad \forall v \in V_{f,o,g}, \qquad z_{f,o,g} \le \sum_{v \in V_{f,o,g}} v,
\tag{C5a}
$$

with group unit weight $\tilde{\lambda}_{f,o,g} = \max_{m \in \mathcal{M}_{f,o,g}} \lambda_m$ and per-faculty Term total:

$$
L_f = \sum_{(o, g)} \tilde{\lambda}_{f,o,g}\, z_{f,o,g} \le \Lambda_f \qquad \forall f \in \mathcal{F}_{\text{active}},
\tag{C5b}
$$

and $L_f = 0$ for every $f \in \mathcal{F} \setminus \mathcal{F}_{\text{active}}$.

### (5.6) Repair Requested-Meeting Pinned Selection (`_apply_repair_hard_requirement`)

When `operation.kind == "repair"`, after confirming `source_candidate.assignments` covers exact key set $\mathcal{U}$ (`invalid_repair_coverage` otherwise) and target unit $u^* = (d^*, q^*) \in \mathcal{U}$ exists (`invalid_repair_request`), `_matching_assignment_spec` resolves the matching slot index $s^*$ in $\mathcal{S}_{m^*}$, faculty $f^*$, and room $r^*$ matching `requested_assignment`. If no admissible match exists, the solver returns `model_invalid` (`repair_request_unavailable`); otherwise:

$$
\begin{aligned}
x_{m^*, s^*} = 1 \quad (\text{if } \texttt{slot\_vars}\text{ active}) \quad\text{or}\quad \text{start}_{m^*} &= \tau(t_{s^*}), \\
y^{\text{fac}}_{m^*, f^*} = 1 \quad (\text{if } |\mathcal{F}_{m^*}| > 1), \qquad
y^{\text{room}}_{m^*, r^*} &= 1 \quad (\text{if } |\mathcal{R}_{m^*}| > 1).
\end{aligned}
\tag{C6}
$$

---

## 6. Six-Level Lexicographic Objective (`lexicographic_v1`) and Optimization Protocol

TALA replaces ad-hoc weighted sums with the fixed priority hierarchy `lexicographic_v1` (with an optional repair prefix at Priority `0`). In CP-SAT, every level $k$ defines a maximization expression $J_k$ stored as a signed integer value (`-value` for minimized penalties, positive for maximized early placement), while reporting human-readable non-negative counts/minutes/units/seats in `objective_details["values"]`. All six levels operate directly on `FactoredModelState` variables (`slot_vars` when $\text{option\_count} \le 1000$, or reified start/day/minute indicators otherwise).

### 6.1 Objective Terms (`_lexicographic_objectives`)

#### Level 0 (Repair Operations Only): `changed_non_requested_meetings`
Minimize non-requested assignment mutations relative to the immutable `source_candidate` baseline (`_repair_change_objective`). For each meeting $m \ne m^*$, let `matches_baseline_m` $\in \{0, 1\}$ denote conjunction of matching baseline slot (`slot_vars` or reified `start_m == ws_base`), matching faculty `fac_vars`, and matching room `room_vars`, and let $\text{chg}_m = 1 - \text{matches\_baseline}_m$:

$$
J_0 = -\sum_{m \in \mathcal{M}_{\text{meet}} \setminus \{m^*\}} \text{chg}_m \qquad \text{(maximized; non-requested mutations minimized).}
\tag{O0}
$$

#### Level 1: `cohort_mode_switches`
Minimize transitions between consecutive selected delivery modalities on each cohort-day $(h, w)$ having $\ge 2$ modalities and $\ge 2$ distinct start minutes (`_cohort_mode_switch_objective_terms`):

1. For each meeting $m$ and admissible start minute $s$ on $(h, w)$ with $\tau = w \cdot 3000 + s$, let placement indicator $\text{at}_{m,w,s} \in \{0, 1\}$ equal `slot_vars[m][s_idx]` when `slot_vars` are active, or a reified `BoolVar` enforcing $\text{at}_{m,w,s} = 1 \iff \text{start}_m = \tau$.
2. Assign deterministic integer codes $\mu(\text{mod}) \in \{1, \dots, |\mathcal{M}_{\text{mod}}|\}$ to sorted modality strings (`0` denoting no meeting starting).
3. For ordered distinct start minutes $s^{(0)} < s^{(1)} < \dots < s^{(M-1)}$ on $(h, w)$ with eligible meetings $\mathcal{K}_{h,w,i}$:
   $$
   \text{starts}_{h,w,i} = \sum_{m \in \mathcal{K}_{h,w,i}} \text{at}_{m,w,s^{(i)}} \in \{0, 1\}, \qquad
   \text{curr}_{h,w,i} = \sum_{m \in \mathcal{K}_{h,w,i}} \mu(\text{mod}(m))\, \text{at}_{m,w,s^{(i)}},
   $$
   and carried latest active mode ($\text{last}_{h,w,0} = \text{curr}_{h,w,0}$):
   $$
   \text{last}_{h,w,i} =
   \begin{cases}
   \text{curr}_{h,w,i} & \text{if } \text{starts}_{h,w,i} = 1,\\
   \text{last}_{h,w,i-1} & \text{if } \text{starts}_{h,w,i} = 0.
   \end{cases}
   $$
4. For $i \ge 1$, switch Boolean $\text{sw}_{h,w,i} \in \{0, 1\}$ satisfies:
   $$
   \text{sw}_{h,w,i} = 1 \iff \bigl(\text{starts}_{h,w,i} = 1\bigr) \land \bigl(\text{last}_{h,w,i-1} \ne 0\bigr) \land \bigl(\text{curr}_{h,w,i} \ne \text{last}_{h,w,i-1}\bigr).
   $$

CP-SAT maximizes:

$$
J_1 = -\sum_{(h, w)} \sum_{i = 1}^{M_{h,w}-1} \text{sw}_{h,w,i}.
\tag{O1}
$$

#### Level 2: `cohort_idle_time`
Minimize total student-cohort idle minutes between first class start and last class end on each cohort-day $(h, w)$ with at least $2$ candidate meetings (`_cohort_idle_gap_objective_terms`). For each such meeting $m$ on day $w$, `_on_day_bool` builds $o_{m,w} \in \{0, 1\}$ (`sum(slot_vars)` on day $w$ or reified $\text{day}_m = w$) and within-day start minute $s^{\text{day}}_m$ (`sum(s_t * slot_var)` or $\text{min}_m$). With horizon bounds $H^{\min}_{h,w}$ and $H^{\max}_{h,w}$:

$$
\begin{aligned}
\text{active}_{h,w} &= \max_{m \in \mathcal{M}_{h,w}} o_{m,w}, \\
\tilde{s}_{m,w} &= \begin{cases} s^{\text{day}}_m & o_{m,w} = 1,\\ H^{\max}_{h,w} & o_{m,w} = 0, \end{cases} \qquad
\tilde{e}_{m,w} = \begin{cases} s^{\text{day}}_m + p_m & o_{m,w} = 1,\\ H^{\min}_{h,w} & o_{m,w} = 0, \end{cases} \\
S^{\min}_{h,w} &= \min_{m \in \mathcal{M}_{h,w}} \tilde{s}_{m,w}, \qquad E^{\max}_{h,w} = \max_{m \in \mathcal{M}_{h,w}} \tilde{e}_{m,w}, \\
\text{idle}_{h,w} &= \begin{cases} E^{\max}_{h,w} - S^{\min}_{h,w} - \sum_{m \in \mathcal{M}_{h,w}} p_m\, o_{m,w} & \text{if } \text{active}_{h,w} = 1, \\ 0 & \text{if } \text{active}_{h,w} = 0. \end{cases}
\end{aligned}
$$

CP-SAT maximizes:

$$
J_2 = -\sum_{(h, w): |\mathcal{M}_{h,w}| \ge 2} \text{idle}_{h,w}.
\tag{O2}
$$

#### Level 3: `faculty_load_imbalance`
Minimize pairwise scaled load inequality across **all** snapshot faculty $f \in \mathcal{F}$ (including zero-load unused faculty), via `_load_balance_objective_terms` using `AddAbsEquality`:

$$
\Delta_{f, f'} = |L_f - L_{f'}| \quad \forall f, f' \in \mathcal{F},\; f < f', \qquad
J_3 = -\sum_{f < f'} \Delta_{f, f'}.
\tag{O3}
$$

#### Level 4: `faculty_idle_time`
Minimize total idle minutes across active faculty teaching days $(f, w)$ with $\ge 2$ eligible meetings (`_faculty_idle_gap_objective_terms`), defining per-meeting faculty-day indicator $a_{m,f,w} = o_{m,w} \land y^{\text{fac}}_{m,f}$ (`on_day` directly when $|\mathcal{F}_m| = 1$) and applying the identical active-span-minus-duration encoding as Level 2:

$$
J_4 = -\sum_{(f, w): |\mathcal{M}_{f,w}| \ge 2} \text{idle}^{\text{fac}}_{f,w}.
\tag{O4}
$$

#### Level 5: `room_seat_waste`
Minimize unused seat capacity across roomed meetings (`_room_efficiency_objective_terms`, `_room_efficiency_value`):

$$
\sigma_{\text{room}}(m, r) =
\begin{cases}
-\max\bigl(0,\; \kappa_r - q_m\bigr) & \text{if } \text{room\_req}_m \land r \in \mathcal{R}_m \text{ and } q_m > 0, \\
0 & \text{otherwise},
\end{cases}
\qquad
J_5 = \sum_{m: \text{room\_req}_m} \begin{cases} \sigma_{\text{room}}(m, r_m) & |\mathcal{R}_m| = 1, \\ \sum_{r \in \mathcal{R}_m} \sigma_{\text{room}}(m, r)\, y^{\text{room}}_{m,r} & |\mathcal{R}_m| > 1. \end{cases}
\tag{O5}
$$

*(Legacy `tal94-demand-v2` rows lacking positive `expected_count` fall back to `max(0, 1000 - capacity)`.)* Reported `objective_details["values"]["room_seat_waste"]` equals $-J_5 \ge 0$.

#### Level 6: `stable_earlier_placement`
Break remaining ties toward earlier weekday numbers and earlier start minutes (`_earlier_placement_objective_terms`, `_slot_earlier_score`):

$$
\sigma_{\text{time}}(w, s) = \max\bigl(0,\; 10000 - (1000\, w + s)\bigr).
$$

When `slot_vars` are active ($\text{option\_count} \le 1000$), $J_6 = \sum_{m, t \in \mathcal{S}_m} \sigma_{\text{time}}(w(t), s_t)\, x_{m,t}$. Otherwise, for large instances where `slot_vars` are omitted, each meeting uses $10000 - (1000\,\text{day}_m + \text{min}_m)$ directly when $\max_{t \in \mathcal{S}_m}(1000\,w(t) + s_t) \le 10000$, or `AddMaxEquality(clamped_m, [0, 10000 - (1000 * day_m + min_m)])` otherwise:

$$
J_6 = \sum_{m \in \mathcal{M}_{\text{meet}}} \max\bigl(0,\; 10000 - (1000\,\text{day}_m + \text{min}_m)\bigr).
\tag{O6}
$$

*(Diagnostic note: each output assignment's `scores["earlier_time_weight"]` reports `max(1, 2000 - (day * 100 + starts_minute // 10) - priority)` strictly as per-row metadata; CP-SAT Level 6 optimizes $J_6$ in `(O6)`.)*

### 6.2 Sequential Fixing & P2 Large-Problem Optimization Protocol (`_solve_lexicographic`)

Given Phase 1 hard-feasible incumbent from `FactoredModelState`:

1. **Hinting (`_apply_solver_hints`)**: Warm-starts `start_vars`, `day_vars`, `min_vars`, `slot_vars`, `fac_vars`, `room_vars`, and reified objective booleans (`extra_bool_hints`) from the Phase 1 solution.
2. **Large-Problem Controls (`len(scheduling_demands) >= STAGE2_LARGE_DEMAND_THRESHOLD = 20`)**:
   - **Per-level time cap**: $\Delta t_k = \min\bigl(\text{remaining} / (L - k),\; 15.0\bigr)$ seconds when large (`STAGE2_LEVEL_MAX_SECONDS = 15.0`), otherwise $\max(0.001, \text{remaining} / (L - k))$.
   - **Relative optimality gap**: `solver.parameters.relative_gap_limit = 0.01` (`STAGE2_LARGE_RELATIVE_GAP_LIMIT = 0.01`) when large, avoiding long tail proofs on near-optimal lexicographic bounds.
   - **Stagnation early-stop watchdog (`_Stage2EarlyStopCallback`)**: Once an improving feasible incumbent is found on level $k$, a lightweight polling watchdog checks every `0.25s` whether wall-clock time without objective improvement has reached `STAGE2_STAGNATION_SECONDS = 2.5`s, halting level $k$ early (`stop_search()`) once search plateaus.
3. **Level Iteration & Floor Continuation** ($k = 0, \dots, L - 1$, where $L = 6$ for generation, $L = 7$ for repair):
   - Solves `model.maximize(expression_k)`.
   - **If `status == OPTIMAL`**: updates incumbent, appends `{"name": name_k, "status": "optimal", "value": v_k}`, adds permanent equality `model.add(expression_k == v_k)`, and advances to $k + 1$.
   - **If `status == FEASIBLE`** (deadline, `1%` gap limit, or `2.5s` stagnation stop): updates incumbent, appends `{"name": name_k, "status": "feasible", "value": v_k}`, records `any_non_optimal = True` with warning `lexicographic_level_feasible`, **adds lower-bound floor constraint `model.add(expression_k >= v_k)` and continues to level $k + 1$** so remaining lower-priority objectives can still improve without degrading level $k$.
   - **If budget expires before `solve()` or status $\notin \{\text{OPTIMAL}, \text{FEASIBLE}\}$**: preserves the last valid incumbent, records `optimization_budget_exhausted` or `optimization_limit_reached`, and returns `solver_status = "feasible"`.
4. **Final Status & Scalar Score**: Returns `solver_status = "optimal"` **only if every level in the hierarchy proved `OPTIMAL`** (`not any_non_optimal`); otherwise returns `solver_status = "feasible"`. Both `objective_score` and `objective_details["scalar_score"]` are always `None` under `lexicographic_v1`.

---

## 7. Single Coordinated Deadline Chain and Outcome Semantics

### 7.1 Nested Timeouts and Queue Alignment

One wall-clock budget starts at entry to Flask `POST /solve` (`SolverRequestContext`). Every phase (parsing, normalization, enumeration, model construction, objective construction, feasibility search, each lexicographic solve, result serialization) shares the same clock while reserving `15`s for typed error/response delivery:

| Boundary Layer | Limit | Configuration Source |
| :--- | ---: | :--- |
| Python total request budget | `300` s (clamped `[1, 300]`) | `SOLVER_REQUEST_BUDGET_SECONDS`, `server.py`, `runtime.py` |
| Python response reserve | `15` s (`285` s build/search cap) | `server.py:42`, `runtime.py` |
| Gunicorn worker timeout | `330` s (`1` worker, `2` threads) | `cloud/scheduler-solver/Dockerfile` |
| Laravel HTTP client timeout | `330` s (`10` s connect timeout) | `config/tala_integrations.php` (`CloudRunSchedulingSolverClient`) |
| Cloud Run HTTP request limit | `360` s (`concurrency = 1`, `max = 2`) | Architecture Specification §9.1 |
| Laravel queue job timeout | `360` s (`$tries = 3`, backoff `[60, 300]`) | `ScheduleSolverDispatchJob` |
| Database queue `retry_after` | `420` s | `config/queue.php` |

When search/serialization hits the reserve (`RequestBudgetExceeded`), `server.py` returns **HTTP `503`** (`solver_request_budget_exhausted`), classified by Laravel (`CloudRunSchedulingSolverClient`) as a retryable technical failure—never persisted as schedule conflicts.

### 7.2 OR-Tools `9.15` Status Mapping vs. Technical Failures

| Outcome Condition | HTTP / JSON `solver_status` | Persisted Result / Recovery Behavior | Mathematical Meaning |
| :--- | :--- | :--- | :--- |
| All levels prove `OPTIMAL` | HTTP `200`, `"optimal"` | Validated $\to$ `StatusUnderReview` (eligible for publication). | Globally optimal over `(C1)–(C6)` in exact `(O0)..(O6)` order. |
| Feasibility found, any level unproved `FEASIBLE` or budget stopped in Phase 2 | HTTP `200`, `"feasible"` | Validated $\to$ `StatusUnderReview` with explicit level warning (`lexicographic_level_feasible` / `optimization_budget_exhausted`). | **All `(C1)–(C6)` hard rules hold**; remaining objective levels remain unproved. |
| Empty candidate check or Phase 1 proves `INFEASIBLE` | HTTP `200`, `"infeasible"` | Conflict rows recorded; `StatusBlocked` (no candidate timetable). | Proof (within snapshot model) that no hard-valid timetable exists. |
| Schema/contract/cohort/load/pattern/repair error or CP-SAT `MODEL_INVALID` | HTTP `200`, `"model_invalid"` | Diagnostic error recorded; no false candidate timetable. | Malformed or inconsistent model inputs. |
| Phase 1 `UNKNOWN` before any hard-valid incumbent | HTTP `200`, `"unknown"` (`timeout=True`) | Search limit reached with zero false conflict rows. | Conclusive feasibility/infeasibility unproved within budget. |
| Hash mismatch / bad JSON | HTTP `400` (`json_required`, `invalid_json`, `snapshot_hash_mismatch`) | Non-retryable client integration error (`StatusFailed` / dispatch failure). | Wire contract violation. |
| Budget exhausted before response / Gunicorn / Transport / HTTP `5xx` | HTTP `503` (`solver_request_budget_exhausted`) or transport error | **Technical failure** (`ScheduleSolverDispatchJob` retry up to 3 attempts; `ScheduleSolverRecoveryService` / `ScheduleSolverRetryService`). | Infrastructure/time limit; makes zero claim about Term feasibility. |

---

## 8. Independent Laravel Validation, Review, Repair, and Publication Boundaries

A CP-SAT `"optimal"` or `"feasible"` response never writes official timetable rows directly:

1. **Single-Ingestion Guard & Independent Replay (`ScheduleCloudResultIngestor`, `ScheduleAssignmentValidationService`)**:
   Inside a database lock on the `Term` and `ScheduleGenerationRun`, Laravel verifies exact-Term run eligibility (preventing duplicate ingestion or stale repair targets), replays every returned `(d, q)` assignment against the frozen `input_snapshot` (verifying coverage, qualifications, loads, room suitability/capacity/features, cohort/faculty/room non-overlap, modality transition gap, recurring calendar blocks, and same-faculty constraints), and rejects non-passing results to `StatusBlocked` without persisting `CandidateScheduleRow` records.
2. **Live-Source Revalidation (`ScheduleAssignmentRevalidationService`)**:
   Both at ingestion (`tala-timetable-v2`), upon manual candidate edits, and immediately before publication, Laravel checks candidate rows against **current live Term records** (updated enrollments, faculty qualifications/loads, room availability, active official `SectionMeeting` overlaps across `liveConflictFindings` and enrolled student collisions). Stale candidates are blocked from publication until repaired or regenerated.
3. **Human Adjustment vs. Solver Repair (`AdjustCandidateMeeting` vs. `RequestTimetableRepair`)**:
   - `AdjustCandidateMeeting` lets an authorized Registrar adjust one candidate row directly and revalidates the whole candidate set synchronously via `validateCandidateSet` without calling Cloud Run, replicating the `ScheduleGenerationRun` cleanly as a successor candidate.
   - `RequestTimetableRepair` queues a new `tala-timetable-v2` snapshot in `"repair"` mode so CP-SAT resolves cascading resource conflicts around the pinned change.
4. **Publication (`SchedulePublishService`, `PublishedScheduleRevisionService`)**:
   Requires an authorized Registrar, passing revalidation, an explicit publication note, and (when `isQualityLoweringSuccessor` triggers relative to the source/previously published run) an explicit quality-regression explanation (`publication_note`), then atomically publishes official `SectionMeeting` rows via `PublishTimetableVersion`.

---

## 9. Verified Authority-vs-Implementation Discrepancies and Modeling Limitations

Reconciling `PRD 03`, `Architecture Specification`, Python `solver.py`, and Laravel scheduling services reveals seven factual discrepancies reported directly below without altering production behavior:

1. **Outdated Live Cloud Run Deployment vs. Repository `main`**: Architecture Specification §9.1 records the August 19, 2026 Issue `#22` activation (`tala-scheduler-solver-i22-3f461a46`). Subsequent `main` changes—most visibly commit `4e41564e` (including unassigned snapshot faculty in Level 3 `faculty_load_imbalance`)—mean the live Cloud Run service is outdated relative to repository `main`, and no post-`4e41564e` Cloud Run benchmark has been executed.
2. **Preparation Limits (`PRD 03 §11.1` vs. `PRD 03 §9.1` & Code)**: `PRD 03 §11.1` mentions "applicable preparation limits" in its hard-constraint summary, whereas `PRD 03 §9.1` clarifies that institutional scheduling policy imposes no numeric cap on distinct subject preparations (displaying preparation count strictly as informational review context). Neither `ScheduleSolverSnapshotService`, `solver.py`, nor `ScheduleAssignmentValidationService` enforces a preparation limit constraint.
3. **`dated_exceptions` & `requires_consecutive_block` (`PRD 03 §11.1` vs. Code)**: `ScheduleSolverSnapshotService` includes a `dated_exceptions` array in the frozen JSON snapshot alongside recurring weekly `calendar_blocks`, whereas both `solver.py` (`_window_conflicts_calendar`) and `ScheduleAssignmentValidationService::calendarFindings()` inspect only `calendar_blocks` (weekly recurring rows where `effective_on IS NULL`), reflecting weekly template scheduling. Similarly, `requires_consecutive_block` is satisfied automatically because every `(d, q)` meeting uses a single contiguous interval (`NewFixedSizeIntervalVar` / `NewOptionalFixedSizeIntervalVar`).
4. **`same_faculty_required` on Single Multi-Meeting Demands (`solver.py` vs. `ScheduleAssignmentValidationService`)**: `_prepare_meetings` (`solver.py:2030–2076`) and `_build_factored_hard_model` (`solver.py:2495–2504`) share `fac_grp` booleans across all `(d, q)` units in `(term_offering_id, section_delivery_group_id)`—correctly enforcing uniform faculty across `2x90` / `3x60` meetings even of a single demand—whereas `ScheduleAssignmentValidationService::sameFacultyFindings` (`line 1461`) skips any group where `$groupDemands->count() < 2` (fewer than two distinct demand rows).
5. **`SchedulePublishService::isQualityLoweringSuccessor()` Sign on Level 6 (`SchedulePublishService.php:315–339`)**: Levels 1–5 (`cohort_mode_switches`, `cohort_idle_time`, `faculty_load_imbalance`, `faculty_idle_time`, `room_seat_waste`) are minimized (higher value = worse), whereas Level 6 (`stable_earlier_placement`) stores $\sum \max(0, 10000 - (1000\, w(c) + s_c))$ which is **maximized** (higher value = earlier placement, lower value = worse). `isQualityLoweringSuccessor()` applies `$comparison > 0` uniformly across all six keys in `QualityHierarchy`, treating an earlier-placed tie-break improvement as quality-lowering if levels 1–5 tie.
6. **`ScheduleAssignmentRevalidationService::liveConflictFindings()` Shared-Cohort Scalar Check (`ScheduleAssignmentRevalidationService.php:250–269`)**: Candidate-vs-candidate validation (`overlapFindings`) checks full intersection over `cohort_or_student_group_ids`, whereas `liveConflictFindings()` against already-active official `SectionMeeting`s compares scalar `cohort_or_student_group_id`.
7. **Retained Legacy `tal94-demand-v2` / `balanced_v1` Code Paths**: `solver.py` and `ScheduleAssignmentValidationService` maintain dual branches for `tal94-demand-v2` (`balanced_v1`) backward-compatibility tests alongside active `tala-timetable-v2` (`lexicographic_v1`).

**Structural Modeling Boundaries**: The CP-SAT formulation solves a static weekly Term template (`calendar_blocks` only, no date-specific holiday exceptions), restricts demands to $1$–$3$ equal-duration contiguous meetings within day slot bounds, charges faculty load identically once per `(faculty_id, offering_id, delivery_group_id)` group, and guarantees optimality only over the proved prefix of the six-level lexicographic hierarchy.

---

## 10. Worked Example (`cloud/scheduler-solver/samples/minimal_snapshot.json`)

To verify every equation against an executable repository fixture without hand-tuned numbers, consider [`cloud/scheduler-solver/samples/minimal_snapshot.json`](../../cloud/scheduler-solver/samples/minimal_snapshot.json):

### 10.1 Inputs (`tala-timetable-v2`, `lexicographic_v1`)

- **Term & Grid**: `term_id = 1`, `day_of_week = 1` (Monday), 4 slots of `30` min from `08:00:00` to `10:00:00`:
  - Slot `1`: `D1-0800` (`[480, 510)`), Slot `2`: `D1-0830` (`[510, 540)`), Slot `3`: `D1-0900` (`[540, 570)`), Slot `4`: `D1-0930` (`[570, 600)`).
  - Day boundary: $E^{\text{grid}}_1 = 600$ minutes (`10:00`).
- **Demands ($\mathcal{D} = \{5001, 5002\}$, both `1x60`, $K_d = 1$, $p_d = 60$, `FACE_TO_FACE`, $q_d = 30$, `load_units = "3.00"`, $\lambda_d = 300$)**:
  - Demand `5001` (`IT101`): offering `300`, delivery group `110`, cohort tuple $\mathcal{H}(5001) = (110,)$, eligible faculty $\mathcal{F}_{5001} = \{200\}$.
  - Demand `5002` (`IT102`): offering `301`, delivery group `110`, cohort tuple $\mathcal{H}(5002) = (110,)$, eligible faculty $\mathcal{F}_{5002} = \{201\}$.
- **Resources**: Room `301` (`LECTURE_ROOM`, capacity $\kappa_{301} = 40 \ge 30$, features $\varnothing$). Faculty `200`, `201` ($\Lambda_{200} = \Lambda_{201} = 2400$). Empty `calendar_blocks`, empty `faculty_availability`.

### 10.2 Factored Variables and Hard Constraints

- **P1 Pre-Solve Feasibility Guards (`_presolve_feasibility_guards`)**: Each faculty has `300` scaled load ($\le 2400$) and `60` exclusive minutes ($\le 120$ open); room `301` has `120` demanded minutes ($\le 120$ open); cohort `110` has `120` demanded minutes ($\le 120$ open). All guards pass.
- **Time Admissibility & Factored Domains**: With $p_m = 60$ and $E^{\text{grid}}_1 = 600$, slot `D1-0930` yields $570 + 60 = 630 > 600$ and is excluded. Three Monday start windows survive per meeting (`08:00`, `08:30`, `09:00`), corresponding to $\text{start}_m \in \{3480, 3510, 3540\}$ ($\Delta_{\text{stride}} = 3000$). Because each meeting has singleton faculty (`{200}` and `{201}`) and singleton room (`{301}`), $|\mathcal{F}_m| = |\mathcal{R}_m| = 1$ requires **zero** faculty or room Boolean variables (`fac_vars[m] = {}`, `room_vars[m] = {}`). Total `option_count` is $3 + 3 = 6 \le 1000$, so `slot_vars` allocates $x_{0,0}, x_{0,1}, x_{0,2}$ and $x_{1,0}, x_{1,1}, x_{1,2}$.
- **Exact Coverage `(C1)`**: $x_{0,0} + x_{0,1} + x_{0,2} = 1$ and $x_{1,0} + x_{1,1} + x_{1,2} = 1$.
- **Non-Overlap `(C2a)` & `(C2c)`**: Both fixed intervals $I_0 = \text{NewFixedSizeIntervalVar}(\text{start}_0, 60)$ and $I_1 = \text{NewFixedSizeIntervalVar}(\text{start}_1, 60)$ belong to cohort `110` and room `301`, enforced via `AddNoOverlap([I_0, I_1])`. Placing one at `3480` (`[480, 540)`) restricts the other to `3540` (`[540, 600)`), eliminating `3510` (`[510, 570)`).
- **Same-Modality Buffer `(C3)`**: Both meetings are `FACE_TO_FACE`, so `_add_factored_modality_transition_buffer` exits immediately with zero buffered intervals; back-to-back `[480, 540)` + `[540, 600)` is valid.
- **Load Bounds `(C5)`**: $L_{200} = 300 \le 2400$, $L_{201} = 300 \le 2400$ (fixed constants via singleton faculty).

### 10.3 Exact Six-Level Solution Evaluation

Two symmetric hard-valid timetables exist: $(5001 \to 08:00,\; 5002 \to 09:00)$ and $(5001 \to 09:00,\; 5002 \to 08:00)$. Evaluating every `lexicographic_v1` level gives identical optimal values:

1. **Level 1 (`cohort_mode_switches`)**: Both meetings share `FACE_TO_FACE` $\implies J_1 = 0$, reported value `0`.
2. **Level 2 (`cohort_idle_time`)**: Span $[480, 600)$ minus $60 + 60 = 120$ min $= 0$ $\implies J_2 = 0$, reported value `0`.
3. **Level 3 (`faculty_load_imbalance`)**: Pairwise $|L_{200} - L_{201}| = |300 - 300| = 0 \implies J_3 = 0$, reported value `0`.
4. **Level 4 (`faculty_idle_time`)**: Each faculty has $1$ meeting on Monday ($|\mathcal{M}_{f,1}| = 1 < 2$) $\implies J_4 = 0$, reported value `0`.
5. **Level 5 (`room_seat_waste`)**: $(40 - 30) + (40 - 30) = 20$ seats $\implies J_5 = -20$, reported value `20`.
6. **Level 6 (`stable_earlier_placement`)**:
   $$
   \sigma_{\text{time}}(1, 480) = 10000 - (1000\cdot 1 + 480) = 8520, \qquad
   \sigma_{\text{time}}(1, 540) = 10000 - (1000\cdot 1 + 540) = 8460,
   $$
   giving $J_6 = 8520 + 8460 = 16980$, reported value `16980`.

Every stage completes with `OPTIMAL`, returning `solver_status = "optimal"`, `objective_score = None`, `objective_details["scalar_score"] = None`, and `objective_details["values"] = {"cohort_mode_switches": 0, "cohort_idle_time": 0, "faculty_load_imbalance": 0, "faculty_idle_time": 0, "room_seat_waste": 20, "stable_earlier_placement": 16980}`.

---

## 11. Historical controlled benchmark experiment and operating envelope

> **Historical Evidence Notice (July 2026, `tal94-demand-v2` / `balanced_v1`).** This section is preserved intact as dated empirical evidence from the July 2026 controlled Cloud Run evaluation of the superseded `tal94-demand-v2` / `balanced_v1` baseline (`b4f` and `d5dstage2` revisions). It predates the repository migration to `tala-timetable-v2` / `lexicographic_v1`, the August 19, 2026 Issue #22 8-vCPU / 16-GiB activation (`tala-scheduler-solver-i22-3f461a46`), and subsequent `main` commits (`4e41564e`). Neither these July 2026 runs nor the outdated live Cloud Run deployment reflect a fresh benchmark of current `main`.

### 11.1 Evaluation purpose and scale basis

The completed empirical evaluation was a **controlled benchmark experiment**. Defined workloads and measures provided a consistent basis for comparing Cloud Run profiles, while deliberate variation of resource settings, workload size, and search duration permitted observation of their effects. The evaluation was neither a survey of institutions, an algorithm competition, nor a predictive-accuracy test. Its results are retained historical evidence, not active product datasets or required executable machinery.

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
| Seeded scheduling inputs | 47 synthetic student profiles; 54 term offerings, course-specific sections, delivery groups, and Scheduling Demands; 9 synthetic faculty records reflecting the current reported headcount, with qualifications and 21-unit load limits; 6 synthetic rooms with types and capacities; course modality and room requirements; and the weekly scheduling grid | Exercises the complete curriculum-to-demand and demand-to-solver pipeline without using live personal or operational records. |
| Solver candidate output | One assignment per demand containing the subject and section identifiers, selected eligible faculty, weekday, start and end time, and room when required | Forms a complete proposed timetable. Laravel rejects missing, duplicate, overlapping, or otherwise invalid assignments. |
| Published application output | Validated candidate assignments copied into official meeting records after authorized review | Produces the normal user-facing timetable by subject and section, with faculty, day, time, and room information. |

For example, the current first-year Business Management cohort `DBM-1A` contains 10 students and 10 curriculum-derived course requirements. Each requirement becomes a term offering, course-specific section, course-specific delivery group with expected count 10, and Scheduling Demand. Those ten delivery groups share the logical cohort identity `DBM-1A`.

For BME05 (Retail Management), the seed supplies the course, cohort, duration, modality, eligible faculty qualification, room type, expected count, and permissible time grid. CP-SAT supplies the selected faculty, room, weekday, and start and end times. The same process applies to other subjects, producing a conventional timetable rather than an aggregate workload count.

The fixture assigns one active synthetic faculty qualification per distinct subject and uses a common weekly scheduling grid. It exercises complete assignment generation, faculty and room non-overlap, room suitability, cross-course logical-cohort conflicts, and faculty-load validation.

It does not reproduce the client's actual faculty roster, individual availability, room inventory, or alternative qualified-faculty pools. The benchmark establishes end-to-end capability for the disclosed client-shaped input, not every possible institutional constraint pattern.

Faculty headcount evidence is reconciled before any timetable claim. Let \(U\) be the sum of teaching units in a scenario and let \(M=21\) be the configured maximum units for one faculty member. The arithmetic lower bound is

\[
F_{\mathrm{lb}}=\left\lceil\frac{U}{M}\right\rceil.
\]

This equation answers only how many faculty would be required if load could be divided ideally. It does not prove that the lower-bound roster has the required qualifications or that its classes fit the weekly timetable. A deterministic acceptance calculation therefore assigns each workload only to a qualified synthetic faculty record while enforcing the same 21-unit ceiling.

| Scenario | Teaching units \(U\) | Arithmetic lower bound \(F_{\mathrm{lb}}\) | Client-reported faculty | Generated scheduling faculty | Maximum constructed load | Interpretation |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| MIN | 162 | 8 | 9 | 9 | 19 | The current reported roster size passes the bounded load-and-qualification construction. |
| MIDDLE | 240 | 12 | Not reported for this synthetic tier | 14 | 18 | Fourteen provide operating headroom; twelve is not presented as a proven minimum. |
| MAX | 532 | 26 | 14 | 26 | 21 | Fourteen can carry only \(14\times21=294\) units, so the constructed MAX workload uses a separate sufficient synthetic roster. |

“Generated scheduling faculty” means synthetic records created only for the acceptance scenario. It does not change the reported client headcount. “Maximum constructed load” is the largest faculty load in the deterministic assignment.

The manifest field `unassignable_workloads` lists any workload that cannot be assigned to a qualified synthetic faculty record without exceeding the 21-unit ceiling. An empty list means none failed this bounded check. Because the fixture contains no faculty-specific unavailability rows, the calculation assumes every synthetic faculty record may use the full Monday-to-Saturday operating grid.

A bounded result of `PASS` proves readiness only under the disclosed load, qualification, and availability assumptions. It is not a CP-SAT feasibility, optimality, or solution-quality result. The 26-faculty MAX roster is sufficient for this construction but is not claimed as a universal or mathematically proven minimum. Real availability restrictions may require a larger roster.

The client population evidence contains labels belonging to different concepts. `Freshman` describes a year level, whereas `Regular` is an academic-standing value. TALA's synthetic personas use the system's actual standing model instead of converting that mixed table directly.

Likewise, client modality headcounts describe students, while the implemented scheduler stores modality per subject offering. Applicant intake records the selected term and program but does not ask for a student-level scheduling modality. The fixture therefore uses `ONLINE` and `FACE_TO_FACE` offerings and does not create per-student modality records. This clarification changes no CP-SAT variable, equation, constraint, objective, fixture, or measured capacity result.

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

The next three tables show one complete first-year timetable produced by the production-baseline deployment. They are research-paper renderings of validated official meeting records, not screenshots of one universal application table.

The three first-year cohorts are shown in full so the reader can inspect the day, time, faculty, room, and modality pattern. The three second-year cohorts were also validated and are summarized by count above. “Not required” means an online meeting consumes no physical room. Every row is one official meeting after Laravel validation, and no two rows within the same cohort overlap.

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

The Registrar projection contained all 54 published meetings because the Registrar reviews the institutional master schedule. Each Faculty projection contained only meetings assigned to that authenticated faculty account. The 12 Faculty projections in this historical pre-reconciliation run collectively contained the same 54 assignments.

Each Student projection contained only active official meetings bound to that student's enrollments. Representative Student projections therefore contained 10 meetings for `DTBM-1A`, 8 for `DIT-1A`, and 10 for `DTHM-1A`, rather than all 54 meetings or the other cohorts' tables. These cross-role counts show that the candidate was both mathematically valid and consumable by the role-specific application views.

The later faculty-evidence correction changed the former MIN research fixture to nine faculty. That executable fixture is now retired. The correction does not rewrite this recorded 12-faculty Cloud result and claims no replacement solver result.

Delivery modality is an academic property of the applicable term offering or delivery group. It is not an individual student's solver preference. The Student schedule therefore shows the student's complete set of bound meetings and labels each row's modality.

An online meeting still displays its subject, faculty, day, and time but consumes no physical room. A face-to-face meeting displays its assigned room. A student whose bound offerings are all online naturally sees an all-online schedule, but one student cannot convert an otherwise mixed cohort schedule into a personal online-only timetable.

The historical replacement solve used 156 half-hour grid units: Monday through Saturday, 07:00–20:00, gives \(6\times13\times2=156\). The current acceptance fixtures extend the configured day to 21:00, giving \(6\times14\times2=168\) half-hour grid units. This reconciliation does not rerun or relabel the historical Cloud result. “Slot” describes the time grid, not a room, class, or independently split meeting.

#### Benchmark design and instance composition

Scheduling research commonly identifies every test instance by the number and type of entities it contains. The International Timetabling Competition 2019 publishes course, class, room, student, and constraint counts for each instance and accepts only schedules satisfying every hard constraint.

PyJobShop reports minimum, average, and maximum task and resource counts across benchmark collections, then compares objective quality, optimality gap, and runtime under fixed controls. Han and Wang list the courses and teachers in each small and large instance, repeat each instance ten times, and report fitness and resource utilization.

TALA follows that reporting pattern for its implemented model. It does not copy those datasets or compare their algorithms with CP-SAT.

TALA derives its tiers from the client-aligned workload instead of treating an external institution's population as equivalent:

- **Reduced technical** verifies deterministic smaller-model construction; it is not a minimum institution size.
- **Client-representative** is the unscaled baseline.
- **Proportional 2×** and **Proportional 4×** create two and four identifier-remapped copies of the baseline demand and resource structure.
- **Contention 2×** doubles the demand structure while retaining baseline faculty and rooms to isolate resource pressure.

All tiers retain the same weekly time grid.

| Tier | Subjects | Offerings | Course-specific delivery groups | Logical cohorts | Demands | Faculty | Rooms | Experimental purpose |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| Reduced technical | 18 | 27 | 27 | — | 27 | 6 | 3 | Verify the harness with a coherent smaller model; the corrected replacement benchmark did not report a final Cloud result or logical-cohort count for this tier. |
| Client-representative | 40 | 54 | 54 | 6 | 54 | 12 | 6 | Historical Cloud benchmark input; validate the then-current client-scale workload and compare production resource profiles. |
| Proportional 2× | 80 | 108 | 108 | 12 | 108 | 24 | 12 | Evaluate proportional growth in work and resources. |
| Contention 2× | 80 | 108 | 108 | 12 | 108 | 12 | 6 | Diagnose demand growth without proportional faculty and room growth. |
| Proportional 4× | 160 | 216 | 216 | 24 | 216 | 48 | 24 | Explore an upper computational boundary; no supported maximum is claimed. |

The client has six logical attendance cohorts. The baseline contains 54 course-specific sections and delivery groups because each cohort-course requirement receives its own offering, section, delivery-group record, and demand. Forty distinct subjects produce 54 offerings because some subjects occur in more than one cohort.

The proportional labels describe copied scheduling structures, not doubled or quadrupled student populations. The 12-faculty value in this historical table is intentionally retained. It is not the current nine-Faculty Canonical TALA Scheduling Dataset or a claim about the reconciled roster.

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

Temporary database rows and active benchmark/replay machinery were deliberately retired after the experiment. Reproducibility evidence remains in these disclosed tables and limitations, archived sanitized artifacts, immutable revision and image identifiers, Issue history, and Git history. The current codebase does not retain commands, seeders, scenario selectors, or services solely to regenerate completed research.

Any future research rerun is new approved experimental work. It must restore or build a bounded harness from the archived method, pin the exact image and profile, isolate the test database, save sanitized evidence, and remove temporary state. It must not be inferred from the current product acceptance path.

### 11.2 Experimental controls and measures

All historical Cloud Run profile comparisons used the same immutable solver image, OR-Tools 9.15.6755, `tal94-demand-v2`, `balanced_v1`, random seed `20260718`, concurrency one, minimum instances zero, maximum instances three, and a 300-second HTTP timeout. Holding those controls constant made CPU, memory, worker count, and search-window differences interpretable.

The representative tier was executed ten times on each profile. Larger tiers used bounded 30-, 120-, and 240-second solver windows. Python verification ran in Cloud Build. Laravel enforced the environment guard, captured the snapshot, authenticated the request, validated the typed response and assignments independently, exercised publication, and rolled back test records.

The **random seed** is an integer that initializes CP-SAT's randomized search choices. The value `20260718` is a reproducibility control derived from the experiment date, 18 July 2026. Using the same seed and configuration reduces uncontrolled variation but does not guarantee identical time-bounded multi-worker results.

A **worker** is one CP-SAT search thread inside a request. **Concurrency one** means one HTTP request at a time per Cloud Run instance. **vCPU** is allocated virtual processor capacity, **GiB** is gibibytes of memory, and the **solver window** is the time allowed for CP-SAT search inside the longer HTTP request limit.

| Profile | vCPU | Memory | Workers | Experimental role |
| --- | ---: | ---: | ---: | --- |
| A | 1 | 2 GiB | 1 | Smallest client-scale comparison |
| B | 2 | 4 GiB | 2 | Client-production selection candidate and 2× comparison |
| C | 4 | 8 GiB | 4 | Upper research profile for 2× and 4× boundaries |

The profile letters are experiment labels, not Cloud Run product tiers. An earlier one-vCPU, one-GiB pilot was rejected after the representative acceptance path terminated the instance at approximately 1,045 MiB and again at 1,154 MiB.

Profile A retained one vCPU and one worker but doubled memory to 2 GiB, making it the smallest post-failure client-scale comparison. Profile B doubled A to 2 vCPU, 4 GiB, and two workers. Profile C doubled B to 4 vCPU, 8 GiB, and four workers.

Keeping concurrency at one meant each instance processed only one CPU- and memory-intensive solve at a time. The ordered doubling ladder compared progressively larger resources using the same image, workload, seed, and search window.

Profiles D, E, and F are not missing measurements. The bounded experiment needed three resource points to identify an unreliable lower profile, compare two fully accepted client-scale profiles, and examine a larger-workload boundary.

After Profile B satisfied the client workload and Profile C supplied the upper comparison, a fourth profile would have answered a different question: how much additional resource and cost should be approved beyond the tested production requirement?

The proportional 4× attempts had already exposed an 8-GiB memory boundary on Profile C. Larger combinations were therefore deferred to the later operating-envelope study in Section 11.6 instead of being added to the production-profile selection experiment.

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

The result tables use these terms:

- **Accepted runs:** requests meeting every acceptance condition.
- **Objective range:** smallest and largest ranking score.
- **Median:** middle observation after sorting.
- **p95:** 95th-percentile tail indicator.
- **p99:** 99th-percentile monitoring indicator.
- **CPU and memory utilization:** used shares of allocated resources.
- **Headroom:** unused share available for variation.
- **Model scale:** generated candidates, solver variables, and constraints.

A dash means the measure does not apply or no accepted incumbent existed.

### 11.3 Stage 1: client-production profile selection

**Purpose.** This stage selects the smallest reliable resource profile for the historical 54-demand client-representative workload. It compares resource configurations, not different equations.

**Observed result.** Profiles B and C accepted all ten comparison requests. Profile A accepted four; six requests reached a compute boundary without returning an incumbent. Every accepted request assigned 54 of 54 demands and passed both the solver and independent Laravel hard-constraint checks.

| Measure | Profile A | Profile B | Profile C |
| --- | ---: | ---: | ---: |
| Accepted runs | 4/10 | **10/10** | **10/10** |
| Accepted objective range | 393,460 | 388,620–406,570 | 385,300–404,140 |
| Median relative gap | 14.8274% | 14.1544% | **13.3831%** |
| p95 relative gap | 14.8274% | **15.8039%** | 17.3505% |
| Median runtime | 31.182 s | **31.152 s** | 31.439 s |
| p95 runtime | 31.471 s | **31.380 s** | 31.849 s |

**Selection rationale.** Profile B was selected through five ordered gates:

1. **Validity:** a profile first had to assign 54/54 demands with zero hard violations in every repetition. Profile A failed this gate in six of ten requests.
2. **Solution quality:** B and C both passed validity. C had a slightly lower median gap, while B had the stronger p95 gap; the complete distribution therefore did not justify C solely on median quality.
3. **Runtime:** B had the fastest median and p95 representative runtime, although the differences were small.
4. **Resource proportionality:** C doubled B's CPU and memory but did not provide a consistently stronger client-representative distribution.
5. **Cost:** cost was considered only after correctness and quality. B was therefore the justified middle profile for the actual 54-demand client workload.

### 11.4 Stages 2 and 3: proportional growth and stress-boundary evidence

**Purpose.** These stages evaluate proportional model growth and resource pressure after Profile B's client-scale selection. They do not repeat the production-profile comparison or represent literal student-population multiplication.

At the 30-second window, profiles B and C returned `unknown` without an incumbent for proportional 2×, with complete model telemetry and no infrastructure failure. The next approved window therefore increased search time rather than changing the mathematical model.

**Acceptance rule.** Solver-status definitions follow Section 7. Capacity evidence accepts only interface value `optimal` or `feasible` when the complete assignment also passes Laravel validation. `UNKNOWN` is inconclusive. A diagnostic `INFEASIBLE` result applies only to the exact stress input. OOM/HTTP 503 is an infrastructure failure, and an untested profile-tier combination supports no conclusion.

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

**How to read the matrix.** `3/3` denotes three accepted requests, not three internal solver trials. The reduced tier supports harness verification but no Cloud-capacity claim. Profile A was not advanced after it failed six client-scale repetitions.

Profiles B and C were compared at proportional 2×. Proportional 4× was tested only on Profile C to locate an upper resource boundary. The single contention result is diagnostic rather than repeatable-capacity evidence. The benchmark is therefore bounded rather than a full-factorial comparison, and every untested cell remains outside the stated conclusions.

| Tier and profile | Accepted | Result | Gap range | Runtime | Model scale |
| --- | ---: | --- | ---: | ---: | --- |
| Proportional 2×, B, 120 s | 2/3 | two `feasible`; one OOM/503 | 12.5155%–12.5212% | 124.745–124.965 s for accepted runs | 35,712 candidates; 108,120 variables; 215,808 constraints |
| Proportional 2×, C, 120 s | 3/3 | `feasible` | 6.8713%–9.7205% | median 125.651 s | same model scale |
| Contention 2×, C, 120 s | 0/1 | diagnostic `infeasible` | — | 63.227 s | 20,712 candidates; 62,610 variables; 125,112 constraints |
| Proportional 4×, C, 120 s | 0/1 | `unknown`; compute boundary | — | 146.428 s | 131,424 candidates; 396,816 variables; 792,192 constraints |
| Proportional 4×, C, 240 s | 0/3 | three OOM/503 infrastructure failures | — | instances terminated after 170–225 s | same 4× model scale |

**Interpretation.** Proportional 2× on Profile C is the largest **repeatably accepted** tier: every approved Profile C repeat returned a validated 108-demand candidate under the stated controls. Profile B accepted two of three; one request exceeded 4 GiB.

Proportional 4× is the largest attempted tier and an **observed boundary**. All three 240-second attempts consumed approximately the full 8-GiB allocation, terminated the instance, and produced HTTP 503. The contention result applies only to its disclosed synthetic transformation and does not imply that every real 108-demand workload is infeasible.

### 11.5 Production acceptance, cost, and applicability

**Deployment identity.** The selected Profile B configuration was deployed as one immutable Cloud Run revision. Its exact revision name is retained so the evidence can be traced to the deployed version that processed the requests.

| Reader-facing name | Exact technical identifier | Meaning |
| --- | --- | --- |
| Production-baseline scheduler deployment | Cloud Run revision `tala-scheduler-solver-b4f-ad9177e472f8` | The immutable deployed service version that received the post-promotion requests. |
| Production resource profile | Profile B: 2 vCPU, 4 GiB, 2 CP-SAT workers, concurrency 1 | The compute and search configuration used by that revision. |
| Canonical production traffic | 100% | All ordinary service traffic was directed to that production revision; private research revisions remained outside canonical traffic. |

The revision name identifies the deployment. Profile B identifies its disclosed resources. The accepted results and Laravel validation establish whether its returned schedules were usable. None of those meanings should be inferred from the revision suffix alone.

**Observed result.** Two post-promotion authenticated solves each assigned 54 of 54 demands with zero hard violations. Laravel independently validated the responses, ingested 54 candidate rows, exercised Registrar publication of 54 official meetings, and rendered the Registrar, Faculty, and representative Student schedules inside a rolled-back database transaction.

No schedule run, candidate row, official meeting, queued job, or failed job survived the controlled acceptance transaction. The scheduling queue was then resumed.

**Cost interpretation.** Using observed elapsed time as a billable-time proxy and the dated Singapore request-based list rates of US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, and US$0.40 per million requests, the corrected retained experiment's 59 requests are estimated at US$0.196810 before free-tier credits.

This is a bounded experiment estimate, not a monthly forecast or invoice. It excludes billing-rounding differences, networking, registry and build charges, taxes, discounts, invalid deployment attempts, and unrelated project usage.

**Conclusion and limitation.** These findings do not alter any equation in Sections 5 and 6. They establish operational acceptance at the disclosed client scale, identify Profile B as the production baseline, establish proportional 2× on Profile C at 120 seconds as repeatable larger-workload evidence, and disclose proportional 4× at 8 GiB as an observed historical boundary. They do not establish a universal institution-size limit.

### 11.6 Population operating-envelope study

**Historical purpose.** The completed population study answered a different question from the proportional experiments. The proportional tiers isolated solver-model growth by transforming one baseline snapshot. The former `MIN`, `MIDDLE`, and `MAX` labels described controlled research fixtures; they are preserved below only so the recorded results remain interpretable. They are no longer active selectors, seed data, product environments, or system-demonstration machinery.

Student headcount supplies the institutional scale narrative. The solver does not optimize one decision variable per student; it receives **scheduling demands** and expands them into candidate assignments, variables, and constraints. Those model measures govern compute difficulty more directly than student count alone.

| Scenario | Population and basis | Executable scheduling composition | Constructed model scale |
| --- | --- | --- | --- |
| `MIN` | 47 current students, the lowest client-reported population | 6 cohorts; 9 client-reported and synthetic scheduling faculty; 54 demands; 6 rooms; 168 half-hour slots | 11,340 candidates; 34,335 variables; 68,592 constraints |
| `MIDDLE` | 270 synthetic students, then selected as a demonstration and growth-planning workload | 9 cohorts; 14 synthetic scheduling faculty; 80 demands; 6 rooms; 168 half-hour slots | 56,112 candidates; 169,043 variables; 337,725 constraints |
| `MAX` | 600 students from the client's reported 2022–2023 high point | 20 cohorts; 178 demands; 6 rooms; 168 half-hour slots; 26 synthetic scheduling faculty | 192,492 candidates; 579,437 variables; 1,157,585 constraints in the corrected fixture's completed exploratory model build |

The reported historical MAX headcount of 14 faculty is preserved as client evidence but is not treated as an executable staffing plan. At a 21-unit ceiling, 532 teaching units require at least 26 faculty by arithmetic alone (`ceil(532 / 21) = 26`) before qualifications and availability are considered.

The MAX fixture therefore uses 26 explicitly synthetic scheduling faculty. This distinction lets the experiment test the disclosed population and workload without claiming that the client's historical faculty roster could satisfy the constructed load.

**Configuration-label guide.** The following labels are research shorthand. The labels do not come from Google Cloud and do not themselves report whether a run succeeded.

| Configuration label | Plain-language purpose | Disclosed configuration |
| --- | --- | --- |
| Production Profile B | Current client-baseline service | 2 vCPU, 4 GiB, 2 workers, concurrency 1, 30-second solver limit |
| `TARGET-CFG-01` | Private MIDDLE-oriented growth candidate | 4 vCPU, 8 GiB, 4 workers, concurrency 1, 120-second solver limit, 300-second HTTP timeout |
| `TARGET-CFG-01-TIME` | Time-only MAX diagnostic using the same resources as `TARGET-CFG-01` | Same CPU, memory, workers, and concurrency; solver limit extended to 240 seconds |
| `FINAL-CFG-01` | Higher-CPU MAX memory-boundary test | 8 vCPU, 8 GiB, 8 workers, concurrency 1, 300-second solver limit, 360-second HTTP timeout |
| `FINAL-CFG-02-MEM` | Controlled higher-memory MAX resource envelope | Same as `FINAL-CFG-01`, but memory increased from 8 GiB to 16 GiB |
| Final staged-search image | Equation-preserving completion attempt on `FINAL-CFG-02-MEM` resources | First find one complete hard-valid timetable, then optimize the unchanged objective with the remaining time |

`TARGET` means the configuration was a bounded research candidate. `FINAL` identifies the later controlled branch of this study, not a guarantee of mathematical optimality or production promotion. `MEM` indicates that memory was the intentionally changed resource.

**Final corrected-MAX evidence identity.** These values identify the exact input, packaged solver, and private deployed revision used for the accepted MAX request.

| Evidence element | Exact identifier | Plain-language meaning |
| --- | --- | --- |
| Corrected-MAX canonical snapshot | SHA-256 `576a5f4ce5e6e5988eb7edd64ce59a20ba61fdc972f7cf57d85dbef1aa48ce38` | Fingerprint of the exact scheduling input containing the disclosed 178-demand corrected-MAX fixture. |
| Final staged-search container image | Digest `sha256:229172013cd0e82a7d4d9c74e259618470a92b01465ba10f1fd4e8c5fa8b9b27` | Immutable identifier of the packaged Python solver contents. |
| Final private research deployment | Cloud Run revision `tala-scheduler-solver-d5dstage2-665963443cc0` | Immutable deployed version that combined the image with the disclosed Cloud Run configuration. |
| Revision traffic state | Zero canonical traffic | The revision could be called only through its private research route; ordinary production requests continued to use Profile B. |

**Method.** The study avoided an exhaustive configuration-by-population cross-product. It started with a candidate suitable for the representative `MIDDLE` workload, diagnosed only the observed boundary, and changed one resource or search-control factor at a time. `TARGET-CFG-01` and its time-only diagnostic are retained as **exploratory evidence**.

The later branch tested `FINAL-CFG-01`, then changed only memory in `FINAL-CFG-02-MEM`. The final staged-search image retained the same 8-vCPU/16-GiB resources and time limits while changing only the search order described above. Production Profile B remained at 100% canonical traffic throughout; none of the private candidates was promoted.

**Observed results.**

| Scenario and configuration | Observed result | Coverage and hard constraints | Solution quality or failure classification | End-to-end duration | Corrected gross request-cost proxy |
| --- | --- | --- | --- | --- | --- |
| `MIN`, exploratory `TARGET-CFG-01` | 3/3 `feasible` and accepted | 54/54 demands; zero solver or Laravel hard violations | Relative gap 3.5256988%–4.1487866%; optimality not proved | 122.191974–122.727425 s; median 122.619782 s | US$0.0067038032–US$0.0067367168 per solver request; US$0.0201717512 total |
| `MIDDLE`, exploratory `TARGET-CFG-01` | 3/3 `feasible` and accepted | 80/80 demands; zero solver or Laravel hard violations | Relative gap 16.8320877%–19.8179851%; optimality not proved | 127.517125–129.330287 s; median 128.939737 s | US$0.0070000256–US$0.0070987664 per solver request; US$0.0211756160 total |
| Corrected `MAX`, exploratory `TARGET-CFG-01` | 1/1 `unknown_timed_out`; not accepted | No incumbent schedule, so no coverage claim | Feasibility was neither proved nor disproved | 119.168814 s client elapsed | US$0.02070680 including its probe and solver request |
| Corrected `MAX`, exploratory `TARGET-CFG-01-TIME` | 1/1 `unknown_timed_out`; not accepted | No incumbent schedule, so no coverage claim | Feasibility was neither proved nor disproved after the longer search | 275.152538 s client elapsed | US$0.04586256 including its probe and solver request |
| Corrected `MAX`, strict `FINAL-CFG-01` | HTTP 503; not accepted | No solver result or assignments were returned | `infrastructure_failure`: Cloud Run terminated the instance at 8,208 MiB against the 8,192-MiB limit | 201.001607 s client elapsed; 200.199660359 s Cloud request latency | US$0.0203565448 including its probe and failed solver request |
| Corrected `MAX`, earlier `FINAL-CFG-02-MEM` image | 1/1 `unknown_timed_out`; not accepted | No incumbent schedule, so no coverage claim; 178 placeholder conflict rows are not assignments | The 16-GiB revision avoided the prior memory termination, but CP-SAT returned no incumbent within the unchanged 300-second solver limit | 342.669576203 s Cloud request latency; 343.477093 s client elapsed | US$0.0378624112 including its probe and solver request |
| Corrected `MAX`, final staged-search image on `FINAL-CFG-02-MEM` resources | 1/1 `feasible`; operationally accepted | 178/178 demands assigned; zero unassigned demands; zero Python or Laravel hard-constraint violations | Objective 1,115,910; best bound 0; relative gap 100%; a valid timetable was found, but optimality was not proved | 307.819849 s reported runtime; 314.471862 s client elapsed | US$0.03593148 including one health probe and one solver request |

**How to interpret the status column.** Interface value `feasible` means the candidate satisfied every hard scheduling rule, but the solver did not prove that no better soft-objective value existed. The relative optimality gap is the disclosed solution-quality measure; it is not an accuracy percentage.

`unknown_timed_out` means the search ended without proving feasibility or infeasibility. An infrastructure failure is not a CP-SAT status; it means the service failed before returning a trustworthy solver result.

**Corrected-MAX interpretation.** The earlier time-only `infeasible` observation belonged to a superseded pre-correction MAX construction. It must not be applied to the corrected MAX fixture.

Before the final Cloud request, the corrected fixture passed deterministic input-readiness and aggregate room-capacity necessary conditions. One independently replayed, non-optimizing witness also satisfied candidate membership and Laravel hard-constraint validation for all 178 demands. That witness proved that the disclosed fixture had at least one hard-valid assignment; it did not claim a CP-SAT optimization result.

The final staged-search Cloud run then supplied direct solver evidence. All 178 demands received assignments, and the complete assignment set passed Laravel hard-constraint validation. The corrected `MAX` scenario is therefore inside the **verified operational envelope** of the disclosed 8-vCPU, 16-GiB, eight-worker, 300-second staged-search configuration for this one controlled run.

This is not an absolute student or institutional ceiling, a throughput guarantee, proof of repeatability, or proof that every 600-student dataset will have the same model size or runtime.

**Cost basis.** The values above use the 27 July 2026 Singapore request-based list rates of US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, and US$0.40 per million requests. Client elapsed time is rounded upward to the disclosed 100-millisecond billing quantum, and no free-tier credit is applied.

The retained exploratory MIN/MIDDLE and original MAX solver-request proxies total US$0.0624073856 before exclusions. The later controlled requests include their health probes: US$0.0203565448 for `FINAL-CFG-01`, US$0.0378624112 for the earlier `FINAL-CFG-02-MEM` image, and US$0.03593148 for the accepted staged-search run.

Earlier immutable reports retain superseded embedded estimates of US$0.06051832 and US$0.11208928 from the wrong rate class. These amounts are neither invoices nor monthly forecasts. Free tier, discounts, networking, logging, image storage, build charges, taxes, and unrelated project use are excluded.

**Configuration decision (as of the July 2026 historical baseline).** No configuration proved repeated `OPTIMAL` results with zero relative gap across `MIN`, `MIDDLE`, and corrected `MAX`; that stronger solution-quality claim remains unproven. The operational acceptance criterion is complete coverage, zero hard-constraint violations, and interface value `feasible` or `optimal`.

At that July 2026 historical checkpoint (`tal94-demand-v2` / `balanced_v1`), Production Profile B functioned as the deployed client-scale baseline, `TARGET-CFG-01` was evaluated as the private candidate for growth toward the 80-demand `MIDDLE` demonstration scale (producing three accepted schedules), and neither private candidate was promoted during that round. Later on August 19, 2026 (Issue #22), the Architecture Specification records activation of an 8-vCPU / 16-GiB Cloud Run revision (`tala-scheduler-solver-i22-3f461a46`) to address larger timetable-v2 headroom. However, as confirmed during this reconciliation, the live Cloud Run deployment is currently outdated relative to repository `main`, and no post-`4e41564e` Cloud Run benchmark has been run for `tala-timetable-v2` / `lexicographic_v1`.

The final report retained all 178 sanitized assignment rows. The following excerpt reconstructs one logical cohort from that captured solver output. It is synthetic research evidence, not a published official schedule, and it must not be described as optimal.

| Cohort | Subject | Day | Time | Faculty | Modality | Room |
| --- | --- | --- | --- | --- | --- | --- |
| DBM-1A | FOSNCII | Monday | 08:30–11:30 | Faculty 12 | Face-to-Face | LAB-101 |
| DBM-1A | GE04 | Monday | 11:30–14:30 | Faculty 15 | Online | Not applicable |
| DBM-1A | PE02 | Monday | 18:00–20:00 | Faculty 01 | Online | Not applicable |
| DBM-1A | NSTP02 | Wednesday | 10:00–12:00 | Faculty 21 | Online | Not applicable |
| DBM-1A | CSNCII | Wednesday | 17:00–21:00 | Faculty 07 | Face-to-Face | LAB-102 |
| DBM-1A | GE06 | Thursday | 10:00–13:00 | Faculty 05 | Online | Not applicable |
| DBM-1A | BME04 | Friday | 11:00–14:00 | Faculty 04 | Face-to-Face | LEC-101 |
| DBM-1A | GE05 | Friday | 14:30–17:30 | Faculty 24 | Online | Not applicable |
| DBM-1A | BME06 | Saturday | 07:00–10:00 | Faculty 10 | Face-to-Face | LEC-102 |
| DBM-1A | BME05 | Saturday | 18:00–21:00 | Faculty 06 | Face-to-Face | LEC-101 |

**Solve scope.** The returned schedule is term-wide: one solve assigns every ready Scheduling Demand in the selected academic term rather than solving one student, one section, or one program independently.

A demand represents one required subject delivery for one delivery group. Individual students later see the meetings attached to their enrolled offerings; the solver does not create a separate optimization problem for each student.

**Scaling trigger.** Operators must reassess configuration when:

- demand, candidate, variable, or constraint counts approach or exceed the verified scenario values;
- repeated runs stop returning accepted `feasible` or `optimal` results inside the approved time objective;
- solution-quality gaps become operationally unacceptable; or
- monitoring shows memory, transport, or queue-pressure failures.

The accepted MAX result establishes one observed operating point, not a guarantee beyond 178 demands or 1,157,585 constraints. Raising maximum instances would increase concurrent-request capacity but would not give one solve more CPU, memory, or search time.

The final immutable report predates the bounded report-persistence correction and therefore does not retain the nested per-stage timing records. Those missing telemetry values must not be reconstructed or guessed. The final result, assignments, aggregate statistics, status, cost, and validation evidence remain intact. These findings change neither the solver contract nor any equation in Sections 5 and 6.

### 11.7 Post-Refactor Factored Model Local Benchmark Addendum (November 2026)

Following the P0/P1/P2 factored CP-SAT refactor of `cloud/scheduler-solver/tala_solver/solver.py` under `tala-timetable-v2` / `lexicographic_v1`, local single-worker (`SOLVER_WORKER_COUNT = 1`) synthetic multi-pool scale measurements (`_build_scale_snapshot` in [`test_scale_and_feasibility_guards.py`](../../cloud/scheduler-solver/tests/test_scale_and_feasibility_guards.py#L612-L728), `70%` Face-to-Face / `30%` Online across `6` weekdays $\times$ `144` slots) recorded the following capacity profile relative to the pre-refactor candidate-product formulation (`>20s` Stage-1 timeout across all three scales):

| Scale | Meetings | Option Count | Stage-1 Vars / Cons | Final Vars / Cons | Build Time | Stage-1 Time | Total Time | Peak Memory | Outcome |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **60 demands** | 75 | 8,460 | 1,059 / 1,361 | 9,544 / 16,877 | 0.53 s | 0.29 s | 42.46 s | 3.48 MB | `feasible` (`75/75`) |
| **120 demands** | 150 | 16,920 | 2,082 / 2,884 | 18,552 / 33,610 | 1.06 s | 4.08 s | 22.06 s | 5.45 MB | `feasible` (`150/150`) |
| **200 demands** | 250 | 28,200 | 3,446 / 4,714 | 30,896 / 55,924 | 3.08 s | 8.90 s | 30.96 s | 9.02 MB | `feasible` (`250/250`) |

All 200-demand runs completed with zero hard-constraint violations (`_assert_zero_hard_violations`), and the full local Python test suite (`cloud/scheduler-solver/tests/`, `60` tests) passed in `26.257s`. These local measurements document refactored model scaling under `SOLVER_WORKER_COUNT = 1` and do not alter the July 2026 Cloud Run historical baseline in Sections 11.1–11.6.

---

## 12. Equation-to-Implementation and Test Traceability

Every symbol, constraint, objective level, execution limit, and validation boundary maps directly to repository code and automated verification tests on `issue-93-academic-operations`:

| Mathematical Construct | Python / Laravel Implementation Symbols | Automated Test Verification |
| :--- | :--- | :--- |
| Snapshot contract & cohort normalization | [`solver.py`](../../cloud/scheduler-solver/tala_solver/solver.py) (`solve_snapshot:187`, `_cohort_ids_by_delivery_group:1662`, `_has_valid_meeting_pattern:3261`), [`ScheduleSolverSnapshotService.php`](../../app/Actions/Scheduling/ScheduleSolverSnapshotService.php) | `test_unsupported_contract_is_model_invalid`, `test_tampered_profile_is_model_invalid`, `test_inconsistent_shared_cohort_mapping_is_model_invalid`, [`TimetableV2LoopbackContractTest.php`](../../tests/Feature/AcademicScheduling/TimetableV2LoopbackContractTest.php) |
| Meeting domain preparation & pruning | `solver.py` (`_prepare_meetings:1878`, `_filter_viable_options:2082`, `_slots_for_demand_parsed:3091`, `_slot_conflicts_global_or_dg:2989`, `_window_conflicts_calendar:3067`, `evaluate_candidate_membership:1216`) | `test_fixed_faculty_room_and_time_are_respected`, `test_off_grid_fixed_time_has_no_admissible_candidate`, `test_recurring_calendar_block_excludes_overlapping_fixed_assignment`, `test_required_room_features_are_hard_constraints`, `test_candidate_membership_replay_accepts_valid_rows_and_rejects_tampering` |
| P1 pre-solve feasibility guards | `solver.py` (`_presolve_feasibility_guards:2159`, `_subtract_intervals:2342`) | [`test_scale_and_feasibility_guards.py`](../../cloud/scheduler-solver/tests/test_scale_and_feasibility_guards.py) (`test_fails_fast_when_faculty_exclusive_load_units_exceed_cap`, `test_fails_fast_when_faculty_exclusive_weekly_hours_exceed_open_minutes`, `test_fails_fast_when_faculty_longest_exclusive_block_exceeds_longest_open_block`, `test_fails_fast_when_room_type_f2f_minutes_exceeded_with_closures`, `test_fails_fast_when_cohort_weekly_minutes_exceed_grid_open_minutes`, `test_guards_do_not_false_positive_on_tight_feasible_edge_cases`) |
| P0 factored model, `(C1)` coverage & `(C2a)–(C2c)` unified-week `AddNoOverlap` | `solver.py` (`MeetingSpec:97`, `FactoredModelState:126`, `_build_factored_hard_model:2377`, `DAY_STRIDE_MINUTES:41`, `SLOT_BOOL_OPTION_LIMIT:43`) | `test_exact_coverage_makes_conflicting_fixed_demands_infeasible`, `test_same_section_group_and_room_assignments_do_not_overlap`, `test_shared_cohort_identity_prevents_overlap_across_course_specific_delivery_groups`, `test_shared_class_membership_blocks_overlap_for_every_attached_cohort`, `test_two_ninety_minute_meetings_are_expanded_without_overlap_for_one_faculty`, `test_three_sixty_minute_meetings_are_expanded_without_overlap_for_one_faculty`, `test_factored_start_vars_mode_enforces_modality_buffer_no_overlap_same_faculty_and_pins` |
| Buffered 30-min modality transition `(C3)` | `solver.py` (`MODALITY_BUFFER_MINUTES:42`, `_add_factored_modality_transition_buffer:2597`) | `test_cross_modality_transition_requires_a_thirty_minute_cohort_buffer`, `test_factored_start_vars_mode_enforces_modality_buffer_no_overlap_same_faculty_and_pins` |
| Structural same-faculty `(C4a)` & distinct-day spread `(C4b)` | `solver.py` (`_prepare_meetings:2030–2076`, `_build_factored_hard_model:2495–2504`, `_add_factored_distinct_day_spread:2645`), [`ScheduleAssignmentValidationService.php`](../../app/Actions/Scheduling/ScheduleAssignmentValidationService.php) (`sameFacultyFindings`) | `test_indexed_solver_matches_a_small_reference_hard_rule_and_objective_evaluator`, [`Slice3ConformanceGapsTest.php`](../../tests/Feature/AcademicScheduling/Slice3ConformanceGapsTest.php) |
| Deduplicated faculty load `(C5a)–(C5b)` | `solver.py` (`_add_factored_faculty_load_constraints:2669`, `_decimal_scaled:3465`), `ScheduleAssignmentValidationService::facultyLoadFindings` | `test_faculty_load_counts_each_offering_group_once`, `test_linked_components_do_not_double_count_faculty_load` |
| Repair pin `(C6)` & Level 0 `(O0)` | `solver.py` (`_apply_repair_hard_requirement:2730`, `_repair_change_objective:2786`, `_matching_assignment_spec:2837`), [`RequestTimetableRepair.php`](../../app/Actions/Scheduling/RequestTimetableRepair.php) | `test_repair_fixes_the_request_and_minimizes_changed_other_meetings_first`, [`AcademicAuthorityToPublishedTimetableJourneyTest.php`](../../tests/Feature/AcademicScheduling/AcademicAuthorityToPublishedTimetableJourneyTest.php) |
| Lexicographic levels 1–6 `(O1)–(O6)` & P2 Stage-2 protocol | `solver.py` (`_lexicographic_objectives:839`, `_on_day_bool:872`, `_cohort_mode_switch_objective_terms:903`, `_cohort_idle_gap_objective_terms:992`, `_load_balance_objective_terms:3123`, `_faculty_idle_gap_objective_terms:1064`, `_room_efficiency_objective_terms:1169`, `_earlier_placement_objective_terms:1186`, `_solve_lexicographic:512`, `_Stage2EarlyStopCallback:143`, `_apply_solver_hints:667`, `_lexicographic_objective_details:3346`) | `test_v2_profile_reports_the_fixed_lexicographic_hierarchy_without_weights`, `test_indexed_solver_matches_a_small_reference_hard_rule_and_objective_evaluator`, `test_load_imbalance_includes_unused_eligible_faculty_in_scaled_load_units`, `test_cohort_mode_switches_count_only_adjacent_selected_meetings`, `test_faculty_idle_gap_counts_only_time_between_adjacent_meetings`, `test_room_seat_waste_uses_confirmed_expected_count`, `test_200_demand_mixed_modality_multi_pool_term_solves_cleanly_within_budget_and_memory` |
| Single deadline chain, reserve & `503` failures | [`runtime.py`](../../cloud/scheduler-solver/tala_solver/runtime.py) (`SolverRequestContext`), [`server.py`](../../cloud/scheduler-solver/tala_solver/server.py), [`Dockerfile`](../../cloud/scheduler-solver/Dockerfile), [`tala_integrations.php`](../../config/tala_integrations.php), [`ScheduleSolverDispatchJob.php`](../../app/Jobs/ScheduleSolverDispatchJob.php) | `test_one_deadline_reserves_response_time_across_every_phase`, `test_phase_timings_are_measured_by_the_same_monotonic_clock`, `test_solver_budget_is_clamped_to_the_approved_300_second_request_cap`, `test_budget_exhaustion_returns_a_bounded_retryable_technical_failure`, `test_request_budget_exhaustion_after_feasibility_keeps_the_complete_timetable`, `test_unknown_feasibility_search_returns_no_false_conflict_timetable`, `test_unknown_optimization_keeps_the_complete_feasibility_assignment` |
| Ingestion, revalidation, adjustment & publication | [`ScheduleCloudResultIngestor.php`](../../app/Actions/Scheduling/ScheduleCloudResultIngestor.php), `ScheduleAssignmentValidationService`, [`ScheduleAssignmentRevalidationService.php`](../../app/Actions/Scheduling/ScheduleAssignmentRevalidationService.php), [`AdjustCandidateMeeting.php`](../../app/Actions/Scheduling/AdjustCandidateMeeting.php), [`SchedulePublishService.php`](../../app/Actions/Scheduling/SchedulePublishService.php), [`PublishedScheduleRevisionService.php`](../../app/Actions/Scheduling/PublishedScheduleRevisionService.php) | [`AcademicAuthorityToPublishedTimetableJourneyTest.php`](../../tests/Feature/AcademicScheduling/AcademicAuthorityToPublishedTimetableJourneyTest.php), [`Slice3ConformanceGapsTest.php`](../../tests/Feature/AcademicScheduling/Slice3ConformanceGapsTest.php), [`TimetableV2LoopbackContractTest.php`](../../tests/Feature/AcademicScheduling/TimetableV2LoopbackContractTest.php) |

---

## 13. References

1. Google OR-Tools CP-SAT Solver Documentation (`ortools==9.15.6755`): [developers.google.com/optimization/cp/cp_solver](https://developers.google.com/optimization/cp/cp_solver).
2. TALA Product Requirements Document — Module 03: [`00_Project_Documents/prd_modules/03_academic_setup_offerings_published_timetable.md`](../prd_modules/03_academic_setup_offerings_published_timetable.md).
3. TALA System Architecture Specification (§9.1 Cloud Run Scheduler Solver): [`00_Project_Documents/architecture_specification.md`](../architecture_specification.md).
4. Solver Service Runtime Contract & Local Verification Guide: [`cloud/scheduler-solver/README.md`](../../cloud/scheduler-solver/README.md).
5. Google Cloud Run Request Timeout & Container Contract: [cloud.google.com/run/docs/configuring/request-timeout](https://cloud.google.com/run/docs/configuring/request-timeout) and [cloud.google.com/run/docs/container-contract](https://cloud.google.com/run/docs/container-contract).
6. Gunicorn Worker Settings (`timeout`, `workers`, `threads`): [docs.gunicorn.org/en/stable/settings.html](https://docs.gunicorn.org/en/stable/settings.html).

---

**Document reconciliation provenance.** Reconciled against refactored `cloud/scheduler-solver/tala_solver/solver.py` (P0 factored meeting intervals, P1 pre-solve feasibility guards, P2 Stage-2 large-problem controls), `PRD 03`, and `Architecture Specification` (§9.1) on November 26, 2026. Sections 11.1–11.6 preserve the completed July 2026 `tal94-demand-v2` / `balanced_v1` Cloud Run empirical study intact with explicit historical qualification, while Section 11.7 records the November 2026 local factored-model scale benchmark addendum.
