# TALA Project Documents Index

This folder is split by document purpose so the main directory stays readable.

## Follow These First

| Purpose | Document |
| --- | --- |
| Functional source of truth | [TALA-Functional-Specification.md](./TALA-Functional-Specification.md) |
| Technical source of truth | [TALA-Technical-Specification.md](./TALA-Technical-Specification.md) |
| Workflow-to-spec/code traceability and reconciliation status | [TALA-Workflow-Reconciliation-Matrix.md](./TALA-Workflow-Reconciliation-Matrix.md) |
| Benchmark baseline for actionable FS/TS final-form scope | [TALA-SIS-Benchmark-Baseline-Matrix.md](./TALA-SIS-Benchmark-Baseline-Matrix.md) |
| Repeatable process for benchmarking and hardening FS/TS by feature group | [TALA-Specification-Benchmarking-Process.md](./TALA-Specification-Benchmarking-Process.md) |
| Active SDD execution map | [TALA-SDD-Execution-Map.md](./TALA-SDD-Execution-Map.md) |
| Local execution tracker | [TALA-Local-Iteration-Checklist.md](./TALA-Local-Iteration-Checklist.md) |
| UAT rescue scope-freeze tracker | [TALA-UAT-Rescue-Plan-2026-06-21.md](./TALA-UAT-Rescue-Plan-2026-06-21.md) |
| Migration/schema wave control | [TALA-Foundation-Migration-Control-Log.md](./TALA-Foundation-Migration-Control-Log.md) |
| Tech stack and package rationale | [TECH_STACK_SUMMARY.md](./TECH_STACK_SUMMARY.md) |
| Feature/capability quick reference (Secondary/Historical; defer to FS/TS) | [TALA-Module-Features-Capabilities.md](./TALA-Module-Features-Capabilities.md) |
| Top-level architecture | [TALA-System-Architecture-Top-Level.md](./TALA-System-Architecture-Top-Level.md) |

## Execution Packages

Use these only when running QA, UAT, or launch preparation.

| Purpose | Folder |
| --- | --- |
| Pre-UAT audit, spin-up, developer QA, UAT signoff, go-live runbook | [uat-readiness/](./uat-readiness/) |

## Business Evidence

Use this folder for the active College-only institutional workflow and current College evidence. SHS-specific source files were moved out of active evidence because SHS is no longer offered in the target deployment. Grade 12/Form 138/Form 137 records may still appear in the active workflow only as prior-education admission evidence for College applicants.

| Purpose | Folder |
| --- | --- |
| Active College workflow, College evaluation forms, College finance sheets, College grade samples | [business-evidence/](./business-evidence/) |

## Archived Material

Use archived material only for history, not as the current implementation guide.

| Purpose | Folder |
| --- | --- |
| Old progress artifacts and one-off helper reports | [archive/project-progress/](./archive/project-progress/) |
| Historical approved rescue plan | [archive/project-progress/TALA-Rescue-Plan.md](./archive/project-progress/TALA-Rescue-Plan.md) |
| Historical client prototype walkthrough | [archive/CLIENT_PROTOTYPE_WALKTHROUGH.md](./archive/CLIENT_PROTOTYPE_WALKTHROUGH.md) |
| Deprecated SHS scope evidence retained for history only | [archive/deprecated-shs-scope-2026-06-21/](./archive/deprecated-shs-scope-2026-06-21/) |
| Older research/prototype/system proposal archive | [archive/](./archive/) |
| Raw source files retained from earlier consolidation | [archive/raw-source-files/](./archive/raw-source-files/) |

## Rule

The consolidated `business-evidence/INSTITUTION WORK  FLOW CURRENT.md` is the newest client-approved business baseline. FS/TS remain the normative system specification after feature-level reconciliation: preserve compatible stronger controls, reopen only exact conflicts or gaps, benchmark material rules, and record the result in the Workflow Reconciliation Matrix before implementation. The SDD map controls dependency order, while the local checklist and Linear mirror execution state. Archived refinement lists and previous grilling-generated iterations are historical unless a specific item has been re-entered into the active SDD map or its Linear child issue.
