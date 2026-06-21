# TALA SIS Benchmark Baseline Matrix

**Created:** 2026-06-21  
**Purpose:** Ground the TALA Functional Specification and Technical Specification in mature Student Information System patterns, verified service/platform references, and SIA business evidence before implementation priority is decided through SDD.

---

## How To Use This Matrix

This document is a bridge, not a replacement for FS/TS.

- **FS/TS use this matrix** to stay complete, actionable, and benchmark-grounded as the final-form system.
- **SDD/checklist use this matrix** to decide implementation order: UAT Core, Core-lite, Supporting, Phase 2, or External Boundary.
- **UAT uses this matrix** to explain why the current build tests only the working SIS lifecycle first, while the complete specification remains larger.

## Actionability Rule

Every benchmarked feature must eventually have:

1. Functional behavior: roles, workflow, states, happy path, negative path, edge case, and access boundary.
2. Technical contract: source tables/models/services, policy boundary, UI surface, integrations, jobs, files, and tests.
3. Acceptance proof: goal-state test cases and a separate testable-now status when implementation exists.
4. Priority digest: UAT Core, Core-lite, Supporting, Phase 2, or External Boundary in SDD/checklist.

---

## Benchmark Source Register

| ID | Benchmark anchor | What it contributes to TALA |
| --- | --- | --- |
| BM-01 | Oracle PeopleSoft Campus Solutions Overview: Campus Community, Recruiting and Admissions, Student Records, Academic Advisement, Student Financials, and Campus Self Service. | Mature SIS module map: person data, checklists/comments/communications, admissions, matriculation, enrollment, grades, advisement, graduation, financials, and self-service. |
| BM-02 | Ellucian SIS overview: centralized student lifecycle across admissions, enrollment, scheduling, grades/records, performance tracking, and financial management. | High-level final-form SIS lifecycle spine. |
| BM-03 | Frappe Education Student Admission. | Published admission process by academic year/program, route, start/end dates, eligibility, and applicant-facing portal. |
| BM-04 | Frappe Education Program Enrollment. | Enrollment as a student-program-course-academic-term record with academic year, term, batch, courses, and fees. |
| BM-05 | OpenEduCat school management buyer guidance. | Core school-management modules: timetable generation, fee/finance, attendance, gradebook/assessment, admissions, records, and communication. |
| BM-06 | Google OR-Tools CP-SAT scheduling documentation and school-scheduling examples. | Automatic scheduling should be a constraint model with hard constraints, soft objectives, solver status, time limits, and conflict/feasibility reporting. |
| BM-07 | Google Cloud Vision OCR documentation. | Current OCR benchmark for extracting text from images/PDF/TIFF and routing low-confidence output to human review. |
| BM-08 | Google Cloud Document AI Form Parser documentation. | Future benchmark for structured form extraction: key-value pairs, tables, checkboxes, generic entities, text, and layout. Not the current TALA OCR baseline unless promoted. |
| BM-09 | PayMongo Payment Acceptance documentation. | Payment intent lifecycle, multiple methods, webhook outcome notification, test mode, and go-live validation. |
| BM-10 | Laravel Excel / Maatwebsite Excel 3.1 documentation plus installed TALA package evidence (`maatwebsite/excel` v3.1.x). | Controlled CSV/XLSX imports and exports, chunking, queued processing where safe, downloadable export classes, and collection/model import patterns for templates, rosters, and reports. |
| BM-11 | Filament v5 documentation. | Staff Admin Nexus pattern: resources, tables, filters, forms, infolists, actions, widgets, notifications, navigation, and tests. |
| BM-12 | Livewire 4 navigation and local TallStackUI stack. | Student/applicant UI pattern: server-rendered flows, progressive navigation/loading, validation states, and read-only PWA boundaries. |
| BM-13 | DomPDF and `barryvdh/laravel-dompdf` documentation plus installed TALA package evidence. | Generated official artifacts can be rendered from controlled Blade/HTML templates into saved, streamed, or downloaded PDFs without making the PDF the operational source of truth. |
| BM-14 | `chillerlan/php-qrcode` documentation, Laravel signed URL documentation, and current TALA COR verification tests. | QR payloads should point to opaque/signed verification routes or tokens and must not embed raw private student, term, balance, grade, or hash data. |
| BM-15 | Registrar/student-record official-document patterns such as transcript, certification, verification, and diploma request handling. | Official academic documents are controlled Registrar outputs from authoritative records, with issuer/signature/release/waiver or hold handling where applicable. |
| BM-16 | Frappe Education Course Schedule. | Mature class-schedule setup binds a course, instructor, room, date, and time and checks instructor/room conflicts before saving. |
| BM-17 | Laravel 12 Queues. | Solver dispatch should run after the source transaction commits, with explicit queue, timeout, retry/backoff, and failed-job evidence rather than blocking the request transaction. |
| BM-18 | Frappe Education Fee Structure and Fees. | Mature education finance separates reusable academic-year/term/program fee structures and component lines from per-student fee records, due dates, payments, and accounting entries. |
| BM-19 | Laravel 12 database transactions and pessimistic locking. | Assessment, payment, ledger, balance projection, capacity, and handover effects should commit atomically with retry-safe uniqueness and row locks around concurrent financial mutation. |
| BM-20 | Frappe Education assessment-plan, assessment-result, and grading-scale domain models. | Mature gradebook structure separates the assessment plan/result from a reusable grading scale and records the student/course/assessment context used to derive a result. TALA adapts this as versioned profile resolution plus immutable submission snapshots rather than copying Frappe internals. |
| BM-21 | DepEd Order No. 8, s. 2015. | Historical SHS assessment reference only. It may inform archived evidence review but must not define active College grading, progression, offerings, or remediation behavior. |
| BM-22 | Registrar enrollment-verification and transcript patterns from mature university registrar offices, including official enrollment certifications, official transcripts, release authorization, and third-party verification boundaries. | Official documents are Registrar/student-record outputs from authoritative records. Enrollment verification is distinct from a transcript/permanent academic record, and release may require requester identity, consent, or hold review. |
| BM-23 | DepEd Order No. 54, s. 2016 and LIS school-record guidance for Form 137/SF10 and Form 138/SF9 transfer/release. | Form 137/SF10 is a permanent learner record and Form 138/SF9 is a report card. Transfer/release must preserve confidentiality and school-to-school/request evidence; TALA records source/release evidence but does not replace DepEd LIS or external receiving-school transactions. |
| BM-24 | Livewire 4 page/loading/offline directives, local TallStackUI display components, MDN Service Worker API, and MDN Cache API. | Student Hub pages can be routed as Livewire pages and should expose loading/offline feedback. PWA caches are explicit, versioned, secure-context browser storage; TALA must avoid unapproved sensitive protected-cache behavior, label freshness, disable offline mutations, and clear protected caches on logout/account denial. |
| BM-25 | Oracle PeopleSoft Campus Solutions Student Records program actions/statuses and Academic Advisement graduation-requirement tracking, plus Banner graduation-application/degree-audit patterns. | Mature SIS status and completion are not free-text profile edits: program actions/statuses are reasoned lifecycle events; graduation requires degree/program audit against requirements, deficiencies, clearances, application/review, completion/degree conferral evidence, and separate credential release or external reporting. |
| BM-26 | DepEd Order No. 40, s. 2012 Child Protection Policy; CHED Manual of Regulations for Private Higher Education discipline/due-process sections; SIA workflow. | Behavior and discipline records must be typed, evidence-based, notice-aware, due-process/appeal-capable, and role-restricted before they can affect enrollment or clearance. |
| BM-27 | RA 9258 Guidance and Counseling Act, NPC Advisory Opinion No. 2025-017 on school records/privacy, and RA 10173/Data Privacy Act. | Guidance/counseling and sensitive school-record details require licensed/authorized handling, privilege/confidentiality controls, data-subject access safeguards, purpose limitation, and least-privilege visibility. |
| SIA-01 | `business-evidence/INSTITUTION WORK  FLOW CURRENT.md`. | Local operating workflow: enrollment gates, modality, document requirements, finance clearance, grade audit, graduation review, manual external offices/systems, and physical evidence. |

