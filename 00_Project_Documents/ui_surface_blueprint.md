# TALA UI Surface Blueprint

## Purpose and Authority

This blueprint is the canonical UI authority for the TALA MVP. It defines user-visible capabilities, navigation, states, information hierarchy, interaction patterns, responsiveness, accessibility, outputs, and acceptance traceability independently of any design tool or implementation structure.

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
## Canonical UI Status and Evidence Boundary

**Status:** Canonical UI authority complete and aligned to standalone PRDs 01–06, including explicit component dispositions, brand roles, fixed CSV contracts, complete printable outputs, and the optional Quick-tour boundary.

The Canonical UI Surface Coverage Inventory is the implementation-coverage contract. Its entries represent required user-visible capabilities and acceptance evidence, not one mandatory route, Laravel page, Livewire component, Filament Resource, modal, or design frame each. Related entries may share one workbench through tabs, selected-record panels, contextual actions, dialogs, outputs, or shared states when ownership and behavior remain explicit.

Current application pages, schema-shaped resources, legacy screenshots, archived UI material, and visual alternatives remain implementation or presentation evidence. A later vertical slice may retain or consolidate a surface only after proving that its role, source record, action, state, and responsive/accessibility behavior conform to the owning PRD and this blueprint. No file-presence or visual similarity creates product authority.
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

Every entry in the Canonical UI Surface Coverage Inventory has one implementation disposition:

| Disposition | Use |
|---|---|
| `NativeFilament` | Filament resources, Pages, Tables, Forms, Infolists, Tabs, Sections, Wizards, Actions, filters, notifications, or an ordinary composition of them satisfy the behavior |
| `InstalledCompatibleDependency` | An already-installed compatible dependency fills one bounded capability that native Filament cannot provide alone |
| `FocusedTALACustom` | A small TALA-owned Blade, Livewire, print, visualization, preview, or failure component is necessary and reuses native primitives where practical |
| `PurposefullyExcluded` | The interaction is unnecessary, unsafe, externally owned, or deliberately outside the MVP; no placeholder page or generic subsystem is created |

These dispositions describe responsibility, not route or class count. A custom Filament Page composed entirely from native Sections, Tables, Forms, Tabs, Infolists, and Actions remains `NativeFilament`. `FocusedTALACustom` is reserved for behavior or rendering that native primitives cannot express alone. New plugins remain last resort after native Filament, an installed compatible dependency, and a focused TALA component have each been shown insufficient.

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

### Deterministic workspace entry

TALA gives every role a complete starting state without adding a Staff dashboard or global work queue.

| Role | First authorized destination | Starting-state and correction behavior |
|---|---|---|
| Public | Public Gateway | Closed or unavailable actions retain existing-account sign-in, bounded public guidance, and official support |
| Applicant | Applicant Home | The page leads with the current owner and next action and links to Registrar guidance without exposing Staff evidence |
| Student | Student Home | Source-labelled Enrollment, Academics, and Finance summaries remain separate; Profile is read-only |
| Registrar | Admissions | Persistent navigation reaches the five canonical Registrar workbenches |
| Accounting | Fee Plans | Persistent navigation reaches Student Accounts and its contextual clearance/action tabs |
| Faculty | My Availability | Persistent navigation reaches My Schedule and Grade Rosters |
| Academic Head | Academic Oversight | Every drill-in remains read-only and source-owned |
| System Administrator | Users & Access | Persistent navigation reaches Public Content, System Health, and Governance & Audit |

A single-role Staff account enters its fixed destination directly. A multi-role Staff account chooses an authorized role context and then enters that role's fixed destination. A role switch always resolves a fresh authorized destination and never carries a prior role's record route. After first official Student activation, Applicant no longer appears in the normal chooser, while the underlying application remains retained Registrar evidence.

## Shared Authenticated Shell and Navigation Authority

### Workspace shell

At 1024 CSS pixels and above, authenticated workspaces use a persistent left navigation, a top bar, and one main-content region. Below 1024 pixels, the left navigation becomes a labelled modal drawer opened from the top bar. TALA does not add role-specific bottom navigation.

The top bar contains only the TALA brand, current workspace/role, an explicitly selected Term context when the owning page requires it, the multi-role workspace switcher when applicable, Account Security, and sign-out. Primary navigation order is exactly the Panel and Navigation Map above and remains stable across pages. The first focusable control is **Skip to main content**.

The shell uses a semantic `header`, one labelled primary `nav`, `main`, and a labelled account menu. Opening the mobile drawer moves focus into it; Tab remains contained while it is modal; Escape closes it where safe; and closing returns focus to the trigger. The current destination is expressed in text and `aria-current`, never by color alone. Hiding navigation never authorizes or deauthorizes a route, query, action, download, or output.

TALA adds no global search. Primary navigation, source-owned contextual links, workbench search, and owning-page links provide alternative paths. A Wizard or guided process owns its own sequence; global navigation and breadcrumbs do not imitate process steps.

### Breadcrumbs, contextual back links, and location

- Public Gateway, Applicant Home, Student Home, each Staff role's first destination, and primary workbench landing pages do not show breadcrumbs.
- A genuinely hierarchical Staff detail or setup page shows `Workspace > Primary destination > Current record` in a labelled breadcrumb navigation with an ordered list and `aria-current="page"` on the current item.
- Wizard and process steps never use breadcrumbs as progress indicators.
- Learner COR, acknowledgment, historical output, and record-detail surfaces use a named **Back to [owning page]** link instead of a Staff-style hierarchy.
- At narrow widths, a long breadcrumb becomes one semantic parent link followed by the current H1. It never becomes a browser-history-only **Back** action.

### Page-title and action hierarchy

Every page uses this order:

1. Browser title: `[Page] | [Workspace] | TALA`.
2. Exactly one H1 describing the page purpose.
3. Optional context line containing only relevant record, Term, Program, state, owner, source/as-of time, or deadline facts.
4. Exactly one state-valid primary action. On learner mobile views it enters normal flow below the heading and may become full width inside the 16-pixel content inset.
5. Secondary actions in an Action Group; destructive or superseding actions are never the default primary action.
6. Failed readiness, action-needed explanation, or one safe next step before supporting data.
7. Search, filters, active filters, and result count before an operational queue or table.
8. Supporting evidence and immutable history after the current decision.

Controls retain a distinct border, fill, or stable action zone and never look like adjacent static content. Grouping uses spacing before additional rules: gaps between sections are at least twice the gap between tightly related items. Content aligns to shared leading edges and uses logical start/end behavior. Long labels, references, and translated strings wrap without clipping actions.

### Optional Quick tour

Authenticated Applicant, Student, and each Staff role may use one short, role-aware **Quick tour** implemented with the installed Driver.js 1.4.0 dependency plus a small TALA wrapper. Public visitors receive no tour. On the first successful entry for a credential, authorized role, and tour version, TALA shows a non-blocking invitation; it never opens the overlay automatically. **Quick tour** remains replayable from the account menu. Dismissal or completion suppresses only that role and version's invitation.

The static steps cover only the current workspace/role, canonical navigation, explicit Term context when present, owner/status/next-action presentation, the page's primary-action location, and Account Security/replay. The tour never navigates, switches a Term or role, opens the mobile drawer, enters data, explains private record contents, or performs an institutional mutation. Before starting, the wrapper removes steps whose targets are not present or authorized; it does not rely on a newer missing-target option. If no usable step remains, ordinary navigation continues and no completion preference is recorded.

The tour uses visible **Next**, **Previous**, **Finish**, and **Close** controls; Escape closes; focus remains within the named dialog and returns to the invitation or replay control. Screen readers receive the title, description, and **Step x of y** progress in meaningful order. At mobile widths, the visible drawer trigger may be highlighted but the drawer is not programmatically opened; a target-free centered explanation may replace a hidden desktop target. With reduced motion, animation and smooth scrolling are disabled. The tour sends no third-party request, captures no DOM or record content, records no grade, application, finance, or identity value, and adds no analytics. Failure changes neither business state nor the dismissal/completion preference.

No onboarding checklist, dashboard, tour editor, database-driven workflow builder, or new plugin is introduced. Later implementation acceptance must qualify Driver.js 1.4.0 with keyboard, NVDA or equivalent desktop screen reader, TalkBack or equivalent mobile screen reader, 360/390 mobile, and reduced-motion behavior; inability to pass that bounded contract reopens only the tour disposition and never blocks ordinary workspace use.

## Visual Foundation and Implementation Authority

The canonical interface is light-first and uses the existing TALA blue/yellow identity. The approved yellow TALA star artwork is the product mark; the word **TALA** is rendered as live text rather than a separate raster wordmark. The approved Servitech/SIA crest is the institution mark. File presence, a legacy screenshot, or an existing template cannot substitute for approval of the underlying artwork.

### Color tokens

| Token | Value | Use |
|---|---|---|
| Brand primary | `#1D4ED8` | Primary actions, selected navigation, links on light surfaces |
| Brand strong | `#1E3A8A` | Emphasis and high-contrast brand surfaces |
| Brand accent | `#FACC15` | Small brand cue with dark text; never body text on white |
| Canvas | `#F8FAFC` | Application background |
| Surface | `#FFFFFF` | Forms, tables, panels, and print-safe content |
| Primary text | `#0F172A` | Headings and body text |
| Muted text | `#475569` | Secondary explanation and metadata |
| Border | `#CBD5E1` | Structural separation and control boundaries |
| Success | `#166534` on `#F0FDF4` | Completed/available state with icon and text |
| Warning | `#92400E` on `#FFFBEB` | Pending/action-needed state with icon and text |
| Danger | `#991B1B` on `#FEF2F2` | Rejected/blocked/voided state with icon and text |
| Information | `#1E40AF` on `#EFF6FF` | Advisory state with icon and text |
| Focus ring | `#A16207` | Visible focus with offset and at least 3:1 component contrast |

