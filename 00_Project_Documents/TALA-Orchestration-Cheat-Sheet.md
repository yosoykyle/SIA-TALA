# TALA Orchestration Cheat Sheet

This is a quick operational companion to the [TALA Orchestrator Protocol](TALA-Orchestrator-Protocol.md). It introduces no workflow authority. If this guide and the protocol differ, the protocol governs.

## Choose the mode

Codex Plan mode is an application mode. `Plan #NN` is a TALA workflow command. One does not automatically activate the other.

| Action | Codex mode | Effect |
| --- | --- | --- |
| Draft a coordination map or implementation Issue | Plan mode | Read-only |
| `Plan #NN` | Plan mode | Read-only Issue plan |
| Re-anchor or run the final audit | Plan mode recommended | Read-only reconstruction or audit |
| Create or update an approved Issue | Default mode | Explicit GitHub write only |
| `Complete #NN` | Default mode | Implement, verify, and make one local commit |
| `Publish #NN` | Default mode | Push directly for approved solo work or open a PR |
| Merge a PR or close the coordination Issue | Default mode | Separate explicit GitHub authorization |

## One-time coordination setup

Do this once when an approved implementation cycle has no coordination Issue.

1. In Plan mode, draft the complete high-level journey map, dependency order, capacity assumptions, completion conditions, and first recommended slice.
2. Review the draft.
3. In Default mode, explicitly authorize creation of the coordination-only Issue with the `coordination` label and inclusion in `TALA Development` as `In Progress`.
4. Do not treat the coordination Issue as coding permission or as an implementation `Todo`.

```text
Draft the TALA coordination map for the current approved implementation cycle.

Read AGENTS.md, the TALA Orchestrator Protocol, canonical product documents,
current GitHub Issues and Project statuses, open pull requests, and Git state.
Include the complete high-level journey map, dependencies, capacity assumptions,
completion and final-audit conditions, and first recommended slice.

Read-only. Do not create or modify anything.
```

```text
Create the approved coordination-only GitHub Issue exactly from the accepted
draft. Do not create an implementation Issue, modify files, begin coding,
commit, publish, merge, or deploy anything.
```

The same coordination map remains active until its accepted cycle passes the final integrated audit and is separately closed. Finishing one slice does not create a new coordination cycle.

## Solo workflow

Use one active implementation Issue at a time:

```text
Derive draft -> Create Todo Issue -> Plan #NN -> Complete #NN
-> Publish #NN -> Done -> Derive the next Issue
```

### 1. Derive the next Issue

In Plan mode:

```text
Derive the next eligible journey-complete implementation Issue from the
approved TALA coordination map.

Read the coordination Issue, current Issues and Project statuses, open PRs,
relevant canonical authority, current implementation, and Git state. Exclude
completed, canceled, active, duplicate, and dependency-blocked work. Explain
why the recommendation is dependency-ready and next on the critical path.

Draft the outcome, owner, authority and UI IDs, scope, dependencies, material
implementation order, acceptance criteria, verification and browser scenarios,
exclusions, and stop conditions.

Read-only. Draft the Issue only; do not create it yet.
```

### 2. Create the Issue

After accepting the draft, switch to Default mode:

```text
Create the approved implementation Issue exactly from the accepted draft.
Apply the implementation label, assign the approved owner, and establish the
approved dependency or sub-Issue links. Project automation adds it to TALA
Development as Todo.

Do not plan, implement, create a branch, commit, publish, merge, or deploy.
```

### 3. Plan, complete, and publish

```text
Plan #NN
```

Use Plan mode. This reads the Issue, relevant authority, implementation, and qualified sources when needed. It makes no edits or external writes.

After accepting the plan, exit Plan mode:

```text
Complete #NN
```

This marks the Issue `In Progress`, implements the bounded scope, researches when needed, verifies, remediates in-scope failures, cleans the intended diff, and creates one local commit. It does not push, merge, or deploy.

When the result and outgoing scope are accepted:

```text
Publish #NN
```

For approved solo work on `main`, this freshly verifies and pushes the accepted commit range, then closes the Issue. Project automation marks it `Done`. It never authorizes deployment.

## You plus one developer

Before a second implementation Issue becomes active concurrently, verify working pull-request CI and protection of `main`: required successful checks, resolved review conversations, and blocked force-pushes and deletion. Configuring these controls is a separate authorized task.

Then use:

- One accountable owner per Issue.
- One Issue-specific branch from accepted and up-to-date `main`.
- One pull request per Issue, with no unrelated Issues on its branch.
- Isolated workspaces and databases unless safe sharing is proven.
- Recorded dependency order and shared-seam ownership before coding.
- Dependent work waits for its prerequisite PR to merge into `main` unless an explicit stacked-branch plan is approved.

In Plan mode, derive only work that can proceed safely in parallel:

