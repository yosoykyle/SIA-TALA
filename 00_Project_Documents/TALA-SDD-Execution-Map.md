# TALA SDD Execution Map

**Purpose:** Active spec-driven execution map for finishing the Admin Nexus, backend, scheduling, and TAL-13 backend contracts before Pre-UAT.
**Last Updated:** 2026-06-19
**Scope:** Backend + Filament Admin UI + TAL-13 backend contracts. Student Hub UI remains deferred.
**Status:** Active execution map for the next backend/Admin SDD pass. Scheduling/curriculum decisions from the 2026-06-17 audit are now locked unless the user reopens a specific decision.

---

## Authority

This map is the current execution control document after the 2026-06-19 consolidated-workflow reconciliation audit. It does not override FS/TS. Each SDD slice must still pass the listed FS/TS/code audit before implementation starts.

1. `TALA-Functional-Specification.md` defines business workflows and role boundaries.
2. `TALA-Technical-Specification.md` defines service, schema, UI, security, and verification contracts.
3. `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` is the newest client-approved business baseline; other evidence supplies forms/sheets for field and policy validation.
4. `TALA-Workflow-Reconciliation-Matrix.md` records the requirement-by-requirement FS/TS/code classification, benchmark gate, SDD owner, and dependency order.
5. The Laravel codebase proves current implementation state through migrations, models, services, policies, Filament resources, and tests.
6. `TALA-Local-Iteration-Checklist.md` and Linear mirror current execution state.

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
| `shs sample classrecord.md`, `MS.-OLIMBERIO-Blended-Online.Final-Grade-1.md` | Faculty class-record/final-grade layouts, SHS quarterly grades, college equivalent grade scales, pass/fail, INC, and DRP evidence. |
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
| Documents/OCR/requests | `DocumentUploadReviewService`, `DocumentRequestLifecycleService`, `ServiceRequestLifecycleService`; document/request Filament resources and tests. |
| Grades/faculty | `GradeEncodingService`, `GradeFinalizationService`, `GradeCorrectionService`, SHS/College grading services; class-list, grades, and grade-correction resources/tests. |
| Student Hub access | `/student/*` route protection and FAQ/help consumption are tested. `StudentDashboardService` now provides the dashboard aggregate contract for profile, enrollment, schedule, financials, finalized grades, requests, holds, notifications, and published FAQ/help links before UI work. |

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
| Applicant intake backend | FS 4.1, FS 5.4, TS 2.5, TS 3.3 | SHS evaluation, transferee evaluation sheets | `ApplicantIntakeService`, `ApplicantIntake`, applicant-linked `document_uploads`, focused tests | Done for backend contract: public registration service, pending applicant status, duplicate guard, document/OCR handoff, and approval-for-payment prerequisites. |
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
- Business evidence alignment: SHS and College class-record/final-grade sheets prove raw component-score layouts differ by level, while the workflow evidence confirms faculty records become official eligibility data only after Registrar verification/finalization.
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
| **SDD-06E - Finance operations and reconciliation delta** | FS 6.1-6.3; TS 3.12/3.14 | Collector/Recorder/Verifier, manual/online receipts, daily three-way reconciliation, private reminders, clearance/SOA | Immutable payment/ledger services exist; no maker-checker daily close or receipt reconciliation | Preserve existing posting services. Add duty permissions, receipt evidence, expected/actual close, variance reason, verifier approval, private reminders, and computed clearance/SOA. |
| **SDD-06F - Financial disposition and refunds** | FS 6.2.4; TS 3.12.3 | Current 15-day admission/enrollment-fee refund and post-enrollment tuition policy | Typed adjustments exist; no refund request/review/channel execution | Implement effective-dated component disposition, authorization, immutable refund entries, channel idempotency, and reconciliation. Withdrawal/cancellation must call this policy rather than assume retention/refund. |

**SDD-06A implementation evidence (2026-06-18)**

