<?php

namespace Tests\Feature;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ViewStudentLifecycleChange;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Student\Pages\LifecycleView;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D6BAcademicStandingLifecycleResultTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function system_admin_cannot_record_individual_standing_or_lifecycle_results(): void
    {
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $profile = StudentProfile::factory()->create();
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateRecordedApproved,
        ]);

        $this->actingAs($systemAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(StudentLifecycleChangeResource::canCreate());
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->assertActionHidden('confirmStanding');
        Livewire::test(ListStudentLifecycleChanges::class)
            ->assertActionHidden(TestAction::make('apply')->table($change))
            ->assertActionHidden(TestAction::make('cancel')->table($change));

        try {
            app(AcademicProgressionService::class)->confirmStanding(
                $profile,
                StudentProfile::StandingIrregular,
                $systemAdmin,
                'Crafted request.',
            );
            $this->fail('System Super Admin unexpectedly confirmed academic standing.');
        } catch (AuthorizationException) {
            $this->assertSame(StudentProfile::StandingRegular, $profile->fresh()->academic_standing);
        }

        try {
            app(StudentLifecycleService::class)->record([], $systemAdmin);
            $this->fail('System Super Admin unexpectedly recorded a lifecycle result.');
        } catch (AuthorizationException) {
            $this->assertSame(1, StudentLifecycleChange::query()->whereKey($change->id)->count());
        }
    }

    #[Test]
    public function registrar_receives_decision_context_and_records_an_audited_standing(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingProbationary,
        ]);
        $this->curriculumEntry($profile);

        $progression = app(AcademicProgressionService::class)->evaluate($profile);
        $this->assertFalse($progression['recommendation']['available']);
        $this->assertNull($progression['recommendation']['standing']);
        $this->assertSame('Institutional review required', $progression['recommendation']['label']);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->assertSee('Official Academic Standing')
            ->assertSee('Institutional review required')
            ->assertSee('Academic Blockers and Recovery')
            ->mountAction('confirmStanding')
            ->assertMountedActionModalSee('Decision Evidence')
            ->assertMountedActionModalSee('Current Official Standing')
            ->assertMountedActionModalSee('Institutional review required')
            ->assertMountedActionModalSee('Record confirmed standing')
            ->setActionData([
                'standing' => StudentProfile::StandingDeficient,
                'reason' => 'Released grades and curriculum requirements were reviewed.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Academic standing confirmed');

        $this->assertSame(StudentProfile::StandingDeficient, $profile->fresh()->academic_standing);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'academic_standing_confirmed',
            'subject_type' => StudentProfile::class,
            'subject_id' => $profile->id,
            'causer_id' => $registrar->id,
        ]);
    }

    #[Test]
    public function staff_lifecycle_surfaces_identify_the_student_and_complete_recorded_result(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['name' => 'Diploma in Test Operations']);
        $profile = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'student_number' => 'TAL-D6B-001',
            'first_name' => 'Maya',
            'middle_name' => null,
            'last_name' => 'Santos',
        ]);
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'recorded_by' => $registrar->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
            'authority' => 'Registrar-approved withdrawal',
            'reason' => 'Approved withdrawal from the current Term.',
            'private_source_reference' => 'PRIVATE-D6B-REF',
            'decided_on' => '2026-07-28',
            'effective_on' => '2026-07-30',
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListStudentLifecycleChanges::class)
            ->assertSee('Santos, Maya')
            ->assertSee('TAL-D6B-001')
            ->assertSee('Diploma in Test Operations')
            ->assertSee('Applied to official record')
            ->assertSee('Responsible office: Registrar Office');

        Livewire::test(ViewStudentLifecycleChange::class, ['record' => $change->getRouteKey()])
            ->assertSee('Recorded Result')
            ->assertSee('Decision Date')
            ->assertSee('Recorded By')
            ->assertSee($registrar->name)
            ->assertSee('Registrar Office')
            ->assertSee('PRIVATE-D6B-REF');
    }

    #[Test]
    public function student_academic_status_is_owner_scoped_and_hides_staff_only_decision_evidence(): void
    {
        $student = $this->staff('student');
        $ownProfile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        $otherProfile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['label' => 'Second Semester']);
        $ownChange = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $ownProfile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
            'authority' => 'STAFF-ONLY AUTHORITY',
            'reason' => 'STAFF-ONLY REASON',
            'private_source_reference' => 'STAFF-ONLY REFERENCE',
        ]);
        $otherChange = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $otherProfile->id,
            'term_id' => $term->id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(LifecycleView::class)
            ->assertCanSeeTableRecords([$ownChange])
            ->assertCanNotSeeTableRecords([$otherChange])
            ->assertSee('Official academic standing: Irregular')
            ->assertSee('Applied to your official record')
            ->assertSee('Registrar Office')
            ->assertDontSee('STAFF-ONLY AUTHORITY')
            ->assertDontSee('STAFF-ONLY REASON')
            ->assertDontSee('STAFF-ONLY REFERENCE');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function curriculumEntry(StudentProfile $profile): CurriculumEntry
    {
        $course = Course::factory()->create(['code' => 'D6B-COURSE']);
        $specification = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'title' => 'D6B Course',
        ]);

        return CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $specification->id,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);
    }
}
