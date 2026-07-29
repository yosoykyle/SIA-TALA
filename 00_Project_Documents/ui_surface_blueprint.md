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
| `/applicant` | Applicant Workspace | Applicants before handover | Account registration, three-step application draft/submission, policy-driven private digital uploads, checklist, status, and correction responses |
| `/student` | Student Hub | Active students after handover | Current profile, enrollment status, holds, schedule, COR, SOA, payments, released grades, and permitted student actions |
| `/admin` | Staff Workspace | Registrar, Accounting, Faculty, Academic Head, System Super Admin | Role-scoped operational queues, setup, review, approvals, reports, integrations, and audit |

MVP decisions:

1. Faculty remains inside `/admin` with role-scoped navigation and policies. TALA does not add a fourth panel for Faculty Workspace.
2. Registrar, Accounting, Academic Head, and System Super Admin share `/admin`. Navigation visibility improves usability; policies and action authorization enforce access.
3. Applicant and Student surfaces remain separate because handover changes both the account lifecycle and the authorized records.
4. Authentication UI stays in the Filament panels. Laravel Fortify remains the backend authentication contract for login, registration, verification, password reset, and custom response handling where already integrated.
5. The public landing page uses an isolated Bootstrap v5.3.3 Blade layout with landing-only public assets. Authenticated work remains Filament-first and does not load Bootstrap globally. The page preserves the Apply, Sign In, About Us, Location, and FAQ sections; begins with a semantic public-entry explanation of the Applicant Workspace, Student Hub, and Staff Workspace; uses literal headings, keyboard focus, reduced-motion behavior, and responsive reflow; and retains the approved navigation style and bottom blur strip. Standalone content CTAs use explicit action containers for predictable separation from explanatory copy; global button margins are not used because they would disrupt navigation and grouped controls. The `/` FAQ accordion is dynamic and admin-managed: it renders published `FaqEntry` records in the administrator-controlled display order and shows each record's configured category as a compact orientation label. `FaqEntryResource` is a registered System Super Admin surface under System Administration; new entries append automatically, and administrators change order through the native reorderable table rather than entering numeric positions.
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

### System-wide failure and workspace identity

TALA uses one product identity across its four entry surfaces: the public site, `TALA Applicant Workspace`, `TALA Student Hub`, and `TALA Staff Workspace`. The Staff Workspace label is the canonical name for `/admin`; technical panel IDs and route prefixes do not appear as user-facing product names.

Browser requests that end in an HTTP failure use a shared TALA presentation contract:

| Failure | User-facing meaning | Recovery guidance |
| --- | --- | --- |
| `403` | The signed-in account is not permitted to open the page or action | Return to that account's authorized workspace, or explicitly confirm sign-out before choosing another account |
| `404` | The page, link, or record is unavailable | Check the address and reopen the item through workspace navigation |
| `419` | The protected session expired | Return, sign in again, and repeat the action once |
| `429` | Requests were temporarily limited | Wait before retrying and avoid repeated refresh or submission |
| `500` | An unexpected application error prevented completion | Retry once; if it persists, report the action to the system administrator |
| `503` | The service is temporarily unavailable | Wait and retry later |
| Other `4xx` / `5xx` | Safe client-error or service-error fallback | Return to TALA and follow the stated recovery step |

These pages identify the status in text, never rely on color alone, expose no internal exception message, remain usable without unnecessary scrolling at a narrow viewport, and provide keyboard-visible recovery actions with at least a 44-pixel target. An authenticated wrong-workspace response never silently ends the session: its primary action returns to the account's authorized workspace, while `Use another account` requires explicit confirmation and a protected POST logout. Guests receive the ordinary public-home action. Laravel continues to own content negotiation: JSON/API requests receive framework JSON errors, while the branded templates apply to browser HTML responses. Domain-specific validation, action notifications, and Livewire errors remain on their owning surfaces rather than being replaced by generic HTTP pages.

## Panel and Navigation Map

### Applicant Workspace

Keep navigation task-based and small:

| Navigation item | Surface | Primary component |
| --- | --- | --- |
| Home | Current application state and next action | Custom Filament Page with compact status sections |
| Application | Draft, validate, and submit personal data and all applicable digital requirements | Custom Filament Page with native three-step Wizard: Personal Information, Required Documents, and Review and Submit |

`Requirements` remains a contextual page reached from the current or historical Application when a checklist exists or Registrar feedback requires action. Filament account, password, and verification controls remain available through the account menu; they are not a third business task.

The public landing page and registration surface read the active institution-scoped Admissions calendar windows. When no active term is accepting applications, public application links become a clear `Applications are currently closed` state while Applicant Sign In remains available. Direct registration and first-intake creation fail closed; existing accounts, drafts, submitted applications, and allowed correction work remain accessible.

The Dashboard and Requirements pages always explain their purpose, current state, and next available action, including before an intake or checklist exists. Empty-state illustrations use native Filament icons with explicit bounded dimensions, and custom action groups retain visible separation from explanatory copy at narrow and desktop widths. The Wizard validates each current step before Next advances, placing field errors beside the relevant input. `Save Draft` accepts partial raw form state, validates supplied values and minimum scope through the intake service, and reports a persistent plain-language error without silently stalling. Final-submission fields remain visibly marked and are revalidated by the intake service. An existing draft stays editable after admissions close, but final submission clearly states that the selected term is closed and is blocked by the service.

The Personal Information step separates `Parent / Guardian Contact` from `Applicant Education`; `Most Recent School Attended` belongs to the applicant. The applicant selects an admission term and program, not a personal delivery modality. Online or Face-to-Face is assigned later to each subject offering, and the Student Hub derives the student's course-delivery mix from the published enrolled rows. Applicant and parent/guardian mobile fields accept the V1 Philippine local format of exactly 11 digits beginning with `09`. The applicant address stays structured while the parent or guardian address uses the existing stored address value. A transient `Same as applicant address` checkbox synchronizes the structured applicant address into a non-editable guardian field while selected. Clearing the checkbox returns to manual entry and removes only auto-copied state or restores independently entered text; it does not add a persisted preference or change the intake schema.

Admission requirement policies remain the source of truth for requirement fields. The Wizard renders a private upload only for `DIGITAL_UPLOAD`, identifies `PHYSICAL_COPY` as `Bring to the Registrar`, and explains `METADATA_ONLY` as staff-tracked. Applicant and Registrar presentations translate stored requirement, evidence-method, blocking, verification, and status codes into human labels. Dashboard stays compact; Requirements owns per-item instructions, Registrar feedback, latest authorized file, physical-submission guidance, and rejected-file replacement.

The Applicant Workspace resolves one current nonterminal intake and preserves earlier terminal intakes as read-only application history. An account may have no more than one intake for the same academic term and no more than one nonterminal intake at a time. After withdrawal, a different-term intake may begin only during that term's open Admissions window; a same-term retry remains Registrar-controlled. The Applicant Dashboard uses one vertical reading order across supported widths: current application or no-active-application guidance, exact next action, responsive application history, then the requirement summary relevant to the current intake. History rows show term, program, admission category, status, and the relevant submitted or withdrawn date with an authorized View action; private withdrawal reasons remain in the detail presentation rather than the compact list.

