# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: complete the human-gated PayMongo integration (TAL-95), run the post-integration regression gate (TAL-96), then prepare the verified demo and rehearsal environment (TAL-97). Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-95 | In progress; TAL-95A-C2 completed and synced; TAL-95D1, TAL-95D2A, and TAL-95D2B1 done locally pending explicit Linear sync; TAL-95D2B2 planned | Payment Gateway End-to-End Hardening: validate real payment attempts, verified webhooks, idempotent ledger posting, Finance Gate, Accounting/Student evidence, checkout audit, webhook reporting, and payment notification. Treat current gateway wiring as unverified until proven. |
| TAL-96 | Planned | Post-Integration Cross-Role Regression: verify system coherence after CP-SAT and PayMongo are wired in. |
| TAL-97 | Planned | Demo and Rehearsal Support built only from the verified MVP. |

### TAL-95 Approved Split

Cross-slice acceptance rule: TAL-95 is end-to-end across A-D. Treat every existing payment-gateway, webhook, configuration, and UI path as unverified salvage until its owning slice re-establishes authority alignment and tests it. Each slice must cover the user-facing states it owns through automated Filament/Livewire tests and, where useful, agent-operated browser verification; it need not invent a screen for backend-only behavior. Before any credential, PayMongo dashboard, public-HTTPS endpoint, or real test-mode step, state the exact human action, expected evidence, and unlocked work. TAL-95D1 established the provider contract; TAL-95D2A corrected signed-webhook admission; TAL-95D2B1 corrected provider-shape normalization and preserved-review recovery; TAL-95D2B2 owns final provider acceptance.

| Slice | Status | Purpose | Next boundary |
| --- | --- | --- | --- |
| TAL-95A | Done; synced as Linear TAL-171 | Authorized, idempotent, recoverable Student Checkout and active Payment Attempt lifecycle. | Completed |
| TAL-95B | Done; synced as Linear TAL-172 | Secure PayMongo webhook processing, exact financial validation, and Accounting review routing. | Completed |
| TAL-95C | Completed through synced TAL-95C1 and TAL-95C2 | Complete Accounting reconciliation first, then PayMongo observability and student-facing delivery. | Completed |
| TAL-95C1 | Done; synced as Linear TAL-173 | Add source-linked Accounting reconciliation with authorized, reasoned confirm/reject decisions and safe reprocessing of persisted PayMongo webhook evidence. | Completed |
| TAL-95C2 | Done; synced as Linear TAL-174 | Deliver sanitized PayMongo reporting and integration status, explicit Student Finance evidence states, and deduplicated payment-posted email delivery. | Completed |
| TAL-95D1 | Done locally; pending explicit Linear sync | Current Hosted Checkout V2/legacy webhook compatibility, retryable-decline behavior, deterministic idempotency, and guarded test-mode rehearsal tooling. | Completed |
| TAL-95D2 | Revised split approved through TAL-95D2A, TAL-95D2B1, and TAL-95D2B2; TAL-95D2B1 done locally; TAL-95D2B2 planned | Resolve provider-rehearsal admission and normalization gaps before completing test-mode provider acceptance. | Plan TAL-95D2B2 |
| TAL-95D2A | Done locally; pending explicit Linear sync | Diagnose and harden the real PayMongo signed-webhook admission and retry boundary without weakening fail-closed signature verification or exposing secrets and payloads. | Completed |
| TAL-95D2B | Revised split approved into TAL-95D2B1 and TAL-95D2B2; TAL-95D2B1 done locally; TAL-95D2B2 planned | Correct the provider-shape defect exposed by the preserved successful delivery before resuming final provider acceptance. | Plan TAL-95D2B2 |
| TAL-95D2B1 | Done locally; pending explicit Linear sync | Provider-faithful Checkout Session normalization and narrowly gated preserved-review recovery. | Completed |
| TAL-95D2B2 | Planned; depends on completed TAL-95D2B1 | Resume official PayMongo resend acceptance, prove exactly-once financial, notification, role, and reporting effects, then tear down and restore the disposable test database. | Plan TAL-95D2B2 |

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

Next primary boundary: **Plan TAL-95D2B2**. No implementation, provider activity, queue processing, Linear sync, push, PR, or deployment is authorized.
