<?php

namespace App\Actions\Grades;

use App\Models\CourseEnrollment;
use App\Models\GradeRoster;
use App\Models\Section;
use App\Models\StudentScheduleBinding;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GenerateGradeRoster
{
    public function __construct(private readonly GradePolicyService $policy) {}

    public function execute(TermOffering $termOffering, Section $section, User $faculty): GradeRoster
    {
        if ((int) $section->term_offering_id !== (int) $termOffering->id) {
            throw new RuntimeException('Section does not belong to the selected term offering.');
        }

        return DB::transaction(function () use ($termOffering, $section, $faculty): GradeRoster {
            $roster = GradeRoster::query()
                ->where('term_offering_id', $termOffering->id)
                ->where('section_id', $section->id)
                ->lockForUpdate()
                ->first();

            if (! $roster instanceof GradeRoster) {
                $roster = GradeRoster::query()->create([
                    'term_offering_id' => $termOffering->id,
                    'section_id' => $section->id,
                    'faculty_user_id' => $faculty->id,
                    'state' => GradeRoster::StateDraft,
                    'grading_profile_snapshot' => $this->policy->snapshot($termOffering->courseSpecification()->grading_profile_key ?? 'servitech_v1'),
                ]);
            } elseif (! $roster->isReleased()) {
                $roster->update([
                    'faculty_user_id' => $faculty->id,
                ]);
            }

            $courseEnrollmentIds = CourseEnrollment::query()
                ->where('term_offering_id', $termOffering->id)
                ->where('status', CourseEnrollment::StatusActive)
                ->whereHas('scheduleBindings', function ($query) use ($section): void {
                    $query->where('is_active', true)
                        ->whereHas('sectionMeeting.schedulingDemand', function ($query) use ($section): void {
                            $query->whereHas('sectionDeliveryGroup', fn ($query) => $query->where('section_id', $section->id));
                        });
                })
                ->pluck('id');

            if ($courseEnrollmentIds->isEmpty()) {
                $courseEnrollmentIds = StudentScheduleBinding::query()
                    ->where('is_active', true)
                    ->whereHas('sectionMeeting.schedulingDemand.sectionDeliveryGroup', fn ($query) => $query->where('section_id', $section->id))
                    ->whereHas('courseEnrollment', fn ($query) => $query
                        ->where('term_offering_id', $termOffering->id)
                        ->where('status', CourseEnrollment::StatusActive))
                    ->pluck('course_enrollment_id')
                    ->unique();
            }

            foreach ($courseEnrollmentIds->unique() as $courseEnrollmentId) {
                $roster->rows()->firstOrCreate([
                    'course_enrollment_id' => $courseEnrollmentId,
                ]);
            }

            return $roster->fresh(['rows.courseEnrollment.enrollment.studentProfile', 'termOffering.curriculumEntry.courseSpecification.course', 'section', 'faculty']);
        });
    }
}
