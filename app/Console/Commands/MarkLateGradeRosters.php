<?php

namespace App\Console\Commands;

use App\Actions\Grades\GradeWindowService;
use App\Models\GradeRoster;
use Illuminate\Console\Command;

class MarkLateGradeRosters extends Command
{
    protected $signature = 'grades:mark-late-rosters';

    protected $description = 'Mark draft or returned grade rosters late after the finalization window closes.';

    public function handle(GradeWindowService $windows): int
    {
        $count = 0;

        GradeRoster::query()
            ->with('termOffering')
            ->whereIn('state', [GradeRoster::StateDraft, GradeRoster::StateReturned])
            ->chunkById(100, function ($rosters) use ($windows, &$count): void {
                foreach ($rosters as $roster) {
                    if ($windows->finalizationClosed($roster)) {
                        $roster->update(['state' => GradeRoster::StateLateNotSubmitted]);
                        $count++;
                    }
                }
            });

        $this->info("Marked {$count} roster(s) as late.");

        return self::SUCCESS;
    }
}
