# PRD 01 — Identity, Access, and Public Entry
## Authority and Standalone Status

**Status:** Standalone and ready for vertical-slice planning.

This PRD is the complete product authority for identity, access, public entry, account security, and bounded public content. It is sufficient to understand the module without a legacy PRD or implementation file. Product-wide terminology and mutation rules come from the [TALA System Definition Baseline](./00_system_definition_baseline.md); exact shared presentation comes from the [UI Surface Blueprint](../ui_surface_blueprint.md).
## 1. Purpose and Successful Outcome

Clinic 1 gives each person one secure TALA credential account and only the workspace contexts that person is authorized to use. It provides:

- A task-focused one-page public gateway with factual Programs, institution/location context, and contextual sign-in
- Minimal Applicant account creation and email verification
- Secure Staff invitation and activation
- Verified-email sign-in and recovery
- Authenticator-app MFA for Staff-capable accounts
- Direct single-role routing and explicit multi-role switching
- A focused Account Security surface
- A System Administrator Users & Access workbench
- Bounded notices and FAQ
- Clear inaccessible, disabled, expired, throttled, and service-failure recovery

The successful result is not “a user table exists.” A complete journey lets a real Applicant or Staff member enter from the public page, establish or recover access, reach the correct authorized workspace, understand the next action, and remain protected against cross-role access.

Verified email is the only sign-in identifier and the single live TALA communication address. Only Applicants self-register; Staff access begins through System Administrator invitation or an authorized role change on an existing verified account.

## 2. Product Boundary and Ownership

### 2.1 TALA owns

| Responsibility | TALA treatment | Feature category |
|---|---|---|
| Credential account and verified sign-in email | Authoritative security/access record | Source record |
| Password, verification, recovery, session, and MFA | Enforced authentication controls | Source record and security control |
| Fixed workspace-role assignments | Current authorization source with audited history | Source record |
| Workspace resolution and switching | Derived authorized projection | Generated read-only view plus action |
| Account disable/reactivate | Audited access action that preserves domain records | Focused state-changing action |
| Staff access identity | Minimal name and optional institution staff identifier | Source record |
| Public notices and FAQ | Bounded published content | Source record and generated public view |
| Public Program availability | Read-only projection of Clinic 2 Program and Admission Cycle authority | Generated public view |
| Institution map and approved hero media | Bounded configured presentation inputs with factual fallback | Generated public view; not a content-management subsystem |
| Security emails and high-value audit events | Transactional output and assurance evidence | Integration output / audit record |

### 2.2 TALA records or consumes but does not decide

- System Administrator records an externally authorized Staff-role assignment or revocation. The authorizing office supplies the person, fixed role, authority reference, and effective date as operational inputs; TALA does not select Staff or model employment approval.
- Lost-factor recovery begins only after the institution verifies the Staff member's identity outside TALA. TALA records the authorized reset and its evidence.
- Clinic 2 owns admissions-cycle dates, Applicant identity, applications, requirements, matching, and admission decisions. Clinic 1 consumes only whether public account creation is currently available.
- Clinic 4 owns official enrollment, student-number creation, and the idempotent grant of Student access.

### 2.3 Explicit exclusions

Clinic 1 does not build:

- A universal Person master record
- HR, employment, appointment, or Staff lifecycle management
- Username, student-number, staff-ID, application-reference, or LRN authentication
- Social login, public API authentication, SSO, biometrics, security questions, or email-as-MFA
- A role builder, permission editor, generic policy DSL, configurable account state machine, or arbitrary Settings page
- A full CMS, page builder, gallery, arbitrary media library, duplicated programs-marketing catalog, map editor, or reviewer workflow
- Applicant intake, admission review, student-master creation, enrollment, finance, grades, or academic decisions
- An automatic retention/disposal module, legal-hold workflow, or ordinary deletion of security/domain history
- Email for routine saves, navigation, successful sign-in, failed sign-in, or internal queue movement

## 3. Roles and Access Boundary

The fixed roles are:

1. Applicant
2. Student
3. Registrar
4. Accounting
5. Faculty
6. Academic Head
7. System Administrator

Public is not a role. Roles and permissions are code-owned and enforced by panel gates, policies, and action authorization.

| Role | Identity/access responsibility | Identity/access boundary |
|---|---|---|
| Applicant | Creates and secures own account; uses own Applicant context | Cannot grant roles, see another account, or enter Student/Staff workspaces |
| Student | Secures own account and uses persistent historical Student context | Cannot grant roles or edit official Student identity |
| Registrar | Uses an assigned Registrar context | Cannot provision Staff, alter access vocabulary, or gain System Administrator authority through Registrar work |
| Accounting | Uses an assigned Accounting context | Cannot provision Staff or gain academic authority |
| Faculty | Uses an assigned Faculty context | Teaching assignment constrains academic work but does not create or expire the base Faculty role |
| Academic Head | Uses an assigned Academic Head context | Is not a universal co-approver or account administrator |
| System Administrator | Invites Staff, changes Staff access, disables/reactivates accounts, resets Staff MFA after external verification, manages bounded public content, and sees appropriate security evidence | Has no automatic admissions, enrollment, payment, grade, timetable-publication, or academic-record authority |

One account may hold multiple legitimate roles. TALA authorizes one active workspace context at a time; it never merges all role permissions into one combined menu.

