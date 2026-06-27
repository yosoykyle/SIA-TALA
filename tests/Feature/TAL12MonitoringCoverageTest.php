<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Jobs\ProcessPayMongoWebhookCall;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TAL12MonitoringCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchema();
    }

    public function test_required_health_queue_and_failure_surfaces_exist(): void
    {
        $this->get('/up')->assertOk();

        foreach (['jobs', 'failed_jobs', 'webhook_calls', 'payment_attempts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} table should exist for monitoring coverage.");
        }

        foreach (['queue', 'payload', 'attempts'] as $column) {
            $this->assertTrue(Schema::hasColumn('jobs', $column), "jobs.{$column} should exist.");
        }

        foreach (['uuid', 'connection', 'queue', 'exception', 'failed_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('failed_jobs', $column), "failed_jobs.{$column} should exist.");
        }

        foreach (['processed_at', 'exception'] as $column) {
            $this->assertTrue(Schema::hasColumn('webhook_calls', $column), "webhook_calls.{$column} should exist.");
        }

        $this->assertSame(0, Artisan::call('queue:failed'));
        $this->assertStringContainsString('No failed jobs found', Artisan::output());
    }

    public function test_required_scheduled_jobs_are_registered_with_overlap_mutexes(): void
    {
        $events = collect(Schedule::events());

        $this->assertNull($events->firstWhere('description', 'installments.process-overdues'));
        $this->assertNull($events->firstWhere('description', 'promissory-notes.process-deadlines'));
    }

    public function test_queue_jobs_have_explicit_retry_backoff_metadata(): void
    {
        $payMongoJob = new ProcessPayMongoWebhookCall(1);

        $this->assertSame(3, $payMongoJob->tries);
        $this->assertSame([60, 300, 900], $payMongoJob->backoff());
    }

    public function test_paymongo_webhook_processing_failures_are_visible_on_webhook_call(): void
    {
        $webhookCallId = (int) DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => 'https://example.test/api/webhooks/paymongo',
            'headers' => json_encode(['paymongo-signature' => ['test']], JSON_UNESCAPED_SLASHES),
            'payload' => json_encode(['data' => ['id' => 'evt_failure']], JSON_UNESCAPED_SLASHES),
            'attachments' => null,
            'exception' => null,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processor = new class extends PayMongoWebhookProcessor
        {
            public function __construct() {}

            /**
             * @return array{status:string}
             */
            public function process(int $webhookCallId): array
            {
                throw new RuntimeException('simulated webhook processor outage');
            }
        };

        try {
            (new ProcessPayMongoWebhookCall($webhookCallId))->handle($processor);

            $this->fail('Webhook processing failures must be rethrown so the queue can retry the job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated webhook processor outage', $exception->getMessage());
        }

        $webhookCall = DB::table('webhook_calls')->where('id', $webhookCallId)->first();

        $this->assertNotNull($webhookCall);
        $this->assertNull($webhookCall->processed_at);
        $this->assertStringContainsString(RuntimeException::class, $webhookCall->exception);
        $this->assertStringContainsString('simulated webhook processor outage', $webhookCall->exception);
    }

    private function prepareSchema(): void
    {
        foreach (['payment_attempts', 'webhook_calls', 'failed_jobs', 'jobs'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('webhook_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('url', 512);
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('attachments')->nullable();
            $table->text('exception')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('pending')->index();
            $table->string('provider')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->timestamps();
        });
    }
}
