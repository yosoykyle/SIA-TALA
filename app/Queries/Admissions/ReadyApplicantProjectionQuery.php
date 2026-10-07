<?php

namespace App\Queries\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\PreliminaryEvidenceReview;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use Carbon\CarbonImmutable;
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
 *     submission_version_id: int|null,
 *     clearance_id: int|null,
 *     clearance_result: string|null,
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

        $clearance = $application->enrollmentClearances()->whereDoesntHave('successor')->first();
        $matchesCurrentSource = $clearance !== null
            && (int) $clearance->application_submission_version_id === (int) $currentSubmission?->id
            && (int) $clearance->admission_decision_id === (int) $currentDecision?->id
            && (int) $currentDecision?->application_submission_version_id === (int) $currentSubmission?->id
            && hash_equals($clearance->identity_source_hash, RegistrarEnrollmentClearance::identitySourceHash($application));

        if (! $matchesCurrentSource || $clearance->result !== RegistrarEnrollmentClearance::ResultCleared
            || ! $clearance->external_checks_confirmed) {
            $blockers[] = $this->blocker(
                'Registrar enrollment clearance',
                'Registrar',
                $matchesCurrentSource && $clearance->result === 'ActionNeeded'
                    ? 'External school checks need attention.'
                    : 'Awaiting current Registrar clearance.',
                $matchesCurrentSource && filled($clearance->safe_instruction)
                    ? $clearance->safe_instruction
                    : 'Contact the Registrar to complete the external checks and record a matching clearance.',
            );
        }

        $credentialResultIds = $application->credentialResults()->orderBy('id')->pluck('id')->all();
        $postEnrollmentFollowUps = [];
        $readyMoments = collect([$currentDecision?->decided_at, $clearance?->recorded_at]);
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
            'submission_version_id' => $currentSubmission?->id,
            'clearance_id' => $matchesCurrentSource ? $clearance?->id : null,
            'clearance_result' => $matchesCurrentSource ? $clearance?->result : null,
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

    public function draftCycleIsOpen(AdmissionApplication $application): bool
    {
        $cycle = $application->admissionCycle;

        return $cycle !== null && $cycle->state === AdmissionCycle::StatePublished
            && $cycle->opens_at <= now() && $cycle->closes_at > now();
    }

    public function draftNextAction(AdmissionApplication $application): string
    {
        return $this->draftCycleIsOpen($application)
            ? 'Complete and submit the five-step Application.'
            : 'Inspect or discard your saved Draft. Its Admission Cycle is closed or canceled; saving, uploading, and first submission are unavailable. Contact the Registrar for Cycle guidance.';
    }

    public function preliminaryReviewIsComplete(AdmissionApplication $application): bool
    {
        $requirements = $application->currentSubmissionVersion?->requirementSet?->requirements;
        if ($requirements === null) {
            return false;
        }

        return $requirements->where('due_stage', AdmissionRequirement::DuePreliminaryReview)
            ->every(function (AdmissionRequirement $requirement) use ($application): bool {
                $evidence = $application->evidenceVersions()
                    ->where('admission_requirement_id', $requirement->id)->latest('id')->first();

                return $evidence?->preliminaryReviews()->whereDoesntHave('successor')->first()?->result
                    === PreliminaryEvidenceReview::ResultAccepted;
            });
    }

    public function submittedNextAction(AdmissionApplication $application): string
    {
        if ($application->identityMatchReviews()->where('outcome', IdentityMatchReview::OutcomePending)->exists()) {
            return 'Resolve the current private identity warning.';
        }

        return $this->preliminaryReviewIsComplete($application)
            ? 'Record the admission decision using the current reviewed evidence.'
            : 'Review the current required preliminary copies.';
    }

    public function responsibleParty(AdmissionApplication $application): string
    {
        if ($application->application_state === AdmissionApplication::StateAdmitted) {
            $projection = $this->forApplication($application);
            if (! $projection['ready']) {
                return 'Applicant / Registrar';
            }

            return $projection['registration_started'] || ! $this->enrollmentAvailability($application)['available']
                ? 'Registrar'
                : 'Applicant';
        }

        return match ($application->application_state) {
            AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded => 'Applicant',
            AdmissionApplication::StateSubmitted => 'Registrar',
            default => 'No active task',
        };
    }

    public function enrollmentNextAction(AdmissionApplication $application): string
    {
        if ($this->registrationHasStarted($application)) {
            return 'Follow the existing Registration Case and its current Registrar instructions; admissions readiness does not change official enrollment.';
        }

        $availability = $this->enrollmentAvailability($application);

        return $availability['available']
            ? 'Enrollment is open. Start enrollment to begin your Registration Case.'
            : $availability['explanation'];
    }

    /** @return array{available: bool, status: string, explanation: string, opens_at: ?CarbonImmutable, closes_at: ?CarbonImmutable} */
    public function enrollmentAvailability(AdmissionApplication $application): array
    {
        if ($application->term_id === null) {
            return [
                'available' => false,
                'status' => 'unavailable',
                'explanation' => 'Term enrollment window is not available.',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        $term = $application->term;

        if (! $term) {
            return [
                'available' => false,
                'status' => 'unavailable',
                'explanation' => 'Term record is not found.',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        $package = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->latest('version')
            ->first();

        if (! $package) {
            return [
                'available' => false,
                'status' => 'not_configured',
                'explanation' => 'Enrollment has not opened yet. The official academic calendar package is being prepared by the Registrar.',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        $window = $package->windows()
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->first();

        if (! $window) {
            return [
                'available' => false,
                'status' => 'not_configured',
                'explanation' => 'Enrollment has not opened yet. The enrollment window has not been scheduled for this Term.',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        $timezone = (string) config('app.display_timezone');
        $opensAt = CarbonImmutable::parse((string) $window->opens_on, $timezone)->startOfDay();
        $cutoff = filled($window->cutoff_at) ? (string) $window->cutoff_at : '23:59:59';
        $closesAt = CarbonImmutable::parse($window->closes_on->toDateString().' '.$cutoff, $timezone);
        $now = CarbonImmutable::now($timezone);

        if ($now->lt($opensAt)) {
            return [
                'available' => false,
                'status' => 'upcoming',
                'explanation' => 'Enrollment will open on '.$opensAt->timezone(config('app.display_timezone'))->format('F j, Y, g:i A').'.',
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
            ];
        }

        if ($now->gt($closesAt)) {
            return [
                'available' => false,
                'status' => 'closed',
                'explanation' => 'Ordinary enrollment for this Term closed on '.$closesAt->timezone(config('app.display_timezone'))->format('F j, Y, g:i A').'. Contact the Registrar if you require late-enrollment assistance.',
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
            ];
        }

        return [
            'available' => true,
            'status' => 'open',
            'explanation' => 'Enrollment is open until '.$closesAt->timezone(config('app.display_timezone'))->format('F j, Y, g:i A').'.',
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
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
            ->where('credential_user_id', $application->user_id)
            ->where('admission_application_id', $application->id)
            ->where('term_id', $application->term_id)
            ->exists();
    }

    /** @return array{source: string, owner: string, reason: string, recovery: string} */
    private function blocker(string $source, string $owner, string $reason, string $recovery): array
    {
        return compact('source', 'owner', 'reason', 'recovery');
    }
}
