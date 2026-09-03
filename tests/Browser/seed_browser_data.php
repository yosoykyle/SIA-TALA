<?php

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require_once __DIR__.'/../../vendor/autoload.php';
    $app = require_once __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Assessment;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\OfficialOutputPaymentClearance;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\Payment;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermOffering;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Spatie\Permission\Models\Role;

echo "Seeding browser qualification data...\n";

// 1. Roles & Users
$roleUsers = [
    'system-super-admin' => ['email' => 'admin@example.test', 'first' => 'System', 'last' => 'Admin'],
    'registrar' => ['email' => 'registrar.test@example.test', 'first' => 'Reg', 'last' => 'Test'],
    'accounting' => ['email' => 'accounting.test@example.test', 'first' => 'Acct', 'last' => 'Test'],
    'faculty' => ['email' => 'faculty.test@example.test', 'first' => 'Fac', 'last' => 'Test'],
    'academic-head' => ['email' => 'ahead.test@example.test', 'first' => 'Head', 'last' => 'Test'],
    'applicant' => ['email' => 'applicant.test@example.test', 'first' => 'App', 'last' => 'Test'],
    'student' => ['email' => 'student.test@example.test', 'first' => 'Stu', 'last' => 'Test'],
];

$users = [];
foreach ($roleUsers as $role => $info) {
    Role::findOrCreate($role, 'web');
    $u = User::firstOrNew(['email' => $info['email']]);
    $u->first_name = $info['first'];
    $u->last_name = $info['last'];
    $u->name = "{$info['first']} {$info['last']}";
    $u->password = Hash::make('password');
    $u->status = User::StatusActive;
    $u->email_verified_at = now();
    $u->save();
    $u->syncRoles([$role]);

    // Configure MFA for staff users
    if (in_array($role, User::staffRoleNames(), true)) {
        $u->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $u->two_factor_recovery_codes_acknowledged_at = now();
        $u->two_factor_recovery_codes = Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code-1', 'recovery-code-2']));
        $u->save();
    }
    $users[$role] = $u;
}

$admin = $users['system-super-admin'];
$registrar = $users['registrar'];
$accounting = $users['accounting'];
$faculty = $users['faculty'];
$applicant = $users['applicant'];
$student = $users['student'];

// 2. Operational Events for System Health
OperationalEvent::query()->firstOrCreate(
    ['event_domain' => OperationalEvent::DomainNotifications, 'event_type' => 'mail_self_test_accepted'],
    [
        'integration' => OperationalEvent::IntegrationMail,
        'user_id' => $admin->id,
        'status' => OperationalEvent::StatusProcessed,
        'occurred_at' => now()->subMinutes(10),
    ]
);

OperationalEvent::query()->firstOrCreate(
    ['event_domain' => OperationalEvent::DomainOperations, 'event_type' => 'backup_completed'],
    [
        'integration' => OperationalEvent::IntegrationBackup,
        'user_id' => $admin->id,
        'status' => OperationalEvent::StatusProcessed,
        'occurred_at' => now()->subHours(2),
    ]
);

// 3. Activity Log & Governance Evidence
DB::table('activity_log')->insertOrIgnore([
    [
        'log_name' => 'default',
        'description' => 'Course catalog revised',
        'event' => 'updated',
        'causer_type' => User::class,
        'causer_id' => $admin->id,
        'properties' => json_encode(['table' => 'courses', 'action' => 'revision']),
        'created_at' => now()->subMinutes(15),
        'updated_at' => now()->subMinutes(15),
    ],
    [
        'log_name' => 'authentication',
        'description' => 'User logged in',
        'event' => 'login',
        'causer_type' => User::class,
        'causer_id' => $admin->id,
        'properties' => json_encode(['ip' => '127.0.0.1']),
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ],
    [
        'log_name' => 'authentication',
        'description' => 'Failed login attempt',
        'event' => 'login_failed',
        'causer_type' => User::class,
        'causer_id' => $admin->id,
        'properties' => json_encode(['ip' => '127.0.0.1']),
        'created_at' => now()->subMinutes(45),
        'updated_at' => now()->subMinutes(45),
    ],
]);

