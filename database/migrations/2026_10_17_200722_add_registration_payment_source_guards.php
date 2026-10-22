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
        Schema::table('fee_plans', function (Blueprint $table): void {
            $table->string('no_payment_required_authority_reference')->nullable();
        });
        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->foreignId('assessment_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('obligation_snapshot')->nullable();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->index('reverses_payment_id', 'payments_reversal_history_index');
            $table->dropUnique('payments_one_reversal_unique');
            $table->string('external_refund_reference')->nullable();
            $table->decimal('external_refund_amount', 12, 2)->nullable();
            $table->timestamp('external_refund_at')->nullable();
            $table->unique(['term_account_id', 'external_refund_reference'], 'payments_external_refund_unique');
        });
        Schema::create('assessment_effect_revalidations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('term_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('obligation_mapping');
            $table->string('authority_reference');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->unique(['assessment_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('reverses_payment_id')->groupBy('reverses_payment_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Partial refund history must be preserved before reverting this migration.');
        }
        Schema::dropIfExists('assessment_effect_revalidations');
        Schema::table('payments', function (Blueprint $table): void {
            $table->unique('reverses_payment_id', 'payments_one_reversal_unique');
            $table->dropIndex('payments_reversal_history_index');
            $table->dropUnique('payments_external_refund_unique');
            $table->dropColumn(['external_refund_reference', 'external_refund_amount', 'external_refund_at']);
        });
        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assessment_id');
            $table->dropColumn('obligation_snapshot');
        });
        Schema::table('fee_plans', function (Blueprint $table): void {
            $table->dropColumn('no_payment_required_authority_reference');
        });
    }
};