A valid withdrawal requires a reason and is available only for a Draft or unreviewed Pending intake before approval or handover. Applicant Dashboard and Requirements surfaces distinguish a withdrawn draft from a withdrawn submitted intake, show the terminal state, date, reason, and Registrar guidance, and never describe an unsubmitted draft as submitted. Opening My Application directly resumes an existing draft with a saved-draft cue; no redundant continuation-confirmation modal is used. The Registrar list shows status and withdrawal date without exposing the reason; the detail surface shows the withdrawal date, actor, and reason. Withdrawal soft-archives the intake for authorized history. Exact applicant-record retention periods remain institution-configured under PRD Section 13.7; the Applicant Workspace does not display an invented expiry countdown and V1 does not automate applicant deletion.

### Student Hub

Student Hub is a read-mostly workspace. Use focused custom Filament Pages rather than exposing staff CRUD resources.

| Navigation item | Surface | Primary component |
| --- | --- | --- |
| Home | Active term, official Student Profile status, confirmed academic standing, system progression review, ledger balance, holds, and next actions | Custom Page with plain-language read-only stats and mobile-stacked hold details; the current official record stays visibly separate from any computed recommendation, and the responsible office is named when action is required |
| Enrollment | Current enrollment decision, gate result, proposed or confirmed sections, COR availability, and next action | Focused custom Page; contextual links open the published schedule, current COR, and authorized enrollment detail |
| Academics | Published class schedule, released grades, academic standing, holds affecting academic work, and completion review | Focused custom Page with vertically ordered read-only summaries and contextual detail links |
| Finance | Lead with Current Amount Due, Payment Status, What to do next, Responsible Office, and Official Receipt Status; explain successful/cancelled checkout returns without treating redirects as payment proof; keep assessment, charge, schedule, ledger, attempt, acknowledgement, and accommodation evidence as collapsed detail | Focused custom Page using responsive native Sections, infolist entries, and authorized Actions |
| Profile | Official student summary and allowed self-service contact fields | Read-only grouped record plus limited Form; status codes are converted to familiar labels, while identity, program, curriculum, lifecycle, grades, finance, and enrollment records remain staff-owned |

The existing Class Schedule, COR, Grades, Holds, Academic Status, and Completion pages remain policy-protected projections. They are contextual destinations from Enrollment, Academics, Home, or Profile and do not remain peer primary-navigation items.

### Staff Workspace

Use navigation groups to prevent the existing resource inventory from becoming one long menu:

The Staff Dashboard begins with a role-owned work summary rather than framework or developer information:

- Registrar receives **Registrar Operating Order**, with six numbered readiness stages over existing source records: Academic Period; Active Curricula; Offerings & Sections; Teaching Resources; Scheduling Demands; and Published Timetable.
- Accounting receives **Accounting Work**, linking the established Fee Setup → Student Accounts → Payment Exceptions → Reports flow.
- Faculty receives **My Faculty Work**, linking Assigned Schedule, Grade Rosters, and My Unavailable Times.
- Academic Head receives **Academic Oversight**, linking Academic Readiness, Class Planning, Grade Review, and Reports.
- System Super Admin receives **System Administration**, linking Accounts, Public FAQs, Integration Status, and Governance & Audit.

Each summary uses authoritative counts or readiness states and provides orientation links only. It does not merge records, execute a domain action, run scheduling, publish a timetable, post finance, or grant permissions beyond the user's policies. The generic Filament framework-information widget is not an institutional task and is not shown.

### Lean-MVP capability and navigation register

This register is the canonical presentation disposition for the currently registered MVP surfaces. Registration and direct-route authorization remain independent of sidebar placement.

| Owner / primary task | Named surfaces and capabilities | Disposition | Normal entry and preservation rule |
| --- | --- | --- | --- |
| Public entry | Landing page, published notices/FAQ, location and institutional content, Apply/Sign In routes | Primary | Public landing page; content remains admin-curated where configured |
| Public recovery | Branded 403, 404, 419, 429, 500, and 503 HTML responses | Contextual | Reached only on failure; Laravel retains JSON/API negotiation |
| Applicant Home / Application | Applicant Dashboard, Application Wizard, application history, withdrawal, status and next-action guidance | Primary | Home or Application |
| Applicant Application | Requirements checklist, Registrar feedback, digital evidence view/reupload, physical-document instructions | Contextual | Current or historical Application record; direct route remains applicant-authorized |
| Applicant account | Profile, password reset, email verification | Contextual | Filament account/auth controls |
| Student Home | Student Dashboard and next-action summary | Primary | Home |
| Student Enrollment | Enrollment page and irregular proposal flow | Primary | Enrollment |
| Student Enrollment | COR and Class Schedule projections | Contextual | Enrollment record or Academics; outputs remain read-only and access-logged |
| Student Academics | Academics task center | Primary | Academics |
| Student Academics | Grades, Holds, Academic Status/Lifecycle, Completion | Contextual | Academics or Profile |
| Student Finance | Finance summary, checkout action, SOA, billing slip, payment acknowledgement | Primary | Finance; generated outputs remain contextual and access-logged |
| Student Profile | Profile and permitted contact updates | Primary | Profile |
| Registrar Academic Readiness | Academic Readiness and combined Curriculum review | Primary | Academic Readiness |
| Registrar Academic Readiness | Academic Years, Terms, Academic Calendar Windows, Programs, Courses, Course Specifications, Curriculum Versions, Import Batches | Contextual | Academic Readiness and Curriculum review links |
| Registrar Admissions | Admissions Work Queue / Applicant Intake | Primary | Admissions |
| Registrar Admissions | Admission Requirement Policies, Duplicate Profile Resolution, checklist/evidence and handover records | Contextual | Applicant record or admissions queue |
| Registrar Class Planning | Class Planning | Primary | Class Planning |
| Registrar Class Planning | Term Offerings, Sections, Rooms, Faculty Qualifications, Faculty Load Overrides, Calendar Events, Scheduling Demands, Schedule Generation Runs, official Section Meetings | Contextual | Class Planning stage links; solver/provider diagnostics are secondary evidence |
| Registrar Students & Enrollment | Enrollment and Student Profile | Primary | Students & Enrollment |
| Registrar Students & Enrollment | Student Lifecycle Changes and record-owned holds/history | Contextual | Student Profile |
| Registrar Grades & Completion | Grade Rosters and Graduation Review Batches | Primary | Grades & Completion |
| Registrar Reports | Reports and authorized exports | Primary | Reports |
| Accounting Student Accounts | Assessments and account-centered finance review | Primary | Student Accounts |
| Accounting Student Accounts | Payments, Ledger Entries, Accounting Adjustments, Financial Accommodations | Contextual | Student account detail |
| Accounting Payment Exceptions | PayMongo Reconciliation and provider/manual evidence requiring review | Primary | Payment Exceptions |
| Accounting Payment Exceptions | Payment Attempts and retained provider-event evidence | Evidence-only | Payment exception detail |
| Accounting Fee Setup | Fee Rules | Primary | Fee Setup |
| Faculty work | Faculty Schedule, Faculty Grade Roster, own Calendar Events / unavailable blocks | Primary | My Schedule, Grade Rosters, My Unavailable Times |
| Academic Head work | Academic Readiness/Class Planning oversight, authorized grade or progression approval, Reports | Primary | Academic Oversight, Approvals, Reports |
| System administration | Users and Roles | Primary | Users & Access |
| System administration | FAQ Entries | Primary | Public Content |
| System administration | System Settings and Integration Status | Primary | System Health; secret values never render |
| Governance | Reports & Audit | Primary | Governance & Audit |
| Governance | Activity Logs, Operational Events, Output Access Logs, Disposal Reviews | Evidence-only | Governance/Audit questions or owning record |
| Framework diagnostics | Generic Filament information widgets | Retired | No institutional purpose; remove from panel registration |
| Deferred product work | Capabilities explicitly routed to TAL-98, TAL-99, TAL-100, TAL-175, or another approved future issue | Deferred | Not presented as active MVP work until its owning issue is approved |

