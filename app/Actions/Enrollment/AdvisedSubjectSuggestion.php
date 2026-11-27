<?php

namespace App\Actions\Enrollment;

use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\IrregularEnrollmentRequest;
use App\Models\PublishedTimetableMeeting;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Support\Collection;

/**
 * #103 Scope 2: the subjects TALA suggests for an Individually Advised case.
 *
 * Nothing is stored or reserved. The Registrar approves the suggestion (or adjusts it), and the
 * ordinary proposal and placement rules then validate it like any other selection.
 */
class AdvisedSubjectSuggestion
{
    public const SourceSuggested = 'suggested';

    public const SourceRequest = 'request';

    public const ReasonEligible = 'Eligible';

    public const ReasonRequested = 'Requested by the student';

    public const ReasonPrerequisite = 'Prerequisite not met';

    public const ReasonCorequisite = 'Corequisite not taken this term';

    public const ReasonFull = 'All classes full';

    public const ReasonClash = 'Schedule clash';

    public const ReasonOverLoad = 'Over unit load';

    public const ReasonNotOffered = 'Not offered this term';

    public function __construct(
        private readonly RegistrationClassChoices $classChoices,
        private readonly RegistrationAcademicEligibilityQuery $eligibility,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     section_ids: list<int>,
     *     curriculum_position: string|null,
     *     position_label: string|null,
     *     normal_units: float|null,
     *     selected_units: float,
     *     picked: list<array{section_id: int, code: string, title: string, class: string, units: float, reason: string}>,
     *     left_out: list<array{code: string, title: string, reason: string}>
     * }|null
     */
    public function forCase(Enrollment $case): ?array
    {
        if ($case->canonical_outcome !== Enrollment::OutcomeInProgress
            || $case->selection_basis !== Enrollment::SelectionIndividuallyAdvised
            || $case->current_proposal_version_id !== null) {
            return null;
        }

        $case->loadMissing(['term', 'studentProfile', 'admissionApplication']);
        $choices = $this->classChoices->forCase($case);
        /** @var Collection<int, Section> $sections */
        $sections = $choices['sections'];
        if ($sections->isEmpty()) {
            return null;
        }

        $curriculum = $this->eligibility->resolveStudentCurriculum(
            $case,
            (int) ($sections->first()?->termOffering?->curriculumEntry?->curriculum_version_id ?? 0) ?: null,
        );
        $entries = $curriculum instanceof CurriculumVersion
            ? CurriculumEntry::query()->with('courseSpecification.course', 'courseSpecification.requirements')
                ->where('curriculum_version_id', $curriculum->id)->orderBy('sequence')->orderBy('id')->get()
            : collect();
        $candidates = $sections->map(function (Section $section) use ($entries, $choices): array {
            $entry = $entries->isNotEmpty()
                ? ($this->eligibility->resolveCurriculumEntryFromEntries($entries, $section->termOffering, $section) ?? $section->termOffering?->curriculumEntry)
                : $section->termOffering?->curriculumEntry;
            $specification = $section->termOffering?->courseSpecification();
            $courseId = (int) ($specification?->course_id ?? 0);
            $used = (int) ($choices['reserved'][$section->id] ?? 0) + (int) ($choices['enrolled'][$section->id] ?? 0);

            return [
                'section' => $section,
                'entry' => $entry,
                'course_id' => $courseId,
                'code' => (string) ($section->termOffering?->course()?->code ?? $specification?->course?->code ?? ''),
                'title' => (string) ($specification?->title ?? ''),
                'units' => (float) ($specification?->credit_units ?? 0),
                'seats' => max(0, (int) $section->capacity - $used),
                'rank' => $this->rank($entry),
                'sequence' => (int) ($entry?->sequence ?? PHP_INT_MAX),
                'unlocks' => $entries->filter(fn (CurriculumEntry $other): bool => ($other->courseSpecification?->requirements ?? collect())
                    ->where('state', CourseRequirement::StateActive)
                    ->where('rule_type', CourseRequirement::TypePrerequisite)
                    ->contains('related_course_id', $courseId))->count(),
                'position' => $entry !== null ? implode('|', [$entry->year_level, $entry->term_label, $entry->term_type]) : null,
            ];
        })->filter(fn (array $candidate): bool => $candidate['course_id'] > 0
            && ! $choices['satisfiedCourseIds']->contains($candidate['course_id']));

        $request = IrregularEnrollmentRequest::query()
            ->where('enrollment_id', $case->id)
            ->where('state', IrregularEnrollmentRequest::StateSubmitted)
            ->first();

        if ($request instanceof IrregularEnrollmentRequest) {
            $picked = $candidates->whereIn('section.id', $request->sectionIds())->values();
            $ceiling = $this->ceiling($picked, $entries, $case);

            return $this->result(self::SourceRequest, $picked, $ceiling, [], self::ReasonRequested, $choices);
        }

        $byCourse = $candidates->groupBy('course_id')
            ->sortBy(fn (Collection $group): array => [
                ...$group->first()['rank'],
                -$group->first()['unlocks'],
                $group->first()['sequence'],
            ]);
        $window = $this->ceiling($candidates->values(), $entries, $case);
        [$picked, $leftOut] = $this->select($byCourse, $window['units'], $choices);
        $ceiling = $window;

        // When every pick sits in one lower curriculum term, that term's load is the limit: pick again against it.
        $positions = $picked->pluck('position')->filter()->unique();
        if ($positions->count() === 1 && $positions->first() !== $window['position']) {
            $ceiling = $this->positionCeiling($positions->first(), $entries, $case);
            [$picked, $leftOut] = $this->select($byCourse, $ceiling['units'], $choices);
        }

        $offeredCourseIds = $candidates->pluck('course_id')->merge($choices['satisfiedCourseIds']);
        $ceilingEntry = $window['position'] !== null
            ? $entries->first(fn (CurriculumEntry $entry): bool => implode('|', [$entry->year_level, $entry->term_label, $entry->term_type]) === $window['position'])
            : null;
        foreach ($entries as $entry) {
            $courseId = (int) ($entry->courseSpecification?->course_id ?? 0);
            if ($courseId > 0 && $ceilingEntry !== null && $entry->term_type === $case->term?->type
                && $this->rank($entry) <= $this->rank($ceilingEntry)
                && ! $offeredCourseIds->contains($courseId)) {
                $leftOut[] = [
                    'code' => (string) ($entry->courseSpecification?->course?->code ?? ''),
                    'title' => (string) ($entry->courseSpecification?->title ?? ''),
                    'reason' => self::ReasonNotOffered,
                ];
            }
        }

        return $this->result(self::SourceSuggested, $picked->values(), $ceiling, $leftOut, self::ReasonEligible, $choices);
    }

