# TALA System Operations and Defense Guide

**Document status:** TAL-96D5C2 local full regression, security, and integration-readiness gate independently verified and closed locally; TAL-96D5D planning remains, dated 2026-07-27
**Purpose:** One consolidated guide for operating, auditing, demonstrating, and defending the TALA production-level MVP. Later TAL-96D slices will expand this same file rather than create competing manuals.

## 1. Scope and Evidence Language

This guide treats the application as an integrated school information system. It begins with the prerequisites that make later workflows valid, follows data across user roles, and separates what has been proved automatically from what still requires browser or stakeholder acceptance.

Evidence is classified as follows:

| Evidence level | Meaning |
|---|---|
| Automated | A repeatable PHPUnit or command check proves the stated database, authorization, route, or business-rule behavior. |
| Browser | The rendered interface was inspected at a named viewport using a representative account. This proves visible behavior for that state, not every possible state. |
| Stakeholder | The client, project adviser, or authorized school representative confirms that terminology, policy, or operational behavior matches institutional practice. |
| Routed | A concern is real or plausible but belongs to a later approved TAL-96D correction slice. Routed does not mean failed. |

An automated pass is not, by itself, final user acceptance. A browser pass does not replace business-policy confirmation. TAL-96D5B used broad programmatic evidence first and limited the user's work to one bounded final smoke review. TAL-96D5C2 has completed and independently verified its local regression gate; formal presentation readiness remains TAL-97.

## 2. System Starting Point and Operating Order

TALA is not one strictly linear process. Admissions may collect applications while staff prepare the next term. However, every action has prerequisites, and enrollment cannot be completed by skipping schedule publication, placement, assessment, or payment gates.

| Stage | Required source records | Primary owner | Result that enables the next stage | Required failure behavior |
|---|---|---|---|---|
| 1. Runtime and access | Correct environment, database, roles, permissions, verified active accounts | System Super Admin | Authorized workspaces | Deny the action or stop the command when the target environment, account state, or permission is wrong. |
| 2. Academic period | Academic year, term, process windows, class days, and operating hours | Registrar / Academic Head | An active planning context | Explain which term or window is missing or closed. |
| 3. Academic structure | Programs, course specifications, approved curricula, curriculum entries, and prerequisite rules | Registrar / Academic Head | The institution knows what may be offered and who may take it | Reject incomplete or unapproved academic records. |
| 4. Delivery preparation | Term offerings, sections, delivery groups, rooms, qualified faculty, availability, and expected cohort counts | Registrar / Academic Head | Validated scheduling demands | Report readiness findings before the solver is called. |
| 5. Master scheduling | Ready demands and an available solver | Registrar / Academic Head | Reviewed and published section meetings | Keep candidate results distinct from the official published schedule; do not enroll against an unpublished candidate. |
| 6. Admissions and student master | Open admission process, accepted applicant decision, and approved handover | Applicant / Registrar | An active Student Profile for a new student | Keep an applicant in the Admissions domain until handover succeeds; do not treat an application as enrollment. |
| 7. Enrollment placement | Student Profile, target term, published compatible sections, academic progression facts, remaining seats, and required exceptions | Registrar, with student input where allowed | Course selections, section bindings, and seat reservations | Name prerequisite, unit, conflict, lifecycle, document, behavior, discipline, or capacity blockers. |
| 8. Assessment and payment | Confirmed placement, active fee rules, and assessment | Accounting / Student | Verified ledger posting or an approved accommodation | A checkout page alone must not clear the finance gate. |
| 9. Official enrollment and outputs | All enrollment gates passed | Registrar | Official enrollment, COR, student schedule, roster participation, and later grades/lifecycle outputs | Show a clear pending or blocked state and responsible office when any gate remains open. |

### 2.1 Regular and irregular timing

A regular cohort timetable is produced before publication. A continuing irregular student is already a Student Profile; the student does not return to Applicant status and does not require the solver to rerun merely because that student enrolls. After publication, the irregular student selects compatible published subjects or sections, and the Registrar confirms the resulting placement and seat reservation.

The waiting period for irregular selection is therefore the interval between the institution's enrollment opening and the publication of compatible sections. TALA does not invent a fixed waiting time. The institution controls this through its term calendar and publication process. The implementation now requires an active institution-scoped enrollment window, an existing Student Profile and term Enrollment, and compatible active meetings from a published schedule before a proposal or complete placement can proceed.

## 3. Reproducible Client-Aligned Acceptance Baseline

The baseline uses the client's reported current population as its scale anchor. It is not an institutional maximum.

| Program | First year | Second year | Total |
|---|---:|---:|---:|
| Diploma in Business Management Technology (DBM) | 10 | 2 | 12 |
| Diploma in Information Technology (DIT) | 10 | 3 | 13 |
| Diploma in Tourism and Hospitality Management Services (DTHM) | 15 | 7 | 22 |
| **Total** | **35** | **12** | **47** |

The six reported program/year groups are represented as six regular cohort identifiers. Their approved curricula produce 54 course delivery demands. The fixture also contains an active second-semester term, a bounded synthetic Admissions window for applicant acceptance, rooms, faculty qualifications, unrestricted default faculty availability, load data, fee rules, and verified test accounts. Production institutions configure their own Admissions dates. Names, personnel, rooms, qualifications, availability assumptions, and the acceptance-window dates are synthetic. They provide complete relational inputs for acceptance testing and do not claim to reproduce the client's real personnel, calendar, or published timetable.

The Program records are the approved three-year `DBM`, `DIT`, and `DTHM` structures. The current population evidence contains only first- and second-year students; that population fact does not shorten the Programs. TAL-96D2C adds separate, explicitly synthetic MIDDLE and MAX workload scenarios when a three-year or historical-scale input set is required. Those scenarios do not rewrite the client-reported MIN facts.

There is no universal 100-student limit in the accepted product rule. TALA controls occupancy through configurable section capacity, physical-room capacity for face-to-face meetings, published offerings, and Registrar-confirmed seat reservations. The current population of 47 is evidence of client scale, not a coded maximum.

### 3.1 State dimensions and scenario anchors

Student state is not one label. The fixture keeps these dimensions separate:

| Dimension | D1 starting state | Why it is separate |
|---|---|---|
| Primary lifecycle | All 47 Student Profiles are `Active` | Lifecycle describes whether a person remains an active student master record. |
| Academic standing | Deliberately distributed across the nine PRD values | Standing describes curriculum progression, not payment or enrollment completion. |
| Term enrollment | No enrollment records yet | A student may exist before beginning a particular term enrollment. |
| Financial state | No assessments, ledger entries, or payments yet | Financial standing is derived from term charges, ledger effects, payments, accommodations, and holds; it is not a permanent student label. |
| Modality | Stored on Course Specification allowances, term offerings, delivery groups, and published meetings | Online or face-to-face delivery describes a class offering, not an immutable type of student. |

Ten deterministic records anchor later adverse and cross-role journeys. All other students begin as `Regular`.

| Student number | Academic standing | Later journey represented |
|---|---|---|
| `DBM-1A-001` | Regular | Standard cohort progression and the representative Student Hub login |
| `DBM-1A-002` | Irregular | First-year individual subject-selection path |
| `DBM-2A-001` | Irregular | Continuing irregular selection path |
| `DIT-1A-001` | Probationary | Probation explanation and staff review |
| `DIT-1A-002` | Deficient | Academic-deficiency guidance and hold interaction |
| `DIT-2A-001` | Blocked by Prerequisite | Prerequisite failure and scoped exception handling |
| `DTHM-1A-001` | Must Repeat Year Level | Repeat-year progression review |
| `DTHM-1A-002` | Completion Candidate | Completion review |
| `DTHM-2A-001` | Graduation Candidate | Graduation eligibility review |
| `DTHM-2A-002` | Not Yet Evaluated | Missing progression-baseline handling |

The resulting academic-standing distribution is 38 Regular, 2 Irregular, and one record for each of the remaining seven values. These anchors are starting personas, not fabricated proof that their enrollment, financial, schedule, hold, or graduation journeys have already passed.

### 3.2 Intentional downstream boundary

The D1 fixture intentionally starts with no generated schedule, published meeting, enrollment, assessment, ledger posting, payment, payment attempt, or webhook delivery. Later slices must create those records through the same actions a real user journey invokes. This prevents the fixture from hiding an implementation defect behind pre-completed data.

### 3.3 Required environment proof

Run these commands in the project root before any acceptance-data command:

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'

