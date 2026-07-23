<?php

namespace Database\Seeders;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Actions\Registrar\BuildTermOfferings;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\AcademicYear;
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

final class ClientAlignedAcceptanceBaselineSeeder extends Seeder
{
    public const StateEmpty = 'empty';

    public const StateComplete = 'complete';

    public const StateConflict = 'conflict';

    public function __construct(
        private readonly DatabaseSeeder $databaseSeeder,
        private readonly BuildTermOfferings $offeringBuilder,
        private readonly SectionDeliveryGroupService $deliveryGroupService,
        private readonly GenerateSchedulingDemand $demandGenerator,
        private readonly TermSchedulingReadinessService $readinessService,
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

            [$term, $registrar, $faculty] = $this->createTermAndAccounts();
            $programs = $this->createPrograms();
            $specifications = $this->createCourseCatalog($term);
            $curricula = $this->createCurricula($term, $registrar, $programs, $specifications);

            $this->createStudents($programs, $curricula);
            $this->createRooms();
            $this->createFacultyReadiness($term, $registrar, $faculty, $specifications);
            $this->createSchedulingWindow($term);
            $this->createOfferings($term, $registrar, $programs, $curricula);
            $this->markDeliveryGroupsReady($registrar);
            $this->createDownpaymentRules($term, $programs);

            $demandSummary = $this->demandGenerator->forTerm($registrar, $term);
            $readiness = $this->readinessService->evaluateTerm($term);

            if ($demandSummary['total'] !== 54
                || $demandSummary['ready'] !== 54
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
     *     database:string,
     *     students:int,
     *     cohorts:int,
     *     scheduling_demands:int,
     *     ready_scheduling_demands:int,
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
            'standings' => $standings,
            'scenario_anchors' => [
                'matched' => $matchedAnchors,
                'expected' => count($this->scenarioAnchorDefinitions()),
            ],
            'downstream_state' => collect($downstream)->sum() === 0 ? 'EMPTY' : 'PRESENT',
            'downstream' => $downstream,
        ];
    }

