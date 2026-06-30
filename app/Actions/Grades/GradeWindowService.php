<?php

namespace App\Actions\Grades;

use App\Models\CalendarEvent;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class GradeWindowService
{
    public function isOpen(GradeRoster $roster, string $period, ?Carbon $at = null): bool
    {
        $at ??= now();
        $period = strtolower($period);

        return $this->calendarWindowIsOpen($roster, $period, $at)
            || $this->lateAuthorizationIsOpen($roster, $period, $at);
    }

    public function finalizationClosed(GradeRoster $roster, ?Carbon $at = null): bool
    {
        $at ??= now();
        $termId = $roster->termOffering?->term_id;

        if ($termId === null) {
            return false;
        }

        return CalendarEvent::query()
            ->where('term_id', $termId)
            ->where('process_key', 'grade_finalization')
            ->where('state', CalendarEvent::StateActive)
            ->where('end_at', '<', $at)
            ->exists();
    }

    private function calendarWindowIsOpen(GradeRoster $roster, string $period, Carbon $at): bool
    {
        $termId = $roster->termOffering?->term_id;

        if ($termId === null) {
            return false;
        }

        return CalendarEvent::query()
            ->where('term_id', $termId)
            ->where('process_key', "grade_encoding_$period")
            ->where('state', CalendarEvent::StateActive)
            ->where(function (Builder $query) use ($at): void {
                $query->where('start_at', '<=', $at)
                    ->where('end_at', '>=', $at);
            })
            ->exists();
    }

    private function lateAuthorizationIsOpen(GradeRoster $roster, string $period, Carbon $at): bool
    {
        return LateGradeAuthorization::query()
            ->where('grade_roster_id', $roster->id)
            ->where('term_offering_id', $roster->term_offering_id)
            ->where('faculty_user_id', $roster->faculty_user_id)
            ->where('grading_period', $period)
            ->where('state', LateGradeAuthorization::StateActive)
            ->where('opens_at', '<=', $at)
            ->where('closes_at', '>=', $at)
            ->exists();
    }
}