php artisan env --no-interaction
php artisan config:show database.default --no-interaction
php artisan config:show database.connections.mysql.database --no-interaction
```

The required results are `testing`, `mysql`, and `test_tala_db`.

### 3.4 Read-only inspection

```powershell
php artisan acceptance:seed-client-baseline --check --no-interaction
```

Possible results:

| Baseline state | Readiness | Meaning and operator action |
|---|---|---|
| `complete` | `PASS` | The exact baseline is present and no write occurred. Continue with the audit. |
| `empty` | `NOT_READY` | No operational baseline is present. Run the guarded seed command only if a new baseline is intended. |
| `conflict` | `NOT_READY` | Data is partial, edited, or contains downstream evidence. Stop. Do not truncate or overwrite it automatically. |

The inspection also reports the database name; student, cohort, demand, and ready-demand counts; every academic-standing count; matched scenario anchors; and counts for each downstream record type. It is read-only. `scenario_anchors=10/10` and `downstream_state=EMPTY` prove the expected starting shape, not final end-to-end acceptance.

### 3.5 Guarded creation

Use this only after the inspection reports `empty`:

```powershell
php artisan acceptance:seed-client-baseline --no-interaction
```

The command creates the baseline transactionally and then performs a live scheduling-readiness check. A complete rerun is a no-op. A partial or edited database fails closed without writing. Rebuilding `test_tala_db` requires a separately approved snapshot-and-rebuild human gate.

### 3.6 Representative test-only accounts

All accounts below are email-verified and use the test-only password `password`.

| Workspace | Email | Intended panel |
|---|---|---|
| Applicant | `applicant.demo@example.test` | Applicant Workspace |
| Student | `student.demo@example.test` | Student Hub |
| Registrar | `registrar.demo@example.test` | Staff Workspace |
| Accounting | `accounting.demo@example.test` | Staff Workspace |
| Faculty | `faculty.demo@example.test` | Staff Workspace |
| Academic Head | `academic-head.demo@example.test` | Staff Workspace |
| System Super Admin | `system-admin.demo@example.test` | Staff Workspace |

These credentials must never be treated as production accounts or copied into production configuration.

## 4. Cross-Role Journey and Readiness Matrix

The matrix is the controlling audit map for TAL-96D. `Source record` identifies the authoritative data that should explain the screen. `Editable/read-only` distinguishes an operational input from an output or evidence view.

| ID | Role / office owner | Goal | Prerequisites | Entry surface | Source record | Editable / read-only | Expected success and failure behavior | Automated evidence | Browser / manual evidence | Current verdict | Remediation owner |
|---|---|---|---|---|---|---|---|---|---|---|---|
| D1-01 | Public user | Choose the correct workspace | None | `/` | Public configuration and FAQ | Read-only | Clear applicant, student, and staff routes; unavailable actions must not be implied | D4D public-entry and cross-role presentation tests | D4D-PUB-01 through D4D-PUB-04 passed, including responsive public entry, keyboard access, FAQ publication/order, and the final Access Guide alignment correction | PASS | TAL-96D5B acceptance |
| D2-ID-01 | Applicant, student, staff | Authenticate only into the assigned panel | Verified, active account and canonical role | Panel login pages | User, role, permission, student profile | Editable only through approved account flows | Valid user reaches intended panel; wrong, unverified, inactive, or archived access is denied or routed to verification | Panel, authentication-eligibility, email-verification, D2A service-authorization, and D4D cross-role tests | D2A-M01 and D4D-XR-01 passed; remaining role cases reconciled programmatically | Programmatic pass; wrong-workspace visual sample is in Section 9.6 | TAL-96D5B acceptance |
| D2-AD-01 | Applicant | Start, save, submit, correct, or withdraw an application when allowed | Active term, active program, effective requirement policy, verified applicant account | Applicant Dashboard, My Application, and Requirements | Applicant intake, checklist item, and document evidence | Draft is editable; each applicable digital requirement has its own private upload; rejected digital evidence is replaceable; withdrawal is restricted to an unreviewed draft or pending intake | Required fields, declaration, file constraints, duplicate checks, status, correction reason, and blocked actions remain explicit | Wizard, partial-draft, policy-driven multi-upload, mixed-evidence, declaration, active-scope, duplicate, invalid-replacement, correction, and withdrawal tests | D2A cases reconciled programmatically after bounded remediation | Programmatic pass; Applicant visual sample is in Section 9.6 | TAL-96D5B acceptance |
| D2-AD-02 | Registrar | Review evidence, move the intake through evaluation and approval, and perform explicit handover | Submitted intake, authorized active Registrar, resolved handover blockers, and exactly one active curriculum | Applicant Review | Applicant intake, checklist/evidence history, output-access log, Student Profile, and initial Enrollment | Read-only evidence and preview with focused review, approval, download, and handover actions | Decisions follow the allowed order; stale/repeat/wrong-role actions fail without mutation; handover creates or explicitly reuses one profile | Registrar action, private-download audit, stale/repeat, blocker, curriculum, first-time, transfer, returning, and failed-handover tests | D2A cases reconciled programmatically after bounded remediation | Programmatic pass; Registrar presentation sample is in Section 9.6 | TAL-96D5B acceptance |
| D2-AS-01 | Registrar / Academic Head | Establish a valid academic period | Authorized staff | Academic Years, Terms, Calendar Windows | Academic year, term, calendar event | Registrar editable; Academic Head read-only | A Term outside its Academic Year is rejected with field-level guidance; later calendar and offering readiness remain separate | D2B term-bound, role, academic-calendar, and scheduling-readiness tests | D2B cases reconciled programmatically | Programmatic pass; one readiness wording sample is in Section 9.6 | TAL-96D5B acceptance |
| D2-AS-02 | Registrar | Maintain catalog and curriculum | Active program and authorized staff | Programs, Course Catalog, Specifications, Curriculum Versions, Import Batch Audit | Program, course, specification, curriculum, import batch | Draft records editable; protected revisions read-only; lifecycle changes use focused actions | Source meaning, inherited enrichment, row-level findings, Draft-only posting, approval evidence, activation impact, supersession, and student curriculum locks remain explicit | D2B lifecycle and import tests plus TAL-82 regressions | D2B cases reconciled programmatically | Programmatic pass; no dedicated manual rerun | TAL-96D5B acceptance |
| D2-OF-01 | Registrar / Academic Head | Build schedulable offerings | Valid term, curriculum, rooms, qualified faculty | Term Offerings, Sections, Scheduling Demand | Offering, section, delivery group, faculty qualification, room | Editable before publication boundaries | Readiness findings identify missing or conflicting inputs before solving | D2C offering, scenario, faculty-capacity, and readiness tests | D2C cases reconciled programmatically | Programmatic pass; one scheduling-readiness sample is in Section 9.6 | TAL-96D5B acceptance |
| D3-SC-01 | Registrar / Academic Head | Generate, review, and publish a timetable | All demands ready; solver integration available | Scheduling Demand, Solver Runs, Official Schedules | Demand, generation run, meeting, revision event | Controlled action and review | Solver status, conflicts, objective evidence, and publication state remain distinguishable | D3A master-schedule hardening and scheduling regressions | Local cases reconciled; functional solve/publication remains Human Gate 1 | Local programmatic pass; external publication gate pending | TAL-96D5B acceptance |
| D3-EN-01 | Registrar / Student | Enroll regular and irregular students through explicit gates | Student profile, active enrollment window, published compatible offerings, progression facts, required clearances | Staff Enrollments and Student Enrollment | Enrollment, course enrollment, proposal fields, gate result, reservation, binding, exception | Irregular Student proposes without holding capacity; Registrar or System Super Admin confirms; regular placement confirms one complete logical cohort block | Missing or closed windows, unpublished or incompatible sections, wrong ownership, lifecycle blocks, unit overload, conflict, capacity, terminal-state, and invalid-replacement failures are explicit and transactional. Only staff confirmation holds capacity. | Named D3B window, start, truthful active reuse, terminal restart denial, responsive actions, proposal, cohort, placement, replacement, cancellation, deadline, wrong-role, rollback, and affected TAL-67/TAL-87 regressions | D3B cases reconciled programmatically; post-publication sample follows Human Gate 1 | Programmatic and independent verification passed | TAL-96D5B acceptance |
| D3-FI-01 | Accounting / Student | Assess fees and process the current due | Enrollment and active fee rules | Assessments, Payments, Student Finance | Assessment, fee line, ledger entry, payment attempt, payment | Accounting editable; student evidence view with payment initiation | Amount due is derived from assessment and ledger; unavailable payment is disabled and explained | D3C finance, PayMongo recovery, TAL-68 through TAL-71, and TAL-95 regressions | Local D3C cases reconciled; one authorized PayMongo test-mode checkout and genuine signed webhook completed | Provider gate passed; final visual smoke remains | TAL-96D5B acceptance |
| D3-CO-01 | Student / Registrar / Accounting | Finalize an eligible Enrollment, then view and issue the current COR and schedule | Active Term, official Enrollment, published bindings, applicable hold/lifecycle clearance | Staff Enrollment, Student COR, Class Schedule, Holds, and print views | Enrollment, course enrollment, meeting, binding, hold, and output-access log | Registrar-owned mutation followed by authorized read-only outputs | Missing or blocked output explains the next step; current Student outputs share one Enrollment source; each row shows Online or Face-to-Face modality | D3D resolver, COR, official-enrollment, source-output, schedule-projection, hold-window, authorization, print, and revision convergence tests | D3D cases reconciled programmatically; live publication projection follows Human Gate 1 | Programmatic and independent verification passed | TAL-96D5B acceptance |
| D3-IN-01 | System Super Admin / Accounting | Monitor and recover integrations | Authorized role and recorded operational event | Integration Status, PayMongo Reconciliation | Operational event, payment attempt, webhook call | Controlled recovery action | Duplicate, delayed, rejected, and retried events remain auditable and idempotent | D3C integration status, recovery, webhook, queue, ledger, and idempotency regressions | Provider dashboard delivery, signed acceptance, stored webhook, queue processing, and duplicate classification verified | Provider gate passed; final visual smoke remains | TAL-96D5B acceptance |
| D4-GR-01 | Faculty / Registrar / Student | Enter, review, release, and view grades | Enrollment, roster, assigned faculty | Grade Rosters, Faculty Grade Roster, Student Grades | Grade roster, grade entry, revision event | Role- and state-dependent | Course, section, term, assigned faculty, progress, state, and permitted next action remain visible; only released grades reach students | TAL-96D4B focused and grade regression tests | D4B cases reconciled through deterministic personas | Programmatic pass; one Student output sample is in Section 9.6 | TAL-96D5B acceptance |
| D4-LC-01 | Registrar / Student | Manage holds and lifecycle decisions | Student master and applicable source evidence | Lifecycle Changes, Graduation Review, Student Holds, Academic Status, Completion | Hold, lifecycle change, progression result, graduation batch and snapshot | Staff-controlled; student read-only | Recognizable selectors and labeled operational impacts replace IDs and raw JSON; responsible office, result, visibility, and required action remain understandable | TAL-96D4B focused and lifecycle/graduation regression tests | D4B cases reconciled through deterministic personas | Programmatic pass; one Student output sample is in Section 9.6 | TAL-96D5B acceptance |
| D4-SH-01 | Student | Understand current academic and financial state | Accessible student profile | Student Hub | Aggregated authoritative records | Read-only except permitted profile fields | Empty, blocked, pending, and complete states provide actionable guidance | D4C Student Hub and output-presentation tests plus affected regressions | D4C cases reconciled programmatically | Programmatic pass; Student Hub visual sample is in Section 9.6 | TAL-96D5B acceptance |
| D4-RP-01 | Authorized staff | Produce reports and trace changes | Source records and role permission | Reports / Audit and Import Batch Audit | Audit logs, operational events, import records, output snapshots | Read-only and export actions | Report totals reconcile with source records; sensitive evidence stays permission-bound | D4C report/export tests plus TAL-75, TAL-88, and TAL-92 regressions | D4C cases reconciled programmatically | Programmatic pass; output sample is in Section 9.6 | TAL-96D5B acceptance |
| D5-AC-01 | Project team / stakeholders | Attempt invalid, out-of-order, and hostile journeys before defense | Completed D2–D4 corrections and the selected `MIDDLE` acceptance fixture | All representative surfaces | Whole-system evidence | Mixed | The system prevents invalid transitions, explains recoverable errors, and records sensitive actions | D5B broad local gate is clean after one stale expectation correction; D5C owns the full regression/security/integration gate | Eight-row bounded final smoke plus Human Gates 1 and 2 | D5B local programmatic closure; bounded human evidence and D5C remain | TAL-96D5B and TAL-96D5C |

## 5. Acceptance Evidence

### 5.1 TAL-96D1 Browser Baseline

The bounded browser audit used the deterministic baseline and test-only accounts. It did not submit applications, generate schedules, enroll students, initiate payments, or call external services.

| Viewport | Surface | Observed result |
|---|---|---|
| 360×800 mobile | Public entry | Applicant, student, and staff destinations were explicit; no horizontal document overflow was detected. |
| 360×800 mobile | Student Dashboard, Class Schedule, Finance | Authentication succeeded. Navigation rendered. Schedule and finance showed explicit unavailable/empty states, disabled unavailable payment, and responsible-office guidance. No horizontal document overflow was detected. |
| 768×1024 tablet | Applicant Dashboard and Application | Historical D1 evidence confirmed authentication and the earlier single-upload surface without horizontal overflow. TAL-96D2A has since replaced that form with the approved Wizard and policy-driven multi-upload; the revised surface requires the user-led checklist in Section 5.2.4. |
| 1366×768 desktop | Registrar Dashboard and Scheduling Demands | Authentication succeeded. Registrar-authorized navigation rendered, and the table exposed 54 client-aligned demands with section, delivery group, course, component, modality, duration, readiness, findings, and check time. |
| Browser default | Regular anchor `DBM-1A-001` Student Dashboard | Authentication succeeded. The page showed lifecycle `Active`, academic standing `Regular`, zero ledger-derived balance, and no active holds. |
| Browser default | Irregular anchor `DBM-1A-002` Student Dashboard | Authentication succeeded. The page showed lifecycle `Active` and stored academic standing `Irregular`. No enrollment or subject-selection entry was available in Student Hub. The page also displayed a computed `Recommended: Regular; blockers: 0` description without explaining how that recommendation differs from the stored standing. |

No warning or error was present in the current browser console during the audit. Historical browser logs and deliberately simulated test exceptions are not treated as current browser failures.

### 5.2 TAL-96D2A Admissions and Handover Acceptance

TAL-96D2A covers the boundary from a verified applicant account to an official Student Profile. It does not perform section placement, finance clearance, COR issuance, or the later student lifecycle. Those processes consume the Student Profile and pending Enrollment created at handover.

#### 5.2.1 Intended operating flow

1. A verified applicant signs in only to the Applicant Workspace.
2. The applicant uses a three-step Wizard: Personal Information, Required Documents, and Review and Submit. The personal step captures the approved identity, contact, structured address, guardian, prior-school, program, and informational modality-preference fields. Age is calculated from date of birth and is not stored separately.
3. The applicant may save one partial private draft against an active term and program without the truthfulness declaration. Applicable upload fields are resolved from active `AdmissionRequirementPolicy` records for the selected admission category and credential basis.
4. Each `DIGITAL_UPLOAD` policy receives its own private, single-file PDF/JPG/PNG field with a 5 MB limit. `PHYSICAL_COPY` and `METADATA_ONLY` policies do not create applicant upload fields.
5. Final submission revalidates the personal data, declaration, current term and program, duplicate identity, effective policy, and every blocking digital requirement. Nonblocking digital requirements may remain pending.
6. Submission creates one checklist item per applicable policy and one `DocumentEvidence` per supplied digital requirement, then places the intake in `pending`. The legacy identity reference remains synchronized for backward compatibility; it no longer limits intake to one file.
7. An active Registrar with document-approval permission reviews each private digital item independently. Every successful private download creates a restricted output-access audit record.
8. Rejection requires applicant-readable correction notes and places the intake in `action_required`. The applicant replaces only the rejected item; the previous evidence remains linked as version history.
9. After correction, the Registrar verifies the evidence, marks the intake `for_evaluation`, and may approve it only when every `BLOCKS_HANDOVER` item is resolved.
10. Handover is a separate confirmation. First-time and transfer applicants create a new Student Profile. A returning applicant may reuse an active, unmerged profile only after an explicit identity match. No profile is silently reused or merged.
11. Successful handover changes the account from Applicant to Student, creates or reuses one Student Profile, carries forward applicable checklist items, starts one pending Enrollment for the selected term, and enables Student Hub access. Compatible address and guardian contact data carry into the Student Profile; all intake data remains linked through the source intake.
12. A failed handover leaves no partial new handover records. A later enrollment-gate failure does not delete an already handed-over Student Profile.

#### 5.2.2 Change-control classification

| Audited element | Classification | Evidence and disposition |
|---|---|---|
| Existing checklist, private storage, checksum, versioning, per-item review, audit, duplicate resolution, transactional handover, and failed-enrollment profile preservation | Aligned | The PRD and focused tests support these controls. They were retained and extended rather than replaced. |
| One identity upload as the entire applicant intake evidence contract | Defect / real gap after approved authority correction | The approved product decision requires one field per applicable `DIGITAL_UPLOAD` policy. The PRD, blueprint, intake storage, service, form, and tests were synchronized. |
| Missing client-registration identity, address, guardian, and informational modality-preference fields | Defect / real gap after approved authority correction | Nullable intake columns and final-submission validation now capture the approved fields. Age is derived, avoiding a second value that could disagree with date of birth. |
| Long single-page application form | Defect / real gap after approved authority correction | The application is now a native Filament v5 Wizard with three explicit steps; the underlying draft and submission services remain authoritative. |
| Per-student modality or a separate timetable per preference | Cosmetic / unsupported redesign | Not implemented. The preference is informational; scheduling modality remains a property of each subject offering. |
| Alternative colors, decorative dashboard elements, or a new upload plugin | Cosmetic / preference | Not changed. Native Filament components meet the verified workflow without a dependency. |

#### 5.2.3 Programmatic evidence

| Scenario | Verified result |
|---|---|
| Declaration omitted | Submission is rejected and the intake remains a draft. |
| Partial draft with only some digital files | Draft is retained without checklist creation; final submission identifies each missing blocking policy field. |
| Multiple applicable digital requirements | The Wizard renders a separate private field per policy and submission creates one checklist item and one evidence record per supplied digital requirement. |
| Mixed digital, physical, and metadata-only policies | Only digital policies create applicant upload fields; every policy still creates its own checklist item for the correct staff workflow. |
| Optional advisory digital requirement omitted | Submission succeeds and the optional checklist item remains pending without invented evidence. |
| Admission category or credential basis changes in a draft | References not applicable to the new policy scope are removed and unretained private draft files are deleted. |
| Legacy identity-only draft | The identity reference is mapped to the matching identity policy so prior records remain compatible. |
| Term or program becomes inactive before submission | Submission is rejected before checklist records are created. |
| No effective requirement policy | Submission stops with an explicit policy message. |
| Matching official identity for a non-returning applicant | Duplicate submission is blocked without creating another intake. |
| Invalid, oversized, or unchanged replacement | The replacement is rejected, the temporary invalid file is removed, and the prior evidence history remains. |
| Registrar rejects, applicant replaces, Registrar accepts | Intake, checklist, user, and evidence states remain synchronized; the replacement links to the rejected version. |
| Physical-copy requirement | Registrar records receipt and an optional institutional reference before verification; `RECEIVED_PHYSICAL`, actor, time, and activity history are retained without inventing a file upload. |
| Wrong-role, inactive, or archived staff action | Panel and service authorization deny the operation without changing admissions records. |
| Stale review after approval | Review is rejected; approved intake and submitted evidence remain unchanged. Approved evidence remains downloadable for handover review. |
| Repeated review or out-of-order approval | The action fails with an explanatory notification and no duplicate transition. |
| Authorized private evidence download | The file remains private and a restricted `output_access_logs` record identifies actor, source, action, and time. |
| Applicant withdrawal | Only the owner of an unreviewed draft or pending intake may withdraw. A concise reason is required; the terminal state, timestamp, actor, and reason remain auditable and appear truthfully on the authorized Applicant and Registrar surfaces. |
| Unresolved `BLOCKS_HANDOVER` item | Approval or handover is blocked without creating a Student Profile. |
| Missing or multiple active curricula | Handover stops without a partial Student Profile. |
| First-time applicant | Handover creates one new Student Profile and a pending `new` Enrollment. |
| Transfer applicant | Handover creates one new Student Profile and a pending `transferee` Enrollment. |
| Returning applicant with confirmed match | Handover reuses the existing student number and creates a pending `returnee` Enrollment. |
| Returning candidate is archived, merged, wrong category, mismatched, or missing a birth date | Existing-profile reuse is rejected without modifying the candidate or intake. |
| Repeated handover | The same Student Profile and Enrollment are returned; no duplicate official record is created. |

Verification for this programmatic gate completed on 2026-07-22:

| Gate | Result |
|---|---|
| Focused D2A vertical suite after verification remediation | 67 tests passed with 351 assertions across applicant submission, private-path ownership, per-item evidence review and correction, admission-policy seeding, handover gates, and workspace boundaries. |
| PHP formatting and static analysis | Laravel Pint passed; scoped PHPStan reported no errors. |
| Full application regression baseline before the bounded verification remediation | 865 tests passed with 12,315 assertions; two guarded real-service acceptance tests were intentionally skipped because their explicit external-service acceptance flags were not enabled. The post-remediation focused suite above covers every changed PHP and Filament surface. |
| Database safety | The approved pre-suite `test_tala_db` snapshot was restored after the full suite, and the TAL-96D2A migration was then applied. The pre-suite records were preserved while the applicant-intake schema remained current. |

TAL-96D5B Batch 2 subsequently closed the admission-window and withdrawal-policy gaps without a migration. Public registration and first-intake creation now require any currently open Admissions window; final submission requires the selected term's currently open window. Existing drafts remain editable after closure. Withdrawal requires a reason and produces consistent Applicant and Registrar projections. The dedicated gate passes 8 tests with 75 assertions, the affected admissions/public/Registrar regressions pass 86 tests with 420 assertions, and the rebuilt acceptance-baseline contract passes 91 assertions.

The programmatic result is a D2A pass, not final visual acceptance. The checklist below is intentionally user-led so the presenter confirms the actual wording, action placement, loading behavior, and role-to-role continuity.

#### 5.2.4 User-led manual acceptance checklist

Use `test_tala_db` only. Begin from a complete client-aligned baseline with no applicant intake. The baseline accounts use the test-only password `password`. Prepare separate small valid PDFs for every digital field shown by the policy (for example, identity, birth certificate, and good moral certificate) plus a visibly different corrected PDF for replacement. Record `Pass` or `Fail` and a short observation for every row.

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D2A-M01 | Applicant — `applicant.demo@example.test` | Complete baseline; verified applicant account | Sign in through Applicant Workspace. Also attempt `/admin` and `/student`. | Applicant Dashboard opens; staff and Student Hub access are forbidden. | No admissions record changes. | Wrong-panel URLs must not expose another workspace. | PASS | After the bounded correction, the native Filament empty-state icon remains contained, the Start Application action has clear separation from its copy at narrow width, and wrong-panel URLs retain the branded forbidden response without exposing another workspace. |
| D2A-M02 | Applicant — same account | Active term, currently open institution-scoped Admissions window, active program, effective mixed-evidence admission policy | Open My Application. Confirm the three Wizard steps. In Personal Information, select the active scope and enter all identity, contact, address, guardian, prior-school, and modality-preference fields. Check `Same as applicant address`, confirm the guardian address follows the structured applicant address, then clear it once and confirm independent editing remains possible. In Required Documents, confirm that each digital policy has its own field while physical/metadata requirements do not. Upload only one valid PDF and save without the declaration. | Draft-save notification appears; values and the partial upload remain available; each field explains the accepted formats, 5 MB limit, and whether it blocks final submission. | One private `draft` Applicant Intake stores policy-keyed draft references and the ordinary guardian-address value; the checkbox itself is not persisted. No checklist or evidence record exists yet. | Close or deactivate the Admissions window after saving: the draft must remain editable, but final submission must identify the closed selected term. With no open window, the landing page must say applications are closed, retain Applicant Sign In, and direct registration must fail closed. | PARTIAL | The initial run found zero Admission Requirement Policy rows and duplicate guardian-address entry. The guarded fixtures now provide ten exact mixed-evidence policies and the Wizard has a transient same-address shortcut; refreshed MIDDLE readiness passes. Rerun this row to verify the real upload fields, staged-file presentation, draft restoration, and same-address behavior before marking it passed. |
| D2A-M03 | Applicant — same account | Partial saved draft | Attempt final submission with a blocking digital file missing. Upload every blocking digital requirement, review the summary, submit once without the declaration, then confirm and submit. | The missing policy is named; the unconfirmed attempt stays on the form with a declaration error. The completed attempt redirects to Dashboard with `Pending Review`. | Intake becomes `pending`; `submitted_at` is set; one checklist item exists per policy and one evidence record per supplied digital file. | Revisit My Application or repeat submission; submitted data must not become an editable second draft. |  |  |
| D2A-M04 | Accounting — `accounting.demo@example.test` | Pending applicant intake | Sign in to Staff Workspace and try the Applicant Review URL or navigation. | Applicant Review is absent or forbidden. | No review, download, or audit record is created. | Direct URL must not bypass role ownership. |  |  |
| D2A-M05 | Registrar — `registrar.demo@example.test` | Pending intake | Open Applicant Review, locate the applicant, inspect the complete intake details and checklist, then download each submitted digital requirement from its checklist item. | Queue hides private drafts; the view shows the intake identity, address, guardian, scope, informational preference, and per-item status. Each download succeeds only through the authorized action. | A restricted admission-evidence access log records the Registrar and source for each download. | Return to the same record; files stay private and are never exposed as public URLs. |  |  |
| D2A-M06 | Registrar — same account | Pending intake with multiple submitted digital items | Accept one digital item and reject another with: `Upload a clearer copy showing the complete name.` | Each decision affects only its selected checklist item; rejection displays the correction note and moves the intake to Action Required. | Accepted evidence stays accepted; rejected checklist/evidence becomes rejected; applicant account becomes `action_required`; reviewer and time are recorded. | Repeat either stale decision; it must be hidden or blocked without changing the other item. |  |  |
| D2A-M07 | Applicant — `applicant.demo@example.test` | Action-required intake | Open Requirements, select the rejected requirement, and upload a different corrected PDF. | Only rejected digital items are selectable; the note is understandable; success states that the prior version remains recorded. | A submitted evidence version links through `replaces_document_evidence_id`; that checklist returns to Received Digital / Not Reviewed; the intake returns to `pending` when no other rejection remains. | Try the rejected file again; unchanged replacement must be blocked while accepted items and history remain unchanged. |  |  |
| D2A-M08 | Registrar — `registrar.demo@example.test` | Corrected submitted evidence; physical original-credentials item visible | Verify the corrected digital evidence. For the physical item, use Record Physical Receipt with a sample reference before deciding whether to verify it. Select Mark for Evaluation, then Approve Application. | Digital review, physical receipt, evaluation, and approval actions appear only in valid states and produce clear notifications. | Digital evidence becomes accepted; physical receipt stores `RECEIVED_PHYSICAL`, actor, time, and audit reference; intake and account progress through `for_evaluation` to `approved`. A non-handover physical item may remain open and carry forward. | Try verifying the physical item before receipt, approval before evaluation, or a repeated old action; each must fail without changing the approved result. |  |  |
| D2A-M09 | Registrar — same account | Approved intake; exactly one active curriculum for the program | Open Hand Over to Student. Read the comparison/preview and confirm handover. | Preview explains program, term, checklist, and profile consequence; success notification links to the Student Profile. | One active Student Profile, student number, pending `new` Enrollment, carried checklist, handover actor/time, and Student role are recorded. | If a handover blocker or curriculum problem is deliberately prepared, the action must explain the blocker and create no partial profile. |  |  |
| D2A-M10 | Same person — `applicant.demo@example.test` | Successful handover | Sign out. Try Applicant Workspace, then sign in through Student Hub. | Applicant Workspace is no longer available; Student Hub opens under the same account as the official student. | No duplicate user or Student Profile is created. | Repeat the Registrar handover URL/action; it must be unavailable or idempotent. |  |  |
| D2A-M11 | Applicant — same baseline account after an approved snapshot restore/rebuild only | Fresh baseline with no intake and an open Admissions window | Save a draft or submit an unreviewed pending intake, select Withdraw Application, enter a concise reason, and confirm. | An empty reason is rejected. The warning explains that withdrawal is retained and online continuation stops; completion notification appears; Dashboard and Requirements show the withdrawal date, reason, and Registrar next step; the action disappears afterward. | Intake becomes `withdrawn`; `archived_at`, actor, reason, and activity event are recorded; account remains in the withdrawal audit state; no Student Profile is created. Registrar list shows status/date without the reason, while detail shows date/actor/reason. | Do not run this row after M09 without restoring the approved baseline snapshot; one account cannot represent both terminal paths simultaneously. A reviewed, approved, or handed-over intake must reject online withdrawal without mutation. |  |  |

For any failure, record the row ID, exact visible message, role, URL, input filename if relevant, and whether a record changed. Do not repair the database manually. Return the completed rows so the primary can distinguish a presentation issue from a state, authorization, or transaction defect.

### 5.3 TAL-96D2B Academic Setup acceptance

#### 5.3.1 Intended operating flow

1. The Registrar records one Academic Year, then creates Terms whose start and end dates remain inside that Academic Year.
2. Programs identify the approved three-year `DBM`, `DIT`, and `DTHM` structures. Current student counts do not redefine Program length.
3. Course identity remains stable while Course Specifications carry versioned academic and scheduling definitions. Staff edit only Draft revisions. A complete Draft is activated through a focused action; later material changes start by copying an existing revision to a new Draft.
4. Curriculum CSV is the normal client-onboarding path. The Import Batch keeps the private source, checksum, full row preview, findings, warning acknowledgement, and Draft-only posting. A proposed Draft may inherit components, grading, modalities, and other enrichment from a complete Active Course Specification, but the preview names that inheritance and requires Registrar review.
5. The Registrar completes Draft curriculum entries and Course Specifications, records the external institutional approval reference, reviews activation impact, and explicitly activates the Curriculum Version.
6. Activation supersedes the prior Active Curriculum Version for future applicant handovers only. Existing Student Profiles retain their assigned Curriculum Version.

#### 5.3.2 Change-control classification

| Finding | Classification | Disposition and evidence |
|---|---|---|
| Separate Course identity, Course Specification revision, Course Component, Course Requirement, Curriculum Version, Curriculum Entry, and Import Batch records | Aligned | Preserved. The structure separates durable catalog facts, curriculum placement, and auditable import evidence. |
| Private source storage, SHA-256 checksum, exact headers, full preview, warning acknowledgement, stale-preview protection, transaction, and Draft-only import posting | Aligned | Preserved and covered by the existing TAL-82D import acceptance suite. |
| Registrar could directly edit Active or Retired Course Specifications and non-Draft Curriculum Versions | Defect / real gap | Policies and staff pages now restrict direct editing to Draft records. Revision copying, approval recording, and activation use focused domain actions. |
| Curriculum state and approval fields were directly editable without an impact-confirmed activation workflow | Defect / real gap | Replaced by external-approval recording and transactional activation. The action locks the Program, validates readiness, supersedes the previous Active version, and preserves existing student locks. |
| Terms could be saved outside the selected Academic Year | Defect / real gap | Both Term date fields now validate against the owning Academic Year. |
| Catalog/import choices still exposed `BLENDED` | Defect / real gap | Course Specification and import choices now accept only Face-to-Face and Online. The offering-level follow-through was routed to and completed in TAL-96D2C. |
| Replacing native Filament resources with a custom academic-setup application | Cosmetic / preference | Not done. Native forms, infolists, actions, policies, and Import Batch review remain sufficient. |

#### 5.3.3 Programmatic evidence

- `TAL96D2BAcademicSetupHardeningTest` covers accepted modalities, Term bounds, Draft-only editing, server-owned lifecycle state despite forged Livewire form values, independent revision copying, Course Specification activation, lifecycle action visibility, explicit confirmation, complete approval evidence, supersession, readiness blockers, one Active curriculum, and unchanged Student Profile curriculum locks.
- `TAL82DImportTemplateAcceptanceTest` covers exact templates, unsupported modality rejection, source/enrichment warning visibility, linked Draft review, Draft-only writes, Active-history protection, stale previews, authorization, private downloads, and audit evidence.
- The final independently verified focused run passed 102 tests with 1,071 assertions across D2B, TAL-55, TAL-59, TAL-61, TAL-82, D2A, and the client baseline. These regressions protect downstream academic foundation, offering readiness, and applicant handover behavior.

#### 5.3.4 User-led manual acceptance table

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D2B-M01 | Registrar — `registrar.demo@example.test` | Existing Academic Year | Open Academic Setup > Terms. Create a Term whose start is before the Academic Year and end is after it; then correct both dates. | Each invalid date receives field-level guidance naming the Academic Year; corrected dates save. | Invalid attempt creates no Term; valid attempt creates one Draft Term. | Reverse start/end as a separate attempt; the end-after-start rule must also remain visible. |  |  |
| D2B-M02 | Registrar — same account | Guarded client baseline | Open Programs and inspect `DBM`, `DIT`, and `DTHM`. | Codes and client-aligned names are consistent; each Program shows a three-year length. | No change during inspection. | Current first-/second-year population must not appear as a two-year Program definition. |  |  |
| D2B-M03 | Registrar — same account | One complete Draft Course Specification | Confirm only Face-to-Face and Online are offered. Activate the Draft, reopen it, then use Copy to New Draft with a unique revision identifier. | Activation warns that the revision becomes protected. Edit disappears after activation. Copy opens a separate editable Draft with cloned components and requirements. | Original becomes Active and remains unchanged; one new Draft is created. | Try a duplicate revision identifier and a Draft with no component; both must be blocked clearly. |  |  |
| D2B-M04 | Registrar — same account | Current templates and at least one complete Active revision | Download the Curriculum template, import one valid source row that proposes a new Draft revision, review the inheritance warning, acknowledge it, post, then select Review Curriculum Draft. | Full source row and warning identify source values versus inherited TALA enrichment; posting never claims activation; the action opens the resulting Draft curriculum. | One posted Import Batch, one Draft Curriculum Version, Draft Course Specification when needed, and Curriculum Entry are recorded. | Upload an altered-header file, `BLENDED` Course Specification template, unknown course, or ambiguous prerequisite; the whole batch must remain unposted with row-level findings. |  |  |
| D2B-M05 | Registrar — same account | Candidate Draft curriculum with complete Active specifications and one previous Active curriculum | Record a real-looking synthetic approval reference, read the activation impact, and confirm activation. | Impact names the previous Active version, entry count, existing student locks, and readiness. Success explains future-handover scope. | Candidate becomes Active; previous version becomes Superseded; existing Student Profiles keep their original curriculum IDs. | Attempt activation with a Draft specification, missing approval, or without confirmation; no curriculum state may change. |  |  |
| D2B-M06 | Academic Head — `academic-head.demo@example.test` | Same academic records | Open Programs, Course Specifications, Curriculum Versions, and Import Batch Audit. | Review information is visible; create, edit, approval, activation, and posting actions are absent. | No record changes. | Direct edit/action URLs must not bypass policy authorization. |  |  |

#### 5.3.5 Likely panel questions

| Question | Defensible answer |
|---|---|
| Why can staff not edit an Active Course Specification directly? | Enrollments, schedules, CORs, grades, and history may reference that exact revision. A new Draft records the change without rewriting past facts. |
| Why does importing a curriculum not activate it automatically? | Import proves file structure and creates reviewable Draft records. Institutional approval, complete operational enrichment, and activation impact are separate decisions that require an authorized Registrar. |
| What happens when the client source does not contain scheduling details? | TALA retains the source values. If a complete Active revision exists, a proposed Draft explicitly inherits its operational enrichment for review. Without a complete source revision, posting is blocked rather than inventing data. |
| What does one Active curriculum per Program mean for existing students? | It selects the default for future handovers. Existing students remain locked to the Curriculum Version already assigned to them. |
| Why are the Programs three-year when current population evidence covers only two year levels? | Program duration comes from the approved curriculum structure. The client population is a current count, not a definition of Program length. |
| Why are only two modalities available? | The approved product authority recognizes Face-to-Face and Online. Modality belongs to course/offer delivery, not to a permanent student type, and this correction does not change the solver contract. |

### 5.4 TAL-96D2C Offering, Resource, and Scheduling-Readiness Acceptance

#### 5.4.1 Intended operating flow

1. The Registrar selects an active Term, Program, Curriculum Version, and year level, then builds course-specific Term Offerings from eligible Curriculum Entries.
2. Each required subject becomes one course-specific Term Offering. Each offering owns one or more Section source records.
3. A Section source-record code is unique across the Term. Its default includes both the logical cohort and course code, such as `DIT-1A-CC102`, because every course-specific offering stores a separate Section row.
4. The Section Delivery Group name carries the stable logical cohort code, such as `DIT-1A`. Repeating that name across the cohort's different subjects lets the schedule payload identify them as the same student group and prevent timetable overlaps.
5. Only `ONLINE` and `FACE_TO_FACE` may be selected. A delivery group must also use a modality allowed by its parent Course Specification.
6. The Registrar completes rooms, active faculty qualifications, term load inputs, delivery groups, and the Monday-to-Saturday 07:00–21:00 operating window before generating Scheduling Demands.
7. The readiness result proves that required source inputs exist and are internally usable. It does **not** prove that CP-SAT found a feasible or optimal timetable. Solver feasibility and solution quality are evaluated in their separately approved later gate.

#### 5.4.2 Deterministic scenario manifests

The three scenarios are replaceable acceptance starting states, not three populations combined in one database. A scenario is created only on an empty guarded `test_tala_db`; selecting another scenario while operational data exists fails closed.

| Scenario | Evidence basis | Students | Logical cohorts | Reported / generated faculty | Course-specific offerings | Section/group/demand rows | What the scenario proves |
|---|---|---:|---:|---:|---:|---:|---|
| `MIN` | Current client-reported population and faculty count | 47 | 6 | 9 / 9 | 54 | 54 | The current six first-/second-year cohorts and nine-faculty evidence can be represented with complete scheduling-readiness inputs. |
| `MIDDLE` | Synthetic representative three-year operating load | 270 | 9 | Not reported / 14 | 80 | 80 | One 30-student cohort for every combination of three Programs and three year levels can be constructed deterministically with a synthetic roster that includes load headroom. |
| `MAX` | Client-reported historical population and faculty count | 600 | 20 | 14 / 26 | 80 | 178 | Twenty 30-student logical cohorts can be represented across the nine Program/year scopes; the generated roster is separate from the insufficient historical headcount. |

The MAX cohort allocation starts with two cohorts in every Program/year scope, then assigns the remaining two cohorts deterministically to `DBM` First Year and `DIT` First Year. This is a balanced synthetic distribution, not a claim about the client's historical year-level distribution.

The MIDDLE and MAX third-year scope uses a load-equivalent synthetic placement from the existing acceptance course catalogue where confirmed client third-year operational rows are incomplete. It is suitable for exercising relationships, forms, readiness, and later controlled capacity experiments; it must not be presented as the client's official third-year curriculum. Real deployment data must come through the approved curriculum recording and activation workflow.

The faculty count is derived and reported separately from timetable solving:

| Scenario | Teaching units | Arithmetic lower bound | Generated faculty | Maximum constructed load | Interpretation |
|---|---:|---:|---:|---:|---|
| `MIN` | 162 | 8 | 9 | 19 | The client-reported nine faculty pass the bounded qualification-and-load construction. |
| `MIDDLE` | 240 | 12 | 14 | 18 | Fourteen provide synthetic operating headroom; twelve is only arithmetic and is not claimed as the proven minimum. |
| `MAX` | 532 | 26 | 26 | 21 | The reported fourteen can carry only 294 units at 21 units each, so the fixture uses a separately disclosed sufficient synthetic roster. |

The arithmetic lower bound is `ceil(total teaching units / 21)`. The bounded construction assigns each fixture workload only to qualified synthetic faculty without exceeding 21 units. No faculty-specific unavailability rows are seeded, so every synthetic faculty record is assumed available across the full Monday-to-Saturday operating grid. `PASS` proves this disclosed load-and-qualification input condition only; it does not consider rooms, meeting times, conflicts, or CP-SAT feasibility. The generated MAX count of 26 is sufficient for this deterministic construction, not a universal or mathematically proven minimum. Real availability restrictions can increase the required roster.

The client demographic table is not imported as a Student standing table: `Freshman` describes year level, while `Regular` is an academic standing. Acceptance personas use the standing values actually supported by TALA. Client modality headcounts are also not copied into Student records because modality belongs to each offering; the fixtures instead use a realistic mix of `ONLINE` and `FACE_TO_FACE` offerings.

Every manifest records its basis, limitation, population, cohort count, reported and generated faculty evidence, teaching units, arithmetic lower bound, maximum constructed load, unassignable workload keys, offering count, section/delivery-group/demand count, operating grid, and two explicit results. `unassignable_workloads=[]` means every constructed workload found a qualified synthetic faculty record without exceeding the configured 21-unit load ceiling; a nonempty list names the workload keys that failed this bounded input-readiness calculation.

- `solver_feasibility=NOT_EVALUATED`
- `solver_optimality=NOT_EVALUATED`

Those labels prevent the readiness fixture from being misreported as a completed solver benchmark.

#### 5.4.3 Guarded commands

Prove the testing environment exactly as described in Section 3.3, then inspect without writing:

```powershell
php artisan acceptance:seed-scheduling-scenario MAX --check --no-interaction
```

Replace `MAX` with `MIN` or `MIDDLE` as needed. The output distinguishes `client_reported_faculty` from `synthetic_scheduling_faculty`, prints the bounded faculty-capacity evidence, and reports the exact admission-policy count required for applicant acceptance. On an empty database the inspection reports `NOT_READY` and the target manifest. On an exact complete scenario it reports `PASS`. On partial, edited, downstream, or different-scenario data it reports a conflict and writes nothing.

After a separately approved snapshot-and-rebuild gate has produced an empty `test_tala_db`, create one selected scenario:

```powershell
php artisan acceptance:seed-scheduling-scenario MIDDLE --no-interaction
```

The legacy command remains the compatible MIN entry point:

```powershell
php artisan acceptance:seed-client-baseline --no-interaction
```

Neither command truncates, switches, or repairs an occupied database automatically. Scenario switching requires the documented human-gated snapshot-and-rebuild procedure.

#### 5.4.4 Change-control classification

| Finding | Classification | Disposition and evidence |
|---|---|---|
| Native Term Offering, Section, Delivery Group, Room, qualification, demand, and readiness records | Aligned | Preserved. No migration or new dependency was introduced. |
| Offering and group controls exposed the retired `MODULAR` value | Defect / real gap | Selectable modality options now contain only Face-to-Face and Online. The historical constant remains deprecated only so old validation references fail safely rather than breaking compatibility. |
| Direct Section editing enforced code uniqueness only inside one offering, while the builder enforced it across the Term | Defect / real gap | Direct saves now apply the same Term-wide source-record-code rule. The builder default includes the course code and uses the delivery-group name for the shared cohort identity. |
| Direct delivery-group editing did not enforce parent Course Specification modalities or provide a friendly duplicate-name error | Defect / real gap | The domain service now performs both checks before persistence. |
| The fixture ended at 20:00 despite approved client evening operation to 21:00 | Defect / real gap | The Term, scheduling window, PRD, and exact-completeness contract now use 21:00. |
| No guarded executable MIDDLE or MAX starting state existed | Defect / real gap | One parameterized scenario catalogue and transactional seeding seam now owns all three manifests while retaining the MIN compatibility command. |
| Guarded acceptance fixtures omitted the configured admissions-policy foundation | Defect / real gap | The legacy MIN and parameterized MIN/MIDDLE/MAX paths now create and report the same ten exact mixed-evidence policies. Completeness fails closed when a policy is missing, extra, inactive, or altered. |
| MIN still generated twelve faculty after the client evidence was corrected to nine | Defect / real gap | MIN now generates nine faculty and proves a 19-unit maximum constructed load for its 162 teaching units. |
| MAX treated the reported fourteen faculty as though they were sufficient for the synthetic 178-demand workload | Defect / real gap | The manifest preserves fourteen as client evidence and separately generates twenty-six synthetic scheduling faculty; the distinction is explicit in commands and documentation. |
| Running CP-SAT or choosing Cloud Run resources inside D2C | Out of scope | Not done. D2C prepares stable input manifests; the later approved benchmark gate owns paid solver runs and resource conclusions. |

#### 5.4.5 Programmatic evidence

- `TAL96D2CSchedulingFacultyCapacityAssessmentTest` covers deterministic load assignment, qualification gaps, overload refusal, and the bounded first-passing-roster calculation.
- `TAL96D2COfferingAndScenarioHardeningTest` contains nine focused cases covering the two approved modalities, Term-wide Section source-record-code uniqueness, parent Course Specification modality enforcement, friendly duplicate-group validation, reconciled faculty manifests, executable and rerunnable MIDDLE/MAX scenarios, read-only inspection, conflicting-scenario refusal, and fail-closed behavior after an operator edits a manifest source record or removes an expected admission policy.
- The focused reconciliation regression gate passed 31 tests with 445 assertions across the capacity assessment, the three scenario fixtures, and the compatible MIN baseline.
- The affected TAL-59, TAL-61, TAL-62, TAL-85A/B/C, TAL-94E2a, TAL-96B1, and TAL-96D2B regression files passed against explicit `APP_ENV=testing`, MySQL, and `test_tala_db`.
- `SchemaConformanceTest` passed 5 tests with 168 assertions after the clean-schema default changed to 21:00.
- Laravel Pint, scoped PHPStan/Larastan, and `git diff --check` passed. No migration execution, Cloud solver call, deployment, external-service mutation, or persistent scenario replacement was performed.

#### 5.4.6 User-led manual acceptance table

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D2C-M01 | Operator — project terminal | Explicit testing environment; current database may be empty or occupied | Run the `--check` command for the intended scenario. | Output names `test_tala_db`, scenario, basis, limitation, target counts, reported/generated faculty, teaching units, arithmetic lower bound, maximum constructed load, `unassignable_workloads`, full-grid availability assumption, bounded readiness, current counts, and `NOT_EVALUATED` solver results. | None. | Run it against a different complete scenario; it must report conflict and preserve all rows. |  |  |
| D2C-M02 | Registrar — `registrar.demo@example.test` | Exact MIN or selected scenario present | Open Term Offerings, Sections, and delivery groups. Inspect one cohort across two subjects. | Section codes differ by course; delivery-group names repeat the same logical cohort code; only Online and Face-to-Face are selectable. | None during inspection. | Attempt a duplicate Section code in the same Term, duplicate group name in one Section, or a modality disallowed by the Course Specification; each must show a field error and save nothing. |  |  |
| D2C-M03 | Registrar — same account | Complete academic setup but one required scheduling input deliberately absent in an isolated test case | Generate/review demands and open readiness evidence. | The affected source record and missing input are named; the user is not told that the solver is feasible. | Invalid source remains unresolved until the Registrar corrects it. | Restore the missing qualification, room, group readiness, or Term window and rerun demand/readiness generation; the input-readiness result should recover. |  |  |
| D2C-M04 | Operator and Registrar | Separately approved empty `test_tala_db` snapshot/rebuild | Seed one scenario, rerun the same command, then inspect the Registrar surfaces. | First run reports `created`; repeat is a no-op; UI row counts match the manifest. | One deterministic scenario only. | Do not switch to another scenario without the human-gated rebuild; the command must refuse. |  |  |

#### 5.4.7 Likely panel questions

| Question | Defensible answer |
|---|---|
| Why are Section codes course-specific if students think of `DIT-1A` as one section? | The database stores a Section under one course-specific Term Offering, so each source row needs a Term-unique code. The delivery-group name keeps `DIT-1A` as the shared logical cohort identity across subjects for conflict protection and presentation. |
| Does readiness PASS mean the solver will find a timetable? | No. It means the required source records pass the Laravel readiness checks. Feasibility and optimality require an actual solver run and are reported separately. |
| Are MIN, MIDDLE, and MAX actual client distributions? | MIN uses the current client-reported cohort and nine-faculty counts. MIDDLE is a representative synthetic operating load. MAX preserves the client-reported historical 600 students and fourteen faculty but uses a transparent deterministic cohort distribution and a separate 26-faculty synthetic scheduling roster. |
| Why does MAX generate 26 faculty if the historical evidence says 14? | At the configured 21-unit ceiling, fourteen faculty can carry 294 units, which is less than the constructed MAX workload of 532. Twenty-six pass the bounded deterministic construction. This does not claim the client historically scheduled the same 532-unit workload or employed 26 faculty. |
| Does bounded faculty readiness mean CP-SAT can solve the timetable? | No. It proves only that the fixture has a qualification-aware assignment within faculty load ceilings. Rooms, times, conflicts, and solver feasibility remain separate. |
| Why not keep all three scenarios in one database? | They are alternative starting states. Combining them would inflate counts and make acceptance results ambiguous. Guarded replacement keeps each run reproducible. |
| Can the system exceed 600 students? | The schema has no universal institution ceiling. Six hundred is an evidence-based evaluation tier, not a coded maximum. Actual capacity depends on offerings, sections, rooms, faculty, time, and later measured solver behavior. |

### 5.5 TAL-96D3A Master Schedule Functional Hardening

#### 5.5.1 Intended operating order and ownership

1. The Registrar corrects academic source records until Scheduling Demands are ready.
2. The Registrar generates the immutable demand snapshot and queues one solver run.
3. The configured solver returns a candidate. Laravel independently validates the returned assignments before review.
4. The Registrar reviews warnings, corrects or replaces a complete candidate when warranted, and publishes only a valid candidate.
5. Academic Heads may inspect demand and run evidence but cannot mutate the schedule.
6. System Super Admins monitor integration and application logs; they do not generate demand, dispatch or retry runs, correct candidates, publish, or revise schedules.
7. Faculty and Students see only active meetings from the current published official schedule. Their print/save-as-PDF output uses that same owner-scoped source and records official-output access evidence.

Publication does not make solver output authoritative by itself. The candidate becomes official only after Laravel validation and Registrar publication. A later change uses the controlled revision workflow so the prior publication and revision history remain traceable.

#### 5.5.2 Solution Quality in plain language

The Schedule Run screen separates solver evidence from Laravel's current validation result:

| Measure | Meaning |
|---|---|
| `Optimal` | The assignment passed validated hard constraints and the solver proved it was the best result for the tested model and objective. |
| `Feasible` | The assignment passed validated hard constraints, but the solver did not prove it was the best possible result within the time limit. |
| Demand coverage | Assigned demands divided by all assigned plus unassigned demands. It shows completeness, not prediction accuracy. |
| Hard conflicts | Violations of mandatory rules. A publishable result must not retain a blocking hard conflict. |
| Warnings | Advisory quality findings that may require Registrar explanation but are not automatically hard-rule failures. |
| Objective value | The solver's weighted soft-quality score. It is meaningful only under the same model, weights, and input. |
| Best bound | The solver's mathematical bound on a potentially better objective value. |
| Relative gap | The distance between the returned objective and best bound. Zero indicates a proven optimum; a nonzero gap indicates remaining proof uncertainty. |
| Runtime | Time reported for the solve. It measures duration, not schedule correctness. |

These are optimization-quality measures. TALA does not present a machine-learning-style “accuracy score,” because timetable generation does not predict a known correct label. Correctness is established by hard-constraint validation; completeness, objective, bound, gap, warnings, and duration describe solution quality and performance.

#### 5.5.3 Change-control classification

| Finding | Classification | Disposition |
|---|---|---|
| Staged demand, run, candidate, Laravel validation, publication, revision, and official-projection architecture | Aligned | Preserved without solver, schema, or dependency changes. |
| System Super Admin could view and perform academic schedule mutations | Defect / real gap | Schedule Demand and Run authority is now Registrar-owned; Academic Head remains read-only; System Super Admin retains integration/log operations outside these academic resources. |
| Demand-generation and dispatch actions exposed raw unexpected exception messages | Defect / real gap | Expected validation remains actionable. Unexpected failures are internally reported and shown as safe recovery guidance without paths, endpoints, or secret-bearing exception text. |
| Solver telemetry existed but lacked one plain-language interpretation block | Defect / real gap | Added the Solution Quality summary above existing detailed evidence; no equation, solver, or result contract changed. |
| Faculty and Student schedule tables had no authenticated print output | Defect / real gap | Added browser print/save-as-PDF outputs using active official records, owner scoping, and output-access logging; no PDF dependency was introduced. |

#### 5.5.4 User-led manual acceptance table

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D3A-M01 | Registrar — `registrar.demo@example.test` | Selected scenario has complete readiness inputs | Open Scheduling Demands, generate for the Term, then open Schedule Generation Runs and dispatch. | Success notices distinguish demand readiness from queued solving; an expected validation block names the source correction needed. | Demand rows refresh; one immutable queued run is created only after readiness passes. | Repeat dispatch while another run is queued/dispatching; it must block without creating a duplicate. |  |  |
| D3A-M02 | Academic Head — `academic-head.demo@example.test` | Existing demands and a run | Open Scheduling Demands and the Schedule Run. | Evidence is readable, including Solution Quality and validation findings; generation, dispatch, retry, correction, publish, and revision actions are absent. | None. | Attempt direct action URLs or Livewire requests; authorization must deny mutation. |  |  |
| D3A-M03 | System Super Admin — `system-admin.demo@example.test` | Existing scheduling records and integration events | Use Integration Status and operational logs; attempt to open academic Scheduling Demand/Run resources. | Integration/log visibility remains available; academic schedule resources and mutations are denied. | None. | Confirm no schedule action can be performed through a crafted request. |  |  |
| D3A-M04 | Registrar — same account | A returned candidate with recorded telemetry | Open the Schedule Run and inspect Solution Quality, current validation, and detailed findings. | Status meaning, assigned/total coverage, hard conflicts, warnings, objective, best bound, relative gap, and runtime are understandable; the screen states these are not predictive accuracy. | None during inspection. | A `Feasible` result must not be described as proven best; a result with blocking conflicts must not be publishable. |  |  |
| D3A-M05 | Faculty — `faculty.demo@example.test` | At least one active meeting in a published run assigned to this Faculty | Open Assigned Schedule, select **Print / Save as PDF**, and use the browser print dialog. | Only the Faculty member's current official assignments appear with Term, course, section, day/time, room, and modality. | One `FACULTY_SCHEDULE` `PRINT` access log is recorded when rows exist. | Another Faculty member's assignment, candidate row, or cancelled meeting must not appear. |  |  |
| D3A-M06 | Student — one seeded verified Student account | Active schedule bindings to a published run | Open Class Schedule, select **Print / Save as PDF**, and use the browser print dialog. | Only the signed-in Student's current official classes appear with Faculty, section, day/time, room, and modality. | Existing page view evidence remains; one `STUDENT_SCHEDULE` `PRINT` access log is recorded when rows exist. | A different Student's rows, candidate rows, cancelled meetings, and inactive bindings must not appear. An empty schedule clearly says no current published schedule is available. |  |  |

#### 5.5.5 Primary implementation evidence

- The named `TAL96D3AMasterScheduleFunctionalHardeningTest` proves the Registrar/Academic Head/System Admin authority matrix, safe unexpected-failure notifications, plain-language Solution Quality interpretation, Faculty and Student owner-scoped print outputs, clear empty outputs without misleading access logs, unauthorized-route denial, and `PRINT` access logging.
- Ten affected scheduling regression files covering demand readiness, dispatch, publication, candidate review, retry operations, impact-safe publication, controlled revision, revision UI/notifications, and cross-role projections passed with the new test: 70 tests and 1,001 assertions.
- Four additional staff-access, navigation, assignment-validation, and controlled-revalidation regression files passed: 41 tests and 315 assertions.
- Laravel Pint passed, scoped PHPStan/Larastan reported no errors, both authenticated schedule-output routes were registered, and `git diff --check` passed.
- No solver, Cloud Run, schema, dependency, persistent scenario, deployment, or external-service change was made. Independent `Verify TAL-96D3A` and the user-led visual table remain separate acceptance gates.

#### 5.5.6 Likely panel questions

| Question | Defensible answer |
|---|---|
| Does the solver publish schedules automatically? | No. It returns a candidate. Laravel validates the candidate, then the Registrar reviews and publishes it. |
| Why can the Academic Head not publish? | The approved responsibility split gives the Academic Head read-only academic oversight and the Registrar operational ownership of the official timetable. |
| Why can the System Super Admin not retry a failed run? | Retry is an academic operation on an immutable timetable request, not merely infrastructure administration. System Admin investigates integration/log evidence; the Registrar decides whether the same academic request should be retried. |
| What is the schedule's accuracy? | Timetabling is not a prediction task. TALA reports hard-constraint validity, demand coverage, objective, bound, relative gap, warnings, and runtime instead of an invented accuracy percentage. |
| Does `Feasible` mean incorrect? | No. It means all validated hard constraints pass, but the solver did not prove that no better objective value exists within the time limit. |
| Do printed schedules come from a separate file? | No. The browser-ready output is built from the same active official records as the on-screen Faculty or Student schedule and is owner-scoped and audited. |

### 5.6 Enrollment window, proposal, and placement hardening

#### 5.6.1 Operating contract

Enrollment placement uses three deliberately different records:

| Record | Meaning | Holds a seat? | Who creates or confirms it? |
|---|---|---:|---|
| Course Enrollment proposal fields | The irregular Student's requested published Section for one subject | No | The owning Student during an active enrollment window |
| Enrollment Seat Reservation | Staff-confirmed capacity allocation for one Course Enrollment and Section until its deadline | Yes | Registrar or System Super Admin |
| Student Schedule Binding | The Student's active link to every official meeting of the confirmed Section | No additional seat; it projects the confirmed placement | Created atomically with staff confirmation |

A new or continuing Student remains a Student while waiting for publication or an enrollment window. The account does not return to Applicant status. Regular placement confirms every eligible published subject carrying the selected logical cohort code as one transaction. Irregular placement confirms all current Student proposals as one transaction. If any selected Section is invalid, unpublished, full, conflicting, outside the term, or unauthorized, no partial reservation or binding is retained.

The Enrollment Gate Evaluator, not the placement button, owns the overall Enrollment status. `Payment Pending` is therefore valid only after every non-finance source gate is clear and Finance is the sole unresolved gate. A placement failure normally converges to `Capacity Pending` or `Pending Review`, with a recorded blocker and responsible office.

#### 5.6.2 Change-control classification

| Finding | Classification | Evidence and disposition |
|---|---|---|
| Existing Enrollment, Course Enrollment, reservation, binding, gate, and exception ownership | Aligned | Preserved. No replacement enrollment model was introduced. |
| Existing row locking, publication validation, capacity counting, lifecycle guard, conflict detection, and idempotent per-course placement | Aligned | Preserved and reused inside complete placement transactions. |
| Student proposal could not be retained without either losing the choice or prematurely holding capacity | Defect / real gap | Added nullable proposal Section and proposal timestamp fields to Course Enrollment. Existing records remain valid with null values. |
| Enrollment timing read retired Term columns | Defect / real gap | Continuing start, proposal, confirmation, and deadline now use the canonical institution enrollment `CalendarEvent` window. |
| Student Hub had no Enrollment page | Defect / real gap | Added one owner-scoped page with status guidance and a flat eligible published-section table. |
| Staff listed all term Sections and confirmed only one Section before directly setting `pending_payment` | Defect / real gap | Options are curriculum/progression scoped; regular cohort or irregular proposal placement is confirmed completely; the gate evaluator owns status. |
| Authorized placement calls could mutate terminal Enrollments | Defect / real gap | Service-level guards now reject placement, proposal confirmation, replacement, or repeated cancellation after Officially Enrolled, Cancelled, Dropped, or Withdrawn. Staff and Student mutation actions are hidden for the same states. |
| Staff replacement could add a new irregular subject without a Student proposal | Defect / real gap | Replacement now requires an existing capacity-holding reservation for the same Term Offering. The replacement list contains only alternative Sections for already-confirmed subjects. |
| Student Enrollment did not identify one regular cohort proposal or validate complete replacement proposals | Defect / real gap | Regular students see a read-only cohort only when that cohort contains every eligible offering. Irregular rows show current capacity and confirmed-schedule blockers; overlapping Sections within the newly submitted complete set are rejected before proposal records are written. |
| A full Section could be saved as a non-capacity-holding proposal through the service even though the UI disabled it | Defect / real gap | Proposal submission now rechecks remaining capacity inside its transaction. Registrar confirmation still rechecks capacity because a proposal does not reserve the seat. |
| Complete irregular confirmation read proposal IDs before acquiring the Enrollment and proposal-row locks | Defect / real gap | Confirmation now locks the Enrollment and current proposal rows in one transaction before reading, validating, and confirming the complete set. |
| The Student proposal action did not explain that each submission replaces the previous complete set | Defect / real gap | The action is named **Replace complete proposal**, requires every Section the Student wants to retain, and allows an alternative that conflicts only with a proposal being replaced. The service validates conflicts within the newly submitted set. |
| Cancellation and deadline expiry did not release all placement effects | Defect / real gap | Recovery releases capacity-holding reservations and active bindings transactionally, then recalculates gates where the Enrollment remains active. |
| Starting a continuing Enrollment returned an existing completed or terminal record while the staff action always reported **Enrollment started** | Defect / real gap | Active records are reused truthfully without creating a duplicate. Officially Enrolled, Cancelled, Dropped, and Withdrawn records are rejected without mutation; the notice identifies the existing state and preserves its recorded reason. |
| Narrow Enrollment tables could push actions outside the immediately visible area and prioritize raw status or gate text over the staff decision | Defect / real gap | Native Filament mobile stacking and one record-action group keep actions reachable. Status and type values use readable labels; the list and record lead with Next Step and responsible office while dates and technical gate evidence remain available as secondary, collapsible detail. |
| Alternative visual styling or a separate enrollment dashboard | Cosmetic / preference | Not implemented. Native Filament table, actions, notifications, empty state, and record infolist remain sufficient. |

#### 5.6.3 Automated evidence

The named D3B feature scenarios prove canonical open, closed, and missing enrollment windows; authorized continuing start; truthful reuse of an existing active Enrollment; rejection of Officially Enrolled, Cancelled, Dropped, and Withdrawn restart attempts without mutation; wrong-role denial; native mobile stacking and grouped action configuration; irregular proposal ownership; proposal-without-capacity behavior; service-level full-Section rejection; curriculum incompatibility rollback; conflict rejection before proposal persistence with exact Student feedback; locked complete irregular confirmation; one read-only complete regular cohort proposal; regular logical-cohort confirmation; explicit complete-proposal replacement; true same-subject replacement; terminal-state immutability; deadline recording; cancellation; automatic expiry release; Student table visibility; and Student proposal action. The focused TAL-96D3B test file passed 33 tests with 289 assertions. The affected TAL-67, TAL-87A/B/C/D, and TAL-96D3B enrollment regression stack passed 61 tests with 629 assertions. Older regression assertions that counted every gate row in the seeded shared database were narrowed to the Enrollment created by each test; the persistent acceptance baseline was not deleted or changed. Pint, scoped PHPStan, Serena diagnostics review, and `git diff --check` completed; PHPStan and the executable gates passed.

The evidence is programmatic. The user-led checks below remain required because automated tests do not judge wording, information hierarchy, or whether the rendered workflow is understandable during the defense.

#### 5.6.4 User-led manual acceptance table

Use only the disposable `test_tala_db` acceptance environment. Enter Pass or Fail and the exact observation; do not repair the database manually during a row.

| ID | Role and credential | Prerequisite data | Steps and inputs | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D3B-M01 | Registrar — `registrar.demo@example.test` | Active Student Profile, active Term, open enrollment window | Open Enrollments, select **Start Continuing Enrollment**, choose Student, Term, and Regular; repeat with the same Student and Term | The first action says **Enrollment started**. The repeat says **Enrollment already exists** and directs staff to the existing record instead of claiming another start. | One pending Enrollment is created; repeating the action creates no duplicate or status change. | Close or remove the window and retry; the message must distinguish closed from not configured. |  |  |
| D3B-M02 | Regular Student — a verified seeded regular account | Started Enrollment and complete published cohort | Open Student **Enrollment** | Overall status and one named proposed logical cohort block are readable; only that block's published Sections appear and no proposal action is offered | None | Another cohort, candidate-only Section, or cancelled Section must not appear in the proposed block |  |  |
| D3B-M03 | Registrar — same account | D3B-M02 Enrollment | Open the Enrollment and select **Confirm / Replace Placement**; choose the logical cohort | The option states how many published subjects it contains; success states that reservations and bindings were recorded | One active Course Enrollment, reservation with deadline, and official meeting binding per cohort subject | Deliberately make one Section full or conflicting; the entire cohort confirmation must fail without partial placement |  |  |
| D3B-M04 | Irregular Student — a verified seeded irregular account | Started irregular Enrollment, open window, eligible published Sections | Open Student **Enrollment**, select every Section to retain, then run **Replace complete proposal** | Table shows subject, description, Section, cohort, modality, units, schedule, remaining seats, eligibility, capacity/conflict result, and Proposed status; the confirmation explains that the selected set replaces the previous proposal | Proposal Section and timestamp are recorded per selected Course Enrollment; an omitted previous proposal is dropped; no reservation or binding exists | Select two Sections for one subject, overlapping Sections, an incompatible Section, a full Section, or a blocked subject; the complete replacement must fail clearly without changing the previous valid set |  |  |
| D3B-M05 | Registrar — same account | D3B-M04 proposal | Open the Enrollment and select **Confirm / Replace Placement** | Modal states that all proposed subject Sections will be confirmed together | Proposals clear; one reservation and all official meeting bindings are created per selected subject | Introduce a time conflict or full Section; confirmation rolls back every selected course |  |  |
| D3B-M06 | Irregular Student — same account | Prerequisite-blocked subject in the seeded progression evidence | Open Student **Enrollment** and inspect published curriculum Sections | The blocked Section remains visible for explanation, its exact academic blocker is shown, and its selection checkbox is disabled | None | Direct or crafted submission of the blocked Section is rejected |  |  |
| D3B-M07 | Irregular Student and Registrar | Selected units exceed the normal limit without an active approved exception | Propose and attempt confirmation | The action states the requested and allowed unit totals | No proposal or placement mutation survives the failed action | Record an approved scoped unit-load exception, retry, and confirm only within the approved limit |  |  |
| D3B-M08 | Registrar — same account | Confirmed irregular placement, open enrollment window, and another compatible Section for the same subject | Run **Confirm / Replace Placement** and choose the replacement | The selector lists only alternative Sections for already-confirmed subjects; success distinguishes replacement from a new Student proposal | Old reservation is Released; old bindings are inactive; the new reservation and bindings are active with the enrollment-window deadline; unrelated courses remain unchanged | With no confirmed placement, or with a Section for a different subject, the action is absent or rejected and the old valid placement remains |  |  |
| D3B-M09 | Registrar — same account | Pending confirmed placement | Run **Cancel Enrollment**, enter a reason, and confirm | Success states that pending reservations and bindings were released | Enrollment becomes Cancelled with actor-supplied reason; reservations are Released; bindings are inactive | Try as Academic Head or Student; the action is absent or denied and no record changes |  |  |
| D3B-M10 | Registrar / operator | Pending reservation with a known enrollment-window deadline | Let the reservation deadline pass, then run `php artisan enrollment:release-expired-reservations --no-interaction`; production scheduling invokes the same command hourly | Command reports the released count; Enrollment returns to an actionable placement/capacity state rather than silently retaining a seat | Expired reservation is Released, bindings are inactive, and gates are recalculated | A reservation with a future or null deadline must not be released |  |  |
| D3B-M11 | Student — same account | Missing or closed enrollment window | Open Student **Enrollment** and attempt a proposal | Existing status remains visible; proposal failure clearly says whether the window is missing or outside its dates | No Course Enrollment proposal, reservation, or binding changes | Reopen the canonical window and retry without changing the Student account |  |  |
| D3B-M12 | Academic Head — `academic-head.demo@example.test` | Existing Enrollment | Open the Enrollment record and attempt placement, replacement, cancellation, or crafted Livewire action | Read-only gate and placement evidence remains available; Registrar-owned mutation actions are absent or denied | None | System Super Admin may perform the approved operational actions; an unrelated staff role may not |  |  |
| D3B-M13 | Registrar — `registrar.demo@example.test` | Several Enrollments in different states | Open the Enrollment list at representative phone, tablet, and desktop widths; open the row action menu and one record | Phone rows stack instead of cutting off controls; one labeled or tooltipped header action remains discoverable; View, Confirm or Replace Placement, and Cancel Enrollment remain available when authorized. The record leads with Current Status, Next Step, and Responsible Office. | None | No horizontal scrolling is required to reach a record action. Lifecycle dates and gate diagnostics remain available in collapsed secondary sections. |  |  |
| D3B-M14 | Registrar — `registrar.demo@example.test` | Existing Officially Enrolled, Cancelled, Dropped, or Withdrawn Student-Term Enrollment with a recorded reason | Run **Start Continuing Enrollment** for the same Student and Term | A danger notice states that the existing state cannot be restarted and points staff to the existing record; the recorded reason remains visible. | No duplicate, reopening, status change, or reason change occurs. | Repeat each final state. A pending active Enrollment must instead report **Enrollment already exists** and remain actionable. |  |  |

#### 5.6.5 Likely panel questions

| Question | Defensible answer |
|---|---|
| Does an irregular Student reserve a seat by clicking a Section? | No. The click stores a proposal only. Capacity is consumed only when authorized staff confirms the complete placement. |
| Why does the Student not trigger CP-SAT? | CP-SAT produces the institution's cohort timetable before publication. Irregular enrollment selects among already published Sections and is validated by Laravel. A new solver run is an institutional scheduling decision, not a per-Student action. |
| What happens while no schedule is published? | The person remains an active Student. The Enrollment page explains that no eligible published Sections are available; no arbitrary waiting time or fake placement is created. |
| How are regular Students placed? | Staff selects one published logical cohort code. TALA resolves all eligible published subject Sections for that cohort and confirms them atomically. |
| Can two staff members take the last seat? | Placement locks the Enrollment, selected Sections, reservations, and relevant bindings, then rechecks capacity inside the transaction. One succeeds; the other receives a capacity failure. |
| Why is the reservation deadline the enrollment-window end? | The institutional enrollment Calendar Event is the canonical policy date. Pending capacity is released when that configured deadline expires. |
| Why can status still say Pending Review after placement? | Placement is only part of enrollment. Document, academic, behavior, discipline, finance, and other source gates may still be unresolved. |
| Does cancellation delete the Student Profile? | No. It ends one term Enrollment and releases its pending placement effects. The Student master record remains. |

### 5.7 Finance, PayMongo, and Accounting recovery

TALA treats the ledger as the source of truth for a Student's balance. A browser redirect from PayMongo is only a return to the Student Hub; it is not payment proof. The normal path remains a signed PayMongo webhook, queued processing, one verified Payment, and one linked payment Ledger Entry.

#### 5.7.1 Authoritative flow and ownership

| Stage | Owner | Authoritative evidence | Visible result |
|---|---|---|---|
| Assessment and amount due | Accounting / TALA | Active Assessment, assessment lines, payment schedule, and posted Ledger Entries | Student Finance shows the Current Amount Due and Payment Status. |
| Hosted checkout | Student / PayMongo | TALA Payment Attempt and PayMongo Checkout Session | Student leaves TALA temporarily to pay. The return page does not clear the balance. |
| Normal payment confirmation | PayMongo / TALA queue | Valid signed webhook matched to the exact TALA attempt | The posting service creates one verified Payment and one payment Ledger Entry, recalculates the finance gate, and queues the Student notification. |
| Missed-webhook recovery | Accounting | Existing TALA Payment Attempt plus a server-to-server retrieval of that exact PayMongo Checkout Session | Pending or expired provider state updates only the attempt. A reported paid state creates sanitized review evidence and requires an Accounting decision. |
| Official receipt mapping | Accounting | Verified Payment and institutional OR number | Student Finance shows the OR number or **Pending OR Mapping**. Provider confirmation does not invent an official receipt. |

PayMongo's Checkout Session retrieval requires the secret API key and may return related payments. TALA keeps only the fields needed for reconciliation: checkout identifier, institutional reference, provider state, payment identifier, amount, currency, mode, intent identifier, dispute/refund indicators, and paid time. It does not persist the checkout URL, API credentials, signature secret, or raw provider response in recovery evidence. This recovery design follows PayMongo's [Checkout Session retrieval contract](https://docs.paymongo.com/reference/retrieve-a-checkout) while preserving the [webhook-based payment-confirmation flow](https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start).

#### 5.7.2 Student checkout-return meanings

| Return | What the Student sees | What TALA changes |
|---|---|---|
| `checkout=success` | **Checkout completed**, **Waiting for verified payment confirmation**, and an explanation that the return is not proof of posting | Nothing is posted from the redirect. The displayed balance still comes from the ledger. |
| `checkout=cancelled` | **Checkout cancelled** and **No payment was recorded from this return** | No Payment or payment Ledger Entry is created. The Student may start another checkout when eligible. |

Student Finance leads with **Current Amount Due**, **Payment Status**, **What to do next**, **Responsible Office**, and **Official Receipt Status**. Assessment lines, schedules, ledger rows, attempts, acknowledgements, and accommodations remain available as collapsed detail instead of displacing the current decision.

#### 5.7.3 Missed-webhook operator recovery

1. Accounting opens **PayMongo Reconciliation** and chooses **Recover a PayMongo checkout**.
2. Accounting selects an existing pending or expired PayMongo Payment Attempt. TALA never accepts an arbitrary provider identifier from the operator.
3. TALA retrieves the recorded checkout server-to-server.
4. A pending or expired checkout updates only the Payment Attempt and creates a processed operational record.
5. A paid response creates sanitized **Provider recovery** evidence in **Accounting confirmation required** state. It does not post automatically because it did not arrive through the normal signed-webhook path.
6. Accounting confirms only when the Student/Assessment ownership, checkout identifier, institutional reference, PHP currency, exact amount, provider payment identifier, payment-intent identifier, test/live mode, and dispute/refund state match.
7. Confirmation reuses the existing idempotent posting service. Repeated confirmation returns the existing Payment and does not create another ledger or notification effect.
8. Accounting may reject unposted recovered evidence with a recorded reason. Posted evidence cannot be rejected through this recovery control.

The **Integration Status** page intentionally separates four facts: **Local PayMongo readiness**, **Recent verified webhook**, **Open local exceptions**, and **Provider dashboard state**. The final value is **Not checked by TALA** because this read-only local screen does not claim knowledge of the current PayMongo dashboard.

#### 5.7.4 TAL-96D3C evidence and classification

| Finding | Classification | Evidence and disposition |
|---|---|---|
| Assessment, checkout, signed webhook, idempotent posting, ledger clearance, notification, and OR mapping already had distinct owners | Aligned | Preserved. Existing TAL-69, TAL-86B, TAL-95, and TAL-96C tests remain the accepted regression evidence. |
| A completely missed webhook had no bounded operator recovery from a known TALA attempt | Defect / real gap | Added server-to-server checkout retrieval, sanitized review evidence, exact-match Accounting confirmation/rejection, and audit activity without a schema change. |
| Hosted-checkout return parameters were not explained | Defect / real gap | Added truthful success and cancellation notices that never claim a payment was posted. |
| Student and operator finance screens led with technical detail | Defect / real gap | Added plain-language current state and next action; retained technical evidence as secondary detail. |
| Combining Assessment, Attempt, Payment, Ledger, reconciliation, and official receipt into one record | Cosmetic / unsafe redesign | Rejected. Separate records preserve evidence authority and auditability. |

Automated TAL-96D3C evidence covers success/cancellation return non-authority; paid recovery without posting; pending, failed, and expired recovery; exact confirmation; rejection of incomplete mode, dispute, or refund evidence; idempotent repetition; mismatch refusal; audited rejection; Accounting authorization; secret-safe reconciliation; and truthful integration monitoring. The compatible affected regression gate passed 104 tests with 1,180 assertions. Two older test families remain unsuitable for a populated acceptance database because they assume globally empty tables; TAL-96D3C did not destructively remove the approved baseline to satisfy those assumptions. Consolidated user-run visual acceptance remains scheduled once in TAL-96D5B.

**TAL-96D5B provider acceptance (2026-07-26).** The authorized sandbox gate used the verified `MIDDLE` assessment `14`, payment attempt `16`, and a PHP 2,000.00 PayMongo Checkout Session in test mode. PayMongo showed **Card Payment Received**, then sent a genuine signed `payment.paid` event through the existing enabled webhook. TALA stored and processed webhook call `2`, marked attempt `16` paid, recorded provider reference `paymongo:pay_4phug8ibjn4eLah2rDHj4c8H`, created one verified Payment (`4`) and one linked payment Ledger Entry (`20`) for the exact amount, cleared the finance gate, and retained notification plus operational event `27`. The provider dashboard independently showed the successful test delivery and its event/process identifiers.

PayMongo disables retry for an already-successful dashboard delivery. To verify duplicate handling without creating another checkout, the accepted stored payload was delivered once more with a new valid test signature while preserving the same event identity. TALA accepted the request with HTTP `202`, stored webhook call `3`, classified it `duplicate`, and retained exactly one Payment, one payment Ledger Entry, one finance effect, and one notification/operational outcome. This is provider-backed test-mode acceptance, not a live transaction, production-readiness certification, or a substitute for D5C regression and D5E deployment-readiness closure.

#### 5.7.5 TAL-96D3C manual acceptance rows for TAL-96D5B

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record/state change | Invalid or edge case | Pass/Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D3C-M01 | Student — assigned D5 finance account | Active assessment with a positive due | Return to Student Finance with `checkout=success` | Waiting-for-verification notice; current due remains ledger-derived | No redirect-only Payment or Ledger Entry | Refresh and repeat the URL |  |  |
| D3C-M02 | Student — assigned D5 finance account | Same as D3C-M01 | Return with `checkout=cancelled` | Cancellation notice and unchanged current finance position | No Payment or payment Ledger Entry | Start another eligible checkout |  |  |
| D3C-M03 | Accounting — `accounting.demo@example.test` | Pending known PayMongo attempt with provider state supplied during the bounded D5 sandbox gate | Recover the known checkout | Pending/expired result or Provider recovery row with a clear next step | Attempt truthfully updated; paid state remains review-only | Wrong role and mismatched evidence must be denied |  |  |
| D3C-M04 | System Super Admin — `system-admin.demo@example.test` | Existing local integration events | Open Integration Status | Local readiness, recent verified webhook, open local exceptions, and provider dashboard not checked are distinct | Read-only; no provider call or secret exposure | Narrow viewport and empty-event state |  |  |

#### 5.7.6 Likely panel questions

| Question | Defensible answer |
|---|---|
| Why does a successful PayMongo return not immediately reduce the balance? | A redirect can be copied, interrupted, or replayed. TALA changes the authoritative ledger only from verified provider evidence processed through an idempotent posting service. |
| What if PayMongo was paid but its webhook never reached TALA? | Accounting retrieves the exact checkout already recorded on the TALA Payment Attempt. A paid response enters review and posts only after all ownership, amount, currency, mode, reference, intent, and risk indicators match. |
| Can recovery create duplicate payments? | No. The provider reference, one-payment-per-attempt behavior, ledger source uniqueness, operational-event identity, and idempotent posting service converge repeats to the existing records. |
| Does TALA generate the school's official receipt from PayMongo? | No. PayMongo evidence verifies the external payment. Accounting separately maps the institution's official receipt number. |
| Can the Registrar override a failed finance gate? | The Registrar consumes the verified finance projection but does not own payment confirmation. Accounting owns payment evidence and reconciliation. |

### 5.8 Official Enrollment, Current COR, Schedule, and Holds

#### 5.8.1 Intended operating flow

1. Registrar reviews an Enrollment only after its academic, placement, document, lifecycle, and finance gates have produced recorded results.
2. **Record Official Enrollment** rechecks those gates inside the existing transaction, converts the confirmed seat reservation, and retains the official published schedule bindings.
3. Successful officialization means the Enrollment is official. It does not by itself promise that the Student may print a COR: lifecycle restrictions and effective COR-print holds remain independent availability checks.
4. TALA identifies the Student's current official Enrollment as the officially enrolled record belonging to the active Term. It does not infer “current” from the largest record ID or combine every active binding in the Student's history.
5. Student COR, Student Schedule, printable schedule, schedule access log, and the Dashboard's current schedule projection use that same Enrollment. Registrar and Accounting may still open an authorized historical COR from its specific Enrollment record.
6. Each enrolled subject preserves its own **Online** or **Face-to-Face** modality. The COR summarizes the set as Online, Face-to-Face, or Mixed and also shows modality on every subject or meeting row. Online meetings do not claim a physical room.
7. An effective COR-print hold blocks the Student view and presents only the Student-safe instruction and responsible office. A future, expired, resolved, or waived hold does not block the current COR.

#### 5.8.2 Change-control classification

| Finding | Classification | Disposition |
| --- | --- | --- |
| Transactional final-gate recheck, reservation conversion, official bindings, role policy, and source-derived outputs | Aligned | Preserved without schema or service redesign. |
| Student COR selected the latest official Enrollment by timestamp even when it belonged to a closed Term | Defect / real gap | One current-official-enrollment resolver now selects an official Enrollment in the active Term with deterministic tie-breaking. |
| Student Schedule and its access log could read all active bindings and select different Enrollment sources | Defect / real gap | Both are scoped to the resolver's Enrollment; historical-term rows cannot leak into the current output or its audit record. |
| COR hold query ignored effective and expiry windows | Defect / real gap | COR availability now reuses the canonical hold evaluation semantics for an effective `blocks_cor_print` hold. |
| COR authority listed Modular and presented one student-level delivery modality | Defect / real gap | PRD and UI blueprint now define only Online and Face-to-Face per offering, with a derived Course Delivery Mix and a modality value on each row. No student-level modality was added. |
| Successful officialization always claimed the COR was available | Defect / real gap | Staff notification now distinguishes official Enrollment from Student COR availability and states the remaining safe reason when blocked. |
| Unavailable COR rendered meaningless “Not available” detail grids, and schedule/hold tables or long actions were difficult on narrow screens | Defect / real gap | Unavailable output leads with one explanation and hides irrelevant detail; touched surfaces use responsive Filament columns, mobile table stacking, and icon-first actions with labels from suitable breakpoints and tooltips. |
| Alternative visual styling or a new output/download framework | Cosmetic / preference | Not implemented. Existing native Filament and browser print/save-as-PDF patterns remain. |

#### 5.8.3 Programmatic evidence

The named D3D scenarios cover active-term resolution, absence of a current official Enrollment, deterministic multiple-active-Term handling, current-versus-historical schedule isolation, matching schedule access-log source, effective hold windows, Mixed delivery, per-row modality, hidden unavailable details and print action, owner authorization, lifecycle restrictions, historical staff access, print output, officialization eligibility and denial, idempotency, terminal-state protection, reservation conservation, and published-schedule revision convergence. Independent verification passed 46 focused tests with 476 assertions, including the directly affected Student Hub regression and a test-isolation correction that scopes older schedule-publication assertions to their own fixture Term and run instead of the populated acceptance database. Pint, scoped PHPStan, route checks, and `git diff --check` passed; Serena reported no diagnostics in the changed domain services and tests and only its known Intelephense `auth()->user()` helper false positives in Filament pages, while Larastan resolved those pages without error. The implementation uses the existing schema, models, policies, transactions, and output log. It introduces no solver, payment-gateway, deployment, dependency, or persistent-database change. Consolidated browser acceptance remains deferred to TAL-96D5B.

#### 5.8.4 User-led manual acceptance table for TAL-96D5B

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or audit result | Invalid or edge check | Pass/Fail | Observation |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| D3D-M01 | Registrar — `registrar.demo@example.test` | Eligible Enrollment with every gate passed and official schedule bindings | Open the Enrollment, read **Record Official Enrollment**, confirm, and reopen the record | Modal explains the transactional recheck and separates official status from COR availability. Success identifies whether the Student COR is available or names the remaining safe blocker. | Enrollment becomes Officially Enrolled once; reservation and bindings remain official; audit evidence is retained. | Repeat the operation and attempt it with a failed gate or terminal Enrollment; no duplicate or partial mutation occurs. |  |  |
| D3D-M02 | Accounting — `accounting.demo@example.test` | Official Enrollment | Open the Enrollment and select **Print COR** | Accounting may inspect or print the authorized source COR but cannot record official enrollment. Course Delivery Mix and row modalities are clear. | A COR print access log uses this Enrollment and Accounting copy context. | Try the Registrar mutation action; it is absent or denied. |  |  |
| D3D-M03 | Student — verified seeded Student | Official Enrollment in the active Term, published bindings, no COR hold | Open **COR**, then **Class Schedule**, then each print action | COR and Schedule show the same active Term, subjects, sections, times, Faculty, rooms, and Online or Face-to-Face modality. Mixed appears only when both modalities occur. | View and print logs point to the same current official Enrollment and current schedule version. | Confirm a closed-Term subject or another Student's row does not appear. |  |  |
| D3D-M04 | Student — same account | Current official Enrollment with an effective COR-print hold | Open **COR** and **Holds** | COR gives one Student-safe blocker and responsible office; Student Information, subject details, and print action are absent. Holds identifies required action and office. | No blocked COR view or print log is created. | Move the hold into the future, expire, resolve, or waive it; the current COR becomes available without changing the Enrollment. |  |  |
| D3D-M05 | Student — verified account without active-Term official Enrollment | Historical official Enrollment or pending current Enrollment | Open **COR** and **Class Schedule** at phone, tablet, and desktop widths | COR explains that no current official Enrollment is available. Schedule explains the missing official enrollment or missing published bindings. No invalid print action is offered. | No current-output access log is recorded. | Historical rows remain absent even if old bindings are retained for audit history. |  |  |
| D3D-M06 | Student / Registrar | Current official Enrollment containing at least one Online and one Face-to-Face subject | Compare Student COR, Student Schedule, and Registrar COR print | All views show modality per row; COR summary says Mixed; Online rows do not claim a physical room. Narrow screens keep actions reachable and stack schedule or hold records. | No source data changes during viewing. | A single-modality Enrollment says Online or Face-to-Face, never Mixed or Modular. |  |  |

#### 5.8.5 Likely panel questions

| Question | Defense-ready answer |
| --- | --- |
| What makes an Enrollment “current”? | For official Student outputs, it is the officially enrolled record in the active academic Term. Deterministic ordering handles an invalid situation with more than one active Term, but staff should correct that setup. |
| Does official enrollment always make the COR printable? | No. Officialization and output availability are separate facts. An effective COR-print hold or blocking lifecycle state can keep the Student COR unavailable while preserving the official Enrollment. |
| Can old schedules appear in the current Student view? | No. COR, Schedule, print, Dashboard schedule projection, and access logging are scoped to the same current official Enrollment. Historical source records remain available to authorized staff through their owning Enrollment and audit history. |
| Is modality chosen for the Student? | No. Modality belongs to each subject offering. A Student can naturally have a Mixed week when some enrolled subjects are Online and others are Face-to-Face. |
| Why does the system log output access? | COR and schedules are sensitive Student records. The log identifies the source Enrollment, actor, role, action, copy context, schedule version, and time without creating a second editable copy of the output. |

### 5.9 System-wide workspace identity and browser failure recovery

TALA presents four consistent entry surfaces: the public site, Applicant Workspace, Student Hub, and Staff Workspace. The `/admin` route is the technical route for the Staff Workspace; staff should not be instructed to look for a separate product called “T.A.L.A. System.”

When a browser request cannot be completed, the error page preserves the real HTTP status and gives a safe recovery step:

| Status | Meaning in plain language | Operator or user response |
| --- | --- | --- |
| `403 Access not allowed` | The account is authenticated or known but lacks permission for the requested surface | Use the workspace assigned to the account's role; contact the responsible office if access should exist |
| `404 Page not found` | The link is wrong, moved, or points to a record no longer available | Reopen the item from TALA navigation rather than repeatedly using the old link |
| `419 Session expired` | The protected browser session or CSRF token is no longer valid | Return to TALA, sign in again, and repeat the action once |
| `429 Too many requests` | Protective request limiting is active | Pause before retrying; do not repeatedly refresh or submit |
| `500 Something went wrong` | An unexpected application error prevented completion | Retry once; if it persists, record the attempted action and contact the system administrator |
| `503 Service temporarily unavailable` | Maintenance or a service interruption is preventing access | Wait a few minutes and retry |
| Other `4xx` or `5xx` | A safe family-level fallback handled the error | Follow the page's return and escalation guidance |

The HTML pages intentionally do not display raw exception messages, paths, queries, or stack details. This does not conceal operational evidence from administrators: server-side logging remains authoritative. JSON/API callers continue to receive Laravel's JSON error response, and Livewire or Filament validation remains attached to the form or action that produced it.

#### 5.9.1 Change-control classification

| Finding | Classification | Disposition |
| --- | --- | --- |
| The three registered panels, logo, palette, Auth Designer integration, and native Filament feedback patterns already work | Aligned | Preserved |
| `/admin` displayed `T.A.L.A. System` while the PRD and UI blueprint call it Staff Workspace | Defect / real gap | Renamed only the Admin panel brand to `TALA Staff Workspace` |
| No application-owned browser error templates existed | Required but unbuilt | Added the Laravel status-specific and fallback templates with one shared accessible layout |
| A custom theme, global exception-response rewrite, or mass domain-resource restyling | Cosmetic preference or unjustified expansion | Not introduced; later D4 slices retain their domain ownership |

#### 5.9.2 Likely panel questions

| Question | Defense-ready answer |
| --- | --- |
| Why customize error pages? | A production-facing SIS must tell a user what happened and what to do next without exposing internal diagnostics or sending them to an unrelated framework page. |
| Do the custom pages change API behavior? | No. They customize browser HTML views through Laravel's documented error-view convention. Laravel still negotiates JSON responses for API clients. |
| Why is the staff route still `/admin`? | The route is an internal technical identifier. `TALA Staff Workspace` is the user-facing identity shared by Registrar, Accounting, Faculty, Academic Head, and System Super Admin roles. |
| Where are detailed failures investigated? | Users receive safe recovery guidance; authorized operators investigate server logs and the owning workflow's audit or operational records. |

#### 5.9.3 Programmatic evidence

`TAL96D4ASystemUxFoundationTest` passes 12 scenarios with 84 assertions. It covers the six named statuses, both fallback families, a genuinely unmatched route, safe HTML content, the recovery action and static stylesheet, preserved JSON negotiation, the canonical Admin panel brand, and contrast thresholds for actions and keyboard-focus indicators in light and dark themes. The three directly affected public-auth, role-aware landing, and panel-access regression files pass 53 tests with 101 assertions against the proven `APP_ENV=testing`, MySQL, `test_tala_db` target. Blade template compilation, Laravel Pint, scoped PHPStan/Larastan, Serena diagnostics, and `git diff --check` also pass. No schema, domain workflow, integration, deployment, dependency, or external-service behavior changed; consolidated visual acceptance remains owned by TAL-96D5B.

### 5.10 TAL-96D4B Grades and Student Lifecycle hardening

TAL-96D4B retained the established grade, hold, lifecycle, and graduation rules. The correction is intentionally presentation- and reachability-focused: it does not change the grading formula, lifecycle transaction behavior, role ownership, or Student Hub release boundary.

| Finding | Classification | Disposition |
|---|---|---|
| Faculty rosters lacked sufficient course, section, term, status, and completion context | Defect / real gap | Added roster identity, plain-language state, final-grade completion count, clearer submission consequence, and mobile stacking. |
| Registrar grade actions were spread across a wide row and internal states were exposed | Defect / real gap | Kept the same authorized actions in one responsive action group and formatted state labels for staff. |
| Lifecycle forms showed enrollment and subject database IDs and the record view printed raw impact JSON | Defect / real gap | Added recognizable selectors and a structured immutable impact section covering affected subjects, schedule assignments, reservations, status, finance, COR, and master-schedule effect. |
| Graduation member search preloaded only the first 100 Student Profiles | Defect / real gap | Replaced the fixed list with query-backed search by student number or name; snapshot visibility and refresh authorization are unchanged. |
| Grade, lifecycle, graduation, and directly owned Student tables could hide actions or meaning on narrow screens | Defect / real gap | Applied native Filament mobile stacking and plain-language labels only within the D4B vertical. |
| A visual redesign of aligned domain workflows | Cosmetic preference | Not performed. Broader Student Hub and cross-role presentation remain D4C and D4D. |

#### 5.10.1 Guarded acceptance-state overlay

The operator command below adds representative grade-roster states, active and resolved holds, two lifecycle decisions, and complete and blocked graduation projections to an already seeded acceptance scenario:

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
php artisan acceptance:seed-tal96d4b-states
```

