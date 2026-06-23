# TALA Active Execution Plan

## Status

Active reset: `SDD-00F Feature Approval and Survival Rebaseline`.

Feature classification is complete for all 8/8 approved batches. S1-S7 implementation is complete at the approved backend/Admin baseline. Next implementation sprint is S8 Student Hub/PWA unless the user redirects.

This file is the only local execution controller. Deleted SDD maps, local checklists, rescue plans, benchmark matrices, capability trackers, and migration-control logs are historical and must not be treated as active instructions. Linear and git history retain the previous execution record.

## SDLC

1. Monolithic baseline: use FS/TS plus active business evidence as the requirement source.
2. Feature approval: classify each FS/TS feature as `KEEP`, `REMOVE`, `EXTERNAL`, or `REVIEW`.
3. Benchmark: for `KEEP` and `REVIEW`, compare against mature SIS/domain systems or official package docs before implementation.
4. Micro-sprint: implement one narrow feature slice at a time with tests and minimal UI needed for verification.
5. Human gate: user reviews scope, UI, and manual UAT before the next sprint is treated as accepted.

## Active Sources

- `business-evidence/INSTITUTION WORK  FLOW CURRENT.md`
- `TALA-Functional-Specification.md`
- `TALA-Technical-Specification.md`
- `TALA-Master-System-Test-Cases.md` after feature-audit rebuild only
- Linear issue created for this reset and its child issues

## Current Scope

- College-only SIS.
- SHS is removed from active product scope.
- External portals and manual outside-office work are not TALA features.
- Capstone integration priority: CP-SAT scheduling, PayMongo/payment flow, and read-only Student Hub/PWA.

## Immediate Work

Completed:

1. Extracted FS/TS feature inventory by lifecycle module and role.
2. Classified features in 8 small user-approved batches.
3. Removed or externalized rejected features from active FS/TS language.
4. Completed survival micro-sprints `S1` through `S4` with focused regression evidence and Linear/git checkpoints.
5. Completed `S5 Finance and PayMongo`: College-scoped fee assessment; manual and PayMongo payment posting; signature/idempotency guards; immutable ledger, balance, overpayment, finance clearance, Accounting adjustments, and authorized internal SOA/payment acknowledgement evidence.
6. Removed unapproved automatic freshmen-discount execution and active discount/promissory permissions; these remain `REVIEW`, not active finance behavior.
7. Applied the College-only schema correction, including removal of redundant `education_level` finance and foundation discriminators.

Current:

1. Continue to `S8 Student Hub/PWA` after user acceptance of S7 evidence.

## Approved Feature Batch 1

- KEEP: auth/RBAC/login/logout/session security; staff roles; applicant intake/admissions; private admission document upload/manual review; student master record; enrollment handover; College academic foundation; SOA/payment acknowledgement/internal payment evidence.
- REMOVE: active non-College offering paths; official document-request portal/catalog/fulfillment; official tax receipt/e-receipt/CAS behavior.
- EXTERNAL: outside-office portal/submission/status work. TALA only owns enrolled-student roster visibility/export and audited internal lifecycle state.

## Approved Feature Batch 2

- KEEP: CP-SAT-assisted scheduling; faculty availability input; curriculum-derived subject demand; Registrar-owned subject/faculty assignment; manual schedule assignment; draft review; Registrar-owned publication; delivery groups/patterns where needed; room conflict checking when room-required delivery exists.
- REVIEW: simplest viable sectioning approach; superseding schedule-version correction path; summer/remedial scheduling; faculty advising status.
- REMOVE: online meeting-link/LMS handling; automatic section creation/balancing as an active implementation promise.
- Clarification: faculty provide availability only. They do not choose teaching subjects or resolve scheduling conflicts. Registrar/setup staff select subjects/faculty from curriculum-derived demand and approved staff records.

## Approved Feature Batch 3

