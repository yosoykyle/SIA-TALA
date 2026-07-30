# TALA System Operations and Defense Guide

**Document status:** TAL-96D5D is independently verified and complete locally; TAL-96D5E final evidence consolidation, deployment-readiness disposition, TAL-97 verified-claim handoff, and charter retirement remain pending, dated 2026-07-28
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
| D2-AD-02 | Registrar | Review evidence, move the intake through evaluation and approval, and perform explicit handover | Submitted intake, authorized active Registrar, resolved handover blockers, and exactly one active curriculum | Admissions Work Queue and Applicant Record | Applicant intake, checklist/evidence history, output-access log, Student Profile, and initial Enrollment | Workflow-first record, per-item evidence review, exact identity-match warning, focused approval, and explicit handover | Decisions follow the allowed order; stale/repeat/wrong-role actions fail without mutation; first-time/transfer exact matches stop silent duplication; returning reuse stays explicit | Queue/record presentation, Registrar action, private-download audit, stale/repeat, blocker, identity-match, curriculum, first-time, transfer, returning, and failed-handover tests | D2A cases plus TAL-96D5E1B2B focused implementation evidence | Programmatic pass; concise human presentation sample remains in Section 5.2.5 | TAL-96D5E1B2B |
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
2. The applicant uses a three-step Wizard: Personal Information, Required Documents, and Review and Submit. The personal step captures the approved identity, contact, structured address, guardian, prior-school, term, and program fields. It does not ask for a student-level delivery modality. Age is calculated from date of birth and is not stored separately.
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
| Missing client-registration identity, address, and guardian fields | Defect / real gap after approved authority correction | Nullable intake columns and final-submission validation now capture the approved identity, address, and guardian fields. Age is derived, avoiding a second value that could disagree with date of birth. |
| Long single-page application form | Defect / real gap after approved authority correction | The application is now a native Filament v5 Wizard with three explicit steps; the underlying draft and submission services remain authoritative. |
| Applicant-level modality choice or a separate timetable per applicant preference | Defect / misleading input | The nonbinding intake choice was removed. Scheduling modality remains a property of each subject offering; Student Hub derives Online, Face-to-Face, or Mixed course delivery from the published enrolled rows. The nullable legacy column remains only for historical compatibility and receives no new application writes. |
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

The programmatic result is a D2A pass, not final visual acceptance. TAL-96D5E1B2B subsequently corrected the proven Registrar interaction-architecture gap without merging or replacing admissions records. **Admissions Work Queue** is now the primary Admissions task entry. Four tabs distinguish Registrar work, applicant-owned correction, approved handover review, and completed/withdrawn history. Queue columns and filters lead with applicant, Program/Term, current stage, responsible party, next action, requirement readiness, and last activity.

The Applicant Record now begins with workflow stage, responsible party, next action, and blocker summary; then shows an exact identity-match check, application scope, personal details, application history, and collapsed technical references. Requirement decisions remain in the per-item checklist workspace, whose secondary review operations are grouped and whose filters use business labels. Admission Requirement Policies and Duplicate Resolutions remain authorized routes reached contextually rather than equal sidebar destinations.

The handover boundary now checks deterministic active-profile candidates for every admission category. A first-time or transfer intake whose first name, last name, and date of birth exactly match an active unmerged Student Profile stops before profile creation and tells the Registrar to investigate. Returning-student reuse remains explicit. This is a duplicate-prevention guard, not fuzzy identity matching or automatic merging.

#### 5.2.4 TAL-96D5E1B2B focused implementation evidence

| Evidence | Result |
|---|---|
| Workflow presenter | Pending, action-required, evaluation, approved, handed-over, and withdrawn records expose one canonical stage, responsible party, next action, requirement summary, and readiness result. |
| Work Queue contract | Native Filament tabs, task-centered columns, and Term/Program/state/category/blocker filters are covered by focused Livewire assertions. |
| Applicant Record contract | The rendered Registrar record is covered for workflow-first ordering, identity-match visibility, application scope, history, and technical-reference placement. |
| Duplicate guard | Exact active matches are surfaced for every category; a non-returning match blocks silent profile creation while preserving the intake and candidate. |
| Navigation preservation | Policy and duplicate-resolution routes remain registered and authorized while their peer sidebar entries are suppressed. |
| Evidence access consolidation | The per-requirement checklist download remains private, authorization-controlled, and recorded in `output_access_logs`; the redundant identity-only header download was removed. |
| Focused implementation suite | 13 transaction-isolated tests passed with 76 assertions, including post-handover self-match exclusion and identity-blocked action visibility, without rebuilding or replacing the preserved MIDDLE fixture. |

#### 5.2.5 User-led manual acceptance checklist

Use `test_tala_db` only. Begin from a complete client-aligned baseline with no applicant intake. The baseline accounts use the test-only password `password`. Prepare separate small valid PDFs for every digital field shown by the policy (for example, identity, birth certificate, and good moral certificate) plus a visibly different corrected PDF for replacement. Record `Pass` or `Fail` and a short observation for every row.

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D2A-M01 | Applicant — `applicant.demo@example.test` | Complete baseline; verified applicant account | Sign in through Applicant Workspace. Also attempt `/admin` and `/student`. | Applicant Dashboard opens; staff and Student Hub access are forbidden. | No admissions record changes. | Wrong-panel URLs must not expose another workspace. | PASS | After the bounded correction, the native Filament empty-state icon remains contained, the Start Application action has clear separation from its copy at narrow width, and wrong-panel URLs retain the branded forbidden response without exposing another workspace. |
| D2A-M02 | Applicant — same account | Active term, currently open institution-scoped Admissions window, active program, effective mixed-evidence admission policy | Open My Application. Confirm the three Wizard steps. In Personal Information, select the active term and program and enter all identity, contact, address, guardian, and prior-school fields. Confirm that the form does not ask for a personal delivery modality. Check `Same as applicant address`, confirm the guardian address follows the structured applicant address, then clear it once and confirm independent editing remains possible. In Required Documents, confirm that each digital policy has its own field while physical/metadata requirements do not. Upload only one valid PDF and save without the declaration. | Draft-save notification appears; values and the partial upload remain available; each field explains the accepted formats, 5 MB limit, and whether it blocks final submission. | One private `draft` Applicant Intake stores policy-keyed draft references and the ordinary guardian-address value; the checkbox itself is not persisted. No checklist or evidence record exists yet, and no new applicant-level modality value is written. | Close or deactivate the Admissions window after saving: the draft must remain editable, but final submission must identify the closed selected term. With no open window, the landing page must say applications are closed, retain Applicant Sign In, and direct registration must fail closed. | PARTIAL | The initial run found zero Admission Requirement Policy rows and duplicate guardian-address entry. The guarded fixtures now provide ten exact mixed-evidence policies and the Wizard has a transient same-address shortcut; refreshed MIDDLE readiness passes. Rerun this row to verify the real upload fields, staged-file presentation, draft restoration, same-address behavior, and absence of a personal modality field before marking it passed. |
| D2A-M03 | Applicant — same account | Partial saved draft | Attempt final submission with a blocking digital file missing. Upload every blocking digital requirement, review the summary, submit once without the declaration, then confirm and submit. | The missing policy is named; the unconfirmed attempt stays on the form with a declaration error. The completed attempt redirects to Dashboard with `Pending Review`. | Intake becomes `pending`; `submitted_at` is set; one checklist item exists per policy and one evidence record per supplied digital file. | Revisit My Application or repeat submission; submitted data must not become an editable second draft. |  |  |
| D2A-M04 | Accounting — `accounting.demo@example.test` | Pending applicant intake | Sign in to Staff Workspace and try the Admissions Work Queue URL or navigation. | Admissions Work Queue is absent or forbidden. | No review, download, or audit record is created. | Direct URL must not bypass role ownership. |  |  |
| D2A-M05 | Registrar — `registrar.demo@example.test` | Pending intake | Open Admissions Work Queue, select Needs Registrar Action, locate the applicant, and open the Applicant Record. Read Current Workflow before inspecting the complete intake and checklist, then download each submitted digital requirement from its checklist item. | Queue hides private drafts and names the stage, responsible party, next action, requirement readiness, and last activity. The record shows identity, scope, exact next action, address, guardian, history, and per-item status without a student-level modality field. Each download succeeds only through the authorized checklist action. | A restricted admission-evidence access log records the Registrar and source for each download. | Use the Term, Program, Workflow State, and blocker filters, then reset them. Return to the same record; files stay private and are never exposed as public URLs. |  |  |
| D2A-M06 | Registrar — same account | Pending intake with multiple submitted digital items | Accept one digital item and reject another with: `Upload a clearer copy showing the complete name.` | Each decision affects only its selected checklist item; rejection displays the correction note and moves the intake to Action Required. | Accepted evidence stays accepted; rejected checklist/evidence becomes rejected; applicant account becomes `action_required`; reviewer and time are recorded. | Repeat either stale decision; it must be hidden or blocked without changing the other item. |  |  |
| D2A-M07 | Applicant — `applicant.demo@example.test` | Action-required intake | Open Requirements, select the rejected requirement, and upload a different corrected PDF. | Only rejected digital items are selectable; the note is understandable; success states that the prior version remains recorded. | A submitted evidence version links through `replaces_document_evidence_id`; that checklist returns to Received Digital / Not Reviewed; the intake returns to `pending` when no other rejection remains. | Try the rejected file again; unchanged replacement must be blocked while accepted items and history remain unchanged. |  |  |
| D2A-M08 | Registrar — `registrar.demo@example.test` | Corrected submitted evidence; physical original-credentials item visible | Verify the corrected digital evidence. For the physical item, use Record Physical Receipt with a sample reference before deciding whether to verify it. Select Mark for Evaluation, then Approve Application. | Digital review, physical receipt, evaluation, and approval actions appear only in valid states and produce clear notifications. | Digital evidence becomes accepted; physical receipt stores `RECEIVED_PHYSICAL`, actor, time, and audit reference; intake and account progress through `for_evaluation` to `approved`. A non-handover physical item may remain open and carry forward. | Try verifying the physical item before receipt, approval before evaluation, or a repeated old action; each must fail without changing the approved result. |  |  |
| D2A-M09 | Registrar — same account | Approved intake; exactly one active curriculum for the program | In Approved / Handover Review, open the Applicant Record. Read Current Workflow, Requirement Readiness, and Identity Match Check before selecting Hand Over to Student. Read the preview and confirm handover. | Preview explains program, term, checklist, identity-match result, and profile consequence; success notification links to the Student Profile. | One active Student Profile, student number, pending `new` Enrollment, carried checklist, handover actor/time, and Student role are recorded. | Prepare an isolated first-time/transfer intake whose name and birth date exactly match an active official profile. Identity Match Check must name the candidate and the Hand Over to Student action must stay unavailable until the match is resolved, without creating another profile. A handover blocker or curriculum problem must likewise explain the blocker and create no partial profile. |  |  |
| D2A-M10 | Same person — `applicant.demo@example.test` | Successful handover | Sign out. Try Applicant Workspace, then sign in through Student Hub. | Applicant Workspace is no longer available; Student Hub opens under the same account as the official student. | No duplicate user or Student Profile is created. | Repeat the Registrar handover URL/action; it must be unavailable or idempotent. |  |  |
| D2A-M11 | Applicant — same baseline account after an approved snapshot restore/rebuild only | Fresh baseline with no intake and an open Admissions window | Save a draft or submit an unreviewed pending intake, select Withdraw Application, enter a concise reason, and confirm. | An empty reason is rejected. The warning explains that withdrawal is retained and online continuation stops; completion notification appears; Dashboard and Requirements show the withdrawal date, reason, and Registrar next step; the action disappears afterward. | Intake becomes `withdrawn`; `archived_at`, actor, reason, and activity event are recorded; account remains in the withdrawal audit state; no Student Profile is created. Registrar list shows status/date without the reason, while detail shows date/actor/reason. | Do not run this row after M09 without restoring the approved baseline snapshot; one account cannot represent both terminal paths simultaneously. A reviewed, approved, or handed-over intake must reject online withdrawal without mutation. |  |  |

For any failure, record the row ID, exact visible message, role, URL, input filename if relevant, and whether a record changed. Do not repair the database manually. Return the completed rows so the primary can distinguish a presentation issue from a state, authorization, or transaction defect.

### 5.3 TAL-96D2B Academic Setup acceptance

#### 5.3.1 Intended operating flow

1. The Registrar opens **Academic Setup > Academic Readiness**. This is the task entry point; it states which Program is ready, which curriculum needs work, the exact blocker, and the next action. Authorized source records remain available from **Source records**.
2. The Registrar records one Academic Year, then creates Terms whose start and end dates remain inside that Academic Year.
3. Programs identify the approved three-year `DBM`, `DIT`, and `DTHM` structures. Current student counts do not redefine Program length.
4. Course identity remains stable while Course Specifications carry versioned academic and scheduling definitions. Staff edit only Draft revisions. A complete Draft is activated through a focused action; later material changes start by copying an existing revision to a new Draft.
5. Curriculum CSV is the normal client-onboarding path. The Import Batch keeps the private source, checksum, full row preview, findings, warning acknowledgement, and Draft-only posting. A proposed Draft may inherit components, grading, modalities, and other enrichment from a complete Active Course Specification, but the preview names that inheritance and requires Registrar review.
6. A manual Draft and a posted CSV import converge on one Curriculum review table. Each row presents source facts, specification completion, curriculum placement, readiness, blocker, and next action. The Registrar can add a row, correct placement, and complete the linked Draft Course Specification and components directly from this table.
7. Workbench actions update the existing Curriculum Entry, Course Specification, and Course Component records transactionally; they do not copy or flatten those records into a second data model. The Registrar then records the external institutional approval reference, reviews activation impact, and explicitly activates the Curriculum Version.
8. Activation supersedes the prior Active Curriculum Version for future applicant handovers only. Existing Student Profiles retain their assigned Curriculum Version.

