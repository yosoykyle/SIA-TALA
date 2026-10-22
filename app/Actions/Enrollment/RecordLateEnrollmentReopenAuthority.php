<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordLateEnrollmentReopenAuthority
{
    public const EventType = 'LateEnrollmentReopenAuthorityRecorded';

    public function execute(
        Enrollment $enrollment,
        User $actor,
        string $authorityReference,
        ?string $reason = null,
        ?int $expectedLockVersion = null,
    ): RegistrationCaseEvent {
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may record late-enrollment reopen authority.');
        }

        return DB::transaction(function () use ($enrollment, $actor, $authorityReference, $reason, $expectedLockVersion): RegistrationCaseEvent {
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $authorityReference = trim($authorityReference);
            $reason = filled($reason) ? trim($reason) : null;

            if ($expectedLockVersion !== null && $locked->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'case' => 'The Registration Case changed. Reopen this review before recording approval.',
                ]);
            }

            if (! in_array($locked->canonical_outcome, Enrollment::reopenableOutcomes(), true) || $authorityReference === '') {
                throw ValidationException::withMessages([
                    'late_authority' => 'Late-enrollment authority requires the exact terminal case, Term and approval reference.',
                ]);
            }

            $existing = RegistrationCaseEvent::query()
                ->where('enrollment_id', $locked->id)
                ->where('event_type', self::EventType)
                ->where('authority_reference', $authorityReference)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof RegistrationCaseEvent && (
                $existing->from_outcome !== $locked->canonical_outcome
                || $existing->to_outcome !== $locked->canonical_outcome
                || ($reason !== null && $existing->reason !== $reason)
                || RegistrationCaseEvent::query()
                    ->where('enrollment_id', $locked->id)
                    ->where('id', '>', $existing->id)
                    ->whereNotNull('from_outcome')
                    ->whereNotNull('to_outcome')
                    ->whereColumn('from_outcome', '!=', 'to_outcome')
                    ->exists()
                || RegistrationCaseEvent::query()
                    ->where('enrollment_id', $locked->id)
                    ->where('event_type', 'Reopened')
                    ->where('authority_reference', $authorityReference)
                    ->where('id', '>', $existing->id)
                    ->exists()
            )) {
                throw ValidationException::withMessages([
                    'late_authority' => 'This approval conflicts with an existing same-case approval or reopening. Record the current approval for this case.',
                ]);
            }

            if ($existing instanceof RegistrationCaseEvent) {
                return $existing;
            }

            return RegistrationCaseEvent::query()->create([
                'enrollment_id' => $locked->id,
                'sequence' => ((int) $locked->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => self::EventType,
                'from_outcome' => $locked->canonical_outcome,
                'to_outcome' => $locked->canonical_outcome,
                'reason' => $reason,
                'authority_reference' => $authorityReference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }
}
