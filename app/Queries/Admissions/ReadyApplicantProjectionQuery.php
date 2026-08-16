<?php

namespace App\Queries\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * @phpstan-type ReadyApplicantProjection array{
 *     ready: bool,
 *     application_reference: string|null,
 *     application_id: int,
 *     user_id: int,
 *     program_id: int,
 *     term_id: int,
 *     path: string,
 *     decision_id: int|null,
 *     requirement_set_id: int|null,
 *     credential_result_ids: list<int>,
 *     verified_identifiers: array{lrn: string|null, prior_college_identifier: string|null},
 *     ready_at: string|null,
 *     unresolved_post_enrollment_follow_ups: list<int>,
 *     registration_started: bool,
 *     blockers: list<array{source: string, owner: string, reason: string, recovery: string}>
 * }
 */
class ReadyApplicantProjectionQuery
{
    /** @return ReadyApplicantProjection */
    public function forApplication(AdmissionApplication $application): array
    {
        $application = AdmissionApplication::query()->findOrFail($application->id);
        $currentSubmission = $application->currentSubmissionVersion()->first();
        $requirementSet = $currentSubmission?->requirementSet()->first();
        $currentDecision = $application->decisions()
            ->whereDoesntHave('successor')
            ->first();
        $blockers = [];

        if (! $currentDecision instanceof AdmissionDecision
            || $currentDecision->decision !== AdmissionDecision::DecisionAdmitted
            || $application->application_state !== AdmissionApplication::StateAdmitted) {
            $blockers[] = $this->blocker(
                'Current Admission Decision',
                'Registrar',
                'The application has no current Admitted decision.',
                'Complete or correct the authorized admission decision.',
            );
        }

        if ($application->identityMatchReviews()
            ->where('outcome', IdentityMatchReview::OutcomePending)
            ->exists()) {
            $blockers[] = $this->blocker(
                'Identity Match Review',
                'Registrar',
                'A private identity warning is unresolved.',
                'Resolve the warning with authorized evidence.',
            );
        }

        if ($requirementSet === null) {
            $blockers[] = $this->blocker(
                'Submitted Application Version',
                'Registrar',
                'No retained requirement-set version is available.',
                'Restore the submitted source version; do not infer current requirements.',
            );
        }

        $credentialResultIds = [];
        $readyMoments = collect([$currentDecision?->decided_at]);
        $postEnrollmentFollowUps = [];

        foreach ($requirementSet?->requirements()->get() ?? collect() as $requirement) {
            $currentResult = $application->credentialResults()
                ->where('admission_requirement_id', $requirement->id)
                ->whereDoesntHave('successor')
                ->first();

            if ($currentResult instanceof OfficialCredentialResult) {
                $credentialResultIds[] = $currentResult->id;
                $readyMoments->push($currentResult->recorded_at);
            }

            if ($requirement->due_stage === AdmissionRequirement::DueEnrollmentReadiness
                && ! $this->resultSatisfiesReadiness($requirement, $currentResult)) {
                $blockers[] = $this->blocker(
                    'Official Credential Result #'.$requirement->id,
                    'Registrar',
                    "{$requirement->label} is not verified or covered by a valid permitted non-core exception.",
                    'Record receipt, review, verification, or a valid authorized exception.',
                );
            }

            if ($requirement->due_stage === AdmissionRequirement::DuePostEnrollmentFollowUp
                && ! $this->resultSatisfiesReadiness($requirement, $currentResult)) {
                $postEnrollmentFollowUps[] = $requirement->id;
            }
        }

        sort($credentialResultIds);
        sort($postEnrollmentFollowUps);
        $ready = $blockers === [];
        $readyAt = $ready
            ? $readyMoments->filter()->sortBy(fn ($date): int => $date->getTimestamp())->last()?->toIso8601String()
            : null;

        return [
            'ready' => $ready,
            'application_reference' => $application->application_reference,
            'application_id' => $application->id,
            'user_id' => $application->user_id,
            'program_id' => $application->program_id,
            'term_id' => $application->term_id,
            'path' => $application->application_path,
            'decision_id' => $currentDecision?->id,
            'requirement_set_id' => $requirementSet?->id,
            'credential_result_ids' => $credentialResultIds,
            'verified_identifiers' => [
                'lrn' => $application->lrn,
                'prior_college_identifier' => $application->prior_college_identifier,
            ],
            'ready_at' => $readyAt,
            'unresolved_post_enrollment_follow_ups' => $postEnrollmentFollowUps,
            'registration_started' => $this->registrationHasStarted($application),
            'blockers' => $blockers,
        ];
    }

    /** @return Collection<int, int> */
    public function readyApplicationIds(): Collection
    {
        return AdmissionApplication::query()
            ->canonical()
            ->where('application_state', AdmissionApplication::StateAdmitted)
            ->orderBy('id')
            ->get()
            ->filter(fn (AdmissionApplication $application): bool => $this->forApplication($application)['ready'])
            ->map(fn (AdmissionApplication $application): int => $application->id)
            ->values();
    }

    public function registrationHasStarted(AdmissionApplication $application): bool
    {
        return Enrollment::query()
            ->where('term_id', $application->term_id)
            ->whereIn('student_profile_id', StudentProfile::query()
                ->select('id')
                ->where('applicant_intake_id', $application->id))
            ->exists();
    }

    private function resultSatisfiesReadiness(
        AdmissionRequirement $requirement,
        ?OfficialCredentialResult $result,
    ): bool {
        if (! $result instanceof OfficialCredentialResult) {
            return false;
        }

        if ($result->result === OfficialCredentialResult::ResultVerified) {
            return true;
        }

        return $result->result === OfficialCredentialResult::ResultAuthorizedException
            && $requirement->credential_classification === AdmissionRequirement::ClassificationNonCore
            && $requirement->exception_permitted
            && filled($requirement->required_approving_authority)
            && $result->exception_expires_at?->isFuture();
    }

    /** @return array{source: string, owner: string, reason: string, recovery: string} */
    private function blocker(string $source, string $owner, string $reason, string $recovery): array
    {
        return compact('source', 'owner', 'reason', 'recovery');
    }
}
