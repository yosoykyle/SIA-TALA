<?php

namespace Tests\Feature;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Actions\StudentHub\StudentDashboardService;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D3EnrollmentCorJourneyClosureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_source_derived_context_reports_mixed_curriculum_levels_and_course_delivery_mix(): void
    {
        $fixture = $this->academicContextFixture(
            studentType: 'irregular',
            levelsAndModalities: [
                ['1', TermOffering::ModalityOnline],
                ['2', TermOffering::ModalityFaceToFace],
            ],
        );

        $context = app(EnrollmentAcademicContextResolver::class)->forEnrollment($fixture['enrollment']);

        $this->assertSame($fixture['enrollment']->id, $context['enrollment_id']);
        $this->assertSame($fixture['term']->label, $context['term_label']);
        $this->assertSame('Officially Enrolled', $context['enrollment_status_label']);
        $this->assertSame('Irregular', $context['enrollment_type_label']);
        $this->assertSame($fixture['program']->code, $context['program_code']);
        $this->assertSame($fixture['profile']->curriculumVersion->name, $context['curriculum_name']);
        $this->assertSame(['1', '2'], $context['curriculum_levels']);
        $this->assertSame('Mixed Levels (1, 2)', $context['curriculum_level_label']);
        $this->assertSame(['D3-1', 'D3-2'], $context['section_labels']);
        $this->assertSame(['D3 Cohort 1', 'D3 Cohort 2'], $context['cohort_labels']);
        $this->assertSame('Mixed', $context['course_delivery_mix']);
        $this->assertSame('Registrar Office', $context['responsible_office']);
        $this->assertSame(
            'Enrollment is official. No further enrollment processing is required.',
            $context['next_action'],
        );
        $this->assertArrayNotHasKey('student_modality', $context);
    }

    public function test_regular_single_level_context_remains_truthful(): void
    {
        $fixture = $this->academicContextFixture(
            studentType: 'regular',
            levelsAndModalities: [
                ['2', TermOffering::ModalityOnline],
                ['2', TermOffering::ModalityOnline],
            ],
        );

        $context = app(EnrollmentAcademicContextResolver::class)->forEnrollment($fixture['enrollment']);

        $this->assertSame(['2'], $context['curriculum_levels']);
        $this->assertSame('Level 2', $context['curriculum_level_label']);
        $this->assertSame('Online', $context['course_delivery_mix']);
    }

    public function test_student_dashboard_and_cor_share_the_source_derived_academic_context(): void
    {
        $fixture = $this->academicContextFixture(
            studentType: 'irregular',
            levelsAndModalities: [
                ['1', TermOffering::ModalityOnline],
                ['3', TermOffering::ModalityFaceToFace],
            ],
        );
        $historicalTerm = Term::factory()->create([
            'label' => 'D3 Newer Historical Record',
            'starts_on' => '2090-01-01',
            'ends_on' => '2090-05-31',
            'state' => Term::StateClosed,
        ]);
        $historicalEnrollment = Enrollment::factory()
            ->for($fixture['profile'])
            ->for($historicalTerm)
            ->create([
                'status' => 'pending_payment',
                'student_type' => 'irregular',
            ]);

        $dashboard = app(StudentDashboardService::class)->forStudent($fixture['profile']);
        $cor = app(BuildCorOutput::class)->forEnrollment(
            $fixture['enrollment'],
            $fixture['student'],
            BuildCorOutput::CopyStudent,
            true,
        );
        $print = view('cor.print', ['cor' => $cor])->render();

        $this->assertGreaterThan($fixture['enrollment']->id, $historicalEnrollment->id);
        $this->assertSame($fixture['enrollment']->id, $dashboard['enrollment']['current']['enrollment_id']);
        $this->assertSame($fixture['term']->label, $dashboard['enrollment']['current']['term_name']);
        $this->assertSame('Mixed Levels (1, 3)', $dashboard['enrollment']['current']['curriculum_level']);
        $this->assertSame('Mixed', $dashboard['enrollment']['current']['course_delivery_mix']);
        $this->assertSame(['D3-1', 'D3-2'], $dashboard['enrollment']['current']['sections']);
        $this->assertArrayNotHasKey('year_level', $dashboard['enrollment']['current']);
        $this->assertArrayNotHasKey('modality', $dashboard['enrollment']['current']);
        $this->assertArrayNotHasKey('lis_status', $dashboard['enrollment']['current']);

        $this->assertTrue($cor['available']);
        $this->assertSame('Mixed Levels (1, 3)', $cor['state']['curriculum_level']);
        $this->assertSame('Mixed Levels (1, 3)', $cor['summary']['curriculum_level']);
        $this->assertSame('Mixed', $cor['state']['course_delivery_mix']);
        $this->assertStringContainsString('Curriculum Level', $print);
        $this->assertStringContainsString('Mixed Levels (1, 3)', $print);
        $this->assertStringContainsString('Course Delivery Mix', $print);
        $this->assertStringNotContainsString('Student Modality', $print);
    }

    public function test_enrollment_queue_filters_by_term_and_program(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $target = $this->academicContextFixture('irregular', [['1', TermOffering::ModalityOnline]]);
        $other = $this->academicContextFixture('regular', [['2', TermOffering::ModalityFaceToFace]]);

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->assertTableFilterExists('term')
            ->assertTableFilterExists('program')
            ->filterTable('term', $target['term']->id)
            ->assertCanSeeTableRecords([$target['enrollment']])
            ->assertCanNotSeeTableRecords([$other['enrollment']])
            ->resetTableFilters()
            ->filterTable('program', $target['program']->id)
            ->assertCanSeeTableRecords([$target['enrollment']])
            ->assertCanNotSeeTableRecords([$other['enrollment']]);
    }

    public function test_irregular_student_waits_on_the_active_term_without_a_proposal_reservation_or_solver_run(): void
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create();
        $activeTerm = Term::factory()->create([
            'label' => 'D3 Active Waiting Term',
            'starts_on' => '2099-06-01',
            'ends_on' => '2099-10-31',
            'state' => Term::StateActive,
        ]);
        $closedTerm = Term::factory()->create([
            'label' => 'D3 Closed Historical Term',
            'starts_on' => '2098-06-01',
            'ends_on' => '2098-10-31',
            'state' => Term::StateClosed,
        ]);
        $activeEnrollment = Enrollment::factory()->for($profile)->for($activeTerm)->create([
            'status' => 'capacity_pending',
            'student_type' => 'irregular',
            'status_reason' => 'Waiting for compatible published sections.',
        ]);
        Enrollment::factory()->for($profile)->for($closedTerm)->create([
            'status' => 'pending_payment',
            'student_type' => 'irregular',
            'status_reason' => 'Historical enrollment must not become current.',
        ]);
        $solverRunsBefore = ScheduleGenerationRun::query()->count();

        Livewire::actingAs($student)
            ->test(StudentEnrollmentPage::class)
            ->assertSee('Current Term: D3 Active Waiting Term')
            ->assertSee('Capacity Pending')
            ->assertSee('Enrollment Type: Irregular')
            ->assertSee('Curriculum Level: Not recorded')
            ->assertSee('Course Delivery Mix: Not recorded')
            ->assertSee('Waiting for compatible published sections')
            ->assertSee('Responsible Office: Registrar Office')
            ->assertSee('Next Action: Confirm a published section with available capacity');

        $this->assertSame(0, CourseEnrollment::query()->where('enrollment_id', $activeEnrollment->id)->count());
        $this->assertSame(0, EnrollmentSeatReservation::query()->where('enrollment_id', $activeEnrollment->id)->count());
        $this->assertSame($solverRunsBefore, ScheduleGenerationRun::query()->count());
    }

    public function test_student_record_list_exposes_and_filters_current_enrollment_context(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $target = $this->academicContextFixture('irregular', [
            ['1', TermOffering::ModalityOnline],
            ['2', TermOffering::ModalityFaceToFace],
        ]);
        $other = $this->academicContextFixture('regular', [['3', TermOffering::ModalityFaceToFace]]);
        $newerActiveTerm = Term::factory()->create([
            'label' => 'D3 Later Active Term',
            'starts_on' => '2101-01-01',
            'ends_on' => '2101-05-31',
            'state' => Term::StateActive,
        ]);
        Enrollment::factory()
            ->for($target['profile'])
            ->for($newerActiveTerm)
            ->create([
                'status' => 'pending_payment',
                'student_type' => 'irregular',
            ]);

        Livewire::actingAs($registrar)
            ->test(ListStudentProfiles::class)
            ->assertTableColumnExists('current_term')
            ->assertTableColumnExists('current_enrollment_status')
            ->assertTableColumnExists('current_enrollment_type')
            ->assertTableColumnExists('curriculum_level')
            ->assertTableFilterExists('program')
            ->assertTableFilterExists('current_enrollment_status')
            ->filterTable('program', $target['program']->id)
            ->assertCanSeeTableRecords([$target['profile']])
            ->assertCanNotSeeTableRecords([$other['profile']])
            ->assertSee($newerActiveTerm->label)
            ->assertSee('Payment Pending')
            ->resetTableFilters()
            ->filterTable('current_enrollment_status', 'officially_enrolled')
            ->assertCanSeeTableRecords([$other['profile']])
            ->assertCanNotSeeTableRecords([$target['profile']])
            ->resetTableFilters()
            ->filterTable('current_enrollment_status', 'pending_payment')
            ->assertCanSeeTableRecords([$target['profile']])
            ->assertCanNotSeeTableRecords([$other['profile']]);
    }

    /**
     * @param  list<array{0:string,1:string}>  $levelsAndModalities
     * @return array{student:User,program:Program,profile:StudentProfile,term:Term,enrollment:Enrollment}
     */
    private function academicContextFixture(string $studentType, array $levelsAndModalities): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');
        $program = Program::factory()->create([
            'code' => fake()->unique()->bothify('D3-PRG-####'),
            'name' => fake()->unique()->words(3, true),
        ]);
        $profile = StudentProfile::factory()
            ->for($student)
            ->for($program)
            ->create();
        $term = Term::factory()->create([
            'label' => fake()->unique()->bothify('D3 Term ####'),
            'starts_on' => fake()->unique()->dateTimeBetween('2095-01-01', '2099-12-31')->format('Y-m-d'),
            'ends_on' => '2100-01-31',
            'state' => Term::StateActive,
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'status' => 'officially_enrolled',
                'student_type' => $studentType,
                'registered_at' => now()->subDay(),
                'officially_enrolled_at' => now(),
            ]);

        foreach ($levelsAndModalities as $index => [$level, $modality]) {
            $entry = CurriculumEntry::factory()
                ->for($profile->curriculumVersion)
                ->create([
                    'year_level' => $level,
                    'term_label' => $term->label,
                    'term_type' => $term->type,
                    'sequence' => $index + 1,
                ]);
            $offering = TermOffering::factory()
                ->for($term)
                ->for($entry, 'curriculumEntry')
                ->create([
                    'modality' => $modality,
                    'state' => TermOffering::StateScheduled,
                ]);
            $section = Section::factory()
                ->for($offering, 'termOffering')
                ->create([
                    'code' => 'D3-'.($index + 1),
                    'state' => Section::StateOpen,
                ]);
            SectionDeliveryGroup::factory()
                ->for($section)
                ->create(['name' => 'D3 Cohort '.($index + 1)]);
            $courseEnrollment = CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
                'proposed_section_id' => $section->id,
                'proposed_at' => now()->subDay(),
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => '3.00',
                'added_at' => now(),
            ]);
            EnrollmentSeatReservation::query()->create([
                'enrollment_id' => $enrollment->id,
                'course_enrollment_id' => $courseEnrollment->id,
                'section_id' => $section->id,
                'status' => EnrollmentSeatReservation::StatusConverted,
                'reserved_at' => now()->subDay(),
                'converted_at' => now(),
                'registrar_user_id' => $this->staff(User::StaffRoleRegistrar)->id,
                'lock_version' => 1,
            ]);
        }

        return compact('student', 'program', 'profile', 'term', 'enrollment');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
