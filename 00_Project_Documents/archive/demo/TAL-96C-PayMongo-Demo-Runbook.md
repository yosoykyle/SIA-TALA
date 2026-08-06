# TAL-96C PayMongo Test-Mode Demo Runbook

> **Archived demonstration material — do not execute as current acceptance authority.** Clinic 6 owns the current account and payment journey; provider calls require a separately authorized slice.

This runbook prepares and demonstrates one client-aligned, test-only PayMongo payment journey. It uses only `test_tala_db` and PayMongo test mode. It must never target `tala_db`, use live PayMongo keys, or move real money.

## Demonstration outcome

The audience should see one complete payment path:

1. TALA prepares the accepted 47-student client baseline and one unpaid PHP 2,000 assessment for the representative student.
2. The student sees the system-derived amount on **Student > Finance** and selects **Pay Current Due**.
3. PayMongo Hosted Checkout accepts a test card payment.
4. PayMongo sends a signed `checkout_session.payment.paid` webhook to TALA's public HTTPS endpoint.
5. TALA verifies the webhook, records one verified Payment, posts one payment Ledger Entry, updates the enrollment finance gate, and records notification evidence.
6. Accounting sees the authoritative attempt, payment, ledger, and pending physical-OR mapping state.
7. Retrying the same PayMongo event creates a second delivery record but no second financial or notification effect.

The browser return page is not proof of payment. The verified webhook and the resulting posted ledger entry are the authoritative evidence.

## Test accounts and fixture

All accounts below are test-only, email-verified, and use the password `password`.

| Role | Email | Demonstration purpose |
| --- | --- | --- |
| Student | `student.demo@example.test` | View the assessment and complete Hosted Checkout |
| Accounting | `accounting.demo@example.test` | Inspect Payment Queue, Confirmed Payments, Ledger Entries, and OR mapping state |
| System Super Admin | `system-admin.demo@example.test` | Inspect Integration Status and operational evidence |

The guarded fixture creates exactly one pending-payment enrollment, one active course enrollment from the student's own curriculum, one test-only fixed charge rule, one active assessment, one due payment-schedule row, and one PHP 2,000 charge ledger entry. It does not create a payment attempt, payment, or webhook record. Those records must arise from the demonstrated checkout.

## Safety boundary

- Use MySQL database `test_tala_db` only.
- Keep PayMongo in test mode with `pk_test_...` and `sk_test_...` credentials and `PAYMONGO_LIVEMODE=false`.
- Never print, paste into documentation, or commit PayMongo credentials or the webhook signing secret.
- The credentials previously shared outside a secure secret store must be rotated before provider acceptance. Rotation is a human-gated PayMongo dashboard action and is not performed by the fixture command.
- Do not rebuild or truncate any database without explicit approval and a fresh check that the target is exactly `test_tala_db`.
- Physical Official Receipt (OR) issuance remains an Accounting/cashier process. TALA records the verified online payment and later maps the paper OR number.

## Prerequisites

Confirm the following before the demonstration:

- MySQL is running and `test_tala_db` exists.
- PHP dependencies are installed.
- ngrok is installed and authenticated.
- The PayMongo dashboard is in **test mode**.
- The local `.env` contains current test credentials and the signing secret for the enabled test webhook endpoint. Do not display their values.
- Port `8001` is available for Laravel and port `4040` is available for ngrok inspection.

PayMongo's current official test-mode behavior and test cards are documented in [Payment Acceptance Testing](https://docs.paymongo.com/docs/payment-acceptance-testing). Hosted Checkout behavior is documented in [Hosted Checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout).

## 1. Load the guarded environment

Open PowerShell in the repository root. Use this environment in every PowerShell process that runs Laravel or a queue worker:

```powershell
$env:APP_ENV = 'testing'
$env:APP_DEBUG = 'false'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
$env:CACHE_STORE = 'database'
$env:QUEUE_CONNECTION = 'database'
$env:DB_QUEUE_RETRY_AFTER = '420'
$env:MAIL_MAILER = 'array'
$env:TALA_PAYMENT_GATEWAY_DRIVER = 'paymongo'
$env:PAYMONGO_BASE_URL = 'https://api.paymongo.com'
$env:PAYMONGO_LIVEMODE = 'false'

php artisan config:clear
```

