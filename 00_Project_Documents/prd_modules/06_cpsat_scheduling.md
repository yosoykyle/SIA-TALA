## 6. CP-SAT Scheduling Subsystem

---

### 6.1. Scheduling and CP-SAT Integration

Scheduling is a core subsystem.

TALA uses the Google Cloud Run CP-SAT service for scheduling computation. TALA remains the source of truth for official scheduling records.

Scheduling flow:

Term setup → Course Specification revisions ready → Curriculum approved → Term Offerings created → section delivery groups created → Scheduling Demand generated → rooms, faculty, calendar grid, break blocks, and constraints configured → readiness check → solver run → TALA validation → human review → approval → publication → enrollment binding → COR generation.

Scheduling Demand:

1. Scheduling Demand is the canonical schedulable unit sent to CP-SAT.
2. A Scheduling Demand represents a Course Component from a Curriculum Entry's referenced Course Specification Revision needed by a section delivery group in a term, combined with the Term Offering's actual modality and approved overrides.
3. A simple lecture subject may create one Scheduling Demand.
4. A Course Specification Revision with lecture and laboratory components may create separate linked Scheduling Demands when those components require different rooms, contact hours, modalities, or faculty constraints.
5. CP-SAT schedules demand rows, not raw subjects.
6. **Two-Phase Architecture:** 
   - **Phase 1 (AI Sandbox):** The CP-SAT solver outputs candidate schedules strictly to the `CandidateScheduleRow` staging table for Registrar review.
   - **Phase 2 (In-Place Live):** Once approved, candidate rows are published (copied) to the official `section_meetings` table. The solver sandbox is then cleared.
7. Candidate schedules remain separate from official section meeting records until publication.
8. The client curriculum table supplies curriculum-owned academic data. Default contact and room requirements come from the Course Specification Revision; actual modality and approved overrides come from the Term Offering; assignment choices come from section delivery groups, faculty, rooms, calendar, and scheduling configuration.
9. CP-SAT schedules cohort delivery groups and regular section blocks. Irregular placement happens post-publication through TALA validation and Registrar-confirmed Enrollment Seat Reservations.
10. Subject Drop, Withdrawal, Leave of Absence, Program Shift, or movement between compatible published sections updates Student Schedule Bindings. Staff initiates a new solver run only when the Master Schedule itself needs re-optimization.
11. A new solver run is considered only when the Master Schedule itself must change, such as opening or cancelling a section or materially changing room, time, or faculty assignments.
12. Linked lecture/laboratory Scheduling Demands must remain tied to one Term Offering, one section delivery group, one student enrollment line, and one released grade unless the institution defines separate subject codes or separate released grades.
13. Linked Lecture and Laboratory Scheduling Demands may use different qualified faculty unless the Course Specification Revision or Term Offering marks same faculty required.

TALA owns:

1. Term and calendar.
2. Course catalog.
3. Curriculum versions.
4. Term offerings.
5. Sections.
6. Rooms and room features.
7. Faculty availability, load, and qualifications.
8. Academic Calendar scheduling grid, blocked periods, and institutional break blocks.
9. Constraint catalog.
10. Solver run history.
11. Candidate schedules.
12. Schedule approval.
13. Published schedule versions.
14. Enrollment-to-schedule binding.
15. COR schedule reference.

Solver readiness must check:

1. Approved term calendar.
2. Approved curriculum.
3. Approved term offerings.
4. Section capacities.
5. Subject contact hours.
6. Room capacity and features.
7. Faculty availability and qualification.
8. Calendar grid, blocked periods, and institutional break blocks.
9. Constraint set.
10. Expected demand.
11. Regular cohort expected count fits the section capacity and selected physical room capacity.
12. Readiness validation is complete.

Hard constraints:

1. Room assignments are unique for each active time block.
2. Faculty assignments are unique for each active time block.
3. Section and cohort meeting times are conflict-free.
4. Student schedule bindings are conflict-free.
5. Required contact hours must be satisfied.
6. Term calendar, institutional break blocks, and blocked periods must be respected.
7. Room type and features must match subject needs.
8. Faculty availability and qualifications must be respected.
9. Faculty load uses the configured term limit or an approved Faculty Term Load Override.
10. Published schedule changes use the controlled schedule revision flow.
11. Large contact-hour components (e.g., 6-hour laboratory classes) must be scheduled in consecutive time blocks on a single day when the Course Component requires it.
12. A Face-to-Face section's expected regular cohort count must fit both its section capacity and assigned room capacity.
13. Same-faculty requirements across linked components must be respected when configured.

