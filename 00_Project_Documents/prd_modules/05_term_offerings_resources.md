## 5. Term Offerings & Resources

---

### 5.1. Term Offering Builder

Term offerings convert approved curriculum into actual subjects and sections for one academic term.

Required inputs:

1. Academic Year
2. Term
3. Program
4. Curriculum Version
5. Year Level
6. Expected Student Count
7. Active Student Count
8. Section Capacity
9. Number of Sections
10. Curriculum Entry List and referenced Course Specification revisions
11. Delivery Modality
12. Room Requirement Override, only when the offering differs from the Course Specification default
13. Faculty Requirement if known
14. Same Faculty Required override for linked components, only when the term offering differs from the Course Specification default
15. Offering Category
16. Special Offering Reason, when applicable
17. Delivery Arrangement

Offering categories:

1. Regular
2. Special

Special Offering reasons:

1. Petitioned Demand.
2. Completion / Catch-up Need.
3. Graduating Student Need.
4. Other Authorized Institutional Reason.

Delivery arrangements:

1. Normal Class.
2. Tutorial, when an approved Special Offering has fewer students than the normal class minimum.

Section code default:

`PROGRAM-YEAR-SECTION`

Examples:

1. BSIT-1A
2. BSIT-1B
3. BSHM-2A

Rules:

1. Only approved curriculum versions can generate term offerings.
2. Section capacity is configurable.
3. Campus active-student ceiling defaults to 100 unless configured differently.
4. Inquiry, application, draft subject choices, or payment initiation do not secure a slot. Only a Registrar-confirmed Enrollment Seat Reservation or official enrollment consumes capacity.
5. A Registrar-confirmed Enrollment Seat Reservation consumes available capacity while enrollment is pending. Official enrollment converts that reservation without consuming capacity a second time.
6. `Irregular` describes a student's Academic Standing and individual-subject enrollment path; it is not a Term Offering category. Irregular students may enroll in compatible Regular or Special offerings.
7. Special Offerings are requested and approved within the configured Academic Calendar windows after demand, faculty, room, and institutional approval are checked.
8. Petitioned demand records the Special Offering request basis. Tutorial records the delivery arrangement for an approved small class. Completion/catch-up records the academic need. These attributes must not create separate scheduling workflows.
9. Summer is the Academic Term, not an Offering Category. Completion/catch-up offerings in Summer remain Special Offerings approved at school discretion.
10. Registrar staff handle cross-enrollment and external visiting students manually outside TALA. TALA keeps registration records only for admitted degree students or approved local Special Offerings.
11. Summer load cap defaults to 6–9 units unless configured differently. Student overload uses a Student Unit Load Exception; Academic Head approval and Registrar recording are the default unless configuration states otherwise.
12. For Face-to-Face classes, the section enrollment capacity is strictly capped at the scheduled room's physical capacity, and the system blocks any increase beyond this limit. Online classes have no physical room constraint and can be sized freely.
13. Each Term Offering inherits units, Course Components, grading profile, prerequisites, and default room requirements from its referenced Course Specification Revision.
14. The Term Offering owns the actual delivery modality and any authorized delivery or room override for that term; overrides do not mutate the Course Specification Revision or Curriculum Entry.
15. Lecture and Laboratory components within one Course Specification Revision remain one Term Offering and one enrollment line unless the institution defines separate subject codes or separate released grades.
16. Different qualified faculty may be assigned to linked Lecture and Laboratory components unless the Course Specification Revision or an authorized Term Offering override marks same faculty required.

Term offering states:

1. Pending Scheduling (Offering is created but lacks a room/time assignment)
2. Scheduled (Room and time have been assigned, either manually or via solver)
3. Cancelled (Offering dissolved due to lack of students or faculty)

---

### 5.2. Faculty Qualification, Availability, and Term Load Override

Faculty availability is an input to scheduling. TALA uses a simplified qualification and load model to reduce administrative overhead while preserving solver correctness.

**Faculty Qualifications:**
Stored as flat subject mappings. If a faculty member has an active mapping to a subject, they are qualified to teach it.

- **Fields:** Faculty ID, Subject ID, Active Status, Recorded By, Recorded At, Notes (optional).
- **Rules:**
  1.  Faculty may only be scheduled for subjects with active qualification mappings.
  2.  Qualification verification is conducted via external HR/dean processes; TALA records only the final approved qualification mapping, not the approval workflow.
  3.  Qualification evidence and approvals are handled outside TALA; TALA records the active subject-qualification result.
  4.  Solver readiness must fail/warn if no qualified faculty exists for a required subject.

**Faculty Load Management:**
Controlled by term configuration and term-specific override records.