- Linear mirror: `TAL-24` (Done), linked as completed evidence for the active `TAL-12` readiness gate.
- Verified exact program/year fee templates take precedence over program-only and education-level defaults.
- Verified eligible new Grade 11/first-year students receive exactly 50% of tuition as a negative ledger entry while laboratory, miscellaneous, and other fees remain undiscounted.
- Verified repeated assessment does not duplicate fee or discount ledger entries and preserves the calculated balance.
- Verified configured minimum downpayment is calculated from net assessment: a payment below the threshold stays pending and meeting the threshold exactly triggers finance clearance and shared account handover.
- Focused proof: `php artisan test --compact tests/Feature/EnrollmentAssessmentServiceTest.php`.

**SDD-06B implementation evidence (2026-06-18)**

- Linear mirror: `TAL-25` (Done), blocking the active `TAL-12` readiness gate and related to `TAL-24`.
- Manual confirmation requires Accounting authorization, prior assessment, positive decimal amount, an allowed manual channel, normalized unique reference, and a non-future payment date.
- The document-shipping confirmation action reuses the allowed manual channels, required unique reference, explicit payment date, and atomic payment/ledger posting boundary; document fulfillment state closure remains in SDD-07.
- Payment, negative ledger credit, running balance, finance clearance/account handover, and audit evidence commit atomically; forced downstream failure is rollback-tested.
- Overpayments remain standard immutable payment credits and produce a negative balance without a separate wallet transaction.
- PayMongo processing row-locks the attempt, suppresses duplicate events, links the attempt and payment to one ledger entry, records processing errors, and rethrows so queue retries remain active.
- Payment Attempt, Confirmed Payment, and Ledger Entry resources remain list/view-only; the Enrollment and document-shipping actions are the authorized typed manual confirmation surfaces.

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

### SDD-07: Documents, OCR, and Service Requests Closure

**Goal:** Finish document/request workflows that staff must run before UAT.

**Workflow-source refresh (2026-06-19):** `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` was replaced by a 734-line consolidated institutional workflow and policy manual prepared by the project manager from the client's current approved business flow. It is the newest authority for current institutional business intent, but it does not discard stronger compatible FS/TS decisions. The workflow supplies outcomes, policies, roles, sequences, and current-institution values; FS/TS refine them into a secure, automated, auditable, and adaptable TALA design. Literal manual steps, UI wording, status names, and technical structure are not copied when an existing or benchmarked design satisfies the same approved outcome better.

**Approved reconciliation rule (refined 2026-06-19):** Treat the consolidated workflow as the approved business baseline and FS/TS/code as candidate or implemented system refinements. Preserve any existing decision that is compatible and improves control, usability, auditability, or adaptability. When sources conflict, compare the required outcome, chronology, implementation evidence, and external benchmark; replace only the incompatible portion. Ask the client/user only when ambiguity, trade-offs, or binding-rule conflicts remain. A later clarification may supersede either source, but no document wins wholesale.

