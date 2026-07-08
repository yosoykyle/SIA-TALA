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
| TAL-92 | Planned — split into TAL-92A–F | Reports, Audit, Imports, Retention, and Remaining Admin Acceptance: validate fixed reports/exports, audit evidence, guarded imports, retention categories, integration settings, and operational monitoring. Owning contract PRD `13_system_admin_reports_audit.md`. Split into TAL-92A–F (see sub-slice map below); parent stays open until all sub-slices complete. |
| TAL-93 | Planned | Cross-Role Regression, Security, and UAT Readiness (Pre-Integration Gate): verify schema, routes, policies, role surfaces, focused feature coverage, static analysis, formatting, and documentation alignment. Must pass before integration hardening begins. |
| TAL-94 | Planned | CP-SAT End-to-End Scheduling Hardening: prove validated foundation records through solver dispatch, candidate review, publication, schedule visibility, and safe failure/infeasibility handling. Human-gated; requires credentials, solver deployment, and manual verification steps. |
| TAL-95 | Planned | Payment Gateway End-to-End Hardening: prove payment attempt, gateway evidence, webhook verification, idempotent ledger posting, Finance Gate, and Accounting/Student visibility. Human-gated; requires API keys, webhook endpoints, and test-mode payment verification. |
| TAL-96 | Planned | Cross-Role Regression and Integration Coherence (Post-Integration Gate): verify the full system remains correct after CP-SAT and PayMongo integrations are wired in; catch regressions introduced by external service handlers, solver publication effects, and payment posting side-effects. |
| TAL-97 | Planned | Demo and Rehearsal Support from Verified MVP: rebuild only the realistic demonstration support needed for accepted flows, on top of a fully verified and integration-tested system. |

### TAL-92 Sub-Slice Map

Parent TAL-92 (owning contract PRD `13_system_admin_reports_audit.md`) is split into six sub-slices, approved by the user on 2026-07-08. The parent remains open until all six are complete. Order is by dependency; each sub-slice is planned, accepted, independently verified, and locally committed on its own before the next. Per-slice benchmark: each cites the parent-level RA 10173 / SIS-reporting benchmark accepted during the TAL-92 split; any sub-slice introducing a new exposed-data-class or manual-office decision (notably TAL-92E disposal) runs a focused top-up benchmark before its plan is finalized.

| Sub-slice | PRD | Nature | Purpose | Status |
| --- | --- | --- | --- | --- |
| TAL-92A | §13.3 | Acceptance + gap | Fixed reports (Registrar / Academic Head / Accounting / Admin-Audit): role-scoped visibility, controlled-filter tables, CSV streaming, hidden-fields-excluded, purpose-capture on sensitive exports, one export → one output-access/export-log record. Reconciled PRD `report_export_log` naming vs the implemented `output_access_logs`. Built the missing Curriculum Version Report and granted Academic Head visibility into the existing Graduation Eligibility Snapshot report (Academic Head catalog 9→11, matching the PRD's 11 enumerated reports). | Done locally; pending explicit Linear sync |
| TAL-92B | §13.6 | Acceptance | Audit trail coverage: prove Spatie Activitylog captures the 11 MVP audit scopes; read-only `ActivityResource` is Super-Admin-scoped and shows actor / action / record type+ID / before-after / source context. Existing: `ActivityResource`, `spatie/laravel-activitylog`, `pxlrbt/filament-activity-log`. | Planned |
| TAL-92C | §13.4 | Acceptance + gap | Guarded imports (Course Specification + Curriculum Version): template download, `template_version` / header / encoding validation, preview with downloadable row-numbered errors + warnings, warning acknowledgement, Draft-only creation, batch state machine, same validation/authorization/audit as manual entry. Existing: `ImportBatchResource`, import services, `ImportBatchLifecycleService`, `TAL82DImportTemplateAcceptanceTest`. | Planned |
| TAL-92D | §13.5, §13.2 | Acceptance + build | Integration settings acceptance (`SystemSettingResource`: restricted, write-only/secure-reference secrets, effective-dated, actor+reason audited) plus BUILD the operational-events / webhook monitoring review surface (retry only where safe). Existing: `SystemSettingResource`, `SystemSetting`, `OperationalEvent` model; monitoring Filament surface does not yet exist. | Planned |
| TAL-92E | §13.7 | Build | Retention categories + disposal review: category classification, read-only disposal-candidate table, hold-aware disposal block (no disposal under institutional / legal / audit / active-workflow hold), permission-controlled manual disposal confirmation, disposal audit logging. Only model columns (`archived_at`, `merged_into_id`) and `DuplicateProfileResolutionResource` exist today; the disposal-review surface must be built. | Planned |
| TAL-92F | §13.1, §13.2 | Acceptance (catch-all) | Remaining System Configuration + notification-metadata acceptance. Exact scope is bounded after TAL-92A–E reveal which configuration surfaces remain un-accepted. Closes the parent. | Planned |

Deferral recorded (protocol Deferral Tracking rule): automated disposal jobs (PRD §13.7.4 rule 10) remain deferred and land in TAL-92E's scope note — tracked, not built in MVP unless the institution explicitly requires them.

### Next Boundary

Next primary boundary: plan TAL-92B (Audit Trail Coverage Acceptance).
