## 1. Product Intent & Architecture

---

### 1.1. Product Name

**T.A.L.A. — Timetable-Integrated Academic Lifecycle Administration**

Full capstone title:

**T.A.L.A.: A Timetable-Integrated Academic Lifecycle Administration System with Constraint-Based Academic Scheduling Using Google OR-Tools**

TALA is a College-focused academic lifecycle administration system for managing the official academic flow of Servitech Institute Asia. Its central technical feature is timetable-integrated, constraint-based academic scheduling connected to curriculum, term offerings, faculty availability, room assignment, enrollment, COR generation, and Student Hub visibility.

TALA must support institutional workflows from applicant intake to official enrollment, finance evidence, scheduling, grades, student status, reports, source-derived outputs, and audit.

### 1.2. Product Intent

TALA supports the official College academic lifecycle:

Applicant intake → admission review → applicant-to-student handover → student master record → curriculum assignment → term offering → scheduling → enrollment gates → assessment → payment evidence → ledger posting → COR/SOA generation → Student Hub visibility → faculty rosters → grade encoding → grade release → lifecycle status management → reporting and audit.

Manual office activities continue where institutional policy requires paper review, signatures, or cashier handling. TALA owns the academic, enrollment, scheduling, finance-evidence, grade, source-derived output, report, security, and audit records required for official SIS operation.

TALA is the source of truth for official SIS records. External systems provide computation, infrastructure, or payment evidence only.

Known product integrations:

1. Google Cloud Run CP-SAT scheduling service.
2. PayMongo payment gateway.
3. Email notification service.

---

### 1.3. Product Boundary

#### 1.3.1 Included Product Scope

TALA owns and implements the following product areas:

1. Identity, users, roles, permissions, and account lifecycle.
2. Applicant intake, minimal upfront identity verification, admission checklist metadata, and requested digital admission evidence.
3. Admission category, credential basis, flat document checklist requirements, physical-copy tracking, and requested digital evidence.
4. Applicant review, correction requests, duplicate review, and approval for handover.
5. Applicant-to-student handover.
6. Official student master records.
7. Student number generation.
8. Program and curriculum assignment.
9. Academic calendar and term setup.
10. Course Catalog identities and effective Course Specification revisions.
11. Course equivalency.
12. Versioned curriculum and Course Specification CSV template download, upload, validation, recorded approval, locking, amendment, and supersession.
13. Term offering builder.
14. Faculty profile, qualification mappings, availability, and term load overrides.
15. Rooms and facilities.
16. Scheduling inputs, CP-SAT solver integration, candidate schedule validation, publication, and lightweight schedule revision events.
17. Enrollment gates.
18. New applicant enrollment.
19. Continuing and irregular enrollment.
20. Registrar-controlled section capacity and enrollment seat reservation.
21. COR / Registration Form dynamic print view, access control, lightweight print logging, and download/print handling.
22. Assessment and fee rules.
23. Manual payment evidence.
24. PayMongo payment evidence.
25. Ledger, balance, adjustment, reversal, and reconciliation.
26. SOA generation.
27. Payment acknowledgement generation.
28. Financial Accommodation recording, including a promissory-note reference when required by institutional policy.
29. Faculty Workspace.
30. Faculty class lists and rosters.
31. Grade encoding, submission, review, posting, release, and correction.
32. Student status, holds, recorded Student Lifecycle Changes, readmission, reactivation, section transfer, transfer-out, and graduation eligibility evaluation.
33. Student Hub.
34. Source-derived academic and finance outputs, access logs, and print/download tracking.
35. Role dashboards, operational review screens, and exception lists.
36. Reports.
37. Imports and exports.
38. Email notifications.
39. Privacy, security, access logs, retention categories, and audit.
40. System configuration and integration settings.

Rules:

1. TALA separates checklist requirements from stored files.
2. TALA supports physical-copy tracking and metadata-only checklist completion.
3. TALA stores digital admission evidence when required upfront, requested by staff, or explicitly configured by institutional policy.
4. TALA keeps the checklist status, verification status, holds, handover eligibility, and enrollment-blocking document conditions.

#### 1.3.2 Institution-Handled Workflows

