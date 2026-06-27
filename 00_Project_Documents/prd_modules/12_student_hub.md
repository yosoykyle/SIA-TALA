## 12. Student Hub & Generated Output Access

---

### 12.1. Student Hub

Student Hub v1 is a minimal authenticated student-facing Laravel / Livewire page set. It is separate from Filament, which remains the staff and administration workspace.

Student Hub v1 includes:

1. Dashboard.
2. COR view / print when allowed.
3. SOA view / print when allowed.
4. Payment acknowledgement view / print when allowed.
5. Published class schedule.
6. Enrolled subject list.
7. Grades after posting and release.
8. Holds and missing requirements.
9. Academic deficiency or irregular status summary if approved.
10. Delivery modality.
11. Account and workflow notices.

Student Hub focuses on current student academic and finance visibility. Registrar document requests, credential requests, courier tracking, Diploma / TOR / Form 137 release, official receipt issuance, and generic service requests are handled through office procedures. Staff-only COR history remains in staff workspaces.

Visibility rules:

1. Student sees only their own records.
2. Applicant cannot access Student Hub before handover.
3. Draft records, candidate schedules, unposted grades, internal staff notes, and audit records are hidden.
4. Non-current source records, archived records, revoked records, and staff-only history must not appear as current Student Hub content.
5. Student-facing hold information must be simplified.

Page map:

| Page | Purpose |
| --- | --- |
| Dashboard | Shows current enrollment status, active holds, schedule summary, balance summary, and available outputs. |
| COR view | Shows the current printable COR when allowed. |
| SOA view | Shows current student account assessment and balance. |
| Payment acknowledgement view | Shows student-facing payment status and acknowledgement output; it is not an official receipt. |
| Schedule view | Shows the published student class schedule and enrolled subject list. |
| Grades view | Shows released period and final grades only. |
| Holds view | Shows active student-facing holds, blocking effect, required action, and office to contact. |

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

1. Student Hub must not expose staff-only reasons.
2. Student Hub must show which office to contact.
3. Student Hub must show the required action where safe.
4. Student Hub must not claim enrollment is complete if COR is blocked.
5. Student Hub must distinguish “officially enrolled” from “COR downloadable.”
6. Student Hub must display modality separately from payment.

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
6. Public file storage paths must not be exposed.
7. Stored snapshots are used only when a specific output explicitly requires snapshot preservation.

---