**Rebaseline rule:** Do not restart every completed SDD and do not replace FS/TS wholesale. Preserve compatible implementation and stronger system contracts, reopen only the incompatible behavior, and synthesize the workflow plus benchmark into one normative FS/TS contract. Change code only after that feature-level reconciliation is approved.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| **SDD-07A - Admission documents and enrollment handover** | FS 4.1, FS 5.4, FS 8.1.1, TS 1.3.1, TS 3.12.1, TS 6.1 | Latest approved admission-gate/retention-document split, Registrar workflow, provisional undertaking, payment/receipt slot rule, and external government encoding | `ApplicantIntakeService`, `StudentEnrollmentService`, `EnrollmentFinanceClearanceService`, `EnrollmentHardCopyReceiptService`, `DocumentUploadReviewService`, enrollment/LIS columns and projections, COR permission, OCR services/resources | Requirements closed for implementation planning. Implement one generic admission lifecycle. Initially publish Regular SHS, SHS Transfer, Regular College Freshman, and College Transfer; keep cross-enrollee inactive. Treat old curriculum/ALS, foreign compliance, and support attributes as composable rules. Add readiness-gated payment/handover, stacked admission-capacity plans, tentative placement expiry, OR-secured capacity, and `PendingInstitutionalPlacement` fallback. Add assisted legacy onboarding for pre-TALA returnees without reliable records, while SDD-07D retains readmission approval/reactivation ownership. |
| **SDD-07B - Official document catalog and fulfillment** | FS 9.1-9.3, TS 3.14.2 | Manual document-request, payment, three-working-day preparation, identity/authorization, and release-log evidence | `DocumentRequestLifecycleService`, `DocumentRequestResource`, fixed model-owned type list, shipping/payment tests | Decide fixed approved types versus a versioned catalog; define eligibility, request basis, fees, holds, processing SLA/extension, pickup representative authorization, release acknowledgement, and delivery boundary. Preserve private evidence paths and immutable payment/audit records. |
| **SDD-07C - Enrollment adjustment requests** | FS 5.3, FS 9.4, TS 3.14.2, TS 3.18 | Drop-subject, section transfer, program transfer, and modality-change workflows | Generic `ServiceRequestLifecycleService`; section/delivery-group capacity and calendar services exist, but no typed request applies these domain changes | Define separate typed workflows for drop subject, section transfer, program transfer, and modality change. Each approval must validate its calendar window, curriculum/prerequisites, capacity/schedule effects, and fee delta, then apply changes atomically. Resolving a generic service request must never mutate enrollment records by itself. |
| **SDD-07D - Student record and status lifecycle** | FS 4.1, FS 5.2, FS 9.4, TS 2.5, TS 3.14.2 | Personal-data update/correction, missing-requirement receipt, withdrawal, LOA, readmission, transfer-out, inactivity, archive, and reactivation workflows | Generic `ServiceRequestLifecycleService`; `users` and `student_profiles` contain inactive/archived values, but no approved transition services implement these workflows | Define typed evidence, approvals, effective dates, notifications, access effects, financial/document holds, and reversible versus terminal transitions. Keep missing admission-document receipt in SDD-07A. Do not infer inactivity from attendance until an attendance source exists. |
| **SDD-07E - Graduation evaluation and credential release** | FS/TS gap to be added after grill | Curriculum-audit, deficiency, clearance, graduate approval, diploma-number, claiming, and external-reporting evidence | Curriculum, finalized grades, ledger, document uploads, and document requests exist; no graduation application/audit lifecycle exists | Decide MVP scope, then define an auditable graduation evaluation snapshot, deficiency resolution, approval, credential preparation/release, and external-reporting export boundary. Government portal submission remains external unless separately approved. |

**Institutional workflow change-impact audit (2026-06-19)**

| Finding | Classification | SDD treatment |
| --- | --- | --- |
| Payment/receipt secures capacity, while the workflow assigns a section and schedule before payment | Approved business sequence conflicts with the earlier sectionless design | Retain the stronger capacity-locking and audit architecture, but model tentative pre-payment placement separately from an OR-secured seat and official access. |
| Thirty-to-sixty-day provisional retention-document period | Approved retention flow conflicts only with the blanket application of the earlier seven-day rule | Preserve versioned requirements, per-item evidence, reminders, and typed holds; separate upfront admission gates from non-critical retention requirements and apply regulator-aware methods/deadlines. |
| Fifteen-day admission/enrollment-fee refund window and non-refundable tuition after official enrollment | Approved current policy conflicts with the earlier active strict-no-refund profile | Retain the stronger effective-dated financial-policy and immutable-ledger architecture, but replace its current-institution policy values and implement authorized refund mechanics. |
| Internal LIS/CHED completion tracking | Superseded by later client decision | Keep government encoding external; provide only the approved generic enrolled-roster export. |
| Exact 100-student campus ceiling, 30 document requests/day, one promissory note/year, and current fees | Approved current-institution values, not universal platform constants | Adopt as effective-dated configuration and enforce through typed services; benchmark operational and regulatory constraints where relevant. |
| Five continuing-enrollment clearance gates | Approved current workflow | Add the missing behavior/discipline evidence sources and typed holds before these gates can be enforced; do not simulate unavailable data. |
| Drop subject, section/program/modality transfer, record correction, LOA, readmission, transfer-out, archive/reactivation, and graduation | Genuine workflow coverage gaps | Added as SDD-07C through SDD-07E. Each requires typed services; the current generic request lifecycle is intake/audit evidence only. |
| Group-chat, Google Classroom, printed-module pickup/drop-off, LIS/CHED encoding, and physical filing details | External/manual operating context | Record integration/export boundaries only; do not create internal automation without an explicit scope decision. |
| Workflow SHS progression summary conflicts with DepEd Order No. 8, s. 2015 | Binding-rule conflict | Use regulator-aware subject remediation/retake policy; do not implement automatic whole-grade repeat from general average alone. |
| Workflow SHS component weights conflict with DepEd Table 5 | Binding-rule conflict | Keep component computation offline for MVP and make any future grading profile versioned; do not adopt the simplified conflicting row. |
| Workflow College grading scale/formula conflicts with FS, raw evidence, and current code | Unresolved institution-policy conflict | Add effective-dated grading profiles and grill the active scope/profile before runtime or historical-grade changes. |
| Workflow ordinary-week attendance/LMS restrictions for debt | Privacy/legal and policy conflict | Do not implement debt-based attendance/LMS restrictions or exam denial. Keep private collection, next-cycle enrollment holds, and lawful record-release holds. |

