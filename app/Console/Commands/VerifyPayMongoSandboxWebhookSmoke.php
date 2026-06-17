<?php

namespace App\Console\Commands;

use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class VerifyPayMongoSandboxWebhookSmoke extends Command
{
    protected $signature = 'integrations:paymongo-sandbox-webhook-smoke
        {--attempt-id= : payment_attempts.id created by integrations:paymongo-sandbox-checkout}
        {--checkout-session-id= : PayMongo checkout session id, e.g. cs_test_*}
        {--process-pending : Process matching stored webhook calls before verifying evidence}
        {--recent-minutes=1440 : Only auto-select attempts created within this many minutes}';

    protected $description = 'Verify live PayMongo sandbox webhook evidence posted exactly one payment and ledger entry.';

    public function handle(DecimalMoney $money, PayMongoWebhookProcessor $processor): int
    {
        if ((bool) config('paymongo.livemode')) {
            $this->error('Refusing to run sandbox smoke verification while PAYMONGO_LIVEMODE=true.');

            return self::FAILURE;
        }

        $attempt = $this->paymentAttempt();

        if (! $attempt instanceof stdClass) {
            $this->error('No PayMongo sandbox payment attempt matched the selector.');
            $this->line('Run integrations:paymongo-sandbox-checkout first, complete the checkout URL, then rerun this command with --attempt-id or --checkout-session-id.');

            return self::FAILURE;
        }

        if ((bool) $this->option('process-pending')) {
            $this->processPendingWebhooks($attempt, $processor);
            $attempt = DB::table('payment_attempts')->where('id', $attempt->id)->first();
        }

        if (! $attempt instanceof stdClass) {
            $this->error('Payment attempt disappeared while verifying smoke evidence.');

            return self::FAILURE;
        }

        $payment = DB::table('payments')
            ->where('payment_attempt_id', $attempt->id)
            ->where('channel', 'paymongo')
            ->where('status', 'confirmed')
            ->first();

        $ledgerEntry = $payment instanceof stdClass
            ? DB::table('ledger_entries')->where('id', $payment->ledger_entry_id)->first()
            : null;

        $providerReference = $attempt->provider_checkout_session_id ?: $attempt->provider_payment_id;
        $webhookCalls = $this->webhookCallCount($attempt, $payment);
        $expectedLedgerAmount = $money->subtract('0.00', $money->normalize((string) $attempt->amount));

        $checks = [
            'attempt_paid' => $attempt->status === 'paid',
            'provider_event_recorded' => filled($attempt->provider_event_id),
            'provider_reference_recorded' => filled($providerReference),
            'webhook_call_stored' => $webhookCalls >= 1,
            'single_confirmed_payment' => $this->confirmedPaymentCount($attempt) === 1,
            'ledger_entry_linked' => $ledgerEntry instanceof stdClass,
            'ledger_is_payment_credit' => $ledgerEntry instanceof stdClass
                && $ledgerEntry->entry_type === 'payment'
                && $ledgerEntry->reference_type === 'payment'
                && (int) $ledgerEntry->reference_id === (int) $payment->id
                && $money->normalize((string) $ledgerEntry->amount) === $expectedLedgerAmount,
        ];

        foreach ($checks as $check => $passed) {
            $this->line(sprintf('%s=%s', $check, $passed ? 'PASS' : 'FAIL'));
        }

        if (in_array(false, $checks, true)) {
            $this->error('PayMongo sandbox webhook smoke evidence is incomplete.');
            $this->line('If webhook_call_stored=PASS but attempt_paid=FAIL, rerun with --process-pending or start the queue worker.');

            return self::FAILURE;
        }

        $this->info('PayMongo sandbox webhook smoke evidence verified.');
        $this->line('payment_attempt_id='.$attempt->id);
        $this->line('provider_event_id='.$attempt->provider_event_id);
        $this->line('provider_reference='.$providerReference);
        $this->line('payment_id='.$payment->id);
        $this->line('ledger_entry_id='.$ledgerEntry->id);
        $this->line('amount='.$money->normalize((string) $attempt->amount));
        $this->line('ledger_amount='.$money->normalize((string) $ledgerEntry->amount));
        $this->line('webhook_calls='.$webhookCalls);

        return self::SUCCESS;
    }

    private function paymentAttempt(): ?stdClass
    {
        $query = DB::table('payment_attempts')
            ->where('channel', 'paymongo')
            ->where('provider', 'paymongo');

        $attemptId = $this->option('attempt-id');
        $checkoutSessionId = $this->option('checkout-session-id');

        if (filled($attemptId)) {
            return $query->where('id', (int) $attemptId)->first();
        }

        if (filled($checkoutSessionId)) {
            return $query->where('provider_checkout_session_id', trim((string) $checkoutSessionId))->first();
        }

        $recentMinutes = max(1, (int) $this->option('recent-minutes'));

        return $query
            ->where('created_at', '>=', CarbonImmutable::now(config('app.timezone'))->subMinutes($recentMinutes)->toDateTimeString())
            ->latest('id')
            ->first();
    }

    private function processPendingWebhooks(stdClass $attempt, PayMongoWebhookProcessor $processor): void
    {
        $webhookCalls = $this->matchingWebhookCalls($attempt)
            ->whereNull('processed_at')
            ->pluck('id');

        foreach ($webhookCalls as $webhookCallId) {
            $result = $processor->process((int) $webhookCallId);
            $this->line('processed_webhook_call_id='.$webhookCallId.' status='.$result['status']);
        }
    }

    private function matchingWebhookCalls(stdClass $attempt): Builder
    {
        $query = DB::table('webhook_calls')->where('name', 'paymongo');

        $providerReference = $attempt->provider_checkout_session_id ?: $attempt->provider_payment_id;

        if (filled($providerReference)) {
            $this->wherePayloadContains($query, (string) $providerReference);
        } elseif (filled($attempt->provider_event_id)) {
            $this->wherePayloadContains($query, (string) $attempt->provider_event_id);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function confirmedPaymentCount(stdClass $attempt): int
    {
        return DB::table('payments')
            ->where('payment_attempt_id', $attempt->id)
            ->where('channel', 'paymongo')
            ->where('status', 'confirmed')
            ->count();
    }

    private function webhookCallCount(stdClass $attempt, ?stdClass $payment): int
    {
        if ($payment instanceof stdClass && filled($payment->meta)) {
            $meta = json_decode((string) $payment->meta, true);

            if (is_array($meta) && filled($meta['webhook_call_id'] ?? null)) {
                return DB::table('webhook_calls')
                    ->where('name', 'paymongo')
                    ->where('id', (int) $meta['webhook_call_id'])
                    ->count();
            }
        }

        return $this->matchingWebhookCalls($attempt)->count();
    }

    private function wherePayloadContains(Builder $query, string $value): void
    {
        $castType = DB::connection()->getDriverName() === 'sqlite' ? 'TEXT' : 'CHAR';

        $query->whereRaw("CAST(payload AS {$castType}) LIKE ?", [
            '%'.$value.'%',
        ]);
    }
}
