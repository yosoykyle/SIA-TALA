<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;

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

        if ($cycle->state === AdmissionCycle::StateDraft
            && filled($cycle->code)
            && AdmissionCycle::codeIsReservedByAnotherCycle($cycle->code, $cycle->id)) {
            $blockers[] = $this->blocker(
                'cycle_code_reserved',
                'Admission Cycle internal code',
                'Registrar',
                'A published admission cycle already uses this internal cycle code.',
                'Choose a different internal cycle code.',
                'Open Continue setup, change the internal cycle code, save, and rerun readiness.',
            );
        }

        if ($cycle->state === AdmissionCycle::StateDraft
            && ($cycle->requirements_due_at === null || $cycle->payment_due_at === null || $cycle->period_ends_at === null)) {
            $blockers[] = $this->blocker(
                'post_admission_deadlines',
                'Admission Cycle post-admission deadlines',
                'Registrar',
                'Physical papers due, Enrollment payment due, and Final admission cutoff are required.',
                'Set all three post-admission deadlines.',
                'Open Continue setup, set the three post-admission deadlines, save, and rerun readiness.',
            );
        }

        $enabledPaths = $this->enabledPaths($cycle);

        if ($enabledPaths === []) {
            $blockers[] = $this->blocker(
                'accepting_programs',
                'Admission Cycle program selection and active Program authority',
                'Registrar',
                'No active accepting program with an open curriculum batch is enabled for this cycle.',
                'Select at least one active program whose curriculum is assigned for the target school year batch.',
                'Activate the program or assign a published curriculum for the incoming batch, select it for the cycle, and rerun readiness.',
            );
        }

        $now = now(config('app.timezone'));
        $effectiveReference = $cycle->opens_at instanceof Carbon && $cycle->opens_at->greaterThan($now)
            ? $cycle->opens_at
            : $now;

        foreach ($enabledPaths as $path) {
            $publishedSets = $cycle->requirementSets
                ->where('application_path', $path)
                ->where('state', AdmissionRequirementSet::StatePublished)
                ->sortByDesc('version');

            $hasApplicableVersion = $publishedSets->contains(
                fn (AdmissionRequirementSet $set): bool => $set->effective_at !== null
                    && $set->effective_at->lessThanOrEqualTo($effectiveReference)
                    && $this->requirementSetCompleteness->isComplete($set),
            );

            if (! $hasApplicableVersion) {
                $studentType = AdmissionCycle::studentTypeLabel($path);
                $latestPublished = $publishedSets->first();
                $reason = "{$studentType} applicants have no published requirement list yet.";
                $nextAction = "Create and publish the {$studentType} requirements.";

                if ($latestPublished instanceof AdmissionRequirementSet) {
                    $completenessErrors = $this->requirementSetCompleteness->errors($latestPublished);

                    if ($completenessErrors !== []) {
                        $reason = "{$studentType} requirements are incomplete: {$completenessErrors[0]}";
                        $nextAction = "Update and republish the {$studentType} requirements.";
                    } elseif ($latestPublished->effective_at === null || $latestPublished->effective_at->greaterThan($effectiveReference)) {
                        $reason = "{$studentType} requirements are scheduled to take effect after the cycle opens.";
                        $nextAction = "Publish a {$studentType} requirement list effective on or before the cycle opening date.";
                    }
                }

                $blockers[] = $this->blocker(
                    'requirement_set_'.str($path)->snake(),
                    "Published Admission Requirement Set for {$studentType} applicants",
                    'Registrar',
                    $reason,
                    $nextAction,
                    "Complete and publish the {$studentType} requirement version, then rerun readiness.",
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
                'Admission Cycle Registrar owner and identity/access authority',
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
        $hasConfiguredCurricula = CurriculumVersion::query()->exists();
        $programs = $cycle->programs()
            ->where('programs.is_active', true)
            ->with(['curriculumVersions.academicYears'])
            ->get()
            ->filter(function (Program $program) use ($cycle, $hasConfiguredCurricula): bool {
                if (! $hasConfiguredCurricula) {
                    return true;
                }

                return $program->admissionsAvailability($cycle)['available'];
            });

        if ($programs->contains(fn (Program $program): bool => (bool) data_get($program->getRelation('pivot'), 'accepts_first_year'))) {
            $paths[] = AdmissionCycle::PathFirstYear;
        }

        if ($programs->contains(fn (Program $program): bool => (bool) data_get($program->getRelation('pivot'), 'accepts_transferee'))) {
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
