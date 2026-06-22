# TALA Active Execution Plan

## Status

Active reset: `SDD-00F Feature Approval and Survival Rebaseline`.

Feature classification is complete for all 8/8 approved batches. The active gate is now benchmark-backed dependency mapping, code-reality cleanup, and survival micro-sprint selection.

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

Current:

1. Benchmark approved features only.
2. Rebuild a tiny sprint backlog from approved P0 dependencies.
3. Update Linear with the reset, retired local execution layer, and new sprint issues.

## Approved Feature Batch 1

- KEEP: auth/RBAC/login/logout/session security; staff roles; applicant intake/admissions; private admission document upload/manual review; student master record; enrollment handover; College academic foundation; SOA/payment acknowledgement/internal payment evidence.
- REMOVE: active non-College offering paths; official document-request portal/catalog/fulfillment; official tax receipt/e-receipt/CAS behavior.
- EXTERNAL: outside-office portal/submission/status work. TALA only owns enrolled-student roster visibility/export and audited internal lifecycle state.

## Approved Feature Batch 2

- KEEP: CP-SAT-assisted scheduling; faculty availability input; curriculum-derived subject demand; Registrar-owned subject/faculty assignment; manual schedule assignment; draft review before commit; Academic Head publish approval; delivery groups/patterns where needed; room conflict checking when room-required delivery exists.
- REVIEW: simplest viable sectioning approach; post-publish schedule-change workflow; summer/remedial scheduling; faculty advising status.
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
| Scheduling | UniTime course timetabling and instructor/student scheduling; Google OR-Tools CP-SAT | Scheduling requires prepared input data, hard conflicts, solver statuses, draft review, and publish/commit separation. Faculty availability is input only; Registrar owns subject/faculty assignment. |
| Student self-service | Frappe Student Portal; openSIS mobile self-service | Student Hub should expose timetable/schedule, grades, fee/payment status, and profile data as read-only owned views before adding mutations. |
| Auth, queues, and operations | Laravel Fortify/authentication/rate limiting and Laravel queues | Use framework-backed throttling/session/auth behavior, database queue workers, retries/backoff, failed-job visibility, and small focused tests. |

## Code Reality Snapshot

High-level inspection shows substantial foundation code exists: academic foundation models/resources, applicant intake, document upload review, enrollment services, payment attempts/webhook processing, ledger/payment resources, scheduling services, faculty availability, schedule drafts/commit/publish, grades/grade corrections, Student Hub routes, PWA package, imports, FAQ, and tests.

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

## Survival Micro-Sprint Backlog

Use this order until replaced by a newer user-approved execution controller:

1. `S0 Scope Cleanup`: remove OCR, passkey, Horizon/Redis, document-request/service-request, and stale removed-scope UI/test remnants that would mislead implementation or UAT.
2. `S1 Identity and RBAC`: prove Fortify login/logout/password reset/email verification/session expiry/throttling, fixed seeded roles, one-role assignment, active-account gate, and direct URL denial.
3. `S2 Academic Foundation`: prove terms, programs, curricula, subjects, sections/delivery groups, rooms, delivery patterns, readiness flags, and controlled curriculum/foundation import.
4. `S3 Admissions to Handover`: prove College applicant intake, requirement-policy resolution, private uploads/manual Registrar review, readiness dashboard, capacity reservation, and applicant-to-student handover without Student Hub access before approval.
5. `S4 Enrollment, COR, and Capacity`: prove canonical enrollment state, section placement, capacity locking, subject/prerequisite suggestion, finance-clearance gate, COR generation, and COR QR verify/revoke/supersede.
6. `S5 Finance and PayMongo`: prove fee templates/assessment, manual payment confirmation, PayMongo checkout/webhook signature/idempotency, immutable ledger posting, balance/overpayment, SOA/payment evidence, and Accounting adjustments.
7. `S6 Scheduling and CP-SAT`: prove faculty availability, Registrar subject/faculty assignment from curriculum demand, schedule snapshot generation, solver dispatch/result ingest, draft conflict review, commit, and Academic Head publish.
8. `S7 Grades`: prove faculty class lists, College grading profile, grade encoding/submission, Registrar verification/finalization/return, INC/prerequisite effects, Academic Head-approved finalized grade correction, and immutable grade history.
9. `S8 Student Hub/PWA`: prove read-only dashboard, COR, published schedule, finalized grades, financial status/payment entry, notifications, FAQ/help, private access, loading/empty/error states, and read-only offline cache boundaries.
10. `S9 Roster, Export, Ops, and UAT`: prove enrolled-student roster CSV/XLSX, controlled import evidence, queues/failed jobs/health/log visibility, backup/restore expectation, master test case rebuild, and manual UAT pass/fail marking.

## Sprint Acceptance Rule

Every sprint must finish with code/tests, a concise local tracker update, a Linear update, and a user manual-test note when a UI is affected. A sprint is not accepted because a file exists; it is accepted only when the approved behavior is testable and removed-scope behavior is absent.
