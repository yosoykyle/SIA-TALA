# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. We plan the next batch of issues and their descriptions here.
  2. We take action and implement the issues.
  3. **Important:** Issues are only moved to `TALA-Local-Linear-Sync-Tracker.md` for syncing after **all** the planned issues/steps in the current batch are fully completed. It will not be moved if just one issue is done.
  4. The completed batch of issues is then removed from this planning document.

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

1. Define the exact module, role surface, data flow, and integration boundary.
2. Review the PRD modules, UI blueprint, architecture specification, schema/migrations, existing code, and tests.
3. Research unclear framework, UI, plugin, integration, policy, or mature-system questions before implementation.
4. Decide whether the PRD remains valid, needs a small clarification, or conflicts with the current implementation.
5. Implement only the accepted slice.
6. Verify the worker output and provide a manual user checklist when external setup or human review is required.
7. Move completed local work to the sync tracker only after the slice or planned batch is fully done.

## Planned Issues

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-80 | Planned | MVP Vertical Slice Readiness Audit: review PRD, UI blueprint, architecture, schema, routes/resources, policies, and tests by module before continuing integration-heavy work. |
| TAL-81 | Planned | CP-SAT End-to-End Scheduling Hardening: prove demand -> solver dispatch -> Cloud Run result -> candidate rows -> staff review -> `section_meetings` publication -> Student Hub/COR schedule visibility. |
| TAL-82 | Planned | CP-SAT Failure and Infeasibility Handling: surface blocked, failed, infeasible, invalid, and unknown solver states safely and prevent invalid publication. |
| TAL-83 | Planned | Payment Gateway End-to-End Verification: verify payment attempt, gateway/mock evidence, ledger posting, Finance Gate, and Accounting/Student visibility. |
| TAL-84 | Planned | Role Surface and Access Regression Pass: verify Applicant, Student, Faculty, Registrar, Accounting, Academic Head, and System Admin workspaces against PRD and UI blueprint. |
| TAL-85 | Planned | Final MVP Stabilization Pass: run focused schema, route, policy, feature, PHPStan, Pint, and documentation alignment checks. |
| TAL-86 | Planned | Demo/Rehearsal Support from Verified MVP: rebuild only the demo support needed for verified MVP flows. |

Completed locally and recorded in the local sync tracker:

- TAL-71 Finance Outputs and Student Hub Finance.
- TAL-72 Grades MVP.
- TAL-73 Progression and Student Lifecycle MVP.
- TAL-74 Graduation and Completion Review MVP.
- TAL-75 Reports, Audit, and Export MVP.
- TAL-76 Bootstrap Public Landing Page Adaptation.
- TAL-77 Calendar-Event Availability Alignment and Solver Mapping.
- TAL-78 Current dirty-work cleanup and scheduling/access verification.
- TAL-79 Orchestrator Protocol and Vertical Slice Workflow Codification.

### Next Boundary

Proceed to TAL-80 before additional CP-SAT or payment implementation. The next slice should audit the implemented MVP vertically against the PRD, UI blueprint, architecture, schema, role surfaces, and tests, then produce the exact patch list needed before TAL-81 scheduling hardening.