All normal text meets 4.5:1 contrast; large text and non-text controls meet 3:1. Status always combines label, icon, and semantic context. No dark-mode requirement is introduced.

### Typography tokens

- Outfit at weights 600–700 is the display/heading face. Inter at weights 400, 500, and 600 is the body, control, table, amount, and identifier face. Both fall back to the system sans-serif stack.
- The type scale is 12, 14, 16, 18, 20, 24, and 30 CSS pixels. Text smaller than 12 pixels is not used.
- Body and learner input text remains at least 16 pixels at mobile widths. Dense Staff table text may be 14 pixels while retaining zoom/reflow and target requirements.
- Body line height is 1.5; headings use approximately 1.25. Wrapped text of three or more lines uses at least 1.4.
- Long explanatory prose is capped near 60–75 characters per line. Headings may use balanced wrapping; descriptions may use deliberate natural wrapping.
- Amounts, changing counts, identifiers, dates, and times use tabular numerals. Text remains selectable, and meaningful truncation always has an expanded or detail view.

### Layout and motion tokens

- Spacing uses 4, 8, 12, 16, 24, 32, and 48 pixels. Mobile content and full-width learner actions remain inset at least 16 pixels from the viewport edge and safe area.
- Corner radii use 6, 8, and 12 pixels. Nested surfaces use concentric radii rather than identical pinched corners.
- Borders carry structural hierarchy. Shadows are reserved for drawers, menus, and dialogs that genuinely float above content.
- Routine state changes use no decorative entrance animation. Necessary feedback is limited to 150–200 ms opacity or transform transitions, never `transition: all`, and respects reduced-motion preference.
- Heroicons Outline is the one interface icon family. Authenticated workspaces use Filament's PHP `Heroicon` abstraction; the separately declared npm Heroicons package gains no independent responsibility. Icon stroke weight matches adjacent text and active states use color/fill without requiring a separate asset.
- Qualification frames are 390×844 and 360×800 for learner mobile, 768×1024 for intermediate review, and 1366×768 for dense Staff work.

### Brand-mark and print roles

- Public and authentication surfaces show institution identity together with the TALA star and live **TALA** wordmark. The star may be friendly and prominent there, but it never competes with the page's task.
- Authenticated Applicant, Student, and Staff shells use a 32 CSS-pixel TALA star with the live workspace name. Dense navigation and workbenches do not repeat the institution crest or decorative mascot treatment.
- The favicon and install/app icon use the approved star-only artwork.
- Official and institutional printable outputs lead with the approved institution crest and institution name. They do not use the mascot; a restrained **Generated through TALA** text footer may identify the product.
- The TALA star is never rendered below 24 CSS pixels, is normally 32 pixels in the authenticated shell, and is at least 48 pixels on public/authentication surfaces. The institution crest is at least 48 CSS pixels on screen and 18 mm high on print, preserves its aspect ratio, and has a qualified monochrome-safe rendering.
- When adjacent visible text already identifies TALA or Servitech/SIA, the image is decorative and uses an empty text alternative. A standalone product or institution mark receives the matching accessible name. No interface uses the filename as alternative text.
- Failure pages use system fallbacks and do not depend on Vite or Livewire. Failure pages and printable outputs do not depend on remotely loaded fonts or a decorative background to communicate identity or status.

## Reusable Component Authority

The implementation and any design artifact use these named component families and variants:

| Family | Required variants and annotation |
|---|---|
| Shell | Public, authenticated desktop, authenticated mobile drawer, Applicant, Student, and Staff role contexts |
| Navigation | Top bar, sidebar, drawer trigger/panel, workspace switcher, account menu, default/current/disabled navigation item |
| Location | Breadcrumb, contextual Back link, browser/page-title example |
| Page header | Title/context, one primary action, secondary Action Group, no-action/read-only variant |
| Status and metadata | Status badge with icon/text; owner, source, version, as-of time, deadline, and immutable marker |
| Guidance | Next-action banner, failed-first readiness list, safe explanation, and responsible-office path |
| Workbench | Tabs, search/filter bar, active filters, result count, queue table, responsive labelled card, and row Action Group |
| Form | Field group, visible label/help, required/optional state, upload/evidence preview, error summary, and Wizard stepper |
| Read-only evidence | Infolist/summary, activity timeline, version history, and output-access evidence |
| Scheduling | Weekly timetable plus equivalent accessible meeting table and result/failure summary |
| Feedback | Alert, toast, empty, filtered-empty, loading, stale, inaccessible, unavailable, validation, and failed-action state |
| Dialog | Named confirmation, consequence summary, exact confirm label, contained focus, Escape behavior where safe, and focus return |
| Output | Authenticated screen summary and print-safe document frame |

Each component contract records semantic role, accessible name, heading relationship, focus order, keyboard behavior, minimum 24×24 target, preferred 44×44 learner target, responsive transformation, error/status announcement, and any screen-reader-only text. Tabs require arrow-key movement; dialogs require initial and returned focus; errors require summary focus and field association. No action depends on drag, hover, color, motion, or a pointer.

### Shared validation, conflict, evidence, and confirmation patterns

**Validation summary.** On failed submission, focus moves to a page-level summary that states that nothing was recorded and links to each invalid field. Each field retains its safe input, visible label, constraint, associated error, and required/optional status. Cross-record errors name the authoritative source and responsible owner rather than inventing a local override.

**Stale or concurrent conflict.** A stale mutation closes any success/loading state, announces **Nothing changed — newer information is available**, names the changed source/version and as-of time, preserves safe uncommitted text where possible, and offers **Review latest information**. Academic, financial, security, publication, enrollment, and output facts are never silently merged.

**Private evidence.** Upload controls accept one PDF, JPEG, or PNG up to 10 MiB per evidence version, state that access is restricted, show upload/scan/validation progress without implying acceptance, and distinguish replace from delete. After authoritative use, replacement creates a successor and no delete action appears. Preview and download require purpose-scoped authorization and access evidence.

**Authority metadata.** Every consequential detail and confirmation shows its owner and source/reference; it also shows the effective version/date, as-of time, and immutable/superseded state whenever those facts exist for the record. Missing authority states what remains usable, why the action is unavailable, responsible office, and exact reopening condition.

**Critical-action confirmation.** The shared `alertdialog` has a specific accessible name and contains: record/version; actor/authority; exact resulting state; affected roles, records, emails, and outputs; reversibility or successor requirement; required administrative reason/authority fields; and an exact button label. Initial focus goes to the dialog heading or first invalid required field, focus remains contained, Escape/cancel is available when safe, and focus returns to the trigger. Failure records no institutional mutation and announces whether input was retained.

| Owning area | Material actions covered | Primary/secondary placement and exact consequence | Shared state and responsive behavior |
|---|---|---|---|
| PRD 01 | Change email/password/MFA; invite/resend Staff; role change; disable/reactivate; MFA reset; publish/unpublish content | One current security/access/publication action is primary on its detail page; alternatives remain in the Action Group. Confirmation names sessions, workspaces, public visibility, invalidated links/codes, and email effects | Rate-limit, expired-token, duplicate-safe, final-admin, stale, mail-failed, and inaccessible variants; mobile uses one-column forms and full-width learner actions |
| PRD 02 | Submit/discard/withdraw/reopen Application; request/resubmit correction; publish/extend/close/cancel Cycle; decide/supersede; verify/reverse credential result | Applicant page owns submit/withdraw; Applicant Record owns review/decision; Cycle detail owns publication. Confirmation names snapshot, deadline, reopened fields, readiness, Applicant message, and no Student creation | Wizard preserves safe steps; overdue remains action-needed; identity duplicate remains non-disclosing; evidence/upload, stale Cycle, filtered-empty queue, and mail failure use shared variants |
| PRD 03 | Activate/retire academic authority; confirm/cancel offering; generate/retry; accept/reject candidate; publish/revise timetable | Setup/detail pages own activation; Generate & Review owns run/candidate actions; Published Timetable owns publication. Confirmation names source snapshot, affected classes/roles, quality/impact, email, and immutable output | Dense Staff tables transform to labelled cards where practical; timetable has accessible table alternative; infeasible/unknown/model-invalid/technical/stale states stay distinct |
| PRD 04 | Confirm/assist/cancel proposal; finalize enrollment; adjust; Course Drop | Learner Enrollment owns confirmation; Registrar selected Case owns assisted/cancel/finalize/change actions. Confirmation lists courses, units, meetings, capacity, finance readiness, Student activation, rosters, COR, and Accounting review | Mobile learner shows checkpoints then one next action; Staff keeps queue plus selected evidence; shortage, expiry, stale source, unavailable assessment, failed atomic finalization, and inaccessible variants state whether anything changed |
| PRD 05 | Submit/return/release roster; INC completion/deadline amendment; correction; external result; lifecycle/academic decision; graduation/conferral; TOR issue/void/replace | Faculty roster owns submit; Grades & Completion owns release/corrections/decisions; TOR detail owns outputs. Confirmation names rows/result effects, averages/curriculum/enrollment, deadlines, lifecycle, template/source, and successor history | No partial roster/average/output state; every INC has `CompletionOpen` or `CompletionOverdue` until resolved and never auto-converts; an overdue unextended INC remains no-credit with a retake path; factual advising replaces automatic sanctions; mobile Student is read-mostly |
| PRD 06 | Publish/supersede Fee Plan; record assessment/coverage/payment; reject/reverse; TOR clearance; export | Fee Plan detail and Student Account detail own mutations; exception/clearance tabs own resolution; exports remain contextual. Confirmation names exact PHP amounts, account/Assessment, remaining requirement, email/output, and append-only effect | Amounts use tabular numerals; private proof is purpose-scoped; pending/mismatch/duplicate/stale/unknown/output-failure variants never imply posting, health, refund, or tax-document authority |

