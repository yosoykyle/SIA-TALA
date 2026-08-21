<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\RegistrationProposalItem;

class RegistrationAcademicResultImpactQuery
{
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

        return $dependentSpecificationIds->isNotEmpty()
            && CourseRequirement::query()
                ->whereIn('course_specification_id', $dependentSpecificationIds)
                ->where('related_course_id', $sourceCourseId)
                ->where('state', CourseRequirement::StateActive)
                ->whereIn('rule_type', [CourseRequirement::TypePrerequisite, CourseRequirement::TypeCorequisite])
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', today()))
                ->exists();
    }
}