## 4. Authoritative Conceptual Records

These are domain contracts, not approved physical table names.

| Name | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| User Account | Credential, verification, security, session, invitation, and current disablement facts | Account holder for self-service facts; System Administrator for Staff/access actions | Persisted authoritative record | Every authenticated workspace | Remains distinct from Applicant, Student, and Staff domain facts |
| Staff Access Profile and role assignment history | Safe Staff identity plus fixed authorized contexts | System Administrator records the external authorization result | Persisted authoritative record plus immutable events | Workspace resolution and role-owned pages | Contains no employment or HR lifecycle |
| Verification, recovery, invitation, MFA, and session-security facts | Enforce bounded authentication journeys | PRD 01 | Persisted security facts or immutable events on the Account | Authentication and Account Security | They do not require separate product modules or public resources |
| Workspace Context | Resolve one authorized role and fixed destination | PRD 01 | Derived projection/calculation | Authenticated shell | Never duplicated or stored as another role record |
| Account Access State | Present InvitationPending, VerificationRequired, Active, or Disabled | PRD 01 | Derived projection/calculation | Authentication, workspace chooser, Users & Access | Derived from current Account/security facts; no state-machine engine |
| Access Change Evidence | Preserve actor, authority, reason, effect, and time | PRD 01 through the existing audit facility | Immutable version or event | Users & Access and Governance & Audit | Reuses product audit history; no parallel audit store |
| PublicNotice and PublicFaq | Publish bounded public information | System Administrator | Persisted authoritative record with publication history | Public Gateway | Separate types because their validation and ordering differ; neither becomes a CMS |

### 4.1 User Account

Contains only:

- Verified sign-in email and pending email-change facts
- Password credential
- Email-verification facts
- Invitation/activation facts when applicable
- Disablement actor, reason, authority, optional evidence reference, and time
- Last successful sign-in
- MFA secret and recovery-code facts for Staff-capable accounts
- Session-security facts needed to enforce the accepted policy

It does not own legal name, Applicant workflow state, Student academic status, employment data, or institution decisions.

### 4.2 Staff Access Profile

Contains:

- Account reference
- Required given and family names, optional middle name, and optional separately stored suffix under the shared name primitive
- Derived display name
- Optional institution-issued Staff identifier

It contains no employment contract, rank, salary, appointment, workload, or HR lifecycle.

### 4.3 Workspace Context

Contains or derives:

- Fixed role
- User-facing label
- Destination
- Current authorization result
- Whether the context is the normal destination

The context does not duplicate the role assignment or domain profile.

### 4.4 Account Access State

Exactly one state is derived:

| State | Derivation | Available action |
|---|---|---|
| **InvitationPending** | Staff invitation exists but activation is incomplete | Activate or resend invitation |
| **VerificationRequired** | Account exists but live sign-in email is unverified | Verify or resend verification |
| **Active** | Required activation/verification is complete and account is not disabled | Sign in to authorized contexts |
| **Disabled** | An authorized disablement is currently effective | No workspace access; contact official support |

No stored workflow engine controls these states.

### 4.5 Access Change Evidence

For access assignment, revocation, disable/reactivate, and MFA reset, record:

- Actor
- Affected account
- Affected role when applicable
- Action
- Required reason
- Required authority
- Optional evidence reference
- Occurred time

The existing audit facility may carry this evidence. No parallel audit engine is created.

### 4.6 Consolidated State and Action Matrix

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| Public entry available/unavailable | Publish or close the current admissions-entry source | Registrar through Clinic 2 | Clinic 2 cycle authority | Current cycle and publication readiness | Public gateway derives whether **Apply** is available | Later cycle publication supersedes the projection; existing sign-in is never removed | Public sees availability; existing Applicants retain sign-in |
| `VerificationRequired` | Create Applicant account or request an email change | Applicant, learner, or authorized System Administrator for Staff-capable accounts | Self-service for Applicant/Student-only; recorded authority for Staff-capable accounts | Unique normalized email, privacy acknowledgement, valid password when creating | One credential account and pending verification evidence | Verification links are single-purpose; a later resend or email-change request supersedes the prior pending link | Account holder sees verification guidance; System Administrator sees safe state only |
| `InvitationPending` | Invite new Staff or resend invitation | System Administrator | Fixed-role assignment plus recorded reason and authority | No duplicate account; final-administrator and self-escalation protections | One account, Staff access profile, role assignment evidence, and expiring activation link | Resend invalidates the previous link; activation supersedes pending invitation | Invited person sees activation; administrator sees pending/expired state |
| `Active` | Verify email, complete activation, or reactivate | Account holder or System Administrator | Valid signed link, or recorded reactivation authority | Account not disabled; Staff-capable account must complete MFA before Staff access | Authorized workspace contexts become usable | Activation/verification evidence is retained; reactivation does not erase prior disablement | Chooser shows only authorized contexts; single-role users route directly |
| MFA enrollment/challenge required | Enter a Staff-capable context | Staff account holder | Existing Staff role | Password-authenticated session; valid TOTP or unused recovery code | Staff workspace access or continued enrollment requirement | Recovery codes are single-use; reset supersedes the prior factor only after external identity verification | Staff sees security action; administrator sees bounded reset evidence, never secrets |
| Access assignment changed | Add or revoke a fixed Staff role | System Administrator | Recorded reason, authority, and optional evidence | Cannot remove the final active System Administrator or self-escalate without authority | Role assignment history and refreshed workspace projection | Later authorized change supersedes current access, but history is immutable | Affected account sees added/removed context; other roles gain no authority |
| `Disabled` | Disable account | System Administrator | Recorded reason and authority | No self-disable; final active System Administrator protected | Sessions end and all workspace entry is blocked while domain records remain | Reactivation supersedes current disablement; disablement evidence remains | Account holder sees only disabled/support guidance; internal reason remains restricted |
| Student context granted | Finalize first official enrollment | Registrar through Clinic 4 | Clinic 4 finalization authority | Existing verified credential account; idempotency by official enrollment/student identity | Existing account gains Student access and Student becomes normal context | Retry creates neither a second account nor a second Student number | Learner sees Student context; Applicant context leaves the everyday chooser |
| Public content scheduled/published/unpublished | Save publication action | System Administrator | Public-content permission | Bounded fields, valid Asia/Manila window, safe optional link | Public notice/FAQ projection changes | Later edit or unpublish supersedes public projection; audit remains | Public sees only currently effective content |

