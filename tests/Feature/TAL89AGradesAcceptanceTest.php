<?php

namespace Tests\Feature;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SaveGradeRosterDraft;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Pages\FacultyGradeRoster;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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

        $component = Livewire::actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertActionVisible('submit')
            ->assertSee('Save draft')
            ->assertSee('Final result')
            ->assertDontSee('Prelim')
            ->assertDontSee('Midterm')
            ->assertDontSee('Set P / INC');
        $this->assertSame(
            $row->courseEnrollment->enrollment->studentProfile->student_number,
            collect($component->instance()->data['rows'])->first()['student_number'],
        );
    }

    #[Test]
    public function view_only_co_faculty_can_open_and_export_but_cannot_edit_or_submit(): void
    {
        [$faculty, $row, $registrar, $section] = $this->rosterFixture();
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($section, $coFaculty, $registrar, 'REG-T03-CO-001');

        $component = Livewire::actingAs($coFaculty)
            ->test(FacultyGradeRoster::class)
            ->assertActionVisible('printRoster')
            ->assertActionVisible('downloadRoster')
            ->assertActionHidden('submit')
            ->assertDontSee('Save draft');
        $this->assertSame(
            $row->courseEnrollment->enrollment->studentProfile->student_number,
            collect($component->instance()->data['rows'])->first()['student_number'],
        );

        $this->assertSame($faculty->id, $row->roster->teachingAssignment->faculty_user_id);
    }

    #[Test]
    public function draft_save_is_atomic_and_rejects_stale_lock_or_membership_evidence(): void
    {
        [$faculty, $row] = $this->rosterFixture();
        $roster = $row->roster;
        $action = app(SaveGradeRosterDraft::class);
        $originalLockVersion = $roster->lock_version;

        $saved = $action->execute(
            $roster,
            [['id' => $row->id, 'final_result' => '4.00', 'inc_completion_note' => null]],
            $originalLockVersion,
            $roster->membership_signature,
            $faculty,
        );

        $this->assertSame('4.00', $row->fresh()->final_result);
        $this->assertSame(1, $row->fresh()->row_revision);
        $this->assertSame($originalLockVersion + 1, $saved->lock_version);

        try {
            $action->execute(
                $saved,
                [['id' => $row->id, 'final_result' => '2.00', 'inc_completion_note' => null]],
                $originalLockVersion,
                $saved->membership_signature,
                $faculty,
            );
            $this->fail('A stale roster lock must not overwrite a newer draft.');
        } catch (RuntimeException) {
            $this->assertSame('4.00', $row->fresh()->final_result);
        }

        try {
            $action->execute(
                $saved->fresh(),
                [['id' => $row->id, 'final_result' => '2.00', 'inc_completion_note' => null]],
                $saved->fresh()->lock_version,
                'stale-membership-signature',
                $faculty,
            );
            $this->fail('Stale membership evidence must not overwrite a current draft.');
        } catch (RuntimeException) {
            $this->assertSame('4.00', $row->fresh()->final_result);
            $this->assertSame(GradeRoster::StateDraft, $roster->fresh()->state);
        }
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
