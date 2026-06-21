<?php

namespace Tests\Feature;

use App\Actions\StudentHub\StudentDashboardService;
use App\Enums\GradeCorrectionStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\FaqEntry;
use App\Models\Grade;
use App\Models\GradeCorrection;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Program;
use App\Models\PromissoryNote;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\ServiceRequest;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_contract_aggregates_student_owned_records(): void
    {
        $user = User::factory()->create([
            ...User::staffNamePayload('Maria', null, 'Santos'),
            'status' => User::StatusActive,
        ]);
        $program = Program::factory()->create([
            'name' => 'Bachelor of Science in Information Technology',
            'code' => 'BSIT',
            'department' => 'college',
        ]);
        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'student_id' => 'SIA-2026-0001',
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'modality' => 'online',
            'current_balance' => '6500.00',
            'hard_copy_received' => false,
        ]);
        $term = Term::factory()->create(['term_name' => '1st Semester AY 2026-2027']);
        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'name' => 'BSIT 1A',
            'room' => 'R-101',
            'modality' => 'online',
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'section_id' => $section->id,
            'status' => 'officially_enrolled',
            'student_type' => 'regular',
            'year_level' => '1st Year',
            'modality' => 'online',
            'officially_enrolled_at' => now(),
        ]);
        $subject = Subject::factory()->create([
            'code' => 'IT101',
            'description' => 'Introduction to Computing',
            'units' => '3.00',
        ]);
        $faculty = User::factory()->create(User::staffNamePayload('Prof.', null, 'Reyes'));

        SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'Online',
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => 'online',
            'committed_by' => User::factory()->create()->id,
            'committed_at' => now(),
        ]);

        $enrollmentSubject = EnrollmentSubject::query()->create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'units' => '3.00',
            'lec_hours' => '3.00',
            'status' => 'enrolled',
            'is_dropped' => false,
        ]);
        $grade = Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'enrollment_subject_id' => $enrollmentSubject->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'faculty_id' => $faculty->id,
            'prelim_grade' => '87.00',
            'midterm_grade' => '88.00',
            'final_grade' => '90.00',
            'grade' => '1.75',
            'remarks' => 'passed',
            'is_inc' => false,
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);

        LedgerEntry::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'entry_type' => 'assessment',
            'description' => 'Tuition Fee',
            'amount' => '10000.00',
            'running_balance' => '10000.00',
            'posted_at' => now()->subDays(2),
        ]);
        LedgerEntry::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'entry_type' => 'payment',
            'description' => 'Accounting-confirmed payment',
            'amount' => '-3500.00',
            'running_balance' => '6500.00',
            'posted_at' => now()->subDay(),
        ]);
        Payment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'amount' => '3500.00',
            'status' => 'confirmed',
        ]);
        PromissoryNote::query()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'amount' => '3000.00',
            'due_date' => now()->addMonth()->toDateString(),
            'status' => 'approved',
            'reason' => 'Payment arrangement',
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now(),
        ]);

        ServiceRequest::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'category' => 'registrar',
            'sub_type' => 'drop_form',
            'status' => ServiceRequest::StatusUnderReview,
        ]);
        GradeCorrection::factory()->create([
            'user_id' => $user->id,
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'term_id' => $term->id,
            'status' => GradeCorrectionStatus::Submitted,
        ]);
        FaqEntry::query()->create([
            'question' => 'How do I view my balance?',
            'answer' => 'Open Financials.',
            'category' => FaqEntry::CategoryPaymentsFees,
            'sort_order' => 1,
            'is_published' => true,
        ]);
        FaqEntry::query()->create([
            'question' => 'Hidden draft',
            'answer' => 'Not visible.',
            'category' => FaqEntry::CategoryGeneral,
            'sort_order' => 2,
            'is_published' => false,
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'student.dashboard.test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Payment Confirmed', 'body' => 'Your payment was posted.']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dashboard = app(StudentDashboardService::class)->forStudent($studentProfile);

        $this->assertSame('SIA-2026-0001', $dashboard['profile']['student_id']);
        $this->assertSame('Maria Santos', $dashboard['profile']['name']);
        $this->assertSame('officially_enrolled', $dashboard['enrollment']['current']['status']);
        $this->assertSame('IT101', $dashboard['schedule']['current'][0]['subject_code']);
        $this->assertSame('Monday', $dashboard['schedule']['current'][0]['day_label']);
        $this->assertSame('08:00-10:00', $dashboard['schedule']['current'][0]['time_label']);
        $this->assertSame('Prof. Reyes', $dashboard['schedule']['current'][0]['faculty_name']);
        $this->assertSame('6500.00', $dashboard['financials']['current_balance']);
        $this->assertSame('10000.00', $dashboard['financials']['term_summaries'][0]['total_assessment']);
        $this->assertSame('3500.00', $dashboard['financials']['term_summaries'][0]['total_paid']);
        $this->assertSame('6500.00', $dashboard['financials']['term_summaries'][0]['remaining_balance']);
        $this->assertSame(['financial_balance', 'hard_copy_missing', 'active_promissory'], array_column($dashboard['holds'], 'code'));
        $this->assertSame('IT101', $dashboard['grades']['terms'][0]['grades'][0]['subject_code']);
        $this->assertSame('1.75', $dashboard['grades']['terms'][0]['grades'][0]['grade']);
        $this->assertSame(ServiceRequest::StatusUnderReview, $dashboard['requests']['service_requests'][0]['status']);
        $this->assertSame(GradeCorrectionStatus::Submitted->value, $dashboard['requests']['grade_corrections'][0]['status']);
        $this->assertSame('How do I view my balance?', $dashboard['help']['faq_entries'][0]['question']);
        $this->assertSame('Payment Confirmed', $dashboard['notifications'][0]['title']);
        $this->assertSame('dashboard_ready', $dashboard['summary']['status']);
    }

    public function test_dashboard_does_not_leak_other_students_records(): void
    {
        $studentProfile = StudentProfile::factory()->create();
        $otherStudent = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $ownEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
        ]);
        $otherEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $otherStudent->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
        ]);
        $ownSubject = Subject::factory()->create(['code' => 'OWN101']);
        $otherSubject = Subject::factory()->create(['code' => 'OTH101']);

        $this->finalizedGrade($ownEnrollment, $ownSubject, '2.00');
        $this->finalizedGrade($otherEnrollment, $otherSubject, '1.00');
        LedgerEntry::factory()->create([
            'student_profile_id' => $otherStudent->id,
            'term_id' => $term->id,
            'enrollment_id' => $otherEnrollment->id,
            'amount' => '9999.00',
            'running_balance' => '9999.00',
        ]);

        $dashboard = app(StudentDashboardService::class)->forStudent($studentProfile);

        $this->assertSame(['OWN101'], array_column($dashboard['grades']['terms'][0]['grades'], 'subject_code'));
        $this->assertSame([], $dashboard['financials']['term_summaries']);
    }

    public function test_dashboard_returns_stable_empty_sections_without_enrollment(): void
    {
        $studentProfile = StudentProfile::factory()->create([
            'current_balance' => '0.00',
            'hard_copy_received' => true,
        ]);

        $dashboard = app(StudentDashboardService::class)->forStudent($studentProfile);

        $this->assertNull($dashboard['enrollment']['current']);
        $this->assertSame([], $dashboard['schedule']['current']);
        $this->assertSame([], $dashboard['grades']['terms']);
        $this->assertSame([], $dashboard['financials']['term_summaries']);
        $this->assertSame([], $dashboard['holds']);
        $this->assertSame('no_current_enrollment', $dashboard['summary']['status']);
    }

    private function finalizedGrade(Enrollment $enrollment, Subject $subject, string $grade): Grade
    {
        $enrollmentSubject = EnrollmentSubject::query()->create([
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'units' => (string) $subject->units,
            'lec_hours' => (string) $subject->lec_hours,
            'status' => 'enrolled',
            'is_dropped' => false,
        ]);

        return Grade::query()->create([
            'enrollment_id' => $enrollment->id,
            'enrollment_subject_id' => $enrollmentSubject->id,
            'subject_id' => $subject->id,
            'term_id' => $enrollment->term_id,
            'prelim_grade' => '85.00',
            'midterm_grade' => '86.00',
            'final_grade' => '87.00',
            'grade' => $grade,
            'remarks' => 'passed',
            'is_inc' => false,
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);
    }
}
