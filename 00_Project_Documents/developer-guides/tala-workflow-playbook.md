# TALA Developer Workflow Playbook

This guide gives practical examples of the [TALA Orchestrator Protocol](../TALA-Orchestrator-Protocol.md). The protocol owns authorization, task lifecycle, evidence, and publication rules. The owner selects the environment and executor; the orchestrator coordinates decisions and the independent reviewer assesses the result.

## Continue an existing task

1. Read the owning Issue, accepted plan and handoff, relevant canonical documents, current Git state, and existing evidence. Identify the authorized boundary and preserve unrelated work.
2. Reuse a sufficient accepted Issue contract or linked plan and matching UI brief. Check only task-applicable prerequisites. Resolve a material change through the owning authority and task record before affected implementation.
3. Under an authorized `LOCAL_EXECUTION` assignment, implement and verify the bounded scope. For UI work, use the applicable Impeccable brief, framework documentation, and representative rendered checks.
4. Return evidence for each acceptance criterion, including failure and recovery behavior. The independent reviewer checks conformance and usability against the accepted contract.
5. Under explicitly authorized `Complete #NN`, satisfy the completion gates and create the single bounded local commit. Under `Publish #NN`, follow the applicable publication path below. These effects may be authorized together in one request; do not stop for another approval already given.

These steps are continuous work within the named authorization, not required separate owner turns. A Complete request may finish remaining bounded implementation without a prior Implement turn. Maintain one acceptance ledger and reuse current evidence rather than repeat completed steps. Routine implementation choices stay with the assigned executor. Product decisions explicitly delegated to the orchestrator are resolved, recorded in their owning authority, and included in the handoff. A task assignment names its actual recipient and delivery method.

## Choose the workspace

| Situation | Workspace and delivery |
|---|---|
| Solo tracked work | Existing primary checkout on `main`; bounded local edits, then authorized completion and direct publication with required CI on the published commit |
| Concurrent tracked work | Issue-specific isolated checkout/branch under authorized setup; publish a pull request linking the owning Issue, with required CI and separately authorized merge |
| Clear direct work | Existing primary checkout; follow the explicit requested boundary. Read-only investigation produces findings; authorized local edits produce a reviewable diff. Commit and publication require their corresponding authorization |

Inspect the current branch, worktrees, and unrelated edits before setup. Selectively stage the exact accepted manifest at completion. Preserve pending documents, application work, tests, references, and other contributors' changes.

## Record a newly discovered concern

Check the active coordination register and owning Issue first. Expand an existing concern when it already owns the outcome. Record genuinely missing work with a stable identifier in the authorized coordination update.

For a new bounded implementation task, derive a contract with outcome, scope, authority, dependencies, acceptance criteria, and verification. Present the concrete contract under `READ_ONLY`. Create the Issue only when authorized, using the repository's [task template](../../.github/ISSUE_TEMPLATE/task.md). Link coordinated work to its active parent; explain standalone work in the contract. Reuse the accepted plan when the created contract matches it.

## Publish and review

For solo publication, push the accepted commit range to `origin/main` after the protocol's fresh local preflight. Verify required CI on the exact published commit, refresh acceptance evidence and Issue state, then perform an authorized closure.

For concurrent publication, push the Issue branch and open a pull request with `Closes #NN`. Follow required CI to its outcome with bounded waits/checks, then present the review link and evidence to the owner. A separately authorized merge follows successful required CI and resolved review. Send a message to another person or session only when the owner authorizes that delivery.

CI provides build and automated-test evidence. Semantic acceptance also requires the owning criterion evidence and, for UI changes, representative rendered checks. The [CI workflow](../../.github/workflows/ci.yml) defines its current commands and environment.

## Recover from a failed check

Read the failing output and identify the affected criterion. A `diagnose` request is read-only. In-scope repairs are part of an authorized execution assignment: preserve unrelated changes, fix the owned failure, rerun affected checks, and return revised evidence for review. If the request already includes corrective commits/publication, perform those effects without another owner turn after their gates pass; otherwise request only the missing effect.

Keep the task's existing Issue and history when fixing its own verification failure. Refresh stale premises before acting, and report an external limitation with the exact remaining criterion. Use bounded status checks when following an executor or CI run; report meaningful changes.

## Resume after interruption

Re-anchor from recent owner instructions, current authority, the owning task records, Git state, and existing evidence. Continue within the retained authorization. Re-plan only when a material premise changed. The [orchestration quick reference](../TALA-Orchestration-Cheat-Sheet.md) summarizes the protocol's commands and boundaries.
