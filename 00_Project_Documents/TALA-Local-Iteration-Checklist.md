# TALA Local Iteration Checklist (DB-First)

**Location Purpose:** Local execution checklist aligned with the 3 main specs and Linear roadmap.
**Last Updated:** 2026-06-09
**Linear Project:** TALA Iterative Implementation Map (DB-First)

---

## Scope Lock (Approved)

- Current active scope is **Backend + Filament Admin UI only**.
- Shared student-domain backend logic required by admin workflows is **not deferred**. This includes student profiles, enrollments, assessments, payment clearance, ledgers, promissory holds, document requests, OCR/manual-review state, grades, class-list visibility, and calendar gates.
- Student Portal UI and student self-service contracts are **deferred** to a separate iteration after backend/admin stabilization.
- No Student Portal frontend or student self-service contract work should be marked complete under backend/admin iterations before `TAL-13`.
- Pre-TAL-12 rescue scope is approved as of 2026-06-07. The rescue is an execution track, not a planning-only phase.
- Do not update Linear from this checklist automatically; when Linear is updated later, mirror this exact local scope boundary.

---

## Spec-First Gate (Mandatory)

- Before starting any iteration task, read `TALA-Functional-Specification.md` and `TALA-Technical-Specification.md` first.
- If Functional and Technical specs conflict, pause implementation and resolve the conflict in docs before coding.
- Do not mark any checklist item done if the implemented behavior is not traceable to FS/TS sections.
- Backend/service completion alone does not make a staff module complete. A staff-facing module is admin-ready only when the required Filament Resource/Page/Action exists, role access is enforced, and the panel action calls the tested backend service.

---

## Pre-TAL-12 Rescue Execution Track (Approved 2026-06-07)

**Purpose:** Rapidly develop the smallest viable working SIS path while protecting the approved rescue boundaries.

**Approved Descope / Freeze**

- [x] Full Student Hub self-service remains outside TAL-12/TAL-12A except authenticated access and published FAQ consumption.
- [x] Student-side promissory upload/pending/replacement/settlement lifecycle remains outside TAL-12; Accounting-only approved promise tracking may remain.
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
- [ ] Enforce `>98%` auto-assignment coverage for feasible inputs as the solver target.
- [x] Enforce `100%` hard-constraint validity for committed `section_meetings`.
- [x] Keep `ScheduleCommitService` as final authority for creating `section_meetings`, synchronizing `section_teacher`, and recording activity.
- [ ] Preserve PayMongo / GCash as required external payment infrastructure with webhook-confirmed evidence and idempotent ledger posting.
- [ ] Preserve controlled legacy migration as strict template -> preview/validation -> commit -> audit, with no in-browser row repair/freeform spreadsheet import.

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
- [x] Validated solver rows against immutable snapshot section demand, section capacity contract, mandatory faculty assignment, faculty-subject eligibility, snapshot faculty availability, required room/modality/time rules, committed meetings, and internal solver overlaps.
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

**Remaining Scheduling Boundary After Slice 11**

- [ ] Faculty availability change requests after lock/deadline remain a separate workflow. The current viable flow supports initial submission and Registrar locking for solver input; post-lock change requests should be handled only if approved as the next rescue slice.

**Slice 12 Evidence - End-to-End Scheduling QA / Fix Pass**

- [x] Added `SchedulingEndToEndWorkflowTest` as the integrated rescue regression for the full viable scheduling path.
- [x] Verified the workflow uses real Laravel services for Registrar period setup, faculty availability submission, Registrar locking, schedule generation, solver dispatch handling, solver-result ingestion, draft-row review state, and final commit.
- [x] Kept the test deterministic by faking only the solver client response; the test does not require Cloud Run uptime, local Docker, or a manual queue worker.
- [x] Confirmed the generated solver rows become `ok` draft rows, the schedule run moves to `under_review`, commit creates official `section_meetings`, and `section_teacher` assignments are written.
- [x] Verified with focused tests: `SchedulingEndToEndWorkflowTest`, `ScheduleGenerationServiceTest`, `ScheduleSolverDispatchJobTest`, `ScheduleCloudResultIngestorTest`, `ScheduleCommitServiceTest`, `FacultyAvailabilityServiceTest`, and `ScheduleSolverSnapshotServiceTest`.
- [x] No user-side Google Cloud Console, Docker, or manual queue action is required for this QA slice. A live Cloud Run smoke test remains optional because the deployed solver was already verified separately.

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
- [x] Implement per-level cutover behavior (SHS/College)
- [x] Wire cutover keys from `system_settings`
- [x] Add PHPUnit coverage for allow/deny gate behavior
- [x] Add tests for blocked late edits + audit trail

