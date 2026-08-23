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
        if (! $finalTermEnrollment instanceof Enrollment && ! $conferral instanceof DegreeConferral) {
            $blockers[] = [
                'source' => 'OfficialEnrollment',
                'owner' => 'Registrar',
                'reason' => 'Final-term official enrollment is not recorded.',
                'recovery' => 'Registrar verifies and records the authoritative final-term enrollment source.',
            ];
        }
        foreach ($curriculum['required'] as $row) {
            if (in_array($row['status'], ['Completed', 'Approved credit'], true)) {
                continue;
            }

            $code = $row['curriculum_entry']->courseSpecification?->course->code ?? 'Curriculum requirement';
            $owner = $row['status'] === 'In progress' ? 'Registrar and Faculty' : 'Registrar';
            $blockers[] = [
                'source' => 'CurriculumEvaluation',
                'owner' => $owner,
                'reason' => "{$code}: {$row['status']}",
                'recovery' => $row['status'] === 'In progress'
                    ? 'Complete the current official course and wait for Registrar release.'
                    : 'Resolve the named curriculum deficiency through Registrar advising.',
            ];
        }

        if ($results->contains(fn (array $result): bool => $result['result'] === 'INC')) {
            $blockers[] = [
                'source' => 'OfficialCourseResultProjection',
                'owner' => 'Registrar and designated Faculty',
                'reason' => 'An official INC remains unresolved.',
                'recovery' => 'Complete the authorized INC path or retake the course when completion is closed.',
            ];
        }

        $requiredCompetencies = ExternalCompetencyRequirement::query()
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->where('state', 'ACTIVE')
            ->where('treatment', ExternalCompetencyRequirement::TreatmentCompletionRequired)
            ->get();
        foreach ($requiredCompetencies as $requirement) {
            $competent = ExternalCompetencyResult::query()
                ->where('student_profile_id', $student->id)
                ->where('external_competency_requirement_id', $requirement->id)
                ->where('is_current', true)
                ->where('outcome', ExternalCompetencyResult::OutcomeCompetent)
                ->exists();

            if (! $competent) {
                $blockers[] = [
                    'source' => "ExternalCompetencyRequirement:{$requirement->requirement_code}",
                    'owner' => 'External assessor and Registrar',
                    'reason' => "Required competency {$requirement->qualification_label} is not yet verified as competent.",
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
                    'source' => "Hold:{$hold->id}",
                    'owner' => $hold->studentFacingOfficeLabel(),
                    'reason' => $hold->studentFacingMessage() ?? 'A named completion clearance is unresolved.',
                    'recovery' => $hold->resolution_requirement ?: 'Use the authorized hold-resolution path.',
                ];
            });

        $state = match (true) {
            $conferral instanceof DegreeConferral => self::Conferred,
            $application instanceof GraduationApplication && $blockers !== [] => self::AwaitingResultsOrClearance,
            $application instanceof GraduationApplication => self::ReadyForConferral,
            $blockers !== [] && ! collect($blockers)->every(fn (array $blocker): bool => str_contains($blocker['reason'], 'In progress')) => self::NotEligible,
            default => self::EligibleToApply,
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

    public function persist(StudentProfile $student, ?User $actor = null): CompletionReadinessVersion
    {
        $projection = $this->forStudent($student);
        $previous = CompletionReadinessVersion::query()
            ->where('student_profile_id', $student->id)
            ->latest('version')->first();

        if ($previous?->source_fingerprint === $projection['source_fingerprint']
            && $previous->state === $projection['state']) {
            return $previous;
        }

        return CompletionReadinessVersion::query()->create([
            'student_profile_id' => $student->id,
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
    }
}