The PayMongo credentials remain in `.env`; do not assign or echo them in the terminal. `config:clear` ensures that a stale cached configuration cannot override the guarded process values.

Before preparing the fixture, run the automated gate for the same Filament header action used by the student:

```powershell
php artisan test --compact `
    --filter='test_student_finance_checkout_(ignores|action)' `
    tests/Feature/TAL95ACheckoutReliabilityTest.php
```

All four tests must pass. They prove that **Pay Current Due** derives the authoritative amount, redirects through the Filament action, reuses an active attempt, follows the PayMongo V2 request contract through a simulated provider response, and shows a safe provider-failure message. They never contact PayMongo and do not replace the later test-mode walkthrough.

## 2. Prepare the deterministic unpaid fixture

Run:

```powershell
php artisan acceptance:prepare-paymongo-demo --no-interaction
```

Expected result:

```text
TAL-96C PayMongo demonstration fixture ready.
outcome=created
student=student.demo@example.test
amount_due=2000.00
readiness=PASS
```

The command also prints local enrollment, assessment, and course-enrollment identifiers. Their numeric values can differ after a clean rebuild.

`outcome=already_present` is safe: the command proved that the exact unpaid fixture already exists and made no changes. If the command reports partial, changed, paid, or conflicting operational data, stop. It deliberately refuses to repair or overwrite evidence. A fresh fixture then requires separately approved reconstruction of only `test_tala_db`.

## 3. Start the local services and HTTPS tunnel

Keep the guarded environment loaded. Start Laravel on port `8001`:

```powershell
$php = (Get-Command php).Source
$root = (Get-Location).Path

$app = Start-Process -FilePath $php `
    -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8001', '--no-reload') `
    -WorkingDirectory $root `
    -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $env:TEMP 'tal96c-app.out.log') `
    -RedirectStandardError (Join-Path $env:TEMP 'tal96c-app.err.log') `
    -PassThru

$queue = Start-Process -FilePath $php `
    -ArgumentList @('artisan', 'queue:work', 'database', '--queue=default', '--timeout=120', '--sleep=1', '--tries=3', '--no-interaction') `
    -WorkingDirectory $root `
    -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $env:TEMP 'tal96c-queue.out.log') `
    -RedirectStandardError (Join-Path $env:TEMP 'tal96c-queue.err.log') `
    -PassThru

$ngrok = Start-Process -FilePath 'ngrok' `
    -ArgumentList @('http', '8001') `
    -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $env:TEMP 'tal96c-ngrok.out.log') `
    -RedirectStandardError (Join-Path $env:TEMP 'tal96c-ngrok.err.log') `
    -PassThru
```

After a few seconds, obtain the current public HTTPS address without hard-coding a previous ngrok domain:

```powershell
$tunnels = (Invoke-RestMethod 'http://127.0.0.1:4040/api/tunnels').tunnels
$publicUrl = $tunnels |
    Where-Object { $_.proto -eq 'https' } |
    Select-Object -ExpandProperty public_url -First 1
$webhookUrl = "$publicUrl/api/webhooks/paymongo"

