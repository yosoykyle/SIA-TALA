<?php

namespace Tests\Feature;

use App\Actions\Registrar\CorVerificationLifecycleService;
use App\Models\CorVerification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CorVerificationLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_cor_lifecycle_requires_manage_lis_permission(): void
    {
        $actor = User::factory()->create();
        $corVerification = $this->corVerification();

        try {
            app(CorVerificationLifecycleService::class)->supersede($corVerification, $actor);
            $this->fail('Expected COR supersede to require manage-lis permission.');
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
        ], CorVerification::statusOptions());
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-lis'));

        return $registrar;
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
