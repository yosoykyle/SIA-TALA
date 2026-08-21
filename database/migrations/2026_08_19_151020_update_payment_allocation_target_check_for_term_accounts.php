<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_allocations DROP CHECK payment_allocations_target_check');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_target_check CHECK ((assessment_obligation_id IS NOT NULL) + (assessment_line_id IS NOT NULL) + (payment_schedule_row_id IS NOT NULL) + (prior_balance_ledger_entry_id IS NOT NULL) = 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_allocations DROP CHECK payment_allocations_target_check');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_target_check CHECK ((assessment_line_id IS NOT NULL) + (payment_schedule_row_id IS NOT NULL) + (prior_balance_ledger_entry_id IS NOT NULL) = 1)');
    }
};
