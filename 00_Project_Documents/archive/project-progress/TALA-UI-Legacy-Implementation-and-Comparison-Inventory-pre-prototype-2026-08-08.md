## Legacy Implementation and Comparison Inventory — Evidence Only

> **Non-authoritative implementation inventory.** Every navigation, class, template, and historical task-center name below records what exists or what an older plan proposed. The final Panel and Navigation Map and Clinic 1–6 UI authorities above control the product. Nothing below may restore Reports, Approvals, Settings, Readiness Center, global holds, legacy finance, or peer-resource navigation.

### Student Hub

Student Hub is a read-mostly workspace. Use focused custom Filament Pages rather than exposing staff CRUD resources.

| Navigation item | Surface | Primary component |
| --- | --- | --- |
| Home | Active Term, official Student Profile status, confirmed academic standing, Clinic 5 progress result, Clinic 6 Finance status, and next actions | Custom Page with plain-language read-only summaries and contextual links; each result names its owning office and source, and no global hold summary is introduced |
| Enrollment | Clinic 4 guided status page: term/deadline/stage/owner/next action, five checkpoints, proposed or official subjects, explanation, placement/reservation, Finance requirement, COR, and change history | Focused custom Page using native Sections, Infolists, responsive Tables, one primary Action, and contextual links |
| Academics | Published class schedule, released grades, academic progress/lifecycle, and completion review | Focused custom Page with vertically ordered read-only summaries and contextual detail links |
| Finance | Lead with Current due, requirement status, next obligation, next action, responsible office, and as-of time; keep Assessment, verified postings, submitted evidence, attempts, adjustments/reversals, outputs, and audit as contextual detail | Focused custom Page using responsive native Sections, Infolists, Tables, and authorized Actions; alumni variant is read-only |
| Profile | Official Student identity/program/curriculum/entry/contact summary and correction guidance | Read-only grouped record; official corrections are Registrar-owned and Account Security remains Clinic 1 |

The existing Class Schedule, COR, Grades, Holds, Academic Status, and Completion pages remain policy-protected projections. They are contextual destinations from Enrollment, Academics, Home, or Profile and do not remain peer primary-navigation items.

### Staff Workspace

Use navigation groups to prevent the existing resource inventory from becoming one long menu:

The Staff Dashboard begins with a role-owned work summary rather than framework or developer information:

- Registrar receives source-owned orientation inside **Catalog & Curricula** and **Term Planning**; there is no separate Academic Readiness destination.
- Accounting receives **Accounting Work**, linking **Fee Plans** and the tabbed **Student Accounts** workbench. Contextual exports are reached from the owning queue; there is no Accounting Reports destination.
- Faculty receives **My Faculty Work**, linking Assigned Schedule, Grade Rosters, and My Unavailable Times.
- Academic Head receives **Academic Oversight**, linking read-only source-owned academic authority, Term Planning, grade/progress, lifecycle, and completion evidence.
- System Administrator receives **System Administration**, linking Users & Access, Public Content, System Health, and Governance & Audit.

Each summary uses authoritative counts or readiness states and provides orientation links only. It does not merge records, execute a domain action, run scheduling, publish a timetable, post finance, or grant permissions beyond the user's policies. The generic Filament framework-information widget is not an institutional task and is not shown.

### Lean-MVP capability and navigation register

This register is the canonical presentation disposition for the currently registered MVP surfaces. Registration and direct-route authorization remain independent of sidebar placement.

