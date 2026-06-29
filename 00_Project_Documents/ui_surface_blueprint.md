# TALA UI Surface Blueprint

## Purpose and Authority

This blueprint translates the approved PRD modules into implementation surfaces for the TALA MVP. It identifies where each workflow appears, which Filament v5 component should carry it, and which existing code may be reused.

Use this source order for every UI slice:

1. `00_Project_Documents/prd_modules/README.md`
2. Relevant files in `00_Project_Documents/prd_modules/`
3. This UI surface blueprint
4. `00_Project_Documents/architecture_specification.md`
5. Existing code and tests as reuse inventory

The PRD controls product behavior. Existing code is retained when it satisfies the current PRD and is adapted, replaced, or deferred when it does not.

## MVP UI Architecture

TALA uses the current three-panel baseline:

| Route | Product surface | Users | MVP use |
| --- | --- | --- | --- |
| `/` | Public Landing Page | Public visitors | Institutional information, admission guidance, notices, FAQ, Apply Online, and Sign In entry points |
| `/applicant` | Applicant Workspace | Applicants before handover | Account registration, application draft/submission, checklist, one required identity upload, status, and correction responses |
| `/student` | Student Hub | Active students after handover | Current profile, enrollment status, holds, schedule, COR, SOA, payments, released grades, and permitted student actions |
| `/admin` | Staff Workspace | Registrar, Accounting, Faculty, Academic Head, System Super Admin | Role-scoped operational queues, setup, review, approvals, reports, integrations, and audit |

MVP decisions:

1. Faculty remains inside `/admin` with role-scoped navigation and policies. TALA does not add a fourth panel for Faculty Workspace.
2. Registrar, Accounting, Academic Head, and System Super Admin share `/admin`. Navigation visibility improves usability; policies and action authorization enforce access.
3. Applicant and Student surfaces remain separate because handover changes both the account lifecycle and the authorized records.
4. Authentication UI stays in the Filament panels. Laravel Fortify remains the backend authentication contract for login, registration, verification, password reset, and custom response handling where already integrated.
5. The public landing page uses the existing Blade, Livewire, Tailwind, and TallStackUI stack.
6. Filament resources, pages, tables, forms, infolists, actions, filters, widgets, and notifications are the default authenticated UI toolkit.
7. Core Filament components are used before custom Blade or a new plugin. A plugin is introduced only when a required PRD behavior cannot be delivered cleanly with installed components.
8. Auth Designer is retained for Filament authentication screens. Applicant registration must keep the custom `RegisterApplicant` page through the package-supported page hook, not a generic replacement page.

## Current Rebaseline State

File presence does not mean a workflow is accepted. Use these states when preparing a slice.

| State | Meaning | Required action |
| --- | --- | --- |
| Confirmed baseline | Recorded as completed in the local sync tracker and supported by focused tests | Reuse and regression-test |
| Local work awaiting baseline review | Present in the dirty worktree or local progress record but not recorded as a completed synced slice | Review against the current PRD, run focused tests, then accept or revise |
| Reuse inventory | Existing model, resource, page, or test from earlier development | Audit behavior and authorization before reuse |
| Required surface | Required by the PRD but not yet confirmed in the current implementation | Create through a vertical slice |
| Deferred | Useful enhancement that is not required for MVP | Keep out of the implementation slice |

### Confirmed baseline

The local tracker records these completed areas:

1. Public landing page and Filament authentication routing.
2. Applicant Workspace shell and navigation.
3. Student Hub shell.
4. Applicant intake draft and submission.
5. Foundation authentication, email verification, password reset, role-aware landing, and panel-access baseline.
6. Admin Panel registration stabilization with explicit retained resources for Users, Roles, Activity Logs, and current accepted domain resources.
7. Academic, course, and curriculum foundation adaptation.
8. Admissions-to-student master backend adaptation.
9. Student Panel profile boot stabilization.
10. Term offering and resource foundation backend adaptation.
11. Registrar Term Offering Builder, including explicit Admin registration for `TermOfferingResource`.