#### 5.3.2 Change-control classification

| Finding | Classification | Disposition and evidence |
|---|---|---|
| Separate Course identity, Course Specification revision, Course Component, Course Requirement, Curriculum Version, Curriculum Entry, and Import Batch records | Aligned | Preserved. The structure separates durable catalog facts, curriculum placement, and auditable import evidence. |
| Private source storage, SHA-256 checksum, exact headers, full preview, warning acknowledgement, stale-preview protection, transaction, and Draft-only import posting | Aligned | Preserved and covered by the existing TAL-82D import acceptance suite. |
| Registrar could directly edit Active or Retired Course Specifications and non-Draft Curriculum Versions | Defect / real gap | Policies and staff pages now restrict direct editing to Draft records. Revision copying, approval recording, and activation use focused domain actions. |
| Curriculum state and approval fields were directly editable without an impact-confirmed activation workflow | Defect / real gap | Replaced by external-approval recording and transactional activation. The action locks the Program, validates readiness, supersedes the previous Active version, and preserves existing student locks. |
| Terms could be saved outside the selected Academic Year | Defect / real gap | Both Term date fields now validate against the owning Academic Year. |
| Catalog/import choices still exposed `BLENDED` | Defect / real gap | Course Specification and import choices now accept only Face-to-Face and Online. The offering-level follow-through was routed to and completed in TAL-96D2C. |
| Eight Academic Setup source tables appeared as equal sidebar destinations, and manual/imported curriculum rows did not converge on one corrective readiness review | Defect / real gap | Replaced the peer navigation with one native Filament Academic Readiness workbench. Its table now adds curriculum rows, corrects placement, and completes linked Draft Course Specifications and components through the existing authoritative records. Existing Resources, routes, policies, import evidence, and lifecycle services remain available contextually; no record or service was merged. |

#### 5.3.3 Programmatic evidence

- `TAL96D2BAcademicSetupHardeningTest` covers accepted modalities, Term bounds, Draft-only editing, server-owned lifecycle state despite forged Livewire form values, independent revision copying, Course Specification activation, lifecycle action visibility, explicit confirmation, complete approval evidence, supersession, readiness blockers, one Active curriculum, and unchanged Student Profile curriculum locks.
- `TAL82DImportTemplateAcceptanceTest` covers exact templates, unsupported modality rejection, source/enrichment warning visibility, linked Draft review, Draft-only writes, Active-history protection, stale previews, authorization, private downloads, and audit evidence.
- `TAL96D5E1B2AAcademicReadinessTest` covers the single task navigation entry, preserved direct routes, Registrar/Academic Head access boundaries, missing and incomplete curriculum guidance, pending-revision priority, manual row creation, in-workbench placement correction, in-workbench Draft specification and component completion, read-only Academic Head projection, and manual/import convergence.
- The TAL-96D5E1B2A implementation gate passed 65 focused tests with 620 assertions across the workbench, academic setup, curriculum/import lifecycle, staff navigation, shared presentation foundation, and corrected B1 fixture truth. Scoped PHPStan reported no errors.
- The final independently verified focused run passed 102 tests with 1,071 assertions across D2B, TAL-55, TAL-59, TAL-61, TAL-82, D2A, and the client baseline. These regressions protect downstream academic foundation, offering readiness, and applicant handover behavior.
- The B2A verification-remediation run passed 47 non-destructive focused tests with 495 assertions. It covers the combined corrective workbench, manual/import convergence, approval and confirmed activation, incomplete-row activation blocking, TAL-82 catalog/import behavior, and corrected B1 fixture truth; scoped PHPStan remained clean.

#### 5.3.4 User-led manual acceptance table

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D2B-M01 | Registrar — `registrar.demo@example.test` | Existing Academic Year | Open Academic Setup > Academic Readiness, select Source records > Terms, create a Term whose start is before the Academic Year and end is after it, then correct both dates. | Academic Readiness is the sole setup task entry. Each invalid date receives field-level guidance naming the Academic Year; corrected dates save. | Invalid attempt creates no Term; valid attempt creates one Draft Term. | Reverse start/end as a separate attempt; the end-after-start rule must also remain visible. |  |  |
| D2B-M02 | Registrar — same account | Guarded client baseline | From Academic Readiness, open Source records > Programs and inspect `DBM`, `DIT`, and `DTHM`. | Codes and client-aligned names are consistent; each Program shows a three-year length. Returning to Academic Readiness shows each Program's curriculum status, blocker, and next action. | No change during inspection. | Current first-/second-year population must not appear as a two-year Program definition. |  |  |
| D2B-M03 | Registrar — same account | One complete Draft Course Specification | Confirm only Face-to-Face and Online are offered. Activate the Draft, reopen it, then use Copy to New Draft with a unique revision identifier. | Activation warns that the revision becomes protected. Edit disappears after activation. Copy opens a separate editable Draft with cloned components and requirements. | Original becomes Active and remains unchanged; one new Draft is created. | Try a duplicate revision identifier and a Draft with no component; both must be blocked clearly. |  |  |
| D2B-M04 | Registrar — same account | Current templates and at least one complete Active revision | From Academic Readiness, select Import or review CSV. Download the Curriculum template, import one valid source row that proposes a new Draft revision, review the inheritance warning, acknowledge it, post, then select Review Curriculum Draft. In the review table, correct placement and complete any missing Draft specification/component fields. | Full source row and warning identify source values versus inherited TALA enrichment; posting never claims activation; the combined review shows source, specification, placement, readiness, blocker, and next action. Row actions make the corrections without sending the Registrar through peer setup pages. | One posted Import Batch, one Draft Curriculum Version, Draft Course Specification when needed, and Curriculum Entry are recorded; corrections update those same authoritative records. | Upload an altered-header file, `BLENDED` Course Specification template, unknown course, or ambiguous prerequisite; the whole batch must remain unposted with row-level findings. Attempt the same row corrections as Academic Head; mutation actions must be absent. |  |  |
| D2B-M05 | Registrar — same account | Candidate Draft curriculum with complete Active specifications and one previous Active curriculum | Record a real-looking synthetic approval reference, read the activation impact, and confirm activation. | Impact names the previous Active version, entry count, existing student locks, and readiness. Success explains future-handover scope. | Candidate becomes Active; previous version becomes Superseded; existing Student Profiles keep their original curriculum IDs. | Attempt activation with a Draft specification, missing approval, or without confirmation; no curriculum state may change. |  |  |
| D2B-M06 | Academic Head — `academic-head.demo@example.test` | Same academic records | Open Academic Readiness, review a Program curriculum, then use Source records for Programs, Course Specifications, Curriculum Versions, and Import Batch Audit. | The same readiness and row facts are visible; create, edit, approval, activation, and posting actions are absent. | No record changes. | Direct edit/action URLs must not bypass policy authorization. |  |  |

#### 5.3.5 Likely panel questions

| Question | Defensible answer |
|---|---|
| Why can staff not edit an Active Course Specification directly? | Enrollments, schedules, CORs, grades, and history may reference that exact revision. A new Draft records the change without rewriting past facts. |
| Why does importing a curriculum not activate it automatically? | Import proves file structure and creates reviewable Draft records. Institutional approval, complete operational enrichment, and activation impact are separate decisions that require an authorized Registrar. |
| What happens when the client source does not contain scheduling details? | TALA retains the source values. If a complete Active revision exists, a proposed Draft explicitly inherits its operational enrichment for review. Without a complete source revision, posting is blocked rather than inventing data. |
| What does one Active curriculum per Program mean for existing students? | It selects the default for future handovers. Existing students remain locked to the Curriculum Version already assigned to them. |
| Why are the Programs three-year when current population evidence covers only two year levels? | Program duration comes from the approved curriculum structure. The client population is a current count, not a definition of Program length. |
| Why are only two modalities available? | The approved product authority recognizes Face-to-Face and Online. Modality belongs to course/offer delivery, not to a permanent student type, and this correction does not change the solver contract. |
| Why does the sidebar show one Academic Readiness item when the database still has many academic tables? | Navigation represents the staff task, while normalized records preserve auditability and downstream references. The workbench states the next action and opens the exact authorized source record needed; it does not flatten or duplicate the data model. |

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
| `MIDDLE` | Synthetic representative three-year operating load | 270 | 9 | Not reported / 14 | 77 | 77 | One 30-student cohort for every combination of three Programs and three year levels can be constructed deterministically with a synthetic roster that includes load headroom. |
| `MAX` | Client-reported historical population and faculty count | 600 | 20 | 14 / 26 | 77 | 172 | Twenty 30-student logical cohorts can be represented across the nine Program/year scopes; the generated roster is separate from the insufficient historical headcount. |

The MAX cohort allocation starts with two cohorts in every Program/year scope, then assigns the remaining two cohorts deterministically to `DBM` First Year and `DIT` First Year. This is a balanced synthetic distribution, not a claim about the client's historical year-level distribution.

The corrected current fixture uses the 23 actual Third Year / Second Semester source rows: eight DBM, seven DIT, and eight DTHM. Course-row units are authoritative, so DBM computes to 25 units against a printed subtotal of 28 and DTHM computes to 29 against a printed subtotal of 23. Both discrepancies are recorded, and no course is invented to force a match. The completed 80-demand MIDDLE and 178-demand MAX TAL-96D5D experiment remains historical synthetic V1 evidence; its solver measurements do not describe the corrected fixture.

The faculty count is derived and reported separately from timetable solving:

| Scenario | Teaching units | Arithmetic lower bound | Generated faculty | Maximum constructed load | Interpretation |
|---|---:|---:|---:|---:|---|
| `MIN` | 162 | 8 | 9 | 19 | The client-reported nine faculty pass the bounded qualification-and-load construction. |
| `MIDDLE` | 241 | 12 | 14 | 18 | Fourteen provide synthetic operating headroom; twelve is only arithmetic and is not claimed as the proven minimum. |
| `MAX` | 534 | 26 | 26 | 21 | The reported fourteen can carry only 294 units at 21 units each, so the fixture uses a separately disclosed sufficient synthetic roster. |

The arithmetic lower bound is `ceil(total teaching units / 21)`. The bounded construction assigns each fixture workload only to qualified synthetic faculty without exceeding 21 units. No faculty-specific unavailability rows are seeded, so every synthetic faculty record is assumed available across the full Monday-to-Saturday operating grid. `PASS` proves this disclosed load-and-qualification input condition only; it does not consider rooms, meeting times, conflicts, or CP-SAT feasibility. The generated MAX count of 26 is sufficient for this deterministic construction, not a universal or mathematically proven minimum. Real availability restrictions can increase the required roster.

The client demographic table is not imported as a Student standing table: `Freshman` describes year level, while `Regular` is an academic standing. Acceptance personas use the standing values actually supported by TALA. Client modality headcounts are also not copied into Applicant or Student records because modality belongs to each offering; intake asks for term and program, while the fixtures use a realistic mix of `ONLINE` and `FACE_TO_FACE` offerings.

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
| Why does MAX generate 26 faculty if the historical evidence says 14? | At the configured 21-unit ceiling, fourteen faculty can carry 294 units, which is less than the corrected MAX workload of 534. Twenty-six pass the bounded deterministic construction. This does not claim the client historically scheduled the same workload or employed 26 faculty. |
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

#### 5.5.7 TAL-96D5E1B2C Class Planning operating workflow

TAL-96D5E1B2C turns the previously fragmented scheduling navigation into one Registrar-centered operating sequence without merging or deleting the authoritative records:

1. **Prerequisites** — confirm the selected Term, operating calendar, active curricula, and academic source records.
2. **Offerings and Sections** — prepare the courses taught in the Term, their Sections, capacities, and delivery groups.
3. **Teaching Resources** — confirm qualified Faculty, approved load limits, active Rooms, and recurring availability.
4. **Schedule Requirements** — generate and correct the required class components. The canonical persisted record remains a Scheduling Demand.
5. **Generated Timetables** — request generation only after readiness passes, then review assignment coverage, hard-rule validation, warnings, and solution quality.
6. **Published Timetable** — explicitly publish a validated candidate. Only active official Section Meetings project to Students and Faculty.

The **Class Planning** page is the primary navigation entry for this workflow. It is a read-only presenter over existing readiness and scheduling services: it shows the current state, blocker, owner, and one next action for each stage. Existing Resources remain available as contextual **Source records** at their established, policy-protected URLs; a link is hidden when that Resource denies the current role. Teaching Resources directs the Registrar to Faculty Qualifications, Faculty Term Loads, or Rooms according to the retained blocker evidence instead of always opening one generic source. No stage silently creates a source record, invokes CP-SAT, corrects a candidate, or publishes a timetable.

| Role | Class Planning access and responsibility |
| --- | --- |
| Registrar | Owns source preparation, Schedule Requirement generation, bounded timetable requests, candidate review/correction, and explicit publication. |
| Academic Head | May inspect the Class Planning flow and authorized exception evidence read-only; cannot create or edit Term Offerings or publish. |
| System Super Admin | Has no academic Class Planning or Term Offering authority; integration and runtime diagnosis remain separate System responsibilities. |
| Faculty | Does not operate Class Planning; receives only the authorized official assigned-schedule projection. |
| Student | Does not operate Class Planning; receives only the official timetable rows bound through enrollment. |

Failure and blocked states remain specific:

- missing academic or Term prerequisites block Offerings and Sections;
- missing Sections, delivery groups, Faculty inputs, Room inputs, or availability keep the responsible source stage blocked;
- absent or invalid Schedule Requirements block timetable generation;
- a queued or dispatching request is shown as in progress, while failed or validation-blocked results direct the Registrar to the retained findings;
- a generated candidate is never described as official; publication remains an explicit Registrar action; and
- no published meetings means Faculty and Student schedule projections truthfully remain unavailable.

