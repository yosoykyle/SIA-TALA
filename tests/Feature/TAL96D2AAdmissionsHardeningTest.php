<?php

namespace Tests\Feature;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TAL96D2AAdmissionsHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

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
