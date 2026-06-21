# TALA SDD Execution Map

**Purpose:** Active spec-driven execution map for finishing the smallest working SIS core before UAT, then continuing deferred SDD slices in dependency order.
**Last Updated:** 2026-06-22
**Scope:** UAT rescue baseline first for the College-only deployment: role access, applicant intake, admission documents, enrollment/section/finance clearance, student record, faculty class/grade operation, read-only student visibility, and completion/graduation boundary.
**Status:** Active execution map under the 2026-06-21 UAT rescue overlay. Scheduling/curriculum decisions from the 2026-06-17 audit remain locked unless the user reopens a specific decision.

---

## Authority

This map is the current execution control document after the 2026-06-19 consolidated-workflow reconciliation audit. It does not override FS/TS. Each SDD slice must still pass the listed FS/TS/code audit before implementation starts.

1. `TALA-Functional-Specification.md` defines business workflows and role boundaries.
2. `TALA-Technical-Specification.md` defines service, schema, UI, security, and verification contracts.
3. `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` is the newest client-approved College-only business baseline; other active evidence supplies College forms/sheets for field and policy validation.
4. `TALA-Specification-Benchmarking-Process.md` defines the repeatable feature-group process for benchmark-hardening FS/TS before implementation.
5. `TALA-Workflow-Reconciliation-Matrix.md` records the requirement-by-requirement FS/TS/code classification, benchmark gate, SDD owner, and dependency order.
6. The Laravel codebase proves current implementation state through migrations, models, services, policies, Filament resources, and tests.
7. `TALA-Local-Iteration-Checklist.md` and Linear mirror current execution state.

If a refinement list, archived plan, old prototype, or previous grilling-generated iteration conflicts with this map, this map wins for current execution. Older refinement files stay historical unless a specific item is re-entered here as a module-feature slice or a linked Linear child issue.

---

## UAT Rescue Overlay (2026-06-21)

The rescue period does not cancel the SDD plan. It changes execution priority.

Before starting any new implementation slice, classify it against `TALA-UAT-Rescue-Plan-2026-06-21.md`:

| Classification | Execution meaning |
| --- | --- |
| `Core` | Required for the applicant-to-completion SIS flow; can block UAT if broken or absent from the demo path. |
| `Core-lite` | Needed for proof of visibility or role experience, but may be read-only or limited for UAT. |
| `Supporting` | Useful and may remain visible if already stable, but not allowed to consume time ahead of broken Core work. |
| `Deferred` | Preserved as future/research value; do not implement during rescue unless promoted by user decision. |
| `External Boundary` | Keep evidence/export/status only; do not build external agency or unapproved third-party automation unless required by the current demo path. |

Rescue execution order:

1. Stabilize authentication and role-specific access.
2. Stabilize the applicant/admission/enrollment/finance handover path.
3. Stabilize official student record and read-only student visibility.
4. Stabilize faculty class list and grade operation enough to prove post-enrollment academic flow.
5. Provide a completion/graduation boundary or status proof without building full diploma/government-submission automation.
6. Update UAT testable-now rows, local checklist, reconciliation matrix, and Linear after each factual change.
7. When a feature's FS/TS contract is unclear, run the benchmarking process first instead of coding from assumptions.

### Specification Baseline Completion Overlay (2026-06-21)

The benchmark-hardening queue in `TALA-Specification-Benchmarking-Process.md` is complete for Feature Groups 1-11. This closes the FS/TS goal-state coherence pass only. It does not close any SDD implementation item, change executable-test status, or promote deferred work. Continue implementation from the active target and dependency order recorded near the end of this map, using the new FS §2.3 and TS §1.4 contracts as acceptance and technical boundaries.

Feature Group 4 passed its deep submission-lock audit on 2026-06-21. Scheduling remains implemented and supporting unless the selected UAT path depends on it, but two factual runtime corrections remain owned by the scheduling slices: distinguish `model_invalid` from timeout, and allow an approved schedule-change lifecycle Apply after publication while keeping direct creation/edit blocked.

Feature Group 5 passed its deep submission-lock audit on 2026-06-21. Existing SDD-06A-D posting behavior remains valid, but "closed" applies only to those implemented slices, not the complete finance goal state. SDD-06E owns effective-dated fee structures and assessment snapshots, OR/SOA issuance, materialized-balance rebuild proof, duty segregation, private reminders, and daily three-way reconciliation; SDD-06F owns component-level disposition/refund execution. Assessment must stop writing `enrolled_at`, which remains an official-handover field under SDD-07A.

Feature Group 7 was rebaselined on 2026-06-21. COR remains the UAT-core generated academic artifact after canonical enrollment. The document-request portal/catalog/fee/fulfillment/courier domain is permanently removed under SDD-00D. SDD-07A/SDD-08A own COR issuance/PDF/QR/public verification and dedicated COR permission cleanup; SDD-06E owns OR/SOA evidence; SDD-07E owns diploma/certificate release after graduation evaluation. Other generated artifacts remain owned by their authoritative source workflows.

Feature Group 8 passed its deep submission-lock audit on 2026-06-21. Student Hub/PWA remains UAT Core-lite: route protection, active-student middleware, published Help/FAQ, layout PWA directives, and the `StudentDashboardService` backend aggregate are valid evidence. Runtime gaps remain that prevent manual pass of most Student Hub rows: five pages are placeholder/static rather than service-backed, student mutation forms remain deferred, and `public/sw.js` only provides generic offline fallback rather than protected read-only cache/freshness/clear-on-logout/offline-mutation proof. SDD-08B/TAL-13 owns the connected Student Hub UI and PWA acceptance slice after core admin/backend rescue items are stable or explicitly promoted.

Feature Group 9 passed its deep submission-lock audit on 2026-06-21. Student status/completion is now benchmark-locked as typed academic lifecycle work, not profile-field editing. Current runtime proves only partial storage fields and generic service-request handling. SDD-07D owns LOA, readmission, transfer-out, withdrawal, archive/reactivation, and other student-status transitions with allowed source/target states, reasons, evidence, notices, access effects, and audit. SDD-07E owns graduation evaluation snapshots, deficiency resolution, completion/graduate status, and credential-release readiness. External CHED/SO/government processing remains an external boundary unless separately promoted.

Feature Group 10 passed its deep submission-lock audit on 2026-06-21. Controlled curriculum import is real current runtime evidence: the Import Batch Filament surface is non-CRUD, uses private CSV/XLSX upload, creates validation previews, blocks invalid/error batches, commits curriculum rows through a service, and records audit evidence. Generic enrolled-roster export, broader reports, export artifact lifecycle, and non-curriculum legacy imports remain implementation gaps. SDD-07A owns the enrolled-roster export that supports external manual encoding; SDD-09 owns broader Admin QA/reporting/export checks. DepEd/CHED/LIS, bank, and receiving-school portals remain external unless a separate approved integration slice defines the credentials, privacy, retry, and audit contract.

Feature Group 11 passed its deep submission-lock audit on 2026-06-21. Attendance, behavior, discipline, guidance, and interventions remain benchmark-gated/deferred because current code has no typed attendance, guidance-case, behavior, discipline, intervention, notice, appeal, or clearance-effect source. The approved boundary is protective: missing Group 11 data cannot silently block the rescue SIS flow. SDD-08B owns any future implementation after institution-approved attendance/discipline/guidance policies define evidence, responsible office, privacy class, notice/response, appeal/review, resolution, effective dates, and exact effects. Guidance/counseling details require restricted access and redacted clearance summaries rather than ordinary Faculty, Accounting, or Registrar data-table visibility.

### College-Only Scope Correction Overlay (2026-06-21)

The institution has removed SHS from the target deployment. `SDD-00C College-Only Scope Correction` is now a blocking cleanup slice before further `SDD-07A` implementation. It supersedes earlier SHS/College branching guidance in this map. SHS references may remain only as archived historical evidence or Grade 12/Form 138/Form 137 prior-credential evidence for College admission.

The document-request system is also removed from active scope. `SDD-00D Foundation Rebaseline and Completion Audit` is the next blocking control slice. It first removes that domain end to end, then inventories FS/TS requirements against code/tests, ranks remaining foundation and integration work by dependency, and publishes the two-day execution backlog before any feature slice resumes.

