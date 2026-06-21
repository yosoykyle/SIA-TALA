# TALA Local Iteration Checklist (DB-First)

**Location Purpose:** Local execution checklist aligned with the 3 main specs and Linear roadmap.
**Last Updated:** 2026-06-21
**Linear Project:** TALA Iterative Implementation Map (DB-First)

---

## Scope Lock (Approved)

- Current active scope is the **2026-06-21 UAT rescue baseline**: finish the smallest working SIS core before expanding again.
- Late 2026-06-21 scope correction: the active deployment is **College-only**. SHS is removed from active workflows and is retained only as archived historical evidence or as Grade 12/Form 138/Form 137 prior-credential evidence for College admission.
- Checklist entries completed before the 2026-06-21 College-only decision remain historical progress evidence when dated. They are superseded for active implementation wherever they instruct SHS offerings, SHS grading, SHS fee/schedule behavior, SHS UAT paths, or SHS/College branching.
- Late 2026-06-21 scope removal: the TALA document-request portal/catalog/fee/fulfillment/pickup/courier/shipping domain is permanently removed, not deferred. Earlier dated checklist entries remain historical evidence only and must not restore its models, schema, permissions, UI, jobs, tests, or UAT cases.
- Core rescue flow: login and role access -> applicant intake -> admission review/documents -> enrollment/section/finance clearance -> student record -> faculty class/grade operation -> student read-only view -> completion/graduation boundary.
- Shared student-domain backend logic required by this flow is **not deferred**. This includes applicant intake, student profiles, enrollments, subject suggestion, assessment/payment clearance, document review state, class-list visibility, grades, student dashboard aggregation, and calendar/capacity gates.
- Student Hub is **Core-lite** for UAT: authenticated read-only visibility matters; advanced PWA polish, offline mutation, and student write-actions remain deferred unless explicitly promoted.
- Advanced promissory, refund, external DepEd/CHED/LIS submission, analytics polish, and non-demo UI enhancements are deferred unless the rescue tracker promotes them.
- Linear `TAL-28` must be updated when this checklist or the rescue baseline changes materially.

---

## Spec-First Gate (Mandatory)

- Before starting any iteration task, read `TALA-Functional-Specification.md` and `TALA-Technical-Specification.md` first.
- If Functional and Technical specs conflict, pause implementation and resolve the conflict in docs before coding.
- Do not mark any checklist item done if the implemented behavior is not traceable to FS/TS sections.
- Backend/service completion alone does not make a staff module complete. A staff-facing module is admin-ready only when the required Filament Resource/Page/Action exists, role access is enforced, and the panel action calls the tested backend service.

---

## SDD Execution Pivot (Rebaselined 2026-06-21)

- `TALA-SDD-Execution-Map.md` is now the active execution map for the next backend/Admin SDD pass.
- The 2026-06-17 scheduling/curriculum grilling decisions are locked unless a specific decision is reopened by the user/client.
- Work is targeted as one dependency-ranked micro-sprint at a time: business evidence, FS/TS contract, mature-system benchmark where needed, code/test audit, backend or integration implementation, required Filament/Student Hub connection, focused verification, human gate, commit, then local checklist and Linear sync.
- Older refinement lists are historical unless a specific item is mapped into the active SDD execution map.
- Current execution order is: SDD-00D permanent scope removal -> FS/TS-to-code completion audit -> dependency-ranked P0 backend/integration micro-sprints -> required Admin and Student Hub connections -> Pre-UAT QA. Historical SDD numbering does not decide priority.
- TAL-13 backend contracts remain active before UAT: `ApplicantIntakeService`, `StudentEnrollmentService`, `SubjectSuggestionService`, and `StudentDashboardService` are implemented; Student Hub UI remains deferred.
- TAL-13 Student Hub UI remains deferred. Do not spend implementation time on Student Hub presentation until the backend contracts above are stable or explicitly descoped.
- Scheduling availability cadence is term-scoped, not whole-academic-year-scoped. SDD-00C supersedes prior SHS cadence rules for the active deployment; only College terms remain active.
- Filament feasibility confirmed: the planned admin UI uses Filament v5 resources, forms, tables, filters, relation managers, infolists, and action modals. Business rules must stay in Laravel services/actions; Filament resources call those services.

### SDD-00D Foundation Rebaseline and Completion Audit

- [x] Record permanent document-request removal in business evidence, reconciliation, FS, TS, and SDD execution control.
- [x] Remove document-request models, table, factory, policy, lifecycle service, Filament resource, Student Hub route/page/navigation/data, permissions, scheduler job, seed fixtures, ledger options, and dedicated tests.
- [x] Replace unrelated permission reuse with `submit-service-requests`, `manage-service-requests`, and existing admission-document permissions.
- [x] Remove document-request UAT rows and references from all active readiness artifacts while preserving admission-document and generated-artifact coverage.
- [x] Run focused PHPUnit, migration, route, schedule, permission, and repository-reference verification; do not run the destructive table-drop migration against a database containing request data without backup/approval.
  - 2026-06-21 SDD-00D removal verification: 44 focused tests passed after one stale Pre-UAT FAQ assertion was corrected; `DocumentRequestScopeRemovalTest` confirms the dropped schema under `RefreshDatabase`; route and scheduler scans show no document-request surface; runtime scans show no positive references outside immutable migration history, the forward removal migration, and negative absence tests; `migrate --pretend --path=database/migrations/2026_06_21_093258_drop_document_requests_table.php` emits only the two permission renames and table drop. The working database has zero request rows, but the migration remains pending so an unrelated pending College-only migration is not applied implicitly.
  - 2026-06-21 Linear mirror: created urgent in-progress `TAL-30` (`SDD-00D - Foundation rebaseline and completion audit`) under `TAL-12`; `TAL-30` blocks `TAL-28`. Added supersession/blocker comments to `TAL-28` (`f1c021aa-23c3-4562-bf18-216fa401521b`) and `TAL-12` (`dedfcd08-6a04-4894-b9d4-82b050cf840f`).
- [ ] Audit every FS/TS baseline area against models, migrations, services, policies, UI surfaces, integrations, and tests; classify `PROVEN`, `PARTIAL`, `MISSING`, `EXTERNAL`, `REMOVED`, or `DEFERRED`.
- [ ] Rank remaining work by dependency and two-day priority: P0 SIS foundation/integrations, P1 supporting workflows, P2 quality-of-life.
- [ ] Publish completion counts, critical-path blockers, and the next micro-sprint in this checklist and Linear before resuming SDD-07A or another feature slice.

### UAT Rescue Alignment - 2026-06-21

- [x] Created active rescue tracker: `TALA-UAT-Rescue-Plan-2026-06-21.md`.
- [x] Linked the rescue tracker from `00_Project_Documents/README.md`.
- [x] Created benchmark baseline matrix: `TALA-SIS-Benchmark-Baseline-Matrix.md`.
- [x] Created repeatable benchmark/spec-hardening process: `TALA-Specification-Benchmarking-Process.md`.
- [x] Added official generated document, PDF, QR verification, COR, TOR, Form 137, SOA, and receipt benchmark coverage to the baseline matrix.
- [x] Reframed FS/TS as goal-state baseline documents, not implementation-complete proof.
- [x] Added FS/TS submission-baseline alignment sections so the final-form SIS spine, module baseline, technical contract map, and readiness rules are explicit before module-level details.
- [x] Corrected baseline contradictions found during the FS/TS audit: Registrar owns versioned admission-requirement setup in the final form; generic settings JSON is transitional only; unresolved "Needs Clarification" wording was converted to controlled optional contracts.
- [x] Applied the benchmarking process to Feature Group 1: identity/login/roles/logout/protected routes/audit. FS §3.3 and TS §4.1 now define the final-form identity/access baseline, and `TALA-Specification-Benchmarking-Process.md` records the applied hardening log.
- [x] Applied the benchmarking process to Feature Group 2: admissions/applicant intake/requirement policies/document OCR. FS now has an admissions baseline acceptance contract; TS now has an admissions/document-review technical baseline covering published offering resolution, checklist snapshots, evidence channels, OCR/manual review, gate computation, and retention undertakings.
- [x] Applied the benchmarking process to Feature Group 3: enrollment/sectioning/finance clearance/enrolled inventory/COR, including atomic handover, secured capacity, generic roster export, and external portal boundary.
- [x] Applied the benchmarking process to Feature Group 4: scheduling/delivery groups/faculty availability/CP-SAT generation, including immutable inputs, hard/soft constraints, bounded solver outcomes, diagnostics, and publish controls.
- [x] Applied the benchmarking process to Feature Group 5: finance/assessment/payments/ledger/SOA/receipts/reconciliation, including channel parity, immutable posting, computed clearance, and derived artifacts.
- [x] Applied the benchmarking process to Feature Group 6: faculty class lists/grades/Registrar verification/corrections, including assignment scoping, grading-profile snapshots, lifecycle separation, and correction evidence.
- [x] Applied the benchmarking process to Feature Group 7: official generated documents and QR verification, including issuance snapshots, private artifacts, minimal disclosure, and revoke/supersede handling.
- [x] Applied the benchmarking process to Feature Group 8: Student Hub/PWA read-only visibility, including owner-scoped read models, published/released data, offline mutation denial, freshness, and cache protection.
- [x] Applied the benchmarking process to Feature Group 9: student status/readmission/transfer/completion/graduation, including typed transitions, provenance review, graduation snapshots, and external processing boundaries.
- [x] Applied the benchmarking process to Feature Group 10: controlled imports/exports/reports/external boundaries, including versioned templates, private batches, preview/commit/audit, field allowlists, and generic exports.
- [x] Applied the benchmarking process to Feature Group 11: attendance/behavior/discipline/guidance/interventions; retained as benchmark-gated/deferred until typed evidence, privacy, notice/appeal, resolution, and approved effects exist.
- [x] Completed the Feature Group 3 submission-lock audit across business evidence, BM-01/BM-02/BM-04/BM-10, FS/TS, reconciliation, SDD, current services/migrations/Filament surfaces/tests, and goal-state UAT rows. Specification is lock-ready; runtime gaps remain explicitly assigned to SDD-07A.
- [x] Completed the Feature Group 4 submission-lock audit across SIA scheduling evidence, BM-05/BM-06/BM-11/BM-16/BM-17, FS/TS, reconciliation, SDD, Python CP-SAT runtime, Laravel services/jobs, Filament surfaces/tests, and goal-state UAT rows. Specification is lock-ready; runtime corrections remain for `model_invalid` timeout semantics and approved post-publication Apply.
- [x] Completed the Feature Group 5 submission-lock audit across SIA Accounting evidence, BM-01/BM-05/BM-09/BM-13/BM-18/BM-19, FS/TS, reconciliation, SDD, assessment/payment/PayMongo/ledger/adjustment services, resources/tests, and goal-state UAT rows. Specification is lock-ready; SDD-06E/06F retain versioned assessment, OR/SOA issuance, duty segregation, daily reconciliation, reminders, and refund execution, while SDD-07A owns removal of assessment-written `enrolled_at`.
- [x] Completed the Feature Group 6 submission-lock audit across the SIA grade-audit workflow, BM-01/BM-05/BM-20/BM-21, FS/TS, reconciliation, SDD, class-list/grading/finalization/correction services, Filament surfaces/tests, and UAT rows. Specification is lock-ready; SDD-08A retains profile/package schema, Registrar verify/return/finalize, verified-only release, Faculty finance-badge removal, and institution approval of the active College profile before any runtime or historical migration.
- [x] Rebaselined Feature Group 7 after the permanent scope decision: generated COR/academic/finance/completion artifacts remain owned by their authoritative workflows, while the document-request portal/catalog/fee/fulfillment/pickup/courier/shipping domain is removed under SDD-00D. SDD-07B is retired.
- [x] Completed the Feature Group 8 submission-lock audit across Student Hub/PWA visibility, BM-01/BM-02/BM-12/BM-24, FS/TS, reconciliation, SDD, Student Hub routes/middleware/layout, `StudentDashboardService`, access/dashboard service tests, and STU UAT rows. Specification is lock-ready; SDD-08B/TAL-13 retain connected service-backed Student Hub pages, no-sample UI, mutation forms only when promoted, protected PWA cache/freshness/clearance behavior, and browser/device PWA acceptance proof.
- [x] Completed the Feature Group 9 submission-lock audit across student status/readmission/transfer/completion/graduation, BM-01/BM-02/BM-15/BM-25, FS/TS, reconciliation, SDD, `StudentProfile`, `Enrollment`, generic service requests, staff account lifecycle separation, and UAT rows. Specification is lock-ready; SDD-07D retains typed academic status transitions and SDD-07E retains graduation evaluation/completion/credential readiness. Runtime does not yet have dedicated student-status or graduation services.
- [x] Completed the Feature Group 10 submission-lock audit across controlled imports/exports/reports/external boundaries, BM-01/BM-10/SIA-01, FS/TS, reconciliation, SDD, `ImportBatch`, `CurriculumImportTemplate`, `CurriculumImportService`, `ImportBatchLifecycleService`, `ImportBatchResource`, and import-focused tests. Specification is lock-ready; current runtime implements controlled curriculum import only. SDD-07A retains enrolled-roster export and SDD-09 retains broader report/export artifact checks, while DepEd/CHED/LIS submission automation remains external.
- [x] Completed the Feature Group 11 submission-lock audit across attendance/behavior/discipline/guidance/interventions, BM-01/BM-05/BM-26/BM-27/SIA-01, FS/TS, reconciliation, SDD, code search, and focused no-hidden-gate tests. Specification is lock-ready; SDD-08B retains the future typed case/evidence workflows, and current runtime must not silently enforce attendance/discipline/guidance blocks.
- [x] Added SDD-00C College-only scope correction as a blocking slice before further SDD-07A implementation.
- [x] Added SDD-00D Foundation Rebaseline and Completion Audit as the blocking slice before any feature-number-driven continuation.
- [x] Aligned FS/TS, capability map, master test cases, and active code/schema cleanup scope with the College-only business baseline.
- [x] Adopted benchmark/default SIS lifecycle as the scope filter: admission, enrollment, records, scheduling/classes, grades, finance, role access, and completion boundary.
- [x] Added rescue overlay to the SDD execution map.
- [x] Added rescue overlay to the reconciliation matrix.
- [x] Added rescue execution split to the master system test cases.
- [x] Updated Linear `TAL-28` with the rescue pivot and current Day 1 documentation status.
  - 2026-06-21 Linear mirror: posted benchmarking-process and generated-documents/QR/PDF baseline update to `TAL-28` as comment `9aa8fffc-440d-4262-a303-25c85d402dcb`.
  - 2026-06-21 Linear mirror: posted FS/TS submission-baseline hardening update to `TAL-28` as comment `d17ccecf-a285-4fc6-af04-ed167b73805e`.
  - 2026-06-21 Linear mirror: posted Feature Group 1 identity/access baseline-hardening update to `TAL-28` as comment `802fd6e6-5ddb-410a-b68e-de26f96487c3`.
  - 2026-06-21 Linear mirror: posted Feature Group 2 admissions/applicant-intake/document-OCR baseline-hardening update to `TAL-28` as comment `63631dd7-6dee-4e77-a5a0-1342bb1fb192`.
  - 2026-06-21 Linear mirror: posted the Feature Group 4 scheduling submission-lock audit, verification result, and two open runtime corrections to `TAL-28` as comment `f5bcce0e-9f65-4f57-9a3b-567dff815025`.
  - 2026-06-21 Linear mirror: posted the Feature Group 5 finance submission-lock audit, 62-test verification result, corrected UAT boundaries, and SDD-06E/06F runtime gaps to `TAL-28` as comment `cab07d02-c0cb-489e-9332-51b876d4b1bb`.
  - 2026-06-21 Linear mirror restored: posted the Feature Group 6 faculty class-list/grading submission-lock audit, 30-test verification result, locked verification/finalization boundary, and SDD-08A runtime gaps to `TAL-28` as comment `f11af062-e299-424c-a775-865fb3e1ce7d`.
  - 2026-06-21 Linear mirror: posted the Feature Group 7 official-document/COR/document-request/PDF/QR submission-lock audit, 11-test verification result, locked issuance/verification/release-evidence boundary, and SDD-07A/07B/06E/07E runtime gaps to `TAL-28` as comment `4cbd4dc6-28e4-4175-8425-95bffe48faed`.
  - 2026-06-21 Linear mirror recovered: backfilled the Feature Group 8 Student Hub/PWA submission-lock audit, 11-test verification result, and SDD-08B/TAL-13 runtime gaps to `TAL-28` as comment `266fcd51-ef48-4616-8eeb-1c64223f1ebe`.
  - 2026-06-21 Linear mirror: posted the Feature Group 9 student-status/readmission/transfer/completion/graduation submission-lock audit, 19-test verification result, locked typed-lifecycle/graduation-evaluation boundary, and SDD-07D/07E runtime gaps to `TAL-28` as comment `1f55395a-8e86-4983-b1d8-958655caa7c5`.
  - 2026-06-21 Linear mirror: posted the Feature Group 10 controlled import/export/report/external-boundary submission-lock audit, 27-test verification result, locked curriculum-import-only runtime evidence, and SDD-07A/SDD-09 export/report gaps to `TAL-28` as comment `2e0e4242-9723-476f-8606-11167757233c`.
  - 2026-06-21 Linear mirror: posted the Feature Group 11 attendance/behavior/discipline/guidance/intervention submission-lock audit, 21-test verification result, benchmark-gated/deferred status, and no-hidden-gate invariant to `TAL-28` as comment `dc142b40-6ab1-402a-b9b1-54be373aebf7`.
