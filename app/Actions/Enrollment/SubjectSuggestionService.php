<?php

namespace App\Actions\Enrollment;

use App\Actions\Grades\GradePolicyService;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SubjectSuggestionService
{
    public const StatusSuggested = 'suggested';

    public const StatusBackSubject = 'back_subject';

    public const StatusBlocked = 'blocked';

    public const StatusAlreadyPassed = 'already_passed';

    public const BlockerMissingHistory = 'missing_history';

    public const BlockerFailed = 'failed';

    public const BlockerActiveInc = 'active_inc';

    public const BlockerPendingGrade = 'pending_grade';

    public const GradeStatusPassed = 'passed';

    public const GradeStatusFailed = 'failed';

    public function __construct(private readonly GradePolicyService $gradePolicy) {}

    /**
     * @return array{
     *     enrollment_id:int,
     *     student_profile_id:int|null,
     *     term_id:int|null,
     *     curriculum_id:int|null,
     *     year_level:string|null,
     *     curriculum_period:string|null,
     *     suggested:list<array<string,mixed>>,
     *     back_subjects:list<array<string,mixed>>,
     *     blocked:list<array<string,mixed>>,
     *     already_passed:list<array<string,mixed>>,
     *     setup_blockers:list<string>,
     *     summary:array{suggested_count:int,back_subject_count:int,blocked_count:int,already_passed_count:int,has_blockers:bool}
     * }
     */
    public function suggestForEnrollment(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['studentProfile.program', 'term']);

        $studentProfile = $enrollment->studentProfile;
        $curriculumVersionId = $studentProfile?->curriculum_version_id;
        $section = $enrollment->section;
        $sectionPeriod = $section instanceof Model ? $section->getAttribute('curriculum_period') : null;
        $yearLevel = $this->filledString($enrollment->getAttribute('year_level'));
        $curriculumPeriod = $this->filledString($sectionPeriod ?? $enrollment->term?->label);
        $setupBlockers = $this->setupBlockers($studentProfile, $curriculumVersionId, $yearLevel, $curriculumPeriod);

        if ($setupBlockers !== [] || ! $studentProfile instanceof StudentProfile || $curriculumVersionId === null) {
            return $this->emptyResult($enrollment, $studentProfile, $curriculumVersionId, $yearLevel, $curriculumPeriod, $setupBlockers);
        }

        $latestGrades = $this->latestRelevantGradesBySubject($studentProfile);
        $allCurriculumEntries = CurriculumEntry::query()
            ->with([
                'courseSpecification.course',
                'courseSpecification.requirements.relatedCourse',
            ])
            ->where('curriculum_version_id', $curriculumVersionId)
            ->orderBy('year_level')
            ->orderBy('term_label')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $currentCurriculumEntries = $allCurriculumEntries
            ->where('year_level', $yearLevel)
            ->where('term_label', $curriculumPeriod)
            ->values();
        $currentCourseIds = $currentCurriculumEntries
            ->map(fn (CurriculumEntry $curriculumEntry): ?int => $curriculumEntry->courseSpecification?->course_id)
            ->filter()
            ->map(fn (int|string $courseId): int => (int) $courseId)
            ->all();

        $suggested = [];
        $backSubjects = [];
        $blocked = [];
        $alreadyPassed = [];

        foreach ($currentCurriculumEntries as $curriculumEntry) {
            $course = $curriculumEntry->courseSpecification?->course;

            if (! $course instanceof Course) {
                continue;
            }

            $latestGrade = $latestGrades->get($course->id);
            $subjectGradeStatus = $this->gradeStatus($latestGrade);

            if ($subjectGradeStatus === self::GradeStatusPassed) {
                $alreadyPassed[] = $this->subjectItem($curriculumEntry, self::StatusAlreadyPassed, $latestGrade);

                continue;
            }

            if ($subjectGradeStatus === self::GradeStatusFailed) {
                $backSubjects[] = $this->subjectItem($curriculumEntry, self::StatusBackSubject, $latestGrade);

                continue;
            }

            if (in_array($subjectGradeStatus, [self::BlockerActiveInc, self::BlockerPendingGrade], true)) {
                $blocked[] = $this->subjectItem(
                    curriculumEntry: $curriculumEntry,
                    status: self::StatusBlocked,
                    latestGrade: $latestGrade,
                    blockers: [$this->blockerFor($course, $subjectGradeStatus, $latestGrade)],
                );

                continue;
            }

            $prerequisiteEvaluation = $this->evaluatePrerequisites($curriculumEntry, $latestGrades);

            if ($prerequisiteEvaluation['blockers'] !== []) {
                $blocked[] = $this->subjectItem(
                    curriculumEntry: $curriculumEntry,
                    status: self::StatusBlocked,
                    latestGrade: $latestGrade,
                    prerequisites: $prerequisiteEvaluation['prerequisites'],
                    blockers: $prerequisiteEvaluation['blockers'],
                );

                continue;
            }

            $suggested[] = $this->subjectItem(
                curriculumEntry: $curriculumEntry,
                status: self::StatusSuggested,
                latestGrade: $latestGrade,
                prerequisites: $prerequisiteEvaluation['prerequisites'],
            );
        }

        foreach ($allCurriculumEntries->unique('course_specification_id')->values() as $curriculumEntry) {
            $course = $curriculumEntry->courseSpecification?->course;

            if (! $course instanceof Course || in_array((int) $course->id, $currentCourseIds, true)) {
                continue;
            }

            $latestGrade = $latestGrades->get($course->id);

            if ($this->gradeStatus($latestGrade) === self::GradeStatusFailed) {
                $backSubjects[] = $this->subjectItem($curriculumEntry, self::StatusBackSubject, $latestGrade);
            }
        }

        return $this->result(
            enrollment: $enrollment,
            studentProfile: $studentProfile,
            curriculumVersionId: $curriculumVersionId,
            yearLevel: $yearLevel,
            curriculumPeriod: $curriculumPeriod,
            suggested: $suggested,
            backSubjects: $backSubjects,
            blocked: $blocked,
            alreadyPassed: $alreadyPassed,
            setupBlockers: [],
        );
    }

    /**
     * @return list<string>
     */
    private function setupBlockers(?StudentProfile $studentProfile, ?int $curriculumVersionId, ?string $yearLevel, ?string $curriculumPeriod): array
    {
        $blockers = [];

        if (! $studentProfile instanceof StudentProfile) {
            $blockers[] = 'missing_student_profile';
        }

        if ($curriculumVersionId === null) {
            $blockers[] = 'missing_curriculum';
        }

        if ($yearLevel === null) {
            $blockers[] = 'missing_year_level';
        }

        if ($curriculumPeriod === null) {
            $blockers[] = 'missing_curriculum_period';
        }

        return $blockers;
    }

    /**
     * @return Collection<int, GradeRosterRow>
     */
    private function latestRelevantGradesBySubject(StudentProfile $studentProfile): Collection
    {
        $releasedRows = GradeRosterRow::query()
            ->with([
                'courseEnrollment.enrollment',
                'courseEnrollment.termOffering.curriculumEntry.courseSpecification.course',
            ])
            ->whereNotNull('released_at')
            ->whereNotNull('current_outcome_code')
            ->whereHas('roster', function ($query): void {
                $query->whereNotNull('released_at');
            })
            ->whereHas('courseEnrollment', function ($query) use ($studentProfile): void {
                $query->where('status', CourseEnrollment::StatusActive)
                    ->whereHas('enrollment', function ($query) use ($studentProfile): void {
                        $query->where('student_profile_id', $studentProfile->id);
                    });
            })
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get();

        if ($releasedRows->isEmpty()) {
            return collect();
        }

        $latestReleasedRows = $releasedRows
            ->unique(fn (GradeRosterRow $row): ?int => $this->courseFor($row)?->id)
            ->values();

        return $latestReleasedRows
            ->mapWithKeys(function (GradeRosterRow $row): array {
                $course = $this->courseFor($row);

                if (! $course instanceof Course) {
                    return [];
                }

                return [(int) $course->id => $row];
            });
    }

    /**
     * @param  Collection<int, GradeRosterRow>  $latestGrades
     * @return array{prerequisites:list<array<string,mixed>>,blockers:list<array<string,mixed>>}
     */
    private function evaluatePrerequisites(CurriculumEntry $curriculumEntry, Collection $latestGrades): array
    {
        $prerequisites = [];
        $blockers = [];
        $requirements = $curriculumEntry->courseSpecification->requirements;

        foreach ($requirements as $requirement) {
            if ($requirement->rule_type !== CourseRequirement::TypePrerequisite || $requirement->state !== CourseRequirement::StateActive) {
                continue;
            }

            $prerequisite = $requirement->relatedCourse;

            if (! $prerequisite instanceof Course) {
                continue;
            }

            $latestGrade = $latestGrades->get($prerequisite->id);
            $status = $this->gradeStatus($latestGrade);
            $prerequisiteItem = $this->blockerFor($prerequisite, $status, $latestGrade);

            $prerequisites[] = $prerequisiteItem;

            if ($status !== self::GradeStatusPassed) {
                $blockers[] = $prerequisiteItem;
            }
        }

        return [
            'prerequisites' => $prerequisites,
            'blockers' => $blockers,
        ];
    }

    private function gradeStatus(?GradeRosterRow $grade): string
    {
        if (! $grade instanceof GradeRosterRow) {
            return self::BlockerMissingHistory;
        }

        $outcomeCode = strtoupper((string) $grade->current_outcome_code);

        if ($outcomeCode === 'INC') {
            return self::BlockerActiveInc;
        }

        if ($outcomeCode === 'P') {
            return self::BlockerPendingGrade;
        }

        if ($grade->released_at === null) {
            return self::BlockerMissingHistory;
        }

        return $this->isPassingGrade($grade)
            ? self::GradeStatusPassed
            : self::GradeStatusFailed;
    }

    private function isPassingGrade(GradeRosterRow $grade): bool
    {
        if (! is_numeric($grade->current_outcome_code)) {
            return false;
        }

        foreach ($this->gradePolicy->snapshot()['scale'] as $band) {
            if ((string) $band['code'] !== (string) $grade->current_outcome_code) {
                continue;
            }

            return $band['category'] === GradeRosterRow::CategoryPassing
                && $grade->current_outcome_category === GradeRosterRow::CategoryPassing;
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $prerequisites
     * @param  list<array<string,mixed>>  $blockers
     * @return array<string,mixed>
     */
    private function subjectItem(
        CurriculumEntry $curriculumEntry,
        string $status,
        ?GradeRosterRow $latestGrade = null,
        array $prerequisites = [],
        array $blockers = [],
    ): array {
        $courseSpecification = $curriculumEntry->courseSpecification;
        $course = $courseSpecification?->course;

        return [
            'subject_id' => (int) $course?->id,
            'course_id' => (int) $course?->id,
            'code' => (string) $course?->code,
            'description' => (string) $courseSpecification?->title,
            'units' => $courseSpecification?->credit_units !== null ? (string) $courseSpecification->credit_units : '0.00',
            'curriculum_subject_id' => (int) $curriculumEntry->id,
            'curriculum_entry_id' => (int) $curriculumEntry->id,
            'year_level' => (string) $curriculumEntry->year_level,
            'curriculum_period' => (string) $curriculumEntry->term_label,
            'sort_order' => (int) $curriculumEntry->sequence,
            'academic_subject_type' => null,
            'scheduling_group' => null,
            'status' => $status,
            'latest_grade' => $this->gradeSnapshot($latestGrade),
            'prerequisites' => $prerequisites,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockerFor(Course $subject, string $reason, ?GradeRosterRow $latestGrade): array
    {
        return [
            'subject_id' => (int) $subject->id,
            'code' => (string) $subject->code,
            'description' => (string) $subject->code,
            'reason' => $reason,
            'latest_grade' => $this->gradeSnapshot($latestGrade),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function gradeSnapshot(?GradeRosterRow $grade): ?array
    {
        if (! $grade instanceof GradeRosterRow) {
            return null;
        }

        return [
            'grade_id' => (int) $grade->id,
            'grade_roster_row_id' => (int) $grade->id,
            'grade_roster_id' => (int) $grade->grade_roster_id,
            'course_enrollment_id' => (int) $grade->course_enrollment_id,
            'enrollment_id' => $grade->courseEnrollment?->enrollment_id,
            'term_id' => $grade->courseEnrollment?->enrollment?->term_id,
            'grade' => is_numeric($grade->current_outcome_code) ? (string) $grade->current_outcome_code : null,
            'outcome_code' => $grade->current_outcome_code,
            'remarks' => $grade->current_outcome_category,
            'is_inc' => $grade->current_outcome_code === 'INC',
            'is_finalized' => $grade->released_at !== null,
            'finalized_at' => $grade->released_at?->toDateTimeString(),
        ];
    }

    private function courseFor(GradeRosterRow $row): ?Course
    {
        return $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course;
    }

    /**
     * @param  list<array<string,mixed>>  $suggested
     * @param  list<array<string,mixed>>  $backSubjects
     * @param  list<array<string,mixed>>  $blocked
     * @param  list<array<string,mixed>>  $alreadyPassed
     * @param  list<string>  $setupBlockers
     * @return array<string,mixed>
     */
    private function result(
        Enrollment $enrollment,
        ?StudentProfile $studentProfile,
        ?int $curriculumVersionId,
        ?string $yearLevel,
        ?string $curriculumPeriod,
        array $suggested,
        array $backSubjects,
        array $blocked,
        array $alreadyPassed,
        array $setupBlockers,
    ): array {
        return [
            'enrollment_id' => (int) $enrollment->id,
            'student_profile_id' => $studentProfile?->id,
            'term_id' => $enrollment->term_id,
            'curriculum_id' => $curriculumVersionId,
            'year_level' => $yearLevel,
            'curriculum_period' => $curriculumPeriod,
            'suggested' => $suggested,
            'back_subjects' => $backSubjects,
            'blocked' => $blocked,
            'already_passed' => $alreadyPassed,
            'setup_blockers' => $setupBlockers,
            'summary' => [
                'suggested_count' => count($suggested),
                'back_subject_count' => count($backSubjects),
                'blocked_count' => count($blocked),
                'already_passed_count' => count($alreadyPassed),
                'has_blockers' => $blocked !== [] || $setupBlockers !== [],
            ],
        ];
    }

    /**
     * @param  list<string>  $setupBlockers
     * @return array<string,mixed>
     */
    private function emptyResult(
        Enrollment $enrollment,
        ?StudentProfile $studentProfile,
        ?int $curriculumVersionId,
        ?string $yearLevel,
        ?string $curriculumPeriod,
        array $setupBlockers,
    ): array {
        return $this->result(
            enrollment: $enrollment,
            studentProfile: $studentProfile,
            curriculumVersionId: $curriculumVersionId,
            yearLevel: $yearLevel,
            curriculumPeriod: $curriculumPeriod,
            suggested: [],
            backSubjects: [],
            blocked: [],
            alreadyPassed: [],
            setupBlockers: $setupBlockers,
        );
    }

    private function filledString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
