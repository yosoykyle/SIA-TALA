# TALA Workflow Reconciliation Matrix

**Status:** Active requirements audit and SDD dependency control

**Audit date:** 2026-06-19

**Primary business baseline:** `business-evidence/INSTITUTION WORK  FLOW CURRENT.md`

## Purpose and Authority

This matrix reconciles the client's current institutional workflow with the Functional Specification (FS), Technical Specification (TS), implemented code, and executable tests. The workflow defines approved business outcomes, roles, policies, and current operating values. FS/TS designs remain valid where they satisfy those outcomes with stronger security, auditability, usability, or adaptability.

No source wins wholesale. For every feature:

1. Preserve compatible implemented behavior and stronger controls.
2. Reopen only the conflicting or missing behavior.
3. Treat current-school numbers, prices, thresholds, and time windows as effective-dated configuration unless a binding rule requires a universal value.
4. Keep manual external systems and physical-office practices outside TALA unless an explicit system boundary, export, or evidence record is required.
5. Benchmark legal, regulatory, privacy, progression, grading, and other high-impact rules before implementation.

## UAT Rescue Overlay (2026-06-21)

During the rescue period, this matrix also controls whether a gap affects the immediate UAT baseline.

| UAT impact | Meaning |
| --- | --- |
| `UAT_CORE` | Blocks or directly affects the working SIS demo path and must be addressed, hidden, or marked failed/blocked before presentation. |
| `UAT_CORE_LITE` | Must be visible or safely limited for UAT, but may remain read-only or partial if test cases state the boundary. |
| `UAT_SUPPORTING` | Useful but does not outrank unresolved core flow failures. |
| `UAT_DEFERRED` | Valid goal-state/research feature, not an immediate UAT blocker. |
| `UAT_EXTERNAL` | Manual/external process; TALA only needs evidence, export, or boundary statement for the rescue baseline. |

Default rescue mapping:

- Authentication/RBAC, applicant intake, admission-document review, enrollment/section/finance clearance, student record, faculty class/grade operation, and completion/graduation boundary are `UAT_CORE`.
- Student Hub profile/status/schedule/grades/balance visibility is `UAT_CORE_LITE`; advanced Student Hub/PWA behavior is `UAT_DEFERRED`.
- Enrollment adjustment workflows are `UAT_SUPPORTING` unless they appear in the chosen presentation path.
- The document-request portal/catalog/fee/fulfillment/pickup/courier/shipping lifecycle is removed from TALA scope. Refund lifecycle, advanced analytics, and public FAQ polish are `UAT_DEFERRED` unless the user explicitly promotes them. External DepEd/CHED/LIS portal encoding is `UAT_EXTERNAL`; the generic school-year/term enrolled-student inventory remains part of the TALA core/supporting baseline.

## College-Only Scope Correction (2026-06-21)

The institution has removed SHS from the target TALA deployment. Active scope is College-only. SHS-specific enrollment, offering, grading, fee, scheduling, faculty, and UAT workflows are deprecated and must not guide implementation. Grade 12, Form 138, Form 137, Good Moral, PSA, and similar records remain valid only as prior-education admission credentials for College applicants. Downstream cleanup is tracked under `SDD-00C College-Only Scope Correction` before resuming `SDD-07A`.

## Document-Request Scope Removal (2026-06-21)

The institution's manual official-document request process remains factual business evidence, but it is outside the TALA product boundary. TALA must not implement a document-request portal, catalog, request fee/payment path, fulfillment queue, pickup/claiming workflow, courier/delivery workflow, shipping fee/debt automation, or related permissions, routes, jobs, seed data, and UAT cases. Admission-document intake/review and generated core artifacts such as COR and finance evidence remain in scope as independent capabilities. This permanent removal is a blocking `SDD-00D` rebaseline item before feature development resumes.

### Feature-Group Baseline Coverage (2026-06-21)

All eleven benchmark feature groups now have a goal-state FS acceptance contract and TS implementation contract. This records specification coherence only; the detailed rows below still determine code status and unresolved decisions.

