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
use App\Models\Term;
use App\Models\TermOffering;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
            $creditedEntryIds = $creditedEntryIds
                ->merge(RegistrationCreditEvaluation::creditedEntryIdsForStudent($case->studentProfile))
                ->merge($eligibility->acceptedProgramShiftCreditEntryIds($case->studentProfile));
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
            $missingPrereqLabels = [];
            $missingCoreqLabels = [];
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
                    $missingGroupCode = $alternatives
                        ->map(fn (CourseRequirement $requirement): ?string => $requirement->relatedCourse?->code)
                        ->filter()
                        ->unique()
                        ->implode(' or ');

                    if ($ruleType === CourseRequirement::TypeCorequisite) {
                        $hasUnmetCoreq = true;
                        if ($missingGroupCode !== '') {
                            $missingCoreqLabels[] = $missingGroupCode;
                        }
                    } else {
                        $hasUnmetPrereq = true;
                        if ($missingGroupCode !== '') {
                            $missingPrereqLabels[] = $missingGroupCode;
                        }
                    }
                }
            }

            $missingPrereqLabels = array_values(array_unique($missingPrereqLabels));
            $missingCoreqLabels = array_values(array_unique($missingCoreqLabels));

            $statusCue = null;
            if ($courseId > 0 && $satisfiedCourseIds->contains($courseId)) {
                $statusCue = 'Already passed';
            } elseif ($hasUnmetPrereq && $hasUnmetCoreq) {
                $missingCodes = array_values(array_unique([...$missingPrereqLabels, ...$missingCoreqLabels]));
                $statusCue = 'Prereq & coreq unmet'.($missingCodes !== [] ? ' (Requires '.implode(', ', $missingCodes).')' : '');
            } elseif ($hasUnmetPrereq) {
                $statusCue = 'Prereq unmet'.($missingPrereqLabels !== [] ? ' (Requires '.implode(', ', $missingPrereqLabels).')' : '');
            } elseif ($hasUnmetCoreq) {
                $statusCue = 'Coreq unmet'.($missingCoreqLabels !== [] ? ' (Requires '.implode(', ', $missingCoreqLabels).')' : '');
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

    /**
     * Whether an option is locked in the subject picker given the currently selected sections.
     *
     * Mutual corequisite pairs stay selectable when both sides are offered with open seats so they
     * do not permanently lock each other out.
     *
     * @param  list<int|string>|null  $selectedSectionIds
     * @param  array<int, string>|null  $options
     */
    public function isSectionLocked(
        Enrollment $case,
        Section $section,
        ?array $selectedSectionIds = null,
        ?array $choices = null,
        ?array $options = null,
    ): bool {
        if ($this->seats($case, $section)['remaining'] <= 0) {
            return true;
        }

        $choices ??= $this->forCase($case);
        $options ??= $this->sectionOptions($case, $selectedSectionIds, $choices);
        $label = (string) ($options[$section->id] ?? '');

        if (str_contains($label, 'Already passed')
            || str_contains($label, 'Prereq unmet')
            || str_contains($label, 'Prereq & coreq unmet')) {
            return true;
        }

        if (str_contains($label, 'Coreq unmet')) {
            return ! $this->hasSelectableMutualCorequisite($case, $section, $selectedSectionIds ?? [], $choices);
        }

        return str_contains($label, 'unmet');
    }

    /**
     * Computes selected units, unit-load ceiling via {@see StudentUnitLoadService}, and schedule clashes.
     *
     * @param  list<int|string>  $selectedSectionIds
     * @return array{
     *     sections: Collection<int, Section>,
     *     selected_units: float,
     *     max_units: float|null,
     *     over_unit_limit: bool,
     *     clashes: list<array{first_code: string, second_code: string, detail: string}>
     * }
     */
    public function evaluateSelection(Enrollment $case, array $selectedSectionIds, ?array $choices = null): array
    {
        $choices ??= $this->forCase($case);
        $selectedIds = collect($selectedSectionIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        /** @var Collection<int, Section> $selectedSections */
        $selectedSections = $choices['sections']->whereIn('id', $selectedIds)->values();
        $selectedUnits = (float) $selectedSections->sum(
            fn (Section $section): float => (float) $section->termOffering?->courseSpecification()?->credit_units,
        );
        $maxUnits = $this->resolveMaxUnits($case, $selectedSections, $choices['sections']);
        $clashes = $this->detectClashes($selectedSections, $choices['meetings']);

        return [
            'sections' => $selectedSections,
            'selected_units' => $selectedUnits,
            'max_units' => $maxUnits,
            'over_unit_limit' => $maxUnits !== null && $selectedUnits > ($maxUnits + 0.0001),
            'clashes' => $clashes,
        ];
    }

    /**
     * @param  list<int|string>  $selectedSectionIds
     * @param  array<string, mixed>  $choices
     */
    private function hasSelectableMutualCorequisite(Enrollment $case, Section $section, array $selectedSectionIds, array $choices): bool
    {
        $specification = $section->termOffering?->courseSpecification();
        $courseId = (int) ($specification?->course_id ?? 0);
        if ($courseId <= 0) {
            return false;
        }

        $satisfiedCourseIds = $choices['satisfiedCourseIds'];
        $concurrentCourseIds = $this->concurrentCourseIds($case, $choices['sections'], $selectedSectionIds);
        $unmetCoreqGroups = ($specification?->requirements ?? collect())
            ->where('state', CourseRequirement::StateActive)
            ->where('rule_type', CourseRequirement::TypeCorequisite)
            ->groupBy('group_key')
            ->reject(fn (Collection $alternatives): bool => $alternatives->contains(function (CourseRequirement $requirement) use ($satisfiedCourseIds, $concurrentCourseIds): bool {
                $relatedCourseId = (int) $requirement->related_course_id;

                return $relatedCourseId > 0
                    && ($satisfiedCourseIds->contains($relatedCourseId) || $concurrentCourseIds->contains($relatedCourseId));
            }));

        if ($unmetCoreqGroups->isEmpty()) {
            return false;
        }

        $openSectionsByCourse = $choices['sections']
            ->filter(fn (Section $candidate): bool => $this->seats($case, $candidate)['remaining'] > 0
                && (int) ($candidate->termOffering?->courseSpecification()?->course_id ?? 0) > 0)
            ->groupBy(fn (Section $candidate): int => (int) $candidate->termOffering?->courseSpecification()?->course_id);

        $allGroupsOffered = $unmetCoreqGroups->every(fn (Collection $alternatives): bool => $alternatives->contains(
            fn (CourseRequirement $requirement): bool => $openSectionsByCourse->has((int) $requirement->related_course_id),
        ));

        if (! $allGroupsOffered) {
            return false;
        }

        return $unmetCoreqGroups->contains(fn (Collection $alternatives): bool => $alternatives->contains(function (CourseRequirement $requirement) use ($openSectionsByCourse, $courseId): bool {
            /** @var Collection<int, Section> $partnerSections */
            $partnerSections = $openSectionsByCourse->get((int) $requirement->related_course_id, collect());

            return $partnerSections->contains(function (Section $partner) use ($courseId): bool {
                $partnerRequirements = $partner->termOffering?->courseSpecification()?->requirements ?? collect();

                return $partnerRequirements->contains(fn (CourseRequirement $partnerRequirement): bool => $partnerRequirement->state === CourseRequirement::StateActive
                    && $partnerRequirement->rule_type === CourseRequirement::TypeCorequisite
                    && (int) $partnerRequirement->related_course_id === $courseId);
            });
        }));
    }

    /**
     * @param  Collection<int, Section>  $selectedSections
     * @param  Collection<int, Section>  $offeredSections
     */
    private function resolveMaxUnits(Enrollment $case, Collection $selectedSections, Collection $offeredSections): ?float
    {
        $case->loadMissing(['term', 'studentProfile', 'admissionApplication']);
        $unitLoad = app(StudentUnitLoadService::class);
        $curriculum = app(RegistrationAcademicEligibilityQuery::class)->resolveStudentCurriculum($case);

        if ($case->term?->type === Term::TypeSpecialTerm) {
            $hasSpecialEntries = $curriculum instanceof CurriculumVersion
                && CurriculumEntry::query()
                    ->where('curriculum_version_id', $curriculum->id)
                    ->where('term_type', Term::TypeSpecialTerm)
                    ->exists();

            if (! $hasSpecialEntries) {
                return StudentUnitLoadService::SummerRetakeUnitCeiling;
            }
        }

        $entries = $curriculum instanceof CurriculumVersion
            ? CurriculumEntry::query()
                ->with('courseSpecification')
                ->where('curriculum_version_id', $curriculum->id)
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
            : collect();

        $confirmedPosition = $this->highestPositionKey(
            $selectedSections->isNotEmpty() ? $selectedSections : $offeredSections,
            $entries,
            $case->term?->type,
        );

        if ($selectedSections->isNotEmpty()) {
            try {
                $snapshot = $unitLoad->snapshotForSections($case, $selectedSections, $confirmedPosition);

                return (float) $snapshot['normal_total'];
            } catch (ValidationException) {
                // Fall back to resolved curriculum term load when sections span unmapped/test fixtures.
            }
        }

        if ($confirmedPosition === null || $entries->isEmpty()) {
            return null;
        }

        $total = (float) $entries
            ->filter(fn (CurriculumEntry $entry): bool => implode('|', [$entry->year_level, $entry->term_label, $entry->term_type]) === $confirmedPosition)
            ->sum(fn (CurriculumEntry $entry): float => (float) $entry->courseSpecification?->credit_units);

        return $total > 0 ? $total : null;
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @param  Collection<int, CurriculumEntry>  $entries
     */
    private function highestPositionKey(Collection $sections, Collection $entries, ?string $termType): ?string
    {
        $eligibility = app(RegistrationAcademicEligibilityQuery::class);
        $resolved = $sections
            ->map(function (Section $section) use ($eligibility, $entries): ?CurriculumEntry {
                $offering = $section->termOffering;
                if ($entries->isNotEmpty()) {
                    $matched = $eligibility->resolveCurriculumEntryFromEntries($entries, $offering, $section);
                    if ($matched instanceof CurriculumEntry) {
                        return $matched;
                    }
                }

                return $offering?->curriculumEntry;
            })
            ->filter(fn (mixed $entry): bool => $entry instanceof CurriculumEntry
                && filled($entry->year_level)
                && filled($entry->term_label)
                && filled($entry->term_type));

        if ($resolved->isEmpty() && $entries->isNotEmpty()) {
            $resolved = $entries->when(
                filled($termType),
                fn (Collection $collection): Collection => $collection->where('term_type', $termType),
            );
        }

        $top = $resolved
            ->sortBy(fn (CurriculumEntry $entry): array => $this->curriculumRank($entry))
            ->last();

        if (! $top instanceof CurriculumEntry) {
            return null;
        }

        return implode('|', [$top->year_level, $top->term_label, $top->term_type]);
    }

    /** @return array{int, int} */
    private function curriculumRank(CurriculumEntry $entry): array
    {
        $ordinal = static function (string $label): int {
            $normalized = strtolower($label);
            if (preg_match('/\d+/', $normalized, $digits) === 1) {
                return (int) $digits[0];
            }

            foreach (['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'summer' => 9, 'special' => 9] as $word => $value) {
                if (str_contains($normalized, $word)) {
                    return $value;
                }
            }

            return 99;
        };

        return [$ordinal((string) $entry->year_level), $ordinal((string) $entry->term_label)];
    }

    /**
     * @param  Collection<int, Section>  $selectedSections
     * @param  Collection<int, Collection<int, PublishedTimetableMeeting>>  $meetings
     * @return list<array{first_code: string, second_code: string, detail: string}>
     */
    private function detectClashes(Collection $selectedSections, Collection $meetings): array
    {
        $clashes = [];
        $list = $selectedSections->values();
        $count = $list->count();

        for ($i = 0; $i < $count; $i++) {
            $first = $list->get($i);
            if (! $first instanceof Section) {
                continue;
            }
            $firstMeetings = $meetings->get($first->id, collect());
            if ($firstMeetings->isEmpty()) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $second = $list->get($j);
                if (! $second instanceof Section) {
                    continue;
                }
                $secondMeetings = $meetings->get($second->id, collect());
                if ($secondMeetings->isEmpty()) {
                    continue;
                }

                foreach ($firstMeetings as $firstMeeting) {
                    $overlapping = $secondMeetings->first(fn (PublishedTimetableMeeting $secondMeeting): bool => (int) $firstMeeting->day_of_week === (int) $secondMeeting->day_of_week
                        && $firstMeeting->starts_at < $secondMeeting->ends_at
                        && $firstMeeting->ends_at > $secondMeeting->starts_at);

                    if ($overlapping instanceof PublishedTimetableMeeting) {
                        $firstCode = (string) ($first->termOffering?->course()?->code ?? $first->code);
                        $secondCode = (string) ($second->termOffering?->course()?->code ?? $second->code);
                        $day = match ((int) $firstMeeting->day_of_week) {
                            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday', default => 'Scheduled day',
                        };
                        $overlapStart = max((string) $firstMeeting->starts_at, (string) $overlapping->starts_at);
                        $overlapEnd = min((string) $firstMeeting->ends_at, (string) $overlapping->ends_at);
                        $window = CarbonImmutable::parse($overlapStart)->format('g:i A').'–'.CarbonImmutable::parse($overlapEnd)->format('g:i A');

                        $clashes[] = [
                            'first_code' => $firstCode,
                            'second_code' => $secondCode,
                            'detail' => "{$firstCode} ({$first->code}) & {$secondCode} ({$second->code}) overlap on {$day} {$window}",
                        ];

                        break 2;
                    }
                }
            }
        }

        return $clashes;
    }
}
