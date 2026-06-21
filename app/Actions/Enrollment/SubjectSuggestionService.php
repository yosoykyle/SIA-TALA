<?php

namespace App\Actions\Enrollment;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\StudentProfile;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SubjectSuggestionService
{
    public const StatusSuggested = 'suggested';

    public const StatusBackSubject = 'back_subject';

    public const StatusBlocked = 'blocked';

    public const StatusAlreadyPassed = 'already_passed';

    public const BlockerMissingHistory = 'missing_history';

    public const BlockerFailed = 'failed';

    public const BlockerActiveInc = 'active_inc';

    public const GradeStatusPassed = 'passed';

    public const GradeStatusFailed = 'failed';

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
        $enrollment->loadMissing(['studentProfile.program', 'section.curriculum', 'term']);

        $studentProfile = $enrollment->studentProfile;
        $curriculum = $this->resolveCurriculum($enrollment, $studentProfile);
        $yearLevel = $this->filledString($enrollment->year_level ?? $studentProfile?->year_level);
        $curriculumPeriod = $this->filledString($enrollment->section?->curriculum_period);
        $setupBlockers = $this->setupBlockers($studentProfile, $curriculum, $yearLevel, $curriculumPeriod);

        if ($setupBlockers !== [] || ! $studentProfile instanceof StudentProfile || ! $curriculum instanceof Curriculum) {
            return $this->emptyResult($enrollment, $studentProfile, $curriculum, $yearLevel, $curriculumPeriod, $setupBlockers);
        }

        $latestGrades = $this->latestRelevantGradesBySubject($studentProfile);
        $allCurriculumSubjects = CurriculumSubject::query()
            ->with(['subject.prerequisites'])
            ->where('curriculum_id', $curriculum->id)
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $currentCurriculumSubjects = $allCurriculumSubjects
            ->where('year_level', $yearLevel)
            ->where('semester', $curriculumPeriod)
            ->values();
        $currentSubjectIds = $currentCurriculumSubjects
            ->pluck('subject_id')
            ->map(fn (int|string $subjectId): int => (int) $subjectId)
            ->all();

        $suggested = [];
        $backSubjects = [];
        $blocked = [];
        $alreadyPassed = [];

        foreach ($currentCurriculumSubjects as $curriculumSubject) {
            if (! $curriculumSubject->subject instanceof Subject) {
                continue;
            }

            $latestGrade = $latestGrades->get($curriculumSubject->subject_id);
            $subjectGradeStatus = $this->gradeStatus($latestGrade);

            if ($subjectGradeStatus === self::GradeStatusPassed) {
                $alreadyPassed[] = $this->subjectItem($curriculumSubject, self::StatusAlreadyPassed, $latestGrade);

                continue;
            }

            if ($subjectGradeStatus === self::GradeStatusFailed) {
                $backSubjects[] = $this->subjectItem($curriculumSubject, self::StatusBackSubject, $latestGrade);

                continue;
            }

            if ($subjectGradeStatus === self::BlockerActiveInc) {
                $blocked[] = $this->subjectItem(
                    curriculumSubject: $curriculumSubject,
                    status: self::StatusBlocked,
                    latestGrade: $latestGrade,
                    blockers: [$this->blockerFor($curriculumSubject->subject, self::BlockerActiveInc, $latestGrade)],
                );

                continue;
            }

            $prerequisiteEvaluation = $this->evaluatePrerequisites($curriculumSubject->subject, $latestGrades);

            if ($prerequisiteEvaluation['blockers'] !== []) {
                $blocked[] = $this->subjectItem(
                    curriculumSubject: $curriculumSubject,
                    status: self::StatusBlocked,
                    latestGrade: $latestGrade,
                    prerequisites: $prerequisiteEvaluation['prerequisites'],
                    blockers: $prerequisiteEvaluation['blockers'],
                );

                continue;
            }

            $suggested[] = $this->subjectItem(
                curriculumSubject: $curriculumSubject,
                status: self::StatusSuggested,
                latestGrade: $latestGrade,
                prerequisites: $prerequisiteEvaluation['prerequisites'],
            );
        }

        foreach ($allCurriculumSubjects->unique('subject_id')->values() as $curriculumSubject) {
            if (in_array((int) $curriculumSubject->subject_id, $currentSubjectIds, true)) {
                continue;
            }

            $latestGrade = $latestGrades->get($curriculumSubject->subject_id);

            if ($this->gradeStatus($latestGrade) === self::GradeStatusFailed) {
                $backSubjects[] = $this->subjectItem($curriculumSubject, self::StatusBackSubject, $latestGrade);
            }
        }

