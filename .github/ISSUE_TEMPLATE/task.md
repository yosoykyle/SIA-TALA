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
<!-- A small set of observable outcomes that define satisfaction. Keep criteria outcome-based, not prescriptive implementation steps. -->
- [ ] Criterion 1: 
- [ ] Criterion 2: 

## Verification Plan
<!-- Map each acceptance outcome to suitable evidence. Keep applicable sections only; reuse sufficient existing tests and brief/state contracts. -->

### Documentation-Only Changes
- [ ] **Document Consistency**: Authority consistency and contradiction review
- [ ] **Intended Diff & Formatting**: Touches only in-scope docs with no extraneous edits, valid Markdown/links

### Code & Backend Changes
- [ ] **Automated Tests**: Affected unit/feature tests pass on `test_tala_db` (`php artisan test --compact --filter=ExampleTest`)
- [ ] **Code Formatting**: Clean Pint formatting (`vendor/bin/pint --dirty --format agent`)
- [ ] **Clean Diff**: Touches only in-scope files with no extraneous edits

### UI-Bearing & End-to-End Changes
- [ ] **Representative Rendered/Browser Check**: Responsive layouts, empty/loading/error states, keyboard/screen-reader accessibility
- [ ] **Integration/Multi-Role Check**: External service or cross-role workflow verified if applicable

## Boundaries

Follow [AGENTS.md](../../AGENTS.md) and the [TALA Orchestrator Protocol](../../00_Project_Documents/TALA-Orchestrator-Protocol.md) for permissions, completion, publication, and recovery. An assignment may explicitly include multiple effects in one request; Issue creation or assignment alone grants no implementation, commit, push, merge, or deployment.

Automated database tests target disposable `test_tala_db`, never `tala_db`. Documentation-only work requires no database or application-test run. Every acceptance criterion must be Verified before completion; required CI must pass on the exact published revision.