- KEEP: fee templates/assessment; minimum downpayment clearance; PayMongo checkout/webhook confirmation; manual payment confirmation for Cash, GCash Manual, and Bank Transfer; immutable student ledger; balance computation and overpayment credit; internal SOA/payment acknowledgement evidence; Accounting debit/credit/reversal adjustments; finance clearance securing capacity; applicant-to-student handover; COR generation; COR QR verification; SOA/payment evidence issuance.
- REVIEW: freshmen tuition discount; irregular/unit-based assessment; promissory promise tracking; exam-access accommodation workflow; installment policies/penalty automation; refund, withdrawal-fee, and financial-disposition automation.
- REMOVE: official BIR receipt/tax invoice generation; promissory note as payment clearance or exam access; generic ledger/payment CRUD; full COR template editor; formal TOR/Form 137/report-card PDF/diploma/certificate credential issuance or fulfillment. This does not remove student grade history, finalized grade viewing, or internal academic records.
- EXTERNAL: outside-office official receipts, tax documents, school-to-school credential release, and document-request fulfillment.

## Approved Feature Batch 4

- KEEP: College grading profiles; assigned-faculty class lists; grade encoding; INC marking and prerequisite blocking; Registrar verification/return/finalization; Academic Head approval for post-finalization grade changes; immutable finalized grade history; Student Hub grade viewing; prerequisite and subject-suggestion use of finalized grade history; internal academic/advising visibility.
- REVIEW: grade upload templates; INC auto-fail policy timing; student-initiated grade-correction request UI, SLA, and escalation; early-advising views that consume unfinalized current-term grades; legacy grade import.
- REMOVE: formal report-card PDF, transcript/TOR, Form 137, diploma, certificate, and full credential generation/release/fulfillment from grade records.
- Boundary clarification: removing formal credential issuance does not remove grade records, grade finalization, academic history, prerequisite use, or student grade viewing.

## Approved Feature Batch 5

- KEEP: read-only Student Hub dashboard; owned profile/enrollment summary; COR view/download; published schedule view; finalized grades view; balance/payment status; notifications; FAQ/help; PWA installability and read-only cache for approved data; PayMongo payment entry only through the approved finance service path.
- REVIEW: student proof upload for manual-payment evidence; student-initiated grade-correction request UI, SLA, and escalation; offline cache families, freshness labels, and clear-on-logout acceptance.
- REMOVE: document-request portal/catalog/fulfillment; generic Student Hub service requests; credential request pages; TOR/Form 137/diploma/certificate request flows; courier/fulfillment tracking; Student Hub Documents tab as an active feature.
- EXTERNAL: official document release, outside-office credential handling, school-to-school records transfer, and any registrar-office fulfillment that is not represented as system-owned admission evidence or generated COR/SOA/payment evidence.
- Boundary clarification: Student Hub is visibility-first. Removing document/service requests does not remove applicant admission evidence upload, Registrar document review, payment evidence, COR access, finalized grade viewing, or published schedule viewing.

## Approved Feature Batch 6

- KEEP: separate account, student-profile, and term-enrollment states; Registrar-owned typed drop-subject, withdrawal, section-transfer, program-shift, LOA, readmission, transfer-out, completion/graduation, archive/reactivation, hold, and deficiency workflows; immutable status history; internal graduation eligibility and approved-graduate roster.
- REVIEW: student-level modality changes that affect schedules or fees; withdrawal-fee/refund/financial-disposition automation; any program-shift fee automation.
- REMOVE: generic service-request records/permissions/routes; Student Hub status-request forms; student-facing graduation application; automatic inactivity/archive from attendance or no-show; fixed grace-period archiving; term-close reset of student profile status to `Not Enrolled`; direct raw lifecycle-status editing.
- EXTERNAL: paper form collection/signatures, guidance consultation, official TOR/Honorable Dismissal/diploma/credential release, CHED Special Order submission, and school-to-school records transfer.
- Boundary clarification: TALA records the authorized internal decision, effective date, reason, evidence reference, access effect, and history. Subject drops affect enrollment-subject records; full withdrawal affects the term enrollment; term close completes the term enrollment without resetting the student profile or authentication account.

