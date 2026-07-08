# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work. It is reached after `AGENTS.md` and `TALA-Orchestrator-Protocol.md` in the agent intake chain, and it controls issue sequencing rather than product behavior.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. Primary plans the current issue or sub-slice and waits for acceptance.
  2. If the plan includes a sub-slice split, primary records the approved sub-slice map in this file immediately after acceptance, then plans the first sub-slice.
  3. Implementation/delegation follows `TALA-Orchestrator-Protocol.md`.
  4. Before cleanup or commit, primary reports the protocol acceptance audit.
  5. Move accepted local work to `TALA-Local-Linear-Sync-Tracker.md` as `Done locally; pending explicit Linear sync`.
  6. Create the bounded local Git commit. This standing permission does not authorize push, deploy, PR, or Linear mutation.
  7. Remove completed standalone issues from this file. For parent issues, update the sub-slice map and keep the parent until all sub-slices are complete.
  8. Give the user an acceptance checklist. Patch current-slice defects before advancing.
  9. External Linear synchronization waits for the explicit command `Sync TAL-XX to Linear`.
- **Parent/Sub-slice Tracking:** If a parent issue is split, keep the parent in this document with a compact sub-slice map. Each sub-slice should show its ID, one-line purpose, status, and next boundary. Completed sub-slices are recorded in the local tracker, but the parent remains here until all sub-slices are complete.

Resume rule: after compaction, interruption, rejected worker output, failed/unclear handoff, or stale state, run the short resume checkpoint from `TALA-Orchestrator-Protocol.md` before continuing.

## Planning Rule

Do not duplicate protocol details here. For each active issue, this file should show only the status, goal, sub-slice map when needed, dependency lock, and next boundary. Source order, research/tool rules, benchmark/plugin gates, worker handoff, verification, tracker movement, and commits are controlled by `TALA-Orchestrator-Protocol.md`.

## Active and Upcoming Issues

Dependency lock:

1. Identity, roles, panels, and base administration come first.
2. Academic setup and calendar come before admissions handover because handover assigns Program and Curriculum.
3. Holds and lifecycle foundation come before enrollment because gates and COR visibility depend on student state.
4. Term offerings, resources, and a published Master Schedule come before enrollment binding.
5. Finance core comes before official enrollment because assessment, ledger, downpayment, accommodation, and Finance Gate affect enrollment.
6. Student Hub comes after source records exist; it is a projection, not a source module.
7. A pre-integration regression gate (TAL-93) must pass before integration hardening begins, proving the SIS foundation is clean.
8. CP-SAT and PayMongo end-to-end hardening (TAL-94/95) start only after the pre-integration gate passes; they are human-gated and require credentials/deployment steps.
9. A post-integration regression gate (TAL-96) runs after both integrations are wired in, catching regressions introduced by external service handlers before demo preparation.
10. Demo and rehearsal support (TAL-97) builds only on a fully verified and integration-tested system.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-91 | Done locally; pending explicit Linear sync | Student Hub Projection Acceptance: validate student-safe views for enrollment, schedule, finance, COR/output, grades, holds, lifecycle, completion, and notices. Split into TAL-91A-D, all complete; see Local Linear Sync Tracker. |
| TAL-92 | Done locally; pending explicit Linear sync | Reports, Audit, Imports, Retention, and Remaining Admin Acceptance: validate fixed reports/exports, audit evidence, guarded imports, retention categories, integration settings, operational monitoring, and remaining system configuration. Owning contract PRD `13_system_admin_reports_audit.md`. Split into TAL-92A–F, all six complete; see Local Linear Sync Tracker. |
| TAL-93 | Planned - split into TAL-93A-B | Cross-Role Regression, Security, and UAT Readiness (Pre-Integration Gate). Split into TAL-93A (foundation housekeeping - the cleanup debts parked here from parent TAL-92) and TAL-93B (the pre-integration verification gate itself; Layer 1, scope to be reformed with the user). See sub-slice map below. Must pass before integration hardening (TAL-94/95) begins. |
| TAL-94 | Planned | CP-SAT End-to-End Scheduling Hardening: prove validated foundation records through solver dispatch, candidate review, publication, schedule visibility, and safe failure/infeasibility handling. Human-gated; requires credentials, solver deployment, and manual verification steps. Deferred items routed here: from TAL-92B — add audit logging (`activity_log`) for solver run records (PRD §13.6 scope 8's solver-run half) once solver dispatch is wired in; from TAL-92A — Solver Run History report (PRD §13.3.4 admin/audit report #6); from TAL-92F: wire the Schedule Released production notification trigger once schedule publication is live. |
| TAL-95 | Planned | Payment Gateway End-to-End Hardening: prove payment attempt, gateway evidence, webhook verification, idempotent ledger posting, Finance Gate, and Accounting/Student visibility. Human-gated; requires API keys, webhook endpoints, and test-mode payment verification. Deferred items routed here: from TAL-92B — add audit logging (`activity_log`) for PayMongo checkout-attempt creation (PRD §13.6 scope 5's PayMongo half) once the live gateway is wired in; from TAL-92A — PayMongo Webhook Event Log report (PRD §13.3.4 admin/audit report #7); from TAL-92F: wire the Payment Received production notification trigger once the live gateway is wired in. |
| TAL-96 | Planned | Cross-Role Regression and Integration Coherence (Post-Integration Gate): verify the full system remains correct after CP-SAT and PayMongo integrations are wired in; catch regressions introduced by external service handlers, solver publication effects, and payment posting side-effects. |
| TAL-97 | Planned | Demo and Rehearsal Support from Verified MVP: rebuild only the realistic demonstration support needed for accepted flows, on top of a fully verified and integration-tested system. |
| TAL-98 | Planned (future enhancement, post-MVP) | Archival & Offline-Storage Management: optional archive-management interface (browse/restore archived and exported records), automated offline/cold-storage export, and on-premise HDD/SSD backup/replication target. Deferred from the TAL-92E retention clarification (PRD §13.7.5 point 7): V1 keeps all records in the operational database via soft-archive (hidden-but-queryable), so this is not required for the capstone MVP. Also the natural home for automated disposal jobs (PRD §13.7.4 rule 10) if the institution later explicitly requires them. Not a dependency for any MVP slice. |
| TAL-99 | Planned (future enhancement, post-MVP) | Data-Subject Privacy Request Handling & Log (RA 10173 §16): intake, DPO triage/fulfilment, and logging of data-subject requests (access, rectification, erasure/blocking, object, data portability, complaint). Deferred from TAL-92F: PRD §13.3.4 admin/audit report #10 (Privacy Request Log) has no source record, and RA 10173 with its IRR mandate no specific in-system request-log UI — data-subject-request handling is a DPO-owned manual/hybrid process. No MVP slice depends on it; access-request evidence is already partly served by `activity_log` + `output_access_logs`, and the erasure/blocking right routes through the TAL-92E hold-aware disposal-review ledger + the TAL-98 archival scope. Not a dependency for any MVP slice. |
| TAL-100 | Planned (future enhancement, post-MVP) | Configurable Notification Templates: admin-editable, database-backed email/notification templates (subject + body per notification type), replacing code-defined content. Deferred from TAL-92F: PRD §13.1.1 configurable record #17. V1 defines notification content in code (Laravel Mailable classes + Blade views); DB-editable templates are a post-MVP administration convenience, not an MVP dependency — no MVP slice requires them. |
| TAL-101 | Planned (future enhancement, post-MVP) | Database-Level Audit Tamper-Evidence Hardening: append-only / write-once protection for the `activity_log` table (e.g., MySQL triggers blocking UPDATE/DELETE, or hash-chaining for cryptographic verifiability). Deferred from TAL-93A (PRD 13.6 note). V1 enforces audit immutability at the application layer only (read-only `ActivityPolicy`, `canCreate()=false`), which the TAL-93A benchmark found proportionate for the capstone MVP; DB-level tamper-evidence is a post-MVP hardening enhancement. Not a dependency for any MVP slice. |

### TAL-93 Sub-Slice Map

Parent TAL-93 (pre-integration gate) is split into two sub-slices, approved 2026-07-08. TAL-93A resolves the cleanup debts parked here from parent TAL-92 before the gate runs; TAL-93B is the verification gate itself (Layer 1), whose scope will be reformed with the user. Design intent: the gate (TAL-93B) stays a pure pass/fail verification, while the actions and decisions live in TAL-93A.

| Sub-slice | Nature | Purpose | Status |
| --- | --- | --- | --- |
| TAL-93A | Housekeeping (decision + cleanup) | DONE. (a) Audit-immutability accepted as application-layer for V1 (proportionate per benchmark; the money trail in `ledger_entries` is separately protected by TAL-86C's reversal-ledger), DB-level tamper-evidence routed to post-MVP TAL-101 and the PRD 13.6 note updated. (b) Removed unused `pxlrbt/filament-activity-log`. (c) Removed unused `maatwebsite/excel` and deleted `config/excel.php`; both reconciled in the architecture spec. Removal proven safe (relevant tests 36/440 + isolated 4/4, composer validate, route:list 123, no dangling refs, Pint, diff-check; no app/ PHP changed). Two pre-existing whole-project debts surfaced and routed to TAL-93B. | Done locally; pending explicit Linear sync |
| TAL-93B | Verification gate (Layer 1) | The main pre-integration Cross-Role Regression, Security, and UAT-readiness gate; scope to be reformed with the user. NOW EXPLICITLY OWNS two pre-existing whole-project debts surfaced by TAL-93A: (1) test-isolation conflict - 6 `RefreshDatabase` files vs 59 `DatabaseTransactions` files share the seeded `test_tala_db`, causing 30 RoleAlreadyExists failures in a full-suite run; the gate must standardize isolation and reach full-suite green. (2) Whole-project Larastan = 407 errors at level 5 with no baseline (team checks only changed files); the gate must fix or baseline these. | Planned (awaiting reformation) |
### Next Boundary

Next primary boundary: TAL-93A (foundation housekeeping) - in planning; then TAL-93B (pre-integration verification gate, scope to be reformed). Parent TAL-92 complete, pending explicit Linear sync.
