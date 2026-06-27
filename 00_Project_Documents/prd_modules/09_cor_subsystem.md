## 9. Certificate of Registration (COR) Subsystem

---

### 9.1. COR / Registration Form Template

The official COR output must use the following structure.

#### 9.1.1 Header

1. Institution Name
2. Logo Area
3. Document Title: Registration Form / Certificate of Registration
4. Enrolled Stamp Area
5. Academic Year
6. Semester / Term
7. Copy Type:
   - Registrar’s Office Copy
   - Accounting Office Copy
   - Student’s Copy

#### 9.1.2 Student Information

Required fields:

1. Student No.
2. LRN / Prior-Education Identifier, if available
3. Full Name
4. Program
5. Year Level
6. Section
7. Registration Date
8. Payment Type / Payment Status
9. Delivery Modality

Payment status values:

1. Unpaid
2. Partially Paid
3. Full Paid
4. Installment
5. Payment Pending
6. Payment Under Review
7. Payment Rejected
8. Cleared by Payment Plan

Delivery modality values:

1. Online
2. Face-to-Face
3. Modular

Payment status and delivery modality must remain separate fields.

#### 9.1.3 Class Schedule / Subjects

Required columns:

1. Subject Code
2. Subject Description
3. Units
4. Lecture Hours
5. Laboratory Hours
6. Section
7. Day
8. Time
9. Room
10. Instructor

Rules:

1. COR schedule rows must come from official enrollment and published schedule version.
2. Candidate schedules must not appear on COR.
3. Irregular student schedules must appear only after Registrar-approved irregular schedule binding.
4. Total units must be computed from enrolled subjects.
5. COR must show the current official enrolled subject list.

#### 9.1.4 Computation of Fees

Required rows:

1. Registration Fee
2. Tuition Fee
3. Laboratory Fee
4. Miscellaneous Fee
5. Other Fee
6. Discount
7. Down Payment
8. Total Fees
9. Balance

Rules:

1. Fee values must come from assessment and ledger-derived balance.
2. Discount must reduce the assessed amount.
3. Down Payment and posted payments must reflect only officially posted ledger entries. Pending OR mappings or unverified payment evidence are not displayed on the COR.
4. Balance must be reproducible from ledger entries.
5. Manual editing of printed fee values is not allowed.

#### 9.1.5 Installment Schedule

Include if payment type or status is installment.

Required columns:

1. Installment Number
2. Due Date
3. Amount
4. Receipt No. / Payment Reference
5. Date Paid
6. Remaining Balance

Rules:

1. Installment rows must come from approved payment plan or assessment schedule.
2. Receipt No. may store manual OR reference if SIA approves.
3. Official tax receipts are issued through the institution's cashier/accounting process.
4. PayMongo references and manual payment references must remain traceable to payment evidence.

#### 9.1.6 Authorization and Signatures

Default signature rows:

1. Encoded / Enlisted By
2. Evaluated By / Registrar
3. Assessed By / Accounting
4. Approved By / School Administrator

Rules:

1. Signatory roles are required.
2. Exact printed names are configurable.
3. The system must record the actual workflow actor where the action is performed digitally.
4. Printed signature areas are part of the printable artifact.

---

### 9.2. COR Generation and Download

The COR is the official source-derived registration output. It is rendered as a clean HTML/CSS view with `@media print` stylesheets. Users click "Print" in the Student Hub or Registrar Workspace to save as PDF or route to a physical printer via the browser.

---

### 9.3. COR Visibility and Lightweight Print Log

TALA renders the COR from the student's current official enrollment, active published schedule version, assessment, and ledger-derived balance. The source records and print logs provide traceability for v1.

**Rules:**

1. **Access Scope:** The COR view is accessible only to the Registrar, Accounting, and the authenticated Student who owns that specific COR record.
2. **Current Active View:** Students may view and print only their current active COR.
3. **Historical Traceability:** Registrar and Accounting review historical schedule changes or enrollment updates using the enrollment logs, published schedule versions, schedule revision events, and ledger logs.
4. **Lightweight Print Logging:** Every time a user views or prints a COR, TALA creates a `cor_print_logs` record containing:
   - `id`, `student_id`, `enrollment_id`, `term_id`, `schedule_version_id`
   - `actor_id`, `actor_role`
   - `copy_type` (Enum: `STUDENT_COPY`, `REGISTRAR_COPY`, `ACCOUNTING_COPY`)
   - `action` (Enum: `VIEW`, `PRINT`)
   - `created_at`
5. **Holds Blocking:** The Student Hub blocks COR viewing/printing if the student has an active `COR Download Hold` (which remains separate from enrollment-blocking holds). Once resolved or wavered, access is restored.
6. **Public Verification Boundary:** Public COR verification, unauthenticated QR scanning, and public artifact lookup are out of v1 scope. They may be added only if a future approved institutional policy requires them.

---
