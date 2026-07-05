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
| TAL-85 | Split planned | Term Offerings, Resources, and Master Schedule Foundation Acceptance: validate offerings, sections, delivery groups, rooms, faculty qualifications/load/availability, scheduling blocks, and official `section_meetings` publication/readiness without expanding CP-SAT. |
| TAL-86 | Planned | Finance Core and Assessment Acceptance: validate fee rules, downpayment rules, assessments, ledger ownership, adjustments, financial accommodations, OR mapping boundary, and Finance Gate source behavior without expanding PayMongo. |
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

### TAL-85 Sub-slice Map

| Slice | Status | Purpose | Next Boundary |
| --- | --- | --- | --- |
| TAL-85A | Done locally; pending explicit Linear sync | Term Offering and Section Delivery Group source-record UI alignment. | Accepted locally; recorded in the local sync tracker. |
| TAL-85B | Done locally; pending explicit Linear sync | Resource readiness surfaces. | Accepted locally; recorded in the local sync tracker. |
| TAL-85C | Done locally; pending explicit Linear sync | Scheduling demand readiness acceptance. | Accepted locally; recorded in the local sync tracker. |
| TAL-85D | Planned | Master schedule publication/read-only official meetings acceptance. | Prove candidate publication creates official `section_meetings`, keeps them read-only, and preserves the boundary that Student Hub/COR/enrollment read only published meetings. |

### Next Boundary

Next implementation boundary is TAL-85D: Master schedule publication/read-only official meetings acceptance. Keep the work limited to proving candidate publication creates official `section_meetings`, keeps them read-only, and preserves the boundary that Student Hub, COR, and enrollment read only published meetings. Do not expand enrollment binding, finance, Student Hub schedule projection, CP-SAT dispatch, Cloud Run, or solver hardening beyond checks needed to preserve the TAL-85 dependency chain.
