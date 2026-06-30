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

TAL-71 Finance Outputs and Student Hub Finance is completed locally and recorded in the local sync tracker.

### Next Unopened Boundary

TAL-72 Grades MVP is completed locally and recorded in the local sync tracker.

TAL-73 Progression and Student Lifecycle MVP is completed locally and recorded in the local sync tracker.

TAL-74 Graduation and Completion Review MVP is completed locally and recorded in the local sync tracker.

### TAL-75 Reports, Audit, and Export MVP

#### TAL-75A - Planning Contract

Status: planned here only. TAL-75A defines the next bounded implementation issue and does not create code, migrations, resources, tests, commits, external sync, or local-sync tracker rows.

Intent: give authorized staff the first MVP Reports, Audit, and Export surface after the upstream Registrar, Accounting, Academic Head, scheduling, grades, lifecycle, graduation, COR, and finance-output source records are stable. The implementation must be lean: fixed role-scoped operational report tables with logged CSV exports, not a report builder, analytics platform, BI dashboard, or custom charting layer.

MVP flow:

1. Authorized staff opens a role-scoped Reports / Audit area inside the existing `/admin` staff workspace.
2. The user selects a fixed report table they are authorized to see.
3. Native filters constrain source records by controlled fields such as term, program, section, status, date range, actor, output type, sensitivity, or source record.
4. The table remains read-only against the owning source records. Corrections happen only through the owning workspace.
5. CSV export uses the same scoped query and excludes hidden/private fields by default.
6. Sensitive exports require a purpose before export.
7. Every report export writes `output_access_logs` with `output_type = REPORT`, `action = EXPORT`, source report identity, actor, actor role, filter summary, row count, purpose when sensitive, sensitivity, request context, status, and timestamp. The Module 13 `report_export_log` contract is implemented through the clean consolidated `output_access_logs` schema rather than by adding another table.

#### TAL-75B - Implementation Contract

Scope: implement the fixed MVP reports/audit/export surface only.

Required staff surfaces:

1. Registrar report tables:
   - Enrollment Master List.
   - Capacity Pending List.
   - Section Capacity Summary.
   - Student Lifecycle Change Register.
   - Graduation Review Batch List.
   - Graduation Eligibility Snapshot Export.
2. Accounting report tables:
   - Daily Cash Collection / Daily Turnover.
   - Reconciliation Exception Report for pending OR mapping.
   - Student Ledger Statement.
   - Term Fee Summary.
   - Financial Accommodation List without private certification evidence by default.
3. Academic Head report tables:
   - Faculty Load Report.
   - Scheduling Exception Report.
   - Faculty Term Load Override Report.
   - Academic Progression Exception Report.
   - Grade Correction Audit Log.
   - Pending Grade List, INC Completion / Removal List, Late Grade Encoding Authorization List, and Student Unit Load Exception List where the TAL-72 to TAL-74 source records exist.
4. System Super Admin / audit tables:
   - User and Role Report.
   - Activity / audit log visibility using `activity_log` and the existing activity-log resource pattern.
   - Generated Output Access Audit from `output_access_logs`.
   - Report Export Audit from `output_access_logs` filtered to `output_type = REPORT` and `action = EXPORT`.
   - Integration Event Log from `operational_events` where present, with PayMongo webhook evidence from the existing webhook source where needed.

Implementation guardrails:

- Use native Filament v5 resources/pages/tables, filters, actions, and authorization. Actions must use `Filament\Actions\*`; filters use `Filament\Tables\Filters\*`.
- Prefer table filters plus a focused CSV export action over new report models.
- Do not add report-builder tables, saved-filter plugins, dashboard/chart libraries, custom JavaScript, BI views, or new package dependencies.
- Reuse `output_access_logs` for export evidence. Do not create a separate `report_export_logs` table unless the primary thread explicitly overturns the clean schema contract.
- Keep Student Hub out of TAL-75 except already-safe generated outputs from TAL-70 and TAL-71. Students do not receive report/audit pages.
- Keep source tables authoritative. Report pages must not mutate enrollments, ledger rows, grades, lifecycle changes, schedules, graduation snapshots, settings, imports, activity logs, webhook logs, operational events, or output logs.
- Sensitive exports must capture purpose and sensitivity. Finance, student data, generated output access, report export audit, and document/access audit exports are sensitive by default.
- Hidden/private fields are excluded by default, including staff-only notes, private evidence references, certification evidence, raw webhook payloads, secrets, tokens, and internal diagnostics unless a specific authorized audit report requires a minimal diagnostic summary.

Verification expectation for TAL-75B:

1. Prove the test runtime is `testing`, MySQL, and `test_tala_db`, and not `tala_db`, before DB-backed tests.
2. Add focused PHPUnit feature tests for authorization, role-scoped table visibility, read-only source behavior, filter behavior, CSV export response, required purpose capture for sensitive exports, `output_access_logs` export rows, row count, filter summary, hidden-field exclusion, and no Student Hub exposure.
3. Run the affected tests sequentially if the shared MySQL test database shows reset or seed contention.
4. Run `vendor/bin/pint --dirty --format agent` after PHP edits, then focused PHPUnit, relevant PHPStan if touched surfaces require it, and `git diff --check`.
5. Include route/resource exposure evidence for `/admin` report/audit surfaces and confirm no public report/export routes were introduced.

Explicit exclusions:

- No TAL-75B implementation during TAL-75A.
- No report builder, configurable report designer, ad hoc query builder, analytics platform, BI dashboard, chart package, or broad dashboard expansion.
- No server-generated PDF report packs, stored report files, retained generated exports, public verification, QR/token lookup, or download center.
- No Student Hub report/audit/export area.
- No retention disposal workflow automation, notification system expansion, in-app notification center, or new dependencies/plugins/custom JavaScript.
- No schema changes unless TAL-75B first proves an unavoidable mismatch against the clean migration contract and stops for primary-thread approval.
- No edits to `00_Project_Documents/TALA-Local-Linear-Sync-Tracker.md` until the TAL-75 implementation batch is complete and ready for sync bookkeeping.

#### TAL-75C - Cleanup and Handoff

After TAL-75B passes focused verification, TAL-75C should be a cleanup-only boundary: inspect the TAL-75B diff, remove this active plan, add the compact TAL-75 tracker row only if the implementation batch is complete, and hand off exact changed files, test commands, DB proof, exclusions preserved, and any deferred reports.
