# TALA UI Surface Blueprint

## Purpose and Authority

This blueprint translates rewritten PRDs into exact product-interface authorities for the TALA MVP. Clinics 1–6, canonical consolidation, and the final cross-module contradiction and omission review are complete. The complete UI authority is approved for later implementation-task derivation; this does not authorize UI implementation.

Use this source order while defining every UI authority and later planning every approved UI slice:

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
| `/` | Public Gateway | Public visitors | Institution identity, application availability, Apply, contextual sign-in, bounded notices/FAQ, and official support/privacy/accessibility links |
| `/applicant` | Applicant Workspace | Verified Applicant accounts | Account entry and Clinic 2-owned application journey; existing accounts remain accessible while application entry is closed |
| `/student` | Student Hub | Accounts with persistent Student access | Historical and current authorized Student projections; current-term eligibility controls actions |
| `/admin` | Staff Workspace | Registrar, Accounting, Faculty, Academic Head, System Administrator | One active fixed-role context at a time, with role-scoped operational work |

MVP decisions:

1. Faculty remains inside `/admin` with role-scoped navigation and policies. TALA does not add a fourth panel for Faculty Workspace.
2. Registrar, Accounting, Academic Head, and System Administrator share `/admin`. Navigation visibility improves usability; policies and action authorization enforce access.
3. Applicant and Student surfaces remain separate projections over one credential account. Official enrollment grants persistent Student access; it does not create another account.
4. Authentication UI stays in the Filament panels. Laravel Fortify remains the backend authentication contract for login, registration, verification, password reset, and custom response handling where already integrated.
5. The public gateway may retain its isolated Bootstrap landing shell and established design language, but it is reduced to the approved task order in the Clinic 1 blueprint. Mission/vision essays, embedded map, galleries, programs marketing, and general CMS behavior are removed. Authenticated work remains Filament-first.
6. Filament resources, pages, tables, forms, infolists, actions, filters, widgets, and notifications are the default authenticated UI toolkit.
7. Core Filament components are used before custom Blade or a new plugin. A plugin is introduced only when a required PRD behavior cannot be delivered cleanly with installed components.
8. Auth Designer is retained only when native Filament verification, recovery, profile, email-change, MFA, responsive, and accessibility behavior still works. Applicant registration remains a focused custom page only if needed to enforce the approved minimal account-creation contract.

## Current Rebaseline State

File presence does not mean a workflow is accepted. The states below remain bounded read-only salvage classifications even after complete-authority approval; only a separately approved vertical task may act on them.

| State | Meaning | Required action |
| --- | --- | --- |
| Confirmed baseline | Recorded as completed in the local sync tracker and supported by focused tests | Classify as a retain candidate; later regression-test under an approved task |
| Local work awaiting baseline review | Present in the dirty worktree or local progress record but not recorded as a completed synced slice | Classify against the current PRD; later test, accept, or revise under an approved task |
| Reuse inventory | Existing model, resource, page, or test from earlier development | Audit behavior and authorization read-only before classifying |
| Required surface | Required by the PRD but not yet confirmed in the current implementation | Record the requirement; create only through a later approved vertical task |
| Deferred | Useful enhancement that is not required for MVP | Keep outside the approved product and later task scope |

### Clinic 1 reconciliation state

The current public/authentication shells, email authentication, verification, password recovery, three panels, Spatie authorization, policies, and FAQ foundation are **salvage candidates**, not confirmed product behavior. Clinic 1 classifies them as retain candidates; a future approved implementation task must prove conformance before retaining them. Username authentication, mixed account/application state, administrator-created passwords, silent role priority, editable role/permission UI, archive/restore, and one-role Staff forms are superseded by PRD 01.

Each module remains governed by its owning clinic. The complete authority set is approved, but implementation remains blocked until a journey-complete vertical task is separately planned and authorized. Historical tracker completion or file presence cannot approve a legacy product rule.

### Clinic 2 reconciliation state

The Applicant draft, private-upload, evidence-history, Registrar-policy, work-queue, exact-match warning, audit, and queued-mail foundations are salvage candidates. Clinic 2 supersedes the generic admissions calendar and policy engine, mixed checklist states and six blocking levels, returning-student application path, Mark for Evaluation, handover action, Student creation, and post-creation duplicate repair. A future approved vertical slice must map every consumer before retaining, simplifying, replacing, or removing any physical implementation.

### Clinic 3 reconciliation state

The immutable catalog/curriculum foundations, term records, Faculty/room sources, CP-SAT adapter, solver snapshots/statuses, Laravel candidate validation, candidate/published separation, revision evidence, queued schedule mail, and native Filament surfaces are salvage candidates. Clinic 3 supersedes the generic calendar store, Term Offering → Section → Delivery Group layering, equal-weight objective and editable constraint profiles, preferred times, assumed operating grid, technical run-first UI, unrestricted manual overrides, and separate split-module navigation. A future approved vertical slice must map every consumer before retaining, simplifying, replacing, or removing any physical implementation.

### Clinic 4 reconciliation state

Transactional placement/finalization, row locking, idempotency, conflict checks, finance-projection integration, authorization, COR rendering/logging, and native Filament foundations are salvage candidates. Clinic 4 supersedes the nine-gate workbench, standalone Study Plan, policy-driving Regular/Irregular status, learner-controlled course shopping, generic overrides, global holds, manually re-entered Term Offerings, ranked waitlists, universal overload/default-fee values, and live-ledger COR behavior. Existing physical consumers remain quarantined until a later approved vertical task maps them.

### Clinic 5 reconciliation state

Roster/result-event foundations, transaction locking, late-authority evidence, lifecycle history, completion snapshots, authorization, and native Filament surfaces are salvage candidates. Clinic 5 supersedes stored period grades and formula calculation, released `P`, mutable released grades, arbitrary GWA editing, learner what-if audits, generic academic-progress/lifecycle engines, manual graduation batches, transcript-template editing, and official-TOR Student self-download. Existing physical consumers remain quarantined until a later approved vertical task maps them.

### Clinic 6 reconciliation state

Append-only assessment/account events, private evidence and output access, signed webhook verification, provider idempotency, policies, and authenticated print foundations are salvage candidates. Clinic 6 supersedes generic Fee Rules and precedence, the silent 20% fallback, Enrollment/StudentProfile-only account ownership, immediate trust of uploaded evidence, Billing Slip, Official Receipt mapping, prior-debt allocation, generic accommodation/hold behavior, the broad report catalog, automatic disposal UI, and provider-control operations. Existing physical consumers remain quarantined until a later approved vertical task maps them.

### Local work awaiting baseline review

No additional UI/auth work is accepted merely because a file exists. During the clinics, resource families remain reuse inventory until the owning module's bounded read-only reconciliation classifies the model, migration, policy, panel registration, and tests against the rewritten PRD. A future approved task must prove any retained implementation.

### Reuse inventory

The staff panel already contains resources across admissions, academic setup, offerings, scheduling, enrollment, finance, COR, grades, imports, users, roles, settings, FAQ, and activity logs. Each module clinic must inspect its relevant resource, model, service, policy, migration, and test read-only before classifying it. A later approved vertical task must prove the final retention decision.

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

## Clinic 1 — Identity, Access, and Public Entry UI Authority

**Status:** Approved on 2026-08-06. This section supersedes conflicting identity/public/access presentation in later legacy sections of this file.

### Page inventory and exact presentation

| Surface | Owner and entry | Information order | Actions and controls | States, permissions, and mobile |
|---|---|---|---|---|
| Public Gateway | Public; root route | Institution identity and short TALA explanation → application availability → Apply and Sign in → accessible Applicant/Student/Staff sign-in menu → Access TALA cards → current notices → FAQ → support/privacy/accessibility/external institution and map links | **Apply** when open; **Sign in** menu; role-context cards repeat the same destinations | Closed application entry replaces Apply with a clear closed state but never removes existing Applicant sign-in. Cards stack on mobile. Menu supports hover, focus, click, tap, and keyboard. |
| Applicant Registration | Public; Apply while entry is open | Applicant account context → email → password/passphrase → confirmation → privacy-notice acknowledgement/link → concise verification expectation | **Create account**; Sign in; Choose another workspace | No name, LRN, application, program, document, or Student field. Closed entry fails safely. Duplicate email does not reveal account details. Single column on mobile. |
| Contextual Sign In | Public; Applicant, Student, or Staff entry | Visible selected context → verified email → password → Remember device only for Applicant/Student-only use → recovery link → workspace-choice link | **Sign in**; Forgot password; Choose another workspace | Invalid/unknown credentials remain generic. Correct credentials in wrong context authenticate once and reroute with explanation. Staff entry omits Remember me. |
| Verification / Activation | Link or post-registration/invitation state | What must be verified/activated → target email (safely shown) → expiry or resend guidance → support | **Verify** or **Set password and activate**; Resend; Return to sign in | Expired, used, malformed, throttled, dispatch-failed, and already-complete states each provide one valid recovery action. Staff activation continues directly to mandatory MFA setup. |
| Password Recovery / Reset | Sign-in recovery link | Neutral instruction → email request; then new passphrase and confirmation on valid link | **Send recovery link** / **Reset password** | Request result never confirms whether an account exists. Reset applies the 15–64-character policy and ends other applicable sessions. |
| Staff MFA Setup / Challenge | After Staff activation or Staff-capable sign-in | Setup purpose → authenticator QR/manual key → TOTP confirmation → one-time recovery codes and storage acknowledgement; later challenge shows TOTP and recovery-code alternative | **Enable MFA** / **Verify**; Use recovery code; Regenerate codes from Account Security | Secrets and codes are never returned to tables or audit. No email bypass. Narrow layout remains one column. |
| Workspace Chooser | After valid sign-in when more than one context is authorized | Short explanation → compact authorized-context cards → account-security/sign-out links | **Open workspace** on each card | Single-role accounts bypass it. No unavailable roles, counts, previews, or analytics. Cards stack and preserve keyboard order. |
| Account Security | Account menu in any workspace | Verified sign-in email → email-change ownership/explanation → password → MFA and recovery codes when Staff-capable → read-only role contexts → minimal Staff access identity when applicable → active-session security guidance | Change email where allowed; Change password; Set up/verify/reset own MFA methods; Regenerate recovery codes; Switch workspace | Applicant/Student-only email change is self-service with new-address verification and old-address alert. Staff-capable email is administrator-controlled. Sensitive actions require current-password confirmation. |
| Users & Access | System Administrator; Staff navigation | Title/purpose → small readiness warning only when access administration is blocked → table/search/filter bar → active-filter indicators → result count → accounts | Header **Invite Staff**; row Action Group: View, resend invitation/verification, send recovery link, change Staff access, disable/reactivate, reset Staff MFA | Columns: displayed name, verified email, authorized workspaces, derived state, invitation/verification, last successful sign-in, created. Search name/email/linked identifiers. Native role, state, verification, created-date, and last-sign-in-date filters. Secondary columns collapse on mobile. |
| Account Detail | Users & Access row View | Account state and next action → Staff profile → role contexts → linked Applicant/Student profiles → security facts → high-value audit history | One state-appropriate primary action; secondary actions in Action Group | Read-only Infolist. No password, delete, archive, arbitrary role creation, permission editing, or academic/finance action. Internal disable reason is visible only to authorized administration, never to the disabled user. |
| Invite Staff | Users & Access header action | Email → existing-account match result when authorized → name parts → optional Staff identifier → fixed Staff roles → reason → authority → optional evidence reference → invitation/access-change summary | **Send invitation** for a new account or **Add Staff access** for an existing verified account; Cancel | No password field. A verified existing account is reused and is not sent through password activation again. Prevent duplicate account creation and final-admin hazards. Form becomes single column on mobile. |
| Change Staff Access / Disable / MFA Reset | Account detail focused actions | Current access/security state → exact proposed change → required reason and authority → optional evidence → irreversible/security effect summary | **Save access change**, **Disable account**, **Reactivate**, or **Reset MFA** with explicit confirmation | Only System Administrator; self-disable and final-admin removal rejected. MFA reset states that external identity verification must already be complete. |
| Public Content | System Administrator; Staff navigation | Tabs: Notices and FAQ → native tables → publication state/window/order → concise preview | Add/edit/publish/unpublish/reorder; safe optional link; keyboard and single-pointer Move up/Move down alternative for any drag reorder | No page builder, uploads, rich layout, gallery, program marketing, embedded map, or reviewer workflow. Tables stack on mobile. |
| Failure / Inaccessible State | Any failed browser route or action | TALA/context identity → plain-language status → what happened without sensitive detail → one recovery step → support when relevant | Return to authorized workspace, sign in again, retry later, or contact support | Covers 403, 404, 419, 429, unexpected error, temporary unavailable, disabled account, and inaccessible record. Never exposes record existence, internal exceptions, or disable reason. |

