<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Models\AcademicDecision;
use App\Models\AdmissionApplication;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationCreditEvaluation;
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
        private readonly AcademicEnrollmentEffect $academicEffect,
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
        $identityReady = $enrollment->credentialUser?->canAuthenticate() && ($enrollment->studentProfile instanceof StudentProfile
            || ($enrollment->admissionApplication !== null
                && $this->readyApplicants->forApplication($enrollment->admissionApplication)['ready']));
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
            $blockers[] = $enrollment->admissionApplication?->application_path === AdmissionApplication::PathTransferee
                && ($proposal === null || RegistrationCreditEvaluation::latestCurrentFor($enrollment, $proposal->curriculum_version_id) === null)
                    ? 'Academic evaluation required' : 'Current academic eligibility or source-impact review';
        }
        if (! $identityReady) {
            $blockers[] = 'Current verified admissions identity and clearance';
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

    public function assertPrepared(Enrollment $enrollment, RegistrationProposalVersion $proposal): void
    {
        $this->placementValidator->assertCurrent($enrollment, $proposal, lockForUpdate: true, requireConfirmation: false);

        $this->assertSourcesCurrent($enrollment, $proposal);

        if ($this->finance->forEnrollment($enrollment)['state'] === 'Unavailable') {
            throw ValidationException::withMessages(['assessment' => 'Accounting must assess this reserved proposal before it can be confirmed.']);
        }
    }

    public function assertSourcesCurrent(Enrollment $enrollment, RegistrationProposalVersion $proposal): void
    {
        $enrollment->loadMissing(['credentialUser', 'admissionApplication', 'studentProfile', 'term']);
        $proposal->loadMissing(['curriculumVersion', 'items.termOffering.curriculumEntry.courseSpecification']);
        if (! $this->authoritativeEligibilityIsCurrent($enrollment, $proposal) || $this->hasUnresolvedImpactReview($enrollment)) {
            throw ValidationException::withMessages(['proposal' => 'The academic or admissions source changed. Registrar must review the current proposal.']);
        }
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
        if (! $enrollment->credentialUser?->canAuthenticate() || ! $proposal instanceof RegistrationProposalVersion) {
            return false;
        }

        if ($enrollment->admissionApplication !== null) {
            $projection = $this->readyApplicants->forApplication($enrollment->admissionApplication);

            return $projection['ready']
                && (int) $projection['user_id'] === (int) $enrollment->credential_user_id
                && (int) $projection['term_id'] === (int) $enrollment->term_id
                && (int) $projection['program_id'] === (int) $proposal->curriculumVersion?->program_id
                && ($enrollment->admissionApplication->application_path !== AdmissionApplication::PathTransferee
                    || (RegistrationCreditEvaluation::latestCurrentFor($enrollment, $proposal->curriculum_version_id)?->id !== null
                        && (int) data_get($proposal->source_snapshot, 'credit_evaluation_id') === (int) RegistrationCreditEvaluation::latestCurrentFor($enrollment, $proposal->curriculum_version_id)?->id))
                && $this->academicEligibility->passes($enrollment, $proposal->curriculumVersion, $proposal->items->pluck('termOffering')->filter()->values());
        }

        $profile = $enrollment->studentProfile;
        $curriculum = $proposal->curriculumVersion;
        $effect = $profile instanceof StudentProfile ? $this->academicEffect->forStudent($profile, $enrollment->term) : null;

        return $profile instanceof StudentProfile
            && ! $profile->blocksEnrollmentByLifecycle()
            && ! in_array($effect['effect'] ?? null, [AcademicDecision::EffectBlocked, AcademicDecision::EffectPendingDecision], true)
            && (($effect['effect'] ?? null) !== AcademicDecision::EffectAdvisingRequired || $proposal->selection_basis === Enrollment::SelectionIndividuallyAdvised)
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