Routine save, search, filter, pagination, tab selection, read-only projection, preview, and calculation actions never receive a redundant confirmation. All material actions use the owning PRD's authorization, validation, retry/deadline, idempotency, and audit contract in addition to this presentation mapping.

## Canonical UI Surface Coverage Inventory

The inventory uses the columns below. `J1`–`J7` refer to the seven representative journeys in the next section; `Support` is a reachable supporting capability rather than a separate end-to-end journey. The count is not authoritative and may change when redundant presentation surfaces are consolidated or missing coverage is identified. Consolidation must not hide ownership, permissions, source evidence, actions, failures, responsive behavior, or accessibility, and must not recreate a generic dashboard.

| Coverage ID | Role/workspace | User-visible surface | Parent entry | Component disposition | Source PRD | Authoritative source | Primary action | Output | Required state/correction coverage | Responsive/print requirement | Acceptance journey |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `SHR-001` | Public | Public Gateway | Direct URL | `FocusedTALACustom` | PRD 01 | Published public content and current Admission Cycle projection | Start application or sign in | — | Closed/unavailable entry retains safe guidance | 390, 360, 1366 | J1 |
| `SHR-002` | Shared identity | Registration and contextual sign-in | Gateway or owning journey | `NativeFilament` | PRD 01 | Person, account, verification state | Register or sign in | Verification request | Duplicate identity, invalid credentials, rate limit | 390, 360 | J1 |
| `SHR-003` | Shared identity | Verification, recovery, reset, and MFA | Secure message or sign-in | `NativeFilament` | PRD 01 | Verification/recovery challenge | Verify or recover access | Security notice | Expired, consumed, invalid, or failed challenge | 390, 360 | J1 |
| `SHR-004` | Multi-role Staff | Workspace chooser | Successful sign-in | `NativeFilament` | PRD 01 | Current role assignments | Enter selected workspace | — | Zero authorized context or stale assignment | 390, 1366 | J1 |
| `SHR-005` | Authenticated | Account Security | Top bar account menu | `NativeFilament` | PRD 01 | Account security state | Update password or MFA | Security notice | Reauthentication or MFA failure | 390, 1366 | Support |
| `SHR-006` | Shared | Access and service failure | Any protected route | `FocusedTALACustom` | Baseline | Authorization and locally known service state | Return to authorized entry | — | Inaccessible, expired session, limited, unavailable | 390, 1366 | J1 |
| `APP-001` | Applicant | Applicant Home | Applicant fixed entry | `NativeFilament` | PRD 02 | Application and readiness projection | Continue current next action | — | No open cycle, correction required, withdrawn | 390, 360 | J2 |
| `APP-002` | Applicant | Five-step Application Wizard | Applicant Home | `NativeFilament` | PRD 02 | Application draft/version | Save and continue or submit | Submitted snapshot | Validation, stale draft, failed submission | 390, 360 | J2 |
| `APP-003` | Applicant | Requirements | Applicant Home or Wizard | `NativeFilament` | PRD 02 | Published Requirement Set and evidence | Submit or replace evidence | — | Missing, rejected, superseded, inaccessible evidence | 390, 360 | J2 |
| `APP-004` | Applicant | Application acknowledgment | Applicant Home | `FocusedTALACustom` | PRD 02 | Submitted Application version and its immutable Requirement Set version | Download acknowledgment | Application Acknowledgment | Superseded snapshot remains labelled; generation failure creates no artifact | 390, print | J2 |
| `STU-001` | Student | Student Home | Student fixed entry | `NativeFilament` | Baseline | Source-owned Enrollment, Academics, Finance, and Examination Period projections | Open highest-priority safe action | — | Stale or unavailable source remains labelled; no inferred examination date | 390, 360, 768 | J3/J4/J5/J6 |
| `STU-002` | Student | Enrollment | Student Home | `NativeFilament` | PRD 04 | Registration Case, proposal, placement, readiness | Confirm or resolve current checkpoint | Current COR link | No proposal/class/assessment, expired reservation | 390, 360 | J4 |
| `STU-003` | Student | Academics | Student Home | `NativeFilament` | PRD 03/05 | Released results, external competency results, academic projections, and Examination Period | Open result or correction guidance | Unofficial academic record | Grades not complete; INC completion open/overdue/amended/resolved; external result not recorded; unavailable examination period | 390, 360, 768 | J3/J5 |
| `STU-004` | Student/alumni | Finance | Student Home | `NativeFilament` | PRD 06 | Term Account and current projection | Pay exact due or submit evidence | SOA, Payment Acknowledgment | Pending/mismatch/stale source; alumni read-only | 390, 360, 768 | J6 |
| `STU-005` | Student | Profile | Student Home | `NativeFilament` | PRD 04 projection | Official identity/program/curriculum/contact facts | Follow correction guidance | — | Missing/stale source; no direct official edit | 390, 360 | Support |
| `STU-006` | Student | Current and historical COR | Enrollment | `FocusedTALACustom` | PRD 04 | Immutable COR versions | Open or print selected version | COR | Superseded version or generation failure | 390, print | J4 |
| `REG-A01` | Registrar | Admissions | Registrar fixed entry | `NativeFilament` | PRD 02 | Applications and action queue | Open next application | — | Initial/filtered empty, stale queue | 1366 | J2 |
| `REG-A02` | Registrar | Applicant Record | Admissions | `NativeFilament` | PRD 02 | Application/evidence/decision history | Request correction or record decision | Decision/credential result | Unauthorized, stale, superseding decision | 1366, 768 read-only | J2 |
| `REG-A03` | Registrar | Admission Cycles and readiness | Admissions | `NativeFilament` | PRD 02 | Admission Cycle and Requirement Set | Publish cycle | — | Failed readiness or competing publication | 1366 | J2 |
| `REG-C01` | Registrar | Catalog & Curricula | Registrar navigation | `FocusedTALACustom` | PRD 03 | Program, Course Revision, Curriculum Version, external competency requirement | Preview/import Draft authority or activate valid successor | Import findings CSV | File/header/row/authority/readiness blocker; no inferred completion treatment | 1366 | J3 |
| `REG-T01` | Registrar | Term Planning Overview | Registrar navigation | `NativeFilament` | PRD 03 | Term Calendar Package, Examination Period, and planning readiness | Open first failed checkpoint | — | Missing/stale calendar authority; examination period unavailable | 1366 | J3 |
| `REG-T02` | Registrar | Cohorts & Classes | Term Planning | `NativeFilament` | PRD 03 | Term Cohort and Class Offering | Add or correct offering | — | Missing Additional-offering authority | 1366 | J3 |
| `REG-T03` | Registrar | Teaching Resources | Term Planning | `NativeFilament` | PRD 03 | Faculty, room, availability, meeting pattern | Resolve resource blocker | — | Missing/incompatible resource | 1366 | J3 |
| `REG-T04` | Registrar | Generate & Review | Term Planning | `FocusedTALACustom` | PRD 03 | Solver request/result and candidate | Generate or validate candidate | Candidate evidence | Infeasible, Unknown, ModelInvalid, TechnicalFailure | 1366 | J3 |
| `REG-T05` | Registrar | Candidate correction | Generate & Review | `NativeFilament` | PRD 03 | Candidate meeting version | Apply valid bounded correction | Revalidated candidate | Invalid replacement or stale candidate | 1366 | J3 |
| `REG-T06` | Registrar | Published Timetable and revision | Term Planning | `FocusedTALACustom` | PRD 03 | Published Timetable Version | Publish or record affected revision | Published Timetable | Failed impact/readiness, stale publication, or visibly superseded output | 1366, 768 read-only, A4 landscape print | J3 |
| `REG-E01` | Registrar | Students & Enrollment | Registrar navigation | `NativeFilament` | PRD 04 | Registration Cases and readiness queue | Open next actionable case | — | Empty/stale/action unavailable | 1366 | J4 |
| `REG-E02` | Registrar | Enrollment case and proposal | Students & Enrollment | `NativeFilament` | PRD 04 | Registration Case and proposal versions | Prepare/revise/finalize valid case | Enrollment and COR result | Failed prerequisite, placement, assessment, clearance | 1366 | J4 |
| `REG-E03` | Registrar | Shortage and timetable-impact context | Students & Enrollment | `NativeFilament` | PRD 04 | Placement/reservation and timetable impact | Resolve affected case | Updated learner projection | No valid class or expired reservation | 1366 | J4 |
| `REG-E04` | Registrar | Adjustment and Course Drop | Enrollment case | `NativeFilament` | PRD 04 | Authorized change and successor versions | Apply authorized change | Successor COR when applicable | Additional clearance or Accounting review pending | 1366 | J4 |
| `REG-G01` | Registrar | Grades & Completion | Registrar navigation | `NativeFilament` | PRD 05 | Roster/release/progress/completion queues | Open next actionable record | — | Empty/stale/unauthorized action | 1366 | J5 |
| `REG-G02` | Registrar | Grade release, INC, and correction detail | Grades & Completion | `NativeFilament` | PRD 05 | Final result, deadline/amendment, and correction chain | Release open completion, amend deadline, show retake guidance, or supersede result | Released result | Completion overdue, deadline/result race, stale result | 1366, 768 read-only | J5 |
| `REG-G03` | Registrar | Progress and lifecycle detail | Grades & Completion | `NativeFilament` | PRD 05 | Curriculum progress and lifecycle result | Record authorized decision | Updated projection | Missing source or consequential pending state | 1366 | J5 |
| `REG-G04` | Registrar | Completion and conferral | Grades & Completion | `NativeFilament` | PRD 05 | Completion review and conferral record | Confirm authorized completion outcome | Completion result | Missing requirement or authority | 1366 | J5 |
| `REG-G05` | Registrar | TOR preview and issuance history | Grades & Completion | `FocusedTALACustom` | PRD 05/06 | Transcript snapshot, TALA Standard TOR version, signatory data, clearance | Preview or issue | Issued TOR | Missing source/signatory/clearance; generation failure; void/replacement/supersession | 1366, print | J5 |
| `REG-G06` | Registrar | Verified external competency result | Grades & Completion | `NativeFilament` | PRD 03/05 | Active external requirement and external assessment/certification evidence | Record verified result | Updated curriculum evaluation | Missing/stale requirement or evidence; successor preserves prior attempt | 1366, 768 read-only | J5 |
| `ACC-001` | Accounting | Fee Plans | Accounting fixed entry | `NativeFilament` | PRD 06 | Fee Plan versions | Create draft or publish valid plan | — | Incomplete/reconciled/competing publication blocker | 1366 | J6 |
| `ACC-002` | Accounting | Fee Plan detail | Fee Plans | `NativeFilament` | PRD 06 | Selected Fee Plan version | Save draft or publish | Published immutable version | Stale or failed readiness | 1366 | J6 |
| `ACC-003` | Accounting | Student Accounts tabs | Accounting navigation | `NativeFilament` | PRD 06 | Account, exception, and TOR-clearance queues | Open next actionable record or export current context | Account Status CSV; Verified Payments CSV from selected account context | Empty/stale/filter/export failure | 1366 | J4/J6 |
| `ACC-004` | Accounting | Student Account detail | Student Accounts | `NativeFilament` | PRD 06 | Term Account, Assessment, Coverage, postings | Record authorized account action | SOA | Assessment unavailable/stale; failed posting | 1366 | J4/J6 |
| `ACC-005` | Accounting | Authorized individual assessment | Account detail | `NativeFilament` | PRD 06 | Registration/change version and Accounting authority | Record exact assessment | Successor AssessmentVersion | Unauthorized, unreconciled, or stale source | 1366 | J4 |
| `ACC-006` | Accounting | Approved Coverage action | Account detail | `NativeFilament` | PRD 06 | Coverage authority and current obligation | Record valid coverage | Updated clearance projection | Excess, conflict, stale, unsupported authority | 1366 | J4 |
| `ACC-007` | Accounting | Payment Exception | Student Accounts | `NativeFilament` | PRD 06 | Evidence/attempt exception | Verify actual amount or reject safely | Payment result when verified | Mismatch, duplicate, reversal, no posting | 1366 | J6 |
| `ACC-008` | Accounting | TOR Clearance | Student Accounts | `NativeFilament` | PRD 06 | Request-specific clearance projection | Record Cleared or NotRequired basis | Clearance result | ActionNeeded or invalid authority | 1366 | J5 |
| `FAC-001` | Faculty | My Availability | Faculty fixed entry | `NativeFilament` | PRD 03 | Availability declaration | Submit declaration | — | Missing term request, stale assignment | 1366, 768 | J3 |
| `FAC-002` | Faculty | My Schedule | Faculty navigation | `NativeFilament` | PRD 03 | Published Timetable and informational Examination Period projections | Review current/revised schedule | Faculty schedule | Affected revision, unavailable publication, or unavailable examination period | 1366, 768 | J3 |
| `FAC-003` | Faculty | Grade Rosters and detail | Faculty navigation | `NativeFilament` | PRD 04/05 | Current official roster and submitted result version | Submit results or use a contextual roster action | Submission result; current Class Roster print/CSV | Empty/incomplete/returned/stale roster; export failure; INC completion open/overdue | 1366, 768, A4 portrait operational print | J5 |
| `AHD-001` | Academic Head | Academic Oversight | Academic Head fixed entry | `NativeFilament` | PRD 03–05 projections | Source-owned calendar, external competency, and attention evidence | Open read-only source drill-in | — | No item, stale/missing source, no mutation | 1366, 768 | J3/J5 |
| `SYS-001` | System Administrator | Users & Access and account detail | System Administrator fixed entry | `NativeFilament` | PRD 01 | Accounts, roles, invitations, MFA state | Invite or apply authorized account action | Security notice | Final-administrator protection, invitation/MFA failure | 1366 | J1 |
| `SYS-002` | System Administrator | Public Content | System Administrator navigation | `NativeFilament` | PRD 01 | Versioned public content | Publish valid content | Public projection | Stale/scheduled/failed publication | 1366 | J1 |
| `SYS-003` | System Administrator | System Health | System Administrator navigation | `NativeFilament` | PRD 06/Architecture | Locally recorded service evidence | Refresh local evidence | — | Unknown/Not checked by TALA/degraded | 1366, 768 | J7 |
| `SYS-004` | System Administrator | Governance & Audit | System Administrator navigation | `NativeFilament` | PRD 06/Architecture | Institutional changes, events, output access | Filter or inspect evidence | — | Inaccessible evidence; automatic disposal not provided | 1366, 768 | J7 |
| `OUT-001` | Applicant | Application Acknowledgment print | Application acknowledgment | `FocusedTALACustom` | PRD 02 | Submitted Application version and its immutable Requirement Set version | Print/save | Application Acknowledgment | Superseded or generation failure | A4 portrait print | J2 |
| `OUT-002` | Authenticated roles | Published Timetable print | Published Timetable | `FocusedTALACustom` | PRD 03 | Published Timetable Version | Print/save | Published Timetable | Superseded/unavailable version | A4 landscape print | J3 |
| `OUT-003` | Student/Registrar | COR print/history | Enrollment or case | `FocusedTALACustom` | PRD 04 | COR Version | Print/save | COR | Superseded version or generation failure | A4 portrait print | J4 |
| `OUT-004` | Student | Unofficial academic record | Academics | `FocusedTALACustom` | PRD 05 | Released academic-record projection as of generation | Print/save | Unofficial Student Record | Incomplete/stale source or generation failure | A4 portrait print | J5 |
| `OUT-005` | Registrar | TALA Standard TOR | TOR preview/history | `FocusedTALACustom` | PRD 05 | Transcript snapshot, template version, and issuance state | Preview, issue, or print authorized state | TOR | Preview/issued/voided/replaced/superseded/generation failure | A4 portrait print | J5 |
| `OUT-006` | Student/Accounting | Account Statement/SOA | Finance or Account detail | `FocusedTALACustom` | PRD 06 | Term Account as-of projection | Generate/print | SOA | Stale source or no partial artifact | A4 portrait print | J6 |
| `OUT-007` | Student/Accounting | Payment Acknowledgment | Finance or Account detail | `FocusedTALACustom` | PRD 06 | Verified payment posting | Open/print | Payment Acknowledgment | Reversed/superseded/generation failure | A4 portrait print | J6 |