#### Executable capability inventory

This is the code-level inventory behind the register above. A class appearing here does not make it a peer navigation item: **Primary** classes are task entries, **Contextual** classes are source records or projections reached from a task, and **Evidence-only** classes answer audit or exception questions. Registration and authorization stay in code; this inventory makes the presentation decision reviewable and prevents an implemented boundary from becoming an unexplained or forgotten surface.

| Workspace / owner | Executable boundaries | Presentation disposition |
| --- | --- | --- |
| Shared staff entry and orientation | `Dashboard`, `StaffRoleWorkspaceOverviewWidget`, `RegistrarOperationalReadinessWidget`, `AccountWidget` | Primary dashboard plus role-owned orientation; account control is Contextual |
| Registrar and Academic Head task centers | `AcademicReadiness`, `ClassPlanning`, `GradesAndCompletion`, `AcademicApprovals`, `ReportsAudit` | Primary where permitted by role |
| Faculty task centers | `FacultySchedule`, `FacultyGradeRoster` | Primary for assigned schedule and grade work |
| Accounting exception task center | `PayMongoReconciliation` | Primary for unresolved provider or manual-payment exceptions |
| System administration task center | `IntegrationStatus` | Primary system-health summary; source settings remain Contextual |
| Admissions records | `ApplicantIntakeResource`, `AdmissionRequirementPolicyResource`, `DuplicateProfileResolutionResource` | Applicant Intake is the Primary queue; policy and duplicate records are Contextual |
| Academic-period and curriculum records | `AcademicYearResource`, `TermResource`, `AcademicCalendarWindowResource`, `ProgramResource`, `CourseResource`, `CourseSpecificationResource`, `CurriculumVersionResource`, `ImportBatchResource` | Contextual source records reached from Academic Readiness |
| Class-planning records | `TermOfferingResource`, `SectionResource`, `RoomResource`, `FacultyQualificationResource`, `FacultyTermLoadOverrideResource`, `CalendarEventResource`, `SchedulingDemandResource`, `ScheduleGenerationRunResource`, `SectionMeetingResource` | Contextual planning, solve, review, and publication records reached from Class Planning |
| Enrollment and student records | `EnrollmentResource`, `StudentProfileResource`, `StudentLifecycleChangeResource` | Enrollment and Student Profile are Primary operational records; lifecycle change is a Contextual consequential action/history record |
| Grades and completion records | `GradeRosterResource`, `GraduationReviewBatchResource` | Contextual records reached from role-owned grade or completion work |
| Finance records | `FeeRuleResource`, `AssessmentResource`, `PaymentResource`, `LedgerEntryResource`, `AccountingAdjustmentResource`, `FinancialAccommodationResource`, `PaymentAttemptResource` | Fee Rule and Assessment support Primary work; posting and adjustment records are Contextual; attempts are Evidence-only |
| Public-content and access records | `UserResource`, `RoleResource`, `FaqEntryResource`, `SystemSettingResource` | Primary administration tasks, with Roles and Settings reached contextually from their task centers |
| Governance records | `ActivityResource`, `OperationalEventResource`, `DisposalReviewResource` | Evidence-only sources reached from Governance & Audit |

| Applicant / Student boundary | Executable boundaries | Presentation disposition |
| --- | --- | --- |
| Applicant account and intake | `RegisterApplicant`, `Dashboard`, `Application`, `Requirements`, `AccountWidget` | Registration, Home, and Application are Primary; Requirements and account control are Contextual |
| Student task centers | `Dashboard`, `Enrollment`, `Academics`, `Finance`, `Profile` | Primary role navigation |
| Student projections | `CorView`, `ScheduleView`, `GradesView`, `HoldsView`, `LifecycleView`, `Completion` | Contextual destinations from Enrollment, Academics, Home, or Profile |
| Student orientation widgets | `StudentPriorityNoticeWidget`, `StudentProfileOverviewWidget`, `ActiveHoldsWidget`, `AccountWidget` | Contextual summaries; authoritative records remain the owning task or staff record |

| Output or communication boundary | Executable boundary | Contract |
| --- | --- | --- |
| Controlled operational CSV | `ExportOperationalReport` | Role-authorized, allowlisted, purpose-recorded export; not a separate navigation feature |
| Certificate of Registration | `CorPrintController` | Owner/role-authorized read-only output with access evidence |
| Student finance outputs | `BillingSlipController`, `FinanceStatementController`, `PaymentAcknowledgementController` | Source-derived billing slip, statement, and payment acknowledgement; never proof of an unverified provider redirect |
| Published schedules | `FacultySchedulePrintController`, `StudentSchedulePrintController` | Source-derived official schedule outputs after publication |
| Applicant status mail | `ApplicantStatusChangedMail` | Queued cross-role status communication using the authoritative intake state |
| Finance mail | `PaymentPostedMail` | Queued notification only after authoritative payment posting |
| Schedule mail | `ScheduleReleasedMail`, `ScheduleRevisionMail` | Queued publication or revision communication from official schedule state |
| Integration diagnostic mail | `TestConnectionMail` | Restricted system-health diagnostic, not a normal user journey |
| In-app notification | `GeneralSystemNotification` | Authorized immediate guidance; it does not replace owning records or email delivery evidence |

