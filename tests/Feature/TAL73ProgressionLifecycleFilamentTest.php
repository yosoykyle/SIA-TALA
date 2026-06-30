<?php

namespace Tests\Feature;

use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\StudentLifecycleChanges\Pages\CreateStudentLifecycleChange;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Student\Pages\LifecycleView;
use App\Models\Enrollment;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL73ProgressionLifecycleFilamentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function resources_are_registered_and_policy_boundaries_are_server_enforced(): void
    {
        $resources = Filament::getPanel('admin')->getResources();
        $this->assertContains(StudentProfileResource::class, $resources);
        $this->assertContains(StudentLifecycleChangeResource::class, $resources);

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(StudentProfileResource::canAccess());
        $this->assertTrue(StudentLifecycleChangeResource::canCreate());
        Livewire::test(ListStudentProfiles::class)->assertOk();

        $accounting = $this->staff(User::StaffRoleAccounting);
        $this->actingAs($accounting);
        $this->assertTrue(StudentLifecycleChangeResource::canAccess());
        $this->assertFalse(StudentLifecycleChangeResource::canCreate());

        $student = $this->staff('student');
        $this->actingAs($student);
        $this->assertFalse(StudentLifecycleChangeResource::canAccess());
    }

    #[Test]
    public function operational_table_filters_and_program_shift_apply_action_visibility_work(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $withdrawal = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        $shift = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateRecordedApproved,
        ]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListStudentLifecycleChanges::class)
            ->filterTable('type', StudentLifecycleChange::TypeWithdrawal)
            ->assertCanSeeTableRecords([$withdrawal])
            ->assertCanNotSeeTableRecords([$shift]);

        Livewire::test(ListStudentLifecycleChanges::class)
            ->assertActionVisible(TestAction::make('apply')->table($shift))
            ->assertActionHidden(TestAction::make('apply')->table($withdrawal));
    }

    #[Test]
    public function create_modal_contract_validates_required_approved_result_fields(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateStudentLifecycleChange::class)
            ->fillForm([])
            ->call('create')
            ->assertHasFormErrors([
                'student_profile_id' => 'required',
                'term_id' => 'required',
                'type' => 'required',
                'effective_on' => 'required',
                'authority' => 'required',
                'reason' => 'required',
            ]);
    }

    #[Test]
    public function registrar_sees_focused_standing_and_unit_load_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $profile = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id]);
        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->assertActionVisible('confirmStanding');
        Livewire::test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertActionVisible('unitLoadException');
    }

    #[Test]
    public function student_hub_lifecycle_projection_is_read_only_and_own_record_only(): void
    {
        $student = $this->staff('student');
        $ownProfile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $otherProfile = StudentProfile::factory()->create();
        $own = StudentLifecycleChange::factory()->create(['student_profile_id' => $ownProfile->id, 'state' => StudentLifecycleChange::StateApplied]);
        $other = StudentLifecycleChange::factory()->create(['student_profile_id' => $otherProfile->id, 'state' => StudentLifecycleChange::StateApplied]);
        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(LifecycleView::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
