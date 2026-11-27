<?php

namespace App\Actions\Scheduling;

use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class FacultyTermWorkloadProjection
{
    /**
     * Build a reusable, query-efficient term context for workload previews and availability target checks.
     *
     * @param  Collection<int, Collection<int, FacultyQualification>>|null  $preloadedQualificationsByFaculty
     * @param  Collection<int, FacultyTermLoadOverride>|null  $preloadedOverridesByFaculty
     * @param  Collection<int, FacultyAvailabilityDeclaration>|null  $preloadedDeclarationsByFaculty
     * @param  array<int, int|string>|null  $preloadedActiveFacultyIds
     * @return array{
     *     term_id: int,
     *     default_max_units: float,
     *     active_package: TermCalendarPackage|null,
     *     school_weekly_minutes: int|null,
     *     school_longest_block_minutes: int|null,
     *     sections: list<array{
     *         section_id: int,
     *         course_id: int,
     *         course_code: string,
     *         units: float,
     *         weekly_minutes: int,
     *         longest_meeting_minutes: int,
     *         confirmed: bool,
     *         fixed_faculty_user_id: int|null
     *     }>,
     *     active_qualifications_by_course: array<int, list<int>>,
     *     overrides_by_faculty: Collection<int, FacultyTermLoadOverride>,
     *     declarations_by_faculty: Collection<int, FacultyAvailabilityDeclaration>,
     *     excluded_faculty_ids: array<int, true>
     * }
     */
    public function buildTermContext(
        Term $term,
        bool $confirmedOnly = false,
        ?TermCalendarPackage $preloadedPackage = null,
        ?Collection $preloadedQualificationsByFaculty = null,
        ?Collection $preloadedOverridesByFaculty = null,
        ?Collection $preloadedDeclarationsByFaculty = null,
        ?array $preloadedActiveFacultyIds = null,
    ): array {
        $activePackage = $preloadedPackage instanceof TermCalendarPackage
            ? $preloadedPackage
            : TermCalendarPackage::query()
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateActive)
                ->with('teachingGridRows')
                ->first();

        $schoolMetrics = $activePackage instanceof TermCalendarPackage
            ? self::usableMinutesFromGrid($activePackage)
            : null;

        $sectionsQuery = Section::query()
            ->whereHas('termOffering', fn (Builder $query): Builder => $query
                ->where('term_id', $term->id)
                ->where('state', '!=', TermOffering::StateCancelled))
            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
            ->with([
                'courseRevision.course',
                'courseRevision.components',
                'termOffering.curriculumEntry.courseSpecification.course',
                'termOffering.curriculumEntry.courseSpecification.components',
                'deliveryGroups' => fn ($query) => $query
                    ->whereNotIn('state', [SectionDeliveryGroup::StateCancelled, SectionDeliveryGroup::StateClosed])
                    ->with('schedulingDemands'),
            ]);

        if ($confirmedOnly) {
            $sectionsQuery->whereNotNull('confirmed_at');
        }

        $sections = [];
        foreach ($sectionsQuery->get() as $section) {
            $spec = $section->courseRevision ?? $section->termOffering?->curriculumEntry?->courseSpecification;
            if (! $spec instanceof CourseSpecification || $spec->course_id === null) {
                continue;
            }
            if ($spec->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged) {
                continue;
            }

            [$weeklyMinutes, $longestMeetingMinutes, $fixedFacultyId] = $this->extractSectionMinutesAndPin($section, $spec);

            $sections[] = [
                'section_id' => (int) $section->id,
                'course_id' => (int) $spec->course_id,
                'course_code' => (string) ($spec->course?->code ?? $section->code),
                'units' => max(0.0, (float) $spec->credit_units),
                'weekly_minutes' => $weeklyMinutes,
                'longest_meeting_minutes' => $longestMeetingMinutes,
                'confirmed' => $section->confirmed_at !== null,
                'fixed_faculty_user_id' => $fixedFacultyId,
            ];
        }

        $activeFacultyIds = $preloadedActiveFacultyIds !== null
            ? array_values(array_map(static fn (mixed $id): int => (int) $id, $preloadedActiveFacultyIds))
            : User::query()
                ->where('status', User::StatusActive)
                ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', User::StaffRoleFaculty))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

        $activeQualificationsByCourse = [];
        if ($activeFacultyIds !== []) {
            if ($preloadedQualificationsByFaculty instanceof Collection) {
                foreach ($activeFacultyIds as $facultyId) {
                    foreach ($preloadedQualificationsByFaculty->get($facultyId, collect()) as $row) {
                        $courseId = (int) $row->course_id;
                        $activeQualificationsByCourse[$courseId][$facultyId] = $facultyId;
                    }
                }
            } else {
                $qualRows = FacultyQualification::query()
                    ->active()
                    ->whereIn('faculty_user_id', $activeFacultyIds)
                    ->get(['course_id', 'faculty_user_id']);

                foreach ($qualRows as $row) {
                    $courseId = (int) $row->course_id;
                    $facultyId = (int) $row->faculty_user_id;
                    $activeQualificationsByCourse[$courseId][$facultyId] = $facultyId;
                }
            }

            foreach ($activeQualificationsByCourse as $courseId => $facultyMap) {
                $activeQualificationsByCourse[$courseId] = array_values($facultyMap);
            }
        }

        $overridesByFaculty = $preloadedOverridesByFaculty instanceof Collection
            ? $preloadedOverridesByFaculty->keyBy(static fn (FacultyTermLoadOverride $override): int => (int) $override->faculty_user_id)
            : FacultyTermLoadOverride::query()
                ->where('term_id', $term->id)
                ->where('is_active', true)
                ->get()
                ->keyBy(static fn (FacultyTermLoadOverride $override): int => (int) $override->faculty_user_id);

        $declarationsByFaculty = $preloadedDeclarationsByFaculty instanceof Collection
            ? $preloadedDeclarationsByFaculty->keyBy(static fn (FacultyAvailabilityDeclaration $declaration): int => (int) $declaration->faculty_user_id)
            : FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->orderByDesc('version')
                ->get()
                ->unique('faculty_user_id')
                ->keyBy(static fn (FacultyAvailabilityDeclaration $declaration): int => (int) $declaration->faculty_user_id);

        $excludedFacultyIds = [];
        foreach ($overridesByFaculty as $facultyId => $override) {
            if ($override->allowedLoadUnits() <= 0.0) {
                $excludedFacultyIds[(int) $facultyId] = true;
            }
        }
        foreach ($declarationsByFaculty as $facultyId => $declaration) {
            if ($declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
                $excludedFacultyIds[(int) $facultyId] = true;
            }
        }

        return [
            'term_id' => (int) $term->id,
            'default_max_units' => $term->default_max_units !== null ? max(0.0, (float) $term->default_max_units) : 24.0,
            'active_package' => $activePackage,
            'school_weekly_minutes' => $schoolMetrics !== null && $schoolMetrics['total_minutes'] > 0 ? $schoolMetrics['total_minutes'] : null,
            'school_longest_block_minutes' => $schoolMetrics !== null && $schoolMetrics['longest_block_minutes'] > 0 ? $schoolMetrics['longest_block_minutes'] : null,
            'sections' => $sections,
            'active_qualifications_by_course' => $activeQualificationsByCourse,
            'overrides_by_faculty' => $overridesByFaculty,
            'declarations_by_faculty' => $declarationsByFaculty,
            'excluded_faculty_ids' => $excludedFacultyIds,
        ];
    }

    /**
     * Compute Sole-teacher (exclusive) vs Shared-pool workload against a faculty member's unit cap and available hours.
     *
     * @param  list<int>  $selectedCourseIds
     * @param  array<string, mixed>|null  $context
     * @return array{
     *     exclusive: array{classes: int, units: float, weekly_minutes: int, weekly_hours: float, longest_meeting_minutes: int},
     *     shared: array{classes: int, units: float, weekly_minutes: int, weekly_hours: float, longest_meeting_minutes: int},
     *     total: array{classes: int, units: float, weekly_minutes: int, weekly_hours: float, longest_meeting_minutes: int},
     *     unit_cap: float,
     *     unit_cap_is_override: bool,
     *     registrar_not_teaching: bool,
     *     school_weekly_minutes: int|null,
     *     school_longest_block_minutes: int|null,
     *     available_minutes: int|null,
     *     longest_open_block_minutes: int|null,
     *     available_source: string,
     *     has_declaration: bool,
     *     exceeds_unit_cap: bool,
     *     exceeds_available_hours: bool,
     *     exceeds_longest_block: bool,
     *     has_warning: bool,
     *     warning_reasons: list<string>
     * }
     */
    public function previewForFaculty(
        Term $term,
        int $facultyUserId,
        array $selectedCourseIds,
        bool $confirmedOnly = false,
        ?array $context = null,
    ): array {
        $ctx = $context ?? $this->buildTermContext($term, $confirmedOnly);
        $selectedLookup = [];
        foreach ($selectedCourseIds as $courseId) {
            $id = (int) $courseId;
            if ($id > 0) {
                $selectedLookup[$id] = true;
            }
        }

        $exclusiveClasses = 0;
        $exclusiveUnits = 0.0;
        $exclusiveMinutes = 0;
        $exclusiveLongestMinutes = 0;

        $sharedClasses = 0;
        $sharedUnits = 0.0;
        $sharedMinutes = 0;
        $sharedLongestMinutes = 0;

        $excludedIds = $ctx['excluded_faculty_ids'];
        $qualsByCourse = $ctx['active_qualifications_by_course'];

        foreach ($ctx['sections'] as $section) {
            if ($confirmedOnly && ! $section['confirmed']) {
                continue;
            }

            $fixedFacultyId = $section['fixed_faculty_user_id'];
            if ($fixedFacultyId !== null && $fixedFacultyId !== $facultyUserId) {
                continue;
            }

            $courseId = $section['course_id'];
            $isSelectedCourse = isset($selectedLookup[$courseId]);
            if (! $isSelectedCourse && $fixedFacultyId !== $facultyUserId) {
                continue;
            }

            $otherAvailableTeachers = 0;
            if ($fixedFacultyId !== $facultyUserId) {
                foreach ($qualsByCourse[$courseId] ?? [] as $otherFacultyId) {
                    if ($otherFacultyId !== $facultyUserId && ! isset($excludedIds[$otherFacultyId])) {
                        $otherAvailableTeachers++;
                    }
                }
            }

            if ($otherAvailableTeachers === 0) {
                $exclusiveClasses++;
                $exclusiveUnits += $section['units'];
                $exclusiveMinutes += $section['weekly_minutes'];
                if ($section['longest_meeting_minutes'] > $exclusiveLongestMinutes) {
                    $exclusiveLongestMinutes = $section['longest_meeting_minutes'];
                }
            } else {
                $sharedClasses++;
                $sharedUnits += $section['units'];
                $sharedMinutes += $section['weekly_minutes'];
                if ($section['longest_meeting_minutes'] > $sharedLongestMinutes) {
                    $sharedLongestMinutes = $section['longest_meeting_minutes'];
                }
            }
        }

        $exclusiveUnits = round($exclusiveUnits, 2);
        $sharedUnits = round($sharedUnits, 2);
        $totalUnits = round($exclusiveUnits + $sharedUnits, 2);
        $totalMinutes = $exclusiveMinutes + $sharedMinutes;
        $totalLongestMinutes = max($exclusiveLongestMinutes, $sharedLongestMinutes);

        /** @var FacultyTermLoadOverride|null $override */
        $override = $ctx['overrides_by_faculty']->get($facultyUserId);
        $unitCap = $override instanceof FacultyTermLoadOverride
            ? max(0.0, $override->allowedLoadUnits())
            : (float) $ctx['default_max_units'];
        $unitCapIsOverride = $override instanceof FacultyTermLoadOverride;
        $registrarNotTeaching = $override instanceof FacultyTermLoadOverride && $override->allowedLoadUnits() <= 0.0;

        /** @var FacultyAvailabilityDeclaration|null $declaration */
        $declaration = $ctx['declarations_by_faculty']->get($facultyUserId);
        /** @var TermCalendarPackage|null $package */
        $package = $ctx['active_package'];

        [$availableMinutes, $longestOpenBlockMinutes, $availableSource] = $this->resolveAvailableCapacity(
            declaration: $declaration,
            package: $package,
            registrarNotTeaching: $registrarNotTeaching,
            schoolWeeklyMinutes: $ctx['school_weekly_minutes'],
            schoolLongestBlockMinutes: $ctx['school_longest_block_minutes'],
        );

        $exceedsUnitCap = $exclusiveUnits > ($unitCap + 0.001) || ($registrarNotTeaching && $exclusiveClasses > 0);
        $exceedsAvailableHours = $exclusiveMinutes > 0 && $availableMinutes !== null && $exclusiveMinutes > $availableMinutes;
        $exceedsLongestBlock = $exclusiveLongestMinutes > 0
            && $longestOpenBlockMinutes !== null
            && $exclusiveLongestMinutes > $longestOpenBlockMinutes;

        $warningReasons = [];
        if ($exceedsUnitCap) {
            $warningReasons[] = $registrarNotTeaching
                ? 'Teacher is marked Not teaching (0-unit cap) for this term.'
                : sprintf(
                    'Sole-teacher load (%s units) exceeds unit cap (%s units).',
                    self::formatNumber($exclusiveUnits),
                    self::formatNumber($unitCap),
                );
        }
        if ($exceedsAvailableHours) {
            $shortMinutes = $exclusiveMinutes - $availableMinutes;
            $sourceNote = match ($availableSource) {
                'submitted_windows', 'submitted_legacy' => 'submitted open hours',
                'submitted_any' => 'school teaching hours',
                'cannot_teach' => 'declared availability (unavailable)',
                'excluded' => '0 available hours (excluded)',
                default => 'school weekly hours',
            };
            $warningReasons[] = sprintf(
                'Sole-teacher load (%s hrs/week) exceeds %s (%s hrs/week, short %s hrs).',
                self::formatHours($exclusiveMinutes),
                $sourceNote,
                self::formatHours($availableMinutes),
                self::formatHours($shortMinutes),
            );
        } elseif ($exceedsLongestBlock) {
            $warningReasons[] = sprintf(
                'Longest sole-teacher class (%s) does not fit inside longest uninterrupted open block (%s).',
                self::formatDuration($exclusiveLongestMinutes),
                self::formatDuration($longestOpenBlockMinutes),
            );
        }

        return [
            'exclusive' => [
                'classes' => $exclusiveClasses,
                'units' => $exclusiveUnits,
                'weekly_minutes' => $exclusiveMinutes,
                'weekly_hours' => round($exclusiveMinutes / 60, 2),
                'longest_meeting_minutes' => $exclusiveLongestMinutes,
            ],
            'shared' => [
                'classes' => $sharedClasses,
                'units' => $sharedUnits,
                'weekly_minutes' => $sharedMinutes,
                'weekly_hours' => round($sharedMinutes / 60, 2),
                'longest_meeting_minutes' => $sharedLongestMinutes,
            ],
            'total' => [
                'classes' => $exclusiveClasses + $sharedClasses,
                'units' => $totalUnits,
                'weekly_minutes' => $totalMinutes,
                'weekly_hours' => round($totalMinutes / 60, 2),
                'longest_meeting_minutes' => $totalLongestMinutes,
            ],
            'unit_cap' => $unitCap,
            'unit_cap_is_override' => $unitCapIsOverride,
            'registrar_not_teaching' => $registrarNotTeaching,
            'school_weekly_minutes' => $ctx['school_weekly_minutes'],
            'school_longest_block_minutes' => $ctx['school_longest_block_minutes'],
            'available_minutes' => $availableMinutes,
            'longest_open_block_minutes' => $longestOpenBlockMinutes,
            'available_source' => $availableSource,
            'has_declaration' => $declaration instanceof FacultyAvailabilityDeclaration,
            'exceeds_unit_cap' => $exceedsUnitCap,
            'exceeds_available_hours' => $exceedsAvailableHours,
            'exceeds_longest_block' => $exceedsLongestBlock,
            'has_warning' => $warningReasons !== [],
            'warning_reasons' => $warningReasons,
        ];
    }

    /**
     * Evaluate whether a Faculty member's saved availability declaration is short of their confirmed sole-teacher load.
     *
     * @param  list<int>  $qualifiedCourseIds
     * @param  array<string, mixed>|null  $context
     * @return array{
     *     needs_hours_review: bool,
     *     summary: string|null,
     *     exclusive_classes: int,
     *     exclusive_minutes: int,
     *     longest_meeting_minutes: int,
     *     available_minutes: int|null,
     *     longest_open_block_minutes: int|null
     * }
     */
    public function availabilityHoursShortfall(
        Term $term,
        int $facultyUserId,
        array $qualifiedCourseIds,
        ?array $context = null,
    ): array {
        $preview = $this->previewForFaculty(
            term: $term,
            facultyUserId: $facultyUserId,
            selectedCourseIds: $qualifiedCourseIds,
            confirmedOnly: true,
            context: $context,
        );

        $exclusiveMinutes = $preview['exclusive']['weekly_minutes'];
        $exclusiveLongest = $preview['exclusive']['longest_meeting_minutes'];
        $availableMinutes = $preview['available_minutes'];
        $longestOpen = $preview['longest_open_block_minutes'];

        $shouldFlag = $preview['has_declaration']
            && ! $preview['registrar_not_teaching']
            && $preview['available_source'] !== 'cannot_teach'
            && $preview['available_source'] !== 'no_calendar'
            && $exclusiveMinutes > 0
            && ($preview['exceeds_available_hours'] || $preview['exceeds_longest_block']);

        $summary = null;
        if ($shouldFlag) {
            if ($preview['exceeds_available_hours'] && $availableMinutes !== null) {
                $shortMinutes = $exclusiveMinutes - $availableMinutes;
                $summary = sprintf(
                    '%s / %s hrs/week open (short %s hrs)',
                    self::formatHours($availableMinutes),
                    self::formatHours($exclusiveMinutes),
                    self::formatHours($shortMinutes),
                );
            } elseif ($preview['exceeds_longest_block'] && $longestOpen !== null) {
                $summary = sprintf(
                    'Longest open block %s < %s class needed',
                    self::formatDuration($longestOpen),
                    self::formatDuration($exclusiveLongest),
                );
            }
        }

        return [
            'needs_hours_review' => $shouldFlag,
            'summary' => $summary,
            'exclusive_classes' => $preview['exclusive']['classes'],
            'exclusive_minutes' => $exclusiveMinutes,
            'longest_meeting_minutes' => $exclusiveLongest,
            'available_minutes' => $availableMinutes,
            'longest_open_block_minutes' => $longestOpen,
        ];
    }

    /**
     * Compute total usable weekly minutes, longest uninterrupted block, and days with hours for chosen windows.
     *
     * @param  list<array{day_of_week:int,start_time:string,end_time:string}>  $windows
     * @return array{total_minutes: int, longest_block_minutes: int, days_with_hours: int}
     */
    public static function usableMinutesFromWindows(array $windows, ?TermCalendarPackage $package): array
    {
        if ($windows === [] || ! $package instanceof TermCalendarPackage) {
            return [
                'total_minutes' => 0,
                'longest_block_minutes' => 0,
                'days_with_hours' => 0,
            ];
        }

        $package->loadMissing('teachingGridRows');
        $gridByDay = $package->teachingGridRows->keyBy('day_of_week');
        $windowsByDay = collect($windows)->groupBy('day_of_week');

        $totalMinutes = 0;
        $longestBlock = 0;
        $daysWithHours = 0;

        foreach ($windowsByDay as $day => $dayWindows) {
            $gridRow = $gridByDay->get((int) $day);
            if (! $gridRow instanceof TermTeachingGridRow) {
                continue;
            }

            $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->starts_at);
            $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->ends_at);
            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $clipped = [];
            foreach ($dayWindows as $window) {
                $wStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($window['start_time'] ?? ''));
                $wEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($window['end_time'] ?? ''));
                if ($wStart === null || $wEnd === null) {
                    continue;
                }
                $cStart = max($gridStart, $wStart);
                $cEnd = min($gridEnd, $wEnd);
                if ($cStart < $cEnd) {
                    $clipped[] = ['start' => $cStart, 'end' => $cEnd];
                }
            }

            $breaks = self::breakIntervalsForRow($gridRow);
            $dayMinutes = 0;
            foreach (FacultyAvailabilityDeclaration::subtractIntervals($clipped, $breaks) as $segment) {
                $length = $segment['end'] - $segment['start'];
                $dayMinutes += $length;
                if ($length > $longestBlock) {
                    $longestBlock = $length;
                }
            }

            if ($dayMinutes > 0) {
                $totalMinutes += $dayMinutes;
                $daysWithHours++;
            }
        }

        return [
            'total_minutes' => $totalMinutes,
            'longest_block_minutes' => $longestBlock,
            'days_with_hours' => $daysWithHours,
        ];
    }

    /**
     * Compute total usable weekly minutes and longest uninterrupted block across the active school grid
     * (optionally subtracting legacy hard_unavailability intervals).
     *
     * @param  list<array<string, mixed>>  $hardUnavailability
     * @return array{total_minutes: int, longest_block_minutes: int, days_with_hours: int}
     */
    public static function usableMinutesFromGrid(?TermCalendarPackage $package, array $hardUnavailability = []): array
    {
        if (! $package instanceof TermCalendarPackage) {
            return [
                'total_minutes' => 0,
                'longest_block_minutes' => 0,
                'days_with_hours' => 0,
            ];
        }

        $package->loadMissing('teachingGridRows');
        $unavailByDay = [];
        foreach ($hardUnavailability as $interval) {
            if (! is_array($interval)) {
                continue;
            }
            $day = (int) ($interval['day_of_week'] ?? 0);
            $start = FacultyAvailabilityDeclaration::clockToMinutes((string) ($interval['starts_at'] ?? $interval['start_time'] ?? ''));
            $end = FacultyAvailabilityDeclaration::clockToMinutes((string) ($interval['ends_at'] ?? $interval['end_time'] ?? ''));
            if ($day >= 1 && $day <= 7 && $start !== null && $end !== null && $start < $end) {
                $unavailByDay[$day][] = ['start' => $start, 'end' => $end];
            }
        }

        $totalMinutes = 0;
        $longestBlock = 0;
        $daysWithHours = 0;

        foreach ($package->teachingGridRows as $row) {
            $day = (int) $row->day_of_week;
            $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at);
            $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at);
            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $cuts = array_merge(self::breakIntervalsForRow($row), $unavailByDay[$day] ?? []);
            $dayMinutes = 0;
            foreach (FacultyAvailabilityDeclaration::subtractIntervals([['start' => $gridStart, 'end' => $gridEnd]], $cuts) as $segment) {
                $length = $segment['end'] - $segment['start'];
                $dayMinutes += $length;
                if ($length > $longestBlock) {
                    $longestBlock = $length;
                }
            }

            if ($dayMinutes > 0) {
                $totalMinutes += $dayMinutes;
                $daysWithHours++;
            }
        }

        return [
            'total_minutes' => $totalMinutes,
            'longest_block_minutes' => $longestBlock,
            'days_with_hours' => $daysWithHours,
        ];
    }

    public static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public static function formatHours(int $minutes): string
    {
        $hours = $minutes / 60;

        return fmod($hours, 1.0) === 0.0
            ? (string) (int) $hours
            : rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }

    public static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' '.Str::plural('min', $minutes);
        }

        $formatted = self::formatHours($minutes);

        return $formatted.' '.($formatted === '1' ? 'hr' : 'hrs');
    }

    /**
     * @return list<array{start: int, end: int}>
     */
    private static function breakIntervalsForRow(TermTeachingGridRow $row): array
    {
        $breaks = [];
        foreach (is_array($row->breaks) ? $row->breaks : [] as $break) {
            if (! is_array($break)) {
                continue;
            }
            $bStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['starts_at'] ?? ''));
            $bEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['ends_at'] ?? ''));
            if ($bStart !== null && $bEnd !== null && $bStart < $bEnd) {
                $breaks[] = ['start' => $bStart, 'end' => $bEnd];
            }
        }

        return $breaks;
    }

    /**
     * @return array{0: int, 1: int, 2: int|null} [weeklyMinutes, longestMeetingMinutes, fixedFacultyUserId]
     */
    private function extractSectionMinutesAndPin(Section $section, CourseSpecification $spec): array
    {
        $demands = $section->deliveryGroups->flatMap->schedulingDemands;

        if ($demands->isNotEmpty()) {
            $weeklyMinutes = 0;
            $longestMeeting = 0;
            $fixedFacultyId = null;

            foreach ($demands as $demand) {
                /** @var SchedulingDemand $demand */
                $count = max(0, (int) $demand->meeting_count);
                $duration = max(0, (int) $demand->required_duration_minutes);
                $weeklyMinutes += ($count * $duration);
                if ($duration > $longestMeeting) {
                    $longestMeeting = $duration;
                }
                if ($demand->fixed_faculty_user_id !== null && $fixedFacultyId === null) {
                    $fixedFacultyId = (int) $demand->fixed_faculty_user_id;
                }
            }

            return [$weeklyMinutes, $longestMeeting, $fixedFacultyId];
        }

        $weeklyMinutes = 0;
        $longestMeeting = 0;

        foreach ($spec->components as $component) {
            /** @var CourseComponent $component */
            $rawPattern = strtoupper(trim((string) $component->meeting_pattern));
            if (in_array($rawPattern, ['NONE', 'EXTERNALLY_ARRANGED'], true)) {
                continue;
            }

            $parsed = CourseComponent::parseMeetingPattern($component->meeting_pattern);
            if ($parsed === null && (float) $component->weekly_contact_hours > 0) {
                $fallbackPattern = CourseComponent::defaultMeetingPatternForHours(
                    (float) $component->weekly_contact_hours,
                    (string) ($component->room_type_default ?: CourseComponent::RoomTypeLectureRoom),
                );
                $parsed = CourseComponent::parseMeetingPattern($fallbackPattern);
            }

            if ($parsed !== null) {
                $count = max(0, (int) $parsed['count']);
                $duration = max(0, (int) $parsed['duration_minutes']);
                $weeklyMinutes += ($count * $duration);
                if ($duration > $longestMeeting) {
                    $longestMeeting = $duration;
                }
            } elseif ((float) $component->weekly_contact_hours > 0) {
                $minutes = (int) round((float) $component->weekly_contact_hours * 60);
                $weeklyMinutes += $minutes;
                if ($minutes > $longestMeeting) {
                    $longestMeeting = $minutes;
                }
            }
        }

        return [$weeklyMinutes, $longestMeeting, null];
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: string} [availableMinutes, longestOpenBlockMinutes, source]
     */
    private function resolveAvailableCapacity(
        ?FacultyAvailabilityDeclaration $declaration,
        ?TermCalendarPackage $package,
        bool $registrarNotTeaching,
        ?int $schoolWeeklyMinutes,
        ?int $schoolLongestBlockMinutes,
    ): array {
        if ($registrarNotTeaching) {
            return [0, 0, 'excluded'];
        }

        if (! $declaration instanceof FacultyAvailabilityDeclaration) {
            return $schoolWeeklyMinutes !== null
                ? [$schoolWeeklyMinutes, $schoolLongestBlockMinutes, 'school_grid']
                : [null, null, 'no_calendar'];
        }

        $mode = $declaration->effectiveMode();

        if ($mode === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return [0, 0, 'cannot_teach'];
        }

        if (! $package instanceof TermCalendarPackage) {
            return [null, null, 'no_calendar'];
        }

        if ($mode === FacultyAvailabilityDeclaration::ModeAnyApproved) {
            return [$schoolWeeklyMinutes ?? 0, $schoolLongestBlockMinutes ?? 0, 'submitted_any'];
        }

        if ($mode === FacultyAvailabilityDeclaration::ModeWindows) {
            $metrics = self::usableMinutesFromWindows($declaration->normalizedTeachingWindows(), $package);

            return [$metrics['total_minutes'], $metrics['longest_block_minutes'], 'submitted_windows'];
        }

        $metrics = self::usableMinutesFromGrid(
            $package,
            is_array($declaration->hard_unavailability) ? $declaration->hard_unavailability : [],
        );

        return [$metrics['total_minutes'], $metrics['longest_block_minutes'], 'submitted_legacy'];
    }
}
