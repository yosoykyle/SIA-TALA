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

    public function execute(
        AdmissionApplication $application,
        User $applicant,
        ?User $actor = null,
    ): void {
        $actor ??= $applicant;

        if ($application->user_id !== $applicant->id
            || ! $applicant->hasRole('applicant')
            || ! $applicant->canAuthenticate()
            || ! ($actor->id === $applicant->id
                || ($actor->hasRole(User::StaffRoleRegistrar)
                    && $actor->canAuthenticate()
                    && $actor->can('approve-documents')))) {
            throw new AuthorizationException('Only the Applicant owner or an authorized Registrar assistant may discard this unsubmitted Draft.');
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

        $this->evidenceService->discardTemporaryEvidence($locked, $actor);

        if ($actor->id !== $applicant->id) {
            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('admission_assisted_draft_discarded')
                ->withProperties(['applicant_user_id' => $applicant->id])
                ->log('Registrar-assisted unsubmitted Application Draft discarded.');
        }

        DB::transaction(function () use ($locked): void {
            AdmissionApplication::query()->lockForUpdate()->findOrFail($locked->id)->delete();
        }, attempts: 3);
    }
}
