<?php

namespace App\Actions\Enrollment;

use App\Actions\Academics\OfficialCourseResultProjection;
use App\Models\AdmissionApplication;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\Section;
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

        $directCourseIds = $passedCourseIds
            ->merge($transferCourseIds)
            ->merge($shiftCreditCourseIds)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        return $this->expandWithEquivalentCourseIds($directCourseIds);
    }

    /**
     * @param  Collection<int, int>  $courseIds
     * @return Collection<int, int>
     */
    public function expandWithEquivalentCourseIds(Collection $courseIds): Collection
    {
        $baseIds = $courseIds
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($baseIds->isEmpty()) {
            return collect();
        }

        $forwardIds = CourseRequirement::query()
            ->where('state', CourseRequirement::StateActive)
            ->where('rule_type', CourseRequirement::TypeEquivalency)
            ->whereHas('courseSpecification', fn ($query) => $query->whereIn('course_id', $baseIds))
            ->pluck('related_course_id');

        $reverseIds = CourseSpecification::query()
            ->whereHas('requirements', fn ($query) => $query
                ->where('state', CourseRequirement::StateActive)
                ->where('rule_type', CourseRequirement::TypeEquivalency)
                ->whereIn('related_course_id', $baseIds))
            ->pluck('course_id');

        return $baseIds
            ->merge($forwardIds)
            ->merge($reverseIds)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    public function resolveStudentCurriculum(Enrollment $enrollment, ?int $fallbackCurriculumId = null): ?CurriculumVersion
    {
        $enrollment->loadMissing(['admissionApplication', 'studentProfile.entryTerm', 'term']);
        $profile = $enrollment->studentProfile;
        $programId = $profile?->program_id ?? $enrollment->admissionApplication?->program_id;

        if ($profile instanceof StudentProfile && $profile->curriculum_version_id !== null) {
            return CurriculumVersion::query()
                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
                ->whereKey($profile->curriculum_version_id)
                ->first();
        }

        if ($programId !== null) {
            $program = Program::query()->find($programId);
            if ($program instanceof Program && $program->curriculumVersionBatches()->exists()) {
                $academicYearId = $profile?->entryTerm?->academic_year_id ?? $enrollment->term?->academic_year_id;

                return $program->curriculumForAcademicYear($academicYearId);
            }

            $active = CurriculumVersion::query()
                ->whereIn('state', [CurriculumVersion::StateActive])
                ->where('program_id', $programId)
                ->get();

            if ($active->count() === 1) {
                return $active->sole();
            }

            if ($fallbackCurriculumId !== null) {
                return $active->firstWhere('id', $fallbackCurriculumId);
            }

            return null;
        }

        return $fallbackCurriculumId !== null
            ? CurriculumVersion::query()->find($fallbackCurriculumId)
            : null;
    }

    public function resolveCurriculumEntry(
        CurriculumVersion $curriculum,
        ?TermOffering $offering,
        ?Section $section = null,
    ): ?CurriculumEntry {
        if (! $offering instanceof TermOffering) {
            return null;
        }

        $entries = CurriculumEntry::query()
            ->with('courseSpecification.requirements')
            ->where('curriculum_version_id', $curriculum->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        return $this->resolveCurriculumEntryFromEntries($entries, $offering, $section);
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $entries
     */
    public function resolveCurriculumEntryFromEntries(
        Collection $entries,
        ?TermOffering $offering,
        ?Section $section = null,
    ): ?CurriculumEntry {
        if (! $offering instanceof TermOffering) {
            return null;
        }

        $offering->loadMissing('curriculumEntry.courseSpecification.requirements');
        $hostEntry = $offering->curriculumEntry;
        $offeredSpecification = $hostEntry?->courseSpecification ?? $section?->courseRevision;

        if (! $hostEntry instanceof CurriculumEntry || ! $offeredSpecification instanceof CourseSpecification) {
            return null;
        }

        $direct = $entries->firstWhere('id', (int) $hostEntry->id);
        if ($direct instanceof CurriculumEntry) {
            return $direct;
        }

        $offeredCourseId = (int) $offeredSpecification->course_id;
        if ($offeredCourseId <= 0) {
            return null;
        }

        $sameCourse = $entries->filter(
            fn (CurriculumEntry $entry): bool => (int) $entry->courseSpecification?->course_id === $offeredCourseId,
        );
        if ($sameCourse->isNotEmpty()) {
            return $sameCourse->firstWhere('term_type', $hostEntry->term_type) ?? $sameCourse->first();
        }

        $offeredEquivalentCourseIds = $offeredSpecification->requirements
            ->where('state', CourseRequirement::StateActive)
            ->where('rule_type', CourseRequirement::TypeEquivalency)
            ->pluck('related_course_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id);

        return $entries->first(function (CurriculumEntry $entry) use ($offeredCourseId, $offeredEquivalentCourseIds): bool {
            $entrySpecification = $entry->courseSpecification;
            if (! $entrySpecification instanceof CourseSpecification) {
                return false;
            }

            $entrySpecification->loadMissing('requirements');
            $entryCourseId = (int) $entrySpecification->course_id;
            if ($entryCourseId > 0 && $offeredEquivalentCourseIds->contains($entryCourseId)) {
                return true;
            }

            return $entrySpecification->requirements
                ->where('state', CourseRequirement::StateActive)
                ->where('rule_type', CourseRequirement::TypeEquivalency)
                ->contains(fn (CourseRequirement $requirement): bool => (int) $requirement->related_course_id === $offeredCourseId);
        });
    }

    /**
     * @param  Collection<int, TermOffering>  $offerings
     */
    public function assertEligible(Enrollment $enrollment, CurriculumVersion $curriculum, Collection $offerings): void
    {
        $enrollment->loadMissing(['admissionApplication', 'studentProfile', 'term']);
        $profile = $enrollment->studentProfile;
        $programId = $enrollment->admissionApplication?->program_id;
        if ($programId === null && $profile instanceof StudentProfile) {
            $programId = $profile->program_id;
        }

        $isAssignedSuperseded = false;
        if ($curriculum->state === CurriculumVersion::StateSuperseded) {
            if ($profile instanceof StudentProfile) {
                $isAssignedSuperseded = (int) $profile->curriculum_version_id === (int) $curriculum->id;
            } elseif ($programId !== null) {
                $program = Program::query()->find($programId);
                $batchCurriculum = $program instanceof Program
                    ? $program->curriculumForAcademicYear($enrollment->term?->academic_year_id)
                    : null;
                $isAssignedSuperseded = $batchCurriculum instanceof CurriculumVersion
                    && (int) $curriculum->id === (int) $batchCurriculum->id;
            }
        }

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

        $curriculumEntries = CurriculumEntry::query()
            ->with('courseSpecification.requirements')
            ->where('curriculum_version_id', $curriculum->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $matchedEntries = $offerings->map(
            fn (TermOffering $offering): ?CurriculumEntry => $this->resolveCurriculumEntryFromEntries($curriculumEntries, $offering),
        );
        if ($matchedEntries->contains(fn (?CurriculumEntry $entry): bool => ! $entry instanceof CurriculumEntry)) {
            throw ValidationException::withMessages([
                'sections' => 'Every proposed Class Offering must come from the learner’s active Program curriculum.',
            ]);
        }

        $offeredCourseIds = $offerings
            ->pluck('curriculumEntry.courseSpecification.course_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        if ($matchedEntries->pluck('id')->unique()->count() !== $offerings->count()
            || $offeredCourseIds->unique()->count() !== $offerings->count()) {
            throw ValidationException::withMessages([
                'sections' => 'Each subject may appear only once in a Registration Proposal.',
            ]);
        }

        $evaluation = $enrollment->admissionApplication?->application_path === AdmissionApplication::PathTransferee
            ? RegistrationCreditEvaluation::latestCurrentFor($enrollment, $curriculum->id) : null;
        if ($enrollment->admissionApplication?->application_path === AdmissionApplication::PathTransferee && $evaluation === null) {
            throw ValidationException::withMessages(['evaluation' => 'Academic evaluation required. Registrar must record the approved result.']);
        }
        $creditEntryIds = array_merge($evaluation?->credited_curriculum_entry_ids ?? [], $profile instanceof StudentProfile ? RegistrationCreditEvaluation::creditedEntryIdsForStudent($profile) : []);
        $transferCourseIds = CurriculumEntry::query()
            ->with('courseSpecification')
            ->whereIn('id', $creditEntryIds)
            ->get()
            ->pluck('courseSpecification.course_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id);
        if ($offerings->contains(fn (TermOffering $offering): bool => in_array((int) $offering->curriculum_entry_id, $creditEntryIds, true))
            || $matchedEntries->contains(fn (?CurriculumEntry $entry): bool => $entry instanceof CurriculumEntry
                && (in_array((int) $entry->id, $creditEntryIds, true)
                    || $transferCourseIds->contains((int) $entry->courseSpecification?->course_id)))
            || $offeredCourseIds->contains(fn (int $courseId): bool => $transferCourseIds->contains($courseId))) {
            throw ValidationException::withMessages(['sections' => 'An approved credited subject must not be enrolled again.']);
        }

        $specificationIds = $offerings
            ->pluck('curriculumEntry.course_specification_id')
            ->merge($matchedEntries->pluck('course_specification_id'))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $selectedCourseIds = $offeredCourseIds
            ->merge($matchedEntries->pluck('courseSpecification.course_id'))
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

        $satisfiedCourseIds = $this->expandWithEquivalentCourseIds(
            collect($transferCourseIds)
                ->merge($profile instanceof StudentProfile ? $this->satisfiedCourseIds($profile) : collect()),
        );

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
