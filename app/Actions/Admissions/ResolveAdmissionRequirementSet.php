<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirementSet;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ResolveAdmissionRequirementSet
{
    public function forApplication(
        AdmissionApplication $application,
        bool $lockForUpdate = false,
    ): AdmissionRequirementSet {
        if ($application->application_state === AdmissionApplication::StateActionNeeded
            && $application->current_submission_version_id !== null) {
            $submissionQuery = $application->currentSubmissionVersion();

            if ($lockForUpdate) {
                $submissionQuery->lockForUpdate();
            }

            return $submissionQuery
                ->firstOrFail()
                ->requirementSet()
                ->firstOrFail();
        }

        return $this->forScope(
            (int) $application->admission_cycle_id,
            (string) $application->application_path,
            $lockForUpdate,
        );
    }

    public function forScope(
        int $admissionCycleId,
        string $applicationPath,
        bool $lockForUpdate = false,
    ): AdmissionRequirementSet {
        $query = AdmissionRequirementSet::query()
            ->where('admission_cycle_id', $admissionCycleId)
            ->where('application_path', $applicationPath)
            ->where('state', AdmissionRequirementSet::StatePublished)
            ->where('effective_at', '<=', CarbonImmutable::now(config('app.timezone')))
            ->latest('version');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $requirementSet = $query->first();

        if (! $requirementSet instanceof AdmissionRequirementSet) {
            throw ValidationException::withMessages([
                'requirements' => 'No effective published requirement version applies to this application.',
            ]);
        }

        return $requirementSet;
    }
}
