<?php

namespace App\Console\Commands;

use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Models\StudentProfile;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use Illuminate\Console\Command;

class DispatchEnrollmentWindowNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enrollment:dispatch-window-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch idempotent continuing-Student notices for active exact-Term enrollment windows';

    /**
     * Execute the console command.
     */
    public function handle(RegistrationNotificationLedger $notifications): int
    {
        $today = today(config('app.timezone'));
        $windows = TermCalendarWindow::query()
            ->with('package')
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->whereDate('opens_on', '<=', $today)
            ->whereDate('closes_on', '>=', $today)
            ->whereHas('package', fn ($query) => $query->where('state', TermCalendarPackage::StateActive))
            ->orderBy('id')
            ->get();
        $queued = 0;

        foreach ($windows as $window) {
            StudentProfile::query()
                ->with('user')
                ->where('lifecycle_status', StudentProfile::LifecycleActive)
                ->whereNull('archived_at')
                ->whereNull('merged_into_id')
                ->whereHas('user', fn ($query) => $query
                    ->where('status', User::StatusActive)
                    ->whereNotNull('email')
                    ->where('email', '<>', ''))
                ->whereDoesntHave('enrollments', fn ($query) => $query->where('term_id', $window->package->term_id))
                ->orderBy('id')
                ->chunkById(100, function ($profiles) use ($notifications, $window, &$queued): void {
                    foreach ($profiles as $profile) {
                        $notifications->recordContinuingWindow($profile, $window->package, $window);
                        $queued++;
                    }
                });
        }

        $this->info("Processed {$queued} continuing-Student enrollment-window recipients.");

        return self::SUCCESS;
    }
}
