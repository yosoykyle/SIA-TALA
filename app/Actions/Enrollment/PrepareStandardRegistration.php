<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PrepareStandardRegistration
{
    public function __construct(
        private readonly PrepareRegistrationProposal $prepare,
        private readonly PlaceRegistrationProposal $place,
        private readonly CreateAssessmentFromPublishedFeePlan $assess,
        private readonly IssueRegistrationProposal $issue,
        private readonly RegistrationAcademicEligibilityQuery $eligibility,
    ) {}

    public function execute(Enrollment $enrollment, User $actor): Enrollment
    {
        if ($enrollment->canonical_outcome !== Enrollment::OutcomeInProgress
            || $enrollment->selection_basis !== Enrollment::SelectionStandardCurriculum
            || $enrollment->currentProposalVersion?->state === RegistrationProposalVersion::StateConfirmed) {
            return $enrollment;
        }
        $enrollment->loadMissing(['studentProfile', 'term']);
        if ($enrollment->studentProfile !== null
            && $enrollment->term !== null
            && ($this->hasPartialSatisfiedPosition($enrollment->studentProfile, $enrollment->term)
                || $this->isUncreditedContinuingCohort($enrollment->studentProfile, $enrollment->term))) {
            $enrollment->update([
                'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
                'status_reason' => 'Registrar advising is required for the remaining curriculum subjects in this Term.',
            ]);

            return $enrollment->refresh();
        }

        try {
            $proposal = $enrollment->currentProposalVersion;
            if ($proposal === null) {
                $proposal = $this->prepare->execute($enrollment, $actor, $this->sectionIds($enrollment), $enrollment->lock_version);
            }
            if ($proposal->state === RegistrationProposalVersion::StateDraft) {
                $this->place->execute($proposal, $actor);
                $this->assess->execute($enrollment->fresh(), $actor);
                $proposal = $proposal->fresh();
                if ($proposal->state === RegistrationProposalVersion::StateDraft) {
                    $this->issue->issuePrepared($proposal);
                }
            }
        } catch (ValidationException|CalendarGateViolation $exception) {
            $enrollment->refresh()->update(['status_reason' => $exception->getMessage()]);
        }

        return $enrollment->refresh();
    }

    public function hasPartialSatisfiedPosition(StudentProfile $profile, Term $term): bool
    {
        if ($profile->curriculum_version_id === null) {
            return false;
        }

        $curriculum = CurriculumVersion::query()
            ->whereKey($profile->curriculum_version_id)
            ->where('program_id', $profile->program_id)
            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
            ->first();

        if (! $curriculum instanceof CurriculumVersion) {
            return false;
        }

        $satisfied = $this->eligibility->satisfiedCourseIds($profile);

        if ($satisfied->isEmpty()) {
            return false;
        }

        $positions = CurriculumEntry::query()
            ->with('courseSpecification')
            ->where('curriculum_version_id', $curriculum->id)
            ->where('term_type', $term->type)
            ->orderBy('year_level')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CurriculumEntry $entry): string => $entry->year_level.':'.$entry->term_label.':'.$entry->term_type);

        $firstUnsatisfied = $positions->first(
            fn (Collection $group): bool => $group->contains(
                fn (CurriculumEntry $entry): bool => ! $satisfied->contains($entry->courseSpecification?->course_id),
            ),
        );

        return $firstUnsatisfied instanceof Collection
            && $firstUnsatisfied->contains(
                fn (CurriculumEntry $entry): bool => $satisfied->contains($entry->courseSpecification?->course_id),
            );
    }

    /** @return list<int> */
    public function sectionIds(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['admissionApplication', 'studentProfile.entryTerm', 'term']);
        $profile = $enrollment->studentProfile;
        $programId = $profile?->program_id ?? $enrollment->admissionApplication?->program_id;

        if ($profile !== null && $profile->curriculum_version_id !== null) {
            $curricula = CurriculumVersion::query()
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                ->where('program_id', $programId)
                ->whereKey($profile->curriculum_version_id)
                ->get();

            if ($curricula->count() !== 1) {
                $this->unavailable('Registrar must resolve the current assigned curriculum before preparing enrollment.');
            }

            $curriculum = $curricula->sole();
        } else {
            $program = $programId !== null ? Program::query()->find($programId) : null;

            if ($program instanceof Program && $program->curriculumVersionBatches()->exists()) {
                $academicYearId = $profile?->entry_academic_year_id ?? $profile?->entryTerm?->academic_year_id ?? $enrollment->term?->academic_year_id;
                $curriculum = $program->curriculumForAcademicYear($academicYearId);

                if (! $curriculum instanceof CurriculumVersion) {
                    $this->unavailable('Registrar must resolve the current assigned curriculum before preparing enrollment.');
                }
            } else {
                $curricula = CurriculumVersion::query()
                    ->whereIn('state', [CurriculumVersion::StateActive])
                    ->where('program_id', $programId)
                    ->get();

                if ($curricula->count() !== 1) {
                    $this->unavailable('Registrar must resolve the current assigned curriculum before preparing enrollment.');
                }

                $curriculum = $curricula->sole();
            }
        }
        $satisfied = $profile !== null ? $this->eligibility->satisfiedCourseIds($profile) : collect();
        $positions = CurriculumEntry::query()->with('courseSpecification.requirements')
            ->where('curriculum_version_id', $curriculum->id)->where('term_type', $enrollment->term?->type)
            ->orderBy('year_level')->orderBy('sequence')->orderBy('id')->get()
            ->groupBy(fn (CurriculumEntry $entry): string => $entry->year_level.':'.$entry->term_label.':'.$entry->term_type);
        $entries = $positions->first(fn (Collection $group): bool => $group->contains(fn (CurriculumEntry $entry): bool => ! $satisfied->contains($entry->courseSpecification?->course_id)));
        if (! $entries instanceof Collection || $entries->isEmpty()
            || $entries->contains(fn (CurriculumEntry $entry): bool => $satisfied->contains($entry->courseSpecification?->course_id))) {
            $this->unavailable('Registrar advising is required for the remaining curriculum subjects in this Term.');
        }
        $timetable = PublishedTimetableVersion::query()->where('term_id', $enrollment->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)->latest('version')->first();
        if ($timetable === null) {
            $this->unavailable('Registrar must publish the exact-Term classes before enrollment can be prepared.');
        }
        $publishedSections = PublishedTimetableMeeting::query()->where('published_timetable_version_id', $timetable->id)->pluck('section_id');
        $targetYearLevel = TermCohort::parseYearLevelValue($entries->first()?->year_level);
        $curriculumEntriesExistCache = [];
        $matchesCohortScope = function (TermCohort $cohort) use ($enrollment, $curriculum, $targetYearLevel, &$curriculumEntriesExistCache): bool {
            if ((int) $cohort->term_id !== (int) $enrollment->term_id
                || (int) $cohort->program_id !== (int) $curriculum->program_id) {
                return false;
            }

            if ($targetYearLevel !== null
                && $cohort->year_level !== null
                && (int) $cohort->year_level !== $targetYearLevel) {
                return false;
            }

            $cohortCurriculumId = (int) $cohort->curriculum_version_id;
            if ($cohortCurriculumId === (int) $curriculum->id) {
                return true;
            }

            if (! array_key_exists($cohortCurriculumId, $curriculumEntriesExistCache)) {
                $curriculumEntriesExistCache[$cohortCurriculumId] = CurriculumEntry::query()
                    ->where('curriculum_version_id', $cohortCurriculumId)
                    ->where('term_type', $enrollment->term?->type)
                    ->exists();
            }

            // Preserve compatibility with legacy/test cohorts whose placeholder curriculum has no entries.
            return ! $curriculumEntriesExistCache[$cohortCurriculumId];
        };

        $sections = Section::query()->with([
            'cohorts',
            'courseRevision',
            'termOffering.curriculumEntry.courseSpecification.requirements',
        ])
            ->where('state', Section::StateOpen)
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $enrollment->term_id)
                ->where('state', TermOffering::StateScheduled))
            ->get()
            ->filter(function (Section $section) use ($publishedSections, $entries, $matchesCohortScope): bool {
                $hasSchedule = $publishedSections->contains($section->id)
                    || $section->termOffering?->curriculumEntry?->courseSpecification?->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged;
                if (! $hasSchedule) {
                    return false;
                }

                if (! $this->eligibility->resolveCurriculumEntryFromEntries($entries, $section->termOffering, $section) instanceof CurriculumEntry) {
                    return false;
                }

                return $section->cohorts->isEmpty()
                    || $section->cohorts->contains(fn (TermCohort $cohort): bool => $matchesCohortScope($cohort));
            })
            ->values();
        $expected = $entries->pluck('id')->sort()->values()->all();
        $exactBlocks = collect();
        $fallbackBlocks = collect([$sections]);
        foreach ($sections->pluck('cohorts')->flatten()->unique('id') as $cohort) {
            if ($cohort instanceof TermCohort
                && $cohort->state === 'Confirmed'
                && $matchesCohortScope($cohort)) {
                $cohortSections = $sections->filter(fn (Section $section): bool => $section->cohorts->contains('id', $cohort->id));
                if ((int) $cohort->curriculum_version_id === (int) $curriculum->id) {
                    $exactBlocks->push($cohortSections);
                } else {
                    $fallbackBlocks->push($cohortSections);
                }
            }
        }
        $blocks = $exactBlocks->concat($fallbackBlocks)->filter(function (Collection $block) use ($entries, $expected): bool {
            if ($block->count() !== count($expected)) {
                return false;
            }

            $matchedIds = $block
                ->map(fn (Section $section): ?int => $this->eligibility->resolveCurriculumEntryFromEntries($entries, $section->termOffering, $section)?->id)
                ->filter()
                ->map(fn (int $id): int => $id)
                ->sort()
                ->values()
                ->all();

            return $matchedIds === $expected;
        })
            ->unique(fn (Collection $block): string => $block->pluck('id')->sort()->implode(','))->values();
        $valid = $blocks->filter(function (Collection $block) use ($enrollment, $curriculum, $timetable): bool {
            if (! $this->eligibility->passes($enrollment, $curriculum, $block->pluck('termOffering')->values())) {
                return false;
            }
            $meetings = PublishedTimetableMeeting::query()->where('published_timetable_version_id', $timetable->id)->whereIn('section_id', $block->pluck('id'))->get();
            foreach ($meetings as $meeting) {
                if ($meetings->contains(fn ($other): bool => $other->id !== $meeting->id && $other->day_of_week === $meeting->day_of_week
                    && $other->starts_at < $meeting->ends_at && $other->ends_at > $meeting->starts_at)) {
                    return false;
                }
            }

            return $block->every(function (Section $section) use ($enrollment): bool {
                $reserved = EnrollmentSeatReservation::query()->where('section_id', $section->id)
                    ->where('enrollment_id', '!=', $enrollment->id)->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->count();
                $official = CourseEnrollment::query()->where('section_id', $section->id)->where('is_current', true)->where('status', CourseEnrollment::StatusActive)->count();

                return $reserved + $official < $section->capacity;
            });
        })->values();
        if ($valid->isNotEmpty()) {
            return $this->roomiestBlock($valid, $enrollment)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        }
        if ($valid->isEmpty() && $blocks->count() === 1) {
            return $blocks->sole()->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        }
        $this->unavailable('Registrar must choose a complete valid class block or advise the affected subjects; enrollment has not been confirmed.');
    }

    /**
     * #103: when several complete blocks are valid, take the one whose fullest class has the most
     * open seats (then the most open seats overall, then the earliest class) instead of stopping.
     *
     * @param  Collection<int, Collection<int, Section>>  $blocks
     * @return Collection<int, Section>
     */
    private function roomiestBlock(Collection $blocks, Enrollment $enrollment): Collection
    {
        if ($blocks->count() === 1) {
            return $blocks->sole();
        }

        $remaining = function (Section $section) use ($enrollment): int {
            $reserved = EnrollmentSeatReservation::query()->where('section_id', $section->id)
                ->where('enrollment_id', '!=', $enrollment->id)->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->count();
            $official = CourseEnrollment::query()->where('section_id', $section->id)->where('is_current', true)->where('status', CourseEnrollment::StatusActive)->count();

            return max(0, (int) $section->capacity - $reserved - $official);
        };

        return $blocks
            ->map(fn (Collection $block): array => [
                'block' => $block,
                'fullest' => $block->map($remaining)->min(),
                'total' => $block->map($remaining)->sum(),
                'first' => (int) $block->min('id'),
            ])
            ->sort(fn (array $a, array $b): int => [$b['fullest'], $b['total'], $a['first']] <=> [$a['fullest'], $a['total'], $b['first']])
            ->first()['block'];
    }

    private function isUncreditedContinuingCohort(StudentProfile $profile, Term $term): bool
    {
        if ($profile->applicant_intake_id !== null || $profile->entry_academic_year_id === null) {
            return false;
        }

        if ((int) $profile->entry_academic_year_id === (int) $term->academic_year_id) {
            return false;
        }

        return $this->eligibility->satisfiedCourseIds($profile)->isEmpty();
    }

    private function unavailable(string $reason): never
    {
        throw ValidationException::withMessages(['preparation' => $reason]);
    }
}
