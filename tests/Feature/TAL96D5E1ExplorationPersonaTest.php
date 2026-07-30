<?php

namespace Tests\Feature;

use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\DocumentEvidence;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
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
    }

    #[Test]
    public function exploration_overlay_builds_the_exact_sign_in_persona_catalog(): void
    {
        try {
            $this->artisan('acceptance:seed-tal96d5e1-exploration')
                ->expectsOutputToContain('coverage_state=PASS')
                ->expectsOutputToContain('personas=28')
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
            'applicant.demo@example.test' => [ApplicantIntake::StatusDraft, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusApplicantPending],
            'applicant.review.demo@example.test' => [ApplicantIntake::StatusPending, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusApplicantPending],
            'applicant.action-required.demo@example.test' => [ApplicantIntake::StatusActionRequired, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusApplicantActionRequired],
            'applicant.evaluation.demo@example.test' => [ApplicantIntake::StatusForEvaluation, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusApplicantForEvaluation],
            'applicant.approved.demo@example.test' => [ApplicantIntake::StatusApproved, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusApplicantApproved],
            'applicant.withdrawn.demo@example.test' => [ApplicantIntake::StatusWithdrawn, ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, User::StatusApplicantWithdrawn],
            'applicant.transfer.demo@example.test' => [ApplicantIntake::StatusDraft, ApplicantIntake::AdmissionCategoryTransfer, ApplicantIntake::CredentialBasisTransferCredentials, User::StatusApplicantPending],
            'applicant.returning.demo@example.test' => [ApplicantIntake::StatusDraft, ApplicantIntake::AdmissionCategoryReturning, ApplicantIntake::CredentialBasisPriorStudentRecord, User::StatusApplicantPending],
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
        $this->assertCount(28, array_unique($personaEmails));

        foreach ($activeStaff as $email) {
            $staff = User::query()->where('email', $email)->sole();
            $this->assertNotNull($staff->email_verified_at);
            $this->assertTrue($staff->canAuthenticate());
        }

        $staffBoundary = User::query()->where('email', $unverifiedStaff)->sole();
        $this->assertNull($staffBoundary->email_verified_at);
        $this->assertTrue($staffBoundary->canAuthenticate());
        $this->assertTrue($staffBoundary->hasRole(User::StaffRoleRegistrar));

        foreach ($applicants as $email => [$intakeStatus, $category, $basis, $userStatus]) {
            $applicant = User::query()->where('email', $email)->sole();
            $intake = ApplicantIntake::query()->whereBelongsTo($applicant)->whereBelongsTo($this->presentationTerm())->sole();

            $this->assertNotNull($applicant->email_verified_at);
            $this->assertTrue($applicant->hasRole('applicant'));
            $this->assertSame($userStatus, $applicant->status);
            $this->assertSame($intakeStatus, $intake->status);
            $this->assertSame($category, $intake->admission_category);
            $this->assertSame($basis, $intake->credential_basis);
            $this->assertNull($intake->modality_preference);
        }

        $actionRequiredIntake = ApplicantIntake::query()
            ->whereBelongsTo(User::query()->where('email', 'applicant.action-required.demo@example.test')->sole())
            ->whereBelongsTo($this->presentationTerm())
            ->sole();
        $this->assertTrue($actionRequiredIntake->checklistItems()
            ->where('verification_status', ChecklistItem::VerificationRejected)
            ->exists());
        $this->assertTrue(DocumentEvidence::query()
            ->whereHas(
                'checklistItem',
                fn ($query) => $query->whereBelongsTo($actionRequiredIntake),
            )
            ->where('status', DocumentEvidence::StatusRejected)
            ->exists());

        foreach (['applicant.evaluation.demo@example.test', 'applicant.approved.demo@example.test'] as $email) {
            $resolvedIntake = ApplicantIntake::query()
                ->whereBelongsTo(User::query()->where('email', $email)->sole())
                ->whereBelongsTo($this->presentationTerm())
                ->sole();

            $this->assertGreaterThan(0, $resolvedIntake->checklistItems()->count());
            $this->assertSame(
                0,
                $resolvedIntake->checklistItems()
                    ->where('verification_status', '!=', ChecklistItem::VerificationVerified)
                    ->count(),
            );
        }

        $withdrawnIntake = ApplicantIntake::query()
            ->whereBelongsTo(User::query()->where('email', 'applicant.withdrawn.demo@example.test')->sole())
            ->whereBelongsTo($this->presentationTerm())
            ->sole();
        $this->assertTrue(DB::table('activity_log')
            ->where('subject_type', ApplicantIntake::class)
            ->where('subject_id', $withdrawnIntake->id)
            ->where('event', 'applicant_intake_withdrawn')
            ->where('properties', 'like', '%Plans changed before submission.%')
            ->exists());

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
    public function first_time_documentation_distinguishes_the_pristine_min_check_from_overlay_inspection(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $guide = file_get_contents(base_path('00_Project_Documents/TALA-System-Operations-and-Defense-Guide.md'));

        $this->assertIsString($readme);
        $this->assertIsString($guide);
        $this->assertStringContainsString(
            'Before the presentation overlay exists, the MIN scenario check must report',
            $readme,
        );
        $this->assertStringContainsString(
            'Once the D5E1 overlay exists, use the D5E1 command as the authoritative read-only check',
            $readme,
        );
        $this->assertStringContainsString(
            'Before the presentation overlay exists, prove the pristine MIN fixture',
            $guide,
        );
        $this->assertStringContainsString(
            'After the overlay exists, the exploration check is the authoritative non-writing inspection',
            $guide,
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
        $this->assertSame(49, StudentProfile::query()->count());

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
