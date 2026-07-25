# TALA System Operations and Defense Guide

**Document status:** TAL-96D5A acceptance-readiness reconciliation independently verified; consolidated user-led acceptance remains pending TAL-96D5B, dated 2026-07-25
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

An automated pass is not, by itself, final user acceptance. A browser pass does not replace business-policy confirmation. Consolidated user-led acceptance is reserved for TAL-96D5B, full regression closure for TAL-96D5C, and formal presentation readiness for TAL-97.

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

The six reported program/year groups are represented as six regular cohort identifiers. Their approved curricula produce 54 course delivery demands. The fixture also contains an active second-semester term, rooms, faculty qualifications, unrestricted default faculty availability, load data, fee rules, and verified test accounts. Names, personnel, rooms, qualifications, and availability assumptions are synthetic. They provide complete relational inputs for acceptance testing and do not claim to reproduce the client's real personnel or published timetable.

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
| D1-01 | Public user | Choose the correct workspace | None | `/` | Public configuration and FAQ | Read-only | Clear applicant, student, and staff routes; unavailable actions must not be implied | D4D public-entry and cross-role presentation tests | Responsive rendered samples passed; D4D-PUB-01 through D4D-PUB-03 remain in the consolidated walkthrough | Programmatic and bounded rendered pass; D5B manual pending | TAL-96D5B acceptance |
| D2-ID-01 | Applicant, student, staff | Authenticate only into the assigned panel | Verified, active account and canonical role | Panel login pages | User, role, permission, student profile | Editable only through approved account flows | Valid user reaches intended panel; wrong, unverified, inactive, or archived access is denied or routed to verification | Panel, authentication-eligibility, email-verification, D2A service-authorization, and D4D cross-role tests | D2A-M01, D2A-M04, D2A-M10, and D4D-XR-01 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D2-AD-01 | Applicant | Start, save, submit, correct, or withdraw an application when allowed | Active term, active program, effective requirement policy, verified applicant account | Applicant Dashboard, My Application, and Requirements | Applicant intake, checklist item, and document evidence | Draft is editable; each applicable digital requirement has its own private upload; rejected digital evidence is replaceable; withdrawal is restricted to an unreviewed draft or pending intake | Required fields, declaration, file constraints, duplicate checks, status, correction reason, and blocked actions remain explicit | Wizard, partial-draft, policy-driven multi-upload, mixed-evidence, declaration, active-scope, duplicate, invalid-replacement, correction, and withdrawal tests | D2A-M02, D2A-M03, D2A-M07, and the restore-isolated D2A-M11 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D2-AD-02 | Registrar | Review evidence, move the intake through evaluation and approval, and perform explicit handover | Submitted intake, authorized active Registrar, resolved handover blockers, and exactly one active curriculum | Applicant Review | Applicant intake, checklist/evidence history, output-access log, Student Profile, and initial Enrollment | Read-only evidence and preview with focused review, approval, download, and handover actions | Decisions follow the allowed order; stale/repeat/wrong-role actions fail without mutation; handover creates or explicitly reuses one profile | Registrar action, private-download audit, stale/repeat, blocker, curriculum, first-time, transfer, returning, and failed-handover tests | D2A-M05, D2A-M06, D2A-M08, and D2A-M09 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D2-AS-01 | Registrar / Academic Head | Establish a valid academic period | Authorized staff | Academic Years, Terms, Calendar Windows | Academic year, term, calendar event | Registrar editable; Academic Head read-only | A Term outside its Academic Year is rejected with field-level guidance; later calendar and offering readiness remain separate | D2B term-bound, role, academic-calendar, and scheduling-readiness tests | D2B-M01 and D2B-M06 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D2-AS-02 | Registrar | Maintain catalog and curriculum | Active program and authorized staff | Programs, Course Catalog, Specifications, Curriculum Versions, Import Batch Audit | Program, course, specification, curriculum, import batch | Draft records editable; protected revisions read-only; lifecycle changes use focused actions | Source meaning, inherited enrichment, row-level findings, Draft-only posting, approval evidence, activation impact, supersession, and student curriculum locks remain explicit | D2B lifecycle and import tests plus TAL-82 regressions | D2B-M02 through D2B-M06 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D2-OF-01 | Registrar / Academic Head | Build schedulable offerings | Valid term, curriculum, rooms, qualified faculty | Term Offerings, Sections, Scheduling Demand | Offering, section, delivery group, faculty qualification, room | Editable before publication boundaries | Readiness findings identify missing or conflicting inputs before solving | D2C offering, scenario, faculty-capacity, and readiness tests | D2C-M01 through D2C-M04 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D3-SC-01 | Registrar / Academic Head | Generate, review, and publish a timetable | All demands ready; solver integration available | Scheduling Demand, Solver Runs, Official Schedules | Demand, generation run, meeting, revision event | Controlled action and review | Solver status, conflicts, objective evidence, and publication state remain distinguishable | D3A master-schedule hardening and scheduling regressions | D3A-M01 through D3A-M06 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D3-EN-01 | Registrar / Student | Enroll regular and irregular students through explicit gates | Student profile, active enrollment window, published compatible offerings, progression facts, required clearances | Staff Enrollments and Student Enrollment | Enrollment, course enrollment, proposal fields, gate result, reservation, binding, exception | Irregular Student proposes without holding capacity; Registrar or System Super Admin confirms; regular placement confirms one complete logical cohort block | Missing or closed windows, unpublished or incompatible sections, wrong ownership, lifecycle blocks, unit overload, conflict, capacity, terminal-state, and invalid-replacement failures are explicit and transactional. Only staff confirmation holds capacity. | Named D3B window, start, truthful active reuse, terminal restart denial, responsive actions, proposal, cohort, placement, replacement, cancellation, deadline, wrong-role, rollback, and affected TAL-67/TAL-87 regressions | D3B-M01 through D3B-M14 pending | Programmatic and independent verification passed; D5B manual pending | TAL-96D5B acceptance |
| D3-FI-01 | Accounting / Student | Assess fees and process the current due | Enrollment and active fee rules | Assessments, Payments, Student Finance | Assessment, fee line, ledger entry, payment attempt, payment | Accounting editable; student evidence view with payment initiation | Amount due is derived from assessment and ledger; unavailable payment is disabled and explained | D3C finance, PayMongo recovery, TAL-68 through TAL-71, and TAL-95 regressions | D3C-M01 through D3C-M04 pending | Programmatic pass; D5B manual and bounded sandbox gate pending where applicable | TAL-96D5B acceptance |
| D3-CO-01 | Student / Registrar / Accounting | Finalize an eligible Enrollment, then view and issue the current COR and schedule | Active Term, official Enrollment, published bindings, applicable hold/lifecycle clearance | Staff Enrollment, Student COR, Class Schedule, Holds, and print views | Enrollment, course enrollment, meeting, binding, hold, and output-access log | Registrar-owned mutation followed by authorized read-only outputs | Missing or blocked output explains the next step; current Student outputs share one Enrollment source; each row shows Online or Face-to-Face modality | D3D resolver, COR, official-enrollment, source-output, schedule-projection, hold-window, authorization, print, and revision convergence tests | D3D-M01 through D3D-M06 pending | Programmatic and independent verification passed; D5B manual pending | TAL-96D5B acceptance |
| D3-IN-01 | System Super Admin / Accounting | Monitor and recover integrations | Authorized role and recorded operational event | Integration Status, PayMongo Reconciliation | Operational event, payment attempt, webhook call | Controlled recovery action | Duplicate, delayed, rejected, and retried events remain auditable and idempotent | D3C integration status, recovery, webhook, queue, ledger, and idempotency regressions | D3C-M03 and D3C-M04 pending; provider-dependent evidence remains a bounded human gate | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D4-GR-01 | Faculty / Registrar / Student | Enter, review, release, and view grades | Enrollment, roster, assigned faculty | Grade Rosters, Faculty Grade Roster, Student Grades | Grade roster, grade entry, revision event | Role- and state-dependent | Course, section, term, assigned faculty, progress, state, and permitted next action remain visible; only released grades reach students | TAL-96D4B focused and grade regression tests | D4B-GR-01 through D4B-GR-03 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D4-LC-01 | Registrar / Student | Manage holds and lifecycle decisions | Student master and applicable source evidence | Lifecycle Changes, Graduation Review, Student Holds, Academic Status, Completion | Hold, lifecycle change, progression result, graduation batch and snapshot | Staff-controlled; student read-only | Recognizable selectors and labeled operational impacts replace IDs and raw JSON; responsible office, result, visibility, and required action remain understandable | TAL-96D4B focused and lifecycle/graduation regression tests | D4B-LC-01, D4B-HO-01, and D4B-GR-04 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D4-SH-01 | Student | Understand current academic and financial state | Accessible student profile | Student Hub | Aggregated authoritative records | Read-only except permitted profile fields | Empty, blocked, pending, and complete states provide actionable guidance | D4C Student Hub and output-presentation tests plus affected regressions | D4C-SH-01 and D4C-SH-02 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D4-RP-01 | Authorized staff | Produce reports and trace changes | Source records and role permission | Reports / Audit and Import Batch Audit | Audit logs, operational events, import records, output snapshots | Read-only and export actions | Report totals reconcile with source records; sensitive evidence stays permission-bound | D4C report/export tests plus TAL-75, TAL-88, and TAL-92 regressions | D4C-RP-01, D4C-RP-02, D4C-OUT-01, and D4C-NO-01 pending | Programmatic pass; D5B manual pending | TAL-96D5B acceptance |
| D5-AC-01 | Project team / stakeholders | Attempt invalid, out-of-order, and hostile journeys before defense | Completed D2–D4 corrections and the selected `MIDDLE` acceptance fixture | All representative surfaces | Whole-system evidence | Mixed | The system prevents invalid transitions, explains recoverable errors, and records sensitive actions | Focused slice evidence is complete; full regression/security/integration gate belongs to D5C | Consolidated user-led UAT belongs to D5B | Pending D5B manual and D5C programmatic closure | TAL-96D5B and TAL-96D5C |

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
| Applicant withdrawal | Only the owner of an unreviewed draft or pending intake may withdraw; the retained intake and activity log remain auditable. |
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

