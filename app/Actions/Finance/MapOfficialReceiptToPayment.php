<?php

namespace App\Actions\Finance;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MapOfficialReceiptToPayment
{
    /**
     * @throws AuthorizationException
     */
    public function execute(
        Payment $payment,
        string $orNumber,
        User $actor,
        ?CarbonImmutable $mappedAt = null,
    ): Payment {
        if (! $actor->can('mapOfficialReceipt', $payment)) {
            throw new AuthorizationException('Only Accounting can map official receipts to posted payment evidence.');
        }

        $trimmedOrNumber = trim($orNumber);

        if ($trimmedOrNumber === '') {
            throw new RuntimeException('Official Receipt number is required.');
        }

        if (Str::length($trimmedOrNumber) > 255) {
            throw new RuntimeException('Official Receipt number must not exceed 255 characters.');
        }

        $timestamp = $mappedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($payment, $actor, $trimmedOrNumber, $timestamp): Payment {
            $locked = Payment::query()
                ->with('ledgerEntry')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $ledgerEntry = $locked->ledgerEntry;

            if ($locked->evidence_status !== 'verified'
                || ! $ledgerEntry instanceof LedgerEntry
                || $ledgerEntry->state !== 'posted') {
                throw new RuntimeException('Official Receipts can only be mapped to verified posted payment evidence.');
            }

            if (filled($locked->or_number)) {
                throw new RuntimeException('This payment already has an Official Receipt number.');
            }

            if (Payment::query()
                ->where('or_number', $trimmedOrNumber)
                ->whereKeyNot($locked->id)
                ->exists()) {
                throw new RuntimeException('Official Receipt number already exists.');
            }

            $locked->forceFill([
                'or_number' => $trimmedOrNumber,
                'or_mapped_by' => $actor->id,
                'or_mapped_at' => $timestamp,
            ])->save();

            return $locked->refresh();
        });
    }
}