Constraint tiers:

1. **Fixed hard constraints** protect physical feasibility, academic validity, capacity, calendar blocks, break blocks, and safety. The system validates these for every solver output, manual assignment, live revision, and publication.
2. **Policy constraints** use source records such as Faculty Term Load Override, Course Specification same-faculty requirement, Term Offering overrides, and Academic Calendar availability. Authorized staff change the source record first, then rerun or revalidate the schedule.
3. **Soft preferences** rank valid schedules. They improve convenience and quality while keeping the schedule publishable when the fixed hard constraints pass.

Manual Schedule Override rules:

1. Manual Schedule Override is available after an infeasible result, invalid result, or institutionally unacceptable candidate schedule.
2. A valid Manual Schedule Override passes validation for room no-overlap, faculty no-overlap, required contact hours, physical room capacity, room suitability, section/cohort overlap, student time conflict, blocked calendar and break blocks, published-schedule audit requirements, and faculty qualification.
3. Faculty load uses the approved default limit or a Faculty Term Load Override recorded in Module 5 before validation.
4. Same-faculty requirements use the Course Specification Revision or authorized Term Offering override before validation.
5. Calendar or break-block conflicts are handled by updating the Academic Calendar or Scheduling Availability source record before validation.
6. A Manual Schedule Override records the affected constraint, actor, reason, authority, affected demand or meeting rows, and validation result.


Hard constraint source map:

| Constraint | Source Records |
| --- | --- |
| Assignment coverage | Term offerings, Scheduling Demand, section delivery groups |
| Room no-overlap | Rooms, room availability, time blocks, existing active assignments |
| Faculty no-overlap | Faculty profile, availability, active assignments |
| Section/cohort no-overlap | Section, cohort group, term offering, meeting pattern |
| Student no-overlap | Checked by TALA during enrollment for irregular students (solver only enforces cohort/section non-overlap) |
| Contact-hour completion | Course Specification Revision, Course Components, Scheduling Demand, term calendar time blocks |
| Calendar validity | Academic Calendar windows, holidays, no-class dates, break blocks, configured examination-period policy |
| Resource availability | Room availability, faculty availability, resource-specific blocked times |
| Room suitability | Room type, features, capacity, delivery modality |
| Faculty eligibility | Faculty-subject qualification mappings |
| Faculty load | Term setting default max units plus term-specific overload override |
| Capacity feasibility | Expected regular cohort count, section capacity, room capacity, delivery modality |
| Same-faculty requirement | Course Specification Revision component rule or authorized Term Offering override |
| Fixed assignment preservation | Published schedule version, fixed assignment inputs, revision events |
| Consecutive laboratory blocks | Course Specification Revision Course Component rules, Scheduling Demand consecutive flag |
| Manual Schedule Override eligibility | Constraint catalog, authorized role, override reason, affected Scheduling Demand or section meeting |

Soft constraints:

1. Prefer faculty requested times.
2. Prefer compact student and section schedules.
3. Reduce faculty idle gaps.
4. Balance faculty load.
5. Use rooms efficiently.
6. Reduce late and weekend schedules.
7. Minimize changes from previous published version.
8. Prefer earlier institutional time blocks when multiple valid assignments exist.

Soft constraint rules:

1. Soft constraints rank schedules that already pass fixed hard constraints.
2. V1 uses an approved default soft-priority preset.
3. Solver output includes enough score detail for Registrar review.
4. Authorized staff may publish a feasible schedule with lower soft-constraint quality and a recorded reason.
5. Manual Schedule Override may accept a lower soft-constraint score or relax a soft preference after fixed hard-constraint validation passes.
6. Soft constraints affect ranking among valid schedules.

CP-SAT modeling note:

CP-SAT works over integer variables, so TALA sends time as stable integer time-block IDs or minute offsets, not free-form time strings. Calendar and duration rules must be converted into integer time blocks before the payload is sent to the solver.

Institutional break blocks:

1. Break blocks are sent to the solver as unavailable time blocks for the configured scope.
2. Institution-wide breaks apply to all affected class meetings.
3. Room-specific, faculty-specific, or date-specific breaks apply only to the matching Scheduling Demand source records.
4. Approved make-up class blocks are treated as valid available time only for the authorized affected meetings.

Fixed Assignments and Pre-locking:

1. Staff can pre-fill or lock specific scheduling details (such as a specific room, faculty, or time block) for a Scheduling Demand before the solver runs.
2. Locked fields are treated as hard constraints (Fixed Assignments) by the solver, which must respect these choices while optimizing the remaining unassigned variables.
3. Modular or online modalities that have no weekly meetings or physical rooms are pre-marked as no-room or no-meeting demands.
4. TALA validates fixed assignments before solver execution and returns the failed source record when a fixed assignment conflicts with a fixed hard constraint.

Consecutive Block Scheduling:

1. For components requiring large contact hours (e.g., 6 hours or 12 half-hour blocks) that must be scheduled in a single day, the solver treats the class session as a single contiguous interval variable (using `NewIntervalVar`).
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
8. course_component_id or component_key
9. section_delivery_group_id
10. scheduling_demand_id or demand_key
11. room_id
12. faculty_id
13. time_slot_id
14. cohort_or_student_group_id
15. constraint_set_id

The solver uses official TALA IDs supplied in the input package.

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
6. course_component_id or component_key
7. faculty_id
8. room_id
9. day
10. start_time
11. end_time
12. time_slot_id or time_block_reference
13. meeting_pattern
14. assignment_status

#### 6.2.5 Solver Status Handling

When the solver returns `INFEASIBLE`, TALA presents a "Relaxation & Override" review path. The Registrar can fix source records, relax configured soft preferences, record approved policy overrides, rerun the solver, or create a Manual Schedule Override that passes fixed hard-constraint validation.

Manual override flow:

1. TALA lists infeasible or invalid constraints by source record.
2. Staff corrects source data when the issue is caused by missing rooms, faculty, contact hours, calendar windows, qualification, capacity, or demand setup.
3. Staff may relax the approved soft-priority preset or selected institutional policy constraints when the constraint catalog marks them overrideable.
4. Staff records the override authority, reason, affected Scheduling Demand or section meeting, and expected impact.
5. TALA revalidates the manually proposed assignment before it can become a candidate schedule.
6. TALA saves only assignments that pass fixed hard-constraint validation.

#### 6.2.6 Candidate Schedule Rule

Solver output is only a candidate schedule.

A candidate schedule becomes official only after:

1. TALA validates non-overrideable hard constraints and any recorded Manual Schedule Override scope.
2. Registrar reviews schedule.
3. Academic Head or authorized staff approves where required.
4. Schedule is published as a versioned schedule.

---

### 6.3. Schedule Publication and Mid-Term Schedule Revisions

TALA uses the AI Sandbox for pre-publication candidate schedules and In-Place Live Edits for post-publication revisions.

**Post-Publication (Mid-Term Edits):**
Once the schedule is published and the Enrollment Calendar opens, the schedule is live. If an operational change happens mid-term (e.g., a room floods, an instructor resigns, or a meeting time must move), the Registrar updates the affected live section meeting rows through a controlled revision form, and TALA validates the replacement before saving it.

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
3. **COR Handling:** CORs are dynamically rendered from the live `section_meetings` table. The next COR view in the Student Hub renders the current validated assignment.
4. **Notification:** Only affected faculty and affected students receive email notifications through the configured Laravel mail transport.
5. **Revision History:** The current state of `section_meetings` plus the `schedule_revision_events` log preserves schedule revision history.

Revision scope rules:

1. **Minor live revision:** A room replacement, faculty reassignment, modality correction, minor label correction, or time change for one affected meeting group may be edited directly if validation passes.
2. **Validation required:** Minor live revisions must recheck room no-overlap, faculty no-overlap, room capacity, room suitability, faculty qualification, contact-hour completion, blocked calendar periods, affected cohort/student conflicts, same-faculty requirements, and COR visibility.
3. **Structural schedule change:** Opening a new section, dissolving a section with enrolled students, splitting or merging sections, changing expected cohort allocation, changing Course Component contact-hour structure, or changing multiple cohort schedules requires a scheduling revision decision.
4. **Structural handling:** A structural schedule change requires a scheduling revision decision. Staff either rerun CP-SAT for the affected scope, manually create a validated replacement through Manual Schedule Override, or record an approved section cancellation only when no replacement schedule is needed.
5. **Section cancellation boundary:** `SECTION_CANCELLATION` is recorded as a revision event after the authorized structural decision and includes the approved academic handling for enrolled students, capacity, and contact-hour effects.
6. **Staff-triggered rerun:** A new solver run is a staff-triggered action when the Master Schedule structure needs re-optimization.

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
4. Solver output becomes official only through the publication action.

