# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work. It is reached after `AGENTS.md` and `TALA-Orchestrator-Protocol.md` in the agent intake chain, and it controls issue sequencing rather than product behavior.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. Plan one small issue, or a tightly related contract/implementation/cleanup batch, here.
  2. Complete its research, accepted contract, implementation, worker verification, and independent primary verification.
  3. Before cleanup or commit, primary reports a pre-cleanup acceptance audit: authority alignment, accepted scope, retained-surface purpose, exclusions, verification, dirty state, and next boundary.
  4. Move the accepted issue to `TALA-Local-Linear-Sync-Tracker.md` as `Done locally; pending explicit Linear sync`.
  5. Create the bounded local Git commit. This standing permission does not authorize a push or Linear mutation.
  6. Remove the completed issue from this planning document immediately after it is recorded in the local tracker.
  7. Give the user an acceptance checklist and patch the current issue before advancing when review finds a defect.
  8. Keep external Linear synchronization pending until the user explicitly says `Sync TAL-XX to Linear`; no other completion command implies external-sync permission.
- **Parent/Sub-slice Tracking:** If a parent issue is split, keep the parent in this document with a compact sub-slice map. Each sub-slice should show its ID, one-line purpose, status, and next boundary. Completed sub-slices are recorded in the local tracker, but the parent remains here until all sub-slices are complete.

Resume rule: after compaction, interruption, rejected worker output, failed/unclear handoff, or stale state, run the short resume checkpoint from `TALA-Orchestrator-Protocol.md` before continuing.

## Source-of-Truth Order

Use this order before implementing each slice:

1. `00_Project_Documents/prd_modules/README.md`
2. `00_Project_Documents/prd_modules/` (All relevant modules inside this directory)
3. `00_Project_Documents/ui_surface_blueprint.md`
4. `00_Project_Documents/architecture_specification.md`
5. `00_Project_Documents/business-evidence/` only when it clarifies workflow, terminology, document shape, or realistic data examples
6. Existing code and tests

Business evidence is context, not scope authority. Use it to understand the client's current environment, then compare it with PRD scope, mature SIS benchmarks, and framework-native implementation patterns. Exclude Senior High School-only material unless it is proven applicable to college workflows. If business evidence conflicts with benchmark/native implementation or would bloat MVP, surface the conflict in the primary plan instead of copying the evidence into the system.

## Research and Tool-Use Order

Apply this order to every planned worker slice:

1. Read the relevant source-of-truth documents, schema contract, current migrations, and existing implementation before deciding the change.
2. Read relevant business-evidence files only for client-context clarification, not as automatic requirements.
3. Use Laravel Boost `application-info` and version-specific `search-docs` before Laravel ecosystem code changes.
4. Run the benchmark/implementation-fit gate every slice: decide whether mature-system/internet benchmarking is required and whether plugin-catalog research is required. If not required, state why the PRD/current implementation/native Filament path is sufficient.
5. When an important technical, integration, or repository question remains unanswered, use the relevant available MCP, connector, or specialized tool before making an assumption.
6. Use authoritative internet research when an institutional policy, Philippine regulatory requirement, external integration contract, current standard, or mature-system benchmark remains unclear. Prefer primary official sources and record the supporting links in the worker report.
7. Research resolves gaps but does not override an approved PRD decision or expand the MVP. If authoritative evidence conflicts with the approved flow or would materially change scope, stop and report the conflict to the primary thread for a decision.
8. Implement only after the required questions are resolved, then run the slice's focused tests and regression checks.

## Vertical Slice Workflow

Use the stable protocol in `AGENTS.md` and `00_Project_Documents/TALA-Orchestrator-Protocol.md` for orchestration. For this planning document, each upcoming issue should stay small enough to inspect, implement, test, and verify without losing the PRD-to-code connection.

For each slice:

1. Define the role, trigger, inputs, changed records, outputs, UI surface, related modules, integration boundary, and exclusions.
2. Review the PRD modules, UI blueprint, architecture specification, schema/migrations, existing code, routes, policies, and tests.
3. Run the benchmark/implementation-fit gate: compare current implementation, native Filament, installed packages, qualified plugin options, focused custom code, and the need for mature-system benchmarking.
4. Research unclear framework, UI, plugin, integration, policy, or mature-system questions before implementation; when deep research is not needed, state the basis for that decision.
5. Decide whether the PRD and UI blueprint remain valid, need clarification, or conflict with the current implementation.
6. Primary presents the purposeful-simplification judgment, evidence checked, recommendation basis, workflow/UI fit review, retained-surface purpose statements, slice plan, worker boundary, verification plan, human-only steps, and exclusions for user acceptance.
7. Implement or delegate only after the user accepts the plan.
8. If delegated, hand off only the accepted checklist. The worker executes the checklist and must stop as `BLOCKED` if the checklist, current issue, or allowed scope is unclear. The worker must not act as another primary orchestrator or return monitoring/status filler as completion.
9. Use minimal worker context by default. For major TAL slices, prefer an inspectable visible worker thread when explicitly authorized and available; otherwise use one accountable internal worker with the same final handshake requirement.
10. Record accepted document changes and review dependent modules before finalizing implementation.
11. Implement only the accepted slice.
12. Require worker self-verification and independent primary verification.
13. Run the pre-cleanup acceptance audit before tracker movement or commit.
14. Follow the tracker, local-commit, explicit Linear-authorization, and user-acceptance sequence above.

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

### Next Boundary

Next primary planning boundary is TAL-84: Holds and Student Lifecycle Foundation Acceptance. The primary must inspect the owning PRD and related lifecycle, enrollment, finance, COR, and Student Hub contracts; compare them with the clean schema and current implementation; then propose small evidence-backed sub-slices before implementation. Do not begin TAL-85, CP-SAT, or PayMongo hardening until TAL-84 is accepted.
