# TALA Specification Benchmarking and Baseline Hardening Process

**Created:** 2026-06-21  
**Purpose:** Define the repeatable process for turning business evidence, mature SIS benchmarks, official integration references, and current code reality into an actionable FS/TS baseline and a controlled SDD implementation queue.

---

## Why This Exists

TALA has two different needs that must not be mixed:

1. **FS/TS final-form baseline:** what the finished system is expected to do when complete.
2. **SDD/UAT execution scope:** what the team implements and proves first under the current time limit.

The Functional Specification and Technical Specification should be complete enough to guide the capstone paper and future development. They should not become a noisy progress tracker. Implementation status, rescue priority, and testable-now boundaries belong in the SDD map, local checklist, reconciliation matrix, UAT artifacts, and Linear.

---

## Working Terms

| Term | Meaning |
| --- | --- |
| Business evidence | Current SIA workflow, forms, sheets, policy notes, and archived source files that describe the real institutional process. |
| Benchmark anchor | Mature SIS documentation, official platform documentation, legal/regulatory reference, or proven domain system pattern used to avoid inventing a workflow from scratch. |
| Feature group | A module-sized area such as admissions, enrollment, scheduling, finance, official documents, grades, Student Hub, or graduation. |
| Benchmark rule | The distilled implementation rule TALA adopts from the benchmark after mapping it to SIA context. |
| FS baseline | User-facing business behavior, roles, states, happy paths, negative paths, edge cases, and access boundaries. |
| TS contract | Schema, service, policy, UI surface, integration, file, job, audit, and test contract needed to make the FS baseline implementable. |
| SDD digest | The implementation slice order and priority classification after FS/TS are clear. |

---

## Per-Feature Benchmarking Process

Use this process for every material feature before coding or rewriting specs.

1. **Inventory the feature**
   - Name the feature and owning module.
   - Identify affected roles, UI surfaces, records, integrations, generated artifacts, and tests.
   - Record whether code already exists.

2. **Read local evidence first**
   - Start from `business-evidence/INSTITUTION WORK  FLOW CURRENT.md`.
   - Check other `business-evidence/` files and `archive/raw-source-files/` only when fields, prices, forms, or workflow details are missing.
   - Check FS/TS and the reconciliation matrix for existing decisions.

3. **Classify the benchmark type**
   - Mature SIS benchmark: default lifecycle or module structure.
   - Official technology benchmark: package/API behavior and constraints.
   - Legal/regulatory benchmark: binding rule, privacy, grading, progression, or learner-rights issue.
   - Related-product benchmark: feature pattern from a credible system when no SIS source is enough.
   - Local implementation benchmark: existing TALA code/tests when the behavior is already implemented.

4. **Select authoritative sources**
   - Prefer official product documentation, official package documentation, official government/legal sources, and mature SIS references.
   - Avoid random blogs unless they only illustrate a UI convention and no authoritative source is needed.
   - For Laravel, Filament, Livewire, TallStackUI, integrations, or cloud services, use official docs or available MCP documentation tools when exposed.

5. **Write the benchmark rule**
   - Convert the source into one TALA decision.
   - State what TALA will do and what it will not do.
   - If the benchmark only proves a future option, mark it as Phase 2 instead of UAT Core.

6. **Map to SIA workflow**
   - Preserve compatible local workflow requirements.
   - Replace only the conflicting or unsafe part.
   - Treat current school values such as prices, capacities, limits, and windows as effective-dated configuration unless a binding rule says otherwise.

7. **Update FS baseline**
   - Add role behavior, workflow states, happy path, negative path, business edge case, and access boundary.
   - Keep FS as final-form behavior, not a progress report.

8. **Update TS contract**
   - Add the implementable technical shape: models/tables, services, policies, UI surfaces, integrations, files, jobs, audit, and tests.
   - State package/API choice only when it materially affects the contract.

9. **Update reconciliation matrix**
   - Mark the row as `ALIGNED`, `RETAIN_STRONGER`, `RECONCILE`, `MISSING`, `EXTERNAL_BOUNDARY`, or `BENCHMARK_GATE`.
   - Add UAT impact when the rescue period is active.

