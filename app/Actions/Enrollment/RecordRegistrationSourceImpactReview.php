<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordRegistrationSourceImpactReview
{
    public const SourceTimetableRevision = 'TimetableRevision';

    public const SourceAcademicResult = 'AcademicResult';

    public function open(
        Enrollment $enrollment,
        User $actor,
        string $sourceType,
        string $sourceReference,
        string $reason,
    ): RegistrationCaseEvent {
        $this->authorizeSourceActor($actor);
        $this->validateSource($sourceType, $sourceReference, $reason);

        return DB::transaction(function () use ($enrollment, $actor, $sourceType, $sourceReference, $reason): RegistrationCaseEvent {
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $eventType = $sourceType.'ImpactReviewOpened';
            $existing = RegistrationCaseEvent::query()
                ->where('enrollment_id', $locked->id)
                ->where('event_type', $eventType)
                ->where('authority_reference', $sourceReference)
                ->first();

            if ($existing instanceof RegistrationCaseEvent) {
                return $existing;
            }

            return RegistrationCaseEvent::query()->create([
                'enrollment_id' => $locked->id,
                'sequence' => ((int) $locked->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => $eventType,
                'from_outcome' => $locked->canonical_outcome,
                'to_outcome' => $locked->canonical_outcome,
                'reason' => $reason,
                'authority_reference' => $sourceReference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }

    public function resolve(
        Enrollment $enrollment,
        RegistrationCaseEvent $openedReview,
        User $actor,
        string $outcome,
    ): RegistrationCaseEvent {
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may resolve a registration impact review.');
        }

        return DB::transaction(function () use ($enrollment, $openedReview, $actor, $outcome): RegistrationCaseEvent {
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $opened = RegistrationCaseEvent::query()->whereKey($openedReview->id)->lockForUpdate()->firstOrFail();

            if ($opened->event_type === 'TimetableRevisionImpactReviewOpened') {
                throw ValidationException::withMessages([
                    'impact_review' => 'Timetable revision impacts require a validated retain, registration-change, or case-cancellation outcome.',
                ]);
            }

            if ((int) $opened->enrollment_id !== (int) $locked->id
                || ! str_ends_with((string) $opened->event_type, 'ImpactReviewOpened')
                || blank($outcome)) {
                throw ValidationException::withMessages(['impact_review' => 'A matching open review and a recorded outcome are required.']);
            }

            $eventType = str_replace('Opened', 'Resolved', (string) $opened->event_type);
            $existing = RegistrationCaseEvent::query()
                ->where('enrollment_id', $locked->id)
                ->where('event_type', $eventType)
                ->where('authority_reference', $opened->authority_reference)
                ->first();

            if ($existing instanceof RegistrationCaseEvent) {
                return $existing;
            }

            return RegistrationCaseEvent::query()->create([
                'enrollment_id' => $locked->id,
                'sequence' => ((int) $locked->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => $eventType,
                'from_outcome' => $locked->canonical_outcome,
                'to_outcome' => $locked->canonical_outcome,
                'reason' => trim($outcome),
                'authority_reference' => $opened->authority_reference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }

    private function authorizeSourceActor(User $actor): void
    {
        if (! $actor->canAuthenticate() || ! $actor->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ])) {
            throw new AuthorizationException('Only an authorized academic owner may record a source-impact review.');
        }
    }

    private function validateSource(string $sourceType, string $sourceReference, string $reason): void
    {
        if (! in_array($sourceType, [self::SourceTimetableRevision, self::SourceAcademicResult], true)
            || blank($sourceReference) || blank($reason)) {
            throw ValidationException::withMessages(['impact_review' => 'A supported immutable source, reference, and reason are required.']);
        }
    }
}
