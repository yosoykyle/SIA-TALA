<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChangeAdmissionCycle
{
    public function close(
        AdmissionCycle $cycle,
        User $actor,
        string $reason,
        string $authorityReference,
    ): AdmissionCycle {
        return $this->changeDates(
            $cycle,
            $actor,
            CarbonImmutable::now(config('app.timezone')),
            $reason,
            $authorityReference,
            'close',
        );
    }

    public function extend(
        AdmissionCycle $cycle,
        User $actor,
        CarbonInterface $newClosingTime,
        string $reason,
        string $authorityReference,
    ): AdmissionCycle {
        return $this->changeDates(
            $cycle,
            $actor,
            CarbonImmutable::instance($newClosingTime),
            $reason,
            $authorityReference,
            'extend',
        );
    }

    public function reopen(
        AdmissionCycle $cycle,
        User $actor,
        CarbonInterface $newClosingTime,
        string $reason,
        string $authorityReference,
    ): AdmissionCycle {
        return $this->changeDates(
            $cycle,
            $actor,
            CarbonImmutable::instance($newClosingTime),
            $reason,
            $authorityReference,
            'reopen',
        );
    }

    public function cancel(
        AdmissionCycle $cycle,
        User $actor,
        string $reason,
        string $authorityReference,
    ): AdmissionCycle {
        $this->authorize($actor);
        [$reason, $authorityReference] = $this->validateEvidence($reason, $authorityReference);

        return DB::transaction(function () use ($cycle, $actor, $reason, $authorityReference): AdmissionCycle {
            $locked = AdmissionCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $this->assertPublished($locked);
            $previousState = $locked->state;
            $locked->forceFill(['state' => AdmissionCycle::StateCancelled])->save();
            $this->recordEvent(
                $locked,
                $actor,
                AdmissionCycleEvent::TypeCancelled,
                ['state' => $previousState],
                ['state' => AdmissionCycle::StateCancelled],
                $reason,
                $authorityReference,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    private function changeDates(
        AdmissionCycle $cycle,
        User $actor,
        CarbonImmutable $newClosingTime,
        string $reason,
        string $authorityReference,
        string $operation,
    ): AdmissionCycle {
        $this->authorize($actor);
        [$reason, $authorityReference] = $this->validateEvidence($reason, $authorityReference);

        return DB::transaction(function () use (
            $cycle,
            $actor,
            $newClosingTime,
            $reason,
            $authorityReference,
            $operation,
        ): AdmissionCycle {
            $locked = AdmissionCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $this->assertPublished($locked);
            $now = CarbonImmutable::now(config('app.timezone'));

            if ($operation === 'close' && $locked->closes_at?->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['closes_at' => 'The Admission Cycle is already closed.']);
            }

            if ($operation === 'extend' && $locked->closes_at !== null
                && $newClosingTime->lessThanOrEqualTo($locked->closes_at)) {
                throw ValidationException::withMessages([
                    'closes_at' => 'An extension must move the closing time later.',
                ]);
            }

            if ($operation === 'reopen'
                && ($locked->closes_at?->isFuture() || ! $newClosingTime->isFuture())) {
                throw ValidationException::withMessages([
                    'closes_at' => 'Reopening requires a closed cycle and a future closing time.',
                ]);
            }

            if ($operation !== 'close' && $newClosingTime->lessThanOrEqualTo($locked->opens_at)) {
                throw ValidationException::withMessages([
                    'closes_at' => 'The new closing time must be after the opening time.',
                ]);
            }

            $previous = $locked->closes_at?->toIso8601String();
            $locked->forceFill(['closes_at' => $newClosingTime])->save();
            $this->recordEvent(
                $locked,
                $actor,
                AdmissionCycleEvent::TypeDatesChanged,
                ['closes_at' => $previous],
                ['closes_at' => $newClosingTime->toIso8601String(), 'operation' => $operation],
                $reason,
                $authorityReference,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('manage-admission-setup')) {
            throw new AuthorizationException('Only an authorized Registrar may change an Admission Cycle.');
        }
    }

    private function assertPublished(AdmissionCycle $cycle): void
    {
        if ($cycle->state !== AdmissionCycle::StatePublished) {
            throw ValidationException::withMessages([
                'state' => 'Only a Published Admission Cycle can be changed or cancelled.',
            ]);
        }
    }

    /** @return array{string, string} */
    private function validateEvidence(string $reason, string $authorityReference): array
    {
        $validated = Validator::make(
            [
                'reason' => trim($reason),
                'authority_reference' => trim($authorityReference),
            ],
            [
                'reason' => ['required', 'string', 'max:1000'],
                'authority_reference' => ['required', 'string', 'max:255'],
            ],
        )->validate();

        return [$validated['reason'], $validated['authority_reference']];
    }

    /** @param array<string, mixed> $previousValues @param array<string, mixed> $newValues */
    private function recordEvent(
        AdmissionCycle $cycle,
        User $actor,
        string $type,
        array $previousValues,
        array $newValues,
        string $reason,
        string $authorityReference,
    ): void {
        $cycle->events()->create([
            'event_type' => $type,
            'event_key' => 'admission-cycle-change:'.Str::uuid(),
            'previous_values' => $previousValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'authority_reference' => $authorityReference,
            'actor_id' => $actor->id,
            'occurred_at' => now(config('app.timezone')),
        ]);
    }
}