The command refuses any environment outside testing, MySQL, and `test_tala_db`. It requires an existing acceptance baseline and is safe to repeat: fixed source references and a fixed completion-batch name update the same synthetic records. To keep the Program Shift example truthful to the existing lifecycle rules, it also creates or refreshes one clearly named future, draft, acceptance-only academic year and term; the target curriculum belongs to a different program and includes a representative credit-checklist row. It is not registered in `DatabaseSeeder`, does not select or replace MIN, MIDDLE, or MAX, and does not run CP-SAT. Replacing the persistent acceptance scenario remains a separate destructive, human-gated operation.

#### 5.10.2 Consolidated manual-acceptance handoff

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D4B-GR-01 | Faculty — `faculty.demo@example.test` | Draft and returned rosters | Select each roster, review context, enter grades, and attempt submission. | Course, section, term, status, and progress are understandable; incomplete or invalid values receive clear guidance. | Valid saves and submissions retain the existing grade-service transitions and audit evidence. | Leave one required grade incomplete and enter one invalid value; neither attempt may submit the roster. |  |  |
| D4B-GR-02 | Registrar — `registrar.demo@example.test` | Submitted, returned, late, and released rosters | Review the action menu and open one roster at phone and desktop widths. | Return, release, and late-authorization actions remain discoverable without clipped controls. | Only the selected authorized transition changes the roster; viewing does not mutate it. | Open a roster in a state that does not permit the chosen action; the action must be absent or rejected without mutation. |  |  |
| D4B-GR-03 | Student — `student.demo@example.test` | One released and one unreleased grade | Open Grades and compare the expected subjects. | The released grade is labeled plainly and visible; the unreleased grade is absent. | No grade or roster record changes during viewing. | Attempt a direct request for another student or an unreleased result; authorization and release rules must prevent disclosure. |  |  |
| D4B-LC-01 | Registrar — `registrar.demo@example.test` | Representative lifecycle records | Open the list and record detail; review selectors, current state, and recorded impact. | The student and affected records are recognizable; consequences are labeled rather than exposed as raw JSON. | Viewing preserves the immutable impact snapshot; an authorized action creates only its defined lifecycle result. | Select a student or target state that violates the lifecycle prerequisites; the action must explain the blocker and save nothing. |  |  |
| D4B-HO-01 | Registrar and Student — seeded verified accounts | Active and resolved holds | Compare the staff hold record with Student Hub > Holds. | Status, student-safe message, resolution, responsible office, and blocking meaning agree across roles. | Viewing creates no change; the existing hold status and scope remain authoritative. | Confirm that a resolved hold is absent from the active Student list and that the staff-only reason is never exposed to the Student. |  |  |
| D4B-GR-04 | Registrar and Student — seeded verified accounts | Complete and blocked graduation snapshots | Search for a student beyond the first 100, expose or hide the snapshot, then open Student Hub > Completion. | Search reaches the record; visibility follows the authorized action; result and required action are readable. | Snapshot versions, visibility state, and visibility audit remain authoritative. | Hide the snapshot or use a different Student account; unavailable or owner-mismatched completion evidence must not appear. |  |  |

