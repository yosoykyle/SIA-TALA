<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ResolveAdmissionRequirementSet
{
    public function forApplication(
        AdmissionApplication $application,
        bool $lockForUpdate = false,
    ): AdmissionRequirementSet {
        $typeCorrection = $application->correctionRequests()->where('state', ApplicationCorrectionRequest::StateActive)
            ->whereNotNull('target_application_path')->first();
        if ($typeCorrection instanceof ApplicationCorrectionRequest) {
            return $this->forTypeCorrection($application, $typeCorrection, $lockForUpdate);
        }
        if ($application->current_submission_version_id !== null) {
            $submissionQuery = $application->currentSubmissionVersion();

            if ($lockForUpdate) {
                $submissionQuery->lockForUpdate();
            }

            $submission = $submissionQuery->firstOrFail();
            $set = $submission->requirementSet()->firstOrFail();

            if ($set->application_path === $application->application_path) {
                return $set;
            }

            if (! $application->allowsSubmittedEdit()) {
                throw ValidationException::withMessages(['application_path' => 'Student type changes after review require a confirmed Registrar request for the target type. Keep the current type and contact the Registrar for correction guidance.']);
            }
        }

        return $this->forScope(
            (int) $application->admission_cycle_id,
            (string) $application->application_path,
            $lockForUpdate,
        );
    }

    public function forTypeCorrection(AdmissionApplication $application, ApplicationCorrectionRequest $request, bool $lockForUpdate = false): AdmissionRequirementSet
    {
        $query = AdmissionRequirementSet::query()->whereKey($request->target_requirement_set_id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $set = $query->first();
        if ($application->application_state !== AdmissionApplication::StateActionNeeded
            || $request->state !== ApplicationCorrectionRequest::StateActive
            || $request->admission_application_id !== $application->id
            || $request->source_submission_version_id !== $application->current_submission_version_id
            || $application->decisions()->exists()
            || ! $set instanceof AdmissionRequirementSet
            || $set->state !== AdmissionRequirementSet::StatePublished
            || $set->admission_cycle_id !== $application->admission_cycle_id
            || $set->application_path !== $request->target_application_path
            || $application->admissionCycle()->firstOrFail()->term_id !== $application->term_id) {
            throw ValidationException::withMessages(['correction_scope' => 'The confirmed Student type correction source is unavailable or changed. Keep your work and contact the Registrar; the prior submission is retained.']);
        }

        return $set;
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