## Approved Feature Batch 7

- KEEP: System Super Admin staff-account create/archive/restore; assignment of exactly one seeded approved staff role; read-only RBAC matrix; critical audit logs under policy-driven retention; COR verification/revoke/supersede controls; typed term/curriculum/fee/admission settings; minimal role-specific dashboards and actionable queues; in-app lifecycle notifications; critical account/admission/payment/schedule/grade email notifications; public/Student Hub FAQ with System Super Admin CRUD; audited enrolled-student roster CSV/XLSX export; shared controlled-import infrastructure; dedicated curriculum/foundation and legacy-student importers.
- REVIEW: grade-submission progress/reminder widget; email bounce/retry and editable-template behavior; legacy grade, finance, and enrollment-history importers based on actual client source data.
- REMOVE: runtime role/permission creation or editing; indefinite audit retention; generic raw settings UI; broad enrollment/revenue/collection/pass-rate analytics; generic ticketing; universal any-entity importer UI; self-service account-claim portal; dedicated walk-in impersonation/session mode; custom database-driven maintenance service/settings UI; all automated text-extraction/document-reading integrations.
- EXTERNAL: regulator-specific templates/submissions/completion tracking and Laravel CLI infrastructure maintenance.
- Global scope correction: automated document text extraction is removed from TALA, superseding its earlier Batch 1 approval. Admissions and legacy onboarding use private uploads plus authorized manual review only.
- Mandatory code cleanup after feature audit: remove the document-reading SDK dependency, service clients, config/env keys, credentials, jobs/commands, provider-specific tables/columns, review UI fields, factories/seeders/tests, test cases, and stale Linear/backlog references in one tested implementation slice. Do not remove only the dependency while dependent code remains.
- Import boundary: shared parsing, private source storage, batch tracking, preview/validation, transactional commit, and audit may be reused, but every approved import domain owns a dedicated template, authorization rule, validator, and service. No arbitrary entity/column mapping is exposed.

## Approved Feature Batch 8

- KEEP: Fortify-backed login/logout/password reset/email verification/session expiry and throttling; fixed seeded RBAC with policies/direct-URL denial/active-account checks; HTTPS, secure cookies, CSRF, private upload validation, secret protection, and security headers; PayMongo signature verification/idempotency; critical lifecycle audits without raw-sensitive-value logging; database queue workers with retry/backoff, failed-job storage, and an external process monitor; approved scheduled work only; health endpoint, application logs, failed jobs, integration status, and operational alerts; provider-neutral backup/restore and CI/CD guardrails in the TS; focused PHPUnit/integration/browser smoke/dependency-audit verification.
- REVIEW: staff-only two-factor authentication; INC expiry/auto-fail timing; any additional scheduled job not already approved by the feature audit.
- REMOVE: passkeys/WebAuthn; Redis/Horizon requirements and unused dependency/configuration; blanket model-mutation logging; automatic payment housekeeping; installment/promissory schedules until their features are promoted; hardcoded monitoring vendors; fixed VPS/provider/server sizing; S3 pricing and variable-cost projections; global automatic active-term Eloquent scope; observer-only enrolled-count mutation; mandatory Apache Bench/k6/Dusk tooling.
- Technical boundary: term context is explicit so historical data remains queryable. Capacity counters, when retained, change only within the same locked transaction as the authoritative enrollment/placement transition.
- Operations boundary: backups, deployment pipelines, worker process management, cron, TLS termination, host monitoring, and infrastructure sizing are deployment responsibilities, not staff-facing SIS modules. The TS states required outcomes without inventing an unapproved provider or exact retention/size value.
- Mandatory code cleanup after sprint rebuild: remove passkey migrations/config, Horizon dependency/stale claims, obsolete scheduled jobs, automated document-reading artifacts, stale environment keys, global-term-scope examples, observer-only capacity mutations, obsolete tests, and unsupported monitoring/deployment assumptions through focused tested slices.

## Sprint Selection Rule

