# TALA Project Documents Index

This folder is split by document purpose so the main directory stays readable.

## Follow These First

| Purpose | Document |
| --- | --- |
| Functional source of truth | [TALA-Functional-Specification.md](./TALA-Functional-Specification.md) |
| Technical source of truth | [TALA-Technical-Specification.md](./TALA-Technical-Specification.md) |
| Workflow-to-spec/code traceability and reconciliation status | [TALA-Workflow-Reconciliation-Matrix.md](./TALA-Workflow-Reconciliation-Matrix.md) |
| Active SDD execution map | [TALA-SDD-Execution-Map.md](./TALA-SDD-Execution-Map.md) |
| Local execution tracker | [TALA-Local-Iteration-Checklist.md](./TALA-Local-Iteration-Checklist.md) |
| Migration/schema wave control | [TALA-Foundation-Migration-Control-Log.md](./TALA-Foundation-Migration-Control-Log.md) |
| Tech stack and package rationale | [TECH_STACK_SUMMARY.md](./TECH_STACK_SUMMARY.md) |
| Feature/capability quick reference | [TALA-Module-Features-Capabilities.md](./TALA-Module-Features-Capabilities.md) |
| Client-expected features and business flows for a new system | [Client-Expected-Features-and-Business-Flows.md](./Client-Expected-Features-and-Business-Flows.md) |
| Top-level architecture | [TALA-System-Architecture-Top-Level.md](./TALA-System-Architecture-Top-Level.md) |

## Execution Packages

Use these only when running QA, UAT, or launch preparation.

| Purpose | Folder |
| --- | --- |
| Pre-UAT audit, spin-up, developer QA, UAT signoff, go-live runbook | [uat-readiness/](./uat-readiness/) |

## Business Evidence

Raw converted evaluation, SOA, SHS template, and legacy grade evidence is kept here. It is not deleted because it supports migration/import decisions.

| Purpose | Folder |
| --- | --- |
| SIA evaluation forms, legacy finance sheets, legacy grade samples | [business-evidence/](./business-evidence/) |

## Archived Material

Use archived material only for history, not as the current implementation guide.

| Purpose | Folder |
| --- | --- |
| Old progress artifacts and one-off helper reports | [archive/project-progress/](./archive/project-progress/) |
| Historical approved rescue plan | [archive/project-progress/TALA-Rescue-Plan.md](./archive/project-progress/TALA-Rescue-Plan.md) |
| Older research/prototype/system proposal archive | [archive/](./archive/) |
| Raw source files retained from earlier consolidation | [archive/raw-source-files/](./archive/raw-source-files/) |

## Rule

The consolidated `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` is the newest client-approved business baseline. FS/TS remain the normative system specification after feature-level reconciliation: preserve compatible stronger controls, reopen only exact conflicts or gaps, benchmark material rules, and record the result in the Workflow Reconciliation Matrix before implementation. The SDD map controls dependency order, while the local checklist and Linear mirror execution state. Archived refinement lists and previous grilling-generated iterations are historical unless a specific item has been re-entered into the active SDD map or its Linear child issue.