The Tables below the workflow lead with human-identifiable course, Section, Term, time, Faculty, Room, teaching mode, state, and next-action information. Internal demand keys, solver/model identifiers, publication versions, meeting sequence values, and similar provenance remain available as toggle-hidden or collapsed evidence. Secondary row and candidate actions are grouped so narrow viewports retain one clear action entry.

**Deferred concise manual acceptance — owned by TAL-96D5E1E**

| ID | Role | Starting state | Check | Expected result |
| --- | --- | --- | --- | --- |
| B2C-M01 | Registrar | Term missing one prerequisite | Open Class Planning and select the Term | The first failed stage names the prerequisite; later generation remains blocked without mutation. |
| B2C-M02 | Registrar | Complete source records and ready Schedule Requirements | Follow the six stages without invoking an external solve | Each stage links to the correct existing source; readiness and candidate-versus-official wording remain distinct. |
| B2C-M03 | Academic Head | Existing Term and scheduling evidence | Open Class Planning and source records | The flow and permitted evidence are readable; create, edit, correction, generation, and publication controls are absent or denied. |
| B2C-M04 | System Super Admin | Valid System account | Attempt Class Planning and Term Offering routes | Access is denied; no academic mutation is possible. |
| B2C-M05 | Registrar, Faculty, Student | A separately approved official timetable exists | Publish through the controlled gate, then inspect projections | Registrar sees active official meetings; Faculty and Student see only their authorized published rows. |
| B2C-M06 | Any affected role | Narrow viewport | Inspect workflow, Tables, and candidate actions | Primary content remains readable; technical columns do not dominate; secondary actions remain grouped and reachable. |

Likely panel questions:

| Question | Defensible answer |
| --- | --- |
| Why is Class Planning one page if the data remains in several tables? | The page is the operator's workflow and readiness map; Term Offerings, Sections, requirements, runs, candidates, and official meetings remain separate authoritative records with different lifecycle and audit duties. |
| Does opening Class Planning run the solver? | No. It performs read-only readiness presentation. Generation requires a separate authorized and confirmed action after prerequisites pass. |
| Why preserve both a Generated Timetable and a Published Timetable? | The generated candidate is optimization output under review. Only the Registrar's explicitly published, Laravel-validated version becomes official. |
| Can the Academic Head or System Admin publish? | No. The approved responsibility model makes the Registrar the operational publisher; Academic Head review is read-only and System Admin authority is infrastructural, not academic. |
| Did this change the equations or CP-SAT contract? | No. B2C changed operating order, labels, responsive presentation, and one proven policy defect. The solver request, equations, validation, candidate records, publication service, and official projections were preserved. |

#### 5.5.8 TAL-96D5E1D2 Timetabling operating-journey closure

TAL-96D5E1D2 preserves the B2C six-stage Class Planning workflow and closes three operating-presentation gaps without changing scheduling equations, requests, validation, publication, revision, schema, or fixtures:

1. **The selected Term remains selected.** Opening Term Offerings, Schedule Requirements, Generated Timetables, or the Published Timetable from Class Planning carries the selected Term into the destination's native table filter. Staff no longer have to remember and reselect the operating context after every transition.
2. **An active request explains and refreshes itself.** A queued or dispatching Generated Timetable review shows a plain next-step message and refreshes every five seconds while the summary is visible. Polling stops when the request reaches review or another terminal operating state; no WebSocket or new dependency is required.
3. **Operational lists expose bounded native filters.** Generated Timetables can be filtered by Term and result status. The Published Timetable can be filtered by Term, day, Section, and teaching mode. These filters change only the visible query; authorization and official-record scoping remain enforced by the Resource.

| Run state | What the operator should understand | Next action |
|---|---|---|
| Queued | The immutable request is waiting for the configured timetable worker. | Keep the review open or return to the polling list; do not create a duplicate request for the same Term. |
| Dispatching | The worker is processing the request. | Wait for automatic refresh; System administration may inspect integration evidence, but cannot make the academic publication decision. |
| Failed | The request did not produce a candidate. | Review Operations and Diagnostics, then retry only when the recorded classification and Registrar policy permit it. |
| Blocked | A candidate or response failed the required validation/publication conditions. | Follow the retained validation finding to its owning source record, correct the input or candidate, and revalidate. |
| Under Review | Candidate assignments exist for human and Laravel review. | Inspect coverage, hard-rule evidence, warnings, and solution quality; correct when warranted; publish only after validation passes. |
| Published | The Registrar explicitly made the validated version official. | Use the Published Timetable; later changes require the controlled revision workflow and a reason. |
| Superseded | A newer official version replaced this publication. | Treat the record as retained history, not the timetable projected to Faculty or Students. |

The UI-specific comparison used the official UniTime solver manual/screen and Oracle PeopleSoft Student Records process reference only as interaction evidence: prerequisites should be validated before solving; live status and candidate quality must be understandable; correction remains under operator control; and saved candidate work must be distinct from institutional publication. TALA retains its approved Registrar ownership, Laravel revalidation, candidate-versus-official boundary, and native Filament presentation rather than copying another product's information architecture.

**Programmatic evidence:** `TAL96D5E1D2TimetablingOperatingJourneyTest` covers selected-Term continuity, active-detail refresh, and Term/day/Section filtering. Together with the reused readiness, duplicate-active-run, failure/retry, assignment-validation, candidate-review, publication, revision, notification, authorization, and Student/Faculty projection suites, the bounded implementation pass covered 123 tests and 1,753 assertions. A pre-existing TAL-85 test collision with the preserved `LAB-101` MIDDLE room was corrected by giving that test its own unique room code; production data and the MIDDLE fixture were not changed. The final changed-surface rerun passed 14 tests and 256 assertions after formatting. Scoped PHPStan reported no errors. The D2 execution invoked no solver, Cloud service, external provider, reseed, or destructive database operation. Rendered responsive and first-time acceptance remains consolidated under TAL-96D5E1E.

Likely panel questions:

| Question | Defensible answer |
|---|---|
| Does automatic refresh start another solve? | No. It only reloads the existing Schedule Run record while its state is queued or dispatching. |
| Why does the Published Timetable need separate filters? | Publication is Term-wide, but staff commonly inspect a particular day, Section, or teaching mode. Native filters reduce noise without changing the official records. |
| Can a filtered table expose a record the role is not allowed to see? | No. Filters narrow the Resource's already-authorized base query; they are not an authorization mechanism and cannot widen it. |
| Did D2 change feasibility, optimization, or solver runtime? | No. D2 changed only operating context and presentation. Solver behavior and capacity evidence remain governed by the approved scheduling contract and completed D5D study. |

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
| D3B-M03 | Registrar — same account | D3B-M02 Enrollment | Open the Enrollment and select **Confirm Placement**; choose the logical cohort | The option states how many published subjects it contains; success states that reservations and bindings were recorded | One active Course Enrollment, reservation with deadline, and official meeting binding per cohort subject | Deliberately make one Section full or conflicting; the entire cohort confirmation must fail without partial placement |  |  |
| D3B-M04 | Irregular Student — a verified seeded irregular account | Started irregular Enrollment, open window, eligible published Sections | Open Student **Enrollment**, select every Section to retain, then run **Replace complete proposal** | Table shows subject, description, Section, cohort, modality, units, schedule, remaining seats, eligibility, capacity/conflict result, and Proposed status; the confirmation explains that the selected set replaces the previous proposal | Proposal Section and timestamp are recorded per selected Course Enrollment; an omitted previous proposal is dropped; no reservation or binding exists | Select two Sections for one subject, overlapping Sections, an incompatible Section, a full Section, or a blocked subject; the complete replacement must fail clearly without changing the previous valid set |  |  |
| D3B-M05 | Registrar — same account | D3B-M04 proposal | Open the Enrollment and select **Confirm Placement** | Modal states that all proposed subject Sections will be confirmed together | Proposals clear; one reservation and all official meeting bindings are created per selected subject | Introduce a time conflict or full Section; confirmation rolls back every selected course |  |  |
| D3B-M06 | Irregular Student — same account | Prerequisite-blocked subject in the seeded progression evidence | Open Student **Enrollment** and inspect published curriculum Sections | The blocked Section remains visible for explanation, its exact academic blocker is shown, and its selection checkbox is disabled | None | Direct or crafted submission of the blocked Section is rejected |  |  |
| D3B-M07 | Irregular Student and Registrar | Selected units exceed the normal limit without an active approved exception | Propose and attempt confirmation | The action states the requested and allowed unit totals | No proposal or placement mutation survives the failed action | Record an approved scoped unit-load exception, retry, and confirm only within the approved limit |  |  |
| D3B-M08 | Registrar — same account | Confirmed irregular placement, open enrollment window, and another compatible Section for the same subject | Open **More actions**, run **Replace Confirmed Section**, and choose the replacement | The selector lists only alternative Sections for already-confirmed subjects; success distinguishes replacement from a new Student proposal | Old reservation is Released; old bindings are inactive; the new reservation and bindings are active with the enrollment-window deadline; unrelated courses remain unchanged | With no confirmed placement, or with a Section for a different subject, the action is absent or rejected and the old valid placement remains |  |  |
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
| Does an applicant choose an Online or Face-to-Face scheduling track? | No. Applicant intake records the selected term and program, not a personal modality. Authorized academic setup assigns Online or Face-to-Face to each subject offering, and the published enrolled rows determine the student's actual course-delivery mix. |
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

The following is historical TAL-96D5B synthetic V1 evidence. The guarded `php artisan acceptance:seed-tal96d5b-states --no-interaction` command added deterministic operational states to the then-verified `MIDDLE` fixture. It reused the D4B grade/lifecycle overlay and added irregular-waiting, cancelled, assessment-due, partial-payment, finance-cleared, and local pending/failed payment-attempt examples. It was idempotent, never ran CP-SAT, and preserved the historical 270 students, 80 offerings, 80 scheduling demands, and 14-faculty scheduling input. Its local payment attempts were explicitly synthetic projection evidence; they did not claim PayMongo provider acceptance. TAL-96D5E1B1 supersedes this fixture as the current curriculum authority with 77 offerings and demands; it does not rewrite the completed D5B evidence below.

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

This historical D5C1 inventory is derived from the three Filament panel providers, discovered custom Pages, registered staff Resources, their policies, and the owning PRD/blueprint statements. In this section, `Aligned` means only that the surface has an MVP purpose, an authorized owner, and retained behavior. It does **not** prove that the navigation order, information hierarchy, terminology, filters, record history, or cross-role journey is understandable. `Fixed gap` means D5C1 corrected a reproducible bounded defect without changing the product model.

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

D5E1A supersedes any interpretation of the table above as final comprehension or client acceptance. The registrations, owners, and producer-consumer facts remain evidence; the workflow and presentation dispositions are reopened in Section 9.11.

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

### 9.9 TAL-96D5D population operating envelope and operator decision

Implementation parity was established locally before the final corrected-MAX Cloud request. With the exact approved scenario loaded and no scheduling outputs or queued jobs present, `php artisan scheduling:capture-parity-evidence MAX` created a private ignored `tal96d5d-parity-v2` artifact containing an allowlisted structural snapshot and deterministic assignment witness. The command requires `APP_ENV=testing` and MySQL `test_tala_db`. It retains the non-sensitive assignment status required by Laravel, validates the allowlisted payload before writing, reads the file back, verifies its payload hash, and independently validates the exact decoded stored snapshot and assignments. It never calls Cloud Run, invokes CP-SAT optimization, or publishes a schedule. `python -m tala_solver.replay <artifact>` then verifies the same artifact hash and checks every assignment against the Python candidate-enumeration path used before CP-SAT. The replay accepted all 178 witness rows and proves candidate admissibility for that artifact; it is not CP-SAT optimization or an optimality claim. Rebuilding MAX and restoring MIDDLE remain human-gated database operations.

#### 9.9.1 What was evaluated

TAL-96D5D evaluated complete `MIN`, `MIDDLE`, and `MAX` scheduling fixtures after functional and cross-role hardening had stabilized. This is a **targeted capacity and solution-quality study**, not a full stress test of every Cloud Run configuration and not a claim that student population alone determines solver capacity.

| Scenario | Students | Cohorts | Scheduling faculty | Ready demands | Candidate / variable / constraint scale |
| --- | ---: | ---: | ---: | ---: | --- |
| `MIN` | 47 | 6 | 9 | 54 | 11,340 / 34,335 / 68,592 |
| `MIDDLE` | 270 | 9 | 14 synthetic | 80 | 56,112 / 169,043 / 337,725 |
| `MAX` | 600 | 20 | 26 synthetic | 178 | 192,492 / 579,437 / 1,157,585 |

The 26 MAX faculty are generated scheduling capacity, not a rewrite of the client's historical 14-faculty evidence. The constructed 532 teaching units require at least 26 people under the configured 21-unit individual ceiling before qualification and availability restrictions are applied.

#### 9.9.2 Configuration and result matrix

