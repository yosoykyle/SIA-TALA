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

#### 11.1.2 Enrollment Status

Term-specific values:

1. Not Started
2. Pending Gates
3. Payment Pending
4. Capacity Pending
5. For Registrar Review
6. For Accounting Review
7. For Academic Review
8. For Irregular Scheduling
9. Ready for Official Enrollment
10. Officially Enrolled
11. Cancelled
12. Dropped
13. Withdrawn
14. Superseded

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

---

### 11.2. Holds

Holds are explicit records, not lifecycle statuses, hidden booleans, or computed-only flags. A hold states what workflow is blocked, why it is blocked, and what condition resolves or waives it.

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
4. **RA 11984 Compliance:** Financial holds must never block examinations, exam permit printing, or regular class access. Financial delinquencies instead trigger next-term enrollment blocks and document request holds (blocking requests for TOR, Certifications, or Transfer Credentials).
5. Student Hub may show simplified hold information, while internal notes remain staff-only.
6. When determining eligibility, the most restrictive active blocking hold wins.
7. **Promissory Note Bypass:** An `ACTIVE` promissory note dynamically bypasses finance-related next-term enrollment and finance-related document holds (allowing the student to register or request credentials while their deferred debt is managed), but does not bypass academic, document, or disciplinary holds.
8. A hold may be created from a checklist item, ledger balance, lifecycle action, correction, graduation evaluation, or authorized staff action.
9. Hold status values are `Active`, `Resolved`, `Waived`, and `Expired`.
10. **Central Table Mandate:** Hardcoded hold checks on models or services (e.g., `hasActiveFinancialHold`, `hasPromissoryHold`) are strictly forbidden. All access checks must query the central `holds` table directly.

---

### 11.3. Student Lifecycle Workflows

Drop Subject, Full Drop, Leave of Absence, and Program Shift use a single-step request and flat approval model.

These workflows follow a "Single-Step Request & Flat Approval" model:

1. The student initiates a pending request digitally via the Student Hub.
2. The student completes required office clearance outside TALA (e.g., visiting Accounting or Dean for physical signatures or verbal approval).
3. The Registrar executes the final drop, shift, or LOA via a single backend override button, which updates the student/enrollment status, records COR impact where applicable, and applies the necessary ledger adjustments.

#### 11.3.1 Graduation Eligibility

1. Graduation eligibility is an evaluation snapshot, not credential issuance.
2. The system checks curriculum completion, finalized grades, failed or missing subjects, INC / DRP, academic deficit holds, finance clearance, document clearance, behavioral / disciplinary clearance, and approved exceptions.
3. Credential preparation, diploma, TOR, Form 137, courier, and claiming workflows are handled by Registrar office procedures.

#### 11.3.2 Account Reactivation

1. If a student leaves the institution with an outstanding balance at the end of an academic year, their primary status is set to `Archived`.
2. This archival automatically creates a `Financial Hold` on their profile with blocking level `Blocks Enrollment`.
3. To reactivate the account, the student must either pay their outstanding debt in full at the Cashier (which brings the balance to ₱0.00 and resolves the hold) or get a Promissory Note approved.
4. Once the `Financial Hold` is resolved, the Registrar clicks a single "Reactivate" button to return the student's primary status to `Active`, allowing them to enlist.

---

### 11.4. Program Shift Credit Evaluation

Program shift requires academic evaluation before new curriculum assignment is finalized.

Required fields:

1. Evaluation ID
2. Student ID
3. Old Program
4. New Program
5. Old Curriculum Version
6. New Curriculum Version
7. Completed Subjects
8. Accepted Subjects
9. Equivalent Subjects
10. Deficient Subjects
11. Rejected Credits
12. Fee Impact
13. Effective Term
14. Evaluated By
15. Approved By
16. Status
17. Audit Metadata

States:

1. Draft
2. Under Registrar Review
3. For Academic Head Review
4. Approved
5. Rejected
6. Superseded
7. Cancelled

Rules:

1. Program shift cannot silently move a student to a new curriculum.
2. Accepted and deficient subjects must be recorded.
3. Equivalency must use approved equivalency records.
4. Fee changes must use assessment recalculation or ledger adjustment.
5. Prior academic history remains preserved.
6. Graduation eligibility must use the approved post-shift curriculum assignment.

---
