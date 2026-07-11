# TALA Orchestrator Protocol

## 1. Purpose

This protocol controls how TALA MVP work is planned, delegated, verified, tracked, and committed. It stays compact and TALA-specific: generic planning, debugging, TDD, verification, review, and subagent technique belongs to installed skills and plugins, not here.

The goal is never a restart. Work in small vertical slices, keep aligned implementation, patch proven gaps, and keep the SIS foundation reliable before CP-SAT and payment hardening.

The `AGENTS.md` router carries the always-loaded summary (intake chain, non-negotiables, commands). This file is the single home for every detailed rule below. If the router and this file ever differ, this file wins and the router must be corrected.

## 2. Intake and authority

Read in this order before planning a slice:

1. `AGENTS.md` — runtime rules and the Laravel Boost block. If it is unavailable, stop before planning, delegating, or implementing.
2. This protocol — orchestration rules.
3. `TALA-Rescue-Next-Steps.md` — current issue, order, sub-slices.
4. `TALA-Local-Linear-Sync-Tracker.md` — issue numbering and sync state only.
5. `prd_modules/` — product behavior, MVP boundaries, records, flows, outputs, roles.
6. `ui_surface_blueprint.md` — UI, role, and surface mapping.
7. `architecture_specification.md` — system and integration boundaries.
8. `business-evidence/` — clarification only (current forms, terminology, document shape, realistic data). Exclude senior-high-only content unless it is proven relevant to college workflows.
9. Migrations, models, services, policies, routes, Filament resources and pages, and tests — salvage evidence, not authority.

Owners: Boost and official docs own framework use; PRD owns product; blueprint owns UI mapping; architecture owns integration boundaries; this protocol owns workflow; Next Steps owns order; tracker owns sync state. Accept existing code only when it is aligned. On any unresolved conflict, stop in the primary thread.

## 3. Planning sequence

Every slice plan follows these steps in order. Each named gate is defined once in Section 4. Do not begin research (steps 4-5) before the slice's domain shape is established (steps 2-3).

1. **Intake** — read the authorities above for the slice's domain.
2. **Ground-Truth Gate** — verify what exists and what the authority requires for every in-scope surface, then assign a verdict.
3. **Slice Clarity Gate** — fix the domain shape: office owner, manual step, TALA responsibility, feature category, and purposeful simplification.
4. **Benchmark Gate** — when triggered, run a bounded reality-check benchmark and record the result.
5. **Qualified-Reference Gate** — assess reference overlap at the minimum useful depth and decide the implementation source.
6. **Write the plan contract** (Section 5) and report it.
7. **User approval** — do not implement or delegate until the user accepts. The only exception is a small docs-only protocol fix the user explicitly requests.

Use Boost `search-docs`, Context7, plugin catalogs, and available MCPs during steps 4-5 to confirm that proposed patterns, packages, or integrations exist and are version-compatible; do not defer tool and skill use to implementation. Activate the installed skills that match the slice's domain so their conventions shape the plan.

Sources: use Laravel Boost first for the Laravel ecosystem, especially version-specific `search-docs`; Context7 for focused, version-specific library, plugin, SDK, or API docs when Boost is insufficient — treat it as technical context only, never product, benchmark, or reference authority; authoritative internet or primary sources for policy, integration contracts, or mature-SIS behavior, preferring local Philippine campus and SIS context when credibility is uncertain; and installed skills or plugins for generic process, which never override TALA authorities. Use official sources for missing, conflicting, security-critical, or contractual details. If deep research is not needed, state why — internal cleanup, native or framework implementation, or direct alignment to a recently accepted benchmark.

## 4. Gates

Each gate uses the same shape: **When**, **Do**, **Block**, and **Skip** where applicable.

### 4.1 Ground-Truth Gate

**When:** Before every plan, before proceeding (re-confirm the exact files about to change), and when orchestrating (carried in the handoff packet and re-confirmed in the worker handshake). The trigger "GTG" invokes it explicitly, but it always applies.