| Owner / primary task | Named surfaces and capabilities | Disposition | Normal entry and preservation rule |
| --- | --- | --- | --- |
| Public entry | Task-focused gateway, application availability, published notices/FAQ, external institution/map links, Apply/Sign In routes | Primary | Clinic 1 Public Gateway |
| Public recovery | Branded 403, 404, 419, 429, 500, and 503 HTML responses | Contextual | Reached only on failure; Laravel retains JSON/API negotiation |
| Applicant Home / Application | Applicant Dashboard, Application Wizard, application history, withdrawal, status and next-action guidance | Primary | Home or Application |
| Applicant Application | Requirements checklist, Registrar feedback, digital evidence view/reupload, physical-document instructions | Contextual | Current or historical Application record; direct route remains applicant-authorized |
| Applicant account | Account Security, password recovery, email verification | Contextual | Clinic 1 Account Security and auth controls |
| Student Home | Student Dashboard and next-action summary | Primary | Home |
| Student Enrollment | Guided current-term registration and official-enrollment page | Primary | Enrollment |
| Student Enrollment | COR and Class Schedule projections | Contextual | Enrollment record or Academics; outputs remain read-only and access-logged |
| Student Academics | Academics task center | Primary | Academics |
| Student Academics | Grades, Holds, Academic Status/Lifecycle, Completion | Contextual | Academics or Profile |
| Student Finance | Summary-first Term Account, exact-due checkout, private evidence submission, SOA, and Payment Acknowledgment | Primary | Finance; alumni history is read-only and outputs remain contextual and access-logged |
| Student Profile | Profile and permitted contact updates | Primary | Profile |
| Registrar academic authority | Catalog & Curricula | Primary | Catalog & Curricula |
| Registrar Academic Readiness | Academic Years, Terms, Academic Calendar Windows, Programs, Courses, Course Specifications, Curriculum Versions, Import Batches | Contextual | Academic Readiness and Curriculum review links |
| Registrar Admissions | Admissions workbench with five operational tabs | Primary | Admissions |
| Registrar Admissions | Applicant Record, Admission Cycles, immutable Requirement Sets, preliminary evidence, official credentials, decisions, and identity-match review | Contextual | Applicant record or Admissions workbench; no generic Settings or handover page |
| Registrar Term Planning | Term Planning | Primary | Term Planning |
| Registrar Class Planning | Term Offerings, Sections, Rooms, Faculty Qualifications, Faculty Load Overrides, Calendar Events, Scheduling Demands, Schedule Generation Runs, official Section Meetings | Contextual | Class Planning stage links; solver/provider diagnostics are secondary evidence |
| Registrar Students & Enrollment | Enrollment and Student Profile | Primary | Students & Enrollment |
| Registrar Students & Enrollment | Student Lifecycle Changes and record-owned holds/history | Contextual | Student Profile |
| Registrar Grades & Completion | Grade Review, INC & Corrections, Academic Progress, Lifecycle, Completion & TOR, and History | Primary | Grades & Completion |
| Accounting Student Accounts | Assessment-required and account-centered finance review | Primary | Student Accounts |
| Accounting Student Accounts | Assessment basis/source, exact authorized individual-assessment action, verified postings, evidence, adjustments/reversals, outputs, and audit | Contextual | Student Account detail tabs |
| Accounting Payment Exceptions | Manual and PayMongo evidence requiring review | Contextual tab | Student Accounts → Payment Exceptions |
| Accounting Payment Exceptions | Payment Attempts and retained provider-event evidence | Evidence-only | Payment exception detail |
| Accounting Fee Plans | Versioned Program-and-Term Fee Plans | Primary | Fee Plans |
| Faculty work | Faculty Schedule, Faculty Grade Roster, own Calendar Events / unavailable blocks | Primary | My Schedule, Grade Rosters, My Unavailable Times |
| Academic Head work | Read-only academic authority, Term Planning, grade/progress/lifecycle/conferral evidence | Primary | Academic Oversight; institutional decisions are recorded by their owning Registrar action |
| System administration | User accounts and fixed Staff access assignments | Primary | Users & Access; no Role or permission editor |
| System administration | Notices and FAQ | Primary | Public Content |
| System administration | Locally evidenced technical status | Primary | System Health; no arbitrary Settings surface and secret values never render |
| Governance | Governance & Audit | Primary | Institutional Changes, System Events, Output Access, and Privacy & Retention tabs |
| Governance | Safe activity, operational-event, and output-access evidence; retention readiness | Evidence-only | Governance/Audit questions or owning record; automatic disposal remains disabled |
| Framework diagnostics | Generic Filament information widgets | Retired | No institutional purpose; remove from panel registration |
| Deferred product work | Capabilities excluded, conditional, or intentionally postponed by canonical 00–06 | Deferred | Not presented as active MVP work unless the owning product authority is first amended and a later vertical slice is separately derived and approved; shared cross-program classes are already governed by PRD 03 |

#### Executable capability inventory

This is the code-level inventory behind the register above. A class appearing here does not make it a peer navigation item: **Primary** classes are task entries, **Contextual** classes are source records or projections reached from a task, and **Evidence-only** classes answer audit or exception questions. Registration and authorization stay in code; this inventory makes the presentation decision reviewable and prevents an implemented boundary from becoming an unexplained or forgotten surface.

