<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\OfficialCourseResultProjection;
use App\Models\AdmissionApplication;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationProposalItem;
use App\Models\Section;
use App\Models\TermOffering;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Classes a registration case may choose from: eligible published sections, prerequisite cues,
 * schedules and seats. Shared by the Registrar's Choose subjects and the student's
 * Enroll as irregular request (#99 Scope 11).
 */
class RegistrationClassChoices
{
    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    /**
     * @return array{
     *     sections: Collection<int, Section>,
     *     meetings: Collection,
     *     reserved: Collection<int, int>,
     *     enrolled: Collection<int, int>,
     *     satisfiedCourseIds: Collection<int, int>
     * }
     */
    public function forCase(Enrollment $case): array
    {
        if (isset($this->cache[$case->id])) {
            return $this->cache[$case->id];
        }

        $case->loadMissing(['studentProfile', 'admissionApplication']);
        $timetable = PublishedTimetableVersion::query()->where('term_id', $case->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)->latest('version')->first();
        if ($timetable === null) {
            return $this->cache[$case->id] = [
                'sections' => collect(),
                'meetings' => collect(),
                'reserved' => collect(),
                'enrolled' => collect(),
                'satisfiedCourseIds' => collect(),
            ];
        }
        $meetings = PublishedTimetableMeeting::query()->with('room')
            ->where('published_timetable_version_id', $timetable->id)->orderBy('day_of_week')->orderBy('starts_at')->get();
        $recurringSectionIds = $meetings->pluck('section_id')->unique();
        $programId = $case->studentProfile?->program_id ?? $case->admissionApplication?->program_id;
        $eligibility = app(RegistrationAcademicEligibilityQuery::class);
        $resolvedCurriculum = $eligibility->resolveStudentCurriculum($case);
        $allowedStates = ($case->studentProfile?->curriculum_version_id !== null
            || $resolvedCurriculum?->state === CurriculumVersion::StateSuperseded)
            ? [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded]
            : [CurriculumVersion::StateActive];
        $studentCurricula = $resolvedCurriculum instanceof CurriculumVersion
            ? collect([$resolvedCurriculum->loadMissing('entries.courseSpecification.requirements')])
            : CurriculumVersion::query()
                ->with('entries.courseSpecification.requirements')
                ->where('program_id', $programId)
                ->whereIn('state', $allowedStates)
                ->when($case->studentProfile?->curriculum_version_id, fn (Builder $query, int $id) => $query->whereKey($id))
                ->get();
        $studentCurriculumIds = $studentCurricula->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
        $studentEntries = $studentCurricula
            ->flatMap(fn (CurriculumVersion $curriculum): Collection => $curriculum->entries)
            ->values();
        $eligibleCourseIds = $eligibility->expandWithEquivalentCourseIds(
            $studentEntries
                ->pluck('courseSpecification.course_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->values(),
        );

        $sections = Section::query()->with([
            'cohorts',
            'courseRevision.requirements.relatedCourse',
            'termOffering.curriculumEntry.curriculumVersion',
            'termOffering.curriculumEntry.courseSpecification.course',
            'termOffering.curriculumEntry.courseSpecification.requirements.relatedCourse',
        ])
            ->where('state', Section::StateOpen)
            ->whereHas('termOffering', fn (Builder $query) => $query->where('term_id', $case->term_id)
                ->where('state', TermOffering::StateScheduled)
                ->where(function (Builder $query) use ($studentCurriculumIds, $eligibleCourseIds): void {
                    $query->whereHas(
                        'curriculumEntry',
                        fn (Builder $entryQuery) => $entryQuery->whereIn('curriculum_version_id', $studentCurriculumIds),
                    );

                    if ($eligibleCourseIds->isNotEmpty()) {
                        $query->orWhereHas(
                            'curriculumEntry.courseSpecification',
                            fn (Builder $specQuery) => $specQuery->whereIn('course_id', $eligibleCourseIds),
                        );
                    }
                }))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereIn('id', $recurringSectionIds)
                    ->whereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $query) => $query->where('scheduling_treatment', CourseSpecification::SchedulingRecurring)))
                ->orWhere(fn (Builder $query) => $query->whereNotIn('id', $recurringSectionIds)
                    ->whereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $query) => $query->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged))))
            ->orderBy('code')
            ->get()
            ->filter(function (Section $section) use ($eligibility, $studentEntries, $studentCurriculumIds, $programId, $case): bool {
                $hostCurriculumId = (int) ($section->termOffering?->curriculumEntry?->curriculum_version_id ?? 0);
                if ($studentCurriculumIds->contains($hostCurriculumId)) {
                    return true;
                }

                if (! $eligibility->resolveCurriculumEntryFromEntries($studentEntries, $section->termOffering, $section) instanceof CurriculumEntry) {
                    return false;
                }

                return $section->source === Section::SourceShared
                    || $section->cohorts->isEmpty()
                    || $section->cohorts->contains(fn ($cohort): bool => (int) $cohort->term_id === (int) $case->term_id
                        && ((int) $cohort->program_id === (int) $programId
                            || $studentCurriculumIds->contains((int) $cohort->curriculum_version_id)));
            })
            ->values();

        $creditedEntryIds = collect();
        if ($case->studentProfile !== null) {
            $creditedEntryIds = $creditedEntryIds->merge(
                RegistrationCreditEvaluation::creditedEntryIdsForStudent($case->studentProfile),
            );
        }
        if ($case->admissionApplication?->application_path === AdmissionApplication::PathTransferee) {
            $curriculumIds = $sections
                ->pluck('termOffering.curriculumEntry.curriculum_version_id')
                ->merge($studentCurriculumIds)
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique();
            $creditedEntryIds = $creditedEntryIds->merge(
                $curriculumIds
                    ->flatMap(fn (int $curriculumId): array => RegistrationCreditEvaluation::latestCurrentFor($case, $curriculumId)?->credited_curriculum_entry_ids ?? []),
            );
        }
        $creditedEntryIds = $creditedEntryIds
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $creditedCourseIds = $creditedEntryIds->isNotEmpty()
            ? CurriculumEntry::query()
                ->with('courseSpecification')
                ->whereIn('id', $creditedEntryIds)
                ->get()
                ->pluck('courseSpecification.course_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
            : collect();

        if ($creditedEntryIds->isNotEmpty() || $creditedCourseIds->isNotEmpty()) {
            $sections = $sections
                ->reject(function (Section $section) use ($eligibility, $studentEntries, $creditedEntryIds, $creditedCourseIds): bool {
                    $offering = $section->termOffering;
                    if ($offering !== null && $creditedEntryIds->contains((int) $offering->curriculum_entry_id)) {
                        return true;
                    }

                    $offeredCourseId = (int) ($offering?->courseSpecification()?->course_id ?? 0);
                    if ($offeredCourseId > 0 && $creditedCourseIds->contains($offeredCourseId)) {
                        return true;
                    }

                    $matchedEntry = $eligibility->resolveCurriculumEntryFromEntries($studentEntries, $offering, $section);

                    return $matchedEntry instanceof CurriculumEntry
                        && ($creditedEntryIds->contains((int) $matchedEntry->id)
                            || $creditedCourseIds->contains((int) ($matchedEntry->courseSpecification?->course_id ?? 0)));
                })
                ->values();
        }

        $sectionIds = $sections->pluck('id')->all();
        $reserved = $sectionIds === []
            ? collect()
            : EnrollmentSeatReservation::query()
                ->whereIn('section_id', $sectionIds)
                ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                ->where('enrollment_id', '!=', $case->id)
                ->selectRaw('section_id, COUNT(*) as total')
                ->groupBy('section_id')
                ->pluck('total', 'section_id');
        $enrolled = $sectionIds === []
            ? collect()
            : CourseEnrollment::query()
                ->whereIn('section_id', $sectionIds)
                ->where('is_current', true)
                ->where('enrollment_id', '!=', $case->id)
                ->selectRaw('section_id, COUNT(*) as total')
                ->groupBy('section_id')
                ->pluck('total', 'section_id');

        $satisfiedCourseIds = collect();
        if ($case->studentProfile !== null) {
            $satisfiedCourseIds = app(RegistrationAcademicEligibilityQuery::class)->satisfiedCourseIds($case->studentProfile);
            $earlierPassedCourseIds = app(OfficialCourseResultProjection::class)
                ->forStudent($case->studentProfile)
                ->filter(fn (array $result): bool => $result['course_specification']?->course_id !== null
                    && (in_array($result['result'], ['TC', 'CREDIT'], true)
                        || (is_numeric($result['result']) && (float) $result['result'] <= 4.00)))
                ->pluck('course_specification.course_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id);
            $satisfiedCourseIds = $satisfiedCourseIds->merge($earlierPassedCourseIds)->unique()->values();
        }
        if ($creditedCourseIds->isNotEmpty()) {
            $satisfiedCourseIds = $eligibility->expandWithEquivalentCourseIds(
                $satisfiedCourseIds->merge($creditedCourseIds),
            );
        }

        return $this->cache[$case->id] = [
            'sections' => $sections,
            'meetings' => $meetings->groupBy('section_id'),
            'reserved' => $reserved,
            'enrolled' => $enrolled,
            'satisfiedCourseIds' => $satisfiedCourseIds,
        ];
    }

    /**
     * @param  array<int, int|string>|null  $selectedSectionIds
     * @return array<int, string>
     */
    public function sectionOptions(Enrollment $case, ?array $selectedSectionIds = null, ?array $choices = null): array
    {
        $choices ??= $this->forCase($case);
        $satisfiedCourseIds = $choices['satisfiedCourseIds'];
        $concurrentCourseIds = $this->concurrentCourseIds($case, $choices['sections'], $selectedSectionIds);

        return $choices['sections']->mapWithKeys(function (Section $section) use ($satisfiedCourseIds, $concurrentCourseIds): array {
            $entry = $section->termOffering?->curriculumEntry;
            $positionLabel = $entry !== null
                ? collect([
                    is_numeric($entry->year_level) ? 'Year '.$entry->year_level : $entry->year_level,
                    $entry->term_label,
                ])->filter()->implode(', ')
                : null;
            $specification = $section->termOffering?->courseSpecification();
            $courseId = (int) ($specification?->course_id ?? 0);
            $requirementGroups = ($specification?->requirements ?? collect())
                ->where('state', CourseRequirement::StateActive)
                ->whereIn('rule_type', [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite])
                ->groupBy(fn (CourseRequirement $requirement): string => $requirement->rule_type.':'.$requirement->group_key);

            $hasUnmetPrereq = false;
            $hasUnmetCoreq = false;
            foreach ($requirementGroups as $alternatives) {
                $ruleType = $alternatives->first()?->rule_type;
                $groupSatisfied = $alternatives->contains(function (CourseRequirement $requirement) use ($satisfiedCourseIds, $concurrentCourseIds): bool {
                    $relatedCourseId = (int) $requirement->related_course_id;
                    if ($relatedCourseId <= 0) {
                        return false;
                    }

                    if ($satisfiedCourseIds->contains($relatedCourseId)) {
                        return true;
                    }

                    return $requirement->rule_type === CourseRequirement::TypeCorequisite
                        && $concurrentCourseIds->contains($relatedCourseId);
                });

                if (! $groupSatisfied) {
                    if ($ruleType === CourseRequirement::TypeCorequisite) {
                        $hasUnmetCoreq = true;
                    } else {
                        $hasUnmetPrereq = true;
                    }
                }
            }

            $statusCue = null;
            if ($courseId > 0 && $satisfiedCourseIds->contains($courseId)) {
                $statusCue = 'Already passed';
            } elseif ($hasUnmetPrereq && $hasUnmetCoreq) {
                $statusCue = 'Prereq & coreq unmet';
            } elseif ($hasUnmetPrereq) {
                $statusCue = 'Prereq unmet';
            } elseif ($hasUnmetCoreq) {
                $statusCue = 'Coreq unmet';
            }

            return [
                $section->id => collect([
                    $specification?->title,
                    $section->termOffering?->course()?->code,
                    $section->code,
                    $positionLabel,
                    $statusCue,
                ])->filter()->implode(' · '),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @param  array<int, int|string>|null  $selectedSectionIds
     * @return Collection<int, int>
     */
    public function concurrentCourseIds(Enrollment $case, Collection $sections, ?array $selectedSectionIds): Collection
    {
        if ($selectedSectionIds !== null) {
            $selectedIds = collect($selectedSectionIds)->map(fn (mixed $id): int => (int) $id)->filter();

            return $sections
                ->whereIn('id', $selectedIds)
                ->map(fn (Section $section): int => (int) ($section->termOffering?->courseSpecification()?->course_id ?? 0))
                ->filter()
                ->unique()
                ->values();
        }

        if ($case->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return $case->courseEnrollments()
                ->with('termOffering.curriculumEntry.courseSpecification')
                ->where('is_current', true)
                ->where('status', CourseEnrollment::StatusActive)
                ->get()
                ->map(fn (CourseEnrollment $enrollment): int => (int) ($enrollment->termOffering?->courseSpecification()?->course_id ?? 0))
                ->filter()
                ->unique()
                ->values();
        }

        $items = $case->currentProposalVersion?->items;
        if ($items === null) {
            return collect();
        }

        $items->loadMissing('termOffering.curriculumEntry.courseSpecification');

        return $items
            ->map(fn (RegistrationProposalItem $item): int => (int) ($item->termOffering?->courseSpecification()?->course_id ?? 0))
            ->filter()
            ->unique()
            ->values();
    }

    /** @return array<int, string> */
    public function sectionDescriptions(Enrollment $case, ?array $choices = null): array
    {
        $choices ??= $this->forCase($case);

        return $choices['sections']->mapWithKeys(function (Section $section) use ($choices): array {
            $units = (float) $section->termOffering?->courseSpecification()?->credit_units;
            $schedule = $choices['meetings']->get($section->id, collect())->map(function (PublishedTimetableMeeting $meeting): string {
                $day = match ((int) $meeting->day_of_week) {
                    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                    5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday', default => 'Day not recorded',
                };

                return $day.' '.CarbonImmutable::parse($meeting->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($meeting->ends_at)->format('g:i A')
                    .' · '.($meeting->room?->name ?? $meeting->location_label ?? 'Room not recorded');
            })->implode('; ');
            $used = (int) ($choices['reserved'][$section->id] ?? 0) + (int) ($choices['enrolled'][$section->id] ?? 0);
            $remaining = max(0, (int) $section->capacity - $used);
            $seatsLabel = $remaining.' '.str('seat')->plural($remaining).' left';

            return [$section->id => $units.' units · '.($schedule !== '' ? $schedule : 'Externally arranged · no recurring meeting').' · '.$seatsLabel];
        })->all();
    }

    /**
     * Seats still open in a class for this case (#99 Scope 11: shown to students as "28 of 30 seats").
     *
     * @return array{capacity: int, used: int, remaining: int}
     */
    public function seats(Enrollment $case, Section $section): array
    {
        $choices = $this->forCase($case);
        $used = (int) ($choices['reserved'][$section->id] ?? 0) + (int) ($choices['enrolled'][$section->id] ?? 0);

        return ['capacity' => (int) $section->capacity, 'used' => $used, 'remaining' => max(0, (int) $section->capacity - $used)];
    }
}
