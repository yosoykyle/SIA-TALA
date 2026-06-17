# TALA SDD Execution Map

**Purpose:** Active spec-driven execution map for finishing the Admin Nexus, backend, scheduling, and TAL-13 backend contracts before Pre-UAT.
**Last Updated:** 2026-06-17
**Scope:** Backend + Filament Admin UI + TAL-13 backend contracts. Student Hub UI remains deferred.
**Status:** Active execution map for the next backend/Admin SDD pass. Scheduling/curriculum decisions from the 2026-06-17 audit are now locked unless the user reopens a specific decision.

---

## Authority

This map is the current execution control document after the 2026-06-17 scheduling/curriculum decision closure. It does not override FS/TS. Each SDD slice must still pass the listed FS/TS/code audit before implementation starts.

1. `TALA-Functional-Specification.md` defines business workflows and role boundaries.
2. `TALA-Technical-Specification.md` defines service, schema, UI, security, and verification contracts.
3. `business-evidence/` supplies real school forms/sheets for field and policy validation.
4. The Laravel codebase proves current implementation state through migrations, models, services, policies, Filament resources, and tests.
5. `TALA-Local-Iteration-Checklist.md` and Linear mirror current execution state.

If a refinement list, archived plan, old prototype, or previous grilling-generated iteration conflicts with this map, this map wins for current execution. Older refinement files stay historical unless a specific item is re-entered here as a module-feature slice or a linked Linear child issue.

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
| `Copy-of-SIA-SHS-EVALUATION-FORM.md` | SHS evaluation/intake structure and learner record expectations. |
| `shs-tf.md` | SHS semester fee, downpayment, and monthly tuition assumptions. |
| `SOA-SHS-1.md`, `SOA-2nd-year-COLLEGE-1.md` | LRN/course/balance/paid/remaining/monthly payment/penalty shapes for ledger and assessment logic. |
| `MS.-OLIMBERIO-Blended-Online.Final-Grade-1.md` | Faculty grade-sheet layout, grade scales, pass/fail, INC, and DRP evidence. |

Use business evidence to clarify fields and policies. Do not copy raw sheet layout into the app as the UI contract.

---

## Current Code Evidence Snapshot

| Area | Current evidence |
| --- | --- |
| Admin/System | `UserResource`, `RoleResource`, `ActivityResource`, `FaqEntryResource`, `SystemSettingResource`; `UserAccountLifecycleService`; RBAC, FAQ, and direct-route denial tests; SDD-04/TAL-23 verification passed. |
| Academic foundation | `ProgramResource`, `SubjectResource`, `CurriculumResource`, `TermResource`, `SectionResource`, `RoomResource`; `AcademicFoundationFilamentResourceTest`; `CurriculumImportServiceTest`. |
| Scheduling | `SectionPlanningService`, `DeliveryPatternService`, `SectionDeliveryGroupService`, `EnrollmentSectioningService`, `FacultyAvailabilityService`, `FacultyAvailabilityChangeRequestService`, `ScheduleGenerationService`, `ScheduleSolverSnapshotService`, `ScheduleCloudResultIngestor`, `ScheduleDraftRowReviewService`, `ScheduleCommitService`, `SchedulePublishService`; scheduling resources/tests; `DeliveryPatternResource`, `SectionDeliveryGroupResource`, Section delivery-groups relation manager, delivery-group-aware Official Schedules and Schedule Draft review actions; Cloud Run solver package now parses/enforces `section_delivery_group_id`; deployed revision `tala-scheduler-solver-00004-wtx` passed authenticated `/health`, authenticated `/solve`, and unauthenticated 403 IAM smoke proof. |
| Enrollment/student records | `StudentProfile`, `Enrollment`, `EnrollmentSubject`; `EnrollmentHardCopyReceiptService`, `EnrollmentAssessmentService`; list/view admin resources exist, but applicant intake and student self-service orchestration services are missing. |
| Finance | `PaymentConfirmationService`, `InstallmentPolicyService`, `FeeTemplateResource`, `PaymentAttemptResource`, `PaymentResource`, `LedgerEntryResource`, `PromissoryNoteResource`; payment and assessment tests. |
| Documents/OCR/requests | `DocumentUploadReviewService`, `DocumentRequestLifecycleService`, `ServiceRequestLifecycleService`; document/request Filament resources and tests. |
| Grades/faculty | `GradeEncodingService`, `GradeFinalizationService`, `GradeCorrectionService`, SHS/College grading services; class-list, grades, and grade-correction resources/tests. |
| Student Hub access | `/student/*` route protection and FAQ/help consumption are tested. Dashboard, schedule, grades, financials, documents, enrollment, and requests still need data-backed backend contracts before UI work. |