- [ ] After the docs are coherent, commit or tag the rescue baseline before UAT execution.

### Previous Grilling/Iteration Reconciliation - 2026-06-17

- [x] Previous open-ended refinement/grilling outputs are reconciled as historical evidence, not an implementation queue ahead of SDD work.
- [x] Completed Iterations 1-8 remain below as progress evidence for prior DB-first, service, integration, admin, and hardening waves.
- [x] Still-valid scheduling/curriculum decisions are absorbed into `TAL-20`, `TAL-21`, and `TAL-22`.
- [x] Optional/deferred admin surfaces remain mapped to `TAL-15` unless stakeholders explicitly pull them into Pre-UAT.
- [x] Student Hub UI/PWA presentation remains deferred to `TAL-13`; shared student-domain backend contracts remain active pre-UAT dependencies.
- [x] Stale FAQ-removal and student-self-service-contract deferral wording in UAT readiness artifacts must be treated as superseded by the 2026-06-17 FS/TS/SDD decision closure.
- [x] Before starting SDD-04, verified the current Admin/System verification slice was not already covered by an existing current SDD child issue. Existing Linear `TAL-16` through `TAL-19` used legacy `SDD-04A-D` student-backend numbering and are now treated as current SDD-05 TAL-13 backend contract work.
- [ ] Before starting each later SDD slice, verify the target slice is not already covered by an existing SDD child issue and that the previous-slice blockers are either complete or intentionally accepted.

### SDD-04 Admin/System Foundation Verification Evidence - 2026-06-17

- [x] Linear `TAL-23` created for current `SDD-04 - Admin/System Foundation Verification`.
- [x] Staff account lifecycle verified against FS §8.3 and TS §3.2: `UserResource` uses split staff-name fields, staff role choices, active/inactive direct status only, one-role-only selection, and service-backed Archive/Restore account actions.
- [x] `UserAccountLifecycleService` verified as the lifecycle authority: target row locking, System Super Admin authorization, archive reason validation, role clearing on archive, exactly one approved role on restore, and activity logging remain intact.
- [x] RBAC matrix verified: `RoleResource` remains list-only with no create/edit routes or actions; role mutation remains release-controlled through seeders/code, not ad hoc admin UI.
- [x] Audit surface verified: `ActivityResource` remains list/view only, policy-gated by `view-audit-logs`, and activity metadata is rendered as readable evidence lines through `ActivityPropertiesFormatter`.
- [x] FAQ maintenance verified: `FaqEntryResource` keeps System Super Admin CRUD through `manage-faqs`; public `/faq` and Student Hub Help read only published FAQ rows.
- [x] System Settings boundary verified: generic `SystemSettingResource` remains hidden, has no create/edit route or raw form/action, and `SystemSettingPolicy` denies all abilities including direct `/admin/system-settings`.
- [x] Focused admin/system tests passed: `php artisan test --compact tests/Feature/UserAccountLifecycleServiceTest.php tests/Feature/TAL12ASystemSuperAdminFilamentResourceTest.php tests/Feature/TAL10RbacMatrixTest.php tests/Feature/PublicFaqPageTest.php tests/Feature/StudentHubAccessTest.php` -> 24 passed / 227 assertions.
- [x] Direct internal route denial test passed: `php artisan test --compact tests/Feature/PreUatInternalRouteDenialTest.php` -> 2 passed / 4 assertions.

### Locked Scheduling/Curriculum Decisions - 2026-06-17

- [x] FAQ admin CRUD remains restored for System Super Admin through `manage-faqs`; public and Student Hub FAQ views read only published rows.
- [x] Delivery Patterns are reusable, versioned, and frozen once used; changes require clone/new version.
- [x] `Section` is the academic grouping and shared subject set. `Section Delivery Group` is the modality/delivery setup subset inside a section.
- [x] One section may contain students with different delivery setups when they take the same subjects.
- [x] Enrollment assignment stores both `section_id` and `section_delivery_group_id` once assigned.
- [x] `schedule_draft_rows` and `section_meetings` must target `section_delivery_group_id` while retaining `section_id` for reporting.
- [x] Capacity is enforced at both section and delivery-group levels. Full capacity blocks assignment and requires Registrar action; no auto-overflow/PIN flow for MVP.
- [x] Student modality capture is staff-assisted first; Registrar/enrollment staff records declared preference and Registrar confirms final section/delivery-group assignment.
- [x] The system may rank compatible delivery groups, but Registrar confirms final assignment.
- [x] Modular print has teacher/adviser ownership but no recurring class meeting in MVP; module pickup/submission logistics stay outside TALA.
- [x] Pure online MVP is scheduled/synchronous; asynchronous online remains future extension.
- [x] Curriculum import target is a College template with `Weekly Contact Hours`, `Academic Subject Type`, `Scheduling Group`, and `Delivery Rule Override`.
- [x] Partial curriculum imports are allowed by College program/curriculum/year/period scope; missing College curriculum blocks only the affected College scheduling scope.
- [x] Empty or zero-valid-row curriculum imports are not commit-ready.
- [x] Curriculum scopes require explicit `ready_for_scheduling` before scheduling can consume them; section planning may reference `needs_review` scopes only with warnings/blockers surfaced.
- [x] Existing curriculum rows remain but become `needs_review` for scheduling until the new fields are confirmed.
- [x] SDD-01 grilling closure: readiness is an explicit `curriculum_readiness_scopes` state keyed by `curriculum_id + year_level + curriculum_period`; program is derived through curriculum.
- [x] SDD-01 grilling closure: scheduler-facing offering fields live on `curriculum_subjects`, not `subjects`: `weekly_contact_hours`, `academic_subject_type`, `scheduling_group`, and constrained nullable `delivery_rule_override`.
- [x] SDD-01 grilling closure: strict import header uses `Education Level`; the database may keep legacy `department` internally for MVP, but legacy `Department` template headers fail validation.
- [x] SDD-01 grilling closure: preview may create audit evidence for zero-valid-row files, but commit requires `error_rows = 0` and `valid_rows > 0`.
- [x] SDD-01 grilling closure: imports require explicit classification values for MVP; no silent auto-fill from GE/TESDA/NC/title patterns.
- [x] SDD-01 grilling closure: readiness statuses are `needs_review`, `ready_for_scheduling`, and service-derived `blocked`; current state lives on the scope row and transitions write to `activity_log`.
- [x] SDD-01 grilling closure: Registrar owns curriculum data entry/import/editing; Academic Head may review blockers and transition readiness; System Super Admin is outside the normal academic readiness path.
- [x] SDD-01 grilling closure: section planning may reference a `needs_review` scope with warnings, but schedule generation and solver snapshots remain blocked until `ready_for_scheduling`.
- [x] SDD-01 grilling closure: scheduler-facing edits or imports touching a ready scope reset it to `needs_review`; readiness does not hard-lock curriculum rows.
- [x] SDD-01 grilling closure: `exclude_from_auto_schedule` stays in curriculum coverage but is omitted from automatic solver demand; all-excluded scopes need an explicit reviewer reason to be ready.
- [x] SDD-01 grilling closure: SDD-01 updates Laravel snapshot tests for readiness evidence and `weekly_contact_hours`; Cloud Run solver redeploy remains SDD-03 unless solver runtime code changes.
- [x] Schedule lifecycle is `draft generated` -> `reviewed` -> `committed official` -> `published` -> `revision requested/applied`.
- [x] Registrar prepares/reviews/commits; Academic Head publishes; System Super Admin emergency publish requires reason and audit evidence.
- [x] Faculty workload overload is a soft override requiring Academic Head approval and audit payload. Hard conflicts remain non-overridable.

---

## Pre-TAL-12 Rescue Execution Track (Approved 2026-06-07)

**Purpose:** Rapidly develop the smallest viable working SIS path while protecting the approved rescue boundaries.

**Approved Descope / Freeze**

- [x] Full Student Hub UI self-service remains outside TAL-12/TAL-12A except authenticated access and published FAQ consumption. Backend service contracts needed for Student Hub data are now active in the SDD execution map before UAT.
- [x] Student-facing promissory UI remains outside TAL-12, but SDD-06C now implements the applicant/student-owner backend request contract, Accounting staff-assisted pending queue, review actions, deadline processing, and payment-driven settlement.
- [x] Generic System Settings editor remains frozen; `system_settings` stays an internal runtime registry.
- [x] Advanced shipping automation remains outside rescue; manual Registrar fulfillment and hardened receipt evidence may remain.
- [x] Vertex AI is not the TAL-12 scheduler.

**Approved Rescue Build Targets**

- [x] Add/approve minimal `faculty_subject_eligibilities` contract before automatic scheduling implementation.
- [x] Confirm section-to-year-level/term mapping for solver input; add a small explicit contract if naming/import convention is not sufficient.
- [x] Confirm minimal room catalog input for solver; defer full room-management module unless explicitly approved.
- [x] Clarify modality scheduling rule: every modality, including Modular, requires an assigned faculty teacher/adviser path for committable schedules.
- [x] Clarify section capacity rule: `max_seats` is editable but bounded to a rescue hard maximum of 30 heads and cannot be lowered below current enrollment.
- [x] Enforce mandatory faculty assignment and bounded section capacity in scheduling code/tests before solver result ingestion is marked complete.
- [x] Implement automatic scheduling as an IAM-private Google Cloud Run service running OR-Tools CP-SAT.
- [x] Generate immutable Laravel input snapshot before solver dispatch.
- [x] Dispatch solver through queue job after database commit using a Google ID-token authenticated request to the private Cloud Run service when the `cloud_run` driver is enabled.
- [x] Ingest solver output into `schedule_draft_rows` with `ok`, `warning`, or `conflict` status.
- [x] Validate all solver rows in Laravel before commit: curriculum, section demand, faculty eligibility, availability, room, modality, time, and existing commitments.
- [x] Added stricter pre-dispatch faculty-input readiness: `TermSchedulingReadinessService` blocks automatic generation when any section-subject demand has zero schedulable faculty, where schedulable means active faculty-subject eligibility plus submitted/locked availability with at least one availability window for the target term.
- [x] Added manual official-schedule assignment availability override: `SectionMeetingAssignmentService` still blocks ineligible faculty and hard section/faculty/room/time conflicts, but missing/outside submitted availability may proceed only with a required Registrar reason captured on `section_meetings` with actor, timestamp, and evidence payload.
- [x] SDD-00C supersedes SHS availability cadence for active deployment; only College term-scoped faculty availability remains active.
- [x] Added typed Academic Year setup interface for MVP calendar operation: `AcademicYear` model/factory/policy plus `AcademicYearResource` create/view/edit, no delete or bulk-delete, College parent calendar rows, and `TermResource` required relationship-backed Academic Year selection.
- [x] Enforce `>98%` auto-assignment coverage for feasible inputs as the solver target.
- [x] Enforce `100%` hard-constraint validity for committed `section_meetings`.
- [x] Keep `ScheduleCommitService` as final authority for creating `section_meetings`, synchronizing `section_teacher`, and recording activity.
- [x] Preserve PayMongo / GCash as required external payment infrastructure with live sandbox webhook-confirmed evidence and idempotent ledger posting.
- [x] Preserve controlled curriculum/foundation migration as strict template -> upload -> preview/validation -> commit -> audit, with no in-browser row repair/freeform spreadsheet import. Student, grade, financial, and enrollment legacy import services remain separate explicit work if required.

**Linear Mirror Note**

- When Linear is updated later, mirror this execution track into the appropriate TAL-12/TAL-12A items or new follow-up issues.
- Do not create one monolithic "rescue plan" issue that consumes the full 1.5 weeks; split implementation into small verifiable slices: eligibility contract, solver input snapshot, IAM-private Cloud Run solver, result ingestion, Laravel validation, commit hardening, and Pre-UAT retest.

**Slice 1 Evidence - Faculty Subject Eligibility**

- [x] Added `faculty_subject_eligibilities` table contract with faculty, subject, optional term scope, active/inactive status, priority, max weekly hours, approval metadata, and uniqueness guard.
- [x] Added `FacultySubjectEligibility` model relationships and active eligibility lookup used by scheduling.
- [x] Added policy boundary: Registrar/Academic Head/System Super Admin permission can manage; Faculty can view only their own eligibility and cannot self-assign.
- [x] Added minimal Filament admin resource for maintaining faculty-subject eligibility records.
- [x] Updated manual schedule assignment and schedule commit path to reject faculty-subject assignments without active eligibility.
- [x] Verified with focused tests: `FacultySubjectEligibilityTest`, `SectionMeetingAssignmentServiceTest`, and `ScheduleCommitServiceTest`.

**Slice 2 Evidence - Scheduling Readiness / Schema Audit**

- [x] Audited FS/TS scheduling source-of-truth layers against current database fields.
- [x] Confirmed section naming/import convention is not sufficient for automatic scheduling demand; added explicit `sections.curriculum_id`, `sections.year_level`, and `sections.curriculum_period`.
- [x] Confirmed rescue room catalog should stay minimal; `sections.room` is the fixed-room/minimal room input and a normalized room-management table remains deferred.
- [x] Added `Curriculum` and `CurriculumSubject` models/factories so solver demand can resolve through Eloquent instead of raw table guesses.
- [x] Added `TermSchedulingReadinessService` to report missing term fields, section solver-scope issues, missing curriculum demand, and room catalog mode before snapshot generation.
- [x] Verified with focused test: `SchedulingReadinessContractTest`.

**Slice 3 Evidence - IAM-Private Cloud Run Auth Plumbing**

