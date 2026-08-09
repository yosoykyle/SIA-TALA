# TALA Product Requirements — Modules Table of Contents

This directory contains TALA's canonical product-definition baseline and six approved journey PRDs. Each PRD now carries its module behavior without requiring a legacy PRD, implementation file, test, benchmark, or formulation document. **Product authority is standalone and ready for separately planned vertical slices.**

The repository-wide [Documentation Authority Registry](../README.md) identifies product authority, workflow authority, operational status, evidence, implementation history, demonstration material, and obsolete/duplicate documents.

The [TALA System Definition Baseline](./00_system_definition_baseline.md) owns the product goal, evidence hierarchy, shared terminology, operating rules, policy classes, coordinated acceptance institution, and cross-module ownership. Each journey PRD defines **what its module must do**. Replaced modules are preserved under [`_legacy/`](./_legacy/) as supporting evidence, not product rules.

The [UI Surface Blueprint](../ui_surface_blueprint.md) is the complete authority for user-visible capabilities, navigation, states, responsiveness, accessibility, and UI acceptance. Its coverage inventory does not prescribe a fixed number of Laravel pages, routes, Livewire components, Filament Resources, or design-tool frames. The next separate gate is planning the first journey-complete vertical implementation slice under the [TALA Orchestrator Protocol](../TALA-Orchestrator-Protocol.md). This status does not authorize application, schema, test, tracker, Git, or external changes.

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
- **Implementation authority:** the approved baseline and six journey PRDs, the canonical UI authority, the Architecture Specification, and a separately accepted journey-complete vertical-slice plan.
- **Change rule:** a development decision must not silently alter a client-facing requirement. Product changes must first be reflected in the relevant module.

Working audits and reconciliation notes may remain in [`_working/`](./_working/) when present; each must state its own authority status. The system-definition baseline no longer lives there because canonical consolidation is complete.

Each module includes a functional interaction contract where user entry or review could otherwise be ambiguous. These contracts identify whether the implementation needs a record form, editable table, selection list, checklist, calendar/date range, validated upload, operational queue, or generated read-only view. The PRDs do not independently prescribe shared visual design; workspace shells, navigation, tokens, reusable components, user-visible capability coverage, responsive/accessibility behavior, and journey acceptance live in the [UI Surface Blueprint](../ui_surface_blueprint.md).

## Standalone Authority Map

