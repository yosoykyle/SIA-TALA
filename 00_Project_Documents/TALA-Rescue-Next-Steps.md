# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: complete the human-gated CP-SAT and PayMongo integrations (TAL-94/95), run the post-integration regression gate (TAL-96), then prepare the verified demo and rehearsal environment (TAL-97). Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-94 | Split approved; TAL-94A-E2b done locally, TAL-94E3 pending | CP-SAT Scheduling End-to-End Hardening. Preserve aligned work and do not treat the deployed solver as current until TAL-94E3 proves it. |
| TAL-94A | Done locally | Solver Contract and Hard Constraints |
| TAL-94B | Done locally via TAL-94B1/B2 | Solver Result Validation, Diagnostics, and Controlled Revalidation |
| TAL-94C | Done locally | Candidate Review and Controlled Correction UX |
| TAL-94D | Done locally via TAL-94D1-D3c | Approval, Publication, Live Revision, Notifications, and Schedule Projections |
| TAL-94E | Split approved; TAL-94E1/E2 done locally, TAL-94E3 pending | Solver Transport, Operations, Deployment, and End-to-End Acceptance |
| TAL-94E1 | Done locally | Solver Transport and Cloud Artifact Hardening |
| TAL-94E2 | Done locally via TAL-94E2a/E2b | Queue Reliability, Operations, and Schedule Release |
| TAL-94E2a | Done locally | Queue Reliability and Solver Operations |
| TAL-94E2b | Done locally | Schedule Released Email Evidence |
| TAL-94E3 | Planned; human-gated deployment | Private Cloud Run Deployment and End-to-End Acceptance: deploy only after explicit authorization, prove IAM and the V2 revision, then verify dispatch through publication, projections, notification, failure evidence, and rollback. |
| TAL-95 | Planned; human-gated | Payment Gateway End-to-End Hardening: validate real payment attempts, verified webhooks, idempotent ledger posting, Finance Gate, Accounting/Student evidence, checkout audit, webhook reporting, and payment notification. Treat current gateway wiring as unverified until proven. |
| TAL-96 | Planned | Post-Integration Cross-Role Regression: verify system coherence after CP-SAT and PayMongo are wired in. |
| TAL-97 | Planned | Demo and Rehearsal Support built only from the verified MVP. |

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

Next primary boundary: **Plan TAL-94E3**. Re-run the Ground-Truth Gate before planning the human-gated private Cloud Run deployment and end-to-end acceptance; do not deploy, push, or call Linear without the corresponding explicit command.