Explicit missing TAL-13 backend contracts as of this audit:

- `ApplicantIntakeService`
- `StudentEnrollmentService`
- `SubjectSuggestionService`
- `StudentDashboardService`

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

### SDD-01: Curriculum Template and Readiness Scopes (`TAL-20`)

**Goal:** Make curriculum data safe for scheduling and sectioning before changing scheduler behavior.

| Feature slice | FS/TS anchors | Current evidence | Target |
| --- | --- | --- | --- |
| Unified curriculum template | FS 5.1.2, TS 3.17 | `CurriculumImportTemplate`, `CurriculumImportService` | Replace old `Lec_Hours` scheduling dependency with `Weekly Contact Hours`; add `Academic Subject Type`, `Scheduling Group`, and `Delivery Rule Override`. |
| Import validation | FS 5.1.2, TS 3.17 | import preview/commit services | Store zero-valid-row files only as non-committable preview/audit evidence; commit requires `error_rows = 0` and `valid_rows > 0`; allow partial SHS-only or College-only imports scoped to affected curriculum scopes. |
| Curriculum scope readiness | FS 5.1.2, TS 3.17, TS 3.6.3 | no explicit current readiness marker | Add explicit readiness by `curriculum_id + year_level + curriculum_period`, displayed as `program + curriculum version + year/grade + period`; old rows become `needs_review` until confirmed. |
| Filament admin surface | TS 3.17, TS 5 | current Import Batches and Curriculum resources | Add coverage/readiness view/action using Filament v5 tables, filters, infolists, and actions; keep business rules in services. |

**Locked SDD-01 decision closure (2026-06-17):**

- Add an explicit readiness model/table keyed by `curriculum_id + year_level + curriculum_period`; derive program through the curriculum relationship.
- Store scheduler-facing offering fields on `curriculum_subjects`: `weekly_contact_hours`, `academic_subject_type`, `scheduling_group`, and constrained nullable `delivery_rule_override`.
- Keep the existing database `department` column as the education-level storage for MVP, but template/UI wording must use `Education Level`; legacy `Department` template headers fail strict validation.
- Import preview may store zero-valid-row files as non-committable audit evidence. Commit requires `error_rows = 0` and `valid_rows > 0`; partial SHS-only or College-only imports are valid and affect only imported scopes.
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
| Applicant intake backend | FS 4.1, FS 5.4, TS 2.5, TS 3.3 | SHS evaluation, transferee evaluation sheets | missing `ApplicantIntakeService` | Create service for public registration, applicant status, duplicate guard, document/OCR handoff, and staff finalization prerequisites. |
| Student enrollment backend | FS 4.2, FS 5.4, TS 3.12 | SOA/enrollment fields, curriculum/evaluation evidence | missing `StudentEnrollmentService` | Create service for regular enrollment, returnee detection, payment/clearance gates, section capacity, and COR readiness. |
| Subject suggestion backend | FS 4.2, FS 5.3, TS 3.4.1 | evaluation/bridging/grade evidence | missing `SubjectSuggestionService` | Create prerequisite-aware subject suggestion for irregulars/transferees, including active INC/failed/missing-history blockers. |
| Student dashboard backend | FS 4.3, FS 6, FS 7, FS 9, TS 5.8 | SOA and grade-sheet evidence | missing `StudentDashboardService` | Aggregate schedule, balance, grades, document requests, grade corrections, holds, and FAQ/help links for future UI. |