**DoD**
- [x] F1 behavior enforced in code
- [x] SHS/College gates test-verified

---

## Iteration 3 - F10 Installment Services, Jobs, and Tests (`TAL-7`)

- [x] Spec-First Gate executed: FS/TS installment policy verified before implementation
- [x] Implement installment service contract/state transitions
- [x] Implement monthly EOM due evaluator
- [x] Implement 3-day grace + overdue handling
- [x] Implement recurring 5% penalty per missed month
- [x] Enforce promissory non-clearing in clearance logic
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
- [x] Faculty/Grades Filament flow works end-to-end: class list -> grade encoding -> submission/finalization -> correction review/resolution/override; Faculty Class Lists are list/view plus typed grade actions only, Grade Oversight is list/view plus typed override actions only, and Grade Correction is list/view plus typed lifecycle actions only, with official correction grade changes calculated from College/SHS period inputs and no generic enrollment-subject, grade, correction create/edit form, direct final-grade override, or manual remarks override
- [x] Academic Head admin screens/actions wired for read-only oversight and approved override actions only
- [x] System Super Admin screens/actions wired for users/read-only roles/audit/FAQ while keeping academic and financial domains read-only where required; generic `system_settings` UI is hidden/internal for TAL-12
- [x] Add Filament/Livewire tests or browser smoke tests for role-specific navigation and critical actions
- [x] Verify seeded staff accounts can perform only their allowed Admin Nexus workflows

