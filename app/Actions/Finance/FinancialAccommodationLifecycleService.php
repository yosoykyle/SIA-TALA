<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccommodation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

class FinancialAccommodationLifecycleService
{
    public const ReasonMaxLength = 1000;

    public function transition(
        FinancialAccommodation $financialAccommodation,
        string $targetStatus,
        string $reason,
        User $actor,
        ?CarbonImmutable $transitionedAt = null,
    ): FinancialAccommodation {
        Gate::forUser($actor)->authorize('transition', $financialAccommodation);

        $normalizedReason = trim($reason);

        if ($normalizedReason === '') {
            throw new RuntimeException('A transition reason is required.');
        }

        if (Str::length($normalizedReason) > self::ReasonMaxLength) {
            throw new RuntimeException('The transition reason must not exceed '.self::ReasonMaxLength.' characters.');
        }

        $timestamp = $transitionedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($financialAccommodation, $targetStatus, $normalizedReason, $actor, $timestamp): FinancialAccommodation {
            $locked = FinancialAccommodation::query()
                ->lockForUpdate()
                ->findOrFail($financialAccommodation->getKey());
            $statusBefore = $locked->status;

            if (! array_key_exists($targetStatus, $locked->transitionStatusOptions())) {
                throw new RuntimeException("Financial accommodation cannot transition from {$statusBefore} to {$targetStatus}.");
            }

            if ($targetStatus === FinancialAccommodation::StatusExpired) {
                if ($locked->expires_on === null) {
                    throw new RuntimeException('An expiry date is required before marking an accommodation expired.');
                }

                if ($timestamp->startOfDay()->isBefore(CarbonImmutable::parse($locked->expires_on)->startOfDay())) {
                    throw new RuntimeException('An accommodation cannot expire before its recorded expiry date.');
                }
            }

            $locked->update(['status' => $targetStatus]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('financial_accommodation_transitioned')
                ->withProperties([
                    'student_profile_id' => $locked->student_profile_id,
                    'term_id' => $locked->term_id,
                    'recorded_by' => $locked->recorded_by,
                    'status_before' => $statusBefore,
                    'status_after' => $targetStatus,
                    'reason' => $normalizedReason,
                    'transitioned_at' => $timestamp->toIso8601String(),
                ])
                ->log('Financial accommodation status updated');

            return $locked->refresh();
        });
    }
}