The programmatic result is a D2A pass, not final visual acceptance. The checklist below is intentionally user-led so the presenter confirms the actual wording, action placement, loading behavior, and role-to-role continuity.

#### 5.2.4 User-led manual acceptance checklist

Use `test_tala_db` only. Begin from a complete client-aligned baseline with no applicant intake. The baseline accounts use the test-only password `password`. Prepare separate small valid PDFs for every digital field shown by the policy (for example, identity, birth certificate, and good moral certificate) plus a visibly different corrected PDF for replacement. Record `Pass` or `Fail` and a short observation for every row.

| ID | Role and credential | Prerequisite | Steps and input | Expected visible result | Expected record or state change | Invalid or edge check | Pass / Fail | Observation |
|---|---|---|---|---|---|---|---|---|
| D2A-M01 | Applicant — `applicant.demo@example.test` | Complete baseline; verified applicant account | Sign in through Applicant Workspace. Also attempt `/admin` and `/student`. | Applicant Dashboard opens; staff and Student Hub access are forbidden. | No admissions record changes. | Wrong-panel URLs must not expose another workspace. |  |  |
| D2A-M02 | Applicant — same account | Active term, active program, effective mixed-evidence admission policy | Open My Application. Confirm the three Wizard steps. In Personal Information, select the active scope and enter all identity, contact, address, guardian, prior-school, and modality-preference fields. In Required Documents, confirm that each digital policy has its own field while physical/metadata requirements do not. Upload only one valid PDF and save without the declaration. | Draft-save notification appears; values and the partial upload remain available; each field explains the accepted formats, 5 MB limit, and whether it blocks final submission. | One private `draft` Applicant Intake stores policy-keyed draft references; no checklist or evidence record exists yet. | Change category/basis and save: fields must refresh to the new policy and a removed upload must not remain attached. Try `.txt` or over 5 MB; the form must identify the problem. |  |  |
| D2A-M03 | Applicant — same account | Partial saved draft | Attempt final submission with a blocking digital file missing. Upload every blocking digital requirement, review the summary, submit once without the declaration, then confirm and submit. | The missing policy is named; the unconfirmed attempt stays on the form with a declaration error. The completed attempt redirects to Dashboard with `Pending Review`. | Intake becomes `pending`; `submitted_at` is set; one checklist item exists per policy and one evidence record per supplied digital file. | Revisit My Application or repeat submission; submitted data must not become an editable second draft. |  |  |
| D2A-M04 | Accounting — `accounting.demo@example.test` | Pending applicant intake | Sign in to Staff Workspace and try the Applicant Review URL or navigation. | Applicant Review is absent or forbidden. | No review, download, or audit record is created. | Direct URL must not bypass role ownership. |  |  |
| D2A-M05 | Registrar — `registrar.demo@example.test` | Pending intake | Open Applicant Review, locate the applicant, inspect the complete intake details and checklist, then download each submitted digital requirement from its checklist item. | Queue hides private drafts; the view shows the intake identity, address, guardian, scope, informational preference, and per-item status. Each download succeeds only through the authorized action. | A restricted admission-evidence access log records the Registrar and source for each download. | Return to the same record; files stay private and are never exposed as public URLs. |  |  |
| D2A-M06 | Registrar — same account | Pending intake with multiple submitted digital items | Accept one digital item and reject another with: `Upload a clearer copy showing the complete name.` | Each decision affects only its selected checklist item; rejection displays the correction note and moves the intake to Action Required. | Accepted evidence stays accepted; rejected checklist/evidence becomes rejected; applicant account becomes `action_required`; reviewer and time are recorded. | Repeat either stale decision; it must be hidden or blocked without changing the other item. |  |  |
| D2A-M07 | Applicant — `applicant.demo@example.test` | Action-required intake | Open Requirements, select the rejected requirement, and upload a different corrected PDF. | Only rejected digital items are selectable; the note is understandable; success states that the prior version remains recorded. | A submitted evidence version links through `replaces_document_evidence_id`; that checklist returns to Received Digital / Not Reviewed; the intake returns to `pending` when no other rejection remains. | Try the rejected file again; unchanged replacement must be blocked while accepted items and history remain unchanged. |  |  |
| D2A-M08 | Registrar — `registrar.demo@example.test` | Corrected submitted evidence; physical original-credentials item visible | Verify the corrected digital evidence. For the physical item, use Record Physical Receipt with a sample reference before deciding whether to verify it. Select Mark for Evaluation, then Approve Application. | Digital review, physical receipt, evaluation, and approval actions appear only in valid states and produce clear notifications. | Digital evidence becomes accepted; physical receipt stores `RECEIVED_PHYSICAL`, actor, time, and audit reference; intake and account progress through `for_evaluation` to `approved`. A non-handover physical item may remain open and carry forward. | Try verifying the physical item before receipt, approval before evaluation, or a repeated old action; each must fail without changing the approved result. |  |  |
| D2A-M09 | Registrar — same account | Approved intake; exactly one active curriculum for the program | Open Hand Over to Student. Read the comparison/preview and confirm handover. | Preview explains program, term, checklist, and profile consequence; success notification links to the Student Profile. | One active Student Profile, student number, pending `new` Enrollment, carried checklist, handover actor/time, and Student role are recorded. | If a handover blocker or curriculum problem is deliberately prepared, the action must explain the blocker and create no partial profile. |  |  |
| D2A-M10 | Same person — `applicant.demo@example.test` | Successful handover | Sign out. Try Applicant Workspace, then sign in through Student Hub. | Applicant Workspace is no longer available; Student Hub opens under the same account as the official student. | No duplicate user or Student Profile is created. | Repeat the Registrar handover URL/action; it must be unavailable or idempotent. |  |  |
| D2A-M11 | Applicant — same baseline account after an approved snapshot restore/rebuild only | Fresh baseline with no intake | Save a draft or submit a pending intake, then select Withdraw Application and confirm. | Warning explains that withdrawal is retained and online continuation stops; completion notification appears; action disappears afterward. | Intake becomes `withdrawn`, `archived_at` and activity event are recorded, account remains in the withdrawal audit state, and no Student Profile is created. | Do not run this row after M09 without restoring the approved baseline snapshot; one account cannot represent both terminal paths simultaneously. |  |  |

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

