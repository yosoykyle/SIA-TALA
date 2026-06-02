<?php

namespace App\Jobs;

use App\Actions\Finance\InstallmentPolicyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInstallmentOverduesJob implements ShouldQueue
{
    use Queueable;

    /**
     * @var int
     */
    public $tries = 3;

    public function __construct() {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(InstallmentPolicyService $installmentPolicyService): void
    {
        $installmentPolicyService->processOverdues();
    }
}