---

### 6.5. Scheduling Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Scheduling run scope and settings | Record Form selecting Term, included demands, constraint profile, and solver settings |
| Calendar grid and Institutional Break Blocks | Calendar / Date-Range Input sourced from Module 4 and shown in readiness validation |
| Scheduling Demand review | Editable Table of generated demands; course-owned values are read-only and only approved offering overrides, including same-faculty requirement, are editable |
| Faculty and room constraints | Read-only consolidated validation table linked to the authoritative forms in Module 5 |
| Start solver run | Read-only input summary and validation report followed by explicit run confirmation |
| Run progress and status | Generated Read-Only View showing queued/running/completed/infeasible/failed state and diagnostic summary |
| Candidate assignments | Review Table of section, course, faculty, room, day, start/end time, and validation status; a timetable/calendar view may supplement but not replace the table |
| Resolve an infeasible or invalid result | Exception list identifying the failed constraint and linking staff to the authoritative input record, soft-priority preset, approved policy override, or Manual Schedule Override form |
| Publish Master Schedule | Read-only comparison and conflict report followed by explicit publication confirmation |
| Revise published schedule | Focused Record Form selecting affected meeting rows, change reason, effective date, and approved replacements, followed by impact preview |
| CP-SAT integration settings | Restricted Record Form; credentials are stored by secure reference and shown only as masked status |

Candidate rows remain provisional until publication. Dragging or visually moving a timetable block, if later implemented, updates the same validated assignment fields and passes the same fixed hard-constraint validation.

---

### 6.6. Scheduling Surface Map

This map identifies how scheduling is surfaced for v1. It is not a visual design or page layout.

| Scheduling surface | Primary owner | Main interaction form | Purpose |
| --- | --- | --- | --- |
| Academic Calendar scheduling grid | Registrar or authorized staff | Calendar / Date-Range Input | Define operating days, hours, no-class dates, examination blocking behavior, and Institutional Break Blocks. |
| Room and facility setup | Registrar or authorized staff | Record Form and Editable Table | Maintain room capacity, room type, flat features, active status, and room-scoped unavailability. |
| Faculty qualification and availability | Registrar, Academic Head, or authorized staff | Editable Table and Calendar / Date-Range Input | Record approved subject qualification, unavailable blocks, preferred blocks, and term load inputs. |
| Term Offering builder | Registrar | Generated Editable Table | Create Regular offerings from Curriculum Entries and add approved offering-owned values. |
| Section delivery groups | Registrar | Editable Table | Define schedulable cohort or section groups and expected counts. |
| Scheduling Demand review | Registrar | Generated Review Table | Show the demand rows that CP-SAT will schedule, with source links and validation status. |
| Constraint profile | System Super Admin or authorized staff | Editable Table / Record Form | Maintain the fixed hard-constraint profile, policy constraints, and default soft-priority preset. |
| Readiness check | Registrar | Generated Read-Only validation table | Show missing inputs, invalid source records, and constraints that must be corrected before a solver run. |
| Solver run setup | Registrar | Record Form plus confirmation | Select term, included demands, constraint profile, and solver settings. |
| Candidate schedule review | Registrar and Academic Head where required | Review Table plus optional timetable/calendar view | Compare candidate assignments, constraint results, soft scores, and warnings before publication. |
| Manual Schedule Override | Registrar with required authority | Focused Record Form | Record a validated replacement assignment, authority, reason, affected rows, and validation result. |
| Master Schedule publication | Registrar / Academic Head where required | Confirmation with conflict summary | Publish validated candidate rows into official section meetings. |
| Published schedule revision | Registrar | Focused Record Form with impact preview | Change room, faculty, modality, time, or cancellation after publication while preserving revision history. |
| Student and faculty schedule visibility | Student Hub and Faculty Workspace | Generated Read-Only View | Show only published schedule records and authorized current assignments. |