Replace `MAX` with `MIN` or `MIDDLE` as needed. The output distinguishes `client_reported_faculty` from `synthetic_scheduling_faculty` and prints the bounded faculty-capacity evidence. On an empty database the inspection reports `NOT_READY` and the target manifest. On an exact complete scenario it reports `PASS`. On partial, edited, downstream, or different-scenario data it reports a conflict and writes nothing.

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
| MIN still generated twelve faculty after the client evidence was corrected to nine | Defect / real gap | MIN now generates nine faculty and proves a 19-unit maximum constructed load for its 162 teaching units. |
| MAX treated the reported fourteen faculty as though they were sufficient for the synthetic 178-demand workload | Defect / real gap | The manifest preserves fourteen as client evidence and separately generates twenty-six synthetic scheduling faculty; the distinction is explicit in commands and documentation. |
| Running CP-SAT or choosing Cloud Run resources inside D2C | Out of scope | Not done. D2C prepares stable input manifests; the later approved benchmark gate owns paid solver runs and resource conclusions. |

#### 5.4.5 Programmatic evidence

- `TAL96D2CSchedulingFacultyCapacityAssessmentTest` covers deterministic load assignment, qualification gaps, overload refusal, and the bounded first-passing-roster calculation.
- `TAL96D2COfferingAndScenarioHardeningTest` contains eight focused cases covering the two approved modalities, Term-wide Section source-record-code uniqueness, parent Course Specification modality enforcement, friendly duplicate-group validation, reconciled faculty manifests, executable and rerunnable MIDDLE/MAX scenarios, read-only inspection, conflicting-scenario refusal, and fail-closed behavior after an operator edits a manifest source record.
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
| D4D-PUB-01 | Public visitor | None | Open `/` at desktop width and follow the page from the hero through FAQ. | Institution purpose, three workspace boundaries, Apply and Sign In choices, location, About Us, and published FAQ are understandable in reading order. | Viewing creates or changes no application, account, FAQ, or school record. | Inspect an empty published-FAQ state; the page must provide truthful help guidance without placeholder answers. |  |  |
| D4D-PUB-02 | Public visitor using a keyboard | None | Press Tab from the top, activate the skip link, then navigate the menu, workspace actions, accordions, and scroll control. | Focus is visible, the skip link moves to main content, every control has an accessible name, and headings remain visible without animation. | Navigation and accordion expansion are presentation-only. | Enable reduced motion and repeat the path; content and focus order must remain available. |  |  |
| D4D-PUB-03 | Public visitor | Browser widths near 375, 768, and 1,440 pixels; light and dark system themes | Inspect the hero, collapsed navigation, workspace cards, map, accordions, footer, and bottom strip. | Content reflows without horizontal clipping; contrast and focus remain clear; reduced motion does not hide content. | Theme and viewport changes do not alter application data. | Rotate or resize through the breakpoints; navigation and actions must remain reachable without clipped text. |  |  |
| D4D-PUB-04 | System Super Admin — `system-admin.demo@example.test`; public visitor | One published and one unpublished FAQ | Edit publication/order through the existing FAQ resource, then open `/`. | Only published FAQs appear in configured order; the empty state explains where to obtain help. | Authorized edits change only `FaqEntry`; the public landing remains read-only. | Unpublish all entries and inspect `/`; unpublished content must remain absent and no static fallback answer may appear. |  |  |
| D4D-XR-01 | Applicant, Student, and authorized staff — respective seeded accounts | Activated account for each panel | Compare landing workspace names with each login page and authenticated header. | TALA identity, blue primary color, and role-specific names remain coherent; no page suggests that one account type belongs in another workspace. | Authentication, panel access, and role policies remain unchanged. | Attempt the wrong panel with each account type; the system must deny or route safely without exposing another workspace. |  |  |
| D4D-OUT-01 | Student or authorized staff — respective seeded account | Existing current schedule or Statement of Account | Open and print the schedule and SOA. | The shared official-output presentation and document-specific schedule/finance layout appear without raw CSS or parser text. | Existing builders, authorization, and output-access logging remain authoritative. | Open an unavailable or unauthorized output; the system must deny it or present a truthful empty state without leaking source data. |  |  |

