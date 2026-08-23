<?php

namespace App\Actions\Completion;

use App\Models\Enrollment;
use App\Models\GraduationApplication;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitGraduationApplication
{
    public function __construct(
        private readonly CompletionReadinessProjection $readiness,
        private readonly CompletionNotificationService $notifications,
    ) {}

    public function execute(StudentProfile $student, User $actor): GraduationApplication
    {
        if ((int) $student->user_id !== (int) $actor->id || ! $actor->hasRole('student')) {
            throw new AuthorizationException('Only the Student may submit this graduation application.');
        }

        return DB::transaction(function () use ($student, $actor): GraduationApplication {
            $locked = StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $existing = GraduationApplication::query()
                ->where('student_profile_id', $locked->id)
                ->where('state', GraduationApplication::StateActive)
                ->lockForUpdate()->first();

            if ($existing instanceof GraduationApplication) {
                return $existing;
            }

            $projection = $this->readiness->forStudent($locked);
            if ($projection['state'] !== CompletionReadinessProjection::EligibleToApply) {
                throw ValidationException::withMessages(['graduation' => 'Your current completion sources do not permit a graduation application.']);
            }

            $term = Enrollment::query()
                ->where('student_profile_id', $locked->id)
                ->whereNotNull('officially_enrolled_at')
                ->latest('term_id')->first()?->term_id;
            if ($term === null) {
                throw ValidationException::withMessages(['graduation' => 'Registrar must establish the final-term enrollment source before application.']);
            }

            $previous = GraduationApplication::query()
                ->where('student_profile_id', $locked->id)
                ->where('curriculum_version_id', $locked->curriculum_version_id)
                ->latest('version')->lockForUpdate()->first();
            $application = GraduationApplication::query()->create([
                'student_profile_id' => $locked->id,
                'curriculum_version_id' => $locked->curriculum_version_id,
                'term_id' => $term,
                'version' => ((int) $previous?->version) + 1,
                'supersedes_application_id' => $previous?->id,
                'state' => GraduationApplication::StateActive,
                'active_scope_key' => "{$locked->id}:{$locked->curriculum_version_id}",
                'source_fingerprint' => $projection['source_fingerprint'],
                'applied_by' => $actor->id,
                'applied_at' => now(),
            ]);

            $this->readiness->persist($locked, $actor);
            $this->notifications->recordAfterCommit($application, 'Graduation application received');

            return $application;
        }, attempts: 3);
    }
}
