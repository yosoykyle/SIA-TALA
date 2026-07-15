<?php

namespace App\Console\Commands;

use App\Actions\Integrations\Payments\PayMongoSandboxEnvironmentGuard;
use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Models\Assessment;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VerifyPayMongoSandboxWebhookSmoke extends Command
{
    protected $signature = 'integrations:paymongo-sandbox-webhook-smoke
        {--attempt-id= : payment_attempts.id created by integrations:paymongo-sandbox-checkout}
        {--checkout-session-id= : PayMongo checkout session id, e.g. cs_test_*}
        {--process-pending : Process matching stored webhook calls before verifying evidence}
        {--recent-minutes=1440 : Only auto-select attempts created within this many minutes}';

    protected $description = 'Verify PayMongo test-mode webhook evidence posted exactly one payment and ledger entry.';

    public function handle(
        DecimalMoney $money,
        PayMongoWebhookProcessor $processor,
        PayMongoSandboxEnvironmentGuard $environmentGuard,
    ): int {
        try {
            $environmentGuard->assertSafe(['secret_key', 'webhook_signature']);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $attempt = $this->paymentAttempt();

        if (! $attempt instanceof PaymentAttempt) {
            $this->error('No PayMongo sandbox payment attempt matched the selector.');
            $this->line('Run integrations:paymongo-sandbox-checkout first, complete the checkout URL, then rerun this command with --attempt-id or --checkout-session-id.');

            return self::FAILURE;
        }

        if ((bool) $this->option('process-pending')) {
            $this->processPendingWebhooks($attempt, $processor);
            $attempt->refresh();
        }

        $payment = Payment::query()
            ->where('payment_attempt_id', $attempt->id)
            ->where('channel', 'paymongo')
            ->where('evidence_status', 'verified')
            ->first();
        $ledgerEntry = $payment instanceof Payment
            ? LedgerEntry::query()
                ->where('payment_id', $payment->id)
                ->where('direction', LedgerEntry::DirectionPayment)
                ->where('source_type', Payment::class)
                ->where('source_id', $payment->id)
                ->first()
            : null;
        $providerEvent = $payment instanceof Payment
            ? $this->processedProviderEvent($payment)
            : null;
        $notificationEvent = $payment instanceof Payment
            ? OperationalEvent::query()
                ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
                ->where('related_record_type', Payment::class)
                ->where('related_record_id', $payment->id)
                ->first()
            : null;
        $assessment = Assessment::query()->with('enrollment')->find($attempt->assessment_id);
        $webhookCalls = $this->webhookCallCount($attempt, $providerEvent);
        $attemptAmount = $money->normalize((string) $attempt->amount);

        $checks = [
            'attempt_paid' => $attempt->status === 'paid',
            'provider_checkout_recorded' => filled($attempt->provider_checkout_id),
            'provider_reference_recorded' => $payment instanceof Payment
                && str_starts_with((string) $payment->provider_reference, 'paymongo:'),
            'webhook_call_stored' => $webhookCalls >= 1,
            'single_verified_payment' => Payment::query()
                ->where('payment_attempt_id', $attempt->id)
                ->where('channel', 'paymongo')
                ->where('evidence_status', 'verified')
                ->count() === 1,
            'ledger_entry_linked' => $ledgerEntry instanceof LedgerEntry,
            'ledger_is_payment_posting' => $ledgerEntry instanceof LedgerEntry
                && $ledgerEntry->state === 'posted'
                && $money->greaterThanZero((string) $ledgerEntry->amount)
                && $money->normalize((string) $ledgerEntry->amount) === $attemptAmount,
            'processed_provider_event' => $providerEvent instanceof OperationalEvent,
            'finance_gate_effect' => in_array($assessment?->enrollment?->status, ['pre_enrolled', 'officially_enrolled'], true),
            'notification_evidence' => $notificationEvent instanceof OperationalEvent,
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
        $this->line('provider_checkout_session_id='.$attempt->provider_checkout_id);
        $this->line('provider_reference='.$payment->provider_reference);
        $this->line('operational_event_id='.$providerEvent->id);
        $this->line('payment_id='.$payment->id);
        $this->line('ledger_entry_id='.$ledgerEntry->id);
        $this->line('amount='.$attemptAmount);
        $this->line('ledger_amount='.$money->normalize((string) $ledgerEntry->amount));
        $this->line('webhook_calls='.$webhookCalls);

        return self::SUCCESS;
    }

    private function paymentAttempt(): ?PaymentAttempt
    {
        $query = PaymentAttempt::query()
            ->where('channel', 'paymongo')
            ->where('provider', 'paymongo');
        $attemptId = trim((string) $this->option('attempt-id'));
        $checkoutSessionId = trim((string) $this->option('checkout-session-id'));

        if ($attemptId !== '') {
            return $query->find((int) $attemptId);
        }

        if ($checkoutSessionId !== '') {
            return $query->where('provider_checkout_id', $checkoutSessionId)->first();
        }

        $recentMinutes = max(1, (int) $this->option('recent-minutes'));

        return $query
            ->where('created_at', '>=', CarbonImmutable::now(config('app.timezone'))->subMinutes($recentMinutes))
            ->latest('id')
            ->first();
    }

    private function processPendingWebhooks(PaymentAttempt $attempt, PayMongoWebhookProcessor $processor): void
    {
        $webhookCalls = $this->matchingWebhookCalls($attempt)
            ->whereNull('processed_at')
            ->pluck('id');

        foreach ($webhookCalls as $webhookCallId) {
            $result = $processor->process((int) $webhookCallId);
            $this->line('processed_webhook_call_id='.$webhookCallId.' status='.$result['status']);
        }
    }

    private function matchingWebhookCalls(PaymentAttempt $attempt): Builder
    {
        $query = DB::table('webhook_calls')->where('name', 'paymongo');
        $providerReference = $attempt->provider_checkout_id
            ?? $attempt->provider_intent_id
            ?? $attempt->internal_reference;

        if (filled($providerReference)) {
            $this->wherePayloadContains($query, (string) $providerReference);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function processedProviderEvent(Payment $payment): ?OperationalEvent
    {
        return OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationPayMongo)
            ->where('channel', OperationalEvent::ChannelWebhook)
            ->where('status', OperationalEvent::StatusProcessed)
            ->where('related_record_type', Payment::class)
            ->where('related_record_id', $payment->id)
            ->latest('id')
            ->first();
    }

    private function webhookCallCount(PaymentAttempt $attempt, ?OperationalEvent $providerEvent): int
    {
        if ($providerEvent instanceof OperationalEvent) {
            $webhookCallIds = collect([
                data_get($providerEvent->diagnostics, 'webhook_call_id'),
                data_get($providerEvent->diagnostics, 'latest_webhook_call_id'),
            ])
                ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn (mixed $id): int => (int) $id)
                ->unique();

            if ($webhookCallIds->isNotEmpty()) {
                return DB::table('webhook_calls')
                    ->where('name', 'paymongo')
                    ->whereIn('id', $webhookCallIds)
                    ->count();
            }
        }

        return $this->matchingWebhookCalls($attempt)->count();
    }

    private function wherePayloadContains(Builder $query, string $value): void
    {
        $query->whereRaw('CAST(payload AS CHAR) LIKE ?', ['%'.$value.'%']);
    }
}