### Low-fidelity wireframes

#### Public Gateway

```text
┌──────────────────────────────────────────────────────────────┐
│ SERVITECH / TALA                         [Sign in ▾]          │
├──────────────────────────────────────────────────────────────┤
│ TALA: secure access to application and school records        │
│ Applications: OPEN until [date]     [Apply] [Sign in]        │
├──────────────────────────────────────────────────────────────┤
│ Access TALA                                                 │
│ [Applicant]             [Student]             [Staff]        │
├──────────────────────────────────────────────────────────────┤
│ Current notices                                              │
│ FAQ                                                          │
├──────────────────────────────────────────────────────────────┤
│ Support · Privacy · Accessibility · Institution · Map link   │
└──────────────────────────────────────────────────────────────┘
```

#### Authentication family

```text
┌──────────────────────────────────────┐
│ TALA · [Applicant | Student | Staff] │
│ [Page purpose / safe state message]  │
│ Email                                │
│ Password / passphrase                │
│ [Context-specific fields only]       │
│ [Primary action]                     │
│ Recovery or resend link              │
│ Choose another workspace             │
└──────────────────────────────────────┘
```

Registration, verification, activation, recovery/reset, and MFA use this same single-column shell. They change only the fields and recovery action required by their state.

#### Workspace Chooser

```text
┌──────────────────────────────────────────────┐
│ Choose a workspace                           │
│ Use one authorized role context at a time.   │
│ [Registrar       Open workspace]             │
│ [Faculty         Open workspace]             │
│ [Student         Open workspace]             │
│ Account Security                 Sign out     │
└──────────────────────────────────────────────┘
```

#### Account Security

```text
┌────────────────────────────────────────────────────┐
│ Account Security                                   │
│ Sign-in email        [verified] [Change / managed] │
│ Password                         [Change password] │
│ Staff MFA            [enabled]  [Manage]           │
│ Recovery codes                  [Regenerate]       │
│ Authorized contexts             [read-only list]   │
│ Staff access identity           [read-only]        │
└────────────────────────────────────────────────────┘
```

#### Users & Access

```text
┌────────────────────────────────────────────────────────────────────┐
│ Users & Access                                  [Invite Staff]     │
│ Search [________________]  [Filters]  Active: Staff, Active        │
├───────────────┬────────────────┬───────────┬─────────┬─────────────┤
│ Name / Email  │ Workspaces     │ State     │ Verified│ Last sign-in│
├───────────────┼────────────────┼───────────┼─────────┼─────────────┤
│ …             │ …              │ …         │ …       │ [Actions ▾]│
└───────────────┴────────────────┴───────────┴─────────┴─────────────┘
```

#### Account Detail and focused access actions

```text
┌──────────────────────────────────────────────────────┐
│ Account: [display name]             [Primary action] │
│ State / next action                                  │
│ Staff access profile                                 │
│ Authorized role contexts                             │
│ Linked Applicant / Student records                   │
│ Security facts                                       │
│ High-value audit history                             │
│                                      [More actions ▾]│
└──────────────────────────────────────────────────────┘
```

Invite Staff, Change Staff Access, Disable/Reactivate, and Reset MFA open focused native actions over this workbench; they do not become separate navigation products.

#### Public Content

```text
┌──────────────────────────────────────────────────────┐
│ Public Content                                      │
│ [Notices] [FAQ]                     [Add notice/FAQ] │
│ Search / native filters / active indicators         │
│ Title or question · State/window · Order · Actions  │
└──────────────────────────────────────────────────────┘
```

#### Failure / inaccessible state

```text
┌──────────────────────────────────────┐
│ TALA                                │
│ [Plain-language status]             │
│ [Safe explanation without details]  │
│ [One primary recovery action]       │
│ Official support                    │
└──────────────────────────────────────┘
```

### Accepted layout comparison

| Accepted layout | Alternative considered | Decision basis |
|---|---|---|
| Task-focused Public Gateway | Marketing site or general CMS home | The first question is whether to apply or sign in; bounded notices/FAQ support that task without creating a publishing product. |
| Focused Users & Access workbench plus Account Detail | Combined-role dashboard, role builder, or generic Settings | Access work is queue-and-record based, fixed-role, auditable, and distinct from academic or financial administration. |
| Shared single-column authentication family | Separate bespoke screens or an authentication Wizard | Registration, verification, recovery, activation, and MFA differ by state but share orientation, safe recovery, keyboard order, and narrow-screen behavior. |

### Default ordering and page-specific states

**Deterministic ordering:** Users & Access sorts action-needed states (`InvitationPending`, `VerificationRequired`, and disabled accounts requiring authorized review) first, then remaining accounts by displayed name and stable account reference. Public Content groups currently published, scheduled, then unpublished/expired items and uses explicit notice or FAQ order within each group.

| Surface or shared family | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Public Gateway | No current notice/FAQ is stated plainly without hiding Apply/sign-in | Availability and content reserve labelled space and expose a non-blocking loading state | Content refresh failure preserves safe static entry/support; Apply fails closed if the admissions source is stale | Closed applications explain the date/support path; public service failure keeps institution identity and one recovery action |
| Authentication family | Not applicable; each state always has its required form or completion message | Primary action becomes busy once, retains entered non-secret values, and prevents duplicate submit | Expired/used/malformed link, validation error, throttling, or dispatch failure names one valid recovery; secrets are cleared as needed | Disabled or unauthorized context reveals no internal reason or role inventory and provides official support/chooser recovery |
| Workspace Chooser | Zero authorized contexts is an access-support state, not an empty dashboard | Contexts load as labelled placeholders without shifting focus order | A revoked context fails authorization, refreshes the list, and explains the safe next step | Only authorized contexts are rendered; direct routes return the shared inaccessible state |
| Account Security | Inapplicable sections, such as MFA for learner-only accounts, are omitted with ownership guidance where needed | Each focused action owns its own progress state | Concurrency or verification expiry preserves current live email/security state and requires refresh/retry | Cross-account access is forbidden; Staff-managed email explains the authorized owner |
| Users & Access | No accounts explains deployment/authorization owner without offering unsafe bootstrap; no filter matches offers **Clear filters** | Table skeleton preserves column labels and disables row actions | Stale state-changing action closes safely, refreshes the account, and shows no success | Unauthorized users receive shared 403 behavior; mail/integration outage shows a bounded readiness warning |
| Account Detail / focused actions | Missing optional profile/history sections say **No recorded evidence** rather than implying success | Infolist and action progress are separately labelled | State/version conflict shows current state and requires reconfirmation; failed mail never reverses access action | Direct unauthorized or nonexistent record routes do not distinguish existence |
| Public Content | First-record guidance appears only for authorized administration; filtered empty offers **Clear filters** | Tables and preview expose labelled loading state | Stale order/publish action refreshes current position/state; unsafe link validation stays field-specific | Public sees no draft; unauthorized administration receives shared inaccessible behavior |

The grouped authentication-family wireframe explicitly covers registration, verification, activation, password recovery/reset, and MFA because they use the same accepted shell and vary only by state-specific fields/actions. Every other primary Clinic 1 page has a direct wireframe above.

### Cross-role consistency

The same account state, verified email, role assignment, effective time, and next action project across authentication, Account Security, and Users & Access. Role-specific pages never copy or rename those facts. Applicant/Student users see their own safe projection; System Administrator sees the administrative evidence permitted for access work.

### Native component decision

- Public Gateway: existing isolated public Blade shell, simplified to the approved order.
- Auth, verification, recovery, profile, and MFA: native Filament/Fortify capabilities inside the retained branded shell when compatibility passes.
- Chooser and Account Security: focused Filament Pages composed from native Sections, Infolists, Forms, and Actions.
- Users & Access and Public Content: Filament Resources/Tables with native search, filters, active indicators, Infolists, and Action Groups.
- No permissions plugin, CMS plugin, dashboard plugin, saved-filter plugin, or custom column-filter component is justified for Clinic 1.

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

The following is the final primary-navigation authority. A registered route or legacy resource does not become a primary destination merely because it exists. Account Security is contextual in the account menu. Readiness is shown inside the action that consumes it and is not a separate destination.

| Context | Primary navigation | Contextual/shared destinations |
|---|---|---|
| Public | Gateway | Apply when Clinic 2 entry is open; contextual sign-in; notices/FAQ; support/privacy/accessibility |
| Applicant | Home; Application | Requirements and acknowledgment from the owning application; Account Security in account menu |
| Student | Home; Enrollment; Academics; Finance; Profile | COR, schedules, history, unofficial/finance outputs; Account Security in account menu |
| Registrar | Admissions; Catalog & Curricula; Term Planning; Students & Enrollment; Grades & Completion | Source records, official outputs, and history from the owning workbench |
| Accounting | Fee Plans; Student Accounts | Accounts, Payment Exceptions, and TOR Clearance tabs; contextual exports and outputs |
| Faculty | My Availability; My Schedule; Grade Rosters | Own assignment, declaration, roster, and history details |
| Academic Head | Academic Oversight | Read-only source-owned catalog/curriculum, timetable, grade/progress, lifecycle, and completion evidence |
| System Administrator | Users & Access; Public Content; System Health; Governance & Audit | Account detail, locally evidenced diagnostics, and read-only audit/history |

There is no primary Reports, Settings, Approvals, Readiness Center, duplicate integration-status, notification-center, or report-hub destination. Navigation visibility is never authorization.

### Student Home — shared projection

Student Home is a source-owned priority-status page, not a card dashboard or global-hold summary. It shows, in order: Student identity and current term; the single highest-priority safe action; current Enrollment, Academics, and Finance summaries with source owner/as-of time; upcoming accepted deadline or obligation; and contextual links. It never merges domain state or invents a universal learner status.

```text
┌ Student Home · 2026 T1 ───────────────────────────────────┐
│ Lea Cruz · SIA-2026-0008                  [Account menu]   │
│ ACTION NEEDED                                              │
│ Complete enrollment confirmation · Registrar · due 12 Jun │
│ [Open Enrollment]                                          │
│                                                           │
│ Enrollment   Waiting for confirmation · as of 10:30       │
│ Academics    Record current through 2025 T2               │
│ Finance      ₱0 due now · next ₱18,000 on 15 Aug          │
│                                                           │
│ Each status links to its owning page and evidence.        │
└───────────────────────────────────────────────────────────┘
```

If no action is needed, the page says so for the selected term and retains the three source summaries. Loading preserves headings. A stale source is labelled and cannot offer its mutation action. An inaccessible source reveals neither record existence nor detail.

### Student Profile — shared official identity projection

