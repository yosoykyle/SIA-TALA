# TALA System Operations and Defense Guide

**Document status:** TAL-96D2A programmatic acceptance complete; user-led manual acceptance pending, dated 2026-07-22
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

An automated pass is not, by itself, final user acceptance. A browser pass does not replace business-policy confirmation. Final acceptance is reserved for TAL-96D5 and formal presentation readiness for TAL-97.

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

The waiting period for irregular selection is therefore the interval between the institution's enrollment opening and the publication of compatible sections. TALA must not invent a fixed waiting time. The institution controls this through its term calendar and publication process. The current implementation does not yet enforce this relationship end to end; the gap is recorded in Section 6 for TAL-96D3 correction.

## 3. Reproducible Client-Aligned Acceptance Baseline

The baseline uses the client's reported current population as its scale anchor. It is not an institutional maximum.

| Program | First year | Second year | Total |
|---|---:|---:|---:|
| Diploma in Tourism Business Management (DTBM) | 10 | 2 | 12 |
| Diploma in Information Technology (DIT) | 10 | 3 | 13 |
| Diploma in Tourism Hospitality Management (DTHM) | 15 | 7 | 22 |
| **Total** | **35** | **12** | **47** |

The six reported program/year groups are represented as six regular cohort identifiers. Their approved curricula produce 54 course delivery demands. The fixture also contains an active second-semester term, rooms, faculty qualifications, availability and load data, fee rules, and verified test accounts. Names, personnel, rooms, and availability are synthetic. They provide complete relational inputs for acceptance testing and do not claim to reproduce the client's real personnel or published timetable.

There is no universal 100-student limit in the accepted product rule. TALA controls occupancy through configurable section capacity, physical-room capacity for face-to-face meetings, published offerings, and Registrar-confirmed seat reservations. The current population of 47 is evidence of client scale, not a coded maximum.

### 3.1 State dimensions and scenario anchors

Student state is not one label. The fixture keeps these dimensions separate:

| Dimension | D1 starting state | Why it is separate |
|---|---|---|
| Primary lifecycle | All 47 Student Profiles are `Active` | Lifecycle describes whether a person remains an active student master record. |
| Academic standing | Deliberately distributed across the nine PRD values | Standing describes curriculum progression, not payment or enrollment completion. |
| Term enrollment | No enrollment records yet | A student may exist before beginning a particular term enrollment. |
| Financial state | No assessments, ledger entries, or payments yet | Financial standing is derived from term charges, ledger effects, payments, accommodations, and holds; it is not a permanent student label. |
| Modality | Stored on term offerings and published meetings | Online, face-to-face, or modular delivery describes a class offering, not an immutable type of student. |

Ten deterministic records anchor later adverse and cross-role journeys. All other students begin as `Regular`.

