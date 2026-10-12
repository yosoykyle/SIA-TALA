<?php

namespace Database\Seeders;

use App\Actions\AcademicSetup\ActivateProgramAuthority;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
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
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class Issue58MultiProgramExplorationSeeder extends Issue58TimetableExplorationSeeder
{
    public const Prefix = 'SYNTH58-MULTI';

    /** @return array{term_id:int,draft_run_id:int,published_version_id:int} */
    public function ensure(User $registrar, string $prefix = self::Prefix): array
    {
        $this->assertSafe();
        if (! str_starts_with($prefix, self::Prefix) || ! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new RuntimeException('Multi-program exploration requires its synthetic prefix and an existing Registrar.');
        }
        $mailer = Mail::getFacadeRoot();
        $queue = Queue::getFacadeRoot();
        Mail::fake();
        Queue::fake();
        try {
            return DB::transaction(function () use ($registrar, $prefix): array {
                $label = $prefix.' — Hypothetical HR / Education / Math';
                $existing = Term::query()->where('label', $label)->lockForUpdate()->first();
                if ($existing) {
                    $draft = ScheduleGenerationRun::query()->where('term_id', $existing->id)->where('status', ScheduleGenerationRun::StatusUnderReview)->sole();
                    $version = PublishedTimetableVersion::query()->where('term_id', $existing->id)->where('state', PublishedTimetableVersion::StatePublished)->sole();

                    return ['term_id' => $existing->id, 'draft_run_id' => $draft->id, 'published_version_id' => $version->id];
                }
                $year = AcademicYear::factory()->create(['label' => $prefix.' — Synthetic AY', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
                $term = $this->term($year, $label, Term::TypeFirstSemester);
                $package = $this->calendar($term, $registrar, $prefix, true);
                $faculty = [];
                foreach (['Alex', 'Blair', 'Casey'] as $index => $name) {
                    $person = User::factory()->create(['name' => 'Synthetic Multi Faculty '.$name, 'first_name' => 'Synthetic Multi', 'last_name' => $name, 'email' => strtolower($prefix).'-faculty-'.$index.'@example.test', 'status' => User::StatusActive]);
                    $person->assignRole(User::StaffRoleFaculty);
                    app(RecordFacultyAvailabilityDeclaration::class)->execute($term, $person, $person, 'Available', []);
                    $faculty[] = $person;
                }
                $rooms = [Room::factory()->create(['code' => $prefix.'-RM1', 'name' => 'Synthetic shared lecture room', 'capacity' => 60, 'room_type' => Room::TypeLectureRoom, 'is_active' => true]),
                    Room::factory()->create(['code' => $prefix.'-RM2', 'name' => 'Synthetic second lecture room', 'capacity' => 60, 'room_type' => Room::TypeLectureRoom, 'is_active' => true])];
                $curricula = [];
                $cohorts = [];
                $classes = [];
                $revisions = [];
                foreach (['HR', 'Education', 'Math'] as $programIndex => $programName) {
                    $program = Program::factory()->create(['code' => $prefix.'-P'.$programIndex, 'name' => 'Hypothetical '.$programName.' — synthetic review', 'is_active' => true]);
                    $authority = ProgramAuthority::factory()->for($program)->create(['authority_reference' => $prefix.'-SYNTHETIC-PROGRAM-'.$programIndex, 'curriculum_source_reference' => $prefix.'-HYPOTHETICAL-CURRICULUM-'.$programIndex, 'recorded_by' => $registrar->id]);
                    app(ActivateProgramAuthority::class)->execute($authority, $registrar);
                    $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive, 'name' => 'Hypothetical '.$programName.' curriculum', 'version_code' => $prefix.'-V1']);
                    $curricula[$programName] = $curriculum;
                    foreach (['First Year', 'Second Year', 'Third Year'] as $yearIndex => $yearLevel) {
                        $key = $programName.'-'.($yearIndex + 1);
                        $course = Course::factory()->create(['code' => $prefix.'-C'.$programIndex.($yearIndex + 1)]);
                        $revision = CourseSpecification::factory()->for($course)->create(['title' => 'Synthetic '.$programName.' year '.($yearIndex + 1).' course', 'description' => 'Hypothetical source for UI review; not Servitech curriculum authority.', 'revision_code' => $prefix.'-R'.$programIndex.($yearIndex + 1), 'state' => CourseSpecification::StateActive, 'authority_reference' => $prefix.'-SYNTHETIC-COURSE-'.$programIndex.($yearIndex + 1), 'effective_from' => '2026-06-01', 'credit_units' => 2]);
                        CourseComponent::factory()->for($revision)->create(['component_type' => CourseComponent::TypeLecture, 'weekly_contact_hours' => 2, 'meeting_pattern' => '2x60', 'room_type_default' => Room::TypeLectureRoom]);
                        FacultyQualification::factory()->for($faculty[$programIndex], 'faculty')->for($course)->create(['is_active' => true]);
                        $entry = CurriculumEntry::factory()->for($curriculum)->for($revision, 'courseSpecification')->create(['year_level' => $yearLevel, 'term_type' => $term->type, 'term_label' => $term->label, 'sequence' => $yearIndex + 1]);
                        $cohort = TermCohort::factory()->for($term)->for($curriculum)->create(['program_id' => $program->id, 'reference' => $prefix.'-'.$key.'A', 'confirmed_count' => 18, 'forecast_count' => null, 'confirmed_by' => $registrar->id]);
                        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['expected_count' => 18]);
                        $class = Section::factory()->for($offering, 'termOffering')->create(['code' => $prefix.'-'.$key.'-CLASS', 'class_reference' => $prefix.'-'.$key.'-CLASS', 'term_calendar_package_id' => $package->id, 'course_specification_id' => $revision->id, 'delivery_mode' => TermOffering::ModalityFaceToFace, 'capacity' => 60]);
                        SectionDeliveryGroup::factory()->for($class, 'section')->create(['name' => 'Synthetic whole class', 'expected_count' => 18]);
                        $cohorts[$key] = $cohort;
                        $classes[$key] = $class;
                        $revisions[$key] = $revision;
                    }
                }
                CurriculumEntry::factory()->for($curricula['Math'])->for($revisions['HR-2'], 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type, 'term_label' => $term->label, 'sequence' => 4]);
                foreach ($classes as $key => $class) {
                    $counts = [$cohorts[$key]->id => 18];
                    if ($key === 'HR-2') {
                        $counts[$cohorts['Math-2']->id] = 18;
                        $class->termOffering->update(['expected_count' => 36]);
                        $class->deliveryGroups()->update(['expected_count' => 36]);
                    }
                    app(ConfirmClassOffering::class)->execute($class, $registrar, $counts);
                }
                $summary = app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
                if ($summary['ready'] !== 9 || $summary['action_required'] !== 0) {
                    throw new RuntimeException('Synthetic multi-program sources need correction: '.json_encode($summary, JSON_THROW_ON_ERROR));
                }
                $published = $this->syntheticCandidate($term, $registrar, $rooms, $prefix.'-OFFICIAL');
                $accepted = app(ReviewTimetableCandidate::class)->accept($published, $registrar, 'Synthetic manual arrangement. No solver or optimization was run.');
                app(SchedulePublishService::class)->publish($accepted, $registrar, 'Synthetic hypothetical timetable for owner review only.', $prefix.'-SYNTHETIC-SIGNOFF');
                $version = PublishedTimetableVersion::query()->where('term_id', $term->id)->sole();
                $draft = $this->syntheticCandidate($term, $registrar, $rooms, $prefix.'-DRAFT');

                return ['term_id' => $term->id, 'draft_run_id' => $draft->id, 'published_version_id' => $version->id];
            });
        } finally {
            Mail::swap($mailer);
            Queue::swap($queue);
        }
    }

    /** @param list<Room> $rooms */
    private function syntheticCandidate(Term $term, User $registrar, array $rooms, string $key): ScheduleGenerationRun
    {
        $run = ScheduleGenerationRun::query()->create(['term_id' => $term->id, 'requested_by' => $registrar->id, 'status' => ScheduleGenerationRun::StatusQueued, 'input_snapshot' => ['capture' => 'pending'], 'input_hash' => hash('sha256', $key), 'solver_version' => 'synthetic-review-no-solver', 'candidate_state' => 'Queued']);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $assignments = [];
        foreach ($snapshot['scheduling_demands'] as $index => $demand) {
            foreach (range(1, $demand['meeting_count']) as $sequence) {
                $day = ($index % 2) + ($sequence === 1 ? 1 : 3);
                $start = 480 + intdiv($index, 2) * 90;
                if ($start >= 720) {
                    $start += 60;
                }
                $end = $start + $demand['required_duration_minutes'];
                $starts = sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);
                $ends = sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60);
                $slot = collect($snapshot['time_slots'])->first(fn (array $slot): bool => $slot['day_of_week'] === $day && $slot['starts_at'] === $starts);
                if (! $slot) {
                    throw new RuntimeException('Synthetic meeting must start on a captured calendar slot.');
                }
                $assignments[] = [...Arr::only($demand, ['scheduling_demand_id', 'term_offering_id', 'section_id', 'section_delivery_group_id', 'course_component_id']), 'subject_id' => $demand['course_id'], 'faculty_user_id' => $demand['eligible_faculty_user_ids'][0], 'faculty_id' => $demand['eligible_faculty_user_ids'][0], 'room_id' => $rooms[$index % count($rooms)]->id, 'day' => $day, 'day_of_week' => $day, 'starts_at' => $starts, 'ends_at' => $ends, 'start_time' => $starts, 'end_time' => $ends, 'time_slot_id' => $slot['time_slot_id'], 'time_block_key' => $slot['time_block_key'], 'time_block_reference' => $slot['time_block_key'], 'meeting_sequence' => $sequence, 'assignment_status' => 'ok', 'warnings' => [], 'scores' => []];
            }
        }
        $validation = app(ScheduleAssignmentValidationService::class)->validateCandidateAssignments($run, $snapshot, $assignments);
        if (! $validation->passes()) {
            throw new RuntimeException('Synthetic multi-program assignment rejected: '.json_encode($validation->findings(), JSON_THROW_ON_ERROR));
        }
        foreach ($validation->candidateRows() as $row) {
            CandidateScheduleRow::query()->create(['schedule_run_id' => $run->id, ...Arr::only($row, (new CandidateScheduleRow)->getFillable())]);
        }
        $run->forceFill(['status' => ScheduleGenerationRun::StatusUnderReview, 'candidate_key' => $key, 'candidate_version' => 1, 'candidate_state' => 'UnderReview', 'runtime_ms' => null, 'objective_value' => null, 'quality_measures' => null, 'diagnostics' => ['synthetic_review' => true, 'solver_result' => ['solver_status' => 'feasible', 'candidate_schedule_id' => $key, 'summary' => $validation->summary(), 'findings' => $validation->findings(), 'warnings' => ['Synthetic manual arrangement. No solver or optimization was run.']]]])->save();

        return $run->fresh();
    }
}
