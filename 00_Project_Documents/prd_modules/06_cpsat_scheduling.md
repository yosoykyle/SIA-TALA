## 6. CP-SAT Scheduling Subsystem

---

### 6.1. Scheduling and CP-SAT Integration

Scheduling is a core subsystem.

TALA uses the Google Cloud Run CP-SAT service for scheduling computation. TALA remains the source of truth for official scheduling records.

Scheduling flow:

Term setup → Course Catalog ready → Curriculum approved → Term offerings created → section delivery groups created → Scheduling Demand generated → rooms, faculty, calendar, and constraints configured → readiness check → solver run → TALA validation → human review → approval → publication → enrollment binding → COR generation.

Scheduling Demand:

1. Scheduling Demand is the canonical schedulable unit sent to CP-SAT.
2. A Scheduling Demand represents a curriculum subject needed by a section delivery group in a term, enriched with the operational scheduling fields TALA needs.
3. A simple lecture subject may create one Scheduling Demand.
4. A lecture/laboratory subject may create separate lecture and laboratory Scheduling Demands when the curriculum or scheduling group requires different room, contact-hour, modality, or faculty constraints.
5. CP-SAT schedules demand rows, not raw subjects.
6. **Two-Phase Architecture:** 
   - **Phase 1 (AI Sandbox):** The CP-SAT solver outputs candidate schedules strictly to the `CandidateScheduleRow` staging table. This allows Registrar review without contaminating live data.
   - **Phase 2 (In-Place Live):** Once approved, candidate rows are published (copied) to the official `section_meetings` table. The solver sandbox is then cleared.
7. This model preserves solver reliability while ensuring live data integrity.
8. The client curriculum table does not need to contain room, instructor, modality, or exact time fields. Those details come from Course Catalog enrichment, section delivery groups, term setup, faculty records, rooms, and scheduling configuration.
9. CP-SAT schedules cohort delivery groups and regular section blocks. It does not schedule individual irregular students. Irregular student demand is handled post-publication during enrollment using section buffer capacities, and conflict checking for irregular schedules is performed by TALA during enrollment section selection.

TALA owns:

1. Term and calendar.
2. Course catalog.
3. Curriculum versions.
4. Term offerings.
5. Sections.
6. Rooms and room features.
7. Faculty availability, load, and qualifications.
8. Constraint catalog.
9. Solver run history.
10. Candidate schedules.
11. Schedule approval.
12. Published schedule versions.
13. Enrollment-to-schedule binding.
14. COR schedule reference.

Solver readiness must check:

1. Approved term calendar.
2. Approved curriculum.
3. Approved term offerings.
4. Section capacities.
5. Subject contact hours.
6. Room capacity and features.
7. Faculty availability and qualification.
8. Constraint set.
9. Expected demand.
10. No blocking validation errors.

Hard constraints:

1. No room double-booking.
2. No faculty double-booking.
3. No section or cohort overlap.
4. No student schedule overlap.
5. Required contact hours must be satisfied.
6. Term calendar and blocked periods must be respected.
7. Room type and features must match subject needs.
8. Faculty availability and qualifications must be respected.
9. Faculty load limit cannot be exceeded unless approved.
10. Published schedules cannot be silently edited.
11. Large contact-hour subjects (e.g., 6-hour laboratory or studio classes) must be scheduled in consecutive time blocks on a single day.


Hard constraint source map:

| Constraint | Source Records |
| --- | --- |
| Assignment coverage | Term offerings, Scheduling Demand, section delivery groups |
| Room no-overlap | Rooms, room availability, time blocks, existing active assignments |
| Faculty no-overlap | Faculty profile, availability, active assignments |
| Section/cohort no-overlap | Section, cohort group, term offering, meeting pattern |
| Student no-overlap | Checked by TALA during enrollment for irregular students (solver only enforces cohort/section non-overlap) |
| Contact-hour completion | Course catalog, Scheduling Demand, term calendar time blocks |
| Calendar validity | Academic calendar, holidays, no-class days, exam periods, room closures |
| Room suitability | Room type, features, capacity, delivery modality |
| Faculty eligibility | Faculty-subject qualification mappings |
| Faculty load | Term setting default max units plus term-specific overload override |
| Capacity feasibility | Section capacity, cohort-reserved seats, irregular buffer slots |
| Fixed assignment preservation | Published schedule version, fixed assignment inputs, revision events |
| Consecutive lab/studio blocks | Course catalog block rules, Scheduling Demand consecutive flag |

Soft constraints:

1. Prefer faculty requested times.
2. Prefer compact student and section schedules.
3. Reduce faculty idle gaps.
4. Balance faculty load.
5. Use rooms efficiently.
6. Reduce late and weekend schedules.
7. Minimize changes from previous published version.

Soft constraint rules:

1. Soft constraints must never override hard constraints.
2. Soft-constraint weights are configured in scheduling settings or selected from approved presets.
3. Solver output must include enough score detail for Registrar review.
4. A feasible schedule with lower soft-constraint quality may still be published by authorized staff with a recorded reason.

CP-SAT modeling note:

CP-SAT works over integer variables, so TALA sends time as stable integer time-block IDs or minute offsets, not free-form time strings. Calendar and duration rules must be converted into integer time blocks before the payload is sent to the solver.

Fixed Assignments and Pre-locking:

1. Staff can pre-fill or lock specific scheduling details (such as a specific room, faculty, or time block) for a Scheduling Demand before the solver runs.
2. Locked fields are treated as hard constraints (Fixed Assignments) by the solver, which must respect these choices while optimizing the remaining unassigned variables.
3. Modular or online modalities that have no weekly meetings or physical rooms are pre-marked to prevent the solver from attempting room or time assignments.

