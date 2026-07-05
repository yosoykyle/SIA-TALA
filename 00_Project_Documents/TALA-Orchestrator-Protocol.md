# TALA Orchestrator Protocol

## Purpose

This file controls how TALA MVP work is planned, delegated, verified, tracked, and committed. It should stay compact and TALA-specific. Generic planning, debugging, TDD, verification, review, and subagent workflow details belong to installed skills/plugins when available, not copied here.

The goal is not a restart. Continue through small vertical slices, preserve aligned implementation, patch proven gaps, and keep the SIS foundation reliable before CP-SAT and payment hardening.

## Authority and Intake

Read in this order before a TALA slice:

1. `AGENTS.md` — runtime rules and Laravel Boost block.
2. This protocol — orchestration rules.
3. `TALA-Rescue-Next-Steps.md` — current issue, sequence, sub-slices.
4. `TALA-Local-Linear-Sync-Tracker.md` — issue numbering and sync state only.
5. `prd_modules/` — product behavior, MVP boundaries, records, flows, outputs, roles.
6. `ui_surface_blueprint.md` — UI/role/surface mapping.
7. `architecture_specification.md` — system and integration boundaries.
8. `business-evidence/` — clarification only: current forms, terminology, document shape, realistic data. Exclude SHS-only content unless proven relevant to college workflows.
9. Migrations, models, services, policies, routes, Filament resources/pages, and tests — salvage evidence.

Ownership: Boost/official docs control framework use; PRD controls product; blueprint controls UI mapping; architecture controls integration boundaries; protocol controls workflow; Next Steps controls order; tracker controls sync status. Existing code is accepted only when aligned. On unresolved conflict, stop in the primary thread.

## Capability and Research Policy

Use available capabilities instead of duplicating their full instructions:

- Laravel ecosystem/framework work: Laravel Boost first, especially version-specific `search-docs`.
- Library/plugin/current-doc gaps: Context7, official docs, or relevant MCPs/connectors.
- Policy, integration contracts, or mature SIS behavior: authoritative internet/primary sources; prefer local Philippine campus/SIS context when policy credibility or workflow familiarity is uncertain.
- Generic planning/debugging/TDD/verification/review/subagent workflows: relevant installed skills/plugins such as Superpowers when available. These guide process only; they do not override TALA authorities.

Every slice runs a benchmark/implementation-fit gate:

1. PRD/blueprint intent.
2. Current implementation.
3. Native Filament v5/Laravel pattern.
4. Installed packages.
5. Qualified plugin option.
6. Focused custom code.
7. Whether mature-system or internet benchmarking is needed.

If deep research is not needed, state why the PRD/current implementation/native path is sufficient. If research is used, record material links and why the recommendation is MVP-fit.

## Primary Orchestrator

Activation prompts include `Act as the TALA primary orchestrator`, `Resume TALA orchestration`, `Plan the next TALA task`, or equivalent explicit orchestration intent.

Before implementation, delegation, tracker movement, or commit, the primary reports:

1. Git/dirty state.
2. Current issue and next boundary.
3. Authority files checked and conflicts/stale statements.
4. Primary-vs-worker decision.
5. Proposed slice plan and exclusions.

Resume checkpoint after compaction, interruption, rejected worker output, unclear handoff, or stale state: restate issue, accepted plan, authority evidence, exclusions, dirty state, verification state, and next action.

Commands:

- `Primary proceed` — continue accepted current issue only.
- `Plan TAL-XX` — draft plan only.
- `Orchestrate TAL-XX` — after accepted plan, authorize one accountable worker.
- `Verify TAL-XX` — independently inspect worker result and live repo.
- `Cleanup TAL-XX` — local tracker + bounded local commit only.
- `Sync TAL-XX to Linear` — only command that authorizes Linear mutation.

`finish`, `close`, `cleanup`, `commit`, or `proceed` without explicit `Sync TAL-XX to Linear` does not authorize Linear sync.

## Slice Contract

A slice must be small enough to inspect, implement, test, and verify. If broad, split it during primary planning and record a compact sub-slice map in Next Steps.

Each primary plan must define:

- Role and user goal.
- Trigger/action.
- Inputs.
- Changed records.
- Outputs.
- UI surface and whether it is read-only/editable.
- Related modules/downstream consumers.
- Integration boundary.
- Purposeful-simplification decision.
- Benchmark/implementation-fit gate result.
- Likely files/surfaces.
- Verification plan.
- Human-only steps.
- Explicit exclusions.