#### 5.10.3 Likely panel questions

| Question | Defense-ready answer |
|---|---|
| Did D4B change how grades are calculated? | No. It clarified roster readiness, status, responsive actions, and student-safe labels. The configured grading profile and existing grade services remain unchanged. |
| Why is lifecycle impact stored but not displayed as JSON? | The immutable snapshot is retained for audit. The interface translates its stable fields into labeled operational consequences so staff can understand what changed without reading an internal data structure. |
| Can the Registrar find a completion candidate in a large student list? | Yes. The selector now performs query-backed search by student number and name instead of loading only the first 100 profiles. |
| Does the D4B overlay alter the scheduling benchmark fixture? | No. It is an explicit downstream overlay applied after a guarded acceptance baseline; it neither selects a population scenario nor invokes the solver. |

### 5.11 TAL-96D4C Student Hub, reports, generated outputs, and notification presentation

TAL-96D4C retains every authoritative source and permission boundary. It changes how existing evidence is explained and delivered: Student Hub distinguishes official records from computed guidance, reports remain fixed and role-scoped, official outputs share a coherent institutional presentation, and existing operational emails explain the next action and responsible office.

| Finding | Classification | Disposition |
|---|---|---|
| Student Dashboard mixed the official academic standing with a terse computed recommendation and internal blocker count/source identifiers | Defect / real gap | Kept both sources separate, labeled the Student Profile status as official, named the Registrar as the authority for any standing change, and replaced internal provenance with student-facing recovery guidance. |
| Student Profile printed code-style status values and Active Holds did not use the native narrow-screen stack | Defect / real gap | Converted status values to familiar labels and enabled native Filament mobile stacking without changing editable fields or hold rules. |
| Student Hub exposed both the owner-scoped priority notice and Filament's persistent database-notification control | Defect / real gap | Removed only the second notification-center control. Existing notification records and the priority resolver remain intact. |
| Reports used a fixed authorized catalog and safe export boundary, but the table was not mobile-stacked and CSV filenames/encoding were implementation-oriented | Defect / real gap | Preserved catalog, queries, filters, role matrix, sensitivity, allowlisted columns, formula protection, purpose, row count, and audit; added native mobile stacking, report-label filenames, and a UTF-8 byte-order mark for Excel compatibility. |
| COR, schedules, SOA, Billing Slip, and Payment Acknowledgement had inconsistent institution names, title hierarchy, dates, print controls, labels, and disclaimers | Defect / real gap | Normalized the configured institution identity and browser-print presentation, reused one output layout for schedules and finance outputs, and retained the authenticated builders and output-access evidence. |
| Schedule-release, schedule-revision, and payment-posting emails used different levels of action guidance | Defect / real gap | Standardized institution identity, responsible-office language, next-action guidance, and discrepancy recovery without changing recipients, triggers, queue semantics, or delivery evidence. |
| Adding a report builder, spreadsheet/PDF package, editable notification templates, or a new persistent notification system | Cosmetic preference or out of scope | Not introduced. |

