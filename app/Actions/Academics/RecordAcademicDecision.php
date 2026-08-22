<?php

namespace App\Actions\Academics;

use App\Models\AcademicDecision;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecordAcademicDecision
{
    public function execute(
        StudentProfile $student,
        ?Term $term,
        string $effect,
        string $authorityReference,
        Carbon $authorityDate,
        string $reason,
        Carbon $effectiveFrom,
        ?Carbon $effectiveUntil,
        User $actor,
    ): AcademicDecision {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can record an academic decision.');
        }

        if (! array_key_exists($effect, AcademicDecision::effectOptions())) {
            throw new RuntimeException('Select one accepted academic enrollment effect.');
        }

        $authorityReference = trim($authorityReference);
        $reason = trim($reason);

        if ($authorityReference === '' || $reason === '') {
            throw new RuntimeException('Academic decisions require authority and a safe explanation.');
        }

        if ($effectiveUntil?->isBefore($effectiveFrom)) {
            throw new RuntimeException('The decision end date cannot precede its start date.');
        }

        return DB::transaction(function () use ($student, $term, $effect, $authorityReference, $authorityDate, $reason, $effectiveFrom, $effectiveUntil, $actor): AcademicDecision {
            AcademicDecision::query()
                ->where('student_profile_id', $student->id)
                ->where('state', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('term_id')->orWhere('term_id', $term?->id))
                ->lockForUpdate()
                ->update(['state' => 'SUPERSEDED']);

            return AcademicDecision::query()->create([
                'student_profile_id' => $student->id,
                'term_id' => $term?->id,
                'effect' => $effect,
                'authority_reference' => $authorityReference,
                'authority_date' => $authorityDate,
                'reason' => $reason,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'state' => 'ACTIVE',
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }
}
