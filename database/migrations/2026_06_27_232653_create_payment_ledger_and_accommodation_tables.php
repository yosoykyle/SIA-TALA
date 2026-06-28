<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('financial_accommodations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->decimal('balance_snapshot', 12, 2);
            $table->decimal('covered_amount', 12, 2);
            $table->string('basis');
            $table->string('certification_reference')->nullable();
            $table->string('private_evidence_reference')->nullable();
            $table->boolean('promissory_required')->default(false);
            $table->string('promissory_maker')->nullable();
            $table->boolean('allows_finance_gate')->default(false);
            $table->boolean('waives_downpayment')->default(false);
            $table->string('authority');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->date('effective_from');
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->index(['student_profile_id', 'term_id', 'status'], 'accommodations_student_term_index');
            $table->index('expires_on');
        });

        Schema::create('payment_schedule_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('financial_accommodation_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('category');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->string('state');
            $table->unsignedBigInteger('linked_payment_allocation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['assessment_id', 'sequence']);
            $table->unique(['financial_accommodation_id', 'sequence'], 'accommodation_schedule_sequence_unique');
            $table->index(['due_date', 'state']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('channel');
            $table->string('provider');
            $table->string('internal_reference')->unique();
            $table->string('provider_checkout_id')->nullable()->unique();
            $table->string('provider_intent_id')->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PHP');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['student_profile_id', 'status']);
            $table->index(['assessment_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('method');
            $table->string('channel');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PHP');
            $table->string('evidence_status');
            $table->timestamp('paid_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('or_number')->nullable()->unique();
            $table->foreignId('or_mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('or_mapped_at')->nullable();
            $table->string('provider_reference')->nullable()->unique();
            $table->timestamps();
            $table->index(['student_profile_id', 'term_id', 'evidence_status'], 'payments_student_term_status_index');
            $table->index(['or_number', 'or_mapped_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_schedule_row_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prior_balance_ledger_entry_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'assessment_line_id'], 'allocations_payment_line_unique');
            $table->unique(['payment_id', 'payment_schedule_row_id'], 'allocations_payment_schedule_unique');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('direction');
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('adjusts_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('description');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->string('state');
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'direction'], 'ledger_source_posting_unique');
            $table->index(['student_profile_id', 'posted_at']);
            $table->index(['term_id', 'category']);
            $table->index(['reverses_entry_id']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreign('prior_balance_ledger_entry_id')->references('id')->on('ledger_entries')->restrictOnDelete();
        });

        Schema::table('payment_schedule_rows', function (Blueprint $table) {
            $table->foreign('linked_payment_allocation_id')->references('id')->on('payment_allocations')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE financial_accommodations ADD CONSTRAINT accommodations_amount_check CHECK (covered_amount >= 0)');
        DB::statement('ALTER TABLE payment_schedule_rows ADD CONSTRAINT payment_schedule_owner_check CHECK ((assessment_id IS NOT NULL AND financial_accommodation_id IS NULL) OR (assessment_id IS NULL AND financial_accommodation_id IS NOT NULL))');
        DB::statement('ALTER TABLE payment_schedule_rows ADD CONSTRAINT payment_schedule_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_target_check CHECK ((assessment_line_id IS NOT NULL) + (payment_schedule_row_id IS NOT NULL) + (prior_balance_ledger_entry_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_check CHECK (amount <> 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_schedule_rows', function (Blueprint $table) {
            $table->dropForeign(['linked_payment_allocation_id']);
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['prior_balance_ledger_entry_id']);
        });
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_schedule_rows');
        Schema::dropIfExists('financial_accommodations');
    }
};