| Feature group | Detailed reconciliation owner | Dominant classification / rescue treatment |
| --- | --- | --- |
| Identity and access | Security/access rows and SDD-04 | `ALIGNED`/`RETAIN_STRONGER`; `UAT_CORE` |
| Admissions and OCR | Section B / SDD-07A | Mixed `RECONCILE`, `MISSING`, `RETAIN_STRONGER`; `UAT_CORE` |
| Enrollment, capacity, inventory, COR | Section B / SDD-07A | `MISSING` for remaining handover/roster deltas; `UAT_CORE` |
| Scheduling | Section A plus SDD-03/SDD-04 | Existing stronger solver controls retained; `UAT_CORE` or `UAT_SUPPORTING` by demo path |
| Finance | Finance rows / SDD-06A-F | Core posting partly aligned; reconciliation/refund deltas remain; `UAT_CORE`/`UAT_SUPPORTING` |
| Faculty and grades | Grade/progression rows / SDD-08A | Mixed aligned controls and benchmark-gated policy; `UAT_CORE` |
| Official generated artifacts | Section C / SDD-07A, SDD-08A, and owning workflows | COR controls are core; generated artifacts are produced only by their authoritative enrollment, grade, finance, or completion workflows. No document-request lifecycle exists. |
| Student Hub/PWA | Student visibility rows / SDD-08B | Group 8 lock-audited; backend/read-only boundary retained; current UI/PWA gaps explicitly bounded; `UAT_CORE_LITE` |
| Student status and completion | Section D / SDD-07D-E | Group 9 lock-audited; mostly `MISSING`/`RECONCILE`; completion boundary core, full lifecycle supporting/deferred |
| Imports, exports, reports | Shared/external rows / SDD-09 | Group 10 lock-audited; controlled curriculum import aligned, generic exports/reporting missing/supporting, portals external |
| Attendance, behavior, guidance | Section D / SDD-08B | Group 11 lock-audited; `BENCHMARK_GATE`; `UAT_DEFERRED` until typed evidence, policy, privacy, notice/appeal, and resolution exist |

## Classification Legend

| Classification | Meaning |
| --- | --- |
| `ALIGNED` | FS/TS and implementation satisfy the approved outcome. |
| `RETAIN_STRONGER` | Existing design is compatible and provides better control or adaptability. |
| `RECONCILE` | Sources or runtime behavior conflict; change only the incompatible portion. |
| `MISSING` | Required workflow has no complete FS/TS and/or typed implementation. |
| `REMOVE` | Rejected product scope; delete active requirements and runtime implementation while preserving factual historical/manual evidence where applicable. |
| `EXTERNAL_BOUNDARY` | Activity remains in another system or manual operation; TALA may export or retain evidence only. |
| `BENCHMARK_GATE` | Implementation must wait for an authoritative rule or explicit client policy decision. |

## A. Shared Policy, Calendar, and Progression

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| College calendars, enrollment windows, adjustment windows, exams, and term close | FS 5.1.1, 10; TS 3.18 | `AcademicYear`, `Term`, `CalendarPhaseGateService`; calendar service now uses College-only term gates without an education-level branch | `RECONCILE` | Keep College term/phase gates only. Academic Head approval and minimum-day evidence remain a later admin contract. | SDD-00C / SDD-04 / SDD-09 |
| Automatic and manual scheduling use immutable inputs, conflict-safe assignment, controlled publication, and auditable post-publish revision | FS 5.3; TS 1.4 and 3.6.3; BM-06/BM-11/BM-16/BM-17 | CP-SAT package, Cloud Run adapter, snapshots, queue job, review/commit/publish, and typed schedule changes exist. Runtime also emits undocumented `partial`/`model_invalid`; the assignment guard blocks approved Apply after publication. | `RECONCILE` | Retain the deployed CP-SAT architecture and hard-conflict gates. Normalize solver/domain outcome semantics and separate timeout evidence. Fix the schedule-change path so the published-term guard blocks direct create/edit but permits an approved lifecycle Apply after revalidation and audit. | SDD-03 / SDD-04 |
| Current institution has a 100-active-student ceiling; inquiry/slip does not secure a seat; OR payment does | FS 4.1, 5.3.4; TS 3.4 | Section/group capacity exists; no campus admission-capacity plan | `RECONCILE` | Add stacked effective-dated College capacity plans by term with optional campus, program, year level, and delivery scopes. Do not hardcode 100 platform-wide. | SDD-00C / SDD-07A |
| Five continuing-enrollment gates: finance, admission/retention documents, behavior, discipline, and academic standing | FS 2.3/4.2/5.2/6; TS 1.4; BM-26/BM-27 | Finance, document, and grade evidence are partial; no attendance, behavior, discipline, or guidance source exists. | `BENCHMARK_GATE` | Create a typed clearance-evaluation result with independent reasons. Do not enforce behavior, discipline, attendance, or guidance holds until their evidence sources, privacy rules, notice/response, appeal/review, and authorized resolutions exist. | SDD-07D / SDD-08B |
| Deprecated SHS progression/remediation | Historical workflow evidence only | Active runtime cleanup completed; remaining SHS references are negative guard tests or archived/historical evidence | `RECONCILE` | Keep SHS progression, remediation, grade-level promotion, and Grade 11/12 enrollment behavior out of active specs, UI, services, seeders, and tests. Preserve archived evidence only for history. | SDD-00C |
| College probation, repeat-year, irregular, and subject-retake outcomes | FS has prerequisite and irregular-load rules but no approved retention profile | Subject suggestions and finalized grades exist; no GWA probation service | `BENCHMARK_GATE` | Treat thresholds and repeat-year effects as institution-approved, effective-dated retention policy. Do not infer a universal CHED threshold. | SDD-05C-R |
| Summer/remedial offering is discretionary; 6-9 unit current cap; College pricing is unit-based | FS 5.3.6 and 4.2 propose summer loads, currently with a generic 30-unit regular cap | Summer term type exists; no complete offering/assessment workflow | `RECONCILE` | Keep summer as a separate College term/offering with configurable load and fee policy. The current 6-9-unit value is deployment configuration. | SDD-00C / SDD-05C-R / SDD-06E |
| Government minimum-day/compliance reports | FS/TS calendar and export boundaries | Calendar data exists; no regulator submission integration | `EXTERNAL_BOUNDARY` | Store calendar evidence and provide generic exports. DepEd/CHED/accreditation portal submission remains external. | SDD-09 |
| Controlled imports, exports, and report artifacts | FS §2.3; TS §1.4; BM-10 | `ImportBatch`, `CurriculumImportService`, `ImportBatchLifecycleService`, `ImportBatchResource`, and focused import tests prove curriculum import only; generic roster/report export services are not complete. | `RECONCILE` | Preserve current private curriculum import pipeline. Build each future import/export/report through a typed service with template/schema version, validation preview, explicit commit or field allowlist, audit, and private/temporary artifact handling. Do not expose generic spreadsheet editing or claim unsupported import types. | SDD-09 / SDD-07A |

