---
name: "Task / Feature / Bug Fix"
about: "Track any implementation slice, feature refinement, bug fix, tooling, or documentation task"
title: "[type](scope): [Brief description of task]"
labels: ["implementation"]
---

<!-- 
TALA UNIVERSAL ISSUE TEMPLATE
Governed by AGENTS.md and the TALA Orchestrator Protocol; CONTRIBUTING.md is setup guidance.
A sufficient accepted Issue contract serves as the plan. Delete inapplicable verification sections and link existing decisions/briefs instead of duplicating them.
-->

## Outcome
<!-- What specific user, academic, or technical capability will be delivered? -->

## Context & Parent Relationship
<!-- Is this part of an active coordination milestone/epic, or standalone? -->
- **Parent Coordination Issue (if applicable)**: #
- **If Standalone (no parent epic)**: [State 1-sentence rationale why no active coordination epic owns it, e.g. "Post-milestone refinement / internal tooling / doc update"]

## Governing Specifications & Target Surfaces (if applicable)
- **Governing Specification / Requirement**: [Link or cite relevant PRD, policy, or spec]
- **Target Surfaces / Role Views**: [Relevant screens, views, components, or files]

## Scope & Exclusions
### In-Scope
- 

### Out-of-Scope (Preserved Surfaces)
- 

## Dependencies & Material Decisions
<!-- Link prerequisites and accepted material decisions when applicable. Routine implementation choices need no extra approval. -->
- **Dependencies / Readiness**:
- **Accepted Decision or Brief References (if applicable)**:
- **Unresolved Material Decisions / Stop Conditions (if any)**:

## Acceptance Criteria
<!-- Follow the Protocol's Practical acceptance criteria section. Use a short set of distinct, understandable task outcomes and essential safeguards within this scope. Confirm each has feasible, proportionate evidence. Avoid arbitrary scores, exhaustive matrices and implementation checklists; keep optional refinements separate. -->
- [ ] Criterion 1: 
- [ ] Criterion 2: 

## Verification Plan
<!-- Choose the simplest sufficient evidence for the changed outcomes. Reuse existing meaningful tests; select representative UI checks and high-value failure paths. Documentation uses consistency/diff/link checks. Behavior changes use affected tests (php artisan test --compact --filter=ExampleTest); format PHP with vendor/bin/pint --dirty --format agent. Pure copy/layout changes need relevant rendered checks, without mandatory new automated tests. No repeated full-suite runs or duplicate evidence ledgers. -->
- **Outcome evidence**: [Name the existing tests, document review or representative rendered/manual journey checks that establish the criteria.]
- **Important failure / recovery / integration checks (if applicable)**: [Name only the meaningful risks introduced or affected by this change.]

## Boundaries

Follow [AGENTS.md](../../AGENTS.md) and the [TALA Orchestrator Protocol](../../00_Project_Documents/TALA-Orchestrator-Protocol.md) for permissions, completion, publication, and recovery. An assignment may explicitly include multiple effects in one request; Issue creation or assignment alone grants no implementation, commit, push, merge, or deployment.

Automated database tests target disposable `test_tala_db`, never `tala_db`. Documentation-only work requires no database or application-test run. Every acceptance criterion must be Verified before completion; required CI must pass on the exact published revision.

Criteria and agent checks establish implementation readiness. The owner, actual users and client judge usability and fitness through the Protocol's existing review process.
