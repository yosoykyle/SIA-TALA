# TALA Local Linear Sync Tracker

## Purpose

This document is the local staging area for completed issues awaiting explicit user-authorized Linear synchronization, and a compact reference for issues already synced.
- After primary verification, record completed work under Active Syncs as `Done locally; pending explicit Linear sync` and create its bounded local Git commit.
- This file never triggers Linear automatically. Do not create, update, comment on, or otherwise synchronize a Linear issue until the user explicitly says `Sync TAL-XX to Linear` or gives an equally explicit issue-specific Linear instruction.
- `Finish`, `close`, `cleanup`, `commit`, or `proceed` alone leave the task pending locally.
- Once explicitly synced, move the row to Compact Synced History and keep only the ID, Linear status, and title.
- Planning remains in `TALA-Rescue-Next-Steps.md`; completed local work belongs here, not in the planning table.

## Current Linear Snapshot

## Active Syncs

| Issue | Local Status | Title / Domain |
| --- | --- | --- |
| TAL-79 | Protocol amendment pending explicit Linear sync | Explicit local-commit and Linear-authorization boundary |
| TAL-80 | Done locally; pending explicit Linear sync | Foundation Acceptance Map and Slice Sequencing |
| TAL-81 | Done locally; pending explicit Linear sync | Identity, Access, Workspace, and Admin Baseline Acceptance |
| TAL-82A | Done locally; pending explicit Linear sync | Academic Setup Core Surfaces Acceptance |
| TAL-82B | Done locally; pending explicit Linear sync | Academic Setup Curriculum and Course Catalog Acceptance |
| TAL-82C | Done locally; pending explicit Linear sync | Academic Calendar Windows and Grade Window Inputs Acceptance |
| TAL-82D | Done locally; pending explicit Linear sync | Guarded Course Specification and Curriculum Import Template Acceptance |
| TAL-83A | Done locally; pending explicit Linear sync | Admissions Intake and Registrar Review Acceptance |
| TAL-83B | Done locally; pending explicit Linear sync | Applicant-to-Student Handover Acceptance |
| TAL-83C | Done locally; pending explicit Linear sync | Duplicate Profile Resolution Acceptance |
| TAL-83D | Done locally; pending explicit Linear sync | Student Profile and Student Hub Activation Smoke |
| TAL-84A | Done locally; pending explicit Linear sync | Holds and Lifecycle-Status Contract |
| TAL-84B | Done locally; pending explicit Linear sync | Core Lifecycle Recorded Results Acceptance |
| TAL-84C | Done locally; pending explicit Linear sync | Program-Shift Credit Evaluation Acceptance |
| TAL-84D | Done locally; pending explicit Linear sync | Academic Standing and Student Unit-Load Exceptions Acceptance |
| TAL-85A | Done locally; pending explicit Linear sync | Term Offering and Section Delivery Group Source-Record UI Alignment |
| TAL-85B | Done locally; pending explicit Linear sync | Resource Readiness Surfaces |
| TAL-85C | Done locally; pending explicit Linear sync | Scheduling Demand Readiness Acceptance |
| TAL-85D | Done locally; pending explicit Linear sync | Master Schedule Publication and Official Meetings Acceptance |
| TAL-86A | Done locally; pending explicit Linear sync | Fee Rules and Assessment Activation Acceptance |
| TAL-86B | Done locally; pending explicit Linear sync | Manual Cashier Payment, OR Mapping, and Payment Evidence Ledger Posting Acceptance |
| TAL-86C | Done locally; pending explicit Linear sync | Ledger Adjustment and Reversal Cleanup Acceptance |
| TAL-86D | Done locally; pending explicit Linear sync | Financial Accommodation and Promissory-Effect Acceptance |
| TAL-86E | Done locally; pending explicit Linear sync | Finance Gate Source Behavior Smoke |
| TAL-87A | Done locally; pending explicit Linear sync | Clean Enrollment Source-Record Baseline |
| TAL-87B | Done locally; pending explicit Linear sync | Staff Gate Review Surface |
| TAL-87C | Done locally; pending explicit Linear sync | Enrollment Gate Evaluator and Exception Gate Acceptance |
| TAL-87D | Done locally; pending explicit Linear sync | Official Enrollment Finalization Acceptance |
| TAL-88A | Done locally; pending explicit Linear sync | COR Source-Output Acceptance |
| TAL-88B | Done locally; pending explicit Linear sync | Finance Official Outputs Acceptance |
| TAL-88C | Done locally; pending explicit Linear sync | Stale Public COR Verification Deferral |
| TAL-88D | Done locally; pending explicit Linear sync | Cross-Role Output Regression |
| TAL-89A | Done locally; pending explicit Linear sync | Faculty Roster Entry and Registrar Post/Release Acceptance |
| TAL-89B | Done locally; pending explicit Linear sync | Late Grade Authorization and Returned-Roster Re-Entry Acceptance |
| TAL-89C | Done locally; pending explicit Linear sync | INC Completion/Removal and Posted Correction Acceptance |
| TAL-89D | Done locally; pending explicit Linear sync | Student Released-Grade Visibility and Cross-Role Grade Regression |
| TAL-90A | Done locally; pending explicit Linear sync | Progression and Standing Acceptance |
| TAL-90B | Done locally; pending explicit Linear sync | Graduation Batch and Snapshot Acceptance (incl. PRD §11.3.1 rules 5-7 clarification) |
| TAL-90C | Done locally; pending explicit Linear sync | Staff Visibility and Student-Safe Completion Regression (incl. PRD §11.3.1 rule 9 inactive-membership retraction) |
| TAL-91A | Done locally; pending explicit Linear sync | Student Hub Dashboard Priority-Notice Acceptance (PRD §12.2 tiers 1/2/3/5/11; native `databaseNotifications()` + notifications table added) |
| TAL-91B | Done locally; pending explicit Linear sync | Student-safe Finance Projection and Official-Output Access Logging Acceptance (PRD §12.1 item 14 accommodation staff-field hiding, page-level own-records isolation, §12.2 rule 9 payment/ledger/OR-mapping state distinction; test-only, no production leak found) |
| TAL-91C | Done locally; pending explicit Linear sync | Academic Outputs Projection Acceptance: fixed `ScheduleView` published+active meeting filter gap (candidate/superseded schedule leak); added Student Hub Capacity Pending (§12.2 tier 4) and Pending-Review gate-reason (§12.2 rule 8) tiers, reusing already-persisted `EnrollmentGateResult` via `EnrollmentGateReviewSummary`; COR acceptance-only (already covered by TAL-70/88A) |
| TAL-91D | Done locally; pending explicit Linear sync | Academic Status Student-Safe Regression (final TAL-91 sub-slice): added Holds view "Office to Contact" column reusing `Hold::studentFacingOfficeLabel()` (PRD §12.2 rule 2); surfaced `StudentProfile::academic_standing` on Lifecycle view and in `StudentDashboardService::profile()` (PRD §12.1 item 11); grades/completion re-verified as regression only (already built/tested by TAL-89D, `StudentHubCompletionReviewTest`). Parent TAL-91 (Student Hub Projection Acceptance) is now complete: 91A-91D all done. Deferred: resolver display-priority tiers 6-10 routed to new TAL-91E. |
| TAL-91E | Done locally; pending explicit Linear sync | Student Hub Display Priority Completion: implemented the final 5 of 11 ranked notice tiers (§12.2 ranks 6-10 - Missing requirements, Active academic deficiency, Schedule available, COR available, Grades released) in `StudentHubPriorityResolver`. Each tier reuses an already-existing, already-student-exposed data source (`ChecklistItem::isResolved()`, `StudentProfile::academic_standing` excluding Irregular, `StudentDashboardService::scheduleFor()`'s filter shape, `BuildCorOutput::forStudent()` directly, `GradesView::releasedGradesQuery()`'s filter shape) rather than new logic, avoiding the divergence-bug class fixed in TAL-91C. All 11 §12.2 tiers now implemented; none remain deferred. |
| TAL-92A | Done locally; pending explicit Linear sync | Fixed Reports & Export Audit Acceptance (first sub-slice of parent TAL-92): accepted the existing `OperationalReportService`/`ExportOperationalReport`/`OperationalReportPolicy`/`ReportsAudit` implementation against PRD §13.3; built the missing Curriculum Version Report catalog entry and granted Academic Head visibility into the existing Graduation Eligibility Snapshot report (additive-only; Registrar access unaffected, Accounting still rejected), bringing the Academic Head catalog from 9 to 11 to match the PRD's enumerated report list. Reconciled PRD §13.3.6 (`report_export_log` naming vs the implemented unified `output_access_logs` table) and recorded deferral destinations for Sensitive/Document/Login-Session Audit breakdowns (→ TAL-92B), Solver Run History (→ TAL-94), PayMongo Webhook Event Log (→ TAL-95), and Privacy Request Log (→ TAL-92F). |

