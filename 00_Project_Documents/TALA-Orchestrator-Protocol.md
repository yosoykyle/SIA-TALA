# TALA Orchestrator Protocol

## 1. Purpose

This protocol keeps TALA work aligned with the product definition while using GitHub Issues as the optional live task system. It defines three visible boundaries: **Plan**, **Complete**, and **Publish**.

For a compact operator sequence and copyable prompts, use the [TALA Orchestration Cheat Sheet](TALA-Orchestration-Cheat-Sheet.md); this protocol governs if the two differ.

Generic planning, debugging, testing, review, Laravel, Filament, and delegation technique belongs to the applicable installed skills and framework guidance. This file contains only TALA-specific authority, permission, coordination, and evidence rules.

## 2. Sources of truth

For a clear direct request, read `AGENTS.md`, the minimum relevant PRD module, UI blueprint or architecture section, and the implementation surfaces needed for the task. Do not load unrelated authorities or historical trackers.

For tracked work, read the named GitHub Issue. For complex, ambiguous, high-risk, split, delegated, or resumed work, also read `business-evidence/` only when institutional terminology or workflow evidence is needed and inspect a qualified reference only when a material choice remains unresolved.

Ownership is simple:

- PRDs own product behavior.
- `ui_surface_blueprint.md` owns UI and role mapping.
- `architecture_specification.md` owns integration and deployment boundaries.
- This protocol owns workflow permissions.
- A GitHub Issue owns its tracked task's goal, scope, acceptance criteria, and live status, but never overrides product authority.
- The linked GitHub Project is a view of issues, not a second task database.
- Code, tests, commits, and pull requests are implementation evidence.
- `00_Project_Documents/archive/project-progress/TALA-Next-Steps-Retired-2026-08-09.md`, `00_Project_Documents/archive/project-progress/TALA-Linear-History-Tracker-2026-08-09.md`, and Linear are frozen history only.

When sources materially conflict, stop and present the conflict rather than silently choosing one.

## 3. Operating model

### Issue derivation

Issue derivation is read-only. It drafts work but never creates an Issue, changes the Project, or mutates GitHub. For coordination-derived work, start from the approved coordination Issue that owns the accepted delivery order, then inspect current Issues and Project statuses, open pull requests, live Git state, and the minimum relevant canonical authority and implementation evidence.

Before drafting any tracked Issue, classify it as coordination-derived or standalone. Coordination-derived implementation work requires its active coordination Issue as a native parent. A genuinely standalone tracked Issue may remain parentless only when no active coordination owns its intended outcome, and its body states that reason. If the work belongs to or materially changes an active coordination map, or the classification is uncertain, stop for the appropriate derivation or authorized map correction instead of treating it as standalone. Clear work without an Issue remains valid under the normal boundaries and has no Project status.

Exclude work that is completed, canceled, already active, duplicated, or blocked by an unmet dependency. From the remaining dependency frontier, recommend the smallest journey-complete vertical slice that best unlocks the critical path. If materially different slices are equally eligible, present the alternatives and recommend one with rationale instead of silently choosing.

By default, derivation recommends one Issue. When the user explicitly declares more than one available implementation owner and requests a parallel-safe batch, it may draft no more Issues than the stated total active-Issue capacity. Select only mutually independent, journey-complete work from the dependency frontier; identify the proposed owner and the dependency, shared-seam, and isolation evidence for each candidate; and return fewer drafts than capacity when safe concurrency cannot be proven. The batch remains read-only and does not activate work.

Before drafting, perform a bounded operability and feasibility check of the owning journey: ordinary, correction, recovery, late, and reopening paths; human versus automatic mutations; downstream role and projection propagation; external-integration capability; and any canonical or supporting artifact that must remain consistent. A substantive contradiction, missing ordinary workflow, or infeasible assumption stops derivation for evidence and a targeted authority correction; it does not reopen unrelated PRDs.

Every derived draft identifies its intended outcome, accountable owner, relevant authority and UI inventory IDs, bounded scope, dependencies, material implementation order, acceptance criteria, verification and applicable browser scenarios, exclusions, and stop conditions. If the coordination Issue is missing, stale, or materially conflicts with current authority or delivery state, stop at a read-only recommendation rather than inventing the order.

