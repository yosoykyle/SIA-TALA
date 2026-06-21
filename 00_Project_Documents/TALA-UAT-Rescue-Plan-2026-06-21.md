# TALA UAT Rescue Plan and Scope-Freeze Tracker

**Created:** 2026-06-21  
**Purpose:** Keep the final UAT push controlled by reducing the project to a working SIS core, preserving stronger TALA ideas as deferred enhancements, and making the documentation/tracking set factual before presentation.

---

## Control Rule

For the rescue period, no feature is treated as a UAT blocker unless it satisfies at least one of these conditions:

1. It is required by the current institutional workflow.
2. It is a default Student Information System lifecycle capability needed to prove applicant-to-completion flow.
3. It is already implemented and visible enough that the professor may reasonably test it.
4. It is needed to prevent the demo from giving a false pass/fail result.

Everything else is parked as a deferred enhancement, not deleted.

---

## Working Definition of the UAT Baseline

The UAT baseline is the smallest working TALA system that can demonstrate this flow:

`Login and role access -> applicant intake -> admission review/documents -> enrollment/section/finance clearance -> student record -> faculty class/grade operations -> student read-only view -> completion/graduation status boundary`

The baseline does not require every advanced automation, external submission, report, or future research extension to be finished.

---

## Day 1 Goal: Documentation and Project-Management Alignment

Day 1 is successful when the active project documents clearly answer:

- What is core for UAT?
- What is deferred?
- What is implemented now?
- What is testable now?
- What still blocks or risks the presentation?
- Which SDD slices remain active after scope freeze?

## Day 1 Checklist

| Step | Output | Status |
| --- | --- | --- |
| 1. Re-read institutional workflow | Core business flow extracted from `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` | Completed |
| 2. Benchmark default SIS lifecycle | Baseline modules confirmed from external SIS patterns and reused as scope filter | Completed |
| 3. Audit FS/TS scope language | Goal-state, implemented, deferred, and transitional claims separated | Completed after benchmark hardening Groups 1-11 |
| 4. Classify modules | Every major feature marked Core, Supporting, Deferred, External Boundary, or Remove/Park | Completed after benchmark hardening Groups 1-11 |
| 5. Update reconciliation matrix | UAT-impacting gaps separated from safe-to-defer gaps | Completed after benchmark hardening Groups 1-11 |
| 6. Update SDD execution map | Active work reordered around working SIS flow instead of full feature wishlist | Completed after benchmark hardening Groups 1-11 |
| 7. Update local iteration checklist | Current local progress mirrors the rescue scope and active blockers | Completed after benchmark hardening Groups 1-11 |
| 8. Update UAT/manual test tracking | Testable-now cases separated from goal-state cases | Completed after benchmark hardening Groups 1-11 |
| 9. Update Linear `TAL-28` | Tracker reflects the rescue pivot, completed docs, and next implementation target | Completed |
| 10. Commit/tag rescue baseline when stable | Git checkpoint records the exact baseline used for UAT preparation | Pending |

---

## Benchmark Baseline Used

The rescue baseline follows common SIS lifecycle patterns rather than inventing a feature list from scratch:

| Benchmark signal | Applied TALA baseline impact |
| --- | --- |
| Ellucian SIS overview: SIS platforms commonly cover admissions, enrollment, course scheduling, grades/academic records, performance tracking, and financial management. | Keep admission, enrollment, scheduling/classes, grades/records, and finance in core scope. |
| EdVisorly SIS overview: SIS acts as the authoritative record from application through graduation/alumni status, including enrollment status, academic history, grades, transcripts, degree progress, and financial standing. | Keep applicant-to-completion lifecycle and student record as the baseline spine. |
| OpenEduCat higher-education SIS comparison: higher education needs transcript/credit-transfer, prerequisite, registration, records, billing, and compliance support, while K-12 emphasizes grade-level progression. | Keep College credit/prerequisite behavior in active scope; archived SHS grade-level progression evidence is historical only. Defer advanced transcript and compliance automation. |
| Open-source/campus-management feature surveys consistently include admission/enrollment, fee/payment tracking, timetable/scheduling, exams/grades, student records, and role communication. | Prioritize those SIS foundations and approved integrations. Treat advanced dashboards, PWA polish, and external agency submission as enhancements unless needed for the demo path. |

