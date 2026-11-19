<?php

namespace App\Actions\Scheduling;

use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;

final class ReadyTermPlanningProjection
{
    /**
     * Evaluate whether a term satisfies all calendar, class, teacher, and room prerequisites for scheduling.
     *
     * @return array{ready: bool, term_id: int, blockers: list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>}
     */
    public function forTerm(Term $term): array
    {
        $blockers = [];
        $activePackage = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();

        if (! $activePackage instanceof TermCalendarPackage) {
            $blockers[] = $this->blocker('calendar_not_active', 'Term Calendar Package', 'Registrar', 'No active package exists for this exact Term.', 'Activate a passing package.', 'Correct the Draft package and retry.');
        }

        $classes = Section::query()
            ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id))
            ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
            ->with([
                'calendarPackage.teachingGridRows',
                'calendarPackage.datedExceptions',
                'courseRevision.course',
                'courseRevision.components',
                'termOffering.curriculumEntry.courseSpecification.course',
                'termOffering.curriculumEntry.courseSpecification.components',
                'deliveryGroups.schedulingDemands',
            ])
            ->get()
            ->filter(fn (Section $section): bool => $activePackage && $section->calendarPackage->isActivatedCoreSource() && $section->calendarPackage->coreSignature() === $activePackage->coreSignature());

        if ($classes->isEmpty() || $classes->contains(fn (Section $section): bool => $section->confirmed_at === null)) {
            $blockers[] = $this->blocker('classes_not_confirmed', 'Cohorts and Class Offerings', 'Registrar', 'Every ready Class Offering must be confirmed.', 'Resolve class authority, cohorts, capacity, and meeting requirements.', 'Correct the affected Draft class and confirm it.');
        }

        $requiredCourses = [];
        foreach ($classes as $section) {
            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            if (! $specification instanceof CourseSpecification
                || $specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                || $specification->components->isEmpty()) {
                continue;
            }

            $hasGroups = $section->deliveryGroups
                ->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled])
                ->isNotEmpty();
            if (! $hasGroups) {
                continue;
            }

            $course = $specification->course;
            if ($course instanceof Course) {
                $requiredCourses[$course->id] = (string) $course->code;
            }
        }

        if ($requiredCourses !== []) {
            $qualifiedCourseIds = FacultyQualification::query()
                ->whereIn('course_id', array_keys($requiredCourses))
                ->where('is_active', true)
                ->distinct()
                ->pluck('course_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $missingCourseCodes = [];
            foreach ($requiredCourses as $courseId => $courseCode) {
                if (! in_array((int) $courseId, $qualifiedCourseIds, true)) {
                    $missingCourseCodes[] = $courseCode;
                }
            }

            if ($missingCourseCodes !== []) {
                sort($missingCourseCodes);
                $blockers[] = $this->blocker(
                    'faculty_qualifications_missing',
                    'Teaching resources',
                    'Registrar',
                    'Record at least one active qualified teacher for: '.implode(', ', $missingCourseCodes).'.',
                    'Open Teaching resources to assign qualified teachers for each offered course.',
                    'Add active Faculty Qualifications for the listed courses, then retry readiness.',
                );
            }
        }

        $facultyIds = $classes->flatMap(fn (Section $section) => $section->deliveryGroups)
            ->flatMap(fn (SectionDeliveryGroup $group) => $group->schedulingDemands->pluck('fixed_faculty_user_id'))
            ->filter()
            ->unique();

        if ($facultyIds->isNotEmpty() && FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->whereIn('faculty_user_id', $facultyIds)
            ->distinct('faculty_user_id')
            ->count('faculty_user_id') !== $facultyIds->count()) {
            $blockers[] = $this->blocker('faculty_availability_incomplete', 'Teaching resources', 'Faculty', 'A required Faculty availability declaration is missing.', 'Faculty records own availability; Registrar reviews readiness.', 'Record or correct the declaration, then regenerate.');
        }

        $activeRooms = Room::query()->where('is_active', true)->get(['id', 'room_type', 'capacity']);

        if ($activeRooms->isEmpty()) {
            $blockers[] = $this->blocker('rooms_unavailable', 'Teaching resources', 'Registrar', 'No active room is available for on-campus planning.', 'Record current room capacity, type, features, and unavailability.', 'Correct the room authority and retry readiness.');
        } else {
            $unmatchedRoomNeeds = [];
            $roomTypeLabels = Room::typeOptions();

            foreach ($classes as $section) {
                $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
                if (! $specification instanceof CourseSpecification
                    || $specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                    || $specification->components->isEmpty()) {
                    continue;
                }

                foreach ($section->deliveryGroups->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled]) as $group) {
                    $modality = filled($group->modality)
                        ? (string) $group->modality
                        : (string) ($section->termOffering?->modality ?? $section->delivery_mode);

                    if ($modality !== TermOffering::ModalityFaceToFace) {
                        continue;
                    }

                    $expectedCount = max((int) $group->expected_count, 0);

                    foreach ($specification->components as $component) {
                        $roomType = $section->termOffering?->room_type_override ?: $component->room_type_default;
                        if (blank($roomType)) {
                            continue;
                        }

                        $hasMatch = $activeRooms->contains(
                            fn (Room $room): bool => $room->room_type === (string) $roomType
                                && (int) $room->capacity >= $expectedCount,
                        );

                        if (! $hasMatch) {
                            $label = $roomTypeLabels[(string) $roomType] ?? (string) $roomType;
                            $unmatchedRoomNeeds[] = $section->code.' ('.$label.', '.$expectedCount.' seats)';
                            break;
                        }
                    }
                }
            }

            if ($unmatchedRoomNeeds !== []) {
                $preview = array_slice($unmatchedRoomNeeds, 0, 3);
                $remainder = count($unmatchedRoomNeeds) - count($preview);
                $blockers[] = $this->blocker(
                    'rooms_unsuitable',
                    'Teaching resources',
                    'Registrar',
                    'No active room matches the required type and seat capacity for: '.implode('; ', $preview).($remainder > 0 ? ' (+'.$remainder.' more)' : '').'.',
                    'Activate or update a matching room in Teaching resources.',
                    'Ensure an active room matches each face-to-face class room type and expected count.',
                );
            }
        }

        return ['ready' => $blockers === [], 'term_id' => (int) $term->id, 'blockers' => $blockers];
    }

    /** @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string} */
    private function blocker(string $code, string $source, string $owner, string $reason, string $nextAction, string $recovery): array
    {
        return compact('code', 'source', 'owner', 'reason') + ['next_action' => $nextAction, 'recovery' => $recovery];
    }
}
