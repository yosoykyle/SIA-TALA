<?php

namespace App\Actions\ServiceRequests;

use App\Models\ServiceRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\GeneralSystemNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ServiceRequestLifecycleService
{
    /**
     * @param  array{student_profile_id:int,term_id?:int|null,category:string,sub_type?:string|null,details?:string|null,attachment_paths?:array<int, string>}  $data
     */
    public function submit(array $data, User $actor): ServiceRequest
    {
        $studentProfile = StudentProfile::query()->findOrFail((int) $data['student_profile_id']);

        if (! $actor->can('submit-service-requests') || (int) $studentProfile->user_id !== (int) $actor->id) {
            throw new AuthorizationException('Only the student owner can submit service requests.');
        }

        return DB::transaction(function () use ($data, $studentProfile, $actor): ServiceRequest {
            $request = ServiceRequest::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $data['term_id'] ?? null,
                'category' => trim((string) $data['category']),
                'sub_type' => isset($data['sub_type']) ? trim((string) $data['sub_type']) : null,
                'status' => ServiceRequest::StatusSubmitted,
                'details' => isset($data['details']) ? trim((string) $data['details']) : null,
                'attachment_paths' => $this->normalizeAttachmentPaths($data['attachment_paths'] ?? []),
            ]);

            $this->recordActivity($request, 'service_request_submitted', $actor, [
                'status_after' => ServiceRequest::StatusSubmitted,
            ]);

            $this->notifyStudent($request, new GeneralSystemNotification(
                type: 'service_request_submitted',
                subject: 'Service request submitted',
                body: 'Your service request was submitted and is waiting for staff review.',
                metadata: $this->notificationMetadata($request),
            ));

            return $request->fresh();
        });
    }

    public function startReview(ServiceRequest $request, User $registrar): ServiceRequest
    {
        $this->authorizeRegistrar($registrar);

        return DB::transaction(function () use ($request, $registrar): ServiceRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [ServiceRequest::StatusSubmitted]);

            $locked->forceFill([
                'status' => ServiceRequest::StatusUnderReview,
                'assigned_to' => $registrar->id,
            ])->save();

            $this->recordActivity($locked, 'service_request_under_review', $registrar, [
                'status_after' => ServiceRequest::StatusUnderReview,
            ]);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'service_request_under_review',
                subject: 'Service request under review',
                body: 'Registrar staff started reviewing your service request.',
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function resolve(ServiceRequest $request, User $registrar, ?string $resolutionNote = null): ServiceRequest
    {
        $this->authorizeRegistrar($registrar);

        return $this->finish($request, $registrar, ServiceRequest::StatusResolved, $resolutionNote);
    }

    public function reject(ServiceRequest $request, User $registrar, string $rejectionReason): ServiceRequest
    {
        $this->authorizeRegistrar($registrar);

        return $this->finish($request, $registrar, ServiceRequest::StatusRejected, $rejectionReason);
    }

    public function cancel(ServiceRequest $request, User $actor, ?string $cancellationReason = null): ServiceRequest
    {
        if (! $this->actorOwnsRequest($request, $actor) && ! $actor->can('manage-service-requests')) {
            throw new AuthorizationException('Only the requesting student or Registrar can cancel this service request.');
        }

        return DB::transaction(function () use ($request, $actor, $cancellationReason): ServiceRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [
                ServiceRequest::StatusSubmitted,
                ServiceRequest::StatusUnderReview,
            ]);
            $reason = $this->normalizedNote($cancellationReason);

            $locked->forceFill([
                'status' => ServiceRequest::StatusCancelled,
            ])->save();

            $properties = [
                'status_after' => ServiceRequest::StatusCancelled,
            ];

            if ($reason !== null) {
                $properties['cancellation_reason'] = $reason;
            }

            $this->recordActivity($locked, 'service_request_cancelled', $actor, $properties);

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'service_request_cancelled',
                subject: 'Service request cancelled',
                body: $reason === null
                    ? 'Your service request has been cancelled.'
                    : "Your service request has been cancelled. Reason: {$reason}",
                metadata: $this->notificationMetadata(
                    $locked,
                    $reason === null ? [] : ['cancellation_reason' => $reason],
                ),
            ));

            return $locked->fresh();
        });
    }

    private function finish(
        ServiceRequest $request,
        User $registrar,
        string $status,
        ?string $note = null,
    ): ServiceRequest {
        $noteKey = $status === ServiceRequest::StatusResolved ? 'resolution_note' : 'rejection_reason';
        $normalizedNote = $status === ServiceRequest::StatusRejected
            ? $this->requiredNote($note, 'A rejection reason is required.')
            : $this->normalizedNote($note);

        return DB::transaction(function () use ($request, $registrar, $status, $noteKey, $normalizedNote): ServiceRequest {
            $locked = $this->lockRequest($request);
            $this->assertStatus($locked, [
                ServiceRequest::StatusSubmitted,
                ServiceRequest::StatusUnderReview,
            ]);

            $locked->forceFill([
                'status' => $status,
                'assigned_to' => $locked->assigned_to ?? $registrar->id,
                'resolved_by' => $registrar->id,
                'resolved_at' => CarbonImmutable::now(config('app.timezone')),
            ])->save();

            $event = $status === ServiceRequest::StatusResolved
                ? 'service_request_resolved'
                : 'service_request_rejected';

            $properties = [
                'status_after' => $status,
            ];

            if ($normalizedNote !== null) {
                $properties[$noteKey] = $normalizedNote;
            }

            $this->recordActivity($locked, $event, $registrar, $properties);

            $isResolved = $status === ServiceRequest::StatusResolved;
            $body = match (true) {
                $isResolved && $normalizedNote !== null => "Your service request has been resolved. Note: {$normalizedNote}",
                $isResolved => 'Your service request has been resolved.',
                $normalizedNote !== null => "Your service request has been rejected. Reason: {$normalizedNote}",
                default => 'Your service request has been rejected. Please check with Registrar for details.',
            };

            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: $event,
                subject: $isResolved
                    ? 'Service request resolved'
                    : 'Service request rejected',
                body: $body,
                metadata: $this->notificationMetadata(
                    $locked,
                    $normalizedNote === null ? [] : [$noteKey => $normalizedNote],
                ),
            ));

            return $locked->fresh();
        });
    }

    private function authorizeRegistrar(User $registrar): void
    {
        if (! $registrar->can('manage-service-requests')) {
            throw new AuthorizationException('Only Registrar can manage service requests.');
        }
    }

    private function lockRequest(ServiceRequest $request): ServiceRequest
    {
        return ServiceRequest::query()
            ->lockForUpdate()
            ->findOrFail($request->id);
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function assertStatus(ServiceRequest $request, array $allowedStatuses): void
    {
        if (! in_array($request->status, $allowedStatuses, true)) {
            throw new RuntimeException(sprintf(
                'Invalid service request transition from [%s].',
                $request->status,
            ));
        }
    }

    /**
     * @param  array<int, string>  $attachmentPaths
     * @return list<string>
     */
    private function normalizeAttachmentPaths(array $attachmentPaths): array
    {
        return array_values(array_filter(array_map(
            fn (string $path): string => trim($path),
            $attachmentPaths,
        )));
    }

    private function actorOwnsRequest(ServiceRequest $request, User $actor): bool
    {
        $studentProfileUserId = StudentProfile::query()
            ->whereKey($request->student_profile_id)
            ->value('user_id');

        return (int) $studentProfileUserId === (int) $actor->id;
    }

    private function normalizedNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    private function requiredNote(?string $note, string $message): string
    {
        $note = $this->normalizedNote($note);

        if ($note === null) {
            throw new RuntimeException($message);
        }

        return $note;
    }

    private function notifyStudent(ServiceRequest $request, GeneralSystemNotification $notification): void
    {
        $student = StudentProfile::query()
            ->with('user')
            ->find($request->student_profile_id)
            ?->user;

        if ($student instanceof User) {
            $student->notify($notification);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function notificationMetadata(ServiceRequest $request, array $extra = []): array
    {
        return array_merge([
            'service_request_id' => $request->id,
            'category' => $request->category,
            'sub_type' => $request->sub_type,
            'status' => $request->status,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        ServiceRequest $request,
        string $event,
        ?User $actor,
        array $properties = [],
    ): void {
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        DB::table('activity_log')->insert([
            'log_name' => 'service_request',
            'description' => 'Service request lifecycle transition.',
            'subject_type' => ServiceRequest::class,
            'subject_id' => $request->id,
            'event' => $event,
            'causer_type' => $actor instanceof User ? User::class : null,
            'causer_id' => $actor?->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
