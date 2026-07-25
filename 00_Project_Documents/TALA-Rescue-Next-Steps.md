# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: complete cross-role UAT, regression, UI/UX correction, and the final Markdown user manual (revised TAL-96D), then prepare the formal client and panel presentation (TAL-97). The completed scheduling and PayMongo readiness work is retained in bounded commits and pending-sync tracker rows. Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-96 | Planned parent | Final MVP operational-data, integration-demo, system-acceptance, UX-polish, documentation, and capacity-readiness gate. |
| TAL-96A | Done locally | Standalone CP-SAT Technical Formulation and Laravel Validation Pipeline handoff for the project manager. |
| TAL-96C | Done locally | Client-baseline PayMongo demo readiness and Student Finance checkout acceptance. |
| TAL-96D | Approved revised split and master charter; depends on TAL-96C | Production-Level MVP Defense Readiness parent governed by [`TALA-96D-Full-System-Refinement-Charter.md`](TALA-96D-Full-System-Refinement-Charter.md): preservation-first vertical hardening, diverse scenario evidence, adversarial cross-role acceptance, targeted capacity readiness, and one consolidated operations and defense guide. |
| TAL-96D1 | Done locally | Client-Corrected Capacity Authority, Representative Scenario Catalogue, Operating-Order Map, Implementation-Validity Audit, Baseline Correction, and Required-Gap Routing. |
| TAL-96D2 | Approved revised split; depends on TAL-96D1 | Identity, Admissions, Academic Setup, and Offering-State Hardening parent. |
| TAL-96D2A | Done locally | Identity, Applicant Intake, Review, and Handover Hardening. |
| TAL-96D2B | Done locally | Academic Period, Catalog, Curriculum, and Import Hardening. |
| TAL-96D2C | Done locally | Offering, Section, Resource, Scheduling-Readiness, and Faculty-Evidence Reconciliation with guarded `MIN`, `MIDDLE`, and `MAX` acceptance scenarios. |
| TAL-96D3 | Approved revised split; depends on TAL-96D2C | Scheduling, Enrollment, Finance, COR, and Integration-State Hardening parent. |
| TAL-96D3A | Done locally | Master Schedule Functional Hardening: readiness, dispatch and retry, candidate review and correction, publication and revision, and official faculty/student schedule projections. |
| TAL-96D3B | Done locally; pending explicit Linear sync; consolidated manual acceptance deferred to TAL-96D5B | Enrollment Window, Proposal, and Placement Hardening plus truthful final-state handling, responsive action controls, and a plain-language enrollment information hierarchy. |
| TAL-96D3C | Done locally; pending explicit Linear sync | Authoritative Finance State, PayMongo Recovery, and Plain-Language Operations Hardening. |
| TAL-96D3D | Done locally; pending explicit Linear sync | Official Enrollment, Current COR/Schedule, Hold, Modality, and Cross-Role Comprehensibility Hardening. |
| TAL-96D4 | Approved revised split; depends on TAL-96D3D | Cross-role UI/UX hardening parent covering the system-wide UX foundation, Grades and Lifecycle, Student Hub and generated outputs, and Bootstrap landing-page refinement. |
| TAL-96D4A | Done locally; pending explicit Linear sync | System-Wide UX Foundation and Error Handling. |
| TAL-96D4B | Done locally; pending explicit Linear sync | Grades and Student Lifecycle Hardening from faculty/staff action through controlled review and student-facing projection. |
| TAL-96D4C | Done locally; pending explicit Linear sync; depends on TAL-96D4B | Student Hub, Reports, Generated Outputs, and Notification Presentation. |
| TAL-96D4D | Done locally; pending explicit Linear sync; depends on TAL-96D4C | Bootstrap Landing, Static Diagnostics, and Cross-Role Consistency Closure. |
| TAL-96D5 | Approved revised split; depends on TAL-96D4 | Final acceptance, capacity evidence, deployment-readiness, documentation consolidation, and defense-closure parent. |
| TAL-96D5A | Done locally; pending explicit Linear sync | Completion Readiness and Acceptance-Matrix Reconciliation. |
| TAL-96D5B | Planned; depends on TAL-96D5A | Consolidated User-Led Adversarial Acceptance against the representative `MIDDLE` scenario, with bounded remediation and affected-case reruns only. |
| TAL-96D5C | Planned; depends on TAL-96D5B | Full Regression, Security, and Integration Readiness Gate after functional and manual-acceptance remediation stabilizes. |
| TAL-96D5D | Planned; depends on TAL-96D5C | Targeted `MIN-CFG` / `TARGET-CFG` / `MAX-CFG` population, Cloud Run configuration, solution-quality, and cost evaluation without relabelling historical proportional evidence. |
| TAL-96D5E | Planned; depends on TAL-96D5D | Evidence Consolidation, final deployment-readiness disposition, System Operations and Defense Guide completion, TAL-97 verified-claim handoff, and TAL-96D charter retirement. |
| TAL-97 | Planned; depends on TAL-96D5 | Formal Client and Panel Presentation and Defense Readiness built only from verified TAL-96D evidence. |

