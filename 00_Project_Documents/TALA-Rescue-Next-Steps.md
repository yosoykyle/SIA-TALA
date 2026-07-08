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
| TAL-93 | Planned | Cross-Role Regression, Security, and UAT Readiness (Pre-Integration Gate): verify schema, routes, policies, role surfaces, focused feature coverage, static analysis, formatting, and documentation alignment. Must pass before integration hardening begins. Deferred items routed here: (a) from TAL-92B — evaluate DB-level append-only/tamper-evident audit hardening for `activity_log` (V1 audit immutability is application-layer/read-only-policy only; PRD §13.6 note records this posture); (b) from TAL-92A — decide on removing the unused `pxlrbt/filament-activity-log` dependency (zero references in the codebase; hand-built `ActivityResource` supersedes it); (c) from TAL-92C — audit whether `maatwebsite/excel` is actually used anywhere (TALA's academic imports use native `fgetcsv`/`fputcsv`, not this package) and decide on removal alongside the `pxlrbt` decision. |
| TAL-94 | Planned | CP-SAT End-to-End Scheduling Hardening: prove validated foundation records through solver dispatch, candidate review, publication, schedule visibility, and safe failure/infeasibility handling. Human-gated; requires credentials, solver deployment, and manual verification steps. Deferred items routed here: from TAL-92B — add audit logging (`activity_log`) for solver run records (PRD §13.6 scope 8's solver-run half) once solver dispatch is wired in; from TAL-92A — Solver Run History report (PRD §13.3.4 admin/audit report #6); from TAL-92F: wire the Schedule Released production notification trigger once schedule publication is live. |
| TAL-95 | Planned | Payment Gateway End-to-End Hardening: prove payment attempt, gateway evidence, webhook verification, idempotent ledger posting, Finance Gate, and Accounting/Student visibility. Human-gated; requires API keys, webhook endpoints, and test-mode payment verification. Deferred items routed here: from TAL-92B — add audit logging (`activity_log`) for PayMongo checkout-attempt creation (PRD §13.6 scope 5's PayMongo half) once the live gateway is wired in; from TAL-92A — PayMongo Webhook Event Log report (PRD §13.3.4 admin/audit report #7); from TAL-92F: wire the Payment Received production notification trigger once the live gateway is wired in. |
| TAL-96 | Planned | Cross-Role Regression and Integration Coherence (Post-Integration Gate): verify the full system remains correct after CP-SAT and PayMongo integrations are wired in; catch regressions introduced by external service handlers, solver publication effects, and payment posting side-effects. |
| TAL-97 | Planned | Demo and Rehearsal Support from Verified MVP: rebuild only the realistic demonstration support needed for accepted flows, on top of a fully verified and integration-tested system. |
| TAL-98 | Planned (future enhancement, post-MVP) | Archival & Offline-Storage Management: optional archive-management interface (browse/restore archived and exported records), automated offline/cold-storage export, and on-premise HDD/SSD backup/replication target. Deferred from the TAL-92E retention clarification (PRD §13.7.5 point 7): V1 keeps all records in the operational database via soft-archive (hidden-but-queryable), so this is not required for the capstone MVP. Also the natural home for automated disposal jobs (PRD §13.7.4 rule 10) if the institution later explicitly requires them. Not a dependency for any MVP slice. |
| TAL-99 | Planned (future enhancement, post-MVP) | Data-Subject Privacy Request Handling & Log (RA 10173 §16): intake, DPO triage/fulfilment, and logging of data-subject requests (access, rectification, erasure/blocking, object, data portability, complaint). Deferred from TAL-92F: PRD §13.3.4 admin/audit report #10 (Privacy Request Log) has no source record, and RA 10173 with its IRR mandate no specific in-system request-log UI — data-subject-request handling is a DPO-owned manual/hybrid process. No MVP slice depends on it; access-request evidence is already partly served by `activity_log` + `output_access_logs`, and the erasure/blocking right routes through the TAL-92E hold-aware disposal-review ledger + the TAL-98 archival scope. Not a dependency for any MVP slice. |
| TAL-100 | Planned (future enhancement, post-MVP) | Configurable Notification Templates: admin-editable, database-backed email/notification templates (subject + body per notification type), replacing code-defined content. Deferred from TAL-92F: PRD §13.1.1 configurable record #17. V1 defines notification content in code (Laravel Mailable classes + Blade views); DB-editable templates are a post-MVP administration convenience, not an MVP dependency — no MVP slice requires them. |

### Next Boundary

Next primary boundary: parent TAL-92 complete — all six sub-slices (TAL-92A–F) done locally, pending explicit Linear sync. Next issue: TAL-93 (Cross-Role Regression, Security, and UAT Readiness — Pre-Integration Gate).