Next implementation work must come from the approved feature inventory, not from old SDD numbering. Highest priority goes to SIS lifecycle dependencies and capstone integrations that can be tested within the remaining time.

## Benchmark Use Rule

`business-evidence/INSTITUTION WORK  FLOW CURRENT.md` remains required local evidence, but it is not an automatic feature source. It supplies local constraints, role ownership, terminology, manual-office boundaries, capacity rules, clearance rules, and paper-process evidence. TALA implements only features that survived the 8/8 feature audit as `KEEP` or unresolved `REVIEW`.

Do not benchmark removed or externalized scope back into the product. Manual document requests, courier handling, official TOR/Form 137/diploma release, official BIR receipts, SHS workflows, regulator portal submission queues, OCR/text extraction, runtime role editing, passkeys, Horizon/Redis requirements, and generic service requests remain outside active implementation unless the feature audit is reopened.

## Benchmark Anchors

| Feature family | Benchmark anchor | Adopted lesson for TALA |
|---|---|---|
| Admissions to student master | Frappe Education Student Applicant and Program Enrollment; openSIS Admissions | Applicant record, approval/rejection, and student-master/enrollment creation are separate states. TALA keeps applicant staging separate until approved handover. |
| Academic foundation | Frappe Academic Year/Term, Program, Course, Student Group, Program Enrollment | Terms, programs, curricula, subjects/courses, groups/sections, and fee structures must exist before enrollment and scheduling. |
| Finance and payments | Frappe Fees; openSIS Billing & Fees; PayMongo webhook docs | Fee assessment and payment evidence should be structured, idempotent, and visible to authorized students/staff without implying official tax receipt issuance. |
| Scheduling | UniTime course timetabling, instructional offerings, instructor scheduling, and student-scheduling prerequisites; Frappe Course Schedule/Program Enrollment; Google OR-Tools CP-SAT | Prepare term, curriculum demand, sections, rooms, faculty eligibility, availability, and workload before solving. Planned sections and availability may proceed in parallel after academic setup; Registrar then confirms faculty assignments. Feasibility precedes optimization. Registrar reviews and publishes through one user-facing action; publication transactionally creates official rows and makes them visible. |
| Student self-service | Frappe Student Portal; openSIS mobile self-service | Student Hub should expose timetable/schedule, grades, fee/payment status, and profile data as read-only owned views before adding mutations. |
| Auth, queues, and operations | Laravel Fortify/authentication/rate limiting and Laravel queues | Use framework-backed throttling/session/auth behavior, database queue workers, retries/backoff, failed-job visibility, and small focused tests. |

## Code Reality Snapshot

High-level inspection shows substantial foundation code exists: academic foundation models/resources, applicant intake, document upload review, enrollment services, payment attempts/webhook processing, ledger/payment resources, scheduling services, faculty availability, schedule drafts/publish, grades/grade corrections, Student Hub routes, PWA package, imports, FAQ, and tests.

S0 cleanup result:

- Removed OCR/text extraction from active dependencies, config, service bindings, commands, jobs, schema, seeders, Filament evidence, and tests.
- Removed app-level passkey/WebAuthn migration and Fortify configuration. `laravel/passkeys` remains only as a Fortify transitive package and must not be enabled/configured unless the feature audit is reopened.
- Removed the Horizon dependency and app-level Horizon assumptions. Active operations use database queues, retries/backoff, failed-job visibility, and external process monitoring.
- Removed document-request and generic service-request runtime artifacts from active schema, models, policies, resources, permissions, seeders, Student Hub summaries, and tests.
- Removed automatic installment/promissory scheduled jobs until those maintenance automations are explicitly promoted.
- Remaining review-sensitive surfaces, including promissory notes, installment policies, exam access accommodations, shifting requests, and Student Hub wording, must be handled through their own promoted sprint decisions.
- External-reporting wording such as LIS/CHED remains roster/export evidence only, not active regulator submission workflow.

S1 identity/RBAC result:

- Fortify now boots through an explicit provider with login, password reset, reset password, and email verification notice views.
- Public Fortify login is role-aware: verified active students land in Student Hub; verified active staff land in the Admin Panel.
- Inactive accounts cannot authenticate through the public login.
- Student Hub routes require authenticated, verified, active student accounts.
- Admin Panel access requires active, verified staff accounts through `User::canAccessPanel()`.
- Registration, passkeys, and two-factor authentication remain out of active scope.
- Staff account UI keeps one role per account; role CRUD remains read-only/seeded rather than runtime-editable.
- S1 is covered by focused Fortify/RBAC tests plus adjacent Student Hub, internal route denial, system-admin, seeded account, and RBAC matrix tests.

S2 academic foundation result:

- College academic setup is present for academic years, terms, programs, subjects, curricula, rooms, sections, section delivery groups, delivery patterns, curriculum readiness scopes, and controlled curriculum imports.
- Academic setup resources are permission-gated for Registrar, Academic Head, curriculum managers, term managers, schedule managers, and global viewers as applicable.
- Active academic setup forms do not expose SHS or `education_level` choices; Grade 12 remains only prior-education admissions evidence outside this sprint.
- Curriculum imports use a dedicated template, private upload path, preview validation, authorized commit, transactional writes, and audit evidence.
- Curriculum readiness scopes block scheduling until subject demand has required scheduling fields; subject edits reset ready scopes to review.
- S2 prepares data for S6 Scheduling and CP-SAT but does not implement solver dispatch, solve logic, or published schedule workflows.
- S2 is tracked in Linear as `TAL-36` and covered by the focused academic foundation, college-only scope, import, readiness, delivery-pattern, section-planning, and scheduling-readiness tests.

S3 admissions-to-handover result:

- College applicant intake is implemented through a staged applicant record with duplicate checks, applicant account status, term/program/year-level scope, and approved offering/policy resolution.
- Admission requirement policies materialize applicant document requirements by gate type, keeping admission-gate evidence separate from retention undertakings.
- Admission uploads use private stored evidence and manual Registrar review; OCR/text extraction remains removed from active scope.
- Admission setup resources and readiness dashboard are permission-gated and College-only; they do not expose SHS or `education_level` choices.
- Readiness evaluation checks published offerings, active requirement policies, enrollment/payment calendar fields, approved capacity plans, scheduling readiness, and published schedule evidence.
- Approved applicants can be handed over into student profile/enrollment records through the typed enrollment service; final student-role activation/COR readiness is gated by finance-cleared handover.
- Capacity reservation is linked to the admission finance-clearance gate and secured when finance clearance completes.
- S3 is tracked in Linear as `TAL-37` and covered by focused applicant intake, document review, admission setup, readiness dashboard, handover, college-only scope, and admission finance-clearance tests.

S4 enrollment, COR, and capacity result:

- Benchmark check: Frappe Program Enrollment separates term/program/course/fee enrollment state; openSIS exposes schedules, grades, fees, and student records from official student/enrollment data; registrar verification patterns treat enrollment proof as a public verification surface derived from official enrollment records.
- Existing enrollment services prove canonical term enrollment, finance-cleared handover, section placement, transactional capacity locking, and subject/prerequisite suggestion from curriculum and finalized grade history.
- COR verification now issues an opaque token only from a ready finance-cleared enrollment with active student account, assigned section, and assigned delivery group.
- COR token issuance is idempotent while a valid unexpired token already exists for the enrollment.
- Public COR verification route reports `valid`, `superseded`, `revoked`, `expired`, and `not_found` states without exposing internal database IDs.
- COR revoke/supersede controls now use the dedicated `manage-cor-verifications` permission instead of the external-reporting `manage-lis` permission.
- S4 is tracked in Linear as `TAL-38` and covered by focused COR lifecycle, enrollment, sectioning, subject suggestion, payment-clearance, PayMongo finance-clearance, Registrar resource, and RBAC matrix tests.

## S6 Scheduling Benchmark Decision