| Student number | Academic standing | Later journey represented |
|---|---|---|
| `DTBM-1A-001` | Regular | Standard cohort progression and the representative Student Hub login |
| `DTBM-1A-002` | Irregular | First-year individual subject-selection path |
| `DTBM-2A-001` | Irregular | Continuing irregular selection path |
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
| D1-01 | Public user | Choose the correct workspace | None | `/` | Public configuration and FAQ | Read-only | Clear applicant, student, and staff routes; unavailable actions must not be implied | Route coverage | Mobile public page inspected at 360×800 | Pass for entry clarity and no horizontal overflow | D2 if identity wording changes |
| D2-ID-01 | Applicant, student, staff | Authenticate only into the assigned panel | Verified, active account and canonical role | Panel login pages | User, role, permission, student profile | Editable only through approved account flows | Valid user reaches intended panel; wrong, unverified, inactive, or archived access is denied or routed to verification | Panel, authentication-eligibility, email-verification, and D2A service-authorization tests | Applicant, student, and registrar login samples inspected; final user-led checklist pending | Programmatic pass | TAL-96D2A |
| D2-AD-01 | Applicant | Start, save, submit, correct, or withdraw an application when allowed | Active term, active program, effective requirement policy, verified applicant account | Applicant Dashboard, My Application, and Requirements | Applicant intake, checklist item, and document evidence | Draft is editable; each applicable digital requirement has its own private upload; rejected digital evidence is replaceable; withdrawal is restricted to an unreviewed draft or pending intake | Required fields, declaration, file constraints, duplicate checks, status, correction reason, and blocked actions remain explicit | Wizard, partial-draft, policy-driven multi-upload, mixed-evidence, declaration, active-scope, duplicate, invalid-replacement, correction, and withdrawal tests | Previous single-upload surface is superseded; revised user-led D2A checklist pending | Programmatic pass | TAL-96D2A |
| D2-AD-02 | Registrar | Review evidence, move the intake through evaluation and approval, and perform explicit handover | Submitted intake, authorized active Registrar, resolved handover blockers, and exactly one active curriculum | Applicant Review | Applicant intake, checklist/evidence history, output-access log, Student Profile, and initial Enrollment | Read-only evidence and preview with focused review, approval, download, and handover actions | Decisions follow the allowed order; stale/repeat/wrong-role actions fail without mutation; handover creates or explicitly reuses one profile | Registrar action, private-download audit, stale/repeat, blocker, curriculum, first-time, transfer, returning, and failed-handover tests | Exact user-led Registrar and post-handover checklist pending | Programmatic pass | TAL-96D2A |
| D2-AS-01 | Registrar / Academic Head | Establish a valid academic period | Authorized staff | Academic Years, Terms, Calendar Windows | Academic year, term, calendar event | Editable before dependent records lock it | Invalid dates, missing windows, or inactive terms prevent dependent operations clearly | Existing academic setup and scheduling-readiness tests | Pending adversarial browser audit | Routed | TAL-96D2 |
| D2-AS-02 | Registrar | Maintain catalog and curriculum | Active program and authorized staff | Programs, Course Catalog, Specifications, Curriculum Versions, Import Batch Audit | Program, course, specification, curriculum, import batch | Editable under lifecycle rules | Import and manual entry preserve source meaning; malformed input returns row-level guidance | Existing import/catalog tests | Pending file-based UAT | Routed | TAL-96D2 |
| D2-OF-01 | Registrar / Academic Head | Build schedulable offerings | Valid term, curriculum, rooms, qualified faculty | Term Offerings, Sections, Scheduling Demand | Offering, section, delivery group, faculty qualification, room | Editable before publication boundaries | Readiness findings identify missing or conflicting inputs before solving | Client baseline and readiness tests | Registrar demand list inspected at 1366×768; 54 ready demands visible | Baseline pass; mutation/error journeys pending | TAL-96D2 |
| D3-SC-01 | Registrar / Academic Head | Generate, review, and publish a timetable | All demands ready; solver integration available | Scheduling Demand, Solver Runs, Official Schedules | Demand, generation run, meeting, revision event | Controlled action and review | Solver status, conflicts, objective evidence, and publication state remain distinguishable | Existing scheduling and solver contract tests | Full operational journey pending | Routed | TAL-96D3 |
| D3-EN-01 | Registrar / Student | Enroll regular and irregular students through explicit gates | Student profile, enrollment window, published compatible offerings, progression facts, required clearances | Enrollments and the planned irregular selection surface | Enrollment, course enrollment, gate result, reservation, exception | Student proposes where policy permits; Registrar confirms | Each failed gate names the responsible office and next action. Irregular choices must be filtered and validated for prerequisites, corequisites, units, conflicts, and remaining capacity. | Existing placement tests cover publication, capacity, conflict, reservation, and lifecycle; the full irregular contract is not yet covered | Full regular and irregular journeys pending | Partial implementation; routed | TAL-96D3 |
| D3-FI-01 | Accounting / Student | Assess fees and process the current due | Enrollment and active fee rules | Assessments, Payments, Student Finance | Assessment, fee line, ledger entry, payment attempt, payment | Accounting editable; student evidence view with payment initiation | Amount due is derived from assessment and ledger; unavailable payment is disabled and explained | Existing finance and PayMongo tests | Student Finance empty state inspected at 360×800 | Empty state pass; populated journey pending | TAL-96D3 |
| D3-CO-01 | Student / Registrar | View and issue the COR and schedule | Official enrollment and published schedule | Student COR and Class Schedule | Enrollment, course enrollment, meeting, output snapshot | Read-only output | Missing output shows a clear state; published data is consistent across roles | D1 render harness and existing output tests | Mobile Class Schedule shows explicit “No schedule available” state | Empty state pass; populated cross-role comparison pending | TAL-96D3 |
| D3-IN-01 | System Super Admin / Accounting | Monitor and recover integrations | Authorized role and recorded operational event | Integration Status, PayMongo Reconciliation | Operational event, payment attempt, webhook call | Controlled recovery action | Duplicate, delayed, rejected, and retried events remain auditable and idempotent | Existing integration tests | Pending browser recovery audit; no external calls in D1 | Routed | TAL-96D3 |
| D4-GR-01 | Faculty / Registrar / Student | Enter, review, release, and view grades | Enrollment, roster, assigned faculty | Grade Rosters, Faculty Grade Roster, Student Grades | Grade roster, grade entry, revision event | Role- and state-dependent | Draft, submitted, reviewed, released, and revised states are distinct | Existing grade tests | Pending end-to-end browser audit | Routed | TAL-96D4 |
| D4-LC-01 | Registrar / Student | Manage holds and lifecycle decisions | Student master and applicable source evidence | Lifecycle Changes, Graduation Review, Student Holds and Academic Status | Hold, lifecycle change, progression result, graduation batch | Staff-controlled; student read-only | Responsible office, reason, effect, and resolution remain understandable | Existing lifecycle tests | Pending cross-role browser comparison | Routed | TAL-96D4 |
| D4-SH-01 | Student | Understand current academic and financial state | Accessible student profile | Student Hub | Aggregated authoritative records | Read-only except permitted profile fields | Empty, blocked, pending, and complete states provide actionable guidance | D1 route harness | Dashboard, Finance, and Schedule sampled on mobile | Sampled pass; comprehensive state audit pending | TAL-96D4 |
| D4-RP-01 | Authorized staff | Produce reports and trace changes | Source records and role permission | Reports / Audit and Import Batch Audit | Audit logs, operational events, import records, output snapshots | Read-only and export actions | Report totals reconcile with source records; sensitive evidence stays permission-bound | Existing report/audit tests | Pending browser and export audit | Routed | TAL-96D4 |
| D5-AC-01 | Project team / stakeholders | Attempt invalid, out-of-order, and hostile journeys before defense | Completed D2–D4 corrections | All representative surfaces | Whole-system evidence | Mixed | The system prevents invalid transitions, explains recoverable errors, and records sensitive actions | Full suite and final adversarial harness | Stakeholder UAT and defense rehearsal | Pending | TAL-96D5 |