| PRD | Successful journey | Owning roles and records | Producer/consumer dependencies | Official outputs | External or operational dependency | Coordinated acceptance subset | UI/architecture dependency | Status |
|---|---|---|---|---|---|---|---|---|
| 01 | Public entry or invitation to secure authorized workspace | Applicant/Student/Staff account owner and System Administrator; Account, access profile, role assignment, security evidence, Public Notice/FAQ | Consumes Clinic 2 entry availability and Clinic 4 Student activation; produces credential/workspace authorization for all modules | Security emails and access evidence only | None; external Staff authority and identity proof are recorded inputs | 47 Student accounts, nine Faculty identities, other Staff, multi-role, disabled, pending, final-admin cases | Shared public/auth shell, workspace shell, Fortify/authorization boundary | Standalone and ready for vertical-slice planning |
| 02 | Verified Applicant to admitted and `ReadyForEnrollment` | Applicant and Registrar; Admission Cycle, Application, evidence, correction, decision, credential result | Consumes PRD 01 identity; produces one versioned ready-applicant projection to PRD 04 | Application Acknowledgment | None; exact requirements, dates, decisions, and credential verification are Registrar operational inputs | Bounded BM/IT/THM applicant cases; no annual forecast | Applicant/Admissions screens, private-file and email boundary | Standalone and ready for vertical-slice planning |
| 03 | Approved academic authority to immutable published timetable | Registrar, Faculty, Academic Head; Program/Course/Curriculum, Term, cohort, Class Offering, resource, candidate, Published Timetable | Consumes demand; produces class/calendar/timetable facts to PRDs 04–05 and receives unmet demand from PRD 04 | Published Timetable/schedule print | None; academic authorities, calendars, capacities, assignments, and sign-off are operational data | Three Programs, six cohorts, 47 Students, nine Faculty, ten rooms plus Special Term | Catalog/Term Planning and private CP-SAT service boundary | Standalone and ready for vertical-slice planning |
| 04 | Ready learner to atomic Official Enrollment and COR | Learner and Registrar; Registration Case, proposal, placement/reservation, Official Enrollment, Student identity, adjustment/drop, COR | Consumes PRDs 02, 03, 05, 06; produces enrollment/roster/change facts to PRDs 01, 03, 05, 06 | Current/historical COR | None; source authority absence blocks only the affected action | First/continuing/advised/Special Term cases over the coordinated institution | Enrollment workbenches, transaction/locking/idempotency boundary | Standalone and ready for vertical-slice planning |
| 05 | Official roster result to academic record, completion, and TOR | Faculty, Registrar, Student, Academic Head; roster/result events, averages, curriculum evaluation, lifecycle, completion/conferral, Transcript Snapshot | Consumes PRDs 03–04 and request-specific PRD 06 clearance; produces released-result, lifecycle, and enrollment-effect projections | Unofficial record and issued/void/replacement/superseded TALA Standard TOR | Physical signing, sealing, delivery, courier, and CAV remain external; signatory data is an operational input | Coordinated roster, retake, deadline-bound nonautomatic INC, external competency, lifecycle, completion and TOR cases | Grade/Academics/Completion screens and immutable-output boundary | Standalone and ready for vertical-slice planning |
| 06 | Authorized assessment to understandable account, verified effect, output, and assurance | Accounting, learner, System Administrator; Fee Plan, Assessment, Term Account, Coverage, evidence/posting, clearance, health/audit | Consumes PRD 04 registration and PRD 05 output request; produces bounded payment-clearance projections to PRDs 04–05 | SOA, Payment Acknowledgment, Account Status CSV, Verified Payments CSV | Lawful retention schedules, privacy requests, legal holds, and secure disposal remain external; automatic disposal is outside the MVP | Same Special Term/account references plus manual, PayMongo, coverage, reversal, alumni and degraded-health cases | Finance/assurance screens, PayMongo/private-file/backup boundary | Standalone and ready for vertical-slice planning |

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
| Academic setup, offerings, and published timetable | Registrar-owned Catalog & Curricula and Term Planning workbenches; Academic Head and Faculty projections | Grouped Draft curriculum sheet/import with authority-backed external-competency requirements, typed First/Second/Special Term Calendar Package with informational Examination Period, Cohorts & Classes, Teaching Resources, whole-term generation, candidate review, and controlled publication/revision | Failed-first readiness, role-consistent Examination Period/unavailable state, Special Term schedule/hour authority, explainable candidate evidence, immutable Published Timetable, and role-specific official schedule projections |
| Current-term registration and official enrollment | Registrar Students & Enrollment workbench with one guided Student Enrollment page | Versioned Standard Curriculum or Registrar-prepared Individually Advised proposal, learner confirmation, transactional placement, and five-checkpoint finalization using a valid ordinary or exact authorized individual assessment plus verified payment/Approved Coverage | Derived checkpoint summary, separate payment/coverage amounts and satisfaction basis, shortage/reservation evidence, official schedule, and immutable COR versions |
| Accounts and payment evidence | Accounting Fee Plans and tabbed Student Accounts workbench with Clinic 4/5 projections | Fixed versioned Program-and-Term Fee Plan for ordinary cases; exact externally calculated authorized individual assessment for bounded exceptions; append-only externally Approved Coverage; private manual-evidence review; exact-due PayMongo; append-only adjustment/reversal results | Summary-first Term Account, separate payment/coverage effects, Enrollment payment projection, request-specific output clearance, non-tax SOA, and Payment Acknowledgment |
| COR and official outputs | Registrar Students & Enrollment workbench and Student Hub | Official-enrollment or approved-change transaction creates an immutable version | High-contrast Registration Form/COR with assessment basis and position at finalization; later financial review may be identified, while Clinic 6 owns current Student Account/SOA behavior |
| Faculty rosters and final grades | Faculty Workspace and Registrar Workspace | Controlled final-result table with complete-roster submission and Registrar release/return actions; every released `INC` receives the fixed one-year completion deadline | Released result history, deadline amendments, `CompletionOpen`/`CompletionOverdue`/`Resolved`, correction evidence, and academic projections; no automatic grade conversion |
| Academic record and lifecycle | Student Academics and Registrar Grades & Completion | Focused actions that record verified external-competency evidence and actual authorized academic/lifecycle decisions | Released grades, explicit average readiness, neutral Term weighted average, cumulative GWA, factual curriculum position, `AcademicEnrollmentEffect`, safe external results, lifecycle history, and unofficial record; no automatic sanction thresholds |
| Graduation, conferral, and TOR | Registrar Grades & Completion with Student Academics visibility | Graduation application, readiness review, recorded conferral, TALA Standard TOR preview, and Registrar issuance after readiness | Completion readiness, immutable degree record, issued/void/replacement/superseded transcript snapshots, and unofficial Student record |
| Imports, contextual exports, health, governance, and retention boundary | System Administrator and owning role workspaces | Fixed TALA CSV templates, locally evidenced health refresh, safe filters, and owning-record actions | Two contextual Clinic 6 CSVs, read-only audit/evidence views, explicit `Not checked by TALA`, and **Automatic retention disposal: Not provided in this MVP** |

## Table of Contents

The seven entries below are the complete standalone canonical product set. Journey-complete implementation planning and execution remain later, separately authorized gates.

1. [00. TALA System Definition Baseline](./00_system_definition_baseline.md)
2. [PRD 01. Identity, Access, and Public Entry — approved](./01_identity_access_public_entry.md)
3. [PRD 02. Application, Admission Decision, and Enrollment Readiness — approved](./02_application_admission_decision_enrollment_readiness.md)
4. [PRD 03. Academic Setup, Offerings, and Published Timetable — approved](./03_academic_setup_offerings_published_timetable.md)
5. [PRD 04. Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop — approved](./04_current_term_registration_official_enrollment.md)
6. [PRD 05. Teaching, Final Grades, Academic Records, Lifecycle, and Completion — approved](./05_teaching_grades_academic_records_completion.md)
7. [PRD 06. Accounts, Official Outputs, Operations, and Assurance — approved](./06_accounts_official_outputs_operations_assurance.md)

See [Replaced PRD Inputs](./_legacy/) for the preserved non-authoritative source set.
