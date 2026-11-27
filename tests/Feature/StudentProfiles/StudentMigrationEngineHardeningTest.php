<?php

namespace Tests\Feature\StudentProfiles;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\TranscriptPreview;
use App\Actions\Enrollment\AcademicProgressionService;
use App\Actions\Enrollment\PrepareStandardRegistration;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Actions\StudentProfiles\OnboardExistingStudent;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\RecordPriorAcademicCredits;
use App\Actions\StudentProfiles\StudentAccountActivation;
use App\Actions\StudentProfiles\UndoStudentImportBatch;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Models\AcademicDecision;
use App\Models\AcademicYear;
use App\Models\ApplicantIntake;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\Section;
use App\Models\StudentImportBatch;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use App\Notifications\StudentAccountActivationNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StudentMigrationEngineHardeningTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    public function test_last_successful_sign_in_at_works_across_activation_status_resource_filter_and_csv_export(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->createProgramCurriculumFixture('SIGN');

        $pending = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-7101',
            'first_name' => 'Pending',
            'last_name' => 'Learner',
            'email' => 'pending.7101@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'send_activation_email' => false,
        ], $registrar)['profile'];

        $signedIn = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-7102',
            'first_name' => 'Active',
            'last_name' => 'Learner',
            'email' => 'active.7102@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'send_activation_email' => false,
        ], $registrar)['profile'];

        $signedInAt = now()->subHours(2)->startOfMinute();
        $signedIn->user->forceFill([
            'password' => Hash::make('StrongStudentPass#2026'),
            'email_verified_at' => $signedInAt,
            'last_successful_sign_in_at' => $signedInAt,
        ])->save();

        $this->assertSame(StudentAccountActivation::StatusPendingPasswordSetup, StudentAccountActivation::statusLabel($pending->fresh('user')));
        $this->assertSame(StudentAccountActivation::StatusActivated, StudentAccountActivation::statusLabel($signedIn->fresh('user')));
        $this->assertFalse(StudentAccountActivation::canResendActivation($signedIn->fresh('user')));

        // Resource table filter must query last_successful_sign_in_at without SQLSTATE crash.
        Livewire::test(ListStudentProfiles::class)
            ->filterTable('program', (string) $fixture['program']->id)
            ->filterTable('activation_status', 'activated')
            ->assertCanSeeTableRecords([$signedIn])
            ->assertCanNotSeeTableRecords([$pending]);

        Livewire::test(ListStudentProfiles::class)
            ->filterTable('program', (string) $fixture['program']->id)
            ->filterTable('activation_status', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$signedIn]);

        $csvPath = app(OngoingStudentSpreadsheetExportService::class)->exportActivationStatus(collect([$signedIn->fresh(['user', 'program', 'curriculumVersion'])]));
        $this->tempPaths[] = $csvPath;
        $rows = $this->readCsvRows($csvPath);
        $this->assertSame(StudentAccountActivation::StatusActivated, $rows[1][7]);
        $this->assertSame($signedInAt->format('Y-m-d H:i'), $rows[1][8]);
    }

    public function test_program_shift_retains_active_passing_prior_academic_credits(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $sourceFixture = $this->createProgramCurriculumFixture('SHF1');
        $targetProgram = Program::factory()->create(['code' => 'SHF2', 'is_active' => true]);
        $targetCurriculum = CurriculumVersion::factory()->for($targetProgram)->create([
            'version_code' => 'SHF2-2024',
            'state' => CurriculumVersion::StateActive,
        ]);
        $targetEntry = CurriculumEntry::factory()->for($targetCurriculum)->for($sourceFixture['spec1'], 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $profile = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2024-7201',
            'first_name' => 'Shifting',
            'last_name' => 'Migrated',
            'email' => 'shift.7201@example.test',
            'program_id' => $sourceFixture['program']->id,
            'curriculum_version_id' => $sourceFixture['curriculum']->id,
            'prior_credits' => [
                [
                    'course_specification_id' => $sourceFixture['spec1']->id,
                    'curriculum_entry_id' => $sourceFixture['entry1']->id,
                    'academic_year_label' => 'AY 2023-2024',
                    'term_label' => 'First Semester',
                    'final_grade' => '1.75',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                ],
            ],
            'send_activation_email' => false,
        ], $registrar)['profile'];

        $priorCredit = PriorAcademicCredit::query()->where('student_profile_id', $profile->id)->sole();
        $lifecycle = app(StudentLifecycleService::class);

        $sources = $lifecycle->programShiftSources($profile);
        $this->assertCount(1, $sources);
        $this->assertSame($priorCredit->id, $sources->first()['prior_credit']->id);

        $suggestions = $lifecycle->matchingSiaCreditSuggestions($profile, $targetCurriculum);
        $this->assertCount(1, $suggestions['already_satisfied']);
        $this->assertSame($priorCredit->id, $suggestions['already_satisfied'][0]['source_prior_academic_credit_id']);

        $futureStart = today('Asia/Manila')->addDays(10);
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => $futureStart,
            'ends_on' => $futureStart->copy()->addMonths(4),
        ]);

        $change = $lifecycle->record([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'effective_on' => $futureStart->toDateString(),
            'reason' => 'Approved shift for migrated student',
            'target_program_id' => $targetProgram->id,
            'target_curriculum_version_id' => $targetCurriculum->id,
            'late_exception_authority' => 'REG-MEMO-2026',
            'late_exception_reason' => 'Approved shift outside window',
            'credit_entries' => [
                [
                    'curriculum_entry_id' => $targetEntry->id,
                    'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
                    'source_prior_academic_credit_id' => $priorCredit->id,
                ],
            ],
        ], $registrar, suppressNotification: true);

        $this->travelTo($futureStart->copy()->addDay());
        $applied = $lifecycle->applyProgramShift($change, $registrar);
        $this->travelBack();
        $this->assertSame(StudentLifecycleChange::StateApplied, $applied->state);
        $this->assertDatabaseHas('program_shift_credit_entries', [
            'student_lifecycle_change_id' => $applied->id,
            'curriculum_entry_id' => $targetEntry->id,
            'source_prior_academic_credit_id' => $priorCredit->id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
        ]);
    }

    public function test_prior_inc_settlement_unblocks_gwa_readiness_supersedes_tor_and_checklist_preserves_non_passing_rows(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->createProgramCurriculumFixture('INCS');
        $records = app(RecordPriorAcademicCredits::class);

        $profile = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2024-7301',
            'first_name' => 'Incomplete',
            'last_name' => 'Settler',
            'email' => 'inc.7301@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'prior_credits' => [
                [
                    'course_specification_id' => $fixture['spec1']->id,
                    'curriculum_entry_id' => $fixture['entry1']->id,
                    'academic_year_label' => 'AY 2023-2024',
                    'term_label' => 'First Semester',
                    'final_grade' => 'INC',
                    'result_status' => PriorAcademicCredit::ResultInc,
                ],
                [
                    'course_specification_id' => $fixture['spec2']->id,
                    'curriculum_entry_id' => $fixture['entry2']->id,
                    'academic_year_label' => 'AY 2023-2024',
                    'term_label' => 'First Semester',
                    'final_grade' => '5.00',
                    'result_status' => PriorAcademicCredit::ResultFailed,
                ],
            ],
            'send_activation_email' => false,
        ], $registrar)['profile'];

        // Before settlement: INC blocks Cumulative GWA and Completion Readiness.
        $gwaBefore = app(CumulativeGwaProjection::class)->forStudent($profile);
        $this->assertSame(AcademicAverageReadiness::IncompleteResultPending, $gwaBefore['state']);
        $this->assertNull($gwaBefore['value']);

        $readinessBefore = app(CompletionReadinessProjection::class)->forStudent($profile);
        $this->assertTrue(collect($readinessBefore['blockers'])->contains('code', 'official-result:inc-unresolved'));

        // Checklist sync must NEVER revoke historical failed or INC rows.
        $records->syncChecklistCredits($profile, [], $registrar);
        $this->assertSame(
            2,
            PriorAcademicCredit::query()->active()->where('student_profile_id', $profile->id)->count(),
        );

        // Issue a mock TOR snapshot so we can verify settlement supersedes it.
        $torRequest = TranscriptRequest::factory()->create([
            'student_profile_id' => $profile->id,
            'external_request_reference' => 'TOR-INC-7301',
            'state' => TranscriptRequest::StateIssued,
            'recorded_by' => $registrar->id,
        ]);
        $snapshot = TranscriptSnapshot::query()->create([
            'transcript_request_id' => $torRequest->id,
            'version' => 1,
            'reference' => 'TOR-2026-7301-V1',
            'template_version' => TranscriptRequest::TemplateServitechV2,
            'source_fingerprint' => str_repeat('a', 64),
            'content' => [],
            'status' => TranscriptSnapshot::StatusIssued,
            'issued_by' => $registrar->id,
            'issued_at' => now(),
        ]);

        // Reject contradictory grade/status pair and DRAFT specification.
        try {
            $records->upsertPriorCredit($profile, [
                'course_specification_id' => $fixture['spec1']->id,
                'final_grade' => '5.00',
                'result_status' => PriorAcademicCredit::ResultPassed,
            ], $registrar);
            $this->fail('Expected contradictory grade/status pair to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('final_grade', $exception->errors());
        }

        $draftCourse = Course::factory()->create(['code' => 'DRFT 101']);
        $draftSpec = CourseSpecification::factory()->for($draftCourse)->create(['state' => CourseSpecification::StateDraft]);
        try {
            $records->upsertPriorCredit($profile, [
                'course_specification_id' => $draftSpec->id,
                'final_grade' => '1.50',
                'result_status' => PriorAcademicCredit::ResultPassed,
            ], $registrar);
            $this->fail('Expected DRAFT course specification to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('course_specification_id', $exception->errors());
        }

        // Settle the pre-TALA INC to 1.75.
        $incCredit = PriorAcademicCredit::query()
            ->where('student_profile_id', $profile->id)
            ->where('result_status', PriorAcademicCredit::ResultInc)
            ->sole();

        $settled = $records->settlePriorInc($incCredit, '1.75', $registrar, null, 'Completed exam');
        $this->assertSame('1.75', $settled->final_grade);
        $this->assertSame(PriorAcademicCredit::ResultPassed, $settled->result_status);

        // Cumulative GWA is now available (1.75*3 + 5.00*3) / 6 = 3.38.
        $gwaAfter = app(CumulativeGwaProjection::class)->forStudent($profile->fresh());
        $this->assertSame(AcademicAverageReadiness::Available, $gwaAfter['state']);
        $this->assertSame('3.38', $gwaAfter['value']);

        // CompletionReadinessVersion persisted without INC blocker and TOR snapshot superseded.
        $latestReadiness = $profile->completionReadinessVersions()->latest('version')->first();
        $this->assertNotNull($latestReadiness);
        $this->assertFalse(collect($latestReadiness->blockers)->contains('code', 'official-result:inc-unresolved'));
        $this->assertDatabaseHas('transcript_issuance_events', [
            'transcript_snapshot_id' => $snapshot->id,
            'type' => TranscriptIssuanceEvent::TypeSuperseded,
        ]);
    }

    public function test_batch_curriculum_resolution_removal_protection_canonical_names_advising_and_applicant_linking(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BTCH', 'is_active' => true]);
        $ay2023 = AcademicYear::factory()->create(['label' => 'AY 2023-2024', 'starts_on' => '2023-08-01', 'ends_on' => '2024-05-31']);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2095-2096', 'starts_on' => '2095-08-01', 'ends_on' => '2096-05-31']);

        $retiredCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'BTCH-2023',
            'state' => CurriculumVersion::StateSuperseded,
        ]);
        $activeCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'BTCH-2025',
            'state' => CurriculumVersion::StateActive,
        ]);

        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $retiredCurriculum->id,
            'academic_year_id' => $ay2023->id,
            'added_by' => $registrar->id,
        ]);
        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $activeCurriculum->id,
            'academic_year_id' => $ay2025->id,
            'added_by' => $registrar->id,
        ]);

        $c1 = Course::factory()->create(['code' => 'BTCH 101']);
        $s1 = CourseSpecification::factory()->for($c1)->create(['state' => CourseSpecification::StateActive, 'credit_units' => 3]);
        CurriculumEntry::factory()->for($retiredCurriculum)->for($s1, 'courseSpecification')->create([
            'year_level' => '1', 'term_type' => Term::TypeFirstSemester, 'term_label' => 'First Semester', 'sequence' => 1,
        ]);
        $c2 = Course::factory()->create(['code' => 'BTCH 102']);
        $s2 = CourseSpecification::factory()->for($c2)->create(['state' => CourseSpecification::StateActive, 'credit_units' => 3]);
        CurriculumEntry::factory()->for($retiredCurriculum)->for($s2, 'courseSpecification')->create([
            'year_level' => '1', 'term_type' => Term::TypeSecondSemester, 'term_label' => 'Second Semester', 'sequence' => 2,
        ]);

        // Unknown standing & lifecycle must throw ValidationException (no silent coercion).
        try {
            app(OnboardExistingStudent::class)->execute([
                'first_name' => 'Bad',
                'last_name' => 'Standing',
                'email' => 'bad.standing@example.test',
                'program_id' => $program->id,
                'entry_academic_year_id' => $ay2023->id,
                'academic_standing' => 'MadeUpStanding',
            ], $registrar);
            $this->fail('Expected unknown academic_standing to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('academic_standing', $exception->errors());
        }

        // Batch resolution resolves retired 2023 curriculum without curriculum_version_id + Probationary gets advising decision.
        $onboarded = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-7401',
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Diaz',
            'email' => 'maria.diaz.7401@example.test',
            'program_id' => $program->id,
            'entry_academic_year_id' => $ay2023->id,
            'academic_standing' => StudentProfile::StandingProbationary,
            'completed_through_year_level' => 1,
            'completed_through_term_type' => Term::TypeFirstSemester,
            'send_activation_email' => false,
        ], $registrar);

        $profile = $onboarded['profile'];
        $user = $onboarded['user'];

        $this->assertSame($retiredCurriculum->id, $profile->curriculum_version_id);
        $this->assertSame($ay2023->id, $profile->entry_academic_year_id);
        $this->assertSame('Maria', $user->first_name);
        $this->assertSame('Santos', $user->middle_name);
        $this->assertSame('Diaz', $user->last_name);
        $this->assertSame(1, $onboarded['prior_credits_created']);

        // Calling applyCompletedThroughTerm again is idempotent (0 new rows).
        $idempotent = app(RecordPriorAcademicCredits::class)->applyCompletedThroughTerm($profile, 1, Term::TypeFirstSemester, $registrar);
        $this->assertSame(0, $idempotent['created']);
        $this->assertSame(1, $idempotent['skipped_duplicates']);

        $this->assertDatabaseHas('academic_decisions', [
            'student_profile_id' => $profile->id,
            'effect' => AcademicDecision::EffectAdvisingRequired,
            'state' => AcademicDecision::StateActive,
        ]);

        // Removing Batch 2023-24 is blocked by student_profiles.entry_academic_year_id even when entry_term_id is NULL.
        $this->assertNull($profile->entry_term_id);
        try {
            app(CurriculumVersionLifecycleService::class)->removeBatch($registrar, $retiredCurriculum, $ay2023);
            $this->fail('Expected removeBatch to block deletion when student_profiles.entry_academic_year_id references the batch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('academic_year_id', $exception->errors());
        }

        // Applicant collision: rejected by default, linked & intake withdrawn when opted in.
        Role::findOrCreate('applicant', 'web');
        $applicantUser = User::factory()->create(['email' => 'applicant.7402@example.test', 'status' => User::StatusActive]);
        $applicantUser->assignRole('applicant');
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicantUser->id,
            'email' => $applicantUser->email,
            'status' => ApplicantIntake::StatusDraft,
            'handed_over_at' => null,
        ]);

        try {
            app(OnboardExistingStudent::class)->execute([
                'first_name' => 'Existing',
                'last_name' => 'Applicant',
                'email' => $applicantUser->email,
                'program_id' => $program->id,
                'entry_academic_year_id' => $ay2023->id,
            ], $registrar);
            $this->fail('Expected applicant email collision to block unless link_existing_applicant is true.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $linked = app(OnboardExistingStudent::class)->execute([
            'first_name' => 'Existing',
            'last_name' => 'Applicant',
            'email' => $applicantUser->email,
            'program_id' => $program->id,
            'entry_academic_year_id' => $ay2023->id,
            'link_existing_applicant' => true,
            'send_activation_email' => false,
        ], $registrar);

        $this->assertTrue($linked['linked_existing_applicant']);
        $this->assertSame($applicantUser->id, $linked['user']->id);
        $this->assertTrue($linked['user']->hasAssignedRole('student'));
        $this->assertSame(ApplicantIntake::StatusWithdrawn, $intake->fresh()->status);
        $this->assertNotNull($intake->fresh()->archived_at);
    }

    public function test_null_entry_term_demand_reenrollment_block_tor_dedup_chronology_external_transfer_and_equivalencies(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->createProgramCurriculumFixture('HARD');

        // Create an equivalent legacy course for entry2.
        $legacyCourse = Course::factory()->create(['code' => 'LEGACY-2']);
        $legacySpec = CourseSpecification::factory()->for($legacyCourse)->create([
            'title' => 'Legacy Equivalent 2',
            'credit_units' => 3,
            'state' => CourseSpecification::StateRetired,
        ]);
        CourseRequirement::query()->create([
            'course_specification_id' => $fixture['spec2']->id,
            'rule_type' => CourseRequirement::TypeEquivalency,
            'group_key' => 'eq-1',
            'sequence' => 1,
            'related_course_id' => $legacyCourse->id,
            'state' => CourseRequirement::StateActive,
        ]);

        // External transfer course.
        $transferCourse = Course::factory()->create(['code' => 'XFER 101']);
        $transferSpec = CourseSpecification::factory()->for($transferCourse)->create([
            'title' => 'External Transfer Subject',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        $ay2023 = AcademicYear::factory()->create(['label' => 'AY 2023-2024', 'starts_on' => '2023-08-01', 'ends_on' => '2024-05-31']);
        $term2023 = Term::factory()->create([
            'academic_year_id' => $ay2023->id,
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2023-08-15',
            'ends_on' => '2023-12-15',
            'state' => Term::StateClosed,
        ]);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2095-2096', 'starts_on' => '2095-08-01', 'ends_on' => '2096-05-31']);
        $activeTerm = Term::factory()->create([
            'academic_year_id' => $ay2025->id,
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2025-08-15',
            'ends_on' => '2025-12-15',
            'state' => Term::StateActive,
        ]);

        $baselineContinuing = app(PrepareTermClassProposal::class)->demandEvidence($activeTerm)['continuing_profiles'];

        $profile = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-7501',
            'first_name' => 'Hardened',
            'last_name' => 'Student',
            'email' => 'hard.7501@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'entry_academic_year_id' => $ay2023->id,
            'prior_credits' => [
                [
                    'course_specification_id' => $fixture['spec1']->id,
                    'curriculum_entry_id' => $fixture['entry1']->id,
                    'academic_year_label' => 'AY 2024-2025',
                    'term_label' => 'First Semester',
                    'year_level' => 2,
                    'final_grade' => '1.50',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                ],
                [
                    'course_specification_id' => $legacySpec->id,
                    'academic_year_label' => 'AY 2024-2025',
                    'term_label' => 'First Semester',
                    'year_level' => 2,
                    'final_grade' => '2.00',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                ],
                [
                    'course_specification_id' => $transferSpec->id,
                    'academic_year_label' => 'AY 2024-2025',
                    'term_label' => 'First Semester',
                    'year_level' => 1,
                    'final_grade' => '1.00',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                    'source_school' => 'Other College',
                    'is_external_transfer' => true,
                ],
            ],
            'send_activation_email' => false,
        ], $registrar)['profile'];

        // 1. PrepareTermClassProposal includes migrated profile with NULL entry_term_id.
        $this->assertNull($profile->entry_term_id);
        $this->assertSame(
            $baselineContinuing + 1,
            app(PrepareTermClassProposal::class)->demandEvidence($activeTerm)['continuing_profiles'],
        );

        // 2. RegistrationAcademicEligibilityQuery blocks re-enrolling already-passed prior credit course.
        $offering1 = TermOffering::factory()->create([
            'term_id' => $activeTerm->id,
            'curriculum_entry_id' => $fixture['entry1']->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $draftEnrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $activeTerm->id,
        ]);
        try {
            app(RegistrationAcademicEligibilityQuery::class)->assertEligible($draftEnrollment, $fixture['curriculum'], collect([$offering1]));
            $this->fail('Expected re-enrollment in already-passed prior subject to be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections', $exception->errors());
        }

        // 3. Add a matched 2023 TALA grade for spec1 (retake/duplicate course) to test TOR chronology & unit dedup.
        $offering2023 = TermOffering::factory()->create(['term_id' => $term2023->id, 'curriculum_entry_id' => $fixture['entry1']->id]);
        $section2023 = Section::factory()->create(['term_offering_id' => $offering2023->id]);
        $enrollment2023 = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term2023->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $ce2023 = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment2023->id,
            'term_offering_id' => $offering2023->id,
            'section_id' => $section2023->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3.00,
        ]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering2023->id,
            'section_id' => $section2023->id,
            'faculty_user_id' => $faculty->id,
            'state' => GradeRoster::StateReleased,
            'released_at' => now(),
        ]);
        $row = GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $ce2023->id,
            'current_outcome_code' => '2.50',
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'released_at' => now(),
        ]);
        GradeOutcomeEvent::query()->create([
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => '2.50',
            'new_value' => '2.50',
            'new_category' => GradeRosterRow::CategoryPassing,
            'reason' => 'Initial official grade release',
            'recorded_by' => $registrar->id,
            'released_at' => now(),
            'source_key' => 'hard-7501-2023',
        ]);

        // External transfer (1.00) must be EXCLUDED from SIA Cumulative GWA!
        // Included numeric attempts: 2.50 (3u) + 1.50 (3u) + 2.00 (3u) = 18.00 / 9 = 2.00.
        $gwa = app(CumulativeGwaProjection::class)->forStudent($profile);
        $this->assertSame('2.00', $gwa['value']);
        $this->assertSame(3, $gwa['included_attempts']);

        // TOR chronology: matched AY 2023-2024 MUST appear BEFORE unmatched AY 2024-2025!
        // Earned units: spec1 (3u) counted ONCE + legacy (3u) + transfer (3u) = 9.00 (NOT 12.00!).
        $torRequest = TranscriptRequest::factory()->create([
            'student_profile_id' => $profile->id,
            'external_request_reference' => 'TOR-HARD-7501',
            'template_version' => TranscriptRequest::TemplateServitechV2,
            'signatory_name' => 'Registrar Name',
            'signatory_title' => 'School Registrar',
            'state' => TranscriptRequest::StateOpen,
            'recorded_by' => $registrar->id,
        ]);
        $tor = app(TranscriptPreview::class)->forRequest($torRequest);
        $yearKeys = array_keys($tor['academic_years']);
        $this->assertSame(['AY 2023-2024', 'AY 2024-2025'], $yearKeys);

        $allTorRows = collect($tor['academic_years'])->flatten(1);
        $transferRow = $allTorRows->firstWhere('course_code', 'XFER 101');
        $this->assertSame('TC', $transferRow['result']);
        $this->assertSame('Transfer credit', $transferRow['attempt_or_credit']);

        $lastRow = $allTorRows->last();
        $this->assertSame('9.00', $lastRow['term_summary']['cumulative_earned_units']);

        // 4. Equivalency expansion in AcademicProgressionService & GraduationEligibilitySnapshotService!
        // entry2 is satisfied via LEGACY-2 equivalency.
        $progression = app(AcademicProgressionService::class)->evaluate($profile);
        $this->assertSame(2, $progression['facts']['completed_count']);
        $this->assertSame('2.00', $progression['gwa']);

        $gradBatch = GraduationReviewBatch::factory()->create([
            'academic_year_id' => $activeTerm->academic_year_id,
            'term_id' => $activeTerm->id,
            'created_by' => $registrar->id,
        ]);
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $gradBatch->id,
            'student_profile_id' => $profile->id,
            'added_by' => $registrar->id,
        ]);
        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);
        $this->assertSame(GraduationEligibilitySnapshotService::ResultComplete, $snapshot->result_status);
        $this->assertCount(2, $snapshot->evaluation_snapshot['completed_requirements']);
        $this->assertCount(0, $snapshot->evaluation_snapshot['missing_requirements']);
    }

    public function test_uncredited_prior_cohort_routes_to_individually_advised_in_prepare_standard_registration(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->createProgramCurriculumFixture('ADVS');
        $ay2023 = AcademicYear::factory()->create(['label' => 'AY 2023-2024', 'starts_on' => '2023-08-01', 'ends_on' => '2024-05-31']);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2095-2096', 'starts_on' => '2095-08-01', 'ends_on' => '2096-05-31']);
        $term2025 = Term::factory()->create(['academic_year_id' => $ay2025->id, 'type' => Term::TypeFirstSemester]);

        $profile = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-7601',
            'first_name' => 'Uncredited',
            'last_name' => 'Cohort',
            'email' => 'uncredited.7601@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'entry_academic_year_id' => $ay2023->id,
            'send_activation_email' => false,
        ], $registrar)['profile'];

        $this->assertDatabaseHas('academic_decisions', [
            'student_profile_id' => $profile->id,
            'effect' => AcademicDecision::EffectAdvisingRequired,
            'state' => AcademicDecision::StateActive,
        ]);

        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term2025->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
        ]);

        $prepared = app(PrepareStandardRegistration::class)->execute($enrollment, $registrar);
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $prepared->selection_basis);
    }

    public function test_seven_day_student_activation_broker_notification_and_reset_password_copy(): void
    {
        Notification::fake();

        $this->assertSame(10080, config('auth.passwords.student_activation.expire'));

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->createProgramCurriculumFixture('ACTV');

        $result = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2026-7701',
            'first_name' => 'Camille',
            'last_name' => 'Reyes',
            'email' => 'camille.reyes.7701@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'send_activation_email' => true,
        ], $registrar);

        $user = $result['user'];
        $capturedNotification = null;
        Notification::assertSentTo($user, StudentAccountActivationNotification::class, function (StudentAccountActivationNotification $notification) use (&$capturedNotification, $user): bool {
            $capturedNotification = $notification;
            $mail = $notification->toMail($user);

            return str_contains($mail->subject, 'Activate your Servitech Student Hub account')
                && str_contains($notification->url, 'purpose=activation');
        });

        $this->assertInstanceOf(StudentAccountActivationNotification::class, $capturedNotification);

        // Age token to 2 hours old (expired for 60-min users broker, still valid for 7-day student_activation broker!).
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subHours(2)]);

        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(ResetPassword::class, [
            'email' => $user->email,
            'token' => $capturedNotification->token,
            'purpose' => StudentAccountActivation::PurposeActivation,
        ])
            ->assertSee('Activate your student account')
            ->assertSee('Set your password to open Servitech Student Hub.')
            ->fillForm([
                'password' => 'MyVeryStrongStudentPass#2026',
                'passwordConfirmation' => 'MyVeryStrongStudentPass#2026',
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertNotNull($user->password);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_prior_balance_hold_blocks_enrollment_official_tor_and_graduation_readiness(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->createProgramCurriculumFixture('HOLD');

        $profile = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2024-7801',
            'first_name' => 'Balance',
            'last_name' => 'Holder',
            'email' => 'hold.7801@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'financial_hold_note' => 'Unpaid legacy balance PHP 8,500',
            'prior_curriculum_entry_ids' => [$fixture['entry1']->id, $fixture['entry2']->id],
            'send_activation_email' => false,
        ], $registrar)['profile'];

        // Blocks next-term enrollment.
        $this->assertTrue(app(HoldEvaluationService::class)->hasActiveBlockingHold($profile, [Hold::BlockingEnrollment]));

        // Blocks Official TOR preview.
        $torRequest = TranscriptRequest::factory()->create([
            'student_profile_id' => $profile->id,
            'external_request_reference' => 'TOR-HOLD-7801',
            'template_version' => TranscriptRequest::TemplateServitechV2,
            'signatory_name' => 'Registrar Name',
            'signatory_title' => 'School Registrar',
            'state' => TranscriptRequest::StateOpen,
            'recorded_by' => $registrar->id,
        ]);

        try {
            app(TranscriptPreview::class)->forRequest($torRequest);
            $this->fail('Expected prior-balance hold to block Official TOR preview.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transcript', $exception->errors());
        }

        // Blocks Completion Readiness & Graduation Snapshot.
        $readiness = app(CompletionReadinessProjection::class)->forStudent($profile);
        $this->assertSame(CompletionReadinessProjection::NotEligible, $readiness['state']);

        $term = Term::factory()->create();
        $batch = GraduationReviewBatch::factory()->create(['academic_year_id' => $term->academic_year_id, 'term_id' => $term->id, 'created_by' => $registrar->id]);
        $member = GraduationReviewMember::factory()->create(['graduation_review_batch_id' => $batch->id, 'student_profile_id' => $profile->id, 'added_by' => $registrar->id]);
        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);
        $this->assertSame(GraduationEligibilitySnapshotService::ResultBlockedHoldOrClearance, $snapshot->result_status);
    }

    public function test_undo_student_import_batch_reverts_untouched_batch_preserves_linked_users_and_blocks_touched_batches(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->createProgramCurriculumFixture('UNDO');

        Role::findOrCreate('applicant', 'web');
        $existingApplicant = User::factory()->create([
            'email' => 'linked.undo@example.test',
            'status' => User::StatusActive,
            'created_at' => now()->subDays(2),
        ]);
        $existingApplicant->assignRole('applicant');

        $batch = StudentImportBatch::query()->create([
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'imported_by' => $registrar->id,
            'student_count' => 2,
            'filename' => 'cohort-undo.xlsx',
        ]);

        $freshStudent = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2024-7901',
            'first_name' => 'Fresh',
            'last_name' => 'Undo',
            'email' => 'fresh.undo@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'import_batch_id' => $batch->id,
            'financial_hold_note' => 'Legacy balance',
            'prior_curriculum_entry_ids' => [$fixture['entry1']->id],
            'send_activation_email' => false,
        ], $registrar);

        $linkedStudent = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2024-7902',
            'first_name' => 'Linked',
            'last_name' => 'Undo',
            'email' => $existingApplicant->email,
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'import_batch_id' => $batch->id,
            'link_existing_applicant' => true,
            'send_activation_email' => false,
        ], $registrar);

        $undo = app(UndoStudentImportBatch::class);
        $this->assertTrue($undo->canUndo($batch)['allowed']);

        $result = $undo->execute($batch, $registrar);
        $this->assertSame(2, $result['reverted_profiles']);
        $this->assertSame(1, $result['deleted_users']);
        $this->assertSame(1, $result['preserved_users']);

        $this->assertDatabaseMissing('student_profiles', ['id' => $freshStudent['profile']->id]);
        $this->assertDatabaseMissing('student_profiles', ['id' => $linkedStudent['profile']->id]);
        $this->assertDatabaseMissing('users', ['id' => $freshStudent['user']->id]);
        $this->assertDatabaseHas('users', ['id' => $existingApplicant->id]);
        $this->assertNotNull($batch->fresh()->reverted_at);
        $this->assertSame($registrar->id, $batch->fresh()->reverted_by);

        // Second batch: once a student signs in or sets a password, Undo must be blocked.
        $touchedBatch = StudentImportBatch::query()->create([
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'imported_by' => $registrar->id,
            'student_count' => 1,
            'filename' => 'cohort-touched.xlsx',
        ]);
        $touched = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2024-7903',
            'first_name' => 'Touched',
            'last_name' => 'Student',
            'email' => 'touched.undo@example.test',
            'program_id' => $fixture['program']->id,
            'curriculum_version_id' => $fixture['curriculum']->id,
            'import_batch_id' => $touchedBatch->id,
            'send_activation_email' => false,
        ], $registrar);
        $touched['user']->forceFill(['last_successful_sign_in_at' => now()])->save();

        $this->assertFalse($undo->canUndo($touchedBatch)['allowed']);
        $this->expectException(ValidationException::class);
        $undo->execute($touchedBatch, $registrar);
    }

    /**
     * @return array{program: Program, curriculum: CurriculumVersion, course1: Course, course2: Course, spec1: CourseSpecification, spec2: CourseSpecification, entry1: CurriculumEntry, entry2: CurriculumEntry}
     */
    private function createProgramCurriculumFixture(string $prefix): array
    {
        $program = Program::factory()->create([
            'code' => $prefix,
            'name' => "Diploma in {$prefix}",
            'duration_years' => 3,
            'is_active' => true,
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => "{$prefix}-2023",
            'state' => CurriculumVersion::StateActive,
        ]);

        $course1 = Course::factory()->create(['code' => "{$prefix} 101"]);
        $spec1 = CourseSpecification::factory()->for($course1)->create([
            'title' => "{$prefix} Intro",
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry1 = CurriculumEntry::factory()->for($curriculum)->for($spec1, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $course2 = Course::factory()->create(['code' => "{$prefix} 102"]);
        $spec2 = CourseSpecification::factory()->for($course2)->create([
            'title' => "{$prefix} Intermediate",
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry2 = CurriculumEntry::factory()->for($curriculum)->for($spec2, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
            'term_label' => 'Second Semester',
            'sequence' => 2,
        ]);

        return [
            'program' => $program,
            'curriculum' => $curriculum,
            'course1' => $course1,
            'course2' => $course2,
            'spec1' => $spec1,
            'spec2' => $spec2,
            'entry1' => $entry1,
            'entry2' => $entry2,
        ];
    }

    /** @return list<list<string>> */
    private function readCsvRows(string $path): array
    {
        $reader = new CsvReader;
        $reader->open($path);
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn (mixed $value): string => (string) $value, $row->toArray());
                }
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private function staff(string $roleName): User
    {
        Role::findOrCreate('student', 'web');
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->syncPermissions([
                Permission::findOrCreate('manage-schedules', 'web'),
                Permission::findOrCreate('approve-documents', 'web'),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }
}
