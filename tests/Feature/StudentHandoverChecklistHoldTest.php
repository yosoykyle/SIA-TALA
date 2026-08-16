<?php

namespace Tests\Feature;

use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Models\ApplicantIntake;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentHandoverChecklistHoldTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
    }

    public function test_legacy_admission_handover_is_retired_without_creating_student_or_enrollment_records(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusApproved,
            'approved_at' => now(),
            'approved_by' => $registrar->id,
        ]);

        try {
            app(HandOverApprovedApplicant::class)->execute($intake, $registrar);
            $this->fail('The retired handover must never create a Student or enrollment record.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Ready for Enrollment', $exception->getMessage());
        }

        $this->assertSame(0, StudentProfile::query()->where('applicant_intake_id', $intake->id)->count());
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertNull($intake->fresh()->handed_over_at);
    }
}