Profile is read-only. It shows legal name, Student number, program, assigned Curriculum Version, entry/admission reference, lifecycle projection, approved contact facts, source/last correction, and plain correction guidance. Email/password/MFA changes link to Clinic 1 Account Security. Students cannot directly edit official identity, program, curriculum, entry, lifecycle, enrollment, grade, or finance facts.

```text
┌ Profile ──────────────────────────────────────────────────┐
│ Official Student information · Registrar-owned            │
│ Legal name          Lea Marie Cruz                         │
│ Student number      SIA-2026-0008                          │
│ Program/Curriculum  BSIT · CUR-BSIT-2026                  │
│ Entry               2026 T1 · APP-2026-0001               │
│ Lifecycle           Active                                 │
│ Contact             lea.cruz@example.test · 09•••••••21   │
│ Last correction     None                                   │
│                                                           │
│ Need an official correction? Contact Registrar with       │
│ supporting authority. [Open Account Security]             │
└───────────────────────────────────────────────────────────┘
```

Registrar reaches the same source from Students & Enrollment and receives a focused **Record authorized correction** action with reason, authority/evidence, effective time, impact preview, and named confirmation. A successful correction updates future projections but never rewrites an issued COR/TOR snapshot.

### Academic Oversight — shared read-only projection

Academic Oversight orients the Academic Head without granting universal approval authority. It groups source-owned readiness and exceptions under Academic Authority, Term Planning, Grades & Progress, and Lifecycle & Completion. Each row shows state, owner, source/version, as-of time, and a read-only link to the owning workbench.

```text
┌ Academic Oversight ───────────────────────────────────────┐
│ Term [2026 T1▼] Program [All▼]        [3 need attention] │
│ AREA                 STATE          OWNER       AS OF     │
│ Curriculum authority Ready          Registrar   09:40     │
│ Timetable revision   Needs review   Registrar   10:05     │
│ Grade release        2 overdue      Registrar   10:20     │
│ Completion evidence  1 awaiting     Registrar   10:25     │
│                                                           │
│ [Open source read-only] · no approve/publish/edit action  │
└───────────────────────────────────────────────────────────┘
```

Empty means no source-owned item needs oversight under the current filters; it does not imply that records do not exist. Missing or stale sources are named as unavailable and never converted into a manually checked readiness result.

## Clinic 2 — Application, Admission Decision, and Enrollment Readiness UI Authority

**Status:** Approved on 2026-08-06.

Clinic 2 uses the same authoritative application across Applicant and Registrar projections. It ends at derived enrollment readiness and never creates a Student identity, registration, placement, assessment, or enrollment. The detailed behavior and data contract live in [PRD 02](./prd_modules/02_application_admission_decision_enrollment_readiness.md).

### Applicant Workspace page inventory

| Navigation item | Surface | Primary component |
| --- | --- | --- |
| Home | Reference, state, owner, deadline, one next action, scope, two readiness summaries, what happens next, and history | Custom Filament Page with vertically ordered Sections and one primary Action |
| Application | Draft, validate, submit, or correct only authorized application facts and preliminary evidence | Custom Filament Page with native five-step Wizard: Application Choice, Identity and Contact, Prior Education, Preliminary Evidence, Review and Submit |
| Requirements | Preliminary digital review and official-credential verification | Contextual Page with two grouped, mobile-stacked Tables and only state-permitted Actions |
| Application acknowledgment | Submitted snapshot, stable reference, requirement list, and physical-submission instructions | Authenticated printable read-only view; never an admission certificate or proof of enrollment |

`Requirements` is reached from Home or the current or historical Application. Account Security remains in the account menu and is not an admissions page.

The Public Gateway and Applicant Workspace read the derived state of a published `AdmissionCycle`. When no cycle is open, **Apply** becomes a clear applications-closed state while Applicant sign-in remains available. New applications and first submissions fail closed. Drafts become read-only until authorized extension or reopening; existing review, scoped correction, decisions, and credential verification continue.

Home always leads with reference, state, responsible party, nearest deadline, one plain-language next action, and one primary button. It then shows cycle/program/path, preliminary readiness, official-credential readiness, **What happens next**, and history. It is not a card dashboard or a complete process timeline. Empty, loading, error, inaccessible, and stale-action states identify what happened and the safe next action.

The Wizard exposes visible **Save draft**, step-level validation, a server-side closing-time recheck, an accessible error summary with field associations, and a single-column mobile layout. It collects only PRD 02's approved application, identity/contact, prior-education, declaration, and preliminary-evidence fields. Guardian name, relationship, and mobile appear only when the applicant is under 18. It does not collect modality, preferred time, complete address, civil status, or complete Student demographics.

Requirements renders the immutable requirement-set version attached to the application. Its two groups are **Preliminary digital review** and **Official credential verification**. Every row shows requirement, purpose, due stage, submission method, applicant-safe result, last update, Registrar instruction, deadline, and only the permitted action. `AcceptedAsPreliminaryEvidence` is written in full; it is never shortened to **Accepted**. Physical resubmission is not uploaded unless the published requirement separately permits a preliminary digital replacement.

After official enrollment, Registrar and Clinic 2 continue to own requirements marked `PostEnrollmentFollowUp`. Clinic 4 and the Student projection may show their safe reference and next instruction, but they do not present those items as failed enrollment checkpoints or decide their credential result.

The same account may have one application per Admission Cycle. Earlier submitted, withdrawn, or decided applications remain read-only history. After submission, only fields or files named by the active correction request reopen; correcting them returns the application to `Submitted`. The prior snapshot, evidence versions, request, and review remain visible in authorized history.

**Discard draft** removes an unsubmitted draft and its temporary uploads. An applicant may self-withdraw a submitted, action-needed, or admitted application until Clinic 4 starts registration; confirmation is required and reason is optional. Registrar-recorded offline withdrawal requires reason and authority. Authorized reopening preserves the same reference and history.

### Registrar Admissions workbench

One primary **Admissions** entry uses a native Filament Table with operational-count tabs: **Needs review**, **Waiting for applicant**, **Official credentials**, **Ready for enrollment**, and **History**.

| Contract | Exact presentation |
| --- | --- |
| Columns | Applicant/reference; program/cycle; plain-language state; responsible party/next action; preliminary readiness; official-credential readiness; nearest deadline; last activity |
| Search | Application reference, legal name, verified email, and exact authorized LRN search without displaying LRN in the list |
| Filters | Cycle, program, path, state, submitted date/time range, last-activity date/time range, and deadline/overdue state |
| Analytics | Small tab counts only; no chart dashboard, scoring, forecasting, or ranking |
| Actions | One state-appropriate primary record Action; secondary actions in an Action Group; no bulk Admit, verification, or withdrawal |

Use Filament's filter panel and active-filter indicators, not custom column-header dropdowns.

The Applicant Record uses this reading order: state/owner/next action; private identity-match warning; application scope and minimum applicant facts; preliminary evidence; current and superseded decisions; official credentials when admitted; collapsed activity, email, and technical evidence.

Contextual Registrar pages provide the Admission Cycle list/readiness, draft cycle form, immutable requirement-set review, and authorized publish, extend, close, cancel, or replacement-version actions. They are reached from Admissions and do not form a generic Settings area.

### Clinic 2 low-fidelity wireframes

```text
Applicant Home
┌ Reference · state · owner · deadline ┐
│ What you need to do next      [Action]│
├ Cycle / program / path                ┤
├ Preliminary evidence summary          ┤
├ Official credentials summary          ┤
├ What happens next                     ┤
└ Application history                   ┘
```

```text
Registrar Admissions
┌ Admissions                    [New cycle] ┐
│ Needs review | Waiting | Credentials | Ready | History │
│ Search                         [Filters]   │
├ Applicant/reference | Program/cycle       ┤
│ State | Owner/next action | Readiness      │
│ Deadline | Last activity       [View]      │
└ Filter indicators / empty or error state  ┘
```

```text
Applicant Record
┌ State · owner · next action     [Primary] ┐
├ Identity-match warning, when present       ┤
├ Scope and minimum applicant facts          ┤
├ Preliminary evidence review                ┤
├ Decision and superseding history           ┤
├ Official credentials, when admitted        ┤
└ Activity / email / technical evidence      ┘
```

```text
Application Wizard
┌ Reference / Draft · Cycle closing time            [Save draft] ┐
│ 1 Choice → 2 Identity → 3 Education → 4 Evidence → 5 Review   │
├ Current step fields · instructions · inline errors             ┤
├ Uploaded evidence versions / replacement action                ┤
└ [Back]                                      [Continue / Submit] │
```

```text
Requirements
┌ Reference · readiness · owner · next deadline                  ┐
├ Preliminary digital review                                     ┤
│ Requirement | Purpose | Result | Instruction | Due | Action    │
├ Official credential verification                               ┤
│ Requirement | Receipt/review/result | Instruction | Due        │
└ Historical/replaced evidence (collapsed)                       ┘
```

```text
Application acknowledgment — printable
┌ Institution · Application acknowledgment · Reference           ┐
├ Submitted applicant/cycle/program/path facts                   ┤
├ Submitted requirement list and evidence receipt summary        ┤
├ Physical/official credential instructions                      ┤
└ Submitted time · This is not admission or enrollment proof     ┘
```

```text
Admission Cycle and readiness
┌ Cycle · target term · Draft/Published/Cancelled      [Primary]  ┐
├ Dates · paths · programs · owner · authority                    ┤
├ Failed checks: source → owner → reason → recovery               ┤
├ Published immutable requirement versions                       ┤
└ Publication/change/cancellation history                         ┘
```

### Accepted layout comparison

| Accepted layout | Alternative considered | Decision basis |
|---|---|---|
| Five-step Application Wizard | One long application form | The bounded steps align to user concepts, support partial drafts and mobile reading order, and keep Review and Submit explicit. |
| Applicant Home guided status page | Card dashboard or complete process timeline | Applicant work is one current application with one next action, two readiness summaries, and history—not analytics. |
| Single Admissions queue with operational tabs | Multiple status resources or generic Settings maze | Registrar needs one accountable queue and contextual cycle/requirement setup, not duplicated records or peer navigation. |

### Default ordering and page-specific states

Admissions queues sort overdue/action-needed items first, then nearest deadline, then oldest last activity and stable reference. Application history sorts newest authoritative event first. Requirements sort by due stage and the published requirement order. Admission Cycles group currently effective/published first, then sort by nearest opening or closing date and stable code.

| Surface | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Applicant Home | No application offers **Start application** only when a cycle is open; historical-only state explains the next valid entry | Status and readiness sections retain labelled structure | Changed decision/correction/readiness refreshes before action and preserves history | Other applicants' references and unsupported paths are inaccessible without disclosure |
| Application Wizard | No Draft is created until start is valid; optional step sections explain applicability | Save/step/submit progress is explicit and duplicate submit disabled | Closing-time race, stale snapshot, or field/evidence validation retains safe Draft data and focuses the error summary | Closed cycle makes Draft read-only; unauthorized record route is generic inaccessible |
| Requirements | No applicable rows states the retained requirement version and owner; filtered empty offers **Clear filters** | Groups and row labels remain available | Replaced evidence or result refreshes the row before action; upload failure preserves prior version | Private files require authorized download and never expose storage paths |
| Acknowledgment | Unavailable before first submission; explanation links back to Application | Print view reports generation/loading without showing a false document | Superseded snapshot remains historical and clearly versioned | Only the owning Applicant and authorized Registrar may view it |
| Admissions queue | First-use and no-filter-match states are distinct; the latter clears filters | Table/tab counts report loading separately | Stale row action refreshes record and rejects out-of-order decision | Unauthorized roles have no navigation or record disclosure |
| Applicant Record | Optional evidence/credential sections state **Not yet applicable** | Ordered Sections load without changing action position | Stale correction/decision/credential action shows current authoritative state | Direct unauthorized record route is indistinguishable from unavailable record |
| Admission Cycle/readiness | No cycle offers bounded **New cycle**; no requirement version remains a blocker, never false success | Failed-first checks retain source/owner placeholders | Publish/date/cancel conflict requires refresh and reconfirmation | Storage/mail outage is attributed to System Administration; storage unavailability blocks publication |

