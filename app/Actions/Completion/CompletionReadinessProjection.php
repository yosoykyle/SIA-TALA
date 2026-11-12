<?php

namespace App\Actions\Completion;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Models\CompletionReadinessVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\ExternalCompetencyRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\GraduationApplication;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompletionReadinessProjection
{
    public const NotEligible = 'NotEligible';

    public const EligibleToApply = 'EligibleToApply';

    public const AwaitingResultsOrClearance = 'AwaitingResultsOrClearance';

    public const ReadyForConferral = 'ReadyForConferral';

    public const Conferred = 'Conferred';

    public function __construct(
        private readonly CurriculumEvaluation $curriculum,
        private readonly OfficialCourseResultProjection $results,
        private readonly CompletionNotificationService $notifications,
    ) {}

    /** @return array{state: string, blockers: list<array{source: string, owner: string, reason: string, recovery: string}>, source_snapshot: array<string, mixed>, source_fingerprint: string, application: GraduationApplication|null, conferral: DegreeConferral|null} */
    public function forStudent(StudentProfile $student): array
    {
        $student->loadMissing(['program', 'curriculumVersion']);
        $curriculum = $this->curriculum->forStudent($student);
        $results = $this->results->forStudent($student);
        $application = GraduationApplication::query()
            ->where('student_profile_id', $student->id)
            ->where('state', GraduationApplication::StateActive)
            ->latest('version')->first();
        $conferral = DegreeConferral::query()
            ->where('student_profile_id', $student->id)
            ->whereNotNull('active_scope_key')
            ->latest('version')->first();
        $finalTermEnrollment = Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->whereNotNull('officially_enrolled_at')
            ->latest('term_id')
            ->first();

        $blockers = [];
        if ($student->curriculumVersion === null || count($curriculum['required']) === 0) {
            $blockers[] = [
                'code' => 'curriculum:requirements-missing',
                'source' => 'CurriculumEvaluation',
                'source_ref' => $student->curriculum_version_id,
                'source_as_of' => $student->curriculumVersion?->updated_at?->toIso8601String(),
                'owner' => 'Registrar',
                'reason' => 'The student’s curriculum requirements are not available for evaluation.',
                'consequence' => 'Graduation cannot be recorded without a complete curriculum evaluation.',
                'recovery' => 'Verify the assigned curriculum version and its required courses before recording graduation.',
            ];
        }
        $hasInProgressRequirement = false;
        foreach ($curriculum['required'] as $row) {
            if (in_array($row['status'], ['Completed', 'Approved credit'], true)) {
                continue;
            }

            $code = $row['curriculum_entry']->courseSpecification?->course->code ?? 'Curriculum requirement';
            $owner = $row['status'] === 'In progress' ? 'Registrar and Faculty' : 'Registrar';
            $hasInProgressRequirement = $hasInProgressRequirement || $row['status'] === 'In progress';
            $blockers[] = [
                'code' => "curriculum-entry:{$row['curriculum_entry']->id}:{$row['status']}",
                'source' => 'CurriculumEvaluation',
                'source_ref' => (string) $row['curriculum_entry']->id,
                'source_as_of' => $row['curriculum_entry']->updated_at?->toIso8601String(),
                'owner' => $owner,
                'reason' => "{$code}: {$row['status']}",
                'consequence' => $row['status'] === 'In progress'
                    ? 'Conferral must wait for the current official result.'
                    : 'Graduation cannot be recorded while this curriculum requirement is unresolved.',
                'recovery' => $row['status'] === 'In progress'
                    ? 'Complete the current official course and wait for Registrar release.'
                    : 'Resolve the named curriculum deficiency through Registrar advising.',
            ];
        }

        if ($hasInProgressRequirement && ! $finalTermEnrollment instanceof Enrollment && ! $conferral instanceof DegreeConferral) {
            $blockers[] = [
                'code' => 'official-enrollment:final-term-missing',
                'source' => 'OfficialEnrollment',
                'source_ref' => null,
                'source_as_of' => $student->curriculumVersion?->updated_at?->toIso8601String(),
                'owner' => 'Registrar',
                'reason' => 'Final-term official enrollment is not recorded.',
                'consequence' => 'In-progress completion evidence requires its exact official enrollment context.',
                'recovery' => 'Registrar verifies and records the authoritative final-term enrollment source.',
            ];
        }

        $incompleteResult = $results->first(fn (array $result): bool => $result['result'] === 'INC' && ! ($result['inc_settled'] ?? false));
        if (is_array($incompleteResult)) {
            $blockers[] = [
                'code' => 'official-result:inc-unresolved',
                'source' => 'OfficialCourseResultProjection',
                'source_ref' => (string) data_get($incompleteResult, 'event.id'),
                'source_as_of' => data_get($incompleteResult, 'event.released_at')?->toIso8601String(),
                'owner' => 'Registrar and designated Faculty',
                'reason' => 'An official INC remains unresolved.',
                'consequence' => 'Conferral remains unavailable while the official INC is unresolved.',
                'recovery' => 'Complete the authorized INC path or retake the course when completion is closed.',
            ];
        }

        $requiredCompetencies = ExternalCompetencyRequirement::query()
            ->applicableTo($student)
            ->where('treatment', ExternalCompetencyRequirement::TreatmentCompletionRequired)
            ->withCurrentResultFor($student)
            ->get();
        foreach ($requiredCompetencies as $requirement) {
            if ($requirement->currentResult?->outcome !== ExternalCompetencyResult::OutcomeCompetent) {
                $blockers[] = [
                    'code' => "external-competency:{$requirement->id}:not-competent",
                    'source' => "ExternalCompetencyRequirement:{$requirement->requirement_code}",
                    'source_ref' => (string) $requirement->id,
                    'source_as_of' => $requirement->updated_at?->toIso8601String(),
                    'owner' => 'External assessor and Registrar',
                    'reason' => "Required competency {$requirement->qualification_label} is not yet verified as competent.",
                    'consequence' => 'Graduation cannot be recorded until the required external competency is verified.',
                    'recovery' => 'Registrar records the verified external result against the active requirement.',
                ];
            }
        }

        Hold::query()
            ->where('student_profile_id', $student->id)
            ->where('status', Hold::StatusActive)
            ->where('blocking_level', Hold::BlockingGraduationEligibility)
            ->get()
            ->each(function (Hold $hold) use (&$blockers): void {
                $blockers[] = [
                    'code' => "hold:{$hold->id}:graduation-eligibility",
                    'source' => "Hold:{$hold->id}",
                    'source_ref' => (string) $hold->id,
                    'source_as_of' => $hold->updated_at?->toIso8601String(),
                    'owner' => $hold->studentFacingOfficeLabel(),
                    'reason' => $hold->studentFacingMessage() ?? 'A named completion clearance is unresolved.',
                    'consequence' => 'Graduation cannot be recorded while this named completion clearance is unresolved.',
                    'recovery' => $hold->resolution_requirement ?: 'Use the authorized hold-resolution path.',
                ];
            });

        $blockers = collect($blockers)->sortBy('code')->values()->all();
        $hardBlockers = collect($blockers)->reject(fn (array $blocker): bool => str_starts_with($blocker['code'], 'curriculum-entry:')
            && str_ends_with($blocker['code'], ':In progress'));

        $state = match (true) {
            $conferral instanceof DegreeConferral => self::Conferred,
            $hardBlockers->isNotEmpty() => self::NotEligible,
            $blockers !== [] => self::AwaitingResultsOrClearance,
            default => self::ReadyForConferral,
        };

        $sourceSnapshot = [
            'student_profile_id' => $student->id,
            'curriculum_version_id' => $student->curriculum_version_id,
            'curriculum' => collect($curriculum['required'])->map(fn (array $row): array => [
                'entry_id' => $row['curriculum_entry']->id,
                'status' => $row['status'],
                'credited_units' => $row['credited_units'],
            ])->values()->all(),
            'result_event_ids' => $results->pluck('event.id')->filter()->values()->all(),
            'application_id' => $application?->id,
            'conferral_id' => $conferral?->id,
            'final_term_enrollment_id' => $finalTermEnrollment?->id,
            'blockers' => $blockers,
        ];

        return [
            'state' => $state,
            'blockers' => $blockers,
            'source_snapshot' => $sourceSnapshot,
            'source_fingerprint' => hash('sha256', json_encode($sourceSnapshot, JSON_THROW_ON_ERROR)),
            'application' => $application,
            'conferral' => $conferral,
        ];
    }