---

## Execution Rule

Do not finish a whole module as "backend first, UI later." Finish one feature slice at a time:

`FS/TS contract -> business evidence -> code audit -> backend/service/policy/test -> Filament admin UI/action/test -> docs + Linear`

A staff-facing feature is complete only when:

- The domain service or model-owned logic exists.
- Policy/RBAC boundaries are enforced.
- The required Filament Resource/Page/Action exists for the owning staff role.
- The UI calls the tested backend path instead of duplicating logic.
- Focused PHPUnit/Livewire/Filament tests cover happy path, blocked path, and role boundary.
- The local checklist and Linear issue are updated with concrete evidence.

Student Hub UI screens, PWA offline behavior, and student presentation polish are deferred. Student-facing backend contracts are not deferred when they are needed to prove enrollment, finance, document, grade, request, or dashboard data before UAT.

---

## Evidence Sources

| Evidence source | Use in SDD |
| --- | --- |
| `BM-Evaluation.md`, `IT-Evaluation-done.md`, `THM-Evaluation.md` | Transferee evaluation fields, subject equivalency, bridging subject, and grade evidence. |
| `archive/deprecated-shs-scope-2026-06-21/` | Historical SHS-only evidence retained for audit/import reference only; not active workflow guidance. |
| `SOA-2nd-year-COLLEGE-1.md` | College LRN/course/balance/paid/remaining/monthly payment/penalty shapes for ledger and assessment logic. |
| `MS.-OLIMBERIO-Blended-Online.Final-Grade-1.md` | College final-grade layout, equivalent grade scale, pass/fail, INC, and DRP evidence. |
| `INSTITUTION WORK  FLOW CURRENT.md` | Subject-offering, enrollment, faculty grade submission, Registrar verification, and archived-grade lifecycle evidence. |

Use business evidence to clarify fields and policies. Do not copy raw sheet layout into the app as the UI contract.

---

## Current Code Evidence Snapshot

| Area | Current evidence |
| --- | --- |
| Admin/System | `UserResource`, `RoleResource`, `ActivityResource`, `FaqEntryResource`, `SystemSettingResource`; `UserAccountLifecycleService`; RBAC, FAQ, and direct-route denial tests; SDD-04/TAL-23 verification passed. |
| Academic foundation | `ProgramResource`, `SubjectResource`, `CurriculumResource`, `TermResource`, `SectionResource`, `RoomResource`; `AcademicFoundationFilamentResourceTest`; `CurriculumImportServiceTest`. |
| Scheduling | `SectionPlanningService`, `DeliveryPatternService`, `SectionDeliveryGroupService`, `EnrollmentSectioningService`, `FacultyAvailabilityService`, `FacultyAvailabilityChangeRequestService`, `ScheduleGenerationService`, `ScheduleSolverSnapshotService`, `ScheduleCloudResultIngestor`, `ScheduleDraftRowReviewService`, `ScheduleCommitService`, `SchedulePublishService`; scheduling resources/tests; `DeliveryPatternResource`, `SectionDeliveryGroupResource`, Section delivery-groups relation manager, delivery-group-aware Official Schedules and Schedule Draft review actions; Cloud Run solver package now parses/enforces `section_delivery_group_id`; deployed revision `tala-scheduler-solver-00004-wtx` passed authenticated `/health`, authenticated `/solve`, and unauthenticated 403 IAM smoke proof. |
| Enrollment/student records | `StudentProfile`, `Enrollment`, `EnrollmentSubject`; `ApplicantIntakeService`, `StudentEnrollmentService`, `SubjectSuggestionService`, `StudentDashboardService`, `EnrollmentHardCopyReceiptService`, `EnrollmentAssessmentService`; list/view admin resources plus TAL-13 applicant, enrollment, subject-suggestion, and dashboard backend contracts exist. |
| Finance | `PaymentConfirmationService`, `EnrollmentFinanceClearanceService`, `PayMongoWebhookProcessor`, `InstallmentPolicyService`, `AccountingAdjustmentService`, `FeeTemplateResource`, `PaymentAttemptResource`, `PaymentResource`, `LedgerEntryResource`, `PromissoryNoteResource`, `AccountingAdjustmentResource`; payment, webhook, assessment, promissory, and accounting-adjustment tests. |
| Admission documents/OCR and service requests | `DocumentUploadReviewService` and admission-document review resources/tests remain; `ServiceRequestLifecycleService` uses dedicated service-request permissions. The document-request model/table/service/resource/Student Hub page/job/seed/test domain is removed by SDD-00D. |
| Grades/faculty | `GradeEncodingService`, `GradeFinalizationService`, `GradeCorrectionService`, College grading service, class-list, grades, and grade-correction resources/tests; active SHS grading service/UI paths were removed under SDD-00C, with only negative guard evidence remaining. |
| Student Hub access | Approved `/student/*` route protection and FAQ/help consumption are tested. `StudentDashboardService` provides the dashboard aggregate contract for profile, enrollment, schedule, financials, finalized grades, approved service requests, holds, notifications, and published FAQ/help links. The document-request route/page is absent. |

Explicit remaining TAL-13 backend contracts after SDD-05D:

- None in the current SDD-05 backend-contract set. Student Hub UI remains deferred.

---

## Target Order

### SDD-00: Governance Pivot

**Goal:** Replace open-ended refinement lists with this feature-slice execution map.

| Contract | Evidence |
| --- | --- |
| FS/TS | FS executive boundary; TS implementation strategy and admin UI boundary rules. |
| Code | N/A, docs/control-plane only. |
| Status | Active. |
| Done when | README, local checklist, FS/TS, and Linear point to this SDD map as the current execution control. |

### SDD-00C: College-Only Scope Correction

**Goal:** Remove SHS from active source-of-truth guidance and prepare the codebase for a clean College-only implementation before resuming SDD-07A.

| Feature slice | FS/TS anchors | Current evidence | Target |
| --- | --- | --- | --- |
| Business evidence correction | Workflow scope note and reconciliation matrix | SHS-only files archived; active workflow rewritten as College-only | Keep SHS evidence historical only; allow Grade 12/Form 138/Form 137 solely as College admission credentials. |
| Specification and test-case correction | FS/TS, capability map, master test cases | Active specs and UAT rows are rewritten as College-only; Grade 12/Form 138/Form 137 remain prior-credential evidence only | Maintain College-only goal-state requirements and keep SHS evidence archived/historical. |
| Runtime cleanup plan | Laravel models, migrations, services, seeders, factories, Filament resources, tests | Active SHS options/service paths are removed; College-only `education_level` discriminators are removed through a forward migration and caller/test cleanup | Keep active scope expressed through College program, curriculum, year level, term, section, subject, enrollment, grade, ledger, document, and roster/export concepts. |
| Verification | Reconciliation matrix, local checklist, focused tests | Schema guard passes; broader focused suites pending rerun after formatting | Prove active tables/forms no longer expose SHS/education-level selection and College applicant/enrollment/finance/grade flows still pass. |

**Done when:** FS/TS, SDD map, local checklist, master test cases, and code agree that active deployment is College-only; SHS references are either archived history or College prior-credential evidence.

### SDD-00D: Foundation Rebaseline and Completion Audit

**Goal:** Establish a measurable two-day delivery baseline before resuming feature work. Audit the final FS/TS against the actual Laravel code and tests, remove rejected scope, rank remaining work by dependency and UAT value, and require a benchmark gate at the start of each implementation micro-sprint.