10. **Digest into SDD/checklist**
    - Classify as `Core`, `Core-lite`, `Supporting`, `Deferred`, or `External Boundary`.
    - Add or update the exact SDD slice owner.
    - Do not implement broad module rewrites when a narrower vertical slice proves the workflow.

11. **Update UAT/test tracking**
    - Add goal-state manual test cases for the final feature.
    - Add testable-now cases only when the current UI/code can actually be tested.
    - Keep pass/fail factual to the exact build used for presentation.

12. **Update Linear**
    - Mirror material local iteration changes to `TAL-28` or the active slice issue.
    - Do this when a baseline decision, priority, blocker, or completion status changes.

13. **Implement only after the contract is clear**
    - Code changes start after FS/TS/reconciliation agree for the specific slice.
    - Implementation completion requires service/model logic, policy/RBAC, UI surface, focused tests, local checklist update, and Linear update.

---

## Feature-Group Benchmark Queue

Process the complete system by feature group, not by trying to rewrite every document at once.

| Order | Feature group | Primary benchmark need | Immediate priority |
| --- | --- | --- | --- |
| 1 | Identity, login, roles, logout, protected routes, audit | Laravel/Fortify/RBAC/security behavior plus current implemented UI | UAT Core |
| 2 | Admissions, applicant intake, requirement policies, document OCR | Mature SIS admission lifecycle, DepEd/CHED where applicable, Google Vision/Document AI boundary | UAT Core |
| 3 | Enrollment, sectioning, finance clearance, enrolled-student inventory, COR access | Mature SIS enrollment lifecycle, SIA payment/placement workflow, roster/export boundary | UAT Core |
| 4 | Scheduling, section delivery groups, faculty availability, automatic generation | OR-Tools CP-SAT and mature SIS class-readiness patterns | UAT Core/Supporting |
| 5 | Finance, assessment, payments, ledger, SOA, receipts, reconciliation | SIS student financials, PayMongo, accounting controls | UAT Core/Supporting |
| 6 | Faculty class lists, grade encoding, Registrar verification, corrections | SIS student records/gradebook, DepEd grading rules, institutional College profile | UAT Core |
| 7 | Official generated documents: COR, COE, COG, TOR, Form 137, report cards, diploma, QR verification | Student Records benchmarks, DomPDF, QR code, signed/opaque verification URLs, document lifecycle controls | UAT Core for COR; Supporting/Phase 2 for full catalog |
| 8 | Student Hub/PWA read-only visibility | Livewire/TallStackUI/PWA read-only patterns and implemented data services | UAT Core-lite |
| 9 | Student status lifecycle, LOA, transfer-out, readmission, completion/graduation | Mature SIS status/graduation records, local workflow | Supporting/Core boundary |
| 10 | Imports, exports, reports, external boundaries | Laravel Excel, generic roster/report exports, government portal boundary | Supporting/External Boundary |
| 11 | Attendance, behavior, discipline, guidance, interventions | Regulatory/privacy and institution-policy benchmark | Phase 2/Benchmark Gate unless promoted |

---

## Done Criteria For One Feature Group

A feature group is benchmark-hardened when it has:

- Named benchmark source links or local implementation anchors.
- One adopted TALA benchmark rule.
- Explicit SIA workflow fit and external-boundary statement where needed.
- FS behavior that a paper writer and tester can understand.
- TS contract that a developer can implement without guessing.
- Reconciliation classification and SDD owner.
- Goal-state test cases and separate testable-now status.
- Linear/local checklist update when status changes.

---

## Current First-Pass Baseline Decisions