#### 5.11.1 Consolidated manual-acceptance handoff

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D4C-SH-01 | Student — `student.demo@example.test` | Active profile with irregular standing and no posted balance | Open Dashboard and Profile at desktop and phone widths. | Official standing, computed recommendation, responsible office, balance, and status labels are distinguishable; no internal source ID appears. | No Student Profile, progression, finance, or notification record changes. | Compare a missing progression review or unavailable balance state; the page must explain the absence without inventing an official result. |  |  |
| D4C-SH-02 | Student — same account | Active and resolved hold examples | Open Dashboard and Holds at phone width. | The active hold effect and required action stack without horizontal clipping; resolved holds remain absent from the active widget. | Hold scope and status remain authoritative and unchanged by viewing. | Confirm that another student's hold and staff-only reason are never visible. |  |  |
| D4C-RP-01 | Registrar, Accounting, Academic Head, and System Super Admin — respective seeded accounts | Role-authorized fixed reports with at least one empty filter result | Change report, apply and clear filters, and inspect at desktop and phone widths. | Only authorized reports appear; title, description, columns, status badges, filter scope, and empty state remain understandable. | Viewing and filtering do not mutate source records. | Attempt a report outside the role's catalog and an empty filter combination; access must be denied or absent, and the empty result must remain explanatory. |  |  |
| D4C-RP-02 | Accounting or another authorized exporter — respective seeded account | Sensitive report with a known formula-like name value | Export once without a purpose, then with a valid purpose; open the CSV in a text editor and Excel. | Missing purpose is rejected; the filename is readable; headings are ordered; characters display correctly; formula-like cells are inert. | One successful `REPORT`/`EXPORT` log records actor, filters, purpose, sensitivity, and exact row count. | Use an unauthorized role or omit the purpose; no file or successful export log may be produced. |  |  |
| D4C-OUT-01 | Student, Registrar, Accounting, and Faculty — respective seeded accounts | Current official enrollment, published schedule, active assessment, and posted payment | Open COR, schedule, SOA, Billing Slip, and Payment Acknowledgement; use Print / Save as PDF. | Institution identity, title, copy context, date, labels, disclaimers, and print controls are coherent; wide tables remain usable. | Each route retains existing authorization and output-access logging. | Use the wrong owner or a state with no current official record; private output must remain denied or show a truthful empty state. |  |  |
| D4C-NO-01 | Student or Faculty test recipient — respective seeded account | Existing schedule release/revision event or posted-payment notification | Render or receive each existing queued email. | Institution identity, changed fact, next action, responsible office, and discrepancy guidance are explicit. | Recipient, trigger, queue, idempotency, and `operational_events` evidence remain unchanged. | Repeat the same triggering event or inspect a failed delivery; duplicate domain effects must not occur and the operational state must remain traceable. |  |  |

#### 5.11.2 Likely panel questions

| Question | Defense-ready answer |
|---|---|
| Why is there no bell-style notification center in Student Hub? | The MVP already has one prioritized, owner-scoped notice surface. A second persistent center duplicated the same database notifications without adding a separate product requirement, so it was removed while the records and priority projection were preserved. |
| Can staff design arbitrary reports? | No. TALA exposes a fixed catalog with role authorization, controlled filters, and allowlisted columns. Analysis and pivoting happen outside TALA so sensitive fields cannot be added ad hoc. |
| Why does a sensitive CSV require a purpose? | The export contains controlled student, finance, staff, or audit information. Recording the business purpose with actor, filters, row count, and sensitivity makes the access defensible and auditable. |
| Is the payment acknowledgement an official receipt? | No. It confirms a verified payment was posted to the TALA ledger and states the OR-mapping status. The Accounting Office remains responsible for the official receipt record. |
| Did the shared document presentation change COR, schedule, or finance calculations? | No. Existing authenticated builders still select and calculate the records. The change standardizes only institution identity, labels, layout, print controls, and disclaimers. |
| Does email prove that a payment or schedule action succeeded? | No. Email is a queued delivery projection. The authoritative schedule publication, payment, ledger, and operational-event records remain the proof. |

#### 5.11.3 Programmatic evidence

The named `TAL96D4CStudentHubReportOutputPresentationTest` covers the Student-panel notification-center boundary, plain-language Student Hub guidance, mobile-stacked holds and reports, readable UTF-8 CSV generation, the shared official-output layout, and consistent operational email guidance. Direct TAL-70, TAL-75, TAL-88, TAL-91, TAL-94, TAL-95, and TAL-96D3C regressions verify that authorization, current-record selection, report scope, output logging, queueing, and idempotency remain intact. The independent focused verification gate passed **130 tests with 1,464 assertions** against `APP_ENV=testing`, MySQL, `test_tala_db`. Pint, Blade compilation, scoped PHPStan/Larastan, and `git diff --check` passed. Serena reported no diagnostics in the report exporter or Student panel provider and only its known Intelephense `auth()->user()` helper false positives in Filament pages/widgets; Larastan resolved the same files without error. Consolidated visual and interactive execution remains deferred to TAL-96D5B.

### 5.12 TAL-96D4D public entry, static diagnostics, and cross-role consistency

TAL-96D4D preserves the existing public routes, published FAQ source, three authenticated workspace boundaries, role authorization, and official-record builders. It closes the final shared presentation gaps before consolidated acceptance: the landing page now explains which workspace a visitor should use without relying on animated text or placeholder imagery, and official-output styles are statically owned by their shared component rather than injected into a CSS block through Blade.

| Finding | Classification | Disposition |
|---|---|---|
| The hero ended in a placeholder-style panel that named the workspaces without explaining their distinct users or purposes | Defect / real gap | Replaced the placeholder presentation inside the same approved hero with a concise three-workspace access guide and lifecycle context. |
| Section headings were visually generated by JavaScript while their literal text was hidden | Defect / real gap | Rendered every heading as semantic visible HTML and removed the typewriter timers so content remains complete without JavaScript and under reduced-motion preferences. |
| Repeated blur elements inflated every card and action without conveying state or meaning | Defect / real gap | Simplified buttons, cards, accordions, and the scroll control while retaining the approved navigation backdrop, brand palette, and bottom blur strip. |
| The landing page had no direct keyboard skip target and wide Bootstrap gutters overflowed a phone viewport | Defect / real gap | Added a visible-on-focus skip link and main landmark, explicit focus treatment, motion-safe scrolling, responsive gutters, and verified no horizontal overflow at 375-pixel and 1,440-pixel viewports. |
| The shared official-output component interpolated a Blade named slot directly inside a CSS block | Defect / real gap | Moved the finite schedule and finance rules into the component's static stylesheet and removed the two named style-slot consumers; rendered output calculations and authorization remain unchanged. |
| Applicant, Student, and Staff panels already used the same TALA logo and primary color with role-specific workspace names | Aligned | Preserved the panel configuration and added a focused regression assertion. |

#### 5.12.1 Consolidated manual-acceptance handoff

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D4D-PUB-01 | Public visitor | None | Open `/` at desktop width and follow the page from the hero through FAQ. | Institution purpose, three workspace boundaries, Apply and Sign In choices, location, About Us, and published FAQ are understandable in reading order. | Viewing creates or changes no application, account, FAQ, or school record. | Inspect an empty published-FAQ state; the page must provide truthful help guidance without placeholder answers. | PASS | The complete landing journey passed. Post-pass annotations found the Access Guide numerals visually offset because a broad span rule overrode the badge's flex display and the location CTA placed directly against the preceding copy. The selector was narrowed so all three numerals remain centered, while the location link now uses an explicit content-action container; neither correction changes the approved information architecture, card, or navigation design. |
| D4D-PUB-02 | Public visitor using a keyboard | None | Press Tab from the top, activate the skip link, then navigate the menu, workspace actions, accordions, and scroll control. | Focus is visible, the skip link moves to main content, every control has an accessible name, and headings remain visible without animation. | Navigation and accordion expansion are presentation-only. | Enable reduced motion and repeat the path; content and focus order must remain available. | PASS | Keyboard order, skip link, focus visibility, controls, accordions, and reduced-motion behavior passed. |
| D4D-PUB-03 | Public visitor | Browser widths near 375, 768, and 1,440 pixels; light and dark system themes | Inspect the hero, collapsed navigation, workspace cards, map, accordions, footer, and bottom strip. | Content reflows without horizontal clipping; contrast and focus remain clear; reduced motion does not hide content. | Theme and viewport changes do not alter application data. | Rotate or resize through the breakpoints; navigation and actions must remain reachable without clipped text. | PASS | Responsive reflow, navigation reachability, theme presentation, and reduced-motion behavior passed at the representative widths. |
| D4D-PUB-04 | System Super Admin — `system-admin.demo@example.test`; public visitor | One published and one unpublished FAQ | Edit publication/order through the existing FAQ resource, then open `/`. | Only published FAQs appear in configured order; the empty state explains where to obtain help. | Authorized edits change only `FaqEntry`; the public landing remains read-only. | Unpublish all entries and inspect `/`; unpublished content must remain absent and no static fallback answer may appear. | PASS | Create, append, drag-and-drop order, publish/unpublish, and empty-state behavior passed. Public questions now also display their existing configured category as a presentation-only label; ordering remains `sort_order`, then `id`. |
| D4D-XR-01 | Applicant, Student, and authorized staff — respective seeded accounts | Activated account for each panel | Compare landing workspace names with each login page and authenticated header. | TALA identity, blue primary color, and role-specific names remain coherent; no page suggests that one account type belongs in another workspace. | Authentication, panel access, and role policies remain unchanged. | Attempt the wrong panel with each account type; the system must deny or route safely without exposing another workspace. | PASS | Wrong-panel denial and recovery passed. The account-switch confirmation intentionally appears only for an authenticated wrong-workspace `403`; unrelated `404`, `419`, `429`, `500`, and `503` pages retain their own safe home recovery. `/` is the configured canonical public home; no unsupported `/home` alias was added. |
| D4D-OUT-01 | Student or authorized staff — respective seeded account | Existing current schedule or Statement of Account | Open and print the schedule and SOA. | The shared official-output presentation and document-specific schedule/finance layout appear without raw CSS or parser text. | Existing builders, authorization, and output-access logging remain authoritative. | Open an unavailable or unauthorized output; the system must deny it or present a truthful empty state without leaking source data. |  |  |

Batch 1 remediation passed 50 focused PHPUnit/Livewire scenarios with 355 assertions across FAQ management, public/landing presentation, shared branded errors, Applicant Workspace, and the affected admissions hardening coverage. Pint, scoped PHPStan, JavaScript syntax checking, the production asset build, Serena diagnostics review, and `git diff --check` passed. Serena reports one pre-existing Intelephense-only type warning for the Filament Wizard submit-action view; Larastan accepts the changed Application page. The focused database tests temporarily reset `test_tala_db`, so the already approved `S0-middle.sql` checkpoint was restored afterward; the read-only MIDDLE inspection again reports 270 students, nine cohorts, 80 ready scheduling demands, and `readiness=PASS`. The four affected visual rows above remain user-led and are not marked complete by automated proof.