| Feature slice | Evidence | Target |
| --- | --- | --- |
| Permanent scope removals | Business evidence, reconciliation, FS/TS, active trackers, code/schema/UI/tests | Remove the document-request portal/catalog/fee/fulfillment/pickup/courier/shipping domain end to end. Keep admission-document review and source-workflow generated artifacts. |
| FS/TS-to-code audit | Every baseline module and technical contract mapped to models, migrations, services, policies, Filament/Livewire surfaces, integrations, and executable tests | Classify each requirement as `PROVEN`, `PARTIAL`, `MISSING`, `EXTERNAL`, `REMOVED`, or `DEFERRED`; record concrete evidence and the smallest remaining slice. |
| Dependency and priority map | Core SIS lifecycle and approved integrations | Rank `P0` identity/RBAC, academic foundation, admissions, enrollment, finance/ledger, scheduling, faculty/grades, canonical student records, and minimum Student Hub visibility before `P1` supporting workflows and `P2` quality-of-life work. |
| Completion dashboard | Local iteration checklist and Linear | Publish counts by module and status, critical-path blockers, test evidence, and remaining estimated micro-sprints so completion is visible rather than inferred from SDD numbering. |
| Benchmark gate | Mature SIS/source-system references, official framework/package docs, legal/policy constraints | Before each sprint, validate the proposed FS/TS implementation. Update FS/TS only when the benchmark exposes an incorrect or incomplete contract; otherwise implement without reopening the baseline. |
| Micro-sprint gate | One independently testable backend/integration behavior at a time | Plan, implement, add/update PHPUnit tests, run focused verification, perform human UI review when applicable, commit, and mirror local/Linear state before the next slice. |

**Two-day execution boundary:** Backend domain correctness and required integrations are completed first. Admin and Student Hub UI connect only to stable backend contracts; quality-of-life features that do not support the College SIS lifecycle or approved integrations remain deferred. Existing correct implementation is retained, so this is a controlled rebaseline rather than a source-code restart.

**Done when:** The active specs, code inventory, test evidence, local checklist, and Linear share one ranked backlog; removed scope is absent from runtime; every P0 item has an evidence status and dependency owner; the next micro-sprint is selected from the critical path rather than from the next historical SDD number.

**SDD-00D audit result (2026-06-22):** The foundation dashboard is now published in `TALA-Local-Iteration-Checklist.md`. Current count is 13 audited areas: 3 `PROVEN`, 7 `PARTIAL`, 2 `MISSING`, 1 `REMOVED`, with P2 deferred work explicitly excluded from the two-day critical path. The selected next micro-sprint is `SDD-03R CP-SAT Scheduling Closure`, because scheduling already has the integration/service/test foundation and needs two narrow correctness fixes before the demo can rely on it: normalize solver outcome semantics and allow approved post-publication Apply while keeping direct schedule edits blocked.

### SDD-01: Curriculum Template and Readiness Scopes (`TAL-20`)

**Goal:** Make curriculum data safe for scheduling and sectioning before changing scheduler behavior.

| Feature slice | FS/TS anchors | Current evidence | Target |
| --- | --- | --- | --- |
| Unified curriculum template | FS 5.1.2, TS 3.17 | `CurriculumImportTemplate`, `CurriculumImportService` | Replace old `Lec_Hours` scheduling dependency with `Weekly Contact Hours`; add `Academic Subject Type`, `Scheduling Group`, and `Delivery Rule Override`. |
| Import validation | FS 5.1.2, TS 3.17 | import preview/commit services | Store zero-valid-row files only as non-committable preview/audit evidence; commit requires `error_rows = 0` and `valid_rows > 0`; allow partial College imports scoped to affected curriculum scopes. |
| Curriculum scope readiness | FS 5.1.2, TS 3.17, TS 3.6.3 | Implemented (`CurriculumScopeReadinessService`, `needs_review`) | Add explicit readiness by `curriculum_id + year_level + curriculum_period`, displayed as `program + curriculum version + year/grade + period`; old rows become `needs_review` until confirmed. |
| Filament admin surface | TS 3.17, TS 5 | current Import Batches and Curriculum resources | Add coverage/readiness view/action using Filament v5 tables, filters, infolists, and actions; keep business rules in services. |

**Locked SDD-01 decision closure (2026-06-17):**

- Add an explicit readiness model/table keyed by `curriculum_id + year_level + curriculum_period`; derive program through the curriculum relationship.
- Store scheduler-facing offering fields on `curriculum_subjects`: `weekly_contact_hours`, `academic_subject_type`, `scheduling_group`, and constrained nullable `delivery_rule_override`.
- Keep the existing database `department` column as the education-level storage for MVP, but template/UI wording must use `Education Level`; legacy `Department` template headers fail strict validation.
- Import preview may store zero-valid-row files as non-committable audit evidence. Commit requires `error_rows = 0` and `valid_rows > 0`; partial College imports are valid and affect only imported scopes.
- Imports require explicit classification fields for MVP. No silent auto-fill from GE/TESDA/NC/title patterns; helper suggestions may be added later only if staff still confirm before readiness.
- Committed imports create/update affected readiness scopes as `needs_review`. Any import or scheduler-facing edit touching a ready scope returns it to `needs_review` with audit evidence.
- Readiness statuses are `needs_review`, `ready_for_scheduling`, and service-derived `blocked`. Current state lives on the scope row; every transition writes to `activity_log`.
- `CurriculumScopeReadinessService` computes blockers live and stores transition snapshots. Staff may mark clear scopes ready or return them to review; staff do not manually select `blocked`.
- Registrar owns import/edit/data entry. Academic Head may review blockers and transition readiness. System Super Admin is not in the normal academic readiness path.
- Section planning may reference a `needs_review` scope and show warnings; term scheduling readiness and solver snapshots must block until `ready_for_scheduling`.
- Modular/no-recurring-meeting rows may use `weekly_contact_hours = 0.00` only with `scheduling_group = modular`; synchronous online/on-site/blended demand requires positive weekly contact hours.
- `Delivery Rule Override` is constrained to blank, `force_online`, `force_on_site`, `force_modular`, or `exclude_from_auto_schedule`. Rows excluded from auto scheduling stay in curriculum coverage but are omitted from solver demand; a scope with no auto-schedulable demand requires an explicit reviewer reason to become ready.
- SDD-01 updates Laravel snapshot tests to include readiness evidence and `weekly_contact_hours`. A temporary `lec_hours` payload alias may remain only when sourced from `curriculum_subjects.weekly_contact_hours` for deployed solver compatibility. Cloud Run solver runtime/redeploy work remains in SDD-03 unless solver code changes during implementation.

### SDD-02: Delivery Patterns and Section Delivery Groups (`TAL-21`)

**Goal:** Replace section-level modality with adaptable delivery-group scheduling.

| Feature slice | FS/TS anchors | Implementation evidence | Target |
| --- | --- | --- | --- |
| Delivery patterns | FS 5.3, TS 3.6.3 | `delivery_patterns`, `DeliveryPattern`, `DeliveryPatternService`, `DeliveryPatternResource`, clone-version action, policy, factory, and focused tests | Versioned pattern CRUD/clone workflow; rules frozen once used. |
| Section delivery groups | FS 5.3, TS 3.6.3 | `section_delivery_groups`, `SectionDeliveryGroup`, `SectionDeliveryGroupService`, `SectionDeliveryGroupResource`, Section relation manager, policy, factory, and focused tests | Add delivery groups with modality, capacity, room requirement, pattern, status, and assigned count. |
| Sectioning assignment | FS 5.3, TS 3.6.3 | `EnrollmentSectioningService`; `enrollments.section_delivery_group_id`; assignment and ranking tests | Store `section_id` + `section_delivery_group_id`; rank compatible groups and require Registrar confirmation. |
| Filament admin surface | TS 5, Filament v5 docs | Service-backed delivery pattern and section delivery group resources plus Section delivery-groups relation manager; unsafe delete/bulk delete unavailable | Use section relation manager or scoped resource for delivery groups; include capacity badges, modality filters, and no unsafe bulk delete. |

**SDD-02 implementation evidence (2026-06-17):**

- Added delivery pattern and section delivery group schema, models, factories, policies, services, Filament resources, and Section relation manager.
- Linked enrollments, solver draft rows, and official section meetings to `section_delivery_group_id` as nullable compatibility fields pending SDD-03 solver/runtime migration.
- Updated term scheduling readiness so missing delivery groups and invalid delivery-group room/capacity setup block generation before solver dispatch.
- Updated Pre-UAT scenario data to seed a frozen delivery pattern and primary section delivery group.
- Verified with focused feature tests for delivery pattern lifecycle, section delivery group validation, Registrar sectioning assignment, Filament resource behavior, scheduling readiness, solver-adjacent compatibility, and Pre-UAT seeding.
- No Cloud Run redeploy was performed for SDD-02 because `cloud/scheduler-solver` runtime code did not change; SDD-03 remains the redeploy/proof gate when the solver starts parsing/enforcing delivery-group fields.