These direct wireframes cover Applicant Home, the five-step Application Wizard, Requirements, the printable acknowledgment, Admissions, Applicant Record, and Admission Cycle/readiness setup. Focused actions reuse their owning record/workbench rather than becoming unlisted pages.

Applicant sees only their safe projection. Registrar owns personal review and decisions. Academic Head has aggregate counts only when authorized and no personal application access by default. Accounting, Faculty, and System Administrator receive no admissions-decision authority. After official enrollment, Applicant disappears from the normal workspace chooser while the application remains retained as Registrar evidence.

On mobile, the Wizard remains single-column, table rows collapse secondary fields into labelled detail, filters use the native panel, and row actions remain in an Action Group. All states require visible focus, labelled controls, screen-reader status text, and interaction that does not rely on color or pointer use.

## Clinic 3 — Academic Setup, Offerings, and Published Timetable UI Authority

**Status:** Approved on 2026-08-06.

Clinic 3 presents one connected journey from recorded academic authority to the official published timetable. The detailed behavior and conceptual contracts live in [PRD 03](./prd_modules/03_academic_setup_offerings_published_timetable.md). The legacy Academic Setup, Term Offerings, and CP-SAT UI descriptions below are reuse evidence only.

### Navigation and page inventory

Registrar receives two primary entries rather than a resource-by-resource setup maze:

| Navigation item | Purpose | Primary component |
| --- | --- | --- |
| Catalog & Curricula | Record program authority, maintain Course Revisions, build the grouped Curriculum Version, resolve import findings, and activate the externally approved version | Connected Filament workbench using Tables, Sections, Forms, Infolists, Actions, and one bounded CSV preview/import |
| Term Planning | Prepare the selected term, cohorts/classes, resources, candidate, publication, and revision in operating order | One selected-term Filament workbench with five Tabs and contextual source-record Actions |

Faculty receives **My Availability** and **My Schedule**. Academic Head receives read-only entry to Catalog & Curricula and Term Planning. System Administrator receives only locally evidenced solver status through Clinic 6 System Health. Student receives no Clinic 3 navigation; Clinic 4 projects the assigned official schedule after enrollment.

### Catalog & Curricula workbench

The workbench reading order is:

1. Program identity, authority, effective dates, status, and approved curriculum source.
2. Course catalog and current immutable revisions.
3. One Curriculum Version sheet grouped by curriculum year and term.
4. Draft CSV preview with errors, warnings, and source comparison.
5. Activation readiness, authority evidence, and one state-appropriate primary action.

The grouped sheet shows course code/title, units, prerequisites/corequisites, scheduling treatment, weekly meeting pattern, modes, room needs, source, and readiness. Draft rows may be edited inline or through a focused form. Active and historically used rows are read-only. An externally arranged practicum is visibly labelled **Externally arranged — no recurring master-timetable meeting**.

Filters use the native Filament filter panel with active indicators: program, curriculum intake/version, curriculum year, term placement, course state, scheduling treatment, and readiness. Search covers program, course code, and course title. Blocking import findings link to the exact Draft source row; import never activates or overwrites authority records.

### Term Planning workbench

The selected-term header always shows term identity, state, current readiness, governing authority, current published version, and exactly one state-appropriate primary action. Context is selected before actions; no global action silently operates on an implicit term.

#### Overview

Show official dates, typed operational windows, weekly teaching grid, recurring breaks, dated exceptions, authority evidence, and failed-first readiness. The neutral `Enrollment` window displays its approved dates; Clinic 4 displays and applies its bounded learner applicability. A successful check collapses to **All required checks passed**. Date-less grid times are institutional Asia/Manila wall-clock values; timestamps retain their actual date/time meaning.

#### Cohorts & Classes

| Record | Columns |
| --- | --- |
| Term Cohort | Code, program, curriculum/version period, forecast count, confirmed count, curriculum coverage, and state; the cohort is not a Regular/Irregular Student classification |
| Class Offering | Reference, course, linked cohorts, `Regular`/`Additional` source, expected count, capacity, meeting pattern, modes, readiness, state, and last change; `Regular` is an offering-source label only |

Native filters are program, cohort, course, source, shared/single cohort, state, readiness, and capacity condition. The workbench may generate Draft rows from active curricula, confirmed standard-curriculum cohorts, forecasts, and Clinic 4 aggregate unmet-demand evidence. Registrar confirms, splits, shares, adds, or cancels those drafts; CP-SAT never creates or merges them. Sharing presents linked cohorts explicitly so a shared class cannot be mistaken for room sharing. Cancellation actions show the publication and Clinic 4 placement impact before confirmation.

#### Teaching Resources

Faculty rows show declaration state, teaching eligibility, term load/preparation capacity, assigned demand, deadline, and blockers. Room rows show capacity, type, flat features, availability, planned demand, and blockers. Contextual actions record corrections or bounded exact commitments with reason and authority. There is no preferred-time field, availability approval queue, Online room, travel matrix, maintenance workflow, or booking marketplace.

#### Generate & Review

The result leads with status, plain-language meaning, responsible owner, and one next action.

- `Optimal` and `Feasible` show the fixed quality measures, filterable weekly view, accessible meeting table, warnings, and candidate actions.
- `Infeasible`, `Unknown`, `ModelInvalid`, and `TechnicalFailure` show diagnostic groups and direct corrective links instead of an empty timetable.
- Failed groups follow **failure → affected record → factual basis → owner → corrective action**.
- Solver statistics, assumptions, identifiers, and constraint details remain collapsed.

Filters are program, cohort, course, Faculty, room, day, mode, and changed/affected rows. **Adjust candidate meeting** offers only valid day/time/Faculty/room choices, revalidates the complete candidate, and never waives a hard rule. A quality-lowering correction requires a publication reason. The label **Manual override** is not used.

The weekly timetable is the one justified custom component. It must have an equivalent native, filterable, screen-reader-readable meeting table. It is not drag-and-drop.

#### Published Timetable

Show the current immutable version, recorded authority, publication time, filtered official timetable, print/save-as-PDF, revision impact, and superseded history. A targeted revision begins from an explicit source change and impact preview; no published meeting is edited in place.

### Low-fidelity wireframes

```text
Catalog & Curricula
┌ Program · authority · effective status          [Primary] ┐
├ Courses and current revisions            [Search] [Filter] ┤
├ Curriculum Version · grouped by year and term              ┤
│ Code/title | Units | Requisites | Meeting | Mode | Ready   │
├ Draft import findings and source comparison                 ┤
└ Activation readiness · evidence · next action               ┘
```

```text
Term Planning
┌ Term · state · readiness · authority · version     [Action] ┐
│ Overview | Cohorts & Classes | Teaching Resources           │
│ Generate & Review | Published Timetable                      │
├ Current tab: owner · next action · failed checks only        ┤
├ Authoritative rows / timetable / diagnostics                 ┤
└ Filter indicators · secondary actions · evidence             ┘
```

```text
Generate & Review — failure
┌ Infeasible · no candidate can be published                  ┐
│ Owner: Registrar                         [Correct source]    │
├ Room capacity conflict                                      │
│ Affected classes → factual basis → room record → action     │
├ Faculty availability conflict                               │
│ Affected classes → factual basis → declaration → action     │
└ Technical assumptions and statistics (collapsed)            ┘
```

```text
Generate & Review — valid candidate
┌ Feasible · complete and hard-valid              [Publish]   ┐
│ Optimality not proven · publication reason required          │
├ Mode switches | Cohort idle | Faculty balance | Room waste   │
├ Weekly timetable                         [Filters] [Table]    │
├ Warnings and changed meetings                                 │
└ [Adjust candidate meeting] [Reject candidate] [More actions] │
```

```text
Faculty — My Availability / My Schedule
┌ Selected term · declaration state · due date          [Action] ┐
├ My Availability: hard unavailable intervals · evidence        ┤
├ My capacity/eligibility (read-only institutional result)       ┤
├ My Schedule: current published version                         ┤
│ Day/time | Course/class | Mode/room | revision marker          │
└ My affected revision history                                  ┘
```

```text
Published Timetable and revision
┌ Official version · authority · published time      [Print/PDF] ┐
├ Filters · weekly view / accessible meeting table               ┤
├ Day/time | Course | Class | Faculty | Mode/room                 ┤
├ Revision source · affected roles/classes · validation [Publish] │
└ Superseded immutable versions and impact history                ┘
```

### Accepted layout comparison

| Accepted layout | Alternative considered | Decision basis |
|---|---|---|
| Catalog & Curricula plus Term Planning | Peer resource navigation or generic Academic Settings | Two connected workbenches preserve operating order and source context without a setup maze. |
| Business-result-first Generate & Review | Technical run-first interface | Registrar needs result meaning, owner, source, and corrective action before solver statistics. |
| Filterable weekly view plus accessible table; bounded correction action | Drag-and-drop scheduling | Every correction must use valid choices and revalidate the complete candidate; pointer-only manipulation cannot prove that. |

### Default ordering and page-specific states

Curricula order by curriculum year, term placement, course code, then stable revision reference. Classes order by program, cohort, course, then class reference. Teaching Resources show blockers first, then Faculty or room name. Candidate and published meetings order by teaching day, start time, course, and class reference. Published history sorts newest version first.

| Page/tab | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Catalog & Curricula | No program/curriculum provides source-owner guidance; no filter matches offers **Clear filters** | Group/sheet structure and import progress are labelled | Stale Draft/import/activation refreshes source; active versions stay read-only | Unauthorized edit actions are absent and server-rejected |
| Overview | Missing calendar facts render failed readiness, never an empty success | Checks retain source/owner placeholders | Stale package/date action refreshes and requires reconfirmation | Inactive/missing term prevents downstream actions with source link |
| Cohorts & Classes | No demand and no filter match are distinct; absence of classes is a blocker when curriculum demand exists | Forecast/source state is labelled | Source change refreshes Draft rows; published-impact action requires review | Other-role and implicit-term actions are inaccessible |
| Teaching Resources / My Availability | No declaration/resource shows responsible owner and due action | Declaration and blocker rows keep labels | Late/stale declaration records a new correction; failure preserves last authority | Faculty sees only own declaration/schedule; System Administrator cannot change academic facts |
| Generate & Review | No run explains readiness/start action; no candidate is distinct from an empty meeting set | One active run shows safe progress without fake completion | Stale source invalidates generation/publication action and links to source | Infeasible, Unknown, ModelInvalid, and TechnicalFailure each show distinct meaning/owner/recovery; no candidate can publish |
| Published Timetable / My Schedule | No published version states not official yet; filtered empty offers **Clear filters** | Version and table/view loading are labelled | Stale sign-off/revision/impact refreshes; failed publication creates no partial version | Candidate data is inaccessible to Faculty/Students; Students receive only Clinic 4 official placement projection |

The Term Planning wireframe is the explicitly shared shell for Overview, Cohorts & Classes, Teaching Resources, Generate & Review, and Published Timetable. The dedicated failure, valid-candidate, Faculty, and published/revision frames provide the required state- and role-specific detail; Catalog & Curricula has its own direct frame.

### Cross-role, responsive, and failure behavior

