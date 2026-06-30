<?php

namespace Tests\Feature;

use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Models\GradeRoster;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL72GradesFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['registrar', 'faculty', 'academic-head', 'student'] as $role) {
            Role::create(['name' => $role]);
        }
    }

    #[Test]
    public function registrar_can_see_grade_roster_resource_navigation(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(GradeRosterResource::canAccess());
        $this->assertTrue(GradeRosterResource::shouldRegisterNavigation());
    }

    #[Test]
    public function faculty_can_access_focused_grade_roster_page_but_not_registrar_resource(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);

        $this->actingAs($faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(FacultyGradeRoster::canAccess());
        $this->assertFalse(GradeRosterResource::shouldRegisterNavigation());
    }

    #[Test]
    public function grade_roster_list_page_renders_for_registrar(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        GradeRoster::factory()->create([
            'term_offering_id' => TermOffering::factory(),
            'section_id' => Section::factory(),
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListGradeRosters::class)
            ->assertOk();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