### 4.7 Public Content

**PublicNotice** contains a required title of 1–160 characters, required plain-text short message of 1–500 characters, publication state, optional visible-from and visible-until in Asia/Manila, a positive display order unique within its effective published group, and an optional HTTPS link whose label is at most 80 characters and URL at most 2,048 characters. When both dates exist, visible-from cannot follow visible-until.

**PublicFaq** contains a required question of 1–160 characters, required plain-text answer of 1–3,000 characters, category label of 1–120 characters, publication state, positive category order, and positive question order unique within the category/effective published group. Neither record supports arbitrary page layout, scripts, uploads, or rich CMS behavior.

## 5. Readiness and Setup Contract

TALA does not provide a first-run setup wizard. Deployment and System Administration must establish these prerequisites:

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Fixed roles and permissions | Code-owned canonical vocabulary | Deployment/System Administrator | Roles and permissions are installed and consistent | Authenticated workspace use blocked | Authorize and route a signed-in account | Restore the approved vocabulary; do not create an editable role builder |
| Initial System Administrator | Controlled deployment record and access assignment | Authorized deployment operator | At least one verified, active account holds System Administrator | Staff access administration blocked | Invite Staff; change or recover access | Record an authorized initial assignment through the controlled operator procedure |
| HTTPS and secure sessions | Deployment configuration evidence | Deployment operator | Production traffic and cookies are securely configured | Production readiness blocked | Sign in and maintain authenticated sessions | Correct deployment configuration before enabling production entry |
| Queue, mail transport, and official sender | Operational integration configuration and dispatch evidence | Deployment operator/System Administrator | Verification, invitation, and recovery dispatch is configured; each attempt records its immediate outcome | Failed dispatch leaves the account in its recoverable pending state; production activation remains an operational gate | Create/verify account; invite/activate Staff | Restore transport/sender and use authorized idempotent resend; never roll back the account |
| Official support | Project-approved public contact paths: Servitech Facebook and `0947 737 9208` | System Administrator maintains configured values | At least one usable contact path is displayed where recovery needs assistance | Assistance guidance is degraded, but Applicant registration is not blocked | Render public/auth/failure recovery | Correct the configured public contact; do not claim monitoring or a service level TALA cannot prove |
| Privacy notice | TALA-authored notice derived from the data this journey actually collects and applicable Philippine privacy authority | Product authority; System Administrator publishes the approved build | The one-page Public Gateway exposes the notice and registration links to the same source | Missing notice is an implementation defect, not an institution-data readiness toggle | Register Applicant account | Restore the approved notice/source before accepting the build |
| Accessibility information | TALA-authored description of implemented and tested keyboard, focus, label/error, zoom/reflow, contrast, reduced-motion, and assistance behavior | Product authority; implementation evidence proves each claim | The one-page Public Gateway exposes only behavior supported by the accepted UI and tests | Missing or unsupported copy is an implementation defect; it is not a client-information blocker | Explain access behavior and assistance | Correct the implementation or the claim; never assert unverified conformance |
| Application-entry availability | Current Clinic 2 Admission Cycle projection | Registrar/Clinic 2 | A published current cycle says entry is open | New account creation through Apply unavailable; existing sign-in remains | Show **Apply** and accept Applicant registration | Clinic 2 publishes/opens an authorized cycle; Clinic 1 never edits the date |

Readiness is derived and failed-first. Passed checks remain collapsed. Applicant registration availability is derived from the current Clinic 2 entry projection; it is not controlled by a manually asserted mail-ready flag or by externally hosted support/privacy/accessibility pages. A runtime mail failure after an account transaction does not erase the account or reverse another institutional transaction; it records failure, presents a safe retry/support path, and allows an authorized resend. Real sender delivery and production configuration remain deployment evidence, while implementation acceptance uses the configured test environment and mail fakes without contacting a real provider.

The first System Administrator is created through a controlled deployment/operator procedure with documented authority. The product does not introduce a publicly reachable bootstrap wizard.

## 6. End-to-End Journeys

### 6.1 Public to verified Applicant

