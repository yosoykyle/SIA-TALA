<?php

namespace Tests\Feature;

use App\Actions\Enrollment\StudentUnitLoadService;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentGateResult;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentUnitLoadExceptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function curriculum_load_precedes_term_fallback_and_exception_does_not_bypass_other_gates(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'default_max_units' => 21]);
        foreach ([6, 6, 6] as $sequence => $units) {
            $specification = CourseSpecification::factory()->create(['credit_units' => $units]);
            CurriculumEntry::factory()->create([
                'curriculum_version_id' => $profile->curriculum_version_id,
                'course_specification_id' => $specification->id,
                'year_level' => 'First Year',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => $sequence + 1,
            ]);
        }
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id, 'term_id' => $term->id]);
        $service = app(StudentUnitLoadService::class);
        $before = $service->evaluate($enrollment, 24, null, 'First Year');
        $this->assertSame('18.00', $before['normal_load']);
        $this->assertSame('24.00', $before['configured_cap']);
        $this->assertFalse($before['unit_load_passes']);

        $actor = User::factory()->create(['status' => User::StatusActive]);
        $actor->assignRole(User::StaffRoleAcademicHead);
        $exception = $service->approve($enrollment, [
            'normal_limit' => 18,
            'requested_total' => 24,
            'configured_cap' => (float) $before['configured_cap'],
            'authority' => 'Academic Head Resolution 2026-01',
            'reason' => 'Approved scoped overload after academic review.',
            'evidence_reference' => 'UL-001',
            'affected_term_offering_ids' => [1, 2],
            'expires_at' => now()->addMonth(),
        ], $actor);
        $this->assertSame(EnrollmentException::TypeUnitLoad, $exception->exception_type);
        $this->assertSame('6', (string) data_get($exception->approved_values, 'approved_excess'));
        $this->assertSame(User::StaffRoleAcademicHead, data_get($exception->approved_values, 'recorded_by_role'));
        $this->assertTrue($service->evaluate($enrollment, 24, null, 'First Year')['unit_load_passes']);

        EnrollmentGateResult::query()->create([
            'enrollment_id' => $enrollment->id,
            'gate_type' => 'FINANCE',
            'sequence' => 1,
            'result' => 'FAILED',
            'responsible_office' => 'Accounting',
            'checked_at' => now(),
            'rule_version' => 'v1',
        ]);
        $after = $service->evaluate($enrollment, 24, null, 'First Year');
        $this->assertFalse($after['all_gates_pass']);
        $this->assertSame(['FINANCE'], $after['other_failed_gates']);

        $exception->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($service->evaluate($enrollment, 24, null, 'First Year')['unit_load_passes']);
    }

    #[Test]
    public function configured_student_unit_load_policy_is_used_when_curriculum_has_no_matching_target_load(): void
    {
        DB::table('system_settings')->insert([
            'key' => 'student_unit_load_policy_defaults',
            'scope_type' => 'institution',
            'scope_id' => 0,
            'value_type' => 'json',
            'value' => '{"version":"1.0","fallback_normal_max_units":15,"regular_overload_excess_cap":6,"summer_overload_excess_cap":8,"default_approving_authority":"Academic Head","default_recording_office":"Registrar"}',
            'effective_from' => now()->subDay(),
            'version' => 1,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create(['type' => Term::TypeSummer, 'default_max_units' => 99]);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id, 'term_id' => $term->id]);

        $result = app(StudentUnitLoadService::class)->evaluate($enrollment, 23, null, 'First Year');
        $this->assertSame('15.00', $result['normal_load']);
        $this->assertSame('23.00', $result['configured_cap']);
        $this->assertTrue($result['requires_exception']);
        $this->assertFalse($result['unit_load_passes']);
    }
}
