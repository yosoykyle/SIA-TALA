<?php

namespace Tests\Feature;

use App\Actions\StudentHub\StudentDashboardService;
use App\Filament\Student\Pages\GradesView;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL89DStudentGradeVisibilityTest extends TestCase
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

        Filament::setCurrentPanel(Filament::getPanel('student'));
    }

    #[Test]
    public function grades_view_shows_only_released_rows_for_the_authenticated_student_with_student_facing_grade_labels(): void
    {
        [$student, $profile] = $this->student();

        $numeric = $this->gradeRowForStudent($profile, 'MATH-101', 'College Algebra', '1.75', GradeRosterRow::CategoryPassing, released: true);
        $incomplete = $this->gradeRowForStudent($profile, 'ENG-102', 'English Communication', 'INC', GradeRosterRow::CategoryIncomplete, released: true);
        $pending = $this->gradeRowForStudent($profile, 'HIST-103', 'Philippine History', 'P', GradeRosterRow::CategoryPending, released: true);
        $dropped = $this->gradeRowForStudent($profile, 'CHEM-104', 'Chemistry Fundamentals', 'DRP', GradeRosterRow::CategoryWithdrawn, released: true);
        $withdrawn = $this->gradeRowForStudent($profile, 'BIO-105', 'General Biology', 'W', GradeRosterRow::CategoryWithdrawn, released: true);
        $transferCredit = $this->gradeRowForStudent($profile, 'PE-106', 'Physical Education', 'TC', GradeRosterRow::CategoryTransferCredit, released: true);
        $hiddenDraft = $this->gradeRowForStudent($profile, 'HIDE-107', 'Unreleased Draft Row', '2.25', GradeRosterRow::CategoryPassing, released: false);

        [, $otherProfile] = $this->student();
        $otherStudentReleased = $this->gradeRowForStudent($otherProfile, 'OTHR-108', 'Other Student Course', '1.50', GradeRosterRow::CategoryPassing, released: true);

        GradeOutcomeEvent::query()->create([
            'grade_roster_row_id' => $incomplete->id,
            'event_type' => GradeOutcomeEvent::TypeIncResolution,
            'previous_value' => null,
            'new_value' => null,
            'previous_category' => GradeRosterRow::CategoryIncomplete,
            'new_category' => GradeRosterRow::CategoryPassing,
            'authority' => 'TAL89D INTERNAL AUTHORITY',
            'reason' => 'TAL89D INTERNAL REASON',
            'evidence_reference' => 'TAL89D-PRIVATE-EVIDENCE',
            'recorded_by' => $student->id,
        ]);

        $component = $this->gradesViewComponent($student);

        $component->assertSuccessful();

        $component
            ->assertCanSeeTableRecords([$numeric, $incomplete, $pending, $dropped, $withdrawn, $transferCredit])
            ->assertCanNotSeeTableRecords([$hiddenDraft, $otherStudentReleased])
            ->assertTableColumnFormattedStateSet('current_outcome_code', '1.75', record: $numeric)
            ->assertTableColumnFormattedStateSet('current_outcome_code', 'Incomplete', record: $incomplete)
            ->assertTableColumnFormattedStateSet('current_outcome_code', 'Pending Grade', record: $pending)
            ->assertTableColumnFormattedStateSet('current_outcome_code', 'Withdrawn', record: $dropped)
            ->assertTableColumnFormattedStateSet('current_outcome_code', 'Withdrawn', record: $withdrawn)
            ->assertTableColumnFormattedStateSet('current_outcome_code', 'Transfer Credit', record: $transferCredit)
            ->assertDontSee('TAL89D INTERNAL AUTHORITY')
            ->assertDontSee('TAL89D INTERNAL REASON')
            ->assertDontSee('TAL89D-PRIVATE-EVIDENCE');
    }

    #[Test]
    public function student_dashboard_service_exposes_student_facing_grade_labels_without_internal_grade_audit_fields(): void
    {
        [, $profile] = $this->student();

        $this->gradeRowForStudent($profile, 'NUM-201', 'Numerical Grade', '2.00', GradeRosterRow::CategoryPassing, released: true);
        $this->gradeRowForStudent($profile, 'INC-202', 'Incomplete Grade', 'INC', GradeRosterRow::CategoryIncomplete, released: true);
        $this->gradeRowForStudent($profile, 'PEN-203', 'Pending Grade', 'P', GradeRosterRow::CategoryPending, released: true);
        $this->gradeRowForStudent($profile, 'WDR-204', 'Withdrawn Grade', 'W', GradeRosterRow::CategoryWithdrawn, released: true);
        $this->gradeRowForStudent($profile, 'TRC-205', 'Transfer Credit Grade', 'TC', GradeRosterRow::CategoryTransferCredit, released: true);
        $this->gradeRowForStudent($profile, 'DRF-206', 'Draft Grade', '1.25', GradeRosterRow::CategoryPassing, released: false);

        $method = new \ReflectionMethod(StudentDashboardService::class, 'gradesByTerm');
        $method->setAccessible(true);

        /** @var list<array{grades:list<array<string,mixed>>}> $terms */
        $terms = $method->invoke(app(StudentDashboardService::class), $profile);

        $grades = collect($terms)
            ->flatMap(fn (array $term): array => $term['grades'])
            ->keyBy('subject_code');

        $this->assertSame('2.00', $grades['NUM-201']['display_grade']);
        $this->assertSame('Incomplete', $grades['INC-202']['display_grade']);
        $this->assertSame('Pending Grade', $grades['PEN-203']['display_grade']);
        $this->assertSame('Withdrawn', $grades['WDR-204']['display_grade']);
        $this->assertSame('Transfer Credit', $grades['TRC-205']['display_grade']);
        $this->assertFalse($grades->has('DRF-206'));

        foreach ($grades as $grade) {
            $this->assertArrayNotHasKey('authority', $grade);
            $this->assertArrayNotHasKey('reason', $grade);
            $this->assertArrayNotHasKey('evidence_reference', $grade);
        }
    }

    #[Test]
    public function staff_roles_cannot_access_the_student_grades_view_route(): void
    {
        foreach ([
            User::StaffRoleFaculty,
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ] as $role) {
            $staff = User::factory()->create([
                'status' => User::StatusActive,
                'email_verified_at' => now(),
            ]);
            $staff->assignRole($role);

            $this->actingAs($staff)
                ->get('/student/grades-view')
                ->assertForbidden();
        }
    }

    /**
     * @return array{0: User, 1: StudentProfile}
     */
    private function student(): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');

        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
        ]);

        return [$student, $profile];
    }

    private function gradeRowForStudent(
        StudentProfile $studentProfile,
        string $courseCode,
        string $courseTitle,
        string $outcomeCode,
        string $outcomeCategory,
        bool $released,
    ): GradeRosterRow {
        $termOffering = TermOffering::factory()->create([
            'state' => TermOffering::StateScheduled,
        ]);
        $termOffering->curriculumEntry->courseSpecification->course->update([
            'code' => $courseCode,
        ]);
        $termOffering->curriculumEntry->courseSpecification->update([
            'title' => $courseTitle,
        ]);

        $section = Section::factory()->create([
            'term_offering_id' => $termOffering->id,
        ]);

        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
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

        $faculty = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $faculty->assignRole(User::StaffRoleFaculty);

        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $termOffering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'state' => $released ? GradeRoster::StateReleased : GradeRoster::StateSubmitted,
            'submitted_by' => $faculty->id,
            'submitted_at' => now(),
            'released_by' => $released ? $faculty->id : null,
            'released_at' => $released ? now() : null,
        ]);

        return GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'prelim_equivalent' => 90,
            'midterm_equivalent' => 91,
            'final_equivalent' => 92,
            'computed_average' => 91.0000,
            'current_outcome_code' => $outcomeCode,
            'current_outcome_category' => $outcomeCategory,
            'released_at' => $released ? now() : null,
        ]);
    }

    private function gradesViewComponent(User $student): Testable
    {
        Livewire::actingAs($student);

        return Livewire::test(GradesView::class);
    }
}
