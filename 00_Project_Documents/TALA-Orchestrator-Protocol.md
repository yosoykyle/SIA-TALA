# TALA Orchestrator Protocol

## Purpose

This file controls how TALA MVP work is planned, delegated, verified, tracked, and committed. It should stay compact and TALA-specific. Generic planning, debugging, TDD, verification, review, and subagent workflow details belong to installed skills/plugins when available, not copied here.

The goal is not a restart. Continue through small vertical slices, preserve aligned implementation, patch proven gaps, and keep the SIS foundation reliable before CP-SAT and payment hardening.

## Authority and Intake

Read in this order before a TALA slice:

1. `AGENTS.md` — runtime rules and Laravel Boost block. Read it from the project root. If it is not available, stop before planning, delegation, or implementation.
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
- Library/plugin/SDK/API work: use Context7 for focused, current, version-specific documentation and code examples when Boost is insufficient. Resolve the exact library/version, ask a specific question, and reuse the result. Context7 may retrieve repository-indexed documentation, but it is technical context only; use official sources for missing, conflicting, security-critical, or contractual details.
- Policy, integration contracts, or mature SIS behavior: authoritative internet/primary sources; prefer local Philippine campus/SIS context when policy credibility or workflow familiarity is uncertain.
- Generic planning/debugging/TDD/verification/review/subagent workflows: relevant installed skills/plugins such as Superpowers when available. These guide process only; they do not override TALA authorities.

Timing: use Boost `search-docs`, plugin catalogs, and available MCPs during planning (gate Phase B, items 6–8) to verify that proposed framework patterns, plugins, or integrations exist and are version-compatible before the plan is finalized. During planning, identify which installed skills apply to the slice's domain and activate them so their conventions inform the plan. Do not defer all tool and skill usage to implementation if it would leave the plan unverified.

Every slice runs a benchmark/implementation-fit gate:

The gate runs in two phases:

- Phase A (scope establishment): items 1–3 define the slice's domain shape — what records, what workflow category, which office owns the decision, and what TALA's responsibility is. This phase uses only PRD, current code, and manual/digital judgment.
- Phase B (research and tool assessment): items 4–9 use the domain shape from Phase A to determine whether external research, reference inspection, or additional tooling is needed. Do not execute Phase B without a clear domain shape from Phase A.

1. PRD/blueprint intent.
2. Current implementation.
3. Manual/digital reality check: office owner, manual office step, TALA-owned record/view/integration responsibility, and whether the proposed behavior over-automates office judgment.
4. Whether a mature-system benchmark is required or reusable.
5. Whether a qualified reference implementation overlaps.
6. Native Filament v5/Laravel pattern.
7. Installed packages.
8. Qualified plugin/dependency option.
9. Focused custom code.

### Slice Clarity Gate

CONDITION: Every slice, before the plan is finalized.

REQUIRED: The plan MUST identify all of the following:
- Office owner (who owns the decision in the real institution).
- Manual workflow step (what humans do outside TALA).
- TALA's exact responsibility (source record, generated view, office-result record, integration I/O, or deferred).
- Proposed feature category (one of the above).
- Why current code or a bounded benchmark does not contradict the plan.

BLOCK: If any item above is missing or unclear → mark the slice unclear → do not implement.

NOTE: "PRD wording is readable" does not satisfy this gate. Readable wording without the five items above is still unclear.

### Benchmark Gate

TRIGGER: First sub-slice of a new parent issue, OR first sub-slice touching any of:
admissions/applicant data, document evidence, enrollment gates, finance/ledger/payment,
CP-SAT/scheduling, COR/official outputs, Student Hub/student-facing data,
integrations/settings, security/privacy, audit, retention, or reporting.

REQUIRED: Run a bounded reality-check benchmark BEFORE finalizing the plan.

OUTPUT: The primary report MUST include a "Benchmark Result" section containing:
- Domain checked.
- Sources consulted (Boost docs, web search, business evidence, or prior benchmark).
- Findings (what the research confirmed or contradicted).
- PRD alignment confirmation (does the PRD match real-world practice?).

