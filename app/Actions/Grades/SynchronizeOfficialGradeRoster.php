<?php

namespace App\Actions\Grades;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GradeRosterVersion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SynchronizeOfficialGradeRoster
{
    public function __construct(private readonly AcademicRecordNotificationService $notifications) {}

    public function execute(Section $section, ?User $actor = null): GradeRoster
    {
        return DB::transaction(function () use ($section, $actor): GradeRoster {
            $section = Section::query()->lockForUpdate()->findOrFail($section->id);
            $assignment = ClassOfferingTeachingAssignment::query()
                ->where('section_id', $section->id)
                ->where('role', ClassOfferingTeachingAssignment::RoleDesignated)
                ->where('state', ClassOfferingTeachingAssignment::StateActive)
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof ClassOfferingTeachingAssignment) {
                throw new RuntimeException('Record the designated Faculty before materializing this roster.');
            }

            $membershipIds = CourseEnrollment::query()
                ->where('term_offering_id', $section->term_offering_id)
                ->where('status', CourseEnrollment::StatusActive)
                ->where('is_current', true)
                ->where(function ($query) use ($section): void {
                    $query->where('section_id', $section->id)
                        ->orWhereHas('seatReservations', fn ($reservationQuery) => $reservationQuery
                            ->where('section_id', $section->id)
                            ->where('status', 'ACTIVE'));
                })
                ->orderBy('id')
                ->pluck('id');
            $signature = hash('sha256', $membershipIds->implode('|'));

            $roster = GradeRoster::query()->firstOrCreate(
                ['term_offering_id' => $section->term_offering_id, 'section_id' => $section->id],
                [
                    'faculty_user_id' => $assignment->faculty_user_id,
                    'teaching_assignment_id' => $assignment->id,
                    'state' => GradeRoster::StateDraft,
                    'grading_profile_snapshot' => ['contract' => 'final-result-v1'],
                    'membership_signature' => $signature,
                ],
            );
            $roster = GradeRoster::query()->lockForUpdate()->findOrFail($roster->id);
            $changed = $roster->membership_signature !== null && $roster->membership_signature !== $signature;

            GradeRosterRow::query()->where('grade_roster_id', $roster->id)->update(['is_current_membership' => false]);

            foreach ($membershipIds as $courseEnrollmentId) {
                GradeRosterRow::query()->updateOrCreate(
                    ['grade_roster_id' => $roster->id, 'course_enrollment_id' => $courseEnrollmentId],
                    ['is_current_membership' => true],
                );
            }

            if ($changed && $roster->state !== GradeRoster::StateReleased) {
                $roster->versions()->where('state', GradeRosterVersion::StateSubmitted)->update([
                    'state' => GradeRosterVersion::StateInvalidated,
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'Official roster membership changed after submission.',
                ]);
            }

            $roster->update([
                'faculty_user_id' => $assignment->faculty_user_id,
                'teaching_assignment_id' => $assignment->id,
                'membership_signature' => $signature,
                'state' => $changed && $roster->state !== GradeRoster::StateReleased ? GradeRoster::StateDraft : $roster->state,
                'invalidated_at' => $changed && $roster->state !== GradeRoster::StateReleased ? now() : $roster->invalidated_at,
                'invalidated_by' => $changed && $roster->state !== GradeRoster::StateReleased ? $actor?->id : $roster->invalidated_by,
                'invalidation_reason' => $changed && $roster->state !== GradeRoster::StateReleased
                    ? 'Official roster membership changed; review and resubmit.'
                    : $roster->invalidation_reason,
                'lock_version' => $changed ? $roster->lock_version + 1 : $roster->lock_version,
            ]);

            $this->notifications->recordSubmissionRequiredAfterCommit($roster->fresh('teachingAssignment'));

            return $roster->fresh(['rows.courseEnrollment', 'teachingAssignment']);
        }, attempts: 3);
    }
}
