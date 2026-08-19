<?php

namespace Database\Seeders;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Actions\Registrar\BuildTermOfferings;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Actions\SystemAdministration\CanonicalTalaSchedulingDataset;
use App\Actions\SystemAdministration\ClientCurriculumFixtureCatalog;
use App\Actions\SystemAdministration\SchedulingFacultyCapacityAssessment;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementPolicy;
use App\Models\AdmissionRequirementSet;
use App\Models\Assessment;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Activitylog\ActivityLogStatus;

/**
 * Constructs the deterministic acceptance records used by TAL-96B1 and extended by TAL-96D2C.
 *
 * It exercises the real offering, cohort, and demand-generation services without
 * invoking the solver. Guarded commands and tests must call it only on test_tala_db.
 */
final class ClientAlignedAcceptanceBaselineSeeder extends Seeder
{
    public const StateEmpty = 'empty';

    public const StateComplete = 'complete';

    public const StateConflict = 'conflict';

    public function __construct(
        private readonly DatabaseSeeder $databaseSeeder,
        private readonly AdmissionRequirementPolicySeeder $admissionRequirementPolicySeeder,
        private readonly AdmissionsAcceptanceSeeder $admissionsAcceptanceSeeder,
        private readonly BuildTermOfferings $offeringBuilder,
        private readonly SectionDeliveryGroupService $deliveryGroupService,
        private readonly GenerateSchedulingDemand $demandGenerator,
        private readonly TermSchedulingReadinessService $readinessService,
        private readonly ClientCurriculumFixtureCatalog $curriculumFixtureCatalog,
        private readonly CanonicalTalaSchedulingDataset $canonicalDataset,
        private readonly SchedulingFacultyCapacityAssessment $facultyCapacityAssessment,
    ) {}

    public function state(): string
    {
        if ($this->isComplete()) {
            return self::StateComplete;
        }

        return $this->hasOperationalData() ? self::StateConflict : self::StateEmpty;
    }

    public function run(): void
    {
        if ($this->state() !== self::StateEmpty) {
            throw new RuntimeException('The client acceptance baseline can only be created from an empty operational state.');
        }

        $logStatus = app(ActivityLogStatus::class);
        $wasDisabled = $logStatus->disabled();
        $logStatus->disable();

        try {
            $this->databaseSeeder->run();
            $this->admissionRequirementPolicySeeder->seedBaseline();

            [$term, $priorTerm, $registrar, $faculty] = $this->createTermAndAccounts();
            $programs = $this->createPrograms();
            $this->admissionsAcceptanceSeeder->run();
            $specifications = $this->createCourseCatalog($term, $priorTerm);
            $curricula = $this->createCurricula($term, $registrar, $programs, $specifications);

            $this->createStudents($programs, $curricula);
            $this->createRooms();
            $this->createFacultyReadiness($term, $registrar, $faculty, $specifications);
            $this->createSchedulingWindow($term);
            $this->createAdmissionsWindow($term);
            $this->createOfferings($term, $registrar, $programs, $curricula);
            $this->markDeliveryGroupsReady($registrar);
            $this->createDownpaymentRules($term, $programs);

            $demandSummary = $this->demandGenerator->forTerm($registrar, $term);
            $readiness = $this->readinessService->evaluateTerm($term);

            $expectedDemands = $this->manifest()['counts']['scheduling_demands'];

            if ($demandSummary['total'] !== $expectedDemands
                || $demandSummary['ready'] !== $expectedDemands
                || $demandSummary['action_required'] !== 0
                || ! $readiness['is_ready']) {
                throw new RuntimeException('The generated client acceptance baseline did not pass scheduling readiness.');
            }

            if (! $this->isComplete()) {
                throw new RuntimeException('The generated client acceptance baseline did not satisfy its exact completeness contract.');
            }
        } finally {
            if (! $wasDisabled) {
                $logStatus->enable();
            }
        }
    }

    public function readinessPasses(): bool
    {
        $academicYear = $this->exactAcademicYear();
        $term = $academicYear instanceof AcademicYear
            ? $this->exactTerm($academicYear)
            : null;

        return $term instanceof Term && $this->readinessPassesForTerm($term);
    }

    /**
     * @return array{
     *     dataset:string,
     *     basis:string,
     *     limitation:string,
     *     counts:array{students:int,cohorts:int,faculty:int,offerings:int,sections:int,scheduling_demands:int,admission_requirement_policies:int},
     *     faculty_evidence:array{
     *         client_reported_faculty:int|null,
     *         synthetic_scheduling_faculty:int,
     *         total_teaching_units:float,
     *         arithmetic_faculty_lower_bound:int,
     *         max_units_per_faculty:float,
     *         maximum_constructed_load:float,
     *         availability_assumption:'FULL_OPERATING_GRID',
     *         bounded_readiness:'PASS',
     *         unassignable_workloads:list<string>,
     *         interpretation:string
     *     },
     *     operating_grid:array{days:list<int>,starts_at:string,ends_at:string,slot_minutes:int},
     *     solver_feasibility:'NOT_EVALUATED',
     *     solver_optimality:'NOT_EVALUATED'
     * }
     */
    public function manifest(): array
    {
        $manifest = $this->canonicalDataset->manifest();
        $assessment = $this->facultyCapacityAssessment();
        $evidence = $manifest['faculty_evidence'];

        if (
            $assessment['readiness'] !== $evidence['bounded_readiness']
            || $assessment['total_teaching_units'] !== $evidence['total_teaching_units']
            || $assessment['arithmetic_faculty_lower_bound'] !== $evidence['arithmetic_faculty_lower_bound']
            || $assessment['maximum_constructed_load'] !== $evidence['maximum_constructed_load']
            || $assessment['unassigned_workloads'] !== $evidence['unassignable_workloads']
        ) {
            throw new RuntimeException(
                'The canonical Faculty evidence no longer matches the constructed scheduling workload.',
            );
        }

        $manifest['counts']['admission_requirement_policies'] = $this->admissionRequirementPolicySeeder->expectedPolicyCount();

        return $manifest;
    }

    /**
     * @return array{
     *     database:string,
     *     students:int,
     *     cohorts:int,
     *     scheduling_demands:int,
     *     ready_scheduling_demands:int,
     *     admission_requirement_policies:int,
     *     standings:array<string,int>,
     *     scenario_anchors:array{matched:int,expected:int},
     *     downstream_state:'EMPTY'|'PRESENT',
     *     downstream:array<string,int>
     * }
     */
    public function inspectionReport(): array
    {
        $standings = collect(AcademicProgressionService::standingValues())
            ->mapWithKeys(fn (string $standing): array => [
                $standing => StudentProfile::query()->where('academic_standing', $standing)->count(),
            ])
            ->all();
        $cohorts = collect(array_keys($this->cohorts()))
            ->filter(fn (string $cohortCode): bool => StudentProfile::query()
                ->where('student_number', 'like', $cohortCode.'-%')
                ->exists())
            ->count();
        $matchedAnchors = collect($this->scenarioAnchorDefinitions())
            ->filter(fn (array $definition, string $studentNumber): bool => StudentProfile::query()
                ->where('student_number', $studentNumber)
                ->where('academic_standing', $definition['academic_standing'])
                ->exists())
            ->count();
        $downstream = [
            'schedule_runs' => ScheduleGenerationRun::query()->count(),
            'section_meetings' => SectionMeeting::query()->count(),
            'enrollments' => Enrollment::query()->count(),
            'assessments' => Assessment::query()->count(),
            'ledger_entries' => LedgerEntry::query()->count(),
            'payments' => Payment::query()->count(),
            'payment_attempts' => PaymentAttempt::query()->count(),
            'webhook_calls' => DB::table('webhook_calls')->count(),
        ];

        return [
            'database' => (string) DB::connection()->getDatabaseName(),
            'students' => StudentProfile::query()->count(),
            'cohorts' => $cohorts,
            'scheduling_demands' => SchedulingDemand::query()->count(),
            'ready_scheduling_demands' => SchedulingDemand::query()
                ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
                ->count(),
            'admission_requirement_policies' => AdmissionRequirementPolicy::query()->count(),
            'standings' => $standings,
            'scenario_anchors' => [
                'matched' => $matchedAnchors,
                'expected' => count($this->scenarioAnchorDefinitions()),
            ],
            'downstream_state' => collect($downstream)->sum() === 0 ? 'EMPTY' : 'PRESENT',
            'downstream' => $downstream,
        ];
    }

