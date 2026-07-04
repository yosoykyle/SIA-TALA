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

Do not use obsolete working notes or missing `docs/agents/*` links as authority.

## Agent Intake and Routing Chain

`AGENTS.md` is the entry point for every primary or worker agent in this workspace.

1. Read the complete `AGENTS.md` without modifying the generated Laravel Boost block.
2. Read this protocol for the detailed orchestration process.
3. Read `TALA-Rescue-Next-Steps.md` to identify the authorized issue and next boundary.
4. Read `TALA-Local-Linear-Sync-Tracker.md` only for issue numbering and synchronization state.
5. Read the relevant PRD modules, UI blueprint, and architecture specification.
6. Inspect migrations, models, services, policies, routes, Filament surfaces, and tests as implementation evidence.
7. Define the slice contract and decide research, delegation, implementation, verification, and handoff only after completing this intake.

Use ownership to resolve apparent conflicts:

1. Runtime instructions, Laravel Boost, and official version-specific documentation control framework and tool usage.
2. PRD modules control product behavior and MVP boundaries.
3. The UI blueprint controls role and surface mapping while remaining subordinate to product behavior.
4. The architecture specification controls system and integration boundaries.
5. This protocol controls orchestration and verification.
6. Next Steps controls task order.
7. The local tracker records synchronization state only.
8. Existing code and tests are salvage evidence, not higher authority.

If applicable authorities still conflict, stop and reconcile the conflict in the primary thread before implementation.

## Primary Orchestrator Activation

Repository instruction discovery loads the rules, but the user's prompt activates the orchestration role. A new session becomes the TALA primary orchestrator when the user explicitly says `Act as the TALA primary orchestrator`, `Resume TALA orchestration`, `Plan the next TALA task`, or otherwise clearly requests orchestration.

After activation, complete the intake chain before implementation, delegation, tracker movement, or commit, then report:

1. Current Git and dirty-worktree state.
2. Current issue and next boundary from Next Steps and the local sync tracker.
3. Relevant authority documents and any detected conflict or stale statement.
4. Whether work should stay in the primary thread or use one explicitly authorized accountable task worker.
5. A concise proposed slice plan and explicit exclusions.

The primary thread owns first-pass planning. It may inspect files, run read-only discovery, and research unclear items to prepare the plan, but it must not implement, delegate, update trackers, or commit until the user accepts the plan. Exception: a small docs-only protocol correction explicitly requested by the user may be applied directly.

Primary planning must be evidence-backed. The recommendation must state what was checked and why the proposed path is MVP-fit: current authority documents, implementation evidence, Laravel/Filament/Boost guidance when relevant, native Filament or installed-package options before new plugins, qualified plugin sources when a real gap exists, and external/benchmark sources when policy, integration, or mature-system behavior is unclear. Do not present model preference alone as the basis for changing or accepting a slice.

Each primary plan must include a workflow/UI fit review. Compare the approved PRD and blueprint intent with the current implementation, native Filament v5 capability, installed packages, qualified plugin options, and focused custom code. Use the official Filament plugin directory and Awesome Filament catalog as discovery sources, not automatic authority. A plugin is recommended only when it solves a real slice gap and is safer or faster than native Filament after checking version compatibility, maintenance, license/security, dependency weight, routes/migrations/config, authorization, tests, upgrade/removal cost, and whether domain rules can stay in TALA services/actions/policies/models.

Within the activated session:

- `Primary proceed` continues the accepted current issue without widening scope.
- `Plan TAL-XX` prepares a primary-thread slice contract for user review only.
- `Orchestrate TAL-XX` explicitly authorizes one accountable task-specific worker after the slice plan is accepted.
- `Verify TAL-XX` triggers independent inspection of the worker result and live repository.
- `Cleanup TAL-XX` is limited to its accepted local-tracker update and bounded local Git commit.
- `Sync TAL-XX to Linear` is the only command that authorizes the primary to create or update the corresponding Linear issue and reconcile its sync state.

`Finish`, `close`, `cleanup`, `commit`, or `proceed` without the explicit Linear-sync instruction does not authorize a Linear mutation.

