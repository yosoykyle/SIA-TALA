<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Jobs\ProcessDocumentOcrJob;
use App\Jobs\ProcessInstallmentOverduesJob;
use App\Jobs\ProcessPayMongoWebhookCall;
use App\Jobs\ShippingFeeEnforcerJob;
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

        foreach (['jobs', 'failed_jobs', 'webhook_calls', 'document_ocr_results', 'payment_attempts'] as $table) {
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

        foreach (['status', 'processing_error'] as $column) {
            $this->assertTrue(Schema::hasColumn('document_ocr_results', $column), "document_ocr_results.{$column} should exist.");
        }

        $this->assertSame(0, Artisan::call('queue:failed'));
        $this->assertStringContainsString('No failed jobs found', Artisan::output());
    }

    public function test_required_scheduled_jobs_are_registered_with_overlap_mutexes(): void
    {
        $this->assertSame(0, Artisan::call('schedule:list', ['--json' => true]));

        $tasks = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertNotNull($tasks->firstWhere('command', 'installments.process-overdues'));
        $this->assertNotNull($tasks->firstWhere('command', 'document-requests.shipping-fee-enforcer'));

        $events = collect(Schedule::events());
        $installmentTask = $events->firstWhere('description', 'installments.process-overdues');
        $shippingTask = $events->firstWhere('description', 'document-requests.shipping-fee-enforcer');

        $this->assertIsObject($installmentTask);
        $this->assertSame('10 0 * * *', $installmentTask->expression);
        $this->assertTrue($installmentTask->withoutOverlapping);

        $this->assertIsObject($shippingTask);
        $this->assertSame('30 0 * * *', $shippingTask->expression);
        $this->assertTrue($shippingTask->withoutOverlapping);
    }

    public function test_queue_jobs_have_explicit_retry_backoff_metadata(): void
    {
        $installmentJob = new ProcessInstallmentOverduesJob;
        $shippingJob = new ShippingFeeEnforcerJob;
        $payMongoJob = new ProcessPayMongoWebhookCall(1);
        $ocrJob = new ProcessDocumentOcrJob(1);

        $this->assertSame(3, $installmentJob->tries);
        $this->assertSame([60, 300, 900], $installmentJob->backoff());

        $this->assertSame(3, $shippingJob->tries);
        $this->assertSame([60, 300, 900], $shippingJob->backoff());

        $this->assertSame(3, $payMongoJob->tries);
        $this->assertSame([60, 300, 900], $payMongoJob->backoff());

        $this->assertSame(5, $ocrJob->tries);
        $this->assertSame([10, 30, 60], $ocrJob->backoff());
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

        (new ProcessPayMongoWebhookCall($webhookCallId))->handle($processor);

        $webhookCall = DB::table('webhook_calls')->where('id', $webhookCallId)->first();

        $this->assertNotNull($webhookCall);
        $this->assertNull($webhookCall->processed_at);
        $this->assertStringContainsString(RuntimeException::class, $webhookCall->exception);
        $this->assertStringContainsString('simulated webhook processor outage', $webhookCall->exception);
    }

    private function prepareSchema(): void
    {
        foreach (['payment_attempts', 'document_ocr_results', 'webhook_calls', 'failed_jobs', 'jobs'] as $table) {
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

        Schema::create('document_ocr_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_upload_id');
            $table->string('ocr_engine')->default('google_vision')->index();
            $table->string('parser_version')->nullable();
            $table->longText('ocr_text')->nullable();
            $table->decimal('ocr_confidence', 5, 2)->nullable();
            $table->string('status')->default('ocr_extracted')->index();
            $table->text('processing_error')->nullable();
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