"Public application URL: $publicUrl"
"Webhook URL: $webhookUrl"
"Port 8001 ready: $([bool](Get-NetTCPConnection -State Listen -LocalPort 8001 -ErrorAction SilentlyContinue))"
"Port 4040 ready: $([bool](Get-NetTCPConnection -State Listen -LocalPort 4040 -ErrorAction SilentlyContinue))"
```

If either readiness value is `False`, stop and inspect the corresponding log in `$env:TEMP`. Do not start a second copy on the same port.

## 4. Human-gated PayMongo dashboard check

This step changes the PayMongo test dashboard and therefore requires explicit provider-acceptance authorization. Login, CAPTCHA, 2FA, and secret rotation remain human actions.

In **PayMongo test mode > Developers > Webhooks**, confirm one enabled webhook with:

- Endpoint URL: the current `$webhookUrl`
- Event: `checkout_session.payment.paid`
- Signing secret: the same current secret stored locally as `PAYMONGO_WEBHOOK_SIG`

Do not expose the signing secret on screen during the presentation. If the endpoint is recreated or its secret is rotated, update the local secret securely, restart Laravel and the queue worker so they load it, and run `php artisan config:clear` before checkout.

The webhook URL cannot be `localhost` because PayMongo runs outside the laptop and cannot reach the laptop's loopback interface. ngrok provides the temporary public HTTPS route. A deployed TALA domain will replace ngrok in a hosted environment.

## 5. Student payment walkthrough

Open `$publicUrl/student/login` and sign in as `student.demo@example.test` / `password`.

1. Open **Finance**.
2. Confirm **Assessment Total**, **Required Downpayment**, and **Current Amount Due** are PHP 2,000.00.
3. Point out the active charge and the due payment-schedule row.
4. Select **Pay Current Due** once. TALA derives the amount from the active assessment; the student cannot type an arbitrary amount.
5. Confirm that the browser leaves Student Finance and opens a URL on `checkout.paymongo.com`. This redirect and the newly stored Payment Attempt are the acceptance evidence for checkout initiation.
6. On PayMongo Hosted Checkout, use the official successful test card `4343 4343 4343 4345`, any future expiry, and any three-digit CVC. Use fictitious test contact and billing details.
7. Complete the checkout and return to TALA.

Expected immediate result: PayMongo displays a successful test payment and redirects to Student Finance. The page may briefly show processing while the signed webhook is admitted and the queue worker posts the evidence. Do not click **Pay Current Due** again.

If the browser remains on Student Finance, stop. Record any visible notification, inspect the current app/browser logs, and confirm whether a Payment Attempt was created. Do not run `integrations:paymongo-sandbox-checkout` as a substitute: that command is an operator diagnostic and cannot prove that the rendered student action works.

## 6. Prove authoritative payment evidence

Wait for the queue worker to finish, then run in the guarded terminal:

```powershell
php artisan integrations:paymongo-sandbox-webhook-smoke `
    --process-pending `
    --no-interaction
```

Every named check must report `PASS`, followed by:

```text
PayMongo sandbox webhook smoke evidence verified.
amount=2000.00
ledger_amount=2000.00
```

Record the displayed payment-attempt ID for troubleshooting. Do not treat the checkout return page alone as acceptance evidence.
This smoke command verifies webhook and financial evidence after checkout; it does not prove that the student initiated checkout through the UI.

Refresh **Student > Finance**. Expected evidence:

- Current amount due is cleared by the posted payment.
- Payment status and Payment Evidence show the verified/posting result.
- The payment appears under **Available Acknowledgements**.
- OR Mapping remains pending until Accounting records the institution's physical OR.

## 7. Cross-role evidence

Sign out and open `$publicUrl/admin/login`.

### Accounting

Sign in as `accounting.demo@example.test` / `password`.

1. Open **Accounting > Payment Queue** and locate the PayMongo attempt. It must be `paid` and show PHP 2,000.00.
2. Open **Accounting > Confirmed Payments** and locate exactly one verified PayMongo payment for the representative student.
3. Open **Accounting > Ledger Entries** and locate exactly one PHP 2,000.00 payment posting linked to that payment.
4. Confirm the payment remains pending physical OR mapping. **Map OR** is a later institutional action; do not invent an OR number for this demo.
5. Open **PayMongo Reconciliation** only to explain that it lists failed or review-required exceptions. A clean auto-confirmed payment should not appear there.

### System Super Admin

Sign in as `system-admin.demo@example.test` / `password` and open **System Administration > Integration Status**. Use it to explain the configured integration boundary. Do not reveal secret values or private webhook payloads.

## 8. Duplicate-resend proof

In the PayMongo test dashboard, open the successful `checkout_session.payment.paid` event delivery and select **Retry** once. This is another explicit provider-side action.

Wait for delivery and queue processing, then rerun:

```powershell
php artisan integrations:paymongo-sandbox-webhook-smoke `
    --process-pending `
    --no-interaction
