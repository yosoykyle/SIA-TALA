<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\TAL96D5E1ExplorationPersonaCatalog;
use App\Models\AdmissionApplication;
use App\Models\ApplicantIntake;
use App\Models\PaymentAttempt;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

#[Group('acceptance-fixture')]
final class TAL96D5E1ExplorationPersonaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->artisan('acceptance:seed-client-baseline')->assertSuccessful();
    }

    #[Test]
    public function exploration_overlay_builds_the_exact_sign_in_persona_catalog(): void
    {
        try {
            $this->artisan('acceptance:seed-tal96d5e1-exploration')
                ->expectsOutputToContain('coverage_state=PASS')
                ->expectsOutputToContain('personas=29')
                ->expectsOutputToContain('denied_login_personas=1')
                ->expectsOutputToContain('student_profiles=49')
                ->expectsOutputToContain('current_students=47')
                ->expectsOutputToContain('historical_case_profiles=2')
                ->expectsOutputToContain('cohorts=6')
                ->expectsOutputToContain('term_offerings=54')
                ->expectsOutputToContain('scheduling_demands=54')
                ->expectsOutputToContain('faculty=9')
                ->expectsOutputToContain('presentation_fixture_ready=yes')
                ->assertSuccessful();
        } catch (Throwable $exception) {
            $this->fail('The guarded D5E1 exploration command must exist and succeed: '.$exception->getMessage());
        }

        $activeStaff = [
            'registrar.demo@example.test',
            'accounting.demo@example.test',
            'faculty.demo@example.test',
            'academic-head.demo@example.test',
            'system-admin.demo@example.test',
        ];
        $unverifiedStaff = 'registrar.unverified.demo@example.test';
        $applicants = [
            'applicant.demo@example.test' => [AdmissionApplication::StateDraft, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
            'applicant.review.demo@example.test' => [AdmissionApplication::StateSubmitted, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
            'applicant.action-required.demo@example.test' => [AdmissionApplication::StateActionNeeded, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
            'applicant.evaluation.demo@example.test' => [AdmissionApplication::StateSubmitted, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
            'applicant.approved.demo@example.test' => [AdmissionApplication::StateAdmitted, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
            'applicant.ready.demo@example.test' => [AdmissionApplication::StateAdmitted, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
            'applicant.withdrawn.demo@example.test' => [AdmissionApplication::StateWithdrawn, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
            'applicant.transfer.demo@example.test' => [AdmissionApplication::StateDraft, ApplicantIntake::AdmissionCategoryTransfer, ApplicantIntake::CredentialBasisTransferCredentials, User::StatusActive],
            'applicant.not-admitted.demo@example.test' => [AdmissionApplication::StateNotAdmitted, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusActive],
        ];
        $activeStudents = [
            'student.demo@example.test' => StudentProfile::StandingRegular,
            'student.dbm-2a.002@example.test' => StudentProfile::StandingRegular,
            'student.dit-2a.002@example.test' => StudentProfile::StandingRegular,
            'student.dbm-2a.001@example.test' => StudentProfile::StandingIrregular,
            'student.dit-1a.001@example.test' => StudentProfile::StandingProbationary,
            'student.dit-1a.002@example.test' => StudentProfile::StandingDeficient,
            'student.dit-2a.001@example.test' => StudentProfile::StandingBlockedByPrerequisite,
            'student.dthm-1a.001@example.test' => StudentProfile::StandingMustRepeatYear,
            'student.dthm-1a.002@example.test' => StudentProfile::StandingRegular,
            'student.dthm-2a.001@example.test' => StudentProfile::StandingRegular,
            'student.dthm-2a.002@example.test' => StudentProfile::StandingNotYetEvaluated,
            'student.completion.demo@example.test' => StudentProfile::StandingCompletionCandidate,
            'student.graduation.demo@example.test' => StudentProfile::StandingGraduationCandidate,
        ];
        $unverifiedStudent = 'student.dbm-1a.002@example.test';
        $deniedStaff = 'staff.inactive.demo@example.test';

        $personaEmails = [
            ...$activeStaff,
            $unverifiedStaff,
            ...array_keys($applicants),
            ...array_keys($activeStudents),
            $unverifiedStudent,
        ];
        $this->assertCount(29, array_unique($personaEmails));

        foreach ($activeStaff as $email) {
            $staff = User::query()->where('email', $email)->sole();
            $this->assertNotNull($staff->email_verified_at);
            $this->assertTrue($staff->canAuthenticate());
        }

        $staffBoundary = User::query()->where('email', $unverifiedStaff)->sole();
        $this->assertNull($staffBoundary->email_verified_at);
        $this->assertTrue($staffBoundary->canAuthenticate());
        $this->assertTrue($staffBoundary->hasRole(User::StaffRoleRegistrar));

        foreach ($applicants as $email => [$applicationState, $category, $basis, $userStatus]) {
            $applicant = User::query()->where('email', $email)->sole();
            $application = AdmissionApplication::query()->canonical()->whereBelongsTo($applicant, 'user')->where('term_id', $this->presentationTerm()->id)->sole();

            $this->assertNotNull($applicant->email_verified_at);
            $this->assertTrue($applicant->hasRole('applicant'));
            $this->assertSame($userStatus, $applicant->status);
            $this->assertSame($applicationState, $application->application_state);
            $this->assertSame($category, $application->admission_category);
            $this->assertSame($basis, $application->credential_basis);
            $this->assertNull($application->modality_preference);
        }

        $actionNeeded = AdmissionApplication::query()->canonical()
            ->whereBelongsTo(User::query()->where('email', 'applicant.action-required.demo@example.test')->sole(), 'user')->sole();
        $this->assertSame(1, $actionNeeded->correctionRequests()->where('state', 'Active')->count());
        $this->assertTrue($actionNeeded->evidenceVersions()->whereHas('preliminaryReviews', fn ($query) => $query->where('result', 'ActionNeeded'))->exists());

        $readiness = app(ReadyApplicantProjectionQuery::class);
        $awaitingReview = AdmissionApplication::query()->canonical()
            ->whereBelongsTo(User::query()->where('email', 'applicant.review.demo@example.test')->sole(), 'user')->sole();
        $this->assertNotNull($awaitingReview->current_submission_version_id);
        $this->assertGreaterThan(0, $awaitingReview->evidenceVersions()->count());
        $this->assertFalse($readiness->preliminaryReviewIsComplete($awaitingReview));
        foreach (['applicant.evaluation.demo@example.test', 'applicant.approved.demo@example.test'] as $email) {
            $reviewed = AdmissionApplication::query()->canonical()
                ->whereBelongsTo(User::query()->where('email', $email)->sole(), 'user')->sole();
            $this->assertGreaterThan(0, $reviewed->evidenceVersions()->count());
            $this->assertTrue($readiness->preliminaryReviewIsComplete($reviewed));
            $this->assertFalse($readiness->forApplication($reviewed)['ready']);
        }
        $ready = AdmissionApplication::query()->canonical()
            ->whereBelongsTo(User::query()->where('email', 'applicant.ready.demo@example.test')->sole(), 'user')->sole();
        $this->assertTrue($readiness->forApplication($ready)['ready']);
        $this->assertSame(1, $ready->enrollmentClearances()->where('result', 'Cleared')->count());

        $withdrawn = AdmissionApplication::query()->canonical()
            ->whereBelongsTo(User::query()->where('email', 'applicant.withdrawn.demo@example.test')->sole(), 'user')->sole();
        $withdrawal = $withdrawn->events()->where('event_type', 'Withdrawn')->sole();
        $this->assertSame($withdrawn->user_id, $withdrawal->actor_id);
        $this->assertSame('Synthetic withdrawn history for exploration.', $withdrawal->payload['reason']);
        $notAdmitted = AdmissionApplication::query()->canonical()
            ->whereBelongsTo(User::query()->where('email', 'applicant.not-admitted.demo@example.test')->sole(), 'user')->sole();
        $this->assertSame('NotAdmitted', $notAdmitted->decisions()->whereDoesntHave('successor')->sole()->decision);

        foreach ($activeStudents as $email => $standing) {
            $student = User::query()->where('email', $email)->sole();
            $this->assertNotNull($student->email_verified_at);
            $this->assertTrue($student->canAuthenticate());
            $this->assertTrue($student->hasRole('student'));
            $this->assertSame($standing, $student->studentProfile()->sole()->academic_standing);
        }

        $studentBoundary = User::query()->where('email', $unverifiedStudent)->sole();
        $this->assertNull($studentBoundary->email_verified_at);
        $this->assertTrue($studentBoundary->canAuthenticate());
        $this->assertSame(StudentProfile::StandingIrregular, $studentBoundary->studentProfile()->sole()->academic_standing);

        $denied = User::query()->where('email', $deniedStaff)->sole();
        $this->assertSame(User::StatusInactive, $denied->status);
        $this->assertFalse($denied->canAuthenticate());
        $this->assertTrue($denied->hasRole(User::StaffRoleRegistrar));

        foreach (['CHECKOUT-EXPIRED-001', 'CHECKOUT-REVIEW-001'] as $reference) {
            $attempt = PaymentAttempt::query()->where('internal_reference', $reference)->sole();
            $this->assertNotNull($attempt->term_account_id);
            $this->assertNotNull($attempt->assessment_version);
        }
    }

    #[Test]
    public function exploration_overlay_is_idempotent_and_preserves_the_min_scheduling_contract(): void
    {
        $term = $this->presentationTerm();
        $beforeFingerprint = $this->schedulingFingerprint($term);
        $beforeScheduleRuns = ScheduleGenerationRun::query()->count();
        $beforeMeetings = SectionMeeting::query()->count();

        $this->artisan('acceptance:seed-tal96d5e1-exploration')->assertSuccessful();
        $firstCounts = $this->explorationCounts();

        $this->artisan('acceptance:seed-tal96d5e1-exploration')->assertSuccessful();

        $this->assertSame($firstCounts, $this->explorationCounts());
        $this->assertSame($beforeFingerprint, $this->schedulingFingerprint($term));
        $this->assertSame(49, StudentProfile::query()->count());
        $this->assertSame(54, TermOffering::query()->whereBelongsTo($term)->count());
        $this->assertSame(54, SchedulingDemand::query()->whereHas(
            'termOffering',
            fn ($query) => $query->whereBelongsTo($term),
        )->count());
        $this->assertSame($beforeScheduleRuns, ScheduleGenerationRun::query()->count());
        $this->assertSame($beforeMeetings, SectionMeeting::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function check_mode_reports_an_incomplete_overlay_without_writing(): void
    {
        $this->artisan('acceptance:seed-tal96d5e1-exploration')->assertSuccessful();

        $boundary = User::query()
            ->where('email', 'registrar.unverified.demo@example.test')
            ->sole();
        $boundary->forceFill(['email_verified_at' => now()])->save();
        $before = $this->explorationCounts();

        $this->artisan('acceptance:seed-tal96d5e1-exploration --check')
            ->expectsOutputToContain('outcome=inspection_only')
            ->expectsOutputToContain('coverage_state=FAIL')
            ->assertFailed();

        $this->assertSame($before, $this->explorationCounts());
    }

    #[Test]
    public function check_mode_rejects_an_unknown_or_mismatched_checkpoint_without_writing(): void
    {
        $this->artisan('acceptance:seed-tal96d5e1-exploration')->assertSuccessful();
        $before = $this->explorationCounts();

        $this->artisan('acceptance:seed-tal96d5e1-exploration --check --checkpoint=unknown')
            ->expectsOutputToContain('Checkpoint must be auto, pristine, accepted-candidate, or published.')
            ->assertFailed();

        $detected = app(TAL96D5E1ExplorationPersonaCatalog::class)
            ->report()['checkpoint_detected'];
        $mismatched = $detected === 'pristine' ? 'published' : 'pristine';

        $this->artisan("acceptance:seed-tal96d5e1-exploration --check --checkpoint={$mismatched}")
            ->expectsOutputToContain('checkpoint_ready=no')
            ->assertFailed();

        $this->assertSame($before, $this->explorationCounts());
    }

    #[Test]
    public function check_mode_rejects_a_persona_whose_documented_local_password_changed(): void
    {
        $this->artisan('acceptance:seed-tal96d5e1-exploration')->assertSuccessful();

        $persona = User::query()
            ->where('email', 'student.demo@example.test')
            ->sole();
        $persona->forceFill(['password' => 'changed-password'])->save();
        $before = $this->explorationCounts();

        $this->artisan('acceptance:seed-tal96d5e1-exploration --check')
            ->expectsOutputToContain('coverage_state=FAIL')
            ->assertFailed();

        $this->assertSame($before, $this->explorationCounts());
    }

    #[Test]
    public function exploration_command_fails_closed_outside_the_testing_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        try {
            $this->artisan('acceptance:seed-tal96d5e1-exploration --check')
                ->expectsOutputToContain('requires APP_ENV=testing')
                ->assertFailed();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    #[Test]
    public function documentation_keeps_acceptance_fixtures_non_authoritative(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $baseline = file_get_contents(base_path('00_Project_Documents/prd_modules/00_system_definition_baseline.md'));

        $this->assertIsString($readme);
        $this->assertIsString($baseline);
        $this->assertStringContainsString(
            'Historical fixture-building, provider rehearsal, demonstration, and acceptance instructions are preserved as non-authoritative evidence',
            $readme,
        );
        $this->assertStringContainsString(
            'All PRD acceptance data uses one coordinated, wholly synthetic institution',
            $baseline,
        );
    }

    /**
     * @return array<string, int>
     */
    private function explorationCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'intakes' => ApplicantIntake::query()->count(),
            'checklist_items' => DB::table('checklist_items')->count(),
            'document_evidence' => DB::table('document_evidence')->count(),
            'activities' => DB::table('activity_log')->count(),
            'terms' => DB::table('terms')->count(),
            'term_offerings' => DB::table('term_offerings')->count(),
            'sections' => DB::table('sections')->count(),
            'enrollments' => DB::table('enrollments')->count(),
            'course_enrollments' => DB::table('course_enrollments')->count(),
            'grade_rosters' => DB::table('grade_rosters')->count(),
            'grade_roster_rows' => DB::table('grade_roster_rows')->count(),
            'grade_outcome_events' => DB::table('grade_outcome_events')->count(),
            'holds' => DB::table('holds')->count(),
            'graduation_review_batches' => DB::table('graduation_review_batches')->count(),
            'graduation_review_members' => DB::table('graduation_review_members')->count(),
            'graduation_snapshots' => DB::table('graduation_snapshots')->count(),
        ];
    }

    private function presentationTerm(): Term
    {
        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }

    private function schedulingFingerprint(Term $term): string
    {
        return hash('sha256', SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->orderBy('id')
            ->get([
                'id',
                'term_offering_id',
                'course_component_id',
                'section_delivery_group_id',
                'demand_key',
                'required_duration_minutes',
                'meeting_count',
                'modality',
                'fixed_faculty_user_id',
                'fixed_room_id',
                'fixed_day_of_week',
                'fixed_start_time',
                'source_snapshot',
                'readiness_findings',
                'validation_state',
                'generated_by',
                'readiness_checked_at',
            ])
            ->toJson());
    }
}
