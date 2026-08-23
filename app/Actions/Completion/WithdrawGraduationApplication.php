<?php

namespace App\Actions\Completion;

use App\Models\GraduationApplication;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawGraduationApplication
{
    public function __construct(
        private readonly CompletionReadinessProjection $readiness,
        private readonly CompletionNotificationService $notifications,
    ) {}

    public function execute(GraduationApplication $application, User $actor, string $reason): GraduationApplication
    {
        if ((int) $application->studentProfile->user_id !== (int) $actor->id || ! $actor->hasRole('student')) {
            throw new AuthorizationException('Only the Student may withdraw this graduation application.');
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'A withdrawal reason is required.']);
        }

        return DB::transaction(function () use ($application, $actor, $reason): GraduationApplication {
            $locked = GraduationApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->state !== GraduationApplication::StateActive) {
                return $locked;
            }
            $locked->update([
                'state' => GraduationApplication::StateWithdrawn,
                'active_scope_key' => null,
                'withdrawn_by' => $actor->id,
                'withdrawn_at' => now(),
                'withdrawal_reason' => trim($reason),
            ]);
            $student = StudentProfile::query()->findOrFail($locked->student_profile_id);
            $this->readiness->persist($student, $actor);
            $this->notifications->recordAfterCommit($locked, 'Graduation application withdrawn');

            return $locked->fresh();
        }, attempts: 3);
    }
}
