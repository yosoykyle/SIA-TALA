<?php

namespace App\Actions\Grades;

use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AuthorizeLateGradeEncoding
{
    public function execute(
        GradeRoster $roster,
        string $period,
        Carbon $opensAt,
        Carbon $closesAt,
        string $reason,
        User $approver,
    ): LateGradeAuthorization {
        if (! $approver->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])) {
            throw new AuthorizationException('Only Registrar or Academic Head staff can authorize late grade encoding.');
        }

        $period = strtolower($period);

        if (! in_array($period, [
            LateGradeAuthorization::PeriodPrelim,
            LateGradeAuthorization::PeriodMidterm,
            LateGradeAuthorization::PeriodFinal,
        ], true)) {
            throw new RuntimeException('Invalid grading period.');
        }

        if (! in_array($roster->state, [GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true)) {
            throw new RuntimeException('Late grade authorization is only available for returned or late-not-submitted rosters.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Late grade authorization reason is required.');
        }

        if ($opensAt->greaterThanOrEqualTo($closesAt)) {
            throw new RuntimeException('Late grade authorization close time must be after open time.');
        }

        return DB::transaction(function () use ($roster, $period, $opensAt, $closesAt, $reason, $approver): LateGradeAuthorization {
            $activeOverlap = LateGradeAuthorization::query()
                ->where('grade_roster_id', $roster->id)
                ->where('term_offering_id', $roster->term_offering_id)
                ->where('faculty_user_id', $roster->faculty_user_id)
                ->where('grading_period', $period)
                ->where('state', LateGradeAuthorization::StateActive)
                ->where('opens_at', '<', $closesAt)
                ->where('closes_at', '>', $opensAt)
                ->lockForUpdate()
                ->exists();

            if ($activeOverlap) {
                throw new RuntimeException('An active overlapping late grade authorization already exists.');
            }

            return LateGradeAuthorization::query()->create([
                'grade_roster_id' => $roster->id,
                'term_offering_id' => $roster->term_offering_id,
                'faculty_user_id' => $roster->faculty_user_id,
                'grading_period' => $period,
                'reason' => $reason,
                'approved_by' => $approver->id,
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
                'state' => LateGradeAuthorization::StateActive,
            ]);
        });
    }
}
