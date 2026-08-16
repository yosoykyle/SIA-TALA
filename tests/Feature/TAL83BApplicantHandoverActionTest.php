<?php

namespace Tests\Feature;

use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TAL83BApplicantHandoverActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_legacy_handover_action_is_retired_without_creating_student_records(): void
    {
        $actor = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $intake = ApplicantIntake::factory()->create();

        try {
            app(HandOverApprovedApplicant::class)->execute($intake, $actor);
            $this->fail('The retired handover action unexpectedly created a Student record.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The legacy Applicant handover is retired. Admission ends at the derived Ready for Enrollment state; Student and enrollment records are created only by the authorized registration journey.',
                $exception->errors()['application'][0],
            );
        }

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
