# TALA Product Requirements — Modules Table of Contents

This directory contains TALA's canonical product-definition baseline and six approved journey PRDs. Canonical `00`–`06` consolidation and the final cross-module contradiction and omission review are complete. The complete authority set is approved for implementation-task derivation.

The repository-wide [Documentation Authority Registry](../README.md) identifies product authority, workflow authority, operational status, evidence, implementation history, demonstration material, and obsolete/duplicate documents.

The [TALA System Definition Baseline](./00_system_definition_baseline.md) owns the product goal, evidence hierarchy, shared operating rules, clinic workflow, and rebaseline status. Each approved journey PRD defines **what its module must do**. Replaced modules are preserved under [`_legacy/`](./_legacy/) as investigation and salvage inputs, not product rules.

Clinics 1–6, canonical consolidation, and complete-authority approval are complete. The next gate is to derive and separately plan journey-complete vertical implementation tasks under the [TALA Orchestrator Protocol](../TALA-Orchestrator-Protocol.md). A later approved slice plan implements the product; it does not reopen settled authority without stronger authority, a material feasibility conflict, or an explicit user change. This status does not itself authorize application or schema changes.

## Canonical Authority and Preserved Inputs

The final numbered authority set is:

- [`00_system_definition_baseline.md`](./00_system_definition_baseline.md) — shared product goal, evidence rules, operating contract, boundaries, and authority status.
- [`01_identity_access_public_entry.md`](./01_identity_access_public_entry.md) — Identity, Access, and Public Entry.
- [`02_application_admission_decision_enrollment_readiness.md`](./02_application_admission_decision_enrollment_readiness.md) — Application, Admission Decision, and Enrollment Readiness.
- [`03_academic_setup_offerings_published_timetable.md`](./03_academic_setup_offerings_published_timetable.md) — Academic Setup, Offerings, and Published Timetable.
- [`04_current_term_registration_official_enrollment.md`](./04_current_term_registration_official_enrollment.md) — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop.
- [`05_teaching_grades_academic_records_completion.md`](./05_teaching_grades_academic_records_completion.md) — Teaching, Final Grades, Academic Records, Lifecycle, and Completion.
- [`06_accounts_official_outputs_operations_assurance.md`](./06_accounts_official_outputs_operations_assurance.md) — Accounts, Official Outputs, Operations, and Assurance.

The former Product Intent, Term Offerings, CP-SAT, COR, Student Lifecycle, Student Hub, and System Administration modules are preserved in [`_legacy/`](./_legacy/) with explicit non-authority banners and their evidence content retained below those banners. They are evidentiary inputs only. Their old numbering and wording do not create additional authorities or override canonical `00`–`06`.

## Audience and Authority

- **Client and product review:** scope, workflows, roles, business rules, outputs, integrations, and product boundaries.
- **Agent and development context:** domain intent, cross-module dependencies, constraints, and terminology needed to prepare feature PRDs and implementation issues.
- **Implementation authority:** the approved complete set of six rewritten journey PRDs and corresponding UI authorities, the resolved final cross-module review, and a separately accepted vertical-slice plan.
- **Change rule:** a development decision must not silently alter a client-facing requirement. Product changes must first be reflected in the relevant module.

Working audits and reconciliation notes may remain in [`_working/`](./_working/) when present; each must state its own authority status. The system-definition baseline no longer lives there because canonical consolidation is complete.

Each module includes a functional interaction contract where user entry or review could otherwise be ambiguous. These contracts identify whether the implementation needs a record form, editable table, selection list, checklist, calendar/date range, validated upload, operational queue, or generated read-only view. They do not prescribe visual design.

## Writing and Surface Rules

Use these rules when updating or implementing the modules:

1. Describe what TALA does before describing a boundary.
2. Use the product workspace names: Public Gateway, Applicant Workspace, Student Hub, Staff Workspace, and the fixed role contexts Registrar, Accounting, Faculty, Academic Head, and System Administrator.
3. Use the canonical interaction forms from Module 1: Record Form, Focused Record Form, Restricted Record Form, Editable Table, Selection List, Checklist, Calendar / Date-Range Input, File Upload with Preview, Operational Queue / Review Table, Filter Form, and Generated Read-Only View.
4. Source records are edited only in their owning workspace. Other surfaces show read-only summaries, links, or generated outputs.
5. Computed values such as balances, eligibility, schedule conflicts, grade outcomes, and official outputs are shown as generated read-only results.
6. Boundary statements are used only when they prevent overbuilding or protect official-record integrity.

## MVP System Surface Map

