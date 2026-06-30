<?php

namespace Tests\Feature;

use App\Actions\Cor\BuildCorOutput;
use App\Filament\Student\Pages\CorView;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL70CorOutputTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_student_can_view_and_print_current_cor_with_output_logs(): void
    {
        $fixture = $this->officialCorFixture();
        $student = $fixture['student'];
        $enrollment = $fixture['enrollment'];

        Livewire::actingAs($student)
            ->test(CorView::class)
            ->assertSee('Available')
            ->assertSee($fixture['course_code'])
            ->assertSee('Print / Save as PDF');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => BuildCorOutput::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $enrollment->id,
            'student_profile_id' => $fixture['profile']->id,
            'actor_user_id' => $student->id,
            'action' => BuildCorOutput::ActionView,
            'copy_context' => BuildCorOutput::CopyStudent,
            'status' => 'logged',
        ]);

        $this->actingAs($student)
            ->get(route('cor.print', $enrollment))
            ->assertOk()
            ->assertSee('Registration Form / Certificate of Registration')
            ->assertSee($fixture['course_code'])
            ->assertSee('PHP 4500.00');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => BuildCorOutput::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $enrollment->id,
            'actor_user_id' => $student->id,
            'action' => BuildCorOutput::ActionPrint,
            'copy_context' => BuildCorOutput::CopyStudent,
        ]);
    }

    public function test_student_sees_blocked_state_without_staff_only_hold_notes(): void
    {
        $fixture = $this->officialCorFixture();

        $this->createCorHold(
            $fixture['profile'],
            $fixture['enrollment'],
            $fixture['term'],
            'Please contact Accounting to clear your COR hold.',
            'Private reconciliation note.',
        );

        Livewire::actingAs($fixture['student'])
            ->test(CorView::class)
            ->assertSee('Unavailable')
            ->assertSee('Please contact Accounting to clear your COR hold.')
            ->assertDontSee('Private reconciliation note.');

        $this->assertSame(0, DB::table('output_access_logs')->count());
    }

    public function test_student_without_current_official_enrollment_sees_empty_state(): void
    {
        $student = $this->studentUser();
        StudentProfile::factory()->for($student)->create();

        Livewire::actingAs($student)
            ->test(CorView::class)
            ->assertSee('Unavailable')
            ->assertSee('No current official enrollment is available for COR viewing.');

        $this->assertSame(0, DB::table('output_access_logs')->count());
    }

    public function test_student_cannot_print_another_students_cor(): void
    {
        $studentFixture = $this->officialCorFixture();
        $otherFixture = $this->officialCorFixture();

        $this->actingAs($studentFixture['student'])
            ->get(route('cor.print', $otherFixture['enrollment']))
            ->assertForbidden();
    }

    public function test_registrar_and_accounting_can_print_from_source_enrollment_context(): void
    {
        $fixture = $this->officialCorFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);

        $this->actingAs($registrar)
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertOk()
            ->assertSee('Registrar Copy');

        $this->actingAs($accounting)
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertOk()
            ->assertSee('Accounting Copy');

        $this->assertDatabaseHas('output_access_logs', [
            'actor_user_id' => $registrar->id,
            'action' => BuildCorOutput::ActionPrint,
            'copy_context' => BuildCorOutput::CopyRegistrar,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'actor_user_id' => $accounting->id,
            'action' => BuildCorOutput::ActionPrint,
            'copy_context' => BuildCorOutput::CopyAccounting,
        ]);
    }

    public function test_guest_access_to_print_route_is_denied(): void
    {
        $route = Route::getRoutes()->getByName('cor.print');

        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());

        $fixture = $this->officialCorFixture();

        $this->get(route('cor.print', $fixture['enrollment']))
            ->assertRedirect(route('filament.student.auth.login'));
    }

    /**
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,course_code:string}
     */
    private function officialCorFixture(): array
    {
        $student = $this->studentUser();
        $program = Program::factory()->create([
            'code' => fake()->unique()->bothify('BSIT####'),
            'name' => 'BS Information Technology',
        ]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => 'SIA-2026-'.fake()->unique()->numerify('####'),
            'prior_identifier' => '123456789012',
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'registered_at' => now()->subDay(),
            'officially_enrolled_at' => now(),
        ]);
        $course = Course::factory()->create(['code' => fake()->unique()->bothify('CS###')]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Introduction to Computing',
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => '3.00',
        ]);
        $curriculumEntry = CurriculumEntry::factory()->for($profile->curriculumVersion)->for($specification)->create([
            'year_level' => '1',
            'term_label' => 'First Semester',
        ]);
        $offering = TermOffering::factory()->for($term)->for($curriculumEntry)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => fake()->unique()->bothify('BSIT-1?'),
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Regular Block',
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', uniqid('tal70', true)),
            'solver_version' => 'tal70-test',
            'published_by' => $this->staff(User::StaffRoleRegistrar)->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for(CourseComponent::query()->where('course_specification_id', $specification->id)->first())
            ->for($group)
            ->create(['modality' => TermOffering::ModalityFaceToFace]);
        $faculty = User::factory()->create(['name' => 'Teacher One', 'status' => User::StatusActive]);
        $room = Room::factory()->create(['code' => fake()->unique()->bothify('R###')]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '9000.00',
            'discount_total' => '0.00',
            'total' => '9000.00',
            'required_downpayment' => '2000.00',
            'activated_at' => now(),
        ]);
        $feeRule = FeeRule::query()->create([
            'code' => 'TUITION',
            'name' => 'Tuition Fee',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryTuition,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'calculation_type' => FeeRule::CalculationPerUnit,
            'rate' => '3000.00',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'authority' => 'TAL-70 fixture',
        ]);
        $assessmentLine = AssessmentLine::query()->create([
            'assessment_id' => $assessment->id,
            'fee_rule_id' => $feeRule->id,
            'source_line_key' => 'tuition',
            'description_snapshot' => 'Tuition Fee',
            'quantity' => '3.0000',
            'rate' => '3000.00',
            'amount' => '9000.00',
            'line_type' => 'tuition',
        ]);
        PaymentScheduleRow::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => now()->addWeek()->toDateString(),
            'amount' => '2000.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => '9000.00',
            'source_type' => AssessmentLine::class,
            'source_id' => $assessmentLine->id,
            'description' => 'Tuition Fee',
            'posted_at' => now(),
            'state' => 'posted',
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => 'downpayment',
            'amount' => '4500.00',
            'source_type' => Enrollment::class,
            'source_id' => $enrollment->id,
            'description' => 'Posted payment',
            'posted_at' => now(),
            'state' => 'posted',
        ]);

        return [
            'student' => $student,
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
            'course_code' => $course->code,
        ];
    }

    private function studentUser(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('student');

        return $user;
    }

    private function createCorHold(
        StudentProfile $profile,
        Enrollment $enrollment,
        Term $term,
        string $studentMessage,
        string $staffOnlyReason,
    ): void {
        Hold::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'hold_type' => Hold::TypeCorDownload,
            'blocking_level' => Hold::BlockingCorPrint,
            'status' => Hold::StatusActive,
            'reason' => $staffOnlyReason,
            'student_message' => $studentMessage,
            'staff_only_reason' => $staffOnlyReason,
            'source_type' => Enrollment::class,
            'source_id' => $enrollment->id,
            'effective_at' => now(),
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
