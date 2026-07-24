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
use App\Models\FinancialAccommodation;
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
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL88ACorSourceOutputAcceptanceTest extends TestCase
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

    public function test_leave_withdrawn_and_transferred_out_lifecycle_block_current_cor_with_student_safe_message(): void
    {
        $fixture = $this->officialCorFixture();

        $cases = [
            StudentProfile::LifecycleLeaveOfAbsence => 'Leave of Absence',
            StudentProfile::LifecycleWithdrawn => 'Withdrawn',
            StudentProfile::LifecycleTransferredOut => 'Transferred Out',
        ];

        foreach ($cases as $status => $label) {
            $fixture['profile']->update(['lifecycle_status' => $status]);

            $output = app(BuildCorOutput::class)->forStudent($fixture['student']);

            $this->assertFalse($output['available'], "Expected COR unavailable for {$status}");
            $this->assertStringContainsString($label, $output['reason']);
            $this->assertStringContainsString('Registrar Office', $output['reason']);
            $this->assertStringNotContainsString('staff-only', mb_strtolower($output['reason']));
            $this->assertSame([], $output['state']['subjects']);
            $this->assertFalse($output['state']['installment_applicable']);
        }

        $this->assertSame(0, DB::table('output_access_logs')->count());
    }

    public function test_cor_totals_align_with_source_units_fees_and_ledger_balance(): void
    {
        $fixture = $this->officialCorFixture();

        $output = app(BuildCorOutput::class)->forEnrollment(
            $fixture['enrollment'],
            $this->staff(User::StaffRoleRegistrar),
            BuildCorOutput::CopyRegistrar,
        );

        $this->assertTrue($output['available']);
        $this->assertSame('3.00', $output['summary']['total_units']);
        $this->assertSame(1, $output['schedule_version']);

        $fees = collect($output['fees']);
        $this->assertSame('9000.00', $fees->firstWhere('label', 'Tuition Fee')['amount']);
        $this->assertSame('9000.00', $fees->firstWhere('label', 'Total Fees')['amount']);
        $this->assertSame('2000.00', $fees->firstWhere('label', 'Down Payment')['amount']);
        $this->assertSame('4500.00', $fees->firstWhere('label', 'Posted Payments')['amount']);
        $this->assertSame('4500.00', $fees->firstWhere('label', 'Balance')['amount']);
    }

    public function test_dropped_subject_is_excluded_from_current_cor_subject_list(): void
    {
        $fixture = $this->officialCorFixture();
        $droppedCode = $this->addDroppedCourseEnrollment($fixture);

        $output = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($output['available']);
        $subjectCodes = collect($output['subjects'])->pluck('subject_code');
        $this->assertTrue($subjectCodes->contains($fixture['course_code']));
        $this->assertFalse($subjectCodes->contains($droppedCode));
        $this->assertSame('3.00', $output['summary']['total_units']);
    }

    public function test_lecture_and_laboratory_components_render_as_separate_rows_with_single_unit_count(): void
    {
        $fixture = $this->officialCorFixture();
        $this->addLaboratoryMeeting($fixture);

        $output = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($output['available']);
        $rows = collect($output['subjects'])->where('subject_code', $fixture['course_code'])->values();

        $this->assertCount(2, $rows, 'Lecture and laboratory should render as two meeting rows.');
        $this->assertSame(1, $rows->pluck('course_enrollment_id')->unique()->count());
        $this->assertSame('3.00', $output['summary']['total_units']);
        $this->assertSame('3.00', $rows->first()['lecture_hours']);
        $this->assertSame('2.00', $rows->first()['laboratory_hours']);
        $this->assertSame(2, $rows->pluck('time')->unique()->count());
    }

    public function test_installment_schedule_renders_from_multi_row_assessment_schedule(): void
    {
        $fixture = $this->officialCorFixture();
        $this->addAssessmentInstallmentRows($fixture['assessment']);

        $output = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($output['available']);
        $this->assertTrue($output['installment']['applicable']);

        $rows = $output['installment']['rows'];
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], collect($rows)->pluck('number')->all());
        $this->assertSame('7000.00', $rows[0]['remaining_balance']);
        $this->assertSame('3500.00', $rows[1]['remaining_balance']);
        $this->assertSame('0.00', $rows[2]['remaining_balance']);
        $this->assertSame('Pending', $rows[0]['reference']);
        $this->assertSame('Unpaid', $rows[0]['date_paid']);

        $this->actingAs($fixture['student'])
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertOk()
            ->assertSee('Installment Schedule')
            ->assertSee('7000.00');

        Livewire::actingAs($fixture['student'])
            ->test(CorView::class)
            ->assertSee('Installment Schedule')
            ->assertSee('3500.00');
    }

    public function test_installment_schedule_prefers_active_financial_accommodation_schedule(): void
    {
        $fixture = $this->officialCorFixture();
        $this->addActiveAccommodationSchedule($fixture);

        $output = app(BuildCorOutput::class)->forStudent($fixture['student']);

        $this->assertTrue($output['available']);
        $this->assertTrue($output['installment']['applicable']);

        $rows = $output['installment']['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('5000.00', $rows[0]['amount']);
        $this->assertSame('4000.00', $rows[0]['remaining_balance']);
        $this->assertSame('4000.00', $rows[1]['amount']);
        $this->assertSame('0.00', $rows[1]['remaining_balance']);
    }

    private function addAssessmentInstallmentRows(Assessment $assessment): void
    {
        PaymentScheduleRow::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 2,
            'category' => 'installment',
            'due_date' => now()->addMonth()->toDateString(),
            'amount' => '3500.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);
        PaymentScheduleRow::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 3,
            'category' => 'installment',
            'due_date' => now()->addMonths(2)->toDateString(),
            'amount' => '3500.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function addActiveAccommodationSchedule(array $fixture): void
    {
        $accommodation = FinancialAccommodation::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'balance_snapshot' => '9000.00',
            'covered_amount' => '0.00',
            'basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
            'authority' => 'TAL-88A fixture',
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => now()->subDay()->toDateString(),
            'expires_on' => now()->addMonths(3)->toDateString(),
        ]);

        PaymentScheduleRow::query()->create([
            'financial_accommodation_id' => $accommodation->id,
            'sequence' => 1,
            'category' => 'installment',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'amount' => '5000.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);
        PaymentScheduleRow::query()->create([
            'financial_accommodation_id' => $accommodation->id,
            'sequence' => 2,
            'category' => 'installment',
            'due_date' => now()->addMonths(2)->toDateString(),
            'amount' => '4000.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function addDroppedCourseEnrollment(array $fixture): string
    {
        $course = Course::factory()->create(['code' => fake()->unique()->bothify('DROP###')]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Dropped Elective',
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateActive,
        ]);
        $curriculumEntry = CurriculumEntry::factory()->for($fixture['profile']->curriculumVersion)->for($specification)->create([
            'year_level' => '1',
            'term_label' => 'First Semester',
        ]);
        $offering = TermOffering::factory()->for($fixture['term'])->for($curriculumEntry)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);

        CourseEnrollment::query()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusDropped,
            'units_snapshot' => '3.00',
            'added_at' => now()->subDays(2),
            'dropped_at' => now()->subDay(),
        ]);

        return $course->code;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function addLaboratoryMeeting(array $fixture): void
    {
        $labComponent = CourseComponent::factory()->for($fixture['specification'])->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => '2.00',
        ]);
        $labDemand = SchedulingDemand::factory()
            ->for($fixture['offering'])
            ->for($labComponent)
            ->for($fixture['group'])
            ->create(['modality' => TermOffering::ModalityFaceToFace]);
        $faculty = User::factory()->create(['name' => 'Teacher Two', 'status' => User::StatusActive]);
        $room = Room::factory()->create(['code' => fake()->unique()->bothify('LAB###')]);
        $labMeeting = SectionMeeting::query()->create([
            'schedule_run_id' => $fixture['run']->id,
            'scheduling_demand_id' => $labDemand->id,
            'meeting_sequence' => 2,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 3,
            'starts_at' => '13:00:00',
            'ends_at' => '15:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $fixture['course_enrollment']->id,
            'section_meeting_id' => $labMeeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);
    }

    /**
     * @return array<string, mixed>
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
        $term = Term::factory()->create([
            'label' => 'First Semester 2026-2027',
            'state' => Term::StateActive,
        ]);
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
        $lectureComponent = CourseComponent::factory()->for($specification)->create([
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
            'input_hash' => hash('sha256', uniqid('tal88a', true)),
            'solver_version' => 'tal88a-test',
            'published_by' => $this->staff(User::StaffRoleRegistrar)->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($lectureComponent)
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
            'authority' => 'TAL-88A fixture',
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
            'program' => $program,
            'term' => $term,
            'enrollment' => $enrollment,
            'course' => $course,
            'course_code' => $course->code,
            'specification' => $specification,
            'lecture_component' => $lectureComponent,
            'curriculum_entry' => $curriculumEntry,
            'offering' => $offering,
            'section' => $section,
            'group' => $group,
            'run' => $run,
            'course_enrollment' => $courseEnrollment,
            'assessment' => $assessment,
            'fee_rule' => $feeRule,
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

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
