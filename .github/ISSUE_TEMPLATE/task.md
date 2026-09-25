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
<!-- A small set of observable outcomes that define satisfaction. Keep criteria outcome-based, not prescriptive implementation steps. -->
- [ ] Criterion 1: 
- [ ] Criterion 2: 

## Verification Plan
<!-- Task-applicable verification proportionate to the change type. Select and fill applicable items only. -->

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
- **Local Execution Boundary**: `LOCAL_EXECUTION` authorizes bounded file edits, running tests, fixing in-scope failures, and Pint formatting. It strictly **prohibits** creating git commits, pushing, or branch mutations.
- **Completion Gate**: `Complete #NN` (under `COMPLETION_AND_PUBLISH`) authorizes creating exactly **ONE** bounded local commit only after all acceptance criteria are `Verified` with task-applicable evidence.
- **Publication Boundary**: `Publish #NN` authorizes pushing approved commits (solo work directly to `origin/main`; concurrent work via Issue branch and PR with `Closes #NN`). Required CI checks on GitHub must pass before closure or merge.
- **Database Safety**: Automated tests target disposable `test_tala_db`, never `tala_db`. (Documentation-only changes do not require a database run).