```text
For this derivation batch, we are working with one additional developer.

Developer:
GitHub username: [username]
Available for: [work area]
Capacity: one active Issue

Derive the next parallel-safe TALA implementation Issue batch from the
approved coordination map. Inspect dependency readiness, active ownership,
open Issues and PRs, shared seams, workspace isolation, CI, and main protection.
Explain ownership, dependencies, branch boundaries, and why the work will not
conflict.

Read-only. Do not create Issues, branches, Project changes, or other writes.
```

Each Issue still follows `Plan #NN` and `Complete #NN`. `Publish #NN` pushes its Issue branch and opens one PR containing `Closes #NN`; open PR review remains `In Progress`.

`Publish #NN` never authorizes merge. Merge only after separate explicit authorization and fresh evidence for acceptance criteria, required automated and browser verification, resolved review, bounded diff, integrated dependencies, and sufficient currency with `main`. After merge, the Issue becomes `Done`, and dependent branches refresh from `main`.

## Work without an Issue

Untracked work remains valid but has no GitHub task status.

| Request | Allowed result |
| --- | --- |
| Review, diagnose, or plan | Read-only unless a change is also requested |
| Implement, fix, change, build, or proceed | Bounded local edits and verification; no commit |
| Explicitly complete or commit the task | Bounded local edits, verification, and one local commit |
| Explicitly publish an accepted commit or range | Separately bounded push; no deployment |

State the target and external effect plainly. Never infer permission to commit, push, merge, deploy, or mutate GitHub.

## Compaction and resumption

Re-anchoring is automatic after compaction or resumption. The agent must treat the compacted summary as navigation, inspect recent original messages and durable evidence, and continue from the last proven checkpoint under the existing permission boundary.

The agent should check the owning Issue, accepted plan, Issue and Project status, Git branch, status, diff and commits, current verification, remote state when relevant, and any surviving terminal process. It must not blindly repeat an edit, migration, push, PR creation, or other external action.

Use this fallback only when the agent appears confused:

```text
Re-anchor and continue the currently authorized TALA task.

Active boundary: [Plan #NN, Complete #NN, Publish #NN, or untracked task]
Previous task: [task ID if available]

Determine what completed, what is running, what remains, and the current
authorization boundary from recent original messages and durable live evidence.
Continue from the last proven checkpoint. Do not repeat completed mutations,
broaden scope, publish, merge, deploy, or begin another Issue. If a material
state cannot be proven safely, stop and report the uncertainty.
```

Compaction never expands authority. A compacted `Complete #NN` may finish its bounded implementation and local commit, but it still requires `Publish #NN` before any push.

## Statuses

| Status | Meaning | Set by |
| --- | --- | --- |
| `Todo` | Approved implementation Issue exists but is not active | GitHub automation when an open `implementation` Issue enters the Project |
| `In Progress` | Implementation, open-PR review, or the coordination cycle is active | Codex at `Complete #NN`; coordination setup is a separately authorized write |
| `Done` | Work was delivered through the approved publication or merge path | GitHub automation when the Issue closes or linked PR merges |
| `Canceled` | Work was intentionally stopped, superseded, or closed as not planned, with a recorded reason | Codex after explicit authorization |

Do not create a local shadow queue or another Project status.

## Final integrated audit

When all accepted journeys appear covered, use Plan mode:

```text
Perform the final integrated acceptance audit for coordination Issue #NN.

Read the coordination map, linked implementation Issues, Project statuses,
merged PRs or published commits, canonical authority, current main, and current
automated and applicable browser evidence. Verify journey coverage, resolved
dependencies, cross-role behavior, official outputs, state transitions, and
the absence of required canonical gaps.

Read-only. Do not close or modify the coordination Issue, create Issues,
change files, commit, publish, merge, or deploy.
```

If the audit finds a required gap, return it to the normal derivation and `Plan`-`Complete`-`Publish` loop. If the audit passes, switch to Default mode and separately authorize closing the coordination Issue. Closing never authorizes deployment.

A successor coordination Issue is appropriate only for materially new approved work after the current map closes, not because one slice finished, time passed, work was informally deferred, or an audit found a gap.

## One-screen reminder

```text
NO COORDINATION ISSUE
Plan mode: draft full coordination map
Default mode: authorize coordination-Issue creation as In Progress

REPEATING SOLO LOOP
Plan mode: derive next Issue draft
Default mode: authorize implementation-Issue creation; automation sets Todo
Plan mode: Plan #NN
Default mode: Complete #NN
Default mode: Publish #NN
Repeat until the map is covered

SUBAGENTS
Default: do not delegate; when delegated, use at most one active subagent
No nested subagents; a second requires explicit approval and a responsive workstation

PARALLEL DIFFERENCE
Verify CI + main protection first
Use isolated owner + Issue + branch + workspace + PR
Publish opens PR; merge requires separate authorization

FINISH
Plan mode: final integrated audit
Gap: return to the Issue loop
Pass: separately authorize coordination closure
```