**Comprehensive-manual revalidation impact (2026-06-19 refresh)**

| Existing slice/contract | New manual impact | Required action |
| --- | --- | --- |
| SDD-01 to SDD-03 curriculum, sectioning, and scheduling | Core ownership and prerequisite/schedule mapping remain compatible. The current 100-student ceiling and progression policies are approved institutional requirements. | Keep solver work closed; add configurable institution capacity and benchmark progression constraints before implementing their gates. |
| SDD-05B enrollment handover / active SDD-07A | Direct conflict: the manual assigns section/schedule before payment and permits 30-to-60-day retention-document follow-up, while the current FS/TS requires seven-day physical completion and no section/access while temporary. | Block SDD-07A implementation until admission-gate versus retention-document behavior is resolved. |
| SDD-05C subject suggestion | Prerequisite enforcement is compatible; the workflow's SHS/College repeat and remediation outcomes now supersede the narrower earlier contract. | Benchmark regulator rules and model the approved progression outcomes and configurable thresholds before adding automation. |
| SDD-06C promissory lifecycle | Approved workflow says one approved note per academic year with dual Registrar/Accounting approval; code enforces one open request per enrollment and Accounting review. | Reopen cap/approval implementation; retain payment-driven settlement and RA 11984 exam-access accommodation unless the approved flow or law requires more. |
| SDD-06D financial disposition | Approved workflow establishes a 15-day admission/enrollment-fee refund window and non-refundable tuition after official enrollment; the strict no-refund contract is stale. | Reconcile the effective-dated policy, payment channels, immutable ledger treatment, and authorization before code changes. |
| SDD-07B document fulfillment | Manual now presents a dynamic catalog, fixed current prices, a 30-request daily capacity, extension reasons, release reminders, and hard financial/document holds. | Treat prices and capacity as effective-dated/configurable data, then grill catalog approval ownership and hold scope. |
| SDD-07C/07D/07E | Manual confirms drop/add, section/program/modality changes, personal-data correction, LOA/readmission/transfer-out, archive/reactivation, summer, and graduation workflows. | Keep these as required typed backend/admin slices; generic `service_requests` resolution remains insufficient. |
| SDD-08 faculty/grades | Manual adds SHS component weights, College grading/absence rules, dual hard/soft submission, Registrar audit, and faculty delinquency lists/attendance restrictions. | Revalidate grade formulas and privacy/legal boundaries; do not expose student balances or automate attendance restrictions from unavailable attendance data. |
| Accounting role model | Approved workflow separates Collector, Recorder, and Verifier duties, while the application has one Accounting role. | Model segregation through permissions/actions and assignment controls; create separate seeded roles only if the approved operating model requires separate accounts. |

