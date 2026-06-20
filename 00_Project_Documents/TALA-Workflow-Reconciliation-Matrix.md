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

## Classification Legend

| Classification | Meaning |
| --- | --- |
| `ALIGNED` | FS/TS and implementation satisfy the approved outcome. |
| `RETAIN_STRONGER` | Existing design is compatible and provides better control or adaptability. |
| `RECONCILE` | Sources or runtime behavior conflict; change only the incompatible portion. |
| `MISSING` | Required workflow has no complete FS/TS and/or typed implementation. |
| `EXTERNAL_BOUNDARY` | Activity remains in another system or manual operation; TALA may export or retain evidence only. |
| `BENCHMARK_GATE` | Implementation must wait for an authoritative rule or explicit client policy decision. |

## A. Shared Policy, Calendar, and Progression

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Separate SHS and College calendars, enrollment windows, adjustment windows, exams, and term close | FS 5.1.1, 10; TS 3.18 | `AcademicYear`, `Term`, `CalendarPhaseGateService`; calendar tests | `RETAIN_STRONGER` | Keep education-level-aware terms and phase gates. Academic Head approval and minimum-day evidence remain a later admin contract. | SDD-04 / SDD-09 |
| Current institution has a 100-active-student ceiling; inquiry/slip does not secure a seat; OR payment does | FS 4.1, 5.3.4; TS 3.4 | Section/group capacity exists; no campus admission-capacity plan | `RECONCILE` | Add stacked effective-dated capacity plans by term with optional campus, SHS/College, program/strand, year/grade, and delivery scopes. If only the campus cap exists, SHS and College share the OR-secured pool; if sub-caps exist, enforce every matching plan. Do not hardcode 100 platform-wide. | SDD-07A |
| Five continuing-enrollment gates: finance, admission/retention documents, behavior, discipline, and academic standing | Partially described across FS 4.2, 5.2, 6; no unified TS gate contract | Finance, document, and grade evidence are partial; no behavior/discipline source | `MISSING` | Create a typed clearance-evaluation result with independent reasons. Do not enforce behavior or discipline until their evidence sources and authorized resolutions exist. | SDD-07D / SDD-08B |
| SHS promotion/remediation | FS 4.2 and grade appendix are narrower; workflow says general average below 75 repeats whole grade | `SubjectSuggestionService` checks subject prerequisites only | `BENCHMARK_GATE` | DepEd Order No. 8, s. 2015 requires Grades 11-12 learners to remediate failed competencies/subjects and retake failed subjects when remediation fails; it does not support a blanket whole-grade repeat based only on general average. Model regulator/versioned progression policy, not the workflow sentence literally. | SDD-05C-R |
| College probation, repeat-year, irregular, and subject-retake outcomes | FS has prerequisite and irregular-load rules but no approved retention profile | Subject suggestions and finalized grades exist; no GWA probation service | `BENCHMARK_GATE` | Treat thresholds and repeat-year effects as institution-approved, effective-dated retention policy. Do not infer a universal CHED threshold. | SDD-05C-R |
| Summer/remedial offering is discretionary; 6-9 unit current cap; different SHS/College pricing | FS 5.3.6 and 4.2 propose summer loads, currently with a generic 30-unit regular cap | Summer term type exists; no complete offering/assessment workflow | `RECONCILE` | Keep summer as a separate term/offering with configurable load and fee policy. The current 6-9-unit value is deployment configuration. | SDD-05C-R / SDD-06E |
| Government minimum-day/compliance reports | FS/TS calendar and export boundaries | Calendar data exists; no regulator submission integration | `EXTERNAL_BOUNDARY` | Store calendar evidence and provide generic exports. DepEd/CHED/accreditation portal submission remains external. | SDD-09 |

