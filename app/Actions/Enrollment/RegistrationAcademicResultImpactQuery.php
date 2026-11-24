<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\RegistrationProposalItem;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class RegistrationAcademicResultImpactQuery
{
    public function __construct(private readonly RegistrationAcademicEligibilityQuery $eligibility) {}

    public function affects(Enrollment $registrationCase, GradeRosterRow $changedRow): bool
    {
        $changedRow->loadMissing('courseEnrollment.termOffering.curriculumEntry.courseSpecification');
        $sourceCourseId = $changedRow->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course_id;
        if ($sourceCourseId === null) {
            return false;
        }

        $proposalSpecificationIds = RegistrationProposalItem::query()
            ->whereHas('proposalVersion', fn ($query) => $query
                ->where('enrollment_id', $registrationCase->id)
                ->where('id', $registrationCase->current_proposal_version_id))
            ->with('termOffering.curriculumEntry')
            ->get()
            ->pluck('termOffering.curriculumEntry.course_specification_id');
        $officialSpecificationIds = CourseEnrollment::query()
            ->where('enrollment_id', $registrationCase->id)
            ->where('is_current', true)
            ->where('status', CourseEnrollment::StatusActive)
            ->with('termOffering.curriculumEntry')
            ->get()
            ->pluck('termOffering.curriculumEntry.course_specification_id');
        $dependentSpecificationIds = $proposalSpecificationIds
            ->merge($officialSpecificationIds)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if ($dependentSpecificationIds->isEmpty()) {
            return false;
        }

        $activeRequirements = CourseRequirement::query()
            ->whereIn('course_specification_id', $dependentSpecificationIds)
            ->where('state', CourseRequirement::StateActive)
            ->whereIn('rule_type', [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite])
            ->get();

        $affectedGroups = $activeRequirements
            ->groupBy(fn (CourseRequirement $requirement): string => $requirement->course_specification_id.':'.$requirement->rule_type.':'.$requirement->group_key)
            ->filter(fn (Collection $alternatives): bool => $alternatives->contains(
                fn (CourseRequirement $requirement): bool => (int) $requirement->related_course_id === (int) $sourceCourseId,
            ));

        if ($affectedGroups->isEmpty()) {
            return false;
        }

        $registrationCase->loadMissing('studentProfile');
        $profile = $registrationCase->studentProfile;
        if (! $profile instanceof StudentProfile) {
            return true;
        }

        $satisfiedCourseIds = $this->eligibility->satisfiedCourseIds($profile);

        return $affectedGroups->contains(fn (Collection $alternatives): bool => ! $alternatives->contains(
            fn (CourseRequirement $requirement): bool => $satisfiedCourseIds->contains((int) $requirement->related_course_id),
        ));
    }
}
