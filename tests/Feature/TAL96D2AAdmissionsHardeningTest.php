<?php

namespace Tests\Feature;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TAL96D2AAdmissionsHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_panel_registers_only_the_canonical_admissions_resources(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(AdmissionApplicationResource::class, $resources);
        $this->assertContains(AdmissionCycleResource::class, $resources);
        $this->assertNotContains(ApplicantIntakeResource::class, $resources);
        $this->assertNotContains(AdmissionRequirementPolicyResource::class, $resources);
    }

    public function test_legacy_admissions_routes_are_unreachable_while_canonical_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.admission-applications.index'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-cycles.index'));
        $this->assertFalse(Route::has('filament.admin.resources.applicant-intakes.index'));
        $this->assertFalse(Route::has('filament.admin.resources.admission-requirement-policies.index'));
    }

    public function test_application_state_does_not_mutate_account_identity_or_create_student_records(): void
    {
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $application = AdmissionApplication::factory()
            ->for($applicant, 'user')
            ->create([
                'application_state' => AdmissionApplication::StateAdmitted,
            ]);

        $this->assertSame(User::StatusActive, $applicant->fresh()->status);
        $this->assertSame(AdmissionApplication::StateAdmitted, $application->fresh()->application_state);
        $this->assertSame(AdmissionCycle::PathFirstYear, $application->application_path);
        $this->assertSame(0, StudentProfile::query()->count());
    }
}