1. Public visitor opens TALA and sees Servitech/TALA identity, current application availability, factual active Programs, the connected learner journey, in-page FAQ, institution/location context, and official support.
2. When entry is open, **Apply** opens Applicant account registration. When closed, the page explains that applications are closed while preserving Applicant sign-in for existing accounts.
3. Registration collects only email, password, confirmation, and acknowledgement of the linked privacy notice.
4. The primary action is **Create account**. Account creation does not create an application.
5. TALA rejects duplicate email safely and does not reveal unrelated account details.
6. TALA creates the credential account in **VerificationRequired**, sends verification, and shows resend/support guidance.
7. Verification activates the account and proves email ownership.
8. The account enters the Applicant workspace. Clinic 2 owns the later **Start application** journey.

Expired, already-used, malformed, throttled, and mail-failure paths must state what happened and the one valid recovery action.

### 6.2 Sign in and workspace resolution

1. Public sign-in offers Applicant, Student, and Staff contexts.
2. Context selection changes orientation and requested destination only; it never grants or discloses a role.
3. The user signs in with verified email and password.
4. Unknown email or wrong password receives the same generic failure.
5. Correct credentials used through the wrong entry authenticate once, then route to an authorized context with a short explanation.
6. A single authorized context routes directly.
7. Multiple authorized contexts open the workspace chooser.
8. The user may switch contexts without another sign-in; every direct route is still independently authorized.

A disabled account with a correct password sees only that access is disabled and the official support path. The internal reason is never displayed on the public/auth surface.

### 6.3 System Administrator to activated Staff

1. System Administrator selects **Invite Staff**.
2. The form collects verified institutional access facts: email, name parts, optional Staff identifier, one or more fixed Staff roles, required reason, required authority, and optional evidence reference.
3. TALA prevents self-escalation that bypasses authorization and prevents removal of the final active System Administrator.
4. For a new email, TALA creates one **InvitationPending** account and sends a single-use 60-minute activation link.
5. For an existing verified active account, TALA reuses the account, does not reset its password, records the Staff-role change, and requires MFA enrollment before Staff workspace use.
6. The administrator never enters or sees the Staff password.
7. New-account activation proves email ownership, sets a compliant password, records activation, and continues to authenticator-app MFA enrollment.
8. TALA shows recovery codes once and requires confirmation that the Staff member stored them.
9. Expired invitations remain **InvitationPending**; **Resend invitation** invalidates the prior link and issues a new one.

### 6.4 Staff MFA and lost-factor recovery

1. Staff-capable accounts complete password authentication and then TOTP verification.
2. A recovery code may replace one TOTP challenge once; consumed codes cannot be reused.
3. Email cannot bypass MFA.
4. If all factors are lost, the institution verifies identity outside TALA.
5. System Administrator records the authorized reset reason, authority, and optional evidence, then resets MFA.
6. The Staff member signs in with the remaining valid credential and enrolls a new authenticator before workspace access.

Any account holding at least one Staff role is Staff-capable. It cannot bypass MFA, the shorter session, or remember-me restrictions by entering through Applicant or Student.

Pending MFA enrollment is a Staff-workspace security gate, not a fifth account state.

### 6.5 Official enrollment to persistent Student access

Clinic 4 owns the transaction:

1. Registrar finalizes official enrollment.
2. The transaction creates the official Student number/profile when required and idempotently assigns Student access to the existing credential account.
3. It never creates a second credential account or a second Student number for a retry.
4. Student becomes the normal context after first official enrollment.
5. The completed application remains retained evidence but is removed from the everyday chooser unless a later active Applicant journey genuinely requires it.
6. Clinic 4 queues one **Official enrollment and COR ready** message. On first enrollment that same message explains that Student access is active; no separate activation email is sent. Mail failure cannot roll back enrollment.
7. Student access persists between terms and after completion; current-term eligibility controls available actions.

### 6.6 Recovery and email change

- Any eligible account may request a generic password-recovery message without account disclosure.
- A completed password recovery invalidates other applicable sessions.
- Applicant/Student-only users may request a sign-in email change. The old address remains live until the new address is verified; the old address receives an alert.
- Staff-capable email changes are initiated by System Administrator with recorded authority. The same safe pending-verification transition applies.
- Sensitive email, password, access, disablement, and MFA-reset actions require current-password confirmation where the acting user has a password-authenticated session.

### 6.7 Disable, reactivate, and Staff access change

- Disable requires reason and authority, records actor and time, ends active sessions, and blocks every workspace.
- Disable preserves roles, profiles, applications, Student records, and audit history.
- Reactivate restores the same authorized contexts and notifies the account.
- Self-disable is prohibited for System Administrator actions.
- The final active System Administrator cannot be disabled or have that role removed.
- Staff roles remain until deliberately revoked. Term teaching assignments constrain Faculty work without expiring the base Faculty role.
- Assignment and revocation use fixed roles and require reason and authority plus optional evidence reference.

### 6.8 Bounded public content

System Administrator may create, edit, publish/unpublish, order, and schedule concise notices and FAQ entries. Publication windows use Asia/Manila. Invalid or unsafe optional links are rejected. Public rendering includes only currently published content.

