<?php

namespace Database\Seeders;

use App\Actions\AcademicSetup\ActivateProgramAuthority;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\ProgramAuthority;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class Issue58TimetableExplorationSeeder extends Seeder
{
    public const Prefix = 'SYNTH58-UX';

    public function run(): void
    {
        $this->assertSafe();
        $registrar = User::query()->where('email', 'issue58-registrar-20261009@example.test')->firstOrFail();
        $terms = $this->ensure($registrar);
        $this->command?->info(json_encode($terms, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, int> */
    public function ensure(User $registrar, string $prefix = self::Prefix): array
    {
        $this->assertSafe();
        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new RuntimeException('Timetable exploration requires an existing Registrar actor.');
        }

        $mailer = Mail::getFacadeRoot();
        Mail::fake();
        try {
            return DB::transaction(function () use ($registrar, $prefix): array {
                $labels = [
                    'populated' => $prefix.' — Populated timetable',
                    'core_only' => $prefix.' — Core-only Special Term',
                    'draft' => $prefix.' — Draft needs correction',
                ];
                $existing = Term::query()->whereIn('label', $labels)->lockForUpdate()->get()->keyBy('label');
                if ($existing->isNotEmpty()) {
                    if ($existing->count() !== count($labels)) {
                        throw new RuntimeException('Incomplete retained review scenario; inspect it without resetting data.');
                    }

                    return array_map(fn (string $label): int => (int) $existing[$label]->id, $labels);
                }

                $year = AcademicYear::query()->where('label', '2026-2027')->first()
                    ?? AcademicYear::factory()->create(['label' => '2026-2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
                $program = Program::factory()->create(['code' => $prefix, 'name' => 'Synthetic timetable review program', 'is_active' => true]);
                $authority = ProgramAuthority::factory()->for($program)->create([
                    'authority_reference' => $prefix.'-PROGRAM', 'curriculum_source_reference' => $prefix.'-CURRICULUM',
                    'recorded_by' => $registrar->id,
                ]);
                app(ActivateProgramAuthority::class)->execute($authority, $registrar);
                $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
                $faculty = [];
                foreach (['Alex', 'Blair', 'Casey'] as $index => $name) {
                    $person = User::factory()->create([
                        'first_name' => 'Synthetic', 'last_name' => 'Faculty '.$name,
                        'name' => 'Synthetic Faculty '.$name,
                        'email' => strtolower($prefix).'-faculty-'.$index.'@example.test',
                        'status' => User::StatusActive,
                    ]);
                    $person->assignRole(User::StaffRoleFaculty);
                    $faculty[] = $person;
                }
                $rooms = [
                    Room::factory()->create(['code' => $prefix.'-L1', 'name' => 'Synthetic Lecture Room', 'capacity' => 40, 'room_type' => Room::TypeLectureRoom, 'is_active' => true]),
                    Room::factory()->create(['code' => $prefix.'-LAB', 'name' => 'Synthetic Computer Laboratory', 'capacity' => 30, 'room_type' => Room::TypeComputerLaboratory, 'is_active' => true]),
                ];
                $definitions = [
                    ['title' => 'Programming fundamentals', 'pattern' => '2x90', 'hours' => 3, 'modality' => TermOffering::ModalityFaceToFace],
                    ['title' => 'Database concepts', 'pattern' => '2x90', 'hours' => 3, 'modality' => TermOffering::ModalityFaceToFace],
                    ['title' => 'Communication skills', 'pattern' => '2x60', 'hours' => 2, 'modality' => TermOffering::ModalityOnline],
                ];
                $specifications = [];
                foreach ($definitions as $index => $definition) {
                    $course = Course::factory()->create(['code' => $prefix.'-C'.($index + 1)]);
                    $specification = CourseSpecification::factory()->for($course)->create([
                        'title' => $definition['title'], 'description' => 'Synthetic review data; not institutional curriculum authority.',
                        'revision_code' => $prefix.'-REV'.($index + 1), 'state' => CourseSpecification::StateActive,
                        'authority_reference' => $prefix.'-COURSE'.($index + 1), 'effective_from' => '2026-06-01',
                        'credit_units' => $definition['hours'],
                    ]);
                    CourseComponent::factory()->for($specification)->create([
                        'weekly_contact_hours' => $definition['hours'], 'meeting_pattern' => $definition['pattern'],
                        'component_type' => $index === 1 ? CourseComponent::TypeLaboratory : CourseComponent::TypeLecture,
                        'room_type_default' => $index === 1 ? Room::TypeComputerLaboratory : Room::TypeLectureRoom,
                    ]);
                    FacultyQualification::factory()->for($faculty[$index], 'faculty')->for($course)->create(['is_active' => true]);
                    $specifications[] = $specification;
                }

                $populated = $this->term($year, $labels['populated'], Term::TypeFirstSemester);
                $core = $this->calendar($populated, $registrar, $prefix, true);
                $this->classes($populated, $core, $program, $curriculum, $specifications, $definitions, $faculty, $registrar, $prefix.'-MAIN');
                $published = $this->candidate($populated, $registrar, $rooms, $prefix.'-PUBLISHED', false);
                $accepted = app(ReviewTimetableCandidate::class)->accept($published, $registrar, 'Synthetic review fixture; no solver optimization was run.');
                app(SchedulePublishService::class)->publish($accepted, $registrar, 'Synthetic timetable for visual review only.', $prefix.'-SYNTHETIC-SIGNOFF');
                $this->candidate($populated, $registrar, $rooms, $prefix.'-BLOCKED', true);
                $this->candidate($populated, $registrar, $rooms, $prefix.'-CANDIDATE', false, 30);

                $special = $this->term($year, $labels['core_only'], Term::TypeSpecialTerm);
                $specialCore = $this->calendar($special, $registrar, $prefix.'-SPECIAL', false);
                $this->classes($special, $specialCore, $program, $curriculum, $specifications, $definitions, $faculty, $registrar, $prefix.'-SPECIAL');

                $draft = $this->term($year, $labels['draft'], Term::TypeSecondSemester);
                TermCalendarPackage::factory()->for($draft)->create(['recorded_by' => $registrar->id, 'authority_reference' => '', 'faculty_availability_due_at' => null]);

                return ['populated' => (int) $populated->id, 'core_only' => (int) $special->id, 'draft' => (int) $draft->id];
            });
        } finally {
            Mail::swap($mailer);
        }
    }

    protected function assertSafe(): void
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'test_tala_db'
            || ($connection->selectOne('SELECT DATABASE() AS name')->name ?? null) !== 'test_tala_db'
            || config('mail.default') !== 'array' || config('queue.default') !== 'sync') {
            throw new RuntimeException('Timetable review seed requires testing/mysql/test_tala_db, array mail and sync queue.');
        }
    }

    protected function term(AcademicYear $year, string $label, string $type): Term
    {
        return Term::factory()->for($year)->create([
            'label' => $label, 'type' => $type, 'starts_on' => '2026-06-01', 'ends_on' => '2026-12-31',
            'state' => Term::StateDraft,
        ]);
    }

    protected function calendar(Term $term, User $registrar, string $prefix, bool $withWindows): TermCalendarPackage
    {
        $versions = $withWindows ? [1, 2] : [1];
        foreach ($versions as $version) {
            $package = TermCalendarPackage::factory()->for($term)->create([
                'version' => $version, 'recorded_by' => $registrar->id,
                'faculty_availability_due_at' => null,
                'authority_reference' => $prefix.'-CALENDAR-V'.$version,
                'special_term_schedule_basis' => $term->type === Term::TypeSpecialTerm ? 'Synthetic approved Special Term review basis.' : null,
            ]);
            foreach ($term->type === Term::TypeSpecialTerm ? [2, 4] : [1, 2, 3, 4, 5] as $day) {
                TermTeachingGridRow::factory()->for($package, 'package')->create(['day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00']);
            }
            if ($withWindows && $version === 2) {
                foreach ([
                    'Enrollment' => ['2026-09-01', '2026-10-31'],
                    'ExaminationPeriod' => ['2026-11-01', '2026-11-07'],
                    'GradeEntry' => ['2026-11-08', '2026-12-15'],
                ] as $type => [$opens, $closes]) {
                    TermCalendarWindow::factory()->for($package, 'package')->create(['window_type' => $type, 'opens_on' => $opens, 'closes_on' => $closes]);
                }
            }
            $package = app(ActivateTermCalendarPackage::class)->execute($package, $registrar);
        }
        if ($withWindows) {
            app(RecordFacultyAvailabilityDeadline::class)->execute($term, $registrar, '2026-10-31 17:00:00', $prefix.'-FACULTY-DEADLINE', null, 0);
        }

        $term->refresh();

        return $package;
    }

    /**
     * @param  list<CourseSpecification>  $specifications
     * @param  list<array{title:string,pattern:string,hours:int,modality:string}>  $definitions
     * @param  list<User>  $faculty
     */
    private function classes(Term $term, TermCalendarPackage $package, Program $program, CurriculumVersion $curriculum, array $specifications, array $definitions, array $faculty, User $registrar, string $prefix): void
    {
        $cohort = TermCohort::factory()->create([
            'term_id' => $term->id, 'program_id' => $program->id, 'curriculum_version_id' => $curriculum->id,
            'reference' => $prefix.'-COHORT', 'confirmed_count' => 24, 'confirmed_by' => $registrar->id, 'confirmed_at' => now(),
        ]);
        foreach ($specifications as $index => $specification) {
            $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
                'term_type' => $term->type, 'term_label' => $term->label, 'sequence' => $index + 1,
            ]);
            $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['expected_count' => 24, 'modality' => $definitions[$index]['modality']]);
            $section = Section::factory()->for($offering, 'termOffering')->create([
                'code' => $prefix.'-CLASS'.($index + 1), 'class_reference' => $prefix.'-CLASS'.($index + 1),
                'term_calendar_package_id' => $package->id, 'course_specification_id' => $specification->id,
                'delivery_mode' => $definitions[$index]['modality'], 'capacity' => 30,
            ]);
            SectionDeliveryGroup::factory()->for($section)->create(['name' => 'Whole class', 'expected_count' => 24, 'modality' => $definitions[$index]['modality']]);
            app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 24]);
            app(RecordFacultyAvailabilityDeclaration::class)->execute($term, $faculty[$index], $faculty[$index], 'Available', []);
        }
        $summary = app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        if ($summary['ready'] !== count($specifications)) {
            throw new RuntimeException('Synthetic class sources are not ready: '.json_encode([
                'summary' => $summary,
                'findings' => SchedulingDemand::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))->pluck('readiness_findings'),
            ], JSON_THROW_ON_ERROR));
        }
    }

    /** @param list<Room> $rooms */
    private function candidate(Term $term, User $registrar, array $rooms, string $key, bool $blocked, int $offset = 0): ScheduleGenerationRun
    {
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id, 'requested_by' => $registrar->id, 'status' => ScheduleGenerationRun::StatusQueued,
            'input_snapshot' => ['capture' => 'pending'], 'input_hash' => hash('sha256', $key),
            'solver_version' => 'synthetic-review-no-solver', 'candidate_state' => 'Queued',
        ]);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $assignments = [];
        foreach ($snapshot['scheduling_demands'] as $index => $demand) {
            foreach (range(1, $demand['meeting_count']) as $sequence) {
                $day = $sequence === 1 ? 1 : 3;
                $startMinutes = 480 + $index * 120 + $offset;
                if ($startMinutes >= 720) {
                    $startMinutes += 60;
                }
                $starts = sprintf('%02d:%02d:00', intdiv($startMinutes, 60), $startMinutes % 60);
                $endMinutes = $startMinutes + $demand['required_duration_minutes'];
                $ends = sprintf('%02d:%02d:00', intdiv($endMinutes, 60), $endMinutes % 60);
                $slot = collect($snapshot['time_slots'])->first(fn (array $slot): bool => $slot['day_of_week'] === $day && $slot['starts_at'] === $starts);
                if (! is_array($slot)) {
                    throw new RuntimeException('Synthetic assignment must start on a captured grid slot.');
                }
                $assignments[] = [
                    ...Arr::only($demand, ['scheduling_demand_id', 'term_offering_id', 'section_id', 'section_delivery_group_id', 'course_component_id']),
                    'subject_id' => $demand['course_id'], 'faculty_user_id' => $demand['eligible_faculty_user_ids'][0],
                    'faculty_id' => $demand['eligible_faculty_user_ids'][0],
                    'room_id' => $demand['room_required'] ? $rooms[$index % count($rooms)]->id : null,
                    'day' => $day, 'day_of_week' => $day, 'starts_at' => $starts, 'ends_at' => $ends,
                    'start_time' => $starts, 'end_time' => $ends,
                    'time_slot_id' => $slot['time_slot_id'], 'time_block_key' => $slot['time_block_key'],
                    'time_block_reference' => $slot['time_block_key'],
                    'meeting_sequence' => $sequence, 'assignment_status' => 'ok', 'warnings' => [], 'scores' => [],
                ];
            }
        }
        if ($blocked) {
            $assignments[0]['day_of_week'] = 7;
            $assignments[0]['day'] = 7;
        }
        $validation = app(ScheduleAssignmentValidationService::class)->validateCandidateAssignments($run, $snapshot, $assignments);
        if ($validation->passes() === $blocked) {
            throw new RuntimeException(json_encode($validation->findings(), JSON_THROW_ON_ERROR));
        }
        if (! $blocked) {
            foreach ($validation->candidateRows() as $row) {
                CandidateScheduleRow::query()->create(['schedule_run_id' => $run->id, ...Arr::only($row, (new CandidateScheduleRow)->getFillable())]);
            }
        }
        $run->forceFill([
            'status' => $blocked ? ScheduleGenerationRun::StatusBlocked : ScheduleGenerationRun::StatusUnderReview,
            'solver_version' => 'synthetic-review-no-solver', 'candidate_key' => $key, 'candidate_version' => 1,
            'candidate_state' => $blocked ? 'Blocked' : 'UnderReview',
            'diagnostics' => ['synthetic_review' => true,
                'solver_dispatch' => ['failure' => $blocked ? [
                    'code' => 'SYNTHETIC_VALIDATION_BLOCKED',
                    'message' => 'A synthetic Sunday assignment was rejected by calendar validation. No solver was run.',
                    'retryable' => false, 'final' => true,
                ] : null],
                'solver_result' => [
                    'solver_status' => $blocked ? 'unknown' : 'feasible', 'candidate_schedule_id' => $key,
                    'summary' => $validation->summary(), 'findings' => $validation->findings(),
                    'warnings' => ['Synthetic review assignments. No solver or optimization was run.'],
                ]],
        ])->save();

        return $run->fresh();
    }
}
