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
10. Instructor / Teacher / Trainor

Rules:

1. COR schedule rows must come from official enrollment and published schedule version.
2. COR displays published schedule records.
3. Irregular student schedules must appear only after Registrar-approved irregular schedule binding.
4. Total units must be computed from the authoritative credit units of the enrolled Course Specification revisions.
5. COR must show the current official enrolled subject list.
6. A course with linked Lecture and Laboratory components remains one subject line for units and enrollment, but may display separate schedule meeting rows for each component.
7. The printed instructor label may be configured as Instructor, Teacher, Trainer, or Teacher/Trainor, but the source value must come from the published faculty assignment.

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
3. Down Payment and posted payments reflect officially posted ledger entries.
4. Balance must be reproducible from ledger entries.
5. Printed fee values are generated from finance source records.

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

1. Installment rows must come from the assessment schedule or an active Financial Accommodation payment schedule.
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

#### 9.1.7 Legacy COR Field Source Map

The legacy COR headers are supported as generated fields. Staff must correct the source record instead of editing the printed COR value directly.

| Legacy COR field | Source record |
| --- | --- |
| Subject Code | Course Catalog identity referenced by the enrolled Course Specification Revision |
| Subject Description | Course Specification Revision title / description |
| LAB HR. | Laboratory Course Component contact hours from the Course Specification Revision |
| Section | Registrar-confirmed Enrollment Seat Reservation / Student Schedule Binding |
| Day | Published schedule meeting row |
| Time | Published schedule meeting row |
| Room | Published schedule meeting row and room assignment |
| Teacher / Trainor | Published faculty assignment |
| Student No. | Student master record |
| Program | Student program assignment |
| Full Name | Student master record |
| Yr/Gr Level | Student academic profile / assigned Curriculum Version placement label |
| LRN | Prior-education identifier, when available in the student profile |
| Registration Date | Official enrollment timestamp or Registrar-confirmed registration date |
| Tuition Fee | Active assessment charge line |
| Laboratory Fee | Active assessment charge line |
| Misc. Fee | Active assessment charge line |
| Other Fee | Active assessment charge line |
| Total Fees | Computed assessment total |
| Down Payment | Required downpayment from the active exact Program-and-Term fee rule and posted payment ledger state |
| Balance | Ledger-derived current balance |

Rules:

1. The COR may be printed as a combined registration and assessment form when configured.
2. COR values come from source records.
3. If a legacy header has no source value, the field is blank or hidden according to the configured template rule.
4. LRN remains optional for college records when not available.
5. Fee labels may match the legacy wording, but amounts must derive from assessment and ledger records.

---

### 9.2. COR Generation and Download

The COR is the official source-derived registration output. It is rendered as a clean HTML/CSS view with `@media print` stylesheets. Users click "Print" in the Student Hub or Registrar Workspace to save as PDF or route to a physical printer via the browser.

For MVP, COR download uses the browser's print or save-as-PDF flow from the authenticated printable view. TALA does not generate or store a server-side PDF unless a later approved policy requires retained generated files.

---

### 9.3. COR Visibility and Lightweight Print Log

TALA renders the COR from the student's current official enrollment, active published schedule version, assessment, and ledger-derived balance. The source records and print logs provide traceability for v1.

**Rules:**

1. **Access Scope:** The COR view is accessible only to the Registrar, Accounting, and the authenticated Student who owns that specific COR record.
2. **Current Active View:** Students may view and print only their current active COR.
3. **Historical Traceability:** Registrar and Accounting review historical schedule changes or enrollment updates using the enrollment logs, published schedule versions, schedule revision events, and ledger logs.
4. **Lightweight Output Logging:** Every time a user views or prints a COR, TALA creates an `output_access_logs` record containing:
   - `id`, `output_type`, `source_record_type`, `source_record_id`
   - `student_profile_id`, `actor_user_id`, `actor_role`
   - `action` (Enum: `VIEW`, `PRINT`)
   - `copy_context` (Enum: `STUDENT_COPY`, `REGISTRAR_COPY`, `ACCOUNTING_COPY`)
   - `schedule_version`, `request_context`, `status`, `occurred_at`
5. **Holds Blocking:** Student Hub shows the COR after active `COR Download Hold` records are resolved or waived. COR Download Hold remains separate from enrollment-blocking holds.
6. **Public Verification Boundary:** Public COR verification, unauthenticated QR scanning, and public artifact lookup require a future approved institutional policy.
7. **Lifecycle Refresh:** Subject Drop updates the dynamically rendered subject list. Withdrawal or current-term Leave of Absence removes the COR from current-active availability while preserving source records and audit history. These changes do not regenerate the Master Schedule.

---

### 9.4. COR Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| COR content | Generated Read-Only View derived from official enrollment, published schedule, Course Specification revisions, assessment, and ledger |
| Subject and schedule rows | Read-only table; one enrolled course may expand into linked Lecture and Laboratory meeting rows; staff correct enrollment or schedule source records |
| Units and fee totals | Read-only computed values |
| Authorization names or signatory labels | Controlled configuration or source-record values; not editable during student viewing |
| View, print, or download | Explicit output action with access and print logging; MVP download means browser save-as-PDF from the printable view |
| Blocked access | Read-only hold explanation showing the permitted next step without exposing staff-only notes |

V1 COR generation uses source records and controlled configuration. Corrections occur through the owning enrollment, schedule, assessment, ledger, or configuration record.

---
