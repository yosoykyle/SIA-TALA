<?php

namespace Tests\Feature;

use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function faculty_can_select_between_multiple_assigned_active_rosters(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $firstRoster = $this->rosterWithRow($faculty, GradeRoster::StateDraft, studentLastName: 'Alpha');
        $secondRoster = $this->rosterWithRow($faculty, GradeRoster::StateReturned, studentLastName: 'Beta');
        $firstRow = $firstRoster->rows()->sole();
        $secondRow = $secondRoster->rows()->sole();

        Livewire::actingAs($faculty)
            ->test(FacultyGradeRoster::class)
            ->assertCanSeeTableRecords([$secondRow])
            ->assertCanNotSeeTableRecords([$firstRow])
            ->callTableAction('selectRoster', data: [
                'rosterId' => $firstRoster->id,
            ])
            ->assertHasNoTableActionErrors()
            ->assertCanSeeTableRecords([$firstRow])
            ->assertCanNotSeeTableRecords([$secondRow]);
    }

    #[Test]
    public function registrar_can_return_a_submitted_roster_from_the_review_table_with_required_reason(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);

        Livewire::actingAs($registrar)
            ->test(ListGradeRosters::class)
            ->assertTableActionVisible('return', $roster)
            ->callTableAction('return', $roster, data: [
                'reason' => 'Correct the final period equivalent before release.',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Grade roster returned');

        $roster->refresh();

        $this->assertSame(GradeRoster::StateReturned, $roster->state);
        $this->assertSame('Correct the final period equivalent before release.', $roster->return_reason);
        $this->assertSame($registrar->id, $roster->reviewed_by);
        $this->assertNotNull($roster->reviewed_at);
    }

    #[Test]
    public function registrar_can_post_and_release_a_submitted_roster_from_the_review_table(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);
        $row = $roster->rows()->sole();

        Livewire::actingAs($registrar)
            ->test(ListGradeRosters::class)
            ->assertTableActionVisible('postAndRelease', $roster)
            ->callTableAction('postAndRelease', $roster)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Grade roster posted and released');

        $roster->refresh();
        $row->refresh();

        $this->assertSame(GradeRoster::StateReleased, $roster->state);
        $this->assertSame($registrar->id, $roster->released_by);
        $this->assertNotNull($roster->released_at);
        $this->assertSame('1.75', $row->current_outcome_code);
        $this->assertSame(GradeRosterRow::CategoryPassing, $row->current_outcome_category);
        $this->assertNotNull($row->released_at);
        $this->assertDatabaseHas('grade_outcome_events', [
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'new_category' => GradeRosterRow::CategoryPassing,
            'recorded_by' => $registrar->id,
        ]);
    }

    #[Test]
    public function non_registrar_staff_cannot_use_registrar_review_table_actions(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);

        Livewire::actingAs($academicHead)
            ->test(ListGradeRosters::class)
            ->assertTableActionHidden('return', $roster)
            ->assertTableActionHidden('postAndRelease', $roster);
    }

    #[Test]
    public function academic_head_cannot_directly_return_a_submitted_roster_through_the_action_service(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);

        try {
            app(ReturnGradeRoster::class)->execute($roster, $academicHead, 'Return outside Registrar authority.');

            $this->fail('Academic Head direct roster return should be rejected.');
        } catch (AuthorizationException) {
            $roster->refresh();

            $this->assertSame(GradeRoster::StateSubmitted, $roster->state);
            $this->assertNull($roster->return_reason);
            $this->assertNull($roster->reviewed_by);
            $this->assertNull($roster->reviewed_at);
        }
    }

    #[Test]
    public function faculty_cannot_directly_post_and_release_a_submitted_roster_through_the_action_service(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted, submittedBy: $faculty);
        $row = $roster->rows()->sole();

        try {
            app(PostAndReleaseGradeRoster::class)->execute($roster, $faculty);

            $this->fail('Faculty direct roster post and release should be rejected.');
        } catch (AuthorizationException) {
            $roster->refresh();
            $row->refresh();

            $this->assertSame(GradeRoster::StateSubmitted, $roster->state);
            $this->assertNull($roster->released_by);
            $this->assertNull($roster->released_at);
            $this->assertNull($row->current_outcome_code);
            $this->assertNull($row->current_outcome_category);
            $this->assertNull($row->released_at);
            $this->assertSame(0, GradeOutcomeEvent::query()->where('grade_roster_row_id', $row->id)->count());
        }
    }

    private function rosterWithRow(
        User $faculty,
        string $state,
        ?User $submittedBy = null,
        string $studentLastName = 'Student',
    ): GradeRoster {
        $termOffering = TermOffering::factory()->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->create(['term_offering_id' => $termOffering->id]);
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'last_name' => $studentLastName,
        ]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'program_id' => $termOffering->curriculumEntry->curriculumVersion->program_id,
            'curriculum_version_id' => $termOffering->curriculumEntry->curriculum_version_id,
            'last_name' => $studentLastName,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $termOffering->term_id,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $termOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $termOffering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'state' => $state,
            'submitted_by' => $submittedBy?->id,
            'submitted_at' => $submittedBy === null ? null : now(),
        ]);

        GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'prelim_equivalent' => 90,
            'midterm_equivalent' => 91,
            'final_equivalent' => 94,
            'computed_average' => 91.9,
        ]);

        return $roster;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