### SDD-03: Scheduling Snapshot, Solver, Commit, and Publish Closure (`TAL-22`)

**Goal:** Finish the scheduling path as a staff-operable flow under the new model before QA.

| Feature slice | FS/TS anchors | Current evidence | Target |
| --- | --- | --- | --- |
| Readiness and snapshot | FS 5.3, TS 3.6.3 | Locally implemented in `ScheduleSolverSnapshotService` schema v3 and tests | Include ready curriculum scopes, delivery groups, weekly contact hours, delivery patterns, and section/group capacity. |
| Solver runtime and ingestion | TS 3.6.3 | Implemented in `cloud/scheduler-solver` and `ScheduleCloudResultIngestor`; deployed revision `tala-scheduler-solver-00004-wtx` smoke-tested with delivery-group sample payload | Update Cloud Run solver and Laravel ingestor for `section_delivery_group_id`; preserve >98% feasible-input target and 100% hard validity. |
| Scheduling solver execution | FS/TS | Implemented (solver payload generation, ingestion) | Add Python solver payload generation, execution, and solution ingestion. |
| Manual official assignment | FS 5.3.2, TS 3.6.3 | Locally implemented in `SectionMeetingAssignmentService`, `SectionMeetingResource`, and tests | Require delivery group; preserve eligibility and hard conflicts; availability override remains reasoned/audited. |
| Workload soft overrides | FS 5.3, TS 3.6.3 | `max_weekly_hours` exists but solver does not enforce broadly | Add configurable caps and Academic Head-approved soft override; never bypass hard conflicts. |
| Publish lifecycle | FS 5.3, TS 3.6.3 | Locally implemented in `SchedulePublishService`, run metadata, Filament actions, and tests | Add `committed official` -> `published` with Academic Head approval; System Super Admin emergency publish only with reason. |
| Cloud solver redeploy checkpoint | TS 3.6.3 | `cloud/scheduler-solver`, Dockerfile, Cloud Build config, deployed Cloud Run URL | If solver code changes, provide step-by-step Google Cloud Console/Cloud Shell redeploy instructions, then smoke-test `/health` and `/solve` before closure. |

### SDD-04: Admin/System Foundation Verification (`TAL-23`)

**Goal:** Reconfirm cross-role admin infrastructure after the scheduling model changes.

| Feature slice | FS/TS anchors | Current evidence | Target |
| --- | --- | --- | --- |
| Staff account lifecycle | FS 8.2-8.3, TS 3.2, TS 4 | `UserResource`, `UserAccountLifecycleService`, seeded roles/permissions | Verify create/edit/archive/restore and one-role-only boundaries remain clean. |
| RBAC matrix and audit | FS 3, FS 8.2, TS 4 | `RoleResource`, `ActivityResource`, policies | Keep role matrix read-only and audit details human-readable. |
| FAQ maintenance | FS 8.7, TS 3.16 | `FaqEntryResource`, `FaqEntryPolicy`, public/student FAQ tests | Keep CRUD for System Super Admin only; public/student read published rows only. |
| System settings boundary | FS 8.5, TS 3.19, TS 8.8 | `SystemSettingResource`, denial tests | Keep generic raw settings hidden/blocked; create typed settings pages only if a module needs them. |

**SDD-04 verification evidence (2026-06-17):**

- Code audit reconfirmed `UserResource` uses split staff-name fields, staff-only role choices, active/inactive direct status options, and one-role-only selection; archive/restore actions delegate to `UserAccountLifecycleService`.
- `UserAccountLifecycleService` locks target rows, blocks invalid lifecycle transitions, clears roles on archive, restores exactly one approved staff role, and records activity evidence.
- `RoleResource` remains list-only with no create/edit routes or actions; `RolePolicy` denies mutation and vendor role policy registration remains explicit in `AppServiceProvider`.
- `ActivityResource` remains list/view only; `ActivityInfolist` renders audit metadata through `ActivityPropertiesFormatter` instead of exposing editable/raw payload fields.
- `FaqEntryResource` keeps System Super Admin CRUD through `manage-faqs`; public `/faq` and Student Hub Help read only published FAQ rows.
- `SystemSettingResource` remains hidden from navigation, exposes no create/edit route or action, and `SystemSettingPolicy` denies every ability, including direct `/admin/system-settings` access.
- Focused tests passed: `php artisan test --compact tests/Feature/UserAccountLifecycleServiceTest.php tests/Feature/TAL12ASystemSuperAdminFilamentResourceTest.php tests/Feature/TAL10RbacMatrixTest.php tests/Feature/PublicFaqPageTest.php tests/Feature/StudentHubAccessTest.php` -> 24 passed / 227 assertions.
- Direct internal route denial test passed: `php artisan test --compact tests/Feature/PreUatInternalRouteDenialTest.php` -> 2 passed / 4 assertions.

### SDD-05: TAL-13 Backend Contracts Before UAT

**Goal:** Implement student-domain backend contracts now while deferring Student Hub UI.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| Applicant intake backend | FS 4.1, FS 5.4, TS 2.5, TS 3.3 | College admission, transferee evaluation, and prior-credential evidence | `ApplicantIntakeService`, `ApplicantIntake`, applicant-linked `document_uploads`, focused tests | Done for backend contract: public registration service, pending applicant status, duplicate guard, document/OCR handoff, and approval-for-payment prerequisites. |
| Student enrollment backend | FS 4.2, FS 5.4, TS 3.12 | SOA/enrollment fields, curriculum/evaluation evidence | `StudentEnrollmentService`, payment handover bridge, focused tests | Done for backend contract: approved-applicant enrollment creation, regular enrollment, returnee detection, payment/clearance handover, section capacity, and COR readiness. |
| Subject suggestion backend | FS 4.2, FS 5.3, TS 3.4.1 | evaluation/bridging/grade evidence plus class-record/final-grade lifecycle evidence | `SubjectSuggestionService`, `Subject::prerequisites()`, focused tests | Done for backend contract: prerequisite-aware current-subject suggestions, back subjects, already-passed subjects, active INC/failed/missing-history blockers, and latest finalized attempt behavior. |
| Student dashboard backend | FS 4.3, FS 6, FS 7, FS 9, TS 5.8 | SOA and grade-sheet evidence | `StudentDashboardService`, focused tests | Done for backend contract: aggregate profile, current enrollment, schedule, financial summaries, finalized grades, document/service requests, grade corrections, holds, notifications, and published FAQ/help links for future UI. |

Do not build the Student Hub pages in this phase. Tests may call services directly or through narrow backend routes/actions if those routes already exist.

**SDD-05A implementation evidence (2026-06-17):**

- Added `applicant_intakes` as the pre-handover staging aggregate for student-profile/external-reporting fields, orientation acknowledgements, required-document lists, duplicate-check status/payload, and Registrar review metadata.
- Linked applicant-owned document evidence through nullable `document_uploads.applicant_intake_id` while keeping `student_profile_id = null` until Official Handover.
- Added `ApplicantIntakeService` to create pending applicant users with the `applicant` role, block duplicate LRN/name-birthdate matches, derive required document lists, dispatch OCR for uploaded documents, and block payment unlock until every required document is Registrar-approved.
- Updated the existing Registrar Document Review list/detail surface to show applicant labels for applicant-owned uploads without adding generic Document Upload CRUD.
- Verified SDD-05A with focused test coverage in `ApplicantIntakeServiceTest` for happy path, duplicate guard, OCR handoff, and blocked finalization prerequisites.

**SDD-05B implementation evidence (2026-06-17):**

