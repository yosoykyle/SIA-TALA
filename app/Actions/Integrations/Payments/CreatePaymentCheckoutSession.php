<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Assessment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class CreatePaymentCheckoutSession
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DecimalMoney $money,
        private readonly FinanceEvidenceService $financeEvidence,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{payment_attempt_id:int,provider:string,provider_checkout_session_id:string,internal_reference:string,checkout_url:string,status:string,amount:string,outcome:string}
     */
    public function create(
        User $actor,
        ?int $assessmentId = null,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
        string $description = 'TALA current finance amount due',
        array $metadata = [],
    ): array {
        $finance = $this->financeEvidence->studentFinance($actor);

        if (($finance['available'] ?? false) !== true) {
            throw PaymentCheckoutException::unavailable((string) ($finance['reason'] ?? 'Student finance is not available.'));
        }

        $assessment = $finance['assessment'] ?? null;
        $profile = $finance['student_profile'] ?? null;

        if (! $assessment instanceof Assessment || ! $profile instanceof StudentProfile) {
            throw PaymentCheckoutException::unavailable('Student finance records are incomplete.');
        }

        if ($assessmentId !== null && $assessmentId !== (int) $assessment->id) {
            throw PaymentCheckoutException::unavailable('The selected assessment is not the current active assessment.');
        }

        $amount = $this->money->normalize((string) ($finance['current_due_amount'] ?? '0.00'));

        if (! $this->money->greaterThanZero($amount)) {
            throw PaymentCheckoutException::unavailable('There is no positive amount due for checkout.');
        }

        $request = new PaymentCheckoutRequest(
            studentProfileId: (int) $profile->id,
            amount: $amount,
            description: $description,
            assessmentId: (int) $assessment->id,
            channel: 'paymongo',
            termId: $assessment->enrollment?->term_id,
            enrollmentId: $assessment->enrollment_id,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            metadata: [
                ...$metadata,
                'assessment_id' => (int) $assessment->id,
                'enrollment_id' => $assessment->enrollment_id,
                'source' => $metadata['source'] ?? 'student_hub_finance',
            ],
        );

        try {
            return Cache::lock('payment-checkout:assessment:'.$assessment->id, 30)
                ->block(5, fn (): array => $this->createUnderLock($actor, $assessment, $request));
        } catch (LockTimeoutException) {
            throw PaymentCheckoutException::unavailable('Another checkout request is already being processed. Please try again.');
        }
    }

    /**
     * @return array{payment_attempt_id:int,provider:string,provider_checkout_session_id:string,internal_reference:string,checkout_url:string,status:string,amount:string,outcome:string}
     */
    private function createUnderLock(User $actor, Assessment $assessment, PaymentCheckoutRequest $request): array
    {
        $activeAttempt = PaymentAttempt::query()
            ->where('assessment_id', $assessment->id)
            ->whereIn('status', ['pending', 'under_review'])
            ->latest('id')
            ->first();

        if ($activeAttempt instanceof PaymentAttempt) {
            $recovered = $this->recoverActiveAttempt($activeAttempt, $request);

            if ($recovered !== null) {
                return $recovered;
            }
        }

        $internalReference = 'TALA-PAY-'.Str::upper((string) Str::uuid());
        $request = $this->withReference($request, $internalReference);
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $request->studentProfileId,
            'channel' => $request->channel,
            'provider' => $this->gateway->provider(),
            'internal_reference' => $internalReference,
            'amount' => $request->amount,
            'currency' => 'PHP',
            'status' => 'pending',
            'expires_at' => null,
            'metadata' => $this->requestMetadata($request),
        ]);

        activity()
            ->performedOn($attempt)
            ->causedBy($actor)
            ->withProperties([
                'assessment_id' => (int) $assessment->id,
                'student_profile_id' => $request->studentProfileId,
                'provider' => $this->gateway->provider(),
                'amount' => $request->amount,
            ])
            ->event('payment_checkout_attempt_created')
            ->log('Payment checkout attempt created');

        return $this->createProviderSession($attempt, $request, 'created');
    }

    /**
     * @return array{payment_attempt_id:int,provider:string,provider_checkout_session_id:string,internal_reference:string,checkout_url:string,status:string,amount:string,outcome:string}|null
     */
    private function recoverActiveAttempt(PaymentAttempt $attempt, PaymentCheckoutRequest $currentRequest): ?array
    {
        if ($attempt->status === 'under_review') {
            throw PaymentCheckoutException::unavailable('The previous checkout requires review before another attempt can be created.');
        }

        if ($this->money->normalize((string) $attempt->amount) !== $currentRequest->amount) {
            $this->retireChangedAmountAttempt($attempt);

            return null;
        }

        if (filled($attempt->provider_checkout_id)) {
            try {
                $session = $this->gateway->retrieveCheckoutSession((string) $attempt->provider_checkout_id);
            } catch (Throwable $exception) {
                $this->markUnderReview($attempt, 'provider_retrieval_failed');

                throw PaymentCheckoutException::unavailable('The existing checkout could not be confirmed and requires review.');
            }

            if ($this->providerSessionIsActive($session) && $this->isSafeCheckoutUrl($session->checkoutUrl, $session->provider)) {
                $this->storeProviderSession($attempt, $session);

                return $this->result($attempt->fresh(), 'reused');
            }

            if (in_array(strtolower($session->status), ['expired', 'cancelled', 'canceled'], true)) {
                $attempt->update(['status' => 'expired']);

                return null;
            }

            $this->markUnderReview($attempt, 'provider_status_unconfirmed');

            throw PaymentCheckoutException::unavailable('The existing checkout requires review before another attempt can be created.');
        }

        if ($attempt->created_at === null || $attempt->created_at->lt(CarbonImmutable::now(config('app.timezone'))->subHours(24))) {
            $this->markUnderReview($attempt, 'idempotency_window_elapsed');

            throw PaymentCheckoutException::unavailable('The previous checkout requires review before another attempt can be created.');
        }

        return $this->createProviderSession($attempt, $this->requestFromAttempt($attempt, $currentRequest), 'reused');
    }

    private function retireChangedAmountAttempt(PaymentAttempt $attempt): void
    {
        if (! filled($attempt->provider_checkout_id)) {
            $this->markUnderReview($attempt, 'amount_changed_before_provider_confirmation');

            throw PaymentCheckoutException::unavailable('The amount due changed while a checkout was unresolved. The attempt requires review.');
        }

        try {
            $session = $this->gateway->retrieveCheckoutSession((string) $attempt->provider_checkout_id);

            if ($this->providerSessionIsActive($session)) {
                $session = $this->gateway->expireCheckoutSession((string) $attempt->provider_checkout_id);
            }

            if (! in_array(strtolower($session->status), ['expired', 'cancelled', 'canceled'], true)) {
                throw new PaymentGatewayException('Provider expiry was not confirmed.', 'expiry_unconfirmed', false, true);
            }

            $attempt->update(['status' => 'expired']);
        } catch (Throwable) {
            $this->markUnderReview($attempt, 'amount_changed_provider_expiry_unconfirmed');

            throw PaymentCheckoutException::unavailable('The amount due changed, but the previous checkout could not be safely expired.');
        }
    }

    /**
     * @return array{payment_attempt_id:int,provider:string,provider_checkout_session_id:string,internal_reference:string,checkout_url:string,status:string,amount:string,outcome:string}
     */
    private function createProviderSession(PaymentAttempt $attempt, PaymentCheckoutRequest $request, string $outcome): array
    {
        try {
            $session = $this->gateway->createCheckoutSession($request, (string) $attempt->internal_reference);

            if (! $this->isSafeCheckoutUrl($session->checkoutUrl, $session->provider)) {
                throw new PaymentGatewayException('Payment provider returned an invalid checkout URL.', 'invalid_checkout_url', false, true);
            }

            $this->storeProviderSession($attempt, $session);

            return $this->result($attempt->fresh(), $outcome);
        } catch (PaymentGatewayException $exception) {
            $this->recordGatewayFailure($attempt, $exception);

            throw PaymentCheckoutException::unavailable('Payment checkout is temporarily unavailable. Please try again later.');
        } catch (Throwable) {
            $this->markUnderReview($attempt, 'unexpected_gateway_failure');

            throw PaymentCheckoutException::unavailable('Payment checkout could not be confirmed and requires review.');
        }
    }

    private function storeProviderSession(PaymentAttempt $attempt, PaymentCheckoutSession $session): void
    {
        $metadata = $this->attemptMetadata($attempt);

        $attempt->update([
            'provider' => $session->provider,
            'provider_checkout_id' => $session->checkoutSessionId,
            'provider_intent_id' => $session->metadata['payment_intent_id'] ?? $attempt->provider_intent_id,
            'status' => 'pending',
            'expires_at' => $session->metadata['expires_at'] ?? null,
            'metadata' => [
                ...$metadata,
                'checkout_url' => $session->checkoutUrl,
                'gateway' => $session->metadata,
                'provider_status' => $session->status,
            ],
        ]);
    }

    private function recordGatewayFailure(PaymentAttempt $attempt, PaymentGatewayException $exception): void
    {
        $metadata = $this->attemptMetadata($attempt);
        $attempt->update([
            'status' => $exception->indeterminate ? 'pending' : 'failed',
            'metadata' => [
                ...$metadata,
                'gateway_error' => [
                    'code' => $exception->errorCode,
                    'retryable' => $exception->retryable,
                    'indeterminate' => $exception->indeterminate,
                    'http_status' => $exception->httpStatus,
                ],
            ],
        ]);
    }

    private function markUnderReview(PaymentAttempt $attempt, string $reason): void
    {
        $metadata = $this->attemptMetadata($attempt);
        $attempt->update([
            'status' => 'under_review',
            'metadata' => [...$metadata, 'review_reason' => $reason],
        ]);
    }

    private function providerSessionIsActive(PaymentCheckoutSession $session): bool
    {
        return in_array(strtolower($session->status), ['active', 'pending', 'open'], true);
    }

    private function isSafeCheckoutUrl(string $url, string $provider): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        return $provider !== 'paymongo' || $host === 'checkout.paymongo.com';
    }

    private function withReference(PaymentCheckoutRequest $request, string $reference): PaymentCheckoutRequest
    {
        return new PaymentCheckoutRequest(
            studentProfileId: $request->studentProfileId,
            amount: $request->amount,
            description: $request->description,
            assessmentId: $request->assessmentId,
            channel: $request->channel,
            termId: $request->termId,
            enrollmentId: $request->enrollmentId,
            ledgerEntryId: $request->ledgerEntryId,
            successUrl: $request->successUrl,
            cancelUrl: $request->cancelUrl,
            metadata: [...$request->metadata, 'tala_reference' => $reference],
        );
    }

    /** @return array<string, mixed> */
    private function requestMetadata(PaymentCheckoutRequest $request): array
    {
        return [
            'request' => [
                'description' => $request->description,
                'success_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
                'metadata' => $request->metadata,
            ],
        ];
    }

    private function requestFromAttempt(PaymentAttempt $attempt, PaymentCheckoutRequest $fallback): PaymentCheckoutRequest
    {
        $stored = data_get($this->attemptMetadata($attempt), 'request', []);

        return new PaymentCheckoutRequest(
            studentProfileId: (int) $attempt->student_profile_id,
            amount: $this->money->normalize((string) $attempt->amount),
            description: (string) ($stored['description'] ?? $fallback->description),
            assessmentId: (int) $attempt->assessment_id,
            channel: (string) $attempt->channel,
            termId: $fallback->termId,
            enrollmentId: $fallback->enrollmentId,
            successUrl: $stored['success_url'] ?? $fallback->successUrl,
            cancelUrl: $stored['cancel_url'] ?? $fallback->cancelUrl,
            metadata: is_array($stored['metadata'] ?? null)
                ? $stored['metadata']
                : $fallback->metadata,
        );
    }

    /**
     * @return array{payment_attempt_id:int,provider:string,provider_checkout_session_id:string,internal_reference:string,checkout_url:string,status:string,amount:string,outcome:string}
     */
    private function result(PaymentAttempt $attempt, string $outcome): array
    {
        return [
            'payment_attempt_id' => (int) $attempt->id,
            'provider' => (string) $attempt->provider,
            'provider_checkout_session_id' => (string) $attempt->provider_checkout_id,
            'internal_reference' => (string) $attempt->internal_reference,
            'checkout_url' => (string) data_get($this->attemptMetadata($attempt), 'checkout_url', ''),
            'status' => (string) $attempt->status,
            'amount' => $this->money->normalize((string) $attempt->amount),
            'outcome' => $outcome,
        ];
    }

    /** @return array<string, mixed> */
    private function attemptMetadata(PaymentAttempt $attempt): array
    {
        $metadata = $attempt->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }
}