## B. Admission, Enrollment, Documents, and Placement

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Admission requirements vary across education level, entry route, prior credential, citizenship/compliance, program/grade, and support attributes; these dimensions can overlap | FS 4.1.2; TS 1.3.1 and 3.12.1 | `ApplicantIntake.required_documents` snapshots a hardcoded service list and has no composable offering/policy resolver | `RECONCILE` | Use one generic admission lifecycle. Publish term-scoped offerings; compose deterministic versioned requirement rules; snapshot source versions per intake; keep unsupported offerings inactive. Treat IP and disability/SEN as purpose-limited support attributes, not mutually exclusive applicant types, admission gates, or denial grounds. Collect restricted evidence only for configured accommodations/support purposes. | SDD-07A |
| Initial intake exposure and returning/cross-enrollee boundaries | FS 4.1.2; TS 1.3.1 and 3.12.1 | Public intake exists; returnee detection is partial; no legacy onboarding/readmission service; no cross-enrollee lifecycle | `RECONCILE` | Publish Regular SHS, SHS Transfer, Regular College Freshman, and College Transfer. Route returning students through Registrar-assisted lookup/readmission, adding provenance-tagged legacy onboarding when no reliable pre-TALA record exists. Keep cross-enrollee inactive until institution acceptance is confirmed. Treat Old Curriculum College as a prior-credential pathway; keep the unsupported Old Curriculum SHS row as inactive trace evidence only because the clarified learner scenario is College admission, not SHS intake. Treat ALS as `als_jhs` under Regular SHS Grade 11 only for the current deployment. Keep foreign compliance inactive until institution acceptance and legal stay/study authorization evidence rules are approved for a scoped offering. | SDD-07A / SDD-07D |
| Admission-gate documents are required before official enrollment; non-critical retention documents may follow | FS Step 5 is updated, but FS Step 7/payment sections and TS state machine still contain stale all-physical gating | `ApplicantIntakeService`, `EnrollmentHardCopyReceiptService`, and finance clearance use coarse completeness behavior | `RECONCILE` | Materialize per-item admission-gate versus retention classification and accepted evidence method. Remove the profile-wide hard-copy flag as authority. | SDD-07A |
| Retention undertaking uses itemized missing documents, 30-60-day due date, monitoring, reminders, and documentary hold | FS Step 5; TS has no implemented undertaking model | No undertaking/deadline/reminder service | `MISSING` | Add configurable due date within approved policy, extensions, reminders, receipt history, resolution, and next-cycle/document-release hold effects. No silent cancellation. | SDD-07A |
| Applicant uploads are preliminary; Registrar verifies authenticity and physical/certified evidence as configured | FS 4.1.2; TS 1.3.1 and 6.1 | Private uploads, OCR, manual review, replacement history partly exist | `RETAIN_STRONGER` | Preserve canonical private file plus extracted text and human verification. OCR never approves authenticity. | SDD-07A |
| Document storage treatment must vary by evidence family rather than treating every document as an OCR image | FS 4.1.2; TS 1.3.1 and 6.1 | Private upload/OCR tables exist, but there is no complete class catalog, restricted medical/SEN boundary, generated-artifact snapshot contract, or physical-custody model | `RECONCILE` | Adopt the normative storage-class matrix: canonical private files where applicable, verified domain data separately, provisional derivatives, immutable generated issuance evidence, controlled imports, and auditable physical custody. Gate/retention status remains independent from storage class. | SDD-07A / SDD-07B / SDD-08A |
| Section/schedule may be prepared before payment, but OR payment secures the slot and official enrollment | FS capacity rebaseline notes conflict with stale Step 7; TS 3.12.1 still assumes sectionless pending state | Current finance clearance can hand over and section immediately | `RECONCILE` | Add readiness-gated payment/handover. Tentative placement expires at payment deadline, admission-window close, or Registrar cancellation and grants no protected access. OR-backed payment atomically secures every matching capacity plan. Final placement/access must route institution-caused placement failure to Registrar resolution, not applicant rejection. | SDD-07A |
| Official handover activates credentials, enrollment, COR/class-list eligibility, and placement | FS 4.1/5.4; TS 3.3/3.12.1 | `StudentEnrollmentService` and payment paths implement an older atomic handover | `RECONCILE` | Define one canonical `enrolled` state and explicit prerequisite gates. Keep atomicity and channel parity; change trigger order only. | SDD-07A |
| Walk-in and online intake have equivalent validation and audit evidence | FS 5.5; TS 5.9.2 | Registrar-assisted intake exists only partially | `RETAIN_STRONGER` | Use one requirement/checklist lifecycle with a submission-channel field; do not build a separate weaker data path. | SDD-07A |
| Enrolled roster supports Registrar operations and external encoding | FS 8.1.1; TS roster contract | No completed roster/export surface | `MISSING` | Build a read-only term roster with audited CSV/XLSX export. No regulator-specific completion status or portal automation. | SDD-07A |
| DepEd LIS and CHED roster encoding | FS 5.4.5 and TS boundary | Legacy LIS columns exist but later decision excludes internal tracking | `EXTERNAL_BOUNDARY` | Remove LIS-only runtime coupling through a controlled migration; TALA exports generic roster data only. | SDD-07A |