Routine empty, loading, and validation states use shared patterns instead of separate coverage entries. A dedicated entry is required only when a state changes meaning, permitted action, cross-role outcome, or official-output status.

### Implementation organization freedom

The implementation may combine related coverage entries in one source-owned workbench, tab set, contextual detail, dialog, or output route. Names must remain semantic and role-owned. Consolidation cannot remove a canonical navigation destination, hide a required state/action, or turn unrelated responsibilities into a generic dashboard, Reports, Settings, Approvals, or Readiness Center.

## Representative Journey and Acceptance Contract

The UI authority requires every primary destination to remain reachable and defines these seven end-to-end journeys for design review and later browser acceptance:

1. Public entry → registration/verification → sign-in → role selection → authorized entry or inaccessible recovery.
2. Application → submission/correction → decision → official credentials → Ready for Enrollment.
3. Academic and curriculum authority, including a tracked-only external-competency requirement → calendar readiness and role-consistent Examination Period/unavailable state → timetable generation failure/correction → valid candidate → publication/revision.
4. Registration → proposal/placement → assessment/coverage/payment → official enrollment → Student activation → COR.
5. Grade submission/return/release → partial-term and INC completion-open/overdue/amendment/resolution branches → verified external competency `NotYetCompetent`/successor `Competent`/tracked-only absence → correction → completion → TALA Standard TOR issuance.
6. Fee Plan or exact assessment → evidence/PayMongo pending/mismatch → verified posting → SOA/acknowledgment → reversal.
7. System Health local evidence/Unknown state → Governance & Audit output access and the explicit no-automatic-disposal boundary.

Each journey identifies the starting persona and preconditions, authoritative source/version, action, visible evidence, one failure, correction path, cross-role result, output, and pass condition. It uses only canonical synthetic records and `example.test` identities. A design artifact may assist review but is never a product or implementation gate.

### Student Home — shared projection

Student Home is a source-owned priority-status page, not a card dashboard or global-hold summary. It shows, in order: Student identity and current term; the single highest-priority safe action; current Enrollment, Academics, and Finance summaries with source owner/as-of time; the approved informational Examination Period when available; upcoming accepted deadline or obligation; and contextual links. It never merges domain state or invents a universal learner status. Exact class examination arrangements remain Faculty-owned and are never inferred from class meetings.

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
│ Exam period  19–24 Oct · Registrar calendar · as of 10:25 │
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
│ Program/Curriculum  IT · CUR-IT-2026                      │
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

Academic Oversight orients the Academic Head without granting universal approval authority. It groups source-owned readiness and exceptions under Academic Authority, Term Planning, Grades & Progress, and Lifecycle & Completion. It includes the informational Examination Period and external-competency requirement/result evidence without granting scheduling, assessment, certification, or academic-record mutation. Each row shows state, owner, source/version, as-of time, and a read-only link to the owning workbench.

