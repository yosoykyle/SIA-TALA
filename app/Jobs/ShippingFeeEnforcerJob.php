<?php

namespace App\Jobs;

use App\Actions\ServiceRequests\DocumentRequestLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ShippingFeeEnforcerJob implements ShouldQueue
{
    use Queueable;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(DocumentRequestLifecycleService $documentRequestLifecycleService): void
    {
        $documentRequestLifecycleService->postExpiredShippingFees(limit: 100);
    }
}
