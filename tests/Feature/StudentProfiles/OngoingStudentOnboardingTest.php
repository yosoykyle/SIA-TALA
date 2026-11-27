<?php

namespace Tests\Feature\StudentProfiles;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Enrollment\AllocateStudentNumber;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\StudentProfiles\OnboardExistingStudent;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\StudentAccountActivation;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\StudentProfiles\Pages\ImportOngoingStudentsSpreadsheet;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Notifications\StudentAccountActivationNotification;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class OngoingStudentOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    public function test_registrar_only_authorization_enforced_across_onboarding_actions_and_pages(): void
    {
        $itStaff = $this->staff(User::StaffRoleSystemSuperAdmin);
        $fixtures = $this->createProgramCurriculumFixture();

        $this->assertFalse(ImportOngoingStudentsSpreadsheet::canAccess());
        Livewire::test(ImportOngoingStudentsSpreadsheet::class)->assertForbidden();
        Livewire::test(ListStudentProfiles::class)->assertForbidden();

        Livewire::test(ListUsers::class, ['queue' => 'learners'])
            ->assertActionVisible('exportActivationStatus');
        Livewire::test(ListUsers::class, ['queue' => 'staff'])
            ->assertActionHidden('exportActivationStatus');

        $this->expectException(AuthorizationException::class);
        app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-9901',
            'first_name' => 'Unauthorized',
            'last_name' => 'Attempt',
            'email' => 'unauth.c3@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
        ], $itStaff);
    }

    public function test_onboard_existing_student_creates_passwordless_account_hold_prior_credits_and_sends_activation(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $result = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-4105',
            'first_name' => 'Elena',
            'middle_name' => 'Cruz',
            'last_name' => 'Mendoza',
            'email' => 'elena.mendoza.c3@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'academic_standing' => StudentProfile::StandingIrregular,
            'financial_hold_note' => 'Unpaid prior balance from AY 2024-2025',
            'prior_curriculum_entry_ids' => [$fixtures['entry1']->id],
            'send_activation_email' => true,
        ], $registrar);

        $profile = $result['profile'];
        $user = $result['user'];

        $this->assertNull($profile->applicant_intake_id);
        $this->assertSame('SIA-2026-4105', $profile->student_number);
        $this->assertSame(StudentProfile::StandingIrregular, $profile->academic_standing);
        $this->assertNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('student'));
        $this->assertTrue($result['activation_sent']);
        $this->assertSame(1, $result['prior_credits_created']);
        $this->assertSame(StudentAccountActivation::StatusPendingPasswordSetup, StudentAccountActivation::statusLabel($profile));

        Notification::assertSentTo($user, StudentAccountActivationNotification::class);

        $this->assertDatabaseHas('holds', [
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'reason' => 'Unpaid prior balance from AY 2024-2025',
        ]);

        $this->assertDatabaseHas('prior_academic_credits', [
            'student_profile_id' => $profile->id,
            'curriculum_entry_id' => $fixtures['entry1']->id,
            'course_specification_id' => $fixtures['spec1']->id,
            'state' => PriorAcademicCredit::StateActive,
            'result_status' => PriorAcademicCredit::ResultCredited,
        ]);
    }

    public function test_allocate_student_number_bumps_sequence_and_skips_occupied_imported_numbers(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        // Import #0003 and #0004 manually into the 2099 sequence to isolate from baseline seed numbers.
        app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2099-0003',
            'first_name' => 'First',
            'last_name' => 'Imported',
            'email' => 'first.2099@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'send_activation_email' => false,
        ], $registrar);

        // Pre-occupy 0004 without bumping sequence beyond 0003 to test collision skip loop.
        StudentProfile::factory()->create([
            'student_number' => 'SIA-2099-0004',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
        ]);

        $nextAllocated = app(AllocateStudentNumber::class)->execute(2099);

        $this->assertSame('SIA-2099-0005', $nextAllocated);
    }

    public function test_student_password_setup_auto_verifies_email_and_marks_account_activated(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $result = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-4120',
            'first_name' => 'Marco',
            'last_name' => 'Villanueva',
            'email' => 'marco.villanueva.c3@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'send_activation_email' => false,
        ], $registrar);

        $user = $result['user'];
        $profile = $result['profile'];

        $this->assertNull($user->email_verified_at);
        $this->assertSame(StudentAccountActivation::StatusPendingPasswordSetup, StudentAccountActivation::statusLabel($profile));

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'NewStudentPass#2026',
            'password_confirmation' => 'NewStudentPass#2026',
        ]);

        $user->refresh();
        $profile->refresh();

        $this->assertNotNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(StudentAccountActivation::StatusPasswordSet, StudentAccountActivation::statusLabel($profile));
        $this->assertFalse(StudentAccountActivation::canCorrectUnactivatedEmail($profile));
    }

    public function test_correct_email_and_resend_updates_both_user_and_profile_atomically_and_blocks_activated_accounts(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $result = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-4130',
            'first_name' => 'Liza',
            'last_name' => 'Ramos',
            'email' => 'liza.typo@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'send_activation_email' => false,
        ], $registrar);

        $profile = $result['profile'];
        $user = $result['user'];

        $sent = app(StudentAccountActivation::class)->correctEmailAndResend(
            $profile,
            'liza.corrected@example.test',
            $registrar,
        );

        $this->assertTrue($sent);
        $profile->refresh();
        $user->refresh();

        $this->assertSame('liza.corrected@example.test', $profile->email);
        $this->assertSame('liza.corrected@example.test', $user->email);
        Notification::assertSentTo($user, StudentAccountActivationNotification::class);

        // Once student activates, correctEmailAndResend must reject further edits.
        app(ResetUserPassword::class)->reset($user, [
            'password' => 'LizaSecure#2026',
            'password_confirmation' => 'LizaSecure#2026',
        ]);

        $this->expectException(ValidationException::class);
        app(StudentAccountActivation::class)->correctEmailAndResend(
            $profile->fresh(),
            'liza.after.activation@example.test',
            $registrar,
        );
    }

    public function test_registrar_list_modal_and_profile_actions_support_checklist_sync_banner_and_formula_safe_export(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        Livewire::test(GradesAndCompletion::class, ['viewTab' => 'academic-progress'])
            ->assertActionVisible('importStudents')
            ->assertActionVisible('addExistingStudent')
            ->assertActionVisible('sendPendingActivationInvites')
            ->assertActionVisible('exportActivationStatus');

        Livewire::test(ListStudentProfiles::class)
            ->assertActionVisible('importStudents')
            ->assertActionVisible('addExistingStudent')
            ->assertActionVisible('sendPendingActivationInvites')
            ->assertActionVisible('exportActivationStatus')
            ->callAction('addExistingStudent', data: [
                'student_number' => 'SIA-2026-4150',
                'first_name' => '=CMD()',
                'last_name' => 'San Pedro',
                'email' => 'sanpedro.c3@example.test',
                'program_id' => $fixtures['program']->id,
                'curriculum_version_id' => $fixtures['curriculum']->id,
                'academic_standing' => StudentProfile::StandingRegular,
                'lifecycle_status' => StudentProfile::LifecycleActive,
                'send_activation_email' => false,
            ])
            ->assertHasNoActionErrors();

        $profile = StudentProfile::query()->where('student_number', 'SIA-2026-4150')->firstOrFail();

        // Missing prior record guidance banner appears for newly onboarded student with 0 enrollments & 0 prior credits.
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->assertSee('Prior academic record not yet recorded')
            ->assertActionVisible('recordPriorSubjects')
            ->assertActionVisible('resendActivation')
            ->assertActionVisible('correctActivationEmail')
            ->callAction('recordPriorSubjects', data: [
                'academic_year_label' => 'AY 2023-2024',
                'source_school' => 'Servitech Institute',
                'term_entries' => [
                    'y1_t1' => [$fixtures['entry1']->id, $fixtures['entry2']->id],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertDontSee('Prior academic record not yet recorded');

        $this->assertSame(
            2,
            PriorAcademicCredit::query()->active()->where('student_profile_id', $profile->id)->count(),
        );

        // Unchecking entry2 revokes it without deleting audit history.
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->callAction('recordPriorSubjects', data: [
                'academic_year_label' => 'AY 2023-2024',
                'source_school' => 'Servitech Institute',
                'term_entries' => [
                    'y1_t1' => [$fixtures['entry1']->id],
                ],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, PriorAcademicCredit::query()->active()->where('student_profile_id', $profile->id)->count());
        $this->assertSame(
            1,
            PriorAcademicCredit::query()
                ->where('student_profile_id', $profile->id)
                ->where('state', PriorAcademicCredit::StateRevoked)
                ->count(),
        );

        // Export activation status CSV neutralizes spreadsheet formula injection in Student Name.
        $csvPath = app(OngoingStudentSpreadsheetExportService::class)->exportActivationStatus(collect([$profile->fresh()]));
        $this->tempPaths[] = $csvPath;

        $rows = $this->readCsvRows($csvPath);
        $this->assertCount(2, $rows);
        $this->assertSame('Student Number', $rows[0][0]);
        $this->assertSame('SIA-2026-4150', $rows[1][0]);
        $this->assertStringStartsWith("'=CMD()", (string) $rows[1][1]);
        $this->assertSame('1', (string) $rows[1][6]);
        $this->assertSame(StudentAccountActivation::StatusPendingPasswordSetup, $rows[1][7]);
    }

    public function test_year_term_prior_editor_preserves_inc_and_failed_settles_inc_and_renders_inline_badges(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $course3 = Course::factory()->create(['code' => 'C3IT 102']);
        $spec3 = CourseSpecification::factory()->for($course3)->create([
            'title' => 'Computer Programming 1',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry3 = CurriculumEntry::factory()->for($fixtures['curriculum'])->for($spec3, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
            'term_label' => 'Second Semester',
            'sequence' => 1,
        ]);

        $retiredCourse = Course::factory()->create(['code' => 'C3OLD 99']);
        $retiredSpec = CourseSpecification::factory()->for($retiredCourse)->create([
            'title' => 'Legacy Technical Elective',
            'credit_units' => 3,
            'state' => CourseSpecification::StateRetired,
        ]);

        $onboarded = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-4201',
            'first_name' => 'Ramon',
            'last_name' => 'Dela Vega',
            'email' => 'ramon.delavega@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'send_activation_email' => false,
        ], $registrar);

        $profile = $onboarded['profile'];

        $incCredit = PriorAcademicCredit::query()->create([
            'student_profile_id' => $profile->id,
            'course_specification_id' => $fixtures['spec1']->id,
            'curriculum_entry_id' => $fixtures['entry1']->id,
            'academic_year_label' => 'AY 2023-2024',
            'term_label' => 'First Semester',
            'year_level' => 1,
            'units' => 3,
            'final_grade' => 'INC',
            'result_status' => PriorAcademicCredit::ResultInc,
            'is_external_transfer' => false,
            'state' => PriorAcademicCredit::StateActive,
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);

        $failedCredit = PriorAcademicCredit::query()->create([
            'student_profile_id' => $profile->id,
            'course_specification_id' => $fixtures['spec2']->id,
            'curriculum_entry_id' => $fixtures['entry2']->id,
            'academic_year_label' => 'AY 2023-2024',
            'term_label' => 'First Semester',
            'year_level' => 1,
            'units' => 3,
            'final_grade' => '5.00',
            'result_status' => PriorAcademicCredit::ResultFailed,
            'is_external_transfer' => false,
            'state' => PriorAcademicCredit::StateActive,
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);

        // Checklist sync for Year 1 2nd Sem must preserve the historical INC and Failed rows untouched.
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->callAction('recordPriorSubjects', data: [
                'academic_year_label' => 'AY 2023-2024',
                'source_school' => 'Servitech Institute',
                'term_entries' => [
                    'y1_t1' => [],
                    'y1_t2' => [$entry3->id],
                ],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(PriorAcademicCredit::StateActive, $incCredit->fresh()->state);
        $this->assertSame(PriorAcademicCredit::StateActive, $failedCredit->fresh()->state);
        $this->assertDatabaseHas('prior_academic_credits', [
            'student_profile_id' => $profile->id,
            'curriculum_entry_id' => $entry3->id,
            'state' => PriorAcademicCredit::StateActive,
            'result_status' => PriorAcademicCredit::ResultCredited,
        ]);

        // Record an external transfer credit using a Retired course specification via upsertPriorCredit.
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->callAction('upsertPriorCredit', data: [
                'course_specification_id' => $retiredSpec->id,
                'result_status' => PriorAcademicCredit::ResultCredited,
                'final_grade' => 'TC',
                'is_external_transfer' => true,
                'source_school' => 'Neighboring State College',
                'academic_year_label' => 'AY 2023-2024',
                'term_label' => 'First Semester',
                'year_level' => 1,
            ])
            ->assertHasNoActionErrors();

        $transferCredit = PriorAcademicCredit::query()
            ->where('student_profile_id', $profile->id)
            ->where('course_specification_id', $retiredSpec->id)
            ->firstOrFail();
        $this->assertTrue($transferCredit->isExternalTransfer());
        $this->assertSame('TC', $transferCredit->effectiveResultCode());

        // Academic tab displays inline Pre-TALA and Transfer (TC) badges, inline Settle INC / Edit cues, and no off-ramp inline font-size style.
        $academicComponent = Livewire::test(ViewStudentProfile::class, [
            'record' => $profile->getRouteKey(),
            'recordTab' => 'academic',
            'academicPeriod' => $incCredit->periodKey(),
        ])
            ->assertSee('Pre-TALA')
            ->assertSee('Transfer (TC)')
            ->assertSee('Settle INC')
            ->assertSee('Edit')
            ->assertDontSee('style="font-size: .8125rem"', false);

        // Settle the pre-TALA INC record via settlePriorInc action.
        $academicComponent
            ->callAction('settlePriorInc', data: [
                'prior_credit_id' => $incCredit->id,
                'result_status' => PriorAcademicCredit::ResultPassed,
                'final_grade' => '1.75',
                'reason' => 'Completion form approved by Registrar.',
            ])
            ->assertHasNoActionErrors();

        $incCredit->refresh();
        $this->assertSame('1.75', $incCredit->final_grade);
        $this->assertSame(PriorAcademicCredit::ResultPassed, $incCredit->result_status);

        // Revoke the transfer credit via revokePriorCredit action with audited reason.
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->callAction('revokePriorCredit', data: [
                'prior_credit_id' => $transferCredit->id,
                'reason' => 'Duplicate entry removed after TOR audit.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(PriorAcademicCredit::StateRevoked, $transferCredit->fresh()->state);
    }

    public function test_manual_add_supports_teach_out_program_entry_academic_year_completed_through_term_and_applicant_link(): void
    {
        Notification::fake();

        $this->staff(User::StaffRoleRegistrar);

        $teachOutProgram = Program::factory()->create([
            'code' => 'ACT-OLD',
            'name' => 'Associate in Computer Technology (Teach-out)',
            'duration_years' => 2,
            'is_active' => false,
        ]);

        $retiredCurriculum = CurriculumVersion::factory()->for($teachOutProgram)->create([
            'version_code' => 'ACT-OLD-2022',
            'state' => CurriculumVersion::StateSuperseded,
        ]);

        $batchAy = AcademicYear::factory()->create([
            'label' => 'AY 2022-2023',
            'starts_on' => '2022-08-01',
            'ends_on' => '2023-05-31',
            'state' => AcademicYear::StateClosed,
        ]);

        CurriculumVersionBatch::query()->create([
            'program_id' => $teachOutProgram->id,
            'curriculum_version_id' => $retiredCurriculum->id,
            'academic_year_id' => $batchAy->id,
        ]);

        $c1 = CourseSpecification::factory()->for(Course::factory()->create(['code' => 'ACT101']))->create(['credit_units' => 3, 'state' => CourseSpecification::StateActive]);
        $c2 = CourseSpecification::factory()->for(Course::factory()->create(['code' => 'ACT102']))->create(['credit_units' => 3, 'state' => CourseSpecification::StateActive]);
        $c3 = CourseSpecification::factory()->for(Course::factory()->create(['code' => 'ACT201']))->create(['credit_units' => 3, 'state' => CourseSpecification::StateActive]);

        CurriculumEntry::factory()->for($retiredCurriculum)->for($c1, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);
        CurriculumEntry::factory()->for($retiredCurriculum)->for($c2, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
            'term_label' => 'Second Semester',
            'sequence' => 1,
        ]);
        CurriculumEntry::factory()->for($retiredCurriculum)->for($c3, 'courseSpecification')->create([
            'year_level' => '2',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        Role::findOrCreate('applicant', 'web');
        $existingApplicant = User::factory()->create([
            'email' => 'teachout.applicant@example.test',
            'status' => User::StatusActive,
        ]);
        $existingApplicant->assignRole('applicant');

        // Manual add with teach-out program, entry_academic_year_id, completed_through_term, Probationary + LOA, and opt-in applicant link.
        Livewire::test(ListStudentProfiles::class)
            ->callAction('addExistingStudent', data: [
                'student_number' => 'SIA-2022-0777',
                'first_name' => 'Clarissa',
                'last_name' => 'Navarro',
                'email' => 'teachout.applicant@example.test',
                'birth_date' => '2004-06-15',
                'prior_identifier' => 'LRN-202200777',
                'phone' => '09171230000',
                'program_id' => $teachOutProgram->id,
                'entry_academic_year_id' => $batchAy->id,
                'curriculum_version_id' => $retiredCurriculum->id,
                'completed_through_term' => '1|'.Term::TypeSecondSemester,
                'academic_standing' => StudentProfile::StandingProbationary,
                'lifecycle_status' => StudentProfile::LifecycleLeaveOfAbsence,
                'financial_hold_note' => 'Legacy balance from AY 2022-2023',
                'link_existing_applicant' => true,
            ])
            ->assertHasNoActionErrors();

        $profile = StudentProfile::query()->where('student_number', 'SIA-2022-0777')->firstOrFail();

        $this->assertSame($existingApplicant->id, $profile->user_id);
        $this->assertSame($teachOutProgram->id, $profile->program_id);
        $this->assertSame($retiredCurriculum->id, $profile->curriculum_version_id);
        $this->assertSame($batchAy->id, $profile->entry_academic_year_id);
        $this->assertSame(StudentProfile::StandingProbationary, $profile->academic_standing);
        $this->assertSame(StudentProfile::LifecycleLeaveOfAbsence, $profile->lifecycle_status);
        $this->assertSame('LRN-202200777', $profile->prior_identifier);

        // Year 1 1st & 2nd Sem credited (2 subjects); Year 2 subject NOT credited.
        $creditedSpecIds = PriorAcademicCredit::query()
            ->active()
            ->where('student_profile_id', $profile->id)
            ->pluck('course_specification_id')
            ->all();
        $this->assertEqualsCanonicalizing([$c1->id, $c2->id], $creditedSpecIds);

        // Prior balance hold blocks BOTH enrollment and official record release.
        $this->assertDatabaseHas('holds', [
            'student_profile_id' => $profile->id,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
        ]);
        $this->assertDatabaseHas('holds', [
            'student_profile_id' => $profile->id,
            'blocking_level' => Hold::BlockingRecordRelease,
            'status' => Hold::StatusActive,
        ]);

        // Default send_activation_email is OFF.
        Notification::assertNothingSent();
    }

    public function test_bulk_activation_invites_csv_export_entry_year_and_activation_filters_and_registrar_shortcuts(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createProgramCurriculumFixture();

        $migratedAy = AcademicYear::factory()->create([
            'label' => 'AY 2023-2024 Migrated',
            'starts_on' => '2023-08-01',
            'ends_on' => '2024-05-31',
            'state' => AcademicYear::StateClosed,
        ]);

        $pending = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-8801',
            'first_name' => 'Paolo',
            'last_name' => 'Aquino',
            'email' => 'paolo.aquino.bulk@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'entry_academic_year_id' => $migratedAy->id,
            'send_activation_email' => false,
        ], $registrar);

        $activated = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-8802',
            'first_name' => 'Bianca',
            'last_name' => 'Torres',
            'email' => 'bianca.torres.bulk@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'entry_academic_year_id' => $migratedAy->id,
            'send_activation_email' => false,
        ], $registrar);

        $activated['user']->forceFill([
            'password' => bcrypt('AlreadyActive#2026'),
            'email_verified_at' => now(),
            'last_successful_sign_in_at' => now(),
        ])->save();

        // Entry academic year filter matches entry_academic_year_id even when entry_term_id is NULL;
        // Account activation filter uses last_successful_sign_in_at without SQL error.
        Livewire::test(ListStudentProfiles::class)
            ->filterTable('entry_year', $migratedAy->id)
            ->assertCanSeeTableRecords([$pending['profile'], $activated['profile']])
            ->filterTable('activation_status', 'pending')
            ->assertCanSeeTableRecords([$pending['profile']])
            ->assertCanNotSeeTableRecords([$activated['profile']])
            ->filterTable('activation_status', 'activated')
            ->assertCanSeeTableRecords([$activated['profile']])
            ->assertCanNotSeeTableRecords([$pending['profile']]);

        // Bulk action sends 7-day activation invite ONLY to eligible pending student.
        Livewire::test(ListStudentProfiles::class)
            ->selectTableRecords([$pending['profile']->getKey(), $activated['profile']->getKey()])
            ->callAction(TestAction::make('sendSelectedActivationInvites')->table()->bulk())
            ->assertHasNoActionErrors();

        Notification::assertSentTo($pending['user'], StudentAccountActivationNotification::class);
        Notification::assertNotSentTo($activated['user'], StudentAccountActivationNotification::class);

        // Header "Send activation invites" & "Export activation status (.csv)" work on GradesAndCompletion Student Records.
        Notification::fake();
        $this->travel(65)->seconds();

        Livewire::test(GradesAndCompletion::class, ['viewTab' => 'academic-progress'])
            ->filterTable('entry_year', $migratedAy->id)
            ->callAction('sendPendingActivationInvites')
            ->assertHasNoActionErrors();

        Notification::assertSentTo($pending['user'], StudentAccountActivationNotification::class);
        Notification::assertNotSentTo($activated['user'], StudentAccountActivationNotification::class);

        Livewire::test(GradesAndCompletion::class, ['viewTab' => 'academic-progress'])
            ->filterTable('entry_year', $migratedAy->id)
            ->callAction('exportActivationStatus')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();

        // GradesAndCompletion overview launcher & Students & Enrollment workbench signpost continuing-student onboarding.
        Livewire::test(GradesAndCompletion::class, ['viewTab' => 'overview'])
            ->assertSee('Onboard continuing students');

        Livewire::test(ListEnrollments::class)
            ->assertDontSee('Import continuing students');
    }

    /**
     * @return array{program: Program, curriculum: CurriculumVersion, spec1: CourseSpecification, spec2: CourseSpecification, entry1: CurriculumEntry, entry2: CurriculumEntry}
     */
    private function createProgramCurriculumFixture(): array
    {
        $program = Program::factory()->create([
            'code' => 'DIT-C3',
            'name' => 'Diploma in Information Technology (C3)',
            'duration_years' => 3,
            'is_active' => true,
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DIT-C3-2023',
            'state' => CurriculumVersion::StateActive,
        ]);

        $course1 = Course::factory()->create(['code' => 'C3GE 1']);
        $spec1 = CourseSpecification::factory()->for($course1)->create([
            'title' => 'Understanding the Self',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry1 = CurriculumEntry::factory()->for($curriculum)->for($spec1, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $course2 = Course::factory()->create(['code' => 'C3IT 101']);
        $spec2 = CourseSpecification::factory()->for($course2)->create([
            'title' => 'Introduction to Computing',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry2 = CurriculumEntry::factory()->for($curriculum)->for($spec2, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 2,
        ]);

        return [
            'program' => $program,
            'curriculum' => $curriculum,
            'spec1' => $spec1,
            'spec2' => $spec2,
            'entry1' => $entry1,
            'entry2' => $entry2,
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function readCsvRows(string $path): array
    {
        $reader = new CsvReader;
        $reader->open($path);
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn (mixed $value): string => (string) $value, $row->toArray());
                }
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private function staff(string $roleName): User
    {
        Role::findOrCreate('student', 'web');
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->syncPermissions([
                Permission::findOrCreate('manage-schedules', 'web'),
                Permission::findOrCreate('approve-documents', 'web'),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }
}