Before presenting a derived draft, self-review it against live evidence and the Issue/Plan boundary. It must be the leanest acceptance-complete contract: reference rather than restate governing authority, preserve proven aligned implementation by default, express required outcomes instead of speculative schema, component, or method choices, and prescribe an implementation choice only when current evidence proves it necessary. Resolve correctable defects before presentation. Revise the same draft when only its quality or precision changes; derive again only when the selected outcome, scope, dependencies, or governing authority materially changes. End by stating the exact next action and Codex mode.

Creating the approved draft is a separate explicit external write. For coordination-derived implementation work, that authorization creates the Issue with the `implementation` label, assigns the approved owner, attaches it as a native sub-Issue of the active coordination Issue, and establishes approved dependency links separately. For standalone tracked work, create the parentless Issue only from its accepted contract and record why no active coordination owns it.

After creating any tracked Issue, re-read its live Issue and Project state and verify the intended parent classification, label, owner, dependency links, and Project status. Project automation adds an open `implementation` Issue to `TALA Development` as `Todo`; the user does not need to request those two Project mutations separately. Missing or incorrect recorded state stops before `Plan #NN` or `Complete #NN` for an explicitly authorized correction. Issue creation does not authorize planning, coding, a branch, a commit, publication, or merge.

### Plan

`Plan #NN` reads the named GitHub Issue, relevant Git authority, current implementation, and qualified sources when needed, then returns a decision-complete plan. It makes no local edits, commits, or external writes.

A useful plan states the goal, bounded scope, relevant authorities and surfaces, verification approach, exclusions, and any material decision requiring the user. Add detail only when it can change the decision.

Before presenting the plan, challenge each material implementation decision against governing authority, current implementation evidence, applicable qualified sources, reasonable alternatives and trade-offs, verification, and cleanup. Resolve correctable contradictions, unsupported assumptions, and premature choices; surface any remaining material decision to the user instead of guessing. Present the best-supported decision-complete plan with concise rationale, without claiming universal optimality.

For a UI-bearing vertical slice, the plan and any developer handoff derived from it must explicitly identify:

- Canonical UI inventory IDs from `ui_surface_blueprint.md`.
- Workspace and navigation entry.
- Information and action hierarchy.
- Component disposition: Filament core, installed compatible dependency, focused TALA custom, or purposeful exclusion.
- Reusable current components.
- Loading, empty, validation, stale or concurrent, failure, and inaccessible states.
- Responsive transformation.
- Keyboard and screen-reader behavior.
- Print or other output effects when applicable.
- Browser acceptance scenarios.

This is a planning and handoff completeness contract. It does not require high-fidelity mockups for every state, reopen settled PRDs, map each inventory row to a separate route, or introduce new plugins or components.

A planning request without an issue number works the same way but has no GitHub task mutation. Accepting a plan does not itself authorize implementation while Codex remains in Plan mode.

### Complete

`Complete #NN` explicitly authorizes Codex to mark the named issue `In Progress` and then:

1. Inspect the relevant authority and current implementation.
2. Before material implementation, establish a working coverage map for every owning-Issue acceptance criterion. Reuse the accepted Plan where it already supplies the mapping; connect each criterion to its applicable governing authority, required domain behavior and ordering, human and automatic actions, UI or output reachability, and proportionate positive, negative, boundary, recovery, stale or concurrent, integration, browser, companion-artifact, external-action, and stop-condition evidence. Maintain the map as implementation decisions or findings change. A passing main journey, aggregate tests, or related code never proves criterion coverage by itself.
3. Research when existing authority is insufficient, conflicting, time-sensitive, security-critical, or likely wrong.
4. Make bounded local changes.
5. Run proportionate non-destructive verification in the configured project environment.
6. Remediate and re-check in-scope failures.
7. Inspect and clean the intended diff without touching unrelated work.
8. Build a criterion-by-criterion acceptance ledger for the owning Issue. Classify every acceptance criterion as `Verified`, `Partial`, or `Unverified` and cite current attributable evidence; automated or browser results may support entries but never auto-satisfy a semantic criterion.
9. Create one bounded local commit after verification evidence is current. Use a clear subject that identifies the bounded outcome; add a concise body only when important rationale, constraints, exclusions, or consequences are not apparent from the subject, owning Issue, and diff. Reference the owning Issue when applicable.
10. Report the result, evidence, exclusions, remaining risk, and publication boundary.