This map identifies how major system areas are surfaced for v1. It is not a visual design or page layout.

| Lifecycle area | Primary surface | Main user entry | Review or output surface |
| --- | --- | --- | --- |
| Public entry and access | Public Landing Page and Filament authentication surfaces | Record Form for sign-in, registration, recovery, and verification | Generated public information page with sign-in/apply entry points |
| Applicant intake | Applicant Workspace | Native five-step Wizard, private File Upload with Preview, scoped corrections, and contextual Requirements | Generated application state, preliminary-evidence summary, official-credential summary, and printable acknowledgment |
| Admission review and enrollment readiness | Registrar Workspace | One Operational Queue / Review Table plus focused Actions for evidence, decisions, credential outcomes, and cycle setup | Derived Ready Applicant projection consumed by Clinic 4 without Student creation or record copying |
| Official Student profile | Registrar Students & Enrollment and read-only Student Profile | Focused Registrar correction action with authority/evidence; Account Security remains Clinic 1 | Generated identity/program/curriculum/entry/contact summary with correction guidance and append-only history |
| Academic setup, offerings, and published timetable | Registrar-owned Catalog & Curricula and Term Planning workbenches; Academic Head and Faculty projections | Grouped Draft curriculum sheet/import, typed Term Calendar Package, Cohorts & Classes, Teaching Resources, whole-term generation, candidate review, and controlled publication/revision | Failed-first readiness, explainable candidate evidence, immutable Published Timetable, and role-specific official schedule projections |
| Current-term registration and official enrollment | Registrar Students & Enrollment workbench with one guided Student Enrollment page | Versioned Standard Curriculum or Registrar-prepared Individually Advised proposal, learner confirmation, transactional placement, and five-checkpoint finalization | Derived checkpoint summary, shortage/reservation evidence, official schedule, and immutable COR versions |
| Accounts and payment evidence | Accounting Fee Plans and tabbed Student Accounts workbench with Clinic 4/5 projections | Versioned Program-and-Term Fee Plan, private manual-evidence review, exact-due PayMongo, and append-only adjustment/reversal results | Summary-first Term Account, Enrollment payment projection, request-specific output clearance, non-tax SOA, and Payment Acknowledgment |
| COR and official outputs | Registrar Students & Enrollment workbench and Student Hub | Official-enrollment or approved-change transaction creates an immutable version | High-contrast Registration Form/COR with registration and assessment-at-finalization snapshot; Clinic 6 owns later Student Account/SOA behavior |
| Faculty rosters and final grades | Faculty Workspace and Registrar Workspace | Controlled final-result table with complete-roster submission and Registrar release/return actions | Released result history, INC/correction evidence, and academic projections |
| Academic record and lifecycle | Student Academics and Registrar Grades & Completion | Focused actions that record authorized progress and lifecycle decisions | Released grades, GWA, curriculum evaluation, confirmed progress, lifecycle history, and unofficial record |
| Graduation, conferral, and TOR | Registrar Grades & Completion with Student Academics visibility | Graduation application, readiness review, recorded conferral, proposed TOR preview, and issuance only after template approval/certification | Completion readiness, immutable degree record, proposed or issued transcript snapshot, and unofficial Student record |
| Imports, contextual exports, health, governance, and retention readiness | System Administrator and owning role workspaces | Fixed TALA CSV templates, locally evidenced health refresh, safe filters, and owning-record actions | Two contextual Clinic 6 CSVs, read-only audit/evidence views, explicit `Not checked by TALA`, and disabled disposal until policy approval |

## Table of Contents

The seven entries below are the complete approved canonical set. Approval authorizes implementation-task derivation only; every task and execution remains separately planned and authorized.

1. [00. TALA System Definition Baseline](./00_system_definition_baseline.md)
2. [PRD 01. Identity, Access, and Public Entry — approved](./01_identity_access_public_entry.md)
3. [PRD 02. Application, Admission Decision, and Enrollment Readiness — approved](./02_application_admission_decision_enrollment_readiness.md)
4. [PRD 03. Academic Setup, Offerings, and Published Timetable — approved](./03_academic_setup_offerings_published_timetable.md)
5. [PRD 04. Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop — approved](./04_current_term_registration_official_enrollment.md)
6. [PRD 05. Teaching, Final Grades, Academic Records, Lifecycle, and Completion — approved](./05_teaching_grades_academic_records_completion.md)
7. [PRD 06. Accounts, Official Outputs, Operations, and Assurance — approved](./06_accounts_official_outputs_operations_assurance.md)

See [Replaced PRD Inputs](./_legacy/) for the preserved non-authoritative source set.