// 4. Output Access Logs
OutputAccessLog::query()->firstOrCreate(
    ['output_type' => 'TOR', 'action' => 'VIEW'],
    [
        'source_record_type' => 'transcript_request',
        'source_record_id' => 1,
        'actor_user_id' => $admin->id,
        'actor_role' => User::StaffRoleSystemSuperAdmin,
        'request_context' => ['channel' => 'web'],
        'stored_file_reference' => 'tor_001.pdf',
        'status' => 'VIEWED',
        'occurred_at' => now()->subMinutes(5),
    ]
);

OutputAccessLog::query()->firstOrCreate(
    ['output_type' => 'COR', 'action' => 'PRINT'],
    [
        'source_record_type' => 'registration_case',
        'source_record_id' => 1,
        'actor_user_id' => $admin->id,
        'actor_role' => User::StaffRoleSystemSuperAdmin,
        'request_context' => ['channel' => 'web'],
        'stored_file_reference' => 'cor_001.pdf',
        'status' => 'generated',
        'occurred_at' => now()->subMinutes(4),
    ]
);

// 5. Official Outputs & Class Roster Models
use App\Models\AcademicYear;

$program = Program::firstOrCreate(
    ['code' => 'BSTM'],
    ['name' => 'BS Tourism Management']
);

$curriculumVersion = CurriculumVersion::firstOrCreate(
    ['program_id' => $program->id, 'version_code' => 'BSTM-2026-V1'],
    ['name' => 'BSTM Curriculum v1', 'state' => CurriculumVersion::StateActive]
);

$academicYear = AcademicYear::firstOrCreate(
    ['label' => 'Academic Year 2026-2027'],
    [
        'starts_on' => now()->startOfYear()->toDateString(),
        'ends_on' => now()->endOfYear()->toDateString(),
        'state' => AcademicYear::StateActive,
    ]
);

$term = Term::firstOrCreate(
    ['label' => 'First Semester 2026-2027'],
    [
        'academic_year_id' => $academicYear->id,
        'type' => Term::TypeFirstSemester,
        'starts_on' => now()->subMonths(2)->toDateString(),
        'ends_on' => now()->addMonths(3)->toDateString(),
        'state' => Term::StateActive,
    ]
);

// OUT-001 (Application Acknowledgment)
$cycle = AdmissionCycle::firstOrCreate(
    ['code' => 'CYCLE-2026'],
    [
        'label' => 'AY 2026-2027 Admissions',
        'term_id' => $term->id,
        'state' => AdmissionCycle::StatePublished,
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonths(6),
    ]
);
$reqSet = AdmissionRequirementSet::firstOrCreate(
    ['admission_cycle_id' => $cycle->id, 'application_path' => AdmissionCycle::PathFirstYear, 'version' => 1],
    [
        'state' => AdmissionRequirementSet::StateDraft,
        'authority_reference' => 'REG-REQ-2026',
    ]
);
if ($reqSet->state !== AdmissionRequirementSet::StatePublished) {
    AdmissionRequirement::firstOrCreate(
        ['admission_requirement_set_id' => $reqSet->id, 'label' => 'Form 138 (Report Card)'],
        [
            'code' => 'FORM-138',
            'due_stage' => 'submission',
            'official_submission_method' => 'physical_copy',
            'applicant_instructions' => 'Submit original report card.',
            'display_order' => 1,
        ]
    );
    $reqSet->update([
        'state' => AdmissionRequirementSet::StatePublished,
        'effective_at' => now(),
        'published_by' => $registrar->id,
        'published_at' => now(),
    ]);
}
$application = AdmissionApplication::query()->where('user_id', $applicant->id)->first();
if (! $application) {
    $application = AdmissionApplication::factory()
        ->for($applicant)
        ->for($cycle)
        ->for($program)
        ->for($term)
        ->create([
            'application_reference' => 'APP-2026-0001',
            'application_state' => AdmissionApplication::StateSubmitted,
            'application_path' => AdmissionApplication::PathFirstYear,
            'first_name' => 'App',
            'last_name' => 'Test',
            'email' => $applicant->email,
            'submitted_at' => now()->subDay(),
        ]);
}
$appVersion = ApplicationSubmissionVersion::firstOrCreate(
    ['admission_application_id' => $application->id, 'version' => 1],
    [
        'admission_requirement_set_id' => $reqSet->id,
        'submitted_by' => $applicant->id,
        'submitted_at' => now()->subDay(),
        'privacy_notice_reference' => 'privacy-notice:synthetic-v1',
        'snapshot' => [
            'application_reference' => 'APP-2026-0001',
            'first_name' => 'App',
            'last_name' => 'Test',
            'admission_cycle_id' => $cycle->id,
            'admission_cycle' => ['label' => $cycle->label, 'code' => $cycle->code],
            'term_id' => $term->id,
            'term' => ['label' => $term->label],
            'program_id' => $program->id,
            'program' => ['name' => $program->name],
            'application_path' => AdmissionApplication::PathFirstYear,
            'prior_school_name' => 'Synthetic High School',
        ],
    ]
);
$application->update(['current_submission_version_id' => $appVersion->id]);