BLOCK: If the trigger applies and this section is absent or states "not needed" without
citing a valid skip condition → plan is incomplete → do not approve or implement.

SKIP: A later sub-slice may reuse a recent accepted benchmark ONLY when ALL of these hold:
- Same workflow as the benchmarked slice.
- No new role introduced.
- No new source record introduced.
- No new integration boundary introduced.
- No new official output introduced.
- No new exposed data class introduced.
- No new manual-office decision introduced.
Cite the prior accepted benchmark by TAL-XX ID.

### Research Recording

IF deep research is not needed → state why: internal cleanup, framework/native implementation, or direct code alignment to a recently accepted benchmarked contract.

IF research is used → record material links and state why the recommendation is MVP-fit.

IF the PRD appears complete but manual/digital boundary or benchmark fit has not been checked → mark the slice unclear → stop before implementation.

Business benchmarking validates workflow credibility; a qualified reference validates implementation fit. Neither overrides TALA authorities.

### Qualified Reference Implementation

Use the local Academico checkout at `D:\D SCHOOL\SYSTEMS\SIA-TALA-COGNITRES` as the default reference when the current slice has meaningful overlap; canonical remote: [academico-sis/academico](https://github.com/academico-sis/academico). Do not scan it routinely or broadly.

Overlap assessment order:

1. Complete gate Phase A (items 1–3) to establish the slice's domain shape.
2. Ask: "Does this workflow category, record type, or office/role pattern plausibly exist in the reference?" A structural check (directory listing, model file names, or a focused GitHub API query) is sufficient to confirm or rule out overlap without deep inspection.
3. If no plausible overlap: record depth 0 with the reason (e.g., "domain mismatch: TALA 9-category enrollment gate vs. language-school course enrollment") and proceed.
4. If plausible overlap: proceed to minimum-depth inspection per the rules below.
5. If the local checkout is unavailable or stale, use the canonical remote via GitHub API at the same minimum depth.

Use the minimum depth: `0` skip, `1` rendered UI, `2` directly relevant files, `3` dependencies/tests/data model only when adoption or copying is plausible. Check the remote only for freshness, license, dependency, or copy decisions. Record the source, depth, examined surfaces, and one disposition: keep TALA, adapt a pattern, copy bounded code with attribution, adopt a dependency, reject as incompatible, or reconcile a PRD/blueprint conflict.

Reuse an accepted finding for related sub-slices unless the workflow, reference, or integration boundary changes. The primary gives workers exact reference paths and allowed patterns; workers do not repeat broad discovery.

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

Sub-slice map recording sequence:

1. Primary identifies during planning that the issue is too broad for one slice.
2. Primary proposes the sub-slice map in its report (IDs, one-line purposes, dependency order, next boundary for each).
3. User approves the plan (including the split).
4. Primary immediately records the approved sub-slice map in Next Steps. This is planning documentation, not implementation — it does not require a separate approval step.
5. Primary then plans the first sub-slice per normal gate/benchmark rules.

If the primary realizes mid-implementation that an approved sub-slice itself needs further splitting: stop implementation, report the new split proposal in the primary thread, get user approval, record the updated map, then continue.

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
- Manual/digital boundary and benchmark/implementation-fit gate result.
- Benchmark result (REQUIRED when the Benchmark Gate trigger applies; cite sources, findings, and PRD alignment — or state the skip condition and prior TAL-XX ID).
- Qualified-reference source, depth, and disposition, or why it is not applicable.
- Likely files/surfaces.
- Verification plan.
- Human-only steps.
- Explicit exclusions.

Every retained UI surface needs a plain-purpose statement: who uses it, what decision/action it supports, why it belongs in MVP, and why it is read-only or editable. If the purpose is unclear, rename, hide, defer, redesign, or tie it to the owning module before acceptance.

Every proposed feature must be classified before implementation as one of: source record, generated read-only view, manual-office result record, integration input/output, or deferred. If it does not fit one category cleanly, challenge its MVP purpose in the primary thread before handoff.

Primary planning is evidence-backed. Do not implement or delegate until the user accepts the plan, except for a small docs-only protocol correction explicitly requested by the user.

## Simplification Rule

Simplification means purposeful scope, not deletion or presence for its own sake.

### Retain/Add/Revise/Defer Decision

CONDITION: Every proposed feature or surface in a slice plan.

REQUIRED: The feature MUST support at least one of:
- Clear school workflow (names office owner, manual decision, and TALA record type).
- Inter-department handoff.
- CP-SAT or payment integration dependency.
- Usability.
- Audit/control.
- Maintainability.

BLOCK: If a feature does not clearly support at least one item above → defer it or challenge its MVP purpose in the primary thread before handoff.

### Hybrid Manual/Digital Preference

Prefer hybrid manual/digital workflows when they keep the system useful without encoding unnecessary institutional complexity. Benchmarks guide credibility and implementation shape; they do not authorize broad enterprise scope.

### Reduced-Feature Override

DO NOT preserve a reduced feature solely because richer behavior was assumed expensive — IF a compatible native, packaged, or qualified-reference implementation makes it bounded. Domain fit and purposeful scope still control.

## Filament and Plugin Gate

Default order:

1. Keep current aligned, authorized, tested implementation.
2. Use native Filament v5/Laravel features.
3. Adapt a qualified-reference pattern when domain semantics fit.
4. Reuse an installed package/component.
5. Add a qualified plugin/dependency only for a real gap that reduces code or risk.
6. Build focused custom code when the above do not fit.

Catalogs: [Filament plugins](https://filamentphp.com/plugins), [Awesome Filament](https://github.com/spekulatius/awesome-filament). They are discovery aids, not authority.

A dependency included in an accepted slice plan needs no second approval. The plan must document compatibility with Laravel 12, Filament 5, Livewire 4, PHP 8.2; maintenance; license/security; migrations/routes/config; authorization; tests; upgrade/removal cost; and whether TALA domain rules remain in services/actions/policies/models.

## Delegation and Worker Rules

Use a separate worker only when the user explicitly asks for orchestration/delegation/background work or when an accepted plan authorizes it. One primary + one accountable worker by default.

Before starting a worker, the primary writes a compact handoff packet to the OS temp directory and points the worker to that absolute path. The packet includes only: issue, accepted checklist, authority files, allowed changes, approved reference paths/patterns, exclusions, verification, DB proof requirement, and handshake format. Reference existing docs/commits/diffs by path instead of duplicating them. Redact secrets and use minimal context by default.

Workers must:

- Read the handoff packet before acting.
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
7. Research/reference/dependency decision and links or paths when applicable.
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

Product behavior belongs in PRD modules, UI blueprint, and architecture specification, not this protocol. Task contracts must cite relevant owning files. Do not create duplicate glossaries or module rules here.

### Authority Document Correction During Planning

If the primary identifies an error, contradiction, gap, or stale statement in any authority document (PRD modules, UI blueprint, or architecture specification) during intake or planning:

1. Flag it in the Phase 2 primary report under "contradictions/gaps."
2. Propose the correction or resolution with evidence (benchmark result, code discovery, authority conflict, plugin/pattern research).
3. Include the authority document patch as part of the slice plan. It is approved alongside the implementation plan, not as a separate task — unless the issue changes scope for multiple future slices, in which case it becomes a standalone docs-only micro-task before the implementation slice.
4. Apply the authority document update before finalizing implementation. The commit includes both the document fix and the implementation that depends on it.

Trivial fixes (typos, obviously wrong terms) may be included without separate justification. Substantive behavior changes always require explicit user approval.

### Authority Document Stability After Verification

Authority documents describe desired behavior (the contract). Failed or partial verification does not trigger authority document changes.

- Worker delivers PARTIAL or primary verification finds failures: fix the code or re-delegate. The documents stay unchanged because the target is still correct.
- Only change an authority document when implementation reveals a design flaw — not when the code fails to meet a correct specification. This includes blueprint surfaces that are discovered to be infeasible with the chosen framework pattern.
- If a design flaw is discovered during verification: loop back to Phase 2, flag the issue, propose correction, get user approval, then continue.
