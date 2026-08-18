<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\RecordAdmissionDecision;
use App\Actions\Admissions\RecordOfficialCredentialResult;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\ResolveAdmissionIdentity;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use App\Models\StudentProfile;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionReviewReadinessActionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        $registrar = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar->givePermissionTo([
            Permission::findOrCreate('approve-documents', 'web'),
            Permission::findOrCreate('evaluate-transferees', 'web'),
        ]);
    }

    public function test_correction_reopens_only_named_scope_and_resubmission_closes_the_request(): void
    {
        [$application] = $this->submittedApplication();
        $registrar = $this->registrar();
        $request = app(RequestAdmissionCorrection::class)->execute(
            $application,
            $registrar,
            scopes: [[
                'type' => ApplicationCorrectionItem::ScopeField,
                'key' => 'current_province',
                'admission_requirement_id' => null,
            ]],
            applicantInstruction: 'Correct the province shown in your application.',
            responsibleParty: 'Applicant',
            dueAt: now()->addDay(),
        );

        $this->assertSame(ApplicationCorrectionRequest::StateActive, $request->state);
        $this->assertSame(AdmissionApplication::StateActionNeeded, $application->fresh()->application_state);

        try {
            app(SaveAdmissionApplication::class)->execute(
                $application->user,
                $application->admissionCycle,
                ['last_name' => 'Unauthorized change'],
                $application,
            );
            $this->fail('An unscoped submitted field must remain read-only.');
        } catch (ValidationException) {
            $this->assertNotSame('Unauthorized change', $application->fresh()->last_name);
        }

        $application->admissionCycle->forceFill(['closes_at' => now()->subSecond()])->save();

        app(SaveAdmissionApplication::class)->execute(
            $application->user,
            $application->admissionCycle,
            [
                'current_province' => 'Cavite',
                'privacy_acknowledged' => true,
                'accuracy_declared' => true,
            ],
            $application,
        );
        $resubmitted = app(SubmitAdmissionApplication::class)->execute(
            $application->fresh(),
            $application->user,
        );

        $this->assertSame(AdmissionApplication::StateSubmitted, $resubmitted->application_state);
        $this->assertSame(2, $resubmitted->submissionVersions()->count());
        $this->assertSame(ApplicationCorrectionRequest::StateCompleted, $request->fresh()->state);

        $resubmitted->admissionCycle->forceFill(['correction_closes_at' => now()->subSecond()])->save();

        try {
            app(RequestAdmissionCorrection::class)->execute(
                $resubmitted,
                $registrar,
                scopes: [[
                    'type' => ApplicationCorrectionItem::ScopeField,
                    'key' => 'current_city_municipality',
                    'admission_requirement_id' => null,
                ]],
                applicantInstruction: 'Correct the named city field.',
                responsibleParty: 'Applicant',
                dueAt: now()->addDay(),
            );
            $this->fail('An expired correction boundary must not create a new correction request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_at', $exception->errors());
        }
    }

    public function test_identity_decision_credential_reversal_and_ready_projection_remain_append_only(): void
    {
        [$application, $requirement, $nonCoreRequirement] = $this->submittedApplication();
        $application->admissionCycle->forceFill([
            'closes_at' => now()->subDays(2),
            'correction_closes_at' => now()->subDay(),
        ])->save();
        $registrar = $this->registrar();
        $identityReview = IdentityMatchReview::factory()->for($application, 'application')->create([
            'outcome' => IdentityMatchReview::OutcomePending,
        ]);

        try {
            app(RecordAdmissionDecision::class)->execute(
                $application,
                $registrar,
                AdmissionDecision::DecisionAdmitted,
                reason: 'All preliminary admission checks passed.',
                authorityReference: 'Synthetic admission authority',
                applicantExplanation: 'You are admitted, subject to official credentials.',
            );
            $this->fail('An unresolved private identity warning must block admission.');
        } catch (ValidationException) {
            $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        }

        app(ResolveAdmissionIdentity::class)->execute(
            $identityReview,
            $registrar,
            IdentityMatchReview::OutcomeDifferentPerson,
            evidenceReference: 'identity-review:SYN-001',
        );
        $admitted = app(RecordAdmissionDecision::class)->execute(
            $application,
            $registrar,
            AdmissionDecision::DecisionAdmitted,
            reason: 'All preliminary admission checks passed.',
            authorityReference: 'Synthetic admission authority',
            applicantExplanation: 'You are admitted, subject to official credentials.',
        );
        $verified = app(RecordOfficialCredentialResult::class)->execute(
            $application->fresh(),
            $requirement,
            $registrar,
            OfficialCredentialResult::ResultVerified,
            sourceReference: 'credential:SYN-001',
            safeExplanation: 'The official credential was verified.',
            authorityReference: 'Synthetic credential authority',
        );
        app(RecordOfficialCredentialResult::class)->execute(
            $application->fresh(),
            $nonCoreRequirement,
            $registrar,
            OfficialCredentialResult::ResultAuthorizedException,
            sourceReference: 'credential:SYN-OPTIONAL-001',
            safeExplanation: 'A bounded non-core exception was authorized.',
            authorityReference: 'Synthetic non-core exception authority',
            exceptionExpiresAt: now()->addDays(7),
        );

        $projection = app(ReadyApplicantProjectionQuery::class)->forApplication($application->fresh());
        $this->assertTrue($projection['ready']);
        $this->assertSame($application->application_reference, $projection['application_reference']);
        $this->assertSame($projection, app(ReadyApplicantProjectionQuery::class)->forApplication($application->fresh()));
        $this->assertContains($application->id, app(ReadyApplicantProjectionQuery::class)->readyApplicationIds());
        $this->assertSame(0, StudentProfile::query()->count());

        $reversed = app(RecordOfficialCredentialResult::class)->execute(
            $application->fresh(),
            $requirement,
            $registrar,
            OfficialCredentialResult::ResultActionNeeded,
            sourceReference: 'credential:SYN-001-reversal',
            safeExplanation: 'Registrar review found a credential issue.',
            authorityReference: 'Synthetic reversal authority',
            expectedCurrentResultId: $verified->id,
        );
        $this->assertFalse(
            app(ReadyApplicantProjectionQuery::class)->forApplication($application->fresh())['ready'],
        );
        $this->assertNotContains($application->id, app(ReadyApplicantProjectionQuery::class)->readyApplicationIds());

        try {
            app(RecordOfficialCredentialResult::class)->execute(
                $application->fresh(),
                $requirement,
                $registrar,
                OfficialCredentialResult::ResultAuthorizedException,
                sourceReference: 'credential:SYN-001-exception',
                safeExplanation: 'An invalid core-credential exception was attempted.',
                authorityReference: 'Synthetic invalid exception authority',
                exceptionExpiresAt: now()->addDays(7),
                expectedCurrentResultId: $reversed->id,
            );
            $this->fail('A core official credential must never accept an authorized exception.');
        } catch (ValidationException) {
            $this->assertFalse(
                app(ReadyApplicantProjectionQuery::class)->forApplication($application->fresh())['ready'],
            );
        }

        $supersedingDecision = app(RecordAdmissionDecision::class)->execute(
            $application->fresh(),
            $registrar,
            AdmissionDecision::DecisionNotAdmitted,
            reason: 'Authorized reconsideration after corrected source evidence.',
            authorityReference: 'Synthetic reconsideration authority',
            applicantExplanation: 'The admission decision was reconsidered. Contact Registrar support.',
            expectedCurrentDecisionId: $admitted->id,
        );
        $this->assertTrue($supersedingDecision->supersedes($admitted));
        $this->assertSame(2, $application->decisions()->count());
    }

    public function test_withdrawal_and_reopening_stop_after_clinic_four_registration_exists(): void
    {
        [$application] = $this->submittedApplication();
        $application->admissionCycle->forceFill([
            'closes_at' => now()->subDays(2),
            'correction_closes_at' => now()->subDay(),
        ])->save();
        $applicant = $application->user;
        $registrar = $this->registrar();
        $lifecycle = app(ChangeAdmissionApplicationLifecycle::class);

        $withdrawn = $lifecycle->withdrawByApplicant($application, $applicant, 'Plans changed.');
        $reopened = $lifecycle->reopen(
            $withdrawn,
            $registrar,
            reason: 'Applicant requested authorized reconsideration.',
            authorityReference: 'Synthetic reopen authority',
        );
        $this->assertSame(AdmissionApplication::StateSubmitted, $reopened->application_state);

        $student = StudentProfile::factory()->create([
            'applicant_intake_id' => $application->id,
            'program_id' => $application->program_id,
        ]);
        Enrollment::factory()->for($student)->create(['term_id' => $application->term_id]);

        $this->expectException(ValidationException::class);
        $lifecycle->withdrawByApplicant($reopened, $applicant, null);
    }

    /** @return array{AdmissionApplication, AdmissionRequirement, AdmissionRequirement} */
    private function submittedApplication(): array
    {
        $application = AdmissionApplication::factory()->submitted()->create();
        $application->admissionCycle->forceFill([
            'state' => AdmissionCycle::StatePublished,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addMonth(),
        ])->save();
        $application->admissionCycle->programs()->attach($application->program_id, [
            'accepts_first_year' => $application->application_path === AdmissionApplication::PathFirstYear,
            'accepts_transferee' => $application->application_path === AdmissionApplication::PathTransferee,
        ]);
        $requirementSet = AdmissionRequirementSet::factory()
            ->for($application->admissionCycle)
            ->create([
                'application_path' => $application->application_path,
            ]);
        $requirement = AdmissionRequirement::factory()->for($requirementSet, 'requirementSet')->create([
            'requires_preliminary_evidence' => false,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'credential_classification' => 'CoreFirstYearCompletionCredential',
            'exception_permitted' => true,
            'required_approving_authority' => 'Registrar',
        ]);
        $nonCoreRequirement = AdmissionRequirement::factory()->for($requirementSet, 'requirementSet')->create([
            'requires_preliminary_evidence' => false,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'credential_classification' => 'NonCore',
            'exception_permitted' => true,
            'required_approving_authority' => 'Registrar',
            'display_order' => 20,
        ]);
        $requirementSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create(['version' => 1]);
        $application->forceFill(['current_submission_version_id' => $submission->id])->save();
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');

        return [$application->refresh(), $requirement, $nonCoreRequirement];
    }

    private function registrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }
}
