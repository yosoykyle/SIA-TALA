# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. We plan the next batch of issues and their descriptions here.
  2. We take action and implement the issues.
  3. **Important:** Issues are only moved to `TALA-Local-Linear-Sync-Tracker.md` for syncing after **all** the planned issues/steps in the current batch are fully completed. It will not be moved if just one issue is done.
  4. The completed batch of issues is then removed from this planning document.

## Source-of-Truth Order

Use this order before implementing each slice:

1. `00_Project_Documents/prd_modules/README.md`
2. `00_Project_Documents/prd_modules/` (All relevant modules inside this directory)
3. `00_Project_Documents/ui_surface_blueprint.md`
4. `00_Project_Documents/architecture_specification.md`
5. Existing code and tests

## Research and Tool-Use Order

Apply this order to every planned worker slice:

1. Read the relevant source-of-truth documents, schema contract, current migrations, and existing implementation before deciding the change.
2. Use Laravel Boost `application-info` and version-specific `search-docs` before Laravel ecosystem code changes.
3. When an important technical, integration, or repository question remains unanswered, use the relevant available MCP, connector, or specialized tool before making an assumption.
4. Use authoritative internet research when an institutional policy, Philippine regulatory requirement, external integration contract, current standard, or mature-system benchmark remains unclear. Prefer primary official sources and record the supporting links in the worker report.
5. Research resolves gaps but does not override an approved PRD decision or expand the MVP. If authoritative evidence conflicts with the approved flow or would materially change scope, stop and report the conflict to the primary thread for a decision.
6. Implement only after the required questions are resolved, then run the slice's focused tests and regression checks.

## Planned Issues

### TAL-70 — COR / Official Generated Output Foundation

Plan and implement the MVP COR foundation as a native Laravel and Filament output flow.

Scope:

1. Replace the Student Hub COR shell with a current active COR generated read-only page that resolves the authenticated student's official enrollment, published schedule rows, active assessment, posted ledger balance, and blocking COR holds.
2. Add an authenticated Laravel printable Blade route for COR output with `@media print` CSS and a Filament `Action` that opens it in a new tab for browser print/save-as-PDF.
3. Record COR view and print/save actions in `output_access_logs` using the clean migration contract.
4. Add a focused staff-accessible read-only path or action from the enrollment/source record for Registrar and Accounting review without reviving public verification.
5. Cover allowed student access, blocked student access, staff access, and output logging with focused PHPUnit feature tests.

Exclusions:

1. No server-generated PDF package.
2. No stored generated COR file.
3. No public QR, token, or unauthenticated COR verification.
4. No TAL-71 or downstream SOA/payment acknowledgement implementation beyond preserving their shared output-log contract.
