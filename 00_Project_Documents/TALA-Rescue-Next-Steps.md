# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: complete the human-gated CP-SAT and PayMongo integrations (TAL-94/95), run the post-integration regression gate (TAL-96), then prepare the verified demo and rehearsal environment (TAL-97). Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-94 | Split approved; TAL-94A-D done locally, TAL-94E pending | CP-SAT Scheduling End-to-End Hardening. Preserve aligned work and do not treat `cloud/` as verified deployment truth. Deferred items from TAL-92A/B/F remain owned by TAL-94E. |
| TAL-94A | Done locally | Solver Contract and Hard Constraints |
| TAL-94B | Done locally via TAL-94B1/B2 | Solver Result Validation, Diagnostics, and Controlled Revalidation |
| TAL-94C | Done locally | Candidate Review and Controlled Correction UX |
| TAL-94D | Done locally via TAL-94D1-D3c | Approval, Publication, Live Revision, Notifications, and Schedule Projections |
| TAL-94E | Planned; human-gated | Solver Transport and End-to-End Acceptance: validate local/demo and Cloud Run transport, IAM/credentials, queued execution and failure handling, operational/run-history evidence, release notification, and the dispatch-to-publication path. Treat deployment and `cloud/` artifacts as unverified until proven. |
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

Next primary boundary: **Plan TAL-94E**. Re-run the Ground-Truth, Benchmark, Qualified-Reference, credential, deployment, and human-gate checks before planning solver transport and end-to-end acceptance.