- Added `StudentEnrollmentService` as the backend authority for moving approved applicant intakes into `student_profiles` and `enrollments` while keeping the account `approved` until finance clearance.
- Linked applicant-owned `document_uploads` to the created official `student_profiles` row during the enrollment bridge, preserving applicant intake history.
- Added regular enrollment support with an outstanding-balance gate, returnee detection from profile/account state, and compatible delivery-group assignment through the existing capacity-locking sectioning service.
- Added finance-cleared account handover that sets `users.status = active`, switches `users.username` to the generated student ID, removes the `applicant` role, assigns the `student` role, and exposes a `corReadiness` contract for COR/class-list gates.
- Added `EnrollmentFinanceClearanceService` as the shared minimum-downpayment/full-payment rule used by both manual Accounting payment confirmation and PayMongo webhook-confirmed linked enrollment payments. SDD-06C later corrected promissory handling so a promissory note remains non-payment evidence but cannot block real confirmed payment clearance.
- Updated `PaymentConfirmationService` and `PayMongoWebhookProcessor` so payment clearance delegates handover to `StudentEnrollmentService`; this preserves the then-current `PendingPayment` -> `PreEnrolled` implementation for both manual and online gateway paths when the payment attempt is enrollment-linked. SDD-07A supersedes that implementation with physical-document gating and canonical `Enrolled` migration.
- Verified SDD-05B with focused test coverage in `StudentEnrollmentServiceTest` for approved-applicant happy path, regular enrollment, outstanding-balance block, payment-clearance handover, capacity-blocked rollback, idempotency, and minimum-downpayment clearance. Also verified PayMongo linked-enrollment parity in `PayMongoWebhookFinanceClearanceTest`, existing webhook contract behavior in `PayMongoWebhookMockContractTest`, payment source coverage in `PaymentConfirmationServiceTest`, and monitoring source coverage in `TAL12MonitoringCoverageTest`.

**SDD-05C implementation evidence (2026-06-18):**

- Added `SubjectSuggestionService` as the backend authority for prerequisite-aware irregular/transferee subject suggestions before Student Hub UI work.
- Added explicit `Subject::prerequisites()` and `Subject::requiredBySubjects()` relationships over the existing `prerequisites` table.
- The service returns suggested current subjects, back subjects, blocked subjects, already-passed current subjects, setup blockers, and summary counts for an enrollment's current curriculum scope.
- Finalized grade history is the eligibility authority: the service uses the latest relevant finalized attempt per subject, treats active INC as `active_inc`, finalized failing grades as `failed`, and missing prerequisite history as `missing_history`.
- Business evidence alignment: active College class-record/final-grade evidence confirms faculty records become official eligibility data only after Registrar verification/finalization. Archived SHS class-record evidence is retained only for historical reference after SDD-00C.
- Approved equivalent or credited-subject satisfaction remains blocked until a controlled equivalency/credit-evaluation record exists; the service does not mutate enrollment subjects, perform unit-cap/summer split decisions, or bypass Registrar approval.
- Verified SDD-05C with focused test coverage in `SubjectSuggestionServiceTest` for passed-prerequisite suggestions, missing/failed/active-INC blockers, latest finalized attempt behavior, and failed current subjects becoming back subjects.

**SDD-05D implementation evidence (2026-06-18):**

- Added `StudentDashboardService` as the read-only Student Hub backend aggregate contract before Student Hub UI work.
- The service returns student-owned profile, current enrollment/history, current schedule, financial term summaries/latest confirmed payments, finalized grade history, recent document/service requests, recent grade-correction requests, dashboard holds, latest notifications, and published FAQ/help links.
- Dashboard holds surface outstanding balance, missing hard-copy evidence, and active promissory-note context without treating promissory notes as finance clearance.
- Data scope is student-owned: grades are limited to the student's enrollments, requests are limited to the student's profile/user, and FAQ output is limited to published entries.
- Verified SDD-05D with focused test coverage in `StudentDashboardServiceTest` for aggregate happy path, cross-student leakage prevention, and stable empty output when no current enrollment exists.

### SDD-06: Accounting Backend/Admin Closure

**Goal:** Make finance policies and admin surfaces consistent with business evidence.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| Assessment/downpayment | FS 6.1-6.2, TS 3.12 | `shs-tf.md`, SOA files | `EnrollmentAssessmentService`, `EnrollmentFinanceClearanceService`, `EnrollmentAssessmentServiceTest` | **SDD-06A closed:** most-specific fee-template scope, tuition-only freshmen discount, idempotent ledger posting, and configured downpayment threshold are executable-test verified. |
| Payments/ledger | FS 6.2-6.3, TS 3.12, TS 3.14 | SOA paid/date/balance/monthly/penalty shapes | `PaymentConfirmationService`, `EnrollmentFinanceClearanceService`, `PayMongoWebhookProcessor`, queue/resource tests | **SDD-06B closed:** typed manual confirmation, atomic immutable posting, provider idempotency/retry, overpayment, shared clearance, and list/view admin boundaries are executable-test verified. |
| Promissory lifecycle | FS 6.2.3, TS 2.5.3, TS 2.6.1, TS 8.8 | SOA balance evidence, RA 11984 benchmark | `PromissoryNoteLifecycleService`, `ExamAccessDecisionService`, Accounting resources/actions | **SDD-06C closed:** applicant/student-owner backend request, staff-assisted pending creation, Accounting approve/reject/cancel, payment-driven settlement, deadline processing, and separate exam-access accommodations are executable-test verified. |
| Accounting adjustments | FS 6.3, TS 3.12, TS 8.8 | SOA corrections/balances, accounting-log/manual-receipt workflow evidence, finance correction benchmarks | `AccountingAdjustmentService`, `accounting_adjustments`, `AccountingAdjustmentResource`, `LedgerEntryResource` list/view-only evidence | **SDD-06D closed:** typed debit, credit, and ledger-entry reversal workflow posts one audited adjustment plus one immutable ledger entry; generic ledger CRUD remains forbidden. |
| **SDD-06E - Finance operations and reconciliation delta** | FS 6.1-6.3; TS 3.12/3.14 | Fee structures, Collector/Recorder/Verifier, manual/online receipts, daily three-way reconciliation, private reminders, clearance/SOA | Immutable payment/ledger services exist; fee template is transitional and no assessment snapshot, OR/SOA issuance, maker-checker daily close, or projection rebuild exists | Preserve existing posting services. Add versioned effective-dated fee structures/component lines, assessment snapshots, policy-driven discount, duty permissions, OR/SOA issuance, balance rebuild check, expected/actual close, variance reason, verifier approval, and private reminders. Remove assessment-owned `enrolled_at`. |
| **SDD-06F - Financial disposition and refunds** | FS 6.2.4; TS 3.12.3 | Current 15-day admission/enrollment-fee refund and post-enrollment tuition policy | Typed adjustments exist; no refund request/review/channel execution | Implement effective-dated component disposition, authorization, immutable refund entries, channel idempotency, and reconciliation. Withdrawal/cancellation must call this policy rather than assume retention/refund. |

**SDD-06A implementation evidence (2026-06-18)**

- Linear mirror: `TAL-24` (Done), linked as completed evidence for the active `TAL-12` readiness gate.
- Verified exact program/year fee templates take precedence over program-only and education-level defaults.
- Verified eligible new first-year College students receive exactly 50% of tuition as a negative ledger entry while laboratory, miscellaneous, and other fees remain undiscounted.
- Verified repeated assessment does not duplicate fee or discount ledger entries and preserves the calculated balance.
- Verified configured minimum downpayment is calculated from net assessment: a payment below the threshold stays pending and meeting the threshold exactly triggers finance clearance and shared account handover.
- Focused proof: `php artisan test --compact tests/Feature/EnrollmentAssessmentServiceTest.php`.

**SDD-06B implementation evidence (2026-06-18)**

- Linear mirror: `TAL-25` (Done), blocking the active `TAL-12` readiness gate and related to `TAL-24`.
- Manual confirmation requires Accounting authorization, prior assessment, positive decimal amount, an allowed manual channel, normalized unique reference, and a non-future payment date.
- Payment, negative ledger credit, running balance, finance clearance/account handover, and audit evidence commit atomically; forced downstream failure is rollback-tested.
- Overpayments remain standard immutable payment credits and produce a negative balance without a separate wallet transaction.
- PayMongo processing row-locks the attempt, suppresses duplicate events, links the attempt and payment to one ledger entry, records processing errors, and rethrows so queue retries remain active.
- Payment Attempt, Confirmed Payment, and Ledger Entry resources remain list/view-only; enrollment and approved Accounting workflows are the authorized typed manual confirmation surfaces.

**SDD-06C implementation evidence (2026-06-18)**

