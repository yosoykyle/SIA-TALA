## 12. Student Hub & Generated Output Access

---

### 12.1. Student Hub

Student Hub v1 is the authenticated student-facing workspace for current academic status, finance status, holds, schedules, grades, and generated outputs.

The public landing page is the public non-authenticated surface. It explains TALA, admission guidance, account access boundaries, notices, and FAQ links before users continue to sign-in or applicant application access.

Student Hub v1 includes:

1. Dashboard.
2. COR view / print when allowed.
3. SOA view / print when allowed.
4. Payment acknowledgement view / print when allowed.
5. Published class schedule.
6. Enrolled subject list.
7. Grades after posting and release, including student-facing labels for `INC`, `P`, withdrawn lifecycle outcomes, and `TC` when applicable.
8. Holds and missing requirements.
9. Academic deficiency or irregular status summary if approved.
10. Delivery modality.
11. Account and workflow notices.
12. Student-facing Financial Accommodation status and next due date when an active arrangement exists; certification details, evidence, internal reasons, and staff notes remain hidden.
13. Student-facing graduation or completion review summary when Registrar makes it visible.

Student Hub focuses on current student academic and finance visibility. Registrar document requests, credential requests, courier tracking, Diploma / TOR / Form 137 release, official receipt issuance, and generic service requests are handled through office procedures. Staff-only COR history remains in staff workspaces.

For Subject Drop, Withdrawal, Leave of Absence, or Program Shift, the student follows the institution's published office procedure. Student Hub shows the resulting status and changed current records after the Registrar records the approved result.

Visibility rules:

1. Student sees only their own records.
2. Applicants use Applicant Workspace until handover activates Student Hub access.
3. Student Hub shows current, student-authorized records.
4. Draft records, candidate schedules, unposted roster values, internal staff notes, audit records, archived records, revoked records, and staff-only history remain staff-workspace records.
5. Student-facing hold information uses simplified labels, blocking effect, required action, and office to contact.
6. Graduation Eligibility Snapshot details are shown as student-facing completion review information.

Page map:

| Page | Purpose |
| --- | --- |
| Dashboard | Shows current enrollment status, active holds, schedule summary, balance summary, and available outputs. |
| COR view | Shows the current printable COR when allowed. |
| SOA view | Shows current student account assessment and balance. |
| Payment acknowledgement view | Shows student-facing payment status and acknowledgement output; it is not an official receipt. |
| Schedule view | Shows the published student class schedule and enrolled subject list. |
| Grades view | Shows released numeric grades and controlled Grade Outcome labels such as Incomplete, Pending Grade, Withdrawn, and Transfer Credit. |
| Holds view | Shows active student-facing holds, blocking effect, required action, and office to contact. |
| Completion review view | Shows student-facing remaining requirements, pending grade or INC blockers, and office to contact when Registrar exposes a Graduation Eligibility Snapshot. |

---

### 12.2. Student Hub Display Priority

When multiple states exist, show the highest-priority actionable item first:

1. Security / account notice
2. Enrollment blocked
3. Payment pending or rejected
4. Capacity pending
5. COR blocked
6. Missing requirements
7. Active academic deficiency
8. Schedule available
9. COR available
10. Grades released
11. Informational notices

Rules:

1. Student Hub shows student-facing reasons only.
2. Student Hub must show which office to contact.
3. Student Hub must show the required action where safe.
4. Student Hub distinguishes enrollment status from COR availability.
5. Student Hub must distinguish “officially enrolled” from “COR downloadable.”
6. Student Hub must display modality separately from payment.
7. Capacity Pending tells the student that section placement is awaiting Registrar action.
8. When Enrollment Status is Pending Review, Student Hub uses the highest-priority pending gate to show the student-facing reason, required action, and office to contact.
9. Student Hub must distinguish payment checkout status, payment evidence review, ledger-posted payment, and OR mapping status.
10. Pending OR mapping is shown as a receipt-reconciliation status after ledger posting unless a separate active hold blocks enrollment.
11. Pending Grade is shown as an unresolved grade state.
12. INC must show only the student-facing completion/removal status or deadline when configured; staff notes and private evidence references remain hidden.
13. Graduation or completion review shows blockers and next office to contact.

Example:

Enrollment Status: Officially Enrolled
Delivery Modality: Online
Payment Status: Installment
COR Status: Available

---

### 12.3. Generated Output Access and Logging

Generated outputs are rendered or exported from official source records. TALA stores the source records, access logs, print logs, download logs, and audit trails needed to prove what was viewed or produced.

Generated outputs may include:

1. COR / Registration Form from official enrollment and active published schedule version.
2. SOA from assessment and ledger.
3. Payment acknowledgement from confirmed payment evidence and ledger posting.
4. Student schedule from published schedule.
5. Class roster from official enrollment.
6. Graduation eligibility snapshot.

Rules:

1. COR uses dynamic rendering and `cor_print_logs`; source records and print logs provide traceability for v1.
2. SOA and payment acknowledgement derive from assessment, payment evidence, and ledger records.
3. Student schedules and rosters derive from active published schedule and official enrollment records.
4. Access requires authentication.
5. View, print, download, or export actions must be logged when the output is official or sensitive.
6. Official output access uses controlled view, print, download, or export actions.
7. Stored snapshots are used only when a specific output explicitly requires snapshot preservation.

---

### 12.4. Student Hub Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| View current status, gate result, balance, or hold | Generated Read-Only summary linked to a detailed read-only record |
| Update allowed profile information | Limited Record Form owned by Module 3 |
| Submit or replace requested admission/checklist evidence | Checklist plus File Upload only when the configured item permits digital evidence |
| Choose irregular sections during an open enrollment window | Flat selectable section table owned by Module 7 |
| Pay online | PayMongo checkout action owned by Module 8; return pages show read-only processing status |
| View finance gate status | Generated Read-Only summary showing required payment posted, payment under review, active Financial Accommodation effect, or office to contact |
| View schedule, COR, SOA, payment acknowledgement, or released grades | Generated Read-Only View with only the authorized view/print/download action |
| View pending or incomplete grade status | Generated Read-Only View showing student-facing label, affected course, responsible office, and configured deadline when safe |
| View graduation or completion review status | Generated Read-Only View from the latest visible Graduation Eligibility Snapshot |
| Resolve a hold or lifecycle issue | Read-only instruction identifying the responsible office or permitted evidence action |

Student Hub exposes student-authorized summaries, outputs, and allowed evidence actions. Staff queues, approval forms, private notes, draft schedules, unposted grades, ledger posting controls, and source-record editors remain in staff workspaces.

---
