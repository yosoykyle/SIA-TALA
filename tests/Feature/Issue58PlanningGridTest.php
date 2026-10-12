<?php

namespace Tests\Feature;

use App\Actions\Calendar\TermTeachingGrid;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\CalendarEvent;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\FacultyQualification;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue58PlanningGridTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('unavailableCanonicalGrids')]
    public function test_fresh_solver_snapshot_rejects_unavailable_canonical_grid_despite_legacy_calendar(string $condition): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $context = $this->source();
        $originalHash = $context['run']->input_hash;
        app(GenerateSchedulingDemand::class)->forTerm($context['registrar'], $context['term']);
        CalendarEvent::factory()->for($context['term'])->create([
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        if ($condition === 'missing') {
            $context['package']->teachingGridRows()->delete();
            $context['package']->delete();
        } elseif ($condition === 'closed') {
            $context['package']->update(['state' => TermCalendarPackage::StateClosed]);
        } else {
            $context['package']->teachingGridRows()->delete();
        }

        try {
            app(ScheduleSolverSnapshotService::class)->captureForRun($context['run']);
            $this->fail('A fresh solver snapshot requires the active canonical teaching grid.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'term_calendar_package' => ['An active term calendar package with an approved teaching grid is required before solver dispatch.'],
            ], $exception->errors());
        }

        $this->assertSame([], $context['run']->fresh()->input_snapshot);
        $this->assertSame($originalHash, $context['run']->fresh()->input_hash);
    }

    /** @return array<string, array{string}> */
    public static function unavailableCanonicalGrids(): array
    {
        return [
            'missing package' => ['missing'],
            'closed package' => ['closed'],
            'active package without grid' => ['empty'],
        ];
    }

    public function test_core_only_planning_emits_the_approved_per_day_grid_with_thirty_minute_slots(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $context = $this->source();
        $grid = app(TermTeachingGrid::class);
        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($context['term']);

        $this->assertTrue($readiness['is_ready']);
        $this->assertSame([], $readiness['missing_term_fields']);
        $this->assertSame(30, $grid->slotMinutes($context['term']));
        $this->assertSame([1, 2], array_column($grid->rows($context['term']), 'day_of_week'));
        $summary = app(GenerateSchedulingDemand::class)->forTerm($context['registrar'], $context['term']);
        $this->assertSame(1, $summary['ready']);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($context['run']);

        $this->assertSame(30, $snapshot['term']['scheduling_slot_minutes']);
        $this->assertSame(30, $snapshot['optimization_settings']['slot_granularity_minutes']);
        $this->assertSame([1, 2], $snapshot['term']['scheduling_days']);
        $this->assertSame([
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00'],
            ['day_of_week' => 2, 'starts_at' => '13:00:00', 'ends_at' => '16:00:00'],
        ], $snapshot['term']['teaching_grid_rows']);
        $this->assertSame(['08:00:00', '08:30:00', '09:00:00', '09:30:00'], collect($snapshot['time_slots'])->where('day_of_week', 1)->pluck('starts_at')->all());
        $this->assertSame(['13:00:00', '13:30:00', '14:00:00', '14:30:00', '15:00:00', '15:30:00'], collect($snapshot['time_slots'])->where('day_of_week', 2)->pluck('starts_at')->all());

        $captured = $context['run']->fresh()->input_snapshot;
        $capturedHash = $context['run']->fresh()->input_hash;
        $context['package']->update(['state' => TermCalendarPackage::StateClosed]);
        $successor = TermCalendarPackage::factory()->for($context['term'])->create(['state' => TermCalendarPackage::StateActive, 'version' => 2]);
        $successor->teachingGridRows()->create(['day_of_week' => 3, 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'breaks' => []]);
        $this->assertSame($captured, app(ScheduleSolverSnapshotService::class)->captureForRun($context['run']->fresh()));
        $this->assertSame($capturedHash, $context['run']->fresh()->input_hash);
    }

    public function test_fixed_demand_and_assignment_validation_use_the_selected_days_own_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $context = $this->source();
        app(GenerateSchedulingDemand::class)->forTerm($context['registrar'], $context['term']);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($context['run']);
        $demand = SchedulingDemand::query()->where('term_offering_id', $context['offering']->id)->sole();
        $slot = collect($snapshot['time_slots'])->firstWhere('day_of_week', 2);
        $capturedDemand = $snapshot['scheduling_demands'][0];
        $assignment = [
            'scheduling_demand_id' => $demand->id,
            'term_offering_id' => $capturedDemand['term_offering_id'],
            'section_id' => $capturedDemand['section_id'],
            'section_delivery_group_id' => $capturedDemand['section_delivery_group_id'],
            'subject_id' => $capturedDemand['course_id'],
            'course_component_id' => $capturedDemand['course_component_id'],
            'meeting_sequence' => 1,
            'faculty_user_id' => $context['faculty']->id,
            'faculty_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'day_of_week' => 2,
            'day' => 2,
            'starts_at' => '13:00:00',
            'start_time' => '13:00:00',
            'ends_at' => '14:00:00',
            'end_time' => '14:00:00',
            'time_slot_id' => $slot['time_slot_id'],
            'time_block_key' => $slot['time_block_key'],
            'time_block_reference' => $slot['time_block_key'],
            'assignment_status' => 'ok',
            'violations' => [],
            'warnings' => [],
            'scores' => [],
        ];
        $validator = app(ScheduleAssignmentValidationService::class);
        $valid = $validator->validateCandidateAssignments($context['run'], $snapshot, [$assignment]);
        $this->assertTrue($valid->passes(), json_encode($valid->findings(), JSON_THROW_ON_ERROR));
        $assignment['starts_at'] = '08:00:00';
        $assignment['start_time'] = '08:00:00';
        $assignment['ends_at'] = '09:00:00';
        $assignment['end_time'] = '09:00:00';
        $invalid = $validator->validateCandidateAssignments($context['run'], $snapshot, [$assignment]);
        $this->assertContains('assignment_outside_operating_grid', array_column($invalid->blockingFindings(), 'code'));

        $context['group']->update(['delivery_override' => ['fixed_day_of_week' => 2, 'fixed_start_time' => '08:00:00']]);
        app(GenerateSchedulingDemand::class)->forTerm($context['registrar'], $context['term']);
        $this->assertContains('fixed_start_outside_scheduling_grid', $demand->fresh()->readinessFindingKeys());
        $context['group']->update(['delivery_override' => ['fixed_day_of_week' => 2, 'fixed_start_time' => '13:00:00']]);
        app(GenerateSchedulingDemand::class)->forTerm($context['registrar'], $context['term']);
        $this->assertSame([], $demand->fresh()->readinessFindingKeys());
    }

    public function test_fixed_demand_respects_the_approved_package_break_without_a_legacy_event(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $context = $this->source();
        $gridRow = $context['package']->teachingGridRows()->where('day_of_week', 2)->firstOrFail();
        $gridRow->update([
            'breaks' => [['starts_at' => '14:00:00', 'ends_at' => '14:30:00']],
        ]);
        $context['group']->update(['delivery_override' => ['fixed_day_of_week' => 2, 'fixed_start_time' => '14:00:00']]);

        app(GenerateSchedulingDemand::class)->forTerm($context['registrar'], $context['term']);

        $demand = SchedulingDemand::query()->where('term_offering_id', $context['offering']->id)->sole();
        $this->assertContains('fixed_time_conflicts_with_calendar_block', $demand->readinessFindingKeys());
        $finding = collect($demand->readiness_findings)->firstWhere('key', 'fixed_time_conflicts_with_calendar_block');
        $this->assertSame('term_teaching_grid_row', $finding['source_type']);
        $this->assertSame($gridRow->id, $finding['source_id']);
        $this->assertSame(SchedulingDemand::ValidationActionRequired, $demand->validation_state);
        $context['group']->update(['delivery_override' => ['fixed_day_of_week' => 2, 'fixed_start_time' => '13:00:00']]);
        app(GenerateSchedulingDemand::class)->forTerm($context['registrar'], $context['term']);
        $this->assertSame([], $demand->fresh()->readinessFindingKeys());
    }

    /** @return array{term: Term, package: TermCalendarPackage, offering: TermOffering, group: SectionDeliveryGroup, run: ScheduleGenerationRun, registrar: User, faculty: User, room: Room} */
    private function source(): array
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleFaculty] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $term = Term::factory()->create(['state' => Term::StateActive, 'scheduling_slot_minutes' => 60, 'scheduling_days' => [6], 'scheduling_day_starts_at' => '07:00:00', 'scheduling_day_ends_at' => '21:00:00']);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'faculty_availability_due_at' => null]);
        $package->teachingGridRows()->createMany([
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00', 'breaks' => []],
            ['day_of_week' => 2, 'starts_at' => '13:00:00', 'ends_at' => '16:00:00', 'breaks' => []],
        ]);
        $specification = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityFaceToFace]]);
        $component = CourseComponent::factory()->for($specification)->create(['component_type' => CourseComponent::TypeLecture, 'weekly_contact_hours' => 1, 'meeting_pattern' => null, 'room_type_default' => Room::TypeLectureRoom]);
        $entry = CurriculumEntry::factory()->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StatePendingScheduling, 'modality' => TermOffering::ModalityFaceToFace]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['capacity' => 10, 'state' => Section::StatePlanned]);
        $group = SectionDeliveryGroup::factory()->for($section)->create(['expected_count' => 10, 'modality' => TermOffering::ModalityFaceToFace, 'state' => SectionDeliveryGroup::StateReady]);
        FacultyQualification::factory()->for($faculty, 'faculty')->create(['course_id' => $specification->course_id, 'is_active' => true]);
        $room = Room::factory()->create(['room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);
        $run = ScheduleGenerationRun::factory()->for($term)->create(['input_snapshot' => [], 'status' => ScheduleGenerationRun::StatusQueued]);

        return compact('term', 'package', 'offering', 'group', 'run', 'registrar', 'faculty', 'room');
    }
}