- Financial promissory notes now use `pending`, `approved`, `rejected`, `cancelled`, `expired`, and `settled` lifecycle states through `PromissoryNoteLifecycleService`; generic status editing remains forbidden.
- Applicant-owner and active-student-owner backend requests are supported before Student Hub UI work resumes; Accounting can create staff-assisted pending requests from the Admin Nexus.
- One open promissory request is allowed per enrollment. Cross-student, cross-term, and cross-enrollment submissions are rejected by service validation.
- Real confirmed payments still clear finance when thresholds are met; promissory notes are non-payment evidence and no longer block real payment clearance. Payment confirmation and PayMongo webhook processing both run a promissory settlement check.
- `ProcessPromissoryNoteDeadlinesJob` is scheduled at `00:45` Asia/Manila and dedupes expiring/expired notifications with timestamp fields.
- Exam access evidence remains separate from finance clearance. The current institution's approved policy permits all enrolled students to take scheduled exams without a debt-based permit block; RA 11984 certification/accommodation evidence remains private where retained for compliance. Collection and lawful next-cycle/document holds remain separate.
- Focused proof: `php artisan test --compact tests/Feature/PromissoryNoteLifecycleServiceTest.php tests/Feature/ExamAccessDecisionServiceTest.php tests/Feature/SDD06CPromissoryFilamentWorkflowTest.php tests/Feature/PaymentConfirmationServiceTest.php tests/Feature/InstallmentPolicyServiceTest.php`.

**SDD-06D implementation evidence (2026-06-18)**

- Added `accounting_adjustments` as the audit record for manual Accounting corrections, linked to the selected student, optional term/enrollment/source ledger entry, generated ledger entry, reason, evidence reference, posting timestamp, and poster.
- Added `AccountingAdjustmentService` as the sole manual correction path. It requires `post-accounting-adjustments`, validates reason/evidence/date/scope, row-locks the student profile, posts inside a transaction, updates `student_profiles.current_balance`, and writes activity-log evidence.
- Supported adjustment types are `student_account_debit`, `student_account_credit`, and `ledger_entry_reversal`. Reversal posts the exact opposite amount of the selected source ledger entry and rejects duplicate reversal of that source.
- Added `AccountingAdjustmentResource` as a typed Accounting surface with list/create/view only. Create delegates to the service; list/view use descriptive student, enrollment, source-ledger, posted-ledger, and poster labels; edit/delete/bulk-delete remain unavailable.
- Added `post-accounting-adjustments` permission and assigned it to the Accounting role. Existing `LedgerEntryResource` remains immutable list/view evidence and now includes accounting-adjustment filter values.
- Accounting adjustments update balances but intentionally do not create refunds, edit payments, or silently undo finance-cleared account handover/enrollment access. Any such rollback requires a separate approved workflow.
- Focused proof: `php artisan test --compact tests/Feature/SDD06DAccountingAdjustmentServiceTest.php` and `php artisan test --compact tests/Feature/TAL12AAccountingFilamentResourceTest.php`.

### SDD-07: Admissions, OCR, Enrollment Adjustments, and Student Lifecycle

**Goal:** Finish College admissions, enrollment handover, typed adjustment requests, student-status transitions, and completion boundaries that staff must run before UAT.

**Workflow-source refresh (2026-06-19):** `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` was replaced by a 734-line consolidated institutional workflow and policy manual prepared by the project manager from the client's current approved business flow. It is the newest authority for current institutional business intent, but it does not discard stronger compatible FS/TS decisions. The workflow supplies outcomes, policies, roles, sequences, and current-institution values; FS/TS refine them into a secure, automated, auditable, and adaptable TALA design. Literal manual steps, UI wording, status names, and technical structure are not copied when an existing or benchmarked design satisfies the same approved outcome better.

**Approved reconciliation rule (refined 2026-06-19):** Treat the consolidated workflow as the approved business baseline and FS/TS/code as candidate or implemented system refinements. Preserve any existing decision that is compatible and improves control, usability, auditability, or adaptability. When sources conflict, compare the required outcome, chronology, implementation evidence, and external benchmark; replace only the incompatible portion. Ask the client/user only when ambiguity, trade-offs, or binding-rule conflicts remain. A later clarification may supersede either source, but no document wins wholesale.

**Rebaseline rule:** Do not restart every completed SDD and do not replace FS/TS wholesale. Preserve compatible implementation and stronger system contracts, reopen only the incompatible behavior, and synthesize the workflow plus benchmark into one normative FS/TS contract. Change code only after that feature-level reconciliation is approved.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| **SDD-07A - Admission documents and enrollment handover** | FS 4.1, FS 5.4, FS 8.1.1, TS 1.3.1, TS 3.12.1, TS 6.1 | Latest approved College-only admission-gate/retention-document split, Registrar workflow, provisional undertaking, payment/receipt slot rule, and external roster boundary | `ApplicantIntakeService`, `StudentEnrollmentService`, `EnrollmentFinanceClearanceService`, `EnrollmentHardCopyReceiptService`, `DocumentUploadReviewService`, enrollment/LIS columns and projections, COR permission, OCR services/resources | Resume only after SDD-00D publishes the dependency-ranked P0 backlog. Implement one generic College admission lifecycle, initially publishing College Freshman and College Transfer only. Treat old curriculum, ALS, foreign compliance, IP, PWD/SEN, and support attributes as composable College rules only when approved. Add readiness-gated payment/handover, stacked College admission-capacity plans, tentative placement expiry, OR-secured capacity, and `PendingInstitutionalPlacement` fallback. Add assisted legacy onboarding for pre-TALA returnees without reliable records, while SDD-07D retains readmission approval/reactivation ownership. |
| **SDD-07B - Retired scope identifier** | SDD-00D scope decision | The document-request portal/catalog/fee/fulfillment/pickup/courier/shipping implementation is being removed | Do not implement or reuse this identifier for active work. Generated COR, finance, academic, transfer, and completion artifacts remain owned by SDD-07A/08A, SDD-06E, SDD-07D, and SDD-07E. |
| **SDD-07C - Enrollment adjustment requests** | FS 5.3, FS 9.4, TS 3.14.2, TS 3.18 | Drop-subject, section transfer, program transfer, and modality-change workflows | Generic `ServiceRequestLifecycleService`; section/delivery-group capacity and calendar services exist, but no typed request applies these domain changes | Define separate typed workflows for drop subject, section transfer, program transfer, and modality change. Each approval must validate its calendar window, curriculum/prerequisites, capacity/schedule effects, and fee delta, then apply changes atomically. Resolving a generic service request must never mutate enrollment records by itself. |
| **SDD-07D - Student record and status lifecycle** | FS 4.1, FS 5.2, FS 9.2, TS 2.5, TS 3.14.2 | Personal-data update/correction, missing-requirement receipt, withdrawal, LOA, readmission, transfer-out, inactivity, archive, and reactivation workflows | Generic `ServiceRequestLifecycleService`; `users` and `student_profiles` contain inactive/archived values, but no approved transition services implement these workflows | Define typed evidence, approvals, effective dates, notifications, access effects, financial/admission-document holds, and reversible versus terminal transitions. Keep missing admission-document receipt in SDD-07A. Do not infer inactivity from attendance until an attendance source exists. |
| **SDD-07E - Graduation evaluation and credential release** | FS/TS completion and generated-artifact boundaries | Curriculum-audit, deficiency, clearance, graduate approval, diploma-number, authorized release, and external-reporting evidence | Curriculum, finalized grades, ledger, and document uploads exist; no graduation application/audit lifecycle exists | Decide MVP scope, then define an auditable graduation evaluation snapshot, deficiency resolution, approval, credential preparation/release, and external-reporting export boundary. Government portal submission remains external unless separately approved. |

**Institutional workflow change-impact audit (2026-06-19)**

