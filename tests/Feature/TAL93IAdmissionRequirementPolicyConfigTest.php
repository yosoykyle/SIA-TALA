<?php

namespace Tests\Feature;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TAL93IAdmissionRequirementPolicyConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_legacy_intake_and_generic_policy_resources_are_not_reachable(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertNotContains(ApplicantIntakeResource::class, $resources);
        $this->assertNotContains(AdmissionRequirementPolicyResource::class, $resources);
        $this->assertFalse(Route::has('filament.admin.resources.applicant-intakes.index'));
        $this->assertFalse(Route::has('filament.admin.resources.admission-requirement-policies.index'));
    }

    public function test_canonical_cycle_and_application_resources_replace_the_legacy_setup_and_queue(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(AdmissionCycleResource::class, $resources);
        $this->assertContains(AdmissionApplicationResource::class, $resources);
        $this->assertTrue(Route::has('filament.admin.resources.admission-cycles.index'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-cycles.create'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-cycles.view'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-cycles.edit'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-applications.index'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-applications.view'));
    }

    public function test_registrar_can_access_the_canonical_admissions_surfaces(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $this->actingAs($registrar);

        $this->assertTrue(AdmissionCycleResource::canAccess());
        $this->assertTrue(AdmissionApplicationResource::canAccess());

        $this->get(AdmissionCycleResource::getUrl('index'))->assertOk();
        $this->get(AdmissionApplicationResource::getUrl('index'))->assertOk();
    }
}
