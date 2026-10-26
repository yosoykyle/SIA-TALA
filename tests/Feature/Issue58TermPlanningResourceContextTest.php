<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\EditCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\FacultyQualifications\Pages\ViewFacultyQualification;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\CreateFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\EditFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ListFacultyTermLoadOverrides;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ViewFacultyTermLoadOverride;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\Pages\ViewRoom;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\SchedulingDemands\Pages\ListSchedulingDemands;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Resources\Sections\Pages\CreateSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Sections\Pages\ViewSection;
use App\Filament\Resources\TermOfferings\Pages\ListTermOfferings;
use App\Filament\Resources\Terms\TermResource;
use App\Models\CalendarEvent;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\RoomFeature;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Issue58TermPlanningResourceContextTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('planningLists')]
    public function test_source_lists_keep_exact_term_and_owning_task(string $pageClass, string $task, string $label): void
    {
        $this->registrar();
        $term = Term::factory()->create();

        if ($pageClass === ListSections::class) {
            Livewire::withQueryParams(['termId' => $term->id])->test($pageClass)->assertRedirect(TermClasses::getUrl(['termId' => $term->id]));

            return;
        }

        $page = Livewire::withQueryParams(['termId' => $term->id])->test($pageClass)
            ->assertSuccessful()->assertSee($term->label)->instance();

        $returnUrl = TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $task, ...($pageClass === ListRooms::class ? ['resourceView' => 'rooms'] : [])]);
        $this->assertSame($returnUrl, $page->termPlanningReturnUrl());
        $this->assertArrayHasKey($returnUrl, $page->getBreadcrumbs());
        $this->assertStringContainsString('termId='.$term->id, $page->getResourceUrl('index'));
        $navigation = collect($page->getWorkspaceNavigation());
        $this->assertCount(5, $navigation);
        $this->assertSame($label, $navigation->first(fn ($item): bool => $item->isActive())->getLabel());
        foreach ($navigation as $item) {
            $this->assertStringContainsString('termId='.$term->id, $item->getUrl());
        }
        $this->assertSame([], $page->getSubNavigation());
    }

    public function test_global_source_discovery_keeps_an_owning_workbench_without_inventing_a_term(): void
    {
        $this->registrar();
        $page = Livewire::test(ListRooms::class)->assertSuccessful()->instance();
        $this->assertNull($page->termId);
        $returnUrl = TermPlanningWorkbench::getUrl(['viewTab' => 'resources', 'resourceView' => 'rooms']);
        $this->assertSame($returnUrl, $page->termPlanningReturnUrl());
        $this->assertArrayHasKey($returnUrl, $page->getBreadcrumbs());
    }

    public function test_qualification_search_uses_approved_course_titles_and_keeps_the_teacher_filter(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $qualification = FacultyQualification::factory()->create();
        $other = FacultyQualification::factory()->create();
        foreach ([$qualification, $other] as $record) {
            CourseSpecification::factory()->create(['course_id' => $record->course_id, 'state' => CourseSpecification::StateActive, 'title' => 'Introductory computing']);
        }
        CourseSpecification::factory()->create(['course_id' => $qualification->course_id, 'title' => 'Unapproved draft title']);

        Livewire::withQueryParams(['termId' => $term->id])->test(ListFacultyQualifications::class)
            ->filterTable('faculty_user_id', $qualification->faculty_user_id)
            ->searchTable('Introductory computing')
            ->assertCanSeeTableRecords([$qualification])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('Introductory computing')
            ->assertDontSee('Unapproved draft title')
            ->searchTable('Unapproved draft title')
            ->assertCanNotSeeTableRecords([$qualification, $other]);
    }

    public function test_qualification_details_render_active_course_and_keep_term_return_context(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $qualification = FacultyQualification::factory()->create(['is_active' => true]);
        CourseSpecification::factory()->create([
            'course_id' => $qualification->course_id,
            'state' => CourseSpecification::StateActive,
            'title' => 'Programming fundamentals',
        ]);

        $test = Livewire::withQueryParams(['termId' => $term->id])->test(ViewFacultyQualification::class, ['record' => $qualification->id])
            ->assertSuccessful()
            ->assertSee('Programming fundamentals')
            ->assertSee('Qualification details')
            ->assertSee('Recorded evidence')
            ->assertSee('Edit qualification')
            ->assertDontSee('Back to qualifications');

        $page = $test->instance();
        $returnUrl = FacultyQualificationResource::getUrl('index', ['termId' => $term->id]);
        $this->assertStringContainsString('termId='.$term->id, $page->getResourceUrl('edit'));
        $breadcrumbs = array_values($page->getBreadcrumbs());
        $this->assertSame('Qualification details', end($breadcrumbs));
        $test->assertSee($returnUrl);
    }

    public function test_native_room_create_record_edit_and_cancel_links_keep_term_context(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $room = Room::factory()->create();

        $list = Livewire::withQueryParams(['termId' => $term->id])->test(ListRooms::class)->instance();
        $create = collect($list->getCachedHeaderActions())->first(fn ($action): bool => $action->getName() === 'create');
        $this->assertSame(RoomResource::getUrl('create', ['termId' => $term->id]), $create->getUrl());
        $this->assertStringContainsString('termId='.$term->id, $list->getTable()->getAction('edit')->record($room)->getUrl());

        foreach ([CreateRoom::class => [], EditRoom::class => ['record' => $room->id]] as $pageClass => $parameters) {
            $page = Livewire::withQueryParams(['termId' => $term->id])->test($pageClass, $parameters);
            $document = new \DOMDocument;
            @$document->loadHTML($page->html());
            $cancel = collect(iterator_to_array($document->getElementsByTagName('a')))
                ->first(fn (\DOMElement $link): bool => trim($link->textContent) === 'Cancel');
            $this->assertNotNull($cancel);
            $this->assertSame(RoomResource::getUrl('index', ['termId' => $term->id]), $cancel->getAttribute('href'));
        }

        $view = Livewire::withQueryParams(['termId' => $term->id])->test(ViewRoom::class, ['record' => $room->id])->instance();
        $this->assertStringContainsString('termId='.$term->id, $view->getResourceUrl('edit'));
        $returnUrl = TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'rooms']);
        $this->assertSame('Faculty & rooms', $view->getBreadcrumbs()[$returnUrl]);
    }

    public function test_room_save_returns_to_the_scoped_list_without_filtering_institution_sources(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $room = Room::factory()->create();

        Livewire::withQueryParams(['termId' => $term->id])->test(ListRooms::class)->assertCanSeeTableRecords([$room]);
        Livewire::withQueryParams(['termId' => $term->id])->test(EditRoom::class, ['record' => $room->id])
            ->fillForm(['name' => 'Updated contextual room'])->call('save')->assertHasNoFormErrors()
            ->assertRedirect(RoomResource::getUrl('index', ['termId' => $term->id]));
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Updated contextual room']);

        $qualification = FacultyQualification::factory()->create();
        Livewire::withQueryParams(['termId' => $term->id])->test(ListFacultyQualifications::class)
            ->assertCanSeeTableRecords([$qualification]);
    }

    public function test_room_detail_renders_equipment_status_timestamps_and_term_navigation(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $room = Room::factory()->create([
            'code' => 'DETAIL-A101',
            'name' => 'General classroom',
            'is_active' => false,
            'created_at' => '2026-10-01 12:00:00',
            'updated_at' => '2026-10-02 12:00:00',
        ]);
        RoomFeature::factory()->for($room)->create(['feature_key' => 'PROJECTOR']);
        RoomFeature::factory()->for($room)->create(['feature_key' => 'COMPUTER_UNITS']);

        Livewire::withQueryParams(['termId' => $term->id])->test(ViewRoom::class, ['record' => $room->id])
            ->assertSuccessful()
            ->assertSee('Room details')
            ->assertSee('General classroom')
            ->assertSee('Projector')
            ->assertSee('Computers')
            ->assertSee('Inactive')
            ->assertSee('Created (Asia/Manila)')
            ->assertSee('Last updated (Asia/Manila)')
            ->assertSee('Oct 1, 2026')
            ->assertSee('Oct 2, 2026')
            ->assertDontSee('Back to rooms')
            ->assertSee(RoomResource::getUrl('index', ['termId' => $term->id]));
    }

    public function test_room_search_matches_building_and_weekly_unavailable_link_is_registrar_only(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $room = Room::factory()->create(['name' => 'Presentation suite', 'building' => 'North Annex']);
        $otherRoom = Room::factory()->create(['name' => 'Seminar room', 'building' => 'South Hall']);
        $list = Livewire::withQueryParams(['termId' => $term->id])->test(ListRooms::class)
            ->searchTable('North Annex')
            ->assertCanSeeTableRecords([$room])
            ->assertCanNotSeeTableRecords([$otherRoom])
            ->searchTable('Presentation suite')
            ->assertCanSeeTableRecords([$room])
            ->assertCanNotSeeTableRecords([$otherRoom])
            ->assertActionVisible('weeklyUnavailableTimes')
            ->instance();
        $weeklyAction = collect($list->getCachedHeaderActions())->first(fn ($action): bool => $action->getName() === 'weeklyUnavailableTimes');

        $this->assertSame(CalendarEventResource::getUrl('index', ['termId' => $term->id, 'resourceView' => 'rooms']), $weeklyAction->getUrl());

        $role = Role::findOrCreate(User::StaffRoleAcademicHead, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAcademicHead]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['termId' => $term->id])->test(ListRooms::class)
            ->assertSuccessful()
            ->assertActionHidden('weeklyUnavailableTimes');
    }

    public function test_unavailable_times_search_room_and_faculty_and_show_recorded_authority(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $room = Room::factory()->create(['code' => 'SEARCH-ROOM-66']);
        $faculty = User::factory()->create(User::staffNamePayload('Search Faculty', null, 'Sixty Six'));
        $faculty->assignRole(User::StaffRoleFaculty);
        $roomBlock = CalendarEvent::factory()->for($term)->create([
            ...$this->recurringBlock(), 'scope_type' => CalendarEvent::ScopeRoom,
            'room_id' => $room->id, 'authority' => 'Recorded room authority',
        ]);
        $facultyBlock = CalendarEvent::factory()->for($term)->create([
            ...$this->recurringBlock(), 'scope_type' => CalendarEvent::ScopeFaculty,
            'faculty_user_id' => $faculty->id, 'authority' => 'Recorded Faculty authority',
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(ListCalendarEvents::class)
            ->assertSee('Recorded room authority')->assertSee('Recorded Faculty authority')
            ->assertSee('12:00 PM')->assertSee('1:00 PM')
            ->searchTable('SEARCH-ROOM-66')->assertCanSeeTableRecords([$roomBlock])->assertCanNotSeeTableRecords([$facultyBlock])
            ->searchTable('Search Faculty Sixty Six')->assertCanSeeTableRecords([$facultyBlock])->assertCanNotSeeTableRecords([$roomBlock]);
    }

    public function test_editing_noon_unavailability_preserves_recorded_wall_clock_times(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $block = CalendarEvent::factory()->for($term)->create($this->recurringBlock());

        Livewire::withQueryParams(['termId' => $term->id])->test(EditCalendarEvent::class, ['record' => $block->id])
            ->assertSee('Time zone: Asia/Manila.')
            ->assertFormSet(['starts_at' => '12:00', 'ends_at' => '13:00'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('12:00:00', $block->fresh()->starts_at->format('H:i:s'));
        $this->assertSame('13:00:00', $block->fresh()->ends_at->format('H:i:s'));
    }

    public function test_term_owned_sources_exclude_other_terms_even_after_filters_are_cleared(): void
    {
        $this->registrar();
        $selected = Term::factory()->create();
        $other = Term::factory()->create();
        $offering = TermOffering::factory()->for($selected)->create();
        $otherOffering = TermOffering::factory()->for($other)->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $otherSection = Section::factory()->for($otherOffering, 'termOffering')->create();
        $confirmed = Section::factory()->create([
            'term_offering_id' => $offering->id,
            'term_calendar_package_id' => TermCalendarPackage::factory()->for($selected)->create()->id,
        ]);
        $load = FacultyTermLoadOverride::factory()->for($selected)->create();
        $otherLoad = FacultyTermLoadOverride::factory()->for($other)->create();
        $run = ScheduleGenerationRun::factory()->for($selected)->create();
        $otherRun = ScheduleGenerationRun::factory()->for($other)->create();
        $block = CalendarEvent::factory()->for($selected)->create($this->recurringBlock());
        $otherBlock = CalendarEvent::factory()->for($other)->create($this->recurringBlock());
        $demand = SchedulingDemand::factory()->for($offering, 'termOffering')->create();
        $otherDemand = SchedulingDemand::factory()->for($otherOffering, 'termOffering')->create();
        $faculty = User::factory()->create();
        $meetingRoom = Room::factory()->create();
        $meetings = [];
        foreach ([[$run, $demand], [$otherRun, $otherDemand]] as [$meetingRun, $meetingDemand]) {
            $meetings[] = SectionMeeting::query()->create([
                'schedule_run_id' => $meetingRun->id, 'scheduling_demand_id' => $meetingDemand->id,
                'meeting_sequence' => 1, 'faculty_user_id' => $faculty->id, 'room_id' => $meetingRoom->id,
                'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '11:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionMeeting::StateActive, 'published_at' => now(),
            ]);
        }

        foreach ([
            [ListTermOfferings::class, $offering, $otherOffering],
            [TermClasses::class, $section, $otherSection],
            [ListFacultyTermLoadOverrides::class, $load, $otherLoad],
            [ListScheduleGenerationRuns::class, $run, $otherRun],
            [ListCalendarEvents::class, $block, $otherBlock],
            [ListSchedulingDemands::class, $demand, $otherDemand],
            [ListSectionMeetings::class, $meetings[0], $meetings[1]],
        ] as [$pageClass, $visible, $excluded]) {
            Livewire::withQueryParams(['termId' => $selected->id])->test($pageClass)
                ->resetTableFilters()->assertCanSeeTableRecords([$visible])->assertCanNotSeeTableRecords([$excluded]);
        }

        Livewire::withQueryParams(['termId' => $selected->id])->test(TermClasses::class)->assertCanSeeTableRecords([$confirmed]);
    }

    public function test_load_exception_list_shows_term_default_and_keeps_term_on_actions(): void
    {
        $this->registrar();
        $term = Term::factory()->create(['default_max_units' => 17.5]);
        $load = FacultyTermLoadOverride::factory()->for($term)->create();
        $component = Livewire::withQueryParams(['termId' => $term->id])->test(ListFacultyTermLoadOverrides::class)
            ->assertSuccessful()
            ->assertSee('17.5 units per teacher')
            ->assertSee('Edit Term default')
            ->assertSee('View details')
            ->assertSee('Edit');

        $component->assertSee(TermResource::getUrl('edit', [
            'record' => $term,
            'termId' => $term->id,
            'viewTab' => 'resources',
            'resourceView' => 'faculty',
        ]));
        $component->assertSee(FacultyTermLoadOverrideResource::getUrl('view', ['record' => $load, 'termId' => $term->id]));
        $component->assertSee(FacultyTermLoadOverrideResource::getUrl('edit', ['record' => $load, 'termId' => $term->id]));
    }

    public function test_load_exception_empty_state_discloses_missing_term_default(): void
    {
        $this->registrar();
        $term = Term::factory()->create(['default_max_units' => null]);

        Livewire::withQueryParams(['termId' => $term->id])->test(ListFacultyTermLoadOverrides::class)
            ->assertSuccessful()
            ->assertSee('Not recorded')
            ->assertSee('No load exceptions recorded')
            ->assertSee('Teachers use the Term default unless an approved exception is recorded.');
    }

    public function test_load_exception_edit_form_keeps_term_and_displays_its_recorded_snapshot(): void
    {
        $this->registrar();
        $term = Term::factory()->create(['default_max_units' => 16]);
        $load = FacultyTermLoadOverride::factory()->for($term)->create(['default_max_units_snapshot' => 14]);

        Livewire::withQueryParams(['termId' => $term->id])->test(EditFacultyTermLoadOverride::class, ['record' => $load->id])
            ->assertSuccessful()
            ->assertSee('Edit load exception')
            ->assertSee('14 units (recorded snapshot)')
            ->assertSee('Approval reference')
            ->assertSee('Active exception')
            ->assertSee(FacultyTermLoadOverrideResource::getUrl('view', ['record' => $load, 'termId' => $term->id]));
    }

    public function test_load_exception_detail_explains_inactive_effect_and_keeps_exact_term_navigation(): void
    {
        $this->registrar();
        $term = Term::factory()->create(['default_max_units' => 19]);
        $load = FacultyTermLoadOverride::factory()->for($term)->create([
            'default_max_units_snapshot' => 21,
            'approved_overload_units' => 0,
            'authority' => 'Approval reference for detail test',
            'reason' => 'Recorded reason for detail test',
            'is_active' => false,
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(ViewFacultyTermLoadOverride::class, ['record' => $load->id])
            ->assertSuccessful()
            ->assertSee($load->faculty->name)
            ->assertSee('Exception details')
            ->assertSee('Edit load exception')
            ->assertSee('Term')
            ->assertSee($term->label)
            ->assertSee('Inactive')
            ->assertSee('21 units')
            ->assertSee('This inactive exception does not replace the Term’s default for this teacher.')
            ->assertSee('Approval reference for detail test')
            ->assertSee('Recorded reason for detail test')
            ->assertDontSee('Back to load exceptions')
            ->assertSee(FacultyTermLoadOverrideResource::getUrl('index', ['termId' => $term->id]));
    }

    public function test_class_detail_keeps_course_identity_and_delivery_groups_visible_in_exact_term_context(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $offering = TermOffering::factory()->for($term)->create();
        $class = Section::factory()->for($offering)->create(['code' => 'DETAIL-CLASS-1']);
        $group = SectionDeliveryGroup::factory()->for($class)->create([
            'name' => 'Whole class',
            'expected_count' => 24,
        ]);
        $courseTitle = $class->termOffering?->curriculumEntry?->courseSpecification?->title;

        Livewire::withQueryParams(['termId' => $term->id])->test(ViewSection::class, ['record' => $class->id])
            ->assertSuccessful()
            ->assertSee('Class details')
            ->assertSee('Edit class')
            ->assertSee('Class · DETAIL-CLASS-1')
            ->assertSee('Course code')
            ->assertSee('Class reference')
            ->assertSee('Capacity')
            ->assertSee('Student sections served')
            ->assertSee('Meeting requirements')
            ->assertSee('Delivery groups')
            ->assertSee('Groups of learners that meet together.')
            ->assertSee('Search groups')
            ->assertSee('Expected learners')
            ->assertSee('View details')
            ->assertSee($group->name)
            ->assertSee($courseTitle);
    }

    public function test_generation_history_search_uses_recorded_status_and_preserves_exact_term(): void
    {
        $this->registrar();
        $term = Term::factory()->create(['label' => 'History search selected Term']);
        $otherTerm = Term::factory()->create(['label' => 'History search other Term']);
        $selected = ScheduleGenerationRun::factory()->for($term)->create(['status' => ScheduleGenerationRun::StatusUnderReview]);
        $other = ScheduleGenerationRun::factory()->for($otherTerm)->create(['status' => ScheduleGenerationRun::StatusUnderReview]);
        Livewire::withQueryParams(['termId' => $term->id])->test(ListScheduleGenerationRuns::class)
            ->searchTable('History search')->assertCanSeeTableRecords([$selected])->assertCanNotSeeTableRecords([$other])
            ->searchTable('review')->assertCanSeeTableRecords([$selected])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_room_code_normalization_rejects_a_duplicate_as_a_field_error_without_writes(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $room = Room::factory()->create(['code' => 'TERM-ROOM-NORM']);
        $original = $room->fresh()->getRawOriginal();
        $count = Room::query()->count();
        Livewire::withQueryParams(['termId' => $term->id])->test(CreateRoom::class)
            ->fillForm(['code' => '  term-room-norm  ', 'name' => 'Duplicate normalized room', 'room_type' => Room::TypeLectureRoom, 'capacity' => 30, 'is_active' => true])
            ->call('create')->assertHasFormErrors(['code' => 'unique'])->assertNotNotified();
        $this->assertSame($count, Room::query()->count());
        $this->assertSame($original, $room->fresh()->getRawOriginal());
    }

    public function test_contextual_forms_prefill_exact_term_and_only_offer_that_terms_sections_sources(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $offering = TermOffering::factory()->for($term)->create();
        $otherOffering = TermOffering::factory()->create();

        foreach ([CreateCalendarEvent::class, CreateFacultyTermLoadOverride::class] as $pageClass) {
            Livewire::withQueryParams(['termId' => $term->id])->test($pageClass)
                ->assertFormSet(['term_id' => $term->id]);
        }

        Livewire::withQueryParams(['termId' => $term->id])->test(CreateSection::class)->assertRedirect(TermClasses::getUrl(['termId' => $term->id, 'action' => 'addClass']));
        $section = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)->mountAction('addClass')->instance();
        $options = $section->getSchema($section->getMountedActionSchemaName())->getFlatFields()['term_offering_id']->getOptions();
        $this->assertArrayHasKey($offering->id, $options);
        $this->assertArrayNotHasKey($otherOffering->id, $options);
    }

    public function test_invalid_or_mismatched_term_context_does_not_open_another_terms_record(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $section = Section::factory()->create();

        Livewire::withQueryParams(['termId' => $term->id])->test(ViewSection::class, ['record' => $section->id])->assertNotFound();
        Livewire::withQueryParams(['termId' => PHP_INT_MAX])->test(ListRooms::class)->assertNotFound();
    }

    public function test_livewire_cannot_switch_context_during_a_pending_action(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $other = Term::factory()->create();
        $page = Livewire::withQueryParams(['termId' => $term->id])->test(CreateCalendarEvent::class);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('termId', $other->id);
    }

    public function test_posted_calendar_form_cannot_change_the_selected_term(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $other = Term::factory()->create();

        Livewire::withQueryParams(['termId' => $term->id])->test(CreateCalendarEvent::class)
            ->fillForm([...$this->recurringBlock(), 'term_id' => $other->id])->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('calendar_events', ['term_id' => $term->id, 'event_type' => CalendarEvent::TypeUnavailable]);
        $this->assertDatabaseMissing('calendar_events', ['term_id' => $other->id]);
    }

    public function test_unscoped_source_pages_keep_native_resource_urls(): void
    {
        $this->registrar();

        $page = Livewire::test(ListRooms::class)->instance();

        $this->assertSame(RoomResource::getUrl('create'), $page->getResourceUrl('create'));
    }

    public function test_academic_head_retains_read_only_term_context(): void
    {
        $role = Role::findOrCreate(User::StaffRoleAcademicHead, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAcademicHead]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create();

        Livewire::withQueryParams(['termId' => $term->id])->test(ListRooms::class)
            ->assertSuccessful()->assertActionHidden('create');
    }

    public function test_faculty_history_returns_to_own_availability_with_exact_term(): void
    {
        $role = Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleFaculty]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $term = Term::factory()->create();
        $returnUrl = MyAvailability::getUrl(['termId' => $term->id]);

        Livewire::withQueryParams(['termId' => $term->id])->test(MyAvailability::class)
            ->assertDontSee('Older unavailable-time records');
        CalendarEvent::factory()->create([
            'term_id' => $term->id,
            'faculty_user_id' => $user->id,
            'scope_type' => CalendarEvent::ScopeFaculty,
            'event_type' => CalendarEvent::TypeUnavailable,
            'blocks_scheduling' => true,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
        ]);
        Livewire::withQueryParams(['termId' => $term->id])->test(MyAvailability::class)
            ->assertSeeHtml(CalendarEventResource::getUrl('index', ['termId' => $term->id]));
        $history = Livewire::withQueryParams(['termId' => $term->id])->test(ListCalendarEvents::class)
            ->assertSee('Back to My Availability')
            ->assertSee('Read-only earlier unavailable-time records belonging to you.')
            ->instance();

        $this->assertSame([
            $returnUrl => 'My Availability',
            'Historical unavailable-time records',
        ], $history->getBreadcrumbs());
        $return = collect($history->getCachedHeaderActions())->sole();
        $this->assertSame($returnUrl, $return->getUrl());
        $this->assertFalse(TermPlanningWorkbench::canAccess());
    }

    /** @return array<string, array{class-string, string, string}> */
    public static function planningLists(): array
    {
        return [
            'Offerings' => [ListTermOfferings::class, 'classes', 'Sections & classes'],
            'Sections' => [ListSections::class, 'classes', 'Sections & classes'],
            'Requirements' => [ListSchedulingDemands::class, 'classes', 'Sections & classes'],
            'Rooms' => [ListRooms::class, 'resources', 'Faculty & rooms'],
            'Qualifications' => [ListFacultyQualifications::class, 'resources', 'Faculty & rooms'],
            'Loads' => [ListFacultyTermLoadOverrides::class, 'resources', 'Faculty & rooms'],
            'Availability' => [ListCalendarEvents::class, 'resources', 'Faculty & rooms'],
            'Runs' => [ListScheduleGenerationRuns::class, 'generate', 'Timetable'],
            'Meetings' => [ListSectionMeetings::class, 'published', 'Timetable'],
        ];
    }

    private function registrar(): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->syncPermissions([Permission::findOrCreate('manage-schedules', 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, mixed> */
    private function recurringBlock(): array
    {
        return [
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'day_of_week' => 1, 'starts_at' => '12:00', 'ends_at' => '13:00',
            'start_at' => null, 'end_at' => null, 'blocks_scheduling' => true,
        ];
    }
}
