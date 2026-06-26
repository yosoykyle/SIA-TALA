## 4. Academic Setup

---

### 4.1. Academic Calendar and Term Rules

Supported terms:

1. First Semester
2. Second Semester
3. Summer / Special Term, when offered. The Summer Term can only be activated in TALA after the Second Semester grade encoding period has closed, ensuring complete student academic histories are available.

Default scheduling grid:

1. Monday to Saturday.
2. 7:00 AM to 8:00 PM.
3. 30-minute base blocks.
4. Sunday blocked by default.
5. Holidays and no-class days block scheduling.
6. Time rules must be configurable.

Term setup must exist before curriculum planning, scheduling, enrollment, or grade periods are activated.

Institution-wide calendar rules:

1. Calendar periods, terms, and date rules are strictly institution-wide.
2. If a specific subset of programs (e.g., Graduate School) requires different academic dates, they are modeled under a distinct Academic Term record (e.g., "Graduate School - First Semester") rather than program-specific calendar overrides within the same term.

Calendar effects:

1. Scheduling uses the academic calendar to generate valid time blocks and exclude holidays, no-class days, exam periods, make-up class blocks, room closures, and faculty unavailable periods.
2. Enrollment uses the term calendar to determine enrollment windows, late-enrollment handling, capacity deadlines, payment due dates, and official enrollment cutoffs.
3. Assessment uses the active term and enrollment timing to apply downpayment rules, late enrollment fees, installment schedules, and term-specific fee configuration.
4. Grade encoding uses the academic calendar to open and close faculty grade-entry windows.
5. Student Hub visibility uses the active term to decide current schedule, current COR, current SOA, released grades, and active holds.
6. Reports and exports use academic year and term as primary filters for operational records.

Calendar exceptions:

1. Holiday
2. No-class day
3. Exam period
4. Make-up class block
5. Room closure
6. Faculty unavailable period

(TALA maintains standard calendar exception records for operational scheduling. Institutional class suspensions or temporary closures are managed outside TALA by school administration, and TALA records remain bound to the official term calendar.)

Exam and deadline rules:

1. Exam periods on the calendar exception list block regular class scheduling for those dates.
2. Payment due dates and grade encoding windows are configured as explicit, absolute dates on the Term calendar setup, rather than being computed dynamically from exam date ranges.

---

### 4.2. Delivery Modality

Supported delivery modality values:

1. Online
2. Face-to-Face
3. Modular

Recommended architecture field:

`delivery_modality`

Allowed enum values:

1. `ONLINE`
2. `FACE_TO_FACE`
3. `MODULAR`

Rules:

1. Modality must be modeled separately from payment status.
2. Payment status must not contain modality values.
3. Online classes do not require physical room assignment.
4. Face-to-Face classes require physical room assignment.
5. Modular classes may require staff handling. Modular packet distribution is handled through classroom or office procedures.
6. Modality may affect fee computation only if Accounting configures modality-based fee rules.

---

### 4.3. Course Catalog

Course Catalog defines what a subject is.

Required fields:

1. Subject Code
2. Subject Title
3. Description
4. Units
5. Lecture Hours
6. Laboratory Hours
7. Total Contact Hours
8. Component Type
9. Grading Profile
10. Prerequisites
11. Corequisites
12. Equivalent Subjects
13. Required Room Type
14. Required Room Features
15. Allowed Delivery Modalities
16. Active / Inactive Status
17. Effective Term
18. Revision Notes

Rules:

1. Subject codes must be unique.
2. Prerequisites and corequisites must reference existing subjects.
3. Circular prerequisites are blocked.
4. Contact hours must be complete before scheduling.
5. Course catalog revisions must not silently mutate historical schedules, enrollments, or grades.

---

### 4.4. Course Equivalency & Batch Credit UI

Transfer and program-shift crediting use a low-friction **"Batch Credit UI"**. The Registrar opens the student's target curriculum and rapidly checks off credited subjects in one pass based on the approved paper evaluation. Approved credited subjects satisfy prerequisite checks without requiring a global external-course mapping engine.

**Differentiated Credit Model:**
To maintain institutional GWA integrity, credited subjects are stored under two distinct rules in the student's academic history:

1. **External Transfer Credits (Transferees):** Mapped with a grade of `TC` (Transfer Credit). These satisfy prerequisites and degree checklist requirements, but **are strictly excluded** from the student's cumulative General Weighted Average (GWA) calculations.
2. **Internal Program Shift Credits (Internal Shifters):** Mapped with their original numeric grades (1.00 to 5.00 scale) earned at the institution. These satisfy degree requirements and **are included** in the GWA calculations.