## B. Admission, Enrollment, Documents, and Placement

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Admission requirements vary across education level, entry route, prior credential, citizenship/compliance, program/grade, and support attributes; these dimensions can overlap | FS 4.1.2; TS 1.3.1 and 3.12.1 | `ApplicantIntake.required_documents` snapshots a hardcoded service list and has no composable offering/policy resolver | `RECONCILE` | Use one generic admission lifecycle. Publish term-scoped offerings; compose deterministic versioned requirement rules; snapshot source versions per intake; keep unsupported offerings inactive. Treat IP and disability/SEN as purpose-limited support attributes, not mutually exclusive applicant types, admission gates, or denial grounds. Collect restricted evidence only for configured accommodations/support purposes. | SDD-07A |
| Initial intake exposure and returning/cross-enrollee boundaries | FS 4.1.2; TS 1.3.1 and 3.12.1 | Public intake exists; returnee detection is partial; no legacy onboarding/readmission service; no cross-enrollee lifecycle | `RECONCILE` | Publish College Freshman and College Transfer offerings only until additional College pathways are approved. Route returning students through Registrar-assisted lookup/readmission. Treat old-curriculum, ALS, IP, PWD/SEN, and foreign cases as College applicant attributes/pathways with purpose-limited evidence, not SHS offerings. Keep cross-enrollee inactive until institution acceptance is confirmed. | SDD-00C / SDD-07A / SDD-07D |
| Admission-gate documents are required before official enrollment; non-critical retention documents may follow | FS Step 5 is updated, but FS Step 7/payment sections and TS state machine still contain stale all-physical gating | `ApplicantIntakeService`, `EnrollmentHardCopyReceiptService`, and finance clearance use coarse completeness behavior | `RECONCILE` | Materialize per-item admission-gate versus retention classification and accepted evidence method. Remove the profile-wide hard-copy flag as authority. | SDD-07A |
| Retention undertaking uses itemized missing documents, 30-60-day due date, monitoring, reminders, and documentary hold | FS Step 5; TS has no implemented undertaking model | No undertaking/deadline/reminder service | `MISSING` | Add configurable due date within approved policy, extensions, reminders, receipt history, resolution, and next-cycle/document-release hold effects. No silent cancellation. | SDD-07A |
| Applicant uploads are preliminary; Registrar verifies authenticity and physical/certified evidence as configured | FS 4.1.2; TS 1.3.1 and 6.1 | Private uploads, OCR, manual review, replacement history partly exist | `RETAIN_STRONGER` | Preserve canonical private file plus extracted text and human verification. OCR never approves authenticity. | SDD-07A |
| Document storage treatment must vary by evidence family rather than treating every document as an OCR image | FS 4.1.2; TS 1.3.1 and 6.1 | Private upload/OCR tables exist, but there is no complete class catalog, restricted medical/SEN boundary, generated-artifact snapshot contract, or physical-custody model | `RECONCILE` | Adopt the normative storage-class matrix: canonical private files where applicable, verified domain data separately, provisional derivatives, immutable generated issuance evidence, controlled imports, and auditable physical custody. Gate/retention status remains independent from storage class. | SDD-07A / SDD-07B / SDD-08A |
| Section/schedule may be prepared before payment, but OR payment secures the slot and official enrollment | FS capacity and placement contract; TS 3.12.1 lock-audited state contract | Readiness and capacity reservation are tested, but the transitional payment path may activate the account without proving final section/delivery placement and still writes `pre_enrolled` | `RECONCILE` | Implement tentative-placement expiry and require compatible final placement inside the handover transaction. Qualifying payment atomically secures every matching capacity plan; no placement routes to institution-owned `PendingInstitutionalPlacement`, not active access or applicant rejection. | SDD-07A |
| Official handover activates credentials, enrollment, COR/class-list eligibility, and placement | FS 2.3/4.1/5.4; TS 1.4/3.3/3.12.1 | `StudentEnrollmentService` and payment paths preserve transaction/idempotency controls but use `pre_enrolled`/`officially_enrolled`, legacy timestamps, and LIS fields | `RECONCILE` | Migrate to one canonical `enrolled` state after every prerequisite passes in the same transaction. Preserve manual/PayMongo parity, locking, rollback, role activation, and audit; do not add a second manual handover step after successful payment. | SDD-07A |
| Walk-in and online intake have equivalent validation and audit evidence | FS 5.5; TS 5.9.2 | Registrar-assisted intake exists only partially | `RETAIN_STRONGER` | Use one requirement/checklist lifecycle with a submission-channel field; do not build a separate weaker data path. | SDD-07A |
| Enrolled roster supports Registrar operations and external encoding | FS 8.1.1; TS roster contract | No completed roster/export surface | `MISSING` | Build a read-only term roster with audited CSV/XLSX export. No regulator-specific completion status or portal automation. | SDD-07A |
| COR issuance, student access, and verification lifecycle | FS 2.3/4.2/8.1; TS 1.4/2.6/3.12.1 | COR readiness and verification revoke/supersede controls exist; no complete issuance/PDF/QR route exists, and lifecycle authorization still uses legacy `manage-lis` | `RECONCILE` | Issue COR only from canonical `enrolled` data through a dedicated service; store issuance snapshot/checksum/token; expose authorized student/staff access and minimal public verification; replace `manage-lis` with dedicated COR permissions. | SDD-07A / SDD-08A |
| DepEd LIS and CHED roster encoding | FS 5.4.5 and TS boundary | Legacy LIS columns exist but later decision excludes internal tracking | `EXTERNAL_BOUNDARY` | Remove LIS-only runtime coupling through a controlled migration; TALA exports generic roster data only. | SDD-07A |