| Finding | Classification | SDD treatment |
| --- | --- | --- |
| Payment/receipt secures capacity, while the workflow assigns a section and schedule before payment | Approved business sequence conflicts with the earlier sectionless design | Retain the stronger capacity-locking and audit architecture, but model tentative pre-payment placement separately from an OR-secured seat and official access. |
| Thirty-to-sixty-day provisional retention-document period | Approved retention flow conflicts only with the blanket application of the earlier seven-day rule | Preserve versioned requirements, per-item evidence, reminders, and typed holds; separate upfront admission gates from non-critical retention requirements and apply regulator-aware methods/deadlines. |
| Fifteen-day admission/enrollment-fee refund window and non-refundable tuition after official enrollment | Approved current policy conflicts with the earlier active strict-no-refund profile | Retain the stronger effective-dated financial-policy and immutable-ledger architecture, but replace its current-institution policy values and implement authorized refund mechanics. |
| Internal LIS/CHED completion tracking | Superseded by later client decision | Keep government encoding external; provide only the approved generic enrolled-roster export. |
| Exact 100-student campus ceiling, one promissory note/year, and current approved fees | Approved current-institution values, not universal platform constants | Adopt as effective-dated configuration and enforce through typed services; benchmark operational and regulatory constraints where relevant. |
| Five continuing-enrollment clearance gates | Approved current workflow | Add the missing behavior/discipline evidence sources and typed holds before these gates can be enforced; do not simulate unavailable data. |
| Drop subject, section/program/modality transfer, record correction, LOA, readmission, transfer-out, archive/reactivation, and graduation | Genuine workflow coverage gaps | Added as SDD-07C through SDD-07E. Each requires typed services; the current generic request lifecycle is intake/audit evidence only. |
| Group-chat, Google Classroom, printed-module pickup/drop-off, LIS/CHED encoding, and physical filing details | External/manual operating context | Record integration/export boundaries only; do not create internal automation without an explicit scope decision. |
| Archived SHS progression summary | Superseded by SDD-00C | Do not implement active SHS progression. Retain only as archived historical evidence unless SHS scope is formally restored. |
| Archived SHS component weights | Superseded by SDD-00C | Do not implement active SHS grading profiles, UI, validation, services, or tests. Retain only as archived historical evidence unless SHS scope is formally restored. |
| Workflow College grading scale/formula conflicts with FS, raw evidence, and current code | Unresolved institution-policy conflict | Add effective-dated grading profiles and grill the active scope/profile before runtime or historical-grade changes. |
| Workflow ordinary-week attendance/LMS restrictions for debt | Privacy/legal and policy conflict | Do not implement debt-based attendance/LMS restrictions or exam denial. Keep private collection, next-cycle enrollment holds, and lawful record-release holds. |

**Comprehensive-manual revalidation impact (2026-06-19 refresh)**

| Existing slice/contract | New manual impact | Required action |
| --- | --- | --- |
| SDD-01 to SDD-03 curriculum, sectioning, and scheduling | Core ownership and prerequisite/schedule mapping remain compatible. The current 100-student ceiling and progression policies are approved institutional requirements. | Keep solver work closed; add configurable institution capacity and benchmark progression constraints before implementing their gates. |
| SDD-05B enrollment handover / active SDD-07A | Resolved conflict: pre-payment section/schedule work is tentative planning; admission-gate evidence controls payment eligibility; retention undertakings may follow; qualifying payment secures capacity; active access requires compatible final placement and canonical enrollment. | Continue SDD-07A against the lock-audited FS/TS contract. Migrate transitional states/LIS coupling, add tentative-placement expiry and placement-aware handover, then implement generic enrolled roster and COR issuance without reopening the resolved gate split. |
| SDD-05C subject suggestion | Prerequisite enforcement remains compatible for College progression and remediation outcomes. | Benchmark College progression rules and model approved configurable thresholds before adding automation. |
| SDD-06C promissory lifecycle | Approved workflow says one approved note per academic year with dual Registrar/Accounting approval; code enforces one open request per enrollment and Accounting review. | Reopen cap/approval implementation; retain payment-driven settlement and RA 11984 exam-access accommodation unless the approved flow or law requires more. |
| SDD-06D financial disposition | Approved workflow establishes a 15-day admission/enrollment-fee refund window and non-refundable tuition after official enrollment; the strict no-refund contract is stale. | Reconcile the effective-dated policy, payment channels, immutable ledger treatment, and authorization before code changes. |
| SDD-07B document fulfillment | Removed from TALA scope by SDD-00D. | Delete runtime/docs/test/tracker implementation; retain the manual institutional process only as external business evidence. |
| SDD-07C/07D/07E | Manual confirms drop/add, section/program/modality changes, personal-data correction, LOA/readmission/transfer-out, archive/reactivation, summer, and graduation workflows. | Keep these as required typed backend/admin slices; generic `service_requests` resolution remains insufficient. |
| SDD-08 faculty/grades | Manual adds College grading/absence rules, dual hard/soft submission, Registrar audit, and faculty delinquency lists/attendance restrictions; SHS component rows are archived by SDD-00C. | Revalidate College grade formulas and privacy/legal boundaries; do not expose student balances or automate attendance restrictions from unavailable attendance data. |
| Accounting role model | Approved workflow separates Collector, Recorder, and Verifier duties, while the application has one Accounting role. | Model segregation through permissions/actions and assignment controls; create separate seeded roles only if the approved operating model requires separate accounts. |

