<?php

namespace App\Actions\Enrollment;

use App\Actions\Grades\GradePolicyService;
use App\Models\CourseRequirement;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RegistrationAcademicEligibilityQuery
{
    public function __construct(private readonly GradePolicyService $gradePolicy) {}

    /**
     * @param  Collection<int, TermOffering>  $offerings
     */
    public function assertEligible(Enrollment $enrollment, CurriculumVersion $curriculum, Collection $offerings): void
    {
        $enrollment->loadMissing(['admissionApplication', 'studentProfile']);
        $profile = $enrollment->studentProfile;
        $programId = $enrollment->admissionApplication?->program_id;
        if ($programId === null && $profile instanceof StudentProfile) {
            $programId = $profile->program_id;
        }

        if ($programId === null
            || (int) $curriculum->program_id !== (int) $programId
            || $curriculum->state !== CurriculumVersion::StateActive) {
            throw ValidationException::withMessages([
                'sections' => 'Every proposed Class Offering must come from the learner’s active Program curriculum.',
            ]);
        }

        if ($profile instanceof StudentProfile
            && (int) $profile->curriculum_version_id !== (int) $curriculum->id) {
            throw ValidationException::withMessages([
                'sections' => 'A continuing Student proposal must use the current recorded Curriculum Version.',
            ]);
        }

        $specificationIds = $offerings
            ->pluck('curriculumEntry.course_specification_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $selectedCourseIds = $offerings
            ->pluck('curriculumEntry.courseSpecification.course_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $requirements = CourseRequirement::query()
            ->with('relatedCourse')
            ->whereIn('course_specification_id', $specificationIds)
            ->where('state', CourseRequirement::StateActive)
            ->whereIn('rule_type', [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite])
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))
            ->get()
            ->groupBy(fn (CourseRequirement $requirement): string => $requirement->course_specification_id.':'.$requirement->rule_type.':'.$requirement->group_key);

        if ($requirements->isEmpty()) {
            return;
        }

        $releasedRows = $profile instanceof StudentProfile
            ? $this->releasedRows($profile)
            : collect();
        $acceptedCredits = $profile instanceof StudentProfile
            ? $this->acceptedCredits($profile)
            : collect();

        foreach ($requirements as $alternatives) {
            $passes = $alternatives->contains(fn (CourseRequirement $requirement): bool => $this->alternativePasses(
                $requirement,
                $selectedCourseIds,
                $releasedRows,
                $acceptedCredits,
            ));

            if (! $passes) {
                $codes = $alternatives->pluck('relatedCourse.code')->filter()->implode(' or ');
                throw ValidationException::withMessages([
                    'sections' => 'Released academic results do not yet satisfy the required prerequisite or corequisite'.($codes !== '' ? ": {$codes}" : '').'.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, TermOffering>  $offerings
     */
    public function passes(Enrollment $enrollment, CurriculumVersion $curriculum, Collection $offerings): bool
    {
        try {
            $this->assertEligible($enrollment, $curriculum, $offerings);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** @return Collection<int, GradeRosterRow> */
    private function releasedRows(StudentProfile $profile): Collection
    {
        return GradeRosterRow::query()
            ->with('courseEnrollment.termOffering.curriculumEntry.courseSpecification')
            ->whereNotNull('released_at')
            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('credential_user_id', $profile->user_id))
            ->latest('released_at')
            ->latest('id')
            ->get()
            ->toBase()
            ->mapWithKeys(function (GradeRosterRow $row): array {
                $courseId = $row->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course_id;

                return $courseId === null ? [] : [(int) $courseId => $row];
            });
    }

    /** @return Collection<int, ProgramShiftCreditEntry> */
    private function acceptedCredits(StudentProfile $profile): Collection
    {
        return ProgramShiftCreditEntry::query()
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->where('state', ProgramShiftCreditEntry::StateRecorded)
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $profile->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->toBase();
    }

    /**
     * @param  Collection<int, int>  $selectedCourseIds
     * @param  Collection<int, GradeRosterRow>  $releasedRows
     * @param  Collection<int, ProgramShiftCreditEntry>  $acceptedCredits
     */
    private function alternativePasses(
        CourseRequirement $requirement,
        Collection $selectedCourseIds,
        Collection $releasedRows,
        Collection $acceptedCredits,
    ): bool {
        if ($requirement->rule_type === CourseRequirement::TypeCorequisite
            && $selectedCourseIds->contains((int) $requirement->related_course_id)) {
            return true;
        }

        $row = $releasedRows->get((int) $requirement->related_course_id);
        if ($row instanceof GradeRosterRow) {
            if ($row->current_outcome_code === 'TC') {
                return $requirement->accepts_transfer_credit;
            }

            return $row->current_outcome_category === GradeRosterRow::CategoryPassing
                && is_numeric($row->current_outcome_code)
                && $this->gradeMeets((string) $row->current_outcome_code, $requirement->minimum_grade);
        }

        return $acceptedCredits->contains(fn (ProgramShiftCreditEntry $credit): bool => (int) $credit->source_course_id === (int) $requirement->related_course_id
            && ($credit->numeric_grade === null || $this->gradeMeets((string) $credit->numeric_grade, $requirement->minimum_grade)));
    }

    private function gradeMeets(string $grade, mixed $minimumGrade): bool
    {
        if ($minimumGrade === null) {
            return true;
        }

        $codes = collect($this->gradePolicy->snapshot()['scale'])
            ->pluck('code')
            ->map(fn (mixed $code): string => (string) $code)
            ->values();
        $actual = $codes->search(fn (string $code): bool => (float) $code === (float) $grade);
        $minimum = $codes->search(fn (string $code): bool => (float) $code === (float) $minimumGrade);

        return $actual !== false && $minimum !== false && $actual <= $minimum;
    }
}