## Compact Synced History

*Search these IDs on the Linear website for full details, descriptions, and evidence.*

| Issue | Status (Linear) | Title / Domain |
| --- | --- | --- |
| TAL-42 | Done | R0 PRD rebaseline rescue controller |
| TAL-43 | Done | Public landing page and Filament auth routing baseline |
| TAL-44 | Done | Applicant Workspace shell and navigation |
| TAL-45 | Done | Student Hub shell |
| TAL-46 | Canceled | Staff workspace navigation |
| TAL-47 | Canceled | Frontend smoke tests |
| TAL-48 | Done | Applicant intake draft and submission |
| TAL-49 | Done | Foundation/Auth Workspace Rebaseline |
| TAL-50 | Done | Current Worktree Intake and Backend Salvage Ledger |
| TAL-51 | Done | Clean MVP ERD and Schema Contract |
| TAL-52 | Done | Clean MVP Migration Baseline |
| TAL-53 | Done | Backend Schema Reconciliation Inventory |
| TAL-54 | Done | Backend Boot and Filament Registration Stabilization |
| TAL-55 | Done | Academic, Course, and Curriculum Foundation Adaptation |
| TAL-56 | Done | Admissions-to-Student Master Backend Adaptation |
| TAL-57 | Done | Student Panel Filament v5 Profile Boot Stabilization |
| TAL-58 | Done | Term Offerings and Resource Foundation Backend Adaptation |
| TAL-59 | Done | Registrar Term Offering Builder |
| TAL-60 | Done | Scheduling UI Baseline Realignment |
| TAL-61 | Done | Scheduling Demand Source Contract and Filament Readiness Surface |
| TAL-62 | Done | Solver Run Dispatch and Candidate Schedule Review |
| TAL-63 | Done | Cloud Run Solver Demand Contract Alignment |
| TAL-64 | Done | Cloud Run Solver Deployment Path Review |
| TAL-65 | Done | Laravel Cloud Run Solver Integration Verification |
| TAL-66 | Done | Candidate Schedule Approval and Publication |
| TAL-67 | Done | Enrollment Gate and Seat Reservation |
| TAL-68 | Done | Finance Assessment and Ledger Foundation |
| TAL-69 | Done | PayMongo Payment Evidence and Ledger Posting |
| TAL-70 | Done | Authenticated COR Output and Holds Alignment |
| TAL-71 | Done | Finance Outputs and Student Hub Finance |
| TAL-72 | Done | Grades MVP |
| TAL-73 | Done | Progression and Student Lifecycle MVP |
| TAL-74 | Done | Graduation and Completion Review MVP |
| TAL-75 | Done | Reports, Audit, and Export MVP |
| TAL-76 | Done | Bootstrap Public Landing Page Adaptation |
| TAL-77 | Done | Calendar-Event Availability Alignment and Solver Mapping |
| TAL-78 | Done | Current dirty-work cleanup and scheduling/access verification |
| TAL-79 | Done | Orchestrator Protocol and Vertical Slice Workflow Codification |