```text
┌ Academic Oversight ───────────────────────────────────────┐
│ Term [2026 T1▼] Program [All▼]        [3 need attention] │
│ AREA                 STATE          OWNER       AS OF     │
│ Curriculum authority Ready          Registrar   09:40     │
│ Examination period  19–24 Oct      Registrar   09:42     │
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
| Application acknowledgment | Submitted Application and Requirement Set versions, stable reference, submitted summary, versioned requirements, physical-submission instructions, generation evidence, and no-admission/no-enrollment claim | Authenticated A4 portrait printable read-only view; never an admission certificate or proof of enrollment |

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
┌ Institution · APPLICATION ACKNOWLEDGMENT · Reference            ┐
├ Application version · Requirement Set version · submitted time  ┤
├ Applicant / cycle / program / path submitted facts              ┤
├ Versioned requirement list · method/state as of submission      ┤
├ Physical/official credential instructions                       ┤
└ Generation ref/time · Not admission or enrollment proof         ┘
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
| Acknowledgment | Unavailable before first submission; explanation links back to Application | A4 portrait view retains Application/Requirement Set version and output-generation labels without showing a false document | Superseded snapshot remains historical and clearly versioned; failure creates no artifact and returns to Application | Only the owning Applicant and authorized Registrar may view it; navigation/controls do not print |
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
| Catalog & Curricula | Record program authority, maintain Course Revisions, build the grouped Curriculum Version including authority-backed external-competency requirements, resolve import findings, and activate the externally approved version | Connected Filament workbench using Tables, Sections, Forms, Infolists, Actions, and one bounded CSV preview/import |
| Term Planning | Prepare the selected term, cohorts/classes, resources, candidate, publication, and revision in operating order | One selected-term Filament workbench with five Tabs and contextual source-record Actions |

Faculty receives **My Availability** and **My Schedule**. Academic Head receives read-only entry to Catalog & Curricula and Term Planning. System Administrator receives only locally evidenced solver status through Clinic 6 System Health. Student receives no Clinic 3 navigation; Clinic 4 projects the assigned official schedule after enrollment.

### Catalog & Curricula workbench

The workbench reading order is:

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

### Term Planning workbench

The selected-term header always shows term identity, state, current readiness, governing authority, current published version, and exactly one state-appropriate primary action. Context is selected before actions; no global action silently operates on an implicit term. The selector may show multiple concurrently active First, Second, or Special Terms and labels each by academic year, term type/display label, and state. Changing context never carries a Draft edit, filter with a different meaning, selected record, or pending action into another Term.

#### Overview

Show official dates, typed operational windows, weekly teaching grid, recurring breaks, dated exceptions, authority evidence, and failed-first readiness. The informational Examination Period shows inclusive Asia/Manila dates, approved display label, authority, package version, owner, and as-of time. The neutral `Enrollment` window displays its approved dates; Clinic 4 displays and applies its bounded learner applicability. A successful check collapses to **All required checks passed**. Date-less grid times are institutional Asia/Manila wall-clock values; timestamps retain their actual date/time meaning.

Registrar, Academic Head, Faculty, and Student projections use the same source. Missing or stale evidence shows **Examination period unavailable — contact Registrar or Faculty**. Exact class arrangements remain in the Faculty-owned teaching channel; no class-level date/time, scheduling action, email, generic event, or financial examination hold appears.

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

The official browser output is `FocusedTALACustom` and A4 landscape. It uses exactly one Published Timetable Version labelled `Published` or `Superseded` and shows institution identity, exact Academic Year and First/Second/Special Term, Term reference, authority, publication/generation times, version, role/filter context, and ordered day/time, Course, Class, authorized Faculty, mode, room/Online location, and revision marker. Continuation pages repeat Term/version and table headings. It is monochrome-safe, prints no navigation or controls, and uses restrained **Generated through TALA** footer text. A superseded version is visibly historical; stale/unavailable source or rendering failure creates no artifact and leaves the owning page available with retry/support guidance.

### Low-fidelity wireframes

```text
Catalog & Curricula
┌ Program · authority · effective status          [Primary] ┐
├ Courses and current revisions            [Search] [Filter] ┤
├ Curriculum Version · grouped by year and term              ┤
│ Code/title | Units | Requisites | Meeting | Mode | Ready   │
├ External competencies · treatment · authority · effective   ┤
├ Draft import findings and source comparison                 ┤
└ Activation readiness · evidence · next action               ┘
```

```text
Term Planning
┌ Term · state · readiness · authority · version     [Action] ┐
│ Overview | Cohorts & Classes | Teaching Resources           │
│ Generate & Review | Published Timetable                      │
├ Examination Period · dates · source/version · as-of          ┤
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
├ Examination Period · dates · Registrar source · as-of          ┤
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
| Overview | Missing calendar facts render failed readiness, never an empty success | Checks retain source/owner placeholders | Stale package/date action refreshes and requires reconfirmation; exam dates are never inferred | Inactive/missing exact Term prevents only that Term's downstream actions with source link; other active Terms remain usable; unavailable Examination Period names Registrar/Faculty recovery |
| Cohorts & Classes | No demand and no filter match are distinct; absence of classes is a blocker when curriculum demand exists | Forecast/source state is labelled | Source change refreshes Draft rows; published-impact action requires review | Other-role and implicit-term actions are inaccessible |
| Teaching Resources / My Availability | No declaration/resource shows responsible owner and due action | Declaration and blocker rows keep labels | Late/stale declaration records a new correction; failure preserves last authority | Faculty sees only own declaration/schedule; System Administrator cannot change academic facts |
| Generate & Review | No run explains readiness/start action; no candidate is distinct from an empty meeting set | One active run shows safe progress without fake completion | Stale source invalidates generation/publication action and links to source | Infeasible, Unknown, ModelInvalid, and TechnicalFailure each show distinct meaning/owner/recovery; no candidate can publish |
| Published Timetable / My Schedule | No published version states not official yet; filtered empty offers **Clear filters** | Version and table/view/loading and A4 generation states are labelled | Stale sign-off/revision/impact refreshes; failed publication/output creates no partial version/artifact; superseded print is visibly historical | Candidate data is inaccessible to Faculty/Students; Students receive only Clinic 4 official placement projection |

The Term Planning wireframe is the explicitly shared shell for Overview, Cohorts & Classes, Teaching Resources, Generate & Review, and Published Timetable. The dedicated failure, valid-candidate, Faculty, and published/revision frames provide the required state- and role-specific detail; Catalog & Curricula has its own direct frame.

### Cross-role, responsive, and failure behavior

- Registrar owns editable setup, candidate correction, publication, and revision.
- Academic Head sees read-only calendar and Examination Period, curriculum including external requirements, readiness, candidate evidence, and published timetable oversight.
- Faculty declares availability, reads assigned official meetings and the informational Examination Period, and sees affected revision history.
- Student sees only the Clinic 4-owned placed and officially enrolled schedule plus the informational Examination Period on Student Home/Academics.
- System Administrator sees solver-related System Health evidence without academic actions.
- Applicant, Accounting, and Public receive no Clinic 3 master-timetable access.

On mobile, grouped curriculum and resource rows stack with labels, the weekly view becomes a day-by-day list, filters remain in the native panel, and secondary actions stay in Action Groups. Result status includes text and screen-reader meaning and never depends on color. Empty, loading, inaccessible, stale-source, technical-failure, and no-candidate states all name what happened and the safe next action.

Concurrent-Term acceptance switches between an active prior/Special Term and the next active Term on desktop and mobile, announces the selected Term to screen readers, preserves an unambiguous heading and breadcrumb, and proves that each timetable, window, deadline, failure, print view, and action remains bound to the selected exact Term.

### Native component and communication decision

Native Filament Tables own queues and record lists; Infolists own immutable evidence; Forms own actual input; Sections and Tabs own progressive disclosure; Action Groups own secondary actions. No scheduling, calendar, dashboard, permissions, saved-filter, or generic import plugin is justified by Clinic 3. The bounded CSV preview/import and custom weekly view remain focused TALA components.

Email is limited to the Faculty availability action request, first publication to assigned Faculty, and one shared published-revision event. Clinic 3 owns the revision trigger and affected Faculty; Clinic 4 supplies affected officially enrolled Students and their updated schedule/COR context. Examination Period visibility and external-competency requirement changes create no email. Routine saves, readiness checks, generation, failure, and candidate correction use in-workspace feedback only.

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
5. Academic blockers, unavailable requirements, excluded dependent courses, any result-impact review, shortage state, and bounded completion outlook. An unreleased prerequisite shows the source course/Term, excluded dependent course, responsible Registrar office, and **This subject can be added only after the prerequisite grade is released and ordinary enrollment checks pass**.
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

Tabs are **Ready to prepare**, **Waiting for learner**, **Placement and shortages**, **Finance pending**, **Ready to finalize**, **Adjustments and Drops**, and **Official and history**. A result impact before finalization returns the exact case to **Ready to prepare**; after finalization it appears in **Adjustments and Drops** with the released source, affected course, owner, window/late-authority state, and next action. Counts are operational orientation, not a chart dashboard.

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

State-valid actions are **Prepare/revise proposal**, **Issue for confirmation**, **Record assisted confirmation**, **Place/change class**, **Finalize official enrollment**, **Record cancellation**, **Record adjustment**, **Record Course Drop**, and **Print current/historical COR**. An unreleased prerequisite creates no special action or permission form: the dependent course is excluded, and a later satisfying release enables the existing **Record adjustment** action subject to the ordinary window or exact late-adjustment authority plus learner confirmation, capacity, schedule, load, and Finance checks. The primary action stands alone; secondary actions use an Action Group. Invalid or stale actions remain server-rejected even when a crafted request bypasses the UI.

