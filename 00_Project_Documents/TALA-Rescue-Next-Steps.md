# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work. It is reached after `AGENTS.md` and `TALA-Orchestrator-Protocol.md` in the agent intake chain, and it controls issue sequencing rather than product behavior.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. Plan one small issue, or a tightly related contract/implementation/cleanup batch, here.
  2. Complete its research, accepted contract, implementation, worker verification, and independent primary verification.
  3. Move the accepted issue to `TALA-Local-Linear-Sync-Tracker.md` as `Done locally; pending explicit Linear sync`.
  4. Create the bounded local Git commit. This standing permission does not authorize a push or Linear mutation.
  5. Remove the completed issue from this planning document immediately after it is recorded in the local tracker.
  6. Give the user an acceptance checklist and patch the current issue before advancing when review finds a defect.
  7. Keep external Linear synchronization pending until the user explicitly says `Sync TAL-XX to Linear`; no other completion command implies external-sync permission.

## Source-of-Truth Order

Use this order before implementing each slice:

1. `00_Project_Documents/prd_modules/README.md`
2. `00_Project_Documents/prd_modules/` (All relevant modules inside this directory)
3. `00_Project_Documents/ui_surface_blueprint.md`
4. `00_Project_Documents/architecture_specification.md`
5. Existing code and tests

## Research and Tool-Use Order

Apply this order to every planned worker slice:

1. Read the relevant source-of-truth documents, schema contract, current migrations, and existing implementation before deciding the change.
2. Use Laravel Boost `application-info` and version-specific `search-docs` before Laravel ecosystem code changes.
3. When an important technical, integration, or repository question remains unanswered, use the relevant available MCP, connector, or specialized tool before making an assumption.
4. Use authoritative internet research when an institutional policy, Philippine regulatory requirement, external integration contract, current standard, or mature-system benchmark remains unclear. Prefer primary official sources and record the supporting links in the worker report.
5. Research resolves gaps but does not override an approved PRD decision or expand the MVP. If authoritative evidence conflicts with the approved flow or would materially change scope, stop and report the conflict to the primary thread for a decision.
6. Implement only after the required questions are resolved, then run the slice's focused tests and regression checks.

## Vertical Slice Workflow

Use the stable protocol in `AGENTS.md` and `00_Project_Documents/TALA-Orchestrator-Protocol.md` for orchestration. For this planning document, each upcoming issue should stay small enough to inspect, implement, test, and verify without losing the PRD-to-code connection.

For each slice:

1. Define the role, trigger, inputs, changed records, outputs, UI surface, related modules, integration boundary, and exclusions.
2. Review the PRD modules, UI blueprint, architecture specification, schema/migrations, existing code, routes, policies, and tests.
3. Research unclear framework, UI, plugin, integration, policy, or mature-system questions before implementation.
4. Decide whether the PRD and UI blueprint remain valid, need clarification, or conflict with the current implementation.
5. Primary presents the slice plan, worker boundary, verification plan, human-only steps, and exclusions for user acceptance.
6. Implement or delegate only after the user accepts the plan.
7. Record accepted document changes and review dependent modules before finalizing implementation.
8. Implement only the accepted slice.
9. Require worker self-verification and independent primary verification.
10. Follow the tracker, local-commit, explicit Linear-authorization, and user-acceptance sequence above.

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
| TAL-81 | Planned | Identity, Access, Workspace, and Admin Baseline Acceptance: validate accounts, roles, panel access, authorization, base settings, audit visibility, and role-facing navigation. |
| TAL-82 | Planned | Academic Setup and Calendar Acceptance: validate programs, curricula, course specifications, components, grade-outcome policy inputs, academic years, terms, calendar windows, import templates, and setup records used by later modules. |
| TAL-83 | Planned | Admissions and Student Handover Acceptance: validate applicant draft/submission, requirements, review, acceptance, duplicate handling, official student creation, Program/Curriculum assignment, and Student Hub activation gate. |
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

Next implementation slice is TAL-81 Identity, Access, Workspace, and Admin Baseline Acceptance. TAL-81 must start with a slice contract against PRD Module 2, the UI blueprint, architecture specification, current auth/role implementation, and admin/audit surfaces. Do not begin CP-SAT or PayMongo hardening until their prerequisite foundation slices are accepted.