| Custom Blade family | View inventory | Presentation disposition |
| --- | --- | --- |
| Public entry layouts | `welcome.blade.php`, `layouts/landing-bootstrap.blade.php`, `layouts/public.blade.php` | Primary public entry and its isolated layouts |
| Applicant workflow | `filament/applicant/pages/application.blade.php`, `filament/applicant/pages/application-submit-action.blade.php`, `filament/applicant/pages/dashboard.blade.php`, `filament/applicant/pages/requirements.blade.php` | Primary Application/Home views plus Contextual requirements projection |
| Staff task centers | `filament/pages/academic-readiness.blade.php`, `filament/pages/class-planning.blade.php`, `filament/pages/academic-approvals.blade.php`, `filament/pages/grades-and-completion.blade.php`, `filament/pages/integration-status.blade.php`, `filament/pages/pay-mongo-reconciliation.blade.php`, `filament/pages/reports-audit.blade.php` | Primary role task-center views |
| Applicant handover evidence | `filament/admin/applicant-intakes/handover-preview.blade.php` | Contextual review evidence inside the authoritative applicant record |
| Student task and projection views | `filament/student/pages/academics.blade.php`, `filament/student/pages/profile.blade.php`, `filament/student/pages/completion.blade.php`, `filament/student/pages/generic-infolist.blade.php`, `filament/student/pages/generic-table.blade.php` | Primary task views or Contextual reusable projections as owned by their Page classes |
| Official output layout and documents | `components/official-output-layout.blade.php`, `cor/print.blade.php`, `finance/billing-slip.blade.php`, `finance/statement.blade.php`, `finance/payment-acknowledgement.blade.php`, `schedules/print.blade.php` | Contextual authenticated outputs; the shared layout does not own source data |
| Branded mail views | `mail/applicant-status-changed.blade.php`, `mail/payment-posted.blade.php`, `mail/schedule-released.blade.php`, `mail/schedule-revision.blade.php` | Cross-role communication generated from authoritative state |
| Error view family | `errors/layout.blade.php`, `errors/4xx.blade.php`, `errors/5xx.blade.php`, `errors/403.blade.php`, `errors/404.blade.php`, `errors/419.blade.php`, `errors/429.blade.php`, `errors/500.blade.php`, `errors/503.blade.php` | Contextual recovery only |

The public boundaries are the Bootstrap landing page, `/home` compatibility redirect, Filament/Fortify login, registration, verification, reset, and recovery surfaces, and the branded HTML error responses `403`, `404`, `419`, `429`, `500`, and `503`. Error pages remain contextual recovery surfaces and retain Laravel's content-negotiated JSON behavior for API requests.

**Ground-truth verdicts for this inventory:** each retained boundary above is **Aligned** at the registration, authorization, and presentation-disposition level unless named here. The missing Academic Head → Class Planning, Users → Roles, Integration Status → System Settings, and Governance → audit/event/disposal contextual links were **Gaps** and are corrected in D5E1D1. The generic framework-information widget was a **Superseded remnant** and remains retired. Items routed to named future issues are intentionally **Deferred**, not missing MVP capabilities. No D5E1D1 capability is classified as **Required-but-unbuilt**, and no unresolved product-authority **Conflict** was found at this inventory level. Behavioral completeness and presentation correctness inside the retained journeys are deliberately owned by D5E1D2–D7; this inventory does not pre-label those later findings as aligned.

The role-owned primary navigation is:

| Role | Primary navigation |
| --- | --- |
| Applicant | Home; Application |
| Student | Home; Enrollment; Academics; Finance; Profile |
| Registrar | Home; Academic Readiness; Admissions; Class Planning; Students & Enrollment; Grades & Completion; Reports |
| Accounting | Home; Student Accounts; Payment Exceptions; Fee Setup; Reports |
| Faculty | Home; My Schedule; Grade Rosters; My Unavailable Times |
| Academic Head | Home; Academic Oversight; Approvals; Reports |
| System Super Admin | Home; Users & Access; Public Content; System Health; Governance & Audit |

### Demonstration-critical cross-role journeys

| Journey | Primary operating sequence | Contextual evidence and consumer |
| --- | --- | --- |
| Admissions and handover | Applicant Application → Registrar Admissions → decision and handover | Requirements/evidence, duplicate decision, Student Profile |
| Timetable publication | Academic Readiness → Class Planning → solve/review/publish | Offering/resource/demand/run evidence; Faculty and Student schedules |
| Enrollment and COR | Student or Registrar Enrollment → gates → placement → official enrollment | Reservation/proposal and exception evidence; COR and enrollment history |
| Finance clearance | Fee Setup → Student Accounts or Payment Exceptions → verified posting/clearance | Assessment/payment/ledger/provider evidence; Student Finance and receipt outputs |
| Grades | Faculty Grade Rosters → Registrar review/post/release | Grade history; Student Academics |
| Lifecycle and completion | Registrar Student Profile → lifecycle/progression/completion action | Academic Head approval when required; Student Profile/Academics projection |

**Academic Readiness** is the only primary Academic Setup navigation item. It lists each Program, the pending revision that needs action (or the Active curriculum when no pending revision exists), row count, plain-language readiness, exact blocker, and next action. Registrar actions create a Draft or open the existing Curriculum review; Academic Head sees the same truth without mutation actions. Academic Years, Terms, Calendar Windows, Programs, Courses, Course Specifications, Curriculum Versions, and Import Batches remain authorized source-record routes reached contextually from the workbench.

The Curriculum review presents every entry in one ordered table with curriculum-source facts (course code, title, and units), Course Specification revision/state/modalities, curriculum placement (year, term, sequence, and requirement group), readiness, exact blocker, and next action. Manual Draft creation redirects to this review, and a posted Curriculum Import Batch opens the same review. Registrar table actions add an entry, correct its placement, and complete the linked Draft Course Specification and components without leaving the workbench; those actions update the existing authoritative records through the academic-setup service layer. Full source-record forms remain available contextually, and lifecycle services still own approval and activation. The UI does not duplicate or merge the underlying records.

| Group | Primary roles | Contents |
| --- | --- | --- |
| Admissions | Registrar | Applicant queue, checklist review, handover, manual student profile updates (Admin Override), duplicate-profile resolution |
| Academic Setup | Registrar, Academic Head | One Academic Readiness task entry with contextual access to academic periods, programs, course specifications, curricula, and import evidence |
| Offerings & Scheduling | Registrar, Academic Head, Faculty where applicable | Term offerings, sections, delivery groups, rooms, faculty qualification/availability, scheduling demand, solver runs, publication |
| Enrollment | Registrar, Academic Head for exceptions | Plain-language status and next-step queue, placement, reservations, academic exceptions, unit-load exceptions |
| Finance | Accounting | One Fee Rules table/form with Program and Term scope and peso amounts, assessments, payment evidence, OR mapping, ledger, accommodations, adjustments, signed-webhook reconciliation, and bounded provider-checkout recovery; assessment activation requires an exact Program-and-Term downpayment rule |
| Grades | Faculty, Registrar, Academic Head | Faculty rosters, late authorization, submission review, posting/release, INC completion, corrections |
| Student Records | Registrar, Accounting for owned holds | Student profile, holds, lifecycle changes, program shifts, graduation review |
| Reports & Audit | Authorized staff | Role-authorized fixed report catalog, controlled filters, mobile-stacked table, audited UTF-8 CSV export, audit log, and integration events |
| System | System Super Admin | Users, fixed canonical role assignment, a read-only versioned settings registry with explicit operational/superseded/dormant disposition and verified consumer/effect, code-defined notification content, and restricted read-only integration status |

