# TALA Orchestration Cheat Sheet

This is the non-authoritative operator companion to the [TALA Orchestrator Protocol](TALA-Orchestrator-Protocol.md). Use it to choose the next action and copy a prompt. The protocol governs any conflict.

## Choose the mode

Codex Plan mode is an application mode. `Plan #NN` is a TALA workflow command; one does not activate the other.

| Action | Codex mode | Effect |
| --- | --- | --- |
| Draft a coordination map or Issue | Plan mode | Read-only draft |
| `Plan #NN` | Plan mode | Read-only implementation plan |
| Re-anchor | Current mode | Read-only reconstruction, then resume the existing boundary |
| Create or update an approved Issue | Default mode | Explicit GitHub write only |
| `Complete #NN` | Default mode | Implement, verify, and create one local commit |
| `Publish #NN` | Default mode | Push approved solo work or open a PR |
| Final integrated audit | Plan mode | Read-only cycle audit |
| Merge a PR or close coordination | Default mode | Separate explicit authorization |

## One-screen route

```text
SET UP ONCE
Draft coordination map -> authorize coordination Issue creation

REPEAT PER IMPLEMENTATION ISSUE
Derive draft -> accept draft -> authorize Issue creation (automation sets Todo)
-> Plan #NN -> accept plan -> Complete #NN -> Publish #NN -> Done

PARALLEL WORK
First verify CI and main protection
Use one owner + Issue + branch + isolated workspace + PR per task
Publish opens the PR; merge needs separate authorization

FINISH
Final integrated audit -> remediate gaps or authorize coordination closure
```

## Set up coordination once

Use one coordination Issue for the complete approved implementation cycle. It is the delivery map, not permission to code.

In Plan mode:

```text
Draft the TALA coordination map for the current approved implementation cycle.

Read AGENTS.md, the TALA Orchestrator Protocol, canonical product documents,
current GitHub Issues and Project statuses, open PRs, and Git state. Include the
high-level journey map, dependencies, capacity assumptions, completion and
final-audit conditions, and first recommended slice.

Read-only. Do not create or modify anything.
```

After accepting the draft, switch to Default mode:

```text
Create the approved coordination-only GitHub Issue exactly from the accepted
draft. Do not create an implementation Issue, modify files, begin coding,
commit, publish, merge, or deploy.
```

Keep that map until its cycle passes the final audit and is separately closed. Completing one slice does not create another coordination Issue.

## Repeat the implementation-Issue loop

### 1. Derive

In Plan mode:

```text
Derive the next eligible journey-complete implementation Issue from the
approved TALA coordination map.

Inspect the coordination Issue, live Issues and Project, open PRs, relevant
authority, implementation, and Git state. Exclude completed, canceled, active,
duplicate, and dependency-blocked work. Run the protocol's operability and
feasibility check, then explain why this is the next dependency-ready slice.

Draft the outcome, owner, authority and UI IDs, scope, dependencies, material
implementation order, acceptance criteria, verification and browser scenarios,
exclusions, and stop conditions. Self-review it as the leanest acceptance-
complete contract and correct unsupported choices or contradictions.

Read-only. Draft the Issue only; do not create it.
```

Derivation chooses **what work is next**. It does not decide the implementation.

### 2. Create

After accepting the draft, switch to Default mode:

```text
Create the approved tracked Issue exactly from the accepted draft.

If coordination-derived, attach its active coordination Issue as the native
parent. Apply the approved label, owner, and separate dependency links. If
genuinely standalone, keep it parentless and record why no coordination owns it.

After creation, verify the live parent classification, label, owner, dependency
links, and Project status. Stop if any recorded state is missing or incorrect.

Do not plan, implement, create a branch, commit, publish, merge, or deploy.
```

Project automation adds an open `implementation` Issue as `Todo`.

### 3. Plan

```text
Plan #NN
```

Use Plan mode. The plan decides **how the accepted Issue will be implemented**. It reads authority, live implementation, and qualified sources when needed, challenges material choices and alternatives, and makes no edits or external writes.

For architecture, security, migrations, integrations, parallel seams, hard-to-reverse choices, or weak evidence, optionally request a second read-only review:

```text
Independently review the proposed derivation or Plan #NN before I accept it.

Re-anchor from its authority and live implementation, Git, and GitHub evidence.
Challenge scope, dependencies, choices, alternatives, verification, preservation,
and cleanup. Report concrete defects or state that no material objection remains.
Do not change anything.
```

This review is optional and is not a fourth command.

Before accepting a draft or plan, confirm that you understand what will be built, why it is the correct next work, what is excluded, how completion will be proven, and what conditions require stopping. Resolve any material uncertainty before authorizing the next action.

### 4. Complete

After accepting the plan, exit Plan mode:

```text
Complete #NN
```

This marks the Issue `In Progress`, implements the bounded plan, verifies and remediates in-scope failures, cleans the intended diff, and creates one local commit. It never pushes, merges, or deploys.

Near the start, map every acceptance criterion to its governing authority, required behavior and actions, reachable UI or output, and proportionate evidence. Maintain that map as findings change.

