<?php

namespace App\Actions\Scheduling;

use App\Mail\ScheduleRevisionMail;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\OperationalEvent;
use App\Models\Room;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ScheduleRevisionNotificationService
{
    /**
     * @param  EloquentCollection<int, ScheduleRevisionEvent>  $events
     */
    public function recordAndQueue(EloquentCollection $events): void
    {
        if ($events->isEmpty()) {
            return;
        }

        $events->load([
            'sectionMeeting.schedulingDemand.courseComponent.courseSpecification.course',
            'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
        ]);

        $eventIds = $events->modelKeys();
        sort($eventIds);
        $eventsByRecipient = $this->eventsByRecipient($events);
        $recipientKinds = $eventsByRecipient['kinds'];
        $recipients = User::query()
            ->whereKey(array_keys($eventsByRecipient['events']))
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->with('roles')
            ->orderBy('id')
            ->get()
            ->filter(function (User $user) use ($recipientKinds): bool {
                $kinds = $recipientKinds[(int) $user->id] ?? [];

                return (isset($kinds['student']) && $user->hasRole('student'))
                    || (isset($kinds['faculty']) && $user->hasRole(User::StaffRoleFaculty));
            });
        $roomLabels = $this->roomLabels($events);
        $facultyLabels = $this->facultyLabels($events);
        $eventSetHash = hash('sha256', implode(',', $eventIds));

        foreach ($recipients as $recipient) {
            $recipientEvents = new EloquentCollection(array_values($eventsByRecipient['events'][(int) $recipient->id]));
            $payload = $this->payload($recipientEvents, $roomLabels, $facultyLabels, $recipient);
            $externalId = "schedule-revision:{$eventSetHash}:user:{$recipient->id}";
            $deliveryEvent = OperationalEvent::query()->firstOrCreate(
                [
                    'event_domain' => OperationalEvent::DomainNotifications,
                    'external_id' => $externalId,
                ],
                [
                    'integration' => OperationalEvent::IntegrationMail,
                    'channel' => OperationalEvent::ChannelEmail,
                    'direction' => OperationalEvent::DirectionOutbound,
                    'event_type' => OperationalEvent::TypeScheduleRevisionEmail,
                    'event_version' => '1',
                    'user_id' => $recipient->id,
                    'recipient_snapshot' => [
                        'user_id' => (int) $recipient->id,
                        'email' => (string) $recipient->email,
                    ],
                    'status' => OperationalEvent::StatusPending,
                    'occurred_at' => now(),
                    'processed_at' => null,
                    'sent_at' => null,
                    'failed_at' => null,
                    'related_record_type' => ScheduleRevisionEvent::class,
                    'related_record_id' => $eventIds[0],
                    'diagnostics' => null,
                    'payload' => $payload,
                ],
            );

            if (! $deliveryEvent->wasRecentlyCreated) {
                continue;
            }

            $this->queue($deliveryEvent, $recipient);
        }
    }

    public function resend(OperationalEvent $event, User $actor): OperationalEvent
    {
        return DB::transaction(function () use ($event, $actor): OperationalEvent {
            $locked = OperationalEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->event_type !== OperationalEvent::TypeScheduleRevisionEmail
                || $locked->status !== OperationalEvent::StatusFailed) {
                throw ValidationException::withMessages([
                    'notification' => 'Only a failed timetable revision notification can be resent.',
                ]);
            }

            if ((int) $locked->user_id !== (int) $actor->id
                && ! $actor->hasRole(User::StaffRoleRegistrar)) {
                abort(403);
            }

            $recipient = User::query()->whereKey($locked->user_id)->firstOrFail();
            $locked->forceFill([
                'status' => OperationalEvent::StatusPending,
                'processed_at' => null,
                'failed_at' => null,
                'diagnostics' => null,
            ])->save();
            DB::afterCommit(fn () => $this->queue($locked, $recipient));

            return $locked->fresh();
        }, 3);
    }

    private function queue(OperationalEvent $event, User $recipient): void
    {
        try {
            Mail::to($recipient)->queue(new ScheduleRevisionMail(
                operationalEventId: (int) $event->id,
                recipientName: (string) $recipient->name,
                revisionPayload: is_array($event->payload) ? $event->payload : [],
            ));
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => OperationalEvent::StatusFailed,
                'processed_at' => now(),
                'sent_at' => null,
                'failed_at' => now(),
                'diagnostics' => [
                    'reason' => 'Mail could not be queued.',
                    'exception_type' => class_basename($exception),
                ],
            ])->save();
        }
    }

    /**
     * @param  EloquentCollection<int, ScheduleRevisionEvent>  $events
     * @return array{events:array<int, array<int, ScheduleRevisionEvent>>,kinds:array<int, array<string, true>>}
     */
    private function eventsByRecipient(EloquentCollection $events): array
    {
        $eventsByRecipient = [];
        $recipientKinds = [];
        $eventsBySection = $events
            ->groupBy(fn (ScheduleRevisionEvent $event): int => (int) $event->sectionMeeting?->schedulingDemand?->sectionDeliveryGroup?->section_id)
            ->filter(fn (EloquentCollection $events, int $sectionId): bool => $sectionId > 0);
        $officialRegistrations = CourseEnrollment::query()
            ->with('enrollment.credentialUser')
            ->whereIn('section_id', $eventsBySection->keys())
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->get();

        foreach ($officialRegistrations as $registration) {
            $userId = (int) $registration->enrollment?->credential_user_id;
            if ($userId <= 0) {
                continue;
            }

            foreach ($eventsBySection->get((int) $registration->section_id, new EloquentCollection) as $event) {
                $eventsByRecipient[$userId][(int) $event->id] = $event;
                $recipientKinds[$userId]['student'] = true;
            }
        }

        foreach ($events as $event) {
            foreach ([$event->old_snapshot_json['faculty_user_id'] ?? null, $event->new_snapshot_json['faculty_user_id'] ?? null] as $facultyId) {
                $userId = (int) $facultyId;

                if ($userId <= 0) {
                    continue;
                }

                $eventsByRecipient[$userId][(int) $event->id] = $event;
                $recipientKinds[$userId]['faculty'] = true;
            }
        }

        return [
            'events' => $eventsByRecipient,
            'kinds' => $recipientKinds,
        ];
    }

    /**
     * @param  EloquentCollection<int, ScheduleRevisionEvent>  $events
     * @return array<int, string>
     */
    private function roomLabels(EloquentCollection $events): array
    {
        $roomIds = $events
            ->flatMap(fn (ScheduleRevisionEvent $event): array => [
                $event->old_snapshot_json['room_id'] ?? null,
                $event->new_snapshot_json['room_id'] ?? null,
            ])
            ->filter()
            ->unique()
            ->values();

        return Room::query()
            ->whereKey($roomIds)
            ->get()
            ->mapWithKeys(fn (Room $room): array => [(int) $room->id => $room->displayLabel()])
            ->all();
    }

    /**
     * @param  EloquentCollection<int, ScheduleRevisionEvent>  $events
     * @return array<int, string>
     */
    private function facultyLabels(EloquentCollection $events): array
    {
        $facultyIds = $events
            ->flatMap(fn (ScheduleRevisionEvent $event): array => [
                $event->old_snapshot_json['faculty_user_id'] ?? null,
                $event->new_snapshot_json['faculty_user_id'] ?? null,
            ])
            ->filter()
            ->unique()
            ->values();

        return User::query()
            ->whereKey($facultyIds)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
    }

    /**
     * @param  EloquentCollection<int, ScheduleRevisionEvent>  $events
     * @param  array<int, string>  $roomLabels
     * @param  array<int, string>  $facultyLabels
     * @return array<string, mixed>
     */
    private function payload(EloquentCollection $events, array $roomLabels, array $facultyLabels, User $recipient): array
    {
        $events = $events->sortBy('id')->values();
        $sectionIds = $events
            ->map(fn (ScheduleRevisionEvent $event): int => (int) $event->sectionMeeting?->schedulingDemand?->sectionDeliveryGroup?->section_id)
            ->filter()
            ->unique()
            ->values();
        $enrollmentContexts = CourseEnrollment::query()
            ->with('enrollment.currentCorVersion')
            ->whereIn('section_id', $sectionIds)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereHas('enrollment', fn ($query) => $query->where('credential_user_id', $recipient->id))
            ->get()
            ->pluck('enrollment')
            ->filter()
            ->unique('id')
            ->map(fn ($enrollment): array => [
                'enrollment_id' => (int) $enrollment->id,
                'cor_version_id' => $enrollment->current_cor_version_id,
                'enrollment_url' => route('filament.student.pages.enrollment'),
                'cor_url' => $enrollment->current_cor_version_id !== null
                    ? route('cor.print', ['enrollment' => $enrollment->id])
                    : null,
            ])
            ->values()
            ->all();

        return [
            'revision_event_ids' => $events->modelKeys(),
            'change_types' => $events->pluck('change_type')->unique()->values()->all(),
            'effective_date' => $events->pluck('effective_date')->filter()->sort()->first()?->toDateString(),
            'affected_enrollments' => $enrollmentContexts,
            'changes' => $events->map(function (ScheduleRevisionEvent $event) use ($roomLabels, $facultyLabels): array {
                $meeting = $event->sectionMeeting;
                $demand = $meeting?->schedulingDemand;
                $courseComponent = $demand instanceof SchedulingDemand ? $demand->courseComponent : null;
                $courseSpecification = $courseComponent instanceof CourseComponent ? $courseComponent->courseSpecification : null;
                $course = $courseSpecification instanceof CourseSpecification ? $courseSpecification->course : null;
                $deliveryGroup = $demand instanceof SchedulingDemand ? $demand->sectionDeliveryGroup : null;
                $section = $deliveryGroup instanceof SectionDeliveryGroup ? $deliveryGroup->section : null;

                return [
                    'revision_event_id' => (int) $event->id,
                    'section_meeting_id' => (int) $event->section_meeting_id,
                    'meeting_sequence' => (int) ($event->new_snapshot_json['meeting_sequence'] ?? $event->old_snapshot_json['meeting_sequence'] ?? 0),
                    'change_type' => (string) $event->change_type,
                    'change_label' => ScheduleRevisionEvent::changeTypeOptions()[$event->change_type] ?? str((string) $event->change_type)->headline()->toString(),
                    'course' => $course instanceof Course ? $course->code : 'Scheduling demand #'.(int) ($event->new_snapshot_json['scheduling_demand_id'] ?? 0),
                    'section' => $section instanceof Section ? $section->code : 'Meeting #'.(int) $event->section_meeting_id,
                    'before' => $this->assignment($event->old_snapshot_json, $roomLabels, $facultyLabels),
                    'after' => $this->assignment($event->new_snapshot_json, $roomLabels, $facultyLabels),
                ];
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, string>  $roomLabels
     * @param  array<int, string>  $facultyLabels
     * @return array<string, string>
     */
    private function assignment(array $snapshot, array $roomLabels, array $facultyLabels): array
    {
        $facultyId = (int) ($snapshot['faculty_user_id'] ?? 0);
        $roomId = (int) ($snapshot['room_id'] ?? 0);
        $day = (int) ($snapshot['day_of_week'] ?? 0);

        return [
            'faculty' => $facultyLabels[$facultyId] ?? "Faculty #{$facultyId}",
            'room' => $roomId === 0 ? 'No physical room' : ($roomLabels[$roomId] ?? "Room #{$roomId}"),
            'day' => SectionMeeting::dayOptions()[$day] ?? "Day {$day}",
            'starts_at' => substr((string) ($snapshot['starts_at'] ?? ''), 0, 5),
            'ends_at' => substr((string) ($snapshot['ends_at'] ?? ''), 0, 5),
            'modality' => str((string) ($snapshot['modality'] ?? ''))->headline()->toString(),
            'state' => str((string) ($snapshot['state'] ?? ''))->headline()->toString(),
        ];
    }
}
