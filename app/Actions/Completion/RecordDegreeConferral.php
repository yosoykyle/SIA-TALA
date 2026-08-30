<?php

namespace App\Actions\Completion;

use App\Actions\Academics\CurriculumEvaluation;
use App\Models\DegreeConferral;
use App\Models\GraduationApplication;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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
        CarbonInterface|string $conferredOn,
        string $authorityReference,
        ?string $honorText = null,
        ?string $honorAuthorityReference = null,
    ): DegreeConferral {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may record degree conferral.');
        }
        if (blank(trim($degreeName)) || blank(trim($authorityReference))) {
            throw ValidationException::withMessages(['conferral' => 'Degree and conferral authority are required.']);
        }
        if (filled($honorText) && blank(trim((string) $honorAuthorityReference))) {
            throw ValidationException::withMessages(['honor' => 'Recorded honor text requires its external authority.']);
        }

        return DB::transaction(function () use ($student, $actor, $degreeName, $conferredOn, $authorityReference, $honorText, $honorAuthorityReference): DegreeConferral {
            $locked = StudentProfile::query()->with(['program', 'curriculumVersion'])->lockForUpdate()->findOrFail($student->id);
            $existing = DegreeConferral::query()
                ->where('student_profile_id', $locked->id)
                ->whereNotNull('active_scope_key')
                ->lockForUpdate()->first();
            if ($existing instanceof DegreeConferral) {
                $requestedDate = $conferredOn instanceof CarbonInterface
                    ? CarbonImmutable::instance($conferredOn)->toDateString()
                    : CarbonImmutable::parse($conferredOn, config('app.timezone'))->toDateString();
                $sameRequest = $existing->degree_name === trim($degreeName)
                    && $existing->conferred_on->toDateString() === $requestedDate
                    && $existing->authority_reference === trim($authorityReference)
                    && $existing->honor_text === (filled($honorText) ? trim((string) $honorText) : null)
                    && $existing->honor_authority_reference === (filled($honorAuthorityReference) ? trim((string) $honorAuthorityReference) : null);
                if ($sameRequest) {
                    return $existing;
                }

                throw ValidationException::withMessages(['conferral' => 'A different current conferral already exists. Use the authorized correction workflow.']);
            }

            $projection = $this->readiness->forStudent($locked);
            if ($projection['state'] !== CompletionReadinessProjection::ReadyForConferral
                || ! $projection['application'] instanceof GraduationApplication) {
                throw ValidationException::withMessages(['conferral' => 'Current completion sources are not ready for conferral.']);
            }
            if (blank($locked->student_number)
                || blank($locked->first_name)
                || blank($locked->last_name)
                || (int) $projection['application']->curriculum_version_id !== (int) $locked->curriculum_version_id) {
                throw ValidationException::withMessages(['conferral' => 'Conferral requires a continuous Student identity, Program, Curriculum Version, account, and matching Graduation Application.']);
            }
            $readiness = $this->readiness->persist($locked, $actor, 'conferral-precheck');
            $evaluation = $this->curriculum->forStudent($locked);
            $date = $conferredOn instanceof CarbonInterface
                ? CarbonImmutable::instance($conferredOn)
                : CarbonImmutable::parse($conferredOn, config('app.timezone'));

            $conferral = DegreeConferral::query()->create([
                'student_profile_id' => $locked->id,
                'graduation_application_id' => $projection['application']->id,
                'completion_readiness_version_id' => $readiness->id,
                'curriculum_version_id' => $locked->curriculum_version_id,
                'version' => 1,
                'active_scope_key' => (string) $locked->id,
                'program_name_snapshot' => $locked->program->name,
                'degree_name' => trim($degreeName),
                'conferred_on' => $date->toDateString(),
                'authority_reference' => trim($authorityReference),
                'honor_text' => filled($honorText) ? trim((string) $honorText) : null,
                'honor_authority_reference' => filled($honorAuthorityReference) ? trim((string) $honorAuthorityReference) : null,
                'source_fingerprint' => $readiness->source_fingerprint,
                'final_evaluation_snapshot' => $evaluation,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            StudentLifecycleChange::query()->create([
                'student_profile_id' => $locked->id,
                'term_id' => $projection['application']->term_id,
                'type' => StudentLifecycleChange::TypeCompletion,
                'effective_on' => $date->toDateString(),
                'decided_on' => today()->toDateString(),
                'authority' => trim($authorityReference),
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