**DoD**
- [x] Each staff role can complete its current TAL-12A implemented admin workflows inside Filament
  - 2026-06-02 admin-side clarification hardening pass completed: Academic Head finance access narrowed to read-only finance status, fee template/downpayment rules, installment policy summary, and promissory status/tag; FAQ categories fixed; document request type selections fixed. No migration-log update required because this changed UI/policy/spec contracts only, not table structure.
  - 2026-06-03 role/resource reconciliation completed: vendor-backed `Role` and `Activity` policies are explicitly registered; only System Super Admin sees Roles/Audit/Users/FAQ; Import Batches are scoped as `Import Batch Audit` with no generic create/edit routes; Grade Oversight has no raw create/edit grade form; grade-correction grade changes record already-approved Academic Head authorization; full import upload/preview pages, COR template editor, document-catalog admin, rich dashboard metrics, and any separate System Health admin page are not claimed as completed TAL-12A evidence unless implemented through separate items. Faculty availability self-service was later implemented as rescue Slice 11.
  - 2026-06-05 Import Batch lifecycle hardening completed: `ImportBatchResource` remains audit-only with no raw file-path/error-log form; commit/cancel controls delegate to `ImportBatchLifecycleService`, which validates Registrar import permissions, allows only pending batches, writes commit metadata when appropriate, and records lifecycle activity. `ImportBatch` owns import type/status options so Filament filters and badges do not duplicate enum literals.
  - 2026-06-05 Schedule Draft commit hardening completed: `ScheduleGenerationRunResource` remains list/view with no raw run form; Commit is visible only for committable generated/under-review runs and delegates to `ScheduleCommitService`. The service validates Registrar scheduling permission, rejects conflicted or incomplete draft rows, creates official `section_meetings`, synchronizes `section_teacher`, writes commit metadata, and records lifecycle activity inside one transaction.
  - 2026-06-05 COR Verification lifecycle hardening completed: `CorVerificationResource` remains list/view only with no generic token/status/timestamp form; Supersede/Revoke delegate to `CorVerificationLifecycleService`, which validates `manage-lis`, accepts only valid transitions, requires a typed revoke reason before revoked state, writes `revoked_at` and `revocation_reason`, and records lifecycle activity. `CorVerification` owns status options/colors so filters and badges do not duplicate literals; token detail display uses descriptive student/term/enrollment labels instead of raw foreign-key IDs.
  - 2026-06-06 Document Upload review lifecycle hardening completed: `DocumentUploadResource` remains Registrar list/view review queue with no generic create/edit form or raw OCR/payload editing; Approve/Needs Correction/Reject controls delegate to `DocumentUploadReviewService`, which validates `approve-documents`, allows only active review states, treats approved/rejected uploads as terminal lifecycle evidence, requires typed correction/rejection reasons, captures approved payload snapshots, and records document-review activity. `DocumentUpload` owns review status options/colors so Filament filters and badges do not duplicate literals; the detail view now uses descriptive student/uploader/term/reviewer labels and source-file evidence instead of raw internal IDs or private storage path display.
  - 2026-06-05 Grade Correction official-change hardening completed: `GradeCorrectionResource` remains list/view/action-only; student/backend intake owns ticket creation and server-derived fields; Registrar lifecycle actions own review/reject/resolve transitions; the approved grade-change action collects College Prelim/Midterm/Final raw scores or SHS Q1/Q2 grades and `GradeCorrectionService` derives final grade/remarks through the grading services; no generic correction create/edit/raw status form, direct final-grade override, or manual remarks override remains in TAL-12A.
  - 2026-06-03 Official Schedule surface hardening completed: `SectionMeetingResource` keeps Registrar manual assignment but replaces raw schedule/commit fields with typed term/section/subject/faculty/day/time/modality/room controls; commit metadata is server-derived; direct edit/delete of committed meetings is blocked; schedule-change apply reuses the assignment conflict guard. Faculty availability-window enforcement was completed later through rescue Slice 11.
  - 2026-06-05 Schedule Change lifecycle boundary tightened: schedule-change forms remain typed and old/new payloads remain internal snapshots; direct edit access is now limited to `proposed` requests, while approved/applied/rejected records are lifecycle evidence only. Approve/Apply table actions now delegate to `ScheduleChangeLifecycleService`, which validates `authorize-overrides` or `manage-schedules`, accepts only valid state transitions, applies normalized official-meeting changes through the assignment conflict guard, and records lifecycle activity.
  - 2026-06-05 Service Request lifecycle note capture completed: `ServiceRequestResource` keeps list/view/action-only boundaries; Resolve exposes optional `resolution_note`, Reject requires `rejection_reason`, and Registrar Cancel requires `cancellation_reason`. Notes/reasons are normalized into activity properties and notification metadata instead of a discarded generic note or mutable request field.
  - 2026-06-06 System Super Admin staff account lifecycle hardening completed: Roles are a list-only seeded permission matrix with no create/edit route, action, page class, form, or permissions multi-select; staff user direct edit is limited to other non-archived accounts and archive/restore owns archived lifecycle. `UsersTable` now delegates Archive/Restore Account actions to `UserAccountLifecycleService`, which validates `archiveStaffAccount`/`restoreStaffAccount` policy abilities, blocks self/invalid state transitions, requires an official archive reason, clears roles on archive, requires one approved staff role on restore, and records lifecycle activity.
  - 2026-06-06 Audit Log detail display hardening completed: `ActivityResource` remains a System Super Admin read-only list/view evidence surface, and `ActivityInfolist` now formats activity properties into labeled audit metadata lines through `ActivityPropertiesFormatter` instead of exposing raw JSON/key-value payload UI.
  - 2026-06-05 Staff User Management status/role control hardened: direct staff create/edit status uses a validated active/inactive toggle sourced from `User::staffEditableStatusOptions()`, archived is not a direct form option, and restore/direct role choices reuse `User::staffRoleOptions()` instead of duplicated ad hoc role lists.
  - 2026-06-06 Student Hub access/FAQ boundary hardening completed: `/student/*` routes now require `auth` plus `student.active`, `EnsureActiveStudentHubUser` allows only authenticated active users with the `student` role and is persisted for Livewire requests, and the Student Hub Help route displays only published `FaqEntry` records grouped by model-owned categories.
  - 2026-06-06 Public FAQ consumption completed: `/faq` is implemented as a guest-accessible read-only Livewire page backed only by published `FaqEntry` records and model-owned categories. FAQ mutation remains System Super Admin only through `FaqEntryResource`.
  - 2026-06-05 FAQ scope reconciliation completed: System Super Admin FAQ authoring is implemented through `FaqEntryResource` with model-owned category options, publish toggle, numeric order, and system-derived author/updater fields.
  - 2026-06-04 Faculty Class List surface debloat completed: `EnrollmentSubjectResource` is list/view/action-only; stale create/edit/form scaffold files are removed; faculty grade entry is handled through program-specific modal fields (`q1`/`q2` for SHS, `prelim`/`midterm`/`final` for College) backed by grade services; direct raw enrollment-subject mutation remains outside TAL-12A.
  - 2026-06-06 Promissory Note surface hardening completed: `PromissoryNoteResource` keeps Accounting create/review for approved promise cases but removes the generic edit page, edit actions, and raw status selector; status/approval metadata are system-derived on create. The create form now uses dependent student/term-scoped enrollment and ledger selects, backend validation rejects cross-student, cross-term, or cross-enrollment IDs, and list/detail views reuse descriptive student, enrollment, ledger, and approver labels so raw unscoped IDs are not part of the Accounting workflow. Student Hub upload intake, pending/reject/expire/settle lifecycle actions, replacement rules, and one-per-academic-year enforcement remain outside completed TAL-12A until a dedicated workflow contract is added.
  - 2026-06-06 Enrollment hard-copy receipt lifecycle hardening completed: `EnrollmentResource` remains list/view/action-only; stale create/edit/form scaffold files are removed; direct raw `status`, `lis_status`, section, hard-copy flag, and lifecycle timestamp mutation is blocked. The Registrar hard-copy receipt action now delegates to `EnrollmentHardCopyReceiptService`, which validates the `markHardCopyReceived` policy ability, locks the enrollment and linked student profile, prevents duplicate confirmation, writes `hard_copy_received` and `last_status_changed_at`, and records lifecycle activity. Approved-applicant enrollment creation, LIS lifecycle actions, ineligibility handling, term-close completion, and manual repair flows remain outside completed TAL-12A unless implemented through dedicated services/actions.
  - 2026-06-05 Installment Policy milestone surface hardening completed: `InstallmentPolicyResource` owns milestone schedule configuration through a typed `milestones` relationship repeater; `InstallmentPolicyMilestoneResource` is list/view only with no create/edit pages, header actions, edit table action, or raw status form. Per-student installment state and penalties remain service-calculated from ledger/payment evidence.
  - 2026-06-05 Accounting configuration scope hardening completed: `FeeTemplate` and `InstallmentPolicy` forms use canonical year/grade selects matching enrollment values, normalize blank program/year scope to all-scope `null`, and reject a second active config for the same education/program/year scope. Inactive historical configs may share a scope.
  - 2026-06-05 System Settings generic edit surface removed: `system_settings` remains an internal runtime registry; `SystemSettingResource` has no navigation, no create/edit page route, no edit table action, and no raw JSON form. Future changes require dedicated typed settings pages or service-backed form handlers.
  - 2026-06-06 Document Request shipment evidence hardening completed: Registrar shipment recording now collects courier receipt proof through a private `FileUpload` stored under `document-request-receipts/` with Filament file-path tamper protection; `DocumentRequestLifecycleService` rejects arbitrary receipt paths outside that private directory, so no raw private path text entry remains in the shipment action and the detail view shows receipt-proof status instead of the private path.
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
  - 2026-06-01 closed through TAL-12 monitoring/failure-handling pass. Added scheduler hardening for `installments.process-overdues` at 00:10 and `document-requests.shipping-fee-enforcer` at 00:30 with explicit names and `withoutOverlapping()`. Added explicit retry/backoff metadata for installment and PayMongo webhook jobs. `php artisan schedule:list --no-interaction` shows both scheduled jobs, `php artisan queue:failed --no-interaction` reports no failed jobs, `/up` health route is present, Boost schema confirms `jobs`, `failed_jobs`, `webhook_calls`, `document_ocr_results`, and `payment_attempts`, and focused monitoring/integration tests pass. Production-only monitoring remains a go-live runbook item: Prometheus/Grafana, Uptime Kuma, Sentry/Telescope, Redis/Horizon, and server alert wiring are not required for this local backend/admin gate.