Complete leaves the issue open and its project item short of `Done`. It never authorizes a push, pull request, merge, deployment, destructive operation, dependency, credential, external cost, public-access change, or material scope expansion.

Complete may be reported successful only when every owning-Issue acceptance criterion is `Verified`. Any `Partial` or `Unverified` criterion keeps the Issue `In Progress` and blocks Publish, merge, closure, and `Done` unless the Issue contract is separately and explicitly corrected. Issue status, pull-request merge, and Project automation are consequences, not acceptance evidence.

A clear task without an issue number remains valid. A request to implement, fix, change, build, or proceed authorizes bounded local edits, verification, and in-scope remediation but not a commit. An explicit natural-language request to complete or commit that task authorizes one bounded local commit and skips GitHub issue/project mutations.

### Publish

`Publish #NN` is a separate external-write boundary. It first requires current verification, intended-diff evidence, and an all-`Verified` acceptance ledger.

After the source is on GitHub, every required CI check for the exact published commit or pull-request head must succeed. Immediately before an authorized solo closure or separately authorized concurrent merge, re-fetch the owning Issue and Project state, revalidate that every criterion remains `Verified`, update only evidence-backed acceptance checkboxes, and post a compact durable evidence record. Closure or merge happens last. Any failed gate keeps the Issue `In Progress`; never tick a semantic criterion merely because tests passed.

- For approved solo work on `main`, inspect every commit ahead of `origin/main`, push only when the entire range is explicitly accepted, then complete the required CI and closure preflight above before closing the Issue; the configured Project workflow sets its item to `Done`.
- For concurrent work, push the Issue-specific branch and open one pull request containing `Closes #NN`. Open pull-request review remains `In Progress`.
- `Publish #NN` may open that pull request but never authorizes merge. Merge requires separate explicit authorization and fresh evidence that acceptance criteria, automated and applicable browser verification, resolved review, bounded diff, integrated dependencies, and sufficient currency with `main` are satisfied.
- After an authorized merge, the linked issue closes and the configured Project workflow sets its item to `Done`; the merged branch may be deleted, and dependent branches refresh from `main` before continuing.
- Publishing never authorizes deployment, public-access changes, or movement of unrelated issues unless explicitly included.

The command names are optional shorthands. Clear natural language with the same target, scope, and external effect is equivalent.

## 4. Human gates

Stop and ask only when safe progress requires one of these:

- A material product or institutional decision that current authority and evidence do not settle.
- A substantive correction to product authority.
- A destructive or difficult-to-recover action.
- A new dependency, credential, external cost, deployment, or public-access change.
- An external write outside the explicitly named Complete or Publish action.
- A material expansion beyond the requested scope.
- Delegation or subagent use that the user has not authorized.

Do not manufacture a gate for reading files, inspecting logs, editing in-scope local files, running configured tests or checks, fixing an in-scope defect, or recording a non-material discovery.

## 5. Durable coordination

GitHub Issues are the only live shared task records. Keep issue bodies concise: goal, bounded scope, acceptance criteria, and material exclusions. Use issue comments only for durable decisions or evidence that collaborators need; do not duplicate full logs or transcripts.

One coordination-only GitHub Issue holds the accepted implementation order and links the derived implementation Issues. It is labeled `coordination`, appears in `TALA Development` as `In Progress` while its cycle is active, and remains the parent view of the implementation work. It is the durable delivery map, not an implementation authorization: it owns no branch or code change and permits no commit, publication, or merge. Native Issue dependency links determine which work is dependency-ready; the GitHub Project remains a view. Creating or materially updating the coordination Issue requires separate explicit authorization.

When no current coordination Issue exists for an approved implementation cycle, bootstrap is read-only: inspect canonical product authority and live delivery state, then draft the full accepted high-level journey map, dependency order, capacity assumptions, completion conditions, and first recommended slice. Creating that map as the coordination Issue remains a separately authorized GitHub write.