// OUT-002 (Published Timetable)
$timetableVersion = PublishedTimetableVersion::query()->where('term_id', $term->id)->first();
if (! $timetableVersion) {
    $timetableVersion = PublishedTimetableVersion::factory()
        ->for($term)
        ->create([
            'published_by' => $registrar->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
        ]);
}

// Student Profile & Enrollment
$studentProfile = StudentProfile::firstOrCreate(
    ['student_number' => 'SIA-2026-0001'],
    [
        'user_id' => $student->id,
        'program_id' => $program->id,
        'curriculum_version_id' => $curriculumVersion->id,
        'first_name' => 'Stu',
        'last_name' => 'Test',
        'birth_date' => '2004-01-01',
        'email' => $student->email,
        'lifecycle_status' => StudentProfile::LifecycleActive,
        'academic_standing' => StudentProfile::StandingRegular,
    ]
);

$enrollment = Enrollment::firstOrCreate(
    ['student_profile_id' => $studentProfile->id, 'term_id' => $term->id],
    [
        'credential_user_id' => $student->id,
        'case_reference' => 'REG-2026-0001',
        'selection_basis' => Enrollment::SelectionStandardCurriculum,
        'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
        'status' => 'officially_enrolled',
        'registered_at' => now()->subDays(3),
        'officially_enrolled_at' => now()->subDays(2),
    ]
);

// Term Account & Assessment
$termAccount = TermAccount::firstOrCreate(
    ['enrollment_id' => $enrollment->id],
    [
        'credential_user_id' => $student->id,
        'term_id' => $term->id,
        'student_profile_id' => $studentProfile->id,
    ]
);

$assessment = Assessment::firstOrCreate(
    ['enrollment_id' => $enrollment->id, 'version' => 1],
    [
        'term_account_id' => $termAccount->id,
        'state' => Assessment::StateActive,
        'currency' => 'PHP',
        'subtotal' => '9000.00',
        'discount_total' => '0.00',
        'total' => '9000.00',
        'required_downpayment' => '2000.00',
        'activated_at' => now()->subDays(2),
    ]
);

// OUT-003 (COR)
use App\Models\RegistrationProposalVersion;

$proposal = RegistrationProposalVersion::query()->where('enrollment_id', $enrollment->id)->first();
if (! $proposal) {
    $proposal = RegistrationProposalVersion::factory()->for($enrollment)->create([
        'state' => RegistrationProposalVersion::StateConfirmed,
        'published_timetable_version_id' => $timetableVersion->id,
        'curriculum_version_id' => $studentProfile->curriculum_version_id,
        'prepared_by' => $registrar->id,
    ]);
}

$corSnapshot = [
    'student_number' => $studentProfile->student_number,
    'student_name' => 'Stu Test',
    'program_id' => $program->id,
    'program_code' => $program->code,
    'term_label' => $term->label,
    'published_timetable_version_id' => $timetableVersion->id,
    'fees' => [],
    'courses' => [],
];
$corVersion = CorVersion::firstOrCreate(
    ['enrollment_id' => $enrollment->id, 'version' => 1],
    [
        'registration_proposal_version_id' => $proposal->id,
        'assessment_id' => $assessment->id,
        'published_timetable_version_id' => $timetableVersion->id,
        'snapshot' => $corSnapshot,
        'content_hash' => hash('sha256', json_encode($corSnapshot)),
        'issued_by' => $registrar->id,
        'issued_at' => now()->subDays(2),
    ]
);
$enrollment->update(['current_cor_version_id' => $corVersion->id]);

