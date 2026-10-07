<?php

namespace Tests\Feature;

use App\Actions\Admissions\RecordAdmissionDecision;
use App\Actions\Admissions\RecordRegistrarEnrollmentClearance;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\OfficialCredentialResult;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrarEnrollmentClearanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')
            ->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
    }

    public function test_only_matching_clearance_grants_readiness_without_creating_student_identity(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $profiles = StudentProfile::count();
        $this->assertFalse($this->projection($application)['ready']);

        $clearance = $this->record($application, $actor);

        $this->assertTrue($this->projection($application)['ready']);
        $this->assertSame($clearance->id, $this->projection($application)['clearance_id']);
        $this->assertSame($actor->id, $clearance->recorded_by);
        $this->assertSame($profiles, StudentProfile::count());
    }

    public function test_successor_preserves_official_enrollment_and_routes_a_registrar_discrepancy(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $clearance = $this->record($application, $actor);
        $case = Enrollment::factory()->create([
            'admission_application_id' => $application->id,
            'credential_user_id' => $application->user_id, 'term_id' => $application->term_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled, 'officially_enrolled_at' => now(),
        ]);
        $facts = $case->fresh()->getAttributes();

        $successor = $this->record($application, $actor, 'ActionNeeded', $clearance->id);

        $this->assertFalse($this->projection($application)['ready']);
        $this->assertSame($clearance->id, $successor->supersedes_clearance_id);
        $this->assertSame($facts, $case->fresh()->getAttributes());
        $this->assertSame('AdmissionsDiscrepancyRequiresRegistrar', $case->registrationEvents()->sole()->event_type);
        $this->assertSame(2, $application->enrollmentClearances()->count());
    }

    public function test_loss_of_clearance_flags_an_active_case_without_changing_its_outcome(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $clearance = $this->record($application, $actor);
        $case = Enrollment::factory()->create([
            'admission_application_id' => $application->id,
            'credential_user_id' => $application->user_id, 'term_id' => $application->term_id,
        ]);

        $this->record($application, $actor, 'ActionNeeded', $clearance->id);

        $this->assertSame('AdmissionClearanceActionNeeded', $case->registrationEvents()->sole()->event_type);
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertFalse($this->projection($application)['ready']);
    }

    public function test_changed_identity_invalidates_old_clearance(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $this->record($application, $actor);

        $application->update(['first_name' => 'Corrected identity']);

        $this->assertFalse($this->projection($application)['ready']);
        $this->assertNull($this->projection($application)['clearance_id']);
    }

    public function test_stale_first_result_form_cannot_create_two_current_clearances(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $this->record($application, $actor);

        try {
            $this->record($application, $actor);
            $this->fail('A stale first-result form must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('result', $exception->errors());
        }
        $this->assertSame(1, $application->enrollmentClearances()->count());
    }

    public function test_changed_decision_cannot_reuse_clearance(): void
    {
        [$application, $actor, $decision] = $this->admittedApplication();
        $this->record($application, $actor);

        AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $application->current_submission_version_id,
            'supersedes_admission_decision_id' => $decision->id,
        ]);

        $this->assertFalse($this->projection($application)['ready']);
    }

    public function test_cleared_requires_confirmed_external_checks(): void
    {
        [$application, $actor, $decision] = $this->admittedApplication();

        $this->expectException(ValidationException::class);
        app(RecordRegistrarEnrollmentClearance::class)->execute(
            $application, $actor, 'Cleared', false, expectedDecisionId: $decision->id,
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
    }

    public function test_unauthorized_actor_cannot_record_clearance(): void
    {
        [$application] = $this->admittedApplication();

        $this->expectException(AuthorizationException::class);
        $this->record($application, User::factory()->create());
    }

    public function test_clearance_history_is_immutable(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $clearance = $this->record($application, $actor);

        $this->expectException(\LogicException::class);
        $clearance->update(['result' => 'ActionNeeded']);
    }

    public function test_action_needed_requires_an_applicant_safe_instruction(): void
    {
        [$application, $actor, $decision] = $this->admittedApplication();
        $this->expectException(ValidationException::class);
        app(RecordRegistrarEnrollmentClearance::class)->execute(
            $application, $actor, 'ActionNeeded', false,
            expectedDecisionId: $decision->id,
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
    }

    public function test_replacement_requires_an_attributable_reason(): void
    {
        [$application, $actor, $decision] = $this->admittedApplication();
        $clearance = $this->record($application, $actor);
        $this->expectException(ValidationException::class);
        app(RecordRegistrarEnrollmentClearance::class)->execute(
            $application, $actor, 'Cleared', true,
            expectedCurrentClearanceId: $clearance->id, expectedDecisionId: $decision->id,
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
    }

    public function test_a_legacy_verified_document_cannot_fabricate_new_clearance(): void
    {
        [$application, $actor] = $this->admittedApplication();
        OfficialCredentialResult::factory()->verified()->create([
            'admission_application_id' => $application->id, 'recorded_by' => $actor->id,
        ]);
        $projection = $this->projection($application);
        $this->assertFalse($projection['ready']);
        $this->assertNull($projection['clearance_id']);
        $this->assertCount(1, $projection['credential_result_ids']);
    }

    public function test_changed_submission_invalidates_clearance_and_rejects_a_stale_write(): void
    {
        [$application, $actor, $decision] = $this->admittedApplication();
        $clearance = $this->record($application, $actor);
        $oldVersion = $application->current_submission_version_id;
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $application->currentSubmissionVersion->admission_requirement_set_id,
            'submitted_by' => $application->user_id,
            'version' => 2,
        ]);
        $application->update(['current_submission_version_id' => $version->id]);
        $this->assertFalse($this->projection($application)['ready']);
        try {
            app(RecordRegistrarEnrollmentClearance::class)->execute(
                $application, $actor, 'Cleared', true, reason: 'Review corrected submission.',
                expectedCurrentClearanceId: $clearance->id, expectedDecisionId: $decision->id,
                expectedSubmissionVersionId: $oldVersion,
            );
            $this->fail('A stale submission must not receive clearance.');
        } catch (ValidationException) {
            $this->assertSame(1, $application->enrollmentClearances()->count());
        }
    }

    public function test_registration_consumer_revalidates_the_same_clearance_source(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $case = Enrollment::factory()->create([
            'student_profile_id' => null, 'admission_application_id' => $application->id,
            'credential_user_id' => $application->user_id, 'term_id' => $application->term_id,
        ]);
        $curriculum = CurriculumVersion::factory()->create(['program_id' => $application->program_id]);
        $timetable = PublishedTimetableVersion::factory()->create(['term_id' => $application->term_id]);
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $case->id, 'curriculum_version_id' => $curriculum->id,
            'published_timetable_version_id' => $timetable->id,
        ]);
        $case->forceFill(['current_proposal_version_id' => $proposal->id])->save();
        $query = app(RegistrationReadinessQuery::class);
        $this->assertFalse($query->for($case->fresh())['eligibility']);
        $clearance = $this->record($application, $actor);
        $this->assertTrue($query->for($case->fresh())['eligibility']);
        $this->record($application, $actor, 'ActionNeeded', $clearance->id);
        $this->assertFalse($query->for($case->fresh())['eligibility']);
    }

    public function test_cancelled_cycle_allows_recorded_existing_admission_to_receive_clearance(): void
    {
        [$application, $actor] = $this->admittedApplication();
        $application->admissionCycle->update(['state' => AdmissionCycle::StateCancelled]);
        $clearance = $this->record($application, $actor);
        $this->assertSame(RegistrarEnrollmentClearance::ResultCleared, $clearance->result);
        $this->assertTrue($this->projection($application)['ready']);
        $this->assertSame(0, StudentProfile::query()->where('user_id', $application->user_id)->count());
    }

    public function test_real_decision_supersession_flags_active_and_official_cases_without_reversing_them(): void
    {
        foreach ([false, true] as $official) {
            [$application, $actor, $decision] = $this->admittedApplication();
            $this->record($application, $actor);
            $case = Enrollment::factory()->create([
                'admission_application_id' => $application->id,
                'credential_user_id' => $application->user_id, 'term_id' => $application->term_id,
                'canonical_outcome' => $official ? Enrollment::OutcomeOfficiallyEnrolled : Enrollment::OutcomeInProgress,
                'officially_enrolled_at' => $official ? now() : null,
            ]);
            $facts = $case->fresh()->getAttributes();

            $successor = app(RecordAdmissionDecision::class)->execute(
                $application, $actor, AdmissionDecision::DecisionNotAdmitted,
                'Corrected admissions source.', 'Synthetic decision correction authority',
                'Contact the Registrar to resolve the corrected admissions source.',
                expectedCurrentDecisionId: $decision->id,
                expectedSubmissionVersionId: $application->current_submission_version_id,
            );

            $this->assertFalse($this->projection($application)['ready']);
            $this->assertSame($facts, $case->fresh()->getAttributes());
            $event = $case->registrationEvents()->sole();
            $this->assertSame($official ? 'AdmissionsDiscrepancyRequiresRegistrar' : 'AdmissionDecisionActionNeeded', $event->event_type);
            $this->assertSame($actor->id, $event->actor_id);
            $this->assertSame('Synthetic decision correction authority', $event->authority_reference);
            $this->assertStringContainsString('decision #'.$successor->id.' supersedes #'.$decision->id, $event->reason);
            $this->assertStringContainsString('submission version #'.$application->current_submission_version_id, $event->reason);
            $this->assertStringContainsString('Registrar must resolve', $event->reason);
            $this->assertSame($facts['canonical_outcome'], $event->to_outcome);
            $this->assertSame(1, $application->enrollmentClearances()->count());
        }
    }

    private function admittedApplication(): array
    {
        $application = AdmissionApplication::factory()->submitted()->create([
            'admission_cycle_id' => AdmissionCycle::factory()->create([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ])->id,
        ]);
        $set = AdmissionRequirementSet::factory()->for($application->admissionCycle)->create([
            'application_path' => $application->application_path,
        ]);
        $version = ApplicationSubmissionVersion::factory()->for($set, 'requirementSet')->create(['admission_application_id' => $application->id]);
        $application->update(['application_state' => AdmissionApplication::StateAdmitted, 'current_submission_version_id' => $version->id]);
        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole(User::StaffRoleRegistrar);
        $decision = AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $version->id, 'decided_by' => $actor->id,
        ]);

        return [$application->fresh(), $actor, $decision];
    }

    private function record(AdmissionApplication $application, User $actor, string $result = 'Cleared', ?int $currentId = null): RegistrarEnrollmentClearance
    {
        return app(RecordRegistrarEnrollmentClearance::class)->execute(
            $application, $actor, $result, $result === 'Cleared',
            safeInstruction: $result === 'ActionNeeded' ? 'Contact the Registrar about the external check.' : null,
            reason: $currentId !== null ? 'External school source was corrected.' : null,
            authorityReference: 'Synthetic school clearance authority',
            expectedCurrentClearanceId: $currentId,
            expectedDecisionId: $application->decisions()->whereDoesntHave('successor')->firstOrFail()->id,
            expectedSubmissionVersionId: $application->current_submission_version_id,
        );
    }

    private function projection(AdmissionApplication $application): array
    {
        return app(ReadyApplicantProjectionQuery::class)->forApplication($application->fresh());
    }
}
