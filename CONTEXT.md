# TALA Context

TALA is the academic lifecycle and administration system for the institution. This glossary defines the product language used across PRD modules, implementation slices, and future domain decisions.

## Language

**Student Hub**:
The authenticated student-facing area where a student views current academic status, finance status, holds, schedules, grades, and generated outputs.
_Avoid_: Student portal, generic dashboard

**Staff Workspace**:
The authenticated operational area for all institution employees (e.g., Registrar, Accounting, Academic Head) to manage queues, approvals, setup, and reports.
_Avoid_: Admin portal, backoffice, Filament panel

**Applicant Workspace**:
The authenticated area for pre-handover applicants to draft applications, submit checklist uploads, and view admission status.
_Avoid_: Applicant portal, pre-student hub

**Hold**:
An explicit student-affecting restriction record that states what workflow is blocked, why it is blocked, and what condition resolves or waives it.
_Avoid_: Hold flag, hidden block, computed-only restriction

**Admission Checklist Item**:
A flat applicant or student requirement record that captures the required document or credential condition, accepted evidence method, blocking effect, review result, and resolution state.
_Avoid_: Document workflow, nested document request, upload-only requirement

**TALA Result Record**:
The official record created or updated in TALA after an institution-handled office action affects the academic lifecycle.
_Avoid_: Offline-only action, undocumented office result

**Scheduling Demand**:
A schedulable need for a curriculum subject within a section delivery group for a term. It carries contact hours, scheduling group, delivery rule, room requirement, faculty qualification requirement, modality, and capacity context before CP-SAT turns it into candidate schedule rows.
_Avoid_: Raw subject scheduling, vague meeting requirement

**Enrollment Gate**:
A specific validation checkpoint (e.g., Finance, Document, Capacity) that a student must pass before their enrollment becomes official. Gates yield explicit statuses like passed, failed, or overridden, rather than just returning form errors.
_Avoid_: Enrollment step, form validation, enrollment checklist

**Handover**:
The official transition where an approved applicant is converted into an active student. This process generates or reuses the official student number and activates Student Hub access.
_Avoid_: Student registration, account signup, applicant conversion

**Official Receipt (OR) Mapping**:
The process of linking a physical paper receipt reference (OR number) from the cashier to an existing digital payment record in TALA. This ensures the daily reconciliation audit is supported without double-crediting the student's ledger.
_Avoid_: Digital OR generation, double payment

**COR (Certificate of Registration)**:
The official source-derived output document representing a student's enrollment and schedule for a specific term. 
_Avoid_: Schedule printout, generic registration form

**Irregular Student**:
A student who selects individual subjects from a flat list because they do not have guaranteed placement in a progressing cohort block.
_Avoid_: Custom enrollee, off-block student

**CP-SAT Solver**:
The constraint-based scheduling engine (Google OR-Tools) used by TALA to generate candidate schedule rows by resolving room, faculty, and section demands.
_Avoid_: Auto-scheduler, magic button

**Promissory Note**:
An approved deferred payment request that temporarily bypasses finance-related holds (complying with RA 11984) to allow enrollment or document requests, but does not bypass academic or disciplinary holds. Limited to one active note per academic year.
_Avoid_: Payment plan, flexible installment, manual override

**Batch Credit UI**:
A low-friction interface used by the Registrar to quickly check off credited subjects (distinguishing between external transfer credits and internal program shift credits) based on an approved paper evaluation, bypassing the need for a global external-course mapping engine.
_Avoid_: Global equivalency engine, credit transfer wizard

**Period Equivalent**:
The final computed grade for a specific academic period (e.g., Prelim Equiv, Midterm Equiv, Final Equiv) entered into TALA by the faculty. TALA does not store raw quiz or exam scores.
_Avoid_: Raw score, computed grade, partial grade