**Do:** Read each in-scope file in full — never a partial grep. For every model, table, resource, service, or column in scope, verify both sides with cited evidence: (1) what exists in the running system — `Schema::hasTable` on `test_tala_db`, `AdminPanelProvider` registration, the creating migration, and live references or passing tests; and (2) what the PRD, blueprint, or architecture requires. Then assign one verdict per surface:

- **Aligned** — exists and matches the authority. Keep it; cite it as accepted evidence.
- **Gap** — exists but the required behavior is missing or incomplete. Patch it as a focused addition; never rewrite what is aligned.
- **Superseded remnant** — exists, but a live replacement already satisfies the authority. Retire it, naming the replacement.
- **Required-but-unbuilt** — the authority requires it and it does not exist. Build it or defer it; never delete a required feature merely because it is unbuilt.
- **Conflict** — code and authority disagree, or the authority's own design is wrong or infeasible. Route it to the Authority Document Correction rule (Section 9); never silently patch code to a possibly-wrong authority, and never silently rewrite an authority to match code.

"Does not exist" alone never decides an action: the existence check chooses write-versus-delete, and the authority check decides whether the action is warranted. Cite the verdict and evidence per surface, not as a blanket "existing code accepted."

**Block:** Never trust an issue's or Next Steps' framing over this check. If reality differs from the framing, stop and re-surface to the user before acting.

### 4.2 Slice Clarity Gate

**When:** Every slice, before the plan is finalized.

**Do:** State all five, using PRD intent, current code, and manual/digital judgment:

- Office owner — who owns the decision in the real institution.
- Manual workflow step — what humans do outside TALA.
- TALA's responsibility — source record, generated read-only view, office-result record, integration input/output, or deferred.
- Feature category — one of the responsibilities above.
- Why current code or a bounded benchmark does not contradict the plan, and whether the behavior over-automates office judgment.

**Block:** If any item is missing or unclear, mark the slice unclear and do not implement. "PRD wording is readable" does not satisfy this gate.

### 4.3 Benchmark Gate

**When:** The first sub-slice of a new parent issue, or the first sub-slice touching any of: admissions or applicant data, document evidence, enrollment gates, finance, ledger, or payment, CP-SAT or scheduling, COR or official outputs, Student Hub or student-facing data, integrations or settings, security or privacy, audit, retention, or reporting.

**Do:** Run a bounded reality-check benchmark before finalizing the plan. Record a "Benchmark Result" section with: the domain checked; sources consulted (Boost docs, web search, business evidence, or a prior benchmark); findings (what was confirmed or contradicted); and PRD-alignment confirmation (does the PRD match real-world practice?). Business benchmarking validates workflow credibility; a qualified reference validates implementation fit; neither overrides TALA authorities.

**Block:** If the trigger applies and this section is absent or says "not needed" without a valid skip citation, the plan is incomplete — do not approve or implement.

**Skip:** A later sub-slice may reuse a recent accepted benchmark only when all of these hold: same workflow; no new role; no new source record; no new integration boundary; no new official output; no new exposed-data class; no new manual-office decision. Cite the prior benchmark by TAL-XX ID.

### 4.4 Qualified-Reference (Overlap) Gate

**When:** Every slice, after the Slice Clarity Gate establishes the domain shape. Default reference: the local Academico checkout at `D:\D SCHOOL\SYSTEMS\SIA-TALA-COGNITRES`; canonical remote: https://github.com/academico-sis/academico. Do not scan it routinely or broadly.

**Do:** Answer both questions, then record the disposition:

- **Business-logic overlap** — does this exact workflow (same records, rules, domain) exist in the reference? If yes, inspect at depth 2-3 for logic, service patterns, and data model. If no, record "no business-logic overlap."
- **Implementation-pattern overlap** — does the reference have a similar UI need (data shapes, roster tables, review queues, submission flows, Filament resource patterns, plugin usage) even if the rules differ? If yes, inspect at depth 1-2 for UI patterns, components, plugins, and architecture. If no, record depth 0 with a reason.

Depth levels: `0` skip (both answers "no"); `1` rendered UI (resource structure, table and form layout, actions); `2` relevant files (services, models, policies, migrations); `3` dependencies, tests, and data model (only when adoption or copying is plausible). Record the source (local or remote), depth used, surfaces examined, and disposition: keep TALA, adapt a pattern, copy bounded code with attribution, adopt a dependency, reject as incompatible, or reconcile an authority conflict.

