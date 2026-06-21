<?php

namespace Tests\Feature;

use App\Actions\ServiceRequests\ServiceRequestLifecycleService;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\GeneralSystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ServiceRequestLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_records_resolution_note_as_lifecycle_evidence(): void
    {
        Notification::fake();

        $registrar = $this->registrar();
        $request = ServiceRequest::factory()->create([
            'status' => ServiceRequest::StatusUnderReview,
        ]);

        app(ServiceRequestLifecycleService::class)->resolve(
            $request,
            $registrar,
            'Drop consultation completed and recorded.',
        );

        $request->refresh();
        $properties = $this->activityProperties($request, 'service_request_resolved');

        $this->assertSame(ServiceRequest::StatusResolved, $request->status);
        $this->assertSame($registrar->id, $request->resolved_by);
        $this->assertSame(ServiceRequest::StatusResolved, $properties['status_after']);
        $this->assertSame('Drop consultation completed and recorded.', $properties['resolution_note']);

        Notification::assertSentTo(
            $request->studentProfile->user,
            GeneralSystemNotification::class,
            fn (GeneralSystemNotification $notification): bool => $notification->metadata['resolution_note']
                === 'Drop consultation completed and recorded.',
        );
    }

    public function test_reject_requires_and_records_rejection_reason(): void
    {
        Notification::fake();

        $registrar = $this->registrar();
        $request = ServiceRequest::factory()->create([
            'status' => ServiceRequest::StatusSubmitted,
        ]);

        app(ServiceRequestLifecycleService::class)->reject(
            $request,
            $registrar,
            'Missing signed consultation form.',
        );

        $request->refresh();
        $properties = $this->activityProperties($request, 'service_request_rejected');

        $this->assertSame(ServiceRequest::StatusRejected, $request->status);
        $this->assertSame(ServiceRequest::StatusRejected, $properties['status_after']);
        $this->assertSame('Missing signed consultation form.', $properties['rejection_reason']);

        Notification::assertSentTo(
            $request->studentProfile->user,
            GeneralSystemNotification::class,
            fn (GeneralSystemNotification $notification): bool => $notification->metadata['rejection_reason']
                === 'Missing signed consultation form.',
        );
    }

    public function test_reject_does_not_allow_blank_rejection_reason(): void
    {
        $registrar = $this->registrar();
        $request = ServiceRequest::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A rejection reason is required.');

        app(ServiceRequestLifecycleService::class)->reject($request, $registrar, '   ');
    }

    public function test_cancel_records_cancellation_reason_when_provided(): void
    {
        Notification::fake();

        $registrar = $this->registrar();
        $request = ServiceRequest::factory()->create([
            'status' => ServiceRequest::StatusUnderReview,
        ]);

        app(ServiceRequestLifecycleService::class)->cancel(
            $request,
            $registrar,
            'Duplicate service request submitted by the student.',
        );

        $request->refresh();
        $properties = $this->activityProperties($request, 'service_request_cancelled');

        $this->assertSame(ServiceRequest::StatusCancelled, $request->status);
        $this->assertSame(ServiceRequest::StatusCancelled, $properties['status_after']);
        $this->assertSame('Duplicate service request submitted by the student.', $properties['cancellation_reason']);

        Notification::assertSentTo(
            $request->studentProfile->user,
            GeneralSystemNotification::class,
            fn (GeneralSystemNotification $notification): bool => $notification->metadata['cancellation_reason']
                === 'Duplicate service request submitted by the student.',
        );
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-service-requests'));

        return $registrar;
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(ServiceRequest $request, string $event): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', ServiceRequest::class)
            ->where('subject_id', $request->id)
            ->where('event', $event)
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