```

Expected proof:

- `webhook_calls` increases to at least `2`.
- `single_verified_payment=PASS`.
- `ledger_entry_linked=PASS` and only one payment ledger effect exists.
- `notification_evidence=PASS` and only one notification effect exists.
- The same payment-attempt, payment, and ledger identifiers remain authoritative.

This proves idempotency: PayMongo may retry delivery, but TALA does not double-charge or double-post.

## Troubleshooting

| Symptom | Meaning | Correct response |
| --- | --- | --- |
| Fixture command rejects the environment | The process is not safely targeting testing/MySQL/`test_tala_db`, PayMongo test mode, or complete test credentials | Correct the process environment. Never weaken the guard. |
| Fixture command reports conflicting data | The isolated database is not the exact unpaid fixture | Stop. Request approval before rebuilding only `test_tala_db`. |
| **Pay Current Due** is disabled | No positive current due is currently available | Recheck the fixture and Payment Queue. Do not create a second assessment or arbitrary amount. |
| **Pay Current Due** returns to the same page without a Hosted Checkout redirect | The UI initiation path did not complete, even if the Livewire request returned HTTP 200 | Record the visible notification and current logs, check whether a Payment Attempt exists, and stop. Do not substitute the CLI checkout command. |
| PayMongo delivery returns `401 Unauthorized` | The endpoint secret loaded by Laravel does not match the dashboard endpoint secret, or the app process did not reload it | Rotate or reconcile the test endpoint secret as a human-gated action, clear config, and restart app/worker. Never log the secret. |
| Payment remains processing | The webhook is stored but its queued job has not completed | Inspect the queue log; run the smoke command with `--process-pending`. Do not repeat checkout. |
| No webhook delivery appears | The dashboard URL is stale, disabled, or not public HTTPS | Replace it with the current `$webhookUrl` and confirm `checkout_session.payment.paid` is selected. |
| Reconciliation is empty after success | The payment auto-confirmed without an exception | This is expected. Confirm it under Payment Queue, Confirmed Payments, and Ledger Entries. |
| Retry creates a failed smoke check | Duplicate handling or evidence consistency is not proven | Stop the demo and retain the records for diagnosis. Do not manually repair financial evidence. |

PayMongo webhook acknowledgement and retry expectations are documented in [Retry Logic](https://docs.paymongo.com/docs/developer-tools-retry-logic) and [Developer Best Practices](https://docs.paymongo.com/docs/developer-tools-best-practices-1).

## Stop and clear the acceptance environment

After the walkthrough, stop only the processes launched in this session:

```powershell
@($app, $queue, $ngrok) |
    Where-Object { $_ -and -not $_.HasExited } |
    Stop-Process

Remove-Item Env:PAYMONGO_WEBHOOK_SIG -ErrorAction SilentlyContinue
Remove-Item Env:PAYMONGO_PUBLIC_KEY -ErrorAction SilentlyContinue
Remove-Item Env:PAYMONGO_SECRET_KEY -ErrorAction SilentlyContinue
Remove-Item Env:TALA_PAYMENT_GATEWAY_DRIVER -ErrorAction SilentlyContinue
Remove-Item Env:PAYMONGO_BASE_URL -ErrorAction SilentlyContinue
Remove-Item Env:PAYMONGO_LIVEMODE -ErrorAction SilentlyContinue

"Port 8001 stopped: $(-not [bool](Get-NetTCPConnection -State Listen -LocalPort 8001 -ErrorAction SilentlyContinue))"
"Ngrok stopped: $(-not [bool](Get-NetTCPConnection -State Listen -LocalPort 4040 -ErrorAction SilentlyContinue))"
```

Removing process environment variables does not delete values stored in `.env`. Do not delete payment evidence after the presentation unless a separately approved cleanup explicitly targets the disposable `test_tala_db` records.