### Local work awaiting baseline review

No additional UI/auth work is accepted merely because a file exists. Resource families outside the confirmed slices remain reuse inventory until the relevant vertical slice audits the model, migration, policy, panel registration, and tests against the current PRD.

### Reuse inventory

The staff panel already contains resources across admissions, academic setup, offerings, scheduling, enrollment, finance, COR, grades, imports, users, roles, settings, FAQ, and activity logs. Each vertical slice must inspect its relevant resource, model, service, policy, migration, and test before deciding to retain it.

## Native Filament Surface Rules

| PRD interaction form | Default Filament v5 implementation | MVP rule |
| --- | --- | --- |
| Record Form | Resource create/edit schema using `Section`, `Grid`, typed form fields, and policy-protected actions | Use for records with their own lifecycle |
| Focused Record Form | `Action` modal with only the decision fields, reason, authority, effective date, and evidence reference | Use for approve, reject, override, post, release, correct, waive, reverse, and lifecycle actions |
| Restricted Record Form | Authorized Resource or custom Page; secret fields are write-only or masked | Use for integration and security settings |
| Editable Table | Resource or relation-manager `Table` with filters and row `EditAction`; use inline columns only for simple, low-risk values | Use a custom page table when a workflow edits many related rows at once |
| Selection List | `Select`, `CheckboxList`, or a selectable filtered `Table` | Show eligibility, conflict, and capacity beside the choice when required |
| Checklist | Status `Table` for operational items; `CheckboxList` only for simple configuration | Checklist outcomes remain auditable records |
| Calendar / Date-Range Input | `DatePicker`, `DateTimePicker`, time fields, and availability/block tables | Use structured date/time inputs for MVP; do not add a full-calendar plugin |
| File Upload with Preview | Private `FileUpload`, metadata summary, validation state, and explicit confirmation | Public visibility is opt-in; official evidence remains access-controlled |
| Operational Queue / Review Table | Resource `Table` with default filters, status badges, row actions, and optional header/bulk actions | Default view shows the role's next work |
| Filter Form | Native table filters, including controlled selects and date ranges | Add saved-filter plugins only after repeated use proves the need |
| Generated Read-Only View | Resource view page with an infolist, read-only table, focused custom Filament Page, or authenticated Laravel printable Blade route | Corrections link back to the owning source record |

Filament v5 implementation conventions:

1. Actions use `Filament\Actions`.
2. Layout components use `Filament\Schemas\Components`.
3. Read-only record details use infolists where possible.
4. Business operations live in application actions or services, not Resource classes.
5. Laravel policies protect resources and record actions. Hidden navigation is not an authorization boundary.
6. Status badges use consistent semantic colors: warning for pending/action needed, success for accepted/posted/released, danger for rejected/blocked/voided, and info for advisory states.
7. Bulk actions are used only when the same authorized decision can safely apply to every selected record.
8. Native confirmation modals and Filament notifications provide action feedback.

## Panel and Navigation Map

### Applicant Workspace

Keep navigation task-based and small:

| Navigation item | Surface | Primary component |
| --- | --- | --- |
| Dashboard | Current application state and next action | Custom Filament Page with compact status sections |
| Application | Draft, validate, and submit application | Custom Filament Page with multi-section Form |
| Requirements | Checklist and allowed upload/reupload actions | Read-only Table plus private FileUpload action |
| Account | Profile, password, and verification | Filament auth/profile surfaces |

### Student Hub

Student Hub is a read-mostly workspace. Use focused custom Filament Pages rather than exposing staff CRUD resources.