| Configuration | Purpose | Verified result | Operational disposition |
| --- | --- | --- | --- |
| Production Profile B: 2 vCPU, 4 GiB, 2 workers, concurrency 1, 30-second solver limit | Current 54-demand client baseline | Previously promoted and accepted; remained at 100% traffic during D5D | Keep serving until a separately approved promotion |
| `TARGET-CFG-01`: 4 vCPU, 8 GiB, 4 workers, concurrency 1, 120-second solver limit, 300-second HTTP timeout | Evidence-based MIDDLE candidate | `MIN` 3/3 accepted feasible; `MIDDLE` 3/3 accepted feasible; `MAX` returned `unknown_timed_out` | Recommended candidate when workload grows toward the verified MIDDLE shape; remains private at zero traffic |
| `TARGET-CFG-01-TIME`: same resources with a 240-second solver limit | Historical diagnostic branch | The corrected fixture returned `unknown_timed_out`; the earlier `infeasible` result belongs to a superseded pre-correction fixture | Historical only; do not promote or cite the old infeasible result against corrected MAX |
| `FINAL-CFG-01`: 8 vCPU, 8 GiB, 8 workers, concurrency 1, 300-second solver limit, 360-second HTTP timeout | Strict corrected-MAX candidate | HTTP 503 after Cloud Run terminated the instance at 8,208 MiB against an 8,192-MiB limit | Infrastructure-memory failure; no solver-status or timetable claim |
| Earlier `FINAL-CFG-02-MEM` image: same as `FINAL-CFG-01`, but 16 GiB | Controlled higher-memory corrected-MAX test | HTTP 200; `unknown_timed_out`; no incumbent after the unchanged 300-second solver limit; no OOM | Memory failure resolved, but that image did not return a schedule; historical private evidence |
| Final staged-search image on `FINAL-CFG-02-MEM` resources | Equation-preserving corrected-MAX completion run | `feasible`; 178/178 assigned; zero hard violations; objective 1,115,910; best bound 0; relative gap 100%; 307.819849-second reported runtime | Operationally accepted for the disclosed MAX fixture; not optimal, not repeated, not promoted, and not a universal ceiling |

For accepted `MIN` runs, end-to-end duration was 122.191974–122.727425 seconds and relative gap was 3.5256988%–4.1487866%. For accepted `MIDDLE` runs, duration was 127.517125–129.330287 seconds and relative gap was 16.8320877%–19.8179851%. Every accepted run assigned every demand and passed both solver and independent Laravel hard-constraint checks. These are valid feasible schedules, not proofs of optimality.

The corrected MAX fixture is structurally complete and first passed the cross-runtime 178/178 feasibility witness. The final staged-search Cloud request then returned a real solver assignment for all 178 demands. The sanitized rows include day, start time, duration, faculty, modality, and room where applicable and can be reconstructed into cohort, faculty, and room views. They are research evidence, not official published meetings. The readable DBM-1A excerpt is retained in the standalone technical formulation.

The approved staged-search implementation searches the unchanged hard model first. A complete first-stage timetable is used as a full solution hint for the same four-term objective during the remaining budget. If only optimization reaches its limit, operators still receive the independently validated first-stage timetable as `feasible`; if feasibility itself reaches its limit, no timetable is shown. Future evidence reports retain validated `result_source` and `search_stages` telemetry. The immutable accepted MAX report predates that bounded persistence correction, so its missing nested stage values are neither inferred nor rewritten.

#### 9.9.3 Scaling trigger and cost interpretation

Do not resize from student count alone. Reassess the solver configuration when one or more of these conditions occurs:

- ready demands, candidate choices, variables, or constraints approach or exceed the verified MIDDLE scale, at which point the private 4-vCPU/8-GiB candidate becomes the evidence-based review point;
- model scale approaches the disclosed MAX values, or the MIDDLE candidate repeatedly fails acceptance, at which point the private 8-vCPU/16-GiB staged-search configuration becomes the evidence-based review point;
- repeated runs cease returning accepted `feasible` or `optimal` results inside the approved time objective;
- the relative optimality gap is too large for institutional review needs;
- monitoring shows OOM, sustained memory pressure, transport failure, or queue delay; or
- material scheduling rules, operating hours, rooms, qualifications, or cohort construction change.

The corrected request-based proxy uses the 27 July 2026 Singapore rates of US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, US$0.40 per million requests, and 100-millisecond rounding. It estimates `MIN` at US$0.0067038032–US$0.0067367168 per run and `MIDDLE` at US$0.0070000256–US$0.0070987664 per run. The retained eight-run exploratory series totals US$0.0624073856. Recalculation gives US$0.0203565448 for the `FINAL-CFG-01` probe plus failed request, US$0.0378624112 for the earlier `FINAL-CFG-02-MEM` probe plus request, and US$0.03593148 for the accepted staged-search probe plus request. The earlier immutable reports retain their embedded US$0.06051832 and US$0.11208928 fields as superseded evidence. All figures are before free tier and excluded platform charges; solver results and timings were unaffected.

#### 9.9.4 TAL-96D5D post-study operator state and defense answer (historical synthetic V1)

At TAL-96D5D cleanup, the demonstration database was restored to the then-deterministic `MIDDLE` fixture: 270 students, 9 cohorts, 14 synthetic scheduling faculty, and 80/80 ready demands on the Monday–Saturday 07:00–21:00 Asia/Manila grid. It contained no schedule run, candidate row, official meeting, or queued job from the study. This statement records that dated restore; the current TAL-96D5E1B1 authority is 77/77 and remains pending its separately approved persistent rebuild.

**Historical-study panel answer:** TALA does not claim a universal maximum population. The production profile was verified for the client's 54-demand baseline. A private 4-vCPU/8-GiB candidate repeatedly solved both the historical `MIN` and synthetic V1 `MIDDLE` fixtures, establishing an evidence-based review configuration as workload approached the disclosed 80-demand V1 model scale. For the synthetic V1 178-demand MAX fixture, the final private 8-vCPU/16-GiB staged-search configuration returned one complete `FEASIBLE` schedule with zero hard-constraint violations in 307.819849 seconds. This verified an operational point through that disclosed V1 workload, but the 100% relative gap meant optimality was not proved, and one run was not a repeatability or universal-capacity claim. The CP-SAT equations, constraints, objective, Laravel validation contract, and publication workflow did not change. The later 77/172 curriculum correction is a new fixture-input authority and must not inherit these measured results without a separately authorized study.

### 9.10 TAL-96D5E1 first-time exploration environment

#### 9.10.1 Purpose, verified foundation, and limits

TAL-96D5E1 turns the verified `MIDDLE` database into a safe learning environment for a first-time operator. It is intended for exploring how one role creates information and how another role receives or acts on it. It is also an acceptance pass: when the visible result differs from the expected result below, record the mismatch before changing the system.

The overlay preserves the scheduling foundation exactly:

| Foundation fact | Required value |
| --- | ---: |
| Student profiles | 270 |
| Cohorts | 9 |
| Active-term offerings | 77 |
| Ready scheduling demands | 77 |
| Synthetic scheduling faculty | 14 |
| Schedule runs, candidate rows, official meetings, queued jobs, and failed jobs | 0 |

The overlay adds synthetic accounts and operational states only. It does not alter CP-SAT equations, resize a fixture, call Cloud Run, publish a timetable, contact SMTP, contact PayMongo, change schemas, or affect production seed behavior. Because there is no official `MIDDLE` publication, Schedule and COR pages must truthfully explain that prerequisite instead of displaying a fabricated timetable.

#### 9.10.2 Safe preparation and startup

Use a dedicated PowerShell session:

```powershell
$env:APP_ENV="testing"
$env:DB_CONNECTION="mysql"
$env:DB_DATABASE="test_tala_db"

php artisan config:clear
php artisan tinker --execute 'echo app()->environment()."|".DB::connection()->getDriverName()."|".DB::connection()->getDatabaseName();'
php artisan acceptance:seed-tal96d5e1-exploration --check --no-interaction
composer dev
```

The environment proof must be `testing|mysql|test_tala_db`; the D5E1 check must pass with `coverage_state=PASS`. Stop on any other value. Do not use `migrate:fresh`, restore a snapshot, replace a scenario, or rerun a seeder over an unknown database without its destructive database gate.

Before the exploration overlay exists, prove the pristine MIDDLE fixture with `php artisan acceptance:seed-scheduling-scenario MIDDLE --check --no-interaction` after the separately approved corrected-fixture rebuild; it must report `scenario_state=complete` and `readiness=PASS`. After the overlay exists, the exploration check is the authoritative non-writing inspection because it verifies the same 270-student / 9-cohort / 77-offering / 77-demand / 14-faculty fingerprint together with the added operational personas. The older scenario checker then deliberately fails closed on those additional records; that expected conflict is not evidence that the scheduling inputs changed.

Open `http://127.0.0.1:8000/`. The three authenticated workspaces are `/applicant`, `/student`, and `/admin`. Every account below is synthetic and uses the local password `password`.

#### 9.10.3 Sign-in persona catalogue

The catalogue has 26 exploration or verification personas, one separate denied-login persona, and the public visitor. One persona may carry several compatible states; incompatible states use separate accounts.

| Workspace | Email | State or responsibility | What this persona is for |
| --- | --- | --- | --- |
| Public | No account | Visitor | Landing page, admission availability, workspace guidance, login and error boundaries |
| Staff | `registrar.demo@example.test` | Active Registrar | Applicant review, requirements, academic setup, enrollment, official-record and audit projections |
| Staff | `accounting.demo@example.test` | Active Accounting | Assessments, payments, ledger, finance gate, reconciliation, and financial outputs |
| Staff | `faculty.demo@example.test` | Active Faculty | Assigned teaching work and draft/submitted grade workflows |
| Staff | `academic-head.demo@example.test` | Active Academic Head | Academic oversight, scheduling review, and grade review boundaries |
| Staff | `system-admin.demo@example.test` | Active System Super Admin | Accounts, system settings, integrations, reports, audit, and authorization boundaries |
| Staff | `registrar.unverified.demo@example.test` | Unverified Registrar | Email-verification boundary before protected staff work |
| Applicant | `applicant.demo@example.test` | First-time; editable draft | Save/resume, validation, current application, and retained prior withdrawn history |
| Applicant | `applicant.review.demo@example.test` | Pending Registrar review | Submitted digital evidence and a pending physical-copy requirement |
| Applicant | `applicant.action-required.demo@example.test` | Action required | Rejected digital evidence, visible reason, and replacement-file path |
| Applicant | `applicant.evaluation.demo@example.test` | For evaluation | Requirements resolved and ready for an admission decision |
| Applicant | `applicant.approved.demo@example.test` | Approved | Controlled handover readiness; approval alone must not fabricate enrollment |
| Applicant | `applicant.withdrawn.demo@example.test` | Withdrawn | Terminal history, reason traceability, and truthful next-step guidance |
| Applicant | `applicant.transfer.demo@example.test` | Transferee draft | Transfer category and transfer-credential requirement resolution |
| Applicant | `applicant.returning.demo@example.test` | Returning draft | Returning category and prior-student-record requirement resolution |
| Student | `student.demo@example.test` | DBM-1A-001; Regular | General Student Hub and one of the deterministic grade-state anchors |
| Student | `student.dbm-2a.002@example.test` | DBM-2A-002; Regular | Second-year released-grade history and progression projection |
| Student | `student.dbm-3a.001@example.test` | DBM-3A-001; Regular | Third-year released-grade history and three-year curriculum projection |
| Student | `student.dbm-2a.001@example.test` | DBM-2A-001; Irregular | Irregular enrollment waiting for compatible published sections |
| Student | `student.dit-1a.001@example.test` | DIT-1A-001; Probationary | Active amount due and a synthetic failed payment attempt |
| Student | `student.dit-1a.002@example.test` | DIT-1A-002; Deficient | Partial payment, remaining balance, and synthetic pending attempt |
| Student | `student.dit-2a.001@example.test` | DIT-2A-001; Blocked by prerequisite | Cleared finance example while an academic prerequisite still blocks progress |
| Student | `student.dthm-1a.001@example.test` | DTHM-1A-001; Must repeat year level | Cancelled-enrollment history and restart guidance |
| Student | `student.dthm-1a.002@example.test` | DTHM-1A-002; Completion candidate | Completion review and outstanding-condition visibility |
| Student | `student.dthm-2a.001@example.test` | DTHM-2A-001; Graduation candidate | Graduation review and official-record boundary |
| Student | `student.dthm-2a.002@example.test` | DTHM-2A-002; Not yet evaluated | Clear not-yet-evaluated state and next action |
| Student | `student.dbm-1a.002@example.test` | DBM-1A-002; Irregular and unverified | Student email-verification boundary; not an active exploration login |
| Staff | `staff.inactive.demo@example.test` | Inactive Registrar | Denied authentication; the account remains as an audit-safe negative case |

#### 9.10.4 Recommended first-time journey order

Use this order so that prerequisites and producer-consumer relationships remain understandable:

| Order | Roles and surfaces | Action or question | Expected visible and record result | Result |
| ---: | --- | --- | --- | --- |
| 1 | Public; landing, workspace entry, login, 403/404 | Can a new visitor tell which workspace to use? Try one valid route and one unauthorized direct route. | Clear role guidance; branded error; no protected data; no state change. | PASS / PARTIAL / FAIL |
| 2 | Applicant draft, transfer, and returning personas | Open the current application, inspect required fields, save a valid change, and try one invalid value. | Draft persists only valid data; the error names the field and correction; category-specific requirements are understandable. | PASS / PARTIAL / FAIL |
| 3 | Pending, action-required, evaluation, approved, and withdrawn applicants | Compare Dashboard, My Application, and Requirements for each terminal or review state. | All surfaces show the same status, one truthful next step, requirement method, staff feedback, and terminal-state restrictions. | PASS / PARTIAL / FAIL |
| 4 | Registrar; Applicant Intakes | Inspect the same applicant records and requirement items. | Configured digital, physical-copy, and metadata requirements match the applicant projection; each review action identifies its consequence. | PASS / PARTIAL / FAIL |
| 5 | Registrar and Academic Head; academic setup and scheduling | Follow the configured order: academic year and term, curriculum, sections, offerings, faculty/rooms, demands, then runs. | Readiness explains every missing prerequisite. The current database has ready demands but no official timetable; no candidate is described as published. | PASS / PARTIAL / FAIL |
| 6 | Regular and irregular students; Registrar Enrollment | Compare regular, irregular-awaiting-publication, and cancelled records. | Student and Registrar see the same enrollment status and blocker; an irregular student is not silently assigned an invented schedule. | PASS / PARTIAL / FAIL |
| 7 | Due, partial, and cleared students; Accounting | Compare assessment, balance, payment attempt, ledger, finance gate, and reconciliation. | The amount due, partial posting, cleared state, failed/pending attempt, and next action agree across roles. | PASS / PARTIAL / FAIL |
| 8 | Faculty, Academic Head, Registrar, and Student; Grades | Inspect the four deterministic Draft, Submitted, Returned, and Released rosters. | Edit/review/release authority changes by state; only a released grade is an official student projection. | PASS / PARTIAL / FAIL |
| 9 | Completion and graduation personas; Registrar | Inspect holds, lifecycle history, completion, graduation review, and generated-output prerequisites. | The state, blocker, responsible office, and next action are explicit; no missing prerequisite is presented as completed. | PASS / PARTIAL / FAIL |
| 10 | System Super Admin; settings, integration status, reports/audit | Determine which settings are operational, superseded, or informational; inspect authorization and audit sources. | Purpose and consumer are explained; secrets are absent; forbidden academic actions remain unavailable. | PASS / PARTIAL / FAIL |

