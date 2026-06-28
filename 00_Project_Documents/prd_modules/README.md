# TALA Product Requirements — Modules Table of Contents

This directory is the shared product requirements baseline for TALA. It describes the expected capabilities, business rules, actors, records, outputs, integrations, and product boundaries by module.

The modules provide context for client stakeholders, product owners, developers, and coding agents. They define **what the product must do**, but they are not an implementation sequence, sprint plan, issue breakdown, or definition of done. Technical references that clarify a product constraint do not by themselves prescribe the final implementation.

For multi-session development, use the relevant modules as context for Matt's `/grill-with-docs` -> `/to-prd` -> `/to-issues` flow. The resulting feature PRD and approved vertical-slice issues control implementation. Architecture choices are documented separately in the [System Architecture Specification](../architecture_specification.md).

## Audience and Authority

- **Client and product review:** scope, workflows, roles, business rules, outputs, integrations, and product boundaries.
- **Agent and development context:** domain intent, cross-module dependencies, constraints, and terminology needed to prepare feature PRDs and implementation issues.
- **Implementation authority:** the approved feature PRD and its tracer-bullet issues, interpreted consistently with these product modules.
- **Change rule:** a development decision must not silently alter a client-facing requirement. Product changes must first be reflected in the relevant module.

Working audits, clarification trackers, and reconciliation notes are kept in [`_working/`](./_working/) so the module list stays focused on the PRD surface.

Each module includes a functional interaction contract where user entry or review could otherwise be ambiguous. These contracts identify whether the implementation needs a record form, editable table, selection list, checklist, calendar/date range, validated upload, operational queue, or generated read-only view. They do not prescribe visual design.

## Writing and Surface Rules

Use these rules when updating or implementing the modules:

1. Describe what TALA does before describing a boundary.
2. Use the product workspace names: Public Landing Page, Applicant Workspace, Student Hub, Faculty Workspace, Registrar Workspace, Accounting Workspace, Academic Head Workspace, and System Super Admin Workspace.
3. Use the canonical interaction forms from Module 1: Record Form, Focused Record Form, Restricted Record Form, Editable Table, Selection List, Checklist, Calendar / Date-Range Input, File Upload with Preview, Operational Queue / Review Table, Filter Form, and Generated Read-Only View.
4. Source records are edited only in their owning workspace. Other surfaces show read-only summaries, links, or generated outputs.
5. Computed values such as balances, eligibility, schedule conflicts, grade outcomes, and official outputs are shown as generated read-only results.
6. Boundary statements are used only when they prevent overbuilding or protect official-record integrity.

## MVP System Surface Map

This map identifies how major system areas are surfaced for v1. It is not a visual design or page layout.

| Lifecycle area | Primary surface | Main user entry | Review or output surface |
| --- | --- | --- | --- |
| Public entry and access | Public Landing Page and Filament authentication surfaces | Record Form for sign-in, registration, recovery, and verification | Generated public information page with sign-in/apply entry points |
| Applicant intake | Applicant Workspace | Multi-section Record Form, Checklist, and File Upload with Preview | Generated application status and checklist summary |
| Admission review and handover | Registrar Workspace | Operational Queue / Review Table plus Focused Record Form for decisions | Generated handover summary and student master-record preview |
| Student master record | Registrar Workspace and limited Student Hub profile area | Staff Record Form for official profile fields; limited Student Record Form for allowed contact fields | Generated student profile summary |
| Academic calendar and curriculum setup | Staff Workspace / System Super Admin Workspace | Record Forms, Editable Tables, Calendar / Date-Range Inputs, and TALA CSV Import Templates | Generated readiness and validation summaries |
| Term offerings and resources | Registrar Workspace / Academic Head Workspace | Generated Editable Tables, Record Forms, Selection Lists, and Calendar / Date-Range Inputs | Read-only resource readiness and capacity summaries |
| CP-SAT scheduling | Registrar Workspace | Readiness Review Table, solver-run Record Form, and candidate Review Table | Candidate schedule table, optional timetable view, publication confirmation, and published Master Schedule |
| Enrollment and section placement | Registrar Workspace with Student Hub visibility | Operational Queue / Review Table, Selection Lists, Focused Record Forms for exceptions | Generated gate summary, reservation result, and enrollment status |
| Finance, payment, and ledger | Accounting Workspace with Student Hub visibility | Fee matrix Editable Table, manual payment Record Form, PayMongo checkout, adjustment/reversal forms | Generated assessment, ledger, SOA, payment acknowledgement, and Finance Gate status |
| COR and official outputs | Registrar Workspace and Student Hub | Generate / Render Output action | Generated Read-Only View with authorized print or download |
| Faculty rosters and grades | Faculty Workspace and Registrar Workspace | Faculty roster Editable Table for Period Equivalents; Registrar review queue | Generated grade status, released grade history, and correction records |
| Holds and lifecycle changes | Registrar Workspace / Accounting Workspace / Academic Head Workspace | Focused Record Forms for holds, waivers, recorded lifecycle results, and exceptions | Generated status, hold, capacity, COR, finance, and audit impact summary |
| Graduation and completion review | Registrar Workspace with optional Student Hub visibility | Graduation Review Batch table and snapshot refresh action | Generated Graduation Eligibility Snapshot |
| Reports, imports, audit, retention, and integrations | System Super Admin Workspace and role workspaces | Filter Forms, fixed TALA CSV templates, configuration Record Forms, and Operational Queues | Generated Read-Only tables, CSV exports, audit logs, and integration event logs |

## Table of Contents

1. [01. Product Intent & Architecture](./01_product_intent_architecture.md)
2. [02. Identity, Access, and Workspaces](./02_identity_access_workspaces.md)
3. [03. Admissions & Student Handover](./03_admissions_student_handover.md)
4. [04. Academic Setup](./04_academic_setup.md)
5. [05. Term Offerings & Resources](./05_term_offerings_resources.md)
6. [06. CP-SAT Scheduling Subsystem](./06_cpsat_scheduling.md)
7. [07. Enrollment Gate Model & Execution](./07_enrollment_gate_model.md)
8. [08. Finance, Ledger, & PayMongo Subsystem](./08_finance_ledger_paymongo.md)
9. [09. Certificate of Registration (COR) Subsystem](./09_cor_subsystem.md)
10. [10. Grades](./10_grades.md)
11. [11. Student Lifecycle Status & Holds](./11_student_lifecycle.md)
12. [12. Student Hub & Generated Output Access](./12_student_hub.md)
13. [13. System Administration, Reports, & Audit](./13_system_admin_reports_audit.md)