The Batch Credit UI must provide a toggle for the Registrar to designate each credited subject as either External (TC) or Internal (Numeric Grade), preventing GWA inflation during graduation clearance and honors audits.

---

### 4.5. Curriculum Creation and Management

The client-provided curriculum is a completed table by program, year level, and term. It gives the academic subject plan, not operational scheduling details.

Client curriculum source columns:

1. Academic grouping or term block, such as First Year / First Semester.
2. Subject Code.
3. Subject Title.
4. Total Units.
5. Units.
6. Prerequisite.

TALA adapts this curriculum into one curriculum encoding table. The table starts with the client curriculum columns and adds the minimum scheduler-needed columns in the same screen.

TALA curriculum encoding table:

| Column Group | Columns |
| --- | --- |
| Client curriculum fields | Academic grouping / term block, Subject Code, Subject Title, Total Units, Units, Prerequisite |
| TALA scheduling fields | Weekly Contact Hours, Scheduling Group, Delivery Rule Override, Room Type Needed if applicable |

Rules:

1. Staff work in one curriculum encoding table instead of separate curriculum and scheduling-enrichment screens.
2. TALA must preserve which fields came from the client curriculum and which fields were added by TALA for scheduling readiness.
3. TALA must not require the uploaded client file to contain scheduler-specific headers.
4. Staff may fill scheduler-needed columns during encoding, import cleanup, or review in the same table.
5. A curriculum version cannot become scheduling-ready until scheduler-needed columns are complete for subjects that require automatic scheduling.

Curriculum versions are the strict source of truth for program subject structure, units, prerequisites, co-requisites when available, year-level progression, and graduation path. Course Catalog and term configuration supply additional operational fields needed for scheduling and assessment.

Curriculum information is shared across modules as follows:

1. Term offerings are generated from the active approved curriculum version for a program, year level, and term.
2. CP-SAT scheduling receives Scheduling Demand derived from curriculum subjects, Course Catalog enrichment, section delivery groups, faculty eligibility, room configuration, calendar, and term setup.
3. Enrollment gates use curriculum assignment to validate prerequisites, eligible subjects, year level progression, irregular subject selection, and graduation path.
4. Assessment uses enrolled subjects, units, laboratory indicators, delivery modality, and term fee configuration. Curriculum supplies the academic structure; fee rules determine the charge amounts.
5. COR and SOA derive from official enrollment, published schedule, assessment, and ledger records; they do not invent curriculum data.
6. Grades and graduation eligibility use the student's assigned curriculum version to determine completed, failed, deficient, credited, and remaining subjects.

Rules:

1. Curriculum encoding must not require uploaded files to contain headers that do not exist in the client-provided curriculum table.
2. Weekly contact hours, scheduling group, delivery rule override, and room type needed are TALA scheduling fields shown in the same curriculum encoding table.
3. Missing scheduling fields may block scheduling readiness, but they must not block saving a draft curriculum.
4. TALA should preserve the original curriculum table meaning while adding the minimum operational fields needed for enrollment, scheduling, assessment, grades, and graduation evaluation.

**Flattened Workflow:**
Academic Head curriculum approval happens through institutional review outside TALA. After approval, the Registrar or Admin records the approved curriculum in TALA and uses a direct "Set Active Curriculum" function to activate it.

Curriculum version states:

1. `Draft`: being encoded or imported.
2. `Recorded Approved`: approved outside TALA and recorded in TALA.
3. `Active`: default curriculum for new handovers in that program.
4. `Superseded`: replaced by a newer active curriculum.
5. `Archived`: retained for history and not selectable for new handovers.

Rules:

1. V1 does not route Academic Head curriculum approval inside TALA.
2. TALA records the approved curriculum result and activation action.
3. Only one curriculum version per program may be `Active` for new handovers at a time.
4. Activating a new curriculum version supersedes the previous active version for future handovers only.

**Curriculum Locking & Cohort Rules:**

1. **Entrance Lock:** When an applicant undergoes official handover, their student master profile is permanently bound to the `curriculum_version_id` active at their time of entry (e.g., BSIT v2024).
2. **Supersession Scope:** Setting a new curriculum version to "Active" only changes the default version mapped to newly onboarding applicant intakes.
3. **Immutability:** Continuing students remain bound to their entry curriculum version throughout their degree track. The system prevents dynamic global curriculum updates from altering a continuing student's mapped version.
4. **Amendment Gate:** Any mid-stream curriculum track changes for a student must be explicitly routed through a formal **Program Shift Credit Evaluation** workflow. This ensures that historical course checklist audits, prerequisite validations, and graduation audits remain aligned to the student's legal entry cohort requirements.

The system enforces these curriculum rules during enrollment and scheduling. TALA records the approved curriculum result without routing the paper approval itself.

---
