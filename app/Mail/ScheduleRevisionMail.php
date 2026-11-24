<?php

namespace App\Mail;

use App\Models\OperationalEvent;
use App\Models\ScheduleRevisionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScheduleRevisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public string $operationalEventType = OperationalEvent::TypeScheduleRevisionEmail;

    /** @var list<array<string, mixed>> */
    public array $scheduleChanges;

    /** @var list<array<string, mixed>> */
    public array $affectedEnrollments;

    public string $recipientRole;

    public string $termLabel;

    public ?string $effectiveDate;

    public ?string $reason;

    public string $scheduleUrl;

    public bool $isCancellation;

    /**
     * @param  array<string, mixed>  $revisionPayload
     */
    public function __construct(
        public int $operationalEventId,
        public string $recipientName,
        array $revisionPayload,
    ) {
        $rawChanges = is_array($revisionPayload['changes'] ?? null) ? $revisionPayload['changes'] : [];
        $this->scheduleChanges = array_map(
            fn (array $change): array => $this->normalizeChange($change),
            $rawChanges,
        );
        $this->affectedEnrollments = is_array($revisionPayload['affected_enrollments'] ?? null)
            ? $revisionPayload['affected_enrollments']
            : [];
        $this->recipientRole = ($revisionPayload['recipient_role'] ?? null) === 'faculty'
            ? 'faculty'
            : ($this->affectedEnrollments !== [] ? 'student' : (string) ($revisionPayload['recipient_role'] ?? 'student'));
        $this->termLabel = trim((string) ($revisionPayload['term_label'] ?? ''));
        $this->effectiveDate = $this->formatDate($revisionPayload['effective_date'] ?? null);
        $reason = trim((string) ($revisionPayload['reason'] ?? ''));
        $this->reason = $reason !== '' ? $reason : null;
        $termId = is_numeric($revisionPayload['term_id'] ?? null) ? (int) $revisionPayload['term_id'] : null;
        $defaultUrl = $this->recipientRole === 'faculty'
            ? route('filament.admin.pages.faculty-schedule', $termId !== null ? ['termId' => $termId] : [])
            : route('filament.student.pages.schedule-view');
        $this->scheduleUrl = filled($revisionPayload['schedule_url'] ?? null)
            ? (string) $revisionPayload['schedule_url']
            : $defaultUrl;
        $this->isCancellation = $this->scheduleChanges !== []
            && collect($this->scheduleChanges)->every(fn (array $change): bool => (bool) ($change['is_cancelled'] ?? false));
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');

        if ($this->isCancellation && $this->scheduleChanges !== []) {
            $classLabels = collect($this->scheduleChanges)
                ->map(fn (array $change): string => trim((string) ($change['course'] ?? '').' '.(string) ($change['section'] ?? '')))
                ->filter()
                ->unique()
                ->values();

            $subject = $classLabels->count() === 1
                ? "{$institution} — Class cancelled — {$classLabels->first()}"
                : ($this->termLabel !== ''
                    ? "{$institution} — Classes cancelled — {$this->termLabel}"
                    : "{$institution} — Published class cancelled");

            return new Envelope(subject: $subject);
        }

        $subject = $this->termLabel !== ''
            ? "{$institution} — Class schedule updated — {$this->termLabel}"
            : "{$institution} — Your published class schedule was updated";

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.schedule-revision',
        );
    }

    public function failed(Throwable $exception): void
    {
        $deliveryEvent = OperationalEvent::query()
            ->whereKey($this->operationalEventId)
            ->where('event_type', $this->operationalEventType)
            ->where('status', OperationalEvent::StatusPending)
            ->first();

        if (! $deliveryEvent instanceof OperationalEvent) {
            return;
        }

        $timestamp = now();
        $deliveryEvent->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'processed_at' => $timestamp,
            'sent_at' => null,
            'failed_at' => $timestamp,
            'diagnostics' => [
                'reason' => 'Mail delivery failed.',
                'exception_type' => class_basename($exception),
            ],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function normalizeChange(array $change): array
    {
        $before = is_array($change['before'] ?? null) ? $change['before'] : [];
        $after = is_array($change['after'] ?? null) ? $change['after'] : [];
        $isCancelled = (bool) ($change['is_cancelled'] ?? false)
            || ($change['change_type'] ?? null) === ScheduleRevisionEvent::ChangeSectionCancellation
            || strcasecmp((string) ($after['state'] ?? ''), 'Cancelled') === 0;

        $before['time_range'] = $this->formatTimeRange($before['starts_at'] ?? null, $before['ends_at'] ?? null);
        $after['time_range'] = $this->formatTimeRange($after['starts_at'] ?? null, $after['ends_at'] ?? null);
        $before['state'] = filled($before['state'] ?? null) ? (string) $before['state'] : 'Active';
        $after['state'] = $isCancelled ? 'Cancelled' : (filled($after['state'] ?? null) ? (string) $after['state'] : 'Active');

        return [
            ...$change,
            'is_cancelled' => $isCancelled,
            'before' => $before,
            'after' => $after,
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('M j, Y');
        } catch (Throwable) {
            return trim($value);
        }
    }

    private function formatTimeRange(mixed $startsAt, mixed $endsAt): string
    {
        $start = $this->formatClockTime($startsAt);
        $end = $this->formatClockTime($endsAt);

        if ($start === '' && $end === '') {
            return 'Not scheduled';
        }

        if ($end === '') {
            return $start;
        }

        return "{$start}–{$end}";
    }

    private function formatClockTime(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        $trimmed = trim($value);

        foreach (['g:i A', 'g:i a', 'H:i:s', 'H:i'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $trimmed)->format('g:i A');
            } catch (Throwable) {
                continue;
            }
        }

        return $trimmed;
    }
}