    /** @return array{Term, User, list<User>} */
    private function createTermAndAccounts(): array
    {
        $academicYear = AcademicYear::query()->create([
            'label' => 'AY 2025-2026',
            'starts_on' => '2025-06-01',
            'ends_on' => '2026-05-31',
            'state' => AcademicYear::StateActive,
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
            'scheduling_day_ends_at' => '20:00:00',
            'default_max_units' => 21,
        ]);

        $this->createUser('Applicant', 'Demo', 'applicant.demo@example.test', 'applicant', User::StatusApplicantPending);
        $registrar = $this->createUser('Registrar', 'Demo', 'registrar.demo@example.test', User::StaffRoleRegistrar);
        $this->createUser('Accounting', 'Demo', 'accounting.demo@example.test', User::StaffRoleAccounting);
        $this->createUser('Academic Head', 'Demo', 'academic-head.demo@example.test', User::StaffRoleAcademicHead);
        $this->createUser('System Admin', 'Demo', 'system-admin.demo@example.test', User::StaffRoleSystemSuperAdmin);

        $faculty = [];

        for ($number = 1; $number <= 12; $number++) {
            $email = $number === 1
                ? 'faculty.demo@example.test'
                : sprintf('faculty%02d.demo@example.test', $number);
            $faculty[] = $this->createUser('Faculty', sprintf('%02d', $number), $email, User::StaffRoleFaculty);
        }

        return [$term, $registrar, $faculty];
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
    private function createCourseCatalog(Term $term): array
    {
        $specifications = [];

        foreach ($this->courseCatalog() as $code => $definition) {
            $course = Course::query()->create([
                'code' => $code,
                'state' => Course::StateActive,
            ]);
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

        return $specifications;
    }

    /** @param array{title:string,units:float,modality:string,room_type:string} $definition */
    private function createCourseSpecification(
        Course $course,
        Term $term,
        string $revisionCode,
        array $definition,
    ): CourseSpecification {
        $specification = CourseSpecification::query()->create([
            'course_id' => $course->id,
            'revision_code' => $revisionCode,
            'title' => $definition['title'],
            'description' => 'Synthetic acceptance specification aligned to the supplied curriculum evidence.',
            'credit_units' => $definition['units'],
            'grading_profile_key' => CourseSpecification::GradingProfileCollegeStandard,
            'grading_profile_version' => 1,
            'allowed_modalities' => [TermOffering::ModalityOnline, TermOffering::ModalityFaceToFace],
            'same_faculty_default' => true,
            'effective_term_id' => $term->id,
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::query()->create([
            'course_specification_id' => $specification->id,
            'component_type' => $definition['room_type'] === Room::TypeLectureRoom
                ? CourseComponent::TypeLecture
                : CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => $definition['units'],
            'room_type_default' => $definition['room_type'],
            'required_room_feature_keys' => [],
            'modality_restriction' => null,
            'requires_consecutive_block' => $definition['units'] >= 4,
            'same_faculty' => true,
            'sequence' => 1,
        ]);

        return $specification;
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
                'approval_reference' => 'TAL-96B1 synthetic acceptance baseline',
                'approved_by' => $registrar->id,
                'approved_at' => '2025-12-01 08:00:00',
            ]);
            $sequence = 1;

            foreach ($this->cohorts() as $cohort) {
                if ($cohort['program'] !== $programCode) {
                    continue;
                }

                foreach ($cohort['courses'] as $courseCode) {
                    CurriculumEntry::query()->create([
                        'curriculum_version_id' => $curriculum->id,
                        'course_specification_id' => $specifications[
                            $this->specificationKey($programCode, $courseCode)
                        ]->id,
                        'year_level' => $cohort['year'],
                        'term_label' => 'Second Semester',
                        'term_type' => Term::TypeSecondSemester,
                        'sequence' => $sequence++,
                        'requirement_group' => CurriculumEntry::RequirementGroupRequired,
                    ]);
                }
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
                $firstName = 'Tala';
                $lastName = sprintf('Student %03d', $globalNumber);
                $user = $this->createUser($firstName, $lastName, $email, 'student');

                $studentNumber = sprintf('%s-%03d', $cohortCode, $number);

                StudentProfile::query()->create([
                    'user_id' => $user->id,
                    'student_number' => $studentNumber,
                    'first_name' => $firstName,
                    'middle_name' => null,
                    'last_name' => $lastName,
                    'birth_date' => sprintf('2005-%02d-%02d', (($globalNumber - 1) % 12) + 1, (($globalNumber - 1) % 27) + 1),
                    'program_id' => $programs[$cohort['program']]->id,
                    'curriculum_version_id' => $curricula[$cohort['program']]->id,
                    'lifecycle_status' => StudentProfile::LifecycleActive,
                    'academic_standing' => $this->expectedAcademicStanding($studentNumber),
                    'email' => $email,
                    'phone' => null,
                    'address' => 'Synthetic acceptance address',
                    'emergency_contact_name' => 'Synthetic Contact',
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
                'building' => 'Synthetic Main Building',
                'room_type' => $type,
                'capacity' => 40,
                'is_active' => true,
                'notes' => 'Fictional TAL-96B1 acceptance resource.',
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
                'authority' => 'TAL-96B1 synthetic acceptance baseline',
                'reason' => 'Deterministic acceptance load ceiling.',
                'is_active' => true,
            ]);
        }

        $weights = $this->courseDemandWeights();
        arsort($weights);
        $facultyLoads = array_fill(0, count($faculty), 0.0);

        foreach (array_keys($weights) as $courseCode) {
            $facultyIndex = array_search(min($facultyLoads), $facultyLoads, true);
            $facultyIndex = is_int($facultyIndex) ? $facultyIndex : 0;
            $specification = $specifications[$courseCode];

            FacultyQualification::query()->create([
                'faculty_user_id' => $faculty[$facultyIndex]->id,
                'course_id' => $specification->course_id,
                'is_active' => true,
                'recorded_by' => $registrar->id,
                'recorded_at' => '2025-12-01 08:00:00',
                'notes' => 'Synthetic qualification for TAL-96B1 acceptance.',
            ]);
            $facultyLoads[$facultyIndex] += $weights[$courseCode];
        }

        if (max($facultyLoads) > 21) {
            throw new RuntimeException('The deterministic faculty assignment exceeds the approved 21-unit ceiling.');
        }
    }

    private function createSchedulingWindow(Term $term): void
    {
        CalendarEvent::query()->create([
            'term_id' => $term->id,
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessScheduling,
            'start_at' => '2026-01-05 07:00:00',
            'end_at' => '2026-05-30 20:00:00',
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
            'authority' => 'TAL-96B1 synthetic acceptance baseline',
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
        foreach ($this->cohorts() as $cohortCode => $cohort) {
            $curriculum = $curricula[$cohort['program']];
            $entries = $this->offeringBuilder
                ->preview($term, $programs[$cohort['program']], $curriculum, $cohort['year'])
                ->keyBy(fn (CurriculumEntry $entry): string => (string) $entry->courseSpecification?->course?->code);
            $rows = [];

            foreach ($cohort['courses'] as $courseCode) {
                $entry = $entries->get($courseCode);

                if (! $entry instanceof CurriculumEntry) {
                    throw new RuntimeException("Missing eligible curriculum entry for {$cohortCode} {$courseCode}.");
                }

                $modality = $this->courseCatalog()[$courseCode]['modality'];
                $rows[] = [
                    'curriculum_entry_id' => $entry->id,
                    'expected_count' => $cohort['students'],
                    'modality' => $modality,
                    'sections' => [[
                        'code' => $cohortCode.'-'.$courseCode,
                        'capacity' => 30,
                        'delivery_groups' => [[
                            'name' => $cohortCode,
                            'expected_count' => $cohort['students'],
                            'modality' => $modality,
                        ]],
                    ]],
                ];
            }

            $summary = $this->offeringBuilder->regular(
                $registrar,
                $term,
                $programs[$cohort['program']],
                $curriculum,
                $cohort['year'],
                $rows,
            );

            if ($summary['created'] !== count($cohort['courses']) || $summary['blocked'] !== 0) {
                throw new RuntimeException("The offering builder did not create the complete {$cohortCode} baseline.");
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
                'authority' => 'Client-reported PHP 2,000 downpayment; TAL-96B1 acceptance baseline.',
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

    private function isComplete(): bool
    {
        $academicYear = $this->exactAcademicYear();

        if (! $academicYear instanceof AcademicYear) {
            return false;
        }

        $term = $this->exactTerm($academicYear);

        if (! $term instanceof Term) {
            return false;
        }

        return $this->programsAreComplete()
            && User::query()->count() === 64
            && User::query()->whereNull('email_verified_at')->doesntExist()
            && User::query()->where('email', 'not like', '%@example.test')->doesntExist()
            && StudentProfile::query()->count() === 47
            && Course::query()->count() === 40
            && Course::query()->where('state', Course::StateActive)->count() === 40
            && CourseSpecification::query()->count() === 41
            && CourseSpecification::query()->where('state', CourseSpecification::StateActive)->count() === 41
            && CourseComponent::query()->count() === 41
            && CurriculumVersion::query()->count() === 3
            && CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)->count() === 3
            && CurriculumEntry::query()->count() === 54
            && Room::query()->count() === 6
            && Room::query()->where('is_active', true)->count() === 6
            && FacultyQualification::query()->count() === 40
            && FacultyQualification::query()->where('is_active', true)->count() === 40
            && FacultyTermLoadOverride::query()->count() === 12
            && FacultyTermLoadOverride::query()->where('is_active', true)->count() === 12
            && $this->schedulingWindowIsComplete($term)
            && TermOffering::query()->count() === 54
            && Section::query()->count() === 54
            && SectionDeliveryGroup::query()->where('state', SectionDeliveryGroup::StateReady)->count() === 54
            && SchedulingDemand::query()->count() === 54
            && SchedulingDemand::query()->where('validation_state', SchedulingDemand::ValidationReadyForReview)->count() === 54
            && $this->downpaymentRulesAreComplete($term)
            && $this->courseCatalogIsComplete($term)
            && $this->curriculaAndEntriesAreComplete($term)
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
        if (Term::query()->count() !== 1) {
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
            ->where('scheduling_day_ends_at', '20:00:00')
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

    private function schedulingWindowIsComplete(Term $term): bool
    {
        if (CalendarEvent::query()->count() !== 1) {
            return false;
        }

        return CalendarEvent::query()
            ->whereBelongsTo($term)
            ->where('event_type', CalendarEvent::TypeWindow)
            ->where('scope_type', CalendarEvent::ScopeInstitution)
            ->where('process_key', CalendarEvent::ProcessScheduling)
            ->where('start_at', '2026-01-05 07:00:00')
            ->where('end_at', '2026-05-30 20:00:00')
            ->where('blocks_scheduling', false)
            ->where('state', CalendarEvent::StateActive)
            ->where('authority', 'TAL-96B1 synthetic acceptance baseline')
            ->exists();
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

    private function courseCatalogIsComplete(Term $term): bool
    {
        foreach ($this->courseCatalog() as $code => $definition) {
            $course = Course::query()
                ->where('code', $code)
                ->where('state', Course::StateActive)
                ->first();

            if (! $course instanceof Course) {
                return false;
            }

            if (! $this->courseSpecificationIsComplete($term, $course, 'AY2025-2026', $definition)) {
                return false;
            }
        }

        $nstpCourse = Course::query()->where('code', 'NSTP02')->first();
        $dbmNstpDefinition = $this->courseCatalog()['NSTP02'];
        $dbmNstpDefinition['units'] = 2;

        return $nstpCourse instanceof Course
            && $this->courseSpecificationIsComplete(
                $term,
                $nstpCourse,
                $this->specificationRevisionCode('DBM', 'NSTP02'),
                $dbmNstpDefinition,
            );
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
            ->where('description', 'Synthetic acceptance specification aligned to the supplied curriculum evidence.')
            ->where('credit_units', $definition['units'])
            ->where('grading_profile_key', CourseSpecification::GradingProfileCollegeStandard)
            ->where('grading_profile_version', 1)
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

        $component = CourseComponent::query()
            ->whereBelongsTo($specification)
            ->where('component_type', $definition['room_type'] === Room::TypeLectureRoom
                ? CourseComponent::TypeLecture
                : CourseComponent::TypeLaboratory)
            ->where('weekly_contact_hours', $definition['units'])
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
                ->where('approval_reference', 'TAL-96B1 synthetic acceptance baseline')
                ->where('approved_at', '2025-12-01 08:00:00')
                ->first();

            if (! $curriculum instanceof CurriculumVersion) {
                return false;
            }

            $curricula[$programCode] = $curriculum;
        }

        $sequences = array_fill_keys(array_keys($this->programDefinitions()), 1);

        foreach ($this->cohorts() as $cohort) {
            $curriculum = $curricula[$cohort['program']];

            foreach ($cohort['courses'] as $courseCode) {
                $course = Course::query()->where('code', $courseCode)->first();
                $specification = $course instanceof Course
                    ? CourseSpecification::query()
                        ->whereBelongsTo($course)
                        ->where('revision_code', $this->specificationRevisionCode($cohort['program'], $courseCode))
                        ->first()
                    : null;

                if (! $specification instanceof CourseSpecification
                    || ! CurriculumEntry::query()
                        ->whereBelongsTo($curriculum)
                        ->whereBelongsTo($specification)
                        ->where('year_level', $cohort['year'])
                        ->where('term_label', 'Second Semester')
                        ->where('term_type', Term::TypeSecondSemester)
                        ->where('sequence', $sequences[$cohort['program']]++)
                        ->where('requirement_group', CurriculumEntry::RequirementGroupRequired)
                        ->exists()) {
                    return false;
                }
            }
        }

        return true;
    }

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
                $lastName = sprintf('Student %03d', $globalNumber);
                $user = User::query()->where('email', $email)->first();

                if (! $user instanceof User
                    || $user->first_name !== 'Tala'
                    || $user->last_name !== $lastName
                    || $user->status !== User::StatusActive
                    || $user->roles()->orderBy('name')->pluck('name')->all() !== ['student']
                    || ! $user->hasVerifiedEmail()
                    || ! Hash::check('password', $user->password)
                    || ! StudentProfile::query()
                        ->whereBelongsTo($user)
                        ->whereBelongsTo($program)
                        ->whereBelongsTo($curriculum, 'curriculumVersion')
                        ->where('student_number', sprintf('%s-%03d', $cohortCode, $number))
                        ->where('first_name', 'Tala')
                        ->where('last_name', $lastName)
                        ->where('birth_date', sprintf('2005-%02d-%02d', (($globalNumber - 1) % 12) + 1, (($globalNumber - 1) % 27) + 1))
                        ->where('lifecycle_status', StudentProfile::LifecycleActive)
                        ->where('academic_standing', $this->expectedAcademicStanding(sprintf('%s-%03d', $cohortCode, $number)))
                        ->where('email', $email)
                        ->where('address', 'Synthetic acceptance address')
                        ->where('emergency_contact_name', 'Synthetic Contact')
                        ->whereNull('middle_name')
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

    private function roomsAreComplete(): bool
    {
        foreach ($this->roomDefinitions() as [$code, $name, $type]) {
            if (! Room::query()
                ->where('code', $code)
                ->where('name', $name)
                ->where('building', 'Synthetic Main Building')
                ->where('room_type', $type)
                ->where('capacity', 40)
                ->where('is_active', true)
                ->where('notes', 'Fictional TAL-96B1 acceptance resource.')
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
                'academic_standing' => StudentProfile::StandingCompletionCandidate,
                'purpose' => 'Completion review journey.',
            ],
            'DTHM-2A-001' => [
                'academic_standing' => StudentProfile::StandingGraduationCandidate,
                'purpose' => 'Graduation review journey.',
            ],
            'DTHM-2A-002' => [
                'academic_standing' => StudentProfile::StandingNotYetEvaluated,
                'purpose' => 'Missing progression-baseline journey.',
            ],
        ];
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
        return $programCode === 'DBM' && $courseCode === 'NSTP02'
            ? 'DBM:NSTP02'
            : $courseCode;
    }

    private function specificationRevisionCode(string $programCode, string $courseCode): string
    {
        return $programCode === 'DBM' && $courseCode === 'NSTP02'
            ? 'AY2025-2026-DBM'
            : 'AY2025-2026';
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
        return [
            ['LEC-101', 'Lecture Room 101', Room::TypeLectureRoom],
            ['LEC-102', 'Lecture Room 102', Room::TypeLectureRoom],
            ['LEC-103', 'Lecture Room 103', Room::TypeLectureRoom],
            ['LAB-101', 'Applied Skills Laboratory', Room::TypeLaboratory],
            ['COMP-101', 'Computer Laboratory', Room::TypeComputerLaboratory],
            ['SPEC-101', 'Specialized Demonstration Room', Room::TypeSpecialRoom],
        ];
    }

    /**
     * @return array<string, array{program:string,year:string,students:int,courses:list<string>}>
     */
    private function cohorts(): array
    {
        return [
            'DBM-1A' => [
                'program' => 'DBM', 'year' => 'First Year', 'students' => 10,
                'courses' => ['GE04', 'GE05', 'BME05', 'BME04', 'CSNCII', 'GE06', 'PE02', 'FOSNCII', 'NSTP02', 'BME06'],
            ],
            'DBM-2A' => [
                'program' => 'DBM', 'year' => 'Second Year', 'students' => 2,
                'courses' => ['AGRONCIII', 'GE10', 'GE09', 'BME09', 'BME10', 'BME11', 'BME12', 'BME13', 'PE04'],
            ],
            'DIT-1A' => [
                'program' => 'DIT', 'year' => 'First Year', 'students' => 10,
                'courses' => ['GE04', 'GE05', 'GE06', 'CC102', 'PHY101', 'CC103', 'NSTP02', 'PE02'],
            ],
            'DIT-2A' => [
                'program' => 'DIT', 'year' => 'Second Year', 'students' => 3,
                'courses' => ['TECH001', 'NET102', 'VGDNCIII', 'IAS101', 'DM101', 'PE04', 'HCI101', 'IAS102'],
            ],
            'DTHM-1A' => [
                'program' => 'DTHM', 'year' => 'First Year', 'students' => 15,
                'courses' => ['HSKPNCII', 'THC05', 'THC04', 'GE04', 'GE05', 'GE06', 'THC03', 'HPC07', 'PE02', 'NSTP02'],
            ],
            'DTHM-2A' => [
                'program' => 'DTHM', 'year' => 'Second Year', 'students' => 7,
                'courses' => ['THC07', 'HPC11EMS', 'THC08', 'BME01', 'HPC13EMS', 'GE10', 'GE09', 'PE04', 'THC06'],
            ],
        ];
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
        ];
    }
}