- [ ] Complete Pre-UAT Developer/Internal QA
  - 2026-06-01 gate added through `TALA-Pre-UAT-Developer-QA-Checklist-2026-06-01.md`. Developer/internal QA must be executed before staff/client UAT handoff. Failed rows must be logged, fixed/retested under TAL-12 when small/medium, moved to separate Linear issues when large missing-module gaps, or moved to TAL-13 when student-frontend-only.
  - 2026-06-01 checklist artifact updated so every executable `Action` cell uses ordered `Step 1`, `Step 2`, etc. Coverage decision added: the checklist covers all critical backend + Filament Admin Pre-UAT scenarios for TAL-12, while edge-case permutations remain covered by automated tests and issue-log retests.
  - 2026-06-02 local Windows spin-up guide added through `TALA-Local-Pre-UAT-Spin-Up-Guide.md`. Use separate terminals for `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`, `npm run dev`, and `Get-Content storage\logs\laravel.log -Wait -Tail 80` because native Windows cannot run Laravel Pail without `pcntl`.
  - 2026-06-02 Pre-UAT checklist refreshed after admin-role hardening: `DEV-AHD-004` now tests narrowed Academic Head read-only finance scope, `DEV-SSA-004` tests fixed FAQ categories, and `DEV-REG-006` tests fixed approved document request types before fulfillment.
  - 2026-06-02 account-name hardening added: `users` now has an applied local canonical name-part migration; Pre-UAT must rerun migration status and retest `DEV-SSA-001` staff user creation plus `DEV-REG-003` walk-in intake using First/Middle/Last/Suffix fields and composed display name.
  - 2026-06-03 student-scope reconciliation added: Pre-UAT may proceed without Student Portal UI, but shared student-domain backend/admin logic remains in scope. Student Portal routes/pages and student self-service contracts are not readiness evidence for TAL-12 and remain TAL-13 work.
  - 2026-06-03 System Settings debloat added: `system_settings` remains an internal runtime registry for backend services such as calendar cutover, but the generic Filament settings navigation/direct resource access is hidden/blocked for Pre-UAT. Admission Requirements typed editing is deferred until the public/student admission workflow.
  - 2026-06-03 role/resource reconciliation added: Pre-UAT must verify only System Super Admin can access read-only Roles/Audit, Roles expose no create/edit role route or permission edit form, Import Batch is audit-only until dedicated upload/preview pages exist, grade-correction grade-change resolution records prior Academic Head approval and derives corrected grades from scheme-specific period inputs, and larger unimplemented workflow surfaces are either accepted risk or moved to separate Linear issues before staff/client UAT.
  - 2026-06-03 admin surface debloat added: Official Schedule manual assignment uses typed fields plus conflict validation and blocks direct post-commit editing; schedule changes now use a term-scoped official-meeting select plus typed requested-meeting fields while old/new payloads remain internal snapshots; Document Review has list/view lifecycle actions only; Import Batch Audit has no raw file-path/error-log form; COR Controls, Schedule Drafts, and Service Requests are service-owned list/view lifecycle-action surfaces; Payment Queue and Confirmed Payments are service-owned list/view surfaces with no generic create/edit forms or raw meta/payload editing.
  - 2026-06-06 Document Request admin surface debloat added: `DocumentRequestResource` is list/view plus role-scoped lifecycle actions only. Pre-UAT must verify there is no generic Create/Edit route, header action, delete action, or raw request form for student/term/status/delivery/free-request fields; shipment receipt evidence is uploaded through the private receipt field with file-path tamper protection rather than typed as a raw path, and the detail view shows receipt-proof status instead of the raw private path; requests move through Accounting/Registrar lifecycle actions backed by `DocumentRequestLifecycleService`.
  - 2026-06-06 raw-input role audit closeout added: FS Appendix F and TS §8.8 now define the final role-by-role audit boundary, priority order, and follow-up contracts. Completed TAL-12A evidence remains implemented hardening; remaining items are no longer open-ended audit work and must be handled as follow-ups: P1 Pre-UAT QA execution, P1 TAL-13 Student Hub modules, P1 typed System Settings pages only where required, P1 Accounting adjustment service/action if needed, P1 Promissory student lifecycle clarification, P2 Service Request detail-label cleanup, and P2 Academic Head in-system approval decision. Faculty availability workflow was completed later through rescue Slice 11.
  - 2026-06-03 Linear `TAL-15` created to track the larger unimplemented admin surfaces if stakeholders require them before expanded UAT.