The coordination map covers the whole accepted implementation cycle at high-level journey boundaries without pre-planning every implementation detail. Derive only the next eligible bounded implementation work. A top-level slice may become one or more implementation Issues only when needed to keep work bounded, and every split must remain journey-complete rather than divide work by technical layer. A material split, reorder, or scope change requires a separately authorized coordination-Issue update before affected coding begins.

The cycle is complete only when every accepted high-level journey is covered by linked implementation Issues that are `Done` or intentionally `Canceled` with a recorded reason, dependencies are resolved, and a fresh read-only integrated acceptance audit finds no required gap against canonical authority. Required gaps return to the normal derivation and `Plan`–`Complete`–`Publish` loop. Closing the coordination Issue is a separate GitHub write and never authorizes deployment. Materially new approved work after closure starts a successor coordination Issue linked to the completed map; finishing one slice never creates a new coordination cycle.

When planner and implementer differ, the accepted plan or a concise execution handoff must be posted in or durably linked from the owning GitHub Issue before coding begins under an authorized assignment or `Complete #NN` boundary. Read the Issue body, relevant durable comments, and linked plan or handoff together. The handoff identifies relevant authority, bounded scope, accountable ownership, dependencies, material implementation order, verification, exclusions, stop conditions, and any task-specific environment, skill, or tool prerequisite whose absence would block implementation or acceptance. Do not repeat generic project guidance or every available tool.

The public `TALA Development` GitHub Project provides an `All Work` table and a status-grouped `Board`. It uses exactly `Todo`, `In Progress`, `Done`, and `Canceled`. An open Issue labeled `implementation` is automatically added and set to `Todo`; closing the Issue or merging its linked pull request sets it to `Done`. Codex sets `In Progress` when `Complete #NN` begins and sets `Canceled` only after an explicit intentional stop, supersession, or not-planned decision with a recorded reason. Open pull-request review remains `In Progress`. The active `coordination` Issue remains `In Progress` until the separately authorized final closure sets it to `Done`. Create no local shadow queue. Work without an issue remains valid but has no shared task status.

The approved solo direct-`main` publication path remains valid while only one implementation Issue is active. For solo work, use the existing primary `main` checkout. Do not create a branch, clone, or worktree unless the user explicitly requests isolation or the accepted plan demonstrates that isolation is necessary. Before a second implementation Issue becomes active concurrently, verify that pull-request CI and protection of `main` are configured and working. Parallel work requires pull requests, successful required checks, and resolved review conversations, with force-pushes and branch deletion blocked. Recording this gate does not authorize CI, repository-rule, or GitHub configuration changes.

A fresh Codex task is recommended when the implementation owner or Issue changes, but it is not a parallel-safety requirement. Any Codex task used for concurrent implementation must be explicitly anchored to one assigned Issue. The Issue-specific branch, isolated workspace and database, and pull request are the durable isolation boundaries; conversation history is not.

Concurrent development starts isolated: one accountable owner, one Issue-specific branch from accepted and up-to-date `main`, one pull request, and no unrelated Issues on that branch. Under an authorized `Complete #NN`, creating or switching to that local Issue branch is an ordinary setup step; pushing it remains part of `Publish #NN`. Do not share a mutable workspace or database unless isolation is proven. Record dependency order and shared-seam ownership before coding. Dependent work waits until the prerequisite Issue's pull request merges into `main` unless an explicit stacked-branch plan is approved.

After compaction or resumption, re-anchor both tracked and untracked work before editing, committing, publishing, or claiming completion. Treat the compacted summary as navigation rather than the sole authority. Read recent original messages and expand backward only until the outcome, accepted decisions, current step, remaining work, and authorization boundaries are clear.

Then compare available durable evidence: the named Issue, including relevant durable comments and its linked plan or handoff, if any; relevant Git authority; live Git state; and current verification results. Do not reread unrelated historical comments. Refresh only premises that may have changed and re-plan only when authority, scope, risk, acceptance, permission, or feasibility changed. If a material point would require guessing, stop and ask the user.

Memory may help recall prior reasoning but never replaces live Git authority or issue state. Cleanup never creates, updates, renames, or deletes memory unless the user explicitly requests it through a supported interface.

## 6. Product and implementation judgment

