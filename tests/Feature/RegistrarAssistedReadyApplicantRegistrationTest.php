<?php

namespace Tests\Feature;

use App\Actions\Enrollment\StartRegistrationCase;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Assessment;
use App\Models\CorVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\OfficialCredentialResult;
use App\Models\Program;
use App\Models\RegistrationCaseEvent;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrarAssistedReadyApplicantRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleSystemSuperAdmin, 'student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_registrar_can_start_registration_case_for_ready_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $test = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->assertActionExists('startReadyApplicantRegistration')
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'In-person intake desk, Slip #R-2026-001',
            ])
            ->assertHasNoActionErrors();

        $enrollment = Enrollment::query()
            ->where('admission_application_id', $application->id)
            ->where('term_id', $term->id)
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertSame($application->user_id, $enrollment->credential_user_id);
        $this->assertSame('RegistrarAssisted', $enrollment->start_method);
        $this->assertSame($registrar->id, $enrollment->started_by);
        $this->assertNull($enrollment->student_profile_id);
        $this->assertSame(Enrollment::OutcomeInProgress, $enrollment->canonical_outcome);

        $test->assertRedirect(EnrollmentResource::getUrl('view', ['record' => $enrollment]));

        $event = RegistrationCaseEvent::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('event_type', 'Started')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('RegistrarAssisted', $event->reason);
        $this->assertSame('In-person intake desk, Slip #R-2026-001', $event->authority_reference);
        $this->assertSame($registrar->id, $event->actor_id);
    }

    public function test_starting_case_for_applicant_with_existing_case_opens_existing_case_without_duplicate(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        // Pre-create the enrollment case
        $existingEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Initial intake voucher #101',
        );

        $initialCount = Enrollment::query()->where('admission_application_id', $application->id)->count();
        $this->assertSame(1, $initialCount);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. In startReadyApplicantRegistration, the applicant with an existing case is excluded from options
        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('startReadyApplicantRegistration');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $schema = $component->instance()->getSchema($schemaName);
        $options = $schema->getComponent('application_id')->getOptions();
        $this->assertArrayNotHasKey($application->id, $options);

        // 2. In readyApplicants modal, the card presents the Open case route
        $action = $component->instance()->getAction('readyApplicants');
        $modalView = $action->getModalContent();
        $casesMap = $modalView->getData()['casesMap'];
        $this->assertNotNull($casesMap[$application->id]);
        $this->assertSame($existingEnrollment->id, $casesMap[$application->id]->id);

        $rendered = $modalView->render();
        $this->assertStringContainsString('Open case &rarr;', $rendered);
        $this->assertStringContainsString($existingEnrollment->case_reference, $rendered);

        // 3. Directly accessing the case on ViewEnrollment succeeds
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $existingEnrollment->getRouteKey()])
            ->assertSuccessful();

        $afterCount = Enrollment::query()->where('admission_application_id', $application->id)->count();
        $this->assertSame(1, $afterCount);
    }

    public function test_starting_case_rejects_wrong_term_selection(): void
    {
        $registrar = $this->createRegistrar();
        $termA = $this->createTermWithOpenEnrollmentWindow('Term A');
        $termB = $this->createTermWithOpenEnrollmentWindow('Term B');
        $application = $this->createReadyApplicant($termA);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $termB->id,
                'authority_reference' => 'Wrong term intake voucher #999',
            ])
            ->assertNotified('Registration not started');

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
            'term_id' => $termB->id,
        ]);
    }

    public function test_domain_service_rejects_wrong_term_for_ready_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $termA = $this->createTermWithOpenEnrollmentWindow('Term A');
        $termB = $this->createTermWithOpenEnrollmentWindow('Term B');
        $application = $this->createReadyApplicant($termA);

        $this->expectException(ValidationException::class);

        app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $termB,
            $registrar,
            'RegistrarAssisted',
            'Intake desk',
        );
    }

    public function test_starting_case_rejects_ineligible_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        // Make applicant ineligible by dropping the admitted state
        $application->update(['application_state' => AdmissionApplication::StateSubmitted]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Ineligible test voucher',
            ])
            ->assertHasActionErrors(['application_id']);

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
        ]);
    }

    public function test_domain_service_rejects_ineligible_applicant(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $application->update(['application_state' => AdmissionApplication::StateSubmitted]);

        $this->expectException(ValidationException::class);

        app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Ineligible domain test voucher',
        );
    }

    public function test_stale_request_rejected_when_applicant_readiness_is_revoked_after_modal_mount(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $testable = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
            ])
            ->setActionData([
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Stale verification intake voucher #STALE-1',
            ]);

        // Stale transition: Credential is invalidated after staff mounted the action
        $application->credentialResults()->update([
            'result' => OfficialCredentialResult::ResultActionNeeded,
        ]);

        $testable->callMountedAction()
            ->assertHasActionErrors(['application_id']);

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
            'term_id' => $term->id,
        ]);
    }

    public function test_starting_case_rejects_closed_enrollment_window(): void
    {
        $registrar = $this->createRegistrar();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        // Calendar window closed
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDays(10)->toDateString(),
            'closes_on' => now()->subDays(2)->toDateString(),
        ]);

        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Closed window voucher',
            ])
            ->assertNotified('Registration not started');

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $application->id,
        ]);
    }

    public function test_starting_case_requires_authority_reference(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => '',
            ])
            ->assertHasActionErrors(['authority_reference' => 'required']);
    }

    public function test_unauthorized_user_cannot_start_assisted_registration(): void
    {
        $accountingUser = User::factory()->create(['status' => User::StatusActive]);
        $accountingUser->assignRole(User::StaffRoleAccounting);

        $term = $this->createTermWithOpenEnrollmentWindow();
        $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accountingUser)
            ->test(ListEnrollments::class)
            ->assertActionDoesNotExist('startReadyApplicantRegistration');
    }

    public function test_domain_service_rejects_non_registrar_assisted_start(): void
    {
        $accountingUser = User::factory()->create(['status' => User::StatusActive]);
        $accountingUser->assignRole(User::StaffRoleAccounting);

        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $this->expectException(AuthorizationException::class);

        app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $accountingUser,
            'RegistrarAssisted',
            'Unauthorized staff attempt',
        );
    }

    public function test_starting_case_does_not_create_student_profile_seat_assessment_or_cor(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $initialProfiles = StudentProfile::count();
        $initialSeats = EnrollmentSeatReservation::count();
        $initialAssessments = Assessment::count();
        $initialCors = CorVersion::count();

        $enrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Strict identity boundary test',
        );

        $this->assertNull($enrollment->student_profile_id);
        $this->assertSame($initialProfiles, StudentProfile::count());
        $this->assertSame($initialSeats, EnrollmentSeatReservation::count());
        $this->assertSame($initialAssessments, Assessment::count());
        $this->assertSame($initialCors, CorVersion::count());
    }

    public function test_applicant_self_service_and_continuing_student_starts_remain_intact(): void
    {
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);
        $applicantUser = $application->user;

        // 1. Applicant self-service start
        $applicantEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $applicantUser,
            'SelfService',
        );

        $this->assertNotNull($applicantEnrollment);
        $this->assertSame('SelfService', $applicantEnrollment->start_method);
        $this->assertSame($applicantUser->id, $applicantEnrollment->started_by);

        // 2. Continuing student start by registrar
        $registrar = $this->createRegistrar();
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'academic_standing' => StudentProfile::StandingRegular,
        ]);

        $continuingEnrollment = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Continuing student in-person intake',
        );

        $this->assertNotNull($continuingEnrollment);
        $this->assertSame('RegistrarAssisted', $continuingEnrollment->start_method);
        $this->assertSame($profile->id, $continuingEnrollment->student_profile_id);
    }

    public function test_start_registration_for_applicant_helper_replaces_mounted_action_with_prefilled_arguments(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('readyApplicants')
            ->assertActionMounted('readyApplicants')
            ->call('startRegistrationForApplicant', $application->id, $term->id)
            ->assertActionNotMounted('readyApplicants')
            ->assertActionMounted('startReadyApplicantRegistration')
            ->assertActionDataSet([
                'application_id' => $application->id,
                'term_id' => $term->id,
            ]);
    }

    public function test_ready_applicants_modal_displays_truthful_canonical_states_and_next_steps_with_preloaded_cases(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();

        // Applicant 1: Ready, no case yet
        $appWithoutCase = $this->createReadyApplicant($term);

        // Applicant 2: InProgress case
        $appWithActiveCase = $this->createReadyApplicant($term);
        $activeCase = app(StartRegistrationCase::class)->forReadyApplicant(
            $appWithActiveCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake-001',
        );

        // Applicant 3: Officially enrolled case
        $appWithEnrolledCase = $this->createReadyApplicant($term);
        $enrolledCase = app(StartRegistrationCase::class)->forReadyApplicant(
            $appWithEnrolledCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake-002',
        );
        $enrolledCase->update([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);

        // Applicant 4: Cancelled case
        $appWithCancelledCase = $this->createReadyApplicant($term);
        $cancelledCase = app(StartRegistrationCase::class)->forReadyApplicant(
            $appWithCancelledCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake-003',
        );
        $cancelledCase->update([
            'canonical_outcome' => Enrollment::OutcomeCancelledByLearner,
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $view = $this->view('filament.admin.enrollments.ready-applicants', [
            'applications' => collect([$appWithoutCase, $appWithActiveCase, $appWithEnrolledCase, $appWithCancelledCase]),
            'casesMap' => collect([
                $appWithoutCase->id => null,
                $appWithActiveCase->id => $activeCase,
                $appWithEnrolledCase->id => $enrolledCase,
                $appWithCancelledCase->id => $cancelledCase,
            ]),
        ]);

        $view->assertSee('Ready for Case Creation')
            ->assertSee('Start registration')
            ->assertSee("Case Active: {$activeCase->case_reference}")
            ->assertSee('Next step: Proceed with proposal course placement')
            ->assertSee("Officially Enrolled: {$enrolledCase->case_reference}")
            ->assertSee('Next step: Official enrollment finalized')
            ->assertDontSee('COR v1 issued')
            ->assertSee("Cancelled By Learner: {$cancelledCase->case_reference}")
            ->assertSee('Next step: Case closed/cancelled');

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('readyApplicants')
            ->assertActionMounted('readyApplicants');
    }

    public function test_existing_case_is_accessible_when_window_closed_while_new_intake_fails_closed(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        // Start existing case while window is open
        $existingEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake desk',
        );

        // Close the enrollment window
        TermCalendarWindow::query()
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update([
                'opens_on' => now()->addDays(10)->toDateString(),
                'closes_on' => now()->addDays(20)->toDateString(),
            ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. Attempting to start a NEW registration case for another applicant fails closed due to calendar gate
        $anotherApp = $this->createReadyApplicant($term);
        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $anotherApp->id,
                'term_id' => $term->id,
                'authority_reference' => 'Attempt while window closed',
            ])
            ->assertNotified('Registration not started');

        $this->assertDatabaseMissing('enrollments', [
            'admission_application_id' => $anotherApp->id,
        ]);

        // 2. Existing case view access on REG-E02 remains fully accessible and authorized without window check
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $existingEnrollment->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_unexpected_exception_is_reported_safely_without_leaking_internal_details(): void
    {
        Exceptions::fake();

        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        $injectedSecret = 'Sensitive DB connection string: mysql://secret@internal-cluster:3306';

        $mockService = \Mockery::mock(StartRegistrationCase::class);
        $mockService->shouldReceive('forReadyApplicant')
            ->once()
            ->andThrow(new \RuntimeException($injectedSecret));
        $this->app->instance(StartRegistrationCase::class, $mockService);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $test = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startReadyApplicantRegistration', [
                'application_id' => $application->id,
                'term_id' => $term->id,
                'authority_reference' => 'Valid authority reference',
            ]);

        // 1. Verify user-facing notification in session before assertNotified pulls it
        $notifications = session('filament.claimed_notifications') ?? session('filament.notifications') ?? [];
        $this->assertNotEmpty($notifications);
        $notification = collect($notifications)->firstWhere('title', 'Registration not started');
        $this->assertNotNull($notification);
        $this->assertSame(
            'An unexpected error occurred while starting registration. Please try again or contact system support.',
            $notification['body']
        );
        $this->assertStringNotContainsString($injectedSecret, json_encode($notifications));

        // 2. Filament notification assertion
        $test->assertNotified('Registration not started');

        // 3. Verify the unexpected exception was reported via Laravel exception handling
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === $injectedSecret);
    }

    public function test_authorized_to_view_non_registrar_cannot_invoke_assisted_registration_or_access_ready_applicants(): void
    {
        $accountingUser = User::factory()->create(['status' => User::StatusActive]);
        $accountingUser->assignRole(User::StaffRoleAccounting);

        $term = $this->createTermWithOpenEnrollmentWindow();
        $application = $this->createReadyApplicant($term);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. For Accounting user, assisted registration actions do not exist in their surface
        Livewire::actingAs($accountingUser)
            ->test(ListEnrollments::class)
            ->assertActionDoesNotExist('startReadyApplicantRegistration')
            ->assertActionDoesNotExist('readyApplicants');

        // 2. Direct invocation of startRegistrationForApplicant fails server-side with 403
        Livewire::actingAs($accountingUser)
            ->test(ListEnrollments::class)
            ->call('startRegistrationForApplicant', $application->id, $term->id)
            ->assertForbidden();
    }

    public function test_existing_exact_term_case_opens_without_open_window_and_is_filtered_from_start_selector(): void
    {
        $registrar = $this->createRegistrar();
        $term = $this->createTermWithOpenEnrollmentWindow();
        $applicationWithCase = $this->createReadyApplicant($term);
        $applicationWithoutCase = $this->createReadyApplicant($term);

        // Start existing case while window is open
        $existingEnrollment = app(StartRegistrationCase::class)->forReadyApplicant(
            $applicationWithCase,
            $term,
            $registrar,
            'RegistrarAssisted',
            'Intake desk reference',
        );

        // Close the enrollment window
        TermCalendarWindow::query()
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update([
                'opens_on' => now()->addDays(10)->toDateString(),
                'closes_on' => now()->addDays(20)->toDateString(),
            ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 1. Selector removes applicant with existing case, while applicant without case is available
        $component = Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->mountAction('startReadyApplicantRegistration');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $schema = $component->instance()->getSchema($schemaName);
        $options = $schema->getComponent('application_id')->getOptions();

        $this->assertArrayNotHasKey($applicationWithCase->id, $options);
        $this->assertArrayHasKey($applicationWithoutCase->id, $options);

        // 2. When window is closed, existing case UI route (REG-E02 ViewEnrollment) remains open and accessible
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $existingEnrollment->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_case_matching_is_strictly_exact_term(): void
    {
        $registrar = $this->createRegistrar();
        $term1 = $this->createTermWithOpenEnrollmentWindow('Term 1');
        $term2 = $this->createTermWithOpenEnrollmentWindow('Term 2');

        $application = $this->createReadyApplicant($term1);

        // Create an enrollment for this applicant in a DIFFERENT term (Term 2)
        Enrollment::query()->create([
            'credential_user_id' => $application->user_id,
            'admission_application_id' => null,
            'term_id' => $term2->id,
            'case_reference' => 'REG-TERM2-001',
            'selection_basis' => 'ApplicantAdmissionProjection',
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'status' => 'pending_review',
            'started_by' => $registrar->id,
            'start_method' => 'RegistrarAssisted',
            'started_at' => now(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // For Term 1, applicant should NOT match Term 2 enrollment, so card shows Ready for Case Creation
        $component = Livewire::actingAs($registrar)->test(ListEnrollments::class);
        $action = $component->instance()->getAction('readyApplicants');
        $modalView = $action->getModalContent();
        $casesMap = $modalView->getData()['casesMap'];
        $this->assertNull($casesMap[$application->id]);

        $rendered = $modalView->render();
        $this->assertStringContainsString('Ready for Case Creation', $rendered);
        $this->assertStringNotContainsString('REG-TERM2-001', $rendered);
    }

    private function createRegistrar(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleRegistrar);

        return $user;
    }

    private function createTermWithOpenEnrollmentWindow(?string $label = null): Term
    {
        static $termSequence = 3000;
        $termSequence++;

        $academicYear = AcademicYear::query()->firstOrCreate(
            ['label' => "AY-{$termSequence}-".($termSequence + 1)],
            [
                'starts_on' => "{$termSequence}-08-01",
                'ends_on' => ($termSequence + 1).'-05-31',
                'state' => AcademicYear::StateActive,
            ]
        );

        $term = Term::factory()->for($academicYear)->create([
            'label' => ($label ?? 'Academic Year 2026-2027 1st Semester')." #{$termSequence}",
            'state' => Term::StateActive,
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(5)->toDateString(),
        ]);

        return $term;
    }

    private function createReadyApplicant(Term $term): AdmissionApplication
    {
        $cycle = AdmissionCycle::factory()->for($term)->create();
        $requirementSet = AdmissionRequirementSet::factory()->for($cycle)->create([
            'state' => AdmissionRequirementSet::StateDraft,
        ]);

        $requirement = AdmissionRequirement::factory()->create([
            'admission_requirement_set_id' => $requirementSet->id,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
        ]);

        $requirementSet->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now(),
            'published_at' => now(),
        ]);

        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $program = Program::factory()->create();

        $application = AdmissionApplication::factory()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'application_state' => AdmissionApplication::StateAdmitted,
            'application_path' => AdmissionApplication::PathFirstYear,
        ]);

        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_set_id' => $requirementSet->id,
        ]);

        $application->update(['current_submission_version_id' => $version->id]);

        AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'decision' => AdmissionDecision::DecisionAdmitted,
        ]);

        OfficialCredentialResult::factory()->verified()->create([
            'admission_application_id' => $application->id,
            'admission_requirement_id' => $requirement->id,
        ]);

        return $application->fresh();
    }
}
