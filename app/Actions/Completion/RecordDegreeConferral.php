<?php

namespace App\Actions\Completion;

use App\Actions\Academics\CurriculumEvaluation;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GraduationApplication;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordDegreeConferral
{
    public function __construct(
        private readonly CompletionReadinessProjection $readiness,
        private readonly CurriculumEvaluation $curriculum,
        private readonly CompletionNotificationService $notifications,
    ) {}

    public function execute(
        StudentProfile $student,
        User $actor,
        string $degreeName,
        CarbonInterface|string|null $conferredOn = null,
        ?string $authorityReference = null,
        ?string $honorText = null,
        ?string $honorAuthorityReference = null,
    ): DegreeConferral {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may record degree conferral.');
        }
        if (blank(trim($degreeName))) {
            throw ValidationException::withMessages(['conferral' => 'The actual degree awarded is required.']);
        }
        if (filled($honorText) && blank(trim((string) $honorAuthorityReference))) {
            throw ValidationException::withMessages(['honor' => 'Recorded honor text requires its external authority.']);
        }

        if (is_string($conferredOn) && filled($conferredOn)) {
            Validator::make(['conferred_on' => $conferredOn], ['conferred_on' => ['date']])->validate();
        }
        $date = $conferredOn instanceof CarbonInterface
            ? CarbonImmutable::instance($conferredOn)
            : (filled($conferredOn) ? CarbonImmutable::parse($conferredOn, config('app.timezone')) : null);
        $authorityReference = filled($authorityReference) ? trim($authorityReference) : null;

        return DB::transaction(function () use ($student, $actor, $degreeName, $date, $authorityReference, $honorText, $honorAuthorityReference): DegreeConferral {
            $locked = StudentProfile::query()->with(['program', 'curriculumVersion', 'user'])->lockForUpdate()->findOrFail($student->id);
            $existing = DegreeConferral::query()
                ->where('student_profile_id', $locked->id)
                ->whereNotNull('active_scope_key')
                ->lockForUpdate()->first();
            if ($existing instanceof DegreeConferral) {
                $sameRequest = $existing->degree_name === trim($degreeName)
                    && $existing->conferred_on?->toDateString() === $date?->toDateString()
                    && $existing->authority_reference === $authorityReference
                    && $existing->honor_text === (filled($honorText) ? trim((string) $honorText) : null)
                    && $existing->honor_authority_reference === (filled($honorAuthorityReference) ? trim((string) $honorAuthorityReference) : null);
                if ($sameRequest) {
                    return $existing;
                }

                throw ValidationException::withMessages(['conferral' => 'A different current conferral already exists. Use the authorized correction workflow.']);
            }

            $projection = $this->readiness->forStudent($locked);
            if ($projection['state'] !== CompletionReadinessProjection::ReadyForConferral) {
                throw ValidationException::withMessages(['conferral' => 'Current completion sources are not ready for conferral.']);
            }
            if (blank($locked->student_number)
                || blank($locked->first_name)
                || blank($locked->last_name)
                || $locked->program === null
                || $locked->curriculumVersion === null
                || $locked->user === null) {
                throw ValidationException::withMessages(['conferral' => 'Conferral requires a continuous Student identity, Program, Curriculum Version, and account.']);
            }
            $application = $projection['application'];
            if (! $application instanceof GraduationApplication || (int) $application->curriculum_version_id !== (int) $locked->curriculum_version_id) {
                $application = null;
            }
            $readiness = $this->readiness->persist($locked, $actor, 'conferral-precheck');
            $evaluation = $this->curriculum->forStudent($locked);
            $termId = filled($projection['source_snapshot']['final_term_enrollment_id'])
                ? Enrollment::query()->whereKey($projection['source_snapshot']['final_term_enrollment_id'])->value('term_id')
                : null;

            $conferral = DegreeConferral::query()->create([
                'student_profile_id' => $locked->id,
                'graduation_application_id' => $application?->id,
                'completion_readiness_version_id' => $readiness->id,
                'curriculum_version_id' => $locked->curriculum_version_id,
                'version' => 1,
                'active_scope_key' => (string) $locked->id,
                'program_name_snapshot' => $locked->program->name,
                'degree_name' => trim($degreeName),
                'conferred_on' => $date?->toDateString(),
                'authority_reference' => $authorityReference,
                'honor_text' => filled($honorText) ? trim((string) $honorText) : null,
                'honor_authority_reference' => filled($honorAuthorityReference) ? trim((string) $honorAuthorityReference) : null,
                'source_fingerprint' => $readiness->source_fingerprint,
                'final_evaluation_snapshot' => $evaluation,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            StudentLifecycleChange::query()->create([
                'student_profile_id' => $locked->id,
                'term_id' => $termId,
                'type' => StudentLifecycleChange::TypeCompletion,
                'effective_on' => $date?->toDateString() ?? today('Asia/Manila')->toDateString(),
                'decided_on' => today('Asia/Manila')->toDateString(),
                'authority' => $authorityReference ?? 'Recorded by authenticated Registrar #'.$actor->id,
                'private_source_reference' => "degree-conferral:{$conferral->id}",
                'reason' => 'Degree conferral recorded from verified completion sources.',
                'impact_snapshot' => [
                    'prior_lifecycle' => $locked->lifecycle_status,
                    'resulting_lifecycle' => StudentProfile::LifecycleCompleted,
                    'degree_conferral_id' => $conferral->id,
                ],
                'recorded_by' => $actor->id,
                'state' => StudentLifecycleChange::StateApplied,
            ]);
            $locked->update(['lifecycle_status' => StudentProfile::LifecycleCompleted]);
            $this->readiness->persist($locked, $actor, 'conferral');
            $this->notifications->reserveConferralAfterCommit($conferral);

            return $conferral;
        }, attempts: 3);
    }
}