#### 5.12.2 Likely panel questions

| Question | Defense-ready answer |
|---|---|
| Why are there three workspaces instead of one login for everyone? | Applicant identity exists before official student handover, while Student and Staff accounts expose different protected records and actions. Separate entry points make that authorization boundary understandable; backend policies still enforce it. |
| Does the landing page contain its own academic or admissions rules? | No. It explains access and public guidance. Authenticated workflows and their authoritative records remain in the Filament panels, while published FAQ records supply the managed public answers. |
| Will the landing page still work when JavaScript is disabled? | The content, headings, links, workspace explanation, and FAQ text are server-rendered. JavaScript adds only Bootstrap collapse behavior, navbar presentation, theme reaction, and the scroll shortcut. |
| Did the landing redesign add another frontend framework to the application? | No. It refines the already isolated local Bootstrap 5.3 landing assets. Bootstrap is not loaded into the Filament panels, and no dependency was added. |
| Did fixing the stylesheet warning change the schedule or finance calculations? | No. The warning came from injecting a finite Blade slot into a CSS block. Those static rules now live in the shared layout; the schedule and finance builders, records, permissions, and calculations did not change. |

#### 5.12.3 Programmatic and bounded rendered evidence

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

TAL-96D5 is executed as five bounded sub-slices. D5A reconciles evidence and prepares the walkthrough; D5B performs the user-led walkthrough; D5C runs the full regression, security, and integration gate; D5D performs the separately authorized population/configuration and cost study; and D5E consolidates verified facts for TAL-97 and retires the TAL-96D charter.