## C. Document Requests and Enrollment Adjustments

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Registrar-managed document catalog; Accounting-owned fee policy; types can evolve | FS 9.1 currently says fixed approved catalog while also describing management; TS 3.14.2 notes fixed runtime list | `DocumentRequest::documentTypeOptions()` is hardcoded | `RECONCILE` | Use a versioned catalog with Registrar metadata/availability and Accounting fee activation. Existing request rows retain a type/price snapshot. | SDD-07B |
| Current document types, prices, free-request conditions, and processing notes | FS/TS partially define types/free rules; workflow supplies current values | Lifecycle exists; no catalog or fee snapshot entity | `RECONCILE` | Seed current values as editable effective-dated configuration after client validation; do not hardcode prices in service logic. | SDD-07B |
| Admission/documentary and financial holds block ineligible official-document requests | FS 9.1; TS 3.14.2 | Document lifecycle checks are partial | `MISSING` | Centralize request eligibility and return explicit hold reasons. Exam access is never part of this hold. | SDD-07B |
| Registrar queue has a current 30/day planning value, estimated completion, extension reason/date, ready and unclaimed reminders | FS 9.3 prefers dynamic SLA; workflow uses 30/day estimate | No complete queue-capacity/SLA service | `RETAIN_STRONGER` | Keep dynamic ETA with configurable daily capacity. Require audited extension and notification; 30 is the current profile, not a hard rejection limit. | SDD-07B |
| Pickup identity/representative authorization and release acknowledgement | FS/TS do not fully define release evidence | Pickup completion transition exists without full claimant evidence | `MISSING` | Record claimant, representative authority when applicable, release time, releasing staff, and acknowledgement. | SDD-07B |
| Manual courier, consent, actual shipping fee, receipt, tracking, and debt grace | FS 9.1-9.2; TS 3.14.2/3.15.2 | `DocumentRequestLifecycleService`, private proof, fee enforcer, and tests | `ALIGNED` | Preserve private evidence and two-payment model. LBC is an external/manual courier, not a required API integration. | SDD-07B |
| Drop subject | FS calendar/drop references; no complete typed contract | Generic service request cannot mutate enrollment subjects | `MISSING` | Typed request validates window, adviser/Registrar authority, prerequisite/load effects, grade/attendance state, and atomic subject mutation. | SDD-07C |
| Withdraw enrollment with consultation and current PHP 3,500 fee | FS 9.4/6.2.2; TS only mentions assessment | No withdrawal service | `MISSING` | Separate withdrawal from drop-subject. Fee and refund outcome come from effective-dated policies; outstanding balance does not prevent filing. | SDD-07C / SDD-06E |
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
| Transfer-out and honorable-dismissal/record release | FS document types only | No transfer-out lifecycle | `MISSING` | Typed clearance and transfer status; credential release follows request/hold rules. External receiving-school handoff remains manual. | SDD-07D |
| Archive/reactivate while preserving debts and records | FS status values; TS account lifecycle is staff-focused | Student profile fields exist; no student transition service | `MISSING` | Add student-specific reversible/terminal transitions. Never reuse staff-account archive service for student academic status. | SDD-07D |
| Graduation application, curriculum audit, deficiency list, clearances, and approval | FS/TS gap | Curriculum, finalized grades, ledger, and documents exist separately | `MISSING` | Build an auditable evaluation snapshot and deficiency lifecycle before diploma/document release. | SDD-07E |
| Diploma preparation, number, claiming, and release holds | FS currently says diploma issuance out of scope while workflow requires it | No diploma lifecycle | `RECONCILE` | Include backend/Admin credential preparation and release evidence if required for MVP; keep printing/template polish separately scoped. | SDD-07E |
| CHED Special Order and government submissions | No complete contract | No integration | `EXTERNAL_BOUNDARY` | Store optional reference/export evidence only. Submission and approval remain external. | SDD-07E |
| Group chats, Google Classroom, printed module pickup/drop-off, physical folders/cabinets | FS correctly treats several as outside MVP | No integrations | `EXTERNAL_BOUNDARY` | TALA owns roster/export/status evidence only unless a later integration is approved. | SDD-07A / SDD-09 |

