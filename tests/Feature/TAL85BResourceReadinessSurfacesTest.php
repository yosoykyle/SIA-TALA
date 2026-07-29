<?php

namespace Tests\Feature;

use App\Filament\Resources\FacultyQualifications\Pages\CreateFacultyQualification;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\CreateFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ListFacultyTermLoadOverrides;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\RelationManagers\FeaturesRelationManager;
use App\Models\Course;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\RoomFeature;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL85BResourceReadinessSurfacesTest extends TestCase
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

    public function test_resource_readiness_routes_are_registered_and_legacy_availability_routes_remain_absent(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.rooms.index'));
        $this->assertTrue(Route::has('filament.admin.resources.faculty-qualifications.index'));
        $this->assertTrue(Route::has('filament.admin.resources.faculty-term-load-overrides.index'));
        $this->assertTrue(Route::has('filament.admin.resources.calendar-events.index'));

        foreach ([
            'filament.admin.resources.faculty-availability-periods.index',
            'filament.admin.resources.faculty-availability-submissions.index',
            'filament.admin.resources.faculty-availability-change-requests.index',
        ] as $legacyRoute) {
            $this->assertFalse(Route::has($legacyRoute));
        }
    }

    public function test_room_surface_uses_clean_room_fields_and_flat_feature_rows(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $room = Room::factory()->create([
            'code' => 'TAL85-LAB-101',
            'name' => 'TAL-85 Computer Laboratory',
            'building' => 'Main',
            'room_type' => Room::TypeComputerLaboratory,
            'capacity' => 36,
            'notes' => 'Scheduling-ready computer laboratory.',
        ]);
        $feature = RoomFeature::factory()->for($room)->create(['feature_key' => 'COMPUTER_UNITS']);

        $this->assertTrue(Gate::forUser($registrar)->allows('create', Room::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('update', $room));
        $this->assertTrue(Gate::forUser($academicHead)->allows('view', $room));
        $this->assertFalse(Gate::forUser($academicHead)->allows('update', $room));
        $this->assertFalse(Gate::forUser($accounting)->allows('viewAny', Room::class));
        $this->assertFalse(Gate::forUser($registrar)->allows('delete', $room));

        Livewire::actingAs($registrar)
            ->test(CreateRoom::class)
            ->assertFormFieldExists('code')
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('building')
            ->assertFormFieldExists('room_type')
            ->assertFormFieldExists('capacity')
            ->assertFormFieldExists('is_active')
            ->assertFormFieldExists('notes')
            ->assertFormFieldDoesNotExist('max_seats')
            ->assertFormFieldDoesNotExist('status');

        Livewire::actingAs($registrar)
            ->test(ListRooms::class)
            ->assertTableColumnExists('code')
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('building')
            ->assertTableColumnExists('room_type')
            ->assertTableColumnExists('capacity')
            ->assertTableColumnExists('features.feature_key')
            ->assertTableColumnExists('is_active')
            ->assertCanSeeTableRecords([$room]);

        Livewire::actingAs($registrar)
            ->test(FeaturesRelationManager::class, [
                'ownerRecord' => $room,
                'pageClass' => EditRoom::class,
            ])
            ->assertTableColumnExists('feature_key')
            ->assertCanSeeTableRecords([$feature]);
    }

    public function test_faculty_qualification_surface_uses_clean_mapping_fields_and_role_scoped_access(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $otherFaculty = $this->staff(User::StaffRoleFaculty);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $course = Course::factory()->create(['code' => 'IT101']);
        $ownQualification = FacultyQualification::factory()
            ->for($faculty, 'faculty')
            ->for($course)
            ->for($registrar, 'recorder')
            ->create(['is_active' => true]);
        $otherQualification = FacultyQualification::factory()
            ->for($otherFaculty, 'faculty')
            ->for(Course::factory()->create(['code' => 'IT102']))
            ->for($registrar, 'recorder')
            ->create(['is_active' => true]);

        $this->assertTrue(Gate::forUser($registrar)->allows('create', FacultyQualification::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('update', $ownQualification));
        $this->assertTrue(Gate::forUser($academicHead)->allows('view', $ownQualification));
        $this->assertFalse(Gate::forUser($academicHead)->allows('update', $ownQualification));
        $this->assertTrue(Gate::forUser($faculty)->allows('view', $ownQualification));
        $this->assertFalse(Gate::forUser($faculty)->allows('view', $otherQualification));
        $this->assertFalse(Gate::forUser($accounting)->allows('viewAny', FacultyQualification::class));
        $this->assertFalse(Gate::forUser($registrar)->allows('delete', $ownQualification));

        Livewire::actingAs($registrar)
            ->test(CreateFacultyQualification::class)
            ->assertFormFieldExists('faculty_user_id')
            ->assertFormFieldExists('course_id')
            ->assertFormFieldExists('is_active')
            ->assertFormFieldExists('recorded_by')
            ->assertFormFieldExists('recorded_at')
            ->assertFormFieldExists('notes')
            ->assertFormFieldDoesNotExist('subject_id')
            ->assertFormFieldDoesNotExist('term_id')
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('priority')
            ->assertFormFieldDoesNotExist('max_weekly_hours');

        Livewire::actingAs($faculty)
            ->test(ListFacultyQualifications::class)
            ->assertCanSeeTableRecords([$ownQualification])
            ->assertCanNotSeeTableRecords([$otherQualification]);

        Livewire::actingAs($academicHead)
            ->test(ListFacultyQualifications::class)
            ->assertCanSeeTableRecords([$ownQualification, $otherQualification]);
    }

    public function test_faculty_term_load_override_surface_records_final_staff_managed_overload_only(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $term = Term::factory()->create(['default_max_units' => 21.00]);
        $override = FacultyTermLoadOverride::factory()
            ->for($faculty, 'faculty')
            ->for($term)
            ->create([
                'default_max_units_snapshot' => 21.00,
                'approved_overload_units' => 3.00,
                'authority' => 'Registrar Office with Academic Head approval',
                'reason' => 'Approved overload for a hard-to-staff course.',
                'is_active' => true,
            ]);

        $this->assertTrue(Gate::forUser($registrar)->allows('create', FacultyTermLoadOverride::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('update', $override));
        $this->assertTrue(Gate::forUser($academicHead)->allows('view', $override));
        $this->assertFalse(Gate::forUser($academicHead)->allows('update', $override));
        $this->assertFalse(Gate::forUser($faculty)->allows('viewAny', FacultyTermLoadOverride::class));
        $this->assertFalse(Gate::forUser($registrar)->allows('delete', $override));

        Livewire::actingAs($registrar)
            ->test(CreateFacultyTermLoadOverride::class)
            ->assertFormFieldExists('faculty_user_id')
            ->assertFormFieldExists('term_id')
            ->assertFormFieldExists('default_max_units_snapshot')
            ->assertFormFieldExists('approved_overload_units')
            ->assertFormFieldExists('authority')
            ->assertFormFieldExists('reason')
            ->assertFormFieldExists('is_active')
            ->assertFormFieldDoesNotExist('approval_status')
            ->assertFormFieldDoesNotExist('requested_units')
            ->assertFormFieldDoesNotExist('approved_by');

        Livewire::actingAs($academicHead)
            ->test(ListFacultyTermLoadOverrides::class)
            ->assertCanSeeTableRecords([$override]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
