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
        Schema::create('accounting_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->unique()->constrained('ledger_entries')->nullOnDelete();
            $table->string('adjustment_type')->index();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('evidence_reference')->nullable();
            $table->timestamp('posted_at');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_profile_id', 'term_id', 'created_at'], 'accounting_adjustments_student_term_time_index');
            $table->index(['enrollment_id', 'adjustment_type'], 'accounting_adjustments_enrollment_type_index');
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
