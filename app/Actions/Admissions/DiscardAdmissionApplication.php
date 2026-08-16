<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscardAdmissionApplication
{
    public function __construct(private readonly AdmissionEvidenceService $evidenceService) {}

    public function execute(AdmissionApplication $application, User $applicant): void
    {
        if ($application->user_id !== $applicant->id
            || ! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()) {
            throw new AuthorizationException('Applicants may discard only their own draft.');
        }

        $locked = DB::transaction(function () use ($application): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($locked->application_state !== AdmissionApplication::StateDraft
                || $locked->current_submission_version_id !== null
                || $locked->submissionVersions()->exists()) {
                throw ValidationException::withMessages([
                    'application_state' => 'Only an unsubmitted Draft application can be discarded.',
                ]);
            }

            return $locked;
        }, attempts: 3);

        $this->evidenceService->discardTemporaryEvidence($locked, $applicant);

        DB::transaction(function () use ($locked): void {
            AdmissionApplication::query()->lockForUpdate()->findOrFail($locked->id)->delete();
        }, attempts: 3);
    }
}