- [x] Confirmed local solver config values load from `.env` through `tala_integrations.scheduling_solver`.
- [x] Added `CloudRunIdTokenProvider` and service-account-backed Google ID-token provider for IAM-private Cloud Run.
- [x] Added `CloudRunSchedulingSolverClient` and `LocalStubSchedulingSolverClient`; default driver remains `local_stub` until the real solver dispatch path is enabled.
- [x] Verified authenticated placeholder Cloud Run probe returns HTTP 200 using the configured invoker service-account key.
- [x] Verified unauthenticated placeholder Cloud Run request returns HTTP 403, proving Cloud Run IAM is blocking anonymous callers.
- [x] Verified with focused test: `SchedulingCloudRunSolverClientTest`.

**Slice 4 Evidence - Immutable Solver Input Snapshot**

- [x] Added `solver_input_snapshot`, `solver_input_hash`, and `solver_snapshot_captured_at` to `schedule_generation_runs`.
- [x] Added `ScheduleSolverSnapshotService` to capture run metadata, section/curriculum demand, faculty eligibility, submitted/locked availability, fixed-room catalog, existing commitments, readiness, and policy constraints.
- [x] Enforced immutability: once a run has a stored snapshot, later calls return the original snapshot instead of rebuilding from changed source records.
- [x] Enforced readiness: unready terms cannot capture a solver snapshot.
- [x] Verified with focused test: `ScheduleSolverSnapshotServiceTest`.

**Slice 5 Evidence - After-Commit Solver Dispatch**

- [x] Added `ScheduleGenerationService` to create draft schedule runs, capture immutable solver snapshots, and dispatch solver work after database commit.
- [x] Added `ScheduleSolverDispatchJob` on the `scheduling` queue with retry/backoff settings, configured solver-client invocation, success summary recording, and failure summary recording before retry/fail.
- [x] Preserved the current rescue-safe default `TALA_SCHEDULING_SOLVER_DRIVER=local_stub`; no additional Google Cloud Console action is required until the real Python OR-Tools `/solve` service replaces the placeholder container.
- [x] Verified with focused tests: `ScheduleGenerationServiceTest` and `ScheduleSolverDispatchJobTest`.

**Scheduling Constraint Clarification - 2026-06-08**

- [x] Updated FS/TS planning contract so Modular, Online, and On-site all require faculty teacher/adviser ownership for committable schedule rows.
- [x] Updated FS/TS planning contract so section `max_seats` remains editable but cannot exceed 30 heads or drop below `enrolled_count`.
- [x] Code follow-up completed before solver-ingestion slice: make `faculty_id` mandatory for `ok` draft rows/committed meetings and enforce section capacity bounds in services/tests.

**Slice 6 Evidence - Clarified Scheduling Constraint Enforcement**

- [x] Updated `SectionMeetingAssignmentService` so every committable schedule row requires `faculty_id`, including Modular and Online modalities.
- [x] Updated `TermSchedulingReadinessService` so section capacity must stay within the rescue contract: `max_seats` from 1 to 30 and not below `enrolled_count`.
- [x] Updated `ScheduleSolverSnapshotService` so solver snapshots expose `available_seats`, `mandatory_faculty_assignment`, `max_section_seats = 30`, and section capacity mode.
- [x] Updated `ScheduleCommitService` so an `ok` draft row without faculty fails before official `section_meetings` creation.
- [x] Updated `SectionFactory` default `max_seats` to 30.
- [x] Verified with focused tests: `SectionMeetingAssignmentServiceTest`, `SchedulingReadinessContractTest`, `ScheduleSolverSnapshotServiceTest`, and `ScheduleCommitServiceTest`.

**Slice 7 Evidence - Solver Output Ingestion**

- [x] Added `ScheduleDraftRow` model constants/relationships/casts for `ok`, `warning`, and `conflict` rows.
- [x] Added `ScheduleCloudResultIngestor` to replace a run's draft rows from solver output and record `constraint_summary.solver_ingestion`.
- [x] Validated solver rows against immutable snapshot section demand, section capacity contract, mandatory faculty assignment, faculty-subject eligibility, snapshot faculty availability, required room/fixed-room/modality/time rules, committed meetings, and internal solver overlaps.
- [x] Preserved unresolved rows as `conflict` draft rows when possible; rows without valid section/subject foreign keys are counted as rejected in the ingestion summary because the existing table cannot store them.
- [x] Wired `ScheduleSolverDispatchJob` to ingest successful solver output before recording dispatch completion.
- [x] Updated `ScheduleCommitService` so `warning` rows are committable only after the same hard-constraint validation used for `ok` rows; `conflict` rows still block commit.
- [x] Verified with focused tests: `ScheduleCloudResultIngestorTest`, `ScheduleSolverDispatchJobTest`, and `ScheduleCommitServiceTest`.

**Slice 8 Evidence - Cloud Run OR-Tools Solver Container POC**

- [x] Added `cloud/scheduler-solver` Python container project for the IAM-private Cloud Run solver.
- [x] Added OR-Tools CP-SAT solver logic that turns Laravel solver snapshots into `draft_rows` and leaves unresolved curriculum demand as `conflict` rows.
- [x] Added stdlib HTTP service with `GET /health` and `POST /solve`, listening on the Cloud Run `PORT` environment variable.
- [x] Added local sample snapshot and Python unit tests for feasible assignment, unassignable conflicts, and existing-commitment avoidance.
- [x] Added Dockerfile, `.dockerignore`, Cloud Build image config, and local/cloud setup instructions.
- [x] Verified local Python tests and local HTTP `/health` + `/solve` probe.
- [x] Docker image build/run verification passed locally: `/health` returned `ok`, `/solve` returned `solver_status = optimal`, `assigned_count = 2`, `unassigned_count = 0`, and 2 `ok` draft rows from the sample snapshot.
- [x] Prepared `cloud/scheduler-solver.zip` for Google Cloud Shell upload and recorded the Cloud Shell deployment path in the solver README.
- [x] Cloud Run deployment completed through Google Cloud Shell: image `asia-southeast1-docker.pkg.dev/tala-dev-ocr-3s/tala-containers/tala-scheduler-solver:rescued-poc` built successfully and revision `tala-scheduler-solver-00003-lfk` is serving 100% traffic at `https://tala-scheduler-solver-783866300038.asia-southeast1.run.app`.
- [x] Verified Cloud Run IAM boundary: unauthenticated `/health` returned HTTP 403, while authenticated `/solve` with the sample snapshot returned `solver_status = optimal`, `assigned_count = 2`, and `unassigned_count = 0`.
- [x] Verified local Laravel-to-Cloud-Run integration: `.env` uses `cloud_run`, Laravel minted the service-account ID token, and the sample `/solve` smoke call returned `solver_status = optimal`, `assigned_count = 2`, `unassigned_count = 0`, `hard_violation_count = 0`, and 2 draft rows.
  - 2026-06-14 re-audit: `cloud/scheduler-solver` local Python unit tests passed in a temp venv; private Cloud Run `/health` returned HTTP 200 through Laravel client; private sample `/solve` returned `solver_status = optimal`, `assigned_count = 2`, `unassigned_count = 0`, and `draft_row_count = 2`.
  - 2026-06-15 feasible-input proof: local `cloud/scheduler-solver` unit verification now includes a deterministic 100-demand feasible snapshot. It returned `assigned_count = 100`, `unassigned_count = 0`, `hard_violation_count = 0`, greater than 98% coverage, and no independently detected section, faculty, room, eligibility, availability, fixed-room, time, or existing-commitment violations.
  - 2026-06-15 Laravel hard-validity guard: `ScheduleCloudResultIngestor` now treats a solver row whose room differs from the immutable section fixed room as a draft conflict before review/commit.
  - 2026-06-16 deployed Cloud Run proof: the configured IAM-private `/solve` endpoint accepted the deterministic 100-demand feasible snapshot and returned `solver_status = optimal`, `assigned_count = 100`, `unassigned_count = 0`, `hard_violation_count = 0`, `warning_count = 0`, `timeout = false`, `draft_row_count = 100`, and `solve_time_ms = 1596`. Independent validation of the deployed response found zero section, faculty, room, eligibility, availability, fixed-room, time, or existing-commitment violations.
  - 2026-06-16 constraint coverage audit: implemented rescue/MVP hard constraints are section-driven generation, curriculum-derived demand, bounded capacity, required fixed room for on-site/blended sections, mandatory faculty assignment, eligibility, submitted/locked availability, no internal/existing section/faculty/room overlaps, valid time ranges, immutable snapshots, private Cloud Run dispatch, Laravel ingestion validation, and commit-only official meetings. Broader TS policy hooks remain undecided/unimplemented as hard solver constraints: absolute campus day bounds, lunch breaks, max back-to-back load, optional faculty max-weekly-hours caps, and lecture/laboratory meeting split rules.
  - 2026-06-14 redeploy rule: if `cloud/scheduler-solver` changes during the Scheduling implementation slice, the agent must provide explicit Google Cloud Console or Cloud Shell redeployment steps when requested, then smoke-test `/health` and `/solve` before closing the slice.

**Scheduling/Curriculum SDD Decision Closure - 2026-06-17**

- [x] Resolved old policy-hook blocker: hard constraints remain non-overridable; faculty availability and workload overload are controlled soft overrides with required reason/approval where applicable.
- [x] Resolved curriculum/scheduling dependency: scheduling must use ready College curriculum scopes and weekly contact hours, not raw subject hours or College units as meeting duration.
- [x] Resolved sectioning model: section delivery groups are required for adaptable modality/delivery setup inside one academic section.
- [x] Resolved publish governance: Academic Head approval publishes official schedules after Registrar commit; System Super Admin emergency publish requires reason.
- [x] Resolved SDD-01 readiness architecture: explicit readiness scopes, scheduler-facing curriculum-subject fields, strict Education Level template, non-committable zero-valid previews, zero-error commit, scoped partial imports, service-derived blockers, activity-log transitions, and readiness-gated scheduling.
- [x] Implement SDD-01 (`TAL-20`) curriculum template/readiness scope changes.
  - 2026-06-17 implementation evidence: added `curriculum_readiness_scopes`, curriculum-subject scheduling fields, strict `Education Level` import template, zero-valid-row non-committable preview behavior, readiness service transitions, observer-based reset on scheduler-facing edits, readiness-gated scheduling, Filament curriculum scope actions, and solver snapshot readiness evidence.
  - 2026-06-17 verification: `php artisan test --compact tests/Feature/CurriculumScopeReadinessServiceTest.php tests/Feature/CurriculumImportServiceTest.php tests/Feature/SchedulingReadinessContractTest.php tests/Feature/ScheduleSolverSnapshotServiceTest.php tests/Feature/ScheduleGenerationServiceTest.php tests/Feature/ScheduleSolverDispatchJobTest.php tests/Feature/SchedulingEndToEndWorkflowTest.php tests/Feature/AcademicFoundationFilamentResourceTest.php tests/Feature/ImportBatchFilamentControlledWorkflowTest.php tests/Feature/PreUatScenarioSeederTest.php tests/Feature/SchedulingFilamentWorkflowTest.php` passed with 38 tests and 380 assertions.
  - 2026-06-17 Cloud Run boundary: no Cloud Run redeploy was performed for SDD-01. Laravel snapshots now include `weekly_contact_hours` and keep a legacy `lec_hours` alias sourced from `curriculum_subjects.weekly_contact_hours` so the already deployed solver remains compatible until SDD-03 runtime parsing changes.
- [x] Implement SDD-02 (`TAL-21`) delivery patterns and section delivery groups.
  - 2026-06-17 implementation evidence: added `delivery_patterns`, `section_delivery_groups`, nullable `section_delivery_group_id` compatibility links on enrollments, draft rows, and official meetings, delivery pattern/section delivery group models, factories, policies, services, service-backed Filament resources, Section delivery-groups relation manager, and Registrar section/group assignment service.
  - 2026-06-17 scheduling readiness evidence: `TermSchedulingReadinessService` now reports `delivery_group_issues`, blocks generation when required delivery groups are missing or invalid, derives demand per active delivery group, and keeps room catalog mode aligned to section delivery groups.
  - 2026-06-17 Pre-UAT evidence: `PreUatScenarioSeeder` now seeds a frozen face-to-face delivery pattern and primary section delivery group for the sample section, and links the sample enrollment to that group.
  - 2026-06-17 formatting verification: `vendor/bin/pint --dirty --format agent` passed.
  - 2026-06-17 verification: `php artisan test --compact tests/Feature/DeliveryPatternServiceTest.php tests/Feature/SectionDeliveryGroupServiceTest.php tests/Feature/EnrollmentSectioningServiceTest.php tests/Feature/DeliveryPatternFilamentResourceTest.php tests/Feature/SchedulingReadinessContractTest.php` passed with 21 tests and 126 assertions.
  - 2026-06-17 verification: `php artisan test --compact tests/Feature/ScheduleSolverSnapshotServiceTest.php tests/Feature/ScheduleGenerationServiceTest.php tests/Feature/ScheduleSolverDispatchJobTest.php tests/Feature/SchedulingEndToEndWorkflowTest.php tests/Feature/SchedulingFilamentWorkflowTest.php tests/Feature/PreUatScenarioSeederTest.php` passed with 13 tests and 143 assertions.
  - 2026-06-17 final focused regression: `php artisan test --compact tests/Feature/DeliveryPatternServiceTest.php tests/Feature/SectionDeliveryGroupServiceTest.php tests/Feature/EnrollmentSectioningServiceTest.php tests/Feature/DeliveryPatternFilamentResourceTest.php tests/Feature/SchedulingReadinessContractTest.php tests/Feature/ScheduleSolverSnapshotServiceTest.php tests/Feature/ScheduleGenerationServiceTest.php tests/Feature/ScheduleSolverDispatchJobTest.php tests/Feature/SchedulingEndToEndWorkflowTest.php tests/Feature/SchedulingFilamentWorkflowTest.php tests/Feature/PreUatScenarioSeederTest.php` passed with 34 tests and 269 assertions after Pint.
  - 2026-06-17 Cloud Run boundary: no Cloud Run redeploy was performed for SDD-02 because `cloud/scheduler-solver` runtime code did not change. SDD-03 remains the solver snapshot/runtime/ingestion/commit/publish redeploy and proof gate for required `section_delivery_group_id`.
- [x] Implement SDD-03 (`TAL-22`) local scheduling snapshot/solver/ingestion/commit/publish changes for `section_delivery_group_id`.
  - 2026-06-17 implementation evidence: solver snapshots now use schema version 3 with `section_delivery_groups`, delivery-group demand keys, delivery pattern fields, group capacity, room requirement, and existing commitments keyed by `section_delivery_group_id`.
  - 2026-06-17 runtime evidence: `cloud/scheduler-solver` now parses and emits `section_delivery_group_id`, enforces delivery-group capacity, fixed group room, same-group overlaps, faculty/room overlaps, eligibility, availability, and valid time ranges, and uses `weekly_contact_hours` before legacy `lec_hours`.
  - 2026-06-17 Laravel evidence: solver result ingestion, draft-row review, commit, manual official assignment, schedule-change apply, and Filament scheduling surfaces now require/select delivery groups. Published runs block late solver ingestion, draft-row revision, manual official assignment, and schedule-change apply.
  - 2026-06-17 publish evidence: added `SchedulePublishService`, publish metadata on schedule generation runs, Academic Head publish action, System Super Admin emergency publish action with required reason, publish infolist/table evidence, and audit activity logging.
  - 2026-06-17 verification: `php artisan test --compact tests/Feature/ScheduleCloudResultIngestorTest.php tests/Feature/ScheduleDraftRowReviewServiceTest.php tests/Feature/ScheduleChangeLifecycleServiceTest.php tests/Feature/ScheduleChangeTargetMeetingScopeTest.php tests/Feature/ScheduleSolverSnapshotServiceTest.php tests/Feature/ScheduleCommitServiceTest.php tests/Feature/SectionMeetingAssignmentServiceTest.php tests/Feature/SchedulePublishServiceTest.php tests/Unit/ScheduleChangePayloadTest.php tests/Feature/SchedulingEndToEndWorkflowTest.php tests/Feature/SchedulingFilamentWorkflowTest.php` passed with 53 tests and 271 assertions after Pint.
  - 2026-06-17 solver verification: temp-venv `python -m unittest discover -s cloud/scheduler-solver/tests` passed with 4 tests after installing `cloud/scheduler-solver/requirements.txt`.
  - 2026-06-17 package boundary: `cloud/scheduler-solver.zip` was refreshed on disk for Cloud Shell upload, but it is gitignored. No Cloud Run redeploy or private `/health` + `/solve` deployed smoke proof has been performed yet.