## E. Faculty, Grading, Attendance, and Student Support

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Faculty receives official class list and committed schedule | FS 7.1; TS 3.7 | `FacultyClassListService`, scheduling publish services/tests | `ALIGNED` | Preserve read-only roster and committed/published schedule boundary. | SDD-08 |
| SHS faculty enters transmuted period grades; TALA need not capture every component score | FS 7.2 and Appendix A; TS 3.1.1 | `SHSGradingService` averages Q1/Q2 | `RETAIN_STRONGER` | Keep component computation offline for MVP, but store the grading-profile identifier used for audit/import. | SDD-08A |
| SHS component weights in the workflow | Workflow table conflicts with DepEd Order No. 8, s. 2015 and FS Appendix A | Runtime does not compute components | `BENCHMARK_GATE` | DepEd Table 5 governs the default profile: Core 25/50/25; Academic all-other 25/45/30; Academic work-immersion/research/etc. 35/40/25; TVL/Sports/Arts all-other 20/60/20. Do not adopt the workflow's 35/45/20 blanket applied/specialized row. | SDD-08A |
| College grading formula and point scale | Workflow says lecture 60/40 and a different transmutation scale; FS/code use Prelim 30%, Midterm 30%, Final 40% and raw-evidence scale | `CollegeGradingService` hardcodes current FS profile | `RECONCILE` | Introduce versioned grading profiles. Client must approve which current profile applies by program/subject/term before runtime change or grade migration. | SDD-08A |
| Faculty submission, Registrar line-by-line verification, finalization, correction memo, permanent academic record | FS 7.2; TS 3.1 | Encoding/finalization/correction services exist; Registrar verification stage and submission package are incomplete | `RECONCILE` | Preserve immutable finalization and Academic Head-controlled official change. Add Registrar verification/return stage and evidence references without requiring duplicate paper data entry. | SDD-08A |
| College absence threshold above 20% may produce FA/DRP | FS/TS have no attendance source or approved outcome profile | No attendance model/service | `BENCHMARK_GATE` | Do not automate. Define attendance capture, excused/adjusted hours, faculty review, notice/appeal, and institution-approved result codes first. Threshold remains configurable pending authoritative validation. | SDD-08B |
| Faculty identifies struggling students and confidentially refers to Guidance | FS advising status is read-only; no case workflow | Advising service exists; no guidance case source | `MISSING` | Keep computed advising signals non-punitive. Add minimal confidential referral/closure evidence only if included in MVP. | SDD-08B |
| Behavioral and disciplinary clearance affects future enrollment | FS/TS lack source and due process | No behavior/discipline domain | `MISSING` | Add authorized case outcome/clearance evidence with privacy, appeal, and resolution boundaries before it can block enrollment. | SDD-08B |
| Financial delinquency must not cause exam denial or public disclosure | FS 6.2.1 partly corrected, but FS lifecycle and quick-reference still contain permit blocks; workflow also proposes regular-week attendance/LMS restrictions | Separate exam accommodation exists; faculty privacy service exposes a high-level indicator | `RECONCILE` | Remove exam-permit blocking and do not implement attendance/LMS restrictions based on debt. Keep private Accounting collection, next-cycle enrollment holds, and lawful record-release holds. | SDD-06C-R / SDD-08B |

