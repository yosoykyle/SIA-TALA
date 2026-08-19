<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverRequest;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\RequestTimetableRepair;
use App\Actions\Scheduling\RevisePublishedTimetable;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ResourceUnavailability;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermDatedException;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Slice3ConformanceGapsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = Role::findOrCreate(User::StaffRoleRegistrar);
        $registrar->givePermissionTo(Permission::findOrCreate('manage-schedules'));
    }

    public function test_demand_generation_preserves_two_by_ninety_meeting_pattern(): void
    {
        $fixture = $this->classFixture('2x90', 3.0);

        app(GenerateSchedulingDemand::class)->forTerm($this->registrar(), $fixture['term']);

        $demand = SchedulingDemand::query()->where('course_component_id', $fixture['component']->id)->sole();
        $this->assertSame(2, $demand->meeting_count);
        $this->assertSame(90, $demand->required_duration_minutes);
        $this->assertSame('2x90', $demand->source_snapshot['meeting_pattern']);
    }

    public function test_verification_stub_preserves_every_meeting_sequence(): void
    {
        $snapshot = json_decode(
            file_get_contents(base_path('cloud/scheduler-solver/samples/minimal_snapshot.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $snapshot['scheduling_demands'][0]['meeting_count'] = 2;
        $snapshot['scheduling_demands'][0]['meeting_pattern'] = '2x60';
        $snapshot['time_slots'][] = [
            'time_slot_id' => 5,
            'time_block_key' => 'D1-1000',
            'day_of_week' => 1,
            'starts_at' => '10:00:00',
            'ends_at' => '10:30:00',
            'duration_minutes' => 30,
        ];
        $snapshot['time_slots'][] = [
            'time_slot_id' => 6,
            'time_block_key' => 'D1-1030',
            'day_of_week' => 1,
            'starts_at' => '10:30:00',
            'ends_at' => '11:00:00',
            'duration_minutes' => 30,
        ];

        $result = app(LocalStubSchedulingSolverClient::class)
            ->solve(new SchedulingSolverRequest($snapshot, 'slice3-conformance'))
            ->payload();
        $firstDemandAssignments = collect($result['assignments'])
            ->where('scheduling_demand_id', 5001)
            ->sortBy('meeting_sequence')
            ->values();

        $this->assertSame('feasible', $result['solver_status']);
        $this->assertSame(3, $result['assigned_count']);
        $this->assertSame([1, 2], $firstDemandAssignments->pluck('meeting_sequence')->all());
        $this->assertSame(['2x60'], $firstDemandAssignments->pluck('meeting_pattern')->unique()->values()->all());
    }

    public function test_class_confirmation_rejects_cross_term_and_nonpositive_cohorts_without_partial_sync(): void
    {
        $fixture = $this->classFixture('1x180', 3.0);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => Program::factory(),
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $fixture['specification']->id,
        ]);
        $crossTerm = TermCohort::factory()->create([
            'term_id' => Term::factory(),
            'program_id' => $curriculum->program_id,
            'curriculum_version_id' => $curriculum->id,
        ]);

        foreach ([0, 10] as $count) {
            try {
                app(ConfirmClassOffering::class)->execute(
                    $fixture['section'],
                    $this->registrar(),
                    [$crossTerm->id => $count],
                );
                $this->fail('Invalid cohort confirmation was persisted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cohorts', $exception->errors());
            }

            $this->assertSame(0, $fixture['section']->cohorts()->count());
        }
    }

    public function test_regular_shared_and_authority_backed_additional_classes_preserve_sources_and_capacity(): void
    {
        $registrar = $this->registrar();
        $regular = $this->classFixture('1x60', 1.0);
        $regular['section']->update([
            'source' => Section::SourceRegular,
            'capacity' => 12,
        ]);
        $regularCohort = $this->cohortForFixture($regular, 12);

        app(ConfirmClassOffering::class)->execute($regular['section'], $registrar, [$regularCohort->id => 12]);

        $shared = $this->classFixture('1x60', 1.0);
        $shared['section']->update([
            'source' => Section::SourceShared,
            'capacity' => 18,
        ]);
        $sharedCohorts = [
            $this->cohortForFixture($shared, 10),
            $this->cohortForFixture($shared, 8),
        ];

        app(ConfirmClassOffering::class)->execute($shared['section'], $registrar, [
            $sharedCohorts[0]->id => 10,
            $sharedCohorts[1]->id => 8,
        ]);

        $additional = $this->classFixture('1x60', 1.0);
        $additional['section']->update([
            'source' => Section::SourceAdditional,
            'capacity' => 15,
            'authority_reference' => null,
        ]);
        $additionalCohort = $this->cohortForFixture($additional, 15);

        try {
            app(ConfirmClassOffering::class)->execute($additional['section'], $registrar, [$additionalCohort->id => 15]);
            $this->fail('An Additional Class Offering was confirmed without external authority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_reference', $exception->errors());
        }

        $this->assertSame(0, $additional['section']->cohorts()->count());
        $confirmedAdditional = app(ConfirmClassOffering::class)->execute(
            $additional['section'],
            $registrar,
            [$additionalCohort->id => 15],
            'SYNTH-ADDITIONAL-CLASS-AUTHORITY',
        );

        $this->assertSame(Section::SourceRegular, $regular['section']->fresh()->source);
        $this->assertSame(12, $this->confirmedCohortCount($regular['section']));
        $this->assertSame(Section::SourceShared, $shared['section']->fresh()->source);
        $this->assertSame(18, $this->confirmedCohortCount($shared['section']));
        $this->assertSame(Section::SourceAdditional, $confirmedAdditional->source);
        $this->assertSame('SYNTH-ADDITIONAL-CLASS-AUTHORITY', $confirmedAdditional->authority_reference);
    }

    public function test_section_commitment_is_demand_scoped_and_dated_exceptions_are_not_recurring_blocks(): void
    {
        $fixture = $this->classFixture('1x60', 1.0);
        $room = Room::factory()->create();
        $demand = SchedulingDemand::factory()
            ->for($fixture['offering'])
            ->for($fixture['component'])
            ->for($fixture['group'])
            ->create([
                'required_duration_minutes' => 60,
                'meeting_count' => 1,
            ]);
        SchedulingCommitment::factory()->create([
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
        ]);
        TermDatedException::factory()->for($fixture['package'], 'package')->create([
            'starts_on' => '2026-08-24',
            'ends_on' => '2026-08-24',
            'blocks_teaching' => true,
        ]);
        ResourceUnavailability::factory()->create([
            'term_id' => $fixture['term']->id,
            'effective_on' => '2026-08-25',
            'day_of_week' => null,
        ]);
        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $fixture['term']->id,
            'input_snapshot' => [
                'scheduling_demands' => [['scheduling_demand_id' => $demand->id]],
            ],
        ]);

        $snapshot = app(ScheduleSolverSnapshotService::class)->currentForRun($run);
        $payload = collect($snapshot['scheduling_demands'])->sole();

        $this->assertSame($room->id, $payload['fixed_room_id']);
        $this->assertSame(1, $payload['fixed_day_of_week']);
        $this->assertSame('08:00:00', $payload['fixed_start_time']);
        $this->assertFalse(collect($snapshot['calendar_blocks'])->contains(
            fn (array $block): bool => in_array($block['event_type'] ?? null, ['SchedulingCommitment', 'DatedTeachingException'], true),
        ));
        $this->assertCount(2, $snapshot['dated_exceptions']);
    }

    public function test_repair_request_captures_the_complete_source_preview_and_blocks_a_duplicate_active_run(): void
    {
        Queue::fake();
        $registrar = $this->registrar();
        $fixture = $this->classFixture('1x60', 1.0);
        $faculty = User::factory()->create();
        $room = Room::factory()->create();
        $demand = SchedulingDemand::factory()
            ->for($fixture['offering'])
            ->for($fixture['component'])
            ->for($fixture['group'])
            ->create(['required_duration_minutes' => 60]);
        $snapshot = json_decode(
            file_get_contents(base_path('cloud/scheduler-solver/samples/minimal_snapshot.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $snapshot['scheduling_demands'] = [[
            ...$snapshot['scheduling_demands'][0],
            'scheduling_demand_id' => $demand->id,
            'term_offering_id' => $fixture['offering']->id,
            'section_id' => $fixture['section']->id,
            'section_delivery_group_id' => $fixture['group']->id,
            'cohort_or_student_group_id' => $fixture['group']->id,
            'cohort_or_student_group_ids' => [$fixture['group']->id],
            'course_component_id' => $fixture['component']->id,
        ]];
        $sourceRun = ScheduleGenerationRun::factory()->create([
            'term_id' => $fixture['term']->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'input_snapshot' => $snapshot,
            'candidate_version' => 1,
            'candidate_state' => 'UnderReview',
        ]);
        $requestedRow = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $sourceRun->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'time_block_key' => 'D1-0800',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        $repair = app(RequestTimetableRepair::class)->execute(
            $requestedRow,
            $registrar,
            [
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
            ],
            'Find a complete valid successor around this requested meeting.',
            'SYNTH-REPAIR-AUTHORITY',
        );

        $this->assertSame('RepairQueued', $repair->candidate_state);
        $this->assertSame('repair', $repair->input_snapshot['operation']['kind']);
        $this->assertCount(1, $repair->input_snapshot['operation']['source_candidate']['assignments']);
        $this->assertSame($requestedRow->id, $repair->input_snapshot['operation']['source_candidate']['assignments'][0]['candidate_row_id']);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $sourceRun->fresh()->status);
        Queue::assertPushed(ScheduleSolverDispatchJob::class, 1);

        try {
            app(RequestTimetableRepair::class)->execute(
                $requestedRow,
                $registrar,
                [
                    'faculty_user_id' => $faculty->id,
                    'room_id' => $room->id,
                    'day_of_week' => 3,
                    'starts_at' => '09:00:00',
                    'ends_at' => '10:00:00',
                ],
                'Attempt a duplicate active repair.',
                'SYNTH-REPAIR-AUTHORITY-2',
            );
            $this->fail('A duplicate active repair run was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_id', $exception->errors());
        }

        $this->assertSame(2, ScheduleGenerationRun::query()->where('term_id', $fixture['term']->id)->count());
    }

    public function test_legacy_published_backfill_is_idempotent_and_preserves_version_succession(): void
    {
        $fixture = $this->classFixture('1x60', 1.0);
        $registrar = $this->registrar();
        $room = Room::factory()->create();
        $faculty = User::factory()->create();
        $demand = SchedulingDemand::factory()
            ->for($fixture['offering'])
            ->for($fixture['component'])
            ->for($fixture['group'])
            ->create(['required_duration_minutes' => 60]);
        $runs = collect([
            ['status' => ScheduleGenerationRun::StatusSuperseded, 'published_at' => now()->subHour(), 'publication_version' => 1],
            ['status' => ScheduleGenerationRun::StatusPublished, 'published_at' => now(), 'publication_version' => 2],
        ])->map(function (array $state) use ($fixture, $registrar, $demand, $faculty, $room): ScheduleGenerationRun {
            $run = ScheduleGenerationRun::factory()->create([
                ...$state,
                'term_id' => $fixture['term']->id,
                'published_by' => $registrar->id,
            ]);
            SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => 1,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '09:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionMeeting::StateActive,
                'published_at' => $state['published_at'],
            ]);

            return $run;
        });
        $migration = require database_path('migrations/2026_08_18_222040_backfill_published_timetable_baselines.php');

        $migration->up();
        $migration->up();

        $versions = PublishedTimetableVersion::query()
            ->whereIn('schedule_run_id', $runs->pluck('id'))
            ->with('meetings')
            ->orderBy('version')
            ->get();
        $this->assertCount(2, $versions);
        $this->assertSame($versions[0]->id, $versions[1]->supersedes_version_id);
        $this->assertSame($versions[0]->meetings->sole()->id, $versions[1]->meetings->sole()->supersedes_meeting_id);
        $this->assertSame(2, PublishedTimetableMeeting::query()->whereIn('published_timetable_version_id', $versions->modelKeys())->count());
    }

    public function test_scheduling_treatment_backfill_derives_only_component_backed_legacy_rows(): void
    {
        $recurring = CourseSpecification::factory()->create(['scheduling_treatment' => null]);
        CourseComponent::factory()->for($recurring)->create();
        $incomplete = CourseSpecification::factory()->create(['scheduling_treatment' => null]);
        $migration = require database_path('migrations/2026_08_18_233005_backfill_course_specification_scheduling_treatments.php');

        $migration->up();
        $migration->up();

        $this->assertSame(CourseSpecification::SchedulingRecurring, $recurring->fresh()->scheduling_treatment);
        $this->assertNull($incomplete->fresh()->scheduling_treatment);
        $this->assertSame(0, $incomplete->components()->count());
    }

    public function test_direct_revision_cannot_remove_a_meeting_outside_whole_section_cancellation(): void
    {
        $registrar = $this->registrar();
        $version = PublishedTimetableVersion::factory()->create([
            'published_by' => $registrar->id,
        ]);
        $meeting = PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id,
        ]);

        try {
            app(RevisePublishedTimetable::class)->execute(
                $version,
                $registrar,
                [$meeting->id => ['remove' => true]],
                'SYNTH-CANCELLATION-AUTHORITY',
                'Attempted direct removal.',
            );
            $this->fail('Direct meeting removal bypassed whole-Section cancellation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }

        $this->assertSame(PublishedTimetableVersion::StatePublished, $version->fresh()->state);
        $this->assertSame(1, $version->meetings()->count());
    }

    /** @return array{term:Term,package:TermCalendarPackage,specification:CourseSpecification,component:CourseComponent,offering:TermOffering,section:Section,group:SectionDeliveryGroup} */
    private function classFixture(string $meetingPattern, float $weeklyHours): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->create([
            'term_id' => $term->id,
            'state' => TermCalendarPackage::StateActive,
        ]);
        $specification = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive]);
        $component = CourseComponent::factory()->for($specification)->create([
            'weekly_contact_hours' => $weeklyHours,
            'meeting_pattern' => $meetingPattern,
        ]);
        $entry = CurriculumEntry::factory()->create(['course_specification_id' => $specification->id]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $specification->id,
            'capacity' => 40,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 20,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        return compact('term', 'package', 'specification', 'component', 'offering', 'section', 'group');
    }

    /** @param array{term:Term,package:TermCalendarPackage,specification:CourseSpecification,component:CourseComponent,offering:TermOffering,section:Section,group:SectionDeliveryGroup} $fixture */
    private function cohortForFixture(array $fixture, int $count): TermCohort
    {
        $curriculum = $fixture['offering']->curriculumEntry->curriculumVersion;
        $curriculum->update(['state' => CurriculumVersion::StateActive]);

        return TermCohort::factory()->create([
            'term_id' => $fixture['term']->id,
            'program_id' => $curriculum->program_id,
            'curriculum_version_id' => $curriculum->id,
            'forecast_count' => $count,
            'confirmed_count' => $count,
        ]);
    }

    private function confirmedCohortCount(Section $section): int
    {
        return $section->cohorts()->get()->sum(
            fn (TermCohort $cohort): int => (int) $cohort->pivot?->expected_count,
        );
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
