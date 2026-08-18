<?php

namespace Tests\Feature;

use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Models\AdmissionApplication;
use App\Models\ApplicantIntake;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5E1B2BAdmissionsWorkQueueTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo([
            Permission::findOrCreate('approve-documents', 'web'),
            Permission::findOrCreate('manage-admission-setup', 'web'),
        ]);
    }

    public function test_canonical_admissions_resources_replace_the_legacy_intake_and_generic_policy_routes(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(AdmissionApplicationResource::class, $resources);
        $this->assertContains(AdmissionCycleResource::class, $resources);
        $this->assertNotContains(ApplicantIntakeResource::class, $resources);
        $this->assertNotContains(AdmissionRequirementPolicyResource::class, $resources);
    }

    public function test_registrar_work_queue_uses_journey_states_and_business_filters_without_bulk_decisions(): void
    {
        $registrar = $this->registrar();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListAdmissionApplications::class)
            ->assertSee('Needs review')
            ->assertSee('Waiting for applicant')
            ->assertSee('Official credentials')
            ->assertSee('Ready for enrollment')
            ->assertSee('History')
            ->assertTableColumnExists('applicant')
            ->assertTableColumnExists('scope')
            ->assertTableColumnExists('application_state')
            ->assertTableColumnExists('owner_next_action')
            ->assertTableColumnExists('application_path')
            ->assertTableColumnExists('correction_status')
            ->assertTableColumnExists('updated_at')
            ->assertTableFilterExists('admission_cycle_id')
            ->assertTableFilterExists('program_id')
            ->assertTableFilterExists('application_path')
            ->assertTableFilterExists('application_state')
            ->assertTableFilterExists('overdue_correction')
            ->assertTableBulkActionDoesNotExist('admit')
            ->assertTableBulkActionDoesNotExist('verify')
            ->assertTableBulkActionDoesNotExist('withdraw');
    }

    public function test_retired_handover_cannot_create_student_or_enrollment_records(): void
    {
        $registrar = $this->registrar();
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $intake = ApplicantIntake::factory()->approved($registrar)->create([
            'user_id' => $applicant->id,
        ]);

        try {
            app(HandOverApprovedApplicant::class)->execute($intake, $registrar);
            $this->fail('The legacy handover path must remain retired.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Ready for Enrollment', $exception->getMessage());
        }

        $this->assertSame(0, StudentProfile::query()->where('applicant_intake_id', $intake->id)->count());
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_work_queue_tabs_keep_current_journey_states_separate(): void
    {
        $registrar = $this->registrar();
        $submitted = AdmissionApplication::factory()->submitted()->create();
        $actionNeeded = AdmissionApplication::factory()->submitted()->create([
            'application_state' => AdmissionApplication::StateActionNeeded,
        ]);
        $history = AdmissionApplication::factory()->submitted()->create([
            'application_state' => AdmissionApplication::StateWithdrawn,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListAdmissionApplications::class)
            ->set('activeTab', 'needs_review')
            ->assertCanSeeTableRecords([$submitted])
            ->assertCanNotSeeTableRecords([$actionNeeded, $history])
            ->set('activeTab', 'waiting_for_applicant')
            ->assertCanSeeTableRecords([$actionNeeded])
            ->assertCanNotSeeTableRecords([$submitted, $history])
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$history])
            ->assertCanNotSeeTableRecords([$submitted, $actionNeeded]);
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
