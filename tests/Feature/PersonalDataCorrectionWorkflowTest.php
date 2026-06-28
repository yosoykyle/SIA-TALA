<?php

namespace Tests\Feature;

use App\Actions\Enrollment\PersonalDataCorrectionService;
use App\Models\ApplicantIntake;
use App\Models\PersonalDataCorrectionRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonalDataCorrectionWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected PersonalDataCorrectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate('student', 'web');

        $this->service = app(PersonalDataCorrectionService::class);
    }

    public function test_submitting_request_validates_fields(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'John',
            'middle_name' => 'Middle',
            'last_name' => 'Doe',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
            'birthdate' => '2005-05-15',
        ]);

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
        ]);

        // 1. Submit invalid field (not in the allowed list)
        $this->expectException(ValidationException::class);
        try {
            $this->service->submitRequest($studentProfile, [
                'email' => 'newemail@example.com',
            ]);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('requested_changes', $e->errors());
            throw $e;
        }
    }

    public function test_submitting_request_validates_difference_from_current(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'John',
            'middle_name' => 'Middle',
            'last_name' => 'Doe',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
            'birthdate' => '2005-05-15',
        ]);

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
        ]);

        // 2. Submit values that are identical to current values
        $this->expectException(ValidationException::class);
        try {
            $this->service->submitRequest($studentProfile, [
                'first_name' => 'John',
                'lrn' => '123456789012',
            ]);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('requested_changes', $e->errors());
            throw $e;
        }
    }

    public function test_submitting_request_blocks_duplicate_pending_requests(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'John',
            'middle_name' => 'Middle',
            'last_name' => 'Doe',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
            'birthdate' => '2005-05-15',
        ]);

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
        ]);

        // First submission (succeeds)
        $request1 = $this->service->submitRequest($studentProfile, [
            'first_name' => 'Johnny',
        ]);

        $this->assertDatabaseHas('personal_data_correction_requests', [
            'id' => $request1->id,
            'status' => PersonalDataCorrectionRequest::STATUS_PENDING,
        ]);

        // Second submission (fails because request1 is pending)
        $this->expectException(ValidationException::class);
        try {
            $this->service->submitRequest($studentProfile, [
                'last_name' => 'Smith',
            ]);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_profile_id', $e->errors());
            throw $e;
        }
    }

    public function test_resolving_request_requires_registrar_role(): void
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'John',
            'middle_name' => 'Middle',
            'last_name' => 'Doe',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
            'birthdate' => '2005-05-15',
        ]);

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
        ]);

        $request = $this->service->submitRequest($studentProfile, [
            'first_name' => 'Johnny',
        ]);

        // Student tries to resolve request
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');

        $this->expectException(AuthorizationException::class);
        $this->service->resolveRequest($request, $studentUser, 'approve');
    }

    public function test_approving_request_updates_related_records(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-06-28 12:00:00'));

        $user = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'John',
            'middle_name' => 'Middle',
            'last_name' => 'Doe',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
            'birthdate' => '2005-05-15',
        ]);

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
        ]);

        $request = $this->service->submitRequest($studentProfile, [
            'first_name' => 'Johnny',
            'middle_name' => 'NMN',
            'last_name' => 'Smith',
            'lrn' => '987654321098',
            'birthdate' => '2005-06-20',
        ]);

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $resolvedRequest = $this->service->resolveRequest($request, $registrar, 'approve');

        // Assert request status and metadata
        $this->assertEquals(PersonalDataCorrectionRequest::STATUS_APPROVED, $resolvedRequest->status);
        $this->assertEquals($registrar->id, $resolvedRequest->resolved_by);
        $this->assertEquals($now->toDateTimeString(), $resolvedRequest->resolved_at->toDateTimeString());

        // Assert old values are preserved
        $this->assertEquals([
            'first_name' => 'John',
            'middle_name' => 'Middle',
            'last_name' => 'Doe',
            'lrn' => '123456789012',
            'birthdate' => '2005-05-15',
        ], $resolvedRequest->old_values);

        // Assert User model updates
        $user->refresh();
        $this->assertEquals('Johnny', $user->first_name);
        $this->assertEquals('NMN', $user->middle_name);
        $this->assertEquals('Smith', $user->last_name);
        $this->assertEquals('Johnny NMN Smith', $user->name); // Composed full name

        // Assert StudentProfile updates
        $studentProfile->refresh();
        $this->assertEquals('987654321098', $studentProfile->lrn);

        // Assert ApplicantIntake updates
        $applicantIntake->refresh();
        $this->assertEquals('987654321098', $applicantIntake->lrn);
        $this->assertEquals('2005-06-20', $applicantIntake->birthdate->format('Y-m-d'));

        Carbon::setTestNow();
    }

    public function test_rejecting_request_stores_reject_reason(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-06-28 12:00:00'));

        $user = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'John',
            'middle_name' => 'Middle',
            'last_name' => 'Doe',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
            'birthdate' => '2005-05-15',
        ]);

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'lrn' => '123456789012',
        ]);

        $request = $this->service->submitRequest($studentProfile, [
            'first_name' => 'Johnny',
        ]);

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $resolvedRequest = $this->service->resolveRequest($request, $registrar, 'reject', 'Documents provided do not match.');

        // Assert request status and metadata
        $this->assertEquals(PersonalDataCorrectionRequest::STATUS_REJECTED, $resolvedRequest->status);
        $this->assertEquals($registrar->id, $resolvedRequest->resolved_by);
        $this->assertEquals($now->toDateTimeString(), $resolvedRequest->resolved_at->toDateTimeString());
        $this->assertEquals('Documents provided do not match.', $resolvedRequest->reject_reason);
        $this->assertNull($resolvedRequest->old_values);

        // Assert records did not change
        $user->refresh();
        $this->assertEquals('John', $user->first_name);

        $studentProfile->refresh();
        $this->assertEquals('123456789012', $studentProfile->lrn);

        $applicantIntake->refresh();
        $this->assertEquals('123456789012', $applicantIntake->lrn);

        Carbon::setTestNow();
    }
}
