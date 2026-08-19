<?php

namespace App\Actions\SystemAdministration;

use App\Models\ApplicantIntake;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\GraduationSnapshot;
use App\Models\Hold;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Defines and inspects the guarded TAL-96D5E1 first-time exploration accounts.
 *
 * This catalogue is test-only acceptance scaffolding. It does not authorize
 * production accounts, real identities, provider credentials, or solver work.
 */
final class TAL96D5E1ExplorationPersonaCatalog
{
    public const CheckpointAuto = 'auto';

    public const CheckpointPristine = 'pristine';

    public const CheckpointAcceptedCandidate = 'accepted-candidate';

    public const CheckpointPublished = 'published';

    public function __construct(
        private readonly CanonicalTalaSchedulingDataset $canonicalDataset,
    ) {}

    /**
     * @return array<string, string>
     */
    public function activeStaff(): array
    {
        return [
            'registrar.demo@example.test' => User::StaffRoleRegistrar,
            'accounting.demo@example.test' => User::StaffRoleAccounting,
            'faculty.demo@example.test' => User::StaffRoleFaculty,
            'academic-head.demo@example.test' => User::StaffRoleAcademicHead,
            'system-admin.demo@example.test' => User::StaffRoleSystemSuperAdmin,
        ];
    }

    /**
     * @return array{email:string,role:string}
     */
    public function unverifiedStaff(): array
    {
        return [
            'email' => 'registrar.unverified.demo@example.test',
            'role' => User::StaffRoleRegistrar,
        ];
    }

