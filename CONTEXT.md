# TALA Context

TALA is the academic lifecycle and administration system for the institution. This glossary defines the product language used across PRD modules, implementation slices, and future domain decisions.

## Language

**Student Hub**:
The authenticated student-facing area where a student views current academic status, finance status, holds, schedules, grades, and generated outputs.
_Avoid_: Student portal, student Filament panel, generic dashboard

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
