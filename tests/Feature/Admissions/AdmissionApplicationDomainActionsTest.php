<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\RecordAdmissionDecision;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicantIntake;
use App\Models\ApplicationCorrectionItem;
use App\Models\DocumentEvidence;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
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
        $this->assertNull($draft->application_reference);
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
        $this->assertStringStartsWith('APP-', $submitted->application_reference);
        $this->assertSame(1, $submitted->submissionVersions()->count());
        $this->assertSame($requirementSet->id, $submitted->currentSubmissionVersion->admission_requirement_set_id);
        $this->assertSame($submitted->application_reference, $submitted->currentSubmissionVersion->snapshot['application_reference']);
        $this->assertSame(AdmissionApplicationEvent::TypeSubmitted, $submitted->events()->sole()->event_type);
        $this->assertSame(0, StudentProfile::query()->count());

        $this->expectException(ValidationException::class);
        app(SubmitAdmissionApplication::class)->execute($submitted, $applicant);
    }

    public function test_switching_a_transferee_draft_to_first_year_clears_its_hidden_prior_college_identifier(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$this->applicationData($program),
            'application_path' => AdmissionApplication::PathTransferee,
            'credential_basis' => AdmissionApplication::CredentialTransfer,
            'prior_college_identifier' => 'TRANSFER-001',
        ]);
        $this->assertSame('TRANSFER-001', $draft->prior_college_identifier);

        $firstYear = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            'application_path' => AdmissionApplication::PathFirstYear,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
        ], $draft);
        $this->assertNull($firstYear->prior_college_identifier);
        $submitted = app(SubmitAdmissionApplication::class)->execute($firstYear, $applicant);
        $this->assertNull($submitted->prior_college_identifier);
        $this->assertNull($submitted->currentSubmissionVersion->snapshot['prior_college_identifier']);
    }

    public function test_acknowledgment_preserves_evidence_and_review_facts_at_submission(): void
    {
        [$cycle, $program, $set] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $this->applicationData($program));
        $requirement = $set->requirements()->firstOrFail();
        $evidence = DocumentEvidence::factory()->canonical($draft, $requirement)->create();
        $review = PreliminaryEvidenceReview::factory()->for($evidence, 'documentEvidence')->create([
            'result' => PreliminaryEvidenceReview::ResultAccepted,
        ]);

        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
        $version = $submitted->currentSubmissionVersion;
        $facts = $version->snapshot['requirements'][0];
        $this->assertSame($requirement->label, $facts['label']);
        $this->assertSame($requirement->applicant_instructions, $facts['applicant_instructions']);
        $this->assertSame($evidence->id, $facts['evidence']['id']);
        $this->assertSame($evidence->checksum, $facts['evidence']['checksum']);
        $this->assertSame($review->id, $facts['preliminary_review']['id']);
        $this->assertSame(PreliminaryEvidenceReview::ResultAccepted, $facts['preliminary_result']);
        $this->assertNull($facts['official_credential_result']);
        $this->assertSame('Submitted', $version->snapshot['application_state_at_submission']);

        PreliminaryEvidenceReview::factory()->for($evidence, 'documentEvidence')->create([
            'result' => PreliminaryEvidenceReview::ResultActionNeeded,
            'supersedes_preliminary_evidence_review_id' => $review->id,
        ]);
        DocumentEvidence::factory()->canonical($submitted, $requirement, $version)->create([
            'replaces_document_evidence_id' => $evidence->id,
        ]);

        $this->assertSame($facts, $version->fresh()->snapshot['requirements'][0]);
        $this->actingAs($applicant)->get(route('admissions.application.acknowledgment', [
            'application' => $submitted, 'version' => $version,
        ]))->assertOk()
            ->assertSee('Accepted As Preliminary Evidence')
            ->assertSee('Evidence '.$evidence->id)
            ->assertDontSee('Action Needed');
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
        $exactCandidate = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->submitted()->create([
            'first_name' => '  ALMA ',
            'last_name' => ' ADULT  ',
            'birth_date' => $birthDate,
            'lrn' => null,
        ]);
        $lrnCandidate = AdmissionApplication::factory()->state([
            'admission_cycle_id' => AdmissionCycle::factory()->state([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ]),
        ])->submitted()->create([
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
            [...$this->applicationData($program), 'birth_date' => $birthDate, 'lrn_availability' => 'Provided', 'lrn' => '123456789012'],
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

    public function test_first_decision_opened_before_field_correction_cannot_decide_the_resubmitted_version(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, $this->applicationData($program));
        $opened = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        app(RequestAdmissionCorrection::class)->execute($opened, $registrar, [
            ['type' => ApplicationCorrectionItem::ScopeField, 'key' => 'phone'],
        ], 'Correct the phone number.', 'Applicant', now()->addHour());
        $corrected = app(SaveAdmissionApplication::class)->execute($applicant, $cycle,
            ['phone' => '09123456789', 'privacy_acknowledged' => true, 'accuracy_declared' => true], $opened->fresh());
        $resubmitted = app(SubmitAdmissionApplication::class)->execute($corrected, $applicant);
        $this->assertNotSame($opened->current_submission_version_id, $resubmitted->current_submission_version_id);
        $eventsBefore = $resubmitted->events()->count();
        $notificationsBefore = OperationalEvent::query()->count();

        try {
            app(RecordAdmissionDecision::class)->execute($opened, $registrar,
                AdmissionDecision::DecisionAdmitted, 'Reviewed', 'Registrar authority', 'Admission complete');
            $this->fail('A first-decision form opened on the previous submitted version must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }
        $this->assertSame(0, $resubmitted->decisions()->count());
        $this->assertSame(AdmissionApplication::StateSubmitted, $resubmitted->fresh()->application_state);
        $this->assertSame($eventsBefore, $resubmitted->events()->count());
        $this->assertSame($notificationsBefore, OperationalEvent::query()->count());
    }

    public function test_optional_identity_requires_specific_consent_and_clears_on_withdrawal(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $data = $this->applicationData($program);
        try {
            app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [...$data, 'gender' => 'Female']);
            $this->fail('Optional identity must require consent.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gender', $exception->errors());
        }

        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$data, 'optional_identity_consent' => true, 'gender' => 'Female', 'civil_status' => 'Single',
        ]);
        $this->assertSame($cycle->privacy_notice_reference, $draft->optional_identity_notice_reference);
        $this->assertSame('Identity-record comparison', $draft->optional_identity_consent_purpose);
        $this->assertNotNull($draft->optional_identity_consented_at);

        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['optional_identity_consent' => false], $draft);
        $this->assertNull($draft->gender);
        $this->assertNull($draft->civil_status);
        $this->assertNull($draft->optional_identity_consented_at);
    }

    public function test_leading_zero_lrn_and_consent_are_retained_in_submission_snapshot(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$this->applicationData($program), 'lrn_availability' => 'Provided', 'lrn' => '001234567890',
            'optional_identity_consent' => true, 'gender' => 'Female',
        ]);
        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
        $snapshot = $submitted->currentSubmissionVersion->snapshot;
        $this->assertSame('001234567890', $snapshot['lrn']);
        $this->assertSame('Provided', $snapshot['lrn_availability']);
        $this->assertSame('Female', $snapshot['gender']);
        $this->assertNotEmpty($snapshot['optional_identity_consented_at']);
    }

    public function test_minor_requires_complete_guardian_but_has_no_minimum_age_cutoff(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$this->applicationData($program), 'birth_date' => now()->subYears(12)->toDateString(),
        ]);
        try {
            app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
            $this->fail('Minor must have a complete guardian contact.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('guardian_full_name', $exception->errors());
        }
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            'guardian_full_name' => 'Synthetic Guardian', 'guardian_relationship' => 'Parent', 'guardian_mobile' => '09123456789',
        ], $draft);
        $this->assertSame(AdmissionApplication::StateSubmitted, app(SubmitAdmissionApplication::class)->execute($draft, $applicant)->application_state);
    }

    public function test_manila_eighteenth_birthday_allows_no_contact_but_requires_a_complete_optional_contact(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 00:30:00', 'Asia/Manila'));
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$this->applicationData($program), 'birth_date' => '2008-10-04',
            'guardian_full_name' => 'Optional Contact',
        ]);
        try {
            app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
            $this->fail('An adult optional contact must be complete when supplied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('guardian_relationship', $exception->errors());
            $this->assertArrayHasKey('guardian_mobile', $exception->errors());
        }
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, ['guardian_full_name' => null], $draft);
        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
        $this->assertSame(AdmissionApplication::StateSubmitted, $submitted->application_state);
        $this->assertNull($submitted->guardian_full_name);
    }

    public function test_before_manila_eighteenth_birthday_a_complete_guardian_is_required(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 23:59:00', 'Asia/Manila'));
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$this->applicationData($program), 'birth_date' => '2008-10-04',
        ]);
        try {
            app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
            $this->fail('A contact is required before the eighteenth birthday.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('guardian_full_name', $exception->errors());
        }
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            'guardian_full_name' => 'Synthetic Guardian', 'guardian_relationship' => 'Parent', 'guardian_mobile' => '09123456789',
        ], $draft);
        $this->assertSame(AdmissionApplication::StateSubmitted, app(SubmitAdmissionApplication::class)->execute($draft, $applicant)->application_state);
    }

    public function test_partial_draft_can_record_lrn_availability_before_the_identifier_is_entered(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$this->applicationData($program), 'lrn_availability' => 'Provided',
        ]);
        $this->assertSame('Provided', $draft->lrn_availability);
        $this->assertNull($draft->lrn);
        $this->expectException(ValidationException::class);
        app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
    }

    public function test_graduation_year_uses_the_philippine_date_at_the_new_year_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            ...$this->applicationData($program), 'prior_school_completion_year' => 2027,
        ]);
        $this->assertSame(2027, $draft->prior_school_completion_year);
        $this->assertSame(AdmissionApplication::StateSubmitted, app(SubmitAdmissionApplication::class)->execute($draft, $applicant)->application_state);
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
        $term = Term::query()->where('state', Term::StateActive)->first()
            ?? Term::factory()->create(['state' => Term::StateActive]);
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
            'lrn_availability' => 'NotIssued',
            'prior_school_completion_year' => now()->year - 1,
            'privacy_acknowledged' => true,
            'accuracy_declared' => true,
        ];
    }
}