**Admission-document benchmark finding (2026-06-19, bounded by SDD-00C):** [DepEd Order No. 017, s. 2025](https://www.deped.gov.ph/2025/06/13/june-13-2025-do-017-s-2025-revised-basic-education-enrollment-policy/) remains useful only for prior-school record-transfer and documentary-deficiency benchmarking. It must not restore active SHS enrollment. SDD-07A must separate minimum College admission/identity evidence from follow-up school records and must model regulator-specific satisfaction methods and deadlines where College prior credentials are involved.

**SDD-07A capacity benchmark:** Oracle PeopleSoft Campus Solutions documents configurable enrollment targets by institution-defined cohort, population, and division, including term, academic career, program, admit type, and program status criteria; this supports scoped College admission-capacity plans rather than a universal campus ceiling. FEU High School's reservation model remains only an analogous benchmark for separating paid reservation from official enrollment; it does not make SHS, strand, or grade-level offerings active in TALA. Sources: [Oracle enrollment-management targets](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lsad/concept_UnderstandingEnrollmentManagementTargets-ab78b6.html), [FEU High School slot reservation](https://www.feuhighschool.edu.ph/slot-reservation/).

**SDD-07A readiness and seat-consumption benchmark:** Mature SIS enrollment separates term/session dates, class/schedule readiness, planning validation, payment, and actual enrollment. TALA therefore treats pre-payment placement as non-protected planning and requires calendar/enrollment window, published offering/policy, approved capacity plan, ready curriculum/subjects, planned sections/delivery groups, faculty eligibility/availability or approved override, and committed/published schedule readiness before payment clearance and handover unless an authorized institution-controlled exception is recorded. OR-backed payment secures capacity across every matching capacity-plan scope. Sources: [Oracle class enrollment processing](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lssr/concept_UnderstandingClassEnrollmentProcessing-ab456f.html), [Oracle terms and sessions](https://docs.oracle.com/cd/E29376_01/hrcs90r5/eng/psbooks/lsfn/htm/lsfn08.htm), [Oracle enrollment and validation appointments](https://docs.oracle.com/cd/E56917_01/hrcs90r5/eng/cs/lssr/concept_UnderstandingEnrollmentandValidationAppointments-ab459b.html).

**SDD-07A admissions-pipeline benchmark:** Oracle PeopleSoft uses configurable checklist items with responsible actor, status, and due date, while Frappe Education separates the published admission offering, applicant record and decision, student-master creation, and program enrollment. TALA therefore uses one shared admission lifecycle and treats applicant characteristics as composable policy dimensions rather than separate hardcoded pipelines or one mutually exclusive applicant-type enum. Sources: [Oracle checklist setup](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lscc/concept_UnderstandingChecklistSetup-ab6ca5.html), [Frappe Student Admission](https://docs.frappe.io/education/student_admission), [Frappe Student Applicant](https://docs.frappe.io/education/student-applicant).

**SDD-07A document-storage benchmark:** TALA retains the submitted source file as evidence where a file exists, stores verified operational facts separately, and treats OCR/parser output as a non-authoritative derivative. The storage-class matrix further separates ordinary credentials, official school transmissions, ID photos, restricted medical/SEN/IP/immigration evidence, transaction proof, generated official artifacts, controlled imports, and physical custody. Gate versus retention classification is independent from storage treatment. File handling follows [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) controls, while purpose limitation and retention/disposal follow the [Philippine Data Privacy Act IRR](https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/).

**SDD-07A old-curriculum benchmark:** [CHED's K to 12 transition guidance](https://chedk12.wordpress.com/lifelonglearner/) states that high-school graduates under the old basic education curriculum remain eligible to enroll in College, subject to admitting-institution requirements and possible bridging/special assessment. [MMDC's admission requirements](https://admissions.mmdc.mcl.edu.ph/hc/en-us/articles/33489744398733-Requirements-for-Old-Curriculum-Graduates-Senior-High-School-Graduates-and-Transferees) treat old-curriculum graduates without College units as College applicants and require an old-curriculum Form 138 establishing first-year College eligibility alongside identity and Good Moral evidence. [Mindoro State University's AY 2026-2027 admission notice](https://www.minsu.edu.ph/news/details/394) likewise identifies old-curriculum graduates without SHS as qualified College applicants, separately from ALS/PEPT pathways. The client clarification describes learners who completed Grade 10/high school before SHS existed and now want to continue studying; TALA therefore activates Old Curriculum College as a prior-credential pathway under Regular College Freshman and keeps the former Old Curriculum SHS row as inactive trace evidence only, with no public route or resolver fallback.

**SDD-07A ALS benchmark (superseded by SDD-00C):** ALS/equivalency evidence does not create an active SHS route. Any future College ALS/equivalency pathway remains inactive until the institution approves a College offering and evidence rule. If approved later, the requirement policy must accept one authoritative eligibility outcome and deduplicate Certificate of Rating, Certificate of Completion, and equivalent evidence when they prove the same purpose. Sources: [DepEd LIS ALS Portfolio Assessment guide](https://support.lis.deped.gov.ph/support/Manuals/ALS-Portfolio-Assessment-Tutorial-Guide.pdf), [DepEd LIS support updates](https://support.lis.deped.gov.ph/support/), [CHED ALS admission advisory](https://legacy.ched.gov.ph/ched-to-heis-accept-als-passers-for-ay-2018-2019/).

**SDD-07A foreign-compliance benchmark:** The Bureau of Immigration defines Student Visa 9(f) for foreign nationals at least 18 taking higher-than-high-school study, while Philippine consular and school guidance distinguish visa, permit, medical, and acceptance evidence handled through external immigration/consular processes. TALA therefore keeps foreign compliance inactive by default for MVP and allows publication only when the institution confirms an accepted base offering and activates a compliant evidence policy. TALA stores restricted compliance evidence, verification status, deadlines, and holds, but does not submit to or update BI, DFA, CHED, DepEd, embassy, or other regulator systems. Sources: [BI Student Visa 9(f)](https://immigration.gov.ph/student-visa-9f/), [BI Visas](https://immigration.gov.ph/visas/), [Philippine Embassy Singapore Student Visa](https://www.philippine-embassy.org.sg/consular/visa/procedures-and-requirements-for-student-visa/).

**SDD-07A IP/SEN support benchmark:** [RA 11650](https://lawphil.net/statutes/repacts/ra2022/ra_11650_2022.html) establishes inclusion and support services for learners with disabilities, not a denial pathway. [RA 8371/IPRA](https://faolex.fao.org/docs/pdf/phi13930.pdf) and DepEd IPEd policy references support culturally responsive participation for Indigenous learners. [RA 10173/Data Privacy Act](https://privacy.gov.ph/data-privacy-act/) requires sensitive personal information to be processed only under valid purpose and safeguards. TALA therefore treats IP and disability/SEN as optional support attributes that may collect restricted evidence for configured accommodations/support only, with no automated rejection, ranking, sectioning, billing, discipline, or public-reporting effect unless separately approved.

### SDD-08: Faculty, Grades, Attendance, and Student-Support Closure

**Goal:** Make faculty/admin grade workflows consistent from class list to final grade correction.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| Class list visibility | FS 7.1, TS 3.7 | official roster and published schedule evidence | `FacultyClassListService`, `EnrollmentSubjectResource` | Scope rows to active official enrollment-subject plus published assignment; remove finance-derived status from Faculty visibility. |
| **SDD-08A - Grade policy, encoding, verification, and correction** | FS 7.2, TS 3.1 | grade-sheet evidence plus consolidated workflow | grading services/resources | Add effective-dated profile resolution/snapshot, immutable submission packages, Registrar return/verify/finalize, verified-only official release, and append-only correction supersession. Keep the hardcoded College profile and historical grades unchanged until institution approval and migration rules exist. |
| Grade correction | FS 7.2.5, TS 3.1.5 | grade-sheet/evaluation evidence | `GradeCorrectionService`, resource/tests | Keep Academic Head approval before official grade mutation. |
| Advising status | FS 7.1.4, TS 3.1.6 | grade evidence | service/API evidence present | Verify advisory-only status does not trigger sanctions or holds. |
| **SDD-08B - Attendance, guidance, behavior, and discipline evidence** | FS/TS gap plus continuing-enrollment gates | attendance threshold, confidential referrals, behavior/discipline clearance | No attendance, guidance-case, or discipline domain source | Define configurable attendance policy, review/appeal, confidential referrals, and resolved clearance evidence. No automatic FA/DRP or enrollment block until these sources exist. |

### SDD-09: Final Admin Readiness and QA Gate

**Goal:** Enter Pre-UAT only after admin/back-end feature slices are evidence-backed.

| Gate | Target |
| --- | --- |
| P1 raw-label cleanup | Browser/test audit of foundation, import, approval, request, payment, and scheduling admin screens. |
| Pre-UAT scenario data | `PreUatScenarioSeeder` remains local/UAT-only and idempotent. |
| Developer/Internal QA | Run after P1 hardening and TAL-13 backend contracts are either implemented or explicitly descoped. |
| Linear sync | Each SDD slice gets a small Linear issue or issue section with FS/TS/code/test evidence. No monolithic rescue/refinement issue. |

---

## Linear Mirroring Rule

Mirror this map logically, not mechanically:

- Keep the current Linear project as the roadmap container.
- Keep `TAL-12` as the active admin/backend readiness gate unless Linear is intentionally split.
- Update `TAL-13` so backend contracts are active dependencies before UAT, while Student Hub UI remains deferred/backlog.
- Use `TAL-15` only for optional/deferred admin surfaces that are not required for the backend/admin UAT gate.
- Create new Linear issues only for concrete SDD slices with a testable completion condition.

---

## Immediate Next Slice

Execute `SDD-00D Foundation Rebaseline and Completion Audit`. First remove the document-request domain end to end. Then produce the FS/TS-to-code evidence inventory, dependency-ranked P0/P1/P2 backlog, and completion counts. Resume a feature slice such as SDD-07A only when that audit identifies it as the next critical-path micro-sprint.

**Status context**

- **Completed evidence:** SDD-01 through SDD-04 cover curriculum readiness, delivery groups, scheduling solver/runtime/ingestion/commit/publish, Cloud Run smoke proof, and Admin/System foundation boundaries.
- **Completed TAL-13 backend evidence:** SDD-05A through SDD-05D cover applicant intake, student enrollment, PayMongo linked-enrollment finance-clearance parity, subject suggestion, and student dashboard aggregation.
- **Completed Accounting evidence:** SDD-06A verifies assessment/downpayment behavior; SDD-06B verifies payment/ledger immutability, idempotency, retry handling, finance-clearance parity, and admin action boundaries; SDD-06C verifies promissory lifecycle, payment-driven settlement, deadline processing, and exam-access accommodation separation; SDD-06D verifies typed Accounting adjustments without generic ledger CRUD.
- **Reconciliation audit:** The 2026-06-19 workflow matrix cross-references shared policy, admission, documents, status, graduation, faculty, grading, attendance, finance, and Academic Head requirements against FS/TS/code. It reopens only classified deltas and preserves completed compatible evidence.
- **Active target:** SDD-00D is blocking feature development. Document-request removal is its first micro-sprint; the remaining audit establishes measurable completion and selects the next dependency-critical backend/integration slice. Completed implementation remains preserved where compatible with the College-only baseline.
- **Deferred boundary:** Student Hub UI remains deferred until the backend/Admin closure slices are complete and Pre-UAT QA can begin.
