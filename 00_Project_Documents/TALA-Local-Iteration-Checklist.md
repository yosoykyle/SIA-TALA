# TALA Local Iteration Checklist (DB-First)

**Location Purpose:** Local execution checklist aligned with the 3 main specs and Linear roadmap.
**Last Updated:** 2026-06-03
**Linear Project:** TALA Iterative Implementation Map (DB-First)

---

## Scope Lock (Approved)

- Current active scope is **Backend + Filament Admin UI only**.
- Shared student-domain backend logic required by admin workflows is **not deferred**. This includes student profiles, enrollments, assessments, payment clearance, ledgers, promissory holds, document requests, OCR/manual-review state, grades, class-list visibility, and calendar gates.
- Student Portal UI and student self-service contracts are **deferred** to a separate iteration after backend/admin stabilization.
- No Student Portal frontend or student self-service contract work should be marked complete under backend/admin iterations before `TAL-13`.

---

## Spec-First Gate (Mandatory)

- Before starting any iteration task, read `TALA-Functional-Specification.md` and `TALA-Technical-Specification.md` first.
- If Functional and Technical specs conflict, pause implementation and resolve the conflict in docs before coding.
- Do not mark any checklist item done if the implemented behavior is not traceable to FS/TS sections.
- Backend/service completion alone does not make a staff module complete. A staff-facing module is admin-ready only when the required Filament Resource/Page/Action exists, role access is enforced, and the panel action calls the tested backend service.

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
- [x] Registrar admin screens/actions wired for enrollment review, document review, scheduling/import-audit controls, COR controls, and service-request fulfillment
- [x] Accounting admin screens/actions wired for assessments, ledger review, OTC/manual payment confirmation, payment queues, promissory records, and installment visibility
- [x] Faculty admin screens/actions wired for class lists, finance-status-only visibility, grade encoding/submission/finalization, correction requests, advising status, and quick links
- [x] Faculty/Grades Filament flow works end-to-end: class list -> grade encoding -> submission/finalization -> correction review/resolution/override
- [x] Academic Head admin screens/actions wired for read-only oversight and approved override actions only
- [x] System Super Admin screens/actions wired for users/roles/audit/FAQ while keeping academic and financial domains read-only where required; generic `system_settings` UI is hidden/internal for TAL-12
- [x] Add Filament/Livewire tests or browser smoke tests for role-specific navigation and critical actions
- [x] Verify seeded staff accounts can perform only their allowed Admin Nexus workflows

**DoD**
- [x] Each staff role can complete its current TAL-12A implemented admin workflows inside Filament
  - 2026-06-02 admin-side clarification hardening pass completed: Academic Head finance access narrowed to read-only finance status, fee template/downpayment rules, installment policy summary, and promissory status/tag; FAQ categories fixed; document request type selections fixed. No migration-log update required because this changed UI/policy/spec contracts only, not table structure.
  - 2026-06-03 role/resource reconciliation completed: vendor-backed `Role` and `Activity` policies are explicitly registered; only System Super Admin sees Roles/Audit/Users/FAQ; Import Batches are scoped as `Import Batch Audit` with no generic create/edit routes; grade-correction grade changes record already-approved Academic Head authorization; full import upload/preview pages, faculty availability self-service, COR template editor, document-catalog admin, rich dashboard metrics, and any separate System Health admin page are not claimed as completed TAL-12A evidence unless implemented through separate items.
  - 2026-06-03 follow-up tracking created in Linear `TAL-15` for larger admin surfaces not included in current TAL-12 Pre-UAT scope.
- [x] Grade flow works end-to-end in Filament for the allowed staff roles
- [x] Panel actions call tested backend services without bypassing policies
- [x] Student Portal UI remains untouched and deferred to `TAL-13`; shared student-domain backend logic required by admin workflows remains in backend/admin scope

---

## Iteration 8 - Hardening, UAT, and Go-Live Readiness (`TAL-12`)

- [x] Verify `TAL-12A` Filament admin completion evidence before UAT
- [x] Consolidate regression suite
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
  - 2026-06-03 role/resource reconciliation added: Pre-UAT must verify only System Super Admin can access Roles/Audit, Import Batch is audit-only until dedicated upload/preview pages exist, grade-correction grade-change resolution records prior Academic Head approval, and larger unimplemented workflow surfaces are either accepted risk or moved to separate Linear issues before staff/client UAT.
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
- Existing `/student/*` placeholder routes/pages are not UAT-ready evidence until they are protected, data-backed, and tested under this iteration.

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

**DoD**
- [ ] Student self-service backend logic is test-verified
- [ ] Student UI uses stable backend contracts without policy drift
- [ ] Student journeys are test-verified end-to-end

---

## Execution Rule

- Complete iterations in order unless explicitly re-prioritized.
- Always execute the **Spec-First Gate** before any implementation task.
- Do not mark F1/F10 done until behavior is code-enforced and PHPUnit-covered.