Approved TAL-96D order: `TAL-96D1 -> TAL-96D2A -> TAL-96D2B -> TAL-96D2C -> TAL-96D3A -> TAL-96D3B implementation/automated verification -> standalone TAL-96D2C faculty-evidence reconciliation -> refreshed MIN baseline -> TAL-96D3B manual acceptance finding -> TAL-96D3B remediation and automated reverification -> TAL-96D3C -> TAL-96D3D -> TAL-96D4A -> TAL-96D4B -> TAL-96D4C -> TAL-96D4D -> TAL-96D5A -> TAL-96D5B -> TAL-96D5C -> TAL-96D5D -> TAL-96D5E -> TAL-97`. D5A reconciles readiness before destructive or external work; D5B performs the single consolidated user-led walkthrough; D5C closes regression and integration readiness; D5D performs the deferred targeted population/configuration and cost study only after functional behavior is stable; D5E synchronizes evidence and retires the charter. TAL-96D remains primary-only; subagents require explicit user approval. Production deployment, solver traffic promotion, and cutover remain outside this split unless separately authorized.

The full execution method, accepted product directions, state coverage, population scenarios, capacity-study timing, documentation ownership, and human gates are durable in [`TALA-96D-Full-System-Refinement-Charter.md`](TALA-96D-Full-System-Refinement-Charter.md). Every remaining TAL-96D plan must cite and reconcile that charter through the Ground-Truth Gate. It supplements rather than replaces the PRD, blueprint, architecture, master protocol, or the one active approved slice contract recorded here.

Approved compact dispositions: preserve aligned implementation; fix only evidence-backed defects or real gaps; leave cosmetic preferences unchanged or optional. The product supports only `FACE_TO_FACE` and `ONLINE` per offering. D2B stabilizes three-year academic inputs. D2C owns the 21:00 operating grid, realistic offering/resource readiness, and executable `MIN`/`MIDDLE`/`MAX` fixtures without invoking Cloud capacity tests. Its approved standalone correction must distinguish client-reported faculty headcount from generated synthetic scheduling capacity and must not treat demographic status or student modality counts as equivalent to TALA academic-standing or per-offering modality dimensions. D3 and D4 use the stable scenarios for functional and cross-role hardening. D5 owns the targeted population/configuration and cost study after workload manifests are final. Earlier Profile A/B/C and proportional experiments remain historical solver-scaling evidence rather than population tiers. Shared cross-program common classes remain routed to TAL-175. Any solver-contract or constraint-model change is a human-gated plan revision.

## Post-MVP Deferrals

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-98 | Future; nonblocking | Archival, offline-storage management, and disposal automation deferred from TAL-92E and PRD §13.7. |
| TAL-99 | Future; nonblocking | DPO-owned privacy-request intake and logging deferred from TAL-92F and PRD §13.3.4. |
| TAL-100 | Future; nonblocking | Database-backed configurable notification templates deferred from TAL-92F and PRD §13.1.1. |
| TAL-101 | Future; nonblocking | Database-level audit tamper-evidence hardening deferred from TAL-93A and PRD §13.6. |
| TAL-175 | Future; nonblocking | Shared cross-program common-class modeling with multi-cohort conflict protection, solver-contract review, and synchronized formulation evidence. |

### Unapproved Proposals

No work outside the listed issues is approved or implied. Any additional institutional feature, UI plugin, or infrastructure enhancement must pass the protocol gates and receive an explicit Next Steps issue before implementation.

## Next Planning Boundary

Next primary boundary: **`Plan TAL-96D5B`** through a fresh Ground-Truth Gate against the verified D5A acceptance package and the governing charter. Linear sync, push, PR creation, deployment, subagent use, destructive database actions, external-service changes, persistent scenario replacement, Cloud solver execution, and full-suite rebuilds remain unauthorized until their corresponding explicit commands or human gates.