| Navigation item | Surface | Primary component |
| --- | --- | --- |
| Dashboard | Current term, enrollment, finance gate, holds, and next actions | Custom Page with read-only sections and small stats |
| Profile | Student summary and allowed self-service fields | Infolist plus limited Form |
| Enrollment | Gate result, selected sections, and enrollment status | Read-only Table; selectable section table only during an authorized irregular-enrollment window |
| Schedule | Published class schedule | Read-only Table grouped by day; optional printable view |
| COR | Current official COR | Generated read-only page with an authenticated print/save-as-PDF action |
| Finance | Assessment, ledger, SOA, payment acknowledgement, and PayMongo action | Infolist and read-only Tables |
| Grades | Released grade history and student-facing marks | Read-only Table |
| Completion | Latest visible graduation eligibility snapshot | Read-only checklist Table |

### Staff Workspace

Use navigation groups to prevent the existing resource inventory from becoming one long menu:

| Group | Primary roles | Contents |
| --- | --- | --- |
| Admissions | Registrar | Applicant queue, checklist review, handover, profile correction, duplicate-profile resolution |
| Academic Setup | Registrar, Academic Head | Academic calendar, programs, course specifications, curricula, terms, grade outcomes, unit-load policy |
| Offerings & Scheduling | Registrar, Academic Head, Faculty where applicable | Term offerings, sections, delivery groups, rooms, faculty qualification/availability, scheduling demand, solver runs, publication |
| Enrollment | Registrar, Academic Head for exceptions | Gate queue, placement, reservations, academic exceptions, unit-load exceptions |
| Finance | Accounting | One Fee Rules table/form with Program and Term scope and peso amounts, assessments, payment evidence, OR mapping, ledger, accommodations, adjustments, reconciliation; assessment activation requires an exact Program-and-Term downpayment rule |
| Grades | Faculty, Registrar, Academic Head | Faculty rosters, late authorization, submission review, posting/release, INC completion, corrections |
| Student Records | Registrar, Accounting for owned holds | Student profile, holds, lifecycle changes, program shifts, graduation review |
| Reports & Audit | Authorized staff | Filtered operational reports, CSV export, audit log, integration events |
| System | System Super Admin | Users, fixed canonical role assignment, settings, email templates, integration configuration |

Staff dashboards show a small number of actionable counts and links. The operational table remains the source for work; charts are deferred unless a PRD report requires a comparison that a table cannot express clearly.

## TAL-60 Realignment Decisions

| Area | Decision | Reason and MVP benefit | Implementation risk | Future-task effect |
| --- | --- | --- | --- | --- |
| Fortify and Filament auth | Keep current setup | Fortify already supplies backend auth contracts while Filament panels own the login, registration, password reset, and verification UI. This keeps the three workspace entry points proven by tests. | Low if response contracts and panel route names remain covered. | Future auth changes should extend focused response/panel tests rather than add public Fortify views. |
| Applicant registration and Auth Designer | Use existing plugin | Auth Designer is already installed on the panels and the Applicant panel preserves `RegisterApplicant` with the package page hook. This keeps branded auth without losing applicant role assignment. | Medium if future package updates change page-extension APIs. | Keep applicant registration regression tests in every auth/panel slice. |
| Staff operational workflows | Use native Filament | Resources, tables, forms, actions, infolists, relation managers, filters, and widgets cover the MVP staff workflows without custom JavaScript. | Medium only when old inventory resources point at stale schema. | Each domain slice must explicitly register only accepted resources and leave stale families deferred. |
| Student Hub and Applicant Workspace pages | Use native Filament pages | Student and applicant surfaces are task-focused panels, not generic CRUD portals. Filament pages composed from forms, tables, infolists, and actions keep authorization server-side. | Low to medium, depending on source-record readiness. | Future learner-facing slices should build read-mostly pages after the owning staff source records exist. |
| Calendar-like scheduling views | Defer plugin/package | MVP scheduling review is table-first; date/time inputs and validation tables are sufficient. A timetable/calendar view may supplement after the canonical table path is stable. | Low for MVP; adding a plugin early would create maintenance and test cost. | TAL-61 and scheduling slices must not start with drag-and-drop calendar UI. |
| TallStackUI | Keep current setup | TallStackUI is useful for the public Blade/Livewire surface already in use. Authenticated work remains Filament-first. | Low if it stays out of Filament panel implementation decisions. | Use TallStackUI only for non-Filament Blade/Livewire surfaces with a documented need. |
| Activity Log plugin | Use existing plugin | The existing Activity Resource gives System Super Admin audit visibility aligned with Module 13. | Low if activity tables remain migrated and authorization is retained. | Official-record slices should write audit events and expose them through the accepted audit surface. |
| Additional UI/plugins | Defer plugin/package | No current PRD requirement proves a need for saved-filter, import, calendar, dashboard, permissions, or custom UI plugins before native Filament is tried. | Low; deferral preserves dependency discipline. | Future plugin proposals require a capability gap, compatibility check, maintenance cost, and focused tests. |