Worker status is never inferred. A worker must receive an explicit handoff containing the issue, scope, authorities, exclusions, verification, and handshake requirements.

## Source-of-Truth Order

Before planning or implementing a slice, read:

1. `00_Project_Documents/prd_modules/README.md`
2. Relevant PRD module files
3. `00_Project_Documents/ui_surface_blueprint.md`
4. `00_Project_Documents/architecture_specification.md`
5. Current migrations, models, policies, routes, Filament resources, and tests

Existing code is salvage inventory. It is useful evidence, but it does not override the approved PRD or architecture.

## Vertical Slice Workflow

Work should continue as vertical slices, not broad horizontal rewrites.

Each slice must:

1. Define the module, user role, trigger, inputs, records changed, outputs, UI surface, related modules, integration boundary, and explicit exclusions.
2. Review the PRD, UI blueprint, architecture, schema, existing code, routes, policies, and tests enough to draft the plan.
3. Compare current implementation, native Filament, installed packages, qualified plugin options, and focused custom code for the slice's workflow/UI needs.
4. Research unclear framework, plugin, UI, policy, mature-system, or external-integration behavior before implementation.
5. Decide whether the PRD and UI blueprint remain valid, need clarification, or conflict with the current system.
6. Present the slice contract, evidence checked, recommendation basis, workflow/UI fit review, likely files/surfaces, verification plan, worker boundary, human-only steps, and explicit exclusions to the user.
7. Wait for user acceptance before implementation or worker handoff.
8. When an accepted decision changes a flow, update every affected authority document and review dependent modules before implementation is finalized.
9. Implement only the accepted scope and keep business rules in Laravel services, actions, policies, and models rather than in Filament presentation classes or third-party plugins.
10. Require the worker to verify its own output before handoff, then require independent primary-thread inspection and proportionate verification.
11. Record accepted local work in the sync tracker as `Done locally; pending explicit Linear sync`, remove it from active planning, then create the bounded local Git commit.
12. Give the user a post-commit acceptance checklist with exact pages, actions, expected results, and likely failure signs.
13. Keep the task pending in the local tracker until the user explicitly says `Sync TAL-XX to Linear`.
14. After explicit Linear synchronization, move the tracker row to compact synced history.
15. Patch user-reported defects inside the current slice before starting the next slice.

If a slice is too large to verify properly, split it during primary planning before implementation.

## Foundation Acceptance Rule

TALA does not restart from zero. The existing schema, services, policies, Filament resources, pages, and tests are salvage inventory that must be accepted, corrected, or replaced one vertical slice at a time.

Before additional CP-SAT or PayMongo expansion:

1. Revalidate the school-information-system foundation in dependency order.
2. Confirm each slice's records, lifecycle behavior, authorization, UI presentation, and downstream consumers.
3. Retain working implementation when it satisfies the accepted contract.
4. Patch only proven gaps; do not rewrite a module solely to make it look newer or more customized.
5. Treat a broad inventory as a sequencing aid, not as authorization for a whole-system rewrite.

## Research Rules

Use research when the implementation choice is not already clear from the PRD and current code.

Use:

1. Laravel Boost and version-specific docs before Laravel, Filament, Livewire, Fortify, or framework code changes.
2. Context7, plugin documentation, MCPs, or official docs when library/plugin behavior is unclear.
3. Authoritative internet research for Philippine policy, institutional standards, external APIs, current integration contracts, or mature-system benchmarking.
4. Official or primary sources whenever possible.

Research may recommend a PRD clarification, but it must not expand MVP without a primary-thread decision.

## Filament and Plugin Decision Gate

The objective is the leanest maintainable implementation, not the largest plugin count and not custom code by default.

Use this order for each UI or behavior requirement:

1. Keep the current implementation when it is aligned, authorized, tested, and maintainable.
2. Use native Filament v5 Resources, Pages, Tables, Forms, Infolists, Actions, Filters, Widgets, notifications, and panel features when they provide the behavior cleanly.
3. Reuse an installed package or component when it already fits the accepted behavior better than writing custom code.
4. Evaluate a new third-party plugin only when it closes a real capability gap or materially reduces custom implementation and maintenance.
5. Build a focused custom component only when current code, native Filament, installed packages, and qualified plugins do not fit the requirement.

Use the [official Filament plugin directory](https://filamentphp.com/plugins) as the primary live catalog and [Awesome Filament](https://github.com/spekulatius/awesome-filament) as an additional discovery catalog. These are external sources, not repository files and not approval lists.

During a relevant slice, open the live catalogs, search for the specific capability gap, then verify candidate repositories, package metadata, release history, and documentation. Record the selected or rejected candidates and supporting links in the slice report. Do not maintain a copied global plugin inventory because it will become stale. If external research is unavailable, use compatible installed or native Filament capabilities and report the unresolved plugin question.

Before adding a plugin, record:

1. Filament 5, Livewire 4, Laravel 12, and PHP 8.2 compatibility.
2. Maintainer activity, release recency, issue health, license, and security posture.
3. Required migrations, services, JavaScript, queues, permissions, or public routes.
4. Whether it keeps authorization and domain rules inside TALA.
5. Test strategy, upgrade risk, and a practical removal or replacement path.

Do not add or replace a dependency without explicit primary-thread approval.

## Orchestration Rules

Use a separate Codex thread only when the user explicitly asks for orchestration, delegation, background work, or another thread.

For ordinary work, the primary thread may proceed directly.

For delegated work:

1. Keep each task owned by the primary plus one accountable task worker unless the primary explicitly approves more accountable workers.
2. The primary defines scope, source documents, exclusions, verification, and next boundary.
3. Workers must read `AGENTS.md` and relevant source docs before editing.
4. Workers must preserve unrelated worktree changes.
5. Workers must not commit, push, deploy, open PRs, sync external systems, or start the next issue unless explicitly scoped.
6. Workers must stop and give user instructions when external dashboards, credentials, approvals, or environment-specific setup are required.

The accountable task worker may use helper sub-agents when useful. It still owns coordination, verification, and one final handshake to the primary. The primary accepts the accountable worker's combined result, not scattered helper outputs.

When user action is required, the handoff must state what the user must do, why human action is required, current step-by-step instructions, the expected result or evidence, and how that evidence unlocks the next step.

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
9. Research and plugin decision, including supporting links when applicable

The primary must independently inspect and proportionately verify worker output before accepting it.

## Git, Database, and Verification Rules

1. Preserve user-owned and unrelated changes.
2. After primary acceptance, create the bounded local Git commit without requiring a separate per-commit prompt; this standing permission does not authorize a push.
3. Record the completed task in the local tracker as pending explicit Linear sync.
4. Do not create, update, comment on, or otherwise synchronize a Linear issue until the user explicitly says `Sync TAL-XX to Linear` or gives an equally explicit issue-specific Linear instruction.
5. Do not treat `finish`, `close`, `cleanup`, `commit`, or `proceed` as Linear authorization.
6. Do not push unless explicitly requested.
7. Before DB-backed tests, prove `APP_ENV=testing`, `DB_CONNECTION=mysql`, and `DB_DATABASE=test_tala_db`.
8. Do not run DB-backed verification against `tala_db` or `tala_test_codex`.
9. Run focused PHPUnit tests for changed behavior.
10. Run `vendor/bin/pint --dirty --format agent` after PHP changes.
11. Run focused PHPStan/Larastan when a slice changes typed PHP paths or tests.
12. Run `git diff --check` before handoff or commit.

## Product-Rule Ownership

Product behavior and MVP boundaries belong in the PRD modules, UI blueprint, and architecture specification. This protocol must not duplicate scheduling, payment, enrollment, grading, role-surface, or demo rules that can drift from those authorities.

Each task contract must reference the relevant authority files and sections. When product behavior changes, update the owning documents and affected dependent modules before finalizing implementation.

Canonical domain terms live in their owning PRD module. Do not recreate a standalone glossary that can drift from the product flow.
