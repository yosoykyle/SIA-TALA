<?php

namespace App\Actions\Scheduling;

use App\Mail\ScheduleReleasedMail;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class ScheduleReleaseNotificationService
{
    public function recordPublishedRun(ScheduleGenerationRun $run): void
    {
        if ($run->status !== ScheduleGenerationRun::StatusPublished) {
            return;
        }

        $facultyIds = SectionMeeting::query()
            ->where('schedule_run_id', $run->id)
            ->where('state', SectionMeeting::StateActive)
            ->whereNotNull('faculty_user_id')
            ->distinct()
            ->pluck('faculty_user_id');

        $recipients = User::query()
            ->whereKey($facultyIds)
            ->with('roles')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->hasRole(User::StaffRoleFaculty));

        $run->loadMissing('term');
        $term = $run->term;

        if (! $term instanceof Term) {
            return;
        }

        foreach ($recipients as $recipient) {
            $this->recordRecipient(
                recipient: $recipient,
                externalId: "schedule-release:published-run:{$run->id}:user:{$recipient->id}",
                relatedRecordType: ScheduleGenerationRun::class,
                relatedRecordId: (int) $run->id,
                trigger: 'published_run',
                termId: (int) $run->term_id,
                termLabel: (string) $term->label,
                scheduleUrl: route('filament.admin.pages.faculty-schedule'),
            );
        }
    }

    public function recordOfficialEnrollment(Enrollment $enrollment): void
    {
        $enrollment->loadMissing(['studentProfile.user.roles', 'term']);
        $recipient = $enrollment->studentProfile?->user;

        if ($enrollment->status !== 'officially_enrolled'
            || ! $recipient instanceof User
            || ! $recipient->hasRole('student')
            || ! StudentScheduleBinding::query()
                ->activeOfficial()
                ->forEnrollment($enrollment)
                ->exists()) {
            return;
        }

        $this->recordRecipient(
            recipient: $recipient,
            externalId: "schedule-release:official-enrollment:{$enrollment->id}:user:{$recipient->id}",
            relatedRecordType: Enrollment::class,
            relatedRecordId: (int) $enrollment->id,
            trigger: 'official_enrollment',
            termId: (int) $enrollment->term_id,
            termLabel: (string) $enrollment->term?->label,
            scheduleUrl: route('filament.student.pages.schedule-view'),
        );
    }

    /**
     * @param  class-string  $relatedRecordType
     */
    private function recordRecipient(
        User $recipient,
        string $externalId,
        string $relatedRecordType,
        int $relatedRecordId,
        string $trigger,
        int $termId,
        string $termLabel,
        string $scheduleUrl,
    ): void {
        $deliveryEvent = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => $externalId,
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => OperationalEvent::TypeScheduleReleasedEmail,
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
                'related_record_type' => $relatedRecordType,
                'related_record_id' => $relatedRecordId,
                'diagnostics' => null,
                'payload' => [
                    'trigger' => $trigger,
                    'term_id' => $termId,
                    'term_label' => $termLabel,
                ],
            ],
        );

        if (! $deliveryEvent->wasRecentlyCreated) {
            return;
        }

        $email = trim((string) $recipient->email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->markFailed($deliveryEvent, 'Recipient email is missing or invalid.');

            return;
        }

        try {
            Mail::to($recipient)->queue(new ScheduleReleasedMail(
                operationalEventId: (int) $deliveryEvent->id,
                recipientName: (string) $recipient->name,
                termLabel: $termLabel,
                scheduleUrl: $scheduleUrl,
            ));
        } catch (Throwable $exception) {
            $this->markFailed($deliveryEvent, 'Mail could not be queued.', $exception);
        }
    }

    private function markFailed(
        OperationalEvent $deliveryEvent,
        string $reason,
        ?Throwable $exception = null,
    ): void {
        $timestamp = now();
        $diagnostics = ['reason' => $reason];

        if ($exception instanceof Throwable) {
            $diagnostics['exception_type'] = class_basename($exception);
        }

        $deliveryEvent->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'processed_at' => $timestamp,
            'sent_at' => null,
            'failed_at' => $timestamp,
            'diagnostics' => $diagnostics,
        ])->save();
    }
}