- [x] SDD-03 Cloud Run closure: redeployed and smoke-tested the IAM-private solver after `section_delivery_group_id` runtime changes.
  - 2026-06-17 Cloud Build evidence: image `asia-southeast1-docker.pkg.dev/tala-dev-ocr-3s/tala-containers/tala-scheduler-solver:sdd-03-delivery-groups-20260617` built and pushed successfully with digest `sha256:94435d3bd658907eef99e38a7f2fc44fc713c0d94a4a97cbc0c1fffafd755688`.
  - 2026-06-17 Cloud Run evidence: service `tala-scheduler-solver` deployed revision `tala-scheduler-solver-00004-wtx` in `asia-southeast1`, serving 100% traffic at `https://tala-scheduler-solver-783866300038.asia-southeast1.run.app`.
  - 2026-06-17 private smoke evidence: authenticated `/health` returned `{"status":"ok","service":"tala-scheduler-solver"}`; authenticated `/solve` with `samples/minimal_snapshot.json` returned `solver_status = optimal`, `assigned_count = 2`, `unassigned_count = 0`, `hard_violation_count = 0`, `warning_count = 0`, and draft rows containing `section_delivery_group_id = 110`.
  - 2026-06-17 IAM boundary evidence: unauthenticated `/health` returned HTTP 403.

**Scheduling Flow Clarification - 2026-06-09**

- [x] Updated FS/TS so automatic scheduling is explicitly section-driven: term sections by program/year level/curriculum period must exist before solver dispatch.
- [x] Clarified that the rescue solver assigns faculty, room when required, day, and time for planned section-subject demand; it does not create, split, merge, or rebalance sections.
- [x] Clarified that missing planned sections or missing section solver-scope fields block generation readiness.

**Slice 9 Evidence - Section Planning / Real Scheduling Readiness Flow**

- [x] Added `SectionPlanningService` as the backend guard for section planning create/edit data before solver readiness: term, program, curriculum, year level, curriculum period, name, modality, bounded capacity, enrolled count, and room rules.
- [x] Added Registrar `Section Planning` Filament resource with typed create/edit/view/list surfaces for planned term sections; delete and bulk-delete remain unavailable.
- [x] Enforced rescue capacity through the section planning path: `max_seats` cannot exceed 30 and cannot be lower than `enrolled_count`.
- [x] Enforced modality room behavior through section planning: on-site/blended require a fixed room; online/modular clear physical room.
- [x] Added curriculum/program mismatch detection to `TermSchedulingReadinessService` so wrong-curriculum sections cannot pass snapshot capture.
- [x] Added service-backed `Generate Schedule` action to Schedule Drafts; it calls `ScheduleGenerationService`, captures the immutable snapshot, and queues solver dispatch without exposing generic schedule-run CRUD.
- [x] Verified with focused tests: `SectionPlanningServiceTest`, `SectionPlanningFilamentResourceTest`, `SchedulingReadinessContractTest`, `ScheduleGenerationServiceTest`, `ScheduleSolverSnapshotServiceTest`, and `TAL12ARegistrarFilamentResourceTest`.

**Slice 10 Evidence - Schedule Draft Review / Conflict Resolution UI**

- [x] Added `ScheduleDraftRowReviewService` so Registrar draft-row edits are authorized, require a review reason, and re-run the full Laravel ingestion validator before commit can succeed.
- [x] Added `DraftRowsRelationManager` to Schedule Drafts as a run-scoped review table for `ok`, `warning`, and `conflict` rows.
- [x] Kept draft rows out of generic CRUD: no standalone draft-row resource, no create/delete/dissociate/bulk-delete relation actions, and no raw payload editing.
- [x] Exposed controlled row revision fields only: faculty, day, start, end, modality, room, and review reason.
- [x] Added Schedule Draft detail counts for total draft rows, blocking conflicts, and warnings.
- [x] Confirmed unresolved hard conflicts remain blocking after manual revision, while hard-valid revisions become warning rows with edit evidence.
- [x] Verified with focused tests: `ScheduleDraftRowReviewServiceTest`, `ScheduleDraftRowsRelationManagerTest`, `TAL12ARegistrarFilamentResourceTest`, `ScheduleCloudResultIngestorTest`, `ScheduleCommitServiceTest`, and `ScheduleSolverDispatchJobTest`.

**Slice 11 Evidence - Faculty Availability Submission / Locking Flow**

- [x] Added `FacultyAvailabilityService` as the backend owner for Registrar period validation, faculty weekly-window submission, duplicate-submission blocking, invalid/overlapping-window rejection, and Registrar locking.
- [x] Added Registrar `Availability Periods` Filament resource with typed term/open/close controls; period creation/update routes through `FacultyAvailabilityService::preparePeriodData`.
- [x] Added shared `Faculty Availability` Filament resource that appears under Faculty, Registrar, or Academic Head context and scopes faculty users to their own submissions.
- [x] Kept faculty submissions out of generic CRUD: no edit route, no delete actions, no raw `faculty_id`/`term_id`/`status` fields, and creation routes through `FacultyAvailabilityService::submitAvailability`.
- [x] Added Registrar lock action on submitted availability records; locked rows record approver and lock timestamps and become stable solver input evidence.
- [x] Registered `FacultyAvailabilityPeriodPolicy` and `FacultyAvailabilitySubmissionPolicy` so period management, faculty submission, and read-only oversight use the approved permissions.
- [x] Verified solver compatibility remains intact because `ScheduleSolverSnapshotService` already captures submitted/locked availability windows from the same tables.
- [x] Verified with focused tests: `FacultyAvailabilityServiceTest`, `FacultyAvailabilityFilamentResourceTest`, `ScheduleSolverSnapshotServiceTest`, `TAL12ARegistrarFilamentResourceTest`, and `FacultySubjectEligibilityTest`.

**Slice 15 Evidence - Post-Lock / Deadline Availability Change Requests**

- [x] Added `FacultyAvailabilityChangeRequestService` to validate Faculty-owned submitted/locked availability, required reason, requested windows, ownership, duplicate pending requests, stale source versions, and Registrar approve/reject transitions.
- [x] Added `FacultyAvailabilityChangeRequest` model/factory/policy and a forward migration for request audit payloads (`source_windows`, `requested_windows`, `review_note`).
- [x] Added `Availability Change Requests` Filament resource: Faculty can create/view own requests; Registrar/Academic Head can review; Registrar can approve/reject pending requests through lifecycle table actions.
- [x] Kept the workflow out of generic CRUD: no edit route, no delete actions, no raw `faculty_id`/`term_id`/`status` fields, and no raw solver snapshot editing.
- [x] Approved requests create a new locked `faculty_availability_submissions` revision with `version + 1` and `parent_submission_id`; original locked availability is not mutated.
- [x] Rejected requests record Registrar review evidence without creating a revision.
- [x] Confirmed committed official schedules are not mutated by this workflow; downstream official changes still require the Schedule Change lifecycle.
- [x] Verified with focused tests: `FacultyAvailabilityChangeRequestServiceTest`, `FacultyAvailabilityChangeRequestFilamentTest`, `FacultyAvailabilityServiceTest`, `FacultyAvailabilityFilamentResourceTest`, `ScheduleSolverSnapshotServiceTest`, `SchedulingFilamentSmokeTest`, `SchedulingFilamentWorkflowTest`, and `SchedulingEndToEndWorkflowTest`.

**Slice 12 Evidence - End-to-End Scheduling QA / Fix Pass**

- [x] Added `SchedulingEndToEndWorkflowTest` as the integrated rescue regression for the full viable scheduling path.
- [x] Verified the workflow uses real Laravel services for Registrar period setup, faculty availability submission, Registrar locking, schedule generation, solver dispatch handling, solver-result ingestion, draft-row review state, and final commit.
- [x] Kept the test deterministic by faking only the solver client response; the test does not require Cloud Run uptime, local Docker, or a manual queue worker.
- [x] Confirmed the generated solver rows become `ok` draft rows, the schedule run moves to `under_review`, commit creates official `section_meetings`, and `section_teacher` assignments are written.
- [x] Verified with focused tests: `SchedulingEndToEndWorkflowTest`, `ScheduleGenerationServiceTest`, `ScheduleSolverDispatchJobTest`, `ScheduleCloudResultIngestorTest`, `ScheduleCommitServiceTest`, `FacultyAvailabilityServiceTest`, and `ScheduleSolverSnapshotServiceTest`.
- [x] No user-side Google Cloud Console, Docker, or manual queue action is required for this QA slice. A live Cloud Run smoke test remains optional because the deployed solver was already verified separately.

**Slice 13 Evidence - Scheduling Filament Admin Smoke Pass**

- [x] Added `SchedulingFilamentSmokeTest` to verify the scheduling admin pages render through the real Filament panel routes instead of only source-level assertions.
- [x] Confirmed Registrar can open Section Planning, Official Schedule manual assignment, Faculty Subject Eligibility, Availability Periods, Faculty Availability review, and Schedule Drafts surfaces with the approved rescue permissions.
- [x] Confirmed Faculty can open only their self-service scheduling surfaces: own subject eligibility visibility and faculty availability submission/list pages.
- [x] Confirmed Faculty cannot open Registrar-only section planning, eligibility creation, availability-period creation, or schedule-draft routes.
- [x] Kept this as route-render smoke coverage; full browser/manual Pre-UAT QA remains tracked under Iteration 8.
- [x] Verified with focused test: `SchedulingFilamentSmokeTest`.

**Slice 14 Evidence - UI-Driven Scheduling Workflow QA**

- [x] Added `SchedulingFilamentWorkflowTest` as the automated admin workflow proof that scheduling is usable through Filament/Livewire surfaces, not only backend service calls.
- [x] Verified Registrar can create a section plan through `CreateSection` with typed term/program/curriculum/year/period/modality/capacity/room fields.
- [x] Verified Registrar can create faculty-subject eligibility through `CreateFacultySubjectEligibility` and open the faculty availability period through `CreateFacultyAvailabilityPeriod`.
- [x] Verified Faculty can submit weekly availability through `CreateFacultyAvailabilitySubmission`, and Registrar can lock it through the Faculty Availability table action.
  - 2026-06-14 UI clarification: Faculty availability submission already has a concrete Admin Nexus UI. Faculty selects an open availability period and adds weekly windows with Day, Start time, End time, and optional Notes through a repeater. It is not a drag-calendar UI, and it does not expose raw faculty/term/status/lock fields.
  - 2026-06-14 cadence clarification, superseded by SDD-00C for SHS: Faculty availability is one normal submission per faculty per configured College scheduling term. The Academic Year is an umbrella, not the submission unit. Visual weekly calendar selection is a deferred UX enhancement; the current weekly-window repeater remains Pre-UAT scope.
  - 2026-06-14 calendar interface implementation, bounded by SDD-00C: `AcademicYearResource` now makes College Academic Year rows staff-operable through typed create/view/edit screens with no delete or bulk-delete. `TermResource` remains the child operational-gate surface and requires Academic Year selection through the `academicYear` relationship.
- [x] Verified Registrar can run `Generate Schedule` from Schedule Drafts, then the queued solver dispatch can ingest deterministic solver rows into `schedule_draft_rows`.
- [x] Verified Registrar can review generated draft rows through `DraftRowsRelationManager` and commit the schedule through the Schedule Drafts table action.
- [x] Confirmed commit creates official `section_meetings`, records `committed_by`, and writes `section_teacher` assignments from the generated rows.
- [x] Verified with focused tests: `SchedulingFilamentWorkflowTest`, `SchedulingFilamentSmokeTest`, `SectionPlanningFilamentResourceTest`, `FacultyAvailabilityFilamentResourceTest`, `ScheduleDraftRowsRelationManagerTest`, `TAL12ARegistrarFilamentResourceTest`, `SchedulingEndToEndWorkflowTest`, `ScheduleGenerationServiceTest`, `ScheduleSolverDispatchJobTest`, `ScheduleCloudResultIngestorTest`, and `ScheduleCommitServiceTest`.

**Slice 16 Evidence - Controlled Pre-UAT Scenario Data Path**

- [x] Added local/UAT-only `PreUatScenarioSeeder`; it aborts in production and is idempotent for the named Pre-UAT records.
- [x] Seed path creates the minimum executable Pre-UAT dataset: active term, BSIT program/curriculum, two subjects, one planned section capped at 30 seats, faculty eligibility, locked faculty availability, student/enrollment/enrolled subjects, ledger/payment evidence, document upload/request, grade/correction, service request, and published/unpublished FAQ rows.
- [x] Seed path intentionally leaves `section_meetings` empty so Registrar scheduling QA still exercises Generate Schedule, solver ingestion, draft review, and commit.
- [x] Updated `uat-readiness/TALA-Local-Pre-UAT-Spin-Up-Guide.md` and `uat-readiness/TALA-Pre-UAT-Developer-QA-Checklist-2026-06-01.md` so controlled scenario seeding is a required setup step before browser QA.
- [x] Verified with focused tests: `PreUatScenarioSeederTest`, `SchedulingReadinessContractTest`, and `ScheduleSolverSnapshotServiceTest`.

**Slice 17 Evidence - Academic Foundation Admin Behavior / Room Catalog**

- [x] Added typed Filament resources for Programs, Subjects, Curricula/Curriculum Subjects, Terms, and Rooms so foundation setup is no longer seed-only.
- [x] Added manager/viewer policy boundaries for academic foundation records; generic delete/bulk delete remains blocked for dependent foundation tables.
- [x] Added the `rooms` table, `Room` model/factory/policy/resource, and active room selection in Section Planning for on-site/blended sections.
- [x] Updated `SectionPlanningService` to reject missing, unknown, or inactive physical rooms before solver inputs are saved.
- [x] Updated Pre-UAT scenario seeding to create `R-101` as a valid room catalog entry.
- [x] Verified with `php artisan migrate --no-interaction`, route checks for `/admin/programs`, `/admin/subjects`, `/admin/curricula`, `/admin/terms`, `/admin/rooms`, and focused tests: `AcademicFoundationFilamentResourceTest`, `SectionPlanningServiceTest`, `SectionPlanningFilamentResourceTest`, and `SchedulingFilamentWorkflowTest`.

**Slice 18 Evidence - Controlled Curriculum Import Flow**

