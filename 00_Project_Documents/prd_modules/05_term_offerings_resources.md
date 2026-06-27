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
10. Subject List
11. Delivery Modality
12. Room Requirements
13. Faculty Requirement if known
14. Offering Type

Offering types:

1. Regular
2. Irregular
3. Petitioned
4. Summer Recoup
5. Tutorial / Special Class if approved

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
4. Inquiry, application, or unpaid registration does not secure a slot.
5. Official enrollment consumes capacity.
6. TALA processes regular, irregular, petitioned, summer recoup, and tutorial/special class offering types. Registrar staff handle cross-enrollment and special external visiting students manually outside TALA. TALA keeps registration records only for students admitted to degree programs or approved local tutorial/special classes.
7. Summer recoup is offered only by school discretion. Academic Heads decide which recoup subjects are offered, and Registrar staff encode these approved offerings in TALA.
8. Summer load cap defaults to 6–9 units unless configured differently. Academic Heads approve summer load overrides (e.g., for graduating students), and Registrar staff record the approved overrides in TALA.
9. For Face-to-Face classes, the section enrollment capacity is strictly capped at the scheduled room's physical capacity, and the system blocks any increase beyond this limit. Online classes have no physical room constraint and can be sized freely.

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
  2.  Allowed load = Default Max Units + Approved Overload Units (for that specific term).
  3.  Overload approval is processed via physical registrar/academic channels; TALA records only the final approved overload unit value.
  4.  Overload must **not** be stored as a permanent global faculty profile field.
  5.  The system rejects/warns against assignments exceeding the computed allowed load.
  6.  The CP-SAT solver receives only the derived `max_allowed_units`.
  7.  Overload requests and approvals are handled outside TALA; TALA records the approved term-specific override.
  8.  All edits require authorized staff access and are auditable via product records and application audit logs.

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
4. Inactive rooms cannot be used for new schedules.
5. Temporary room unavailability must block scheduling.

---

### 5.4. Capacity Management and Overflow Requests

Capacity management separates cohort-reserved seats from irregular buffer slots to prevent race conditions. Registrar staff resolve overflow demand through capacity adjustment or a new scheduling run.

1. **Capacity Allocation:**
   - **Cohort Reservation Slots:** When a section is created for a regular class cohort (e.g., BSIT-2A), the system automatically pre-allocates and reserves seats matching the size of the progressing regular student cohort. These slots are locked and can only be claimed by students in that specific cohort block.
   - **Buffer Slots:** The remaining capacity in a section (Total Capacity minus Cohort Reservation Slots) is designated as the buffer. This is open to irregular and transferee students on a first-come, first-served basis.
2. **Database-Backed Soft Locks:** When an irregular student registers for a subject and chooses a section, the system validates the available buffer capacity. Upon clicking "Pay via PayMongo", the system creates a 15-minute database-backed soft lock on that buffer slot. If checkout is completed within 15 minutes, the slot becomes permanent; otherwise, the lock expires and the slot is released.
3. **Overflow Requests & Capacity Adjustments:** If all buffer slots for a section are full, irregular students can click a "Request Overflow Slot" button. The Registrar monitors these tallies in the workspace queue and can resolve them by:
   - Manually increasing the section capacity (e.g., from 45 to 48) to allow students to "sit in" on the section, provided the increase does not exceed the scheduled room's physical capacity for Face-to-Face classes.
   - Running the CP-SAT solver to generate a new optimized section if overall term demand justifies it.

---
