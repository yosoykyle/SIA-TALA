<?php

namespace App\Jobs;

use App\Actions\Applicants\RetentionDocumentUndertakingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRetentionDocumentUndertakingsJob implements ShouldQueue
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

    public function handle(RetentionDocumentUndertakingService $service): void
    {
        $service->processDeadlines();
    }
}
