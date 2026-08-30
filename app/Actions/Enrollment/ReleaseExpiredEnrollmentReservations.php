<?php

namespace App\Actions\Enrollment;

use App\Models\EnrollmentSeatReservation;
use Illuminate\Support\Facades\DB;

class ReleaseExpiredEnrollmentReservations
{
    public function __construct(
        private readonly RegistrationNotificationLedger $notifications,
        private readonly ExpireRegistrationCasesAtFinalCutoff $caseExpiry,
    ) {}

    public function execute(): int
    {
        $released = $this->caseExpiry->execute();

        EnrollmentSeatReservation::query()
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->whereNotNull('deadline')
            ->where('deadline', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use (&$released): void {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation, &$released): void {
                        $locked = EnrollmentSeatReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

                        if (in_array($locked->status, EnrollmentSeatReservation::capacityHoldingStatuses(), true)
                            && $locked->deadline?->isPast()) {
                            $locked->update(['status' => EnrollmentSeatReservation::StatusReleased, 'released_at' => now(), 'lock_version' => $locked->lock_version + 1]);
                            $this->notifications->recordReservationRelease($locked->refresh());
                            $released++;
                        }
                    }, attempts: 3);
                }
            });

        return $released;
    }
}
