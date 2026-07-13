<?php

namespace Tests\Feature;

use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\RevisionEventsRelationManager;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94D3aRevisionUxTest extends TestCase
{
    use DatabaseTransactions;

    private int $contextCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'demo_tala_db',
            'tala_test_codex',
        ]);

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_revision_action_and_history_follow_role_and_published_state_boundaries(): void
    {
        $published = $this->context();
        $draft = $this->context(runStatus: ScheduleGenerationRun::StatusUnderReview);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $published['run']->getRouteKey()])
            ->assertOk()
            ->assertActionVisible('revisePublishedSchedule');

        foreach ([$academicHead, $superAdmin] as $reviewer) {
            Livewire::actingAs($reviewer)
                ->test(ViewScheduleGenerationRun::class, ['record' => $published['run']->getRouteKey()])
                ->assertOk()
                ->assertActionHidden('revisePublishedSchedule');

            Livewire::actingAs($reviewer)
                ->test(RevisionEventsRelationManager::class, [
                    'ownerRecord' => $published['run'],
                    'pageClass' => ViewScheduleGenerationRun::class,
                ])
                ->assertOk();
        }

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $draft['run']->getRouteKey()])
            ->assertActionHidden('revisePublishedSchedule');

        foreach ([
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            'student',
            'applicant',
        ] as $deniedRole) {
            $user = $this->staff($deniedRole);

            $this->assertFalse(Gate::forUser($user)->allows('view', $published['run']));
            $this->assertFalse(Gate::forUser($user)->allows('revise', SectionMeeting::class));
        }
    }

    public function test_registrar_sees_a_structured_preview_and_applies_a_room_revision(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $meeting = $context['meetings'][0];

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('revisePublishedSchedule')
            ->setActionData([
                'change_type' => ScheduleRevisionEvent::ChangeRoom,
                'section_meeting_ids' => [$meeting->id],
                'replacement_room_id' => $context['replacementRoom']->id,
                'reason' => 'The original room is unavailable.',
            ])
            ->assertMountedActionModalSee('Immediate effective date')
            ->assertMountedActionModalSee('Ready to apply')
            ->assertMountedActionModalSee($context['room']->code)
            ->assertMountedActionModalSee($context['replacementRoom']->code)
            ->assertMountedActionModalDontSee('old_snapshot_json');

        $component
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Published schedule revised');

        $event = ScheduleRevisionEvent::query()
            ->where('section_meeting_id', $meeting->id)
            ->sole();

        $this->assertSame($context['replacementRoom']->id, $meeting->fresh()->room_id);
        $this->assertSame(ScheduleRevisionEvent::ChangeRoom, $event->change_type);
        $this->assertSame(now()->toDateString(), $event->effective_date->toDateString());
        $this->assertSame($registrar->id, $event->changed_by);
    }

    public function test_registrar_can_apply_faculty_time_modality_and_whole_section_revision_paths(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $facultyContext = $this->context();
        $replacementFaculty = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()
            ->for($replacementFaculty, 'faculty')
            ->for($facultyContext['course'])
            ->create(['is_active' => true]);

        $this->callRevision($registrar, $facultyContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeFacultyReassignment,
            'section_meeting_ids' => [$facultyContext['meetings'][0]->id],
            'replacement_faculty_user_id' => $replacementFaculty->id,
            'reason' => 'Faculty reassignment approved.',
        ]);
        $this->assertSame($replacementFaculty->id, $facultyContext['meetings'][0]->fresh()->faculty_user_id);

        $timeContext = $this->context();
        $this->callRevision($registrar, $timeContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeTime,
            'section_meeting_ids' => [$timeContext['meetings'][0]->id],
            'day_of_week' => 2,
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'reason' => 'Time change approved.',
        ]);
        $this->assertSame(2, $timeContext['meetings'][0]->fresh()->day_of_week);
        $this->assertSame('09:00:00', $timeContext['meetings'][0]->fresh()->starts_at);

        $modalityContext = $this->context();
        $modalityContext['meetings'][0]->forceFill([
            'modality' => TermOffering::ModalityOnline,
            'room_id' => null,
        ])->save();
        $this->callRevision($registrar, $modalityContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeDeliveryModality,
            'section_meeting_ids' => [$modalityContext['meetings'][0]->id],
            'replacement_room_id' => $modalityContext['room']->id,
            'reason' => 'Delivery modality corrected to the authoritative source.',
        ]);
        $this->assertSame(TermOffering::ModalityFaceToFace, $modalityContext['meetings'][0]->fresh()->modality);
        $this->assertSame($modalityContext['room']->id, $modalityContext['meetings'][0]->fresh()->room_id);

        $cancellationContext = $this->context(meetingCount: 2);
        $this->callRevision($registrar, $cancellationContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeSectionCancellation,
            'section_id' => $cancellationContext['section']->id,
            'reason' => 'The section is no longer required.',
        ]);
        $this->assertSame(Section::StateCancelled, $cancellationContext['section']->fresh()->state);
        $this->assertSame(SectionDeliveryGroup::StateCancelled, $cancellationContext['group']->fresh()->state);
        $this->assertSame(2, SectionMeeting::query()
            ->whereIn('id', collect($cancellationContext['meetings'])->pluck('id'))
            ->where('state', SectionMeeting::StateCancelled)
            ->count());
    }

    public function test_source_drift_after_preview_blocks_every_grouped_write(): void
    {
        $context = $this->context(meetingCount: 2);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $meetingIds = collect($context['meetings'])->pluck('id')->all();

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('revisePublishedSchedule')
            ->setActionData([
                'change_type' => ScheduleRevisionEvent::ChangeRoom,
                'section_meeting_ids' => $meetingIds,
                'replacement_room_id' => $context['replacementRoom']->id,
                'reason' => 'This grouped preview will become stale.',
            ])
            ->assertMountedActionModalSee('Ready to apply');

        $context['replacementRoom']->forceFill(['capacity' => 10])->save();

        $component
            ->callMountedAction()
            ->assertNotified('Published schedule revision blocked')
            ->assertHasActionErrors();

        $this->assertSame(count($meetingIds), SectionMeeting::query()
            ->whereIn('id', $meetingIds)
            ->where('room_id', $context['room']->id)
            ->count());
        $this->assertSame(0, ScheduleRevisionEvent::query()
            ->whereIn('section_meeting_id', $meetingIds)
            ->count());
    }

    public function test_revision_history_is_newest_first_filterable_read_only_and_uses_snapshot_fallbacks(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $meeting = $context['meetings'][0];
        $oldRoomId = $context['room']->id;

        $this->callRevision($registrar, $context['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeRoom,
            'section_meeting_ids' => [$meeting->id],
            'replacement_room_id' => $context['replacementRoom']->id,
            'reason' => 'Room changed after facilities review.',
        ]);
        $first = ScheduleRevisionEvent::query()->where('section_meeting_id', $meeting->id)->sole();

        $this->travel(1)->minute();
        $this->callRevision($registrar, $context['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeTime,
            'section_meeting_ids' => [$meeting->id],
            'day_of_week' => 2,
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'reason' => 'Time changed after Registrar review.',
        ]);
        $second = ScheduleRevisionEvent::query()
            ->where('section_meeting_id', $meeting->id)
            ->whereKeyNot($first->id)
            ->sole();

        $context['room']->delete();

        Livewire::actingAs($academicHead)
            ->test(RevisionEventsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->assertTableColumnExists('change_type')
            ->assertTableColumnExists('effective_date')
            ->assertTableColumnExists('section_code')
            ->assertTableColumnExists('actor_name')
            ->assertTableFilterExists('change_type')
            ->filterTable('change_type', ScheduleRevisionEvent::ChangeRoom)
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second])
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete')
            ->mountTableAction('view', $first)
            ->assertMountedActionModalSee('Before')
            ->assertMountedActionModalSee('After')
            ->assertMountedActionModalSee('Room #'.$oldRoomId)
            ->assertMountedActionModalDontSee('old_snapshot_json')
            ->assertMountedActionModalDontSee('new_snapshot_json');
    }

    /** @param array<string, mixed> $data */
    private function callRevision(User $registrar, ScheduleGenerationRun $run, array $data): void
    {
        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->getRouteKey()])
            ->callAction('revisePublishedSchedule', data: $data)
            ->assertHasNoActionErrors()
            ->assertNotified('Published schedule revised');
    }

    /** @return array<string, mixed> */
    private function context(
        int $meetingCount = 1,
        string $runStatus = ScheduleGenerationRun::StatusPublished,
    ): array {
        $this->contextCounter++;
        $term = Term::factory()->create([
            'label' => 'TAL-94D3A Term '.$this->contextCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'code' => 'D3A-R'.$this->contextCounter.'A',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $replacementRoom = Room::factory()->create([
            'code' => 'D3A-R'.$this->contextCounter.'B',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $program = Program::factory()->create(['code' => 'D3A'.$this->contextCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'D3AC'.$this->contextCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [
                TermOffering::ModalityFaceToFace,
                TermOffering::ModalityOnline,
            ],
            'credit_units' => 3.00,
            'same_faculty_default' => true,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'room_type_default' => Room::TypeLectureRoom,
            'required_room_feature_keys' => [],
            'requires_consecutive_block' => false,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
            'expected_count' => 30,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'D3A-S'.$this->contextCounter,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'TAL-94D3A Group '.$this->contextCounter,
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'required_duration_minutes' => 180,
                'meeting_count' => $meetingCount,
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $runStatus,
            'requested_by' => null,
            'input_snapshot' => [
                'scheduling_demands' => [['scheduling_demand_id' => $demand->id]],
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94d3a-test-solver',
            'published_at' => $runStatus === ScheduleGenerationRun::StatusPublished ? now()->subDay() : null,
            'publication_version' => $runStatus === ScheduleGenerationRun::StatusPublished ? 1 : null,
        ]);
        $meetings = [];

        foreach (range(1, $meetingCount) as $sequence) {
            $meetings[] = SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => (($sequence - 1) * 2) + 1,
                'starts_at' => '08:00:00',
                'ends_at' => '11:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionMeeting::StateActive,
                'published_at' => now()->subDay(),
            ]);
        }

        return compact(
            'term',
            'faculty',
            'room',
            'replacementRoom',
            'program',
            'course',
            'offering',
            'section',
            'group',
            'demand',
            'run',
            'meetings',
        );
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