Every retained UI surface needs a plain-purpose statement: who uses it, what decision/action it supports, why it belongs in MVP, and why it is read-only or editable. If the purpose is unclear, rename, hide, defer, redesign, or tie it to the owning module before acceptance.

Every proposed feature must be classified before implementation as one of: source record, generated read-only view, manual-office result record, integration input/output, or deferred. If it does not fit one category cleanly, challenge its MVP purpose in the primary thread before handoff.

Primary planning is evidence-backed. Do not implement or delegate until the user accepts the plan, except for a small docs-only protocol correction explicitly requested by the user.

## Simplification Rule

Simplification means purposeful scope, not deletion or presence for its own sake. Retain/add/revise/defer only when it supports:

- Clear school workflow.
- Inter-department handoff.
- CP-SAT or payment integration dependency.
- Usability.
- Audit/control.
- Maintainability.

Prefer hybrid manual/digital workflows when they keep the system useful without encoding unnecessary institutional complexity. Benchmarks guide credibility and implementation shape; they do not authorize broad enterprise scope.

## Filament and Plugin Gate

Default order:

1. Keep current aligned, authorized, tested implementation.
2. Use native Filament v5/Laravel features.
3. Reuse installed package/component.
4. Evaluate a new plugin only for a real gap that reduces code or risk.
5. Build focused custom code when the above do not fit.

Catalogs: [Filament plugins](https://filamentphp.com/plugins), [Awesome Filament](https://github.com/spekulatius/awesome-filament). They are discovery aids, not authority.

Before adding a dependency, get explicit approval and document compatibility with Laravel 12, Filament 5, Livewire 4, PHP 8.2; maintainer activity; license/security; migrations/routes/config; authorization; tests; upgrade/removal cost; and whether TALA domain rules remain in services/actions/policies/models.

## Delegation and Worker Rules

Use a separate worker only when the user explicitly asks for orchestration/delegation/background work or when an accepted plan authorizes it. One primary + one accountable worker by default.

Worker handoff includes only: issue, accepted checklist, authority files, allowed changes, exclusions, verification, DB proof requirement, and handshake format. Use minimal context by default.

Workers must:

- Read `AGENTS.md` and relevant authorities before editing.
- Preserve unrelated worktree changes.
- Execute the accepted checklist; not act as another primary unless scoped for research/planning.
- Stop as `BLOCKED` if checklist, current issue, or scope is unclear.
- Stop for user instructions when dashboards, credentials, approvals, or environment setup are required.
- Not commit, push, deploy, open PRs, sync external systems, or start next issue unless explicitly scoped.
- Merge any helper-agent work into one final handshake.

## Worker Handshake and Acceptance

Worker final report must include:

1. `Status: PASS`, `PARTIAL`, or `FAIL`.
2. Changed files and exact work.
3. Verification commands/results.
4. DB target proof for DB-backed checks.
5. Untouched exclusions.
6. Caveats/blockers.
7. Research/plugin decision and links when applicable.
8. Next boundary.

Primary acceptance requires independent inspection and proportionate verification. Passing tests alone is not acceptance. Before cleanup/commit, report: authority alignment, accepted scope, retained-surface purpose, exclusions, verification, dirty state, and next boundary.

## Git, Database, Verification, and Sync

- Preserve user-owned/unrelated changes.
- DB-backed checks require proof: `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=test_tala_db`; never `tala_db` or `tala_test_codex`.
- Run focused PHPUnit for changed behavior.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run focused PHPStan/Larastan when typed PHP paths/tests change.
- Run `git diff --check` before handoff or commit.
- After primary acceptance, record local work in tracker as `Done locally; pending explicit Linear sync`, remove completed active planning entry or advance the parent sub-slice map, then create a bounded local Git commit.
- Local commit permission does not authorize push, deploy, PR, or Linear mutation.
- Keep tracker row pending until user explicitly says `Sync TAL-XX to Linear`; after sync, move to compact synced history.
- Give the user a post-commit checklist with pages/actions/expected results/failure signs.
- Patch current-slice defects before starting the next slice.

## Product-Rule Ownership

Product behavior belongs in PRD modules, UI blueprint, and architecture specification, not this protocol. Task contracts must cite relevant owning files. If product behavior changes, update owning documents and dependent modules before finalizing implementation. Do not create duplicate glossaries or module rules here.
