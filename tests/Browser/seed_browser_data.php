<?php

$targetDb = getenv('DB_DATABASE') ?: 'test_tala_db';
putenv("DB_DATABASE={$targetDb}");
$_ENV['DB_DATABASE'] = $targetDb;
$_SERVER['DB_DATABASE'] = $targetDb;

use Illuminate\Contracts\Console\Kernel;

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require_once __DIR__.'/../../vendor/autoload.php';
    $app = require_once __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

use Tests\Browser\BrowserQualificationEnvironment;

BrowserQualificationEnvironment::assertValidDatabase();

use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
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
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermOffering;
use App\Models\TranscriptRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Spatie\Permission\Models\Role;

echo "Seeding browser qualification data...\n";

(new DatabaseSeeder)->run();

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

$emptyApplicant = User::firstOrNew(['email' => 'applicant.empty@example.test']);
$emptyApplicant->first_name = 'Empty';
$emptyApplicant->last_name = 'Applicant';
$emptyApplicant->name = 'Empty Applicant';
$emptyApplicant->password = Hash::make('password');
$emptyApplicant->status = User::StatusActive;
$emptyApplicant->email_verified_at = now();
$emptyApplicant->save();
$emptyApplicant->syncRoles(['applicant']);

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

// 4. Output Access Logs (generated dynamically on output access)

// 5. Official Outputs & Class Roster Models

$program = Program::firstOrCreate(
    ['code' => 'BSTM'],
    ['name' => 'BS Tourism Management']
);

$curriculumVersion = CurriculumVersion::firstOrCreate(
    ['program_id' => $program->id, 'version_code' => 'BSTM-2026-V1'],
    ['name' => 'BSTM Curriculum v1', 'state' => CurriculumVersion::StateActive]
);

$ayPrevious = AcademicYear::firstOrCreate(
    ['label' => 'Academic Year 2025-2026'],
    [
        'starts_on' => now()->subYear()->startOfYear()->toDateString(),
        'ends_on' => now()->subYear()->endOfYear()->toDateString(),
        'state' => AcademicYear::StateActive,
    ]
);

$term1 = Term::firstOrCreate(
    ['label' => 'First Semester 2025-2026'],
    [
        'academic_year_id' => $ayPrevious->id,
        'type' => Term::TypeFirstSemester,
        'starts_on' => now()->subMonths(14)->toDateString(),
        'ends_on' => now()->subMonths(9)->toDateString(),
        'state' => Term::StateClosed,
    ]
);