Programs are not Public Content records. The Public Gateway projects active Program facts and intake availability from Clinic 2 authority. Location uses the configured approved map reference with an external-link fallback. Hero media is limited to a small tracked or approved-object-storage asset set selected through deployment/configuration; it is never an arbitrary gallery. Moving media must be muted, include a poster and visible pause/stop control, preserve all meaning in text, and become static when reduced motion is requested.

## 7. Authentication and Session Policy

### 7.1 Passwords

- Accept 15–64 characters.
- Allow spaces, paste, autofill, and password managers.
- Do not require uppercase, lowercase, number, or symbol composition.
- Do not require periodic changes.
- Reject known-compromised values through Laravel's privacy-preserving check when the service is reachable.
- When the compromised-password service is unreachable, apply every local passphrase rule, record the degraded check for operations, and avoid exposing provider details. It does not weaken unrelated authorization.

### 7.2 MFA

- Staff-capable accounts require native authenticator-app TOTP and recovery codes.
- Email is not an MFA factor.
- Applicant/Student-only accounts do not require MFA in V1.

### 7.3 Sessions

| Account capability | Idle timeout | Remember device |
|---|---:|---|
| Applicant/Student only | 120 minutes | Allowed |
| Any Staff role | 30 minutes | Not offered |

The stricter Staff policy applies across all panels for a multi-role account.

## 8. Transactional Email Contract

| Trigger | Recipient | Safe contents | Source / idempotency key | Failure behavior | Excluded notifications |
|---|---|---|---|---|---|
| Verify or resend email | Account email | Purpose, expiry/recovery guidance, secure link | Account plus verification generation | Account remains recoverable; no duplicate account | No routine save or page-activity mail |
| Password-recovery request | Requested email when eligible | Neutral secure link and expiry | Recovery request generation; public response remains generic | Request can be safely repeated without account disclosure | No successful/failed sign-in mail |
| Staff invitation or resend | Invited email | Role-neutral activation explanation, 60-minute link, support | Invitation generation | Failure preserves `InvitationPending`; resend invalidates the prior link | No separate role-preview message |
| Email-change request | New and old addresses | Verification to new; security alert to old | Pending email-change generation | Old address remains live until new verification | No mail for an unchanged address |
| Disable or reactivate | Account email | State, effective time, support; no sensitive internal reason | Access-change evidence reference | Access action remains effective and authorized resend is available | No internal reason or authority evidence in mail |
| Staff-role change | Account email | Added/removed workspace context and support | Access-change evidence reference | Role action remains effective and authorized resend is available | No mail for teaching assignment or routine queue movement |
| Official enrollment and COR ready | Account email | Clinic 4's secure enrollment/COR notice; on first enrollment, Student access is active | Clinic 4 official-enrollment event and COR version | Enrollment never rolls back; Clinic 4 owns authorized resend | No duplicate Student-activation email |

Templates are code-defined. Delivery is queued, retried, and recorded without creating a notification-center product.

## 9. Audit and Security Evidence

Clinic 1 issues no official institutional document. Its authoritative outputs are the transactional emails in Section 8 and the bounded security/audit evidence below; neither is a transcript, registration form, financial document, or substitute for an owning clinic's official output.

Audit high-value events only:

- Staff invitation and activation
- Email verification
- Completed password recovery
- Email change
- Role assignment and revocation
- Disable/reactivate
- MFA enrollment, challenge outcome, recovery-code use, and authorized reset
- Last successful sign-in

Bounded security logs may retain throttling and failed-authentication evidence without passwords, tokens, MFA secrets, recovery codes, full request bodies, or a navigation clickstream. Institutional retention schedules, privacy requests, legal holds, and secure disposal remain external responsibilities. TALA provides no automatic disposal function and PRD 01 invents no retention duration.

## 10. UI Authority

The exact Clinic 1 page blueprints and low-fidelity wireframes live in the UI Surface Blueprint. This PRD owns the required inventory and information contract.

| Page/surface | Owner/user | Primary purpose | Primary action |
|---|---|---|---|
| Public gateway | Public | Understand Servitech/TALA, current admission availability, factual Programs, location/support, and choose Apply or sign-in context | Apply or Sign in |
| Applicant registration | Public Applicant | Create a credential account | Create account |
| Email verification | Applicant/Staff | Prove email ownership | Verify / Resend |
| Contextual sign-in | All account holders | Authenticate for an intended context | Sign in |
| Password recovery/reset | All eligible accounts | Restore password access | Send recovery link / Reset password |
| Workspace chooser | Multi-role account | Choose one authorized context | Open workspace |
| Account Security | Signed-in account | Manage email/password and applicable MFA | Contextual security action |
| Users & Access list | System Administrator | Find accounts and identify the next access action | Invite Staff |
| Account detail | System Administrator | Review one account and take a safe access action | State-dependent action |
| Invite Staff | System Administrator | Provision fixed Staff contexts without a password | Send invitation |
| Change Staff access | System Administrator | Assign/revoke fixed roles with evidence | Save access change |
| Public Content | System Administrator | Maintain bounded notices and FAQ | Add notice / Add FAQ |
| Branded failure pages | Public/signed-in | Explain inaccessible, expired, throttled, or failed requests | One safe recovery action |

### 10.1 Users & Access table

Required columns:

- Displayed name
- Verified email
- Authorized workspaces
- Derived account state
- Invitation/verification state
- Last successful sign-in
- Created date

Search:

- Displayed name
- Email
- Linked application, Student, or Staff identifier