- [x] Added `CurriculumImportTemplate` and `CurriculumImportService` for strict curriculum/foundation imports instead of generic import CRUD.
- [x] Added Import Batches header actions for curriculum template download and private CSV/XLSX upload.
- [x] Enforced strict headers, row validation, spreadsheet formula-prefix rejection for key fields, preview summary storage, and zero-error commit eligibility.
- [x] Updated `ImportBatchLifecycleService` so curriculum commits delegate to the controlled import service and unsupported import types cannot be fake-committed.
- [x] Kept `ImportBatchResource` list/view plus typed actions only; no generic create/edit route, no raw private file path form, and no in-browser row repair.
- [x] Commit re-parses the stored private source under lock, upserts Programs, Subjects, Curricula, and Curriculum Subjects, writes commit metadata, and records activity.
- [x] Verified with focused tests: `CurriculumImportServiceTest`, `ImportBatchLifecycleServiceTest`, `ImportBatchFilamentControlledWorkflowTest`, `TAL12ARegistrarFilamentResourceTest`, `AcademicFoundationFilamentResourceTest`, `SectionPlanningServiceTest`, and `SchedulingFilamentWorkflowTest`.

**Slice 19 Evidence - Academic Head Grade-Correction Approval**

- [x] Added Academic Head review metadata to `grade_corrections`: review status, reviewer, reviewed timestamp, and decision note.
- [x] Added service-owned Academic Head approval/rejection transitions through `GradeCorrectionService::approveOfficialGradeChange()` and `rejectOfficialGradeChange()`.
- [x] Blocked Registrar official grade-change resolution unless the correction has in-system Academic Head approval.
- [x] Added policy-guarded Filament actions: `Approve Official Grade Change`, `Reject Official Grade Change`, and `Resolve - Apply Approved Grade Change`.
- [x] Removed Registrar-selected offline approver behavior from the active UI and service contract.
- [x] Kept `GradeCorrectionResource` list/view/action-only with no generic create/edit correction form or raw Academic Head review metadata form.
- [x] Verified with focused tests: `GradeCorrectionServiceTest`, `TAL12AAcademicHeadFilamentResourceTest`, and `TAL12AFacultyFilamentResourceTest`.

---

## Iteration 1 - DB-First Closure Wave (`TAL-5`)

- [x] Spec-First Gate executed: FS/TS/Migration Log used as implementation basis
- [x] Freeze baseline spec refs (FS/TS/Migration Log snapshot)
- [x] Build schema contract matrix (table/columns/constraints/source/migration)
- [x] Run gap audit (spec vs migration vs log)
- [x] Patch migration files only (no service logic yet)
- [x] Validate FK/index/unique/decimal/status defaults
- [x] Run `php -l` on changed migrations
- [x] Run targeted `php artisan migrate --pretend`
- [x] Run `php artisan migrate:fresh --seed`
- [x] Verify schema result against matrix
- [x] Update migration log inventory/status

**DoD**
- [x] No major schema gap remains
- [x] All contract tables have migration coverage
- [x] Migration log matches actual inventory

---

## Iteration 2 - F1 Calendar Phase-Gate Services and Tests (`TAL-6`)

- [x] Spec-First Gate executed: FS/TS calendar contract verified before implementation
- [x] Implement enrollment gate checks
- [x] Implement scheduling gate checks
- [x] Enforce no late enrollment edits after close
- [x] Implement College cutover behavior
- [x] Wire cutover keys from `system_settings`
- [x] Add PHPUnit coverage for allow/deny gate behavior
- [x] Add tests for blocked late edits + audit trail

**DoD**
- [x] F1 behavior enforced in code
- [x] College gates test-verified

---

## Iteration 3 - F10 Installment Services, Jobs, and Tests (`TAL-7`)

- [x] Spec-First Gate executed: FS/TS installment policy verified before implementation
- [x] Implement installment service contract/state transitions
- [x] Implement monthly EOM due evaluator
- [x] Implement 3-day grace + overdue handling
- [x] Implement recurring 5% penalty per missed month
- [x] Enforce promissory non-payment semantics in clearance logic; promissory approval never clears finance by itself, but real confirmed payments still clear finance when thresholds are met.
- [x] Add PHPUnit coverage for happy/failure/overdue cases
- [x] Add audit logs for installment state changes

**DoD**
- [x] F10 policy runs in code/jobs
- [x] Penalty/grace behavior matches spec

---

## Iteration 4 - Enrollment + Accounting Vertical Slice (Admin-first) (`TAL-8`)

- [x] Registrar intake/review/enrollment prep flow
- [x] Accounting assessment + ledger posting flow
- [x] Apply freshmen discount (50% tuition-only) rule
- [x] Enforce registrar/accounting RBAC boundaries
- [x] Expose finance status transitions in admin UI
- [x] Add feature tests for key transitions/permissions

**DoD**
- [x] Admin can complete enrollment-to-assessment flow
- [x] Discount and RBAC are validated

---

## Iteration 5 - Integration Layer (PayMongo + Google Vision Sandbox) (`TAL-9`)

### Mock Contract Phase (no external network calls)

- [x] Add mock integration switchboard (`config/tala_integrations.php`)
- [x] Add PayMongo test keys to local `.env` while keeping `TALA_PAYMENT_GATEWAY_DRIVER=mock`
- [x] Add mock payment checkout service writing to `payment_attempts`
- [x] Implement mock PayMongo webhook route using real event names (`POST /api/webhooks/paymongo`)
- [x] Store mock webhook payloads and headers in `webhook_calls`
- [x] Apply idempotency using `{event_id}:{provider_checkout_session_id|provider_payment_id}`
- [x] Convert successful mock webhook into `payment_attempts -> payments -> ledger_entries`
- [x] Add duplicate, failed, unknown, and invalid-signature webhook tests
- [x] Add mock OCR processing service/job writing to `document_uploads` + `document_ocr_results`
- [x] Add mock OCR/payment placeholder tests

### Real Sandbox Phase (external provider enabled intentionally)

- [x] Create PayMongo dashboard webhook after route exists
- [x] Add `PAYMONGO_WEBHOOK_SIG` from PayMongo dashboard
- [x] Enable real PayMongo checkout driver only after webhook tests pass
- [x] Verify real PayMongo webhook signature and callback delivery
- [x] Configure GCV test credentials + OCR pipeline
- [x] Add retry/backoff for real external failures
- [x] Add integration tests/mocks for provider callbacks/errors

**DoD**
- [x] Mock-contract PayMongo flow is operational without external calls
- [x] Sandbox integrations are operational
- [x] Duplicate webhooks are safely ignored

---

## Iteration 6 - Faculty + Grades Module (`TAL-10`)

- [x] Implement grade encoding/submission flow
- [x] Implement lock/finalization flow + role checks
- [x] Implement grade correction flow
- [x] Enforce faculty finance-visibility boundary
- [x] Add tests for grade rules/state/RBAC

**DoD**
- [x] Backend grade services/RBAC flow works end-to-end
- [x] Filament grade UI end-to-end proof moved to `TAL-12A`

---

## Iteration 7 - Service Requests + Fulfillment Module (`TAL-11`)

- [x] Implement request lifecycle states
- [x] Implement payment checkpoints for paid requests
- [x] Implement fulfillment/release controls
- [x] Implement grace/debt behavior where required
- [x] Add notifications + audit logs
- [x] Add feature tests for lifecycle and payment gates

**DoD**
- [x] Request lifecycle is role-correct and traceable

---

## Iteration 7.5 - Filament Admin UI Completion Pass (`TAL-12A`, Linear: `TAL-14`)

- [x] Spec-First Gate executed: FS/TS staff workflows mapped to Filament screens/actions
- [x] Registrar admin screens/actions wired for enrollment review, document review, scheduling/import-audit controls, COR controls, and service-request fulfillment; enrollments are list/view lifecycle-action surfaces with no generic create/edit state form; service-request resolve/reject/cancel actions use typed note/reason modals with lifecycle evidence capture
- [x] Accounting admin screens/actions wired for assessments, ledger review, OTC/manual payment confirmation, payment queues, promissory records, fee/installment configuration, and installment policy visibility; fee templates/installment policies use canonical education/program/year scope controls with one active config per scope, ledger entries are list/view immutable evidence only, promissory records are typed approved-promise records with student/term-scoped enrollment and ledger selects, no generic edit/status form, and installment milestones are typed child schedule rows on the policy form rather than standalone generic create/edit rows
- [x] Faculty admin screens/actions wired for class lists, finance-status-only visibility, program-specific grade encoding/submission/finalization, correction requests, advising status, and quick links
- [x] Faculty/Grades Filament flow works end-to-end for College-only scope: class list -> grade encoding -> submission/finalization -> correction review/resolution/override; Faculty Class Lists are list/view plus typed grade actions only, Grade Oversight is list/view plus typed override actions only, and Grade Correction is list/view plus typed lifecycle actions only, with official correction grade changes calculated from College period inputs and no generic enrollment-subject, grade, correction create/edit form, direct final-grade override, or manual remarks override
- [x] Academic Head admin screens/actions wired for read-only oversight and approved override actions only
- [x] System Super Admin screens/actions wired for users/read-only roles/audit/FAQ content maintenance while keeping academic and financial domains read-only where required; generic `system_settings` UI is hidden/internal for TAL-12
- [x] Add Filament/Livewire tests or browser smoke tests for role-specific navigation and critical actions
- [x] Verify seeded staff accounts can perform only their allowed Admin Nexus workflows

**DoD**
- [x] Each staff role can complete its current TAL-12A implemented admin workflows inside Filament
  - 2026-06-02 historical evidence, partially superseded by SDD-00D: Academic Head finance access was narrowed to read-only finance status, fee template/downpayment rules, installment policy summary, and promissory status/tag; FAQ categories were fixed. The former document-request type work was later removed with the feature.
  - 2026-06-03 role/resource reconciliation completed: vendor-backed `Role` and `Activity` policies are explicitly registered; only System Super Admin sees Roles/Audit/Users/FAQ; Import Batches are scoped as `Import Batch Audit` with no generic create/edit routes; Grade Oversight has no raw create/edit grade form; grade-correction grade changes record already-approved Academic Head authorization; full import upload/preview pages, COR template editor, document-catalog admin, rich dashboard metrics, and any separate System Health admin page are not claimed as completed TAL-12A evidence unless implemented through separate items. Faculty availability self-service was later implemented as rescue Slice 11.
  - 2026-06-05 Import Batch lifecycle hardening completed: `ImportBatchResource` remains audit-only with no raw file-path/error-log form; commit/cancel controls delegate to `ImportBatchLifecycleService`, which validates Registrar import permissions, allows only pending batches, writes commit metadata when appropriate, and records lifecycle activity. `ImportBatch` owns import type/status options so Filament filters and badges do not duplicate enum literals.
  - 2026-06-05 Schedule Draft commit hardening completed: `ScheduleGenerationRunResource` remains list/view with no raw run form; Commit is visible only for committable generated/under-review runs and delegates to `ScheduleCommitService`. The service validates Registrar scheduling permission, rejects conflicted or incomplete draft rows, creates official `section_meetings`, synchronizes `section_teacher`, writes commit metadata, and records lifecycle activity inside one transaction.
  - 2026-06-05 COR Verification lifecycle hardening completed: `CorVerificationResource` remains list/view only with no generic token/status/timestamp form; Supersede/Revoke delegate to `CorVerificationLifecycleService`, which validates `manage-lis`, accepts only valid transitions, requires a typed revoke reason before revoked state, writes `revoked_at` and `revocation_reason`, and records lifecycle activity. `CorVerification` owns status options/colors so filters and badges do not duplicate literals; token detail display uses descriptive student/term/enrollment labels instead of raw foreign-key IDs.
  - 2026-06-06 Document Upload review lifecycle hardening completed: `DocumentUploadResource` remains Registrar list/view review queue with no generic create/edit form or raw OCR/payload editing; Approve/Needs Correction/Reject controls delegate to `DocumentUploadReviewService`, which validates `approve-documents`, allows only active review states, treats approved/rejected uploads as terminal lifecycle evidence, requires typed correction/rejection reasons, captures approved payload snapshots, and records document-review activity. `DocumentUpload` owns review status options/colors so Filament filters and badges do not duplicate literals; the detail view now uses descriptive student/uploader/term/reviewer labels and source-file evidence instead of raw internal IDs or private storage path display.
  - 2026-06-11 Grade Correction official-change hardening completed, with SHS inputs superseded by SDD-00C: `GradeCorrectionResource` remains list/view/action-only; student/backend intake owns ticket creation and server-derived fields; Registrar lifecycle actions own review/reject/no-change resolution; Academic Head approve/reject actions now own official/finalized grade-change approval evidence; the approved grade-change action collects College Prelim/Midterm/Final raw scores after approval and `GradeCorrectionService` derives final grade/remarks through the College grading service; no generic correction create/edit/raw status form, Registrar-selected offline approver, direct final-grade override, or manual remarks override remains in TAL-12A.
  - 2026-06-03 Official Schedule surface hardening completed: `SectionMeetingResource` keeps Registrar manual assignment but replaces raw schedule/commit fields with typed term/section/subject/faculty/day/time/modality/room controls; commit metadata is server-derived; direct edit/delete of committed meetings is blocked; schedule-change apply reuses the assignment conflict guard. Faculty availability-window enforcement was completed later through rescue Slice 11.
  - 2026-06-05 Schedule Change lifecycle boundary tightened: schedule-change forms remain typed and old/new payloads remain internal snapshots; direct edit access is now limited to `proposed` requests, while approved/applied/rejected records are lifecycle evidence only. Approve/Apply table actions now delegate to `ScheduleChangeLifecycleService`, which validates `authorize-overrides` or `manage-schedules`, accepts only valid state transitions, applies normalized official-meeting changes through the assignment conflict guard, and records lifecycle activity.
  - 2026-06-05 Service Request lifecycle note capture completed: `ServiceRequestResource` keeps list/view/action-only boundaries; Resolve exposes optional `resolution_note`, Reject requires `rejection_reason`, and Registrar Cancel requires `cancellation_reason`. Notes/reasons are normalized into activity properties and notification metadata instead of a discarded generic note or mutable request field.
  - 2026-06-06 System Super Admin staff account lifecycle hardening completed: Roles are a list-only seeded permission matrix with no create/edit route, action, page class, form, or permissions multi-select; staff user direct edit is limited to other non-archived accounts and archive/restore owns archived lifecycle. `UsersTable` now delegates Archive/Restore Account actions to `UserAccountLifecycleService`, which validates `archiveStaffAccount`/`restoreStaffAccount` policy abilities, blocks self/invalid state transitions, requires an official archive reason, clears roles on archive, requires one approved staff role on restore, and records lifecycle activity.
  - 2026-06-06 Audit Log detail display hardening completed: `ActivityResource` remains a System Super Admin read-only list/view evidence surface, and `ActivityInfolist` now formats activity properties into labeled audit metadata lines through `ActivityPropertiesFormatter` instead of exposing raw JSON/key-value payload UI.
  - 2026-06-05 Staff User Management status/role control hardened: direct staff create/edit status uses a validated active/inactive toggle sourced from `User::staffEditableStatusOptions()`, archived is not a direct form option, and restore/direct role choices reuse `User::staffRoleOptions()` instead of duplicated ad hoc role lists.
  - 2026-06-06 Student Hub access/FAQ boundary hardening completed: `/student/*` routes now require `auth` plus `student.active`, `EnsureActiveStudentHubUser` allows only authenticated active users with the `student` role and is persisted for Livewire requests, and the Student Hub Help route displays only published `FaqEntry` records grouped by model-owned categories.
  - 2026-06-06 Public FAQ consumption completed: `/faq` is implemented as a guest-accessible read-only Livewire page backed only by published `FaqEntry` records and model-owned categories.
  - 2026-06-14 FAQ maintainability correction completed: `FaqEntryResource`, its Filament pages, `FaqEntryPolicy`, and the seeded `manage-faqs` permission are restored so System Super Admin can maintain FAQ content through Admin Nexus instead of hardcoding or seed-only updates. Public `/faq` and Student Hub Help continue to consume only published `FaqEntry` rows; Registrar, Accounting, Faculty, Academic Head, Students, and public users remain read-only for FAQ content.
  - 2026-06-04 Faculty Class List surface debloat completed, with SHS inputs superseded by SDD-00C: `EnrollmentSubjectResource` is list/view/action-only; stale create/edit/form scaffold files are removed; faculty grade entry is handled through College modal fields (`prelim`/`midterm`/`final`) backed by grade services; direct raw enrollment-subject mutation remains outside TAL-12A.
  - 2026-06-06 Promissory Note surface hardening completed: `PromissoryNoteResource` keeps Accounting create/review for approved promise cases but removes the generic edit page, edit actions, and raw status selector; status/approval metadata are system-derived on create. The create form now uses dependent student/term-scoped enrollment and ledger selects, backend validation rejects cross-student, cross-term, or cross-enrollment IDs, and list/detail views reuse descriptive student, enrollment, ledger, and approver labels so raw unscoped IDs are not part of the Accounting workflow. Student Hub upload intake, pending/reject/expire/settle lifecycle actions, replacement rules, and one-per-academic-year enforcement remain outside completed TAL-12A until a dedicated workflow contract is added.
  - 2026-06-06 Enrollment hard-copy receipt lifecycle hardening completed: `EnrollmentResource` remains list/view/action-only; stale create/edit/form scaffold files are removed; direct raw `status`, `lis_status`, section, hard-copy flag, and lifecycle timestamp mutation is blocked. The Registrar hard-copy receipt action now delegates to `EnrollmentHardCopyReceiptService`, which validates the `markHardCopyReceived` policy ability, locks the enrollment and linked student profile, prevents duplicate confirmation, writes `hard_copy_received` and `last_status_changed_at`, and records lifecycle activity. Approved-applicant enrollment creation, LIS lifecycle actions, ineligibility handling, term-close completion, and manual repair flows remain outside completed TAL-12A unless implemented through dedicated services/actions.
  - 2026-06-05 Installment Policy milestone surface hardening completed: `InstallmentPolicyResource` owns milestone schedule configuration through a typed `milestones` relationship repeater; `InstallmentPolicyMilestoneResource` is list/view only with no create/edit pages, header actions, edit table action, or raw status form. Per-student installment state and penalties remain service-calculated from ledger/payment evidence.
  - 2026-06-05 Accounting configuration scope hardening completed: `FeeTemplate` and `InstallmentPolicy` forms use canonical year/grade selects matching enrollment values, normalize blank program/year scope to all-scope `null`, and reject a second active config for the same education/program/year scope. Inactive historical configs may share a scope.
  - 2026-06-05 System Settings generic edit surface removed: `system_settings` remains an internal runtime registry; `SystemSettingResource` has no navigation, no create/edit page route, no edit table action, and no raw JSON form. Future changes require dedicated typed settings pages or service-backed form handlers.
  - 2026-06-06 historical evidence, superseded by SDD-00D: Document Request shipment evidence hardening was completed before the feature was removed from TALA scope.
  - 2026-06-03 follow-up tracking created in Linear `TAL-15` for larger admin surfaces not included in current TAL-12 Pre-UAT scope.