## F. Accounting, Promissory Notes, Refunds, and Reconciliation

| Workflow requirement | FS / TS | Code / tests | Classification | Decision or required action | SDD owner |
| --- | --- | --- | --- | --- | --- |
| Collector, Recorder, and Verifier duties are segregated | FS has one Accounting/Cashier role; TS permissions are action-based | Permissions exist but accounts are not segregated by duty | `RETAIN_STRONGER` | Enforce separation through permissions and maker-checker actions. Separate role names are optional; the same actor must not collect/record/verify the same transaction when the policy requires segregation. | SDD-06E |
| Online/manual payment proof, reference verification, onsite collection, and immutable receipt/payment evidence | FS 6.2; TS 3.14.1 | Payment services, PayMongo webhook, immutable ledger, and tests | `ALIGNED` | Preserve typed channels, unique references, idempotency, and provider verification. Add receipt-number/evidence policy where official receipts are recorded. | SDD-06E |
| Assessment, scholarships/discounts, downpayment, and Registrar handoff | FS 6.1-6.2; TS 3.12 | Assessment and finance-clearance services/tests | `RECONCILE` | Keep scoped fee templates and atomic ledger. Rewire only the enrollment/seat transition after SDD-07A settles it. | SDD-06E / SDD-07A |
| Daily three-way reconciliation: ledger = receipts = cash/deposits | FS/TS mention reconciliation mainly for PayMongo | No daily collection/variance/approval workflow | `MISSING` | Add daily batch/shift close, expected versus actual totals, variance reason, verifier approval, and immutable evidence. | SDD-06E |
| Zero-balance clearance and SOA | FS 6; TS ledger/dashboard | Balance and ledger exist; signed/issued clearance workflow is incomplete | `RETAIN_STRONGER` | Compute clearance from ledger; generate an auditable clearance/SOA projection rather than a mutable flag. | SDD-06E |
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

## Benchmark Findings Applied