Do not build the Student Hub pages in this phase. Tests may call services directly or through narrow backend routes/actions if those routes already exist.

### SDD-06: Accounting Backend/Admin Closure

**Goal:** Make finance policies and admin surfaces consistent with business evidence.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| Assessment/downpayment | FS 6.1-6.2, TS 3.12 | `shs-tf.md`, SOA files | `EnrollmentAssessmentService`, tests | Verify fee template scope, freshmen discount, downpayment clearance, and idempotent assessment posting. |
| Payments/ledger | FS 6.2-6.3, TS 3.12, TS 3.14 | SOA paid/balance/monthly/penalty shapes | `PaymentConfirmationService`, PayMongo tests/resources | Keep ledger immutable, gateway idempotent, and admin resources list/view or service-action only. |
| Promissory lifecycle | FS 6.2.3, TS 2.5.3, TS 8.8 | SOA balance evidence | accounting-side resource exists | Clarify/implement student request backend if needed before UI; promissory must not clear finance status. |
| Accounting adjustments | FS 6.3, TS 8.8 | SOA corrections/balances | ledger list/view only | Build typed adjustment service/action only if UAT requires manual corrections. |

### SDD-07: Documents, OCR, and Service Requests Closure

**Goal:** Finish document/request workflows that staff must run before UAT.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| Document upload review | FS 4.1, FS 5.4, TS 1.3.1, TS 6.1 | SHS/evaluation field evidence | `DocumentUploadReviewService` | Verify OCR is assistive only and manual review owns official fields. |
| Document requests | FS 9.1-9.3, TS 3.14.2 | document/evaluation evidence | `DocumentRequestLifecycleService`, resources | Verify request type options, fee confirmation, fulfillment, shipping proof, and private paths. |
| Service requests/dropout | FS 9.4, TS 3.14.2 | SOA/debt evidence | `ServiceRequestLifecycleService` | Verify status labels, notes, cancellation/rejection reasons, and relationship labels. |

### SDD-08: Faculty and Grades Closure

**Goal:** Make faculty/admin grade workflows consistent from class list to final grade correction.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| Class list visibility | FS 7.1, TS 3.7 | SOA finance-cleared evidence | `FacultyClassListService`, `EnrollmentSubjectResource` | Verify only finance-cleared/allowed students appear and labels hide balances from faculty. |
| Grade encoding/finalization | FS 7.2, TS 3.1 | grade-sheet evidence | grading services/resources | Verify SHS/College grade rules, INC lifecycle, finalization/reopen policy. |
| Grade correction | FS 7.2.5, TS 3.1.5 | grade-sheet/evaluation evidence | `GradeCorrectionService`, resource/tests | Keep Academic Head approval before official grade mutation. |
| Advising status | FS 7.1.4, TS 3.1.6 | grade evidence | service/API evidence present | Verify advisory-only status does not trigger sanctions or holds. |

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

Start `SDD-05: TAL-13 Backend Contracts Before UAT`.

1. Treat SDD-01, SDD-02, SDD-03, and SDD-04 as completed implementation/verification evidence for curriculum readiness, delivery groups, solver/runtime/ingestion/commit/publish, Cloud Run smoke proof, and Admin/System foundation boundaries.
2. Reconcile existing Linear `TAL-16` through `TAL-19` legacy `SDD-04A-D` titles to the current SDD-05 TAL-13 backend contract numbering before implementation starts.
3. Keep Student Hub UI deferred; do not enter Pre-UAT until the active TAL-13 backend contracts are either implemented or explicitly descoped.