- [x] Grade flow works end-to-end in Filament for the allowed staff roles
- [x] Panel actions call tested backend services without bypassing policies
- [x] Student Portal UI remains untouched and deferred to `TAL-13`; shared student-domain backend logic required by admin workflows remains in backend/admin scope

---

## Iteration 8 - Hardening, UAT, and Go-Live Readiness (`TAL-12`)

- [x] Verify `TAL-12A` Filament admin completion evidence before UAT
- [x] Consolidate regression suite
  - 2026-06-06 static-analysis baseline added: Larastan is installed in dev dependencies and `phpstan.neon` defines a level 5 scan over `app/` and `routes/`, giving TAL-12/TAL-12A follow-up hardening a repeatable PHP static-analysis entrypoint alongside Pint and focused PHPUnit checks.
- [x] Run security/data-protection checks
  - 2026-05-31 product security check closed for the TALA backend/admin scope. Composer audit passed, focused security/RBAC tests passed, and full compact test suite passed. Agent/tooling-only npm audit findings are not counted as system readiness blockers.
- [x] Validate migration wave order + rollback notes
  - 2026-05-31 closed through TAL-12 migration hardening pass. `php artisan migrate:status --no-interaction` reports 39 ran / 0 pending, Boost schema confirms canonical applied tables/columns, and `php artisan migrate:rollback --pretend --no-interaction` shows reverse dependency-safe rollback SQL. Current rollback policy: acceptable for local/UAT reset, production requires backup + forward-fix migration after real school data exists.
- [x] Validate monitoring/alerting coverage
  - 2026-06-01 historical evidence, partially superseded by SDD-00D: the monitoring/failure-handling pass covered installments, PayMongo, health, and queue infrastructure. The former document-request shipping-fee schedule was later removed with the feature.
- [x] Complete Pre-UAT Dependency & UI Audit
  - 2026-06-10 completed through `uat-readiness/Pre-UAT-Dependency-Audit-Plan.md`. The audit maps backend/admin readiness by L0-L3 dependency layer, compares FS/TS contracts against current routes, migrations, seeders, Filament resources, policies, tests, and local data counts, and keeps Linear updates as draft recommendations only.
  - 2026-06-10 scope correction: Pre-UAT is paused. Seed-only readiness is no longer accepted as the final boundary. P1 hardening now blocks UAT until academic foundation behavior, in-system Academic Head grade-change approval, live PayMongo/OCR verification, and controlled import upload/preview/commit are implemented or explicitly descoped.
- [ ] Complete Pre-UAT Hardening Before QA
  - [x] Build academic foundation admin behavior for Programs, Subjects, Curricula/Curriculum Subjects, Terms, Sections, and the minimum safe room input needed by scheduling.
  - [x] Build controlled curriculum/foundation import template download -> upload -> parse -> preview/validation report -> commit -> audit flow with no freeform in-browser row repair.
  - [x] Build in-system Academic Head approval action/queue for official/finalized grade corrections before Registrar resolution applies corrected values.
  - [x] Verify live PayMongo sandbox webhook path posts idempotent payment evidence to ledger.
    - 2026-06-12 passed: `integrations:paymongo-sandbox-checkout` created attempt `2`, the PayMongo-hosted sandbox checkout was completed, `checkout_session.payment.paid` was stored and processed, and `integrations:paymongo-sandbox-webhook-smoke --attempt-id=2 --process-pending` verified one confirmed payment `2`, one linked ledger entry `3`, amount `2000.00`, ledger amount `-2000.00`, and webhook idempotency. The separate `payment.paid` webhook was processed afterward as a duplicate without creating another payment or ledger entry.
  - [x] Verify live Google Cloud Vision OCR path writes OCR evidence and routes low-confidence/failure cases to manual review.
    - 2026-06-12 passed: `integrations:google-vision-ocr-smoke` was run with `TALA_OCR_DRIVER=google_vision` process override and configured Google credentials. Evidence: private source stored, `document_uploads` created/updated, `document_ocr_results` persisted, `ocr_engine=google_vision_document_text_detection`, `status=ocr_extracted`, `ocr_confidence=99.08`. A second live blank-sample run with `--expect=needs_manual_review` persisted manual-review evidence with `ocr_confidence=null` and `processing_error=Google Cloud Vision OCR output requires manual review.`
  - [x] Verify hidden/internal direct URLs remain blocked, especially `SystemSettingResource`.
    - 2026-06-12 passed: `/admin/system-settings` returns 403 even for System Super Admin because `SystemSettingPolicy` denies all abilities.
    - 2026-06-14 correction: `/admin/faq-entries*` is no longer part of the hidden/internal direct URL denial gate. FAQ Entries are a restored, permission-gated System Super Admin content-maintenance surface guarded by `manage-faqs`.
  - [ ] Clean up P1 raw-label UI leaks found during the audit if they affect staff comprehension in foundation/import/approval workflows.
    - 2026-06-12 reassessment: this is the only remaining Pre-UAT Hardening Before QA checklist item after live PayMongo, live OCR, and direct URL denial passed. Next slice should be a focused browser/test audit of foundation, import, and approval UI labels before starting the Pre-UAT Developer/Internal QA checklist.
- [ ] Complete Pre-UAT Developer/Internal QA
  - 2026-06-10 gate update: do not execute this checklist until `Complete Pre-UAT Hardening Before QA` is complete or explicitly descoped. UAT is not the next slice.
  - 2026-06-01 gate added through `uat-readiness/TALA-Pre-UAT-Developer-QA-Checklist-2026-06-01.md`. Developer/internal QA must be executed before staff/client UAT handoff. Failed rows must be logged, fixed/retested under TAL-12 when small/medium, moved to separate Linear issues when large missing-module gaps, or moved to TAL-13 when student-frontend-only.
  - 2026-06-01 checklist artifact updated so every executable `Action` cell uses ordered `Step 1`, `Step 2`, etc. Coverage decision added: the checklist covers all critical backend + Filament Admin Pre-UAT scenarios for TAL-12, while edge-case permutations remain covered by automated tests and issue-log retests.
  - 2026-06-02 local Windows spin-up guide added through `uat-readiness/TALA-Local-Pre-UAT-Spin-Up-Guide.md`. Use separate terminals for `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`, `npm run dev`, and `Get-Content storage\logs\laravel.log -Wait -Tail 80` because native Windows cannot run Laravel Pail without `pcntl`.
  - 2026-06-02 historical evidence, partially superseded by SDD-00D: Pre-UAT coverage was refreshed for admin-role hardening; the former document-request case was later removed with the feature.
  - 2026-06-02 account-name hardening added: `users` now has an applied local canonical name-part migration; Pre-UAT must rerun migration status and retest `DEV-SSA-001` staff user creation plus `DEV-REG-003` walk-in intake using First/Middle/Last/Suffix fields and composed display name.
  - 2026-06-03 student-scope reconciliation added: Pre-UAT may proceed without Student Portal UI, but shared student-domain backend/admin logic remains in scope.
  - 2026-06-14 SDD pivot added: Student Portal routes/pages are not readiness evidence for backend/admin UAT, but TAL-13 backend contracts are now active pre-UAT backend work. Student Hub UI remains deferred.
  - 2026-06-03 System Settings debloat added: `system_settings` remains an internal runtime registry for backend services such as calendar cutover, but the generic Filament settings navigation/direct resource access is hidden/blocked for Pre-UAT. Admission Requirements typed editing is deferred until the public/student admission workflow.
  - 2026-06-03 role/resource reconciliation added: Pre-UAT must verify only System Super Admin can access read-only Roles/Audit, Roles expose no create/edit role route or permission edit form, Import Batch is audit-only until dedicated upload/preview pages exist, grade-correction grade-change resolution records prior Academic Head approval and derives corrected grades from scheme-specific period inputs, and larger unimplemented workflow surfaces are either accepted risk or moved to separate Linear issues before staff/client UAT.
  - 2026-06-03 admin surface debloat added: Official Schedule manual assignment uses typed fields plus conflict validation and blocks direct post-commit editing; schedule changes now use a term-scoped official-meeting select plus typed requested-meeting fields while old/new payloads remain internal snapshots; Document Review has list/view lifecycle actions only; Import Batch Audit has no raw file-path/error-log form; COR Controls, Schedule Drafts, and Service Requests are service-owned list/view lifecycle-action surfaces; Payment Queue and Confirmed Payments are service-owned list/view surfaces with no generic create/edit forms or raw meta/payload editing.
  - 2026-06-06 historical evidence, superseded by SDD-00D: the former Document Request admin surface was hardened before the entire feature was removed from TALA scope.
  - 2026-06-06 raw-input role audit closeout added: FS Appendix F and TS §8.8 now define the final role-by-role audit boundary, priority order, and follow-up contracts. Completed TAL-12A evidence remains implemented hardening; remaining items are no longer open-ended audit work and must be handled as follow-ups: P1 Pre-UAT QA execution, P1 TAL-13 Student Hub modules, P1 typed System Settings pages only where required, P1 Accounting adjustment service/action if needed, P2 Service Request detail-label cleanup, and P2 Academic Head in-system approval decision. Faculty availability workflow was completed later through rescue Slice 11; promissory backend lifecycle was closed later by SDD-06C while its Student Hub UI remains deferred.
  - 2026-06-03 Linear `TAL-15` created to track the larger unimplemented admin surfaces if stakeholders require them before expanded UAT.
  - 2026-06-10 post-lock/deadline faculty availability change requests implemented as active TAL-12 rescue scope. Faculty files requested windows plus reason, Registrar approves/rejects, approved requests create a new locked availability revision for future solver snapshots, and committed schedules still require the separate Schedule Change lifecycle.
  - 2026-06-10 controlled Pre-UAT scenario seed path implemented through `PreUatScenarioSeeder`. Manual QA must run `php artisan db:seed --class=PreUatScenarioSeeder --no-interaction` after migrations and before browser workflow testing so Registrar, Accounting, Faculty, Academic Head, System Super Admin, scheduling, payment, document, grade, service-request, and FAQ rows have usable local/UAT data.
  - 2026-06-10 Filament runtime dependency lock repaired for native PHP 8.2.12. Composer platform PHP is pinned to `8.2.12`; PHP 8.3/8.4-only lock drift was corrected for Symfony HTML sanitizer, Spatie model states, OpenSpout, ZipStream, and Symfony components. The `Dom\HTMLDocument` fatal on Filament notification rendering is covered by `FilamentRuntimeCompatibilityTest`.
- [x] Prepare UAT checklist + sign-off evidence artifact
  - 2026-06-01 prepared through `uat-readiness/TALA-UAT-Checklist-Signoff-2026-06-01.md`. The UAT package uses the requested test-case scenario table with Pass/Fail, Actual Input, ISO 25010 Product Quality Component, comments/suggestions, issue-log, and sign-off sections while preserving FS/TS traceability. This is a prepared staff/client UAT artifact only. It must be refreshed after Pre-UAT Developer/Internal QA if QA changes actual behavior. Actual staff signatures remain pending UAT execution.
- [x] Prepare go-live cutover runbook artifact
  - 2026-06-01 prepared through `uat-readiness/TALA-Go-Live-Cutover-Runbook-2026-06-01.md`. The runbook defines required operational values, go/no-go criteria, pre-go-live gates, T-7/T-1/T-0 cutover steps, Laravel maintenance/deploy/cache/queue/scheduler commands, smoke checks, integration launch controls, rollback/forward-fix policy, communication plan, and final sign-off matrix. This is a launch-planning artifact only. Actual production launch approval remains pending Pre-UAT Developer/Internal QA, staff UAT signatures, target host/domain, backup location, live PayMongo/GCV decisions, and named operational owners.

**DoD**
- [ ] Pre-UAT Developer/Internal QA passes critical backend/admin flows
- [ ] UAT passes critical flows
- [x] Release readiness artifacts documented

---

## Active Backend Closure + Deferred Student UI (`TAL-13` Split Scope)

**Scope Boundary**
- Backend contracts needed for student-domain data are now part of the active pre-UAT backend/admin closure.
- Student Hub UI, PWA presentation, and student-facing page buildout remain deferred until these backend contracts are stable or explicitly descoped.
- The backend contracts must consume shared backend/admin policy already built for TAL-12/TAL-12A instead of redefining enrollment, finance, document, grade, or gate policy.
- Existing `/student/*` routes are protected by authenticated active-student middleware and the Student Hub Help page is data-backed by published FAQ entries. Dashboard, schedule, grades, financials, and documents pages remain placeholder UI surfaces, but their read-only dashboard aggregate is now backed by `StudentDashboardService` for the deferred UI phase.
- Linear should split this scope logically: TAL-13 backend contract work is an active dependency for UAT readiness; TAL-13 Student Hub UI remains backlog/deferred.