// Course, Spec, Section, Roster
$courseSpec = CourseSpecification::query()->first();
if (! $courseSpec) {
    $courseSpec = CourseSpecification::factory()->create([
        'title' => 'Introduction to Systems',
        'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
        'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
    ]);
}
$currEntry = CurriculumEntry::query()
    ->where('curriculum_version_id', $curriculumVersion->id)
    ->where('course_specification_id', $courseSpec->id)
    ->first();
if (! $currEntry) {
    $currEntry = CurriculumEntry::factory()->create([
        'curriculum_version_id' => $curriculumVersion->id,
        'course_specification_id' => $courseSpec->id,
    ]);
}
$offering = TermOffering::query()
    ->where('term_id', $term->id)
    ->where('curriculum_entry_id', $currEntry->id)
    ->first();
if (! $offering) {
    $offering = TermOffering::factory()->create([
        'term_id' => $term->id,
        'curriculum_entry_id' => $currEntry->id,
        'state' => TermOffering::StateScheduled,
    ]);
}
$section = Section::query()->where('term_offering_id', $offering->id)->first();
if (! $section) {
    $section = Section::factory()->create([
        'term_offering_id' => $offering->id,
        'code' => 'SEC-101',
        'state' => Section::StateOpen,
    ]);
}

use App\Models\PublishedTimetableMeeting;
use App\Models\Room;
use Illuminate\Contracts\Console\Kernel;

$room = Room::firstOrCreate(
    ['code' => 'RM-101'],
    ['name' => 'Room 101', 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]
);

$meeting = PublishedTimetableMeeting::query()->where('published_timetable_version_id', $timetableVersion->id)->first();
if (! $meeting) {
    $meeting = PublishedTimetableMeeting::create([
        'published_timetable_version_id' => $timetableVersion->id,
        'section_id' => $section->id,
        'faculty_user_id' => $faculty->id,
        'room_id' => $room->id,
        'meeting_sequence' => 1,
        'day_of_week' => 1,
        'starts_at' => '08:00:00',
        'ends_at' => '09:30:00',
        'modality' => 'Face to Face',
        'location_label' => 'Main Campus - RM 101',
    ]);
}
ClassOfferingTeachingAssignment::firstOrCreate(
    ['section_id' => $section->id, 'faculty_user_id' => $faculty->id],
    [
        'term_offering_id' => $offering->id,
        'role' => ClassOfferingTeachingAssignment::RoleDesignated,
        'state' => ClassOfferingTeachingAssignment::StateActive,
        'authority_reference' => 'ASSIGN-SEC-101',
        'assigned_by' => $registrar->id,
        'effective_at' => now()->subDays(5),
    ]
);
$courseEnrollment = CourseEnrollment::firstOrCreate(
    ['enrollment_id' => $enrollment->id, 'section_id' => $section->id],
    [
        'term_offering_id' => $offering->id,
        'status' => CourseEnrollment::StatusActive,
        'is_current' => true,
        'units_snapshot' => '3.00',
        'added_at' => now()->subDays(2),
    ]
);

$gradeRoster = GradeRoster::firstOrCreate(
    ['section_id' => $section->id],
    [
        'term_offering_id' => $offering->id,
        'faculty_user_id' => $faculty->id,
        'state' => GradeRoster::StateReleased,
        'grading_profile_snapshot' => config('grades.servitech_v1'),
    ]
);
$rosterRow = GradeRosterRow::firstOrCreate(
    ['grade_roster_id' => $gradeRoster->id, 'course_enrollment_id' => $courseEnrollment->id],
    [
        'final_result' => '1.75',
        'current_outcome_code' => '1.75',
        'current_outcome_category' => GradeRosterRow::CategoryPassing,
        'is_current_membership' => true,
        'released_at' => now()->subDay(),
    ]
);
$outcomeEvent = GradeOutcomeEvent::query()->where('grade_roster_row_id', $rosterRow->id)->first();
if (! $outcomeEvent) {
    $outcomeEvent = GradeOutcomeEvent::factory()->create([
        'grade_roster_row_id' => $rosterRow->id,
        'result_code' => '1.75',
        'new_value' => '1.75',
        'new_category' => 'Passing',
        'authority' => 'REG-RELEASE-001',
        'released_at' => now()->subDay(),
        'recorded_by' => $registrar->id,
    ]);
}

