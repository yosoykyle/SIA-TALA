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
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
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
        try {
            $proposal = $enrollment->currentProposalVersion;
            if ($proposal === null) {
                $proposal = $this->prepare->execute($enrollment, $actor, $this->sectionIds($enrollment), $enrollment->lock_version);
            }
            if ($proposal->state === RegistrationProposalVersion::StateDraft) {
                $this->place->execute($proposal, $actor);
                $this->assess->execute($enrollment->fresh(), $actor);
                $this->issue->issuePrepared($proposal->fresh());
            }
        } catch (ValidationException|CalendarGateViolation $exception) {
            $enrollment->refresh()->update(['status_reason' => $exception->getMessage()]);
        }

        return $enrollment->refresh();
    }

    /** @return list<int> */
    public function sectionIds(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['admissionApplication', 'studentProfile', 'term']);
        $profile = $enrollment->studentProfile;
        $curricula = CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)
            ->where('program_id', $profile?->program_id ?? $enrollment->admissionApplication?->program_id)
            ->when($profile !== null, fn ($query) => $query->whereKey($profile->curriculum_version_id))->get();
        if ($curricula->count() !== 1) {
            $this->unavailable('Registrar must resolve the current assigned curriculum before preparing enrollment.');
        }
        $curriculum = $curricula->sole();
        $satisfied = $profile !== null ? $this->eligibility->satisfiedCourseIds($profile) : collect();
        $positions = CurriculumEntry::query()->with('courseSpecification')
            ->where('curriculum_version_id', $curriculum->id)->where('term_type', $enrollment->term?->type)
            ->orderBy('sequence')->orderBy('id')->get()
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
        $sections = Section::query()->with(['cohorts', 'termOffering.curriculumEntry.courseSpecification'])
            ->where('state', Section::StateOpen)
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $enrollment->term_id)
                ->where('state', TermOffering::StateScheduled)->whereIn('curriculum_entry_id', $entries->pluck('id')))
            ->get()->filter(fn (Section $section): bool => $publishedSections->contains($section->id)
                || $section->termOffering?->curriculumEntry?->courseSpecification?->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged);
        $expected = $entries->pluck('id')->sort()->values()->all();
        $blocks = collect([$sections]);
        foreach ($sections->pluck('cohorts')->flatten()->unique('id') as $cohort) {
            if ((int) $cohort->term_id === (int) $enrollment->term_id && $cohort->state === 'Confirmed') {
                $blocks->push($sections->filter(fn (Section $section): bool => $section->cohorts->contains('id', $cohort->id)));
            }
        }
        $blocks = $blocks->filter(fn (Collection $block): bool => $block->pluck('termOffering.curriculum_entry_id')->sort()->values()->all() === $expected)
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
        if ($valid->count() === 1) {
            return $valid->sole()->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        }
        if ($valid->isEmpty() && $blocks->count() === 1) {
            return $blocks->sole()->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        }
        $this->unavailable('Registrar must choose a complete valid class block or advise the affected subjects; enrollment has not been confirmed.');
    }

    private function unavailable(string $reason): never
    {
        throw ValidationException::withMessages(['preparation' => $reason]);
    }
}
