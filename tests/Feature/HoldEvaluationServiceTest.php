<?php

namespace Tests\Feature;

use App\Actions\StudentLifecycle\CreateHold;
use App\Actions\StudentLifecycle\ExpireHold;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Actions\StudentLifecycle\ResolveHold;
use App\Actions\StudentLifecycle\WaiveHold;
use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class HoldEvaluationServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleAcademicHead, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function exact_accommodation_effects_bypass_only_the_named_financial_workflow(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $profile->id, 'term_id' => $term->id]);
        foreach ([Hold::BlockingEnrollment, Hold::BlockingReactivation, Hold::BlockingRecordRelease] as $blockingLevel) {
            Hold::factory()->create([
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'hold_type' => Hold::TypeFinancial,
                'blocking_level' => $blockingLevel,
            ]);
        }
        FinancialAccommodation::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'balance_snapshot' => 1000,
            'covered_amount' => 1000,
            'basis' => 'Approved hardship accommodation',
            'allows_finance_gate' => true,
            'allows_next_term_enrollment' => false,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => false,
            'authority' => 'Accounting Director',
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => today(),
            'expires_on' => today()->addMonth(),
        ]);

        $service = app(HoldEvaluationService::class);
        $this->assertFalse($service->hasActiveBlockingHold($profile, [Hold::BlockingEnrollment], $enrollment));
        $this->assertTrue($service->hasActiveBlockingHold($profile, [Hold::BlockingReactivation], $enrollment));
        $this->assertTrue($service->hasActiveBlockingHold($profile, [Hold::BlockingRecordRelease], $enrollment));
        $this->assertSame(3, Hold::query()->where('student_profile_id', $profile->id)->count());
    }

    #[Test]
    public function source_owned_create_resolve_waive_expire_and_most_restrictive_evaluation_are_auditable(): void
    {
        $profile = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $financial = app(CreateHold::class)->execute($profile, [
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'reason' => 'Outstanding balance evidence.',
            'resolution_requirement' => 'Clear the verified balance.',
        ], $accounting);
        $reactivation = app(CreateHold::class)->execute($profile, [
            'hold_type' => Hold::TypeReactivation,
            'blocking_level' => Hold::BlockingReactivation,
            'reason' => 'Archived record requires review.',
            'resolution_requirement' => 'Complete Registrar review.',
        ], $registrar);
        $this->assertSame($reactivation->id, app(HoldEvaluationService::class)->mostRestrictiveActiveHold($profile, [Hold::BlockingEnrollment, Hold::BlockingReactivation])?->id);

        app(ResolveHold::class)->execute($financial, $accounting, 'Ledger balance verified as cleared.');
        $this->assertSame(Hold::StatusResolved, $financial->fresh()->status);
        $this->assertSame($accounting->id, $financial->fresh()->resolved_by);

        app(WaiveHold::class)->execute($reactivation, $registrar, 'Registrar Director', 'Approved exception for reactivation.');
        $this->assertSame(Hold::StatusWaived, $reactivation->fresh()->status);
        $this->assertSame($registrar->id, $reactivation->fresh()->waived_by);

        $expiring = Hold::factory()->create(['student_profile_id' => $profile->id, 'expires_at' => now()->subSecond()]);
        app(ExpireHold::class)->execute($expiring);
        $this->assertSame(Hold::StatusExpired, $expiring->fresh()->status);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