## C. Generated Artifacts and Enrollment Adjustments

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Document-request portal, catalog, fees, fulfillment, pickup, courier, and shipping automation | Manual institutional workflow evidence | Runtime model, table, service, Filament resource, Student Hub page/route, permission names, job, seed fixture, and dedicated tests removed; focused removal test added | `REMOVE` | Keep the institution's manual process outside TALA. Do not defer, hide, or restore dormant compatibility code. | SDD-00D |
| Official generated artifacts, private files, and public verification | FS generated-artifact contracts; TS issuance contract; BM-13/BM-14/BM-15/BM-22/BM-23 | COR verification token lifecycle exists; no complete issuance/PDF/source snapshot/checksum/public verification service | `RECONCILE` | Each authoritative workflow issues its own derived artifact. COR is UAT-core after canonical enrollment; academic, finance, and completion artifacts remain owned by their source workflow. Files are private derived evidence with source snapshot/checksum/template/reference/lifecycle state. QR/public verification uses opaque or signed minimal disclosure. | SDD-07A / SDD-08A / SDD-06E / SDD-07E |
| Drop subject | FS calendar/drop references; no complete typed contract | Generic service request cannot mutate enrollment subjects | `MISSING` | Typed request validates window, adviser/Registrar authority, prerequisite/load effects, grade/attendance state, and atomic subject mutation. | SDD-07C |
| Withdraw enrollment with consultation and policy-driven fee | FS 9.4/6.2.2; TS only mentions assessment | No withdrawal service | `MISSING` | Separate withdrawal from drop-subject. Fee and refund outcome come from effective-dated policies; outstanding balance does not prevent filing. | SDD-07C / SDD-06E |
| Section transfer | FS scheduling/capacity; generic request only | Capacity and conflict services exist; no transfer transaction | `MISSING` | Reuse capacity/conflict checks and atomically move section/delivery assignment with audit evidence. | SDD-07C |
| Program shift | FS 5.2.1; TS 3.12.6 deferred | Legacy migration names exist; no current typed service | `MISSING` | Typed curriculum-credit, prerequisite, capacity, fee-delta, and approval workflow. | SDD-07C |
| Modality change during configured adjustment window | FS modality/scheduling rules; no request contract | Delivery groups exist; no approved mutation service | `MISSING` | Treat modality as delivery setup, validate capacity/schedule/room/fee effects, and apply atomically. External LMS changes are staff follow-up only. | SDD-07C |
| Personal data correction with proof and controlled fields | FS 5.2; TS data dictionary | Generic request only | `MISSING` | Direct-edit low-risk contact fields; typed correction for identity/LRN/birth data with proof, review, old/new values, and audit. | SDD-07D |

