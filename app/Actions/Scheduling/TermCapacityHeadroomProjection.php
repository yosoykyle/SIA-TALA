<?php

namespace App\Actions\Scheduling;

use App\Models\CourseComponent;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;

final class TermCapacityHeadroomProjection
{
    /**
     * Compute collegiate capacity headroom (room seats, weekly room hours, and available qualified faculty units)
     * for the selected Term so Registrars see feasibility signals before creating or confirming sections.
     *
     * @return array{
     *     status: 'healthy'|'warning'|'over'|'awaiting',
     *     status_color: 'success'|'warning'|'danger'|'gray',
     *     status_label: string,
     *     room_seats: array{largest_section: int, largest_room: int, active_rooms: int, over_capacity_sections: int, status: string, summary: string},
     *     room_hours: array{demanded_hours: float, available_hours: float, percent: int, status: string, summary: string},
     *     faculty_units: array{demanded_units: float, available_units: float, available_teachers: int, excluded_teachers: int, percent: int, status: string, summary: string},
     * }
     */
    public function forTerm(Term $term): array
    {
        $activePackage = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('teachingGridRows')
            ->first();

        $rooms = Room::query()->where('is_active', true)->get();
        $activeRoomCount = $rooms->count();
        $largestRoomSeats = (int) ($rooms->max('capacity') ?? 0);

        $cohorts = TermCohort::query()->where('term_id', $term->id)->get();
        $cohortSizes = $cohorts->map(fn (TermCohort $cohort): int => (int) ($cohort->confirmed_count ?? $cohort->forecast_count ?? 0));
        $largestSectionSeats = (int) ($cohortSizes->max() ?? 0);
        $overCapacitySections = $largestRoomSeats > 0
            ? $cohortSizes->filter(fn (int $size): bool => $size > $largestRoomSeats)->count()
            : ($largestSectionSeats > 0 ? $cohorts->count() : 0);

        $weeklyTeachingHoursPerRoom = $this->weeklyTeachingHours($activePackage);
        $availableRoomHours = round($activeRoomCount * $weeklyTeachingHoursPerRoom, 1);

        $sections = Section::query()
            ->whereHas('termOffering', fn ($query) => $query
                ->where('term_id', $term->id)
                ->where('state', '!=', TermOffering::StateCancelled))
            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
            ->with([
                'courseRevision.components',
                'termOffering.curriculumEntry.courseSpecification.components',
                'deliveryGroups.schedulingDemands',
            ])
            ->get();

        $demandedRoomHours = 0.0;
        $demandedFacultyUnits = 0.0;
        $demandedCourseIds = [];

        foreach ($sections as $section) {
            $spec = $section->courseRevision ?? $section->termOffering?->curriculumEntry?->courseSpecification;
            if ($spec !== null) {
                $demandedFacultyUnits += (float) $spec->credit_units;
                if ($spec->course_id !== null) {
                    $demandedCourseIds[(int) $spec->course_id] = true;
                }
            }

            $sectionDemands = $section->deliveryGroups->flatMap->schedulingDemands;
            if ($sectionDemands->isNotEmpty()) {
                foreach ($sectionDemands as $demand) {
                    /** @var SchedulingDemand $demand */
                    if ($demand->modality === TermOffering::ModalityOnline) {
                        continue;
                    }
                    $demandedRoomHours += ((int) $demand->meeting_count * (int) $demand->required_duration_minutes) / 60.0;
                }
            } elseif ($spec !== null && ($section->delivery_mode ?? $section->termOffering?->modality) !== TermOffering::ModalityOnline) {
                foreach ($spec->components as $component) {
                    /** @var CourseComponent $component */
                    if (in_array(strtoupper((string) $component->meeting_pattern), ['NONE', 'EXTERNALLY_ARRANGED', ''], true)) {
                        continue;
                    }
                    $demandedRoomHours += max(0.0, (float) $component->weekly_contact_hours);
                }
            }
        }

        $demandedRoomHours = round($demandedRoomHours, 1);
        $demandedFacultyUnits = round($demandedFacultyUnits, 1);

        $roomHoursPercent = $availableRoomHours > 0
            ? (int) min(999, round(($demandedRoomHours / $availableRoomHours) * 100))
            : ($demandedRoomHours > 0 ? 100 : 0);

        $activeFacultyIds = User::query()
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $qualifiedFacultyIds = FacultyQualification::query()
            ->active()
            ->whereIn('faculty_user_id', $activeFacultyIds)
            ->when($demandedCourseIds !== [], fn ($query) => $query->whereIn('course_id', array_keys($demandedCourseIds)))
            ->distinct()
            ->pluck('faculty_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $targetFacultyIds = $qualifiedFacultyIds !== [] ? $qualifiedFacultyIds : $activeFacultyIds;

        $declarations = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->whereIn('faculty_user_id', $targetFacultyIds)
            ->orderByDesc('version')
            ->get()
            ->unique('faculty_user_id')
            ->keyBy('faculty_user_id');

        $overrides = FacultyTermLoadOverride::query()
            ->where('term_id', $term->id)
            ->where('is_active', true)
            ->whereIn('faculty_user_id', $targetFacultyIds)
            ->get()
            ->keyBy('faculty_user_id');

        $defaultMaxUnits = $term->default_max_units !== null ? (float) $term->default_max_units : 24.0;
        $availableFacultyUnits = 0.0;
        $availableTeachers = 0;
        $excludedTeachers = 0;

        foreach ($targetFacultyIds as $facultyId) {
            /** @var FacultyAvailabilityDeclaration|null $declaration */
            $declaration = $declarations->get($facultyId);
            /** @var FacultyTermLoadOverride|null $override */
            $override = $overrides->get($facultyId);

            $allowedUnits = $override instanceof FacultyTermLoadOverride
                ? max(0.0, $override->allowedLoadUnits())
                : max(0.0, $defaultMaxUnits);

            $cannotTeach = ($declaration instanceof FacultyAvailabilityDeclaration
                && $declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach)
                || $allowedUnits <= 0.0;

            if ($cannotTeach || $declaration === null) {
                $excludedTeachers++;

                continue;
            }

            $availableTeachers++;
            $availableFacultyUnits += $allowedUnits;
        }

        $availableFacultyUnits = round($availableFacultyUnits, 1);
        $facultyUnitsPercent = $availableFacultyUnits > 0
            ? (int) min(999, round(($demandedFacultyUnits / $availableFacultyUnits) * 100))
            : ($demandedFacultyUnits > 0 ? 100 : 0);

        $seatStatus = match (true) {
            $activeRoomCount === 0 && $largestSectionSeats > 0 => 'over',
            $overCapacitySections > 0 => 'over',
            $largestRoomSeats > 0 && $largestSectionSeats >= (int) floor($largestRoomSeats * 0.9) && $largestSectionSeats > 0 => 'warning',
            default => 'healthy',
        };

        $roomHoursStatus = match (true) {
            $availableRoomHours <= 0 && $demandedRoomHours > 0 => 'over',
            $demandedRoomHours > $availableRoomHours => 'over',
            $roomHoursPercent >= 80 => 'warning',
            default => 'healthy',
        };

        $hasClasses = $sections->isNotEmpty();

        $facultyStatus = match (true) {
            $hasClasses && $availableTeachers === 0 => 'awaiting',
            $demandedFacultyUnits > 0 && $availableFacultyUnits <= 0 => 'over',
            $demandedFacultyUnits > $availableFacultyUnits => 'over',
            $facultyUnitsPercent >= 85 => 'warning',
            default => 'healthy',
        };

        $overallStatus = match (true) {
            in_array('over', [$seatStatus, $roomHoursStatus, $facultyStatus], true) => 'over',
            in_array('warning', [$seatStatus, $roomHoursStatus, $facultyStatus], true) => 'warning',
            $facultyStatus === 'awaiting' => 'awaiting',
            default => 'healthy',
        };

        $overallColor = match ($overallStatus) {
            'over' => 'danger',
            'warning' => 'warning',
            'awaiting' => 'gray',
            default => 'success',
        };

        $overallLabel = match ($overallStatus) {
            'over' => 'Capacity exceeded',
            'warning' => 'Approaching limit',
            'awaiting' => 'Awaiting faculty replies',
            default => 'Within capacity',
        };

        return [
            'status' => $overallStatus,
            'status_color' => $overallColor,
            'status_label' => $overallLabel,
            'room_seats' => [
                'largest_section' => $largestSectionSeats,
                'largest_room' => $largestRoomSeats,
                'active_rooms' => $activeRoomCount,
                'over_capacity_sections' => $overCapacitySections,
                'status' => $seatStatus,
                'summary' => $activeRoomCount === 0
                    ? 'No active rooms recorded'
                    : ($overCapacitySections > 0
                        ? "{$overCapacitySections} section(s) exceed largest room ({$largestRoomSeats} seats)"
                        : "Largest section {$largestSectionSeats} / {$largestRoomSeats} max room seats · {$activeRoomCount} active rooms"),
            ],
            'room_hours' => [
                'demanded_hours' => $demandedRoomHours,
                'available_hours' => $availableRoomHours,
                'percent' => $roomHoursPercent,
                'status' => $roomHoursStatus,
                'summary' => $availableRoomHours > 0
                    ? "{$this->fmt($demandedRoomHours)} / {$this->fmt($availableRoomHours)} room-hrs/wk ({$roomHoursPercent}%)"
                    : ($demandedRoomHours > 0 ? "{$this->fmt($demandedRoomHours)} hrs needed · no room hours available" : '0 room-hrs planned'),
            ],
            'faculty_units' => [
                'demanded_units' => $demandedFacultyUnits,
                'available_units' => $availableFacultyUnits,
                'available_teachers' => $availableTeachers,
                'excluded_teachers' => $excludedTeachers,
                'percent' => $facultyUnitsPercent,
                'status' => $facultyStatus,
                'summary' => $availableFacultyUnits > 0
                    ? "{$this->fmt($demandedFacultyUnits)} / {$this->fmt($availableFacultyUnits)} available units ({$availableTeachers} ready)"
                    : ($demandedFacultyUnits > 0
                        ? "{$this->fmt($demandedFacultyUnits)} units needed · 0 available faculty units"
                        : "{$availableTeachers} faculty ready ({$excludedTeachers} awaiting / excluded)"),
            ],
        ];
    }

    private function weeklyTeachingHours(?TermCalendarPackage $package): float
    {
        if (! $package instanceof TermCalendarPackage || $package->teachingGridRows->isEmpty()) {
            return 60.0;
        }

        $minutes = 0;
        foreach ($package->teachingGridRows as $row) {
            $start = CarbonImmutable::parse($row->starts_at);
            $end = CarbonImmutable::parse($row->ends_at);
            $dayMinutes = max(0, (int) $start->diffInMinutes($end, false));

            foreach ($row->breaks ?? [] as $break) {
                if (! isset($break['starts_at'], $break['ends_at'])) {
                    continue;
                }
                $bStart = CarbonImmutable::parse($break['starts_at']);
                $bEnd = CarbonImmutable::parse($break['ends_at']);
                $dayMinutes -= max(0, (int) $bStart->diffInMinutes($bEnd, false));
            }

            $minutes += max(0, $dayMinutes);
        }

        return $minutes > 0 ? round($minutes / 60.0, 2) : 60.0;
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
