# TALA Orchestrator Protocol

## 1. Purpose

This protocol controls how TALA MVP work is planned, delegated, verified, tracked, and committed. Its authority, database, preservation, verification, and external-mutation rails apply to all TALA work. Its full orchestrated-slice lifecycle applies only when the user invokes an orchestration command or continues an active approved contract. Outside Plan mode, a clear direct request to implement, fix, review, or update authorizes that bounded local work without manufacturing a second approval or temporary governance-file churn; material human gates below still apply.

It stays compact and TALA-specific: generic planning, debugging, TDD, verification, review, and subagent technique belongs to installed skills and plugins, not here.

The goal is never a restart. Work in small vertical slices, keep aligned implementation, patch proven gaps, and keep the SIS foundation reliable before CP-SAT and payment hardening.

The `AGENTS.md` router carries the always-loaded summary (intake chain, non-negotiables, commands). This file is the single home for every detailed rule below. If the router and this file ever differ, this file wins and the router must be corrected.

Selected efficiency rules in this protocol are informed by the official Codex guidance to keep repository instructions practical, plan complex work, preserve explicit constraints, and verify through relevant checks plus diff review: [Codex best practices](https://learn.chatgpt.com/guides/best-practices.md), [prompting](https://learn.chatgpt.com/docs/prompting.md), and [long-running work](https://learn.chatgpt.com/docs/long-running-work.md). TALA's approval, tracking, database, and product-authority gates are project-specific; these sources do not prescribe this lifecycle.

## 2. Intake and authority

For an orchestrated slice, read in this order before planning:

1. `AGENTS.md` — runtime rules and the Laravel Boost block. If it is unavailable, stop before planning, delegating, or implementing.
2. This protocol — orchestration rules.
3. `TALA-Rescue-Next-Steps.md` — current issue, order, sub-slices, and the one active approved plan contract.
4. `TALA-Local-Linear-Sync-Tracker.md` — issue numbering and sync state only.
5. `prd_modules/` — product behavior, MVP boundaries, records, flows, outputs, roles.
6. `ui_surface_blueprint.md` — UI, role, and surface mapping.
7. `architecture_specification.md` — system and integration boundaries.
8. `business-evidence/` — clarification only (current forms, terminology, document shape, realistic data). Exclude senior-high-only content unless it is proven relevant to college workflows.
9. Migrations, models, services, policies, routes, Filament resources and pages, and tests — salvage evidence, not authority.

For clear direct work, always read items 1-2, then the minimum relevant product, UI, architecture, and implementation authorities. Read Next Steps and the tracker only when issue order, an active contract, status, Cleanup, or Linear sync is in scope. Do not turn a narrow request into a broad authority scan.

Owners: Boost and official docs own framework use; PRD owns product; blueprint owns UI mapping; architecture owns integration boundaries; this protocol owns workflow; Next Steps owns order and the active approved contract; tracker owns sync state. Accept existing code only when it is aligned. On any unresolved conflict, stop in the primary thread.

## 3. Planning sequence

Every orchestrated or separately requested slice plan follows these steps in order. Direct implementation requests use the applicable gates proportionately and proceed unless a material human decision remains. Each named gate is defined once in Section 4. Do not begin broad benchmark or reference research before the slice's domain shape is established; focused official technical documentation or evidence needed to establish that shape may be consulted earlier.

1. **Intake** — read the authorities above for the slice's domain.
2. **Applicable Ground-Truth Gate** — establish what exists and what the authority requires at the depth and form Section 4.1 requires.
3. **Slice Clarity Gate** — when triggered, fix the domain shape: office owner, manual step, TALA responsibility, feature category, and purposeful simplification.
4. **Benchmark Gate** — when triggered, run a bounded reality-check benchmark and record the result.
5. **Qualified-Reference Gate** — when triggered, assess reference overlap at the minimum useful depth and decide the implementation source.
6. **Prepare the plan contract when Section 5 requires durable state** — include its complete proposed contents in the final plan, but persist it only on the first authorized execution turn.
7. **User approval when needed** — a `Plan TAL-XX` request remains read-only until accepted. Outside Plan mode, a clear request to implement, fix, change, proceed, or update is already authorization for bounded local implementation. Stop for approval only when a material choice, authority correction, destructive action, dependency, cost, deployment, external mutation, credential, or scope expansion is unresolved. A small docs-only protocol fix explicitly requested by the user may proceed directly.

Use Boost `search-docs`, Context7, plugin catalogs, and available MCPs when their context can materially change the plan, especially to confirm that proposed patterns, packages, or integrations exist and are version-compatible. Do not invoke external research or tooling merely to satisfy ceremony. Activate the installed skills that match the slice's domain so their conventions shape the work.

Sources: use Laravel Boost first for the Laravel ecosystem, especially version-specific `search-docs`; Context7 for focused, version-specific library, plugin, SDK, or API docs when Boost is insufficient — treat it as technical context only, never product, benchmark, or reference authority; authoritative internet or primary sources for policy, integration contracts, or mature-SIS behavior, preferring local Philippine campus and SIS context when credibility is uncertain; and installed skills or plugins for generic process, which never override TALA authorities. Use official sources for missing, conflicting, security-critical, or contractual details. If deep research is not needed, state why — internal cleanup, native or framework implementation, or direct alignment to a recently accepted benchmark.

Mandatory tools and domain skills need to be activated and proven available once per uninterrupted slice, then may be reused through implementation, verification, and Cleanup. Recheck them after tool failure, a newly introduced domain, evidence that the connection or installed-version context changed, or a resume delta that invalidates their prior proof. Compaction or interruption alone does not invalidate a proven tool. Reuse never waives a mandatory-tool failure gate.

## 4. Gates

Each gate uses the same shape: **When**, **Do**, **Block**, and **Skip** where applicable.

### 4.1 Ground-Truth Gate

**When:** Run a **full** gate for a new orchestrated slice when no attributable evidence exists, and whenever scope, authority, worktree, commit attribution, runtime, database premises, or a contradiction invalidates the relevant evidence. Use a proportionate **delta** gate for clear direct work and before proceeding, handoff, verification, and Cleanup while prior evidence remains valid. Compaction or interruption triggers a resume delta first; repeat the full gate only when that delta shows missing or invalid evidence. The trigger "GTG" invokes the applicable form explicitly.

**Do — full gate:** Read each in-scope authority and implementation file at the depth necessary to understand the relevant contract; do not infer whole-file meaning from an isolated match. For database, registration, or runtime surfaces in scope, verify both sides with the applicable evidence: (1) what exists in the running system — such as `Schema::hasTable` on `test_tala_db`, panel registration, the creating migration, live references, or passing tests; and (2) what the PRD, blueprint, or architecture requires. Do not run database or registration probes for documentation-only or unrelated non-database work. Then assign one verdict per material surface:

- **Aligned** — exists and matches the authority. Keep it; cite it as accepted evidence.
- **Gap** — exists but the required behavior is missing or incomplete. Patch it as a focused addition; never rewrite what is aligned.
- **Superseded remnant** — exists, but a live replacement already satisfies the authority. Retire it, naming the replacement.
- **Required-but-unbuilt** — the authority requires it and it does not exist. Build it or defer it; never delete a required feature merely because it is unbuilt.
- **Conflict** — code and authority disagree, or the authority's own design is wrong or infeasible. Route it to the Authority Document Correction rule (Section 9); never silently patch code to a possibly-wrong authority, and never silently rewrite an authority to match code.

"Does not exist" alone never decides an action: the existence check chooses write-versus-delete, and the authority check decides whether the action is warranted. Cite the verdict and evidence per surface, not as a blanket "existing code accepted."

**Do — delta gate:** Reconfirm only the premises that could have changed since the last valid full gate: current commit and dirty state; the active contract; changed files and their direct authorities; affected schema, registration, configuration, runtime, or external facts; failed or incomplete evidence; and any newly reported behavior. Reuse unchanged cited evidence instead of automatically rereading documents, rerunning identical probes, or repeating accepted research. Record the delta and whether it leaves the prior verdicts valid.

**Evidence invalidation:** Prior evidence is stale when relevant executable code, dependency, configuration, schema, fixture, environment, external state, authority, or approved scope changes; the worktree or commit differs without attribution; the prior check failed or was incomplete; Cleanup changes behavior; or a new finding contradicts the premise. Time-sensitive external evidence must be refreshed when its age could affect the decision.

**Block:** Never trust an issue's or Next Steps' framing over this check. Stop and re-surface only when reality changes product authority, approved scope, risk, acceptance criteria, destructive or external permission, or the feasibility of the requested outcome. Record non-material differences, update the evidence boundary, and continue.

### 4.2 Slice Clarity Gate

**When:** A slice introduces or materially changes a school workflow, office decision, TALA responsibility, feature category, or manual/digital boundary, or when any of those are unclear. A direct conformance fix may reuse an attributable accepted clarity result when none of those premises changes.

**Do:** State all five, using PRD intent, current code, and manual/digital judgment:

- Office owner — who owns the decision in the real institution.
- Manual workflow step — what humans do outside TALA.
- TALA's responsibility — source record, generated read-only view, office-result record, integration input/output, or deferred.
- Feature category — one of the responsibilities above.
- Why current code or a bounded benchmark does not contradict the plan, and whether the behavior over-automates office judgment.

**Block:** If a triggered item is materially missing or unclear, mark the slice unclear and do not implement. "PRD wording is readable" does not satisfy this gate. Non-material naming or evidence gaps may be corrected in scope without a separate approval.

### 4.3 Benchmark Gate

**When:** Planning a new or materially changed real-world workflow, manual-office decision, integration contract, official output, exposed-data class, or security, privacy, audit, retention, or reporting policy when existing TALA authority and accepted evidence do not already settle the behavior. A domain name or first sub-slice alone does not trigger a benchmark.

**Do:** Run a bounded reality-check benchmark before finalizing the plan. Record a "Benchmark Result" section with: the domain checked; sources consulted (Boost docs, web search, business evidence, or a prior benchmark); findings (what was confirmed or contradicted); and PRD-alignment confirmation (does the PRD match real-world practice?). Business benchmarking validates workflow credibility; a qualified reference validates implementation fit; neither overrides TALA authorities.

**Block:** If the trigger applies and this section is absent or says "not needed" without a valid skip citation, the plan is incomplete — do not approve or implement.

**Skip:** Skip for direct authority conformance, narrow defects, tests, formatting, local documentation corrections, and implementation choices that do not change the real-world workflow. A later sub-slice may reuse a recent accepted benchmark only when all of these hold: same workflow; no new role; no new source record; no new integration boundary; no new official output; no new exposed-data class; no new manual-office decision. Cite the prior benchmark by TAL-XX ID when one exists.

### 4.4 Qualified-Reference (Overlap) Gate

**When:** A material implementation or workflow choice remains open and meaningful overlap with a qualified reference could reduce uncertainty, custom code, or adoption risk. Default reference: the local Academico checkout at `D:\D SCHOOL\SYSTEMS\SIA-TALA-COGNITRES`; canonical remote: https://github.com/academico-sis/academico. Do not scan it routinely or broadly.

**Do:** Answer both questions, then record the disposition:

- **Business-logic overlap** — does this exact workflow (same records, rules, domain) exist in the reference? If yes, inspect at depth 2-3 for logic, service patterns, and data model. If no, record "no business-logic overlap."
- **Implementation-pattern overlap** — does the reference have a similar UI need (data shapes, roster tables, review queues, submission flows, Filament resource patterns, plugin usage) even if the rules differ? If yes, inspect at depth 1-2 for UI patterns, components, plugins, and architecture. If no, record depth 0 with a reason.

Depth levels: `0` skip (both answers "no"); `1` rendered UI (resource structure, table and form layout, actions); `2` relevant files (services, models, policies, migrations); `3` dependencies, tests, and data model (only when adoption or copying is plausible). Record the source (local or remote), depth used, surfaces examined, and disposition: keep TALA, adapt a pattern, copy bounded code with attribution, adopt a dependency, reject as incompatible, or reconcile an authority conflict.

**Block:** When the gate triggers, do not skip to depth 0 until both questions are answered "no."

**Skip and reuse:** Skip for direct authority conformance, narrow defects, tests, formatting, local documentation corrections, or work with no plausible reference overlap. When triggered, check the local checkout first; use the canonical remote via the GitHub API at the same minimum depth only if the local checkout is unavailable or stale, or to settle freshness, license, dependency, or copy decisions. Reuse an accepted finding for related sub-slices unless the workflow, reference, or integration boundary changes. The primary gives workers exact reference paths and allowed patterns; workers do not repeat broad discovery.

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

The full lifecycle in this section applies after one of those activation prompts or while an active approved contract is being continued. A clear direct request outside that lifecycle uses the core safety rails and proportionate gates without creating an active contract or tracker churn unless the user asks for orchestration, the work must be split or delegated, or its risk and duration require durable coordination.

### Codex Plan Mode bridge

When the Codex runtime declares Plan mode or the user invokes `/plan`, treat the turn as `Plan TAL-XX` when the target issue or slice is identifiable. If the target is not identifiable, ask for it rather than inventing one. The runtime mode is the signal; do not create or depend on a repository flag to detect it.

While Plan mode is active, stay read-only. The primary may inspect Git and authorities, run non-mutating discovery and verification, ask clarifying questions, apply the planning gates, and draft or revise the plan. It must not edit code or documentation, persist the active contract, delegate a worker, commit, push, deploy, open a PR, mutate Linear or another external system, or perform another write. This runtime restriction takes precedence over general words such as `proceed`, `implement`, or `fix` while the mode remains active.

The final plan must state its execution boundary. By default, accepting that plan and explicitly choosing **Implement** or otherwise requesting execution authorizes `Primary proceed`, automatic remediation of in-scope defects, and proportionate verification only. It does not authorize Cleanup or a commit, orchestration or subagent use, push, PR, deployment, Linear or other external mutation, dependency changes, destructive database work, credentials, cost-bearing action, or material scope expansion unless the plan and approval explicitly include the applicable permission.

Approval while Plan mode remains active accepts the current final plan but does not start execution. Map the transition to `Primary proceed` only when all three facts are present: the current final plan was accepted; the user or Codex UI explicitly requests **Implement**, execute, or an unambiguous equivalent; and the runtime is no longer in Plan mode. Merely leaving or toggling out of Plan mode, restarting a session, or sending an unrelated prompt is not execution authorization. If the plan changes materially after approval, invalidate that approval and present the revised plan again.

On the first authorized execution turn, run the resume delta, persist the accepted contract when the durable-contract rule applies, and then implement and verify within the accepted boundary. If the runtime still reports Plan mode, remain read-only and explain that an explicit implementation transition is still required; never fight the host mode. Cleanup, delegation, and every external effect retain their separate gates.

Source boundary: [official Codex Plan mode guidance](https://learn.chatgpt.com/guides/best-practices.md) and the [`/plan` command reference](https://learn.chatgpt.com/docs/developer-commands?surface=cli#switch-to-plan-mode-with-plan).

Before orchestrated implementation, delegation, tracker change, or commit, the primary reports:

1. Git and dirty state.
2. Current issue and next boundary.
3. Authority files checked, and any conflicts or stale statements.
4. Primary-versus-worker decision.
5. Proposed slice plan and exclusions.

After compaction, interruption, rejected worker output, an unclear handoff, or stale state, run a resume checkpoint: load the accepted plan from the active contract when one is required and exists; otherwise use the accepted same-task plan plus current Git authority. Run the Ground-Truth delta first, then restate the issue, authority evidence, exclusions, dirty state, verification state, and next action. Escalate to the full gate only when the delta invalidates evidence. The same-chat context may supplement a contract but never override Git authority; do not invent a missing contract or reconstruct an accepted plan solely from memory.

### Durable active plan contract

After the user approves a long-running, delegated, high-risk, or split orchestration plan, the primary must record the complete accepted contract under one `Active Approved Plan Contract` section in `TALA-Rescue-Next-Steps.md` on the first authorized execution turn after Plan mode, or immediately after approval in a non-Plan workflow, and before implementation or `Orchestrate TAL-XX`. The compact parent/sub-slice table remains the roadmap; the active section is the temporary Git-tracked execution authority. Exactly one active contract may exist. A small direct slice does not edit Next Steps merely to create temporary execution state.

The active contract contains the Section 5 plan fields, accepted implementation checklist, authority corrections, exclusions, expected verification, and human-only steps. Keep it concise: cite authority paths and accepted benchmark/reference conclusions instead of copying source text, transcripts, code, test results, volatile counters, or delivered-work history.

An approved revision replaces the active contract in place; do not retain stale versions. After compaction or interruption, read this section and run the resume delta; use the full Ground-Truth Gate only when evidence was invalidated, and stop only for a material difference under Section 4.1. If the slice is canceled or replaced, remove or replace the contract only after the user approves that disposition.

After a worker launch succeeds, mark the roadmap row and active contract `In progress` and change the Next Boundary to `Verify TAL-XX`. If launch fails, leave the contract `Approved; awaiting orchestration`. These Git-tracked states are the only durable execution marker; do not create a separate handoff file or memory entry.

During `Cleanup TAL-XX`, move delivered detail into the canonical commit message, remove the active contract, and compact or remove the roadmap row as Section 8 requires. Active task contracts never belong in Serena or agent-native memory; those systems may retain only durable procedure or genuine non-Git carry-ins.

### Memory Freshness Check

**When:** Primary activation, resumed or compacted work, and Cleanup only when relevant memory is enabled, available, and likely to affect a material decision.

**Do:** Read Git state, Next Steps, and the tracker first. Then inspect only the relevant Serena or agent-native entries, read-only by default. Treat memory as recall, never authority; keep current boundaries, commits, statuses, and counters only in Git authorities. If stale memory could mislead future work, report it and propose the supported correction. Mutate, rename, or delete memory only when the user explicitly requests or authorizes that exact action and the interface permits it. Report memory status only when memory materially informed the work.

Source boundary: [official Codex memory guidance](https://learn.chatgpt.com/docs/customization/memories.md).

**Block:** Never scan or rewrite all memories by default, edit proprietary storage directly, mutate memory without explicit user authority, duplicate Git state, or claim an unconfirmed update.

Commands are canonical shorthands, not magic strings. An explicit natural-language equivalent counts when the target, action, scope, and external effect are unambiguous:

- `Primary proceed` — continue the accepted current issue only.
- `Plan TAL-XX` — draft a plan only.
- `Orchestrate TAL-XX` — after an accepted plan, authorize one accountable worker.
- `Verify TAL-XX` — independently inspect the worker result and the live repo.
- `Cleanup TAL-XX` — local tracker update plus one bounded local commit only.
- `Sync TAL-XX to Linear` — authorize the named Linear mutation. An equivalent request must explicitly name Linear, the issue, and the requested mutation; `finish`, `close`, `cleanup`, `commit`, or `proceed` alone never authorize a Linear sync.

Outside Plan mode, a clear direct request to implement, fix, change, or update authorizes bounded local work but not a commit, push, deployment, PR, Linear mutation, destructive database action, dependency, external cost, or material scope expansion unless the request explicitly includes it.

The user may explicitly combine the normal phases for one approved slice, for example: `Primary proceed TAL-XX, automatically remediate in-scope defects, Verify, and Cleanup after verification passes. Stop only at a protocol human gate.` This is advance authorization for the named slice's implementation, verification, and Cleanup phases; the primary must still preserve their internal boundaries, run the applicable delta gates, and stop rather than commit when verification fails. Compound authorization never applies to a different or next slice and never authorizes destructive database work, unresolved product authority, credentials, cost-bearing or external mutation, deployment, dependency or material scope expansion, subagent use, push, PR, or Linear mutation unless the user separately grants the corresponding gate.

### Slice contract

A slice must be small enough to inspect, implement, test, and verify in one pass. If it is broad, split it during planning and record a compact sub-slice map in Next Steps. This roadmap map is separate from the one detailed active contract required above:

1. The primary identifies during planning that the issue is too broad.
2. The primary proposes the sub-slice map in its report (IDs, one-line purposes, dependency order, and next boundary for each).
3. The user approves the plan, including the split.
4. The primary immediately records the approved map in Next Steps. This is planning documentation and needs no separate approval.
5. The primary plans the first sub-slice under the normal gates.
6. On completion, trim each finished sub-slice's map row to a one-line status stub (its delivered detail lives in the commit message, not here). When every sub-slice of a parent is complete, remove the parent and its map from Next Steps; the record persists in the commit messages and, once synced, in Linear.

If an approved sub-slice is later found mid-implementation to need further splitting, stop, propose the new split in the primary thread, get approval, record the updated map, then continue.

Every plan defines the user goal, accepted scope, applicable authorities, likely files or surfaces, verification, and explicit exclusions. Add role, trigger, inputs, changed records, outputs, UI editability, downstream consumers, integration boundary, purposeful simplification, manual/digital ownership, benchmark, and qualified-reference fields only when they can change the decision or when their gate triggers. Do not fill non-applicable fields merely to satisfy a template.

Every retained UI surface needs a plain-purpose statement: who uses it, what decision or action it supports, why it belongs in the MVP, and why it is read-only or editable. If the purpose is unclear, rename, hide, defer, redesign, or tie it to its owning module before acceptance. Classify every feature before implementation as one of: source record, generated read-only view, manual-office result record, integration input/output, or deferred. If it fits no category cleanly, challenge its MVP purpose before handoff.

When a human-only step exists, state what it is, why it is manual, the exact steps, the expected evidence, and what it unlocks.

## 6. Delegation and workers

Use a separate worker only when the user explicitly asks for orchestration, delegation, or background work, or when an accepted plan authorizes it. Default to one primary and one accountable worker.

**Multi-worker (max 3):** permitted only when all of these hold — (1) the plan identifies parallelizable sub-tasks with zero file overlap; (2) each worker's file scope is enumerated in its packet and no worker edits another's file; (3) at most one worker runs DB-backed PHPUnit at a time against shared `test_tala_db`; (4) no worker touches a migration, seeder, or shared service another depends on; (5) the user approves the parallel plan. Static checks such as PHPStan may overlap only when they do not contend for generated files or material runtime resources. Safe parallel patterns: multiple read-only audit workers; parallel research or benchmark workers; non-overlapping implementers with staggered DB-backed test runs. If any file, model, service, or test-DB access overlaps, fall back to sequential single-worker execution. The primary merges all handshakes into one acceptance.

**Thin launch envelope:** before starting a worker, confirm that the active approved contract exists in Next Steps and matches the requested issue. The launch envelope references that heading instead of repeating it and contains only execution-time facts: issue ID; current commit and attributable dirty state; fresh Ground-Truth Gate findings or deltas; exact worker-owned files and concurrency/test-DB ordering; required skills, tools, worker model/effort, runtime and DB-proof commands; any narrower stop condition; and the required return-handshake format. Deliver it inline or through another supported worker interface. Do not restate the contract's checklist, authorities, benchmark, exclusions, or verification plan unless an execution-time fact narrows them; any contradiction or expansion blocks delegation and returns to the primary for approved plan revision. Redact secrets and keep context minimal.

**Workers must:** read the launch envelope first, then `AGENTS.md`, this protocol, the full active contract, and its relevant authorities before editing; preserve unrelated worktree changes; execute the accepted contract and not act as a second primary unless scoped for research or planning; stop as `BLOCKED` if the contract, issue, or scope is materially unclear; return to the primary if a ground-truth difference changes authority, approved scope, risk, acceptance, permission, or feasibility; record non-material differences and continue within the contract; stop for the user when dashboards, credentials, approvals, or environment setup are needed; not commit, push, deploy, open PRs, sync external systems, or start the next issue unless explicitly scoped; and merge any helper work into one final handshake.

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

Primary acceptance requires independent inspection and proportionate verification; passing tests alone is not acceptance. Independent verification means a fresh judgment against the approved contract, authority, diff, state transitions, authorization, and cross-role effects. It does not require an automatic identical rerun of every successful command when the evidence remains attributable and uninvalidated.

High-risk deletion slices need one independent re-check before cleanup. Orchestrated work already has two reviewers — the worker's ground-truth re-check plus the primary's verification — so it never adds a **cold audit**. A primary-direct deletion adds a cold audit only when the change is hard to reverse *and* not fully test-provable — it drops a table, column, or migration; removes a live-referenced model, service, or policy that registered surfaces depend on; or spans a large file set — *and* the primary still holds residual doubt; behavior-neutral retires, additive/verification/docs slices, and small deletions fully covered by a reference sweep and green suite skip it with a one-line reason. When invoked, a fresh read-only reviewer is given only the diff, deletion list, and safety checklist (no repo re-exploration) and returns `JUSTIFIED` / `NOT JUSTIFIED` with evidence; `NOT JUSTIFIED` blocks cleanup, and the user may request or waive it for any slice.

Before cleanup or commit, the primary reports: authority alignment, accepted scope, retained-surface purpose, exclusions, verification, dirty state, and next boundary.

## 8. Git, database, verification, and sync

- Preserve user-owned and unrelated changes.
- DB-backed checks require proof of target: `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=test_tala_db` — never `tala_db` or `tala_test_codex`.
- Match verification to change risk:
  - documentation-only changes require authority consistency, reference and contradiction review, and `git diff --check`;
  - local additive behavior requires focused positive, negative, authorization, state, and regression tests appropriate to the changed path;
  - cross-role, destructive, schema, external, deployment, security, or cost-bearing work keeps its stronger existing tests and human gates.
- Run focused PHPUnit for changed behavior. Reuse a successful attributable run until an invalidation condition in Section 4.1 occurs; rerun when evidence is missing, failed, incomplete, stale, or when independent inspection identifies a different case that must be proved.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run focused PHPStan or Larastan when typed PHP paths or tests change.
- Run `git diff --check` before any handoff or commit.
- Use browser or rendered acceptance only when visual, responsive, accessibility, or interaction behavior cannot be established reliably from code and programmatic evidence. Repeat only changed, failed, newly high-risk, or cross-role-dependent journeys; keep the consolidated role-organized human pass in the slice that owns final acceptance.
- Cleanup invalidates prior verification only to the extent Cleanup changes its evidence boundary. Documentation, tracker compaction, or removal of temporary evidence needs documentation and diff checks; executable code, configuration, schema, fixture, or behavior changes require the affected verification to run again.
- After primary acceptance and explicit commit authorization through `Cleanup TAL-XX` or an unambiguous equivalent request, make one bounded local Git commit whose message is the canonical record of what the slice delivered and why: scope, key changes, verification evidence, and any routed deferrals.
- Keep each kind of evidence under one owner: PRD owns product behavior; the UI blueprint owns surface mapping; architecture owns integration and deployment boundaries; this protocol and the `AGENTS.md` router own process; Next Steps owns order and the active contract; the tracker owns local/Linear status; the bounded commit owns delivered implementation detail and verification; the Operations and Defense Guide owns consolidated operator and defense claims; and volatile execution state belongs in no durable memory. Link to the owner instead of duplicating its content.
- While a durable orchestrated slice is active, Next Steps holds its approved plan contract; at Cleanup that section is removed. Otherwise Next Steps holds scope, order, and the sub-slice map; the tracker holds ID, status, and title/domain; the commit message — and, once synced, Linear — holds the delivered detail. Never paste verification results or evidence blocks into Next Steps or the tracker.
- For an orchestrated TAL Cleanup, record a lean tracker row (`Done locally; pending explicit Linear sync`, title/domain only), and either remove the completed active-planning entry or trim its sub-slice map row to a one-line status stub. Direct work does not create tracker churn unless the user explicitly includes that action.
- A standalone governance amendment without a TAL issue ID does not create an artificial tracker or Linear row; when explicitly authorized, its bounded local commit is the canonical delivery record.
- A local commit never authorizes push, deploy, PR, or Linear mutation.
- Keep the tracker row pending until the user explicitly authorizes the named Linear sync through `Sync TAL-XX to Linear` or an unambiguous equivalent; after sync, move it to compact synced history.
- When application behavior or UI changed, give the user a post-commit checklist: pages, actions, expected results, and failure signs.
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
