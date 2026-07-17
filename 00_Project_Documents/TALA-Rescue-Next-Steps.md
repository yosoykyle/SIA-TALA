# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: retain the completed standalone CP-SAT formulation handoff (TAL-96A), establish the guarded client-aligned acceptance baseline (TAL-96B1), prove CP-SAT scheduling demo readiness (TAL-96B2), prove PayMongo test-mode demo readiness (TAL-96B3), complete cross-role UAT, regression, UI/UX correction, and the final Markdown user manual (TAL-96C), benchmark CP-SAT capacity and performance with generated growth and stress tiers (TAL-96D), then rehearse and prepare the formal client and panel presentation (TAL-97). Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-96 | Planned parent | Final MVP operational-data, integration-demo, system-acceptance, UX-polish, documentation, and capacity-readiness gate. |
| TAL-96A | Done | Standalone CP-SAT Technical Formulation and Laravel Validation Pipeline handoff for the project manager. |
| TAL-96B | Revised split approved | Client-Aligned Operational Baseline and Scheduling/PayMongo Integration Demo Readiness through TAL-96B1, TAL-96B2, and TAL-96B3. |
| TAL-96B1 | Done locally | Guarded Client-Aligned Deterministic Acceptance Baseline. |
| TAL-96B2 | Planned; depends on TAL-96B1 | CP-SAT Scheduling Demo Readiness using the real loopback HTTP solver and the accepted baseline. |
| TAL-96B3 | Planned; depends on TAL-96B1 and TAL-96B2 | PayMongo Test-Mode Demo Readiness using the accepted baseline and human-gated dashboard, checkout, and webhook actions. |
| TAL-96C | Planned | Cross-Role UAT, Regression, Evidence-Based UI/UX Correction, and Final Markdown User Manual. |
| TAL-96D | Planned | CP-SAT Capacity and Performance Benchmark using one parameterized growth/stress dataset family. |
| TAL-97 | Planned | Rehearsal and Formal Client and Panel Presentation and Defense Readiness built only from verified TAL-96 outputs. |

## Active Approved Plan Contract

No active approved plan contract.

## Post-MVP Deferrals

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-98 | Future; nonblocking | Archival, offline-storage management, and disposal automation deferred from TAL-92E and PRD §13.7. |
| TAL-99 | Future; nonblocking | DPO-owned privacy-request intake and logging deferred from TAL-92F and PRD §13.3.4. |
| TAL-100 | Future; nonblocking | Database-backed configurable notification templates deferred from TAL-92F and PRD §13.1.1. |
| TAL-101 | Future; nonblocking | Database-level audit tamper-evidence hardening deferred from TAL-93A and PRD §13.6. |

### Unapproved Proposals

No work outside the listed issues is approved or implied. Any additional institutional feature, UI plugin, or infrastructure enhancement must pass the protocol gates and receive an explicit Next Steps issue before implementation.

### Next Boundary

Next primary boundary: **Plan revised TAL-96B2** to reconcile the measured Cloud Run memory requirement and recovery strategy before another deployment or promotion attempt. Do not begin TAL-96B2 implementation, sync Linear, push, open a PR, or deploy without the corresponding explicit command.
