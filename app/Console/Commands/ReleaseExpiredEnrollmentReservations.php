<?php

namespace App\Console\Commands;

use App\Actions\Enrollment\EnrollmentPlacementService;
use Illuminate\Console\Command;

class ReleaseExpiredEnrollmentReservations extends Command
{
    protected $signature = 'enrollment:release-expired-reservations';

    protected $description = 'Release expired pending enrollment reservations and their schedule bindings';

    public function handle(EnrollmentPlacementService $placement): int
    {
        $released = $placement->releaseExpired();
        $this->info("Expired enrollment reservations released: {$released}");

        return self::SUCCESS;
    }
}