- [x] Prepare UAT checklist + sign-off evidence artifact
  - 2026-06-01 prepared through `TALA-UAT-Checklist-Signoff-2026-06-01.md`. The UAT package uses the requested test-case scenario table with Pass/Fail, Actual Input, ISO 25010 Product Quality Component, comments/suggestions, issue-log, and sign-off sections while preserving FS/TS traceability. This is a prepared staff/client UAT artifact only. It must be refreshed after Pre-UAT Developer/Internal QA if QA changes actual behavior. Actual staff signatures remain pending UAT execution.
- [x] Prepare go-live cutover runbook artifact
  - 2026-06-01 prepared through `TALA-Go-Live-Cutover-Runbook-2026-06-01.md`. The runbook defines required operational values, go/no-go criteria, pre-go-live gates, T-7/T-1/T-0 cutover steps, Laravel maintenance/deploy/cache/queue/scheduler commands, smoke checks, integration launch controls, rollback/forward-fix policy, communication plan, and final sign-off matrix. This is a launch-planning artifact only. Actual production launch approval remains pending Pre-UAT Developer/Internal QA, staff UAT signatures, target host/domain, backup location, live PayMongo/GCV decisions, and named operational owners.

**DoD**
- [ ] Pre-UAT Developer/Internal QA passes critical backend/admin flows
- [ ] UAT passes critical flows
- [x] Release readiness artifacts documented

