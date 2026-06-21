# T.A.L.A. System - Functional Specification

**Total Academic Lifecycle Automation System**

**Servitech Institute Asia (SIA)**

---

## Document Control

Versioning rule: major version increments once per update date; same-day updates are consolidated.

| Version | Date | Description |
| --- | --- | --- |
| 1.0 | 2026-04-02 | FS baseline consolidated. |
| 2.0 | 2026-04-30 | Hybrid uploads; OCR review; staff verification. |
| 3.0 | 2026-05-01 | Queue, scheduler, Redis, Horizon job strategy. |
| 4.0 | 2026-05-02 | Student Hub/PWA; period grading; ToC and appendix fixes. |
| 5.0 | 2026-05-03 | Offline POST descoped; INC blocks; 3 modalities; PUP transmutation; PayMongo. |
| 6.0 | 2026-05-04 | Heading cleanup; SHS grade range; Student Hub finance; promissory approval. |
| 7.0 | 2026-05-05 | Advising rename; COR/LIS split; SHS irregular load; TallStackUI. |
| 8.0 | 2026-05-12 | Curriculum intake; faculty availability self-service; role split. |
| 9.0 | 2026-05-13 | Applicant, OCR, payment, and calendar workflow refinements. |
| 10.0 | 2026-05-14 | Maintenance mode; user messages; legacy import framework. |
| 11.0 | 2026-05-18 | Student records simplification; PayMongo/OCR/advising locks; grade-correction states. |
| 12.0 | 2026-05-20 | Walk-in metadata; fee/installment rules; enrollment/payment/import policies. |
| 13.0 | 2026-05-21 | Complexity audit: delivery, Google Vision OCR, calendar, fees, ledger, rate limits. |
| 14.0 | 2026-05-22 | Imports, notifications, curriculum, grading, fee terminology; SHS template expansion. |
| 15.0 | 2026-05-23 | Subsidy workflow replaced by freshmen discount. |
| 16.0 | 2026-05-24 | Calendar/installment locks; migration/Fortify/FAQ boundaries. |
| 17.0 | 2026-06-02 | Admin role hardening; canonical split-name contract. |
| 18.0 | 2026-06-03 | Settings debloat; admin CRUD boundaries for schedule, documents, payments, ledger, grades. |
| 19.0 | 2026-06-04 | Faculty class list and grade-encoding boundary. |
| 20.0 | 2026-06-05 | Admin lifecycle surfaces: documents, enrollment, installments, service requests, RBAC, schedules, COR, FAQ. |
| 21.0 | 2026-06-06 | Review/detail hardening; labels; private paths; Student Hub/FAQ access; audit closeout. |
| 22.0 | 2026-06-07 | Scheduling scope approved: descopes, GCP OR-Tools, eligibility, hard constraints. |
| 23.0 | 2026-06-08 | Teacher/adviser requirement; editable max seats capped at 30. |
| 24.0 | 2026-06-09 | Scheduling planning order; solver deployment; promissory and returnee boundaries. |
| 25.0 | 2026-06-10 | Guided tour removed; Phase 1 boundary; foundation admin resources. |
| 26.0 | 2026-06-11 | Controlled import; Academic Head approval; PayMongo smoke command. |
| 27.0 | 2026-06-12 | Google Vision evidence; live OCR/PayMongo smoke; FAQ governance cleanup. |
| 28.0 | 2026-06-14 | Backend services active; Student Hub UI pending; scheduling readiness hardened. |
| 29.0 | 2026-06-17 | Scheduling closure; delivery groups; curriculum readiness; publish lifecycle; workload overrides; PayMongo handover parity. |
| 30.0 | 2026-06-18 | Student-domain backend contracts; assessment/payment/promissory/adjustment contracts; document lifecycle requirements. |
| 31.0 | 2026-06-19 | Workflow reconciliation; roster/admissions benchmarks; Old Curriculum decision; UI test baseline. |
| 32.0 | 2026-06-21 | System freeze; submission baseline; Feature Groups 3-11 hardening; legal/regulatory audit fixes. |

---

**Document Scope Boundary:** This document defines functional requirements and business rules only. Project execution status, QA progress, and implementation ownership live outside the FS in project management artifacts.

**Specification Scope Boundary:** This FS is intended to be the capstone/paper baseline for the final-form TALA system. It is grounded in SIA business evidence, the workflow reconciliation matrix, and the benchmark baseline matrix. Mature SIS references establish the normal lifecycle spine: person/student master data, admissions, enrollment, academic records, scheduling/classes, grades, student financials, self-service, official documents, and completion/graduation processing. SIA-specific policy and workflow evidence narrows how that spine behaves locally.

### Submission Baseline Module Map

| Baseline area | Final-form functional baseline | Benchmark/source basis | Scope Phase |
| --- | --- | --- | --- |
| Identity, roles, and protected access | Separate applicant, student, staff, faculty, Accounting, Registrar, Academic Head, and System Super Admin boundaries with one-role operational authority where required. | Mature SIS role/self-service separation; Filament staff UI boundary; current RBAC model. | Core |
| Applicant intake and admissions | Published term-scoped offerings, applicant self-service, Registrar-assisted intake, configurable requirement policy, checklist states, and admission-gate versus retention-document separation. | Mature admissions lifecycle; Frappe admission pattern; SIA admission workflow; regulatory document-transfer benchmarks. | Core |
| Student master record and enrollment | Applicant-to-student handover creates or reuses one official profile and one canonical enrolled state after admission, finance, capacity, and placement gates clear. | Mature SIS person/student records and matriculation patterns; SIA enrollment/payment workflow. | Core |
| Calendar, curriculum, subjects, sections, and scheduling | Education-level-aware academic years/terms, curricula, section/delivery groups, faculty availability, conflict validation, and solver-assisted schedule generation with staff review. | Mature SIS course/schedule/class-readiness model; OR-Tools CP-SAT benchmark; SIA modality evidence. | Core/Supporting |
| Finance, assessment, ledger, payments, SOA, and receipts | Fee assessment, discounts, installments, manual/online payment confirmation, immutable ledger, computed balance/clearance, SOA/receipt evidence, and privacy-safe finance visibility. | SIS Student Financials benchmark; PayMongo payment lifecycle; SIA SOA/payment evidence. | Core |
| Faculty class lists and grades | Faculty sees only assigned classes; grades are encoded under versioned grading profiles, submitted, verified/finalized, and corrected only through authorized audited workflows. | SIS Student Records/gradebook patterns; DepEd grading benchmark for SHS; SIA class-record evidence. | Core |
| Student Hub and applicant/student self-service | Student-facing read-only visibility for profile, enrollment status, COR, schedule, grades, balance, documents, notifications, and FAQ/help, with later mutation workflows added only through dedicated services. | Campus self-service benchmark; Livewire/TallStackUI/PWA pattern. | Core-lite |
| Official generated documents | COR, COE, COG, TOR/Form 137 copies, report cards, SOA/receipts, and diploma/certificate artifacts are generated from authoritative records with issuance, release, and verification evidence. | Student Records transcript/enrollment-verification/graduation benchmark; PDF/QR verification baseline; SIA document-request workflow. | COR Core/Supporting; full catalog Supporting/Phase 2 |
| Student status, adjustments, and graduation | Drop/withdraw/shift/transfer/modality changes, LOA/readmission, transfer-out, completion/graduation eligibility, deficiency review, and credential release use typed stateful workflows. | Mature SIS student lifecycle and graduation processing; SIA status/graduation workflow. | Boundary/Supporting |
| Reports, imports, exports, and external systems | Controlled imports/exports support rosters, schedules, finance, grades, and source evidence. Government portals and outside office systems remain external; TALA provides accurate data and generic exports where useful. | Laravel Excel/import-export benchmark; mature SIS reporting boundary; SIA external workflow clarification. | Supporting/External Boundary |
| Security, privacy, audit, and evidence | Sensitive student, finance, document, OCR, support, and generated-artifact data remain role-scoped, privately stored where required, audited, and exposed only through authorized workflows. | Data Privacy Act controls, file-upload/security benchmarks, private-storage design. | Core |

### Submission Readiness Rule

