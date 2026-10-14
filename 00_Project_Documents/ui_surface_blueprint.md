# TALA UI Surface Blueprint

## Purpose and Authority

This document defines what each role can recognize, navigate, do and recover from: capabilities, visible states, interactions, responsive/accessibility behavior and output presentation. It contains no implementation progress.

[PRDs 00–06](prd_modules/README.md) own behavior, fields, permissions and safeguards. [DESIGN.md](../DESIGN.md) owns colors, typography, material and motion. [Architecture](architecture_specification.md) owns technical/framework boundaries; the [Protocol](TALA-Orchestrator-Protocol.md) owns execution and verification. Impeccable and relevant skills guide task-specific composition within those contracts.

## Find the journey

- [Identity, access and public entry](#identity-access-and-public-entry)
- [Application and admissions](#application-and-admissions)
- [Academic preparation and Term Planning](#academic-preparation-and-term-planning)
- [Registration and official enrollment](#registration-and-official-enrollment)
- [Teaching, grades and academic records](#teaching-grades-and-academic-records)
- [Accounts, outputs and assurance](#accounts-outputs-and-assurance)
- [Shared role projections](#shared-role-projections): Student Home/Profile and Academic Oversight
- [Capability inventory](#canonical-ui-surface-coverage-inventory) and [journey coverage](#representative-journey-and-acceptance-contract)

Read the relevant journey and shared contracts. The expandable details below are required interface contracts, not archives. Open the source/field/state detail needed for your task; keep the rest out of the reading path. Assignments and acceptance evidence live in [#48](https://github.com/yosoykyle/SIA-TALA/issues/48) and child Issues.

<a id="mvp-ui-architecture"></a>

## Panels and role entry

<details>
<summary>Routes, fixed-role entry and navigation map</summary>

TALA uses the current three-panel baseline:

| Route | Product surface | Users | MVP use |
| --- | --- | --- | --- |
| `/` | Public Gateway | Public visitors | Institution identity, application availability, Apply, unified sign-in, bounded notices/FAQ, and official support/privacy/accessibility links |
| `/applicant` | Applicant | Verified Applicant accounts | Account entry and Clinic 2-owned application journey; existing accounts remain accessible while application entry is closed |
| `/student` | Student Hub | Accounts with persistent Student access | Historical and current authorized Student projections; current-term eligibility controls actions |
| `/admin` | Staff Workspace | Registrar, Accounting, Faculty, Academic Head, System Administrator | One active fixed-role context at a time, with role-scoped operational work |

MVP decisions:

1. Faculty remains inside `/admin` with role-scoped navigation and policies. TALA does not add a fourth panel for Faculty Workspace.
2. Registrar, Accounting, Academic Head, and System Administrator share `/admin`. Navigation visibility improves usability; policies and action authorization enforce access.
3. Applicant and Student surfaces remain separate projections over one credential account. Official enrollment grants persistent Student access; it does not create another account.
4. Authentication UI stays in the Filament panels. Laravel Fortify remains the backend authentication contract for login, registration, verification, password reset, and custom response handling where already integrated.
5. The public gateway retains one isolated Bootstrap landing page with task-fit composition and the approved visual identity, organized around the required task order in the Clinic 1 blueprint. It may project factual active Programs and admission availability, embed the approved institution map with an external-link fallback, and use a small tracked institution-approved hero-media set. Bounded About Servitech and Program details reuse approved facts and contextual return/Apply links. Long institutional essays, galleries, duplicated marketing records, arbitrary media management, page building and general CMS behavior remain excluded; new events/news presentation is deferred. Authenticated work remains Filament-first.
6. Filament resources, pages, tables, forms, infolists, actions, filters, widgets, and notifications are the default authenticated UI toolkit.
7. Filament components are the default toolkit, not a ceiling on usability. Reuse compatible installed components when they serve the task; use a focused TALA-owned visualization or interaction when a stock table, form, or modal makes the authorized workflow harder to understand or complete. Preserve validation, authorization, accessibility, and existing compatible plugin responsibilities. Materially bypassing or replacing an installed plugin still requires explicit approval and replacement proof; new dependencies require separate approval.
8. Auth Designer remains the branded authentication presentation layer around native Filament/Fortify behavior. Preserve its approved Cover/split layouts, media, and theme controls through supported configuration while verifying recovery, profile, email-change, MFA, responsive, and accessibility behavior. A proven incompatibility is a bounded decision to report before bypass or replacement. Applicant registration remains a focused custom page only if needed to enforce the approved minimal account-creation contract.

The following is the final primary-navigation authority. A registered route or legacy resource does not become a primary destination merely because it exists. Account Security is contextual in the account menu. Readiness is shown inside the action that consumes it and is not a separate destination.

| Context | Primary navigation | Contextual/shared destinations |
|---|---|---|
| Public | Gateway | Apply when Clinic 2 entry is open; unified sign-in; notices/FAQ; support/privacy/accessibility |
| Applicant | Home; Application | Requirements and acknowledgment from the owning application; Account Security in account menu |
| Student | Home; Enrollment; Academics; Finance; Profile | COR, schedules, history, unofficial/finance outputs; Account Security in account menu |
| Registrar | Learner work: Admissions; Students & Enrollment; Grades & Completion. Academic setup: Catalog & Curricula; Term Planning | Source records, official outputs, and history from the owning workbench; permission-aware page search includes curated essential setup records |
| Accounting | Fee Plans; Student Accounts | Accounts and Payment Exceptions; contextual output restrictions and account outputs |
| Faculty | Schedule & Availability; Grade Rosters | My Schedule/My Availability are connected exact-Term sibling tasks and role-scoped search destinations; own assignment, declaration, roster, and history details |
| Academic Head | Academic Oversight | Read-only source-owned catalog/curriculum, timetable, grade/progress, lifecycle, and completion evidence |
| System Administrator | Users & Access; Public Content; System Health; Governance & Audit | Account detail, locally evidenced diagnostics, and read-only audit/history |

There is no primary Reports, Settings, Approvals, Readiness Center, duplicate integration-status, notification-center, or report-hub destination. Navigation visibility is never authorization.

### Deterministic workspace entry

TALA gives every role a complete starting state without adding a Staff dashboard or global work queue.

| Role | First authorized destination | Starting-state and correction behavior |
|---|---|---|
| Public | Public Gateway | Closed or unavailable actions retain existing-account sign-in, bounded public guidance, and official support |
| Applicant | Applicant Home | The page leads with the current owner and next action and links to Registrar guidance without exposing Staff evidence |
| Student | Student Home | Source-labelled Enrollment, Academics, and Finance summaries remain separate; Profile is read-only |
| Registrar | Admissions | Persistent navigation reaches the five canonical Registrar workbenches |
| Accounting | Fee Plans | Persistent navigation reaches Student Accounts and its contextual clearance/action tabs |
| Faculty | My Schedule inside Schedule & Availability | Persistent navigation reaches Grade Rosters; exact-Term sibling navigation and role-scoped search reach My Availability |
| Academic Head | Academic Oversight | Every drill-in remains read-only and source-owned |
| System Administrator | Users & Access | Persistent navigation reaches Public Content, System Health, and Governance & Audit |

A single-role Staff account enters its fixed destination directly. A multi-role Staff account chooses an authorized role context and then enters that role's fixed destination. A role switch always resolves a fresh authorized destination and never carries a prior role's record route. After first official Student activation, Applicant no longer appears in the normal chooser, while the underlying application remains retained Registrar evidence.

</details>

<a id="human-centered-operations-and-ethical-decision-presentation"></a>

## Shared interaction contracts

<details>
<summary>Status, fields, confirmation, evidence and accessibility patterns</summary>

The following rules apply to every coverage ID:

1. A default may select only an unambiguous authorized workspace, exact current Term, current record, or role-scoped actionable queue. Its basis remains visible and the user can change it easily.
2. Consent, payment, publication, release, issuance, access change, destructive actions, and other consequential decisions are never preselected.
3. Progress appears only for a fixed journey and comes from persisted canonical state. Use meaningful completed/current/remaining labels and `aria-current="step"` where appropriate; never invent percentages, bonus progress, or a universal cross-clinic progress bar.
4. Saved, submitted, immutable, and recoverable states appear only when attributable persisted evidence proves them. Unsaved changes are identified factually.
5. A safety warning states the exact consequence, source, applicable date, responsible owner, and recovery path. TALA uses no fear, shame, artificial scarcity, countdown pressure, hidden alternatives, decoy choice, or exaggerated harm.
6. Primary/secondary, current/proposed, official/unofficial, available/blocked, and ordinary/destructive distinctions use semantic hierarchy without relying on color, motion, or position alone.
7. Providing requirements, explanations, support, or previews is ordinary service design and never creates an implied obligation to provide data, consent, payment, or another action.

Ordinary loading, saved, error-status and success updates use accessible status messages without stealing focus. A failed form submission is the deliberate exception: focus its first visible invalid control or existing purposeful error summary, with the field's inline error programmatically associated. Responsive transformations preserve reading order, current context, alternatives, and the owning recovery action.

### Source-backed user outcomes

Source provenance and concern mapping live in the [existing #48 evidence register](https://github.com/yosoykyle/SIA-TALA/issues/48#issuecomment-5918342932). These clarifications apply through each owning journey; they do not start an all-screen implementation.

- Applicant Home leads with factual progress, current state, remarks/date and a permitted next action; compact prior applications and acknowledgments remain accessible. Waiting/rejected/closed states cannot promise Continue.
- Application fields use plain visible labels and meaningful required/optional cues. Related fields may share desktop rows and stack on mobile. Save/continue wording reflects real persistence. Malformed/future graduation years receive field-accurate validation. The exact changed intake/requirement contract must be reconciled before collecting additional fields or changing eligibility.
- Private evidence is accessible at the review step, with current/history distinction. Digital review acceptance, admission, Registrar enrollment clearance and enrollment readiness remain recognizable separate facts; detailed paper handling stays outside TALA.
- Student Finance never leaves a labelled fact blank: actual values, zero, unavailable sources and no existing assessment are distinct. Obligations/coverage/payments show truthful empty or unavailable guidance. Text-backed statuses and measured contrast apply in both appearances.
- Breadcrumbs use recognizable page labels while stable record references remain visible in context. Reached failures show safe truthful recovery without internal paths; execution-limit failures are diagnosed separately from ordinary session expiry.
- Public discovery uses a cohesive academic school composition led by Servitech identity, approved artwork and factual Programs/notices/location/support. Program presentation and concise admission deadlines support discovery; existing-account Sign in remains clear without dominating. Owner annotations describe concerns to evaluate against existing product truth, source behavior and usefulness, preserving essential capabilities and safeguards. Retain the navbar, top/bottom blur-strip and footer background effects while recomposing the body, navbar foreground alignment, responsive branding, sign-in placement and footer information hierarchy. Grouping, imagery, map embedding and content depend on supplied facts and the existing bounded public-content contract; no invented institutional claims, repeated system explanation or universal card template is required.

PRD 02 Sections 8–10 own necessary intake, optional client-requested demographics/address detail, one conditional contact, explicit LRN availability, selected preliminary uploads and one Registrar enrollment clearance. The accepted journey is reused. PRDs 04/06 retain payment order, versioned Accounting data and externally executed refunds. Missing operational amounts or requirement versions show their actual unavailable state; the interface never supplies an invented fee, age limit or refund entitlement. Old code/screens are evidence to reconcile, not acceptance.

### Field and action clarity

Apply the shared rule in baseline Section 3.5 across every retained role/surface; the existing inventory remains the coverage map. Every input has a declared actor/purpose, source, collection stage, plain persistent label, requiredness, format and exception. Derive/reuse facts instead of asking twice. Keep exact code/record vocabulary in source detail when necessary; it does not become ordinary headings or helper text. State whether an action saves, submits, confirms, publishes or merely navigates. Useful errors name the field and correction, not an unrelated required error.

Impeccable leads composition, supported by the relevant writing, layout and accessibility skills. Determine the information and interaction the user's task requires, then use Laravel Boost, official framework documentation and official demos to research suitable implementations for the installed versions. Select and justify the arrangement by usability, workflow, accessibility and engineering feasibility. Use the existing framework's supported customization where the task requires it, preserving the authenticated Blade/Livewire/Tailwind and public Bootstrap boundaries. Each owning child records the chosen improvement, retained behavior and rendered result.

Concern provenance is recorded in the linked #48 register; this is not a local progress queue.

- Public discovery/access: F01–F13, F20–F21, F26–F29, F49, F53, F58; S06
- Applicant status/intake: F14–F19, F22–F25; S10–S12
- Registrar review/clearance: F30–F35; S04
- Setup/catalog/account plans: F36–F37, F47, F50–F52; S01–S02, S07–S08
- Scheduling: F38–F39, F51, F56; S03, S07
- Enrollment/Student Home: F40–F42; M01–M04, M06–M07
- Finance: F48; S05, S13
- Faculty/academic records: F43–F46, F54–F57; S08
- Outputs/governance: F59–F64

M01–M07 and the recorded C clarifications apply through their owning outcomes across this map. F31/F33/F35 cover shared navigation and spacing; S09 covers useful duplicate correction and S13 covers measured status readability. The linked register preserves original feedback, proposed solutions and decision history. Each slice traces its applicable concern to the retained/simplified capability, chosen interaction and rendered evidence.

Preserve brand/theme, role authorization, visible focus, keyboard order, mobile reading order, error associations and data continuity. Native components are selected for task fit; dependency additions and implementation remain separately bounded.

| PRD interaction form | Required interaction outcome |
| --- | --- |
| Record Form | Users create or edit an owned record through labelled, validated inputs and authorized actions. |
| Focused Record Form | Users understand the consequence and provide only the necessary decision, reason, authority, date and evidence. |
| Restricted Record Form | Authorized users configure the bounded service or security responsibility; secrets remain write-only or masked. |
| Editable Table | Users identify the affected rows, edit permitted values and recover from row-specific validation or concurrency failures. |
| Selection List | Users can recognize and compare eligible choices, including conflicts and capacity where relevant. |
| Checklist | Users understand each requirement, its source, current result and remedy; recorded outcomes remain attributable. |
| Calendar / Date-Range Input | Users enter valid dates or times and understand the relevant period, timezone and relationship. |
| File Upload with Preview | Users understand the required file, upload it privately and inspect the exact authorized version with truthful validation feedback. |
| Operational Queue / Review Table | Users find the role's next work, recognize its state and open the appropriate review or action. |
| Filter Form | Users understand the search/filter scope, active choices, result count and clear-filter recovery. |
| Generated Read-Only View | Users read the authoritative result and its source/version; corrections lead to the owning source record. |

The implementation and any design artifact use these named component families and variants:

| Family | Required variants and annotation |
|---|---|
| Shell | Public; authenticated native sidebar-only expanded/compact desktop and mobile menu/drawer with complete authorized navigation; Applicant, Student, and Staff contexts |
| Navigation | Sidebar, mobile menu trigger/drawer, workspace switcher, account/theme controls, default/current/disabled item, and purpose-defined optional workload count |
| Location | Breadcrumb, contextual Back link, browser/page-title example |
| Page header | Title/context, one primary action, discoverable secondary actions, no-action/read-only variant |
| Status and metadata | Status badge with icon/text; owner, source, version, as-of time, deadline, and immutable marker |
| Guidance | Next-action banner, failed-first readiness list, safe explanation, and responsible-office path |
| Workbench | Recognizable work groups, contextual search/filters, active scope/result count, current record state and discoverable actions, with usable narrow-screen transformation |
| Form | Field group, visible label/help, required/optional state, upload/evidence preview, error summary, and Wizard stepper |
| Read-only evidence | Infolist/summary, activity timeline, version history, and output-access evidence |
| Scheduling | Weekly timetable plus equivalent accessible meeting table and result/failure summary |
| Feedback | Alert, toast, empty, filtered-empty, loading, stale, inaccessible, unavailable, validation, and failed-action state |
| Dialog | Named confirmation, consequence summary, exact confirm label, contained focus, Escape behavior where safe, and focus return |
| Output | Authenticated screen summary and print-safe document frame |

Each component contract records semantic role, accessible name, heading relationship, focus order, keyboard behavior, minimum 24×24 target, preferred 44×44 learner target, responsive transformation, error/status announcement, and any screen-reader-only text. Tabs require arrow-key movement; dialogs require initial and returned focus; failed form submissions require deliberate first-error or error-summary focus and field association. No action depends on drag, hover, color, motion, or a pointer.

### Shared validation, conflict, evidence, and confirmation patterns

**Validation summary.** On failed submission, focus the first visible invalid control in the submitted form, or its existing purposeful error summary with links to invalid fields. A stable polite summary can announce the correction without taking focus from that field. Each field retains its safe input, visible label, constraint, associated inline error and required/optional status; native custom Selects expose the same invalid/error association as inputs. State that nothing was recorded only when the guarded operation establishes that outcome. Clear shared error attributes and summary text when the error disappears, preserving unrelated authored descriptions. Cross-record errors name the authoritative source and responsible owner rather than inventing a local override. Ordinary status updates preserve useful keyboard position.

**Stale or concurrent conflict.** A stale mutation closes any success/loading state, announces **Nothing changed — newer information is available**, names the changed source/version and as-of time, preserves safe uncommitted text where possible, and offers **Review latest information**. Academic, financial, security, publication, enrollment, and output facts are never silently merged.

**Private evidence.** Upload controls accept one PDF, JPEG, or PNG up to 10 MiB per evidence version, state that access is restricted, show upload/scan/validation progress without implying acceptance, and distinguish replace from delete. After authoritative use, replacement creates a successor and no delete action appears. Preview and download require purpose-scoped authorization and access evidence.

**Authority metadata.** Decision facts and the permitted next action lead. Derive routine actor/time; separate approval references are optional for ordinary actions and required for genuine exceptions under the owning PRD. Policy/source evidence remains required where it establishes the institutional rule. Supporting version/date/as-of and history are accessible through staff detail; learner copy states the short reason, responsible office and safe remedy.

**Critical-action confirmation.** A labelled native modal contains the record/version, actor/authority, exact resulting state, affected roles/records/emails/outputs, reversibility or successor requirement, required reason/authority fields and an exact button label. Structured previews and forms use the [WAI-ARIA modal-dialog pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/); brief important interruptions use its [alert-dialog pattern](https://www.w3.org/WAI/ARIA/apg/patterns/alertdialog/). Initial focus goes to the heading or first invalid required field, focus remains contained, safe Escape/cancel is available and focus returns to the trigger. Failure records no institutional mutation and announces whether input was retained.

**Source-owned restriction and setup pattern.** Baseline Sections 3.2 and 3.7 and the owning PRD define the actual blocked action and condition. An authorized user sees the unavailable action with persistent safe reason, missing/current source, responsible office and exact remedy; a source link is shown only when authorized. Meaningful Draft setup and independent work remain available. Do not substitute hidden buttons, hover-only explanations, a general hold editor or model/schema labels for this contract. External Academic Head decisions are recorded by Registrar; read-only oversight gains no mutation permission. Internal reasons stay private, and output-scoped financial restrictions remain distinct from academic/administrative decisions. The server rechecks the same source and authorization before mutation.

| Owning area | Material actions covered | Primary/secondary placement and exact consequence | Shared state and responsive behavior |
|---|---|---|---|
| PRD 01 | Change email/password/MFA; invite/resend Staff; role change; disable/reactivate; MFA reset; publish/unpublish content | One current security/access/publication action is primary on its detail page; alternatives remain discoverable. Confirmation names sessions, workspaces, public visibility, invalidated links/codes, and email effects | Rate-limit, expired-token, duplicate-safe, final-admin, stale, mail-failed, and inaccessible variants; mobile uses one-column forms and full-width learner actions |
| PRD 02 | Submit/discard/withdraw/reopen Application; request/resubmit correction; publish/extend/close/cancel Cycle; decide/supersede; record/supersede Registrar enrollment clearance | Applicant page owns submit/withdraw; Applicant Record owns review/decision; Cycle detail owns public and correction boundaries/publication. Confirmation names snapshot, deadline, reopened fields, readiness, Applicant message, and no Student creation | Wizard preserves safe steps; public close and correction boundary remain distinct; overdue active correction remains action-needed; identity duplicate remains non-disclosing; evidence/upload, stale Cycle, filtered-empty queue, and mail failure use shared variants |
| PRD 03 | Activate/retire academic authority; confirm/cancel offering; generate/retry; locally adjust/find minimal repair; accept/reject candidate; publish/revise timetable | Setup/detail pages own activation; Generate & Review owns run/candidate actions and full change preview; Published Timetable owns publication. Confirmation names source snapshot, affected classes/roles, quality/impact, email, and immutable output | Dense Staff tables transform to labelled cards where practical; timetable has accessible table alternative; local invalidity changes nothing; repair never moves another meeting silently; failure states stay distinct |
| PRD 04 | Confirm/assist/cancel proposal; reopen terminal case; finalize enrollment; adjust; Course Drop | Learner Enrollment owns confirmation; Registrar selected Case owns assisted/cancel/reopen/finalize/change actions. Confirmation lists courses, units, meetings, capacity, finance readiness, Student activation, rosters, COR, and Accounting review | Mobile learner shows subjects/payment/next action with concise blockers and accessible supporting facts; terminal reopen retains the same case and restores no prior checkpoint; shortage, expiry, stale source, unavailable assessment, failed atomic finalization, and inaccessible variants state whether anything changed |
| PRD 05 | Submit/return/release roster; INC completion/deadline amendment; correction; external result; lifecycle/academic decision; graduation/conferral; TOR issue/void/replace | Faculty roster owns submit; Grades & Completion owns release/corrections/decisions; TOR detail owns outputs. Confirmation names rows/result effects, averages/curriculum/enrollment, deadlines, lifecycle, template/source, and successor history | No partial roster/average/output state; every INC has `CompletionOpen` or `CompletionOverdue` until resolved and never auto-converts; an overdue unextended INC remains no-credit with a retake path; factual advising replaces automatic sanctions; mobile Student is read-mostly |
| PRD 06 | Publish/supersede Fee Plan; record assessment/coverage/payment; reject/reverse; TOR clearance; export | Fee Plan detail and Student Account detail own mutations; exception/clearance tabs own resolution; exports remain contextual. Payment confirmation names exact PHP amount and each current-Assessment obligation effect; other confirmations name account/Assessment, remaining requirement, email/output, and append-only effect | Amounts use tabular numerals; private proof is purpose-scoped; no unassigned/cross-Term posting; pending/mismatch/duplicate/stale/unknown/output-failure variants never imply posting, health, refund, or tax-document authority |

Routine save, search, filter, pagination, tab selection, read-only projection, preview, and calculation actions never receive a redundant confirmation. All material actions use the owning PRD's authorization, validation, retry/deadline, idempotency, and audit contract in addition to this presentation mapping.

Present operational data in this order: selected scope and purpose; the records being worked on with decision-critical facts; truthful state and the permitted next action; supporting record detail; optional quality measures, history and technical provenance. A directory of links is insufficient when the task is to prepare or review records. Explain a missing prerequisite beside its affected record and link to the remedy. A source count or recorded deadline must never stand in for whole-task readiness.

Counts represent useful authorized work. Prioritize status, responsible owner, remarks, and next action over oversized summaries. Essential feedback accompanies each affected workflow; additional motion polish follows verified functionality.

Shared form errors remain visible beside their owning controls, including native custom Selects. Associate each inline message with the actual control through `aria-describedby`, preserve existing description tokens and authored IDs, and mark the current invalid state through `aria-invalid`. After a failed submission, focus the first visible invalid control in that submitted form, or its existing purposeful error summary; ordinary loading, saved, filtered and status updates preserve focus. A stable polite summary announces the need to correct fields without implying spoken assistive-technology verification. When errors disappear, clear the shared bridge's error association, invalid state and announcement while retaining unrelated authored semantics. Preserve safe input and native validation/authorization; duplicate-record feedback names the conflicting source and the available correction. Mobile drawer actions that open a content dialog first close the drawer through the native sidebar store, then open the native confirmation after its focus trap releases; cancellation returns to a visible control.

**The Same Intent Rule.** Equivalent commands, selections, record inspections and record-management destinations share native components and interaction behavior across roles. Role authorization controls available work, rather than changing what an identically named control does. Registrar and Academic Head use the same Class details inspection dialog; a separate permission-bound Manage class link opens sustained editing, delivery-group and source-history work. Meeting details opens a bounded course/time/location-first inspection dialog. A dialog-opening meeting button carries no checkbox or pressed selection state.

**The Working Record Rule.** One compact selected scope and task heading precede the toolbar and records. Course title leads a class/meeting; time and location lead schedule inspection. Comparable working lists use native searchable, sortable, pageable tables with the same controls and page choices; changing scope resets the page. Fixed teaching-day summaries, timetable geometry, complete generation-confirmation scope and official print tables retain their task-specific structure. Align values, wrap names, use tabular numerals and offer clear empty/filter recovery. Status labels name source state; selection, validation and publication remain distinct. Missing facts remain unavailable. Official outputs preserve issuer, immutable source/version and full/subset scope.

**The Evidence Presentation Rule.** Keep the selected Term, actual official/draft status, responsible owner and next action visible beside working records. A clearly named source entry point should help users judge the relevant fact. Detailed source version, actor, date and history belong in Class details or Calendar source details rather than an always-open generic evidence rail. Keep every authorized source destination/history reachable. Native read-only dialogs or inline expansion support inspection; sustained editing retains its own page. Narrow inspection preserves a usable working area, and closing restores focus/context. Academic Head keeps the planning summary; Faculty keeps its current answer/official context; Registrar keeps source state and the blocked-work remedy.

</details>

<a id="shared-authenticated-shell-and-navigation-authority"></a>

## Shared shell and navigation

<details>
<summary>Location, return paths, account controls, shell states and Quick tour</summary>

### Workspace shell

The authenticated shell uses Filament’s sidebar-only authenticated layout (`topbar(false)`), native expanded/compact desktop navigation, a native mobile menu trigger and drawer, and one main region with ordinary page scrolling. Use the installed framework’s responsive behavior rather than a separate bespoke breakpoint or mobile bottom-navigation system. Until a role’s adaptation is accepted, its existing accessible navigation remains the working baseline. This changes presentation, not permissions or the supported mutation capabilities of a viewport.

The expanded sidebar and appropriate mobile identity area show one compact school-identity block with the crest and **Servitech Institute Asia Inc.** or **Servitech Institute Asia**. Student/Staff workspace/role and selected Term context identify the current work separately; their quiet *Powered by TALA* attribution belongs in the sidebar footer or shell footer. Applicant shell identity shows the school plus the signed-in Applicant's name, without `Applicant Workspace` or *Powered by TALA*; Applicant account-entry pages show school identity without those labels. DESIGN.md owns the shared composition. Keep the multi-role workspace switcher when applicable, Account Security, sign-out, and native Light/Dark/System controls reachable through the native sidebar/user menu or supported extension points. Shared account-menu **Sign out** requires confirmation and warns users to save their work first; **Stay signed in** preserves the current page and session, while confirmation uses the existing protected logout flow. Primary navigation order follows the Panel and Navigation Map above and remains stable across pages. The first focusable control is **Skip to main content**.

The shell uses one labelled primary `nav`, `main`, a labelled account menu, and a semantic `header` where a header is present. Opening the native mobile drawer moves focus into it; Tab remains contained while it is modal; Escape closes it where safe; and closing returns focus to the trigger. Preserve existing accessibility safeguards until the replacement passes equivalent verification. The current destination is expressed in text and `aria-current`, never by color alone. Hiding navigation never authorizes or deauthorizes a route, query, action, download, or output.

Staff and Student sidebars offer Find a page through native Filament global-search presentation; the two-page Applicant workspace uses direct Home/Application navigation without a page finder. It searches only visible, authorized destinations in the active workspace, including accessible cluster pages; it returns no learner records, private details or cross-role destinations. The existing table search owns record lookup. Expanded navigation places Find a page inside the native search field beneath school identity and names the selected role workspace above the compact navigation group. Identity, search, navigation and framed account controls share alignment edges. Compact navigation offers a named search icon, and Ctrl/Cmd+K opens and focuses it. Collapsed desktop navigation retains visible inset dividers around identity/collapse, search, native destination groups and the account footer, so hidden group labels do not erase grouping. These dividers separate groups rather than every destination; expanded/mobile navigation retains its native labels. Both expanded and collapsed sidebar destinations use the same solid surface and subtle edge treatment, readable icons and native stroke weight. Preserve the seated current destination, visible keyboard focus, tooltips and accessible labels; identifying icons provide contrast without heavy decorative outlines. Primary navigation, contextual links and breadcrumbs remain available. Wizards retain their own process sequence.

### Breadcrumbs, contextual back links, and location

- Public Gateway, Applicant Home and Student Home do not show breadcrumbs. Staff workbenches may show their workspace and current task to provide orientation; Term Planning shows its exact Term and selected task. Its native cluster sub-navigation identifies Overview, Calendar, Sections & classes, Faculty & rooms and Timetable separately from the location trail, without introducing new gates or fabricated completion.
- A genuinely hierarchical Staff detail or setup page shows `Workspace > Primary destination > Current record` in a labelled breadcrumb navigation with an ordered list and `aria-current="page"` on the current item.
- Wizard and process steps never use breadcrumbs as progress indicators.
- Learner COR, acknowledgment, historical output, and record-detail surfaces use a named **Back to [owning page]** link instead of a Staff-style hierarchy.
- At narrow widths, a long breadcrumb becomes one semantic parent link followed by the current H1. It never becomes a browser-history-only **Back** action.

### Page-title and action hierarchy

Every page uses this order:

1. Browser title: `[Page] | [Workspace] | TALA` for Student/Staff. Applicant titles identify the page and Servitech Institute Asia, plus the signed-in Applicant's name when available; omit Applicant Workspace and Powered by TALA.
2. Exactly one H1 describing the page purpose.
3. Optional context line containing only relevant record, Term, Program, state, owner, source/as-of time, or deadline facts.
4. One primary action when the current state permits the user to act. A waiting state leads with the responsible office, reason and enabling condition; useful review/help links remain secondary. On learner mobile views a permitted primary action enters normal flow below the heading and may become full width inside the 16-pixel content inset.
5. Secondary actions remain identifiable and reachable through task-appropriate controls; destructive or superseding actions require deliberate selection.
6. Failed readiness, action-needed explanation, or one safe next step before supporting data.
7. Search, filters, active filters, and result count before an operational queue or table.
8. Supporting evidence and immutable history after the current decision.

Controls retain a distinct border, fill, or stable action zone and never look like adjacent static content. Grouping uses spacing before additional rules: gaps between sections are at least twice the gap between tightly related items. Content aligns to shared leading edges and uses logical start/end behavior. Long labels, references, and translated strings wrap without clipping actions.

### Optional Quick tour

Authenticated Applicant, Student, and each Staff role may use one short, role-aware **Quick tour** implemented with the installed Driver.js 1.4.0 dependency plus a small TALA wrapper. Public visitors receive no tour. On the first successful entry for a credential, authorized role, and tour version, TALA shows a non-blocking invitation; it never opens the overlay automatically. **Quick tour** remains replayable from the account menu. Dismissal or completion suppresses only that role and version's invitation.

The static steps cover only the current workspace/role, canonical navigation, explicit Term context when present, owner/status/next-action presentation, the page's primary-action location, and Account Security/replay. The tour never navigates, switches a Term or role, opens mobile navigation or an overflow drawer, enters data, explains private record contents, or performs an institutional mutation. Before starting, the wrapper removes steps whose targets are not present or authorized; it does not rely on a newer missing-target option. If no usable step remains, ordinary navigation continues and no completion preference is recorded.

The tour uses visible **Next**, **Previous**, **Finish**, and **Close** controls; Escape closes; focus remains within the named dialog and returns to the invitation or replay control. Screen readers receive the title, description, and **Step x of y** progress in meaningful order. At mobile widths, the visible native menu trigger or other authorized controls may be highlighted, but the drawer is never programmatically opened; a target-free centered explanation may replace a hidden desktop target. With reduced motion, animation and smooth scrolling are disabled. The tour sends no third-party request, captures no DOM or record content, records no grade, application, finance, or identity value, and adds no analytics. Failure changes neither business state nor the dismissal/completion preference.

No onboarding checklist, dashboard, tour editor, database-driven workflow builder, or new plugin is introduced. Later implementation acceptance must qualify Driver.js 1.4.0 with keyboard, NVDA or equivalent desktop screen reader, TalkBack or equivalent mobile screen reader, 360/390 mobile, and reduced-motion behavior; inability to pass that bounded contract reopens only the tour disposition and never blocks ordinary workspace use.

DESIGN.md owns shared visual rules; this Blueprint owns interface outcomes. Laravel Boost and installed-version official Filament/Bootstrap documentation are primary implementation references; the official Filament demo supplies optional rendered examples of native behavior. `design-evidence/layout/` supplies supporting visual evidence. Use native Heroicons for production interface icons and the owning PRD for school capabilities and data.

Use native Filament components and supported configuration first, then existing compatible extensions. Investigate a version-compatible plugin only for a demonstrated gap, with separate approval before adding a dependency. Focused custom UI remains appropriate for justified school workflows that native components cannot adequately present. No new plugin or paid theme is required for the inspected Applicant–Registrar journey. PRDs continue to own business behavior; framework convenience does not silently remove a required field, state, approval, safeguard, or output.

Shared interaction rules apply shared compact identity, Account Security composition, account-menu naming, usable content width and tactile action/selection styling across Filament roles. Reuse common configuration and theme classes; keep authorization and field policy contextual. Green carries the primary action and selection; neutral controls and icon actions have visible boundaries in light and dark appearances. Navigation, status and disabled controls retain distinguishable meaning and native semantics. Task-led judgment and rendered evidence determine composition and dimensions.

Registrar Admissions leads with the person, state, responsible party and next action. Full stable references remain accessible and copyable in record context. Current private evidence supports separate authenticated browser View and attachment Download through the same exact-version/path/permission boundary. Native breadcrumbs retain queue context. Cycle setup/detail explains availability, accepting scope, separate application/correction deadlines and publication blockers; PRD 02 owns every required field and transition. Acknowledgments lead with school issuer, applicant/program/submission facts and receipt meaning; source/version/history remain reachable and included in printing.

**Shell states**

- Use native expanded/compact desktop sidebar states with explicit keyboard/touch-operable collapse controls.
- Use the native mobile menu trigger and drawer. Ordinary scrolling does not require automatic chrome hiding, repositioning, or a second navigation system.
- Preserve entered values, the active task, useful focus, content continuity, and explicit collapse preferences through navigation and native state changes.
- Keep workspace switching, account security, sign-out, and theme controls reachable through the native account/sidebar arrangement or supported extension points.
- Preserve safe areas, readable content spacing, touch targets, and static current-location cues. The virtual keyboard must not obscure a focused control or required action.
- Verify drawer focus containment, background protection, safe Escape dismissal, and focus return; preserve existing accessibility fixes until the native adaptation passes equivalent checks.
- Retain every authorized canonical destination in stable order. The reference’s example menus do not introduce production destinations or change permissions.
- Do not implement hover expansion, drag/keyboard resizing, scroll-driven shell movement, or mobile bottom navigation solely to reproduce archived prototype mechanics.
- Define each search control’s purpose, scope, results, empty behavior, and authorization in the owning child. Reuse source-owned contextual search; do not create a global record-search service.
- Reduced motion preserves every function through non-animated state changes or an accessible equivalent.

**Information and action hierarchy**

- Lead with the current task/state, responsible owner, relevant remarks or deadline, and one meaningful primary action.
- Surface necessary evidence and permitted controls at the workflow step where they are used; progressively disclose secondary detail.
- Missing information or interaction is assessed against product purpose and authority. It does not automatically authorize additional fields, data collection, persisted states, or approval steps.
- Tables, labelled cards, inline sections, and dialogs are selected by task and information density, not by a universal template.

**Navigation counts**

- Show counts only for a defined, useful workload or state.
- Use the role-authorized authoritative query and lead to a destination exposing the matching records and context.
- Define scope and refresh behavior in the owning child. Navigation or a relevant completed action may refresh the projection; do not imply continuous monitoring.
- Default to hiding zero badges. Unavailable or stale evidence must not be converted into a false zero.
- Give the count an understandable accessible meaning. Do not introduce a separate notification or analytics subsystem merely to produce badges.

**Feedback and motion**

- Include loading, validation, empty, stale, failure, success, and inaccessible behavior in each affected slice.
- Necessary transitions use native feedback and the existing motion safeguards, preserve static state cues, and respect reduced motion.
- Native interaction feedback is implemented with each affected workflow. Additional motion polish follows verified functionality; decorative page-load choreography is not required.
- Routine success notifications may auto-dismiss and provide an accessible close action. Errors or notifications containing an action remain until dismissed. Result changes receive appropriate announcements; table updates preserve useful keyboard position. Route/view changes provide meaningful orientation.
- Dialogs preserve background protection, safe Escape behavior, and focus return. Recovery pages expose a truthful explanation and working recovery destination; the selected recovery-background effect follows the finite animation contract below.

**Acceptance**

- Verify native expanded/compact desktop states, the mobile menu/drawer, ordinary long-page scrolling, long labels/content, both appearances, reduced motion, keyboard/focus behavior, and 200% zoom.
- Preserve theme preferences, authorization, private evidence access, and the accepted #56 sign-in/MFA behavior.
- Screen-shell changes do not alter official-output or print contracts.
- Adoption must prevent the documented prototype shortcut, naming, focus, announcement, misleading-status, and forced-colors failures. Qualify keyboard and screen-reader behavior, zoom/reflow, both appearances, interruption recovery, and supported viewport/action boundaries through the destination framework; prototype checks alone cannot establish application acceptance.

Student/Staff shell and non-Applicant authentication attribution remains compact and secondary to school identity. Applicant shell and account-entry pages omit Powered by TALA; their school identity includes the signed-in Applicant's name when available. Choose screen mark sizes by legibility, hierarchy and available space. Preserve approved artwork and official-output print constraints. Native MFA code digits remain centered, readable and clearly bounded without replacing input, validation, focus or paste behavior.

Use Filament’s sidebar-only layout, native desktop collapse, mobile menu/drawer, and ordinary page scrolling, with supported theming and extension points. Preserve complete authorized navigation, account/role/theme controls, safe areas, focus, task data, and reduced-motion access. Verify this behavior within each owning delivery slice.

The expanded sidebar has one compact school-identity block: the supplied crest and readable school name. Student/Staff workspace or role context sits with navigation or the page context. Powered by TALA occupies their quiet sidebar footer, with a shell-footer placement when the sidebar is unavailable. The Applicant panel instead shows Servitech Institute Asia plus the signed-in Applicant's name, without Applicant Workspace or Powered by TALA; Applicant account-entry pages retain the school identity without those labels. The mobile identity area shows the school once while the native drawer owns navigation. Page headings lead with the task. Preserve approved artwork and readable school identity. All roles reuse the same school-first sidebar, framed account-menu trigger and responsive action defaults. The page finder supports staff and student destinations; the two-page Applicant workspace uses direct navigation. The expanded header balances the crest and two-line Servitech / Institute Asia name with the native collapse control at the trailing edge. Student/Staff selected roles name their workspace separately from school identity; Applicant identity uses the person's name rather than a workspace label. Find a page appears inside the native search field below identity and searches authorized workspace destinations only. Shared alignment edges join identity, search, navigation and account controls; compact controls keep usable hit areas. In the collapsed desktop sidebar, inset hairline dividers separate the identity/collapse control, search shortcut, native destination groups and account footer. Preserve group boundaries when labels are hidden; avoid a divider between every destination. Expanded and mobile navigation retain labeled native groups. Expanded and collapsed sidebar destinations share solid surface fills, subtle decorative hairline edges and readable muted icons, with the existing native icon stroke. Keep green for the seated current destination and gold for keyboard focus. Avoid heavy outlines or thicker icons as a substitute for contrast; the identifying icon carries contrast, while the edge only defines the material. The account trigger uses restrained offset elevation, a visible surface and focus/hover/pressed states in both themes and sidebar sizes. Native icon actions compact below the small breakpoint while keeping accessible names; text-only controls, Save/Submit progression and consequential confirmations retain labels. Native unsaved-change alerts protect Create/Edit forms and writable action modals across panels; existing domain-specific draft persistence remains authoritative. Role-specific content and authorization remain contextual. Applicant task navigation uses native breadcrumbs; draft progression uses Save and continue with secondary Save and exit and confirmed discard in the native action menu.

</details>

<a id="visual-foundation-and-implementation-authority"></a>

## Branding and output identity

<details>
<summary>School/system/person identity, artwork, accessibility and print rules</summary>

- Public and non-Applicant authentication surfaces identify the school as the service provider and TALA as secondary product attribution. Applicant account-entry pages identify the school and omit Powered by TALA. Their composition establishes distinct hierarchy for those roles and the current task. Supplied marks retain their full-color artwork; the TALA wordmark uses live text.
- Authenticated shells have one compact school-identity area. Student and Staff retain separate readable workspace context and quiet Powered by TALA attribution in the sidebar footer or shell footer where the sidebar is unavailable. Applicant identity instead includes Servitech Institute Asia and the signed-in person's name, without Applicant Workspace or Powered by TALA. Page headings identify the task or person. DESIGN.md and the matching brief govern placement; preserve artwork, recognizable hierarchy, contrast and accessible names wherever marks are shown.
- The favicon and install/app icon use the approved star-only artwork.
- Official and institutional printable outputs lead with the approved institution crest and institution name. They do not use the mascot; a restrained **Generated through TALA** text footer may identify the product.
- Choose screen mark sizes by legibility and school-first hierarchy in the actual surface. Reuse shared identity components across roles; render quiet attribution only on the surfaces where it remains permitted.
- Preserve complete source artwork and original colors without stretching or recoloring. A non-distorting crest-symbol crop may support compact screen identity. Official outputs retain complete approved artwork and the existing 18 mm print floor, full color when available and qualified monochrome-safe rendering. Accessible-name requirements remain unchanged.
- When adjacent visible text already identifies TALA or Servitech/SIA, the image is decorative and uses an empty text alternative. A standalone product or institution mark receives the matching accessible name. No interface uses the filename as alternative text.
- Failure pages use system fallbacks and do not depend on Vite or Livewire. Failure pages and printable outputs do not depend on remotely loaded fonts or a decorative background to communicate identity or status.

</details>

## Failure and recovery

<details>
<summary>HTTP/session failure, safe retry, authorized recovery and resilient fallback</summary>

- Adopt the selected experimental recovery-page composition independently of archived shell mechanics: one centered reading path, one plain-language H1, a short explanation, one context-safe primary recovery action, secondary actual HTTP code when applicable, school-first branding, and secondary Powered by TALA attribution. Preserve necessary support/account recovery access and readable institutional identity; specimen mark sizes do not override the existing artwork floors.
- Preserve actual response codes and source-specific recovery, including existing 403/404/419/429/5xx behavior where applicable. Session expiry remains a recovery state. Authorized workspace resolution, verification-link recovery, sign-out confirmation, CSRF/MFA/permission protections, and private evidence access are unchanged. Do not import simulated prototype actions.
- Unconfirmed mutation outcomes require checking the latest recorded state before resubmission. Reload/retry is permitted only when safe for the failed operation; it must not automatically replay a submission, decision, payment, or other mutation.
- Recovery actions remain immediately usable and content stationary. Follow DESIGN.md's finite background-motion contract and fallbacks; omit the visible recovery pause/stop button.
- Reuse the existing shared standalone error presentation; core explanation and safe recovery work without Vite, Livewire, JavaScript, remote fonts, or the prototype runtime. Retain usable native navigation for local page-level failure; use the minimal standalone fallback when the application shell cannot load.
- Verify the actual response, authorized recovery destination, mutation-safe retry, keyboard/focus, 200% zoom/reflow, light/dark contrast throughout the animation, its five-second completion, settled state after page return, reduced-motion/forced-colors fallbacks, hidden/offscreen behavior, and missing-asset/script resilience when an affected child adopts the pattern. #57 covers recovery states reached by its Applicant–Registrar journey and proportionate shared-consumer regressions; other recovery states belong to their owning slices.

</details>

<a id="clinic-1--identity-access-and-public-entry-ui-authority"></a>

## Identity, access and public entry

Visitors recognize the school and reach secure entry; account owners can enter, verify or recover.

<details>
<summary>Page, source, action, state, output and responsive/recovery contract</summary>

Use school-first public entry and one sign-in. Resolve authorized role contexts after authentication; preserve multi-role choice/switching, role permissions, Staff MFA and session safeguards. Decision provenance is retained in the [#48 evidence register](https://github.com/yosoykyle/SIA-TALA/issues/48#issuecomment-5918342932).

### Capability inventory and interaction contracts

| Surface | Owner and entry | Required information and priority | Actions and controls | States, permissions, and mobile |
|---|---|---|---|---|
| Public Gateway | Public; root route | School identity and **Apply** lead the masthead → independent source-backed intake/Announcements stack → factual Programs with supplied descriptions/images, task-useful filters and **Read more** → concise FAQ and Visit/contact/map; compact actual deadline/Asia/Manila clock and existing-account Sign in remain available | **Apply** when open; **Sign in**; native in-page anchors; bounded informational modals; external map fallback | One Bootstrap page. Closed/unavailable intake keeps Sign in and support; no public status request or emailed status access. Old status URLs recover neutrally with Sign in, no record disclosure or automatic logout. Program facts/details reuse approved sources; no invented catalog/CMS. Hero motion retains static/reduced-motion fallback and continuing-motion controls. |
| Applicant Registration | Public; Apply while entry is open | School identity and Create account purpose → email → password/passphrase → confirmation → privacy-notice acknowledgement/link → concise verification expectation | **Create account**; Sign in; official support | No name, LRN, application, program, document, or Student field. The privacy link opens `/?modal=privacy` in a new tab and auto-opens the Public Gateway's single Privacy modal source; Bootstrap is not duplicated in Filament. Closed entry fails safely. Duplicate email does not reveal account details. Single column on mobile. No pre-authentication workspace-choice link. |
| Unified Sign In | Public; one Sign in entry for existing accounts | School identity and Sign in purpose → verified email → password → optional Remember me → recovery/support | **Sign in**; Forgot password; official support | Invalid/unknown credentials remain generic. Account roles resolve the destination after authentication; retained role-specific links cannot grant access. Remember me is honored only for Applicant/Student-only accounts; Staff-capable accounts retain strict session/MFA policy. Only accounts with multiple legitimate contexts choose an authorized context after sign-in. No public role selector or Choose another workspace link. |
| Verification / Activation | Link or post-registration/invitation state | What must be verified/activated → target email (safely shown) → expiry or resend guidance → support | **Verify** or **Set password and activate**; Resend; Return to sign in | Expired, used, malformed, throttled, dispatch-failed, and already-complete states each provide one valid recovery action. Staff activation continues directly to mandatory MFA setup. |
| Password Recovery / Reset | Sign-in recovery link | Neutral instruction → email request; then new passphrase and confirmation on valid link | **Send recovery link** / **Reset password** | Request result never confirms whether an account exists. Reset applies the 15–64-character policy and ends other applicable sessions. |
| Staff MFA Setup / Challenge | After Staff activation or Staff-capable sign-in | Setup purpose → authenticator QR/manual key → TOTP confirmation → one-time recovery codes and storage acknowledgement; later challenge shows TOTP and recovery-code alternative | **Enable MFA** / **Verify**; Use recovery code; Regenerate codes from Account Security | Secrets and codes are never returned to tables or audit. No email bypass. Narrow layout remains one column. |
| Workspace Chooser | After valid sign-in when more than one context is authorized | Short explanation → compact authorized-context cards → account-security/sign-out links | **Open workspace** on each card | Single-role accounts bypass it. No unavailable roles, counts, previews, or analytics. Cards stack and preserve keyboard order. |
| Account Security | Account menu in any workspace | Verified sign-in email → email-change ownership/explanation → password → MFA and recovery codes when Staff-capable → read-only role contexts → minimal Staff access identity when applicable → active-session security guidance | Change email where allowed; Change password; Set up/verify/reset own MFA methods; Regenerate recovery codes; Switch workspace | Applicant/Student-only email change is self-service with new-address verification and old-address alert. Staff-capable email is administrator-controlled. Sensitive actions require current-password confirmation. |
| Users & Access | System Administrator; Staff navigation | Title/purpose → small readiness warning only when access administration is blocked → table/search/filter bar → active-filter indicators → result count → accounts | Header **Invite Staff**; contextual row actions: View, resend invitation/verification, send recovery link, change Staff access, disable/reactivate, reset Staff MFA | Columns: displayed name, verified email, authorized workspaces, derived state, invitation/verification, last successful sign-in, created. Search name/email/linked identifiers. Native role, state, verification, created-date, and last-sign-in-date filters. Secondary columns collapse on mobile. |
| Account Detail | Users & Access row View | Account state and next action → Staff profile → role contexts → linked Applicant/Student profiles → security facts → high-value audit history | One state-appropriate primary action; discoverable secondary actions | Read-only source-bound detail. No password, delete, archive, arbitrary role creation, permission editing, or academic/finance action. Internal disable reason is visible only to authorized administration, never to the disabled user. |
| Invite Staff | Users & Access header action | Email → existing-account match result when authorized → name parts → optional Staff identifier → fixed Staff roles → reason → authority → optional evidence reference → invitation/access-change summary | **Send invitation** for a new account or **Add Staff access** for an existing verified account; Cancel | No password field. A verified existing account is reused and is not sent through password activation again. Prevent duplicate account creation and final-admin hazards. Form becomes single column on mobile. |
| Change Staff Access / Disable / MFA Reset | Account detail focused actions | Current access/security state → exact proposed change → required reason and authority → optional evidence → irreversible/security effect summary | **Save access change**, **Disable account**, **Reactivate**, or **Reset MFA** with explicit confirmation | Only System Administrator; self-disable and final-admin removal rejected. MFA reset states that external identity verification must already be complete. |
| Public Content | System Administrator; Staff navigation | Tabs: Notices and FAQ → native tables → publication state and display order → concise preview; notices include their publication window and optional link, FAQs include question, answer, and optional topic | Add/edit/publish/unpublish/reorder; keyboard and single-pointer Move up/Move down alternative for any drag reorder | Programs and admission availability are projected from their owning records; map and bounded hero assets remain deployment/configuration inputs. Tables stack on mobile. |
| Failure / Inaccessible State | Any failed browser route or action | TALA/context identity → plain-language status → what happened without sensitive detail → one recovery step → support when relevant | Return to authorized workspace, sign in again, retry later, or contact support | Covers 403, 404, 419, 429, unexpected error, temporary unavailable, disabled account, and inaccessible record. Never exposes record existence, internal exceptions, or disable reason. |

### Composition rationale

| Starting composition | User outcome |
|---|---|
| Task-focused Public Gateway | Visitors can find application entry, sign-in and relevant notices/FAQ. |
| Focused Users & Access workbench plus Account Detail | Administrators can review access work by queue and record, with fixed roles and attributable actions. |
| Shared single-column authentication family | Registration, verification, recovery, activation and MFA share clear orientation, safe recovery, keyboard order and narrow-screen behavior. |

### Default ordering and page-specific states

**Deterministic ordering:** Users & Access sorts action-needed states (`InvitationPending`, `VerificationRequired`, and disabled accounts requiring authorized review) first, then remaining accounts by displayed name and stable account reference. Public Content groups currently published, scheduled, then unpublished/expired items and uses explicit notice or FAQ order within each group.

| Surface or shared family | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Public Gateway | No current notice/FAQ is stated plainly without hiding Apply/sign-in | Availability and content reserve labelled space and expose a non-blocking loading state | Content refresh failure preserves safe static entry/support; Apply fails closed if the admissions source is stale | Closed applications explain the date/support path; public service failure keeps institution identity and one recovery action |
| Authentication family | Not applicable; each state always has its required form or completion message | Primary action becomes busy once, retains entered non-secret values, and prevents duplicate submit | Expired/used/malformed link, validation error, throttling, or dispatch failure names one valid recovery; secrets are cleared as needed | Disabled or unauthorized context reveals no internal reason or role inventory and provides official support/chooser recovery |
| Workspace Chooser | Zero authorized contexts is an access-support state, not an empty dashboard | Contexts load as labelled placeholders without shifting focus order | A revoked context fails authorization, refreshes the list, and explains the safe next step | Only authorized contexts are rendered; direct routes return the shared inaccessible state |
| Account Security | Inapplicable sections, such as MFA for learner-only accounts, are omitted with ownership guidance where needed | Each focused action owns its own progress state | Concurrency or verification expiry preserves current live email/security state and requires refresh/retry | Cross-account access is forbidden; Staff-managed email explains the authorized owner |
| Users & Access | No accounts explains deployment/authorization owner without offering unsafe bootstrap; no filter matches offers **Clear filters** | Shared delayed loading feedback keeps existing results and column labels available; native controls disable during their pending operation | Stale state-changing action closes safely, refreshes the account, and shows no success | Unauthorized users receive shared 403 behavior; mail/integration outage shows a bounded readiness warning |
| Account Detail / focused actions | Missing optional profile/history sections say **No recorded evidence** rather than implying success | Infolist and action progress are separately labelled | State/version conflict shows current state and requires reconfirmation; failed mail never reverses access action | Direct unauthorized or nonexistent record routes do not distinguish existence |
| Public Content | First-record guidance appears only for authorized administration; filtered empty offers **Clear filters** | Tables and preview expose labelled loading state | Stale order/publish action refreshes current position/state; unsafe link validation stays field-specific | Public sees no draft; unauthorized administration receives shared inaccessible behavior |

The authentication-family contract covers registration, verification, activation, password recovery/reset and MFA through state-specific fields, actions and recovery.

### Cross-role consistency

The same account state, verified email, role assignment, effective time, and next action project across authentication, Account Security, and Users & Access. Role-specific pages never copy or rename those facts. Applicant/Student users see their own safe projection; System Administrator sees the administrative evidence permitted for access work.

### Implementation responsibility

- Public Gateway uses the existing isolated public Blade/Bootstrap surface.
- Authentication, verification, recovery, profile and MFA preserve Filament/Fortify behavior and the installed branded authentication layer.
- Chooser, Account Security, Users & Access and Public Content preserve their source records, permission boundaries and interaction contracts. DESIGN.md and their matching briefs govern the composition, framework research and supported customization.

### Accessibility acceptance details

- Public and authentication layouts use semantic landmarks, a visible-on-focus skip link, logical heading order, and a consistently placed official-support link.
- Email and password fields expose correct autocomplete purpose. Password inputs allow paste and password-manager use.
- Every control has a programmatic name. Icon-only actions retain visible tooltips and screen-reader labels.
- Focus is visible and not obscured by fixed content. Modal focus enters the dialog, remains contained while open, and returns to the invoking action.
- Validation marks the affected field, announces an error summary, and moves focus to the first error without erasing safe non-secret input.
- Dynamic status changes use a polite live region; urgent authentication errors use an assertive alert only when necessary.
- Controls meet the WCAG 2.2 24 × 24 CSS-pixel minimum and use 44 × 44 where practical for primary touch actions.
- Text and controls meet WCAG AA contrast, remain meaningful in high-contrast mode, and never rely on color or icons alone.
- Content remains usable at 200% zoom, preserves logical focus/reading order after responsive reflow, and respects reduced-motion preferences.
- Any drag-based ordering has Move up/Move down actions usable by keyboard and a single pointer.
- Before an idle session ends, TALA gives a clear warning and a **Continue session** action when the security policy permits. Continuing records renewed activity; it does not disable the accepted idle-timeout policy.

### System-wide failure and workspace identity

TALA uses one school-first institutional identity across its four entry surfaces: the public site, the Applicant panel showing `Servitech Institute Asia` plus the signed-in Applicant's name, `Servitech Institute Asia — Student Hub`, and `Servitech Institute Asia — Staff Workspace`. the Applicant panel and its account-entry pages omit `Applicant Workspace` and *Powered by TALA*. Student/Staff, public and error-page attribution and official-output *Generated through TALA* remain unchanged. The Staff Workspace label is the canonical name for `/admin`; technical panel IDs and route prefixes do not appear as user-facing product names.

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

### Working-state refinements

Public arrival uses a confident Servitech masthead, approved institutional artwork, a factual introduction and **Apply** as the primary action. Apply uses real open/closed intake facts; existing-account **Sign in** remains available. Programs browsing remains a native anchor, with supplied descriptions/images, useful filters over existing facts and **Read more** for approved bounded Program details. No invented institutional claims, duplicate marketing catalog or CMS is introduced.

Compact source-derived admission availability/deadline and effective PublicNotice Announcements share one clearly labelled masthead stack with manual Previous/Next controls and position. Their sources stay independent; notices cannot open or close intake. Announcement cards/anchor appear only when notices exist or their source is unavailable; navigation reveals the correct card. Programs follow the masthead; concise FAQ and Visit/contact complete discovery. Home, Programs, conditional Announcements, Visit and FAQ retain accurate native anchor cues and the Asia/Manila clock.

Preserve the navbar backdrop, top/bottom blur strips, footer background and approved Aqua-like action materials while refining foreground alignment, responsive identity, button labels and footer grouping. Approved school media remains optional; muted video has a poster and authored ambient motion has a static fallback. Continuing public motion retains visible pause/stop and reduced-motion safeguards; finite recovery motion keeps its separate contract. Native navigation and access remain usable without hero media or scripting. A configured nonblank address and titled, lazy map embed of at least 200 pixels retain the approved external fallback. Support, privacy and accessibility copy use factual current purposes and approved modal/accessibility behavior.

Public application-status requests and emailed status links are retired. Applicants use signed-in Home for current status and corrections. Old status URLs recover neutrally with Sign in, expose no record/private data and never automatically log out a current user. Remove redundant primary Application Details & History presentation while retaining reachable immutable record history and acknowledgments; this does not alter #61's Home composition or actions.

</details>

<a id="clinic-2--application-admission-decision-and-enrollment-readiness-ui-authority"></a>

## Application and admissions

Applicants submit/correct; Registrar reviews evidence, decides and confirms enrollment readiness.

<details>
<summary>Page, source, action, state, output and responsive/recovery contract</summary>

**Status:** Approved on 2026-08-06.

Clinic 2 uses the same authoritative application across Applicant and Registrar projections. It ends at derived enrollment readiness and never creates a Student identity, registration, placement, assessment, or enrollment. The detailed behavior and data contract live in [PRD 02](./prd_modules/02_application_admission_decision_enrollment_readiness.md).

### Applicant panel page inventory

The Application navigation entry resolves the most recently updated canonical Application owned by the signed-in Applicant, using record ID as the tie-breaker. Draft and ActionNeeded open that exact Application form; Submitted opens its editable form only before explicit review starts; reviewed Submitted, Admitted, NotAdmitted and Withdrawn open Applicant Home for that exact record. With no owned canonical Application, the entry opens the existing new-application form. Navigation does not select another person's record or grant editing permission; ownership, current version and PRD 02 review-start/lifecycle guards remain authoritative.

Coverage includes both visible and conditional surfaces: intake unavailable/open/closed, saved Draft, validation/save failure, scoped and overdue corrections, consent/LRN/transferee/minor fields, missing/replaced evidence, admission result, current clearance, withdrawal, earlier applications and acknowledgment. Review their reachable conditions and recovery paths rather than inferring completeness from the default page. Registration and fee/payment handoffs retain PRDs 04/06 authority; Applicant-facing guidance names the owning office without inventing admission charges.

Cycle overview pairs compact intake context with a distinct application-date group, keeps public closing separate from the new-correction boundary and explains active-correction continuity. Failed publication checks lead when blocked. Programs/paths, source-backed guidance and responsible setup context remain readable; history is secondary. Requirement sets use the native combined relation tab. Exact date/time, authorization and publication guards remain intact.

| Navigation item | Surface | Interaction outcome |
| --- | --- | --- |
| Home | Current situation, owner, relevant deadline and permitted next action; scope, stable reference, preliminary-review/clearance results and history remain accessible | Task-led composition with one current primary action and concise supporting information |
| Application | Draft, validate, submit, or correct only authorized application facts and preliminary evidence | Custom Filament Page with native five-step Wizard: Application Choice, Identity and Contact, Prior Education, Preliminary Evidence, Review and Submit |
| Requirements | Selected preliminary-copy review and one Registrar enrollment clearance | Contextual current-copy access, compact clearance result and state-permitted actions; distinguish prior evidence from the current review |
| Submission receipt and historical acknowledgment | Current submitted source, stable reference, received time, instructions and next action; immutable historical acknowledgment sources remain accessible | On-screen receipt in Application context; authorized historical print access retains its original safeguards |

`Requirements` is reached from Home or the current or historical Application. Account Security remains in the account menu and is not an admissions page.

The Public Gateway and Applicant panel read the derived state of a published `AdmissionCycle`. When no cycle is open, **Apply** becomes a clear applications-closed state while Applicant sign-in remains available. New applications and first submissions fail closed. Drafts become read-only until authorized extension or reopening; existing review, scoped correction, decisions, and Registrar clearance continue.

Home leads with a plain-language current situation, the permitted next step or waiting condition, responsible office and relevant deadline. A compact context line retains the application reference, cycle, program/path and submission facts. Preliminary review, admission and current Registrar clearance remain distinct concise source facts. One explanation states what happens next; historical applications, acknowledgments and technical evidence remain reachable after current work. The matching brief selects grouping and responsive composition. Empty, loading, error, inaccessible, and stale-action states identify what happened and the safe next action.

The five-step Wizard starts with **Let's get your application started.** and understandable Program/Student type choices; the eligible Cycle comes from authorized context. Applicant and Registrar admissions surfaces use **Student type**, **Freshman** and **Transferee**, preserving stored paths and submitted snapshots (F68/D1). Completed steps stay reachable and marked done after going back, including direct return to the step previously being worked on; uncompleted steps stay locked and advancement still validates and saves (F69). Correction scope and read-only inspection remain protected. Draft progression uses a primary native **Save and continue** action: validate the current step, persist Draft progress, then advance only after saving succeeds. Native **Application options** provides **Save and exit** for a partial draft. **Discard draft** is a directly visible secondary danger action on Applicant Home and the draft form, with confirmation describing removal of the unsubmitted record and temporary uploads; it is not hidden in an overflow menu. Native Home breadcrumbs provide task navigation without a separate return row. The Wizard exposes step-level validation, a server-side closing-time recheck, an accessible error summary with field associations, and a single-column mobile layout. It collects only PRD 02's approved application, identity/contact, prior-education, declaration, and preliminary-evidence fields. One guardian name/relationship/telephone group is required below 18; an adult may optionally provide one contact. Required/optional address components, optional sex/civil status and explicit LRN availability follow PRD 02. The optional identity group shows its record-comparison purpose and specific unchecked consent before enabling its editable values. Consent precedes transmission or Draft saving of either value; an unchecked group sends neither. Clearing the choice before submission clears current Draft values through the existing save action and preserves earlier submitted history. Declining keeps the ordinary application path available. The privacy-notice acknowledgement explains presentation of the notice and never labels itself blanket consent. Plain Personal Information, Contact, Address and Educational Background labels replace internal schema terms. No modality, preferred time, extensive family data or speculative reporting demographics are collected.

Requirements renders the immutable requirement-set version governing the displayed application version. A permitted Student type change previews the applicable new requirements under PRD 02 Section 8, distinguishing newly required, retained current and historical copies before commitment. Show the selected preliminary uploads in a native review table and one separate Registrar clearance summary/Action. Digital rows show purpose, current review/version, instruction/deadline and the permitted action. A persisted copy alone does not mean review has started: explain **Awaiting review** until the actual review facts support another instruction, without changing stored Application status labels. The clearance shows safe result, recorder/time and the next instruction. Paper documents use one source-backed path instruction, not a physical-processing table. Preliminary acceptance is never labelled simply **Accepted** or presented as admission/clearance. No extra paper scan is requested merely to record clearance.

External paper-document follow-up stays with the school. Clinic 4 consumes current admissions clearance; a changed source revalidates the affected case before finalization. An already official enrollment retains its facts and shows a Registrar-owned discrepancy with source/version and next action under PRDs 02/04/05. Historical per-document evidence stays attributable and read-only; no new paper-processing workflow is implied.

The same account may have one application per Admission Cycle, resolved from authorized entry context rather than an Applicant Cycle picker. An ambiguous context names Registrar configuration recovery without guessing the latest Cycle. Submitted edits remain available until explicit authorized review starts; each committed edit appends a fully validated atomic successor. Before review, a Student type change keeps the bound Cycle/Term and follows PRD 02 Section 8; show the changed requirements and preserve the prior committed version on failure. Read-only inspection does not start review. After review starts, only active correction scope reopens; correction/resubmission or withdrawal/reopening does not erase that boundary. Before the first admission decision, a Registrar-requested Student type correction shows the supported target type, dependent facts/copies and one complete scope for confirmation under PRD 02 Section 8. Applicant sees what must change, what is retained and why; unrelated fields stay locked. Missing target requirements name Registrar recovery. A failed resubmission preserves the prior committed version and active request; a successful successor needs review against its current source. Final decisions lock ordinary editing; prior snapshots, evidence, requests and review remain in authorized history. Draft partial-save recovery remains distinguishable from an atomic Submitted commit, and committed results remain distinct from later notification-delivery failures.

**Discard draft** removes an unsubmitted draft and its temporary uploads. An applicant may self-withdraw a submitted, action-needed, or admitted application until Clinic 4 starts registration; confirmation is required and reason is optional. Registrar-recorded offline withdrawal requires reason and authority. Authorized reopening preserves the same reference and history.

### Registrar Admissions workbench

One primary **Admissions** entry uses a native Filament Table with operational-count tabs: **Needs review**, **Waiting for applicant**, **Registrar clearance**, **Ready for enrollment**, and **Closed applications**. The last label presents the existing `history` queue of NotAdmitted and Withdrawn applications; it is neither the Student registry nor all applications. Each selected record separately exposes **Record history** for its retained versions, decisions, clearances and activity. These labels change no membership or retention rule.

| Contract | Exact presentation |
| --- | --- |
| Columns | Applicant/reference; program/cycle; plain-language state; responsible party/next action; preliminary readiness; Registrar-clearance result; nearest deadline; last activity |
| Search | Application reference, legal name, verified email, and exact authorized LRN search without displaying LRN in the list |
| Filters | Cycle, program, path, state, submitted date/time range, last-activity date/time range, and deadline/overdue state |
| Analytics | Small tab counts only; no chart dashboard, scoring, forecasting, or ranking |
| Actions | One state-appropriate primary record Action; discoverable secondary actions; no bulk Admit, clearance, or withdrawal |

Use Filament's filter panel and active-filter indicators, not custom column-header dropdowns.

Label each filter trigger for its owning table (for example, **Filter applications** or **Filter cycles**). Explain that filters narrow the selected authorized list; retain active indicators and a clear-filter recovery. Separate Search, Filters and available detail or column controls visibly. Cycle-list supporting metadata uses native row disclosure; column controls appear where the native table supports them. Admissions uses native contextual navigation between **Application queue** and **Admission cycles**; omit a duplicate header link when the same authorized destination is already exposed there.

Applicant review places current document previews, identity warnings, decision reasons and matching clearance in one connected task. Common reason choices and Other distinguish private basis from Applicant-safe instruction. Admit — papers confirmed records matching admission/clearance atomically after confirmed external checks; Admit — papers pending leaves one Mark papers complete follow-up. State, responsible office, next action, stale-source recovery and contextual queue return remain visible; source/history detail is secondary.

Lead the record with a compact current task, responsible party and permitted next action so current evidence remains readily reachable. Avoid nested bordered surfaces that repeat context. The queue leads with the Applicant's name; long email and reference values remain discoverable and copyable without fracturing the primary reading path. Explain receipt of the submitted form, preliminary-copy review, admission result, Registrar clearance and derived readiness as distinct facts without repeating the same outcome as competing prominent badges. An admitted Applicant is not yet officially enrolled. Record history uses compact attributable rows and concise empty states rather than stacks of empty accordions; relevant actor, time, source version and superseding relationships remain accessible. Current and historical acknowledgment return controls retain the authorized selected queue for Registrar review and the exact application context for Applicant Home; unknown return contexts never become arbitrary destinations.

The admission-decision form distinguishes the private internal reason from the explanation sent to the Applicant. An ordinary first decision records the Registrar and time as its authority and permits an empty separate approval reference. Replacement decisions and explicitly identified exceptional approvals require a separate approval reference with a plain-language explanation of what to enter. Exceptional approval never bypasses admission safeguards or introduces a universal co-approver.

Ordinary assisted entry searches active, verified Applicants by name or email and shows only accounts with an available unsubmitted Draft or a new open intake. The same bounded search accepts the long **APP-… application reference** issued with a submission: a matching submitted or terminal application opens its exact authorized Registrar record without starting review or allowing assisted edits. A referenced Draft must still pass the exact-source assistance eligibility check. The selected source is checked again before navigation and saving. Submitted applications and Applicant-owned corrections cannot receive assisted edits; prior submitted or terminal history does not prohibit a separate unused intake. Closed or cancelled Drafts stay inspectable and discardable through their saved context but do not appear as preparation choices. The reason for help precedes assisted editing. Keep any existing approval or office-evidence references in optional supporting details with examples of record identifiers, never as routine prerequisites. Applicant and Registrar assistance reuse the same five-step Draft view, save-status feedback, **Save and continue** progression, **Application options → Save and exit**, save-before-leaving recovery, and filled danger **Discard draft** action with confirmation. Remove the duplicate assisted save/exit/cancel footer. The Registrar's final review action saves the Draft; only the Applicant makes declarations and submits it. Preserve the exact application when saving and resuming, and return the Registrar to the authorized Admissions context. These shared form refinements follow the owner's October 14 #64 review and leave #61's Home composition unchanged. Assisted breadcrumbs identify Admissions and draft preparation. Legacy Staff entry resolves the selected authorized role destination rather than presenting an unlisted generic Dashboard; this creates no new Staff dashboard or scheduling surface.

Contextual Registrar pages provide the Admission Cycle list/readiness, draft cycle form, immutable requirement-set review, and authorized publish, public-window extend/close/reopen, correction-boundary extend, cancel, or replacement-version actions. The list identifies the target term, current public availability and useful boundary before optional setup metadata; its search cue states whether cycle name and code are supported. The overview leads with current intake and groups public opening/closing as one window, distinct from the deadline for issuing new correction requests. Show the school timezone, accepting programs/paths and accountable Registrar without flattening every fact into equal prominence. An active correction remains actionable when overdue or after public closing. Native action hierarchy follows the available task and consequence; a healthy open cycle need not manufacture a primary action. These pages are reached from Admissions and do not form a generic Settings area. Cancellation previews stopped new starts/first submissions, retained Draft inspect/discard and continuing existing-case resolution under PRD 02; it implies no automatic adverse decision or clearance reversal.

### Composition rationale

| Starting composition | User outcome |
|---|---|
| Five-step Application Wizard | Applicants can save partial drafts, follow familiar steps on mobile and explicitly review before submitting. |
| Applicant Home guided status page | Applicants can find their current application, next action, readiness summaries and history. |
| Single Admissions queue with operational tabs | Registrar can review applications and open their cycle/requirement setup in context. |

### Default ordering and page-specific states

Admissions queues sort overdue/action-needed items first, then nearest deadline, then oldest last activity and stable reference. Application history sorts newest authoritative event first. Requirements sort by due stage and the published requirement order. Admission Cycles group currently effective/published first, then sort by nearest opening or closing date and stable code.

| Surface | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Applicant Home | No application offers **Start application** only when a cycle is open; historical-only state explains the next valid entry | Status and readiness sections retain labelled structure | Changed decision/correction/readiness refreshes before action and preserves history | Other applicants' references and unsupported paths are inaccessible without disclosure |
| Application Wizard | No Draft is created until start is valid; optional step sections explain applicability | Save/step/submit progress is explicit and duplicate submit disabled | Closing-time race, stale snapshot, or field/evidence validation retains safe Draft data and focuses the error summary | Closed cycle makes Draft read-only; unauthorized record route is generic inaccessible |
| Requirements | No applicable rows states the retained requirement version and owner; filtered empty offers **Clear filters** | Groups and row labels remain available | Replaced evidence or result refreshes the row before action; upload failure preserves prior version | Private files require authorized download and never expose storage paths |
| Receipt / historical acknowledgment | Before submission, return to Application; historical artifacts are clearly labelled | Source/reference/time remain visible without a false receipt or document | Failed submission preserves safe input; stale historical generation creates no artifact | Only owning Applicant/authorized Registrar see history; receipt claims neither admission nor enrollment |
| Admissions queue | First-use and no-filter-match states are distinct; the latter clears filters | Table/tab counts report loading separately | Stale row action refreshes record and rejects out-of-order decision | Unauthorized roles have no navigation or record disclosure |
| Applicant Record | Optional evidence/credential sections state **Not yet applicable** | Ordered Sections load without changing action position | Stale correction/decision/credential action shows current authoritative state | Direct unauthorized record route is indistinguishable from unavailable record |
| Admission Cycle/readiness | No cycle offers bounded **New cycle**; no requirement version remains a blocker, never false success | Failed-first checks retain source/owner placeholders | Publish/date/cancel conflict requires refresh and reconfirmation | Storage/mail outage is attributed to System Administration; storage unavailability blocks publication |

Focused admissions actions reuse their owning record/workbench; the inventory and state contracts establish coverage without duplicate frames.

Applicant sees only their safe projection. Registrar owns personal review and decisions. Academic Head has aggregate counts only when authorized and no personal application access by default. Accounting, Faculty, and System Administrator receive no admissions-decision authority. After official enrollment, Applicant disappears from the normal workspace chooser while the application remains retained as Registrar evidence.

On mobile, the Wizard remains single-column, table rows collapse secondary fields into labelled detail, filters use the native panel, and row actions remain discoverable. All states require visible focus, labelled controls, screen-reader status text, and interaction that does not rely on color or pointer use.

### Working-state refinements

Cycle overview pairs compact intake context with explicit application dates, separating public closing from the boundary for new correction requests. Blocked publication findings lead; programs/paths, guidance and accountable setup facts follow, with history secondary and native requirement-set relation navigation. Applicant surfaces cover conditional correction, consent, identity, evidence and recovery states as well as the default page.

</details>

<a id="clinic-3--academic-setup-offerings-and-published-timetable-ui-authority"></a>

## Academic preparation and Term Planning

Registrar prepares exact-Term sources, reviews a candidate and publishes; Head inspects read-only evidence; Faculty supplies availability and uses its own official schedule.

<details>
<summary>Page, source, action, state, output and responsive/recovery contract</summary>

**Status:** Approved on 2026-08-06.

Clinic 3 presents one connected journey from recorded academic authority to the official published timetable. The detailed behavior and conceptual contracts live in [PRD 03](./prd_modules/03_academic_setup_offerings_published_timetable.md). The legacy Academic Setup, Term Offerings, and CP-SAT UI descriptions below are reuse evidence only.

### Navigation and page inventory

Registrar receives two primary entries rather than a resource-by-resource setup maze:

| Navigation item | Purpose | Interaction outcome |
| --- | --- | --- |
| Catalog & Curricula | Record program authority, maintain Course Revisions, build the grouped Curriculum Version including authority-backed external-competency requirements, resolve import findings, and activate the externally approved version | Connected Filament workbench using Tables, Sections, Forms, Infolists, Actions, and one bounded CSV preview/import |
| Term Planning | Prepare the selected term, cohorts/classes, resources, candidate, publication, and revision under their source dependencies | One exact-Term native cluster with Overview, Calendar, Sections & classes, Faculty & rooms and Timetable, plus contextual source-owned actions |

Faculty receives **My Availability** and **My Schedule**. Academic Head receives read-only entry to Catalog & Curricula and Term Planning. System Administrator receives only locally evidenced solver status through Clinic 6 System Health. Student receives no Clinic 3 navigation; Clinic 4 projects the assigned official schedule after enrollment.

### Catalog & Curricula workbench

The workbench provides these task groups, with current context, source blockers and the permitted next action readily visible:

1. Program identity, authority, effective dates, status, and approved curriculum source.
2. Course catalog and current immutable revisions.
3. One Curriculum Version sheet grouped by curriculum year and term.
4. Draft CSV preview with errors, warnings, and source comparison.
5. Activation readiness, authority evidence, and one state-appropriate primary action.

The grouped sheet shows course code/title, units, prerequisites/corequisites, scheduling treatment, weekly meeting pattern, modes, room needs, source, and readiness. A bounded external-competency section shows only qualification/level, related curriculum position, `TrackedOnly` or explicitly authorized `CompletionRequired` treatment, authority, and effective version. Draft rows may be edited inline or through a focused form. Active and historically used rows are read-only. An externally arranged practicum is visibly labelled **Externally arranged — no recurring master-timetable meeting**. Evaluation-sheet labels alone cannot activate an external requirement or completion effect.

Filters use the native Filament filter panel with active indicators: program, curriculum intake/version, curriculum year, term placement, course state, scheduling treatment, and readiness. Search covers program, course code, and course title. Blocking import findings link to the exact Draft source row; import never activates or overwrites authority records.

The CSV action is `FocusedTALACustom`: a small preview/commit coordinator built from native private File Upload, Form, Table, Action, policy, notification, and background-progress primitives. It uses the fixed template `tala-curriculum-import-template-v1.csv` with these read-only ordered headers: `template_version`, `program_code`, `curriculum_version`, `curriculum_name`, `curriculum_year`, `term_placement`, `course_code`, `course_title`, `units`, `prerequisite_course_codes`, `corequisite_course_codes`, `equivalent_course_codes`, `scheduling_treatment`, and `source_reference`. Only the three requisite/equivalency cells may be blank; `template_version` is `1`; codes, titles, units, and positive whole-number curriculum year use the shared validation; and `|` separates multiple Course codes that must resolve within the preview or current authority. `term_placement` is `First`, `Second`, or `Special`; `scheduling_treatment` is `Recurring` or `ExternallyArranged`.

Before upload, the action shows **Download TALA curriculum template**, UTF-8/comma guidance, 5 MiB/5,000-row limits, and that import creates Drafts only. Missing, duplicate, reordered, unknown, or unsupported headers cannot be remapped. Preview records no academic authority and shows file summary/checksum, exact source row, normalized interpretation, current-source comparison, proposed Draft effect, errors, warnings, and filterable finding count. A finding links to its row/column and states how to correct the original template. Meeting components, modes, and room needs are completed later in the Draft workbench; the CSV offers no mini-language.

**Create Draft records** appears only after zero blockers and acknowledged warnings. Formula-leading source text is a blocker. Its confirmation names the Program/Curriculum scope, proposed counts, source/checksum, all-or-nothing Draft effect, and that activation remains separate. Commit revalidates authorization and every source version. Failure states that no Draft was created and retains the preview for retry; a successful file/context cannot create duplicate Drafts. **Download findings** prefixes formula-leading generated text with one apostrophe, but that file cannot be re-imported. Preview always escapes and renders source text rather than markup. Upload, preview, findings download, and commit remain Registrar-only and never expose another Program's data.

### Term Planning workspace

The selected-term header always shows term identity, state, current readiness, governing authority, current published version, and exactly one state-appropriate primary action. Context is selected before actions; no global action silently operates on an implicit term. The selector may show multiple concurrently active First, Second, or Special Terms and labels each by academic year, term type/display label, and state. Changing context never carries a Draft edit, filter with a different meaning, selected record, or pending action into another Term.

#### Overview

Group active-curriculum Program/year rows for the selected Term type with actual linked student sections, classes and weekly meetings. Source-supported shared classes appear for every served group while whole-Term totals count each class once. Keep additional and unresolved associations visible. Missing setup is advisory; counts alone do not establish readiness or that a curriculum year is running. Generation reviews the actual recorded whole-Term scope and preserves confirmation, linked-cohort, demand and resource guards. No compulsory cohort year or uniform-section rule is introduced.

#### Calendar

Show official dates, typed operational windows, weekly teaching grid, recurring breaks, dated exceptions, authority evidence, and failed-first readiness. Distinguish core-calendar activation checks from each operational window. A missing window shows **Dates not configured**, its consuming action, Registrar ownership, and the setup remedy; it blocks that action while independent planning remains available. The informational Examination Period shows inclusive Asia/Manila dates, approved display label, authority, package version, owner, and as-of time. The neutral `Enrollment` window displays its approved dates; Clinic 4 displays and applies its bounded learner applicability. A successful scoped check collapses to **All required checks passed**. Date-less grid times are institutional Asia/Manila wall-clock values; timestamps retain their actual date/time meaning.

Registrar, Academic Head, Faculty, and Student projections use the same source. Missing or stale evidence shows **Examination period unavailable — contact Registrar or Faculty**. Exact class arrangements remain in the Faculty-owned teaching channel; no class-level date/time, scheduling action, email, generic event, or financial examination hold appears.

#### Sections & classes

| Record | Columns |
| --- | --- |
| Term Cohort | Code, program, curriculum/version period, forecast count, confirmed count, curriculum coverage, and state; the cohort is not a Regular/Irregular Student classification |
| Class Offering | Reference, course, linked cohorts, `Regular`/`Additional` source, expected count, capacity, meeting pattern, modes, readiness, state, and last change; `Regular` is an offering-source label only |

Native filters are program, cohort, course, source, shared/single cohort, state, readiness and capacity condition. A whole-Term bulk preparation action derives a preview from active curricula and attributable cohort/demand counts, showing missing or ambiguous associations rather than inventing running years or counts. Registrar confirms the valid set and handles actual splits, shares, additions and cancellations; CP-SAT does not create or merge classes. Linked cohorts stay explicit. Cancellation previews publication and enrollment impact.

#### Faculty & rooms

Faculty and Room working lists share native search, sorting and pagination, preserving Term and subview. One coherent Faculty-load view presents eligibility, Term capacity/preparation limit, assigned demand, availability response and relevant blockers, retaining each fact's owner/source and hard constraints. The request area shows its reply deadline and awaiting responses; Request availability and Set/Change deadline have distinct effects. A deadline is generation-readiness evidence, not a core Term-activation prerequisite. Teaching-load defaults and per-Faculty precedence remain inspectable without three disconnected preparation forms.

Room rows show type, seats, equipment and availability. Quick Room inspection opens a compact read-only native dialog; authorized management uses one form/save for Room properties and equipment. Human equipment labels retain exact feature keys and existing identities; no new feature catalog, quantity meaning or deletion capability is inferred. Weekly unavailable times explains recurring meeting exclusions; dated no-class exceptions remain in Calendar. Reached source pages use one compact Term context, meaningful task/record headings and originating-task returns. Term details preserve current facts and history; activated identity appears read-only rather than advertising an ineffective general Edit task. Draft identity and the ongoing Faculty-load setting retain their existing server guards. Contextual corrections/commitments keep required reason and authority. No preferred-time field, availability approval queue, Online room, travel matrix, maintenance workflow or booking marketplace is added.

#### Timetable — draft generation and review

The result leads with status, plain-language meaning, responsible owner, and one next action.

- `Optimal` and `Feasible` show the fixed quality measures, filterable weekly view, accessible meeting table, warnings, and candidate actions.
- `Infeasible`, `Unknown`, `ModelInvalid`, and `TechnicalFailure` show distinct factual outcomes and owned recovery links. `Unknown` says no candidate or infeasibility proof was obtained and offers the permitted retry; it invents no academic conflict. `Infeasible` presents evidenced constraint diagnostics, `ModelInvalid` names model validation/recovery, and `TechnicalFailure` routes operational recovery. Safe source details remain available without replacing the current published timetable.
- Failed groups follow **failure → affected record → factual basis → owner → corrective action**.
- Supporting solver statistics, assumptions, identifiers and constraint evidence remain accessible on demand; current decision measures, warnings and failures stay visible.

Filters are program, cohort, course, Faculty, room, day, mode, and changed/affected rows. **Adjust candidate meeting** offers constrained day/time/Faculty/room choices and first tests the request with all other meetings fixed. A valid local result previews one changed meeting; an invalid result saves nothing and shows its conflicts plus the explicit **Find valid repair** action. Repair fixes the request, minimizes changed non-requested meetings before ordinary quality, and previews every changed meeting and quality difference. Registrar accepts or rejects the entire immutable successor. Every path revalidates the complete candidate and never waives a hard rule or moves another meeting silently. A quality-lowering successor requires a publication reason. The label **Manual override** is not used.

The weekly timetable is the justified custom component in the scheduling workbench. It must have an equivalent native, filterable, screen-reader-readable meeting table. It is not drag-and-drop.

#### Timetable — official versions and revisions

Show the current immutable version, recorded authority, publication time, filtered official timetable, print/save-as-PDF, revision impact, and superseded history. A targeted revision begins from an explicit source change and impact preview; no published meeting is edited in place.

The official browser output is `FocusedTALACustom` and A4 landscape. It uses exactly one Published Timetable Version labelled `Published` or `Superseded` and shows institution identity, exact Academic Year and First/Second/Special Term, Term reference, authority, publication/generation times, version, role/filter context, and ordered day/time, Course, Class, authorized Faculty, mode, room/Online location, and revision marker. Continuation pages repeat Term/version and table headings. It is monochrome-safe, prints no navigation or controls, and uses restrained **Generated through TALA** footer text. A superseded version is visibly historical; stale/unavailable source or rendering failure creates no artifact and leaves the owning page available with retry/support guidance.

### Composition rationale

| Starting composition | User outcome |
|---|---|
| Catalog & Curricula plus Term Planning | Registrar can follow the operating order with setup sources in context. |
| Business-result-first Timetable review | Registrar can understand the result, owner, source and corrective action before reviewing solver statistics. |
| Filterable weekly view plus accessible table; bounded correction action | Registrar can compare meetings and apply corrections using valid choices with complete candidate revalidation. |

### Default ordering and page-specific states

Curricula order by curriculum year, term placement, course code, then stable revision reference. Classes order by program, cohort, course, then class reference. Teaching Resources show blockers first, then Faculty or room name. Candidate and published meetings order by teaching day, start time, course, and class reference. Published history sorts newest version first.

| Page/tab | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Catalog & Curricula | No program/curriculum provides source-owner guidance; no filter matches offers **Clear filters** | Group/sheet structure and import progress are labelled | Stale Draft/import/activation refreshes source; active versions stay read-only | Unauthorized edit actions are absent and server-rejected |
| Calendar | Missing calendar facts render failed readiness, never an empty success | Checks retain source/owner placeholders | Stale package/date action refreshes and requires reconfirmation; exam dates are never inferred | Inactive/missing exact Term prevents only that Term's downstream actions with source link; other active Terms remain usable; unavailable Examination Period names Registrar/Faculty recovery |
| Sections & classes | No recorded demand and no filter match are distinct; expected curriculum gaps remain advisory, while actual confirmed-class/cohort/demand readiness retains its existing guards | Forecast/source state is labelled | Source change refreshes Draft rows; published-impact action requires review | Other-role and implicit-term actions are inaccessible |
| Faculty & rooms / My Availability | No declaration/resource shows responsible owner and due action | Declaration and blocker rows keep labels | Late/stale declaration records a new correction; failure preserves last authority | Faculty sees only own declaration/schedule; System Administrator cannot change academic facts |
| Timetable — draft | No run explains readiness/start action; no candidate is distinct from an empty meeting set | One active run shows safe progress without fake completion | Stale source invalidates generation/publication action and links to source | Infeasible, Unknown, ModelInvalid, and TechnicalFailure each show distinct meaning/owner/recovery; no candidate can publish |
| Timetable — official / My Schedule | No published version states not official yet; filtered empty offers **Clear filters** | Version and table/view/loading and A4 generation states are labelled | Stale sign-off/revision/impact refreshes; failed publication/output creates no partial version/artifact; superseded print is visibly historical | Candidate data is inaccessible to Faculty/Students; Students receive only Clinic 4 official placement projection |

Term Planning groups setup, generation/review and publication in one source-aware native five-page cluster. Draft and official versions share the Timetable destination; view filters never narrow generation scope. Faculty-request deadlines belong to Faculty & rooms; calendar packages retain legacy deadline metadata without a duplicate editor. Grading-teacher assignment belongs to Grades & Completion. The information and state contracts define required role coverage; Impeccable determines task-fit composition.

### Cross-role, responsive, and failure behavior

- Registrar owns editable setup, candidate correction, publication, and revision.
- Academic Head sees read-only calendar and Examination Period, curriculum including external requirements, readiness, candidate evidence, and published timetable oversight.
- Faculty declares availability, reads assigned official meetings and the informational Examination Period, and sees affected revision history.
- Student sees only the Clinic 4-owned placed and officially enrolled schedule plus the informational Examination Period on Student Home/Academics.
- System Administrator sees solver-related System Health evidence without academic actions.
- Applicant, Accounting, and Public receive no Clinic 3 master-timetable access.

On mobile, grouped curriculum and resource rows stack with labels, the weekly view becomes a day-by-day list, filters remain in the native panel, and secondary actions remain discoverable. Result status includes text and screen-reader meaning and never depends on color. Empty, loading, inaccessible, stale-source, technical-failure, and no-candidate states all name what happened and the safe next action.

Concurrent-Term acceptance switches between an active prior/Special Term and the next active Term on desktop and mobile, announces the selected Term to screen readers, preserves an unambiguous heading and breadcrumb, and proves that each timetable, window, deadline, failure, print view, and action remains bound to the selected exact Term.

### Implementation and communication responsibilities

Composition follows DESIGN.md and the matching brief, using framework research and supported customization. Preserve source-owned setup, understandable candidate comparison, current publication evidence and the accessible weekly-timetable equivalent. The CSV preview/import retains its bounded source contract.

Email is limited to the Faculty availability action request, first publication to assigned Faculty, and one shared published-revision event. Clinic 3 owns the revision trigger and affected Faculty; Clinic 4 supplies affected officially enrolled Students and their updated schedule/COR context. Examination Period visibility and external-competency requirement changes create no email. Routine saves, readiness checks, generation, failure, and candidate correction use in-workspace feedback only.

### Term Planning: working-state contract

The following consolidates the shared Term/Faculty refinements within this journey. PRD 03/05 retain behavior; shared appearance follows DESIGN.md.

#### Exact-Term context and working records

- Registrar and read-only Academic Head retain Overview, Calendar, Sections & classes, Faculty & rooms and Timetable, including reached records, history and outputs. A native readable Term selector precedes task navigation and actual records. Term identity appears once; breadcrumb/sidebar/URL ancestry and Back preserve the originating exact Term, task, local view and criteria. Academic Head receives no editing, preparation or institutional-approval power.
- Overview groups active-curriculum Program/year coverage with recorded student sections, classes and weekly meetings. Shared classes count once globally and remain discoverable from every recorded group. Count Programs with classes while retaining advisory empty/unprepared rows. Missing curriculum setup is not proof a year is running/cancelled and is not a requirement that every year must run. Additional, mixed-year and unassociated demand stays explicit; source counts/deadlines never prove complete readiness. Do not infer learner type/year from a group or create absent classes through generation.
- Student section means TermCohort; class means the course offering; weekly meeting requirement means SchedulingDemand; draft timetable means candidate; official timetable means published version. Routine headings, links, breadcrumbs and dialogs use those human terms; technical detail retains source record names. Grades & Completion owns grading-teacher assignment, separately from timetable assignment.
- Calendar leads with teaching dates/hours/exceptions, separately from operational dates. Source/activation guards stay intact; Term details retain source history. Activated identity stays protected. Ongoing Faculty teaching-load default editing belongs to Faculty & rooms and retains existing per-Faculty override precedence. Weekly unavailable times are recurring exclusions; dated exceptions remain Calendar.
- Classes and Student sections are distinct local views; Faculty and Rooms are distinct resource views. Source fields lead with human course/Faculty/recorder/type/equipment names and keep exact IDs/codes/keys/eligibility. Equipment implies no unrecorded quantity. Searchable choices preserve available options, source version and selection guards.
- Registrar and Head share bounded Class details inspection. Linked-class resolution includes all exact-Term classes independently of the Classes Program/year display filter; cross-Term classes are refused. Course, linked student sections, learner counts, delivery groups and meeting requirements lead; history remains reachable. Registrar's permission-bound Manage class opens sustained editing. Room inspection is likewise shared and read-only; authorized Registrar management edits capacity/type/equipment in one form/save.
- Own lifecycle/source/history work uses pages, contextual creation/preparation uses native slide-overs and atomic decisions/focused meeting inspection use native centered dialogs. A supported recording wizard divides a command, not the parallel planning workflow. Closing/cancelling restores the visible trigger and context. Conditional record views keep the native dialog host when a table is absent.

#### Timetable and generation

- Draft and official versions are states of one Timetable destination. Version/authority, view, validated native criteria and eligible actions precede the master/focused/list records. Head separates selected-Term findings from institution-wide curriculum evidence; source detail does not become an always-open generic evidence rail. Candidate/official reading structures are comparable; missing/unmeasured facts are unavailable, never zero.
- The master overview uses captured student-group identities and teaching days; focused student-section/Faculty/room views show weekly relationships with an equivalent native meeting list/agenda. Each meeting occupies its real duration once. Preserve captured starts, durations, hours, overlaps, breaks, dimensions and payloads; current labels supplement rather than mutate captured membership.
- Course titles lead choices, rows, tiles and print; codes support identity. Meeting details leads with course/time/location and is an inspection command, without checkbox or pressed-selection semantics. A focusable named time canvas exposes the full recorded range with keyboard scroll and sticky day/time references. Display uses 12-hour Asia/Manila time while source times/minute geometry stay unchanged. No auto-scroll or hidden-hour policy is added. Timetable labels retain the documented 14px step and compact tile corners 6px.
- Generation uses a native wider decision dialog with a compact accessible Program/year/Classes/Meetings/Status table. Its named bounded horizontal-scroll region supports keyboard access to offscreen columns. Retain every advisory empty row and count shared classes once. Show whole-Term recorded scope and the consequence of creating a draft before confirmation; display filters never narrow generation. Review and publication remain separate.
- Working facts lead the viewport: scope/state, task toolbar and records; supporting provenance/quality/history is disclosed. Avoid repeated identity/introduction/status strips or universal attention bands. Mobile wraps usable toolbars and retains context, criteria, source/output access, complete geometry and the equivalent agenda. All overlays stay opaque. Synthetic provenance is a short factual annotation, not review instructions.
- Official schedule screen/print retains school issuer, approved artwork/attribution, immutable version, source/Registrar/as-of and full/subset identity. Filters change only the view. Records, validation, publication, source history and institutional ownership remain under PRD 03; these refinements imply no new solver, load, Special Term, transfer-credit or all-year policy.

#### Faculty Schedule & Availability

- Faculty enters Schedule & Availability with compact centered My Schedule/My Availability sibling navigation and role-scoped search aliases. Grade Rosters remains separate. One readable Term selector groups current response/official status and source; informational Examination Period dates/source stay secondary on Schedule without a duplicate Availability query.
- My Schedule shows actor-owned current official meetings, Week/Meeting list, protected inspection and own revision history. A selected Term with no official/pending meetings links directly to My Availability without changing Term access. Current criteria remain visible; native Filters have explicit width, at most two columns, a full-width Course and one mobile column. Owned Course/Class/Room choices are searchable; Reset is neutral. Preserve query IDs, validated Day/Modality and other existing criteria. Search matches the meeting-linked course specification title plus course/class/room codes. Component remains reachable via column selection and full detail.
- Print this view uses the same validated criteria and clearly states filtered scope. Print all official meetings uses the complete own selected-version schedule. Neither accepts arbitrary meeting/Faculty IDs. Both preserve actor ownership, current official/version eligibility, exact criteria, identity/as-of/revision evidence and output logging. Faculty rows may omit repeated Term/Faculty header identity. Published affected history stays reachable.
- My Availability leads with Your availability helps the Registrar plan teaching assignments, current request/deadline, actual answer and the permitted next action, then one own Availability history. Unrequested initial response names Registrar/contact; requested unanswered response makes Submit clear; Response recorded vN exposes Update my availability. The update dialog uses Update availability and Reason for this update. Registrar qualifications/load capacity requires no unrelated Faculty disclosure; an own active legacy-unavailable-time path appears only when it exists, with a Term-preserving return.
- Registrar's Faculty & rooms request sends one action-required email with a direct My Availability link. Creating a Term alone does not send it. Request availability leads; Set/Change reply deadline is subordinate and changes only due time. Email identifies school, Term/deadline, the two form questions, initial Submit or existing Update and the fact that no assignment is made. No in-app feed or request/declaration association is invented.
- Ask Can you teach this Term? with explicit Yes/No; for Yes, ask Any weekly times you cannot teach? with explicit Yes/No. The initial weekly answer is required. Weekly Yes seeds one empty Day/From/To row without inventing facts and requires at least one valid row. Weekly No or whole-Term No submits empty intervals. One Asia/Manila cue, leading Add another unavailable time, native Delete and responsive widths keep it compact. Toggling preserves interval drafts until Cancel where supported.
- Corrections prefill the latest own exact-Term values/intervals, require a fresh explanation limited to 2000 characters and retain mounted-Term guards. The weekly question is UI-only; no service/persistence-policy change or soft preference is added. Current constraints remain separate from official meetings and never move meetings automatically.
- Availability history places View details in the native final right-hand column. Narrow screens keep native horizontal table scroll; its grid item can shrink with min-inline-size: 0. Detail shows complete immutable exact-Term answer, version/date, weekly periods and full reason or Initial response. Re-resolve actor/Term at mount/content; reject colleague, tampered, cross-Term and stale-context details. Current request evidence remains separate from response history.
- Same-intent controls use DESIGN.md's shared tactile material, including opaque green meeting inspection and the modal-X exception. Native names, Escape, focus/cancellation and loading/disabled semantics remain. Colors/decor never imply official/valid state. Mobile keeps source/examination/criteria and usable search/filter/output controls.

#### School-issued communications

Use DESIGN.md's common native Laravel Markdown email theme/header for Mailables, account notifications and framework/Filament auth mail. School identity leads and TALA is secondary, including Applicant email; the Applicant screen exception stays unchanged. Preserve recipient, trigger, transport, authentication, business/error/success meaning, responsive/inlined layout, plain-text alternative and System Health self-test subject/message/trigger. Synthetic local rendering proves neither transport nor email-client fidelity.


</details>

<a id="clinic-4--current-term-registration-official-enrollment-student-activation-adjustment-and-course-drop-ui-authority"></a>

## Registration and official enrollment

Learners confirm proposals and see source-labelled blockers; authorized offices complete enrollment and COR.

<details>
<summary>Page, source, action, state, output and responsive/recovery contract</summary>

**Status:** Approved on 2026-08-06. This section translates PRD 04 into exact role surfaces. It is not an implementation task plan.

### Navigation and page inventory

| Role | Primary surface | Contextual destinations |
|---|---|---|
| Ready Applicant / Student | **Enrollment** guided status page | Full curriculum evaluation in Academics, Finance, current/historical COR |
| Registrar | **Students & Enrollment** workbench | Student Record, published Class Offering, curriculum evaluation, account clearance evidence |
| Accounting | **Student Accounts** focused account view | Same Enrollment/account records, payment evidence and contextual restriction recovery; Accounting gains no placement/finalization authority |
| Faculty | Official roster projection only | Published schedule and affected revision history |
| Academic Head | Read-only enrollment oversight | Recorded institutional authority evidence |
| System Administrator | Locally evidenced System Health for integrations, queue, and email only | Technical evidence without academic or financial actions |

There is no separate Study Plan navigation item, generic gate screen, learner class marketplace, or peer resource for each checkpoint.

### Learner Enrollment guided status page

Enrollment presents the current decision and its prerequisites, with the following information available in context:

1. Term, applicable deadline, bounded window applicability, derived stage, responsible owner, next action, and one primary button.
2. Proposed subjects and exact payment requirement with one next action; supporting readiness evidence remains inspectable, with a short reason/office/remedy for a blocker rather than a five-card hold/gate display.
3. Proposed or official subjects and schedule.
4. **Why these subjects** and a contextual link to the full curriculum evaluation.
5. Academic blockers, unavailable requirements, excluded dependent courses, any result-impact review, shortage state, and bounded completion outlook. An unreleased prerequisite shows the source course/Term, excluded dependent course, responsible Registrar office, and **This subject can be added only after the prerequisite grade is released and ordinary enrollment checks pass**.
6. Placement and reservation evidence, including the shared institutional expiry deadline.
7. Amount required now, verified payment applied, Approved Coverage applied, remaining amount required now, satisfaction basis, clearance state, and **Finance** link.
8. Current/historical COR and registration/change history linked to the same admissions source. Physical-paper follow-up remains outside TALA; it creates no new enrollment checkpoint.

Exactly one primary action appears: **Start enrollment**, **Start registration**, **Confirm proposed subjects and schedule**, **Continue to Finance**, or **View COR**. When the proposal looks wrong, the page says **Do not confirm—contact Registrar** and provides the official contact path; it creates no ticket or chat.

### Registrar Students & Enrollment workbench

The selected-term header shows term and applicable windows, authoritative deadlines, current readiness, official-enrollment count, shortage count, and state-appropriate primary actions, including header trigger actions **Start registration for Ready Applicant** and **Start Continuing Enrollment**. **Identity Distinctions:** `Ready Applicant != Registration Case != Official Enrollment != Student Identity != Student Number`. Ready Applicants with pre-registration credentials verified appear in an actionable queue or modal where the Registrar can explicitly trigger registration case creation via `StartRegistrationCase::forReadyApplicant()`.

Tabs are **Ready to prepare**, **Waiting for learner**, **Placement and shortages**, **Finance pending**, **Ready to finalize**, **Adjustments and Drops**, and **Official and history**. Terminal same-Term cases remain in history and expose **Reopen registration case** only to Registrar before the final cutoff or with exact late-enrollment authority. A result impact before finalization returns the exact case to **Ready to prepare**; after finalization it appears in **Adjustments and Drops** with the released source, affected course, owner, window/late-authority state, and next action. Counts are operational orientation, not a chart dashboard.

The native table searches legal name, verified email, application reference, and student number. Native filter-panel controls cover term/program, Applicant/continuing context, `StandardCurriculum`/`IndividuallyAdvised`, academic enrollment effect, checkpoint/stage, shortage/capacity condition, finance state, deadline/overdue state, and started/finalized/last-activity date ranges. Active filters remain visible; column-header dropdowns are not used.

Columns lead with learner and reference, program/term, plain-language stage, responsible owner and next action, proposal/confirmation, placement, Finance state, nearest deadline, and last activity. Secondary evidence collapses on narrower displays.

The record reads in this order:

1. Stage, owner, deadline, failed reason, and primary action.
2. Identity, program, curriculum, and term.
3. Selection basis and compact curriculum evaluation.
4. Proposed-registration version and confirmation.
5. Eligibility, classes, capacity, reservations, and shortages.
6. Enrollment-payment requirement.
7. Finalization evidence.
8. Adjustments, Course Drops, timetable impacts, and COR versions.
9. Accessible supporting audit and email evidence.

State-valid actions prepare/revise ambiguous or advised subjects, record assisted confirmation, recover placement, finalize ready enrollment cases, cancel/reopen, adjust/drop and print current/historical COR. Eligible case opening automatically presents unambiguous standard subjects, temporary seat reservation and source-backed assessment; learner confirmation/payment is one clear flow. Finalize ready cases rechecks each case atomically. Exceptions retain their own authority and same-case history; no expired reservation, stale assessment or prerequisite failure is bypassed. A later qualifying grade release uses the ordinary Adjustment path, including window/late authority, learner confirmation, capacity, schedule, load and Finance checks. Secondary actions stay discoverable and crafted requests receive the same server guards.

An academic-result impact panel uses plain language:

- **Enrollment proposal needs review** before finalization; the current proposal/placement is stale, Registrar owns the revision, and finalization is unavailable.
- **Registrar review is required for your enrollment** after finalization; the current enrollment and COR remain official until an authorized change is applied.
- **Adjustment period closed** when no current late authority exists; the panel names the responsible office, what remains usable, and the next permissible institutional path.

The panel never promises that a course will be added, removed, replaced, or retained. A current late-adjustment authority reveals the existing **Record adjustment** action only after capacity, conflicts, learner confirmation, timetable, load, and Finance readiness pass. Course Drop remains a separate action.

### Placement and shortage presentation

Proposal rows show course, curriculum requirement served, class reference, schedule/mode/room, units/contact hours, prerequisite/equivalency result, capacity/reservation result, and plain-language issue. Learners never see other learners or internal capacity analytics.

Registrar shortage rows show affected course and learners, current and protected capacity, aggregate unmet demand, valid alternatives, academic impact, owner, and next action. Their aggregate `UnmetClassDemandProjection` feeds Clinic 3's Draft Class Offering preparation without exposing learner records there. Resolution links to another valid class, a safe capacity-only amendment, or Clinic 3's externally approved Additional Offering path. The UI promises no ranked position or future seat.

### Accounting Enrollment Clearance

One native queue shows learner, reference, term, assessment basis/source, assessed total, amount required now, verified payment applied, Approved Coverage applied, remaining amount required now, satisfaction basis, clearance state, deadline, and next action. `Assessment required` joins the existing queue when neither a valid `PublishedFeePlan` nor an eligible `AuthorizedIndividualAssessment` is current; it does not create another resource. Accounting may record its owned clearance evidence, an eligible exact externally authorized individual assessment, and externally approved coverage through focused actions. It cannot calculate charges from a rate, determine scholarship eligibility, change subjects/classes, create Student identity, or finalize enrollment.

The focused form records an externally calculated result only. It contains no per-unit rate, formula builder, inheritance, percentage, penalty, refund, or implicit default. Enrollment and changed-registration surfaces identify `Published fee plan`, `Authorized individual assessment`, or `Unavailable`. A changed registration distinguishes **Additional clearance required**, **No additional amount**, and **Accounting review pending**.

This focused action records only an externally approved account effect. Missing, stale, conflicting, unreconciled, unsupported, or excessive authority records nothing. There is no scholarship application, eligibility, ranking, renewal, disbursement, silent cap, refund, or financial-accommodation workflow.

### COR UI and print contract

The current and historical COR surface is an authenticated Generated Read-Only View. It shows institution/document identity; student number and legal name; program, curriculum, term, and enrollment basis; curriculum levels represented; official courses and class/schedule details; total units; assessment-at-finalization basis/source and categories, Approved Coverage and verified-payment amounts, satisfaction basis, remaining amount as of finalization; and actual recorded authority where required. A later authorized removal or Course Drop may identify **Accounting review pending** without changing the frozen assessment snapshot.

LRN, live ledger activity, future installments, attempts, receipt history, continually changing balances, and fictitious signatures are excluded. Later financial activity links to Student Account/SOA. The document is restrained, high-contrast, grayscale-safe, and offers one browser print/save-as-PDF action.

### Composition rationale

| Starting composition | User outcome |
|---|---|
| Learner guided status page | Learners can return across office handoffs and find the current owner, checkpoint, evidence and next action. |
| Single Registrar Students & Enrollment workbench | Registrar can keep versioned proposals, placement, Finance, finalization and COR synchronized through one Registration Case. |
| One next enrollment task with disclosed readiness evidence | Learners understand their subjects, required payment, responsible office and next step; Staff can inspect each actual guard without exposing a typed hold engine. |

### Default ordering and page-specific states

Enrollment queues sort overdue/action-needed cases first, then nearest deadline, then oldest last activity and stable case reference. Proposal rows follow curriculum order, then class reference. Shortages sort by nearest deadline, course, and affected learner. Accounting Clearance sorts by nearest deadline, remaining required amount, then learner. COR history sorts newest version first.

| Surface | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Learner Enrollment | No current case offers a valid start only when eligible; completed/no-current-term state explains next boundary | Checkpoints and primary-action position remain stable | Stale proposal/window/placement/Finance/result source refreshes before action; failed action preserves current case | Authority-blocked, lifecycle-blocked, closed, or expired state identifies source, owner, usable remainder, and next path without exposing internal records |
| Registrar workbench / record | No cases and no filter matches are distinct; filters can be cleared | Tabs/counts/table/detail loading are labelled independently | Stale proposal, prerequisite-eligibility, result-impact, placement, finalization, adjustment, or drop action is rejected and current facts shown | Unauthorized roles and direct records are inaccessible without existence disclosure; closed Adjustment exposes no action without exact late authority |
| Placement and shortages | No shortage states **No unresolved shortage**; no alternatives names Clinic 3 owner/action | Capacity/reservation checks show bounded progress | Concurrency loss or expiry refreshes capacity and never oversubscribes | Learners cannot see other learners or internal capacity analytics |
| Accounting Clearance | No pending cases and filtered empty are distinct | Assessment, payment, and coverage facts show source loading | Missing, stale, unreconciled, or unauthorized assessment/coverage authority is `Unavailable`; a valid account with an unsatisfied current obligation is `ActionNeeded`; failed recording preserves safe input and never creates a fallback, silent cap, or false clearance | Accounting cannot change academic records, create identity, calculate a formula, determine funding eligibility, or finalize |
| COR current/history | Unavailable before official enrollment is explicit; missing historical version is an assurance fault | Print view reports loading without presenting a partial official document | Superseded version is labelled; print failure leaves authenticated view authoritative | Only owning learner and authorized Staff see COR; direct unauthorized access reveals nothing |

Placement, shortage, adjustment, Course Drop and timetable-impact actions are contextual parts of the Registrar workbench, with acceptance coverage for each capability ID.

Clinic 4 demonstration data includes ordinary published-plan enrollment, reduced and Individually Advised cases with selection-specific authority, changed-registration branches, and coordinated `REG-2026-ST-001`. That Special Term case consumes `TERM-2026-ST`, `CLS-ITE3-ST-A`, and Additional retake `CLS-IT201-ST-R`; excludes dependent `IT301` only; remains `Unavailable` until `ACT-2026-ST-001`; then shows PHP 2,000 Applied coverage plus PHP 1,000 verified payment as `Mixed` clearance before official enrollment and COR. The walkthrough must preserve the same references through Clinics 3–6 without creating a Summer, tutorial, irregular-student, scholarship, or accommodation workflow.

Clinic 4 also includes `REG-2026-0011`, whose unreleased prerequisite excludes only the dependent course until a satisfying release makes it eligible for the ordinary Adjustment path, plus `REG-2026-0012`, whose adverse post-enrollment grade correction opens one review. The latter proves open Adjustment, closed Adjustment with exact late authority, and closed Adjustment without authority on desktop/mobile and by keyboard/screen reader. Only an applied guarded change creates a new COR; duplicate result, failed email, stale Finance, capacity conflict, or unavailable late authority leaves the current enrollment/COR intact.

### Responsive, accessibility, failure, and communication behavior

Course and queue rows stack with labels on mobile; information order is unchanged; the primary action remains reachable; secondary actions remain discoverable. All controls are labelled, keyboard reachable, visibly focused, and announced with current status. Meaning never depends on color.

Loading, empty, stale, expired, inaccessible, 403, 404, 419, 429, validation, concurrency, and integration-failure states name what happened, the responsible owner, what remains usable, and a safe recovery action. A newly released or corrected prerequisite result is announced as an eligibility change and never as an automatic course add or removal. A failed checkpoint expands; a multi-check readiness surface whose checks all pass reduces them to **All required checks passed**.

Queued, idempotent email is limited to the continuing-Student enrollment-window notice, proposal ready/materially revised, payment or coverage action required, official enrollment/COR ready, reservation release/case expiry, and official adjustment/Course Drop. On first enrollment, the official-enrollment/COR message also explains that Student access is active; no separate activation email is sent. An affected timetable revision uses Clinic 3's one shared publication event, with Clinic 4 supplying affected enrolled-Student recipients and updated schedule/COR context. Routine saves, checks, navigation, and recurring reminders remain in-workspace only. Mail failure never rolls back enrollment or financial state.

### Implementation responsibility

Composition follows DESIGN.md and the matching brief, using framework research and supported customization. Preserve searchable work, distinct source facts and actual inputs, stage-appropriate actions, source-owned readiness, current/historical COR access and the authenticated print boundary.

</details>

<a id="clinic-5--teaching-final-grades-academic-records-lifecycle-and-completion-ui-authority"></a>

## Teaching, grades and academic records

Faculty submits results; Registrar releases/corrects them and issues permitted records; learners understand academic position.

<details>
<summary>Page, source, action, state, output and responsive/recovery contract</summary>

> **Authority status — PRD 05 UI mapping complete.** This section translates the standalone PRD 05 into exact role surfaces and does not authorize implementation.

### Navigation and page inventory

| Role | Primary surface | Contextual destinations |
| --- | --- | --- |
| Faculty | **Grade Rosters** | Assigned official schedule and returned-roster history |
| Registrar | **Grades & Completion** workbench | Student Record, official Class Offering, curriculum and verified external-competency evidence, Clinic 6 output-restriction evidence |
| Student | **Academics** | Examination Period, external-competency result, unofficial print view, and contextual Enrollment link whenever an initial release, INC resolution, or correction affects registration |
| Academic Head | Read-only Academic Oversight | Examination Period, external-competency, progress, correction, lifecycle, and conferral authority evidence |
| Accounting | Clinic 6 output-scoped restriction only | Student Account; no grade or academic decision action |
| System Administrator | Queue, email, and System Health evidence only | Technical evidence without academic-record authority |

There is no Gradebook, Attendance, Period Grades, What-if Audit, Graduation Batch, Transcript Template, or academic-policy Settings navigation item.

The Clinic 5 visual comparison considered **role workbenches**, **Student-record first**, and **separate peer resources**. Role workbenches are accepted because complete-roster work remains class-centered for Faculty, cross-record decisions remain contextual in one Registrar workbench, and Students receive one coherent academic story. Student-record first obscures whole-roster submission; separate resources create navigation sprawl. Those alternatives are rejected rather than retained as hidden navigation.

### Faculty Grade Rosters

The native queue leads with course/class reference, program/cohort, official learner count, completed-result count, submission deadline, plain-language state, owner, and next action. Search covers class reference and course; filters cover term, state, and deadline/overdue state. Before release, Faculty may see that their assigned roster is one missing source for term-grade completeness; they never see another class's results or a learner's term/cumulative average through this context.

The roster table shows Student number, legal name, official enrollment state, one controlled final-grade/INC selector, derived academic result, and any validation or lifecycle explanation. Selecting `INC` always reveals the required completion note and previews the one-year deadline calculated from the official Term end. The designated submitter receives **Save draft** and **Submit complete roster**. Every assigned Faculty member with roster-view access receives **Print class roster** and **Export roster CSV** as contextual secondary actions; view-only co-Faculty still receive no edit or submit action. Returned-row correction, history, and evidence remain secondary actions.

The print action opens an authenticated A4 landscape **CURRENT CLASS ROSTER — Operational reference** view with practical 12 mm margins, repeated identity/table headings, row-safe page breaks, and automatic page numbers; the CSV is a private initiating-actor download. Both use the selected Class Offering's current Clinic 4 membership, fixed identity/enrollment columns, legal-name/Student-number ordering, an Asia/Manila as-of time, formula-safe cells, role-derived purpose, and logged output access. Neither uses selected table rows, a column chooser, a second format, a bulk action, or a Reports destination. Neither contains grades, result notes, contact details, Applicant evidence, or finance data, and neither creates an official issuance event.

An empty result remains unfinished and cannot be released. A class with no officially enrolled learners shows **No officially enrolled students in this class** and no print/export action. `INC` reveals the required short completion note. Stale, changed, inaccessible, or failed roster generation creates no partial artifact and offers **Refresh roster** or the shared safe return/support path. The UI never requests Preliminary, Midterm, Final-period values, formulas, attendance, raw scores, `P`, Course Drop, withdrawal, or approved-credit marks.

### Registrar Grades & Completion workbench

The workbench contains **Grade Review**, **INC & Corrections**, **Academic Progress**, **Lifecycle**, **Completion & TOR**, and **History**. Counts orient staff to pending work; they do not create a chart dashboard.

The native table searches Student number, legal name, course/class reference, and TOR reference. Filters cover term, program, course, Faculty, roster state, deadline, released result, INC/correction state, progress, lifecycle, completion readiness, and relevant date ranges. Active filters remain visible; no column-header dropdowns are introduced.

Each record reads in this order:

1. State, responsible owner, deadline, next action, and one primary action.
2. Student or roster identity and authoritative term/class context.
3. Released result, INC, correction, or progress facts relevant to the selected tab.
4. Term weighted average/cumulative GWA readiness and curriculum-evaluation effect where applicable.
5. Lifecycle, completion, or transcript effect where applicable.
6. Authority and evidence, including the original Term end, current calculated deadline, and any Registrar deadline-amendment authority, reason, actor, and time.
7. Accessible supporting immutable history, audit, and email evidence.

State-valid primary actions include **Release roster**, **Return specified rows**, **Release INC completion**, **Change INC deadline**, **Record authorized correction**, **Record verified external result**, **Record authorized academic decision**, **Record lifecycle result**, **Record graduation**, **Generate TOR preview**, and **Issue official TOR**. Release/INC/correction previews identify affected active Registration Cases and the Clinic 4 review consequence without offering an enrollment action in Clinic 5. The external-result action appears only for an active authority-backed requirement and shows the Student, requirement and treatment, assessment date, `Competent`/`NotYetCompetent`, optional verified NC/COC reference and validity, safe remarks, external source, and append-only impact preview. **Change INC deadline** requires authority, reason, prior/new dates, and current-version revalidation; there is no lapse or automatic-grade action. The academic-decision action appears only for a real external decision or opened review; failed-unit percentages never create a button or status. Only one primary action appears for the current decision. Release, correction, external-result recording, consequential decisions, conferral, and TOR issuance are record-specific actions; no bulk form exists for them.

### Student Academics

Student Academics provides these read-only task groups, prioritizing current status and any necessary action:

1. Current academic-record status and next action.
2. Released grades grouped by term.
3. **Term weighted average** and **Cumulative GWA**, or the explicit **Grades not complete**, incomplete-result, or not-applicable state; when current values are withheld, show the last complete cumulative **Through [term]** value if one exists.
4. Curriculum evaluation with required courses, attempts, credited mappings, current enrollment, prerequisites, deficiencies, and authority-backed external-competency requirements/results.
5. Factual curriculum position and `AcademicEnrollmentEffect`, including any recorded institutional decision, excluded or newly eligible dependent course, active Registration Case impact, responsible owner, and safe explanation.
6. Attempted, earned, and remaining units.
7. Completion readiness, the outstanding source/owner and guidance for the institution's external completion authority; no separate online graduation-application chain.
8. Correction, INC, external-competency reassessment, and lifecycle history.

Every unresolved `INC` shows its original Term end, current inclusive deadline, `CompletionOpen` or **Completion overdue**, responsible Registrar office, and next safe action. Deadline amendments show the previous/current values, authority, reason, actor, and time. Deadline passage never changes the grade or sends an overdue email. An overdue unextended result shows no ordinary Faculty completion action; it remains `INC` with no credit and directs the Student to the retake path unless Registrar records an authorized future extension.

Use fixed **Term weighted average** and **Cumulative GWA** wording. A partially released term shows **Grades not complete**. An open included INC whose requirement remains unsatisfied shows the PRD 05 pending state. Its settled overdue/retake no-credit exclusion is visible beside the calculation and leaves the historical mark intact. Show the current as-of time and the source that changes readiness. A grade-complete term with only excluded/nonnumeric outcomes shows **Not applicable — no included academic units**. The cumulative value uses all included attempt grade points and units.

An external-competency row shows qualification/level, `Tracked only` or authority-backed `Completion required`, assessment date, safe `Competent`/`Not yet competent` result, optional verified NC/COC reference and validity, source, and current/superseded history. A missing tracked-only result says **Not recorded** and never blocks enrollment, grades, completion, or conferral. A completion effect appears only when the active Curriculum Version cites exact authority for `CompletionRequired`. The row never contributes a grade, unit, average, prerequisite, finance, email, or standard-TOR value.

The printable action is labelled **Unofficial record — for student reference**. It produces a `FocusedTALACustom` A4 portrait view from the released academic projection as of one explicit time. Every page says **UNOFFICIAL STUDENT RECORD** and **Unofficial — for student reference** and includes institution identity; Student legal name/number, Program, and Curriculum Version; output/as-of references; chronological exact Term groups; released course/attempt/credit facts; term units and average/readiness; cumulative unit/GWA readiness; curriculum/completion summary; and safe INC/correction context. It excludes draft/submitted results, private evidence, Faculty, schedule, finance, certification/signatory/seal, and official-TOR actions. Continuation pages repeat Student identity and headings; navigation/controls do not print; stale/unavailable source or rendering failure creates no artifact. Official TOR issuance is absent from Student actions. Whenever an initial release, INC resolution, or correction affects an active Registration Case, the page states **Registrar review is required for your enrollment** and links to Enrollment without promising an automatic course change. A newly eligible dependent course says that Registrar may add it only through the ordinary Adjustment path; a closed Adjustment state says what remains official and who owns the next permissible path.

### TOR preview and issuance

Registrar records the TOR request and issues from one authenticated preview using TALA Standard TOR — Servitech v1. Issue is available only with releasable history, verified identity, request, current Accounting output-restriction source, template/signatory and successful rendering; no per-request Accounting approval is required. An active restriction blocks only the output and names office/recovery. Current/former Students need no blanket graduation gate; completion/conferral appears only when recorded. Preview/issued status stays clear. Existing void/replacement/supersession history remains attributable, with further UI refinement deferred. No Student self-issue/download or template editor is introduced.

The issuance record retains the actual request reference/date, derived due date, current output-scoped restriction evidence, source/template/signatory versions and issuance reference/time. A blocker preserves the request clock and safe office/next action. Physical signature, seal, CAV, claiming, courier and delivery stay external; no generated output claims those acts happened without evidence.

### Sorting and page-specific states

- Faculty rosters sort overdue and due-soon work first, then course/class reference. **No grade rosters assigned** links to the published teaching assignment; a filtered-empty result offers **Clear filters**. The selected Class Roster uses fixed legal-name/Student-number ordering; empty, stale, changed, inaccessible, or generation-failure states never expose a partial print or CSV.
- Registrar queues sort action-needed records first, then due date and latest activity. **No records need review** confirms the active term and filters rather than implying that no academic records exist.
- Student Academics groups terms newest first while TOR rows remain chronological. **No released results yet** explains that only Registrar-released results appear and provides no misleading action.
- Partly released terms show **Grades not complete**, the count/source of missing official outcomes to authorized Staff, and the last complete cumulative **Through [term]** value when available. They never show a partial term value or a newly calculated cumulative value.
- Grade-complete terms with no included numeric units show **Not applicable — no included academic units**. The fixed Term weighted average and Cumulative GWA labels identify the actual calculation scope.
- INC work sorts `CompletionOverdue` first, then nearest current deadline. Every row has a deadline; overdue rows expose only authorized extension or retake guidance, and no automatic-grade state exists.
- External-competency results order current first, then newest superseded attempt. Missing tracked-only evidence shows **Not recorded**; missing/stale authority disables recording and never invents a completion block.
- TOR readiness names the exact unavailable source: identity, releasable official academic history, request reference, Clinic 6 output-restriction evidence, TALA Standard TOR version, signatory data, or rendering. Preview creates no issuance. The surface never shows a generic **Not cleared** state.
- Loading retains the page heading and announces progress. Stale or concurrent actions preserve entered data when safe, identify what changed, and require review before resubmission. Result-impact status, prerequisite-eligibility change, and Adjustment-window state are announced to screen readers and never depend on color. Inaccessible records use the shared non-disclosing recovery surface. Technical or mail failures identify the responsible owner and one safe retry, return, or support action.

### Responsive, accessibility, failure, and communication behavior

Roster, grade-history, curriculum, and queue rows stack with labels on mobile. Reading order remains unchanged, the primary action remains reachable, and secondary actions remain discoverable. Wide TOR previews provide a readable on-screen summary and a print view rather than forcing an unusable scaled document into the mobile viewport.

All controls are labelled, keyboard reachable, visibly focused, and accompanied by screen-reader status text. Examination-period dates are announced with their source and unavailable state; external-result treatment and outcome never depend on color. Empty, loading, stale-record, inaccessible, expired-session, validation, late-window, concurrency, mail-failure, and technical-failure states state what happened, who owns recovery, and the safe next action.

Queued email is limited to the Faculty submission request, returned roster, grade release without values/attachment, INC release/deadline, deadline amendment, INC resolution, authorized correction, consequential progress/lifecycle, completion action-required, and conferral. The one release/INC/correction email may add **Registrar review is required for your enrollment** and an authenticated Enrollment link when the same event affects an active case; no separate review email is sent. Deadline passage sends no email. Routine saves, calculation/readiness refresh, queue movement, navigation, countdowns, and recurring reminders remain in-workspace only.

The Clinic 5 synthetic set includes `INC-OPEN-001` with its calculated deadline and later completion, `INC-OVERDUE-002` whose deadline passes without a grade change/email and exposes only extension-or-retake guidance, and `INC-AMEND-003` whose authorized amendment returns it to `CompletionOpen`; `EXT-COMP-CSS-NCII` as tracked-only; `EXT-RES-CSS-001` `NotYetCompetent` followed by superseding `EXT-RES-CSS-002` `Competent`; one missing tracked-only result that does not block completion; hypothetical authority-backed `EXT-COMP-WEB-NCIII-REQ`, whose missing result keeps completion pending without becoming Servitech policy; and coordinated `TERM-2026-ST` classes `CLS-ITE3-ST-A` (`1.75`) and `CLS-IT201-ST-R` (`2.50`). Releasing the first class alone must show **Grades not complete** and the prior cumulative **Through [term]** value. Releasing the second must show Special Term `2.13` and cumulative `2.01` from 90 prior included units/180 weighted points plus six units/12.75 points; the earlier `IT201` `5.00` remains counted while the retake satisfies the curriculum.

The same Clinic 5 set includes the prerequisite result changes for `REG-2026-0011` and `REG-2026-0012`. Release preview identifies the affected exact case without exposing an enrollment action. Student Academics shows one contextual Enrollment link and the safe review message; email contains no grade value and no duplicate review notice. A satisfying release only makes the excluded course eligible for ordinary Adjustment, while an adverse post-enrollment correction leaves Official Enrollment unchanged until Clinic 4 records an authorized outcome.

The negative-space acceptance coverage proves that Student Home and Faculty schedule show the same approved Examination Period source; Registrar edits its calendar and Academic Head may inspect that authorized source; missing/stale dates produce no fabricated value; and exact class arrangements remain outside TALA. It then blocks an external-result action against a missing/stale requirement, records `EXT-RES-CSS-001`, appends `EXT-RES-CSS-002` without overwriting the first attempt, and shows the same safe result in Student Academics and Academic Oversight. The tracked-only missing example remains **Not recorded** without blocking completion; hypothetical `EXT-COMP-WEB-NCIII-REQ` remains pending only because its synthetic curriculum authority explicitly says `CompletionRequired`. No TESDA operations, new destination, email, standard-TOR field, grade, average, unit, prerequisite, or financial effect appears.

### Implementation responsibility

Composition follows DESIGN.md and the matching brief, using framework research and supported customization. Preserve final-result entry and validation, attributable release/correction, readable academic source evidence, Student Academics and the unofficial/TOR output contracts. Product boundaries remain in PRD 05.

</details>

<a id="clinic-6--accounts-official-outputs-operations-and-assurance-ui-authority"></a>

## Accounts, outputs and assurance

Accounting and learners understand accounts and verified financial effects; permitted outputs and local assurance remain reachable.

<details>
<summary>Page, source, action, state, output and responsive/recovery contract</summary>

### Navigation and primary-page inventory

Accounting receives exactly two primary finance destinations:

1. **Fee Plans**
2. **Student Accounts**, with **Accounts** and **Payment Exceptions**, including contextual output restrictions

System Administrator receives **System Health** and **Governance & Audit**. Student receives one **Finance** destination. Applicant payment status remains embedded in Clinic 4 Enrollment; there is no Applicant Finance destination. Alumni retain read-only Student Finance history.

| Primary page | User goal | Required information and priority | Native surface decision |
|---|---|---|---|
| Fee Plans | Publish one fixed ordinary Program-and-Term version | Current plan, action-needed Drafts, upcoming Terms, history | Native filtered Table plus focused create/view pages; no formula or calculation builder |
| Fee Plan detail | Prepare and publish exact charges and obligations | Identity/authority, charge lines, obligations, readiness, history | Sections, Grid, ordered Repeater/table rows, Infolist after publication, focused publish Action |
| Student Accounts | Find the next account decision, including `Assessment required` | Status, person, account, Program/Term, assessment basis/source, required/payment/coverage/due, satisfaction basis, next action | One role-focused Table with Accounts and Payment Exceptions, native filters and contextual output-restriction actions; existing CSV behavior is preserved without further capstone refinement; no separate assessment, coverage, or Reports destination |
| Payment Exception detail | Check safe evidence and record the external result | Reason/current due, evidence, named target obligations, proposed applied amount per obligation, history, consequence | Authorized view Page/Infolist with private preview and focused Actions; no generic allocation form |
| TOR-scoped restriction | Record or resolve the Accounting restriction on the output action | Learner/output scope, current financial source, safe reason, office, effective scope and history | Contextual Student Account action; no per-request clearance approval queue |
| Student Account detail | Explain one Term position or record an eligible exact individual, coverage, or verified payment result | Current status/due, assessment basis/source, named obligations and applied payment/coverage amounts, satisfaction basis, next obligation/action, projection and supporting evidence | Task-led summary and contextual assessment/coverage/payment controls, with authorized history accessible; no unassigned or cross-Term posting |
| Authorized individual assessment | Record an externally calculated exact result, not calculate a fee | Current Registration/change version and course/unit evidence, reason/authority, exact lines/obligations, totals, impact preview | Contextual Form on Account detail; no formula, rate, inheritance, percentage, penalty, or refund controls |
| Approved Coverage | Record one externally approved Term Account effect | Current Assessment/obligations, category/source, authority/date, exact applicable amount, effective date, safe description, impact preview | Contextual Form on Account detail; no eligibility, application, renewal, disbursement, accommodation, cap, refund, or allocation controls |
| Enrollment payment requirement | Complete Clinic 4's finance checkpoint | Required now, state, assessment basis/source, account, submission, next action | Clinic 4 embedded Section with private File Upload and focused Actions |
| Student Finance | Understand current or historical account | Current due/status, assessment basis/source, next obligation, safe actions, recent activity, outputs | Focused Page with Sections, Infolists, responsive activity rows, contextual Actions |
| System Health | Distinguish local evidence from unknown external state | Capture time, service/status/evidence/as-of, next step | Read-only status Table; local refresh; optional self-test email only |
| Governance & Audit | Investigate high-value evidence | Tab, filters, newest events, selected detail, retention state | Tabs, read-only Tables/Infolists, fixed filters |
| Account Statement | Read or print an as-of non-tax account position | Identity/context, charges, activity, obligations, totals, disclaimer | Authenticated print-safe browser view |
| Payment acknowledgment within SOA | Understand verified posting in the account statement | Payment summary, actual posting references/obligation effects and non-tax disclaimer | One authenticated SOA view; historically issued separate acknowledgments remain accessible and version-labelled |

### Accounting setup and workbench interaction contracts

#### Fee Plan detail and publication

Draft row controls provide explicit **Move up** and **Move down** buttons; order never depends on dragging. Published views replace fields with an Infolist and offer only `Create successor`.

#### Student Accounts workbench

The Payment Exceptions tab uses risk/reason, person/account, claimed amount, channel/source, submission age and Review. Contextual TOR restrictions show the responsible office, safe reason, output scope, current source and next permitted action; no per-request clearance tab or approval queue is required.

#### Contextual finance CSV actions

Both exports are `NativeFilament` CSV-only actions with fixed columns, private initiating-actor retrieval, explicit query scoping, a required purpose, and no column chooser or alternate format.

**Export account status** acts on the exact normalized Accounts-tab filters, authorization scope, and deterministic visible ordering confirmed in its dialog. It uses `tala-account-status-YYYYMMDD-HHmmss-PHT.csv` and these ordered labels: `Account Reference`, `Person Reference`, `Program`, `Term`, `Assessment Total (PHP)`, `Required Now (PHP)`, `Verified Payment Applied (PHP)`, `Approved Coverage Applied (PHP)`, `Current Due (PHP)`, `Projection State`, `Satisfaction Basis`, `Assessment Basis`, `Source Version or Authority Reference`, `As of (Asia/Manila)`.

**Export verified payments** appears only in one selected Student Account's Payments context and applies its active state/date filters. It uses `tala-verified-payments-YYYYMMDD-HHmmss-PHT.csv` and these ordered labels: `Payment Reference`, `Account Reference`, `Person Reference`, `Term`, `Amount (PHP)`, `Channel`, `Masked External Reference`, `Posted At (Asia/Manila)`, `Verification Basis`, `Current State`. It never expands into a system-wide payment report.

The confirmation shows the exact export, scope, row-count estimate, fixed columns, required purpose, and private retrieval. Both files are UTF-8 with BOM, comma-delimited, RFC 4180 quoted, and CRLF. Money uses ungrouped two-decimal values without `₱`; date/time uses RFC 3339 with `+08:00`; blank never means zero. Formula-like text beginning with `=`, `+`, `-`, `@`, tab, or carriage return is prefixed with one apostrophe, while numeric and date cells come only from typed authority.

Export rechecks role, per-record visibility, filters/context, and source/as-of state and allows at most 10,000 rows. Above the limit, the dialog requires narrower filters. Zero rows records `NoRows` and creates no file. Failure creates no partial file, retains the normalized scope, and offers retry. Audit records actor, role, context/filters, purpose, row count, outcome, and time. No export email, notification center, report hub, private proof, raw provider data, bank detail, secret, eligibility material, or internal note is included.

### Detailed Account and Payment Status reference design

#### Accounting account detail

The account makes status, current due, separate payment/coverage effects, satisfaction basis, next obligation and next action readily identifiable. Supporting evidence remains accessible through task-appropriate disclosure. `Record authorized individual assessment` appears contextually only for an eligible current exception, as shown next; it never appears for Ana's ordinary published-plan account. `Record approved coverage` appears only when a current Assessment and named remaining obligations exist. An authorized reversal dialog names the payment or coverage, amount, external authority, resulting projection, and append-only effect; its confirmation is `Record reversal`, not `Yes`.

#### Authorized individual assessment

The action is available only for an approved Special Term, a reduced enrollment whose approved charges differ from the fixed plan, an Individually Advised selection-specific result, or an authorized adjustment/Course Drop effect. Missing, stale, unreconciled, or unauthorized source evidence remains `Unavailable`; the form never calculates from the displayed units.

#### Approved Coverage

The action records one `Applied` account event. Excess, stale, conflicting, unsupported, or unreconciled authority records nothing and preserves safe entered fields. `Superseded` and `Reversed` results start from coverage history and require named authority and an exact impact preview. No learner application, eligibility document, ranking, renewal, disbursement, cash movement, or email appears.

#### Payment Exception detail

Private proof requires record authorization and never appears as a public URL. Field errors say how to recover, and failed verification states whether no posting occurred.

`Verify ₱6,000` is available only when the external check proves that ₱6,000—not the claimed ₱8,000—was actually received and the remaining-obligation guard passes. If the external source proves ₱8,000, no partial posting is created; the exception remains with Accounting for an external resolution because Clinic 6 has no refund or excess-allocation workflow.

### Learner interaction contracts

#### Student Finance — desktop

`Pay exact current due` is unavailable when the registration is inactive, the due is zero, the source is stale, PayMongo local readiness is unavailable, or a matching attempt remains pending. A retained payment with ended registration shows **Registration ended — Accounting review required**, same-Term account history and the Accounting support/next action under PRD 06. Browser return uses `Payment confirmation pending` or `Checkout cancelled — no payment was recorded from this return`.

#### Student Finance — mobile and alumni variant

At 360/390 CSS pixels, actions remain inset inside the content margin and stack without clipping. The alumni variant uses the same history/output order but removes checkout, upload, and account-changing actions.

### Operations and assurance interaction contracts

#### System Health

Unknown or not-recently-checked evidence is never colored or labelled as healthy. Routine copy states what is available, what needs attention, and the safe next action in plain language. Refresh reads locally knowable evidence only. Provider status, backup-media custody, and restore readiness require external operational evidence; the exact evidence source, as-of time, and boundary remain available under technical details.

### Printable-output interaction contracts

Current printed outputs use the same authenticated frame: approved institution identity first; exact output title/status; source/version and generation reference/time; grayscale-safe semantic headings; repeated multipage identity/table headings; deliberate page breaks; no navigation or interactive controls; font fallback; page numbering; practical 12 mm margins; restrained Generated through TALA footer. COR, Unofficial Student Record, TALA Standard TOR and SOA are A4 portrait. Published Timetable retains its approved operational print orientation. New Application submission uses a screen receipt; historically issued acknowledgment prints retain their original source-bound frame. No output-count target substitutes for complete authorized content.

The current Class Roster uses the same authenticated, monochrome-safe, repeated-heading, non-clipping A4 landscape print quality, 12 mm margins, and automatic page numbering, but is explicitly labelled **Operational reference** with a current as-of time. It is an operational reference rather than an officially issued document, creates no issuance/version event, and is never proof of enrollment or grades.

SOA includes verified payment acknowledgment and retains its non-tax disclaimer on every generated copy. Historically issued separate acknowledgments remain accessible and source-labelled. The institution-first frame contains no mascot; TALA appears only in the restrained footer.

### Composition rationale

| Task | Starting composition | User outcome |
|---|---|---|
| Account/payment status | Summary-first status, due, next obligation/action, then history | Learners can understand the amount due and next step before reviewing account history |
| Accounting workspace | Fee Plans plus one tabbed Student Accounts workbench | Accounting prepares fee sources and reviews account evidence in its work context |
| Approved Coverage | Contextual Account action with append-only effect/history | Accounting records the attributable account effect of externally approved coverage |
| Assurance | Plain status and next action with exact locally recorded evidence and a safe disclosure boundary | Authorized users can distinguish recorded health evidence from unavailable evidence and follow the appropriate recovery step |

### Default ordering and page-specific states

- Fee Plans: current Published, action-needed Drafts, upcoming Term opening, Program, version.
- Accounts: `Assessment required`, then `ActionNeeded`/under review, nearest due, oldest relevant activity, person/account reference.
- Payment Exceptions: blocking or security mismatch, oldest submitted, reference.
- TOR-scoped restrictions: actionable current restriction, effective scope, responsible office, oldest relevant activity and stable learner/output reference.
- Account activity: newest authoritative event first; SOA activity is chronological ascending.
- System/audit events: newest first, then severity/status and reference.

Each page has a direct empty state, filtered-empty recovery, structure-preserving loading state, as-of/stale state, inaccessible state with no record disclosure, and action failure that states whether anything was recorded or posted. Missing, stale, unreconciled, or unauthorized assessment authority shows `Unavailable` and blocks only the consuming action without a zero or fee fallback. A failed individual-assessment action preserves safe entered rows, creates no Assessment, and names the failed readiness check. A failed coverage action preserves safe fields, creates no account effect, and names missing, stale, conflicting, unsupported, unreconciled, or excessive authority; it never silently caps or reallocates the amount. Adjustment and Course Drop views distinguish **Additional clearance required**, **No additional amount**, and **Accounting review pending**. Output failure creates no partial or official-looking artifact. System Health with no evidence shows `Unknown`, never a successful default.

### Responsive, accessibility, keyboard, and writing contract

- Learner pages qualify at 360 and 390 CSS pixels; staff desktop work qualifies at 1366 CSS pixels. Read-only staff detail remains usable at intermediate widths.
- Related items use tighter spacing than separate sections; controls align to shared edges and remain visually distinct from content. Important status and amounts lead the reading order.
- Tables become labeled cards where practical. Dense audit tables may use bounded horizontal scrolling with persistent labels.
- Tabs use correct `tablist`, `tab`, and `tabpanel` semantics, selected state, and arrow-key movement.
- Native landmarks, headings, forms, tables, links, buttons, and dialogs are required. Navigation hiding never substitutes for authorization.
- Visible focus, logical focus order, a skip link, 4.5:1 text contrast, 3:1 component/focus contrast, no color-only meaning, and 200% zoom/reflow are required.
- Every field retains a visible label. Placeholders show examples only. Errors identify the field, state how to recover, enter the error summary, and move focus to the first invalid field.
- Dialogs are named, contain focus, return focus, and repeat the exact consequence in the confirm button. Targets meet 24×24 CSS pixels; learner primary actions prefer 44×44.
- No action depends on drag, hover, or a pointer. Fee Plan ordering has Move up/Move down controls.
- Buttons are verb-first and sentence case: `Save draft`, `Publish plan`, `Submit payment evidence`, `Verify ₱6,000`, `Record reversal`, and `Clear filters`.

### Synthetic demonstration set and browser walkthrough

The authoritative synthetic records are `FP-IT-2026-T1-v1`, incomplete `FP-BM-2026-T1-d2`, Term Accounts `ACT-260001`, `ACT-260008`, `ACT-260014`, `ACT-260021`, `ACT-260027`, `ACT-260033` with Applied scholarship coverage and successor/reversal evidence, `ACT-260034` with an institutionally authorized `NoPaymentRequired` Fee Plan result, `ACT-260039` with a missing-webhook manual reconciliation and late event, `ACT-260041`, `ACT-260045` with a reduced Individually Advised exact individual assessment, `ACT-2026-ST-001` for `REG-2026-ST-001` with PHP 6,000 assessment, PHP 3,000 required now, `COV-2026-ST-001` PHP 2,000 Applied subsidy, `PAY-2026-ST-001` PHP 1,000 verified payment and `Mixed` clearance, and `ACT-260047` with changed-registration branches; TOR clearances `TOR-260003` through `TOR-260005`; reversed `PAY-260009`; one alumni account; and one degraded-health example. Identities use `example.test`; no real student, provider, wallet, eligibility, or proof data appears.

The browser walkthrough follows this order:

1. Accounting observes the blocked BM Draft and publishes the valid fixed IT plan.
2. Accounting opens Mira's `Assessment required` row and records a reconciled exact `AuthorizedIndividualAssessment`; stale authority blocks recording and no formula appears.
3. `REG-2026-ST-001` remains `Unavailable` until Accounting records `ACT-2026-ST-001` from the exact externally authorized result.
4. Excessive/stale coverage records nothing; valid `COV-2026-ST-001` applies PHP 2,000, `PAY-2026-ST-001` applies PHP 1,000, and Clinic 4 receives `Mixed` clearance without a coverage email or scholarship workflow.
5. Sam's changed registrations separately show additional clearance, authoritative no-additional-cost confirmation, and Course Drop Accounting review pending without an automatic refund, credit, penalty, forfeiture, or COR rewrite.
6. Applicant Ana sees the embedded due and assessment basis before Student creation and submits private evidence without posting.
7. Accounting verifies the external source; Clinic 4 receives `Cleared`, finalizes enrollment, and the same account gains the Student reference.
8. Student Finance and SOA show payment and coverage as distinct append-only account effects; payment acknowledgment within SOA identifies verified payment only, without representing coverage as cash.
9. Exact-due PayMongo return remains pending until a valid webhook; duplicate delivery posts and emails once.
10. A missing webhook remains pending. Accounting verifies the real provider source through the existing external-payment path; a later matching event creates no duplicate posting or email.
11. Mismatch, underpayment, rejection, resubmission, payment reversal, and coverage supersession/reversal preserve append-only evidence.
12. A later missed obligation or coverage reversal changes Finance only and does not undo enrollment or academic access.
13. Accounting resolves `Cleared`, `NotRequired`, and `ActionNeeded` TOR requests without a global hold.
14. The two Clinic 6 finance CSVs record purpose and output-access evidence.
15. System Health separates local evidence from external unknowns; Governance states that automatic retention disposal is not provided and makes no external compliance verdict.
16. Alumni opens historical Finance read-only.

The walkthrough is documentation authority only. Browser execution remains implementation-acceptance evidence for the owning future vertical slice.

</details>

## Shared role projections

<details>
<summary>Student Home, official Profile and read-only Academic Oversight</summary>

### Student Home — shared projection

Student Home leads with the current Term, one permitted next action, useful dates, tuition balance and effective authorized announcements. Enrollment, Academics and Finance remain links to the same source records. Explain missing/stale facts plainly; internal versions/as-of detail is secondary. Remove engineering lifecycle and empty Hold widgets. Show the approved Examination Period when available without inferring class examination arrangements from meetings.

If no action is needed, the page says so for the selected term and retains the three source summaries. Loading preserves headings. A stale source is labelled and cannot offer its mutation action. An inaccessible source reveals neither record existence nor detail.

### Student Profile — shared official identity projection

Profile is read-only. It shows legal name, Student number, program, assigned Curriculum Version, entry/admission reference, lifecycle projection, approved contact facts, source/last correction, and plain correction guidance. Email/password/MFA changes link to Clinic 1 Account Security. Students cannot directly edit official identity, program, curriculum, entry, lifecycle, enrollment, grade, or finance facts.

Registrar reaches the same source from Students & Enrollment and receives a focused **Record authorized correction** action with reason, authority/evidence, effective time, impact preview, and named confirmation. A successful correction updates future projections but never rewrites an issued COR/TOR snapshot.

### Academic Oversight — shared read-only projection

Academic Oversight is the Academic Head's role-specific evidence dashboard. It leads with a compact Academic Year/Term scope, source-owned findings requiring inspection, and four purposeful domains: Academic Authority, Term Planning, Grades & Progress, and Lifecycle & Completion. Academic Authority identifies its institution-wide Program/curriculum scope; Term Planning retains one exact Term. Other record lists state their own filters rather than silently inheriting the Term. Relevant current states take priority over a repeated launch-card directory or the Registrar's preparation sequence.

Findings identify the responsible office and link to the exact inspectable source. Domain destinations remain discoverable when no finding exists. Calendar and official/candidate timetable states stay distinct; the informational Examination Period is concise within Term Planning, with authority, source/version, owner and as-of evidence accessible through disclosure. Read-only drill-ins provide a contextual return preserving the selected Term and normal browser navigation. Academic Authority consolidates catalog and readiness access. Grade rosters and Student academic records expose released results and progress evidence; lifecycle and completion retain their source-owned evidence boundaries. External-competency requirements/results grant no assessment or certification power. No fabricated metric, universal all-clear, approval queue, escalation action, scheduling or academic-record mutation is introduced.

Empty means no source-owned item needs oversight under the current filters; it does not imply that records do not exist. Missing or stale sources are named as unavailable and never converted into a manually checked readiness result.

</details>

## Canonical UI Surface Coverage Inventory

<details>
<summary>Stable ID, role, source, action, state and output coverage</summary>

The inventory uses the columns below. `J1`–`J7` refer to the seven representative journeys in the next section; `Support` is a reachable supporting capability rather than a separate end-to-end journey. The count is not authoritative and may change when redundant presentation surfaces are consolidated or missing coverage is identified. Consolidation must not hide ownership, permissions, source evidence, actions, failures, responsive behavior, or accessibility, and must not recreate a generic dashboard.

| Coverage ID | Role/workspace | User-visible surface | Parent entry | Component disposition | Source PRD | Authoritative source | Primary action | Output | Required state/correction coverage | Responsive/print requirement | Acceptance journey |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `SHR-001` | Public | Public Gateway | Direct URL | `FocusedTALACustom` | PRD 01 | Published public content, active Programs, current Admission Cycle, and configured institution/map/media projection | Start application or sign in | — | Closed/unavailable entry retains safe guidance; unavailable media/map has a factual fallback | 390, 360, 768, 1366 | J1 |
| `SHR-002` | Shared identity | Registration and unified sign-in | Gateway or owning journey | `NativeFilament` | PRD 01 | Person, account, verification state | Register or sign in | Verification request | Duplicate identity, invalid credentials, rate limit | 390, 360 | J1 |
| `SHR-003` | Shared identity | Verification, recovery, reset, and MFA | Secure message or sign-in | `NativeFilament` | PRD 01 | Verification/recovery challenge | Verify or recover access | Security notice | Expired, consumed, invalid, or failed challenge | 390, 360 | J1 |
| `SHR-004` | Multi-role Staff | Workspace chooser | Successful sign-in | `NativeFilament` | PRD 01 | Current role assignments | Enter selected workspace | — | Zero authorized context or stale assignment | 390, 1366 | J1 |
| `SHR-005` | Authenticated | Account Security | Top bar account menu | `NativeFilament` | PRD 01 | Account security state | Update password or MFA | Security notice | Reauthentication or MFA failure | 390, 1366 | Support |
| `SHR-006` | Shared | Access and service failure | Any protected route | `FocusedTALACustom` | Baseline | Authorization and locally known service state | Return to authorized entry | — | Inaccessible, expired session, limited, unavailable | 390, 1366 | J1 |
| `APP-001` | Applicant | Applicant Home | Applicant fixed entry | `NativeFilament` | PRD 02 | Application and readiness projection | Continue current next action | — | No open cycle, correction required, withdrawn | 390, 360 | J2 |
| `APP-002` | Applicant | Five-step Application Wizard | Applicant Home | `NativeFilament` | PRD 02 | Application draft/version | Save and continue or submit | Submitted snapshot | Validation, stale draft, failed submission | 390, 360 | J2 |
| `APP-003` | Applicant | Requirements | Applicant Home or Wizard | `NativeFilament` | PRD 02 | Published Requirement Set and evidence | Submit or replace evidence | — | Missing, rejected, superseded, inaccessible evidence | 390, 360 | J2 |
| `APP-004` | Applicant | Submission receipt / historical acknowledgment | Application context | `NativeFilament` | PRD 02 | Submitted Application and immutable Requirement Set versions | Read received reference/time and next step; open authorized history | On-screen receipt | Failed/stale submission preserves safe input; historical artifact access is version-labelled | 390, 360 | J2 |
| `STU-001` | Student | Student Home | Student fixed entry | `NativeFilament` | Baseline | Source-owned Enrollment, Academics, Finance, and Examination Period projections | Open highest-priority safe action | — | Stale or unavailable source remains labelled; no inferred examination date | 390, 360, 768 | J3/J4/J5/J6 |
| `STU-002` | Student | Enrollment | Student Home | `NativeFilament` | PRD 04 | Registration Case, proposal, placement, readiness | Confirm or resolve current checkpoint | Current COR link | No proposal/class/assessment, expired reservation | 390, 360 | J4 |
| `STU-003` | Student | Academics | Student Home | `NativeFilament` | PRD 03/05 | Released results, external competency results, academic projections, and Examination Period | Open result or correction guidance | Unofficial academic record | Grades not complete; INC completion open/overdue/amended/resolved; external result not recorded; unavailable examination period | 390, 360, 768 | J3/J5 |
| `STU-004` | Student/alumni | Finance | Student Home | `NativeFilament` | PRD 06 | Term Account and current projection | Pay exact due or submit evidence | SOA with payment acknowledgment | Pending/mismatch/stale source; alumni read-only | 390, 360, 768 | J6 |
| `STU-005` | Student | Profile | Student Home | `NativeFilament` | PRD 04 projection | Official identity/program/curriculum/contact facts | Follow correction guidance | — | Missing/stale source; no direct official edit | 390, 360 | Support |
| `STU-006` | Student | Current and historical COR | Enrollment | `FocusedTALACustom` | PRD 04 | Immutable COR versions | Open or print selected version | COR | Superseded version or generation failure | 390, print | J4 |
| `REG-A01` | Registrar | Admissions | Registrar fixed entry | `NativeFilament` | PRD 02 | Applications and action queue | Open next application | — | Initial/filtered empty, stale queue | 1366 | J2 |
| `REG-A02` | Registrar | Applicant Record | Admissions | `NativeFilament` | PRD 02 | Application/evidence/decision history | Request correction or record decision | Decision/current Registrar clearance | Unauthorized, stale, superseding decision | 1366, 768 read-only | J2 |
| `REG-A03` | Registrar | Admission Cycles and readiness | Admissions | `NativeFilament` | PRD 02 | Admission Cycle public and correction boundaries and Requirement Set | Publish or extend valid cycle boundary | — | Failed readiness, invalid boundary, or competing publication | 1366 | J2 |
| `REG-C01` | Registrar | Catalog & Curricula | Registrar navigation | `FocusedTALACustom` | PRD 03 | Program, Course Revision, Curriculum Version, external competency requirement | Preview/import Draft authority or activate valid successor | Import findings CSV | File/header/row/authority/readiness blocker; no inferred completion treatment | 1366 | J3 |
| `REG-T01` | Registrar | Term Planning Overview & Calendar | Registrar navigation | `NativeFilament` | PRD 03 | Term Calendar Package, Examination Period, and planning readiness | Open first failed checkpoint | — | Missing/stale calendar authority; examination period unavailable | 1366 | J3 |
| `REG-T02` | Registrar | Sections & classes | Term Planning | `NativeFilament` | PRD 03 | Term Cohort and Class Offering | Add or correct offering | — | Missing Additional-offering authority | 1366 | J3 |
| `REG-T03` | Registrar | Faculty & rooms | Term Planning | `NativeFilament` | PRD 03 | Faculty, room, availability, meeting pattern | Resolve resource blocker | — | Missing/incompatible resource | 1366 | J3 |
| `REG-T04` | Registrar | Timetable - draft review | Term Planning | `FocusedTALACustom` | PRD 03 | Solver request/result and candidate | Generate or validate candidate | Candidate evidence | Infeasible, Unknown, ModelInvalid, TechnicalFailure | 1366 | J3 |
| `REG-T05` | Registrar | Candidate correction | Timetable | `NativeFilament` | PRD 03 | Candidate meeting version and whole-Term source candidate | Apply one-meeting adjustment or accept explicit minimal repair | Revalidated immutable candidate successor | Invalid replacement, rejected repair, or stale candidate | 1366 | J3 |
| `REG-T06` | Registrar | Timetable - official versions and revision | Term Planning | `FocusedTALACustom` | PRD 03 | Published Timetable Version | Publish or record affected revision | Published Timetable | Failed impact/readiness, stale publication, or visibly superseded output | 1366, 768 read-only, A4 landscape print | J3 |
| `REG-E01` | Registrar | Students & Enrollment | Registrar navigation | `NativeFilament` | PRD 04 | Registration Cases, readiness queues, and Ready Applicants | Open next actionable case, Start registration for Ready Applicant | — | Empty/stale/action unavailable | 1366 | J4 |
| `REG-E02` | Registrar | Enrollment case and proposal | Students & Enrollment | `NativeFilament` | PRD 04 | Registration Case, terminal/reopen history, and proposal versions | Reopen, prepare/revise, or finalize valid case | Reopened same case or Enrollment/COR result | Missing reopen authority, failed prerequisite, placement, assessment, or clearance | 1366 | J4 |
| `REG-E03` | Registrar | Shortage and timetable-impact context | Students & Enrollment | `NativeFilament` | PRD 04 | Placement/reservation and timetable impact | Resolve affected case | Updated learner projection | No valid class or expired reservation | 1366 | J4 |
| `REG-E04` | Registrar | Adjustment and Course Drop | Enrollment case | `NativeFilament` | PRD 04 | Authorized change and successor versions | Apply authorized change | Successor COR when applicable | Additional clearance or Accounting review pending | 1366 | J4 |
| `REG-G01` | Registrar | Grades & Completion | Registrar navigation | `NativeFilament` | PRD 05 | Roster/grading-teacher assignment, release/progress/completion queues | Open next actionable record | — | Empty/stale/unauthorized action | 1366 | J5 |
| `REG-G02` | Registrar | Grade release, INC, and correction detail | Grades & Completion | `NativeFilament` | PRD 05 | Final result, deadline/amendment, and correction chain | Release open completion, amend deadline, show retake guidance, or supersede result | Released result | Completion overdue, deadline/result race, stale result | 1366, 768 read-only | J5 |
| `REG-G03` | Registrar | Progress and lifecycle detail | Grades & Completion | `NativeFilament` | PRD 05 | Curriculum progress and lifecycle result | Record authorized decision | Updated projection | Missing source or consequential pending state | 1366 | J5 |
| `REG-G04` | Registrar | Completion and graduation recording | Grades & Completion | `NativeFilament` | PRD 05 | Full curriculum evaluation and actual authorized institutional result | Record graduation when eligible | Attributable graduation record | Missing completion requirement or actual authority; no separate four-state approval workflow | 1366 | J5 |
| `REG-G05` | Registrar | TOR preview and issuance history | Grades & Completion | `FocusedTALACustom` | PRD 05/06 | Transcript snapshot, TALA Standard TOR version, signatory data, clearance | Preview or issue | Issued TOR | Missing source/signatory or active output restriction; generation failure; void/replacement/supersession | 1366, print | J5 |
| `REG-G06` | Registrar | Verified external competency result | Grades & Completion | `NativeFilament` | PRD 03/05 | Active external requirement and external assessment/certification evidence | Record verified result | Updated curriculum evaluation | Missing/stale requirement or evidence; successor preserves prior attempt | 1366, 768 read-only | J5 |
| `ACC-001` | Accounting | Fee Plans | Accounting fixed entry | `NativeFilament` | PRD 06 | Fee Plan versions | Create draft or publish valid plan | — | Incomplete/reconciled/competing publication blocker | 1366 | J6 |
| `ACC-002` | Accounting | Fee Plan detail | Fee Plans | `NativeFilament` | PRD 06 | Selected Fee Plan version | Save draft or publish | Published immutable version | Stale or failed readiness | 1366 | J6 |
| `ACC-003` | Accounting | Student Accounts tabs | Accounting navigation | `NativeFilament` | PRD 06 | Account, exception, and output-restriction context | Open next actionable record or export current context | Account Status CSV; Verified Payments CSV from selected account context | Empty/stale/filter/export failure | 1366 | J4/J6 |
| `ACC-004` | Accounting | Student Account detail | Student Accounts | `NativeFilament` | PRD 06 | Term Account, Assessment obligations, Coverage, obligation-keyed postings | Record authorized account action | SOA | Assessment unavailable/stale; excess/unassigned or failed posting | 1366 | J4/J6 |
| `ACC-005` | Accounting | Authorized individual assessment | Account detail | `NativeFilament` | PRD 06 | Registration/change version and Accounting authority | Record exact assessment | Successor AssessmentVersion | Unauthorized, unreconciled, or stale source | 1366 | J4 |
| `ACC-006` | Accounting | Approved Coverage action | Account detail | `NativeFilament` | PRD 06 | Coverage authority and current obligation | Record valid coverage | Updated clearance projection | Excess, conflict, stale, unsupported authority | 1366 | J4 |
| `ACC-007` | Accounting | Payment Exception | Student Accounts | `NativeFilament` | PRD 06 | Evidence/attempt exception and named target obligations | Preview and verify actual obligation application or reject safely | Obligation-keyed payment result when verified | Excess target, mismatch, duplicate, reversal, no posting | 1366 | J6 |
| `ACC-008` | Accounting | TOR-scoped restriction | Student Account context | `NativeFilament` | PRD 06 | Current attributable output restriction and financial source | Record or resolve authorized restriction | Output action availability | Missing/stale source blocks only the affected output; no per-request approval | 1366 | J5 |
| `FAC-001` | Faculty | My Availability | Schedule & Availability sibling task and role-scoped search | `NativeFilament` | PRD 03 | Availability declaration | Submit declaration | — | Missing term request, stale assignment | 1366, 768 | J3 |
| `FAC-002` | Faculty | My Schedule | Schedule & Availability fixed entry and sibling navigation | `NativeFilament` | PRD 03 | Published Timetable and informational Examination Period projections | Review current/revised schedule | Faculty schedule | Affected revision, unavailable publication, or unavailable examination period | 1366, 768 | J3 |
| `FAC-003` | Faculty | Grade Rosters and detail | Faculty navigation | `NativeFilament` | PRD 04/05 | Current official roster and submitted result version | Submit results or use a contextual roster action | Submission result; current Class Roster print/CSV | Empty/incomplete/returned/stale roster; export failure; INC completion open/overdue | 1366, 768, A4 landscape operational print | J5 |
| `AHD-001` | Academic Head | Academic Oversight | Academic Head fixed entry | `NativeFilament` | PRD 03–05 projections | Source-owned calendar, external competency, and attention evidence | Open read-only source drill-in | — | No item, stale/missing source, no mutation | 1366, 768 | J3/J5 |
| `SYS-001` | System Administrator | Users & Access and account detail | System Administrator fixed entry | `NativeFilament` | PRD 01 | Accounts, roles, invitations, MFA state | Invite or apply authorized account action | Security notice | Final-administrator protection, invitation/MFA failure | 1366 | J1 |
| `SYS-002` | System Administrator | Public Content | System Administrator navigation | `NativeFilament` | PRD 01 | Versioned public content | Publish valid content | Public projection | Stale/scheduled/failed publication | 1366 | J1 |
| `SYS-003` | System Administrator | System Health | System Administrator navigation | `NativeFilament` | PRD 06/Architecture | Locally recorded service evidence | Refresh local evidence | — | Available/action needed/unavailable/not recently checked, with source, as-of time, owner, and safe next action | 1366, 768 | J7 |
| `SYS-004` | System Administrator | Governance & Audit | System Administrator navigation | `NativeFilament` | PRD 06/Architecture | Institutional changes, events, output access | Filter or inspect evidence | — | Inaccessible evidence; plain-language retention boundary with technical detail on disclosure | 1366, 768 | J7 |
| `OUT-001` | Applicant | Historical Application Acknowledgment print | Application history | `FocusedTALACustom` | PRD 02 | Historically issued submitted Application and Requirement Set versions | Open/print authorized retained artifact | Historical acknowledgment | Source/permission/generation failure never fabricates an artifact | A4 portrait print | J2 |
| `OUT-002` | Authenticated roles | Published Timetable print | Published Timetable | `FocusedTALACustom` | PRD 03 | Published Timetable Version | Print/save | Published Timetable | Superseded/unavailable version | A4 landscape print | J3 |
| `OUT-003` | Student/Registrar | COR print/history | Enrollment or case | `FocusedTALACustom` | PRD 04 | COR Version | Print/save | COR | Superseded version or generation failure | A4 portrait print | J4 |
| `OUT-004` | Student | Unofficial academic record | Academics | `FocusedTALACustom` | PRD 05 | Released academic-record projection as of generation | Print/save | Unofficial Student Record | Incomplete/stale source or generation failure | A4 portrait print | J5 |
| `OUT-005` | Registrar | TALA Standard TOR | TOR preview/history | `FocusedTALACustom` | PRD 05 | Transcript snapshot, template version, and issuance state | Preview, issue, or print authorized state | TOR | Preview/issued/voided/replaced/superseded/generation failure | A4 portrait print | J5 |
| `OUT-006` | Student/Accounting | Account Statement/SOA | Finance or Account detail | `FocusedTALACustom` | PRD 06 | Term Account as-of projection | Generate/print | SOA | Stale source or no partial artifact | A4 portrait print | J6 |
| `OUT-007` | Student/Accounting | Historical Payment Acknowledgment | Finance or Account history | `FocusedTALACustom` | PRD 06 | Historically issued verified-posting snapshot | Open authorized retained artifact | Historical payment acknowledgment | Reversed/superseded/generation failure remains explicit | A4 portrait print | J6 |

Routine empty, loading, and validation states use shared patterns instead of separate coverage entries. A dedicated entry is required only when a state changes meaning, permitted action, cross-role outcome, or official-output status.

### Implementation organization freedom

The implementation may combine related coverage entries in one source-owned workbench, tab set, contextual detail, dialog, or output route. Names must remain semantic and role-owned. Consolidation cannot remove a canonical navigation destination, hide a required state/action, or turn unrelated responsibilities into a generic dashboard, Reports, Settings, Approvals, or Readiness Center.

</details>

## Representative Journey and Acceptance Contract

<details>
<summary>Seven end-to-end scenarios and evidence conditions</summary>

The UI authority requires every primary destination to remain reachable and defines these seven end-to-end journeys for design review and later browser acceptance:

1. Public entry → registration/verification → sign-in → role selection → authorized entry or inaccessible recovery.
2. Application → submission/correction → decision → Registrar clearance → Ready for Enrollment.
3. Academic and curriculum authority, including a tracked-only external-competency requirement → calendar readiness and role-consistent Examination Period/unavailable state → timetable generation failure/correction → valid candidate → publication/revision.
4. Registration → proposal/placement → assessment/coverage/payment → official enrollment → Student activation → COR.
5. Grade submission/return/release → partial-term and INC completion-open/overdue/amendment/resolution branches → verified external competency `NotYetCompetent`/successor `Competent`/tracked-only absence → correction → completion → TALA Standard TOR issuance.
6. Fee Plan or exact assessment → evidence/PayMongo pending/mismatch → verified posting → SOA with payment acknowledgment → reversal.
7. System Health local evidence/Unknown state → Governance & Audit output access and the explicit no-automatic-disposal boundary.

Each journey identifies the starting persona and preconditions, authoritative source/version, action, visible evidence, one failure, correction path, cross-role result, output, and pass condition. It uses only canonical synthetic records and `example.test` identities. A design artifact may assist review but is never a product or implementation gate.

</details>

## Authority and evidence reference

<details>
<summary>Current contracts, historical evidence and implementation organization</summary>

This document owns required UI capabilities and interaction outcomes. Each delivery slice verifies its implementation and rendered behavior against these contracts.

The Canonical UI Surface Coverage Inventory identifies required user-visible capabilities and acceptance evidence. Related entries may share one workbench through tabs, selected-record panels, contextual actions, dialogs, outputs, or shared states when ownership and behavior remain explicit.

Impeccable leads task-specific hierarchy and composition, supported by the owning PRD, DESIGN.md, matching surface brief, relevant design skills and installed-version framework research. Content lists, arrows and workbench groups below identify required facts, priorities and task relationships. Compose their visual order and controls for the task through Laravel Boost, relevant official documentation, supported customization and demos. Business dependencies, consequential guards and fixed official-output formats retain their exact owning contracts. The inventory and contracts below specify required user outcomes. Use sketches when they help resolve a design question. Preserve official-output content, versioning and print quality.

Current application pages, schema-shaped resources, legacy screenshots, archived UI material, and visual alternatives remain implementation or presentation evidence. A later vertical slice may retain or consolidate a surface only after proving that its role, source record, action, state, and responsive/accessibility behavior conform to the owning PRD and this blueprint. No file-presence or visual similarity creates product authority.

The former live **Legacy Implementation and Comparison Inventory**, TAL-60 decisions, superseded finance decisions, module-to-UI implementation map, scheduling UI baseline, import/plugin notes, and future-slice template are preserved byte-for-byte as non-authoritative implementation history in [`archive/project-progress/TALA-UI-Legacy-Implementation-and-Comparison-Inventory-pre-prototype-2026-08-08.md`](./archive/project-progress/TALA-UI-Legacy-Implementation-and-Comparison-Inventory-pre-prototype-2026-08-08.md), SHA-256 `966107D9CE69EC3CA37CE4B3C6999010E78A84F2648C138E33E3264EA835F80E`.

That archive may support later bounded implementation-evidence inspection. It cannot add navigation, restore superseded behavior, define a canonical screen, route implementation work, or override the Panel and Navigation Map, shared UI authority, PRDs 01–06, or Architecture Specification.

</details>

<a id="archived-implementation-inventory--evidence-link-only"></a>
<a id="authenticated-shell-and-interaction-contract--october-2-2026"></a>
<a id="brand-mark-and-print-roles"></a>
<a id="canonical-ui-status-and-evidence-boundary"></a>
<a id="color-tokens"></a>
<a id="connected-term-planning-and-faculty-contracts"></a>
<a id="framework-implementation-responsibilities"></a>
<a id="http-and-session-recovery-presentation--october-2-2026"></a>
<a id="layout-and-motion-guidance"></a>
<a id="material-layers"></a>
<a id="october-2-client-feedback-outcomes-reconciled-october-3"></a>
<a id="panel-and-navigation-map"></a>
<a id="reusable-component-authority"></a>
<a id="typography-and-layout-guidance"></a>
<a id="whole-system-field-and-component-decisions--october-3"></a>

Shared colors/material/typography/motion are in [DESIGN.md](../DESIGN.md). Supported component methods, implementation dispositions and framework boundaries are in [Architecture](architecture_specification.md#ui-implementation-responsibilities). Current concern dispositions and history remain in the linked GitHub records.

<a id="connected-role-surfaces"></a>
<a id="connected-term-planning-composition"></a>
<a id="faculty-response-and-history-controls"></a>
<a id="faculty-task-and-output-contract"></a>
<a id="shared-controls-and-role-safe-inspection"></a>
<a id="working-frame-composition"></a>
