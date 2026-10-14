<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\ApplicationSubmissionVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdmissionReviewGuard
{
    public function start(AdmissionApplication $application, User $actor, ?int $expectedSubmissionVersionId): AdmissionApplication
    {
        return DB::transaction(function () use ($application, $actor, $expectedSubmissionVersionId): AdmissionApplication {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $actor = $actor->fresh();
            if (! $actor instanceof User) {
                throw new AuthorizationException('The Registrar account is unavailable.');
            }
            if (! $actor->hasRole(User::StaffRoleRegistrar) || ! $actor->canAuthenticate() || ! $actor->can('approve-documents')) {
                throw new AuthorizationException('Only an authorized Registrar may start review.');
            }
            if ($locked->application_state !== AdmissionApplication::StateSubmitted
                || $locked->current_submission_version_id === null
                || $locked->current_submission_version_id !== $expectedSubmissionVersionId) {
                throw ValidationException::withMessages(['review' => 'The application changed. Reload and inspect the current submitted version before starting review.']);
            }
            $source = $locked->currentSubmissionVersion()->firstOrFail();
            if ($source->admission_application_id !== $locked->id || $source->requirementSet->admission_cycle_id !== $locked->admission_cycle_id
                || $source->requirementSet->application_path !== $locked->application_path || $locked->admissionCycle->term_id !== $locked->term_id) {
                throw ValidationException::withMessages(['review' => 'The submitted source and intake disagree. Restore the source before starting review.']);
            }
            if (! $locked->reviewHasStarted()) {
                $locked->events()->create([
                    'event_type' => AdmissionApplicationEvent::TypeReviewStarted,
                    'event_key' => 'admission-review-start:'.$locked->id,
                    'actor_id' => $actor->id,
                    'source_type' => ApplicationSubmissionVersion::class,
                    'source_id' => $locked->current_submission_version_id,
                    'payload' => ['submission_version_id' => $locked->current_submission_version_id],
                    'occurred_at' => now(),
                ]);
            }

            return $locked;
        }, attempts: 3);
    }
}
