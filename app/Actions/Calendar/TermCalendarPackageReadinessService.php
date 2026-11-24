<?php

namespace App\Actions\Calendar;

use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
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

        $administrativeStartsOn = CarbonImmutable::parse((string) $package->administrative_starts_on);
        $administrativeEndsOn = CarbonImmutable::parse((string) $package->administrative_ends_on);
        $classesStartOn = CarbonImmutable::parse((string) $package->classes_start_on);
        $classesEndOn = CarbonImmutable::parse((string) $package->classes_end_on);

        if ($administrativeEndsOn->lt($administrativeStartsOn)
            || $classesEndOn->lt($classesStartOn)
            || $classesStartOn->lt($administrativeStartsOn)
            || $classesEndOn->gt($administrativeEndsOn)) {
            $blockers[] = $this->blocker('calendar_bounds_invalid', 'Term calendar', 'Registrar', 'Term dates and class dates overlap or fall out of order.', 'Adjust the term start/end and class dates so classes stay within the term.', 'Save the corrected dates on the draft calendar before activating.');
        }

        foreach ($package->windows as $window) {
            $opensOn = CarbonImmutable::parse((string) $window->opens_on);
            $closesOn = CarbonImmutable::parse((string) $window->closes_on);

            if (! array_key_exists($window->window_type, TermCalendarWindow::typeOptions())
                || $closesOn->lt($opensOn)
                || ($window->cutoff_at !== null && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string) $window->cutoff_at))) {
                $blockers[] = $this->blocker('operational_window_invalid', 'Key dates', 'Registrar', 'A key date period has an unsupported type, invalid date range, or invalid cutoff time.', 'Fix the start date, end date, or cutoff time so it fits this term.', 'Update the draft calendar until every key date period is valid.');
                break;
            }
        }

        if ($package->teachingGridRows->isEmpty()) {
            $blockers[] = $this->blocker('teaching_grid_empty', 'Weekly teaching hours', 'Registrar', 'No school teaching days are set.', 'Add at least one teaching day with start and end hours.', 'Update the draft calendar and try activating again.');
        }

        foreach ($package->teachingGridRows as $row) {
            $starts = strtotime((string) $row->starts_at);
            $ends = strtotime((string) $row->ends_at);

            if ($row->day_of_week < 1 || $row->day_of_week > 7 || $starts === false || $ends === false || $ends <= $starts || ($starts % 1800) !== 0 || ($ends % 1800) !== 0) {
                $blockers[] = $this->blocker('teaching_grid_invalid', 'Weekly teaching hours', 'Registrar', 'A teaching day has invalid hours or times that are not on 30-minute intervals.', 'Fix the start and end times for the affected day.', 'Update the draft calendar until weekly teaching hours are valid.');
                break;
            }

            $breaks = is_array($row->breaks) ? $row->breaks : [];
            $breakIntervals = [];

            foreach ($breaks as $break) {
                if (! is_array($break) || blank($break['starts_at'] ?? null) || blank($break['ends_at'] ?? null)) {
                    $blockers[] = $this->blocker('teaching_grid_break_invalid', 'Scheduled breaks', 'Registrar', 'A scheduled break is incomplete, out of order, or not on 30-minute intervals.', 'Fix or remove the invalid break.', 'Update the draft calendar until all breaks are valid.');
                    break 2;
                }

                $bStarts = strtotime((string) ($break['starts_at'] ?? ''));
                $bEnds = strtotime((string) ($break['ends_at'] ?? ''));

                if ($bStarts === false || $bEnds === false || $bEnds <= $bStarts || ($bStarts % 1800) !== 0 || ($bEnds % 1800) !== 0) {
                    $blockers[] = $this->blocker('teaching_grid_break_invalid', 'Scheduled breaks', 'Registrar', 'A scheduled break is incomplete, out of order, or not on 30-minute intervals.', 'Fix the affected break times.', 'Update the draft calendar until all breaks are valid.');
                    break 2;
                }

                if ($bStarts < $starts || $bEnds > $ends) {
                    $blockers[] = $this->blocker('teaching_grid_break_out_of_bounds', 'Scheduled breaks', 'Registrar', 'A scheduled break falls outside the daily teaching hours.', 'Keep breaks within the daily start and end times.', 'Update the draft calendar until all breaks fall within teaching hours.');
                    break 2;
                }

                foreach ($breakIntervals as $existing) {
                    if ($bStarts < $existing['ends'] && $bEnds > $existing['starts']) {
                        $blockers[] = $this->blocker('teaching_grid_break_overlap', 'Scheduled breaks', 'Registrar', 'Scheduled breaks on the same day overlap each other.', 'Adjust the overlapping breaks on the affected day.', 'Update the draft calendar until breaks do not overlap.');
                        break 3;
                    }
                }

                $breakIntervals[] = ['starts' => $bStarts, 'ends' => $bEnds];
            }
        }

        $exceptionsList = [];
        foreach ($package->datedExceptions as $exception) {
            $exStarts = CarbonImmutable::parse((string) $exception->starts_on);
            $exEnds = CarbonImmutable::parse((string) $exception->ends_on);

            if ($exEnds->lt($exStarts) || $exStarts->lt($administrativeStartsOn) || $exEnds->gt($administrativeEndsOn)) {
                $blockers[] = $this->blocker('dated_exception_invalid', 'Holidays & special dates', 'Registrar', 'A holiday or special date has an invalid date range or falls outside the term dates.', 'Fix the start and end dates for the affected entry.', 'Update the draft calendar until all holidays and special dates are valid.');
                break;
            }

            $blocksTeaching = (bool) $exception->blocks_teaching;
            $normalizedType = strtolower(str_replace([' ', '_', '-'], '', (string) $exception->exception_type));

            if (($normalizedType === 'informationonly' && $blocksTeaching)
                || ($normalizedType === 'noclasses' && ! $blocksTeaching)
                || ($normalizedType === 'makeupallowed' && $blocksTeaching)) {
                $blockers[] = $this->blocker('dated_exception_effect_invalid', 'Holidays & special dates', 'Registrar', 'A holiday or special date has a mismatched class-suspension setting.', 'Set no-class days to suspend classes, and keep make-up days or notices open for classes.', 'Update the draft calendar until each entry is consistent.');
                break;
            }

            foreach ($exceptionsList as $existingEx) {
                if ($normalizedType === 'informationonly' || $existingEx['type'] === 'informationonly') {
                    continue;
                }
                if ($exStarts->lte($existingEx['ends']) && $exEnds->gte($existingEx['starts'])) {
                    $isConflict = false;

                    // 1. Contradictory teaching block effect
                    if ($blocksTeaching !== $existingEx['blocks_teaching']) {
                        $isConflict = true;
                    }

                    // 2. Contradictory exception types (e.g. no-class / holiday / suspension vs make-up / class-day)
                    $isNoClassA = in_array($normalizedType, ['holiday', 'noclass', 'noclasses', 'suspension', 'closure', 'break'], true);
                    $isNoClassB = in_array($existingEx['type'], ['holiday', 'noclass', 'noclasses', 'suspension', 'closure', 'break'], true);

                    $isTeachingA = in_array($normalizedType, ['makeup', 'makeupallowed', 'makeupday', 'classday', 'teachingday'], true);
                    $isTeachingB = in_array($existingEx['type'], ['makeup', 'makeupallowed', 'makeupday', 'classday', 'teachingday'], true);

                    if (($isNoClassA && $isTeachingB) || ($isTeachingA && $isNoClassB)) {
                        $isConflict = true;
                    }

                    if ($isConflict) {
                        $blockers[] = $this->blocker('dated_exception_conflict', 'Holidays & special dates', 'Registrar', 'Two holidays or special dates overlap with conflicting class-day rules.', 'Adjust the overlapping dates so they do not conflict.', 'Update the draft calendar until holidays and special dates do not conflict.');
                        break 2;
                    }
                }
            }

            $exceptionsList[] = [
                'starts' => $exStarts,
                'ends' => $exEnds,
                'blocks_teaching' => $blocksTeaching,
                'type' => $normalizedType,
            ];
        }

        if ($package->term->type === Term::TypeSpecialTerm && blank($package->special_term_schedule_basis)) {
            $blockers[] = $this->blocker('special_term_basis_missing', 'Special term schedule', 'Registrar', 'The approved schedule basis for this special term is missing.', 'Enter the approved class-hour and class-day basis for this special term.', 'Add the special term schedule basis on the draft calendar before activating.');
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }

    /** @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string} */
    private function blocker(string $code, string $source, string $owner, string $reason, string $nextAction, string $recovery): array
    {
        return compact('code', 'source', 'owner', 'reason') + ['next_action' => $nextAction, 'recovery' => $recovery];
    }
}