Native filters:

- Role/workspace
- Account state
- Invitation/verification state
- Created date range
- Last-sign-in date range

Row actions:

- View account
- Resend invitation or verification
- Send recovery link
- Change Staff access
- Disable
- Reactivate
- Reset Staff MFA

There is no delete, archive, password input, role creation, or permission editing.

### 10.2 Account detail

Use an infolist ordered as:

1. Account state and next action
2. Staff access profile when applicable
3. Assigned role contexts
4. Linked Applicant/Student domain profiles
5. Security facts
6. High-value audit history

Technical IDs and evidence references remain secondary detail.

### 10.3 Presentation rules

- Tables carry queues; infolists carry read-only facts; forms collect actual input; Sections/Tabs disclose secondary evidence; Action Groups contain secondary row actions.
- A wizard is not used for registration because the form is only email/password. Staff invitation is a focused form, not a workflow builder.
- The public page and authentication shell visibly identify the selected context and always offer **Choose another workspace**.
- Public and authenticated decisions follow the UI Blueprint's ethical presentation standard: defaults disclose their source and remain reversible; persisted progress and saved state are factual; consequential actions are never preselected; and warnings name the real consequence, owner, date/source, and recovery path without pressure.
- A chooser shows authorized contexts only; it has no unavailable roles, counts, previews, or analytics.
- System Administrator tables use native Filament filter panels and active indicators, not custom column-header dropdowns.
- Status and recovery never rely on color alone.

## 11. Responsive, Accessible, and Failure Behavior

- Public and learner-facing pages qualify at 360 × 800 CSS pixels and larger.
- Public and authentication pages provide semantic landmarks, a visible-on-focus skip link, and consistently placed official support.
- Access cards stack on mobile.
- The sign-in menu is usable by hover, focus, click, tap, and keyboard; it never depends on hover alone.
- Authentication and security forms remain single-column at narrow widths.
- Authentication fields support correct autocomplete, paste, and password managers.
- Users & Access hides or stacks secondary columns while preserving identity, state, and next action.
- Row actions remain in one labelled Action Group.
- Focus is visible and not obscured; labels and instructions are programmatically associated; errors identify fields, announce a summary, and focus the first error; state changes include screen-reader status text.
- Interactive targets meet the WCAG 2.2 minimum and use comfortable touch sizing where practical.
- Content remains usable at 200% zoom, in high-contrast mode, and with reduced motion.
- The Public Gateway remains one Bootstrap page. Programs, notices, FAQ, institution/location and map context, and support remain in the page body; the navigation uses in-page anchors and contextual sign-in. Support, Privacy Notice, and Accessibility may use the approved bounded Bootstrap modal treatment rather than separate public pages. Each modal is full-screen below Bootstrap's small breakpoint and wide, centered, and scrollable on larger screens, with a labelled title, close control, Escape dismissal, contained focus, and focus restoration.
- Approved hero media never carries task meaning by itself. Moving media is muted, pausable, has a poster, and becomes static under reduced motion. Missing media or an unavailable map embed leaves the textual content and external map fallback intact.
- The registration Privacy Notice link opens `/?modal=privacy` in a new tab so entered Filament form data remains intact while the same Public Gateway notice is shown; the notice is not duplicated inside Filament.
- Session-expiry warning allows the user to continue when the security policy permits without removing the accepted idle timeout.
- 403, 404, 419, 429, unexpected error, and temporary-unavailable pages preserve TALA identity and provide one safe recovery route.
- Validation appears beside the relevant field and preserves entered non-secret values when safe.
- Loading and empty states explain whether the user should wait, change a filter, create the first record, or contact the responsible owner.
- A direct unauthorized record route returns inaccessible behavior without leaking record existence.
## 12. Lifecycle, Validation, Confirmation, and Recovery

The following matrix is the controlling module-specific mutation contract. Product-wide primitives, stale/conflict behavior, and critical-action evidence are summarized here where they affect identity behavior.

### 12.1 Authority-hardening control matrix

