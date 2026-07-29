<?php

namespace App\Actions\Applicants;

use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ApplicantDuplicateCandidateFinder
{
    /**
     * Find exact active official-record candidates for Registrar investigation.
     *
     * This is deliberately deterministic. It is a safety guard, not fuzzy
     * identity resolution and never merges records automatically.
     *
     * @return Collection<int, StudentProfile>
     */
    public function find(ApplicantIntake $intake): Collection
    {
        if ($intake->birth_date === null || blank($intake->first_name) || blank($intake->last_name)) {
            return new Collection;
        }

        return StudentProfile::query()
            ->whereNull('archived_at')
            ->whereNull('merged_into_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('applicant_intake_id')
                ->orWhere('applicant_intake_id', '!=', $intake->id))
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower(trim($intake->first_name))])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower(trim($intake->last_name))])
            ->whereDate('birth_date', $intake->birth_date)
            ->orderBy('student_number')
            ->get();
    }

    public function requiresNonReturningIdentityReview(ApplicantIntake $intake): bool
    {
        return $intake->status === ApplicantIntake::StatusApproved
            && $intake->handed_over_at === null
            && $intake->admission_category !== ApplicantIntake::AdmissionCategoryReturning
            && $this->find($intake)->isNotEmpty();
    }
}