---

## Feature Benchmark Matrix

| TALA feature area | Benchmark anchors | SIA workflow fit | Final FS/TS baseline expectation | Implementation priority |
| --- | --- | --- | --- | --- |
| Authentication, roles, and account lifecycle | BM-01, BM-11 | Staff, students, and applicants need separate authority boundaries. | Use role-based access, one operational staff role where required, protected staff/student routes, logout/session expiry, and audit for critical account changes. | UAT Core |
| Person and student master record | BM-01, BM-02, BM-04 | Permanent student folder and school record are central to every office workflow. | Maintain one canonical person/student profile with identifiers, demographics, College program, year level, status, and linked evidence. | UAT Core |
| Academic calendar, school year, and term setup | BM-01, BM-04, SIA-01 | The active deployment is College-only and uses College academic years, terms, calendars, and gates. | Model academic years/terms/calendar phases explicitly; do not hardcode one global school year. | UAT Core |
| Programs, curricula, subjects, and course catalog | BM-01, BM-04, BM-05 | Registrar/Academic Head need curriculum and subject references for enrollment, schedules, grades, and graduation. | Programs/curricula/subjects are managed as structured academic foundation data with versioning/readiness where scheduling depends on them. | UAT Core |
| Admission offering and applicant intake | BM-01, BM-03, BM-04, SIA-01 | New/transfer/returning flows start before enrollment and are document-dependent. | Publish term-scoped admission offerings; applicant selects eligible route; intake snapshots requirement policy and never falls back silently. | UAT Core |
| Admission requirements and document checklist | BM-01, BM-03, SIA-01 | Admission-gate and retention documents differ in timing and effect. | Configurable requirement policies, per-item checklist status, accepted evidence method, deadlines, and human Registrar verification. | UAT Core |
| OCR-assisted document review | BM-07, BM-08, SIA-01 | Uploaded documents are preliminary; Registrar verifies authenticity. | Current baseline: Google Vision OCR text extraction plus extracted text/confidence/manual review. Document AI is Phase 2 for structured KVP/table/checkbox extraction if needed. | UAT Core for current OCR review; Phase 2 for Document AI form parsing |
| Enrollment and matriculation handover | BM-01, BM-04, SIA-01 | Student becomes officially enrolled only after required gates/finance/placement rules. | Applicant-to-student handover is atomic, creates/reuses official profile/enrollment, applies section/finance/capacity prerequisites, and records state evidence. | UAT Core |
| School-year/term enrolled-student inventory | BM-01, BM-02, BM-04, BM-10, SIA-01 | School needs to know who is enrolled per term/batch/section; external encoding happens outside TALA. | Provide authorized College roster/inventory by academic year/term, program, year level, section, delivery group, and status. Generic CSV/XLSX export is allowed; DepEd/CHED/LIS portal submission/tracking is not a TALA module. | UAT Core/Supporting |
| Sectioning, modality, and capacity | BM-01, BM-04, BM-05, SIA-01 | Manual section and modality decisions exist; payment secures seat. | Store section and delivery group assignment, enforce configured capacity, and keep tentative placement separate from secured official enrollment. | UAT Core |
| Automatic scheduling generation | BM-05, BM-06, BM-17, SIA-01 | School timetabling must respect faculty availability, room, subject, modality, and section constraints. | Use OR-Tools CP-SAT or equivalent constraint solver: immutable input snapshot, after-commit queued dispatch, hard constraints, soft objectives, time limit, bounded solver/domain outcomes, explainable conflicts, staff review, commit, publish. Do not hand-roll final automatic scheduling logic. | UAT Core if demo path depends on schedule; otherwise Supporting |
| Manual schedule override and publication | BM-01, BM-05, BM-11, BM-16, SIA-01 | Registrar/Academic Head need controlled override and official publication. | Manual official assignments must still enforce instructor, delivery-group, and room conflicts. Availability/workload overrides require role authority, reason, and audit. Published schedules change only through an approved, audited revision workflow. | UAT Core/Supporting |
| Tuition assessment, fees, discounts, and installment policy | BM-01, BM-04, BM-05, BM-18, SIA-01 | Finance clearance and SOA shape enrollment status and payment. | Assessment resolves one approved effective-dated fee structure, materializes component/policy snapshots per enrollment, then posts immutable ledger entries. Discounts, scholarships, downpayment, and installment rules are versioned policy rather than hardcoded calculations. | UAT Core |
| Payment acceptance and payment confirmation | BM-09, BM-01, BM-19, SIA-01 | Online and OTC/manual payments need verified evidence. | Online payments use PayMongo provider state/webhook/idempotency. Manual/OTC payments use Accounting confirmation with unique reference, channel, receipt evidence, and the same transactional ledger-posting boundary. | UAT Core |
| Ledger, SOA, finance clearance, and daily reconciliation | BM-01, BM-05, BM-18, BM-19, SIA-01 | Previous balance gates enrollment; current balance is private; SIA reconciles receipts, ledger, and cash/deposits. | Ledger history is immutable; materialized balance is a rebuildable projection. Clearance is computed. Daily close compares ledger payments, issued receipts, and actual cash/deposits with variance reason and independent approval. | UAT Core |
| SOA, receipts, and finance-generated PDFs | BM-01, BM-09, BM-13, SIA-01 | Accounting needs printable or downloadable evidence of assessment, confirmed payment, balance, and clearance. | SOA and receipt PDFs are generated artifacts derived from immutable ledger/payment data, with reference number, issue time, issuing actor/channel, template version, checksum, and void/supersede handling where applicable. | UAT Core/Supporting |
| Promissory notes and payment accommodation | BM-01, BM-09, local policy/regulatory evidence | Useful institutional feature, but not required for the first SIS spine unless demo data needs it. | Typed promissory lifecycle with eligibility, endorsement/approval, due date, settlement, expiry, and non-payment effects; no debt-based exam denial. | Phase 2 unless promoted |
| Faculty class list and workload visibility | BM-01, BM-05, SIA-01 | Faculty receives official class lists after enrollment/schedule. | Faculty sees only published assigned classes/students and academic roster fields. Finance status, balances, payment evidence, and finance-derived restrictions are not faculty class-list data. | UAT Core |
| Grade encoding, submission, verification, finalization, and correction | BM-01, BM-05, BM-20, BM-21, SIA-01 | Faculty computes and submits a roster package; Registrar verifies or returns it; only verified results enter permanent records; corrections need evidence. | Resolve and snapshot one grading profile; preserve immutable submission packages; Registrar verification atomically finalizes official history; post-final correction preserves old/new values, approval, application, and supersession evidence. | UAT Core |
| Academic progression, irregular students, and subject suggestion | BM-01, BM-04, SIA-01 plus regulatory benchmark where applicable | Active SIA scope uses College progression, irregular/manual evaluation, and subject-retake flows. | Use grade history, prerequisites, curriculum gaps, failed/INC/back subjects, and versioned policy. Regulator-conflicting rules must be benchmarked before automation. | Supporting/UAT Core if demo includes continuing student |
| Student Hub read-only visibility | BM-01, BM-02, BM-12, BM-24 | Student should see own enrollment, schedule, grades, balance, documents, and notices. | Student Hub reads authoritative owner-scoped services; current UAT can be read-only. Protected PWA cache behavior must be explicit, freshness-labeled, logout-safe, and mutation-free. Advanced install/offline polish is deferred until browser/device smoke tests prove it. | UAT Core-lite |
| Applicant and public self-service | BM-03, BM-12 | Applicants need intake/status; public needs help/FAQ. | Public routes expose only intake/status/help, never protected records. Applicant portal handles staged intake and checklist status. | UAT Core if public intake is in demo; otherwise Core-lite |
| Document request catalog and fulfillment | BM-01 3Cs/checklists, BM-05, SIA-01 | Registrar handles official document requests, holds, fees, release evidence. | Versioned document catalog, fee snapshot, eligibility/holds, request lifecycle, claimant/release evidence, and optional manual delivery evidence. | Supporting |
| Official generated academic documents | BM-01, BM-13, BM-15, BM-22, BM-23, SIA-01 | COR, COE, COG, TOR, Form 137 copies, report cards, and diplomas/certificates are expected Registrar outputs, but the source record remains the database lifecycle. | Generated academic documents must be issued from authoritative enrollment, grade, curriculum, ledger, and student-record services. Store or reproduce an immutable issuance snapshot with document type, subject, term/request, template version, issuer, issue time, checksum, serial/reference number where applicable, lifecycle state, and release evidence. Corrections supersede or revoke; they do not overwrite history. | UAT Core for COR; Supporting/Phase 2 for full catalog |
| COR generation and QR verification | BM-01, BM-13, BM-14, BM-22, SIA-01 | COR proves official enrollment and is visible to students, Registrar, and class-list processes after admission, finance, capacity, and placement gates clear. | COR is generated only for a canonical enrolled state. The QR code resolves to an online verification route backed by an opaque token or signed URL, displays only minimal verification status/metadata, and supports revocation/supersede when enrollment or issuance is invalidated. | UAT Core/Supporting |
| TOR, Form 137, grade records, and diploma release | BM-01, BM-15, BM-22, BM-23, SIA-01 | Permanent academic records require finalized grades, curriculum history, transfer records, clearances, school-to-school/request evidence, and Registrar approval. | TOR/Form 137/diploma workflows require eligibility/hold checks, finalized academic history, controlled request/release evidence, issuer/signature metadata, and privacy/waiver handling before release. Form 137/SF10 transfer evidence is recorded without replacing DepEd LIS or the receiving school's external process. Full automation can follow after the core enrollment-grade lifecycle is stable. | Supporting/Phase 2 |
| Enrollment adjustment requests | BM-01, BM-04, SIA-01 | Drop/withdraw/shift/transfer/modality changes exist but need settled rules. | Typed request workflows validate term window, academic/finance/capacity effects, approval, and atomic mutation. | Phase 2 unless presentation path requires |
| Student status lifecycle | BM-01, BM-02, BM-25, SIA-01 | No-show, LOA, returnee, archive/reactivate, transfer-out affect records. | Status transitions must be typed, reversible/terminal where appropriate, reasoned, audited, effective-dated, notice-aware, and separate from staff account archive. | Supporting/Phase 2 |
| Graduation evaluation and completion boundary | BM-01, BM-15, BM-25, SIA-01 | Graduation review checks academic history, clearances, deficiencies, and external CHED/SO process. | TALA should support graduation eligibility/evaluation snapshot and completion status from finalized grades/curriculum/clearances. Diploma/CHED/SO external submission is outside first UAT unless explicitly promoted. | UAT Core boundary; Phase 2 for full credential release |
| Attendance, behavior, discipline, and guidance | BM-01, BM-05, BM-26, BM-27, SIA-01 | Business workflow names these as gates, but current data source/process is not yet complete. | Do not silently infer these gates. Add typed attendance evidence, excused/adjusted-hour handling, case ownership, notices, response/appeal, confidential guidance referral/closure, privacy classification, resolution, and approved policy effects before enforcing enrollment, clearance, progression, or graduation blocks. | Phase 2 / Benchmark Gate |
| Notifications and reminders | BM-01 3Cs, BM-05 | Staff/student communication supports deadlines and actions. | Notifications must be private, role-scoped, tied to lifecycle events, and not replace authoritative state. | Supporting |
| Imports and legacy migration | BM-10, BM-01, SIA evidence files, current `ImportBatch` implementation | Legacy sheets/forms support startup data and audit. | Use controlled templates, private files, schema/parser version, validation preview, zero-error commit where required, import batch audit, and normalized accepted rows. Current runtime proves curriculum import only; every other import type needs its own controlled service before it can be claimed. | Supporting/UAT Core for data needed by demo |
| Exports and reports | BM-01, BM-10, SIA-01 | Staff may need roster, schedule, SOA, grade, and generic report outputs. | Export only authorized fields from authoritative queries with explicit filters, field allowlists, actor/time/row-count audit, and temporary or managed artifact handling. CSV/XLSX/PDF outputs are evidence/artifacts, not operational source of truth. | Supporting |
| Admin Nexus staff UI | BM-11 | Staff operate through Filament resources/actions. | Staff UI should use typed Filament resources, tables, filters, infolists, actions, widgets, policy navigation, and service-backed actions. | UAT Core |
| Security, privacy, and audit | BM-01, BM-09, BM-11, SIA privacy needs | Student, finance, document, OCR, and support data are sensitive. | Role boundaries, private storage, signed access where needed, immutable activity logs, safe errors, and audit evidence for lifecycle actions. | UAT Core |
| External DepEd/CHED/LIS encoding portals | SIA-01, BM-01 external-system boundary pattern | School encodes records into outside government systems; TALA is not that portal. | TALA owns accurate enrollment/student record data and generic inventory/export where useful. It does not implement special portal formats, submission automation, or external completion tracking for UAT baseline. | External Boundary |