    /**
     * @return array<string, array{label:string,intake_status:string,admission_category:string,credential_basis:string,user_status:string}>
     */
    public function applicants(): array
    {
        return [
            'applicant.demo@example.test' => [
                'label' => 'First-time applicant with an editable draft',
                'intake_status' => ApplicantIntake::StatusDraft,
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'user_status' => User::StatusApplicantPending,
            ],
            'applicant.review.demo@example.test' => [
                'label' => 'First-time applicant pending Registrar review',
                'intake_status' => ApplicantIntake::StatusPending,
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'user_status' => User::StatusApplicantPending,
            ],
            'applicant.action-required.demo@example.test' => [
                'label' => 'First-time applicant with rejected evidence to replace',
                'intake_status' => ApplicantIntake::StatusActionRequired,
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'user_status' => User::StatusApplicantActionRequired,
            ],
            'applicant.evaluation.demo@example.test' => [
                'label' => 'First-time applicant ready for admission evaluation',
                'intake_status' => ApplicantIntake::StatusForEvaluation,
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'user_status' => User::StatusApplicantForEvaluation,
            ],
            'applicant.approved.demo@example.test' => [
                'label' => 'First-time applicant approved for controlled handover',
                'intake_status' => ApplicantIntake::StatusApproved,
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'user_status' => User::StatusApplicantApproved,
            ],
            'applicant.withdrawn.demo@example.test' => [
                'label' => 'Withdrawn applicant retained as non-actionable history',
                'intake_status' => ApplicantIntake::StatusWithdrawn,
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'user_status' => User::StatusApplicantWithdrawn,
            ],
            'applicant.transfer.demo@example.test' => [
                'label' => 'Transferee with an editable draft',
                'intake_status' => ApplicantIntake::StatusDraft,
                'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
                'credential_basis' => ApplicantIntake::CredentialBasisTransferCredentials,
                'user_status' => User::StatusApplicantPending,
            ],
            'applicant.returning.demo@example.test' => [
                'label' => 'Returning student with an editable draft',
                'intake_status' => ApplicantIntake::StatusDraft,
                'admission_category' => ApplicantIntake::AdmissionCategoryReturning,
                'credential_basis' => ApplicantIntake::CredentialBasisPriorStudentRecord,
                'user_status' => User::StatusApplicantPending,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function activeStudents(): array
    {
        return [
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
    }

    /**
     * @return array{email:string,standing:string}
     */
    public function unverifiedStudent(): array
    {
        return [
            'email' => 'student.dbm-1a.002@example.test',
            'standing' => StudentProfile::StandingIrregular,
        ];
    }

    /**
     * @return array{email:string,role:string,status:string}
     */
    public function deniedLoginPersona(): array
    {
        return [
            'email' => 'staff.inactive.demo@example.test',
            'role' => User::StaffRoleRegistrar,
            'status' => User::StatusInactive,
        ];
    }

    /**
     * @return list<string>
     */
    public function personaEmails(): array
    {
        return [
            ...array_keys($this->activeStaff()),
            $this->unverifiedStaff()['email'],
            ...array_keys($this->applicants()),
            ...array_keys($this->activeStudents()),
            $this->unverifiedStudent()['email'],
        ];
    }

    /** @return array<string, mixed> */
    public function report(string $expectedCheckpoint = self::CheckpointAuto): array
    {
        $expectedCheckpoint = strtolower(trim($expectedCheckpoint));

        if (! in_array($expectedCheckpoint, [
            self::CheckpointAuto,
            self::CheckpointPristine,
            self::CheckpointAcceptedCandidate,
            self::CheckpointPublished,
        ], true)) {
            throw new InvalidArgumentException(
                'Checkpoint must be auto, pristine, accepted-candidate, or published.',
            );
        }

        $term = $this->presentationTerm();
        $personaEmails = $this->personaEmails();
        $staffReady = $this->staffAreReady();
        $applicantsReady = $this->applicantsAreReady($term);
        $studentsReady = $this->studentsAreReady();
        $deniedLoginReady = $this->deniedLoginIsReady();
        $studentProfiles = StudentProfile::query()->count();
        $currentStudents = StudentProfile::query()
            ->where(function ($query): void {
                $query
                    ->where('student_number', 'like', 'DBM-1A-%')
                    ->orWhere('student_number', 'like', 'DBM-2A-%')
                    ->orWhere('student_number', 'like', 'DIT-1A-%')
                    ->orWhere('student_number', 'like', 'DIT-2A-%')
                    ->orWhere('student_number', 'like', 'DTHM-1A-%')
                    ->orWhere('student_number', 'like', 'DTHM-2A-%');
            })
            ->count();
        $historicalCaseProfiles = StudentProfile::query()
            ->whereIn('student_number', ['DTHM-3A-001', 'DIT-3A-001'])
            ->where('lifecycle_status', StudentProfile::LifecycleInactive)
            ->count();
        $cohorts = collect(array_keys($this->canonicalDataset->cohorts()))
            ->filter(fn (string $cohortCode): bool => StudentProfile::query()
                ->where('student_number', 'like', $cohortCode.'-%')
                ->exists())
            ->count();
        $termOfferings = TermOffering::query()->whereBelongsTo($term)->count();
        $schedulingDemands = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->count();
        $faculty = User::role(User::StaffRoleFaculty)->count();
        $presentationFixtureReady = $studentProfiles === 49
            && $currentStudents === 47
            && $historicalCaseProfiles === 2
            && $cohorts === 6
            && $termOfferings === 54
            && $schedulingDemands === 54
            && $faculty === 9;
        $termRuns = ScheduleGenerationRun::query()
            ->whereBelongsTo($term)
            ->withCount(['candidateRows', 'sectionMeetings'])
            ->get();
        $termRunIds = $termRuns->modelKeys();
        $candidateRows = DB::table('candidate_schedule_rows')
            ->whereIn('schedule_run_id', $termRunIds)
            ->count();
        $sectionMeetings = SectionMeeting::query()
            ->whereIn('schedule_run_id', $termRunIds)
            ->count();
        $scheduledOfferings = TermOffering::query()
            ->whereBelongsTo($term)
            ->where('state', TermOffering::StateScheduled)
            ->count();
        $termSections = DB::table('sections')
            ->join('term_offerings', 'term_offerings.id', '=', 'sections.term_offering_id')
            ->where('term_offerings.term_id', $term->id);
        $sections = (clone $termSections)->count('sections.id');
        $openSections = (clone $termSections)
            ->where('sections.state', 'OPEN')
            ->count('sections.id');
        $jobsEmpty = DB::table('jobs')->doesntExist()
            && DB::table('failed_jobs')->doesntExist();
        $schedulingOutputsEmpty = $termRuns->isEmpty()
            && $candidateRows === 0
            && $sectionMeetings === 0
            && DB::table('schedule_revision_events')->doesntExist()
            && $jobsEmpty;
        $acceptedCandidateRun = $termRuns
            ->where('status', ScheduleGenerationRun::StatusUnderReview)
            ->first(fn (ScheduleGenerationRun $run): bool => (int) $run->candidate_rows_count === $schedulingDemands
                && $run->canBePublished());
        $acceptedCandidateReady = $acceptedCandidateRun instanceof ScheduleGenerationRun
            && $candidateRows === $schedulingDemands
            && $sectionMeetings === 0
            && $termRuns->where('status', ScheduleGenerationRun::StatusPublished)->isEmpty()
            && $jobsEmpty;
        $officialEnrollment = Enrollment::query()
            ->whereBelongsTo($term)
            ->where('status', 'officially_enrolled')
            ->whereHas('studentProfile.user', fn ($query) => $query
                ->where('email', 'student.dit-1a.005@example.test'))
            ->withCount('courseEnrollments')
            ->first();
        $activeBindings = $officialEnrollment instanceof Enrollment
            ? DB::table('student_schedule_bindings')
                ->join(
                    'course_enrollments',
                    'course_enrollments.id',
                    '=',
                    'student_schedule_bindings.course_enrollment_id',
                )
                ->where('course_enrollments.enrollment_id', $officialEnrollment->id)
                ->where('student_schedule_bindings.is_active', true)
                ->count()
            : 0;
        $publishedRun = $termRuns
            ->where('status', ScheduleGenerationRun::StatusPublished)
            ->first(fn (ScheduleGenerationRun $run): bool => (int) $run->candidate_rows_count === $schedulingDemands
                && (int) $run->section_meetings_count === $schedulingDemands);
        $publishedCheckpointReady = $publishedRun instanceof ScheduleGenerationRun
            && $candidateRows === $schedulingDemands
            && $sectionMeetings === $schedulingDemands
            && $scheduledOfferings === $termOfferings
            && $openSections === $sections
            && $officialEnrollment instanceof Enrollment
            && (int) $officialEnrollment->course_enrollments_count === 8
            && $activeBindings === 8
            && $jobsEmpty;
        $detectedCheckpoint = match (true) {
            $publishedCheckpointReady => self::CheckpointPublished,
            $acceptedCandidateReady => self::CheckpointAcceptedCandidate,
            $schedulingOutputsEmpty => self::CheckpointPristine,
            default => 'invalid',
        };
        $checkpointReady = $detectedCheckpoint !== 'invalid'
            && ($expectedCheckpoint === self::CheckpointAuto
                || $expectedCheckpoint === $detectedCheckpoint);
        $passes = count($personaEmails) === 28
            && count(array_unique($personaEmails)) === 28
            && $staffReady
            && $applicantsReady
            && $studentsReady
            && $deniedLoginReady
            && $presentationFixtureReady
            && $checkpointReady;

        return [
            'coverage_state' => $passes ? 'PASS' : 'FAIL',
            'personas' => count($personaEmails),
            'denied_login_personas' => 1,
            'student_profiles' => $studentProfiles,
            'current_students' => $currentStudents,
            'historical_case_profiles' => $historicalCaseProfiles,
            'cohorts' => $cohorts,
            'term_offerings' => $termOfferings,
            'scheduling_demands' => $schedulingDemands,
            'faculty' => $faculty,
            'staff_ready' => $staffReady,
            'applicants_ready' => $applicantsReady,
            'students_ready' => $studentsReady,
            'denied_login_ready' => $deniedLoginReady,
            'presentation_fixture_ready' => $presentationFixtureReady,
            'checkpoint_expected' => $expectedCheckpoint,
            'checkpoint_detected' => $detectedCheckpoint,
            'checkpoint_ready' => $checkpointReady,
            'schedule_runs' => $termRuns->count(),
            'candidate_rows' => $candidateRows,
            'section_meetings' => $sectionMeetings,
            'scheduled_offerings' => $scheduledOfferings,
            'open_sections' => $openSections,
            'representative_official_courses' => $officialEnrollment->course_enrollments_count ?? 0,
            'representative_active_bindings' => $activeBindings,
            'accepted_candidate_ready' => $acceptedCandidateReady,
            'published_checkpoint_ready' => $publishedCheckpointReady,
            'scheduling_outputs_empty' => $schedulingOutputsEmpty,
        ];
    }

    private function staffAreReady(): bool
    {
        foreach ($this->activeStaff() as $email => $role) {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User
                || $user->email_verified_at === null
                || ! $user->hasRole($role)
                || ! Hash::check('password', $user->password)
                || ! $user->canAuthenticate()) {
                return false;
            }
        }

        $boundary = $this->unverifiedStaff();
        $user = User::query()->where('email', $boundary['email'])->first();

        return $user instanceof User
            && $user->email_verified_at === null
            && $user->hasRole($boundary['role'])
            && Hash::check('password', $user->password)
            && $user->canAuthenticate();
    }

    private function applicantsAreReady(Term $term): bool
    {
        foreach ($this->applicants() as $email => $definition) {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User
                || $user->email_verified_at === null
                || ! $user->hasRole('applicant')
                || ! Hash::check('password', $user->password)
                || $user->status !== $definition['user_status']) {
                return false;
            }

            $intake = ApplicantIntake::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($term)
                ->first();

            if (! $intake instanceof ApplicantIntake
                || $intake->status !== $definition['intake_status']
                || $intake->admission_category !== $definition['admission_category']
                || $intake->credential_basis !== $definition['credential_basis']
                || $intake->modality_preference !== null) {
                return false;
            }
        }

        return true;
    }

    private function studentsAreReady(): bool
    {
        $priorTerm = Term::query()
            ->where('type', Term::TypeFirstSemester)
            ->where('label', 'First Semester')
            ->where('state', Term::StateClosed)
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->first();

        if (! $priorTerm instanceof Term) {
            return false;
        }

        foreach ($this->activeStudents() as $email => $standing) {
            $user = User::query()->where('email', $email)->first();
            $profile = $user?->studentProfile;

            if (! $user instanceof User
                || ! $profile instanceof StudentProfile
                || $user->email_verified_at === null
                || ! $user->hasRole('student')
                || ! Hash::check('password', $user->password)
                || ! $user->canAuthenticate()
                || $profile->academic_standing !== $standing
                || ! $this->studentSourceEvidenceIsReady($email, $profile, $priorTerm)) {
                return false;
            }
        }

        $boundary = $this->unverifiedStudent();
        $user = User::query()->where('email', $boundary['email'])->first();

        return $user instanceof User
            && $user->email_verified_at === null
            && $user->hasRole('student')
            && Hash::check('password', $user->password)
            && $user->canAuthenticate()
            && $user->studentProfile?->academic_standing === $boundary['standing'];
    }

    private function studentSourceEvidenceIsReady(
        string $email,
        StudentProfile $profile,
        Term $priorTerm,
    ): bool {
        if (in_array($email, [
            'student.demo@example.test',
            'student.dbm-2a.002@example.test',
            'student.dit-2a.002@example.test',
            'student.dthm-1a.002@example.test',
            'student.dthm-2a.001@example.test',
            'student.completion.demo@example.test',
            'student.graduation.demo@example.test',
        ], true) && ! $this->priorOutcomeExists($profile, $priorTerm, GradeRosterRow::CategoryPassing)) {
            return false;
        }

        if (in_array($email, [
            'student.dbm-2a.001@example.test',
            'student.dit-1a.001@example.test',
            'student.dit-2a.001@example.test',
            'student.dthm-1a.001@example.test',
        ], true) && ! $this->priorOutcomeExists($profile, $priorTerm, GradeRosterRow::CategoryFailed)) {
            return false;
        }

        if ($email === 'student.dit-1a.002@example.test'
            && ! $this->priorOutcomeExists($profile, $priorTerm, GradeRosterRow::CategoryIncomplete)) {
            return false;
        }

        if ($email === 'student.dit-2a.001@example.test'
            && ! Hold::query()
                ->whereBelongsTo($profile)
                ->whereBelongsTo($priorTerm)
                ->where('hold_type', Hold::TypePrerequisite)
                ->where('blocking_level', Hold::BlockingEnrollment)
                ->where('status', Hold::StatusActive)
                ->exists()) {
            return false;
        }

        if ($email === 'student.dthm-2a.002@example.test'
            && (! Enrollment::query()
                ->whereBelongsTo($profile)
                ->whereBelongsTo($priorTerm)
                ->exists()
                || GradeRosterRow::query()
                    ->whereNotNull('released_at')
                    ->whereHas(
                        'courseEnrollment.enrollment',
                        fn ($query) => $query
                            ->whereBelongsTo($profile)
                            ->whereBelongsTo($priorTerm),
                    )
                    ->exists())) {
            return false;
        }

        $graduationStatus = match ($email) {
            'student.completion.demo@example.test' => 'Ready for Registrar Review',
            'student.graduation.demo@example.test' => 'Complete',
            default => null,
        };

        return $graduationStatus === null
            || GraduationSnapshot::query()
                ->whereHas(
                    'member',
                    fn ($query) => $query
                        ->whereBelongsTo($profile)
                        ->where('is_active', true),
                )
                ->where('result_status', $graduationStatus)
                ->whereNotNull('made_visible_at')
                ->exists();
    }

    private function priorOutcomeExists(
        StudentProfile $profile,
        Term $priorTerm,
        string $category,
    ): bool {
        return GradeRosterRow::query()
            ->where('current_outcome_category', $category)
            ->whereNotNull('released_at')
            ->whereHas(
                'courseEnrollment.enrollment',
                fn ($query) => $query
                    ->whereBelongsTo($profile)
                    ->whereBelongsTo($priorTerm),
            )
            ->exists();
    }

    private function deniedLoginIsReady(): bool
    {
        $definition = $this->deniedLoginPersona();
        $user = User::query()->where('email', $definition['email'])->first();

        return $user instanceof User
            && $user->status === $definition['status']
            && $user->hasRole($definition['role'])
            && Hash::check('password', $user->password)
            && ! $user->canAuthenticate();
    }

    private function presentationTerm(): Term
    {
        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }
}
