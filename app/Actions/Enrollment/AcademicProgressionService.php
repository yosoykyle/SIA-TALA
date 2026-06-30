<?php

namespace App\Actions\Enrollment;

use App\Actions\Grades\GradePolicyService;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\EnrollmentException;
use App\Models\GradeRosterRow;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AcademicProgressionService
{
    public function __construct(private readonly GradePolicyService $gradePolicy) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(StudentProfile $studentProfile, ?Term $targetTerm = null): array
    {
        $entries = CurriculumEntry::query()
            ->with(['courseSpecification.course', 'courseSpecification.requirements.relatedCourse'])
            ->where('curriculum_version_id', $studentProfile->curriculum_version_id)
            ->orderBy('year_level')
            ->orderBy('term_label')
            ->orderBy('sequence')
            ->get();

        if ($entries->isEmpty()) {
            return $this->missingBaselineResult($studentProfile);
        }

        $releasedRows = $this->releasedRows($studentProfile);
        $currentCourseIds = $this->currentCourseIds($studentProfile, $targetTerm);
        $shiftCredits = $this->acceptedShiftCredits($studentProfile);
        $exceptions = $this->activeExceptions($studentProfile, $targetTerm);
        $offerings = $this->availableOfferings($entries, $targetTerm);
        $completed = [];
        $backSubjects = [];
        $blockers = [];
        $suggestions = [];
        $gwaPoints = 0.0;
        $gwaUnits = 0.0;

        foreach ($entries as $entry) {
            $course = $entry->courseSpecification?->course;

            if ($course === null) {
                continue;
            }

            $row = $releasedRows->get((int) $course->id);
            $credit = $shiftCredits->get((int) $entry->id);
            $numericGrade = $credit instanceof ProgramShiftCreditEntry ? $credit->numeric_grade : null;
            $numericGrade ??= $row instanceof GradeRosterRow && is_numeric($row->current_outcome_code) ? $row->current_outcome_code : null;

            if ($numericGrade !== null) {
                $units = (float) $entry->courseSpecification->credit_units;
                $gwaPoints += (float) $numericGrade * $units;
                $gwaUnits += $units;
            }
            $completion = $this->completionSource($row, $credit);

            if ($completion !== null) {
                $completed[] = $this->fact($entry, 'completed', $completion);

                continue;
            }

            $outcomeBlocker = $this->outcomeBlocker($entry, $row);

            if ($outcomeBlocker !== null) {
                $blockers[] = $outcomeBlocker;

                continue;
            }

            if ($this->isBackSubject($row, $studentProfile, (int) $course->id)) {
                $backSubjects[] = $this->fact($entry, 'back_subject', $this->rowSource($row));
            }

            $offering = $offerings->get((int) $entry->id);

            if (! $offering instanceof TermOffering) {
                continue;
            }

            $requirementResult = $this->requirementsPass(
                $entry,
                $offering,
                $releasedRows,
                $shiftCredits,
                $currentCourseIds,
                $exceptions,
            );

            if ($requirementResult['passed']) {
                $suggestions[] = [
                    ...$this->fact($entry, 'suggested', null),
                    'term_offering_id' => (int) $offering->id,
                    'offering_category' => $offering->category,
                ];
            } else {
                $blockers = [...$blockers, ...$requirementResult['blockers']];
            }
        }

        $standing = $this->recommendedStanding($studentProfile, $entries, $completed, $backSubjects, $blockers, $currentCourseIds);

        return [
            'student_profile_id' => (int) $studentProfile->id,
            'curriculum_version_id' => (int) $studentProfile->curriculum_version_id,
            'curriculum_length' => $entries->pluck('year_level')->unique()->count(),
            'standing' => $standing,
            'authorized_standing' => $studentProfile->academic_standing,
            'completed' => $completed,
            'back_subjects' => $backSubjects,
            'blockers' => collect($blockers)->unique(fn (array $item): string => $item['key'])->values()->all(),
            'suggestions' => $suggestions,
            'current_course_ids' => $currentCourseIds->values()->all(),
            'gwa' => $gwaUnits > 0 ? number_format($gwaPoints / $gwaUnits, 2, '.', '') : null,
            'facts' => [
                'required_count' => $entries->where('requirement_group', CurriculumEntry::RequirementGroupRequired)->count(),
                'completed_count' => count($completed),
                'back_subject_count' => count($backSubjects),
                'blocker_count' => count($blockers),
            ],
        ];
    }

    public function confirmStanding(StudentProfile $studentProfile, string $standing, User $actor, string $reason): StudentProfile
    {
        if (! $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin])) {
            throw new AuthorizationException('Only an authorized Registrar may confirm academic standing.');
        }

        if (! in_array($standing, self::standingValues(), true) || trim($reason) === '') {
            throw new RuntimeException('A valid standing and reason are required.');
        }

        return DB::transaction(function () use ($studentProfile, $standing, $actor, $reason): StudentProfile {
            $locked = StudentProfile::query()->lockForUpdate()->findOrFail($studentProfile->id);
            $previous = $locked->academic_standing;
            $locked->update(['academic_standing' => $standing]);

            DB::table('activity_log')->insert([
                'log_name' => 'academic_progression',
                'description' => 'Academic standing confirmed.',
                'subject_type' => StudentProfile::class,
                'subject_id' => $locked->id,
                'event' => 'academic_standing_confirmed',
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'properties' => json_encode(['previous' => $previous, 'current' => $standing, 'reason' => $reason]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    /** @return list<string> */
    public static function standingValues(): array
    {
        return [
            StudentProfile::StandingRegular, StudentProfile::StandingIrregular,
            StudentProfile::StandingProbationary, StudentProfile::StandingDeficient,
            StudentProfile::StandingBlockedByPrerequisite, StudentProfile::StandingMustRepeatYear,
            StudentProfile::StandingCompletionCandidate, StudentProfile::StandingGraduationCandidate,
            StudentProfile::StandingNotYetEvaluated,
        ];
    }

    /** @return Collection<int, GradeRosterRow> */
    private function releasedRows(StudentProfile $studentProfile): Collection
    {
        return GradeRosterRow::query()
            ->with('courseEnrollment.termOffering.curriculumEntry.courseSpecification.course')
            ->whereNotNull('released_at')
            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('student_profile_id', $studentProfile->id))
            ->latest('released_at')
            ->latest('id')
            ->get()
            ->toBase()
            ->mapWithKeys(function (GradeRosterRow $row): array {
                $courseId = $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course_id;

                return $courseId === null ? [] : [(int) $courseId => $row];
            });
    }

    /** @return Collection<int, int> */
    private function currentCourseIds(StudentProfile $studentProfile, ?Term $term): Collection
    {
        return CourseEnrollment::query()
            ->where('status', CourseEnrollment::StatusActive)
            ->whereHas('enrollment', fn ($query) => $query
                ->where('student_profile_id', $studentProfile->id)
                ->when($term, fn ($query) => $query->where('term_id', $term->id)))
            ->with('termOffering.curriculumEntry.courseSpecification')
            ->get()
            ->pluck('termOffering.curriculumEntry.courseSpecification.course_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
    }

    /** @return Collection<int, ProgramShiftCreditEntry> */
    private function acceptedShiftCredits(StudentProfile $studentProfile): Collection
    {
        return ProgramShiftCreditEntry::query()
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $studentProfile->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->keyBy('curriculum_entry_id');
    }

    /** @return Collection<int, EnrollmentException> */
    private function activeExceptions(StudentProfile $studentProfile, ?Term $term): Collection
    {
        return EnrollmentException::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('state', EnrollmentException::StateActive)
            ->when($term, fn ($query) => $query->where('term_id', $term->id))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->toBase();
    }

    /** @return Collection<int, TermOffering> */
    private function availableOfferings(EloquentCollection $entries, ?Term $term): Collection
    {
        if (! $term instanceof Term) {
            return collect();
        }

        return TermOffering::query()
            ->where('term_id', $term->id)
            ->whereIn('curriculum_entry_id', $entries->modelKeys())
            ->where('state', '!=', TermOffering::StateCancelled)
            ->orderByRaw("CASE WHEN category = 'REGULAR' THEN 0 ELSE 1 END")
            ->get()
            ->keyBy('curriculum_entry_id');
    }

    /** @return array{passed:bool,blockers:list<array<string,mixed>>} */
    private function requirementsPass(
        CurriculumEntry $entry,
        TermOffering $offering,
        Collection $rows,
        Collection $credits,
        Collection $currentCourseIds,
        Collection $exceptions,
    ): array {
        $requirements = $entry->courseSpecification->requirements
            ->whereIn('rule_type', [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite])
            ->where('state', CourseRequirement::StateActive)
            ->groupBy(fn (CourseRequirement $requirement): string => $requirement->rule_type.':'.$requirement->group_key);
        $blockers = [];

        foreach ($requirements as $groupKey => $alternatives) {
            $passed = $alternatives->contains(fn (CourseRequirement $requirement): bool => $this->requirementAlternativePasses($requirement, $rows, $credits, $currentCourseIds));
            $exception = $exceptions->first(fn (EnrollmentException $item): bool => (int) $item->target_term_offering_id === (int) $offering->id
                && in_array($item->exception_type, [EnrollmentException::TypePrerequisite, EnrollmentException::TypeCorequisite], true)
                && in_array($item->original_rule, [$groupKey, $alternatives->first()?->group_key], true));

            if (! $passed && ! $exception instanceof EnrollmentException) {
                $blockers[] = [
                    'key' => $offering->id.':'.$groupKey,
                    'kind' => 'prerequisite',
                    'term_offering_id' => (int) $offering->id,
                    'course_id' => (int) $entry->courseSpecification->course_id,
                    'course_code' => $entry->courseSpecification->course->code,
                    'rule' => $groupKey,
                    'alternatives' => $alternatives->pluck('relatedCourse.code')->filter()->values()->all(),
                    'alternative_blockers' => $alternatives
                        ->map(fn ($requirement): array => $this->alternativeBlocker($requirement, $rows))
                        ->values()
                        ->all(),
                ];
            }
        }

        return ['passed' => $blockers === [], 'blockers' => $blockers];
    }

    private function requirementAlternativePasses(CourseRequirement $requirement, Collection $rows, Collection $credits, Collection $currentCourseIds): bool
    {
        if ($requirement->rule_type === CourseRequirement::TypeCorequisite && $currentCourseIds->contains((int) $requirement->related_course_id)) {
            return true;
        }

        $row = $rows->get((int) $requirement->related_course_id);

        if ($row instanceof GradeRosterRow) {
            if ($row->current_outcome_code === 'TC') {
                return $requirement->accepts_transfer_credit;
            }

            return $this->numericOutcomeMeets($row, $requirement->minimum_grade);
        }

        return $credits->contains(fn (ProgramShiftCreditEntry $credit): bool => (int) $credit->source_course_id === (int) $requirement->related_course_id
            && ($credit->numeric_grade === null || $this->numericGradeMeets((string) $credit->numeric_grade, $requirement->minimum_grade)));
    }

    private function requirementReason(CourseRequirement $requirement, Collection $rows): string
    {
        $row = $rows->get((int) $requirement->related_course_id);

        if (! $row instanceof GradeRosterRow) {
            return 'missing_history';
        }

        return match ($row->current_outcome_code) {
            'INC' => 'active_inc',
            'P' => 'pending_grade',
            default => 'failed',
        };
    }

    /** @return array<string,mixed> */
    private function alternativeBlocker(mixed $requirement, Collection $rows): array
    {
        if (! $requirement instanceof CourseRequirement) {
            return ['course_id' => null, 'code' => null, 'reason' => self::class];
        }

        return [
            'course_id' => (int) $requirement->related_course_id,
            'code' => Course::query()->whereKey($requirement->related_course_id)->value('code'),
            'reason' => $this->requirementReason($requirement, $rows),
        ];
    }

    private function numericOutcomeMeets(GradeRosterRow $row, mixed $minimumGrade): bool
    {
        return $row->current_outcome_category === GradeRosterRow::CategoryPassing
            && is_numeric($row->current_outcome_code)
            && $this->numericGradeMeets((string) $row->current_outcome_code, $minimumGrade);
    }

    private function numericGradeMeets(string $grade, mixed $minimumGrade): bool
    {
        if ($minimumGrade === null) {
            return true;
        }

        $orderedCodes = collect($this->gradePolicy->snapshot()['scale'])->pluck('code')->map(fn ($code): string => (string) $code)->values();
        $actualIndex = $orderedCodes->search(fn (string $code): bool => (float) $code === (float) $grade);
        $minimumIndex = $orderedCodes->search(fn (string $code): bool => (float) $code === (float) $minimumGrade);

        return $actualIndex !== false && $minimumIndex !== false && $actualIndex <= $minimumIndex;
    }

    private function completionSource(?GradeRosterRow $row, ?ProgramShiftCreditEntry $credit): ?array
    {
        if ($credit instanceof ProgramShiftCreditEntry) {
            return ['type' => 'internal_shift_credit', 'id' => (int) $credit->id, 'numeric_grade' => $credit->numeric_grade];
        }

        if (! $row instanceof GradeRosterRow) {
            return null;
        }

        if ($row->current_outcome_code === 'TC') {
            return ['type' => 'external_transfer_credit', 'id' => (int) $row->id];
        }

        return $this->numericOutcomeMeets($row, null) ? $this->rowSource($row) : null;
    }

    private function isBackSubject(?GradeRosterRow $row, StudentProfile $studentProfile, int $courseId): bool
    {
        if ($row instanceof GradeRosterRow) {
            return in_array($row->current_outcome_category, [GradeRosterRow::CategoryFailed, GradeRosterRow::CategoryWithdrawn], true)
                || in_array($row->current_outcome_code, ['DRP', 'W'], true);
        }

        return CourseEnrollment::query()
            ->whereIn('status', ['dropped', 'withdrawn'])
            ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $studentProfile->id))
            ->whereHas('termOffering.curriculumEntry.courseSpecification', fn ($query) => $query->where('course_id', $courseId))
            ->exists();
    }

    /** @return array<string,mixed> */
    private function fact(CurriculumEntry $entry, string $status, ?array $source): array
    {
        return [
            'curriculum_entry_id' => (int) $entry->id,
            'course_id' => (int) $entry->courseSpecification->course_id,
            'course_code' => $entry->courseSpecification->course->code,
            'title' => $entry->courseSpecification->title,
            'units' => (string) $entry->courseSpecification->credit_units,
            'year_level' => $entry->year_level,
            'term_label' => $entry->term_label,
            'status' => $status,
            'source' => $source,
        ];
    }

    /** @return array<string,mixed>|null */
    private function rowSource(?GradeRosterRow $row): ?array
    {
        return $row instanceof GradeRosterRow ? [
            'type' => 'grade_roster_row', 'id' => (int) $row->id,
            'code' => $row->current_outcome_code, 'category' => $row->current_outcome_category,
        ] : null;
    }

    /** @return array<string,mixed>|null */
    private function outcomeBlocker(CurriculumEntry $entry, ?GradeRosterRow $row): ?array
    {
        if (! $row instanceof GradeRosterRow || ! in_array($row->current_outcome_code, ['P', 'INC'], true)) {
            return null;
        }

        return [
            'key' => 'outcome:'.$entry->id.':'.$row->current_outcome_code,
            'kind' => 'outcome',
            'course_id' => (int) $entry->courseSpecification->course_id,
            'course_code' => $entry->courseSpecification->course->code,
            'reason' => $row->current_outcome_code === 'INC' ? 'active_inc' : 'pending_grade',
            'source' => $this->rowSource($row),
        ];
    }

    private function recommendedStanding(StudentProfile $studentProfile, EloquentCollection $entries, array $completed, array $backSubjects, array $blockers, Collection $currentCourseIds): string
    {
        $authorized = [
            StudentProfile::StandingGraduationCandidate, StudentProfile::StandingCompletionCandidate,
            StudentProfile::StandingMustRepeatYear, StudentProfile::StandingProbationary,
        ];

        if (in_array($studentProfile->academic_standing, $authorized, true)) {
            return $studentProfile->academic_standing;
        }

        if (collect($blockers)->contains(fn (array $blocker): bool => ($blocker['kind'] ?? null) === 'prerequisite')) {
            return StudentProfile::StandingBlockedByPrerequisite;
        }
        if ($backSubjects !== [] || $blockers !== []) {
            return StudentProfile::StandingDeficient;
        }

        $requiredCount = $entries->where('requirement_group', CurriculumEntry::RequirementGroupRequired)->count();
        if ($requiredCount > 0 && count($completed) === $requiredCount) {
            return StudentProfile::StandingCompletionCandidate;
        }

        $curriculumCourseIds = $entries->pluck('courseSpecification.course_id')->map(fn ($id): int => (int) $id);
        if ($currentCourseIds->diff($curriculumCourseIds)->isNotEmpty()) {
            return StudentProfile::StandingIrregular;
        }

        return StudentProfile::StandingRegular;
    }

    /** @return array<string,mixed> */
    private function missingBaselineResult(StudentProfile $studentProfile): array
    {
        return [
            'student_profile_id' => (int) $studentProfile->id,
            'curriculum_version_id' => $studentProfile->curriculum_version_id,
            'curriculum_length' => 0,
            'standing' => StudentProfile::StandingNotYetEvaluated,
            'authorized_standing' => $studentProfile->academic_standing,
            'completed' => [], 'back_subjects' => [], 'blockers' => [['key' => 'missing_curriculum_baseline']],
            'suggestions' => [], 'current_course_ids' => [],
            'gwa' => null,
            'facts' => ['required_count' => 0, 'completed_count' => 0, 'back_subject_count' => 0, 'blocker_count' => 1],
        ];
    }
}
