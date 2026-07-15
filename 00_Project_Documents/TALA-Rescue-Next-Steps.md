# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: complete the human-gated PayMongo integration (TAL-95), run the post-integration regression gate (TAL-96), then prepare the verified demo and rehearsal environment (TAL-97). Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-95 | In progress; TAL-95A-C done locally; next TAL-95D1 planning; TAL-95D2 is the human-gated final acceptance | Payment Gateway End-to-End Hardening: validate real payment attempts, verified webhooks, idempotent ledger posting, Finance Gate, Accounting/Student evidence, checkout audit, webhook reporting, and payment notification. Treat current gateway wiring as unverified until proven. |
| TAL-96 | Planned | Post-Integration Cross-Role Regression: verify system coherence after CP-SAT and PayMongo are wired in. |
| TAL-97 | Planned | Demo and Rehearsal Support built only from the verified MVP. |

### TAL-95 Approved Split

Cross-slice acceptance rule: TAL-95 is end-to-end across A-D. Treat every existing payment-gateway, webhook, configuration, and UI path as unverified salvage until its owning slice re-establishes authority alignment and tests it. Each slice must cover the user-facing states it owns through automated Filament/Livewire tests and, where useful, agent-operated browser verification; it need not invent a screen for backend-only behavior. Before any credential, PayMongo dashboard, public-HTTPS endpoint, or real test-mode step, state the exact human action, expected evidence, and unlocked work. TAL-95D1 reconciles the current provider contract before TAL-95D2 performs the final human-gated provider acceptance.

| Slice | Status | Purpose | Next boundary |
| --- | --- | --- | --- |
| TAL-95A | Done locally; pending explicit Linear sync | Authorized, idempotent, recoverable Student Checkout and active Payment Attempt lifecycle. | Completed |
| TAL-95B | Done locally; pending explicit Linear sync | Secure PayMongo webhook processing, exact financial validation, and Accounting review routing. | Completed |
| TAL-95C | Done locally; pending explicit Linear sync | Complete Accounting reconciliation first, then PayMongo observability and student-facing delivery. | Completed |
| TAL-95C1 | Done locally; pending explicit Linear sync | Add source-linked Accounting reconciliation with authorized, reasoned confirm/reject decisions and safe reprocessing of persisted PayMongo webhook evidence. | Completed |
| TAL-95C2 | Done locally; pending explicit Linear sync | Deliver sanitized PayMongo reporting and integration status, explicit Student Finance evidence states, and deduplicated payment-posted email delivery. | Completed |
| TAL-95D1 | Approved boundary; ready for planning | Reconcile TALA with the current PayMongo Hosted Checkout V2 webhook envelope and retryable-decline semantics, harden deterministic idempotency and automated contract coverage, and document a reproducible test-mode environment without persisting credentials, endpoint secrets, or temporary tunnel URLs. | Plan TAL-95D1 |
| TAL-95D2 | Approved boundary; blocked on TAL-95D1; human-gated | Prove the complete PayMongo test-mode lifecycle through a fresh public HTTPS webhook endpoint against `test_tala_db`: successful checkout, retryable decline followed by cancellation or explicit expiry, signed delivery, duplicate handling, ledger/Finance Gate outcomes, role surfaces, report evidence, notification delivery, and safe teardown; exclude live keys and real money. | After TAL-95D1 Cleanup, Plan TAL-95D2 |

## Active Approved Plan Contract

No active approved plan contract. The next boundary must be planned and approved before implementation or orchestration.

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

Next primary boundary: **Plan TAL-95D1**. Re-run the Ground-Truth Gate and define the current provider-contract reconciliation, retryable-decline behavior, automated contract coverage, and stable setup documentation. Do not mutate PayMongo dashboard settings, credentials, public endpoints, or test-mode records in TAL-95D1 planning.
