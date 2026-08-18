<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\Term;
use App\Models\User;

class AdmissionCycleReadinessService
{
    public function __construct(
        private readonly AdmissionEvidenceService $evidenceService,
        private readonly AdmissionRequirementSetCompletenessService $requirementSetCompleteness,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     blockers: list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>
     * }
     */
    public function for(AdmissionCycle $cycle): array
    {
        $cycle->loadMissing(['term', 'requirementSets.requirements', 'registrarOwner.roles.permissions']);
        $blockers = [];

        if (! $cycle->term instanceof Term
            || $cycle->term->state !== Term::StateActive
            || $cycle->opens_at === null
            || $cycle->closes_at === null
            || $cycle->correction_closes_at === null
            || ! $cycle->opens_at->lessThan($cycle->closes_at)
            || $cycle->closes_at->greaterThan($cycle->correction_closes_at)) {
            $blockers[] = $this->blocker(
                'target_term_and_dates',
                'Admission Cycle and active academic term',
                'Registrar',
                'The target term, opening, public closing, or correction boundary is invalid.',
                'Correct the cycle term and dates.',
                'Select an active term, ensure opening precedes public closing and public closing is not after the correction boundary, then rerun readiness.',
            );
        }

        $enabledPaths = $this->enabledPaths($cycle);

        if ($enabledPaths === []) {
            $blockers[] = $this->blocker(
                'accepting_programs',
                'Admission Cycle program selection and active Program authority',
                'Registrar',
                'No active program accepts an enabled application path.',
                'Select an active accepting program.',
                'Activate or correct the Program authority, select it for the cycle, and rerun readiness.',
            );
        }

        foreach ($enabledPaths as $path) {
            $hasApplicableVersion = $cycle->requirementSets
                ->where('application_path', $path)
                ->where('state', AdmissionRequirementSet::StatePublished)
                ->contains(fn (AdmissionRequirementSet $set): bool => $set->effective_at !== null
                    && $set->effective_at->lessThanOrEqualTo(now(config('app.timezone')))
                    && $this->requirementSetCompleteness->isComplete($set));

            if (! $hasApplicableVersion) {
                $blockers[] = $this->blocker(
                    'requirement_set_'.str($path)->snake(),
                    "Published Admission Requirement Set for {$path}",
                    'Registrar',
                    "The {$path} path has no effective, complete published requirement version with its mandatory core credential and valid exception classifications.",
                    'Publish an applicable requirement-set version.',
                    'Complete and publish the path requirement version, then rerun readiness.',
                );
            }
        }

        if (blank($cycle->applicant_instructions)
            || blank($cycle->support_contact)
            || blank($cycle->privacy_notice_reference)) {
            $blockers[] = $this->blocker(
                'applicant_guidance',
                'Admission Cycle instructions, support contact, and privacy reference',
                'Registrar and institution',
                'Required applicant guidance or an approved reference is missing.',
                'Complete the applicant-facing guidance.',
                'Record approved instructions, support, and privacy references, then rerun readiness.',
            );
        }

        if (! $this->evidenceService->storageIsPrivateAndAvailable()) {
            $blockers[] = $this->blocker(
                'private_evidence_storage',
                'Configured private evidence filesystem',
                'System Administrator',
                'Private upload, validation, retrieval, and authorized download are unavailable.',
                'Restore private evidence storage.',
                'Restore the private filesystem; do not publish files or weaken access controls.',
            );
        }

        $owner = $cycle->registrarOwner;

        if (! $owner instanceof User
            || ! $owner->hasRole(User::StaffRoleRegistrar)
            || ! $owner->canAuthenticate()
            || ! $owner->can('manage-admission-setup')) {
            $blockers[] = $this->blocker(
                'registrar_owner',
                'Admission Cycle Registrar owner and Clinic 1 access authority',
                'Institution',
                'The cycle has no accountable authorized Registrar owner.',
                'Assign an authorized Registrar owner.',
                'Assign an active Registrar with admission-setup authority, then rerun readiness.',
            );
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /** @return list<string> */
    private function enabledPaths(AdmissionCycle $cycle): array
    {
        $paths = [];
        $programs = $cycle->programs()->where('programs.is_active', true);

        if ((clone $programs)->wherePivot('accepts_first_year', true)->exists()) {
            $paths[] = AdmissionCycle::PathFirstYear;
        }

        if ((clone $programs)->wherePivot('accepts_transferee', true)->exists()) {
            $paths[] = AdmissionCycle::PathTransferee;
        }

        return $paths;
    }

    /**
     * @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}
     */
    private function blocker(
        string $code,
        string $source,
        string $owner,
        string $reason,
        string $nextAction,
        string $recovery,
    ): array {
        return [
            'code' => $code,
            'source' => $source,
            'owner' => $owner,
            'reason' => $reason,
            'next_action' => $nextAction,
            'recovery' => $recovery,
        ];
    }
}