- `Plan` and `Complete` actively evaluate the owned implementation rather than treat the accepted plan or listed changes as a minimum checklist. Judge domain logic and state transitions and, for UI-bearing work, hierarchy, usability, and accessibility against current authority and evidence. Preserve aligned screens, plugins, components, and logic; remediate proven in-scope conformance or quality gaps even when the plan did not predict their exact code location. Do not substitute a different product or design preference, broaden the Issue, replace an established plugin or dependency, or make a material decision silently. Use proportionate rendered or browser inspection of representative states and viewports; an identified owned defect cannot be `Verified` merely because automated tests pass. The final integrated audit is a cross-slice backstop, not the first time obvious owned defects are considered.
- Keep aligned implementation and preserve unrelated work. Patch proven gaps instead of restarting or rewriting broad areas.
- Clarify the real office owner, manual step, TALA responsibility, and editable or read-only boundary only when a task introduces or changes that workflow.
- Research official or institutional sources when existing authority is missing, conflicting, time-sensitive, security-critical, or likely wrong; the user does not need to repeat that instruction.
- Inspect a qualified reference only when meaningful overlap could change an unresolved implementation choice. Benchmarking and reference review inform judgment but never override TALA authority.
- Prefer current aligned code, then native Laravel and Filament features, then an established compatible pattern or installed component, and finally focused custom code. Adding a dependency remains a human gate.
- Record an authority-backed deferral as a separate GitHub Issue only when the user authorizes that external write. Discard ideas with no authority or purposeful MVP role.

## 7. Delegation

Delegate only when the user explicitly requests it or approves a plan that includes it. Use one accountable worker and at most one active subagent by default on the primary workstation. Do not nest subagents. Split work only when tasks are genuinely independent and their files and shared resources do not conflict. A second concurrent subagent requires separate explicit authorization and must stop if local responsiveness degrades.

Give each worker a narrow goal, exact owned files or surfaces, relevant authority, verification expectation, exclusions, and stop conditions. Workers do not commit, publish, deploy, mutate issues, expand scope, or start another issue unless explicitly authorized.

The primary remains responsible for final judgment. A worker returns only:

1. Outcome and changed files.
2. Verification performed and results.
3. Exclusions, risks, or blockers.
4. Any material discovery requiring a decision.

## 8. Verification and handoff

Verification is part of Complete, not a separate user command. Match it to risk:

- Documentation: authority consistency, contradiction review, intended diff, and formatting checks.
- Narrow local behavior: focused positive, negative, authorization, state, and regression checks as relevant, plus required formatting or static analysis.
- Schema, security, cross-role, destructive, external, or deployment work: stronger automated checks and the necessary human or rendered acceptance.

Use the project's configured environment and normal commands. Testing configuration belongs to the test infrastructure, not this orchestration protocol.

Passing tests are evidence, not the whole acceptance decision. Inspect authority alignment, the diff, authorization, state transitions, and affected roles in proportion to risk. Reuse attributable successful evidence until relevant code, configuration, environment, authority, scope, or findings invalidate it.

Every completed local task reports changed scope, verification evidence, untouched exclusions, remaining risks, and the next action requiring authorization. Do not repeat unchanged evidence or narrate internal ceremony.

## 9. Authority corrections and decision records

Product behavior stays in the PRDs, UI blueprint, and architecture specification. If implementation reveals a substantive authority error, present the evidence and proposed correction for approval before depending on it. Trivial wording or consistency fixes may be corrected within an already authorized documentation scope.

The Architecture Specification owns the current architecture. An Architecture Decision Record explains why a separately accepted significant technical choice was made; it never grants authority or replaces the Architecture Specification, PRDs, UI blueprint, protocol, or owning Issue.

Offer an ADR only when the decision is hard to reverse, surprising without context, and the result of a genuine trade-off. If any condition is absent, keep the rationale in the owning plan, Issue, code, or canonical document instead. `Plan` may recommend an ADR but remains read-only, and no ADR may be written merely to make an undecided option appear settled.

When separately authorized, future ADRs live under `00_Project_Documents/architecture-decisions/` and record status, context, considered options, decision, consequences, and any supersession link. Create the directory lazily with the first qualifying ADR; do not mass-backfill historical decisions. If an implemented decision changes, retain the old record as superseded and link the replacement.
