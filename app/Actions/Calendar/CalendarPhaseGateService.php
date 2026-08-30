<?php

namespace App\Actions\Calendar;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\CalendarEvent;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
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

    public function enrollmentWindow(int $termId, ?CarbonImmutable $at = null): TermCalendarWindow
    {
        return $this->resolveTermWindow(
            termId: $termId,
            windowType: TermCalendarWindow::TypeEnrollment,
            gate: 'enrollment_window',
            missingMessage: 'Enrollment gate is not configured for this term.',
            closedMessage: 'Enrollment is outside the configured window.',
            at: $at,
        );
    }

    public function enrollmentDeadline(int $termId, ?CarbonImmutable $at = null): CarbonImmutable
    {
        $window = $this->enrollmentWindow($termId, $at);

        return $this->windowBounds($window)['closes_at'];
    }

    public function finalEnrollmentCutoff(int $termId): CarbonImmutable
    {
        $this->assertTermExists($termId);
        $window = $this->activePackage($termId)?->windows()
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->first();

        if (! $window instanceof TermCalendarWindow) {
            throw new CalendarGateViolation(
                'Enrollment final cutoff is not configured for this term.',
                'enrollment_final_cutoff',
                ['term_id' => $termId, 'window_configured' => false],
            );
        }

        return $this->windowBounds($window)['closes_at'];
    }

    public function assertSchedulingWindowOpen(int $termId, ?CarbonImmutable $at = null): void
    {
        $this->resolveLegacyWindow(
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
            $this->resolveTermWindow(
                termId: $termId,
                windowType: TermCalendarWindow::TypeEnrollment,
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

    public function assertEnrollmentAdjustmentWindowOpen(int $termId, ?CarbonImmutable $at = null): void
    {
        $this->resolveTermWindow(
            termId: $termId,
            windowType: TermCalendarWindow::TypeEnrollmentAdjustment,
            gate: 'enrollment_adjustment_window',
            missingMessage: 'Enrollment Adjustment window is not configured for this term.',
            closedMessage: 'Enrollment Adjustment is outside the configured window.',
            at: $at,
        );
    }

    public function assertCourseDropWindowOpen(int $termId, ?CarbonImmutable $at = null): void
    {
        $this->resolveTermWindow(
            termId: $termId,
            windowType: TermCalendarWindow::TypeCourseDrop,
            gate: 'course_drop_window',
            missingMessage: 'Course Drop window is not configured for this term.',
            closedMessage: 'Course Drop is outside the configured window.',
            at: $at,
        );
    }

    /**
     * @deprecated Use the exact-Term package gates directly.
     */
    public function isCutoverActive(int $termId, ?CarbonImmutable $at = null): bool
    {
        $evaluatedAt = $at ?? CarbonImmutable::now();

        try {
            $this->enrollmentWindow($termId, $evaluatedAt);

            return true;
        } catch (CalendarGateViolation) {
            return false;
        }
    }

    private function resolveTermWindow(
        int $termId,
        string $windowType,
        string $gate,
        string $missingMessage,
        string $closedMessage,
        ?CarbonImmutable $at,
    ): TermCalendarWindow {
        $evaluatedAt = $at ?? CarbonImmutable::now();
        $this->assertTermExists($termId);
        $package = $this->activePackage($termId);
        $windows = $package?->windows()->where('window_type', $windowType)->get() ?? collect();

        if ($windows->isEmpty()) {
            throw new CalendarGateViolation(
                $missingMessage,
                $gate,
                [
                    'term_id' => $termId,
                    'window_type' => $windowType,
                    'window_configured' => false,
                    'evaluated_at' => $evaluatedAt->toIso8601String(),
                ],
            );
        }

        $window = $windows->first(function (TermCalendarWindow $window) use ($evaluatedAt): bool {
            $bounds = $this->windowBounds($window);

            return $evaluatedAt->betweenIncluded($bounds['opens_at'], $bounds['closes_at']);
        });

        if (! $window instanceof TermCalendarWindow) {
            throw new CalendarGateViolation(
                $closedMessage,
                $gate,
                [
                    'term_id' => $termId,
                    'window_type' => $windowType,
                    'window_configured' => true,
                    'evaluated_at' => $evaluatedAt->toIso8601String(),
                    'term_calendar_package_id' => $package?->id,
                    'configured_windows' => $windows->map(fn (TermCalendarWindow $window): array => [
                        'opens_at' => $this->windowBounds($window)['opens_at']->toIso8601String(),
                        'closes_at' => $this->windowBounds($window)['closes_at']->toIso8601String(),
                    ])->all(),
                ],
            );
        }

        return $window;
    }

    private function resolveLegacyWindow(
        int $termId,
        string $processKey,
        string $gate,
        string $missingMessage,
        string $closedMessage,
        ?CarbonImmutable $at,
    ): CalendarEvent {
        $evaluatedAt = $at ?? CarbonImmutable::now();
        $this->assertTermExists($termId);
        $windows = $this->windowQuery($termId, $processKey)->orderBy('start_at')->get();

        if ($windows->isEmpty()) {
            throw new CalendarGateViolation($missingMessage, $gate, ['term_id' => $termId, 'window_configured' => false]);
        }

        $window = $windows->first(fn (CalendarEvent $event): bool => $event->start_at instanceof \DateTimeInterface
            && $event->end_at instanceof \DateTimeInterface
            && $evaluatedAt->betweenIncluded(CarbonImmutable::instance($event->start_at), CarbonImmutable::instance($event->end_at)));

        if (! $window instanceof CalendarEvent) {
            throw new CalendarGateViolation($closedMessage, $gate, ['term_id' => $termId, 'window_configured' => true]);
        }

        return $window;
    }

    private function activePackage(int $termId): ?TermCalendarPackage
    {
        return TermCalendarPackage::query()
            ->where('term_id', $termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->latest('version')
            ->first();
    }

    /** @return array{opens_at: CarbonImmutable, closes_at: CarbonImmutable} */
    private function windowBounds(TermCalendarWindow $window): array
    {
        $timezone = (string) config('app.timezone');
        $opensAt = CarbonImmutable::parse((string) $window->opens_on, $timezone)->startOfDay();
        $cutoff = filled($window->cutoff_at) ? (string) $window->cutoff_at : '23:59:59';
        $closesAt = CarbonImmutable::parse($window->closes_on->toDateString().' '.$cutoff, $timezone);

        return ['opens_at' => $opensAt, 'closes_at' => $closesAt];
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