The Batch 1 visual follow-up passed 42 focused tests with 340 assertions across Applicant Workspace, FAQ management and landing presentation, shared HTTP errors, and the Student profile custom-action surface. It also passed Blade compilation, Pint, Serena Blade diagnostics, and `git diff --check`. The first attempted red test exposed a stale cached configuration that still named `tala_db`; execution stopped, the configuration cache was cleared, and all subsequent database-backed checks explicitly proved `APP_ENV=testing`, MySQL, and `test_tala_db`. The refresh-based focused tests then left the acceptance database empty, so the already approved `S0-middle.sql` checkpoint was restored through the configured MySQL login path. The final inspection again reports `scenario_state=complete`, 270 students, nine cohorts, 14 synthetic faculty, 80 ready demands, and `readiness=PASS`.

The final Access Guide alignment correction passed one focused regression with 14 assertions. The test now proves that the broad summary-span selector excludes `.workspace-number` and that each badge retains explicit width, flex display, and centered justification. The subsequent read-only MIDDLE inspection remained complete and ready; no checkpoint restore was needed.

The final location CTA-spacing correction passed one focused regression with five assertions. The public template now places the Google Maps link inside a semantic content-action container with explicit separation from the preceding copy, and the test rejects a global button margin that could disrupt navigation or grouped actions. The subsequent read-only MIDDLE inspection still reports 270 students, nine cohorts, 14 synthetic faculty, 80 ready demands, and `readiness=PASS`.

The D2A-M02 fixture and guardian-address correction passed 95 affected tests with 915 assertions across the legacy MIN baseline, parameterized scenarios, admission-policy configuration, Applicant Wizard, submission, Applicant Workspace, and admissions-window/withdrawal behavior. Pint, scoped PHPStan, refreshed Serena diagnostics, and `git diff --check` passed. The already recorded Intelephense-only Wizard submit-action view warning remains pre-existing and is accepted by Larastan. Under the approved compound gate, the previous database state and checkpoint were retained outside Git, then `test_tala_db` was rebuilt to exact `MIDDLE`; its read-only manifest reports 270 students, nine cohorts, 14 synthetic faculty, 80 ready demands, ten admission policies, and `readiness=PASS`. The affected D2A-M02 browser rerun subsequently passed and produced the separate lifecycle findings addressed below.

#### 5.12.2 Applicant lifecycle, history, and retention correction

The affected D2A-M02 rerun passed, then exposed a separate lifecycle question: a withdrawn intake remained the account's only application forever even when a later Admissions term opened. The verified correction preserves the existing Wizard, policy-driven uploads, review, withdrawal audit, and handover services while separating the current application from retained history.

| Finding | Classification | Verified disposition |
|---|---|---|
| A single ambiguous `HasOne`/`first()` assumption prevented a later-term application after withdrawal | Defect / real gap | An applicant now owns many intake records, but may have only one nonterminal intake at a time and only one intake per academic term. |
| A withdrawn draft was described as submitted and its missing checklist looked like a Registrar configuration failure | Defect / real gap | The Applicant Workspace now states `Withdrawn before submission` and explains that no Registrar checklist was created. A genuinely submitted withdrawn intake retains its real checklist and evidence history. |
| The Dashboard mixed current status, next action, and withdrawal detail across a two-column layout | Defect / real gap | The Applicant Dashboard now uses one vertical reading order: current/no-active state, next action, retained history, then current requirement evidence. The compact history row excludes the free-text withdrawal reason; authorized View detail contains it. |
| Opening an existing draft immediately resumes it | Aligned | Direct resume is preserved. A saved-draft and last-saved cue was added; no extra confirmation modal interrupts a deliberate navigation action. |
| Applicant records and evidence have retention categories but no approved institution-specific period | Aligned with disclosed limitation | Withdrawal soft-archives the intake and preserves authorized audit history. TALA does not invent a countdown or delete the account. Applicant-specific retention periods, disposal processing, automated expiry, and physical purge are not implemented; exact periods remain institutional policy and any approved automation remains TAL-98 work. |

The applicant account remains usable after withdrawal. A new draft may be created only for a different term whose Admissions window is open; creation moves the account back to the active applicant state in the same transaction. A same-term retry is stopped with Registrar guidance instead of silently producing a duplicate. The database independently enforces one intake per applicant and term. A withdrawn record is immutable and cannot be resumed or edited into a new application.

##### Affected manual acceptance

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D2A-H01 | Applicant — withdrawn synthetic account | One withdrawn draft with a recorded reason; no current intake | Open Dashboard, inspect the history row, select View, then open Requirements. | Dashboard says no active application; history says Withdrawn before submission; View shows date and reason; Requirements says no Registrar checklist was created. | No record changes. | The compact history row must not expose the free-text reason or call the draft submitted. |  |  |
| D2A-H02 | Applicant — same account | H01 plus a different active term with an open Admissions window | Select Start Application, complete the minimum draft scope, and save. | A saved-draft cue appears; Dashboard shows the new current draft above the retained history. | One new draft is created for the later term and the applicant account returns to Pending. The withdrawn intake is unchanged. | Confirm only one nonterminal intake exists. |  |  |
| D2A-H03 | Applicant — same account | H01; the withdrawn term may be open or closed | Attempt to create another application for the same term. | A clear message directs the applicant to the Registrar. | No second same-term intake is created. | Repeat the request; the database applicant-term contract must still prevent a duplicate. |  |  |
| D2A-H04 | Applicant with a current saved draft | Current draft with incomplete fields | Open My Application directly. | The draft opens without a confirmation modal and shows the last-saved cue. | No record changes until Save Draft or Submit is selected. | Close and reopen; saved values must remain. |  |  |
| D2A-H05 | Applicant — desktop and narrow viewport | One current draft and at least one history row | Compare Dashboard at representative desktop and phone widths. | Current/no-active state, next action, Application History, and current requirements remain in the same vertical order without clipped controls. | No record changes. | Withdrawal reason remains detail-only at both widths. |  |  |
| D2A-H06 | Applicant and Registrar — respective synthetic accounts | One withdrawn submitted intake with checklist/evidence and one withdrawn draft | Compare Applicant history/Requirements with Registrar detail and Audit Logs. | Each surface uses the correct submitted-versus-unsubmitted wording; submitted evidence remains available only to authorized roles. | No record changes. | Another applicant must not see either history record; ordinary staff lists must not expose the free-text reason. |  |  |

The primary programmatic gate passed **60 tests with 389 assertions** across Applicant Workspace, intake submission, D2A admissions hardening, Admissions-window and withdrawal behavior, and the new lifecycle/history cases. The checks ran against the explicitly proven `APP_ENV=testing`, MySQL, `test_tala_db` target. The applicant-term unique migration was applied only to that testing database after a read-only duplicate check returned no conflict. Pint, Blade compilation, scoped PHPStan/Larastan, Serena diagnostics review, and `git diff --check` passed. Serena retains only its previously recorded Intelephense-only storage `mimeType()` and Wizard submit-view warnings; Larastan reports no error for the same files. A final read-only MIDDLE inspection remained complete and ready with 270 students, nine cohorts, 14 synthetic faculty, 80 ready scheduling demands, and ten admission policies; no CP-SAT solve ran and no scheduling or Cloud contract changed. This evidence does not mark D2A-H01 through D2A-H06 as manually passed.

#### 5.12.3 Admissions validation, requirement clarity, and status delivery

The next bounded admissions pass addressed the gaps observed while completing D2A-M02 without collapsing the Applicant Dashboard, My Application, and Requirements into one page. Those three surfaces remain intentional: Dashboard explains the current state and next action, My Application owns draft or submission input, and Requirements owns the Registrar checklist and correction loop.

| Finding | Classification | Verified disposition |
|---|---|---|
| Wizard steps displayed required markers but allowed incomplete required data to advance | Defect / real gap | Required personal and digital-upload fields now block Wizard progression. Save Draft temporarily relaxes only completeness requirements, while still validating any value the applicant supplied. |
| Invalid draft values could stop the loading action without a useful visible result | Defect / real gap | Draft validation now returns field-level errors and a persistent danger notification; no invalid draft record is written. |
| Contact and education wording made `Prior School` appear to belong to the guardian | Defect / real gap | Parent or guardian contact is separated from Applicant Education, where the field is named `Most Recent School Attended`. |
| The same-address shortcut copied text but left the derived value editable | Defect / real gap | The copied guardian address remains submitted but read-only while the shortcut is selected. Clearing the shortcut restores the independent value instead of leaving an ambiguous derived address. |
| Philippine contact inputs accepted structurally invalid numbers until service submission | Defect / real gap | Applicant and guardian contact numbers use one consistent rule at both form and service boundaries: exactly 11 digits beginning with `09`. |
| Raw checklist constants and generic evidence labels did not explain what the applicant or Registrar must do | Defect / real gap | Requirement type, evidence method, workflow effect, item status, and verification status use human-readable labels. Physical items say `Bring to the Registrar`; metadata-only items explicitly require no upload. |
| Critical application changes existed only as in-app state | Defect / real gap | A transition to `Action Required` and an approval for handover each queue one after-commit email, create an idempotent `OperationalEvent`, and direct the applicant to the appropriate authorized workspace. Submission acknowledgement email remains outside this approved boundary. |

The focused programmatic gate passes **66 tests with 460 assertions** across intake submission, Applicant Workspace, policy-driven multi-upload and correction, Admissions-window and withdrawal rules, lifecycle/history, Wizard validation, requirement-language projection, and idempotent status email delivery. Scoped Larastan/PHPStan, Pint, and `git diff --check` pass. Serena reports no new diagnostics in the added status-notification classes, mail, models, or Blade views; its existing Intelephense-only Filament submit-view, authentication helper, and storage-adapter warnings remain accepted by Larastan. This evidence strengthens D2A-M02 and the Registrar review cases but does not mark their user-led visual rows passed.

#### 5.12.4 Likely panel questions

| Question | Defense-ready answer |
|---|---|
| Why are there three workspaces instead of one login for everyone? | Applicant identity exists before official student handover, while Student and Staff accounts expose different protected records and actions. Separate entry points make that authorization boundary understandable; backend policies still enforce it. |
| Does the landing page contain its own academic or admissions rules? | No. It explains access and public guidance. Authenticated workflows and their authoritative records remain in the Filament panels, while published FAQ records supply the managed public answers. |
| Will the landing page still work when JavaScript is disabled? | The content, headings, links, workspace explanation, and FAQ text are server-rendered. JavaScript adds only Bootstrap collapse behavior, navbar presentation, theme reaction, and the scroll shortcut. |
| Did the landing redesign add another frontend framework to the application? | No. It refines the already isolated local Bootstrap 5.3 landing assets. Bootstrap is not loaded into the Filament panels, and no dependency was added. |
| Did fixing the stylesheet warning change the schedule or finance calculations? | No. The warning came from injecting a finite Blade slot into a CSS block. Those static rules now live in the shared layout; the schedule and finance builders, records, permissions, and calculations did not change. |

#### 5.12.5 Programmatic and bounded rendered evidence

The named `TAL96D4DLandingAndCrossRolePresentationTest` verifies semantic public-entry content, role links, published FAQ ordering, the static official-output stylesheet contract, the preserved progressive navbar/bottom-edge blur, the original navbar spacing and light-section theme transition, and shared panel branding. Existing `PublicLandingAndFilamentAuthTest` and `TAL96D4CStudentHubReportOutputPresentationTest` remain direct regressions. The primary D4 cross-slice gate passed **30 tests with 256 assertions** against `APP_ENV=testing`, MySQL, `test_tala_db`; the navbar-restoration D4D/public-auth rerun passed **10 tests with 85 assertions**. Blade compilation, JavaScript syntax checking, the Vite production build, Pint, scoped Larastan, first-party static scans, Serena diagnostics, and `git diff --check` passed. A bounded rendered check at 375 by 812 and 1,440 by 900 confirmed the responsive navigation and hero, three workspace cards, literal heading structure, main landmark, FAQ surface, absence of horizontal overflow, and an empty browser error/warning log. The complete cross-role visual walkthrough remains user-led in TAL-96D5B.

## 6. Implementation-Validity Audit and Required-Gap Routing

D1 compares the PRD, database contract, current actions, tests, Git history, and rendered surfaces. A gap listed here is not evidence that the whole application is wrong. It identifies the smallest boundary that must be corrected or proved in its owning vertical slice.

| Area | Required behavior | Current evidence | D1 decision |
|---|---|---|---|
| Institution capacity | No unsupported universal institutional maximum | The rejected 100-student sentence existed only in documentation; no matching production rule or database field was found. | Corrected the PRD. The client population of 47 remains a scale anchor, not a ceiling. |
| Section and room capacity | Occupancy is controlled per published section and, for face-to-face delivery, by an adequate room | Sections and rooms carry capacity. Scheduling readiness rejects expected counts above section or suitable-room capacity. Enrollment confirmation locks records, checks current reservations and active bindings, and prevents the last seat from being double-consumed. | Aligned; retain and test adversarially in D3. |
| Applicant and student identity | Applicant intake remains distinct until approved handover creates the Student Profile | The handover action creates a regular active Student Profile; continuing students already use the Student domain. | Aligned. An irregular continuing student does not revert to Applicant status. |
| Applicant evidence and handover | Applicants own truthful submission and permitted correction; an active Registrar owns review, private evidence access, approval, and explicit handover | D2A tests prove declaration and active-scope checks, private digital evidence, physical receipt recording, correction history, stale and wrong-role denial, download auditing, blocker enforcement, first-time/transfer/returning handover, and transaction rollback. | Programmatic pass after bounded D2A corrections; user-led visual and interaction acceptance remains pending. |
| Academic-state vocabulary | Academic standing uses the nine PRD values independently of lifecycle, enrollment, and finance | The previous fixture used the retired `GOOD_STANDING` value for all 47 students even though progression services use the nine PRD values. | Corrected the fixture, exact-completeness check, inspection evidence, and legacy constant. |
| Regular scheduling | Cohort demands are solved, reviewed, and published before student schedule bindings | The client baseline produces six cohort identities and 54 ready demands. Solver generation and publication services are separate from enrollment placement. | Structurally aligned; the populated cross-role journey remains D3 work. |
| Irregular selection | Student choices must come from published compatible offerings and pass prerequisite, corequisite, unit, conflict, and capacity rules before Registrar confirmation | Student Enrollment now lists only curriculum/progression-eligible active sections backed by published meetings. A Student proposal is stored per Course Enrollment without a reservation or binding. Staff confirmation rechecks publication, lifecycle, unit load, conflicts, and capacity under row locks. | Corrected in D3B. Do not rerun the master solver for an individual irregular student; create or revise an offering only through the separate scheduling workflow. |
| Enrollment calendar | The configured term calendar must control when enrollment and edits are available | Continuing-start, proposal, complete confirmation, and reservation deadline now use active institution-scoped `CalendarEvent` `WINDOW` records for the `enrollment` process. Missing and closed windows produce distinct failures. | Corrected in D3B. The calendar record, not retired Term columns or a hard-coded waiting period, owns timing. |
| Student Hub standing explanation | The student must be able to distinguish the official stored standing from a newly computed progression recommendation | The irregular anchor and computed review remain separate sources. TAL-96D4C labels the Student Profile standing as official, explains the computed review in plain language, and names the Registrar as the authority for any change. | Corrected in D4C without overwriting either source record. |
| Downstream completeness | Schedules, enrollment, finance, COR, grades, and lifecycle effects must be created by their real workflows | D1 deliberately leaves their tables empty and exposes the counts through the read-only command. | Correct D1 boundary. Build each state through D2-D4, then perform combined adversarial acceptance in D5. |
| Navigation clarity | Users should find role-relevant work without scanning unrelated features | Registrar browser evidence shows broad authorized navigation. | Review grouping and labels in D2-D4 using native Filament patterns; do not change backend ownership only to shorten the menu. |

### 6.1 External implementation comparison

The operating order and capacity position are consistent with established system documentation:

- Oracle PeopleSoft Campus Solutions requires academic calendars, course catalog data, facilities, and related setup before scheduling classes. It defines enrollment capacity at the class-section level and separately compares that capacity with requested-room and facility capacity. It then applies requisites and capacity rules when enrollment requests are processed. See [Oracle: Scheduling New Classes](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/scheduling-new-classes.html) and [Oracle: Understanding Class Enrollment Processing](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/understanding-class-enrollment-processing.html).
- UniTime describes course timetabling as placing classes while respecting student demand, faculty availability, rooms, and other constraints. This supports TALA's separation between producing the master timetable and later binding individual students to published sections. See [UniTime: Course Timetabling](https://help.unitime.org/course-timetabling) and [UniTime: Course Timetabling Solver Manual](https://help.unitime.org/manuals/courses-solver).
- The qualified local Academico checkout seeds linked students, periods, courses, rooms, teachers, schedules, and enrollments, then exposes staff enrollment from available course records. This supports TALA's use of relationally complete, repeatable acceptance fixtures and role-based journey checks. Academico serves a different school model and does not contain TALA's curriculum progression, CP-SAT, enrollment-gate, or reservation contract, so it is not product authority and its code is not copied.

These references are comparison evidence, not authority to copy another product's data model. TALA's approved PRD remains the product authority.

### 6.2 Defense questions closed by D1 and D2A

| Question | Defensible answer |
|---|---|
| What is the client's maximum capacity? | The supplied evidence reports 47 current students; it does not establish a maximum. TALA therefore has no universal institution-wide ceiling. Capacity is controlled per section and physical room, with additional offerings created when approved demand requires them. |
| When does an irregular student receive a schedule? | After compatible sections are published and the enrollment window permits selection. The Student proposal does not hold a seat. Registrar confirmation atomically creates the reservation and active schedule bindings. |
| Is an irregular continuing student an applicant while waiting? | No. The person remains an active Student Profile. Applicant status applies only before accepted applicant handover. |
| Must the solver run again for every irregular student? | No. The student selects from compatible published sections. A solver rerun is needed only when the institution approves an additional or revised offering that changes the master schedule. |
| Is delivery modality a student type? | No. Modality belongs to the term offering or published meeting. A student's schedule may contain more than one modality. |
| Why does the application ask for a modality preference? | It is informational guidance for admissions staff and is limited to Face-to-Face or Online. It does not override offering modality, create a separate student schedule, or change the CP-SAT contract. |
| Why are applicant documents separate upload fields instead of one combined file? | Effective admission policies determine which evidence applies. Separate private files let the Registrar accept, reject, replace, version, and audit each requirement without forcing the applicant to resubmit unrelated accepted documents. Physical and metadata-only requirements remain staff-tracked and never receive artificial uploads. |
| Does application approval automatically enroll the applicant? | No. Approval confirms the admissions decision. Explicit handover creates or reuses the official Student Profile and starts one pending Enrollment; later enrollment gates own section placement and confirmation. |
| Is a physical requirement represented by a fake uploaded file? | No. An authorized Registrar records physical receipt, actor, time, and an optional institutional reference before review. Digital evidence remains a private file with version history. |
| Can a returning applicant be merged into any similar profile automatically? | No. Reuse requires an active, unmerged returning-student profile and an explicit identity match, including birth date. Ambiguous or incomplete identity stops handover. |
| What happens if handover fails partway through? | The handover transaction rolls back its partial changes. A later downstream enrollment failure does not delete a Student Profile whose handover already succeeded. |
| Does D1 prove the entire system is defense-ready? | No. D1 proves the safe baseline, operating map, role access, starting-state evidence, and known-gap routing. D2-D4 must execute the vertical journeys; D5 performs combined adversarial acceptance. |

## 7. Audit Standard for Later Slices

Each later journey must answer all of the following before it can be marked ready:

1. What prerequisite record or state makes the action valid?
2. Which role owns the action, and which roles may only view its result?
3. What exact record is created or changed?
4. What happens when the user acts too early, supplies invalid input, repeats an action, loses connectivity, or lacks permission?
5. Does the interface explain the state, responsible office, next action, and irreversible consequence where applicable?
6. Is the same authoritative result presented consistently to affected staff and students?
7. Is there automated proof, browser proof, or an explicit stakeholder-validation requirement?
8. Is any sensitive evidence private, auditable, and excluded from logs or public URLs?

Prefer native Filament and Livewire behavior for forms, validation, tables, loading states, notifications, and responsive layout. Introduce a new component or dependency only when the existing stack cannot satisfy a verified requirement.

## 8. Defense Positioning

TALA should be presented as a production-level MVP with explicit operational boundaries, not as a claim that every institutional policy is universal. The defense should demonstrate that:

- the system starts from authoritative academic and identity records;
- invalid downstream work is prevented until prerequisites are ready;
- CP-SAT scheduling, enrollment, finance, and student outputs exchange structured records rather than isolated screenshots;
- every role sees only its permitted workspace and the state relevant to its responsibility;
- empty, pending, failed, and completed states are distinguishable and actionable;
- external integrations remain idempotent, traceable, and recoverable; and
- the test baseline is reproducible without overwriting edited or historically valuable acceptance evidence.

This document will be expanded with verified inputs, outputs, failure demonstrations, and presenter guidance as TAL-96D5B through TAL-96D5E complete.

## 9. TAL-96D5 Completion Readiness

TAL-96D5 is executed as five bounded sub-slices. D5A reconciles evidence and prepares acceptance; D5B performs agent-led programmatic convergence and produces one bounded final human smoke review; D5C runs the full regression, security, and integration gate; D5D performs the separately authorized population/configuration and cost study; and D5E consolidates verified facts for TAL-97 and retires the TAL-96D charter.

### 9.1 Charter coverage and disposition ledger

| Charter obligation | Current evidence | Current disposition | Remaining owner and blocking effect |
|---|---|---|---|
| Preserve aligned implementation and correct only proven gaps | Sections 5 and 6 record the D2–D4 classification and remediation evidence. | Aligned. D5A changes documentation only and identifies no new application defect. | A later observed failure opens only a bounded remediation for its owning journey. |
| Cover every material role and state | The 17-row cross-role matrix maps public, applicant, student, Registrar, Accounting, faculty, Academic Head, System Super Admin, and stakeholder journeys. The consolidated tables contain 69 retained case IDs. | D5B programmatically reconciled every case to a pass, an evidence-backed boundary, or a bounded final-smoke/external-gate step. | The final smoke checks only visual comprehension and responsive behavior. Cloud publication and PayMongo provider evidence remain separate human gates. |
| Answer the operating-order and defense questions | Sections 2, 5, and 6 contain the source facts, but the answers were previously distributed. | Documentation gap corrected by Section 9.2. | D5B confirms the visible explanations; a disagreement with the recorded product rule is a human gate. |
| Maintain reproducible `MIN`, `MIDDLE`, and `MAX` fixtures | Section 5.4 documents guarded commands, manifests, faculty-capacity distinctions, and fail-closed replacement. | Aligned. D5A uses only read-only `--check` commands. | D5B loads `MIDDLE` only after snapshot/rebuild approval. D5D evaluates all three disclosed workload tiers. |
| Use one complete acceptance-table contract | D2 and D3 already used the complete nine-column format; 18 D4 rows used a shortened six-column handoff. | Documentation gap corrected: retained cases have role/credential, prerequisites, steps/input, visible result, record/state change, invalid case, disposition, and observation evidence. | D5B consolidates only the irreducibly visual checks into Section 9.6 instead of creating another manual. |
| Verify authorization, invalid input, failure, retry, and recovery | Focused D2–D4 tests cover role denial, validation, transaction rollback, duplicate delivery, retries, terminal states, safe errors, and owner-scoped outputs. | D5B's broad local convergence gate is programmatically clean after one stale presentation expectation was corrected and rerun. | The bounded smoke confirms comprehension; D5C remains responsible for the full security and integration regression gate. |
| Report solver correctness, quality, duration, resources, and cost honestly | Section 5.5 explains valid optimization measures and distinguishes feasible, optimal, timed-out, and failed results. Historical profile evidence remains separately documented. | No benchmark claim is changed in D5A. | D5D owns new `MIN-CFG`, `TARGET-CFG`, and `MAX-CFG` evidence and its external/cost gates. |
| Keep architecture, formulation, and operating guide synchronized | The Guide owns journeys and operations; architecture and the standalone formulation own their respective verified technical boundaries. | No current contradiction is introduced by D5A. | D5E performs final synchronization only from D5B–D5D verified results. |
| Satisfy the completion criteria and retire the charter | D1–D4 are completed locally; D5 remains incomplete. | Ownership is made explicit in Section 9.5. | D5E is the only retirement boundary. |

