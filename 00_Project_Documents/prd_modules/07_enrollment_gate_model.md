## 7. Enrollment Gate Model & Execution

---

### 7.1. Enrollment Gate Model

Enrollment is a gated transaction, not a form submission.

Gate categories:

1. Identity Gate
2. Admission or Student Status Gate
3. Document Gate
4. Finance Gate
5. Academic Progression Gate
6. Capacity Gate
7. Section / Schedule Placement Gate
8. Conflict Gate
9. Final Approval Gate

Gate results:

1. Not Checked
2. Passed
3. Failed
4. Pending Review
5. Waived
6. Overridden
7. Not Applicable

Enrollment statuses:

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

Document Gate rules:

1. Document Gate must evaluate checklist requirements by blocking level.
2. Document Gate must not fail because a non-blocking retention requirement remains open.
3. Document Gate passes when all enrollment-blocking checklist items are accepted, verified, waived, overridden, or covered by approved undertaking.
4. Physical-copy requirements may satisfy Document Gate when staff marks the physical copy received and verified.
5. Metadata-only requirements may satisfy Document Gate when authorized staff records the required verification.
6. Staff-requested digital evidence must be submitted and reviewed only if it is configured as enrollment-blocking.
7. Active document holds with Blocks Enrollment must fail the Document Gate.
8. Active document holds with Blocks COR Download must not block official enrollment unless configured to do so.

---

### 7.2. Gate Override Record

Gate overrides must be scoped and auditable.

Required fields:

1. Override ID
2. Student ID
3. Academic Year
4. Term
5. Gate Type
6. Original Gate Result
7. Override Result
8. Scope
9. Expiration Date
10. Reason
11. Requested By
12. Approved By
13. Approved At
14. Related Evidence
15. Audit Metadata

Scope values:

1. This Enrollment Only
2. This Term Only
3. Until Expiration Date
4. Until Hold Resolved
5. Permanent Exception, restricted and discouraged

Rules:

1. Overrides cannot be indefinite by default.
2. Override reason is mandatory.
3. Override approver must have permission for that gate type.
4. Expired overrides no longer satisfy gates.
5. Override does not delete the original failed gate result.
6. Override must appear in audit and exception reports.

---

### 7.3. New Applicant Enrollment

Flow:

1. Upfront identity verification is passed.
2. Applicant is approved for handover.
3. Handover-blocking checklist requirements are resolved, waived, or placed under approved undertaking.
4. Official student profile is created or reused.
5. Student number is assigned.
6. Program and curriculum are assigned.
7. Assessment is generated.
8. Enrollment-blocking document requirements are checked.
9. Required payment or downpayment evidence is verified.
10. Capacity slot is secured.
11. Registrar confirms section or irregular placement.
12. Schedule conflict check runs.
13. Enrollment becomes official. (TALA automatically transitions the student status to "Officially Enrolled" once the required downpayment or full payment is posted to the ledger, either via PayMongo webhook or manual Cashier encoding. No manual Registrar final approval is required.)
14. Student Hub enrollment visibility is enabled.
15. COR becomes available for viewing and printing if no blocking hold exists.

Slot rule:

1. **Regular Cohort Students:** Their slots are pre-allocated in their progressing cohort blocks (e.g., BSIT-2A). These seats are guaranteed and locked to their block, eliminating the risk of losing slots to other enrollees.
2. **Irregular Students:** Their slots are secured by a 15-minute soft lock when initiating PayMongo checkout. If the payment finishes after the 15-minute expiration and the section has reached capacity, they are placed in a Capacity Pending status for Registrar manual override/reassignment.

Document rule:

Official enrollment checks only checklist items configured as enrollment-blocking, accepted alternatives, approved undertakings, or authorized overrides. Retention and follow-up documents may remain tracked without requiring digital upload.

---

### 7.4. Continuing and Irregular Enrollment

Continuing students must clear five gates:

1. Financial Gate
2. Documentary Gate
3. Behavioral Gate
4. Disciplinary Gate
5. Academic Progression Gate

Default continuing finance rule:

Previous balance must be ₱0.00 unless an approved promissory note or payment plan exists.

Irregular flow:

Academic history review → failed or missing subject detection → prerequisite check → eligible subject list → offering match → conflict check → staff approval → enrollment binding.

Rules:

1. Failed prerequisites block downstream subjects.
2. Failed subjects should be retaken when hosted in the master schedule.
3. If a subject is not offered, student waits for next regular or approved special offering.
4. Irregular schedules must not overlap unless controlled override exists.
5. Approved irregular schedule is auditable.
6. Summer recoup is offered only by school discretion.
7. Summer load cap defaults to 6–9 units unless configured differently.
8. Irregular students select from approved CP-SAT sections; staff record overload or bridging approvals that were approved outside TALA.
9. Irregular students must select sections from a flat list of CP-SAT sections, and the backend strictly validates prerequisites, time overlaps, and unit limits.
10. If an overload or bridging exception is needed, the Registrar manually toggles an `Overload_Approved` flag to bypass the system unit limits based on documented academic override approval.

---

### 7.5. Student Acknowledgment

The Registration Form may include acknowledgment text confirming:

1. Agreement to school rules.
2. Payment obligation.
3. Use of personal information for enrollment and school records purposes.
4. Certification that prerequisites were taken and passed.

Rules:

1. The acknowledgment text may appear on printed Registration Form / COR / Assessment document.
2. Student may sign the printed copy manually.
3. TALA may record the output as viewed, printed, or downloaded through access and print logs.
4. Student prerequisite acknowledgment does not replace system prerequisite validation.

---

### 7.6. Enrollment Rules Printed on Form

The following rules may appear on the Registration Form / COR / Assessment Form.

#### 7.6.1 Documentary Requirements

Documentary requirements must be submitted on time.

System behavior:

1. Missing required documents create Documentary Holds only when configured.
2. Holds must state blocking level and deadline.
3. Non-critical missing documents may use Missing Documents Undertaking if approved.
4. Some requirements may be satisfied by physical-copy submission.
5. Some requirements may be tracked through checklist metadata without storing a digital file.
6. Digital upload is collected when the checklist requires it or staff requests it.
7. Non-blocking retention requirements may remain open after enrollment if institutional policy allows it.
8. Enrollment-blocking checklist items follow the canonical Admission Checklist Item lifecycle.

Printed rule text may state:

“Required documents must be submitted according to Registrar deadlines. Some documents may be submitted as physical copies and tracked by the system without digital storage. Missing or unresolved blocking requirements may affect enrollment, COR download, clearance, or record release.”

#### 7.6.2 Clearance for Returning or Old Students

Continuing or returning students must pass clearance gates before enrollment.

#### 7.6.3 Shift / Schedule / Program Change Fee

A configurable default ₱100 fee may apply to student-requested schedule, program, or section changes, or replacement / loss of registration card.

Institution-caused changes are recorded without charging the student.

#### 7.6.4 Down Payment

Downpayment is configurable per program, with default range ₱1,000–₱2,000.

#### 7.6.5 Dropping

Dropping during the school year must use an official request. Approved drop creates status change and ledger impact.

#### 7.6.6 Institution-Caused Change

Institution-caused schedule or program changes are recorded without charging the student.

#### 7.6.7 Rejection of Registrants / Enrollees

Rejection must use an approved admission or enrollment decision state, authorized staff action, and recorded reason. Sensitive reasons must remain staff-only.

#### 7.6.8 Late Enrollment Fee

Late enrollment fee defaults to ₱500 and posts to ledger.

#### 7.6.9 Delayed Payment Penalty

Delayed payment of monthly dues may incur a configurable 5% penalty. It must post to ledger and must not create default exam blocks.

---