An academic-result impact panel uses plain language:

- **Enrollment proposal needs review** before finalization; the current proposal/placement is stale, Registrar owns the revision, and finalization is unavailable.
- **Registrar review is required for your enrollment** after finalization; the current enrollment and COR remain official until an authorized change is applied.
- **Adjustment period closed** when no current late authority exists; the panel names the responsible office, what remains usable, and the next permissible institutional path.

The panel never promises that a course will be added, removed, replaced, or retained. A current late-adjustment authority reveals the existing **Record adjustment** action only after capacity, conflicts, learner confirmation, timetable, load, and Finance readiness pass. Course Drop remains a separate action.

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
| Learner Enrollment | No current case offers a valid start only when eligible; completed/no-current-term state explains next boundary | Checkpoints and primary-action position remain stable | Stale proposal/window/placement/Finance/result source refreshes before action; failed action preserves current case | Authority-blocked, lifecycle-blocked, closed, or expired state identifies source, owner, usable remainder, and next path without exposing internal records |
| Registrar workbench / record | No cases and no filter matches are distinct; filters can be cleared | Tabs/counts/table/detail loading are labelled independently | Stale proposal, prerequisite-eligibility, result-impact, placement, finalization, adjustment, or drop action is rejected and current facts shown | Unauthorized roles and direct records are inaccessible without existence disclosure; closed Adjustment exposes no action without exact late authority |
| Placement and shortages | No shortage states **No unresolved shortage**; no alternatives names Clinic 3 owner/action | Capacity/reservation checks show bounded progress | Concurrency loss or expiry refreshes capacity and never oversubscribes | Learners cannot see other learners or internal capacity analytics |
| Accounting Clearance | No pending cases and filtered empty are distinct | Assessment, payment, and coverage facts show source loading | Missing, stale, unreconciled, or unauthorized assessment/coverage authority is `Unavailable`; a valid account with an unsatisfied current obligation is `ActionNeeded`; failed recording preserves safe input and never creates a fallback, silent cap, or false clearance | Accounting cannot change academic records, create identity, calculate a formula, determine funding eligibility, or finalize |
| COR current/history | Unavailable before official enrollment is explicit; missing historical version is an assurance fault | Print view reports loading without presenting a partial official document | Superseded version is labelled; print failure leaves authenticated view authoritative | Only owning learner and authorized Staff see COR; direct unauthorized access reveals nothing |

Clinic 4's four primary page families—learner Enrollment, Registrar Students & Enrollment, Accounting Enrollment Clearance, and current/historical COR—each have a direct wireframe above. Placement, shortage, adjustment, Course Drop, and timetable-impact actions remain contextual parts of the Registrar workbench rather than separate navigation pages.

Clinic 4 demonstration data includes ordinary published-plan enrollment, reduced and Individually Advised cases with selection-specific authority, changed-registration branches, and coordinated `REG-2026-ST-001`. That Special Term case consumes `TERM-2026-ST`, `CLS-ITE3-ST-A`, and Additional retake `CLS-IT201-ST-R`; excludes dependent `IT301` only; remains `Unavailable` until `ACT-2026-ST-001`; then shows PHP 2,000 Applied coverage plus PHP 1,000 verified payment as `Mixed` clearance before official enrollment and COR. The walkthrough must preserve the same references through Clinics 3–6 without creating a Summer, tutorial, irregular-student, scholarship, or accommodation workflow.

Clinic 4 also includes `REG-2026-0011`, whose unreleased prerequisite excludes only the dependent course until a satisfying release makes it eligible for the ordinary Adjustment path, plus `REG-2026-0012`, whose adverse post-enrollment grade correction opens one review. The latter proves open Adjustment, closed Adjustment with exact late authority, and closed Adjustment without authority on desktop/mobile and by keyboard/screen reader. Only an applied guarded change creates a new COR; duplicate result, failed email, stale Finance, capacity conflict, or unavailable late authority leaves the current enrollment/COR intact.

### Responsive, accessibility, failure, and communication behavior

Course and queue rows stack with labels on mobile; information order is unchanged; the primary action remains reachable; secondary actions remain in Action Groups. All controls are labelled, keyboard reachable, visibly focused, and announced with current status. Meaning never depends on color.

Loading, empty, stale, expired, inaccessible, 403, 404, 419, 429, validation, concurrency, and integration-failure states name what happened, the responsible owner, what remains usable, and a safe recovery action. A newly released or corrected prerequisite result is announced as an eligibility change and never as an automatic course add or removal. A failed checkpoint expands; a multi-check readiness surface whose checks all pass reduces them to **All required checks passed**.

Queued, idempotent email is limited to the continuing-Student enrollment-window notice, proposal ready/materially revised, payment or coverage action required, official enrollment/COR ready, reservation release/case expiry, and official adjustment/Course Drop. On first enrollment, the official-enrollment/COR message also explains that Student access is active; no separate activation email is sent. An affected timetable revision uses Clinic 3's one shared publication event, with Clinic 4 supplying affected enrolled-Student recipients and updated schedule/COR context. Routine saves, checks, navigation, and recurring reminders remain in-workspace only. Mail failure never rolls back enrollment or financial state.

### Native component decision

Native Filament Tables own queues/search/filters; Infolists and Sections own authoritative read-only detail; Forms own real input; Tabs provide the workbench projections; Action Groups hold secondary actions. The guided Enrollment page and authenticated COR print view are focused custom Pages composed from these primitives. Clinic 4 justifies no enrollment, workflow, waitlist, dashboard, PDF-generation, or policy-engine plugin.

## Clinic 5 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion UI Authority

> **Authority status — PRD 05 UI mapping complete.** This section translates the standalone PRD 05 into exact role surfaces and does not authorize implementation.

### Navigation and page inventory

| Role | Primary surface | Contextual destinations |
| --- | --- | --- |
| Faculty | **Grade Rosters** | Assigned official schedule and returned-roster history |
| Registrar | **Grades & Completion** workbench | Student Record, official Class Offering, curriculum and verified external-competency evidence, Clinic 6 clearance |
| Student | **Academics** | Examination Period, external-competency result, unofficial print view, and contextual Enrollment link whenever an initial release, INC resolution, or correction affects registration |
| Academic Head | Read-only Academic Oversight | Examination Period, external-competency, progress, correction, lifecycle, and conferral authority evidence |
| Accounting | Clinic 6 output-payment clearance only | Student Account; no grade or academic decision action |
| System Administrator | Queue, email, and System Health evidence only | Technical evidence without academic-record authority |

There is no Gradebook, Attendance, Period Grades, What-if Audit, Graduation Batch, Transcript Template, or academic-policy Settings navigation item.

The Clinic 5 visual comparison considered **role workbenches**, **Student-record first**, and **separate peer resources**. Role workbenches are accepted because complete-roster work remains class-centered for Faculty, cross-record decisions remain contextual in one Registrar workbench, and Students receive one coherent academic story. Student-record first obscures whole-roster submission; separate resources create navigation sprawl. Those alternatives are rejected rather than retained as hidden navigation.

### Faculty Grade Rosters

The native queue leads with course/class reference, program/cohort, official learner count, completed-result count, submission deadline, plain-language state, owner, and next action. Search covers class reference and course; filters cover term, state, and deadline/overdue state. Before release, Faculty may see that their assigned roster is one missing source for term-grade completeness; they never see another class's results or a learner's term/cumulative average through this context.

The roster table shows Student number, legal name, official enrollment state, one controlled final-grade/INC selector, derived academic result, and any validation or lifecycle explanation. Selecting `INC` always reveals the required completion note and previews the one-year deadline calculated from the official Term end. The designated submitter receives **Save draft** and **Submit complete roster**. Every assigned Faculty member with roster-view access receives **Print class roster** and **Export roster CSV** as contextual secondary actions; view-only co-Faculty still receive no edit or submit action. Returned-row correction, history, and evidence remain secondary actions.

```text
Grade Roster — IT 301 / IT 3A
┌ Due 18 Oct 2026 · 28/30 complete · You own submission      ┐
├ Student no. | Legal name | Enrollment | Final result | Note │
│ SIA-...     | ...        | Official   | [1.00–5.00/INC]     │
├ Validation / returned-row explanation                       │
├ Secondary: [Print class roster] [Export roster CSV]          │
└ [Save draft]                         [Submit complete roster]│
```

The print action opens an authenticated A4 portrait **CURRENT CLASS ROSTER — Operational reference** view; the CSV is a private initiating-actor download. Both use the selected Class Offering's current Clinic 4 membership, fixed identity/enrollment columns, legal-name/Student-number ordering, an Asia/Manila as-of time, formula-safe cells, role-derived purpose, and logged output access. Neither uses selected table rows, a column chooser, a second format, a bulk action, or a Reports destination. Neither contains grades, result notes, contact details, Applicant evidence, or finance data, and neither becomes an eighth canonical official output.

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
7. Collapsed immutable history, audit, and email evidence.