- Dependency spine: term and ready curriculum -> projected-demand sections/delivery groups plus faculty availability and room inventory in parallel -> Registrar faculty assignment -> readiness snapshot -> CP-SAT solve -> Laravel validation -> Registrar review/publish -> payment clearance/final placement -> COR.
- Solver scope: assign day, time, and room only. Section creation, student placement, subject selection, and faculty selection remain outside CP-SAT.
- Hard inputs: approved subject/faculty eligibility, submitted availability windows, configured faculty maximum weekly hours, section/faculty/room conflicts, capacities, room requirements, calendar grid, and existing official commitments.
- Result gate: infeasible, unknown, timed-out, malformed, or hard-conflicted results cannot publish. Diagnostics remain review evidence.
- Publication: user-visible lifecycle is `Generated Draft -> Registrar Review -> Registrar Publish`. One Publish action creates official `section_meetings`, synchronizes `section_teacher`, and marks the run published in one transaction. Academic Head and System Super Admin do not publish schedules.
- Published schedule: immutable. Corrections require a new superseding draft/version and re-publication; no direct edit or automatic post-publication re-solve.
- Cross-sprint retention: S3/S5 keep published schedule as a finance-clearance prerequisite. S4 keeps Registrar-assisted irregular subject placement and overlap checking against the published master schedule; individual irregular students are not solver variables.
- Runtime decision: Google OR-Tools CP-SAT on IAM-private Cloud Run remains the selected solver. Existing solver code is provisional until S6 constraint fixtures, status handling, ingest validation, and redeployment smoke evidence pass.

## S6 Implementation Checkpoint

- Completed: removed the user-facing and production `ScheduleCommitService` path. Registrar publication now creates official schedule rows, synchronizes faculty assignment, records activity, marks the run published, and supersedes an older published run for the same term.
- Completed: Academic Head and System Super Admin no longer receive normal or emergency publish actions. Official Schedules are read-only; direct official schedule creation is removed from the active Filament route/policy path.
- Completed: missing/outside faculty availability is a hard scheduling block. Review notes do not override availability. Active schedule consumers filter out meetings attached to superseded runs.
- Completed: configured faculty workload is enforced inside the CP-SAT solver and again during Laravel solver-result ingestion; non-feasible, timed-out, hard-violating, malformed, or empty solver results block publication and retain diagnostics.
- Completed: removed inactive schedule-change UI/runtime surfaces. Published corrections use superseding schedule runs, not direct schedule-change records.
- Verified: focused S6 scheduling/service, solver dispatch, snapshot, Cloud Run client, Filament resource, registrar-resource, and Python CP-SAT solver tests passed after implementation.
- Completed: Cloud Build `a9323fcd-a371-448d-8e47-92c7e52b6a21` deployed image `asia-southeast1-docker.pkg.dev/tala-dev-ocr-3s/tala-containers/tala-scheduler-solver:rescued-poc` to Cloud Run revision `tala-scheduler-solver-00005-42w`, serving 100% traffic at `https://tala-scheduler-solver-783866300038.asia-southeast1.run.app`.
- Verified: Cloud Run `/health` returned `{"status":"ok","service":"tala-scheduler-solver"}` and `/solve` against `samples/minimal_snapshot.json` returned `solver_status=optimal`, `assigned_count=2`, `unassigned_count=0`, `hard_violation_count=0`, `timeout=false`, and two `ok` draft rows.

## S7 Grades Implementation Checkpoint