---

## Iteration 9 - Student Portal UI & Self-Service Contracts (`TAL-13`)

**Scope Boundary**
- This iteration covers student-initiated workflows and Student Hub presentation only.
- It must consume the shared backend/admin contracts already built for TAL-12/TAL-12A instead of redefining enrollment, finance, document, grade, or gate policy.
- Existing `/student/*` routes are now protected by authenticated active-student middleware and the Student Hub Help page is data-backed by published FAQ entries. Dashboard, schedule, grades, financials, and documents pages remain placeholder/self-service surfaces and are not UAT-ready evidence until they are data-backed and tested under this iteration.

### Part A: Missing Backend Contracts (Student Self-Service)
- [ ] Implement `ApplicantIntakeService` (Public registration to pending applicant)
- [ ] Implement `StudentEnrollmentService` (One-Click Enroll auto-promotion for Regulars)
- [ ] Implement `SubjectSuggestionService` (Prerequisite enforcement & back-subject suggestion for Irregulars)
- [ ] Implement `StudentDashboardService` (Aggregated view of schedule, balance, grades, requests)

### Part B: Frontend UI
- [ ] Build student portal pages (Livewire/TallStack) against the backend contracts
- [ ] Implement student-authenticated enrollment/ledger/document views
- [ ] Implement student request submission and timeline views
- [ ] Add student-side policy messaging (non-clearing promissory, gate locks, payment deadlines)
- [ ] Add frontend integration tests for end-to-end student journeys
  - 2026-06-06 partial access/FAQ coverage added: `StudentHubAccessTest` verifies guest redirect, inactive-student denial, active-applicant denial, active-student access to all `/student/*` pages, and published-only Student Hub FAQ rendering. `PublicFaqPageTest` verifies guest access to `/faq`, published-only public FAQ rendering, and no authenticated student middleware on the public route. This does not close TAL-13 end-to-end student journeys because the core dashboard/enrollment/ledger/document/grade/request pages remain placeholder or missing service-backed workflows.

**DoD**
- [ ] Student self-service backend logic is test-verified
- [ ] Student UI uses stable backend contracts without policy drift
- [ ] Student journeys are test-verified end-to-end

---

## Execution Rule

- Complete iterations in order unless explicitly re-prioritized.
- Always execute the **Spec-First Gate** before any implementation task.
- Do not mark F1/F10 done until behavior is code-enforced and PHPUnit-covered.

