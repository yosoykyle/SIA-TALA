<?php

namespace Database\Seeders;

use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Activitylog\ActivityLogStatus;
use Spatie\Permission\Models\Role;

/**
 * Seeds realistic SERVITECH INSTITUTE ASIA INC. 3-Year Diploma mock data (DHM, DBM, DIT)
 * normalized to Philippine CHED + TESDA PQF Level 5 standards.
 *
 * Idempotent: safely creates or reuses AY 2026-2027 / First Semester (with its pre-solved
 * reference timetable) and Second Semester (ready-to-generate with 0 solver runs),
 * programs, PH-standard course revisions, full 3-year curricula, prerequisites, rooms,
 * faculty, availability, confirmed student sections, class offerings (including cross-program
 * merged GE/Nihongo classes and mixed Face-to-Face + Online modalities), and synchronized
 * scheduling demands.
 */
final class ServitechPhStandardMockSeeder extends Seeder
{
    public const AcademicYearLabel = 'AY 2026-2027';

    public const TermLabel = 'First Semester';

    public const SecondTermLabel = 'Second Semester';

    public const RevisionCode = 'PH-STD-2026';

    public const CurriculumVersionSuffix = 'PH-STD-2026';

    public const DefaultPassword = 'password';

    public function run(): void
    {
        $logStatus = app(ActivityLogStatus::class);
        $wasDisabled = $logStatus->disabled();
        $logStatus->disable();

        try {
            $summary = DB::transaction(function (): array {
                $registrar = $this->resolveRegistrar();
                [$academicYear, $term, $calendarPackage] = $this->ensureAcademicYearTermAndCalendar($registrar);
                [$secondTerm, $secondCalendarPackage] = $this->ensureSecondSemesterTermAndCalendar($academicYear, $registrar);
                $programs = $this->ensurePrograms();
                [$courses, $specifications] = $this->ensureCourseCatalog();
                $prerequisiteCount = $this->ensurePrerequisites($courses, $specifications);
                $curricula = $this->ensureCurricula($academicYear, $term, $registrar, $programs, $specifications);
                $rooms = $this->ensureRooms();
                $faculty = $this->ensureFacultyAndQualifications([$term, $secondTerm], $registrar, $courses);

                $firstCohorts = $this->ensureTermCohorts($term, $registrar, $programs, $curricula, '2026-07-15 10:00:00');
                $firstClasses = $this->ensureClassesForTerm(
                    $term,
                    $calendarPackage,
                    $registrar,
                    $curricula,
                    $specifications,
                    $firstCohorts,
                    Term::TypeFirstSemester,
                    $this->firstSemesterClassDefinitions(),
                );

                $secondCohorts = $this->ensureTermCohorts($secondTerm, $registrar, $programs, $curricula, '2026-11-20 10:00:00');
                $secondClasses = $this->ensureClassesForTerm(
                    $secondTerm,
                    $secondCalendarPackage,
                    $registrar,
                    $curricula,
                    $specifications,
                    $secondCohorts,
                    Term::TypeSecondSemester,
                    $this->secondSemesterClassDefinitions(),
                );

                return [
                    'registrar' => $registrar,
                    'academic_year' => $academicYear,
                    'term' => $term,
                    'second_term' => $secondTerm,
                    'programs_count' => count($programs),
                    'courses_count' => count($courses),
                    'prerequisites_count' => $prerequisiteCount,
                    'rooms_count' => count($rooms),
                    'faculty_count' => count($faculty),
                    'first_cohorts_count' => count($firstCohorts),
                    'first_classes_count' => count($firstClasses),
                    'second_cohorts_count' => count($secondCohorts),
                    'second_classes_count' => count($secondClasses),
                ];
            }, 3);

            /** @var User $registrar */
            $registrar = $summary['registrar'];
            /** @var Term $term */
            $term = $summary['term'];
            /** @var Term $secondTerm */
            $secondTerm = $summary['second_term'];

            $demandSummary = $this->syncAndVerifyTermReadiness($registrar, $term, 'First Semester');
            $secondDemandSummary = $this->syncAndVerifyTermReadiness($registrar, $secondTerm, 'Second Semester');

            $secondRunCount = ScheduleGenerationRun::query()->where('term_id', $secondTerm->id)->count();
            if ($secondRunCount !== 0) {
                throw new RuntimeException(
                    "Second Semester (Term #{$secondTerm->id}) is expected to have 0 solver runs so the user can generate one, found {$secondRunCount}.",
                );
            }

            if ($this->command !== null) {
                $this->command->info(sprintf(
                    'Seeded SERVITECH PH Standard mock dataset: Term #%d (%s · %s) | Programs=%d, Courses=%d, Prerequisites=%d, Rooms=%d, Faculty=%d, Sections=%d, Classes=%d, ReadyDemands=%d',
                    $term->id,
                    self::AcademicYearLabel,
                    self::TermLabel,
                    $summary['programs_count'],
                    $summary['courses_count'],
                    $summary['prerequisites_count'],
                    $summary['rooms_count'],
                    $summary['faculty_count'],
                    $summary['first_cohorts_count'],
                    $summary['first_classes_count'],
                    $demandSummary['ready'],
                ));
                $this->command->info(sprintf(
                    'Seeded ready-to-generate term: Term #%d (%s · %s) | Sections=%d, Classes=%d, ReadyDemands=%d, SolverRuns=%d',
                    $secondTerm->id,
                    self::AcademicYearLabel,
                    self::SecondTermLabel,
                    $summary['second_cohorts_count'],
                    $summary['second_classes_count'],
                    $secondDemandSummary['ready'],
                    $secondRunCount,
                ));
            }
        } finally {
            if (! $wasDisabled) {
                $logStatus->enable();
            }
        }
    }

    /**
     * @return array{created:int,updated:int,skipped:int,total:int,ready:int,action_required:int,findings:int}
     */
    private function syncAndVerifyTermReadiness(User $registrar, Term $term, string $label): array
    {
        $demandSummary = app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        if ($demandSummary['action_required'] !== 0 || $demandSummary['ready'] === 0) {
            throw new RuntimeException(
                "SERVITECH PH Standard {$label} demands are not all ready: ".json_encode($demandSummary, JSON_THROW_ON_ERROR),
            );
        }

        $readiness = app(ReadyTermPlanningProjection::class)->forTerm($term);
        if (! $readiness['ready']) {
            throw new RuntimeException(
                "SERVITECH PH Standard {$label} failed scheduling readiness: ".json_encode($readiness['blockers'], JSON_THROW_ON_ERROR),
            );
        }

        return $demandSummary;
    }