## D. Student Status, Graduation, and External Operations

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Registrar-confirmed no-show/inactivity with one-term grace, warnings, and archive review | FS 9.4 currently implies attendance-driven automation | Profile/user status columns exist; staff archive service is staff-only | `RECONCILE` | Do not infer no-show from unavailable attendance. Use an authorized status case with evidence, notices, effective date, and Registrar confirmation. | SDD-07D |
| Leave of Absence, current one-year limit, Accounting clearance, faculty notification | FS/TS gap | No LOA model/service | `MISSING` | Typed LOA request/approval with configurable duration, term effects, access state, balance/document context, and return conditions. | SDD-07D |
| Readmission/return from LOA | FS returnee notes only | Student type supports returnee; no readmission lifecycle | `MISSING` | Review curriculum alignment, balance/holds, capacity, and new-term eligibility before reactivation. | SDD-07D |
| Transfer-out and honorable-dismissal/record release | FS generated-artifact and status boundaries | No transfer-out lifecycle | `MISSING` | Use typed clearance and transfer status. Any credential preparation/release is an authorized Registrar action in the transfer-out workflow, not a document request. External receiving-school handoff remains manual. | SDD-07D |
| Archive/reactivate while preserving debts and records | FS status values; TS account lifecycle is staff-focused | Student profile fields exist; no student transition service | `MISSING` | Add student-specific reversible/terminal transitions. Never reuse staff-account archive service for student academic status. | SDD-07D |
| Graduation application, curriculum audit, deficiency list, clearances, and approval | FS/TS gap | Curriculum, finalized grades, ledger, and documents exist separately | `MISSING` | Build an auditable evaluation snapshot and deficiency lifecycle before diploma/document release. | SDD-07E |
| Diploma preparation, number, and authorized release | FS generated-artifact boundary; workflow requires completion evidence | No diploma lifecycle | `RECONCILE` | Include backend/Admin credential preparation and release evidence in the graduation workflow if required for MVP; do not route it through a document-request portal. Keep printing/template polish separately scoped. | SDD-07E |
| CHED Special Order and government submissions | No complete contract | No integration | `EXTERNAL_BOUNDARY` | Store optional reference/export evidence only. Submission and approval remain external. | SDD-07E |
| Group chats, Google Classroom, printed module pickup/drop-off, physical folders/cabinets | FS correctly treats several as outside MVP | No integrations | `EXTERNAL_BOUNDARY` | TALA owns roster/export/status evidence only unless a later integration is approved. | SDD-07A / SDD-09 |
| Completion/graduation boundary as UAT proof | FS 2.3; TS 1.4; BM-25 | `student_profiles.graduated_at` and `enrollments.completed_at` fields exist, but no graduation application, evaluation snapshot, deficiency list, approval, or credential-release service exists. | `MISSING` | For UAT rescue, show the boundary truthfully as a goal-state requirement unless SDD-07E is promoted. A passable implementation requires a reproducible graduation-evaluation snapshot before setting completed/graduated state. Do not mark students complete through raw field edits. | SDD-07E |

## E. Faculty, Grading, Attendance, and Student Support

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Faculty receives official class list and committed schedule | FS 7.1; TS 3.7 | `FacultyClassListService`, scheduling publish services/tests; current service also exposes a finance-derived badge | `RECONCILE` | Preserve read-only roster and committed/published assignment boundary. Remove finance status/payment-derived restrictions from the goal-state Faculty class list; runtime cleanup belongs to SDD-08A/08B. | SDD-08 |
| Deprecated SHS faculty grading workflow | Historical workflow evidence only | `SHSGradingService` removed; grade UI/services/tests enforce College-only behavior with deprecated-payload guard coverage | `RECONCILE` | Keep active SHS grading services, UI options, seed data, and tests removed. Archived SHS grade evidence must not define active grade behavior. | SDD-00C |
| College grading formula and point scale | Workflow says lecture 60/40 and a different transmutation scale; FS/code use Prelim 30%, Midterm 30%, Final 40% and raw-evidence scale | `CollegeGradingService` hardcodes current FS profile | `RECONCILE` | Introduce versioned grading profiles. Client must approve which current profile applies by program/subject/term before runtime change or grade migration. | SDD-08A |
| Faculty submission, Registrar line-by-line verification, finalization, correction memo, permanent academic record | FS 7.2; TS 3.1 | Encoding/finalization/correction services exist; current per-row Faculty finalization conflates submission with official finalization; no Registrar package verification exists | `RECONCILE` | Use immutable class/subject submission packages: Faculty submits and locks, Registrar returns with reason or verifies/finalizes atomically, and only verified history feeds official outputs. Keep pre-final return distinct from Academic Head-approved post-final correction. | SDD-08A |
| College absence threshold above 20% may produce FA/DRP | FS 2.3 and TS 1.4 keep attendance as benchmark-gated | No attendance model/service | `BENCHMARK_GATE` | Do not automate. Define attendance capture, excused/adjusted hours, faculty review, notice/response, appeal/review, and institution-approved result codes first. Threshold remains configurable pending authoritative validation. | SDD-08B |
| Faculty identifies struggling students and confidentially refers to Guidance | FS/TS restrict guidance as confidential support/case data | Advising service exists; no guidance case source | `BENCHMARK_GATE` | Keep computed advising signals non-punitive. Add confidential referral/intake/closure evidence only after approved guidance ownership, licensed/authorized-role access, privacy/privilege treatment, and redacted summary rules exist. | SDD-08B |
| Behavioral and disciplinary clearance affects future enrollment | FS/TS now require due process and privacy before effects | No behavior/discipline domain | `BENCHMARK_GATE` | Add authorized case outcome/clearance evidence with notice, response, appeal/review, resolution, privacy, and effective-date boundaries before it can block enrollment, completion, or release. | SDD-08B |
| Financial delinquency must not cause exam denial or public disclosure | FS 6.2.1 partly corrected, but FS lifecycle and quick-reference still contain permit blocks; workflow also proposes regular-week attendance/LMS restrictions | Separate exam accommodation exists; faculty privacy service exposes a high-level indicator | `RECONCILE` | Remove exam-permit blocking and do not implement attendance/LMS restrictions based on debt. Keep private Accounting collection, next-cycle enrollment holds, and lawful record-release holds. | SDD-06C-R / SDD-08B |
| Student Hub read-only visibility and PWA cache boundary | FS 2.3/4.3; TS 1.4/5.6.2/5.8; BM-12/BM-24 | `/student/*` routes, `EnsureActiveStudentHubUser`, Student layout PWA directives, published Help/FAQ, `StudentDashboardService`, and access/dashboard tests exist. Dashboard, Schedule, Grades, and Financials remain partial/static; `public/sw.js` is generic offline fallback only. | `RECONCILE` | Retain the active-student, owner-scoped service contract as the authority. Connect approved Student Hub pages to `StudentDashboardService`, remove sample values and the document-request route/page, and do not cache protected finance/grade/COR data until freshness labels, cache versioning, logout/account-denial clearing, and offline mutation denial are tested. | SDD-00D / SDD-08B / TAL-13 |

