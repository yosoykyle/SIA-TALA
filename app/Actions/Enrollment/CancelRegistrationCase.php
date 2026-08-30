<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\RegistrationCaseEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelRegistrationCase
{
    public function execute(Enrollment $enrollment, User $actor, string $reason, ?int $expectedLockVersion = null): Enrollment
    {
        if (! $actor->canAuthenticate()) {
            throw new AuthorizationException('Only an active authorized user may cancel this Registration Case.');
        }

        if ((int) $enrollment->credential_user_id !== (int) $actor->id
            && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only the learner or authorized Registrar may cancel this Registration Case.');
        }

        return DB::transaction(function () use ($enrollment, $actor, $reason, $expectedLockVersion): Enrollment {
            $locked = Enrollment::query()
                ->with('currentProposalVersion.confirmation')
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectedLockVersion !== null && $locked->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages(['case' => 'The Registration Case changed. Refresh before cancelling.']);
            }

            if ($locked->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
                throw ValidationException::withMessages(['case' => 'Official enrollment requires the controlled withdrawal or drop journey.']);
            }

            if (in_array($locked->canonical_outcome, Enrollment::cancelledOutcomes(), true)) {
                return $locked;
            }

            $learnerIsActor = (int) $locked->credential_user_id === (int) $actor->id;
            if ($learnerIsActor && $locked->currentProposalVersion?->confirmation !== null) {
                throw ValidationException::withMessages([
                    'case' => 'After proposal confirmation, the Registrar must record cancellation and release protected capacity.',
                ]);
            }

            $outcome = $learnerIsActor
                ? Enrollment::OutcomeCancelledByLearner
                : Enrollment::OutcomeCancelledByRegistrar;
            $from = $locked->canonical_outcome;
            EnrollmentSeatReservation::query()
                ->where('enrollment_id', $locked->id)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->lockForUpdate()
                ->update(['status' => EnrollmentSeatReservation::StatusReleased, 'released_at' => now()]);
            $locked->update([
                'canonical_outcome' => $outcome,
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'status_reason' => $reason,
                'lock_version' => $locked->lock_version + 1,
            ]);
            $this->record($locked, $actor, $outcome, $from, $outcome, $reason);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function record(Enrollment $enrollment, User $actor, string $type, ?string $from, string $to, string $reason): void
    {
        RegistrationCaseEvent::query()->create([
            'enrollment_id' => $enrollment->id,
            'sequence' => ((int) $enrollment->registrationEvents()->max('sequence')) + 1,
            'event_type' => $type,
            'from_outcome' => $from,
            'to_outcome' => $to,
            'reason' => $reason,
            'actor_id' => $actor->id,
            'recorded_at' => now(),
        ]);
    }
}
