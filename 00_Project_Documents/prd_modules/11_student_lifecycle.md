## 11. Student Lifecycle Status & Holds

---

### 11.1. Student Status Model

Student state must be separated into:

1. Primary Student Lifecycle Status
2. Enrollment Status
3. Academic Standing
4. Active Holds

#### 11.1.1 Primary Student Lifecycle Status

Only one should be current at a time.

Allowed values:

1. Applicant
2. Approved for Handover
3. Active
4. Leave of Absence
5. Dropped
6. Withdrawn
7. Inactive
8. Archived
9. Reactivation Pending
10. Transferred Out
11. Completed / Graduated
12. Closed

`Dropped` is reserved for an institution-directed termination of active study under approved policy. A student-approved exit from all current-term subjects uses `Withdrawn`. A Subject Drop is recorded against the affected subject enrollment and does not by itself change the primary lifecycle status.

#### 11.1.2 Enrollment Status

Term-specific values:

1. Not Started
2. Pending Review
3. Capacity Pending
4. Payment Pending
5. Ready for Official Enrollment
6. Officially Enrolled
7. Cancelled
8. Dropped
9. Withdrawn

Office ownership and detailed blocking reasons are represented by Enrollment Gate results. Enrollment Status remains the student's overall term-level progress.

#### 11.1.3 Academic Standing

Allowed values:

1. Regular
2. Irregular
3. Probationary
4. Deficient
5. Blocked by Prerequisite
6. Must Repeat Year Level
7. Completion Candidate
8. Graduation Candidate
9. Not Yet Evaluated

Example:

Primary Lifecycle Status: Active
Enrollment Status: Officially Enrolled
Academic Standing: Irregular
Active Hold: Documentary Hold

`Capacity Pending` means the Registrar has not yet confirmed a compatible section with available capacity.

Academic Standing rules:

1. `Irregular` means the student is not following the standard Curriculum Version sequence.
2. `Completion Candidate` means staff has identified the student for completion review because remaining requirements appear limited or operationally reviewable.
3. `Graduation Candidate` means the student is included in a Graduation Review Batch or has a current Graduation Eligibility Snapshot ready for Registrar review.
4. Academic Standing uses Curriculum Version completion and source records instead of year-level labels alone.
5. TALA shows source-record facts and blockers for staff action on overload, Special Offering, and graduation review.

---

### 11.2. Holds

Holds are explicit records, not lifecycle statuses, hidden booleans, or computed-only flags. A hold states the blocked workflow, reason, and resolution or waiver condition.

Hold types:

1. Financial Hold
2. Documentary Hold
3. Behavioral Hold
4. Disciplinary Hold
5. Academic Deficit Hold
6. Prerequisite Hold
7. Enrollment Hold
8. COR Download Hold
9. Clearance Hold
10. Graduation Eligibility Hold
11. Reactivation Hold
12. Transfer-Out Hold
13. Record Release Hold

Hold fields:

1. Hold Type
2. Blocking Level
3. Source Record
4. Reason
5. Staff-Only Reason, if applicable
6. Student-Facing Message, if visible in Student Hub
7. Created By
8. Created At
9. Effective Term
10. Expiration Date, if applicable
11. Resolution Requirement
12. Resolved By
13. Resolved At
14. Waived By
15. Waived At
16. Status

Blocking levels:

1. Blocks Enrollment
2. Blocks COR Print
3. Blocks Clearance
4. Blocks Record Release
5. Blocks Graduation Eligibility
6. Blocks Reactivation
7. Advisory Only

Rules:

1. Holds must be explicit records, not hidden booleans.
2. Holds must state what they block and how to resolve them.
3. Hold lifting must be auditable.
4. **RA 11984 Compliance:** Financial holds apply to next-term enrollment, clearance, and record-release workflows according to institutional policy and applicable law. Examination, exam-permit, and regular class access follow TALA's v1 institutional policy and applicable law.
5. Student Hub may show simplified hold information, while internal notes remain staff-only.
6. When determining eligibility, the most restrictive active blocking hold wins.
7. **Financial Accommodation Scope:** An active Financial Accommodation affects the finance restrictions explicitly recorded in its approved effects.
8. A hold may be created from a checklist item, ledger balance, lifecycle action, correction, graduation evaluation, or authorized staff action.
9. Hold status values are `Active`, `Resolved`, `Waived`, and `Expired`.
10. **Central Table Mandate:** Access checks evaluate the central `holds` table together with any explicit active Financial Accommodation effect.

