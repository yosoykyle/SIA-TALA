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
        Schema::create('shifting_fee_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shifting_request_id')->constrained()->cascadeOnDelete();
            $table->decimal('fee_amount', 12, 2);
            $table->date('due_date')->nullable();
            $table->string('status')->default('assessed')->index(); // assessed, paid, waived, cancelled
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifting_fee_assessments');
    }
};
