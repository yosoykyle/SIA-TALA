# TALA System Definition Baseline

## Purpose and Current Status

This document defines TALA's product-wide goal, boundaries, terminology, ownership, policy classes, shared mutation/validation rules, coordinated acceptance institution, and cross-module handoffs. PRDs 01–06 own their complete module behavior.

Current status:

- **Product authority is standalone and ready for separately planned journey-complete vertical slices.**
- The UI Surface Blueprint is the complete product authority for user-visible capabilities, navigation, states, responsiveness, accessibility, and acceptance coverage. No design-tool artifact or fixed screen count governs implementation.
- The definition-first order remains binding: an approved vertical task must cite this complete authority set, reconcile the bounded existing implementation, and receive separate execution authority before implementation begins.
- Legacy PRDs, business evidence, implementation, schema, tests, formulation, benchmarks, and demonstrations are supporting evidence only.

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

**Status:** Standalone-authority review passed.

Its governing content is:

- **Section 1:** Product Goal
- **Section 2:** Evidence hierarchy, authority structure, lean boundaries, and completeness rules
- **Section 3:** Shared roles, cross-role records, configuration, readiness, communication, contextual operational views/exports, and public-content boundaries
- **Section 10:** Requirements that every standalone PRD and UI definition must satisfy

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
| Clinic 5 | Grade-roster action/return/release, INC release/deadline and resolution, deadline amendment, correction, progress/lifecycle, completion action, conferral | Owning roster/result/deadline-amendment/decision/conferral reference; deadline passage alone sends no email; grade values and attachments are excluded |
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