### Part A: Completed Backend Contracts
- [x] Implement `ApplicantIntakeService` (Public registration to pending applicant)
  - 2026-06-17 SDD-05A backend contract implemented: `applicant_intakes` stores pre-handover applicant profile/status/required-document/duplicate-check evidence; pending applicant `users` rows receive the `applicant` role and remain blocked from protected Student Hub/staff areas; applicant-owned `document_uploads` link through `applicant_intake_id` with no `student_profile_id` until Official Handover; OCR dispatch uses the existing `ProcessDocumentOcrJob`; approval-for-payment is blocked until every required applicant document is Registrar-approved. Focused test: `php artisan test --compact tests/Feature/ApplicantIntakeServiceTest.php`.
- [x] Implement `StudentEnrollmentService` (One-Click Enroll auto-promotion for Regulars)
  - 2026-06-17 SDD-05B backend contract implemented: approved applicant intakes can be bridged into official `student_profiles` and `enrollments` without activating Student Hub access before finance clearance; applicant documents are linked to the official profile during handover; regular enrollment blocks outstanding balances, detects returnees, and assigns compatible delivery groups through the existing locked-capacity sectioning service; Accounting manual payment clearance now delegates account handover to `StudentEnrollmentService`, assigning the student role, Student ID username, active status, and COR readiness evidence. Focused tests: `php artisan test --compact tests/Feature/StudentEnrollmentServiceTest.php tests/Feature/PaymentConfirmationServiceTest.php`.
  - 2026-06-17 PayMongo parity follow-up closed before the next TAL-13 slice: webhook-confirmed PayMongo payments now use the same shared finance-clearance/account-handover rule when the payment attempt is linked to a real enrollment. Focused tests: `php artisan test --compact tests/Feature/PayMongoWebhookFinanceClearanceTest.php`, `php artisan test --compact tests/Feature/PayMongoWebhookMockContractTest.php`, and `php artisan test --compact tests/Feature/TAL12MonitoringCoverageTest.php`.
- [x] Implement `SubjectSuggestionService` (Prerequisite enforcement & back-subject suggestion for Irregulars)
  - 2026-06-18 SDD-05C backend contract implemented: `SubjectSuggestionService` resolves the enrollment curriculum scope, returns suggested current subjects, back subjects, blocked subjects, already-passed current subjects, setup blockers, and summary counts; consumes Registrar-finalized grade history plus active INC rows; uses the latest relevant attempt per subject; blocks active INC, finalized failed prerequisites, and missing historical prerequisite grades; and leaves approved-equivalent/credited-subject satisfaction blocked until controlled equivalency/credit-evaluation records exist. Focused test: `php artisan test --compact tests/Feature/SubjectSuggestionServiceTest.php`.
- [x] Implement `StudentDashboardService` (Aggregated view of schedule, balance, grades, requests)
  - 2026-06-18 SDD-05D backend contract implemented: `StudentDashboardService` returns student-owned profile/enrollment context, current schedule, financial balance and term summaries, latest confirmed payments, finalized grades, recent document/service requests, recent grade-correction requests, dashboard holds, latest notifications, and published FAQ/help links. It prevents cross-student leakage and returns stable empty sections when no current enrollment exists. Focused test: `php artisan test --compact tests/Feature/StudentDashboardServiceTest.php`.

### Part B: Deferred Frontend UI
- [ ] Build student portal pages (Livewire/TallStack) against the backend contracts
- [ ] Implement student-authenticated enrollment/ledger/document views
- [ ] Implement student request submission and timeline views
- [ ] Add student-side policy messaging (non-clearing promissory, gate locks, payment deadlines)
- [ ] Add frontend integration tests for end-to-end student journeys
  - 2026-06-06 partial access/FAQ coverage added: `StudentHubAccessTest` verifies guest redirect, inactive-student denial, active-applicant denial, active-student access to all `/student/*` pages, and published-only Student Hub FAQ rendering. `PublicFaqPageTest` verifies guest access to `/faq`, published-only public FAQ rendering, and no authenticated student middleware on the public route. This does not close TAL-13 end-to-end student journeys because the core dashboard/enrollment/ledger/document/grade/request pages remain placeholder or missing service-backed workflows.

**DoD**
- [x] Student self-service backend logic is test-verified for the current SDD-05 backend-contract set
- [ ] Student UI uses stable backend contracts without policy drift
- [ ] Student journeys are test-verified end-to-end

---

## Active Accounting Backend/Admin Closure (`SDD-06`)

- [x] **SDD-06A - Assessment/downpayment proof**
  - 2026-06-18 executable coverage verifies most-specific active fee-template selection, 50% tuition-only freshmen discount posting, immutable running balances, repeat-assessment idempotency, and configured minimum-downpayment boundary behavior. A below-threshold payment remains `pending_payment`; reaching the threshold exactly transitions through shared finance clearance and account handover. Focused test: `php artisan test --compact tests/Feature/EnrollmentAssessmentServiceTest.php`. Linear mirror: `TAL-24` (Done), linked to the `TAL-12` readiness gate.
- [x] **SDD-06B - Payments/ledger closure**
  - 2026-06-18 payment/ledger closure implemented and executable-test verified: Accounting manual confirmation uses shared allowed channels, normalized unique references, and non-future payment dates; enrollment payments additionally require prior assessment; payment, ledger credit, balance, clearance/handover, and audit effects are atomic; the document-shipping action cannot bypass the shared manual-payment trust boundary; overpayment remains a standard payment with negative balance; PayMongo attempts link to their ledger entry; webhook failures are rethrown for queue retry; duplicate provider events remain idempotent; and payment/payment-attempt/ledger resources remain list/view-only. Linear mirror: `TAL-25` (Done), blocking the `TAL-12` readiness gate and related to `TAL-24`.
- [x] **SDD-06C - Promissory lifecycle closure**
  - 2026-06-18 promissory lifecycle closure implemented and executable-test verified: applicant/student-owner backend requests, Accounting staff-assisted pending creation, approve/reject/cancel actions, one-open-request-per-enrollment enforcement, payment-driven settlement, 00:45 deadline processing, and separate RA 11984/institution-discretion exam access accommodations. Promissory notes remain non-payment evidence; real confirmed payments still clear finance when eligible. Focused tests: `php artisan test --compact tests/Feature/PromissoryNoteLifecycleServiceTest.php tests/Feature/ExamAccessDecisionServiceTest.php tests/Feature/SDD06CPromissoryFilamentWorkflowTest.php tests/Feature/PaymentConfirmationServiceTest.php tests/Feature/InstallmentPolicyServiceTest.php`. Adjacent regression proof also passed for TAL-12 accounting resources, monitoring coverage, dashboard/faculty privacy, PayMongo finance clearance, PayMongo webhook contracts, and promissory form scoping. Linear mirror: `TAL-26` (Done), linked to the `TAL-12` readiness gate and related to `TAL-25`.
- [x] **SDD-06D - Accounting adjustments closure**
  - 2026-06-18 accounting adjustment closure implemented and executable-test verified: `AccountingAdjustmentService` is the sole manual correction path, guarded by `post-accounting-adjustments`; supported types are debit, credit, and ledger-entry reversal; reversals post the exact opposite of the source ledger entry and block duplicate reversal; each post writes one `accounting_adjustments` audit row, one immutable `ledger_entries` row, updates `student_profiles.current_balance`, and writes activity-log evidence. `AccountingAdjustmentResource` is list/create/view only, create delegates to the service, and generic ledger CRUD remains forbidden. Adjustments do not create refunds or silently undo finance-cleared account handover/enrollment access. Focused tests: `php artisan test --compact tests/Feature/SDD06DAccountingAdjustmentServiceTest.php` and `php artisan test --compact tests/Feature/TAL12AAccountingFilamentResourceTest.php`. Linear mirror: `TAL-27` (Done), linked to the `TAL-12` readiness gate and related to `TAL-26`.
- [x] Mirror completed SDD-06A/06B/06C/06D evidence to Linear as `TAL-24`, `TAL-25`, `TAL-26`, and `TAL-27`; continue creating one testable Linear issue per subsequent SDD slice.

---

## Active Admission Documents and Enrollment Handover Closure (`SDD-07A` / `TAL-28`)

- [x] Detect and inventory the 2026-06-19 comprehensive rewrite of `INSTITUTION WORK  FLOW CURRENT.md` (734-line consolidated manual replacing the prior 3,463-line compilation).
- [x] Confirm the refined authority boundary: the consolidated workflow is the newest approved business baseline, while compatible or stronger FS/TS/system decisions remain valid. Reconcile conflicts feature-by-feature rather than replacing either source wholesale.
- [ ] Replace the stale blanket seven-day, sectionless temporary-enrollment contract with the approved upfront admission-gate plus 30-to-60-day retention-document workflow.
  - 2026-06-19 benchmark: DepEd Order No. 017, s. 2025 recognizes temporary enrollment with documentary deficiencies and school-to-school/electronic credential-transfer paths for basic education. Reopen the blanket SHS all-physical, seven-day, no-access rule; keep College behavior as a separate decision.
- [x] Revalidate only directly affected prior slices: SDD-05C progression rules, SDD-06C promissory cap/approval, SDD-06D refund policy, SDD-07A handover/capacity, and SDD-08 grading/privacy. Preserve unaffected implementation evidence.
  - 2026-06-19 traceability evidence: `TALA-Workflow-Reconciliation-Matrix.md` classifies each requirement as aligned, stronger-compatible, conflict, missing, external boundary, or benchmark gate and assigns an SDD owner/dependency.
- [ ] Update normative FS/TS text only after each conflict is resolved; then revise SDD targets, checklist acceptance criteria, Linear, migrations/services, and focused tests in that order.
- [x] Audit `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` against the FS, TS, SDD map, and code; classify the consolidated workflow as authoritative over earlier conflicting decisions unless a newer clarification explicitly supersedes it.
- [x] Keep SDD-07C enrollment adjustments, SDD-07D record/status lifecycle, and SDD-07E graduation evaluation as typed future slices instead of treating generic `service_requests` resolution as implementation. SDD-07B document fulfillment is retired by SDD-00D.
- [ ] Revalidate the SDD-07A payment/capacity sequence against the approved pre-payment section/schedule preparation and OR-secured slot workflow; preserve the external LIS/CHED boundary, generic roster, versioned requirements, and assistive-only OCR where compatible.
- [ ] Replace hardcoded applicant document lists with Registrar-managed, versioned, scope-resolved requirement sets.
- [x] Lock the generic admissions architecture: one lifecycle; term-scoped published offerings; composable dimensions for education level, entry route, prior credential, citizenship/compliance, program/grade, and purpose-limited support attributes; inactive unsupported offerings; deterministic fail-closed requirement resolution.
  - 2026-06-19 benchmark evidence: Oracle PeopleSoft checklist configuration and Frappe Education's admission-offering -> applicant decision -> student creation -> program-enrollment separation. Flat mutually exclusive `applicant_type` branching is superseded; IP and disability/SEN are not applicant routes.
  - 2026-06-19 Linear mirror: the locked architecture, benchmark basis, implementation boundary, and next SDD-07A decisions were posted to `TAL-28`.
- [x] Lock initial intake exposure after SDD-00C: publish only College Freshman and College Transfer paths for active deployment; keep SHS, cross-enrollee, and unapproved ALS/equivalency routes inactive; represent old curriculum, foreign compliance, and IP/SEN support through composable College rules rather than separate pipelines.
- [x] Lock returning-student boundary: use Registrar-assisted lookup/readmission; when no reliable pre-TALA profile exists, create a provenance-tagged legacy baseline after duplicate review, then continue through SDD-07D readmission rather than public new-applicant creation.
  - 2026-06-19 Linear mirror: initial offerings, composable profile treatment, inactive cross-enrollee status, legacy onboarding limits, and SDD-07A/07D ownership were posted to `TAL-28`.
- [ ] Materialize per-applicant digital-review and physical-receipt checklist states for both self-service and Registrar-assisted submissions.
- [x] Lock the hybrid document-storage classification: credentials and transaction evidence retain versioned private originals; official transmissions preserve provenance; ID photos disable OCR; medical/SEN/IP/immigration files use restricted purpose-limited access; generated documents retain immutable issuance snapshots; structured records remain authoritative; imports and OCR/previews remain controlled derivatives; physical custody is recorded separately from scans.
  - 2026-06-19 benchmark basis: DepEd's revised enrollment policy requires regulator-aware documentary satisfaction methods; OWASP file-upload guidance supports allowlisting, detected-type validation, generated names, private storage, authorization, size limits, and malware scanning; Philippine data-protection rules require purpose limitation and non-indefinite retention.
  - 2026-06-19 Linear mirror: the locked document classes, security/OCR boundaries, and remaining Regular SHS requirement decision were posted to `TAL-28`.
- [ ] Enforce per-requirement evidence methods: current physical/original/certified requirements plus regulator-permitted official school-to-school electronic satisfaction; applicant uploads and OCR remain preliminary unless the configured requirement and regulator permit otherwise.
- [ ] Add configurable 30-to-60-day retention undertakings, monitoring, reminders, overdue holds, receipt resolution, and regulator-specific deadlines; remove the stale universal seven-day default.
- [ ] Reconcile tentative pre-payment section/schedule placement with OR-secured capacity, active access, and institution-caused placement shortfalls.
- [ ] Collapse runtime `pre_enrolled` / `officially_enrolled` values into canonical `enrolled`; migrate references and remove LIS-only schema/projections/actions.
- [ ] Replace COR control's `manage-lis` coupling with dedicated `manage-cor-verifications` authorization.
- [ ] Add a read-only Filament v5 Enrolled Student Roster requiring term selection, optional level/program/year/section/modality/student-type filters, and audited generic CSV/XLSX export; regulator-specific templates remain outside MVP.
- [ ] Replace the stale strict no-refund profile with the approved 15-day admission/enrollment-fee refund window and non-refundable tuition after official enrollment, implemented through effective-dated typed financial-disposition policies.
- [ ] Add focused PHPUnit coverage for happy paths, authorization, deadline/capacity races, payment-channel parity, migration behavior, external-reporting roster scope, and failure paths.
  - 2026-06-18 decision record: DepEd LIS encoding remains an external Registrar activity. TALA does not track encoded/error status, provide a mark-as-encoded action, or use LIS completion as an enrollment transition.
  - 2026-06-18 Linear mirror: `TAL-28` is In Progress, high priority, assigned to Kyle Baluyot, under `TALA Iterative Implementation Map (DB-First)`, and related to `TAL-12` / `TAL-27`.
  - 2026-06-18 Linear sync limitation: `TAL-28` creation succeeded with the approved scope, but the follow-up `enrolled_at` migration addendum could not be posted because the connector returned `401 token_revoked`. The TS remains authoritative for that addendum until Linear OAuth is reconnected.
  - 2026-06-19 roster-output decision: one generic TALA roster schema is exportable as CSV or XLSX; separate DepEd LIS/CHED templates and external-system completion tracking are excluded from MVP.
  - 2026-06-19 Linear sync restored: the roster-output decision and previously missed `enrolled_at` migration addendum were posted to `TAL-28`; local and Linear scope are aligned again.
  - 2026-06-19 institutional-workflow audit (superseded classification): the initial audit treated several workflow values as unsupported proposals; the project-manager/client authority clarification below replaces that classification.
  - 2026-06-19 Linear mirror: the institutional-workflow audit, retained SDD-07A decisions, reopened payment/capacity ordering, and future SDD-07B through SDD-07E boundaries were posted to `TAL-28`.
  - 2026-06-19 comprehensive rewrite notice: SDD-07A implementation is requirements-blocked until the manual/SRS authority boundary and admission-document split are confirmed. This is an impact-based rebaseline, not a restart of completed SDD work.
  - 2026-06-19 Linear mirror: the comprehensive rewrite, affected-slice list, requirements block, and first authority decision were posted to `TAL-28`.
  - 2026-06-19 authority decision (superseded): the earlier classification treated the workflow as evidence/proposals rather than the newest approved baseline.
  - 2026-06-19 corrected authority decision: the project manager prepared the consolidated workflow from the client's exact current business flow, making it the newest approved business baseline. Compatible FS/TS improvements remain; only incompatible behavior is reopened. Benchmarking refines the approved outcome into a compliant, secure, and adaptable system contract.
  - 2026-06-19 reconciliation refinement: do not copy the manual literally or discard prior FS/TS wholesale. For each feature, preserve stronger compatible controls, identify the exact conflict or gap, benchmark where material, resolve remaining ambiguity, then update FS/TS and code.
  - 2026-06-19 Linear correction: `TAL-28` was renamed and its main description replaced so Linear now mirrors the approved admission-gate/retention, placement/payment, capacity, refund, and external-reporting baseline; an authority-correction comment supersedes earlier conflicting comments.
  - 2026-06-19 Linear reconciliation refinement: `TAL-28` now explicitly preserves stronger compatible FS/TS controls and reopens only incompatible behavior; the workflow is an approved business baseline, not a literal replacement implementation.

