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

/**
 * Defines and inspects the guarded TAL-96D5E1 first-time exploration accounts.
 *
 * This catalogue is test-only acceptance scaffolding. It does not authorize
 * production accounts, real identities, provider credentials, or solver work.
 */
final class TAL96D5E1ExplorationPersonaCatalog
{
    public function __construct(
        private readonly SchedulingAcceptanceScenarioCatalog $scenarios,
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
            'student.dbm-3a.001@example.test' => StudentProfile::StandingRegular,
            'student.dbm-2a.001@example.test' => StudentProfile::StandingIrregular,
            'student.dit-1a.001@example.test' => StudentProfile::StandingProbationary,
            'student.dit-1a.002@example.test' => StudentProfile::StandingDeficient,
            'student.dit-2a.001@example.test' => StudentProfile::StandingBlockedByPrerequisite,
            'student.dthm-1a.001@example.test' => StudentProfile::StandingMustRepeatYear,
            'student.dthm-1a.002@example.test' => StudentProfile::StandingCompletionCandidate,
            'student.dthm-2a.001@example.test' => StudentProfile::StandingGraduationCandidate,
            'student.dthm-2a.002@example.test' => StudentProfile::StandingNotYetEvaluated,
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

    /**
     * @return array{
     *     coverage_state:'PASS'|'FAIL',
     *     personas:int,
     *     denied_login_personas:int,
     *     students:int,
     *     cohorts:int,
     *     term_offerings:int,
     *     scheduling_demands:int,
     *     synthetic_scheduling_faculty:int,
     *     staff_ready:bool,
     *     applicants_ready:bool,
     *     students_ready:bool,
     *     denied_login_ready:bool,
     *     middle_fingerprint_ready:bool,
     *     scheduling_outputs_empty:bool
     * }
     */
    public function report(): array
    {
        $term = $this->middleTerm();
        $personaEmails = $this->personaEmails();
        $staffReady = $this->staffAreReady();
        $applicantsReady = $this->applicantsAreReady($term);
        $studentsReady = $this->studentsAreReady();
        $deniedLoginReady = $this->deniedLoginIsReady();
        $students = StudentProfile::query()->count();
        $cohorts = collect(array_keys($this->scenarios->cohorts(SchedulingAcceptanceScenarioCatalog::Middle)))
            ->filter(fn (string $cohortCode): bool => StudentProfile::query()
                ->where('student_number', 'like', $cohortCode.'-%')
                ->exists())
            ->count();
        $termOfferings = TermOffering::query()->whereBelongsTo($term)->count();
        $schedulingDemands = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->count();
        $syntheticSchedulingFaculty = User::role(User::StaffRoleFaculty)->count();
        $middleFingerprintReady = $students === 270
            && $cohorts === 9
            && $termOfferings === 77
            && $schedulingDemands === 77
            && $syntheticSchedulingFaculty === 14;
        $schedulingOutputsEmpty = ScheduleGenerationRun::query()->doesntExist()
            && SectionMeeting::query()->doesntExist()
            && DB::table('candidate_schedule_rows')->doesntExist()
            && DB::table('schedule_revision_events')->doesntExist()
            && DB::table('jobs')->doesntExist()
            && DB::table('failed_jobs')->doesntExist();
        $passes = count($personaEmails) === 26
            && count(array_unique($personaEmails)) === 26
            && $staffReady
            && $applicantsReady
            && $studentsReady
            && $deniedLoginReady
            && $middleFingerprintReady
            && $schedulingOutputsEmpty;

        return [
            'coverage_state' => $passes ? 'PASS' : 'FAIL',
            'personas' => count($personaEmails),
            'denied_login_personas' => 1,
            'students' => $students,
            'cohorts' => $cohorts,
            'term_offerings' => $termOfferings,
            'scheduling_demands' => $schedulingDemands,
            'synthetic_scheduling_faculty' => $syntheticSchedulingFaculty,
            'staff_ready' => $staffReady,
            'applicants_ready' => $applicantsReady,
            'students_ready' => $studentsReady,
            'denied_login_ready' => $deniedLoginReady,
            'middle_fingerprint_ready' => $middleFingerprintReady,
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
            'student.dbm-3a.001@example.test',
            'student.dthm-1a.002@example.test',
            'student.dthm-2a.001@example.test',
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
            'student.dthm-1a.002@example.test' => 'Ready for Registrar Review',
            'student.dthm-2a.001@example.test' => 'Complete',
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

    private function middleTerm(): Term
    {
        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }
}
