<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\AcademicYears\Pages\CreateAcademicYear;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Programs\Pages\CreateProgram;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Filament\Resources\Terms\TermResource;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL82AcademicSetupAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleAccounting, User::StaffRoleFaculty, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    #[Test]
    public function registrar_and_academic_head_can_access_accepted_academic_setup_surfaces(): void
    {
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead] as $role) {
            $this->actingAs($this->staff($role));
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            $this->assertTrue(ProgramResource::canAccess(), "{$role} should access Programs.");
            $this->assertTrue(AcademicYearResource::canAccess(), "{$role} should access Academic Years.");
            $this->assertTrue(TermResource::canAccess(), "{$role} should access Terms.");
            $this->assertTrue(CalendarEventResource::canAccess(), "{$role} should access Scheduling Blocks.");
        }
    }

    #[Test]
    public function non_academic_setup_roles_do_not_access_academic_setup_surfaces(): void
    {
        foreach ([User::StaffRoleAccounting, User::StaffRoleSystemSuperAdmin] as $role) {
            $this->actingAs($this->staff($role));
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            $this->assertFalse(ProgramResource::canAccess(), "{$role} should not access Programs.");
            $this->assertFalse(AcademicYearResource::canAccess(), "{$role} should not access Academic Years.");
            $this->assertFalse(TermResource::canAccess(), "{$role} should not access Terms.");
        }
    }

    #[Test]
    public function academic_head_can_view_but_not_create_or_update_core_academic_setup_records(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $program = Program::factory()->create();
        $academicYear = AcademicYear::factory()->create();
        $term = Term::factory()->for($academicYear)->create();

        $this->assertTrue($academicHead->can('view', $program));
        $this->assertTrue($academicHead->can('view', $academicYear));
        $this->assertTrue($academicHead->can('view', $term));

        $this->assertFalse($academicHead->can('create', Program::class));
        $this->assertFalse($academicHead->can('update', $program));
        $this->assertFalse($academicHead->can('create', AcademicYear::class));
        $this->assertFalse($academicHead->can('update', $academicYear));
        $this->assertFalse($academicHead->can('create', Term::class));
        $this->assertFalse($academicHead->can('update', $term));
    }

    #[Test]
    public function registrar_can_create_programs_academic_years_and_terms_with_clean_schema_fields(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateProgram::class)
            ->fillForm([
                'code' => 'BSIT',
                'name' => 'Bachelor of Science in Information Technology',
                'duration_years' => 4,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('programs', [
            'code' => 'BSIT',
            'duration_years' => 4,
            'is_active' => true,
        ]);

        Livewire::test(CreateAcademicYear::class)
            ->fillForm([
                'label' => '2026-2027',
                'starts_on' => '2026-08-01',
                'ends_on' => '2027-05-31',
                'state' => AcademicYear::StateDraft,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $academicYear = AcademicYear::query()->where('label', '2026-2027')->firstOrFail();

        Livewire::test(CreateTerm::class)
            ->fillForm([
                'academic_year_id' => $academicYear->id,
                'type' => Term::TypeFirstSemester,
                'label' => 'First Semester',
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-12-20',
                'state' => Term::StateDraft,
                'scheduling_slot_minutes' => 30,
                'default_max_units' => '21.00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('terms', [
            'academic_year_id' => $academicYear->id,
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'scheduling_slot_minutes' => 30,
            'scheduling_day_starts_at' => '07:00:00',
            'scheduling_day_ends_at' => '21:00:00',
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
