<?php

namespace Tests\Feature\StudentHub;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\StudentHub\StudentDashboardService;
use App\Actions\StudentHub\StudentStudyContext;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Dashboard;
use App\Filament\Student\Pages\Finance;
use App\Filament\Student\Pages\Profile;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MigratedStudentFirstRunTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    public function test_provisional_year_standing_derives_from_completed_prior_credits_for_overview_and_profile(): void
    {
        $fixture = $this->createMigratedStudentFixture(completedYears: [1]);
        $profile = $fixture['profile'];

        $context = app(StudentStudyContext::class)->forStudent($profile->user);

        $this->assertFalse($context['is_official']);
        $this->assertSame(['2'], $context['curriculum_levels']);
        $this->assertSame('Year 2', $context['study_level_label']);
        $this->assertSame($fixture['program']->name, $context['program_name']);

        $this->signInStudent($profile);

        Livewire::test(Profile::class)
            ->assertSee('Year 2')
            ->assertSee('Provisional year standing based on your recorded prior credits.');

        // Completing Year 2 advances provisional standing to Year 3.
        $this->recordPriorCredit($profile, $fixture['entries']['y2_1'], '1.75', PriorAcademicCredit::ResultPassed, 'Servitech Institute Asia');
        $this->recordPriorCredit($profile, $fixture['entries']['y2_2'], '2.00', PriorAcademicCredit::ResultPassed, 'Servitech Institute Asia');

        $thirdYearContext = app(StudentStudyContext::class)->forStudent($profile->user);
        $this->assertSame(['3'], $thirdYearContext['curriculum_levels']);
        $this->assertSame('Year 3', $thirdYearContext['study_level_label']);
    }

    public function test_revoked_prior_credits_do_not_produce_provisional_year_standing(): void
    {
        $fixture = $this->createMigratedStudentFixture(completedYears: []);
        $profile = $fixture['profile'];

        PriorAcademicCredit::query()->create([
            'student_profile_id' => $profile->id,
            'course_specification_id' => $fixture['entries']['y1_1']->course_specification_id,
            'curriculum_entry_id' => $fixture['entries']['y1_1']->id,
            'academic_year_label' => 'AY 2024-2025',
            'term_label' => 'First Semester',
            'year_level' => 1,
            'units' => '3.00',
            'final_grade' => '1.50',
            'result_status' => PriorAcademicCredit::ResultPassed,
            'state' => PriorAcademicCredit::StateRevoked,
            'recorded_at' => now(),
            'revoked_at' => now(),
        ]);

        $context = app(StudentStudyContext::class)->forStudent($profile->user);

        $this->assertSame([], $context['curriculum_levels']);
        $this->assertSame('Unavailable', $context['study_level_label']);
        $this->assertFalse($context['is_official']);
    }

    public function test_migrated_student_overview_shows_readiness_card_instead_of_updating_fees_dead_end(): void
    {
        $fixture = $this->createMigratedStudentFixture(completedYears: [1]);
        $profile = $fixture['profile'];

        $this->signInStudent($profile);

        Livewire::test(Dashboard::class)
            ->assertSee('Enrollment readiness')
            ->assertSee('Welcome back, '.$profile->first_name)
            ->assertSee($fixture['program']->name)
            ->assertSee('Year 2 (provisional)')
            ->assertSee('2 subjects (6 units)')
            ->assertSee('Review your study plan')
            ->assertSee('View academic record')
            ->assertDontSee('Accounting is updating your fees')
            ->assertDontSee('Your balance will appear when your assessment is ready.');
    }

    public function test_prior_balance_financial_hold_is_surfaced_calmly_on_overview_and_finance_without_internal_notes(): void
    {
        $fixture = $this->createMigratedStudentFixture(completedYears: [1]);
        $profile = $fixture['profile'];

        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'effective_at' => now()->subDay(),
            'reason' => 'Unpaid prior balance from AY 2024-2025',
            'student_message' => 'Please settle your prior balance with the Accounting Office before next-term enrollment.',
            'resolution_requirement' => 'Accounting clearance required to release prior-balance enrollment hold.',
            'staff_only_reason' => 'CONFIDENTIAL INTERNAL LEDGER RECONCILIATION',
        ]);

        $this->signInStudent($profile);

        Livewire::test(Dashboard::class)
            ->assertSee('Prior-balance hold')
            ->assertSee('Financial hold on your account')
            ->assertSee('Please settle your prior balance with the Accounting Office before next-term enrollment.')
            ->assertSee('Unpaid prior balance from AY 2024-2025')
            ->assertSee('Accounting Office or Registrar will lift this hold')
            ->assertDontSee('Accounting is updating your fees')
            ->assertDontSee('CONFIDENTIAL INTERNAL LEDGER RECONCILIATION');

        Livewire::test(Finance::class)
            ->assertSee('Prior-balance financial hold on your account')
            ->assertSee('Please settle your prior balance with the Accounting Office before next-term enrollment.')
            ->assertSee('Unpaid prior balance from AY 2024-2025')
            ->assertSee('Accounting or the Registrar will lift the hold once settled.')
            ->assertSee('No current-term assessment yet')
            ->assertDontSee('Your fees will appear here')
            ->assertDontSee('CONFIDENTIAL INTERNAL LEDGER RECONCILIATION');
    }

    public function test_academics_defaults_to_study_plan_when_no_current_enrollment_but_prior_credits_exist(): void
    {
        $fixture = $this->createMigratedStudentFixture(completedYears: [1]);
        $profile = $fixture['profile'];

        $this->signInStudent($profile);

        // Without ?view=, defaults cleanly to Study plan instead of "Current subjects unavailable".
        Livewire::test(Academics::class)
            ->assertSet('academicView', 'plan')
            ->assertSee($fixture['program']->name)
            ->assertSee('Year 2 (provisional)')
            ->assertDontSee('Current subjects unavailable');

        // Explicitly visiting ?view=current shows direct links to Study plan and Academic record.
        Livewire::withQueryParams(['view' => 'current'])
            ->test(Academics::class)
            ->assertSet('academicView', 'current')
            ->assertSee('Current subjects unavailable')
            ->assertSee('View study plan')
            ->assertSee('View academic record');
    }

    public function test_curriculum_evaluation_and_academics_distinguish_pre_tala_sia_tala_and_external_transfer_credits(): void
    {
        $fixture = $this->createMigratedStudentFixture(completedYears: []);
        $profile = $fixture['profile'];

        // 1. Pre-TALA Servitech record (numeric grade, Servitech source_school).
        $this->recordPriorCredit(
            $profile,
            $fixture['entries']['y1_1'],
            '1.50',
            PriorAcademicCredit::ResultPassed,
            'Servitech Institute Asia',
        );

        // 2. External transfer credit (non-Servitech source_school).
        $this->recordPriorCredit(
            $profile,
            $fixture['entries']['y1_2'],
            'TC',
            PriorAcademicCredit::ResultCredited,
            'External State College',
        );

        // 3. Passed at SIA (TALA) via native released GradeOutcomeEvent.
        $term = Term::factory()->create(['state' => Term::StateClosed, 'label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now()->subMonths(2),
        ]);
        $offering = TermOffering::factory()->for($term)->for($fixture['entries']['y2_1'], 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $courseEnrollment = CourseEnrollment::factory()
            ->for($enrollment)
            ->for($offering, 'termOffering')
            ->for($section)
            ->for($timetable, 'publishedTimetableVersion')
            ->create([
                'status' => CourseEnrollment::StatusActive,
                'is_current' => true,
            ]);
        $roster = GradeRoster::factory()->for($offering, 'termOffering')->for($section)->create();
        $row = GradeRosterRow::factory()->for($roster, 'roster')->for($courseEnrollment, 'courseEnrollment')->create([
            'released_at' => now()->subMonth(),
            'current_outcome_code' => '1.25',
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
        ]);
        GradeOutcomeEvent::factory()->for($row, 'row')->create([
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => '1.25',
            'new_value' => '1.25',
            'released_at' => now()->subMonth(),
        ]);

        $evaluation = app(CurriculumEvaluation::class)->forStudent($profile);
        $byEntry = collect($evaluation['required'])->keyBy('curriculum_entry.id');

        $preTalaRow = $byEntry->get($fixture['entries']['y1_1']->id);
        $externalRow = $byEntry->get($fixture['entries']['y1_2']->id);
        $siaTalaRow = $byEntry->get($fixture['entries']['y2_1']->id);

        $this->assertSame('pre_tala', $preTalaRow['checklist_state']);
        $this->assertSame('pre_tala', $preTalaRow['record_source']);
        $this->assertSame('Pre-TALA Servitech record', $preTalaRow['credit']['label']);

        $this->assertSame('credited', $externalRow['checklist_state']);
        $this->assertSame('external_transfer', $externalRow['record_source']);
        $this->assertSame('Transfer credit (External)', $externalRow['credit']['label']);
        $this->assertStringContainsString('From External State College', (string) $externalRow['credit']['detail']);

        $this->assertSame('passed', $siaTalaRow['checklist_state']);
        $this->assertSame('sia_tala', $siaTalaRow['record_source']);

        $this->assertSame(1, $evaluation['checklist_totals']['passed']);
        $this->assertSame(1, $evaluation['checklist_totals']['pre_tala']);
        $this->assertSame(1, $evaluation['checklist_totals']['credited']);

        $this->signInStudent($profile);

        Livewire::withQueryParams(['view' => 'plan'])
            ->test(Academics::class)
            ->assertSee('Passed at SIA (TALA)')
            ->assertSee('Pre-TALA Servitech record')
            ->assertSee('Transfer credit (External)')
            ->assertSee('From External State College');

        Livewire::withQueryParams(['view' => 'record'])
            ->test(Academics::class)
            ->assertSee('Pre-TALA Servitech record')
            ->assertSee('Transfer credit (External) · External State College')
            ->assertSee('Passed at SIA (TALA)');
    }

    public function test_student_dashboard_service_grades_by_term_includes_active_prior_academic_credits(): void
    {
        $fixture = $this->createMigratedStudentFixture(completedYears: []);
        $profile = $fixture['profile'];

        $this->recordPriorCredit($profile, $fixture['entries']['y1_1'], '1.75', PriorAcademicCredit::ResultPassed, 'Servitech Institute Asia');
        $this->recordPriorCredit($profile, $fixture['entries']['y1_2'], null, PriorAcademicCredit::ResultCredited, 'External State College');

        // Revoked row must remain invisible.
        PriorAcademicCredit::query()->create([
            'student_profile_id' => $profile->id,
            'course_specification_id' => $fixture['entries']['y2_1']->course_specification_id,
            'curriculum_entry_id' => $fixture['entries']['y2_1']->id,
            'academic_year_label' => 'AY 2024-2025',
            'term_label' => 'First Semester',
            'year_level' => 2,
            'units' => '3.00',
            'final_grade' => '1.25',
            'result_status' => PriorAcademicCredit::ResultPassed,
            'state' => PriorAcademicCredit::StateRevoked,
            'recorded_at' => now(),
            'revoked_at' => now(),
        ]);

        $dashboard = app(StudentDashboardService::class)->forStudent($profile);
        $grades = collect($dashboard['grades']['terms'])
            ->flatMap(fn (array $term): array => $term['grades'])
            ->keyBy('subject_code');

        $code1 = $fixture['entries']['y1_1']->courseSpecification->course->code;
        $code2 = $fixture['entries']['y1_2']->courseSpecification->course->code;
        $revokedCode = $fixture['entries']['y2_1']->courseSpecification->course->code;

        $this->assertTrue($grades->has($code1));
        $this->assertSame('1.75', $grades[$code1]['display_grade']);
        $this->assertSame('pre_tala', $grades[$code1]['source']);

        $this->assertTrue($grades->has($code2));
        $this->assertSame('Transfer Credit', $grades[$code2]['display_grade']);
        $this->assertSame('external_transfer', $grades[$code2]['source']);

        $this->assertFalse($grades->has($revokedCode));
    }

    /**
     * @param  list<int>  $completedYears
     * @return array{program: Program, curriculum: CurriculumVersion, profile: StudentProfile, entries: array<string, CurriculumEntry>}
     */
    private function createMigratedStudentFixture(array $completedYears): array
    {
        $program = Program::factory()->create([
            'code' => 'DIT-M3B',
            'name' => 'Diploma in Information Technology',
            'duration_years' => 3,
            'is_active' => true,
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DIT-M3B-2023',
            'state' => CurriculumVersion::StateActive,
        ]);

        $entries = [
            'y1_1' => $this->curriculumEntry($curriculum, 'M3B 101', 'Introduction to Computing', '1', Term::TypeFirstSemester, 'First Semester', 1),
            'y1_2' => $this->curriculumEntry($curriculum, 'M3B 102', 'Computer Programming 1', '1', Term::TypeSecondSemester, 'Second Semester', 2),
            'y2_1' => $this->curriculumEntry($curriculum, 'M3B 201', 'Data Structures', '2', Term::TypeFirstSemester, 'First Semester', 3),
            'y2_2' => $this->curriculumEntry($curriculum, 'M3B 202', 'Database Systems', '2', Term::TypeSecondSemester, 'Second Semester', 4),
            'y3_1' => $this->curriculumEntry($curriculum, 'M3B 301', 'Capstone Project', '3', Term::TypeFirstSemester, 'First Semester', 5),
        ];

        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('student');

        $profile = StudentProfile::factory()->for($user)->for($program)->for($curriculum)->create([
            'first_name' => 'Camille',
            'last_name' => 'Soriano',
            'entry_term_id' => null,
            'lifecycle_status' => StudentProfile::LifecycleActive,
            'academic_standing' => StudentProfile::StandingRegular,
        ]);

        foreach ($completedYears as $year) {
            foreach ($entries as $entry) {
                if ((int) $entry->year_level === $year) {
                    $this->recordPriorCredit(
                        $profile,
                        $entry,
                        '1.75',
                        PriorAcademicCredit::ResultPassed,
                        'Servitech Institute Asia',
                    );
                }
            }
        }

        return [
            'program' => $program,
            'curriculum' => $curriculum,
            'profile' => $profile,
            'entries' => $entries,
        ];
    }

    private function curriculumEntry(
        CurriculumVersion $curriculum,
        string $code,
        string $title,
        string $yearLevel,
        string $termType,
        string $termLabel,
        int $sequence,
    ): CurriculumEntry {
        $course = Course::factory()->create(['code' => $code]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        return CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => $yearLevel,
            'term_type' => $termType,
            'term_label' => $termLabel,
            'sequence' => $sequence,
        ]);
    }

    private function recordPriorCredit(
        StudentProfile $profile,
        CurriculumEntry $entry,
        ?string $finalGrade,
        string $status,
        ?string $sourceSchool,
    ): PriorAcademicCredit {
        return PriorAcademicCredit::query()->create([
            'student_profile_id' => $profile->id,
            'course_specification_id' => $entry->course_specification_id,
            'curriculum_entry_id' => $entry->id,
            'academic_year_label' => 'AY 2024-2025',
            'term_label' => $entry->term_label,
            'year_level' => (int) $entry->year_level,
            'units' => $entry->courseSpecification->credit_units,
            'final_grade' => $finalGrade,
            'result_status' => $status,
            'source_school' => $sourceSchool,
            'state' => PriorAcademicCredit::StateActive,
            'recorded_at' => now(),
        ]);
    }

    private function signInStudent(StudentProfile $profile): void
    {
        $profile->user->assignRole('student');
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->actingAs($profile->user);
    }
}
