<?php

namespace Tests\Feature;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CanonicalAdmissionsRoutingTest extends TestCase
{
    public function test_canonical_admissions_resources_and_routes_replace_legacy_entry_points(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(AdmissionApplicationResource::class, $resources);
        $this->assertContains(AdmissionCycleResource::class, $resources);
        $this->assertNotContains(ApplicantIntakeResource::class, $resources);
        $this->assertNotContains(AdmissionRequirementPolicyResource::class, $resources);

        $this->assertTrue(Route::has('filament.admin.resources.admission-applications.index'));
        $this->assertTrue(Route::has('filament.admin.resources.admission-cycles.index'));
        $this->assertFalse(Route::has('filament.admin.resources.applicant-intakes.index'));
        $this->assertFalse(Route::has('filament.admin.resources.admission-requirement-policies.index'));
    }
}
