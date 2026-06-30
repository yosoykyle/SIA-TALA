# TALA Rescue Next Steps

## Purpose

This document is the active planning surface for upcoming work.
- **Issue Numbering:** Always look at the last Issue ID in the `TALA-Local-Linear-Sync-Tracker.md` or on the Linear website. The next issue planned here will start from the subsequent number.
- **The Cycle:**
  1. We plan the next batch of issues and their descriptions here.
  2. We take action and implement the issues.
  3. **Important:** Issues are only moved to `TALA-Local-Linear-Sync-Tracker.md` for syncing after **all** the planned issues/steps in the current batch are fully completed. It will not be moved if just one issue is done.
  4. The completed batch of issues is then removed from this planning document.

## Source-of-Truth Order

Use this order before implementing each slice:

1. `00_Project_Documents/prd_modules/README.md`
2. `00_Project_Documents/prd_modules/` (All relevant modules inside this directory)
3. `00_Project_Documents/ui_surface_blueprint.md`
4. `00_Project_Documents/architecture_specification.md`
5. Existing code and tests

## Research and Tool-Use Order

Apply this order to every planned worker slice:

1. Read the relevant source-of-truth documents, schema contract, current migrations, and existing implementation before deciding the change.
2. Use Laravel Boost `application-info` and version-specific `search-docs` before Laravel ecosystem code changes.
3. When an important technical, integration, or repository question remains unanswered, use the relevant available MCP, connector, or specialized tool before making an assumption.
4. Use authoritative internet research when an institutional policy, Philippine regulatory requirement, external integration contract, current standard, or mature-system benchmark remains unclear. Prefer primary official sources and record the supporting links in the worker report.
5. Research resolves gaps but does not override an approved PRD decision or expand the MVP. If authoritative evidence conflicts with the approved flow or would materially change scope, stop and report the conflict to the primary thread for a decision.
6. Implement only after the required questions are resolved, then run the slice's focused tests and regression checks.

## Planned Issues

### TAL-71 — Finance Outputs and Student Hub Finance

Deliver one bounded vertical slice that reuses TAL-68 assessment/ledger records, TAL-69 verified payment evidence and webhook posting, and the TAL-70 native output pattern.

Scope:

1. Replace the placeholder Student Hub SOA and payment-acknowledgement pages with one read-mostly Finance page showing the authenticated student's active assessment and charge lines, required downpayment, posted ledger entries and payments, ledger-derived current balance, payment schedule, pending/review status, OR mapping state, Financial Accommodation summary, and available acknowledgements.
2. Adapt the finance output builder and authenticated controllers/routes for SOA, billing slip, and payment acknowledgement. Add print-focused Blade views with the internal-billing disclaimer and browser print/save-as-PDF.
3. Log `VIEW` and `PRINT` in `output_access_logs` for `SOA`, `BILLING_SLIP`, and `PAYMENT_ACKNOWLEDGEMENT`, using the source records and copy contexts defined in Module 8.
4. Make billing slips available only from the authenticated student's active assessment and a positive currently due amount. Billing-slip generation creates no payment evidence or ledger effect.
5. Expose payment acknowledgements only after verified payment evidence and the related posted payment Ledger Entry. Show the mapped OR number when present and a Pending OR Mapping reconciliation status when absent.
6. Add a focused PayMongo checkout action backed by the existing checkout service. Use the active assessment and positive system-derived amount, record or reuse a matching pending Payment Attempt, redirect to the configured gateway, and keep verified webhook evidence plus ledger posting authoritative.
7. Retain the existing Assessment and Ledger Entry Resources. Adapt and register the existing Payment and Payment Attempt Resources for Accounting evidence, exception, acknowledgement, and OR-mapping work. Do not add a custom Accounting dashboard.
8. Correct the student-facing finance inventory that refers to removed balance, payment-status, or running-balance fields. Keep the Finance page and Dashboard balance summary derived from active assessment, posted ledger entries, verified Payments, Payment Attempts, Payment Schedule Rows, and active Financial Accommodation records. Leave the unused broad `StudentDashboardService` outside this slice.

Expected implementation files:

- Adapt `app/Actions/Finance/FinanceEvidenceService.php` and `app/Filament/Student/Widgets/StudentProfileOverviewWidget.php`.
- Adapt `app/Actions/Integrations/Payments/CreatePaymentCheckoutSession.php` without changing the TAL-69 webhook authority.
- Adapt `app/Http/Controllers/FinanceStatementController.php` and `app/Http/Controllers/PaymentAcknowledgementController.php`; add `app/Http/Controllers/BillingSlipController.php`.
- Replace `app/Filament/Student/Pages/SoaView.php` and `app/Filament/Student/Pages/PaymentAcknowledgementView.php` with `app/Filament/Student/Pages/Finance.php` and its focused Blade view when required by the page composition.
- Adapt `app/Filament/Resources/Payments/**`, `app/Filament/Resources/PaymentAttempts/**`, the relevant finance policies, `app/Providers/Filament/AdminPanelProvider.php`, and `routes/web.php`.
- Add `resources/views/finance/statement.blade.php`, `resources/views/finance/billing-slip.blade.php`, and `resources/views/finance/payment-acknowledgement.blade.php`.
- Add `tests/Feature/TAL71FinanceOutputsStudentHubTest.php`; update `tests/Feature/StudentHubTest.php` only for the replaced page route.

Focused verification:

1. Prove the test runtime is MySQL `test_tala_db`, never `tala_db`.
2. Run `php artisan test --compact tests/Feature/TAL71FinanceOutputsStudentHubTest.php`.
3. Run `php artisan test --compact tests/Feature/TAL69PayMongoPaymentEvidenceLedgerTest.php`.
4. Run `php artisan test --compact tests/Feature/TAL68FinanceAssessmentLedgerTest.php`.
5. Run `php artisan test --compact tests/Feature/TAL70CorOutputTest.php`.
6. Run the focused Student Hub shell test, `vendor/bin/pint --dirty --format agent`, PHPStan for changed application files, and `git diff --check`.

Exclusions: server-side PDF generation, stored generated finance documents, public verification or QR lookup, official receipt printing, arbitrary student-entered payment amounts, payment-allocation redesign, broad Accounting dashboard or reconciliation expansion, dependency changes, deployment, external sync, and TAL-72 planning.