---

## Immediate FS/TS Hardening Implications

1. Replace wording that sounds like TALA will submit to DepEd/CHED/LIS with "school-year/term enrolled-student inventory" and "external portal encoding boundary."
2. Keep Google Vision as the current OCR baseline. Add Document AI only as a future structured extraction option.
3. Keep OR-Tools CP-SAT as the scheduling benchmark. The TS should require hard constraints, soft objectives, solver status, conflict reporting, and staff review/commit/publish.
4. Keep PayMongo as the online payment benchmark and preserve manual Accounting confirmation as an equivalent school-counter channel.
5. Generated official documents must be modeled as derived issuance artifacts with source snapshot, template version, issuer, serial/reference, checksum, release state, and revoke/supersede behavior where applicable.
6. QR verification must use opaque/signed routes or tokens and expose verification status, not raw private student/term/finance/grade data.
7. Keep FS/TS complete as the target system. Put implementation priority in SDD/checklist/rescue tracker, not scattered throughout every FS/TS module.
8. Authentication/RBAC baseline is applied through the active Laravel/Fortify configuration, Laravel policies, Spatie Permission, and Filament navigation/action authorization. Do not promise a custom three-attempt/five-minute lockout in FS/UAT unless the TS and executable tests prove that exact policy.
9. Admissions baseline is a published-offering -> applicant -> materialized checklist -> evidence submission/review -> eligibility decision -> enrolled-student handover lifecycle. Requirement resolution must fail closed when setup is missing or ambiguous; OCR is a provisional review assistant only.
10. Enrollment baseline is an atomic, idempotent handover after admission, readiness, finance, placement, and capacity gates; tentative placement is not enrollment, and generic roster export is not government-portal integration.
11. Scheduling baseline uses a proven constraint solver with immutable inputs, hard constraints, weighted soft objectives, bounded runtime, explicit solver status, diagnostic conflicts, and review/approve/publish transitions.
12. Finance baseline uses effective-dated assessment policy, one immutable ledger-posting boundary for manual and PayMongo channels, computed clearance, replay-safe webhooks, and derived SOA/receipt artifacts.
13. Grade baseline scopes faculty to published assignments, snapshots grading policy, separates submission from verification/finalization, and preserves old/new evidence for authorized corrections.
14. Official-document baseline issues from authoritative records, stores immutable issuance metadata, records request/release evidence, respects Form 137/Form 138 school-record confidentiality, and uses opaque/signed minimal-disclosure verification with revoke/supersede history.
15. Student Hub baseline is service-backed and owner-scoped; UAT may remain read-only, while offline/PWA behavior must disable mutation and protect cached sensitive data.
16. Student-status and completion baseline uses typed effective-dated transitions and reproducible graduation evaluation; external government submission and credential processing remain outside the first UAT boundary.
17. Import/export baseline requires versioned templates, private sources, validation preview, explicit audited commit, field allowlists, generic artifacts, and per-import-type services rather than portal-specific automation. The current implementation proves curriculum import only; roster/report exports remain separate implementation work.
18. Attendance, behavior, discipline, and guidance remain a benchmark gate until typed evidence, ownership, privacy, notice/response, appeal, and resolution policies exist; missing data must never silently block enrollment or completion. Guidance/counseling information is a restricted support domain, not ordinary Faculty/Accounting/Registrar table data.

