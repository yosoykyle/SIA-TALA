<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use App\Filament\Resources\AcademicCalendarWindows\Pages\CreateAcademicCalendarWindow;
use App\Filament\Resources\AcademicCalendarWindows\Pages\EditAcademicCalendarWindow;
use App\Filament\Resources\AcademicCalendarWindows\Pages\ListAcademicCalendarWindows;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Models\CalendarEvent;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL82CAcademicCalendarWindowTest extends TestCase
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

    public function test_registrar_manages_academic_calendar_windows_while_academic_head_views_and_faculty_is_blocked(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $term = Term::factory()->create();
        $window = $this->calendarWindow($term, [
            'process_key' => CalendarEvent::ProcessGradeEncoding,
        ]);

        $this->assertTrue(Gate::forUser($registrar)->allows('viewAcademicCalendarWindow', $window));
        $this->assertTrue(Gate::forUser($registrar)->allows('updateAcademicCalendarWindow', $window));
        $this->assertTrue(Gate::forUser($registrar)->allows('createAcademicCalendarWindow', CalendarEvent::class));

        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAcademicCalendarWindow', $window));
        $this->assertFalse(Gate::forUser($academicHead)->allows('updateAcademicCalendarWindow', $window));
        $this->assertFalse(Gate::forUser($academicHead)->allows('createAcademicCalendarWindow', CalendarEvent::class));

        $this->assertFalse(Gate::forUser($faculty)->allows('viewAcademicCalendarWindow', $window));
        $this->assertFalse(Gate::forUser($faculty)->allows('updateAcademicCalendarWindow', $window));
        $this->assertFalse(Gate::forUser($faculty)->allows('createAcademicCalendarWindow', CalendarEvent::class));

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(AcademicCalendarWindowResource::canAccess());
        $this->assertTrue(AcademicCalendarWindowResource::canCreate());

        $this->actingAs($academicHead);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(AcademicCalendarWindowResource::canAccess());
        $this->assertFalse(AcademicCalendarWindowResource::canCreate());

        $this->actingAs($faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(AcademicCalendarWindowResource::canAccess());
    }

    public function test_registrar_can_create_absolute_academic_calendar_window_without_recurring_block_fields(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        Livewire::actingAs($registrar)
            ->test(CreateAcademicCalendarWindow::class)
            ->fillForm([
                'term_id' => $term->id,
                'process_key' => CalendarEvent::ProcessGradeFinalization,
                'start_at' => '2026-10-01 08:00:00',
                'end_at' => '2026-10-07 17:00:00',
                'state' => CalendarEvent::StateActive,
                'authority' => 'Registrar Office memo 2026-10',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $window = CalendarEvent::query()->sole();

        $this->assertSame(CalendarEvent::TypeWindow, $window->event_type);
        $this->assertSame(CalendarEvent::ScopeInstitution, $window->scope_type);
        $this->assertSame(CalendarEvent::ProcessGradeFinalization, $window->process_key);
        $this->assertFalse($window->blocks_scheduling);
        $this->assertNull($window->day_of_week);
        $this->assertNull($window->starts_at);
        $this->assertNull($window->ends_at);
        $this->assertSame('2026-10-01 00:00:00', (string) $window->start_at);
        $this->assertSame('2026-10-07 09:00:00', (string) $window->end_at);
    }

    public function test_calendar_window_surface_and_scheduling_blocks_surface_keep_separate_queries(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $window = $this->calendarWindow($term, [
            'process_key' => CalendarEvent::ProcessIncCompletionRemoval,
        ]);
        $recurringBlock = $this->recurringBlock($term, [
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);

        Livewire::actingAs($registrar)
            ->test(ListAcademicCalendarWindows::class)
            ->assertCanSeeTableRecords([$window])
            ->assertCanNotSeeTableRecords([$recurringBlock]);

        Livewire::actingAs($registrar)
            ->test(ListCalendarEvents::class)
            ->assertCanSeeTableRecords([$recurringBlock])
            ->assertCanNotSeeTableRecords([$window]);
    }

    public function test_calendar_window_process_options_include_grade_and_inc_mvp_windows(): void
    {
        $options = CalendarEvent::academicCalendarWindowProcessOptions();

        foreach ([
            CalendarEvent::ProcessGradeEncoding,
            CalendarEvent::ProcessLateGradeEncodingAuthorization,
            CalendarEvent::ProcessGradeFinalization,
            CalendarEvent::ProcessIncCompletionRemoval,
        ] as $processKey) {
            $this->assertArrayHasKey($processKey, $options);
        }

        $this->assertArrayHasKey(CalendarEvent::ProcessTermPlanning, $options);
        $this->assertArrayHasKey(CalendarEvent::ProcessEnrollment, $options);
    }

    public function test_calendar_window_routes_are_registered_and_edit_honors_read_only_academic_head(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $window = $this->calendarWindow(Term::factory()->create());

        $this->assertTrue(Route::has('filament.admin.resources.academic-calendar-windows.index'));
        $this->assertTrue(Route::has('filament.admin.resources.academic-calendar-windows.create'));
        $this->assertTrue(Route::has('filament.admin.resources.academic-calendar-windows.edit'));

        Livewire::actingAs($registrar)
            ->test(EditAcademicCalendarWindow::class, ['record' => $window->getRouteKey()])
            ->fillForm([
                'process_key' => CalendarEvent::ProcessLateGradeEncodingAuthorization,
                'authority' => 'Updated registrar memo',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(CalendarEvent::ProcessLateGradeEncodingAuthorization, $window->fresh()->process_key);

        $this->assertFalse(Gate::forUser($academicHead)->allows('update', $window->fresh()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function calendarWindow(Term $term, array $overrides = []): CalendarEvent
    {
        return CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => CalendarEvent::ProcessTermPlanning,
            'start_at' => '2026-08-01 08:00:00',
            'end_at' => '2026-08-07 17:00:00',
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
            'authority' => 'TAL-82C test',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recurringBlock(Term $term, array $overrides = []): CalendarEvent
    {
        return CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeFaculty,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'TAL-82C recurring block test',
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