Faculty sees **My Unavailable Times** inside **Offerings & Scheduling**. This is the Faculty-scoped projection of the existing Scheduling Blocks Resource: Faculty can maintain only their own active recurring unavailable blocks, while Registrar and Academic Head retain their authorized review scope over the same source records. Submitted and released Grade Rosters remain available in **Grade Roster** as read-only submission history; only Draft, Returned, or Late Not Submitted rosters expose encoding and submission actions.

System Super Admin audit evidence uses two deliberately different read-only surfaces. **Audit Logs** answers who changed which institutional record and when, using business labels such as Audit Area, Change, Recorded Action, Record Type, Actor, and Recorded At. **Operational Events** answers what an integration or delivery service reported, using Area, Service, Event, Status, and Occurred At. Both tables stack on narrow screens. Their technical identifiers remain available in record detail and do not lead the primary table.

**Admissions Work Queue** is the Registrar's only primary Admissions navigation entry. Its tabs separate work that needs Registrar action, work waiting on the applicant, approved records ready for handover review, and completed or withdrawn history. The list leads with applicant identity, Program/Term, plain-language current stage, responsible party, next action, requirement readiness, and last activity. Term, Program, workflow state, admission category, and unresolved-handover-blocker filters answer operating questions; raw credential codes and technical timestamps do not lead the table.

The Applicant Record follows one vertical reading order: current workflow and readiness; deterministic identity-match warning; application scope; personal, address, and guardian details; withdrawal details when applicable; lifecycle history; then collapsed technical references. The checklist relation remains the authoritative per-requirement evidence workspace and groups receipt, download, accept, and reject operations under one review control. Admission Requirement Policies and Duplicate Resolutions remain authorized source-record routes, but they are reached contextually from the record rather than presented as peer Admissions tasks.

Before handover, exact active Student Profile matches use normalized first name, last name, and date of birth as a deterministic warning only. A first-time or transfer intake with such a match cannot silently create a second official profile; the Registrar must investigate or correct the identity evidence. Returning-student reuse still requires an explicit confirmed matching profile. TALA does not perform fuzzy matching or automatic merging.

Staff dashboards show a small number of actionable counts and links. The operational table remains the source for work; charts are not planned unless a revised PRD proves a comparison need and a new Next Steps issue is approved.

The staff Enrollment list leads with Student, Term, Enrollment Status, Enrollment Type, Next Step, and responsible office. Technical gate evidence and lifecycle timestamps remain available in the record view but do not displace the current decision. Rows use the native Filament mobile stack, record actions use one discoverable action group, and the continuing-enrollment header action retains its accessible label and tooltip when its visible text is hidden at narrow widths.

The Enrollment record follows the same decision hierarchy. Exactly one state-appropriate primary action is exposed at a time: confirm placement before a confirmed reservation exists, or record official enrollment after placement and required gates. Irregular replacement, cancellation, gate refresh, academic exception, unit-load exception, and COR printing remain authorized supporting actions under **More actions**. Gate evidence uses human labels first; blocker codes and source references remain explicitly labeled technical evidence. The Student Number links to the canonical Student Profile so the Registrar can move from the term decision to the student's official history without searching again.

The Student Hub Enrollment table is a decision surface, not a copy of the staff record. It leads with Subject, Section, Schedule, Seats Left, and the student's current section status or next action. Description, cohort, modality, and units remain optional columns. The table stacks on mobile. Regular students are told that the Registrar confirms their cohort placement; irregular students see whether a published section is available, already proposed, academically blocked, full, or conflicting. A student proposal never represents a confirmed seat reservation.

The Student Profile record uses one vertical reading order: current official identity and lifecycle state; confirmed academic standing beside a clearly separate system recommendation; unresolved holds with effect, responsible office, and resolution step; term-by-term enrollment history; released academic history; assessment history; and approved lifecycle history. Contextual links open the owning Enrollment, published Schedule, Grade Roster, Assessment, and Lifecycle record when available. Technical source records remain owned by their existing Resources and relation managers. The summary does not rewrite or duplicate those records.

Creating a Student Lifecycle Change is a two-stage consequential action. Staff first record the approved result and its authority, then review a read-only operational-impact summary generated by `StudentLifecycleService::preview()`. The summary names affected subjects and reports binding, reservation, lifecycle status, Program, Curriculum, unresolved-hold, assessment-or-ledger, COR, and master-schedule consequences. Confirmation remains disabled while the preview is unavailable, and the server rejects stale or crafted invalid submissions with field-level guidance. The recorded immutable snapshot is the detail-page evidence after creation.

### Academic Setup lifecycle surfaces

Academic Setup preserves the existing split between course identity, versioned Course Specifications, Curriculum Versions, and Import Batches. The Registrar owns changes; the Academic Head receives read-only review access.

| Surface | Required interaction |
| --- | --- |
| Academic Readiness | Primary task entry. One Program table states the curriculum to review, row count, readiness, exact blocker, and next action. A pending Draft or recorded-approved revision takes precedence over the Active version so unfinished work cannot be hidden. Source-record links preserve direct authorized access without returning eight peer destinations to the main navigation. |
| Academic Years and Terms | Native record forms. A Term's dates must remain inside its selected Academic Year; invalid bounds are rejected with field-level guidance. |
| Programs | Native record form using the approved three-year `DTHM`, `DIT`, and `DBM` identities. |
| Course Specifications | Draft revisions are editable. Active and Retired revisions are read-only. A focused action copies an existing revision into a new Draft so historical records are never edited in place. Only Face-to-Face and Online are selectable modalities. |
| Curriculum Versions | Draft versions remain editable through their authoritative form. The combined review table shows source, specification, placement, readiness, blocker, and next action; focused row actions add entries and correct placement in that same workbench. External approval is recorded through a focused action. Activation uses a read-only impact summary and explicit confirmation; it is not a directly editable state field. Active, Superseded, and Archived versions are read-only. |
| Curriculum import and review | Curriculum CSV is the normal client-onboarding path. Import Batch preserves the private source file, checksum, row preview, errors, warnings, and explicit Draft posting. Posted imports and manually created Drafts converge on the same combined Curriculum review, where the Registrar may complete linked Draft Course Specification fields and scheduling components without navigating to a peer setup destination. Source title, units, placement, and prerequisite text remain distinguishable from inherited or staff-completed TALA scheduling enrichment. |
| Standalone Course Specification import | Optional catalog-maintenance path for complete operational definitions. It does not replace the combined Curriculum import and review journey. |

