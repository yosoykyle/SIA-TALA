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
| TAL-92 | Planned | Reports, Audit, Imports, Retention, and Remaining Admin Acceptance: validate fixed reports/exports, audit evidence, guarded imports, retention categories, integration settings, and operational monitoring. |
| TAL-93 | Planned | Cross-Role Regression, Security, and UAT Readiness (Pre-Integration Gate): verify schema, routes, policies, role surfaces, focused feature coverage, static analysis, formatting, and documentation alignment. Must pass before integration hardening begins. |
| TAL-94 | Planned | CP-SAT End-to-End Scheduling Hardening: prove validated foundation records through solver dispatch, candidate review, publication, schedule visibility, and safe failure/infeasibility handling. Human-gated; requires credentials, solver deployment, and manual verification steps. |
| TAL-95 | Planned | Payment Gateway End-to-End Hardening: prove payment attempt, gateway evidence, webhook verification, idempotent ledger posting, Finance Gate, and Accounting/Student visibility. Human-gated; requires API keys, webhook endpoints, and test-mode payment verification. |
| TAL-96 | Planned | Cross-Role Regression and Integration Coherence (Post-Integration Gate): verify the full system remains correct after CP-SAT and PayMongo integrations are wired in; catch regressions introduced by external service handlers, solver publication effects, and payment posting side-effects. |
| TAL-97 | Planned | Demo and Rehearsal Support from Verified MVP: rebuild only the realistic demonstration support needed for accepted flows, on top of a fully verified and integration-tested system. |

### TAL-91 Sub-slice Map

Owning contract: PRD `prd_modules/12_student_hub.md`; UI blueprint Student Hub page map.

| Sub-slice | Status | Purpose | Next Boundary |
| --- | --- | --- | --- |
| TAL-91A | Done locally; pending explicit Linear sync | Hub access, own-records-only isolation, Dashboard display-priority ordering, and account/security notices. | Recorded in local tracker. |
| TAL-91B | Done locally; pending explicit Linear sync | Student-safe Finance projection and official-output (SOA/billing/acknowledgement) access logging. | Recorded in local tracker; TAL-91C next. |
| TAL-91C | Done locally; pending explicit Linear sync | Academic outputs projection: COR, published schedule, enrolled subjects, and enrollment-status surface. | Recorded in local tracker; TAL-91D next. |
| TAL-91D | Done locally; pending explicit Linear sync | Academic status student-safe regression: Holds view office-to-contact column, Academic Standing surfaced on Lifecycle view, grades/completion re-verified as regression. | Recorded in local tracker. Parent TAL-91 complete (91A-91D all done). |
| TAL-91E | Done locally; pending explicit Linear sync | Student Hub Display Priority completion: implemented remaining resolver tiers 6-10 (Missing requirements, Active academic deficiency, Schedule available, COR available, Grades released) in `StudentHubPriorityResolver`, each reusing an already-existing data source rather than new logic. All 11 §12.2 tiers now implemented. | Recorded in local tracker. |

Parent TAL-91 (Student Hub Projection Acceptance) is complete: 91A, 91B, 91C, and 91D all done locally, pending explicit Linear sync.

### Next Boundary

Next primary boundary: plan TAL-92.