    /** @return array{Term, Term, User, list<User>} */
    private function createTermAndAccounts(): array
    {
        $academicYear = AcademicYear::query()->create([
            'label' => 'AY 2025-2026',
            'starts_on' => '2025-06-01',
            'ends_on' => '2026-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $priorTerm = Term::query()->create([
            'academic_year_id' => $academicYear->id,
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2025-06-02',
            'ends_on' => '2025-10-31',
            'state' => Term::StateClosed,
            'scheduling_slot_minutes' => 30,
            'scheduling_days' => [1, 2, 3, 4, 5, 6],
            'scheduling_day_starts_at' => '07:00:00',
            'scheduling_day_ends_at' => '21:00:00',
            'default_max_units' => 21,
        ]);
        $term = Term::query()->create([
            'academic_year_id' => $academicYear->id,
            'type' => Term::TypeSecondSemester,
            'label' => 'Second Semester',
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-05-30',
            'state' => Term::StateActive,
            'scheduling_slot_minutes' => 30,
            'scheduling_days' => [1, 2, 3, 4, 5, 6],
            'scheduling_day_starts_at' => '07:00:00',
            'scheduling_day_ends_at' => '21:00:00',
            'default_max_units' => 21,
        ]);

        [$applicantFirstName, $applicantLastName] = $this->staffIdentity('applicant.demo@example.test');
        $this->createUser($applicantFirstName, $applicantLastName, 'applicant.demo@example.test', 'applicant');
        [$registrarFirstName, $registrarLastName] = $this->staffIdentity('registrar.demo@example.test');
        $registrar = $this->createUser($registrarFirstName, $registrarLastName, 'registrar.demo@example.test', User::StaffRoleRegistrar);
        [$accountingFirstName, $accountingLastName] = $this->staffIdentity('accounting.demo@example.test');
        $this->createUser($accountingFirstName, $accountingLastName, 'accounting.demo@example.test', User::StaffRoleAccounting);
        [$academicHeadFirstName, $academicHeadLastName] = $this->staffIdentity('academic-head.demo@example.test');
        $this->createUser($academicHeadFirstName, $academicHeadLastName, 'academic-head.demo@example.test', User::StaffRoleAcademicHead);
        [$systemAdminFirstName, $systemAdminLastName] = $this->staffIdentity('system-admin.demo@example.test');
        $this->createUser($systemAdminFirstName, $systemAdminLastName, 'system-admin.demo@example.test', User::StaffRoleSystemSuperAdmin);

        $faculty = [];

        for ($number = 1; $number <= $this->manifest()['counts']['faculty']; $number++) {
            $email = $number === 1
                ? 'faculty.demo@example.test'
                : sprintf('faculty%02d.demo@example.test', $number);
            [$firstName, $lastName] = $this->facultyIdentity($number);
            $faculty[] = $this->createUser($firstName, $lastName, $email, User::StaffRoleFaculty);
        }

        return [$term, $priorTerm, $registrar, $faculty];
    }

    /** @return array<string, Program> */
    private function createPrograms(): array
    {
        $programs = [];

        foreach ($this->programDefinitions() as $code => $name) {
            $programs[$code] = Program::query()->create([
                'code' => $code,
                'name' => $name,
                'duration_years' => 3,
                'is_active' => true,
            ]);
        }

        return $programs;
    }

    /** @return array<string, CourseSpecification> */
    private function createCourseCatalog(Term $term, Term $priorTerm): array
    {
        $specifications = [];
        $programSpecificCourseCodes = collect($this->thirdYearCourseDefinitions())
            ->flatMap(fn (array $definitions): array => array_keys($definitions))
            ->unique()
            ->all();

        foreach ($this->courseCatalog() as $code => $definition) {
            $course = Course::query()->create([
                'code' => $code,
                'state' => Course::StateActive,
            ]);

            if (in_array($code, $programSpecificCourseCodes, true)) {
                continue;
            }

            $specifications[$code] = $this->createCourseSpecification(
                $course,
                $term,
                'AY2025-2026',
                $definition,
            );
        }

        $dbmNstpDefinition = $this->courseCatalog()['NSTP02'];
        $dbmNstpDefinition['units'] = 2;
        $specifications[$this->specificationKey('DBM', 'NSTP02')] = $this->createCourseSpecification(
            Course::query()->where('code', 'NSTP02')->sole(),
            $term,
            $this->specificationRevisionCode('DBM', 'NSTP02'),
            $dbmNstpDefinition,
        );

        foreach ($this->thirdYearCourseDefinitions() as $programCode => $definitions) {
            foreach ($definitions as $courseCode => $definition) {
                $specification = $this->createCourseSpecification(
                    Course::query()->where('code', $courseCode)->sole(),
                    $term,
                    $this->specificationRevisionCode($programCode, $courseCode),
                    $definition,
                );
                $specifications[$this->specificationKey($programCode, $courseCode)] = $specification;
                $specifications[$courseCode] ??= $specification;
            }
        }

        foreach ($this->curriculumFixtureCatalog->firstSemesterRows() as $programCode => $yearRows) {
            foreach ($yearRows as $yearLevel => $rows) {
                foreach ($rows as $row) {
                    $course = Course::query()->firstOrCreate(
                        ['code' => $row['code']],
                        ['state' => Course::StateActive],
                    );
                    $definition = $this->firstSemesterCourseDefinition($row);
                    $specifications[$this->firstSemesterSpecificationKey(
                        $programCode,
                        $yearLevel,
                        $row['code'],
                    )] = $this->createCourseSpecification(
                        $course,
                        $priorTerm,
                        $this->firstSemesterRevisionCode($programCode, $yearLevel),
                        $definition,
                    );
                }
            }
        }

        return $specifications;
    }

    /**
     * @param array{
     *     title:string,
     *     units:float,
     *     modality:string,
     *     room_type:string,
     *     source_code?:string,
     *     prerequisite_source?:string
     * } $definition
     */
    private function createCourseSpecification(
        Course $course,
        Term $term,
        string $revisionCode,
        array $definition,
    ): CourseSpecification {
        $schedulingTreatment = $this->schedulingTreatment($definition);
        $specification = CourseSpecification::query()->create([
            'course_id' => $course->id,
            'revision_code' => $revisionCode,
            'title' => $definition['title'],
            'description' => $this->courseSpecificationDescription($definition),
            'credit_units' => $definition['units'],
            'grading_profile_key' => CourseSpecification::GradingProfileCollegeStandard,
            'grading_profile_version' => 1,
            'scheduling_treatment' => $schedulingTreatment,
            'allowed_modalities' => [TermOffering::ModalityOnline, TermOffering::ModalityFaceToFace],
            'same_faculty_default' => true,
            'effective_term_id' => $term->id,
            'state' => CourseSpecification::StateActive,
        ]);

        if ($schedulingTreatment === CourseSpecification::SchedulingExternallyArranged) {
            return $specification;
        }

        CourseComponent::query()->create([
            'course_specification_id' => $specification->id,
            'component_type' => $definition['room_type'] === Room::TypeLectureRoom
                ? CourseComponent::TypeLecture
                : CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => $definition['units'],
            'meeting_pattern' => $this->meetingPattern($course, $definition),
            'room_type_default' => $definition['room_type'],
            'required_room_feature_keys' => [],
            'modality_restriction' => null,
            'requires_consecutive_block' => $definition['units'] >= 4,
            'same_faculty' => true,
            'sequence' => 1,
        ]);

        return $specification;
    }

    /** @param array{title:string,units:float,modality:string,room_type:string} $definition */
    private function schedulingTreatment(array $definition): string
    {
        return str($definition['title'])->contains(['Internship', 'Work-Related Training', 'Practicum'])
            ? CourseSpecification::SchedulingExternallyArranged
            : CourseSpecification::SchedulingRecurring;
    }

    /** @param array{title:string,units:float,modality:string,room_type:string} $definition */
    private function meetingPattern(Course $course, array $definition): string
    {
        $weeklyMinutes = (int) round($definition['units'] * 60);

        if ($weeklyMinutes === 180) {
            if ($definition['room_type'] !== Room::TypeLectureRoom) {
                return '1x180';
            }

            return in_array($course->code, ['GE04', 'GE05', 'GE06'], true) ? '3x60' : '2x90';
        }

        return match ($weeklyMinutes) {
            60 => '1x60',
            90 => '1x90',
            120 => '2x60',
            240 => '2x120',
            default => '1x'.$weeklyMinutes,
        };
    }

