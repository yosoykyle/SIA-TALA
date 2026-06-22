# TALA Active Execution Plan

## Status

Active reset: `SDD-00F Feature Approval and Survival Rebaseline`.

This file is the only local execution controller. Deleted SDD maps, local checklists, rescue plans, benchmark matrices, capability trackers, and migration-control logs are historical and must not be treated as active instructions. Linear and git history retain the previous execution record.

## SDLC

1. Monolithic baseline: use FS/TS plus active business evidence as the requirement source.
2. Feature approval: classify each FS/TS feature as `KEEP`, `REMOVE`, `EXTERNAL`, or `REVIEW`.
3. Benchmark: for `KEEP` and `REVIEW`, compare against mature SIS/domain systems or official package docs before implementation.
4. Micro-sprint: implement one narrow feature slice at a time with tests and minimal UI needed for verification.
5. Human gate: user reviews scope, UI, and manual UAT before the next sprint is treated as accepted.

## Active Sources

- `business-evidence/INSTITUTION WORK  FLOW CURRENT.md`
- `TALA-Functional-Specification.md`
- `TALA-Technical-Specification.md`
- `TALA-Master-System-Test-Cases.md` after feature-audit rebuild only
- Linear issue created for this reset and its child issues

## Current Scope

- College-only SIS.
- SHS is removed from active product scope.
- External portals and manual outside-office work are not TALA features.
- Capstone integration priority: CP-SAT scheduling, Google Vision OCR, PayMongo/payment flow, and read-only Student Hub/PWA only if approved by feature audit.

## Immediate Work

1. Extract FS/TS feature inventory by lifecycle module and role.
2. Ask the user to classify features in small batches.
3. Remove rejected and externalized features from active FS/TS language.
4. Benchmark approved features only.
5. Rebuild a tiny sprint backlog from approved P0 dependencies.
6. Update Linear with the reset, retired local execution layer, and new sprint issues.

## Approved Feature Batch 1

- KEEP: auth/RBAC/login/logout/session security; staff roles; applicant intake/admissions; admission document upload/review; Google Vision OCR; student master record; enrollment handover; College academic foundation; SOA/payment acknowledgement/internal payment evidence.
- REMOVE: active non-College offering paths; official document-request portal/catalog/fulfillment; official tax receipt/e-receipt/CAS behavior.
- EXTERNAL: outside-office portal/submission/status work. TALA only owns enrolled-student roster visibility/export and audited internal lifecycle state.

## Approved Feature Batch 2

- KEEP: CP-SAT-assisted scheduling; faculty availability input; curriculum-derived subject demand; Registrar-owned subject/faculty assignment; manual schedule assignment; draft review before commit; Academic Head publish approval; delivery groups/patterns where needed; room conflict checking when room-required delivery exists.
- REVIEW: simplest viable sectioning approach; post-publish schedule-change workflow; summer/remedial scheduling; faculty advising status.
- REMOVE: online meeting-link/LMS handling; automatic section creation/balancing as an active implementation promise.
- Clarification: faculty provide availability only. They do not choose teaching subjects or resolve scheduling conflicts. Registrar/setup staff select subjects/faculty from curriculum-derived demand and approved staff records.

## Approved Feature Batch 3

- KEEP: fee templates/assessment; minimum downpayment clearance; PayMongo checkout/webhook confirmation; manual payment confirmation for Cash, GCash Manual, and Bank Transfer; immutable student ledger; balance computation and overpayment credit; internal SOA/payment acknowledgement evidence; Accounting debit/credit/reversal adjustments; finance clearance securing capacity; applicant-to-student handover; COR generation; COR QR verification; SOA/payment evidence issuance.
- REVIEW: freshmen tuition discount; irregular/unit-based assessment; promissory promise tracking; exam-access accommodation workflow; installment policies/penalty automation; refund, withdrawal-fee, and financial-disposition automation.
- REMOVE: official BIR receipt/tax invoice generation; promissory note as payment clearance or exam access; generic ledger/payment CRUD; full COR template editor; formal TOR/Form 137/report-card PDF/diploma/certificate credential issuance or fulfillment. This does not remove student grade history, finalized grade viewing, or internal academic records.
- EXTERNAL: outside-office official receipts, tax documents, school-to-school credential release, and document-request fulfillment.

## Sprint Selection Rule

Next implementation work must come from the approved feature inventory, not from old SDD numbering. Highest priority goes to SIS lifecycle dependencies and capstone integrations that can be tested within the remaining time.
