<?php

namespace Database\Seeders;

use App\Actions\Admissions\PublishAdmissionCycle;
use App\Actions\Admissions\PublishAdmissionRequirementSet;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdmissionsAcceptanceSeeder extends Seeder
{
    public function run(): void
    {
        if (AdmissionCycle::query()->where('code', 'CYCLE-2026-A')->exists()) {
            return;
        }

        $term = Term::query()->where('state', Term::StateActive)->orderByDesc('starts_on')->first();
        $registrar = User::role(User::StaffRoleRegistrar)->orderBy('id')->first();
        $programs = Program::query()
            ->whereIn('code', ['DBM', 'DIT', 'DTHM'])
            ->where('is_active', true)
            ->get();

        if (! $term instanceof Term || ! $registrar instanceof User || $programs->count() !== 3) {
            throw new RuntimeException('The coordinated Term, Registrar, and DBM/DIT/DTHM Programs must exist before seeding CYCLE-2026-A.');
        }

        DB::transaction(function () use ($term, $registrar, $programs): void {
            $cycle = AdmissionCycle::query()->create([
                'code' => 'CYCLE-2026-A',
                'label' => 'Synthetic First-Year and Transferee Admission Cycle',
                'term_id' => $term->id,
                'state' => AdmissionCycle::StateDraft,
                'opens_at' => '2026-08-01 08:00:00',
                'closes_at' => '2026-09-30 17:00:00',
                'applicant_instructions' => 'Complete the five-step TALA Application and provide the requirement-version evidence shown in the Applicant workspace.',
                'support_contact' => 'Servitech Facebook or 0947 737 9208',
                'privacy_notice_reference' => 'tala-privacy:2026-08',
                'registrar_owner_id' => $registrar->id,
            ]);

            $cycle->programs()->sync($programs->mapWithKeys(fn (Program $program): array => [
                $program->id => [
                    'accepts_first_year' => true,
                    'accepts_transferee' => true,
                ],
            ])->all());

            $firstYear = $this->createRequirementSet($cycle, AdmissionCycle::PathFirstYear, $this->firstYearRequirements());
            $transferee = $this->createRequirementSet($cycle, AdmissionCycle::PathTransferee, $this->transfereeRequirements());

            app(PublishAdmissionRequirementSet::class)->execute(
                $firstYear,
                $registrar,
                'PRD-02 synthetic First-Year Requirement Set authority',
            );
            app(PublishAdmissionRequirementSet::class)->execute(
                $transferee,
                $registrar,
                'PRD-02 synthetic Transferee Requirement Set authority',
            );
            app(PublishAdmissionCycle::class)->execute(
                $cycle,
                $registrar,
                'PRD-02 coordinated synthetic acceptance authority',
            );
        }, attempts: 3);
    }

    /** @param list<array<string, mixed>> $requirements */
    private function createRequirementSet(
        AdmissionCycle $cycle,
        string $path,
        array $requirements,
    ): AdmissionRequirementSet {
        $set = AdmissionRequirementSet::query()->create([
            'admission_cycle_id' => $cycle->id,
            'application_path' => $path,
            'version' => 1,
            'state' => AdmissionRequirementSet::StateDraft,
            'authority_reference' => 'PRD-02 coordinated synthetic acceptance requirements',
            'effective_at' => $cycle->opens_at,
        ]);

        foreach ($requirements as $requirement) {
            $set->requirements()->create($requirement);
        }

        return $set;
    }

    /** @return list<array<string, mixed>> */
    private function firstYearRequirements(): array
    {
        return [
            $this->requirement('FIRST-YEAR-FORM-138', 'Form 138 or equivalent', 'Establish the official completion credential required before enrollment.', AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential, AdmissionRequirement::SubmissionInPerson, AdmissionRequirement::DueEnrollmentReadiness, true, 10),
            $this->requirement('FIRST-YEAR-FORM-137', 'Form 137 official school record', 'Retain the institution-to-institution official record after enrollment.', AdmissionRequirement::ClassificationCoreOtherOfficialCredential, AdmissionRequirement::SubmissionSchoolToSchool, AdmissionRequirement::DuePostEnrollmentFollowUp, false, 20),
            $this->requirement('FIRST-YEAR-SUPPLEMENTAL-ID', 'Supplemental identity record', 'Support bounded identity review without replacing the official core credential.', AdmissionRequirement::ClassificationNonCore, AdmissionRequirement::SubmissionInPerson, AdmissionRequirement::DuePreliminaryReview, true, 30, true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function transfereeRequirements(): array
    {
        return [
            $this->requirement('TRANSFER-CREDENTIAL', 'Transfer Credential or Certificate of Transfer', 'Establish authorized transfer from the prior college before enrollment.', AdmissionRequirement::ClassificationCoreTransferCredential, AdmissionRequirement::SubmissionSchoolToSchool, AdmissionRequirement::DueEnrollmentReadiness, true, 10),
            $this->requirement('TRANSFER-OFFICIAL-RECORDS', 'Official prior-college records', 'Preserve the later institution-to-institution official-record follow-up.', AdmissionRequirement::ClassificationCoreOtherOfficialCredential, AdmissionRequirement::SubmissionSchoolToSchool, AdmissionRequirement::DuePostEnrollmentFollowUp, true, 20),
        ];
    }

    /** @return array<string, mixed> */
    private function requirement(
        string $code,
        string $label,
        string $purpose,
        string $classification,
        string $method,
        string $dueStage,
        bool $preliminary,
        int $order,
        bool $exceptionPermitted = false,
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'authority_reference' => 'PRD-02 coordinated synthetic requirement authority',
            'purpose' => $purpose,
            'credential_classification' => $classification,
            'requires_preliminary_evidence' => $preliminary,
            'official_submission_method' => $method,
            'due_stage' => $dueStage,
            'applicant_instructions' => $preliminary
                ? 'Upload one private preliminary PDF, JPEG, or PNG copy up to 10 MiB and follow the official-submission instruction separately.'
                : 'Follow the official-submission instruction shown by the Registrar.',
            'registrar_instructions' => 'Keep preliminary review distinct from the official credential result and record every successor outcome.',
            'exception_permitted' => $exceptionPermitted,
            'required_approving_authority' => $exceptionPermitted ? 'Registrar with recorded bounded authority' : null,
            'display_order' => $order,
        ];
    }
}