| Workspace / owner | Executable boundaries | Presentation disposition |
| --- | --- | --- |
| Shared staff entry and orientation | `Dashboard`, `StaffRoleWorkspaceOverviewWidget`, `RegistrarOperationalReadinessWidget`, `AccountWidget` | Primary dashboard plus role-owned orientation; account control is Contextual |
| Registrar and Academic Head task centers | `AcademicReadiness`, `ClassPlanning`, `GradesAndCompletion`, `AcademicApprovals`, `ReportsAudit` | Primary where permitted by role |
| Faculty task centers | `FacultySchedule`, `FacultyGradeRoster` | Primary for assigned schedule and grade work |
| Accounting exception task center | `PayMongoReconciliation` | Primary for unresolved provider or manual-payment exceptions |
| System administration task center | `IntegrationStatus` | Primary system-health summary; source settings remain Contextual |
| Admissions records | Current `ApplicantIntakeResource`, policy, checklist, evidence, calendar, duplicate-resolution, and handover implementation | Salvage inventory only: retain the queue/evidence foundations when conforming; replace legacy policy, calendar, duplicate, and handover boundaries under a future approved slice |
| Academic-period and curriculum records | `AcademicYearResource`, `TermResource`, `AcademicCalendarWindowResource`, `ProgramResource`, `CourseResource`, `CourseSpecificationResource`, `CurriculumVersionResource`, `ImportBatchResource` | Contextual source records reached from Academic Readiness |
| Class-planning records | `TermOfferingResource`, `SectionResource`, `RoomResource`, `FacultyQualificationResource`, `FacultyTermLoadOverrideResource`, `CalendarEventResource`, `SchedulingDemandResource`, `ScheduleGenerationRunResource`, `SectionMeetingResource` | Contextual planning, solve, review, and publication records reached from Class Planning |
| Enrollment and student records | `EnrollmentResource`, `StudentProfileResource`, `StudentLifecycleChangeResource` | Enrollment and Student Profile are Primary operational records; lifecycle change is a Contextual consequential action/history record |
| Grades and completion records | `GradeRosterResource`, `GraduationReviewBatchResource` | Contextual records reached from role-owned grade or completion work |
| Finance records | Existing `FeeRuleResource`, `AssessmentResource`, `PaymentResource`, `LedgerEntryResource`, `AccountingAdjustmentResource`, `FinancialAccommodationResource`, and `PaymentAttemptResource` | Quarantined salvage inventory. Clinic 6 requires fixed ordinary Fee Plans, exact externally authorized individual-assessment recording for bounded exceptions, and continuous Term Accounts; legacy Fee Rule, automated unit calculation, allocation, accommodation, and ledger-first behavior cannot lead the UI. |
| Public-content and access records | Current user, role, FAQ, notice, and settings implementation | Reconcile against Clinic 1: Users & Access and bounded Public Content are Primary; editable roles/permissions are removed; settings survive only when a later owning domain proves a consumer |
| Governance records | `ActivityResource`, `OperationalEventResource`, `DisposalReviewResource` | Activity and operational-event foundations are evidence candidates. Disposal Review cannot become an active queue while the retention schedule is not approved. |

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
| Student finance outputs | Existing `BillingSlipController`, `FinanceStatementController`, `PaymentAcknowledgementController` | Finance Statement and Payment Acknowledgment are salvage candidates subject to PRD 06; Billing Slip is removed from the target product after later dependency migration. |
| Published schedules | `FacultySchedulePrintController`, `StudentSchedulePrintController` | Source-derived official schedule outputs after publication |
| Applicant status mail | Current `ApplicantStatusChangedMail` | Salvage candidate that must be split or adapted to Clinic 2's six idempotent events and safe portal-linked content |
| Finance mail | `PaymentPostedMail` | Queued notification only after authoritative payment posting |
| Schedule mail | `ScheduleReleasedMail`, `ScheduleRevisionMail` | Queued publication or revision communication from official schedule state |
| Integration diagnostic mail | `TestConnectionMail` | Restricted system-health diagnostic, not a normal user journey |
| In-app notification | `GeneralSystemNotification` | Authorized immediate guidance; it does not replace owning records or email delivery evidence |

| Custom Blade family | View inventory | Presentation disposition |
| --- | --- | --- |
| Public entry layouts | `welcome.blade.php`, `layouts/landing-bootstrap.blade.php`, `layouts/public.blade.php` | Primary public entry and its isolated layouts |
| Applicant workflow | `filament/applicant/pages/application.blade.php`, `filament/applicant/pages/application-submit-action.blade.php`, `filament/applicant/pages/dashboard.blade.php`, `filament/applicant/pages/requirements.blade.php` | Primary Application/Home views plus Contextual requirements projection |
| Staff task centers | `filament/pages/academic-readiness.blade.php`, `filament/pages/class-planning.blade.php`, `filament/pages/academic-approvals.blade.php`, `filament/pages/grades-and-completion.blade.php`, `filament/pages/integration-status.blade.php`, `filament/pages/pay-mongo-reconciliation.blade.php`, `filament/pages/reports-audit.blade.php` | Primary role task-center views |
| Applicant handover evidence | `filament/admin/applicant-intakes/handover-preview.blade.php` | Superseded salvage surface; future Clinic 4 consumes the shared Ready Applicant projection without a preview/confirmation handover action |
| Student task and projection views | `filament/student/pages/academics.blade.php`, `filament/student/pages/profile.blade.php`, `filament/student/pages/completion.blade.php`, `filament/student/pages/generic-infolist.blade.php`, `filament/student/pages/generic-table.blade.php` | Primary task views or Contextual reusable projections as owned by their Page classes |
| Official output layout and documents | `components/official-output-layout.blade.php`, `cor/print.blade.php`, `finance/billing-slip.blade.php`, `finance/statement.blade.php`, `finance/payment-acknowledgement.blade.php`, `schedules/print.blade.php` | Contextual authenticated outputs; the shared layout does not own source data |
| Branded mail views | `mail/applicant-status-changed.blade.php`, `mail/payment-posted.blade.php`, `mail/schedule-released.blade.php`, `mail/schedule-revision.blade.php` | Cross-role communication generated from authoritative state |
| Error view family | `errors/layout.blade.php`, `errors/4xx.blade.php`, `errors/5xx.blade.php`, `errors/403.blade.php`, `errors/404.blade.php`, `errors/419.blade.php`, `errors/429.blade.php`, `errors/500.blade.php`, `errors/503.blade.php` | Contextual recovery only |

The public boundaries are the Bootstrap landing page, `/home` compatibility redirect, Filament/Fortify login, registration, verification, reset, and recovery surfaces, and the branded HTML error responses `403`, `404`, `419`, `429`, `500`, and `503`. Error pages remain contextual recovery surfaces and retain Laravel's content-negotiated JSON behavior for API requests.

**Historical inventory note:** the earlier D5E1D1 review treated several registered routes as aligned and added contextual Users → Roles and Integration Status → System Settings links. Clinic 1 supersedes that identity/access presentation: editable Roles/permissions are removed and no arbitrary Settings surface survives. The remaining academic, finance, report, integration, and governance entries are only reuse inventory until their owning clinics classify them. The generic framework-information widget remains a superseded remnant with no institutional purpose.

For comparison against the executable inventory, the final role-owned primary navigation is:

