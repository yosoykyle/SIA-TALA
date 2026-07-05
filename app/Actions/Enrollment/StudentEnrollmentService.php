<?php

namespace App\Actions\Enrollment;

use App\Models\ChecklistItem;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StudentEnrollmentService
{
    /**
     * @throws ValidationException
     */
    public function completeFinanceClearedHandover(
        Enrollment $enrollment,
        ?User $actor = null,
        ?CarbonImmutable $clearedAt = null,
    ): Enrollment {
        $timestamp = $clearedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($enrollment, $actor, $timestamp): Enrollment {
            $lockedEnrollment = Enrollment::query()
                ->with(['studentProfile.user'])
                ->lockForUpdate()
                ->findOrFail($enrollment->id);

            if (! in_array($lockedEnrollment->status, ['pre_enrolled', 'officially_enrolled'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Finance-cleared handover requires a Pre-Enrolled or Officially Enrolled enrollment.',
                ]);
            }

            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($lockedEnrollment->student_profile_id);
            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($studentProfile->user_id);

            $unresolvedChecklistItems = $studentProfile->checklistItems()
                ->where('blocking_level', 'blocks_handover')
                ->get()
                ->filter(fn (ChecklistItem $item): bool => ! $item->isResolved());

            if ($unresolvedChecklistItems->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'checklist' => 'Handover is blocked by unresolved checklist items.',
                ]);
            }

            $alreadyHandedOver = $user->status === User::StatusActive
                && $user->username === $studentProfile->student_number
                && $user->hasRole('student')
                && ! $user->hasRole('applicant');

            $user->forceFill([
                'status' => User::StatusActive,
                'username' => $studentProfile->student_number,
            ])->save();

            Role::findOrCreate('student', 'web');
            Role::findOrCreate('applicant', 'web');

            if ($user->hasRole('applicant')) {
                $user->removeRole('applicant');
            }

            if (! $user->hasRole('student')) {
                $user->assignRole('student');
            }

            if (! $alreadyHandedOver) {
                $this->recordActivity(
                    enrollment: $lockedEnrollment,
                    event: 'student_account_handover_completed',
                    causer: $actor,
                    properties: [
                        'student_profile_id' => $studentProfile->id,
                        'user_id' => $user->id,
                        'student_number' => $studentProfile->student_number,
                        'status_after' => User::StatusActive,
                    ],
                    timestamp: $timestamp,
                );
            }

            return $lockedEnrollment->refresh()->load([
                'studentProfile.user',
                'term',
                'courseEnrollments.scheduleBindings',
            ]);
        }, attempts: 3);
    }

    /**
     * @return array{ready:bool, blockers:list<string>}
     */
    public function corReadiness(Enrollment $enrollment): array
    {
        $enrollment->loadMissing([
            'studentProfile.user',
            'courseEnrollments.scheduleBindings',
        ]);
        $blockers = [];

        if ($enrollment->status !== 'officially_enrolled') {
            $blockers[] = 'enrollment_not_official';
        }

        if ($enrollment->studentProfile?->user?->status !== User::StatusActive) {
            $blockers[] = 'account_not_active';
        }

        if (! $enrollment->studentProfile?->user?->hasRole('student')) {
            $blockers[] = 'student_role_missing';
        }

        $activeCourseEnrollments = $enrollment->courseEnrollments
            ->where('status', CourseEnrollment::StatusActive);

        if ($activeCourseEnrollments->isEmpty()) {
            $blockers[] = 'course_enrollment_missing';
        } elseif ($activeCourseEnrollments->contains(
            fn (CourseEnrollment $courseEnrollment): bool => $courseEnrollment->scheduleBindings
                ->where('is_active', true)
                ->isEmpty(),
        )) {
            $blockers[] = 'schedule_binding_missing';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        Enrollment $enrollment,
        string $event,
        ?User $causer,
        array $properties,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'student_enrollment',
            'description' => 'Student enrollment lifecycle transition.',
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => $event,
            'causer_type' => $causer instanceof User ? User::class : null,
            'causer_id' => $causer?->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