---

### 11.3. Recorded Student Lifecycle Changes

Subject Drop, Withdrawal, Leave of Absence, and Program Shift use a recorded-result model. The institution receives, reviews, and approves the student's request through its published office procedure. TALA records only the approved result and applies it consistently to official SIS records.

Common Student Lifecycle Change fields:

1. Change ID.
2. Student ID.
3. Academic Year and Term.
4. Change Type.
5. Reason Category and Concise Reason.
6. Requested Date, if present on the approved source record.
7. Effective Date or Effective Term.
8. Decision Authority.
9. Decision Date.
10. Private Source-Form or Approval Reference, when required.
11. Recorded By and Recorded At.
12. Affected Enrollment, COR, Assessment, or Ledger References.
13. Late-Exception Authority and Reason, when applicable.
14. Audit Metadata.

Allowed change types and rules:

1. **Subject Drop:** The student must be officially enrolled in the subject, the institutional deadline must be satisfied or an authorized late exception recorded, and no final Grade Outcome may already be posted. Record the subject enrollment, effective date, administrative class standing when policy requires it, and fee/refund effect.
2. **Withdrawal:** Applies to all current-term subject enrollments. Record the effective date, affected subjects, administrative class standings or lifecycle-derived withdrawn Grade Outcome labels when required, remaining-balance or refund effect, and capacity release.
3. **Leave of Absence:** Record the approved start term, expected return term, duration, return conditions, and any current-term withdrawal effect. The duration must remain within the institution-configured limit unless an authorized exception is recorded.
4. **Program Shift:** Requires target-program acceptance, approved credit evaluation, and a future effective term. Record old and new program/curriculum assignments, accepted and deficient subjects, and fee impact.

Application flow:

1. Registrar selects the approved change type and affected records.
2. TALA checks the configured window, required source reference, and type-specific preconditions.
3. TALA previews student enrollment, capacity, COR, assessment, ledger, hold, and curriculum effects.
4. Registrar records the decision authority and confirms the change.
5. TALA applies the approved changes atomically and creates the audit event.
6. Student Hub shows the resulting student-facing status and current records.

Schedule rules:

1. Student Lifecycle Changes modify Student Schedule Bindings, not the Master Schedule.
2. Subject Drop releases only the affected section seat.
3. Withdrawal or current-term Leave of Absence releases all affected current-term section seats.
4. Program Shift becomes effective in a future term and does not rewrite the current schedule or historical curriculum assignment.
5. Student Lifecycle Changes update Student Schedule Bindings; Master Schedule changes use the scheduling revision or solver-run workflow.
6. Aggregate demand changes that require opening or cancelling a section, or changing room, time, or faculty, use a separate Master Schedule revision or solver run.

#### 11.3.1 Graduation Eligibility

1. Graduation eligibility is a generated evaluation snapshot used for Registrar review.
2. Staff resolve or record the source records that feed the snapshot.
3. The system checks the assigned Curriculum Version, finalized Grade Outcomes, accepted credits, current enrollments, failed or missing subjects, pending grades, INC, withdrawn or dropped subjects, academic deficit holds, finance clearance, document clearance, behavioral / disciplinary clearance, and approved exceptions.
4. Program duration comes from the assigned Curriculum Version. The snapshot must support three-year, four-year, or other approved program structures without hardcoded year-level assumptions.
5. Credential preparation, diploma, TOR, Form 137, courier, and claiming workflows are handled by Registrar office procedures.

Graduation Review Batch flow:

1. Registrar creates a Graduation Review Batch for an academic year and term.
2. Registrar adds students manually for v1. Simple filters such as program, curriculum version, current year-level label, academic standing, or remaining-units threshold may assist selection.
3. TALA generates or refreshes a Graduation Eligibility Snapshot for each included student.
4. Staff reviews blockers and resolves source records outside the snapshot, such as grades, credits, holds, finance, document clearance, or approved Academic Exceptions.
5. Registrar refreshes the snapshot after source records change.

Snapshot result statuses:

1. Complete.
2. Ready for Registrar Review.
3. Blocked: Missing Requirement.
4. Blocked: Failed Requirement.
5. Blocked: Pending Grade.
6. Blocked: INC.
7. Blocked: Hold or Clearance.
8. Blocked: Current Enrollment Not Finalized.

Snapshot output fields:

1. Student and program.
2. Assigned Curriculum Version.
3. Completed requirements.
4. Currently enrolled requirements.
5. Missing requirements.
6. Failed requirements.
7. Pending Grade requirements.
8. INC requirements.
9. Withdrawn or dropped requirements.
10. Accepted transfer or internal credits.
11. Approved Academic Exceptions.
12. Active holds and clearance blockers.
13. Remaining units.
14. Generated At and Generated By.
15. Source record references.

Rules:

1. Graduation Review Batch membership is a review list for snapshot generation and Registrar review.
2. Graduation Eligibility Snapshot is read-only and regenerated from source records.
3. TALA shows completion blockers from source records for staff review.
4. Irregular students are evaluated against the same assigned Curriculum Version requirements as regular students.
5. If an irregular student's remaining requirements are all completed, credited, currently enrolled, or cleared by approved source records, the student may become Ready for Registrar Review.
6. Graduation Eligibility Snapshot visibility defaults to staff-only. Registrar may expose a simplified student-facing view when institution policy allows it.

#### 11.3.2 Account Reactivation

1. If a student leaves the institution with an outstanding balance at the end of an academic year, their primary status is set to `Archived`.
2. This archival automatically creates a `Financial Hold` on their profile with blocking level `Blocks Enrollment`.
3. To reactivate the account, the student must either pay the outstanding debt in full or have an active Financial Accommodation that explicitly allows reactivation or next-term enrollment.
4. Once the `Financial Hold` is resolved, the Registrar clicks a single "Reactivate" button to return the student's primary status to `Active`, allowing them to enlist.

---

### 11.4. Program Shift Credit Evaluation

Program shift requires an institution-approved academic evaluation before the future curriculum assignment is applied. V1 records the approved evaluation result.

Required fields:

1. Evaluation ID.
2. Student ID.
3. Old Program and Curriculum Version.
4. New Program and Curriculum Version.
5. Completed Subjects.
6. Accepted or Equivalent Subjects.
7. Deficient Subjects.
8. Rejected Credits, when applicable.
9. Effective Future Term.
10. Decision Authority and Decision Date.
11. Private Source-Form or Approval Reference, when required.
12. Fee Impact.
13. Recorded By and Recorded At.
14. Audit Metadata.

States:

1. Recorded Approved.
2. Applied.
3. Cancelled.

Rules:

1. Program shift applies through an authorized recorded evaluation result.
2. Accepted and deficient subjects must be recorded.
3. Equivalency must use approved equivalency records.
4. Fee changes must use assessment recalculation or ledger adjustment.
5. Prior academic history remains preserved.
6. Graduation eligibility must use the approved post-shift curriculum assignment.
7. The shift becomes effective in a future term. Schedule changes use the scheduling revision or solver-run workflow when needed.

---

### 11.5. Student-Lifecycle Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Student lifecycle and academic standing | Generated Read-Only View derived from official records; authorized transitions use focused actions |
| Holds | Operational table with one hold per row and a Record Form for type, blocking effects, reason, source, dates, owner, and resolution condition |
| Record Subject Drop, Withdrawal, Leave of Absence, or Program Shift | Record Form selecting the approved change type, student, affected enrollment/subjects, effective date/term, authority, reason, and private reference |
| Lifecycle effect review | Read-only impact preview covering Student Schedule Bindings, capacity, COR, assessment/ledger, holds, status, and curriculum before application |
| Program Shift Credit Evaluation | Target-curriculum checklist table showing completed, accepted, deficient, and equivalent subjects, with source grade/credit treatment |
| Graduation Review Batch | Operational Review Table where Registrar adds students manually or through simple filters, then refreshes generated snapshots |
| Graduation Eligibility Snapshot | Generated checklist table comparing the assigned Curriculum Version against released grades, credits, current enrollments, holds, and approved exceptions; result is read-only |
| Account reactivation | Focused Record Form with eligibility result, authority, reason, and effective date |

V1 records approved lifecycle results. Student Hub shows student-facing status after Registrar recording; office approval routing remains an institutional procedure.

---
