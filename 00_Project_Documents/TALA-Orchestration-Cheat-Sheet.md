# TALA Orchestration Cheat Sheet

Use this quick reference to choose the next authorized action. The [TALA Orchestrator Protocol](TALA-Orchestrator-Protocol.md) owns the workflow. The [developer playbook](developer-guides/tala-workflow-playbook.md) gives practical task, publication, and recovery examples.

## Choose the boundary

| Request | Boundary | Result |
|---|---|---|
| Derive, plan, review, audit, diagnose | `READ_ONLY` | Findings, contract, or decision-complete plan |
| Implement, fix, change, proceed | `LOCAL_EXECUTION` | Bounded edits and task-applicable verification |
| Create/update an accepted Issue | Explicit external-write authorization | The specified task record is saved and read back |
| Complete | `COMPLETION_AND_PUBLISH` | All criteria Verified, fresh verification, one bounded local commit |
| Publish | `COMPLETION_AND_PUBLISH` | Accepted commit range published through the applicable solo/PR path; required CI verified on the exact revision |
| Merge, deploy, or close coordination | Explicit authorization for that effect | The specified terminal action after its gates pass |
| Re-anchor or resume | Retained authorized boundary | Current authority and state checked, then unfinished authorized work continued |

The owner's stated scope and exclusions govern the request. Boundaries define permissions, not required separate turns: reuse authorization already given and continue to its named endpoint. Product decisions explicitly delegated to the orchestrator are assessed and recorded in their owning authority, then reconciled with affected task records.

## Continue a child task

1. Read the Issue, durable handoff, relevant authority, Git state, and current evidence.
2. Use a sufficient accepted Issue contract or linked plan; plan separately only for a missing or materially changed decision. Check only task-applicable prerequisites.
3. Name the assigned executor, bounded scope, actual delivery method, and material safeguards. UI work identifies the matching accepted Impeccable brief.
4. Execute within the authorized boundary and return criterion-level evidence.
5. Independently review the result. Complete and publish under their corresponding authorization, which may be given in one request. Maintain one acceptance ledger and reuse valid evidence.

The owner selects the environment and recipient. Orchestration counterparts coordinate and review; an assigned executor implements and verifies. Platform and session details belong to the task handoff.

## Name the endpoint once

For a settled Issue, choose the effects you want:

- Local only: `Implement #NN under its accepted contract; verify and fix in-scope failures. Do not commit or publish.`
- Complete and publish: `Complete and publish #NN under its accepted contract. Include necessary concurrent isolation setup, verification, and in-scope CI remediation with bounded corrective commits/pushes. Do not merge, deploy, or broaden scope.`

A direct untracked request names its outcome and scope instead of an Issue number. Stop only for an unresolved material decision, safety conflict, or missing authorization; do not invent another approval for routine execution or checks.

## Preserve scope and evidence

Use the existing coordination register for system concerns and stable identifiers. Expand the owning concern before adding a new one. Derive only the next dependency-ready bounded task. Keep the actual shared task state in GitHub Issues and Project views.

Inspect and preserve unrelated edits. Solo work uses the primary checkout on `main`. Concurrent work uses the protocol's isolated Issue workspace, branch, database, and PR arrangement. Stage only the accepted manifest at completion.

Verification follows the change: document consistency/diff/format checks for documentation, affected tests for code, and representative rendered checks for UI. Each criterion has current evidence and a `Verified`, `Partial`, or `Unverified` result; completion requires all criteria Verified. Tests and CI support their covered behavior. Independent review assesses product conformance and usability.

## Publication and recovery

Solo publication pushes the accepted range to `origin/main`; required CI and refreshed acceptance evidence precede authorized closure. Concurrent publication opens a linked PR; required CI and review precede separately authorized merge. Deployment has its own authorization.

A diagnose-only request is read-only. Within an execution assignment, inspect and fix in-scope failures, then rerun affected checks without another approval. Commit and publish corrections only when those effects are explicitly included, reusing authorization already given. Keep the same task and history. Follow required CI to its outcome with bounded waits/checks and report meaningful changes.

## Resume after interruption

Read recent owner instructions and refresh volatile Issue, Git, and evidence state. Retain the accepted decisions and permission boundary. Resolve a material conflict in its owning authority before affected execution. Routine implementation detail stays with the assigned executor.