    public function persist(
        StudentProfile $student,
        ?User $actor = null,
        string $cause = 'source-change',
    ): CompletionReadinessVersion {
        return DB::transaction(function () use ($student, $actor, $cause): CompletionReadinessVersion {
            $lockedStudent = StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $projection = $this->forStudent($lockedStudent);
            $previous = CompletionReadinessVersion::query()
                ->where('student_profile_id', $lockedStudent->id)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if ($previous?->source_fingerprint === $projection['source_fingerprint']
                && $previous->state === $projection['state']) {
                return $previous;
            }

            $readiness = CompletionReadinessVersion::query()->create([
                'student_profile_id' => $lockedStudent->id,
                'graduation_application_id' => $projection['application']?->id,
                'version' => ((int) $previous?->version) + 1,
                'supersedes_readiness_id' => $previous?->id,
                'state' => $projection['state'],
                'source_fingerprint' => $projection['source_fingerprint'],
                'source_snapshot' => $projection['source_snapshot'],
                'blockers' => $projection['blockers'],
                'generated_by' => $actor?->id,
                'generated_at' => now(),
            ]);

            if ($cause === 'source-change') {
                $this->notifications->reserveReadinessAfterCommit($readiness, $previous);
            }

            return $readiness;
        }, attempts: 3);
    }
}
