<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\IssueTranscript;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\SubmitGraduationApplication;
use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Actions\Completion\VoidTranscript;
use App\Actions\Enrollment\ConfirmRegistrationIdentity;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\IssueRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\RecordOfficialOutputPaymentClearance;
use App\Actions\Grades\AmendIncDeadline;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\SaveFinalGradeResult;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SubmitIncCompletion;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\CalendarEvent;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CompletionReadinessVersion;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\GradeOutcomeEvent;
use App\Models\IncCompletionSubmission;
use App\Models\IncDeadlineAmendment;
use App\Models\OfficialOutputPaymentClearance;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MultiProcessConcurrencyJourneyTest extends TestCase
{
    /**
     * @var array<callable>
     */
    private array $cleanupCallbacks = [];

    /**
     * @var list<string>
     */
    private array $trackedTables = [
        'users',
        'academic_years',
        'terms',
        'term_calendar_packages',
        'term_calendar_windows',
        'calendar_events',
        'rooms',
        'programs',
        'curriculum_versions',
        'courses',
        'course_specifications',
        'curriculum_entries',
        'term_offerings',
        'sections',
        'published_timetable_versions',
        'published_timetable_meetings',
        'class_offering_teaching_assignments',
        'grade_rosters',
        'grade_roster_versions',
        'grade_roster_rows',
        'grade_roster_returned_rows',
        'inc_deadline_amendments',
        'inc_completion_submissions',
        'grade_outcome_events',
        'admission_cycles',
        'applicant_intakes',
        'admission_requirement_sets',
        'admission_requirements',
        'application_submission_versions',
        'admission_decisions',
        'student_profiles',
        'enrollments',
        'enrollment_gate_results',
        'registration_case_events',
        'registration_identity_confirmation_versions',
        'registration_proposal_versions',
        'registration_proposal_items',
        'registration_proposal_confirmations',
        'enrollment_seat_reservations',
        'course_enrollments',
        'student_lifecycle_changes',
        'program_shift_credit_entries',
        'graduation_applications',
        'completion_readiness_versions',
        'degree_conferrals',
        'official_output_payment_clearances',
        'transcript_requests',
        'transcript_snapshots',
        'transcript_issuance_events',
        'output_access_logs',
        'operational_events',
        'activity_log',
    ];

    /**
     * @var array<string, int>
     */
    private array $startingIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        config([
            'institution.address' => 'Synthetic Servitech Campus, Philippines',
            'institution.public.support_phone' => '0947 737 9208',
        ]);

        foreach (['student', 'applicant', User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleFaculty, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        foreach ($this->trackedTables as $table) {
            $this->startingIds[$table] = (int) (DB::table($table)->max('id') ?? 0);
        }
    }

    protected function tearDown(): void
    {
        $exceptions = [];
        while (! empty($this->cleanupCallbacks)) {
            $callback = array_pop($this->cleanupCallbacks);
            try {
                $callback();
            } catch (\Throwable $e) {
                $exceptions[] = $e->getMessage().' in '.$e->getFile().':'.$e->getLine();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        try {
            foreach (array_reverse($this->trackedTables) as $table) {
                $startId = $this->startingIds[$table] ?? 0;
                if ($table === 'users') {
                    $createdUserIds = DB::table('users')->where('id', '>', $startId)->pluck('id');
                    if ($createdUserIds->isNotEmpty()) {
                        DB::table('model_has_roles')->whereIn('model_id', $createdUserIds)->delete();
                        DB::table('users')->whereIn('id', $createdUserIds)->delete();
                    }
                } else {
                    DB::table($table)->where('id', '>', $startId)->delete();
                }
            }
        } catch (\Throwable $e) {
            $exceptions[] = 'Automated table cleanup failed: '.$e->getMessage();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        gc_collect_cycles();

        parent::tearDown();

        if (! empty($exceptions)) {
            throw new \RuntimeException("Cleanup failed during tearDown:\n".implode("\n", $exceptions));
        }
    }

    #[Test]
    public function test_multi_process_concurrent_proposal_placement_claims_last_seat_atomically_without_over_capacity_or_duplicate_reservation(): void
    {
        // Issue #37 Criterion 10:
        // "Placement validates prerequisites, conflicts, capacity, protection, source versions, and concurrent attempts atomically."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);

        $term = $this->createUniqueTerm();
        $this->openTermCalendarPackage($term);

        $program = Program::factory()->create();
        $this->registerCleanup(fn () => $program->forceDelete());

        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'effective_entry_term_id' => $term->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $this->registerCleanup(fn () => $curriculum->forceDelete());

        $course = Course::factory()->create();
        $this->registerCleanup(fn () => $course->forceDelete());

        $specification = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'credit_units' => 3,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
        ]);
        $this->registerCleanup(fn () => $specification->forceDelete());

        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $specification->id,
        ]);
        $this->registerCleanup(fn () => $entry->forceDelete());

        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $this->registerCleanup(fn () => $offering->forceDelete());

        // Capacity is exactly 1
        $section = Section::factory()->create([
            'term_offering_id' => $offering->id,
            'state' => Section::StateOpen,
            'capacity' => 1,
        ]);
        $this->registerCleanup(fn () => $section->forceDelete());

        $timetable = PublishedTimetableVersion::factory()->create([
            'term_id' => $term->id,
            'state' => PublishedTimetableVersion::StatePublished,
            'version' => 1,
        ]);
        $this->registerCleanup(function () use ($timetable) {
            $runId = $timetable->schedule_run_id;
            $timetable->forceDelete();
            if ($runId) {
                DB::table('schedule_runs')->where('id', $runId)->delete();
            }
        });

        $faculty = $this->createStaffUser(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'code' => 'RM-CONC-'.uniqid(),
        ]);
        $this->registerCleanup(fn () => $room->forceDelete());

        $meeting = PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $timetable->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
        ]);
        $this->registerCleanup(fn () => $meeting->forceDelete());

        // Setup Applicant 1 and confirmed proposal
        $app1 = $this->createReadyApplicant($term, $program);
        $case1 = app(StartRegistrationCase::class)->forReadyApplicant($app1, $term, $app1->user);
        $this->registerCleanup(fn () => $this->cleanupRegistrationCase($case1));
        app(ConfirmRegistrationIdentity::class)->execute($case1, $app1->user);
        $proposal1 = app(PrepareRegistrationProposal::class)->execute($case1, $registrar, [$section->id], $case1->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal1, $registrar);
        $proposal1 = app(ConfirmRegistrationProposal::class)->execute($proposal1->fresh(), $app1->user);

        // Setup Applicant 2 and confirmed proposal for the SAME section
        $app2 = $this->createReadyApplicant($term, $program);
        $case2 = app(StartRegistrationCase::class)->forReadyApplicant($app2, $term, $app2->user);
        $this->registerCleanup(fn () => $this->cleanupRegistrationCase($case2));
        app(ConfirmRegistrationIdentity::class)->execute($case2, $app2->user);
        $proposal2 = app(PrepareRegistrationProposal::class)->execute($case2, $registrar, [$section->id], $case2->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal2, $registrar);
        $proposal2 = app(ConfirmRegistrationProposal::class)->execute($proposal2->fresh(), $app2->user);

        $this->assertSame(0, EnrollmentSeatReservation::query()->where('section_id', $section->id)->count());

        // Execute genuine competing multi-process race
        $results = $this->runConcurrentWorkers(
            actionA: 'placement',
            payloadA: ['proposal_id' => $proposal1->id, 'registrar_id' => $registrar->id],
            payloadB: ['proposal_id' => $proposal2->id, 'registrar_id' => $registrar->id],
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Exactly one worker must succeed, and the other must fail with capacity validation exception
        $successes = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === true);
        $failures = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === false);

        $this->assertCount(1, $successes, 'Exactly one concurrent placement attempt must succeed.');
        $this->assertCount(1, $failures, 'Exactly one concurrent placement attempt must be rejected.');

        $failedWorker = $failures->first();
        $this->assertStringContainsString('capacity', strtolower(json_encode($failedWorker['errors'] ?? $failedWorker['message'])));

        // Verify database state: exactly 1 seat reservation exists in test_tala_db
        $reservations = EnrollmentSeatReservation::query()->where('section_id', $section->id)->get();
        $this->assertCount(1, $reservations, 'Section must have exactly 1 seat reservation committed in test_tala_db.');
        $this->assertSame(EnrollmentSeatReservation::StatusActive, $reservations->first()->status);

        // Observable cleanup: delete reservation and verify zero residue in test_tala_db
        foreach ($reservations as $r) {
            $r->forceDelete();
        }
        $this->assertSame(0, EnrollmentSeatReservation::query()->where('section_id', $section->id)->count());

        $vIds = [$proposal1->id, $proposal2->id];
        DB::table('registration_proposal_items')->whereIn('registration_proposal_version_id', $vIds)->delete();
        DB::table('registration_proposal_confirmations')->whereIn('registration_proposal_version_id', $vIds)->delete();
        DB::table('enrollments')->whereIn('current_proposal_version_id', $vIds)->update(['current_proposal_version_id' => null]);
        DB::table('registration_proposal_versions')->whereIn('id', $vIds)->update(['supersedes_version_id' => null]);
        DB::table('registration_proposal_versions')->whereIn('id', $vIds)->delete();
    }

    #[Test]
    public function test_multi_process_concurrent_inc_completion_release_commits_exactly_once_without_duplicate_outcome_event(): void
    {
        // Issue #38 Criterion 9:
        // "An intervening correction, successor, deadline change, expiry, or concurrent action prevents stale INC completion release."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $faculty = $this->createStaffUser(User::StaffRoleFaculty);

        $term = $this->createUniqueTerm();
        $this->openTermCalendarPackage($term);
        $gradeEntryEvent = CalendarEvent::factory()->create([
            'term_id' => $term->id,
            'process_key' => 'grade_entry',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        $this->registerCleanup(fn () => $gradeEntryEvent->forceDelete());

        $program = Program::factory()->create();
        $this->registerCleanup(fn () => $program->forceDelete());

        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'effective_entry_term_id' => $term->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $this->registerCleanup(fn () => $curriculum->forceDelete());

        $entry = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id]);
        $this->registerCleanup(fn () => $entry->forceDelete());

        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $this->registerCleanup(fn () => $offering->forceDelete());

        $section = Section::factory()->create(['term_offering_id' => $offering->id, 'state' => Section::StateOpen]);
        $this->registerCleanup(fn () => $section->forceDelete());

        $student = StudentProfile::factory()->create([
            'student_number' => 'SIA-CONC-'.uniqid(),
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
        ]);
        $this->registerCleanup(function () use ($student) {
            $user = $student->user;
            DB::table('output_access_logs')->where('student_profile_id', $student->id)->delete();
            DB::table('student_profiles')->where('id', $student->id)->delete();
            if ($user) {
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $this->registerCleanup(fn () => $enrollment->forceDelete());

        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $this->registerCleanup(fn () => $courseEnrollment->forceDelete());

        // Setup grade roster and release initial INC
        app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'ASSIGN-CONC-INC');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar);
        $this->registerCleanup(fn () => $roster->forceDelete());
        $row = $roster->rows->sole();

        app(SaveFinalGradeResult::class)->execute($row, 'INC', 'Complete project portfolio', $faculty);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $faculty);
        $released = app(PostAndReleaseGradeRoster::class)->execute($submitted, $registrar, 'RELEASE-CONC-INC-001');

        $incompleteEvent = $released->rows->sole()->outcomeEvents->sole();
        $this->assertSame('INC', $incompleteEvent->result_code);

        // Faculty submits INC completion
        $submission = app(SubmitIncCompletion::class)->execute(
            $incompleteEvent,
            '1.75',
            'Portfolio submission completed and reviewed.',
            $faculty,
        );

        $this->assertSame(IncCompletionSubmission::StateSubmitted, $submission->state);

        // Run competing concurrent processes attempting to release the same INC completion
        $results = $this->runConcurrentWorkers(
            actionA: 'inc_release',
            payloadA: [
                'submission_id' => $submission->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-CONC-AUTH-A',
            ],
            payloadB: [
                'submission_id' => $submission->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-CONC-AUTH-B',
            ],
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Assert outcomes of both competing workers:
        // Both workers execute against the shared database. Worker A commits the transition,
        // and Worker B returns the idempotent event reference or rejects a duplicate release attempt.
        $this->assertTrue($workerA['success'], 'Worker A must complete without unhandled crash.');
        $this->assertTrue($workerB['success'], 'Worker B must complete without unhandled crash.');

        // In test_tala_db, exactly ONE inc_resolution GradeOutcomeEvent must exist for this submission
        $incEvents = GradeOutcomeEvent::query()
            ->where('source_key', "inc-completion:{$submission->id}")
            ->get();
        $this->assertCount(1, $incEvents, 'Exactly ONE inc_resolution GradeOutcomeEvent must exist in test_tala_db.');
        $this->assertSame('1.75', $incEvents->first()->result_code);
        $this->assertSame(GradeOutcomeEvent::TypeIncResolution, $incEvents->first()->event_type);

        // Both workers returned the exact same committed event ID (idempotent, no competing event)
        $this->assertSame($incEvents->first()->id, $workerA['data']['event_id']);
        $this->assertSame($incEvents->first()->id, $workerB['data']['event_id']);

        // The grade roster row has been updated to the resolved outcome
        $this->assertSame('1.75', $row->fresh()->current_outcome_code);

        // Observable cleanup: delete submission and outcome events, then verify zero residue in test_tala_db
        $submission->update(['released_event_id' => null]);
        $submission->forceDelete();
        foreach ($incEvents as $incEvent) {
            $incEvent->forceDelete();
        }
        $incompleteEvent->forceDelete();

        $this->assertSame(0, GradeOutcomeEvent::query()->where('source_key', "inc-completion:{$submission->id}")->count());
        $this->assertSame(0, IncCompletionSubmission::query()->where('id', $submission->id)->count());

        foreach ($roster->rows as $rRow) {
            GradeOutcomeEvent::query()->where('grade_roster_row_id', $rRow->id)->forceDelete();
        }
        foreach ($roster->versions as $v) {
            DB::table('grade_roster_version_rows')->where('grade_roster_version_id', $v->id)->delete();
        }
        $roster->versions()->forceDelete();
        $roster->rows()->forceDelete();
        $roster->forceDelete();
        ClassOfferingTeachingAssignment::query()->where('section_id', $section->id)->forceDelete();
        $courseEnrollment->forceDelete();
        $enrollment->forceDelete();
        DB::table('output_access_logs')->where('student_profile_id', $student->id)->delete();
        CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->update(['supersedes_readiness_id' => null]);
        CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->forceDelete();
        $studentUser = $student->user;
        $student->forceDelete();
        $studentUser?->forceDelete();
    }

    #[Test]
    public function test_multi_process_concurrent_intervening_deadline_change_prevents_stale_inc_completion_release(): void
    {
        // Issue #38 Criterion 9:
        // "An intervening correction, successor, deadline change, expiry, or concurrent action prevents stale INC completion release."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $faculty = $this->createStaffUser(User::StaffRoleFaculty);

        $term = $this->createUniqueTerm();
        $this->openTermCalendarPackage($term);
        $gradeEntryEvent = CalendarEvent::factory()->create([
            'term_id' => $term->id,
            'process_key' => 'grade_entry',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        $this->registerCleanup(fn () => $gradeEntryEvent->forceDelete());

        $program = Program::factory()->create();
        $this->registerCleanup(fn () => $program->forceDelete());

        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'effective_entry_term_id' => $term->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $this->registerCleanup(fn () => $curriculum->forceDelete());

        $entry = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id]);
        $this->registerCleanup(fn () => $entry->forceDelete());

        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $this->registerCleanup(fn () => $offering->forceDelete());

        $section = Section::factory()->create(['term_offering_id' => $offering->id, 'state' => Section::StateOpen]);
        $this->registerCleanup(fn () => $section->forceDelete());

        $student = StudentProfile::factory()->create([
            'student_number' => 'SIA-CONC-'.uniqid(),
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
        ]);
        $this->registerCleanup(function () use ($student) {
            $user = $student->user;
            DB::table('output_access_logs')->where('student_profile_id', $student->id)->delete();
            DB::table('student_profiles')->where('id', $student->id)->delete();
            if ($user) {
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $this->registerCleanup(fn () => $enrollment->forceDelete());

        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $this->registerCleanup(fn () => $courseEnrollment->forceDelete());

        app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'ASSIGN-CONC-INC-STALE');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar);
        $this->registerCleanup(fn () => $roster->forceDelete());
        $row = $roster->rows->sole();

        app(SaveFinalGradeResult::class)->execute($row, 'INC', 'Complete project portfolio', $faculty);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $faculty);
        $released = app(PostAndReleaseGradeRoster::class)->execute($submitted, $registrar, 'RELEASE-CONC-INC-STALE-001');

        $incompleteEvent = $released->rows->sole()->outcomeEvents->sole();
        $this->assertSame('INC', $incompleteEvent->result_code);

        // Faculty submits completion based on the current initial deadline
        $submission = app(SubmitIncCompletion::class)->execute(
            $incompleteEvent,
            '1.75',
            'Portfolio submission completed and reviewed.',
            $faculty,
        );

        // Intervening action: Registrar amends the INC deadline, creating a new controlling deadline authority
        $amendedDeadline = now()->addMonths(18)->startOfDay();
        $amendment = app(AmendIncDeadline::class)->execute(
            $incompleteEvent,
            $amendedDeadline,
            'REG-AMEND-INTERVENING-001',
            now(),
            'Board-authorized extension granted before completion release',
            $registrar,
        );

        // Run concurrent processes: Worker A and Worker B both attempt to release the now-stale submission
        $results = $this->runConcurrentWorkers(
            actionA: 'inc_release',
            payloadA: [
                'submission_id' => $submission->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-STALE-RELEASE-A',
            ],
            payloadB: [
                'submission_id' => $submission->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-STALE-RELEASE-B',
            ],
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Assert outcomes of both competing workers:
        // BOTH competing actions must be rejected because the submission's controlling deadline is stale!
        $this->assertFalse($workerA['success'], 'Worker A must fail closed against the stale submission.');
        $this->assertFalse($workerB['success'], 'Worker B must fail closed against the stale submission.');

        $this->assertStringContainsString('stale', strtolower($workerA['message']));
        $this->assertStringContainsString('stale', strtolower($workerB['message']));

        // In test_tala_db, NO inc_resolution event exists, and the roster row remains in INC state
        $this->assertSame(0, GradeOutcomeEvent::query()->where('source_key', "inc-completion:{$submission->id}")->count());
        $this->assertSame('INC', $row->fresh()->current_outcome_code);

        // Observable cleanup
        $submission->forceDelete();
        $amendment->forceDelete();
        $incompleteEvent->forceDelete();

        $this->assertSame(0, IncCompletionSubmission::query()->where('id', $submission->id)->count());
        $this->assertSame(0, IncDeadlineAmendment::query()->where('id', $amendment->id)->count());

        foreach ($roster->rows as $rRow) {
            GradeOutcomeEvent::query()->where('grade_roster_row_id', $rRow->id)->forceDelete();
        }
        foreach ($roster->versions as $v) {
            DB::table('grade_roster_version_rows')->where('grade_roster_version_id', $v->id)->delete();
        }
        $roster->versions()->forceDelete();
        $roster->rows()->forceDelete();
        $roster->forceDelete();
        ClassOfferingTeachingAssignment::query()->where('section_id', $section->id)->forceDelete();
        $courseEnrollment->forceDelete();
        $enrollment->forceDelete();
        DB::table('output_access_logs')->where('student_profile_id', $student->id)->delete();
        CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->update(['supersedes_readiness_id' => null]);
        CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->forceDelete();
        $studentUser = $student->user;
        $student->forceDelete();
        $studentUser?->forceDelete();
    }

    #[Test]
    public function test_multi_process_concurrent_degree_conferral_creates_single_record_and_lifecycle_event(): void
    {
        // Issue #39 Criterion 8:
        // "Duplicate, stale, or concurrent conferral attempts create no duplicate record or lifecycle event."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);

        $student = StudentProfile::factory()->create([
            'student_number' => 'SIA-CONC-'.uniqid(),
        ]);
        $student->user->assignRole('student');

        $term = $this->createUniqueTerm();

        $specification = CourseSpecification::factory()->create([
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);

        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id,
            'course_specification_id' => $specification->id,
        ]);

        $authority = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);

        $credit = ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $authority->id,
            'curriculum_entry_id' => $entry->id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'state' => ProgramShiftCreditEntry::StateRecorded,
            'numeric_grade' => '2.00',
        ]);

        $projection = app(CompletionReadinessProjection::class)->forStudent($student);
        $this->assertSame(CompletionReadinessProjection::EligibleToApply, $projection['state']);

        $application = app(SubmitGraduationApplication::class)->execute($student, $student->user);

        $this->registerCleanup(function () use ($student, $authority, $entry, $specification) {
            $user = $student->user;
            DegreeConferral::query()->where('student_profile_id', $student->id)->forceDelete();
            CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->update(['supersedes_readiness_id' => null]);
            CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->forceDelete();
            DB::table('graduation_applications')->where('student_profile_id', $student->id)->update(['supersedes_application_id' => null]);
            DB::table('graduation_applications')->where('student_profile_id', $student->id)->delete();
            ProgramShiftCreditEntry::query()->where('student_lifecycle_change_id', $authority->id)->forceDelete();
            $authority->forceDelete();
            StudentLifecycleChange::query()->where('student_profile_id', $student->id)->forceDelete();
            $entry->forceDelete();
            $specification->forceDelete();
            DB::table('output_access_logs')->where('student_profile_id', $student->id)->delete();
            DB::table('student_profiles')->where('id', $student->id)->delete();
            if ($user) {
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        // Run competing concurrent processes attempting to record degree conferral for the same student
        $results = $this->runConcurrentWorkers(
            actionA: 'conferral',
            payloadA: [
                'student_id' => $student->id,
                'registrar_id' => $registrar->id,
                'degree_name' => 'Bachelor of Science in Information Technology',
                'conferred_on' => '2026-06-30',
                'authority_reference' => 'BOR-RES-2026-CONC-001',
            ],
            payloadB: [
                'student_id' => $student->id,
                'registrar_id' => $registrar->id,
                'degree_name' => 'Bachelor of Science in Information Technology',
                'conferred_on' => '2026-06-30',
                'authority_reference' => 'BOR-RES-2026-CONC-001',
            ],
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Assert outcomes of both competing workers:
        // Both workers report success. Worker A commits the record, and Worker B returns the idempotent conferral.
        $this->assertTrue($workerA['success'], 'Worker A must complete without unhandled crash.');
        $this->assertTrue($workerB['success'], 'Worker B must complete without unhandled crash.');

        // In test_tala_db:
        // Exactly ONE DegreeConferral record exists for this student
        $conferrals = DegreeConferral::query()->where('student_profile_id', $student->id)->get();
        $this->assertCount(1, $conferrals, 'Exactly ONE DegreeConferral record must exist in test_tala_db.');

        // Exactly ONE StudentLifecycleChange of type completion exists
        $lifecycleChanges = StudentLifecycleChange::query()
            ->where('student_profile_id', $student->id)
            ->where('type', StudentLifecycleChange::TypeCompletion)
            ->get();
        $this->assertCount(1, $lifecycleChanges, 'Exactly ONE StudentLifecycleChange of type Completion must exist in test_tala_db.');

        // Both workers returned the exact same committed conferral ID
        $this->assertSame($conferrals->first()->id, $workerA['data']['conferral_id']);
        $this->assertSame($conferrals->first()->id, $workerB['data']['conferral_id']);

        // Student lifecycle status is completed
        $this->assertSame(StudentProfile::LifecycleCompleted, $student->fresh()->lifecycle_status);

        // Observable cleanup: delete conferral and lifecycle change, then verify zero residue in test_tala_db
        foreach ($conferrals as $c) {
            $c->forceDelete();
        }
        foreach ($lifecycleChanges as $lc) {
            $lc->forceDelete();
        }

        $this->assertSame(0, DegreeConferral::query()->where('student_profile_id', $student->id)->count());
        $this->assertSame(0, StudentLifecycleChange::query()->where('student_profile_id', $student->id)->where('type', StudentLifecycleChange::TypeCompletion)->count());
    }

    #[Test]
    public function test_multi_process_concurrent_transcript_issue_commits_at_most_one_valid_transition_without_competing_active_snapshots(): void
    {
        // Issue #39 Criterion 26 (Operation 1: Issue):
        // "Concurrent issue, void, replace, and supersede operations commit at most one valid transition and produce no competing current snapshots or partial events."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $accounting = $this->createStaffUser(User::StaffRoleAccounting);

        [$student, $conferral, $request] = $this->createClearedTranscriptRequest($registrar, $accounting);

        // Run competing concurrent processes attempting to issue the same TOR request
        $results = $this->runConcurrentWorkers(
            actionA: 'transcript_issue',
            payloadA: [
                'request_id' => $request->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-ISSUE-CONC-A',
            ],
            payloadB: [
                'request_id' => $request->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-ISSUE-CONC-B',
            ],
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Exactly one issue operation must succeed; the competing attempt must fail closed
        $successes = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === true);
        $failures = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === false);

        $this->assertCount(1, $successes, 'Exactly one concurrent issue operation must commit.');
        $this->assertCount(1, $failures, 'Competing issue operation must be rejected.');

        $failedWorker = $failures->first();
        $this->assertStringContainsString('current tor', strtolower(json_encode($failedWorker['errors'] ?? $failedWorker['message'])));

        // In test_tala_db:
        // Exactly ONE snapshot exists for this request (version 1, status issued)
        $snapshots = TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->get();
        $this->assertCount(1, $snapshots, 'Exactly ONE TranscriptSnapshot must exist in test_tala_db.');
        $this->assertSame(1, $snapshots->first()->version);
        $this->assertSame(TranscriptSnapshot::StatusIssued, $snapshots->first()->status);

        // Exactly ONE issued event exists
        $issuedEvents = TranscriptIssuanceEvent::query()
            ->where('transcript_request_id', $request->id)
            ->where('type', TranscriptIssuanceEvent::TypeIssued)
            ->get();
        $this->assertCount(1, $issuedEvents, 'Exactly ONE issued TranscriptIssuanceEvent must exist in test_tala_db.');

        // Exactly ONE current snapshot exists in the request lifecycle (no competing active snapshots)
        $currentSnapshot = app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh());
        $this->assertNotNull($currentSnapshot);
        $this->assertSame($snapshots->first()->id, $currentSnapshot->id);

        // Observable cleanup: delete issued event and snapshot, then verify zero residue
        $issuedEvents->first()->forceDelete();
        $snapshots->first()->forceDelete();

        $this->assertSame(0, TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->count());
        $this->assertSame(0, TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->count());
    }

    #[Test]
    public function test_multi_process_concurrent_transcript_void_commits_at_most_one_valid_transition_without_competing_active_snapshots(): void
    {
        // Issue #39 Criterion 26 (Operation 2: Void):
        // "Concurrent issue, void, replace, and supersede operations commit at most one valid transition and produce no competing current snapshots or partial events."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $accounting = $this->createStaffUser(User::StaffRoleAccounting);

        [$student, $conferral, $request] = $this->createClearedTranscriptRequest($registrar, $accounting);
        $snapshot = $this->issueTranscriptSnapshot($request, $registrar);

        $this->assertSame(TranscriptSnapshot::StatusIssued, $snapshot->status);

        // Run competing concurrent void attempts with different reasons/authorities
        $results = $this->runConcurrentWorkers(
            actionA: 'transcript_void',
            payloadA: [
                'snapshot_id' => $snapshot->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-VOID-CONC-A',
                'reason' => 'Void reason A: Administrative correction requested',
            ],
            payloadB: [
                'snapshot_id' => $snapshot->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-VOID-CONC-B',
                'reason' => 'Void reason B: Duplicate review flagged',
            ],
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Exactly one void attempt must succeed; the competing attempt must fail
        $successes = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === true);
        $failures = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === false);

        $this->assertCount(1, $successes, 'Exactly one concurrent void operation must commit.');
        $this->assertCount(1, $failures, 'Competing void operation must be rejected.');

        $failedWorker = $failures->first();
        $this->assertStringContainsString('void', strtolower(json_encode($failedWorker['errors'] ?? $failedWorker['message'])));

        // In test_tala_db:
        // Exactly ONE void event exists for this snapshot
        $voidEvents = TranscriptIssuanceEvent::query()
            ->where('transcript_snapshot_id', $snapshot->id)
            ->where('type', TranscriptIssuanceEvent::TypeVoided)
            ->get();
        $this->assertCount(1, $voidEvents, 'Exactly ONE void TranscriptIssuanceEvent must exist in test_tala_db.');

        // Zero competing current snapshots exist (the request has no current issued snapshot)
        $currentSnapshot = app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh());
        $this->assertNull($currentSnapshot, 'No active/current snapshot must exist after voiding.');

        // Observable cleanup: delete events and snapshot, then verify zero residue
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->update(['predecessor_event_id' => null]);
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->forceDelete();
        $snapshot->forceDelete();

        $this->assertSame(0, TranscriptIssuanceEvent::query()->where('transcript_snapshot_id', $snapshot->id)->count());
        $this->assertSame(0, TranscriptSnapshot::query()->where('id', $snapshot->id)->count());
    }

    #[Test]
    public function test_multi_process_concurrent_transcript_replace_commits_at_most_one_valid_transition_with_superseded_predecessor_link(): void
    {
        // Issue #39 Criterion 26 (Operation 3: Replace):
        // "Concurrent issue, void, replace, and supersede operations commit at most one valid transition and produce no competing current snapshots or partial events."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $accounting = $this->createStaffUser(User::StaffRoleAccounting);

        [$student, $conferral, $request] = $this->createClearedTranscriptRequest($registrar, $accounting);
        $snapshotV1 = $this->issueTranscriptSnapshot($request, $registrar);
        $voidEvent = $this->voidTranscriptSnapshot($snapshotV1, $registrar);

        // Run competing concurrent processes attempting to replace the voided predecessor
        $results = $this->runConcurrentWorkers(
            actionA: 'transcript_replace',
            payloadA: [
                'predecessor_id' => $snapshotV1->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-REPLACE-CONC-A',
                'reason' => 'Replacement reason A: Updated academic honors note',
            ],
            payloadB: [
                'predecessor_id' => $snapshotV1->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-REPLACE-CONC-B',
                'reason' => 'Replacement reason B: Alternate replacement proposal',
            ],
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Exactly one replacement operation must succeed; competing attempt must be rejected
        $successes = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === true);
        $failures = collect([$workerA, $workerB])->filter(fn ($w) => ($w['success'] ?? false) === false);

        $this->assertCount(1, $successes, 'Exactly one concurrent replacement operation must commit.');
        $this->assertCount(1, $failures, 'Competing replacement operation must be rejected.');

        $failedWorker = $failures->first();
        $this->assertStringContainsString('replacement', strtolower(json_encode($failedWorker['errors'] ?? $failedWorker['message'])));

        // In test_tala_db:
        // Exactly TWO snapshots exist: V1 (voided/superseded) and V2 (replacement)
        $snapshots = TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->orderBy('version')->get();
        $this->assertCount(2, $snapshots, 'Exactly TWO TranscriptSnapshots must exist (V1 superseded, V2 replacement).');

        $snapshotV2 = $snapshots->last();
        $this->assertSame(2, $snapshotV2->version);
        $this->assertSame(TranscriptIssuanceEvent::TypeReplacement, $snapshotV2->status);

        // Supersede link is explicit: V2 supersedes V1
        $this->assertSame($snapshotV1->id, $snapshotV2->supersedes_snapshot_id);

        // Exactly ONE current snapshot exists in the entire request lifecycle (V2)
        $currentSnapshot = app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh());
        $this->assertNotNull($currentSnapshot);
        $this->assertSame($snapshotV2->id, $currentSnapshot->id);

        // Observable cleanup: delete snapshots and events, then verify zero residue
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->update(['predecessor_event_id' => null]);
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->forceDelete();
        TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->update(['supersedes_snapshot_id' => null]);
        TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->forceDelete();

        $this->assertSame(0, TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->count());
        $this->assertSame(0, TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->count());
    }

    #[Test]
    public function test_multi_process_concurrent_transcript_supersede_commits_at_most_one_valid_transition_without_duplicate_superseded_events(): void
    {
        // Issue #39 Criterion 26 (Operation 4: Supersede):
        // "Concurrent issue, void, replace, and supersede operations commit at most one valid transition and produce no competing current snapshots or partial events."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $accounting = $this->createStaffUser(User::StaffRoleAccounting);

        [$student, $conferral, $request] = $this->createClearedTranscriptRequest($registrar, $accounting);
        $snapshot = $this->issueTranscriptSnapshot($request, $registrar);

        // Run competing concurrent processes attempting to supersede the issued snapshot for the student
        $results = $this->runConcurrentWorkers(
            actionA: 'transcript_supersede',
            payloadA: [
                'student_id' => $student->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-SUPERSEDE-CONC-A',
                'reason' => 'Supersede reason A: Degree correction applied',
            ],
            payloadB: [
                'student_id' => $student->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-SUPERSEDE-CONC-B',
                'reason' => 'Supersede reason B: Duplicate conferral adjustment',
            ],
            actionB: 'transcript_supersede',
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        $this->assertTrue($workerA['success'], 'Worker A must complete without unhandled crash.');
        $this->assertTrue($workerB['success'], 'Worker B must complete without unhandled crash.');

        // Across both competing workers, exactly ONE snapshot supersession occurred
        $totalCount = ($workerA['data']['count'] ?? 0) + ($workerB['data']['count'] ?? 0);
        $this->assertSame(1, $totalCount, 'Exactly one snapshot supersession must occur across competing workers.');

        // In test_tala_db:
        // Exactly ONE superseded event exists for this snapshot
        $supersededEvents = TranscriptIssuanceEvent::query()
            ->where('transcript_snapshot_id', $snapshot->id)
            ->where('type', TranscriptIssuanceEvent::TypeSuperseded)
            ->get();
        $this->assertCount(1, $supersededEvents, 'Exactly ONE superseded TranscriptIssuanceEvent must exist in test_tala_db.');

        // Observable cleanup: delete events and snapshot, then verify zero residue
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->update(['predecessor_event_id' => null]);
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->forceDelete();
        $snapshot->forceDelete();

        $this->assertSame(0, TranscriptIssuanceEvent::query()->where('transcript_snapshot_id', $snapshot->id)->count());
        $this->assertSame(0, TranscriptSnapshot::query()->where('id', $snapshot->id)->count());
    }

    #[Test]
    public function test_multi_process_conflicting_transcript_void_and_replace_commits_at_most_one_valid_transition(): void
    {
        // Issue #39 Criterion 26 (Conflicting Transitions):
        // "Concurrent issue, void, replace, and supersede operations commit at most one valid transition and produce no competing current snapshots or partial events."
        $registrar = $this->createStaffUser(User::StaffRoleRegistrar);
        $accounting = $this->createStaffUser(User::StaffRoleAccounting);

        [$student, $conferral, $request] = $this->createClearedTranscriptRequest($registrar, $accounting);
        $snapshotV1 = $this->issueTranscriptSnapshot($request, $registrar);

        // Snapshot V1 is currently ISSUED (not yet voided).
        // Competing processes:
        // Worker A attempts to void V1.
        // Worker B attempts to replace V1 without waiting for voiding to complete.
        $results = $this->runConcurrentWorkers(
            actionA: 'transcript_void',
            payloadA: [
                'snapshot_id' => $snapshotV1->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-CONFLICT-VOID',
                'reason' => 'Conflicting void operation',
            ],
            payloadB: [
                'predecessor_id' => $snapshotV1->id,
                'registrar_id' => $registrar->id,
                'authority_reference' => 'REG-CONFLICT-REPLACE',
                'reason' => 'Premature replacement attempt',
            ],
            actionB: 'transcript_replace',
        );

        $workerA = $results['worker_a'];
        $workerB = $results['worker_b'];

        $this->assertNotNull($workerA, 'Worker A did not return valid JSON: '.$results['raw_a']);
        $this->assertNotNull($workerB, 'Worker B did not return valid JSON: '.$results['raw_b']);

        // Assert outcomes of competing conflicting operations:
        // At most one valid transition commits for each lifecycle state.
        // Worker A commits the void operation.
        $this->assertTrue($workerA['success'], 'Void operation should commit as valid transition.');

        if (! $workerB['success']) {
            $this->assertStringContainsString('void', strtolower(json_encode($workerB['errors'] ?? $workerB['message'])));
            $this->assertNull(app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh()), 'Request has zero current snapshots after voiding.');
        } else {
            // If Worker B acquired the lock after Worker A voided, Worker B committed the replacement
            $currentSnapshot = app(TranscriptLifecycleProjection::class)->currentSnapshot($request->fresh());
            $this->assertNotNull($currentSnapshot, 'Replacement snapshot exists as current TOR.');
            $this->assertSame(TranscriptIssuanceEvent::TypeReplacement, $currentSnapshot->status);
        }

        // Under no interleaving are there ever competing current snapshots according to the lifecycle projection:
        $currentSnapshots = $request->fresh()->snapshots->filter(fn ($s) => in_array(
            app(TranscriptLifecycleProjection::class)->statusForSnapshot($s),
            [TranscriptIssuanceEvent::TypeIssued, TranscriptIssuanceEvent::TypeReplacement],
            true
        ));
        $this->assertLessThanOrEqual(1, $currentSnapshots->count(), 'There must never be competing current snapshots.');

        // Observable cleanup: delete snapshots and events, then verify zero residue
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->update(['predecessor_event_id' => null]);
        TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->forceDelete();
        TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->update(['supersedes_snapshot_id' => null]);
        TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->forceDelete();

        $this->assertSame(0, TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->count());
        $this->assertSame(0, TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->count());
    }

    /**
     * @return array{worker_a: ?array, worker_b: ?array, raw_a: string, raw_b: string}
     */
    private function runConcurrentWorkers(string $actionA, array $payloadA, array $payloadB, ?string $actionB = null): array
    {
        $actionB = $actionB ?? $actionA;

        $tempDir = storage_path('framework/testing/concurrency');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $barrierFile = $tempDir.'/barrier_'.uniqid().'.flag';
        $readyFileA = $tempDir.'/ready_a_'.uniqid().'.flag';
        $readyFileB = $tempDir.'/ready_b_'.uniqid().'.flag';

        $workerScript = base_path('tests/Feature/Concurrency/concurrency_worker.php');

        $processA = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=256M',
            $workerScript,
            '--action='.$actionA,
            '--payload='.base64_encode(json_encode($payloadA)),
            '--barrier='.$barrierFile,
            '--ready='.$readyFileA,
        ], base_path());

        $processB = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=256M',
            $workerScript,
            '--action='.$actionB,
            '--payload='.base64_encode(json_encode($payloadB)),
            '--barrier='.$barrierFile,
            '--ready='.$readyFileB,
        ], base_path());

        $processA->start();
        $processB->start();

        // Wait until both processes boot and signal readiness
        $waited = 0;
        while (! file_exists($readyFileA) || ! file_exists($readyFileB)) {
            usleep(5000); // 5ms
            $waited += 5000;
            if ($waited > 25000000) { // 25s
                @unlink($readyFileA);
                @unlink($readyFileB);
                @unlink($barrierFile);
                $processA->stop();
                $processB->stop();
                throw new \RuntimeException('Timeout waiting for concurrent workers to signal readiness. Output A: '.$processA->getOutput().' Err A: '.$processA->getErrorOutput().' Output B: '.$processB->getOutput().' Err B: '.$processB->getErrorOutput());
            }
        }

        // Release the barrier flag!
        file_put_contents($barrierFile, 'GO');

        $processA->wait();
        $processB->wait();

        @unlink($readyFileA);
        @unlink($readyFileB);
        @unlink($barrierFile);

        $outA = json_decode($processA->getOutput(), true);
        $outB = json_decode($processB->getOutput(), true);

        return [
            'worker_a' => $outA,
            'worker_b' => $outB,
            'raw_a' => $processA->getOutput(),
            'raw_b' => $processB->getOutput(),
        ];
    }

    private function createClearedTranscriptRequest(User $registrar, User $accounting): array
    {
        $student = StudentProfile::factory()->create([
            'student_number' => 'SIA-CONC-'.uniqid(),
        ]);
        $student->user->assignRole('student');

        $term = $this->createUniqueTerm();

        $specification = CourseSpecification::factory()->create([
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);

        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id,
            'course_specification_id' => $specification->id,
        ]);

        $authority = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);

        $credit = ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $authority->id,
            'curriculum_entry_id' => $entry->id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'state' => ProgramShiftCreditEntry::StateRecorded,
            'numeric_grade' => '2.00',
        ]);

        $application = app(SubmitGraduationApplication::class)->execute($student, $student->user);

        $conferral = app(RecordDegreeConferral::class)->execute(
            $student,
            $registrar,
            'Bachelor of Science in Information Technology',
            '2026-06-30',
            'BOR-RES-2026-TOR-CONC-'.uniqid(),
        );

        $request = app(RecordTranscriptRequest::class)->execute(
            $conferral,
            $registrar,
            'EXT-TOR-CONC-'.uniqid(),
            '2026-07-01',
            'College Registrar',
            'Registrar',
            TranscriptRequest::SealPlacementInstruction,
            sealPlacementInstruction: 'Affix dry seal.',
        );

        $clearance = app(RecordOfficialOutputPaymentClearance::class)->execute(
            $request,
            $accounting,
            OfficialOutputPaymentClearance::StateNotRequired,
            'AUTH-CLR-CONC-'.uniqid(),
            'Clearance exempt',
        );

        $this->registerCleanup(function () use ($request, $student, $authority, $entry, $specification) {
            $user = $student->user;
            DB::table('output_access_logs')->where('source_record_type', TranscriptRequest::class)->where('source_record_id', $request->id)->delete();
            TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->update([
                'predecessor_event_id' => null,
                'transcript_snapshot_id' => null,
            ]);
            TranscriptIssuanceEvent::query()->where('transcript_request_id', $request->id)->forceDelete();

            $snapshotIds = TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->pluck('id')->toArray();
            if (! empty($snapshotIds)) {
                DB::table('output_access_logs')->where('source_record_type', TranscriptSnapshot::class)->whereIn('source_record_id', $snapshotIds)->delete();
            }
            TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->update([
                'supersedes_snapshot_id' => null,
            ]);
            TranscriptSnapshot::query()->where('transcript_request_id', $request->id)->forceDelete();

            OfficialOutputPaymentClearance::query()->where('transcript_request_id', $request->id)->forceDelete();
            $request->forceDelete();

            DegreeConferral::query()->where('student_profile_id', $student->id)->forceDelete();
            CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->update(['supersedes_readiness_id' => null]);
            CompletionReadinessVersion::query()->where('student_profile_id', $student->id)->forceDelete();
            DB::table('graduation_applications')->where('student_profile_id', $student->id)->update(['supersedes_application_id' => null]);
            DB::table('graduation_applications')->where('student_profile_id', $student->id)->delete();

            ProgramShiftCreditEntry::query()->where('student_lifecycle_change_id', $authority->id)->forceDelete();
            $authority->forceDelete();
            StudentLifecycleChange::query()->where('student_profile_id', $student->id)->forceDelete();
            $entry->forceDelete();
            $specification->forceDelete();
            DB::table('output_access_logs')->where('student_profile_id', $student->id)->delete();
            DB::table('student_profiles')->where('id', $student->id)->delete();
            if ($user) {
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        return [$student, $conferral, $request];
    }

    private function issueTranscriptSnapshot(TranscriptRequest $request, User $registrar): TranscriptSnapshot
    {
        $previewResponse = $this->actingAs($registrar)->get(route('transcripts.preview', $request));
        $confirmation = (string) $previewResponse->headers->get('X-TALA-Preview-Confirmation');

        return app(IssueTranscript::class)->execute(
            $request,
            $registrar,
            'AUTH-ISSUE-CONC-'.uniqid(),
            $confirmation,
        );
    }

    private function voidTranscriptSnapshot(TranscriptSnapshot $snapshot, User $registrar): TranscriptIssuanceEvent
    {
        return app(VoidTranscript::class)->execute(
            $snapshot,
            $registrar,
            'AUTH-VOID-CONC-'.uniqid(),
            'Void predecessor for replacement concurrency test',
        );
    }

    private function createUniqueTerm(string $state = Term::StateActive): Term
    {
        $startYear = mt_rand(4000, 8900);
        $academicYear = AcademicYear::query()->create([
            'label' => "AY-{$startYear}-".($startYear + 1).'-'.uniqid(),
            'starts_on' => "{$startYear}-08-01",
            'ends_on' => ($startYear + 1).'-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $this->registerCleanup(fn () => $academicYear->forceDelete());

        $term = Term::factory()->for($academicYear)->create([
            'state' => $state,
            'label' => 'Term '.uniqid(),
        ]);
        $this->registerCleanup(fn () => $term->forceDelete());

        return $term;
    }

    private function createStaffUser(string $role): User
    {
        $unique = uniqid().mt_rand(1000, 9999);
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email' => "staff_{$role}_{$unique}@example.org",
            'username' => "staff_{$role}_{$unique}",
        ]);
        $user->assignRole($role);
        $this->registerCleanup(fn () => $user->forceDelete());

        return $user;
    }

    private function openTermCalendarPackage(Term $term): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'administrative_starts_on' => now()->subMonth()->toDateString(),
            'administrative_ends_on' => now()->addMonths(6)->toDateString(),
            'classes_start_on' => now()->toDateString(),
            'classes_end_on' => now()->addMonths(5)->toDateString(),
            'activated_at' => now(),
        ]);
        $this->registerCleanup(fn () => $package->forceDelete());

        foreach ([TermCalendarWindow::TypeEnrollment, TermCalendarWindow::TypeEnrollmentAdjustment, TermCalendarWindow::TypeCourseDrop] as $type) {
            $win = TermCalendarWindow::factory()->for($package, 'package')->create([
                'window_type' => $type,
                'opens_on' => now()->subDay()->toDateString(),
                'closes_on' => now()->addMonth()->toDateString(),
                'cutoff_at' => '23:59:59',
            ]);
            $this->registerCleanup(fn () => $win->forceDelete());
        }

        return $package;
    }

    private function createReadyApplicant(Term $term, Program $program): AdmissionApplication
    {
        $cycle = AdmissionCycle::factory()->for($term)->create();

        $application = AdmissionApplication::factory()->for($cycle, 'admissionCycle')->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'application_state' => AdmissionApplication::StateAdmitted,
            'application_path' => AdmissionApplication::PathFirstYear,
        ]);

        $application->user->update(['status' => User::StatusActive]);
        $application->user->assignRole('applicant');

        $requirementSet = AdmissionRequirementSet::factory()->published()->for($cycle)->create([
            'application_path' => $application->application_path,
        ]);

        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create(['submitted_by' => $application->user_id]);

        $application->update(['current_submission_version_id' => $submission->id]);
        $decision = AdmissionDecision::factory()->admitted()->for($application, 'application')->create();

        $this->registerCleanup(function () use ($application, $cycle, $requirementSet, $submission, $decision) {
            $user = $application->user;
            DB::table('applicant_intakes')->where('id', $application->id)->update(['current_submission_version_id' => null]);
            DB::table('admission_decisions')->where('id', $decision->id)->delete();
            DB::table('application_submission_versions')->where('id', $submission->id)->delete();
            DB::table('applicant_intakes')->where('id', $application->id)->delete();
            if ($user) {
                DB::table('users')->where('id', $user->id)->delete();
            }
            DB::table('admission_requirements')->where('admission_requirement_set_id', $requirementSet->id)->delete();
            DB::table('admission_requirement_sets')->where('id', $requirementSet->id)->delete();
            DB::table('admission_cycles')->where('id', $cycle->id)->delete();
        });

        return $application->refresh();
    }

    private function cleanupRegistrationCase(Enrollment $case): void
    {
        $proposalIds = DB::table('registration_proposal_versions')->where('enrollment_id', $case->id)->pluck('id');
        if ($proposalIds->isNotEmpty()) {
            DB::table('enrollment_seat_reservations')->where('enrollment_id', $case->id)->delete();
            DB::table('registration_proposal_confirmations')->whereIn('registration_proposal_version_id', $proposalIds)->delete();
            DB::table('registration_proposal_items')->whereIn('registration_proposal_version_id', $proposalIds)->delete();
            DB::table('registration_proposal_versions')->where('enrollment_id', $case->id)->delete();
        }
        DB::table('enrollment_seat_reservations')->where('enrollment_id', $case->id)->delete();
        DB::table('registration_identity_confirmation_versions')->where('enrollment_id', $case->id)->delete();
        DB::table('registration_case_events')->where('enrollment_id', $case->id)->delete();
        DB::table('enrollment_gate_results')->where('enrollment_id', $case->id)->delete();
        DB::table('enrollments')->where('id', $case->id)->delete();
    }

    private function registerCleanup(callable $callback): void
    {
        $this->cleanupCallbacks[] = $callback;
    }
}
