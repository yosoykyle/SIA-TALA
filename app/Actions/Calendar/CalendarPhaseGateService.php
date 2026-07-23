<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\CalendarEvent;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CalendarPhaseGateService
{
    public function assertEnrollmentWindowOpen(int $termId, ?CarbonImmutable $at = null): void
    {
        $this->enrollmentWindow($termId, $at);
    }

    public function enrollmentWindow(int $termId, ?CarbonImmutable $at = null): CalendarEvent
    {
        return $this->resolveWindow(
            termId: $termId,
            processKey: CalendarEvent::ProcessEnrollment,
            gate: 'enrollment_window',
            missingMessage: 'Enrollment gate is not configured for this term.',
            closedMessage: 'Enrollment is outside the configured window.',
            at: $at,
        );
    }

    public function enrollmentDeadline(int $termId, ?CarbonImmutable $at = null): CarbonImmutable
    {
        $window = $this->enrollmentWindow($termId, $at);

        if (! $window->end_at instanceof \DateTimeInterface) {
            throw new CalendarGateViolation(
                'Enrollment window has no valid reservation deadline.',
                'enrollment_window',
                [
                    'term_id' => $termId,
                    'window_configured' => true,
                    'calendar_event_id' => $window->id,
                ],
            );
        }

        return CarbonImmutable::instance($window->end_at);
    }

    public function assertSchedulingWindowOpen(int $termId, ?CarbonImmutable $at = null): void
    {
        $this->resolveWindow(
            termId: $termId,
            processKey: CalendarEvent::ProcessScheduling,
            gate: 'scheduling_window',
            missingMessage: 'Scheduling gate is not configured for this term.',
            closedMessage: 'Scheduling is outside the configured window.',
            at: $at,
        );
    }

    public function assertEnrollmentEditWindowOpen(int $termId, ?CarbonImmutable $at = null): void
    {
        $evaluatedAt = $at ?? CarbonImmutable::now();

        try {
            $this->resolveWindow(
                termId: $termId,
                processKey: CalendarEvent::ProcessEnrollment,
                gate: 'enrollment_edit_window',
                missingMessage: 'Enrollment edit window is not configured for this term.',
                closedMessage: 'Enrollment edits are locked outside the enrollment window.',
                at: $evaluatedAt,
            );
        } catch (CalendarGateViolation $exception) {
            $this->recordEnrollmentEditGateBlock(
                $termId,
                $evaluatedAt,
                ($exception->context['window_configured'] ?? false) === true
                    ? 'outside_enrollment_window'
                    : 'missing_enrollment_window',
            );

            throw $exception;
        }
    }

    /**
     * @deprecated Canonical CalendarEvent windows are authoritative for every term.
     */
    public function isCutoverActive(int $termId, ?CarbonImmutable $at = null): bool
    {
        $evaluatedAt = $at ?? CarbonImmutable::now();

        return $this->windowQuery($termId, CalendarEvent::ProcessEnrollment)
            ->where('start_at', '<=', $evaluatedAt)
            ->where('end_at', '>=', $evaluatedAt)
            ->exists();
    }

    private function resolveWindow(
        int $termId,
        string $processKey,
        string $gate,
        string $missingMessage,
        string $closedMessage,
        ?CarbonImmutable $at,
    ): CalendarEvent {
        $evaluatedAt = $at ?? CarbonImmutable::now();
        $this->assertTermExists($termId);
        $windows = $this->windowQuery($termId, $processKey)
            ->orderBy('start_at')
            ->get();

        if ($windows->isEmpty()) {
            throw new CalendarGateViolation(
                $missingMessage,
                $gate,
                [
                    'term_id' => $termId,
                    'process_key' => $processKey,
                    'window_configured' => false,
                    'evaluated_at' => $evaluatedAt->toIso8601String(),
                ],
            );
        }

        $window = $windows->first(function (CalendarEvent $event) use ($evaluatedAt): bool {
            if (! $event->start_at instanceof \DateTimeInterface
                || ! $event->end_at instanceof \DateTimeInterface) {
                return false;
            }

            $startsAt = CarbonImmutable::instance($event->start_at);
            $endsAt = CarbonImmutable::instance($event->end_at);

            return $evaluatedAt->betweenIncluded($startsAt, $endsAt);
        });

        if (! $window instanceof CalendarEvent) {
            throw new CalendarGateViolation(
                $closedMessage,
                $gate,
                [
                    'term_id' => $termId,
                    'process_key' => $processKey,
                    'window_configured' => true,
                    'evaluated_at' => $evaluatedAt->toIso8601String(),
                    'configured_windows' => $windows->map(fn (CalendarEvent $event): array => [
                        'start_at' => $event->start_at?->toIso8601String(),
                        'end_at' => $event->end_at?->toIso8601String(),
                    ])->all(),
                ],
            );
        }

        return $window;
    }

    /**
     * @return Builder<CalendarEvent>
     */
    private function windowQuery(int $termId, string $processKey): Builder
    {
        return CalendarEvent::query()
            ->academicCalendarWindows()
            ->where('term_id', $termId)
            ->where('process_key', $processKey)
            ->where('state', CalendarEvent::StateActive);
    }

    private function assertTermExists(int $termId): void
    {
        if (Term::query()->whereKey($termId)->exists()) {
            return;
        }

        throw new CalendarGateViolation(
            'Term not found for gate validation.',
            'term_resolution',
            ['term_id' => $termId],
        );
    }

    private function recordEnrollmentEditGateBlock(
        int $termId,
        CarbonImmutable $evaluatedAt,
        string $reason,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'calendar_gate',
            'description' => 'Enrollment edit blocked by calendar phase gate.',
            'event' => 'enrollment_edit_blocked',
            'subject_type' => 'term',
            'subject_id' => $termId,
            'causer_type' => Auth::id() !== null ? 'App\\Models\\User' : null,
            'causer_id' => Auth::id(),
            'properties' => json_encode([
                'reason' => $reason,
                'evaluated_at' => $evaluatedAt->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $evaluatedAt->toDateTimeString(),
            'updated_at' => $evaluatedAt->toDateTimeString(),
        ]);
    }
}
