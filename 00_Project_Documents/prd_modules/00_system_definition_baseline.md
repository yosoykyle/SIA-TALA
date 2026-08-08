# TALA System Definition Baseline

## Purpose and Current Status

This document defines what TALA is supposed to be before the existing application, database, or UI is judged. It is the pre-PRD product authority for the rebaseline—not an implementation plan, issue tracker, database specification, or replacement for the rewritten module PRDs.

Current status:

- Clinic-level product definition, documentation-only canonical consolidation, and the final cross-module contradiction and omission review are complete. Clinic 0 and Clinics 1–6 are approved, and the complete canonical authority set is approved for implementation-task derivation.
- Complete-authority approval authorizes only the next planning boundary: derive and separately approve journey-complete vertical implementation tasks. It does not authorize application or schema changes by itself.
- The definition-first order remains binding: an approved vertical task must cite this complete authority set, reconcile the bounded existing implementation, and receive separate execution authority before implementation begins.
- The post-Clinic-0 shallow implementation inventory and bounded Clinic 1–6 reconciliations are salvage evidence only. They do not make existing behavior authoritative.
- The legacy 13 PRDs, business evidence, database, and code are inputs to scrutinize rather than authorities to preserve.
- Accepted product decisions are written directly into their governing sections and identified by the status rules below.

Statement-status rules:

| Status | Meaning |
|---|---|
| **Accepted** | Explicitly selected product direction or applicable governing rule. It remains active unless the user reopens it or stronger authority contradicts it. |
| **Accepted input — clinic incomplete** | The individual choice is preserved, but the owning module is not complete and the surrounding workflow must still be scrutinized. |
| **Draft clinic material** | A claim inherited from a legacy PRD, earlier plan, business evidence, benchmark, or unapproved recommendation. It cannot govern implementation. |
| **Open** | A material decision still requiring evidence and user resolution. |
| **Conditional** | Applicable only after the stated institutional authority or verified condition exists. |

Sections 1–3 contain the accepted product goal and shared foundation unless a statement is explicitly marked open or conditional. Sections 4–9 contain concise approved module summaries; complete detail belongs in PRDs 01–06 and their UI authorities. Draft legacy or planning claims are not retained as product narrative. Section 12 is the approved cross-module acceptance contract; it traces the owning PRDs without replacing their detailed rules.

The final approved authority set is:

1. This baseline for the product goal, evidence rules, shared system boundaries, module ownership, and definition process.
2. Six rewritten journey PRDs, stored beside this baseline as canonical files `01`–`06`, for the complete product-authority details of Identity and Access; Application, Admission Decision, and Enrollment Readiness; Academic Setup and Scheduling; Enrollment; Grades and Records; and Accounts and Operations.
3. UI blueprints and prototypes owned by their corresponding rewritten PRD rather than designed during coding.

If a rewritten PRD conflicts with this baseline, the conflict must be reconciled explicitly. Neither document silently overrides the other.

## Clinic 0 — Foundation and Shared Rules

**Status: Approved on 2026-08-04.**

Clinic 0 is the foundation-planning stage that produces this baseline. Its governing content is:

- **Section 1:** Product Goal
- **Section 2:** Evidence hierarchy, authority structure, lean boundaries, and completeness rules
- **Section 3:** Shared roles, cross-role records, configuration, readiness, communication, contextual operational views/exports, and public-content boundaries
- **Section 10:** Requirements that every rewritten PRD and UI definition must satisfy

Clinic 0 does not approve the detailed behavior of Identity, Admissions, Academic Setup, Enrollment, Grades, or Accounts. Sections 4–9 preserve only their previously accepted inputs and open matters so the later clinics do not lose or falsely repeat them.

## 1. Product Goal

The goal is not to finish, polish, or rescue the system that already exists. The goal is to define and deliver the leanest, clearest, factually defensible, user-centered, and defense-ready Philippine college information system.

TALA must:

- **Use the leanest defensible implementation.** Remove, simplify, or externalize functionality when the institutional result can be achieved through a smaller and clearer workflow.
- **Follow authoritative Philippine higher-education rules.** Law, regulator publications, and approved institutional policy govern the product. Existing workflows and business evidence remain evidence requiring scrutiny.
- **Assume a normally recognized and authorized Philippine college.** Recognition-pending workarounds and student-facing recognition concerns do not belong in the product.
- **Remain understandable from every user's perspective.** Each role sees only the information, action, evidence, and next step needed for its responsibility while sharing the same authoritative records.
- **Define the complete product before judging the implementation.** Every module settles its narrative, exact data, states, alternatives, invalid cases, setup, readiness, actions, emails, outputs, UI, and exclusions before code or schema classification.
- **Plan the interface as part of the product.** Important workbenches receive evidence-backed visual alternatives; routine pages still receive exact blueprints.
- **Deliver complete vertical slices.** A finished module visibly works across data, rules, authorization, every participating role, emails, outputs, audit evidence, realistic data, tests, and browser-verified desktop and mobile journeys.
- **Preserve good work without becoming trapped by it.** Existing Laravel, Filament, authentication, scheduling, integrations, and tests survive only when they align with the approved product.
- **Remain demonstrable and technically defensible.** TALA must resist invalid and out-of-order actions, explain failures clearly, and support a convincing end-to-end defense within the capstone refinement period.

In one sentence:

> Define and deliver a lean, coherent, user-centered, policy-aligned, defense-ready Philippine college information system—preserving existing work only where it supports the correct product, and simplifying or rebuilding anything that does not.

TALA is Servitech-first rather than a speculative multi-school platform.

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
| Current PRD, code, or database | Salvage evidence only |
| Unverified assumption | Removed or retained visibly as unresolved |

The business evidence may shape terminology, realistic fields, document layout, and office handoffs, but it must not preserve an incorrect or unnecessarily complicated workflow. Panel and stakeholder observations identify problems that a clinic must investigate; they do not prove that the suggested feature or UI treatment is the correct solution. Mature systems identify competent SIS capabilities and patterns; their institution-specific policies are not copied into TALA.

Primary policy sources include:

