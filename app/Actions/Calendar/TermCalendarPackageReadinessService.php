<?php

namespace App\Actions\Calendar;

use App\Models\Term;
use App\Models\TermCalendarPackage;
use Carbon\CarbonImmutable;

final class TermCalendarPackageReadinessService
{
    /**
     * @return array{ready: bool, blockers: list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>}
     */
    public function for(TermCalendarPackage $package): array
    {
        $package->loadMissing(['term', 'windows', 'teachingGridRows', 'datedExceptions']);
        $blockers = [];

        if (blank($package->authority_reference) || blank($package->authority_date)) {
            $blockers[] = $this->blocker('calendar_authority_missing', 'Term Calendar Package', 'Registrar', 'The external calendar authority is incomplete.', 'Record its approval reference and date.', 'Correct the Draft package and retry activation.');
        }

        $administrativeStartsOn = CarbonImmutable::parse((string) $package->administrative_starts_on);
        $administrativeEndsOn = CarbonImmutable::parse((string) $package->administrative_ends_on);
        $classesStartOn = CarbonImmutable::parse((string) $package->classes_start_on);
        $classesEndOn = CarbonImmutable::parse((string) $package->classes_end_on);

        if ($administrativeEndsOn->lt($administrativeStartsOn)
            || $classesEndOn->lt($classesStartOn)
            || $classesStartOn->lt($administrativeStartsOn)
            || $classesEndOn->gt($administrativeEndsOn)) {
            $blockers[] = $this->blocker('calendar_bounds_invalid', 'Term Calendar Package', 'Registrar', 'Administrative and class dates are contradictory.', 'Correct the inclusive date bounds.', 'Retain the Draft package until all date bounds are valid.');
        }

        foreach (['Enrollment', 'ExaminationPeriod', 'GradeEntry'] as $windowType) {
            $window = $package->windows->firstWhere('window_type', $windowType);

            if ($window === null
                || CarbonImmutable::parse((string) $window->closes_on)
                    ->lt(CarbonImmutable::parse((string) $window->opens_on))) {
                $blockers[] = $this->blocker('window_'.strtolower($windowType).'_invalid', 'Operational windows', 'Registrar', "The {$windowType} window is missing or invalid.", "Record a valid {$windowType} window.", 'Correct the Draft package and retry activation.');
            }
        }

        if ($package->teachingGridRows->isEmpty()) {
            $blockers[] = $this->blocker('teaching_grid_empty', 'Weekly teaching grid', 'Registrar', 'No approved teaching day is recorded.', 'Record each allowed teaching day and operating interval.', 'Correct the Draft package and retry activation.');
        }

        foreach ($package->teachingGridRows as $row) {
            $starts = strtotime((string) $row->starts_at);
            $ends = strtotime((string) $row->ends_at);

            if ($starts === false || $ends === false || $ends <= $starts || ($starts % 1800) !== 0 || ($ends % 1800) !== 0) {
                $blockers[] = $this->blocker('teaching_grid_invalid', 'Weekly teaching grid', 'Registrar', 'A teaching-grid row is contradictory or not aligned to the fixed 30-minute grid.', 'Correct the affected teaching day.', 'Retain the Draft package until the grid is valid.');
                break;
            }
        }

        if ($package->term->type === Term::TypeSummer && blank($package->special_term_schedule_basis)) {
            $blockers[] = $this->blocker('special_term_basis_missing', 'Special Term authority', 'Registrar', 'The approved particular schedule and class-hour/class-day basis are missing.', 'Record the attributable Special Term schedule basis.', 'Do not infer a Summer default; correct the Draft package.');
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }

    /** @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string} */
    private function blocker(string $code, string $source, string $owner, string $reason, string $nextAction, string $recovery): array
    {
        return compact('code', 'source', 'owner', 'reason') + ['next_action' => $nextAction, 'recovery' => $recovery];
    }
}
