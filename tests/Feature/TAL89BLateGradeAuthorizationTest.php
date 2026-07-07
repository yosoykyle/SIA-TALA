<?php

namespace Tests\Feature;

use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\SaveGradeRosterPeriodEquivalent;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\LateGradeAuthorization;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL89BLateGradeAuthorizationTest extends TestCase
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
    public function registrar_can_create_scoped_late_authorization_for_roster_period(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateLateNotSubmitted);
        $opensAt = now()->subMinutes(10)->seconds(0);
        $closesAt = now()->addHours(2)->seconds(0);

        $authorization = app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            $opensAt,
            $closesAt,
            'Faculty submitted approved late grade request.',
            $registrar,
        );

        $this->assertSame($roster->id, $authorization->grade_roster_id);
        $this->assertSame($roster->term_offering_id, $authorization->term_offering_id);
        $this->assertSame($faculty->id, $authorization->faculty_user_id);
        $this->assertSame(LateGradeAuthorization::PeriodFinal, $authorization->grading_period);
        $this->assertSame('Faculty submitted approved late grade request.', $authorization->reason);
        $this->assertSame($registrar->id, $authorization->approved_by);
        $this->assertSame($opensAt->toDateTimeString(), $authorization->opens_at->toDateTimeString());
        $this->assertSame($closesAt->toDateTimeString(), $authorization->closes_at->toDateTimeString());
        $this->assertSame(LateGradeAuthorization::StateActive, $authorization->state);
    }

    #[Test]
    public function academic_head_can_create_scoped_late_authorization_for_roster_period(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateReturned);

        $authorization = app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodMidterm,
            now()->subMinutes(5),
            now()->addHour(),
            'Academic Head approved returned-roster re-entry.',
            $academicHead,
        );

        $this->assertSame($academicHead->id, $authorization->approved_by);
        $this->assertSame(LateGradeAuthorization::PeriodMidterm, $authorization->grading_period);
    }

    #[Test]
    public function non_authorized_staff_cannot_create_late_authorization_through_service(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateLateNotSubmitted);

        $this->expectException(AuthorizationException::class);

        app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            now()->subMinute(),
            now()->addHour(),
            'Faculty cannot approve own late encoding.',
            $faculty,
        );
    }

    #[Test]
    public function invalid_grading_period_is_rejected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateLateNotSubmitted);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid grading period.');

        app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            'summer',
            now()->subMinute(),
            now()->addHour(),
            'Invalid period should not create an authorization.',
            $registrar,
        );
    }

    #[Test]
    public function overlapping_active_late_authorization_for_same_roster_period_is_rejected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateLateNotSubmitted);

        app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            now()->subMinutes(15),
            now()->addHour(),
            'First active late authorization.',
            $registrar,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An active overlapping late grade authorization already exists.');

        app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            now()->addMinutes(30),
            now()->addHours(2),
            'Second overlapping authorization.',
            $registrar,
        );
    }

    #[Test]
    public function late_authorization_is_limited_to_returned_or_late_not_submitted_rosters(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Late grade authorization is only available for returned or late-not-submitted rosters.');

        app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            now()->subMinute(),
            now()->addHour(),
            'Submitted rosters should use Registrar review actions instead.',
            $registrar,
        );
    }

    #[Test]
    public function returned_or_late_not_submitted_roster_reentry_opens_only_with_late_authorization(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateLateNotSubmitted);
        $row = $roster->rows()->sole();

        app(AuthorizeLateGradeEncoding::class)->execute(
            $roster,
            LateGradeAuthorization::PeriodFinal,
            now()->subMinute(),
            now()->addHour(),
            'Registrar opened scoped final-period re-entry.',
            $registrar,
        );

        $saved = app(SaveGradeRosterPeriodEquivalent::class)->execute($row, LateGradeAuthorization::PeriodFinal, 94, $faculty);

        $this->assertSame('94.0000', $saved->final_equivalent);
    }

    #[Test]
    public function closed_calendar_without_late_authorization_rejects_roster_reentry(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateReturned);
        $row = $roster->rows()->sole();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The grade encoding window is closed for this period.');

        app(SaveGradeRosterPeriodEquivalent::class)->execute($row, LateGradeAuthorization::PeriodFinal, 94, $faculty);
    }

    #[Test]
    public function grade_roster_review_table_exposes_late_authorization_action_to_authorized_staff_only_for_meaningful_states(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $returnedRoster = $this->rosterWithRow($faculty, GradeRoster::StateReturned);
        $lateRoster = $this->rosterWithRow($faculty, GradeRoster::StateLateNotSubmitted);
        $submittedRoster = $this->rosterWithRow($faculty, GradeRoster::StateSubmitted);

        Livewire::actingAs($registrar)
            ->test(ListGradeRosters::class)
            ->assertTableActionVisible('authorizeLateGradeEncoding', $returnedRoster)
            ->assertTableActionVisible('authorizeLateGradeEncoding', $lateRoster)
            ->assertTableActionHidden('authorizeLateGradeEncoding', $submittedRoster);

        Livewire::actingAs($academicHead)
            ->test(ListGradeRosters::class)
            ->assertTableActionVisible('authorizeLateGradeEncoding', $returnedRoster);

        Livewire::actingAs($faculty)
            ->test(ListGradeRosters::class)
            ->assertTableActionHidden('authorizeLateGradeEncoding', $returnedRoster);
    }

    #[Test]
    public function grade_roster_review_table_action_creates_late_authorization(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateLateNotSubmitted);
        $opensAt = now()->subMinutes(5)->seconds(0);
        $closesAt = now()->addHours(3)->seconds(0);

        Livewire::actingAs($registrar)
            ->test(ListGradeRosters::class)
            ->callTableAction('authorizeLateGradeEncoding', $roster, data: [
                'period' => LateGradeAuthorization::PeriodFinal,
                'opens_at' => $opensAt->format('Y-m-d H:i:s'),
                'closes_at' => $closesAt->format('Y-m-d H:i:s'),
                'reason' => 'Registrar approved final-period late encoding.',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Late grade authorization opened');

        $authorization = LateGradeAuthorization::query()->sole();

        $this->assertSame($roster->id, $authorization->grade_roster_id);
        $this->assertSame($faculty->id, $authorization->faculty_user_id);
        $this->assertSame(LateGradeAuthorization::PeriodFinal, $authorization->grading_period);
        $this->assertSame('Registrar approved final-period late encoding.', $authorization->reason);
        $this->assertSame($registrar->id, $authorization->approved_by);
    }

    #[Test]
    public function grade_roster_view_page_exposes_late_authorization_header_action(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $roster = $this->rosterWithRow($faculty, GradeRoster::StateReturned);

        Livewire::actingAs($registrar)
            ->test(ViewGradeRoster::class, ['record' => $roster->getRouteKey()])
            ->assertActionVisible('authorizeLateGradeEncoding')
            ->callAction('authorizeLateGradeEncoding', data: [
                'period' => LateGradeAuthorization::PeriodMidterm,
                'opens_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
                'closes_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
                'reason' => 'Returned roster midterm correction window.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Late grade authorization opened');

        $this->assertDatabaseHas('late_grade_authorizations', [
            'grade_roster_id' => $roster->id,
            'faculty_user_id' => $faculty->id,
            'grading_period' => LateGradeAuthorization::PeriodMidterm,
            'approved_by' => $registrar->id,
            'reason' => 'Returned roster midterm correction window.',
        ]);
    }

    private function rosterWithRow(User $faculty, string $state): GradeRoster
    {
        $termOffering = TermOffering::factory()->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->create(['term_offering_id' => $termOffering->id]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'program_id' => $termOffering->curriculumEntry->curriculumVersion->program_id,
            'curriculum_version_id' => $termOffering->curriculumEntry->curriculum_version_id,
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
        ]);

        GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
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
