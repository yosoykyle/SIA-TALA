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
        ?string $authorityReference,
        ?Carbon $authorityDate,
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

        $authorityReference = filled($authorityReference) ? trim((string) $authorityReference) : null;
        $authorityReference = $authorityReference !== '' ? $authorityReference : null;
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Academic decisions require a safe explanation.');
        }

        if ($effectiveUntil?->isBefore($effectiveFrom)) {
            throw new RuntimeException('The decision end date cannot precede its start date.');
        }

        return DB::transaction(function () use ($student, $term, $effect, $authorityReference, $authorityDate, $reason, $effectiveFrom, $effectiveUntil, $actor): AcademicDecision {
            $lockedStudent = StudentProfile::query()->lockForUpdate()->findOrFail($student->id);

            $priorActiveEffects = AcademicDecision::query()
                ->where('student_profile_id', $student->id)
                ->where('term_id', $term?->id)
                ->where('state', AcademicDecision::StateActive)
                ->pluck('effect')
                ->all();

            AcademicDecision::query()
                ->where('student_profile_id', $student->id)
                ->where('term_id', $term?->id)
                ->where('state', AcademicDecision::StateActive)
                ->update(['state' => AcademicDecision::StateSuperseded]);

            $decision = AcademicDecision::query()->create([
                'student_profile_id' => $student->id,
                'term_id' => $term?->id,
                'effect' => $effect,
                'authority_reference' => $authorityReference,
                'authority_date' => $authorityDate,
                'reason' => $reason,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'state' => AcademicDecision::StateActive,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $this->syncStudentStanding($lockedStudent, $decision, $priorActiveEffects);

            return $decision;
        }, attempts: 3);
    }

    /** @param list<string> $priorActiveEffects */
    private function syncStudentStanding(StudentProfile $student, AcademicDecision $decision, array $priorActiveEffects): void
    {
        $schoolDate = today('Asia/Manila')->toDateString();
        if ($decision->effective_from->toDateString() > $schoolDate
            || ($decision->effective_until !== null && $decision->effective_until->toDateString() < $schoolDate)) {
            return;
        }

        $targetStanding = null;

        if ($decision->effect === AcademicDecision::EffectBlocked) {
            $restrictive = [
                StudentProfile::StandingMustRepeatYear,
                StudentProfile::StandingProbationary,
                StudentProfile::StandingDeficient,
                StudentProfile::StandingBlockedByPrerequisite,
            ];
            if ($decision->term_id === null || ! in_array($student->academic_standing, $restrictive, true)) {
                $targetStanding = StudentProfile::StandingMustRepeatYear;
            }
        } elseif ($decision->effect === AcademicDecision::EffectAdvisingRequired) {
            $advisedStandings = [
                StudentProfile::StandingProbationary,
                StudentProfile::StandingMustRepeatYear,
                StudentProfile::StandingDeficient,
                StudentProfile::StandingBlockedByPrerequisite,
                StudentProfile::StandingIrregular,
            ];
            if (in_array(AcademicDecision::EffectBlocked, $priorActiveEffects, true)
                || $student->academic_standing === StudentProfile::StandingMustRepeatYear
                || ! in_array($student->academic_standing, $advisedStandings, true)) {
                $targetStanding = StudentProfile::StandingProbationary;
            }
        } elseif ($decision->effect === AcademicDecision::EffectAllowed) {
            $hasRemainingRestriction = AcademicDecision::query()
                ->where('student_profile_id', $student->id)
                ->whereKeyNot($decision->id)
                ->where('state', AcademicDecision::StateActive)
                ->whereDate('effective_from', '<=', $schoolDate)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $schoolDate))
                ->whereIn('effect', [
                    AcademicDecision::EffectBlocked,
                    AcademicDecision::EffectPendingDecision,
                    AcademicDecision::EffectAdvisingRequired,
                ])
                ->exists();

            $clearableStandings = [
                StudentProfile::StandingProbationary,
                StudentProfile::StandingMustRepeatYear,
                StudentProfile::StandingDeficient,
                StudentProfile::StandingBlockedByPrerequisite,
            ];
            if (! $hasRemainingRestriction && in_array($student->academic_standing, $clearableStandings, true)) {
                $targetStanding = StudentProfile::StandingRegular;
            }
        }

        if ($targetStanding !== null && $student->academic_standing !== $targetStanding) {
            $student->update(['academic_standing' => $targetStanding]);
        }
    }
}