### 9.2 Operating-order closure table

| Required question | Defense-ready answer | Evidence and current disposition |
|---|---|---|
| What must exist before each major journey can proceed? | Applications require an active intake scope, program, and effective requirement policy. Curriculum import requires the academic structure and accepted template. Offerings require an active academic period and approved curriculum records. Scheduling requires complete offerings, sections, groups, rooms where applicable, qualified faculty, availability, and an operating grid. Enrollment requires a Student Profile, open enrollment window, compatible published sections, progression facts, and required clearances. Assessment requires confirmed placement and fee rules. Payment requires an active assessment with a positive due. Grading requires official enrollment, roster creation, and assigned faculty. Progression and graduation require released academic results and their applicable clearance evidence. | Section 2 and the intended-flow subsections in Section 5. Programmatically verified; D5B confirms user-facing guidance. |
| What happens when an academic prerequisite record is missing or closed? | The action stops before downstream mutation and identifies the missing, closed, unpublished, incompatible, or incomplete source. A candidate schedule cannot substitute for publication, and a missing enrollment window cannot be bypassed through student or staff placement actions. | D2B, D2C, D3A, and D3B named tests; D2B-M01, D2C-M03, D3A-M01, D3B-M11. Programmatic pass; only the final visual wording sample remains in the bounded smoke. |
| When and where does an applicant become a student? | The person remains an Applicant through submission, review, correction, evaluation, and approval. An authorized Registrar's explicit handover transaction creates or reuses one Student Profile, assigns the Student role, and starts the initial pending Enrollment. Approval alone is not enrollment. | Section 5.2 and D2A-M08 through D2A-M10. Programmatic pass; the bounded smoke samples the Applicant and Registrar projections. |
| How do regular and irregular enrollment differ? | A regular student is placed into one complete published logical-cohort block. An irregular student proposes a complete set of compatible published sections; the proposal holds no seat. Registrar or System Super Admin confirmation rechecks the full set transactionally and creates reservations and schedule bindings. | Sections 2.1 and 5.6; D3B-M02 through D3B-M08. Programmatic pass; the bounded smoke samples both visible paths after publication. |
| Where does an irregular student remain while waiting, and what prevents invalid placement? | The person remains an active Student Profile with a term Enrollment, never an Applicant again. Selection waits for an open enrollment window and compatible published sections. Ownership, curriculum/progression eligibility, prerequisites, units, conflicts, publication, lifecycle, capacity, and row locks prevent invalid or partial placement. TALA does not invent a fixed waiting duration. | Sections 2.1, 5.6, and 6.2. Programmatic pass; the bounded smoke confirms that waiting and blocker guidance is understandable. |
| How are additional sections created and how is capacity determined? | The Registrar creates another Section under the relevant Term Offering and completes its delivery-group, faculty, room, and scheduling inputs. It enters the normal scheduling and publication workflow; adding a student does not rerun the solver by itself. Capacity is controlled by the configured Section, applicable face-to-face room capacity, published meetings, and confirmed active reservations or bindings—not an institution-wide ceiling. | Sections 3, 5.4, and 6; D2C-M02/M03 and D3B capacity cases. Aligned product rule and programmatic pass. Shared cross-program combined sections remain TAL-175. |
| What does each role see before and after a state change? | The acting office sees its source record, permitted action, current status, blocker, responsible office, and result. Affected users receive only authorized projections: Applicant status and requirements; Student enrollment, finance, COR, schedule, holds, grades, and completion; Faculty assigned schedule and rosters; Academic Head read-only academic oversight; Accounting finance and authorized COR evidence; System Super Admin operational integration evidence. Candidate, staff-only, private, and other-owner records remain hidden. | Section 4 maps every cross-role source and projection. D4 presentation tests pass; D5B validates comprehension and consistency. |
| What happens after invalid input, rejection, payment failure, duplicate delivery, queue delay, solver timeout, or a downstream transaction failure? | Invalid input produces field or action guidance and no unauthorized partial write. Rejected applicant evidence moves only that item to correction and preserves accepted evidence. A redirect, decline, cancellation, or expiry does not post payment. Signed webhook and reconciliation processing are idempotent, and delayed or failed local events remain visible for controlled recovery. A solver timeout or unknown result remains non-publishable unless a valid candidate passed independent Laravel validation; infrastructure failure is distinct from infeasible input. Transactional handover, placement, payment posting, and officialization roll back partial effects when their operation fails. | Sections 5.2, 5.5, 5.6, 5.7, and 5.8. Programmatic pass; D5B handles visible recovery and any authorized sandbox evidence, while D5C closes full regression. |
| Which results are provisional, which are official, and who finalizes them? | A submitted or approved application remains an admissions record until Registrar handover. A solver candidate remains provisional until Laravel validation and Registrar publication. A student proposal remains provisional and holds no seat until authorized staff confirmation. Checkout return or provider state is not official finance evidence until verified payment and ledger posting. Grades remain staff-controlled until authorized release. Progression and graduation projections remain controlled snapshots until the owning office releases or records the institutional result. | Sections 5.2, 5.5–5.8, and 5.10. Programmatic pass; the Cloud publication and PayMongo provider boundaries remain explicitly human-gated. |

### 9.3 Scenario and operator readiness

The D5A read-only inspection found no active acceptance scenario in `test_tala_db`. After the approved D5B snapshot-and-rebuild gate, the current acceptance state is the exact `MIDDLE` scheduling fixture plus the deterministic D5B operational overlay.

| Scenario | Read-only command | D5B disposition | Permitted next use |
|---|---|---|---|
| `MIN` | `php artisan acceptance:seed-scheduling-scenario MIN --check --no-interaction` | Manifest and guarded constructor retained; not loaded during D5B | Retain for current-client comparison and D5D `MIN-CFG`; do not use as the complete D5B acceptance fixture. |
| `MIDDLE` | `php artisan acceptance:seed-scheduling-scenario MIDDLE --check --no-interaction` | Loaded with 270 students, nine cohorts, 14 synthetic faculty, 80 offerings, 80 ready demands, and ten policies; the later `conflict` state is the expected downstream overlay signal | Current D5B acceptance fixture. It supplies all three programs and year levels without claiming to be a client census. |
| `MAX` | `php artisan acceptance:seed-scheduling-scenario MAX --check --no-interaction` | Manifest and guarded constructor retained; not loaded during D5B | Retain for D5D `MAX-CFG`; do not load during D5B unless a separately approved diagnostic requires it. |

The D5B operator must first prove `APP_ENV=testing`, MySQL, and `test_tala_db`, receive approval for the snapshot/rebuild operation, create exactly one `MIDDLE` scenario, rerun its `--check`, and stop if the command reports conflict before acceptance work begins. Once bounded operational records exist, the scenario command correctly reports `conflict`; that later result means the pristine population fixture is no longer empty of downstream evidence, not that its scheduling inputs were resized.

The guarded `php artisan acceptance:seed-tal96d5b-states --no-interaction` command adds deterministic operational states to the verified `MIDDLE` fixture. It reuses the D4B grade/lifecycle overlay and adds irregular-waiting, cancelled, assessment-due, partial-payment, finance-cleared, and local pending/failed payment-attempt examples. It is idempotent, never runs CP-SAT, and preserves the 270 students, 80 offerings, 80 scheduling demands, and 14-faculty scheduling input. Its local payment attempts are explicitly synthetic projection evidence; they do not claim PayMongo provider acceptance.

| State family | Named synthetic evidence | Disposition before final smoke |
|---|---|---|
| Applicant | `applicant.demo@example.test` plus named D2A validation, withdrawal, history, notification, and handover tests | Programmatic test evidence |
| Academic setup | `AY 2025-2026 / Second Semester`, 80 offerings, and 80 ready demands | Persistent MIDDLE fixture |
| Documents | D2A mixed digital, physical-copy, metadata, review, rejection, and replacement cases | Programmatic test evidence |
| Enrollment | `DBM-2A-001` irregular waiting; `DTHM-1A-001` cancelled; D4B official enrollments | Persistent overlay records |
| Finance | `DIT-1A-001` due; `DIT-1A-002` partially paid; `DIT-2A-001` finance-cleared | Persistent assessment, ledger, and manual-payment records |
| Payment integration | Synthetic local pending/failed attempts plus the bounded provider-backed attempt `16`, Payment `4`, Ledger Entry `20`, and duplicate webhook call `3` | PayMongo test-mode provider gate passed; preserve evidence for final smoke and D5E consolidation |
| Scheduling | MIDDLE 80-demand ready workload | Candidate generation and publication remain the separately approved one-time Cloud Run functional gate |
| Grades | Four D4B personas with Draft, Submitted, Returned, and Released rosters | Persistent overlay records |
| Lifecycle | D4B withdrawal, program-shift, holds, and graduation snapshots | Persistent overlay records |
| Failure and recovery | Named invalid, wrong-role, duplicate, rollback, idempotency, and retry tests | Programmatic test evidence |

The focused `TAL96D5BOperationalStateOverlayTest` proves the overlay is guarded, repeatable, and scheduling-preserving. It also verifies eight representative roles and ten materially different state families while keeping Cloud Run and PayMongo results honestly marked as human gates.

### 9.4 Restore-safe D5B execution lanes

| Lane | Required starting state | Cases and action | Checkpoint or human gate | Durable result |
|---|---|---|---|---|
| 0. Environment and fixture readiness | Empty guarded testing database | Prove the environment; inspect all three manifests; create and verify `MIDDLE`. | Human approval for snapshot/rebuild and persistent scenario creation. Record baseline checkpoint `S0` after exact `MIDDLE` verification. | One deterministic representative fixture. |
| 1. Public entry, identity, and workspace boundaries | `S0`; verified demo accounts | D4D public/accessibility/cross-role cases and D2A wrong-panel cases. | No restore unless account state is changed. | Entry and authorization observations only. |
| 2. Admissions and handover | `S0`; open Admissions window, active admission scope, and requirements | Execute the main D2A draft-to-handover path. Exercise closed-window behavior and withdrawal as separate terminal branches. | Run closed-window and withdrawal mutations from an isolated copy of `S0`, restore `S0`, then execute the main handover path. Never attempt handover and withdrawal on the same retained intake. | Main branch retains one handed-over Student Profile and initial Enrollment. |
| 3. Academic setup and scheduling preparation | Main branch plus complete MIDDLE academic sources | Execute D2B/D2C inspection and invalid-input cases without replacing authoritative fixture records; then generate demands. | Any destructive edit to a fixture source uses an isolated checkpoint and restore. One functional external solver call requires separate Cloud authorization; it is not a capacity benchmark. Record `S1` after valid publication. | One reviewed and published official schedule. |
| 4. Regular and irregular enrollment | `S1`; open enrollment window and published compatible sections | Execute D3B regular, irregular, blocker, capacity, replacement, responsive, and terminal-state cases. | Cancellation and expiry are terminal branches. Run them against isolated records or a restorable copy of `S1`; preserve a main branch with confirmed placement. Record `S2` before finance. | Confirmed reservations and official schedule bindings on the main branch. |
| 5. Finance, payment, COR, and holds | `S2`; assessment and fee rules | Execute D3C finance/recovery and D3D official-enrollment/output cases. | PayMongo sandbox or dashboard work requires credentials and external-service approval. Hold-blocked and clear-output states must use separate records or an isolated checkpoint. Record `S3` after verified ledger posting and official enrollment. | Current official Enrollment with traceable finance and output evidence. |
| 6. Grades, lifecycle, reports, outputs, and notifications | `S3`; official enrollments and released schedule | Apply or refresh the guarded D5B operational-state overlay, which includes the D4B grade/lifecycle states; execute D4B, D4C, and remaining D4D output cases. | Overlay is repeatable and test-only. No population switch or Cloud solve occurs. | Representative downstream states and cross-role projections. |
| 7. Adversarial and recovery closure | Completed main branch and retained checkpoints | Execute wrong-role, stale/repeated action, invalid input, owner-isolation, empty, blocked, retry, timeout, and recovery cases not already observed. | A failing case opens bounded remediation and reruns only affected cases. No manual database repair. | Completed Pass/Fail/Observation columns and a routed defect or limitation for every failure. |

### 9.5 D5 completion ledger

| Completion criterion | Owning slice | Ready when |
|---|---|---|
| Acceptance package is complete, consistently formatted, and executable | D5A | This section, the cross-role matrix, every manual table, and the charter boundary pass independent verification. |
| Material journeys and state variations have programmatic evidence plus bounded human acceptance where rendering or an external service cannot be proved locally | D5B | Every material case has a programmatic pass, an evidence-backed boundary, or a named final-smoke/external-gate step after bounded remediation. |
| Full regression, authorization, security, queue, payment, and integration readiness is established | D5C | The approved full-suite and security/integration gates pass after D5B remediation, with the testing database restored as authorized. |
| `MIN`, `MIDDLE`, and `MAX` fixtures and safe operator procedures are verified | D5B and D5D | D5B proves the representative operator path; D5D confirms the three workload manifests used by the targeted study. |
| Targeted capacity, solution-quality, resource, and cost evidence is complete or honestly bounded | D5D | Authorized results use the charter classifications and measures without inventing an accuracy percentage or rerunning an unnecessary full cross-product. |
| Architecture, formulation, Guide, and deployment-readiness statements reflect verified facts | D5E | Documents contain only D5B–D5D verified claims and disclose remaining limitations. |
| TAL-97 can present and defend the system using verified evidence | D5E | The final presentation handoff identifies the approved demo sequence, expected results, recovery path, likely questions, and claim boundaries. |
| The TAL-96D charter no longer competes as an active authority | D5E Cleanup | The charter is marked complete and retained or archived as governance history after its outcomes are consolidated. |

### 9.6 TAL-96D5B accelerated-convergence evidence and final smoke

#### 9.6.1 Shared-foundation dispositions

| Finding | Classification and evidence | Disposition |
|---|---|---|
| Human-facing timestamps were not consistently converted from UTC storage to the institution's Philippine display timezone. | Defect. Laravel keeps UTC storage correctly, but custom Blade and service projections bypassed the shared display contract. | Added the `Asia/Manila` display-time configuration, Filament timezone, and one shared formatter; class-slot clock values and date-only values remain unchanged. |
| Staff navigation used role-oriented group labels instead of the workflow groups approved in the UI blueprint. | Comprehensibility defect. Authorization was correct, but the sidebar did not communicate the operating order. | Added native ordered Filament navigation groups: Admissions; Academic Setup; Offerings & Scheduling; Enrollment; Finance; Grades; Student Records; Reports & Audit; System. Existing resource authorization and URLs remain unchanged. |
| Integration Status tests used obsolete PayMongo labels and expected a System Super Admin link to an academic Schedule Run that the role is correctly forbidden to open. | Stale test expectation plus a bounded clarity defect. The D3A authority intentionally denies this role access to academic scheduling resources. | Updated truthful provider-state wording and retained the Schedule Run source label without rendering an unauthorized link. No permission was broadened. |
| The Applicant Requirements empty state supplied unnamed callout content that Filament did not render. | Defect. The page showed an icon without the guidance required by the accepted Applicant journey. | Replaced the unnamed content with native Filament heading and description slots. The requirement resolver and intake state rules are unchanged. |
| The acceptance database had no current and historical Applicant records suitable for the final smoke. | Acceptance-fixture gap. The application logic and production schema were present, but the bounded smoke could not exercise Draft, Withdrawn, submitted, digital, and physical-requirement states. | Extended the guarded testing-only overlay with deterministic Applicant and Registrar records. It does not change production data, population size, or the scheduling manifest. |
| The Schedule Runs list selected and sorted large JSON snapshot columns even though the list did not display them. | Defect. MySQL exhausted sort memory on the list page; this was a query-projection problem, not a Cloud Run resource or solver-capacity failure. | The list query now selects only displayed metadata and relationship/count fields. Full snapshots and diagnostics remain available when the individual run is opened. |
| Student Finance showed an active remaining balance and payment action while saying that no new payment was needed. | Defect. The persisted ledger state was correct, but the projected next-action guidance contradicted it. | The shared finance presenter now distinguishes partially posted payments with a remaining current due from a fully posted state and directs the student to pay only the remainder. |

These corrections do not change the CP-SAT contract, scheduling equations, PayMongo transaction rules, enrollment placement rules, or any production data model.

#### 9.6.2 Programmatic convergence record

- The broad local D5B gate reached **167 passing tests with 1,708 assertions** and one failing stale schedule-print wording expectation. That expectation was corrected to the already implemented account-scoped message, and its affected test then passed with **9 assertions**.
- The focused Integration Status gate passed **13 tests with 143 assertions**. The shared timezone and workflow-navigation gate, together with the existing staff-navigation visibility checks, passed **10 tests with 96 assertions**.
- The deterministic operational-state overlay passed **3 tests with 88 assertions** and preserved the `MIDDLE` scheduling manifest. It represents eight roles and ten material state families without invoking CP-SAT or PayMongo.
- The final-smoke remediation gate passed **43 focused tests with 494 assertions** against `APP_ENV=testing`, MySQL, and `test_tala_db`. The changed files also passed Pint, scoped PHPStan, Blade compilation, Serena diagnostics, and `git diff --check`.
- Pint, Blade compilation, scoped Larastan/PHPStan, and `git diff --check` passed. Serena diagnostics are clean for the new foundation and affected services; its two `auth()->user()` findings in Integration Status are Intelephense dynamic-helper false positives accepted by the clean scoped Larastan result.

The 69 retained acceptance IDs are accounted for by family:

| Family | IDs | Count | D5B disposition |
|---|---:|---:|---|
| Admissions | `D2A-M01`–`D2A-M11` | 11 | Programmatic pass after bounded admissions remediation; visual sample retained below. |
| Academic structure | `D2B-M01`–`D2B-M06` | 6 | Programmatic pass. |
| Offerings and scheduling readiness | `D2C-M01`–`D2C-M04` | 4 | Programmatic pass; no solver invocation required. |
| Master scheduling | `D3A-M01`–`D3A-M06` | 6 | Local validation pass. The one authorized unchanged-Profile-B `MIDDLE` screening returned solver `unknown` at its time budget, so Laravel correctly produced no candidate publication; configuration selection is routed to D5D. |
| Enrollment | `D3B-M01`–`D3B-M14` | 14 | Programmatic pass; post-publication visual sample retained below. |
| Finance and payment | `D3C-M01`–`D3C-M04` | 4 | Local application pass; PayMongo provider interaction remains human-gated. |
| Official enrollment and projections | `D3D-M01`–`D3D-M06` | 6 | Programmatic pass; live `MIDDLE` published-schedule projection remains prerequisite-blocked and is routed through D5D because the D5B screening produced no publishable candidate. |
| Grades and lifecycle | D4B retained IDs | 6 | Programmatic pass with deterministic state personas. |
| Student Hub and outputs | D4C retained IDs | 6 | Programmatic pass; visual output sample retained below. |
| Public and cross-role presentation | D4D retained IDs | 6 | Programmatic pass; responsive comprehension sample retained below. |
| **Total** |  | **69** | No case requires open-ended manual discovery. |

#### 9.6.3 Bounded final human smoke checklist

This checklist deliberately samples only behavior that programmatic evidence cannot establish: human comprehension, responsive presentation, and authorized external interaction. It does not repeat all 69 cases.

| # | Role and surface | Prerequisite | Human check | Expected visible result | Gate or disposition | Pass/Fail | Observation |
|---:|---|---|---|---|---|---|---|
| 1 | Public visitor; landing page at phone and desktop widths | Public route available | Review workspace choices, FAQ grouping, primary calls to action, and page width | Purpose and three workspaces are understandable; no clipped controls or horizontal overflow | Local final smoke | PASS | Desktop and phone layouts kept the three workspace choices, grouped FAQs, and calls to action readable without horizontal overflow. |
| 2 | Applicant; Dashboard, My Application, Requirements at phone and desktop widths | D5B applicant current/history fixture | Review current versus historical application, Wizard errors, document guidance, and next action | Current state, terminal history, digital versus physical requirements, and recovery guidance are unambiguous | Local final smoke | PASS | The Dashboard distinguishes the current Draft from withdrawn history; the Wizard reflows on a phone and explains saved state, guardian address behavior, and policy-driven requirements. The no-application Requirements state now renders complete guidance. |
| 3 | Registrar; Applicant Intake detail and staff navigation | Registrar demo account and submitted fixture | Review requirement labels, evidence actions, handover blocker, and sidebar sequence | Human labels replace codes; actions and responsible workflow are clear; workflow groups are ordered correctly | Local final smoke | PASS | The submitted intake presents human-readable digital and physical requirements, their blocking effects, verification states, and the permitted verify, reject, download, and physical-receipt actions. |
| 4 | Registrar or Academic Head; Academic Setup, Offerings, Scheduling | Ready `MIDDLE` fixture | Review prerequisite/readiness messages and candidate-versus-official language | Missing inputs name the blocker; a candidate is never presented as an official timetable | Local clarity check remains available; actual `MIDDLE` candidate/publication is routed to D5D after the time-bounded D5B screening | PASS / ROUTED D5D | The local surfaces are clear and the Schedule Runs list loads without sorting its large JSON payloads. Run `43` remains visibly blocked with zero candidate rows; no candidate is described as official. Publication evidence remains routed to D5D. |
| 5 | Student and Registrar; regular and irregular Enrollment | Published compatible schedule | Review proposal, confirmation, cancelled/recovery, conflict, and capacity guidance | Proposal versus confirmed placement is clear; invalid or out-of-order actions explain the blocking rule | Publication-dependent portion is routed to D5D; do not mark it passed before that prerequisite exists | ROUTED D5D | Not executed: the authorized D5B screening produced no publishable candidate. Existing programmatic Enrollment evidence is retained; a visual publication-dependent pass is not invented. |
| 6 | Accounting and Student; Finance | Due, partially paid, and cleared overlay personas | Review assessment, ledger, finance gate, and retry language | Due, paid, failed, and cleared states are distinct; checkout return alone is not shown as payment | Local final smoke; provider success/failure requires Gate 2 | PASS | Due, partially posted, and cleared states are distinct. A partially posted assessment with a remaining balance now directs the student to pay the remainder, while OR mapping remains a separate Accounting action. Gate 2 also passed. |
| 7 | Student; COR, schedule, grades, completion | Official enrollment and published schedule | Review generated and on-screen outputs | Official source, term, subject, modality, day/time, status, and limitations are understandable and consistent | Publication-dependent portion is routed to D5D; do not substitute synthetic local state for live publication proof | ROUTED D5D | Not executed: there is no official `MIDDLE` publication. Existing programmatic COR, schedule, grade, and completion evidence is retained pending the authorized D5D result. |
| 8 | System Super Admin; sidebar, wrong-workspace recovery, Integration Status, Operational Event | System Super Admin demo account | Review workflow grouping, access denial recovery, provider wording, and Schedule Run source | Only authorized destinations are actionable; provider state is truthful; forbidden academic detail is not linked | Local final smoke | PASS | Workflow navigation is grouped, wrong-workspace access uses the approved account-switch recovery, provider wording is truthful, and the academic Schedule Run source is not linked for a role forbidden to open it. |