Sources: Ellucian SIS overview, EdVisorly SIS overview, OpenEduCat SIS guidance, and current open-source/campus-management feature surveys reviewed on 2026-06-21.

---

## Core Scope Classification Draft

| Area | Rescue Decision | Why |
| --- | --- | --- |
| Authentication and role access | Core | Required for every tested role and security boundary. |
| Admin/Registrar staff access | Core | Staff must operate the business workflow. |
| Applicant intake | Core | Entry point of the SIS lifecycle and institutional workflow. |
| Admission document review | Core | Required before enrollment and directly supported by business evidence. |
| Enrollment status and section assignment | Core | Converts applicant/student into active enrollment. |
| Finance clearance and payment evidence | Core | Business workflow makes financial clearance part of enrollment control. |
| Student record/profile | Core | Central SIS record after intake and enrollment. |
| Faculty class list and grade encoding/finalization | Core | Required to show academic operation after enrollment. |
| Student Hub read-only status/profile/schedule/grades/balance | Core-lite | Needed as student-facing proof, but advanced polish and write actions can be deferred. |
| Document-request portal/catalog/fulfillment | Removed | Manual institutional process remains outside TALA; remove its runtime, UI, schema, tests, and UAT cases under SDD-00D. |
| Enrollment adjustment workflows | Supporting/defer | Drop, withdrawal, shift, and transfer are valid SIS flows but not first-path UAT blockers unless already testable. |
| Graduation evaluation/completion status | Core boundary | Need completion-state proof or boundary, but full diploma/SO/government submission can be deferred. |
| Promissory notes | Deferred unless needed by demo data | Useful TALA feature but not required to prove the base SIS flow. |
| Refund lifecycle | Deferred | Complex finance side effect; should not block the main flow. |
| Advanced analytics/dashboard widgets | Deferred | Not required for working SIS flow. |
| PWA/offline polish | Deferred | Presentation enhancement, not core workflow. |
| School-year/term enrolled-student inventory | Core/supporting | TALA must know who is enrolled per College school year/term, program, year level, and section so authorized staff can view the batch/roster. External DepEd/CHED/LIS encoding remains outside TALA. |

---

## Day 2 Goal: Stabilize Working Demo Path

Day 2 is successful when the system has a tested, repeatable, manually demonstrable path with stable demo data.

## Day 2 Checklist

| Step | Output | Status |
| --- | --- | --- |
| 1. Pick one complete demo path | Named users, records, and expected screens | Pending |
| 2. Fix only blockers in that path | Code changes limited to core workflow failures | Pending |
| 3. Seed or document demo data | Professor can log in and see the same state | Pending |
| 4. Run focused automated tests | Only tests supporting the UAT path and touched code | Pending |
| 5. Run manual testable-now checklist | Pass/fail recorded against the exact build | Pending |
| 6. Hide or label unsafe unfinished UI | No misleading unfinished feature is treated as passed | Pending |
| 7. Commit/tag presentation build | Exact code/docs version is preserved | Pending |
| 8. Update Linear and local checklist | Final rescue status and remaining risks are recorded | Pending |

---

## Required Document Updates During Rescue

| Document | Rescue role | Required update |
| --- | --- | --- |
| `TALA-Functional-Specification.md` | Goal-state business baseline | Add/confirm baseline status and separate core from deferred requirements. |
| `TALA-Technical-Specification.md` | Goal-state technical baseline | Separate implementation-ready contracts from transitional/deferred notes. |
| `TALA-Workflow-Reconciliation-Matrix.md` | Conflict and scope control | Mark which gaps affect UAT and which are deferred safely. |
| `TALA-SDD-Execution-Map.md` | Active execution order | Reorder around the working SIS path. |
| `TALA-Local-Iteration-Checklist.md` | Local progress tracker | Mirror rescue scope, test status, and blockers. |
| `TALA-Master-System-Test-Cases.md` | Goal-state and UAT tracker | Separate testable-now cases from future/goal-state cases. |
| `uat-readiness/*` | Presentation readiness evidence | Keep manual UAT instructions factual for current UI. |
| Linear `TAL-28` | External tracker | Update whenever rescue baseline or local iteration status changes. |

