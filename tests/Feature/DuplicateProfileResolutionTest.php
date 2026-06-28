<?php

namespace Tests\Feature;

use App\Actions\Enrollment\DuplicateProfileResolver;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ChecklistItem;
use App\Models\DuplicateProfileResolution;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DuplicateProfileResolutionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate('student', 'web');
    }

    public function test_authorization_verification(): void
    {
        $studentUser = User::factory()->create();
        $studentUser->assignRole('student');

        $this->expectException(AuthorizationException::class);
        app(DuplicateProfileResolver::class)->resolve(
            StudentProfile::factory()->create(),
            StudentProfile::factory()->create(),
            'LINKED_DUPLICATE',
            'Confirmed duplicate identity.',
            $studentUser,
        );
    }

    public function test_validation_integrity(): void
    {
        $registrar = $this->registrar();
        $student = StudentProfile::factory()->create();

        try {
            app(DuplicateProfileResolver::class)->resolve($student, $student, 'LINKED_DUPLICATE', 'Same profile.', $registrar);
            $this->fail('Expected self-link validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('duplicate_student_profile_id', $exception->errors());
        }
    }

    public function test_linked_duplicate_resolution_success_flow(): void
    {
        $registrar = $this->registrar();
        $duplicate = StudentProfile::factory()->create();
        $duplicate->user->assignRole('student');
        $primary = StudentProfile::factory()->create();
        $policy = AdmissionRequirementPolicy::factory()->create();
        $history = ChecklistItem::factory()->forStudent($duplicate)->create([
            'source_policy_id' => $policy->id,
        ]);

        $resolution = app(DuplicateProfileResolver::class)->resolve(
            $duplicate,
            $primary,
            'LINKED_DUPLICATE',
            'Registrar confirmed a duplicate official profile.',
            $registrar,
        );

        $duplicate->refresh();
        $this->assertSame(StudentProfile::LifecycleArchived, $duplicate->lifecycle_status);
        $this->assertSame($primary->id, $duplicate->merged_into_id);
        $this->assertNotNull($duplicate->archived_at);
        $this->assertSame(User::StatusArchived, $duplicate->user->status);
        $this->assertSame($duplicate->id, $history->fresh()->student_profile_id);
        $this->assertDatabaseHas('duplicate_profile_resolutions', [
            'id' => $resolution->id,
            'duplicate_student_profile_id' => $duplicate->id,
            'primary_student_profile_id' => $primary->id,
            'resolution_type' => 'LINKED_DUPLICATE',
            'resolved_by' => $registrar->id,
        ]);
    }

    public function test_not_duplicate_keep_separate_flow(): void
    {
        $duplicate = StudentProfile::factory()->create();
        $primary = StudentProfile::factory()->create();

        app(DuplicateProfileResolver::class)->resolve(
            $duplicate,
            $primary,
            'NOT_DUPLICATE',
            'Registrar confirmed these are different people.',
            $this->registrar(),
        );

        $this->assertNull($duplicate->fresh()->archived_at);
        $this->assertNull($duplicate->fresh()->merged_into_id);
        $this->assertSame(1, DuplicateProfileResolution::query()
            ->where('duplicate_student_profile_id', $duplicate->id)
            ->count());
    }

    public function test_global_scope_isolation(): void
    {
        $duplicate = StudentProfile::factory()->create();
        $primary = StudentProfile::factory()->create();
        app(DuplicateProfileResolver::class)->resolve(
            $duplicate,
            $primary,
            'LINKED_DUPLICATE',
            'Registrar confirmed a duplicate.',
            $this->registrar(),
        );

        $this->assertTrue(StudentProfile::query()->get()->contains('id', $duplicate->id));
        $this->assertFalse(StudentProfile::query()->active()->get()->contains('id', $duplicate->id));
        $this->assertTrue(StudentProfile::query()->active()->get()->contains('id', $primary->id));
    }

    public function test_student_hub_login_prevention(): void
    {
        $duplicate = StudentProfile::factory()->create();
        $duplicate->user->assignRole('student');
        app(DuplicateProfileResolver::class)->resolve(
            $duplicate,
            StudentProfile::factory()->create(),
            'LINKED_DUPLICATE',
            'Registrar confirmed a duplicate.',
            $this->registrar(),
        );

        $this->assertFalse($duplicate->user->fresh()->canAuthenticate());
    }

    public function test_primary_profile_cannot_be_already_merged(): void
    {
        $primary = StudentProfile::factory()->create([
            'archived_at' => now(),
            'lifecycle_status' => StudentProfile::LifecycleArchived,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('primary profile must be active and unmerged');
        app(DuplicateProfileResolver::class)->resolve(
            StudentProfile::factory()->create(),
            $primary,
            'LINKED_DUPLICATE',
            'Attempted invalid primary selection.',
            $this->registrar(),
        );
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
