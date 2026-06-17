<?php

namespace App\Actions\ServiceRequests;

use App\Models\DocumentRequest;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\GeneralSystemNotification;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DocumentRequestLifecycleService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @param  array{student_profile_id:int,term_id?:int|null,document_type:string,is_free_request:bool,delivery_mode:string,delivery_consent?:bool}  $data
     */
    public function create(array $data, User $actor): DocumentRequest
    {
        $studentProfile = StudentProfile::query()->findOrFail((int) $data['student_profile_id']);
        $this->authorizeRequester($studentProfile, $actor);
        $deliveryMode = $this->normalizeDeliveryMode((string) $data['delivery_mode']);
        $deliveryConsent = (bool) ($data['delivery_consent'] ?? false);

        if ($deliveryMode === DocumentRequest::DeliveryModeCourier && ! $deliveryConsent) {
            throw ValidationException::withMessages([
                'delivery_consent' => 'Courier delivery requires explicit data privacy consent.',
            ]);
        }

        return DB::transaction(function () use ($data, $studentProfile, $actor, $deliveryMode, $deliveryConsent): DocumentRequest {
            $this->assertNoPendingShippingPayment($studentProfile->id);

            $isFreeRequest = (bool) $data['is_free_request'];

            return DocumentRequest::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $data['term_id'] ?? null,
                'document_type' => trim((string) $data['document_type']),
                'status' => $isFreeRequest
                    ? DocumentRequest::StatusProcessing
                    : DocumentRequest::StatusPendingDocumentFee,
                'is_free_request' => $isFreeRequest,
                'delivery_mode' => $deliveryMode,
                'delivery_consent' => $deliveryConsent,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    public function confirmDocumentFee(DocumentRequest $request, User $accounting): DocumentRequest
    {
        $this->authorizeAccounting($accounting);

        return DB::transaction(function () use ($request, $accounting): DocumentRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [DocumentRequest::StatusPendingDocumentFee]);

            $locked->forceFill([
                'status' => DocumentRequest::StatusProcessing,
                'updated_by' => $accounting->id,
            ])->save();

            $this->recordActivity($locked, 'document_fee_confirmed', $accounting, [
                'status_after' => DocumentRequest::StatusProcessing,
            ]);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'document_request_processing',
                subject: $this->documentLabel($locked).' request is now processing',
                body: 'Your document fee was confirmed. Registrar processing can now begin.',
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function markReadyForPickup(DocumentRequest $request, User $registrar): DocumentRequest
    {
        $this->authorizeRegistrar($registrar);

        return DB::transaction(function () use ($request, $registrar): DocumentRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [DocumentRequest::StatusProcessing]);

            if ($locked->delivery_mode !== DocumentRequest::DeliveryModePickup) {
                throw new RuntimeException('Only pickup requests can be marked ready for pickup.');
            }

            $locked->forceFill([
                'status' => DocumentRequest::StatusReadyForPickup,
                'updated_by' => $registrar->id,
            ])->save();

            $this->recordActivity($locked, 'ready_for_pickup', $registrar, [
                'status_after' => DocumentRequest::StatusReadyForPickup,
            ]);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'document_ready_for_pickup',
                subject: $this->documentLabel($locked).' is ready for pickup',
                body: 'Your requested document is ready for campus pickup.',
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function completePickup(DocumentRequest $request, User $registrar): DocumentRequest
    {
        $this->authorizeRegistrar($registrar);

        return DB::transaction(function () use ($request, $registrar): DocumentRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [DocumentRequest::StatusReadyForPickup]);

            $locked->forceFill([
                'status' => DocumentRequest::StatusCompleted,
                'updated_by' => $registrar->id,
            ])->save();

            $this->recordActivity($locked, 'pickup_completed', $registrar, [
                'status_after' => DocumentRequest::StatusCompleted,
            ]);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'document_request_completed',
                subject: $this->documentLabel($locked).' request completed',
                body: 'Your document request has been marked completed.',
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    /**
     * @param  array{courier_name:string,shipping_fee:string,tracking_number:string,courier_receipt_path:string}  $data
     */
    public function markShipped(
        DocumentRequest $request,
        array $data,
        User $registrar,
        ?CarbonImmutable $shippedAt = null,
    ): DocumentRequest {
        $this->authorizeRegistrar($registrar);
        $validated = $this->validateShipmentData($data);
        $timestamp = $shippedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($request, $validated, $registrar, $timestamp): DocumentRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [DocumentRequest::StatusProcessing]);

            if ($locked->delivery_mode !== DocumentRequest::DeliveryModeCourier) {
                throw new RuntimeException('Only courier requests can be marked shipped.');
            }

            if (! $locked->delivery_consent) {
                throw new RuntimeException('Courier request cannot ship without data privacy consent.');
            }

            $locked->forceFill([
                'status' => DocumentRequest::StatusPendingShippingPayment,
                'courier_name' => $validated['courier_name'],
                'tracking_number' => $validated['tracking_number'],
                'tracking_number_normalized' => $validated['tracking_number_normalized'],
                'shipping_fee' => $validated['shipping_fee'],
                'courier_receipt_path' => $validated['courier_receipt_path'],
                'shipped_at' => $timestamp,
                'shipping_grace_ends_at' => $timestamp->addDays(3),
                'updated_by' => $registrar->id,
            ])->save();

            $this->recordActivity($locked, 'document_shipped', $registrar, [
                'status_after' => DocumentRequest::StatusPendingShippingPayment,
                'shipping_fee' => $validated['shipping_fee'],
                'tracking_number_normalized' => $validated['tracking_number_normalized'],
            ]);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'document_shipped',
                subject: $this->documentLabel($locked).' shipped via '.$validated['courier_name'],
                body: sprintf(
                    'Tracking: %s. Shipping fee: %s. Please pay the shipping fee within 3 calendar days to avoid debt posting.',
                    $validated['tracking_number_normalized'],
                    $validated['shipping_fee'],
                ),
                metadata: $this->notificationMetadata($locked, [
                    'courier_name' => $validated['courier_name'],
                    'tracking_number' => $validated['tracking_number_normalized'],
                    'shipping_fee' => $validated['shipping_fee'],
                ]),
            ));

            return $locked->fresh();
        });
    }

    public function confirmShippingPayment(DocumentRequest $request, LedgerEntry $paymentLedgerEntry, User $cashier): DocumentRequest
    {
        $this->authorizeAccounting($cashier);

        return DB::transaction(function () use ($request, $paymentLedgerEntry, $cashier): DocumentRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [DocumentRequest::StatusPendingShippingPayment]);

            if ($locked->shipping_fee_payment_transaction_id !== null) {
                throw new RuntimeException('Shipping payment is already linked.');
            }

            $locked->forceFill([
                'status' => DocumentRequest::StatusCompleted,
                'shipping_fee_payment_transaction_id' => $paymentLedgerEntry->id,
                'updated_by' => $cashier->id,
            ])->save();

            $this->recordActivity($locked, 'shipping_payment_confirmed', $cashier, [
                'status_after' => DocumentRequest::StatusCompleted,
                'ledger_entry_id' => $paymentLedgerEntry->id,
            ]);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'document_shipping_payment_confirmed',
                subject: 'Shipping fee payment confirmed',
                body: 'Accounting confirmed the shipping fee payment for your document request.',
                metadata: $this->notificationMetadata($locked, [
                    'ledger_entry_id' => $paymentLedgerEntry->id,
                ]),
            ));

            return $locked->fresh();
        });
    }

    public function confirmShippingPaymentManually(
        DocumentRequest $request,
        User $cashier,
        string $amount,
        string $channel,
        ?string $paymentReference,
        ?CarbonImmutable $confirmedAt = null,
    ): DocumentRequest {
        $this->authorizeAccounting($cashier);

        $normalizedAmount = $this->money->normalize($amount);

        if (! $this->money->greaterThanZero($normalizedAmount)) {
            throw new RuntimeException('Shipping payment amount must be greater than zero.');
        }

        $normalizedChannel = strtolower(trim($channel));

        if (! array_key_exists($normalizedChannel, Payment::manualConfirmationChannelOptions())) {
            throw new RuntimeException('Unsupported manual payment channel.');
        }

        $normalizedReference = trim((string) $paymentReference);

        if ($normalizedReference === '') {
            throw new RuntimeException('Payment reference is required.');
        }

        if (Str::length($normalizedReference) > 255) {
            throw new RuntimeException('Payment reference must not exceed 255 characters.');
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $timestamp = $confirmedAt ?? $now;

        if ($timestamp->greaterThan($now)) {
            throw new RuntimeException('Payment confirmation date cannot be in the future.');
        }

        return DB::transaction(function () use ($request, $cashier, $normalizedAmount, $normalizedChannel, $normalizedReference, $timestamp): DocumentRequest {
            if (Payment::query()->where('payment_reference', $normalizedReference)->exists()) {
                throw new RuntimeException('Payment reference already exists.');
            }

            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [DocumentRequest::StatusPendingShippingPayment]);

            if ($locked->shipping_fee_payment_transaction_id !== null) {
                throw new RuntimeException('Shipping payment is already linked.');
            }

            if ($locked->shipping_fee === null || ! $this->money->greaterThanZero((string) $locked->shipping_fee)) {
                throw new RuntimeException('Document request has no payable shipping fee.');
            }

            $shippingFee = $this->money->normalize((string) $locked->shipping_fee);

            if ($this->money->toCents($normalizedAmount) !== $this->money->toCents($shippingFee)) {
                throw new RuntimeException('Shipping payment must match the assessed shipping fee.');
            }

            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($locked->student_profile_id);
            $currentBalance = $this->money->normalize((string) $studentProfile->current_balance);
            $paymentLedgerAmount = $this->money->subtract('0.00', $normalizedAmount);
            $newBalance = $this->money->add($currentBalance, $paymentLedgerAmount);

            $payment = Payment::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $locked->term_id,
                'enrollment_id' => null,
                'payment_reference' => $normalizedReference,
                'channel' => $normalizedChannel,
                'amount' => $normalizedAmount,
                'status' => 'confirmed',
                'confirmed_at' => $timestamp,
                'confirmed_by' => $cashier->id,
                'meta' => [
                    'source' => 'filament_document_shipping_confirmation',
                    'document_request_id' => $locked->id,
                ],
            ]);

            $ledgerEntry = LedgerEntry::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $locked->term_id,
                'enrollment_id' => null,
                'entry_type' => 'payment',
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'description' => 'Document shipping fee payment',
                'amount' => $paymentLedgerAmount,
                'running_balance' => $newBalance,
                'posted_at' => $timestamp,
                'posted_by' => $cashier->id,
            ]);

            $payment->forceFill([
                'ledger_entry_id' => $ledgerEntry->id,
            ])->save();

            $studentProfile->forceFill([
                'current_balance' => $newBalance,
            ])->save();

            $locked->forceFill([
                'status' => DocumentRequest::StatusCompleted,
                'shipping_fee_payment_transaction_id' => $ledgerEntry->id,
                'updated_by' => $cashier->id,
            ])->save();

            $this->recordActivity($locked, 'shipping_payment_confirmed', $cashier, [
                'status_after' => DocumentRequest::StatusCompleted,
                'payment_id' => $payment->id,
                'ledger_entry_id' => $ledgerEntry->id,
                'amount' => $normalizedAmount,
            ], $timestamp);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'document_shipping_payment_confirmed',
                subject: 'Shipping fee payment confirmed',
                body: 'Accounting confirmed the shipping fee payment for your document request.',
                metadata: $this->notificationMetadata($locked, [
                    'payment_id' => $payment->id,
                    'ledger_entry_id' => $ledgerEntry->id,
                    'amount' => $normalizedAmount,
                ]),
            ));

            return $locked->fresh();
        });
    }

    public function cancel(DocumentRequest $request, User $actor, string $reason): DocumentRequest
    {
        if (! $actor->can('manage-document-requests') && ! $this->actorOwnsRequest($request, $actor)) {
            throw new AuthorizationException('Only the requesting student or Registrar can cancel this request.');
        }

        return DB::transaction(function () use ($request, $actor, $reason): DocumentRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [
                DocumentRequest::StatusPendingDocumentFee,
                DocumentRequest::StatusProcessing,
            ]);

            $locked->forceFill([
                'status' => DocumentRequest::StatusCancelled,
                'updated_by' => $actor->id,
            ])->save();

            $this->recordActivity($locked, 'document_request_cancelled', $actor, [
                'reason' => trim($reason),
                'status_after' => DocumentRequest::StatusCancelled,
            ]);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'document_request_cancelled',
                subject: $this->documentLabel($locked).' request cancelled',
                body: trim($reason) !== '' ? trim($reason) : 'Your document request has been cancelled.',
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    /**
     * @return array{processed:int, skipped:int}
     */
    public function postExpiredShippingFees(?CarbonImmutable $asOf = null, int $limit = 100): array
    {
        $evaluatedAt = $asOf ?? CarbonImmutable::now(config('app.timezone'));
        $processed = 0;
        $skipped = 0;

        $requestIds = DocumentRequest::query()
            ->where('status', DocumentRequest::StatusPendingShippingPayment)
            ->whereNotNull('shipping_fee')
            ->whereNotNull('shipped_at')
            ->where(function ($query) use ($evaluatedAt): void {
                $query->where('shipping_grace_ends_at', '<=', $evaluatedAt)
                    ->orWhere(function ($fallback) use ($evaluatedAt): void {
                        $fallback->whereNull('shipping_grace_ends_at')
                            ->where('shipped_at', '<=', $evaluatedAt->subDays(3));
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($requestIds as $requestId) {
            $posted = DB::transaction(function () use ($requestId, $evaluatedAt): bool {
                $request = DocumentRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($requestId);

                if ($request->status !== DocumentRequest::StatusPendingShippingPayment
                    || $request->shipping_fee_assessment_transaction_id !== null
                    || ! $this->money->greaterThanZero((string) $request->shipping_fee)) {
                    return false;
                }

                $studentProfile = StudentProfile::query()
                    ->lockForUpdate()
                    ->findOrFail($request->student_profile_id);

                $currentBalance = $this->money->normalize((string) $studentProfile->current_balance);
                $shippingFee = $this->money->normalize((string) $request->shipping_fee);
                $newBalance = $this->money->add($currentBalance, $shippingFee);

                $ledgerEntry = LedgerEntry::query()->create([
                    'student_profile_id' => $studentProfile->id,
                    'term_id' => $request->term_id,
                    'enrollment_id' => null,
                    'entry_type' => 'shipping_fee',
                    'reference_type' => 'document_request',
                    'reference_id' => $request->id,
                    'description' => 'Unpaid document shipping fee posted after 3-day grace.',
                    'amount' => $shippingFee,
                    'running_balance' => $newBalance,
                    'posted_at' => $evaluatedAt,
                    'posted_by' => null,
                ]);

                $studentProfile->forceFill([
                    'current_balance' => $newBalance,
                ])->save();

                $request->forceFill([
                    'status' => DocumentRequest::StatusCompletedWithDebt,
                    'shipping_fee_assessment_transaction_id' => $ledgerEntry->id,
                ])->save();

                $this->recordActivity($request, 'shipping_fee_debt_posted', null, [
                    'ledger_entry_id' => $ledgerEntry->id,
                    'amount' => $shippingFee,
                    'status_after' => DocumentRequest::StatusCompletedWithDebt,
                ], $evaluatedAt);

                $this->notifyStudent($request, new GeneralSystemNotification(
                    type: 'document_shipping_fee_debt_posted',
                    subject: 'Shipping fee posted to your balance',
                    body: 'The unpaid document shipping fee was posted to your student ledger after the 3-day grace period.',
                    metadata: $this->notificationMetadata($request, [
                        'ledger_entry_id' => $ledgerEntry->id,
                        'amount' => $shippingFee,
                    ]),
                ));

                return true;
            });

            if ($posted) {
                $processed++;
            } else {
                $skipped++;
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
        ];
    }

    private function authorizeRequester(StudentProfile $studentProfile, User $actor): void
    {
        if ($actor->can('manage-document-requests')) {
            return;
        }

        if ($actor->can('request-documents') && (int) $studentProfile->user_id === (int) $actor->id) {
            return;
        }

        throw new AuthorizationException('Only the student owner or Registrar can create document requests.');
    }

    private function authorizeRegistrar(User $registrar): void
    {
        if (! $registrar->can('manage-document-requests')) {
            throw new AuthorizationException('Only Registrar can manage document fulfillment.');
        }
    }

    private function authorizeAccounting(User $accounting): void
    {
        if (! $accounting->can('process-payments')) {
            throw new AuthorizationException('Only Accounting/Cashier can confirm document request payments.');
        }
    }

    private function assertNoPendingShippingPayment(int $studentProfileId): void
    {
        $hasPendingShipping = DocumentRequest::query()
            ->where('student_profile_id', $studentProfileId)
            ->where('status', DocumentRequest::StatusPendingShippingPayment)
            ->exists();

        if ($hasPendingShipping) {
            throw new RuntimeException('Student has a pending shipping payment and cannot create another document request.');
        }
    }

    private function lockRequest(DocumentRequest $request): DocumentRequest
    {
        return DocumentRequest::query()
            ->lockForUpdate()
            ->findOrFail($request->id);
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function assertStatus(DocumentRequest $request, array $allowedStatuses): void
    {
        if (! in_array($request->status, $allowedStatuses, true)) {
            throw new RuntimeException(sprintf(
                'Invalid document request transition from [%s].',
                $request->status,
            ));
        }
    }

    private function normalizeDeliveryMode(string $deliveryMode): string
    {
        return match (strtolower(trim($deliveryMode))) {
            'pickup' => DocumentRequest::DeliveryModePickup,
            'courier', 'delivery' => DocumentRequest::DeliveryModeCourier,
            default => throw ValidationException::withMessages([
                'delivery_mode' => 'Delivery mode must be pickup or courier.',
            ]),
        };
    }

    /**
     * @param  array{courier_name:string,shipping_fee:string,tracking_number:string,courier_receipt_path:string}  $data
     * @return array{courier_name:string,shipping_fee:string,tracking_number:string,tracking_number_normalized:string,courier_receipt_path:string}
     */
    private function validateShipmentData(array $data): array
    {
        $courierName = trim((string) ($data['courier_name'] ?? ''));
        $trackingNumber = trim((string) ($data['tracking_number'] ?? ''));
        $receiptPath = trim((string) ($data['courier_receipt_path'] ?? ''));
        $shippingFee = $data['shipping_fee'] ?? null;

        $errors = [];

        if ($courierName === '' || mb_strlen($courierName) > 100) {
            $errors['courier_name'] = 'Courier name is required and must not exceed 100 characters.';
        }

        if ($trackingNumber === '' || mb_strlen($trackingNumber) > 100) {
            $errors['tracking_number'] = 'Tracking number is required and must not exceed 100 characters.';
        }

        if ($receiptPath === ''
            || mb_strlen($receiptPath) > 500
            || ! str_starts_with($receiptPath, DocumentRequest::CourierReceiptDirectory.'/')) {
            $errors['courier_receipt_path'] = 'Courier receipt file is required and must be uploaded through the private document request receipt field.';
        }

        if (! is_string($shippingFee) || ! preg_match('/^\d+(\.\d{1,2})?$/', $shippingFee)) {
            $errors['shipping_fee'] = 'Shipping fee must be a decimal string with at most two decimal places.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $normalizedFee = $this->money->normalize($shippingFee);

        if (! $this->money->greaterThanZero($normalizedFee)) {
            throw ValidationException::withMessages([
                'shipping_fee' => 'Shipping fee must be greater than zero.',
            ]);
        }

        return [
            'courier_name' => $courierName,
            'shipping_fee' => $normalizedFee,
            'tracking_number' => strtoupper($trackingNumber) === 'N/A' ? 'N/A' : $trackingNumber,
            'tracking_number_normalized' => strtoupper($trackingNumber) === 'N/A' ? 'N/A' : strtoupper($trackingNumber),
            'courier_receipt_path' => $receiptPath,
        ];
    }

    private function actorOwnsRequest(DocumentRequest $request, User $actor): bool
    {
        $studentProfileUserId = StudentProfile::query()
            ->whereKey($request->student_profile_id)
            ->value('user_id');

        return (int) $studentProfileUserId === (int) $actor->id;
    }

    private function notifyStudent(DocumentRequest $request, GeneralSystemNotification $notification): void
    {
        $student = StudentProfile::query()
            ->with('user')
            ->find($request->student_profile_id)
            ?->user;

        if ($student instanceof User) {
            $student->notify($notification);
        }
    }

    private function documentLabel(DocumentRequest $request): string
    {
        return str((string) $request->document_type)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function notificationMetadata(DocumentRequest $request, array $extra = []): array
    {
        return array_merge([
            'document_request_id' => $request->id,
            'document_type' => $request->document_type,
            'status' => $request->status,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        DocumentRequest $request,
        string $event,
        ?User $actor,
        array $properties = [],
        ?CarbonImmutable $recordedAt = null,
    ): void {
        $timestamp = $recordedAt ?? CarbonImmutable::now(config('app.timezone'));

        DB::table('activity_log')->insert([
            'log_name' => 'document_request',
            'description' => 'Document request lifecycle transition.',
            'subject_type' => DocumentRequest::class,
            'subject_id' => $request->id,
            'event' => $event,
            'causer_type' => $actor instanceof User ? User::class : null,
            'causer_id' => $actor?->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