### 9.1 Charter coverage and disposition ledger

| Charter obligation | Current evidence | D5A disposition | Remaining owner and blocking effect |
|---|---|---|---|
| Preserve aligned implementation and correct only proven gaps | Sections 5 and 6 record the D2–D4 classification and remediation evidence. | Aligned. D5A changes documentation only and identifies no new application defect. | A later observed failure opens only a bounded remediation for its owning journey. |
| Cover every material role and state | The 17-row cross-role matrix maps public, applicant, student, Registrar, Accounting, faculty, Academic Head, System Super Admin, and stakeholder journeys. The consolidated tables contain 69 manual cases. | Programmatic evidence exists; manual evidence remains intentionally blank. | D5B. Blocking until every material case is Passed, explicitly Not Applicable with evidence, or routed through an approved limitation. |
| Answer the operating-order and defense questions | Sections 2, 5, and 6 contain the source facts, but the answers were previously distributed. | Documentation gap corrected by Section 9.2. | D5B confirms the visible explanations; a disagreement with the recorded product rule is a human gate. |
| Maintain reproducible `MIN`, `MIDDLE`, and `MAX` fixtures | Section 5.4 documents guarded commands, manifests, faculty-capacity distinctions, and fail-closed replacement. | Aligned. D5A uses only read-only `--check` commands. | D5B loads `MIDDLE` only after snapshot/rebuild approval. D5D evaluates all three disclosed workload tiers. |
| Use one complete acceptance-table contract | D2 and D3 already used the complete nine-column format; 18 D4 rows used a shortened six-column handoff. | Documentation gap corrected: all manual cases now use role/credential, prerequisites, steps/input, visible result, record/state change, invalid case, Pass/Fail, and Observation. | D5B records the results without creating another manual. |
| Verify authorization, invalid input, failure, retry, and recovery | Focused D2–D4 tests cover role denial, validation, transaction rollback, duplicate delivery, retries, terminal states, safe errors, and owner-scoped outputs. | Programmatic slice evidence is aligned; consolidated adversarial execution remains pending. | D5B performs representative manual attacks; D5C runs the full security and integration regression gate. |
| Report solver correctness, quality, duration, resources, and cost honestly | Section 5.5 explains valid optimization measures and distinguishes feasible, optimal, timed-out, and failed results. Historical profile evidence remains separately documented. | No benchmark claim is changed in D5A. | D5D owns new `MIN-CFG`, `TARGET-CFG`, and `MAX-CFG` evidence and its external/cost gates. |
| Keep architecture, formulation, and operating guide synchronized | The Guide owns journeys and operations; architecture and the standalone formulation own their respective verified technical boundaries. | No current contradiction is introduced by D5A. | D5E performs final synchronization only from D5B–D5D verified results. |
| Satisfy the completion criteria and retire the charter | D1–D4 are completed locally; D5 remains incomplete. | Ownership is made explicit in Section 9.5. | D5E is the only retirement boundary. |

