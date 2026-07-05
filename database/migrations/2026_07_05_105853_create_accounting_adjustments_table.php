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
        Schema::create('accounting_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_ledger_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('adjustment_type');
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('evidence_reference')->nullable();
            $table->timestamp('posted_at');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_profile_id', 'posted_at'], 'accounting_adjustments_student_posted_index');
            $table->index(['adjustment_type', 'posted_at'], 'accounting_adjustments_type_posted_index');
            $table->index(['source_ledger_entry_id'], 'accounting_adjustments_source_ledger_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_adjustments');
    }
};
