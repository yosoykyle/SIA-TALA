<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\OfficialCourseResultProjection;
use App\Models\AdmissionApplication;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RegistrationAcademicEligibilityQuery
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    public static function isPassingResult(mixed $code): bool
    {
        if (! is_string($code) && ! is_numeric($code)) {
            return false;
        }

        $normalized = is_string($code) ? trim($code) : (string) $code;

        return in_array($normalized, ['TC', 'CREDIT'], true)
            || (is_numeric($normalized) && (float) $normalized <= 4.00);
    }

    /** @return Collection<int, int> */
    public function satisfiedCourseIds(StudentProfile $profile): Collection
    {
        $passedCourseIds = $this->results->forStudent($profile)
            ->filter(fn (array $result): bool => $result['course_specification']?->course_id !== null
                && self::isPassingResult($result['result'] ?? null))
            ->pluck('course_specification.course_id');

        $transferCourseIds = CurriculumEntry::query()
            ->with('courseSpecification')
            ->whereIn('id', RegistrationCreditEvaluation::creditedEntryIdsForStudent($profile))
            ->get()
            ->pluck('courseSpecification.course_id');

        $shiftCreditCourseIds = $this->acceptedCredits($profile)
            ->filter(fn (ProgramShiftCreditEntry $credit): bool => $credit->numeric_grade === null || (float) $credit->numeric_grade <= 4.00)
            ->pluck('curriculumEntry.courseSpecification.course_id');

        return $passedCourseIds
            ->merge($transferCourseIds)
            ->merge($shiftCreditCourseIds)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }

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

        $isAssignedSuperseded = $profile instanceof StudentProfile
            && (int) $profile->curriculum_version_id === (int) $curriculum->id
            && $curriculum->state === CurriculumVersion::StateSuperseded;

        if ($programId === null
            || (int) $curriculum->program_id !== (int) $programId
            || ($curriculum->state !== CurriculumVersion::StateActive && ! $isAssignedSuperseded)) {
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

        $evaluation = $enrollment->admissionApplication?->application_path === AdmissionApplication::PathTransferee
            ? RegistrationCreditEvaluation::latestCurrentFor($enrollment, $curriculum->id) : null;
        if ($enrollment->admissionApplication?->application_path === AdmissionApplication::PathTransferee && $evaluation === null) {
            throw ValidationException::withMessages(['evaluation' => 'Academic evaluation required. Registrar must record the approved result.']);
        }
        $creditEntryIds = array_merge($evaluation?->credited_curriculum_entry_ids ?? [], $profile instanceof StudentProfile ? RegistrationCreditEvaluation::creditedEntryIdsForStudent($profile) : []);
        $transferCourseIds = CurriculumEntry::query()->with('courseSpecification')->whereIn('id', $creditEntryIds)->get()->pluck('courseSpecification.course_id');
        if ($offerings->contains(fn (TermOffering $offering): bool => in_array((int) $offering->curriculum_entry_id, $creditEntryIds, true))) {
            throw ValidationException::withMessages(['sections' => 'An approved credited subject must not be enrolled again.']);
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
            ->get()
            ->groupBy(fn (CourseRequirement $requirement): string => $requirement->course_specification_id.':'.$requirement->rule_type.':'.$requirement->group_key);

        if ($requirements->isEmpty()) {
            return;
        }

        $satisfiedCourseIds = collect($transferCourseIds)
            ->merge($profile instanceof StudentProfile ? $this->satisfiedCourseIds($profile) : collect())
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($requirements as $alternatives) {
            $passes = $alternatives->contains(fn (CourseRequirement $requirement): bool => $this->alternativePasses(
                $requirement,
                $selectedCourseIds,
                $satisfiedCourseIds,
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

    /** @return Collection<int, ProgramShiftCreditEntry> */
    private function acceptedCredits(StudentProfile $profile): Collection
    {
        return ProgramShiftCreditEntry::query()
            ->with('curriculumEntry.courseSpecification')
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->where('state', ProgramShiftCreditEntry::StateRecorded)
            ->whereHas('curriculumEntry', fn ($query) => $query->where('curriculum_version_id', $profile->curriculum_version_id))
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $profile->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->toBase();
    }

    /**
     * @param  Collection<int, int>  $selectedCourseIds
     * @param  Collection<int, int>  $satisfiedCourseIds
     */
    private function alternativePasses(
        CourseRequirement $requirement,
        Collection $selectedCourseIds,
        Collection $satisfiedCourseIds,
    ): bool {
        if ($requirement->rule_type === CourseRequirement::TypeCorequisite
            && $selectedCourseIds->contains((int) $requirement->related_course_id)) {
            return true;
        }

        return $satisfiedCourseIds->contains((int) $requirement->related_course_id);
    }
}
