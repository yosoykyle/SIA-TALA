<?php

namespace App\Jobs;

use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessPayMongoWebhookCall implements ShouldQueue
{
    use Queueable;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $webhookCallId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Execute the job.
     */
    public function handle(PayMongoWebhookProcessor $processor): void
    {
        try {
            $processor->process($this->webhookCallId);
        } catch (Throwable $exception) {
            DB::table('webhook_calls')->where('id', $this->webhookCallId)->update([
                'exception' => $exception::class.': '.$exception->getMessage(),
                'updated_at' => CarbonImmutable::now(config('app.timezone'))->toDateTimeString(),
            ]);

            throw $exception;
        }
    }
}
