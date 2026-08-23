<?php

namespace App\Actions\Completion;

use App\Models\GraduationApplication;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectGraduationApplication
{
    public function execute(GraduationApplication $application, User $actor, string $authorityReference, string $reason): GraduationApplication
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may record a graduation-application correction.');
        }
        if (blank(trim($authorityReference)) || blank(trim($reason))) {
            throw ValidationException::withMessages(['correction' => 'Correction authority and reason are required.']);
        }

        return DB::transaction(function () use ($application, $authorityReference, $reason): GraduationApplication {
            $locked = GraduationApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->state !== GraduationApplication::StateActive) {
                throw ValidationException::withMessages(['correction' => 'Only the current active application may be corrected.']);
            }
            $locked->update(['state' => GraduationApplication::StateCorrected, 'active_scope_key' => null]);

            return GraduationApplication::query()->create([
                'student_profile_id' => $locked->student_profile_id,
                'curriculum_version_id' => $locked->curriculum_version_id,
                'term_id' => $locked->term_id,
                'version' => $locked->version + 1,
                'supersedes_application_id' => $locked->id,
                'state' => GraduationApplication::StateActive,
                'active_scope_key' => "{$locked->student_profile_id}:{$locked->curriculum_version_id}",
                'source_fingerprint' => $locked->source_fingerprint,
                'applied_by' => $locked->applied_by,
                'applied_at' => $locked->applied_at,
                'correction_authority_reference' => trim($authorityReference),
                'correction_reason' => trim($reason),
            ]);
        }, attempts: 3);
    }
}
