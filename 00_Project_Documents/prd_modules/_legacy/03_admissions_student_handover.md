## 3. Admissions & Student Handover

> **Legacy input — not current product authority.** Preserved for traceability and bounded salvage review only. Canonical PRD 02 governs application, admission decisions, credentials, and derived enrollment readiness.

---

### 3.1. Simplified Admission Model (Flat Checklist System)

The admission model uses a simplified flat checklist approach to track document compliance for both Applicants and Students.

1. **Flat Checklist Items:** The system tracks individual document requirements (e.g., Birth Certificate, Form 137, Transcript of Records) mapped directly to the Applicant or Student record.
2. **Checklist Item States:** Each required document has a status of `Pending`, `Received Physical`, `Received Digital`, `Accepted`, `Rejected`, `Waived`, or `Undertaking Approved`.
3. **Policy-Driven Upfront Digital Upload:** Applicant Workspace resolves the effective admission requirement policies for the selected admission category and credential basis. Each applicable requirement whose evidence method is `DIGITAL_UPLOAD` receives its own private upload field, including the identity document when required. A draft may contain partial uploads, but final submission requires every blocking digital-upload requirement. Each submitted file becomes separate document evidence linked to its matching checklist item for individual Registrar review.
4. **Physical and Metadata Tracking:** Requirements configured as `PHYSICAL_COPY` or `METADATA_ONLY` are not uploaded during applicant intake. The Registrar records and verifies those checklist items through the staff workflow. Handover is blocked if any requirement marked as "Blocks Handover" remains unresolved.
5. **Correction and Versioning:** Rejected digital evidence places the application in `Action Required`. The applicant replaces the rejected item from the Requirements page; the system retains the evidence-version link, checksum, private-storage controls, and audit history.

The Applicant Wizard validates the current step before moving forward so errors appear beside the information being entered. Personal, contact, guardian, and blocking digital-evidence fields required for final submission are enforced during step progression and again by the intake service at submission. `Save Draft` is intentionally different: it accepts an incomplete application, validates only values already supplied plus the minimum application scope, and returns field-level and plain-language failure feedback without advancing the workflow. Applicant and parent/guardian mobile numbers use the V1 Philippine local format of exactly 11 digits beginning with `09`.

`Prior School` means the applicant's most recent school attended; it is applicant education information, not guardian information. `Same as applicant address` is a form-only convenience: while selected, the guardian address follows the applicant address and cannot be independently edited. Clearing it restores manual guardian-address entry without changing the stored intake schema.

Document compliance is represented as direct checklist items on the applicant or student record.

#### Admissions availability and applicant withdrawal

1. Public applicant registration and creation of a first intake are available only while at least one active institution-scoped `Admissions` calendar window is open for an active term.
2. Final submission is allowed only while the selected term's `Admissions` window is open. A missing, inactive, future, or expired window fails closed with a clear message.
3. Closing admissions does not block existing applicant accounts from signing in, saving an existing draft, viewing a submitted intake, or responding to an allowed Registrar correction. The landing page replaces application calls to action with an applications-closed explanation while preserving Applicant Sign In.
4. An applicant may withdraw only their own `Draft` or unreviewed `Pending` intake before approval or student handover. Withdrawal is state-based and has no elapsed-time limit.
5. Withdrawal requires a concise plain-text reason. The intake becomes terminally `Withdrawn`, the withdrawal timestamp is stored in `archived_at`, and the actor and reason are retained in the immutable activity log.
6. Applicant surfaces show the withdrawal date, reason, and Registrar recovery guidance. Registrar lists show the status and date; the intake detail shows the actor and reason. The reason is not included in ordinary exports or a new report.
7. An applicant account may retain multiple immutable application records, but only one nonterminal intake may be active at a time and no more than one intake may exist for the same applicant account and academic term.
8. A withdrawn intake remains terminal history. The applicant may begin a new intake for a different term only while that term's `Admissions` window is open. A same-term retry requires Registrar assistance and must not create a silent duplicate.
9. Withdrawal soft-archives the intake for authorized history and audit use. Applicant records and admission evidence follow the `Archive After Review` category in Section 13.7; the institution owns the exact retention period. V1 does not automatically expire or physically delete the applicant account.

Configured admission policies define which checklist items apply by admission category and credential basis. Applicant or student checklist items track the actual requirement status, accepted evidence method, blocking effect, review result, and resolution.

Checklist item fields:

1. Requirement Type
2. Owner Type: Applicant or Student
3. Owner ID
4. Status
5. Blocking Level
6. Evidence Method
7. Verification Status
8. Deadline
9. Source Policy
10. Reviewed By
11. Reviewed At
12. Notes

Blocking levels:

1. Blocks Handover
2. Blocks Enrollment
3. Blocks COR Print
4. Blocks Record Release
5. Retention Only
6. Advisory Only

Evidence methods:

1. Physical Copy
2. Digital Upload
3. Metadata Only

Verification statuses:

1. Not Reviewed
2. Verified
3. Rejected

Resolution rule:

1. A blocking checklist item is resolved when it is accepted, verified, waived, overridden, or covered by an approved undertaking.
2. Non-blocking retention items may remain open after handover or enrollment when institutional policy allows it.
3. Rejected items remain visible to staff until corrected, waived, overridden, or replaced by an approved undertaking.

---

### 3.2. Applicant-to-Student Handover

Handover creates or reuses one official student profile.

Student number default format:

`SIA-YYYY-NNNN`

Rules:

1. Generate student number only during official handover.
2. Do not encode sensitive data in the student number.
3. Never reuse retired numbers.
4. Returning Student / Readmission applicants reuse the existing student number if identity match is confirmed.
5. Transfer Applicant and First-Time College Applicant records create a new student profile only when no existing official student profile should be reused. (TALA supports degree-seeking students bound to full curricula. Registrar staff handle non-degree-seeking admissions and academic placement manually outside TALA. TALA keeps records exclusively for matriculated students enrolled in official programs.)
6. Student Hub access activates only after handover.
7. Applicant evidence history and checklist metadata remain linked to the official student profile.
8. Failed enrollment after handover does not delete the student profile.
9. Admission checklist status may convert into student retention, document, COR, record-release, or enrollment hold status where needed.
10. Support Flags that remain relevant after handover may convert into student holds, notes, clearance requirements, or restricted student-record metadata.
11. Missing non-blocking retention documents may remain open after handover if institutional policy allows it.
12. Handover must be blocked only by unresolved requirements configured as Blocks Handover.

---

### 3.3. Duplicate Official Student Profile Resolution

Duplicate official student profiles are resolved by Registrar review. If a duplicate is confirmed, the Registrar archives the duplicate profile and links it to the primary profile.

**Rules:**

1. **Record Preservation:** Grades, payments, enrollments, and documents stay attached to their original profile. The duplicate profile is linked to the primary profile for audit integrity.
2. **Primary Selection:** The Registrar reviews the records and selects the primary (master) student profile.
3. **Archiving Duplicates:** For `LINKED_DUPLICATE` resolutions, the duplicate student profile is marked with `lifecycle_status = ARCHIVED` and `archived_at` is set. The resolution explanation is stored in `duplicate_profile_resolutions.reason`; no separate `archive_reason` column is used.
4. **Reference Linkage:** The archived duplicate profile stores a pointer to the primary student profile in `merged_into_id`.
5. **Resolution Logging:** Every duplicate resolution action creates a record in the `duplicate_profile_resolutions` table containing:
   - `id`, `duplicate_student_profile_id`, `primary_student_profile_id`
   - `resolution_type` (Enum: `LINKED_DUPLICATE`, `NOT_DUPLICATE`, `KEEP_SEPARATE`)
   - `reason` (Required explanation text)
   - `resolved_by`, `resolved_at`
6. **Visibility Restriction:** Archived duplicates are hidden from normal search views, reports, and Student Hub logins, but remain accessible to staff for historical audit lookups.
7. **Manual Corrections:** Any required academic or finance adjustments are handled manually by authorized Registrar or Accounting staff using existing correction workflows.
8. **Student Number Preservation:** Duplicate student numbers remain retired or archived and are not reissued.

---

### 3.4. Student Records

The official student profile is the canonical source for:

1. Student identity.
2. Program.
3. Curriculum assignment.
4. Student status.
5. Enrollment history.
6. Academic history.
7. Holds.
8. Source-derived academic outputs and official access logs.

Rules:

1. Student profile changes require authorized workflow.
2. Sensitive identity updates (e.g., name, birthdate) require Registrar verification in person.
3. Status changes must come from an authorized recorded result, including a Student Lifecycle Change where applicable, and remain typed, reasoned, effective-dated, permission-controlled, and auditable.
4. Student records must remain confidential and scoped to authorized users.

---

### 3.5. Profile Updates (MVP Workflow)

For the MVP, student profile updates are divided into Editable (Self-Service) and Locked (Admin-Only) fields to reduce administrative burden while maintaining data integrity.

#### 3.5.1 Locked Fields (Admin-Only)

These fields represent the student's legal identity or official school-record identity and are staff-controlled.

1. First Name, Middle Name, Last Name
2. Date of Birth
3. Prior-education identifiers (e.g., Learner Reference Number)

*Update Process:* The student physically presents legal evidence to the Registrar's office. The Registrar updates the record through an authorized staff Record Form.

#### 3.5.2 Editable Fields (Self-Service)
These fields are operational and can be directly updated by the student via the Student Hub without staff review.
1. Contact Information (Phone Number, Personal Email)
2. Current Home Address
3. Guardian or Emergency Contact Details

*Update Process:* Student logs into Student Hub → Navigates to Profile → Edits allowed fields → System instantly saves the new values.

Rules:

1. Locked field updates require an authorized Registrar role.
2. The system must log the date, time, and user (student or staff) who modified any profile fields for basic auditability.
3. Staff-only notes remain in Staff Workspace records.
4. Changes affecting source-derived outputs must still trigger output impact review if applicable.

---

### 3.6. Admission and Student-Record Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Applicant personal, contact, guardian, prior-school, and program-choice data | Three-step Wizard saved as a draft before final submission: Personal Information, Required Documents, and Review and Submit. Applicant intake does not ask for a student-level delivery modality; Online or Face-to-Face is assigned later to each subject offering. |
| Admission requirements | Checklist of configured Admission Checklist Items with human-readable requirement, evidence-method, blocking, verification, and status labels; each item exposes only its allowed evidence method |
| Digital evidence | One private File Upload per applicable `DIGITAL_UPLOAD` policy, with file-type/size validation, preview, and per-item replace/resubmit action |
| Physical-copy or metadata-only evidence | Applicant guidance distinguishes `Bring to the Registrar` from staff-tracked metadata; staff use a Record Form capturing received/verified status, date, recorder, and reference; no artificial upload requirement |
| Applicant review | Operational Queue / Review Table with filters and a focused decision form |
| Handover | Read-only comparison/preview of applicant and proposed student records, followed by an explicit confirmation action |
| Possible duplicate student | Review Table comparing candidate official profiles; staff select reuse, merge according to policy, or stop handover |
| Student master profile | Record Form for authorized staff; program, curriculum, status, and identity references use Selection Lists |
| Student self-service profile changes | Record Form containing only the editable contact, address, guardian, and emergency-contact fields |

Handover carries forward accepted applicant data for staff review and confirmation.

---
