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
| TAL-84 | Planned | Holds and Student Lifecycle Foundation Acceptance: validate primary lifecycle status, academic standing, active holds, waivers, student unit-load exceptions, and source-record effects used by enrollment, COR, finance, and Student Hub. |
| TAL-85 | Planned | Term Offerings, Resources, and Master Schedule Foundation Acceptance: validate offerings, sections, delivery groups, rooms, faculty qualifications/load/availability, scheduling blocks, and official `section_meetings` publication/readiness without expanding CP-SAT. |
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

### TAL-84 Sub-slice Map

Parent issue: Holds and Student Lifecycle Foundation Acceptance.

| Sub-slice | Status | Purpose | Next Boundary |
| --- | --- | --- | --- |
| TAL-84A | Done locally; pending explicit Linear sync | Holds and lifecycle-status contract: debt uses explicit financial holds, applicant states stay in Admissions, student-facing hold/lifecycle messaging is safe, and waiver ownership follows office responsibility. | Cleanup complete; wait for explicit Linear sync only. |
| TAL-84B | Next | Core lifecycle recorded results: validate Active/LOA/Withdrawn/Transferred Out/Inactive/Archived/Reactivation effects, staff recording rules, student-safe visibility, and downstream gate inputs. | Primary planning before implementation. |
| TAL-84C | Planned | Program-shift credit evaluation: validate future-term program shift, target curriculum, credit checklist, and dependent enrollment/progression effects. | Start only after TAL-84B acceptance. |
| TAL-84D | Planned | Academic standing and student unit-load exceptions: separate student load policy from faculty load, validate exception authority, and align enrollment gate consumers. | Start only after TAL-84C acceptance. |

### Next Boundary

Next primary planning boundary is TAL-84B: Core lifecycle recorded results. The primary must inspect the owning PRD and related lifecycle, enrollment, finance, COR, and Student Hub contracts; compare them with the clean schema and current implementation; then propose an evidence-backed slice plan before implementation. Do not begin TAL-85, CP-SAT, or PayMongo hardening until all TAL-84 sub-slices are accepted.