Consecutive Block Scheduling:

1. For subjects requiring large contact hours (e.g., 6 hours or 12 half-hour blocks) that must be scheduled in a single day, the solver treats the class session as a single contiguous interval variable (using `NewIntervalVar`).
2. CP-SAT uses `AddNoOverlap` on this interval to ensure the entire 6-hour block is scheduled without interruption in a suitable room, without conflicting with faculty availability or cohort schedules.

---

### 6.2. CP-SAT Product-Level Solver Contract

This is the product-level contract between TALA and the CP-SAT scheduling service.

#### 6.2.1 Solver Input Package

TALA must send only validated data.

Required input groups:

1. Run Metadata
2. Term
3. Time Slots
4. Subjects
5. Scheduling Demand
6. Sections
7. Section Delivery Groups
8. Rooms
9. Faculty
10. Faculty Qualifications
11. Faculty Availability
12. Term Offerings
13. Student / Cohort Groups
14. Hard Constraints
15. Soft Constraints
16. Fixed Assignments
17. Optimization Settings

#### 6.2.2 Required Input IDs

Every solver input must use stable TALA IDs:

1. solver_run_id
2. academic_year_id
3. term_id
4. curriculum_version_id
5. term_offering_id
6. section_id
7. subject_id
8. section_delivery_group_id
9. scheduling_demand_id or demand_key
10. room_id
11. faculty_id
12. time_slot_id
13. cohort_or_student_group_id
14. constraint_set_id

The solver must not invent official TALA IDs.

#### 6.2.3 Solver Output Package

Expected output:

1. solver_run_id
2. solver_status
3. candidate_schedule_id
4. assignments
5. hard_constraint_violations
6. soft_constraint_scores
7. infeasible_reasons, if applicable
8. warnings
9. runtime_seconds
10. objective_score, if available
11. solver_version or model_version
12. generated_at

#### 6.2.4 Assignment Output

Each assignment must map back to TALA records:

1. scheduling_demand_id or demand_key
2. term_offering_id
3. section_id
4. section_delivery_group_id
5. subject_id
6. faculty_id
7. room_id
8. day
9. start_time
10. end_time
11. time_slot_id or time_block_reference
12. meeting_pattern
13. assignment_status

#### 6.2.5 Solver Status Handling

If the solver determines a schedule is mathematically `INFEASIBLE`, TALA presents a "Relaxation & Override" review path. The Registrar can relax selected constraints, rerun the solver, or manually force specific assignments with recorded reasons so the term can still reach a workable schedule.

#### 6.2.6 Candidate Schedule Rule

Solver output is only a candidate schedule.

A candidate schedule becomes official only after:

1. TALA validates hard constraints.
2. Registrar reviews schedule.
3. Academic Head or authorized staff approves where required.
4. Schedule is published as a versioned schedule.

---

### 6.3. Schedule Publication and Mid-Term Schedule Revisions

TALA strictly forbids "Schedule Versioning" (cloning the entire schedule into superseded versions) to prevent database bloat. Instead, it relies on a strict Two-Phase model: the AI Sandbox (pre-enrollment) and In-Place Live Edits (post-enrollment).

**Post-Publication (Mid-Term Edits):**
Once the schedule is published and the Enrollment Calendar opens, the schedule is live. If an emergency happens mid-term (e.g., a room floods or an instructor resigns), the Registrar does *not* use a draft table or generate a new "version". They edit the live schedule directly.

**Requirements:**

1. **In-Place Updates:** Mid-term schedule adjustments update the `section_meetings` table directly in-place.
2. **Revision Event (Audit Log):** Every in-place schedule modification generates an immutable record in the `schedule_revision_events` table containing:
   - `id`, `term_id`, `section_meeting_id`
   - `change_type` (Enum: `ROOM_CHANGE`, `FACULTY_REASSIGNMENT`, `TIME_CHANGE`, `DELIVERY_MODALITY_CHANGE`, `SECTION_CANCELLATION`, `MINOR_LABEL_CORRECTION`)
   - `reason` (Required text explanation entered by Registrar)
   - `effective_date`, `changed_by`
   - `old_snapshot_json`, `new_snapshot_json` (structural data snapshots of the modified assignment to preserve history without cloning the entire schedule)
   - `affected_student_count`, `affected_faculty_count`
   - `created_at`
3. **COR Handling:** CORs are dynamically rendered from the live `section_meetings` table. No stored COR file is superseded. The next COR view in the Student Hub simply renders the updated reality.
4. **Notification:** Affected faculty and students receive email notifications through the configured Laravel mail transport.
5. **No Version Bloat:** The system does NOT maintain `new_schedule_version_id` or `SUPERSEDED` copies of the entire term schedule. The current state of `section_meetings` plus the `schedule_revision_events` log is mathematically sufficient to reconstruct any past state without massive database bloat.

---

### 6.4. CP-SAT Integration Settings

Settings:

1. Solver service endpoint.
2. Authentication or service credential reference.
3. Active / inactive status.
4. Timeout settings.
5. Retry settings.
6. Last successful run.
7. Last failed run.
8. Solver version or model version when available.

Rules:

1. Only validated scheduling input can be sent.
2. Solver run payloads must be logged according to retention policy.
3. Failed solver calls must appear in integration event logs.
4. Solver output must not directly publish official schedules.

---
