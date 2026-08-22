<?php

namespace Tests\Feature;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Pages\FacultyGradeRoster;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRosterRow;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL89AGradesAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty, User::StaffRoleAcademicHead] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function designated_faculty_receives_one_final_result_surface_without_period_or_p_controls(): void
    {
        [$faculty, $row] = $this->rosterFixture();

        Livewire::actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertCanSeeTableRecords([$row])
            ->assertTableActionVisible('recordFinalResult', $row)
            ->assertSee('Final result')
            ->assertDontSee('Prelim')
            ->assertDontSee('Midterm')
            ->assertDontSee('Set P / INC');
    }

    #[Test]
    public function view_only_co_faculty_can_open_and_export_but_cannot_edit_or_submit(): void
    {
        [$faculty, $row, $registrar, $section] = $this->rosterFixture();
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($section, $coFaculty, $registrar, 'REG-T03-CO-001');

        Livewire::actingAs($coFaculty)
            ->test(FacultyGradeRoster::class)
            ->assertCanSeeTableRecords([$row])
            ->assertTableActionHidden('recordFinalResult', $row)
            ->assertTableActionVisible('printRoster')
            ->assertTableActionVisible('downloadRoster')
            ->assertTableActionHidden('submit');

        $this->assertSame($faculty->id, $row->roster->teachingAssignment->faculty_user_id);
    }

    /** @return array{User, GradeRosterRow, User, Section} */
    private function rosterFixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $offering = TermOffering::factory()->create();
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $student = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $offering->term_id,
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        CalendarEvent::factory()->create([
            'term_id' => $offering->term_id,
            'process_key' => 'grade_entry',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'REG-T03-DESIGNATED-001');
        $row = app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar)->rows->sole();

        return [$faculty, $row, $registrar, $section];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
