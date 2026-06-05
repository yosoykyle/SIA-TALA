<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentHardCopyReceiptService
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function markReceived(Enrollment $enrollment, User $registrar, ?CarbonImmutable $receivedAt = null): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $registrar, $receivedAt): Enrollment {
            $lockedEnrollment = Enrollment::query()
                ->with(['studentProfile'])
                ->lockForUpdate()
                ->findOrFail($enrollment->id);

            $this->authorize($registrar, $lockedEnrollment);

            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($lockedEnrollment->student_profile_id);

            if ($studentProfile->hard_copy_received) {
                throw ValidationException::withMessages([
                    'hard_copy_received' => 'Hard-copy submission has already been marked as received.',
                ]);
            }

            $timestamp = $receivedAt ?? CarbonImmutable::now(config('app.timezone'));

            $studentProfile->forceFill([
                'hard_copy_received' => true,
                'last_status_changed_at' => $timestamp,
            ])->save();

            $this->recordActivity($lockedEnrollment, $studentProfile, $registrar, $timestamp);

            return $lockedEnrollment->fresh(['studentProfile']);
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $registrar, Enrollment $enrollment): void
    {
        if ($registrar->can('markHardCopyReceived', $enrollment)) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can confirm hard-copy receipt.');
    }

    private function recordActivity(
        Enrollment $enrollment,
        StudentProfile $studentProfile,
        User $registrar,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'enrollment_registrar',
            'description' => 'Registrar confirmed physical document submission.',
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'hard_copy_received',
            'causer_type' => User::class,
            'causer_id' => $registrar->id,
            'properties' => json_encode([
                'student_profile_id' => $studentProfile->id,
                'hard_copy_received' => true,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
