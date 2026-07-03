# TALA Orchestrator Protocol

## Purpose

This document defines how future TALA MVP work should be planned, delegated, verified, tracked, and committed.

It exists because the remaining work must stay aligned with the PRD, UI blueprint, architecture, existing implementation, and the MVP deadline. The goal is not to restart the system. The goal is to continue through small vertical slices that are easier to reason about, test, and correct.

## Authority and Document Roles

Use these files for their intended purpose:

1. `AGENTS.md` — local agent/runtime rules for this workspace.
2. `00_Project_Documents/TALA-Orchestrator-Protocol.md` — committed orchestration rules for future planning and workers.
3. `00_Project_Documents/TALA-Rescue-Next-Steps.md` — active next-task planning.
4. `00_Project_Documents/TALA-Local-Linear-Sync-Tracker.md` — local-to-Linear sync status and compact synced history only.
5. `00_Project_Documents/prd_modules/` — product requirements, MVP boundaries, records, flows, outputs, and role behavior.
6. `00_Project_Documents/ui_surface_blueprint.md` — UI surfaces, roles, and workspace expectations.
7. `00_Project_Documents/architecture_specification.md` — system architecture and integration boundaries.
8. `CONTEXT.md` — shared domain language when relevant.

Do not use obsolete working notes or missing `docs/agents/*` links as authority.

## Source-of-Truth Order

Before planning or implementing a slice, read:

1. `00_Project_Documents/prd_modules/README.md`
2. Relevant PRD module files
3. `00_Project_Documents/ui_surface_blueprint.md`
4. `00_Project_Documents/architecture_specification.md`
5. `CONTEXT.md` when domain language matters
6. Current migrations, models, policies, routes, Filament resources, and tests

Existing code is salvage inventory. It is useful evidence, but it does not override the approved PRD or architecture.

## Vertical Slice Workflow

Work should continue as vertical slices, not broad horizontal rewrites.

Each slice must:

1. Define the module, role surface, data flow, and integration boundary.
2. Review the PRD, UI blueprint, architecture, schema, existing code, and tests.
3. Research unclear framework, plugin, UI, policy, or external-integration behavior before implementation.
4. Decide whether the PRD remains valid, needs clarification, or conflicts with the current implementation.
5. Implement only the accepted scope.
6. Verify the worker output with focused tests and static checks.
7. Provide a manual user checklist when external setup, credentials, dashboards, or human judgment are required.
8. Move completed local work to the sync tracker only after the slice or planned batch is fully complete.

If a slice is too large to verify properly, split it before implementation.

## Research Rules

Use research when the implementation choice is not already clear from the PRD and current code.

Use:

1. Laravel Boost and version-specific docs before Laravel, Filament, Livewire, Fortify, or framework code changes.
2. Context7, plugin documentation, MCPs, or official docs when library/plugin behavior is unclear.
3. Authoritative internet research for Philippine policy, institutional standards, external APIs, current integration contracts, or mature-system benchmarking.
4. Official or primary sources whenever possible.

Research may recommend a PRD clarification, but it must not expand MVP without a primary-thread decision.

## Orchestration Rules

Use a separate Codex thread only when the user explicitly asks for orchestration, delegation, background work, or another thread.

For ordinary work, the primary thread may proceed directly.

For delegated work:

1. Keep each task contained to the primary thread plus one dedicated worker thread unless the primary explicitly approves more.
2. The primary defines scope, source documents, exclusions, verification, and next boundary.
3. Workers must read `AGENTS.md` and relevant source docs before editing.
4. Workers must preserve unrelated worktree changes.
5. Workers must not commit, push, deploy, open PRs, sync external systems, or start the next issue unless explicitly scoped.
6. Workers must stop and give user instructions when external dashboards, credentials, approvals, or environment-specific setup are required.

## Worker Handshake

Every worker must end with:

1. `Status: PASS`, `PARTIAL`, or `FAIL`
2. Changed files
3. Exact work performed
4. Verification commands and results
5. Database target proof for DB-backed checks
6. Untouched exclusions
7. Caveats or blockers
8. Next boundary

The primary must independently inspect and proportionately verify worker output before accepting it.

## Git, Database, and Verification Rules

1. Preserve user-owned and unrelated changes.
2. Commit only after the intended scope is verified and the dirty set is understood.
3. Do not push unless explicitly requested.
4. Before DB-backed tests, prove `APP_ENV=testing`, `DB_CONNECTION=mysql`, and `DB_DATABASE=test_tala_db`.
5. Do not run DB-backed verification against `tala_db` or `tala_test_codex`.
6. Run focused PHPUnit tests for changed behavior.
7. Run `vendor/bin/pint --dirty --format agent` after PHP changes.
8. Run focused PHPStan/Larastan when a slice changes typed PHP paths or tests.
9. Run `git diff --check` before handoff or commit.

## MVP Product Guardrails

1. Keep the school information system foundation stable before expanding integrations.
2. Keep CP-SAT scheduling as constraint optimization over validated TALA records.
3. Treat solver output as candidate-only until staff publish accepted rows into `section_meetings`.
4. Keep payment gateway work tied to verified evidence, ledger posting, Finance Gate behavior, and Accounting reconciliation.
5. Keep role surfaces and navigation aligned with the PRD and UI blueprint.
6. Keep demo/rehearsal support separate from production MVP implementation unless explicitly promoted into the main chain.
