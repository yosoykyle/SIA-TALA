<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\RegistrationCaseEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ExpireRegistrationCasesAtFinalCutoff
{
    public function __construct(
        private readonly CalendarPhaseGateService $calendar,
        private readonly RegistrationNotificationLedger $notifications,
    ) {}

    public function execute(?CarbonImmutable $at = null): int
    {
        $evaluatedAt = $at ?? CarbonImmutable::now(config('app.timezone'));
        $releasedReservations = 0;

        Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->orderBy('id')
            ->chunkById(100, function ($enrollments) use ($evaluatedAt, &$releasedReservations): void {
                foreach ($enrollments as $enrollment) {
                    try {
                        $cutoff = $this->calendar->finalEnrollmentCutoff((int) $enrollment->term_id);
                    } catch (CalendarGateViolation) {
                        continue;
                    }

                    if (! $evaluatedAt->isAfter($cutoff)) {
                        continue;
                    }

                    [$expired, $released] = DB::transaction(function () use ($enrollment, $evaluatedAt): array {
                        $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
                        if ($locked->canonical_outcome !== Enrollment::OutcomeInProgress) {
                            return [null, 0];
                        }

                        $reservations = EnrollmentSeatReservation::query()
                            ->where('enrollment_id', $locked->id)
                            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                            ->lockForUpdate()
                            ->get();
                        foreach ($reservations as $reservation) {
                            $reservation->update([
                                'status' => EnrollmentSeatReservation::StatusReleased,
                                'released_at' => $evaluatedAt,
                                'lock_version' => $reservation->lock_version + 1,
                            ]);
                        }

                        $from = $locked->canonical_outcome;
                        $locked->update([
                            'canonical_outcome' => Enrollment::OutcomeNotEnrolled,
                            'status' => 'not_enrolled',
                            'status_reason' => 'The exact-Term final enrollment cutoff passed before Official Enrollment.',
                            'lock_version' => $locked->lock_version + 1,
                        ]);
                        RegistrationCaseEvent::query()->create([
                            'enrollment_id' => $locked->id,
                            'sequence' => ((int) $locked->registrationEvents()->max('sequence')) + 1,
                            'event_type' => Enrollment::OutcomeNotEnrolled,
                            'from_outcome' => $from,
                            'to_outcome' => Enrollment::OutcomeNotEnrolled,
                            'reason' => 'The exact-Term final enrollment cutoff passed before Official Enrollment.',
                            'authority_reference' => 'term-calendar-final-enrollment-cutoff',
                            'recorded_at' => $evaluatedAt,
                        ]);

                        return [$locked->refresh(), $reservations->count()];
                    }, attempts: 3);

                    if ($expired instanceof Enrollment) {
                        $this->notifications->recordCaseExpiry($expired);
                        $releasedReservations += $released;
                    }
                }
            });

        return $releasedReservations;
    }
}