## 5. Acceptance Evidence

### 5.1 TAL-96D1 Browser Baseline

The bounded browser audit used the deterministic baseline and test-only accounts. It did not submit applications, generate schedules, enroll students, initiate payments, or call external services.

| Viewport | Surface | Observed result |
|---|---|---|
| 360×800 mobile | Public entry | Applicant, student, and staff destinations were explicit; no horizontal document overflow was detected. |
| 360×800 mobile | Student Dashboard, Class Schedule, Finance | Authentication succeeded. Navigation rendered. Schedule and finance showed explicit unavailable/empty states, disabled unavailable payment, and responsible-office guidance. No horizontal document overflow was detected. |
| 768×1024 tablet | Applicant Dashboard and Application | Historical D1 evidence confirmed authentication and the earlier single-upload surface without horizontal overflow. TAL-96D2A has since replaced that form with the approved Wizard and policy-driven multi-upload; the revised surface requires the user-led checklist in Section 5.2.4. |
| 1366×768 desktop | Registrar Dashboard and Scheduling Demands | Authentication succeeded. Registrar-authorized navigation rendered, and the table exposed 54 client-aligned demands with section, delivery group, course, component, modality, duration, readiness, findings, and check time. |
| Browser default | Regular anchor `DTBM-1A-001` Student Dashboard | Authentication succeeded. The page showed lifecycle `Active`, academic standing `Regular`, zero ledger-derived balance, and no active holds. |
| Browser default | Irregular anchor `DTBM-1A-002` Student Dashboard | Authentication succeeded. The page showed lifecycle `Active` and stored academic standing `Irregular`. No enrollment or subject-selection entry was available in Student Hub. The page also displayed a computed `Recommended: Regular; blockers: 0` description without explaining how that recommendation differs from the stored standing. |

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
| Irregular selection | Student choices must come from published compatible offerings and pass prerequisite, corequisite, unit, conflict, and capacity rules before Registrar confirmation | The staff action lists active published sections and confirmation checks term, publication, lifecycle, time conflict, and capacity. It does not yet provide the PRD's complete student selection surface or prove prerequisite, corequisite, and unit filtering at selection. | Required D3 correction. Do not claim the irregular journey is complete and do not rerun the master solver for each irregular student. |
| Enrollment calendar | The configured term calendar must control when enrollment and edits are available | `CalendarEvent` defines an enrollment process window, but the enrollment start action does not consume it. The registered edit-window middleware is not attached to the current routes and its service reads retired term-column names rather than the canonical calendar-event model. | Required D3 correction before enrollment timing is defended as enforced. |
| Student Hub standing explanation | The student must be able to distinguish the official stored standing from a newly computed progression recommendation | The irregular anchor correctly renders `Irregular`, but the adjacent recommendation says `Regular` with zero blockers because the D1 starting fixture has no downstream progression evidence. The interface does not label the two values as current versus recommended or explain that staff confirmation owns the official change. | Required D4 UI/content correction; preserve the separate source records and do not silently overwrite the official standing. |
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
| When does an irregular student receive a schedule? | After compatible sections are published and the enrollment window permits selection. Registrar confirmation creates the reservation and student schedule bindings. The current end-to-end calendar and selection gap must be corrected in D3. |
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

This document will be expanded with verified inputs, outputs, failure demonstrations, and presenter guidance as TAL-96D2 through TAL-96D5 complete.