| Role | Primary navigation |
| --- | --- |
| Applicant | Home; Application |
| Student | Home; Enrollment; Academics; Finance; Profile |
| Registrar | Admissions; Catalog & Curricula; Term Planning; Students & Enrollment; Grades & Completion |
| Accounting | Fee Plans; Student Accounts |
| Faculty | My Availability; My Schedule; Grade Rosters |
| Academic Head | Academic Oversight |
| System Administrator | Users & Access; Public Content; System Health; Governance & Audit |

### Demonstration-critical cross-role journeys

| Journey | Primary operating sequence | Contextual evidence and consumer |
| --- | --- | --- |
| Application to enrollment readiness | Applicant Application → Registrar Admissions → decision → official credentials → derived readiness | Requirements/evidence, identity-match review, shared Clinic 4 Ready Applicant projection; no Student creation |
| Timetable publication | Academic Readiness → Class Planning → solve/review/publish | Offering/resource/demand/run evidence; Faculty and Student schedules |
| Enrollment and COR | Learner starts registration → proposal → confirmation → reservation → Accounting clearance → Registrar finalization | Five-checkpoint evidence, shortages, official schedule/roster, immutable COR, and enrollment/change history |
| Finance clearance | Fixed Fee Plan or authorized individual assessment → Student Accounts → verified payment and/or Approved Coverage | Separate payment/coverage amounts and sources, satisfaction basis, Clinic 4 projection, Student Finance, and non-tax outputs; no scholarship/accommodation workflow |
| Special Term through academic projection | Approved `TERM-2026-ST` → published Regular/Additional classes → `REG-2026-ST-001` → `ACT-2026-ST-001`/`COV-2026-ST-001`/`PAY-2026-ST-001` → official enrollment → two roster releases | `Grades not complete` after the first release, then `2.13` Term weighted average and `2.01` Cumulative GWA with earlier failure retained |
| Grades | Faculty Grade Rosters → Registrar review/post/release | Grade history; Student Academics with explicit average readiness |
| Lifecycle and completion | Registrar Student Profile → lifecycle/progression/completion action | Academic Head approval when required; Student Profile/Academics projection |

**Catalog & Curricula** and **Term Planning** are the two Clinic 3 primary navigation items. Catalog & Curricula owns academic authority and the grouped curriculum journey. Term Planning owns the selected term from typed calendar setup through cohorts/classes, teaching resources, candidate review, publication, and revision. Underlying source-record routes may remain authorized and reachable contextually during later implementation reconciliation, but they are not peer tasks in the accepted product.

The Curriculum review presents every entry in one ordered table with curriculum-source facts (course code, title, and units), Course Specification revision/state/modalities, curriculum placement (year, term, sequence, and requirement group), readiness, exact blocker, and next action. Manual Draft creation redirects to this review, and a posted Curriculum Import Batch opens the same review. Registrar table actions add an entry, correct its placement, and complete the linked Draft Course Specification and components without leaving the workbench; those actions update the existing authoritative records through the academic-setup service layer. Full source-record forms remain available contextually, and lifecycle services still own approval and activation. The UI does not duplicate or merge the underlying records.

| Group | Primary roles | Contents |
| --- | --- | --- |
| Admissions | Registrar | One Admissions workbench, Applicant Record, preliminary-evidence review, decisions, official-credential outcomes, Admission Cycles, immutable Requirement Sets, and identity-match review |
| Academic Setup, Offerings & Timetable | Registrar, Academic Head, Faculty where applicable | Catalog & Curricula; Term Planning Overview, Cohorts & Classes, Teaching Resources, Generate & Review, Published Timetable; Faculty My Availability and My Schedule projections |
| Enrollment | Registrar, Academic Head for exceptions | Plain-language status and next-step queue, placement, reservations, academic exceptions, unit-load exceptions |
| Finance | Accounting | Versioned fixed Program-and-Term Fee Plans; exact externally calculated authorized individual assessments for bounded exceptions; continuous Term Accounts; private manual evidence; exact-due PayMongo; append-only postings, adjustments, and reversals; bounded Clinic 4/5 projections; SOA and Payment Acknowledgment |
| Grades | Faculty, Registrar, Academic Head | Faculty rosters, late authorization, submission review, posting/release, INC completion, corrections |
| Student Records | Registrar, Accounting for owned holds | Student profile, holds, lifecycle changes, program shifts, graduation review |
| Governance & Audit | System Administrator and authorized owning roles | Read-only institutional changes, system events, output/export access, and retention readiness; two contextual Clinic 6 CSVs rather than a report catalog |
| System | System Administrator | Users & Access, bounded Public Content, code-owned roles/permissions, typed technical settings only when a verified consumer exists, code-defined notification content, and restricted read-only integration status |

Faculty sees **My Availability** and makes one term declaration of genuine hard unavailability or **No additional restrictions**. Faculty sees **My Schedule** only from published meetings and may inspect affected revision history. Submitted and released Grade Rosters remain available in **Grade Roster** as read-only submission history; only Draft, Returned, or Late Not Submitted rosters expose encoding and submission actions.

System Administrator audit evidence uses two deliberately different read-only surfaces. **Audit Logs** answers who changed which institutional record and when, using business labels such as Audit Area, Change, Recorded Action, Record Type, Actor, and Recorded At. **Operational Events** answers what an integration or delivery service reported, using Area, Service, Event, Status, and Occurred At. Both tables stack on narrow screens. Their technical identifiers remain available in record detail and do not lead the primary table.