    /**
     * @param array{
     *     source_code?:string,
     *     prerequisite_source?:string
     * } $definition
     */
    private function courseSpecificationDescription(array $definition): string
    {
        if (! isset($definition['source_code'])) {
            return 'Course specification encoded from the approved curriculum evidence.';
        }

        return sprintf(
            'Course specification encoded from client source code %s; prerequisite source: %s.',
            $definition['source_code'],
            $definition['prerequisite_source'] ?? 'None',
        );
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, CourseSpecification>  $specifications
     * @return array<string, CurriculumVersion>
     */
    private function createCurricula(Term $term, User $registrar, array $programs, array $specifications): array
    {
        $curricula = [];

        foreach ($programs as $programCode => $program) {
            $curriculum = CurriculumVersion::query()->create([
                'program_id' => $program->id,
                'version_code' => $programCode.'-AY2025-2026',
                'name' => $programCode.' AY 2025-2026 Curriculum',
                'effective_entry_term_id' => $term->id,
                'state' => CurriculumVersion::StateActive,
                'approval_reference' => 'Registrar-approved client curriculum encoding',
                'approved_by' => $registrar->id,
                'approved_at' => '2025-12-01 08:00:00',
            ]);
            $sequence = 1;

            foreach ($this->curriculumPlacements($programCode) as $placement) {
                CurriculumEntry::query()->create([
                    'curriculum_version_id' => $curriculum->id,
                    'course_specification_id' => $specifications[$placement['specification_key']]->id,
                    'year_level' => $placement['year_level'],
                    'term_label' => $placement['term_label'],
                    'term_type' => $placement['term_type'],
                    'sequence' => $sequence++,
                    'requirement_group' => CurriculumEntry::RequirementGroupRequired,
                ]);
            }

            $curricula[$programCode] = $curriculum;
        }

        return $curricula;
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, CurriculumVersion>  $curricula
     */
    private function createStudents(array $programs, array $curricula): void
    {
        $globalNumber = 1;

        foreach ($this->cohorts() as $cohortCode => $cohort) {
            for ($number = 1; $number <= $cohort['students']; $number++) {
                $email = $globalNumber === 1
                    ? 'student.demo@example.test'
                    : sprintf('student.%s.%03d@example.test', strtolower($cohortCode), $number);
                [$firstName, $middleName, $lastName] = $this->studentIdentity($globalNumber);
                $user = $this->createUser($firstName, $lastName, $email, 'student');

                $studentNumber = sprintf('%s-%03d', $cohortCode, $number);

                StudentProfile::query()->create([
                    'user_id' => $user->id,
                    'student_number' => $studentNumber,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'birth_date' => sprintf('2005-%02d-%02d', (($globalNumber - 1) % 12) + 1, (($globalNumber - 1) % 27) + 1),
                    'program_id' => $programs[$cohort['program']]->id,
                    'curriculum_version_id' => $curricula[$cohort['program']]->id,
                    'lifecycle_status' => StudentProfile::LifecycleActive,
                    'academic_standing' => $this->expectedAcademicStanding($studentNumber),
                    'email' => $email,
                    'phone' => null,
                    'address' => 'San Pedro, Laguna',
                    'emergency_contact_name' => 'Designated family contact',
                    'emergency_contact_phone' => null,
                ]);

                $globalNumber++;
            }
        }
    }

    private function createRooms(): void
    {
        foreach ($this->roomDefinitions() as [$code, $name, $type]) {
            Room::query()->create([
                'code' => $code,
                'name' => $name,
                'building' => 'Main Academic Building',
                'room_type' => $type,
                'capacity' => 40,
                'is_active' => true,
                'notes' => 'Teaching space available for the current term.',
            ]);
        }
    }

    /**
     * @param  list<User>  $faculty
     * @param  array<string, CourseSpecification>  $specifications
     */
    private function createFacultyReadiness(
        Term $term,
        User $registrar,
        array $faculty,
        array $specifications,
    ): void {
        foreach ($faculty as $facultyUser) {
            FacultyTermLoadOverride::query()->create([
                'faculty_user_id' => $facultyUser->id,
                'term_id' => $term->id,
                'default_max_units_snapshot' => 21,
                'approved_overload_units' => 0,
                'authority' => 'Academic Head-approved current-term faculty load',
                'reason' => 'Current-term teaching-load ceiling.',
                'is_active' => true,
            ]);
        }

        foreach ($this->facultyQualificationCourseMap() as $facultyIndex => $courseCodes) {
            foreach ($courseCodes as $courseCode) {
                $this->createFacultyQualification(
                    $faculty[$facultyIndex],
                    $specifications[$courseCode],
                    $registrar,
                );
            }
        }
    }

    private function createFacultyQualification(
        User $faculty,
        CourseSpecification $specification,
        User $registrar,
    ): void {
        FacultyQualification::query()->create([
            'faculty_user_id' => $faculty->id,
            'course_id' => $specification->course_id,
            'is_active' => true,
            'recorded_by' => $registrar->id,
            'recorded_at' => '2025-12-01 08:00:00',
            'notes' => 'Verified qualification for current-term scheduling.',
        ]);
    }

    private function createSchedulingWindow(Term $term): void
    {
        CalendarEvent::query()->create([
            'term_id' => $term->id,
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessScheduling,
            'start_at' => '2026-01-05 07:00:00',
            'end_at' => '2026-05-30 21:00:00',
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Registrar-approved current-term scheduling window',
        ]);
    }

    /**
     * The acceptance fixture keeps applicant intake open during the bounded
     * TAL-96D5B manual-acceptance period. Production institutions configure
     * their own Admissions window through the academic calendar.
     */
    private function createAdmissionsWindow(Term $term): void
    {
        CalendarEvent::query()->create([
            'term_id' => $term->id,
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessAdmissions,
            'start_at' => '2026-07-01 00:00:00',
            'end_at' => '2026-08-31 23:59:59',
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Registrar-approved admissions period',
        ]);
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, CurriculumVersion>  $curricula
     */
    private function createOfferings(
        Term $term,
        User $registrar,
        array $programs,
        array $curricula,
    ): void {
        foreach ($this->academicScopes() as $scope) {
            $curriculum = $curricula[$scope['program']];
            $entries = $this->offeringBuilder
                ->preview($term, $programs[$scope['program']], $curriculum, $scope['year'])
                ->keyBy(fn (CurriculumEntry $entry): string => (string) $entry->courseSpecification?->course?->code);
            $rows = [];

            foreach ($scope['courses'] as $courseCode) {
                $entry = $entries->get($courseCode);

                if (! $entry instanceof CurriculumEntry) {
                    throw new RuntimeException(
                        "Missing eligible curriculum entry for {$scope['program']} {$scope['year']} {$courseCode}.",
                    );
                }

                $modality = $this->courseCatalog()[$courseCode]['modality'];
                $sections = collect($scope['cohorts'])
                    ->map(fn (array $cohort, string $cohortCode): array => [
                        'code' => $cohortCode.'-'.$courseCode,
                        'capacity' => 30,
                        'delivery_groups' => [[
                            'name' => $cohortCode,
                            'expected_count' => $cohort['students'],
                            'modality' => $modality,
                        ]],
                    ])
                    ->values()
                    ->all();
                $rows[] = [
                    'curriculum_entry_id' => $entry->id,
                    'expected_count' => collect($scope['cohorts'])->sum('students'),
                    'modality' => $modality,
                    'sections' => $sections,
                ];
            }

            $summary = $this->offeringBuilder->regular(
                $registrar,
                $term,
                $programs[$scope['program']],
                $curriculum,
                $scope['year'],
                $rows,
            );

            if ($summary['created'] !== count($scope['courses']) || $summary['blocked'] !== 0) {
                throw new RuntimeException(
                    "The offering builder did not create the complete {$scope['program']} {$scope['year']} scenario.",
                );
            }
        }
    }

    private function markDeliveryGroupsReady(User $registrar): void
    {
        SectionDeliveryGroup::query()
            ->with('section')
            ->orderBy('id')
            ->get()
            ->each(function (SectionDeliveryGroup $group) use ($registrar): void {
                $section = $group->section;

                if (! $section instanceof Section) {
                    throw new RuntimeException('A baseline delivery group is missing its owning section.');
                }

                $this->deliveryGroupService->save($section, [
                    'name' => $group->name,
                    'expected_count' => $group->expected_count,
                    'modality' => $group->modality,
                    'delivery_override' => null,
                    'state' => SectionDeliveryGroup::StateReady,
                ], $group, $registrar);
            });
    }

    /** @param array<string, Program> $programs */
    private function createDownpaymentRules(Term $term, array $programs): void
    {
        foreach ($programs as $programCode => $program) {
            FeeRule::query()->create([
                'code' => 'DOWNPAYMENT-'.$programCode.'-2025-2',
                'name' => $programCode.' Second Semester Downpayment',
                'ledger_category' => FeeRule::LedgerCategoryDownpayment,
                'display_category' => FeeRule::DisplayCategoryDownpayment,
                'program_id' => $program->id,
                'term_id' => $term->id,
                'calculation_type' => FeeRule::CalculationFixed,
                'amount' => 2000,
                'rate' => null,
                'effective_from' => '2026-01-05',
                'effective_until' => '2026-05-30',
                'is_active' => true,
                'authority' => 'Client-reported PHP 2,000 required downpayment.',
            ]);
        }
    }

    private function createUser(
        string $firstName,
        string $lastName,
        string $email,
        string $role,
        string $status = User::StatusActive,
    ): User {
        $user = User::query()->create([
            'first_name' => $firstName,
            'middle_name' => null,
            'last_name' => $lastName,
            'username' => str($email)->before('@')->replace('.', '-')->toString(),
            'email' => $email,
            'password' => 'password',
            'status' => $status,
        ]);
        $user->forceFill(['email_verified_at' => '2025-12-01 08:00:00'])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * @return array{string, string}
     */
    private function staffIdentity(string $email): array
    {
        return match ($email) {
            'applicant.demo@example.test' => ['Andrea', 'Marquez'],
            'registrar.demo@example.test' => ['Maribel', 'Dizon'],
            'accounting.demo@example.test' => ['Renato', 'Salcedo'],
            'academic-head.demo@example.test' => ['Lourdes', 'Alvarado'],
            'system-admin.demo@example.test' => ['Miguel', 'Serrano'],
            default => ['Tala', 'Operator'],
        };
    }

    /**
     * @return array{string, string}
     */
    private function facultyIdentity(int $number): array
    {
        return match ($number) {
            1 => ['Teresa', 'Villanueva'],
            2 => ['Noel', 'Bautista'],
            3 => ['Caroline', 'Mendoza'],
            4 => ['Dennis', 'Garcia'],
            5 => ['Ramon', 'Flores'],
            6 => ['Irene', 'Aquino'],
            7 => ['Victor', 'Reyes'],
            8 => ['Jocelyn', 'Castillo'],
            9 => ['Edgar', 'Morales'],
            default => ['Faculty', sprintf('%02d', $number)],
        };
    }

    /**
     * @return array{string, string|null, string}
     */
    private function studentIdentity(int $number): array
    {
        $identities = [
            ['Alyssa', 'Mae', 'Cruz'],
            ['John Paolo', null, 'Garcia'],
            ['Mikaela', 'Anne', 'Reyes'],
            ['Joshua', 'Miguel', 'Santos'],
            ['Angela', 'Marie', 'Flores'],
            ['Nathaniel', null, 'Ramos'],
            ['Patricia', 'Mae', 'Mendoza'],
            ['Mark Anthony', null, 'Bautista'],
            ['Camille', 'Joy', 'Navarro'],
            ['Christian', 'Luis', 'Aquino'],
            ['Bea', 'Nicole', 'Castillo'],
            ['Jericho', 'Paul', 'Villanueva'],
            ['Sofia', 'Isabel', 'Dela Cruz'],
            ['Rafael', 'Andre', 'Torres'],
            ['Trisha', 'Mae', 'Morales'],
            ['Vince', 'Carlo', 'Mercado'],
            ['Janine', 'Grace', 'Herrera'],
            ['Gabriel', 'Jose', 'Salazar'],
            ['Kyla', 'Marie', 'Domingo'],
            ['Adrian', 'Matthew', 'Fernandez'],
            ['Clarisse', 'Anne', 'Rivera'],
            ['Daniel', 'Paolo', 'Santiago'],
            ['Hannah', 'Louise', 'Manalo'],
            ['Jomar', 'Vincent', 'Soriano'],
            ['Princess', 'Mae', 'Valdez'],
            ['Luis', 'Miguel', 'Padilla'],
            ['Nicole', 'Andrea', 'Castro'],
            ['Sean', 'Patrick', 'Lim'],
            ['Rhea', 'Mae', 'Macapagal'],
            ['Francis', 'Xavier', 'Natividad'],
            ['Ella', 'Christine', 'Evangelista'],
            ['Marco', 'Antonio', 'De Guzman'],
            ['Julia', 'Francesca', 'Cabrera'],
            ['Kenneth', 'John', 'Pascual'],
            ['Bianca', 'Louise', 'Alonzo'],
            ['Aaron', 'James', 'Sarmiento'],
            ['Mary', 'Grace', 'Abad'],
            ['Paolo', 'Miguel', 'Angeles'],
            ['Rica', 'Mae', 'Agustin'],
            ['Carl', 'Vincent', 'Rosales'],
            ['Denise', 'Marie', 'Yambao'],
            ['Ethan', 'Gabriel', 'Lacsamana'],
            ['Kristine', 'Joy', 'Arellano'],
            ['Angelo', 'Rafael', 'Beltran'],
            ['Mae Ann', null, 'Robles'],
            ['Jayson', 'Carlo', 'Ocampo'],
            ['Lara', 'Nicole', 'Gonzales'],
        ];

        return $identities[$number - 1] ?? ['Tala', null, sprintf('Learner %03d', $number)];
    }

    private function isComplete(): bool
    {
        $manifest = $this->manifest();
        $counts = $manifest['counts'];
        $qualificationCount = array_sum(array_map(
            'count',
            $this->facultyQualificationCourseMap(),
        ));
        $firstSemesterRows = collect($this->curriculumFixtureCatalog->firstSemesterRows())
            ->flatMap(fn (array $yearRows): array => array_values($yearRows))
            ->flatten(1);
        $firstSemesterRowCount = $firstSemesterRows->count();
        $currentCourseCount = count($this->courseCatalog());
        $courseCount = collect(array_keys($this->courseCatalog()))
            ->merge($firstSemesterRows->pluck('code'))
            ->unique()
            ->count();
        $programSpecificSpecificationCount = collect($this->thirdYearCourseDefinitions())
            ->sum(fn (array $definitions): int => count($definitions));
        $programSpecificCourseCount = collect($this->thirdYearCourseDefinitions())
            ->flatMap(fn (array $definitions): array => array_keys($definitions))
            ->unique()
            ->count();
        $specificationCount = $currentCourseCount
            + 1
            + $programSpecificSpecificationCount
            - $programSpecificCourseCount
            + $firstSemesterRowCount;
        $curriculumEntryCount = collect(array_keys($this->programDefinitions()))
            ->sum(fn (string $programCode): int => count($this->curriculumPlacements($programCode)));
        $academicYear = $this->exactAcademicYear();

        if (! $academicYear instanceof AcademicYear) {
            return false;
        }

        $term = $this->exactTerm($academicYear);
        $priorTerm = $this->exactPriorTerm($academicYear);

        if (! $term instanceof Term || ! $priorTerm instanceof Term) {
            return false;
        }

        return $this->programsAreComplete()
            && $this->admissionsAcceptanceBaselineIsComplete($term)
            && $this->admissionRequirementPolicySeeder->baselineIsComplete()
            && User::query()->count() === $counts['students'] + $counts['faculty'] + 5
            && User::query()->whereNull('email_verified_at')->doesntExist()
            && User::query()->where('email', 'not like', '%@example.test')->doesntExist()
            && StudentProfile::query()->count() === $counts['students']
            && Course::query()->count() === $courseCount
            && Course::query()->where('state', Course::StateActive)->count() === $courseCount
            && CourseSpecification::query()->count() === $specificationCount
            && CourseSpecification::query()->where('state', CourseSpecification::StateActive)->count() === $specificationCount
            && CourseComponent::query()->count() === CourseSpecification::query()
                ->where('scheduling_treatment', CourseSpecification::SchedulingRecurring)
                ->count()
            && CurriculumVersion::query()->count() === 3
            && CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)->count() === 3
            && CurriculumEntry::query()->count() === $curriculumEntryCount
            && Room::query()->count() === 10
            && Room::query()->where('is_active', true)->count() === 10
            && FacultyQualification::query()->count() === $qualificationCount
            && FacultyQualification::query()->where('is_active', true)->count() === $qualificationCount
            && FacultyTermLoadOverride::query()->count() === $counts['faculty']
            && FacultyTermLoadOverride::query()->where('is_active', true)->count() === $counts['faculty']
            && $this->calendarWindowsAreComplete($term)
            && TermOffering::query()->count() === $counts['offerings']
            && Section::query()->count() === $counts['sections']
            && SectionDeliveryGroup::query()->where('state', SectionDeliveryGroup::StateReady)->count() === $counts['sections']
            && SchedulingDemand::query()->count() === $counts['scheduling_demands']
            && SchedulingDemand::query()->where('validation_state', SchedulingDemand::ValidationReadyForReview)->count() === $counts['scheduling_demands']
            && $this->downpaymentRulesAreComplete($term)
            && $this->courseCatalogIsComplete($term, $priorTerm)
            && $this->curriculaAndEntriesAreComplete($term)
            && $this->offeringStructureIsComplete($term)
            && $this->studentCohortsAreComplete()
            && $this->roomsAreComplete()
            && $this->representativeAccountsAreComplete()
            && CourseSpecification::query()
                ->where(function ($query): void {
                    $query->whereJsonContains('allowed_modalities', 'BLENDED')
                        ->orWhereJsonContains('allowed_modalities', 'HYFE')
                        ->orWhereJsonContains('allowed_modalities', 'MODULAR');
                })
                ->doesntExist()
            && TermOffering::query()->whereIn('modality', ['BLENDED', 'HYFE', 'MODULAR'])->doesntExist()
            && SectionDeliveryGroup::query()->whereIn('modality', ['BLENDED', 'HYFE', 'MODULAR'])->doesntExist()
            && $this->readinessPassesForTerm($term)
            && $this->downstreamEvidenceIsEmpty();
    }

    private function exactAcademicYear(): ?AcademicYear
    {
        if (AcademicYear::query()->count() !== 1) {
            return null;
        }

        return AcademicYear::query()
            ->where('label', 'AY 2025-2026')
            ->where('starts_on', '2025-06-01')
            ->where('ends_on', '2026-05-31')
            ->where('state', AcademicYear::StateActive)
            ->first();
    }

    private function exactTerm(AcademicYear $academicYear): ?Term
    {
        if (Term::query()->count() !== 2) {
            return null;
        }

        $term = Term::query()
            ->whereBelongsTo($academicYear)
            ->where('type', Term::TypeSecondSemester)
            ->where('label', 'Second Semester')
            ->where('starts_on', '2026-01-05')
            ->where('ends_on', '2026-05-30')
            ->where('state', Term::StateActive)
            ->where('scheduling_slot_minutes', 30)
            ->where('scheduling_day_starts_at', '07:00:00')
            ->where('scheduling_day_ends_at', '21:00:00')
            ->where('default_max_units', 21)
            ->first();

        if (! $term instanceof Term || $term->getAttribute('scheduling_days') !== [1, 2, 3, 4, 5, 6]) {
            return null;
        }

        return $term;
    }

    private function exactPriorTerm(AcademicYear $academicYear): ?Term
    {
        $term = Term::query()
            ->whereBelongsTo($academicYear)
            ->where('type', Term::TypeFirstSemester)
            ->where('label', 'First Semester')
            ->where('starts_on', '2025-06-02')
            ->where('ends_on', '2025-10-31')
            ->where('state', Term::StateClosed)
            ->where('scheduling_slot_minutes', 30)
            ->where('scheduling_day_starts_at', '07:00:00')
            ->where('scheduling_day_ends_at', '21:00:00')
            ->where('default_max_units', 21)
            ->first();

        if (! $term instanceof Term || $term->getAttribute('scheduling_days') !== [1, 2, 3, 4, 5, 6]) {
            return null;
        }

        return $term;
    }

    private function programsAreComplete(): bool
    {
        $definitions = $this->programDefinitions();

        if (Program::query()->count() !== count($definitions)) {
            return false;
        }

        foreach ($definitions as $code => $name) {
            if (! Program::query()
                ->where('code', $code)
                ->where('name', $name)
                ->where('duration_years', 3)
                ->where('is_active', true)
                ->exists()) {
                return false;
            }
        }

        return true;
    }

    private function admissionsAcceptanceBaselineIsComplete(Term $term): bool
    {
        if (AdmissionCycle::query()->count() !== 1) {
            return false;
        }

        $cycle = AdmissionCycle::query()
            ->where('code', 'CYCLE-2026-A')
            ->where('state', AdmissionCycle::StatePublished)
            ->whereBelongsTo($term)
            ->with(['programs', 'requirementSets.requirements'])
            ->first();

        if (! $cycle instanceof AdmissionCycle
            || $cycle->programs->pluck('code')->sort()->values()->all() !== ['DBM', 'DIT', 'DTHM']
            || $cycle->programs->contains(function (Program $program): bool {
                $pivot = $program->getRelation('pivot');

                return ! data_get($pivot, 'accepts_first_year')
                    || ! data_get($pivot, 'accepts_transferee');
            })) {
            return false;
        }

        $sets = $cycle->requirementSets->keyBy('application_path');
        $firstYear = $sets->get(AdmissionCycle::PathFirstYear);
        $transferee = $sets->get(AdmissionCycle::PathTransferee);

        return $sets->count() === 2
            && $sets->every(fn (AdmissionRequirementSet $set): bool => $set->state === AdmissionRequirementSet::StatePublished)
            && $firstYear instanceof AdmissionRequirementSet
            && $firstYear->requirements->count() === 3
            && $transferee instanceof AdmissionRequirementSet
            && $transferee->requirements->count() === 2;
    }

    private function calendarWindowsAreComplete(Term $term): bool
    {
        if (CalendarEvent::query()->count() !== 2) {
            return false;
        }

        $schedulingWindowExists = CalendarEvent::query()
            ->whereBelongsTo($term)
            ->where('event_type', CalendarEvent::TypeWindow)
            ->where('scope_type', CalendarEvent::ScopeInstitution)
            ->where('process_key', CalendarEvent::ProcessScheduling)
            ->where('start_at', '2026-01-05 07:00:00')
            ->where('end_at', '2026-05-30 21:00:00')
            ->where('blocks_scheduling', false)
            ->where('state', CalendarEvent::StateActive)
            ->where('authority', 'Registrar-approved current-term scheduling window')
            ->exists();

        $admissionsWindowExists = CalendarEvent::query()
            ->whereBelongsTo($term)
            ->where('event_type', CalendarEvent::TypeWindow)
            ->where('scope_type', CalendarEvent::ScopeInstitution)
            ->where('process_key', CalendarEvent::ProcessAdmissions)
            ->where('start_at', '2026-07-01 00:00:00')
            ->where('end_at', '2026-08-31 23:59:59')
            ->where('blocks_scheduling', false)
            ->where('state', CalendarEvent::StateActive)
            ->where('authority', 'Registrar-approved admissions period')
            ->exists();

        return $schedulingWindowExists && $admissionsWindowExists;
    }

    private function downpaymentRulesAreComplete(Term $term): bool
    {
        if (FeeRule::query()->count() !== 3) {
            return false;
        }

        foreach (array_keys($this->programDefinitions()) as $programCode) {
            $program = Program::query()->where('code', $programCode)->first();
            $feeRule = FeeRule::query()->where('code', 'DOWNPAYMENT-'.$programCode.'-2025-2')->first();

            if (! $program instanceof Program
                || ! $feeRule instanceof FeeRule
                || ! FeeRule::query()
                    ->whereKey($feeRule->id)
                    ->where('name', $programCode.' Second Semester Downpayment')
                    ->where('ledger_category', FeeRule::LedgerCategoryDownpayment)
                    ->where('display_category', FeeRule::DisplayCategoryDownpayment)
                    ->whereBelongsTo($program)
                    ->whereBelongsTo($term)
                    ->where('calculation_type', FeeRule::CalculationFixed)
                    ->where('amount', 2000)
                    ->whereNull('rate')
                    ->where('effective_from', '2026-01-05')
                    ->where('effective_until', '2026-05-30')
                    ->where('is_active', true)
                    ->exists()) {
                return false;
            }
        }

        return true;
    }

    private function readinessPassesForTerm(Term $term): bool
    {
        return $this->readinessService->evaluateTerm($term)['is_ready'];
    }

    private function courseCatalogIsComplete(Term $term, Term $priorTerm): bool
    {
        $programSpecificCourseCodes = collect($this->thirdYearCourseDefinitions())
            ->flatMap(fn (array $definitions): array => array_keys($definitions))
            ->unique()
            ->all();

        foreach ($this->courseCatalog() as $code => $definition) {
            $course = Course::query()
                ->where('code', $code)
                ->where('state', Course::StateActive)
                ->first();

            if (! $course instanceof Course) {
                return false;
            }

            if (in_array($code, $programSpecificCourseCodes, true)) {
                continue;
            }

            if (! $this->courseSpecificationIsComplete($term, $course, 'AY2025-2026', $definition)) {
                return false;
            }
        }

        $nstpCourse = Course::query()->where('code', 'NSTP02')->first();
        $dbmNstpDefinition = $this->courseCatalog()['NSTP02'];
        $dbmNstpDefinition['units'] = 2;

        if (! $nstpCourse instanceof Course
            || ! $this->courseSpecificationIsComplete(
                $term,
                $nstpCourse,
                $this->specificationRevisionCode('DBM', 'NSTP02'),
                $dbmNstpDefinition,
            )) {
            return false;
        }

        foreach ($this->thirdYearCourseDefinitions() as $programCode => $definitions) {
            foreach ($definitions as $courseCode => $definition) {
                $course = Course::query()->where('code', $courseCode)->first();

                if (! $course instanceof Course
                    || ! $this->courseSpecificationIsComplete(
                        $term,
                        $course,
                        $this->specificationRevisionCode($programCode, $courseCode),
                        $definition,
                    )) {
                    return false;
                }
            }
        }

        foreach ($this->curriculumFixtureCatalog->firstSemesterRows() as $programCode => $yearRows) {
            foreach ($yearRows as $yearLevel => $rows) {
                foreach ($rows as $row) {
                    $course = Course::query()->where('code', $row['code'])->first();

                    if (! $course instanceof Course
                        || ! $this->courseSpecificationIsComplete(
                            $priorTerm,
                            $course,
                            $this->firstSemesterRevisionCode($programCode, $yearLevel),
                            $this->firstSemesterCourseDefinition($row),
                        )) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /** @param array{title:string,units:float,modality:string,room_type:string} $definition */
    private function courseSpecificationIsComplete(
        Term $term,
        Course $course,
        string $revisionCode,
        array $definition,
    ): bool {
        $specification = CourseSpecification::query()
            ->whereBelongsTo($course)
            ->whereBelongsTo($term, 'effectiveTerm')
            ->where('revision_code', $revisionCode)
            ->where('title', $definition['title'])
            ->where('description', $this->courseSpecificationDescription($definition))
            ->where('credit_units', $definition['units'])
            ->where('grading_profile_key', CourseSpecification::GradingProfileCollegeStandard)
            ->where('grading_profile_version', 1)
            ->where('scheduling_treatment', $this->schedulingTreatment($definition))
            ->where('same_faculty_default', true)
            ->where('state', CourseSpecification::StateActive)
            ->first();

        if (! $specification instanceof CourseSpecification
            || $specification->getAttribute('allowed_modalities') !== [
                TermOffering::ModalityOnline,
                TermOffering::ModalityFaceToFace,
            ]) {
            return false;
        }

        if ($specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged) {
            return ! $specification->components()->exists();
        }

        $component = CourseComponent::query()
            ->whereBelongsTo($specification)
            ->where('component_type', $definition['room_type'] === Room::TypeLectureRoom
                ? CourseComponent::TypeLecture
                : CourseComponent::TypeLaboratory)
            ->where('weekly_contact_hours', $definition['units'])
            ->where('meeting_pattern', $this->meetingPattern($course, $definition))
            ->where('room_type_default', $definition['room_type'])
            ->whereNull('modality_restriction')
            ->where('requires_consecutive_block', $definition['units'] >= 4)
            ->where('same_faculty', true)
            ->where('sequence', 1)
            ->first();

        return $component instanceof CourseComponent
            && $component->getAttribute('required_room_feature_keys') === [];
    }

    private function curriculaAndEntriesAreComplete(Term $term): bool
    {
        $registrar = User::query()->where('email', 'registrar.demo@example.test')->first();

        if (! $registrar instanceof User) {
            return false;
        }

        $curricula = [];

        foreach (array_keys($this->programDefinitions()) as $programCode) {
            $program = Program::query()->where('code', $programCode)->first();

            if (! $program instanceof Program) {
                return false;
            }

            $curriculum = CurriculumVersion::query()
                ->whereBelongsTo($program)
                ->whereBelongsTo($term, 'effectiveEntryTerm')
                ->whereBelongsTo($registrar, 'approver')
                ->where('version_code', $programCode.'-AY2025-2026')
                ->where('name', $programCode.' AY 2025-2026 Curriculum')
                ->where('state', CurriculumVersion::StateActive)
                ->where('approval_reference', 'Registrar-approved client curriculum encoding')
                ->where('approved_at', '2025-12-01 08:00:00')
                ->first();

            if (! $curriculum instanceof CurriculumVersion) {
                return false;
            }

            $curricula[$programCode] = $curriculum;
        }

        foreach ($curricula as $programCode => $curriculum) {
            foreach ($this->curriculumPlacements($programCode) as $index => $placement) {
                $course = Course::query()->where('code', $placement['course_code'])->first();
                $specification = $course instanceof Course
                    ? CourseSpecification::query()
                        ->whereBelongsTo($course)
                        ->where('revision_code', $placement['revision_code'])
                        ->first()
                    : null;

                if (! $specification instanceof CourseSpecification
                    || ! CurriculumEntry::query()
                        ->whereBelongsTo($curriculum)
                        ->whereBelongsTo($specification)
                        ->where('year_level', $placement['year_level'])
                        ->where('term_label', $placement['term_label'])
                        ->where('term_type', $placement['term_type'])
                        ->where('sequence', $index + 1)
                        ->where('requirement_group', CurriculumEntry::RequirementGroupRequired)
                        ->exists()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Verifies every synthetic student's structural fixture data.
     *
     * Login-password verification belongs to representativeAccountsAreComplete()
     * so scenario inspection does not repeat an expensive hash check per student.
     */
    private function studentCohortsAreComplete(): bool
    {
        $globalNumber = 1;

        foreach ($this->cohorts() as $cohortCode => $cohort) {
            $program = Program::query()->where('code', $cohort['program'])->first();
            $curriculum = $program instanceof Program
                ? CurriculumVersion::query()->whereBelongsTo($program)->first()
                : null;

            if (! $program instanceof Program || ! $curriculum instanceof CurriculumVersion) {
                return false;
            }

            for ($number = 1; $number <= $cohort['students']; $number++) {
                $email = $globalNumber === 1
                    ? 'student.demo@example.test'
                    : sprintf('student.%s.%03d@example.test', strtolower($cohortCode), $number);
                [$firstName, $middleName, $lastName] = $this->studentIdentity($globalNumber);
                $user = User::query()->where('email', $email)->first();

                if (! $user instanceof User
                    || $user->first_name !== $firstName
                    || $user->last_name !== $lastName
                    || $user->status !== User::StatusActive
                    || $user->roles()->orderBy('name')->pluck('name')->all() !== ['student']
                    || ! $user->hasVerifiedEmail()
                    || ! StudentProfile::query()
                        ->whereBelongsTo($user)
                        ->whereBelongsTo($program)
                        ->whereBelongsTo($curriculum, 'curriculumVersion')
                        ->where('student_number', sprintf('%s-%03d', $cohortCode, $number))
                        ->where('first_name', $firstName)
                        ->where('middle_name', $middleName)
                        ->where('last_name', $lastName)
                        ->where('birth_date', sprintf('2005-%02d-%02d', (($globalNumber - 1) % 12) + 1, (($globalNumber - 1) % 27) + 1))
                        ->where('lifecycle_status', StudentProfile::LifecycleActive)
                        ->where('academic_standing', $this->expectedAcademicStanding(sprintf('%s-%03d', $cohortCode, $number)))
                        ->where('email', $email)
                        ->where('address', 'San Pedro, Laguna')
                        ->where('emergency_contact_name', 'Designated family contact')
                        ->whereNull('phone')
                        ->whereNull('emergency_contact_phone')
                        ->exists()) {
                    return false;
                }

                $globalNumber++;
            }
        }

        return true;
    }

    private function offeringStructureIsComplete(Term $term): bool
    {
        foreach ($this->academicScopes() as $scope) {
            $program = Program::query()->where('code', $scope['program'])->first();
            $curriculum = $program instanceof Program
                ? CurriculumVersion::query()->whereBelongsTo($program)->first()
                : null;

            if (! $curriculum instanceof CurriculumVersion) {
                return false;
            }

            $scopeExpectedCount = collect($scope['cohorts'])->sum('students');

            foreach ($scope['courses'] as $courseCode) {
                $course = Course::query()->where('code', $courseCode)->first();
                $specification = $course instanceof Course
                    ? CourseSpecification::query()
                        ->whereBelongsTo($course)
                        ->where('revision_code', $this->specificationRevisionCode($scope['program'], $courseCode))
                        ->first()
                    : null;
                $entry = $specification instanceof CourseSpecification
                    ? CurriculumEntry::query()
                        ->whereBelongsTo($curriculum)
                        ->whereBelongsTo($specification)
                        ->where('year_level', $scope['year'])
                        ->where('term_type', Term::TypeSecondSemester)
                        ->first()
                    : null;
                $modality = $this->courseCatalog()[$courseCode]['modality'];
                $offering = $entry instanceof CurriculumEntry
                    ? TermOffering::query()
                        ->whereBelongsTo($term)
                        ->whereBelongsTo($entry)
                        ->where('category', TermOffering::CategoryRegular)
                        ->where('delivery_variant', TermOffering::ArrangementNormalClass)
                        ->where('modality', $modality)
                        ->where('expected_count', $scopeExpectedCount)
                        ->where('state', TermOffering::StatePendingScheduling)
                        ->first()
                    : null;

                if (! $offering instanceof TermOffering) {
                    return false;
                }

                foreach ($scope['cohorts'] as $cohortCode => $cohort) {
                    $section = Section::query()
                        ->whereBelongsTo($offering, 'termOffering')
                        ->where('code', $cohortCode.'-'.$courseCode)
                        ->where('capacity', 30)
                        ->where('state', Section::StatePlanned)
                        ->first();

                    if (! $section instanceof Section) {
                        return false;
                    }

                    $group = SectionDeliveryGroup::query()
                        ->whereBelongsTo($section)
                        ->where('name', $cohortCode)
                        ->where('expected_count', $cohort['students'])
                        ->where('modality', $modality)
                        ->where('state', SectionDeliveryGroup::StateReady)
                        ->first();

                    if (! $group instanceof SectionDeliveryGroup) {
                        return false;
                    }

                    $demandQuery = SchedulingDemand::query()
                        ->whereBelongsTo($offering)
                        ->whereBelongsTo($group, 'sectionDeliveryGroup');

                    if ($this->canonicalDataset->isExternallyArranged($scope['program'], $courseCode)) {
                        if ($demandQuery->exists()) {
                            return false;
                        }

                        continue;
                    }

                    if (! $demandQuery
                        ->where('modality', $modality)
                        ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
                        ->exists()) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function roomsAreComplete(): bool
    {
        foreach ($this->roomDefinitions() as [$code, $name, $type]) {
            if (! Room::query()
                ->where('code', $code)
                ->where('name', $name)
                ->where('building', 'Main Academic Building')
                ->where('room_type', $type)
                ->where('capacity', 40)
                ->where('is_active', true)
                ->where('notes', 'Teaching space available for the current term.')
                ->exists()) {
                return false;
            }
        }

        return true;
    }

    private function representativeAccountsAreComplete(): bool
    {
        $accounts = [
            'applicant.demo@example.test' => 'applicant',
            'student.demo@example.test' => 'student',
            'registrar.demo@example.test' => User::StaffRoleRegistrar,
            'accounting.demo@example.test' => User::StaffRoleAccounting,
            'faculty.demo@example.test' => User::StaffRoleFaculty,
            'academic-head.demo@example.test' => User::StaffRoleAcademicHead,
            'system-admin.demo@example.test' => User::StaffRoleSystemSuperAdmin,
        ];

        foreach ($accounts as $email => $role) {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User
                || ! $user->hasRole($role)
                || $user->roles()->orderBy('name')->pluck('name')->all() !== [$role]
                || ! $user->canAuthenticate()
                || ! $user->hasVerifiedEmail()
                || ! Hash::check('password', $user->password)) {
                return false;
            }
        }

        return true;
    }

    private function hasOperationalData(): bool
    {
        /** @var list<class-string<Model>> $models */
        $models = [
            AcademicYear::class,
            AdmissionCycle::class,
            AdmissionRequirementPolicy::class,
            Term::class,
            Program::class,
            User::class,
            StudentProfile::class,
            Course::class,
            CourseSpecification::class,
            CourseComponent::class,
            CurriculumVersion::class,
            CurriculumEntry::class,
            Room::class,
            FacultyQualification::class,
            FacultyTermLoadOverride::class,
            CalendarEvent::class,
            TermOffering::class,
            Section::class,
            SectionDeliveryGroup::class,
            SchedulingDemand::class,
            FeeRule::class,
            ScheduleGenerationRun::class,
            SectionMeeting::class,
            Enrollment::class,
            Assessment::class,
            LedgerEntry::class,
            PaymentAttempt::class,
            Payment::class,
        ];

        foreach ($models as $model) {
            if ($model::query()->exists()) {
                return true;
            }
        }

        return DB::table('webhook_calls')->exists();
    }

    private function downstreamEvidenceIsEmpty(): bool
    {
        return ! ScheduleGenerationRun::query()->exists()
            && ! SectionMeeting::query()->exists()
            && ! Enrollment::query()->exists()
            && ! Assessment::query()->exists()
            && ! LedgerEntry::query()->exists()
            && ! PaymentAttempt::query()->exists()
            && ! Payment::query()->exists()
            && ! DB::table('webhook_calls')->exists();
    }

    private function expectedAcademicStanding(string $studentNumber): string
    {
        return $this->scenarioAnchorDefinitions()[$studentNumber]['academic_standing']
            ?? StudentProfile::StandingRegular;
    }

    /**
     * These records are starting-state personas for later vertical acceptance
     * journeys. They do not fabricate enrollments, balances, holds, or schedules.
     *
     * @return array<string, array{academic_standing:string,purpose:string}>
     */
    private function scenarioAnchorDefinitions(): array
    {
        return [
            'DBM-1A-001' => [
                'academic_standing' => StudentProfile::StandingRegular,
                'purpose' => 'Regular progression and representative Student Hub account.',
            ],
            'DBM-1A-002' => [
                'academic_standing' => StudentProfile::StandingIrregular,
                'purpose' => 'First-year irregular subject-selection journey.',
            ],
            'DBM-2A-001' => [
                'academic_standing' => StudentProfile::StandingIrregular,
                'purpose' => 'Continuing irregular subject-selection journey.',
            ],
            'DIT-1A-001' => [
                'academic_standing' => StudentProfile::StandingProbationary,
                'purpose' => 'Probationary academic-status explanation and staff review.',
            ],
            'DIT-1A-002' => [
                'academic_standing' => StudentProfile::StandingDeficient,
                'purpose' => 'Academic-deficiency guidance and hold interaction.',
            ],
            'DIT-2A-001' => [
                'academic_standing' => StudentProfile::StandingBlockedByPrerequisite,
                'purpose' => 'Failed prerequisite and scoped-exception journey.',
            ],
            'DTHM-1A-001' => [
                'academic_standing' => StudentProfile::StandingMustRepeatYear,
                'purpose' => 'Repeat-year progression review.',
            ],
            'DTHM-1A-002' => [
                'academic_standing' => StudentProfile::StandingRegular,
                'purpose' => 'Regular first-year hospitality journey.',
            ],
            'DTHM-2A-001' => [
                'academic_standing' => StudentProfile::StandingRegular,
                'purpose' => 'Regular continuing hospitality journey.',
            ],
            'DTHM-2A-002' => [
                'academic_standing' => StudentProfile::StandingNotYetEvaluated,
                'purpose' => 'Missing progression-baseline journey.',
            ],
        ];
    }

    /**
     * @return array{
     *     readiness:'PASS'|'FAIL',
     *     total_teaching_units:float,
     *     arithmetic_faculty_lower_bound:int,
     *     faculty_loads:list<float>,
     *     maximum_constructed_load:float,
     *     assignments:list<array{workload_key:string,course_code:string,units:float,faculty_index:int}>,
     *     faculty_course_codes:array<int,list<string>>,
     *     unassigned_workloads:list<string>
     * }
     */
    private function facultyCapacityAssessment(): array
    {
        return $this->facultyCapacityAssessment->assess(
            workloads: $this->facultyWorkloads(),
            facultyCount: $this->canonicalDataset->manifest()['counts']['faculty'],
            maxUnits: 21.0,
        );
    }

    /**
     * Keeps each course's total demand together so the nine-Faculty canonical
     * result remains conservative.
     *
     * @return list<array{key:string,course_code:string,units:float}>
     */
    private function facultyWorkloads(): array
    {
        return collect($this->courseDemandWeights())
            ->map(fn (float $units, string $courseCode): array => [
                'key' => $courseCode,
                'course_code' => $courseCode,
                'units' => $units,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, list<string>> */
    private function facultyQualificationCourseMap(): array
    {
        $assessment = $this->facultyCapacityAssessment();

        if ($assessment['readiness'] !== 'PASS') {
            throw new RuntimeException(
                'The canonical workload cannot be assigned within its synthetic Faculty roster.',
            );
        }

        $qualificationMap = $assessment['faculty_course_codes'];

        return array_map(function (array $courseCodes): array {
            $courseCodes = array_values(array_unique($courseCodes));
            sort($courseCodes);

            return $courseCodes;
        }, $qualificationMap);
    }

    /** @return array<string, float> */
    private function courseDemandWeights(): array
    {
        $catalog = $this->courseCatalog();
        $weights = array_fill_keys(array_keys($catalog), 0.0);

        foreach ($this->cohorts() as $cohort) {
            foreach ($cohort['courses'] as $courseCode) {
                $weights[$courseCode] += $courseCode === 'NSTP02' && $cohort['program'] === 'DBM'
                    ? 2
                    : $catalog[$courseCode]['units'];
            }
        }

        return $weights;
    }

    private function specificationKey(string $programCode, string $courseCode): string
    {
        if ($programCode === 'DBM' && $courseCode === 'NSTP02') {
            return 'DBM:NSTP02';
        }

        if (isset($this->thirdYearCourseDefinitions()[$programCode][$courseCode])) {
            return $programCode.':'.$courseCode;
        }

        return $courseCode;
    }

    private function specificationRevisionCode(string $programCode, string $courseCode): string
    {
        if ($programCode === 'DBM' && $courseCode === 'NSTP02') {
            return 'AY2025-2026-DBM';
        }

        if (isset($this->thirdYearCourseDefinitions()[$programCode][$courseCode])) {
            return 'AY2025-2026-'.$programCode;
        }

        return 'AY2025-2026';
    }

    private function firstSemesterSpecificationKey(
        string $programCode,
        string $yearLevel,
        string $courseCode,
    ): string {
        return implode(':', ['FIRST_SEMESTER', $programCode, $yearLevel, $courseCode]);
    }

    private function firstSemesterRevisionCode(string $programCode, string $yearLevel): string
    {
        $yearCode = match ($yearLevel) {
            'First Year' => 'Y1',
            'Second Year' => 'Y2',
            'Third Year' => 'Y3',
            default => throw new RuntimeException("Unknown curriculum year level [{$yearLevel}]."),
        };

        return "AY2025-2026-{$programCode}-{$yearCode}-S1";
    }

    /**
     * @param array{
     *     source_code:string,
     *     code:string,
     *     title:string,
     *     units:float,
     *     prerequisite_source:string
     * } $row
     * @return array{
     *     title:string,
     *     units:float,
     *     modality:string,
     *     room_type:string,
     *     source_code:string,
     *     prerequisite_source:string
     * }
     */
    private function firstSemesterCourseDefinition(array $row): array
    {
        $online = str_starts_with($row['code'], 'GE')
            || str_starts_with($row['code'], 'NSTP')
            || str_starts_with($row['code'], 'PE')
            || str_starts_with($row['code'], 'NIHONGO');
        $computer = collect([
            'CC',
            'CSS',
            'NET',
            'IM',
            'IPT',
            'SA',
            'CAP',
            'MS',
            'WEB',
            'SP',
            'IS',
            'ANIM',
        ])->contains(fn (string $prefix): bool => str_starts_with($row['code'], $prefix));

        return [
            'title' => $row['title'],
            'units' => $row['units'],
            'modality' => $online
                ? TermOffering::ModalityOnline
                : TermOffering::ModalityFaceToFace,
            'room_type' => $computer
                ? Room::TypeComputerLaboratory
                : ($row['units'] >= 4 ? Room::TypeLaboratory : Room::TypeLectureRoom),
            'source_code' => $row['source_code'],
            'prerequisite_source' => $row['prerequisite_source'],
        ];
    }

    /**
     * @return list<array{
     *     year_level:string,
     *     term_label:string,
     *     term_type:string,
     *     specification_key:string,
     *     course_code:string,
     *     revision_code:string
     * }>
     */
    private function curriculumPlacements(string $programCode): array
    {
        $placements = [];
        $firstSemesterRows = $this->curriculumFixtureCatalog->firstSemesterRows()[$programCode] ?? null;

        if (! is_array($firstSemesterRows)) {
            throw new RuntimeException("Missing first-semester curriculum rows for [{$programCode}].");
        }

        foreach (['First Year', 'Second Year', 'Third Year'] as $yearLevel) {
            foreach ($firstSemesterRows[$yearLevel] ?? [] as $row) {
                $placements[] = [
                    'year_level' => $yearLevel,
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'specification_key' => $this->firstSemesterSpecificationKey(
                        $programCode,
                        $yearLevel,
                        $row['code'],
                    ),
                    'course_code' => $row['code'],
                    'revision_code' => $this->firstSemesterRevisionCode($programCode, $yearLevel),
                ];
            }

            foreach ($this->canonicalDataset->courseCodes($programCode, $yearLevel) as $courseCode) {
                $placements[] = [
                    'year_level' => $yearLevel,
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'specification_key' => $this->specificationKey($programCode, $courseCode),
                    'course_code' => $courseCode,
                    'revision_code' => $this->specificationRevisionCode($programCode, $courseCode),
                ];
            }
        }

        return $placements;
    }

    /** @return array<string, string> */
    private function programDefinitions(): array
    {
        return [
            'DBM' => 'Diploma in Business Management Technology',
            'DIT' => 'Diploma in Information Technology',
            'DTHM' => 'Diploma in Tourism and Hospitality Management Services',
        ];
    }

    /** @return list<array{string, string, string}> */
    private function roomDefinitions(): array
    {
        return $this->canonicalDataset->roomDefinitions();
    }

    /**
     * @return array<string, array{program:string,year:string,students:int,courses:list<string>}>
     */
    private function cohorts(): array
    {
        return $this->canonicalDataset->cohorts();
    }

    /**
     * @return list<array{
     *     program:string,
     *     year:string,
     *     courses:list<string>,
     *     cohorts:array<string, array{program:string,year:string,students:int,courses:list<string>}>
     * }>
     */
    private function academicScopes(): array
    {
        return collect($this->cohorts())
            ->groupBy(
                fn (array $cohort): string => $cohort['program'].'|'.$cohort['year'],
                preserveKeys: true,
            )
            ->map(function ($cohorts): array {
                $first = $cohorts->first();

                return [
                    'program' => $first['program'],
                    'year' => $first['year'],
                    'courses' => $first['courses'],
                    'cohorts' => $cohorts->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{title:string,units:float,modality:string,room_type:string}>
     */
    private function courseCatalog(): array
    {
        $online = TermOffering::ModalityOnline;
        $faceToFace = TermOffering::ModalityFaceToFace;
        $lecture = Room::TypeLectureRoom;
        $laboratory = Room::TypeLaboratory;
        $computer = Room::TypeComputerLaboratory;

        return [
            'GE04' => ['title' => 'Contemporary World', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
            'GE05' => ['title' => 'Science, Technology and Society', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
            'GE06' => ['title' => 'Reading in Philippine History', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
            'NSTP02' => ['title' => 'Civic Welfare Training Service 2', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
            'PE02' => ['title' => 'Physical Education (Rhythmic Activities)', 'units' => 2, 'modality' => $online, 'room_type' => $lecture],
            'BME05' => ['title' => 'Retail Management', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'BME04' => ['title' => 'Advertising', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'CSNCII' => ['title' => 'Customer Service NC II', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'FOSNCII' => ['title' => 'Front Office Services NC II', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'BME06' => ['title' => 'Product Management', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'CC102' => ['title' => 'Computer Programming 1 (Java)', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'PHY101' => ['title' => 'Calculus-Based Physics', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'CC103' => ['title' => 'Computer Programming 2 (.NET Console)', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'HSKPNCII' => ['title' => 'Housekeeping NC II', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'THC05' => ['title' => 'Micro Perspective of Tourism and Hospitality', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'THC04' => ['title' => 'Professional Development and Applied Ethics', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'THC03' => ['title' => 'Quality Service Management in Tourism and Hospitality', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'HPC07' => ['title' => 'Front Office Services NC II', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'GE10' => ['title' => 'Social Science and Philosophy', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
            'GE09' => ['title' => 'Art Appreciation', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
            'PE04' => ['title' => 'Physical Education (Team and Group Sports)', 'units' => 2, 'modality' => $online, 'room_type' => $lecture],
            'AGRONCIII' => ['title' => 'Agro Entrepreneurship NC III', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'BME09' => ['title' => 'Basic Macroeconomics', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'BME10' => ['title' => 'Professional Salesmanship', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'BME11' => ['title' => 'Marketing Research', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'BME12' => ['title' => 'Marketing Management (with Case Analysis)', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'BME13' => ['title' => 'Income Taxation', 'units' => 2, 'modality' => $faceToFace, 'room_type' => $lecture],
            'TECH001' => ['title' => 'Technopreneurship', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'NET102' => ['title' => 'Networking 2 (SCS)', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'VGDNCIII' => ['title' => 'Visual Graphic Design NC III', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $computer],
            'IAS101' => ['title' => 'Information Assurance and Security 1', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'DM101' => ['title' => 'Organization and Management Concepts', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'HCI101' => ['title' => 'Introduction to Human-Computer Interaction', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'IAS102' => ['title' => 'Information Assurance and Security 2', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'THC07' => ['title' => 'Tourism and Hospitality Marketing', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'HPC11EMS' => ['title' => 'Menu Design and Revenue Management / Product Packaging Merchandising', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'THC08' => ['title' => 'Legal Aspects in Tourism and Hospitality / Housekeeping NC IV', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'BME01' => ['title' => 'Operations Management in the Tourism and Hospitality Industry', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'HPC13EMS' => ['title' => 'Introduction to MICE / Events Management Services NC III', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'THC06' => ['title' => 'Philippine Tourism, Geography and Culture', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'THC09' => ['title' => 'Program-specific Third-Year THC 9', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'THC10' => ['title' => 'Program-specific Third-Year THC 10', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'HMPRAC03' => ['title' => 'Program-specific Third-Year Practicum 3', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'HPC22' => ['title' => 'Program-specific Third-Year HPC 22', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'COOPMM' => ['title' => 'Cooperative Marketing / Cooperative Development and Management NC IV', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'BME20' => ['title' => 'Business Research', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'BME21' => ['title' => 'E-Commerce and Internet Marketing', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'BME16' => ['title' => 'Cost Accounting and Control', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            'SIA101' => ['title' => 'System Integration and Architecture 1', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'SF101' => ['title' => 'System Fundamentals', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'PD101' => ['title' => 'Parallel and Distributed Computing', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'CAP102' => ['title' => 'Research 2', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
            'WEBNCIII2' => ['title' => 'Web Development NC III - 2 (Cascading Style Sheets)', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $computer],
            'NIHONGO02' => ['title' => 'Foreign Language / Japanese Language 2', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
            'HMPRAC02' => ['title' => 'Program-specific Work-Related Training / Internship', 'units' => 6, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'HPC23' => ['title' => 'Gastronomy (Food and Culture)', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'HPC24' => ['title' => 'Recreation and Leisure Management / Tourism Promotion Services NC II', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
            'HPC20' => ['title' => 'Ergonomics and Facilities Planning for the Hospitality Industry / Housekeeping NC IV', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
        ];
    }

    /**
     * @return array<string, array<string, array{title:string,units:float,modality:string,room_type:string}>>
     */
    private function thirdYearCourseDefinitions(): array
    {
        $online = TermOffering::ModalityOnline;
        $faceToFace = TermOffering::ModalityFaceToFace;
        $lecture = Room::TypeLectureRoom;
        $laboratory = Room::TypeLaboratory;
        $computer = Room::TypeComputerLaboratory;

        return [
            'DBM' => [
                'THC09' => ['title' => 'International Business and Trade', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
                'THC10' => ['title' => 'Feasibility Study', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
                'HMPRAC03' => ['title' => 'IT Application Tools in Business', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
                'HPC22' => ['title' => 'Strategic Management', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
                'COOPMM' => ['title' => 'Cooperative Marketing / Cooperative Development and Management NC IV', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
                'BME20' => ['title' => 'Business Research', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
                'BME21' => ['title' => 'E-Commerce and Internet Marketing', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
                'BME16' => ['title' => 'Cost Accounting and Control', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
            ],
            'DIT' => [
                'SIA101' => ['title' => 'System Integration and Architecture 1', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
                'SF101' => ['title' => 'System Fundamentals', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
                'PD101' => ['title' => 'Parallel and Distributed Computing', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
                'CAP102' => ['title' => 'Research 2', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $computer],
                'WEBNCIII2' => ['title' => 'Web Development NC III - 2 (Cascading Style Sheets)', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $computer],
                'NIHONGO02' => ['title' => 'Foreign Language / Japanese Language 2', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
                'HMPRAC02' => ['title' => 'Work-Related Training - Internship (600 Hours)', 'units' => 6, 'modality' => $faceToFace, 'room_type' => $laboratory],
            ],
            'DTHM' => [
                'THC09' => ['title' => 'Multi-Cultural Diversity in the Workplace for the Tourism Professional', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
                'THC10' => ['title' => 'Entrepreneurship in Tourism and Hospitality', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $lecture],
                'HMPRAC03' => ['title' => 'Work-Related Training (Travel Agency / Hotel)', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $laboratory],
                'HPC22' => ['title' => 'Foreign Language / Japanese Language', 'units' => 3, 'modality' => $online, 'room_type' => $lecture],
                'HPC23' => ['title' => 'Gastronomy (Food and Culture)', 'units' => 3, 'modality' => $faceToFace, 'room_type' => $laboratory],
                'HPC24' => ['title' => 'Recreation and Leisure Management / Tourism Promotion Services NC II', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
                'HPC20' => ['title' => 'Ergonomics and Facilities Planning for the Hospitality Industry / Housekeeping NC IV', 'units' => 4, 'modality' => $faceToFace, 'room_type' => $laboratory],
                'HMPRAC02' => ['title' => 'Work-Related Training - Internship (600 Hours)', 'units' => 6, 'modality' => $faceToFace, 'room_type' => $laboratory],
            ],
        ];
    }
}
