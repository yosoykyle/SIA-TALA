<?php

namespace App\Console\Commands;

use App\Actions\Enrollment\ReleaseExpiredEnrollmentReservations as ReleaseExpiredEnrollmentReservationsAction;
use Illuminate\Console\Command;

class ReleaseExpiredEnrollmentReservations extends Command
{
    protected $signature = 'enrollment:release-expired-reservations';

    protected $description = 'Release expired canonical registration seat reservations';

    public function handle(ReleaseExpiredEnrollmentReservationsAction $releaseExpiredReservations): int
    {
        $released = $releaseExpiredReservations->execute();
        $this->info("Expired enrollment reservations released: {$released}");

        return self::SUCCESS;
    }
}