Six locally executable rows passed. Rows 5 and 7 remain honestly routed to TAL-96D5D because they require an official published `MIDDLE` schedule. The bounded smoke did not retry Cloud Run, change resources, deploy, or substitute synthetic publication evidence.

**Human Gate 1 — current-profile `MIDDLE` screening and conditional Registrar publication (consumed 2026-07-26):** One request was sent through Laravel run `43` to the unchanged promoted Profile B revision. The frozen `tal94-demand-v2` request used input hash `a2894dbf335c36881279d9d55dbdec6d2719a7974cfe174f052e7f36c8a54a98` and contained 80 demands, 80 sections, 14 faculty, 6 rooms, and 168 half-hour time slots. The live revision `tala-scheduler-solver-b4f-ad9177e472f8` remained at 2 CPU, 4 GiB, concurrency 1, and returned HTTP `200`; there was no OOM, transport failure, deployment, traffic change, or retry. CP-SAT returned solver status `unknown` with `timeout=true`, zero assigned demands, 80 unassigned demands, approximately 31.835 seconds of application-recorded runtime, 169,043 model variables, and 337,725 model constraints. The Cloud request took approximately 39.817 seconds including cold start.

This result means only that the current solver budget on the current promoted profile did not find or disprove a feasible solution for this exact MIDDLE snapshot. It does **not** prove that the workload is infeasible. Laravel correctly marked the run `blocked`, retained zero candidate rows, and refused publication; therefore no `S1` checkpoint or published-schedule projection was created. The result is preliminary D5D screening evidence. Profile/resource/time selection and any further paid solve require the separately planned D5D authorization.

**Human Gate 2 — PayMongo test-mode acceptance (consumed and passed 2026-07-26):** the separately authorized test-mode checkout, genuine signed webhook, provider-dashboard delivery, queue and ledger effects, and one valid duplicate delivery were reconciled. The duplicate was classified without posting a second payment or ledger entry. No production key, live-mode charge, webhook reconfiguration, or deployment was used.

### 9.7 TAL-96D5C1 role, surface, and cross-role contract closure

#### 9.7.1 Registered surface inventory and purpose

This inventory is derived from the three Filament panel providers, discovered custom Pages, registered staff Resources, their policies, and the owning PRD/blueprint statements. `Aligned` means the surface has an MVP purpose, an authorized owner, and retained behavior. `Fixed gap` means D5C1 corrected a reproducible defect without changing the product model.

| Workspace / workflow | Registered surfaces | Owner and purpose | Editability and downstream value | Disposition |
|---|---|---|---|---|
| Public and Applicant | Public landing and workspace entry; Applicant Dashboard; My Application; Requirements; applicant authentication/profile | A visitor chooses the correct workspace. An Applicant creates one policy-scoped current application, saves a partial draft, supplies configured evidence, follows review state, corrects rejected evidence, withdraws when allowed, and sees retained history. | Applicant-owned draft fields and permitted replacements are editable; submitted decisions and history are read-only projections consumed by Registrar intake. | Aligned; retained after D5B admissions remediation. |
| Student Hub | Dashboard; Enrollment; COR; Class Schedule; Finance; Grades; Holds & Blockers; Academic Status; Completion; My Profile | A Student sees current official records and only the self-service actions allowed by the responsible office. | Office-owned academic and financial records remain read-only; enrollment proposal, allowed profile/evidence actions, and payment initiation are controlled actions. All pages resolve the authenticated Student Profile. | Aligned. The stale navigation expectation that omitted Enrollment was corrected. |
| Admissions | Applicant Intakes; Admission Requirement Policies; Duplicate Profile Resolutions | Registrar configures applicable requirement rules, verifies evidence and physical receipt, resolves duplicate identity, and performs accepted-applicant handover. | Policy and review decisions produce Checklist Items, Document Evidence, audit records, notifications, and the Student Profile handover consumed by later workflows. | Aligned. |
| Academic Setup | Academic Years; Terms; Academic Calendar Windows; Programs; Courses; Course Specifications; Curriculum Versions; Import Batches | Registrar and Academic Head establish the authoritative academic structure before offerings, schedules, or enrollment. | Editable by authorized setup roles. Imports are previewed and validated before records are accepted. These records feed offerings and scheduling readiness. | Aligned; the Import Batch upload received the missing Filament schema-upload restriction. |
| Offerings & Scheduling | Calendar Events; Rooms; Faculty Qualifications; Faculty Term Load Overrides; Term Offerings; Sections; Scheduling Demands; Schedule Generation Runs; Section Meetings; Faculty Schedule | Registrar prepares schedulable inputs and publishes the official master timetable; Academic Head reviews allowed exceptions; Faculty sees their assigned schedule. | Source setup is editable by the owning role. Generated demands and solver evidence are review records. Candidate correction and publication are guarded actions with reasons, Laravel revalidation, and retained provenance. | Fixed gap: recurring wall-clock inputs no longer undergo timestamp timezone conversion; Schedule Run review now shows hard-constraint and soft-objective evidence. No solver contract changed. |
| Enrollment | Enrollments | Registrar manages regular/irregular proposal, gate review, reservations, confirmation, cancellation, and recovery; Student sees the corresponding proposal/current state. | Consequential changes use focused actions and validation. Confirmed placement consumes the published Section Meeting and produces official enrollment bindings consumed by Finance, COR, schedule, grades, and lifecycle. | Aligned. |
| Finance | Fee Rules; Assessments; Accounting Adjustments; Financial Accommodations; Ledger Entries; Payment Attempts; Payments; PayMongo Reconciliation | Accounting configures fees, assesses obligations, records controlled exceptions, reconciles evidence, and preserves the ledger as financial truth. | Accounting decisions are controlled/audited; Student Finance is a projection and checkout initiation surface. Posted ledger state feeds the enrollment finance gate and official outputs. | Aligned; provider acceptance remains the already consumed D5B gate. |
| Grades | Grade Rosters; Faculty Grade Roster | Faculty records authorized roster grades; Registrar reviews, releases, corrects, and completes allowed outcomes. | Draft and review actions are role/state constrained. Released outcomes project to Student Grades, completion, and graduation review. | Aligned. |
| Student Records | Student Profiles; Student Lifecycle Changes; Graduation Review Batches | Registrar maintains the student master record and auditable lifecycle decisions; authorized reviewers assess completion/graduation. | Master facts and approved decisions feed enrollment eligibility, records, reports, COR, holds, and completion views. Historical effects remain read-only. | Aligned. |
| Reports & Audit | Reports & Audit; Activity Log; Operational Events | Authorized staff inspect fixed operational reports, recorded actor activity, and system/integration incidents. | Reports and logs are evidence surfaces, not alternate mutation paths. Sensitive exports require an authorized purpose and are audited. | Aligned. |
| System Administration | Users; Roles; System Settings; FAQ Entries; Disposal Candidates; Integration Status | System Super Admin manages identities/role assignment and public FAQ content, reviews retention candidates and safe integration state, and inspects the versioned settings registry. | Users, roles, FAQ, and approved disposal actions are controlled. System Settings are intentionally **read-only**: each persisted row identifies its operational disposition, owner, purpose, actual consumer/effect, and current stored value. Secrets and Laravel maintenance remain environment/deployment-managed; Integration Status reports safe readiness without exposing secrets. | Fixed gap: the registry no longer implies that every historical definition controls live behavior. No dormant setting was activated and no historical row was deleted. |

Unregistered resource directories are not automatically user-facing contracts. `CorVerifications` and `PromissoryNotes` are supporting implementation inventory rather than registered navigation destinations; D5C1 does not invent duplicate surfaces merely because those directories exist.

##### System Settings per-key disposition

The generic registry is a read-only governance and traceability surface, not a promise that every stored key controls the running application. Only the Student Unit Load fallback has a verified application consumer. Existing version rows remain preserved even when their definition is dormant or superseded.

| Setting key | Operational disposition | Owner | Verified consumer or effect | Editability |
|---|---|---|---|---|
| `maintenance_mode` | Dormant | Deployment operator | No application consumer. Laravel maintenance is controlled through deployment or CLI operations. | Read-only |
| `maintenance_message` | Dormant | Deployment operator | No application consumer. Maintenance messaging remains deployment-managed. | Read-only |
| `maintenance_eta` | Dormant | Deployment operator | No application consumer. Maintenance timing remains deployment-managed. | Read-only |
| `admission_requirements` | Superseded | Registrar | The typed `AdmissionRequirementPolicy` workflow drives Applicant and Registrar requirements. | Read-only |
| `installment_policy_defaults` | Dormant | Accounting | No verified runtime consumer in the current application. | Read-only |
| `college_cutover_effective_term` | Dormant | Academic Head and Registrar | No verified runtime consumer in the current application. | Read-only |
| `college_cutover_effective_datetime` | Dormant | Academic Head and Registrar | No verified runtime consumer in the current application. | Read-only |
| `student_unit_load_policy_defaults` | Operational | Academic Head and Registrar | `StudentUnitLoadPolicy` reads the active JSON fallback while evaluating enrollment unit limits; recorded exceptions remain the office-owned decision evidence. | Read-only |

When the registry has no persisted rows, the page explicitly explains that this is not a configuration failure: fixed behavior may live in the owning typed workflow, application code, or deployment environment. D5C1 does not create a generic settings editor or infer runtime behavior from historical storage tests.

#### 9.7.2 Producer-consumer trace

| Producer and decision owner | Authoritative record/output | Consumer and visible effect | Guard against mismatch |
|---|---|---|---|
| System Super Admin and Applicant authentication | User, role, verified identity, Applicant ownership | Correct panel access and owner-scoped Applicant/Student records | Panel access, policy checks, record ownership, and direct-URL denial |
| Registrar admission policy and Applicant submission | Admission Requirement Policy, Applicant Intake, Checklist Item, Document Evidence | Registrar review; Applicant Requirements status; accepted Student Profile | Effective policy resolution, per-item evidence binding, duplicate checks, private files, checksums, versioning, and transactional handover |
| Registrar/Academic Head academic setup | Academic Year, Term, Calendar Window, Program, Course Specification, Curriculum Version | Offerings, imports, scheduling readiness, enrollment term choice | Active/effective scope, prerequisite validation, and blocked out-of-order actions |
| Registrar offering/resource setup | Term Offering, Section, Room, Faculty qualification/load, Calendar Event | Scheduling Demand and immutable solver input snapshot | Readiness checks, eligibility/availability/load validation, and operating-grid bounds |
| Cloud solver response plus Laravel validation | Schedule Generation Run diagnostics and candidate assignments | Registrar candidate review, correction, publication decision | Typed response validation, per-hard-constraint evidence, recorded soft-objective evidence, warnings, coverage, objective/bound/gap, and no publication when invalid or empty |
| Registrar publication/revision | Official Section Meetings and retained publication provenance | Faculty Schedule; Student enrollment selection; published Student schedule | Confirmation, reason, Laravel revalidation, binding-impact rules, and official/candidate language separation |
| Registrar enrollment decision | Enrollment, selected offerings, reservations, gate decisions | Student Enrollment; Accounting assessment context; COR/schedule eligibility | Window, capacity, conflict, prerequisite, terminal-state, and replacement/cancellation guards |
| Accounting assessment and verified payment evidence | Assessment, Payment Attempt, Payment, Ledger Entry, accommodation/adjustment | Student Finance; finance gate; official enrollment eligibility | Signed/idempotent provider evidence, ledger source of truth, exact ownership/amount matching, and audited manual recovery |
| Faculty and Registrar grade workflow | Grade Roster and released grade outcomes | Student Grades, completion, lifecycle, graduation review | Roster ownership, release state, late/correction authority, and released-only Student projection |
| Registrar lifecycle/retention decisions | Student Lifecycle Change, Graduation Review, Disposal Review | Student Academic Status/Completion; reports and audit evidence | Explicit authority, immutable impact evidence, retention review rather than silent deletion |

#### 9.7.3 Evidence-backed D5C1 corrections

| Finding | Classification | Correction and boundary |
|---|---|---|
| The custom Import Batch page accepted File Upload components without Filament's schema upload-restriction concern. | Defect / security hardening gap | Added the official Filament upload restriction concern and a focused Livewire assertion. File type, size, storage, preview, and import semantics are unchanged. |
| Date-less class/availability/operating-grid values were passed through the panel display timezone and valid `09:00` input became `01:00` before Laravel validation. | Defect | Recurring Time Pickers now use the application timezone directly, preserving institutional wall-clock values. True timestamps continue to use UTC storage and Philippine display conversion. The Term form default is synchronized to the approved `21:00` close. |
| Schedule Run review showed aggregate quality and findings but not one readable result for every applicable hard constraint or the recorded soft-objective terms. | Defect / defense-readiness gap | Laravel persists the already validated `soft_constraint_scores` and `objective_details`; the run infolist renders a Hard Constraint Checklist and Soft Objective Evidence. This changes neither CP-SAT equations nor the request/response contract. |
| The Student navigation boundary test omitted the implemented Enrollment page. | Stale verification expectation | Updated the expected navigation contract; production navigation and authorization were already correct. |
| System Settings descriptions treated dormant maintenance/calendar/payment definitions as though each had a live runtime consumer. Three maintenance definitions were also marked editable even though policy and the Resource deny all mutations. | Defect / administrative truthfulness gap | Classified every registered key as Operational, Superseded, or Dormant; exposed owner and verified consumer/effect; made the registry consistently read-only; and added a truthful empty state. Historical rows remain intact and no dormant feature was implemented. |
| The three D5B operational-overlay checks require the guarded `MIDDLE` overlay, which is not the current database state. | Environment prerequisite, not a product defect | Retain the failure as explicit D5C2 setup evidence. Do not rebuild or replace `test_tala_db` without its destructive gate, and do not misclassify missing fixture state as an application regression. |

#### 9.7.4 Concise human cherry-pick table

Programmatic evidence owns exhaustive coverage. During independent verification, a human need only sample these distinct comprehension patterns; do not repeat every Resource.

| Sample | Role and surface | What to inspect | Expected result |
|---:|---|---|---|
| 1 | Applicant — Dashboard, My Application, Requirements | Current/history state, configured requirement labels, blocked/recovery guidance | One understandable next action; no conflicting state or unexplained technical code |
| 2 | Student — Enrollment, Finance, COR, Schedule | Proposal versus official state and cross-page term/section/payment consistency | Every projection refers to the same authorized Enrollment and explains any missing prerequisite |
| 3 | Registrar — Academic Setup through Schedule Run review | Workflow order, readiness block, hard checklist, soft evidence, manual correction/publication controls | Inputs remain local wall-clock times; candidate is not called official; consequential actions explain impact and require authority/reason |
| 4 | Accounting — Assessment, Reconciliation, Student Finance projection | Amount, evidence source, posting/retry language, and downstream gate | Ledger state and Student guidance agree; no checkout return is presented as payment proof |
| 5 | System Super Admin — System Settings and Integration Status | Operational disposition, owner, verified consumer/effect, stored value, edit affordance, provider readiness, forbidden academic link | Operational, superseded, and dormant entries are distinguishable; the registry is read-only; no dormant value is presented as live behavior; secrets are absent; unauthorized academic detail is not actionable |

TAL-96D5C1 does not certify the full suite, create an official `MIDDLE` publication, benchmark Cloud Run resources, or repeat PayMongo provider acceptance. Those boundaries remain with D5C2, D5D, and the already consumed D5B evidence respectively.

#### 9.7.5 Primary execution record

- Environment proof was `APP_ENV=testing`, `DB_CONNECTION=mysql`, and `DB_DATABASE=test_tala_db`.
- The focused role/surface gate passed **114 tests with 1,168 assertions**. It covered Applicant/Student navigation, staff role and direct-access boundaries, System Settings purpose/read-only presentation, recurring calendar and Term inputs, academic imports, schedule-result ingestion and review, candidate correction, published revision, and offering/scenario hardening.
- Pint passed for all dirty PHP files. Scoped Larastan/PHPStan passed for every changed application class. Blade compilation and `git diff --check` passed.
- A broader pre-correction run had **284 passing tests and 7 failures**. The four scheduling failures isolated to the wall-clock timezone defect and now pass in the focused gate. The remaining three require the guarded D5B `MIDDLE` operational overlay, which is absent from the current database; they remain a declared D5C2 fixture prerequisite rather than a fabricated D5C1 product defect.
- Verification remediation passed **18 focused tests with 255 assertions** covering the System Settings policy/table, every registered key's disposition metadata, truthful empty-state guidance, version-history storage semantics, and the operational Student Unit Load consumer. Scoped PHPStan, Serena diagnostics, Pint, Blade compilation, and `git diff --check` passed.
- No destructive database action, browser-wide exploratory pass, Cloud solve, PayMongo provider call, deployment, dependency change, solver-contract change, or capacity benchmark occurred.

Independent `Verify TAL-96D5C1` and `Cleanup TAL-96D5C1` subsequently passed, and the bounded local result is recorded in commit `23292bc0`. The next accepted boundary is TAL-96D5C2's full regression, security, and integration-readiness gate; this D5C1 evidence does not self-certify that later gate.

### 9.8 TAL-96D5C2 full regression, security, and integration-readiness gate

#### 9.8.1 Final local disposition

The primary implementation and independent verification dispositions are **PASS for the approved local TAL-96D5C2 boundary**. This is not production, provider, browser-family, stakeholder, deployment, or capacity certification. No Cloud solve, PayMongo provider call, deployment, traffic promotion, credential rotation, paid capacity run, Linear mutation, push, or pull request occurred; Cleanup creates only the bounded local D5C2 commit.

The test database target was proved as `APP_ENV=testing`, `DB_CONNECTION=mysql`, and `DB_DATABASE=test_tala_db` before every database-backed command. The ordinary lane ran without persistent acceptance data. The database was then proved exactly empty before the approved guarded `MIDDLE` seed; the re-check showed 270 students, 9 cohorts, 80 term offerings, 80 ready scheduling demands, 14 synthetic scheduling faculty, and readiness `PASS`.

#### 9.8.2 Evidence-backed corrections

| Finding | Classification | Correction and boundary |
|---|---|---|
| The three operational-overlay tests were indistinguishable from ordinary transaction-isolated tests. | Verification-harness defect | Added the PHPUnit 11 class-level `acceptance-fixture` group. The ordinary and guarded lanes now form an explicit, complete 1,025-test union. |
| The current `MIN` fixture is correctly 9 faculty with a 21:00 operating close, while retained TAL-96B3 calibration evidence expects its historical 12-faculty, 156-slot shape. | Historical-fixture drift, not a current product or solver defect | The benchmark capture now makes a deterministic in-memory historical projection only for the known 54/9/6/168 MIN snapshot. It preserves the live MIN records, the old benchmark label, and the solver contract; it does not repeat or relabel the historical Cloud experiments. Current population/configuration evaluation remains TAL-96D5D. |
| PayMongo demo readiness hard-coded the former 64-user baseline after MIN faculty authority changed from 12 to 9. | Test-only integration fixture defect | Derive the expected user total from the authoritative baseline manifest while preserving all exact unpaid-demo artifact, amount, ownership, readiness, and fail-closed checks. No PayMongo transaction behavior changed. |
| Calendar-window acceptance expected Philippine wall-clock input to remain unconverted in UTC storage. | Stale acceptance expectation | Assert the documented UTC values while retaining Asia/Manila display conversion. Product timestamp behavior remains unchanged. |
| Finance acceptance called a ₱500 ledger posting against a ₱9,000 charge fully posted. | Stale acceptance expectation | Assert the already accepted `Payment Partially Posted` state, remaining-due action, and separate OR-mapping state. Product finance behavior remains unchanged. |
| Locked Guzzle, Axios, PostCSS, shell-quote, and Concurrently versions carried applicable advisories. | Supply-chain security finding | Updated only the affected lock entries within existing manifest constraints. `composer.json` and `package.json` remain unchanged; Composer and npm audits now report zero advisories. |

#### 9.8.3 Programmatic evidence

- Ordinary lane: `vendor\bin\phpunit.bat --exclude-group=acceptance-fixture --colors=never` passed **1,022 tests with 13,865 assertions** and 2 expected opt-in external-service skips in 6 minutes 57 seconds. The skips are the guarded real loopback and private tagged Cloud Run tests; neither external mode was authorized for D5C2.
- Guarded fixture lane: `TAL96D5BOperationalStateOverlayTest.php` passed **3 tests with 88 assertions** after the verified-empty MIDDLE seed. The complete union is **1,025 passing tests with 13,953 assertions**.
- Focused security and integration lane: **305 tests with 2,583 assertions** passed after the guarded MIDDLE lane. Five included classes deliberately use Laravel's `LazilyRefreshDatabase`, whose first database access runs `migrate:fresh`; the matrix therefore restored a clean migrated baseline rather than retaining MIDDLE afterward. Coverage included role/direct-entry authorization, private applicant evidence and uploads, controlled outputs, reports/audit, sessions, validation/error behavior, retention, PayMongo signatures/idempotency/ledger/recovery, queue and solver transport, notifications, monitoring, and the pre-integration gate.
- Focused regression repairs passed independently: calendar windows **5/64**, Student Finance **3/51**, PayMongo demo readiness **4/60**, and the historical scheduling benchmark **12/4,434**.
- Full Larastan/PHPStan reported **no errors**. Pint passed for dirty PHP, Serena reported no warning-or-higher diagnostics for changed PHP files, and `git diff --check` passed.
- Production Vite build passed. Route, configuration, and Blade view caches compiled successfully and were then cleared through `optimize:clear`.
- All **31 migrations** were applied on `test_tala_db`. The database queue resolved with `retry_after=420`; SMTP remained the configured mailer; the hourly `enrollment:release-expired-reservations` schedule was registered; and `queue:failed` reported no failed jobs.

#### 9.8.4 Dependency and security evidence

| Check | Verified result |
|---|---|
| Composer lock installation | `composer install --dry-run --no-interaction` found nothing to install, update, or remove |
| Composer advisories | Zero; Guzzle `7.15.2` and Guzzle PSR-7 `2.13.0` installed |
| npm lock installation | `npm ci` completed and a later `npm ci --dry-run` reported up to date |
| npm advisories | Zero; Axios `1.18.1`, PostCSS `8.5.23`, its required NanoID `3.3.16`, shell-quote `1.9.0`, and Concurrently `9.2.4` installed |
| Manifest validation | Composer reports a valid manifest; strict mode returns only the existing exact-version warning for TallStackUI `3.0.0` |
| Tracked-secret patterns | No tracked application or configuration file matched the high-confidence live-key/private-key patterns; two duplicated best-practices rule files matched only their explicitly labelled synthetic `Incorrect` examples, and only `.env.example` is tracked as an environment file |

`gitleaks` is not installed in this workspace, so the secret evidence is the bounded tracked-file pattern check, the review and classification of its two documentation-only example matches, and the existing application tests that prevent secret disclosure. This limitation does not authorize storing credentials in Git. The exact TallStackUI pin is a known dependency-governance warning, not an unresolved security advisory; broadening its constraint requires a separate dependency decision.

#### 9.8.5 Next boundary

Independent `Verify TAL-96D5C2` passed after rerunning the complete 1,025-test union, the 305-test security/integration matrix, changed acceptance behavior, full static analysis, formatter, dependency/audit/build checks, migrations, caches, queue/scheduler inventories, and the bounded tracked-secret review. Verification also corrected the fixture-lifecycle and documentation-only secret-match descriptions above. Cleanup records that verified result locally. The next boundary is **`Plan TAL-96D5D`** through a fresh Ground-Truth Gate; Linear synchronization, external traffic, deployment, push, and pull-request creation remain unauthorized.