### Comprehensive Workflow Reconciliation Audit - 2026-06-19

- [x] Inventory all 734 lines of the consolidated workflow across enrollment, Registrar, faculty, Accounting, and Academic Head operations.
- [x] Cross-reference workflow outcomes against FS/TS sections, current models/migrations/services/resources, and available PHPUnit evidence.
- [x] Create `TALA-Workflow-Reconciliation-Matrix.md` as the active requirement-to-SDD traceability artifact.
- [x] Benchmark SHS weights and progression against DepEd Order No. 8, s. 2015; reject the conflicting simplified workflow rows as direct implementation rules.
- [x] Reconfirm DepEd temporary-enrollment/document-transfer implications through DepEd Order No. 17, s. 2025.
- [x] Reconfirm RA 11984 scope: no debt-based scheduled-exam denial for the current institution; promissory/accommodation evidence remains separate from finance clearance; collection and lawful record holds remain available.
- [x] Correct the docs index authority rule, FS stale physical-document/exam/progression language, TS admission/state/exam/grading holds, SDD dependency order, and module quick-reference rows.
- [ ] Grill and approve the first SDD-07A open decision: exact admission-gate versus retention-document items for each supported applicant scope.
- [x] Supersede the Regular SHS requirement contract under SDD-00C: retain it only as archived historical evidence; active deployment uses College freshman/transfer requirement contracts.
  - 2026-06-19 boundary: school-to-school transmission remains an external/manual channel with TALA receipt/provenance tracking; undertakings are generated and monitored inside TALA; alternative identity evidence originates externally but uses TALA's private review lifecycle.
  - 2026-06-19 Linear mirror: `TAL-28` now records the approved Regular SHS contract and narrows its open-decision list to the remaining offerings/pathways and tentative-placement expiry.
- [x] Approve the Regular College Freshman requirement contract: verified identity, Grade 12/prior-education completion or eligibility, and Good Moral are admission outcomes for the current deployment; diploma/completion evidence and one normalized ID-photo obligation are retention/follow-up; entrance-exam results are structured internal records with no score-based denial until an effective-dated approved policy exists.
  - 2026-06-19 Linear mirror: `TAL-28` records this approved contract; SHS Transfer is the next unresolved public-offering profile.
- [x] Supersede the SHS Transfer requirement contract under SDD-00C: retain it only as archived historical evidence; active deployment uses College Transfer requirement handling.
  - 2026-06-19 transmission boundary: the sending school and Registrar communicate externally; TALA records receipt/provenance and stores a received electronic artifact privately or a physical-only custody event without forcing a scan.
  - 2026-06-19 Linear mirror: `TAL-28` records the approved SHS Transfer contract; College Transfer is the next unresolved public-offering profile.
- [x] Approve the College Transfer requirement contract: admission requires verified identity, sufficient preliminary academic evidence, transfer-release eligibility/Honorable Dismissal, and Good Moral; final TOR, remaining distinct transfer evidence, and one ID-photo obligation are retention/follow-up; generic transfer-credential labels cannot duplicate Honorable Dismissal or final TOR.
  - 2026-06-19 Linear mirror: `TAL-28` now records all four approved initial public-offering contracts; composed prior-credential/compliance/support rules remain open.
- [x] Benchmark and approve the old-curriculum boundary: Old Curriculum College composes with Regular College Freshman and substitutes verified old-high-school College-eligibility evidence; Form 137 is not duplicated and Certificate of Completion is not universal. Old Curriculum SHS remains inactive trace evidence only, with no public route, no resolver fallback, and no runtime requirement list in the current deployment.
  - 2026-06-19 benchmark: CHED transition guidance confirms old-curriculum high-school graduates remain College-eligible subject to HEI requirements and possible bridging/special assessment; MMDC requires old-curriculum Form 138 showing first-year College eligibility, PSA, Good Moral, and photo; Mindoro State University explicitly accepts old-curriculum graduates without SHS as College applicants. The client clarification matches this College pathway rather than a separate SHS route.
  - 2026-06-19 Linear mirror: `TAL-28` records the approved College pathway, inactive SHS template, benchmark basis, and fail-closed behavior.
- [x] Benchmark and bound the ALS/equivalency pathway after SDD-00C: do not publish ALS as an active route. Future College ALS/equivalency handling remains inactive until the institution approves a College offering and evidence rule.
  - 2026-06-19 benchmark: DepEd LIS support identifies ALS portfolio assessment as an eligibility requirement for Grade 11 and records Grade 11 enrollment using Junior High School-level portfolio-assessment passer evidence, subject to Division-level approval. CHED transition guidance treats old-curriculum learners as College eligible, reinforcing that old-curriculum-to-SHS is not the default fallback.
  - 2026-06-19 documentation update: FS/TS now require ALS duplicate-purpose deduplication across Certificate of Rating, Certificate of Completion, and portfolio-assessment evidence, and keep Old Curriculum SHS inactive as trace evidence only unless a future adult/remedial SHS route is separately approved.
- [x] Benchmark and approve the foreign-compliance boundary: foreign status is a composable compliance profile, inactive by default for MVP, and publishable only after the institution confirms it accepts foreign applicants for the scoped term/offering and activates legal stay/study authorization evidence rules.
  - 2026-06-19 benchmark: Bureau of Immigration Student Visa 9(f) applies to foreign nationals at least 18 taking higher-than-high-school study; BI/DFA guidance also distinguishes Special Study Permit or other legal-stay handling for applicable non-degree, exchange, minor/basic-education, or short-term cases. TALA records evidence and verification only and does not integrate with immigration, DFA, CHED, DepEd, or embassy systems.
  - 2026-06-19 documentation update: FS/TS now treat passport, visa, permit, immigration, medical, and English-proficiency evidence as restricted compliance files with purpose-limited access and audit.
- [x] Benchmark and approve IP/SEN support-profile rules: IP and disability/SEN are optional purpose-limited support attributes, not applicant routes or admission-denial dimensions. Support evidence is collected only for configured accommodations, scholarships/support programs, culture-responsive coordination, safety planning, or legally required inclusive-education services.
  - 2026-06-19 benchmark: RA 11650 frames learners with disabilities through inclusion and support services; IPRA and DepEd IPEd policy frame Indigenous status through culturally responsive access and participation; Philippine data-protection rules require sensitive health/ethnicity-style evidence to be purpose-limited and secured.
  - 2026-06-19 documentation update: FS/TS now require restricted support-file controls, role-scoped access, audit, no default faculty/Accounting exposure, and no automated rejection/ranking/sectioning/billing/discipline/public-reporting use without a later approved rule.
- [x] Benchmark and approve tentative-placement expiry, seat-consumption, readiness order, and College capacity handling.
  - 2026-06-19 benchmark: mature SIS behavior separates term/session/enrollment dates, schedule/class readiness, planning validation, and actual enrollment/capacity. TALA therefore blocks payment clearance and official handover until term/calendar, offering/policy, capacity plan, curriculum/subjects, sections/delivery groups, faculty availability/eligibility, and schedule readiness are complete or explicitly overridden.
  - 2026-06-19 capacity rule, updated by SDD-00C: the current institution may seed a top-level 100-active-student campus cap, while College program/year/delivery caps are optional effective-dated sub-plans. OR-backed payment secures capacity against every matching plan; tentative pre-payment placement consumes no protected capacity and expires at payment deadline, admission-window close, or Registrar cancellation.
  - 2026-06-19 applicant-protection rule: if capacity is secured by OR but exact section/delivery placement fails because of institution-caused delay or planning failure, route to `PendingInstitutionalPlacement`/Registrar resolution instead of silent rejection.
- [x] Mirror the reconciliation matrix summary and dependency-ordered reopened deltas to Linear without marking implementation complete.
  - 2026-06-19 Linear evidence: `TAL-28` description now contains the audit status, corrected architecture, runtime gaps, open SDD-07A decisions, dependency order, and implementation DoD; `TAL-28` and `TAL-12` received audit/readiness comments. Both issues remain In Progress and no future placeholder issues were created before their contracts are approved.
- [x] Start SDD-07A implementation with the resolver/checklist foundation.
  - 2026-06-19 code evidence: added `AdmissionOffering`, `AdmissionRequirementPolicy`, `DocumentRequirementItem`, and `ApplicantDocumentRequirement` models, migrations, and factories; added `AdmissionRequirementResolver`; replaced `ApplicantIntakeService` hardcoded document resolution with fail-closed published-offering/policy resolution; materialized applicant checklist snapshots; linked applicant uploads to checklist rows; admission-gate items now block Registrar evaluation/payment approval while retention items do not; document review syncs satisfied/correction/rejection state back to the checklist.
  - 2026-06-19 test evidence: `php artisan test --compact tests/Feature/ApplicantIntakeServiceTest.php`, `php artisan test --compact tests/Feature/DocumentUploadReviewServiceTest.php`, `php artisan test --compact tests/Feature/StudentEnrollmentServiceTest.php`, and `php artisan test --compact tests/Feature/PaymentConfirmationServiceTest.php` passed.
- [x] Implement the SDD-07A retention undertaking lifecycle backend.
  - 2026-06-19 code evidence: added `RetentionDocumentUndertaking` model/migration/factory, `RetentionDocumentUndertakingService`, `ProcessRetentionDocumentUndertakingsJob`, and daily scheduled processing; applicant approval creates itemized active undertakings for pending retention checklist rows; applicant handover attaches student/enrollment context; Registrar document approval resolves matching undertakings; deadline processing marks active overdue rows with documentary/next-cycle hold evidence without canceling the intake/enrollment.
  - 2026-06-19 test evidence: focused applicant lifecycle coverage verifies undertaking creation, non-blocking retention behavior, review-driven resolution, and overdue processing; adjacent document review, enrollment, and payment tests passed.
- [x] Implement the SDD-07A configured capacity reservation backend.
  - 2026-06-19 code evidence: added `AdmissionCapacityPlan` and `AdmissionCapacityReservation` models/migrations/factories plus `AdmissionCapacityReservationService`; finance clearance now resolves and locks every matching approved capacity plan, creates OR-backed `secured` reservations per enrollment/plan, increments `reserved_count` idempotently, and rolls back payment/ledger posting when any configured plan is full.
  - 2026-06-19 compatibility boundary: capacity reservation is a no-op only when no approved capacity plans exist, preserving legacy/local tests until the remaining readiness/admin setup makes capacity plans mandatory before payment opens.
  - 2026-06-19 test evidence: `PaymentConfirmationServiceTest` verifies stacked plan reservation, idempotent retries, and full-capacity rollback; adjacent applicant, enrollment, and PayMongo finance-clearance tests passed.
- [x] Implement the SDD-07A mandatory backend readiness gate before finance-cleared handover.
  - 2026-06-19 code evidence: added `AdmissionFinanceReadinessGateService` and wired it into `EnrollmentFinanceClearanceService` before capacity reservation and handover. Materialized SDD-07A applicant-checklist enrollments now require published admission offering/policy setup, configured enrollment/payment calendar fields, an approved matching admission-capacity plan, term scheduling readiness, and a published schedule with official meetings before payment clearance can secure capacity or activate handover.
  - 2026-06-19 compatibility boundary: the readiness gate no-ops for legacy enrollments without a materialized applicant checklist, preserving existing regular/manual enrollment behavior until those flows are migrated into SDD-07A setup.
  - 2026-06-19 test evidence: `php artisan test --compact tests/Feature/PaymentConfirmationServiceTest.php` verifies missing-capacity rollback, missing-published-schedule rollback, and the configured happy path; `php artisan test --compact tests/Feature/StudentEnrollmentServiceTest.php` verifies adjacent applicant handover compatibility.
- [x] Implement the first SDD-07A Filament admin setup surfaces.
  - 2026-06-19 benchmark/application evidence: used Filament v5 Resource/Schemas/Tables/Infolist conventions and Laravel policy authorization instead of a custom admin workflow. Added Registrar setup resources for admission offerings, admission requirement policies, document requirement items, and admission capacity plans, all under `manage-admission-setup`; global viewers retain read-only access through policy, and delete/bulk-delete actions are intentionally absent so setup rows are retired/versioned rather than destroyed.
  - 2026-06-19 code evidence: setup forms expose term-scoped offering dimensions, policy version/effective approval fields, per-requirement gate/evidence/storage/OCR fields, and effective capacity-plan scope/limit fields. `reserved_count` remains read-only on capacity plans and is still updated only by OR-backed finance clearance.
  - 2026-06-19 test evidence: `php artisan test --compact tests/Feature/SDD07AAdmissionSetupFilamentResourceTest.php` verifies Filament route access, typed fields, no delete actions, policy behavior, and Registrar seeder permission. Adjacent SDD-07A backend and Filament compatibility tests also passed.
- [x] Implement the SDD-07A admission readiness dashboard.
  - 2026-06-19 benchmark/application evidence: external enrollment-management dashboard patterns emphasize centralized access, workflow checklists, resource/capacity visibility, fill-rate/enrollment context, and bottleneck/hold visibility. The TALA implementation keeps the first pass operational and read-only: term filter, offering readiness status, blocker categories, active policy/item counts, capacity remaining, and drilldown links to setup resources.
  - 2026-06-19 code evidence: added `AdmissionReadinessDashboardService` plus the Registrar `AdmissionReadinessDashboard` Filament page under `manage-admission-setup`/`view-global-records`. The service reuses the term scheduling readiness contract, checks published offering/policy setup, requirement items, calendar fields, approved matching capacity plans, published official schedules, and capacity remaining without mutating setup, capacity, schedule, or applicant records.
  - 2026-06-19 test evidence: `php artisan test --compact tests/Feature/SDD07AAdmissionReadinessDashboardTest.php` verifies Registrar page access, ordinary-staff denial, blocked setup reporting, and configured ready-offering reporting.
- [ ] Continue SDD-07A implementation: tentative placement expiry, canonical enrollment-state cleanup, enrolled-roster export, extension/reminder UI, and notification delivery.

---

## Execution Rule

- Complete iterations in order unless explicitly re-prioritized.
- Always execute the **Spec-First Gate** before any implementation task.
- Do not mark F1/F10 done until behavior is code-enforced and PHPUnit-covered.