## TAL-60 Realignment Decisions

| Area | Decision | Reason and MVP benefit | Implementation risk | Future-task effect |
| --- | --- | --- | --- | --- |
| Fortify and Filament auth | Keep current setup | Fortify already supplies backend auth contracts while Filament panels own the login, registration, password reset, and verification UI. This keeps the three workspace entry points proven by tests. | Low if response contracts and panel route names remain covered. | Future auth changes should extend focused response/panel tests rather than add public Fortify views. |
| Applicant registration and Auth Designer | Use existing plugin | Auth Designer is already installed on the panels and the Applicant panel preserves `RegisterApplicant` with the package page hook. This keeps branded auth without losing applicant role assignment. | Medium if future package updates change page-extension APIs. | Keep applicant registration regression tests in every auth/panel slice. |
| Staff operational workflows | Use native Filament | Resources, tables, forms, actions, infolists, relation managers, filters, and widgets cover the MVP staff workflows without custom JavaScript. | Medium only when old inventory resources point at stale schema. | Each domain slice explicitly registers accepted resources and routes or discards stale families through the protocol. |
| Student Hub and Applicant Workspace pages | Use native Filament pages | Student and applicant surfaces are task-focused panels, not generic CRUD portals. Filament pages composed from forms, tables, infolists, and actions keep authorization server-side. | Low to medium, depending on source-record readiness. | Future learner-facing slices should build read-mostly pages after the owning staff source records exist. |
| Calendar-like scheduling views | Not planned for MVP | MVP scheduling review is table-first; date/time inputs and validation tables are sufficient. | Low; avoiding an unproven plugin preserves the validated table path. | No active Next Steps issue. A future approved visualization must receive a new bounded issue and may supplement, never replace, the canonical table and validation path. |
| TallStackUI | Keep available outside the public landing replacement | TallStackUI remains installed for non-Filament Blade/Livewire surfaces that prove a need. The current public landing page is implemented with isolated Bootstrap assets instead. | Low if it stays out of Filament panel implementation decisions and Bootstrap remains landing-only. | Use TallStackUI only for non-Filament Blade/Livewire surfaces with a documented need. |
| Activity Log surface | Use the hand-built resource | The registered read-only `ActivityResource` gives System Super Admin audit visibility aligned with Module 13. | Low if activity tables remain migrated and authorization is retained. | Official-record slices should write audit events and expose them through the accepted audit surface. |
| Additional UI/plugins | Not planned | No current PRD requirement proves a need for saved-filter, import, calendar, dashboard, permissions, or custom UI plugins beyond accepted native Filament surfaces. | Low; rejecting speculative dependencies preserves dependency discipline. | No active Next Steps issue. A future proposal requires a proven capability gap and a new approved bounded issue. |

## TAL-71 Finance Output and Student Hub Decisions

| Area | Decision | MVP implementation |
| --- | --- | --- |
| Student finance surface | Use one read-mostly Student Hub Finance page | Replace the placeholder SOA and payment-acknowledgement pages with one focused page showing the active assessment, charge lines, required downpayment, posted payments, ledger-derived balance, payment schedule, pending/review status, OR mapping state, Financial Accommodation summary, and available outputs. The existing Dashboard balance stat uses the same ledger-derived balance. |
| Finance printable outputs | Reuse the TAL-70 output pattern | SOA, billing slip, and payment acknowledgement use authenticated Laravel Blade print routes, one institutional header and print control, browser print/save-as-PDF, ownership/role authorization, and `output_access_logs`. |
| Billing slip | Generate from an active assessment with a positive currently due amount | The slip is an internal request for payment, identifies the due category and exact amount, and never creates payment evidence or ledger activity. |
| Payment acknowledgement | Show only after verified evidence and posted ledger payment | OR mapping is displayed when present and remains Accounting reconciliation when absent. |
| PayMongo checkout | Use a focused Filament Action backed by the existing checkout service | The action uses the authenticated student's active assessment and a positive system-derived amount, records a pending Payment Attempt, reuses an active matching pending attempt, and redirects to the configured gateway. Webhook verification and ledger posting remain authoritative. |
| Accounting access | Reuse existing finance Resources | Retain Assessment and Ledger Entry Resources; adapt and register Payment and Payment Attempt Resources for evidence, exception, acknowledgement, and OR-mapping work. Do not add a custom Accounting dashboard. |
| Output logging | Use the existing `output_access_logs` schema | Log `SOA`, `BILLING_SLIP`, and `PAYMENT_ACKNOWLEDGEMENT` with `VIEW` and `PRINT`; browser save-as-PDF is `PRINT`. |

## TAL-96D3C Finance and PayMongo Recovery Decisions

| Area | Decision | MVP implementation |
| --- | --- | --- |
| Hosted-checkout return | Treat `success` and `cancelled` as informational browser returns | Success says that verified confirmation is still pending; cancellation says no payment was recorded from the return. Neither path writes a Payment or Ledger Entry. |
| Student information hierarchy | Put the current decision before evidence detail | Current Amount Due, Payment Status, What to do next, Responsible Office, and Official Receipt Status appear first. Technical finance sections remain accessible but collapsed. |
| Normal confirmation | Keep the signed webhook and queue as the primary path | Existing webhook persistence, validation, normalization, idempotency, posting, clearance, notification, and OR-mapping boundaries remain unchanged. |
| Completely missed webhook | Add bounded recovery from an existing TALA Payment Attempt | Accounting selects a pending or expired attempt; TALA retrieves its recorded provider checkout. Pending/expired state updates only the attempt. Reported paid state creates sanitized review evidence and never auto-posts. |
| Recovered payment decision | Require exact Accounting confirmation | Confirmation requires matching ownership, checkout and institutional references, currency, amount, provider payment and intent identifiers, mode, and no dispute/refund indicators, then reuses the existing posting service. |
| Reconciliation presentation | Lead with evidence source, plain reason, and next step | Technical event identifiers and event types are hidden by default. Signed-webhook actions and provider-recovery actions remain distinct. |
| Integration monitoring | Separate locally knowable facts from provider state | Show Local PayMongo readiness, Recent verified webhook, Open local exceptions, and Provider dashboard state as **Not checked by TALA**. Never render credentials, signatures, or raw payloads. |

## TAL-96D5E1C Accounting Operational Recovery Decisions

Accounting uses one three-stage operating path: **Fee Setup -> Student Accounts -> Payment Exceptions**. The normalized records remain separate because they answer different audit questions; they are reached from the Student Account context instead of appearing as unexplained peer destinations.