- Registrar owns editable setup, candidate correction, publication, and revision.
- Academic Head sees read-only calendar, curriculum, readiness, candidate evidence, and published timetable oversight.
- Faculty declares availability, reads assigned official meetings, and sees affected revision history.
- Student sees only the Clinic 4-owned placed and officially enrolled projection.
- System Administrator sees solver-related System Health evidence without academic actions.
- Applicant, Accounting, and Public receive no Clinic 3 master-timetable access.

On mobile, grouped curriculum and resource rows stack with labels, the weekly view becomes a day-by-day list, filters remain in the native panel, and secondary actions stay in Action Groups. Result status includes text and screen-reader meaning and never depends on color. Empty, loading, inaccessible, stale-source, technical-failure, and no-candidate states all name what happened and the safe next action.

### Native component and communication decision

Native Filament Tables own queues and record lists; Infolists own immutable evidence; Forms own actual input; Sections and Tabs own progressive disclosure; Action Groups own secondary actions. No scheduling, calendar, dashboard, permissions, saved-filter, or generic import plugin is justified by Clinic 3. The bounded CSV preview/import and custom weekly view remain focused TALA components.

Email is limited to the Faculty availability action request, first publication to assigned Faculty, and one shared published-revision event. Clinic 3 owns the revision trigger and affected Faculty; Clinic 4 supplies affected officially enrolled Students and their updated schedule/COR context. Routine saves, readiness checks, generation, failure, and candidate correction use in-workspace feedback only.

## Clinic 4 — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop UI Authority

**Status:** Approved on 2026-08-06. This section translates PRD 04 into exact role surfaces. It is not an implementation task plan.

### Navigation and page inventory

| Role | Primary surface | Contextual destinations |
|---|---|---|
| Ready Applicant / Student | **Enrollment** guided status page | Full curriculum evaluation in Academics, Finance, current/historical COR |
| Registrar | **Students & Enrollment** workbench | Student Record, published Class Offering, curriculum evaluation, account clearance evidence |
| Accounting | **Enrollment Clearance** queue | Student Account and payment-evidence records |
| Faculty | Official roster projection only | Published schedule and affected revision history |
| Academic Head | Read-only enrollment oversight | Recorded institutional authority evidence |
| System Administrator | Locally evidenced System Health for integrations, queue, and email only | Technical evidence without academic or financial actions |

There is no separate Study Plan navigation item, generic gate screen, learner class marketplace, or peer resource for each checkpoint.

### Learner Enrollment guided status page

The page is a vertically ordered decision surface, not a Wizard or card dashboard:

1. Term, applicable deadline, bounded window applicability, derived stage, responsible owner, next action, and one primary button.
2. Five-checkpoint summary: eligibility, confirmed proposal, valid placement, Accounting clearance/coverage, and Registrar finalization. Successful checks collapse; failures lead with reason, owner, and recovery.
3. Proposed or official subjects and schedule.
4. **Why these subjects** and a contextual link to the full curriculum evaluation.
5. Academic blockers, unavailable requirements, shortage state, and bounded completion outlook.
6. Placement and reservation evidence, including the shared institutional expiry deadline.
7. Amount required now, verified payment applied, Approved Coverage applied, remaining amount required now, satisfaction basis, clearance state, and **Finance** link.
8. Current/historical COR, registration/change history, and any Registrar-owned post-enrollment credential follow-up reference. The follow-up is not shown as an enrollment checkpoint failure.

Exactly one primary action appears: **Start enrollment**, **Start registration**, **Confirm proposed subjects and schedule**, **Continue to Finance**, or **View COR**. When the proposal looks wrong, the page says **Do not confirm—contact Registrar** and provides the official contact path; it creates no ticket or chat.

```text
Enrollment — First Semester AY 2026–2027
┌ Action needed · You                         [Confirm proposal] ┐
│ Confirm by 18 June 2026 · Registrar owns corrections          │
├ Checkpoints: Eligibility ✓ · Proposal ! · Placement —         │
│              Finance — · Official enrollment —                │
├ Proposed subjects and schedule                                │
│ Course | Class | Schedule | Units | Result                     │
├ Why these subjects · Full curriculum evaluation →             │
├ Placement / reservation · Finance requirement                 │
└ History · COR unavailable until official enrollment           │
```

### Registrar Students & Enrollment workbench

The selected-term header shows term and applicable windows, authoritative deadlines, current readiness, official-enrollment count, shortage count, and one state-appropriate primary action.

Tabs are **Ready to prepare**, **Waiting for learner**, **Placement and shortages**, **Finance pending**, **Ready to finalize**, **Adjustments and Drops**, and **Official and history**. Counts are operational orientation, not a chart dashboard.

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
9. Collapsed audit and email evidence.

State-valid actions are **Prepare/revise proposal**, **Issue for confirmation**, **Record assisted confirmation**, **Place/change class**, **Finalize official enrollment**, **Record cancellation**, **Record adjustment**, **Record Course Drop**, and **Print current/historical COR**. The primary action stands alone; secondary actions use an Action Group. Invalid or stale actions remain server-rejected even when a crafted request bypasses the UI.

```text
Students & Enrollment — First Semester AY 2026–2027
┌ Readiness · deadlines · 428 official · 7 shortages [Primary]  ┐
├ Ready to prepare | Waiting | Shortages | Finance | Finalize…   │
├ Search                                      [Filters: 3 active] │
│ Learner | Program | Stage | Owner / next action | Deadline     │
└ Row → one ordered Enrollment Record · [More actions]           │
```

### Placement and shortage presentation

Proposal rows show course, curriculum requirement served, class reference, schedule/mode/room, units/contact hours, prerequisite/equivalency result, capacity/reservation result, and plain-language issue. Learners never see other learners or internal capacity analytics.

Registrar shortage rows show affected course and learners, current and protected capacity, aggregate unmet demand, valid alternatives, academic impact, owner, and next action. Their aggregate `UnmetClassDemandProjection` feeds Clinic 3's Draft Class Offering preparation without exposing learner records there. Resolution links to another valid class, a safe capacity-only amendment, or Clinic 3's externally approved Additional Offering path. The UI promises no ranked position or future seat.

### Accounting Enrollment Clearance

One native queue shows learner, reference, term, assessment basis/source, assessed total, amount required now, verified payment applied, Approved Coverage applied, remaining amount required now, satisfaction basis, clearance state, deadline, and next action. `Assessment required` joins the existing queue when neither a valid `PublishedFeePlan` nor an eligible `AuthorizedIndividualAssessment` is current; it does not create another resource. Accounting may record its owned clearance evidence, an eligible exact externally authorized individual assessment, and externally approved coverage through focused actions. It cannot calculate charges from a rate, determine scholarship eligibility, change subjects/classes, create Student identity, or finalize enrollment.

```text
Enrollment Clearance
┌ Term · readiness · deadline                              ┐
├ Search                                  [Filters: active] │
│ Learner/reference | Basis/source | Assessed | Required now│
│ Payment | Coverage | Remaining | Basis | State | Action  │
└ Row detail / focused Accounting action                   ┘
```

```text
Record authorized individual assessment
┌ Registration/change version · Program/Term · reason category ┐
├ Confirmed course/unit snapshot · authority reference/date     │
├ Exact ordered charge lines · obligations · reconciled total   │
├ Enrollment-required amount · predecessor · impact preview     │
└ [Cancel]                         [Record exact assessment]     │
```

The focused form records an externally calculated result only. It contains no per-unit rate, formula builder, inheritance, percentage, penalty, refund, or implicit default. Enrollment and changed-registration surfaces identify `Published fee plan`, `Authorized individual assessment`, or `Unavailable`. A changed registration distinguishes **Additional clearance required**, **No additional amount**, and **Accounting review pending**.

```text
Record approved coverage
┌ Current Assessment · named obligations · remaining amounts  ┐
├ Category/source · authority reference/date · effective date  │
├ Exact applicable amount · safe learner description           │
├ Impact: payment ₱___ + coverage ₱___ = remaining ₱___        │
└ [Cancel]                              [Apply coverage]        │
```

This focused action records only an externally approved account effect. Missing, stale, conflicting, unreconciled, unsupported, or excessive authority records nothing. There is no scholarship application, eligibility, ranking, renewal, disbursement, silent cap, refund, or financial-accommodation workflow.

### COR UI and print contract

The current and historical COR surface is an authenticated Generated Read-Only View. It shows institution/document identity; student number and legal name; program, curriculum, term, and enrollment basis; curriculum levels represented; official courses and class/schedule details; total units; assessment-at-finalization basis/source and categories, Approved Coverage and verified-payment amounts, satisfaction basis, remaining amount as of finalization; and actual recorded authority where required. A later authorized removal or Course Drop may identify **Accounting review pending** without changing the frozen assessment snapshot.

LRN, live ledger activity, future installments, attempts, receipt history, continually changing balances, and fictitious signatures are excluded. Later financial activity links to Student Account/SOA. The document is restrained, high-contrast, grayscale-safe, and offers one browser print/save-as-PDF action.

```text
Current / historical COR
┌ Institution · COR version · Official / Superseded [Print] │
├ Student · program · curriculum · term · selection basis   │
├ Course/class | Units | Schedule | Mode/room | Faculty      │
├ Total units · assessment-at-finalization snapshot          │
├ Recorded authority, when required                          │
└ Versions: newest first · Student Account/SOA link          ┘
```

### Accepted layout comparison

| Accepted layout | Alternative considered | Decision basis |
|---|---|---|
| Learner guided status page | Wizard or card dashboard | The journey may pause across offices and time; one durable status surface exposes the current owner, checkpoint, evidence, and next action. |
| Single Registrar Students & Enrollment workbench | Standalone Study Plan or peer resource per checkpoint | Proposals are versioned facts inside one Registration Case and must stay synchronized with placement, Finance, finalization, and COR. |
| Five accountable checkpoints | Generic gate engine | Fixed product-owned checks are explainable and cross-role; a configurable workflow would invent policy and obscure ownership. |

### Default ordering and page-specific states

Enrollment queues sort overdue/action-needed cases first, then nearest deadline, then oldest last activity and stable case reference. Proposal rows follow curriculum order, then class reference. Shortages sort by nearest deadline, course, and affected learner. Accounting Clearance sorts by nearest deadline, remaining required amount, then learner. COR history sorts newest version first.

| Surface | Empty / filtered empty | Loading | Stale / failed action | Inaccessible / unavailable |
|---|---|---|---|---|
| Learner Enrollment | No current case offers a valid start only when eligible; completed/no-current-term state explains next boundary | Checkpoints and primary-action position remain stable | Stale proposal/window/placement/Finance result refreshes before confirmation; failed action preserves current case | Ineligible/closed/expired state identifies owner and support without exposing internal records |
| Registrar workbench / record | No cases and no filter matches are distinct; filters can be cleared | Tabs/counts/table/detail loading are labelled independently | Stale proposal, placement, finalization, adjustment, or drop action is rejected and current facts shown | Unauthorized roles and direct records are inaccessible without existence disclosure |
| Placement and shortages | No shortage states **No unresolved shortage**; no alternatives names Clinic 3 owner/action | Capacity/reservation checks show bounded progress | Concurrency loss or expiry refreshes capacity and never oversubscribes | Learners cannot see other learners or internal capacity analytics |
| Accounting Clearance | No pending cases and filtered empty are distinct | Assessment, payment, and coverage facts show source loading | Missing/stale/unreconciled/unauthorized assessment or coverage is `Unavailable`/`ActionNeeded` as applicable; failed recording preserves safe input and never creates a fallback, silent cap, or false clearance | Accounting cannot change academic records, create identity, calculate a formula, determine funding eligibility, or finalize |
| COR current/history | Unavailable before official enrollment is explicit; missing historical version is an assurance fault | Print view reports loading without presenting a partial official document | Superseded version is labelled; print failure leaves authenticated view authoritative | Only owning learner and authorized Staff see COR; direct unauthorized access reveals nothing |

