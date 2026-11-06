<?php

namespace App\Actions\Grades;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\TermCalendarWindow;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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

    /** @return array{is_open: bool, basis: string, opens_at: ?CarbonInterface, closes_at: ?CarbonInterface, reference: ?string} */
    public function entryContext(GradeRoster $roster, ?Carbon $at = null): array
    {
        $at = ($at ?? now())->copy()->utc();
        $window = $roster->termOffering?->term_id
            ? $this->calendar->configuredWindow($roster->termOffering->term_id, TermCalendarWindow::TypeGradeEntry)
            : null;
        $bounds = $window ? $this->calendar->windowBounds($window) : null;

        if ($bounds && $at->betweenIncluded($bounds['opens_at'], $bounds['closes_at'])) {
            return ['is_open' => true, 'basis' => 'Grade Entry window', ...$bounds, 'reference' => $window->package?->authority_reference];
        }

        $late = $this->activeLateAuthorization($roster, $at);
        if ($late) {
            return ['is_open' => true, 'basis' => 'Authorized late grade entry', 'opens_at' => $late->opens_at, 'closes_at' => $late->closes_at, 'reference' => null];
        }

        return ['is_open' => false, 'basis' => $window ? 'Grade Entry window closed' : 'Grade Entry dates not configured', 'opens_at' => $bounds['opens_at'] ?? null, 'closes_at' => $bounds['closes_at'] ?? null, 'reference' => $window?->package?->authority_reference];
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
        return $this->activeLateAuthorization($roster, $at) !== null;
    }

    private function activeLateAuthorization(GradeRoster $roster, Carbon $at): ?LateGradeAuthorization
    {
        return LateGradeAuthorization::query()
            ->where('grade_roster_id', $roster->id)
            ->where('term_offering_id', $roster->term_offering_id)
            ->where('faculty_user_id', $roster->faculty_user_id)
            ->where('grading_period', LateGradeAuthorization::PeriodFinal)
            ->where('state', LateGradeAuthorization::StateActive)
            ->whereHas('approver.roles', fn (Builder $query): Builder => $query
                ->where('name', User::StaffRoleRegistrar)
                ->where('guard_name', 'web'))
            ->where('opens_at', '<=', $at)
            ->where('closes_at', '>=', $at)
            ->latest('id')->first();
    }
}