### 9.2 Operating-order closure table

| Required question | Defense-ready answer | Evidence and current disposition |
|---|---|---|
| What must exist before each major journey can proceed? | Applications require an active intake scope, program, and effective requirement policy. Curriculum import requires the academic structure and accepted template. Offerings require an active academic period and approved curriculum records. Scheduling requires complete offerings, sections, groups, rooms where applicable, qualified faculty, availability, and an operating grid. Enrollment requires a Student Profile, open enrollment window, compatible published sections, progression facts, and required clearances. Assessment requires confirmed placement and fee rules. Payment requires an active assessment with a positive due. Grading requires official enrollment, roster creation, and assigned faculty. Progression and graduation require released academic results and their applicable clearance evidence. | Section 2 and the intended-flow subsections in Section 5. Programmatically verified; D5B confirms user-facing guidance. |
| What happens when an academic prerequisite record is missing or closed? | The action stops before downstream mutation and identifies the missing, closed, unpublished, incompatible, or incomplete source. A candidate schedule cannot substitute for publication, and a missing enrollment window cannot be bypassed through student or staff placement actions. | D2B, D2C, D3A, and D3B named tests; D2B-M01, D2C-M03, D3A-M01, D3B-M11. Programmatic pass; D5B manual pending. |
| When and where does an applicant become a student? | The person remains an Applicant through submission, review, correction, evaluation, and approval. An authorized Registrar's explicit handover transaction creates or reuses one Student Profile, assigns the Student role, and starts the initial pending Enrollment. Approval alone is not enrollment. | Section 5.2 and D2A-M08 through D2A-M10. Programmatic pass; D5B manual pending. |
| How do regular and irregular enrollment differ? | A regular student is placed into one complete published logical-cohort block. An irregular student proposes a complete set of compatible published sections; the proposal holds no seat. Registrar or System Super Admin confirmation rechecks the full set transactionally and creates reservations and schedule bindings. | Sections 2.1 and 5.6; D3B-M02 through D3B-M08. Programmatic pass; D5B manual pending. |
| Where does an irregular student remain while waiting, and what prevents invalid placement? | The person remains an active Student Profile with a term Enrollment, never an Applicant again. Selection waits for an open enrollment window and compatible published sections. Ownership, curriculum/progression eligibility, prerequisites, units, conflicts, publication, lifecycle, capacity, and row locks prevent invalid or partial placement. TALA does not invent a fixed waiting duration. | Sections 2.1, 5.6, and 6.2. Programmatic pass; D5B manual pending. |
| How are additional sections created and how is capacity determined? | The Registrar creates another Section under the relevant Term Offering and completes its delivery-group, faculty, room, and scheduling inputs. It enters the normal scheduling and publication workflow; adding a student does not rerun the solver by itself. Capacity is controlled by the configured Section, applicable face-to-face room capacity, published meetings, and confirmed active reservations or bindings—not an institution-wide ceiling. | Sections 3, 5.4, and 6; D2C-M02/M03 and D3B capacity cases. Aligned product rule; D5B manual pending. Shared cross-program combined sections remain TAL-175. |
| What does each role see before and after a state change? | The acting office sees its source record, permitted action, current status, blocker, responsible office, and result. Affected users receive only authorized projections: Applicant status and requirements; Student enrollment, finance, COR, schedule, holds, grades, and completion; Faculty assigned schedule and rosters; Academic Head read-only academic oversight; Accounting finance and authorized COR evidence; System Super Admin operational integration evidence. Candidate, staff-only, private, and other-owner records remain hidden. | Section 4 maps every cross-role source and projection. D4 presentation tests pass; D5B validates comprehension and consistency. |
| What happens after invalid input, rejection, payment failure, duplicate delivery, queue delay, solver timeout, or a downstream transaction failure? | Invalid input produces field or action guidance and no unauthorized partial write. Rejected applicant evidence moves only that item to correction and preserves accepted evidence. A redirect, decline, cancellation, or expiry does not post payment. Signed webhook and reconciliation processing are idempotent, and delayed or failed local events remain visible for controlled recovery. A solver timeout or unknown result remains non-publishable unless a valid candidate passed independent Laravel validation; infrastructure failure is distinct from infeasible input. Transactional handover, placement, payment posting, and officialization roll back partial effects when their operation fails. | Sections 5.2, 5.5, 5.6, 5.7, and 5.8. Programmatic pass; D5B handles visible recovery and any authorized sandbox evidence, while D5C closes full regression. |
| Which results are provisional, which are official, and who finalizes them? | A submitted or approved application remains an admissions record until Registrar handover. A solver candidate remains provisional until Laravel validation and Registrar publication. A student proposal remains provisional and holds no seat until authorized staff confirmation. Checkout return or provider state is not official finance evidence until verified payment and ledger posting. Grades remain staff-controlled until authorized release. Progression and graduation projections remain controlled snapshots until the owning office releases or records the institutional result. | Sections 5.2, 5.5–5.8, and 5.10. Programmatic pass; D5B manual pending. |