For each non-pass, record: persona and role, page or route, prerequisite state, exact action and input, expected result, observed result, screenshot or error text, downstream role affected, and whether the finding is an aligned behavior, defect/real gap, cosmetic preference, or unresolved authority question. Repeat only the failed or corrected journey.

#### 9.10.5 Email and PayMongo boundaries

The 26 committed personas do not require 26 real inboxes. A provider-backed rehearsal needs only three human-supplied inboxes or alias families:

1. Applicant — registration and applicant verification link.
2. Student — Student Hub verification or notification delivery.
3. Staff — staff verification and operational notification delivery.

The addresses and credentials remain untracked. Actual SMTP delivery is a human gate. When authorized, configure the real testing mailer and run `php artisan integrations:verify-mail-connection <applicant-alias>,<student-alias>,<staff-alias>`, then verify receipt and link behavior. Local `Mail::fake()` tests remain the programmatic proof for message dispatch and recipient boundaries.

PayMongo exploration starts with the due student `student.dit-1a.001@example.test`. The partial and cleared personas provide comparison states without unnecessary provider charges. Creating a Checkout Session, opening the hosted payment page, exposing or registering a webhook endpoint, processing a signed event, and deliberately redelivering one duplicate event remain the separately approved PayMongo test-mode gate. A checkout return is not payment proof; acceptance requires one Payment, one payment-sourced ledger entry, an updated finance gate, Accounting reconciliation visibility, and idempotent duplicate handling.

#### 9.10.6 Current D5E1 programmatic evidence

The guarded command is `acceptance:seed-tal96d5e1-exploration`. It is restricted to `APP_ENV=testing`, MySQL, and `test_tala_db`; it is transactional and repeatable. `--check` performs no writes. The focused test proves:

- exactly 26 unique exploration or verification personas and one denied-login persona;
- all staff roles, applicant categories and states, student standings, and verification boundaries;
- source-backed prior-term enrollments and owner-correct Faculty grade / Registrar release evidence for regular first-, second-, and third-year students plus irregular, probationary, deficient, held, repeat, completion, graduation, and not-yet-evaluated states;
- rejected digital evidence, resolved requirement checklists, and withdrawn-reason audit evidence;
- repeatable overlay behavior;
- corrected 270-student, 77-offering, 77-demand, 14-faculty MIDDLE scheduling contract; and
- no schedule run, official meeting, queued job, CP-SAT invocation, SMTP call, or PayMongo call.

The final pre-D5E1C overlay-compatible matrix passed **87 tests with 1,104 assertions**. It covered the D5E1 catalogue, retained D5B operational states, applicant validation and status mail, checkout reliability, PayMongo signature and provider-contract boundaries, idempotent webhook posting, Accounting observability, and Student delivery. Its first broad attempt exposed older finance tests that counted every Payment or Ledger Entry in the persistent acceptance database. D5E1C corrected those test-isolation assumptions by scoping assertions to records created after each test boundary or to the test's Student, Term, and Enrollment. The expected business outcomes were not weakened. The resulting D5E1C affected matrix, including the complete TAL-69 webhook-to-ledger class, passed **96 tests with 1,292 assertions** against the populated MIDDLE fixture. The complete clean-baseline suite remains the independently verified D5C2 evidence.

The landing-navigation acceptance regression also passed independently. Real-browser inspection at 929 by 818 pixels proved the mixed overlap that the old navbar-wide switch could not represent: the TALA brand and menu icon remained literal white over the dark hero while each expanded menu item became literal black over the white portal card. No opaque navbar background or replacement visual system was added.

Provider-backed email and PayMongo acceptance, an official MIDDLE Schedule/COR projection, deployment, and final charter retirement are not claimed by this local overlay. They remain explicit later or human-gated boundaries.

### 9.11 TAL-96D5E1A system truth and workflow reconciliation

#### 9.11.1 Scope, method, and verdict

This is the approved browser-free reconciliation after the client reported that the Staff Workspace was difficult to understand. It inspected the authority chain, Git state, three Filament panel providers, registered routes and surfaces, policies, model relationships, forms, tables, infolists, actions/services, transaction boundaries, existing tests, and the preserved D5E1 exploration fixture. It changed no product behavior, schema, dependency, database record, solver configuration, PayMongo state, email state, or public route.

The result is neither “the whole codebase is wrong” nor “the client merely disliked the styling”:

- the normalized domain records, role boundaries, policies, transactions, PayMongo evidence chain, enrollment gate, scheduling validation, and generated-output ownership remain substantially valid salvage;
- the client finding is supported by a systemic interaction-architecture problem: many valid source records are presented as equal navigation destinations instead of one ordered task flow;
- some pages lead with internal codes or disconnected facts rather than identity, current decision, next action, responsible office, blockers, and history;
- at least two user-facing implementations do not yet satisfy explicit PRD interaction contracts; and
- D5C1 proved bounded registration, authorization, and regression facts but overstated those facts as complete role/surface comprehensibility.

No evidence in this audit justifies a whole-schema rewrite, a ledger redesign, a solver change, or deletion of registered records. The recovery should reorganize and clarify the existing implementation vertically, then apply the smallest proven behavioral fixes.

#### 9.11.2 Evidence boundary

Current registration evidence is:

| Workspace | Registered surface evidence | D5E1A meaning |
|---|---:|---|
| Staff Workspace | 39 Resources and 6 custom or framework Pages; 122 current panel routes including authentication/profile routes | Broad operational coverage exists. Registration count is not usability evidence. |
| Applicant Workspace | Dashboard, My Application, Requirements, registration/authentication; 11 current panel routes including authentication/profile routes | The intended small task navigation exists and remains preserved. |
| Student Hub | Dashboard, Enrollment, COR, Class Schedule, Finance, Grades, Holds & Blockers, Academic Status, Completion, My Profile; 16 current panel routes including authentication/profile routes | The read-mostly projections exist. Their consistency still requires D5E1D and concise human acceptance. |
| Public | Landing, admission availability, workspace routing, authenticated output routes, and branded failures | Public behavior remains bounded by its existing focused evidence and later representative acceptance. |

