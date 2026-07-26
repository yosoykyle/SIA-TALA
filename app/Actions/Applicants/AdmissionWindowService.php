<?php

namespace App\Actions\Applicants;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\CalendarEvent;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdmissionWindowService
{
    public function hasOpenAdmissionsWindow(?CarbonImmutable $at = null): bool
    {
        return $this->openWindowQuery($at)->exists();
    }

    public function isAdmissionsWindowOpenForTerm(int $termId, ?CarbonImmutable $at = null): bool
    {
        return $this->openWindowQuery($at)
            ->where('term_id', $termId)
            ->exists();
    }

    public function admissionsWindow(int $termId, ?CarbonImmutable $at = null): CalendarEvent
    {
        $window = $this->openWindowQuery($at)
            ->where('term_id', $termId)
            ->orderBy('start_at')
            ->first();

        if ($window instanceof CalendarEvent) {
            return $window;
        }

        throw new CalendarGateViolation(
            'Applications are currently closed for the selected admission term.',
            'admissions_window',
            [
                'term_id' => $termId,
                'process_key' => CalendarEvent::ProcessAdmissions,
                'evaluated_at' => ($at ?? CarbonImmutable::now())->toIso8601String(),
            ],
        );
    }

    /**
     * @return Collection<int, int>
     */
    public function openTermIds(?CarbonImmutable $at = null): Collection
    {
        return $this->openWindowQuery($at)
            ->orderBy('term_id')
            ->pluck('term_id')
            ->map(fn (mixed $termId): int => (int) $termId)
            ->unique()
            ->values();
    }

    /**
     * @return Builder<CalendarEvent>
     */
    private function openWindowQuery(?CarbonImmutable $at = null): Builder
    {
        $evaluatedAt = $at ?? CarbonImmutable::now();

        return CalendarEvent::query()
            ->academicCalendarWindows()
            ->where('process_key', CalendarEvent::ProcessAdmissions)
            ->where('state', CalendarEvent::StateActive)
            ->where('start_at', '<=', $evaluatedAt)
            ->where('end_at', '>=', $evaluatedAt)
            ->whereHas('term', fn (Builder $query): Builder => $query
                ->where('state', Term::StateActive));
    }
}