The following workflows are handled by the relevant office outside TALA. TALA supports them only through source records, holds, status visibility, generated outputs, or audit evidence when those records affect the academic lifecycle:

1. Senior High School operations are handled through separate school processes.
2. Document and credential requests are handled by the Registrar office.
3. TOR, Diploma, Form 137, Form 138, and certificate release are handled by the Registrar office.
4. Courier, LBC, pickup, and claiming activities are handled by office procedures.
5. Official tax receipts are issued through the institution's cashier/accounting process.
6. Government portal reporting is prepared outside TALA unless a future integration is approved.
7. Full-section and excess-demand decisions are resolved by Registrar reassignment, a capacity adjustment that remains within physical-room limits, or a new scheduling run.
8. LMS instruction, modular packet distribution, and learning-material tracking remain classroom or LMS processes.
9. Public artifact verification and QR scanning are handled only if a future approved policy adds them.

TALA still tracks admission-document requirements, stores approved admission evidence, and renders or exports internal outputs such as COR, SOA, payment acknowledgement, student schedules, class rosters, and graduation eligibility snapshots.

#### 1.3.3 Office Action Result Rule

When an office-handled workflow affects the academic lifecycle, the PRD must name the TALA result record created or updated by that office action.

Pattern:

Office action happens outside TALA -> authorized staff records the result in TALA -> TALA applies gates, visibility, output, and audit rules.

Examples:

1. Curriculum approval outside TALA -> `curriculum_version` becomes `Recorded Approved` or `Active`.
2. Grade correction approval outside TALA -> `grade_correction` record is created.
3. Cashier OR issuance outside TALA -> OR number is mapped to existing payment evidence or ledger entry.
4. Faculty qualification approval outside TALA -> active faculty-subject qualification record is created.
5. Overload approval outside TALA -> term-specific load override record is created.
6. Student lifecycle approval outside TALA -> Registrar records the approved Student Lifecycle Change -> TALA updates student status, enrollment, capacity, COR, finance effects, and audit without automatically rerunning CP-SAT.
7. Credential or document request outside TALA -> TALA exposes holds, status, or source records only when they affect enrollment, clearance, or record release.

---

### 1.4. User Input and Interaction Contract

The PRD identifies the interaction form needed to enter or review information without prescribing page layout, visual styling, or a specific frontend component library.

Canonical interaction forms:

1. **Record Form:** creates or edits one record using typed fields, selectors, dates, amounts, and validation.
2. **Focused Record Form:** records one authorized decision, correction, override, posting, release, or lifecycle result with reason, authority, evidence reference, and impact preview when required.
3. **Restricted Record Form:** captures sensitive configuration or credentials using permission checks and write-only or secure references where applicable.
4. **Editable Table:** captures repeated comparable rows, with row-level validation and controlled add/remove actions.
5. **Selection List:** chooses existing authoritative records; it must not accept free text when a referenced record is required.
6. **Checklist:** records multiple independent yes/no or status decisions.
7. **Calendar / Date-Range Input:** captures dates, windows, recurrence, or availability where chronological context matters.
8. **File Upload with Preview:** accepts a defined file type, validates it, and requires review before posting official records.
9. **Operational Queue / Review Table:** lists records requiring staff action and opens the selected record or a focused action form.
10. **Filter Form:** narrows generated tables and exports using controlled date, term, program, status, role, or ownership filters.
11. **Generated Read-Only View:** derives information from authoritative records and permits only allowed view, print, download, or export actions.

Rules:

1. Free text is limited to names, addresses, references, reasons, and notes; codes, statuses, terms, courses, sections, people, fees, and authorities use controlled values or authoritative-record selection.
2. Bulk upload supplements normal forms and tables only where a module explicitly permits import.
3. A supported user-facing import uses the current TALA CSV Import Template for that record type.
4. A destructive, posting, release, activation, override, or status-changing action requires a preview or confirmation showing its effect.
5. Computed totals, eligibility, conflicts, balances, schedules, and official outputs are read-only results; users change the relevant source records in the owning workspace.
6. Each module's interaction table is a functional contract for implementation, not a visual design specification.

---