---

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-21 | Pivot from full SDD completion to UAT rescue baseline. | Remaining SDD scope is too large for the available time; UAT needs a truthful working SIS core. |
| 2026-06-21 | Preserve advanced TALA features as deferred enhancements. | Research value is retained without making unfinished features presentation blockers. |
| 2026-06-21 | FS/TS remain baseline specs, not implementation-complete proof. | Code/tests/UAT checklist prove current implementation; FS/TS define approved target behavior. |
| 2026-06-21 | Initial control-document overlay added to FS, TS, reconciliation, SDD, local checklist, and master test cases. | Day 1 needs project-management alignment before additional feature work. |
| 2026-06-21 | Benchmark baseline matrix created. | FS/TS can now be hardened module-by-module against mature SIS and official integration references. |
| 2026-06-21 | Specification benchmarking process created. | Future FS/TS updates now follow a repeatable feature-group process before SDD/code changes. |
| 2026-06-21 | Official generated document, PDF, and QR verification benchmark gap added to the matrix. | COR is UAT-core where enrollment proof is needed; full TOR/Form 137/diploma automation remains supporting/Phase 2 unless promoted. |
| 2026-06-21 | Linear `TAL-28` mirrored the benchmark-process and official-document baseline update. | Local iteration changes now have tracker evidence without claiming runtime completion. |
| 2026-06-21 | FS/TS submission-baseline alignment added. | The specs now state the benchmark-grounded final-form SIS spine and technical contract map before implementation/rescue details. |
| 2026-06-21 | Admission-requirement baseline contradiction corrected. | Final form assigns versioned admission-requirement setup to Registrar/authorized academic operations; internal settings JSON is transitional only. |
| 2026-06-21 | Benchmarking process applied to Feature Group 1 identity/access. | FS/TS now clarify public/applicant/student/staff protected-route boundaries, one-role staff access, Laravel/Fortify-configured throttling and recovery behavior, logout/session expiry, safe errors, and audit expectations without claiming runtime completion. |
| 2026-06-21 | Benchmarking process applied to Feature Group 2 admissions/applicant intake/document OCR. | FS/TS now summarize published-offering intake, materialized requirement checklists, self-service and Registrar-assisted evidence channels, OCR-as-assistive-review, admission-gate versus retention-undertaking effects, and fail-closed setup behavior without claiming runtime completion. |
| 2026-06-21 | Benchmark-hardening queue completed for Feature Groups 3-11. | FS §2.3 and TS §1.4 now provide synchronized goal-state acceptance and technical contracts for enrollment, scheduling, finance, grades, official documents, Student Hub, status/completion, data exchange, and attendance/guidance. Reconciliation and SDD priority remain controlling; no runtime completion is claimed. |
| 2026-06-21 | Feature Group 3 passed submission-lock audit. | Canonical enrollment states, tentative/secured capacity, atomic payment-to-handover, roster/export, COR issuance/verification, and external LIS boundaries are specification-complete. Transitional runtime states, placement-aware handover, roster export, and complete COR issuance remain SDD-07A implementation work. |
| 2026-06-21 | Feature Groups 4-11 passed deep submission-lock audits. | Baseline is now specification-complete. Runtime gaps assigned to SDD slices. Group 10 (curriculum import) is implemented but roster/export gaps remain. Group 11 (attendance/discipline) is benchmark-gated/deferred with no hidden blocks. |

---

## Open Risks

| Risk | Impact | Handling |
| --- | --- | --- |
| FS/TS still imply some deferred features are active | Professor or future developers may expect unfinished behavior | Mitigated; monitor future edits. Baseline hardening completed. |
| UAT test cases may include non-testable goal-state flows | Failed demo if marked as passed | Split goal-state from testable-now before submission/presentation. |
| Filament UI contains unfinished or messy surfaces | Demo may enter unsupported paths | Prioritize core path and label/hide unsafe unfinished areas where feasible. |
| Demo data may not match committed system state | Professor may test a different version | Commit/tag tested build and document seed/login credentials. |
| Scope may drift again during SDD work | Remaining time gets spent on non-core features | Use this tracker and reconciliation matrix before accepting new implementation work. |