State-valid primary actions include **Release roster**, **Return specified rows**, **Release INC completion**, **Change INC deadline**, **Record authorized correction**, **Record verified external result**, **Record authorized academic decision**, **Record lifecycle result**, **Record conferral**, **Generate TOR preview**, and **Issue official TOR**. Release/INC/correction previews identify affected active Registration Cases and the Clinic 4 review consequence without offering an enrollment action in Clinic 5. The external-result action appears only for an active authority-backed requirement and shows the Student, requirement and treatment, assessment date, `Competent`/`NotYetCompetent`, optional verified NC/COC reference and validity, safe remarks, external source, and append-only impact preview. **Change INC deadline** requires authority, reason, prior/new dates, and current-version revalidation; there is no lapse or automatic-grade action. The academic-decision action appears only for a real external decision or opened review; failed-unit percentages never create a button or status. Only one primary action appears for the current decision. Release, correction, external-result recording, consequential decisions, conferral, and TOR issuance are record-specific actions; no bulk form exists for them.

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
2. Current informational Examination Period with calendar authority/package version, owner, and as-of time, or the named unavailable state.
3. Released grades grouped by term.
4. **Term weighted average** and **Cumulative GWA**, or the explicit **Grades not complete**, incomplete-result, or not-applicable state; when current values are withheld, show the last complete cumulative **Through [term]** value if one exists.
5. Curriculum evaluation with required courses, attempts, credited mappings, current enrollment, prerequisites, deficiencies, and authority-backed external-competency requirements/results.
6. Factual curriculum position and `AcademicEnrollmentEffect`, including any recorded institutional decision, excluded or newly eligible dependent course, active Registration Case impact, responsible owner, and safe explanation.
7. Attempted, earned, and remaining units.
8. Completion readiness and state-valid **Apply for graduation** action.
9. Correction, INC, external-competency reassessment, and lifecycle history.

Every unresolved `INC` shows its original Term end, current inclusive deadline, `CompletionOpen` or **Completion overdue**, responsible Registrar office, and next safe action. Deadline amendments show the previous/current values, authority, reason, actor, and time. Deadline passage never changes the grade or sends an overdue email. An overdue unextended result shows no ordinary Faculty completion action; it remains `INC` with no credit and directs the Student to the retake path unless Registrar records an authorized future extension.

`Term weighted average` is the neutral one-term label. **Term GPA** or another display term appears only when bounded operational metadata records Servitech's authority, reference/date, and effective term. A partially released term always shows **Grades not complete** and never calculates from the released subset. A grade-complete term with only excluded/nonnumeric outcomes shows **Not applicable — no included academic units**, never zero. The cumulative value is recalculated from all included attempts and units rather than averaging displayed term values.

An external-competency row shows qualification/level, `Tracked only` or authority-backed `Completion required`, assessment date, safe `Competent`/`Not yet competent` result, optional verified NC/COC reference and validity, source, and current/superseded history. A missing tracked-only result says **Not recorded** and never blocks enrollment, grades, completion, or conferral. A completion effect appears only when the active Curriculum Version cites exact authority for `CompletionRequired`. The row never contributes a grade, unit, average, prerequisite, finance, email, or standard-TOR value.

The printable action is labelled **Unofficial record — for student reference**. It produces a `FocusedTALACustom` A4 portrait view from the released academic projection as of one explicit time. Every page says **UNOFFICIAL STUDENT RECORD** and **Unofficial — for student reference** and includes institution identity; Student legal name/number, Program, and Curriculum Version; output/as-of references; chronological exact Term groups; released course/attempt/credit facts; term units and average/readiness; cumulative unit/GWA readiness; curriculum/completion summary; and safe INC/correction context. It excludes draft/submitted results, private evidence, Faculty, schedule, finance, certification/signatory/seal, and official-TOR actions. Continuation pages repeat Student identity and headings; navigation/controls do not print; stale/unavailable source or rendering failure creates no artifact. Official TOR issuance is absent from Student actions. Whenever an initial release, INC resolution, or correction affects an active Registration Case, the page states **Registrar review is required for your enrollment** and links to Enrollment without promising an automatic course change. A newly eligible dependent course says that Registrar may add it only through the ordinary Adjustment path; a closed Adjustment state says what remains official and who owns the next permissible path.

```text
Academics
┌ Record current · Registrar owns official corrections       ┐
├ Examination Period · dates / unavailable · source · as-of  ┤
├ Released grades by term                          [Print unofficial]
├ Term weighted average / readiness · Cumulative GWA Through […]│
├ Curriculum progress · external competencies · units         │
├ Curriculum position · advising/decision source · next step   │
├ Completion readiness                   [Apply for graduation] │
└ INC, correction, lifecycle, and conferral history            │
```

```text
External competency result
┌ CSS NC II · Tracked only · CUR-IT-2026                      ┐
├ Current: Competent · assessed 18 Oct 2026 · source verified │
├ NC/COC: optional verified reference and validity            │
├ Earlier: Not yet competent · superseded                     │
└ No grade, units, average, prerequisite, finance, or TOR effect│
```

```text
INC detail — Completion open / Completion overdue
┌ Result INC · Completion note · Original Term end            ┐
├ Deadline: [inclusive Asia/Manila date] · current amendment   │
├ Effect: Average pending · prerequisite unsatisfied · advising required│
└ Open: [Release completion] / [Change deadline]               │
  Overdue: [Record authorized extension] / Retake required     │
```

### TOR preview and issuance

Registrar's transcript view is an authenticated Generated Read-Only View using **TALA Standard TOR — Servitech v1**. The header clearly distinguishes **Preview**, **Issued**, **Voided**, **Replacement**, and **Superseded** states. **Issue official TOR** appears only when the completed academic snapshot, identity, request, Clinic 6 clearance, template version, signatory data, and rendering checks pass. The only output action is print/save-as-PDF for authorized Registrar processing; no Student self-issue/download or template editor exists.

The issuance record shows external request reference/date, derived 30-day due date, Clinic 6 clearance, source/template versions, signatory inputs, issuance date/reference, and any void/replacement or later supersession link. Physical signature, seal, CAV, claiming, courier, and delivery controls are not recreated; the output never claims those external acts occurred unless separately recorded.

```text
TOR — Preview / Issued / Voided / Replacement / Superseded
┌ Student and program · request reference · statutory due date │
├ Readiness: identity · completion · clearance · signatory data │
├ TALA STANDARD TOR — SERVITECH v1 · exact preview              │
├ Source/template version · issue/generation reference          │
└ [Generate preview] / [Issue official TOR] / secondary history │
```

### Sorting and page-specific states

- Faculty rosters sort overdue and due-soon work first, then course/class reference. **No grade rosters assigned** links to the published teaching assignment; a filtered-empty result offers **Clear filters**. The selected Class Roster uses fixed legal-name/Student-number ordering; empty, stale, changed, inaccessible, or generation-failure states never expose a partial print or CSV.
- Registrar queues sort action-needed records first, then due date and latest activity. **No records need review** confirms the active term and filters rather than implying that no academic records exist.
- Student Academics groups terms newest first while TOR rows remain chronological. **No released results yet** explains that only Registrar-released results appear and provides no misleading action.
- Partly released terms show **Grades not complete**, the count/source of missing official outcomes to authorized Staff, and the last complete cumulative **Through [term]** value when available. They never show a partial term value or a newly calculated cumulative value.
- Grade-complete terms with no included numeric units show **Not applicable — no included academic units**. Institution-approved display terminology is shown with its effective source; otherwise the neutral label remains.
- INC work sorts `CompletionOverdue` first, then nearest current deadline. Every row has a deadline; overdue rows expose only authorized extension or retake guidance, and no automatic-grade state exists.
- External-competency results order current first, then newest superseded attempt. Missing tracked-only evidence shows **Not recorded**; missing/stale authority disables recording and never invents a completion block.
- TOR readiness names the exact unavailable source: identity, completed academic record/conferral, request reference, Clinic 6 clearance, TALA Standard TOR version, signatory data, or rendering. Preview creates no issuance. The surface never shows a generic **Not cleared** state.
- Loading retains the page heading and announces progress. Stale or concurrent actions preserve entered data when safe, identify what changed, and require review before resubmission. Result-impact status, prerequisite-eligibility change, and Adjustment-window state are announced to screen readers and never depend on color. Inaccessible records use the shared non-disclosing recovery surface. Technical or mail failures identify the responsible owner and one safe retry, return, or support action.

### Responsive, accessibility, failure, and communication behavior

Roster, grade-history, curriculum, and queue rows stack with labels on mobile. Reading order remains unchanged, the primary action remains reachable, and secondary actions use Action Groups. Wide TOR previews provide a readable on-screen summary and a print view rather than forcing an unusable scaled document into the mobile viewport.

All controls are labelled, keyboard reachable, visibly focused, and accompanied by screen-reader status text. Examination-period dates are announced with their source and unavailable state; external-result treatment and outcome never depend on color. Empty, loading, stale-record, inaccessible, expired-session, validation, late-window, concurrency, mail-failure, and technical-failure states state what happened, who owns recovery, and the safe next action.

Queued email is limited to the Faculty submission request, returned roster, grade release without values/attachment, INC release/deadline, deadline amendment, INC resolution, authorized correction, consequential progress/lifecycle, completion action-required, and conferral. The one release/INC/correction email may add **Registrar review is required for your enrollment** and an authenticated Enrollment link when the same event affects an active case; no separate review email is sent. Deadline passage sends no email. Routine saves, calculation/readiness refresh, queue movement, navigation, countdowns, and recurring reminders remain in-workspace only.

The Clinic 5 synthetic set includes `INC-OPEN-001` with its calculated deadline and later completion, `INC-OVERDUE-002` whose deadline passes without a grade change/email and exposes only extension-or-retake guidance, and `INC-AMEND-003` whose authorized amendment returns it to `CompletionOpen`; `EXT-COMP-CSS-NCII` as tracked-only; `EXT-RES-CSS-001` `NotYetCompetent` followed by superseding `EXT-RES-CSS-002` `Competent`; one missing tracked-only result that does not block completion; hypothetical authority-backed `EXT-COMP-WEB-NCIII-REQ`, whose missing result keeps completion pending without becoming Servitech policy; and coordinated `TERM-2026-ST` classes `CLS-ITE3-ST-A` (`1.75`) and `CLS-IT201-ST-R` (`2.50`). Releasing the first class alone must show **Grades not complete** and the prior cumulative **Through [term]** value. Releasing the second must show Special Term `2.13` and cumulative `2.01` from 90 prior included units/180 weighted points plus six units/12.75 points; the earlier `IT201` `5.00` remains counted while the retake satisfies the curriculum.

