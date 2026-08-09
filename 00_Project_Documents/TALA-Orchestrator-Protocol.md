# TALA Orchestrator Protocol

## 1. Purpose

This protocol keeps TALA work aligned with the product definition while allowing Codex to complete safe local work without unnecessary pauses. It defines four workflow boundaries: **Plan**, **Deliver**, **Commit**, and **Sync**.

Generic planning, debugging, testing, review, Laravel, Filament, and subagent technique belongs to the applicable installed skills and framework guidance. This file contains only TALA-specific authority, permission, coordination, and evidence rules.

## 2. Sources of truth

For a clear direct request, read `AGENTS.md`, the minimum relevant PRD module, UI blueprint or architecture section, and the implementation surfaces needed for the task. Do not load unrelated authorities or trackers.

For complex, ambiguous, high-risk, split, delegated, or resumed work, also read the relevant parts of:

1. `TALA-Rescue-Next-Steps.md` for issue order and any active contract.
2. `TALA-Local-Linear-Sync-Tracker.md` only when local or Linear status is in scope.
3. `business-evidence/` only when institutional terminology or workflow evidence is needed.
4. A qualified reference only when a material implementation choice remains unresolved.

Ownership is simple: PRDs own product behavior; `ui_surface_blueprint.md` owns UI and role mapping; `architecture_specification.md` owns integration and deployment boundaries; this protocol owns workflow permissions; Next Steps owns issue order and any active contract; the tracker owns sync state. Code and tests are implementation evidence, not product authority.

When sources materially conflict, stop and present the conflict rather than silently choosing one.

## 3. Operating model

### Plan

`Plan TAL-XX` or a request for a plan starts read-only planning. Also plan when the work is materially ambiguous, high-risk, long-running, split, or delegated.

A useful plan states the goal, bounded scope, relevant authorities and surfaces, verification approach, exclusions, and any decision that requires the user. Add workflow, role, data, benchmark, reference, or dependency detail only when it can change the decision.

Accepting a plan does not itself authorize implementation while Codex remains in Plan mode. Execution begins when the user requests implementation and the runtime permits writes.

### Deliver

A clear request to implement, fix, change, build, proceed, or `Deliver TAL-XX` authorizes Codex to:

1. Inspect the relevant authority and current implementation.
2. Make bounded local changes.
3. Run proportionate non-destructive verification using the project's configured environment.
4. Remediate and re-check in-scope failures.
5. Report the result, evidence, exclusions, and remaining risk.

Deliver does not authorize a commit, push, pull request, deployment, Linear mutation, destructive operation, new dependency, credential use, external cost, or material scope expansion unless the user explicitly includes it.

Small direct work does not create an active contract or tracker churn.

### Commit

`Commit TAL-XX` or an unambiguous equivalent authorizes one bounded local commit after verification evidence is current. Inspect the intended diff, preserve unrelated work, update only the Git-tracked task state required by the completed TAL workflow, remove any completed active contract, and commit only the approved slice.

A local commit never authorizes push, pull request, deployment, or external sync.

### Sync

`Sync TAL-XX to Linear` authorizes only the named Linear mutation. Push, pull request, deployment, public access, or another external write each requires its own explicit authorization.

The command names are optional shorthands. Clear natural language with the same target, scope, and effect is equivalent.

## 4. Human gates

Stop and ask only when safe progress requires one of these:

- A material product or institutional decision that current authority and evidence do not settle.
- A substantive correction to product authority.
- A destructive or difficult-to-recover action.
- A new dependency, credential, external cost, deployment, or public-access change.
- An external write such as Linear, push, pull request, email, or third-party mutation.
- A material expansion beyond the requested scope.
- Delegation or subagent use that the user has not authorized.

Do not manufacture a gate for reading files, inspecting logs, editing in-scope local files, running configured tests or checks, fixing an in-scope defect, or recording a non-material discovery.

## 5. Durable coordination

Use one `Active Approved Plan Contract` in `TALA-Rescue-Next-Steps.md` only when work is long-running, delegated, high-risk, split across slices, or expected to survive sessions. Record it on the first authorized execution turn, not during read-only planning.

The contract contains only the approved goal, scope, authorities, implementation checklist, exclusions, verification, human gates, status, and next boundary. Cite source documents rather than copying them. Do not duplicate volatile test output, counters, transcripts, or delivered history.

On resume, compare the live Git state and active contract, refresh only premises that may have changed, and continue when the difference is non-material. Re-plan only when authority, scope, risk, acceptance, permission, or feasibility changed.

Remove the completed contract during the authorized Commit step. Memory may help recall prior reasoning but never replaces live Git authority or stores active task state.

## 6. Product and implementation judgment

- Keep aligned implementation and preserve unrelated work. Patch proven gaps instead of restarting or rewriting broad areas.
- Clarify the real office owner, manual step, TALA responsibility, and editable or read-only boundary only when a slice introduces or changes that workflow.
- Research official or institutional sources only when existing authority is missing, conflicting, time-sensitive, security-critical, or likely wrong.
- Inspect a qualified reference only when meaningful overlap could change an unresolved implementation choice. Benchmarking informs workflow credibility; reference review informs implementation fit; neither overrides TALA authority.
- Prefer current aligned code, then native Laravel and Filament features, then an established compatible pattern or installed component, and finally focused custom code. Adding a dependency remains a human gate.
- Route an authority-backed deferral to the appropriate Next Steps issue. Discard ideas that have no authority or purposeful MVP role. Escalate disagreements with approved product scope instead of hiding them as deferrals.

## 7. Delegation

Delegate only when the user explicitly requests it or approves a plan that includes it. Use one accountable worker by default and split work only when the tasks are genuinely independent and their files and shared resources do not conflict.

Give each worker a narrow goal, exact owned files or surfaces, relevant authority, verification expectation, exclusions, and stop conditions. Workers do not commit, push, deploy, mutate Linear, expand scope, or start the next issue unless explicitly authorized.

The primary remains responsible for the final judgment. A worker returns only:

1. Outcome and changed files.
2. Verification performed and results.
3. Exclusions, risks, or blockers.
4. Any material discovery requiring a decision.

## 8. Verification and handoff

Verification is part of Deliver, not a separate permission boundary. Match it to risk:

- Documentation: authority consistency, contradiction review, intended diff, and formatting checks.
- Narrow local behavior: focused positive, negative, authorization, state, and regression checks as relevant, plus required formatting or static analysis.
- Schema, security, cross-role, destructive, external, or deployment work: stronger automated checks and the necessary human or rendered acceptance.

Use the project's configured environment and normal commands. Testing configuration belongs to the test infrastructure, not this orchestration protocol.

Passing tests are evidence, not the whole acceptance decision. Inspect authority alignment, the diff, authorization, state transitions, and affected roles in proportion to risk. Reuse attributable successful evidence until relevant code, configuration, environment, authority, scope, or findings invalidate it.

Every completed Deliver or Commit handoff reports the changed scope, verification evidence, untouched exclusions, remaining risks, and next action requiring authorization. Do not repeat unchanged evidence or narrate internal ceremony.

## 9. Authority corrections

Product behavior stays in the PRDs, UI blueprint, and architecture specification. If implementation reveals a substantive authority error, present the evidence and proposed correction for approval before depending on it. Trivial wording or consistency fixes may be corrected within an already authorized documentation scope.
