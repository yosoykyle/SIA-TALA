# TALA Context

TALA is the academic lifecycle and administration system for the institution. This glossary defines the product language used across PRD modules, implementation slices, and future domain decisions.

## Language

**Student Hub**:
The authenticated student-facing area where a student views current academic status, finance status, holds, schedules, grades, and generated outputs.
_Avoid_: Student portal, generic dashboard

**Staff Workspace**:
The authenticated operational area for all institution employees (e.g., Registrar, Accounting, Academic Head) to manage queues, approvals, setup, and reports.
_Avoid_: Admin portal, backoffice, Filament panel

**Applicant Workspace**:
The authenticated area for pre-handover applicants to draft applications, submit checklist uploads, and view admission status.
_Avoid_: Applicant portal, pre-student hub

**Hold**:
An explicit student-affecting restriction record that states what workflow is blocked, why it is blocked, and what condition resolves or waives it.
_Avoid_: Hold flag, hidden block, computed-only restriction

**Admission Checklist Item**:
A flat applicant or student requirement record that captures the required document or credential condition, accepted evidence method, blocking effect, review result, and resolution state.
_Avoid_: Document workflow, nested document request, upload-only requirement

**TALA Result Record**:
The official record created or updated in TALA after an institution-handled office action affects the academic lifecycle.
_Avoid_: Offline-only action, undocumented office result

**Scheduling Demand**:
A schedulable need for one Course Component within a section delivery group for a term. It combines authoritative component contact-hour and default room requirements with the Term Offering's actual modality, approved overrides, faculty context, and capacity before CP-SAT turns it into candidate schedule rows.
_Avoid_: Raw subject scheduling, vague meeting requirement

**Course Specification Revision**:
An effective version of a course's authoritative code, title, units, course components, prerequisite/corequisite rules, grading profile, and default delivery requirements. Published revisions are immutable for historical use.
_Avoid_: Curriculum placement, Term Offering, silently edited subject

**Course Component**:
The lecture or laboratory part of one Course Specification Revision that may require its own contact hours, room need, faculty/load rule, and schedule row while remaining tied to one course enrollment and one released grade.
_Avoid_: Separate subject, duplicate Curriculum Entry, independent grade

**Curriculum Entry**:
The placement of one Course Specification revision within a Curriculum Version by year level, term, sequence, and required/elective grouping.
_Avoid_: Duplicate course definition, scheduled class, Term Offering

**Prerequisite Rule Set**:
The effective structured academic requirements a student must satisfy before enrolling in a target course. Groups are combined with AND, while approved alternatives inside a group are combined with OR; corequisites are recorded separately.
_Avoid_: Runtime free-text prerequisite, student certification, unrestricted rules engine

**Academic Exception**:
An authorized, student-specific and term-specific record allowing a defined academic rule to be treated differently for one target Term Offering. It preserves the failed rule, authority, reason, evidence reference, and scope rather than acting as a general bypass.
_Avoid_: Override flag, blanket waiver, permanent bypass

**Student Unit Load Exception**:
An authorized term-specific record allowing one student to exceed the configured normal unit load within an approved scope. It does not bypass prerequisites, conflicts, capacity, finance, or document gates.
_Avoid_: Overload toggle, automatic graduating privilege, schedule bypass

**TALA CSV Import Template**:
The system-issued, versioned CSV structure for one supported import type. It standardizes expected curriculum or Course Specification columns while all uploaded values remain subject to preview and domain validation.
_Avoid_: Arbitrary spreadsheet, column-mapping profile, direct database import

**Enrollment Gate**:
A specific validation checkpoint (e.g., Finance, Document, Capacity) that a student must pass before their enrollment becomes official. Gates yield explicit statuses like passed, failed, or overridden, rather than just returning form errors.
_Avoid_: Enrollment step, form validation, enrollment checklist

**Enrollment Status**:
The term-specific overall progress of one enrollment. Office ownership, blocking reasons, and review details belong to Enrollment Gate results rather than separate office-named enrollment statuses.
_Avoid_: Registrar review status, Accounting review status, duplicate gate status

**Enrollment Seat Reservation**:
A term-specific capacity claim recorded by the Registrar after section placement and academic validation but before payment clearance. It becomes part of official enrollment when the remaining gates pass, or is released when the pending enrollment is cancelled or reaches the institutional deadline.
_Avoid_: PayMongo seat lock, checkout timer, first-come payment race

**Master Schedule**:
The official set of section meetings for a term, including time, room, faculty, and delivery assignments. Individual student lifecycle changes do not modify it automatically.
_Avoid_: Student schedule, enrolled subject list

**Academic Calendar**:
The institution-wide academic year and term timeline that defines planning, offering, scheduling, enrollment, add/drop, classes, examinations, grade encoding, and finalization windows. Resource availability is configured separately.
_Avoid_: Faculty availability, room availability, program-specific calendar override

**Scoped Calendar Window**:
A named Academic Calendar window that applies to all students by default or to a simple configured scope such as year level, continuing students, graduating-review students, or a specific term process.
_Avoid_: Separate program calendar, hidden deadline, hardcoded year-level date

