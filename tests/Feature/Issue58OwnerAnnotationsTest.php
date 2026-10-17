<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermClassProposal;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\CourseSpecifications\Pages\EditCourseSpecification;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ViewRoom;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Terms\Pages\EditTerm;
use App\Filament\Resources\Terms\Pages\ViewTerm;
use App\Models\CalendarEvent;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\RoomFeature;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use App\Support\RoomEquipment;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Issue58OwnerAnnotationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_room_projection_search_sort_page_and_view_reset_preserve_record_keys(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        foreach (range(1, 13) as $number) {
            Room::factory()->create(['code' => sprintf('ANNOTATION-%02d', $number), 'is_active' => true]);
        }
        $page = Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'rooms'])->test(TermResources::class)
            ->searchTable('ANNOTATION-')->sortTable('name')->set('tableRecordsPerPage', 10);
        $records = $page->instance()->getTableRecords();
        $this->assertSame(13, $records->total());
        $this->assertCount(10, $records->items());
        $this->assertStringStartsWith('room-', (string) array_key_first($records->items()));
        $this->assertStringStartsWith('ANNOTATION-01', collect($records->items())->first()['name']);
        $page->call('gotoPage', 2);
        $this->assertCount(3, $page->instance()->getTableRecords()->items());
        $page->searchTable('ANNOTATION-13');
        $this->assertSame(1, $page->instance()->getTableRecords()->total());
        $page->call('chooseResourceView', 'faculty');
        $this->assertSame(1, $page->instance()->getTablePage());
        $this->assertSame('', $page->instance()->tableSearch);
        $page->call('chooseResourceView', 'rooms')->searchTable('ANNOTATION-')->sortTable('name', 'desc');
        $this->assertStringStartsWith('ANNOTATION-13', collect($page->instance()->getTableRecords()->items())->first()['name']);
    }

    public function test_room_inspection_opens_exact_term_source_and_preserves_head_read_only_access(): void
    {
        $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $room = Room::factory()->create(['code' => 'ANNOTATION-DIALOG', 'is_active' => true]);
        RoomFeature::factory()->for($room)->create(['feature_key' => 'COMPUTER_UNITS']);
        $page = Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'rooms'])->test(TermResources::class)
            ->searchTable('ANNOTATION-DIALOG');
        $record = $page->instance()->getTableRecords()->items()['room-'.$room->id];
        $action = $page->instance()->getTable()->getAction('inspectRoom')->record($record);
        $this->assertSame(RoomResource::getUrl('view', ['record' => $room->id, 'termId' => $term->id, 'returnToResources' => true]), $action->getUrl());
        Livewire::withQueryParams(['termId' => $term->id, 'returnToResources' => true])->test(ViewRoom::class, ['record' => $room->id])
            ->assertSee('Computers')->assertSee('Back to Faculty & rooms')
            ->assertSee(TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'rooms']))
            ->assertActionHidden('edit');
        Livewire::test(EditRoom::class, ['record' => $room->id])->assertForbidden();
    }

    public function test_empty_coverage_prepares_complete_term_classes_while_head_keeps_read_only_scope(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->create(['term_type' => $term->type]);
        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class)->assertSee('Prepare Term classes');
        $record = collect($page->instance()->getTableRecords()->items())->firstWhere('curriculum_version_id', $curriculum->id);
        $this->assertNotNull($record);
        $action = $page->instance()->getTable()->getAction('setup')->record($record);
        $this->assertSame(TermClassProposal::getUrl(['termId' => $term->id]), $action->getUrl());

        $this->staff(User::StaffRoleAcademicHead);
        $head = Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class)->assertDontSee('Prepare Term classes');
        $action = $head->instance()->getTable()->getAction('setup')->record($record);
        $this->assertSame(TermOfferingResource::getUrl('index', [
            'termId' => $term->id, 'programId' => $record['program_id'], 'curriculumVersionId' => $curriculum->id, 'yearLevel' => $record['year_level'],
        ]), $action->getUrl());
    }

    public function test_unified_room_save_preserves_feature_ids_and_unknown_keys_and_cancel_writes_nothing(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $room = Room::factory()->create();
        $feature = RoomFeature::factory()->for($room)->create(['feature_key' => 'EXISTING_CUSTOM_KEY']);
        $this->get(RoomResource::getUrl('edit', ['record' => $room, 'termId' => $term->id, 'returnToResources' => true]))
            ->assertOk()->assertSee('Edit room')->assertSee('Equipment');
        $page = Livewire::withQueryParams(['termId' => $term->id, 'returnToResources' => true])->test(EditRoom::class, ['record' => $room->id]);
        $page->set('data.name', 'Unsaved name')->set('data.features.record-'.$feature->id.'.feature_key', 'PROJECTOR');
        $this->assertSame($room->name, $room->fresh()->name);
        $this->assertSame('EXISTING_CUSTOM_KEY', $feature->fresh()->feature_key);
        $page->set('data.name', 'Saved unified room')->set('data.features.record-'.$feature->id.'.feature_key', 'EXISTING_CUSTOM_KEY')
            ->set('data.features.new-equipment', ['feature_key' => 'COMPUTER_UNITS'])->call('save')->assertHasNoFormErrors()
            ->assertRedirect(TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'rooms']));
        $this->assertSame('Saved unified room', $room->fresh()->name);
        $this->assertSame('EXISTING_CUSTOM_KEY', $feature->fresh()->feature_key);
        $this->assertDatabaseHas('room_features', ['room_id' => $room->id, 'feature_key' => 'COMPUTER_UNITS']);
        $this->assertSame('Existing Custom Key', RoomEquipment::options()['EXISTING_CUSTOM_KEY']);
    }

    public function test_room_equipment_omission_tampering_is_rejected_without_partial_save(): void
    {
        $this->staff();
        $room = Room::factory()->create();
        $feature = RoomFeature::factory()->for($room)->create(['feature_key' => 'PROJECTOR']);
        Livewire::test(EditRoom::class, ['record' => $room->id])->set('data.name', 'Must not save')
            ->set('data.features', [])->call('save')->assertHasErrors(['data.features']);
        $this->assertSame($room->name, $room->fresh()->name);
        $this->assertModelExists($feature);
    }

    public function test_room_create_saves_equipment_with_room_in_one_form(): void
    {
        $this->staff();
        Livewire::test(CreateRoom::class)->fillForm([
            'code' => 'ANNOTATION-CREATE', 'name' => 'Unified room', 'room_type' => Room::TypeLectureRoom,
            'capacity' => 30, 'is_active' => true, 'features' => [['feature_key' => 'LECTURE_MICROPHONE']],
        ])->call('create')->assertHasNoFormErrors();
        $room = Room::query()->where('code', 'ANNOTATION-CREATE')->sole();
        $this->assertSame(['LECTURE_MICROPHONE'], $room->features()->pluck('feature_key')->all());
    }

    public function test_weekly_unavailable_time_drill_in_keeps_room_origin_and_defaults_room_scope(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $list = Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'rooms'])
            ->test(ListCalendarEvents::class)->instance();
        $url = $list->getResourceUrl('create');
        $this->assertStringContainsString('resourceView=rooms', $url);
        $this->assertSame(TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'rooms']), $list->termPlanningReturnUrl());
        $create = Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'rooms'])
            ->test(CreateCalendarEvent::class)->instance();
        $this->assertSame(CalendarEvent::ScopeRoom, $create->data['scope_type']);
        $this->assertSame((string) $term->id, $create->data['term_id']);
    }

    public function test_only_unsaved_equipment_can_be_discarded(): void
    {
        $this->staff();
        $room = Room::factory()->create();
        $feature = RoomFeature::factory()->for($room)->create(['feature_key' => 'PROJECTOR']);
        $page = Livewire::test(EditRoom::class, ['record' => $room->id])
            ->set('data.features.unsaved-equipment', ['feature_key' => null]);
        $page->assertActionHidden(TestAction::make('delete')->schemaComponent('features')->arguments(['item' => 'record-'.$feature->id]))
            ->callAction(TestAction::make('delete')->schemaComponent('features')->arguments(['item' => 'unsaved-equipment']));
        $this->assertArrayNotHasKey('unsaved-equipment', $page->instance()->data['features']);
        $this->assertArrayHasKey('record-'.$feature->id, $page->instance()->data['features']);
        $this->assertModelExists($feature);
    }

    public function test_course_equipment_requirements_keep_custom_keys_and_validate_each_identifier(): void
    {
        $this->staff();
        $specification = CourseSpecification::factory()->create();
        $component = CourseComponent::factory()->for($specification)->create();
        $path = 'data.components.record-'.$component->id.'.required_room_feature_keys';
        $page = Livewire::test(EditCourseSpecification::class, ['record' => $specification->id])
            ->set($path, ['LECTURE_MICROPHONE', 'PROJECTOR'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['LECTURE_MICROPHONE', 'PROJECTOR'], $component->fresh()->required_room_feature_keys);
        $page->set($path, [str_repeat('X', 256)])->call('save')->assertHasErrors([$path.'.0']);
        $this->assertSame(['LECTURE_MICROPHONE', 'PROJECTOR'], $component->fresh()->required_room_feature_keys);
    }

    public function test_room_create_rolls_back_when_equipment_persistence_fails(): void
    {
        $this->staff();
        $dispatcher = RoomFeature::getEventDispatcher();
        RoomFeature::setEventDispatcher(clone $dispatcher);
        RoomFeature::creating(function (): void {
            throw new \RuntimeException('Synthetic equipment persistence failure');
        });

        try {
            Livewire::test(CreateRoom::class)->fillForm([
                'code' => 'ANNOTATION-ROLLBACK', 'name' => 'Failed room', 'room_type' => Room::TypeLectureRoom,
                'capacity' => 30, 'is_active' => true, 'features' => [['feature_key' => 'PROJECTOR']],
            ])->call('create');
            $this->fail('Equipment persistence failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic equipment persistence failure', $exception->getMessage());
        } finally {
            RoomFeature::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseMissing('rooms', ['code' => 'ANNOTATION-ROLLBACK']);
    }

    public function test_meeting_list_controls_preserve_complete_visual_and_print_scope(): void
    {
        $this->staff();
        $version = PublishedTimetableVersion::factory()->create();
        $first = PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id, 'room_id' => null, 'location_label' => 'ANNOTATION-MEETING-01',
        ]);
        foreach (range(2, 13) as $number) {
            PublishedTimetableMeeting::factory()->create([
                'published_timetable_version_id' => $version->id, 'section_id' => $first->section_id,
                'faculty_user_id' => $first->faculty_user_id, 'room_id' => null,
                'meeting_sequence' => $number, 'location_label' => sprintf('ANNOTATION-MEETING-%02d', $number),
            ]);
        }
        $page = Livewire::withQueryParams(['termId' => $version->term_id, 'timetableState' => 'official', 'timetableView' => 'list'])
            ->test(TermTimetable::class)->searchTable('ANNOTATION-MEETING-')->sortTable('room');
        $this->assertSame(13, $page->instance()->getTableRecords()->total());
        $this->assertCount(10, $page->instance()->getTableRecords()->items());
        $page->call('gotoPage', 2);
        $this->assertCount(3, $page->instance()->getTableRecords()->items());
        $page->set('programFilter', 'No matching program');
        $this->assertSame(1, $page->instance()->getTablePage());
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->call('clearViewFilters');
        $this->assertSame(13, $page->instance()->getTableRecords()->total());
        $page->searchTable('ANNOTATION-MEETING-13')->sortTable('room', 'desc');
        $this->assertSame('ANNOTATION-MEETING-13', collect($page->instance()->getTableRecords()->items())->sole()['room']);
        $page->assertViewHas('scopeMeetingCount', 13)
            ->assertViewHas('timetablePresentation', fn (array $presentation): bool => count($presentation['meetings']) === 13)
            ->call('showTimetableView', 'whole')
            ->assertViewHas('timetablePresentation', fn (array $presentation): bool => count($presentation['meetings']) === 13);
        $this->get(route('timetable.version.print', ['version' => $version]))
            ->assertOk()->assertSee('ANNOTATION-MEETING-01')->assertSee('ANNOTATION-MEETING-13');
        $this->assertSame(13, $version->meetings()->count());
    }

    public function test_student_sections_use_exact_term_search_pagination_and_switch_reset(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        $cohort = TermCohort::factory()->for($term)->create(['reference' => 'ANNOTATION-SECTION']);
        $other = TermCohort::factory()->create(['reference' => 'ANNOTATION-OTHER']);
        $page = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])->test(TermClasses::class)
            ->searchTable('ANNOTATION-')->assertCanSeeTableRecords([$cohort])->assertCanNotSeeTableRecords([$other]);
        $page->call('chooseClassesView', 'classes');
        $this->assertSame('', $page->instance()->tableSearch);
        $this->assertSame(1, $page->instance()->getTablePage());
    }

    public function test_active_term_edit_exposes_load_default_and_preserves_protected_identity_and_origin(): void
    {
        $this->staff();
        $term = Term::factory()->create(['default_max_units' => 18]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        TermTeachingGridRow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00',
            'breaks' => [['starts_at' => '10:00:00', 'ends_at' => '10:30:00'], ['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
        ]);
        TermTeachingGridRow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 4, 'starts_at' => '09:00:00', 'ends_at' => '15:00:00', 'breaks' => [],
        ]);
        $page = Livewire::withQueryParams(['viewTab' => 'resources', 'resourceView' => 'rooms'])->test(EditTerm::class, ['record' => $term->id])
            ->assertSee('Protected Term identity')->assertSee('Faculty teaching-load default');
        $this->assertSame('Faculty teaching-load default', $page->instance()->getTitle());
        $page->fillForm(['default_max_units' => -1])->call('save')->assertHasFormErrors(['default_max_units']);
        $page->fillForm(['default_max_units' => 21.5])->call('save')->assertHasNoFormErrors()
            ->assertRedirect(TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'rooms']));
        $this->assertSame('21.50', $term->fresh()->default_max_units);
        $this->assertSame($term->label, $term->fresh()->label);
        $method = new \ReflectionMethod(EditTerm::class, 'handleRecordUpdate');
        try {
            $method->invoke($page->instance(), $term, ['label' => 'Tampered identity']);
            $this->fail('Activated identity update must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('label', $exception->errors());
        }
        $view = Livewire::withQueryParams(['viewTab' => 'resources', 'resourceView' => 'rooms'])->test(ViewTerm::class, ['record' => $term->id])
            ->assertSee('Monday 8:00 AM–5:00 PM; Breaks: 10:00 AM–10:30 AM, 12:00 PM–1:00 PM')
            ->assertSee('Thursday 9:00 AM–3:00 PM');
        $return = collect($view->instance()->getCachedHeaderActions())->first(fn ($action): bool => $action->getName() === 'returnToPlanning');
        $this->assertSame(TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'rooms']), $return->getUrl());
    }

    public function test_availability_request_names_missing_core_or_deadline_and_recording_is_separate(): void
    {
        $this->staff();
        $term = Term::factory()->create();
        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)->assertSee('Activate the teaching calendar before requesting availability.')
            ->assertActionDisabled('requestFacultyAvailability');
        TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'faculty_availability_due_at' => null]);
        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSee('Set a reply deadline before sending requests.')->assertActionDisabled('requestFacultyAvailability');
        Mail::fake();
        $page->callAction('recordFacultyDeadline', ['due_at' => now()->addDays(3)->format('Y-m-d H:i:s'), 'authority_reference' => 'Synthetic approved deadline'])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('faculty_availability_deadlines', ['term_id' => $term->id, 'authority_reference' => 'Synthetic approved deadline']);
        Mail::assertNothingOutgoing();
        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)->assertActionEnabled('requestFacultyAvailability')->mountAction('requestFacultyAvailability')
            ->assertMountedActionModalSee('Reply by')->assertMountedActionModalSee('Recipients');
    }

    private function staff(string $name = User::StaffRoleRegistrar): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate($name, 'web');
        if ($name === User::StaffRoleRegistrar) {
            $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $name]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