**Block:** Do not skip to depth 0 until both questions are answered "no."

**Skip and reuse:** Check the local checkout first; use the canonical remote via the GitHub API at the same minimum depth only if the local checkout is unavailable or stale, or to settle freshness, license, dependency, or copy decisions. Reuse an accepted finding for related sub-slices unless the workflow, reference, or integration boundary changes. The primary gives workers exact reference paths and allowed patterns; workers do not repeat broad discovery.

### 4.5 Implementation-source order (Filament and plugins)

**When:** Choosing how to build any surface in an accepted plan.

**Do:** Prefer, in order: (1) keep current aligned, authorized, tested code; (2) native Filament v5 and Laravel features; (3) adapt a qualified-reference pattern when domain semantics fit; (4) reuse an installed package or component; (5) add a qualified plugin or dependency only for a real gap that reduces code or risk; (6) build focused custom code when none of the above fit. Plugin catalogs (https://filamentphp.com/plugins, https://github.com/spekulatius/awesome-filament) are discovery aids, not authority.

A dependency named in an accepted plan needs no second approval, but the plan must document: compatibility with Laravel 12, Filament 5, Livewire 4, and PHP 8.2; maintenance; license and security; migrations, routes, and config; authorization; tests; upgrade and removal cost; and that TALA domain rules stay in services, actions, policies, and models.

**Block:** Do not add a dependency that is not documented in the accepted plan.

### 4.6 Simplification and deferral

**When:** Every proposed feature or surface in a slice plan.

**Do:** Simplification means purposeful scope, not deletion or presence for its own sake. Each feature must support at least one of: a clear school workflow (naming the office owner, manual decision, and TALA record type); an inter-department handoff; a CP-SAT or payment integration dependency; usability; audit and control; or maintainability. Prefer hybrid manual/digital workflows that stay useful without encoding unnecessary institutional complexity. Do not preserve a reduced feature merely because richer behavior was assumed expensive — if a compatible native, packaged, or qualified-reference option makes it bounded, use it; domain fit and purposeful scope still control.

Route every deferral to exactly one destination, in this order: backed by an authority document → an existing Next Steps issue, or a new issue if none fits (post-MVP enhancements get their own "future enhancement, post-MVP" issue); not in any authority → discard, stated explicitly; a disagreement with PRD scope → the Authority Document Correction rule. Next Steps rows are the single source of truth for where a deferral lives; completed-work detail belongs in the git commit message, not the roadmap or tracker.

**Block:** Do not defer without a recorded destination issue or an explicit stated discard. Do not close a parent issue with unrouted deferrals. If a feature supports none of the criteria above, defer it or challenge its MVP purpose in the primary thread before handoff.

## 5. Primary orchestrator and the plan contract

Activation prompts include `Act as the TALA primary orchestrator`, `Resume TALA orchestration`, `Plan the next TALA task`, or equivalent explicit intent.

Before any implementation, delegation, tracker change, or commit, the primary reports:

1. Git and dirty state.
2. Current issue and next boundary.
3. Authority files checked, and any conflicts or stale statements.
4. Primary-versus-worker decision.
5. Proposed slice plan and exclusions.

After compaction, interruption, rejected worker output, an unclear handoff, or stale state, run a resume checkpoint: restate the issue, accepted plan, authority evidence, exclusions, dirty state, verification state, and next action.

### Memory Freshness Check

**When:** Primary activation, resumed or compacted work, and Cleanup.

**Do:** Read Git state, Next Steps, and the tracker first. List memories, then read only relevant Serena and available agent-native entries. Through supported memory interfaces, correct stale durable guidance and delete expired temporary carry-ins; during Cleanup, route useful carry-ins to Git first. Keep current boundaries, commits, statuses, and counters only in Git authorities. Report `Memory: current`, `Memory: corrected - <items>`, or `Memory: unavailable - <reason>`.

**Block:** Never scan or rewrite all memories by default, edit proprietary storage directly, duplicate Git state, or claim an unconfirmed update.

Commands:

- `Primary proceed` — continue the accepted current issue only.
- `Plan TAL-XX` — draft a plan only.
- `Orchestrate TAL-XX` — after an accepted plan, authorize one accountable worker.
- `Verify TAL-XX` — independently inspect the worker result and the live repo.
- `Cleanup TAL-XX` — local tracker update plus one bounded local commit only.
- `Sync TAL-XX to Linear` — the only command that authorizes Linear mutation. `finish`, `close`, `cleanup`, `commit`, or `proceed` never authorize a Linear sync.

### Slice contract

A slice must be small enough to inspect, implement, test, and verify in one pass. If it is broad, split it during planning and record a compact sub-slice map in Next Steps:

1. The primary identifies during planning that the issue is too broad.
2. The primary proposes the sub-slice map in its report (IDs, one-line purposes, dependency order, and next boundary for each).
3. The user approves the plan, including the split.
4. The primary immediately records the approved map in Next Steps. This is planning documentation and needs no separate approval.
5. The primary plans the first sub-slice under the normal gates.
6. On completion, trim each finished sub-slice's map row to a one-line status stub (its delivered detail lives in the commit message, not here). When every sub-slice of a parent is complete, remove the parent and its map from Next Steps; the record persists in the commit messages and, once synced, in Linear.

If an approved sub-slice is later found mid-implementation to need further splitting, stop, propose the new split in the primary thread, get approval, record the updated map, then continue.

Each plan must define: role and user goal; trigger and action; inputs; changed records; outputs; UI surface and whether it is read-only or editable; related modules and downstream consumers; integration boundary; purposeful-simplification decision; manual/digital boundary and gate results; benchmark result when the Benchmark Gate triggers (otherwise the skip condition and prior TAL-XX ID); qualified-reference source, depth, and disposition (or why it is not applicable); likely files and surfaces; verification plan; human-only steps; and explicit exclusions.

Every retained UI surface needs a plain-purpose statement: who uses it, what decision or action it supports, why it belongs in the MVP, and why it is read-only or editable. If the purpose is unclear, rename, hide, defer, redesign, or tie it to its owning module before acceptance. Classify every feature before implementation as one of: source record, generated read-only view, manual-office result record, integration input/output, or deferred. If it fits no category cleanly, challenge its MVP purpose before handoff.

State each human-only step with what it is, why it is manual, the exact steps, the expected evidence, and what it unlocks.

## 6. Delegation and workers

Use a separate worker only when the user explicitly asks for orchestration, delegation, or background work, or when an accepted plan authorizes it. Default to one primary and one accountable worker.

**Multi-worker (max 3):** permitted only when all of these hold — (1) the plan identifies parallelizable sub-tasks with zero file overlap; (2) each worker's file scope is enumerated in its packet and no worker edits another's file; (3) at most one worker runs PHPUnit or PHPStan at a time (shared `test_tala_db`); (4) no worker touches a migration, seeder, or shared service another depends on; (5) the user approves the parallel plan. Safe parallel patterns: multiple read-only audit workers; parallel research or benchmark workers; non-overlapping implementers with staggered test runs. If any file, model, service, or test-DB access overlaps, fall back to sequential single-worker execution. The primary merges all handshakes into one acceptance.

**Handoff packet:** before starting a worker, assemble a compact packet with the issue; accepted checklist; authority files; allowed changes; approved reference paths and patterns; exclusions; verification; DB-proof requirement; handshake format; and the Ground-Truth Gate classification (verified existence plus authority verdict per surface, with cited evidence and a stop-rule if reality differs). Deliver it via any available mechanism (temp file, inline sub-agent prompt, shared context). Reference existing docs, commits, and diffs by path instead of copying them. Redact secrets and keep context minimal.

**Workers must:** read the packet first; read `AGENTS.md` and the relevant authorities before editing; preserve unrelated worktree changes; execute the accepted checklist and not act as a second primary unless scoped for research or planning; stop as `BLOCKED` if the checklist, issue, or scope is unclear; halt as `BLOCKED` and return to the primary if any surface's ground truth (existence, registration, or authority alignment) differs from the packet; stop for the user when dashboards, credentials, approvals, or environment setup are needed; not commit, push, deploy, open PRs, sync external systems, or start the next issue unless explicitly scoped; and merge any helper work into one final handshake.

## 7. Worker handshake and acceptance

The worker's final report must include:

1. Status: `PASS`, `PARTIAL`, or `FAIL`.
2. Changed files and the exact work.
3. Verification commands and results.
4. DB-target proof for DB-backed checks.
5. Untouched exclusions.
6. Caveats and blockers.
7. Research, reference, and dependency decisions, with links or paths.
8. Ground-truth re-check for the surfaces touched — evidence that existence and authority alignment were re-confirmed, not passing tests alone.
9. Next boundary.

Primary acceptance requires independent inspection and proportionate verification; passing tests alone is not acceptance.

Large or high-risk deletion slices — retiring a cluster of files, models, tables, or permissions — additionally require a **cold audit** before cleanup. A fresh, read-only reviewer with no prior context (a separate subagent or a clearly reset review pass) independently re-checks that every deletion is safe: no live caller, no required-but-unbuilt authority need, and a named live replacement wherever the concept must survive. The reviewer returns its own `JUSTIFIED` or `NOT JUSTIFIED` verdict with cited evidence; a `NOT JUSTIFIED` finding blocks cleanup until reconciled. This gate is proportionate: additive builds and small, behavior-neutral retires (for example, an unregistered, tableless, untested surface whose only edge is a guarded no-op) may skip it, stating the reason in the acceptance report.

Before cleanup or commit, the primary reports: authority alignment, accepted scope, retained-surface purpose, exclusions, verification, dirty state, and next boundary.

## 8. Git, database, verification, and sync

- Preserve user-owned and unrelated changes.
- DB-backed checks require proof of target: `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=test_tala_db` — never `tala_db` or `tala_test_codex`.
- Run focused PHPUnit for changed behavior.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run focused PHPStan or Larastan when typed PHP paths or tests change.
- Run `git diff --check` before any handoff or commit.
- After primary acceptance, make one bounded local Git commit whose message is the canonical record of what the slice delivered and why: scope, key changes, verification evidence, and any routed deferrals.
- Keep delivered detail in the commit message, not the roadmap or tracker. Next Steps holds scope, order, and the sub-slice map; the tracker holds ID, status, and title/domain; the commit message — and, once synced, Linear — holds the detail. Never paste verification or evidence blocks into Next Steps or the tracker.
- Then record a lean tracker row (`Done locally; pending explicit Linear sync`, title/domain only), and either remove the completed active-planning entry or trim its sub-slice map row to a one-line status stub.
- A local commit never authorizes push, deploy, PR, or Linear mutation.
- Keep the tracker row pending until the user says `Sync TAL-XX to Linear`; after sync, move it to compact synced history.
- Give the user a post-commit checklist: pages, actions, expected results, and failure signs.
- Patch current-slice defects before starting the next slice.

## 9. Product-rule ownership

Product behavior belongs in the PRD modules, UI blueprint, and architecture specification — not this protocol. Task contracts must cite the owning files. Do not create duplicate glossaries or module rules here.

### Authority Document Correction (during planning)

If the primary finds an error, contradiction, gap, or stale statement in any authority document during intake or planning:

1. Flag it in the primary report under authorities checked and conflicts.
2. Propose the correction with evidence (benchmark result, code discovery, authority conflict, or plugin/pattern research).
3. Include the document patch in the slice plan, approved alongside the implementation — unless it changes scope for multiple future slices, in which case it becomes a standalone docs-only micro-task before the implementation slice.
4. Apply the document update before finalizing implementation; the commit includes both the document fix and the dependent implementation.

Trivial fixes (typos, obviously wrong terms) need no separate justification. Substantive behavior changes always require explicit user approval.

### Authority Document Stability (after verification)

Authority documents describe the desired contract, so failed or partial verification does not change them. If a worker returns PARTIAL or the primary finds failures, fix the code or re-delegate — the target is still correct. Change an authority document only when implementation reveals a design flaw, including a blueprint surface that proves infeasible with the chosen framework pattern: return to planning, flag the issue, propose the correction, get user approval, then continue.