- **Fields:** Faculty ID, Academic Year, Term, Standard Max Units Snapshot, Approved Overload Units, Reason, Override_Approved_By (optional), Recorded By, Recorded At, Active Status.
- **Rules:**
  1.  Default teaching load is configured per term (`term_settings.default_faculty_max_units`).
  2.  Faculty load uses course credit units by default.
  3.  Linked Lecture and Laboratory components for the same course do not double-count load unless the institution defines separate subject codes or separate released grades.
  4.  Allowed load = Default Max Units + Approved Overload Units (for that specific term).
  5.  Overload approval is processed via physical registrar/academic channels; TALA records only the final approved overload unit value.
  6.  Overload must **not** be stored as a permanent global faculty profile field.
  7.  The system rejects/warns against assignments exceeding the computed allowed load.
  8.  The CP-SAT solver receives only the derived `max_allowed_units`.
  9.  V1 faculty load uses course credit units and term-specific approved overrides.
  10. Overload requests and approvals are handled outside TALA; TALA records the approved term-specific override.
  11. All edits require authorized staff access and are auditable via product records and application audit logs.

---

### 5.3. Rooms and Facilities

Room records must support:

1. Room ID
2. Room Name
3. Room Type
4. Capacity
5. Features
6. Availability
7. Active / Inactive Status
8. Notes

Room types may include:

1. Lecture Room
2. Laboratory
3. Computer Laboratory
4. Special Room
5. Online / No Physical Room Required

Rules:

1. Face-to-Face offerings require room assignment.
2. Laboratory subjects require suitable room type and features.
3. Online offerings may use a non-physical room placeholder or no-room rule.
4. Active rooms are available for new schedules.
5. Temporary room unavailability and applicable Institutional Break Blocks remove affected time blocks from scheduling.
6. Room suitability is evaluated using flat Room Type, Capacity, and Features.
7. V1 room suitability uses flat room features for scheduling.

---

### 5.4. Registrar-Controlled Capacity and Seat Reservation

Capacity management is controlled through Registrar-confirmed academic placement.

1. **Regular Cohort Allocation:** Before schedule publication, each regular cohort section carries its expected student count. CP-SAT treats that count as capacity demand and assigns Face-to-Face sections to rooms that fit the expected count.
2. **Irregular Placement:** After schedule publication, an irregular student submits subject or section choices. TALA validates prerequisites, unit limits, time conflicts, and remaining capacity. The Registrar confirms the final section placement.
3. **Enrollment Seat Reservation:** Registrar confirmation creates a term-specific Enrollment Seat Reservation. The reservation consumes available capacity while the enrollment is pending and is converted into official enrollment after the remaining gates pass.
4. **Release Rule:** A reservation is released when the pending enrollment is cancelled, rejected, or reaches the institution-configured enrollment deadline. Payment status remains handled by the Finance Gate.
5. **Full Section Resolution:** When the preferred section is full, the Registrar chooses another compatible section, increases capacity within the scheduled physical room capacity, or creates an additional offering and reruns scheduling when demand justifies it.
6. **Full Section Handling:** V1 uses Registrar placement and capacity review for excess demand.
7. **Concurrency Rule:** Capacity confirmation must prevent two staff actions from reserving the same final seat.
8. **Lifecycle Release Rule:** An approved Subject Drop releases only the affected Student Schedule Binding. Withdrawal or a current-term Leave of Absence releases all affected bindings. These actions update enrollment capacity without modifying the Master Schedule.

---

### 5.5. Offering and Resource Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Build Regular Term Offerings | Generated Editable Table seeded from active Curriculum Entries; staff adjust only offering-owned values, including authorized same-faculty overrides when needed |
| Add a Special Offering | Record Form selecting course revision, reason, target population, estimated demand, modality, same-faculty override when needed, and approval reference |
| Section delivery groups and expected counts | Editable Table with program/cohort Selection Lists and validated non-negative counts |
| Faculty profile and qualification | Record Form plus Editable Table of approved course or discipline qualifications |
| Faculty availability | Calendar / Date-Range Input for unavailable and preferred time blocks |
| Faculty term load or approved override | Record Form showing computed load and capturing the authorized limit/exception, reason, authority, and evidence reference |
| Room and facility inventory | Record Form per room plus checklist/Selection List for room type and flat features |
| Room closure, temporary unavailability, or room-scoped break block | Calendar / Date-Range Input tied to an existing room |
| Capacity and current reservations | Read-only capacity table showing expected count, room capacity, reserved, officially enrolled, and remaining seats |
| Registrar section placement | Selection List of compatible published sections with visible conflict, prerequisite, unit, and remaining-capacity results; confirmation creates the reservation |

Actual modality, faculty, room, and term-specific overrides are edited on the Term Offering or scheduling inputs, not on the historical Curriculum Entry.

---
