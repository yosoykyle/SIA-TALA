<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\Enrollment;
use App\Models\OfficialCredentialResult;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Admission papers the school receives outside TALA (#103 Scope 7): each is Passed once the Registrar
 * ticks it, otherwise To be followed. Shared by the admission application and the Student record.
 */
class AdmissionPaperChecklist
{
    public const PassedAuthority = 'Registrar enrollment clearance';

    /**
     * Papers grouped by how they reach the Registrar.
     *
     * @return array<string, array{label: string, items: list<array{id: int, label: string, due: string, required: bool, result: string|null, result_id: int|null, status: string, tone: string, requirement: AdmissionRequirement}>}>
     */
    public function categories(AdmissionApplication $application): array
    {
        $requirements = $application->currentSubmissionVersion?->requirementSet?->requirements ?? collect();
        $results = $application->credentialResults()->whereDoesntHave('successor')->get()->keyBy('admission_requirement_id');
        $categories = [
            AdmissionRequirement::SubmissionInPerson => 'Physical papers passed on-site',
            AdmissionRequirement::SubmissionSchoolToSchool => 'Records sent school-to-school',
        ];

        return collect($categories)->map(function (string $label, string $method) use ($requirements, $results): array {
            /** @var Collection<int, AdmissionRequirement> $papers */
            $papers = $requirements->where('official_submission_method', $method)->sortBy('display_order')->values();

            return [
                'label' => $label,
                'items' => $papers->map(function (AdmissionRequirement $requirement) use ($results): array {
                    $result = $results->get($requirement->id);
                    $passed = $result?->result === OfficialCredentialResult::ResultVerified;

                    return [
                        'id' => $requirement->id,
                        'label' => $requirement->label,
                        'due' => $requirement->dueStageLabel(),
                        'required' => $requirement->due_stage !== AdmissionRequirement::DuePostEnrollmentFollowUp,
                        'result' => $result?->result,
                        'result_id' => $result?->id,
                        'status' => $passed ? 'Passed' : 'To be followed',
                        'tone' => $passed ? 'verified' : 'held',
                        'requirement' => $requirement,
                    ];
                })->all(),
            ];
        })->filter(fn (array $category): bool => $category['items'] !== [])->all();
    }

    /** @return list<string> labels of papers not yet Passed */
    public function followed(AdmissionApplication $application): array
    {
        return collect($this->categories($application))->flatMap(fn (array $category): array => $category['items'])
            ->reject(fn (array $item): bool => $item['result'] === OfficialCredentialResult::ResultVerified)
            ->pluck('label')->values()->all();
    }

    /**
     * Records each ticked paper as Passed. Papers already Passed are left as they are.
     *
     * @param  list<int>  $checkedRequirementIds
     */
    public function recordPassed(AdmissionApplication $application, User $actor, array $checkedRequirementIds, string $authority = self::PassedAuthority): void
    {
        $items = collect($this->categories($application))->flatMap(fn (array $category): array => $category['items']);

        foreach ($items as $item) {
            if (! in_array($item['id'], $checkedRequirementIds, true) || $item['result'] === OfficialCredentialResult::ResultVerified) {
                continue;
            }

            app(RecordOfficialCredentialResult::class)->execute(
                $application,
                $item['requirement'],
                $actor,
                OfficialCredentialResult::ResultVerified,
                'On-site paper check',
                'Paper checked by the Registrar.',
                $authority,
                exceptionExpiresAt: null,
                expectedCurrentResultId: $item['result_id'],
            );
        }
    }

    /** The admission application this Student was officially enrolled from, if any. */
    public function applicationForStudent(StudentProfile $profile): ?AdmissionApplication
    {
        $applicationId = Enrollment::query()
            ->where('student_profile_id', $profile->id)
            ->whereNotNull('admission_application_id')
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->oldest('officially_enrolled_at')
            ->value('admission_application_id');

        return $applicationId === null ? null : AdmissionApplication::query()->find($applicationId);
    }
}
