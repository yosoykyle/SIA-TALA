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
| TAL-96D | Approved revised split and master charter; depends on TAL-96C | Production-Level MVP Defense Readiness parent covering vertical prerequisite and state hardening, adversarial cross-role UAT, evidence-based UI/UX remediation, deployment-readiness review, and one consolidated operations, acceptance, and defense guide. |
| TAL-96D1 | Done locally | Client-Corrected Capacity Authority, Representative Scenario Catalogue, Operating-Order Map, Implementation-Validity Audit, Baseline Correction, and Required-Gap Routing. |
| TAL-96D2 | Approved revised split; depends on TAL-96D1 | Identity, Admissions, Academic Setup, and Offering-State Hardening parent. |
| TAL-96D2A | Done locally | Identity, Applicant Intake, Review, and Handover Hardening. |
| TAL-96D2B | Planned; depends on TAL-96D2A | Academic Period, Catalog, Curriculum, and Import Hardening. |
| TAL-96D2C | Planned; depends on TAL-96D2B | Offering, Section, Resource, and Scheduling-Readiness Hardening. |
| TAL-96D3 | Planned; depends on TAL-96D2C | Scheduling, Enrollment, Finance, COR, and Integration-State Hardening. |
| TAL-96D4 | Planned; depends on TAL-96D3 | Grades, Lifecycle, Student Hub, Reports, and Cross-Role UX Hardening. |
| TAL-96D5 | Planned; depends on TAL-96D4 | Final Adversarial Acceptance, Deployment-Readiness Gate, and Consolidated System Operations and Defense Guide. |
| TAL-97 | Planned; depends on TAL-96D5 | Formal Client and Panel Presentation and Defense Readiness built only from verified TAL-96D evidence. |

Approved TAL-96D order: `TAL-96D1 -> TAL-96D2A -> TAL-96D2B -> TAL-96D2C -> TAL-96D3 -> TAL-96D4 -> TAL-96D5 -> TAL-97`. TAL-96D remains primary-only; subagents require explicit user approval. Production deployment and cutover remain outside this split.

Approved TAL-96D master dispositions: preserve aligned implementation; fix only evidence-backed defects or real gaps; leave cosmetic preferences unchanged or list them as optional. The product supports only `FACE_TO_FACE` and `ONLINE`, assigned per subject offering; an applicant's D2A modality preference is informational for Registrar review and never creates a per-student scheduling track. D2A owns the approved policy-driven multi-document intake and Wizard. D2B reconciles the client program labels/codes and three-year duration. D2C owns the 21:00 operating-grid correction, realistic contact-hour fixture, offering-level modality cleanup, and tiny-cohort readiness evidence. The current MVP keeps separate per-program offerings; shared cross-program common classes are a post-MVP solver-contract enhancement routed to TAL-175. The single-contiguous-meeting model remains accepted, no minimum class-size automation is introduced, and synthetic schedules may be Online-heavy without an equal-modality rule. Any discovery that requires changing the CP-SAT contract, solver, or formulation is a human-gated plan revision.

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

### Next Boundary

Next primary boundary: **`Plan TAL-96D2B`**. Linear sync, push, PR creation, deployment, subagent use, and external-service changes remain unauthorized until their corresponding explicit commands.