| Area | Decision | MVP implementation |
| --- | --- | --- |
| Primary Accounting navigation | Show three task-centered entry points | **Fee Setup** owns fee rules; **Student Accounts** owns the current account decision; **Payment Exceptions** owns evidence that requires investigation or recovery. Payment, attempt, ledger, adjustment, and accommodation Resources remain registered and policy-protected but do not lead the sidebar. |
| Student Account list | Lead with the decision, not record mechanics | Show Student, Term, Account State, assessed amount, posted payments, remaining balance, amount due now, Payment Status, Finance Gate and source, plus the next action. Filters answer student, term, and account-state questions. |
| Student Account detail | Use one vertical reading order | Show Student Account, Current Position, and Next Action first. Assessment charges, payment schedule, account activity, attempts, verified payments and official receipts, adjustments/reversals, and accommodations remain collapsed supporting evidence. |
| Assessment versus account activity | Explain the distinction in plain language | **Assessment** is what the school charged for the Term. **Account Activity** is the chronological evidence of charges, payments, adjustments, and reversals used to reproduce the balance. |
| Supporting records | Keep source records contextual and directly addressable | The **Account Records** action group opens filtered Account Activity, Payment Attempts, Payments and Official Receipts, Adjustments and Reversals, and Financial Accommodations for the selected Student. No source record or service boundary is merged. |
| Account Activity | Replace technical-first columns with business meaning | Lead with Student, Term, Posted date, Balance Effect, Category, Source, Payment Method, Reason, Amount, state, and Posted By. Technical IDs and correction provenance remain available in toggled or collapsed audit detail. |
| Payment Exceptions | Present actionable provider evidence | Lead with Student, Assessment, Amount, evidence source, plain reason, state, responsible office, and next action. Filters cover source, status, and reason. The page states that a browser return is informational and cannot post a payment. |
| Correction behavior | Preserve immutable financial history | An authorized correction creates an adjustment or reversal with reason and linked evidence. It never edits or deletes the posted source entry. |

## Module-to-UI Implementation Map

| Module | MVP surface | Native Filament implementation | Existing-code disposition |
| --- | --- | --- | --- |
| 01 Product Intent & Architecture | Public entry plus three authenticated panel shells | Existing public page and Panel Providers | Reuse confirmed baseline |
| 02 Identity, Access & Workspaces | Panel auth, profile, role-aware landing, fixed-role access | Panel auth features, policies, `canAccessPanel`, role-scoped navigation | Reuse confirmed baseline; retain three panels |
| 03 Admissions & Student Handover | Applicant application, requirements, Registrar review, handover, student master record, manual profile updates (Admin Override), duplicate resolution | Applicant custom Pages; staff queue Resources; focused Actions; Student Profile Resource | Intake is confirmed; current profile-update (Admin Override)/duplicate work requires baseline review |
| 04 Academic Setup | Calendar, programs, course specifications, curricula, terms, grade outcomes, policy values | Resources, relation managers, date/time forms, import Page, readiness infolists | Audit existing resources; add only missing PRD fields and workflows |
| 05 Term Offerings & Resources | Generated offerings, special offerings, sections, faculty, rooms, capacity | Resources and relation managers; filtered selection Tables; date/time availability forms | Audit existing offerings/resource Resources before reuse |
| 06 Constraint Programming–Satisfiability (CP-SAT) Scheduling | Demand readiness, solver run, candidate review, publication, revision | Accessible canonical tables plus focused run, publish, and revision actions; Schedule Run review groups status, coverage, hard-constraint validation, objective/bound/gap, runtime, the applicable hard-constraint checklist, and recorded soft-objective contributions under **Solution Quality** | Reuse existing run/candidate inventory; corrected TAL-96B4 evidence supplies empirical terminology and does not authorize an accuracy percentage, capacity claim, chart, or scoring-control UI |
| 07 Enrollment Gate | Gate queue, placement, reservations, exceptions, official enrollment result | Responsive Enrollment Resource with plain-language current status, next step, responsible office, collapsible technical gate evidence, selectable sections Table, and focused override/exception Actions | Existing service ownership is retained; D3B corrects proven start-state truthfulness and presentation gaps without redesigning the Enrollment model |
| 08 Finance, Ledger & PayMongo | Fee matrix, student accounts, payment evidence, OR mapping, account activity, signed-webhook reconciliation, bounded missed-webhook recovery, and Student Finance | **Fee Setup -> Student Accounts -> Payment Exceptions** as the Accounting navigation flow; one vertical Assessment detail with contextual supporting records; registered Payment/Attempt/Ledger/Adjustment/Accommodation Resources; and one Student Hub Finance Page with authenticated print routes | Preserve every normalized record and the ledger as source of truth; require exact Program-and-Term downpayment configuration for activation; keep checkout redirects subordinate to verified evidence and ledger posting; never auto-post unsigned provider-recovery evidence |
| 09 COR | Current generated COR | Student Hub custom Page, staff-accessible read-only source summary, authenticated printable Blade route, and output log action | Exclude public verification/QR/token inventory for MVP; resolve the active term's official enrollment once, then generate COR, schedule, dashboard, and output-log context from that same record; show each subject's Online or Face-to-Face modality and a derived course-delivery mix |
| 10 Grades | Faculty roster entry, Registrar review/release, late authorization, INC completion, correction, student history | Mobile-stacked Faculty roster Page with course, section, term, state, and completion context; staff review Resource with one focused action group; Student Hub released-only Table | Preserve the grading formula and workflow. TAL-96D4B replaces code-oriented states and narrow-screen action sprawl with plain-language context; it does not introduce generic Grade CRUD. |
| 11 Student Lifecycle | Holds, approved lifecycle changes, program-shift credit evaluation, graduation review | Recognizable student/enrollment/subject selectors; staff Resources with focused action groups; structured immutable-impact infolists; searchable graduation members; Student Hub status and completion projections | Preserve recorded decisions, hold ownership, snapshot history, and authorization. TAL-96D4B presents affected subjects, released assignments/reservations, finance effect, status, COR effect, and master-schedule effect as labeled fields instead of raw JSON. |
| 12 Student Hub | Read-only current records plus permitted profile/evidence/payment/enrollment actions | Custom Filament Pages composed from infolists, mobile-stacked Tables, Forms, Actions, and one owner-scoped priority-notice projection | TAL-96D4C keeps official status, computed guidance, balance, holds, and office-owned recovery instructions distinct; it deliberately does not expose a second persistent notification center |
| 13 System Admin, Reports & Audit | Users, canonical role assignment, governed setting history, imports, fixed filtered reports, CSV export, audit, integrations | Resources, native filters, mobile-stacked report Table, audited UTF-8 streamed CSV, activity-log Resource, and a read-only settings Table that distinguishes operational, superseded, and dormant definitions | TAL-96D4C preserves the established catalog, queries, role matrix, sensitivity, filter set, and allowlisted columns. TAL-96D5C1 prevents the registry from implying that stored historical values necessarily control live runtime behavior. |

## Scheduling UI Baseline

Scheduling remains table-first because validation and exception details are easier to review reliably in rows than through drag-and-drop blocks.

