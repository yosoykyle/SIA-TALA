<?php

namespace App\Jobs;

use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Models\OperationalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessPayMongoWebhookCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $webhookCallId,
        public int $operationalEventId,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PayMongoWebhookProcessor $processor): void
    {
        try {
            $processor->process($this->webhookCallId, $this->operationalEventId);
        } catch (Throwable $exception) {
            DB::table('webhook_calls')->where('id', $this->webhookCallId)->update([
                'exception' => 'processing_exception:'.$exception::class,
                'updated_at' => CarbonImmutable::now(config('app.timezone'))->toDateTimeString(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $exceptionClass = $exception !== null ? $exception::class : 'unknown';

        DB::transaction(function () use ($now, $exceptionClass): void {
            DB::table('webhook_calls')->where('id', $this->webhookCallId)->update([
                'exception' => 'processing_failed:'.$exceptionClass,
                'processed_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

            $event = OperationalEvent::query()->lockForUpdate()->find($this->operationalEventId);

            if ($event !== null) {
                $event->forceFill([
                    'status' => OperationalEvent::StatusFailed,
                    'failed_at' => $now,
                    'diagnostics' => [
                        ...($event->diagnostics ?? []),
                        'reason' => 'processing_failed',
                        'exception_class' => $exceptionClass,
                    ],
                ])->save();
            }
        }, 3);
    }
}