| Feature | Current decision |
| --- | --- |
| External DepEd/CHED/LIS portal work | External boundary. TALA owns accurate student/enrollment data and generic roster/export; it does not implement portal-specific submission automation for UAT. |
| OCR | Google Cloud Vision text extraction plus Registrar review is the current baseline. Document AI Form Parser is a Phase 2 structured extraction option. |
| Automatic scheduling | OR-Tools CP-SAT or equivalent constraint solver is the benchmark. TALA must model hard constraints, soft objectives, timeout/status, conflict reporting, staff review, commit, and publish. |
| Payments | PayMongo is the online payment benchmark; manual Accounting confirmation remains a first-class school-counter channel. |
| Official generated documents | Generated PDFs and QR verification are derived artifacts, not primary records. The source of truth remains enrollment, grade, ledger, student-record, transfer, and completion data. |
| Student Hub/PWA | Student Hub is read-only owner-scoped visibility first. Livewire/TallStackUI pages consume backend services; PWA caches require secure context, explicit cache versioning/purging, freshness labels, and disabled offline mutations. |
| Student status/completion | Mature SIS status and graduation workflows are typed lifecycle events. Use transition history, readmission/provenance review, graduation requirement snapshots, deficiency/clearance checks, completion status, and separate credential/external-processing boundaries. |
| Imports/exports/reports | Laravel Excel is the package primitive, not the domain contract. TALA wraps imports/exports in typed services with private files, templates, previews, explicit commits, field allowlists, audit, and no regulator-portal submission automation. Current runtime proves curriculum import only. |
| Attendance/discipline/guidance | Benchmark-gated deferred domain. No enrollment, clearance, progression, or graduation effect may consume attendance, behavior, discipline, or guidance data until typed evidence, due process, privacy, appeal/review, resolution, and approved policy effects exist. |

---

## Applied Hardening Log

| Date | Feature group | Process steps applied | Result |
| --- | --- | --- | --- |
| 2026-06-21 | System-wide baseline | Inventory, benchmark rule, FS baseline, TS contract, SDD/checklist, Linear | Added FS/TS submission-baseline maps and readiness rules. |
| 2026-06-21 | Official generated documents | Benchmark rule, FS/TS implications, SDD/checklist, Linear | Added PDF/QR/COR/TOR/Form 137/SOA/receipt artifact baseline to the benchmark matrix and trackers. |
| 2026-06-21 | Identity, login, roles, protected routes, audit | Inventory, benchmark rule, FS baseline, TS contract | Added explicit FS identity/access baseline and TS identity/access technical contract using the benchmark matrix authentication/RBAC rule. |
| 2026-06-21 | Admissions, applicant intake, requirement policies, document OCR | Inventory, benchmark rule, FS baseline, TS contract | Added explicit FS admissions acceptance contract and TS admissions/document-review technical baseline using the benchmark matrix admissions/checklist/OCR rules. |
| 2026-06-21 | Enrollment, sectioning, finance clearance, enrolled-student inventory, COR access | Inventory, benchmark rule, FS baseline, TS contract, reconciliation, SDD/checklist | Added atomic handover, tentative-versus-secured placement, generic roster/export, and canonical COR boundaries; corrected tracker ownership to `TAL-28`. |
| 2026-06-21 | Scheduling, delivery groups, faculty availability, automatic generation | Inventory, BM-05/BM-06 rule, FS baseline, TS contract, reconciliation, SDD/checklist | Locked immutable solver snapshots, CP-SAT hard/soft constraints, bounded statuses, diagnostics, review/approve/commit/publish, and controlled override behavior. |
| 2026-06-21 | Finance, assessment, payments, ledger, SOA, receipts, reconciliation | Inventory, BM-01/BM-05/BM-09/BM-13 rule, FS baseline, TS contract, reconciliation, SDD/checklist | Locked effective-dated assessment, manual/PayMongo parity, immutable idempotent ledger posting, computed clearance, correction entries, and derived finance artifacts. |
| 2026-06-21 | Faculty class lists, grades, verification, corrections | Inventory, BM-01/BM-05/SIA-01 rule, FS baseline, TS contract, reconciliation, SDD/checklist | Locked assignment-scoped class lists, grading-profile snapshots, submission/verification/finalization separation, and audited Academic Head-authorized correction. |
| 2026-06-21 | Official generated documents and QR verification | Inventory, BM-13/BM-14/BM-15/BM-22/BM-23 rule, FS baseline, TS contract, reconciliation, SDD/checklist, code/tests | Locked authoritative source records, immutable issuance snapshots, private artifact handling, school-record release evidence, minimal-disclosure verification, and revoke/supersede history. |
| 2026-06-21 | Student Hub/PWA read-only visibility | Inventory, BM-01/BM-02/BM-12/BM-24 rule, FS baseline, TS contract, reconciliation, SDD/checklist, code/tests | Locked owner-scoped service read models, released/published data only, read-only offline boundary, freshness indication, cache clearing, and safe loading/error behavior. Current runtime proves routes/access/help/backend aggregate, while connected pages and advanced PWA cache behavior remain SDD-08B/TAL-13 gaps. |
| 2026-06-21 | Student status, readmission, transfer, completion/graduation | Inventory, BM-01/BM-02/BM-25/SIA-01 rule, FS baseline, TS contract, reconciliation, SDD/checklist, code/tests | Locked typed effective-dated transitions, assisted readmission/provenance review, reproducible graduation evaluation, and external credential/government boundary. Runtime proves only status fields, completed timestamps, generic service requests, and staff-account lifecycle services; dedicated SDD-07D/07E implementation remains missing. |
| 2026-06-21 | Imports, exports, reports, external boundaries | Inventory, BM-01/BM-10/SIA-01 rule, FS baseline, TS contract, reconciliation, SDD/checklist, code/tests | Locked private versioned import batches, preview/commit/audit, export allowlists, artifact lifecycle, and no DepEd/CHED/LIS-specific submission automation. Current `ImportBatch` runtime proves curriculum import only; roster/report exports and other import types remain separate implementation work. |
| 2026-06-21 | Attendance, behavior, discipline, guidance, interventions | Inventory, BM-01/BM-05/BM-26/BM-27/SIA-01 rule, FS baseline, TS contract, reconciliation, SDD/checklist, code/tests | Classified as benchmark-gated/deferred until typed evidence, privacy, notice/appeal, resolution, retention, and approved effect policies exist. Current code has no attendance/guidance/discipline domain; the safe current invariant is no hidden Group 11 block in the core rescue flow. |