    private function resolveRegistrar(): User
    {
        $existing = User::query()
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleRegistrar))
            ->orderBy('id')
            ->first();

        if ($existing instanceof User) {
            return $existing;
        }

        Role::findOrCreate(User::StaffRoleRegistrar, 'web');

        $registrar = User::query()->firstOrCreate(
            ['email' => 'registrar.servitech@servitech.test'],
            [
                'name' => 'Maribel Dizon',
                'first_name' => 'Maribel',
                'last_name' => 'Dizon',
                'password' => Hash::make(self::DefaultPassword),
                'status' => User::StatusActive,
                'email_verified_at' => now(),
            ],
        );

        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            $registrar->assignRole(User::StaffRoleRegistrar);
        }

        return $registrar;
    }

    /**
     * @return array{0: AcademicYear, 1: Term, 2: TermCalendarPackage}
     */
    private function ensureAcademicYearTermAndCalendar(User $registrar): array
    {
        $academicYear = AcademicYear::query()->firstOrCreate(
            ['label' => self::AcademicYearLabel],
            [
                'starts_on' => '2026-06-01',
                'ends_on' => '2027-05-31',
                'state' => AcademicYear::StateActive,
            ],
        );

        if ($academicYear->state !== AcademicYear::StateActive) {
            $academicYear->update(['state' => AcademicYear::StateActive]);
        }

        $term = Term::query()->firstOrCreate(
            [
                'academic_year_id' => $academicYear->id,
                'type' => Term::TypeFirstSemester,
                'label' => self::TermLabel,
            ],
            [
                'starts_on' => '2026-08-17',
                'ends_on' => '2026-12-19',
                'state' => Term::StateActive,
                'scheduling_slot_minutes' => 30,
                'scheduling_days' => [1, 2, 3, 4, 5, 6],
                'scheduling_day_starts_at' => '07:30:00',
                'scheduling_day_ends_at' => '19:00:00',
                'default_max_units' => 24,
            ],
        );

        $term->forceFill([
            'state' => Term::StateActive,
            'scheduling_slot_minutes' => 30,
            'scheduling_days' => [1, 2, 3, 4, 5, 6],
            'scheduling_day_starts_at' => '07:30:00',
            'scheduling_day_ends_at' => '19:00:00',
            'default_max_units' => 24,
        ])->save();

        $package = TermCalendarPackage::query()->firstOrCreate(
            [
                'term_id' => $term->id,
                'version' => 1,
            ],
            [
                'state' => TermCalendarPackage::StateActive,
                'administrative_starts_on' => '2026-08-10',
                'administrative_ends_on' => '2026-12-22',
                'classes_start_on' => '2026-08-17',
                'classes_end_on' => '2026-12-19',
                'authority_reference' => 'SERVITECH-BOT-2026-CAL-01',
                'authority_date' => '2026-06-15',
                'recorded_by' => $registrar->id,
                'activated_at' => '2026-07-01 08:00:00',
            ],
        );

        if ($package->state !== TermCalendarPackage::StateActive) {
            $package->forceFill([
                'state' => TermCalendarPackage::StateActive,
                'activated_at' => $package->activated_at ?? '2026-07-01 08:00:00',
            ])->save();
        }

        foreach ([1, 2, 3, 4, 5, 6] as $dayOfWeek) {
            $package->teachingGridRows()->updateOrCreate(
                ['day_of_week' => $dayOfWeek],
                [
                    'starts_at' => '07:30:00',
                    'ends_at' => '19:00:00',
                    'breaks' => [
                        [
                            'starts_at' => '12:00',
                            'ends_at' => '13:00',
                        ],
                    ],
                ],
            );
        }

        $package->load('teachingGridRows');

        return [$academicYear, $term, $package];
    }

    /**
     * @return array{0: Term, 1: TermCalendarPackage}
     */
    private function ensureSecondSemesterTermAndCalendar(AcademicYear $academicYear, User $registrar): array
    {
        $term = Term::query()->firstOrCreate(
            [
                'academic_year_id' => $academicYear->id,
                'type' => Term::TypeSecondSemester,
                'label' => self::SecondTermLabel,
            ],
            [
                'starts_on' => '2027-01-11',
                'ends_on' => '2027-05-22',
                'state' => Term::StateActive,
                'scheduling_slot_minutes' => 30,
                'scheduling_days' => [1, 2, 3, 4, 5, 6],
                'scheduling_day_starts_at' => '07:30:00',
                'scheduling_day_ends_at' => '19:00:00',
                'default_max_units' => 24,
            ],
        );

        $term->forceFill([
            'state' => Term::StateActive,
            'scheduling_slot_minutes' => 30,
            'scheduling_days' => [1, 2, 3, 4, 5, 6],
            'scheduling_day_starts_at' => '07:30:00',
            'scheduling_day_ends_at' => '19:00:00',
            'default_max_units' => 24,
        ])->save();

        $package = TermCalendarPackage::query()->firstOrCreate(
            [
                'term_id' => $term->id,
                'version' => 1,
            ],
            [
                'state' => TermCalendarPackage::StateActive,
                'administrative_starts_on' => '2027-01-04',
                'administrative_ends_on' => '2027-05-25',
                'classes_start_on' => '2027-01-11',
                'classes_end_on' => '2027-05-22',
                'authority_reference' => 'SERVITECH-BOT-2026-CAL-02',
                'authority_date' => '2026-11-15',
                'recorded_by' => $registrar->id,
                'activated_at' => '2026-11-20 08:00:00',
            ],
        );

        if ($package->state !== TermCalendarPackage::StateActive) {
            $package->forceFill([
                'state' => TermCalendarPackage::StateActive,
                'activated_at' => $package->activated_at ?? '2026-11-20 08:00:00',
            ])->save();
        }

        foreach ([1, 2, 3, 4, 5, 6] as $dayOfWeek) {
            $package->teachingGridRows()->updateOrCreate(
                ['day_of_week' => $dayOfWeek],
                [
                    'starts_at' => '07:30:00',
                    'ends_at' => '19:00:00',
                    'breaks' => [
                        [
                            'starts_at' => '12:00',
                            'ends_at' => '13:00',
                        ],
                    ],
                ],
            );
        }

        $package->load('teachingGridRows');

        return [$term, $package];
    }

    /**
     * @return array<string, Program>
     */
    private function ensurePrograms(): array
    {
        $definitions = [
            'DHM' => [
                'name' => '3-Year Diploma in Hospitality Management',
                'description' => 'PQF Level 5 Diploma in Hospitality Management ladderized toward BSHM, aligned with CHED CMO 62 s.2017 and TESDA Hospitality NC II/III standards.',
            ],
            'DBM' => [
                'name' => '3-Year Diploma in Business Management',
                'description' => 'PQF Level 5 Diploma in Business Management ladderized toward BSBA, aligned with CHED CMO 17 s.2017 and TESDA Business/Bookkeeping/Agro-Entrepreneurship NC II/III standards.',
            ],
            'DIT' => [
                'name' => '3-Year Diploma in Information Technology',
                'description' => 'PQF Level 5 Diploma in Information Technology ladderized toward BSIT, aligned with CHED CMO 25 s.2015 and TESDA ICT NC II/III standards.',
            ],
        ];

        $programs = [];
        foreach ($definitions as $code => $meta) {
            $program = Program::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                    'duration_years' => 3,
                    'is_active' => true,
                ],
            );

            $program->forceFill([
                'name' => $meta['name'],
                'description' => $meta['description'],
                'duration_years' => 3,
                'is_active' => true,
            ])->save();

            $programs[$code] = $program;
        }

        return $programs;
    }

    /**
     * @return array{0: array<string, Course>, 1: array<string, CourseSpecification>}
     */
    private function ensureCourseCatalog(): array
    {
        $courses = [];
        $specifications = [];

        foreach ($this->courseCatalogDefinitions() as $code => $def) {
            $course = Course::query()->firstOrCreate(
                ['code' => $code],
                ['state' => Course::StateActive],
            );

            if ($course->state !== Course::StateActive) {
                $course->update(['state' => Course::StateActive]);
            }

            $treatment = $def['treatment'] ?? CourseSpecification::SchedulingRecurring;
            $classification = $def['classification'] ?? CourseSpecification::AcademicClassificationOrdinary;

            $spec = CourseSpecification::query()->updateOrCreate(
                [
                    'course_id' => $course->id,
                    'revision_code' => self::RevisionCode,
                ],
                [
                    'title' => $def['title'],
                    'description' => $def['description'] ?? 'Normalized Philippine CHED/TESDA PQF Level 5 course specification for SERVITECH INSTITUTE ASIA INC.',
                    'credit_units' => $def['units'],
                    'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                    'grading_profile_version' => 1,
                    'academic_classification' => $classification,
                    'scheduling_treatment' => $treatment,
                    'allowed_modalities' => $def['allowed_modalities'] ?? [
                        CourseSpecification::ModalityFaceToFace,
                        CourseSpecification::ModalityOnline,
                    ],
                    'same_faculty_default' => true,
                    'state' => CourseSpecification::StateActive,
                ],
            );

            if ($treatment === CourseSpecification::SchedulingRecurring) {
                $components = $this->componentsForCourseDefinition($def);
                $keptIds = [];

                foreach ($components as $index => $componentDef) {
                    $component = CourseComponent::query()->updateOrCreate(
                        [
                            'course_specification_id' => $spec->id,
                            'sequence' => $index + 1,
                        ],
                        [
                            'component_type' => $componentDef['component_type'],
                            'weekly_contact_hours' => $componentDef['weekly_contact_hours'],
                            'meeting_pattern' => $componentDef['meeting_pattern'],
                            'room_type_default' => $componentDef['room_type_default'],
                            'required_room_feature_keys' => [],
                            'modality_restriction' => null,
                            'requires_consecutive_block' => $componentDef['requires_consecutive_block'],
                            'same_faculty' => true,
                        ],
                    );

                    $keptIds[] = $component->id;
                }

                CourseComponent::query()
                    ->where('course_specification_id', $spec->id)
                    ->whereNotIn('id', $keptIds)
                    ->delete();
            }

            $courses[$code] = $course;
            $specifications[$code] = $spec;
        }

        return [$courses, $specifications];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<array{
     *     component_type: string,
     *     weekly_contact_hours: float,
     *     meeting_pattern: string,
     *     room_type_default: string,
     *     requires_consecutive_block: bool
     * }>
     */
    private function componentsForCourseDefinition(array $def): array
    {
        $kind = $def['kind'] ?? 'lec3';

        return match ($kind) {
            'lec3' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3.0,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => Room::TypeLectureRoom,
                    'requires_consecutive_block' => false,
                ],
            ],
            'lec2_lab1_comp' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 2.0,
                    'meeting_pattern' => '1x120',
                    'room_type_default' => Room::TypeLectureRoom,
                    'requires_consecutive_block' => false,
                ],
                [
                    'component_type' => CourseComponent::TypeLaboratory,
                    'weekly_contact_hours' => 3.0,
                    'meeting_pattern' => '1x180',
                    'room_type_default' => Room::TypeComputerLaboratory,
                    'requires_consecutive_block' => true,
                ],
            ],
            'lec2_lab1_hosp' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 2.0,
                    'meeting_pattern' => '1x120',
                    'room_type_default' => Room::TypeLectureRoom,
                    'requires_consecutive_block' => false,
                ],
                [
                    'component_type' => CourseComponent::TypeLaboratory,
                    'weekly_contact_hours' => 3.0,
                    'meeting_pattern' => '1x180',
                    'room_type_default' => Room::TypeLaboratory,
                    'requires_consecutive_block' => true,
                ],
            ],
            'pe2' => [
                [
                    'component_type' => CourseComponent::TypeLaboratory,
                    'weekly_contact_hours' => 2.0,
                    'meeting_pattern' => '1x120',
                    'room_type_default' => Room::TypeSpecialRoom,
                    'requires_consecutive_block' => true,
                ],
            ],
            'nstp3' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3.0,
                    'meeting_pattern' => '1x180',
                    'room_type_default' => Room::TypeLectureRoom,
                    'requires_consecutive_block' => true,
                ],
            ],
            default => throw new RuntimeException("Unsupported course component kind [{$kind}]."),
        };
    }

    /**
     * @param  array<string, Course>  $courses
     * @param  array<string, CourseSpecification>  $specifications
     */
    private function ensurePrerequisites(array $courses, array $specifications): int
    {
        $count = 0;

        foreach ($this->prerequisiteMap() as $courseCode => $requiredCodes) {
            $spec = $specifications[$courseCode] ?? null;
            if (! $spec instanceof CourseSpecification) {
                throw new RuntimeException("Missing course specification for prerequisite owner [{$courseCode}].");
            }

            foreach ($requiredCodes as $index => $prereqCode) {
                if ($prereqCode === $courseCode) {
                    throw new RuntimeException("Self-prerequisite detected on [{$courseCode}].");
                }

                $relatedCourse = $courses[$prereqCode] ?? null;
                if (! $relatedCourse instanceof Course) {
                    throw new RuntimeException("Missing prerequisite course [{$prereqCode}] for [{$courseCode}].");
                }

                CourseRequirement::query()->updateOrCreate(
                    [
                        'course_specification_id' => $spec->id,
                        'rule_type' => CourseRequirement::TypePrerequisite,
                        'group_key' => 'ALL',
                        'related_course_id' => $relatedCourse->id,
                    ],
                    [
                        'state' => CourseRequirement::StateActive,
                        'sequence' => $index + 1,
                    ],
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, CourseSpecification>  $specifications
     * @return array<string, CurriculumVersion>
     */
    private function ensureCurricula(
        AcademicYear $academicYear,
        Term $term,
        User $registrar,
        array $programs,
        array $specifications,
    ): array {
        $curricula = [];

        foreach ($this->curriculumPlans() as $programCode => $termsByPeriod) {
            $program = $programs[$programCode];
            $curriculum = CurriculumVersion::query()->updateOrCreate(
                [
                    'program_id' => $program->id,
                    'version_code' => $programCode.'-'.self::CurriculumVersionSuffix,
                ],
                [
                    'name' => $programCode.' PH Standard Curriculum (AY 2026-2027)',
                    'effective_entry_term_id' => $term->id,
                    'state' => CurriculumVersion::StateActive,
                    'approved_by' => $registrar->id,
                    'approved_at' => '2026-06-20 09:00:00',
                ],
            );

            CurriculumVersionBatch::query()->firstOrCreate(
                [
                    'program_id' => $program->id,
                    'curriculum_version_id' => $curriculum->id,
                    'academic_year_id' => $academicYear->id,
                ],
                [
                    'added_by' => $registrar->id,
                ],
            );

            $sequence = 1;
            foreach ($termsByPeriod as $period) {
                $semesterUnits = 0.0;
                foreach ($period['courses'] as $courseCode) {
                    $spec = $specifications[$courseCode] ?? null;
                    if (! $spec instanceof CourseSpecification) {
                        throw new RuntimeException("Missing course specification [{$courseCode}] for {$programCode}.");
                    }

                    $semesterUnits += (float) $spec->credit_units;

                    CurriculumEntry::query()->updateOrCreate(
                        [
                            'curriculum_version_id' => $curriculum->id,
                            'year_level' => $period['year_level'],
                            'term_label' => $period['term_label'],
                            'course_specification_id' => $spec->id,
                        ],
                        [
                            'term_type' => $period['term_type'],
                            'sequence' => $sequence++,
                            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
                        ],
                    );
                }

                if ($period['term_type'] !== Term::TypeSpecialTerm && $semesterUnits > 24.0) {
                    throw new RuntimeException(
                        "Semester unit cap violated for {$programCode} {$period['year_level']} {$period['term_label']}: {$semesterUnits} units.",
                    );
                }
            }

            $curricula[$programCode] = $curriculum;
        }

        return $curricula;
    }

    /**
     * @return list<Room>
     */
    private function ensureRooms(): array
    {
        $definitions = [
            // 10 Lecture Rooms (45 seats)
            ['LEC-101', 'Lecture Room 101', 'Main Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-102', 'Lecture Room 102', 'Main Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-103', 'Lecture Room 103', 'Main Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-104', 'Lecture Room 104', 'Main Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-105', 'Lecture Room 105', 'Main Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-106', 'Lecture Room 106', 'Main Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-107', 'Lecture Room 107', 'Annex Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-108', 'Lecture Room 108', 'Annex Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-109', 'Lecture Room 109', 'Annex Academic Building', Room::TypeLectureRoom, 45],
            ['LEC-110', 'Lecture Room 110', 'Annex Academic Building', Room::TypeLectureRoom, 45],
            // 6 Computer Laboratories (40 seats)
            ['CLAB-201', 'Computer Laboratory 201 (Programming)', 'ICT Building', Room::TypeComputerLaboratory, 40],
            ['CLAB-202', 'Computer Laboratory 202 (Networking & Systems)', 'ICT Building', Room::TypeComputerLaboratory, 40],
            ['CLAB-203', 'Computer Laboratory 203 (Web & Multimedia)', 'ICT Building', Room::TypeComputerLaboratory, 40],
            ['CLAB-204', 'Computer Laboratory 204 (Database & App Dev)', 'ICT Building', Room::TypeComputerLaboratory, 40],
            ['CLAB-205', 'Computer Laboratory 205 (Contact Center & BPO)', 'ICT Building', Room::TypeComputerLaboratory, 40],
            ['CLAB-206', 'Computer Laboratory 206 (Bookkeeping & ERP)', 'ICT Building', Room::TypeComputerLaboratory, 40],
            // 4 Specialized / Hospitality Laboratories (40 seats)
            ['HLAB-101', 'Culinary Kitchen Laboratory', 'Hospitality Wing', Room::TypeLaboratory, 40],
            ['HLAB-102', 'Food & Beverage Service Laboratory', 'Hospitality Wing', Room::TypeLaboratory, 40],
            ['HLAB-103', 'Housekeeping & Front Office Mock Suite', 'Hospitality Wing', Room::TypeLaboratory, 40],
            ['HLAB-104', 'Agro-Entrepreneurship & Operations Demo Lab', 'Hospitality Wing', Room::TypeLaboratory, 40],
            // 2 Special Rooms / PE Courts (50 seats)
            ['PE-COURT-1', 'Covered Sports Court A', 'Student Activity Center', Room::TypeSpecialRoom, 50],
            ['PE-COURT-2', 'Multipurpose Activity Hall B', 'Student Activity Center', Room::TypeSpecialRoom, 50],
        ];

        $rooms = [];
        foreach ($definitions as [$code, $name, $building, $type, $capacity]) {
            $rooms[] = Room::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'building' => $building,
                    'room_type' => $type,
                    'capacity' => $capacity,
                    'is_active' => true,
                    'notes' => 'SERVITECH PH Standard campus teaching facility.',
                ],
            );
        }

        return $rooms;
    }

    /**
     * @param  list<Term>  $terms
     * @param  array<string, Course>  $courses
     * @return array<string, User>
     */
    private function ensureFacultyAndQualifications(array $terms, User $registrar, array $courses): array
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');

        $availabilityRecorder = app(RecordFacultyAvailabilityDeclaration::class);
        $facultyByEmail = [];

        foreach ($this->facultyRosterDefinitions() as $entry) {
            $user = User::query()->firstOrCreate(
                ['email' => $entry['email']],
                [
                    'name' => $entry['first_name'].' '.$entry['last_name'],
                    'first_name' => $entry['first_name'],
                    'last_name' => $entry['last_name'],
                    'password' => Hash::make(self::DefaultPassword),
                    'status' => User::StatusActive,
                    'email_verified_at' => now(),
                ],
            );

            $user->forceFill([
                'name' => $entry['first_name'].' '.$entry['last_name'],
                'first_name' => $entry['first_name'],
                'last_name' => $entry['last_name'],
                'status' => User::StatusActive,
            ])->save();

            if (! $user->hasRole(User::StaffRoleFaculty)) {
                $user->assignRole(User::StaffRoleFaculty);
            }

            foreach ($entry['courses'] as $courseCode) {
                $course = $courses[$courseCode] ?? null;
                if (! $course instanceof Course) {
                    throw new RuntimeException("Unknown course [{$courseCode}] for faculty [{$entry['email']}].");
                }

                FacultyQualification::query()->updateOrCreate(
                    [
                        'faculty_user_id' => $user->id,
                        'course_id' => $course->id,
                    ],
                    [
                        'is_active' => true,
                        'recorded_by' => $registrar->id,
                        'recorded_at' => '2026-07-05 09:00:00',
                        'notes' => 'Verified SERVITECH PH Standard subject qualification.',
                    ],
                );
            }

            foreach ($terms as $term) {
                $hasDeclaration = FacultyAvailabilityDeclaration::query()
                    ->where('term_id', $term->id)
                    ->where('faculty_user_id', $user->id)
                    ->exists();

                if (! $hasDeclaration) {
                    $availabilityRecorder->execute(
                        term: $term,
                        faculty: $user,
                        actor: $user,
                        availabilityMode: $entry['availability_mode'],
                        teachingWindows: $entry['teaching_windows'] ?? [],
                    );
                }
            }

            $facultyByEmail[$entry['email']] = $user;
        }

        return $facultyByEmail;
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, CurriculumVersion>  $curricula
     * @return array<string, TermCohort>
     */
    private function ensureTermCohorts(
        Term $term,
        User $registrar,
        array $programs,
        array $curricula,
        string $confirmedAt,
    ): array {
        $definitions = [
            'DHM 1-1' => ['program' => 'DHM', 'year_level' => 1, 'count' => 20],
            'DHM 2-1' => ['program' => 'DHM', 'year_level' => 2, 'count' => 22],
            'DHM 3-1' => ['program' => 'DHM', 'year_level' => 3, 'count' => 20],
            'DBM 1-1' => ['program' => 'DBM', 'year_level' => 1, 'count' => 20],
            'DBM 2-1' => ['program' => 'DBM', 'year_level' => 2, 'count' => 22],
            'DBM 3-1' => ['program' => 'DBM', 'year_level' => 3, 'count' => 20],
            'DIT 1-1' => ['program' => 'DIT', 'year_level' => 1, 'count' => 25],
            'DIT 2-1' => ['program' => 'DIT', 'year_level' => 2, 'count' => 26],
            'DIT 3-1' => ['program' => 'DIT', 'year_level' => 3, 'count' => 24],
        ];

        $cohorts = [];
        foreach ($definitions as $reference => $meta) {
            $program = $programs[$meta['program']];
            $curriculum = $curricula[$meta['program']];

            $cohorts[$reference] = TermCohort::query()->updateOrCreate(
                [
                    'term_id' => $term->id,
                    'reference' => $reference,
                ],
                [
                    'program_id' => $program->id,
                    'curriculum_version_id' => $curriculum->id,
                    'year_level' => $meta['year_level'],
                    'source' => 'Confirmed',
                    'forecast_count' => $meta['count'],
                    'confirmed_count' => $meta['count'],
                    'state' => 'Confirmed',
                    'confirmed_by' => $registrar->id,
                    'confirmed_at' => $confirmedAt,
                ],
            );
        }

        return $cohorts;
    }

    /**
     * @param  array<string, CurriculumVersion>  $curricula
     * @param  array<string, CourseSpecification>  $specifications
     * @param  array<string, TermCohort>  $cohorts
     * @param  list<array{
     *     code: string,
     *     owner_program: string,
     *     year_level: string,
     *     course: string,
     *     cohorts: list<string>,
     *     group_name: string,
     *     modality: string,
     *     capacity?: int
     * }>  $classDefinitions
     * @return list<Section>
     */
    private function ensureClassesForTerm(
        Term $term,
        TermCalendarPackage $calendarPackage,
        User $registrar,
        array $curricula,
        array $specifications,
        array $cohorts,
        string $termType,
        array $classDefinitions,
    ): array {
        $confirmer = app(ConfirmClassOffering::class);
        $entriesByKey = CurriculumEntry::query()
            ->whereIn('curriculum_version_id', collect($curricula)->pluck('id')->all())
            ->where('term_type', $termType)
            ->get()
            ->keyBy(fn (CurriculumEntry $entry): string => $entry->curriculum_version_id.':'.$entry->year_level.':'.$entry->course_specification_id);

        $createdSections = [];

        foreach ($classDefinitions as $classDef) {
            $ownerProgram = $classDef['owner_program'];
            $curriculum = $curricula[$ownerProgram];
            $spec = $specifications[$classDef['course']];
            $entryKey = $curriculum->id.':'.$classDef['year_level'].':'.$spec->id;
            $entry = $entriesByKey->get($entryKey);

            if (! $entry instanceof CurriculumEntry) {
                throw new RuntimeException(
                    "Missing {$termType} curriculum entry for {$ownerProgram} {$classDef['year_level']} {$classDef['course']}.",
                );
            }

            $cohortCounts = [];
            foreach ($classDef['cohorts'] as $cohortRef) {
                $cohort = $cohorts[$cohortRef];
                $cohortCounts[(int) $cohort->id] = (int) $cohort->confirmed_count;
            }

            $totalExpected = array_sum($cohortCounts);
            $capacity = $classDef['capacity'] ?? ($totalExpected > 40 ? 45 : 40);
            $modality = $classDef['modality'];

            $offering = TermOffering::query()->firstOrCreate(
                [
                    'term_id' => $term->id,
                    'curriculum_entry_id' => $entry->id,
                    'delivery_variant' => TermOffering::ArrangementNormalClass,
                ],
                [
                    'category' => TermOffering::CategoryRegular,
                    'modality' => $modality,
                    'expected_count' => $totalExpected,
                    'state' => TermOffering::StatePendingScheduling,
                ],
            );

            $offering->forceFill([
                'modality' => $modality,
                'expected_count' => $totalExpected,
                'state' => TermOffering::StatePendingScheduling,
            ])->save();

            $section = Section::query()->firstOrCreate(
                [
                    'term_offering_id' => $offering->id,
                    'code' => $classDef['code'],
                ],
                [
                    'term_calendar_package_id' => $calendarPackage->id,
                    'course_specification_id' => $spec->id,
                    'class_reference' => $classDef['code'],
                    'source' => Section::SourceRegular,
                    'delivery_mode' => $modality,
                    'capacity' => $capacity,
                    'state' => Section::StateOpen,
                ],
            );

            $section->forceFill([
                'term_calendar_package_id' => $calendarPackage->id,
                'course_specification_id' => $spec->id,
                'class_reference' => $classDef['code'],
                'source' => Section::SourceRegular,
                'delivery_mode' => $modality,
                'capacity' => $capacity,
                'state' => Section::StateOpen,
            ])->save();

            SectionDeliveryGroup::query()->updateOrCreate(
                [
                    'section_id' => $section->id,
                    'name' => $classDef['group_name'],
                ],
                [
                    'expected_count' => $totalExpected,
                    'modality' => $modality,
                    'state' => SectionDeliveryGroup::StateReady,
                ],
            );

            if ($section->confirmed_at === null) {
                $section = $confirmer->execute($section, $registrar, $cohortCounts);
            }

            $createdSections[] = $section;
        }

        return $createdSections;
    }

    /**
     * Normalized PH CHED + TESDA PQF Level 5 course catalog across DHM, DBM, and DIT.
     *
     * @return array<string, array{
     *     title: string,
     *     units: float,
     *     kind?: string,
     *     classification?: string,
     *     treatment?: string,
     *     allowed_modalities?: list<string>,
     *     description?: string
     * }>
     */
    private function courseCatalogDefinitions(): array
    {
        $f2fOnly = [CourseSpecification::ModalityFaceToFace];

        return [
            // --- Mandated General Education (CMO 20 s.2013: 3u = 3h/wk) ---
            'GE001' => ['title' => 'Understanding the Self', 'units' => 3.0, 'kind' => 'lec3'],
            'GE002' => ['title' => 'Readings in Philippine History', 'units' => 3.0, 'kind' => 'lec3'],
            'GE003' => ['title' => 'The Contemporary World', 'units' => 3.0, 'kind' => 'lec3'],
            'GE004' => ['title' => 'Mathematics in the Modern World', 'units' => 3.0, 'kind' => 'lec3'],
            'GE005' => ['title' => 'Purposive Communication', 'units' => 3.0, 'kind' => 'lec3'],
            'GE006' => ['title' => 'Art Appreciation', 'units' => 3.0, 'kind' => 'lec3'],
            'GE007' => ['title' => 'Science, Technology, and Society', 'units' => 3.0, 'kind' => 'lec3'],
            'GE008' => ['title' => 'Ethics', 'units' => 3.0, 'kind' => 'lec3'],
            'GE009' => ['title' => 'The Life and Works of Jose Rizal', 'units' => 3.0, 'kind' => 'lec3'],
            'GE010' => ['title' => 'Philippine Popular Culture', 'units' => 3.0, 'kind' => 'lec3'],

            // --- Mandated PE (CMO 39 s.2021 PATHFIT: 2u = 2h/wk, 1x120) ---
            'PATHFIT1' => [
                'title' => 'PATHFIT 1: Movement Competency Training',
                'units' => 2.0,
                'kind' => 'pe2',
                'classification' => CourseSpecification::AcademicClassificationPe,
                'allowed_modalities' => $f2fOnly,
            ],
            'PATHFIT2' => [
                'title' => 'PATHFIT 2: Exercise-Based Fitness Activities',
                'units' => 2.0,
                'kind' => 'pe2',
                'classification' => CourseSpecification::AcademicClassificationPe,
                'allowed_modalities' => $f2fOnly,
            ],
            'PATHFIT3' => [
                'title' => 'PATHFIT 3: Dance, Sports, and Group Exercise',
                'units' => 2.0,
                'kind' => 'pe2',
                'classification' => CourseSpecification::AcademicClassificationPe,
                'allowed_modalities' => $f2fOnly,
            ],
            'PATHFIT4' => [
                'title' => 'PATHFIT 4: Outdoor and Adventure Activities',
                'units' => 2.0,
                'kind' => 'pe2',
                'classification' => CourseSpecification::AcademicClassificationPe,
                'allowed_modalities' => $f2fOnly,
            ],

            // --- Mandated NSTP (RA 9163: 3u = 3h/wk, 1x180) ---
            'NSTP1' => [
                'title' => 'National Service Training Program 1 (CWTS)',
                'units' => 3.0,
                'kind' => 'nstp3',
                'classification' => CourseSpecification::AcademicClassificationNstp,
                'allowed_modalities' => $f2fOnly,
            ],
            'NSTP2' => [
                'title' => 'National Service Training Program 2 (CWTS)',
                'units' => 3.0,
                'kind' => 'nstp3',
                'classification' => CourseSpecification::AcademicClassificationNstp,
                'allowed_modalities' => $f2fOnly,
            ],

            // --- Shared Institutional Language (3u = 3h/wk) ---
            'NIHONGO1' => ['title' => 'Foreign Language 1 (Basic Nihongo)', 'units' => 3.0, 'kind' => 'lec3'],
            'NIHONGO2' => ['title' => 'Foreign Language 2 (Intermediate Nihongo)', 'units' => 3.0, 'kind' => 'lec3'],

            // --- Shared TESDA Competency across DBM & DIT ---
            'CCS101' => [
                'title' => 'Contact Center Services NC II',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],

            // --- DHM (Hospitality Management) ---
            'THC1' => ['title' => 'Macro Perspective of Tourism and Hospitality', 'units' => 3.0, 'kind' => 'lec3'],
            'THC2' => ['title' => 'Risk Management as Applied to Safety, Security, and Sanitation', 'units' => 3.0, 'kind' => 'lec3'],
            'THC3' => ['title' => 'Quality Service Management in Tourism and Hospitality', 'units' => 3.0, 'kind' => 'lec3'],
            'THC4' => ['title' => 'Philippine Culture and Tourism Geography', 'units' => 3.0, 'kind' => 'lec3'],
            'THC5' => ['title' => 'Micro Perspective of Tourism and Hospitality', 'units' => 3.0, 'kind' => 'lec3'],
            'THC6' => ['title' => 'Professional Development and Applied Ethics in Hospitality', 'units' => 3.0, 'kind' => 'lec3'],
            'THC7' => ['title' => 'Tourism and Hospitality Marketing', 'units' => 3.0, 'kind' => 'lec3'],
            'THC8' => [
                'title' => 'Multicultural Diversity and Workplace Hospitality Operations',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'allowed_modalities' => $f2fOnly,
            ],
            'THC9' => ['title' => 'Legal Aspects in Tourism and Hospitality', 'units' => 3.0, 'kind' => 'lec3'],
            'THC10' => ['title' => 'Entrepreneurship in Tourism and Hospitality', 'units' => 3.0, 'kind' => 'lec3'],

            'HPC1' => [
                'title' => 'Kitchen Essentials and Basic Food Preparation (Cookery NC II)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC2' => [
                'title' => 'Fundamentals in Lodging Operations (Housekeeping NC II)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC3' => [
                'title' => 'Applied Business Tools and Front Office Procedures (Front Office NC II)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC4' => [
                'title' => 'Fundamentals in Food Service Operations (Food & Beverage Services NC II)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC5' => [
                'title' => 'Bread and Pastry Production NC II',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC6' => [
                'title' => 'Bartending and Bar Operations NC II',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC7' => [
                'title' => 'Supply Chain and Culinary Purchasing Management',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC8' => ['title' => 'Ergonomics and Facilities Planning for Hospitality', 'units' => 3.0, 'kind' => 'lec3'],
            'HPC9' => [
                'title' => 'Introduction to Meetings, Incentives, Conferences, and Events (Events Management NC III)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC10' => [
                'title' => 'Commercial Cooking and Quantity Food Production (NC III)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC11' => [
                'title' => 'Banquet, Function, and Catering Services Procedures',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC12' => [
                'title' => 'Asian and International Cuisine Laboratory',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'allowed_modalities' => $f2fOnly,
            ],
            'HPC13' => [
                'title' => 'Hospitality Cost Control and Menu Engineering',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'allowed_modalities' => $f2fOnly,
            ],
            'BME1' => [
                'title' => 'Operations Management in Hospitality (TQM & PMS Lab)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'BME2' => ['title' => 'Strategic Management in Tourism and Hospitality', 'units' => 3.0, 'kind' => 'lec3'],
            'LIT1' => ['title' => 'Philippine Literature and Cultural Heritage', 'units' => 3.0, 'kind' => 'lec3'],
            'LIT2' => ['title' => 'World Literature and Global Perspectives', 'units' => 3.0, 'kind' => 'lec3'],
            'RESHM' => ['title' => 'Research in Hospitality Management', 'units' => 3.0, 'kind' => 'lec3'],
            'HMPRAC1' => [
                'title' => 'Hospitality Industry Practicum 1 (400 Hours OJT)',
                'units' => 6.0,
                'treatment' => CourseSpecification::SchedulingExternallyArranged,
            ],
            'HMPRAC2' => [
                'title' => 'Hospitality Industry Practicum 2 (400 Hours OJT)',
                'units' => 6.0,
                'treatment' => CourseSpecification::SchedulingExternallyArranged,
            ],

            // --- DBM (Business Management) ---
            'BKP101' => [
                'title' => 'Bookkeeping NC III (Financial Accounting Fundamentals)',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'BKP102' => ['title' => 'Intermediate Accounting 2', 'units' => 3.0, 'kind' => 'lec3'],
            'BKP103' => ['title' => 'Intermediate Accounting 3', 'units' => 3.0, 'kind' => 'lec3'],
            'BME001' => ['title' => 'Principles of Management and Organization', 'units' => 3.0, 'kind' => 'lec3'],
            'BME002' => ['title' => 'Basic Microeconomics', 'units' => 3.0, 'kind' => 'lec3'],
            'BME003' => ['title' => 'Human Resource Management', 'units' => 3.0, 'kind' => 'lec3'],
            'BME004' => ['title' => 'Principles of Marketing', 'units' => 3.0, 'kind' => 'lec3'],
            'BME005' => ['title' => 'Financial Management', 'units' => 3.0, 'kind' => 'lec3'],
            'BME006' => ['title' => 'Operations Management and Total Quality Management', 'units' => 3.0, 'kind' => 'lec3'],
            'BME007' => ['title' => 'International Business and Trade', 'units' => 3.0, 'kind' => 'lec3'],
            'BME008' => ['title' => 'Strategic Management', 'units' => 3.0, 'kind' => 'lec3'],
            'BME009' => ['title' => 'Consumer Behavior and Market Analysis', 'units' => 3.0, 'kind' => 'lec3'],
            'BME010' => ['title' => 'Business Research Methods', 'units' => 3.0, 'kind' => 'lec3'],
            'BME011' => ['title' => 'Entrepreneurial Management and Micro-Enterprise', 'units' => 3.0, 'kind' => 'lec3'],
            'BME012' => ['title' => 'Marketing Management', 'units' => 3.0, 'kind' => 'lec3'],
            'BME013' => ['title' => 'Income Taxation', 'units' => 3.0, 'kind' => 'lec3'],
            'BME014' => ['title' => 'Business Law (Obligations and Contracts)', 'units' => 3.0, 'kind' => 'lec3'],
            'BME015' => ['title' => 'Good Governance and Social Responsibility', 'units' => 3.0, 'kind' => 'lec3'],
            'BME016' => ['title' => 'Cost Accounting and Control', 'units' => 3.0, 'kind' => 'lec3'],
            'BME017' => ['title' => 'Feasibility Study and Project Development', 'units' => 3.0, 'kind' => 'lec3'],
            'PROFM001' => ['title' => 'Professional Salesmanship and Customer Relations', 'units' => 3.0, 'kind' => 'lec3'],
            'PROFM002' => ['title' => 'Distribution and Retail Management', 'units' => 3.0, 'kind' => 'lec3'],
            'PROFM003' => ['title' => 'Pricing Strategy and Product Management', 'units' => 3.0, 'kind' => 'lec3'],
            'AGRO101' => [
                'title' => 'Agro-Entrepreneurship NC II',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'AGRO102' => [
                'title' => 'Agro-Entrepreneurship NC III',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'FOS101' => [
                'title' => 'Front Office Services NC II',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'COOP101' => [
                'title' => 'Cooperative Marketing and Enterprise Operations',
                'units' => 3.0,
                'kind' => 'lec2_lab1_hosp',
                'allowed_modalities' => $f2fOnly,
            ],
            'BMPRAC1' => [
                'title' => 'Business Management Practicum 1 (400 Hours OJT)',
                'units' => 6.0,
                'treatment' => CourseSpecification::SchedulingExternallyArranged,
            ],
            'BMPRAC2' => [
                'title' => 'Business Management Practicum 2 (400 Hours OJT)',
                'units' => 6.0,
                'treatment' => CourseSpecification::SchedulingExternallyArranged,
            ],

            // --- DIT (Information Technology) ---
            'CC101' => [
                'title' => 'Introduction to Computing',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'CC102' => [
                'title' => 'Computer Programming 1',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'CC103' => [
                'title' => 'Computer Programming 2',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'CC104' => [
                'title' => 'Data Structures and Algorithms',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'CC105' => ['title' => 'Information Management', 'units' => 3.0, 'kind' => 'lec3'],
            'CC107' => [
                'title' => 'Application Development and Emerging Technologies',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'MATH111' => ['title' => 'Linear Algebra', 'units' => 3.0, 'kind' => 'lec3'],
            'PHY101' => ['title' => 'Calculus-Based Physics', 'units' => 3.0, 'kind' => 'lec3'],
            'CSS101' => [
                'title' => 'Computer Systems Servicing NC II',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'NET101' => [
                'title' => 'Computer Networking 1',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'NET102' => [
                'title' => 'Computer Networking 2',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'LAW101' => ['title' => 'IT Law and Professional Ethics', 'units' => 3.0, 'kind' => 'lec3'],
            'IM101' => [
                'title' => 'Database Management Systems',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'IPT101' => [
                'title' => 'Integrative Programming and Technologies 1',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'RES101' => ['title' => 'Fundamentals of Research in Computing', 'units' => 3.0, 'kind' => 'lec3'],
            'HCI101' => ['title' => 'Human-Computer Interaction', 'units' => 3.0, 'kind' => 'lec3'],
            'IAS101' => ['title' => 'Information Assurance and Security 1', 'units' => 3.0, 'kind' => 'lec3'],
            'IAS102' => ['title' => 'Information Assurance and Security 2', 'units' => 3.0, 'kind' => 'lec3'],
            'SA101' => [
                'title' => 'Systems Administration and Maintenance',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'allowed_modalities' => $f2fOnly,
            ],
            'SEF101' => ['title' => 'Software Engineering Fundamentals', 'units' => 3.0, 'kind' => 'lec3'],
            'WEB101' => [
                'title' => 'Web Development NC III - 1',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'WEB102' => [
                'title' => 'Web Development NC III - 2',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'VGD101' => [
                'title' => 'Visual Graphics Design NC III',
                'units' => 3.0,
                'kind' => 'lec2_lab1_comp',
                'classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                'allowed_modalities' => $f2fOnly,
            ],
            'SIA101' => ['title' => 'System Integration and Architecture 1', 'units' => 3.0, 'kind' => 'lec3'],
            'SP101' => ['title' => 'Social and Professional Issues in IT', 'units' => 3.0, 'kind' => 'lec3'],
            'CAP101' => ['title' => 'Capstone Project and Research 1', 'units' => 3.0, 'kind' => 'lec3'],
            'CAP102' => ['title' => 'Capstone Project and Research 2', 'units' => 3.0, 'kind' => 'lec3'],
            'ITPRAC1' => [
                'title' => 'IT Industry Practicum 1 (400 Hours OJT)',
                'units' => 6.0,
                'treatment' => CourseSpecification::SchedulingExternallyArranged,
            ],
            'ITPRAC2' => [
                'title' => 'IT Industry Practicum 2 (400 Hours OJT)',
                'units' => 6.0,
                'treatment' => CourseSpecification::SchedulingExternallyArranged,
            ],
        ];
    }

    /**
     * Strictly prior-semester prerequisites (zero self-prerequisites, zero same-semester cycles).
     *
     * @return array<string, list<string>>
     */
    private function prerequisiteMap(): array
    {
        return [
            // Shared
            'PATHFIT2' => ['PATHFIT1'],
            'PATHFIT3' => ['PATHFIT2'],
            'PATHFIT4' => ['PATHFIT3'],
            'NSTP2' => ['NSTP1'],
            'NIHONGO2' => ['NIHONGO1'],

            // DHM
            'THC3' => ['THC1'],
            'THC4' => ['THC3'],
            'THC5' => ['THC2'],
            'THC6' => ['THC4'],
            'THC7' => ['THC5'],
            'THC8' => ['THC6'],
            'THC9' => ['THC8'],
            'THC10' => ['THC7'],
            'HPC3' => ['HPC1'],
            'HPC4' => ['HPC1', 'HPC2'],
            'HPC5' => ['HPC1', 'HPC3'],
            'HPC6' => ['HPC3'],
            'HPC7' => ['HPC4'],
            'HPC8' => ['HPC5'],
            'HPC9' => ['HPC6'],
            'HPC10' => ['HPC7'],
            'HPC11' => ['HPC9'],
            'HPC12' => ['HPC10'],
            'HPC13' => ['HPC11'],
            'BME2' => ['BME1'],
            'LIT2' => ['LIT1'],
            'HMPRAC1' => ['HPC1', 'HPC2', 'HPC3'],
            'HMPRAC2' => ['HMPRAC1', 'HPC7'],

            // DBM
            'BME003' => ['BME001'],
            'BME004' => ['BME002'],
            'BME005' => ['BME003'],
            'BME006' => ['BME004'],
            'BME007' => ['BME005'],
            'BME008' => ['BME006'],
            'BKP102' => ['BKP101'],
            'BKP103' => ['BKP102'],
            'PROFM003' => ['PROFM001', 'PROFM002'],
            'AGRO102' => ['AGRO101'],
            'BME009' => ['BME007'],
            'BME010' => ['BME008'],
            'BME011' => ['PROFM003'],
            'BME012' => ['BME009'],
            'BME013' => ['BKP103'],
            'BME015' => ['BME014'],
            'BME016' => ['BKP103'],
            'BME017' => ['BME010', 'BME011'],
            'COOP101' => ['AGRO102'],
            'BMPRAC1' => ['BKP101', 'CCS101'],
            'BMPRAC2' => ['BMPRAC1', 'FOS101'],

            // DIT
            'CC102' => ['CC101'],
            'PHY101' => ['MATH111'],
            'CC103' => ['CC102'],
            'CC104' => ['CC102'],
            'CC105' => ['CC102'],
            'NET101' => ['CSS101'],
            'IM101' => ['CC105'],
            'NET102' => ['NET101'],
            'IPT101' => ['CC103', 'CC104'],
            'HCI101' => ['CC103'],
            'CC107' => ['IPT101'],
            'IAS101' => ['NET102'],
            'SA101' => ['NET102'],
            'SEF101' => ['CC104', 'IM101'],
            'WEB101' => ['IPT101', 'HCI101'],
            'CAP101' => ['RES101', 'IM101'],
            'SIA101' => ['CC107', 'SEF101'],
            'IAS102' => ['IAS101'],
            'SP101' => ['LAW101'],
            'VGD101' => ['HCI101'],
            'WEB102' => ['WEB101'],
            'CAP102' => ['CAP101'],
            'ITPRAC1' => ['CC102', 'CSS101'],
            'ITPRAC2' => ['ITPRAC1', 'IM101', 'NET102'],
        ];
    }

    /**
     * Full 3-Year Diploma curricula (23u Y1S1/Y1S2/Y2S1/Y2S2, 6u Summer OJT, 21u Y3S1/Y3S2).
     *
     * @return array<string, list<array{
     *     year_level: string,
     *     term_label: string,
     *     term_type: string,
     *     courses: list<string>
     * }>>
     */
    private function curriculumPlans(): array
    {
        return [
            'DHM' => [
                [
                    'year_level' => 'First Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['GE001', 'GE002', 'GE003', 'THC1', 'HPC1', 'HPC2', 'PATHFIT1', 'NSTP1'],
                ],
                [
                    'year_level' => 'First Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['GE004', 'GE005', 'GE006', 'THC2', 'THC3', 'HPC3', 'PATHFIT2', 'NSTP2'],
                ],
                [
                    'year_level' => 'First Year',
                    'term_label' => 'Summer',
                    'term_type' => Term::TypeSpecialTerm,
                    'courses' => ['HMPRAC1'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['GE007', 'GE008', 'THC4', 'THC5', 'HPC4', 'HPC5', 'HPC6', 'PATHFIT3'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['GE009', 'GE010', 'THC6', 'THC7', 'HPC7', 'HPC8', 'HPC9', 'PATHFIT4'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'Summer',
                    'term_type' => Term::TypeSpecialTerm,
                    'courses' => ['HMPRAC2'],
                ],
                [
                    'year_level' => 'Third Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['LIT1', 'RESHM', 'THC8', 'BME1', 'HPC10', 'HPC11', 'NIHONGO1'],
                ],
                [
                    'year_level' => 'Third Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['LIT2', 'THC9', 'THC10', 'BME2', 'HPC12', 'HPC13', 'NIHONGO2'],
                ],
            ],
            'DBM' => [
                [
                    'year_level' => 'First Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['GE001', 'GE002', 'GE003', 'BKP101', 'BME001', 'BME002', 'PATHFIT1', 'NSTP1'],
                ],
                [
                    'year_level' => 'First Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['GE004', 'GE005', 'GE006', 'BME003', 'BME004', 'CCS101', 'PATHFIT2', 'NSTP2'],
                ],
                [
                    'year_level' => 'First Year',
                    'term_label' => 'Summer',
                    'term_type' => Term::TypeSpecialTerm,
                    'courses' => ['BMPRAC1'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['GE007', 'GE008', 'BME005', 'BME006', 'PROFM001', 'PROFM002', 'AGRO101', 'PATHFIT3'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['GE009', 'GE010', 'BKP102', 'BME007', 'BME008', 'PROFM003', 'FOS101', 'PATHFIT4'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'Summer',
                    'term_type' => Term::TypeSpecialTerm,
                    'courses' => ['BMPRAC2'],
                ],
                [
                    'year_level' => 'Third Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['BKP103', 'BME009', 'BME010', 'BME011', 'BME014', 'AGRO102', 'NIHONGO1'],
                ],
                [
                    'year_level' => 'Third Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['BME012', 'BME013', 'BME015', 'BME016', 'BME017', 'COOP101', 'NIHONGO2'],
                ],
            ],
            'DIT' => [
                [
                    'year_level' => 'First Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['GE001', 'GE002', 'GE003', 'CC101', 'MATH111', 'CCS101', 'PATHFIT1', 'NSTP1'],
                ],
                [
                    'year_level' => 'First Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['GE004', 'GE005', 'GE006', 'CC102', 'PHY101', 'CSS101', 'PATHFIT2', 'NSTP2'],
                ],
                [
                    'year_level' => 'First Year',
                    'term_label' => 'Summer',
                    'term_type' => Term::TypeSpecialTerm,
                    'courses' => ['ITPRAC1'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['GE007', 'GE008', 'CC103', 'CC104', 'CC105', 'NET101', 'LAW101', 'PATHFIT3'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['GE009', 'GE010', 'IM101', 'NET102', 'IPT101', 'RES101', 'HCI101', 'PATHFIT4'],
                ],
                [
                    'year_level' => 'Second Year',
                    'term_label' => 'Summer',
                    'term_type' => Term::TypeSpecialTerm,
                    'courses' => ['ITPRAC2'],
                ],
                [
                    'year_level' => 'Third Year',
                    'term_label' => 'First Semester',
                    'term_type' => Term::TypeFirstSemester,
                    'courses' => ['CC107', 'IAS101', 'SA101', 'SEF101', 'WEB101', 'CAP101', 'NIHONGO1'],
                ],
                [
                    'year_level' => 'Third Year',
                    'term_label' => 'Second Semester',
                    'term_type' => Term::TypeSecondSemester,
                    'courses' => ['SIA101', 'IAS102', 'SP101', 'VGD101', 'WEB102', 'CAP102', 'NIHONGO2'],
                ],
            ],
        ];
    }

    /**
     * 26 active SERVITECH faculty members with realistic Filipino names, balanced pools,
     * and generous availability declarations (mix of any_approved and windows).
     *
     * @return list<array{
     *     email: string,
     *     first_name: string,
     *     last_name: string,
     *     courses: list<string>,
     *     availability_mode: string,
     *     teaching_windows?: list<array{day_of_week: int, start_time: string, end_time: string}>
     * }>
     */
    private function facultyRosterDefinitions(): array
    {
        $monFriFull = [
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 2, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 5, 'start_time' => '13:00', 'end_time' => '18:00'],
        ];

        $monFriEarly = [
            ['day_of_week' => 1, 'start_time' => '07:30', 'end_time' => '12:00'],
            ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 2, 'start_time' => '07:30', 'end_time' => '12:00'],
            ['day_of_week' => 2, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 3, 'start_time' => '07:30', 'end_time' => '12:00'],
            ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 4, 'start_time' => '07:30', 'end_time' => '12:00'],
            ['day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 5, 'start_time' => '07:30', 'end_time' => '12:00'],
            ['day_of_week' => 5, 'start_time' => '13:00', 'end_time' => '17:30'],
        ];

        $tueSatFull = [
            ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 2, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 5, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 6, 'start_time' => '13:00', 'end_time' => '17:00'],
        ];

        $monThuSat = [
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 2, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 6, 'start_time' => '13:00', 'end_time' => '17:00'],
        ];

        $any = FacultyAvailabilityDeclaration::ModeAnyApproved;
        $win = FacultyAvailabilityDeclaration::ModeWindows;

        return [
            // General Education & Languages (6)
            [
                'email' => 'prof.alcantara@servitech.test',
                'first_name' => 'Marisol',
                'last_name' => 'Alcantara',
                'courses' => ['GE001', 'GE002', 'GE004', 'GE005'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.bautista@servitech.test',
                'first_name' => 'Danilo',
                'last_name' => 'Bautista',
                'courses' => ['GE001', 'GE003', 'GE006', 'GE009', 'GE010'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriFull,
            ],
            [
                'email' => 'prof.castillo@servitech.test',
                'first_name' => 'Clarissa',
                'last_name' => 'Castillo',
                'courses' => ['GE002', 'GE003', 'GE006', 'GE007', 'GE009', 'GE010'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.delacruz@servitech.test',
                'first_name' => 'Roberto',
                'last_name' => 'Dela Cruz',
                'courses' => ['GE004', 'GE007', 'GE008', 'MATH111', 'PHY101'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriEarly,
            ],
            [
                'email' => 'prof.espiritu@servitech.test',
                'first_name' => 'Lorna',
                'last_name' => 'Espiritu',
                'courses' => ['GE008', 'LIT1', 'LIT2', 'GE005'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.takahashi@servitech.test',
                'first_name' => 'Kenji',
                'last_name' => 'Takahashi',
                'courses' => ['NIHONGO1', 'NIHONGO2'],
                'availability_mode' => $win,
                'teaching_windows' => $monThuSat,
            ],

            // PE & NSTP (3)
            [
                'email' => 'prof.garcia@servitech.test',
                'first_name' => 'Rafael',
                'last_name' => 'Garcia',
                'courses' => ['PATHFIT1', 'PATHFIT2', 'PATHFIT3', 'PATHFIT4'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.hernandez@servitech.test',
                'first_name' => 'Bianca',
                'last_name' => 'Hernandez',
                'courses' => ['PATHFIT1', 'PATHFIT2', 'PATHFIT3', 'PATHFIT4'],
                'availability_mode' => $win,
                'teaching_windows' => $tueSatFull,
            ],
            [
                'email' => 'prof.ignacio@servitech.test',
                'first_name' => 'Arturo',
                'last_name' => 'Ignacio',
                'courses' => ['NSTP1', 'NSTP2', 'GE001'],
                'availability_mode' => $win,
                'teaching_windows' => $monThuSat,
            ],

            // Hospitality Management — DHM (6)
            [
                'email' => 'prof.javier@servitech.test',
                'first_name' => 'Antonio',
                'last_name' => 'Javier',
                'courses' => ['HPC1', 'HPC2', 'HPC3', 'HPC4', 'HPC7', 'HPC10'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.katigbak@servitech.test',
                'first_name' => 'Camille',
                'last_name' => 'Katigbak',
                'courses' => ['HPC1', 'HPC2', 'HPC3', 'HPC5', 'HPC8', 'HPC11'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriEarly,
            ],
            [
                'email' => 'prof.lazaro@servitech.test',
                'first_name' => 'Miguel',
                'last_name' => 'Lazaro',
                'courses' => ['HPC4', 'HPC5', 'HPC6', 'HPC9', 'HPC10', 'HPC11', 'HPC12', 'HPC13'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.manalo@servitech.test',
                'first_name' => 'Teresa',
                'last_name' => 'Manalo',
                'courses' => ['THC1', 'THC2', 'THC3', 'THC4', 'THC6', 'THC8', 'THC9'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriFull,
            ],
            [
                'email' => 'prof.navarro@servitech.test',
                'first_name' => 'Gabriel',
                'last_name' => 'Navarro',
                'courses' => ['THC2', 'THC3', 'THC4', 'THC5', 'THC7', 'THC8', 'THC10', 'RESHM'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.ocampo@servitech.test',
                'first_name' => 'Patricia',
                'last_name' => 'Ocampo',
                'courses' => ['THC1', 'THC5', 'HPC6', 'BME1', 'BME2', 'RESHM'],
                'availability_mode' => $win,
                'teaching_windows' => $tueSatFull,
            ],

            // Business Management — DBM (6)
            [
                'email' => 'prof.pascual@servitech.test',
                'first_name' => 'Eduardo',
                'last_name' => 'Pascual',
                'courses' => ['BKP101', 'BKP102', 'BKP103', 'BME013', 'BME016'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.quijano@servitech.test',
                'first_name' => 'Melissa',
                'last_name' => 'Quijano',
                'courses' => ['BKP101', 'BKP102', 'BKP103', 'BME001', 'BME002', 'BME003', 'BME005', 'BME016'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriFull,
            ],
            [
                'email' => 'prof.ramos@servitech.test',
                'first_name' => 'Fernando',
                'last_name' => 'Ramos',
                'courses' => ['BME001', 'BME002', 'BME003', 'BME004', 'BME006', 'BME009', 'BME012'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.salazar@servitech.test',
                'first_name' => 'Angela',
                'last_name' => 'Salazar',
                'courses' => ['BME005', 'BME006', 'BME007', 'BME008', 'PROFM001', 'PROFM002', 'PROFM003'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriEarly,
            ],
            [
                'email' => 'prof.tolentino@servitech.test',
                'first_name' => 'Victor',
                'last_name' => 'Tolentino',
                'courses' => ['BME014', 'BME015', 'LAW101', 'SP101', 'BME010', 'BME011'],
                'availability_mode' => $win,
                'teaching_windows' => $monThuSat,
            ],
            [
                'email' => 'prof.umali@servitech.test',
                'first_name' => 'Rosario',
                'last_name' => 'Umali',
                'courses' => ['AGRO101', 'AGRO102', 'COOP101', 'FOS101', 'BME010', 'BME011', 'BME017', 'CCS101'],
                'availability_mode' => $any,
            ],

            // Information Technology — DIT (5)
            [
                'email' => 'prof.valdez@servitech.test',
                'first_name' => 'Carlo',
                'last_name' => 'Valdez',
                'courses' => ['CC101', 'CC102', 'CC103', 'CC104', 'IM101', 'HCI101', 'IPT101', 'SEF101'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.villanueva@servitech.test',
                'first_name' => 'Diana',
                'last_name' => 'Villanueva',
                'courses' => ['CC101', 'CC103', 'CC104', 'CC105', 'IM101', 'HCI101', 'RES101', 'CAP101', 'CAP102'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriFull,
            ],
            [
                'email' => 'prof.yap@servitech.test',
                'first_name' => 'Jerome',
                'last_name' => 'Yap',
                'courses' => ['NET101', 'NET102', 'CSS101', 'SA101', 'IAS101', 'IAS102'],
                'availability_mode' => $any,
            ],
            [
                'email' => 'prof.zamora@servitech.test',
                'first_name' => 'Kristine',
                'last_name' => 'Zamora',
                'courses' => ['CC107', 'WEB101', 'WEB102', 'VGD101', 'SEF101', 'SIA101', 'CAP101'],
                'availability_mode' => $win,
                'teaching_windows' => $monFriEarly,
            ],
            [
                'email' => 'prof.soriano@servitech.test',
                'first_name' => 'Paolo',
                'last_name' => 'Soriano',
                'courses' => ['CCS101', 'CC102', 'CC105', 'CC107', 'NET101', 'NET102', 'SA101', 'WEB101', 'IAS101', 'LAW101'],
                'availability_mode' => $any,
            ],
        ];
    }

    /**
     * All First Semester class offerings across the 9 confirmed sections, including
     * 6 cross-program merged GE/Nihongo classes and 7 Online classes alongside Face-to-Face majors.
     *
     * @return list<array{
     *     code: string,
     *     owner_program: string,
     *     year_level: string,
     *     course: string,
     *     cohorts: list<string>,
     *     group_name: string,
     *     modality: string,
     *     capacity?: int
     * }>
     */
    private function firstSemesterClassDefinitions(): array
    {
        $f2f = TermOffering::ModalityFaceToFace;
        $online = TermOffering::ModalityOnline;

        return [
            // === YEAR 1 FIRST SEMESTER — Shared / Cross-Program Merged GE ===
            [
                'code' => 'GE001-Y1-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'First Year',
                'course' => 'GE001',
                'cohorts' => ['DHM 1-1', 'DBM 1-1'],
                'group_name' => 'DHM 1-1 + DBM 1-1',
                'modality' => $f2f,
                'capacity' => 45,
            ],
            [
                'code' => 'GE001-DIT11',
                'owner_program' => 'DIT',
                'year_level' => 'First Year',
                'course' => 'GE001',
                'cohorts' => ['DIT 1-1'],
                'group_name' => 'DIT 1-1',
                'modality' => $f2f,
                'capacity' => 40,
            ],
            [
                'code' => 'GE002-Y1-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'First Year',
                'course' => 'GE002',
                'cohorts' => ['DHM 1-1', 'DBM 1-1'],
                'group_name' => 'DHM 1-1 + DBM 1-1',
                'modality' => $online,
                'capacity' => 45,
            ],
            [
                'code' => 'GE002-DIT11',
                'owner_program' => 'DIT',
                'year_level' => 'First Year',
                'course' => 'GE002',
                'cohorts' => ['DIT 1-1'],
                'group_name' => 'DIT 1-1',
                'modality' => $online,
                'capacity' => 40,
            ],
            [
                'code' => 'GE003-Y1-MERGED',
                'owner_program' => 'DBM',
                'year_level' => 'First Year',
                'course' => 'GE003',
                'cohorts' => ['DHM 1-1', 'DBM 1-1'],
                'group_name' => 'DHM 1-1 + DBM 1-1',
                'modality' => $f2f,
                'capacity' => 45,
            ],
            [
                'code' => 'GE003-DIT11',
                'owner_program' => 'DIT',
                'year_level' => 'First Year',
                'course' => 'GE003',
                'cohorts' => ['DIT 1-1'],
                'group_name' => 'DIT 1-1',
                'modality' => $f2f,
                'capacity' => 40,
            ],

            // === YEAR 1 FIRST SEMESTER — PATHFIT1 & NSTP1 ===
            ['code' => 'PATHFIT1-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'PATHFIT1', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'PATHFIT1-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'PATHFIT1', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'PATHFIT1-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'PATHFIT1', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],
            ['code' => 'NSTP1-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'NSTP1', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'NSTP1-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'NSTP1', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'NSTP1-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'NSTP1', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],

            // === YEAR 1 FIRST SEMESTER — Program Majors ===
            ['code' => 'THC1-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'THC1', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'HPC1-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'HPC1', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'HPC2-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'HPC2', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],

            ['code' => 'BKP101-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'BKP101', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'BME001-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'BME001', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'BME002-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'BME002', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],

            ['code' => 'CC101-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'CC101', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],
            ['code' => 'MATH111-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'MATH111', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],
            ['code' => 'CCS101-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'CCS101', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],

            // === YEAR 2 FIRST SEMESTER — Shared / Cross-Program Merged GE ===
            [
                'code' => 'GE007-Y2-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'Second Year',
                'course' => 'GE007',
                'cohorts' => ['DHM 2-1', 'DBM 2-1'],
                'group_name' => 'DHM 2-1 + DBM 2-1',
                'modality' => $f2f,
                'capacity' => 45,
            ],
            [
                'code' => 'GE007-DIT21',
                'owner_program' => 'DIT',
                'year_level' => 'Second Year',
                'course' => 'GE007',
                'cohorts' => ['DIT 2-1'],
                'group_name' => 'DIT 2-1',
                'modality' => $f2f,
                'capacity' => 40,
            ],
            [
                'code' => 'GE008-Y2-MERGED',
                'owner_program' => 'DBM',
                'year_level' => 'Second Year',
                'course' => 'GE008',
                'cohorts' => ['DHM 2-1', 'DBM 2-1'],
                'group_name' => 'DHM 2-1 + DBM 2-1',
                'modality' => $online,
                'capacity' => 45,
            ],
            [
                'code' => 'GE008-DIT21',
                'owner_program' => 'DIT',
                'year_level' => 'Second Year',
                'course' => 'GE008',
                'cohorts' => ['DIT 2-1'],
                'group_name' => 'DIT 2-1',
                'modality' => $online,
                'capacity' => 40,
            ],

            // === YEAR 2 FIRST SEMESTER — PATHFIT3 ===
            ['code' => 'PATHFIT3-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'PATHFIT3', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'PATHFIT3-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'PATHFIT3', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'PATHFIT3-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'PATHFIT3', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],

            // === YEAR 2 FIRST SEMESTER — Program Majors ===
            ['code' => 'THC4-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'THC4', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'THC5-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'THC5', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'HPC4-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'HPC4', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'HPC5-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'HPC5', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'HPC6-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'HPC6', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],

            ['code' => 'BME005-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'BME005', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'BME006-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'BME006', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'PROFM001-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'PROFM001', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'PROFM002-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'PROFM002', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'AGRO101-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'AGRO101', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],

            ['code' => 'CC103-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'CC103', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],
            ['code' => 'CC104-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'CC104', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],
            ['code' => 'CC105-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'CC105', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],
            ['code' => 'NET101-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'NET101', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],
            ['code' => 'LAW101-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'LAW101', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $online],

            // === YEAR 3 FIRST SEMESTER — Shared / Cross-Program Merged Nihongo ===
            [
                'code' => 'NIHONGO1-Y3-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'Third Year',
                'course' => 'NIHONGO1',
                'cohorts' => ['DHM 3-1', 'DBM 3-1'],
                'group_name' => 'DHM 3-1 + DBM 3-1',
                'modality' => $f2f,
                'capacity' => 45,
            ],
            [
                'code' => 'NIHONGO1-DIT31',
                'owner_program' => 'DIT',
                'year_level' => 'Third Year',
                'course' => 'NIHONGO1',
                'cohorts' => ['DIT 3-1'],
                'group_name' => 'DIT 3-1',
                'modality' => $f2f,
                'capacity' => 40,
            ],

            // === YEAR 3 FIRST SEMESTER — Program Majors ===
            ['code' => 'LIT1-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'LIT1', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $online],
            ['code' => 'RESHM-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'RESHM', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'THC8-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'THC8', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'BME1-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'BME1', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'HPC10-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'HPC10', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'HPC11-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'HPC11', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],

            ['code' => 'BKP103-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BKP103', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'BME009-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME009', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'BME010-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME010', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'BME011-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME011', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'BME014-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME014', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $online],
            ['code' => 'AGRO102-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'AGRO102', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],

            ['code' => 'CC107-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'CC107', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'IAS101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'IAS101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'SA101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'SA101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'SEF101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'SEF101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'WEB101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'WEB101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'CAP101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'CAP101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
        ];
    }

    /**
     * All Second Semester class offerings across the 9 confirmed sections, including
     * 6 cross-program merged GE/Nihongo classes and 10 Online classes alongside Face-to-Face majors.
     *
     * @return list<array{
     *     code: string,
     *     owner_program: string,
     *     year_level: string,
     *     course: string,
     *     cohorts: list<string>,
     *     group_name: string,
     *     modality: string,
     *     capacity?: int
     * }>
     */
    private function secondSemesterClassDefinitions(): array
    {
        $f2f = TermOffering::ModalityFaceToFace;
        $online = TermOffering::ModalityOnline;

        return [
            // === YEAR 1 SECOND SEMESTER — Shared / Cross-Program Merged GE ===
            [
                'code' => 'GE004-S2-Y1-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'First Year',
                'course' => 'GE004',
                'cohorts' => ['DHM 1-1', 'DBM 1-1'],
                'group_name' => 'DHM 1-1 + DBM 1-1',
                'modality' => $f2f,
                'capacity' => 45,
            ],
            [
                'code' => 'GE004-S2-DIT11',
                'owner_program' => 'DIT',
                'year_level' => 'First Year',
                'course' => 'GE004',
                'cohorts' => ['DIT 1-1'],
                'group_name' => 'DIT 1-1',
                'modality' => $f2f,
                'capacity' => 40,
            ],
            [
                'code' => 'GE005-S2-Y1-MERGED',
                'owner_program' => 'DBM',
                'year_level' => 'First Year',
                'course' => 'GE005',
                'cohorts' => ['DHM 1-1', 'DBM 1-1'],
                'group_name' => 'DHM 1-1 + DBM 1-1',
                'modality' => $online,
                'capacity' => 45,
            ],
            [
                'code' => 'GE005-S2-DIT11',
                'owner_program' => 'DIT',
                'year_level' => 'First Year',
                'course' => 'GE005',
                'cohorts' => ['DIT 1-1'],
                'group_name' => 'DIT 1-1',
                'modality' => $online,
                'capacity' => 40,
            ],
            [
                'code' => 'GE006-S2-Y1-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'First Year',
                'course' => 'GE006',
                'cohorts' => ['DHM 1-1', 'DBM 1-1'],
                'group_name' => 'DHM 1-1 + DBM 1-1',
                'modality' => $online,
                'capacity' => 45,
            ],
            [
                'code' => 'GE006-S2-DIT11',
                'owner_program' => 'DIT',
                'year_level' => 'First Year',
                'course' => 'GE006',
                'cohorts' => ['DIT 1-1'],
                'group_name' => 'DIT 1-1',
                'modality' => $online,
                'capacity' => 40,
            ],

            // === YEAR 1 SECOND SEMESTER — PATHFIT2 & NSTP2 ===
            ['code' => 'PATHFIT2-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'PATHFIT2', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'PATHFIT2-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'PATHFIT2', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'PATHFIT2-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'PATHFIT2', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],
            ['code' => 'NSTP2-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'NSTP2', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'NSTP2-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'NSTP2', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'NSTP2-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'NSTP2', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],

            // === YEAR 1 SECOND SEMESTER — Program Majors ===
            ['code' => 'THC2-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'THC2', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'THC3-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'THC3', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],
            ['code' => 'HPC3-DHM11', 'owner_program' => 'DHM', 'year_level' => 'First Year', 'course' => 'HPC3', 'cohorts' => ['DHM 1-1'], 'group_name' => 'DHM 1-1', 'modality' => $f2f],

            ['code' => 'BME003-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'BME003', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'BME004-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'BME004', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],
            ['code' => 'CCS101-DBM11', 'owner_program' => 'DBM', 'year_level' => 'First Year', 'course' => 'CCS101', 'cohorts' => ['DBM 1-1'], 'group_name' => 'DBM 1-1', 'modality' => $f2f],

            ['code' => 'CC102-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'CC102', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],
            ['code' => 'PHY101-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'PHY101', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],
            ['code' => 'CSS101-DIT11', 'owner_program' => 'DIT', 'year_level' => 'First Year', 'course' => 'CSS101', 'cohorts' => ['DIT 1-1'], 'group_name' => 'DIT 1-1', 'modality' => $f2f],

            // === YEAR 2 SECOND SEMESTER — Shared / Cross-Program Merged GE ===
            [
                'code' => 'GE009-S2-Y2-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'Second Year',
                'course' => 'GE009',
                'cohorts' => ['DHM 2-1', 'DBM 2-1'],
                'group_name' => 'DHM 2-1 + DBM 2-1',
                'modality' => $online,
                'capacity' => 45,
            ],
            [
                'code' => 'GE009-S2-DIT21',
                'owner_program' => 'DIT',
                'year_level' => 'Second Year',
                'course' => 'GE009',
                'cohorts' => ['DIT 2-1'],
                'group_name' => 'DIT 2-1',
                'modality' => $online,
                'capacity' => 40,
            ],
            [
                'code' => 'GE010-S2-Y2-MERGED',
                'owner_program' => 'DBM',
                'year_level' => 'Second Year',
                'course' => 'GE010',
                'cohorts' => ['DHM 2-1', 'DBM 2-1'],
                'group_name' => 'DHM 2-1 + DBM 2-1',
                'modality' => $f2f,
                'capacity' => 45,
            ],
            [
                'code' => 'GE010-S2-DIT21',
                'owner_program' => 'DIT',
                'year_level' => 'Second Year',
                'course' => 'GE010',
                'cohorts' => ['DIT 2-1'],
                'group_name' => 'DIT 2-1',
                'modality' => $f2f,
                'capacity' => 40,
            ],

            // === YEAR 2 SECOND SEMESTER — PATHFIT4 ===
            ['code' => 'PATHFIT4-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'PATHFIT4', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'PATHFIT4-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'PATHFIT4', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'PATHFIT4-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'PATHFIT4', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],

            // === YEAR 2 SECOND SEMESTER — Program Majors ===
            ['code' => 'THC6-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'THC6', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $online],
            ['code' => 'THC7-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'THC7', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'HPC7-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'HPC7', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'HPC8-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'HPC8', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],
            ['code' => 'HPC9-DHM21', 'owner_program' => 'DHM', 'year_level' => 'Second Year', 'course' => 'HPC9', 'cohorts' => ['DHM 2-1'], 'group_name' => 'DHM 2-1', 'modality' => $f2f],

            ['code' => 'BKP102-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'BKP102', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'BME007-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'BME007', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'BME008-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'BME008', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $online],
            ['code' => 'PROFM003-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'PROFM003', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],
            ['code' => 'FOS101-DBM21', 'owner_program' => 'DBM', 'year_level' => 'Second Year', 'course' => 'FOS101', 'cohorts' => ['DBM 2-1'], 'group_name' => 'DBM 2-1', 'modality' => $f2f],

            ['code' => 'IM101-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'IM101', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],
            ['code' => 'NET102-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'NET102', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],
            ['code' => 'IPT101-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'IPT101', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],
            ['code' => 'RES101-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'RES101', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $online],
            ['code' => 'HCI101-DIT21', 'owner_program' => 'DIT', 'year_level' => 'Second Year', 'course' => 'HCI101', 'cohorts' => ['DIT 2-1'], 'group_name' => 'DIT 2-1', 'modality' => $f2f],

            // === YEAR 3 SECOND SEMESTER — Shared / Cross-Program Merged Nihongo ===
            [
                'code' => 'NIHONGO2-S2-Y3-MERGED',
                'owner_program' => 'DHM',
                'year_level' => 'Third Year',
                'course' => 'NIHONGO2',
                'cohorts' => ['DHM 3-1', 'DBM 3-1'],
                'group_name' => 'DHM 3-1 + DBM 3-1',
                'modality' => $f2f,
                'capacity' => 45,
            ],
            [
                'code' => 'NIHONGO2-S2-DIT31',
                'owner_program' => 'DIT',
                'year_level' => 'Third Year',
                'course' => 'NIHONGO2',
                'cohorts' => ['DIT 3-1'],
                'group_name' => 'DIT 3-1',
                'modality' => $f2f,
                'capacity' => 40,
            ],

            // === YEAR 3 SECOND SEMESTER — Program Majors ===
            ['code' => 'LIT2-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'LIT2', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $online],
            ['code' => 'THC9-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'THC9', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'THC10-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'THC10', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'BME2-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'BME2', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'HPC12-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'HPC12', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],
            ['code' => 'HPC13-DHM31', 'owner_program' => 'DHM', 'year_level' => 'Third Year', 'course' => 'HPC13', 'cohorts' => ['DHM 3-1'], 'group_name' => 'DHM 3-1', 'modality' => $f2f],

            ['code' => 'BME012-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME012', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'BME013-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME013', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'BME015-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME015', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $online],
            ['code' => 'BME016-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME016', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'BME017-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'BME017', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],
            ['code' => 'COOP101-DBM31', 'owner_program' => 'DBM', 'year_level' => 'Third Year', 'course' => 'COOP101', 'cohorts' => ['DBM 3-1'], 'group_name' => 'DBM 3-1', 'modality' => $f2f],

            ['code' => 'SIA101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'SIA101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'IAS102-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'IAS102', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'SP101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'SP101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $online],
            ['code' => 'VGD101-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'VGD101', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'WEB102-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'WEB102', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
            ['code' => 'CAP102-DIT31', 'owner_program' => 'DIT', 'year_level' => 'Third Year', 'course' => 'CAP102', 'cohorts' => ['DIT 3-1'], 'group_name' => 'DIT 3-1', 'modality' => $f2f],
        ];
    }
}
