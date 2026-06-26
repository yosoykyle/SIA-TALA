## 3. Admissions & Student Handover

---

### 3.1. Simplified Admission Model (Flat Checklist System)

The admission model uses a simplified flat checklist approach to track document compliance for both Applicants and Students.

1. **Flat Checklist Items:** The system tracks individual document requirements (e.g., Birth Certificate, Form 137, Transcript of Records) mapped directly to the Applicant or Student record.
2. **Checklist Item States:** Each required document has a status of `Pending`, `Received Physical`, `Received Digital`, `Accepted`, `Rejected`, `Waived`, or `Undertaking Approved`.
3. **Upfront Digital Upload:** The online portal captures basic profile data and exactly one required digital upload: `identity_document_url` (verified by the Registrar).
4. **Physical Tracking & Verification:** The Registrar updates individual checklist item states as physical documents are received. Handover is blocked if any requirement marked as "Blocks Handover" remains unresolved.

Document compliance is represented as direct checklist items on the applicant or student record. 
**Hybrid Dictionary Approach:** The system retains administrative dictionary tables (`DocumentRequirementItem`, `AdmissionRequirementPolicy`) to allow staff to configure global requirements, but deletes legacy tracking models (`RetentionDocumentUndertaking`, `ApplicantDocumentRequirement`). These are strictly replaced by the polymorphic `checklist_items` table for actual student/applicant tracking. Staff configure which items block handover or enrollment and record the accepted evidence method for each item.

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
2. Sensitive identity corrections require evidence and Registrar verification.
3. Status changes must be typed, reasoned, effective-dated, permission-controlled, and auditable.
4. Student records must remain confidential and scoped to authorized users.

---

### 3.5. Personal Data Correction Workflow

Students may submit personal data correction requests for approved categories.

#### 3.5.1 Correction Request Fields

Required fields:

1. Request ID
2. Student ID
3. Correction Category
4. Current Value
5. Requested Value
6. Reason
7. Supporting Evidence
8. Submitted At
9. Review Status
10. Reviewed By
11. Review Decision
12. Applied By
13. Audit Metadata

#### 3.5.2 Correction Categories

Supported categories:

1. Name correction
2. Birthdate correction
3. Contact information correction
4. Address correction
5. Guardian or emergency contact correction
6. Prior-education identifier correction
7. Other Registrar-approved correction

#### 3.5.3 Flow

Student submits request → evidence uploaded → Registrar reviews → request approved or rejected → approved correction updates official profile → affected source-derived outputs are checked for display/update impact → student is notified.

Rules:

1. Sensitive corrections require evidence.
2. Rejected requests remain auditable.
3. Approved corrections must not erase previous values.
4. Staff-only notes must not appear in Student Hub.
5. Changes affecting source-derived outputs must trigger output impact review.

---