Clinic 4's four primary page families—learner Enrollment, Registrar Students & Enrollment, Accounting Enrollment Clearance, and current/historical COR—each have a direct wireframe above. Placement, shortage, adjustment, Course Drop, and timetable-impact actions remain contextual parts of the Registrar workbench rather than separate navigation pages.

Clinic 4 demonstration data includes ordinary published-plan enrollment, reduced and Individually Advised cases with selection-specific authority, changed-registration branches, and coordinated `REG-2026-ST-001`. That Special Term case consumes `TERM-2026-ST`, `CLS-ITE3-ST-A`, and Additional retake `CLS-IT201-ST-R`; excludes dependent `IT301` only; remains `Unavailable` until `ACT-2026-ST-001`; then shows PHP 2,000 Applied coverage plus PHP 1,000 verified payment as `Mixed` clearance before official enrollment and COR. The walkthrough must preserve the same references through Clinics 3–6 without creating a Summer, tutorial, irregular-student, scholarship, or accommodation workflow.

### Responsive, accessibility, failure, and communication behavior

Course and queue rows stack with labels on mobile; information order is unchanged; the primary action remains reachable; secondary actions remain in Action Groups. All controls are labelled, keyboard reachable, visibly focused, and announced with current status. Meaning never depends on color.

Loading, empty, stale, expired, inaccessible, 403, 404, 419, 429, validation, concurrency, and integration-failure states name what happened, the responsible owner, and a safe recovery action. A failed checkpoint expands; successful checks reduce to **All required checks passed** where appropriate.

Queued, idempotent email is limited to the continuing-Student enrollment-window notice, proposal ready/materially revised, payment or coverage action required, official enrollment/COR ready, reservation release/case expiry, and official adjustment/Course Drop. On first enrollment, the official-enrollment/COR message also explains that Student access is active; no separate activation email is sent. An affected timetable revision uses Clinic 3's one shared publication event, with Clinic 4 supplying affected enrolled-Student recipients and updated schedule/COR context. Routine saves, checks, navigation, and recurring reminders remain in-workspace only. Mail failure never rolls back enrollment or financial state.

### Native component decision

Native Filament Tables own queues/search/filters; Infolists and Sections own authoritative read-only detail; Forms own real input; Tabs provide the workbench projections; Action Groups hold secondary actions. The guided Enrollment page and authenticated COR print view are focused custom Pages composed from these primitives. Clinic 4 justifies no enrollment, workflow, waitlist, dashboard, PDF-generation, or policy-engine plugin.

## Clinic 5 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion UI Authority

> **Authority status — Clinic 5 approved.** This section translates PRD 05 into exact role surfaces. This approval closes UI definition for Clinic 5 only; it is not an implementation task plan.

### Navigation and page inventory

| Role | Primary surface | Contextual destinations |
| --- | --- | --- |
| Faculty | **Grade Rosters** | Assigned official schedule and returned-roster history |
| Registrar | **Grades & Completion** workbench | Student Record, official Class Offering, curriculum evidence, Clinic 6 clearance |
| Student | **Academics** | Unofficial print view and contextual Enrollment link when a changed result affects registration |
| Academic Head | Read-only Academic Oversight | Recorded progress, correction, lifecycle, and conferral authority evidence |
| Accounting | Clinic 6 output-payment clearance only | Student Account; no grade or academic decision action |
| System Administrator | Queue, email, and System Health evidence only | Technical evidence without academic-record authority |

There is no Gradebook, Attendance, Period Grades, What-if Audit, Graduation Batch, Transcript Template, or academic-policy Settings navigation item.

The Clinic 5 visual comparison considered **role workbenches**, **Student-record first**, and **separate peer resources**. Role workbenches are accepted because complete-roster work remains class-centered for Faculty, cross-record decisions remain contextual in one Registrar workbench, and Students receive one coherent academic story. Student-record first obscures whole-roster submission; separate resources create navigation sprawl. Those alternatives are rejected rather than retained as hidden navigation.

### Faculty Grade Rosters

The native queue leads with course/class reference, program/cohort, official learner count, completed-result count, submission deadline, plain-language state, owner, and next action. Search covers class reference and course; filters cover term, state, and deadline/overdue state. Before release, Faculty may see that their assigned roster is one missing source for term-grade completeness; they never see another class's results or a learner's term/cumulative average through this context.

The roster table shows Student number, legal name, official enrollment state, one controlled final-grade/INC selector, derived academic result, and any validation or lifecycle explanation. Selecting `INC` always reveals the required completion note. If no applicable approved INC policy exists, it also shows **Deadline not established — institutional policy required** without blocking roster submission. The designated submitter receives **Save draft** and **Submit complete roster**. View-only co-Faculty receive no edit or submit action. Returned-row correction, history, and evidence remain secondary actions.

```text
Grade Roster — IT 301 / BSIT 3A
┌ Due 18 Oct 2026 · 28/30 complete · You own submission      ┐
├ Student no. | Legal name | Enrollment | Final result | Note │
│ SIA-...     | ...        | Official   | [1.00–5.00/INC]     │
├ Validation / returned-row explanation                       │
└ [Save draft]                         [Submit complete roster]│
```

An empty result remains unfinished and cannot be released. `INC` reveals the required short completion note. The UI never requests Preliminary, Midterm, Final-period values, formulas, attendance, raw scores, `P`, Course Drop, withdrawal, or approved-credit marks.

### Registrar Grades & Completion workbench

The workbench contains **Grade Review**, **INC & Corrections**, **Academic Progress**, **Lifecycle**, **Completion & TOR**, and **History**. Counts orient staff to pending work; they do not create a chart dashboard.

The native table searches Student number, legal name, course/class reference, and TOR reference. Filters cover term, program, course, Faculty, roster state, deadline, released result, INC/correction state, progress, lifecycle, completion readiness, and relevant date ranges. Active filters remain visible; no column-header dropdowns are introduced.

Each record reads in this order:

1. State, responsible owner, deadline, next action, and one primary action.
2. Student or roster identity and authoritative term/class context.
3. Released result, INC, correction, or progress facts relevant to the selected tab.
4. Term weighted average/cumulative GWA readiness and curriculum-evaluation effect where applicable.
5. Lifecycle, completion, or transcript effect where applicable.
6. Authority and evidence, including the applicable INC policy version, authority reference, and effective Term when one exists.
7. Collapsed immutable history, audit, and email evidence.

State-valid primary actions include **Release roster**, **Return specified rows**, **Release INC resolution**, **Record authorized correction**, **Record progress decision**, **Record lifecycle result**, **Record conferral**, **Generate transcript preview**, and **Record issuance**. An INC lapse action appears only when an applicable approved policy version authorizes automatic lapse and its inclusive Asia/Manila deadline has passed; it is disabled when the policy source is unavailable or stale. Policy recording is contextual to **Grades & Completion**, not an academic-policy Settings page, and Academic Head sees policy evidence read-only. Only one primary action appears for the current decision. There is no bulk release, correction, consequential decision, conferral, or TOR issuance.

```text
Grades & Completion
┌ Term / deadlines · owner summary · one primary action          ┐
├ Review | INC & Corrections | Progress | Lifecycle | Completion  │
├ Search                                      [Filters: 2 active] │
│ Record | Context | State | Owner / next action | Due            │
└ Row → ordered evidence, effect, authority, and immutable history│
```

### Student Academics

Student Academics is one read-mostly vertical page:

1. Current academic-record status and next action.
2. Released grades grouped by term.
3. **Term weighted average** and **Cumulative GWA**, or the explicit **Grades not complete**, incomplete-result, or not-applicable state; when current values are withheld, show the last complete cumulative **Through [term]** value if one exists.
4. Curriculum evaluation with required courses, attempts, credited mappings, current enrollment, prerequisites, and deficiencies.
5. Confirmed academic progress and safe explanation.
6. Attempted, earned, and remaining units.
7. Completion readiness and state-valid **Apply for graduation** action.
8. Correction, INC, and lifecycle history.

An unresolved `INC` shows a deadline only when an applicable approved policy version supplies it. Without one, Faculty, Registrar, Student, and Academic Oversight projections all show **Deadline not established — institutional policy required**, name Registrar as the responsible office, and give the next safe action. No countdown, deadline reminder, or implied lapse result appears.

`Term weighted average` is the neutral one-term label. **Term GPA** or another display term appears only when the effective `GwaPolicyVersion` records Servitech's authority, reference/date, and effective term. A partially released term always shows **Grades not complete** and never calculates from the released subset. A grade-complete term with only excluded/nonnumeric outcomes shows **Not applicable — no included academic units**, never zero. The cumulative value is recalculated from all included attempts and units rather than averaging displayed term values.

The printable action is labelled **Unofficial record — for student reference**. Official TOR issuance is absent from Student actions. When a correction affects an active Registration Case, the page states that Registrar review is required and links to Enrollment without promising an automatic course change.

```text
Academics
┌ Record current · Registrar owns official corrections       ┐
├ Released grades by term                          [Print unofficial]
├ Term weighted average / readiness · Cumulative GWA Through […]│
├ Curriculum progress · attempted / earned / remaining units  │
├ Confirmed academic progress · responsible office / next step │
├ Completion readiness                   [Apply for graduation] │
└ INC, correction, lifecycle, and conferral history            │
```

```text
INC detail — Policy unavailable / Policy bound
┌ Result INC · Completion note · Original Term end            ┐
├ Policy: Not established / [version · authority · effective]  │
├ Deadline: Not established / [inclusive Asia/Manila date]      │
├ Effect: Academic average pending · prerequisite unsatisfied · progress pending│
└ Owner: Registrar · [Release completion] / [Lapse when authorized]│
```

### TOR preview and issuance

Registrar's transcript view is an authenticated Generated Read-Only View. Because the supplied Servitech format cannot be reused, the demonstration uses an original code-owned layout labelled **Proposed institutional format — Not for official issuance**. The header clearly distinguishes **Proposed preview**, **Issued**, **Voided**, and **Superseded** snapshots. **Record issuance** appears only after the institution has approved the exact template version and external certification is complete. The only browser output action is print/save-as-PDF for authorized Registrar processing; no Student official-download or template editor exists.

The issuance record shows external request reference/date, derived 30-day due date, Clinic 6 clearance, source record version, certification state, issuance date/reference, and any void/replacement or later supersession link. Signature, seal, CAV, payment, claiming, courier, and delivery controls are not recreated.

```text
TOR — Proposed preview / Issued / Voided / Superseded
┌ Student and program · request reference · statutory due date │
├ Readiness: academic record · template · Clinic 6 clearance    │
├ PROPOSED INSTITUTIONAL FORMAT — NOT FOR OFFICIAL ISSUANCE      │
├ Source version · template version · certification state       │
└ [Generate preview] / [Record issuance] / secondary history    │
```

### Sorting and page-specific states

- Faculty rosters sort overdue and due-soon work first, then course/class reference. **No grade rosters assigned** links to the published teaching assignment; a filtered-empty result offers **Clear filters**.
- Registrar queues sort action-needed records first, then due date and latest activity. **No records need review** confirms the active term and filters rather than implying that no academic records exist.
- Student Academics groups terms newest first while TOR rows remain chronological. **No released results yet** explains that only Registrar-released results appear and provides no misleading action.
- Partly released terms show **Grades not complete**, the count/source of missing official outcomes to authorized Staff, and the last complete cumulative **Through [term]** value when available. They never show a partial term value or a newly calculated cumulative value.
- Grade-complete terms with no included numeric units show **Not applicable — no included academic units**. Institution-approved display terminology is shown with its effective source; otherwise the neutral label remains.
- An INC with no applicable policy is neither overdue nor lapse-eligible. Its detail shows the responsible Registrar office and completion path; only policy-bound records may be sorted by an authoritative deadline.
- TOR readiness names the exact unavailable source: academic record, proposed-layout source, institution-approved template for issuance, Clinic 6 clearance, or external certification. A proposed preview may be generated when its sources are ready, but it cannot enable **Record issuance**. The surface never shows a generic **Not cleared** state.
- Loading retains the page heading and announces progress. Stale or concurrent actions preserve entered data when safe, identify what changed, and require review before resubmission. Inaccessible records use the shared non-disclosing recovery surface. Technical or mail failures identify the responsible owner and one safe retry, return, or support action.

