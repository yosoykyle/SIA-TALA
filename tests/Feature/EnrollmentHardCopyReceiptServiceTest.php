<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentHardCopyReceiptService;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EnrollmentHardCopyReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_can_confirm_hard_copy_receipt_once(): void
    {
        $registrar = $this->userWithPermission('approve-documents');
        $enrollment = Enrollment::factory()->create();
        $receivedAt = CarbonImmutable::parse('2026-06-06 09:30:00', config('app.timezone'));

        $updated = app(EnrollmentHardCopyReceiptService::class)->markReceived($enrollment, $registrar, $receivedAt);

        $studentProfile = $enrollment->studentProfile->refresh();
        $properties = $this->activityProperties($enrollment);

        $this->assertTrue($studentProfile->hard_copy_received);
        $this->assertSame($receivedAt->toDateTimeString(), $studentProfile->last_status_changed_at?->toDateTimeString());
        $this->assertTrue($updated->studentProfile->hard_copy_received);
        $this->assertSame($studentProfile->id, $properties['student_profile_id']);
        $this->assertTrue($properties['hard_copy_received']);
    }

    public function test_transferee_evaluator_can_confirm_hard_copy_receipt(): void
    {
        $evaluator = $this->userWithPermission('evaluate-transferees');
        $enrollment = Enrollment::factory()->create();

        app(EnrollmentHardCopyReceiptService::class)->markReceived($enrollment, $evaluator);

        $this->assertTrue($enrollment->studentProfile->refresh()->hard_copy_received);
    }

    public function test_hard_copy_receipt_requires_matching_permission(): void
    {
        $actor = User::factory()->create();
        $enrollment = Enrollment::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(EnrollmentHardCopyReceiptService::class)->markReceived($enrollment, $actor);
    }

    public function test_hard_copy_receipt_cannot_be_confirmed_twice(): void
    {
        $registrar = $this->userWithPermission('approve-documents');
        $enrollment = Enrollment::factory()->create();

        app(EnrollmentHardCopyReceiptService::class)->markReceived($enrollment, $registrar);

        $this->expectException(ValidationException::class);

        try {
            app(EnrollmentHardCopyReceiptService::class)->markReceived($enrollment, $registrar);
        } finally {
            $this->assertSame(1, $this->activityCount($enrollment));
        }
    }

    private function userWithPermission(string $permission): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate($permission));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(Enrollment $enrollment): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', Enrollment::class)
            ->where('subject_id', $enrollment->id)
            ->where('event', 'hard_copy_received')
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }

    private function activityCount(Enrollment $enrollment): int
    {
        return DB::table('activity_log')
            ->where('subject_type', Enrollment::class)
            ->where('subject_id', $enrollment->id)
            ->where('event', 'hard_copy_received')
            ->count();
    }
}
