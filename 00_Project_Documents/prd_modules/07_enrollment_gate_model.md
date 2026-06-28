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
2. Pending Review
3. Capacity Pending
4. Payment Pending
5. Ready for Official Enrollment
6. Officially Enrolled
7. Cancelled
8. Dropped
9. Withdrawn

Status rules:

1. Enrollment Status describes overall progress.
2. `Pending Review` is used while one or more Registrar, Accounting, Academic, document, progression, conflict, or irregular-placement gates require review.
3. The responsible office and blocking reason come from the pending Enrollment Gate result.
4. `Capacity Pending` is used only while compatible section placement or capacity confirmation remains unresolved.
5. `Payment Pending` is used only after placement and assessment are ready and the Finance Gate has not passed.
6. `Ready for Official Enrollment` means every required gate has passed, been waived, or been validly overridden.
7. `Cancelled`, `Dropped`, and `Withdrawn` remain distinct final statuses with separate academic, capacity, COR, and ledger effects.
8. Record replacement or correction history is auditable metadata attached to the enrollment record.

Document Gate rules:

1. Document Gate must evaluate checklist requirements by blocking level.
2. Document Gate must ignore open non-blocking retention requirements.
3. Document Gate passes when all enrollment-blocking checklist items are accepted, verified, waived, overridden, or covered by approved undertaking.
4. Physical-copy requirements may satisfy Document Gate when staff marks the physical copy received and verified.
5. Metadata-only requirements may satisfy Document Gate when authorized staff records the required verification.
6. Staff-requested digital evidence must be submitted and reviewed only if it is configured as enrollment-blocking.
7. Active document holds with Blocks Enrollment must fail the Document Gate.
8. Active document holds with Blocks COR Download affect COR access according to their configured blocking level.

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

1. Overrides have a defined scope or expiration.
2. Override reason is mandatory.
3. Override approver must have permission for that gate type.
4. Expired overrides no longer satisfy gates.
5. Override preserves the original failed gate result.
6. Override must appear in audit and exception reports.

Gate Override and Academic Exception are distinct:

1. Gate Override changes the treatment of one Enrollment Gate result within its approved scope.
2. Academic Exception changes the treatment of one academic rule for one student and target Term Offering, such as prerequisite, corequisite, unit-limit, or approved bridging treatment.
3. Both records preserve the original failed validation and their approved scope.

---

### 7.3. New Applicant Enrollment

Flow:

1. Upfront identity verification is passed.
2. Applicant is approved for handover.
3. Handover-blocking checklist requirements are resolved, waived, or placed under approved undertaking.
4. Official student profile is created or reused.
5. Student number is assigned.
6. Program and curriculum are assigned.
7. Enrollment-blocking document requirements are checked.
8. Registrar confirms the regular or irregular section placement after capacity and schedule-conflict validation.
9. TALA creates an Enrollment Seat Reservation.
10. Assessment is generated from the reserved subjects and sections.
11. Required payment or downpayment is posted to the ledger, or an active Financial Accommodation explicitly allows enrollment for the covered term.
12. TALA rechecks the remaining enrollment gates.
13. Enrollment becomes official. TALA automatically transitions the student status to "Officially Enrolled" once every required gate passes, including the Finance Gate.
14. Student Hub enrollment visibility is enabled.
15. COR becomes available for viewing and printing if no blocking hold exists.

Slot rule:

1. **Regular Cohort Students:** TALA proposes the progressing cohort block and validates it against the published schedule and remaining capacity. Registrar confirmation creates the Enrollment Seat Reservation.
2. **Irregular Students:** The student submits subject or section choices from published offerings. TALA validates prerequisites, unit limits, conflicts, and capacity; the Registrar confirms the final placement and creates the Enrollment Seat Reservation.
3. **Capacity Pending:** This means the Registrar has not yet confirmed a compatible available section.
4. **Payment Independence:** PayMongo or cashier payment begins only after placement, reservation, and assessment. Section capacity remains controlled by the Registrar-confirmed Enrollment Seat Reservation.
5. **Release:** A pending reservation is released when enrollment is cancelled, rejected, or reaches the institution-configured deadline.

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

Default student unit-load policy:

1. Normal maximum load defaults to the total units in the student's assigned Curriculum Version for the target term.
2. If the Curriculum Version term load is not available, the normal maximum load defaults to the institution-configured term maximum.
3. Student overload requires a recorded Student Unit Load Exception.
4. Student overload cap defaults to 6 excess units for a regular semester unless configured differently.
5. Summer or Special Term uses its own configured cap.
6. Academic Head is the default approving authority for Student Unit Load Exceptions.
7. Registrar is the default recording office after the approved result exists.
8. System Super Admin configures the normal-load rules, excess-unit caps, and required authority. Individual student overload decisions use the configured academic authority.

Default continuing finance rule:

The Finance Gate passes when the required enrollment payment or downpayment has been posted to the ledger, or when an active Financial Accommodation explicitly allows enrollment for the covered term.

Finance Gate rules:

