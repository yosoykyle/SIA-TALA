## 2. Identity, Access, and Workspaces

---

### 2.1. Main Actors

#### 2.1.1 Applicant

Submits application data, minimal upfront identity evidence, requested digital evidence, physical-submission responses, and checklist correction responses. Views own application status and applicant-facing checklist instructions.

#### 2.1.2 Student

Views approved student-facing records after official handover. Downloads own current active COR, SOA, and payment acknowledgement when allowed.

#### 2.1.3 Registrar

Owns admissions, student master records, handover, enrollment gates, section placement, scheduling review, COR, student status, academic records, grade review, Graduation Review Batches, Graduation Eligibility Snapshots, and Registrar-recorded unit-load exceptions.

#### 2.1.4 Accounting

Owns fee setup, assessment, payment evidence, ledger posting, balance, SOA, payment acknowledgement, finance clearance, reconciliation, finance holds, and Financial Accommodation records.

#### 2.1.5 Faculty

Submits availability, views assigned classes, views rosters, encodes grades, submits grade rosters, and responds to returned rosters before posting.

#### 2.1.6 Academic Head

Approves curriculum versions, academic exceptions, and Student Unit Load Exceptions where institutional policy requires it. Program-shift credit approval and other office-handled lifecycle decisions occur through institutional procedure; TALA exposes their recorded results when Academic Head visibility is required.

#### 2.1.7 System Super Admin

Manages users, roles, permissions, configuration, integration settings, security policy, access policy, and audit visibility. System Super Admin configures policy values but does not decide individual student academic eligibility.

---

### 2.2. Identity, Access, and Account Lifecycle

TALA must separate Applicant, Student, Faculty, Registrar, Accounting, Academic Head, and System Super Admin access.

Application surfaces:

1. The public landing page is the only public, non-authenticated surface. It provides institutional information, admission guidance, Filament sign-in/apply entry points, account-boundary explanations, notices, and FAQ content.
2. Applicant Workspace is an authenticated Filament workspace for applicants before handover, including Filament-handled applicant registration/auth UI.
3. Student Hub is an authenticated Filament workspace for students after handover.
4. Faculty Workspace means authenticated Filament surfaces for faculty academic work; MVP may implement these as role-scoped faculty pages inside the shared Staff Workspace rather than as a separate panel.
5. Registrar, Accounting, Academic Head, and System Super Admin workspaces are authenticated Filament staff workspaces.
6. Product language uses Applicant Workspace, Student Hub, Faculty Workspace, and Staff Workspace.

#### 2.2.1 Canonical Roles and Laravel Authentication

1. **Authentication Flows:** Login, session management, email verification, password resets, and applicant registration UI are handled through Filament panel authentication surfaces. Laravel Fortify may remain as a backend authentication contract where already integrated.
2. **Roles:** Roles are canonical and assigned via **Spatie Laravel Permission** using the database.
3. **Canonical Permission Model:** The 7 canonical roles use predefined permissions and authorization guards throughout the application. Super Admin manages users and configured role records within that fixed model.

---

### 2.3. Action-Level Permissions

Use these action categories across modules:

1. View
2. Create
3. Edit Draft
4. Submit
5. Review
6. Approve
7. Reject
8. Post / Finalize
9. Correct
10. Override
11. Generate / Render Output
12. Download / Print Output
13. Export
14. Archive
15. Void / Supersede
16. Configure

#### 2.3.1 Applicant Permissions

Allowed:

1. View own application.
2. Create own application.
3. Edit own draft application.
4. Submit own application.
5. Upload required upfront identity evidence.
6. Upload additional digital evidence only when requested or allowed by checklist configuration.
7. View own checklist requirements and submission instructions.
8. Reupload documents requested for correction.
9. Withdraw own application before handover when allowed.
10. View own application status.

Not allowed:

1. Access Student Hub before handover.
2. View other applicants.
3. Approve, reject, override, post, export, or archive official records.
4. Mark physical-copy requirements as received or verified.
5. Bypass document, identity, handover, or enrollment gates.

#### 2.3.2 Student Permissions

Allowed:

1. View own approved Student Hub records.
2. Download own current active COR when allowed.
3. View or download own current SOA when allowed.
4. View or download own payment acknowledgement when allowed.
5. Directly edit allowed profile information (contact info, address, emergency contact).
6. View own holds and current status notices.
7. View released grades.
8. View published class schedule.

Not allowed:

1. View other students.
2. Directly edit official identity, enrollment, grades, finance, or COR.
3. View staff notes, audit logs, draft schedules, candidate schedules, or unposted grades.
4. View staff-only historical COR source records, COR print logs, or non-current COR-related history.

#### 2.3.3 Faculty Permissions

Allowed:

1. View assigned classes.
2. View assigned class rosters.
3. Submit availability.
4. Encode grade drafts for assigned classes.
5. Submit grade rosters.
6. Respond to returned-for-correction grade rosters.

Boundaries:

1. Faculty views assigned classes and assigned rosters.
2. Registrar workflow posts final grades and handles finalized-grade corrections.
3. Student finance records and admission evidence stay in the authorized staff workspaces.
4. Posted grade correction requests follow physical school policy outside TALA; the Registrar records approved corrections in TALA.

#### 2.3.4 Registrar Permissions

Allowed:

1. Review applicant evidence.
2. Approve applicant for handover.
3. Create or reuse student master record through handover.
4. Manage student records.
5. Run enrollment gates.
6. Manage section placement and irregular schedules.
7. Manage COR access, COR download holds, COR dynamic print view, and COR print-log review.
8. Review grade submissions.
9. Post final grades if authorized.
10. Release grades to Student Hub.
11. Manage student status and holds.
12. Run graduation eligibility evaluation.
13. Create Graduation Review Batches and refresh Graduation Eligibility Snapshots.
14. Record approved Student Unit Load Exceptions when Registrar is the configured recording office.
15. Export Registrar-scoped reports.

Controlled actions requiring reason, permission, and audit:

1. Gate override.
2. Published schedule revision.
3. COR download hold or access restriction.
4. Registrar-recorded posted-grade correction.
5. Duplicate profile archive/link resolution.
6. Sensitive export.
7. Student Unit Load Exception recording.
8. Graduation Eligibility Snapshot visibility change.

#### 2.3.5 Accounting Permissions

Allowed:

1. Create and review assessment.
2. Verify payment evidence.
3. Review PayMongo exceptions.
4. Post ledger entries if authorized.
5. Create adjustment or reversal requests.
6. Generate SOA.
7. Generate payment acknowledgement.
8. Manage finance clearance.
9. Manage finance holds.
10. Record and manage Financial Accommodations approved under institutional policy.
11. Run reconciliation.
12. Export Accounting-scoped reports.

Boundaries:

1. Grade changes remain in Registrar and academic workflows.
2. Academic progression decisions remain in Registrar or Academic Head workflows.
3. Curriculum and schedule changes remain in academic and scheduling workflows.
4. Registrar workflow marks enrollment official after finance clearance is available.

#### 2.3.6 Academic Head Permissions

Allowed:

1. View curriculum versions whose institutional approval result was recorded in TALA.
2. Review recorded curriculum amendment results and activation impact when authorized.
3. Approve scoped academic exceptions where institutional policy requires Academic Head authority.
4. Review faculty term load overrides.
5. Review scheduling exceptions.
6. Approve academic progression exceptions.
7. View recorded approved program-shift credit evaluation results when authorized.
8. Review graduation eligibility exceptions.
9. Approve Student Unit Load Exceptions where configured.

Boundaries:

1. Ledger entries remain in Accounting workflow.
2. Grade posting remains in Registrar workflow.
3. Academic Head approvals are audited.

#### 2.3.7 System Super Admin Permissions

Allowed:

1. Configure roles and permissions.
2. Configure system rules.
3. Configure academic year and term settings.
4. Configure integration settings.
5. View system audit reports.
6. Manage user accounts.
7. Configure email templates.
8. Configure retention categories.

Rules:

1. Super Admin actions must be audited.
2. Super Admin configuration actions preserve official workflow records and are audited.
3. System configuration changes must preserve previous settings where relevant.

---

### 2.4. Faculty Workspace

Faculty Workspace provides faculty-facing academic work functions.

#### 2.4.1 Faculty Workspace Functions

Faculty Workspace includes:

1. Faculty overview.
2. Assigned classes.
3. Class rosters.
4. Faculty availability submission.
5. Grade encoding workspace.
6. Draft grade saving.
7. Grade roster submission.
8. Returned-for-correction roster handling.
9. Grade submission history.

#### 2.4.2 Faculty Workspace Rules

1. Faculty sees assigned classes and assigned rosters.
2. Student finance records and admission evidence remain staff-workspace records for authorized offices.
3. Registrar posts and releases final grades.
4. Faculty actions are auditable.

---

### 2.5. Registrar Workspace

Registrar Workspace provides operational queues for Registrar-owned workflows.

Registrar queues:

1. Applicant evidence review.
2. Manual applicant profile updates (Admin Override).
3. Duplicate applicant or student profile review.
4. Applicant-to-student handover.
5. Manual student profile updates (Admin Override).
6. Enrollment gate review.
7. Regular and irregular section placement, capacity review, and Enrollment Seat Reservation.
8. Schedule publication review.
9. Published schedule revision review.
10. COR access and print-log review.
11. Grade roster review.
12. Grade release.
13. Approved Student Lifecycle Change recording and student status transition review.
14. Graduation eligibility review.
15. Graduation Review Batch and Graduation Eligibility Snapshot review.
16. Student Unit Load Exception recording.
17. Registrar reports and exports.

Rules:

1. Registrar actions require workflow state checks.
2. Controlled actions require reason and audit.
3. Accounting-owned ledger posting remains in Accounting workflow.
4. Staff-only notes stay in staff workspaces and are filtered from Student Hub output.

---

### 2.6. Accounting Workspace

Accounting Workspace provides operational queues for finance-owned workflows.

Accounting queues:

1. Fee setup review.
2. Assessment review.
3. Manual payment evidence review.
4. PayMongo exception review.
5. Ledger posting review.
6. Adjustment request review.
7. Reversal request review.
8. Finance hold review.
9. Financial Accommodation recording and status review.
10. SOA generation.
11. Payment acknowledgement generation.
12. Reconciliation.
13. Accounting reports and exports.

Rules:

1. Accounting controls finance evidence and ledger posting.
2. Registrar workflow marks enrollment official after finance clearance is available.
3. Grade changes remain in Registrar and academic workflows.
4. Finance-sensitive actions require audit.
5. Accounting actions and PayMongo events do not assign, reserve, or release section capacity.

---

### 2.7. Academic Head Workspace

Academic Head Workspace provides governance review queues for academic exceptions that require institutional oversight.

Academic Head queues:

1. Recorded curriculum approval result review.
2. Recorded curriculum amendment result and activation-impact review.
3. Scoped academic exception review and recorded approval.
4. Scheduling exception review.
5. Faculty term load override review, if institution requires Academic Head visibility.
6. Academic progression exception approval.
7. Recorded program-shift credit evaluation review, when institutional visibility requires it.
8. Graduation eligibility exception review.
9. Student Unit Load Exception approval, when configured.

Rules:

1. In-system approval or rejection actions, where a module explicitly retains them, require a recorded decision.
2. Rejection requires reason.
3. Academic Head approvals are audited.
4. Ledger entries remain in Accounting workflow.
5. Grade posting remains in Registrar workflow.

---

### 2.8. Workspace Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Sign in, register, recover password, verify identity | Record Form with validated identity fields and explicit submission |
| View assigned work | Role-filtered Operational Queue / Review Table |
| Create or update one applicant, student, faculty, or staff profile | Record Form; referenced roles, programs, and statuses use Selection Lists |
| Review admission, enrollment, grade, finance, or academic records | Review Table opening a read-only record summary plus only the authorized action form |
| Approve, reject, post, release, override, or activate | Focused Record Form or confirmation requiring reason/evidence and a labeled action |
| Faculty availability | Calendar / Date-Range Input with repeated unavailable or preferred blocks |
| Faculty grade entry | Class-roster Editable Table defined in Module 10 |
| View COR, SOA, schedules, grades, and reports | Generated Read-Only View with authorized print, download, or export actions |

Workspace dashboards may summarize counts and alerts, but official entry and decisions occur through the interaction form owned by the corresponding module.

---
