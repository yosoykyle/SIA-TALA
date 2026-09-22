---
name: "Task / Feature / Bug Fix"
about: "Track any implementation slice, feature refinement, bug fix, tooling, or documentation task"
title: "[type](scope): [Brief description of task]"
labels: ["implementation"]
---

<!-- 
TALA UNIVERSAL ISSUE TEMPLATE
Governed by AGENTS.md, CONTRIBUTING.md, and the TALA Orchestrator Protocol.
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

## Acceptance Criteria
<!-- Testable, unambiguous conditions of satisfaction. -->
- [ ] Criterion 1: 
- [ ] Criterion 2: 

## Verification Plan
<!-- How will you verify this work? -->
- [ ] **Automated Tests**: (`php artisan test --compact --filter=ExampleTest`)
- [ ] **Code Formatting**: (`vendor/bin/pint --format agent`)
- [ ] **Clean Diff**: Working tree clean, touches only in-scope files

## Boundaries
- **Execution Boundary**: `LOCAL_EXECUTION` for development and 1 local commit.
- **Publication**: Do not push directly to `origin/main` if working concurrently. Follow PR workflow with `Closes #NN`.
- **Database Safety**: Never run tests or migration resets against `tala_db`. All automated tests target `test_tala_db`.
