<?php

namespace Tests\Feature\StudentProfiles;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Enrollment\AllocateStudentNumber;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\StudentProfiles\OnboardExistingStudent;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\StudentAccountActivation;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\StudentProfiles\Pages\ImportOngoingStudentsSpreadsheet;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
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

        Notification::assertSentTo($user, ResetPassword::class);

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
        Notification::assertSentTo($user, ResetPassword::class);

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
            ->assertActionDoesNotExist('exportActivationStatus');

        Livewire::test(ListStudentProfiles::class)
            ->assertActionVisible('importStudents')
            ->assertActionVisible('addExistingStudent')
            ->assertActionDoesNotExist('exportActivationStatus')
            ->callAction('addExistingStudent', data: [
                'student_number' => 'SIA-2026-4150',
                'first_name' => '=CMD()',
                'last_name' => 'San Pedro',
                'email' => 'sanpedro.c3@example.test',
                'program_id' => $fixtures['program']->id,
                'curriculum_version_id' => $fixtures['curriculum']->id,
                'academic_standing' => StudentProfile::StandingRegular,
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
                'selected_entry_ids' => [$fixtures['entry1']->id, $fixtures['entry2']->id],
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
                'selected_entry_ids' => [$fixtures['entry1']->id],
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
