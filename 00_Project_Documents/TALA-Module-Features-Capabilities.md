# T.A.L.A. — Complete Module Features & Role Capabilities Reference

**Total Academic Lifecycle Automation System**
**Servitech Institute Asia (SIA)**

> This document consolidates every feature, capability, and role boundary across all T.A.L.A. modules as defined in the Functional Specification (FS) and Technical Specification (TS). It serves as a single, authoritative quick-reference for developers, QA, stakeholders, and onboarding personnel.

**Execution Status Boundary:** This document is a capability reference, not the active build tracker. Current implementation order, backend/admin closure scope, TAL-13 backend split, and Linear mirroring rules live in `TALA-SDD-Execution-Map.md`.

---

## Table of Contents

1. [Role Definitions & Access Matrix](#1-role-definitions--access-matrix)
2. [Module 1: Student Module](#2-module-1-student-module)
3. [Module 2: Registrar Module](#3-module-2-registrar-module)
4. [Module 3: Accounting Module](#4-module-3-accounting-module)
5. [Module 4: Faculty Module](#5-module-4-faculty-module)
6. [Module 5: Administration & Integration](#6-module-5-administration--integration)
7. [Module 6: Service Requests & Documents](#7-module-6-service-requests--documents)
8. [Cross-Module Features](#8-cross-module-features)
9. [System Lifecycle & Scheduled Jobs](#9-system-lifecycle--scheduled-jobs)

---

## 1. Role Definitions & Access Matrix

### 1.1 Role Definitions

| Role | Description | Constraint |
| --- | --- | --- |
| **Applicant** | Temporary account for enrollment workflow | Limited to applicant portal; no Student Hub access |
| **Student** | Officially enrolled student | View-only for most data; self-service for payments, documents, grade corrections |
| **Registrar** | Academic records custodian | Full student records, scheduling, approvals, document SLAs, drop form consultations |
| **Accounting / Cashier** | Financial transactions and ledger owner | OTC payment processing, assessments, promissory approval, fee template management |
| **Faculty** | Teaching and grading | Class lists, grade encoding, availability submission |
| **System Super Admin (IT)** | System administration | Full IT access, staff user management, seeded RBAC matrix review only; **Read-Only** for academics/financials |
| **Academic Head / Principal** | School oversight | **Read-Only** oversight across all domains; can **Authorize Overrides** |

**Hard Rule**: One role per user — no dual assignments (e.g., a user cannot be both Faculty and Registrar).

### 1.2 Complete Access Control Matrix

| Feature | Applicant | Student | Registrar | Accounting | Faculty | Academic Head | System Super Admin |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| Upload Documents | ✅ | ❌ | ✅ (Walk-in) | ❌ | ❌ | ❌ | ✅ |
| View COR | ❌ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| View Grades | ❌ | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ |
| Encode Grades | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Process Payments | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| Approve Documents | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Manage Schedules | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Submit Faculty Availability | ❌ | ❌ | ✅ (Review) | ❌ | ✅ | ✅ (Read-Only) | ❌ |
| View Advising Status | ❌ | ✅ | ✅ | ❌ | ✅ (Read-Only) | ✅ | ✅ |
| Request Documents | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Approve Promissory Note | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| View Promissory Tag | ❌ | ✅ | ✅ (Read-Only) | ✅ | ❌ | ✅ | ✅ |
| Configure Fee Templates | ❌ | ❌ | ❌ | ✅ | ❌ | ✅ (Read-Only) | ❌ |
| Drop/Transfer Consult | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Review Shifting Requests | ❌ | ✅ (Request) | ✅ | ❌ | ❌ | ✅ (Override) | ❌ |
| Manage Summer Schedules | ❌ | ✅ (View) | ✅ | ❌ | ✅ (Assigned) | ✅ (Read-Only) | ❌ |
| Manage Admission Reqs | ❌ | ❌ | ❌ (Deferred) | ❌ | ❌ | ❌ | ❌ (Deferred) |
| Staff User Management | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| RBAC Matrix Review | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (Read-Only) |
| System Settings | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ (Internal Registry) |
| Authorize Override | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| FAQ Management | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Audit Trail Access | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Bulk Data Import | ❌ | ❌ | ✅ (Academic) | ✅ (Financial) | ❌ | ❌ | ❌ |

---

## 2. Module 1: Student Module

### 2.1 Pipeline A — New Student Intake (Applicant Portal)

**Target**: Freshmen (Grade 11 / 1st Year) and Transferees

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Temporary Account Creation** | Applicant signs up with personal email & password; saves progress | Applicant |
| **Digital Orientation** | Mandatory "Rules & Policies" modal with checkbox acceptance before proceeding | Applicant |
| **Applicant Profiling (Student Record / External Reporting Ready)** | Full data capture: identity, contact, academic & status, last school attended, modality selection | Applicant |
| **Duplicate Check** | Fuzzy match on name + birthdate against existing records; redirects to login if match found | System |
| **Modality Preference Capture** | MVP staff-assisted capture: Registrar/enrollment staff records declared modality/delivery preference; future Student Hub self-entry may feed the same field, but Registrar confirmation remains authoritative | Applicant / Registrar |
| **Google Cloud Vision OCR Submission** | Upload document → OCR extracts candidate text → Student reviews & confirms extracted values | Applicant |
| **Quality Filter** | `average_confidence ≥ 80.00` passes; below flags for Registrar manual review | System |
| **Rejection Loop** | If Registrar rejects: Status → `Action_Required`, upload form unlocked, email notification sent; no billing for rejected applicants | Registrar → Applicant |
| **Configurable Admission Offering** | Registrar publishes only supported term/program intake routes; unavailable routes remain inactive and cannot receive public or assisted applications | Registrar |
| **Composable Requirement Resolution** | One admission lifecycle composes requirements from education level, entry route, prior credential, citizenship/compliance, program/grade, and lawful support attributes, then snapshots the resolved policy on intake | System / Registrar |
| **Admission vs. Retention Documents** | Admission-gate evidence is required before handover; non-critical retention documents use itemized 30-to-60-day undertakings and documentary/next-cycle holds without removing current section, COR, class, or grade access | System / Registrar |
| **Document-Class Storage Policy** | Catalog-driven treatment separates private credential originals, official transmissions, restricted medical/support files, ID photos, transaction evidence, generated official artifacts, import sources, OCR derivatives, verified structured data, and physical custody events | System / Registrar / Accounting |
| **Academic Pathing — Freshmen** | Auto-assigned block section; moved to payment assessment | System / Registrar |
| **Academic Pathing — Transferees** | OCR-assisted credit evaluation with pre-populated subject matches; Registrar adjusts; unlocks subject selection (College) or assigns block section (SHS) | Registrar / System |
| **Subject Selection (College Transferees)** | "Shopping Cart" interface for remaining subjects | Student |
| **Official Handover (Account Migration)** | Triggered by accepted admission gates + confirmed payment/secured capacity + compatible placement; retention undertakings may remain active; credential rotation, welcome notification, and old-session invalidation are atomic with enrollment activation | System / Registrar / Cashier |

**Applicant Status Flow**: `pending` → `action_required` → `for_evaluation` → `approved` → `active`

### 2.2 Pipeline B — Existing Student Enrollment (Student Hub)

**Target**: Regular, Irregular, and Returnee students

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Returnee Detection and Legacy Onboarding** | Registrar searches existing/imported records before readmission. If no reliable pre-TALA record exists, staff create a provenance-tagged baseline from verified evidence, run duplicate review, and continue through controlled readmission; no second public applicant identity is created | Registrar |
| **Clearance & Eligibility Check** | Balance > 0 → Blocked; Failed grades detected → Flagged as Irregular | System |
| **Regular Enrollment** | One-click enroll; auto-promoted to next block section | Student |
| **Irregular Enrollment (SHS)** | Student submits proposed back subjects; Registrar manually assigns to appropriate section(s) per subject | Student / Registrar |
| **Irregular Enrollment (College)** | System-guided subject selection with prerequisite enforcement, capacity checks, and schedule conflict prevention | Student / Registrar |
| **Prerequisite Enforcement** | Blocks enrollment if prerequisite has active INC, failed, or missing grade; latest finalized attempt used for repeated subjects; approved equivalents supported | System |
| **Academic Load Cap (30 Units)** | Proposed load > 30 units → enrollment blocked until resolved; no overload exception in current scope | System |
| **Automatic Summer-Class Split** | Overflow subjects separated into proposed summer bucket; advisory until Registrar confirms | System / Registrar |
| **Payment & Activation** | Upload proof → Cashier confirms finance clearance and secures capacity → admission/placement gates complete → `Enrolled` and COR available; retention undertakings remain separate holds | Student / Cashier / Registrar |

### 2.3 Student Hub (PWA)

| Feature | Description | Access |
| --- | --- | --- |
| **Offline COR/Schedule Viewing** | PWA caches COR, class schedule, latest grades for offline read-only access | Student |
| **Modular Ownership Status** | Shows responsible faculty teacher/adviser ownership when available; printed-module pickup/submission logistics stay outside MVP | Student (Modular) |
| **Pull-to-Refresh Sync** | Auto-refreshes data when internet returns | Student |
| **Financial Dashboard** | Current balance, payment history, Pay Now button, promissory note status, exam permit access indicator | Student |
| **Grade Viewing** | View finalized grades per term/subject | Student |
| **Grade Correction Request** | Submit request with subject, current grade, desired correction, reason, optional attachments (max 3 files, 5 MB each) | Student |
| **Document Request Portal** | Request official documents (Form 137, COE, Good Moral, etc.) with pickup or delivery option | Student |
| **Shifting Request** | Submit program/strand shifting request (deferred module) | Student |
| **Interactive Onboarding** | First-login guided tutorial highlighting COR, Grades, and Request Document areas | Student |

---

## 3. Module 2: Registrar Module

### 3.1 Pre-Semester Setup

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Academic Year Setup** | Configure academic year, education level, start/end dates, status, reference note | Registrar |
| **Term Setup (Calendar Contract)** | Configure term identity, dates, and 6 operational gates: `enrollment_starts_at`, `enrollment_ends_at`, `late_enrollment_ends_at`, `payment_deadline`, `adjustment_ends_at`, `scheduling_starts_at` | Registrar |
| **Date-Driven Feature Locking** | Missing dates auto-lock affected features (enrollment, scheduling, class lists) | System |
| **Per-Level Cutover** | SHS activates at quarter boundary; College at semester boundary; mid-term cutovers blocked | System |

### 3.2 Curriculum Intake & Versioning

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Download Standardized Template** | Unified curriculum template with strict headers for education level, program, curriculum version, year/grade, period, subject, units, weekly contact hours, academic subject type, scheduling group, and delivery-rule override | Academic Head / Registrar |
| **Upload & Parse Curriculum** | System validates headers and rows, rejects zero-valid-row batches, previews errors, commits only zero-error batches, and marks affected curriculum scopes `needs_review` until confirmed ready | Academic Head / Registrar |
| **Curriculum Scope Readiness** | Scheduling can use only scopes marked `ready_for_scheduling` by program + curriculum version + year/grade + period | Academic Head / Registrar |
| **Curriculum Versioning** | Immutable per batch; existing students bound to admission-year version; no mid-year replacements for active batches | System |
| **Clerical Edits** | Academic Head can fix specific subject mapping (typo, prerequisite) via Filament UI without re-uploading entire sheet | Academic Head |

### 3.3 Student Record Management (Digital File Cabinet)

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Centralized Filament Data Table** | Global search with filters: Department, Program/Strand, Year/Term, Status (Active/Inactive/Graduated/Archived), Standing (Regular/Irregular) | Registrar |
| **Class-Aware Document Cabinet** | Shows authorized canonical evidence, versions, provenance, review/OCR status, requirement satisfaction, and physical custody without exposing raw storage paths; restricted medical/SEN/IP/immigration evidence uses a separate permission boundary | Registrar / Authorized Staff |
| **Student Jacket (View/Edit)** | Direct edit: Contact, Civil Status. Formal change request: LRN, Name, Birthdate (with proof upload). Strictly view-only: Grades, Balances, Student ID, Audit Logs | Registrar |
| **Program Shifting Rules** | Grade 12 SHS blocked; College up to 2nd-year limit; preserves all prior records; multi-step approval workflow | Student / Registrar / Academic Head / Accounting |

### 3.4 Scheduling & Sectioning

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Delivery Patterns** | Versioned reusable scheduling rules for modality/day/subject routing; used versions are frozen and changes require cloning | Registrar / Academic Head |
| **Section Delivery Groups** | A section can contain multiple delivery setups when students take the same subjects; each group has its own modality, capacity, pattern, and room requirement | Registrar |
| **Modality Impact on Scheduling** | On-site/blended: room + faculty required, full conflict check; online: faculty and group conflict check, no meeting URL tracking; modular print: no recurring meeting in MVP, but teacher/adviser ownership remains required | System / Registrar |
| **Term Readiness Gate** | Term must have `term_name`, `term_start_date`, `term_end_date`, `scheduling_starts_at` before scheduling unlocks | System |
| **Faculty Availability Self-Service** | Registrar opens availability submission period (`opens_at < closes_at <= scheduling_starts_at`); Faculty enters available days/times; editable only while `draft` and window open | Registrar / Faculty |
| **Direct Schedule Assignment** | Registrar assigns teacher, room, time to section delivery groups; real-time conflict validation covers teacher, room, group/section, eligibility, availability, and capacity | Registrar |
| **Capacity Management** | Capacity is enforced at both section and delivery-group levels; overflow/PIN is not part of MVP | Registrar |
| **Schedule Publish Lifecycle** | Registrar prepares/reviews/commits; Academic Head publishes; System Super Admin emergency publish requires reason | Registrar / Academic Head |
| **Post-Publish Changes** | Require change request, reason capture, approval, audit history, and affected schedule update through lifecycle service | Registrar / Academic Head |
| **Summer Class Scheduling Panel** | Review overflow students; open summer candidates; assign sections; commit using same conflict/capacity/audit rules | Registrar |

### 3.5 Enrollment Management

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Pending Applications Review** | Queue of students with submitted documents; Approve or Reject (triggers Rejection Loop) | Registrar |
| **Transferee Evaluation (College)** | View TOR; OCR pre-fills credited subjects via regex/fuzzy matching against curriculum; Registrar reviews and approves → unlocks Subject Selection | Registrar |
| **Transferee Evaluation (SHS)** | View Grade 11 Card; OCR highlights promotion signals; Registrar confirms → assigns block section | Registrar |
| **Physical Document Verification** | Registrar verifies hard copies match digital uploads; clicks "Confirm Physical Submission" | Registrar |
| **Finalize Applicant (Account Migration)** | Auto-triggered when physical docs confirmed + payment confirmed; generates Student ID, creates student profile, rotates credentials, emails welcome | System |
| **Enrolled Student Roster & Export** | Read-only term roster of `Enrolled` students; filters by level, program/strand, year/grade, section, modality, and student type; audited generic CSV/XLSX export supports external reporting without regulator-specific templates or external-system completion tracking | Registrar |
| **Walk-In Entry** | Streamlined form for physically present students; optional document upload (bypasses OCR); enforces prerequisites, payments, capacity; tagged as `Staff_Assisted` | Registrar |
| **Global Enrollment Lock & COR Generation** | Toggle "End Enrollment Period"; batch-generates PDF COR with QR Code for all finance-cleared students | Registrar |

### 3.6 COR & Document Generation

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **PDF COR Generation** | Read-only PDF with QR code (opaque token/signed route); contains student info, schedule, units, fees, payment status | System |
| **QR Verification** | Online verification URL showing minimal validity details (type, student identity, term, issue date, status); no financial data exposed | Public |
| **COR Scope** | Auto-generated for Enrollment (COR) and Grades (Report Card) only; Diploma issuance remains out-of-scope | System |

---

## 4. Module 3: Accounting Module

### 4.1 Tuition Assessment (Auto-Assessment)

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Global Fee Database** | Single `fees` table; each fee mapped to academic scope (grade/program); auto-tagged to student ledger upon enrollment | Accounting |
| **Bulk Fee Import** | Optional Excel (.xlsx) import for global fees | Accounting |
| **Manual Adjustments** | One-off charges or overrides added to student ledger | Accounting |
| **Automated Freshmen Discount** | 50% Tuition Fee discount for `student_type = 'New'` AND (`year_level = '1st Year'` OR `year_level = 'Grade 11'`); applied as negative ledger entry; excludes Misc/Lab/Other fees | System |
| **Irregular Assessment Flag** | Irregular students flagged as "Custom Calculation Required" for unit-based fee verification | System |
| **Fee Template Downpayments** | `minimum_downpayment_percentage` configured through canonical education/program/year scoped fee templates; only one active fee template may govern a scope at a time | Accounting |

### 4.2 Payment Processing

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Online Payment (PayMongo — GCash/E-Wallet)** | Student pays via hosted checkout; confirmation via `checkout_session.payment.paid` webhook only; redirect URL is not proof of payment; idempotent processing | Student / System |
| **Over-The-Counter (OTC) / Manual Bank Transfer** | Student uploads GCash screenshot, bank deposit slip, or receipt; Cashier reviews, enters amount/reference/date, confirms; ledger updates instantly | Student / Cashier |
| **Promissory Note** | Student uploads signed note (one per academic year); Accounting/Cashier approves; records promise and expiry; does NOT clear balance, NOT satisfy downpayment, NOT move to `Enrolled` | Student / Cashier |
| **Drop-Out Fee** | Automatic ₱3,500 fee assessed to ledger upon official withdrawal | System |
| **Document Request Fees** | Paid documents require Accounting confirmation before Registrar fulfillment; free documents bypass Accounting | Accounting / Registrar |
| **Shipping Fee (2-Phase Model)** | Document fee confirmed before fulfillment; Registrar records actual shipping fee after shipment; 3-day grace before debt posting | Registrar / Accounting |

### 4.3 Financial Business Rules

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Downpayment Clearance** | Finance clearance when minimum downpayment is received or full balance is paid; `Enrolled` still requires physical documents and placement; promissory notes do NOT trigger this | System / Accounting |
| **Exam Permit Visibility** | Viewable only if `Current_Balance <= 0` (fully paid); promissory does not grant access | System |
| **Financial Disposition Policy** | Current institution uses strict no refund; typed cancellation/discrepancy causes resolve through the active deployment policy; excess remains credit unless policy authorizes another disposition | System |
| **Advance Payments (Negative Balance)** | Overpayment drives balance below zero; new assessments automatically offset; no separate "Student Wallet" | System |
| **Immutable Ledger** | All financial entries are write-once; errors corrected via reversal transactions, never deletion | System |
| **Real-Time Synchronization** | Payment confirmed at 10:00 AM → Student Hub and Faculty lists reflect "Paid" within ~1 minute | System |

### 4.4 Deferred: Installment Policy (F10 Target)

| Feature | Description | Status |
| --- | --- | --- |
| **Installment Plans** | Configurable up to 10 months; monthly due at end-of-month | Deferred |
| **Grace Period** | 3-day grace before overdue | Deferred |
| **Penalty** | 5% on overdue installment | Deferred |

---

## 5. Module 4: Faculty Module

### 5.1 Class Management

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Digital Class List** | Populated by Accounting logic (paid students only); real-time updates; late enrollees marked "New" for 3 days; pending-payment students invisible | Faculty (Read-Only) |
| **Payment Status Pill (Privacy-Protected)** | Faculty sees only `Paid/Cleared` (green) or `With Balance` (amber/orange); no balance amount, transaction history, or payment channel exposed | Faculty (Read-Only) |
| **Student Info Update Indicators** | In-app notification + "Updated" badge (48 hrs) when a student changes contact, modality, guardian, or enrollment info; diff view available | Faculty |
| **View Teaching Schedule** | Assigned classes: Subject, Day, Time, Room; sourced from committed Registrar schedules | Faculty |
| **Faculty Availability Submission** | Self-service entry of available days/time windows during Registrar-opened submission period; editable while `draft` and window open | Faculty |
| **Availability Change After Deadline** | Formal change request with reason; Registrar notified; no auto-schedule mutation | Faculty / Registrar |
| **Public Admission Requirements Link** | "Quick Links" widget on dashboard; one-click copy of public admission requirements URL | Faculty |

### 5.2 Grading Ecosystem

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Period-Level Entry** | Faculty enters one computed grade per grading period; component-level computation (Written Work, Performance Tasks, etc.) done offline | Faculty |
| **SHS Grading (DepEd-Aligned)** | Input: transmuted grade (60–100) for Q1 and Q2; system averages for final grade; minimum transmuted = 60, minimum passing = 75 | Faculty / System |
| **College Grading (Profile-Gated)** | Current runtime uses raw Prelim 30%, Midterm 30%, Final 40% and the raw-evidence SIA scale. The updated workflow conflicts; SDD-08A must add effective-dated profiles and obtain client approval before changing calculations or historical grades. | Faculty / System / Academic Head |
| **INC (Incomplete) Lifecycle** | 365-day countdown from end of term; auto-fail to 5.0 on day 365 via nightly batch job; blocks prerequisite chain while active | Faculty / System |
| **Grade Finalization & Locking** | Faculty clicks "Finalize Grades" → read-only; already-finalized shows notice (no state change); Academic Head may force-finalize or reopen with reason | Faculty / Academic Head |
| **Grade Upload (Excel Template)** | Faculty downloads pre-populated `.xlsx` template; uploads with Student ID cross-check against official class list | Faculty |
| **Grade Correction (Student-Initiated)** | Student submits via Hub → Registrar acknowledges (3 working days SLA) → Reviews/coordinates with Faculty → Resolves or Rejects (10 working days SLA) → Academic Head approves if official grade changes | Student / Registrar / Faculty / Academic Head |
| **Auto-Escalation** | SLA breach → automatic escalation to Academic Head with notifications | System |

### 5.3 Faculty Academic Advising Status

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Advising Status Modal** | Read-only modal from class list showing system-computed advisory status per student | Faculty (Read-Only) |
| **Status Values** | `Not Available` (gray) — no grades yet; `Good` (green) — no risk; `Watch` (amber) — one low-pass (75-79); `Priority` (red) — any INC, fail, or 2+ low-pass | System |
| **Visible Data** | Enrollment status, advising status + reasons, current term subjects, prerequisite status, year/grade level, modality, enrollment history | Faculty |
| **Hidden from Faculty** | Financial balances, LRN, birthdate, guardian contact, computed GPA | System |
| **Consequence** | None — advisory signal only; no sanctions, blocks, or automated actions | — |

---

## 6. Module 5: Administration & Integration

### 6.1 System Super Admin Functions

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Custom COR & Document Templates** | Modify COR layout/fields to match institutional branding; changes apply globally for future COR generations | System Super Admin |
| **HR Management & Account Archiving** | Phase 1: Archive account (session flush, role stripping, status → `archived`); Phase 2: Historical integrity (never delete user record, name appears as `[Inactive]`); Phase 3: Restore account for rehire | System Super Admin |
| **User Management** | Create, edit other non-archived staff, archive, restore staff accounts; assign exactly one approved role | System Super Admin |
| **System Settings (Internal Registry)** | Seeded runtime keys read by backend services; no generic admin control panel or raw key/value/JSON editing during TAL-12. Future maintenance/admission settings require dedicated typed pages. | System |
| **Term Management** | Create new terms; carry-over logic (profiles/balances carry, enrollment resets); supports overlapping terms | Registrar / System Super Admin |
| **FAQ Management** | CRUD for FAQ entries; explicit sort order; publish toggle; filter by category; only `system-super-admin` with `manage-faqs` can create/edit/publish/delete | System Super Admin |
| **Two-Layer Maintenance Mode** | Application-level UI toggle with custom message/ETA (staff read-only bypass) AND Infrastructure-level CLI (`php artisan down`) | System Super Admin |

### 6.2 Security & Access Control

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **RBAC Enforcement** | Role-based permissions enforced at middleware, policy, and query levels; one role per user; role/permission assignments are seeded code-owned matrix data and visible to System Super Admin as read-only review evidence | System / System Super Admin |
| **Status-Based Middleware** | Only `users.status = 'active'` can access protected areas; blocks pending, inactive, dropped, archived, etc. | System |
| **Audit Trail** | Indefinite retention; users cannot see own logs; System Super Admin access only; critical action alerts | System / System Super Admin |
| **In-Flight Transaction Protection** | PayMongo webhook endpoints excluded from maintenance middleware; active queued jobs complete | System |

### 6.3 Administrative Dashboard

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Real-Time Overview Stats** | Enrollment (Enrolled/Pending/Dropped/Transferee), Financial (Revenue/Outstanding/Collection Rate), Academic (Pass/Fail per Subject/Teacher) | System Super Admin / Academic Head |
| **Filtering** | Date range (this term vs last term); enrollment trends (year-over-year) | — |
| **Grade Submission Progress Widget** | Tracks faculty grade submission progress; deadline countdown banner; summary stats (Total/Submitted/In Progress/Not Started/Overdue); detail table per faculty/section | Academic Head |
| **Bulk Reminder Action** | Select faculty → send email + in-app notification with deadline and section/subject details | Academic Head |
| **Export Report** | Download Excel (.xlsx) of submission status for all faculty | Academic Head |

### 6.4 Database & Architecture

| Feature | Description |
| --- | --- |
| **Centralized Database** | Single source of truth; same Student_ID across all modules |
| **External Reporting Support** | Enrolled Student Roster with filtered, audited export of institution-held student/enrollment data; no LIS status, queue, or mark-as-encoded action |
| **Discrepancy Handling** | External-system findings return through the normal audited enrollment correction/cancellation workflow; no external system directly mutates T.A.L.A. |

### 6.5 Email Notifications

| Feature | Description |
| --- | --- |
| **Unified Notification Class** | Single `GeneralSystemNotification` accepting `type`, `subject`, and `body` |
| **Delivery** | System retries bounced emails; permanent fail → "Invalid Email" flag; in-app Notification Center mirrors all emails |
| **Opt-Out** | Not available — account updates are mandatory |
| **Language** | English only |
| **Branding** | Templates include school logo/colors (System Super Admin editable text) |

**Notification Triggers**:

| Module | Trigger | Subject |
| --- | --- | --- |
| Module 1 | Account Created | "Welcome to T.A.L.A." |
| Module 1 | Document Rejected | "Action Required: Re-upload document" |
| Module 1 | Account Upgraded | "You are officially enrolled" |
| Module 3 | Payment Confirmed | "Payment Received - Ref #12345" |
| Module 3 | Financial Hold Applied | "Action Required: Minimum downpayment required" |
| Module 3 | Payment Follow-up Required | "Your balance remains due. Scheduled examinations remain available; contact Accounting for settlement options." |
| Module 3 | Promissory Activated | "Promissory Note recorded — payment still required" |
| Module 3 | Promissory Expiring | "Warning: Promissory Note expires in 3 days" |
| Module 3 | Promissory Expired | "Promissory Note expired — payment still required" |
| Module 3 | Account Unrestricted | "Account cleared — Full access restored" |
| Module 4 | Grades Finalized | "Your grades are posted" |
| Module 5 | Password Reset | "Security Alert: Password Changed" |
| Module 6 | Document Shipped | "Your [Type] has been shipped via [Courier]" |
| Module 7 | Student Info Updated | "Student [Name] updated their [field]" |

### 6.6 Data Migration & Provisioning (Hybrid Seed & Claim)

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Phase 1: Seed (Bulk Import)** | Registrar imports legacy Excel masterlist; creates "Skeleton Accounts" with `status = 'unclaimed'`; financial balances posted as `Legacy Balance Forward` | Registrar |
| **Phase 2: Claim (Self-Service + OCR)** | Student inputs LRN + uploads previous Report Card → OCR verifies identity → If match: student sets password, account → `active`; If low confidence: routed to Registrar manual review | Student / Registrar |
| **Lockout Protection** | 5 attempts per LRN/IP per hour; 3 OCR mismatches within 24 hrs → 24-hour claim lock + Registrar review task | System |

### 6.7 Bulk Data Import Framework

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Strict Templates** | Staff downloads official `.xlsx` template with locked headers; freeform layouts rejected | Registrar / Accounting |
| **3-Phase Pipeline** | Phase 1: Upload & Parse → Phase 2: Preview & Validation (color-coded: ✅ Valid / ⚠️ Warning / ❌ Error) → Phase 3: Commit (DB transaction) | Staff |
| **Non-Destructive** | Never overwrites existing records; duplicates flagged and skipped | System |
| **Immutable Source Tagging** | Every imported record carries `source: legacy_import`, `import_batch_id`, `imported_by`, `imported_at` | System |

**Template Types**:

| Template | Purpose | Authorized Roles |
| --- | --- | --- |
| Template A — Student Data | Skeleton accounts from legacy | Registrar |
| Template B — Legacy Grades | Historical academic records | Registrar |
| Template C — Legacy Financial Records | Historical ledger entries | Accounting |
| Template D — Enrollment Records | Historical term enrollments | Registrar |

### 6.8 Interactive User Onboarding

| Feature | Description | Target |
| --- | --- | --- |
| **Student Onboarding (PWA)** | Auto-activates on first login; highlights My Documents, Grades, Request Document; saved to profile to prevent repeat | Student |
| **Staff Onboarding (Admin Nexus)** | Staff training is delivered through operations guidance, UAT scripts, role guidance, and maintained FAQ/help content. A Filament guided-tour runtime is not part of the approved TAL-12 production surface unless a later reviewed slice reintroduces it. | Staff |

---

## 7. Module 6: Service Requests & Documents

### 7.1 Document Request Portal

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Document Catalog** | Registrar manages type metadata, requirements, processing notes, availability; Accounting manages free/paid classification and fee amounts | Registrar / Accounting |
| **Activation Rule** | New document types not requestable until Accounting marks as `free` or assigns a positive fee | Accounting |
| **Free Documents** | First Form 137 and first Grade 12 Card per student (with requesting-school basis); bypass Accounting | Student / Registrar |
| **Paid Documents** | Good Moral, COE, Grade 11 Card, Dismissal Certificate; require Accounting fee confirmation before Registrar processing | Student / Accounting / Registrar |
| **Pickup Flow** | `processing` → `ready_for_pickup` → `completed` (after student claims) | Registrar / Student |
| **Delivery Flow** | Mandatory Data Privacy consent (RA 10173); Registrar ships and records courier name, tracking, shipping fee, receipt photo; `pending_shipping_payment` until Accounting confirms | Registrar / Accounting / Student |
| **Grace-Period Debt** | Unpaid shipping fee after 3 calendar days → posted as standard debt to ledger → `completed_with_debt` | System |

### 7.2 Dropout Management & Grace Periods

| Feature | Description | Roles Involved |
| --- | --- | --- |
| **Drop Form Process** | Student files form and schedules mandatory consultation with Registrar/Guidance | Student / Registrar |
| **Service Request Lifecycle Notes** | Registrar resolves service requests with optional resolution notes and must enter rejection/cancellation reasons; these are stored as lifecycle activity/notification context, not editable request fields | Registrar / Student |
| **Drop Fee** | Automatic ₱3,500 assessed to ledger upon official withdrawal | System |
| **Grace Period** | One-term grace for non-formal drops; system sends warnings; archives account after expiry | System |
| **Document Hold** | Dropped students cannot request documents until balance settled | System |

### 7.3 Dynamic Queue Management (SLA)

| Feature | Description |
| --- | --- |
| **Dynamic SLA** | Estimated processing times calculated from current queue volume (e.g., "3 days" vs "5 days") |
| **No Hard Daily Cap** | Replaces fixed 30/day limit with volume-based dynamic estimation |

---

## 8. Cross-Module Features

### 8.1 Standard User-Facing Message Templates

| Category | Severity | Title | Message Template |
| --- | --- | --- | --- |
| Success | `success` | Saved Successfully | "Your changes have been saved." |
| Success | `success` | Payment Submitted | "Your payment reference #{ref} has been submitted for confirmation." |
| Success | `success` | Document Uploaded | "Your document has been uploaded and is pending review." |
| Success | `success` | Grades Submitted | "Grades for {section} — {subject} have been submitted." |
| Validation | `warning` | Missing Required Fields | "Please complete all required fields before submitting." |
| Validation | `warning` | Invalid File Format | "Please upload a valid file. Accepted formats: {formats}." |
| Blocking | `danger` | Action Not Permitted | "You do not have permission to perform this action." |
| Blocking | `danger` | System Under Maintenance | "The system is currently undergoing scheduled maintenance." |
| Blocking | `danger` | Financial Hold Active | "Your account has a financial hold. Please visit the Cashier's Office." |
| Info | `info` | Processing Request | "Your request is being processed. You will be notified when complete." |
| Error | `danger` | Something Went Wrong | "An unexpected error occurred. Please try again." |
| Error | `danger` | Session Expired | "Your session has expired. Please log in again." |

**Display Rules**: Top-right toasts; success/info auto-dismiss 5s; danger persists 8s; warning 6s; field-level validation errors appear as red text below input.

### 8.2 Resilience & Error Handling

| Scenario | Fallback |
| --- | --- |
| Google Cloud Vision OCR Failure | Manual review (upload raw image) |
| Payment Gateway Down | OTC mode or manual screenshot upload |
| System Error | Friendly messages ("Service Busy") instead of raw 500 codes |

### 8.3 Public Landing Page

| Button | Target | Destination |
| --- | --- | --- |
| Enroll Now | New Students | Module 1: Step 0 (Orientation) |
| Check Application Status | Applicants | Applicant Portal Login |
| Student Login | Official Students | Student Hub (PWA) |
| Staff Login | Employees | Admin Nexus (Filament Panel) |
| Help & Support (FAQ) | All Users | Public FAQ Page |

### 8.4 Public Admission Requirements Portal

| Feature | Description |
| --- | --- |
| **Public Page** (`/admission-requirements`) | Document checklists (SHS/College), modality options, enrollment steps overview, FAQ links |
| **Configuration** | Stored internally in `system_settings.admission_requirements` as seeded versioned JSON; Registrar/System Super Admin typed editing is deferred until a dedicated validated workflow exists |
| **Faculty Quick Link** | "Copy SHS Requirements Link" / "Copy College Requirements Link" widget on Faculty Dashboard |

---

## 9. System Lifecycle & Scheduled Jobs

### 9.1 Phase-Based Feature Availability

| Phase | Dates (Example) | Key Module States |
| --- | --- | --- |
| **Enrollment Period** | Jul 11–31 | Student: OPEN (Create Account, Upload Docs); Registrar: ACTIVE (Sectioning unlocked); Faculty: READ-ONLY |
| **Academic Term Start** | Aug 18 | Last Day for Addition (Aug 25): "Add Subject" disabled; Dropping Period (Sep 22-24): "Drop Subject" enabled |
| **Examination Periods** | Oct 20-26 (Midterm), Dec 15-19 (Final) | Accounting: private collection follow-up continues; Student: no debt-based exam block; Faculty: configured grade period is editable |
| **Grade Encoding** | Dec 5-12 | Faculty: Final Grades unlocked; Dec 12 11:59 PM: grade sheets manually locked; late submission requires Academic Head unlock |
| **End of Term** | Dec 20 | Registrar: Term Close batch job; enrollment status resets to "Not Enrolled" |

### 9.2 Scheduled Background Jobs

| Job | Schedule | Description | Batch Limit |
| --- | --- | --- | --- |
| `GracePeriodEnforcerJob` | Daily 00:15 (Asia/Manila) | Warns approaching grace periods; archives expired accounts | 100/chunk, 1,000/run |
| `ShippingFeeEnforcerJob` | Daily 00:30 | Posts unpaid shipping fees as debt after 3-day grace | 100/chunk, 1,000/run |
| `CheckPromissoryNoteExpiry` | Daily 00:45 | Sends expiry warnings; deactivates expired promissory notes | 100/chunk, 1,000/run |
| `IncAutoFailJob` | Daily 01:00 | Converts INC → 5.0/Failed after 365 days | 100/chunk, 1,000/run |
| `PaymentHousekeepingJob` | Daily 01:30 | Auto-rejects pending payments older than 72 hours | 100/chunk, 1,000/run |
| `TermCloseJob` | Manual (Registrar) | Closes term; resets enrollment; preserves balances; resets section enrolled counts | 100/chunk, 1 term/batch |
| `SLAWatcherJob` | Nightly | Escalates grade corrections breaching SLA to Academic Head | — |

**Global Job Contract**: All use `Asia/Manila` timezone; `withoutOverlapping()`; retry 3× with backoff [60, 300, 900]s; external API jobs may retry 5×; failed jobs in `failed_jobs` table for staff review.

---

## Role-by-Role Summary

### Applicant
- Create temporary account, complete profiling, upload documents, review OCR results, track application status, re-upload rejected documents, view payment instructions, upload payment proof.

### Student
- View COR/grades/schedule (online + offline via PWA), pay tuition (online/OTC), upload payment proof, view financial dashboard (balance/history/promissory/exam permit), request documents (pickup/delivery), submit grade correction requests, submit shifting requests, view advising status, complete onboarding tutorial.

### Registrar
- Configure academic year/terms/calendar gates, import curriculum, manage student records (view/edit with audit), review/approve/reject applicant documents, receive required physical credentials, evaluate transferee credits (OCR-assisted), assign sections and schedules (with conflict validation), manage section/delivery-group capacity with no bypass override, manage enrollment lifecycle (approve → finalize → COR), view/export enrolled-student rosters for external reporting, process walk-in enrollments, fulfill document requests (pickup/delivery/shipping), handle drop consultations, import legacy academic/enrollment data, manage admission requirements.

### Accounting / Cashier
- Maintain global fee database, configure canonical education/program/year scoped fee templates and downpayment percentages, configure scoped installment policies, process OTC payments, approve promissory notes, confirm online payment webhooks, confirm document request fees, confirm shipping fee payments, manage document pricing, assess drop fees, import legacy financial records.

### Faculty
- View digital class list (finance-cleared students only), view student payment status pills (privacy-protected), submit pre-scheduling availability, view teaching schedule, encode grades (period-level entry), finalize grade sheets, upload grades via Excel template, manage INC grades, view Faculty Academic Advising Status modal, receive student info update notifications, share admission requirements link.

### Academic Head / Principal
- Read-only oversight across all domains, authorize overrides (grade finalization force/reopen, schedule exceptions, shifting exceptions), upload curriculum templates, make clerical curriculum edits, monitor grade submission progress widget, send bulk grade reminders, receive SLA escalation notifications.

### System Super Admin (IT)
- Full staff user management (create/archive/restore and edit other non-archived staff), read-only seeded RBAC matrix review, FAQ content, audit trails, system maintenance mode, enrollment/reporting visibility, and administrative dashboard. Generic runtime settings/admission requirements are internal or deferred until dedicated typed workflows exist; academic and financial operations remain read-only.

---

## 10. Infrastructure & Operations

| Component | Description |
| --- | --- |
| **Server Requirements** | Ubuntu 22.04 LTS, PHP 8.2+, MySQL 8.0+, Redis, Nginx, Supervisor |
| **CI/CD Pipeline** | GitHub Actions workflows for automated deployment and testing |
| **Monitoring** | Laravel Telescope (Local), Sentry (Error Tracking), Horizon (Queue), Prometheus/Grafana (Server Metrics) |
| **Backup Strategy** | Daily database and uploads backup to S3; Weekly audit log archiving |

---

*End of Module Features & Capabilities Reference*
