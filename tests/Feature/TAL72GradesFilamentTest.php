<?php

namespace Tests\Feature;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
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
            Role::findOrCreate($role, 'web');
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
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => TermOffering::factory(),
            'section_id' => Section::factory(),
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListGradeRosters::class)
            ->assertRedirect(GradesAndCompletion::getUrl(['viewTab' => 'grade-review']));

        Livewire::withQueryParams(['viewTab' => 'grade-review'])->test(GradesAndCompletion::class)
            ->assertCanSeeTableRecords([$roster]);
    }

    #[Test]
    public function faculty_grade_roster_displays_inline_missing_result_and_inc_note_readiness_counts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $offering = TermOffering::factory()->create();
        $package = TermCalendarPackage::factory()->for($offering->term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDay()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create();

        foreach ([0, 1] as $index) {
            $student = StudentProfile::factory()->create();
            $enrollment = Enrollment::factory()->for($offering->term)->create([
                'student_profile_id' => $student->id,
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'officially_enrolled_at' => now(),
            ]);
            CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
                'section_id' => $section->id,
                'status' => CourseEnrollment::StatusActive,
                'is_current' => true,
                'units_snapshot' => '3.00',
                'added_at' => now(),
            ]);
        }

        app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'SYN-FACULTY-READINESS');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar);
        $rows = $roster->rows()->orderBy('id')->get();
        $rows[0]->update(['final_result' => 'INC', 'inc_completion_note' => null]);

        $this->actingAs($faculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['roster' => $roster->id])->test(FacultyGradeRoster::class)
            ->assertSee('1 missing result')
            ->assertSee('1 missing INC note');

        $rows[0]->update(['inc_completion_note' => 'Submit final capstone artifact']);
        $rows[1]->update(['final_result' => '1.75']);

        Livewire::withQueryParams(['roster' => $roster->id])->test(FacultyGradeRoster::class)
            ->assertSee('Ready to submit')
            ->assertDontSee('missing result')
            ->assertDontSee('missing INC note');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