### Responsive, accessibility, failure, and communication behavior

Roster, grade-history, curriculum, and queue rows stack with labels on mobile. Reading order remains unchanged, the primary action remains reachable, and secondary actions use Action Groups. Wide TOR previews provide a readable on-screen summary and a print view rather than forcing an unusable scaled document into the mobile viewport.

All controls are labelled, keyboard reachable, visibly focused, and accompanied by screen-reader status text. Meaning never depends on color. Empty, loading, stale-record, inaccessible, expired-session, validation, late-window, concurrency, mail-failure, and technical-failure states state what happened, who owns recovery, and the safe next action.

Queued email is limited to the Faculty submission request, returned roster, grade release without values/attachment, policy-bound INC action/deadline, INC resolution or authorized lapse, authorized correction, consequential progress/lifecycle, completion action-required, and conferral. No INC deadline email is created when no applicable approved policy exists. Routine saves, calculation/readiness refresh, queue movement, navigation, and recurring reminders remain in-workspace only.

The Clinic 5 synthetic set includes `INC-NOPOL-001` with no applicable policy and no deadline; `INC-POL-002` bound prospectively to an approved policy and completed before its inclusive deadline; `INC-LAPSE-003` with one idempotent superseding lapse result; and coordinated `TERM-2026-ST` classes `CLS-ITE3-ST-A` (`1.75`) and `CLS-IT201-ST-R` (`2.50`). Releasing the first class alone must show **Grades not complete** and the prior cumulative **Through [term]** value. Releasing the second must show Special Term `2.13` and cumulative `2.01` from 90 prior included units/180 weighted points plus six units/12.75 points; the earlier `IT201` `5.00` remains counted while the retake satisfies the curriculum. No PUP label appears unless separately authorized by Servitech.

### Native component decision

Native Filament Tables own queues, rosters, search, and filters; Forms own controlled final-result and authority input; Infolists and Sections own read-only academic evidence; Tabs own the Registrar workbench; Action Groups own secondary actions. Focused custom Pages are justified only for Student Academics, the unofficial print view, and the code-owned TOR preview. Clinic 5 justifies no gradebook, spreadsheet-import, attendance, workflow, academic-policy, transcript-template, dashboard, or PDF plugin.

## Clinic 6 — Accounts, Official Outputs, Operations, and Assurance UI Authority

### Navigation and primary-page inventory

Accounting receives exactly two primary finance destinations:

1. **Fee Plans**
2. **Student Accounts**, with **Accounts**, **Payment Exceptions**, and **TOR Clearance** tabs

System Administrator receives **System Health** and **Governance & Audit**. Student receives one **Finance** destination. Applicant payment status remains embedded in Clinic 4 Enrollment; there is no Applicant Finance destination. Alumni retain read-only Student Finance history.

| Primary page | User goal | Information order | Native surface decision |
|---|---|---|---|
| Fee Plans | Publish one fixed ordinary Program-and-Term version | Current plan, action-needed Drafts, upcoming Terms, history | Native filtered Table plus focused create/view pages; no formula or calculation builder |
| Fee Plan detail | Prepare and publish exact charges and obligations | Identity/authority, charge lines, obligations, readiness, history | Sections, Grid, ordered Repeater/table rows, Infolist after publication, focused publish Action |
| Student Accounts | Find the next account decision, including `Assessment required` | Status, person, account, Program/Term, assessment basis/source, required/payment/coverage/due, satisfaction basis, next action | One Table with three semantic tabs and native filters; no separate assessment or coverage destination |
| Payment Exception detail | Check safe evidence and record the external result | Reason/current due, evidence, review fields, history, consequence | Authorized view Page/Infolist with private preview and focused Actions |
| TOR Clearance detail | Record one request-specific result | Output request, learner, requirement/reference, source, result | Contextual Infolist with `Record cleared` and `Record not required` Actions |
| Student Account detail | Explain one Term position or record an eligible exact individual or coverage result | Current status/due, assessment basis/source, separate payment/coverage amounts, satisfaction basis, next obligation/action, projection, evidence tabs | Summary Sections, Infolists, responsive Tables, Tabs, contextual assessment/coverage Actions, Action Group |
| Authorized individual assessment | Record an externally calculated exact result, not calculate a fee | Current Registration/change version and course/unit evidence, reason/authority, exact lines/obligations, totals, impact preview | Contextual Form on Account detail; no formula, rate, inheritance, percentage, penalty, or refund controls |
| Approved Coverage | Record one externally approved Term Account effect | Current Assessment/obligations, category/source, authority/date, exact applicable amount, effective date, safe description, impact preview | Contextual Form on Account detail; no eligibility, application, renewal, disbursement, accommodation, cap, refund, or allocation controls |
| Enrollment payment requirement | Complete Clinic 4's finance checkpoint | Required now, state, assessment basis/source, account, submission, next action | Clinic 4 embedded Section with private File Upload and focused Actions |
| Student Finance | Understand current or historical account | Current due/status, assessment basis/source, next obligation, safe actions, recent activity, outputs | Focused Page with Sections, Infolists, responsive activity rows, contextual Actions |
| System Health | Distinguish local evidence from unknown external state | Capture time, service/status/evidence/as-of, next step | Read-only status Table; local refresh; optional self-test email only |
| Governance & Audit | Investigate high-value evidence | Tab, filters, newest events, selected detail, retention state | Tabs, read-only Tables/Infolists, fixed filters |
| Account Statement | Read or print an as-of non-tax account position | Identity/context, charges, activity, obligations, totals, disclaimer | Authenticated print-safe browser view |
| Payment Acknowledgment | Read or print one verified posting | Payment summary, verification basis, effect/state, disclaimer | Authenticated print-safe browser view |

### Accounting setup and workbench wireframes

#### Fee Plans

```text
┌ Fee Plans ─────────────────────────────────── [New draft] ┐
│ Term [2026 T1▼] Program [All▼] State [All▼]  [Search…]   │
│                                                          │
│ STATUS          PROGRAM   VERSION  TOTAL       ACTION     │
│ Published       BSIT      v1       ₱48,000     View       │
│ Needs attention BSA       Draft 2  Incomplete  Continue   │
│ Upcoming        BSCS      v1       ₱46,500     View       │
│                                                          │
│ Published plans are immutable. New versions supersede.   │
└──────────────────────────────────────────────────────────┘
```

#### Fee Plan detail and publication

```text
┌ Fee Plan · BSIT · 2026 T1 · Draft 2 ─────────────────────┐
│ Authority ref [________]  Date [____]  Currency [PHP]     │
│                                                          │
│ CHARGE LINES                                              │
│ Code   Label                 Category     Amount     Order │
│ TUI    Tuition               Instruction  ₱40,000    1     │
│ LAB    Laboratory            Laboratory   ₱8,000     2     │
│                                          Total ₱48,000    │
│                                                          │
│ OBLIGATIONS                                               │
│ Enrollment requirement   10 Jun 2026      ₱12,000         │
│ Second obligation        15 Aug 2026      ₱18,000         │
│ Final obligation         15 Oct 2026      ₱18,000         │
│                                                          │
│ Readiness: ✓ authority ✓ totals ✓ dates ✓ unique version │
│ [Save draft]                              [Publish plan]   │
└──────────────────────────────────────────────────────────┘
```

Draft row controls provide explicit **Move up** and **Move down** buttons; order never depends on dragging. Published views replace fields with an Infolist and offer only `Create successor`.

#### Student Accounts workbench

```text
┌ Student Accounts ─────────────────────────────────────────┐
│ [Accounts 24] [Payment Exceptions 3] [TOR Clearance 2]   │
│ Term [2026 T1▼] State [Action needed▼] Program [All▼]     │
│ [Search person or account…]              [Export status]  │
│                                                          │
│ STATUS              PERSON         ACCOUNT       ACTION    │
│ Assessment required S. Student   ACT-2026-ST-001 Record   │
│ Under review         Ana Reyes     ACT-260001    Review    │
│ Action needed        Miguel Santos ACT-260014    View      │
└──────────────────────────────────────────────────────────┘
```

The Payment Exceptions tab uses risk/reason, person/account, claimed amount, channel/source, submission age, and `Review`. The TOR Clearance tab uses state, request reference, learner, required amount/reference, required date, and `Open`.

### Detailed Account and Payment Status prototype

#### Accounting account detail

```text
┌ Ana Reyes · ACT-260001 · BSIT · 2026 T1 ────────────────┐
│ [Action needed] Published fee plan · FP-...-v1 · 10:32    │
│                                                         │
│ Assessment   Required now   Payment   Coverage   Due now │
│ ₱48,000      ₱12,000        ₱8,000   ₱0         ₱4,000  │
│ Next obligation: ₱18,000 · 15 Aug 2026                  │
│ Enrollment projection: ActionNeeded · VerifiedPayment   │
│                                                         │
│ [Record verified payment] [Record approved coverage]    │
│ [Open exception]                                        │
│ [Generate SOA] [Export this account]                     │
│                                                         │
│ ACTIVITY                                                │
│ 09 Jun  Payment verified · Bank · ₱8,000 · PAY-001      │
│ 08 Jun  Evidence submitted · Underpayment               │
│ 01 Jun  Assessment v1 published · ₱48,000               │
│                                                         │
│ ASSESSMENT | PAYMENTS | COVERAGE | EVIDENCE | OUTPUTS…  │
└─────────────────────────────────────────────────────────┘
```

The first screenful answers status, current due, separate payment/coverage effects, satisfaction basis, next obligation, and next action. Supporting evidence is progressively disclosed through named tabs. `Record authorized individual assessment` appears contextually only for an eligible current exception, as shown next; it never appears for Ana's ordinary published-plan account. `Record approved coverage` appears only when a current Assessment and named remaining obligations exist. An authorized reversal dialog names the payment or coverage, amount, external authority, resulting projection, and append-only effect; its confirmation is `Record reversal`, not `Yes`.

#### Authorized individual assessment

```text
┌ Record authorized individual assessment · ACT-260045 ────────┐
│ Registration/change REG-045-v3 · Reduced/Individually Advised │
│ Courses/units [read-only confirmed snapshot]                   │
│ Authority ref [________] Date [____] Reason [________▼]        │
│ CHARGE LINES: code · label · amount · order                    │
│ OBLIGATIONS: label · due date · exact amount                   │
│ Total ₱_____ · Enrollment required ₱_____ · Reconciled ✓       │
│ Impact: Additional clearance required ₱_____                   │
│ [Cancel]                              [Record exact assessment]│
└────────────────────────────────────────────────────────────────┘
```

The action is available only for an approved Special Term, a reduced enrollment whose approved charges differ from the fixed plan, an Individually Advised selection-specific result, or an authorized adjustment/Course Drop effect. Missing, stale, unreconciled, or unauthorized source evidence remains `Unavailable`; the form never calculates from the displayed units.

#### Approved Coverage

