<?php

namespace App\Actions\Enrollment;

use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Validation\ValidationException;

class RegistrationReadinessQuery
{
    public function __construct(
        private readonly EnrollmentPaymentRequirementProjection $finance,
        private readonly ReadyApplicantProjectionQuery $readyApplicants,
        private readonly RegistrationAcademicEligibilityQuery $academicEligibility,
        private readonly RegistrationPlacementValidator $placementValidator,
        private readonly RegistrationShortageProjection $shortages,
        private readonly StudentUnitLoadService $unitLoad,
        private readonly ConfirmRegistrationIdentity $identityConfirmations,
    ) {}

    /**
     * @return array{ready:bool,proposal:bool,confirmation:bool,placement:bool,finance:bool,identity:bool,eligibility:bool,shortages:list<array<string,mixed>>,blockers:list<string>}
     */
    public function for(Enrollment $enrollment): array
    {
        $enrollment->loadMissing([
            'admissionApplication', 'studentProfile', 'credentialUser',
            'currentProposalVersion.confirmation',
            'currentProposalVersion.curriculumVersion',
            'currentProposalVersion.items.reservation',
            'currentProposalVersion.items.termOffering.curriculumEntry.courseSpecification.course',
        ]);
        $proposal = $enrollment->currentProposalVersion;
        $proposalReady = $proposal instanceof RegistrationProposalVersion && $proposal->state === RegistrationProposalVersion::StateConfirmed;
        $currentTimetableId = PublishedTimetableVersion::query()
            ->where('term_id', $enrollment->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')
            ->value('id');
        $sourceReady = $proposalReady
            && $currentTimetableId !== null
            && (int) $proposal->published_timetable_version_id === (int) $currentTimetableId;
        $placementReady = $sourceReady
            && $this->placementValidator->passes($enrollment, $proposal);
        $unitLoadBlocker = $proposalReady ? $this->unitLoadBlocker($enrollment, $proposal) : null;
        $finance = $this->finance->forEnrollment($enrollment);
        $shortages = $this->shortages->for($enrollment);
        $eligibilityReady = $this->authoritativeEligibilityIsCurrent($enrollment, $proposal)
            && ! $this->hasUnresolvedImpactReview($enrollment);
        $identityReady = $enrollment->studentProfile instanceof StudentProfile
            || ($enrollment->admissionApplication !== null
                && $this->identityConfirmations->latestMatching($enrollment, $enrollment->admissionApplication) !== null);
        $blockers = [];

        if (! $proposalReady) {
            $blockers[] = 'Confirmed Registration Proposal';
        }
        if (! $sourceReady) {
            $blockers[] = 'Current Published Timetable source';
        }
        if (! $placementReady) {
            $blockers[] = 'Complete current placement';
        }
        if ($unitLoadBlocker !== null) {
            $blockers[] = $unitLoadBlocker;
        }
        if ($finance['state'] !== 'Cleared') {
            $blockers[] = 'Accounting clearance';
        }
        if (! $eligibilityReady) {
            $blockers[] = 'Current academic eligibility or source-impact review';
        }
        if (! $identityReady) {
            $blockers[] = 'Current learner-confirmed identity and contact source';
        }

        return [
            'ready' => $blockers === [],
            'proposal' => $proposalReady,
            'confirmation' => $proposal?->confirmation !== null,
            'placement' => $placementReady,
            'finance' => $finance['state'] === 'Cleared',
            'identity' => $identityReady,
            'eligibility' => $eligibilityReady,
            'shortages' => $shortages,
            'blockers' => $blockers,
        ];
    }

    private function unitLoadBlocker(
        Enrollment $enrollment,
        RegistrationProposalVersion $proposal,
    ): ?string {
        try {
            $this->unitLoad->assertProposalPermitted($enrollment, $proposal);

            return null;
        } catch (ValidationException $exception) {
            return str_contains($exception->getMessage(), 'graduating-overload authority')
                ? 'Graduating overload authority'
                : 'Curriculum term load unavailable';
        }
    }

    private function authoritativeEligibilityIsCurrent(
        Enrollment $enrollment,
        ?RegistrationProposalVersion $proposal,
    ): bool {
        if ($enrollment->credentialUser === null || ! $proposal instanceof RegistrationProposalVersion) {
            return false;
        }

        if ($enrollment->admissionApplication !== null) {
            $projection = $this->readyApplicants->forApplication($enrollment->admissionApplication);

            return $projection['ready']
                && (int) $projection['user_id'] === (int) $enrollment->credential_user_id
                && (int) $projection['term_id'] === (int) $enrollment->term_id
                && (int) $projection['program_id'] === (int) $proposal->curriculumVersion?->program_id;
        }

        $profile = $enrollment->studentProfile;
        $curriculum = $proposal->curriculumVersion;

        return $profile instanceof StudentProfile
            && ! $profile->blocksEnrollmentByLifecycle()
            && $curriculum instanceof CurriculumVersion
            && $this->academicEligibility->passes(
                $enrollment,
                $curriculum,
                $proposal->items->pluck('termOffering')->filter()->values(),
            );
    }

    private function hasUnresolvedImpactReview(Enrollment $enrollment): bool
    {
        return RegistrationCaseEvent::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('event_type', 'like', '%ImpactReviewOpened')
            ->get()
            ->contains(function (RegistrationCaseEvent $opened) use ($enrollment): bool {
                $resolvedType = str_replace('Opened', 'Resolved', (string) $opened->event_type);

                return ! RegistrationCaseEvent::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('event_type', $resolvedType)
                    ->where('authority_reference', $opened->authority_reference)
                    ->exists();
            });
    }
}