## F. Accounting, Promissory Notes, Refunds, and Reconciliation

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Collector, Recorder, and Verifier duties are segregated | FS has one Accounting/Cashier role; TS permissions are action-based | Permissions exist but accounts are not segregated by duty | `RETAIN_STRONGER` | Enforce separation through permissions and maker-checker actions. Separate role names are optional; the same actor must not collect/record/verify the same transaction when the policy requires segregation. | SDD-06E |
| Online/manual payment proof, reference verification, onsite collection, and immutable receipt/payment evidence | FS 6.2; TS 3.14.1 | Payment services, PayMongo webhook, immutable ledger, and tests | `ALIGNED` | Preserve typed channels, unique references, idempotency, and provider verification. Add receipt-number/evidence policy where official receipts are recorded. | SDD-06E |
| Assessment, scholarships/discounts, downpayment, and Registrar handoff | FS 6.1-6.2; TS 3.12 | Fixed-column fee templates and assessment/clearance services exist; no effective dates, component lines, assessment snapshot, or policy-driven freshman discount; assessment currently writes `enrolled_at` | `RECONCILE` | Preserve atomic decimal ledger posting. Add versioned effective-dated fee structures and immutable per-enrollment assessment snapshots; move freshman discount to policy; reserve `enrolled_at` for official handover. | SDD-06E / SDD-07A |
| Daily three-way reconciliation: ledger = receipts = cash/deposits | FS/TS mention reconciliation mainly for PayMongo | No daily collection/variance/approval workflow | `MISSING` | Add daily batch/shift close, expected versus actual totals, variance reason, verifier approval, and immutable evidence. | SDD-06E |
| Zero-balance clearance, SOA, and Official Receipt issuance | FS 6; TS ledger/dashboard/artifact contract | Balance/payment/ledger records exist; no issued SOA/OR record, template snapshot, checksum, void/supersede lifecycle, or private PDF | `RETAIN_STRONGER` | Compute clearance from authoritative ledger; treat cached balance as rebuildable. Issue versioned private SOA/OR artifacts with immutable source snapshots and lifecycle history. | SDD-06E |
| One approved promissory note per academic year with Registrar and Accounting participation | FS/code use one open per enrollment and Accounting-only approval | `PromissoryNoteLifecycleService` and tests | `RECONCILE` | Preserve typed lifecycle, settlement, expiry, and non-payment behavior. Reconcile cap scope and define Registrar endorsement versus financial approval; avoid duplicate approvals without purpose. | SDD-06C-R |
| Exam access under RA 11984 and institution policy | FS 6.2.1; TS exam-access service | `ExamAccessDecisionService`/accommodation tests | `RECONCILE` | RA 11984 covers qualifying disadvantaged students and allows institutions to require a PN while retaining collection/record remedies. The client's stronger policy allows all enrolled students to take exams. Implement no debt-based exam block; retain accommodation evidence only when useful for compliance/audit. | SDD-06C-R |
| Monthly reminders and private balance communication | FS notifications; TS unified notification | Notification infrastructure exists; no full due-list process | `MISSING` | Queue private reminders from ledger/installment policy; never publish delinquent lists to classes or faculty. | SDD-06E |
| Admission/enrollment fee refundable within 15 days; tuition non-refundable after official enrollment | FS/TS financial disposition was rebaselined but has no refund execution | Adjustments exist; no refund lifecycle/provider/cash reconciliation | `MISSING` | Implement effective-dated component-level disposition, authorized request/review, immutable refund ledger entries, channel-specific execution, and reconciliation. | SDD-06F |

