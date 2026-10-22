<?php

namespace App\Actions\Scheduling;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\TimetableRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveTimetableRevisionRegistrationImpact
{
    public const OutcomeRetainedWithAcknowledgement = 'RetainedWithAcknowledgement';

    public const OutcomeRegistrationChanged = 'RegistrationChanged';

    public const OutcomeCaseCancelled = 'CaseCancelled';

    public function execute(
        TimetableRevision $revision,
        Enrollment $enrollment,
        RegistrationCaseEvent $openedReview,
        User $actor,
        string $outcome,
        string $evidenceReference,
    ): RegistrationCaseEvent {
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may resolve a timetable-registration impact.');
        }

        return DB::transaction(function () use ($revision, $enrollment, $openedReview, $actor, $outcome, $evidenceReference): RegistrationCaseEvent {
            $lockedRevision = TimetableRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $lockedEnrollment = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $opened = RegistrationCaseEvent::query()->whereKey($openedReview->id)->lockForUpdate()->firstOrFail();
            $sourceReference = 'timetable-revision:'.$lockedRevision->id;
            $evidenceReference = trim($evidenceReference);
            $affectedCaseIds = collect($lockedRevision->impact_snapshot['affected_registration_case_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id);

            if ($lockedRevision->state !== TimetableRevision::StateDraft
                || ! $affectedCaseIds->contains((int) $lockedEnrollment->id)
                || (int) $opened->enrollment_id !== (int) $lockedEnrollment->id
                || $opened->event_type !== 'TimetableRevisionImpactReviewOpened'
                || $opened->authority_reference !== $sourceReference
                || ! in_array($outcome, [
                    self::OutcomeRetainedWithAcknowledgement,
                    self::OutcomeRegistrationChanged,
                    self::OutcomeCaseCancelled,
                ], true)) {
                throw ValidationException::withMessages([
                    'impact_review' => 'A current exact timetable revision, affected Registration Case, supported outcome, and attributable evidence are required.',
                ]);
            }

            if ($outcome === self::OutcomeRetainedWithAcknowledgement && $evidenceReference === '') {
                throw ValidationException::withMessages([
                    'evidence_reference' => 'Record the attributable acknowledgement for these changed meeting facts.',
                ]);
            }

            $existing = RegistrationCaseEvent::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('event_type', 'TimetableRevisionImpactReviewResolved')
                ->where('authority_reference', $sourceReference)
                ->first();
            if ($existing instanceof RegistrationCaseEvent) {
                return $existing;
            }

            $changedSectionIds = collect($lockedRevision->impact_snapshot['changed_section_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->values();
            $removesMeeting = collect($lockedRevision->changes_snapshot)
                ->contains(fn (array $change): bool => ($change['remove'] ?? false) === true);

            if ($outcome === self::OutcomeRetainedWithAcknowledgement && $removesMeeting) {
                throw ValidationException::withMessages([
                    'outcome' => 'A cancelled meeting cannot be retained by acknowledgement; record an applied registration change or case cancellation.',
                ]);
            }

            if ($outcome === self::OutcomeRegistrationChanged) {
                $proposalStillUsesChangedSection = $lockedEnrollment->currentProposalVersion()
                    ->whereHas('items', fn ($query) => $query->whereIn('section_id', $changedSectionIds))
                    ->exists();
                $officialStillUsesChangedSection = CourseEnrollment::query()
                    ->where('enrollment_id', $lockedEnrollment->id)
                    ->where('is_current', true)
                    ->where('status', CourseEnrollment::StatusActive)
                    ->whereIn('section_id', $changedSectionIds)
                    ->exists();

                if ($proposalStillUsesChangedSection || $officialStillUsesChangedSection) {
                    throw ValidationException::withMessages([
                        'outcome' => 'The current proposal or official registration still uses the affected Class Offering.',
                    ]);
                }
            }

            if ($outcome === self::OutcomeCaseCancelled
                && ! in_array($lockedEnrollment->canonical_outcome, Enrollment::reopenableOutcomes(), true)) {
                throw ValidationException::withMessages([
                    'outcome' => 'Case-cancelled resolution requires the Registration Case to be terminal first.',
                ]);
            }

            if ($evidenceReference === '' && $outcome === self::OutcomeRegistrationChanged) {
                $currentProposal = $lockedEnrollment->currentProposalVersion()
                    ->where('created_at', '>=', $opened->recorded_at)
                    ->whereDoesntHave('items', fn ($query) => $query->whereIn('section_id', $changedSectionIds))
                    ->first();
                if ($currentProposal !== null) {
                    $evidenceReference = 'registration-proposal:'.$currentProposal->id;
                } else {
                    $currentCertificate = $lockedEnrollment->currentCorVersion()
                        ->where('created_at', '>=', $opened->recorded_at)
                        ->first();
                    if ($currentCertificate !== null) {
                        $evidenceReference = 'registration-certificate:'.$currentCertificate->id;
                    }
                }
            }

            if ($evidenceReference === '' && $outcome === self::OutcomeCaseCancelled) {
                $cancellationEvent = $lockedEnrollment->registrationEvents()
                    ->where('event_type', $lockedEnrollment->canonical_outcome)
                    ->where('to_outcome', $lockedEnrollment->canonical_outcome)
                    ->where('recorded_at', '>=', $opened->recorded_at)
                    ->orderByDesc('sequence')
                    ->first();
                if ($cancellationEvent !== null) {
                    $evidenceReference = 'registration-case-event:'.$cancellationEvent->id;
                }
            }

            if ($evidenceReference === '') {
                throw ValidationException::withMessages([
                    'evidence_reference' => 'The current outcome has no exact source recorded after this review was opened.',
                ]);
            }

            return RegistrationCaseEvent::query()->create([
                'enrollment_id' => $lockedEnrollment->id,
                'sequence' => ((int) $lockedEnrollment->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => 'TimetableRevisionImpactReviewResolved',
                'from_outcome' => $lockedEnrollment->canonical_outcome,
                'to_outcome' => $lockedEnrollment->canonical_outcome,
                'reason' => json_encode([
                    'outcome' => $outcome,
                    'evidence_reference' => $evidenceReference,
                ], JSON_THROW_ON_ERROR),
                'authority_reference' => $sourceReference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }
}