**Admissions** is the Registrar's only primary Admissions navigation entry. Its tabs are Needs review, Waiting for applicant, Official credentials, Ready for enrollment, and History. The list leads with applicant/reference, Program/Cycle, plain-language state, responsible party/next action, preliminary readiness, official-credential readiness, nearest deadline, and last activity. Cycle, Program, path, state, submitted and last-activity date/time ranges, and deadline/overdue filters answer operating questions; raw credential codes and technical timestamps do not lead the table.

The Applicant Record follows one vertical reading order: state/owner/next action; private identity-match warning; application scope and minimum applicant facts; preliminary evidence; current and superseded decisions; official credentials after admission; then collapsed activity, notification, and technical evidence. Admission Cycles and immutable Requirement Sets are contextual Registrar source records. Current generic policy and duplicate-resolution resources are salvage inventory, not accepted peer tasks.

Before `Admitted`, a verified-LRN collision or exact normalized legal name plus birth-date candidate warning requires Registrar resolution. Submission remains allowed; the admission decision is blocked. TALA does not perform fuzzy matching, automatic merging, applicant-facing disclosure of another record, returning-student reuse, or Student-profile duplicate repair inside Clinic 2.

Staff dashboards show a small number of actionable counts and links. The operational table remains the source for work; charts are not planned unless a revised PRD proves a comparison need and a new Next Steps issue is approved.

The accepted Clinic 4 workbench and guided learner page above replace the legacy gate presentation. The staff list leads with learner, term/program, derived stage, owner and next action, proposal/confirmation, placement, Finance state, deadline, and last activity. Technical evidence and lifecycle timestamps remain collapsed context and never displace the current decision.

The Enrollment record exposes exactly one state-appropriate primary action. Standard Curriculum and Individually Advised are proposal bases, not Student statuses or separate workflows. Exceptions are recorded only when externally authorized and explicitly modeled by PRD 04; there is no generic gate refresh or override action.

The Student Enrollment page is a decision surface, not a copy of the staff record. Proposed subjects never imply a reserved seat, and reservations never imply official enrollment. The learner sees their own schedule, eligibility explanation, reservation/shortage result, Finance requirement, and next action without internal capacity analytics or other learners' data.

The Student Profile list identifies the current active-Term Enrollment, Enrollment Status and Type, and source-derived curriculum level or mixed-level context, with Program and current-enrollment-status filters. The Student Profile record uses one vertical reading order: current official identity and lifecycle state plus that current Enrollment context; confirmed academic standing beside a clearly separate system recommendation; unresolved holds with effect, responsible office, and resolution step; term-by-term enrollment history; released academic history; assessment history; and approved lifecycle history. Contextual links open the owning Enrollment, published Schedule, Grade Roster, Assessment, and Lifecycle record when available. Technical source records remain owned by their existing Resources and relation managers. The summary does not rewrite or duplicate those records.

Creating a Student Lifecycle Change is a two-stage consequential action. Staff first record the approved result and its authority, then review a read-only operational-impact summary generated by `StudentLifecycleService::preview()`. The summary names affected subjects and reports binding, reservation, lifecycle status, Program, Curriculum, unresolved-hold, assessment-or-ledger, COR, and master-schedule consequences. Confirmation remains disabled while the preview is unavailable, and the server rejects stale or crafted invalid submissions with field-level guidance. The recorded immutable snapshot is the detail-page evidence after creation.

### Academic Setup lifecycle surfaces

> **Legacy Clinic 3 UI evidence — superseded as authority.** The accepted Clinic 3 workbench hierarchy above governs. The material below is preserved only to identify reusable source-record and import patterns; its peer-resource navigation, legacy model names, fixed programs, modalities, and approval behavior do not override PRD 03.

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
| Applicant registration and Auth Designer | Retain conditionally | Auth Designer is already installed. Keep its branded shell only if the minimal Create account form, native verification/recovery/email-change/MFA behavior, responsive layout, and accessibility remain compatible. | Medium if package extension APIs conflict with native security behavior. | Future approved Identity tasks prove the complete auth journeys before retention. |
| Staff operational workflows | Use native Filament | Resources, tables, forms, actions, infolists, relation managers, filters, and widgets cover the MVP staff workflows without custom JavaScript. | Medium only when old inventory resources point at stale schema. | Each domain slice explicitly registers accepted resources and routes or discards stale families through the protocol. |
| Student Hub and Applicant Workspace pages | Use native Filament pages | Student and applicant surfaces are task-focused panels, not generic CRUD portals. Filament pages composed from forms, tables, infolists, and actions keep authorization server-side. | Low to medium, depending on source-record readiness. | Future learner-facing slices should build read-mostly pages after the owning staff source records exist. |
| Calendar-like scheduling views | Not planned for MVP | MVP scheduling review is table-first; date/time inputs and validation tables are sufficient. | Low; avoiding an unproven plugin preserves the validated table path. | No active Next Steps issue. A future approved visualization must receive a new bounded issue and may supplement, never replace, the canonical table and validation path. |
| TallStackUI | Keep available outside the public landing replacement | TallStackUI remains installed for non-Filament Blade/Livewire surfaces that prove a need. The current public landing page is implemented with isolated Bootstrap assets instead. | Low if it stays out of Filament panel implementation decisions and Bootstrap remains landing-only. | Use TallStackUI only for non-Filament Blade/Livewire surfaces with a documented need. |
| Activity Log surface | Use the hand-built resource | The registered read-only `ActivityResource` may give System Administrator appropriate high-value audit visibility. | Low if activity tables remain migrated and authorization is retained. | Clinic 1 limits the view to high-value security events; later modules own their audit evidence. |
| Additional UI/plugins | Not planned | No current PRD requirement proves a need for saved-filter, import, calendar, dashboard, permissions, or custom UI plugins beyond accepted native Filament surfaces. | Low; rejecting speculative dependencies preserves dependency discipline. | No active Next Steps issue. A future proposal requires a proven capability gap and a new approved bounded issue. |

