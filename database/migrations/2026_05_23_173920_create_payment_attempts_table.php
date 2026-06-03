<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $webhookIdempotencyExpression = Schema::getConnection()->getDriverName() === 'sqlite'
            ? "
                CASE
                    WHEN provider_event_id IS NULL THEN NULL
                    WHEN provider_checkout_session_id IS NOT NULL AND provider_checkout_session_id <> '' THEN provider_event_id || ':' || provider_checkout_session_id
                    WHEN provider_payment_id IS NOT NULL AND provider_payment_id <> '' THEN provider_event_id || ':' || provider_payment_id
                    ELSE NULL
                END
            "
            : "
                CASE
                    WHEN provider_event_id IS NULL THEN NULL
                    WHEN provider_checkout_session_id IS NOT NULL AND provider_checkout_session_id <> '' THEN CONCAT(provider_event_id, ':', provider_checkout_session_id)
                    WHEN provider_payment_id IS NOT NULL AND provider_payment_id <> '' THEN CONCAT(provider_event_id, ':', provider_payment_id)
                    ELSE NULL
                END
            ";

        Schema::create('payment_attempts', function (Blueprint $table) use ($webhookIdempotencyExpression) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->string('channel')->index(); // paymongo, otc, manual
            $table->string('status')->default('pending')->index(); // pending, paid, failed, cancelled
            $table->string('provider')->nullable()->index();
            $table->string('provider_event_id')->nullable();
            $table->string('provider_checkout_session_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_payment_intent_id')->nullable();
            $table->string('webhook_idempotency_key')->nullable()->storedAs($webhookIdempotencyExpression);
            $table->decimal('amount', 12, 2);
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique('webhook_idempotency_key', 'payment_attempts_webhook_idempotency_unique');
            $table->index(['student_profile_id', 'status', 'created_at'], 'payment_attempts_student_status_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