Before success, classify every criterion as `Verified`, `Partial`, or `Unverified` with current evidence. Anything not `Verified` keeps the Issue `In Progress` and blocks publication, merge, closure, and `Done`.

### 5. Publish

```text
Publish #NN
```

- Solo: freshly verify and push the accepted commit range from the primary `main` checkout.
- Concurrent: push the Issue branch and open one PR containing `Closes #NN`.

Keep the Issue `In Progress` until required CI passes for the exact published commit or PR head. Revalidate the all-`Verified` ledger, update only evidence-backed checkboxes, leave a compact evidence record, and close or merge last. `Publish #NN` never authorizes deployment or PR merge.

If CI fails, diagnose first:

```text
Re-anchor and diagnose the failed Publish #NN verification.

Inspect the owning Issue, published commit, failed TALA CI run, and any existing
branch or PR. Report the exact cause and smallest correction.

Read-only. Do not create replacement tracked work, edit files, merge, close,
or deploy.
```

After the diagnosis is accepted:

- Solo: authorize one bounded corrective commit on `main`, then separately republish it.
- Concurrent: authorize the correction on the same Issue branch, then update the existing PR.

## You plus another developer

Before activating a second implementation Issue, verify required PR CI and protection of `main`. Derive no more independent Issues than the stated owner capacity.

```text
For this derivation batch, we are working with one additional developer.

Developer:
GitHub username: [username]
Available for: [work area]
Capacity: one active Issue

Derive the next parallel-safe TALA implementation Issue batch from the approved
coordination map. Verify dependency readiness, ownership, shared seams, workspace
isolation, CI, and main protection. Return fewer drafts when safe concurrency
cannot be proven; keep each draft journey-complete.

Read-only. Do not create Issues, branches, Project changes, or other writes.
```

When planner and implementer differ, put the accepted plan or concise execution handoff in or durably link it from the owning Issue. In the assigned developer's Codex task, use Default mode:

```text
Complete #NN as the assigned implementation owner.

You are the implementer for this Issue, not the primary TALA orchestrator.
Read AGENTS.md, the protocol, owning Issue, and accepted plan or handoff. Re-anchor
from live Git and GitHub state. Verify assignment, dependencies, and workspace
isolation, then create or switch to the Issue branch from accepted, up-to-date
main. Remain within this Issue's scope.

Use applicable project skills and tools. Do not derive or reorder other work,
modify coordination, work directly on main, publish, merge, or deploy. Finish
only through the verified bounded local commit.
```

Use one owner, Issue-specific branch, PR, and isolated workspace/database per active Issue. Dependent work waits for its prerequisite PR to merge unless an explicit stacked-branch plan is approved. Open PR review remains `In Progress`; merge requires separate authorization.

## Work without an Issue

Clear direct work remains valid and has no GitHub Project status.

| Request | Allowed result |
| --- | --- |
| Review, diagnose, or plan | Read-only |
| Implement, fix, change, build, or proceed | Bounded local edits and verification; no commit |
| Explicitly complete or commit | Bounded work, verification, and one local commit |
| Explicitly publish an accepted commit or range | Bounded push; no deployment |

State the target and external effect plainly. Never infer permission to commit, push, merge, deploy, or mutate GitHub.

## Compaction and re-anchoring

The protocol requires re-anchoring after compaction or resumption; it is not a guaranteed Codex product hook. Stay in the current mode, reconstruct the last proven checkpoint from recent original messages and durable live evidence, then continue only within the existing permission boundary.

Use this fallback when the agent appears confused:

```text
Re-anchor and continue the currently authorized TALA task.

Active boundary: [Plan #NN, Complete #NN, Publish #NN, or untracked task]
Previous task: [task ID if available]

Determine what completed, what is running, what remains, and the current
authorization from recent original messages and durable live evidence. Continue
from the last proven checkpoint. Do not repeat completed mutations, broaden
scope, publish, merge, deploy, or begin another Issue. Stop if material state
cannot be proven safely.
```

Compaction never expands authority.

## Statuses

| Status | Meaning | Set by |
| --- | --- | --- |
| `Todo` | Approved implementation Issue exists but is inactive | GitHub automation |
| `In Progress` | Implementation, open-PR review, or coordination is active | Codex or authorized coordination setup |
| `Done` | Work completed its approved publication or merge path | GitHub automation after closure or linked merge |
| `Canceled` | Work intentionally stopped or superseded with a recorded reason | Codex after explicit authorization |

Do not create another Project status or a local shadow queue.

## Finish the coordination cycle

When all accepted journeys appear covered, use Plan mode:

```text
Perform the final integrated acceptance audit for coordination Issue #NN.

Read the coordination map, linked Issues, Project statuses, merged PRs or
published commits, canonical authority, current main, and current automated and
browser evidence. Verify journey coverage, dependencies, cross-role behavior,
outputs, state transitions, and required canonical coverage.

Read-only. Do not close or modify coordination, create Issues, change files,
commit, publish, merge, or deploy.
```

If a required gap exists, return it to the normal Issue loop. If the audit passes, separately authorize coordination closure. Start a successor coordination Issue only for materially new approved work after closure.