## G. Academic Head and Cross-Module Governance

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Academic Head approves curriculum readiness, faculty workload exceptions, and schedule publication | FS 5; TS scheduling contracts | Readiness, workload override, publish services/tests exist | `ALIGNED` | Preserve current approval/audit boundaries. | SDD-01 to SDD-03 |
| Academic Head reviews grade disputes, probation, interventions, and graduation candidates | FS grade correction partly covers this; other workflows missing | Grade correction exists; no probation/intervention/graduation workflow | `MISSING` | Add approval only where it changes an official academic outcome; operational meetings and notes need not become modules. | SDD-08A / SDD-08B / SDD-07E |
| Enrollment projections, section planning, fee-matrix coordination | FS/TS cover separate modules | Curriculum, scheduling, capacity, and finance are not joined by an admission plan | `MISSING` | Use scoped admission-capacity plans and readiness checks instead of a single global setting. | SDD-07A |
| Accreditation, NAT/cohort metrics, annual reports, and board summaries | Dashboard/exports are partial | No complete reporting suite | `EXTERNAL_BOUNDARY` | Provide reliable source data and generic exports for MVP. Do not automate regulator/accreditor submission without a separate approved slice. | SDD-09 |

## H. Legal, Privacy, and Audit Controls

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Institutional data privacy, security, and consent management | FS/TS only partially define privacy consent and retention | No comprehensive privacy-by-design, data retention, or NPC 2023-06 compliance | `MISSING` | Implement explicit privacy notices, consent tracking, data retention/deletion rules, and role-based access logs compliant with RA 10173 and NPC Circular 2023-06. | SDD-04 |

## Benchmark Findings Applied