This FS is submission-ready only when each baseline area above has enough detail for a reader to identify: responsible role, entry point, workflow states, user action, success result, validation failure, business edge case, access boundary, generated evidence, and the related TS contract. Implementation progress is deliberately tracked outside this FS so the submitted specification remains a stable target rather than a moving development diary.

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [System Overview](#2-system-overview)
3. [User Roles & Access Matrix](#3-user-roles--access-matrix)
4. [Module 1: Student Module](#4-module-1-student-module)
5. [Module 2: Registrar Module](#5-module-2-registrar-module)
6. [Module 3: Accounting Module](#6-module-3-accounting-module)
7. [Module 4: Faculty Module](#7-module-4-faculty-module)
8. [Module 5: Administration & Integration](#8-module-5-administration--integration)
9. [Module 6: Service Requests & Documents](#9-module-6-service-requests--documents)
10. [System Lifecycle](#10-system-lifecycle)
11. [User Onboarding Guidance](#11-user-onboarding-guidance)
12. [Appendices](#12-appendices)

---

## 1. Executive Summary

T.A.L.A. (Total Academic Lifecycle Automation) is a comprehensive School Information Management System (SIS) designed specifically for Servitech Institute Asia (SIA). The title "T.A.L.A." (Filipino for Star/Guide) reflects the system's role as the central source of truth for academic management.

The system replaces fragmented manual processes (paper forms, Google Sheets, separate accounting logs) with a unified, automated platform. It streamlines the entire student lifecycle-from online enrollment and document validation to grade management and financial clearance-while preserving applicable DepEd/CHED, institutional-policy, and Data Privacy Act boundaries.

This document serves as the **Functional Specification** for the system, detailing the logical workflows, business rules, and user-facing features.

### Core Acceptance Boundary

System acceptance depends on the following functional requirements. These are functional acceptance boundaries, not a project-management task list:

- **Academic foundation behavior**: Registrar/Academic Head-approved staff must be able to maintain or import Programs, Subjects, Curricula/Curriculum Subjects, Terms, Sections, and the minimum safe room input needed by scheduling. Local seeders are QA support only and are not the production/staff starting point.
- **Academic Head approval**: Any grade correction or override that changes an official/finalized grade must receive an authenticated in-system Academic Head approval action before Registrar resolution applies the corrected values. Registrar-only recording of prior offline approval is not sufficient for system acceptance.
- **Live integrations**: PayMongo and Google Cloud Vision OCR must pass live sandbox/configured-environment smoke checks before readiness. Mock drivers remain for automated tests and local fallback, not for final Phase 1 sign-off.
- **Controlled import**: Legacy/curriculum import must support strict template download, upload, validation preview, commit, and audit evidence. Audit-only import batch viewing is not enough.

**Core Implementation Boundary:** Registrar/authorized staff can manage Programs, Subjects, Curricula/Curriculum Subjects, Terms, Sections, Section Delivery Groups, Delivery Patterns, and Rooms through typed Filament resources and guarded policies. On-site/blended delivery groups must select an active Room when their meeting rows are room-required; online/modular delivery groups keep room blank unless a later approved policy requires otherwise. Curriculum/foundation import must support strict template download, private upload, parse/validation preview, zero-error commit, explicit per-scope `ready_for_scheduling` confirmation, and audit evidence. Official/finalized grade correction changes require an Academic Head approve/reject action before Registrar resolution applies corrected grade values. Official schedule publication requires Academic Head approval after Registrar review/commit; post-publication changes require the schedule-change workflow. PayMongo and Google Cloud Vision OCR code paths must provide operator smoke commands for webhook/ledger evidence and OCR/manual-review evidence; execution results belong in acceptance readiness artifacts. Internal runtime settings must remain blocked from generic raw Admin CRUD. FAQ content must remain maintainable through permission-gated System Super Admin CRUD while public and Student Hub users can read only published entries. Student, grade, financial, and enrollment legacy imports require separate controlled pipelines if they become required for acceptance. Backend contracts for applicant intake, student enrollment, subject suggestion, and student dashboard aggregation are part of the system acceptance boundary.

### Key Enhancements (Revised Requirements)

This specification integrates new requirements gathered from stakeholders:

- **Pre-Semester Data Preparation**: Curriculum and schedule uploads before enrollment opens
- **Credit Evaluation**: Assisted matching against curriculum
- **Modality Support**: Both modular and on-campus learning modes
- **Automated Freshmen Discount**: 50% Tuition Fee discount for eligible New/Freshmen (Grade 11 and 1st Year)
- **Expanded Payment Methods**: E-wallets, OTC, promissory notes
- **Service Requests & Documents**: Manual courier fulfillment with two-phase payment, online payment confirmations, Drop Forms, and Modality Change workflows
- **Data Privacy & Security**: RA 10173 and NPC 2023-06 controls for consent, retention, breach protocol, and security of personal data

---

## 2. System Overview

### 2.1 System Entry Point (Public Landing Page)

The system is accessed via a public landing page that serves as the central router for all users.

**Hero Section**: Servitech Institute Asia branding with "Welcome" message.

**Routing Actions**:
| Button | Target Audience | Destination |
|--------|----------------|-------------|
| Enroll Now | New Students | Module 1: Step 0 (Orientation) |
| Check Application Status | Applicants | Applicant Portal Login |
| Student Login | Official Students | Student Hub (PWA) |
| Staff Login | Employees | Admin Nexus (Filament Panel) |
| Help & Support (FAQ) | All Users | Public FAQ Page |

**Login Clarity**: The system uses separate login portals based on user type to ensure clear user experience and proper access control. Support inquiries are routed to a self-service FAQ to deflect unnecessary IT tickets.

**Panel Terminology Boundary**: "Admin Nexus," "Admin Panel," and similar Filament panel labels describe the shared staff operations interface only. They do not create or imply a generic `admin` role. Operational authority must always follow the approved roles in §3.1 and §3.2.

### 2.1.1 Goal-State UI Acceptance Baseline

The goal-state user interface is specified at the level needed for acceptance testing: user role, entry point, visible workflow state, required action, validation outcome, access boundary, notification behavior, and expected record/state effect. The FS does not prescribe pixel-perfect layouts, but every user-facing feature must have enough behavior defined to derive happy-path, negative-path, business-edge, and security/access test cases.

| Surface | Framework/UI Pattern | Goal-State Acceptance Rule |
| --- | --- | --- |
| Public landing and applicant intake | Laravel Blade/Livewire with TallStackUI-compatible public components | Public users can start enrollment, check application status, access FAQ/help, and reach the correct login route without seeing protected staff/student data. |
| Applicant portal | Livewire/TallStackUI forms, cards, upload controls, status banners, and toast feedback | Applicants complete staged intake, submit required data/documents, receive validation errors inline, see action-required status when rejected, and remain blocked from Student Hub until official handover. |
| Student Hub / PWA | Livewire + TallStackUI + Tailwind + Alpine.js, mobile-first tabs/cards/tables, read-only PWA cache for approved offline data | Active students can view dashboard, COR, schedule, grades, balance, payment/document/request status, notifications, and FAQ/help. Offline mode is read-only and must disable mutation actions. |
| Loading, splash, and offline fallback layer | Student Hub PWA via `erag/laravel-pwa`, manifest/service worker assets, TallStackUI buttons/alerts/progress, Livewire loading/offline directives, and Tailwind motion-safe/reduced-motion utilities | Installed Student Hub launches with branded manifest icon/splash behavior on supported platforms, shows stable loading/progress states for navigation/forms/uploads, disables duplicate submissions and offline mutations, provides a safe offline fallback, and respects reduced-motion/accessibility labels. |
| Admin Nexus staff panel | Filament v5 resources/pages/tables/forms/infolists/actions/widgets with policy-based navigation | Registrar, Accounting/Cashier, Faculty, Academic Head, and System Super Admin see only authorized menus, tables, actions, filters, widgets, and lifecycle buttons. Staff workflows use typed actions instead of raw CRUD where a lifecycle or audit rule exists. |
| Notification and feedback layer | Filament Notifications for Admin Nexus; TallStackUI toasts and inline errors for Student Hub/applicant forms | Success, warning, blocking, info, and error messages follow the standard message catalog in §8.2.3. Required-field and validation errors appear below fields, while workflow outcomes use toast/banner feedback. |
| Test-case baseline | `TALA-Master-System-Test-Cases.md` | The master test-case matrix is a locked goal-state acceptance baseline for submission. It may include approved and envisioned scenarios. Pass/Fail columns remain blank until execution; execution notes belong in comments, not in FS pass/fail language. |

Each role/module scenario in the master test-case matrix must trace back to this FS, the TS UI/technical contract, the module capability map, or an active reconciliation/SDD decision. If later development changes the approved behavior, the FS/TS and master test-case baseline must be versioned together.

### 2.2 Key Innovations

| Innovation                        | Description                                                                                                                           | User Benefit                                                             |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| **Smart Document Validation**     | Hybrid upload + Google Cloud Vision OCR: raw documents remain canonical evidence, while OCR text-matching helps staff review | Reduces manual checking time without treating extracted fields as the official record |
| **Conflict-Validated Scheduling** | Real-time room/teacher conflict detection                                                                                             | Prevents double-bookings                                                 |
| **Unified Financial Ledger**      | Seamless enrollment-billing pipeline                                                                                                  | Instant balance visibility                                               |
| **Offline-Ready Student Portal**  | PWA with cached COR and schedules                                                                                                     | Access without internet                                                  |
| **Automated Curriculum Matching** | Cross-checks report cards against curriculum                                                                                          | Suggests credited subjects                                               |
| **Dynamic Modality Scheduling**   | Auto-generates schedules based on modality choice                                                                                     | Flexible learning options                                                |

### 2.3 Benchmark-Hardened Lifecycle Acceptance Contracts

The following contracts define the feature-group baseline. Detailed module rules later in this FS remain controlling; these rows state the cross-module outcome, blocked-path behavior, and priority that must remain consistent across implementation and acceptance artifacts.

| Feature group | Final-form acceptance contract | Negative and business-edge boundary | Priority and traceability |
| --- | --- | --- | --- |
| Enrollment, sectioning, finance clearance, inventory, and COR | Official handover occurs once after admission gates, readiness, finance, and capacity rules pass. It creates or reuses one canonical student profile, one term enrollment, secured placement, account access, and COR/class-list eligibility. Registrar can filter enrolled students by year, term, level, program/strand, grade/year, section, delivery group, and status and export an authorized generic roster. | Tentative placement grants no protected access and may expire. Missing readiness, unpaid required amount, full capacity, duplicate identity, or concurrent handover blocks atomically with an actionable reason. Institution-caused placement failure routes to Registrar resolution rather than applicant rejection. DepEd/CHED/LIS submission remains external. | Phase 1 / Core; BM-01, BM-02, BM-04, BM-10, SIA-01; master enrollment/Registrar/COR cases. |
| Scheduling and automatic generation | Registrar prepares delivery groups, meetings, rooms, eligible faculty, workload, and availability; OR-Tools CP-SAT or an equivalent proven solver generates a reviewable proposal from an immutable input snapshot. Academic Head approval precedes official publication; students and faculty see only the published schedule. | Hard conflicts can never be overridden. Infeasible, unknown, or timed-out runs publish nothing and expose constraint-level diagnostics. Authorized workload/availability exceptions require reason and audit; post-publication changes use a controlled change workflow. | Phase 1 when schedule is on the demo path, otherwise Supporting; BM-05, BM-06, SIA-01; schedule generation and schedule publication cases. |
| Finance, assessment, payments, ledger, SOA, and receipts | Effective-dated fee, discount, scholarship, installment, and downpayment policies produce an assessment. Manual Accounting confirmation and PayMongo outcomes post through the same idempotent immutable ledger contract. Balance and finance clearance are computed; SOA and receipts are derived, versioned artifacts. | Duplicate references/webhooks post once; failed or pending provider outcomes do not clear finance; overpayment remains credit; corrections use reversal/adjustment entries rather than editing history. Balances remain private and debt does not become an undocumented exam-access block. | Phase 1 for assessment/payment/clearance; advanced Deferred; BM-01, BM-05, BM-09, BM-13, SIA-01; finance and payment cases. |
| Faculty class lists, grades, verification, and correction | Faculty sees only officially assigned and published classes. Grade entry follows the applicable effective-dated grading profile; submission locks a review snapshot; Registrar verifies completeness and Academic Head authorizes corrections to finalized grades before application. | Unassigned faculty, unpublished classes, invalid periods/scales, incomplete rows, concurrent finalization, or missing correction evidence are blocked. Finalized history is never silently overwritten; every correction preserves old/new values, reason, actors, and timestamps. | Phase 1 / Core; BM-01, BM-05, SIA-01; faculty, Academic Head, and Registrar grade cases. |
| Official generated documents and QR verification | COR is available only from canonical enrolled data. COE, COG, report cards, TOR, Form 137, diploma/certificates, SOA, and receipts derive from authoritative enrollment, grade, curriculum, ledger, and request records. Issuance records preserve type, subject, source snapshot, template version, issuer, issue time, reference/serial, checksum, state, and release evidence. QR verification uses an opaque token or signed route and reveals only minimal validity metadata. | Missing eligibility, holds, unfinalized grades, invalid enrollment, revoked/superseded issuance, or unauthorized request blocks generation/release. Corrections create superseding or revoking history; generated files never become the operational source of truth. | COR Phase 1; full catalog Phase 2/Deferred; BM-13, BM-14, BM-15, SIA-01; official-document and QR verification cases. |
| Student Hub and PWA visibility | An active student sees only their own profile, enrollment status, COR, published schedule, released grades, computed balance/transactions, document/request states, notices, and FAQ through service-backed read models. Loading, empty, offline, and error states remain stable and accessible. | Applicant, inactive, archived, dropped, unclaimed, or unauthorized identities cannot enter. Unpublished or unfinalized data stays hidden. Offline mode is read-only, labels data freshness, disables mutations, and does not cache unnecessarily sensitive content. | Phase 1 / Core Lite; advanced install/offline polish Deferred; BM-01, BM-02, BM-12; Student Hub access and visibility cases. |
| Student status, readmission, transfer, completion, and graduation | Registrar owns typed, reasoned, effective-dated transitions for leave, return/readmission, transfer-out, withdrawal, completion, graduation, archive, and reactivation. Graduation evaluation snapshots curriculum completion, finalized grades, deficiencies, and approved clearances before completion status and credential eligibility. | Status is not inferred from missing attendance or unpaid balance alone. Invalid transitions, unresolved academic deficiencies, active holds, or duplicate legacy identity block completion. Returning students use assisted lookup/readmission and controlled legacy provenance rather than public duplicate creation. Government submission and diploma external processing remain outside TALA. | Completion boundary Phase 1; full lifecycle Phase 2/Deferred; BM-01, BM-02, SIA-01; student-status and graduation cases. |
| Imports, exports, reports, and external systems | Authorized staff use versioned templates, private uploads, validation preview, explicit commit, row-level results, and import-batch audit for controlled data ingestion. Exports query authoritative records and expose only role-authorized fields. | Wrong template/version, unknown references, duplicate keys, partial invalid batches where zero-error commit is required, unauthorized fields, or stale scopes block commit/export. Generated CSV/XLSX/PDF files are evidence artifacts. DepEd/CHED/LIS, courier, bank, and other portals remain external unless a separately approved integration exists. | Supporting, promoted to Phase 1 only for demo-required foundation data; BM-01, BM-10, SIA-01; import/export/reporting cases. |
| Attendance, behavior, discipline, guidance, and interventions | These domains require typed evidence sources, authorized case ownership, privacy classification, notices, response/appeal opportunity, resolution, effective dates, and explicit policy before they can affect enrollment, clearance, progression, or graduation. | TALA must not infer misconduct, absence, guidance clearance, or enrollment blocks from missing data, free-text notes, or unsupported thresholds. Sensitive case details are restricted to authorized roles and are not exposed in general student/finance views. | Deferred unless promoted by approved policy and complete data ownership; BM-01, BM-05, SIA-01; future student-support cases. |

For every row, the master test-case matrix may describe the goal-state happy path, negative path, and edge case. Pass/Fail remains blank until the corresponding UI and executable evidence are tested.

**Feature Boundary**: Student status and graduation are approved as Registrar-owned lifecycle workflows, not direct edits to `student_profiles.operational_status` or reuse of staff account archive/restore. Each status change must have an allowed source/target state, effective date, reason, evidence, actor, notice effect, access effect, and immutable history. Readmission must search existing/legacy identity evidence and create a controlled reactivation/provenance decision instead of allowing duplicate public registration. Graduation/completion must be based on a reproducible evaluation snapshot of curriculum requirements, finalized grades, deficiencies, finance/document clearances, and approval result. Completion status, diploma/certificate preparation, document release, and external CHED/SO/government processing are separate outcomes.

**Feature Boundary**: Controlled data exchange is approved as a governed import/export/report layer, not a general spreadsheet editor or external-agency automation module. Imports must use named templates, private source files, uploader/scope evidence, parser or schema version, validation preview, explicit authorized commit, row results, and audit. Where zero-error commit is required, any invalid row blocks the batch; any allowed partial policy must be named before implementation. Exports and reports must query authoritative records, apply role authorization and field allowlists, preserve filters/row counts, and never expose passwords, tokens, raw private paths, unsupported sensitive evidence, or hidden regulator-status fields.

**Feature Boundary**: Attendance, behavior, discipline, guidance, and intervention effects are approved only as typed, policy-backed future workflows. TALA must not infer misconduct, absenteeism, guidance clearance, FA/DRP, probation, re-enrollment denial, graduation hold, or discipline outcome from missing data, free-text notes, unpaid balance, sample UI, or unsupported thresholds. Before any effect is enforceable, the school must approve the evidence source, responsible office, notice/response process, appeal or review path, privacy classification, resolution states, effective dates, and exact clearance/progression effect. Guidance and counseling details are restricted support information handled only by licensed/authorized roles and must not be exposed to ordinary Faculty, Accounting, or general Registrar views.

---

## 3. User Roles & Access Matrix

### 3.1 Role Definitions

| Role                          | Description                      | Access Level                                                                                 |
| ----------------------------- | -------------------------------- | -------------------------------------------------------------------------------------------- |
| **Applicant**                 | Temporary account for enrollment | Limited to enrollment workflow                                                               |
| **Student**                   | Official enrolled student        | View grades, schedule, COR, financials, document requests                                    |
| **Registrar**                 | Academic records management      | Full student records, scheduling, approvals, document SLAs, drop form consultations          |
| **Accounting/Cashier**        | Financial transactions           | OTC Payment processing, assessments. (Online payments are confirmed automatically).          |
| **Faculty**                   | Teaching and grading             | Class lists, grade encoding                                                                  |
| **System Super Admin (IT)**   | System administration            | Full IT access, User Mgmt. **Read-Only** for academics/financials.                           |
| **Academic Head / Principal** | School oversight                 | **Read-Only** oversight across all domains. Can "Authorize Overrides" for operational staff. |

### 3.2 Access Control Matrix

| Feature                     | Applicant | Student | Registrar          | Accounting | Faculty            | Academic Head  | System Super Admin |
| --------------------------- | --------- | ------- | ------------------ | ---------- | ------------------ | -------------- | ------------------ |
| Upload Documents            | ✅        | ❌      | ✅ (Walk-in)       | ❌         | ❌                 | ❌             | ✅                 |
| View COR                    | ❌        | ✅      | ✅                 | ✅         | ❌                 | ✅             | ✅                 |
| View Grades                 | ❌        | ✅      | ✅                 | ❌         | ✅                 | ✅             | ✅                 |
| Encode Grades               | ❌        | ❌      | ❌                 | ❌         | ✅                 | ❌             | ❌                 |
| Process Payments            | ❌        | ❌      | ❌                 | ✅         | ❌                 | ❌             | ❌                 |
| Approve Documents           | ❌        | ❌      | ✅                 | ❌         | ❌                 | ❌             | ❌                 |
| Manage Schedules            | ❌        | ❌      | ✅                 | ❌         | ❌                 | ❌             | ❌                 |
| Submit Faculty Availability | ❌        | ❌      | ✅ (Review/Lock)   | ❌         | ✅                 | ✅ (Read-Only) | ❌                 |
| **View Advising Status**    | ❌        | ✅      | ✅                 | ❌         | ✅ **(Read-Only)** | ✅             | ✅                 |
| Request Documents           | ❌        | ✅      | ✅                 | ❌         | ❌                 | ❌             | ❌                 |
| **Approve Promissory Note** | ❌        | ❌      | ❌                 | ✅         | ❌                 | ❌             | ❌                 |
| **View Promissory Tag**     | ❌        | ✅      | ✅ **(Read-Only)** | ✅         | ❌                 | ✅             | ✅                 |
| **Configure Fee Template Downpayments** | ❌     | ❌      | ❌                 | ✅         | ❌                 | ✅ **(Read-Only)** | ❌              |
| Drop/Transfer Consult       | ❌        | ❌      | ✅                 | ❌         | ❌                 | ❌             | ❌                 |
| **Review Shifting Requests** | ❌       | ✅ (Request) | ✅              | ❌         | ❌                 | ✅ (Override)  | ❌                 |
| **Manage Summer Schedules** | ❌        | ✅ (View) | ✅                | ❌         | ✅ (Assigned)      | ✅ **(Read-Only)** | ❌              |
| Manage Admission Requirements | ❌      | ❌      | ✅ (Versioned setup) | ❌      | ❌                 | ✅ (Read-Only/Oversight) | ❌ (System maintenance only) |
| User Management             | ❌        | ❌      | ❌                 | ❌         | ❌                 | ❌             | ✅                 |
| System Settings             | ❌        | ❌      | ❌                 | ❌         | ❌                 | ❌             | ❌ (Internal Registry) |
| **Authorize Override**      | ❌        | ❌      | ❌                 | ❌         | ❌                 | ✅             | ❌                 |

**Academic Head Finance Visibility Clarification (Current Approved Admin Scope)**:

- Academic Head may view only read-only finance status, fee template/downpayment rules, installment policy summaries, and promissory note status/tag.
- Academic Head must not see or operate Accounting work queues for payment processing, confirmed payments, or full ledger-entry review.
- Academic Head cannot approve promissory notes, process payments, create assessments, apply discounts, mutate installment policies, or edit finance records.

**Constraint**: One Role Per User. A user CANNOT be both Faculty and Registrar.

### 3.3 Identity and Access Baseline

Identity and access are a core and final-form baseline capability. The system must provide a clear, testable path for public users, applicants, students, and staff without relying on a generic `admin` role or ad hoc route hiding.

| Access capability | Final-form functional rule | Testable user outcome |
| --- | --- | --- |
| Public entry | Guests may open the landing page, FAQ/help, admission requirements, application start, application-status login, password reset, and public verification pages only. | A guest can start or resume the correct public flow but cannot see protected records, staff menus, Student Hub pages, or private files. |
| Applicant authentication | Applicant accounts authenticate only for applicant intake/progress until official handover. Approved applicants remain blocked from Student Hub until the handover transaction activates the student account. | An applicant can view status and upload/respond to requirements but receives a safe denial when trying to access Student Hub or staff routes. |
| Student authentication | Student Hub access requires an active user with the `student` role and an official student profile/enrollment context. | An active student can see only their own profile, enrollment status, COR, schedule, grades, balance summary, documents, notifications, and help. |
| Staff authentication | Staff use the Admin Nexus through the approved operational roles: Registrar, Accounting/Cashier, Faculty, Academic Head, and System Super Admin. | Staff navigation shows only policy-authorized resources, tables, widgets, actions, and lifecycle buttons. |
| Logout/session expiry | Every authenticated portal provides logout. Expired sessions return a safe session-expired message and require re-authentication before protected actions continue. | Users cannot continue protected work after logout, archive, inactive status, or session expiration. |
| Login throttling and password recovery | Login and recovery flows use the configured Laravel/Fortify rate limiting and password-reset behavior. The FS does not require a custom three-attempt/five-minute lockout unless later approved and implemented in TS. | Repeated invalid login attempts are throttled by the configured auth backend, and password recovery does not reveal whether an email belongs to a protected account. |
| Role boundary | One operational role is assigned per staff user unless a later approved permission model explicitly allows a composite role. | A Faculty account cannot open Registrar setup/payment queues; an Accounting account cannot encode grades; System Super Admin cannot mutate academic/finance records through generic admin forms. |
| Account archive/inactive state | Archived, inactive, dropped, unclaimed, or non-active accounts lose protected access while historical records remain intact. | Previously-created payments, grades, documents, and audit entries keep the person's historical label without allowing that account to log in. |
| Audit and safe errors | Critical lifecycle actions create audit evidence and authorization failures return safe user-facing messages. | Unauthorized users see safe denial or redirect behavior; no stack trace, SQL error, private path, or raw internal ID is exposed as primary UI feedback. |

---

## 4. Module 1: Student Module

### 4.1 Pipeline A: New Student Intake (Applicant Portal)

**Concept**: A separate, public-facing "Admissions Portal" for new applicants.

**Account Lifespan**: Temporary access mode. The same user row is upgraded during Official Handover; it is not archived when the applicant becomes an active student.

**Target Audience**: Freshmen (Grade 11 / 1st Year) and Transferees.

#### 4.1.1 Applicant Login & Progress Tracking

After initial registration, applicants can return to check their enrollment progress.

**Login Credentials**: Personal Email + Password (set during registration)

**Access Point**: "Check Application Status" button on the Public Landing Page

**Available Features (Status Board)**:

- View current application status (Pending, Action_Required, For_Evaluation, Approved)
- Re-upload rejected documents (if status is Action_Required)
- View payment instructions and upload proof of payment
- See timeline/progress tracker of enrollment journey

**Session Termination**: Upon Official Handover (Account Migration), the applicant's session is invalidated. They must use their new Official Student Credentials (Student ID + Password) to access the Student Hub.

**Protected Access Rule**: Only accounts with `users.status = active` may enter staff dashboards, staff panels, Student Hub pages, or protected API actions. Applicants in `pending`, `action_required`, `for_evaluation`, or `approved`, continuing students in `unclaimed`, and users marked `inactive`, `dropped`, or `archived` are blocked from protected areas. Public application, applicant progress/status, claim-account, password reset, email verification, FAQ, admission requirements, and payment webhook flows remain accessible because they are applicant/public/recovery or integration routes with scoped access.

#### 4.1.2 Enrollment Steps

**Step 0: Temporary Account Creation**

- **Action**: User signs up with Personal Email & Password
- **System Logic**: Creates a temporary applicant user row plus an applicant intake staging record with applicant-facing status; Student Hub access remains blocked until Official Handover
- **Purpose**: Allows them to save progress, pause, and return later

**Step 1: Digital Orientation**

- **Action**: Student views a "Rules & Policies" Modal
- **Requirement**: User must explicitly check "I understand the Learning Modality" and "I accept the School Policy" before the "Create Account" button becomes active

**Step 2: Applicant Account Creation & Profiling**

- **Logic**: User creates a Temporary "Applicant" Account (Standard signup: Email/Password)

**Data Acquisition (Student Record and External Reporting Readiness)**:

| Category                 | Fields                                                                                                                                                                                                                                                            |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Student Identity**     | LRN (12-digit), Last Name, First Name, Middle Name, Extended Name (Jr., Sr., II, III), Birthdate (YYYY-MM-DD), Place of Birth (City/Municipality, Province), Gender (Male/Female), Civil Status (Single/Married/Widowed/Separated/Annulled), Mother's Maiden Name |
| **Personal Contact**     | Home Address (Street, Barangay, City/Municipality, Province, Region, Zip Code), Contact Number (09XXXXXXXXX format), Father's Name, Father's Occupation, Mother's Occupation, Guardian's Name (required if minor), Guardian's Contact Number, Guardian's Address  |
| **Academic & Status**    | Educational Level (SHS/College), Program/Strand (SHS: STEM/GAS/ICT/HE/ABM/HUMSS; College: IT/BM/THM/etc.), Year/Grade Level (SHS: Grade 11/12; College: 1st–4th Year), Status (New/Transferee), Assigned Class (System Generated/Pending)                         |
| **Last School Attended** | School Name (required for Transferees), School Address, Year Graduated / Last Year Attended                                                                                                                                                                       |

**Name Storage Rule**: Student/applicant/staff person names are captured as separate `first_name`, `middle_name`, `last_name`, and `suffix` fields. The system composes `users.name` for display, search, exports, audit labels, and legacy compatibility. Registrar walk-in intake and System Super Admin staff-account creation must use the same split-name contract; student academic identifiers remain in `student_profiles`.

**Applicant Staging Rule**: Public intake stores pre-handover student-profile and external-reporting context, duplicate-check evidence, required-document lists, and Registrar review status in the applicant intake staging record. `student_profiles`, enrollments, ledger entries, and official student credentials are created only during the later Official Handover / enrollment backend flow.

**Educational Level Check**:

- The system explicitly asks: "Are you enrolling for Senior High School (SHS) or College?"
- "Student Type:" (New Student / Transferee)
- If SHS: Select Grade Level & Strand
- If College: Select Program/Course & Year Level

**Duplicate Check**: The system performs a fuzzy match against existing student records (name and birthdate). If a potential match is found, the applicant is redirected to the Student Login page to prevent duplicate accounts.

**Modality Selection**: Applicants must choose a preferred learning mode based on their department:

- **SHS Options**:
    - **Modular**: Self-paced printed-module setup. No recurring class meeting or room assignment is required in TALA MVP, but a faculty teacher/adviser assignment is still required for class ownership, module accountability, grade encoding, and faculty class-list visibility. Physical pickup/submission logistics remain outside the system.
    - **Online**: Virtual classes. No room assignment needed.
- **College Options**:
    - **On-Site**: Face-to-face for all subjects. Fixed classroom schedules with room/teacher assignment.
    - **Blended**: Room-required hybrid delivery for scheduling purposes. It uses on-site-style room/faculty conflict checks while detailed online meeting link tracking remains outside MVP.
    - **Online**: Virtual classes. No room assignment needed.

**Conditional Fields**:

- If applicant is minor: Guardian's Name becomes required
- If status = Transferee: Last School fields become required

**Document Requirement Set Rule**:
Before applicant intake opens for a term, the Registrar must publish an admission offering and activate a versioned requirement policy for each supported scope. A scope composes the term, education level (SHS/College), entry route, prior-credential pathway, citizenship/compliance profile, applicable grade/year or program, and only those learner-support attributes that lawfully change an admission requirement or accommodation. `Regular`, `transfer`, `returning`, and `cross-enrollee` are entry routes; `old curriculum` and `ALS` are prior-credential pathways; `foreign` is a citizenship/compliance profile; and IP or disability/SEN indicators are purpose-limited support attributes, not mutually exclusive applicant types. One applicant may therefore match more than one dimension.

All supported scopes use the same lifecycle: published offering -> applicant record -> materialized checklist -> submission and verification -> eligibility decision -> student-record creation -> program enrollment. The system composes base and conditional requirement rules, resolves one deterministic effective policy, and snapshots the resulting document items and rule versions onto the applicant intake. Later revisions apply only to new intakes unless the Registrar performs an explicit, audited reassignment; they must not silently change an existing checklist. An unpublished offering is unavailable to applicants. Missing, ambiguous, or conflicting active policies block new intake for the affected scope and produce a staff-facing setup error.

**Admissions Baseline Acceptance Contract**:
This slice adopts the benchmark pattern used by mature SIS platforms: admissions begins from a published admission process/offering, creates a candidate/applicant record, tracks checklist requirements, supports staff communications/review, and converts an accepted applicant into an enrolled student only after the configured academic, document, finance, capacity, and placement gates are satisfied. TALA adapts that pattern to the SIA workflow by keeping school-to-school credential transmission external, separating admission gates from retention undertakings, and treating OCR as staff-assistive evidence only.

| Capability | Final-form user-visible rule | Negative or edge-case behavior |
| --- | --- | --- |
| Published offering selection | Public applicants see only active offerings whose term, education level, entry route, prior-credential pathway, program/strand, grade/year, and compliance/support dimensions have a valid published policy. | Unpublished, expired, missing, or ambiguous offerings are hidden from public intake and produce a safe staff-facing setup error, not a hardcoded fallback list. |
| Applicant profile creation | Self-service intake creates an applicant account and staging record only; Registrar-assisted intake uses the same lifecycle for walk-in applicants. | Duplicate identity signals, weak name-only matches, or returning-student matches route to Registrar review instead of creating a second official student record. |
| Requirement checklist | Intake materializes the exact requirement-policy version into applicant-owned checklist rows with gate/retention classification, accepted evidence methods, deadlines, and review states. | Later policy edits do not silently rewrite existing applicants. Required checklist items are not waived by free-text notes or generic admin edits. |
| Document/evidence submission | Applicants upload private files; Registrar may record physical inspection or attach a scan; official school-to-school transmission is recorded as a receipt/provenance event. | A physical-only inspection does not fabricate a file; an applicant upload does not replace an official transmission when the active policy requires one. |
| OCR-assisted review | Google Vision OCR may extract text, confidence, and candidate fields for staff review when the document type enables OCR. | Low-confidence OCR, missing text, mismatched identity, or external-service failure routes to manual review; OCR never approves authenticity or final values by itself. |
| Admission-gate decision | Only accepted admission-gate requirements unlock the next payment/enrollment path. | Payment attempts, tentative section previews, or uploaded-but-unreviewed documents do not activate Student Hub, COR, class-list access, or official enrollment. |
| Retention undertaking | Non-critical retention items may remain pending after enrollment only through itemized undertakings with due dates, reminders, extensions, receipt history, and hold effects. | Overdue retention items do not silently cancel current enrollment; they apply only configured documentary/next-cycle holds through audited actions. |
| Safe feedback | Applicants and staff receive clear status messages: pending, needs correction, rejected, approved, blocked by setup, or manual review required. | UI messages must not expose private storage paths, stack traces, raw provider errors, or unsupported internal IDs. |

**Initial Offering Profile**: The initial public/system acceptance offering set is `Regular SHS`, `SHS Transfer`, `Regular College Freshman`, and `College Transfer`. Old-curriculum and ALS status are prior-credential pathways resolved inside a compatible published offering, not separate application pipelines. For the active institutional deployment, ALS is accepted only as an ALS Junior High School-to-Grade 11 pathway under Regular SHS; it is not a generic College or transfer shortcut. Foreign-student compliance is an independently composable profile, but it remains unpublished unless the institution confirms that it accepts foreign applicants for the scoped term/offering and the Registrar activates a compliant evidence policy. IP and disability/SEN are optional, purpose-limited support attributes and must not be represented as denial-producing applicant routes. `Cross-Enrollee` remains an inactive template until the institution confirms that it accepts students who remain primarily enrolled at another home institution.

**Returning and Legacy Student Boundary**: A returning student does not create a second public applicant identity. Registrar searches existing and imported records first. When the student's attendance predates TALA and no reliable profile exists, Registrar performs controlled legacy onboarding from verified institutional evidence, records provenance and known historical gaps, runs duplicate checks, and then starts readmission/reactivation. Missing historical evidence may become itemized readmission requirements; it must not be invented or silently marked complete. Backend requirement materialization supports this assisted path, while the readmission workflow owns the readmission decision, status transition, curriculum alignment, hold checks, and account reactivation.

Requirement sets are maintained through a typed Registrar workflow with activation and version history. They are not arbitrary per-applicant free text and are not permanent code constants. Each item uses a normalized document-type catalog key and a staff-facing label so requirements can change without losing historical meaning.

**Per-Requirement Compliance Rule**:
Each configured requirement declares whether a verified digital copy, a Registrar-confirmed physical copy, or both are required. The applicant checklist tracks digital review and physical receipt separately for every item. Overall digital completeness and physical completeness are derived from those item states; a single profile-wide checkbox is not the source of truth. Staff cannot waive an item materialized as required for that applicant; requirement changes occur only through a new versioned set for future intakes.

**Submission Channel Rule**:
TALA supports two channels into the same requirement checklist and review lifecycle: `self_service`, where an applicant uploads a file online, and `registrar_assisted`, where Registrar staff record a walk-in submission or physical inspection and optionally attach a scan. The channel changes who performs intake, not the meaning of completeness, validity, review status, or historical evidence. Both channels must resolve the same active requirement set and preserve the submitting/inspecting actor, timestamp, and later replacement history. A scanned walk-in document follows the same private-storage and authorization rules as a self-service upload.

**Step 3: Document Submission & Pre-Check**

- **Self-Service Action**: Student clicks "Upload Document" (selects file from device). When OCR is enabled for that requirement type, Google Cloud Vision extracts candidate text for provisional student confirmation.
- **Registrar-Assisted Action**: Registrar selects the applicant and outstanding requirement, records the walk-in inspection, and may attach a scan. Staff entry uses the same checklist and review history rather than a separate walk-in document store.
- **Constraint**: No Direct Camera Capture (file must be pre-captured/scanned)
- **Storage Rule**: The uploaded file is retained as the canonical evidence in private storage. Extracted text, confidence, and candidate fields are stored separately for review.
- **Verification Rule**: OCR output and student-confirmed fields are provisional. Identity, academic, and financial fields become official only after staff side-by-side review and verification.

**Document Storage Classification**:

The requirement catalog must assign every document type a storage class, sensitivity level, allowed evidence methods, OCR policy, verified-field mapping, and retention/disposal policy. Storage class does not determine whether an item is an admission gate or retention requirement; that classification remains scope- and policy-version-specific.

| Document/evidence family | Examples aligned to the institutional workflow | Required system treatment |
| --- | --- | --- |
| **Identity and academic credentials** | PSA/birth certificate, Form 138/report card, Form 137, TOR/copy of grades, Good Moral, diploma/completion certificate, Honorable Dismissal, ALS rating/completion records, entrance-exam results, passport/visa | Preserve each submitted image/PDF privately as canonical submission evidence with owner, source, checksum, MIME type, size, version, and review history. OCR may create provisional search/prefill data. Verified operational values are stored separately; replacements create new versions and never overwrite prior evidence. |
| **Official institution-to-institution records** | Form 137, final TOR, transfer credentials, official electronic/scanned school records | Preserve the received file or transmission artifact plus sender, receiving channel, received time, provenance, and verification result. Link it to the same requirement item; do not require an applicant upload when an approved official transmission satisfies the active policy. |
| **ID photographs** | 2x2 or other recent ID photos | Store the private source image and approved derivative, with checksum and review status. OCR is disabled. The photo must not become public merely because it is later used on a school ID or document. |
| **Restricted support/compliance evidence** | Medical certificate, medical/psychological assessment, disability/SEN evidence, IP/community certification, scholarship/support-program evidence, immigration files, medical clearance | Store privately under a restricted sensitivity class with purpose-limited access and audit. OCR is off by default and may be enabled only for an approved field-extraction purpose. These records must not be exposed in ordinary applicant/student views or used as automatic denial criteria. |
| **Financial and transaction evidence** | Payment proof, promissory-note attachment, official receipt image, courier receipt | Preserve privately and link immutably to the payment, note, request, or shipment. OCR may suggest references/amounts but never confirms payment, approval, or settlement. |
| **Generated official artifacts** | COR, report card, assessment, requested COE/COG/Good Moral/Form 137 copy, system-issued receipt | Store an immutable issuance snapshot or reproducible PDF artifact with document type, subject, term/request, template version, issuer, issue time, checksum, verification token where applicable, and lifecycle state. Corrections supersede or revoke prior artifacts; they do not overwrite issued history. |
| **Structured operational records** | Applicant profile, checklist status, enrollment, section/schedule, grades, ledger, holds, requirement satisfaction | Store as authorized database records and audit events, not as screenshots or PDFs as the primary source of truth. PDF/CSV/XLSX outputs are derived artifacts. |
| **Controlled import sources** | Curriculum, legacy roster, grade, enrollment, or fee CSV/XLSX files | Preserve the private source file, checksum, uploader, parser/template version, validation report, and batch status. Accepted rows become normalized database records; the spreadsheet is not the live operational source. |
| **OCR/parser derivatives and previews** | Extracted text, confidence, candidate fields, thumbnails | Store separately from the source file and link to its exact version, engine/parser version, and processing attempt. Derivatives have no independent authority and may be regenerated or disposed according to policy. |
| **Physical-only custody evidence** | Original/certified credential inspected without a scan | Record requirement item, evidence method, inspecting/receiving staff, receipt time, custody status/location, and return or transfer event when applicable. A scan is optional unless the active policy requires one; a scan never proves that TALA still holds the physical original. |

For all file-bearing classes, uploads must use allowlisted extensions and verified MIME/signature checks, generated storage names, size limits, malware scanning where available, authorization, private storage outside the public web root, and controlled temporary preview/download access. Retention must be purpose- and policy-based; legal holds or active disputes suspend disposal without converting every file into permanent storage.

Institutional forms and clearances are classified by origin. An externally issued readmission form, home-school permit, prior-school clearance, or approval letter is a `credential_file`; a decision completed inside TALA is a `structured_record`; and a signed/PDF copy issued by TALA is additionally a `generated_official_artifact`. The system must not force one document to be stored as an image merely because a paper equivalent exists.

**Institution Requirement Baseline (Configuration Source, Not Platform Hardcode)**:

The consolidated client workflow supplies the following operational baseline. The system seeds these rows as draft, versioned requirement policies; it does not implement them as conditionals in application code. Before publication, Registrar must confirm the offering is accepted for the term and the policy must pass regulator-aware evidence-method and deadline validation. In particular, the SHS admission/retention split remains open where DepEd Order No. 017, s. 2025 permits temporary documentary deficiency, alternative identity evidence, or official school-to-school credential transmission.

| Applicant scope/profile | Client-declared admission documents | Client-declared retention/follow-up documents | Initial exposure |
| --- | --- | --- | --- |
| **Old Curriculum SHS** | Grade 10 Form 138, PSA Birth Certificate, Good Moral, 2x2 photo | Certificate of Completion, Form 137 | Inactive trace row only; not published or selectable because the clarified scenario routes to Old Curriculum College |
| **Old Curriculum College** | Grade 12 Form 138, PSA Birth Certificate, Good Moral, 2x2 photo | Certificate of Completion, Form 137 | Approved conditional pathway under Regular College Freshman; benchmarked rule below supersedes the literal row where needed |
| **Regular SHS** | Grade 10 Form 138, PSA Birth Certificate, Good Moral, 2x2 photo | Certificate of Completion, Form 137 | Initial public offering; client row retained for provenance, with the approved regulator-aware policy below governing activation |
| **Regular College Freshman** | SHS Form 138, PSA Birth Certificate, Good Moral, 2x2 photo | Diploma/Certificate of Graduation, entrance-exam result, ID photos | Initial public offering |
| **SHS Transfer** | Copy of Grade 12 grades, Good Moral, PSA Birth Certificate, 2x2 photo | Official transfer credentials, Form 137 | Initial public offering; official transmission may satisfy configured records |
| **College Transfer** | TOR/copy of grades, Honorable Dismissal, Good Moral, PSA Birth Certificate, 2x2 photo | Official transfer credentials, final TOR | Initial public offering |
| **Returning College** | Previous school records, PSA Birth Certificate, 2x2 photo | Readmission form, Registrar clearance, previous-enrollment clearance | Registrar-assisted readmission/legacy onboarding; not public new intake |
| **Returning SHS** | SHS Form 138, PSA Birth Certificate, Good Moral, 2x2 photo | Diploma/Certificate of Graduation, entrance-exam result, ID photos | Registrar-assisted readmission/legacy onboarding; row requires policy review before activation |
| **ALS Completer** | ALS Junior High School-level completion/pass/equivalency evidence for incoming Grade 11, PSA Birth Certificate, Good Moral, 2x2 photo | Any remaining official ALS rating/completion record required by the active policy | Conditional prior-credential pathway under Regular SHS only for Grade 11 intake in the active deployment |
| **IP support profile** | No additional admission gate by default; collect IP/community, scholarship, or culture-responsive support evidence only when the applicant requests or is eligible for a specific support program/accommodation | Support-program evidence, community coordination record, or scholarship/accommodation evidence required by the approved support rule | Purpose-limited optional support attribute; never a denial-producing applicant route |
| **Disability/SEN support profile** | No additional admission gate by default; collect medical, psychological, functional, accessibility, or accommodation evidence only when needed for a requested accommodation, safety plan, or approved support program | Assessment, accommodation-plan evidence, assistive-support record, or follow-up documentation required by the approved support rule | Restricted optional support attribute; never a denial-producing applicant route |
| **Foreign compliance profile** | Verified identity and prior academic eligibility for the selected base offering; passport or equivalent travel/identity evidence when activated | Student visa, Special Study Permit, immigration files, English-proficiency evidence, medical clearance, and related compliance evidence required by the active policy | Inactive compliance profile until institution acceptance and a compliant evidence policy are approved |
| **Cross-enrollee** | PSA Birth Certificate, copy of grades/TOR, home-institution cross-enrollment permit, both-school approvals | Registered-course clearances | Inactive template until institution acceptance and lifecycle are approved |

If the client baseline and a binding regulator rule differ, the regulator-compatible evidence method/deadline governs the active policy while the original client row remains traceable as business evidence. Missing or empty draft rows cannot be published and never mean that no documents are required.

**Approved Regular SHS Requirement Policy**: The admission gate is outcome-based: (1) identity is sufficiently established and (2) Grade 10 completion/eligibility is sufficiently established. PSA Birth Certificate and Grade 10 Form 138 are the preferred credentials, but the system must also support regulator-permitted alternative identity evidence, temporary documentary deficiency with an itemized undertaking, and verified official school-to-school transmission where applicable. Good Moral, 2x2 photo, Certificate of Completion, and Form 137 are configurable retention/follow-up items and are not universal absolute blockers to initial official enrollment. Registrar records the accepted evidence method and authority for each satisfied gate.

**External-Channel Boundary**: Previous-school delivery of Form 137 or another official credential, including an authorized electronic/scanned transmission, occurs outside TALA in MVP. TALA does not require a DepEd, LIS, email, or previous-school API integration. Registrar records the sender/school, channel, receipt time, provenance, attached received artifact when available, verification result, and requirement satisfaction. The temporary-enrollment undertaking is an internal TALA-generated and monitored record. Alternative identity evidence is issued outside TALA but is uploaded or recorded, reviewed, and tracked through TALA's normal private-document workflow.

**Approved Regular College Freshman Requirement Policy**: Admission requires verified identity, verified SHS completion/eligibility, and an accepted Good Moral credential for the active institutional deployment. PSA/birth evidence and SHS Form 138 are preferred credentials, while the active policy may permit equivalent official evidence supported by provenance and Registrar verification. Diploma/Certificate of Graduation and one consolidated ID-photo requirement are configurable retention/follow-up items; the duplicated `2x2 photo` and `ID photos` labels in the client baseline must materialize as one normalized photo obligation unless a future policy identifies distinct purposes. An institution-administered entrance-exam result is a structured TALA admission record, not an applicant image requirement. It does not block admission by score unless an effective-dated, institution-approved passing or placement policy is configured; otherwise, only completion/status may be recorded for operational follow-up.

**Approved SHS Transfer Requirement Policy**: Admission requires verified identity and verified eligibility from the learner's latest completed grade. The workflow phrase `Copy of Grade 12 grades` must not be implemented literally for every transferee: the active requirement resolves the applicable prior-grade record from the target grade (for example, Grade 11 evidence for an incoming Grade 12 learner). Good Moral, one ID-photo obligation, official transfer credentials, and Form 137 are configurable retention/follow-up items. An approved official school-to-school transmission may satisfy the corresponding record requirement.

For school-to-school transmission, the sending school and receiving Registrar perform the communication outside TALA by the authorized institutional channel. TALA records the sending school, channel, date received, receiving staff, provenance/authenticity review, and linked requirement satisfaction. A received electronic file or an authorized scan is retained in private storage as the canonical received artifact. If only a physical original/certified copy is received, TALA records physical custody/inspection and does not require a scan unless the active policy requires one.

**Approved College Transfer Requirement Policy**: Admission requires verified identity, an academic record sufficient for initial credit/eligibility evaluation, verified transfer-release eligibility through Honorable Dismissal or the active policy's approved equivalent, and accepted Good Moral evidence for the active deployment. A preliminary TOR/copy of grades may support initial evaluation when provenance is recorded. Final TOR, any remaining distinct official transfer credential, and one normalized ID-photo obligation are retention/follow-up items. The requirement catalog must not create duplicate obligations under generic `official transfer credentials`: Honorable Dismissal represents release/transfer eligibility, while final TOR represents the authoritative academic record.

**Approved Old-Curriculum College Pathway**: An old-curriculum graduate who has not taken College units applies through the Regular College Freshman offering with `prior_credential_pathway = old_curriculum`. Admission requires verified identity, an old high-school Form 138/Form 137 or equivalent official record establishing eligibility for first-year College, and accepted Good Moral evidence for the active deployment. One ID-photo obligation is retention/follow-up. Form 137 remains follow-up only when the accepted eligibility evidence did not already satisfy the same authoritative academic-record purpose. A generic Certificate of Completion is not universally required for this pathway; it may be configured only when the issuing credential and institution policy make it applicable.

**Old-Curriculum SHS Boundary**: Client clarification identifies the old-curriculum scenario as learners who completed the old Grade 10/high-school curriculum before SHS existed and now want to continue studying. Benchmark evidence supports this as a College-admission pathway, not a standard SHS intake route. The former `Old Curriculum SHS` row remains traceable business evidence only; it is inactive, not selectable, and not publishable in the active deployment. A pre-K-to-12 high-school graduate who simply wants to continue formal education is routed to the College old-curriculum pathway when otherwise eligible, not silently converted into a Grade 11 SHS applicant. A future adult/remedial SHS route would require a new client-approved scenario, target grade, eligibility authority, and requirement policy.

**Approved ALS-to-Grade-11 Pathway**: The institution accepts ALS applicants only when the target intake is Grade 11. The applicant composes `Regular SHS` with `prior_credential_pathway = als_jhs`. Admission requires verified identity plus authoritative ALS Junior High School-level evidence showing Grade 11 eligibility. Acceptable evidence methods include regulator-recognized ALS A&E/Certificate of Rating, ALS Junior High School completion evidence, or ALS portfolio-assessment passer evidence when permitted by the active policy and verified by the Registrar. The active requirement policy must identify which credential family is accepted and must not require duplicate ALS Certificate of Rating and Certificate of Completion documents when one authoritative credential already proves the same Grade 11 eligibility outcome. Good Moral, one ID-photo obligation, and any remaining official ALS record are retention/follow-up unless the active policy makes one an admission gate for a documented regulatory or institutional reason. ALS is not published as a College pathway in the active deployment.

**Approved Foreign Compliance Boundary**: Foreign-student handling is a citizenship/compliance profile that composes with a base offering only after the institution confirms that the term/offering accepts foreign applicants. It is not a separate applicant pipeline and is not published for MVP by default. When activated, admission requires verified identity, prior academic eligibility for the selected base offering, and legal stay/study authorization evidence appropriate to the offering. For College or higher-than-high-school study, the policy must account for Student Visa 9(f) applicability; for basic education, short-term, non-degree, exchange, or other cases where applicable, the policy must account for the correct permit or legal-stay basis, such as a Special Study Permit. Passport, visa, permit, immigration, medical, and English-proficiency evidence are restricted compliance evidence with purpose-limited access and audit. TALA records submitted evidence, verification state, deadlines, and holds only; it does not submit to, query, or update Bureau of Immigration, DFA, CHED, DepEd, or embassy systems.

**Approved IP/SEN Support Boundary**: IP and disability/SEN indicators are optional, purpose-limited support attributes, not applicant routes and not admission-denial criteria. The base offering's ordinary identity and academic eligibility requirements remain the enrollment gate. TALA may collect IP/community, scholarship, disability, medical, psychological, functional, accessibility, or accommodation evidence only for a configured support purpose such as a scholarship/support program, culture-responsive coordination, accessibility accommodation, safety planning, or legally required inclusive-education service. These records use restricted support evidence controls, are hidden from ordinary applicant/student views, and may be accessed only by authorized staff for the approved purpose. They must not be shown to faculty, Accounting, or unrelated Registrar workflows by default, and they must not be used for automated rejection, ranking, section assignment, billing, disciplinary action, or public reporting unless a later approved rule explicitly permits the specific use.

**Enrollment Readiness and Capacity Contract**: A term may collect inquiries or applicant submissions before every academic setup step is complete, but payment clearance, OR-backed capacity security, and official enrollment handover must remain blocked until the term is enrollment-ready or an authorized Registrar/Academic Head override records the unresolved readiness risk. Enrollment readiness requires an active calendar/enrollment window, published admission offering and requirement policy, approved admission-capacity plan, ready curriculum/subject-offering scope, planned sections and delivery groups, faculty-subject eligibility/availability or approved override, and a committed/published official schedule or documented institution-controlled scheduling exception. A pre-payment section/schedule preview is tentative planning only; it does not reserve a protected seat, does not activate access, and expires at the earlier of the configured payment deadline, admission-window close, or manual Registrar cancellation for stale/ineligible cases. Confirmed minimum/full payment with Official Receipt evidence atomically secures capacity against every applicable capacity plan. If no compatible final section/delivery placement remains after payment through institution-caused delay or planning failure, TALA routes the case to `PendingInstitutionalPlacement`/Registrar resolution instead of silently rejecting or blaming the applicant.

Capacity is handled through a stack of effective-dated admission-capacity plans rather than a single hardcoded campus value. The current institution may seed a top-level `100 active enrolled students` campus cap, but SHS and College may also have separate caps by term, education level, program/strand, year/grade, or delivery setup when the institution approves those values. If only the campus cap exists, SHS and College draw from the same 100-student pool on an OR-secured first-paid basis. If SHS/College sub-caps exist, the system enforces both the matching sub-cap and the parent campus cap. Section and delivery-group capacities remain separate operational capacities for exact placement; they cannot exceed or bypass the approved admission-capacity plan.

**UI Response**: Instant Preview (100px thumbnail) appears immediately

**Feedback**: If blurry, a Red "Retake" button allows immediate replacement

**System Logic (Quality Filter)**:

- The system sends the image to Google Vision `DOCUMENT_TEXT_DETECTION`.
- Extraction begins after upload; the student may see a `Processing` status until it finishes
- **Quality Check**: `average_confidence` is a normalized `0.00` to `100.00` value computed from returned word confidence values. If confidence is unavailable or `< 80.00`, the system flags the upload for Registrar manual review. If `average_confidence >= 80.00`, the upload passes the automated quality check.
- **User Feedback**: "Image looks blurry or low quality. You may proceed, but this might be rejected later."
- **Provisional Status**: Enrollment is Conditional until Registrar approves



**Scope**: The student manually selects the document type. Google Cloud Vision OCR assists with text extraction, name/LRN comparison, and quality signals; it does not independently approve document type accuracy.

**Step 4: Verification & The "Rejection Loop"**

- **Action**: Registrar reviews the original document, extracted text, and student-confirmed fields side by side.
- **SLA**: The Registrar must process pending documents within **48 business hours** to maintain enrollment velocity

**Rejection Loop Business Rule**:
If Registrar clicks "Reject" (e.g., blurry, wrong document):

1. Changes Status: Pending → Action_Required
2. Unlocks the Upload Form for the student
3. Sends an email notification requiring re-upload
4. **Billing Rule**: Rejected applicants never owe an intake payment. Payment assessment only occurs after full Registrar approval.

**Step 5: Admission-Gate and Retention-Document Lifecycle**

- **Approved Business Rule**: The consolidated institutional workflow distinguishes **Admission Gate Documents**, required upfront before the applicant may complete registration, from non-critical **Retention Documents**, which may remain pending after enrollment under a Registrar-issued undertaking.
- **Admission Gate**: The active versioned requirement set identifies the documents and evidence methods that must be accepted before registration/payment can produce official enrollment. Online uploads and OCR may support preliminary review, but they do not override the configured acceptance method or a binding regulator rule.
- **Retention Undertaking**: Missing non-critical retention documents use an applicant-specific due date within the current institution's approved 30-to-60-day window. The undertaking records each missing item, issue date, due date, responsible party, Registrar, reminders, submissions, and resolution.
- **Retention Effects**: An unresolved retention requirement creates a documentary hold and monitoring obligation. It does not automatically remove the current section, schedule, COR, or class access after the admission and payment gates have produced official enrollment. It may block controlled document release and the next enrollment/registration cycle according to the active policy.
- **Regulator-Aware Satisfaction**: Each requirement defines permitted evidence methods, including physical original, certified true copy, or official school-to-school electronic transmission where applicable. Applicant-submitted photos/scans remain preliminary unless the active requirement and regulator explicitly permit them as final evidence.
- **Deadline Source of Truth**: Deadlines and extensions operate per requirement/undertaking and are calendar-aware. TALA must not use the profile-wide `Hard_Copy_Received` compatibility flag or a universal seven-day deadline.
- **Overdue Handling**: Scheduled monitoring marks unresolved items overdue, sends configured reminders, and applies the approved hold. It must not silently cancel enrollment, delete records, or fabricate receipt. Escalation and any enrollment effect require a typed, authorized action with reason and audit evidence.
- **Financial Policy**: The latest approved workflow allows refund of the admission/enrollment fee within 15 days of the Official Receipt date and treats tuition as non-refundable after official enrollment. These are effective-dated deployment policies, not universal constants, and all payment/ledger evidence remains immutable.

**Online Intake Boundary**:
Online uploads provide intake and preliminary verification. An applicant missing an upfront admission-gate requirement remains an applicant and receives no official section/COR/class access merely because a payment attempt or upload exists. Once admission gates, payment, and the approved placement/registration sequence are complete, pending non-critical retention documents are tracked through the undertaking workflow rather than the stale blanket `pending_physical_documents` rule.

**Capacity and Placement Rebaseline**:
The latest approved workflow prepares a section and schedule before payment, while the Official Receipt secures the slot and completes official enrollment. TALA must distinguish tentative pre-payment placement from a capacity-consuming secured seat. The locking, expiry, reassignment, and access transitions must be defined through benchmarked technical contracts before implementation.

**Step 6: Academic Pathing (The "Fork")**

**Path A: Freshmen (Auto-Sectioning)**

- **Logic**: System auto-assigns Block Section (e.g., Grade 11-A)
- **Action**: Move to Payment Assessment (Payment instructions/checkout are enabled only after this Registrar approval)

**Path B: Transferees (Credit Evaluation & Selection)**

- **Status**: For_Evaluation
- **Automated Extraction (Google Cloud Vision OCR)**: The system uses Google Cloud Vision OCR to extract text from the uploaded Transcript of Records (TOR) or Report Card.
- **OCR-Assisted Credit Evaluation**: The system pre-populates proposed credited subjects by matching extracted text data against the school curriculum using regular expressions and fuzzy text-matching patterns.
- The Registrar reviews and adjusts this pre-filled list before unlocking prerequisites and subject selection.
- Approved credit decisions are stored as structured academic records; raw extracted text remains supporting evidence only.

**Subject Selection (College Only)**:

- **College Transferees**: Select remaining subjects via "Shopping Cart" interface
- **SHS Transferees (Grade 12)**: Automatically assigned to the appropriate Strand/Block Section (No manual subject selection)
- **Action**: Move to Payment Assessment (Payment instructions/checkout are enabled only after this Registrar approval)

**Step 7: The "Official Handover" (Account Migration)**

- **Admission Trigger**: Official handover requires accepted admission-gate evidence, confirmed minimum payment/full payment, and a secured placement/capacity reservation. A preliminary upload, OCR result, tentative section, or unconfirmed payment never activates the account.
- **Retention Documents**: Missing non-critical retention documents do not prevent handover when an approved undertaking exists. They create itemized monitoring and documentary-hold effects, not automatic loss of section, schedule, COR, or class access.
- **Placement Rule**: A pre-payment section/schedule is tentative only. Official Receipt confirmation secures capacity. The final transaction must atomically activate the account, finalize a compatible section/delivery group, write `enrolled_at`, and enable COR/class-list eligibility; institution-caused placement failure is not applicant noncompliance.

**System Action (Automated)**:

1. **Credential Rotation**: The email login is replaced by the generated Student_ID
2. **Password**: User must reset or receives a temporary password
3. **Migrates Data**: Updates `users.status` from applicant-facing state to `active` on the same row
4. **Result**: The old email login stops working
5. **Sends Welcome Email**: Contains the New Official Credentials and link to the Main Student Portal

**System Logic**:

- **If an enforceable continuing-enrollment gate fails**: Block next-term registration and show the specific finance, document, behavior, discipline, or academic reason. Outstanding balance does not block scheduled examinations.
- **If Failed Grades Detected**: Evaluate the active SHS/College progression policy and flag the appropriate remedial, retake, irregular, or probation outcome; do not infer a universal whole-grade/year repeat.

#### 4.2.2 Step 2: Enrollment Flow

**Regulars (Clean Record)**:

- **Action**: One-Click Enroll
- **System**: Auto-promoted to next Block Section

**Irregulars (Failures/Back subjects)**:

- **Automated Subject Suggestion**: The system cross-checks their academic record with the curriculum to suggest allowable subjects and back subjects.
- **Prerequisite Enforcement**: Prevents enrollment in courses whose prerequisites are not yet passed. A prerequisite is satisfied by a finalized passing grade for the same subject or an approved equivalent subject. If a student repeated a subject, the latest finalized attempt is used. _An "Incomplete" (INC) grade acts as a hard block. Students cannot enroll in advanced subjects if the prerequisite holds an active INC._ Expired INC grades are handled by the nightly auto-fail job and then treated as failed. Missing grade history blocks enrollment unless the Registrar applies an audited prerequisite override.
- **Grade-History Authority**: Subject suggestion consumes registrar-verified finalized grade history only. Faculty class records, component scores, and working grade sheets are evidence for grade computation and review, but they become enrollment eligibility data only after faculty submission, Registrar verification, finalization, and archival in the student's academic record.
- **Backend Boundary**: The subject-suggestion backend returns suggested current subjects, back subjects, blocked subjects, and already-passed current subjects. Active INC, failed prerequisites, and missing historical grades remain blocking. Approved-equivalent or credited-subject satisfaction remains a business rule, but it requires controlled equivalency/credit-evaluation records before the system may auto-treat an alternate subject as satisfying a prerequisite.
- **Modality Choice**: Must select a learning mode each term based on their Department constraints. SHS students may only select **Modular** or **Online**. College students may only select **On-Site** or **Online**.

**SHS Irregulars — Registrar-Assisted Subject Load Assignment**:

- SHS uses **block sections** where all students in a section take the same subjects. Irregulars cannot self-build a block schedule.
- **Student Request**: The student submits a list of proposed back subjects they need to retake or catch up on.
- **Registrar Assignment**: The Registrar reviews the request and manually assigns the student to the appropriate section(s) per subject. An SHS irregular may be placed into **multiple sections** in the same term (e.g., Section A for Math, Section B for English).
- **No Open Section**: If a needed subject has no available section with remaining capacity, the Registrar may: (a) queue the subject for a special class, (b) mark it as a pending assignment, or (c) defer it to the next term.
- **Constraints**: Cannot exceed the 30-unit maximum academic load. Schedule conflicts must be resolved by the Registrar before enrollment is confirmed.

**College Irregulars — System-Guided Subject Selection**:

- **Action**: System-guided subject selection with Registrar approval.
- **System**: Shows available subjects and sections based on prerequisites, capacity, and schedule.
- **Constraints**: Cannot pick conflicting schedules or exceed the 30-unit maximum academic load.
- **Registrar Override**: If manual adjustments are needed (e.g., overloading, special permission), the Registrar can intervene.

**Academic Load Cap**:

- **Default Maximum**: A student's enrolled load must not exceed **30 academic units** in a regular term.
- **Scope**: The cap applies to regular promotion, SHS irregular multi-section assignment, College irregular self-selection, transferee credited-subject adjustments, and Registrar manual overrides.
- **Blocking Rule**: If the proposed subject load exceeds 30 units, enrollment cannot be confirmed until subjects are removed or moved into a valid future/summer term plan.
- **Override Boundary**: Academic load overload for students remains blocked unless a separate approved policy exists. Faculty workload overload for scheduling is a distinct soft-constraint exception: it may be approved only by the Academic Head with a non-empty reason and audit evidence showing the exceeded limit. Hard scheduling conflicts remain non-overridable.

**Automatic Summer-Class Split**:

- **Trigger**: When a student's required back subjects, failed subjects, or excess allowable subjects cannot fit inside the 30-unit regular-term limit, the system separates the overflow into a proposed **Summer Load** bucket where the curriculum and calendar permit.
- **Registrar Confirmation**: The split is advisory until the Registrar confirms which subjects remain in the regular term and which subjects move to summer.
- **No Auto-Enrollment Guarantee**: A proposed summer subject does not create an official class by itself. It becomes schedulable only after the Registrar opens or assigns a valid summer class/section.
- **Student View**: The Student Hub must clearly distinguish regular-term subjects from proposed or confirmed summer subjects.

**Continuing-Enrollment Clearance and Progression Contract**:

- The system evaluates finance, admission/retention documents, behavior, discipline, and academic standing as separate typed gates with explicit reasons. A gate is enforceable only when its authorized evidence source and resolution workflow exist.
- SHS progression must follow the active DepEd-aware policy. DepEd Order No. 8, s. 2015 requires Grades 11-12 learners with failed competencies/subjects to complete remediation and, if remediation fails, retake the failed subject. TALA must not automatically repeat the whole grade based only on general average.
- College probation, repeat-year, GWA thresholds, and retake outcomes are institution-approved effective-dated policy. They are not universal platform constants and must not be automated until the current profile is approved.
- Summer/remedial load limits and pricing are separate term policies. The current 6-to-9-unit workflow value is deployment configuration, not the regular-term 30-unit cap.

#### 4.2.3 Step 3: Payment & Activation

Upload Proof → Accounting Confirms → Student becomes **Finance-Cleared**. For applicant-origin enrollments, finance clearance secures the approved capacity reservation but does not bypass admission-gate documents. When admission, payment, and placement gates are clear, the system completes **Enrolled** handover. Missing non-critical retention documents remain on an undertaking and do not undo active access.

**Note**: Finance clearance is reached when Accounting confirms that the student has paid at least the required minimum downpayment, or has fully paid the assessed balance. It does not bypass an admission-gate requirement, but an approved retention-document undertaking is not an admission blocker. Promissory notes do not clear finance. External DepEd LIS/CHED encoding does not change the TALA enrollment state (see §5.4.5).

**State Invariant**: A student cannot be `active` while payment, admission, or institutional placement remains unresolved, and an `Enrolled` student cannot remain inactive. For applicant-origin enrollment, secured placement, account activation, `enrolled_at`, COR access, and class-list visibility must commit together or roll back together. Retention-document holds remain separate from this activation transaction.

**Enrollment Admin Surface Boundary**: Enrollment records are lifecycle evidence and operational queues, not generic Registrar CRUD. Admin Nexus may show list/view details and expose approved typed actions such as per-requirement receipt, assessment posting, and Accounting payment confirmation. Receipt is a Registrar/Transferee-evaluator lifecycle action against the materialized applicant checklist, with permission, actor, timestamp, evidence method, and audit history; it is not a generic profile checkbox. Generic Create/Edit Enrollment routes and direct mutation of IDs, status, compliance fields, or lifecycle timestamps remain forbidden. Applicant-origin pending states, admission/retention resolution, placement, promotion to `Enrolled`, ineligibility handling, and term close require dedicated services/actions.

**Handover Requirement**: The system must preserve manual/PayMongo parity and atomic handover while separating admission-gate/retention-document requirements and distinguishing tentative versus secured placement. Payment attempts without an enrollment link remain ledger-only evidence until controlled reconciliation.

**Controlled Repair Boundary**: Manual repair of enrollment state, section assignment, or lifecycle timestamps is not a generic edit permission. If the institution activates this capability, it must be a separate controlled repair workflow with reason capture, role approval, rollback rules, and audit trail before it appears in Admin Nexus.

---

### 4.3 Offline Accessibility (PWA)

**Scope**: The Student Portal is a Progressive Web App (PWA)

**Offline Features**:

- Caches Read-Only Data (COR, Class Schedule, Latest Grades) on the phone
- **Modular Students**: Caches responsible faculty teacher/adviser ownership when available. Printed-module pickup/submission logistics are outside the TALA MVP.
- **Sync Logic**: When internet returns, the app Auto-Refreshes (Pull-to-Refresh) to fetch the latest status

**Constraint**: No Offline Form Submission. Students cannot enroll or upload docs without internet.

**Student Dashboard Backend Contract**: Before Student Hub UI buildout, the dashboard data source must be a read-only backend aggregate rather than hardcoded page content. The implemented contract provides student-owned profile/enrollment context, current schedule, financial balance and term summaries, finalized grades, recent document/service/grade-correction requests, dashboard holds, latest notifications, and published FAQ/help links. PWA/offline presentation may cache only these read-only outputs; enrollment, document upload, grade-correction, and payment actions still require an online authenticated request.

**PWA/Splash Requirement**: The system must support installability over HTTPS or localhost, manifest branding, Android manifest-generated splash behavior, iOS startup-image coverage where supported, service-worker offline fallback, and no offline mutation.

**Feature Boundary**: The Student Hub/PWA baseline is service-backed read-only visibility first. Dashboard, schedule, grades, financials, documents, and help must render only data owned by the authenticated active student and must hide unpublished schedules, draft grades, staff-only financial details, internal IDs, raw storage paths, and other students' records. Offline behavior is allowed only as a read-only convenience with clear freshness labels; payment, document upload/request, grade correction, enrollment, and other mutations require an online authenticated request.

---

## 5. Module 2: Registrar Module

### 5.1 Pre-Semester Setup & Curriculum Import

Before the enrollment period opens, the Registrar prepares foundational data to enable automated evaluation and scheduling. System Super Admin does not configure academic schedule data because the role is read-only for academic operations; Academic Head may authorize exceptional overrides using the existing **Authorize Override** capability when policy requires higher approval.

**Required Datasets and Configuration** (must be prepared prior to opening enrollment):

| Dataset                        | Description                                                                                                    | Purpose                                                             |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| **Curriculum & Prerequisites** | Detailed list of all subjects for each grade/year with prerequisite relationships                              | Evaluates transferees and irregular students                        |
| **Scheduling Inputs & Drafts** | Section needs, subject loads, room inventory, faculty self-service availability, and proposed timetable drafts | Drives assisted conflict-free scheduling and final schedule export  |
| **Academic Calendar**          | Term name, scope (SHS/College), start/end dates, scheduling dates, enrollment periods, and adjustment periods | Configures system state transitions and unlocks time-bound features |

**Note**: Without these datasets, the system cannot automatically assign strands, evaluate credits, or generate schedules. Faculty availability and schedule generation stay locked until the target term has a name, `enrollment_starts_at`, `enrollment_ends_at`, and `scheduling_starts_at`.

#### 5.1.1 Academic Year and Term Timeline Setup for SHS and College

The system handles both **Senior High School (SHS)** and **College** academic calendars. Because these departments follow different regulatory guidelines, they possess distinct, configurable timeline rules. The Academic Year is the umbrella boundary only; operational scheduling, enrollment gates, grading windows, billing periods, and availability collection are controlled by the configured terms under that year.

- **Separate Timeline Handling**:
  - **SHS**: Follows DepEd-aligned school year dates (e.g., June to March). Configurable based on annual DepEd issuances rather than hard-coded. DepEd Order No. 012, s. 2025 is the current reference for SY 2025-2026 and succeeding-year memorandum behavior.
  - **College**: Subject to CHED rules, academic-hour requirements, and the school-approved/CHED-noted higher-education calendar. College dates must not be inferred from the DepEd SHS calendar. Terms are explicitly configurable and must be client-verified.

**1. Academic Year Setup Fields**
The Registrar must configure the overarching Academic Year before setting up specific terms:
- `academic_year`: (e.g., "2025-2026")
- `education_level`: `shs` or `college`
- `school_year_start_date`
- `school_year_end_date`
- `status`: `draft`, `active`, `closed`, `archived`
- `reference_note`: Source basis (e.g., "DepEd Order No. XX", "CHED-noted Calendar", "School-approved Calendar").

**2. Term Setup Fields (Canonical Calendar Contract v4.6)**
Each configured Academic Year supports multiple operational terms (quarters, semesters, or summer terms).

Required term identity and date fields:
- `term_name`
- `term_type` (`quarter`, `semester`, `summer`)
- `term_start_date`
- `term_end_date`
- `class_start_date`
- `class_end_date`

Required operational gate fields:
- `enrollment_starts_at`: Opens student applications and payment assessment
- `enrollment_ends_at`: Locks standard enrollment
- `late_enrollment_ends_at`: Final late-enrollment cutoff (if enabled by policy)
- `credential_submission_cutoff`: Latest ordinary deadline for completing admission credentials unless a requirement-specific regulator rule allows a later date
- `payment_deadline`: Last allowed timestamp for initial required payment
- `adjustment_ends_at`: Final cutoff for enrollment-affecting edits
- `scheduling_starts_at`: Unlocks Registrar schedule assignment and room mapping

**3. Specific Phase Behavior**
- **Admission and Capacity Readiness**: Applications may open for review, but payment and temporary enrollment remain locked until an approved admission-capacity plan exists for the applicant's term and scope. The plan is an institutional commitment; the academic calendar does not shift because an applicant or section is delayed.
- **Enrollment Period**: Standard enrollment is locked outside the `[enrollment_starts_at, enrollment_ends_at]` window. A late-enrollment path is disabled for the active deployment unless explicitly enabled by policy, and no override may pass `late_enrollment_ends_at`.
- **Credential Grace**: Before accepting payment, the system confirms that each unresolved requirement's derived deadline fits within `credential_submission_cutoff` or a documented requirement-specific exception. An extension cannot silently move class, grading, or term dates.
- **Scheduling Preparation**: Schedule assignment unlocks only if `scheduling_starts_at` is reached.
- **Late Edit Policy (Approved)**: Enrollment-affecting edits (`add/drop`, section transfer, irregular subject adjustments, schedule-slot reassignment) are allowed only while the active term windows are open. After `enrollment_ends_at`, the system hard-locks these edits unless an approved late-edit override policy exists.
- **Per-Level Cutover Policy (Approved)**: Calendar-model rollout is not global. SHS activates at the next configured SHS scheduling-term boundary, while College activates at the next semester boundary (1st/2nd semester). Mid-term cutovers are not allowed.

**Canonical Term Matrix (Must Be Filled Before F1 De-Deferral):**

| Education Level | Term Sequence | Term Label Examples | Required Entries |
| --- | --- | --- | --- |
| SHS | Approved Phase 1 scheduling terms: 1st Semester and 2nd Semester. SHS quarters remain grading periods only unless a future quarter-based scheduling change is approved | `SHS 1st Sem AY 2026-2027`, `SHS 2nd Sem AY 2026-2027` | `term_start_date`, `term_end_date`, `class_start_date`, `class_end_date`, `enrollment_starts_at`, `enrollment_ends_at`, `late_enrollment_ends_at`, `credential_submission_cutoff`, `payment_deadline`, `adjustment_ends_at`, `scheduling_starts_at` |
| College | 1st Semester, 2nd Semester | `1st Sem AY 2026-2027`, `2nd Sem AY 2026-2027` | Same required entries as SHS |
| Optional Summer | Summer Term (when enabled) | `Summer AY 2026-2027` | Same required entries as SHS/College; only if summer term is opened |

**SHS Quarter vs Scheduling Term Note**: SHS grading still uses quarter evidence inside an active semester where applicable. That does not mean the scheduler collects faculty availability four times per school year. Current SIA business evidence shows SHS curriculum/SOA periods grouped by 1st Semester and 2nd Semester, and the approved Phase 1 scheduling/availability cadence is semester-scoped.

**Calendar Setup Interface Rule**: School calendar setup is a two-level Admin Nexus workflow. First, authorized setup staff create or select an Academic Year per `education_level` (`shs` and `college` may share the same year label but have separate date ranges, statuses, and reference notes). Second, authorized Registrar/Academic Head staff create child Terms under that Academic Year. Terms, not Academic Years, hold the operational gates used by enrollment, scheduling, payment deadlines, adjustment locks, billing periods, faculty availability, class lists, and grade windows. The interface must not force College to inherit the SHS/DepEd calendar or force SHS to inherit the College/CHED calendar. The implementation uses a typed no-delete Academic Years admin resource plus the Terms admin resource; seed-only Academic Year setup is not acceptable for calendar operation.

**Required Cutover Entries (Per-Level):**
- `shs_cutover_effective_term` (first SHS term using the new model)
- `college_cutover_effective_term` (first College term using the new model)
- `shs_cutover_effective_datetime` and `college_cutover_effective_datetime`

**4. Date-Driven Feature Locking & Edge Cases**
If timeline fields are incomplete or violated, affected features automatically lock:
- **Missing `enrollment_starts_at` / `ends_at`**: Enrollment intake closes.
- **Missing `credential_submission_cutoff` or approved admission-capacity plan**: Payment and temporary enrollment lock for the affected applicant scope.
- **Missing `scheduling_starts_at`**: Faculty availability and schedule assignment lock.
- **Missing term/class dates**: Attendance, class-list visibility, and term close computations halt.
- **Edge Cases Handled**:
  - SHS and College have disjoint enrollment, class start, and adjustment dates (system allows simultaneous overlapping phases bound strictly by `education_level`).
  - Enrollment attempted before/after window -> Rejected (UI reflects "Enrollment Closed").
  - Academic year dates changed post-enrollment -> Audited via standard system logging.

**Faculty Availability Cadence Rule**: Faculty availability is submitted once per faculty per configured scheduling term, not once globally for the whole Academic Year. College normally requires one submission per semester, plus summer if a summer term is opened. SHS is approved as semester-scoped for Phase 1. Post-deadline or post-lock changes use the controlled availability change-request workflow rather than a second normal submission.

#### 5.1.2 Curriculum Intake & Versioning

Because academic departments often use varying formats for their curricula (Word documents, legacy Excel files with merged cells), the system mandates a **Strict Standardized Template** to ensure data integrity during upload.

**The Intake Flow:**

1. **Download Template**: Authorized curriculum managers download the standard Curriculum Template from the Filament staff panel. The MVP implementation may provide CSV and accept CSV/XLSX; a styled XLSX template with dropdowns/locked instructional sheets is an acceptable later enhancement if needed.
2. **Data Entry**: Academic staff map source curriculum documents into the normalized template. Business evidence and raw source files are reference inputs only; the imported template is the system contract.
3. **Upload and Preview**: The user uploads the populated template. The system parses it, validates headers, rejects blank/invalid rows, and creates a preview batch with valid/error counts. A file with the correct headers but zero valid subject rows may be stored as audit evidence, but it is not commit-ready.
4. **Commit**: A zero-error batch with at least one valid row may be committed. Commit creates or updates Programs, Subjects, Curriculum versions, Curriculum Subject rows, and scheduling classification data, then creates or updates the affected curriculum readiness scopes as `needs_review`. If a committed import touches a previously `ready_for_scheduling` scope, that scope returns to `needs_review` with audit evidence.
5. **Readiness Confirmation**: Authorized Registrar/Academic Head staff review coverage by `program + curriculum version + year/grade + curriculum period`. A scope becomes usable for scheduling only after it has at least one valid subject row, no hard blockers, and is explicitly marked `ready_for_scheduling`. Section planning may reference a `needs_review` scope for setup, but schedule generation and solver snapshots must remain blocked until readiness is confirmed.

**Readiness Scope Decisions:**

- Readiness is stored as explicit curriculum scope state, not inferred only from subject rows. The implementation key is `curriculum_id + year_level + curriculum_period`; `program` is derived through the selected curriculum and displayed as part of the review scope.
- Scope state lives on `curriculum_readiness_scopes` with statuses `needs_review`, `ready_for_scheduling`, and system-derived `blocked`. Transition history is written to the existing `activity_log`.
- `blocked` is derived by `CurriculumScopeReadinessService` when hard blockers exist. Staff may mark clear scopes ready or return them to `needs_review`, but staff do not manually select `blocked`.
- Readiness blockers are computed live for the current UI and readiness checks. Transition snapshots are stored when state changes, including actor, timestamp, blocker snapshot, and exception reason when required.
- Registrar owns curriculum data entry, import, and normal curriculum edits. Academic Head may view blockers and transition readiness when academically approved. System Super Admin is not part of the normal academic readiness path.
- Readiness does not hard-lock curriculum rows. Authorized scheduler-facing changes reset the affected ready scope back to `needs_review`, including subject changes, year/period changes, weekly contact hours, academic subject type, scheduling group, delivery override, row addition, or row removal.
- Normal ready transitions require actor, timestamp, and blocker snapshot. Exception cases require a reason, including all rows excluded from automatic scheduling or readiness after manual repair of legacy rows.

**Unified Template Column Definitions:**

| Column | Required | Contract |
| --- | --- | --- |
| `Education Level` | Yes | `shs` or `college`; drives allowed year/period values and reporting scope. The database may keep the legacy `department` column name, but all template/UI wording must use Education Level. Legacy `Department` headers are not accepted in the strict template. |
| `Program Code` | Yes | Program/strand/course code such as `BSIT`, `HUMSS`, or another approved school code. |
| `Program Name` | Yes | Human-readable program, strand, or course name. |
| `Curriculum Version` | Yes | Version label, e.g. `BSIT 2026` or `SHS HUMSS 2026`. |
| `Effective Year` | Yes | Four-digit starting year. |
| `Is Active` | Yes | Boolean-style input (`yes/no`, `true/false`, `1/0`). |
| `Year/Grade` | Yes | Must match canonical values used by sections and enrollment, e.g. `Grade 11`, `Grade 12`, `1st Year`. |
| `Curriculum Period` | Yes | `1st Semester`, `2nd Semester`, or another approved period. SHS quarter labels are grading evidence and do not create separate scheduling imports unless later approved. |
| `Subject Code` | Yes | Stable subject code. TESDA/NC and GE code patterns may be used for classification suggestions. |
| `Subject Title` | Yes | Human-readable subject title. |
| `Units` | Conditional | Required where academic units are used, especially College. May be blank for non-unit SHS subjects if the scheduling/contact fields are present. |
| `Weekly Contact Hours` | Yes | Scheduler-facing load/duration field stored on the curriculum-subject offering row. This replaces solver dependence on raw `Lec_Hours` or units. `0.00` is allowed only for modular/no-recurring-meeting rows with `Scheduling Group = modular`; synchronous online, on-site, and blended rows require a positive value. |
| `Academic Subject Type` | Yes | Academic meaning such as `general_education`, `professional_tesda`, `core`, `applied`, `specialized`, or `tvl`. |
| `Scheduling Group` | Yes | Operational scheduler bucket such as `minor`, `major`, `modular`, or `online_only`. |
| `Delivery Rule Override` | No | Optional constrained code, initially blank, `force_online`, `force_on_site`, `force_modular`, or `exclude_from_auto_schedule`. Free text is not allowed. |
| `Category` | No | Lecture/laboratory/seminar/practicum reference only; it does not replace `Weekly Contact Hours`. |
| `Sort Order` | No | Optional whole-number display/order hint. |

**Classification Rules:**

- Subject classification is two-layered. `Academic Subject Type` describes academic meaning; `Scheduling Group` describes operational scheduling behavior.
- MVP import requires explicit `Academic Subject Type` and `Scheduling Group` values. Later helper tooling may suggest classifications from subject code/title, but suggestions must not silently make blank fields valid or make a scope `ready_for_scheduling`.
- College `GE*` / General Education defaults to `Academic Subject Type = general_education` and `Scheduling Group = minor`.
- TESDA, NC II/NC III, professional, and program-specialization subjects default toward `Scheduling Group = major`. Client clarification confirms TESDA subjects with NC are major; GE subjects are minor.
- SHS headers/defaults map to `core`, `applied`, `specialized`, or `tvl`, while scheduling group depends on the approved delivery pattern.
- Ambiguous rows remain `needs_review`; they cannot feed automatic scheduling until confirmed.
- `exclude_from_auto_schedule` rows remain part of curriculum coverage but are omitted from automatic solver demand. Manual official assignment or ownership evidence remains possible where staff choose it. A scope whose rows are all excluded from automatic scheduling may become `ready_for_scheduling` only with an explicit reviewer reason.

**Versioning Rules (Source of Truth):**

- **No Mid-Year Replacements**: A curriculum is tied to an academic batch. The system does not allow a major curriculum replacement mid-academic year for an active batch, as this would corrupt existing enrollments and grade progressions.
- **Student Binding**: Existing students remain tied to the curriculum version active during their admission year unless they explicitly shift programs.
- **Clerical Edits**: If a minor clerical error is discovered (e.g., a typo in a subject title, or an incorrect prerequisite mapping), authorized Registrar staff can manually correct that specific curriculum/subject data through the Filament UI without needing to re-upload the entire Excel file. Academic Head reviews readiness after academic/scheduling-impacting changes. System Super Admin is not the normal academic data-entry role.

---

### 5.2 Student Record Management (Digital File Cabinet)

A digital record system of ALL students (past and present) powered by a centralized Filament Data Table. The system provides robust global search and filters to replace the legacy hierarchical folder drill-down approach.

**Organization (Filament Data Table Filters)**:
- **Department**: SHS vs College (Cascades Program/Strand filter).
- **Program/Strand**: Dynamic based on the selected Department.
- **Year/Term**: Scoped to the Active Term by default, but allows lookbacks.
- **Status**: Multi-select filtering (Active, Inactive, Graduated, Archived).
- **Standing**: Regular vs. Irregular.

**Status Definitions & Retention Policy**:

| Status | Description | Retention Policy |
| :--- | :--- | :--- |
| **Active** | Currently enrolled in an active term. | Permanent (Transcript needed) |
| **Inactive** | Previously enrolled but currently not. Must include a `status_reason` code (e.g., "LOA", "Dropped", "Financial", "Disciplinary"). | Permanent (History for Returnee flow) |
| **Graduated** | Successfully completed their academic program (Alumni). | Permanent |
| **Archived** | Soft-deleted records for purged applicants or severe expulsions. Data is hidden from operational queries to comply with RA 10173. | Soft-deleted / Long-term storage |

**Storage Boundary**: `users.status` is reserved for account lifecycle and authentication state. The future `student_profiles.operational_status` field owns the student lifecycle status values above. `student_profiles.status_reason` is required only when `operational_status = Inactive`; it is optional for Active, Graduated, and Archived records unless a later workflow explicitly requires a reason.

**Note on Irregular Standing**: `Irregular` is an *Academic Standing Flag*, not a base status. It appears as a badge derived from the `AcademicAdvisingStatus` service for active students.

**Student Jacket (View vs Editable Boundaries)**:
To maintain data integrity and strict RBAC alignment, fields in the Student Jacket are explicitly controlled:

*Editable by Registrar (Direct Edit)*:
- Contact Information (Address, Mobile, Guardian)
- Civil Status
*(Note: Direct edits do not send an email notification to the student or require student acknowledgment. These changes are saved instantly, display a success toast to the Registrar, and are securely recorded in the immutable Audit Trail.)*

*Editable by Registrar (Formal Change Request Required)*:
- **Identity Fields**: LRN, Name, Birthdate. (Requires proof upload to prevent duplicate or mismatched institutional/external records).
- **Enrollment Status**: Changing to `Inactive` (with reason "Dropped") triggers the policy-driven Drop/Withdrawal Fee assessment.

*Strictly View-Only*:
- **Academic Grades**: Managed by Faculty grading workflows.
- **Financial Balances**: Managed by Accounting module.
- **Student ID**: Immutable business key.
- **Audit Logs**: Immutable activity logs.

#### 5.2.1 Program Shifting Rules

**Purpose**: Control student-initiated shifting without corrupting curriculum binding, academic history, or financial assessment.

**Eligibility Boundaries**:

- **SHS Grade 12 Restriction**: Grade 12 SHS students cannot shift strand/program through the standard shifting workflow. Any exceptional handling must be outside the normal self-service flow and must be explicitly approved as a separate school policy.
- **College 2nd-Year Limit**: College students may request shifting only up to the approved 2nd-year limit. Requests beyond that limit are blocked by default and require a separately approved Academic Head exception policy before they can be processed.
- **Academic Record Preservation**: Shifting never deletes prior grades, enrollments, payments, documents, or curriculum history. The new program binding starts from the approved effective term.

**Workflow Ownership**:

1. Student submits a shifting request from the Student Hub.
2. Registrar reviews academic eligibility, curriculum fit, credited subjects, prerequisites, and available sections.
3. Academic Head may authorize exceptions only where school policy allows them.
4. Accounting/Cashier assesses and manages any shifting fee or financial adjustment after Registrar academic review.
5. The shift becomes effective only after academic approval and required fee handling are complete.

**Student-Facing Statuses**: `Submitted`, `Under Review`, `Approved - Pending Fee`, `Approved`, `Rejected`.

**Fee Boundary**: Shifting fee amounts and due dates are Accounting-owned configurable policy. The Registrar must not set or waive shifting fees.

---

### 5.3 Scheduling & Sectioning

#### 5.3.0 Delivery Pattern and Modality Impact on Scheduling

Modality is no longer treated as a single immutable property of the whole section. A section is the academic grouping and shared subject set. A **Section Delivery Group** is the subset of students inside that section who share a delivery setup such as online minor, Saturday face-to-face major, pure online, or modular print.

| Level | Business Meaning | Scheduling Contract |
| --- | --- | --- |
| `Section` | Academic grouping and shared curriculum/subject set | Holds term, program, curriculum, year/grade, period, name, and total section capacity. |
| `Section Delivery Group` | Students in that section with the same delivery setup/modality/pattern | Holds delivery pattern, modality, group capacity, room requirement, and scheduling rules. |
| `Section Meeting` | Actual scheduled class row | Targets section + delivery group + subject + faculty + day/time + room when required. |

| Modality / Setup | Scope | Room Assignment | Faculty Assignment | Conflict Checking | Schedule Display |
| --- | --- | --- | --- | --- | --- |
| **On-site / F2F** | Delivery group | Required | Required | Faculty, delivery-group/section, and room conflicts | Fixed timetable shown on COR |
| **Blended** | Delivery group | Required for MVP | Required | Same as on-site for conflict validation | Fixed timetable shown on COR; online-link tracking excluded |
| **Online synchronous** | Delivery group | Not required | Required | Faculty and delivery-group/section conflicts | Online schedule details without meeting URL management |
| **Modular print** | Delivery group | Not required | Teacher/adviser ownership required | No recurring class meeting conflict; ownership/eligibility still required | No in-system pickup/submission scheduling in MVP |

**Delivery Pattern Logic**: Delivery Patterns are reusable, versioned rule sets configured by Registrar/Academic Head staff. They may be assigned as program/strand + term defaults and inherited by sections and delivery groups, with explicit delivery-group overrides when needed. Once a Delivery Pattern version is used by committed schedules or enrollments, edits require cloning a new version rather than mutating history.

**Section Delivery Group Logic**: One section may contain students with different delivery setups if they take the same subject set. The default rule is same section + same subject + different delivery groups use the same faculty; the time/modality may differ. If the same faculty is impossible because of eligibility, workload, availability, or conflicts, the row remains an unassigned draft conflict unless Registrar/Academic Head approves a documented split according to policy.

**Staff-Assisted Modality Capture**: In MVP, Enrollment/Registrar staff record the student's declared modality/delivery preference during staff-assisted enrollment. Future Student Hub self-entry may feed the same field, but Registrar confirmation remains authoritative before section/delivery-group assignment.

**Assignment Suggestion Rule**: During sectioning, the system ranks compatible delivery groups by matching subject set, declared modality, available capacity, and schedule fit. The Registrar confirms the final assignment. The system must not silently auto-section students without Registrar confirmation.

**Online Link Boundary**: TALA records schedule modality, day, time, instructor, section, subject, and room when applicable. TALA does **not** require, validate, store, warn about, or export Zoom, Google Meet, LMS, or platform meeting URLs. Online link coordination remains outside the system unless the client explicitly approves link tracking in a later version.

**Term Readiness Gate**: The Registrar must first configure the target academic term with `term_name`, `term_start_date`, `term_end_date`, and `scheduling_starts_at`. If any required field is missing, faculty availability submission, availability locking, draft generation, and schedule commitment are disabled. The system shows a setup warning listing missing fields (for example: "Schedule setup locked: missing `scheduling_starts_at`").

**Approved Rescue Scheduling Architecture**: Automatic schedule generation is a cloud-hosted deterministic optimization workflow. TALA uses an IAM-private Google Cloud Run service running Google OR-Tools CP-SAT. Vertex AI is not the primary scheduler because timetable generation is a hard-constraint optimization problem, not a prediction problem. Laravel remains the source of truth, validator, reviewer, and committer.

**Pre-Solver Section and Delivery-Group Planning Rule**: TALA must plan sections and section delivery groups before calling the scheduler. The solver does not decide how many sections or delivery groups exist, does not split a year level into new sections, and does not create section records during solving. Registrar/setup staff first create term-scoped sections for each program and year/grade level, set `curriculum_id`, `year_level`, `curriculum_period`, total section capacity, and one or more delivery groups with modality, delivery pattern, group capacity, and room when required. Only after those records exist does Laravel derive subject demand from `ready_for_scheduling` curriculum scopes and ask the solver to assign faculty, room, day, and time.

**Correct Scheduling Sequence**: The business flow is section/curriculum first, then faculty/room/time solving. Faculty availability and faculty-subject eligibility are inputs used to assign each planned section's required subjects. They do not replace section planning and they do not determine the section count. A section can be scheduled only when it has explicit year-level/curriculum-period scope and a valid curriculum subject set.

**Cloud Solver Security Boundary**: The Cloud Run solver must require authentication and must not allow public unauthenticated invocation. Laravel calls the solver with a Google-signed ID token whose audience is the solver service URL. The invoking service account must be restricted to the Cloud Run Invoker role for the scheduler service; OCR credentials must not be reused unless a later security review explicitly approves shared credentials.

**Scheduling Source-of-Truth Layers**:

| Layer | Business Meaning | Rescue Contract |
| --- | --- | --- |
| Curriculum offering | Defines which subjects must be offered for a program, year level, and term/semester. | Use `curriculums`, `curriculum_subjects`, and `subjects`; scheduling must not invent subjects. |
| Section planning | Defines the academic class block for the target term. | Registrar/setup staff create sections before solving. The solver schedules existing sections and delivery groups only; automatic section creation/splitting is outside MVP. |
| Delivery-group planning | Defines delivery setup subsets inside a section. | Each section must have one or more delivery groups before scheduling if students in that section may have different delivery setups. |
| Section demand | Defines which section/delivery group needs which subject set for the target term. | Use `sections.curriculum_id`, `sections.year_level`, and `sections.curriculum_period` to map each section to ready curriculum scopes; each schedulable meeting row targets a section delivery group. Do not infer demand from section names. |
| Section capacity | Defines total students allowed in a section. | `sections.max_seats` remains editable by authorized Registrar/setup staff, cannot be below total assigned students, and is enforced together with delivery-group capacity. |
| Delivery-group capacity | Defines students allowed for a delivery setup inside the section. | Assignment is allowed only if both section capacity and delivery-group capacity have available seats. If capacity is full, the system blocks assignment and requires Registrar action; it does not auto-create overflow groups. |
| Faculty teaching eligibility | Defines which faculty may teach which subjects before the solver runs. | Add a minimal `faculty_subject_eligibilities` contract; do not use post-commit `section_teacher` as the pre-scheduling eligibility source. |
| Faculty availability | Defines when faculty are available for the target term. | Faculty submits availability windows only; they do not self-select official teaching subjects. |
| Room/catalog constraints | Defines room code, capacity, type, and availability. | Room requirement belongs to room-required delivery groups/meetings. A normalized room table may be used where implemented; online/modular groups do not require room. |
| Calendar/term constraints | Defines the scheduling period and operational gates. | Generate schedules per term as a recurring weekly timetable, not monthly or whole-year schedules. |

**Faculty Subject Eligibility Rule**: The faculty account creator, Academic Head, Registrar, or another approved administrative owner assigns the subjects a faculty member is eligible to teach. Faculty may view their assigned/eligible subjects but may not add, remove, replace, or self-approve teaching subjects from their own account. Any change request to teaching eligibility is handled outside the faculty self-service flow unless a later typed request workflow is approved.

**Accuracy and Validity Target**: The automatic scheduler target is **greater than 98% auto-assignment coverage for feasible inputs**. Every committed official schedule must still have **100% hard-constraint validity**. Any generated row with a hard conflict remains a draft conflict and cannot be committed.

**MVP Coverage Proof Boundary**: For Phase 1 closure, a feasible-input proof must use a deterministic solver fixture with at least 100 section-subject demands, pre-created sections, fixed rooms for room-required modalities, eligible faculty, submitted/locked availability windows, and avoidable existing commitments. Passing proof means greater than 98% of feasible demands are auto-assigned, unresolved demand remains non-committable, and every assigned row satisfies section, faculty, room, eligibility, availability, fixed-room, time, and existing-commitment hard constraints before any official schedule can be committed.

**Section Planning Readiness Flow**:

1. Registrar selects the target term and confirms term readiness.
2. Registrar/setup staff create the planned sections per program and year level, such as `BSIT 1A`, `BSIT 1B`, or Grade 11 sections.
3. Each section receives explicit solver scope: `curriculum_id`, `year_level`, and `curriculum_period`.
4. Each relevant curriculum scope must have valid subject rows and be marked `ready_for_scheduling`.
5. Each section receives total capacity and one or more delivery groups. Each delivery group receives modality, delivery pattern, group capacity, and fixed room when required.
6. Laravel derives each section/delivery-group required subject demand from the ready curriculum scope and delivery pattern rules, not from section names and not from faculty preferences.
7. Faculty-subject eligibility defines who may teach each derived subject.
8. Faculty availability defines when eligible faculty may teach.
9. The solver assigns faculty, room when required, day, and time for each section-delivery-group-subject demand.

**MVP Boundary**: Automatic section creation, automatic delivery-group creation, automatic student balancing across sections, and automatic generation of overflow sections/groups are not part of the MVP scheduler. If a program/year level or delivery setup needs more capacity, Registrar/setup staff create or adjust the section/delivery group before generation and rerun readiness.

**Approved Student Sectioning Rule**: Student assignment is owned by the Registrar or authorized setup staff, not by the scheduler. An enrollment stores both `section_id` and `section_delivery_group_id` once assigned. Students are assigned only into pre-created term sections and delivery groups. The system enforces both capacities with a hard transactional guard against overfilling. If a section or delivery group is full, assignment is blocked and Registrar/setup staff must create/adjust another compatible group or section before assigning the student.

#### 5.3.1 Step 1: Faculty Availability Self-Service Submission

**Implementation Scope**: The scheduling workflow includes Faculty Availability service/UI needed for automatic scheduling: Registrar opens a term availability period, Faculty submits weekly windows during the open period, Registrar reviews/locks submissions, and locked availability feeds the solver snapshot. Post-lock/deadline faculty availability change requests are handled as a controlled exception workflow. Faculty must not directly edit locked availability. Any late or post-lock revision must be filed with a reason, approved or rejected by the Registrar, audited, and then either replace solver input before generation/rerun or require an official schedule-change record after commitment.

**Schedule Draft and Publish Surface Boundary**: Schedule generation run records are created by the scheduling service/action layer after term readiness and conflict checks. The Registrar may view runs, review draft rows, and commit eligible generated or under-review runs through approved lifecycle actions. Committing a run must call a backend schedule-commit service that rejects conflicted/incomplete draft rows, creates official `section_meetings`, synchronizes faculty-section-subject assignment, records lifecycle activity, and marks the run committed. The official schedule is not released to stakeholders until Academic Head publish approval. The lifecycle is `draft generated` -> `reviewed` -> `committed official` -> `published` -> `revision requested/applied`. The Admin Nexus must not expose a generic Schedule Draft create/edit form for raw `term_id`, `requested_by`, `constraint_summary`, solver payload, or status mutation.

- **Registrar Action**: Before the target term scheduling period begins, the Registrar opens one limited availability submission period with `opens_at`, `closes_at`, and target term.
- **Cadence Rule**: The normal path is one availability submission per faculty per configured term. Academic Year setup alone does not unlock a whole-year faculty availability record.
- **Date Rule**: The system enforces `opens_at < closes_at <= scheduling_starts_at`. If no valid submission period exists, faculty availability entry and schedule generation remain locked for that term.
- **Faculty Action**: Faculty log in to their own account and enter available days and time windows for that term. Availability is entered as positive available windows, not unavailable blocks.
- **Faculty UI**: Faculty use the Admin Nexus `Faculty Availability` create form under the Faculty navigation group. The form shows only open availability periods and lets faculty add one or more weekly windows with Day, Start time, End time, and optional Notes. This is a structured weekly-window repeater, not a drag calendar. Faculty do not type their own `faculty_id`, `term_id`, status, lock metadata, or solver payload.
- **Deadline Rule**: Faculty must submit before the deadline. Faculty may edit only while the record is `draft` and the submission window is open. After the deadline or Registrar lock, faculty can only request an availability revision through the controlled change-request workflow.
- **Status**: Faculty submission is a simple record of available days and times. It is used as a reference during schedule assignment.

#### 5.3.1.1 Post-Lock / Deadline Faculty Availability Change Requests

**Purpose**: Allow realistic rescue-mode corrections when a faculty member misses the availability deadline or needs an exceptional revision after Registrar lock, without allowing direct mutation of solver inputs.

**Required Workflow**:

1. Faculty opens their own locked/submitted availability record and files a change request.
2. Faculty provides requested available windows and a required reason.
3. The system validates day/time format, `starts_at < ends_at`, no overlapping requested windows, and ownership of the source availability record.
4. Registrar reviews pending requests from a controlled admin surface.
5. Registrar approves or rejects the request with an optional review note.
6. If approved before a schedule is committed, the system creates a new approved availability revision or replacement snapshot and marks the previous locked input as superseded for future solver runs.
7. If approved after a schedule is committed, the request must not silently mutate official schedules. Registrar must process the downstream impact through the official Schedule Change workflow.
8. Every request, decision, old values, new requested values, actor, timestamp, and reason must be auditable.

**Boundary**: This workflow is not a generic availability editor. Faculty cannot edit `faculty_id`, `term_id`, approval status, lock metadata, or solver snapshot payloads. Registrar cannot bypass validation by typing raw JSON. Approved revisions affect only future generation/reruns unless a separate official schedule change is approved and applied.

#### 5.3.2 Step 2: Direct Schedule Assignment

- **Manual Assignment Logic**: The Registrar may assign a teacher, room, and time to a section directly as a fallback or correction path. Automatic generation uses schedule runs and draft rows; direct manual assignment is not the automatic scheduler.
- **Real-Time Validation (The Commit Guard)**: Upon saving an assignment, the system performs real-time conflict checking:
    - "Is the teacher assigned to another class at the same day/time?"
    - "Is the room already occupied at the same day/time?" (on-site meetings only)
    - "Is the teacher available at this time based on their submitted availability?"
    - "Is the teacher eligible to teach this subject?"

**If YES** -> Show Error: "Hard conflict detected. Resolve all blocking conflicts before saving." (Save Failed)

**If NO** -> Save Successfully

**Faculty Assignment Tracking**: When saved or committed, the system records official meeting rows in `section_meetings` and synchronizes the `section_teacher` pivot. This enables grade submission tracking (Section 8.4.1), faculty class list generation (Section 7.1.1), and final schedule export.

**Admin Mapping**: Direct Schedule Assignment is exposed as a Registrar-only `Manual Assignment` create flow on Official Schedules. The form uses typed fields for term, section, section delivery group, subject, faculty, room, day, start time, end time, and modality. The system derives `committed_by`, `committed_at`, and manual-vs-draft source internally; staff do not manually type these values. Once an official meeting row exists, TALA does not expose direct edit/delete actions for it. Corrections after commitment or publication must be recorded through the Schedule Change workflow below.

**Conflict Guard**: The guard rejects invalid time ranges, overlapping section assignments, overlapping faculty assignments, overlapping physical-room assignments for on-site/blended meetings, missing faculty assignment, missing faculty-subject eligibility, and solver rows outside locked faculty availability windows before commit.

**Implemented Faculty-Input Readiness Rule**: Automatic schedule generation is blocked before solver dispatch when any required section-subject demand has zero schedulable faculty. A schedulable faculty member means the faculty member has active faculty-subject eligibility for the subject and submitted or locked availability with at least one availability window for the target term. Missing availability from other eligible faculty may be shown as a readiness warning when at least one schedulable faculty member remains for that demand. Registrar-verified manual assignment remains the fallback for exceptional cases where faculty availability was not captured in time.

**Manual Assignment Availability Override Rule**: Manual official-schedule assignment still requires faculty-subject eligibility and must still reject hard faculty/room/section time conflicts. Faculty availability is a soft-but-audited guard for this fallback path. If the selected meeting time is outside the faculty member's submitted/locked availability, or if the faculty member has no availability submission for the term, Registrar may proceed only by entering an override reason. The reason, actor, timestamp, target meeting data, and availability condition become audit evidence. This override does not permit assigning ineligible faculty, overfilling sections, double-booking faculty, double-booking rooms, or bypassing post-commit Schedule Change workflow.

**Automatic Generation Flow**:

1. Registrar confirms term readiness.
2. Registrar/setup staff create planned sections for the term by program and year level.
3. Laravel verifies section planning readiness: every target section has `curriculum_id`, `year_level`, `curriculum_period`, modality, valid capacity, and room input when required.
4. Laravel verifies faculty-input readiness: every required section-subject demand has at least one schedulable faculty member with active eligibility and submitted/locked availability for the term.
5. Registrar clicks Generate Schedule from Schedule Generation Runs.
6. Laravel creates a run and captures an immutable input snapshot.
7. Laravel dispatches a queue job after database commit.
8. The queue job triggers the IAM-private Cloud Run solver service with a Google ID-token authenticated request.
9. The solver runs OR-Tools CP-SAT with a strict timeout and returns result JSON or writes result JSON to Cloud Storage.
10. Laravel validates every returned row against curriculum, section capacity, delivery-group capacity, mandatory faculty assignment, faculty-subject eligibility, availability, room, section/delivery-group, modality, and conflict rules.
11. Laravel inserts `schedule_draft_rows` with `ok`, `warning`, or `conflict` status.
12. Registrar reviews the run.
13. `ScheduleCommitService` commits only runs with no blocking draft-row conflicts.
14. The commit creates `section_meetings`, updates `section_teacher`, and records activity.
15. Academic Head reviews and publishes the official schedule. System Super Admin may emergency-publish only with a required reason and audit trail.

**Solver Contract**: The system enforces section planning readiness, delivery-group readiness, faculty-input readiness, manual assignment availability override audit, solver-row validation, publish governance, and 100% commit validity for the hard constraints.

**Constraint Coverage Boundary**: The scheduling implementation covers section delivery groups, scoped curriculum readiness, weekly contact hours, delivery patterns, delivery-group capacity, schedule publish lifecycle, and Laravel-side workload/availability soft-override boundaries. The approved MVP policy is: eligibility, faculty time overlap, section/delivery-group time overlap, room conflict, invalid calendar day/time, missing required delivery group, and missing required curriculum/scope data are hard blocking constraints; missing/outside availability and faculty workload overload may be approved only as audited soft overrides where specified. Broader policy values such as lunch-break windows, max back-to-back load, and exact max-weekly-hours defaults remain configurable policy inputs and require executable tests before acceptance.

**Post-Publish Changes**: Availability changes after schedules are committed or published do not automatically affect approved schedules. Any official schedule change requires a request, reason capture, approval, and audit history containing old values and new values. Registrar-facing schedule-change forms must use typed fields for the target official meeting, requested teacher, section delivery group, room, day, start time, end time, modality, and reason. The target official meeting control must be scoped to the selected term, show descriptive meeting labels, and reject submitted meetings that do not belong to that term. The old/new values may be stored internally as audit snapshots, but raw JSON payload editing is not an admin workflow. After publication, direct edits are blocked; changes go through `revision requested` / approval / `applied` flow. Approve and Apply controls must call a backend lifecycle service that validates `authorize-overrides` or `manage-schedules`, accepts only valid state transitions, applies the normalized payload through the official schedule assignment conflict guard, notifies affected faculty/sections where notification channels exist, and records lifecycle activity.



#### 5.3.4 Step 4: Capacity Management

- **Business Rule**: Capacity is enforced at both `Section` and `Section Delivery Group` levels.
- **Section Capacity**: Defines the total students allowed under the academic section.
- **Delivery-Group Capacity**: Defines the students allowed for a specific delivery setup/modality inside the section.
- **Safe Edit Rule**: Authorized Registrar/setup staff may edit capacities before or during sectioning, but section capacity cannot be lowered below the total assigned students and delivery-group capacity cannot be lowered below the assigned students in that group.
- **Auto-Close Rule**: If either the parent section or the target delivery group has no available seats, the assignment is blocked.
- **Overflow Boundary**: The previous 10% overflow/PIN model is not part of the approved MVP flow. If capacity is insufficient, Registrar/setup staff must create or adjust an approved section/delivery group and rerun affected readiness/scheduling as needed.
- **Log**: All capacity edits are logged in the audit trail.

#### 5.3.5 Scheduling Edge Cases

| Scenario                                                    | System Behavior                                                                                                                              | Responsible Resolver              |
| ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| Incomplete academic term setup                              | Lock availability and scheduling features; show missing required term fields                                                                 | Registrar                         |
| Missing `term_start_date`, `term_end_date`, or `scheduling_starts_at` | Lock availability period creation, faculty availability editing, and schedule assignment                                                     | Registrar                         |
| Missing planned sections for a program/year level           | Block schedule generation for that scope; Registrar/setup staff must create sections before solving                                          | Registrar / Setup Staff           |
| Section missing `curriculum_id`, `year_level`, or `curriculum_period` | Mark term scheduling readiness as failed; do not derive subject demand from section name                                                     | Registrar / Setup Staff           |
| Missing faculty availability submission period              | Keep faculty availability and schedule assignment locked for the selected term                                                               | Registrar                         |
| Faculty misses availability deadline                        | Mark as missing/late; Registrar manually verifies availability during schedule assignment                                                    | Registrar                         |
| Faculty submits availability late                           | Store as late; Registrar reviews during manual assignment                                                                                    | Registrar                         |
| Faculty requests change after deadline or Registrar lock    | Faculty files a formal availability change request with requested windows and reason; Registrar approves/rejects; approved revisions replace future solver input only. | Registrar                         |
| Faculty requests change after schedule assignment           | No automatic schedule mutation; requires official schedule change record with old/new values and approval.                                    | Registrar / Academic Head         |
| Teacher double-booking                                      | Hard conflict; assignment cannot be saved until resolved.                                                                                    | Registrar                         |
| Section delivery group full                                 | Block assignment; Registrar creates/adjusts a compatible delivery group or section before assigning the student.                              | Registrar / Setup Staff           |
| Curriculum scope not ready for scheduling                   | Block section planning/schedule generation for that scope only; other ready scopes may proceed.                                               | Registrar / Academic Head         |
| Export failure after schedule commitment                    | Official schedule remains committed; export can be retried without changing schedule records                                                 | Registrar                         |
| Online class link changes externally                        | No TALA action required because meeting URLs are outside system scope                                                                        | Faculty / Department outside TALA |

#### 5.3.6 Summer Class Scheduling Panel

**Purpose**: Give the Registrar a controlled panel for opening, reviewing, and assigning summer classes without bypassing curriculum, prerequisite, capacity, or financial rules.

**Registrar Actions**:

1. Review students whose regular-term load was split because of the 30-unit cap, failed/back subjects, or schedule conflicts.
2. Open summer class candidates for eligible subjects only when the academic calendar contains a valid summer term.
3. Assign students to summer sections manually or from the proposed Summer Load bucket.
4. Commit the summer schedule using the same conflict, capacity, and audit rules as regular scheduling.

**Constraints**:

- Summer classes are optional operational offerings, not automatic entitlements.
- Registrar owns academic scheduling and subject assignment.
- Accounting owns any summer tuition or shifting-related fee assessment tied to the summer load.
- Faculty see assigned summer classes only after the Registrar commits the summer schedule.

---

### 5.4 Enrollment Management

**Section Assignment Boundary**: Enrollment finalization or Registrar-assisted enrollment may assign a student to a section only from the pre-created sections for the target term, program, year level, curriculum, and modality. The assignment path must use the same capacity rule as Scheduling §5.3.4: `max_seats` cannot exceed 30, cannot be lower than `enrolled_count`, and cannot be bypassed by automatic balancing or overflow. Any future automated recommendation may suggest a section, but the authoritative assignment remains Registrar/admin-owned until a later policy explicitly approves automation.

#### 5.4.1 Step 1: Pending Applications Review

- **View**: Registrar sees a "Pending Applicants" queue showing students who have submitted documents
- **Business Rule**: If documents are valid, Registrar clicks "Approve". If invalid, clicks "Reject" → Triggers the Rejection Loop

#### 5.4.2 Step 2: Transferee Evaluation (For Transferees Only)

- **View**: Registrar sees students in For_Evaluation status
- **Action (College)**: Views uploaded TOR/Grades. The system presents a pre-filled list of "Credited Subjects" generated via Google Cloud Vision OCR extraction and regex/fuzzy text-matching against the curriculum. The Registrar reviews the automated matches, makes adjustments, and approves → Unlocks "Subject Selection" (Shopping Cart).
- **Action (SHS)**: Views Grade 11 Card. Google Cloud Vision OCR highlights possible promotion/completion signals via text pattern-matching; Registrar confirms. Registrar approves → Assigns Block Section (Grade 12).
- **Outcome**: Student moves to Payment Phase (Payment instructions/checkout only enabled after this Registrar approval).

#### 5.4.3 Step 3: Verification & Physical Handover

- **Context**: Student arrives with the brown envelope (Hard Copies)
- **Action**: Registrar verifies physical docs match the digital uploads
- **System Action**: Registrar records receipt against each applicable physical requirement item. The system derives physical completeness only after all required items are received.

#### 5.4.4 Step 4: Finalize Applicant (Account Migration)

- **Trigger**: "Confirm Physical Submission" (Registrar) + "Payment Confirmed" (Cashier)
- **Phase 2 (Official)**: Upon "Official Handover", the system performs Credential Rotation

**Mechanism**:

1. **Username Update**: The email column is replaced/unlinked as the auth identifier. The new student_id becomes the primary login username
2. **Password Reset**: A new system-generated password is set
3. **Result**: The old email login stops working. The User Row #ID stays the same (Data preserved)
4. **Dependency**: This action is AUTO-TRIGGERED once both conditions are met. Registrar does not manually "Create Account"

#### 5.4.5 Step 5: External DepEd LIS Handoff (Out of System)

**Business Rule**: TALA owns the institution's enrollment lifecycle. Once finance, required physical documents, and section placement are complete, the enrollment becomes `Enrolled`, the account activates, and COR/class access becomes available. Any required DepEd LIS encoding is performed by authorized school staff in the external DepEd system and does not create another TALA enrollment state.

**TALA Support Boundary**:

1. Admin Nexus provides a read-only **Enrolled Student Roster** containing only `Enrolled` records for a required term.
2. Registrar may filter the roster by education level, program/strand, year/grade, section, delivery modality, and student type, then export the filtered data for authorized external reporting or manual encoding.
3. The roster includes the student identity, LRN where applicable, demographic, guardian, prior-school, program/strand, year/grade, section, and term fields already held by TALA. It must not claim to reproduce or submit an external regulator form automatically.
4. TALA does not store `lis_status`, `lis_encoded_at`, `lis_notes`, an LIS queue, or a "Mark as Encoded" action. Exporting or viewing a roster never changes enrollment state.
5. If staff later discover an external-system conflict that invalidates the institutional enrollment, they use the normal audited enrollment correction/cancellation workflow with a typed reason. The external system does not directly mutate TALA.

**Scope Boundary**: Direct LIS API integration, browser automation, credential storage, completion tracking, and two-way synchronization are outside the TALA MVP.

---

### 5.5 Registrar Walk-In Entry (Staff-Assisted)

**Scope**: Dedicated workflow for Registrar staff to enroll students physically present with hard copies

**Implementation**: A button inside the Registrar Panel ("Enroll New Walk-In") that opens a streamlined form

**Constraints & Digital Retention**:

- **Optional Document Upload**: The Registrar form includes an *optional* secure file upload field for scanned copies or photos of the student's physical documents.
- **OCR Rule**: Registrar-assisted submissions use OCR only when a scan is attached and the configured requirement item enables OCR. Physical inspection without a scan remains manual and does not call Google Vision.
- **Name Capture**: Registrar walk-in intake captures First Name, Middle Name (optional), Last Name, and Extended Name/Suffix using the canonical account-name fields; it must not store a separate student-only full-name value.
- **Enforces**: Prerequisites, Payments, and Capacity (Same rules as online)
- **Audit**: Tagged as Source: Walk-In (or `Staff_Assisted`) in logs. Uploaded files are logged with `ocr_review_status = manual_entry`.

**Registrar Document Review Surface Boundary**: The Registrar Document Review screen is a list/view review queue. Staff may inspect source upload evidence, OCR/manual-review metadata, and lifecycle review actions such as approve, needs correction, or reject. It must not expose a generic create/edit Document Upload CRUD form, raw OCR text as a normal editable field, or student/Registrar-approved payload snapshots as free-text admin inputs. Review transitions must call a backend lifecycle service that validates `approve-documents`, accepts only active review states, treats approved/rejected records as terminal lifecycle evidence, requires typed reasons for correction/rejection decisions, copies student-confirmed payload data only on approval, and records document-review activity evidence. Detail views must show descriptive student, uploader, term, reviewer, review-status, and source-file evidence labels, not raw internal foreign-key IDs or the private storage `file_path`.



---

### 5.6 Global Enrollment Lock & COR Generation

**Trigger**: COR availability follows official enrollment handover. It is not a separate external-reporting state and does not wait for DepEd/CHED/LIS portal encoding.

**System Logic**:

1. **Source rule**: COR is generated only from canonical `Enrolled` data after admission, finance, capacity, and compatible section/delivery placement are clear in the handover transaction.
2. **Contents**: The COR displays student identity needed for institutional use, term, program/strand, section/delivery group, schedule, subject/load details, and authorized assessment/payment-clearance summary fields approved for COR display.
3. **Artifact rule**: The generated COR is a private derived artifact or reproducible issuance snapshot. It is not the source of truth for enrollment, schedule, grades, or finance.
4. **QR Verification Contract**: QR code contains an online verification URL using an opaque token or signed route. It must not expose raw `Student_ID + Active_Term + Security_Hash` as the visible payload.
5. **Official Verification Requirement**: Internet is required for third-party authenticity checks so the system can confirm whether the COR is current, superseded, revoked, or not found. Offline/PWA COR access is read-only viewing convenience only and is not proof of current validity.
6. **Verification Privacy**: The public verification page shows only minimal document validity details: document type, reference/serial where applicable, issue date, status, and enough displayed identity/term metadata to compare against the presented document. It must not expose balances, payment history, transactions, promissory details, internal ledger fields, private file paths, or grade data.
7. **Lifecycle**: Corrections, invalidated enrollment, or replacement CORs supersede or revoke the prior issuance/token with actor, timestamp, and reason evidence. Issued history is not overwritten.
8. **External Reporting Independence**: External regulatory encoding does not affect COR generation. Any later institutionally disqualifying discrepancy uses the normal audited enrollment correction/cancellation workflow.

**Scope Constraint**:

- COR is the immediate core generated academic document because it proves official enrollment.
- Report cards/COG derive only from Registrar-verified finalized grades.
- COE, Good Moral, TOR/Form 137 copies, Form 138/report cards, diploma/certificates, and dismissal/transfer credentials follow the document request and release rules in §9.1 unless a later slice promotes full automation.
- Diploma preparation/release remains a graduation/credential workflow; the first acceptance baseline may record eligibility/release evidence without automating government submission or final diploma printing.

---

## 6. Module 3: Accounting Module

**Finance duty-separation rule**: TALA may retain one Accounting/Cashier staff-role label, but collection, recording, and verification are separate permissions and lifecycle actions. Where maker-checker control applies, the actor who records or confirms a transaction cannot approve the same daily close or variance. Amounts, balances, receipts, and delinquency details remain private to authorized Accounting users and the owning student; faculty and unrelated staff receive only the minimum permitted clearance/status result.

### 6.1 Tuition Assessment Logic (Auto-Assessment)

**Efficiency Strategy**: Accounting maintains approved, versioned fee structures scoped by effective period, academic year/term, education level, and optional program/year-grade/student category. Each structure contains typed component lines such as tuition, laboratory, miscellaneous, and other approved charges plus the applicable downpayment, discount, scholarship, and installment-policy references.

**Process**:

1. **Deterministic Resolution**: At assessment time the system resolves exactly one active fee structure for the enrollment and assessment date. Missing or ambiguous matches block posting.
2. **Immutable Assessment Snapshot**: The system materializes an assessment header and component lines containing the selected structure/version, resolved scope, quantities/units where applicable, policy versions, gross amount, discounts/scholarships, net amount, actor, and assessment time before posting ledger effects.
3. **Controlled Import (Optional)**: Accounting may import fee structures through a versioned template, validation preview, and explicit audited commit. Import does not bypass approval/effective-date rules.
4. **Manual Adjustments**: Accounting may add one-off charges or corrections only through an approved typed adjustment workflow. TALA does not expose raw ledger CRUD.
5. **Finalization**: Ledger entries reference the immutable assessment snapshot. Current Balance is a rebuildable projection over ledger history, not independently editable authority.

**Ledger Admin Surface Boundary**: The Ledger Entries screen is an Accounting review/evidence surface. Accounting may view and filter assessed fees, payments, discounts, penalties, shipping debt, credits, and balances, but must not create, edit, or delete arbitrary ledger rows through generic Filament CRUD. Ledger mutations are produced by assessment, discount, payment-confirmation, webhook, installment-penalty, document-request, or future approved adjustment services.

**Assessment Selection and Idempotency Contract**: Auto-assessment selects the most specific active fee template matching the student's education level, optional program, and optional year/grade. An exact program-and-year template takes precedence over broader program-only or education-level defaults. Re-running assessment for the same enrollment/template must return the existing assessment result without duplicating fee or discount ledger entries.



#### 6.1.1 Automated Freshmen Discounts

- **Strategy**: SIA operates on a Non-Subsidized, Regular Rate model but offers an automatic 50% discount on the **Tuition Fee** for incoming freshmen.
- **Trigger**: During the auto-assessment, the system evaluates the student's level and type. If `student_type == 'New'` AND (`year_level == '1st Year'` OR `year_level == 'Grade 11'`), the system calculates 50% of the assessed Tuition Fee.
- **Process**: The calculated discount is applied instantly to the student's ledger as a **Negative Ledger Entry** (Credit), effectively reducing the overall balance.
- **Exclusions**: Transferees, returning students, and non-freshmen do not receive this automatic discount. The discount strictly applies to the Tuition Fee (Miscellaneous, Laboratory, and Other fees remain at 100%).

#### 6.1.2 Irregular Tagging

- **Logic**: For Irregular students, the system flags the assessment as **"Custom Calculation Required"** to ensure unit-based fees are verified before the student can pay

---

### 6.2 Payment & Policies

#### 6.2.1 Step 1: Payment Processing Modes

**Mode A: Online Payment Gateway (PayMongo - GCash/E-Wallet)**

- **Workflow**: Student pays through the online payment gateway during checkout and is redirected to the provider's official interface.
- **Validation**: The system confirms payment from PayMongo webhooks only. Hosted Checkout uses `checkout_session.payment.paid` as the primary success event. `payment.paid` is accepted only when it maps to an existing TALA provider reference.
- **Redirect Rule**: A success/return URL is not proof of payment. The system must never mark a payment as paid from redirect navigation alone.
- **Idempotency Rule**: Duplicate webhooks and manual reconciliation use the same provider event/payment reference so the ledger is posted only once.
- **Atomic Trigger**: Ledger updates once payment is confirmed. If the PayMongo `payment_attempt` is linked to a real enrollment, the same transaction evaluates minimum-downpayment/full-payment finance clearance and secures the approved capacity reservation. Account handover occurs only when admission and placement gates are clear; an approved retention undertaking is tracked separately.


**Mode B: Over-The-Counter (OTC) & Manual Bank Transfer**

- **Workflow**: Student uploads a GCash screenshot, bank deposit slip, or physical receipt.
- **Validation**: Cashier manually reviews the uploaded image, comparing it against bank records, and enters the **Amount**, **Reference Number**, and **Date** into the system before clicking confirm.
- **Allowed Manual Channels**: Enrollment payment confirmation accepts Cash, GCash Manual, or Bank Transfer. PayMongo reconciliation is not a typed manual channel; a missed PayMongo event must retrieve the provider object and use the idempotent provider-processing path.
- **Eligibility Rule**: The enrollment must already have assessment ledger evidence. A blank reference, unsupported channel, non-positive amount, future payment date, or duplicate normalized reference is rejected before ledger effects are committed.
- **Atomic Trigger**: Once "Confirmed" by Cashier, the ledger updates instantly.
- **Atomicity Rule**: Payment evidence, its negative ledger credit, the student's running balance, finance-clearance evaluation, secured-seat result, and audit evidence commit as one transaction or roll back together. If admission and placement gates are already clear, account handover may join the transaction; otherwise an explicit non-active pending state remains until the responsible gate is resolved.
- **Admin Surface Boundary**: Payment Queue and Confirmed Payments are not generic payment CRUD modules. `payment_attempts` are created by checkout/manual upload/service workflows and confirmed through approved Accounting actions. `payments` are immutable evidence records created by webhook processing, manual confirmation, or reconciliation services. Accounting may view, filter, confirm eligible queued attempts, and inspect ledger evidence, but must not create or edit arbitrary `payment_attempts` or `payments` through generic forms.

**Mode C: Promissory Note (Promise Tracking Only)**

- **Workflow**: Applicant-owner or active-student-owner backend requests record amount, reason, and promised payment date against a specific enrollment. Student-facing UI remains deferred, so Accounting may also create a staff-assisted pending request from the Admin Nexus. Only one open promissory request is allowed per enrollment; settled/rejected/cancelled/expired history remains audit evidence.
- **Approval**: **Accounting/Cashier** is the canonical reviewer of financial promissory notes. The Admin Nexus acts as an approval queue using typed approve/reject/cancel actions. The Registrar sees a read-only **"Promissory Active"** tag/badge on the student's enrollment record but cannot approve or modify promissory notes.
- **System Logic**: A validated Promissory Note records the student's payment promise and expiry date for Accounting follow-up. It does **not** clear the balance, does **not** satisfy the minimum downpayment requirement, and does **not** move the student to `Enrolled`.
- **Effect**: Promissory approval does not unlock COR, class-list visibility, official enrollment, or exam access by itself. The student remains financially pending until the minimum downpayment is actually received or the balance is fully paid. If real payment later satisfies the promise, the promissory note may be marked `settled`, but any remaining balance still applies.
- **Exam Access Boundary**: Exam access exceptions are not decided by promissory status. Under Republic Act No. 11984, qualifying disadvantaged students may be accommodated for examinations with social-welfare certification or institution-approved discretionary handling while the school retains collection and record-withholding remedies. TALA records this through a separate Exam Access Accommodation workflow.
- **Admin Surface Boundary**: In the Admin Nexus implementation, Accounting/Cashier reviews pending promissory requests in an approval queue, using approve/reject/cancel actions. Enrollment and ledger choices must be scoped to the selected student and term, with backend validation rejecting cross-student, cross-term, or cross-enrollment submissions. Raw unscoped enrollment-ID or ledger-entry-ID pickers are not approved promissory UI. Accounting may view/filter promissory records using descriptive student, enrollment, ledger, requester, reviewer, and settlement labels rather than raw foreign-key IDs, but must not edit arbitrary status values through a generic form after creation. Registrar, Faculty, Student, and Academic Head surfaces may show only the permitted high-level status/tag and must not expose amount history or mutation actions.

#### 6.2.2 Service Fees & Assessments

- **Drop-out Fee**: If a student officially drops out, an effective-dated withdrawal/refund fee is assessed based on approved institutional policy, which must align with DepEd/CHED regulations on withdrawal timing versus total term fees. Students may drop even with outstanding balances, but cannot request documents until settled.
- **Document Request Fees**: Paid document requests require the document fee to be confirmed by Accounting before Registrar fulfillment begins. Free document requests bypass Accounting and proceed directly to the Registrar queue.
- **Shipping Fees**: Delivery requests use a two-phase model. The document fee is confirmed before fulfillment. After shipment, the Registrar records the actual shipping fee and the request moves to `pending_shipping_payment` until Accounting confirms payment. If unpaid after 3 calendar days, the shipping fee is posted as debt and normal hold rules apply.

#### 6.2.3 Business Rules

**Downpayment & Minimum Required Payment**:

- **Initial Rule**: Initial payment clearance is governed by `minimum_downpayment_percentage` attached directly to each program's `fee_templates`.
- **Ownership**: Accounting configures the base fee templates and the downpayment percentage. 
- **Scope Contract**: Fee templates use canonical scope fields: education level, optional program, and optional year/grade. Year/grade values must match the enrollment values used by finance services (for example `Grade 11`, `Grade 12`, `1st Year`, `2nd Year`, `3rd Year`, `4th Year`). Blank year/grade means all year/grade levels for the selected education level. Only one active fee template may exist for the same education/program/year scope; older alternatives must be inactive historical records before a replacement becomes active.
- **Student Visibility**: The Student Hub must show the student's assessed fees, minimum required downpayment, remaining balance, and whether the account is currently finance-cleared.
- **State Transition**: Reaching the minimum downpayment/full-payment threshold creates finance clearance and secures the approved capacity reservation. Applicant-origin enrollment proceeds to **Enrolled** only when admission gates and placement also succeed. Retention undertakings remain separate holds. External regulatory encoding does not create another TALA state. Promissory notes do not trigger these transitions.
- **Threshold Precision**: The minimum required payment is calculated from the enrollment's net assessment after automatic discounts. Payments below the configured threshold remain financially pending; reaching the threshold exactly is sufficient for finance clearance but not, by itself, for applicant account handover.

**Approved F10 Target Policy (for rollout once de-deferred):**

- **Installment Structure**: Configurable installment plans up to **10 months** total.
- **Due-Date Rule**: Monthly installment due date is **end of month**.
- **Missed-Payment Rule**: **3-day grace period**, then overdue handling applies.
- **Scope Contract**: Installment policies use the same canonical education/program/year scope as fee templates. Only one active installment policy may exist for a scope; inactive historical policies may share a scope for audit and replacement history.
- **Penalty Rule**: **5% penalty** on overdue installment amount (aligned with SOA wording).
- **Promissory Interaction**: Promissory notes remain promise records only; they do not create finance clearance.
- **Appeal Exception Path**: Accounting may grant case-based grace/consideration only through manual appeal handling outside the normal automated flow.
- **Admin Surface Boundary**: Accounting configures installment policy scope, due rule, grace days, penalty rate/frequency, and child milestone schedule rows through the typed Installment Policy screen. Milestone rows capture sequence, month offset, required percentage, and active flag as policy configuration only. They must not be exposed as standalone generic Create/Edit milestone pages or raw `status` selectors. Payment state (`paid`, `in_grace`, `overdue`) remains calculated by installment services and scheduled jobs from balances, due dates, grace windows, and payment evidence.

**Exam Permit Visibility**:

- **Trigger**: A student can view/download their digital Exam Permit ONLY IF:
    1. `Current_Balance <= 0` (Fully Paid)
    2. A valid Exam Access Accommodation is approved for the applicable term or academic year.
- **Accommodation Rule**: Financial promissory-note approval is not the access decision. RA 11984/social-welfare certification or documented institution-discretion evidence must be recorded in the separate Exam Access Accommodation workflow. Senior High School statutory accommodations apply for the academic year; College accommodations are term-scoped unless a later approved policy says otherwise.
- **Privacy Rule**: Faculty, public verification, and student-facing exam decision responses must never expose certification files, certification numbers, balances, payment channels, or promissory amounts. They receive only the high-level permit/access result.

#### 6.2.4 Financial Disposition Policy

**Business Rule**: Every payment-related cancellation, refund, or adjustment is resolved by a versioned financial-disposition policy scoped to the deployment, effective period, education level, term/program when needed, and typed event cause. The current approved workflow allows refund of the admission/enrollment fee within 15 days of the Official Receipt date and makes tuition non-refundable after official enrollment. These rules must not be hard-coded as universal TALA behavior; other deployments may use legally and operationally approved percentage schedules, account credits, term transfers, or controlled refund workflows.

**Benchmark Basis**: CHED Memorandum Order No. 40, s. 2008 provides a default higher-education refund schedule while recognizing institutional policies, and mature SIS products such as Oracle PeopleSoft use dated adjustment calendars and percentage schedules. Configuration alone does not establish legal compliance; each deploying institution remains responsible for approving its policy against applicable law and regulator guidance.

**Scenario Handling**:

1.  **Within 15 Days of Official Receipt**: An authorized refund request for the admission/enrollment fee is evaluated against the effective policy, payment channel, prior dispositions, and official-enrollment evidence. Approval and payout mechanics require finance reconciliation and immutable audit evidence.
2.  **After Official Enrollment**: Tuition is non-refundable under the current approved workflow. Any refundable fee component remains governed by its own effective policy rather than a blanket payment-retention rule.
3.  **External Regulatory, System, or Institution-Caused Failure**: Registrar records the typed cause and Accounting resolves each paid component through the active policy. The event must not be mislabeled as applicant document noncompliance.
4.  **Other Cancelled Enrollment**: Operational cancellation does not alter historical payment evidence. Accounting resolves the typed cause and fee components through the active policy.
5.  **Duplicate Payment**: Duplicate PayMongo webhook retries are blocked by idempotency. A separate second successful payment is preserved and resolved according to the active policy; the active deployment retains it as an account credit unless an authorized case disposition says otherwise.
6.  **Overpayment**: The excess remains an immutable account credit unless the active policy authorizes another disposition.

**Restriction**: A refund action may be enabled only after its approved policy, role permission, channel-specific idempotency, immutable audit evidence, reconciliation behavior, and tests are present. Refunds must never be simulated through raw ledger edits.

#### 6.2.5 Advance Payments (Negative Ledger Balance)

**Business Rule**: Payments exceeding the current assessed debt simply drive the student's overall ledger balance below zero (a negative balance). The system does not maintain a separate "Student Wallet".

**Workflow**:

1.  **Overpayment Detection**: If a payment exceeds the current debt, the total balance becomes negative (e.g., `₱-2,300.00`).
2.  **Credit Application**: This negative balance acts as a credit. When a new fee is assessed (e.g., next semester's tuition, or a mid-semester Document Request fee), it simply adds to the ledger, automatically offsetting against the negative balance.

**Student-Facing Display (Student Hub)**:
The Student Hub financial view must display the following information to the student:

- **Current Balance**: The net ledger balance. If positive, it represents outstanding debt (e.g., "₱12,500.00"). If negative, it represents a credit balance (e.g., "₱-2,300.00").
- **Payment History**: Chronological list of all ledger entries (assessments, payments, credits, and adjustments).
- **"Pay Now" Button**: Initiates PayMongo checkout or screenshot upload workflow (§6.2.1). Hidden if balance is zero or negative.
- **Promissory Note Status**: Active / Expired / None (read-only, not finance-cleared)
- **Exam Permit Access**: Visual indicator showing whether the exam permit is accessible based on §6.2.3 rules

---

### 6.3 Real-Time Ledger Synchronization (Atomic Rules)

- **Regulatory Boundary**: TALA acts as a subsidiary ledger for student accounts. It is not a BIR-accredited electronic invoicing or official receipting system. Generated SOA and payment-acknowledgement documents are internal tracking artifacts, not official BIR receipts.
- **Immutable Records**: All financial entries (assessments, payments, credits, and accounting adjustments) are **Write-Once**. Errors are corrected via typed Accounting adjustments and reversal transactions, never via deletion or direct ledger editing.
- **Correction Workflow**: Accounting/Cashier may post only three approved correction types: student-account debit, student-account credit, and ledger-entry reversal. Each correction requires authorization, a selected student, a reason, a non-future posting date, and optional evidence reference. Term/enrollment/source ledger selections must be scoped to the selected student.
- **Reversal Rule**: A ledger-entry reversal posts the exact opposite of the selected source ledger entry and blocks duplicate reversal of the same source entry. It does not delete, edit, or hide the original record.
- **Handover Boundary**: Accounting adjustments update the student's running balance and future financial status calculations, but they do not silently undo an already completed finance-cleared account handover, remove the student role, or change `Enrolled` status. Any enrollment/access rollback requires a separate approved workflow.
- **State Synchronization**: If a student pays at 10:00 AM and the Cashier confirms at 10:05 AM, the Student Hub and Faculty Class Lists reflect the "Paid" status at 10:06 AM

---

## 7. Module 4: Faculty Module

### 7.1 Class Management

#### 7.1.1 Digital Class List

- **Source**: Generated from canonical officially enrolled subject assignments and the committed/published section schedule. Payment may be an upstream enrollment gate, but Faculty does not independently evaluate or display a student's account standing.
- **Update Frequency**: Near real time after an authorized enrollment, subject, section, drop, or published-assignment change commits.
- **Late Enrollees**: Marked with a "New" badge for 3 days to alert Faculty
- **Finance Privacy**: Faculty class lists expose no balance, payment status, payment reference, promissory status, financial hold, or finance-derived attendance/exam/grade restriction. Accounting owns collection and clearance evidence. A student appears because the canonical enrollment-subject assignment is active, not because Faculty can inspect the financial reason behind it.

**Faculty Class List Surface Boundary**: Faculty Class Lists are not generic `enrollment_subjects` CRUD. Class-list rows are generated by enrollment/scheduling processes and exposed to Faculty as assigned list/view rows with role-scoped grade actions. TALA must not expose generic create/edit forms for raw `enrollment_id`, `subject_id`, `section_meeting_id`, `status`, `is_dropped`, or `dropped_at` mutation from the Faculty Class List screen. Enrollment-subject drops, transfers, or section assignment corrections require the appropriate Registrar/enrollment workflow rather than direct Faculty-side row editing.

**Student Information Update Indicators (Faculty-Facing)**:
When a student in the faculty's class list updates their personal or academic information, the faculty receives an **in-app notification** and the student's row in the class list displays a **"Recently Updated" badge** for 48 hours. This keeps faculty records aligned without manual follow-ups.

**Monitored Fields** (fields that trigger faculty notification when changed):

| Category       | Fields That Trigger Notification               | Why Faculty Needs to Know                    |
| -------------- | ---------------------------------------------- | -------------------------------------------- |
| **Contact**    | Contact Number, Home Address                   | For attendance follow-ups, emergency contact |
| **Modality**   | Learning Mode changed (e.g., On-Site → Online) | Affects attendance expectations, scheduling  |
| **Guardian**   | Guardian Name or Contact changed               | For minor students, parent communication     |
| **Enrollment** | Section transfer, dropped & re-enrolled        | Class list accuracy, grade record continuity |

**Notification Behavior**:

- Faculty receives an **in-app notification**: "Student [Name] in your [Section] section updated their [field category]. View changes in class list."
- The student's row in the class list shows a small blue **"Updated"** badge next to their name for 48 hours
- Faculty can click the badge to see a **diff view** showing old value → new value
- After 48 hours, the badge auto-dismisses. Notification remains in the Faculty Notification Center for 30 days

**Constraint**: Faculty see ONLY the fields relevant to their teaching context. They do NOT see financial changes, discount details, or other sensitive data.

#### 7.1.2 View Teaching Schedule

- Faculty can view their assigned classes: Subject, Day, Time, Room
- Faculty can submit pre-scheduling availability for a target term only during the Registrar-opened submission period
- Faculty availability changes after submission/deadline must be filed as formal change requests with a reason; faculty cannot directly modify locked availability
- **Source**: Official committed schedules generated by Registrar Module (Scheduling)

#### 7.1.3 Public Admission Requirements Portal & Faculty Sharing Link

**Purpose**: Addresses Story F1. Provides a clear, official admission requirements page accessible to students, enabling faculty to spend less time answering repetitive inquiries. Faculty can quickly share the link rather than manually looking up requirements.

**Location**:

- **Public**: Accessible via the Public Landing Page (`/admission-requirements`)
- **Faculty Dashboard**: A "Share Requirements" quick-action widget providing one-click copyable links.

**Public Portal Content** (auto-synced from system settings):

- **Document Requirements — SHS**: Required documents for New Grade 11 and Transferee Grade 12.
- **Document Requirements — College**: Required documents for New and Transferee college students.
- **Modality Options**: 3 learning modes (Modular, Online, On-Site) with department restrictions and brief descriptions.
- **Enrollment Steps Overview**: High-level 7-step pipeline.
- **FAQ Links**: Quick links to relevant FAQ entries.

**Configuration Rule**: Admission requirements are configurable school rules, not hardcoded component text. The final-form workflow uses Registrar-owned versioned setup for offerings, requirement policies, document items, gate/retention classification, evidence methods, and publication state. System Super Admin may maintain the underlying system only; ordinary requirement policy ownership stays with Registrar/authorized academic operations. During transitional builds, seeded or internal configuration may stand in for the typed setup UI, but the submitted baseline remains the versioned Registrar workflow.

**Faculty Workflow**:

- Faculty do not maintain or view a redundant internal reference page.
- Instead, the Faculty Dashboard features a "Quick Links" widget.
- Clicking "Copy SHS Requirements Link" copies `tala.edu.ph/admission-requirements#shs` to the clipboard.
- Faculty sends this link to the inquiring student.

**Access**: Public page is available 24/7. Faculty widget is available year-round.

#### 7.1.4 Faculty Academic Advising Status (Student Advising)

**Purpose**: Addresses Story F7. Provides faculty with read-only access to a system-computed advising status for consultation and follow-up purposes. This is an **advisory signal only** — it does not trigger sanctions, enrollment blocks, financial holds, or automatic parent notifications.

**Access Point**: "View Advising Status" action button on each student row in the faculty class list (opens a focused advising modal).

**Term Rule**: When opened from a class list, the advising modal uses the viewed class/section term. If no viewed term is available, it uses the configured active term for the student's education level. If neither exists, the modal shows **Not Available** instead of using the latest historical term.

**Visible Information** (Read-Only):

- **Enrollment Status**: Regular / Irregular / Transferee / Returnee.
- **Academic Advising Status**: System-computed label based on current-term grades:

| Status            | Condition                                                                  | Badge Color |
| ----------------- | -------------------------------------------------------------------------- | ----------- |
| **Not Available** | No current encoded grades yet and no active INC                            | Gray        |
| **Good**          | At least one current grade exists, with no risk trigger                    | Green       |
| **Watch**         | Exactly one current subject has a low-pass grade (75-79)                   | Amber       |
| **Priority**      | Any active INC, any failed grade, or two or more low-pass subjects (75-79) | Red         |

- **Status Reasons**: Brief explanation of why the student is flagged (e.g., "INC in MATH101", "Low-pass in ENG201 (77)").
- **Current Term Subjects**: List of enrolled subjects with section assignments.
- **Prerequisite Status**: Passed / Failed / Incomplete for prerequisites of current subjects.
- **Year/Grade Level**: Current year level and program/strand.
- **Modality**: Learning Mode context for attendance expectations.
- **Enrollment History**: Terms enrolled and sections previously assigned.

**Explicitly Hidden from Faculty** (privacy-protected):

- ❌ Financial balances, payment history, transaction details, discounts, or promissory notes.
- ❌ Sensitive personal data (LRN, birthdate, guardian contact).
- ❌ Computed GPA.

**Grade Interpretation for Advising Status**:

- **SHS**: Low-pass = transmuted grade 75–79; Fail = transmuted grade < 75; Active INC → Priority.
- **College**: Low-pass = raw percentage 75–79 (or finalized equivalent > 3.00); Fail = raw < 75.
- Uses latest encoded current-term grades, **including unfinalized grades**, because the purpose is early advising.

**Consequences**: None. This status exists solely to help faculty prioritize advising conversations. No automated actions are triggered by any status value.

**Interaction**:

- Modal opens with read-only Infolist display.
- Data cannot be exported or printed.
- Closed via single click or Escape key.

---

### 7.2 Grading Ecosystem (Automated Calculation)

**Efficiency Strategy**: To balance simplicity and accuracy, the system uses **Period-Level Entry**. Faculty enters a single computed grade per grading period. Component-level computation (Written Work, Performance Tasks, Quarterly Assessment with DepEd weights) is performed offline by the faculty; TALA stores only the resulting grade per period.

#### 7.2.1 Step 1: Program-Specific Grading Logic

The system automatically applies different calculation engines based on the Student's Department (determined by their enrolled program):

**Senior High School (DepEd Aligned)**:

- **Input**: Faculty enters the **transmuted grade** (60-100) for exactly two SHS active-semester quarters: Q1 and Q2. The transmuted grade is the DepEd-converted value from the Initial Grade (see DepEd Order No. 8, s. 2015 Transmutation Table). The minimum transmuted grade is **60** (not zero); the minimum **passing** transmuted grade is **75**. The faculty computes the weighted average of Written Work, Performance Tasks, and Quarterly Assessment offline using DepEd-prescribed weights per subject type (Core, Academic Track, TVL — see Appendix A).
- **System Logic**: Averages Q1 and Q2 to produce the final subject grade. Missing, blank, duplicated, null, or unexpected quarter entries block calculation with a validation message. The system must not average incomplete SHS grades.
- **Engine Selection**: The system reads the student's `program.department` to confirm SHS, and the `subject.subject_type` to determine which DepEd weight profile applies (for reference/validation purposes).
- **Faculty UI**: The grade encoding modal shows only SHS Q1/Q2 transmuted-grade fields for SHS students. It must not show College Prelim/Midterm/Final fields for SHS records.

**College (Zero-Based)**:

- **Profile Resolution**: The system resolves one published effective-dated College grading profile by term, with optional program, subject, and course-delivery scope. A missing or ambiguous profile blocks a new grade sheet; it never falls back silently.
- **Input and Calculation**: Period names, accepted score ranges, weights, rounding, transmutation bands, remarks, and passing rules come from the resolved profile. The grade sheet snapshots the profile version and calculation inputs so later policy changes cannot rewrite historical results.
- **Faculty UI**: The encoding modal renders only the periods required by the snapshotted profile. A Prelim/Midterm/Final `30/30/40` profile is treated as a deployment-specific grading profile, not an approved universal College policy. It remains unchanged until the institution selects the active profile and approves any migration boundary.

#### 7.2.2 Step 2: INC (Incomplete) Lifecycle

- **Action**: Faculty selects "INC" status
- **Time-Bound Rule**: The system starts a **365-day countdown** from the end of the term
- **Auto-Fail**: On day 365, a nightly batch job automatically converts "INC" → "5.0 / Failed" to ensure no grades are left in limbo indefinitely
- **Prerequisite Block**: While the student has 365 days to clear the INC, they are completely blocked from advancing in that specific subject chain. An INC prevents enrollment in any advanced subjects that require it as a prerequisite.

#### 7.2.3 Step 3: Grade Submission, Registrar Verification, and Finalization

1. **Draft**: Assigned Faculty encodes grades only for the published class assignment and active grading window. Draft values are not official student-record grades.
2. **Submit**: Faculty submits the complete section/subject/period package. Submission snapshots the roster, grading-profile version, grade values, calculation results, submitter, and time, then locks Faculty editing.
3. **Registrar Verify or Return**: Registrar compares the submitted package with the required signed/source evidence and checks completeness, roster identity, permitted values, and calculation/profile consistency. Registrar either returns the entire package with a reason and affected rows, or verifies it. Return reopens only the controlled draft revision path and preserves the rejected submission snapshot.
4. **Finalize**: Registrar verification atomically finalizes the verified package into official academic history. Only verified/finalized grades are released to the Student Hub, prerequisite/progression services, transcripts, report cards, or other official documents.
5. **After Finalization**: No role edits the official row directly. An official change uses the post-finalization correction workflow, preserving old/new values, reason, evidence, Academic Head decision, Registrar application, and superseded history.

**Authority Rule**: Faculty submits; Registrar verifies/returns and finalizes the official record; Academic Head approves exceptional post-finalization changes or an explicitly authorized emergency override. System Super Admin and Accounting have no academic mutation authority.



#### 7.2.4 Step 4: Grade Upload (Downloadable Template)

- **Efficiency**: Faculty can download a pre-populated Excel template for their specific section (containing Student IDs and Names)
- **Validation**: Upon upload, the system cross-checks the Student IDs against the official class list to prevent data corruption

#### 7.2.5 Student-Initiated Grade Correction (Appeals)

**Purpose**: Addresses Story S10. Provides a transparent, trackable process for students to request grade reviews and ensures concerns are resolved transparently and on time based on defined SLAs.

**Access Point**: "Request Grade Correction" button on the Student Hub Grade view (per subject/assessment).

**Constraint**: This form requires an active internet connection. The UI will gracefully disable the "Submit" button using Livewire's `wire:offline.attr="disabled"` directive when the user loses connection, adhering to the global read-only PWA rule (§4.3).

**Request Form Fields**:

- Subject and Assessment Component (pre-selected).
- Current Grade (auto-filled).
- Desired Correction / Requested Action (text).
- Reason (text, 250 chars max).
- Attachments (optional evidence files: screenshot/photo/PDF of rubric, graded paper, LMS entry, computation proof, or similar academic evidence). Accepted types are `jpg`, `jpeg`, `png`, and `pdf`, with a maximum of 3 files and 5 MB per file.

**Attachment Rule**: Attachments are always optional for issue/concern resolution. Registrar may add review notes or request clarification, but the system must not require a supporting file upload before a concern can be reviewed or resolved.

**Request Lifecycle (Student-Visible Statuses)**:

1. `Submitted` → Student submits the request through Student Hub. No grade value is changed at this stage.
2. `Under Review` → Registrar acknowledges the request, checks completeness, validates attachment safety only when files were provided, confirms the request is tied to an actual enrolled subject/grade, and may coordinate with the assigned Faculty outside the student-visible workflow to verify raw computation.
3. `Resolved` / `Rejected` → Registrar records the final outcome. If the outcome changes an official/finalized grade, **Academic Head** approval is required under the Academic Head Override policy (§7.2.3) before the Registrar can mark the request `Resolved`.

**Role Ownership**:

- **Student** submits and tracks the request only.
- **Registrar** performs review, Faculty coordination when needed, final recording, and official-record custody.
- **Assigned Faculty** provides raw computation clarification only when the Registrar needs verification; this is an internal review note, not a separate student-visible status.
- **Academic Head** approves any correction that changes an official/finalized grade.
- **System Super Admin** has audit/read-only visibility only; **Accounting/Cashier** has no grade-correction role.

**Hardening Requirement**: If the correction resolution changes an official/finalized grade, the Academic Head approval happens inside TALA as an authenticated, audited action before the Registrar can apply the correction. The Academic Head approves or rejects from the Grade Correction queue with a required decision note. The Registrar may enter corrected values through scheme-specific period fields only after approval: College uses Prelim, Midterm, and Final raw scores; SHS uses Quarter 1 and Quarter 2 transmuted grades. TALA derives the stored final grade and remarks through the same grading services used by Faculty grade encoding; the Registrar screen must not manually accept a direct final-grade or remarks override. Registrar-recorded offline/prior approval is not an accepted grade-change workflow.

**Admin Surface Boundary**: Grade Correction is not generic correction-ticket CRUD. Student/API intake or approved backend workflows create the ticket, derive `current_grade`, `user_id`, `creator_id`, initial `status`, and private attachment paths, and preserve the ticket as review evidence. Filament must expose only list/view, filters, and typed lifecycle actions (`Start Review`, `Reject`, `Approve Official Grade Change`, `Reject Official Grade Change`, `Resolve - No Grade Change`, `Resolve - Apply Approved Grade Change`). It must not expose raw create/edit forms for `user_id`, `current_grade`, `attachment_paths`, `status`, `assigned_to`, Academic Head review metadata, `creator_id`, or arbitrary resolved timestamps.

**SLA & Timelines**:

- **Acknowledgement SLA**: Registrar must acknowledge (status `Under Review`) within 3 working days.
- **Resolution SLA**: Final decision within 10 working days of acknowledgement.
- **Escalation**: Automatic escalation to **Academic Head** (with notifications) if SLAs are breached.

**Notifications**:

- **Student**: In-App + Email on status changes (`Submitted`, `Under Review`, `Resolved`/`Rejected`) and Escalation.
- **Staff**: Registrar receives in-app/email review notifications. Faculty is notified only when the Registrar requests raw computation clarification.

**Audit & Security**:

- Full audit log of all status transitions and comments.
- If approved, the actual grade change still strictly follows the **Academic Head Override** policy (§7.2.3), linking the override to the correction ticket ID.

---

## 8. Module 5: Administration & Integration

### 8.1 Database & Architecture

**Centralized Database**: A single source of truth for all modules. Ensures Student_ID #12345 is the same entity in Accounting, Registrar, and Faculty.

**Schema Implementation Reference**: This functional specification remains the source of truth for business workflows, role ownership, deadlines, approvals, locks, and official-record behavior. Table names, columns, indexes, foreign keys, and migration status are maintained in the implementation artifacts below:

- `00_Project_Documents/TALA-Foundation-Migration-Control-Log.md`
- `database/migrations/2026_05_12_055403_add_tala_account_fields_to_users_table.php`
- `database/migrations/2026_05_12_055403_create_academic_foundation_tables.php`
- `database/migrations/2026_05_12_055403_create_scheduling_foundation_tables.php`
- `database/migrations/2026_05_12_055413_create_activity_log_table.php`
- `database/migrations/2026_05_12_055414_add_event_column_to_activity_log_table.php`
- `database/migrations/2026_05_12_055415_add_batch_uuid_column_to_activity_log_table.php`

Future schema changes must be added through new migration files and summarized in the control log instead of duplicating table-by-table schema contracts inside this functional specification.

**Migration Status Boundary**:
- This FS defines functional behavior and references schema control artifacts; it does not maintain live migration counts or pending/applied status.
- Current migration execution must be checked in the target environment with `php artisan migrate:status --no-interaction`.
- Fortify two-factor and passkey schema may exist, but `config/fortify.php` remains the runtime authority for whether those flows are active.

#### 8.1.1 Enrolled Student Roster and External Reporting Export

**Feature**: Registrar-owned read-only roster of students currently `Enrolled` in the selected term.

- **Required Filter**: Term.
- **Optional Filters**: Education level, program/strand, year/grade, section, delivery modality, and student type.
- **Columns/Export Fields**: Student ID, LRN where applicable, complete name, demographics required by the institution, guardian details, prior school, program/strand, year/grade, section, modality, and term.
- **Actions**: View enrollment/student details and export the currently filtered roster as CSV or XLSX. The exported rows and columns must match the active filters and authorized roster fields. There is no create/edit/delete, "Mark as Encoded", or external-status action on this surface.
- **Template Boundary**: MVP provides one generic TALA roster schema in both CSV and XLSX. Separate DepEd LIS, CHED, or institution-specific regulator templates are not generated; staff may use the generic export as authorized source data for external workflows.
- **Authority**: Registrar and explicitly authorized reporting staff only. Export activity is audited because it contains personal student data.
- **External-System Boundary**: Staff decide how and when to use the export in DepEd LIS or another regulator workflow. TALA neither tracks nor asserts completion in that external system.

---

### 8.2 Security & Access Control

#### 8.2.1 Role-Based Access Control (RBAC)

| Role          | Permissions                                                  |
| ------------- | ------------------------------------------------------------ |
| **Cashier**   | Read/Write Payments. NO ACCESS to Grades                     |
| **Faculty**   | Read/Write Grades. NO ACCESS to Financial Balances (Privacy) |
| **Registrar** | Read/Write Records. Read-Only Payments (Cannot Modify)       |
| **System Super Admin** | User Management, Audit Logs, and permission-gated FAQ content maintenance. **Read-Only** for academics/financials; generic runtime settings registry is internal and not exposed as a general-purpose admin surface. |
| **Academic Head**      | **Read-Only** oversight across all domains. Authorize Override for grade/schedule exceptions |

**Policy Registration Requirement**: Role management and audit-log viewing are System Super Admin surfaces only. Because those resources are backed by vendor models (`Spatie\Permission\Models\Role` and `Spatie\Activitylog\Models\Activity`), their policies must be explicitly registered in Laravel so Registrar, Accounting, Faculty, and Academic Head users do not see or access Roles/Audit Logs by accident.

#### 8.2.2 Audit Trail

- **Retention**: Indefinite (Logs are kept forever; storage is cheap)
- **Visibility**: Users CANNOT see their own logs. **System Super Admin** access only
- **Detail Display**: Audit detail screens must present metadata as readable labeled evidence lines. Raw JSON/key-value payload editing or dump-style presentation is not a staff workflow.
- **Alerts**: System flags "Critical Actions" (e.g., Bulk Grade Changes, Historic Balance Clearing) → Alerts **System Super Admin** Dashboard

#### 8.2.3 Resilience & Error Handling

| Scenario             | Fallback                                                            |
| -------------------- | ------------------------------------------------------------------- |
| Google Cloud Vision OCR Failure | Manual Review (Upload Raw Image)                                    |
| Payment Gateway Down | OTC Mode or Manual Screenshot Upload                                |
| System Error         | Friendly error messages ("Service Busy") instead of raw codes (500) |

**Standard User-Facing Message Templates**

All user-facing feedback (toast notifications, banners, and inline messages) must use the standardized templates below. Messages must never expose stack traces, SQL errors, technical codes, or internal class names. The Technical Specification §5.10.3 defines the implementation patterns for each UI surface (Filament Notifications for Admin Nexus, TallStackUI Toast for Student Hub).

| Category | Severity | Title | Message Template | Trigger Context |
| --- | --- | --- | --- | --- |
| Success | `success` | Saved Successfully | "Your changes have been saved." | General CRUD (create, update, delete) |
| Success | `success` | Payment Submitted | "Your payment reference #{ref} has been submitted for confirmation." | Student payment upload or GCash checkout |
| Success | `success` | Document Uploaded | "Your document has been uploaded and is pending review." | Applicant/student file upload |
| Success | `success` | Grades Submitted | "Grades for {section} — {subject} have been submitted." | Faculty grade finalization |
| Validation | `warning` | Missing Required Fields | "Please complete all required fields before submitting." | Form validation failure (generic) |
| Validation | `warning` | Invalid File Format | "Please upload a valid file. Accepted formats: {formats}." | File type validation |
| Blocking | `danger` | Action Not Permitted | "You do not have permission to perform this action." | RBAC denial (403) |
| Blocking | `danger` | System Under Maintenance | "The system is currently undergoing scheduled maintenance. Please try again later." | Application-level maintenance mode active (§8.9) |
| Blocking | `danger` | Financial Hold Active | "Your account has a financial hold. Please visit the Cashier's Office for settlement." | Student attempts action blocked by financial restriction |
| Info | `info` | Processing Request | "Your request is being processed. You will be notified when complete." | Async operations (OCR queue, document generation) |
| Info | `info` | Schedule In Progress | "Schedule generation is in progress. Please check back shortly." | Registrar draft generation running |
| Error | `danger` | Something Went Wrong | "An unexpected error occurred. Please try again. If the issue persists, contact support." | Unhandled server errors (500) |
| Error | `danger` | Service Temporarily Unavailable | "This service is temporarily unavailable. Please try again later." | External service failure (PayMongo, Google Cloud Vision OCR) |
| Error | `danger` | Session Expired | "Your session has expired. Please log in again." | Authentication timeout (419/401) |

**Display Rules**:
- **Position**: All toasts render in the **top-right** corner of the viewport
- **Auto-Dismiss**: `success` and `info` toasts auto-dismiss after **5 seconds**; `danger` toasts persist for **8 seconds**; `warning` toasts persist for **6 seconds**
- **Stacking**: Multiple simultaneous toasts stack vertically with newest on top
- **Validation Errors**: Field-level validation errors appear as **red text below the input field** (not as toasts) per standard Filament/TallStackUI form behavior

---

### 8.3 System Super Admin Functions

#### 8.3.1 Custom COR & Document Templates

The **System Super Admin** COR template editor is an approved target capability for modifying the layout and fields of the Certificate of Registration (COR) to match institutional branding. Changes apply globally for future COR generations only after a dedicated editor is implemented and tested.

**Implementation Scope**: COR verification/control surfaces are part of the admin hardening scope. Generated COR verification tokens are list/view evidence records with controlled lifecycle actions such as supersede and revoke. The system must use a dedicated COR-verification permission rather than unrelated external-reporting permissions. Supersede/revoke actions must call a backend lifecycle service that accepts only valid state transitions, records audit activity, and requires a typed revoke reason before marking a token revoked. Staff must not manually create or edit arbitrary COR tokens, status values, student links, or issue/expiry timestamps through a generic CRUD form. List/detail evidence surfaces must show descriptive student, term, and enrollment labels instead of raw internal foreign-key IDs as primary UI labels. A full COR template/layout editor requires a separate implemented and tested workflow. COR template editing remains a deferred System Super Admin capability until that workflow exists.

#### 8.3.2 Staff Account Creation Boundary

System Super Admin may create and maintain **staff accounts only** for Registrar, Accounting/Cashier, Faculty, Academic Head, and System Super Admin users. The staff-account form captures First Name, Middle Name (optional), Last Name, Suffix (optional), username, email, password, a validated active/inactive status toggle, and exactly one approved staff role from the seeded staff-role set. Student/applicant accounts are created by applicant intake, official handover, or Registrar walk-in intake, not by the generic staff user-management form. Archived status, archive fields, verification timestamps, and audit fields are system-managed and must not be exposed as ordinary creation inputs. Direct staff edit is limited to other non-archived staff accounts. The current System Super Admin cannot edit their own account through this management surface, and archived accounts must move only through the Restore Account action. Archive and Restore Account actions must call the backend staff-account lifecycle service, which validates the System Super Admin policy ability, requires an official archive reason, prevents self-archive and invalid state transitions, clears roles when archived, requires exactly one approved staff role on restore, and records lifecycle activity.

#### 8.3.2.1 RBAC Matrix Boundary

System Super Admin may view the seeded role/permission matrix for audit and verification, but the Filament surface must not expose a role create page/action/route, generic permissions multi-select, or role edit form. Role definitions and permission assignments are code/seeder-owned release artifacts; changes require a reviewed implementation/configuration change and regression tests, not ad hoc admin UI mutation.

#### 8.3.3 HR Management & Account Archiving

**Efficiency Strategy**: Soft-Archive strategy for all staff accounts to maintain clear audit trail.

**Phase 1: Immediate Off-Boarding (Security)**

- **Trigger**: **System Super Admin** clicks "Archive Account" and enters the required official archive reason. Optional HR evidence upload is permitted only through a dedicated private evidence table/file contract; it must not be attached through free-form public paths or generic notes.
- **System Action (Atomic)**:
    1. **Session Flush**: Immediately invalidates all active web sessions for that user (forces logout)
    2. **Role Stripping**: Removes all active Roles/Permissions
    3. **Status Update**: Sets `users.status = 'archived'`
- **Result**: The staff member can no longer access any part of the Admin Nexus

**Phase 2: Historical Integrity (Audit)**

- **Immutable History**: The system **never deletes** the user record
- **Read-Only Preservation**: Historical records (e.g., "Grades encoded by Sir John," "Payments confirmed by Ms. Jane") remain perfectly intact. The user's name will still appear on PDF exports and dashboards, but their account is flagged as `[Inactive]`

**Phase 3: Re-Hiring Logic**

- If a former staff member returns, the **System Super Admin** can "Restore Account." The system preserves their historical link while assigning a fresh set of roles

**Staff Walk-In Access**:

- A dedicated "Walk-In" session flag exists for staff who need to assist students physically present at the office. This is logged as `Source: Staff_Assisted` in the audit logs

---

### 8.4 Administrative Dashboard (Overview Stats)

**Update Frequency**: Real-Time



**Features**:

- **Filtering**: Date Range (This Term vs Last Term)
- **History**: Enrollment Trends (Year-over-Year comparison)

**Key Metrics**:

| Category       | Metrics                                   |
| -------------- | ----------------------------------------- |
| **Enrollment** | Enrolled / Pending / Dropped / Transferee |
| **Financial**  | Revenue / Outstanding / Collection Rate   |
| **Academic**   | Pass/Fail Rates per Subject/Teacher       |

---

### 8.4.1 Grade Submission Progress Widget (Administrative Dashboard)

**Purpose**: Proactive monitoring of faculty grade submission progress BEFORE the deadline, enabling the **Academic Head** to identify non-compliant faculty and send reminders.

**Location**: Administrative Dashboard (top section, above overview stats)

**Deadline Countdown Banner** (shown during encoding period):

- Displays: "⏰ Grade Encoding Deadline: **Dec 12, 2025 11:59 PM** — **X days, Y hours remaining**"
- Color coding: Green (> one week), Yellow (3 days to one week), Red (< 3 days), Black (expired)
- Deadline source: `settings.grade_encoding_deadline`, stored as an ISO 8601 datetime with timezone. If not configured, the widget shows "Not configured" and no faculty is marked overdue.

**Summary Stats Bar**:

| Metric            | Description                                            |
| ----------------- | ------------------------------------------------------ |
| **Total Faculty** | Count of faculty with assigned sections this term      |
| **Submitted**     | Faculty who finalized ALL grades (green)               |
| **In Progress**   | Faculty who started encoding but not finalized (amber) |
| **Not Started**   | Faculty with no grade records yet (gray)               |
| **Overdue**       | Faculty past deadline with incomplete grades (red)     |

**Detail Table** (expandable per faculty):

| Column                | Description                                             | Display           |
| --------------------- | ------------------------------------------------------- | ----------------- |
| **Faculty**           | Teacher name (linked to profile)                        | Text              |
| **Section**           | Assigned section (e.g., "Grade 11-A")                   | Text              |
| **Subject**           | Subject code + description (e.g., "MATH101 — Calculus") | Text              |
| **Enrolled Students** | Count of students in this section                       | Number            |
| **Grades Finalized**  | "X / Y" (e.g., "18 / 25")                               | Progress fraction |
| **Completion %**      | Percentage of grades finalized                          | Progress bar      |
| **Status**            | Submission state                                        | Badge (see below) |

**Status Badge Values**:

| Badge           | Condition                                       | Color |
| --------------- | ----------------------------------------------- | ----- |
| **Submitted**   | 100% grades finalized BEFORE deadline           | Green |
| **In Progress** | 1-99% grades finalized, deadline not yet passed | Amber |
| **Not Started** | 0% grades finalized, deadline not yet passed    | Gray  |
| **Overdue**     | Deadline passed, < 100% grades finalized        | Red   |

**Bulk Actions**:

- **Send Reminder**: Select one or more faculty → System sends email + in-app notification: "Reminder: Grade encoding deadline is [date]. Please finalize your grades for [section/subject]."
- **Export Report**: Download Excel (.xlsx) of submission status for all faculty

**Filtering**:

- Filter by Status (All / Submitted / In Progress / Not Started / Overdue)
- Filter by Department (SHS / College)
- Filter by Faculty name (search)

**Auto-Refresh**: Widget refreshes every 60 seconds during encoding period.

---

### 8.5 System Configuration & Flexibility (Control Panel)

**Concept**: T.A.L.A. is "State-Aware" but **Configuration-Driven**. The Rules are fixed, but the Variables are adjustable.

**Global Settings (Filament Staff Panel → Settings)**:

| Setting            | Description                                                               |
| ------------------ | ------------------------------------------------------------------------- |
| **Calendar**       | Set Start/End of Semester. (System uses this to auto-lock features)       |
| **Curriculum**     | Manage Programs, Strands, and Courses (Add/Edit dynamic options)          |
| **Event Triggers** | Set Enrollment Period (July 11-31) and Dropping Period dates              |
| **Deadlines**      | Set Grade Encoding Cutoff (e.g., Dec 12, 11:59 PM)                        |
| **Financial**      | Update cost per unit, lab fees, downpayment percentages on fee templates |

**Term Management**:

- **New Term**: Created Manually (e.g., "1st Sem 2026-2027")
- **Required Dates**: Registrar must configure `term_start_date`, `term_end_date`, and `scheduling_starts_at` before faculty availability or schedule generation unlocks
- **Carry-Over Logic**: Student Profile/Balances carry over; Enrollment Status resets to "Not Enrolled"
- **Co-Existence**: Supports overlapping terms (e.g., Finishing Term 1 while Term 2 enrolls)

---

### 8.6 Email Notifications (Account-Related Only)

**Policies**:

- **Delivery**: System Retries bounced emails. If permanent fail, flags account "Invalid Email"
- **Backup**: In-App Notification Center mirrors all emails
- **Opt-Out**: No. Account updates are mandatory
- **Customization**:
    - Language: English Only
    - Branding: Templates include School Logo and Colors (**System Super Admin** Editable Text)

**Consolidated General System Notification Implementation**:
All notifications listed below are dispatched using a single, unified `GeneralSystemNotification` class that accepts a `type`, `subject`, and `body`. This prevents class fragmentation and standardizes the notification delivery architecture.

**Triggers**:

| Module   | Action                                          | Subject                                                             | Trigger Condition                                                                        |
| -------- | ----------------------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Module 1 | Applicant Account Created                       | "Welcome to T.A.L.A."                                               | Temporary account created                                                                |
| Module 1 | Document Rejected                               | "Action Required: Re-upload document"                               | Registrar rejects upload                                                                 |
| Module 1 | Account Upgraded to Student                     | "You are officially enrolled"                                       | Physical docs + payment confirmed                                                        |
| Module 3 | Payment Confirmed                               | "Payment Received - Ref #12345"                                     | Cashier confirms payment                                                                 |
| Module 4 | Grades Finalized                                | "Your grades are posted"                                            | Faculty finalizes grade sheet                                                            |
| Module 5 | Password Reset                                  | "Security Alert: Password Changed"                                  | User resets password                                                                     |
| Module 3 | Financial Hold Applied                      | "Action Required: Minimum downpayment required"                    | Balance > 0 and minimum downpayment not met                |
| Module 3 | Payment Follow-up Required                  | "Your balance remains due. You may still take scheduled examinations; contact Accounting for settlement options." | Student with a balance views exam-period account guidance                                |
| Module 3 | Exam Access Accommodation Approved          | "Exam access accommodation approved"                                | Accounting approves RA 11984 or institution-discretion accommodation evidence            |
| Module 1 | Physical Documents Overdue                  | "Required physical documents are overdue — contact the Registrar"  | One or more required physical items pass their effective deadline unresolved             |
| Module 1 | Temporary Enrollment Cancelled              | "Enrollment not completed — required physical documents were not submitted" | Registrar cancels the temporary enrollment after the unresolved physical-document deadline |
| Module 3 | Promissory Note Activated                   | "Promissory Note recorded — payment still required"                | Accounting/Cashier approves promissory note; no finance clearance is granted             |
| Module 3 | Promissory Note Expiring Soon               | "Warning: Promissory Note expires in 3 days"                        | 3 days before promissory note expiry                                                     |
| Module 3 | Promissory Note Expired                     | "Promissory Note expired — payment still required"                 | Promissory note expires without payment                                                  |
| Module 3 | Account Unrestricted                        | "Account cleared — Full access restored"                            | Balance <= 0 (payment clears existing hold)                                              |
| Module 6 | Document Shipped                            | "Your [Document Type] has been shipped via [Courier] - Tracking: [Tracking]" | Registrar marks a delivery request as shipped and records courier details                |
| Module 7 | Student Info Updated (Faculty Notification) | "Student [Name] in your [Section] section updated their [field]"    | Student modifies contact, modality, guardian, or enrollment info (monitored fields only) |

---

### 8.7 Frequently Asked Questions & Support

The system includes an FAQ/help content module accessible via the public landing page and Student Hub. FAQ entries are maintained through a permission-gated Admin Nexus CRUD surface so support content is not hardcoded. Public users and students can read published guidance before submitting an inquiry. This feature reduces support load while ensuring consistent guidance.

**FAQ Flow (Current Approved Behavior)**:
- **Step 1 (Authoring)**: System Super Admin users with `manage-faqs` create and update FAQ entries through the Admin Nexus FAQ Entries resource.
- **Step 2 (Publish Control)**: The same guarded admin surface owns the publish toggle, category selection, and sort order.
- **Step 3 (Public Consumption)**: Public users and students can read/search only published entries from the landing-page FAQ and Student Hub Help link.
- **Step 4 (Escalation)**: If the answer is not in FAQ, the user proceeds to the applicable module workflow (e.g., enrollment, finance, registrar request).

**Approved FAQ Categories**:

- General
- Admission / Enrollment
- Payments / Fees
- Documents / Requests
- Grades / Academics
- Account / Login
- Technical Support

**Role Boundary**:
- System Super Admin may create, edit, publish/unpublish, sort, and delete FAQ entries only through the `manage-faqs` policy.
- Registrar, Accounting, Faculty, Academic Head, Students, and Public users are **read-only** for FAQ content.
- No separate "FAQ manager" role is required in the approved role model.

**Implementation Scope**: Admin FAQ CRUD is restored as a System Super Admin content-maintenance surface guarded by `manage-faqs`. The public `/faq` route and Student Hub Help route read only published FAQ entries; `/faq` is guest-accessible and read-only, while Student Hub Help is protected by authenticated active-student access. Backend contracts for applicant intake, student enrollment, subject suggestion, and student dashboard aggregation provide the data for the Student Hub UI.

---

### 8.8 Data Migration & Provisioning (Hybrid Seed & Claim)

**Purpose**: Seamlessly onboard continuing students (non-freshmen) and their historical data from the legacy 'SIA' system without creating a massive operational bottleneck.

**Strategy**: T.A.L.A. uses a "Hybrid Seed & Claim" workflow to balance security with scalability.

**Phase 1: The Seed (Registrar-Initiated Bulk Import)**

- The Registrar performs a bulk Excel (.xlsx) import of the legacy masterlist.
- **Required Fields**: `LRN`, `First_Name`, `Last_Name`, `Legacy_Financial_Balance` (from Accounting).
- **System Action**: Creates "Skeleton Accounts" (Status: `Unclaimed`). No passwords are generated. Financial balances are posted as `Legacy Balance Forward`.

**Phase 2: The Claim (Self-Service + OCR Verification)**

- The continuing student visits the "Claim Account" portal and inputs their `LRN`.
- **The Proof**: The student must upload a photo of their previous Report Card.
- **Automated Extraction (Google Cloud Vision OCR)**: Google Cloud Vision OCR extracts raw text containing the student's past subjects and grades.
- **OCR-Assisted Match**: The system compares extracted Name/LRN text patterns against the seeded Skeleton Account. OCR is used for routing and prefill only; it is not final identity authority.
- **Result**: If the OCR quality check passes and identity signals match, the student is allowed to set a password. The account becomes `Active`, and the extracted academic history is pushed to a Registrar review queue.
- **Manual Review Route**: If OCR confidence is low, text is incomplete, or identity signals conflict, the claim is routed to Registrar manual review. The student receives a "manual review required" message and cannot activate the account until approval.
- **Lockout Rule**: Uses standard Laravel RateLimiter (e.g., 5 attempts per minute per IP) to prevent brute force. Custom complex mismatch lockout logic is removed.
- **Record Rule**: Extracted academic history is not official until the Registrar verifies and promotes it into the student's structured academic record.

---

### 8.9 System Maintenance Mode

The system leverages **Laravel's Built-In Maintenance Mode** to handle downtime securely and efficiently, without maintaining a custom, secondary database-driven maintenance layer.

#### 8.9.1 Built-in Maintenance (CLI)

- **Mechanism**: Laravel's built-in `php artisan down` command.
- **Activation**: **System Super Admin** executes via server CLI during infrastructure updates (database migrations, server patches, dependency upgrades). `php artisan down --secret={token}` allows bypass.
- **Behavior**: All web traffic (except those with the bypass token) receives a 503 Service Unavailable HTTP response. Filament naturally supports customized 503 pages.
- **Deactivation**: `php artisan up`

#### 8.9.2 In-Flight Transaction Protection

- **Payment Webhooks**: To ensure payments are not lost during maintenance, the PayMongo webhook endpoints in `routes/api.php` are explicitly excluded from the `PreventRequestsDuringMaintenance` middleware.
- **Queue Impact**: Active queued jobs will complete, but no new jobs are dispatched by user requests since the system blocks HTTP traffic.

---

### 8.10 Bulk Data Import Framework

All legacy data imports — including the student seed described in §8.8 — must follow a strict, system-generated Excel template approach with a mandatory preview/validation step before data is committed to official records. This framework standardizes how historical data migrates from the legacy SIA system into T.A.L.A. while preventing corruption of official academic, financial, and enrollment records.

#### 8.10.1 Policy

- **Strict Templates Only**: Staff must download the official `.xlsx` template from the system before filling it. The template contains locked headers that define the exact column structure. Freeform uploads with arbitrary column layouts are rejected.
- **No Blind Import**: Every import passes through a mandatory **Preview & Validation** screen where staff review parsed data before committing. There is no "skip preview" shortcut.
- **Non-Destructive**: Imports never overwrite existing records. If a duplicate is detected (e.g., same LRN + Subject + Term already has a grade), the row is flagged as a warning and skipped.
- **Immutable Source Tagging**: Every imported record carries `source: legacy_import`, a unique `import_batch_id`, and the `imported_by` staff reference for full audit traceability.
- **Curriculum Import**: The existing Curriculum Import workflow (§5.1.1 / Tech Spec §3.17) should also adopt this preview/validation pipeline for consistency. When a curriculum template is uploaded, it passes through the same 3-phase process before creating or modifying curriculum subject mappings.

#### 8.10.2 Role Authorization

| Import Type | Authorized Roles | Rationale |
| --- | --- | --- |
| Student Data (Skeleton Accounts) | Registrar | Registrar owns student enrollment records |
| Legacy Grades | Registrar | Registrar is the custodian of academic records |
| Legacy Financial Records | Accounting | Accounting owns the financial ledger |
| Enrollment History | Registrar | Registrar owns section/term assignment records |

**System Super Admin Boundary**: System Super Admin may view import audit logs and maintain system infrastructure, but cannot upload, preview, commit, or approve academic/enrollment/financial import templates. The role remains read-only for academic and financial operations.

**Import Requirement**: The curriculum/foundation import path must satisfy the controlled import boundary: staff download the system template, upload a private CSV/XLSX file, receive a strict parse/validation preview, and can commit only zero-error curriculum batches. Commit re-validates the stored source, writes Programs, Subjects, Curricula, and Curriculum Subjects, and records audit activity. Generic create/edit routes, raw file-path/error-log forms, and freeform in-browser spreadsheet repair remain forbidden. Student data, legacy grades, legacy financial records, and enrollment-history imports are not automatically covered by the curriculum importer and require separate controlled services before they can be claimed as system acceptance-ready.

#### 8.10.3 Template Definitions

Each template defines the minimum required columns. Templates are downloadable as `.xlsx` files from the Filament panel via a "Download Template" button on each import page.

**Template A — Student Data** (extends §8.8 Hybrid Seed)

| Column | Required | Validation | Notes |
| --- | --- | --- | --- |
| `LRN` | Yes | 12-digit unique string | Universal student identifier |
| `Last_Name` | Yes | max 100 characters | |
| `First_Name` | Yes | max 100 characters | |
| `Middle_Name` | No | max 100 characters | |
| `Email` | No | valid email, unique | For account claim notification |
| `Contact_Number` | No | PH mobile format | |
| `Education_Level` | Yes | `shs` or `college` | Determines grading engine |
| `Program_Code` | Conditional | must exist in programs table | Required for College students |
| `Year_Level` | Yes | e.g., `Grade 11`, `1st Year` | |
| `Legacy_Financial_Balance` | No | decimal, default 0.00 | Posted as `Legacy Balance Forward` |

**Template B — Legacy Grades**

| Column | Required | Validation | Notes |
| --- | --- | --- | --- |
| `LRN` | Yes | must match `student_profiles.lrn` for an existing student | |
| `School_Year` | Yes | e.g., `2024-2025` | |
| `Term` | Yes | e.g., `1st Semester`, `Q1` | |
| `Subject_Code` | Yes | must exist in subjects table | |
| `Raw_Score` | Conditional | numeric 0–100 | Required for College; not accepted for SHS rows |
| `Transmuted_Grade` | Conditional | 60–100 range | Required for SHS per DepEd Order No. 8; not accepted for College rows |
| `Remarks` | No | `Passed`, `Failed`, `INC` | Defaults to computed result |
| `Education_Level` | Yes | `shs` or `college` | Determines validation rules |

Imported grades are automatically marked `status: finalized` and `is_legacy: true`. They bypass the normal faculty submission workflow.

**Template C — Legacy Financial Records**

| Column | Required | Validation | Notes |
| --- | --- | --- | --- |
| `LRN` | Yes | must match `student_profiles.lrn` for an existing student | |
| `School_Year` | Yes | e.g., `2024-2025` | |
| `Description` | Yes | e.g., `Tuition Balance`, `Payment` | |
| `Transaction_Type` | Yes | `assessment` or `payment` | Must match TS §2.5.3 canonical enum |
| `Amount` | Yes | positive decimal | |
| `Reference_Number` | No | legacy receipt or OR number | |
| `Transaction_Date` | Yes | `YYYY-MM-DD` format | |

Each row creates an immutable ledger entry tagged `source: legacy_import`. The Atomic Ledger principle (§6.1) applies — imported entries cannot be edited, only reversed.

**Template D — Enrollment Records**

| Column | Required | Validation | Notes |
| --- | --- | --- | --- |
| `LRN` | Yes | must match `student_profiles.lrn` for an existing student | |
| `School_Year` | Yes | e.g., `2024-2025` | |
| `Term` | Yes | e.g., `1st Semester` | |
| `Section_Name` | Yes | e.g., `BSIT-1A` | |
| `Program_Code` | Yes | must exist in programs table | |
| `Year_Level` | Yes | e.g., `1st Year` | |
| `Enrollment_Status` | Yes | `completed`, `dropped`, `incomplete` | |

Imported enrollment records are tagged `source: legacy_import` and do not trigger the enrollment state machine or financial workflows.

#### 8.10.4 Mandatory 3-Phase Import Pipeline

**Phase 1 — Upload & Parse**

1. Staff navigates to the import page in the Admin Nexus and selects the import type
2. Staff clicks "Download Template" to obtain the official `.xlsx` template with locked headers
3. Staff fills the template, uploads it, and clicks "Parse & Validate"
4. System checks: file format (`.xlsx` only), header match against the strict template, and file size limit
5. If headers don't match → immediate rejection: "Template mismatch. Please download the official template."

**Header Rule**: Header order and names must match the official template exactly after trimming whitespace. Blank trailing columns are ignored, but hidden or extra non-empty columns, renamed headers, translated headers, and formula-generated headers are rejected.

**Phase 2 — Preview & Validation**

1. System parses all rows and applies row-level validation:
    - Required field presence
    - Data type conformity (numbers, dates, enums)
    - Foreign key existence (LRN exists? Subject code exists? Program code exists?)
    - Business rule checks (SHS grade in 60–100? Amount is positive? Date is valid?)
    - Duplicate detection (same LRN + Subject + Term = grade already exists?)
2. A **Preview Table** is displayed with color-coded row status:
    - ✅ **Valid** (green) — ready to import
    - ⚠️ **Warning** (amber) — duplicate detected or non-critical issue; will be skipped on commit
    - ❌ **Error** (red) — validation failure; must be fixed in the source file
3. A **Summary Banner** shows: "{total} rows parsed. {valid} valid. {warnings} warnings. {errors} errors."
4. Staff can download an **Error Report** (`.xlsx`) listing all failed rows with error descriptions for correction
5. Staff clicks "Confirm Import" to proceed, or "Cancel" to discard

**Phase 3 — Commit**

1. All valid rows are inserted inside a `DB::transaction()` block
2. Each record is tagged with:
    - `source: 'legacy_import'`
    - `import_batch_id`: a unique UUID linking all records from this upload
    - `imported_by`: the authenticated staff member's user ID
    - `imported_at`: the current timestamp
3. An audit log entry records the import event: import type, file name, row counts (valid/skipped/rejected), and the responsible staff member
4. Staff sees a Filament success notification: "{valid} records imported. {skipped} skipped. {errors} rejected."

#### 8.10.5 Technical Implementation Reference

The implementation details for this framework — including the `import_batches` tracking table, `DataImportService` base class, Laravel Excel import/export classes, and Filament import pages — are defined in Technical Specification §3.20.

---

## 9. Module 6: Service Requests & Documents

### 9.1 Document Request Portal

The student portal allows official students to request documents from a fixed approved catalog directly from their dashboard.

**Approved Document Request Types**:

- Certificate of Registration
- Certificate of Enrollment
- Certificate of Good Moral Character
- Transcript of Records
- Form 137
- Form 138
- Diploma
- Other

**Workflow Logic**:

- **Document Catalog Ownership**: Registrar manages metadata, requirements, processing notes, and availability for the approved document request types. Accounting manages free/paid classification and fee amounts for the same approved catalog. Adding a new selectable type requires a future approved specification update.
- **Official Issuance Rule**: A fulfilled academic document is generated or released from authoritative student-record, enrollment, grade, curriculum, ledger, or document-request data. The file or paper copy is evidence of an issuance, not the operational source of truth.
- **Issuance Snapshot**: Goal-state fulfilled records preserve document type, subject/student, term/request context, source snapshot or reproducibility reference, template version, issuer, issued time, reference/serial when applicable, checksum, lifecycle state, and release evidence.
- **Eligibility and Hold Rule**: Missing eligibility, unresolved document-release holds, unfinalized grades for grade-bearing outputs, invalid enrollment, unpaid request/shipping fee where applicable, or unauthorized requester blocks release with an explicit reason.
- **Release Evidence**: Pickup or representative release must capture claimant identity, representative authority where applicable, releasing staff, release time, and acknowledgement. Courier release records consent, courier, tracking or `N/A`, shipping fee, and private receipt proof.
- **School-Record Transfer Boundary**: Form 137/SF10 and Form 138/SF9 handling follows school-record confidentiality and receiving/originating-school request practice. TALA records request, eligibility, release, and evidence; it does not replace DepEd LIS or a receiving school's official portal process.
- **Activation Rule**: Newly created document types are not requestable by students until Accounting marks them as `free` or assigns a positive document fee.
- **Free Request Rule**: Only the student's first Form 137 request and first Grade 12 Card request can be free, and only when the request is supported by a requesting-school basis. The one-time free allowance is tracked per student and per document type.
- **Implementation Scope**: The approved selectable catalog is implemented as a fixed system list for request creation and fulfillment. A dedicated Registrar/Accounting document-catalog management UI for metadata, availability, and fee-policy editing requires a separate implemented and tested workflow.
- **Paid Documents**: Good Moral, Certificate of Enrollment (COE), Grade 11 Card, and Dismissal Certificate are paid document requests.
- **Price List Ownership**: Accounting/Cashier maintains and updates the document price list. Registrar manages document availability, requirements, processing notes, and fulfillment.
- **Free Bypass**: Eligible free Form 137 or Grade 12 Card requests bypass Accounting payment confirmation and proceed straight to the Registrar queue.
- **Paid Requests**: Students pay the document fee first. Accounting confirms the document fee before the request moves to Registrar processing.
- **Pickup vs. Delivery**: Students choose either campus pickup or manual courier delivery. Pickup requests move from Registrar processing to `ready_for_pickup`, then `completed` after release.
- **Delivery Payment Sequence**: For delivery, the student pays only the document fee before processing. The Registrar then ships the document and records the actual courier fee, moving the request to `pending_shipping_payment` for Accounting confirmation.
- **Grace-Period Debt Rule**: If the recorded shipping fee is not paid within 3 calendar days from shipment, the system posts the shipping amount as standard debt to the student ledger and marks the request `completed_with_debt`.
- **Document Request Admin Surface Boundary**: Document request records are created by student/request intake or approved service workflows. Registrar/Accounting-facing Admin Nexus screens are list/view lifecycle-action surfaces for document-fee confirmation, Registrar fulfillment, courier recording, pickup completion, shipping-payment confirmation, and cancellation. They must not expose a generic create/edit Document Request form with raw student IDs, term IDs, document type mutation, status mutation, delivery flags, or free-request toggles.
- **Automation Boundary**: Advanced shipping automation is outside the core system acceptance scope. Manual Registrar fulfillment may remain, but courier integrations, automatic shipping fee escalation, rich shipment UX, and shipping SLA niceties are deferred unless stable and tested. Shipping must not block the core SIS, enrollment, finance ledger, grades, or automatic scheduling delivery.
- **Service Request Admin Surface Boundary**: Service request records are created by student/request intake or approved backend workflows. Registrar-facing admin screens are list/view lifecycle-action surfaces for review, resolution, rejection, cancellation, and fulfillment handoff. Resolve must expose an optional typed `resolution_note`, while Reject and Registrar Cancel must require typed `rejection_reason` / `cancellation_reason` modal fields that feed student notification context and lifecycle activity evidence. The UI must not expose a generic create/edit Service Request form with raw student IDs, attachment path arrays, assignee/resolver IDs, arbitrary status mutation, or discarded generic notes.

### 9.2 Privacy & Security Controls (RA 10173 & NPC 2023-06)

- **Consent & Notice**: Collection of student data, including checkout forms for courier delivery, requires explicit consent and a privacy notice detailing OCR/processor disclosure.
- **Security & Lifecycle**: Personal data is protected by privacy-by-design access controls (NPC 2023-06), purpose-limited retention schedules, right-to-delete mechanisms, access logs, and an institutional breach management protocol. Data-sharing with 3rd-party logistics providers requires explicit authorization and processor contracts.
- **Manual Courier Details**: After shipping, the Registrar records the courier name, tracking number or `N/A`, actual shipping fee, and required courier receipt proof through a private upload field.
- **Courier Data Rules**: Shipping fee is stored as a two-decimal money value, receipt proof is uploaded to private document-request receipt storage through a tamper-protected file upload field, and tracking `N/A` is normalized to uppercase so student notifications and ledger posting stay consistent. Staff must not type arbitrary private storage paths for receipt evidence, and request detail screens must show receipt-proof status rather than the raw private path.
- **Student Notification**: Once courier details are saved, the system sends an email and in-app notification showing the courier name, tracking number, and shipping fee payment status.
- **Restriction Timing**: `pending_shipping_payment` blocks new document requests immediately. Broader financial holds apply only after the grace period of 3 calendar days expires and the shipping fee is posted as debt.

### 9.3 Dynamic Queue Management (SLA)

- To prevent backlog blockages, the system uses a **Dynamic Service Level Agreement (SLA)** with an effective-dated Registrar capacity profile. The current institution may seed **30 requests/day** as its planning capacity, but reaching that value changes the estimated completion date rather than rejecting valid requests.
- The portal displays estimated processing times dynamically from queue volume, working days, request type, and active capacity. An extension requires a reason, revised target date, actor/time audit evidence, and student notification.
- Ready-for-pickup and unclaimed reminders are policy-driven. Release records must identify the claimant, representative authority when applicable, releasing staff, timestamp, and acknowledgement.

### 9.4 Dropout Management & Grace Periods

- **Drop Form Process**: A student wishing to drop must file a form and schedule a mandatory consultation with the Registrar/Guidance.
- **Drop Fee**: An effective-dated withdrawal/refund fee is assessed to their ledger upon official withdrawal, based on approved institutional policy bounding the fee by regulatory refund periods.
- **Grace Period Control**: A Registrar-confirmed no-show/inactivity case may use a configurable one-term grace period, warnings, and archive review. TALA must not infer inactivity or archive a student from attendance until an approved attendance source, review, and appeal path exist.

---

## 10. System Lifecycle

The T.A.L.A. system is "State-Aware", meaning feature availability changes automatically based on the Academic Calendar Dates configured by the **Registrar** and **Academic Head**.

### 10.1 Phase: Enrollment Period

**Dates (Example)**: July 11 - July 31

| Module                   | Status    | Features                                                                               |
| ------------------------ | --------- | -------------------------------------------------------------------------------------- |
| **Module 1 (Student)**   | OPEN      | "Create Account" and "Upload Documents" are active. PWA shows "Enrollment in Progress" |
| **Module 2 (Registrar)** | ACTIVE    | Sectioning tools are unlocked. Editable bounded `Max_Seats` is enabled, with 30 heads as the rescue hard maximum |
| **Module 4 (Faculty)**   | READ-ONLY | Class lists are empty or fluctuating                                                   |

---

### 10.2 Phase: Academic Term (Classes Start)

**Dates (Example)**: August 18 (Start)

**Event: Last Day for Addition (Aug 25)**

- **System Action**: Modules 1 & 2 Disable "Add Subject" button

**Event: Dropping Period (Sept 22-24)**

- **System Action**: Module 2 Enables "Drop Subject" tool
- **Logic**: Dropping after Sept 24 requires "**Registrar Override**" (Late Drop)

---

### 10.3 Phase: Examination Periods

**Dates (Example)**: Oct 20-26 (Midterm), Dec 15-19 (Final)

**Module 3 (Accounting)**:

- **Trigger**: 1 Week before Exam Date
- **System Action**: Refresh Financial_Hold status

**Student View**:

- Examination access is not blocked solely by outstanding balance. The current institutional policy allows enrolled students to take scheduled exams without a debt-based permit gate. Accounting may continue private collection follow-up, next-cycle enrollment holds, and lawful record-release holds.

**Module 4 (Faculty)**:

- **Teaching**: Classes continue
- **Grading**: "Midterm Grade" column becomes Editable during this window

---

### 10.4 Phase: Grade Encoding & End of Term

**Dates (Example)**: Dec 5 - Dec 12 (Encoding Period)

**Module 4 (Faculty)**:

- **Status**: UNLOCKED. Faculty can input Final Grades
- **Deadline Enforcement**: On Dec 12 at 11:59 PM, the system Manually Locks all Grade Sheets not yet finalized
- **Late Submission**: Requires Faculty to request "Unlock" from the Academic Head with a recorded reason

**Module 2 (Registrar)**:

- **Action**: Dec 20 (Semester End) triggers "Term Close" batch job
- **System Action**: Status resets to "Not Enrolled" for next term

### 10.5 Scheduled Job Operations

School housekeeping jobs run off-hours in `Asia/Manila` to reduce daytime load. The standard batch size is 100 records, with a maximum of 1,000 records per run unless a stricter rule is defined. Jobs retry 3 times with backoff intervals of 60, 300, and 900 seconds; external API jobs may retry up to 5 times when the provider operation is idempotent. Failed jobs require staff review after final failure.

| Job Area | Normal Run Window | Functional Limit |
| --- | --- | --- |
| Grace period/account archive checks | After midnight | Must not archive users already restored or manually corrected. |
| Shipping fee debt posting | After midnight | Must not double-post a shipping fee. |
| Promissory expiry warnings/expiry | After midnight | Must not send duplicate warnings for the same note/date. |
| INC auto-fail | After midnight | Must skip grades already cleared by staff. |
| Payment housekeeping | After midnight | Must not cancel or reverse a confirmed payment without Accounting action. |
| Term close | Manual Registrar trigger, queued for off-hours unless urgent | Must not close the same term twice. |

---

## 11. User Onboarding Guidance

**Description:**
User guidance is handled through role-specific operations guidance and help content. In-app guided-tour overlays are not part of the core system acceptance scope unless a later implementation item explicitly approves and tests them.

### 11.1 Student Onboarding (PWA Portal)

- **Boundary:** Student onboarding guidance is delivered through Student Hub page content, published FAQ entries, and operations/system acceptance guidance until a separate guided-tour item is approved.
- **Deferred Guided Tour Rule:** Any first-login walkthrough, completion-state storage, or client-side tour library must be specified, implemented, and tested before it becomes system acceptance evidence.

### 11.2 Staff Onboarding (Filament Panels)

- **Decision:** Removed from Admin Nexus. The Filament guided-tour/topbar button is not an approved production surface.
- **Approved Guidance Channel:** Staff training must use maintained operations documents, acceptance scripts, role guidance, and the public/student FAQ where appropriate.
- **Reintroduction Rule:** Any future guided-tour feature requires explicit approval, a documented package/security review, role-specific content ownership, and regression tests proving it does not expose unauthorized staff workflows.

---

## 12. Appendices

### Appendix A: SHS Grading System

| Level           | Component            | Core Subjects | Academic Track | TVL/Sport/Arts Track |
| --------------- | -------------------- | ------------- | -------------- | -------------------- |
| **Grade 11-12** | Written Work         | 25%           | 25%            | 20%                  |
|                 | Performance Tasks    | 50%           | 45%            | 60%                  |
|                 | Quarterly Assessment | 25%           | 30%            | 20%                  |

**Note**: Work Immersion/Research/Business Enterprise Simulation/Exhibit Performance subjects may have different weightings as per DepEd guidelines.

**System Note**: TALA does **not** compute these weights automatically. Faculty applies the appropriate weight profile offline (via Excel, manual computation, etc.) and enters the resulting **transmuted grade** per quarter into TALA. This table is provided as reference documentation. The `subjects.subject_type` column records which weight profile applies to each subject for audit and validation purposes.

**Authority Note**: DepEd Order No. 8, s. 2015 Table 5 is the default authority for these component profiles. The consolidated workflow's simplified `Applied/Specialized = 35/45/20` row conflicts with that table and must not replace this appendix. If TALA later calculates components, it must use a versioned grading profile and store the profile used for every grade sheet.

---

### Appendix B: Current Transitional College Grading Profile (Pending Institution Approval)

**Transitional Runtime Algorithm**: The current code computes the Final Subject Grade using a **weighted average** of raw percentage scores: **Prelim (30%)**, **Midterm (30%)**, and **Final (40%)**. The weighted average is rounded to the nearest whole integer and then transmuted **once** via the table below. This section documents existing behavior for traceability; it does not approve the profile as the institution-wide final policy.

**Grading Policy Boundary**: The consolidated workflow describes a College calculation/point scale that may differ by profile, including lecture/laboratory weighting and percentage bands. The system must use a versioned grading profile scoped by effective term/program/subject and obtain client approval for the active profile and any migration boundary before changing runtime or historical grades.

| Raw Percentage Range | Equivalent Grade | Description  |
| -------------------- | ---------------- | ------------ |
| 98 \u2013 100             | **1.00**         | Excellent    |
| 93 \u2013 97              | **1.25**         | Excellent    |
| 90 \u2013 92              | **1.50**         | Very Good    |
| 87 \u2013 89              | **1.75**         | Very Good    |
| 84 \u2013 86              | **2.00**         | Good         |
| 82 \u2013 83              | **2.25**         | Good         |
| 80 \u2013 81              | **2.50**         | Satisfactory |
| 78 \u2013 79              | **2.75**         | Satisfactory |
| 75 \u2013 77              | **3.00**         | Passing      |
| 74                   | **4.00**         | Conditional  |
| Below 74             | **5.00**         | Failure      |
| N/A                  | **INC**          | Incomplete   |
| N/A                  | **W**            | Withdrawn    |

**Transitional Passing Rule**: 3.00 (75%), pending institution approval of the effective profile.

> **Source**: SIA Evaluation Forms (Ground Truth). This table supersedes the previously referenced PUP standard. The SIA scale includes a **4.00 (Conditional)** grade for raw score 74, which is not present in the generic PUP table.

**Implementation Constraint**: The system **MUST NOT** average transmuted equivalents (e.g., `(1.25 + 1.50) / 2 = 1.375`). This produces values that cannot be resolved against the transmutation table, leading to data drift and registrar disputes. All averaging MUST be performed on raw percentage scores before a single final transmutation.

---

### Appendix C: Document Requirements Matrix

#### Senior High School

| Document                | New Grade 11 | Transferee Grade 12 |
| ----------------------- | ------------ | ------------------- |
| PSA Birth Certificate   | ✅           | ✅                  |
| Diploma                 | ✅           | ✅                  |
| Grade 10/11 Report Card | ✅           | ✅                  |
| Form 137                | ✅           | ✅                  |
| Good Moral Character    | ✅           | ✅                  |
| AF5 (for Grade 11)      | ✅           | ❌                  |

#### College

| Document              | New Student | Transferee |
| --------------------- | ----------- | ---------- |
| PSA Birth Certificate | ✅          | ✅         |
| Grade 11 Report Card  | ✅          | ✅         |
| Grade 12 Report Card  | ✅          | ✅         |
| Form 137              | ✅          | ✅         |
| Good Moral Character  | ✅          | ✅         |
| Diploma               | ✅          | ✅         |

---

### Appendix D: Glossary

| Term     | Definition                                                 |
| -------- | ---------------------------------------------------------- |
| **COR**  | Certificate of Registration - Official enrollment document |
| **LIS**  | Learner Information System (DepEd)                         |
| **LRN**  | Learner Reference Number                                   |
| **PWA**  | Progressive Web App                                        |
| **OCR**  | Optical Character Recognition                              |
| **RBAC** | Role-Based Access Control                                  |
| **SLA**  | Service Level Agreement                                    |
| **TOR**  | Transcript of Records                                      |

---

### Appendix E: Factual Alignment Addendum (2026-05-22)

Based on factual evaluation of actual school documents (Evaluation Forms, Grading Sheets, and SOAs), the following baseline data structures and terminologies **MUST** be strictly adhered to across all implementations, overriding any generic terminology previously used:

1. **Curriculum Dual-Track Structures**
   - **SHS**: Subjects are quantified using **LEC HOURS** (Standard is 80 hours per semester). SHS subjects must be categorized into `Core Subjects`, `Applied Subjects`, and `Specialized Subjects`.
   - **College**: Subjects are quantified using standard **Units** (Lec, Lab, Credit).

2. **Standard Fee Terminology**
   - All enrollment billing, SOAs, and Accounting views MUST use the following exact fee categories as default seeded line items:
     - `Registration Fee` (Standard: ₱500)
     - `Tuition Fee` (Standard SHS: ₱8,750 | College varies)
     - `Other Fees (E-Learning Resources)` (Standard SHS: ₱1,250)
   - *Note: These are baseline values for seeders and templates; the actual values remain fully editable by Accounting per term.*

3. **College Grading Engine Alignment**
   - The final system resolves an institution-approved, effective-dated grading profile and snapshots it with every grade submission package.
   - The current `30/30/40` Prelim/Midterm/Final formula and SIA scale remain transitional runtime evidence only. They must not be promoted as the universal College policy or used to recalculate history until the institution approves the active profile and migration rule.
---

*End of Functional Specification*