### 9.3 Scenario and operator readiness

The D5A read-only inspection found no active acceptance scenario in `test_tala_db`. This is a safe empty starting condition, not a failure.

| Scenario | Read-only command | D5A current result | Permitted next use |
|---|---|---|---|
| `MIN` | `php artisan acceptance:seed-scheduling-scenario MIN --check --no-interaction` | `scenario_state=empty`, `readiness=NOT_READY`; target manifest remains inspectable | Retain for current-client comparison and D5D `MIN-CFG`; do not use as the complete D5B walkthrough. |
| `MIDDLE` | `php artisan acceptance:seed-scheduling-scenario MIDDLE --check --no-interaction` | `scenario_state=empty`, `readiness=NOT_READY`; target manifest remains inspectable | Default D5B fixture after an approved snapshot-and-rebuild gate. It supplies all three programs and year levels without claiming to be a client census. |
| `MAX` | `php artisan acceptance:seed-scheduling-scenario MAX --check --no-interaction` | `scenario_state=empty`, `readiness=NOT_READY`; target manifest remains inspectable | Retain for D5D `MAX-CFG`; do not load during D5B unless a separately approved diagnostic requires it. |

The D5B operator must first prove `APP_ENV=testing`, MySQL, and `test_tala_db`, receive approval for the snapshot/rebuild operation, create exactly one `MIDDLE` scenario, rerun its `--check`, and stop if the command reports conflict. The D4B state overlay is applied only when its grade/lifecycle lane is reached. It does not replace the population scenario or invoke CP-SAT.

