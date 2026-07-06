# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work. It is reached after `AGENTS.md` and `TALA-Orchestrator-Protocol.md` in the agent intake chain, and it controls issue sequencing rather than product behavior.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. Primary plans the current issue or sub-slice and waits for acceptance.
  2. Implementation/delegation follows `TALA-Orchestrator-Protocol.md`.
  3. Before cleanup or commit, primary reports the protocol acceptance audit.
  4. Move accepted local work to `TALA-Local-Linear-Sync-Tracker.md` as `Done locally; pending explicit Linear sync`.
  5. Create the bounded local Git commit. This standing permission does not authorize push, deploy, PR, or Linear mutation.
  6. Remove completed standalone issues from this file. For parent issues, update the sub-slice map and keep the parent until all sub-slices are complete.
  7. Give the user an acceptance checklist. Patch current-slice defects before advancing.
  8. External Linear synchronization waits for the explicit command `Sync TAL-XX to Linear`.
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
7. CP-SAT and PayMongo end-to-end hardening start only after their SIS source records are accepted.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-87 | Planned | Enrollment Gate and Official Enrollment Acceptance: validate eligibility, section placement, capacity, seat reservation, document/academic/finance gates, exceptions, official enrollment, and schedule binding. |
| TAL-88 | Planned | COR and Official Output Acceptance: validate owning records, read-only views, authenticated print output, access logging, hold behavior, and source alignment with enrollment, schedule, and ledger. |
| TAL-89 | Planned | Grades Acceptance: validate faculty rosters, period outcomes, temporary/final marks, posting/release, completion/removal, correction, and student visibility. |
| TAL-90 | Planned | Progression, Completion, and Graduation Review Acceptance: validate prerequisite/progression effects, irregular/completion standing, graduation review batches, eligibility snapshots, and staff-controlled visibility. |
| TAL-91 | Planned | Student Hub Projection Acceptance: validate student-safe views for enrollment, schedule, finance, COR/output, grades, holds, lifecycle, completion, and notices. |
| TAL-92 | Planned | Reports, Audit, Imports, Retention, and Remaining Admin Acceptance: validate fixed reports/exports, audit evidence, guarded imports, retention categories, integration settings, and operational monitoring. |
| TAL-93 | Planned | CP-SAT End-to-End Scheduling Hardening: prove validated foundation records through solver dispatch, candidate review, publication, schedule visibility, and safe failure/infeasibility handling. |
| TAL-94 | Planned | Payment Gateway End-to-End Hardening: prove payment attempt, gateway evidence, webhook verification, idempotent ledger posting, Finance Gate, and Accounting/Student visibility. |
| TAL-95 | Planned | Cross-Role Regression, Security, and UAT Readiness: verify schema, routes, policies, role surfaces, focused feature coverage, static analysis, formatting, and documentation alignment. |
| TAL-96 | Planned | Demo and Rehearsal Support from Verified MVP: rebuild only the realistic demonstration support needed for accepted flows. |

### TAL-87 Sub-slice Map

Parent goal: Enrollment Gate and Official Enrollment Acceptance.

| Sub-slice | Status | Purpose | Next boundary |
| --- | --- | --- | --- |
| TAL-87A | Done locally; pending explicit Linear sync | Clean enrollment source-record baseline and retire or bypass stale legacy sectioning paths that no longer match the clean schema. | Recorded in local tracker; waits for explicit Linear sync. |
| TAL-87B | Done locally; pending explicit Linear sync | Add or align the staff gate review surface so Registrar-facing enrollment gate status is clear, evidence-backed, and based on current source records. | Recorded in local tracker; waits for explicit Linear sync. |
| TAL-87C | Done locally; pending explicit Linear sync | Evaluate document, academic, lifecycle, unit-load, capacity, finance, and exception gates without over-automating manual office decisions. | Recorded in local tracker; waits for explicit Linear sync. |
| TAL-87D | Planned | Finalize official enrollment acceptance: verify all gates, convert reservation/source records, bind official schedule records, and expose the source records needed by COR and Student Hub. | Starts only after TAL-87C is accepted and cleaned up. |

TAL-87 exclusions for all sub-slices: no CP-SAT solver hardening, PayMongo hardening, COR redesign, Student Hub redesign, reports/export work, seeders/demo database work, push/deploy/PR, or Linear sync.

### Next Boundary

Next primary-planning boundary is TAL-87D: official enrollment acceptance. Do not implement TAL-87D until primary planning classifies the slice, checks the PRD/blueprint/code fit, runs the protocol manual/digital reality-check benchmark, and receives user approval.