## Module-to-UI Implementation Map

| Module | MVP surface | Native Filament implementation | Existing-code disposition |
| --- | --- | --- | --- |
| 01 Product Intent & Architecture | Public entry plus three authenticated panel shells | Existing public page and Panel Providers | Reuse confirmed baseline |
| 02 Identity, Access & Workspaces | Panel auth, profile, role-aware landing, fixed-role access | Panel auth features, policies, `canAccessPanel`, role-scoped navigation | Reuse confirmed baseline; retain three panels |
| 03 Admissions & Student Handover | Applicant application, requirements, Registrar review, handover, student master record, corrections, duplicate resolution | Applicant custom Pages; staff queue Resources; focused Actions; Student Profile Resource | Intake is confirmed; current profile/correction/duplicate work requires baseline review |
| 04 Academic Setup | Calendar, programs, course specifications, curricula, terms, grade outcomes, policy values | Resources, relation managers, date/time forms, import Page, readiness infolists | Audit existing resources; add only missing PRD fields and workflows |
| 05 Term Offerings & Resources | Generated offerings, special offerings, sections, faculty, rooms, capacity | Resources and relation managers; filtered selection Tables; date/time availability forms | Audit existing offerings/resource Resources before reuse |
| 06 CP-SAT Scheduling | Demand readiness, solver run, candidate review, publication, revision | Schedule run Resource, candidate relation manager, validation infolist, focused publish/revision Actions | Reuse existing run/candidate inventory after contract audit; table view is canonical |
| 07 Enrollment Gate | Gate queue, placement, reservations, exceptions, official enrollment result | Enrollment Resource, gate infolist, selectable sections Table, focused override/exception Actions | Audit current enrollment Resources and service behavior |
| 08 Finance, Ledger & PayMongo | Fee matrix, assessment, payment evidence, OR mapping, ledger, reconciliation, student finance view | One Accounting Fee Rules Resource with editable table/form, focused assessment Actions, allocation Table, Student Hub read-only Pages | Audit existing finance Resources; preserve ledger as source of truth and require exact Program-and-Term downpayment configuration for activation |
| 09 COR | Current generated COR | Student Hub custom Page, staff-accessible read-only source summary, authenticated printable Blade route, and output log action | Exclude public verification/QR/token inventory for MVP; generate content from owning enrollment, schedule, assessment, and ledger records |
| 10 Grades | Faculty roster entry, Registrar review/release, late authorization, INC completion, correction, student history | Custom roster Page with editable Table; staff review Resource; focused Actions; Student Hub read-only Table | Existing grade Resources are inventory; do not use generic Grade CRUD for faculty encoding |
| 11 Student Lifecycle | Holds, approved lifecycle changes, program-shift credit evaluation, graduation review | Staff Resources and focused Actions; impact infolists; review-batch and checklist Tables | Audit existing holds/profile data; add lifecycle workflows vertically |
| 12 Student Hub | Read-only current records plus permitted profile/evidence/payment/enrollment actions | Custom Filament Pages composed from infolists, Tables, Forms, and Actions | Shell is confirmed; profile work requires baseline review; remaining pages are required surfaces |
| 13 System Admin, Reports & Audit | Users, canonical role assignment, typed settings, imports, filtered reports, CSV export, audit, integrations | Resources, native filters, Export Actions or streamed CSV, activity-log Resource, restricted settings Forms | Reuse installed activity-log support; audit Users/Roles/Settings/Import inventory |