1. Finance Gate passes only when the required enrollment payment or downpayment has been posted to the ledger, or when an active Financial Accommodation explicitly allows enrollment for the covered term.
2. PayMongo checkout success, pending webhook processing, pending payment evidence review, and pending OR mapping remain payment-processing or reconciliation states until ledger posting occurs.
3. Pending OR mapping is an Accounting reconciliation state after ledger posting. It affects official enrollment only when the institution configures a separate enrollment-blocking hold for it.
4. Pending payment evidence review fails the Finance Gate until Accounting or policy-approved auto-confirmation posts the ledger entry.
5. Old or previous balances block continuing enrollment only when configured as enrollment-blocking and not covered by an enrollment-effective Financial Accommodation.
6. Finance Gate failure reason must identify the exact blocker: Missing Required Payment, Payment Evidence Pending Review, Required Balance Unpaid, or No Enrollment-Effective Financial Accommodation.

Irregular flow:

Academic history review → failed or missing Curriculum Entry detection → effective Prerequisite Rule Set evaluation → corequisite evaluation → approved equivalency and credit evaluation → eligible subject list → offering match → unit-limit and conflict checks → scoped Academic Exception check when needed → Registrar placement confirmation → Enrollment Seat Reservation and enrollment binding.

Rules:

1. Unsatisfied prerequisite groups block downstream subjects unless a valid scoped Academic Exception exists.
2. Failed subjects should be retaken when hosted in the Master Schedule.
3. If a subject is not offered, student waits for next regular or approved special offering.
4. Irregular schedules require conflict-free section selections or a controlled override.
5. Approved irregular schedule is auditable.
6. Summer completion/catch-up Special Offerings are available only when approved by the institution.
7. Summer load cap defaults to 6–9 units unless configured differently.
8. Irregular students select from approved CP-SAT sections; staff record overload, prerequisite, corequisite, or bridging exceptions that were approved outside TALA.
9. Irregular students must select sections from a flat list of CP-SAT sections, and the backend strictly validates prerequisites, time overlaps, and unit limits.
10. An approved exception is recorded as an Academic Exception scoped to the student, academic year and term, target Term Offering, failed rule and exception type, authority, reason, evidence reference, effective period, recorder, and audit metadata.
11. An Academic Exception applies only to the named failed rule.
12. Failed, incomplete, pending-grade, withdrawn, dropped, blank, and currently enrolled courses do not satisfy prerequisites. Concurrent enrollment may satisfy only a defined corequisite.
13. Enrollment selection is at the Term Offering / section level. Linked lecture and laboratory components for one course stay under one enrollment line unless the institution defines separate subject codes or separate released grades.
14. A `P` / Pending Grade blocks automatic prerequisite satisfaction. If the student must enroll while the prerequisite grade is pending, staff must record a scoped Academic Exception tied to the affected target Term Offering.
15. If a pending prerequisite grade is later replaced by a failing outcome, TALA flags the affected enrollment for Registrar or Academic Head review.
16. Unit overload uses a recorded Academic Exception or Student Unit Load Exception approved outside TALA.
17. Unit overload approval affects only the unit-load rule. Prerequisites, schedule conflicts, capacity, finance, document gates, and Graduation Eligibility Snapshot blockers continue to use their own validations.
18. TALA may show that a selected irregular schedule exceeds the configured unit limit for staff review.
19. A Student Unit Load Exception affects only the term and student named in the record.

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
4. Student prerequisite acknowledgment is recorded separately from system prerequisite validation.

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

Delayed payment of monthly dues may incur a configurable 5% Accounting-posted penalty. The charge appears in the ledger and SOA while exam access follows institutional law and policy.

---

### 7.7. Enrollment Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Enrollment record | Generated gate summary and status view; users do not type the overall status directly |
| Gate review | Operational Queue / Review Table with one row per student enrollment and expandable gate results |
| Gate Override | Focused Record Form selecting the failed gate, scope, expiration, authority, reason, and evidence reference |
| Regular cohort placement | Read-only proposed block with compatible-section Selection List and Registrar confirmation |
| Irregular subject/section choice | Flat selectable table of published sections showing course, units, linked lecture/laboratory schedule rows when applicable, capacity, prerequisite/corequisite result, and conflict result |
| Academic Exception | Focused Record Form tied to the student and target Term Offering; staff select the failed rule and exception type and record authority, reason, evidence, and effective scope |
| Student Unit Load Exception | Focused Record Form tied to the student and term; staff record normal max units, requested total units, approved excess units, authority, reason, scope, and affected subjects |
| Enrollment Seat Reservation | Generated result of Registrar placement confirmation; not a student-entered field or payment action |
| Assessment and payment readiness | Generated Read-Only View linked to Module 8 source records |
| Final official-enrollment transition | System action after all gates pass; the resulting status and triggering evidence are read-only and auditable |

The selectable section table marks ineligible rows and exposes the exact failed rule. Exceptions are scoped to the approved failed rule and are displayed as named exception records.

---
