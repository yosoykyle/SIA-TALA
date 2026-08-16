<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicantIntake;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionApplicationDomainActionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
    }

    public function test_applicant_can_save_and_discard_only_an_open_unsubmitted_canonical_draft(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            $this->applicationData($program),
        );

        $this->assertSame(AdmissionApplication::StateDraft, $draft->application_state);
        $this->assertStringStartsWith('APP-', $draft->application_reference);
        $this->assertSame($cycle->term_id, $draft->term_id);
        $this->assertSame($applicant->email, $draft->email);

        $cycle->forceFill(['closes_at' => now()->subSecond()])->save();

        try {
            app(SaveAdmissionApplication::class)->execute(
                $applicant,
                $cycle->refresh(),
                ['current_province' => 'Cavite'],
                $draft,
            );
            $this->fail('Closed-cycle drafts must be read-only.');
        } catch (ValidationException) {
            $this->assertSame('Laguna', $draft->fresh()->current_province);
        }

        app(DiscardAdmissionApplication::class)->execute($draft, $applicant);

        $this->assertModelMissing($draft);
    }

    public function test_partial_draft_steps_allow_missing_program_path_and_minor_guardian_completion_until_submit(): void
    {
        [$cycle] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            ['first_name' => 'Partial'],
        );
        $minorDraft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            ['birth_date' => now()->subYears(16)->toDateString()],
            $draft,
        );

        $this->assertNull($minorDraft->program_id);
        $this->assertNull($minorDraft->application_path);
        $this->assertNull($minorDraft->guardian_full_name);

        $this->expectException(ValidationException::class);
        app(SubmitAdmissionApplication::class)->execute($minorDraft, $applicant);
    }

    public function test_first_submission_revalidates_under_lock_and_preserves_an_immutable_version(): void
    {
        [$cycle, $program, $requirementSet] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            $this->applicationData($program),
        );

        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);

        $this->assertSame(AdmissionApplication::StateSubmitted, $submitted->application_state);
        $this->assertSame(1, $submitted->submissionVersions()->count());
        $this->assertSame($requirementSet->id, $submitted->currentSubmissionVersion->admission_requirement_set_id);
        $this->assertSame($submitted->application_reference, $submitted->currentSubmissionVersion->snapshot['application_reference']);
        $this->assertSame(AdmissionApplicationEvent::TypeSubmitted, $submitted->events()->sole()->event_type);
        $this->assertSame(0, StudentProfile::query()->count());

        $this->expectException(ValidationException::class);
        app(SubmitAdmissionApplication::class)->execute($submitted, $applicant);
    }

    #[DataProvider('applicationPathAndCredentialProvider')]
    public function test_supported_paths_credentials_and_age_branches_submit_without_invented_profiles(
        string $path,
        string $credential,
        bool $minor,
    ): void {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $data = [
            ...$this->applicationData($program),
            'application_path' => $path,
            'credential_basis' => $credential,
            'birth_date' => now()->subYears($minor ? 16 : 20)->toDateString(),
        ];

        if ($minor) {
            $data = [
                ...$data,
                'guardian_full_name' => 'Synthetic Guardian',
                'guardian_relationship' => 'Parent',
                'guardian_mobile' => '09171234567',
            ];
        }

        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $data);
        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);

        $this->assertSame($path, $submitted->application_path);
        $this->assertSame($credential, $submitted->credential_basis);
        $this->assertSame($minor ? 'Synthetic Guardian' : null, $submitted->guardian_full_name);
        $this->assertSame(0, StudentProfile::query()->where('user_id', $applicant->id)->count());
    }

    /** @return array<string, array{string, string, bool}> */
    public static function applicationPathAndCredentialProvider(): array
    {
        return [
            'adult senior high school' => [AdmissionApplication::PathFirstYear, AdmissionApplication::CredentialSeniorHighSchool, false],
            'adult ALS A&E' => [AdmissionApplication::PathFirstYear, AdmissionApplication::CredentialAlsAe, false],
            'minor PEPT with guardian' => [AdmissionApplication::PathFirstYear, AdmissionApplication::CredentialPept, true],
            'adult transferee' => [AdmissionApplication::PathTransferee, AdmissionApplication::CredentialTransfer, false],
        ];
    }

    public function test_submission_idempotently_creates_private_exact_identity_and_verified_lrn_warnings(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $birthDate = now()->subYears(20)->toDateString();
        $exactCandidate = AdmissionApplication::factory()->submitted()->create([
            'first_name' => '  ALMA ',
            'last_name' => ' ADULT  ',
            'birth_date' => $birthDate,
            'lrn' => null,
        ]);
        $lrnCandidate = AdmissionApplication::factory()->submitted()->create([
            'first_name' => 'Different',
            'last_name' => 'Person',
            'birth_date' => now()->subYears(19)->toDateString(),
            'lrn' => '123456789012',
        ]);
        OfficialCredentialResult::factory()->for($lrnCandidate, 'application')->create([
            'result' => OfficialCredentialResult::ResultVerified,
        ]);
        $draft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            [...$this->applicationData($program), 'birth_date' => $birthDate, 'lrn' => '123456789012'],
        );

        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);

        $this->assertDatabaseHas('identity_match_reviews', [
            'admission_application_id' => $submitted->id,
            'match_type' => IdentityMatchReview::TypeExactNameBirthDate,
            'candidate_user_id' => $exactCandidate->user_id,
            'outcome' => IdentityMatchReview::OutcomePending,
        ]);
        $this->assertDatabaseHas('identity_match_reviews', [
            'admission_application_id' => $submitted->id,
            'match_type' => IdentityMatchReview::TypeVerifiedLrnCollision,
            'candidate_user_id' => $lrnCandidate->user_id,
            'outcome' => IdentityMatchReview::OutcomePending,
        ]);
        $this->assertSame(2, $submitted->identityMatchReviews()->count());
    }

    private function applicant(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('applicant');

        return $user;
    }

    /** @return array{AdmissionCycle, Program, AdmissionRequirementSet} */
    private function openCycle(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => $term->id,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
        $cycle->programs()->attach($program, [
            'accepts_first_year' => true,
            'accepts_transferee' => true,
        ]);
        $requirementSet = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathFirstYear,
        ]);
        AdmissionRequirement::factory()->for($requirementSet, 'requirementSet')->create([
            'requires_preliminary_evidence' => false,
        ]);
        $requirementSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $transfereeSet = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathTransferee,
        ]);
        AdmissionRequirement::factory()->for($transfereeSet, 'requirementSet')->create([
            'requires_preliminary_evidence' => false,
        ]);
        $transfereeSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);

        return [$cycle, $program, $requirementSet];
    }

    /** @return array<string, mixed> */
    private function applicationData(Program $program): array
    {
        return [
            'program_id' => $program->id,
            'application_path' => AdmissionApplication::PathFirstYear,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => 'Alma',
            'middle_name' => null,
            'last_name' => 'Adult',
            'extension_name' => null,
            'birth_date' => now()->subYears(20)->toDateString(),
            'citizenship_country_code' => 'PH',
            'phone' => '09477379208',
            'current_city_municipality' => 'Calamba',
            'current_province' => 'Laguna',
            'prior_school_name' => 'Synthetic Senior High School',
            'prior_school_country_code' => 'PH',
            'prior_school_completion_year' => now()->year - 1,
            'privacy_acknowledged' => true,
            'accuracy_declared' => true,
        ];
    }
}
