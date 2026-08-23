<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Assessment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreatePaymentCheckoutSession
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DecimalMoney $money,
        private readonly FinanceEvidenceService $financeEvidence,
        private readonly ExactDuePaymentSnapshotService $snapshots,
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

        $account = $assessment->termAccount;

        if (! $account instanceof TermAccount || $account->credential_user_id !== $actor->id) {
            throw PaymentCheckoutException::unavailable('The current Term Account is not available for checkout.');
        }

        $request = new PaymentCheckoutRequest(
            studentProfileId: (int) $profile->id,
            amount: '0.00',
            description: $description,
            assessmentId: (int) $assessment->id,
            termAccountId: (int) $account->id,
            assessmentVersion: (int) $assessment->version,
            channel: 'paymongo',
            termId: $assessment->enrollment?->term_id,
            enrollmentId: $assessment->enrollment_id,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            metadata: [
                ...$metadata,
                'term_account_id' => (int) $account->id,
                'enrollment_id' => $assessment->enrollment_id,
                'source' => $metadata['source'] ?? 'student_hub_finance',
            ],
        );

        try {
            return Cache::lock('payment-checkout:term-account:'.$account->id, 30)
                ->block(5, fn (): array => $this->createUnderLock($actor, $account, $request));
        } catch (LockTimeoutException) {
            throw PaymentCheckoutException::unavailable('Another checkout request is already being processed. Please try again.');
        }
    }

    /**
     * @return array{payment_attempt_id:int,provider:string,provider_checkout_session_id:string,internal_reference:string,checkout_url:string,status:string,amount:string,outcome:string}
     */
    private function createUnderLock(User $actor, TermAccount $account, PaymentCheckoutRequest $request): array
    {
        try {
            $snapshot = $this->snapshots->forAccount($account->fresh());
        } catch (PaymentAttemptSnapshotException $exception) {
            throw PaymentCheckoutException::unavailable(match ($exception->reason) {
                'positive_current_due_unavailable' => 'There is no positive current due for checkout.',
                default => 'The current Term Account is not ready for checkout.',
            });
        }
        $request = $this->withSnapshot($request, $snapshot);
        $activeAttempt = PaymentAttempt::query()
            ->where('term_account_id', $account->id)
            ->whereIn('status', PaymentAttempt::ActiveStatuses)
            ->latest('id')
            ->first();

        if ($activeAttempt instanceof PaymentAttempt) {
            $recovered = $this->recoverActiveAttempt($activeAttempt, $request);

            if ($recovered !== null) {
                return $recovered;
            }
        }

        $attempt = DB::transaction(function () use ($account, $request): PaymentAttempt {
            TermAccount::query()->lockForUpdate()->findOrFail($account->id);
            $existing = PaymentAttempt::query()
                ->where('term_account_id', $account->id)
                ->whereIn('status', PaymentAttempt::ActiveStatuses)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PaymentAttempt) {
                return $existing;
            }

            $internalReference = 'TALA-PAY-'.Str::upper((string) Str::uuid());
            $storedRequest = $this->withReference($request, $internalReference);
            $created = PaymentAttempt::query()->create([
                'assessment_id' => $storedRequest->assessmentId,
                'term_account_id' => $storedRequest->termAccountId,
                'student_profile_id' => $storedRequest->studentProfileId,
                'assessment_version' => $storedRequest->assessmentVersion,
                'snapshot_created_at' => data_get($storedRequest->metadata, 'snapshot_created_at'),
                'snapshot_checksum' => $storedRequest->snapshotChecksum,
                'channel' => $storedRequest->channel,
                'provider' => $this->gateway->provider(),
                'internal_reference' => $internalReference,
                'amount' => $storedRequest->amount,
                'currency' => 'PHP',
                'status' => PaymentAttempt::StatusPending,
                'expires_at' => null,
                'metadata' => $this->requestMetadata($storedRequest),
            ]);

            foreach ((array) data_get($storedRequest->metadata, 'snapshot_obligations', []) as $target) {
                $created->obligations()->create([
                    'assessment_obligation_id' => (int) $target['id'],
                    'sequence' => (int) $target['sequence'],
                    'amount' => (string) $target['amount'],
                ]);
            }

            return $created;
        }, attempts: 3);

        $request = $this->requestFromAttempt($attempt, $request);

        activity()
            ->performedOn($attempt)
            ->causedBy($actor)
            ->withProperties([
                'term_account_id' => (int) $account->id,
                'assessment_id' => (int) $attempt->assessment_id,
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
        if ($attempt->status === PaymentAttempt::StatusReviewRequired) {
            throw PaymentCheckoutException::unavailable('The previous checkout requires review before another attempt can be created.');
        }

        if ($this->money->normalize((string) $attempt->amount) !== $currentRequest->amount
            || ! is_string($attempt->snapshot_checksum)
            || $attempt->snapshot_checksum !== $currentRequest->snapshotChecksum) {
            $this->retireChangedSnapshotAttempt($attempt);

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
                $attempt->update(['status' => strtolower($session->status) === 'expired'
                    ? PaymentAttempt::StatusExpired
                    : PaymentAttempt::StatusCancelled]);

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

    private function retireChangedSnapshotAttempt(PaymentAttempt $attempt): void
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

            $attempt->update(['status' => strtolower($session->status) === 'expired'
                ? PaymentAttempt::StatusExpired
                : PaymentAttempt::StatusCancelled]);
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
            'status' => PaymentAttempt::StatusPending,
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
            'status' => $exception->indeterminate
                ? PaymentAttempt::StatusReviewRequired
                : PaymentAttempt::StatusFailed,
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
            'status' => PaymentAttempt::StatusReviewRequired,
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
            termAccountId: $request->termAccountId,
            assessmentVersion: $request->assessmentVersion,
            snapshotChecksum: $request->snapshotChecksum,
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
            termAccountId: (int) $attempt->term_account_id,
            assessmentVersion: (int) $attempt->assessment_version,
            snapshotChecksum: (string) $attempt->snapshot_checksum,
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

    /**
     * @param  array{term_account_id:int,assessment_id:int,assessment_version:int,amount:string,checksum:string,created_at:CarbonImmutable,obligations:list<array{id:int,sequence:int,code:string,label:string,amount:string}>}  $snapshot
     */
    private function withSnapshot(PaymentCheckoutRequest $request, array $snapshot): PaymentCheckoutRequest
    {
        return new PaymentCheckoutRequest(
            studentProfileId: $request->studentProfileId,
            amount: $snapshot['amount'],
            description: $request->description,
            assessmentId: $snapshot['assessment_id'],
            termAccountId: $snapshot['term_account_id'],
            assessmentVersion: $snapshot['assessment_version'],
            snapshotChecksum: $snapshot['checksum'],
            channel: $request->channel,
            termId: $request->termId,
            enrollmentId: $request->enrollmentId,
            ledgerEntryId: $request->ledgerEntryId,
            successUrl: $request->successUrl,
            cancelUrl: $request->cancelUrl,
            metadata: [
                ...$request->metadata,
                'assessment_id' => $snapshot['assessment_id'],
                'assessment_version' => $snapshot['assessment_version'],
                'snapshot_checksum' => $snapshot['checksum'],
                'snapshot_created_at' => $snapshot['created_at']->toIso8601String(),
                'snapshot_obligations' => $snapshot['obligations'],
            ],
        );
    }
}
