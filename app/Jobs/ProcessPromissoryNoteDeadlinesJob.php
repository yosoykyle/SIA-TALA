<?php

namespace App\Jobs;

use App\Actions\Finance\PromissoryNoteLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPromissoryNoteDeadlinesJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PromissoryNoteLifecycleService $service): void
    {
        $service->processDeadlines();
    }
}