The same Clinic 5 set includes the prerequisite result changes for `REG-2026-0011` and `REG-2026-0012`. Release preview identifies the affected exact case without exposing an enrollment action. Student Academics shows one contextual Enrollment link and the safe review message; email contains no grade value and no duplicate review notice. A satisfying release only makes the excluded course eligible for ordinary Adjustment, while an adverse post-enrollment correction leaves Official Enrollment unchanged until Clinic 4 records an authorized outcome.

The negative-space acceptance coverage proves that Registrar, Academic Head, Faculty, and Student see the same sourced Examination Period; missing/stale dates produce no fabricated value; and exact class arrangements remain outside TALA. It then blocks an external-result action against a missing/stale requirement, records `EXT-RES-CSS-001`, appends `EXT-RES-CSS-002` without overwriting the first attempt, and shows the same safe result in Student Academics and Academic Oversight. The tracked-only missing example remains **Not recorded** without blocking completion; hypothetical `EXT-COMP-WEB-NCIII-REQ` remains pending only because its synthetic curriculum authority explicitly says `CompletionRequired`. No TESDA operations, new destination, email, standard-TOR field, grade, average, unit, prerequisite, or financial effect appears.

### Native component decision

Native Filament Tables own queues, rosters, search, and filters; Forms own controlled final-result, authority, and verified external-result input; Infolists and Sections own read-only academic evidence; Tabs own the Registrar workbench; Action Groups own secondary actions. Focused custom Pages are justified only for Student Academics, the unofficial print view, and the TALA Standard TOR preview. Clinic 5 justifies no gradebook, spreadsheet-import, attendance, TESDA/certification, workflow, academic-policy, transcript-template, dashboard, or PDF plugin.

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
| Student Accounts | Find the next account decision, including `Assessment required` | Status, person, account, Program/Term, assessment basis/source, required/payment/coverage/due, satisfaction basis, next action | One Table with three semantic tabs, native filters, and fixed contextual native Filament CSV Actions; no separate assessment, coverage, or Reports destination |
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
│ Published       IT        v1       ₱48,000     View       │
│ Needs attention BM        Draft 2  Incomplete  Continue   │
│ Upcoming        THM       v1       ₱46,500     View       │
│                                                          │
│ Published plans are immutable. New versions supersede.   │
└──────────────────────────────────────────────────────────┘
```

#### Fee Plan detail and publication

```text
┌ Fee Plan · IT · 2026 T1 · Draft 2 ───────────────────────┐
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
│ [Search person or account…]      [Export account status]  │
│                                                          │
│ STATUS              PERSON         ACCOUNT       ACTION    │
│ Assessment required S. Student   ACT-2026-ST-001 Record   │
│ Under review         Ana Reyes     ACT-260001    Review    │
│ Action needed        Miguel Santos ACT-260014    View      │
└──────────────────────────────────────────────────────────┘
```

The Payment Exceptions tab uses risk/reason, person/account, claimed amount, channel/source, submission age, and `Review`. The TOR Clearance tab uses state, request reference, learner, required amount/reference, required date, and `Open`.

#### Contextual finance CSV actions

Both exports are `NativeFilament` CSV-only actions with fixed columns, private initiating-actor retrieval, explicit query scoping, a required purpose, and no column chooser or alternate format.

**Export account status** acts on the exact normalized Accounts-tab filters, authorization scope, and deterministic visible ordering confirmed in its dialog. It uses `tala-account-status-YYYYMMDD-HHmmss-PHT.csv` and these ordered labels: `Account Reference`, `Person Reference`, `Program`, `Term`, `Assessment Total (PHP)`, `Required Now (PHP)`, `Verified Payment Applied (PHP)`, `Approved Coverage Applied (PHP)`, `Current Due (PHP)`, `Projection State`, `Satisfaction Basis`, `Assessment Basis`, `Source Version or Authority Reference`, `As of (Asia/Manila)`.

**Export verified payments** appears only in one selected Student Account's Payments context and applies its active state/date filters. It uses `tala-verified-payments-YYYYMMDD-HHmmss-PHT.csv` and these ordered labels: `Payment Reference`, `Account Reference`, `Person Reference`, `Term`, `Amount (PHP)`, `Channel`, `Masked External Reference`, `Posted At (Asia/Manila)`, `Verification Basis`, `Current State`. It never expands into a system-wide payment report.

The confirmation shows the exact export, scope, row-count estimate, fixed columns, required purpose, and private retrieval. Both files are UTF-8 with BOM, comma-delimited, RFC 4180 quoted, and CRLF. Money uses ungrouped two-decimal values without `₱`; date/time uses RFC 3339 with `+08:00`; blank never means zero. Formula-like text beginning with `=`, `+`, `-`, `@`, tab, or carriage return is prefixed with one apostrophe, while numeric and date cells come only from typed authority.

Export rechecks role, per-record visibility, filters/context, and source/as-of state and allows at most 10,000 rows. Above the limit, the dialog requires narrower filters. Zero rows records `NoRows` and creates no file. Failure creates no partial file, retains the normalized scope, and offers retry. Audit records actor, role, context/filters, purpose, row count, outcome, and time. No export email, notification center, report hub, private proof, raw provider data, bank detail, secret, eligibility material, or internal note is included.

### Detailed Account and Payment Status reference design

#### Accounting account detail

```text
┌ Ana Reyes · ACT-260001 · IT · 2026 T1 ──────────────────┐
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
│ Assessment: Published fee plan · FP-IT-2026-T1-v1        │
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
│ Primary host  Not checked by TALA     Unknown       —     │
│ Off-host copy Not checked by TALA     Unknown       —     │
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
│ Automatic retention disposal: Not provided in this MVP  │
│ External compliance status: Not evaluated by TALA       │
└──────────────────────────────────────────────────────────┘
```

### Printable-output wireframes

All seven canonical outputs use the same authenticated print frame: approved institution crest and name first; exact output title/status; source/version and generation reference/time; monochrome-safe semantic headings; repeated identity/table headings; deliberate page breaks without clipped rows; no navigation or interactive controls; system-font fallback; and restrained **Generated through TALA** footer text. Application Acknowledgment, COR, Unofficial Student Record, TALA Standard TOR, Account Statement/SOA, and Payment Acknowledgment are A4 portrait. Published Timetable is A4 landscape. A stale, inaccessible, or failed source creates no partial or official-looking artifact and preserves the owning page with a safe retry/support path.

The current Class Roster uses the same authenticated, monochrome-safe, repeated-heading, non-clipping A4 portrait print quality, but is explicitly labelled **Operational reference** with a current as-of time. It is not included in the seven canonical outputs, creates no issuance/version event, and is never proof of enrollment or grades.

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

SOA and Payment Acknowledgment retain their non-tax disclaimer on every generated copy. Their approved institution-first frame contains no mascot; the TALA product is identified only by the restrained footer.

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

The authoritative synthetic records are `FP-IT-2026-T1-v1`, incomplete `FP-BM-2026-T1-d2`, Term Accounts `ACT-260001`, `ACT-260008`, `ACT-260014`, `ACT-260021`, `ACT-260027`, `ACT-260033` with Applied scholarship coverage and successor/reversal evidence, `ACT-260034` with an institutionally authorized `NoPaymentRequired` Fee Plan result, `ACT-260039` with a missing-webhook manual reconciliation and late event, `ACT-260041`, `ACT-260045` with a reduced Individually Advised exact individual assessment, `ACT-2026-ST-001` for `REG-2026-ST-001` with PHP 6,000 assessment, PHP 3,000 required now, `COV-2026-ST-001` PHP 2,000 Applied subsidy, `PAY-2026-ST-001` PHP 1,000 verified payment and `Mixed` clearance, and `ACT-260047` with changed-registration branches; TOR clearances `TOR-260003` through `TOR-260005`; reversed `PAY-260009`; one alumni account; and one degraded-health example. Identities use `example.test`; no real student, provider, wallet, eligibility, or proof data appears.

The browser walkthrough follows this order:

1. Accounting observes the blocked BM Draft and publishes the valid fixed IT plan.
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
14. The two Clinic 6 finance CSVs record purpose and output-access evidence.
15. System Health separates local evidence from external unknowns; Governance states that automatic retention disposal is not provided and makes no external compliance verdict.
16. Alumni opens historical Finance read-only.

The walkthrough is documentation authority only. Browser execution remains implementation-acceptance evidence for the owning future vertical slice.

## Archived Implementation Inventory — Evidence Link Only

The former live **Legacy Implementation and Comparison Inventory**, TAL-60 decisions, superseded finance decisions, module-to-UI implementation map, scheduling UI baseline, import/plugin notes, and future-slice template are preserved byte-for-byte as non-authoritative implementation history in [`archive/project-progress/TALA-UI-Legacy-Implementation-and-Comparison-Inventory-pre-prototype-2026-08-08.md`](./archive/project-progress/TALA-UI-Legacy-Implementation-and-Comparison-Inventory-pre-prototype-2026-08-08.md), SHA-256 `966107D9CE69EC3CA37CE4B3C6999010E78A84F2648C138E33E3264EA835F80E`.

That archive may support later bounded implementation-evidence inspection. It cannot add navigation, restore superseded behavior, define a canonical screen, route implementation work, or override the Panel and Navigation Map, shared UI authority, PRDs 01–06, or Architecture Specification.