**Institutional Break Block**:
A configured Academic Calendar or Scheduling Availability block that removes selected time blocks from regular class scheduling for a defined day, date, term, room, faculty, or institutional scope.
_Avoid_: Student lifecycle status, enrollment gate, informal lunch note

**Special Offering**:
An institution-approved Term Offering outside the initially planned Regular offerings because of petitioned demand, completion or catch-up need, or graduating-student need. Tutorial is a delivery arrangement for a small approved Special Offering, not a separate academic term.
_Avoid_: Irregular class, Summer offering type

**Student Schedule Binding**:
The association between one student's official enrollment and sections already available in the Master Schedule. Subject drops, withdrawal, and leave normally change these bindings without rerunning CP-SAT.
_Avoid_: Master Schedule, candidate schedule

**Student Lifecycle Change**:
The Registrar-recorded result of an institution-approved Subject Drop, Withdrawal, Leave of Absence, or Program Shift. TALA applies the approved academic, capacity, COR, and finance effects but does not reproduce the institution's approval routing.
_Avoid_: Student self-service request, approval engine, automatic schedule regeneration

**Handover**:
The official transition where an approved applicant is converted into an active student. This process generates or reuses the official student number and activates Student Hub access.
_Avoid_: Student registration, account signup, applicant conversion

**Official Receipt (OR) Mapping**:
The process of linking a physical paper receipt reference (OR number) from the cashier to an existing digital payment record in TALA. This ensures the daily reconciliation audit is supported without double-crediting the student's ledger.
_Avoid_: Digital OR generation, double payment

**COR (Certificate of Registration)**:
The official source-derived output document representing a student's enrollment and schedule for a specific term. 
_Avoid_: Schedule printout, generic registration form

**Irregular Student**:
A student who selects individual subjects from a flat list because they do not have guaranteed placement in a progressing cohort block.
_Avoid_: Custom enrollee, off-block student

**CP-SAT Solver**:
The constraint-based scheduling engine (Google OR-Tools) used by TALA to generate candidate schedule rows by resolving room, faculty, and section demands.
_Avoid_: Auto-scheduler, magic button

**Manual Schedule Override**:
An authorized scheduling decision used only when solver output is infeasible or institutionally unacceptable. It may relax configured institutional preferences or selected policy constraints, but it cannot violate physical, academic, capacity, or safety constraints.
_Avoid_: Force schedule, ignore hard constraints, edit solver output

**Financial Accommodation**:
An Accounting-recorded arrangement that documents the approved basis, covered balance, payment commitment, and exact finance restrictions affected for a student who cannot pay on the normal schedule. Its effects are explicit and do not automatically extend to enrollment or credential release.
_Avoid_: Global finance bypass, automatic clearance, generic payment plan

**Finance Gate**:
The enrollment gate that checks whether the required enrollment payment or downpayment has been posted to the student's ledger, or whether an active Financial Accommodation explicitly allows enrollment for the covered term.
_Avoid_: PayMongo success status, pending OR mapping, manual paid toggle

**Promissory Note**:
A written and signed promise to pay a stated amount on demand or at a determinable time. It may support a Financial Accommodation but is not itself an approval workflow or a blanket hold waiver.
_Avoid_: Financial Accommodation, exam permit, automatic enrollment clearance

**Batch Credit UI**:
A low-friction interface used by the Registrar to quickly check off credited subjects (distinguishing between external transfer credits and internal program shift credits) based on an approved paper evaluation, bypassing the need for a global external-course mapping engine.
_Avoid_: Global equivalency engine, credit transfer wizard

**Period Equivalent**:
The final computed grade for a specific academic period (e.g., Prelim Equiv, Midterm Equiv, Final Equiv) entered into TALA by the faculty. TALA does not store raw quiz or exam scores.
_Avoid_: Raw score, computed grade, partial grade

**Grade Outcome**:
The released academic result or controlled non-final mark for one student's enrollment in one course. It determines whether the course is passing, failed, incomplete, pending, withdrawn, or credited for prerequisite, GWA, and curriculum-completion checks.
_Avoid_: Raw gradebook score, untyped text mark, hidden completion flag

**Pending Grade**:
A temporary administrative grade outcome used when the faculty grade is not yet finalized or encoded. It is not a pass, fail, or incomplete result, and does not satisfy prerequisites unless a scoped Academic Exception exists.
_Avoid_: Passing grade, failed grade, student incomplete

**Graduation Review Batch**:
A Registrar-created review set of students whose completion status should be checked for a term. The batch selects students for review; it does not approve graduation.
_Avoid_: Graduation approval, automatic candidate finder, diploma workflow

**Graduation Eligibility Snapshot**:
A generated checklist comparing one student's assigned Curriculum Version against Grade Outcomes, credits, current enrollments, exceptions, holds, and clearance records. Staff refreshes it from source records rather than editing the result directly.
_Avoid_: Manual eligible flag, graduation planner, credential release workflow
