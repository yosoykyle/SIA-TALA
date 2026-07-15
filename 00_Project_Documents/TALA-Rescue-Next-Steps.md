# TALA Rescue Next Steps

## Purpose

This is the active planning surface for issue order, compact parent/sub-slice maps, routed deferrals, and the one active approved plan contract. Product behavior and workflow rules remain in their owning authorities. Allocate a new top-level issue after the highest numeric ID already reserved here, in `TALA-Local-Linear-Sync-Tracker.md`, or in Linear; confirm live Linear before creating it.

## Active and Upcoming Issues

Remaining dependency chain: complete the human-gated PayMongo integration (TAL-95), run the post-integration regression gate (TAL-96), then prepare the verified demo and rehearsal environment (TAL-97). Post-MVP deferrals are nonblocking.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-95 | In progress; TAL-95A done locally; next TAL-95B; human-gated final acceptance | Payment Gateway End-to-End Hardening: validate real payment attempts, verified webhooks, idempotent ledger posting, Finance Gate, Accounting/Student evidence, checkout audit, webhook reporting, and payment notification. Treat current gateway wiring as unverified until proven. |
| TAL-96 | Planned | Post-Integration Cross-Role Regression: verify system coherence after CP-SAT and PayMongo are wired in. |
| TAL-97 | Planned | Demo and Rehearsal Support built only from the verified MVP. |

### TAL-95 Approved Split

Cross-slice acceptance rule: TAL-95 is end-to-end across A-D. Treat every existing payment-gateway, webhook, configuration, and UI path as unverified salvage until its owning slice re-establishes authority alignment and tests it. Each slice must cover the user-facing states it owns through automated Filament/Livewire tests and, where useful, agent-operated browser verification; it need not invent a screen for backend-only behavior. Before any credential, PayMongo dashboard, public-HTTPS endpoint, or real test-mode step, state the exact human action, expected evidence, and unlocked work. TAL-95D remains the final human-gated provider acceptance.

| Slice | Status | Purpose | Next boundary |
| --- | --- | --- | --- |
| TAL-95A | Done locally; pending explicit Linear sync | Authorized, idempotent, recoverable Student Checkout and active Payment Attempt lifecycle. | Plan TAL-95B |
| TAL-95B | Approved boundary; ready to plan | Secure and structure PayMongo webhook ingress, queued processing, exact evidence validation, duplicate-safe Payment/Ledger posting, Finance Gate effects, and Accounting review routing for failed, mismatched, refund, or reversal events. | Plan TAL-95B |
| TAL-95C | Approved boundary; blocked by TAL-95B | Complete source-linked Accounting review/retry operations, sanitized PayMongo webhook reporting and integration status, Student Finance evidence, and deduplicated payment-posted email delivery. | Plan after TAL-95B Cleanup |
| TAL-95D | Approved boundary; blocked by TAL-95C; human-gated | Prove the complete PayMongo test-mode lifecycle through a public HTTPS webhook endpoint against `test_tala_db`, including successful and failed checkout, signed delivery, duplicate handling, ledger/Finance Gate outcomes, role surfaces, report evidence, and notification delivery; exclude live keys and real money. | Plan after TAL-95C Cleanup |

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

Next primary boundary: **Plan TAL-95B**. Re-run the Ground-Truth Gate against the approved TAL-95B boundary before drafting its implementation plan; treat the existing webhook, ledger-posting, Finance Gate, and Accounting-review paths as unverified salvage until proven.