$term2 = Term::firstOrCreate(
    ['label' => 'Second Semester 2025-2026'],
    [
        'academic_year_id' => $ayPrevious->id,
        'type' => Term::TypeSecondSemester,
        'starts_on' => now()->subMonths(8)->toDateString(),
        'ends_on' => now()->subMonths(3)->toDateString(),
        'state' => Term::StateClosed,
    ]
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

$term4 = Term::firstOrCreate(
    ['label' => 'Second Semester 2026-2027'],
    [
        'academic_year_id' => $academicYear->id,
        'type' => Term::TypeSecondSemester,
        'starts_on' => now()->addMonths(3)->toDateString(),
        'ends_on' => now()->addMonths(8)->toDateString(),
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
            'authority_reference' => 'REG-REQ-2026',
            'purpose' => 'Academic eligibility verification.',
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
    ['student_number' => 'SIA-2026-9001'],
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

$corCourses = [];
for ($i = 1; $i <= 16; $i++) {
    $dayIndex = (($i - 1) % 6) + 1;
    $startHour = 7 + ($i % 8);
    $corCourses[] = [
        'course_code' => sprintf('BSTM-%03d', 100 + $i),
        'course_title' => sprintf('Hospitality Operations and Management Module %02d', $i),
        'units' => '3.00',
        'contact_hours' => ['lecture' => '3.0', 'laboratory' => '0.0'],
        'section_code' => sprintf('SEC-10%02d', $i),
        'scheduling_treatment' => 'Ordinary',
        'meetings' => [
            [
                'day_of_week' => $dayIndex,
                'starts_at' => sprintf('%02d:00:00', $startHour),
                'ends_at' => sprintf('%02d:30:00', $startHour + 1),
                'room_label' => sprintf('Main Campus - RM %03d', 100 + $i),
                'faculty_name' => sprintf('Prof. Instructor %02d', $i),
                'modality' => 'Face to Face',
            ],
        ],
    ];
}

$corFees = [
    ['label' => 'Tuition Fee (48 Units at 100.00)', 'amount' => '4800.00'],
    ['label' => 'Matriculation and Registration Fee', 'amount' => '500.00'],
    ['label' => 'Library and Digital Resource Fee', 'amount' => '400.00'],
    ['label' => 'Computer and Simulation Lab Fee', 'amount' => '1200.00'],
    ['label' => 'Athletic and Physical Development Fee', 'amount' => '300.00'],
    ['label' => 'Health and Medical Services Fee', 'amount' => '300.00'],
    ['label' => 'Student Council and Activity Fee', 'amount' => '200.00'],
];

$corSnapshot = [
    'student_number' => $studentProfile->student_number,
    'student_name' => 'Stu Test',
    'program_id' => $program->id,
    'program_code' => $program->code,
    'term_label' => $term->label,
    'published_timetable_version_id' => $timetableVersion->id,
    'curriculum_version_id' => $studentProfile->curriculum_version_id,
    'represented_curriculum_levels' => ['First Year', 'First Semester'],
    'selection_basis' => 'Standard Curriculum',
    'assessment_id' => $assessment->id,
    'assessment_total' => '7700.00',
    'term_account_id' => $termAccount->id,
    'issued_by_name' => 'Reg Test',
    'issued_at' => now()->subDays(2)->toIso8601String(),
    'fees' => $corFees,
    'courses' => $corCourses,
];
$latestVersionNum = (int) CorVersion::query()->where('enrollment_id', $enrollment->id)->max('version');
$corVersion = CorVersion::query()->where('enrollment_id', $enrollment->id)->where('version', $latestVersionNum)->first();
if (! $corVersion || count($corVersion->snapshot['courses'] ?? []) < 16) {
    $newVersionNum = $latestVersionNum + 1;
    $corVersion = CorVersion::create([
        'enrollment_id' => $enrollment->id,
        'version' => $newVersionNum,
        'registration_proposal_version_id' => $proposal->id,
        'assessment_id' => $assessment->id,
        'published_timetable_version_id' => $timetableVersion->id,
        'snapshot' => $corSnapshot,
        'content_hash' => hash('sha256', json_encode($corSnapshot)),
        'issued_by' => $registrar->id,
        'issued_at' => now()->subDays(2),
    ]);
}
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

// Seed 34 additional students into GradeRoster (total 35 students) to force Class Roster landscape to >= 2 pages
for ($s = 2; $s <= 35; $s++) {
    $stuUser = User::firstOrNew(['email' => sprintf('student.%02d@example.test', $s)]);
    $stuUser->first_name = sprintf('Student%02d', $s);
    $stuUser->last_name = 'Test';
    $stuUser->name = sprintf('Student%02d Test', $s);
    $stuUser->password = Hash::make('password');
    $stuUser->status = User::StatusActive;
    $stuUser->email_verified_at = now();
    $stuUser->save();
    $stuUser->syncRoles(['student']);

    $stuProf = StudentProfile::firstOrCreate(
        ['student_number' => sprintf('SIA-2026-90%02d', $s)],
        [
            'user_id' => $stuUser->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculumVersion->id,
            'first_name' => sprintf('Student%02d', $s),
            'last_name' => 'Test',
            'birth_date' => '2004-01-01',
            'email' => $stuUser->email,
            'lifecycle_status' => StudentProfile::LifecycleActive,
            'academic_standing' => StudentProfile::StandingRegular,
        ]
    );

    $stuEnrollment = Enrollment::firstOrCreate(
        ['student_profile_id' => $stuProf->id, 'term_id' => $term->id],
        [
            'credential_user_id' => $stuUser->id,
            'case_reference' => sprintf('REG-2026-%04d', $s),
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'registered_at' => now()->subDays(3),
            'officially_enrolled_at' => now()->subDays(2),
        ]
    );

    $stuCourseEnrollment = CourseEnrollment::firstOrCreate(
        ['enrollment_id' => $stuEnrollment->id, 'section_id' => $section->id],
        [
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
            'added_at' => now()->subDays(2),
        ]
    );

    GradeRosterRow::firstOrCreate(
        ['grade_roster_id' => $gradeRoster->id, 'course_enrollment_id' => $stuCourseEnrollment->id],
        [
            'final_result' => '1.50',
            'current_outcome_code' => '1.50',
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'is_current_membership' => true,
            'released_at' => now()->subDay(),
        ]
    );
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

// Seed 24 AssessmentObligations on $assessment (forces SOA to >= 2 pages)
$obligations = [];
for ($i = 1; $i <= 24; $i++) {
    $obligation = AssessmentObligation::updateOrCreate(
        ['assessment_id' => $assessment->id, 'sequence' => $i],
        [
            'code' => sprintf('OBL-%02d', $i),
            'label' => sprintf('Semester Scheduled Obligation %02d', $i),
            'purpose' => $i <= 2 ? 'Downpayment and Matriculation' : 'Instructional Assessment Installment',
            'amount' => '100.00',
            'due_at' => now()->subDays(25 - $i),
            'required_for_enrollment' => $i <= 2,
        ]
    );
    $obligations[] = $obligation;
}

// Seed 20 PaymentAllocations linking $payment to obligations (forces Payment Acknowledgment to >= 2 pages)
foreach (array_slice($obligations, 0, 20) as $idx => $obl) {
    PaymentAllocation::updateOrCreate(
        ['payment_id' => $payment->id, 'sequence' => $idx + 1],
        [
            'assessment_obligation_id' => $obl->id,
            'amount' => '100.00',
        ]
    );
}

// Seed 4 terms across 2 academic years with 5 courses each (20 total courses) for $studentProfile
// (forces Unofficial Student Record and Standard TOR to >= 2 pages)
$allTerms = [$term1, $term2, $term, $term4];
foreach ($allTerms as $tIdx => $termObj) {
    $tEnrollment = Enrollment::firstOrCreate(
        ['student_profile_id' => $studentProfile->id, 'term_id' => $termObj->id],
        [
            'credential_user_id' => $student->id,
            'case_reference' => sprintf('REG-AY-%d-%02d', $termObj->academic_year_id, $tIdx + 1),
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'registered_at' => now()->subMonths(14 - ($tIdx * 3)),
            'officially_enrolled_at' => now()->subMonths(14 - ($tIdx * 3)),
        ]
    );

    for ($c = 1; $c <= 5; $c++) {
        $cCode = sprintf('TM-%d%02d', $tIdx + 1, $c);
        $cTitle = sprintf('Tourism Management Term %d Academic Course %d', $tIdx + 1, $c);

        $cCourse = Course::firstOrCreate(
            ['code' => $cCode],
            ['state' => Course::StateActive]
        );

        $cSpec = CourseSpecification::factory()->for($cCourse)->create([
            'title' => $cTitle,
            'credit_units' => 3.00,
            'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
            'grading_profile_version' => 1,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'state' => CourseSpecification::StateActive,
        ]);

        $cCurrEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $curriculumVersion->id,
            'course_specification_id' => $cSpec->id,
            'year_level' => (int) ceil(($tIdx + 1) / 2).' Year',
            'term_label' => ($tIdx % 2 === 0 ? 'First Semester' : 'Second Semester'),
            'term_type' => ($tIdx % 2 === 0 ? Term::TypeFirstSemester : Term::TypeSecondSemester),
        ]);

        $cOffering = TermOffering::factory()->create([
            'term_id' => $termObj->id,
            'curriculum_entry_id' => $cCurrEntry->id,
            'state' => TermOffering::StateScheduled,
        ]);

        $cSection = Section::factory()->create([
            'term_offering_id' => $cOffering->id,
            'code' => sprintf('SEC-%d%02d', $tIdx + 1, $c),
            'state' => Section::StateOpen,
        ]);

        $cCourseEnrollment = CourseEnrollment::firstOrCreate(
            ['enrollment_id' => $tEnrollment->id, 'section_id' => $cSection->id],
            [
                'term_offering_id' => $cOffering->id,
                'status' => CourseEnrollment::StatusActive,
                'is_current' => true,
                'units_snapshot' => '3.00',
                'added_at' => now()->subMonths(14 - ($tIdx * 3)),
            ]
        );

        $cGradeRoster = GradeRoster::firstOrCreate(
            ['section_id' => $cSection->id],
            [
                'term_offering_id' => $cOffering->id,
                'faculty_user_id' => $faculty->id,
                'state' => GradeRoster::StateReleased,
                'grading_profile_snapshot' => config('grades.servitech_v1'),
            ]
        );

        $cRosterRow = GradeRosterRow::firstOrCreate(
            ['grade_roster_id' => $cGradeRoster->id, 'course_enrollment_id' => $cCourseEnrollment->id],
            [
                'final_result' => '1.75',
                'current_outcome_code' => '1.75',
                'current_outcome_category' => GradeRosterRow::CategoryPassing,
                'is_current_membership' => true,
                'released_at' => now()->subMonths(13 - ($tIdx * 3)),
            ]
        );

        $existingEvent = GradeOutcomeEvent::query()->where('grade_roster_row_id', $cRosterRow->id)->first();
        if (! $existingEvent) {
            GradeOutcomeEvent::factory()->create([
                'grade_roster_row_id' => $cRosterRow->id,
                'event_type' => GradeOutcomeEvent::TypeInitialRelease,
                'result_code' => '1.75',
                'new_value' => '1.75',
                'new_category' => 'Passing',
                'authority' => sprintf('REG-RELEASE-%d-%02d', $tIdx + 1, $c),
                'reason' => 'Official term outcome release.',
                'released_at' => now()->subMonths(13 - ($tIdx * 3)),
                'recorded_by' => $registrar->id,
            ]);
        }
    }
}

// Note: DegreeConferral, TranscriptRequest, and OfficialOutputPaymentClearance are omitted from persistent
// seeding to prevent collisions with CompletionToStandardTorJourneyTest which asserts exact database counts
// (e.g. assertDatabaseCount('degree_conferrals', 1) and assertDatabaseCount('official_output_payment_clearances', 0)).
// These models are managed dynamically by browser qualification passes that test preview.

// Output URL Map
$outputUrls = [
    'out001' => route('admissions.application.acknowledgment', ['application' => $application, 'version' => $appVersion], false),
    'out002' => route('timetable.version.print', ['version' => $timetableVersion], false),
    'out003' => route('cor.print', ['enrollment' => $enrollment], false),
    'out004' => route('student-academics.unofficial-record', ['student' => $studentProfile], false),
    'out005' => '/outputs/academics/transcript/1',
    'out006' => route('finance.statement', ['assessment' => $assessment], false),
    'out007' => route('finance.payments.acknowledgement', ['payment' => $payment], false).'?print=1',
    'classRoster' => route('grade-rosters.print', ['roster' => $gradeRoster], false),
];

echo "OUTPUT_URLS_START\n";
echo json_encode($outputUrls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
file_put_contents(__DIR__.'/fixtures.json', json_encode($outputUrls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Data seeding completed successfully.\n";