| Action or record | Who and when | Validation/readiness | Confirmation and audit | Limits, deletion, and recovery |
|---|---|---|---|---|
| Applicant registration and verification | Public user while registration is available | Email primitive; password 15–64; acknowledgement; no protected duplicate disclosure | Submit does not need an alertdialog; successful creation records credential reference and verification event | One credential account per normalized email. Resend is one message per 60 seconds; token expires after 60 minutes; no duplicate account is created |
| Sign-in, MFA, and recovery | Account owner; Staff context requires enrolled TOTP | Five failed login/MFA attempts per normalized account/IP per minute; stricter Staff session policy; valid recovery token/code | Sensitive account changes require password reconfirmation no older than 15 minutes | Throttle waits for window reset, never permanent auto-lock. Recovery codes are shown once and complete-set replacement invalidates the prior set |
| Email change | Account owner after recent password confirmation and verification of the replacement | New email valid/unique; current account/version; no unresolved conflicting change | **Change sign-in email** shows session and notification consequences; audit old/new normalized address without exposing secrets | No second credential account. Stale/conflict posts nothing; prior verified email remains until successor verification completes |
| Staff invitation/resend/activation | System Administrator with Staff-access authority | Existing eligible verified account is reused; fixed role; unique active invitation; mail readiness | **Invite Staff** or **Resend invitation** shows role, recipient, expiry, and that the prior link becomes invalid | One active invitation per account/scope; expiry creates no account. Resend invalidates prior link; activation is idempotent |
| Role assignment/revocation | System Administrator | Fixed role set; authority/reason; current account; transactional final-active-System-Administrator protection | Named confirmation shows gained/lost workspaces and sessions; records before/after roles and reason | No role builder or account deletion. Rejected final-admin action changes nothing and is safe to retry after another administrator exists |
| Disable/reactivate | System Administrator for another account; self-disable is unavailable | Current account/state, reason/authority, affected contexts, and transactional final-active-System-Administrator protection | **Disable account** states that all sessions and contexts end while history remains; **Reactivate account** states restored access | Accounts are never archived or deleted. Disablement preserves every domain link; reactivation does not recreate roles or records |
| MFA reset | System Administrator under bounded recovery authority | Verified subject/recovery basis, recent actor password confirmation, current Staff account | **Reset Staff MFA** shows session termination and re-enrollment requirement | Old factor and recovery codes become unusable; no secret is displayed or recoverable |
| Public Notice/FAQ Draft | System Administrator | Title/label primitives; unique order within published group; Asia/Manila window; HTTPS link; safe content | Routine Draft save needs no confirmation | Hard-delete only before first publication and without references. Previously published content is unpublished or superseded, never deleted |
| Publish/unpublish public content | System Administrator | Current Draft/version, complete safe content, valid window/link/order | **Publish [type]** or **Unpublish [type]** shows public visibility and effective window | Atomic and idempotent; stale version posts nothing. Scheduled/public projections update without a generic archive state |

All identity mutations revalidate authorization and version server-side. Conflicting changes are never merged. Inaccessible and duplicate-account responses disclose neither whether another person exists nor their roles, state, or identifiers. Email delivery failure never reverses the access transaction and retains the same immutable email idempotency key.
## 13. Technical and Operational Boundaries

This PRD names conceptual product records, actions, and acceptance behavior; it does not prescribe physical tables, routes, classes, migrations, or an implementation sequence. A later journey-complete slice must reconcile Fortify, Filament panels, role assignments, policies, current account fields, tests, and every consumer against this authority before retaining or changing them.

## 14. Acceptance and Defense Scenarios

The implemented module must prove:

- Application entry open/closed, including existing Applicant sign-in while closed
- Minimal registration and no accidental application creation
- Duplicate-email guidance without account disclosure
- Verification required, resend throttle, expired/used links, and dispatch failure recovery
- Password creation/reset against the passphrase policy
- Session invalidation after completed recovery
- Applicant, Student, and Staff contextual sign-in with verified email
- Wrong-entry recovery without pre-authentication role disclosure
- Single-role direct routing and multi-role choosing/switching
- Staff invitation, 60-minute expiry, resend invalidation, activation, and forced MFA enrollment
- Valid/invalid TOTP and single-use recovery codes
- Externally authorized and audited MFA reset; no email bypass
- Staff-capable account unable to bypass MFA/session policy through Applicant or Student entry
- Fixed role matrix and direct unauthorized-page/record behavior
- System Administrator unable to perform academic, enrollment, payment, or grade actions without a separately legitimate role
- Disable/reactivate preserving roles and linked records
- Disabled-message privacy, self-disable rejection, and final-admin protection
- Learner versus Staff email-change ownership
- Official-enrollment hook granting Student access idempotently without a second account or Student number
- Public notice/FAQ publication windows, ordering, and safe links
- Factual Program projection from current Program/Admission Cycle authority without duplicate marketing records
- Approved hero-media and map fallback behavior, including pause/poster/reduced-motion and no dependency on media for meaning
- Ethical defaults, persisted progress/saved state, active choice for consequential actions, factual warnings, and visible alternatives
- Native search and role/state/verification/date filters with active indicators
- Empty, loading, validation, error, keyboard, screen-reader, desktop, and mobile behavior
- Policy traceability for every automatic security rule

### 14.1 Synthetic Demonstration Data

This module supplies credential and role contexts for the coordinated baseline of 47 Students, nine Faculty, Registrar, Accounting, Academic Head, and System Administrator personas. All identities use `example.test`; references are stable synthetic values and contain no real person data. PRD 01 owns only access facts and consumes no invented academic or finance detail.

| Reference | Persona and starting state | Roles/contexts | Demonstrated evidence |
|---|---|---|---|
| `C1-APP-ACTIVE` | Ana Applicant, `ana.applicant@example.test`, active and verified | Applicant | Open-entry sign-in, direct routing, Account Security |
| `C1-APP-VERIFY` | Vera Applicant, `vera.verify@example.test`, `VerificationRequired` | Applicant pending | Expired verification, resend, verification completion |
| `C1-STAFF-INVITE` | Felipe Invitee, `felipe.invitee@example.test`, `InvitationPending` | Faculty pending | Invitation expiry, resend invalidation, activation, MFA enrollment |
| `C1-STAFF-ACTIVE` | Regina Registrar, `regina.registrar@example.test`, active | Registrar | Contextual Staff sign-in, TOTP, Staff session policy |
| `C1-MULTIROLE` | Mara Multi-role, `mara.multirole@example.test`, active | Student and Faculty | Workspace chooser, switching, Staff policy across contexts |
| `C1-DISABLED` | Diego Disabled, `diego.disabled@example.test`, disabled | Applicant | Generic disabled guidance, inaccessible direct route, reactivation |
| `C1-FINAL-ADMIN` | Sienna Administrator, `sienna.admin@example.test`, active | Sole System Administrator | Rejected self-disable and final-administrator role-removal attempts |
| `C1-CONTENT` | Three notices and four FAQ entries | Public projection | Published, scheduled, unpublished, expired, explicit ordering, and unsafe-link rejection |