[CHED's HEMIS orientation](https://region1.ched.gov.ph/chedro-spearheads-2024-hemis-orientation/) and [Citizen's Charter CAV process](https://ched.gov.ph/wp-content/uploads/CHED-Updated-CC-2025-1st-edition-033125.pdf) confirm external HEI data-submission and academic-record-verification responsibilities; they do not prescribe a Servitech-specific TALA workflow. CHED and other regulator submissions therefore remain an external institutional responsibility. TALA retains the approved source records from which an authorized office may later prepare an Enrollment List, Promotional Report, List of Graduates, Special Order support, CAV evidence, HEMIS submission, or another prescribed return, but it does not invent a generic Reports destination, speculative demographic fields, an unapproved template, or a regulator-portal workflow. An exact regulatory output may be considered only after Servitech supplies the applicable authority, prescribed format, responsible owner, submission process, privacy basis, and acceptance evidence.

### 3.5 Capability ownership and explicit exclusions

The complete product remains lean because every normally expected SIS capability is either owned by one canonical journey or explicitly left with its responsible external process. This matrix is a traceability index; detailed behavior remains in the owning PRD.

| Capability | Authority and record owner | User journey and UI projection | Failure, correction, or output | Verdict |
|---|---|---|---|---|
| Identity, access, and public entry | PRD 01; account, role assignments, invitation, and verification | Public Gateway, Applicant/Student entry, Staff context selection, Users & Access | Recovery, disablement, inaccessible routes, access correction, security email | Complete |
| Admissions | PRD 02; Admission Cycle, Application, evidence, decision, and credentials | Applicant Home/Application/Requirements; Registrar Admissions | Correction, withdrawal, reopening, superseding decision, acknowledgment | Complete |
| Academic authority and curriculum | PRD 03; Program, Course Revision, Curriculum Version, and authorized external-competency requirements | Catalog & Curricula; read-only Academic Oversight | Import finding, blocked activation, successor authority, external-result source correction | Complete with bounded external evidence |
| Terms and offerings | PRD 03; Term Calendar Package, Term Cohort, and Class Offering | Term Planning; Faculty availability and informational Examination Period projections | Missing authority, incomplete resource, Additional Offering correction, unavailable calendar source | Complete |
| Scheduling | PRD 03; solver request/result, candidate, and Published Timetable Version | Generate & Review, Published Timetable, Faculty/Student projections | Infeasible, Unknown, ModelInvalid, TechnicalFailure, bounded correction, revision | Complete |
| Registration | PRD 04; Registration Case and proposal versions | Learner Enrollment and Registrar Students & Enrollment | Proposal revision, assisted confirmation, expiry, cancellation | Complete |
| Enrollment | PRD 04; placement, reservation, five-checkpoint readiness, and official enrollment | Learner status, Registrar workbench, Accounting clearance | Shortage, stale placement, missing assessment, failed finalization | Complete |
| COR | PRD 04; immutable COR versions and finalization snapshot | Authenticated current/historical COR | Adjustment or Course Drop successor, superseded version, print failure | Complete |
| Grades and averages | PRD 05; roster results, bounded operational metadata, average projections, and externally verified competency results | Grade Rosters, Grades & Completion, Student Academics | Return, INC completion/overdue state/deadline amendment, correction, Grades not complete, superseding external result | Complete |
| Lifecycle and completion | PRD 05; curriculum evaluation, progress, lifecycle, completion, and conferral records | Student Academics, Registrar workbench, Academic Oversight | Pending source, authorized decision, superseding result, authority-gated external requirement | Complete |
| TOR | PRD 05 fixed TALA Standard TOR authority plus PRD 06 request-specific clearance | Registrar preview, issuance, and history | Missing source/certification data or clearance; output failure; void/replacement | Complete within the approved external boundary |
| Accounts and assessments | PRD 06; Fee Plan, Authorized Individual Assessment, and Term Account | Fee Plans, Student Accounts, Student Finance | Unavailable/stale assessment, append-only correction | Complete |
| Coverage and payments | PRD 06; Approved Coverage, evidence, verified posting, and PayMongo attempt | Account detail, Payment Exceptions, learner Finance | Rejection, mismatch, pending webhook, reversal, supersession | Complete |
| Official outputs | Owning PRDs; acknowledgment, timetable, COR, unofficial record, TOR, SOA, Payment Acknowledgment, and two CSVs | Authenticated print/read-only surfaces | No partial artifact, explicit version/state, output-access audit | Complete |
| Privacy, audit, and retention | PRDs 01–06 and Architecture | Private evidence, Governance & Audit, contextual history | Non-disclosing failure; automatic disposal is outside the MVP | Complete |
| Operations and integrations | Architecture and PRD 06 | System Health and locally evidenced projections | Unknown/Not checked by TALA, degraded service, safe continuity | Complete |
| Regulatory submissions | External institutional responsibility; TALA retains source records only | No current Reports destination or speculative submission UI | Reopen only for an exact authority, format, owner, privacy basis, and acceptance process | External boundary recorded |

The supplied Servitech curriculum-evaluation forms separately track TESDA qualification assessment dates and remarks. TALA therefore permits an approved `CurriculumVersion` to identify a bounded external-competency requirement and Clinic 5 to record its externally verified result. TESDA or its accredited assessor remains authoritative for the judgment and certification. TALA does not conduct, schedule, charge for, issue, renew, or verify a TESDA assessment or certificate through an operational integration. A requirement is `TrackedOnly` unless an exact approved Servitech curriculum authority makes it `CompletionRequired`; supplied evaluation sheets alone cannot create a completion block.

The approved term-level `Examination Period` is sufficient for the current Servitech scope. Its dates, calendar authority, package version, owner, and as-of time are projected read-only in Term Planning, Academic Oversight, Faculty My Schedule, Student Home, and Student Academics. Exact class arrangements remain Faculty-owned and use the approved teaching channel. Missing or stale calendar evidence shows **Examination period unavailable — contact Registrar or Faculty** and never creates a date from class meetings. No class-level exam record, examination timetable, facility/proctor/seating/permit workflow, assessment-content feature, email, output, generic event system, or financial examination hold is introduced.

The final inclusion/exclusion register applies a stricter negative-space test. Institutional occurrence alone does not justify digitizing a process. TALA retains a fact only when omitting it would break an accepted journey, lose a required authoritative source, force an unsafe shadow record, or prevent a necessary learner or Staff action. `Minimal retained TALA effect` never transfers ownership of the external process.

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
- **Accepted:** First, Second, and institutionally approved Special Terms use the same Term Calendar Package, Class Offering, timetable, registration, account, and academic-record contracts. Multiple exact Terms may be `Active` concurrently, and their enrollment, adjustment, teaching, grade-entry, account, and output work may overlap. Every action and projection binds to an explicit Term; no implicit system-wide “current term” is authoritative. A Special Term requires its recorded particular schedule and attributable class-hour/class-day basis. There is no separate Summer scheduler, tutorial workflow, universal Special Term unit cap, or learner classification.
- **Accepted:** Faculty provides only hard unavailability or **No additional restrictions**. Rooms have flat suitability facts and hard unavailability. Authorized exact commitments require authority and reason; no soft locks, preferred times, travel matrix, or booking marketplace exist.
- **Accepted:** Whole-term CP-SAT uses complete hard validation and a fixed lexicographic hierarchy: cohort mode switches, cohort idle time, Faculty load imbalance, Faculty idle time, room-seat waste, then stable earlier placement. No editable weights or accuracy percentage exists.
- **Accepted:** Results distinguish `Optimal`, `Feasible`, `Infeasible`, `Unknown`, `ModelInvalid`, and `TechnicalFailure`. Failure evidence is deterministic and source-linked; only failed checks expand.
- **Accepted:** Solver-first candidate correction revalidates the whole candidate and cannot waive hard rules. Publication and every targeted revision create immutable timetable versions; no published meeting is edited in place.
- **Accepted:** Clinic 4 consumes curriculum totals, requisites, equivalencies, published Class Offerings, capacity, and official meeting times and returns bounded `UnmetClassDemandProjection` evidence to Clinic 3. Clinic 3 does not own student eligibility, proposal confirmation, placement, finance, enrollment, activation, or COR. Clinic 5 owns full curriculum evaluation and official academic-history outcomes; Clinic 4 consumes those released facts for current-term eligibility and proposed registrations.

PRD 03 owns the complete Clinic 3 behavior, conceptual records, exclusions, UI contract, technical-evidence boundary, and acceptance scenarios. This section is only the product-wide summary.

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

CHED establishes calendar and instructional-time requirements but does not supply one universal add/drop duration. TALA records the institution's approved dates and authority instead of hard-coding another school's value. Supporting benchmarks include [CHED CMO No. 1, s. 2011](https://ched.gov.ph/wp-content/uploads/2017/10/CMO-No.01-s2011.pdf), [UP Diliman Change of Matriculation](https://our.upd.edu.ph/files/acadinfo/CHANGE%20OF%20MATRICULATION.pdf), [UP Diliman AY 2026–2027 calendar](https://our.upd.edu.ph/files/calendar/regular/ACAD%20CAL%202026-2027.pdf), [UPOU Registrar FAQ](https://registrar.upou.edu.ph/faq-enrollment/), and the scoped [PUP 2026–2027 registration, Summer grade-encoding, and adjustment schedule](https://www.pup.edu.ph/announcements/?go=Cjoh4ZVj%2FLE%3D&v=Schedule-of-AY-2026-2027-First-Semester-Online-Enrollment-and-Encoding-of-Grades-20260727134235133), which demonstrates operational overlap but establishes no Servitech deadline or grade rule.

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

Asia/Manila is the system timezone and is not another editable calendar field. `Draft` is the editable preparation state. `Active` is the version consumed by operational actions. `Closed` preserves the historical term. More than one exact First, Second, or Special Term may be `Active` at the same time; the uniqueness rule is one active package version per Term, not one active Term for the institution. Every window, class, registration, roster, result, account, timetable, COR, and official output carries its exact Term reference. Activation is blocked when required calendar records are incomplete or internally inconsistent; it does not reproduce the Academic Head's institutional approval inside TALA.

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

The Term Calendar Package owns the approved opening and closing dates. Clinic 4 owns the `Enrollment` window's bounded applicability: Ready Applicants, Standard continuing Students, Individually Advised or exception cases, or all otherwise eligible learners. These are fixed code-owned choices, not arbitrary audience rules, programmable effects, or per-window email configuration. Examination Period is informational unless an approved institutional rule gives it a direct class effect. PRD 05 derives each released INC deadline from the original Term's official end date; it is not a configurable Calendar window.

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

## 7. Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop

> **Clinic status — Approved.** The complete Clinic 4 contract lives in [PRD 04 — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop](./04_current_term_registration_official_enrollment.md) and the Clinic 4 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This section retains its cross-module summary.

### Accepted summary

- **Accepted:** The standalone Study Plan module is removed. `EnrollmentSelectionBasis` is `StandardCurriculum` or `IndividuallyAdvised`; the current `RegistrationCase` owns versioned proposed course registrations. Selection basis is independent from academic progress, enrollment effect, identity, and funding.
- **Accepted:** Learners do not freely shop for subjects or classes. Standard proposals derive from the assigned curriculum and published cohort classes; Registrar prepares bounded Individually Advised proposals from curriculum evaluation, released results, approved credits/equivalencies, and actual offerings.
- **Accepted:** Clinic 4 uses only released Clinic 5 results. A failed prerequisite blocks its dependent chain, pending/incomplete results do not satisfy prerequisites, and unrelated eligible curriculum courses remain available. A narrowly scoped `AuthorizedPrerequisiteException` may temporarily permit one named dependent course for an Individually Advised continuing-Student case while the prerequisite result is unreleased; it is append-only authority evidence, never a `P` grade, course satisfaction, credit, GWA effect, permanent learner type, or generic override.
- **Accepted:** The Term Calendar Package owns enrollment-window dates, while Clinic 4 selects only the bounded applicable audience. Clinic 4 returns aggregate unmet-class-demand evidence to Clinic 3; Clinic 3 may generate Draft Class Offerings, but Registrar remains the confirmer and CP-SAT never merges cohorts.
- **Accepted:** Clinic 2 retains ownership of unresolved `PostEnrollmentFollowUp` credential results. Clinic 4 preserves and surfaces their references without turning them into enrollment blockers.
- **Accepted:** One registration engine supports new, continuing, failed/back-course, transferee, returning/reactivated, shifted, old-curriculum, bridging, graduating, and approved Special Term circumstances without storing Regular/Irregular policy status.
- **Accepted:** Stored Registration Case outcomes are `Active`, `OfficiallyEnrolled`, `CancelledByLearner`, `CancelledByRegistrar`, and `NotEnrolled`. Current stage, owner, reason, and next action are derived from five checkpoints: eligibility, confirmed proposal, valid placement, Accounting clearance/coverage, and Registrar finalization.
- **Accepted:** Learner confirmation transactionally validates capacity and creates temporary seat reservations that share the applicable institutional enrollment/payment deadline. Full/stale classes produce Registrar shortage items; there is no ranked waitlist or individual countdown.
- **Accepted:** Official enrollment requires clearance for the amount currently required, not a universal zero balance. Verified payment, Applied Approved Coverage, a mixed basis, or institutionally authorized `NoPaymentRequired` may satisfy that amount. Funding processing and later obligations remain external/separate from academic finalization; service-specific restrictions cannot globally block login or examinations.
- **Accepted:** Every-term finalization atomically revalidates all checkpoints, converts reservations, creates official registrations, activates schedules/rosters, records enrollment, creates immutable COR version 1, and publishes account/academic effects. Only first-ever finalization also creates the minimal Student profile, permanent `SIA-YYYY-NNNN`, and Student access on the existing account.
- **Accepted:** Finalization queues one **Official enrollment and COR ready** message. On first enrollment it also announces Student access; it is not accompanied by a separate activation email. A published timetable revision likewise produces one shared event: Clinic 3 owns the publication trigger, while Clinic 4 supplies affected officially enrolled Students and their updated schedule/COR context.
- **Accepted:** Every initial result release, INC resolution, or correction recalculates curriculum/requisite facts and reviews affected active Registration Cases. Before finalization, an adverse or expired source invalidates the proposal/placement and blocks finalization. After finalization, Registrar review opens without silently adding, removing, replacing, or retaining a course by assumption.
- **Accepted:** Adjustment and Course Drop are separate externally authorized outcomes recorded in the same workbench. During the Adjustment window, a guarded adjustment may apply; after closure, only a specific recorded late-adjustment authority permits the same transaction. Without it, current enrollment/COR remain intact and the action names the owner and next permissible path. Every applied change synchronizes placement, roster, schedule, account-review projection, and a new immutable COR version; fees, penalties, and refunds are never invented.
- **Accepted:** The learner receives one guided status page; Registrar receives one Students & Enrollment workbench; Accounting receives a bounded Enrollment Clearance queue. Native Filament tables, infolists, forms, filters, and Action Groups carry the UI.

## 8. Teaching, Grades, Academic Records, and Completion

> **Clinic status — Approved.** The complete Clinic 5 contract lives in [PRD 05 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion](./05_teaching_grades_academic_records_completion.md) and the Clinic 5 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This baseline retains only the cross-module summary.

### Accepted summary

- **Accepted:** TALA records one controlled final result per official course registration. Faculty calculates period grades, raw scores, attendance, and formulas outside TALA. The accepted final vocabulary is `1.00` through `3.00` in quarter-point steps, `4.00`, `5.00`, or `INC`; `1.00–4.00` satisfies the course, `5.00` does not, and `P` is not an official mark.
- **Accepted:** One roster exists per official `ClassOffering`, including externally arranged courses without recurring timetable meetings. Only officially enrolled learners appear. One designated Faculty submits the complete roster; Registrar releases it as a whole or returns specified rows with one consolidated explanation.
- **Accepted:** Roster state is `Draft`, `Submitted`, `Returned`, or `Released`. Released grades are immutable events. Grade entry uses the Term Calendar's definite window and due date; an overdue submission requires recorded late authority.
- **Accepted:** `TermWeightedAverageProjection` is the full-precision, unit-weighted result for one term; `CumulativeGwaProjection` uses all included attempts and units through the selected grade-complete term and is not an arithmetic mean of term values. The neutral term label is **Term weighted average**; **Term GPA** or another label requires recorded Servitech authority and an effective term. All attempts count, while PE and NSTP—including CWTS, LTS, and ROTC equivalents—are excluded under the client-confirmed Servitech rule. `INC`, dropped or withdrawn results, and nonnumeric approved credit are excluded. Values display to two decimals in academic views and remain absent from the standard TOR.
- **Accepted:** `AcademicAverageReadiness` is `GradesNotComplete`, `IncompleteResultPending`, `Available`, or `NotApplicable`. A partially released term shows **Grades not complete** and no partial term/new cumulative value; a grade-complete zero-denominator term shows **Not applicable — no included academic units** rather than zero. An unresolved included `INC` withholds the current term and current cumulative final value and does not satisfy prerequisites. Completion or later correction preserves history and recalculates every affected projection; deadline passage changes only the derived completion state to `CompletionOverdue` and never converts the grade.
- **Accepted:** Every initial release, INC resolution, and authorized grade correction recalculates the affected term weighted average, cumulative GWA, curriculum evaluation, `AcademicEnrollmentEffect`, completion readiness, and active Clinic 4 impact. Corrections append a superseding result without a hard technical cutoff; earlier decisions and issued transcript snapshots remain historical.
- **Accepted:** Full curriculum evaluation is deterministic from effective curricula, every released attempt, approved credits/equivalencies, current official enrollment, and effective shift, bridging, deficiency, or old-curriculum mappings. TALA has no what-if audit, speculative graduation date, generic substitution builder, automatic equivalency decision, or double counting.
- **Accepted:** Curriculum and released-result facts determine course satisfaction, prerequisites, remaining requirements, retake need, and whether the standard curriculum sequence remains usable. `AcademicEnrollmentEffect` is `Allowed`, `AdvisingRequired`, `Blocked`, or `PendingDecision`. Failures, deficiencies, shifts, bridging, or other nonstandard placement produce `AdvisingRequired`; `Blocked` requires a recorded authorized institutional decision or incompatible lifecycle state; `PendingDecision` requires an actual opened review or unresolved authoritative source. No failed-unit percentage automatically creates Warning, Probation, load reduction, dismissal, or institutional ineligibility.
- **Accepted:** Append-only lifecycle events derive `Active`, `OnLeave`, `Withdrawn`, `TransferredOut`, or `Completed`. Course Drop remains Clinic 4. Lifecycle changes preserve academic history, do not infer refunds, do not disable historical portal access, and never create a registration or seat by themselves.
- **Accepted:** Completion readiness is `NotEligible`, `EligibleToApply`, `AwaitingResultsOrClearance`, `ReadyForConferral`, or `Conferred`. Applying records intent only. Conferral requires satisfied curriculum, no unresolved result, an application, source-owned clearances, and recorded external authority.
- **Accepted:** Student Academics presents released grades, term weighted average/cumulative GWA or its explicit readiness state, curriculum evaluation, confirmed progress, units, completion readiness, and history. Students may print an unofficial record only.
- **Accepted:** TALA owns the fixed versioned **TALA Standard TOR — Servitech v1** contract. Registrar may preview and issue it after academic completion, identity verification, request-specific Clinic 6 clearance, required signatory data, and output-readiness validation. Issued snapshots retain void/replacement and supersession history. Physical signature, seal, claiming, delivery, courier, CAV, diploma, and ceremony remain external and do not prevent TALA from recording system issuance. A later Servitech format becomes a successor template version; no transcript-template builder or generic document engine exists.
- **Accepted:** Clinic 5 exposes released `OfficialCourseResultProjection`, `AcademicEnrollmentEffect`, curriculum-evaluation, and lifecycle facts to Clinic 4. Draft or submitted grades never change registration. A later correction sends an affected active Registration Case to Registrar review rather than silently changing subjects.
- **Accepted:** Grade-release and other approved academic emails contain no grade values or attachments. Mail failure never rolls back an academic transaction.

## 9. Accounts, Official Outputs, Operations, and Assurance

> **Clinic status — Approved.** Complete journey, state, data, output, operations, and UI authority lives in [PRD 06 — Accounts, Official Outputs, Operations, and Assurance](./06_accounts_official_outputs_operations_assurance.md) and the Clinic 6 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This baseline retains only the cross-module boundary.

### Accepted summary

- TALA provides one narrow fixed Program-and-Term Fee Plan for ordinary cases and one continuous same-human-subject/RegistrationCase Term Account companion. An Assessment version uses exactly `PublishedFeePlan` or, for an approved selection-specific exception, `AuthorizedIndividualAssessment`. The latter records Accounting's exact externally calculated lines and obligations without executing a formula. `Person` is only a documentation label for identity continuity, not a new master record. TALA does not replace Accounting's bookkeeping, cashiering, collections, general ledger, refund, or BIR-invoicing procedures.
- `ApprovedCoverage` is an append-only externally approved Term Account effect categorized as scholarship, sponsorship, government subsidy, or other authorized funding. It is `Applied`, `Superseded`, or `Reversed`, targets an exact Assessment/obligation, and never becomes a funding application, eligibility, renewal, disbursement, accommodation, allocation, refund, or cash-movement workflow.
- Clinic 6 publishes `EnrollmentPaymentRequirementProjection` to Clinic 4. It states the assessment basis and exact registration/change source, current enrollment obligation, separate verified-payment and Approved-Coverage amounts, remaining required amount, state, `SatisfactionBasis = VerifiedPayment | ApprovedCoverage | Mixed | NoPaymentRequired | None`, authority/source/as-of time, later-obligation indicator, and authorized account link. `Cleared` never means a lifetime zero balance. A missing, stale, or invalid assessment authority produces `Unavailable`; a valid assessment whose current obligation is not satisfied produces `ActionNeeded`. Neither state invents zero, a silent cap, or a percentage fallback.
- Clinic 6 publishes request-specific `OfficialOutputPaymentClearance = Cleared | NotRequired | ActionNeeded` to Clinic 5. It never creates a global finance hold.
- A later missed obligation never reverses official enrollment or blocks login, classes, examinations, or released academic records. COR remains Clinic 4's immutable enrollment output with an assessment-at-finalization snapshot; Account Statement/SOA remains Clinic 6's current non-tax account output.
- Manual evidence stays unverified until Accounting checks the actual external bank, wallet, cash, or institutional source. Exact valid signed PayMongo evidence posts idempotently; browser returns do not prove payment, and mismatches enter an exception queue.
- Clinic 6 generates only a non-tax Account Statement/SOA, non-tax Payment Acknowledgment, contextual Account Status CSV, and contextual Verified Payments CSV. Accounting owns any required BIR invoice or external tax document.
- Accounting navigation is **Fee Plans** and one tabbed **Student Accounts** workbench. Student Finance is summary-first and becomes read-only for alumni.
- System Health shows only locally recorded evidence and explicitly labels provider or physical-backup facts `Not checked by TALA`. Governance & Audit is read-only. Automatic retention disposal is not provided in the MVP; lawful retention schedules, privacy requests, legal holds, and secure disposal remain external institutional responsibilities.
- The selected MVP infrastructure direction is a self-managed Hostinger KVM 1 VPS with independent encrypted off-server backups, additional encrypted ORICO offline copies, six-hour RPO, and eight-hour RTO. Provider facts and recovery performance require external operational evidence.
- Clinic 6 owns only the idempotent **Verified payment posted** email.
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
- Page inventory and information hierarchy
- Fields, columns, filters, sorting, actions, and evidence
- Empty, loading, error, and inaccessible states
- Desktop, mobile, print, accessibility, and keyboard behavior
- Explicit exclusions and external/manual decisions
- Realistic demonstration data and browser acceptance script

Every primary user-visible capability receives a low-fidelity wireframe or an explicitly governed shared pattern. The Canonical UI Surface Coverage Inventory makes every primary destination reachable and gives dedicated acceptance coverage to seven cross-role journeys:

1. Public entry, identity, verification, role selection, and access failure
2. Application, decision, official credentials, and enrollment readiness
3. Academic authority, timetable readiness/failure, publication, and revision
4. Registration, assessment/coverage, official enrollment, Student activation, and COR
5. Grade submission/release, INC/correction, completion, and TOR
6. Fee Plan/assessment, payment evidence or PayMongo, account outputs, and reversal
7. System Health, Governance & Audit, output access, and the explicit no-automatic-disposal boundary

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
| Clinic 2 | Admission Cycle, Application and immutable snapshots, evidence/correction/decision history, official-credential results, one `ReadyApplicantProjection` |
| Clinic 3 | Program/Course/Curriculum authority, Term Calendar Package, cohorts and Class Offerings, resource declarations, generation run/candidate history, published timetable versions, derived readiness/availability/demand/Examination Period projections |
| Clinic 4 | Registration Case, proposal/confirmation/reservation history, narrowly authorized prerequisite exceptions, Official Enrollment and registrations, Student identity events, adjustments/Drops, COR versions, and source-owned readiness projections |
| Clinic 5 | Roster/result history, INC deadline amendments, external competency and lifecycle results, derived averages/evaluation/enrollment/completion projections, Graduation/Conferral records, and versioned transcript output records |
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
| TOR | Clinic 5 transcript snapshot and `TALA Standard TOR — Servitech v1` | Registrar-controlled official output for academically completed learners; physical signing, sealing, delivery, and CAV remain external | Void/replacement/supersession is append-only; failure produces no issuance event or official-looking artifact |
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

### 11.4 Shared Authority-Control Annex

This annex supplies the normalized controls used by PRDs 01–06. An owning PRD may narrow a rule but may not silently weaken it. The records named here are conceptual product authority, not database, API, class, or migration design.

#### Matrix 1 — Capability and authoritative ownership

| Capability | Authority owner | Authoritative source | Consumers may | Consumers may not |
|---|---|---|---|---|
| Identity and access | PRD 01; System Administrator for bounded Staff access | Credential account, verified contact, fixed role assignment, access-change evidence | Read authorized identity and context projections | Edit another clinic's domain record or merge people silently |
| Admissions | PRD 02; Registrar | Admission Cycle, Application/versioned evidence, decision, credential result | Consume `ReadyApplicantProjection` | Create Student identity, enrollment, placement, or assessment |
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
| Reference/code | Trimmed 1–64 characters; letters, numbers, spaces, hyphen, underscore, slash, period, and colon only; unique within owning scope |
| Title/label | Title maximum 160 characters; short label maximum 120 |
| Administrative reason | Required for consequential Staff actions; 10–1,000 characters |
| Safe learner explanation | 1–500 characters; no internal notes, secrets, private evidence, or unsupported accusation |
| LRN | Optional 12 digits; a match to another credential identity blocks submission and routes to non-disclosing Registrar review |
| Telephone | Optional; normalized to 8–15 international digits while accepting Philippine-friendly input |
| Money | PHP only for MVP, two decimal places, nonnegative; payment and coverage postings must be positive |
| Units | Positive, up to two decimal places; curriculum/authority reconciliation controls validity, not a universal cap |
| Date/time | Asia/Manila; explicit inclusive/exclusive semantics; start cannot follow end; effective dates never silently rewrite prior authority |
| Public URL | HTTPS only, maximum 2,048 characters; link label maximum 80 |
| Private evidence file | Exactly one PDF, JPEG, or PNG per requirement/evidence version; maximum 10 MiB; actual MIME/signature validation, private storage, generated storage name, checksum, and access audit |

Every material mutation revalidates actor, authorization, current state, effective version, dependencies, and source server-side. A stale or conflicting submission creates no partial mutation, identifies what changed, refreshes authoritative facts, and preserves safe uncommitted text where possible. Academic, financial, security, publication, capacity, finalization, and issuance edits are never silently merged.

#### Matrix 7 — Critical-action confirmation and audit

| Action class | Confirmation | Success evidence |
|---|---|---|
| Routine save, filter, search, preview, or calculation | None | Normal request evidence only |
| Security/access, identity, academic release/correction, publication, enrollment, financial posting/correction, lifecycle/conferral, or official output | Named `alertdialog` | Actor, role, record/version, authority/reason, before/after state, time, idempotency result, affected roles/projections/emails/outputs |

The dialog shows the exact record/version, actor/authority, resulting state, downstream effects, reversibility or successor requirement, and required reason/authority fields. The action label names the consequence—such as **Publish timetable**, **Finalize enrollment**, **Release roster**, or **Record reversal**—and never uses only **Yes**. Cancellation or failed confirmation causes no institutional mutation.

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

Every included policy-gated capability has a complete workflow rather than an `Unavailable` placeholder. `INC` uses the bounded one-year nonautomatic completion rule in PRD 05; TALA owns a fixed Servitech-branded TOR template; the narrowly authorized prerequisite exception is completed across PRDs 04–05; and automatic retention disposal is intentionally outside the MVP. Legally restricted and external actions remain explicitly owned outside TALA without making the product definition incomplete.

## 12. Approved Cross-Module Acceptance Coverage

This matrix is the final traceability contract for later journey-complete vertical delivery. Detailed scenario data, states, and browser steps remain in the owning PRD. Every later implementation uses synthetic identities and `example.test` addresses.

| Journey | Required end-to-end evidence | Cross-module pass condition |
|---|---|---|
| Identity and entry | Public closed/open entry, registration, verification, contextual sign-in, multi-role choice, MFA/recovery, disablement, inaccessible route | One credential account; no protected disclosure, silent role priority, or duplicate activation message |
| Application to readiness | Draft/submission, scoped correction, decision, official credentials, duplicate warning, withdrawal, `ReadyApplicantProjection` | Clinic 4 sees the same application/version without copy or early Student creation |
| Concurrent term operation | First-, Second-, and Special-Term packages with overlapping enrollment, adjustment, teaching, grade-entry, account, timetable, COR, and output work | Every record/action carries its exact Term; one Term's window or closure never silently controls another; no Summer subsystem or implicit current term appears |
| Academic authority to publication | Curriculum/calendar/class/resource readiness, feasible/infeasible/unknown/technical solver results, candidate review, publication and revision | Only Registrar publication creates the official version; consumers keep its identifier and version |
| First official enrollment | RegistrationCase, proposal, learner confirmation, placement, Clinic 6 requirement, finalization, Student access, COR | Same human/credential/RegistrationCase/TermAccount continuity; five checkpoints revalidated atomically |
| Continuing and advised enrollment | Standard and Individually Advised proposals, reduced/Special Term cases, fixed or authorized individual assessment, prerequisites, shortages, reservations, timetable revision | Clinic 3 owns one revision event/email; no arbitrary course shopping, invented assessment, or silent learner move |
| Special Term through cumulative projection | Approved `TERM-2026-ST`, Regular and Additional published classes, `REG-2026-ST-001`, exact individual assessment, Applied coverage plus verified payment, official enrollment, partial then complete roster release | Same references cross Clinics 3–6; partial release shows **Grades not complete**, final release yields deterministic term/cumulative values; no Summer/tutorial/irregular/scholarship engine |
| Grade release and correction | Designated Faculty roster, returned rows, complete release, `GradesNotComplete`/INC/not-applicable/available average states, completion deadline/amendment/overdue/result race, correction, RegistrationCase review | Only released results cross clinics; no partial average, automatic grade conversion, overwritten result, or silent registration change |
| Pending prerequisite to next-term outcome | Individually Advised case, exact authorized exception, learner confirmation, initial release/INC/correction, pre/post-finalization review, open Adjustment, closed Adjustment with/without late authority | No released `P` or fabricated satisfaction; unrelated courses remain usable; capacity/finance/roster/schedule/COR effects occur only through an authorized guarded transaction |
| Lifecycle and withdrawal | Leave, full withdrawal, return, transfer, shift, conferral and current-term effects | Seats, rosters, schedule, COR and account review remain synchronized with append-only history |
| Completion and TOR | Completion readiness, request-specific Clinic 6 clearance, TALA Standard TOR preview/issuance, void/replacement/supersession | Only Registrar confirmation creates issuance; physical certification remains external; consumers cannot edit finance or source academic facts |
| Account, coverage, and payment | Fixed Fee Plan and authorized-individual-assessment readiness, Approved Coverage application/supersession/reversal, mixed satisfaction, unavailable source, adjustment/drop review, manual evidence, exact-due checkout, under/mismatch, duplicate and missing/late webhook, reversal | Browser return never posts; coverage is not payment or eligibility processing; one posting and one email; no silent cap, fee fallback, invented refund/penalty, or global hold |
| Outputs, export, health and retention | COR/timetable/unofficial record/TOR/SOA/acknowledgment, two CSVs, purpose audit, degraded services, explicit no-automatic-disposal boundary | No partial official-looking output; unknown external fact is not healthy; no hidden retention engine or compliance claim |
| Shared UI and failure | 1366 desktop, 360/390 mobile, keyboard/screen reader, 200% zoom/reflow, print, empty/loading/stale/inaccessible/concurrency/failure | Owning source, as-of time, responsible role and safe recovery remain visible without color-only meaning |

Across every row, consumers must not edit producer-owned facts; missing or stale authority prevents unsafe action; and no workflow creates a duplicate account, handoff record, official output, payment posting, or email.

## 13. Standalone Authority Status and Next Boundary

There are seven product-definition clinics. A clinic is a planning boundary, not a system feature. The resolved final cross-module contradiction and omission review is an approval gate rather than an eighth clinic.

| Clinic | Authority produced | Purpose | Current position |
|---|---|---|---|
| **0 — Foundation and Shared Rules** | This baseline | Sections 1–3, 10, and 11.1–11.4: product goal, evidence hierarchy, lean boundaries, roles, shared vocabulary, coordinated acceptance data, readiness, communication, PRD completeness, UI planning, and authority controls | Approved; standalone-authority review passed |
| **1 — Identity, Access, and Public Entry** | PRD 01 | Identity model, authentication entry, role workspaces, public content, access and inaccessible-record behavior | Standalone and ready for vertical-slice planning |
| **2 — Application, Admission Decision, and Enrollment Readiness** | PRD 02 | Application intake, versioned requirements, scoped correction, authorized decision, official-credential outcomes, derived readiness, and the shared Clinic 4 projection; official-Student activation remains in Clinic 4 | Standalone and ready for vertical-slice planning |
| **3 — Academic Setup and Published Timetable** | PRD 03 | Calendar and informational Examination Period, curricula and bounded external-competency requirements, courses, offerings, resources, faculty availability, CP-SAT, review, publication, and timetable failure behavior | Standalone and ready for vertical-slice planning |
| **4 — Current-Term Registration and Official Enrollment** | PRD 04 | Eligibility, proposed registrations, placement, minimum Accounting clearance, Registrar finalization, conditional first Student activation, adjustment, Course Drop, and COR | Standalone and ready for vertical-slice planning |
| **5 — Teaching and Official Academic Record** | PRD 05 | Official rosters, final grades, release, correction, deadline-bound nonautomatic INC, term weighted average, cumulative GWA, factual curriculum position, lifecycle, standard TOR, and completion | Standalone and ready for vertical-slice planning |
| **6 — Accounts and Operations** | PRD 06 | Fee Plans, continuous Term Accounts, Approved Coverage, payment evidence, bounded enrollment/output-clearance projections, non-tax account outputs, contextual exports, System Health, privacy, audit, recovery, and assurance | Standalone and ready for vertical-slice planning |

Clinic 0 establishes the universal readiness presentation; each journey PRD owns its sources, validity, owner, consequence, consuming action, and recovery. The calendar ownership, Term Planning Workbench, typed Term Calendar Package, unified Class Offering model, whole-term solver contract, and immutable publication/revision boundary remain fixed Clinic 3 authority. The Clinic 2→4, Clinic 3↔4, Clinic 4↔5, Clinic 6→4, and Clinic 6→5 handoffs remain fixed as summarized in Section 10.1.

No unresolved product choice is deferred to implementation. Student identity continuity, INC completion, TOR generation, output ownership, contextual reporting, payment-webhook recovery, regulatory-submission boundaries, Examination Period visibility, externally verified competency-result ownership, requested-record and quality-assurance boundaries, role entry, shared shell behavior, design foundations, component coverage, UI traceability, mutation governance, validation, concurrency, confirmation, retry, and deletion behavior are defined by this baseline and their owning PRDs. A later proven stronger authority, material feasibility conflict, or explicit user change may reopen only the affected decision.

Current workflow gates are:

- Canonical product definition, cross-module resolution, UI coverage, negative-space review, authority hardening, and standalone-authority refinement are complete.
- The Canonical UI Surface Coverage Inventory governs required user-visible behavior without prescribing a design artifact, fixed page count, route count, or component count.
- The next boundary is separately planning the first journey-complete vertical implementation slice. Each slice must cite its owning PRD, UI authority, architecture boundary, shared handoff, and acceptance row; inspect bounded code/schema/test evidence; and classify retained work before execution.
- Complete-authority approval alone does not authorize application, schema, seeder, test, dependency, tracker, Linear, Git-history, push, PR, or deployment changes.
- Destructive data/schema work, external effects, and implementation execution retain their separate human and protocol gates.

## 14. Assumptions

- TALA is developed for an ordinarily recognized Philippine college.
- Approved Servitech evidence is the first local source but does not cap necessary SIS coverage when evidence is unavailable or confidentiality-restricted.
- Qualified Philippine comparisons and mature-system benchmarks establish scoped concepts and lean implementation patterns, not Servitech-specific policy values.
- The supplied 2019 handbook concerns TESDA operations and is contextual evidence only; it does not establish Servitech college policy.
- The supplied evidence does not establish a Servitech INC deadline or one universal variable-fee formula. TALA therefore uses the bounded one-year nonautomatic INC completion rule, while ordinary fixed Fee Plans and bounded exact individual assessments resolve the fee-source behavior.
- Any Special Term remains unavailable until supported by an approved particular calendar/schedule and attributable class-hour/class-day basis; TALA supplies no Summer defaults.
- Current code and database remain implementation evidence and are retained only when the owning vertical slice proves alignment.
- Each owning PRD and the UI Blueprint must remain approved before that module's code or physical schema is changed.