## Superseded Finance UI Decisions

The TAL-71, TAL-96D3C, and TAL-96D5E1C finance notes were implementation-recovery evidence. Clinic 6 retains their useful summary-first presentation, authenticated output access, informational browser return, signed/idempotent webhook, locally evidenced integration status, and append-only correction principles. It supersedes their Fee Rule/downpayment model, Billing Slip, Official Receipt mapping, ledger-as-product language, Financial Accommodation surface, provider-recovery confirmation flow, three-entry Accounting navigation, and assumption that every normalized legacy record survives. The approved Clinic 6 authority above is the only current finance UI contract.

## Module-to-UI Implementation Map

| Module | MVP surface | Native Filament implementation | Existing-code disposition |
| --- | --- | --- | --- |
| 01 Product Intent & Architecture | Public entry plus three authenticated panel shells | Existing public page and Panel Providers | Reuse confirmed baseline |
| 02 Identity, Access & Workspaces | Public Gateway, minimal account creation, contextual auth, MFA, Account Security, workspace resolver/chooser, Users & Access, bounded Public Content | Native Filament/Fortify auth and MFA, policies/panel gates, focused Pages, Resources/Tables/Infolists/Actions | Retain three panels and aligned auth foundations; simplify, replace, remove, or quarantine legacy account machinery exactly as PRD 01 requires |
| PRD 02 Application, Admission Decision & Enrollment Readiness | Applicant Home/Application/Requirements/acknowledgment; Registrar Admissions/Applicant Record/Cycles/Requirement Sets | Native five-step Wizard; grouped requirement Tables; one queue Table with native tabs/search/filters; Infolists; focused Actions; authenticated print view | Retain bounded draft/upload/queue/audit/mail foundations when conforming; simplify intake/evidence/readiness; replace generic calendar/policy/handover/duplicate boundaries; keep physical columns quarantined until later dependency mapping |
| PRD 03 Academic Setup, Offerings & Published Timetable | Catalog & Curricula; Term Planning Overview, Cohorts & Classes, Teaching Resources, Generate & Review, and Published Timetable; Faculty availability/schedule projections | Native connected workbenches plus one accessible custom weekly view with table fallback; failed-first readiness; fixed quality measures; constrained candidate correction; immutable publication/revision | Retain bounded immutable, solver, validation, mail, and Filament foundations when conforming; simplify calendar/curriculum/availability/class planning; replace legacy layering, equal weights, generic profiles, run-first UI, and override semantics |
| PRD 04 Current-Term Registration, Official Enrollment, Student Activation, Adjustment & Course Drop | Guided learner Enrollment page; Registrar Students & Enrollment workbench; Accounting Enrollment Clearance; COR and official roster/schedule projections | Native queue Tables and filters, ordered Infolists/Sections, focused Forms, one primary Action, Action Groups, responsive proposal/schedule rows, and authenticated print view | Retain bounded transactional/idempotent/COR foundations when conforming; simplify nine gates and state; replace standalone Study Plan, Regular/Irregular policy status, generic overrides/global holds, and manually re-entered Term Offerings; quarantine physical consumers until later dependency mapping |
| PRD 06 Accounts, Official Outputs, Operations & Assurance | Fixed ordinary Fee Plans; Student Accounts with Accounts/Payment Exceptions/TOR Clearance tabs and contextual exact individual-assessment/Approved-Coverage actions; Student Finance; System Health; Governance & Audit; SOA and Payment Acknowledgment | Native Tables, Tabs, Sections, Infolists, private File Upload, focused Actions, contextual CSV export, and authenticated print views | Retain bounded event/webhook/private-output foundations only after conformance; replace Fee Rules/automated unit calculation, silent fallback, legacy account ownership, Billing Slip/OR/allocation/accommodation/report/disposal/ops-console behavior; quarantine physical consumers |
| 09 COR | Current generated COR | Student Hub custom Page, staff-accessible read-only source summary, authenticated printable Blade route, and output log action | Exclude public verification/QR/token inventory for MVP; resolve the active term's official enrollment once, then generate COR, schedule, dashboard, and output-log context from that same record; derive one curriculum level or a truthful mixed-level label from active enrolled subjects; show each subject's Online or Face-to-Face modality and a derived Course Delivery Mix |
| PRD 05 Teaching, Final Grades, Academic Records, Lifecycle & Completion | Faculty Grade Rosters; Registrar Grades & Completion; Student Academics; unofficial record; TOR preview/issuance | Native roster/queue Tables and filters, controlled Forms, ordered Infolists/Sections, one primary Action, Action Groups, focused Student Academics and authenticated print Pages | Retain bounded roster/event/lifecycle/snapshot foundations when conforming; replace period-grade/formula, released `P`, mutable result, generic policy/hold, batch, and template-editor behavior; quarantine physical consumers until later dependency mapping |
| Legacy 11 Student Lifecycle | Legacy holds, status, shift, and graduation surfaces | Non-authoritative comparison input only | Academic lifecycle/completion is superseded by PRD 05; Clinic 6 rejects global financial holds and exposes only request-specific projections |
| Legacy 12 Student Hub | Remaining cross-module read-only workspace material | Contextual projections governed by each owning clinic | Clinic 5 owns Academics; Clinic 6 owns Finance and historical alumni account access |
| Legacy 13 System Admin, Reports & Audit | Existing report, audit, retention, and integration surfaces | Read-only salvage inventory | Clinic 1 owns access/public content; Clinic 6 replaces the broad report/operations/disposal product with contextual exports, System Health, and Governance & Audit |

