<?php

namespace App\Actions\Integrations\Payments;

use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreatePaymentCheckoutSession
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DecimalMoney $money,
    ) {}

    /**
     * @return array{payment_attempt_id:int, provider:string, provider_checkout_session_id:string, checkout_url:string, status:string, amount:string}
     */
    public function create(PaymentCheckoutRequest $request): array
    {
        $amount = $this->money->normalize($request->amount);

        if (! $this->money->greaterThanZero($amount)) {
            throw new RuntimeException('Checkout amount must be greater than zero.');
        }

        $assessment = $this->assessmentFor($request);
        $reusableAttempt = $this->reusablePendingAttempt((int) $assessment->id, $request->studentProfileId, $amount);

        if (is_object($reusableAttempt)) {
            $metadata = json_decode((string) ($reusableAttempt->metadata ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

            return [
                'payment_attempt_id' => (int) $reusableAttempt->id,
                'provider' => (string) $reusableAttempt->provider,
                'provider_checkout_session_id' => (string) $reusableAttempt->provider_checkout_id,
                'internal_reference' => (string) $reusableAttempt->internal_reference,
                'checkout_url' => (string) ($metadata['checkout_url'] ?? ''),
                'status' => (string) $reusableAttempt->status,
                'amount' => $this->money->normalize((string) $reusableAttempt->amount),
            ];
        }

        $internalReference = 'TALA-PAY-'.Str::upper((string) Str::uuid());
        $metadata = [
            ...$request->metadata,
            'tala_reference' => $internalReference,
            'assessment_id' => $assessment->id,
            'enrollment_id' => $assessment->enrollment_id,
        ];

        $normalizedRequest = new PaymentCheckoutRequest(
            studentProfileId: $request->studentProfileId,
            amount: $amount,
            description: $request->description,
            assessmentId: (int) $assessment->id,
            channel: $request->channel,
            termId: $request->termId,
            enrollmentId: $assessment->enrollment_id !== null ? (int) $assessment->enrollment_id : $request->enrollmentId,
            ledgerEntryId: $request->ledgerEntryId,
            successUrl: $request->successUrl,
            cancelUrl: $request->cancelUrl,
            metadata: $metadata,
        );

        $session = $this->gateway->createCheckoutSession($normalizedRequest);
        $createdAt = CarbonImmutable::now(config('app.timezone'));

        $paymentAttemptId = DB::table('payment_attempts')->insertGetId([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $normalizedRequest->studentProfileId,
            'channel' => $normalizedRequest->channel,
            'provider' => $session->provider,
            'internal_reference' => $internalReference,
            'provider_checkout_id' => $session->checkoutSessionId,
            'provider_intent_id' => $session->metadata['payment_intent_id'] ?? null,
            'amount' => $amount,
            'currency' => 'PHP',
            'status' => $session->status,
            'expires_at' => $createdAt->addMinutes(30)->toDateTimeString(),
            'metadata' => json_encode([
                'checkout_url' => $session->checkoutUrl,
                'success_url' => $normalizedRequest->successUrl,
                'cancel_url' => $normalizedRequest->cancelUrl,
                'request' => $normalizedRequest->metadata,
                'gateway' => $session->metadata,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->toDateTimeString(),
        ]);

        return [
            'payment_attempt_id' => $paymentAttemptId,
            'provider' => $session->provider,
            'provider_checkout_session_id' => $session->checkoutSessionId,
            'internal_reference' => $internalReference,
            'checkout_url' => $session->checkoutUrl,
            'status' => $session->status,
            'amount' => $amount,
        ];
    }

    private function assessmentFor(PaymentCheckoutRequest $request): object
    {
        if ($request->assessmentId !== null) {
            $assessment = DB::table('assessments')->where('id', $request->assessmentId)->first();
        } elseif ($request->enrollmentId !== null) {
            $assessment = DB::table('assessments')
                ->where('enrollment_id', $request->enrollmentId)
                ->where('state', 'ACTIVE')
                ->latest('version')
                ->latest('id')
                ->first();
        } else {
            $assessment = null;
        }

        if (! is_object($assessment)) {
            throw new RuntimeException('An active assessment is required before creating a payment checkout attempt.');
        }

        if (($assessment->state ?? null) !== 'ACTIVE') {
            throw new RuntimeException('Payment checkout requires an active assessment.');
        }

        $assessmentStudentProfileId = $assessment->enrollment_id !== null
            ? DB::table('enrollments')->where('id', $assessment->enrollment_id)->value('student_profile_id')
            : null;

        if ($assessmentStudentProfileId !== null && (int) $assessmentStudentProfileId !== $request->studentProfileId) {
            throw new RuntimeException('Payment checkout assessment does not belong to the selected student.');
        }

        return $assessment;
    }

    private function reusablePendingAttempt(int $assessmentId, int $studentProfileId, string $amount): ?object
    {
        return DB::table('payment_attempts')
            ->where('assessment_id', $assessmentId)
            ->where('student_profile_id', $studentProfileId)
            ->where('amount', $amount)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now(config('app.timezone'))->toDateTimeString());
            })
            ->latest('created_at')
            ->latest('id')
            ->first();
    }
}