**Admission-document benchmark finding (2026-06-19):** [DepEd Order No. 017, s. 2025](https://www.deped.gov.ph/2025/06/13/june-13-2025-do-017-s-2025-revised-basic-education-enrollment-policy/) applies the revised Basic Education Enrollment Policy to public and private basic-education schools. It defines `temporarily enrolled` for documentary deficiencies, provides school-to-school transfer timelines for learner records, and permits official electronic/scanned credential transmission for specified grade-level transfers without requiring later physical copies. Therefore, the current blanket SHS rule requiring every physical credential within seven days before section/schedule/class access is not safe to retain as a universal contract. SDD-07A must separate minimum admission/identity evidence from follow-up school records and must model regulator-specific satisfaction methods and deadlines. This finding does not by itself decide the College workflow.

**SDD-07A capacity benchmark:** Oracle PeopleSoft Campus Solutions documents configurable enrollment targets by institution-defined cohort, population, and division, including term, academic career, program, admit type, and program status criteria; this supports scoped admission-capacity plans rather than a universal campus ceiling. FEU High School separately documents a paid reservation fee that guarantees a strand/grade-level slot, credits the reservation toward enrollment, and allows reservation while documents are incomplete; this supports keeping capacity reservation distinct from official enrollment and exact section assignment. Sources: [Oracle enrollment-management targets](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lsad/concept_UnderstandingEnrollmentManagementTargets-ab78b6.html), [FEU High School slot reservation](https://www.feuhighschool.edu.ph/slot-reservation/).

**SDD-07A readiness and seat-consumption benchmark:** Mature SIS enrollment separates term/session dates, class/schedule readiness, planning validation, payment, and actual enrollment. TALA therefore treats pre-payment placement as non-protected planning and requires calendar/enrollment window, published offering/policy, approved capacity plan, ready curriculum/subjects, planned sections/delivery groups, faculty eligibility/availability or approved override, and committed/published schedule readiness before payment clearance and handover unless an authorized institution-controlled exception is recorded. OR-backed payment secures capacity across every matching capacity-plan scope. Sources: [Oracle class enrollment processing](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lssr/concept_UnderstandingClassEnrollmentProcessing-ab456f.html), [Oracle terms and sessions](https://docs.oracle.com/cd/E29376_01/hrcs90r5/eng/psbooks/lsfn/htm/lsfn08.htm), [Oracle enrollment and validation appointments](https://docs.oracle.com/cd/E56917_01/hrcs90r5/eng/cs/lssr/concept_UnderstandingEnrollmentandValidationAppointments-ab459b.html).

**SDD-07A admissions-pipeline benchmark:** Oracle PeopleSoft uses configurable checklist items with responsible actor, status, and due date, while Frappe Education separates the published admission offering, applicant record and decision, student-master creation, and program enrollment. TALA therefore uses one shared admission lifecycle and treats applicant characteristics as composable policy dimensions rather than separate hardcoded pipelines or one mutually exclusive applicant-type enum. Sources: [Oracle checklist setup](https://docs.oracle.com/cd/E56917_01/cs9pbr4/eng/cs/lscc/concept_UnderstandingChecklistSetup-ab6ca5.html), [Frappe Student Admission](https://docs.frappe.io/education/student_admission), [Frappe Student Applicant](https://docs.frappe.io/education/student-applicant).

**SDD-07A document-storage benchmark:** TALA retains the submitted source file as evidence where a file exists, stores verified operational facts separately, and treats OCR/parser output as a non-authoritative derivative. The storage-class matrix further separates ordinary credentials, official school transmissions, ID photos, restricted medical/SEN/IP/immigration evidence, transaction proof, generated official artifacts, controlled imports, and physical custody. Gate versus retention classification is independent from storage treatment. File handling follows [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) controls, while purpose limitation and retention/disposal follow the [Philippine Data Privacy Act IRR](https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/).

**SDD-07A old-curriculum benchmark:** [CHED's K to 12 transition guidance](https://chedk12.wordpress.com/lifelonglearner/) states that high-school graduates under the old basic education curriculum remain eligible to enroll in College, subject to admitting-institution requirements and possible bridging/special assessment. [MMDC's admission requirements](https://admissions.mmdc.mcl.edu.ph/hc/en-us/articles/33489744398733-Requirements-for-Old-Curriculum-Graduates-Senior-High-School-Graduates-and-Transferees) treat old-curriculum graduates without College units as College applicants and require an old-curriculum Form 138 establishing first-year College eligibility alongside identity and Good Moral evidence. [Mindoro State University's AY 2026-2027 admission notice](https://www.minsu.edu.ph/news/details/394) likewise identifies old-curriculum graduates without SHS as qualified College applicants, separately from ALS/PEPT pathways. The client clarification describes learners who completed Grade 10/high school before SHS existed and now want to continue studying; TALA therefore activates Old Curriculum College as a prior-credential pathway under Regular College Freshman and keeps the former Old Curriculum SHS row as inactive trace evidence only, with no public route or resolver fallback.

**SDD-07A ALS benchmark:** DepEd LIS support documents ALS portfolio assessment as an additional eligibility requirement for Grade 7 and Grade 11 and specifies Grade 11 enrollment using Junior High School-level portfolio-assessment passer evidence. CHED has also issued transition guidance allowing certain ALS high-school level passers to apply as first-year College students subject to HEI admission policies, but the current client clarification accepts ALS only for incoming Grade 11. TALA therefore models ALS only as an `als_jhs` prior-credential pathway under Regular SHS Grade 11 in the current deployment and leaves any College ALS pathway inactive unless a future policy explicitly approves it. The requirement policy must accept one authoritative ALS Junior High School-level eligibility outcome and deduplicate Certificate of Rating, Certificate of Completion, and portfolio-assessment evidence when they prove the same purpose. Sources: [DepEd LIS ALS Portfolio Assessment guide](https://support.lis.deped.gov.ph/support/Manuals/ALS-Portfolio-Assessment-Tutorial-Guide.pdf), [DepEd LIS support updates](https://support.lis.deped.gov.ph/support/), [CHED ALS admission advisory](https://legacy.ched.gov.ph/ched-to-heis-accept-als-passers-for-ay-2018-2019/).

**SDD-07A foreign-compliance benchmark:** The Bureau of Immigration defines Student Visa 9(f) for foreign nationals at least 18 taking higher-than-high-school study, while Philippine consular and school guidance distinguish visa, permit, medical, and acceptance evidence handled through external immigration/consular processes. TALA therefore keeps foreign compliance inactive by default for MVP and allows publication only when the institution confirms an accepted base offering and activates a compliant evidence policy. TALA stores restricted compliance evidence, verification status, deadlines, and holds, but does not submit to or update BI, DFA, CHED, DepEd, embassy, or other regulator systems. Sources: [BI Student Visa 9(f)](https://immigration.gov.ph/student-visa-9f/), [BI Visas](https://immigration.gov.ph/visas/), [Philippine Embassy Singapore Student Visa](https://www.philippine-embassy.org.sg/consular/visa/procedures-and-requirements-for-student-visa/).

**SDD-07A IP/SEN support benchmark:** [RA 11650](https://lawphil.net/statutes/repacts/ra2022/ra_11650_2022.html) establishes inclusion and support services for learners with disabilities, not a denial pathway. [RA 8371/IPRA](https://faolex.fao.org/docs/pdf/phi13930.pdf) and DepEd IPEd policy references support culturally responsive participation for Indigenous learners. [RA 10173/Data Privacy Act](https://privacy.gov.ph/data-privacy-act/) requires sensitive personal information to be processed only under valid purpose and safeguards. TALA therefore treats IP and disability/SEN as optional support attributes that may collect restricted evidence for configured accommodations/support only, with no automated rejection, ranking, sectioning, billing, discipline, or public-reporting effect unless separately approved.

### SDD-08: Faculty, Grades, Attendance, and Student-Support Closure

**Goal:** Make faculty/admin grade workflows consistent from class list to final grade correction.

| Feature slice | FS/TS anchors | Business evidence | Current evidence | Target |
| --- | --- | --- | --- | --- |
| Class list visibility | FS 7.1, TS 3.7 | SOA finance-cleared evidence | `FacultyClassListService`, `EnrollmentSubjectResource` | Verify only finance-cleared/allowed students appear and labels hide balances from faculty. |
| **SDD-08A - Grade policy, encoding, verification, and correction** | FS 7.2, TS 3.1 | grade-sheet evidence plus consolidated workflow | grading services/resources | Preserve encoding/finalization/correction controls. Add versioned grading-profile snapshots and Registrar verification/return stage; resolve the College profile conflict before code changes. |
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

Continue SDD-07A implementation. The generic pipeline, all four initial public-offering requirement contracts, Old Curriculum College pathway, inactive/no-public-route Old Curriculum SHS trace row, ALS Junior High School-to-Grade-11 pathway, inactive-by-default foreign compliance profile, purpose-limited IP/SEN support attributes, readiness-gated payment/handover, stacked capacity-plan enforcement, readiness dashboard, tentative placement expiry, returning/legacy boundary, and inactive cross-enrollee decision are locked. Follow the matrix dependency order: SDD-07A, SDD-06E/06F, SDD-05C-R/08A, SDD-07B, SDD-07C, SDD-07D, SDD-08B, SDD-07E, then SDD-09.

**Status context**

- **Completed evidence:** SDD-01 through SDD-04 cover curriculum readiness, delivery groups, scheduling solver/runtime/ingestion/commit/publish, Cloud Run smoke proof, and Admin/System foundation boundaries.
- **Completed TAL-13 backend evidence:** SDD-05A through SDD-05D cover applicant intake, student enrollment, PayMongo linked-enrollment finance-clearance parity, subject suggestion, and student dashboard aggregation.
- **Completed Accounting evidence:** SDD-06A verifies assessment/downpayment behavior; SDD-06B verifies payment/ledger immutability, idempotency, retry handling, finance-clearance parity, and admin action boundaries; SDD-06C verifies promissory lifecycle, payment-driven settlement, deadline processing, and exam-access accommodation separation; SDD-06D verifies typed Accounting adjustments without generic ledger CRUD.
- **Reconciliation audit:** The 2026-06-19 workflow matrix cross-references shared policy, admission, documents, status, graduation, faculty, grading, attendance, finance, and Academic Head requirements against FS/TS/code. It reopens only classified deltas and preserves completed compatible evidence.
- **Active target:** SDD-07A implementation is underway. Completed backend/admin passes now cover versioned admission offerings, requirement policies, normalized document items, materialized applicant checklist snapshots, resolver-driven intake creation, fail-closed missing/ambiguous offering behavior, admission-gate-only payment approval, retention-item non-blocking behavior, checklist-state sync from document review, itemized retention undertakings, deadline processing, overdue hold evidence, student/enrollment context attachment, configured admission-capacity plan stacks, OR-backed secured reservations, idempotent reservation retries, full-capacity rollback before handover, the mandatory backend readiness gate for materialized SDD-07A applicant-checklist flows before finance-cleared capacity security/handover, first-pass Filament setup surfaces for offerings, policies, requirement items, and capacity plans under `manage-admission-setup`, and the Registrar admission readiness dashboard/service exposing term/offering blockers, policy/item counts, capacity remaining, schedule/readiness status, and setup drilldowns under `manage-admission-setup`/`view-global-records`. Remaining SDD-07A work: tentative placement expiry, canonical enrollment-state cleanup, enrolled-roster export, extension/reminder UI, and notification delivery. Confirmed follow-on work includes SDD-06E/06F, SDD-05C-R, SDD-07B through 07E, and SDD-08A/08B.
- **Deferred boundary:** Student Hub UI remains deferred until the backend/Admin closure slices are complete and Pre-UAT QA can begin.