```text
┌ Record approved coverage · ACT-2026-ST-001 ─────────────┐
│ Assessment AIA-ST-001 · Required now ₱3,000             │
│ Payment applied ₱1,000 · Coverage available up to ₱2,000│
│ Category [Government subsidy▼]  Source [____________]   │
│ Authority ref [________] Date [____] Effective [____]   │
│ Applicable obligation [Enrollment requirement▼]        │
│ Exact amount [₱2,000]  Safe description [___________]   │
│ Impact: Mixed · remaining required now ₱0               │
│ [Cancel]                              [Apply coverage]   │
└─────────────────────────────────────────────────────────┘
```

The action records one `Applied` account event. Excess, stale, conflicting, unsupported, or unreconciled authority records nothing and preserves safe entered fields. `Superseded` and `Reversed` results start from coverage history and require named authority and an exact impact preview. No learner application, eligibility document, ranking, renewal, disbursement, cash movement, or email appears.

#### Payment Exception detail

```text
┌ Payment Exception · EXC-003 ─────────────────────────────┐
│ Reason: Amount exceeds remaining obligation              │
│ Account ACT-260014     Claim ₱8,000     Due ₱6,000       │
│ Channel GCash          Reference ••••8842                │
│ Submitted 08 Jun 2026  Evidence [Open private preview]   │
│                                                         │
│ External check result [____________________________]     │
│ Safe learner reason   [____________________________]     │
│                                                         │
│ [Reject evidence] [Keep under review] [Verify ₱6,000]    │
│ No action creates a refund or deletes the submission.    │
└─────────────────────────────────────────────────────────┘
```

Private proof requires record authorization and never appears as a public URL. Field errors say how to recover, and failed verification states whether no posting occurred.

`Verify ₱6,000` is available only when the external check proves that ₱6,000—not the claimed ₱8,000—was actually received and the remaining-obligation guard passes. If the external source proves ₱8,000, no partial posting is created; the exception remains with Accounting for an external resolution because Clinic 6 has no refund or excess-allocation workflow.

#### TOR Clearance detail

```text
┌ TOR Clearance · TOR-260005 ──────────────────────────────┐
│ [Action needed] Student: Eva Ramos                       │
│ Output request: Official TOR · Request date 10 Jun 2026 │
│ Required amount: ₱500     External reference: None       │
│ Source: Registrar request TOR-260005 · As of 10:40       │
│                                                         │
│ [Record cleared] [Record not required]                   │
│ This result affects only this output request.             │
└─────────────────────────────────────────────────────────┘
```

### Learner wireframes

#### Applicant payment requirement inside Enrollment

```text
┌ Enrollment · Payment requirement ────────────────────────┐
│ Amount required now                    ₱12,000            │
│ Verified payment applied               ₱0                 │
│ Approved coverage applied              ₱0                 │
│ Status                                 Under review       │
│ Account reference                      ACT-260001         │
│ Assessment                             Published fee plan │
│                                                         │
│ Submitted: GCash · ₱12,000 · 09 Jun 2026                │
│ Accounting must verify the actual payment source.        │
│ [Open evidence] [Replace submission]                     │
│                                                         │
│ Enrollment can continue when the projection is Cleared.  │
└─────────────────────────────────────────────────────────┘
```

#### Student Finance — desktop

```text
┌ Student Finance · 2026 T1 ───────────────────────────────┐
│ [Cleared for enrollment]              As of 10 Jun 10:32 │
│ Assessment: Published fee plan · FP-BSIT-2026-T1-v1      │
│                                                         │
│ Current due       Next obligation       Term balance     │
│ ₱0                ₱18,000 · 15 Aug      ₱36,000          │
│ Satisfaction: Approved coverage · Coverage ₱12,000       │
│                                                         │
│ [Pay exact current due] [Submit payment evidence]        │
│ [Download SOA]                                           │
│                                                         │
│ Recent activity                                         │
│ Approved coverage · Scholarship · ₱12,000               │
│ Assessment published · ₱48,000                          │
│                                                         │
│ A later amount due does not cancel official enrollment.  │
└─────────────────────────────────────────────────────────┘
```

`Pay exact current due` is unavailable when the due is zero, the source is stale, PayMongo local readiness is unavailable, or a matching attempt remains pending. Browser return uses `Payment confirmation pending` or `Checkout cancelled — no payment was recorded from this return`.

#### Student Finance — mobile and alumni variant

```text
┌ Student Finance ─────────────┐
│ 2026 T1              [▼]     │
│ [Action needed]              │
│ Assessment: Individual       │
│ Due now                      │
│ ₱4,000                       │
│ Required ₱12k · Payment ₱8k  │
│ Coverage ₱0 · Basis Payment  │
│ [Pay ₱4,000]                 │
│ [Submit evidence]            │
│ [Download SOA]               │
│                              │
│ Next: ₱18k · 15 Aug          │
│ Activity                     │
│ • Payment verified ₱8k       │
│ • Assessment created ₱48k    │
└──────────────────────────────┘
```

At 360/390 CSS pixels, actions remain inset inside the content margin and stack without clipping. The alumni variant uses the same history/output order but removes checkout, upload, and account-changing actions.

### Operations and assurance wireframes

#### System Health

```text
┌ System Health ────────────────────────────────────────────┐
│ Evidence captured 10 Jun 2026 10:35     [Refresh local]   │
│                                                          │
│ SERVICE       LOCAL EVIDENCE          STATUS      AS OF   │
│ Email         Test delivery recorded  Available   10:30   │
│ PayMongo      Webhook received        Available   10:20   │
│ Solver        Last accepted result    Available   09:55   │
│ Queue         2 pending / 1 failed    Attention   10:35   │
│ Database      Local check succeeded   Available   10:35   │
│ App backup    Last job 05:40          Available   05:40   │
│ Hostinger     Not checked by TALA     Unknown       —     │
│ ORICO copy    Not checked by TALA     Unknown       —     │
│                                                          │
│ No provider, restore, payment, or solver controls appear.│
└──────────────────────────────────────────────────────────┘
```

Unknown is never colored or labeled as healthy. Refresh reads locally knowable evidence only. Provider status, backup-media custody, and restore readiness require external operational evidence.

#### Governance & Audit

```text
┌ Governance & Audit ───────────────────────────────────────┐
│ [Institutional Changes] [System Events] [Output Access]  │
│ [Privacy & Retention]                                    │
│ Actor [All▼] Type [All▼] Date [____—____] [Search…]       │
│                                                          │
│ TIME    EVENT                  ACTOR       RESULT         │
│ 10:31   Payment export         acct-01     24 rows        │
│ 10:22   Fee Plan published     acct-02     FP-...-v1      │
│ 09:40   SOA generated          student     ACT-260008     │
│                                                          │
│ Retention schedule: Not approved                         │
│ Automatic disposal: Disabled                             │
└──────────────────────────────────────────────────────────┘
```

### Printable-output wireframes

```text
ACCOUNT STATEMENT / SOA                 PAYMENT ACKNOWLEDGMENT
Non-tax institutional output            Non-tax institutional output
Learner and account reference            Payment and account reference
Program / Term / as-of                    Amount / date / channel
Assessment basis/source/version            Masked external reference
Ordered charge lines                       Verification basis
Chronological account activity             Account effect and current state
Obligation schedule                        Reversal notice when applicable
Current due / Term balance                  Generation reference
Generation reference                       Institutional disclaimer
Institutional disclaimer
```

Print views are monochrome-safe, use semantic headings, repeat table headers, avoid clipped rows, and retain the disclaimer on every generated copy.

### Selected alternatives

| Decision | Selected layout | Rejected alternatives | Reason |
|---|---|---|---|
| Account/payment status | Summary-first status, due, next obligation/action, then history | Ledger-first page; payment Wizard | Learners should not interpret accounting mechanics or traverse unnecessary steps |
| Accounting workspace | Fee Plans plus one tabbed Student Accounts workbench | Peer record Resources; dashboard/report hub | Matches the two office tasks and keeps evidence contextual |
| Approved Coverage | Contextual Account action with append-only effect/history | Scholarship module; Financial Accommodation resource | Records only an externally approved account effect without eligibility, application, renewal, disbursement, or collection scope |
| Assurance | Locally evidenced status plus `Not checked by TALA` | Provider operations console; manual attestation checklist | Prevents unsupported health/compliance claims and risky controls |

### Default ordering and page-specific states

- Fee Plans: current Published, action-needed Drafts, upcoming Term opening, Program, version.
- Accounts: `Assessment required`, then `ActionNeeded`/under review, nearest due, oldest relevant activity, person/account reference.
- Payment Exceptions: blocking or security mismatch, oldest submitted, reference.
- TOR Clearance: `ActionNeeded`, nearest required date, request date, request reference.
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

The authoritative synthetic records are `FP-BSIT-2026-T1-v1`, incomplete `FP-BSA-2026-T1-d2`, Term Accounts `ACT-260001`, `ACT-260008`, `ACT-260014`, `ACT-260021`, `ACT-260027`, `ACT-260033` with Applied scholarship coverage and successor/reversal evidence, `ACT-260034` with an institutionally authorized `NoPaymentRequired` Fee Plan result, `ACT-260039` with a missing-webhook manual reconciliation and late event, `ACT-260041`, `ACT-260045` with a reduced Individually Advised exact individual assessment, `ACT-2026-ST-001` for `REG-2026-ST-001` with PHP 6,000 assessment, PHP 3,000 required now, `COV-2026-ST-001` PHP 2,000 Applied subsidy, `PAY-2026-ST-001` PHP 1,000 verified payment and `Mixed` clearance, and `ACT-260047` with changed-registration branches; TOR clearances `TOR-260003` through `TOR-260005`; reversed `PAY-260009`; one alumni account; and one degraded-health example. Identities use `example.test`; no real student, provider, wallet, eligibility, or proof data appears.

The browser walkthrough follows this order:

1. Accounting observes the blocked BSA Draft and publishes the valid fixed BSIT plan.
2. Accounting opens Mira's `Assessment required` row and records a reconciled exact `AuthorizedIndividualAssessment`; stale authority blocks recording and no formula appears.
3. `REG-2026-ST-001` remains `Unavailable` until Accounting records `ACT-2026-ST-001` from the exact externally authorized result.
4. Excessive/stale coverage records nothing; valid `COV-2026-ST-001` applies PHP 2,000, `PAY-2026-ST-001` applies PHP 1,000, and Clinic 4 receives `Mixed` clearance without a coverage email or scholarship workflow.
5. Sam's changed registrations separately show additional clearance, authoritative no-additional-cost confirmation, and Course Drop Accounting review pending without an automatic refund, credit, penalty, forfeiture, or COR rewrite.
6. Applicant Ana sees the embedded due and assessment basis before Student creation and submits private evidence without posting.
7. Accounting verifies the external source; Clinic 4 receives `Cleared`, finalizes enrollment, and the same account gains the Student reference.
8. Student Finance and SOA show payment and coverage as separate append-only account effects; Payment Acknowledgment remains payment-only.
9. Exact-due PayMongo return remains pending until a valid webhook; duplicate delivery posts and emails once.
10. A missing webhook remains pending. Accounting verifies the real provider source through the existing external-payment path; a later matching event creates no duplicate posting or email.
11. Mismatch, underpayment, rejection, resubmission, payment reversal, and coverage supersession/reversal preserve append-only evidence.
12. A later missed obligation or coverage reversal changes Finance only and does not undo enrollment or academic access.
13. Accounting resolves `Cleared`, `NotRequired`, and `ActionNeeded` TOR requests without a global hold.
14. The two contextual CSVs record purpose and output-access evidence.
15. System Health separates local evidence from external unknowns; Governance shows retention not approved and disposal disabled.
16. Alumni opens historical Finance read-only.

The walkthrough is documentation authority only and is not executed during clinic closure.

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