// OUT-007 (Payment)
$payment = Payment::firstOrCreate(
    ['term_account_id' => $termAccount->id],
    [
        'student_profile_id' => $studentProfile->id,
        'term_id' => $term->id,
        'method' => 'paymongo',
        'channel' => 'paymongo',
        'amount' => '2000.00',
        'evidence_status' => 'verified',
        'paid_at' => now()->subDay(),
        'verified_at' => now()->subDay(),
        'state' => Payment::StatePosted,
        'verification_basis' => 'IndependentSourceCheck',
        'external_check_reference' => 'SYNTH-CHECK-001',
        'provider_reference' => 'pm_test_001',
    ]
);

$conferral = DegreeConferral::query()->where('student_profile_id', $studentProfile->id)->first();
if (! $conferral) {
    $conferral = DegreeConferral::factory()
        ->for($studentProfile)
        ->create([
            'curriculum_version_id' => $curriculumVersion->id,
            'version' => 1,
            'program_name_snapshot' => $program->name,
            'degree_name' => 'Bachelor of Science in Tourism Management',
            'conferred_on' => now()->toDateString(),
            'authority_reference' => 'BOT-RES-2026-01',
            'final_evaluation_snapshot' => ['cleared' => true],
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);
}

$transcriptRequest = TranscriptRequest::query()->where('student_profile_id', $studentProfile->id)->first();
if (! $transcriptRequest) {
    $transcriptRequest = TranscriptRequest::factory()
        ->for($studentProfile)
        ->create([
            'degree_conferral_id' => $conferral->id,
            'version' => 1,
            'external_request_reference' => 'EXT-TOR-0001',
            'requested_on' => now()->toDateString(),
            'due_on' => now()->addDays(14)->toDateString(),
            'template_version' => TranscriptRequest::TemplateServitechV1,
            'signatory_name' => 'Dr. Registrar',
            'signatory_title' => 'College Registrar',
            'seal_input_type' => TranscriptRequest::SealPlacementInstruction,
            'seal_placement_instruction' => 'Affix seal in the designated certification area.',
            'state' => TranscriptRequest::StateOpen,
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);
}

OfficialOutputPaymentClearance::firstOrCreate(
    ['transcript_request_id' => $transcriptRequest->id],
    [
        'output_request_reference' => 'OUT-REQ-0001',
        'term_account_id' => $termAccount->id,
        'version' => 1,
        'state' => OfficialOutputPaymentClearance::StateNotRequired,
        'required_amount' => '0.00',
        'authority_reference' => 'CLEAR-AUTH-001',
        'safe_reason' => 'Standard institutional TOR request.',
        'decided_by' => $accounting->id,
        'decided_at' => now(),
    ]
);

// Output URL Map
$outputUrls = [
    'out001' => route('admissions.application.acknowledgment', ['application' => $application, 'version' => $appVersion], false),
    'out002' => route('timetable.version.print', ['version' => $timetableVersion], false),
    'out003' => route('cor.print', ['enrollment' => $enrollment], false),
    'out004' => route('student-academics.unofficial-record', ['student' => $studentProfile], false),
    'out005' => route('transcripts.preview', ['transcriptRequest' => $transcriptRequest], false),
    'out006' => route('finance.statement', ['assessment' => $assessment], false),
    'out007' => route('finance.payments.acknowledgement', ['payment' => $payment], false).'?print=1',
    'classRoster' => route('grade-rosters.print', ['roster' => $gradeRoster], false),
];

echo "OUTPUT_URLS_START\n";
echo json_encode($outputUrls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
file_put_contents(__DIR__.'/fixtures.json', json_encode($outputUrls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Data seeding completed successfully.\n";