- Completed S7A backend package lifecycle: `grade_submission_packages` and `grade_submission_package_items` snapshot the term, section, subject, faculty, roster checksum, College grading-profile metadata, submitted grade rows, Registrar reviewer, return reason, and verified-finalized timestamp.
- Completed Faculty class-list action correction: Faculty no longer directly finalizes official grade rows from the class-list action. The active action submits a section/subject package for Registrar verification.
- Completed pending-review edit lock: submitted packages block Faculty grade edits until the Registrar returns the package. Returned packages remain editable and can be resubmitted.
- Completed Registrar backend transitions: Registrar can return a submitted package without finalizing grades, or verify/finalize the whole package atomically. Verification marks included grade rows official and sets `finalized_by` to the Registrar reviewer.
- Completed permission boundary: Registrar package verification uses the explicit `verify-grade-submissions` permission assigned to the seeded Registrar role.
- Verified: focused grade package, grade encoding, grade correction, Faculty/Academic Head Filament contract, Student Dashboard grade visibility, and prerequisite/subject-suggestion tests passed after implementation.
- Tracked in Linear as `TAL-40`.
- Completed S7B Registrar package queue: `GradeSubmissionPackageResource` is list/view only, uses policy-gated table visibility, exposes status/term filters and package status columns, and provides typed `Return` and `Verify & Finalize` actions backed by `GradeSubmissionPackageService`.
- Completed S7B read-only package detail: the infolist shows package metadata, roster checksum, reviewer/return/finalization fields, and submitted grade-row snapshots.
- Completed S7B CRUD guard: no create/edit/form routes, generic edit/delete actions, or bulk delete actions exist for grade-submission packages.
- Verified: S7B Filament resource tests plus S7 grade package, grade encoding, grade correction, Faculty/Academic Head Filament contract, Student Dashboard grade visibility, and prerequisite/subject-suggestion tests passed after implementation.
- S7 remaining review items not implemented: grade upload templates, INC auto-fail timing, student-initiated grade-correction request UI/SLA/escalation, early-advising views that consume unfinalized grades, and legacy grade import.

## Survival Micro-Sprint Backlog

Use this order until replaced by a newer user-approved execution controller:

1. `S0 Scope Cleanup`: remove OCR, passkey, Horizon/Redis, document-request/service-request, and stale removed-scope UI/test remnants that would mislead implementation or UAT.
2. `S1 Identity and RBAC`: prove Fortify login/logout/password reset/email verification/session expiry/throttling, fixed seeded roles, one-role assignment, active-account gate, and direct URL denial.
3. `S2 Academic Foundation`: prove terms, programs, curricula, subjects, sections/delivery groups, rooms, delivery patterns, readiness flags, and controlled curriculum/foundation import.
4. `S3 Admissions to Handover`: prove College applicant intake, requirement-policy resolution, private uploads/manual Registrar review, readiness dashboard, capacity reservation, and applicant-to-student handover without Student Hub access before approval.
5. `S4 Enrollment, COR, and Capacity`: prove canonical enrollment state, section placement, capacity locking, subject/prerequisite suggestion, finance-clearance gate, COR generation, and COR QR verify/revoke/supersede.
6. `S5 Finance and PayMongo`: prove fee templates/assessment, manual payment confirmation, PayMongo checkout/webhook signature/idempotency, immutable ledger posting, balance/overpayment, SOA/payment evidence, and Accounting adjustments.
7. `S6 Scheduling and CP-SAT`: prove projected-demand section readiness, faculty eligibility/availability/configured workload, Registrar subject/faculty assignment, immutable snapshot generation, CP-SAT feasibility/optimization, authenticated dispatch, validated result ingest, draft review, transactional Registrar publication, published immutability, and benchmark fixture coverage.
8. `S7 Grades`: prove faculty class lists, College grading profile, grade encoding/submission, Registrar verification/finalization/return, INC/prerequisite effects, Academic Head-approved finalized grade correction, and immutable grade history.
9. `S8 Student Hub/PWA`: prove read-only dashboard, COR, published schedule, finalized grades, financial status/payment entry, notifications, FAQ/help, private access, loading/empty/error states, and read-only offline cache boundaries.
10. `S9 Roster, Export, Ops, and UAT`: prove enrolled-student roster CSV/XLSX, controlled import evidence, queues/failed jobs/health/log visibility, backup/restore expectation, master test case rebuild, and manual UAT pass/fail marking.

## Sprint Acceptance Rule

Every sprint must finish with code/tests, a concise local tracker update, a Linear update, and a user manual-test note when a UI is affected. A sprint is not accepted because a file exists; it is accepted only when the approved behavior is testable and removed-scope behavior is absent.