| Scheduling step | Surface | Component choice |
| --- | --- | --- |
| Class-planning operating flow | **Class Planning** primary task page | One vertical native Filament page for the selected Term: Prerequisites → Offerings and Sections → Teaching Resources → Schedule Requirements → Generated Timetables → Published Timetable. Each stage shows its current state, blocker, owner, and one next action. |
| Academic calendar and break blocks | Term-scoped setup forms | DatePicker/DateTimePicker and blocked-period Table |
| Room and faculty availability | One term-scoped scheduling-block source surface | Native Filament Resource Form and Table over `calendar_events`; faculty is limited to own `FACULTY`/`UNAVAILABLE` rows, while authorized Registrar or Academic Head staff may review and manage term rows; no submission, lock, version, or change-request UI |
| Term offerings, course-specific sections, and delivery groups | Setup Resources | Resource and relation-manager Tables; Section source-record codes are unique within the Term, while each delivery-group name carries the stable logical cohort code shared across that cohort's subjects |
| Schedule Requirements (canonical model: Scheduling Demand) | Generated review queue | Filtered read-only/edit-limited Table with source links and plain requirement summaries |
| Readiness check | Validation result | Infolist summary plus missing/invalid input Table |
| Generate Timetable (canonical record: Schedule Generation Run) | Generated Timetables Resource | Create Action/Form, confirmation, status badge, and polling read-only view |
| Generated timetable review | Candidate Assignments relation manager | Mobile-stacked Table with grouped secondary actions, filters, warnings, validation status, and a plain-language Solution Quality summary sourced from typed solver evidence, including one result for every applicable hard-constraint category and every recorded soft-objective term |
| Infeasible result | Diagnostic review | Exception Table linking to authoritative source records |
| Manual override | Controlled decision | Focused Action modal with replacement assignment and reason |
| Publication | Controlled decision | Read-only comparison followed by confirmed Action |
| Published revision | Controlled decision | Focused Action modal with impact preview and validation result |
| Published Timetable (canonical records: Section Meetings) | Official staff source plus Student/Faculty projections | Read-only mobile-stacked Table grouped by day and printable owner-scoped views |

The **Offerings & Scheduling** navigation group exposes **Class Planning** as the Registrar's primary workflow and **Assigned Schedule** as the Faculty projection. The authoritative setup and evidence Resources remain registered and policy-protected at their existing URLs, but the Class Planning page reaches them as contextual source records instead of presenting every database record type as a peer task. The Registrar may prepare offerings, generate requirements, review a candidate, and publish. The Academic Head may inspect the Class Planning flow and authorized scheduling evidence read-only. System Super Admin configuration authority does not grant academic offering, candidate-correction, or publication authority.

Scheduling labels must remain understandable without optimization knowledge. A **Schedule Requirement** is one required course component for one regular cohort; its canonical persisted model remains `SchedulingDemand`. **Coverage** is the number assigned divided by the number required. A **hard conflict** is a mandatory-rule violation. The **objective** is a ranking score, the **bound** is CP-SAT's limit on a possibly better undiscovered score, and the **relative gap** is the normalized distance between the returned objective and that bound. These are review evidence, not predictive accuracy or a student grade. Technical solver identifiers and provenance remain available in collapsed or toggle-hidden evidence fields rather than leading the operating view.

The Registrar is the V1 Master Schedule publisher. Academic Head access supports read-only scheduling-exception review, not a universal publication approval, and System Super Admin configuration authority does not grant academic publication authority. Candidate runs close to mutation but remain retained as publication provenance. Whole-version replacement stops once active student bindings exist; subsequent operational changes use the focused published-revision action.

For MVP, TALA does not require a drag-and-drop timetable, FullCalendar plugin, generic constraint builder, or user-editable scoring weights. The Solution Quality presentation explains that `feasible` means valid without proved optimality, coverage and hard-constraint satisfaction establish acceptance, and objective/bound/gap describe optimization quality. A suitable plain-language summary is: **“Valid schedule found — 100% of demands assigned, 0 hard conflicts; optimality not proven within the time limit.”** The gap may be shown separately with an explanation that smaller is better; it must not be labeled predictive “accuracy.” No visualization task is currently planned; a future approved proposal must receive a new Next Steps issue and may supplement, never replace, the candidate review table or validation path.

Date-less class, availability, and operating-grid times are institutional wall-clock values. Filament time inputs for these values use the application timezone directly so that, for example, `09:00` remains `09:00`; only true timestamps such as submission, publication, and audit times are stored in UTC and converted for human display. The approved Monday-to-Saturday operating window is `07:00`–`21:00`.

## Imports, Reports, Notifications, and Plugins

### Imports

Course Specification and Curriculum imports use a custom Filament Page composed from native `FileUpload`, validation summaries, a preview Table, and an explicit Draft-creation Action. This preserves the PRD's versioned-template, full-preview, and all-errors-block-posting behavior. Current imports use the native CSV implementation; no additional import plugin is required.

### Reports

MVP reports are fixed, role-authorized operational Tables with controlled filters and one CSV export Action. The report title and description explain the selected dataset, the table stacks on narrow screens, and empty results explain how to change scope. CSV files preserve the approved heading order and allowlisted fields, use readable report-label filenames, begin with a UTF-8 byte-order mark for Excel compatibility, protect formula-like values, and retain stable date and money semantics. Sensitive exports require a purpose; every export records actor, role, report, normalized filters, purpose, sensitivity, row count, request context, and generation outcome. Analysis, pivoting, and chart building occur outside TALA.

### Generated outputs

COR, student and faculty schedules, Statement of Account, Billing Slip, and Payment Acknowledgement remain authenticated source-derived browser outputs. They use the configured institution identity, a clear document title and copy context, a consistent generated timestamp, responsive overflow for wide tables, one print/save-as-PDF control, and document-specific disclaimers. The shared presentation layer does not change source builders, role or owner authorization, or `output_access_logs`.

### Notifications

Filament notifications provide immediate success, warning, and error feedback after an action. Student Hub renders one owner-scoped priority notice from the existing notification and authoritative domain records; it does not expose Filament's separate persistent notification-center control. Critical schedule-release, schedule-revision, payment-posting, applicant Action Required, and applicant Approved for Handover messages use queued email with consistent institution branding, a plain action, the responsible office, idempotent operational-event evidence, and recorded delivery metadata. Applicant submission uses an immediate on-screen confirmation rather than a separate email. Database-editable templates remain outside the MVP.

### Plugin policy

Approved baseline:

1. Core Filament v5 for authenticated UI.
2. Existing Auth Designer integration for Filament panel authentication screens, preserving the custom Applicant registration page.
3. Isolated Bootstrap v5.3.3 public assets for the public landing page; existing TallStackUI components remain available for other non-Filament Blade/Livewire surfaces with a documented need.
4. Hand-built read-only `ActivityResource` for authorized audit visibility under Module 13.
5. Native CSV import/export handling for fixed templates; no spreadsheet package is required.

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
