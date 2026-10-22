<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Cor\BuildCorOutput;
use App\Actions\Enrollment\ApplyRegistrationAdjustment;
use App\Actions\Enrollment\CancelRegistrationCase;
use App\Actions\Enrollment\ConfirmRegistrationIdentity;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\IssueRegistrationProposal;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\RecordCourseDrop;
use App\Actions\Enrollment\RecordLateEnrollmentReopenAuthority;
use App\Actions\Enrollment\RecordRegistrationAdjustmentFinanceConfirmation;
use App\Actions\Enrollment\RecordRegistrationCreditEvaluation;
use App\Actions\Enrollment\RecordRegistrationLateAuthority;
use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\ReleaseExpiredEnrollmentReservations;
use App\Actions\Enrollment\ReopenRegistrationCase;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\CreateSuccessorFeePlan;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Actions\Finance\UpdateFeePlanDraft;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Applicant\Pages\Dashboard as ApplicantDashboard;
use App\Filament\Applicant\Pages\Enrollment as ApplicantEnrollment;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment as RegistrarEnrollment;
use App\Filament\Student\Pages\CorView;
use App\Filament\Student\Pages\Dashboard;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Filament\Student\Pages\Finance;
use App\Filament\Student\Pages\ScheduleView;
use App\Mail\OfficialEnrollmentMail;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\CalendarEvent;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CorVersion;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\FeePlan;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationLateAuthority;
use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\RegistrationSchedulePresentation;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RegistrationToOfficialEnrollmentJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleFaculty, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Mail::fake();
        Storage::fake('local');
    }

    public static function admissionPaths(): array
    {
        return ['freshman' => [AdmissionApplication::PathFirstYear, false], 'transferee' => [AdmissionApplication::PathTransferee, false], 'transferee with approved credit' => [AdmissionApplication::PathTransferee, true]];
    }

    #[DataProvider('admissionPaths')]
    public function test_ready_applicant_reaches_official_enrollment_and_immutable_cor_without_early_student_identity(string $path, bool $withTransferCredit): void
    {
        [$application, $term] = $this->readyApplicant($path);
        [$section, $timetable, $curriculum] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $assignment = $this->designatedFaculty($section, $registrar);
        $legacyCase = Enrollment::factory()->for($term)->create(['canonical_outcome' => Enrollment::OutcomeInProgress]);
        $legacyMembership = CourseEnrollment::factory()->create([
            'enrollment_id' => $legacyCase->id, 'term_offering_id' => $section->term_offering_id,
            'section_id' => $section->id, 'published_timetable_version_id' => $timetable->id,
            'is_current' => true, 'status' => CourseEnrollment::StatusActive,
        ]);

        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'amount' => '1000.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic approved fee authority');

        $credited = null;
        if ($withTransferCredit) {
            $credited = CurriculumEntry::factory()->for($curriculum)->create(['year_level' => 'First Year', 'term_label' => 'Second Semester', 'term_type' => Term::TypeSecondSemester]);
            CourseRequirement::factory()->create([
                'course_specification_id' => $section->termOffering->curriculumEntry->course_specification_id,
                'related_course_id' => $credited->courseSpecification->course_id, 'accepts_transfer_credit' => true,
            ]);
        }
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case, $credited !== null ? [$credited->id] : []);

        $this->assertNull($case->student_profile_id);
        $this->assertFalse($application->user->hasRole('student'));
        $this->assertSame(1, Enrollment::query()->where('credential_user_id', $application->user_id)->where('term_id', $term->id)->count());
        $this->assertTrue($case->is(app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user)));

        if ($path === AdmissionApplication::PathTransferee) {
            $advised = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->fresh()->lock_version);
            app(PlaceRegistrationProposal::class)->execute($advised, $registrar);
            app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
            app(IssueRegistrationProposal::class)->execute($advised->fresh(), $registrar);
            $case->refresh();
        }
        $proposal = $case->currentProposalVersion;
        $this->assertSame(RegistrationProposalVersion::StateIssued, $proposal->state, (string) $case->status_reason);
        $this->assertNotNull($proposal->items()->firstOrFail()->reservation);
        $assessment = $case->termAccount->assessments()->where('state', Assessment::StateActive)->firstOrFail();
        $this->assertSame($proposal->id, $assessment->source_proposal_version_id);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $review = Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)
            ->assertSee('Proposed subjects')->assertSee('Enrollment payment')
            ->mountAction('confirmProposal')->assertActionMounted('confirmProposal');
        $this->assertStringContainsString('PHP 1,000.00', $review->instance()->getMountedAction()->getModalContent()->render());
        $proposal = app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        $proposal = app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);
        app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);

        $this->assertSame(RegistrationProposalVersion::StateConfirmed, $proposal->state);
        $this->assertSame($timetable->id, $proposal->published_timetable_version_id);
        $this->assertSame($curriculum->id, $proposal->curriculum_version_id);
        $this->assertSame(0, CourseEnrollment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
        $this->assertSame(1, $proposal->items()->firstOrFail()->reservation()->count());
        $this->assertSame(0, GradeRoster::query()->where('section_id', $section->id)->count());
        $this->assertSame(0, GradeRosterRow::query()->count());

        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $assessment->termAccount,
            $application->user,
            UploadedFile::fake()->image('receipt.jpg'),
            '1000.00',
            'bank_transfer',
            now()->subMinute(),
            'SYN-PAYMENT-001',
        );
        $this->assertSame('ActionNeeded', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);
        $this->assertFalse(app(RegistrationReadinessQuery::class)->for($case->fresh())['finance']);
        $this->assertSame(0, $assessment->termAccount->payments()->count());

        app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $accounting,
            '1000.00',
            'SYN-INDEPENDENT-CHECK-001',
        );
        $this->assertSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $finalization = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->mountAction('finalizeOfficialEnrollment')->assertMountedActionModalDontSee('Registrar note');
        $finalization->callMountedAction()->assertHasNoActionErrors()->assertNotified('Official enrollment finalized');
        $official = $case->fresh();
        $again = app(FinalizeOfficialEnrollment::class)->execute($official, $registrar);

        $this->assertTrue($official->is($again));
        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(1, StudentProfile::query()->where('user_id', $application->user_id)->count());
        $this->assertMatchesRegularExpression('/^SIA-\d{4}-\d{4}$/', $official->studentProfile->student_number);
        $this->assertSame($official->studentProfile->first_name, $application->user->fresh()->first_name);
        $this->assertTrue($application->user->fresh()->hasRole('student'));
        $this->assertTrue($application->user->fresh()->hasRole('applicant'));
        $this->assertSame(1, CourseEnrollment::query()->where('enrollment_id', $official->id)->where('is_current', true)->count());
        $this->assertSame(0, EnrollmentGateResult::query()->where('enrollment_id', $official->id)->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
        $this->assertSame(1, $official->corVersions()->count());
        if ($credited !== null) {
            $this->assertContains((int) $credited->courseSpecification->course_id, app(RegistrationAcademicEligibilityQuery::class)->satisfiedCourseIds($official->studentProfile)->all());
            $evaluation = app(CurriculumEvaluation::class)->forStudent($official->studentProfile);
            $creditedRequirement = collect($evaluation['required'])->first(fn (array $row): bool => $row['curriculum_entry']->id === $credited->id);
            $this->assertSame('Approved credit', $creditedRequirement['status']);
            $this->assertSame('3.00', $creditedRequirement['credited_units']);
            $this->assertSame(0, $official->courseEnrollments()->where('term_offering_id', '!=', $section->term_offering_id)->count());
        }
        $roster = GradeRoster::query()->where('section_id', $section->id)->sole();
        $membership = $official->courseEnrollments()->where('is_current', true)->sole();
        $this->assertSame($assignment->id, $roster->teaching_assignment_id);
        $this->assertSame($membership->id, $roster->rows()->sole()->course_enrollment_id);
        $this->assertTrue($roster->rows()->sole()->is_current_membership);
        $this->assertSame(1, GradeRoster::query()->where('section_id', $section->id)->count());
        $this->assertSame(0, GradeRosterRow::query()->where('course_enrollment_id', $legacyMembership->id)->count());
        $this->assertSame(Enrollment::OutcomeInProgress, $legacyCase->fresh()->canonical_outcome);
        $this->assertTrue($legacyMembership->fresh()->is_current);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $facultyPage = Livewire::actingAs($assignment->faculty)->test(FacultyGradeRoster::class)
            ->assertSee($section->termOffering->curriculumEntry->courseSpecification->title)->assertDontSee('No assigned roster');
        $this->assertSame($official->studentProfile->student_number, collect($facultyPage->get('data.rows'))->sole()['student_number']);
        $this->assertSame($roster->rows()->sole()->id, collect($facultyPage->get('data.rows'))->sole()['id']);

        Mail::assertQueued(OfficialEnrollmentMail::class);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeRegistrationProposalEmail)->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeRegistrationPaymentActionEmail)->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeOfficialEnrollmentEmail)->count());

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($application->user->fresh())->test(Dashboard::class)
            ->assertSee('Your enrollment is official')->assertSee('View enrollment')->assertSee('Class schedule');
        Livewire::actingAs($application->user->fresh())->test(Finance::class)
            ->assertSee('No payment due today')->assertSee('PHP 0.00')
            ->assertActionHidden('submitEvidence')->assertActionHidden('payExactCurrentDue');
        Livewire::actingAs($application->user->fresh())->test(CorView::class)
            ->assertSee('Certificate of Registration')->assertSee('PHP 1,000.00');

        $corBefore = app(BuildCorOutput::class)->forEnrollment($official, $application->user);
        $section->update(['code' => 'CHANGED-AFTER-FINALIZATION']);
        $assessment->update(['total' => '9999.00']);
        $corAfter = app(BuildCorOutput::class)->forEnrollment($official->fresh(), $application->user);

        $this->assertSame($corBefore['state'], $corAfter['state']);
        $this->assertSame('1000.00', $corAfter['fees'][0]['amount']);
        $this->assertSame($timetable->id, $corAfter['schedule_version']);

        $originalMeeting = $timetable->meetings()->sole();
        $timetable->update(['state' => PublishedTimetableVersion::StateSuperseded]);
        $successor = PublishedTimetableVersion::factory()->for($term)->create([
            'supersedes_version_id' => $timetable->id,
            'version' => 2,
            'state' => PublishedTimetableVersion::StatePublished,
        ]);
        $currentMeeting = PublishedTimetableMeeting::factory()->for($successor, 'timetableVersion')->create([
            'section_id' => $section->id,
            'supersedes_meeting_id' => $originalMeeting->id,
            'faculty_user_id' => $originalMeeting->faculty_user_id,
            'room_id' => $originalMeeting->room_id,
            'day_of_week' => 3,
            'starts_at' => '14:00:00',
            'ends_at' => '15:30:00',
        ]);
        foreach ([
            ['enrollment_id' => $official->id, 'is_current' => false, 'status' => CourseEnrollment::StatusActive],
            ['enrollment_id' => $official->id, 'is_current' => true, 'status' => CourseEnrollment::StatusDropped],
            ['enrollment_id' => $legacyCase->id, 'is_current' => true, 'status' => CourseEnrollment::StatusActive],
        ] as $excludedMembership) {
            $excludedSection = Section::factory()->for($section->termOffering, 'termOffering')->create();
            CourseEnrollment::factory()->create([
                ...$excludedMembership,
                'term_offering_id' => $section->term_offering_id,
                'section_id' => $excludedSection->id,
                'published_timetable_version_id' => $successor->id,
            ]);
            PublishedTimetableMeeting::factory()->for($successor, 'timetableVersion')->create([
                'section_id' => $excludedSection->id,
            ]);
        }

        $currentSchedule = app(RegistrationSchedulePresentation::class)->currentFor($application->user->fresh());
        $selectedEnrollmentSchedule = app(RegistrationSchedulePresentation::class)->currentForEnrollment($official->fresh());
        $this->assertSame($currentSchedule, $selectedEnrollmentSchedule);
        $this->assertNull(app(RegistrationSchedulePresentation::class)->currentForEnrollment($legacyCase));
        $frozenSchedule = app(RegistrationSchedulePresentation::class)->for(null, $official->fresh());
        $corAfterSuccessor = app(BuildCorOutput::class)->forEnrollment($official->fresh(), $application->user);

        $this->assertSame(Term::StateActive, $term->fresh()->state);
        $this->assertModelExists($timetable);
        $this->assertModelExists($originalMeeting);
        $this->assertSame(PublishedTimetableVersion::StateSuperseded, $timetable->fresh()->state);
        $this->assertSame(PublishedTimetableVersion::StatePublished, $successor->fresh()->state);
        $this->assertSame([$currentMeeting->id], array_map('intval', array_column($currentSchedule['meetings'], 'id')));
        $this->assertSame('Wednesday', $currentSchedule['meetings'][0]['dayLabel']);
        $this->assertSame('2:00 PM–3:30 PM', $currentSchedule['meetings'][0]['time']);
        $specification = $section->termOffering->curriculumEntry->courseSpecification;
        $this->assertNull($section->course_specification_id);
        $this->assertSame($specification->course->code, $currentSchedule['meetings'][0]['courseCode']);
        $this->assertSame($specification->title, $currentSchedule['meetings'][0]['courseTitle']);
        $this->assertSame('CHANGED-AFTER-FINALIZATION', $currentSchedule['meetings'][0]['class']);
        $this->assertSame($currentMeeting->faculty->name, $currentSchedule['meetings'][0]['faculty']);
        $this->assertSame($currentMeeting->room->code, $currentSchedule['meetings'][0]['room']);
        $this->assertSame([$originalMeeting->id], array_map('intval', array_column($frozenSchedule['meetings'], 'id')));
        $this->assertSame('Monday', $frozenSchedule['meetings'][0]['dayLabel']);
        $this->assertSame('8:00 AM–9:30 AM', $frozenSchedule['meetings'][0]['time']);
        $this->assertSame($corBefore['subjects'], $corAfterSuccessor['subjects']);
        $this->assertSame($timetable->id, $corAfterSuccessor['schedule_version']);
        Livewire::actingAs($application->user->fresh())->test(Dashboard::class)
            ->assertSee('Class schedule')->assertSee('2:00 PM')->assertDontSee('8:00 AM');
        Livewire::actingAs($application->user->fresh())->test(StudentEnrollmentPage::class)
            ->assertSee('Registered subjects')->assertSee('View registration')
            ->assertSee('CHANGED-AFTER-FINALIZATION')->assertSee('View fees and payments')
            ->assertDontSee('Charges and payment requirement')->assertDontSee('Case history and recovery')
            ->assertViewHas('registeredSubjects', fn ($subjects): bool => $subjects->pluck('id')->all() === [$membership->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $registrarPage = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertSee('Officially enrolled')->assertSee('View registration certificate')
            ->assertSee('1 subject ·')
            ->assertSee('2:00 PM')->assertDontSee('8:00 AM')
            ->assertSee('CHANGED-AFTER-FINALIZATION')
            ->assertDontSee('Subjects and reserved schedule')->assertDontSee('Five finalization checkpoints');
        $this->assertSame('official', $registrarPage->instance()->focusedRegistrarView());
        Livewire::actingAs($registrar)->withQueryParams(['view' => 'history'])->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertSee('Current certificate')->assertSee('Issued ')->assertSee('Open certificate');
    }

    public function test_head_official_inspection_separates_pending_sources_and_preserves_current_memberships_and_return_context(): void
    {
        [$official, , $term, $section, $timetable, $registrar] = $this->officialEnrollment();
        $membership = $official->courseEnrollments()->sole();
        $issuedProposal = $official->currentCorVersion->proposalVersion;
        $issuedProposal->curriculumVersion->update(['name' => 'Issued curriculum authority']);
        $originalMeeting = $timetable->meetings()->sole();
        $timetable->update(['state' => PublishedTimetableVersion::StateSuperseded]);
        $successor = PublishedTimetableVersion::factory()->for($term)->create([
            'supersedes_version_id' => $timetable->id, 'version' => 2,
            'state' => PublishedTimetableVersion::StatePublished,
        ]);
        PublishedTimetableMeeting::factory()->for($successor, 'timetableVersion')->create([
            'section_id' => $section->id, 'faculty_user_id' => $originalMeeting->faculty_user_id,
            'room_id' => $originalMeeting->room_id, 'day_of_week' => 3,
            'starts_at' => '14:00:00', 'ends_at' => '15:30:00',
        ]);
        foreach ([['is_current' => false, 'status' => CourseEnrollment::StatusActive], ['is_current' => true, 'status' => CourseEnrollment::StatusDropped]] as $excluded) {
            $excludedSection = Section::factory()->for($section->termOffering, 'termOffering')->create();
            CourseEnrollment::factory()->create([
                ...$excluded, 'enrollment_id' => $official->id,
                'term_offering_id' => $section->term_offering_id, 'section_id' => $excludedSection->id,
                'published_timetable_version_id' => $successor->id,
            ]);
            PublishedTimetableMeeting::factory()->for($successor, 'timetableVersion')->create(['section_id' => $excludedSection->id]);
        }
        $pendingCurriculum = CurriculumVersion::factory()->create(['name' => 'Pending curriculum authority']);
        $pending = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $official->id, 'version' => 2, 'purpose' => RegistrationProposalVersion::PurposeAdjustment,
            'curriculum_version_id' => $pendingCurriculum->id, 'published_timetable_version_id' => $successor->id,
            'prepared_by' => $registrar->id,
        ]);
        RegistrationProposalItem::factory()->create([
            'registration_proposal_version_id' => $pending->id, 'term_offering_id' => $section->term_offering_id,
            'section_id' => $section->id, 'course_title_snapshot' => 'Pending subject must remain proposed',
        ]);
        $official->update(['current_proposal_version_id' => $pending->id]);
        $originTerm = Term::factory()->create();
        $filters = ['term' => ['value' => (string) $term->id], 'canonical_outcome' => ['value' => Enrollment::OutcomeOfficiallyEnrolled]];
        $before = [$official->fresh()->getAttributes(), $official->courseEnrollments()->get()->toArray(), $official->corVersions()->get()->toArray(), RegistrationCaseEvent::query()->count()];

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))->test(RegistrarEnrollment::class, [
            'record' => $official->id, 'oversightTermId' => $originTerm->id,
            'returnFilters' => $filters, 'returnSearch' => 'Exact learner',
        ])->assertSee('Official class registrations')->assertSee('Issued curriculum authority')->assertDontSee('Pending curriculum authority')
            ->assertSee('Wednesday')->assertSee('2:00 PM')->assertDontSee('8:00 AM')
            ->assertSee('Proposed subject change')->assertSee('Pending subject must remain proposed')
            ->assertSee('This proposal has not replaced the official registrations.')
            ->assertActionHasUrl('backToEnrollmentOversight', EnrollmentResource::getUrl('index', [
                'oversightTermId' => $originTerm->id, 'filters' => $filters, 'search' => 'Exact learner',
            ]));
        foreach (['printCor', 'printCorHistory', 'resendOfficialEnrollmentEmail'] as $action) {
            $page->assertActionDoesNotExist($action);
        }
        $page->assertDontSee('Prepare enrollment adjustment')->assertDontSee('Adjust official registration')
            ->assertDontSee('Record course drop')->assertDontSee('Cancel registration case');
        foreach (['finalizeOfficialEnrollment', 'prepareProposal', 'createAssessment'] as $action) {
            $page->assertActionHidden($action);
        }
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new \DOMXPath($document);
        $officialSubjects = $xpath->query('//section[h2[normalize-space()="Official class registrations"] or div/h2[normalize-space()="Official class registrations"]]')->item(0);
        $this->assertNotNull($officialSubjects);
        $this->assertStringContainsString($membership->proposalItem->course_title_snapshot, $officialSubjects->textContent);
        $this->assertStringNotContainsString('Pending subject must remain proposed', $officialSubjects->textContent);
        $this->assertSame(1, $xpath->query('.//tbody/tr', $officialSubjects)->length);
        $this->assertSame(1, $xpath->query('//section[h2[normalize-space()="Published class schedule"]]//li')->length);
        $this->assertSame('Self Service · '.$issuedProposal->confirmation->confirmed_at->timezone('Asia/Manila')->format('j M Y, g:i A'),
            trim($xpath->query('//div[dt[normalize-space()="Issued subject confirmation"]]/dd')->item(0)->textContent));
        $this->assertSame($before, [$official->fresh()->getAttributes(), $official->courseEnrollments()->get()->toArray(), $official->corVersions()->get()->toArray(), RegistrationCaseEvent::query()->count()]);
    }

    public function test_head_in_progress_inspection_uses_recorded_proposal_deadline_and_confirmation_without_official_or_finance_controls(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $head = $this->staff(User::StaffRoleAcademicHead);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $unprepared = Enrollment::factory()->create(['term_id' => $term->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($head)->test(RegistrarEnrollment::class, ['record' => $unprepared->id])
            ->assertSee('Subjects being prepared')->assertSee('Registrar has not recorded a subject proposal')
            ->assertDontSee('Proposed class schedule')->assertDontSee('Recorded seat deadline')
            ->assertDontSee('Current enrollment clearance')->assertDontSee('Official class registrations');

        $proposal = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$section->id], $case->fresh()->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $item = $proposal->fresh()->items()->sole();
        $deadline = $item->reservation->deadline->timezone('Asia/Manila')->format('j M Y, g:i A');
        $page = Livewire::actingAs($head)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Proposed subjects')->assertSee('Awaiting learner confirmation')
            ->assertSee('Recorded seat deadline')->assertSee($deadline)
            ->assertSee('Registration proposal v'.$proposal->version.' · Published timetable v'.$timetable->version)
            ->assertSee('Current placement source')->assertSee($item->course_title_snapshot)
            ->assertSee('These subjects are proposed and are not official registrations.')
            ->assertDontSee('Official class registrations')->assertDontSee('PHP')->assertDontSee('0.00')
            ->assertActionHidden('prepareProposal')->assertActionHidden('finalizeOfficialEnrollment')->assertActionHidden('createAssessment');
        $page->assertActionDoesNotExist('printCor')->assertActionDoesNotExist('resendOfficialEnrollmentEmail');

        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $reserve = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Reserve seats');
        $this->assertFalse($reserve->instance()->getAction('placeProposal')->shouldOpenModal());
        $reserve->callAction('placeProposal')->assertHasNoActionErrors()->assertNotified('Seats reserved');
        Livewire::actingAs($head)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Awaiting finalization')->assertSee('Confirmed proposed subjects')
            ->assertSee('Self Service')->assertDontSee('Official class registrations')->assertDontSee('PHP')
            ->assertActionHidden('finalizeOfficialEnrollment');
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertNull($case->fresh()->current_cor_version_id);
        $this->assertSame(0, $case->courseEnrollments()->count());
    }

    #[DataProvider('closedHeadOutcomes')]
    public function test_head_closed_and_unknown_outcomes_keep_escaped_recorded_history_without_finance_or_recovery_controls(?string $outcome, string $label, string $heading): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $reason = 'Recorded closure <script>alert("reason")</script>';
        $authority = 'Approved <em>authority</em>';
        $case = Enrollment::factory()->create(['canonical_outcome' => $outcome, 'status_reason' => $reason]);
        $event = RegistrationCaseEvent::factory()->create([
            'enrollment_id' => $case->id, 'event_type' => 'OutcomeRecorded', 'to_outcome' => $outcome,
            'reason' => $reason, 'authority_reference' => $authority, 'actor_id' => $registrar->id,
            'recorded_at' => '2026-10-18 04:15:00',
        ]);
        $account = TermAccount::factory()->create(['enrollment_id' => $case->id]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $case->id, 'term_account_id' => $account->id,
            'subtotal' => '9876.54', 'total' => '9876.54', 'required_downpayment' => '9876.54',
        ]);
        $obligation = AssessmentObligation::factory()->create(['assessment_id' => $assessment->id, 'amount' => '9876.54']);
        $before = [$case->fresh()->getAttributes(), $event->fresh()->getAttributes(), $account->fresh()->getAttributes(), $assessment->fresh()->getAttributes(), $obligation->fresh()->getAttributes(), RegistrationCaseEvent::query()->count()];
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee($label)->assertSee($heading)->assertSee('Recorded reason: '.$reason)
            ->assertSee('Recorded activity')->assertSee('Outcome Recorded')->assertSee($authority)
            ->assertSee($registrar->getFilamentName())->assertSee('18 Oct 2026, 12:15 PM')
            ->assertSee('Assessment '.$assessment->id)->assertSee('Accounting review needed')
            ->assertDontSeeHtml($reason)->assertDontSeeHtml($authority)
            ->assertDontSee('Five finalization checkpoints')->assertDontSee('Finance requirement')
            ->assertDontSee('Official class registrations')->assertDontSee('Proposed subjects')->assertDontSee('Subjects being prepared')
            ->assertDontSee('9876.54')->assertDontSee('9,876.54')->assertDontSee('PHP')
            ->assertDontSee('Reopen registration case')->assertDontSee('Cancel registration case')
            ->assertActionHidden('finalizeOfficialEnrollment')->assertActionHidden('prepareProposal')->assertActionHidden('createAssessment');
        foreach (['printCor', 'printCorHistory', 'resendOfficialEnrollmentEmail'] as $action) {
            $page->assertActionDoesNotExist($action);
        }
        $this->assertSame($before, [$case->fresh()->getAttributes(), $event->fresh()->getAttributes(), $account->fresh()->getAttributes(), $assessment->fresh()->getAttributes(), $obligation->fresh()->getAttributes(), RegistrationCaseEvent::query()->count()]);
        $this->assertSame(0, $case->courseEnrollments()->count());
        $this->assertSame(0, $case->corVersions()->count());
    }

    /** @return array<string, array{?string, string, string}> */
    public static function closedHeadOutcomes(): array
    {
        return [
            'registrar cancellation' => [Enrollment::OutcomeCancelledByRegistrar, 'Cancelled by Registrar', 'Closed enrollment'],
            'not enrolled' => [Enrollment::OutcomeNotEnrolled, 'Not enrolled', 'Closed enrollment'],
            'unrecognized source' => ['Unrecognized', 'Outcome unavailable', 'Enrollment outcome needs review'],
            'missing source' => [null, 'Outcome unavailable', 'Enrollment outcome needs review'],
        ];
    }

    public function test_cor_groups_multiple_meetings_and_keeps_the_issued_program_and_assessment_authority(): void
    {
        [$application, $term] = $this->readyApplicant();
        $application->program->update(['name' => 'Program captured at issue']);
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $section->termOffering->curriculumEntry->courseSpecification->update(['credit_units' => '3.00']);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'faculty_user_id' => $timetable->meetings()->sole()->faculty_user_id,
            'meeting_sequence' => 2, 'day_of_week' => 3, 'starts_at' => '14:00:00', 'ends_at' => '15:30:00',
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application->fresh(), $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $application->program->update(['name' => 'Program renamed after issue']);
        $official->termAccount->assessments()->where('state', Assessment::StateActive)->update(['authority_reference' => 'LIVE-AUTHORITY-CHANGED-AFTER-ISSUE']);

        $output = app(BuildCorOutput::class)->forEnrollment($official, $registrar);
        $response = $this->actingAs($registrar)->get(route('cor.print', $official))
            ->assertSee('Program captured at issue')->assertDontSee('Program renamed after issue')
            ->assertSee('SYN-NO-PAYMENT-PLAN')->assertDontSee('LIVE-AUTHORITY-CHANGED-AFTER-ISSUE')
            ->assertSee('Monday')->assertSee('Wednesday')->assertSee('14:00 - 15:30');

        $this->assertSame('3.00', $output['summary']['total_units']);
        $this->assertCount(2, $output['subjects']);
        $this->assertSame(1, substr_count($response->getContent(), $output['subjects'][0]['subject_code']));
        $this->assertSame($official->currentCorVersion->content_hash, $official->fresh()->currentCorVersion->content_hash);
    }

    public function test_changed_admissions_source_requires_current_registrar_clearance_and_preserves_legacy_identity_history(): void
    {
        [$case, $application, , $registrar] = $this->caseReadyForFinalization();
        $legacyConfirmation = app(ConfirmRegistrationIdentity::class)->execute($case, $application->user);

        $application->update([
            'phone' => '09171234567',
            'lrn' => '123456789012',
        ]);

        $this->assertFalse(app(RegistrationReadinessQuery::class)->for($case->fresh())['identity']);

        try {
            app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
            $this->fail('Changed admissions identity facts must require current Registrar clearance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        $source = $application->fresh();
        $priorClearance = $source->enrollmentClearances()->whereDoesntHave('successor')->sole();
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($source)->create([
            'result' => RegistrarEnrollmentClearance::ResultCleared,
            'external_checks_confirmed' => true,
            'recorded_by' => $registrar->id,
            'supersedes_clearance_id' => $priorClearance->id,
            'reason' => 'Registrar rechecked the changed identity source.',
        ]);
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);

        $this->assertSame(1, $case->identityConfirmationVersions()->count());
        $this->assertSame($legacyConfirmation->id, $case->identityConfirmationVersions()->sole()->id);
        $this->assertSame('09171234567', $official->studentProfile->phone);
        $this->assertSame('123456789012', $official->studentProfile->prior_identifier);
        $this->assertNull(data_get($official->currentCorVersion->snapshot, 'identity_confirmation_version_id'));
    }

    /** @return array<string, array{string,string}> */
    public static function registrarRequirementFailures(): array
    {
        return [
            'legacy admissions change without a review event' => ['admissions', 'Check admissions clearance'],
            'inactive account' => ['account', 'Student account is inactive'],
            'retired curriculum' => ['curriculum', 'Review subject eligibility'],
        ];
    }

    #[DataProvider('registrarRequirementFailures')]
    public function test_registrar_blocked_enrollment_names_the_actual_recovery_without_internal_checkpoints(string $failure, string $task): void
    {
        [$case, $application, , $registrar] = $this->caseReadyForFinalization();
        if ($failure === 'admissions') {
            DB::table($application->getTable())->where('id', $application->id)->update(['phone' => '09171234567', 'lrn' => '999999999999']);
            $registrar->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
        } elseif ($failure === 'account') {
            $application->user->update(['status' => User::StatusInactive]);
        } else {
            $case->currentProposalVersion->curriculumVersion->update(['state' => CurriculumVersion::StateSuperseded]);
        }
        $before = $case->fresh()->getAttributes();
        $proposalBefore = $case->currentProposalVersion->fresh()->getAttributes();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Review enrollment requirements')->assertSee($task)
            ->assertDontSee('State, owner, and next action')->assertDontSee('Five finalization checkpoints')
            ->assertDontSee('Current source review required')->assertDontSee('Case actions and recovery')
            ->assertActionHidden('finalizeOfficialEnrollment');
        if ($failure === 'admissions') {
            $page->assertSee('Review admissions');
        } elseif ($failure === 'account') {
            $page->assertSee('Ask the System Administrator')->assertDontSee('Review admissions');
        } else {
            $page->assertSee('Revise subjects');
        }
        $page->set('enrollmentView', 'history')->assertSee('Activity')->assertSee('Supporting records')
            ->assertSee('Subject selections')->assertSee('Current selection')->assertDontSee('Subjects version');
        try {
            app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
            $this->fail('A simpler recovery view must preserve the finalization refusal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($failure === 'curriculum' ? 'sections' : 'readiness', $exception->errors());
        }
        $this->assertSame($before, $case->fresh()->getAttributes());
        $this->assertSame($proposalBefore, $case->currentProposalVersion->fresh()->getAttributes());
        $this->assertSame(0, $case->corVersions()->count());
    }

    public function test_approved_no_meeting_course_places_and_finalizes_without_inventing_a_recurring_schedule(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $specification = $section->termOffering->curriculumEntry->courseSpecification;
        $specification->update(['scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged]);
        PublishedTimetableMeeting::query()->where('section_id', $section->id)->delete();

        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic no-payment authority');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $output = app(BuildCorOutput::class)->forEnrollment($official, $application->user);

        $this->assertSame(CourseSpecification::SchedulingExternallyArranged, $proposal->items()->sole()->scheduling_treatment_snapshot);
        $this->assertSame([], $proposal->items()->sole()->meeting_snapshot);
        $this->assertSame('No recurring meeting', $output['subjects'][0]['day']);
        $this->assertSame('Externally arranged', $output['subjects'][0]['modality']);
    }

    public function test_unavailable_assessment_never_falls_back_to_zero_and_fee_plan_publication_is_versioned(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        try {
            app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
            $this->fail('Missing fee authority must remain Unavailable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }
        $unavailable = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh());
        $this->assertSame('Unavailable', $unavailable['state']);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)
            ->assertSee('Waiting for Registrar and Accounting')
            ->assertSee('No action is needed from you yet.')
            ->assertDontSee('PHP 0.00')
            ->assertActionHidden('confirmProposal');
        $this->assertNull($unavailable['total']);
        $this->assertNull($unavailable['balance']);

        $draft = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $published = $this->publishFeePlan($draft, $accounting, 'Synthetic no-payment authority');
        $successor = app(CreateSuccessorFeePlan::class)->execute($published, $accounting);
        $this->updateFeePlanDraft(
            $successor,
            [['code' => 'NO-PAYMENT-V2', 'label' => 'Successor no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $successor = $this->publishFeePlan($successor->fresh(), $accounting, 'Synthetic successor authority');
        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);

        $this->assertSame(FeePlan::StateSuperseded, $published->fresh()->state);
        $this->assertSame(FeePlan::StatePublished, $successor->state);
        $this->assertSame($published->id, $successor->supersedes_fee_plan_id);
        $this->assertSame('PublishedFeePlan', $assessment->assessment_basis);
        $this->assertSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);
        $this->assertSame('NoPaymentRequired', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['basis']);
        $this->assertSame(0, OperationalEvent::query()->where('event_type', OperationalEvent::TypeRegistrationPaymentActionEmail)->count());
    }

    public function test_cancel_reopen_and_stale_proposal_paths_are_fail_closed(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $cancelled = app(CancelRegistrationCase::class)->execute($case, $application->user, 'Learner changed plans.');
        $reopened = app(ReopenRegistrationCase::class)->execute($cancelled, $registrar, 'Authorized same-case recovery.', ' SYN-REOPEN-001 ');

        $this->assertSame($case->id, $reopened->id);
        $this->assertSame('SYN-REOPEN-001', $reopened->registrationEvents()->where('event_type', 'Reopened')->sole()->authority_reference);
        $this->assertSame(4, $reopened->registrationEvents()->count());
        $this->assertTrue($reopened->registrationEvents()->where('event_type', 'ReopenImpactPreviewed')->exists());

        $expectedVersion = $reopened->lock_version;
        $reopened->update(['lock_version' => $expectedVersion + 1]);

        $arbitrarySection = Section::factory()->create();
        $historyCount = $reopened->proposalVersions()->count();
        try {
            app(PrepareRegistrationProposal::class)->execute($reopened, $application->user, [$arbitrarySection->id], $reopened->lock_version);
            $this->fail('Learners must not select arbitrary Class Offerings.');
        } catch (AuthorizationException) {
            $this->assertSame($historyCount, $reopened->proposalVersions()->count());
        }

        $this->expectException(ValidationException::class);
        app(PrepareRegistrationProposal::class)->execute($reopened, $registrar, [$section->id], $expectedVersion);
    }

    public function test_registrar_closed_case_leads_with_attributed_outcome_and_preserves_financial_history(): void
    {
        $this->freezeTime();
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $account = TermAccount::factory()->create(['enrollment_id' => $case->id]);
        $assessment = Assessment::factory()->create(['enrollment_id' => $case->id, 'term_account_id' => $account->id, 'total' => '4321.00']);
        $payment = Payment::factory()->create(['student_profile_id' => null, 'term_account_id' => $account->id, 'term_id' => $term->id]);
        $paymentBefore = $payment->fresh()->getAttributes();
        $cancelled = app(CancelRegistrationCase::class)->execute($case, $registrar, 'Recorded closure <reason>.');
        $financialBefore = $assessment->fresh()->getAttributes();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Registration was cancelled')
            ->assertSee('Cancelled by Registrar')
            ->assertSee('Recorded closure &lt;reason&gt;.', escape: false)
            ->assertSee($registrar->getFilamentName())
            ->assertDontSee('Assessment and enrollment requirement')
            ->assertDontSee('Prepare subjects')
            ->assertDontSee('Confirmation and finalization')
            ->assertDontSee('4,321.00')
            ->assertDontSee('Case actions and recovery')
            ->assertActionVisible('reopenCase')
            ->assertActionHidden('recordLateReopenAuthority')
            ->set('enrollmentView', 'history')
            ->assertSee('Recorded closure &lt;reason&gt;.', escape: false)
            ->assertSee('Supporting records')
            ->set('enrollmentView', 'overview')
            ->mountAction('reopenCase')->assertActionMounted('reopenCase')
            ->assertMountedActionModalSee(['Reopen enrollment', 'previous seats are not restored.'])
            ->assertMountedActionModalDontSee('Approval reference');

        $component->setActionData(['reason' => ''])->callMountedAction()->assertHasActionErrors(['reason']);
        $case->increment('lock_version');
        $component->setActionData(['reason' => 'Resume current enrollment.', 'reviewed_lock_version' => $case->fresh()->lock_version])
            ->callMountedAction()->assertNotified('Registration Case was not reopened');
        $this->assertSame(Enrollment::OutcomeCancelledByRegistrar, $case->fresh()->canonical_outcome);
        $this->assertFalse($case->registrationEvents()->where('event_type', 'Reopened')->exists());

        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->callAction('reopenCase', ['reason' => 'Resume current enrollment.']);

        $component->assertHasNoActionErrors()->assertNotified('Registration reopened');
        $this->assertSame(Enrollment::OutcomeInProgress, $cancelled->fresh()->canonical_outcome);
        $this->assertSame('4321.00', $assessment->fresh()->total);
        $this->assertSame($financialBefore['source_snapshot'], $assessment->fresh()->getAttributes()['source_snapshot']);
        $this->assertSame($account->id, $case->fresh()->termAccount->id);
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertNull($case->fresh()->current_proposal_version_id);
        $event = $case->registrationEvents()->where('event_type', 'Reopened')->sole();
        $this->assertSame($registrar->id, $event->actor_id);
        $this->assertSame('Resume current enrollment.', $event->reason);
        $this->assertSame(now()->toDateTimeString(), $event->recorded_at->toDateTimeString());
        $this->assertNull($event->authority_reference);
    }

    public function test_registrar_reopen_form_uses_exact_recorded_authority_and_rejects_stale_review(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $case = app(CancelRegistrationCase::class)->execute($case, $registrar, 'Closed case.');
        TermCalendarWindow::query()->whereHas('package', fn ($query) => $query->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update(['closes_on' => now()->subDay()->toDateString()]);
        $authority = app(RecordLateEnrollmentReopenAuthority::class)->execute($case, $registrar, 'EXACT-REOPEN', 'Approved late resumption.');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->mountAction('reopenCase')->assertActionMounted('reopenCase');
        $case->increment('lock_version');
        $component->setActionData(['reason' => 'Resume.', 'late_authority_event_id' => $authority->id, 'reviewed_lock_version' => $case->fresh()->lock_version])
            ->callMountedAction()->assertNotified('Registration Case was not reopened');
        $this->assertSame(Enrollment::OutcomeCancelledByRegistrar, $case->fresh()->canonical_outcome);
        $this->assertFalse($case->registrationEvents()->where('event_type', 'Reopened')->exists());

        app(RecordLateEnrollmentReopenAuthority::class)->execute($case, $registrar, 'SECOND-REOPEN');
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->mountAction('reopenCase')->assertMountedActionModalSee(['EXACT-REOPEN', 'SECOND-REOPEN'])
            ->setActionData(['reason' => 'Resume.', 'late_authority_event_id' => $authority->id])->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Registration reopened');
        $this->assertSame('EXACT-REOPEN', $case->registrationEvents()->where('event_type', 'Reopened')->sole()->authority_reference);
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
    }

    public function test_registrar_terminal_recovery_hides_foreign_authority_and_unknown_outcome_actions(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $case = app(CancelRegistrationCase::class)->execute($case, $registrar, 'Closed.');
        [$otherApplication, $otherTerm] = $this->readyApplicant();
        $other = app(StartRegistrationCase::class)->forReadyApplicant($otherApplication, $otherTerm, $otherApplication->user);
        $other = app(CancelRegistrationCase::class)->execute($other, $registrar, 'Other closed case.');
        $foreign = app(RecordLateEnrollmentReopenAuthority::class)->execute($other, $registrar, 'FOREIGN-APPROVAL', 'Other learner approval.');
        app(RecordLateEnrollmentReopenAuthority::class)->execute($case, $registrar, 'LOCAL-APPROVAL', 'Current learner approval.');
        TermCalendarWindow::query()->whereHas('package', fn ($query) => $query->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update(['closes_on' => now()->subDay()->toDateString()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->callAction('reopenCase', ['reason' => 'Attempt foreign approval.', 'late_authority_event_id' => $foreign->id])
            ->assertHasActionErrors(['late_authority_event_id']);
        $this->assertSame(Enrollment::OutcomeCancelledByRegistrar, $case->fresh()->canonical_outcome);
        $this->assertFalse($case->registrationEvents()->where('event_type', 'Reopened')->exists());
        $case->update(['canonical_outcome' => null]);
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('The enrollment outcome needs review')->assertSee('Outcome unavailable')
            ->assertActionHidden('reopenCase')->assertActionHidden('recordLateReopenAuthority');
        $accounting = $this->staff(User::StaffRoleAccounting);
        Livewire::actingAs($accounting)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertActionHidden('reopenCase')->assertActionHidden('recordLateReopenAuthority');
    }

    public function test_registrar_cancel_dialog_rejects_a_changed_case_without_cancelling_it(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->mountAction('cancelCase')->assertActionMounted('cancelCase');
        $case->increment('lock_version');
        $component->setActionData(['reason' => 'Stale cancellation.'])->callMountedAction()
            ->assertNotified('Registration Case was not cancelled');
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertFalse($case->registrationEvents()->where('event_type', Enrollment::OutcomeCancelledByRegistrar)->exists());
    }

    public function test_registrar_not_enrolled_without_a_published_cutoff_explains_recovery_without_reopen_permission(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $case->update(['canonical_outcome' => Enrollment::OutcomeNotEnrolled, 'status' => 'not_enrolled',
            'status_reason' => 'The enrollment deadline passed.']);
        TermCalendarWindow::query()->whereHas('package', fn ($query) => $query->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeEnrollment)->delete();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('The enrollment cutoff passed')->assertSee('The enrollment deadline passed.')
            ->assertSee('The final enrollment cutoff is unavailable.')
            ->assertActionDisabled('reopenCase')->assertDontSee('Assessment and enrollment requirement');
        $this->assertSame(Enrollment::OutcomeNotEnrolled, $case->fresh()->canonical_outcome);
        $this->assertFalse($case->registrationEvents()->where('event_type', 'Reopened')->exists());
    }

    public function test_registrar_late_authority_form_rejects_stale_review_and_records_only_its_current_case(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $case = app(CancelRegistrationCase::class)->execute($case, $registrar, 'Closed.');
        TermCalendarWindow::query()->whereHas('package', fn ($query) => $query->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update(['closes_on' => now()->subDay()->toDateString()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->mountAction('reopenCase')->assertMountedActionModalDontSee('Reopen enrollment')
            ->assertMountedActionModalSee('Record late-enrollment approval');
        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertActionEnabled('reopenCase')->mountAction('recordLateReopenAuthority')
            ->assertActionMounted('recordLateReopenAuthority');
        $case->increment('lock_version');
        $component->setActionData(['authority_reference' => 'STALE-APPROVAL', 'reason' => 'Late approval.', 'reviewed_lock_version' => $case->fresh()->lock_version])
            ->callMountedAction();
        $this->assertFalse($case->registrationEvents()->where('event_type', RecordLateEnrollmentReopenAuthority::EventType)->exists());
        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->callAction('recordLateReopenAuthority', ['authority_reference' => 'CURRENT-APPROVAL'])
            ->assertHasNoActionErrors()->assertNotified('Late-enrollment approval recorded')
            ->assertActionMounted('reopenCase')->assertMountedActionModalSee(['CURRENT-APPROVAL', 'Reason for reopening']);
        $component->setActionData(['reason' => 'Resume registration after reviewing the approval.'])
            ->callMountedAction()->assertNotified('Registration reopened');
        $event = $case->registrationEvents()->where('event_type', RecordLateEnrollmentReopenAuthority::EventType)->sole();
        $this->assertSame('CURRENT-APPROVAL', $event->authority_reference);
        $this->assertNull($event->reason);
        $this->assertSame($registrar->id, $event->actor_id);
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
    }

    public function test_late_reopen_reference_may_cover_multiple_cases_but_cannot_be_reused_after_a_later_case_cycle(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $case = app(CancelRegistrationCase::class)->execute($case, $registrar, 'Closed for this term.');
        TermCalendarWindow::query()->whereHas('package', fn ($query) => $query->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update(['closes_on' => now()->subDay()->toDateString()]);

        $authority = app(RecordLateEnrollmentReopenAuthority::class)->execute($case, $registrar, 'SCHOOL-APPROVAL-42');
        [$otherApplication, $otherTerm] = $this->readyApplicant();
        $otherCase = app(StartRegistrationCase::class)->forReadyApplicant($otherApplication, $otherTerm, $otherApplication->user);
        $otherCase = app(CancelRegistrationCase::class)->execute($otherCase, $registrar, 'Another learner closed.');
        $sharedDocument = app(RecordLateEnrollmentReopenAuthority::class)->execute($otherCase, $registrar, 'SCHOOL-APPROVAL-42');
        $this->assertNotSame($authority->id, $sharedDocument->id);

        $case = app(ReopenRegistrationCase::class)->execute($case, $registrar, 'Resume this learner.',
            $authority->authority_reference, $case->lock_version, $authority);
        $case = app(CancelRegistrationCase::class)->execute($case, $registrar, 'Closed again after review.');

        try {
            app(RecordLateEnrollmentReopenAuthority::class)->execute($case, $registrar, 'SCHOOL-APPROVAL-42');
            $this->fail('An approval used during an earlier case cycle cannot be reused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }

        $newAuthority = app(RecordLateEnrollmentReopenAuthority::class)->execute($case, $registrar, 'SCHOOL-APPROVAL-43');
        $this->assertSame(Enrollment::OutcomeCancelledByRegistrar, $case->fresh()->canonical_outcome);
        $this->assertNull($newAuthority->reason);
    }

    public function test_after_cutoff_reopen_requires_exact_unused_authority_for_the_same_case(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $cancelled = app(CancelRegistrationCase::class)->execute($case, $application->user, 'Learner cancelled before confirmation.');
        TermCalendarWindow::query()
            ->whereHas('package', fn ($query) => $query->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive))
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update(['closes_on' => now()->subDay()->toDateString()]);

        try {
            app(ReopenRegistrationCase::class)->execute(
                $cancelled,
                $registrar,
                'Late same-case recovery.',
                'SYN-LATE-REOPEN-001',
            );
            $this->fail('A terminal case cannot reopen after the final cutoff without exact authority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }

        $authority = app(RecordLateEnrollmentReopenAuthority::class)->execute(
            $cancelled,
            $registrar,
            'SYN-LATE-REOPEN-001',
            'Registrar approved exact-Term late enrollment recovery.',
        );
        try {
            app(ReopenRegistrationCase::class)->execute(
                $cancelled, $registrar, 'Attempt mismatched approval.', 'WRONG-REFERENCE', $cancelled->lock_version, $authority,
            );
            $this->fail('A supplied reference must match the exact late authority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }
        $reopened = app(ReopenRegistrationCase::class)->execute(
            $cancelled,
            $registrar,
            'Late same-case recovery.',
            null,
            $cancelled->lock_version,
            $authority,
        );

        $this->assertSame($case->id, $reopened->id);
        $this->assertSame(Enrollment::OutcomeInProgress, $reopened->canonical_outcome);
        $this->assertSame('SYN-LATE-REOPEN-001', $reopened->registrationEvents()->where('event_type', 'Reopened')->sole()->authority_reference);
        $cancelledAgain = app(CancelRegistrationCase::class)->execute($reopened, $registrar, 'Second terminal state.');

        try {
            app(ReopenRegistrationCase::class)->execute(
                $cancelledAgain,
                $registrar,
                'Attempted authority reuse.',
                'SYN-LATE-REOPEN-001',
                $cancelledAgain->lock_version,
                $authority,
            );
            $this->fail('A late-enrollment authority must be single-use.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }
    }

    public function test_standard_curriculum_requires_the_complete_exact_term_offering_set(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$firstSection, $timetable, $curriculum] = $this->publishedOffering($application, $term);
        $secondEntry = CurriculumEntry::factory()->for($curriculum)->create();
        $secondOffering = TermOffering::factory()->for($term)->for($secondEntry)->create([
            'state' => TermOffering::StateScheduled,
        ]);
        $secondSection = Section::factory()->for($secondOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $secondSection->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 3,
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);

        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$firstSection->id], $case->lock_version);
            $this->fail('Standard Curriculum must not silently omit an expected exact-Term offering.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections', $exception->errors());
        }

        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $case->fresh(),
            $registrar,
            [$firstSection->id, $secondSection->id],
            $case->fresh()->lock_version,
        );

        $this->assertCount(2, $proposal->items);
        $this->assertEqualsCanonicalizing(
            [$firstSection->id, $secondSection->id],
            $proposal->items->pluck('section_id')->all(),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function registrarActiveRecoveryStates(): iterable
    {
        yield 'payment due' => ['payment', 'Waiting for enrollment payment'];
        yield 'current proof review' => ['review', 'Payment awaiting Accounting review'];
        yield 'missing assessment' => ['assessment', 'Waiting for Accounting assessment'];
        yield 'released seats' => ['seats', 'Seats need a new reservation'];
        yield 'expired active seats' => ['expired', 'Review the selected classes'];
        yield 'full released class' => ['shortage', 'Review the selected classes'];
    }

    #[DataProvider('registrarActiveRecoveryStates')]
    public function test_registrar_active_recovery_leads_with_its_current_task_and_preserves_source_guards(string $state, string $heading): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $assessment = $this->recordIndividualAssessment($case->fresh(), $this->staff(User::StaffRoleAccounting), 'SYN-ACTIVE-RECOVERY', [
            ['code' => 'ENROLLMENT', 'label' => 'Enrollment payment', 'amount' => '1500.00'],
            ['code' => 'LATER', 'label' => 'Later term payment', 'amount' => '4500.00'],
        ]);
        app(IssueRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        if ($state !== 'assessment') {
            app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        }
        if ($state === 'review') {
            PaymentEvidenceVersion::factory()->create(['term_account_id' => $case->fresh()->termAccount->id,
                'assessment_id' => $assessment->id, 'state' => PaymentEvidenceVersion::StateSubmitted, 'submitted_by' => $application->user_id]);
        } elseif ($state === 'assessment') {
            $assessment->update(['state' => Assessment::StateSuperseded]);
        } elseif (in_array($state, ['seats', 'shortage'], true)) {
            $case->seatReservations()->update(['status' => EnrollmentSeatReservation::StatusReleased, 'released_at' => now()]);
            if ($state === 'shortage') {
                $section->update(['capacity' => 1]);
                EnrollmentSeatReservation::query()->create(['enrollment_id' => Enrollment::factory()->create(['term_id' => $term->id])->id,
                    'section_id' => $section->id, 'registrar_user_id' => $registrar->id, 'reserved_at' => now(), 'lock_version' => 1,
                    'published_timetable_version_id' => $proposal->published_timetable_version_id,
                    'status' => EnrollmentSeatReservation::StatusActive, 'deadline' => now()->addDay()]);
            }
        } elseif ($state === 'expired') {
            $case->seatReservations()->update(['deadline' => now()->subMinute()]);
        }
        $assessmentBefore = $assessment->fresh()->getAttributes();
        $reservationBefore = $case->seatReservations()->orderBy('id')->get()->map->getAttributes()->all();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee($heading)->assertSee('Enrollment history')->assertSee('Proposed schedule')
            ->assertDontSee('Confirmation and finalization')->assertDontSee('Case actions and recovery')
            ->assertActionHidden('finalizeOfficialEnrollment');
        if ($state === 'assessment') {
            $component->assertSee('Current assessment unavailable')->assertDontSee('PHP 0.00')->assertDontSee('PHP 1,500.00')->assertDontSee('PHP 6,000.00');
        } else {
            $component->assertSee('PHP 1,500.00')->assertSee('PHP 6,000.00');
        }
        if (in_array($state, ['expired', 'seats', 'shortage'], true)) {
            $component->assertDontSee('Seats held until');
        }
        if ($state === 'shortage') {
            $component->assertSee('No available seats.');
        }
        $component->set('enrollmentView', 'history')->assertSee('Supporting records');
        $this->assertSame($assessmentBefore, $assessment->fresh()->getAttributes());
        $this->assertSame($reservationBefore, $case->seatReservations()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertNull($case->fresh()->current_cor_version_id);
    }

    public function test_legacy_draft_send_reviews_the_exact_subjects_without_a_routine_input_or_financial_effect(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $issuer = app(IssueRegistrationProposal::class);
        $this->mock(IssueRegistrationProposal::class)->shouldReceive('issuePrepared')->once()->andReturnUsing(fn (RegistrationProposalVersion $prepared): RegistrationProposalVersion => $prepared);
        $assessment = $this->recordIndividualAssessment($case->fresh(), $this->staff(User::StaffRoleAccounting), 'SYN-LEGACY-DRAFT-SOURCE', [
            ['code' => 'ENROLLMENT', 'label' => 'Enrollment payment', 'amount' => '1500.00'],
        ]);
        $this->app->instance(IssueRegistrationProposal::class, $issuer);
        $before = $assessment->fresh()->getAttributes();
        $this->assertSame(RegistrationProposalVersion::StateDraft, $proposal->fresh()->state);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $send = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Send subjects')->assertActionHidden('finalizeOfficialEnrollment');
        $this->assertSame($proposal->id, $send->get('reviewedProposalVersionId'));
        $send->callAction('issueProposal')->assertHasNoActionErrors()->assertNotified('Subjects sent for confirmation');

        $this->assertSame(RegistrationProposalVersion::StateIssued, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->confirmation);
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertSame(0, $case->courseEnrollments()->count());
        $this->assertSame($before, $assessment->fresh()->getAttributes());
    }

    public function test_learner_self_cancellation_stops_at_confirmation_and_registrar_releases_capacity(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        try {
            app(CancelRegistrationCase::class)->execute($case->fresh(), $application->user, 'Too late for self-cancellation.');
            $this->fail('A learner must not self-cancel after confirming the proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }

        $account = $case->fresh()->termAccount;
        $payment = Payment::factory()->create(['student_profile_id' => null, 'term_account_id' => $account->id, 'term_id' => $term->id]);
        $paymentBefore = $payment->fresh()->getAttributes();
        $caseBefore = $case->fresh()->getAttributes();
        $reservationBefore = $proposal->items()->firstOrFail()->reservation()->firstOrFail()->getAttributes();
        try {
            app(CancelRegistrationCase::class)->execute($case->fresh(), $registrar, '   ');
            $this->fail('Registrar cancellation must record a reason.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Enter the reason for cancelling this enrollment.'], $exception->errors()['reason']);
        }
        $this->assertSame($caseBefore, $case->fresh()->getAttributes());
        $this->assertSame($reservationBefore, $proposal->items()->firstOrFail()->reservation()->firstOrFail()->getAttributes());
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->mountAction('cancelCase')->assertActionMounted('cancelCase')
            ->assertMountedActionModalSee(['Cancellation does not issue a refund.', 'Keep enrollment'])
            ->assertMountedActionModalDontSee('Cancellation approval reference');
        $case->increment('lock_version');
        $component->setActionData(['reason' => 'Stale cancellation.', 'authority_reference' => 'SYN-CANCEL-STALE'])
            ->callMountedAction()->assertNotified('Registration Case was not cancelled');
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertSame($reservationBefore, $proposal->items()->firstOrFail()->reservation()->firstOrFail()->getAttributes());
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->callAction('cancelCase', ['reason' => 'Registrar recorded post-confirmation cancellation.', 'authority_reference' => 'UNREQUESTED-FIELD'])
            ->assertHasNoActionErrors()->assertNotified('Registration cancelled');
        $cancelled = $case->fresh();
        $this->assertSame(Enrollment::OutcomeCancelledByRegistrar, $cancelled->canonical_outcome);
        $event = $cancelled->registrationEvents()->where('event_type', Enrollment::OutcomeCancelledByRegistrar)->sole();
        $this->assertNull($event->authority_reference);
        $this->assertSame('Registrar recorded post-confirmation cancellation.', $event->reason);
        $this->assertSame($registrar->id, $event->actor_id);
        $this->assertNotNull($event->recorded_at);
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertSame(
            EnrollmentSeatReservation::StatusReleased,
            $proposal->items()->firstOrFail()->reservation()->value('status'),
        );
    }

    public function test_learner_payment_form_accepts_manila_time_and_stores_the_exact_utc_instant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 18:10:00', 'UTC'));
        [$application, $term] = $this->readyApplicant();
        $this->publishedOffering($application, $term);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $plan = $this->createFeePlanDraft($application->program, $term, [
            ['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'amount' => '1000.00'],
        ], $accounting);
        $this->publishFeePlan($plan, $accounting, 'Synthetic Manila-time payment authority');
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        app(ConfirmRegistrationProposal::class)->execute($case->currentProposalVersion, $application->user);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)
            ->assertSee('Complete your enrollment payment')
            ->callAction('submitPaymentEvidence', data: [
                'evidence' => UploadedFile::fake()->image('manila-receipt.jpg'),
                'claimed_amount' => '1000.00',
                'channel' => 'bank_transfer',
                'paid_at' => '2026-10-18 02:09',
                'payment_reference' => 'SYN-MANILA-TIME',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Evidence submitted for Accounting review')
            ->assertSee('Payment proof awaiting review')->assertSee('View submitted proof')
            ->assertDontSee('Complete your enrollment payment')->assertDontSee('Pay online');

        $evidence = $case->termAccount->paymentEvidenceVersions()->sole();
        $this->assertSame('2026-10-17 18:09:00', $evidence->paid_at->utc()->toDateTimeString());
        $this->assertSame('Submitted', $evidence->state);
        $this->assertSame(0, $case->termAccount->payments()->count());
    }

    public function test_applicant_can_cancel_an_unconfirmed_case_from_the_owned_workspace(): void
    {
        [$application, $term] = $this->readyApplicant();
        app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($application->user->fresh())
            ->test(ApplicantDashboard::class)
            ->assertActionVisible('openEnrollment');

        Livewire::actingAs($application->user->fresh())
            ->test(ApplicantEnrollment::class)
            ->assertActionVisible('cancelRegistration')
            ->callAction('cancelRegistration')
            ->assertNotified('Registration case cancelled');

        $this->assertDatabaseHas('enrollments', [
            'credential_user_id' => $application->user_id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeCancelledByLearner,
        ]);
    }

    public function test_overlapping_current_timetable_placement_fails_without_partial_reservations(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $entry = $section->termOffering->curriculumEntry;
        $otherEntry = CurriculumEntry::factory()->for($entry->curriculumVersion)->create();
        $otherOffering = TermOffering::factory()->for($term)->for($otherEntry)->create(['state' => TermOffering::StateScheduled]);
        $otherSection = Section::factory()->for($otherOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $otherSection->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
        ]);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $case,
            $registrar,
            [$section->id, $otherSection->id],
            $case->lock_version,
        );

        try {
            app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
            $this->fail('Overlapping published meetings must fail atomically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conflict', $exception->errors());
        }

        $this->assertSame(0, $case->seatReservations()->count());
    }

    public function test_full_capacity_placement_fails_without_partial_reservations(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $section->update(['capacity' => 1]);
        CourseEnrollment::factory()->create([
            'section_id' => $section->id,
            'is_current' => true,
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $case,
            $registrar,
            [$section->id],
            $case->lock_version,
        );

        try {
            app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
            $this->fail('Full capacity section must fail placement atomically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }

        $this->assertSame(0, $case->seatReservations()->count());
    }

    public function test_competing_placement_collision_for_last_capacity_seat_fails_atomically_without_partial_reservations(): void
    {
        [$application1, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application1, $term);
        $section->update(['capacity' => 1]);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        // Two Draft selections compete for the same final seat before confirmation.
        $case1 = app(StartRegistrationCase::class)->forReadyApplicant($application1, $term, $application1->user);
        $proposal1 = app(PrepareRegistrationProposal::class)->execute($case1, $registrar, [$section->id], $case1->lock_version);

        // Prepare the competing Draft before either explicit placement attempt.
        [$application2] = $this->readyApplicant(AdmissionApplication::PathFirstYear, $term, $application1->program_id);
        $case2 = app(StartRegistrationCase::class)->forReadyApplicant($application2->fresh(), $term, $application2->user);
        $proposal2 = app(PrepareRegistrationProposal::class)->execute($case2, $registrar, [$section->id], $case2->lock_version);

        // Case 1 places first -> successfully claims the last seat
        app(PlaceRegistrationProposal::class)->execute($proposal1->fresh(), $registrar);
        $this->assertSame(1, $case1->seatReservations()->where('status', EnrollmentSeatReservation::StatusActive)->count());

        // Case 2 attempts to place -> collision on section capacity fails atomically
        try {
            app(PlaceRegistrationProposal::class)->execute($proposal2->fresh(), $registrar);
            $this->fail('Competing placement exceeding section capacity must fail atomically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }

        // Case 2 has zero orphaned seat holds
        $this->assertSame(0, $case2->seatReservations()->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->count());
    }

    public function test_stale_or_superseded_proposal_placement_fails_atomically(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section1, $timetable] = $this->publishedOffering($application, $term);
        $section2 = Section::factory()->for($section1->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section2->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal1 = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section1->id], $case->lock_version);

        // Case prepares a successor proposal with section2, advancing lock_version and changing current_proposal_version_id
        app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$section2->id], $case->fresh()->lock_version);

        // Attempting to place stale proposal1 fails atomically
        try {
            app(PlaceRegistrationProposal::class)->execute($proposal1->fresh(), $registrar);
            $this->fail('Stale or superseded proposal placement must fail atomically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proposal', $exception->errors());
        }

        $this->assertSame(0, $case->seatReservations()->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->count());
    }

    public function test_registration_case_backfill_is_idempotent_and_stops_on_ambiguous_legacy_status(): void
    {
        $legacy = Enrollment::factory()->create([
            'status' => 'pending_review',
            'student_type' => 'regular',
        ]);
        DB::table('enrollments')->where('id', $legacy->id)->update([
            'credential_user_id' => null,
            'admission_application_id' => null,
            'case_reference' => null,
            'selection_basis' => null,
            'canonical_outcome' => null,
        ]);
        $migration = require database_path('migrations/2026_08_19_144604_backfill_canonical_registration_case_fields.php');
        $migration->up();
        $migration->up();

        $legacy->refresh();
        $this->assertSame($legacy->studentProfile->user_id, $legacy->credential_user_id);
        $this->assertSame(Enrollment::SelectionStandardCurriculum, $legacy->selection_basis);
        $this->assertSame(Enrollment::OutcomeInProgress, $legacy->canonical_outcome);
        $this->assertSame(sprintf('REG-LEGACY-%08d', $legacy->id), $legacy->case_reference);

        $ambiguous = Enrollment::factory()->create([
            'status' => 'withdrawn',
            'student_type' => 'regular',
        ]);
        DB::table('enrollments')->where('id', $ambiguous->id)->update([
            'credential_user_id' => null,
            'case_reference' => null,
            'selection_basis' => null,
            'canonical_outcome' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $migration->up();
    }

    public function test_student_number_allocation_is_unique_across_two_first_finalizations(): void
    {
        [$first] = $this->officialEnrollment();
        [$second] = $this->officialEnrollment();

        $this->assertNotSame($first->studentProfile->student_number, $second->studentProfile->student_number);
        $this->assertSame(2, StudentProfile::query()
            ->whereIn('id', [$first->student_profile_id, $second->student_profile_id])
            ->distinct('student_number')
            ->count('student_number'));
    }

    public function test_continuing_student_uses_the_same_exact_term_case_with_controlled_selection_and_late_authority(): void
    {
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingIrregular]);
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->openTermCalendarPackage($term);

        try {
            app(StartRegistrationCase::class)->forContinuingStudent(
                $profile,
                $term,
                $profile->user,
                'RegistrarAssisted',
                'FORGED-ASSISTED-START',
            );
            $this->fail('A learner cannot forge a Registrar-assisted start method.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Enrollment::query()->where('credential_user_id', $profile->user_id)->count());
        }
        $case = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $term,
            $registrar,
            'RegistrarAssisted',
            'SYN-ASSISTED-001',
        );
        $same = app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $profile->user);

        $this->assertTrue($case->is($same));
        $this->assertSame($profile->id, $case->student_profile_id);
        $this->assertSame(Enrollment::SelectionStandardCurriculum, $case->selection_basis);
        $this->assertSame('RegistrarAssisted', $case->start_method);
        $this->assertSame(1, StudentProfile::query()->where('user_id', $profile->user_id)->count());

        $lateTerm = Term::factory()->create(['state' => Term::StateActive]);
        $late = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $lateTerm,
            $registrar,
            'LateAuthority',
            'SYN-LATE-001',
        );

        $this->assertSame('LateAuthority', $late->start_method);
        $this->assertSame(2, Enrollment::query()->where('credential_user_id', $profile->user_id)->count());
    }

    public function test_transfer_evaluation_requires_owned_current_approved_sources_and_replaces_without_erasing_history(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section, , $curriculum] = $this->publishedOffering($application, $term);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
            $this->fail('A transferee must not prepare subjects before evaluation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evaluation', $exception->errors());
            $this->assertSame(0, $case->proposalVersions()->count());
        }
        $record = app(RecordRegistrationCreditEvaluation::class);
        $submission = (int) $application->current_submission_version_id;
        $args = [$case, $registrar, [], 'SYN-EVALUATION', CarbonImmutable::today(), $submission, $curriculum->id, $case->lock_version];
        foreach (['foreign entry', 'duplicate entry', 'foreign curriculum', 'stale submission', 'future approval', 'inactive actor', 'wrong role'] as $failure) {
            $values = $args;
            if ($failure === 'foreign entry') {
                $values[2] = [CurriculumEntry::factory()->create()->id];
            } elseif ($failure === 'duplicate entry') {
                $values[2] = [$section->termOffering->curriculum_entry_id, $section->termOffering->curriculum_entry_id];
            } elseif ($failure === 'foreign curriculum') {
                $values[6] = CurriculumVersion::factory()->create()->id;
            } elseif ($failure === 'stale submission') {
                $values[5] = $submission + 1;
            } elseif ($failure === 'future approval') {
                $values[4] = CarbonImmutable::tomorrow();
            } elseif ($failure === 'inactive actor') {
                $values[1] = $this->staff(User::StaffRoleRegistrar);
                $values[1]->update(['status' => User::StatusInactive]);
            } else {
                $values[1] = $this->staff(User::StaffRoleAccounting);
            }
            try {
                $record->execute(...$values);
                $this->fail('Invalid evaluation accepted: '.$failure);
            } catch (ValidationException|AuthorizationException) {
                $this->assertSame(0, RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->count());
            }
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Record transfer credit evaluation')->assertDontSee('Choose subjects')->assertDontSee('Enrollment options');
        $component->mountAction('recordAcademicEvaluation')->assertActionMounted('recordAcademicEvaluation');
        $component->setActionData(['curriculum_version_id' => $curriculum->id, 'result' => 'none', 'authority_reference' => 'SYN-EVALUATION', 'approved_on' => today()->toDateString()]);
        $component->callMountedAction()->assertHasNoActionErrors()->assertNotified('Academic evaluation recorded');
        $first = RegistrationCreditEvaluation::latestCurrentFor($case->fresh(), $curriculum->id);
        $this->assertNotNull($first);
        $this->assertSame([], $first->credited_curriculum_entry_ids);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->assertSame($first->id, data_get($proposal->source_snapshot, 'credit_evaluation_id'));
        $args[0] = $case->fresh();
        $args[7] = $case->fresh()->lock_version;
        $args[8] = $first->id;
        $second = $record->execute(...$args);
        $this->assertNotSame($first->id, $second->id);
        try {
            app(RegistrationReadinessQuery::class)->assertSourcesCurrent($case->fresh(), $proposal);
            $this->fail('A proposal bound to a replaced evaluation must not proceed.');
        } catch (ValidationException) {
            $this->assertSame(2, RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->count());
            $this->assertSame(0, $case->corVersions()->count());
        }
        try {
            $record->execute(...$args);
            $this->fail('A second open form must not overwrite the new evaluation.');
        } catch (ValidationException) {
            $this->assertSame($second->id, RegistrationCreditEvaluation::latestCurrentFor($case->fresh(), $curriculum->id)->id);
        }
        $this->assertFalse($application->user->fresh()->hasRole('student'));
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Review subject eligibility')->assertSee('Revise subjects')
            ->assertActionHidden('finalizeOfficialEnrollment')
            ->set('enrollmentView', 'history')->assertSee('Academic evaluations')->assertSee('SYN-EVALUATION');
    }

    public static function continuingCircumstances(): array
    {
        return ['continuing' => ['continuing'], 'reactivated returnee' => ['returnee'], 'effective program shift' => ['shift']];
    }

    #[DataProvider('continuingCircumstances')]
    public function test_continuing_student_finalization_reuses_the_permanent_identity_and_number(string $circumstance): void
    {
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingIrregular]);
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $studentNumber = $profile->student_number;
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            ...($circumstance === 'shift' ? ['starts_on' => today()->addMonth(), 'ends_on' => today()->addMonths(5)] : []),
        ]);
        $originalProgramId = $profile->program_id;
        $originalCurriculumId = $profile->curriculum_version_id;
        $creditedEntry = null;
        if ($circumstance !== 'continuing') {
            $type = $circumstance === 'returnee' ? StudentLifecycleChange::TypeReactivation : StudentLifecycleChange::TypeProgramShift;
            CalendarEvent::factory()->create([
                'term_id' => $term->id,
                'process_key' => $circumstance === 'returnee' ? 'reactivation' : 'program_shift',
                'start_at' => now()->subDay(), 'end_at' => now()->addDay(),
            ]);
            $data = [
                'student_profile_id' => $profile->id, 'term_id' => $term->id, 'type' => $type,
                'effective_on' => $term->starts_on->toDateString(), 'decided_on' => today()->toDateString(),
                'authority' => 'Recorded institutional approval', 'reason' => 'Approved '.$circumstance.' outcome.',
            ];
            if ($circumstance === 'returnee') {
                $profile->update(['lifecycle_status' => StudentProfile::LifecycleArchived, 'archived_at' => now()]);
                try {
                    app(StartRegistrationCase::class)->forContinuingStudent($profile->fresh(), $term, $registrar, 'RegistrarAssisted');
                    $this->fail('An archived learner must not start before approved reactivation.');
                } catch (ValidationException) {
                    $this->assertSame(0, Enrollment::query()->where('student_profile_id', $profile->id)->count());
                }
            } else {
                $targetProgram = Program::factory()->create();
                $targetCurriculum = CurriculumVersion::factory()->for($targetProgram)->create(['state' => CurriculumVersion::StateActive]);
                $creditedEntry = CurriculumEntry::factory()->for($targetCurriculum)->create();
                $data += [
                    'target_program_id' => $targetProgram->id, 'target_curriculum_version_id' => $targetCurriculum->id,
                    'credit_entries' => [[
                        'curriculum_entry_id' => $creditedEntry->id,
                        'source_course_id' => $creditedEntry->courseSpecification->course_id,
                        'treatment' => ProgramShiftCreditEntry::TreatmentAccepted, 'numeric_grade' => '2.00',
                    ]],
                ];
            }
            $change = app(StudentLifecycleService::class)->record($data, $registrar);
            $this->assertSame(0, Enrollment::query()->where('student_profile_id', $profile->id)->count());
            if ($circumstance === 'shift') {
                $this->assertSame($originalProgramId, $profile->fresh()->program_id);
                $this->assertSame($originalCurriculumId, $profile->fresh()->curriculum_version_id);
                try {
                    app(StudentLifecycleService::class)->applyProgramShift($change, $registrar);
                    $this->fail('A future shift must not be applied before its effective term.');
                } catch (RuntimeException) {
                    $this->assertSame(StudentLifecycleChange::StateRecordedApproved, $change->fresh()->state);
                }
                $this->travelTo($term->starts_on->copy()->addDay());
                app(StudentLifecycleService::class)->applyProgramShift($change, $registrar);
                $this->assertSame($targetProgram->id, $profile->fresh()->program_id);
                $this->assertSame($targetCurriculum->id, $profile->fresh()->curriculum_version_id);
            }
            $profile->refresh();
            $this->assertSame(StudentProfile::LifecycleActive, $profile->lifecycle_status);
        }
        $this->openTermCalendarPackage($term);
        [$section] = $this->publishedOfferingForProgram($profile->program, $term, $profile->curriculumVersion);
        if ($creditedEntry !== null) {
            CourseRequirement::factory()->create([
                'course_specification_id' => $section->termOffering->curriculumEntry->course_specification_id,
                'related_course_id' => $creditedEntry->courseSpecification->course_id,
                'minimum_grade' => '3.00', 'accepts_transfer_credit' => false,
            ]);
        }
        $case = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $term,
            $registrar,
            'RegistrarAssisted',
            'SYN-CONTINUING-001',
        );
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft(
            $profile->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic continuing Student fee authority');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute(
            $proposal->fresh(),
            $profile->user,
            $registrar,
            'SYN-ASSISTED-CONFIRMATION-001',
        );
        $this->assertSame('SYN-ASSISTED-CONFIRMATION-001', $proposal->confirmation()->sole()->assisted_evidence_reference);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $again = app(FinalizeOfficialEnrollment::class)->execute($official, $registrar);

        $this->assertTrue($official->is($again));
        $this->assertSame($profile->id, $official->student_profile_id);
        $this->assertSame($studentNumber, $official->studentProfile->student_number);
        $this->assertSame(1, StudentProfile::query()->where('user_id', $profile->user_id)->count());
        $this->assertSame(1, $official->courseEnrollments()->where('is_current', true)->count());
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertSame($profile->curriculum_version_id, $proposal->curriculum_version_id);
        $this->assertSame($profile->program_id, $official->studentProfile->program_id);
        $cor = app(BuildCorOutput::class)->forEnrollment($official, $profile->user);
        $this->assertSame('Available', $cor['state']['availability_status']);
        $this->assertSame($section->id, $official->courseEnrollments()->where('is_current', true)->sole()->section_id);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($profile->user->fresh())->test(StudentEnrollmentPage::class)
            ->assertSeeHtml('href="'.ScheduleView::getUrl(panel: 'student').'"')
            ->assertSee('1 subject');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->set('enrollmentView', 'history')->assertSee('Confirmation reference: SYN-ASSISTED-CONFIRMATION-001');
    }

    public function test_continuing_proposal_uses_the_recorded_curriculum_and_released_result_rules(): void
    {
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingIrregular]);
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->openTermCalendarPackage($term);
        [$section, $timetable] = $this->publishedOfferingForProgram($profile->program, $term, $profile->curriculumVersion);
        CourseRequirement::factory()->create([
            'course_specification_id' => $section->termOffering->curriculumEntry->course_specification_id,
            'minimum_grade' => '3.0000',
        ]);
        $case = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $term,
            $registrar,
            'RegistrarAssisted',
            'SYN-ELIGIBILITY-001',
        );

        $otherCurriculum = CurriculumVersion::factory()->create(['program_id' => $profile->program_id, 'state' => CurriculumVersion::StateActive]);
        $otherEntry = CurriculumEntry::factory()->for($otherCurriculum)->create();
        $otherOffering = TermOffering::factory()->for($term)->for($otherEntry)->create(['state' => TermOffering::StateScheduled]);
        $otherSection = Section::factory()->for($otherOffering, 'termOffering')->create(['state' => Section::StateOpen]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $otherSection->id]);
        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$otherSection->id], $case->lock_version);
            $this->fail('A published class from another Curriculum Version must not replace the recorded curriculum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections', $exception->errors());
            $this->assertSame(0, $case->proposalVersions()->count());
        }

        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
            $this->fail('A missing released prerequisite result must block the proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections', $exception->errors());
        }

        $this->assertSame(0, $case->proposalVersions()->count());
    }

    public function test_failed_finalization_creates_no_partial_student_registration_cor_or_number(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->designatedFaculty($section, $registrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);

        try {
            app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
            $this->fail('Finalization without placement and assessment authority must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        $this->assertSame(0, StudentProfile::query()->where('user_id', $application->user_id)->count());
        $this->assertFalse($application->user->fresh()->hasRole('student'));
        $this->assertSame(0, CourseEnrollment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(0, $case->corVersions()->count());
        $this->assertDatabaseCount('student_number_sequences', 0);
        $this->assertSame(0, GradeRoster::query()->where('section_id', $section->id)->count());
        $this->assertSame(0, GradeRosterRow::query()->count());
    }

    public function test_assessment_requires_current_reserved_placement_and_individual_authority(): void
    {
        [$application, $term] = $this->readyApplicant();
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'FIXED', 'label' => 'Fixed exact-Term charge', 'amount' => '100.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic ordinary authority');

        try {
            app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
            $this->fail('An unplaced proposal must not produce an assessment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('placement', $exception->errors());
        }
        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());

        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        try {
            $this->recordIndividualAssessment(
                $case->fresh(),
                $accounting,
                'SYN-UNAUTHORIZED-INDIVIDUAL-001',
                [['code' => 'BYPASS', 'label' => 'Must not bypass the ordinary plan', 'amount' => '0.00']],
            );
            $this->fail('A Standard Curriculum case must use its Published Fee Plan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }

        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());
    }

    public function test_unresolved_source_impact_and_stale_placement_block_atomic_finalization(): void
    {
        [$case, $application, , $registrar] = $this->caseReadyForFinalization();
        $opened = app(RecordRegistrationSourceImpactReview::class)->open(
            $case,
            $registrar,
            RecordRegistrationSourceImpactReview::SourceAcademicResult,
            'released-result:SYN-001',
            'A released result changed after proposal confirmation.',
        );

        $this->assertFalse(app(RegistrationReadinessQuery::class)->for($case->fresh())['eligibility']);
        try {
            app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
            $this->fail('An unresolved source-impact review must block finalization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }
        $this->assertSame(0, StudentProfile::query()->where('user_id', $application->user_id)->count());

        app(RecordRegistrationSourceImpactReview::class)->resolve(
            $case,
            $opened,
            $registrar,
            'Registrar revalidated the same authoritative proposal.',
        );
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);

        [$staleCase, $staleApplication, $staleSection, $staleRegistrar] = $this->caseReadyForFinalization();
        $staleSection->update(['state' => Section::StateClosed]);
        try {
            app(FinalizeOfficialEnrollment::class)->execute($staleCase->fresh(), $staleRegistrar);
            $this->fail('A section change after placement must fail finalization atomically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('placement', $exception->errors());
        }
        $this->assertSame(0, StudentProfile::query()->where('user_id', $staleApplication->user_id)->count());
        $this->assertSame(0, $staleCase->courseEnrollments()->count());
        $this->assertSame(0, $staleCase->corVersions()->count());
        $this->assertSame(Section::StateClosed, $staleSection->fresh()->state);
    }

    public function test_registrar_confirmation_keeps_the_reviewed_subject_version_when_a_successor_is_issued(): void
    {
        $this->freezeTime();
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $first = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($first, $registrar);
        app(IssueRegistrationProposal::class)->execute($first->fresh(), $registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->assertSee('Waiting for confirmation')
            ->assertDontSee('Five finalization checkpoints')
            ->set('enrollmentView', 'history')
            ->assertSee('Enrollment started')
            ->assertSee('Supporting records')
            ->assertDontSee('Five finalization checkpoints')
            ->set('enrollmentView', 'overview')
            ->mountAction('recordAssistedConfirmation')
            ->assertActionMounted('recordAssistedConfirmation')
            ->assertActionDataSet(['proposal_id' => $first->id])
            ->assertMountedActionModalSee(['Keep for review', 'enrollment becomes official only after Registrar finalization.'])
            ->assertMountedActionModalDontSee('Confirmation reference');

        $replacement = Section::factory()->for($section->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);
        $second = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$replacement->id], $case->fresh()->lock_version);
        $this->assertNotSame($first->id, $second->id);
        $this->reserveAndAssessNoPaymentProposal($second, $registrar);
        app(IssueRegistrationProposal::class)->execute($second->fresh(), $registrar);

        $component->setActionData(['evidence_reference' => 'SYN-REVIEWED-FIRST-VERSION'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Confirmation was not recorded');
        $this->assertFalse($first->confirmation()->exists());
        $this->assertFalse($second->confirmation()->exists());

        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])
            ->callAction('recordAssistedConfirmation', ['evidence_reference' => 'UNREQUESTED-FIELD'])
            ->assertHasNoActionErrors()
            ->assertNotified('Assisted confirmation recorded');
        $this->assertFalse($first->confirmation()->exists());
        $this->assertTrue($second->confirmation()->exists());
        $confirmation = $second->confirmation()->sole();
        $this->assertNull($confirmation->assisted_evidence_reference);
        $this->assertSame(RegistrationProposalConfirmation::MethodRegistrarAssisted, $confirmation->method);
        $this->assertSame($registrar->id, $confirmation->assisted_by);
        $this->assertSame($application->user_id, $confirmation->learner_user_id);
        $this->assertSame($second->id, $confirmation->registration_proposal_version_id);
        $this->assertSame(now()->toDateTimeString(), $confirmation->confirmed_at->toDateTimeString());
        $this->assertNull($case->fresh()->student_profile_id);
    }

    public function test_finalization_rejects_a_stale_dialog_proposal_and_accepts_the_reviewed_current_proposal_idempotently(): void
    {
        [$case, $application, $section, $registrar] = $this->caseReadyForFinalization();
        $reviewedProposalId = $case->current_proposal_version_id;
        $replacement = Section::factory()->for($section->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen, 'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($case->currentProposalVersion->timetableVersion, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);
        $successor = app(PrepareRegistrationProposal::class)->execute(
            $case->fresh(), $registrar, [$replacement->id], $case->fresh()->lock_version,
        );
        app(PlaceRegistrationProposal::class)->execute($successor->fresh(), $registrar);
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $this->staff(User::StaffRoleAccounting));
        app(IssueRegistrationProposal::class)->execute($successor->fresh(), $registrar);
        app(ConfirmRegistrationProposal::class)->execute($successor->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($successor->fresh(), $registrar);
        $this->assertTrue(app(RegistrationReadinessQuery::class)->for($case->fresh())['ready']);
        $before = $case->fresh()->getAttributes();

        try {
            app(FinalizeOfficialEnrollment::class)->execute($case, $registrar, expectedProposalVersionId: $reviewedProposalId);
            $this->fail('The decision must apply to the proposal whose subjects were reviewed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proposal', $exception->errors());
        }

        $this->assertSame($before, $case->fresh()->getAttributes());
        $this->assertSame(0, $case->courseEnrollments()->count());
        $this->assertSame(0, $case->corVersions()->count());
        $this->assertSame(0, StudentProfile::query()->where('user_id', $application->user_id)->count());
        $this->assertSame(EnrollmentSeatReservation::StatusActive, $successor->items()->sole()->reservation->status);

        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar, expectedProposalVersionId: $successor->id);
        $again = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar, expectedProposalVersionId: $successor->id);

        $this->assertSame($official->id, $again->id);
        $this->assertSame($official->current_cor_version_id, $again->current_cor_version_id);
        $this->assertSame($successor->id, $official->currentCorVersion->registration_proposal_version_id);
        $this->assertSame($replacement->id, $official->courseEnrollments()->sole()->section_id);
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertSame(1, StudentProfile::query()->where('user_id', $application->user_id)->count());
    }

    public function test_proposal_supersession_preserves_source_and_confirmation_history(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $application->user,
        );
        $this->recordApprovedTransferEvaluation($case);
        $first = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($first->fresh(), $registrar);
        $assessment = $this->recordIndividualAssessment(
            $case->fresh(),
            $accounting,
            'SYN-SUPERSEDED-ASSESSMENT-001',
            [['code' => 'AUTH', 'label' => 'Superseded obligation', 'amount' => '0.00']],
        );

        app(IssueRegistrationProposal::class)->execute($first, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($first->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($first->fresh(), $registrar);
        $reservation = $first->items()->firstOrFail()->reservation()->firstOrFail();
        $firstHash = $first->fresh()->content_hash;
        $replacement = Section::factory()->for($section->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);

        $second = app(PrepareRegistrationProposal::class)->execute(
            $case->fresh(),
            $registrar,
            [$replacement->id],
            $case->fresh()->lock_version,
        );

        $this->assertSame(RegistrationProposalVersion::StateSuperseded, $first->fresh()->state);
        $this->assertSame($first->id, $second->supersedes_version_id);
        $this->assertSame(2, $second->version);
        $this->assertNotNull($first->confirmation()->first());
        $this->assertSame($firstHash, $first->fresh()->content_hash);
        $this->assertSame(EnrollmentSeatReservation::StatusReleased, $reservation->fresh()->status);
        $this->assertSame(Assessment::StateSuperseded, $assessment->fresh()->state);
        $this->assertSame('Unavailable', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);

        $this->expectException(LogicException::class);
        $first->fresh()->update(['content_hash' => hash('sha256', 'rewritten')]);
    }

    public function test_expiry_releases_capacity_once_and_returns_the_case_to_actionable_placement(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $reservation = $proposal->items()->firstOrFail()->reservation()->firstOrFail();
        $reservation->update(['deadline' => now()->subMinute()]);

        $first = app(ReleaseExpiredEnrollmentReservations::class)->execute();
        $second = app(ReleaseExpiredEnrollmentReservations::class)->execute();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertFalse(app(RegistrationReadinessQuery::class)->for($case->fresh())['placement']);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeRegistrationCaseExpiryEmail)->count());
    }

    public function test_final_cutoff_records_not_enrolled_once_and_preserves_the_same_case_for_authorized_reopening(): void
    {
        [$application, $term] = $this->readyApplicant();
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        TermCalendarWindow::query()
            ->whereHas('package', fn ($query) => $query
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateActive))
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->update(['closes_on' => now()->subDay()->toDateString()]);

        app(ReleaseExpiredEnrollmentReservations::class)->execute();
        app(ReleaseExpiredEnrollmentReservations::class)->execute();

        $this->assertSame(Enrollment::OutcomeNotEnrolled, $case->fresh()->canonical_outcome);
        $this->assertSame('not_enrolled', $case->fresh()->status);
        $this->assertSame(1, $case->registrationEvents()->where('event_type', Enrollment::OutcomeNotEnrolled)->count());
        $this->assertSame(1, OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeRegistrationCaseExpiryEmail)
            ->where('related_record_type', Enrollment::class)
            ->where('related_record_id', $case->id)
            ->count());
    }

    public function test_capacity_shortage_saves_no_partial_placement_and_recovers_through_a_successor_proposal(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$fullSection, $timetable] = $this->publishedOffering($application, $term);
        $fullSection->update(['capacity' => 1]);
        CourseEnrollment::factory()->create([
            'enrollment_id' => Enrollment::factory()->create(['term_id' => $term->id])->id,
            'term_offering_id' => $fullSection->term_offering_id,
            'section_id' => $fullSection->id,
            'published_timetable_version_id' => $timetable->id,
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$fullSection->id], $case->lock_version);

        try {
            app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
            $this->fail('A full section must not create a partial reservation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                "{$fullSection->code} has no remaining capacity. Registrar must choose a replacement.",
                $exception->errors()['capacity'][0],
            );
        }

        $this->assertSame(0, EnrollmentSeatReservation::query()->where('enrollment_id', $case->id)->count());
        $this->assertContains('Complete current placement', app(RegistrationReadinessQuery::class)->for($case->fresh())['blockers']);

        $replacement = Section::factory()->for($fullSection->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 1,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);
        $successor = app(PrepareRegistrationProposal::class)->execute(
            $case->fresh(),
            $registrar,
            [$replacement->id],
            $case->fresh()->lock_version,
        );
        $this->reserveAndAssessNoPaymentProposal($successor, $registrar);
        app(IssueRegistrationProposal::class)->execute($successor, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($successor->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($successor->fresh(), $registrar);

        $this->assertSame(RegistrationProposalVersion::StateSuperseded, $proposal->fresh()->state);
        $this->assertSame($replacement->id, $successor->fresh()->items()->firstOrFail()->reservation()->value('section_id'));
        $this->assertTrue(app(RegistrationReadinessQuery::class)->for($case->fresh())['placement']);
    }

    public function test_inactive_cross_owner_cross_term_and_stale_actions_are_denied_without_partial_state(): void
    {
        [$application, $term] = $this->readyApplicant();
        $wrongTerm = Term::factory()->create(['state' => Term::StateActive]);
        $other = User::factory()->create(['status' => User::StatusActive]);
        $other->assignRole('applicant');
        $application->user->update(['status' => User::StatusInactive]);

        try {
            app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user->fresh());
            $this->fail('An inactive Applicant must not start registration.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Enrollment::query()->where('credential_user_id', $application->user_id)->count());
        }

        $application->user->update(['status' => User::StatusActive]);

        foreach ([[$application, $term, $other], [$application, $wrongTerm, $application->user->fresh()]] as [$source, $targetTerm, $actor]) {
            try {
                app(StartRegistrationCase::class)->forReadyApplicant($source, $targetTerm, $actor);
                $this->fail('Cross-owner or cross-Term registration must fail closed.');
            } catch (AuthorizationException|ValidationException) {
                $this->assertSame(0, Enrollment::query()->where('credential_user_id', $application->user_id)->count());
            }
        }

        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);

        try {
            app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$section->id], 0);
            $this->fail('A stale case token must not create another proposal.');
        } catch (ValidationException) {
            $this->assertSame(1, $case->proposalVersions()->count());
        }

        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);

        try {
            app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $other);
            $this->fail('A non-owning learner must not confirm the proposal.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $proposal->confirmation()->count());
        }
    }

    public function test_cancelled_case_rejects_late_confirmation_without_partial_state(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(CancelRegistrationCase::class)->execute($case->fresh(), $application->user, 'Learner cancelled before confirmation.');
        $historyCount = $case->registrationEvents()->count();
        $again = app(CancelRegistrationCase::class)->execute($case->fresh(), $application->user, 'Repeated cancellation request.');
        $this->assertSame(Enrollment::OutcomeCancelledByLearner, $again->canonical_outcome);
        $this->assertSame($historyCount, $case->registrationEvents()->count());

        try {
            app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
            $this->fail('A cancelled Registration Case must reject late confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proposal', $exception->errors());
        }

        $this->assertSame(Enrollment::OutcomeCancelledByLearner, $case->fresh()->canonical_outcome);
        $this->assertSame(0, $proposal->confirmation()->count());
    }

    public function test_manual_payment_and_approved_coverage_produce_a_mixed_exact_obligation_clearance(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $application->user,
        );
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $assessment = $this->recordIndividualAssessment(
            $case->fresh(),
            $accounting,
            'SYN-INDIVIDUAL-ASSESSMENT-001',
            [
                ['code' => 'TUITION', 'label' => 'Fixed tuition obligation', 'amount' => '1000.00'],
                ['code' => 'LAB', 'label' => 'Fixed laboratory obligation', 'amount' => '400.00'],
            ],
        );

        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        $tuition = $assessment->obligations->firstWhere('code', 'TUITION');
        app(RecordApprovedCoverage::class)->execute(
            $assessment->termAccount,
            $tuition,
            [
                'category' => ApprovedCoverage::CategoryScholarship,
                'safe_source_description' => 'Synthetic approved scholarship result',
                'amount' => '600.00',
                'authority_reference' => 'SYN-COVERAGE-001',
                'authority_date' => '2026-08-01',
                'effective_date' => '2026-08-01',
            ],
            $accounting,
        );
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $assessment->termAccount,
            $application->user,
            UploadedFile::fake()->image('manual-payment.jpg'),
            '400.00',
            'bank_transfer',
            now()->subMinute(),
            'SYN-MANUAL-PAYMENT-001',
        );

        $download = $this->actingAs($application->user)
            ->get(route('finance.payment-evidence.download', $evidence))
            ->assertOk();
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $download->headers->get('Cache-Control'));
        $otherApplicant = User::factory()->create(['status' => User::StatusActive]);
        $otherApplicant->assignRole('applicant');
        $this->actingAs($otherApplicant)
            ->get(route('finance.payment-evidence.download', $evidence))
            ->assertForbidden();

        app(ReviewPaymentEvidence::class)->verify($evidence, $accounting, '400.00', 'SYN-INDEPENDENT-CHECK-001');

        $projection = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh());
        $this->assertSame('Cleared', $projection['state']);
        $this->assertSame('Mixed', $projection['satisfaction_basis']);
        $this->assertSame('600.00', $projection['coverage_applied']);
        $this->assertSame('400.00', $projection['payment_applied']);
    }

    public function test_official_adjustment_and_drop_create_successors_without_mutating_prior_cor(): void
    {
        [$official, $application, $term, $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true, withDesignatedFaculty: true);
        $sourceCourse = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        $sourceRosterRow = GradeRosterRow::query()->where('course_enrollment_id', $sourceCourse->id)->sole();
        $this->assertTrue($sourceRosterRow->is_current_membership);
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 5,
        ]);
        $replacementAssignment = $this->designatedFaculty($replacement, $registrar);
        $originalHash = $official->currentCorVersion->content_hash;
        $accounting = $this->staff(User::StaffRoleAccounting);
        $adjustmentProposal = $this->confirmedAdjustmentProposal(
            $official,
            $sourceCourse,
            $replacement,
            $registrar,
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $pendingChangePage = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id]);
        $this->assertSame('adjustment', $pendingChangePage->instance()->focusedRegistrarView());
        $pendingChangePage->assertSee('Waiting for Accounting review')->assertActionHidden('placeProposal')->assertActionHidden('adjustRegistration');

        try {
            app(ApplyRegistrationAdjustment::class)->execute(
                $official,
                $adjustmentProposal,
                $registrar,
                'NoAdditionalCost',
                'SYN-ADJUSTMENT-WITHOUT-ACCOUNTING',
            );
            $this->fail('A Registrar label cannot replace attributable Accounting confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_confirmation', $exception->errors());
            $this->assertTrue($sourceCourse->fresh()->is_current);
            $this->assertSame(1, $official->corVersions()->count());
            $this->assertTrue($sourceRosterRow->fresh()->is_current_membership);
            $this->assertSame(0, GradeRoster::query()->where('section_id', $replacement->id)->count());
        }

        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
            $official,
            $sourceCourse,
            $replacement,
            $accounting,
            'SYN-NO-COST-001',
        );

        app(ApplyRegistrationAdjustment::class)->execute(
            $official,
            $adjustmentProposal,
            $registrar,
            'NoAdditionalCost',
            'SYN-ADJUSTMENT-001',
            financialConfirmation: $confirmation,
        );
        $official->refresh();
        $successorCourse = $official->courseEnrollments()
            ->where('supersedes_course_enrollment_id', $sourceCourse->id)
            ->firstOrFail();

        $this->assertFalse($sourceCourse->fresh()->is_current);
        $this->assertFalse($sourceRosterRow->fresh()->is_current_membership);
        $replacementRoster = GradeRoster::query()->where('section_id', $replacement->id)->sole();
        $replacementRosterRow = $replacementRoster->rows()->sole();
        $this->assertSame($replacementAssignment->id, $replacementRoster->teaching_assignment_id);
        $this->assertSame($successorCourse->id, $replacementRosterRow->course_enrollment_id);
        $this->assertTrue($replacementRosterRow->is_current_membership);

        $this->assertSame($sourceCourse->id, $successorCourse->supersedes_course_enrollment_id);
        $this->assertSame(2, $official->corVersions()->count());
        $this->assertNotNull($confirmation->fresh()->consumed_at);
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])->assertActionHidden('adjustRegistration')->mountAction('adjustRegistration')->assertActionNotMounted('adjustRegistration');
        $this->assertSame($originalHash, $official->corVersions()->where('version', 1)->value('content_hash'));
        $historicalVersion = $official->corVersions()->where('version', 1)->firstOrFail();
        $historicalOutput = app(BuildCorOutput::class)->forEnrollment(
            $official->fresh(),
            $application->user,
            BuildCorOutput::CopyStudent,
            requestedVersion: $historicalVersion,
        );
        $this->assertSame(1, $historicalOutput['summary']['cor_version']);
        $this->assertSame('Superseded', $historicalOutput['summary']['document_status']);
        $this->actingAs($application->user)
            ->get(route('cor.print', ['enrollment' => $official, 'version' => 1]))
            ->assertOk();
        $otherApplicant = User::factory()->create(['status' => User::StatusActive]);
        $otherApplicant->assignRole('applicant');
        $this->actingAs($otherApplicant)
            ->get(route('cor.print', ['enrollment' => $official, 'version' => 1]))
            ->assertForbidden();
        $this->actingAs($registrar)
            ->get(route('cor.print', ['enrollment' => $official, 'version' => 1]))
            ->assertOk();

        app(RecordCourseDrop::class)->execute(
            $official,
            $successorCourse,
            $registrar,
            'Learner requested an authorized drop.',
            'SYN-DROP-001',
        );

        $this->assertSame(CourseEnrollment::StatusDropped, $successorCourse->fresh()->status);
        $this->assertFalse($replacementRosterRow->fresh()->is_current_membership);
        $this->assertSame(0, $replacementRoster->rows()->where('is_current_membership', true)->count());
        $this->assertSame(1, GradeRosterRow::query()->whereHas('courseEnrollment', fn ($query) => $query->where('enrollment_id', $official->id))->where('is_current_membership', true)->count());
        $this->assertSame(1, $replacementRoster->rows()->count());
        $this->assertSame(1, $official->courseEnrollments()->where('is_current', true)->count());
        $this->assertSame(3, $official->corVersions()->count());
        $this->assertSame('Open', $official->termAccount->fresh()->state);
        $this->assertSame($application->user_id, $official->credential_user_id);
        $this->assertSame($term->id, $official->term_id);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeRegistrationAdjustmentEmail)->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeCourseDropEmail)->count());
        $dropOutput = app(BuildCorOutput::class)->forEnrollment($official->fresh(), $registrar);
        $this->assertTrue($dropOutput['summary']['accounting_review_pending']);
        $this->actingAs($registrar)->get(route('cor.print', $official))->assertSee('Accounting review pending');
        $updatedPage = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertSee('1 subject ·')->assertSee('View registration certificate')
            ->assertActionHasUrl('printCorHistory', EnrollmentResource::getUrl('view', ['record' => $official, 'view' => 'history']).'#certificate-copies');
        $this->assertSame('official', $updatedPage->instance()->focusedRegistrarView());
        $officialRowActions = $updatedPage->html();
        $currentCourseId = (string) $official->courseEnrollments()->where('is_current', true)->value('id');
        $this->assertStringContainsString('change_type', $officialRowActions);
        $this->assertStringContainsString('Replace', $officialRowActions);
        $this->assertStringContainsString('current_course_enrollment_id', $officialRowActions);
        $this->assertStringContainsString($currentCourseId, $officialRowActions);
        $this->assertStringContainsString('course_enrollment_id', $officialRowActions);
        $this->assertFalse($updatedPage->instance()->getAction('printCorHistory')->shouldOpenModal());
        $updatedPage->set('enrollmentView', 'history')->assertSeeHtml('id="certificate-copies"')
            ->assertSee('Current certificate')->assertSee('Earlier certificate')->assertSee('Open certificate');
        foreach ([1, 2, 3] as $version) {
            $updatedPage->assertSee(route('cor.print', ['enrollment' => $official, 'version' => $version]), escape: false);
        }
    }

    public function test_continuing_student_window_notification_is_idempotent_for_the_exact_calendar_window(): void
    {
        [$official] = $this->officialEnrollment();
        $eligible = StudentProfile::factory()->create([
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        $eligible->user->update(['status' => User::StatusActive]);
        $eligible->user->assignRole('student');

        Artisan::call('enrollment:dispatch-window-notifications');
        Artisan::call('enrollment:dispatch-window-notifications');

        $this->assertSame(1, OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeEnrollmentWindowEmail)
            ->where('user_id', $eligible->user_id)
            ->count());
        $this->assertSame(0, OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeEnrollmentWindowEmail)
            ->where('user_id', $official->credential_user_id)
            ->count());
    }

    public function test_pending_adjustment_draft_and_issued_render_their_native_contextual_actions(): void
    {
        [$official, $application, $term, $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $sections = $official->courseEnrollments()->where('is_current', true)->get()->map(fn (CourseEnrollment $course): int => $course->section_id === $sourceSection->id ? $replacement->id : $course->section_id)->all();
        $proposal = app(PrepareRegistrationProposal::class)->execute($official->fresh(), $registrar, $sections, $official->fresh()->lock_version, RegistrationProposalVersion::PurposeAdjustment);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $currentCourse = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('prepareAdjustmentProposal')->assertMountedActionModalSee('Add a subject')
            ->assertMountedActionModalSee('A proposed change is already open. Review or revise that change before adding another subject.')
            ->assertMountedActionModalDontSee('Current official subject:');
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('prepareAdjustmentProposal', ['change_type' => 'Replace', 'current_course_enrollment_id' => $currentCourse->id])
            ->assertMountedActionModalSee('Change class')->assertMountedActionModalSee('Current official subject:');
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('prepareAdjustmentProposal', ['change_type' => 'Replace', 'current_course_enrollment_id' => $currentCourse->id, 'revise_proposal_id' => $proposal->id])
            ->assertMountedActionModalSee('Replacement class')->assertMountedActionModalDontSee('A proposed change is already open.');
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertSee('Review the proposed class change')->assertSee('Revise the proposed change')->assertSee('Send change for confirmation')->assertActionHidden('placeProposal');
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertSee('Waiting for learner confirmation')->assertSee('Revise the proposed change')->assertSee('Record confirmation')->assertActionHidden('placeProposal');
        $this->assertSame(1, $official->corVersions()->count());
    }

    public function test_no_cost_review_derives_the_confirmed_pair_and_records_internal_confirmation_identity(): void
    {
        [$official, $current, $replacement, $proposal, $accounting] = $this->noCostReviewFixture();
        $originalCor = $official->current_cor_version_id;
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($accounting)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertSee('Review the confirmed registration change')->mountAction('confirmNoAdditionalCost')
            ->assertMountedActionModalSee([$replacement->code, 'Registrar applies the change separately.'])
            ->assertMountedActionModalDontSee('Accounting authority reference');
        $page
            ->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('No-additional-cost classification confirmed');
        $record = RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $official->id)->sole();
        $this->assertSame($current->id, $record->current_course_enrollment_id);
        $this->assertSame($replacement->id, $record->replacement_section_id);
        $this->assertSame('accounting-no-cost:'.$official->id.':proposal:'.$proposal->id.':'.$current->id.':'.$replacement->id, $record->authority_reference);
        $this->assertSame($accounting->id, $record->confirmed_by);
        $this->assertNotNull($record->confirmed_at);
        $this->assertNull($record->consumed_at);
        $this->assertSame($originalCor, $official->fresh()->current_cor_version_id);
        $this->assertTrue($current->fresh()->is_current);
        $this->assertSame(1, $official->corVersions()->count());
    }

    public function test_no_cost_review_ignores_forged_target_selection(): void
    {
        [$official, $current, $replacement, , $accounting] = $this->noCostReviewFixture();
        [$foreign] = $this->officialEnrollment();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($accounting)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('confirmNoAdditionalCost')
            ->setActionData(['authority_reference' => 'EXTERNAL-OWN-CHANGE', 'course_enrollment_id' => $foreign->courseEnrollments()->sole()->id, 'replacement_section_id' => $foreign->courseEnrollments()->sole()->section_id])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('No-additional-cost classification confirmed');
        $this->assertDatabaseHas('registration_adjustment_finance_confirmations', ['enrollment_id' => $official->id, 'current_course_enrollment_id' => $current->id, 'replacement_section_id' => $replacement->id]);
        $this->assertSame(0, RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $foreign->id)->count());
    }

    #[DataProvider('unavailableNoCostReviewSources')]
    public function test_no_cost_review_is_unavailable_without_one_current_confirmed_change(string $source): void
    {
        [$official, $current, $replacement, $proposal, $accounting] = $this->noCostReviewFixture();
        match ($source) {
            'no_pending' => $official->update(['current_proposal_version_id' => $official->currentCorVersion->registration_proposal_version_id]),
            'unconfirmed' => DB::table('registration_proposal_versions')->where('id', $proposal->id)->update(['state' => RegistrationProposalVersion::StateIssued]),
            'missing_confirmation' => DB::table('registration_proposal_confirmations')->where('registration_proposal_version_id', $proposal->id)->delete(),
            'foreign_proposal' => DB::table('registration_proposal_versions')->where('id', $proposal->id)->update(['enrollment_id' => Enrollment::factory()->create()->id]),
            'ambiguous' => RegistrationProposalItem::factory()->create(['registration_proposal_version_id' => $proposal->id, 'sequence' => 99]),
        };
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($accounting)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertActionHidden('confirmNoAdditionalCost')->mountAction('confirmNoAdditionalCost')->assertActionNotMounted('confirmNoAdditionalCost');
        $this->assertSame(0, RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $official->id)->count());
    }

    /** @return array<string, array{string}> */
    public static function unavailableNoCostReviewSources(): array
    {
        return ['no pending change' => ['no_pending'], 'not confirmed' => ['unconfirmed'], 'missing confirmation' => ['missing_confirmation'], 'another case proposal' => ['foreign_proposal'], 'multiple changes' => ['ambiguous']];
    }

    #[DataProvider('changedNoCostReviewSources')]
    public function test_no_cost_review_refuses_changed_sources_after_open(string $source): void
    {
        [$official, $current, $replacement, $proposal, $accounting] = $this->noCostReviewFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($accounting)->test(RegistrarEnrollment::class, ['record' => $official->id])->mountAction('confirmNoAdditionalCost');
        match ($source) {
            'proposal' => DB::table('registration_proposal_versions')->where('id', $proposal->id)->update(['content_hash' => hash('sha256', 'Changed reviewed proposal')]),
            'confirmation' => DB::table('registration_proposal_confirmations')->where('registration_proposal_version_id', $proposal->id)->update(['assisted_evidence_reference' => 'Changed confirmation evidence']),
            'cor' => DB::table('cor_versions')->where('id', $official->current_cor_version_id)->update(['content_hash' => hash('sha256', 'Changed reviewed COR')]),
            'target' => $replacement->update(['code' => 'CHANGED-CLASS']),
            'current_course' => $current->update(['status_reason' => 'Official course source changed.']),
            'timetable' => DB::table('published_timetable_versions')->where('id', $proposal->published_timetable_version_id)->update(['content_hash' => hash('sha256', 'Changed reviewed timetable')]),
        };
        $page->setActionData(['authority_reference' => 'STALE-EXTERNAL-APPROVAL', 'course_enrollment_id' => $current->id, 'replacement_section_id' => $replacement->id])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Accounting confirmation was not recorded');
        $this->assertSame(0, RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $official->id)->count());
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertTrue($current->fresh()->is_current);
    }

    /** @return array<string, array{string}> */
    public static function changedNoCostReviewSources(): array
    {
        return ['proposal' => ['proposal'], 'confirmation' => ['confirmation'], 'COR' => ['cor'], 'target class' => ['target'], 'official course' => ['current_course'], 'published timetable' => ['timetable']];
    }

    public function test_no_cost_review_source_snapshot_cannot_be_replaced_by_client_input(): void
    {
        [$official, , , , $accounting] = $this->noCostReviewFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($accounting)->test(RegistrarEnrollment::class, ['record' => $official->id])->mountAction('confirmNoAdditionalCost');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('reviewedNoCostChange', []);
    }

    public function test_no_cost_review_supports_an_exact_add_without_a_current_course_selection(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment();
        $entry = CurriculumEntry::factory()->for($sourceSection->termOffering->curriculumEntry->curriculumVersion)->create();
        $offering = TermOffering::factory()->for($official->term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $replacement = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 4]);
        $this->confirmedAdjustmentProposal($official, null, $replacement, $registrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($accounting)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('confirmNoAdditionalCost')->assertMountedActionModalSee('New subject')
            ->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('No-additional-cost classification confirmed');
        $record = RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $official->id)->sole();
        $this->assertNull($record->current_course_enrollment_id);
        $this->assertSame($replacement->id, $record->replacement_section_id);
        $this->assertSame(1, $official->corVersions()->count());
    }

    public function test_generated_no_cost_confirmation_is_bound_to_one_exact_proposal_and_consumed_once(): void
    {
        [$official, $current, $replacement, $proposal, $accounting] = $this->noCostReviewFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $otherTarget = Section::factory()->for($replacement->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for(PublishedTimetableVersion::query()->findOrFail($proposal->published_timetable_version_id), 'timetableVersion')->create([
            'section_id' => $otherTarget->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 6,
        ]);

        try {
            app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
                $official,
                $current,
                $otherTarget,
                $accounting,
                proposalVersionId: $proposal->id,
            );
            $this->fail('An internal confirmation must match the exact confirmed proposal pair.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmation', $exception->errors());
        }
        $this->assertSame(0, RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $official->id)->count());

        $firstConfirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
            $official,
            $current,
            $replacement,
            $accounting,
            proposalVersionId: $proposal->id,
        );
        $this->confirmedAdjustmentProposal($official, $current, $otherTarget, $registrar);
        $nextProposal = $this->confirmedAdjustmentProposal($official, $current, $replacement, $registrar);
        $nextConfirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
            $official,
            $current,
            $replacement,
            $accounting,
            proposalVersionId: $nextProposal->id,
        );

        try {
            app(ApplyRegistrationAdjustment::class)->execute(
                $official,
                $nextProposal,
                $registrar,
                RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
                financialConfirmation: $firstConfirmation,
            );
            $this->fail('An internal confirmation for a prior proposal must not authorize a later same-pair proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_confirmation', $exception->errors());
        }
        $this->assertTrue($current->fresh()->is_current);
        $this->assertSame(1, $official->fresh()->corVersions()->count());
        $this->assertNull($firstConfirmation->fresh()->consumed_at);
        $this->assertNull($nextConfirmation->fresh()->consumed_at);

        app(ApplyRegistrationAdjustment::class)->execute(
            $official->fresh(),
            $nextProposal->fresh(),
            $registrar,
            RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
            financialConfirmation: $nextConfirmation,
        );
        $this->assertFalse($current->fresh()->is_current);
        $this->assertNotNull($nextConfirmation->fresh()->consumed_at);
        $this->assertNull($firstConfirmation->fresh()->consumed_at);
        $this->assertSame(2, $official->fresh()->corVersions()->count());
    }

    public function test_supplied_legacy_no_cost_reference_still_applies_the_exact_confirmed_change(): void
    {
        [$official, $current, $replacement, $proposal, $accounting] = $this->noCostReviewFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
            $official,
            $current,
            $replacement,
            $accounting,
            'LEGACY-EXTERNAL-ACCOUNTING-APPROVAL',
        );

        app(ApplyRegistrationAdjustment::class)->execute(
            $official,
            $proposal,
            $registrar,
            RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
            financialConfirmation: $confirmation,
        );

        $this->assertFalse($current->fresh()->is_current);
        $this->assertNotNull($confirmation->fresh()->consumed_at);
        $this->assertSame(2, $official->fresh()->corVersions()->count());
    }

    public function test_no_cost_review_cannot_be_mounted_by_registrar(): void
    {
        [$official] = $this->noCostReviewFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->staff(User::StaffRoleRegistrar))->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('confirmNoAdditionalCost')->assertActionNotMounted('confirmNoAdditionalCost');
        $this->assertSame(0, RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $official->id)->count());
    }

    /** @return array{Enrollment, CourseEnrollment, Section, RegistrationProposalVersion, User} */
    private function noCostReviewFixture(): array
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $current = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $proposal = $this->confirmedAdjustmentProposal($official, $current, $replacement, $registrar);

        return [$official->fresh(), $current, $replacement, $proposal, $this->staff(User::StaffRoleAccounting)];
    }

    public function test_adjustment_finance_basis_is_exact_and_rechecked_after_the_form_opens(): void
    {
        [$official, $application, $term, $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $current = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $proposal = $this->confirmedAdjustmentProposal($official, $current, $replacement, $registrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute($official, $current, $replacement, $accounting, 'EXACT-NO-COST-SOURCE');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id]);
        $page->assertSee('Apply the confirmed class change')->assertActionHidden('placeProposal')->mountAction('adjustRegistration');
        $this->assertSame('confirmation:'.$confirmation->id, $page->get('mountedActions.0.data.financial_basis'));
        $page->setActionData(['financial_basis' => 'confirmation:999999', 'authority_reference' => 'REGISTRAR-APPROVAL'])->callMountedAction()->assertHasActionErrors(['financial_basis']);
        $page->unmountAction()->mountAction('adjustRegistration');
        $confirmation->update(['consumed_at' => now()]);
        $page->setActionData(['authority_reference' => 'REGISTRAR-APPROVAL'])->callMountedAction();
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertTrue($current->fresh()->is_current);
        $page->unmountAction();
        $confirmation->update(['consumed_at' => null]);
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id]);
        $second = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute($official, $current, $replacement, $accounting, 'SECOND-EXACT-NO-COST-SOURCE');
        $page->unmountAction()->mountAction('adjustRegistration');
        $this->assertNull($page->get('mountedActions.0.data.financial_basis'));
        $this->assertCount(2, $page->instance()->adjustmentViewData()['financialBases']);
        $page->setActionData(['authority_reference' => 'REQUIRES-EXPLICIT-CHOICE'])->callMountedAction()->assertHasActionErrors(['financial_basis']);
        $second->update(['consumed_at' => now()]);
        $page->unmountAction()->mountAction('adjustRegistration');
        $official->fresh()->update(['current_proposal_version_id' => $official->currentCorVersion->registration_proposal_version_id]);
        $page->setActionData(['authority_reference' => 'STALE-REGISTRAR-APPROVAL'])->callMountedAction();
        $this->assertSame(1, $official->corVersions()->count());
    }

    public function test_missing_current_cor_is_a_read_only_source_fault_with_retained_version_access(): void
    {
        [$official, $application, $term, $section, $timetable, $registrar] = $this->officialEnrollment();
        $cor = $official->currentCorVersion;
        $hash = $cor->content_hash;
        $official->update(['current_cor_version_id' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertSee('Registration certificate source unavailable')->assertSee('View retained certificate')->assertSee('Current official subjects')
            ->assertActionHidden('finalizeOfficialEnrollment')->assertActionHidden('adjustRegistration')->assertActionHidden('placeProposal');
        $unavailable = app(BuildCorOutput::class)->forEnrollment($official->fresh(), $registrar, BuildCorOutput::CopyRegistrar);
        $this->assertFalse($unavailable['available']);
        $retained = app(BuildCorOutput::class)->forEnrollment($official->fresh(), $registrar, BuildCorOutput::CopyRegistrar, requestedVersion: $cor);
        $this->assertTrue($retained['available']);
        $this->assertSame('Retained copy - current version unavailable', $retained['summary']['document_status']);
        $this->assertSame($hash, $cor->fresh()->content_hash);
        $this->assertSame(1, $official->corVersions()->count());
    }

    public function test_authorized_adjustment_assessments_preserve_versioned_authority(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment();
        $sourceCourse = $official->courseEnrollments()->where('is_current', true)->firstOrFail();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 5,
        ]);
        $this->confirmedAdjustmentProposal($official, $sourceCourse, $replacement, $registrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $created = collect(range(1, 4))->map(function (int $version) use ($official, $accounting): Assessment {
            return $this->recordIndividualAssessment(
                $official->fresh(), $accounting, 'SYN-AUTHORIZED-ADJUSTMENT-'.$version,
                [['code' => 'AUTHORIZED-'.$version, 'label' => 'Authorized fixed adjustment', 'amount' => '0.00']],
                Assessment::CategoryAuthorizedAdjustment,
            );
        });

        $this->assertSame(4, $created->count());
        $this->assertSame([Assessment::CategoryAuthorizedAdjustment], $created->pluck('reason_category')->unique()->values()->all());
        $this->assertTrue($created->every(fn (Assessment $assessment): bool => $assessment->authority_date !== null && is_array($assessment->source_snapshot)));
        $this->assertSame(Assessment::StateActive, $created->last()->fresh()->state);
        $this->assertTrue($created->take(3)->every(fn (Assessment $assessment): bool => $assessment->fresh()->state === Assessment::StateSuperseded));
    }

    public function test_cost_increase_adjustment_requires_the_same_case_current_cleared_successor_assessment(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment();
        $sourceCourse = $official->courseEnrollments()->where('is_current', true)->firstOrFail();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 5,
        ]);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $wrongAssessment = Assessment::factory()->create(['state' => Assessment::StateActive]);
        $adjustmentProposal = $this->confirmedAdjustmentProposal(
            $official,
            $sourceCourse,
            $replacement,
            $registrar,
            assisted: true,
        );

        try {
            app(ApplyRegistrationAdjustment::class)->execute(
                $official,
                $adjustmentProposal,
                $registrar,
                'Increase',
                'SYN-CROSS-CASE-ASSESSMENT',
                $wrongAssessment,
            );
            $this->fail('Another Registration Case assessment must not authorize this adjustment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }

        $successorAssessment = $this->recordIndividualAssessment(
            $official->fresh(),
            $accounting,
            'SYN-INCREASE-001',
            [['code' => 'INCREASE', 'label' => 'Authorized adjustment increase', 'amount' => '0.00']],
            Assessment::CategoryAuthorizedAdjustment,
        );
        app(ApplyRegistrationAdjustment::class)->execute(
            $official->fresh(),
            $adjustmentProposal->fresh(),
            $registrar,
            'Increase',
            'SYN-INCREASE-ADJUSTMENT-001',
            $successorAssessment,
        );

        $this->assertFalse($sourceCourse->fresh()->is_current);
        $this->assertSame(2, $official->fresh()->corVersions()->count());
        $this->assertSame($successorAssessment->id, $official->fresh()->currentCorVersion->assessment_id);
    }

    public function test_learner_confirmed_adjustment_can_add_one_newly_eligible_course_atomically(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment();
        $curriculum = $sourceSection->termOffering->curriculumEntry->curriculumVersion;
        $entry = CurriculumEntry::factory()->for($curriculum)->create();
        $offering = TermOffering::factory()
            ->for($official->term)
            ->for($entry)
            ->create(['state' => TermOffering::StateScheduled]);
        $addedSection = Section::factory()->for($offering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $addedSection->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 4,
        ]);
        $accounting = $this->staff(User::StaffRoleAccounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])->mountAction('prepareAdjustmentProposal')
            ->setActionData(['change_type' => 'Add', 'replacement_section_id' => $addedSection->id])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Proposed change prepared');
        $adjustmentProposal = $official->fresh()->currentProposalVersion;
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('prepareAdjustmentProposal', ['change_type' => 'Add', 'revise_proposal_id' => $adjustmentProposal->id])
            ->assertMountedActionModalSee('New subject class')->assertMountedActionModalDontSee('A proposed change is already open.');
        app(IssueRegistrationProposal::class)->execute($adjustmentProposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($adjustmentProposal->fresh(), $official->credentialUser);
        $adjustmentProposal = $adjustmentProposal->fresh();
        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
            $official,
            null,
            $addedSection,
            $accounting,
            'SYN-NO-COST-ADD-001',
        );

        app(ApplyRegistrationAdjustment::class)->execute(
            $official,
            $adjustmentProposal,
            $registrar,
            RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
            'SYN-ADJUSTMENT-ADD-001',
            financialConfirmation: $confirmation,
        );

        $this->assertSame(2, $official->fresh()->courseEnrollments()->where('is_current', true)->count());
        $this->assertSame(2, $official->fresh()->corVersions()->count());
        $this->assertDatabaseHas('course_enrollments', [
            'enrollment_id' => $official->id,
            'section_id' => $addedSection->id,
            'is_current' => true,
        ]);
    }

    public function test_adjustment_editor_refuses_no_op_and_foreign_class_sources_without_new_proposals(): void
    {
        [$official, , , $section, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $original = $official->current_proposal_version_id;
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $noOptionsPage = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])->mountAction('prepareAdjustmentProposal');
        $this->assertSame([], (fn (): array => $this->adjustmentSectionOptions('Add'))->call($noOptionsPage->instance()));
        $current = $official->courseEnrollments()->where('section_id', $section->id)->sole();
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('prepareAdjustmentProposal', ['change_type' => 'Replace', 'current_course_enrollment_id' => $current->id])
            ->assertMountedActionModalSee('No eligible replacement classes are available for this subject. Review current offerings.')
            ->assertMountedActionModalDontSee('Replacement class')
            ->assertMountedActionModalDontSee('Prepare change');
        $replacement = Section::factory()->for($section->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('prepareAdjustmentProposal', ['change_type' => 'Replace', 'current_course_enrollment_id' => $current->id]);
        $page->setActionData(['replacement_section_id' => $section->id])->callMountedAction()->assertHasActionErrors(['replacement_section_id']);
        $foreign = Section::factory()->create();
        $page->setActionData(['replacement_section_id' => $foreign->id])->callMountedAction()->assertHasActionErrors(['replacement_section_id']);
        $this->assertSame($original, $official->fresh()->current_proposal_version_id);
        $this->assertSame(1, $official->proposalVersions()->count());
        $this->assertSame(1, $official->corVersions()->count());
    }

    public function test_course_drop_refuses_foreign_or_changed_official_source_before_mutation(): void
    {
        [$official, , , $section, , $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $course = $official->courseEnrollments()->where('section_id', $section->id)->sole();
        $foreign = CourseEnrollment::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id]);
        $page->mountAction('dropCourse')->setActionData(['course_enrollment_id' => $foreign->id, 'reason' => 'An approved selected subject drop.', 'authority_reference' => 'DROP-REF'])->callMountedAction()->assertHasActionErrors(['course_enrollment_id']);
        $page->setActionData(['course_enrollment_id' => $course->id, 'reviewed_cor_id' => 999999])->callMountedAction()->assertHasActionErrors(['reviewed_cor_id']);
        $this->assertTrue($course->fresh()->is_current);
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertDatabaseMissing('course_drop_records', ['enrollment_id' => $official->id]);
    }

    public function test_late_course_drop_consumes_only_exact_recorded_authority_without_retyped_reference(): void
    {
        [$official, , $term, , , $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $course = $official->courseEnrollments()->where('is_current', true)->firstOrFail();
        $other = $official->courseEnrollments()->where('is_current', true)->whereKeyNot($course->id)->firstOrFail();
        TermCalendarWindow::whereHas('package', fn ($query) => $query->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeCourseDrop)->update(['closes_on' => now()->subDay()->toDateString()]);
        $authority = app(RecordRegistrationLateAuthority::class)->execute($official, $course, null, $registrar, RegistrationLateAuthority::ActionCourseDrop, 'Registrar Office', 'LATE-EXACT-DROP', CarbonImmutable::now(), 'Approved late Course Drop.', CarbonImmutable::now(), 'Learner acknowledged the exact subject.', 'Approved academic decision.');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id]);
        $page->mountAction('dropCourse')->set('mountedActions.0.data.course_enrollment_id', $course->id);
        $page
            ->assertMountedActionModalSee('LATE-EXACT-DROP')
            ->assertMountedActionModalDontSee('Record late approval')
            ->set('mountedActions.0.data.course_enrollment_id', $other->id)
            ->assertMountedActionModalDontSee('LATE-EXACT-DROP')
            ->assertMountedActionModalDontSee('Record Course Drop')
            ->assertMountedActionModalSee('Record late approval')
            ->setActionData(['late_authority_id' => $authority->id, 'reason' => 'Wrong course must not consume authority.']);
        $page->callMountedAction()->assertHasActionErrors(['late_authority_id']);
        $this->assertNull($authority->fresh()->consumed_at);
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('dropCourse', ['course_enrollment_id' => $course->id])
            ->assertMountedActionModalSee('LATE-EXACT-DROP')
            ->assertMountedActionModalDontSee('Record late approval')
            ->setActionData(['reviewed_cor_id' => $official->current_cor_version_id, 'course_enrollment_id' => $course->id,
                'late_authority_id' => $authority->id, 'reason' => 'The learner requested this exact subject removal.'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Course Drop recorded');
        $this->assertNotNull($authority->fresh()->consumed_at);
        $this->assertSame(CourseEnrollment::StatusDropped, $course->fresh()->status);
        $this->assertSame(2, $official->corVersions()->count());
        $this->assertDatabaseHas('course_drop_records', ['course_enrollment_id' => $course->id, 'authority_reference' => 'LATE-EXACT-DROP', 'finance_state' => 'AccountingReviewPending']);
    }

    public function test_late_drop_approval_uses_the_fixed_subject_and_keeps_optional_details_empty(): void
    {
        [$official, , , , , $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $courses = $official->courseEnrollments()->where('is_current', true)->get();
        $course = $courses->first();
        $other = $courses->last();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id]);
        $page->mountAction('recordLateAuthority')->assertActionNotMounted();
        $page->mountAction('recordLateAuthority', ['action_type' => RegistrationLateAuthority::ActionCourseDrop, 'before_course_enrollment_id' => $course->id])
            ->assertMountedActionModalDontSee('Approved operation')->assertMountedActionModalDontSee('Effective date and time')
            ->setActionData(['approving_office' => 'Academic Office', 'authority_reference' => 'ACTUAL-LATE-DROP', 'authority_date' => now()->toDateString(),
                'learner_acknowledgement_basis' => 'Student request held by the Registrar.',
                'before_course_enrollment_id' => $other->id, 'action_type' => RegistrationLateAuthority::ActionAdjustment, 'effective_at' => now()->addYear()->toDateTimeString()]);
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Late approval recorded');
        $authority = RegistrationLateAuthority::query()->where('enrollment_id', $official->id)->sole();
        $this->assertSame($course->id, (int) $authority->before_course_enrollment_id);
        $this->assertSame(RegistrationLateAuthority::ActionCourseDrop, $authority->action_type);
        $this->assertNull($authority->after_section_id);
        $this->assertNull($authority->reason);
        $this->assertNull($authority->effective_at);
        $this->assertNull($authority->source_academic_decision);
        $this->assertSame($registrar->id, (int) $authority->recorded_by);
        $this->assertTrue($course->fresh()->is_current);
        $this->assertSame(1, $official->corVersions()->count());
        try {
            app(RecordRegistrationLateAuthority::class)->execute($official, $other, null, $registrar, RegistrationLateAuthority::ActionCourseDrop, 'Academic Office', 'ACTUAL-LATE-DROP', CarbonImmutable::now(), null, null, 'Another subject request.', null);
            $this->fail('One approval identity must not silently attach to another subject.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }
        $this->assertSame($course->id, (int) $authority->fresh()->before_course_enrollment_id);
        $this->assertSame(1, RegistrationLateAuthority::where('enrollment_id', $official->id)->count());
        $legacy = app(RecordRegistrationLateAuthority::class)->execute($official, $course, null, $registrar, RegistrationLateAuthority::ActionCourseDrop, 'Academic Office', 'LEGACY-APPROVAL', CarbonImmutable::now(), 'Original approval rationale.', CarbonImmutable::now(), 'Original acknowledgement.', 'Separate recorded academic decision.');
        $before = $legacy->fresh()->getAttributes();
        $replayed = app(RecordRegistrationLateAuthority::class)->execute($official, $course, null, $this->staff(User::StaffRoleRegistrar), RegistrationLateAuthority::ActionCourseDrop, 'Academic Office', 'LEGACY-APPROVAL', CarbonImmutable::now(), null, null, 'Existing student acknowledgement.', null);
        $this->assertSame($legacy->id, $replayed->id);
        $this->assertSame($before, $replayed->fresh()->getAttributes());
    }

    public function test_late_change_approval_derives_confirmation_and_refuses_a_changed_review(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $current = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $this->confirmedAdjustmentProposal($official, $current, $replacement, $registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('recordLateAuthority')->assertMountedActionModalSee('Student confirmation recorded')
            ->assertMountedActionModalDontSee('Student acknowledgement')->assertMountedActionModalDontSee('Effective date and time');
        $data = ['approving_office' => 'Academic Office', 'authority_reference' => 'CONFIRMED-CHANGE-APPROVAL', 'authority_date' => now()->toDateString(), 'learner_acknowledgement_basis' => 'FORGED-ACKNOWLEDGEMENT'];
        $nextSection = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $nextSection->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 6]);
        $nextProposal = $this->confirmedAdjustmentProposal($official, $current, $nextSection, $registrar);
        $page->setActionData($data)->callMountedAction()->assertNotified('Late approval was not recorded');
        $this->assertSame(0, RegistrationLateAuthority::where('enrollment_id', $official->id)->count());
        $page->unmountAction()->mountAction('recordLateAuthority')->setActionData($data)->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Late approval recorded');
        $authority = RegistrationLateAuthority::where('enrollment_id', $official->id)->sole();
        $this->assertSame($nextSection->id, (int) $authority->after_section_id);
        $this->assertSame('Student confirmation #'.$nextProposal->confirmation->id.' for proposal #'.$nextProposal->id, $authority->learner_acknowledgement_basis);
        $this->assertNull($authority->effective_at);
        $this->assertNull($authority->reason);
        $this->assertTrue($current->fresh()->is_current);
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertDatabaseMissing('enrollment_adjustments', ['enrollment_id' => $official->id]);
    }

    public function test_ordinary_adjustment_and_drop_record_their_sources_without_typed_approval_references(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $current = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        $originalCor = $official->currentCorVersion;
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $this->confirmedAdjustmentProposal($official, $current, $replacement, $registrar);
        app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute($official, $current, $replacement, $this->staff(User::StaffRoleAccounting), 'EXACT-ACCOUNTING-AUTHORITY');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('adjustRegistration')->assertMountedActionModalDontSee('Registrar approval reference');
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Official registration adjusted');
        $this->assertDatabaseHas('enrollment_adjustments', ['enrollment_id' => $official->id, 'authority_reference' => null, 'recorded_by' => $registrar->id]);
        $this->assertFalse($current->fresh()->is_current);
        $this->assertSame($originalCor->content_hash, $originalCor->fresh()->content_hash);

        $successor = $official->courseEnrollments()->where('section_id', $replacement->id)->where('is_current', true)->sole();
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('dropCourse', ['course_enrollment_id' => $successor->id])->setActionData(['reason' => 'The student requested the approved subject removal.'])
            ->assertMountedActionModalDontSee('Registrar approval reference');
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Course Drop recorded');
        $this->assertDatabaseHas('course_drop_records', ['course_enrollment_id' => $successor->id, 'authority_reference' => null, 'recorded_by' => $registrar->id, 'finance_state' => 'AccountingReviewPending']);
        $this->assertSame(CourseEnrollment::StatusDropped, $successor->fresh()->status);
        $this->assertSame(3, $official->corVersions()->count());
        $events = $official->registrationEvents()->whereIn('event_type', ['RegistrationAdjustment', 'CourseDrop'])->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (RegistrationCaseEvent $event): bool => $event->authority_reference === null && $event->actor_id === $registrar->id && $event->recorded_at !== null));
    }

    public function test_late_adjustment_uses_stored_authority_and_rejects_stale_or_forged_sources(): void
    {
        [$official, , $term, $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $current = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $proposal = $this->confirmedAdjustmentProposal($official, $current, $replacement, $registrar);
        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute($official, $current, $replacement, $this->staff(User::StaffRoleAccounting), 'EXACT-ACCOUNTING-AUTHORITY');
        TermCalendarWindow::whereHas('package', fn ($query) => $query->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeEnrollmentAdjustment)->update(['closes_on' => now()->subDay()->toDateString()]);
        $authority = app(RecordRegistrationLateAuthority::class)->execute($official, $current, $replacement, $registrar, RegistrationLateAuthority::ActionAdjustment, 'Registrar Office', 'EXACT-LATE-APPROVAL', CarbonImmutable::now(), 'Approved late class change.', CarbonImmutable::now(), 'The student confirmed this exact change.', 'Recorded decision.');
        $authority->update(['effective_at' => now()->addDay()]);
        try {
            app(ApplyRegistrationAdjustment::class)->execute($official, $proposal, $registrar, 'NoAdditionalCost', 'EXACT-LATE-APPROVAL', lateAuthority: $authority, financialConfirmation: $confirmation);
            $this->fail('A future-effective approval must not apply a change early.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }
        $this->assertTrue($current->fresh()->is_current);
        $this->assertNull($authority->fresh()->consumed_at);
        $this->assertSame(1, $official->corVersions()->count());
        $authority->update(['effective_at' => now()]);

        try {
            app(ApplyRegistrationAdjustment::class)->execute($official, $proposal, $registrar, 'NoAdditionalCost', 'FORGED-REFERENCE', lateAuthority: $authority, financialConfirmation: $confirmation);
            $this->fail('A supplied reference must match the exact selected late authority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('adjustRegistration')->setActionData(['late_authority_id' => $authority->id]);
        $authority->update(['consumed_at' => now()]);
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('adjustRegistration')->assertMountedActionModalDontSee('Apply change')->assertMountedActionModalSee('Record approval');
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertTrue($current->fresh()->is_current);
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertDatabaseMissing('enrollment_adjustments', ['enrollment_id' => $official->id]);

        $authority->update(['consumed_at' => null]);
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('adjustRegistration')
            ->setActionData(['late_authority_id' => $authority->id, 'authority_reference' => 'FORGED-FORM-REFERENCE'])
            ->assertMountedActionModalDontSee('Registrar approval reference')
            ->assertMountedActionModalSee('EXACT-LATE-APPROVAL');
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseHas('enrollment_adjustments', ['enrollment_id' => $official->id, 'authority_reference' => 'EXACT-LATE-APPROVAL']);
        $this->assertDatabaseHas('registration_case_events', ['enrollment_id' => $official->id, 'event_type' => 'LateAuthorizedRegistrationAdjustment', 'authority_reference' => 'EXACT-LATE-APPROVAL']);
        $this->assertNotNull($authority->fresh()->consumed_at);
        $this->assertFalse($current->fresh()->is_current);
        $this->assertSame(2, $official->corVersions()->count());
    }

    public function test_late_adjustment_keeps_a_native_choice_when_multiple_exact_approvals_match(): void
    {
        [$official, , $term, $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $current = $official->courseEnrollments()->where('section_id', $sourceSection->id)->where('is_current', true)->sole();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 5]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $replacement->id, 'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id, 'day_of_week' => 5]);
        $proposal = $this->confirmedAdjustmentProposal($official, $current, $replacement, $registrar);
        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute($official, $current, $replacement, $this->staff(User::StaffRoleAccounting), 'MULTI-ACCOUNTING-AUTHORITY');
        TermCalendarWindow::query()->whereHas('package', fn ($query) => $query->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive))->where('window_type', TermCalendarWindow::TypeEnrollmentAdjustment)
            ->update(['closes_on' => now()->subDay()->toDateString()]);
        $record = app(RecordRegistrationLateAuthority::class);
        $record->execute($official, $current, $replacement, $registrar, RegistrationLateAuthority::ActionAdjustment,
            'Registrar Office', 'MULTI-APPROVAL-A', CarbonImmutable::now(), null, CarbonImmutable::now(), 'Confirmed exact class change.', null);
        $selected = $record->execute($official, $current, $replacement, $registrar, RegistrationLateAuthority::ActionAdjustment,
            'Registrar Office', 'MULTI-APPROVAL-B', CarbonImmutable::now(), null, CarbonImmutable::now(), 'Confirmed exact class change.', null);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->mountAction('adjustRegistration')->assertMountedActionModalSee('Exact late-adjustment approval')
            ->setActionData(['late_authority_id' => $selected->id]);
        $page->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Official registration adjusted');

        $this->assertDatabaseHas('enrollment_adjustments', [
            'enrollment_id' => $official->id,
            'authority_reference' => 'MULTI-APPROVAL-B',
            'recorded_by' => $registrar->id,
        ]);
        $this->assertNotNull($selected->fresh()->consumed_at);
        $this->assertFalse($current->fresh()->is_current);
    }

    public function test_adjustment_window_and_exact_late_authority_are_enforced_without_boolean_bypass(): void
    {
        [$official, , $term, , , $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $course = $official->courseEnrollments()->where('is_current', true)->firstOrFail();
        TermCalendarWindow::query()
            ->whereHas('package', fn ($query) => $query->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive))
            ->where('window_type', TermCalendarWindow::TypeCourseDrop)
            ->update(['closes_on' => now()->subDay()->toDateString()]);

        try {
            app(RecordCourseDrop::class)->execute(
                $official,
                $course,
                $registrar,
                'Closed-window attempt.',
                'SYN-LATE-DROP-001',
            );
            $this->fail('A closed adjustment window must reject an ordinary Course Drop.');
        } catch (CalendarGateViolation $exception) {
            $this->assertSame('course_drop_window', $exception->gate);
        }
        $this->assertTrue($course->fresh()->is_current);

        $lateAuthority = app(RecordRegistrationLateAuthority::class)->execute(
            $official,
            $course,
            null,
            $registrar,
            RegistrationLateAuthority::ActionCourseDrop,
            'Registrar Office',
            'SYN-LATE-DROP-001',
            CarbonImmutable::now(),
            'Approved exact-Term late Course Drop.',
            CarbonImmutable::now(),
            'Learner acknowledgement SYN-ACK-001',
            'Academic decision SYN-DECISION-001',
        );
        $lateAuthority->update(['effective_at' => now()->addDay()]);
        try {
            app(RecordCourseDrop::class)->execute($official, $course, $registrar, 'Approved exact-Term late Course Drop.', 'SYN-LATE-DROP-001', $lateAuthority);
            $this->fail('A future-effective approval must not drop a subject early.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }
        $this->assertTrue($course->fresh()->is_current);
        $this->assertNull($lateAuthority->fresh()->consumed_at);
        $lateAuthority->update(['effective_at' => now()]);
        app(RecordCourseDrop::class)->execute(
            $official->fresh(),
            $course->fresh(),
            $registrar,
            'Approved exact-Term late Course Drop.',
            'SYN-LATE-DROP-001',
            $lateAuthority,
        );

        $this->assertNotNull($lateAuthority->fresh()->consumed_at);
        $this->assertSame(CourseEnrollment::StatusDropped, $course->fresh()->status);

        [$singleCourseEnrollment, , , , , $singleRegistrar] = $this->officialEnrollment();
        $lastCourse = $singleCourseEnrollment->courseEnrollments()->where('is_current', true)->firstOrFail();
        try {
            app(RecordCourseDrop::class)->execute(
                $singleCourseEnrollment,
                $lastCourse,
                $singleRegistrar,
                'This must be full withdrawal.',
                'SYN-LAST-COURSE-001',
            );
            $this->fail('Course Drop must not remove the final active course.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('drop', $exception->errors());
        }
        $this->assertTrue($lastCourse->fresh()->is_current);
    }

    public function test_source_impact_review_is_idempotent_and_never_changes_official_course_or_cor_history(): void
    {
        [$official, , , , , $registrar] = $this->officialEnrollment();
        $courseIds = $official->courseEnrollments()->pluck('id')->all();
        $corIds = $official->corVersions()->pluck('id')->all();

        $opened = app(RecordRegistrationSourceImpactReview::class)->open(
            $official,
            $registrar,
            RecordRegistrationSourceImpactReview::SourceAcademicResult,
            'grade-outcome-event:999',
            'Review a synthetic released academic result.',
        );
        $same = app(RecordRegistrationSourceImpactReview::class)->open(
            $official,
            $registrar,
            RecordRegistrationSourceImpactReview::SourceAcademicResult,
            'grade-outcome-event:999',
            'Review a synthetic released academic result.',
        );
        $resolved = app(RecordRegistrationSourceImpactReview::class)->resolve($official, $opened, $registrar, 'No registration change required.');

        $this->assertTrue($opened->is($same));
        $this->assertStringEndsWith('Resolved', $resolved->event_type);
        $this->assertSame($courseIds, $official->courseEnrollments()->pluck('id')->all());
        $this->assertSame($corIds, $official->corVersions()->pluck('id')->all());
        $this->assertSame(2, RegistrationCaseEvent::query()->where('authority_reference', 'grade-outcome-event:999')->count());
    }

    public function test_notification_failure_preserves_official_enrollment_and_authorized_resend_queues_once(): void
    {
        [$official, $application, , , , $registrar] = $this->officialEnrollment(invalidRecipientEmail: true);
        $event = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeOfficialEnrollmentEmail)
            ->where('related_record_id', $official->id)
            ->firstOrFail();

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(OperationalEvent::StatusFailed, $event->status);
        Mail::assertNotQueued(OfficialEnrollmentMail::class);
        $officialBefore = $official->fresh()->getAttributes();
        $corHash = $official->currentCorVersion->content_hash;
        try {
            app(RegistrationNotificationLedger::class)->resend($event, $this->staff(User::StaffRoleFaculty));
            $this->fail('Unrelated staff cannot resend another learner\'s enrollment email.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(OperationalEvent::StatusFailed, $event->fresh()->status);

        $application->user->update([
            'email' => 'applicant@example.test',
            'email_verified_at' => now(),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)
            ->test(RegistrarEnrollment::class, ['record' => $official->id]);
        $component
            ->assertActionVisible('resendOfficialEnrollmentEmail');
        $this->assertFalse($component->instance()->getAction('resendOfficialEnrollmentEmail')->shouldOpenModal());
        $component->callAction('resendOfficialEnrollmentEmail')
            ->assertActionNotMounted('resendOfficialEnrollmentEmail')
            ->assertNotified('Enrollment email queued again');

        $this->assertSame(OperationalEvent::StatusPending, $event->fresh()->status);
        Mail::assertQueued(OfficialEnrollmentMail::class, fn (OfficialEnrollmentMail $mail): bool => $mail->hasTo('applicant@example.test'));
        try {
            app(RegistrationNotificationLedger::class)->resend($event->fresh(), $registrar);
            $this->fail('A pending enrollment email cannot be queued again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notification', $exception->errors());
        }
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])
            ->assertActionHidden('resendOfficialEnrollmentEmail');
        Mail::assertQueued(OfficialEnrollmentMail::class, 1);
        $this->assertSame($officialBefore, $official->fresh()->getAttributes());
        $this->assertSame($corHash, $official->currentCorVersion->fresh()->content_hash);
    }

    /** @return array{AdmissionApplication, Term} */
    public function test_confirmed_and_cleared_learner_sees_a_waiting_result_without_a_payment_demand_or_official_claim(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)
            ->assertSee('Your part is complete')->assertSee('Registrar')
            ->assertDontSee('Submit payment evidence')->assertDontSee('Pay online')->assertDontSee('Official enrollment');
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
    }

    #[DataProvider('unavailableLearnerPlacements')]
    public function test_confirmed_and_cleared_learner_with_unavailable_placement_sees_specific_recovery_without_claiming_completion(string $placement): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);

        $reservation = $proposal->items()->firstOrFail()->reservation;
        match ($placement) {
            'expired' => $reservation->update(['deadline' => now()->subMinute()]),
            'released' => $reservation->update(['status' => EnrollmentSeatReservation::StatusReleased]),
            'missing' => $reservation->delete(),
        };

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $recoveryPage = Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)
            ->assertSee('The Registrar needs to review your classes')
            ->assertSee($placement === 'expired'
                ? 'This enrollment is not official yet. Your temporary seat reservation expired.'
                : 'This enrollment is not official yet. The Registrar needs to confirm an available class placement.')
            ->assertSee('What happens next')
            ->assertSee('Your records stay on file')
            ->assertDontSee('Your part is complete')
            ->assertDontSee('Official enrollment');
        if ($placement !== 'expired') {
            $recoveryPage->assertDontSee('Your temporary seat reservation expired.');
        }
    }

    /** @return array<string, array{string}> */
    public static function unavailableLearnerPlacements(): array
    {
        return ['expired' => ['expired'], 'released' => ['released'], 'missing' => ['missing']];
    }

    public function test_learner_cancellation_form_binds_the_reviewed_case_and_the_closed_view_names_retained_records(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)
            ->mountAction('cancelRegistration')->assertMountedActionModalSee(['Cancel this enrollment?', 'Keep enrollment', 'Only the Registrar can reopen it.'])
            ->assertMountedActionModalDontSee('Reason (optional)');

        $case->increment('lock_version');
        $page->callMountedAction()->assertNotified('Registration was not cancelled');
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertNull($case->fresh()->cancelled_at);

        $page->call('unmountAction')->mountAction('cancelRegistration')->callMountedAction()->assertNotified('Registration case cancelled');
        $this->assertSame(Enrollment::OutcomeCancelledByLearner, $case->fresh()->canonical_outcome);
        $this->assertSame('Cancelled by the learner.', $case->fresh()->status_reason);
        $this->assertSame(EnrollmentSeatReservation::StatusReleased, $proposal->items()->firstOrFail()->reservation()->value('status'));
        Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)
            ->assertSee('Cancelled by you')->assertSee('What stays on record')->assertDontSee('Cancel enrollment');
    }

    #[DataProvider('registrarQueueContexts')]
    public function test_registrar_work_queue_shows_its_current_source_context(string $context, string $tab, string $expected): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft($application->program, $term, [
            ['code' => 'ENROLLMENT', 'label' => 'Authorized enrollment requirement', 'amount' => $context === 'payment' ? '1000.00' : '0.00'],
        ], $accounting);
        $this->publishFeePlan($plan, $accounting, 'SYN-QUEUE-FEE-AUTHORITY');
        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        app(IssueRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        if ($context === 'expired') {
            $proposal->items()->firstOrFail()->reservation->update(['deadline' => now()->subMinute()]);
        } elseif ($context === 'unavailable') {
            $assessment->update(['state' => Assessment::StateSuperseded]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($registrar)->test(ListEnrollments::class)->set('activeTab', $tab)
            ->assertCanSeeTableRecords([$case])->assertSee($expected);
        if ($context === 'unavailable') {
            $page->assertDontSee('enrollment requirement remaining');
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function registrarQueueContexts(): array
    {
        return [
            'expired seats' => ['expired', 'placement_shortages', 'Temporary seats expired'],
            'remaining payment' => ['payment', 'finance_pending', 'PHP 1,000.00 enrollment requirement remaining'],
            'unavailable fee source' => ['unavailable', 'finance_pending', 'Accounting needs to review the fee source.'],
            'ready subject context' => ['ready', 'ready_to_finalize', '1 confirmed subject · 3 units'],
        ];
    }

    public function test_learner_confirmation_form_refuses_a_proposal_replaced_after_it_opened(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $page = Livewire::actingAs($application->user->fresh())->test(ApplicantEnrollment::class)->mountAction('confirmProposal');

        $page->assertSet('reviewedProposalId', $proposal->id);
        try {
            $page->set('reviewedProposalId', $proposal->id + 1);
            $this->fail('The reviewed proposal must not be editable from the browser.');
        } catch (CannotUpdateLockedPropertyException) {
            $page->assertSet('reviewedProposalId', $proposal->id);
        }
        $page->callMountedAction()->assertNotified('Subjects confirmed');
        $this->assertNotNull($proposal->fresh()->confirmation);
    }

    public function test_student_cor_page_leads_with_the_issued_version_and_offers_only_owned_versions(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application->fresh(), $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $first = $official->currentCorVersion;
        $second = $first->replicate()->forceFill(['version' => $first->version + 1, 'supersedes_version_id' => $first->id, 'content_hash' => hash('sha256', 'second-version')]);
        $second->save();
        $official->update(['current_cor_version_id' => $second->id]);
        $foreign = CorVersion::factory()->create(['enrollment_id' => Enrollment::factory()->create()->id]);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $student = $official->credentialUser->fresh();

        Livewire::actingAs($student)->test(CorView::class)
            ->assertSee('Issued copy · Current')->assertSee('Earlier copies and other terms')->assertSee('Earlier copy')->assertDontSee('Version 1')
            ->assertSet('cor.summary.cor_version', 2);
        Livewire::withQueryParams(['version' => $first->id])->actingAs($student)->test(CorView::class)
            ->assertSee('Earlier copy')->assertSet('cor.summary.cor_version', 1);
        Livewire::withQueryParams(['version' => $foreign->id])->actingAs($student)->test(CorView::class)
            ->assertSet('cor.summary.enrollment_id', $official->id)->assertSet('cor.summary.cor_version', 2);
    }

    public function test_student_cor_page_labels_past_term_version_as_historical_not_current(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application->fresh(), $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $student = $official->credentialUser->fresh();

        // Close the term so this enrollment is no longer the active official term
        $term->update(['state' => Term::StateClosed]);

        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($student)->test(CorView::class)
            ->assertSet('cor.summary.document_status', 'Historical')
            ->assertSee('Historical')
            ->assertDontSee('Issued copy · Current');
    }

    private function recordApprovedTransferEvaluation(Enrollment $case, array $credits = []): void
    {
        if ($case->admissionApplication?->application_path !== AdmissionApplication::PathTransferee) {
            return;
        }
        $curriculum = CurriculumVersion::query()->where('program_id', $case->admissionApplication->program_id)->where('state', CurriculumVersion::StateActive)->firstOrFail();
        app(RecordRegistrationCreditEvaluation::class)->execute($case->fresh(), $this->staff(User::StaffRoleRegistrar), $credits,
            'SYN-APPROVED-EVALUATION', CarbonImmutable::today(), (int) $case->admissionApplication->current_submission_version_id,
            $curriculum->id, $case->fresh()->lock_version);
    }

    private function readyApplicant(string $applicationPath = AdmissionApplication::PathFirstYear, ?Term $term = null, ?int $programId = null): array
    {
        if (! $term instanceof Term) {
            $term = Term::factory()->create(['state' => Term::StateActive]);
            $this->openTermCalendarPackage($term);
        }
        $cycle = AdmissionCycle::factory()->for($term)->create();
        $application = AdmissionApplication::factory()->for($cycle, 'admissionCycle')->create([
            'term_id' => $term->id,
            'application_state' => AdmissionApplication::StateAdmitted,
            'application_path' => $applicationPath,
            ...($programId !== null ? ['program_id' => $programId] : []),
        ]);
        $application->user->update(['status' => User::StatusActive]);
        $application->user->assignRole('applicant');
        $requirementSet = AdmissionRequirementSet::factory()->published()->for($cycle)->create([
            'application_path' => $application->application_path,
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create(['submitted_by' => $application->user_id]);
        $application->update(['current_submission_version_id' => $submission->id]);
        $decision = AdmissionDecision::factory()->admitted()->for($application, 'application')->create([
            'application_submission_version_id' => $submission->id,
        ]);
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application)->create([
            'result' => RegistrarEnrollmentClearance::ResultCleared,
            'external_checks_confirmed' => true,
            'recorded_by' => $decision->decided_by,
        ]);

        return [$application->refresh(), $term];
    }

    private function openTermCalendarPackage(Term $term): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'administrative_starts_on' => now()->subMonth()->toDateString(),
            'administrative_ends_on' => now()->addMonths(6)->toDateString(),
            'classes_start_on' => now()->toDateString(),
            'classes_end_on' => now()->addMonths(5)->toDateString(),
            'activated_at' => now(),
        ]);

        foreach ([TermCalendarWindow::TypeEnrollment, TermCalendarWindow::TypeEnrollmentAdjustment, TermCalendarWindow::TypeCourseDrop] as $type) {
            TermCalendarWindow::factory()->for($package, 'package')->create([
                'window_type' => $type,
                'opens_on' => now()->subDay()->toDateString(),
                'closes_on' => now()->addMonth()->toDateString(),
                'cutoff_at' => '23:59:59',
            ]);
        }

        return $package;
    }

    /** @return array{Section, PublishedTimetableVersion, CurriculumVersion} */
    private function publishedOffering(AdmissionApplication $application, Term $term): array
    {
        return $this->publishedOfferingForProgram($application->program, $term);
    }

    /** @return array{Section, PublishedTimetableVersion, CurriculumVersion} */
    private function publishedOfferingForProgram(
        Program $program,
        Term $term,
        ?CurriculumVersion $curriculum = null,
    ): array {
        $curriculum ??= CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'effective_entry_term_id' => $term->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->create();
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $faculty = $this->staff(User::StaffRoleFaculty);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
        ]);

        return [$section, $timetable, $curriculum];
    }

    /** @return array{Enrollment, AdmissionApplication, Term, Section, PublishedTimetableVersion, User} */
    private function officialEnrollment(
        bool $invalidRecipientEmail = false,
        bool $withSecondCourse = false,
        bool $withDesignatedFaculty = false,
    ): array {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $sectionIds = [$section->id];
        if ($withSecondCourse) {
            $entry = CurriculumEntry::factory()->for($section->termOffering->curriculumEntry->curriculumVersion)->create();
            $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
            $secondSection = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
                'section_id' => $secondSection->id,
                'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
                'day_of_week' => 3,
            ]);
            $sectionIds[] = $secondSection->id;
        }
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        if ($withDesignatedFaculty) {
            foreach (Section::query()->whereIn('id', $sectionIds)->get() as $assignedSection) {
                $this->designatedFaculty($assignedSection, $registrar);
            }
        }

        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, $sectionIds, $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic no-payment authority');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        if ($invalidRecipientEmail) {
            $application->user->update(['email' => 'invalid-recipient']);
        }
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);

        return [$official, $application, $term, $section, $timetable, $registrar];
    }

    private function confirmedAdjustmentProposal(
        Enrollment $enrollment,
        ?CourseEnrollment $currentCourse,
        Section $replacementSection,
        User $registrar,
        bool $assisted = false,
    ): RegistrationProposalVersion {
        $sectionIds = $enrollment->courseEnrollments()
            ->where('is_current', true)
            ->where('status', CourseEnrollment::StatusActive)
            ->orderBy('id')
            ->pluck('section_id')
            ->map(fn (int $sectionId): int => $currentCourse !== null && $sectionId === (int) $currentCourse->section_id
                ? (int) $replacementSection->id
                : $sectionId)
            ->push((int) $replacementSection->id)
            ->unique()
            ->values()
            ->all();

        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $enrollment->fresh(),
            $registrar,
            $sectionIds,
            $enrollment->fresh()->lock_version,
            RegistrationProposalVersion::PurposeAdjustment,
        );
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        $learner = User::query()->findOrFail($enrollment->credential_user_id);

        return app(ConfirmRegistrationProposal::class)->execute(
            $proposal->fresh(),
            $learner,
            assistedBy: $assisted ? $registrar : null,
            assistedEvidenceReference: $assisted ? 'SYN-ASSISTED-ADJUSTMENT-CONFIRMATION' : null,
        );
    }

    /** @return array{Enrollment, AdmissionApplication, Section, User} */
    private function caseReadyForFinalization(): array
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic exact-Term authority');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        return [$case->fresh(), $application, $section, $registrar];
    }

    public function test_inactive_continuing_learner_blocks_finalization_without_partial_official_records(): void
    {
        $profile = StudentProfile::factory()->create();
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->openTermCalendarPackage($term);
        [$section] = $this->publishedOfferingForProgram($profile->program, $term, $profile->curriculumVersion);
        $case = app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $profile->user);
        $proposal = $case->currentProposalVersion;
        $this->reserveAndAssessNoPaymentProposal($proposal, $registrar);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $profile->user);
        $profile->user->update(['status' => User::StatusInactive]);

        try {
            app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
            $this->fail('An inactive learner credential must not finalize from earlier clearance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertNull($case->fresh()->current_cor_version_id);
        $this->assertSame(0, $case->courseEnrollments()->count());
        $this->assertSame(EnrollmentSeatReservation::StatusActive, $proposal->items()->sole()->reservation->status);
    }

    public function test_non_registrar_staff_cannot_place_a_current_proposal_or_finalize_it(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        foreach ([User::StaffRoleAcademicHead, User::StaffRoleAccounting, User::StaffRoleFaculty, User::StaffRoleSystemSuperAdmin] as $role) {
            $actor = $this->staff($role);
            foreach ([fn () => app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $actor), fn () => app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $actor)] as $operation) {
                try {
                    $operation();
                    $this->fail('This staff role must not perform Registrar placement or finalization.');
                } catch (AuthorizationException) {
                    $this->assertSame(0, $case->seatReservations()->count());
                    $this->assertSame(0, $case->courseEnrollments()->count());
                    $this->assertNull($case->fresh()->current_cor_version_id);
                }
            }
        }
    }

    public function test_registrar_prepare_work_includes_a_saved_current_draft_without_double_counting_it(): void
    {
        [$application, $term] = $this->readyApplicant(AdmissionApplication::PathTransferee);
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $this->recordApprovedTransferEvaluation($case);
        app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($registrar)->test(ListEnrollments::class);

        $this->assertSame(['prepare' => 1, 'issues' => 0, 'waiting' => 0, 'finance' => 0, 'ready' => 0], $page->instance()->workCounts());
        $page->set('activeTab', 'ready_to_prepare')->assertCanSeeTableRecords([$case]);
    }

    public function test_source_review_binds_sole_and_multiple_events_and_refuses_foreign_or_changed_context(): void
    {
        [$official, , , , , $registrar] = $this->officialEnrollment();
        [$other] = $this->officialEnrollment();
        $academic = $this->staff(User::StaffRoleAcademicHead);
        $first = app(RecordRegistrationSourceImpactReview::class)->open($official, $academic, RecordRegistrationSourceImpactReview::SourceAcademicResult, 'REVIEW-ONE', 'An academic result changed.');
        $foreign = app(RecordRegistrationSourceImpactReview::class)->open($other, $academic, RecordRegistrationSourceImpactReview::SourceAcademicResult, 'REVIEW-FOREIGN', 'Another learner source.');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])->assertSee('Review the academic source change')->mountAction('resolveImpactReview')->assertActionDataSet(['event_id' => $first->id]);
        $page->setActionData(['event_id' => $foreign->id, 'outcome' => 'Reviewed.'])->callMountedAction()->assertHasActionErrors(['event_id']);
        $second = app(RecordRegistrationSourceImpactReview::class)->open($official, $academic, RecordRegistrationSourceImpactReview::SourceAcademicResult, 'REVIEW-TWO', 'A second academic source changed.');
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])->mountAction('resolveImpactReview');
        $this->assertCount(2, (fn (): array => $this->openImpactReviewOptions())->call($page->instance()));
        $page->setActionData(['event_id' => $second->id, 'outcome' => 'Reviewed current source; no registration change required.'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Impact review resolved');
        $this->assertSame(1, $official->registrationEvents()->where('event_type', 'AcademicResultImpactReviewResolved')->count());
        $this->assertTrue($official->courseEnrollments()->sole()->is_current);
        $this->assertSame(1, $official->corVersions()->count());
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $official->id])->mountAction('resolveImpactReview');
        $official->update(['current_cor_version_id' => null]);
        $page->setActionData(['outcome' => 'Reviewed before source changed.'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Impact review was not resolved');
        $this->assertSame(1, $official->registrationEvents()->where('event_type', 'AcademicResultImpactReviewResolved')->count());
    }

    public function test_overload_approval_is_bound_to_the_reviewed_load_and_current_draft(): void
    {
        $profile = StudentProfile::factory()->create(['academic_standing' => StudentProfile::StandingGraduationCandidate]);
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester]);
        $case = Enrollment::factory()->create(['student_profile_id' => $profile->id, 'term_id' => $term->id, 'selection_basis' => Enrollment::SelectionIndividuallyAdvised]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $sections = [];
        foreach (['First Semester', 'Second Semester'] as $index => $label) {
            $entry = CurriculumEntry::factory()->create(['curriculum_version_id' => $profile->curriculum_version_id, 'course_specification_id' => CourseSpecification::factory()->create(['credit_units' => 3])->id, 'year_level' => 'Third Year', 'term_label' => $label, 'term_type' => $index === 0 ? Term::TypeFirstSemester : Term::TypeSecondSemester]);
            $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id, 'category' => $index === 0 ? TermOffering::CategoryRegular : TermOffering::CategorySpecial]);
            $sections[] = Section::factory()->create(['term_offering_id' => $offering->id, 'state' => Section::StateOpen]);
        }
        $load = app(StudentUnitLoadService::class)->snapshotForSections($case, collect($sections));
        $proposal = RegistrationProposalVersion::factory()->create(['enrollment_id' => $case->id, 'curriculum_version_id' => $profile->curriculum_version_id, 'state' => RegistrationProposalVersion::StateDraft, 'source_snapshot' => ['unit_load' => $load]]);
        foreach ($sections as $index => $section) {
            RegistrationProposalItem::factory()->create(['registration_proposal_version_id' => $proposal->id, 'section_id' => $section->id, 'term_offering_id' => $section->term_offering_id, 'sequence' => $index + 1, 'units_snapshot' => 3]);
        }
        $case->update(['current_proposal_version_id' => $proposal->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])->assertSee('Record the approved overload')->assertDontSee('Reserve seats')->mountAction('recordGraduatingOverloadAuthority')
            ->assertMountedActionModalSee('Curriculum load')
            ->assertMountedActionModalDontSee('Evidence reference')->assertMountedActionModalDontSee('Recorded basis');
        $data = ['authority_reference' => 'EXTERNAL-APPROVAL', 'authority_date' => now()->toDateString(), 'evidence_reference' => 'SIGNED-SOURCE', 'reason' => 'Recorded graduating approval for this exact proposed load.'];
        $specification = $sections[0]->termOffering->curriculumEntry->courseSpecification;
        $specification->update(['credit_units' => 4]);
        $page->setActionData($data)->callMountedAction()->assertHasNoActionErrors()->assertNotified('Graduating overload authority was not recorded');
        $specification->update(['credit_units' => 3]);
        $this->assertSame(0, EnrollmentException::where('enrollment_id', $case->id)->count());
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $case->id])->callAction('recordGraduatingOverloadAuthority', $data)->assertHasNoActionErrors()->assertNotified('Graduating overload authority recorded');
        $this->assertSame(1, EnrollmentException::where('enrollment_id', $case->id)->count());
        $authority = EnrollmentException::where('enrollment_id', $case->id)->sole();
        $this->assertNull($authority->evidence_reference);
        $this->assertSame('Overload approval recorded for the reviewed proposed subjects.', $authority->reason);
        $this->assertSame('EXTERNAL-APPROVAL', $authority->approved_values['authority_reference']);
        $this->assertSame($registrar->id, $authority->approved_by);
        $this->assertSame(0, $case->corVersions()->count());
    }

    public function test_selected_batch_finalizes_each_case_independently_and_retries_without_duplicate_official_records(): void
    {
        [$ready, $readyApplication, $section, $registrar] = $this->caseReadyForFinalization();
        [$blocked, $blockedApplication] = $this->caseReadyForFinalization();
        $blocked->currentProposalVersion->items()->sole()->reservation->update(['deadline' => now()->subMinute()]);
        $blockedBefore = $blocked->fresh()->getAttributes();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertNull($section->course_specification_id);
        $item = $ready->currentProposalVersion->items()->sole();
        Livewire::actingAs($registrar)->test(RegistrarEnrollment::class, ['record' => $ready->getRouteKey()])
            ->assertSee($item->course_code_snapshot)->assertSee($item->course_title_snapshot)
            ->assertDontSee('Course unavailable');

        $page = Livewire::actingAs($registrar)->test(ListEnrollments::class);
        $this->assertSame(1, $page->instance()->workCounts()['ready']);
        $this->assertSame(1, $page->instance()->workCounts()['issues']);
        $page->set('activeTab', 'placement_shortages')->assertCanSeeTableRecords([$blocked])->assertCanNotSeeTableRecords([$ready]);
        $page->set('activeTab', 'all_cases')
            ->assertCanSeeTableRecords([$ready, $blocked])
            ->callTableBulkAction('finalizeSelected', [$ready, $blocked])
            ->assertHasNoActionErrors()->assertSee('Selected-case finalization results');
        $results = collect($page->get('finalizationResults'))->keyBy('case');
        $this->assertCount(2, $results);
        $this->assertSame('Finalized', $results[$ready->case_reference]['result']);
        $this->assertSame('Not finalized', $results[$blocked->case_reference]['result']);
        $this->assertNotEmpty($results[$blocked->case_reference]['recovery']);
        $this->assertSame($blockedBefore, $blocked->fresh()->getAttributes());
        $this->assertSame(1, StudentProfile::query()->where('user_id', $readyApplication->user_id)->count());
        $this->assertSame(1, $ready->courseEnrollments()->where('is_current', true)->count());
        $this->assertSame(1, $ready->corVersions()->count());
        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $ready->fresh()->canonical_outcome);
        $this->assertSame(0, StudentProfile::query()->where('user_id', $blockedApplication->user_id)->count());
        $this->assertSame(0, $blocked->courseEnrollments()->count());
        $this->assertSame(0, $blocked->corVersions()->count());
        $corId = $ready->fresh()->current_cor_version_id;
        $membershipId = $ready->courseEnrollments()->sole()->id;

        $page->callTableBulkAction('finalizeSelected', [$ready->fresh(), $blocked->fresh()])->assertHasNoActionErrors();
        $retry = collect($page->get('finalizationResults'))->keyBy('case');
        $this->assertCount(2, $retry);
        $this->assertSame('Finalized', $retry[$ready->case_reference]['result']);
        $this->assertSame('Not finalized', $retry[$blocked->case_reference]['result']);
        $this->assertSame($corId, $ready->fresh()->current_cor_version_id);
        $this->assertSame($membershipId, $ready->courseEnrollments()->sole()->id);
        $this->assertSame(1, $ready->corVersions()->count());
        $this->assertSame(1, StudentProfile::query()->where('user_id', $readyApplication->user_id)->count());
        $this->assertSame($blockedBefore, $blocked->fresh()->getAttributes());
    }

    private function designatedFaculty(Section $section, User $registrar): ClassOfferingTeachingAssignment
    {
        return ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $section->term_offering_id, 'section_id' => $section->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated, 'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'SYN-EXISTING-DESIGNATED-FACULTY-'.$section->id,
            'assigned_by' => $registrar->id, 'effective_at' => now(),
        ]);
    }

    private function reserveAndAssessNoPaymentProposal(RegistrationProposalVersion $proposal, User $registrar): void
    {
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $case = $proposal->enrollment->fresh();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $charges = [['code' => 'NO-PAYMENT', 'label' => 'Synthetic explicitly authorized no-payment requirement', 'amount' => '0.00']];
        if ($case->selection_basis === Enrollment::SelectionIndividuallyAdvised) {
            $this->recordIndividualAssessment($case, $accounting, 'SYN-NO-PAYMENT-ASSESSMENT', $charges);

            return;
        }
        $plan = $this->createFeePlanDraft($case->admissionApplication?->program ?? $case->studentProfile->program, $case->term, $charges, $accounting);
        $this->publishFeePlan($plan, $accounting, 'SYN-NO-PAYMENT-PLAN');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /** @param list<array{code:string,label:string,amount:string}> $charges */
    private function createFeePlanDraft(Program $program, Term $term, array $charges, User $accounting): FeePlan
    {
        return app(CreateFeePlanDraft::class)->execute(
            $program,
            $term,
            $charges,
            $accounting,
            obligations: $this->feePlanObligations($charges, $term),
        );
    }

    private function publishFeePlan(FeePlan $plan, User $accounting, string $authorityReference): FeePlan
    {
        return app(PublishFeePlan::class)->execute(
            $plan,
            $accounting,
            $authorityReference,
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
            'SYN-EXPLICIT-ZERO-ENROLLMENT-AUTHORITY',
        );
    }

    /** @param list<array{code:string,label:string,amount:string}> $charges */
    private function recordIndividualAssessment(Enrollment $enrollment, User $accounting, string $authorityReference, array $charges, string $category = Assessment::CategoryIndividuallyAdvised): Assessment
    {
        return app(RecordAuthorizedIndividualAssessment::class)->execute(
            $enrollment,
            $accounting,
            $category,
            $authorityReference,
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
            $charges,
            $this->feePlanObligations($charges, $enrollment->term),
            'SYN-EXPLICIT-INDIVIDUAL-ZERO-AUTHORITY',
        );
    }

    /** @param list<array{code:string,label:string,amount:string}> $charges */
    private function updateFeePlanDraft(FeePlan $plan, array $charges, User $accounting): FeePlan
    {
        return app(UpdateFeePlanDraft::class)->execute(
            $plan,
            $charges,
            $this->feePlanObligations($charges, $plan->term),
            $accounting,
        );
    }

    /**
     * @param  list<array{code:string,label:string,amount:string}>  $charges
     * @return list<array{code:string,label:string,purpose:string,amount:string,due_at:string,required_for_enrollment:bool}>
     */
    private function feePlanObligations(array $charges, Term $term): array
    {
        return collect($charges)->values()->map(fn (array $charge, int $index): array => [
            'code' => $charge['code'],
            'label' => $charge['label'],
            'purpose' => $index === 0 ? 'Enrollment' : 'Later',
            'amount' => $charge['amount'],
            'due_at' => ($index === 0 ? now()->subMinute() : now()->addMonth())->toDateTimeString(),
            'required_for_enrollment' => $index === 0,
        ])->all();
    }
}