## Scheduling UI Baseline

> **Legacy Clinic 3 UI evidence — superseded as authority.** The Clinic 3 UI Authority above replaces this older Class Planning and scheduling baseline. The table and notes below remain comparison evidence for later implementation reconciliation only; legacy model names, `calendar_events`, fixed operating hours, equal-weight quality evidence, and manual-override language must not govern the product.

Scheduling remains table-first because validation and exception details are easier to review reliably in rows than through drag-and-drop blocks.

| Scheduling step | Surface | Component choice |
| --- | --- | --- |
| Class-planning operating flow | **Class Planning** primary task page | One vertical native Filament page for the selected Term: Prerequisites → Offerings and Sections → Teaching Resources → Schedule Requirements → Generated Timetables → Published Timetable. Each stage shows its current state, blocker, owner, and one next action. |
| Academic calendar and break blocks | Term-scoped setup forms | DatePicker/DateTimePicker and blocked-period Table |
| Room and Faculty availability | Teaching Resources tab plus Faculty My Availability | Native forms/tables over the future reconciled Clinic 3 records; one Faculty declaration, room hard unavailability, and bounded exact commitments only |
| Term cohorts and Class Offerings | Cohorts & Classes tab | Native responsive Tables with linked-cohort visibility, source/readiness filters, and contextual actions |
| Schedule Requirements (canonical model: Scheduling Demand) | Generated review queue | Filtered read-only/edit-limited Table with source links and plain requirement summaries |
| Readiness check | Validation result | Infolist summary plus missing/invalid input Table |
| Generate Timetable (canonical record: Schedule Generation Run) | Generated Timetables Resource | Create Action/Form, confirmation, status badge, and polling read-only view |
| Generated timetable review | Candidate Assignments relation manager | Mobile-stacked Table with grouped secondary actions, filters, warnings, validation status, and a plain-language Solution Quality summary sourced from typed solver evidence, including one result for every applicable hard-constraint category and every recorded soft-objective term |
| Infeasible result | Diagnostic review | Exception Table linking to authoritative source records |
| Candidate correction | Controlled decision | **Adjust candidate meeting** Action with valid replacement choices, whole-candidate revalidation, and a quality-impact reason when required |
| Publication | Controlled decision | Read-only comparison followed by confirmed Action |
| Published revision | Controlled decision | Focused Action modal with impact preview and validation result |
| Published Timetable (canonical records: Section Meetings) | Official staff source plus Student/Faculty projections | Read-only mobile-stacked Table grouped by day and printable owner-scoped views |

The **Offerings & Scheduling** navigation group exposes **Class Planning** as the Registrar's primary workflow and **Assigned Schedule** as the Faculty projection. The authoritative setup and evidence Resources remain registered and policy-protected at their existing URLs, but the Class Planning page reaches them as contextual source records instead of presenting every database record type as a peer task. The Registrar may prepare offerings, generate requirements, review a candidate, and publish. The Academic Head may inspect the Class Planning flow and authorized scheduling evidence read-only. System Administrator access authority does not grant academic offering, candidate-correction, or publication authority.

Scheduling labels must remain understandable without optimization knowledge. A **Schedule Requirement** is one required course component for one standard-curriculum cohort; its canonical persisted model remains `SchedulingDemand`. **Coverage** is the number assigned divided by the number required. A **hard conflict** is a mandatory-rule violation. The **objective** is a ranking score, the **bound** is CP-SAT's limit on a possibly better undiscovered score, and the **relative gap** is the normalized distance between the returned objective and that bound. These are review evidence, not predictive accuracy or a student grade. Technical solver identifiers and provenance remain available in collapsed or toggle-hidden evidence fields rather than leading the operating view.

The Registrar is the V1 Master Schedule publisher. Academic Head access supports read-only scheduling-exception review, not a universal publication approval, and System Administrator access authority does not grant academic publication authority. Candidate runs close to mutation but remain retained as publication provenance. Whole-version replacement stops once active student bindings exist; subsequent operational changes use the focused published-revision action.

For MVP, TALA does not require a drag-and-drop timetable, FullCalendar plugin, generic constraint builder, or user-editable scoring weights. The Solution Quality presentation explains that `feasible` means valid without proved optimality, coverage and hard-constraint satisfaction establish acceptance, and objective/bound/gap describe optimization quality. A suitable plain-language summary is: **“Valid schedule found — 100% of demands assigned, 0 hard conflicts; optimality not proven within the time limit.”** The gap may be shown separately with an explanation that smaller is better; it must not be labeled predictive “accuracy.” No visualization task is currently planned; a future approved proposal must receive a new Next Steps issue and may supplement, never replace, the candidate review table or validation path.

