<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\DocumentRequest;
use App\Models\DocumentUpload;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\FaqEntry;
use App\Models\FeeTemplate;
use App\Models\Grade;
use App\Models\GradeCorrection;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\Room;
use App\Models\Section;
use App\Models\ServiceRequest;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PreUatScenarioSeeder extends Seeder
{
    private const TermName = 'Pre-UAT 1st Semester AY 2026-2027';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('PreUatScenarioSeeder is local/UAT-only and must not run in production.');
        }

        $this->call(DatabaseSeeder::class);

        $now = CarbonImmutable::now(config('app.timezone'));
        $academicYearId = $this->academicYearId($now);

        $registrar = User::query()->where('email', 'registrar@tala.edu')->firstOrFail();
        $accounting = User::query()->where('email', 'accounting@tala.edu')->firstOrFail();
        $faculty = User::query()->where('email', 'faculty@tala.edu')->firstOrFail();
        $studentUser = User::query()->where('email', 'student@tala.edu')->firstOrFail();

        $program = Program::query()->updateOrCreate(
            ['code' => 'BSIT'],
            [
                'name' => 'Bachelor of Science in Information Technology',
                'department' => 'college',
                'is_active' => true,
            ],
        );

        $subjects = $this->subjects();

        $curriculum = Curriculum::query()->updateOrCreate(
            [
                'program_id' => $program->id,
                'effective_year' => '2026',
                'version_name' => 'Pre-UAT BSIT 2026',
            ],
            [
                'is_active' => true,
                'activated_at' => $now,
            ],
        );

        foreach ($subjects as $index => $subject) {
            CurriculumSubject::query()->updateOrCreate(
                [
                    'curriculum_id' => $curriculum->id,
                    'subject_id' => $subject->id,
                    'year_level' => '1st Year',
                    'semester' => '1st Semester',
                ],
                [
                    'weekly_contact_hours' => '3.00',
                    'academic_subject_type' => $subject->subject_type === 'general_education'
                        ? CurriculumSubject::AcademicSubjectTypeMinor
                        : CurriculumSubject::AcademicSubjectTypeMajor,
                    'scheduling_group' => CurriculumSubject::SchedulingGroupLecture,
                    'delivery_rule_override' => null,
                    'sort_order' => $index + 1,
                ],
            );
        }
        CurriculumReadinessScope::query()->updateOrCreate(
            [
                'curriculum_id' => $curriculum->id,
                'year_level' => '1st Year',
                'curriculum_period' => '1st Semester',
            ],
            [
                'status' => CurriculumReadinessScope::StatusReadyForScheduling,
                'last_transition_by' => $registrar->id,
                'last_transition_at' => $now,
                'last_blockers' => [],
                'last_blocker_hash' => null,
                'last_transition_reason' => 'Pre-UAT seed reviewed scope.',
            ],
        );

        $term = Term::query()->updateOrCreate(
            ['term_name' => self::TermName],
            [
                'academic_year_id' => $academicYearId,
                'term_type' => 'semester',
                'is_active' => true,
                'term_start_date' => '2026-08-03',
                'term_end_date' => '2026-12-18',
                'class_start_date' => '2026-08-10',
                'class_end_date' => '2026-12-11',
                'scheduling_starts_at' => '2026-07-15 08:00:00',
                'enrollment_starts_at' => '2026-07-01 08:00:00',
                'enrollment_ends_at' => '2026-07-31 17:00:00',
                'late_enrollment_ends_at' => '2026-08-07 17:00:00',
                'payment_deadline' => '2026-08-07 17:00:00',
                'adjustment_ends_at' => '2026-08-14 17:00:00',
                'locked_at' => null,
            ],
        );

        Room::query()->updateOrCreate(
            ['code' => 'R-101'],
            [
                'name' => 'Pre-UAT Room 101',
                'building' => 'Main',
                'capacity' => Section::MaxRescueSeats,
                'is_active' => true,
            ],
        );

        $section = Section::query()->updateOrCreate(
            [
                'term_id' => $term->id,
                'program_id' => $program->id,
                'name' => 'BSIT-1A',
            ],
            [
                'curriculum_id' => $curriculum->id,
                'year_level' => '1st Year',
                'curriculum_period' => '1st Semester',
                'room' => 'R-101',
                'max_seats' => Section::MaxRescueSeats,
                'enrolled_count' => 1,
                'modality' => 'on_site',
            ],
        );

        foreach ($subjects as $subject) {
            FacultySubjectEligibility::query()->updateOrCreate(
                [
                    'faculty_id' => $faculty->id,
                    'subject_id' => $subject->id,
                    'term_id' => $term->id,
                ],
                [
                    'status' => FacultySubjectEligibility::StatusActive,
                    'priority' => 1,
                    'max_weekly_hours' => '12.00',
                    'approved_by' => $registrar->id,
                    'approved_at' => $now,
                ],
            );
        }

        $period = FacultyAvailabilityPeriod::query()->updateOrCreate(
            ['term_id' => $term->id],
            [
                'opens_at' => '2026-07-01 08:00:00',
                'closes_at' => '2026-07-10 17:00:00',
                'status' => FacultyAvailabilityPeriod::StatusLocked,
                'created_by' => $registrar->id,
                'locked_at' => '2026-07-10 17:30:00',
            ],
        );

        $submission = FacultyAvailabilitySubmission::query()->updateOrCreate(
            [
                'term_id' => $term->id,
                'faculty_id' => $faculty->id,
                'version' => 1,
            ],
            [
                'availability_period_id' => $period->id,
                'status' => FacultyAvailabilitySubmission::StatusLocked,
                'submitted_at' => '2026-07-05 09:00:00',
                'locked_at' => '2026-07-10 17:30:00',
                'approved_by' => $registrar->id,
                'approved_at' => '2026-07-10 17:30:00',
            ],
        );

        FacultyAvailabilityWindow::query()
            ->where('submission_id', $submission->id)
            ->delete();

        foreach ([1, 3] as $dayOfWeek) {
            FacultyAvailabilityWindow::query()->create([
                'submission_id' => $submission->id,
                'day_of_week' => $dayOfWeek,
                'starts_at' => '08:00:00',
                'ends_at' => '12:00:00',
                'notes' => 'Pre-UAT locked availability window.',
            ]);
        }

        $studentProfile = StudentProfile::query()->updateOrCreate(
            ['student_id' => 'TALA-2026-0001'],
            [
                'user_id' => $studentUser->id,
                'lrn' => '123456789012',
                'education_level' => 'college',
                'program_id' => $program->id,
                'year_level' => '1st Year',
                'operational_status' => 'Active',
                'status_reason' => null,
                'modality' => 'on_site',
                'current_balance' => '6500.00',
                'hard_copy_received' => true,
                'last_status_changed_at' => $now,
            ],
        );

        $enrollment = Enrollment::query()->updateOrCreate(
            [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
            ],
            [
                'section_id' => $section->id,
                'status' => 'officially_enrolled',
                'student_type' => 'new',
                'year_level' => '1st Year',
                'modality' => 'on_site',
                'lis_status' => 'not_encoded',
                'is_late_enrollment' => false,
                'enrolled_at' => '2026-07-20 10:00:00',
                'pre_enrolled_at' => '2026-07-20 10:00:00',
                'officially_enrolled_at' => '2026-07-22 15:00:00',
            ],
        );

        foreach ($subjects as $subject) {
            EnrollmentSubject::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'subject_id' => $subject->id,
                ],
                [
                    'units' => $subject->units,
                    'lec_hours' => $subject->lec_hours,
                    'status' => 'enrolled',
                    'is_dropped' => false,
                ],
            );
        }

        FeeTemplate::query()->updateOrCreate(
            [
                'name' => 'Pre-UAT BSIT 1st Year Standard Fees',
                'education_level' => 'college',
                'program_id' => $program->id,
                'year_level' => '1st Year',
            ],
            [
                'tuition_fee' => '12000.00',
                'laboratory_fee' => '2500.00',
                'misc_fee' => '1500.00',
                'other_fee' => '500.00',
                'minimum_downpayment_percentage' => '20.00',
                'is_active' => true,
            ],
        );

        $assessment = LedgerEntry::query()->updateOrCreate(
            [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'entry_type' => 'assessment',
                'reference_type' => 'pre_uat_seed',
                'reference_id' => 1,
            ],
            [
                'description' => 'Pre-UAT enrollment assessment.',
                'amount' => '16500.00',
                'running_balance' => '16500.00',
                'posted_at' => '2026-07-20 10:30:00',
                'posted_by' => $accounting->id,
            ],
        );

        $paymentLedger = LedgerEntry::query()->updateOrCreate(
            [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'entry_type' => 'payment',
                'reference_type' => 'pre_uat_seed',
                'reference_id' => 2,
            ],
            [
                'description' => 'Pre-UAT confirmed downpayment.',
                'amount' => '-10000.00',
                'running_balance' => '6500.00',
                'posted_at' => '2026-07-22 15:00:00',
                'posted_by' => $accounting->id,
            ],
        );

        $paymentAttempt = PaymentAttempt::query()->updateOrCreate(
            [
                'provider_event_id' => 'evt_pre_uat_payment_0001',
                'provider_checkout_session_id' => 'cs_pre_uat_0001',
            ],
            [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'ledger_entry_id' => $assessment->id,
                'channel' => 'paymongo',
                'status' => 'paid',
                'provider' => 'paymongo',
                'provider_payment_id' => 'pay_pre_uat_0001',
                'provider_payment_intent_id' => 'pi_pre_uat_0001',
                'amount' => '10000.00',
                'meta' => ['seed' => 'pre_uat'],
                'paid_at' => '2026-07-22 15:00:00',
            ],
        );

        Payment::query()->updateOrCreate(
            ['payment_reference' => 'PRE-UAT-PAY-0001'],
            [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'payment_attempt_id' => $paymentAttempt->id,
                'ledger_entry_id' => $paymentLedger->id,
                'channel' => 'paymongo',
                'amount' => '10000.00',
                'status' => 'confirmed',
                'confirmed_at' => '2026-07-22 15:00:00',
                'confirmed_by' => $accounting->id,
                'meta' => ['seed' => 'pre_uat'],
            ],
        );

        DocumentUpload::query()->updateOrCreate(
            [
                'student_profile_id' => $studentProfile->id,
                'document_type' => 'report_card',
                'file_path' => 'pre-uat/report-card-sample.pdf',
            ],
            [
                'user_id' => $studentUser->id,
                'term_id' => $term->id,
                'file_disk' => 'local',
                'file_name' => 'report-card-sample.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 204800,
                'checksum' => 'pre-uat-report-card',
                'upload_status' => 'uploaded',
                'ocr_review_status' => DocumentUpload::ReviewStatusNeedsManualReview,
                'ocr_confidence' => '71.50',
                'ocr_text' => 'Pre-UAT OCR sample requiring Registrar review.',
                'ocr_processed_at' => '2026-07-19 09:00:00',
                'parser_version' => 'pre-uat',
            ],
        );

        DocumentRequest::query()->updateOrCreate(
            [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'document_type' => DocumentRequest::TypeCertificateOfEnrollment,
            ],
            [
                'status' => DocumentRequest::StatusProcessing,
                'is_free_request' => false,
                'delivery_consent' => false,
                'delivery_mode' => DocumentRequest::DeliveryModePickup,
                'created_by' => $studentUser->id,
                'updated_by' => $registrar->id,
            ],
        );

        $firstEnrollmentSubject = EnrollmentSubject::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('subject_id', $subjects[0]->id)
            ->firstOrFail();

        $grade = Grade::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'subject_id' => $subjects[0]->id,
            ],
            [
                'enrollment_subject_id' => $firstEnrollmentSubject->id,
                'term_id' => $term->id,
                'faculty_id' => $faculty->id,
                'prelim_grade' => '88.00',
                'midterm_grade' => '90.00',
                'final_grade' => null,
                'grade' => null,
                'remarks' => null,
                'is_inc' => false,
                'is_finalized' => false,
            ],
        );

        GradeCorrection::query()->updateOrCreate(
            [
                'user_id' => $studentUser->id,
                'grade_id' => $grade->id,
                'subject_id' => $subjects[0]->id,
                'term_id' => $term->id,
            ],
            [
                'assessment_component' => 'prelim',
                'current_grade' => '88.00',
                'requested_action' => 'Review prelim grade encoding evidence.',
                'reason' => 'Pre-UAT grade correction scenario.',
                'attachment_paths' => ['pre-uat/grade-correction-evidence.pdf'],
                'status' => 'submitted',
                'assigned_to' => $registrar->id,
                'creator_id' => $studentUser->id,
            ],
        );

        ServiceRequest::query()->updateOrCreate(
            [
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'category' => 'student_record',
                'sub_type' => 'profile_update',
            ],
            [
                'status' => ServiceRequest::StatusSubmitted,
                'details' => 'Pre-UAT service request scenario.',
                'attachment_paths' => ['pre-uat/service-request-evidence.pdf'],
                'assigned_to' => $registrar->id,
            ],
        );

        FaqEntry::query()->updateOrCreate(
            ['question' => 'How do I request a document during Pre-UAT?'],
            [
                'answer' => 'Use the seeded Pre-UAT document request scenario to verify Registrar and Accounting actions.',
                'category' => FaqEntry::CategoryDocumentsRequests,
                'sort_order' => 10,
                'is_published' => true,
            ],
        );

        FaqEntry::query()->updateOrCreate(
            ['question' => 'Internal Pre-UAT unpublished FAQ'],
            [
                'answer' => 'This row verifies unpublished FAQs are hidden from public and Student Hub help pages.',
                'category' => FaqEntry::CategoryGeneral,
                'sort_order' => 99,
                'is_published' => false,
            ],
        );
    }

    private function academicYearId(CarbonImmutable $now): int
    {
        DB::table('academic_years')->updateOrInsert(
            [
                'academic_year' => '2026-2027',
                'education_level' => 'college',
            ],
            [
                'school_year_start_date' => '2026-08-01',
                'school_year_end_date' => '2027-05-31',
                'status' => 'active',
                'reference_note' => 'Pre-UAT scenario seed.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return (int) DB::table('academic_years')
            ->where('academic_year', '2026-2027')
            ->where('education_level', 'college')
            ->value('id');
    }

    /**
     * @return list<Subject>
     */
    private function subjects(): array
    {
        return [
            Subject::query()->updateOrCreate(
                ['code' => 'IT101'],
                [
                    'description' => 'Introduction to Computing',
                    'units' => '3.00',
                    'lec_hours' => '3.00',
                    'department' => 'college',
                    'subject_type' => 'major',
                    'category' => null,
                ],
            ),
            Subject::query()->updateOrCreate(
                ['code' => 'MATH101'],
                [
                    'description' => 'Mathematics in the Modern World',
                    'units' => '3.00',
                    'lec_hours' => '3.00',
                    'department' => 'college',
                    'subject_type' => 'general_education',
                    'category' => null,
                ],
            ),
        ];
    }
}
