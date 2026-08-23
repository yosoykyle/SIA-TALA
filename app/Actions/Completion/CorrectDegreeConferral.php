<?php

namespace App\Actions\Completion;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Models\DegreeConferral;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectDegreeConferral
{
    public function __construct(
        private readonly SupersedeTranscriptSnapshots $transcriptSupersession,
        private readonly AcademicRecordNotificationService $notifications,
    ) {}

    public function execute(
        DegreeConferral $conferral,
        User $actor,
        string $degreeName,
        CarbonInterface|string $conferredOn,
        string $authorityReference,
        string $reason,
        ?string $honorText = null,
        ?string $honorAuthorityReference = null,
    ): DegreeConferral {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may correct degree conferral.');
        }
        if (blank(trim($degreeName)) || blank(trim($authorityReference)) || blank(trim($reason))) {
            throw ValidationException::withMessages(['conferral' => 'Corrected degree, authority, and reason are required.']);
        }
        if (filled($honorText) && blank(trim((string) $honorAuthorityReference))) {
            throw ValidationException::withMessages(['honor' => 'Corrected honor text requires its external authority.']);
        }

        return DB::transaction(function () use ($conferral, $actor, $degreeName, $conferredOn, $authorityReference, $reason, $honorText, $honorAuthorityReference): DegreeConferral {
            $locked = DegreeConferral::query()->with('application')->lockForUpdate()->findOrFail($conferral->id);
            if ($locked->active_scope_key === null) {
                $successor = DegreeConferral::query()->where('supersedes_conferral_id', $locked->id)->first();
                if ($successor instanceof DegreeConferral) {
                    return $successor;
                }

                throw ValidationException::withMessages(['conferral' => 'Only the current conferral may be corrected.']);
            }
            $student = StudentProfile::query()->lockForUpdate()->findOrFail($locked->student_profile_id);
            $date = $conferredOn instanceof CarbonInterface
                ? CarbonImmutable::instance($conferredOn)
                : CarbonImmutable::parse($conferredOn, config('app.timezone'));
            $source = [
                'predecessor_conferral_id' => $locked->id,
                'degree_name' => trim($degreeName),
                'conferred_on' => $date->toDateString(),
                'authority_reference' => trim($authorityReference),
                'reason' => trim($reason),
                'honor_text' => filled($honorText) ? trim((string) $honorText) : null,
                'honor_authority_reference' => filled($honorAuthorityReference) ? trim((string) $honorAuthorityReference) : null,
            ];

            $locked->update(['active_scope_key' => null]);
            $successor = DegreeConferral::query()->create([
                ...$locked->only([
                    'student_profile_id', 'graduation_application_id', 'completion_readiness_version_id',
                    'curriculum_version_id', 'program_name_snapshot', 'final_evaluation_snapshot',
                ]),
                'version' => $locked->version + 1,
                'supersedes_conferral_id' => $locked->id,
                'active_scope_key' => (string) $locked->student_profile_id,
                'degree_name' => trim($degreeName),
                'conferred_on' => $date->toDateString(),
                'authority_reference' => trim($authorityReference),
                'honor_text' => $source['honor_text'],
                'honor_authority_reference' => $source['honor_authority_reference'],
                'source_fingerprint' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
            $change = StudentLifecycleChange::query()->create([
                'student_profile_id' => $student->id,
                'term_id' => $locked->application->term_id,
                'type' => StudentLifecycleChange::TypeCompletion,
                'effective_on' => $date->toDateString(),
                'decided_on' => today()->toDateString(),
                'authority' => trim($authorityReference),
                'private_source_reference' => "degree-conferral-correction:{$successor->id}",
                'reason' => trim($reason),
                'impact_snapshot' => [
                    'predecessor_conferral_id' => $locked->id,
                    'successor_conferral_id' => $successor->id,
                    'resulting_lifecycle' => StudentProfile::LifecycleCompleted,
                ],
                'recorded_by' => $actor->id,
                'state' => StudentLifecycleChange::StateApplied,
            ]);
            $this->transcriptSupersession->execute($student, $actor, $authorityReference, $reason);
            $this->notifications->recordLifecycleAfterCommit($change);

            return $successor;
        }, attempts: 3);
    }
}