---

## Source Links

- Oracle PeopleSoft Campus Solutions Overview: https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lsfn/concept_CampusSolutionsOverview-ab58bf.html
- Ellucian SIS overview: https://www.ellucian.com/blog/what-student-information-system-higher-ed
- Frappe Education Student Admission: https://docs.frappe.io/education/student_admission
- Frappe Education Program Enrollment: https://docs.frappe.io/education/program-enrollment
- Frappe Education Course Schedule: https://docs.frappe.io/education/course-schedule
- OpenEduCat buyer guide: https://openeducat.org/articles/school-management-software-buyers-guide/
- Google OR-Tools CP-SAT scheduling: https://developers.google.com/optimization/scheduling/employee_scheduling
- Google OR-Tools constraint optimization: https://developers.google.com/optimization/cp
- Google Cloud Vision OCR: https://docs.cloud.google.com/vision/docs
- Google Cloud Document AI Form Parser: https://docs.cloud.google.com/document-ai/docs/form-parser
- PayMongo Payment Acceptance: https://docs.paymongo.com/docs/payment-acceptance-introduction
- Laravel Excel: https://laravel-excel.com/
- Filament v5 resources: https://filamentphp.com/docs/5.x/resources/overview
- Livewire navigation: https://livewire.laravel.com/docs/4.x/navigate
- Laravel DOMPDF wrapper: https://github.com/barryvdh/laravel-dompdf
- Dompdf HTML-to-PDF renderer: https://github.com/dompdf/dompdf
- chillerlan PHP-QRCode manual: https://php-qrcode.readthedocs.io/en/stable/
- Laravel signed URLs: https://laravel.com/docs/12.x/urls#signed-urls
- Laravel 12 queues: https://laravel.com/docs/12.x/queues
- Frappe Education Fees: https://docs.frappe.io/education/fees
- Frappe Education Fee Structure: https://docs.frappe.io/education/fee-structure
- Laravel 12 database transactions: https://laravel.com/docs/12.x/database#database-transactions
- Frappe Education Assessment Result model: https://github.com/frappe/education/blob/develop/education/education/doctype/assessment_result/assessment_result.json
- Frappe Education Assessment Plan model: https://github.com/frappe/education/blob/develop/education/education/doctype/assessment_plan/assessment_plan.json
- Frappe Education Grading Scale model: https://github.com/frappe/education/blob/develop/education/education/doctype/grading_scale/grading_scale.json
- DepEd Order No. 8, s. 2015: https://www.deped.gov.ph/wp-content/uploads/2015/04/DO_s2015_08.pdf
- California BPPE transcript definition and record-retention reference: https://www.bppe.ca.gov/students/transcripts.shtml
- Uniformed Services University Registrar records, transcripts, certifications, and diplomas reference: https://reg.usuhs.edu/request-records
