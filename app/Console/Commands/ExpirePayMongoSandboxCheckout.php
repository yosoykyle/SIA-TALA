<?php

namespace App\Console\Commands;

use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PayMongoSandboxEnvironmentGuard;
use App\Models\PaymentAttempt;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ExpirePayMongoSandboxCheckout extends Command
{
    protected $signature = 'integrations:paymongo-sandbox-expire
        {--attempt-id= : Existing PayMongo payment_attempts.id}
        {--checkout-session-id= : Existing PayMongo checkout session id}';

    protected $description = 'Explicitly expire one existing PayMongo test-mode Checkout Session.';

    public function handle(
        PaymentGateway $gateway,
        PayMongoSandboxEnvironmentGuard $environmentGuard,
    ): int {
        try {
            $environmentGuard->assertSafe(['secret_key']);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($gateway->provider() !== 'paymongo') {
            $this->error('The configured payment gateway is not PayMongo.');

            return self::FAILURE;
        }

        $attempt = $this->selectedAttempt();

        if (! $attempt instanceof PaymentAttempt) {
            $this->error('One existing PayMongo payment attempt must match the explicit selector.');

            return self::FAILURE;
        }

        try {
            $outcome = Cache::lock('payment-checkout:assessment:'.$attempt->assessment_id, 30)
                ->block(5, fn (): string => $this->expireUnderLock($attempt->id, $gateway));
        } catch (LockTimeoutException) {
            $this->error('The selected assessment is currently being processed. Try again.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('PayMongo sandbox expiry could not be confirmed. No local expiry was recorded.');

            return self::FAILURE;
        }

        $attempt = $attempt->fresh();
        $this->info($outcome === 'already_expired'
            ? 'PayMongo sandbox checkout was already expired.'
            : 'PayMongo sandbox checkout expiry confirmed.');
        $this->line('payment_attempt_id='.$attempt->id);
        $this->line('provider_checkout_session_id='.$attempt->provider_checkout_id);
        $this->line('status='.$attempt->status);

        return self::SUCCESS;
    }

    private function selectedAttempt(): ?PaymentAttempt
    {
        $attemptId = trim((string) $this->option('attempt-id'));
        $checkoutSessionId = trim((string) $this->option('checkout-session-id'));

        if (($attemptId === '') === ($checkoutSessionId === '')) {
            return null;
        }

        return PaymentAttempt::query()
            ->where('provider', 'paymongo')
            ->where('channel', 'paymongo')
            ->when(
                $attemptId !== '',
                fn ($query) => $query->whereKey((int) $attemptId),
                fn ($query) => $query->where('provider_checkout_id', $checkoutSessionId),
            )
            ->first();
    }

    private function expireUnderLock(int $attemptId, PaymentGateway $gateway): string
    {
        $attempt = PaymentAttempt::query()->findOrFail($attemptId);

        if ($attempt->status === 'expired') {
            return 'already_expired';
        }

        if ($attempt->status !== 'pending' || ! filled($attempt->provider_checkout_id)) {
            throw new RuntimeException('The selected payment attempt is not eligible for expiry.');
        }

        $session = $gateway->expireCheckoutSession((string) $attempt->provider_checkout_id);

        if ($session->provider !== 'paymongo'
            || $session->checkoutSessionId !== $attempt->provider_checkout_id
            || ! in_array(strtolower($session->status), ['expired', 'cancelled', 'canceled'], true)) {
            throw new RuntimeException('The provider did not confirm expiry for the selected checkout.');
        }

        DB::transaction(function () use ($attemptId, $session): void {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);

            if ($attempt->status === 'paid') {
                throw new RuntimeException('A paid attempt cannot be expired.');
            }

            if ($attempt->status !== 'pending' && $attempt->status !== 'expired') {
                throw new RuntimeException('The payment attempt changed state during expiry.');
            }

            $storedMetadata = $attempt->getAttribute('metadata');
            $metadata = is_array($storedMetadata) ? $storedMetadata : [];
            $attempt->forceFill([
                'status' => 'expired',
                'metadata' => [
                    ...$metadata,
                    'provider_status' => strtolower($session->status),
                    'explicitly_expired_at' => now()->toIso8601String(),
                ],
            ])->save();
        }, 3);

        return 'expired';
    }
}