### 14.2 Browser Acceptance Walkthrough

| Persona / preconditions | Entry | Action | Visible evidence | Cross-role result | Output | Failure branch | Pass condition |
|---|---|---|---|---|---|---|---|
| Public visitor; entry closed then open | Public gateway | Inspect closed state, Programs, FAQ, map context, and contextual sign-in; then select **Apply** after Clinic 2 opens entry | Availability, active Program source, support, privacy/accessibility links, institution/location, and contextual sign-in remain clear | Existing Applicant sign-in remains available | Applicant registration entry only when open | Unsafe public link, unavailable map/media, or unavailable source shows a textual safe fallback | No duplicate marketing/CMS detour, no artificial urgency, and no application is created by registration |
| `C1-APP-VERIFY` | Applicant registration | Create account, open expired link, resend, verify | Generic duplicate protection, pending state, resend guidance, verified completion | System Administrator sees bounded state, not secrets | Verified Applicant access | Mail failure preserves account and offers retry/support | One account reaches Applicant workspace |
| `C1-APP-ACTIVE` | Applicant sign-in | Sign in through the wrong context, recover, then reset password | Context orientation, generic auth error, authorized destination, recovery result | Other roles remain undisclosed | Applicant workspace and invalidated old sessions | Used/expired recovery link has one safe action | Authentication occurs once and route authorization holds |
| `C1-MULTIROLE` | Staff sign-in | Complete MFA, choose Student, switch to Faculty | Only authorized contexts, current context identity, stricter Staff session policy | Student and Faculty projections remain separate | Correct destination for each chosen context | Direct unauthorized role route is inaccessible without record leakage | No combined-role dashboard or privilege merging |
| System Administrator and `C1-STAFF-INVITE` | Users & Access | Invite Staff, expire/resend link, activate, enroll MFA | Pending/expired state, invalidated old link, fixed roles, access evidence | Invitee receives Staff context after MFA | Activated Staff account | Duplicate-email path reuses an eligible verified account | Administrator never enters a password; one account is used |
| System Administrator and `C1-DISABLED` | Account detail | Disable, attempt sign-in/direct route, then reactivate | Required authority evidence, ended sessions, generic support guidance | All contexts blocked then restored | Immutable access-change evidence | Mail failure does not reverse access state | Linked records and roles remain intact |
| `C1-FINAL-ADMIN` | Account detail | Attempt self-disable and final-admin removal | Specific authorized-user error with no state change | Administration remains available | Rejected consequential-action evidence | Stale record requires refresh before retry | Final active administrator protection holds server-side |

Implementation verification must target **test_tala_db** for DB-backed tests, use focused PHPUnit, format modified PHP with Pint, run narrowed Larastan for changed typed paths, run **git diff --check**, and complete browser acceptance. Those checks are implementation gates, not evidence that the current application already conforms.

## 15. Evidence and Decision Basis

| Claim area | Evidence class | Treatment |
|---|---|---|
| Proportional identity collection, controlled access, and security safeguards | [Data Privacy Act](https://privacy.gov.ph/data-privacy-act/) and [NPC security guidance](https://privacy.gov.ph/npc-issues-circulars-to-strengthen-personal-data-protection-in-ph/) | Governing privacy/security principle |
| Password/passphrase and authenticator guidance | [NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b.html) | Security benchmark, not Philippine college policy |
| LRN meaning | [DepEd Order No. 22, s. 2012](https://www.deped.gov.ph/2012/03/20/do-22-s-2012-adoption-of-the-unique-learner-reference-number/) | Confirms LRN is a basic-education identifier, not a college sign-in credential |
| Authentication, verification, password broker, sessions, email change, MFA, and Filament surfaces | [Laravel authentication](https://laravel.com/docs/12.x/authentication), [Laravel Fortify](https://laravel.com/docs/12.x/fortify), and version-compatible Filament capabilities | Technical implementation boundary |
| Existing Fortify, panels, Spatie roles, public/auth shell, and current account fields | Current repository inspection | Implementation evidence only |
| Academico identity model | Bounded qualified-reference inspection | Rejected as product authority; only generic session/policy patterns may be adapted |

No Philippine higher-education source requires username login, a role builder, an HR profile, a general CMS, or the legacy account state machine. Those features therefore require their own proven institutional need; none was established for Clinic 1.

## 16. Assumptions and External Responsibilities

- The institution's authorizing office supplies Staff-role approval and completes off-system identity proof for lost-factor recovery. These are external operational inputs; System Administrator records only the authorized assignment or reset result and its evidence.
- Account disablement preserves every domain link. Ordinary UI never deletes Account or security history; lawful retention/privacy/disposal operations remain external under the product-wide boundary.
- No PRD 01 product-policy ambiguity remains for implementation planning.