## Scheduling UI Baseline

Scheduling remains table-first because validation and exception details are easier to review reliably in rows than through drag-and-drop blocks.

| Scheduling step | Surface | Component choice |
| --- | --- | --- |
| Academic calendar and break blocks | Term-scoped setup forms | DatePicker/DateTimePicker and blocked-period Table |
| Room and faculty availability | Source-record forms | Resource Forms plus availability Tables |
| Term offerings and delivery groups | Setup Resources | Resource and relation-manager Tables |
| Scheduling demand | Generated review queue | Filtered read-only/edit-limited Table with source links |
| Readiness check | Validation result | Infolist summary plus missing/invalid input Table |
| Solver run | Run record | Create Action/Form, confirmation, status badge, and polling read-only view |
| Candidate review | Candidate rows | Relation-manager Table with filters, warnings, and validation status |
| Infeasible result | Diagnostic review | Exception Table linking to authoritative source records |
| Manual override | Controlled decision | Focused Action modal with replacement assignment and reason |
| Publication | Controlled decision | Read-only comparison followed by confirmed Action |
| Published revision | Controlled decision | Focused Action modal with impact preview and validation result |
| Student/faculty schedule | Authorized output | Read-only Table grouped by day and printable view |

For MVP, TALA does not require a drag-and-drop timetable, FullCalendar plugin, generic constraint builder, or user-editable scoring weights. An optional timetable visualization may be added later without replacing the candidate review table or validation path.

## Imports, Reports, Notifications, and Plugins

### Imports

Course Specification and Curriculum imports use a custom Filament Page composed from native `FileUpload`, validation summaries, a preview Table, and an explicit Draft-creation Action. This preserves the PRD's versioned-template, full-preview, and all-errors-block-posting behavior. The installed Laravel Excel package handles file parsing; no additional import plugin is required.

### Reports

MVP reports are filtered operational Tables with CSV export. Use native Filament filters and an authorized export Action. Analysis, pivoting, and chart building occur outside TALA. Sensitive exports capture purpose and create an export log.

### Notifications

Filament notifications provide immediate success, warning, and error feedback after an action. Critical asynchronous product notifications use email and recorded delivery metadata. MVP does not include a persistent in-app notification center.

### Plugin policy

Approved baseline:

1. Core Filament v5 for authenticated UI.
2. Existing Auth Designer integration for Filament panel authentication screens, preserving the custom Applicant registration page.
3. Existing TallStackUI components for the public Blade/Livewire surface.
4. Existing `pxlrbt/filament-activity-log` integration for authorized audit visibility when it satisfies Module 13.
5. Existing Laravel Excel package for fixed-template import parsing and CSV/Excel support where already compatible.

Do not add a calendar, saved-filter, dashboard, permissions, import, or custom UI plugin until a vertical slice documents a required capability gap, compatibility check, maintenance cost, and focused test plan.

## Vertical Slice Contract

Before changing UI code, record the following for one user-visible capability:

1. PRD module and exact workflow.
2. Primary user and panel.
3. User-visible starting state and successful outcome.
4. Existing files to retain, adapt, replace, or defer.
5. Owning source records and read-only dependent views.
6. Filament Resource, Page, Table, Form, Infolist, Action, Filter, or Widget required.
7. Fields, columns, filters, empty state, blocker state, and success feedback.
8. Authorization policy and action-level permission.
9. Audit event and notification, when required.
10. Focused PHPUnit feature tests.
11. PRD, blueprint, architecture, and tracker updates required after acceptance.

A slice is accepted only after its current behavior matches the PRD, focused tests pass, and its status is recorded in the active planning/sync workflow.
