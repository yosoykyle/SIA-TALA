<?php

namespace Tests\Feature;

use App\Actions\Registrar\CorVerificationLifecycleService;
use App\Models\CorVerification;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CorVerificationLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_for_enrollment_creates_public_cor_token_from_ready_enrollment(): void
    {
        $registrar = $this->registrar();
        $enrollment = $this->readyEnrollment();
        $issuedAt = CarbonImmutable::parse('2026-06-22 09:00:00', config('app.timezone'));
        $expiresAt = CarbonImmutable::parse('2026-12-31 23:59:59', config('app.timezone'));

        $corVerification = app(CorVerificationLifecycleService::class)->issueForEnrollment(
            enrollment: $enrollment,
            registrar: $registrar,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );

        $this->assertSame($enrollment->student_profile_id, $corVerification->student_profile_id);
        $this->assertSame($enrollment->term_id, $corVerification->term_id);
        $this->assertSame($enrollment->id, $corVerification->enrollment_id);
        $this->assertSame(CorVerification::StatusValid, $corVerification->status);
        $this->assertSame(48, strlen($corVerification->token));
        $this->assertSame(CorVerification::StatusValid, $this->activityProperties($corVerification, 'cor_issued')['status_after']);
    }

    public function test_issue_for_enrollment_is_idempotent_while_valid_token_is_active(): void
    {
        $registrar = $this->registrar();
        $enrollment = $this->readyEnrollment();

        $first = app(CorVerificationLifecycleService::class)->issueForEnrollment($enrollment, $registrar);
        $second = app(CorVerificationLifecycleService::class)->issueForEnrollment($enrollment, $registrar);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CorVerification::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_issue_for_enrollment_rejects_not_ready_enrollment(): void
    {
        $registrar = $this->registrar();
        $enrollment = Enrollment::factory()->create(['status' => 'pending_payment']);

        try {
            app(CorVerificationLifecycleService::class)->issueForEnrollment($enrollment, $registrar);
            $this->fail('Expected COR issue to require enrollment readiness.');
        } catch (ValidationException $exception) {
            $this->assertSame(0, CorVerification::query()->count());
            $this->assertArrayHasKey('blockers', $exception->errors());
        }
    }

    public function test_public_verification_result_reports_lifecycle_states_without_internal_ids(): void
    {
        $registrar = $this->registrar();
        $valid = app(CorVerificationLifecycleService::class)->issueForEnrollment($this->readyEnrollment(), $registrar);
        $superseded = $this->corVerification();
        $revoked = $this->corVerification();
        $expired = $this->corVerification([
            'expires_at' => CarbonImmutable::parse('2026-06-21 08:00:00', config('app.timezone')),
        ]);

        app(CorVerificationLifecycleService::class)->supersede($superseded, $registrar);
        app(CorVerificationLifecycleService::class)->revoke($revoked, $registrar, 'Registrar correction.');

        $service = app(CorVerificationLifecycleService::class);
        $checkedAt = CarbonImmutable::parse('2026-06-22 08:00:00', config('app.timezone'));

        $this->assertSame(CorVerification::StatusValid, $service->verificationResult($valid->token, $checkedAt)['status']);
        $this->assertSame(CorVerification::StatusSuperseded, $service->verificationResult($superseded->token, $checkedAt)['status']);
        $this->assertSame(CorVerification::StatusRevoked, $service->verificationResult($revoked->token, $checkedAt)['status']);
        $this->assertSame(CorVerification::StatusExpired, $service->verificationResult($expired->token, $checkedAt)['status']);
        $this->assertSame(CorVerification::StatusNotFound, $service->verificationResult('missing-token', $checkedAt)['status']);
        $this->assertArrayNotHasKey('id', $service->verificationResult($valid->token, $checkedAt));
    }

    public function test_public_cor_verification_route_is_guest_accessible(): void
    {
        $this->withoutVite();

        $corVerification = app(CorVerificationLifecycleService::class)
            ->issueForEnrollment($this->readyEnrollment(), $this->registrar());

        $this->get(route('cor.verify', ['token' => $corVerification->token]))
            ->assertOk()
            ->assertSee('Verification Result')
            ->assertSee($corVerification->studentProfile->student_id)
            ->assertDontSee('student_profile_id')
            ->assertDontSee('enrollment_id');
    }

    public function test_supersede_marks_valid_cor_token_as_lifecycle_evidence(): void
    {
        $registrar = $this->registrar();
        $corVerification = $this->corVerification();

        app(CorVerificationLifecycleService::class)->supersede($corVerification, $registrar);

        $corVerification->refresh();
        $properties = $this->activityProperties($corVerification, 'cor_superseded');

        $this->assertSame(CorVerification::StatusSuperseded, $corVerification->status);
        $this->assertNull($corVerification->revoked_at);
        $this->assertNull($corVerification->revocation_reason);
        $this->assertSame(CorVerification::StatusSuperseded, $properties['status_after']);
    }

    public function test_revoke_requires_and_records_reason(): void
    {
        $registrar = $this->registrar();
        $corVerification = $this->corVerification();

        app(CorVerificationLifecycleService::class)->revoke(
            $corVerification,
            $registrar,
            'LIS encoding conflict invalidated this COR.',
        );

        $corVerification->refresh();
        $properties = $this->activityProperties($corVerification, 'cor_revoked');

        $this->assertSame(CorVerification::StatusRevoked, $corVerification->status);
        $this->assertNotNull($corVerification->revoked_at);
        $this->assertSame('LIS encoding conflict invalidated this COR.', $corVerification->revocation_reason);
        $this->assertSame(CorVerification::StatusRevoked, $properties['status_after']);
        $this->assertSame('LIS encoding conflict invalidated this COR.', $properties['reason']);
    }

    public function test_cor_lifecycle_requires_manage_cor_verifications_permission(): void
    {
        $actor = User::factory()->create();
        $corVerification = $this->corVerification();

        try {
            app(CorVerificationLifecycleService::class)->supersede($corVerification, $actor);
            $this->fail('Expected COR supersede to require manage-cor-verifications permission.');
        } catch (AuthorizationException) {
            $this->assertSame(CorVerification::StatusValid, $corVerification->refresh()->status);
        }
    }

    public function test_already_revoked_cor_cannot_be_revoked_again(): void
    {
        $registrar = $this->registrar();
        $corVerification = $this->corVerification([
            'status' => CorVerification::StatusRevoked,
            'revoked_at' => now(),
            'revocation_reason' => 'Prior revocation.',
        ]);

        $this->expectException(ValidationException::class);

        app(CorVerificationLifecycleService::class)->revoke(
            $corVerification,
            $registrar,
            'Second revocation.',
        );
    }

    public function test_cor_status_options_match_public_verification_contract(): void
    {
        $this->assertSame([
            CorVerification::StatusValid => 'Valid',
            CorVerification::StatusSuperseded => 'Superseded',
            CorVerification::StatusRevoked => 'Revoked',
            CorVerification::StatusExpired => 'Expired',
        ], CorVerification::statusOptions());
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-cor-verifications'));

        return $registrar;
    }

    private function readyEnrollment(): Enrollment
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('student', 'web');

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole('student');

        $studentProfile = StudentProfile::factory()
            ->for($user)
            ->create();
        $term = Term::factory()->create();
        $section = Section::factory()->create([
            'term_id' => $term->id,
            'program_id' => $studentProfile->program_id,
            'year_level' => $studentProfile->year_level,
        ]);
        $group = SectionDeliveryGroup::factory()
            ->for($section)
            ->create();

        return Enrollment::factory()
            ->for($studentProfile)
            ->for($term)
            ->create([
                'status' => 'pre_enrolled',
                'section_id' => $section->id,
                'section_delivery_group_id' => $group->id,
                'pre_enrolled_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function corVerification(array $attributes = []): CorVerification
    {
        $enrollment = Enrollment::factory()->create();

        return CorVerification::query()->create([
            'student_profile_id' => $enrollment->student_profile_id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'token' => Str::random(40),
            'status' => CorVerification::StatusValid,
            'issued_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(CorVerification $corVerification, string $event): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', CorVerification::class)
            ->where('subject_id', $corVerification->id)
            ->where('event', $event)
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