        return $this->result(
            enrollment: $enrollment,
            studentProfile: $studentProfile,
            curriculum: $curriculum,
            yearLevel: $yearLevel,
            curriculumPeriod: $curriculumPeriod,
            suggested: $suggested,
            backSubjects: $backSubjects,
            blocked: $blocked,
            alreadyPassed: $alreadyPassed,
            setupBlockers: [],
        );
    }

    private function resolveCurriculum(Enrollment $enrollment, ?StudentProfile $studentProfile): ?Curriculum
    {
        if ($enrollment->section?->curriculum instanceof Curriculum) {
            return $enrollment->section->curriculum;
        }

        if (! $studentProfile instanceof StudentProfile || $studentProfile->program_id === null) {
            return null;
        }

        return Curriculum::query()
            ->where('program_id', $studentProfile->program_id)
            ->where('is_active', true)
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function setupBlockers(?StudentProfile $studentProfile, ?Curriculum $curriculum, ?string $yearLevel, ?string $curriculumPeriod): array
    {
        $blockers = [];

        if (! $studentProfile instanceof StudentProfile) {
            $blockers[] = 'missing_student_profile';
        }

        if (! $curriculum instanceof Curriculum) {
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
     * @return Collection<int, Grade>
     */
    private function latestRelevantGradesBySubject(StudentProfile $studentProfile): Collection
    {
        $enrollmentIds = $studentProfile->enrollments()
            ->pluck('id');

        if ($enrollmentIds->isEmpty()) {
            return collect();
        }

        return Grade::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where(function ($query): void {
                $query->where('is_finalized', true)
                    ->orWhere('is_inc', true);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('subject_id')
            ->keyBy('subject_id');
    }

    /**
     * @param  Collection<int, Grade>  $latestGrades
     * @return array{prerequisites:list<array<string,mixed>>,blockers:list<array<string,mixed>>}
     */
    private function evaluatePrerequisites(Subject $subject, Collection $latestGrades): array
    {
        $prerequisites = [];
        $blockers = [];

        foreach ($subject->prerequisites as $prerequisite) {
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

    private function gradeStatus(?Grade $grade): string
    {
        if (! $grade instanceof Grade) {
            return self::BlockerMissingHistory;
        }

        if ($grade->is_inc) {
            return self::BlockerActiveInc;
        }

        if (! $grade->is_finalized) {
            return self::BlockerMissingHistory;
        }

        return $this->isPassingGrade($grade)
            ? self::GradeStatusPassed
            : self::GradeStatusFailed;
    }

    private function isPassingGrade(Grade $grade): bool
    {
        $remarks = Str::of((string) $grade->remarks)->lower()->squish()->toString();

        if ($remarks === self::GradeStatusPassed) {
            return true;
        }

        if ($remarks === self::GradeStatusFailed || $remarks === 'inc') {
            return false;
        }

        if ($grade->grade === null) {
            return false;
        }

        $gradeValue = (float) $grade->grade;

        return $gradeValue <= 3.0;
    }

    /**
     * @param  list<array<string,mixed>>  $prerequisites
     * @param  list<array<string,mixed>>  $blockers
     * @return array<string,mixed>
     */
    private function subjectItem(
        CurriculumSubject $curriculumSubject,
        string $status,
        ?Grade $latestGrade = null,
        array $prerequisites = [],
        array $blockers = [],
    ): array {
        $subject = $curriculumSubject->subject;

        return [
            'subject_id' => (int) $curriculumSubject->subject_id,
            'code' => (string) $subject?->code,
            'description' => (string) $subject?->description,
            'units' => $subject?->units !== null ? (string) $subject->units : '0.00',
            'curriculum_subject_id' => (int) $curriculumSubject->id,
            'year_level' => (string) $curriculumSubject->year_level,
            'curriculum_period' => (string) $curriculumSubject->semester,
            'sort_order' => (int) $curriculumSubject->sort_order,
            'academic_subject_type' => $curriculumSubject->academic_subject_type,
            'scheduling_group' => $curriculumSubject->scheduling_group,
            'status' => $status,
            'latest_grade' => $this->gradeSnapshot($latestGrade),
            'prerequisites' => $prerequisites,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockerFor(Subject $subject, string $reason, ?Grade $latestGrade): array
    {
        return [
            'subject_id' => (int) $subject->id,
            'code' => (string) $subject->code,
            'description' => (string) $subject->description,
            'reason' => $reason,
            'latest_grade' => $this->gradeSnapshot($latestGrade),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function gradeSnapshot(?Grade $grade): ?array
    {
        if (! $grade instanceof Grade) {
            return null;
        }

        return [
            'grade_id' => (int) $grade->id,
            'enrollment_id' => (int) $grade->enrollment_id,
            'term_id' => (int) $grade->term_id,
            'grade' => $grade->grade !== null ? (string) $grade->grade : null,
            'remarks' => $grade->remarks,
            'is_inc' => (bool) $grade->is_inc,
            'is_finalized' => (bool) $grade->is_finalized,
            'finalized_at' => $grade->finalized_at?->toDateTimeString(),
        ];
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
        ?Curriculum $curriculum,
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
            'curriculum_id' => $curriculum?->id,
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
        ?Curriculum $curriculum,
        ?string $yearLevel,
        ?string $curriculumPeriod,
        array $setupBlockers,
    ): array {
        return $this->result(
            enrollment: $enrollment,
            studentProfile: $studentProfile,
            curriculum: $curriculum,
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
