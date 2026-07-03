# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work. It is reached after `AGENTS.md` and `TALA-Orchestrator-Protocol.md` in the agent intake chain, and it controls issue sequencing rather than product behavior.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. Plan one small issue, or a tightly related contract/implementation/cleanup batch, here.
  2. Complete its research, accepted contract, implementation, worker verification, and independent primary verification.
  3. Move the accepted issue to `TALA-Local-Linear-Sync-Tracker.md` as `Done locally; pending explicit Linear sync`.
  4. Create the bounded local Git commit. This standing permission does not authorize a push or Linear mutation.
  5. Give the user an acceptance checklist and patch the current issue before advancing when review finds a defect.
  6. Keep the issue pending locally until the user explicitly says `Sync TAL-XX to Linear`; no other completion command implies external-sync permission.
  7. After the explicit Linear sync, move the tracker row to compact synced history and remove the completed issue from this planning document.

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
5. Record accepted document changes and review dependent modules before finalizing implementation.
6. Implement only the accepted slice.
7. Require worker self-verification and independent primary verification.
8. Follow the tracker, local-commit, explicit Linear-authorization, and user-acceptance sequence above.

## Planned Issues

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-80 | In progress | Foundation Acceptance Map and Slice Sequencing: the standalone glossary authority audit is accepted; complete the bounded dependency inventory, correct the provisional task order, and lock the exact boundaries of the module acceptance slices. This is not a whole-system rewrite. |
| TAL-81 | Planned | Identity, Access, and Workspace Acceptance: validate accounts, roles, panel access, handover gates, authorization, and role-facing navigation. |
| TAL-82 | Planned | Admissions and Student Handover Acceptance: validate applicant draft/submission, requirements, review, acceptance, duplicate handling, and official student creation. |
| TAL-83 | Planned | Academic Setup Acceptance: validate programs, curricula, course specifications, grade policy inputs, academic years, and term configuration. |
| TAL-84 | Planned | Term Offerings, Resources, and Calendar Acceptance: validate sections, offerings, delivery groups, faculty/room eligibility, capacities, and scheduling blocks. |
| TAL-85 | Planned | Enrollment Gate Acceptance: validate eligibility, reservations, placement, holds, exceptions, and official enrollment outcomes. |
| TAL-86 | Planned | Finance and Ledger Core Acceptance: validate fee rules, assessments, downpayments, ledger ownership, reconciliation inputs, and Finance Gate behavior without expanding the gateway. |
| TAL-87 | Planned | COR and Official Output Acceptance: validate owning records, read-only views, authenticated print output, access logging, and hold behavior. |
| TAL-88 | Planned | Grades Acceptance: validate faculty rosters, period outcomes, temporary/final marks, posting, completion, correction, and student visibility. |
| TAL-89 | Planned | Progression, Lifecycle, and Graduation Acceptance: validate holds, irregular progression, lifecycle changes, completion work, and eligibility snapshots. |
| TAL-90 | Planned | Student Hub Acceptance: validate the student-safe projection of enrollment, schedule, finance, outputs, grades, holds, lifecycle, and completion. |
| TAL-91 | Planned | System Administration, Reports, Audit, and Import Acceptance: validate staff controls, fixed reports/exports, audit evidence, settings, retention responsibilities, and guarded imports. |
| TAL-92 | Planned | CP-SAT End-to-End Scheduling Hardening: prove validated foundation records through solver dispatch, candidate review, publication, and schedule visibility, including safe failure and infeasibility handling. |
| TAL-93 | Planned | Payment Gateway End-to-End Hardening: prove payment attempt, gateway evidence, webhook verification, idempotent ledger posting, Finance Gate, and Accounting/Student visibility. |
| TAL-94 | Planned | Cross-Role Regression, Security, and UAT Readiness: verify schema, routes, policies, role surfaces, focused feature coverage, static analysis, formatting, and documentation alignment. |
| TAL-95 | Planned | Demo and Rehearsal Support from Verified MVP: rebuild only the realistic demonstration support needed for accepted flows. |

Completed locally and recorded in the local sync tracker:

- TAL-71 Finance Outputs and Student Hub Finance.
- TAL-72 Grades MVP.
- TAL-73 Progression and Student Lifecycle MVP.
- TAL-74 Graduation and Completion Review MVP.
- TAL-75 Reports, Audit, and Export MVP.
- TAL-76 Bootstrap Public Landing Page Adaptation.
- TAL-77 Calendar-Event Availability Alignment and Solver Mapping.
- TAL-78 Current dirty-work cleanup and scheduling/access verification.

### Next Boundary

Continue TAL-80 Foundation Acceptance Map and Slice Sequencing. The TAL-80A glossary authority audit is accepted: canonical terms remain in their owning PRD modules and the redundant standalone glossary is removed. The remaining TAL-80 work must correct the provisional dependency order and lock the exact boundaries of later module slices; it does not authorize a broad rewrite. Each later foundation issue may be split into contract, implementation, and cleanup subtasks when its scope is too large. CP-SAT and PayMongo hardening begin only after their prerequisite foundation slices are accepted.