### 9.4 Restore-safe D5B execution lanes

| Lane | Required starting state | Cases and action | Checkpoint or human gate | Durable result |
|---|---|---|---|---|
| 0. Environment and fixture readiness | Empty guarded testing database | Prove the environment; inspect all three manifests; create and verify `MIDDLE`. | Human approval for snapshot/rebuild and persistent scenario creation. Record baseline checkpoint `S0` after exact `MIDDLE` verification. | One deterministic representative fixture. |
| 1. Public entry, identity, and workspace boundaries | `S0`; verified demo accounts | D4D public/accessibility/cross-role cases and D2A wrong-panel cases. | No restore unless account state is changed. | Entry and authorization observations only. |
| 2. Admissions and handover | `S0`; active admission scope and requirements | Execute the main D2A draft-to-handover path. Exercise withdrawal as a separate terminal branch. | Run withdrawal from an isolated copy of `S0`, restore `S0`, then execute the main handover path. Never attempt handover and withdrawal on the same retained intake. | Main branch retains one handed-over Student Profile and initial Enrollment. |
| 3. Academic setup and scheduling preparation | Main branch plus complete MIDDLE academic sources | Execute D2B/D2C inspection and invalid-input cases without replacing authoritative fixture records; then generate demands. | Any destructive edit to a fixture source uses an isolated checkpoint and restore. One functional external solver call requires separate Cloud authorization; it is not a capacity benchmark. Record `S1` after valid publication. | One reviewed and published official schedule. |
| 4. Regular and irregular enrollment | `S1`; open enrollment window and published compatible sections | Execute D3B regular, irregular, blocker, capacity, replacement, responsive, and terminal-state cases. | Cancellation and expiry are terminal branches. Run them against isolated records or a restorable copy of `S1`; preserve a main branch with confirmed placement. Record `S2` before finance. | Confirmed reservations and official schedule bindings on the main branch. |
| 5. Finance, payment, COR, and holds | `S2`; assessment and fee rules | Execute D3C finance/recovery and D3D official-enrollment/output cases. | PayMongo sandbox or dashboard work requires credentials and external-service approval. Hold-blocked and clear-output states must use separate records or an isolated checkpoint. Record `S3` after verified ledger posting and official enrollment. | Current official Enrollment with traceable finance and output evidence. |
| 6. Grades, lifecycle, reports, outputs, and notifications | `S3`; official enrollments and released schedule | Apply the guarded D4B overlay; execute D4B, D4C, and remaining D4D output cases. | Overlay is repeatable and test-only. No population switch or Cloud solve occurs. | Representative downstream states and cross-role projections. |
| 7. Adversarial and recovery closure | Completed main branch and retained checkpoints | Execute wrong-role, stale/repeated action, invalid input, owner-isolation, empty, blocked, retry, timeout, and recovery cases not already observed. | A failing case opens bounded remediation and reruns only affected cases. No manual database repair. | Completed Pass/Fail/Observation columns and a routed defect or limitation for every failure. |

### 9.5 D5 completion ledger

| Completion criterion | Owning slice | Ready when |
|---|---|---|
| Acceptance package is complete, consistently formatted, and executable | D5A | This section, the cross-role matrix, every manual table, and the charter boundary pass independent verification. |
| Material journeys and state variations have manual evidence | D5B | Every material case is Passed, explicitly Not Applicable with evidence, or routed through an approved limitation after bounded remediation. |
| Full regression, authorization, security, queue, payment, and integration readiness is established | D5C | The approved full-suite and security/integration gates pass after D5B remediation, with the testing database restored as authorized. |
| `MIN`, `MIDDLE`, and `MAX` fixtures and safe operator procedures are verified | D5B and D5D | D5B proves the representative operator path; D5D confirms the three workload manifests used by the targeted study. |
| Targeted capacity, solution-quality, resource, and cost evidence is complete or honestly bounded | D5D | Authorized results use the charter classifications and measures without inventing an accuracy percentage or rerunning an unnecessary full cross-product. |
| Architecture, formulation, Guide, and deployment-readiness statements reflect verified facts | D5E | Documents contain only D5B–D5D verified claims and disclose remaining limitations. |
| TAL-97 can present and defend the system using verified evidence | D5E | The final presentation handoff identifies the approved demo sequence, expected results, recovery path, likely questions, and claim boundaries. |
| The TAL-96D charter no longer competes as an active authority | D5E Cleanup | The charter is marked complete and retained or archived as governance history after its outcomes are consolidated. |
