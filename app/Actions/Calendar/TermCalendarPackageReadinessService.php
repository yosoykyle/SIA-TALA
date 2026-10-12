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

        foreach ($package->windows as $window) {
            $opensOn = CarbonImmutable::parse((string) $window->opens_on);
            $closesOn = CarbonImmutable::parse((string) $window->closes_on);

            if (! array_key_exists($window->window_type, TermCalendarWindow::typeOptions())
                || $closesOn->lt($opensOn)
                || ($window->cutoff_at !== null && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string) $window->cutoff_at))) {
                $blockers[] = $this->blocker('operational_window_invalid', 'Operational windows', 'Registrar', 'An entered operational window has an unsupported type, contradictory dates or invalid cutoff.', 'Correct the affected dates against this exact Term\'s approved consumer period and calendar authority.', 'Retain the Draft package until every entered window is valid.');
                break;
            }
        }

        if ($package->teachingGridRows->isEmpty()) {
            $blockers[] = $this->blocker('teaching_grid_empty', 'Weekly teaching grid', 'Registrar', 'No approved teaching day is recorded.', 'Record each allowed teaching day and operating interval.', 'Correct the Draft package and retry activation.');
        }

        foreach ($package->teachingGridRows as $row) {
            $starts = strtotime((string) $row->starts_at);
            $ends = strtotime((string) $row->ends_at);

            if ($row->day_of_week < 1 || $row->day_of_week > 7 || $starts === false || $ends === false || $ends <= $starts || ($starts % 1800) !== 0 || ($ends % 1800) !== 0) {
                $blockers[] = $this->blocker('teaching_grid_invalid', 'Weekly teaching grid', 'Registrar', 'A teaching-grid row is contradictory or not aligned to the fixed 30-minute grid.', 'Correct the affected teaching day.', 'Retain the Draft package until the grid is valid.');
                break;
            }

            $breaks = is_array($row->breaks) ? $row->breaks : [];
            $breakIntervals = [];

            foreach ($breaks as $break) {
                if (! is_array($break) || blank($break['starts_at'] ?? null) || blank($break['ends_at'] ?? null)) {
                    $blockers[] = $this->blocker('teaching_grid_break_invalid', 'Recurring institutional breaks', 'Registrar', 'A recurring teaching-grid break is incomplete, contradictory, or not aligned to the fixed 30-minute grid.', 'Correct or remove the invalid break interval.', 'Retain the Draft package until all recurring breaks are valid.');
                    break 2;
                }

                $bStarts = strtotime((string) ($break['starts_at'] ?? ''));
                $bEnds = strtotime((string) ($break['ends_at'] ?? ''));

                if ($bStarts === false || $bEnds === false || $bEnds <= $bStarts || ($bStarts % 1800) !== 0 || ($bEnds % 1800) !== 0) {
                    $blockers[] = $this->blocker('teaching_grid_break_invalid', 'Recurring institutional breaks', 'Registrar', 'A recurring teaching-grid break is incomplete, contradictory, or not aligned to the fixed 30-minute grid.', 'Correct the affected break interval.', 'Retain the Draft package until all recurring breaks are valid.');
                    break 2;
                }

                if ($bStarts < $starts || $bEnds > $ends) {
                    $blockers[] = $this->blocker('teaching_grid_break_out_of_bounds', 'Recurring institutional breaks', 'Registrar', 'A recurring teaching-grid break falls outside the approved daily teaching interval.', 'Keep breaks within the daily teaching start and end times.', 'Retain the Draft package until all breaks fall within teaching hours.');
                    break 2;
                }

                foreach ($breakIntervals as $existing) {
                    if ($bStarts < $existing['ends'] && $bEnds > $existing['starts']) {
                        $blockers[] = $this->blocker('teaching_grid_break_overlap', 'Recurring institutional breaks', 'Registrar', 'Recurring teaching-grid breaks on the same day overlap each other.', 'Resolve overlapping break intervals on the affected day.', 'Retain the Draft package until breaks do not overlap.');
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
                $blockers[] = $this->blocker('dated_exception_invalid', 'Dated exceptions', 'Registrar', 'A dated exception has contradictory dates or falls outside the exact-Term administrative bounds.', 'Correct the affected dated exception interval.', 'Retain the Draft package until all dated exceptions are valid.');
                break;
            }

            if (blank($exception->authority_reference)) {
                $blockers[] = $this->blocker('dated_exception_authority_missing', 'Dated exceptions', 'Registrar', 'A dated exception is missing its attributable authority reference.', 'Record the authority reference for each dated exception.', 'Retain the Draft package until all exceptions record authority.');
                break;
            }

            $blocksTeaching = (bool) $exception->blocks_teaching;
            $normalizedType = strtolower(str_replace([' ', '_', '-'], '', (string) $exception->exception_type));

            if (($normalizedType === 'informationonly' && $blocksTeaching)
                || ($normalizedType === 'noclasses' && ! $blocksTeaching)
                || ($normalizedType === 'makeupallowed' && $blocksTeaching)) {
                $blockers[] = $this->blocker('dated_exception_effect_invalid', 'Dated exceptions', 'Registrar', 'A dated exception contradicts its recorded instructional effect.', 'Correct the NoClasses, MakeUpAllowed or InformationOnly effect against the approved source.', 'Retain the Draft package until the exception is consistent.');
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
                        $blockers[] = $this->blocker('dated_exception_conflict', 'Dated exceptions', 'Registrar', 'Dated exceptions have conflicting instructional effects or dates.', 'Resolve the conflicting dated exception intervals.', 'Retain the Draft package until dated exceptions do not conflict.');
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
            $blockers[] = $this->blocker('special_term_basis_missing', 'Special Term authority', 'Registrar', 'The approved particular schedule and class-hour/class-day basis are missing.', 'Record the attributable Special Term schedule basis.', 'Do not infer a Special Term default; correct the Draft package.');
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }

    /** @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string} */
    private function blocker(string $code, string $source, string $owner, string $reason, string $nextAction, string $recovery): array
    {
        return compact('code', 'source', 'owner', 'reason') + ['next_action' => $nextAction, 'recovery' => $recovery];
    }
}