1. SHS/Grades 11-12 DepEd grading and promotion benchmarks are retained only as archived historical evidence after the College-only scope correction. They must not define active TALA implementation unless SHS scope is formally restored.
2. [DepEd Order No. 17, s. 2025](https://www.deped.gov.ph/2025/06/13/june-13-2025-do-017-s-2025-revised-basic-education-enrollment-policy/) remains relevant only where Grade 12/Form 138/Form 137 records are used as prior-education evidence for College admission.
3. [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html) applies to public/private basic, higher, and qualifying technical-vocational institutions. It mandates exam accommodation for qualifying disadvantaged students, permits a promissory-note requirement and lawful record-withholding remedies, and does not support debt-based public disclosure or exam denial.
4. Current College retention, grading-profile, and attendance-outcome values remain institution policy decisions. They must be configurable and client-approved before runtime changes because no verified universal CHED rule was found in this audit that establishes the workflow's exact GWA, grading scale, or automatic FA/DRP behavior.
5. [Oracle PeopleSoft checklist setup](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lscc/concept_UnderstandingChecklistSetup-ab6ca5.html) models configurable checklist items with status, responsible actor, and due date. [Frappe Student Admission](https://docs.frappe.io/education/student_admission) and [Student Applicant](https://docs.frappe.io/education/student-applicant) separate the published admission offering, applicant decision, student creation, and program enrollment. This supports one shared TALA admission lifecycle with composable policy dimensions instead of separate category pipelines or a flat mutually exclusive applicant type.
6. ALS evidence for College admission requires an institution-approved College pathway and supporting credential rule. No active Grade 11 pathway remains in the TALA deployment.
7. [Bureau of Immigration Student Visa 9(f)](https://immigration.gov.ph/student-visa-9f/) applies to foreign nationals at least 18 taking higher-than-high-school study, while Philippine consular guidance separates visa and permit evidence handled through external immigration/consular processes. This supports an inactive-by-default foreign compliance profile with restricted evidence tracking, not an MVP immigration integration.
8. [RA 11650](https://lawphil.net/statutes/repacts/ra2022/ra_11650_2022.html) frames learners with disabilities through inclusion and support services, [RA 8371/IPRA](https://faolex.fao.org/docs/pdf/phi13930.pdf) recognizes Indigenous rights and culturally responsive services, and [RA 10173/Data Privacy Act](https://privacy.gov.ph/data-privacy-act/) requires sensitive evidence safeguards. This supports IP/SEN as optional restricted support attributes, not admission-denial routes.
9. Oracle Campus Solutions separates terms/sessions, enrollment dates, validation/planning appointments, class readiness, and actual class enrollment. This supports TALA's readiness-gated payment/handover and non-protected tentative placement design.
10. Generated official documents are derived artifacts, not primary operational records. COR, SOA, receipts, academic records, and completion credentials must be generated from authoritative enrollment, ledger, grade, student-record, transfer, and completion data with template version, issuer, checksum, serial/reference where applicable, and revoke/supersede behavior. QR verification must resolve through opaque/signed routes or tokens rather than embedding private student data.
11. Registrar enrollment-verification/transcript benchmarks distinguish enrollment certifications from permanent academic records and place release authority with the Registrar/student-record office. DepEd school-record guidance treats Form 137/SF10 and Form 138/SF9 as confidential learner records. TALA may record authorized release evidence inside a transfer, admission, or academic-record workflow, but it does not provide a document-request portal or become DepEd LIS or a receiving-school portal.
12. Livewire page/loading/offline directives and MDN service-worker/cache guidance support the current Student Hub architecture but also make the cache boundary explicit: cached protected data is never automatic proof of current validity, must be intentionally versioned/purged, and needs freshness/offline indicators plus mutation denial before it becomes passable PWA evidence.
13. Mature SIS status/completion benchmarks treat program status, readmission, withdrawal, completion, and graduation as reasoned lifecycle events backed by academic requirements and audit evidence. TALA should therefore add typed transition and graduation-evaluation services instead of relying on profile status fields or generic service requests as final authority.
14. Laravel Excel 3.1 supports collection/model imports, chunk/queued imports, and downloadable exports, but TALA must wrap package primitives in domain-specific import/export services. Current runtime only satisfies the curriculum import contract; generic enrolled-roster/report exports and other legacy imports remain separate SDD work, while DepEd/CHED/LIS portal submission remains external.
15. DepEd child-protection guidance, CHED private-higher-education discipline rules, and school-record privacy references make behavior/discipline enforcement a due-process and evidence problem, not a free-text flag. TALA must not impose attendance, discipline, or behavior effects until typed evidence, notice/response, appeal/review, resolution, and authorized effect rules exist.
16. RA 9258 treats guidance and counseling as a regulated profession with privilege/confidentiality protections, and NPC Advisory Opinion No. 2025-017 treats identifiable learner school records as personal/sensitive information requiring confidentiality and safeguards. Guidance case details therefore require restricted access and redacted clearance summaries, not ordinary staff-table visibility.

## Dependency-Ordered Execution

1. **SDD-00D:** permanent scope removal, FS/TS-to-code evidence inventory, dependency ranking, and measurable completion dashboard are recorded locally; Linear mirror remains active.
2. **SDD-03R CP-SAT Scheduling Closure:** selected P0 integration micro-sprint. Normalize solver/domain outcome semantics (`model_invalid`, `partial`, timeout) and allow approved post-publication Apply while direct create/edit remains blocked.
3. **SDD-07A enrollment handover continuation:** canonical `enrolled` state, placement-aware handover, generic roster export, COR issuance/PDF/QR permission cleanup, and the minimum admission gate/retention evidence needed for handover.
4. **SDD-06E/06F finance policy closure:** maker-checker reconciliation, receipt evidence, private reminders, and refund/disposition execution before withdrawal/cancellation side effects.
5. **SDD-05C-R and SDD-08A policy closure:** College progression plus versioned College grading profiles. Do not change historical or current grades without an approved profile and migration rule.
6. **SDD-08B/TAL-13 Student Hub read-only connection:** connect Dashboard, Schedule, Grades, and Financials to service-backed read models after backend contracts are stable; keep advanced PWA/offline work deferred.
7. **SDD-07C:** drop/withdrawal, section transfer, program shift, and modality change using settled calendar, capacity, curriculum, and finance policies.
8. **SDD-07D:** personal correction, LOA, readmission, transfer-out, inactivity, archive, and reactivation.
9. **SDD-07E:** graduation audit, deficiencies, clearances, diploma/release evidence, and external export.
10. **SDD-09:** cross-module Admin QA, reporting/export checks, and Pre-UAT gate.

## Open Decisions Requiring Grill

Ask one question at a time only after code, documents, and benchmarks cannot answer it:

1. Which current College grading profile is authoritative: the updated workflow profile, the raw evaluation-form profile, or separate profiles by program/subject?
2. Does Registrar provide a non-financial endorsement on promissory notes, or is Accounting approval alone the intended control?
3. Which behavior/discipline outcomes may block the next enrollment, and what appeal/clearance evidence resolves them?
4. Is diploma preparation/release required for MVP Admin UAT, or is graduation evaluation plus eligibility/export sufficient?

## Audit Boundary

This audit changes documentation and execution ordering only. It does not claim the missing or conflicting runtime behavior is implemented. Existing completed tests remain evidence for compatible behavior, while reopened rows require new focused PHPUnit coverage when their implementation slices begin.
