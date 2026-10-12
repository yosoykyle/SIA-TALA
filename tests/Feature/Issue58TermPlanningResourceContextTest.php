<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\CreateFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ListFacultyTermLoadOverrides;
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
use App\Models\CalendarEvent;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
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

        $page = Livewire::withQueryParams(['termId' => $term->id])->test($pageClass)
            ->assertSuccessful()->assertSee($term->label)->instance();

        $returnUrl = TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $task, ...($pageClass === ListRooms::class ? ['resourceView' => 'rooms'] : [])]);
        $this->assertSame($returnUrl, $page->termPlanningReturnUrl());
        $this->assertArrayHasKey($returnUrl, $page->getBreadcrumbs());
        $this->assertStringContainsString('termId='.$term->id, $page->getResourceUrl('index'));
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
            [ListSections::class, $section, $otherSection],
            [ListFacultyTermLoadOverrides::class, $load, $otherLoad],
            [ListScheduleGenerationRuns::class, $run, $otherRun],
            [ListCalendarEvents::class, $block, $otherBlock],
            [ListSchedulingDemands::class, $demand, $otherDemand],
            [ListSectionMeetings::class, $meetings[0], $meetings[1]],
        ] as [$pageClass, $visible, $excluded]) {
            Livewire::withQueryParams(['termId' => $selected->id])->test($pageClass)
                ->resetTableFilters()->assertCanSeeTableRecords([$visible])->assertCanNotSeeTableRecords([$excluded]);
        }

        Livewire::withQueryParams(['termId' => $selected->id])->test(ListSections::class)->assertCanSeeTableRecords([$confirmed]);
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

        $section = Livewire::withQueryParams(['termId' => $term->id])->test(CreateSection::class)->instance();
        $options = $section->form->getFlatFields()['term_offering_id']->getOptions();
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
