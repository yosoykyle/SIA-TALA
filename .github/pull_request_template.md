<!-- 
TALA PULL REQUEST TEMPLATE
Governed by AGENTS.md, CONTRIBUTING.md, and the TALA Orchestrator Protocol.
-->

## Issue Linkage
<!-- 
MANDATORY: Link the owning GitHub Issue. 
Using "Closes #NN" ensures GitHub automatically links this PR to the issue, 
keeps it in "In Progress" during review, and moves it to "Done" in GitHub Projects upon merge.
-->
Closes #

## Change Classification
<!-- Select the classification that applies to this pull request: -->
- [ ] **Coordination-Derived Implementation Slice** (Native sub-issue from active coordination map)
- [ ] **Standalone Work** (Internal DX, tooling, documentation, or infrastructure)
- [ ] **Bug Fix / Defect Remediation** (Resolving a defect with regression test coverage)
- [ ] **Maintenance / Non-Functional** (Refactoring, dependency upgrades, cleanups)

## Summary of Changes
<!-- Provide a concise summary of what was accomplished in this PR. -->
- 

## Governing Specifications & Target Surfaces
<!-- Cite applicable governing specifications, requirements, and target surfaces or role views: -->
- **Governing Specification / Requirement**: [Link or cite relevant PRD, policy, or spec]
- **Target Surfaces / Role Views**: [Relevant screens, views, components, or IDs if applicable]
- **Architecture Boundaries**: [Relevant architecture specifications or integration boundaries]

## Pre-Merge Verification Checklist
<!-- All items must be completed before requesting review or human merge authorization: -->
- [ ] **Automated Tests**: All unit and feature tests pass against `test_tala_db` (`php artisan test --compact`).
- [ ] **Code Formatting**: Code passes Laravel Pint (`vendor/bin/pint --format agent`).
- [ ] **Browser Qualification**: Rendered interactions verified on port 8008 (`php -S 127.0.0.1:8008 -t public`) if UI-bearing.
- [ ] **Clean Diff**: Working tree is clean and diff contains only in-scope target files.
- [ ] **Acceptance Ledger**: Every criterion in the owning Issue's acceptance criteria is marked `Verified` with direct evidence.
- [ ] **Branch Currency**: Branch is strictly up-to-date with `origin/main` (satisfying `required_status_checks.strict: true`).

---

> [!IMPORTANT]
> ### 🛡️ Human Lead Merge Gate
> Under the **TALA Orchestrator Protocol**, Pull Requests are **NEVER auto-merged**.
> Even when GitHub Actions CI checks pass completely, merge requires the **Human Project Owner's (`@yosoykyle`) explicit authorization and manual merge action**.
> The linked issue and GitHub Project item will remain **`In Progress`** until the PR is merged into `main`.