Date-less class, availability, and operating-grid times are institutional Asia/Manila wall-clock values. Filament time inputs preserve the entered wall-clock value; true timestamps such as publication and audit time retain their timestamp semantics. Clinic 3 assumes no operating weekday, opening time, closing time, or break. Registrar records the approved values in the Term Calendar Package; scheduling uses a fixed code-owned 30-minute grid within them.

## Imports, Contextual Outputs, Notifications, and Plugins

### Imports

Course Specification and Curriculum imports use a custom Filament Page composed from native `FileUpload`, validation summaries, a preview Table, and an explicit Draft-creation Action. This preserves the PRD's versioned-template, full-preview, and all-errors-block-posting behavior. Current imports use the native CSV implementation; no additional import plugin is required.

### Contextual operational views and exports

There is no Reports navigation destination or shared report catalog. Clinics 1–5 keep operational counts, queues, histories, and printable outputs in their owning surfaces. Clinic 6 alone defines the two contextual finance CSVs: Account Status and Verified Payments. They preserve approved heading order and allowlisted fields, protect formula-like values, retain stable date/money semantics, and require purpose plus actor, role, filters, row count, request context, time, and outcome. Analysis, pivoting, charting, and broader reporting occur outside TALA.

### Generated outputs

| Output | Required presentation and authority cue |
|---|---|
| Application Acknowledgment | Authenticated submitted snapshot; explicitly not admission or enrollment proof |
| Published timetable and schedules | Official only after Registrar publication; version and owner scope visible |
| Registration Form / COR | Immutable official-enrollment version with assessment basis/source and position at finalization; later financial review may be identified but no live ledger appears |
| Unofficial Student Record | Labelled **Unofficial — for student reference** on screen and print |
| TOR | **Proposed institutional format — Not for official issuance** until exact template approval; Issued/Voided/Superseded states are explicit |
| Account Statement / SOA and Payment Acknowledgment | Authenticated non-tax outputs with source/as-of and reversal/supersession state |
| Clinic 6 CSVs | Contextual action only, allowlisted columns, stated purpose, and output-access evidence |

Generated browser outputs use configured institution identity, a clear document title and copy context, a consistent generated timestamp, responsive overflow for wide tables, one print/save-as-PDF control, and document-specific disclaimers. The shared presentation layer does not change source builders, role/owner authorization, version history, or output-access evidence. Failure produces no partial or official-looking artifact.

### Notifications

Filament notifications provide immediate success, warning, and error feedback after an action. Student Hub renders one owner-scoped priority notice from authoritative domain records; it does not expose a second persistent notification-center control. Clinic 2 queues idempotent email for submission, one consolidated Action Needed request, Admitted, Not Admitted, Ready for Enrollment, and withdrawal. Clinic 3 sends the Faculty availability request, first timetable publication, and one affected-revision event; Clinic 4 supplies its enrolled-Student recipients and updated schedule/COR context. Clinic 4 sends only the enrollment-window notice, proposal-ready/materially-revised notice, payment/coverage action request, official-enrollment/COR notice, reservation-release/case-expiry notice, and official adjustment/Course Drop notice. The first official-enrollment/COR notice also announces Student access, so neither activation nor timetable revision produces a duplicate email. Clinic 5 sends only Faculty submission, returned roster, grade release without values/attachment, policy-bound INC action/deadline, INC resolution or authorized lapse, authorized correction, consequential progress/lifecycle, completion action-required, and conferral notices. No applicable approved INC policy means no deadline message. Clinic 6 sends only **Verified payment posted**, keyed to the immutable posting reference and containing no tax-document claim. Proof submission/rejection, checkout return, exceptions, TOR clearance, reversals, health, exports, draft saves, routine checks, calculations, page activity, and recurring reminders produce no email. Database-editable templates remain outside MVP.

### Plugin policy

Approved baseline:

1. Core Filament v5 for authenticated UI.
2. Existing Auth Designer integration for Filament panel authentication screens, preserving the custom Applicant registration page.
3. Isolated Bootstrap v5.3.3 public assets for the public landing page; existing TallStackUI components remain available for other non-Filament Blade/Livewire surfaces with a documented need.
4. Hand-built read-only `ActivityResource` may be salvage evidence for Clinic 6 Governance & Audit; it has no separate Module 13 authority.
5. Native CSV import/export handling for fixed templates; no spreadsheet package is required.

Complete-authority approval does not approve any new plugin. Do not add a calendar, saved-filter, dashboard, permissions, import, or custom UI plugin unless a separately approved vertical task proves a capability gap, compatibility, maintenance cost, and focused verification plan.

## Future Vertical Slice Contract — Available Only Through Separate Planning

This checklist is not an active implementation plan and grants no implementation authority. The complete-authority gate has passed; the checklist becomes usable only inside a separately accepted vertical task under the orchestration protocol.

Before changing UI code under that later approved task, record the following for one user-visible capability:

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

A future slice is accepted only after its current behavior matches the complete approved authority set, focused tests pass, and its status is recorded in the authorized planning/sync workflow.