---

## Process Control Rule

When time is short, do not broaden implementation. First decide whether the feature is:

`UAT Core -> Core-lite -> Supporting -> Deferred -> External Boundary`

Then implement only the next vertical slice needed to make the applicant-to-completion SIS path truthful and testable.

---

## Submission-Lock Audit Register

| Feature group | Lock-audit status | Evidence and remaining implementation boundary |
| --- | --- | --- |
| 3 - Enrollment, sectioning, finance clearance, inventory, COR | `SPEC_LOCK_READY` (2026-06-21) | Business evidence, BM-01/BM-02/BM-04/BM-10, FS, TS, reconciliation, SDD, code, focused tests, and goal-state UAT rows were cross-checked. Canonical states and atomic handover are approved. Runtime still requires tentative-placement expiry, placement-aware handover, transitional-state/LIS migration, generic roster/export, complete COR issuance/PDF/QR access, and dedicated COR permissions. These are implementation gaps, not unresolved specification decisions. |
| 4 - Scheduling, delivery groups, faculty availability, CP-SAT generation | `SPEC_LOCK_READY` (2026-06-21) | SIA workflow, BM-05/BM-06/BM-11/BM-16/BM-17, FS, TS, reconciliation, SDD, Python solver, Laravel services/jobs, Filament surfaces, focused tests, and UAT rows were cross-checked. Immutable snapshots, hard/soft boundaries, normalized outcomes, review/commit/publish authority, and controlled revision are approved. Runtime gaps remain for `model_invalid` timeout classification and applying an approved change after publication. |
| 5 - Finance, assessment, payments, ledger, SOA, receipts, reconciliation | `SPEC_LOCK_READY` (2026-06-21) | SIA Accounting workflow, BM-01/BM-05/BM-09/BM-13/BM-18/BM-19, FS, TS, reconciliation, SDD, services/webhooks/jobs/resources/tests, and UAT rows were cross-checked. Versioned assessment evidence, channel-parity posting, immutable ledger authority, computed clearance, issued artifacts, duty segregation, and daily reconciliation are approved. Runtime gaps remain assigned to SDD-06E/06F and the handover timestamp correction. |
| 6 - Faculty class lists, grade profiles, submission, Registrar verification, finalization, corrections | `SPEC_LOCK_READY` (2026-06-21) | SIA end-of-term audit workflow, BM-01/BM-05/BM-20/BM-21, FS, TS, reconciliation, SDD, grading/class-list/correction services, Filament surfaces, focused tests, and UAT rows were cross-checked. Published-assignment scope, profile snapshots, immutable submission packages, Registrar verify/return/finalize authority, verified-only release, and append-only correction evidence are approved. Runtime still lacks grading-profile/package schema and Registrar verification, conflates Faculty submission with finalization, exposes a finance badge to Faculty, and retains a client-unapproved hardcoded College profile. |
| 7 - Official generated documents, PDF artifacts, and QR verification | `REBASELINED` (2026-06-21) | Authoritative-source issuance, private artifacts, source snapshots/checksums, opaque minimal-disclosure verification, and revoke/supersede history remain approved. The document-request portal/catalog/fee/fulfillment/pickup/courier/shipping domain is removed. COR, finance, academic, transfer, and completion artifacts remain owned by their source workflows. |
| 8 - Student Hub/PWA read-only visibility | `SPEC_LOCK_READY` (2026-06-21) | BM-01/BM-02/BM-12/BM-24, FS, TS, reconciliation, SDD, routes, middleware, Student layout PWA directives, `StudentDashboardService`, Student Hub access tests, dashboard service tests, and STU UAT rows were cross-checked. Active-student owner-scoped visibility, service-backed view models, published/released data only, and read-only PWA boundaries are approved. Runtime still lacks service-backed Student Hub pages beyond Help/FAQ, student mutation forms, protected cache/freshness labels, clear-on-logout/account-denial cache handling, and browser/device PWA acceptance proof. |
| 9 - Student status, readmission, transfer, completion, and graduation | `SPEC_LOCK_READY` (2026-06-21) | BM-01/BM-02/BM-15/BM-25, SIA workflow, FS, TS, reconciliation, SDD, `StudentProfile`, `Enrollment`, generic `ServiceRequestLifecycleService`, staff-only `UserAccountLifecycleService`, and current UAT rows were cross-checked. Typed student lifecycle transitions, readmission/provenance review, graduation-evaluation snapshot, deficiency/clearance proof, completion status, credential-release separation, and external CHED/SO boundary are approved. Runtime still lacks dedicated student-status transition tables/services, graduation application/evaluation, deficiency resolution, completion approval, and credential-release readiness. |
| 10 - Imports, exports, reports, and external boundaries | `SPEC_LOCK_READY` (2026-06-21) | BM-01/BM-10/SIA-01, FS, TS, reconciliation, SDD, `ImportBatch`, `CurriculumImportTemplate`, `CurriculumImportService`, `ImportBatchLifecycleService`, `ImportBatchResource`, and import-focused tests were cross-checked. Private versioned import batches, strict validation preview, explicit commit/cancel, activity audit, export allowlists, generic artifact handling, and external DepEd/CHED/LIS boundary are approved. Runtime currently implements curriculum import only; enrolled-roster export, broader report/export services, export artifact lifecycle, and non-curriculum imports remain implementation gaps. |
| 11 - Attendance, behavior, discipline, guidance, and interventions | `SPEC_LOCK_READY` (2026-06-21) | BM-01/BM-05/BM-26/BM-27, SIA workflow, FS, TS, reconciliation, SDD, code search, and focused no-hidden-gate tests were cross-checked. Attendance, guidance, behavior, discipline, and interventions are approved only as future typed evidence/case workflows with privacy, notice/response, appeal/review, resolution, and explicit policy effects. Runtime has no domain source and must not silently enforce Group 11 blocks in enrollment, progression, completion, or release. |