    /**
     * One class per subject, in curriculum order, skipping what cannot be taken now.
     *
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $byCourse
     * @param  array<string, mixed>  $choices
     * @return array{0: Collection<int, array<string, mixed>>, 1: list<array{code: string, title: string, reason: string}>}
     */
    private function select(Collection $byCourse, ?float $unitLimit, array $choices): array
    {
        $leftOut = [];
        $picked = collect();
        foreach ($byCourse as $group) {
            $first = $group->first();
            if (! $this->prerequisitesMet($first['section'], $choices['satisfiedCourseIds'])) {
                $leftOut[] = $this->leftOut($first, self::ReasonPrerequisite);

                continue;
            }

            $open = $group->filter(fn (array $candidate): bool => $candidate['seats'] > 0);
            if ($open->isEmpty()) {
                $leftOut[] = $this->leftOut($first, self::ReasonFull);

                continue;
            }

            $fitting = $open->reject(fn (array $candidate): bool => $this->clashes($candidate['section'], $picked, $choices['meetings']))
                ->sortByDesc('seats');
            if ($fitting->isEmpty()) {
                $leftOut[] = $this->leftOut($first, self::ReasonClash);

                continue;
            }

            if ($unitLimit !== null && $picked->sum('units') + $first['units'] > $unitLimit) {
                $leftOut[] = $this->leftOut($first, self::ReasonOverLoad);

                continue;
            }

            $picked->push($fitting->first());
        }

        // A corequisite must be satisfied already or taken in the same term.
        do {
            $pickedCourseIds = $picked->pluck('course_id');
            $missingCoreq = $picked->first(fn (array $candidate): bool => ! $this->corequisitesMet(
                $candidate['section'],
                $choices['satisfiedCourseIds']->merge($pickedCourseIds),
            ));
            if ($missingCoreq !== null) {
                $leftOut[] = $this->leftOut($missingCoreq, self::ReasonCorequisite);
                $picked = $picked->reject(fn (array $candidate): bool => $candidate['section']->is($missingCoreq['section']))->values();
            }
        } while ($missingCoreq !== null);

        return [$picked->values(), $leftOut];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $picked
     * @param  array{position: string|null, units: float|null, label: string|null}  $ceiling
     * @param  list<array{code: string, title: string, reason: string}>  $leftOut
     * @param  array<string, mixed>  $choices
     * @return array<string, mixed>
     */
    private function result(string $source, Collection $picked, array $ceiling, array $leftOut, string $reason, array $choices): array
    {
        $positions = $picked->pluck('position')->filter()->unique();

        return [
            'source' => $source,
            'section_ids' => $picked->map(fn (array $candidate): int => (int) $candidate['section']->id)->values()->all(),
            'curriculum_position' => $positions->count() > 1 ? $ceiling['position'] : null,
            'position_label' => $ceiling['label'],
            'normal_units' => $ceiling['units'],
            'selected_units' => (float) $picked->sum('units'),
            'picked' => $picked->map(fn (array $candidate): array => [
                'section_id' => (int) $candidate['section']->id,
                'code' => $candidate['code'],
                'title' => $candidate['title'],
                'class' => (string) $candidate['section']->code,
                'units' => $candidate['units'],
                'reason' => $reason,
            ])->values()->all(),
            'left_out' => collect($leftOut)->unique(fn (array $row): string => $row['code'].'|'.$row['reason'])->values()->all(),
        ];
    }

    /**
     * The highest curriculum term among the candidates sets the normal unit load.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  Collection<int, CurriculumEntry>  $entries
     * @return array{position: string|null, units: float|null, label: string|null}
     */
    private function ceiling(Collection $candidates, Collection $entries, Enrollment $case): array
    {
        $position = $candidates->filter(fn (array $candidate): bool => $candidate['position'] !== null)
            ->sortBy(fn (array $candidate): array => $candidate['rank'])->last()['position'] ?? null;

        return $this->positionCeiling($position, $entries, $case);
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $entries
     * @return array{position: string|null, units: float|null, label: string|null}
     */
    private function positionCeiling(?string $position, Collection $entries, Enrollment $case): array
    {
        if ($case->term?->type === Term::TypeSpecialTerm && ! $entries->contains('term_type', Term::TypeSpecialTerm)) {
            return [
                'position' => implode('|', ['Special Term', 'Special Term', Term::TypeSpecialTerm]),
                'units' => StudentUnitLoadService::SummerRetakeUnitCeiling,
                'label' => 'Special Term',
            ];
        }

        if ($position === null) {
            return ['position' => null, 'units' => null, 'label' => null];
        }

        $positionEntries = $entries->filter(fn (CurriculumEntry $entry): bool => implode('|', [$entry->year_level, $entry->term_label, $entry->term_type]) === $position);
        $units = (float) $positionEntries->sum(fn (CurriculumEntry $entry): float => (float) $entry->courseSpecification?->credit_units);
        $first = $positionEntries->first();

        return [
            'position' => $position,
            'units' => $units > 0 ? $units : null,
            'label' => $first !== null ? trim($first->year_level.', '.$first->term_label, ', ') : null,
        ];
    }

    /**
     * Curriculum order of a subject: year level, then term within the year.
     *
     * @return array{int, int}
     */
    private function rank(?CurriculumEntry $entry): array
    {
        $ordinal = function (string $label): int {
            $label = strtolower($label);
            if (preg_match('/\d+/', $label, $digits) === 1) {
                return (int) $digits[0];
            }

            foreach (['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'summer' => 9, 'special' => 9] as $word => $value) {
                if (str_contains($label, $word)) {
                    return $value;
                }
            }

            return 99;
        };

        return $entry === null ? [99, 99] : [$ordinal((string) $entry->year_level), $ordinal((string) $entry->term_label)];
    }

    /** @param Collection<int, int> $satisfiedCourseIds */
    private function prerequisitesMet(Section $section, Collection $satisfiedCourseIds): bool
    {
        return $this->requirementGroupsMet($section, CourseRequirement::TypePrerequisite, $satisfiedCourseIds);
    }

    /** @param Collection<int, int> $availableCourseIds */
    private function corequisitesMet(Section $section, Collection $availableCourseIds): bool
    {
        return $this->requirementGroupsMet($section, CourseRequirement::TypeCorequisite, $availableCourseIds);
    }

    /** @param Collection<int, int> $courseIds */
    private function requirementGroupsMet(Section $section, string $ruleType, Collection $courseIds): bool
    {
        return ($section->termOffering?->courseSpecification()?->requirements ?? collect())
            ->where('state', CourseRequirement::StateActive)
            ->where('rule_type', $ruleType)
            ->groupBy('group_key')
            ->every(fn (Collection $alternatives): bool => $alternatives->contains(
                fn (CourseRequirement $requirement): bool => $courseIds->contains((int) $requirement->related_course_id),
            ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $picked
     * @param  Collection<int, Collection<int, PublishedTimetableMeeting>>  $meetings
     */
    private function clashes(Section $section, Collection $picked, Collection $meetings): bool
    {
        $own = $meetings->get($section->id, collect());

        return $picked->contains(fn (array $candidate): bool => $own->contains(
            fn (PublishedTimetableMeeting $meeting): bool => $meetings->get($candidate['section']->id, collect())->contains(
                fn (PublishedTimetableMeeting $other): bool => (int) $other->day_of_week === (int) $meeting->day_of_week
                    && $other->starts_at < $meeting->ends_at
                    && $other->ends_at > $meeting->starts_at,
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{code: string, title: string, reason: string}
     */
    private function leftOut(array $candidate, string $reason): array
    {
        return ['code' => $candidate['code'], 'title' => $candidate['title'], 'reason' => $reason];
    }
}
