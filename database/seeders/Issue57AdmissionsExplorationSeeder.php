<?php

namespace Database\Seeders;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\PublishAdmissionCycle;
use App\Actions\Admissions\PublishAdmissionRequirementSet;
use App\Actions\Admissions\RecordAdmissionDecision;
use App\Actions\Admissions\RecordRegistrarEnrollmentClearance;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\SystemAdministration\TAL96D5E1ExplorationPersonaCatalog;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class Issue57AdmissionsExplorationSeeder extends Seeder
{
    public const CycleCode = 'ISSUE57-EXPLORATION';

    public const RegistrarEmail = 'registrar.issue57.demo@example.test';

    public function run(): void
    {
        $this->assertSafe();
        $existing = AdmissionCycle::query()->where('code', self::CycleCode)->first();
        $term = $existing?->term ?? Term::query()->where('state', Term::StateActive)->orderByDesc('starts_on')->first();
        $program = $existing?->programs()->first() ?? Program::query()->where('is_active', true)->orderBy('id')->first();
        if (! $term instanceof Term || ! $program instanceof Program) {
            $term ??= Term::factory()->create(['state' => Term::StateActive, 'label' => 'Issue #57 synthetic term']);
            $program ??= Program::factory()->create(['code' => 'ISSUE57', 'name' => 'Issue #57 synthetic program', 'is_active' => true]);
        }

        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate('applicant', 'web');
        $this->ensure($term, $program, $this->ensureExplorationRegistrar());
    }

    private function ensureExplorationRegistrar(): User
    {
        return DB::transaction(function (): User {
            $existing = User::query()->where('email', self::RegistrarEmail)->lockForUpdate()->first();

            if ($existing instanceof User) {
                if (! $existing->hasRole(User::StaffRoleRegistrar) || ! $existing->canAuthenticate()
                    || $existing->email_verified_at === null || blank($existing->getAppAuthenticationSecret())
                    || blank($existing->getAppAuthenticationRecoveryCodes())
                    || $existing->two_factor_recovery_codes_acknowledged_at === null) {
                    throw new RuntimeException('The retained Issue #57 Registrar fixture needs inspection; its authentication settings were preserved.');
                }

                return $existing;
            }

            $role = Role::findByName(User::StaffRoleRegistrar, 'web');
            $permissions = array_map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
                ['approve-documents', 'manage-admission-setup']);
            $registrar = User::factory()->create([
                ...User::staffNamePayload('Synthetic', null, 'Registrar'),
                'email' => self::RegistrarEmail, 'username' => 'registrar-issue57-demo',
                'status' => User::StatusActive,
            ]);
            $registrar->assignRole($role);
            $registrar->givePermissionTo($permissions);
            $authentication = app(TalaAppAuthentication::class)->recoverable();
            $authentication->saveSecret($registrar, $authentication->generateSecret());
            $authentication->saveRecoveryCodes($registrar, $authentication->generateRecoveryCodes());
            $registrar->acknowledgeRecoveryCodeStorage();

            return $registrar->fresh();
        });
    }

    public function ensure(Term $term, Program $program, User $registrar): void
    {
        $this->assertSafe();

        if (! $registrar->canAuthenticate() || ! $registrar->can('approve-documents')
            || ! $registrar->can('manage-admission-setup') || ! $registrar->hasRole(User::StaffRoleRegistrar)
            || $term->state !== Term::StateActive || ! $program->is_active) {
            throw new RuntimeException('Use an active Term/Program and a Registrar with existing admission setup/review authorization.');
        }

        $mailer = Mail::getFacadeRoot();
        Mail::fake();

        try {
            DB::transaction(function () use ($term, $program, $registrar): void {
                $cycle = $this->ensureCycle($term, $program, $registrar);

                $definitions = app(TAL96D5E1ExplorationPersonaCatalog::class)->applicants();
                $definitions['applicant.minor.demo@example.test'] = [
                    ...$definitions['applicant.demo@example.test'],
                    'birth_date' => '2010-01-15', 'guardian_full_name' => 'Synthetic Parent',
                    'guardian_relationship' => 'Parent', 'guardian_mobile' => '09170000002',
                ];
                $definitions['applicant.als.demo@example.test'] = [
                    ...$definitions['applicant.review.demo@example.test'],
                    'credential_basis' => 'ALS_AE', 'lrn_availability' => 'NotAvailable',
                    'prior_school_name' => 'Synthetic ALS learning center',
                ];

                foreach ($definitions as $email => $definition) {
                    $user = User::query()->where('email', $email)->first();

                    if (! $user instanceof User) {
                        $user = User::factory()->create([
                            ...User::staffNamePayload('Synthetic', null, str($email)->before('@')->after('applicant.')->replace('-', ' ')->headline()->toString()),
                            'email' => $email,
                            'username' => str($email)->before('@')->replace('.', '-')->toString(),
                            'status' => User::StatusActive,
                        ]);
                        $user->assignRole('applicant');
                    } elseif ($user->hasRole('applicant') && in_array($user->status, [
                        User::StatusApplicantPending, User::StatusApplicantActionRequired,
                        User::StatusApplicantForEvaluation, User::StatusApplicantApproved, User::StatusApplicantWithdrawn,
                    ], true)) {
                        $user->forceFill(['status' => User::StatusActive, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
                    } elseif (! $user->hasRole('applicant') || ! $user->canAuthenticate() || $user->email_verified_at === null) {
                        throw new RuntimeException("The retained synthetic account {$email} needs an authorized authentication repair before exploration seeding.");
                    }

                    if (! $cycle->applications()->where('user_id', $user->id)->exists()) {
                        $this->createApplication($cycle, $program, $registrar, $user, $definition);
                    }
                }
            }, attempts: 3);
        } finally {
            Mail::swap($mailer);
        }
    }

    private function assertSafe(): void
    {
        $connection = DB::connection();

        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'test_tala_db') {
            throw new RuntimeException('Admissions exploration requires APP_ENV=testing and configured MySQL test_tala_db.');
        }

        if (($connection->selectOne('SELECT DATABASE() AS selected_database')->selected_database ?? null) !== 'test_tala_db') {
            throw new RuntimeException('Admissions exploration requires the server-selected database test_tala_db.');
        }
    }

    private function ensureCycle(Term $term, Program $program, User $registrar): AdmissionCycle
    {
        $existing = AdmissionCycle::query()->where('code', self::CycleCode)->lockForUpdate()->first();

        if ($existing instanceof AdmissionCycle) {
            if ($existing->term_id !== $term->id || ! $existing->programs()->whereKey($program->id)->exists()) {
                throw new RuntimeException('The retained exploration cycle has different Term/Program bindings; preserve it and inspect before seeding.');
            }

            return $existing;
        }

        $cycle = AdmissionCycle::factory()->create([
            'code' => self::CycleCode, 'label' => 'Synthetic Applicant–Registrar exploration',
            'term_id' => $term->id, 'state' => AdmissionCycle::StateDraft,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(14), 'correction_closes_at' => now()->addDays(21),
            'applicant_instructions' => 'Synthetic exploration only: complete the five-step application and follow the named requirement instructions.',
            'support_contact' => 'Synthetic Registrar support', 'privacy_notice_reference' => 'synthetic:issue57:privacy:v1',
            'registrar_owner_id' => $registrar->id,
        ]);
        $cycle->programs()->attach($program->id, ['accepts_first_year' => true, 'accepts_transferee' => true]);

        foreach ([AdmissionApplication::PathFirstYear, AdmissionApplication::PathTransferee] as $path) {
            $set = AdmissionRequirementSet::factory()->for($cycle)->create([
                'application_path' => $path, 'version' => 1, 'effective_at' => $cycle->opens_at,
            ]);
            $factory = AdmissionRequirement::factory()->for($set, 'requirementSet');
            ($path === AdmissionApplication::PathFirstYear ? $factory->firstYearCompletionCredential() : $factory->transferCredential())->create([
                'code' => 'CORE-CREDENTIAL', 'label' => $path === AdmissionApplication::PathFirstYear ? 'Form 138 or equivalent' : 'Transfer Credential',
                'requires_preliminary_evidence' => false,
                'applicant_instructions' => 'Bring the applicable school credential to the Registrar. Paper checking remains outside TALA; the Registrar records one enrollment clearance.',
            ]);
            AdmissionRequirement::factory()->for($set, 'requirementSet')->create([
                'code' => 'PSA-COPY', 'label' => 'PSA birth certificate',
                'credential_classification' => AdmissionRequirement::ClassificationNonCore,
                'due_stage' => AdmissionRequirement::DuePreliminaryReview,
                'official_submission_method' => AdmissionRequirement::SubmissionNone,
                'applicant_instructions' => 'Upload one legible private copy for preliminary review.', 'display_order' => 20,
            ]);
            AdmissionRequirement::factory()->for($set, 'requirementSet')->create([
                'code' => 'ID-PHOTO', 'label' => '2x2 ID photo',
                'credential_classification' => AdmissionRequirement::ClassificationNonCore,
                'due_stage' => AdmissionRequirement::DuePreliminaryReview,
                'official_submission_method' => AdmissionRequirement::SubmissionNone,
                'applicant_instructions' => 'Upload one clear JPEG or PNG image for identity-record comparison.', 'display_order' => 30,
            ]);
            app(PublishAdmissionRequirementSet::class)->execute($set, $registrar, 'Issue #57 synthetic requirement authority');
        }

        return app(PublishAdmissionCycle::class)->execute($cycle, $registrar, 'Issue #57 synthetic exploration authority');
    }

    /** @param array{label:string,application_state:string,application_path:string,credential_basis:string,user_status:string,reviewed:bool,ready:bool} $definition */
    private function createApplication(AdmissionCycle $cycle, Program $program, User $registrar, User $user, array $definition): void
    {
        $application = app(SaveAdmissionApplication::class)->execute($user, $cycle, [
            'program_id' => $program->id, 'application_path' => $definition['application_path'], 'credential_basis' => $definition['credential_basis'],
            'first_name' => $user->first_name, 'last_name' => $user->last_name, 'birth_date' => $definition['birth_date'] ?? '2000-01-15',
            'guardian_full_name' => $definition['guardian_full_name'] ?? null,
            'guardian_relationship' => $definition['guardian_relationship'] ?? null,
            'guardian_mobile' => $definition['guardian_mobile'] ?? null,
            'citizenship_country_code' => 'PH', 'phone' => '09170000001', 'current_city_municipality' => 'Synthetic City',
            'current_province' => 'Synthetic Province', 'prior_school_name' => $definition['prior_school_name'] ?? 'Synthetic Prior School',
            'prior_school_country_code' => 'PH', 'prior_school_completion_year' => now()->year - 1,
            'privacy_acknowledged' => true, 'accuracy_declared' => true, 'lrn_availability' => $definition['lrn_availability'] ?? 'NotIssued',
        ]);

        if ($definition['application_state'] === AdmissionApplication::StateDraft) {
            return;
        }

        $requirements = $cycle->requirementSets()->where('application_path', $definition['application_path'])->sole()->requirements()->get();
        $evidence = [];

        foreach ($requirements->where('requires_preliminary_evidence', true) as $requirement) {
            $evidence[$requirement->id] = app(AdmissionEvidenceService::class)->store($application, $requirement, $user, str_contains(strtolower($requirement->label), 'photo') ? UploadedFile::fake()->image('synthetic-id-photo.png', 160, 160) : $this->evidenceFile());
        }

        $application = app(SubmitAdmissionApplication::class)->execute($application, $user);

        if ($definition['reviewed'] || $definition['application_state'] === AdmissionApplication::StateActionNeeded) {
            foreach ($evidence as $file) {
                app(ReviewPreliminaryEvidence::class)->execute($file, $registrar,
                    PreliminaryEvidenceReview::ResultAccepted, 'Synthetic preliminary copy accepted for review only.');
            }
        }

        if ($definition['application_state'] === AdmissionApplication::StateActionNeeded) {
            $requirement = $requirements->firstWhere('due_stage', AdmissionRequirement::DuePreliminaryReview);
            app(RequestAdmissionCorrection::class)->execute($application, $registrar, [[
                'type' => ApplicationCorrectionItem::ScopeEvidence, 'key' => $requirement->code, 'admission_requirement_id' => $requirement->id,
            ]], 'Replace only the named identity evidence with a legible copy.', 'Applicant', now()->addDays(7));
            $replacement = app(AdmissionEvidenceService::class)->replace($evidence[$requirement->id], $user, str_contains(strtolower($requirement->label), 'photo') ? UploadedFile::fake()->image('replacement-id-photo.png', 161, 161) : $this->evidenceFile('Version 2 - replacement for preliminary review'));
            app(ReviewPreliminaryEvidence::class)->execute($replacement, $registrar,
                PreliminaryEvidenceReview::ResultActionNeeded, 'The replacement remains unreadable; provide a legible copy.');
        } elseif (in_array($definition['application_state'], [AdmissionApplication::StateAdmitted, AdmissionApplication::StateNotAdmitted], true)) {
            app(RecordAdmissionDecision::class)->execute($application, $registrar,
                $definition['application_state'] === AdmissionApplication::StateAdmitted ? AdmissionDecision::DecisionAdmitted : AdmissionDecision::DecisionNotAdmitted,
                'Synthetic Registrar decision for exploration.', 'Issue #57 synthetic decision authority',
                'The synthetic admission review is complete. Contact the Registrar for the permitted next step.');

            if ($definition['ready']) {
                $application = $application->fresh();
                app(RecordRegistrarEnrollmentClearance::class)->execute(
                    $application, $registrar, 'Cleared', true,
                    authorityReference: 'Issue #57 synthetic external-check authority',
                    expectedDecisionId: $application->decisions()->whereDoesntHave('successor')->sole()->id,
                    expectedSubmissionVersionId: $application->current_submission_version_id,
                );
            }
        } elseif ($definition['application_state'] === AdmissionApplication::StateWithdrawn) {
            app(ChangeAdmissionApplicationLifecycle::class)->withdrawByApplicant($application, $user, 'Synthetic withdrawn history for exploration.');
        }
    }

    private function evidenceFile(string $versionLabel = 'Version 1 - original preliminary review copy'): UploadedFile
    {
        $stream = 'BT /F1 18 Tf 50 740 Td (Synthetic admission evidence - exploration only) Tj 0 -30 Td ('.$versionLabel.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return UploadedFile::fake()->createWithContent('synthetic-evidence.pdf', $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n");
    }
}