The Staff Workspace already uses nine navigation groups, but it exposes 45 registered Resources/Pages across those groups and no Filament Cluster or task workbench. Filament documents Clusters as a native way to reduce sidebar size and add local subnavigation and breadcrumbs. Filament also provides resource subnavigation, relation managers, tabs, action groups, responsive table layouts, filter indicators, infolists, and focused actions. These mechanisms allow task consolidation without merging authoritative database records. References: [Filament Clusters](https://github.com/filamentphp/filament/blob/5.x/docs/06-navigation/04-clusters.md), [Filament resource record subnavigation](https://github.com/filamentphp/filament/blob/5.x/docs/03-resources/01-overview.md#resource-sub-navigation), [Filament table layout](https://github.com/filamentphp/filament/blob/5.x/packages/tables/docs/05-layout.md), and [Filament record infolists](https://github.com/filamentphp/filament/blob/5.x/docs/03-resources/05-viewing-records.md).

The applicable SIS comparison supports record-centered presentation without requiring TALA to copy another product. PeopleSoft Student Financials presents account summary, activity, charges, payments, and refunds as distinct account views within one student account journey. Its enrollment processing likewise evaluates prerequisites, permissions, deadlines, and class limits before recording enrollment. References: [PeopleSoft Student Financials self-service](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/campus-self-service/viewing-outstanding-charges-payments-financial-aid-refunds.html) and [PeopleSoft enrollment processing](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/class-enrollment-processing.html). These references inform presentation and guardrail norms; TALA's PRD remains product authority.

#### 9.11.3 Registered-surface disposition

Every registered destination is accounted for below. A mixed row preserves aligned records while routing only its proven presentation or workflow gaps.

| Workspace or navigation group | Registered destinations | D5E1A classification | Preserve | Required route |
|---|---|---|---|---|
| Public | Landing, application availability, workspace links, authenticated output routes, HTTP failure pages | `ALIGNED` with later representative acceptance | Bootstrap isolation, workspace separation, admissions-window truth, branded failure boundary | D5E1D verifies shared public/error/output presentation; D5E1E performs the concise human sample. |
| Applicant | Dashboard, My Application, Requirements, registration/profile/authentication | `ALIGNED` at authority/service level; presentation acceptance remains open | One-current-intake rules, history, policy-driven requirements, private evidence, withdrawal audit, admissions-window gates | D5E1D checks cross-page status/next-action consistency; D5E1E samples draft, review, action-required, approved, and withdrawn personas. |
| Student | Dashboard, Enrollment, COR, Class Schedule, Finance, Grades, Holds & Blockers, Academic Status, Completion, My Profile | `ALIGNED` source ownership; `FRAGMENTED_OR_DUPLICATE` where related current-state facts require repeated interpretation | Read-only office-owned records, owner scoping, enrollment proposal, payment initiation, generated outputs | D5E1B corrects affected Registrar projections; D5E1C owns Finance; D5E1D closes remaining Student hierarchy and navigation. |
| Staff Dashboard | Default Dashboard, Account Widget, Filament information widget | `DEFECT_OR_REAL_GAP` as an operational starting point | Authenticated workspace entry and account access | D5E1B supplies the Registrar's ordered work summary; D5E1C/D add role-owned actionable counts and links without inventing charts. |
| Admissions | Applicant Review, Admission Requirements, Duplicate Resolutions | `ALIGNED` records; `FRAGMENTED_OR_DUPLICATE` as an operating journey | Intake, policy, checklist, evidence, duplicate resolution, transactional handover | D5E1B makes policy → applicant upload → Registrar review → physical tracking → decision → handover understandable without merging records. |
| Academic Setup | Academic Years, Terms, Academic Calendar Windows, Programs, Course Catalog, Course Specifications, Curriculum Versions, Import Batch Audit | `DEFECT_OR_REAL_GAP` for the missing complete single curriculum encoding/review workflow; otherwise aligned versioned records | Separate Course, Course Specification, Curriculum Version, Entry, and Import Batch records and lifecycle rules | D5E1B implements the PRD-required task-centered curriculum encoding/review path and setup order over the existing records. |
| Offerings & Scheduling | Scheduling Blocks, Rooms, Faculty Qualifications, Faculty Term Load Overrides, Term Offerings, Section Planning, Scheduling Demand, Solver Runs, Official Schedules, Assigned Schedule | `FRAGMENTED_OR_DUPLICATE` at navigation/operating-order level; solver evidence and source records remain aligned | Readiness, immutable snapshots, independent Laravel validation, candidate/publication distinction, official meetings | D5E1B supplies a setup/readiness/run/review/publish workbench or native subnavigation and preserves all solver contracts. |
| Enrollment | Enrollments | `ALIGNED` list hierarchy; record-level workflow comprehension remains a `DEFECT_OR_REAL_GAP` candidate | Gate service, regular/irregular placement, seat reservation, finance gate, terminal-state rules | D5E1B makes proposal, placement, gate results, assessment, payment, official enrollment, COR, and schedule order explicit on one record-centered journey. |
| Finance | Fee Rules, Assessments, Accounting Adjustments, Financial Accommodations, Ledger Entries, Payment Queue, Confirmed Payments, PayMongo Reconciliation | `DEFECT_OR_REAL_GAP` for ledger presentation and business filtering; `FRAGMENTED_OR_DUPLICATE` for the account journey | Separate records and services, append-only ledger truth, signed/idempotent webhook, manual evidence, recovery decisions | D5E1C creates an account-centered operational path, corrects technical-first ledger UI, and groups exception actions without changing posting semantics. |
| Grades | Grade Rosters, Faculty Grade Roster | `ALIGNED` backend/state model; later role comprehension evidence required | Faculty ownership, Registrar review/release, released-only Student projection | D5E1D verifies task language, responsive roster behavior, correction authority, and cross-role state consistency. |
| Student Records | Student Profiles, Lifecycle Changes, Graduation Review Batches | `DEFECT_OR_REAL_GAP` for missing user-visible lifecycle impact preview and incomplete record-centered history; `TECHNICAL_ONLY_OR_MISPLACED` for progression jargon | Student master, lifecycle snapshots, holds, enrollment/finance/grade relationships, graduation evidence | D5E1B adds the impact preview and a comprehensible student summary/timeline over existing relationships. |
| Reports & Audit | Reports / Audit, Audit Logs, Operational Events | `ALIGNED` as fixed evidence surfaces; some technical filters/details are valid only here | Role-scoped fixed reports, sensitive export purpose, audit/operational evidence | D5E1D confirms descriptions, filter labels, empty states, and separation between operational reports and raw audit evidence. |
| System | Users, Roles & Permissions, System Settings, FAQ Entries, Disposal Review, Integration Status | `ALIGNED` ownership; `TECHNICAL_ONLY_OR_MISPLACED` risk where governance/diagnostic detail displaces tasks | Read-only setting dispositions, environment-owned secrets, controlled FAQ/users/roles/disposal, safe readiness facts | D5E1D groups administration and diagnostics, keeps Settings purpose explicit, and does not activate dormant keys. |

No registered destination is classified `SUPERSEDED_OR_DEAD` in D5E1A. The evidence does not justify deleting a live Resource. Unregistered `CorVerifications` and `PromissoryNotes` directories remain supporting implementation inventory, not proof that another navigation item should be added.

#### 9.11.4 Proven findings and correction map

| ID | Current evidence | Intended behavior | Classification | Retained implementation | Approved next-slice correction and evidence |
|---|---|---|---|---|---|
| E1A-01 | The Staff provider exposes 39 Resources and 6 Pages across nine groups. Valid setup, scheduling, enrollment, finance, and record sources appear as peer destinations. | A first-time operator must know what to configure first, what work is pending, and which record carries the next decision. | `FRAGMENTED_OR_DUPLICATE` | All registered records, policies, services, and routes | D5E1B/C/D propose native clusters, local subnavigation, record relations, or workbenches. Test role visibility, ordered navigation, direct access, and preserved URLs. |
| E1A-02 | PRD 04 requires staff to work in one curriculum encoding table that resolves Course Specification and Curriculum Entry data. The current Curriculum Version form edits only entry placement against pre-existing specifications; Course Catalog, Course Specifications, Curriculum Versions, and Import Audit are separate peer destinations. | One task-centered curriculum encoding/review workflow while retaining separate authoritative records. | `DEFECT_OR_REAL_GAP` | Versioned Course and Curriculum models, import preview, lifecycle service | D5E1B supplies the missing combined review/cleanup interaction and focused tests for manual/imported draft, incomplete specification, readiness block, approval, and activation impact. |
| E1A-03 | `StudentLifecycleService::preview()` computes affected course enrollments, bindings, reservations, profile status, curriculum, finance, and COR effect. `CreateStudentLifecycleChange` calls `record()` directly and does not show that preview before the consequential write. | PRD 11 requires a read-only impact preview before applying lifecycle changes. | `DEFECT_OR_REAL_GAP` | Preview service, transaction, impact snapshot, audit, downstream actions | D5E1B adds a user-visible preview/confirmation step and Livewire tests proving displayed impact, blocked invalid state, and no write before confirmation. |
| E1A-04 | Student Profile has current state, computed “Progression Facts,” lifecycle history, Checklist Items, and Holds. The model also owns enrollment history, while finance, grades, schedule/output history remain discoverable only through separate destinations. | The canonical student record should show a comprehensible identity/current-state summary and chronological, office-owned history without duplicating sources. | `DEFECT_OR_REAL_GAP` and `TECHNICAL_ONLY_OR_MISPLACED` | Student Profile and all related source records | D5E1B adds record-centered summary/history tabs or subnavigation, replaces jargon with official versus recommended language, and keeps edits in owning workflows. |
| E1A-05 | Ledger list leads with raw enrollment/source identifiers and exposes only a Direction filter. Detail renders numeric Student Profile, Term, Enrollment, Source ID, and Posted By values; list and detail disagree on the poster representation. | Accounting should answer whose account, which term, what transaction, amount, state, source context, and correction path; technical provenance stays secondary. | `DEFECT_OR_REAL_GAP` | Append-only Ledger Entry, Assessment, Payment, adjustment, and reversal services | D5E1C uses human labels and consistent actor display, adds business filters for student/term/category/state/source, hides technical IDs by default, and tests source links and immutable behavior. |
| E1A-06 | Assessment, Payment Attempt, Payment, Ledger, adjustment, and reconciliation are separate records. The client interpreted separate navigation as duplicate functionality. | Preserve separate financial evidence and posting records but present one student-account workflow with clear distinctions. | `FRAGMENTED_OR_DUPLICATE`; merging records would be `COSMETIC_OR_PREFERENCE` and unsafe | Existing finance schema, transactions, webhook idempotency, manual fallback | D5E1C creates an account-centered entry point and plain-language “why this record exists” context; no ledger or PayMongo redesign. |
| E1A-07 | Only a bounded subset of table implementations explicitly uses `stackedOnMobile()`. Many wide operational tables retain numerous columns and raw badges. | Every demonstration-critical table must prioritize business columns, stack or reflow on narrow screens, and keep secondary details discoverable. | `DEFECT_OR_REAL_GAP` where controls are clipped or meaning is lost; otherwise `COSMETIC_OR_PREFERENCE` | Native Filament tables, search, sorting, authorization | D5E1B/C/D apply the contract only to role-critical tables and add render/Livewire assertions; no blanket CSS rewrite. |
| E1A-08 | Numerous status/state/type/direction/category fields use raw badges without an explicit formatter in the immediate component. Some models provide label helpers while the UI bypasses them. | Canonical plain-language status, semantic color, next action, and responsible office must agree across producer and consumer surfaces. | `DEFECT_OR_REAL_GAP` when raw codes or contradictory meanings are visible | Stored enum/code values and state machines | D5E1B/C/D introduce shared presentation helpers or existing model label methods and test cross-role label/state parity without changing stored values. |
| E1A-09 | Enrollment list already leads with Student, Term, Status, Enrollment Type, Next Step, and responsible office; actions are grouped and the table stacks on mobile. | Preserve this as the baseline list pattern. | `ALIGNED` | Enrollment list implementation | D5E1B changes only proven record/detail and operating-order gaps. |
| E1A-10 | Schedule Run review already distinguishes candidate/official state and shows coverage, hard-constraint checklist, soft-objective evidence, duration, objective, bound, gap, and findings. | Preserve factual solver evidence and publication control. | `ALIGNED` | Solver contract, evidence, Laravel validation, publication actions | D5E1B reorganizes navigation/readiness only if required; no equation, solver, or evidence change. |
| E1A-11 | `config/app.php` stores true timestamps in UTC, Filament displays in `Asia/Manila`, and the date helper converts true timestamps. Recurring class/grid times deliberately remain wall-clock values. | UTC storage with Philippine display; no shift for recurring institutional time. | `ALIGNED` | Time storage/display boundary | D5E1D verifies representative timestamps and labels; it does not change global timezone by assumption. |
| E1A-12 | D5C1 explicitly performed no browser-wide exploratory pass, yet its table labeled broad workflow families “Aligned.” | Historical evidence must state its actual boundary. | `DEFECT_OR_REAL_GAP` in documentation | D5C1 tests and bounded fixes remain valid | This section qualifies the claim. D5E1E supplies the later concise human evidence; D5E2 may consolidate only verified results. |
| E1A-13 | The Staff provider registers the framework Dashboard, Account Widget, and Filament information widget. No application Staff widget directory exists, while the blueprint requires a small number of role-actionable counts and links. | A staff member should land on a role-scoped work summary that identifies pending work and the next operational stage. | `DEFECT_OR_REAL_GAP` | Staff panel, authentication, role policies, existing operational queries | D5E1B implements the Registrar start state; D5E1C/D add only the Accounting and remaining-role summaries proven useful. No chart dashboard or speculative metric is added. |

#### 9.11.5 Registrar-centered operating-order map

This map defines the demonstration-critical order. It does not imply that every role receives permission to edit every source.

| Stage | Producer and prerequisites | Primary decision/result | Downstream consumer and blocked/recovery behavior |
|---:|---|---|---|
| 1 | System Super Admin provisions authorized accounts and verifies environment-owned integrations; Registrar establishes Academic Year and Term. | A valid institutional and term scope exists. | All workspaces fail closed on role or term mismatch; integration status never exposes secrets. |
| 2 | Registrar configures calendar windows, Programs, Course Specifications, and a recorded/active Curriculum. | Academic structure is complete enough for the intended next stage. | Admissions may use an open admission term/program; offerings and scheduling remain blocked by incomplete curriculum readiness. |
| 3 | Registrar configures Admission Requirement Policies and opens the Admissions window. | Applicable digital, physical-copy, and metadata requirements are resolved. | Public registration and first intake close truthfully outside the window; existing permitted applicant work remains accessible. |
| 4 | Applicant submits; Registrar reviews evidence and physical requirements, resolves duplicates, records decision, and performs handover. | One canonical Student Profile is created or reused. | Applicant sees the same status/next step; Student access does not imply enrollment. |
| 5 | Registrar builds Term Offerings, Sections/delivery groups, faculty/room eligibility, scheduling blocks, and demands. | Readiness is complete or reports exact missing sources. | Solver dispatch remains blocked until inputs are valid. |
| 6 | Authorized scheduling flow captures a snapshot, obtains a candidate, validates it in Laravel, reviews/corrects it, then publishes. | Official Section Meetings exist. | Faculty and enrollment selection read only published meetings; candidate data is never presented as official. |
| 7 | Registrar starts regular or irregular enrollment, records compatible proposal/placement, and evaluates admission, progression, capacity, conflict, and unit-load gates. | Placement and reservations are confirmed or a responsible office owns the blocker. | Irregular students select published compatible sections; no invented timetable or silent section assignment. |
| 8 | Accounting creates/activates the Assessment, accepts verified or authorized manual evidence, and posts the Ledger. | Finance gate is cleared or remains blocked with an exact amount/reason. | Enrollment cannot become official from a checkout redirect alone. |
| 9 | Registrar finalizes official enrollment after all gates. | Official Enrollment is the source for COR, Student Schedule, rosters, and current status. | Missing publication, payment, or placement yields a truthful unavailable state rather than a fabricated output. |
| 10 | Faculty records grades; authorized staff review/release. Registrar records lifecycle or graduation results with impact preview. | Released outcomes and approved lifecycle facts become official history. | Student sees released records only; consequential history is auditable and source-derived. |

#### 9.11.6 Shared presentation contract for D5E1B–D

1. **Navigation:** expose the role's tasks and operating stages. Use native cluster/subnavigation patterns where they reduce sidebar overload; preserve direct authorization and URLs.
2. **List tables:** lead with record identity, scope/term, current status, next action, and responsible office. Put technical keys, audit metadata, and secondary timestamps behind toggles or the record view.
3. **Filters:** each filter must answer a visible business question such as term, program, student, work status, exception type, source, or responsible office. Show active filter indicators and a clear reset path.
4. **Record pages:** use one vertical reading order: identity and scope → current official state → blocker/next action → authorized primary action → history/related records → technical evidence.
5. **Actions:** show at most one current primary action. Group secondary actions. Consequential actions require an impact statement and confirmation; destructive actions require reason, authorization, audit, and supported recovery.
6. **Status language:** retain stored codes but render canonical human labels, semantic colors, next action, and responsible office. Color is never the only status cue.
7. **Dates:** label event meaning—effective, submitted, reviewed, posted, approved, recorded, generated—rather than showing an unlabeled timestamp. Present true timestamps in Asia/Manila; preserve class/grid wall-clock values.
8. **States:** every material surface handles empty, unavailable prerequisite, blocked, validation failure, success, terminal, and retry/recovery states in plain language.
9. **History:** preserve chronology and source ownership. A summary may aggregate related records, but edits and corrections route to their authoritative owner.
10. **Responsive behavior:** use native responsive columns, layouts, action groups, accessible labels/tooltips, and 44-pixel targets where applicable. Do not solve one table with a system-wide CSS margin or forced one-column rule.

#### 9.11.7 Remediation ownership, tests, and human gates

| Slice | Primary files/surfaces | Minimum programmatic evidence | Human gate |
|---|---|---|---|
| D5E1B | Staff navigation provider; curriculum/import forms; offerings/scheduling; Enrollment list/detail; Student Profile and Lifecycle Change surfaces; affected Applicant/Student projections | Role navigation/direct URL; setup ordering; curriculum manual/import review; lifecycle preview/no-write-before-confirmation; regular/irregular gates; record history and cross-role state parity | Any live-surface merge/deletion, schema change, or office-ownership change |
| D5E1C | Assessment, Payment Attempt, Payment, Ledger, Adjustment, Accommodation, PayMongo Reconciliation, Student Finance, finance gate, outputs | Human labels/filters; immutable ledger; assessment-versus-ledger explanation; signed/idempotent posting; manual fallback; recovery decisions; Student/Accounting parity | Any real provider call, credential change, webhook setup, or policy authority conflict |
| D5E1D | Applicant, Student, Faculty, Academic Head, System, Reports/Audit, public/error/output surfaces and shared presentation helpers | Canonical statuses/dates; responsive critical tables/actions; validation/empty/blocked/recovery states; role authorization and producer-consumer parity | New dependency, broad visual redesign, or unresolved product behavior |
| D5E1E | Preserved MIDDLE persona catalogue, developer startup, guide, bounded acceptance matrix | Overlay check; focused regression for corrected journeys; no fixture drift | Real inboxes, real PayMongo test-mode action, official publication, or any other external/destructive step |

D5E1A makes no structural choice on the user's behalf. It therefore stops before implementing clusters, merging navigation, deleting resources, changing schema, or altering workflow ownership. Those are approved only through the plan for their owning remediation slice.

#### 9.11.8 Non-goals and current acceptance status

- No browser or screenshot claim was made.
- No surface was declared visually accepted.
- No registered Resource was deleted, merged, hidden, or renamed.
- No schema, service contract, stored state, solver equation, fixture, payment workflow, or authorization rule changed.
- No database, SMTP, PayMongo, Cloud Run, deployment, Git push, pull request, or Linear mutation occurred.
- The D5E1 personas and MIDDLE scheduling fingerprint remain preserved.

Current status: D5E1A is independently verified and cleaned locally as a reconciliation and recovery map only. It does not certify that the routed surfaces are already understandable. `Plan TAL-96D5E1B` is the next boundary.

### 9.12 TAL-96D5E1B1 fixture truth and academic operating foundation

TAL-96D5E1B1 corrects the exploration foundation before later Registrar workflow remediation:

- the complete three-year curricula retain all 158 First- and Second-Semester source placements;
- the current Second Semester uses 77 offerings and demands from 54 first-/second-year rows plus the 23 actual third-year rows;
- DBM's 25-versus-28 and DTHM's 29-versus-23 printed-subtotal discrepancies remain visible authority findings, and no missing DBM course is fabricated;
- the prior closed First Semester supplies owner-correct enrollments, Faculty-owned grades, Registrar-confirmed releases, holds, and completion/graduation snapshots for the named exploration personas;
- the catalogue now includes regular first-, second-, and third-year students in addition to the non-regular and terminal-state cases; and
- the Registrar Dashboard exposes a native Filament **Registrar Operating Order** widget with six linked stages: Academic Period, Active Curricula, Offerings & Sections, Teaching Resources, Scheduling Demands, and Published Timetable; and
- each Curriculum Version now exposes an ordered read-only curriculum table with Year Level, Term, Sequence, Course Code, Course Title, Units, and Requirement so staff can review the three-year source facts without decoding a concatenated text block.

The widget is guidance over existing records. It does not combine tables, change office ownership, create records, invoke CP-SAT, or publish a schedule. The 80-demand/178-demand TAL-96D5D study remains historical synthetic V1 and is not reinterpreted as evidence for the corrected fixture.

Programmatic construction has been proven inside rolled-back `DatabaseTransactions`. The focused pre-rebuild gate passed 10 tests with 318 assertions, covering the corrected curriculum authority, deterministic scenario construction, source-backed personas, operating-order widget, and ordered curriculum review.

One verification-harness incident changed the persistent state: the first curriculum-view regression was placed in a pre-existing `RefreshDatabase` test class, and its isolated execution rebuilt `test_tala_db` to an empty migrated schema. A read-only fingerprint then proved 0 students, 0 sections, 0 offerings, 0 demands, 0 users, 0 roles, and 0 curriculum entries. The regression was moved to the transaction-safe D2C fixture test and passed there with 46 assertions. Do not run a `RefreshDatabase` suite against a preserved acceptance fixture without an approved snapshot/rebuild lane.

The separately approved recovery-and-corrected-rebuild gate has now passed. A rollback-only phase profile first proved that the apparent stall was synthetic password-hashing cost, not curriculum, offering, demand-generation, scheduling-readiness, or solver behavior: creating the 270 student users at standalone bcrypt work factor 12 consumed 85.589 seconds, whereas all phases after student creation completed in about 9.5 seconds. The guarded retry used `BCRYPT_ROUNDS=4` only in the testing process, matching PHPUnit's existing work factor without changing production configuration. It completed the corrected foundation in 15.269 seconds and then loaded the exploration overlay.

The current persistent `test_tala_db` passes the non-writing exploration check with 270 students, nine cohorts, fourteen synthetic faculty, 77 active Second Semester offerings, 77 ready demands, 158 three-year curriculum entries, 26 exploration personas, and one denied-login persona. Twelve additional offerings exist only in the closed First Semester to support prior-term enrollment and grade history, so the all-term database total is 89 while the active scheduling fingerprint remains 77. Schedule runs, candidate rows, official meetings, queued jobs, and failed jobs are zero. No solver or external provider was invoked.

### 9.13 TAL-96D5E1B3 enrollment, student record, and lifecycle operating flow

TAL-96D5E1B3 preserves the existing enrollment, placement, exception, lifecycle, and history records. It corrects how those records are presented and how a consequential lifecycle decision is confirmed.

#### 9.13.1 Registrar operating sequence

1. Open **Enrollments** and identify the Student, Term, current Enrollment Status, Enrollment Type, Next Step, and responsible office.
2. Open the Enrollment record. The Student Number links to the canonical Student Profile.
3. Use the one state-appropriate primary action:
   - **Confirm Placement** when no confirmed placement exists; or
   - **Record Official Enrollment** only after a confirmed placement and finance/academic clearance.
4. Use **More actions** only for authorized supporting work: cancel placement, replace one confirmed irregular section, refresh gate results, record an academic exception, record a unit-load exception, or print an available COR.
5. Read plain-language gate findings first. Technical blocker codes and evidence sources remain available for diagnosis and defense but do not lead the decision.

This action hierarchy does not bypass policies or collapse transactions. The existing services remain authoritative for enrollment creation/reuse, gate evaluation, proposal, capacity reservation, placement, exception recording, officialization, and cancellation.

#### 9.13.2 Regular and irregular Student Hub truth

The Student Hub Enrollment table answers five immediate questions: which Subject, which Section, when it meets, how many seats remain, and what the student can or must do next. Secondary description, cohort, delivery modality, and unit fields remain optional.

- A regular student is informed that the Registrar confirms the cohort placement.
- An irregular student may see a compatible published section as available to select.
- A submitted proposal is labeled as proposed, not reserved or official.
- Academic prerequisites, full capacity, and schedule conflicts are named as blockers.
- The mobile table stacks rather than clipping decision columns.

The student-facing table does not expose Registrar exception actions and does not reinterpret a proposal as a confirmed seat.

#### 9.13.3 Canonical Student Profile reading order

The Student Profile detail is the staff-facing explanation of the student's institutional history:

1. current official identity, Program, Curriculum Version, and lifecycle state;
2. **Confirmed Academic Standing**, which is the official stored result;
3. **System Recommendation**, which remains computed decision support until authorized confirmation;
4. unresolved holds, including what each hold blocks, the responsible office, reason, and resolution step;
5. term-by-term enrollment history with status, type, office, next step, and links to the owning Enrollment and published Schedule when available;
6. released academic history linked to the owning Grade Roster;
7. term assessment history linked to the owning Assessment; and
8. approved lifecycle history linked to the owning Lifecycle record.

The summary is read-only aggregation. Enrollments, holds, checklist evidence, grades, assessments, schedules, and lifecycle changes retain their existing authoritative owners and audit histories.

#### 9.13.4 Lifecycle impact review and confirmation

Creating a lifecycle change now separates data entry from consequence confirmation:

1. Select the Student, Term, approved change, effective date, authority, and reason.
2. For enrollment- or subject-specific changes, select the affected Enrollment and Course Enrollment.
3. Review the read-only impact preview. It reports affected subjects, bindings released, reservations released, lifecycle state, Program and Curriculum before and after, active holds that remain, the exact assessment-or-ledger consequence, COR effect, and whether the published master schedule changes.
4. Confirm the impact statement.
5. Select **Confirm and Record Approved Result**.

No lifecycle record is written before the explicit confirmation passes. An unavailable preview disables confirmation, and the server rejects a crafted submission with a field-level explanation instead of exposing an exception page. `StudentLifecycleService::record()` remains the transactional writer and retains the final immutable `impact_snapshot`. The preview uses the same service's read-only `preview()` path; it does not invoke scheduling, alter a timetable, or write provisional records.

#### 9.13.5 Focused evidence and deferred human gate

The affected regression check passed 61 focused tests with 568 assertions across enrollment windows/proposals/placement, Student Hub academic-status projection, Student Profile progression/history, and lifecycle changes. The B3 regression set specifically proves:

- decision-focused, mobile-stacked Student Hub enrollment columns;
- mutually exclusive placement and officialization primary actions, with replacement and COR retained as supporting actions;
- separate confirmed standing and system recommendation plus enrollment, released-grade, assessment, lifecycle, hold, and source-record history; and
- no lifecycle write until a valid impact is reviewed and confirmed, field-level rejection of an unavailable preview, confirmation invalidation when an impact-driving value changes, and an immutable cross-module snapshot after creation.

This is programmatic evidence, not visual acceptance. The concise cross-role browser check remains owned by TAL-96D5E1E after the remaining D5E1 remediation slices. No database rebuild, solver run, provider call, schema change, dependency, deployment, push, pull request, or Linear mutation belongs to B3.

#### 9.13.6 Likely panel questions

| Question | Defense answer |
|---|---|
| Why are enrollment actions not all shown at once? | Enrollment is stateful. TALA exposes the one valid next decision and groups authorized supporting actions so staff cannot mistake an exception or refresh for the normal path. |
| Does an irregular student's proposal reserve a seat? | No. The proposal records preference only. The authorized placement transaction rechecks gates and capacity before creating the reservation and binding. |
| Which academic standing is official? | Confirmed Academic Standing is the institution's stored result. System Recommendation is computed decision support and is labeled separately. |
| Does recording a withdrawal or program shift rebuild the published timetable? | No. The preview explains affected enrollment bindings and reservations, while the published master schedule remains unchanged. Later enrollment placement uses the already published compatible offerings. |
| Can lifecycle staff save a consequential change without seeing its effects? | No. The create flow requires a read-only impact review and explicit confirmation before the transactional writer runs. |

### 9.14 TAL-96D5E1C Accounting and PayMongo operating flow

TAL-96D5E1C changes the Accounting mental model without changing the normalized finance model. Accounting now follows **Fee Setup -> Student Accounts -> Payment Exceptions**. Assessment, Payment Attempt, Payment, Ledger Entry, Accounting Adjustment, Financial Accommodation, provider evidence, and output-access records remain separate authoritative records.

#### 9.14.1 First-time Accounting operator sequence

1. Open **Fee Setup** and confirm that the applicable Program-and-Term rules exist. An assessment may remain Draft while ordinary setup is incomplete, but activation requires the exact Program-and-Term downpayment rule.
2. Open **Student Accounts** and locate the Student and Term. Read Account State, assessed total, posted payments, remaining balance, amount due now, Payment Status, Finance Gate, gate source, responsible office, and Next Action.
3. Open the Student Account. Read **Current Position** and **Next Action** before opening supporting evidence.
4. Use **Account Records** only when investigation is needed:
   - **Account Activity** shows the chronological balance evidence;
   - **Payment Attempts** shows checkout attempts and provider-facing states;
   - **Payments and Official Receipts** shows verified evidence and OR mapping;
   - **Adjustments and Reversals** shows authorized corrections; and
   - **Financial Accommodations** shows approved institutional effects and schedules.
5. Open **Payment Exceptions** when a signed webhook, provider-recovery result, mismatch, duplicate, expired attempt, failure, or refund/reversal signal requires Accounting action.
6. Confirm or reject provider-recovery evidence only after the exact Student, Assessment, amount, currency, institutional reference, provider identifiers, mode, and dispute/refund state agree. A browser return never proves payment.

#### 9.14.2 What each finance record means

| Record | Plain-language meaning | What it must not be confused with |
| --- | --- | --- |
| Assessment | What the school charged the Student for the Term | It is not the chronological account history. |
| Account Activity / Ledger Entry | Posted evidence that reproduces the balance: charges, payments, adjustments, and reversals | It is not a second assessment and posted history is not edited in place. |
| Payment Attempt | A checkout attempt and its current provider-facing state | It is not verified payment evidence. |
| Payment | Verified or authorized payment evidence, including payment method and OR-mapping state | A successful browser return alone does not create it. |
| Accounting Adjustment | An authorized correction or reversal request with reason and evidence | It does not delete or rewrite the original ledger entry. |
| Financial Accommodation | An approved institutional result and its explicit gate effects | It does not pretend that TALA authored the external promissory document. |
| Payment Exception | Operational evidence that Accounting must investigate or decide | It is not automatically a posted payment. |

#### 9.14.3 Exploration states in the corrected MIDDLE fixture

The deterministic D5E1 overlay makes the finance workflow explorable without contacting PayMongo:

| Persona / reference | Representative state | Expected operator interpretation |
| --- | --- | --- |
| `DIT-1A-001` | Amount due, expired checkout attempt, adjustment and reversal evidence, active accommodation, and an open provider exception | Accounting must distinguish balance evidence, gate effect, and unresolved provider evidence rather than treating every record as payment. |
| `DIT-1A-002` | Partially paid, verified manual bank-transfer evidence awaiting OR mapping, and an expired accommodation | The ledger reflects the posted payment while OR mapping remains a separate cashier reconciliation step. |
| `DIT-2A-001` | Cleared account plus an under-review attempt and recovered provider evidence | A cleared balance does not permit unresolved evidence to be silently discarded; the exception remains auditable. |

The overlay is idempotent and creates no schedule run, candidate assignment, official meeting, solver job, SMTP request, PayMongo request, or production seed behavior.

#### 9.14.4 Failure, recovery, and authorization rules

- A duplicate manual reference or OR number is rejected without creating another Payment or payment Ledger Entry.
- An under-review, failed, expired, mismatched, or unknown-reference attempt does not clear the Finance Gate.
- Pending OR mapping does not create another payment. Accounting updates the existing verified Payment.
- A correction creates a linked adjustment or reversal. The original posted Ledger Entry remains visible.
- Registrar may view authorized finance summaries needed for enrollment decisions but cannot activate assessments, record payments, map OR numbers, or post corrections.
- Student Finance remains read-only except for the authorized checkout action and generated-output access.
- SOA, billing slip, and payment acknowledgement remain read-only projections and continue to write `output_access_logs`.

#### 9.14.5 Likely panel questions

| Question | Defense answer |
| --- | --- |
| Why did you not combine Assessment, Payment, and Ledger into one table? | They prove different facts. Combining them would weaken auditability and idempotency. TALA instead presents them through one Student Account workflow. |
| What is the difference between Assessment and Account Activity? | Assessment is what the school charged. Account Activity is the chronological posted evidence used to reproduce what remains payable. |
| Does returning from PayMongo mean the Student has paid? | No. The return is informational. TALA requires verified signed-webhook evidence or authorized manual evidence before creating one Payment and one payment Ledger Entry. |
| How does Accounting correct a mistake? | Through an authorized adjustment or reversal linked to the source entry, with reason and evidence. Posted history is never silently edited or deleted. |
| Why can a paid account still show a Payment Exception? | Balance state and evidence-review state answer different questions. A duplicate, delayed, mismatched, recovered, or refund-related event can require review even when the account balance is already clear. |
| What happens if PayMongo is unavailable? | Existing verified records remain intact. New hosted checkout may be unavailable, while authorized manual cashier evidence and later controlled reconciliation provide continuity. |

#### 9.14.6 Evidence boundary

The focused D5E1C matrix passed **96 tests with 1,292 assertions**. It covers the task-centered navigation, account-summary and detail parity, business labels and filters, immutable adjustment/reversal behavior, manual-payment duplicate protection, OR mapping, accommodation effects, Finance Gate behavior, Student Finance outputs, signed and idempotent PayMongo processing, exception presentation and decisions, fixture idempotency, and the absence of scheduling side effects. These are local programmatic findings. No PayMongo request, webhook registration, credential change, deployment, destructive database rebuild, schema change, source-record merge, or visual acceptance is claimed. Consolidated human acceptance remains owned by TAL-96D5E1E.

### 9.15 TAL-96D5E1D lean-MVP authority and workflow consolidation

TAL-96D5E1D separates capability ownership from menu placement. The PRD capability lists remain valid responsibility inventories, but they no longer imply one peer sidebar destination per record type. Existing authoritative records, services, state machines, transactions, and policies remain intact. Primary navigation now names role-owned operating stages; supporting records remain contextual, technical proof remains investigator evidence, future work remains deferred, and framework-only presentation is retired.

#### 9.15.1 Eight-role capability disposition

| User category | Understandable task entry and retained capability | Authoritative result or consumer | D5E1D disposition |
| --- | --- | --- | --- |
| Public visitor | Public landing explains Applicant, Student, and Staff workspaces, admission availability, FAQs, and safe error recovery. | Published FAQs and the configured admissions window determine what is shown; authenticated records remain private. | `Aligned`; retain the D4D and D5B implementation and evidence. |
| Applicant | **Home** and **Application** are primary. Requirements, evidence, and Registrar feedback remain contextual to the current or historical Application. | Applicant Intake, Checklist Items, and private Document Evidence remain authoritative until explicit Registrar handover. | `Gap corrected`: Requirements no longer competes as a peer task, while direct authorized access and correction links remain. |
| Student | **Home**, **Enrollment**, **Academics**, **Finance**, and **Profile** are primary. COR, Class Schedule, Grades, Holds, Academic Status, and Completion remain contextual read-only projections. | Official Enrollment, published meetings, Ledger, released Grades, Holds, and lifecycle/graduation records remain staff-owned sources. | `Gap corrected`: nine peer destinations became five understandable task entries without deleting a projection. |
| Faculty | **My Faculty Work** links Assigned Schedule, Grade Rosters, and **My Unavailable Times**. Draft/Returned/Late rosters are editable; Submitted/Released rosters remain visible as read-only history. | Published Section Meetings, Grade Rosters, Grade Roster Rows, and Faculty-scoped Calendar Events remain authoritative. | `Gap corrected`: availability became discoverable and completed roster history no longer disappeared. |
| Registrar | **Home**, **Academic Readiness**, **Admissions**, **Class Planning**, **Students & Enrollment**, **Grades & Completion**, and **Reports** are primary. | Existing academic, admissions, scheduling, enrollment, and student-record services retain ownership. | `Gap corrected`: source records remain contextual; Grades and Completion now share a truthful task center. |
| Accounting | **Home**, **Student Accounts**, **Payment Exceptions**, **Fee Setup**, and **Reports** are primary. | Assessment, Payment Attempt, Payment, immutable Ledger Entry, Adjustment, Accommodation, and provider evidence remain distinct sources. | `Aligned`; reuse independently verified D5E1C behavior and present its three-stage operating model directly. |
| Academic Head | **Home**, **Academic Oversight**, **Approvals**, and **Reports** are primary. Approvals opens only policy-authorized evidence and never transfers Registrar or Faculty ownership. | The owning Registrar or Faculty records remain authoritative; only PRD-approved review/correction actions are exposed. | `Gap corrected`: a combined Approvals task center replaces unexplained peer resources. |
| System Super Admin | **Home**, **Users & Access**, **Public Content**, **System Health**, and **Governance & Audit** are primary. | Users/Roles, FAQ Entries, settings dispositions, Output Access Logs, Activity Logs, Operational Events, and Disposal Reviews retain separate purposes. | `Gap corrected`: investigator evidence remains available but no longer leads routine system administration. |

#### 9.15.2 Corrected findings and preserved boundaries

| Finding | Classification | Correction | Preserved behavior |
| --- | --- | --- | --- |
| Faculty-owned unavailability existed in policy, schema, forms, and source scoping but was omitted from the custom Staff navigation. | `Defect / real gap` | Added the existing Calendar Event Resource to Offerings & Scheduling and labeled its Faculty projection **My Unavailable Times**. | Same `calendar_events` source, term scope, Faculty ownership, policy, validation, and Registrar/Academic Head review boundaries. |
| Faculty Grade Roster selection hid Submitted and Released records even though the PRD requires submission history. | `Defect / real gap` | Kept every assigned roster selectable, made non-encoding states read-only, hid submit outside editable states, and explained historical state. | Same grade formula, save/submit actions, roster states, authorization, review/release flow, and Student released-only projection. |
| Accounting, Faculty, Academic Head, and System Super Admin landed on a generic Dashboard containing framework information rather than institutional work orientation. | `Defect / real gap` | Replaced the framework-information widget with small role-owned task summaries over existing counts and authorized URLs. | No chart, duplicate task record, new permission, office-ownership change, or automated domain action. |
| Audit and operational-event lists exposed technical-first labels and did not use the native narrow-screen stack. | `Defect / real gap` | Added business labels, human-readable stored-code formatting, explicit empty states, and native mobile stacking. | Audit and operational evidence remain separate, read-only, policy-protected sources; technical provenance remains available in detail. |
| Registered capabilities were presented as peer navigation even when they were supporting records or investigator evidence. | `Defect / real gap` | Applied the PRD/blueprint disposition contract and exact role-owned navigation. Added only the Academics, Grades & Completion, and Academic Approvals task centers needed to make combined labels truthful. | Existing authorized routes, records, services, schemas, state machines, and direct links remain stable. |
| Applicant Requirements and Student COR/Schedule/Grades/Holds/Lifecycle/Completion needed to remain reachable after navigation consolidation. | `Defect / real gap` | Kept every page registered and policy-protected. Applicant correction guidance links Requirements; Student Enrollment links COR and Class Schedule; Student Academics links the academic projections. | No output, evidence, or historical record was removed. |

#### 9.15.3 First-time operating cues

1. Start from **Home** for orientation, then choose the named operating stage; Home does not perform an official decision.
2. Follow the owning task entry and read identity/scope, current status, blocker or next action, and responsible office before opening supporting evidence.
3. Faculty records unavailability before timetable generation, reads only published assignments, edits only open rosters, and uses completed rosters as history.
4. Academic Head uses Academic Oversight for readiness and Approvals for assigned decisions without silently assuming Registrar or Faculty ownership.
5. System Super Admin uses Users & Access, Public Content, System Health, and Governance & Audit. Audit Logs answer actor/record change questions; Operational Events answer integration/delivery questions. Neither replaces the owning transaction record.

#### 9.15.4 Programmatic and runtime evidence boundary

`TAL96D5E1DRemainingRoleCapabilityClosureTest` proves exact primary navigation for every staff role, Applicant and Student consolidation, contextual-page registration, Student Academics orientation, Student Enrollment links to COR and Class Schedule, truthful Registrar and Academic Head task centers, Faculty availability and roster history, role-owned Dashboard summaries, retirement of generic framework-information widgets, and business-readable Audit Log and Operational Event tables. It runs against process-scoped `APP_ENV=testing`, MySQL, `test_tala_db`. Existing transaction-safe Applicant, Student, authorization, report, integration, grade, Registrar, and Accounting tests remain attributable evidence for retained capabilities.

The focused six-journey implementation matrix passed **98 tests with 817 assertions**. Blade compilation, changed-PHP Pint formatting, scoped PHPStan, route registration review, and `git diff --check` also passed. This is programmatic implementation evidence, not visual client acceptance. TAL-96D5E1E still owns the concise phone/tablet/desktop walkthrough. D5E1D did not rebuild, reseed, or delete rows from the preserved MIDDLE fixture and did not invoke the solver, Cloud Run, PayMongo, email, or another provider.

#### 9.15.5 Six-journey evidence handoff

| Journey | Programmatic owner reused by D5E1D | Concise D5E1E human check |
| --- | --- | --- |
| Applicant to student | Admissions Work Queue, applicant handover, and Applicant Workspace tests | Applicant submits/corrects; Registrar decides/hands over; resulting Student Profile is understandable |
| Timetable publication | Class Planning, solver-dispatch boundary, publication, and projection tests | Registrar follows readiness to publication; Faculty and Student see the official result |
| Enrollment and COR | Regular/irregular proposal, gate, placement, official-enrollment, COR, and lifecycle tests | Registrar and Student can identify current state, blocker, next action, and official COR |
| Finance clearance | Accounting recovery, provider evidence, posting, finance gate, and Student Finance tests | Accounting follows account/exception work; Student sees due status and authorized output |
| Grades | Faculty roster and grade lifecycle tests | Faculty identifies editable versus historical roster; Registrar reviews/releases; Student sees released grades only |
| Lifecycle and completion | Student lifecycle preview/apply, history, and graduation-review tests | Registrar sees impact before confirmation; Student sees the authorized status/history projection |

The human pass uses the preserved MIDDLE personas and checks changed navigation, terminology, information hierarchy, and responsive action reachability. It does not repeat every programmatic state transition.

#### 9.15.6 Likely panel questions

| Question | Defense answer |
| --- | --- |
| Why did you keep separate operational Resources? | They represent different authoritative facts and permissions. TALA makes them understandable through role-owned task summaries and contextual links instead of merging records or weakening auditability. |
| How does Faculty availability affect scheduling? | Faculty records term-scoped recurring unavailable blocks in My Unavailable Times. The same Calendar Event records are hard scheduling inputs and are reviewable by authorized Registrar or Academic Head users. |
| Can Faculty change a submitted or released roster? | No. It remains visible as submission history, but its grade cells and submit action are read-only. Corrections continue through the authorized grade workflow. |
| What is the difference between Audit Logs and Operational Events? | Audit Logs answer who changed which institutional record and when. Operational Events answer what an integration, notification, or delivery service reported. |
| Do the Dashboard cards create or approve records? | No. They are orientation links and factual counts over existing sources. The destination policy and action service still enforce every decision. |

### 9.16 TAL-96D5E1D3 Enrollment-to-COR operating flow

#### 9.16.1 Regular and irregular operating order

1. The institution first configures the active academic period, curriculum, offerings and sections, resources, and published timetable. An admitted learner may already have a Student account and an auditable pending Enrollment; publication controls placement, not account existence.
2. A regular Enrollment uses the complete published cohort block. An irregular Enrollment shows only compatible published sections and records the learner's complete proposal without reserving capacity.
3. If no compatible published section exists, the irregular learner waits in the visible pending or capacity-pending Enrollment. The Registrar either waits for the next applicable offering or follows the institution's approved additional-offering process. One learner's placement never reruns the solver.
4. Registrar confirmation rechecks the open window, publication, prerequisite, unit-limit, time-conflict, and remaining-capacity rules atomically. Only successful confirmation creates or replaces the seat reservation and official schedule binding; a proposal alone consumes no capacity.
5. Assessment and payment follow confirmed placement. Accounting-owned ledger evidence determines the Finance Gate. The Registrar records official enrollment only after every required gate is satisfied.
6. Student Hub then projects the same current Enrollment, published bindings, responsible office, source-derived curriculum-level context, and Course Delivery Mix. COR is generated from that official record; an effective COR hold or blocking lifecycle state may still prevent Student printing.

#### 9.16.2 Source records and views

| Surface | What it answers | Authority boundary |
| --- | --- | --- |
| Enrollment | What is the current term decision, blocker, next action, and responsible office? | Term-specific source record; placement and officialization remain transactional Registrar actions. |
| Student Profile | Who is the learner and what is their cross-term institutional history? | Canonical person record with source-derived current Enrollment context and contextual history links. |
| Published Schedule | Which section meetings are official and available for placement or projection? | Registrar-published timetable; candidate or unpublished rows are never Student assignments. |
| COR | What subjects, sections, meeting details, curriculum levels, delivery mix, and finance summary are officially registered now? | Read-only output from the current official Enrollment and its owned source records; corrections happen in the sources. |

Curriculum level is not a persisted Student attribute. TALA derives the unique levels represented by the current active enrolled subjects: one level is shown when uniform, while an irregular mixed-level load is labeled with every represented level. Course Delivery Mix is similarly derived from per-offering Online and Face-to-Face values and is never a personal Student modality.

#### 9.16.3 Likely panel questions

| Question | Defense answer |
| --- | --- |
| Where is an irregular learner before sections are published? | The learner remains an admitted Student with a visible pending Enrollment. The system explains that the Registrar is waiting for compatible published sections; it does not invent a proposal, seat, or schedule. |
| Does selecting or proposing a section reduce capacity? | No. Capacity changes only inside successful Registrar placement confirmation, which rechecks every section and writes the reservation and binding atomically. |
| When should another offering and schedule run be considered? | Only when the institution approves an additional regular or special offering because no suitable published capacity exists. Individual placement consumes an existing published section and does not invoke CP-SAT. |
| Why can one Student show Mixed Levels? | Irregular loads may contain courses from several curriculum levels. TALA lists the represented levels instead of mislabeling the whole enrollment from the first course. |
| Are Enrollment, Student Profile, Published Schedule, and COR duplicate records? | No. Enrollment is the term decision; Student Profile is the person and history; Published Schedule is the official timetable; COR is a generated current-registration output from those sources. |
