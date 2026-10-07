# TALA System Definition Baseline

## Purpose and Authority

This document defines TALA's product-wide goal, boundaries, terminology, ownership, policy classes, shared mutation/validation rules, coordinated acceptance institution, and cross-module handoffs. PRDs 01–06 own their complete module behavior.

Read the owning journey PRD for product behavior and the UI Surface Blueprint for user-visible capabilities, navigation, states, responsiveness, accessibility and acceptance coverage. Each delivery slice cites its governing contracts, reconciles existing implementation evidence and follows the authorized execution boundary in the Orchestrator Protocol. The Documentation Authority Registry classifies supporting and historical evidence.

Statement-status rules:

| Status | Meaning |
|---|---|
| **Accepted** | Explicitly selected product direction or applicable governing rule. It remains active unless the user reopens it or stronger authority contradicts it. |
| **Supporting evidence** | A claim from business evidence, legacy material, implementation, a benchmark, or an outside reference. It cannot govern behavior without adoption through the authority hierarchy. |
| **Open** | A material decision still requiring evidence and user resolution. |
| **Conditional** | Applicable only after the stated institutional authority or verified condition exists. |

Sections 1–3 contain the accepted product goal and shared foundation unless a statement is explicitly conditional. Sections 4–9 contain concise module summaries; complete detail belongs in PRDs 01–06 and their UI authorities. Section 11 owns the shared standalone-authority contract, and Section 12 owns cross-module acceptance.

The final approved authority set is:

1. This baseline for the product goal, evidence rules, shared system boundaries, module ownership, and definition process.
2. Six standalone journey PRDs, stored beside this baseline as canonical files `01`–`06`, for the complete product-authority details of Identity and Access; Application, Admission Decision, and Enrollment Readiness; Academic Setup and Scheduling; Enrollment; Grades and Records; and Accounts and Operations.
3. The shared UI Surface Blueprint for workspace behavior, navigation, visual foundations, reusable components, user-visible capability coverage, and PRD-owned page and journey projections.

If a PRD conflicts with this baseline, the conflict must be reconciled explicitly. Neither document silently overrides the other.

## Foundation and Shared Rules

Its governing content is:

- **Section 1:** Product Goal
- **Section 2:** Evidence hierarchy, authority structure, lean boundaries, and completeness rules
- **Section 3:** Shared roles, cross-role records, configuration, readiness, communication, contextual operational views/exports, and public-content boundaries
- **Section 10:** Requirements that every standalone PRD and UI definition must satisfy

Sections 4–9 identify each journey's owner and minimum connected outcome. The owning PRD supplies its detailed behavior; delivery progress and review evidence belong to the active Issues.

## 1. Product Goal

The goal is to refine and deliver TALA as a lean, clear, factually defensible, user-centered, and defense-ready Philippine college information system. Reuse sound existing work and simplify the parts that obstruct the connected learner journey.

TALA must:

- **Use the leanest defensible implementation.** Remove, simplify, or externalize functionality when the institutional result can be achieved through a smaller and clearer workflow.
- **Follow authoritative Philippine higher-education rules.** Law, regulator publications, and approved institutional policy govern the product. Existing workflows and business evidence remain evidence requiring scrutiny.
- **Assume a normally recognized and authorized Philippine college.** Recognition-pending workarounds and student-facing recognition concerns do not belong in the product.
- **Remain understandable from every user's perspective.** Each role sees only the information, action, evidence, and next step needed for its responsibility while sharing the same authoritative records.
- **Define the complete product before judging the implementation.** Every module settles its narrative, exact data, states, alternatives, invalid cases, setup, readiness, actions, emails, outputs, UI, and exclusions before code or schema classification.
- **Plan the interface as part of the product.** Define required information, actions, states, access and recovery. Impeccable leads task-specific composition using suitable native components. Use visual alternatives or sketches when they help resolve a design decision.
- **Deliver complete vertical slices.** A finished module visibly works across data, rules, authorization, every participating role, emails, outputs, audit evidence, realistic data, tests, and browser-verified desktop and mobile journeys.
- **Preserve good work without becoming trapped by it.** Existing Laravel, Filament, authentication, scheduling, integrations, and tests survive only when they align with the approved product.
- **Remain demonstrable and technically defensible.** TALA must resist invalid and out-of-order actions, explain failures clearly, and support a convincing end-to-end defense within the capstone refinement period.

In one sentence:

> Define and deliver a lean, coherent, user-centered, policy-aligned, defense-ready Philippine college information system—preserving existing work only where it supports the correct product, and simplifying or rebuilding anything that does not.

TALA is Servitech-first rather than a speculative multi-school platform. Institutional authority belongs to the school: all workspaces, student/applicant surfaces, official documents, and notifications must lead with the official institutional identity (**Servitech Institute Asia Inc.** or **Servitech Institute Asia**). System identity is strictly secondary (e.g., *Powered by TALA* or *Generated through TALA from authenticated records*). Bare "TALA Staff Workspace", standalone "TALA" branding, or system-first presentations where institutional authority is exercised are prohibited.

## 2. Authority, Product Boundary, and Completeness Reset

### 2.1 Evidence hierarchy

Every enforceable rule must be traceable to its authority:

| Evidence class | Treatment |
|---|---|
| Philippine law or applicable regulator rule | Mandatory when applicable |
| Approved institutional policy | May define institution-specific values and procedures |
| Observed business workflow or form | Useful evidence requiring validation; never automatic authority |
| Panel or stakeholder feedback and current UI observation | Problem evidence requiring validation; the suggested solution is never automatic authority |
| Mature-system pattern | Design benchmark, not Philippine policy |
| Current implementation, schema, tests, or historical PRD | Supporting implementation/historical evidence only |
| Unverified assumption | Removed or retained visibly as unresolved |

TALA is **Servitech-first but not Servitech-evidence-limited**. Approved Servitech evidence establishes confirmed local terminology, roles, records, outputs, and policies within its actual scope. Evidence that is unavailable or confidentiality-restricted cannot be disclosed, inferred, or treated as confirmed Servitech policy, but its absence cannot justify omitting a necessary SIS capability or leaving its journey incomplete.

When approved Servitech evidence does not settle necessary behavior, resolve the gap in this order:

1. Applicable Philippine law or regulator rule.
2. Approved Servitech evidence within its evidenced scope.
3. A qualified Philippine institutional comparison, labelled with its covered population, period, and policy scope.
4. A mature-SIS operational pattern, used only to test workflow competence.
5. A lean, safe, proportionate, and correctable TALA default when one can be justified.
6. A complete policy-gated workflow when no safe default exists, including the owner, required authority, usable remainder, blocked action, learner explanation, recovery, and reopening condition.

The business evidence may shape terminology, realistic fields, document layout, and office handoffs, but it must not preserve an incorrect or unnecessarily complicated workflow. Panel and stakeholder observations identify problems that a clinic must investigate; they do not prove that the suggested feature or UI treatment is the correct solution. Philippine institutional comparisons and mature systems identify competent SIS capabilities and patterns; their institution-specific grade vocabulary, thresholds, sanctions, deadlines, fees, and workflow scale are never copied into TALA automatically.

Primary policy sources include:

- [TESDA UTPRAS requirements](https://tesda.gov.ph/About/TESDA/26), [TESDA assessment and certification](https://tesda.gov.ph/About/TESDA/25), the [TESDA assessment FAQ](https://tesda.gov.ph/About/Tesda/127), and [TESDA Circular No. 021, s. 2023](https://intranet.tesda.gov.ph/circulariframe?dateIssueFilter=2023)
- [CHED Manual of Regulations for Private Higher Education](https://legacy.ched.gov.ph/manual-regulations-private-higher-education-morphe/)
- [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html), particularly the limits on denying examinations to qualified disadvantaged students
- [Data Privacy Act and NPC guidance](https://privacy.gov.ph/data-privacy-act/) for proportional collection, access, retention, and disclosure

These are starting authorities, not blanket proof for every module rule. Each PRD must cite the exact applicable source and scope for every automatic rule, classify a bounded TALA default explicitly, or define the complete policy gate and external owner of a restricted action. `Unavailable` by itself is never a final capability disposition.

An institutional value such as a deadline, grading formula, overload exception, fee amount, or drop effect is never copied from another institution or retained from the old PRD as if it were Servitech policy. TALA may provide the necessary effective-dated input when the value is truly variable, but enforcement begins only after an authorized institutional value exists.

### 2.2 Baseline and standalone PRDs

The canonical product authority consists of one baseline and six complete journey PRDs:

1. **00 — TALA System Definition Baseline**
2. **01 — Identity, Access, and Public Entry**
3. **02 — Application, Admission Decision, and Enrollment Readiness**
4. **03 — Academic Setup, Offerings, and Published Timetable**
5. **04 — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop**
6. **05 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion**
7. **06 — Accounts, Official Outputs, Operations, and Assurance**

The baseline establishes the product-wide rules. Each journey PRD owns the exact narrative, records, states, role actions, readiness requirements, emails, outputs, UI, and acceptance contract for its module.

The list above is the **canonical authority set**:

- `00_system_definition_baseline.md` owns the shared product definition and current authority status.
- `01_identity_access_public_entry.md` through `06_accounts_official_outputs_operations_assurance.md` own the six approved journeys.
- Replaced PRD inputs are preserved intact in [`_legacy/`](./_legacy/) as non-authoritative evidence. Their filenames, numbering, or content cannot override `00`–`06`.
- PRD 03 remains one unified **Academic Setup, Offerings, and Published Timetable** authority; the archived Term Offerings and Resources and CP-SAT Scheduling inputs are not independent journey authorities.
- The complete authority set is standalone and approved for separately planned journey-complete vertical delivery; this approval does not itself authorize implementation or task derivation.

Every material capability, role action, official output, external/manual result, integration failure, and cross-role effect has an owning authority or an explicit exclusion. Section 12 records the product-wide acceptance coverage. Supporting evidence may identify a future contradiction or feasibility issue, but it cannot change product behavior without first updating the affected canonical authority.

### 2.3 Product boundary

- External judgments such as discipline, readmission, overload, program shifting, document authenticity, transfer credit, late adjustment, and graduation clearance normally remain with the responsible office. TALA records the authorized result, authority, evidence reference, effective dates, and direct system effects.
- TALA does not add generic approval engines, universal override records, configurable state machines, policy DSLs, or workflow builders without a later verified need.
- Existing code and schema remain implementation evidence until reconciled within an authorized vertical slice.
- CP-SAT is the principal intelligent capability. PayMongo is an optional exact-due payment integration and cannot block the core school journey.

## 3. Shared Operating Contract and Cross-Role Presentation

### 3.1 One record, role-specific projections

The accepted presentation model is:

`Role work queue → shared authoritative record → one primary action → contextual evidence and history`

Draft interaction follows one preparation contract: identify what is saved versus unsaved, allow incomplete preparation where the owning schema and PRD permit it, resume the same source, offer Save and exit with a truthful destination, and confirm discard only when that domain allows it. Publication/submission readiness remains separate from preparation validation. Reuse native save/status/validation components and vocabulary; each domain retains its own minimum identity, ownership, versioning and Submit/Activate/Issue/Publish/Release transition. No generic Draft workflow or deletion engine is introduced.

Inputs have an explicit task purpose: identity, operational choice, policy source, external approval/evidence or internal review basis. Derive actor/time/current source from authoritative records. Require a separate reference only where it establishes an actual policy source, external fact or consequential authorization under the owning PRD. Developer unfamiliarity alone changes no requirement. Explain the source with a relevant example; preserve historical references and protected content.

TALA does not create separate role-owned copies of the same application, student, enrollment, timetable, grade, or account state. Every role projection retains the same identifier, status vocabulary, owner, effective date, and next action.

Approved workspace map:

- **Public:** institutional information, FAQ/notices, application entry, and sign-in
- **Applicant:** Home, Application, and contextual Requirements
- **Student:** Home, Enrollment, Academics, Finance, and Profile
- **Registrar:** Admissions; Catalog & Curricula; Term Planning; Students & Enrollment; Grades & Completion
- **Accounting:** Fee Plans; Student Accounts with Accounts, Payment Exceptions, and TOR Clearance tabs
- **Faculty:** My Availability; My Schedule; Grade Rosters
- **Academic Head:** read-only Academic Oversight linking source-owned academic authority, timetable, grade/progress, lifecycle, and completion evidence
- **System Administrator:** Users & Access; Public Content; System Health; Governance & Audit

Academic Head is not a universal co-approver. System Administrator does not decide academic calendars, curricula, fees, enrollment eligibility, payments, or grades.

### 3.2 Configuration and readiness

There is no miscellaneous central Settings product. Setup belongs to its domain owner:

- **Variable source records:** approved dates, programs, curricula, rooms, faculty hard unavailability, offerings, capacities, fee plans, and versioned requirement checklists
- **Protected institutional policy:** verified grading policy, scale, authorities, drop effects, and other approved rules
- **Fixed safeguards:** authorization, prerequisites, conflict prevention, audit history, privacy, and payment idempotency
- **Environment-managed integrations:** SMTP, solver credentials, and PayMongo secrets
- **Recorded external decisions:** overload, late adjustment, transfer credit, discipline, shifting, and externally approved funding coverage effects

Every configurable item must identify its owner, scope, effective date, and consuming action. A variable record without a real consumer is removed.

Readiness belongs beside its consuming action. Explain failed checks, name the source owner and remedy, and provide a concise successful-check summary with accessible evidence. Role authorization governs source links. A calculated `Blocked` result changes only when its authoritative sources satisfy the action's guards.

The baseline owns only this universal readiness behavior. Each journey PRD defines the authoritative inputs required by its own actions, who owns them, what validity means, and whether a missing or invalid input blocks, warns, or degrades that action. TALA does not create one abstract global settings model or require a complete cross-system source-record inventory before the module clinics.

Readiness communicates dependency order, current source results, responsible owner, applicable deadline and the next permissible action. The owning PRD supplies those facts; Impeccable and the UI Blueprint guide a task-appropriate presentation.

Readiness results use:

- Hard blocker
- Advisory warning
- Degraded integration
- Passed

Failed items show the responsible owner, evidence/source link, and next action. Successful checks use a concise summary with accessible source detail. Missing SMTP never reverses an academic or financial transaction. PayMongo failure disables only optional checkout. Solver failure never changes an already published timetable.

**Setup order and unavailable actions.** For an authorized user, an applicable action blocked by incomplete setup remains discoverable with a persistent plain-language reason, the missing source, its responsible owner and the exact remedy that enables it. Show a source link only when that user may access it; otherwise show safe office/contact guidance. Do not rely on a disabled-button tooltip. Useful Draft entry and independent work remain available. Actions forbidden by authorization remain undisclosed where disclosure would expose protected information. Server-side guards enforce the same prerequisites even when the UI is bypassed.

| Work to enable | Minimum dependency order | Blocked action and source owner |
|---|---|---|
| Account entry and Staff access | Deployment security/contact configuration → account verification; invited Staff then complete MFA | Unverified entry or Staff access; account holder/System Administrator. Closed admissions never disables existing-account sign-in. PRD 01 owns recovery. |
| Application entry | Target Term exists → approved accepting Program/path and preliminary requirement version → valid Cycle dates/instructions/private storage → Registrar publishes Cycle | Start/first submission while the Cycle or private evidence source is unavailable; Registrar owns setup. A published timetable or Fee Plan is not an admissions prerequisite. PRD 02 owns correction/review boundaries. |
| Admissions readiness | Submitted current facts and identity review → admitted decision → current Registrar enrollment clearance | Admission decision while identity/review is unresolved; enrollment readiness while clearance is absent, stale or action-needed. Registrar owns resolution. |
| Timetable generation/publication | Approved active curriculum and exact Term calendar → confirmed classes → complete Faculty/room/meeting inputs → valid candidate → recorded external sign-off/publication | Generate or publish with the named missing/current source; Registrar owns setup and publication, Faculty owns their availability declaration. Draft setup remains usable. PRD 03 owns each check. |
| Enrollment | Exact-Term window and eligible identity/results → current proposed subjects/classes → learner confirmation and valid placement → current Accounting assessment/clearance → Registrar finalization | Only the consuming checkpoint/action; Registrar owns academic/placement sources, Accounting owns assessment/clearance. Readiness or payment is never official enrollment. PRD 04 owns atomic finalization. |
| Grade release and later registration | Official membership/assigned Faculty/open entry window → complete submitted roster → Registrar release → current academic evaluation | Submit/release with missing or stale rows/source; designated Faculty or Registrar owns the remedy. Unreleased prerequisites exclude only dependent courses; eligible remainder stays usable. PRD 05 owns results. |
| Finance and transcript output | Confirmed registration → valid fixed plan or eligible exact individual assessment → verified account effects; TOR separately needs releasable history, request, signatory/template and request-specific clearance | Accounting owns missing assessment/payment evidence; Registrar owns transcript sources. Graduation is not a universal TOR prerequisite. PayMongo failure disables only checkout. PRDs 05–06 own the checks. |

The owning PRD matrices define each dependency's exact validity, correction and recovery. Complete the prerequisites for the consuming action; keep independent work available.

### 3.3 Contextual communication

TALA uses contextual statuses plus selective transactional email. It does not build a persistent notification-center subsystem.

The accepted V1 communication boundary is owned event by event by the relevant module PRD. Clinic 1 owns these essential security messages:

- Email verification and resend
- Password recovery
- Staff invitation
- Email-change verification and alerts
- Account disable/reactivate notice
- Staff-role-change notice

Clinic 2 owns these admissions messages:

- Application submission with the stable reference
- One consolidated Action Needed request
- Admitted with official-credential instructions
- Not Admitted
- Ready for Enrollment
- Withdrawal confirmation

The final module-level ownership is:

| Owner | Authorized events | Source and failure boundary |
|---|---|---|
| Clinic 1 | Verification/resend, password recovery, Staff invitation, email-change verification/alerts, account disable/reactivate, Staff-role change | Credential/access event; failure never changes account state |
| Clinic 2 | Submission, one consolidated Action Needed request, Admitted, Not Admitted, Ready for Enrollment, withdrawal | Application/decision/readiness reference; workspace remains authoritative |
| Clinic 3 | Faculty availability request, first timetable publication, one shared published-revision event | Availability request or published version plus recipient identity; Clinic 4 supplies affected enrolled-Student context without sending a duplicate |
| Clinic 4 | Continuing-Student enrollment window, proposal ready/materially revised, payment/coverage action, official enrollment/COR, reservation release/case expiry, adjustment/Course Drop | Owning window/proposal/case/COR/change version; first-enrollment message also announces Student access |
| Clinic 5 | Grade-roster action/return/release, INC release/deadline and resolution, deadline amendment, correction, progress/lifecycle, completion action, conferral | Owning roster/result/deadline-amendment/decision/conferral reference; deadline passage alone sends no email; grade values and attachments are excluded |
| Clinic 6 | Verified payment posted | Immutable posting reference; exactly one message |

Every message uses its owning record plus recipient identity as the idempotency source. Grade-release email contains no grade values or attachment—only a release notice and secure portal link.

No email is sent for ordinary saves, navigation, successful or failed sign-in attempts, solver failure, internal queue movement, export, payment-proof submission/rejection, checkout return, payment exception creation, TOR clearance, reversal, System Health change, routine calculation/readiness checks, or recurring reminders. Delivery failure is logged and retried without rolling back institutional state. Templates remain code-defined; there is no template editor.

### 3.4 Operational views, contextual exports, and public content

- Operational information remains in its owning queue, record, or read-only projection. There is no top-level Reports destination, report hub, BI product, report designer, or duplicate reporting page.
- The approved contextual data-file outputs are Clinic 6's two allowlisted, purpose-recorded finance CSV exports and Clinics 4–5's one current, per-Class-Offering roster CSV. The roster CSV is available only from an authorized selected roster, uses fixed minimum teaching-roster columns, and never becomes a generic Student, enrollment, grade, or reporting export. Other modules provide only their approved operational views and printable outputs.
- Admissions analytics means a small operational summary—counts and aging—above the same filtered Admissions queue. It is not scoring, forecasting, or applicant ranking.
- Staff queues use meaningful date ranges and native Filament filters with active-filter indicators. TALA does not reproduce custom dropdowns in every column header.
- Public FAQs answer recurring process questions; announcements communicate current school notices, dates, and changes. [PRD 01 Section 4.7](01_identity_access_public_entry.md#47-public-content) owns their fields, publication, ordering, and history.

[CHED's HEMIS orientation](https://region1.ched.gov.ph/chedro-spearheads-2024-hemis-orientation/) and [Citizen's Charter CAV process](https://ched.gov.ph/wp-content/uploads/CHED-Updated-CC-2025-1st-edition-033125.pdf) confirm external HEI data-submission and academic-record-verification responsibilities; they do not prescribe a Servitech-specific TALA workflow. CHED and other regulator submissions therefore remain an external institutional responsibility. TALA retains the approved source records from which an authorized office may later prepare an Enrollment List, Promotional Report, List of Graduates, Special Order support, CAV evidence, HEMIS submission, or another prescribed return, but it does not invent a generic Reports destination, speculative demographic fields, an unapproved template, or a regulator-portal workflow. An exact regulatory output may be considered only after Servitech supplies the applicable authority, prescribed format, responsible owner, submission process, privacy basis, and acceptance evidence.

### 3.5 Capability ownership and explicit exclusions

The complete product remains lean because every normally expected SIS capability is either owned by one canonical journey or explicitly left with its responsible external process. This matrix is a traceability index; detailed behavior remains in the owning PRD.

| Capability | Authority and record owner | User journey and UI projection | Failure, correction, or output | Scope disposition |
|---|---|---|---|---|
| Identity, access, and public entry | PRD 01; account, role assignments, invitation, and verification | Public Gateway, unified sign-in, authorized post-authentication context resolution, Users & Access | Recovery, disablement, inaccessible routes, access correction, security email | Retained |
| Admissions | PRD 02; Admission Cycle, Application, evidence, decision, and Registrar enrollment clearance | Applicant Home/Application/Requirements; Registrar Admissions | Correction, withdrawal, reopening, superseding decision, acknowledgment | Retained |
| Academic authority and curriculum | PRD 03; Program, Course Revision, Curriculum Version, and authorized external-competency requirements | Catalog & Curricula; read-only Academic Oversight | Import finding, blocked activation, successor authority, external-result source correction | Retained with bounded external evidence |
| Terms and offerings | PRD 03; Term Calendar Package, Term Cohort, and Class Offering | Term Planning; Faculty availability and informational Examination Period projections | Missing authority, incomplete resource, Additional Offering correction, unavailable calendar source | Retained |
| Scheduling | PRD 03; solver request/result, candidate, and Published Timetable Version | Generate & Review, Published Timetable, Faculty/Student projections | Infeasible, Unknown, ModelInvalid, TechnicalFailure, bounded correction, revision | Retained |
| Registration | PRD 04; Registration Case and proposal versions | Learner Enrollment and Registrar Students & Enrollment | Proposal revision, assisted confirmation, expiry, cancellation | Retained |
| Enrollment | PRD 04; placement, reservation, five-checkpoint readiness, and official enrollment | Learner status, Registrar workbench, Accounting clearance | Shortage, stale placement, missing assessment, failed finalization | Retained |
| COR | PRD 04; immutable COR versions and finalization snapshot | Authenticated current/historical COR | Adjustment or Course Drop successor, superseded version, print failure | Retained |
| Grades and averages | PRD 05; roster results, bounded operational metadata, average projections, and externally verified competency results | Grade Rosters, Grades & Completion, Student Academics | Return, INC completion/overdue state/deadline amendment, correction, Grades not complete, superseding external result | Retained |
| Lifecycle and completion | PRD 05; curriculum evaluation, progress, lifecycle, completion, and conferral records | Student Academics, Registrar workbench, Academic Oversight | Pending source, authorized decision, superseding result, authority-gated external requirement | Retained |
| TOR | PRD 05 fixed TALA Standard TOR authority plus PRD 06 request-specific clearance | Registrar preview, issuance, and history | Missing source/certification data or clearance; output failure; void/replacement | Retained within the approved external boundary |
| Accounts and assessments | PRD 06; Fee Plan, Authorized Individual Assessment, and Term Account | Fee Plans, Student Accounts, Student Finance | Unavailable/stale assessment, append-only correction | Retained |
| Coverage and payments | PRD 06; Approved Coverage, evidence, verified posting, and PayMongo attempt | Account detail, Payment Exceptions, learner Finance | Rejection, mismatch, pending webhook, reversal, supersession | Retained |
| Official outputs and contextual exports | Owning PRDs; seven canonical outputs plus the two Clinic 6 finance CSVs and one Clinics 4–5 current Class Roster CSV | Authenticated print/read-only surfaces and authorized contextual CSV actions | No partial artifact, explicit version/state, formula-safe CSV, output-access audit | Retained |
| Privacy, audit, and retention | PRDs 01–06 and Architecture | Private evidence, Governance & Audit, contextual history | Non-disclosing failure; automatic disposal is outside the MVP | Retained |
| Operations and integrations | Architecture and PRD 06 | System Health and locally evidenced projections | Unknown/Not checked by TALA, degraded service, safe continuity | Retained |
| Regulatory submissions | External institutional responsibility; TALA retains source records only | No current Reports destination or speculative submission UI | Reopen only for an exact authority, format, owner, privacy basis, and acceptance process | External boundary recorded |

The supplied Servitech curriculum-evaluation forms separately track TESDA qualification assessment dates and remarks. TALA therefore permits an approved `CurriculumVersion` to identify a bounded external-competency requirement and Clinic 5 to record its externally verified result. TESDA or its accredited assessor remains authoritative for the judgment and certification. TALA does not conduct, schedule, charge for, issue, renew, or verify a TESDA assessment or certificate through an operational integration. A requirement is `TrackedOnly` unless an exact approved Servitech curriculum authority makes it `CompletionRequired`; supplied evaluation sheets alone cannot create a completion block.

The approved term-level `Examination Period` is sufficient for the current Servitech scope. Its dates, calendar authority, package version, owner, and as-of time are projected read-only in Term Planning, Academic Oversight, Faculty My Schedule, Student Home, and Student Academics. Exact class arrangements remain Faculty-owned and use the approved teaching channel. Missing or stale calendar evidence shows **Examination period unavailable — contact Registrar or Faculty** and never creates a date from class meetings. No class-level exam record, examination timetable, facility/proctor/seating/permit workflow, assessment-content feature, email, output, generic event system, or financial examination hold is introduced.

The final inclusion/exclusion register applies a stricter negative-space test. Institutional occurrence alone does not justify digitizing a process. TALA retains a fact only when omitting it would break an accepted journey, lose a required authoritative source, force an unsafe shadow record, or prevent a necessary learner or Staff action. `Minimal retained TALA effect` never transfers ownership of the external process.

The retained scope supports the connected college lifecycle through the following minimum outcomes:

| Area | Minimum outcome |
|---|---|
| Public and access — PRD 01 | School-first factual discovery, one sign-in, application entry, fixed authorized roles, account security/recovery and bounded notices/FAQ. |
| Admissions — PRD 02 | Necessary intake, selected private preliminary copies, scoped correction/decision and one attributable Registrar enrollment clearance. Historical records remain available to authorized reviewers. |
| Academic setup/scheduling — PRD 03 | Approved catalog/curriculum/calendar, actual classes/resources/Faculty availability, CP-SAT candidates, independent validation and human publication. Retain the core innovation; external approvals remain result/reference intake. |
| Registration — PRD 04 | Reuse identity/application and released academic records, confirm proposed subjects/classes, protect placement, consume current clearance/assessment, finalize enrollment and COR through one Registration Case. |
| Academic records — PRD 05 | Faculty final results, Registrar release/correction, factual evaluation, necessary lifecycle/completion and official outputs. Retain; raw gradebooks, attendance, appeals and physical fulfillment stay external. |
| Finance/assurance — PRD 06 | Accounting-owned exact assessments, approved coverage and verified postings, action-specific clearances, truthful account views, necessary outputs/audit/local health. Retain; cash/refund execution and provider/office operations stay external. |

A field must have an exact actor/purpose, collection stage, authoritative source, visible label, requiredness, format/limits and applicable exception. Reuse or derive an existing fact. Distinguish optional, unknown and inapplicable values; show missing source data as unavailable. Use plain task language for visible labels.

**Product defaults.** Use supplied institutional evidence as the local backbone. Owner-delegated product choices use scoped Philippine and mature-SIS comparisons, official framework guidance and relevant skills. Identify each adopted default as a TALA project choice, with its source, limits, owner and correction path. Preserve security, privacy and record integrity. PRD 05 owns the bounded one-year, nonautomatic INC default. Show academic readiness in its owning source/workbench and use the fixed labels Term weighted average and Cumulative GWA.

**Connected workflow.** The owning state/action and readiness matrices govern the journey: one verified account feeds admissions; one current admissions clearance feeds registration; approved curriculum/calendar/resources feed candidate generation and human timetable publication; one confirmed registration/placement feeds exact Accounting assessment and atomic enrollment; released official results feed academic evaluation and later registration. TOR issuance consumes releasable history under PRD 05 Section 8. Graduation/conferral has its own recorded authority. Accounting executes refunds externally and records verified local corrections. A missing source blocks its named consuming action while independent work remains available. The derived [lifecycle flowchart](../TALA-System-Lifecycle-Flowchart.md) presents the connected journey. The Orchestrator Protocol governs environment-neutral roles, accepted plans, authorized execution, independent review and completion/publication boundaries.


| Capability | Institutional occurrence | External/inside owner | Authoritative source | Affected canonical records | User-visible need | Consequence if omitted | Minimal retained TALA effect | Final verdict | Reopening evidence |
|---|---|---|---|---|---|---|---|---|---|
| Identity, admissions, curriculum, terms, offerings, timetable, registration, enrollment, COR, grades, averages, lifecycle, completion, TOR, accounts, coverage, payments, outputs, privacy, audit, retention, and assurance | Yes | PRDs 01–06 and Architecture | Canonical authority set | Existing canonical records | Complete role journeys and official projections | Core SIS journey fails | Existing approved behavior | Included | Reopen only the affected authority on stronger evidence or material feasibility conflict |
| Institution-wide Examination Period | Yes | Academic Head approves externally; Registrar records | Approved Term Calendar Package | `OperationalWindow` and calendar projection | Students and Faculty need the approved period and source | Users rely on untraceable informal dates | Read-only period, source/version, owner, as-of time, and unavailable state | Included as informational projection | Exact approved calendar authority changes its institutional effect |
| Class-level examination date/time | Faculty schedules exact arrangements in supplied workflow evidence | Faculty/teaching process | Faculty's approved teaching channel | None | Exact arrangements remain discoverable outside TALA | No accepted central schedule is lost | Term-level Examination Period only | Excluded for current scope | Servitech supplies one centrally published schedule, owner, source, and required TALA projection |
| Examination timetabling, rooms, proctors, seating, permits, content, and raw scores | May occur institutionally | Faculty and academic operations | External teaching/examination process | Released roster result only | No accepted SIS journey requires operational controls | Adding it would create a second scheduling/assessment system | Controlled final result per official roster row | Excluded | Approved journey that cannot be satisfied by the period plus final-result intake |
| External TESDA-linked curriculum result | Present in all supplied curriculum-evaluation examples | TESDA/accredited assessor judges; Registrar records verified evidence | Active Curriculum Version plus external assessment/certification evidence | External competency requirement/result and Curriculum Evaluation | Student and Staff need the tracked qualification result and its curriculum effect | Omission forces a separate shadow evaluation record | Authorized requirement plus append-only verified result | Included as bounded external evidence | Exact curriculum authority changes the requirement or its completion effect |
| TESDA application, training, scheduling, assessment, certification, fees, renewal, and registry operations | May occur | TESDA, accredited centers/assessors, learner, and institution | TESDA rules and external records | External result reference only | TALA need not operate the external process | Scope expands into a TVET administration platform | Safe qualification/result/source projection | Excluded | Separately approved operational scope, integration authority, and journey |
| LMS, attendance, raw-score gradebook, assessment authoring, and teaching delivery | Yes | Faculty and teaching platforms | Institutional teaching process | Official roster and final result | Official result must reach the academic record | A second gradebook creates conflicting authority | One controlled final result per roster row | Excluded | Explicit requirement that final-result intake cannot satisfy |
| HR, payroll, Faculty employment, and workload approval | Yes | Institutional administration/HR | HR and institutional decisions | Faculty eligibility, capacity, and assignment facts | Scheduling needs authorized resources | Scheduling could use unapproved Faculty facts | Approved identity, eligibility, capacity, and assignment | Excluded | Approved scope with exact owner, rules, records, and cross-journey need |
| Library, discipline, guidance, grievance, and appeal operations | May occur | Respective institutional offices | Their approved process | Only an authorized consequential result when required | Existing journeys need only the final authorized effect | Operational duplication creates unsafe parallel cases | Safe source-owned consequential result | Excluded | Approved policy and journey-complete MVP use case |
| Internship/practicum placement and supervision | Yes for applicable curricula | Program office and external partners | Approved curriculum and placement process | Curriculum entry, enrollment, grade, completion | Learner record must retain the requirement | Scheduling fictitious meetings or duplicating supervision is misleading | Externally arranged/no-recurring-meeting treatment | Excluded operationally | Approved process, owner, required system record, and unmet journey |
| Tutorial/remedial administration | May occur | Academic authority outside TALA | External approval | `Additional` Class Offering | Catch-up class must be registrable and schedulable | A separate status/workflow would duplicate Class Offering | Externally approved Additional Offering | Excluded as subsystem | Servitech adopts distinct behavior that Additional Offering cannot represent |
| Foreign, cross-enrollee, second-degree, non-degree, special, and refresher admissions | Not established for MVP | Registrar external intake | Category-specific institutional rules | Authorized result only if later consumed | None in accepted FirstYear/Transferee journey | Invented fields and rules create false eligibility | No speculative intake workflow | Excluded | Servitech selects a category and supplies its exact journey and requirements |
| Parent/guardian portal or unrestricted academic-record access | No accepted college journey | Student and institution under applicable privacy/consent rules | Approved consent and privacy authority | Under-18 admission contact only | No independent portal need is established | Unauthorised disclosure risk | Guardian contact only when the applicant is under 18 | Excluded | Approved consent model, role, purpose, permissions, and revocation journey |
| Scholarship eligibility, application, ranking, renewal, and disbursement | May occur | Scholarship/provider and Accounting processes | External funding authority | `ApprovedCoverage` on one Assessment/obligation | Learner must see the approved account effect | A scholarship module would invent eligibility and money movement | Append-only Approved Coverage | Excluded operationally | Approved administration scope beyond recording coverage |
| Cashiering, registered invoices, official receipts, ledger, budgeting, procurement, refunds, penalties, allocations, and collections | Yes outside TALA | Accounting and registered financial procedures | Accounting and applicable financial/tax authority | Assessment, verified payment, coverage, correction, non-tax outputs | Learner needs current term-account position | TALA could falsely become accounting or tax authority | Bounded Term Account companion | Excluded | Applicable authority and separately approved product expansion |
| COE, COG, Good Moral, certified copies, Form 137/138 issuance, Honorable Dismissal, and other requested-record fulfillment | Present manually | Registrar and responsible institutional office | Institution-approved document procedure | Identity, enrollment, academic, and lifecycle source records | Staff must be able to locate trustworthy source facts | A hidden document catalog would be speculative | Retain authoritative source records only | External | Exact approved template, owner, fee/clearance, fulfillment, and acceptance process |
| Physical Student ID/card production and replacement | May occur | Registrar/Student Affairs and external production process | Institutional identity-card procedure | Official Student identity and number | Source identity must be trustworthy | Card production would add unrelated logistics | Official identity and Student number | External | Approved digital/physical ID journey, security design, and owner |
| Transcript request, signatures, seals, CAV, claiming, delivery, diploma, and ceremony | Yes | Registrar and external certification/fulfillment processes | Institutional and regulator documentary authority | Request reference, clearance, source snapshot, certification, issuance history | TALA must protect the academic source and issuance state | Recreating fulfillment risks false official authority | Existing bounded TOR contract | External | Institution-approved digital workflow and exact documentary authority |
| HEMIS and other regulator templates, portals, reconciliation, and certification | Yes | Authorized Servitech regulatory officers and regulator portals | Exact regulator authority and prescribed format | Program, enrollment, academic, and completion source records | Authorized officers need trustworthy sources | Speculative exports create incorrect submissions or excess data | Approved source records only | External | Exact authority, format, privacy basis, owner, process, and acceptance evidence |
| Accreditation and institutional quality-assurance operations/reports | Present institutionally | Academic Head/quality-assurance office and accreditor | Exact accreditation/QA framework | Trustworthy in-scope source records only | No accepted operational/reporting UI need | Generic reports or attestations could misstate compliance | Source records and audited access only | External | Named framework, required dataset, owner, workflow, and acceptance evidence |
| Provider consoles, server commands, restore controls, test transactions, and manual attestations | Yes operationally | Authorized external operations | Provider and institutional operations procedures | System Health and operational events | Staff need locally evidenced status only | Unsafe controls or false health claims | Local evidence, `Unknown`, and `Not checked by TALA` | Excluded | Separately approved operations-control scope and security design |
| Offline operation | Not established | Institutional continuity procedures | Approved continuity plan | Durable server records and backup evidence | Safe degraded guidance is sufficient | Conflict-prone replicas and synchronization ambiguity | Central service plus backups and degraded-state guidance | Excluded | Proven disconnected-use requirement and approved synchronization design |
| Generic Reports, Settings, Approvals, notification center, Readiness Center, policy/workflow builder, and generic event calendar | No independent owner | Each source-owning domain | Canonical PRDs | Contextual queues, readiness, history, messages, dates, and actions | Users need source-owned work, not generic hubs | Generic surfaces duplicate ownership and invite invented rules | Existing contextual projections | Excluded | Repeated measured cross-domain need that cannot remain contextual |

No demographic field, report, export, state, event, or workflow may be added merely because another institution or a possible future regulator template uses it.

### 3.6 Shared failure and authorization behavior

| Condition | Required product behavior |
|---|---|
| Missing authoritative source | Consuming action is unavailable; the UI names the source owner and recovery and never invents a fallback |
| Stale or concurrently changed version | Server rejects the mutation, preserves safe entered data, shows what changed, and requires review before retry |
| Inaccessible record or workspace | Reveal neither protected record existence nor details; provide only the authorized workspace/recovery action |
| Integration unavailable | Degrade only the dependent optional action; never reverse a committed institutional record or change a published timetable |
| Consequential action fails | State whether anything was committed, preserve authoritative prior state, and prevent duplicate retry effects through locking/idempotency |
| Output generation fails | Create no partial, downloadable, or official-looking artifact; the authenticated source remains authoritative |
| Email delivery fails | Keep the owning transaction committed, record delivery evidence, and allow only authorized idempotent resend |

Navigation visibility is a usability decision, never authorization. Every page, query, action, download, projection, and output rechecks role and record authority server-side.

### 3.7 Source-owned Action Restrictions

An action restriction needs a named consuming action, its condition in the owning PRD and an attributable source. Model names, schema flags or a generic list of hold types do not create product requirements. Use existing admission clearance, academic/lifecycle decisions, placement readiness and request-specific financial projections for their declared effects; no separate general hold editor, office-clearance workflow or waiver engine is required.

An externally authorized consequential restriction records its responsible office, authority/source, effective scope, actor/time, safe learner explanation, resolution requirement and attributable correction/resolution history. Internal evidence stays restricted. Academic Head may own an external academic decision; Registrar records its effect in TALA. This does not give the read-only Academic Head workspace a mutation permission. Accounting owns its exact financial projections; they are separate from an academic or administrative decision.

Blanket finance blocks on sign-in, classes, examinations or grade viewing are prohibited. Account security disablement remains PRD 01's distinct authorized action. Missing setup follows Section 3.2; it does not become a new institutional restriction. Existing hold records and working guards remain implementation evidence to reconcile in the owning slice; this definition change neither deletes history nor authorizes silently bypassing a current restriction.

## 4. Identity, Access, and Public Entry

[PRD 01](01_identity_access_public_entry.md) owns account creation/verification, unified sign-in, authorized context resolution, fixed roles, Staff MFA, sessions, recovery and bounded public content. The minimum outcome is one secure credential account with an understandable authorized next action; official enrollment later adds persistent Student access. Public discovery includes the landing page. No second account, general CMS or role builder is required.

## 5. Application, Admission Decision, and Enrollment Readiness

[PRD 02](02_application_admission_decision_enrollment_readiness.md) owns Cycle/requirement setup, minimum intake, selected private preliminary copies, scoped corrections, identity resolution, admission decision and one current Registrar enrollment clearance. The selected copies, admission and permission to begin enrollment remain distinct. Physical-document processing stays external; attributable old evidence remains protected.

PRD 04 consumes the current source-bound ReadyApplicantProjection on the same account/application. Admission creates no Student number, class placement, assessment or official enrollment.

## 6. Academic Setup, Offerings, and Published Timetable

[PRD 03](03_academic_setup_offerings_published_timetable.md) owns approved Program/Course/Curriculum sources, exact-Term calendars, actual cohorts/classes/resources, Faculty availability, CP-SAT rules, candidate review and immutable human publication/revision. These inputs are necessary to the principal scheduling innovation. TALA records external approvals and resource facts without recreating regulator, HR or committee workflows.

### 6.1 Accepted Academic Calendar Contract

PRD 03 Section 6 is the single owner of Term type/state, dates, windows, teaching grid, breaks, dated exceptions, Examination Period, activation conditions and correction behavior. This baseline provides shared readiness presentation; it does not maintain a second calendar field or policy list. Concurrent Terms remain exact-source contexts, and a Term's activation does not itself open enrollment or publish a timetable.

## 7. Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop

[PRD 04](04_current_term_registration_official_enrollment.md) owns proposals, confirmation, placement/capacity, registration outcomes, finalization, identity continuity, adjustments, Course Drop and immutable COR versions. It consumes current admissions, published-class, released academic and Accounting sources rather than copying their records or rules.

The minimum outcome is an attributable official enrollment and COR on the same credential account. Eligibility, readiness, reservation and payment are earlier facts, not enrollment. The owning transaction rechecks current checkpoints; a later source change uses a guarded correction rather than silently reversing the official record.

## 8. Teaching, Grades, Academic Records, and Completion

[PRD 05](05_teaching_grades_academic_records_completion.md) owns official membership-based final-result submission, Registrar release/correction, fixed averages, factual curriculum evaluation, source-backed lifecycle/completion and transcript issuance. Faculty gradebooks, attendance, office appeals and physical fulfillment remain external.

Released history supports eligible current and former Students' records. Completion/conferral is included when recorded and is not a universal TOR prerequisite. Exact grade vocabulary, average formula/classifications, nonautomatic INC deadline and request-specific output conditions belong to PRD 05; this baseline does not duplicate them.

## 9. Accounts, Official Outputs, Operations, and Assurance

[PRD 06](06_accounts_official_outputs_operations_assurance.md) owns Accounting's fixed Fee Plans or eligible exact authorized individual assessments, continuous Term Accounts, approved coverage, verified payment effects, request-specific financial projections, non-tax outputs, contextual exports and truthful local health/audit views.

The minimum outcome is a source-backed account position and clearance for the action it satisfies. No fee amount is inferred, no pending browser return proves payment, and no payment or coverage event silently revokes enrollment. Refund execution, tax invoicing, cash movement, provider operations, retention decisions and disaster restoration remain external. Prospective infrastructure requirements belong to Architecture and remain distinct from capstone implementation acceptance.

## 10. Canonical PRD and UI Contract

The baseline owns product-wide vocabulary, common mutation and validation rules, cross-module ownership, policy classes, official-output rules, exclusions, and handoffs. Each PRD owns the complete current-state behavior of one journey and must be understandable without a legacy PRD, implementation file, test, benchmark, or task plan. The UI Surface Blueprint owns shared presentation and screen coverage; the Architecture Specification owns technical and integration boundaries.

A PRD may cite these shared authorities without copying them, but it may not outsource a module-specific product decision. Legacy documents, code, schema, tests, demonstrations, and benchmarks remain supporting evidence only. They cannot add product behavior or prove implementation conformance.

Each standalone PRD must settle:

- Applicable law, regulator evidence, institutional authority, accepted TALA defaults, and intentional external responsibilities
- User goal, owner, starting state, and successful ending
- Required setup and source records
- Normal chronological flow
- Alternate, invalid, late, unavailable, correction, and failure paths
- State/action table, actor, authorization, guards, and irreversible effects
- Cross-role projections from the same authoritative record
- Readiness matrix
- Email-trigger rows
- Official outputs and audit evidence
- Exact authoritative data and conceptual contract
- Required user tasks, destinations and information priorities
- Necessary inputs and review facts, contextual search/sorting, actions and evidence
- Empty, loading, error, and inaccessible states
- Desktop, mobile, print, accessibility, and keyboard behavior
- Explicit exclusions and external/manual decisions
- Realistic demonstration data and browser acceptance script

Every primary user-visible capability has an explicit information/action/state/access/recovery contract. The Canonical UI Surface Coverage Inventory makes every required capability reachable and gives dedicated acceptance coverage to seven cross-role journeys. Related capabilities may share a native surface suited to their tasks. Impeccable leads composition; sketches provide supporting evidence for design decisions:

1. Public entry, identity, verification, unified sign-in, authorized post-authentication context selection, and access failure
2. Application, selected preliminary review, decision, Registrar clearance, and enrollment readiness
3. Academic authority, timetable readiness/failure, publication, and revision
4. Registration, assessment/coverage, official enrollment, Student activation, and COR
5. Grade submission/release, INC/correction, completion, and TOR
6. Fee Plan/assessment, payment evidence or PayMongo, account outputs, and reversal
7. System Health, Governance & Audit, output access, and the explicit no-automatic-disposal boundary

Impeccable establishes the task-specific composition and tests useful alternatives when a design decision warrants comparison. Research relevant capabilities, supported customization and demos through Laravel Boost and official installed-version documentation. Choose the smallest suitable implementation across native configuration, installed compatible components and focused TALA-owned extensions. Keep required decision facts visible and justify material choices by workflow, usability, accessibility, security and maintenance. A new dependency requires separate approval. A month calendar may supplement dated exceptions as a read-only view; it cannot replace the Term Setup workbench.

Every entry in the Canonical UI Surface Coverage Inventory carries one implementation disposition:

| Disposition | Meaning |
|---|---|
| `NativeFilament` | Filament resources, Pages, Tables, Forms, Infolists, Tabs, Sections, Wizards, Actions, filters, notifications, or their ordinary composition satisfy the approved behavior |
| `InstalledCompatibleDependency` | An already-installed, version-compatible dependency has one bounded approved responsibility that native Filament cannot supply alone |
| `FocusedTALACustom` | A small TALA-owned Blade, Livewire, print, visualization, preview, or failure component is necessary for the exact approved behavior and reuses native primitives where practical |
| `PurposefullyExcluded` | The interaction is unnecessary, unsafe, externally owned, or deliberately outside the MVP; no placeholder page, plugin, or generic engine is created |

The disposition does not mandate one route or component per inventory row. A custom Filament Page composed from ordinary native primitives remains `NativeFilament`; `FocusedTALACustom` is reserved for behavior or rendering that those primitives cannot express by themselves.

No public HTTP API is added. The shared vocabulary below names logical responsibilities, not approved tables, classes, routes, or a mandate to preserve a legacy abstraction. Each owning PRD classifies every named concept as an authoritative record, immutable version/event, derived projection/calculation, UI-only state, external result, official output, or documentation-only concept.

| Owner | Canonical conceptual vocabulary |
|---|---|
| Clinic 1 | Credential account, Staff access profile, role/security/public-content facts, derived workspace context and access state |
| Clinic 2 | Admission Cycle, Application and immutable snapshots, evidence/correction/decision history, Registrar enrollment-clearance history, one `ReadyApplicantProjection` |
| Clinic 3 | Program/Course/Curriculum authority, Term Calendar Package, cohorts and Class Offerings, resource declarations, generation run/candidate history, published timetable versions, derived readiness/availability/demand/Examination Period projections |
| Clinic 4 | Registration Case, proposal/confirmation/reservation history, Official Enrollment and registrations, Student identity events, adjustments/Drops, COR versions, and source-owned readiness projections |
| Clinic 5 | Roster/result history, INC deadline amendments, external competency and lifecycle results, derived averages/evaluation/enrollment/completion projections, Conferral records, and versioned transcript output records |
| Clinic 6 | Fee Plan and Assessment versions, continuous Term Account events, Approved Coverage, payment evidence/attempt/posting/reversal history, clearance decisions, derived account/readiness/health projections, and account/finance outputs |
| Shared presentation/evidence | `ReadinessResult`; `TransactionalMessageEvent` only as the code-defined audit/idempotency envelope for an owning clinic email, never a notification center or template editor |

`Person` is only a cross-document label for the same human subject and stable identity continuity. It does not authorize a universal Person master, table, profile, sign-in identifier, or extra UI. Clinic 1 owns credentials; Clinic 2 owns Applicant facts; Clinic 4 owns the minimal official Student profile and its authorized correction history.

### 10.1 Cross-clinic record handoffs

| Producer → consumer | Shared reference or projection | Required continuity and unavailable behavior | Forbidden consumer behavior |
|---|---|---|---|
| Clinic 1 → all clinics | Credential account and workspace authorization | Same account reference; inaccessible context reveals no protected record | Recreate credentials, infer roles, or authorize from navigation visibility |
| Clinic 2 → Clinic 4 | `ReadyApplicantProjection` | Same application/version; reversal or stale source removes readiness | Copy the application, create a Student early, or override readiness |
| Clinic 3 → Clinic 4 | `PublishedClassAvailabilityProjection` | Published timetable/class versions; missing/stale source blocks placement/finalization | Edit classes, capacity, meetings, or timetable authority |
| Clinic 4 → Clinic 3 | `UnmetClassDemandProjection` | Aggregate demand keyed to term/course/program context | Move learners or create confirmed Class Offerings automatically |
| Clinic 3 → Clinic 5 | Class, Faculty assignment, calendar, units, and classification facts | Exact Class Offering/course/calendar versions; stale membership blocks roster action | Edit academic setup or timetable facts |
| Clinic 4 → Clinic 5 | Official registrations, roster membership, adjustments, drops | Official registration/change versions; material change invalidates pending roster review | Maintain a duplicate roster-membership source |
| Clinic 5 → Clinic 4 | `OfficialCourseResultProjection`, `AcademicEnrollmentEffect`, lifecycle facts | Released/confirmed versions only; every initial release, INC resolution, or correction recomputes affected cases; a pending decision blocks only the affected action | Use draft grades, treat an exception as course satisfaction, or silently change a Registration Case |
| Clinic 6 → Clinic 4 | `EnrollmentPaymentRequirementProjection` | Term Account/Assessment version, assessment basis, exact proposal/change source, separate payment/coverage amounts and references, satisfaction basis, authority, and as-of time; `Unavailable` blocks finalization and cost-increasing change but never an authorized removal/drop | Recalculate finance, determine funding eligibility, invent a fee/refund/penalty, require lifetime zero balance, or apply a global hold |
| Clinic 6 → Clinic 5 | `OfficialOutputPaymentClearance` | Exact official-output request reference; `ActionNeeded` pauses only that output | Operate a global credential hold or edit payment evidence |
| Clinics 4/5 → Clinic 6 | Registration/account and output-request references | Same learner, term, RegistrationCase or request identity | Copy academic records or turn Clinic 6 into enrollment/TOR workflow |

### 10.2 Official-output ownership

| Output | Owner and authoritative source | Status and authorized audience | Supersession and failure behavior |
|---|---|---|---|
| Application Acknowledgment | Clinic 2 submitted snapshot | Authenticated Applicant/Registrar; not admission or enrollment proof | Historical versions remain labelled; failure produces no document |
| Published Timetable / schedule print | Clinic 3 published version | Official only after Registrar publication; role/owner scoped | New publication supersedes; unpublished candidate never appears official |
| Registration Form / COR | Clinic 4 official enrollment and COR version | Official enrollment output for learner/authorized Staff; assessment-at-finalization snapshot, not live ledger | Change creates a new immutable version; failure produces no partial COR |
| Unofficial Student Record | Clinic 5 released academic record | Clearly **Unofficial — for student reference** | Current projection only; print failure cannot imply official issuance |
| TOR | Clinic 5 transcript snapshot and `TALA Standard TOR — Servitech v1` | Registrar-controlled official output from releasable history for authorized current/former Students, without a blanket graduation gate; completion/conferral appears only if recorded. Physical signing, sealing, delivery and CAV remain external | Void/replacement/supersession is append-only; failure produces no issuance event or official-looking artifact |
| Account Statement / SOA and Payment Acknowledgment | Clinic 6 Term Account and verified posting | Authenticated non-tax outputs | Reversal remains visible and marks acknowledgment reversed/superseded |
| Account Status CSV / Verified Payments CSV | Clinic 6 owning queues | Contextual, allowlisted, purpose-recorded, role-authorized | Failure records no completed export and exposes no partial file |

Physical tables, classes, routes, tests, and current integrations are implementation evidence. They may be designed or changed only inside a separately planned and authorized journey-complete vertical slice that reconciles every relevant consumer against the standalone authority. Shared identifiers and cross-module records must remain consistent with this approved set.
## 11. Shared Standalone-Authority Contract

### 11.1 Cross-PRD terminology dictionary

| Term | Product-wide meaning | Owning authority |
|---|---|---|
| Account | One credential and access-security record identified by verified email; never the Applicant, Student, or Staff domain record itself | PRD 01 |
| Applicant | A person with Applicant workspace access and, when started, one Admission Application per Admission Cycle | PRDs 01–02 |
| Student | The minimal official learner identity created only by Clinic 4 first-enrollment finalization and linked to the existing Account/person continuity | PRD 04 |
| Staff context | One active authorized Registrar, Accounting, Faculty, Academic Head, or System Administrator workspace context; roles never merge into a combined permission set | PRD 01 |
| Application | The versioned admissions source from Draft through decision, credentials, and the derived ready-applicant projection | PRD 02 |
| Curriculum Version | An immutable activated program curriculum defining course placement, units, requisites, classifications, and any authority-backed external-competency requirement | PRD 03 |
| Term | An institutionally authorized First, Second, or Special Term governed by one active package version for that exact Term; multiple exact Terms may operate concurrently | PRD 03 |
| Class Offering | One term-specific class for a Course, cohort demand, Faculty/resource preparation, capacity, meeting requirements, and timetable publication | PRD 03 |
| Registration Case | One learner-and-Term container for proposal versions, confirmation, placement, payment readiness, finalization, changes, and cancellation | PRD 04 |
| Official Enrollment | The atomic Registrar result created only after all five checkpoints are current and valid | PRD 04 |
| COR | An immutable Certificate of Registration version sourced from Official Enrollment or an authorized successor change; it is not a live finance ledger | PRD 04 |
| Official Grade Event | An append-only submitted, released, completed, corrected, or superseding final-result event for one official roster row | PRD 05 |
| Term Account | One continuous person/Registration Case/Term account that exists before or after Student activation without being copied | PRD 06 |
| Assessment | One immutable version sourced from a Published Fee Plan or Authorized Individual Assessment | PRD 06 |
| Approved Coverage | An append-only externally authorized funding effect on named Term Account obligations; not scholarship processing or payment | PRD 06 |
| Official-output version | One immutable, source-labelled generation or issuance snapshot whose official, unofficial, non-tax, superseded, voided, or reversed status is explicit | Owning output PRD |

The concept tables in PRDs 01–06 form the complete logical-object inventory for the approved product. Every named item is classified as exactly one of: persisted authoritative record; immutable version or event; derived projection or calculation; UI-only state or presentation label; external reference or result; official output; or documentation concept requiring no separate implementation object. A conceptual distinction authorizes a separate physical table, model, service, API, route, resource, or page only when later slice design proves it is necessary for ownership, historical reproducibility, authorization, concurrency, idempotency, correction/supersession, or official-output integrity. Otherwise it remains an owned field group, controlled value, calculation, or presentation concern.

### 11.2 Product-wide ownership and policy register

Section 10.1 is the controlling producer/consumer matrix. Consumers may display, filter, link to, or act on an owned projection only as their PRD permits; they never edit producer-owned facts. Every immutable handoff carries the producer reference/version and an as-of time. Missing, inaccessible, stale, or conflicting producer authority blocks only the consuming action and creates no fallback record.

| Policy class | Meaning and use |
|---|---|
| Philippine legal or regulatory rule | Applies within the cited law or regulator guidance; TALA does not broaden it |
| Supplied Servitech evidence | Establishes observed vocabulary, document shape, population, or confirmed client decision within its evidenced scope; unavailable or confidentiality-restricted evidence is never inferred |
| Qualified Philippine institutional comparison | Demonstrates a scoped local policy or operating pattern for its stated institution, population, and period; never becomes Servitech policy automatically |
| Mature-SIS operational pattern | Tests whether a workflow covers competent states, permissions, deadlines, corrections, and recovery without importing enterprise complexity |
| Accepted TALA product decision | Project authority chosen to keep the SIS coherent and lean where the client delegated the product decision |
| Bounded product default | A safe, explicit default with a named scope and correction path; never a generic policy engine |
| Required institutional operational data | Dates, references, amounts, assigned people, templates, or evidence needed to operate already-resolved logic |
| Legally restricted authority | A decision or act that controlling law reserves to an institution, regulator, provider, or authorized professional; TALA records only the permitted source or result |
| External responsibility | A real institutional or provider process for which TALA retains only the necessary source, result, or projection |

Ordinary operational data is never treated as an unresolved product decision. Necessary coverage is not removed merely because approved Servitech evidence is unavailable. A legally restricted or external responsibility names its owner, TALA-retained effect, usable remainder, blocked action, learner explanation, safe failure behavior, recovery, and reopening evidence. No product-policy choice is deferred to implementation.

### 11.3 Coordinated synthetic Servitech institution

All PRD acceptance data uses one coordinated, wholly synthetic institution. Personal identities use `example.test`; no real learner, credential, payment, wallet, or provider identifier is copied.

| Dimension | Coordinated baseline |
|---|---|
| Programs | BM, IT, and THM |
| Current Students | 47 total: BM 10 first-year and 2 second-year; IT 10 first-year and 3 second-year; THM 15 first-year and 7 second-year |
| Active cohorts | Six: one current first-year and one current second-year cohort per Program |
| Faculty | Nine synthetic Faculty identities with explicit eligibility, availability, and capacity evidence |
| Classrooms | Ten synthetic rooms with explicit capacity, type, features, and availability |
| Curricula | Evidence-shaped BM, IT, and THM Curriculum Versions; inconsistent source rows remain import findings |
| Modality evidence | The supplied 34 face-to-face and 13 online learner distribution is contextual population evidence only and never assigns Class Offering modality |
| Applicant demand | Bounded journey cases only; no annual-volume forecast |
| Special and edge cases | The same Students, Terms, classes, Registration Cases, accounts, and outputs carry Special Term, Additional Class Offering, retake, INC, external competency, lifecycle, individual assessment, coverage, payment, reversal, and TOR scenarios |
| Headroom | Any larger population is labelled a synthetic structural or capacity test, not a Servitech forecast |

Third-year curriculum authority may exist, but current third-year enrollment is not fabricated. Every PRD names the subset it owns, consumes, projects, and exercises in its browser acceptance. Shared references must resolve to the same program, term, person, course, class, amount, state, version, and as-of time wherever they appear.

#### Implementation input and acceptance classification

Confidentiality restrictions and the absence of direct client contact do not reopen an approved product decision or create a hidden implementation gate. Each slice classifies a required input as one of: verified public fact; accepted researched TALA rule or bounded product default; coordinated synthetic acceptance data; proven implementation dependency; or institution-entered runtime record. Public facts are checked against attributable sources. Institution-exclusive facts are never invented or presented as approved. When real runtime values are unavailable, the coordinated synthetic institution must still exercise the complete product workflow; the installation accepts the real value later through the already-defined operational record or configuration boundary.

| Slice | Proven high-level prerequisites | Inputs used for implementation acceptance rather than treated as client blockers |
|---|---|---|
| 1 — Public entry and verified Applicant | Open Clinic 2 Admission Cycle projection for registration; existing credential, authorization, and mail-dispatch boundaries | Approved TALA public guidance, project-approved public support contacts, synthetic Applicant accounts, and fake/local mail delivery evidence |
| 2 — Application to enrollment readiness | Verified Applicant context from Slice 1 | Synthetic Cycle dates, requirement versions, Registrar authority, Applicants, selected private preliminary evidence, Registrar clearance, and support/privacy references |
| 3 — Academic authority to published timetable | Authorized Registrar Staff access; PRD 03 academic authority, Term, offering, resource, and solver/publication boundaries. Delivery after Slice 2 is a solo-capacity order, not a blanket dependency | Synthetic curricula, Term calendars, Faculty, rooms, cohorts, Class Offerings, commitments, solver outcomes, sign-off authority, and publication versions |
| 4 — Registration to Official Enrollment and COR | Ready Applicant projection from Slice 2; applicable active academic authority and published timetable from Slice 3; current Clinic 6 assessment/payment-readiness projections for the selected case | Synthetic Registration Cases, proposals, placement/capacity facts, assessment or coverage/payment results, Registrar authority, and COR sources |
| 5 — Official roster to released Student Academics | Official Enrollment and roster projections from Slice 4; applicable academic authority from Slice 3 | Fixed PRD 05 result/INC rules plus synthetic Faculty, rosters, grade events, corrections, lifecycle authority, curriculum evaluation, and completion cases |
| 6 — Completion, TOR, accounts, outputs, and assurance | Only the Slice 4–5 records consumed by the relevant completion/TOR subjourney; other subjourneys use dependencies proven when derived | Synthetic Fee Plans, assessments, coverage, payments, clearances, signatory/output inputs, degraded-service evidence, and backup/restore result records. Provider credentials, procurement, and real recovery operations remain production gates, not capstone implementation-acceptance gates |

Missing or stale runtime authority may block only the action that consumes it. It does not justify incomplete code, a placeholder journey, fabricated success, or deferral from the current accepted scope. A real production installation remains responsible for entering and approving its own dates, people, amounts, authorities, provider accounts, custody, and operational evidence before enabling the affected live action.

### 11.4 Shared Authority-Control Annex

This annex supplies the normalized controls used by PRDs 01–06. An owning PRD may narrow a rule but may not silently weaken it. The records named here are conceptual product authority, not database, API, class, or migration design.

#### Matrix 1 — Capability and authoritative ownership

| Capability | Authority owner | Authoritative source | Consumers may | Consumers may not |
|---|---|---|---|---|
| Identity and access | PRD 01; System Administrator for bounded Staff access | Credential account, verified contact, fixed role assignment, access-change evidence | Read authorized identity and context projections | Edit another clinic's domain record or merge people silently |
| Admissions | PRD 02; Registrar | Admission Cycle, Application/versioned evidence, decision, current Registrar enrollment clearance | Consume `ReadyApplicantProjection` | Create Student identity, enrollment, placement, or assessment |
| Academic authority and timetable | PRD 03; Registrar, with external institutional authority where required | Program, Course Revision, Curriculum Version, Calendar Package, Class Offering, Published Timetable Version | Consume immutable effective versions | Edit source authority or treat a candidate as published |
| Registration and official enrollment | PRD 04; Registrar | Registration Case, proposal version, placement/reservation, official enrollment, COR version | Consume source-owned readiness projections | Recreate admissions, curriculum, grades, or finance authority |
| Academic record and completion | PRD 05; Faculty submits and Registrar releases/records | Official roster results, corrections, curriculum evaluation, lifecycle, completion, conferral, TOR snapshot | Consume released projections | Use draft grades or overwrite released history |
| Accounts and assurance | PRD 06; Accounting and System Administrator within their bounded roles | Fee Plan/Assessment, Term Account events, payment/coverage evidence, output clearance, local health/audit evidence | Consume action-specific clearance and safe status | Create global holds, cashiering, tax documents, or provider controls |

#### Matrix 2 — Common record lifecycle and state transition rules

| Record condition | Permitted mutation | Prohibited behavior | Correction or recovery |
|---|---|---|---|
| Never-used mutable Draft | Edit; hard-delete only if never submitted, published, released, posted, issued, referenced, or depended upon | Deleting a referenced or historically relevant draft | Resolve dependency first or retain and mark the owning terminal state |
| Submitted or pending request | Owner-scoped correction, withdrawal, cancellation, return, rejection, expiry, or successor as the PRD allows | Hard deletion, silent state reset, or a second active request for the same logical scope | Close or supersede the existing request, preserving history |
| Published, activated, released, posted, finalized, or issued record | Read; append cancellation, deactivation, reversal, void, retirement, correction, or successor authorized by the owning PRD | Edit-in-place, hard deletion, generic archive/restore, or history erasure | Create an attributable successor and preserve the prior version |
| Historically used setup record | Effective-dated retirement/reactivation or successor | Removing it from historical projections | Keep prior effective facts and use a later effective version |
| Authoritative or submitted personal-data record | Retain securely with least-privilege access; no ordinary UI deletion | Automatic disposal, disposal-candidate generation, or history erasure inside the MVP | Institution handles lawful retention schedules, privacy requests, legal holds, and secure disposal outside TALA |

Exactly one active mutable draft or pending request exists per logical scope unless an owning PRD explicitly authorizes multiple simultaneous records. Accounts use disable/reactivate; public content uses publish/unpublish; Programs, Courses, Curricula, rooms, and resources use effective-dated retirement/reactivation; Cycles and Terms use close/cancel; institutional transactions and official outputs remain append-only.

#### Matrix 3 — Role permissions and field visibility

| Role | Material authority | Restricted visibility |
|---|---|---|
| Applicant/Student/alumnus | Create or submit only their authorized self-service records; confirm/cancel only within the owning window; read their safe projections and outputs | No other person's records, private Staff notes, provider payloads, internal security facts, or source evidence beyond safe labels |
| Faculty | Maintain own availability; submit assigned complete rosters; view own official schedules and assigned learners | No admissions, finance, account security, other Faculty records, or Registrar release/correction authority |
| Registrar | Own admissions decisions, academic setup, timetable publication, enrollment finalization, academic release, lifecycle/completion, and bounded external-result recording | No password/MFA secrets, private payment instruments, payment verification, or provider control |
| Accounting | Own Fee Plans, exact assessments, coverage, payment verification/correction, bounded output clearance, and contextual exports | No academic decision, grade, admissions decision, role administration, or private evidence outside Accounting purpose |
| Academic Head | Read-only oversight and attributable source drill-in | No producer-owned mutation, publication, grade release, enrollment finalization, or finance action |
| System Administrator | Own credential/Staff-access controls, bounded public content, local System Health, and read-only Governance & Audit | No academic, admissions, enrollment, or accounting decision by virtue of administrator role |

Field visibility follows least privilege and purpose limitation. Authorization is revalidated server-side for every material action; hiding a navigation item is never authorization.

#### Matrix 4 — Create, edit, archive, delete, and supersede

| Action | Shared rule | Audit requirement |
|---|---|---|
| Create | Require authorized scope, current source, uniqueness, and absence of a conflicting active record | Actor, role, source, scope, time |
| Edit | Draft-only unless the PRD names a mutable pending state; revalidate version and dependencies | Before/after fields for consequential changes |
| Archive/restore | No generic product action | Not applicable |
| Hard delete | Only a never-authoritatively-used, unreferenced draft with no dependent record | Actor, scope, deletion basis when material |
| Cancel/withdraw/disable/unpublish/retire | Use the record-specific terminal or reversible action; do not erase history | Reason, actor, role, old/new state, effective time |
| Correct/supersede/reverse/void | Append a successor or correcting event linked to its predecessor | Authority, reason, source version, before/after state, downstream projections |

#### Matrix 5 — Readiness and cross-clinic handoffs

| Handoff | Producer | Consumer | Invalid, stale, or unavailable behavior |
|---|---|---|---|
| Verified identity/access context | PRD 01 | All workspaces | Deny without disclosure; preserve public/recovery route |
| `ReadyApplicantProjection` | PRD 02 | PRD 04 | Do not copy or create Student identity; show owner and next safe Registrar action |
| Active curriculum, calendar, Class Offering, published timetable | PRD 03 | PRDs 04–05 | Block only the consuming action; never infer or edit producer facts |
| Official enrollment/roster/COR projections | PRD 04 | PRDs 01, 03, 05, 06 | Atomic retry; no duplicate Student, placement, roster row, Term Account, email, or output |
| Released result, curriculum, lifecycle, completion projection | PRD 05 | PRDs 03–04 | Draft/submitted results have no effect; every initial release, INC resolution, or correction flags affected active cases for deterministic review |
| Enrollment/output payment clearance | PRD 06 | PRDs 04–05 | `Unavailable` or `ActionNeeded`; never zero fallback, global hold, or consumer-side override |

Every readiness projection names its source, owner, effective version or as-of time, valid condition, consuming action, failure consequence, and recovery. An unavailable state is complete only when it also states what remains usable and the exact reopening condition.

#### Matrix 6 — Input validation, duplicate, and concurrency handling

| Primitive | Shared validation |
|---|---|
| Email | Trim; compare lowercase; valid address; maximum 254 characters; case-insensitive uniqueness per credential account |
| Name part | 1–100 Unicode letters/marks plus spaces, apostrophes, periods, and hyphens; middle name optional; suffix separate and maximum 20 characters |
| Internal code | Trimmed 1–64 characters; letters, numbers, spaces, hyphen, underscore, slash, period, and colon only; unique within its explicitly defined owning scope |
| External authority/source reference | Plain text, trimmed 1–255 characters when required by its owning action; accept legitimate Unicode and punctuation. Show what source is expected and an appropriate example. One source may support multiple records, so no generic uniqueness rule applies. A provider transaction reference, LRN or other identifier keeps its own defined format/idempotency boundary. Render safely and escape export text. |
| Title/label | Title maximum 160 characters; short label maximum 120 |
| Administrative reason | Required for rejection, correction, reversal and authority-backed exceptions; 10–1,000 characters. An owning PRD may use an attributable fixed result for routine successful verification/clearance without a separate narrative. Actor, time and current source are still required. |
| Safe learner explanation | 1–500 characters; no internal notes, secrets, private evidence, or unsupported accusation |
| LRN | Explicit Provided / Not issued / Not available route. Provided is exactly 12 digits stored as text, preserving leading zeros. Before `Admitted`, Registrar resolves a verified identity collision or unavailable-LRN warning through the non-disclosing PRD 02 path. Initial submission remains available; `NotAdmitted` uses its own recorded decision basis. |
| Telephone | Requiredness belongs to the owning field contract; normalize to 8–15 international digits while accepting Philippine-friendly input |
| Money | PHP only for MVP, two decimal places, nonnegative; payment and coverage postings must be positive |
| Units | Positive, up to two decimal places; curriculum/authority reconciliation controls validity, not a universal cap |
| Date/time | Asia/Manila; explicit inclusive/exclusive semantics; start cannot follow end; effective dates never silently rewrite prior authority |
| Public URL | HTTPS only, maximum 2,048 characters; link label maximum 80 |
| Private evidence file | Exactly one PDF, JPEG, or PNG per requirement/evidence version; maximum 10 MiB; server-detected MIME and matching file-format header, private storage, generated storage name, checksum, and access audit. Every attach, replace, read, download, or discard revalidates purpose and exact record/version/path ownership. File checks and checksums establish permitted format and byte identity; document authenticity remains an external institutional check. |

Every material mutation revalidates actor, authorization, current state, effective version, dependencies, and source server-side. A stale or conflicting submission creates no partial mutation, identifies what changed, refreshes authoritative facts, and preserves safe uncommitted text where possible. Academic, financial, security, publication, capacity, finalization, and issuance edits are never silently merged.

#### Matrix 7 — Critical-action confirmation and audit

| Action class | Confirmation | Success evidence |
|---|---|---|
| Routine save, filter, search, preview, or calculation | None | Normal request evidence only |
| Security/access, identity, academic release/correction, publication, enrollment, financial posting/correction, lifecycle/conferral, or official output | Labelled modal consequence confirmation | Actor, role, record/version, authority/reason, before/after state, time, idempotency result, affected roles/projections/emails/outputs |

The dialog shows the exact record/version, actor/authority, resulting state, downstream effects, reversibility or successor requirement, and required reason/authority fields. The action label names the consequence, such as **Publish timetable**, **Finalize enrollment**, **Release roster** or **Record reversal**. Use the native labelled modal for structured preview or input; a brief important interruption uses the WAI-ARIA alert-dialog pattern. Both contain focus and restore it on close. Cancellation or failed confirmation causes no institutional mutation.

#### Matrix 8 — Retry, attempt, correction, and deadline behavior

| Situation | Limit | Exhaustion or deadline effect | Recovery |
|---|---|---|---|
| Ordinary draft, correction, resubmission, or authorized reissue | No arbitrary lifetime numeric cap | State/window/authority may close the action | Authorized extension, reopening, late authority, successor, or external decision |
| Active correction request, matching pending checkout, schedule run, or mutable successor draft | One per logical action/scope | New duplicate is blocked; existing record is shown | Resolve/close existing action first |
| Business deadline | Governing window | Closes affected self-service only; never auto-rejects, fails, grades, deletes, or penalizes | Owning PRD's authorized late/reopen/extension path |
| Login or MFA failure | Five failed attempts per normalized account/IP per minute | Wait until the window resets; no permanent automatic lock | Retry after window or use authorized recovery |
| Verification/password-reset resend | One outbound message per 60 seconds; token expires after 60 minutes | Existing valid token remains governed by its expiry | Resend after throttle window |
| Sensitive account action | Password reconfirmation no older than 15 minutes | Action blocked without changing state | Reconfirm password; successful authentication resets current failure sequence |

#### Matrix 9 — Email ownership and idempotency

| Rule | Authority |
|---|---|
| Owning PRD defines the only trigger, recipient, safe contents, immutable source/idempotency key, failure behavior, and explicit non-email events | PRDs 01–06 email matrices |
| Delivery never proves or reverses the institutional transaction | Shared |
| Duplicate jobs or retries produce no duplicate institutional email for the same immutable event | Shared |
| Failure is recorded and visible to the responsible authorized role; retry reuses the same event key | Shared |

#### Matrix 10 — Official outputs, versioning, access, and failure

| Requirement | Shared rule |
|---|---|
| Source | One immutable authoritative version/snapshot, owner, generation reference, and time |
| Access | Authenticated, role/purpose-scoped, non-disclosing failure, and access audit where sensitive |
| Versioning | Superseded, corrected, voided, or reversed outputs remain historical and visibly labelled |
| Failure | Produce no partial or official-looking artifact; preserve the source transaction and provide a safe retry/support path |
| Claims | Output states whether it is official, unofficial, non-tax, superseded, voided, reversed, or externally certified; it never implies unrecorded authority |
| Print frame | Institution identity leads official and institutional outputs; the TALA product mark is restrained, navigation and interactive controls are absent, headings remain semantic, table headers repeat, rows do not clip, and every copy is monochrome-safe |
| Completeness | Application Acknowledgment, Published Timetable, COR, Unofficial Student Record, TALA Standard TOR, Account Statement/SOA, and Payment Acknowledgment each define exact source/version, status, content, orientation, generation evidence, supersession, and failure behavior in the owning PRD |

#### Matrix 11 — UI screens, actions, states, navigation, responsiveness, and accessibility

| Concern | Shared rule |
|---|---|
| Information hierarchy | One H1, source/owner/as-of context, failed readiness before supporting data, and one current primary action |
| Action placement | Primary action is state-valid; secondary actions are grouped; critical actions use the shared confirmation contract |
| Page states | Initial empty, filtered empty, loading, stale/concurrent, failed, unavailable, and inaccessible are direct or explicit shared variants |
| Navigation | Deterministic role entry; persistent canonical destinations; Staff breadcrumbs on hierarchy; learner **Back to [owner]** links; no browser-history-only dependence |
| Responsive | Learner journeys qualify at 360/390 CSS pixels; Staff operational views at 1366; intermediate navigation transformation; 200% reflow |
| Accessibility | Semantic landmarks/headings/forms/tables, visible focus, logical order, keyboard-complete controls, labelled dialogs, associated/announced errors, no color-only meaning, and accessible output/table alternatives |
| Failure wording | State what happened, whether anything changed, responsible owner, preserved input, next safe action, and source/as-of evidence without exposing restricted data |
| Component disposition | Every canonical surface is classified as `NativeFilament`, `InstalledCompatibleDependency`, `FocusedTALACustom`, or `PurposefullyExcluded`; a new dependency requires a proven gap after the first three options are evaluated in order |
| Brand authority | The UI Surface Blueprint owns the semantic roles of the TALA product mark, live wordmark, institution mark, typography, Heroicons Outline interface icons, favicon/app icon, and monochrome print identity; file presence or legacy use does not establish authority |

#### Matrix 12 — Policy dependency and decision classification

| Class | Meaning | TALA behavior |
|---|---|---|
| Product logic resolved | Canonical authority defines behavior | Implement exactly through a separately planned slice |
| Project-authorized bounded default | Client delegated the decision and the ordered evidence hierarchy supports a lean, safe, proportionate, correctable rule | Record the rule, scope, evidence, correction path, and owner; do not expose a generic policy engine |
| Institutionally supplied operational data | Dates, authorities, amounts, people, templates, or evidence are needed to operate resolved logic | Keep setup/action unavailable until exact data is recorded; surrounding journey remains usable |
| Legally or institutionally restricted authority | Project cannot validly invent the decision and no safe default exists | Define the complete policy gate: source, owner, blocked action, usable remainder, explanation, recovery, and reopening condition |
| Intentional external responsibility | Process occurs outside TALA; TALA retains only a necessary source/result/projection | No hidden module or speculative workflow |
| Genuine contradiction | Two controlling rules cannot coexist | Reopen only the affected authority before implementation |

Every included policy-gated capability has a complete workflow rather than an `Unavailable` placeholder. `INC` uses the bounded one-year nonautomatic completion rule and explicit no-credit/retake consequence in PRD 05; TALA owns a fixed Servitech-branded TOR template; unreleased prerequisites use the lean exclusion-and-ordinary-adjustment path across PRDs 04–05; and automatic retention disposal is intentionally outside the MVP. Legally restricted and external actions remain explicitly owned outside TALA without making the product definition incomplete.

## 12. Approved Cross-Module Acceptance Coverage

This matrix is the final traceability contract for later journey-complete vertical delivery. Detailed scenario data, states, and browser steps remain in the owning PRD. Every later implementation uses synthetic identities and `example.test` addresses.

| Journey | Required end-to-end evidence | Cross-module pass condition |
|---|---|---|
| Identity and entry | Public closed/open entry, registration, verification, unified sign-in, authorized post-authentication multi-role choice, MFA/recovery, disablement, inaccessible route | One credential account; no protected disclosure, silent role priority, or duplicate activation message |
| Application to readiness | Draft/submission, scoped correction, decision, Registrar enrollment clearance, duplicate warning, withdrawal, `ReadyApplicantProjection` | Clinic 4 sees the same application/version without copy or early Student creation |
| Concurrent term operation | First-, Second-, and Special-Term packages with overlapping enrollment, adjustment, teaching, grade-entry, account, timetable, COR, and output work | Every record/action carries its exact Term; one Term's window or closure never silently controls another; no Summer subsystem or implicit current term appears |
| Academic authority to publication | Curriculum/calendar/class/resource readiness, feasible/infeasible/unknown/technical solver results, candidate review, publication and revision | Only Registrar publication creates the official version; consumers keep its identifier and version |
| First official enrollment | RegistrationCase, proposal, learner confirmation, placement, Clinic 6 requirement, finalization, Student access, COR | Same human/credential/RegistrationCase/TermAccount continuity; five checkpoints revalidated atomically |
| Continuing and advised enrollment | Standard and Individually Advised proposals, reduced/Special Term cases, fixed or authorized individual assessment, prerequisites, shortages, reservations, timetable revision | Clinic 3 owns one revision event/email; no arbitrary course shopping, invented assessment, or silent learner move |
| Special Term through cumulative projection | Approved `TERM-2026-ST`, Regular and Additional published classes, `REG-2026-ST-001`, exact individual assessment, Applied coverage plus verified payment, official enrollment, partial then complete roster release | Same references cross Clinics 3–6; partial release shows **Grades not complete**, final release yields deterministic term/cumulative values; no Summer/tutorial/irregular/scholarship engine |
| Grade release and correction | Designated Faculty roster, returned rows, complete release, `GradesNotComplete`/INC/not-applicable/available average states, completion deadline/amendment/overdue/result race, correction, RegistrationCase review | Only released results cross clinics; no partial average, automatic grade conversion, overwritten result, or silent registration change |
| Pending prerequisite to next-term outcome | Individually Advised case, exact approved credit/equivalency or overload/late-adjustment authority, learner confirmation, initial release/INC/correction, pre/post-finalization review, open Adjustment, closed Adjustment with/without late authority | No released `P` or fabricated satisfaction; unrelated courses remain usable; capacity/finance/roster/schedule/COR effects occur only through an authorized guarded transaction |
| Lifecycle and withdrawal | Leave, full withdrawal, return, transfer, shift, conferral and current-term effects | Seats, rosters, schedule, COR and account review remain synchronized with append-only history |
| Completion and TOR | Completion readiness, request-specific Clinic 6 clearance, TALA Standard TOR preview/issuance, void/replacement/supersession | Only Registrar confirmation creates issuance; physical certification remains external; consumers cannot edit finance or source academic facts |
| Account, coverage, and payment | Fixed Fee Plan and authorized-individual-assessment readiness, Approved Coverage application/supersession/reversal, mixed satisfaction, unavailable source, adjustment/drop review, manual evidence, exact-due checkout, under/mismatch, duplicate and missing/late webhook, reversal | Browser return never posts; coverage is not payment or eligibility processing; one posting and one email; no silent cap, fee fallback, invented refund/penalty, or global hold |
| Outputs, export, health and retention | COR/timetable/unofficial record/TOR/SOA/acknowledgment, two finance CSVs, one current per-Class-Offering roster CSV, purpose audit, degraded services, explicit no-automatic-disposal boundary | No partial official-looking output; roster export never expands beyond one authorized current class or includes grade/contact/finance data; unknown external fact is not healthy; no hidden reporting, retention, or compliance engine |
| Shared UI and failure | 1366 desktop, 360/390 mobile, keyboard/screen reader, 200% zoom/reflow, print, empty/loading/stale/inaccessible/concurrency/failure | Owning source, as-of time, responsible role and safe recovery remain visible without color-only meaning |

Across every row, consumers must not edit producer-owned facts; missing or stale authority prevents unsafe action; and no workflow creates a duplicate account, handoff record, official output, payment posting, or email.

## 13. Canonical Ownership and Delivery

The seven modules below identify canonical ownership. Live delivery scope, progress and acceptance evidence belong to the coordination Issue and its owning children.

| Clinic | Owning document | Purpose |
|---|---|---|
| **0 — Foundation and Shared Rules** | This baseline | Product goal, evidence hierarchy, lean boundaries, roles, shared vocabulary, coordinated acceptance data, readiness, communication, UI planning and authority controls |
| **1 — Identity, Access, and Public Entry** | PRD 01 | Identity model, authentication entry, role workspaces, public content, access and inaccessible-record behavior |
| **2 — Application, Admission Decision, and Enrollment Readiness** | PRD 02 | Application intake, versioned requirements, scoped correction, authorized decision, Registrar enrollment clearance, derived readiness and the shared Clinic 4 projection |
| **3 — Academic Setup and Published Timetable** | PRD 03 | Calendar and informational Examination Period, curricula and bounded external-competency requirements, courses, offerings, resources, faculty availability, CP-SAT, review, publication and timetable failure behavior |
| **4 — Current-Term Registration and Official Enrollment** | PRD 04 | Eligibility, proposed registrations, placement, minimum Accounting clearance, Registrar finalization, conditional first Student activation, adjustment, Course Drop and COR |
| **5 — Teaching and Official Academic Record** | PRD 05 | Official rosters, final grades, release, correction, deadline-bound nonautomatic INC, term weighted average, cumulative GWA, factual curriculum position, lifecycle, standard TOR and completion |
| **6 — Accounts and Operations** | PRD 06 | Fee Plans, continuous Term Accounts, Approved Coverage, payment evidence, bounded enrollment/output-clearance projections, non-tax account outputs, contextual exports, System Health, privacy, audit, recovery and assurance |

Clinic 0 establishes the universal readiness presentation; each journey PRD owns its sources, validity, owner, consequence, consuming action, and recovery. The calendar ownership, Term Planning Workbench, typed Term Calendar Package, unified Class Offering model, whole-term solver contract, and immutable publication/revision boundary remain fixed Clinic 3 authority. The Clinic 2→4, Clinic 3↔4, Clinic 4↔5, Clinic 6→4, and Clinic 6→5 handoffs remain fixed as summarized in Section 10.1.

The owning contracts govern identity continuity, academic results, official outputs, role entry, interface coverage, validation, concurrency, confirmation, retry and record preservation. Resolve a material authority or feasibility conflict in its owning document before implementing the affected behavior. Production operations and institution-owner decisions follow the owning PRD and Architecture Specification.

Current workflow gates are:

- The Canonical UI Surface Coverage Inventory governs required user-visible behavior and acceptance coverage.
- Reuse the accepted slice plan and matching brief. Each authorized slice cites current owning product/UI/architecture contracts, inspects relevant code/schema/test evidence, and verifies retained behavior before acceptance.
- Apply the permission boundaries and human gates in the Orchestrator Protocol to implementation, destructive work, external effects, completion and publication.

## 14. Assumptions

- TALA is developed for an ordinarily recognized Philippine college.
- Approved Servitech evidence is the first local source but does not cap necessary SIS coverage when evidence is unavailable or confidentiality-restricted.
- Qualified Philippine comparisons and mature-system benchmarks establish scoped concepts and lean implementation patterns, not Servitech-specific policy values.
- The supplied 2019 handbook concerns TESDA operations and is contextual evidence only; it does not establish Servitech college policy.
- The supplied evidence does not establish a Servitech INC deadline or one universal variable-fee formula. TALA therefore uses the bounded one-year nonautomatic INC completion rule, while ordinary fixed Fee Plans and bounded exact individual assessments resolve the fee-source behavior.
- Any Special Term remains unavailable until supported by an approved particular calendar/schedule and attributable class-hour/class-day basis; TALA supplies no Summer defaults.
- Current code and database remain implementation evidence and are retained only when the owning vertical slice proves alignment.
- Each owning PRD and the UI Blueprint must remain approved before that module's code or physical schema is changed.
