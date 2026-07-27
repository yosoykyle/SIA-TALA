<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SectionMeetingAssignmentService;
use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Models\CalendarEvent;
use App\Models\Room;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL77CalendarSchedulingBlockTest extends TestCase
{
    use DatabaseTransactions;

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

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_policy_enforces_faculty_ownership_and_staff_management_boundaries(): void
    {
        $term = Term::factory()->create();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $otherFaculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $ownBlock = $this->recurringBlock($term, [
            'faculty_user_id' => $faculty->id,
        ]);
        $otherBlock = $this->recurringBlock($term, [
            'faculty_user_id' => $otherFaculty->id,
        ]);
        $institutionBlock = $this->recurringBlock($term, [
            'event_type' => CalendarEvent::TypeBreak,
            'scope_type' => CalendarEvent::ScopeInstitution,
        ]);

        $this->assertTrue(Gate::forUser($faculty)->allows('view', $ownBlock));
        $this->assertTrue(Gate::forUser($faculty)->allows('update', $ownBlock));
        $this->assertFalse(Gate::forUser($faculty)->allows('view', $otherBlock));
        $this->assertFalse(Gate::forUser($faculty)->allows('update', $otherBlock));
        $this->assertTrue(Gate::forUser($registrar)->allows('update', $otherBlock));
        $this->assertTrue(Gate::forUser($academicHead)->allows('update', $otherBlock));
        $this->assertFalse(Gate::forUser($accounting)->allows('viewAny', CalendarEvent::class));
        $this->assertFalse(Gate::forUser($systemSuperAdmin)->allows('viewAny', CalendarEvent::class));

        Livewire::actingAs($faculty)
            ->test(ListCalendarEvents::class)
            ->assertCanSeeTableRecords([$ownBlock])
            ->assertCanNotSeeTableRecords([$otherBlock]);

        Livewire::actingAs($registrar)
            ->test(ListCalendarEvents::class)
            ->assertCanSeeTableRecords([$ownBlock, $otherBlock, $institutionBlock]);

        Livewire::actingAs($academicHead)
            ->test(ListCalendarEvents::class)
            ->assertCanSeeTableRecords([$ownBlock, $otherBlock])
            ->assertCanNotSeeTableRecords([$institutionBlock]);
    }

    public function test_faculty_create_surface_forces_own_active_recurring_unavailable_block(): void
    {
        $term = Term::factory()->create();
        $faculty = $this->staff(User::StaffRoleFaculty);

        Livewire::actingAs($faculty)
            ->test(CreateCalendarEvent::class)
            ->fillForm([
                'term_id' => $term->id,
                'day_of_week' => 2,
                'starts_at' => '08:00',
                'ends_at' => '12:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $block = CalendarEvent::query()->sole();

        $this->assertSame(CalendarEvent::TypeUnavailable, $block->event_type);
        $this->assertSame(CalendarEvent::ScopeFaculty, $block->scope_type);
        $this->assertSame($faculty->id, $block->faculty_user_id);
        $this->assertTrue($block->blocks_scheduling);
        $this->assertSame(CalendarEvent::StateActive, $block->state);
        $this->assertSame('08:00:00', $block->starts_at?->format('H:i:s'));
        $this->assertSame('12:00:00', $block->ends_at?->format('H:i:s'));
        $this->assertNull($block->start_at);
        $this->assertNull($block->end_at);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => CalendarEvent::class,
            'subject_id' => $block->id,
            'event' => 'created',
        ]);
    }

    public function test_recurring_blocks_reject_manual_assignment_but_no_row_and_absolute_events_do_not(): void
    {
        $term = Term::factory()->create();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create();
        $service = app(SectionMeetingAssignmentService::class);
        $assignment = [
            'term_id' => $term->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 3,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
            'availability_override_reason' => 'A note cannot bypass a hard block.',
        ];

        $service->assertRecurringBlocksAllow($assignment);

        CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeNoClass,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'start_at' => now()->next('Wednesday')->setTime(9, 0),
            'end_at' => now()->next('Wednesday')->setTime(10, 0),
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
        ]);

        $service->assertRecurringBlocksAllow($assignment);

        foreach ([
            [CalendarEvent::ScopeFaculty, ['faculty_user_id' => $faculty->id], 'faculty_user_id'],
            [CalendarEvent::ScopeRoom, ['room_id' => $room->id], 'room_id'],
            [CalendarEvent::ScopeInstitution, [], 'day_of_week'],
        ] as [$scope, $targets, $expectedField]) {
            $block = $this->recurringBlock($term, [
                'scope_type' => $scope,
                'day_of_week' => 3,
                'starts_at' => '09:30:00',
                'ends_at' => '11:00:00',
                ...$targets,
            ]);

            try {
                $service->assertRecurringBlocksAllow($assignment);
                $this->fail("Expected {$scope} recurring block to reject the assignment.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($expectedField, $exception->errors());
            } finally {
                $block->delete();
            }
        }
    }

    public function test_new_route_is_registered_and_legacy_availability_routes_remain_unreachable(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.calendar-events.index'));

        foreach ([
            'filament.admin.resources.faculty-availability-periods.index',
            'filament.admin.resources.faculty-availability-submissions.index',
            'filament.admin.resources.faculty-availability-change-requests.index',
        ] as $legacyRoute) {
            $this->assertFalse(Route::has($legacyRoute));
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recurringBlock(Term $term, array $overrides = []): CalendarEvent
    {
        return CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeFaculty,
            'faculty_user_id' => null,
            'room_id' => null,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'TAL-77 test',
            ...$overrides,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