1. [DepEd Order No. 8, s. 2015](https://www.deped.gov.ph/wp-content/uploads/2015/04/DO_s2015_08.pdf) controls the default SHS component weights and Grades 11-12 promotion/remediation rules described above. The conflicting workflow summaries must not be implemented literally.
2. [DepEd Order No. 17, s. 2025](https://www.deped.gov.ph/2025/06/13/june-13-2025-do-017-s-2025-revised-basic-education-enrollment-policy/) supports a regulator-aware temporary-enrollment/document-transfer design rather than one universal all-physical deadline.
3. [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html) applies to public/private basic, higher, and qualifying technical-vocational institutions. It mandates exam accommodation for qualifying disadvantaged students, permits a promissory-note requirement and lawful record-withholding remedies, and does not support debt-based public disclosure or exam denial.
4. Current College retention, grading-profile, and attendance-outcome values remain institution policy decisions. They must be configurable and client-approved before runtime changes because no verified universal CHED rule was found in this audit that establishes the workflow's exact GWA, grading scale, or automatic FA/DRP behavior.
5. [Oracle PeopleSoft checklist setup](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lscc/concept_UnderstandingChecklistSetup-ab6ca5.html) models configurable checklist items with status, responsible actor, and due date. [Frappe Student Admission](https://docs.frappe.io/education/student_admission) and [Student Applicant](https://docs.frappe.io/education/student-applicant) separate the published admission offering, applicant decision, student creation, and program enrollment. This supports one shared TALA admission lifecycle with composable policy dimensions instead of separate category pipelines or a flat mutually exclusive applicant type.
6. [DepEd LIS ALS Portfolio Assessment guidance](https://support.lis.deped.gov.ph/support/Manuals/ALS-Portfolio-Assessment-Tutorial-Guide.pdf) treats ALS portfolio assessment as Grade 7/Grade 11 eligibility evidence and specifies Grade 11 enrollment through Junior High School-level portfolio-assessment passer evidence. This supports an ALS Junior High School-to-Grade 11 pathway, not a generic ALS College or transfer pathway.
7. [Bureau of Immigration Student Visa 9(f)](https://immigration.gov.ph/student-visa-9f/) applies to foreign nationals at least 18 taking higher-than-high-school study, while Philippine consular guidance separates visa and permit evidence handled through external immigration/consular processes. This supports an inactive-by-default foreign compliance profile with restricted evidence tracking, not an MVP immigration integration.
8. [RA 11650](https://lawphil.net/statutes/repacts/ra2022/ra_11650_2022.html) frames learners with disabilities through inclusion and support services, [RA 8371/IPRA](https://faolex.fao.org/docs/pdf/phi13930.pdf) recognizes Indigenous rights and culturally responsive services, and [RA 10173/Data Privacy Act](https://privacy.gov.ph/data-privacy-act/) requires sensitive evidence safeguards. This supports IP/SEN as optional restricted support attributes, not admission-denial routes.
9. Oracle Campus Solutions separates terms/sessions, enrollment dates, validation/planning appointments, class readiness, and actual class enrollment. This supports TALA's readiness-gated payment/handover and non-protected tentative placement design.

## Dependency-Ordered Execution

1. **SDD-07A contract closure:** admission/retention requirement model, capacity/placement/payment sequence, canonical enrollment state, and roster/export boundary.
2. **SDD-06E/06F finance policy closure:** maker-checker reconciliation, receipt evidence, private reminders, and refund/disposition execution. This must be defined before withdrawal and cancellation side effects.
3. **SDD-05C-R and SDD-08A policy closure:** regulator-aware progression plus versioned SHS/College grading profiles. Do not change historical or current grades without an approved profile and migration rule.
4. **SDD-07B:** catalog, eligibility, SLA/queue, release, and courier completion.
5. **SDD-07C:** drop/withdrawal, section transfer, program shift, and modality change using the settled calendar, capacity, curriculum, and finance policies.
6. **SDD-07D:** personal correction, LOA, readmission, transfer-out, inactivity, archive, and reactivation.
7. **SDD-08B:** attendance, guidance, behavior, and discipline evidence required by clearance/progression gates.
8. **SDD-07E:** graduation audit, deficiencies, clearances, diploma/release evidence, and external export.
9. **SDD-09:** cross-module Admin QA, reporting/export checks, and Pre-UAT gate.

## Open Decisions Requiring Grill

Ask one question at a time only after code, documents, and benchmarks cannot answer it:

1. Which current College grading profile is authoritative: the updated workflow profile, the raw evaluation-form profile, or separate profiles by program/subject?
2. Does Registrar provide a non-financial endorsement on promissory notes, or is Accounting approval alone the intended control?
3. Which behavior/discipline outcomes may block the next enrollment, and what appeal/clearance evidence resolves them?
4. Is diploma preparation/release required for MVP Admin UAT, or is graduation evaluation plus eligibility/export sufficient?

## Audit Boundary

This audit changes documentation and execution ordering only. It does not claim the missing or conflicting runtime behavior is implemented. Existing completed tests remain evidence for compatible behavior, while reopened rows require new focused PHPUnit coverage when their implementation slices begin.
