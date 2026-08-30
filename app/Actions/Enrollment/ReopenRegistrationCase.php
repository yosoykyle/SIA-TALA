<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Models\Assessment;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReopenRegistrationCase
{
    public function __construct(
        private readonly CalendarPhaseGateService $calendar,
        private readonly ReadyApplicantProjectionQuery $readyApplicants,
    ) {}

    public function execute(
        Enrollment $enrollment,
        User $actor,
        string $reason,
        string $authorityReference,
        ?int $expectedLockVersion = null,
        ?RegistrationCaseEvent $lateAuthority = null,
    ): Enrollment {
        if (! $actor->canAuthenticate()
            || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only authorized Registrar staff may reopen a Registration Case.');
        }

        return DB::transaction(function () use ($enrollment, $actor, $reason, $authorityReference, $expectedLockVersion, $lateAuthority): Enrollment {
            $locked = Enrollment::query()
                ->with(['admissionApplication', 'studentProfile.curriculumVersion', 'credentialUser'])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            Term::query()->whereKey($locked->term_id)->lockForUpdate()->firstOrFail();
            User::query()->whereKey($locked->credential_user_id)->lockForUpdate()->firstOrFail();
            $authorityReference = trim($authorityReference);
            $reason = trim($reason);

            if ($expectedLockVersion !== null && $locked->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages(['case' => 'The Registration Case changed. Refresh before reopening.']);
            }

            if (! in_array($locked->canonical_outcome, Enrollment::reopenableOutcomes(), true)) {
                throw ValidationException::withMessages(['case' => 'Only a cancelled or not-enrolled case may be reopened.']);
            }

            if ($authorityReference === '' || $reason === '') {
                throw ValidationException::withMessages(['case' => 'Reopening requires a recorded reason and authority reference.']);
            }

            $requiresLateAuthority = now()->isAfter($this->calendar->finalEnrollmentCutoff((int) $locked->term_id));

            $late = $lateAuthority instanceof RegistrationCaseEvent
                ? RegistrationCaseEvent::query()->whereKey($lateAuthority->id)->lockForUpdate()->first()
                : null;
            if ($requiresLateAuthority && (! $late instanceof RegistrationCaseEvent
                || (int) $late->enrollment_id !== (int) $locked->id
                || $late->event_type !== RecordLateEnrollmentReopenAuthority::EventType
                || $late->authority_reference !== $authorityReference
                || RegistrationCaseEvent::query()
                    ->where('enrollment_id', $locked->id)
                    ->where('event_type', 'Reopened')
                    ->where('authority_reference', $late->authority_reference)
                    ->where('id', '>', $late->id)
                    ->exists())) {
                throw ValidationException::withMessages([
                    'late_authority' => 'Reopening after the final cutoff requires an unused exact late-enrollment authority for this same case and Term.',
                ]);
            }

            $this->assertCurrentSourceEligibility($locked);

            $from = $locked->canonical_outcome;
            $proposalCount = RegistrationProposalVersion::query()
                ->where('enrollment_id', $locked->id)
                ->whereIn('state', [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed])
                ->lockForUpdate()
                ->count();
            $reservationCount = EnrollmentSeatReservation::query()
                ->where('enrollment_id', $locked->id)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->lockForUpdate()
                ->count();
            $assessmentCount = Assessment::query()
                ->where('enrollment_id', $locked->id)
                ->where('state', Assessment::StateActive)
                ->lockForUpdate()
                ->count();
            RegistrationCaseEvent::query()->create([
                'enrollment_id' => $locked->id,
                'sequence' => ((int) $locked->registrationEvents()->lockForUpdate()->max('sequence')) + 1,
                'event_type' => 'ReopenImpactPreviewed',
                'from_outcome' => $from,
                'to_outcome' => $from,
                'reason' => "Current sources revalidated; reopen will supersede {$proposalCount} proposal(s), release {$reservationCount} reservation(s), and supersede {$assessmentCount} assessment(s). No prior checkpoint is restored.",
                'authority_reference' => $authorityReference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);
            RegistrationProposalVersion::query()
                ->where('enrollment_id', $locked->id)
                ->whereIn('state', [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed])
                ->lockForUpdate()
                ->update(['state' => RegistrationProposalVersion::StateSuperseded]);
            EnrollmentSeatReservation::query()
                ->where('enrollment_id', $locked->id)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->lockForUpdate()
                ->update(['status' => EnrollmentSeatReservation::StatusReleased, 'released_at' => now()]);
            Assessment::query()
                ->where('enrollment_id', $locked->id)
                ->where('state', Assessment::StateActive)
                ->lockForUpdate()
                ->update(['state' => Assessment::StateSuperseded]);
            TermAccount::query()
                ->where('enrollment_id', $locked->id)
                ->lockForUpdate()
                ->update(['state' => TermAccount::StateOpen]);
            $locked->update([
                'canonical_outcome' => Enrollment::OutcomeInProgress,
                'status' => 'pending_review',
                'current_proposal_version_id' => null,
                'cancelled_at' => null,
                'status_reason' => $reason,
                'lock_version' => $locked->lock_version + 1,
            ]);
            RegistrationCaseEvent::query()->create([
                'enrollment_id' => $locked->id,
                'sequence' => ((int) $locked->registrationEvents()->max('sequence')) + 1,
                'event_type' => 'Reopened',
                'from_outcome' => $from,
                'to_outcome' => Enrollment::OutcomeInProgress,
                'reason' => $reason,
                'authority_reference' => $authorityReference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function assertCurrentSourceEligibility(Enrollment $enrollment): void
    {
        if (! $enrollment->credentialUser?->canAuthenticate()) {
            throw ValidationException::withMessages(['case' => 'The learner account is not currently eligible to resume registration.']);
        }

        if ($enrollment->admissionApplication !== null) {
            $projection = $this->readyApplicants->forApplication($enrollment->admissionApplication);

            if ($projection['ready'] !== true || (int) $projection['term_id'] !== (int) $enrollment->term_id) {
                throw ValidationException::withMessages(['case' => 'The originating Applicant is no longer Ready for Enrollment in this exact Term.']);
            }

            return;
        }

        $profile = $enrollment->studentProfile;
        if (! $profile instanceof StudentProfile
            || $profile->blocksEnrollmentByLifecycle()
            || $profile->curriculumVersion?->state !== CurriculumVersion::StateActive) {
            throw ValidationException::withMessages(['case' => 'The continuing Student’s current lifecycle, Program, and Curriculum authority do not permit reopening.']);
        }
    }
}
