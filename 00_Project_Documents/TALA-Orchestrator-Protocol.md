# TALA Orchestrator Protocol

## 1. Purpose

This protocol keeps TALA work aligned with the product definition while using GitHub Issues as the optional live task system. It defines three visible boundaries: **Plan**, **Complete**, and **Publish**.

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
- `archive/project-progress/TALA-Next-Steps-Retired-2026-08-09.md`, `archive/project-progress/TALA-Linear-History-Tracker-2026-08-09.md`, and Linear are frozen history only.

When sources materially conflict, stop and present the conflict rather than silently choosing one.

## 3. Operating model

### Plan

`Plan #NN` reads the named GitHub Issue, relevant Git authority, current implementation, and qualified sources when needed, then returns a decision-complete plan. It makes no local edits, commits, or external writes.

A useful plan states the goal, bounded scope, relevant authorities and surfaces, verification approach, exclusions, and any material decision requiring the user. Add detail only when it can change the decision.

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
2. Research when existing authority is insufficient, conflicting, time-sensitive, security-critical, or likely wrong.
3. Make bounded local changes.
4. Run proportionate non-destructive verification in the configured project environment.
5. Remediate and re-check in-scope failures.
6. Inspect and clean the intended diff without touching unrelated work.
7. Create one bounded local commit after verification evidence is current.
8. Report the result, evidence, exclusions, remaining risk, and publication boundary.

Complete leaves the issue open and its project item short of `Done`. It never authorizes a push, pull request, merge, deployment, destructive operation, dependency, credential, external cost, public-access change, or material scope expansion.

A clear task without an issue number remains valid. A request to implement, fix, change, build, or proceed authorizes bounded local edits, verification, and in-scope remediation but not a commit. An explicit natural-language request to complete or commit that task authorizes one bounded local commit and skips GitHub issue/project mutations.

### Publish

`Publish #NN` is a separate external-write boundary. It first requires current verification and intended-diff evidence.

- For approved solo work on `main`, inspect every commit ahead of `origin/main`, push only when the entire range is explicitly accepted, then close the issue and mark its project item `Done`.
- For concurrent work, push a `codex/` or developer branch and open a pull request containing `Closes #NN`. Leave the issue open until the pull request merges.
- Publishing never authorizes deployment, merge, public-access changes, or movement of unrelated issues unless explicitly included.

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

The `TALA Development` GitHub Project uses `Todo`, `In Progress`, `Done`, and `Canceled`. `Done` means the task was delivered; `Canceled` means it was intentionally stopped, superseded, or closed as not planned. Create no local shadow queue. Work without an issue remains valid but has no shared task status.

After compaction or resumption, re-anchor both tracked and untracked work before editing, committing, publishing, or claiming completion. Treat the compacted summary as navigation rather than the sole authority. Read recent original messages and expand backward only until the outcome, accepted decisions, current step, remaining work, and authorization boundaries are clear.

Then compare available durable evidence: the named issue, if any; relevant Git authority; live Git state; and current verification results. Refresh only premises that may have changed and re-plan only when authority, scope, risk, acceptance, permission, or feasibility changed. If a material point would require guessing, stop and ask the user.

Memory may help recall prior reasoning but never replaces live Git authority or issue state. Cleanup never creates, updates, renames, or deletes memory unless the user explicitly requests it through a supported interface.

## 6. Product and implementation judgment

- Keep aligned implementation and preserve unrelated work. Patch proven gaps instead of restarting or rewriting broad areas.
- Clarify the real office owner, manual step, TALA responsibility, and editable or read-only boundary only when a task introduces or changes that workflow.
- Research official or institutional sources when existing authority is missing, conflicting, time-sensitive, security-critical, or likely wrong; the user does not need to repeat that instruction.
- Inspect a qualified reference only when meaningful overlap could change an unresolved implementation choice. Benchmarking and reference review inform judgment but never override TALA authority.
- Prefer current aligned code, then native Laravel and Filament features, then an established compatible pattern or installed component, and finally focused custom code. Adding a dependency remains a human gate.
- Record an authority-backed deferral as a separate GitHub Issue only when the user authorizes that external write. Discard ideas with no authority or purposeful MVP role.

## 7. Delegation

Delegate only when the user explicitly requests it or approves a plan that includes it. Use one accountable worker by default and split work only when tasks are genuinely independent and their files and shared resources do not conflict.

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

## 9. Authority corrections

Product behavior stays in the PRDs, UI blueprint, and architecture specification. If implementation reveals a substantive authority error, present the evidence and proposed correction for approval before depending on it. Trivial wording or consistency fixes may be corrected within an already authorized documentation scope.
