<?php

namespace Tests\Feature;

use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TAL83BApplicantHandoverActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_legacy_handover_action_is_retired_without_creating_student_records(): void
    {
        $intake = ApplicantIntake::factory()->create();

        $this->assertFileDoesNotExist(app_path('Actions/Applicants/HandOverApprovedApplicant.php'));
        $this->assertSame(0, StudentProfile::query()->count());
        $this->assertNull($intake->fresh()->handed_over_at);
        $this->assertNull($intake->fresh()->handed_over_by);
    }

    public function test_legacy_intake_and_policy_routes_are_not_reachable(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.applicant-intakes.index'));
        $this->assertFalse(Route::has('filament.admin.resources.applicant-intakes.view'));
        $this->assertFalse(Route::has('filament.admin.resources.admission-requirement-policies.index'));
        $this->assertFalse(Route::has('filament.admin.resources.admission-requirement-policies.create'));
    }
}
