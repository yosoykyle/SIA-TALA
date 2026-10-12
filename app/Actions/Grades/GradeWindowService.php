<?php

namespace App\Actions\Grades;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\TermCalendarWindow;
use Illuminate\Support\Carbon;

class GradeWindowService
{
    public function __construct(private readonly CalendarPhaseGateService $calendar) {}

    public function isOpen(GradeRoster $roster, string $period, ?Carbon $at = null): bool
    {
        if (strtolower($period) !== LateGradeAuthorization::PeriodFinal) {
            return false;
        }

        $at = ($at ?? now())->copy()->utc();

        return $this->calendarWindowIsOpen($roster, $at)
            || $this->lateAuthorizationIsOpen($roster, $at);
    }

    public function finalizationClosed(GradeRoster $roster, ?Carbon $at = null): bool
    {
        $at ??= now();
        $termId = $roster->termOffering?->term_id;

        if ($termId === null) {
            return false;
        }

        $window = $this->calendar->configuredWindow($termId, TermCalendarWindow::TypeGradeEntry);

        if ($window !== null) {
            return $at->gt($this->calendar->windowBounds($window)['closes_at']);
        }

        return false;
    }

    private function calendarWindowIsOpen(GradeRoster $roster, Carbon $at): bool
    {
        $termId = $roster->termOffering?->term_id;

        if ($termId === null) {
            return false;
        }

        $window = $this->calendar->configuredWindow($termId, TermCalendarWindow::TypeGradeEntry);

        if ($window !== null) {
            $bounds = $this->calendar->windowBounds($window);

            return $at->betweenIncluded($bounds['opens_at'], $bounds['closes_at']);
        }

        return false;
    }

    private function lateAuthorizationIsOpen(GradeRoster $roster, Carbon $at): bool
    {
        return LateGradeAuthorization::query()
            ->where('grade_roster_id', $roster->id)
            ->where('term_offering_id', $roster->term_offering_id)
            ->where('faculty_user_id', $roster->faculty_user_id)
            ->where('grading_period', LateGradeAuthorization::PeriodFinal)
            ->where('state', LateGradeAuthorization::StateActive)
            ->where('opens_at', '<=', $at)
            ->where('closes_at', '>=', $at)
            ->exists();
    }
}