- [TESDA UTPRAS requirements](https://tesda.gov.ph/About/TESDA/26) and [TESDA Circular No. 021, s. 2023](https://intranet.tesda.gov.ph/circulariframe?dateIssueFilter=2023)
- [CHED Manual of Regulations for Private Higher Education](https://legacy.ched.gov.ph/manual-regulations-private-higher-education-morphe/)
- [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html), particularly the limits on denying examinations to qualified disadvantaged students
- [Data Privacy Act and NPC guidance](https://privacy.gov.ph/data-privacy-act/) for proportional collection, access, retention, and disclosure

These are starting authorities, not blanket proof for every module rule. Each rewritten PRD must cite the exact applicable source and scope for every automatic policy rule, or leave the rule explicitly open.

An institutional value such as a deadline, grading formula, overload exception, fee amount, or drop effect is never copied from another institution or retained from the old PRD as if it were Servitech policy. TALA may provide the necessary effective-dated input when the value is truly variable, but enforcement begins only after an authorized institutional value exists.

### 2.2 Baseline and rewritten PRDs

The mixed 13 legacy PRDs will be replaced by one baseline and six complete journey PRDs:

1. **00 — TALA System Definition Baseline**
2. **01 — Identity, Access, and Public Entry**
3. **02 — Application, Admission Decision, and Enrollment Readiness**
4. **03 — Academic Setup, Offerings, and Published Timetable**
5. **04 — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop**
6. **05 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion**
7. **06 — Accounts, Official Outputs, Operations, and Assurance**

The baseline establishes the product-wide rules. Each journey PRD owns the exact narrative, records, states, role actions, readiness requirements, emails, outputs, UI, and acceptance contract for its module.

The list above is now the **canonical authority set**. The documentation-only consolidation completed after Clinic 6 and before the final cross-module review:

- `00_system_definition_baseline.md` owns the shared product definition and current authority status.
- `01_identity_access_public_entry.md` through `06_accounts_official_outputs_operations_assurance.md` own the six approved journeys.
- Replaced PRD inputs are preserved intact in [`_legacy/`](./_legacy/) as non-authoritative evidence. Their filenames, numbering, or content cannot override `00`–`06`.
- PRD 03 remains one unified **Academic Setup, Offerings, and Published Timetable** authority; the archived Term Offerings and Resources and CP-SAT Scheduling inputs are not independent journey authorities.
- The final cross-module review has resolved the remaining shared seams. The complete authority set is approved for implementation-task derivation, but consolidation and approval do not authorize implementation.

At the start of each module clinic, create one input-coverage set containing: accepted baseline constraints; unresolved user questions, corrections, and objections; panel and stakeholder feedback; every relevant requirement from all 13 legacy PRDs; applicable business evidence; exact official policy sources; and bounded mature-system benchmarks. After the desired module boundary and journey are established from those sources, append the relevant read-only implementation-inventory findings for reconciliation. An item may guide investigation without becoming product authority.

Before a module's detailed design is approved, every input receives a visible disposition in the owning PRD. The completed final coverage pass confirmed that every legacy requirement and material stakeholder concern has a disposition across identity, admissions, student records, calendar, curriculum, equivalencies, offerings, resources, scheduling, enrollment, finance, COR/SOA, grades, progression, completion, lifecycle, operational views/exports, imports, audit, retention, and integrations.

Each row identifies:

- User problem and owning role
- Current requirement
- Philippine or institutional authority
- Mature-system comparison
- User harm or unnecessary complexity
- Lean replacement
- Verdict: `Core`, `Supporting`, `ExternalDecisionRecorded`, `Deferred`, `Removed`, or `Unresolved`
- Replacement authority and vertical slice
- UI surface or reason no UI is needed

The completed cross-module omission pass checked every role goal, state-changing action, official output, external/manual decision, integration failure, and cross-role effect; Section 12 records the final coverage contract.

### 2.3 Product boundary

- External judgments such as discipline, readmission, overload, program shifting, document authenticity, transfer credit, late adjustment, and graduation clearance normally remain with the responsible office. TALA records the authorized result, authority, evidence reference, effective dates, and direct system effects.
- TALA does not add generic approval engines, universal override records, configurable state machines, policy DSLs, or workflow builders without a later verified need.
- Current demonstrative data may be rebuilt only after a verified backup. Existing code and schema are preserved as later comparison evidence.
- CP-SAT remains the principal intelligent capability. PayMongo is demoted to an optional payment-evidence adapter and cannot block the core school journey.

## 3. Shared Operating Contract and Cross-Role Presentation

### 3.1 One record, role-specific projections

The accepted presentation model is:

`Role work queue → shared authoritative record → one primary action → contextual evidence and history`

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

Readiness is a shared contextual presentation pattern, not a navigation destination or a generic gate engine. Each consuming action shows failed checks first, names the source owner, links to the owning record, and collapses passed checks. No role may manually change a calculated `Blocked` result to `Ready`.

The baseline owns only this universal readiness behavior. Each journey PRD defines the authoritative inputs required by its own actions, who owns them, what validity means, and whether a missing or invalid input blocks, warns, or degrades that action. TALA does not create one abstract global settings model or require a complete cross-system source-record inventory before the module clinics.

The accepted A1 presentation uses:

- Guided dependency order
- A milestone strip
- Inline owner, deadline, blocker, and next action

A1 defines the shared presentation pattern. Its rows and results are derived from the readiness contracts approved inside each journey PRD.

Readiness results use:

- Hard blocker
- Advisory warning
- Degraded integration
- Passed

Failed items show the responsible owner, evidence/source link, and next action. Passed checks remain collapsed. Missing SMTP never reverses an academic or financial transaction. PayMongo failure disables only optional checkout. Solver failure never changes an already published timetable.

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
| Clinic 5 | Grade-roster action/return/release, policy-bound INC action/deadline and resolution/lapse, correction, progress/lifecycle, completion action, conferral | Owning roster/result/policy/decision/conferral reference; a missing INC policy produces no deadline email; grade values and attachments are excluded |
| Clinic 6 | Verified payment posted | Immutable posting reference; exactly one message |

Every message uses its owning record plus recipient identity as the idempotency source. Grade-release email contains no grade values or attachment—only a release notice and secure portal link.

No email is sent for ordinary saves, navigation, successful or failed sign-in attempts, solver failure, internal queue movement, export, payment-proof submission/rejection, checkout return, payment exception creation, TOR clearance, reversal, System Health change, routine calculation/readiness checks, or recurring reminders. Delivery failure is logged and retried without rolling back institutional state. Templates remain code-defined; there is no template editor.

### 3.4 Operational views, contextual exports, and public content

- Operational information remains in its owning queue, record, or read-only projection. There is no top-level Reports destination, report hub, BI product, report designer, or duplicate reporting page.
- The only approved general data-file outputs are Clinic 6's two contextual, allowlisted, purpose-recorded CSV exports. Other modules provide only their approved operational views and printable outputs.
- Admissions analytics means a small operational summary—counts and aging—above the same filtered Admissions queue. It is not scoring, forecasting, or applicant ranking.
- Staff queues use meaningful date ranges and native Filament filters with active-filter indicators. TALA does not reproduce custom dropdowns in every column header.
- Public content includes bounded FAQ add/edit/delete, category, publish/unpublish, and display ordering, plus simple dated notices.
- FAQ ordering controls category order and question order within a category. There is no page builder, media library, reviewer workflow, or general-purpose CMS.

### 3.5 Shared failure and authorization behavior

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

## 4. Identity, Access, and Public Entry

> **Clinic status — Approved.** The complete Clinic 1 contract now lives in [PRD 01 — Identity, Access, and Public Entry](./01_identity_access_public_entry.md) and the Clinic 1 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This baseline retains only the cross-module summary.

### Accepted summary

- **Accepted:** One credential account belongs to one person. Verified email is the only sign-in identifier and the single live TALA communication address. Application references, student numbers, optional staff identifiers, and LRN are domain identifiers and never authenticate an account.
- **Accepted:** Only Applicants self-register, and only while application entry is open. Registration creates an account—not an application—and email verification precedes Applicant workspace access.
- **Accepted:** Staff are invited by System Administrator through a single-use activation link. The administrator never creates or sees a Staff password. Staff-capable accounts require authenticator-app MFA and recovery codes.
- **Accepted:** Official enrollment—not admission acceptance or readiness—adds Student access to the existing account. Student access persists for historical portal use; current-term eligibility controls actions rather than account existence.
- **Accepted:** One account may hold multiple legitimate fixed roles and enters one authorized context at a time. A single-role account routes directly; a multi-role account receives a compact authorized-only chooser and may switch without signing in again.
- **Accepted:** The fixed role name is **System Administrator**. It owns account access, bounded public content, locally evidenced System Health, and appropriate security/audit evidence, but gains no automatic academic, admissions, enrollment, grade, or payment authority.
- **Accepted:** Account access state is derived as `InvitationPending`, `VerificationRequired`, `Active`, or `Disabled`. Disable/reactivate replaces archive/restore and preserves linked records and roles.
- **Accepted:** The credential account holds authentication and access facts only. Purpose-specific identity belongs to Applicant, Student, or minimal Staff access profiles.
- **Accepted:** Public entry is a task gateway with Applicant, Student, and Staff sign-in contexts, bounded notices and FAQ, and official support/privacy/accessibility links. It is not a marketing site or general CMS.
- **Accepted:** Roles and permissions are code-owned. TALA has no role builder, permission editor, generic policy DSL, arbitrary Settings area, or configurable account state machine.
- **Accepted:** Identity readiness is derived from fixed access vocabulary, at least one active System Administrator, secure deployment, queued mail, and official support/privacy configuration. TALA adds no setup wizard.
- **Routed to Clinic 2:** LRN capture, Applicant identity fields, duplicate-candidate matching, application records, and admissions decisions.
- **Routed to Clinic 4:** The idempotent official-enrollment transaction grants Student access. Clinic 4 sends one non-rollback **Official enrollment and COR ready** message; on first enrollment that same message also explains that Student access is active, so no separate activation email is sent.

No Clinic 1 application or schema change begins until Clinics 1–6, the final cross-module contradiction and omission review, and approval of the complete authority set are finished and a bounded vertical slice is separately planned and authorized.

## 5. Application, Admission Decision, and Enrollment Readiness

> **Clinic status — Approved.** The complete Clinic 2 contract now lives in [PRD 02 — Application, Admission Decision, and Enrollment Readiness](./02_application_admission_decision_enrollment_readiness.md) and the Clinic 2 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This baseline retains only the cross-module summary.

### Accepted summary

- **Accepted:** Clinic 2 supports first-year and transferee applications. SHS, ALS A&E, and PEPT or equivalent are credential bases rather than separate applicant types. Returning/readmission, foreign-student processing, cross-enrollment, second-degree, special or non-degree, refresher, exam, interview, appeal, scholarship, medical, appointment, courier, and credit-evaluation workflows remain outside this clinic unless later authority proves a need.
- **Accepted:** One verified Applicant account may start one application per Admission Cycle for one accepting program. Public self-service is normal; Registrar-assisted entry is a bounded exception using the same record and rules.
- **Accepted:** Stored application states are `Draft`, `Submitted`, `ActionNeeded`, `Admitted`, `NotAdmitted`, and `Withdrawn`. `AwaitingOfficialCredentials`, `ReadyForEnrollment`, and `RegistrationStarted` are derived projections; there is no Mark for Evaluation, Approved for Handover, or configurable workflow engine.
- **Accepted:** After first submission, only fields or evidence named by one scoped correction request reopen. Decisions and later corrections remain append-only. Appeals and physical credential transactions stay outside TALA.
- **Accepted:** LRN is an optional credential identifier when applicable, not a login. A verified-LRN collision or exact legal-name plus birth-date warning blocks the admission decision until Registrar resolves it; TALA does not perform fuzzy merging or disclose another record to the applicant.
- **Accepted:** `AdmissionCycle` owns application dates and readiness. Registrar owns immutable, versioned requirement sets. A shared calendar may project cycle dates but cannot own or edit them, and there is no generic admissions Settings or policy engine.
- **Accepted:** Preliminary evidence and official-credential results are separate vocabularies. Review copies cannot be presented as officially verified credentials. Core official-enrollment credentials cannot receive arbitrary waivers.
- **Accepted:** Applicant intake is data-minimized to application scope, minimum identity and contact, prior education, declarations, and the applicable preliminary evidence. Complete Student-reporting demographics are not collected during admission.
- **Accepted:** Registrar Admissions is one queue-first workbench with small operational counts, native search/date filters and active indicators, owner and next-action presentation, two readiness summaries, and one state-appropriate primary action. There is no analytics dashboard, applicant ranking, or bulk admission decision.
- **Accepted:** Emails are limited to submission, consolidated Action Needed, Admitted, Not Admitted, Ready for Enrollment, and withdrawal. Delivery failure never rolls back the institutional transaction.
- **Accepted:** Clinic 2 ends at the same application's derived `ReadyForEnrollment` projection. Clinic 4 consumes it automatically and alone owns registration, placement, finance, official enrollment, Student creation, student-number generation, and Student access. There is no handover button or copied record.
- **Accepted:** A requirement classified as `PostEnrollmentFollowUp` does not become an enrollment-readiness blocker. Registrar and Clinic 2 retain responsibility for the follow-up after enrollment; Clinic 4 preserves its reference and may surface it without reclassifying or deciding the credential result.

No Clinic 2 application or schema change begins until Clinics 1–6, the final cross-module contradiction and omission review, and approval of the complete authority set are finished and a bounded vertical slice is separately planned and authorized.

## 6. Academic Setup, Offerings, and Published Timetable

> **Clinic status — Approved.** The complete Clinic 3 contract now lives in [PRD 03 — Academic Setup, Offerings, and Published Timetable](./03_academic_setup_offerings_published_timetable.md) and the Clinic 3 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This baseline retains the cross-module summary and the previously accepted calendar detail.

### Accepted inputs

- **Accepted:** Academic calendar ownership follows `Decision then record`: Academic Head approves through the institution's process; Registrar records, activates, and operates the approved calendar; TALA adds no duplicate approval queue.
- **Accepted:** The full Term Setup Workbench and typed Term Calendar Package in Section 6.1 govern the calendar boundary.
- **Accepted:** Faculty supplies genuine hard unavailability only; TALA does not collect preferred times or model informal arrangements that do not change the official timetable.
- **Accepted:** There is no universal 100-student ceiling; capacity comes from actual offerings, sections, applicable rooms, and authorized placements.
- **Accepted:** CP-SAT remains TALA's principal intelligent capability, generating explainable candidates for authorized review and Registrar publication; candidate and published timetables remain separate records.
- **Accepted:** Timetable failure distinguishes source-readiness failure, proven infeasibility, timeout/unknown, and technical failure, showing reason, basis, owner, and next action without a diagnostic rules engine.
- **Accepted:** Clinic 3 is one journey from recorded academic authority through immutable timetable publication and controlled revision. It replaces separate Academic Setup, Term Offerings, and CP-SAT journey authorities.
- **Accepted:** Stable Courses, immutable Course Revisions, externally approved Curriculum Versions, simple requisites/equivalencies, and zero-or-more weekly meeting requirements form the catalog boundary. Internships without genuine recurring meetings remain academic records but are excluded from CP-SAT.
- **Accepted:** `TermCohort` plus `ClassOffering` replaces Term Offering → Section → Delivery Group layering. TALA may generate Draft Class Offerings from active curricula, confirmed standard-curriculum cohorts, forecast demand, and bounded unmet-demand evidence; Registrar alone confirms, splits, shares, adds, or cancels them. Class sources remain `Regular` or `Additional`, where `Regular` describes the offering source only and never a Student status. Shared classes require canonical identity or approved equivalency, capacity, resources, and Registrar confirmation; CP-SAT never merges cohorts.
- **Accepted:** First, Second, and institutionally approved Special Terms use the same Term Calendar Package, Class Offering, timetable, registration, account, and academic-record contracts. A Special Term requires its recorded particular schedule and attributable class-hour/class-day basis. There is no separate Summer scheduler, tutorial workflow, universal Special Term unit cap, or learner classification.
- **Accepted:** Faculty provides only hard unavailability or **No additional restrictions**. Rooms have flat suitability facts and hard unavailability. Authorized exact commitments require authority and reason; no soft locks, preferred times, travel matrix, or booking marketplace exist.
- **Accepted:** Whole-term CP-SAT uses complete hard validation and a fixed lexicographic hierarchy: cohort mode switches, cohort idle time, Faculty load imbalance, Faculty idle time, room-seat waste, then stable earlier placement. No editable weights or accuracy percentage exists.
- **Accepted:** Results distinguish `Optimal`, `Feasible`, `Infeasible`, `Unknown`, `ModelInvalid`, and `TechnicalFailure`. Failure evidence is deterministic and source-linked; only failed checks expand.
- **Accepted:** Solver-first candidate correction revalidates the whole candidate and cannot waive hard rules. Publication and every targeted revision create immutable timetable versions; no published meeting is edited in place.
- **Accepted:** Clinic 4 consumes curriculum totals, requisites, equivalencies, published Class Offerings, capacity, and official meeting times and returns bounded `UnmetClassDemandProjection` evidence to Clinic 3. Clinic 3 does not own student eligibility, proposal confirmation, placement, finance, enrollment, activation, or COR. Clinic 5 owns full curriculum evaluation and official academic-history outcomes; Clinic 4 consumes those released facts for current-term eligibility and proposed registrations.

PRD 03 owns the complete Clinic 3 behavior, conceptual records, exclusions, UI contract, salvage disposition, and acceptance scenarios. This section cannot be used alone to derive implementation.

### 6.1 Accepted Academic Calendar Contract

Use the selected **A — Term Setup Workbench** structure:

- Overview and official dates
- Operational deadlines
- Weekly teaching grid
- Date exceptions
- Failed-first readiness

Calendar ownership uses the accepted **Decision then record** boundary:

1. Academic Head owns and approves the institutional calendar through the school's authorized process outside TALA.
2. Registrar records the approved dates, authority, and evidence reference.
3. Registrar activates the recorded calendar and operates the associated deadlines.
4. Academic Head receives a read-only verification view.
5. TALA does not add a duplicate Academic Head approval queue or approval button.

Calendar concepts remain separate:

- Academic year, term, class start, and class end
- Registration period
- Enrollment Adjustment period
- Course Drop deadline
- Grade-entry period
- INC-resolution deadline only after policy confirmation
- Informational examination period; no examination-scheduling module
- Holidays, no-class dates, and approved make-up dates
- Weekly teaching hours and institutional breaks
- Room and faculty unavailability under scheduling resources, not the calendar
- Application dates under Admissions Cycle
- Payment dates under the student-term account or fee plan

Deadlines use an inclusive Asia/Manila date plus an optional cutoff time. A time is entered only when the approved source specifies one.

CHED establishes calendar and instructional-time requirements but does not supply one universal add/drop duration. TALA records the institution's approved dates and authority instead of hard-coding another school's value. Supporting benchmarks include [CHED CMO No. 1, s. 2011](https://ched.gov.ph/wp-content/uploads/2017/10/CMO-No.01-s2011.pdf), [UP Diliman Change of Matriculation](https://our.upd.edu.ph/files/acadinfo/CHANGE%20OF%20MATRICULATION.pdf), [UP Diliman AY 2026–2027 calendar](https://our.upd.edu.ph/files/calendar/regular/ACAD%20CAL%202026-2027.pdf), and [UPOU Registrar FAQ](https://registrar.upou.edu.ph/faq-enrollment/).

The accepted calendar contract is a typed **Term Calendar Package**. It is one workbench composed of four small authoritative record groups, not one generic event store or configurable calendar-rules engine.

#### Term Calendar

The Term Calendar owns:

- Academic year
- Controlled term type
- Display label
- Administrative term start and end dates
- Instruction/class start and end dates
- State: `Draft`, `Active`, or `Closed`
- External approval reference
- External approval date
- Registrar recorder and recorded-at timestamp
- For a Special Term, its approved particular schedule and attributable class-hour/class-day basis

Asia/Manila is the system timezone and is not another editable calendar field. `Draft` is the editable preparation state. `Active` is the version consumed by operational actions. `Closed` preserves the historical term. Activation is blocked when required calendar records are incomplete or internally inconsistent; it does not reproduce the Academic Head's institutional approval inside TALA.

#### Operational Windows

Each Operational Window owns only:

- Controlled window type
- Open date
- Inclusive close date
- Optional approved cutoff time
- Optional public-facing note

V1 supports these controlled types:

- Enrollment
- Late Enrollment, only when supported by an approved institutional period
- Enrollment Adjustment
- Course Drop
- Examination Period
- Grade Entry
- INC Resolution, only after an approved INC policy is available

The Term Calendar Package owns the approved opening and closing dates. Clinic 4 owns the `Enrollment` window's bounded applicability: Ready Applicants, Standard continuing Students, Individually Advised or exception cases, or all otherwise eligible learners. These are fixed code-owned choices, not arbitrary audience rules, programmable effects, or per-window email configuration. Examination Period is informational unless an approved institutional rule gives it a direct class effect.

Application dates remain under Admissions Cycle. Payment due dates remain under the Fee Plan or Student-Term Account. Offering preparation, schedule generation, schedule review, late-grade authorization, and grade finalization remain readiness milestones or recorded exception decisions rather than calendar windows unless an approved institutional source establishes a real deadline.

#### Weekly Teaching Grid

The Weekly Teaching Grid owns one row per allowed teaching day:

- Weekday
- Earliest allowed class start
- Latest allowed class end

Recurring institutional breaks own:

- Weekday
- Break start and end
- Display label

The grid is a CP-SAT operating input. It does not own faculty preferences, faculty hard unavailability, room unavailability, or informal arrangements. TALA supplies no assumed weekday, opening time, closing time, or break value; Registrar records only institutionally approved operating values.

#### Dated Exceptions

Each Dated Exception owns:

- Date or date range
- Optional start and end times
- Controlled type
- Display title or reason
- Instructional effect
- Authority or evidence reference

Instructional effect is limited to:

- `NoClasses`
- `MakeUpAllowed`
- `InformationOnly`

An exception may identify a holiday, authorized no-class period, institutional closure, or approved make-up opportunity. `MakeUpAllowed` does not silently create a meeting or alter the published timetable; the responsible scheduling workflow must record and publish any actual timetable change.

#### Workbench and role projections

The Term Setup Workbench presents:

- **Overview:** term identity, official and instructional dates, state, and approval evidence
- **Operational Deadlines:** native table with type and date-range filters
- **Weekly Teaching Grid:** compact editable weekday rows with recurring break rows
- **Dated Exceptions:** native table with type and date-range filters; an optional read-only month view may supplement but never replace it
- **Readiness:** failed-first A1 results with owner, source link, blocker, and next action

Registrar records, edits while Draft, activates, and closes the package. Academic Head sees the same calendar as a read-only verification projection. Students and faculty see only relevant active dates and exceptions in their own workspaces. Scheduling consumes instructional dates, the weekly grid, and applicable blocking exceptions. Enrollment, adjustment, dropping, grade entry, and INC handling consume only their typed windows.

Recording or activating a date does not itself send an email. The separate transactional-message contract decides whether a qualifying business transition warrants email, preventing the calendar from becoming a hidden notification engine.

No Clinic 3 application or schema change begins until Clinics 1–6, canonical `00`–`06` consolidation, the final cross-module contradiction and omission review, and approval of the complete authority set are finished and a bounded vertical slice is separately planned and authorized.

## 7. Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop

> **Clinic status — Approved.** The complete Clinic 4 contract lives in [PRD 04 — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop](./04_current_term_registration_official_enrollment.md) and the Clinic 4 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This section retains its cross-module summary.

### Accepted summary

- **Accepted:** The standalone Study Plan module is removed. `EnrollmentSelectionBasis` is `StandardCurriculum` or `IndividuallyAdvised`; the current `RegistrationCase` owns versioned proposed course registrations. Selection basis is independent from academic progress, enrollment effect, identity, and funding.
- **Accepted:** Learners do not freely shop for subjects or classes. Standard proposals derive from the assigned curriculum and published cohort classes; Registrar prepares bounded Individually Advised proposals from curriculum evaluation, released results, approved credits/equivalencies, and actual offerings.
- **Accepted:** Clinic 4 uses only released Clinic 5 results. A failed prerequisite blocks its dependent chain, pending/incomplete results do not satisfy prerequisites, and unrelated eligible curriculum courses remain available.
- **Accepted:** The Term Calendar Package owns enrollment-window dates, while Clinic 4 selects only the bounded applicable audience. Clinic 4 returns aggregate unmet-class-demand evidence to Clinic 3; Clinic 3 may generate Draft Class Offerings, but Registrar remains the confirmer and CP-SAT never merges cohorts.
- **Accepted:** Clinic 2 retains ownership of unresolved `PostEnrollmentFollowUp` credential results. Clinic 4 preserves and surfaces their references without turning them into enrollment blockers.
- **Accepted:** One registration engine supports new, continuing, failed/back-course, transferee, returning/reactivated, shifted, old-curriculum, bridging, graduating, and approved Special Term circumstances without storing Regular/Irregular policy status.
- **Accepted:** Stored Registration Case outcomes are `Active`, `OfficiallyEnrolled`, `CancelledByLearner`, `CancelledByRegistrar`, and `NotEnrolled`. Current stage, owner, reason, and next action are derived from five checkpoints: eligibility, confirmed proposal, valid placement, Accounting clearance/coverage, and Registrar finalization.
- **Accepted:** Learner confirmation transactionally validates capacity and creates temporary seat reservations that share the applicable institutional enrollment/payment deadline. Full/stale classes produce Registrar shortage items; there is no ranked waitlist or individual countdown.
- **Accepted:** Official enrollment requires clearance for the amount currently required, not a universal zero balance. Verified payment, Applied Approved Coverage, a mixed basis, or institutionally authorized `NoPaymentRequired` may satisfy that amount. Funding processing and later obligations remain external/separate from academic finalization; service-specific restrictions cannot globally block login or examinations.
- **Accepted:** Every-term finalization atomically revalidates all checkpoints, converts reservations, creates official registrations, activates schedules/rosters, records enrollment, creates immutable COR version 1, and publishes account/academic effects. Only first-ever finalization also creates the minimal Student profile, permanent `SIA-YYYY-NNNN`, and Student access on the existing account.
- **Accepted:** Finalization queues one **Official enrollment and COR ready** message. On first enrollment it also announces Student access; it is not accompanied by a separate activation email. A published timetable revision likewise produces one shared event: Clinic 3 owns the publication trigger, while Clinic 4 supplies affected officially enrolled Students and their updated schedule/COR context.
- **Accepted:** Adjustment and Course Drop are separate externally authorized outcomes recorded in the same workbench. Every applied change synchronizes placement, roster, schedule, account-review projection, and a new immutable COR version; fees, penalties, and refunds are never invented.
- **Accepted:** The learner receives one guided status page; Registrar receives one Students & Enrollment workbench; Accounting receives a bounded Enrollment Clearance queue. Native Filament tables, infolists, forms, filters, and Action Groups carry the UI.

No Clinic 4 application or schema change begins until Clinics 1–6, canonical `00`–`06` consolidation, the final cross-module contradiction and omission review, and approval of the complete authority set are finished and a bounded vertical slice is separately planned and authorized.

## 8. Teaching, Grades, Academic Records, and Completion

> **Clinic status — Approved.** The complete Clinic 5 contract lives in [PRD 05 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion](./05_teaching_grades_academic_records_completion.md) and the Clinic 5 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This baseline retains only the cross-module summary.

### Accepted summary

- **Accepted:** TALA records one controlled final result per official course registration. Faculty calculates period grades, raw scores, attendance, and formulas outside TALA. The accepted final vocabulary is `1.00` through `3.00` in quarter-point steps, `4.00`, `5.00`, or `INC`; `1.00–4.00` satisfies the course, `5.00` does not, and `P` is not an official mark.
- **Accepted:** One roster exists per official `ClassOffering`, including externally arranged courses without recurring timetable meetings. Only officially enrolled learners appear. One designated Faculty submits the complete roster; Registrar releases it as a whole or returns specified rows with one consolidated explanation.
- **Accepted:** Roster state is `Draft`, `Submitted`, `Returned`, or `Released`. Released grades are immutable events. Grade entry uses the Term Calendar's definite window and due date; an overdue submission requires recorded late authority.
- **Accepted:** `TermWeightedAverageProjection` is the full-precision, unit-weighted result for one term; `CumulativeGwaProjection` uses all included attempts and units through the selected grade-complete term and is not an arithmetic mean of term values. The neutral term label is **Term weighted average**; **Term GPA** or another label requires recorded Servitech authority and an effective term. All attempts count, while PE and NSTP—including CWTS, LTS, and ROTC equivalents—are excluded under the client-confirmed Servitech rule. `INC`, dropped or withdrawn results, and nonnumeric approved credit are excluded. Values display to two decimals in academic views and remain absent from the standard TOR.
- **Accepted:** `AcademicAverageReadiness` is `GradesNotComplete`, `IncompleteResultPending`, `Available`, or `NotApplicable`. A partially released term shows **Grades not complete** and no partial term/new cumulative value; a grade-complete zero-denominator term shows **Not applicable — no included academic units** rather than zero. An unresolved included `INC` withholds the current term and current cumulative final value and does not satisfy prerequisites. Completion, authorized lapse, or later correction preserves history and recalculates every affected projection.
- **Accepted:** Authorized grade corrections append a superseding result without a hard technical cutoff. They recalculate the original term weighted average, every affected cumulative GWA, curriculum evaluation, progress recommendations, and completion readiness, while earlier decisions and issued transcript snapshots remain historical.
- **Accepted:** Full curriculum evaluation is deterministic from effective curricula, every released attempt, approved credits/equivalencies, current official enrollment, and effective shift, bridging, deficiency, or old-curriculum mappings. TALA has no what-if audit, speculative graduation date, generic substitution builder, automatic equivalency decision, or double counting.
- **Accepted:** The transparent PUP-based academic-progress profile is a capstone reference, not CHED-wide policy. `Good` is automatic; Warning, Probation, load reduction, and Ineligible effects require a recorded authorized institutional decision. An unresolved `INC` keeps consequential progress pending.
- **Accepted:** Append-only lifecycle events derive `Active`, `OnLeave`, `Withdrawn`, `TransferredOut`, or `Completed`. Course Drop remains Clinic 4. Lifecycle changes preserve academic history, do not infer refunds, do not disable historical portal access, and never create a registration or seat by themselves.
- **Accepted:** Completion readiness is `NotEligible`, `EligibleToApply`, `AwaitingResultsOrClearance`, `ReadyForConferral`, or `Conferred`. Applying records intent only. Conferral requires satisfied curriculum, no unresolved result, an application, source-owned clearances, and recorded external authority.
- **Accepted:** Student Academics presents released grades, term weighted average/cumulative GWA or its explicit readiness state, curriculum evaluation, confirmed progress, units, completion readiness, and history. Students may print an unofficial record only.
- **Accepted:** Because the supplied Servitech TOR format is unavailable for reuse, TALA demonstrates one original code-owned, versioned layout labelled **Proposed institutional format — Not for official issuance**. Registrar may preview that proposed layout, but **Record issuance** remains unavailable until the institution approves a code-owned template version and external certification is complete. Issued snapshots retain void/replacement and supersession history. Request, payment, delivery, CAV, signature, seal, and diploma processes remain external; Clinic 6 supplies only the bounded output-payment clearance.
- **Accepted:** Clinic 5 exposes released `OfficialCourseResultProjection`, `AcademicEnrollmentEffect`, curriculum-evaluation, and lifecycle facts to Clinic 4. Draft or submitted grades never change registration. A later correction sends an affected active Registration Case to Registrar review rather than silently changing subjects.
- **Accepted:** Grade-release and other approved academic emails contain no grade values or attachments. Mail failure never rolls back an academic transaction.

No Clinic 5 application or schema change begins until Clinics 1–6, canonical `00`–`06` consolidation, the final cross-module contradiction and omission review, and approval of the complete authority set are finished and a bounded vertical slice is separately planned and authorized.

## 9. Accounts, Official Outputs, Operations, and Assurance

> **Clinic status — Approved.** Complete journey, state, data, output, operations, and UI authority lives in [PRD 06 — Accounts, Official Outputs, Operations, and Assurance](./06_accounts_official_outputs_operations_assurance.md) and the Clinic 6 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This baseline retains only the cross-module boundary.

### Accepted summary

- TALA provides one narrow fixed Program-and-Term Fee Plan for ordinary cases and one continuous same-human-subject/RegistrationCase Term Account companion. An Assessment version uses exactly `PublishedFeePlan` or, for an approved selection-specific exception, `AuthorizedIndividualAssessment`. The latter records Accounting's exact externally calculated lines and obligations without executing a formula. `Person` is only a documentation label for identity continuity, not a new master record. TALA does not replace Accounting's bookkeeping, cashiering, collections, general ledger, refund, or BIR-invoicing procedures.
- `ApprovedCoverage` is an append-only externally approved Term Account effect categorized as scholarship, sponsorship, government subsidy, or other authorized funding. It is `Applied`, `Superseded`, or `Reversed`, targets an exact Assessment/obligation, and never becomes a funding application, eligibility, renewal, disbursement, accommodation, allocation, refund, or cash-movement workflow.
- Clinic 6 publishes `EnrollmentPaymentRequirementProjection` to Clinic 4. It states the assessment basis and exact registration/change source, current enrollment obligation, separate verified-payment and Approved-Coverage amounts, remaining required amount, state, `SatisfactionBasis = VerifiedPayment | ApprovedCoverage | Mixed | NoPaymentRequired | None`, authority/source/as-of time, later-obligation indicator, and authorized account link. `Cleared` never means a lifetime zero balance. If assessment or required coverage authority is invalid, the projection is `Unavailable`/`ActionNeeded` as applicable without a zero, silent cap, or percentage fallback.
- Clinic 6 publishes request-specific `OfficialOutputPaymentClearance = Cleared | NotRequired | ActionNeeded` to Clinic 5. It never creates a global finance hold.
- A later missed obligation never reverses official enrollment or blocks login, classes, examinations, or released academic records. COR remains Clinic 4's immutable enrollment output with an assessment-at-finalization snapshot; Account Statement/SOA remains Clinic 6's current non-tax account output.
- Manual evidence stays unverified until Accounting checks the actual external bank, wallet, cash, or institutional source. Exact valid signed PayMongo evidence posts idempotently; browser returns do not prove payment, and mismatches enter an exception queue.
- Clinic 6 generates only a non-tax Account Statement/SOA, non-tax Payment Acknowledgment, contextual Account Status CSV, and contextual Verified Payments CSV. Accounting owns any required BIR invoice or external tax document.
- Accounting navigation is **Fee Plans** and one tabbed **Student Accounts** workbench. Student Finance is summary-first and becomes read-only for alumni.
- System Health shows only locally recorded evidence and explicitly labels provider or physical-backup facts `Not checked by TALA`. Governance & Audit is read-only and automatic disposal remains disabled while the institutional retention schedule is not approved.
- The selected MVP infrastructure direction is a self-managed Hostinger KVM 1 VPS with independent encrypted off-server backups, additional encrypted ORICO offline copies, six-hour RPO, and eight-hour RTO. Provider facts and recovery performance require external operational evidence.
- Clinic 6 owns only the idempotent **Verified payment posted** email.

## 10. Rewritten PRD and UI Contract

A clinic is the planning process used to complete one coherent part of the authority set. It is not a TALA feature or a separate product document.

While a clinic is open, this baseline preserves its accepted inputs and clearly labels the remaining working material. When the clinic completes, complete product-authority detail is written into the owning rewritten PRD; the baseline retains only product-wide rules, module boundaries, and a concise accepted summary. The same detailed rule must not be maintained independently in both places.

Detailed behavior and UI are defined module by module. Clinic 0 must be approved before the application is inventoried. After that gate, a shallow read-only inventory may map the current panels, routes, records, migrations, services, integrations, seeders, tests, and cross-module dependencies without treating them as correct product behavior.

Module clinics proceed in lifecycle and dependency order, one at a time. Each clinic follows the same bounded sequence:

1. Establish the desired policy, institutional boundary, user journey, data, role handoffs, and UI from the accepted baseline and verified evidence without allowing current code to dictate the product.
2. Inspect only the implementation and physical-schema surfaces relevant to that module.
3. Classify each relevant surface as `Retain`, `Simplify`, `Replace`, `Remove`, or `Quarantine`.
4. Reconcile material feasibility or authority conflicts, then finalize and approve the rewritten PRD and its UI blueprint.
5. Continue to the next module clinic without deriving an implementation task.
6. After Clinic 6, consolidate the rewritten authorities into the final canonical `00`–`06` file set, update links and the PRD index, and preserve replaced legacy inputs as clearly non-authoritative evidence.
7. Perform one final cross-module contradiction and omission review across all rewritten PRDs, UI authorities, shared contracts, handoffs, states, outputs, notifications, and exclusions.
8. Resolve the review findings and approve the complete TALA authority set as one coherent product definition.
9. Only after that approval, derive journey-complete vertical implementation tasks and plan and deliver them under the orchestration protocol.

The rewritten PRD is the product plan for its module, but one approved module PRD is not sufficient authority to begin implementation. After the complete authority set passes the final review and approval gate, a later slice plan does not redesign the product; it states how a bounded part of the approved product will be delivered in the existing repository and how conformance will be proved. A PRD is reopened only when stronger authority, a material implementation constraint, a cross-module contradiction, or an explicit user change invalidates it.

Each complete module clinic must settle:

- Relevant legacy requirements and their lean verdicts
- Applicable law, regulator evidence, institutional authority, and unresolved policy gaps
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
- Page inventory and information hierarchy
- Fields, columns, filters, sorting, actions, and evidence
- Empty, loading, error, and inaccessible states
- Desktop, mobile, print, accessibility, and keyboard behavior
- Explicit exclusions and external/manual decisions
- Realistic demonstration data and browser acceptance script

Every primary page receives a low-fidelity wireframe. Five core journeys receive detailed prototypes:

1. Application
2. Timetable publication
3. Enrollment
4. Grades and progression
5. Account and payment status

Key pages receive two or three visual alternatives. Routine forms and detail pages receive one evidence-based recommended blueprint.

Use native Filament v5 first:

- Tables for queues and rosters
- Infolists for official read-only records
- Forms for actual input
- Tabs and Sections for progressive disclosure
- Action Groups for secondary actions
- Wizards only for genuinely chronological flows
- Widgets only for small operational counts
- Native filter panels and active indicators

Custom components or plugins require a demonstrated native capability gap. A month calendar may supplement dated exceptions as a read-only view; it cannot replace the Term Setup workbench.

No public HTTP API is added. The final shared vocabulary below names conceptual responsibilities, not approved tables, classes, routes, or a mandate to preserve a legacy abstraction.

| Owner | Canonical conceptual vocabulary |
|---|---|
| Clinic 1 | Credential account, Staff access profile, workspace context, account access state |
| Clinic 2 | `AdmissionCycle`, `AdmissionApplication`, `AdmissionDecision`, `OfficialCredentialResult`, `ReadyApplicantProjection` |
| Clinic 3 | `ProgramAuthority`, `TermCalendarPackage`, `WeeklyTeachingGrid`, `DatedException`, `ClassOffering`, timetable candidate, `PublishedTimetableVersion`, `PublishedClassAvailabilityProjection`, `UnmetClassDemandProjection` |
| Clinic 4 | `RegistrationCase`, `EnrollmentSelectionBasis`, `ProposedRegistrationVersion`, `EnrollmentCheckpointProjection`, `EnrollmentSeatReservation`, `OfficialTermEnrollment`, `CertificateOfRegistrationVersion`, `EnrollmentAdjustment`, `CourseDropOutcome` |
| Clinic 5 | `FinalGradePolicyVersion`, `OfficialCourseResultProjection`, `AcademicEnrollmentEffect`, `CurriculumEvaluation`, `CompletionReadiness`, `TranscriptSnapshot` |
| Clinic 6 | `FeePlan`, `TermAccount`, `AssessmentBasis`, `AssessmentVersion`, `PaymentEvidence`, `PaymentPosting`, `EnrollmentPaymentRequirementProjection`, `OfficialOutputPaymentClearance` |
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
| Clinic 5 → Clinic 4 | `OfficialCourseResultProjection`, `AcademicEnrollmentEffect`, lifecycle facts | Released/confirmed versions only; pending decision blocks the affected action | Use draft grades or silently change a Registration Case |
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
| TOR | Clinic 5 transcript snapshot and proposed or institution-approved template version | Proposed demonstration preview until institutional template approval; issued snapshot is Registrar-controlled and externally certified | Void/replacement/supersession is append-only; failure produces no official-looking artifact |
| Account Statement / SOA and Payment Acknowledgment | Clinic 6 Term Account and verified posting | Authenticated non-tax outputs | Reversal remains visible and marks acknowledgment reversed/superseded |
| Account Status CSV / Verified Payments CSV | Clinic 6 owning queues | Contextual, allowlisted, purpose-recorded, role-authorized | Failure records no completed export and exposes no partial file |

During each clinic, physical tables are inspected only as read-only salvage evidence and conceptual data contracts are defined without authorizing migrations. Physical tables may be designed or changed only after the complete authority set passes the final cross-module review, a vertical implementation task is separately planned and authorized, and the task has reconciled the relevant existing implementation. Shared identifiers and cross-module records must remain consistent with the complete approved authority set.

## 11. Reconciliation and Future Vertical Delivery

After Clinic 0 is approved, perform one shallow, read-only implementation inventory. Its purpose is to identify what exists, what appears connected, what has verification evidence, and where module seams or duplicate responsibilities may exist. It does not approve current behavior and does not authorize code, schema, seed-data, tracker, or external changes.

### 11.1 Post-Clinic-0 Shallow Implementation Inventory

**Recorded:** 2026-08-04

**Source revision:** local commit `0ed1c885`

**Resume delta:** local commit `46892b09` changes only `AGENTS.md` and the orchestration protocol after the recorded inventory. No application implementation, schema, seeder, or test surface changed, so the inventory evidence remains attributable.

**Disposition authority:** none. These are routing facts and investigation signals only. `Retain`, `Simplify`, `Replace`, `Remove`, or `Quarantine` decisions remain owned by the relevant module clinic after its desired product boundary is established.

The inventory inspected repository structure, framework configuration, panels, routes, models, migrations, action namespaces, integrations, notifications, seeders, and tests. It did not query the live application database, run migrations or tests, inspect seeded records, exercise browser journeys, or judge current behavior as correct.

#### Existing application footprint

| Surface | Shallow finding |
|---|---|
| Runtime | PHP 8.2, Laravel 12, Filament 5, Livewire 4, Fortify, MySQL |
| Workspaces | Three Filament panels: staff/admin, applicant, and student |
| Routes | 142 non-vendor routes: 119 staff/admin, 11 student, 6 authenticated outputs, 4 applicant, 1 public root, and 1 PayMongo webhook |
| UI | 39 Filament resources, about 125 resource/custom pages, and 3 widgets |
| Domain code | 62 models, 158 action-area PHP files, 49 policies, 8 controllers, 2 queued jobs, 5 mailables, and 1 notification class |
| Data definition | 31 migrations, 45 factories, and 8 seeders; this is a file inventory, not live-schema confirmation |
| Verification assets | 148 PHPUnit test files: 137 feature and 10 unit plus the base test case; many are named after historical TAL slices |

The staff panel already projects different navigation to Registrar, Accounting, Faculty, Academic Head, and the legacy-labelled System Super Admin role. Applicant and Student have dedicated panels. This is useful salvage evidence for the accepted shared-record/role-projection model, but the product role is now **System Administrator** and every retained implementation surface must adopt that label and the narrower approved authority.

#### Module seams and concentration

The application is organized mainly under domain-oriented `Actions` namespaces rather than one generic service layer. Complexity is concentrated in Scheduling (37 action files), Integrations (27), Enrollment (17), Finance (12), Grades (11), and Applicants (10). Several individual classes exceed 500 lines; the largest import, scheduling-validation, reporting, placement, finance-evidence, COR, and applicant services are investigation priorities rather than automatic rewrite targets.

Detected action-to-action seams include:

- Scheduling to solver integrations, enrollment, system administration, and student projections.
- Enrollment to calendar, finance, grades, and scheduling.
- Finance and integrations back to enrollment records.
- Applicant intake to the Admission Cycle and the shared enrollment-readiness projection.
- Student Hub as a projection over enrollment, finance, lifecycle, COR, grades, and published schedules.

This confirms that modules are interconnected, but it does not justify horizontal redevelopment. Each clinic must define the producer's authoritative output and the minimum downstream contract before implementation slices are derived.

#### Specific reconciliation signals

- **Identity and access:** centralized panel eligibility, role-aware login responses, email verification, seven canonical roles, and role-specific navigation already exist. Clinic 1 should treat this as substantial salvage evidence, then verify the desired identity contract and actual journeys before classifying it.
- **Calendar:** the current code uses one broad `CalendarEvent` model with event types, scopes, and many process keys. Separate `AcademicCalendarWindows` and `CalendarEvents` resources project different uses of that same record. PRD 03 supersedes that product shape with the typed Term Calendar Package inside Term Planning; later implementation reconciliation must map every consumer before altering the physical records.
- **Settings:** `SystemSetting` contains eight hard-coded definitions, all read-only in its generic resource. Seven are dormant or superseded metadata; only the student unit-load JSON fallback has a verified runtime consumer. The owning clinics must decide whether that operational value belongs in a typed record and whether the generic Settings surface has any remaining user purpose.
- **Scheduling and payments:** both already have adapters. The default solver driver is a local stub and the default payment driver is a mock; Cloud Run and PayMongo are isolated alternatives. Clinic 3 and Clinic 6 must preserve only the adapters justified by their accepted journeys and assurance needs.
- **Transactional email:** current domain email pathways cover applicant action-required/approved transitions, published or revised schedules, official-enrollment schedule delivery, and posted payments. A queued general system notification class exists but no normal application call site was found. Each clinic still owns its final trigger-recipient-template-failure matrix.
- **Demonstration data:** the normal database seeder creates the authorization vocabulary plus admission requirements and FAQs. Large MIN/MIDDLE/MAX, TAL-96 acceptance, PayMongo demo, and exploration-persona builders exist as explicit acceptance commands or auxiliary seeders rather than normal application seeding. They remain untouched until module acceptance data is redesigned.
- **Tests:** the test environment is explicitly guarded to MySQL database `test_tala_db`. The suite is extensive evidence of implemented behavior, but issue-named tests cannot make old PRD rules authoritative and were not rerun during this inventory.
- **Authorization coverage:** 49 named policies cover most primary aggregate models. Several child/evidence records rely on parent or service boundaries rather than a same-named policy; Clinic reconciliation must verify inaccessible-record behavior instead of inferring coverage from policy count.
- **Governance state:** the complete canonical authority set is approved for task derivation. `TALA-Rescue-Next-Steps.md` contains no legacy executable roadmap or active contract; superseded task history is archived and its local/Linear disposition is owned by the sync tracker without implying a Linear mutation.

#### Inventory conclusion

The codebase is neither a blank slate nor proven fit for the rebaselined product. It contains meaningful Laravel, Filament, authorization, integration, output, and test foundations alongside concentrated complexity, generic historical configuration, broad shared records, and task-specific acceptance machinery. Clinic 1 began from the desired Identity/Public/Auth journey and inspected only the bounded implementation evidence needed to classify that module. No later module is reopened or implemented by this inventory.

Definition proceeds in this lifecycle order across the six module clinics:

1. Identity, Access, and Public Entry
2. Application, Admission Decision, and Enrollment Readiness
3. Academic Setup, Offerings, and Timetable Publication
4. Current-Term Registration, Official Enrollment, Student Activation, Adjustment, Course Drop, COR, and the minimum Accounting-clearance interaction required by enrollment
5. Faculty Schedule, Grades, Progression, Transcript, and Completion
6. Account Summary, Payment Evidence, SOA, System Health, and Operations Assurance

For each module, the clinic first establishes the desired product and then performs its bounded read-only implementation reconciliation. Preserve aligned implementation and unrelated work. No clinic authorizes an application change, task breakdown, destructive rebuild, or seed replacement.

After all six rewritten PRDs and their UI authorities are complete, first consolidate them into the canonical `00`–`06` file set and preserve the replaced legacy inputs as non-authoritative evidence. The final cross-module review then proves that their shared records, status vocabularies, readiness conditions, role handoffs, emails, official outputs, exclusions, and cross-role projections form one coherent system. Every contradiction and material omission must be resolved before the complete authority set is approved.

Only after that complete-authority approval may one or more implementation tasks be derived. Every task must be a vertical slice with a user-visible outcome; tasks must not be organized as disconnected horizontal layers such as all migrations first, all services next, and UI later. Each slice is then planned and executed through the orchestration protocol, but its plan implements the approved authority set rather than reopening product decisions.

A module may finish before a later consumer exists only when its own published output is independently usable and the downstream contract is explicit. When the current module requires a result owned mainly by a later module, either include the minimum participating-role record and action needed for the current journey or narrow the current module's promised endpoint. A module cannot be called complete when its successful journey depends on placeholder behavior, direct database manipulation, or an unbuilt mandatory checkpoint.

The next module clinic begins after the current rewritten PRD and UI authority pass their document-review gate. It does not wait for application implementation.

A slice is ready to be derived and planned only when Clinics 1–6 are complete, the final cross-module review is resolved, the complete authority set is approved, and its policy, narrative, readiness, email, conceptual data, UI, and acceptance contracts are internally consistent.

A slice is complete only when it has:

- Schema and domain logic
- Authorization
- Every participating role interface
- Cross-role outputs
- Required emails and audit evidence
- Realistic demonstration data
- Focused automated tests
- Browser-verified desktop and mobile journeys
- A working end-to-end demonstration without database manipulation

Passing backend tests alone is not acceptance.

### 11.2 Clinic 1 Bounded Reconciliation

Clinic 1 inspected only the identity, authentication, workspace-entry, access-administration, public-entry, and related verification surfaces after the desired journey had been established. The detailed classification and replacement contract live in PRD 01; this table records the cross-module reconciliation boundary.

| Verdict | Clinic 1 disposition |
|---|---|
| `Retain` | Fortify email authentication, session guard, email verification, password recovery, three Filament panels, Spatie authorization, central panel gates and policies, the public visual language and FAQ foundation, and the branded authentication shell when native-feature, accessibility, and responsive compatibility pass. |
| `Simplify` | The credential account becomes security/access ownership only; the public page becomes a task gateway; account profile becomes a focused Account Security surface. |
| `Replace` | Silent role-priority redirects become an explicit workspace resolver; administrator-created Staff passwords become invitations; archive/restore becomes disable/reactivate; one-role Staff editing becomes fixed multi-role contexts; Applicant registration copy becomes account-creation copy. |
| `Remove after dependency migration` | Username authentication/data, authentication-owned legal-name fields, Applicant workflow state stored on the credential account, editable role/permission UI, and account archival semantics. |
| `Quarantine` | Current mixed status, name, username, and archive fields remain untouched until a later implementation increment migrates every consumer and proves safe removal. |

This reconciliation does not authorize dropping fields or rewriting working authentication. Each later approved vertical slice must prove its exact consumers, preserve aligned behavior, and deliver its visible journey across schema, logic, authorization, UI, email/audit evidence, tests, and browser acceptance.

### 11.3 Clinic 2 Bounded Reconciliation

Clinic 2 established the desired application-to-enrollment-readiness journey before inspecting the bounded admissions implementation. The detailed classification and replacement contract live in PRD 02; this table records the cross-module reconciliation boundary.

| Verdict | Clinic 2 disposition |
|---|---|
| `Retain` | Applicant panel separation, draft-saving foundation, private uploads with validation/checksum/history, Registrar authorization, native Filament queue foundations, activity/audit logging, queued-mail evidence, and exact-match warning concepts when conformance passes. |
| `Simplify` | Applicant intake to the approved minimum fields and six stored states; Home and Requirements to one next action and two readiness groups; Admissions to one queue; evidence to distinct preliminary and official results; readiness to a failed-first cycle checklist. |
| `Replace` | Generic admissions calendar windows with `AdmissionCycle`; generic policy rows with immutable requirement-set versions; handover with the shared Clinic 4 projection; user-owned workflow state with application-owned state; post-created Student duplicate repair with pre-decision identity review. |
| `Remove after dependency migration` | Returning/readmission applications, modality/preferred time, Mark for Evaluation, Approved for Handover, six blocking levels, duplicated checklist state combinations, arbitrary waiver/undertaking, Student creation/access/enrollment inside admissions, over-collected fields, generic requirement Settings, and quota/payment-secured admission rules. |
| `Quarantine` | Existing columns, actions, policy/checklist machinery, calendar links, and handover consumers remain untouched until a later approved slice maps every dependency and proves safe migration. |

This reconciliation is read-only product-planning evidence. It does not authorize a migration, data deletion, Student-identity change, or implementation task.

### 11.4 Clinic 3 Bounded Reconciliation

Clinic 3 established the desired authority-to-published-timetable journey before classifying the bounded academic setup and scheduling implementation. The complete contract lives in PRD 03; this table records the cross-module reconciliation boundary.

| Verdict | Clinic 3 disposition |
|---|---|
| `Retain` | Immutable course/curriculum foundations, term records, Faculty/room sources, CP-SAT integration, immutable snapshots and status distinctions, independent candidate validation, candidate/published separation, revision evidence, queued schedule mail, and native Filament foundations when conformance passes. |
| `Simplify` | Calendar into the typed Term Calendar Package; curricula into one grouped sheet/import; Faculty availability into one declaration; class planning into Term Cohort plus Class Offering. |
| `Replace` | Term Offering → Section → Delivery Group layering; equal-weight objective and generic constraint profiles; technical run-first UI; unrestricted manual override; automatic handover or publication assumptions. |
| `Remove after dependency migration` | Configurable time granularity, assumed day/hour values, preferred times, HyFlex, universal capacity ceilings, separate special-offering engines, generic approval/policy/override machinery, duplicated readiness states, exam scheduling, and automatic term cloning. |
| `Quarantine` | Existing columns, services, solver contracts, resources, routes, and tests remain untouched until a later approved task maps every consumer and proves safe migration. |

### 11.5 Clinic 4 Bounded Reconciliation

Clinic 4 established the desired eligible-learner-to-official-enrollment journey before classifying bounded enrollment, placement, finance-clearance, COR, and change-processing implementation. The complete contract lives in PRD 04; this table records the cross-module boundary.

| Verdict | Clinic 4 disposition |
|---|---|
| `Retain` | Transactional placement/finalization, row locking, idempotency, schedule/conflict validation, finance-projection integration, authorization foundations, COR rendering/logging, and native Filament foundations when conformance passes. |
| `Simplify` | Nine gates into five accountable checkpoints; enrollment state into terminal outcomes plus a derived stage; course planning into proposed-registration rows; capacity into protection, reservation, and shortage evidence. |
| `Replace` | Standalone Study Plan, policy-driving Regular/Irregular status, learner-controlled arbitrary selection, generic overrides, global holds, and manually re-entered Term Offerings. |
| `Remove after dependency migration` | Unsupported numeric overload/default fees, zero-balance assumptions, ranked waitlists, duplicate Applicant/readmission paths, live installments in COR, and generic policy/state-machine machinery. |
| `Quarantine` | Existing fields, services, actions, routes, and tests remain untouched until post-authority task derivation maps every consumer and proves safe migration. |

This is read-only product-planning evidence. It authorizes no migration, data deletion, enrollment transition, or implementation task.

This reconciliation does not authorize a solver-contract change, schema change, data deletion, timetable generation, deployment, or implementation task.

### 11.6 Clinic 5 Bounded Reconciliation

Clinic 5 established the official-roster-to-academic-record-and-completion journey before classifying bounded grade, lifecycle, Student Academics, completion, and transcript implementation evidence. The complete contract lives in PRD 05; this table records the cross-module boundary.

| Verdict | Clinic 5 disposition |
|---|---|
| `Retain` | Roster and immutable result-event foundations, transaction locking, late-authority evidence, lifecycle history, completion snapshots, authorization, and native Filament foundations when conformance passes. |
| `Simplify` | Faculty entry to one final result; curriculum evaluation to one deterministic projection; progress to recommendation plus authorized decision; completion to application, readiness, and conferral. |
| `Replace` | Period-grade calculation and formula engine, released `P`, mutable released grades, legacy Term Offering dependencies, manual graduation batches, and global-hold completion behavior. |
| `Remove after dependency migration` | Preliminary/Midterm/Final storage, raw gradebook logic, generic grading DSL, arbitrary GWA editing, attendance, learner what-if audit, transcript-template editing, internal appeals/chat, and Student official-TOR self-download. |
| `Quarantine` | Existing fields, services, pages, actions, routes, configuration, and tests—including hard-coded `365`/`5.00` values and current-time-based INC deadline calculation—remain implementation evidence until post-authority task derivation maps every consumer and proves conformance. |

This is read-only product-planning evidence. It authorizes no academic-record mutation, migration, data deletion, task derivation, or implementation.

### 11.7 Clinic 6 Bounded Reconciliation

Clinic 6 established the Fee-Plan-to-continuous-Term-Account journey and the bounded Clinic 4/5, learner-output, operations, privacy, and assurance boundaries before classifying finance, report, integration, retention, and deployment evidence. The complete contract lives in PRD 06; this table records the cross-module disposition.

| Verdict | Clinic 6 disposition |
|---|---|
| `Retain` | Append-only/versioned assessment and account-event foundations, private evidence and output access, policies, signed webhook verification, idempotency, queued delivery, operational events, and authenticated print foundations when conformance passes. |
| `Simplify` | Ledger presentation to understandable Term Account activity; integration status to locally evidenced System Health; broad reports to two contextual exports; corrections to append-only adjustment/reversal evidence. |
| `Replace` | Generic Fee Rule precedence/per-unit engine with fixed ordinary Fee Plans plus exact externally calculated authorized individual assessments; silent 20% fallback; Enrollment/StudentProfile-only account ownership; immediate manual confirmation; fragmented finance UI; and legacy host selection. |
| `Remove after dependency migration` | Billing Slip, Official Receipt mapping, prior-debt allocation, generic accommodation/hold behavior, full cashier/refund behavior, the 27-report catalog, provider operations console, and automatic-disposal product. |
| `Quarantine` | Existing tables, fields, models, services, pages, routes, seeders, and tests remain untouched until post-authority task derivation maps every consumer and proves safe migration. |

This is read-only product-planning evidence. It authorizes no account mutation, migration, data deletion, implementation-task derivation, infrastructure change, or deployment.

## 12. Approved Cross-Module Acceptance Coverage

This matrix is the final traceability contract for later journey-complete vertical delivery. Detailed scenario data, states, and browser steps remain in the owning PRD. Every later implementation uses synthetic identities and `example.test` addresses.

| Journey | Required end-to-end evidence | Cross-module pass condition |
|---|---|---|
| Identity and entry | Public closed/open entry, registration, verification, contextual sign-in, multi-role choice, MFA/recovery, disablement, inaccessible route | One credential account; no protected disclosure, silent role priority, or duplicate activation message |
| Application to readiness | Draft/submission, scoped correction, decision, official credentials, duplicate warning, withdrawal, `ReadyApplicantProjection` | Clinic 4 sees the same application/version without copy or early Student creation |
| Academic authority to publication | Curriculum/calendar/class/resource readiness, feasible/infeasible/unknown/technical solver results, candidate review, publication and revision | Only Registrar publication creates the official version; consumers keep its identifier and version |
| First official enrollment | RegistrationCase, proposal, learner confirmation, placement, Clinic 6 requirement, finalization, Student access, COR | Same human/credential/RegistrationCase/TermAccount continuity; five checkpoints revalidated atomically |
| Continuing and advised enrollment | Standard and Individually Advised proposals, reduced/Special Term cases, fixed or authorized individual assessment, prerequisites, shortages, reservations, timetable revision | Clinic 3 owns one revision event/email; no arbitrary course shopping, invented assessment, or silent learner move |
| Special Term through cumulative projection | Approved `TERM-2026-ST`, Regular and Additional published classes, `REG-2026-ST-001`, exact individual assessment, Applied coverage plus verified payment, official enrollment, partial then complete roster release | Same references cross Clinics 3–6; partial release shows **Grades not complete**, final release yields deterministic term/cumulative values; no Summer/tutorial/irregular/scholarship engine |
| Grade release and correction | Designated Faculty roster, returned rows, complete release, `GradesNotComplete`/INC/not-applicable/available average states, no-policy and policy-bound INC, completion/lapse race, correction, RegistrationCase review | Only released results cross clinics; no partial average, invented terminology/deadline, overwritten result, duplicate lapse, or silent registration change |
| Lifecycle and withdrawal | Leave, full withdrawal, return, transfer, shift, conferral and current-term effects | Seats, rosters, schedule, COR and account review remain synchronized with append-only history |
| Completion and TOR | Completion readiness, request-specific Clinic 6 clearance, proposed preview, approved-template issuance, void/replacement/supersession | Proposed layout never appears issued; consumer cannot edit finance or source academic facts |
| Account, coverage, and payment | Fixed Fee Plan and authorized-individual-assessment readiness, Approved Coverage application/supersession/reversal, mixed satisfaction, unavailable source, adjustment/drop review, manual evidence, exact-due checkout, under/mismatch, duplicate and missing/late webhook, reversal | Browser return never posts; coverage is not payment or eligibility processing; one posting and one email; no silent cap, fee fallback, invented refund/penalty, or global hold |
| Outputs, export, health and retention | COR/timetable/unofficial record/TOR/SOA/acknowledgment, two CSVs, purpose audit, degraded services, absent retention policy | No partial official-looking output; unknown external fact is not healthy; disposal remains disabled |
| Shared UI and failure | 1366 desktop, 360/390 mobile, keyboard/screen reader, 200% zoom/reflow, print, empty/loading/stale/inaccessible/concurrency/failure | Owning source, as-of time, responsible role and safe recovery remain visible without color-only meaning |

Across every row, consumers must not edit producer-owned facts; missing or stale authority prevents unsafe action; and no workflow creates a duplicate account, handoff record, official output, payment posting, or email.

## 13. Complete Authority Approval and Next Boundary

There are seven product-definition clinics. A clinic is a planning boundary, not a system feature. The resolved final cross-module contradiction and omission review is an approval gate rather than an eighth clinic.

`Pending` means the clinic may already have accepted inputs, but its module narrative and rewritten PRD are not approved. It never means that the current module text may be implemented.

| Clinic | Authority produced | Purpose | Current position |
|---|---|---|---|
| **0 — Foundation and Shared Rules** | This baseline | Sections 1–3 and 10: product goal, evidence hierarchy, lean boundaries, roles, shared records, readiness behavior, communication, PRD completeness, and UI planning rules | Approved; complete-set review passed |
| **1 — Identity, Access, and Public Entry** | PRD 01 | Identity model, authentication entry, role workspaces, public content, access and inaccessible-record behavior | Approved; complete-set review passed |
| **2 — Application, Admission Decision, and Enrollment Readiness** | PRD 02 | Application intake, versioned requirements, scoped correction, authorized decision, official-credential outcomes, derived readiness, and the shared Clinic 4 projection; official-Student activation remains in Clinic 4 | Approved; complete-set review passed |
| **3 — Academic Setup and Published Timetable** | PRD 03 | Calendar, curricula, courses, offerings, resources, faculty availability, CP-SAT, review, publication, and timetable failure behavior | Approved; complete-set review passed |
| **4 — Current-Term Registration and Official Enrollment** | PRD 04 | Eligibility, proposed registrations, placement, minimum Accounting clearance, Registrar finalization, conditional first Student activation, adjustment, Course Drop, and COR | Approved; complete-set review passed |
| **5 — Teaching and Official Academic Record** | PRD 05 | Official rosters, final grades, release, correction, INC, term weighted average, cumulative GWA, curriculum evaluation, progress, lifecycle, transcript, and completion | Approved; complete-set review passed |
| **6 — Accounts and Operations** | PRD 06 | Fee Plans, continuous Term Accounts, Approved Coverage, payment evidence, bounded enrollment/output-clearance projections, non-tax account outputs, contextual exports, System Health, privacy, audit, recovery, and assurance | Approved; complete-set review passed |

Clinic 0 establishes the universal readiness presentation; each journey PRD owns its sources, validity, owner, consequence, consuming action, and recovery. The calendar ownership, Term Planning Workbench, typed Term Calendar Package, unified Class Offering model, whole-term solver contract, and immutable publication/revision boundary remain fixed Clinic 3 authority. The Clinic 2→4, Clinic 3↔4, Clinic 4↔5, Clinic 6→4, and Clinic 6→5 handoffs remain fixed as summarized in Section 10.1.

The final review found no unresolved product choice. It resolved documentation seams for the shared shell, Student identity continuity, TOR template readiness, output ownership, contextual reporting, payment-webhook recovery, and acceptance traceability. A later proven stronger authority, material feasibility conflict, or explicit user change may reopen only the affected decision.

Current workflow gates are:

- Clinic definition, canonical consolidation, cross-module resolution, and complete-authority approval are complete.
- The next boundary is to derive and separately plan journey-complete vertical implementation tasks under the orchestration protocol.
- Each task must cite its owning PRD, UI authority, architecture boundary, shared handoff, and acceptance row; inspect bounded code/schema evidence; and classify retained work before execution.
- Complete-authority approval alone does not authorize application, schema, seeder, test, dependency, tracker, Linear, Git-history, push, PR, or deployment changes.
- Destructive data/schema work, external effects, and implementation execution retain their separate human and protocol gates.

## 14. Assumptions

- TALA is developed for an ordinarily recognized Philippine college.
- Benchmarks establish competent SIS concepts and lean implementation patterns, not Servitech-specific policy values.
- No additional approved institutional handbook has been supplied.
- The supplied evidence does not establish an approved Servitech INC deadline/lapse policy or one universal variable-fee formula; dependent behavior remains unavailable until its exact authority is recorded.
- Any Special Term remains unavailable until supported by an approved particular calendar/schedule and attributable class-hour/class-day basis; TALA supplies no Summer defaults.
- Current code and database remain salvage evidence and are retained only when the owning clinic proves alignment.
- Clinic 0 must be approved before the shallow implementation inventory. Each owning PRD and UI blueprint must be approved before that module's code or physical schema is changed.
