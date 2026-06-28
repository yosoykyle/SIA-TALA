## 3. Admissions & Student Handover

---

### 3.1. Simplified Admission Model (Flat Checklist System)

The admission model uses a simplified flat checklist approach to track document compliance for both Applicants and Students.

1. **Flat Checklist Items:** The system tracks individual document requirements (e.g., Birth Certificate, Form 137, Transcript of Records) mapped directly to the Applicant or Student record.
2. **Checklist Item States:** Each required document has a status of `Pending`, `Received Physical`, `Received Digital`, `Accepted`, `Rejected`, `Waived`, or `Undertaking Approved`.
3. **Upfront Digital Upload:** Applicant Workspace captures basic profile data and exactly one required digital upload: `identity_document_url` (verified by the Registrar).
4. **Physical Tracking & Verification:** The Registrar updates individual checklist item states as physical documents are received. Handover is blocked if any requirement marked as "Blocks Handover" remains unresolved.

Document compliance is represented as direct checklist items on the applicant or student record. 

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
3. **Archiving Duplicates:** The duplicate student profile is marked as `ARCHIVED`, with `archive_reason` set to `DUPLICATE_PROFILE`.
4. **Reference Linkage:** The archived duplicate profile stores a pointer to the primary student profile in `merged_into_student_id`.
5. **Resolution Logging:** Every duplicate resolution action creates a record in the `duplicate_profile_resolutions` table containing:
   - `id`, `duplicate_student_id`, `primary_student_id`
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
| Applicant personal, contact, prior-school, and program-choice information | Multi-section Record Form saved as a draft before final submission |
| Admission requirements | Checklist of configured Admission Checklist Items; each item exposes only its allowed evidence method |
| Digital evidence | File Upload with file-type/size validation, preview, and replace/resubmit action |
| Physical-copy or metadata-only evidence | Staff Record Form capturing received/verified status, date, recorder, and reference; no artificial upload requirement |
| Applicant review | Operational Queue / Review Table with filters and a focused decision form |
| Handover | Read-only comparison/preview of applicant and proposed student records, followed by an explicit confirmation action |
| Possible duplicate student | Review Table comparing candidate official profiles; staff select reuse, merge according to policy, or stop handover |
| Student master profile | Record Form for authorized staff; program, curriculum, status, and identity references use Selection Lists |
| Student self-service profile changes | Record Form containing only the editable contact, address, guardian, and emergency-contact fields |

Handover carries forward accepted applicant data for staff review and confirmation.

---
