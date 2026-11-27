<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetParser;
use App\Actions\Cor\BuildCorOutput;
use App\Actions\Scheduling\BuildOfficialScheduleOutput;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\AssessmentObligation;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue93PrintOutputsStandardsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([
            'student',
            'applicant',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    #[Test]
    public function official_output_layout_and_transcript_outputs_follow_print_and_hygiene_standards(): void
    {
        $layoutHtml = view('components.official-output-layout', [
            'title' => 'Sample Output',
            'slot' => '<p>Body</p>',
        ])->render();

        $this->assertStringContainsString('fonts/filament/filament/inter/index.css', $layoutHtml);
        $this->assertStringContainsString("var(--tala-font-body, 'Inter Variable'", $layoutHtml);

        $transcriptRequest = new TranscriptRequest;
        $transcriptRequest->id = 42;
        $transcriptRequest->external_request_reference = 'TOR-REQ-42';

        $reviewHtml = view('outputs.transcript-review', [
            'transcriptRequest' => $transcriptRequest,
            'documentReference' => 'TOR-2026-042',
            'status' => 'OFFICIAL',
            'confirmationAction' => null,
            'documentHtml' => '<html><body><p>TOR</p></body></html>',
        ])->render();

        $this->assertStringContainsString('@media print', $reviewHtml);
        $this->assertStringContainsString('.tor-review-bar, .tor-review-note { display:none !important; }', $reviewHtml);

        $torHtml = view('outputs.tala-standard-tor-v2', [
            'status' => 'OFFICIAL',
            'institution' => [
                'name' => 'Servitech Institute Asia',
                'address' => '123 Campus Rd',
                'phone' => null,
                'facebook_url' => 'https://facebook.com/internal-leak',
            ],
            'document' => [
                'reference' => 'TOR-2026-042',
                'generated_at' => 'July 1, 2026',
                'template_version' => 'v2',
                'generation_reference' => 'GEN-1',
            ],
            'student' => [
                'legal_name' => 'Dela Cruz, Juan',
                'student_number' => '2026-0001',
                'program' => 'BSIT',
                'curriculum_version' => 'BSIT 2026',
                'admission_basis' => 'Freshman',
                'admission_date' => '2026-06-01',
                'prior_school_or_credit' => null,
            ],
            'academic_years' => [],
            'conferral' => [],
            'certification' => [
                'statement' => 'Certified true record.',
                'signatory_name' => 'Registrar',
                'signatory_title' => 'School Registrar',
            ],
        ])->render();

        $this->assertStringNotContainsString('facebook.com/internal-leak', $torHtml);
    }

    #[Test]
    public function unofficial_record_hides_raw_version_code_and_class_roster_uses_portrait_with_signatories(): void
    {
        config(['institution.address' => '99 Servitech Campus Blvd']);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $ay = AcademicYear::factory()->create(['label' => '2026-2027']);
        $term = Term::factory()->for($ay)->create([
            'label' => 'First Semester',
            'type' => Term::TypeFirstSemester,
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'RAW-INTERNAL-VC-99',
            'name' => 'BSIT Standard Curriculum 2026',
            'state' => CurriculumVersion::StateActive,
        ]);
        $curriculum->academicYears()->attach($ay->id, ['program_id' => $program->id]);

        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $student = StudentProfile::factory()->for($studentUser)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'student_number' => '2026-00123',
        ]);

        $unofficialHtml = view('outputs.unofficial-student-record', [
            'student' => $student,
            'recordPeriods' => [[
                'id_slug' => 'term-1',
                'heading' => 'First Semester · 2026-2027',
                'label' => 'First Semester',
                'rows' => [],
                'average' => ['label' => 'Term GWA', 'value' => '1.75', 'reason' => null],
            ]],
            'cumulative' => ['value' => '1.75', 'through' => 'First Semester', 'reason' => null],
            'curriculum' => ['completed_units' => 3, 'deficiency_count' => 0],
            'asOf' => now('Asia/Manila'),
        ])->render();

        $this->assertStringContainsString('BSIT Standard Curriculum 2026', $unofficialHtml);
        $this->assertStringContainsString('Batch 2026-2027', $unofficialHtml);
        $this->assertStringNotContainsString('RAW-INTERNAL-VC-99', $unofficialHtml);
        $this->assertStringContainsString('<caption class="tala-sr-only">', $unofficialHtml);

        $faculty = User::factory()->create(['name' => 'Prof. Clara', 'status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $course = Course::factory()->create(['code' => 'IT 101']);
        $spec = CourseSpecification::factory()->for($course)->create(['title' => 'Intro to Computing', 'state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create(['code' => 'BSIT-1A']);
        $roster = GradeRoster::factory()->for($offering, 'termOffering')->for($section)->create([
            'faculty_user_id' => $faculty->id,
        ]);
        $roster->load(['rows', 'termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course', 'section', 'faculty']);

        $rosterHtml = view('outputs.class-roster', [
            'roster' => $roster,
            'generatedAt' => now('Asia/Manila'),
        ])->render();

        $this->assertStringContainsString('size: A4 portrait', $rosterHtml);
        $this->assertStringNotContainsString('size: A4 landscape', $rosterHtml);
        $this->assertStringContainsString('99 Servitech Campus Blvd', $rosterHtml);
        $this->assertStringContainsString('AY 2026-2027 · First Semester', $rosterHtml);
        $this->assertStringContainsString('Verified by · Assigned Faculty', $rosterHtml);
        $this->assertStringContainsString('Office of the Registrar', $rosterHtml);
    }

    #[Test]
    public function application_acknowledgment_hides_internal_ids_and_formats_state_labels(): void
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $ay = AcademicYear::factory()->create();
        $term = Term::factory()->for($ay)->create(['label' => 'First Semester 2026-2027']);
        $program = Program::factory()->create(['name' => 'Diploma in IT']);
        $cycle = AdmissionCycle::factory()->create([
            'term_id' => $term->id,
            'label' => 'AY 2026 Intake',
        ]);
        $set = AdmissionRequirementSet::factory()->create(['version' => 1]);
        $req = AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id, 'label' => 'Form 138']);
        $app = AdmissionApplication::factory()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
        ]);
        $version = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $app->id,
            'admission_requirement_set_id' => $set->id,
            'snapshot' => [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'application_path' => AdmissionApplication::PathFirstYear,
                'application_state_at_submission' => 'UNDER_REVIEW',
                'term_id' => $term->id,
                'program_id' => $program->id,
                'admission_cycle_id' => $cycle->id,
                'requirements' => [[
                    'label' => $req->label,
                    'applicant_instructions' => 'Bring original',
                    'due_stage' => 'preliminary_review',
                    'official_submission_method' => 'physical',
                    'preliminary_result' => 'accepted_as_preliminary_evidence',
                    'evidence' => ['id' => 98765],
                ]],
            ],
        ]);
        $app->update(['current_submission_version_id' => $version->id]);

        $this->actingAs($applicant)
            ->get(route('admissions.application.acknowledgment', ['application' => $app, 'version' => $version]))
            ->assertOk()
            ->assertSee('AY 2026 Intake')
            ->assertSee('First Semester 2026-2027')
            ->assertSee('Diploma in IT')
            ->assertSee('Under Review')
            ->assertSee('Document submitted')
            ->assertDontSee('Evidence 98765')
            ->assertDontSee('Term record #')
            ->assertDontSee('Program record #')
            ->assertDontSee('UNDER_REVIEW');
    }

    #[Test]
    public function finance_and_cor_outputs_gate_internal_references_to_authorized_staff(): void
    {
        $accounting = User::factory()->create(['name' => 'Acct Officer', 'status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);
        $registrar = User::factory()->create(['name' => 'Reg Officer', 'status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $studentUser = User::factory()->create(['name' => 'Maria Santos', 'status' => User::StatusActive]);
        $studentUser->assignRole('student');

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $ay = AcademicYear::factory()->create();
        $term = Term::factory()->for($ay)->create(['label' => 'First Semester']);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['name' => 'BSIT 2026']);
        $profile = StudentProfile::factory()->for($studentUser)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'student_number' => '2026-77889',
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'credential_user_id' => $studentUser->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'credential_user_id' => $studentUser->id,
        ]);
        $assessment = Assessment::factory()->create([
            'term_account_id' => $account->id,
            'enrollment_id' => $enrollment->id,
            'state' => Assessment::StateActive,
            'subtotal' => '5000.00',
            'total' => '5000.00',
        ]);
        AssessmentLine::query()->create([
            'assessment_id' => $assessment->id,
            'source_line_key' => 'tuition',
            'description_snapshot' => 'Tuition',
            'quantity' => '1.0000',
            'rate' => '5000.00',
            'amount' => '5000.00',
            'line_type' => 'tuition',
        ]);
        $obligation = AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'label' => 'Tuition fee',
            'amount' => '5000.00',
            'due_at' => now()->addWeek(),
        ]);
        $payment = Payment::factory()->create([
            'term_account_id' => $account->id,
            'student_profile_id' => $profile->id,
            'state' => Payment::StatePosted,
            'evidence_status' => 'verified',
            'method' => 'cash',
            'channel' => 'cash',
            'amount' => '5000.00',
            'verification_basis' => 'manual_cashier_verification',
            'provider_reference' => 'INTERNAL-PROV-REF-555',
            'verified_by' => $accounting->id,
            'paid_at' => now(),
            'verified_at' => now(),
        ]);
        PaymentAllocation::factory()->create([
            'payment_id' => $payment->id,
            'assessment_obligation_id' => $obligation->id,
            'assessment_line_id' => null,
            'amount' => '5000.00',
        ]);

        $this->actingAs($studentUser)
            ->get(route('finance.statement', $assessment))
            ->assertOk()
            ->assertDontSee('TERM-ACCOUNT-'.$account->id)
            ->assertDontSee('INTERNAL-PROV-REF-555');

        $this->actingAs($accounting)
            ->get(route('finance.statement', $assessment))
            ->assertOk()
            ->assertSee('TERM-ACCOUNT-'.$account->id)
            ->assertSee('INTERNAL-PROV-REF-555');

        $this->actingAs($studentUser)
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk()
            ->assertSee('2026-77889')
            ->assertSee('BS Information Technology')
            ->assertSee('Manual Cashier Verification')
            ->assertDontSee('TERM-ACCOUNT-'.$account->id)
            ->assertDontSee('INTERNAL-PROV-REF-555');

        $this->actingAs($accounting)
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk()
            ->assertSee('TERM-ACCOUNT-'.$account->id)
            ->assertSee('INTERNAL-PROV-REF-555')
            ->assertSee('Manual Cashier Verification')
            ->assertSee($accounting->getFilamentName());

        $corBase = [
            'available' => true,
            'subjects' => [],
            'fees' => [['label' => 'Tuition', 'amount' => '5000.00']],
            'summary' => [
                'document_status' => 'Current',
                'registration_date' => 'Jul 1, 2026',
                'student_name' => 'Santos, Maria',
                'student_number' => '2026-77889',
                'program_name' => 'BS Information Technology',
                'program_code' => 'BSIT',
                'curriculum_levels' => '1',
                'term' => 'First Semester',
                'total_units' => '3.00',
                'assessment_total' => '5000.00',
                'accounting_review_pending' => false,
                'enrollment_id' => $enrollment->id,
                'cor_version' => 1,
                'issued_by' => 'Reg Officer',
                'issued_at' => now()->toIso8601String(),
                'curriculum_version' => 'BSIT 2026',
                'selection_basis' => 'STANDARD_CURRICULUM',
                'course_delivery_mix' => 'Published timetable version #1',
                'term_account_reference' => 'TERM-ACCOUNT-'.$account->id,
                'payment_status' => 'Paid',
                'assessment_reference' => 'ASSESSMENT-'.$assessment->id,
                'assessment_basis' => 'PUBLISHED_FEE_PLAN',
                'assessment_authority_reference' => 'AUTH-001',
                'assessment_authority_date' => null,
            ],
        ];

        $studentCorHtml = view('cor.print', ['cor' => [...$corBase, 'copy_context' => BuildCorOutput::CopyStudent]])->render();
        $this->assertStringContainsString('COR-'.$enrollment->id.'-1', $studentCorHtml);
        $this->assertStringContainsString('Curriculum', $studentCorHtml);
        $this->assertStringNotContainsString('Subject selection', $studentCorHtml);
        $this->assertStringNotContainsString('Timetable source', $studentCorHtml);
        $this->assertStringNotContainsString('Assessment basis', $studentCorHtml);
        $this->assertStringNotContainsString('TERM-ACCOUNT-'.$account->id, $studentCorHtml);
        $this->assertStringNotContainsString('ASSESSMENT-'.$assessment->id, $studentCorHtml);
        $this->assertStringNotContainsString('AUTH-001', $studentCorHtml);

        $staffCorHtml = view('cor.print', ['cor' => [...$corBase, 'copy_context' => BuildCorOutput::CopyRegistrar]])->render();
        $this->assertStringContainsString('Curriculum record', $staffCorHtml);
        $this->assertStringContainsString('Timetable source', $staffCorHtml);
        $this->assertStringContainsString('TERM-ACCOUNT-'.$account->id, $staffCorHtml);
        $this->assertStringContainsString('ASSESSMENT-'.$assessment->id, $staffCorHtml);
    }

    #[Test]
    public function schedules_and_published_timetable_prints_include_totals_portrait_signatories_and_clean_footer(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $faculty = User::factory()->create(['name' => 'Prof. Cruz', 'status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $studentUser = User::factory()->create(['name' => 'Student One', 'status' => User::StatusActive]);
        $studentUser->assignRole('student');

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $ay = AcademicYear::factory()->create(['label' => '2026-2027']);
        $term = Term::factory()->for($ay)->create([
            'label' => 'First Semester',
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create();
        $profile = StudentProfile::factory()->for($studentUser)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'student_number' => '2026-00999',
        ]);
        $course = Course::factory()->create(['code' => 'IT 101']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Programming 1',
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateActive,
        ]);
        $comp = CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => '3.00',
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['code' => 'BSIT-1A']);
        $group = SectionDeliveryGroup::factory()->create(['section_id' => $section->id]);
        $demand = SchedulingDemand::factory()->for($offering)->for($comp)->for($group)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $room = Room::factory()->create(['code' => 'RM-101']);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
            'diagnostics' => ['solver_result' => ['generated_at' => '2026-08-10T09:30:00Z']],
        ]);
        $version = PublishedTimetableVersion::factory()->for($term)->for($run, 'scheduleRun')->create([
            'version' => 2,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_at' => now(),
        ]);
        SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'meeting_sequence' => 1,
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'credential_user_id' => $studentUser->id,
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'state' => RegistrationProposalVersion::StateConfirmed,
            'published_timetable_version_id' => $version->id,
            'curriculum_version_id' => $curriculum->id,
        ]);
        $cor = CorVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'registration_proposal_version_id' => $proposal->id,
            'published_timetable_version_id' => $version->id,
        ]);
        $enrollment->update([
            'current_proposal_version_id' => $proposal->id,
            'current_cor_version_id' => $cor->id,
        ]);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'published_timetable_version_id' => $version->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
        ]);

        $studentResponse = $this->actingAs($studentUser)->get(route('student.schedule.print'));
        $studentResponse->assertOk()
            ->assertSee('size: A4 portrait', escape: false)
            ->assertSee('2026-00999')
            ->assertSee('BS Information Technology')
            ->assertSee('Total units:')
            ->assertSee('3.00')
            ->assertSee('3 hrs/wk')
            ->assertSee('Enrolled subjects and class schedule')
            ->assertSee('<th scope="col">Modality</th>', escape: false)
            ->assertSee('<th scope="row"', escape: false)
            ->assertSee('Student copy')
            ->assertDontSee('<th scope="col">Class</th>', escape: false)
            ->assertDontSee('Check Class schedule in the Student Hub')
            ->assertDontSee('Schedule source:')
            ->assertDontSee('Generated through TALA')
            ->assertDontSee('Asia/Manila');

        $facultyResponse = $this->actingAs($faculty)->get(route('faculty.schedule.print', ['timetable_version' => $version->id]));
        $facultyResponse->assertOk()
            ->assertSee('Total units:')
            ->assertSee('3.00')
            ->assertSee('3 hrs/wk')
            ->assertSee('Generated through TALA');

        $timetableResponse = $this->actingAs($registrar)->get(route('timetable.version.print', $version));
        $timetableResponse->assertOk()
            ->assertSee('Aug 17, 2026 – Dec 18, 2026')
            ->assertSee('Prepared by (Registrar)')
            ->assertSee('Academic Head')
            ->assertSee('Published version v2')
            ->assertDontSee('TALA-TT-'.$version->id)
            ->assertDontSee('Schedule built');
    }

    #[Test]
    public function student_schedule_print_merges_multi_slot_subjects_uses_modality_header_shows_breaks_and_omits_boilerplate(): void
    {
        $faculty = User::factory()->create(['name' => 'Prof. Elena Santos', 'status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $studentUser = User::factory()->create(['name' => 'Juan Dela Cruz', 'status' => User::StatusActive]);
        $studentUser->assignRole('student');

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $ay = AcademicYear::factory()->create(['label' => '2026-2027']);
        $term = Term::factory()->for($ay)->create([
            'label' => 'First Semester',
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
        ]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            TermTeachingGridRow::query()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [
                    ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
                ],
            ]);
        }

        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $profile = StudentProfile::factory()->for($studentUser)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'student_number' => '2026-00421',
        ]);
        $course = Course::factory()->create(['code' => 'IT 204']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Data Structures and Algorithms',
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateActive,
        ]);
        $comp = CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => '3.00',
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['code' => 'Q']);
        $group = SectionDeliveryGroup::factory()->create(['section_id' => $section->id]);
        $demand = SchedulingDemand::factory()->for($offering)->for($comp)->for($group)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $room = Room::factory()->create(['code' => 'RM-204']);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
        ]);
        $version = PublishedTimetableVersion::factory()->for($term)->for($run, 'scheduleRun')->create([
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_at' => now(),
        ]);

        PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'meeting_sequence' => 1,
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 3,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'meeting_sequence' => 2,
            'modality' => TermOffering::ModalityFaceToFace,
        ]);

        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'credential_user_id' => $studentUser->id,
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $cor = CorVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'published_timetable_version_id' => $version->id,
        ]);
        $enrollment->update(['current_cor_version_id' => $cor->id]);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'published_timetable_version_id' => $version->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
        ]);

        $output = app(BuildOfficialScheduleOutput::class)->forStudent(
            $studentUser,
            Request::create('/outputs/schedules/student'),
        );

        $this->assertCount(1, $output['rows']);
        $this->assertSame('Mon 8:00–9:30 AM, Wed 8:00–9:30 AM', $output['rows'][0]['schedule']);
        $this->assertSame('RM-204', $output['rows'][0]['room']);
        $this->assertSame($faculty->fresh()->name, $output['rows'][0]['faculty']);
        $this->assertCount(1, $output['breaks']);
        $this->assertSame('Mon–Fri: 12:00 PM–1:00 PM', $output['breaks'][0]['summary']);

        $response = $this->actingAs($studentUser)->get(route('student.schedule.print'));
        $response->assertOk()
            ->assertSeeInOrder(['2026-00421', $studentUser->fresh()->name, 'BS Information Technology'])
            ->assertSee('<th scope="col">Modality</th>', escape: false)
            ->assertSee('Mon 8:00–9:30 AM, Wed 8:00–9:30 AM')
            ->assertSee('RM-204')
            ->assertSee('Break')
            ->assertSee('Mon–Fri: 12:00 PM–1:00 PM')
            ->assertSee('Student copy')
            ->assertDontSee('<th scope="col">Class</th>', escape: false)
            ->assertDontSee('<div>Q<br>', escape: false)
            ->assertDontSee('Generated ')
            ->assertDontSee('Check Class schedule in the Student Hub')
            ->assertDontSee('Schedule source:')
            ->assertDontSee('Asia/Manila');
    }

    #[Test]
    public function curriculum_xlsx_export_adds_summary_sheet_while_preserving_round_trip_sheet_zero(): void
    {
        $ay = AcademicYear::factory()->create(['label' => '2026-2027']);
        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'BSIT-2026',
            'name' => 'BSIT 2026 Revised',
            'state' => CurriculumVersion::StateActive,
        ]);
        $curriculum->academicYears()->attach($ay->id, ['program_id' => $program->id]);

        $course = Course::factory()->create(['code' => 'IT 101']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Intro to Computing',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);

        $exportService = app(CurriculumSpreadsheetExportService::class);
        $xlsxPath = $exportService->exportCurriculum($curriculum->fresh(), 'xlsx');
        $this->tempPaths[] = $xlsxPath;

        $reader = new XlsxReader;
        $reader->open($xlsxPath);
        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($cell): mixed => $cell->getValue(), $row->getCells());
                }
                $sheets[$sheet->getName()] = $rows;
            }
        } finally {
            $reader->close();
        }

        $this->assertArrayHasKey('BSIT', $sheets);
        $this->assertArrayHasKey('Summary', $sheets);
        $this->assertSame(CurriculumSpreadsheetExportService::curriculumHeaders(), $sheets['BSIT'][0]);
        $this->assertSame('IT 101', $sheets['BSIT'][1][0]);

        $summaryMap = collect($sheets['Summary'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1] ?? null]);
        $this->assertSame('BS Information Technology (BSIT)', $summaryMap->get('Program'));
        $this->assertSame('BSIT 2026 Revised', $summaryMap->get('Curriculum Name'));
        $this->assertSame('2026-2027', $summaryMap->get('Assigned Batches'));
        $this->assertSame('Active', $summaryMap->get('Status'));

        // Verify parser still selects canonical Sheet 0 automatically without disruption.
        $parser = app(CurriculumSpreadsheetParser::class);
        $workbook = $parser->inspectWorkbook($xlsxPath);
        $parsedRows = $parser->parseRows($xlsxPath, $workbook['default_sheet_index']);
        $this->assertSame(0, $workbook['default_sheet_index']);
        $this->assertCount(1, $parsedRows);
        $this->assertSame('IT 101', $parsedRows[0]['code']);
    }
}
