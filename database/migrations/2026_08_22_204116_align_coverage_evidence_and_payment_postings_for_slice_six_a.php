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
        Schema::table('approved_coverages', function (Blueprint $table) {
            $table->foreignId('supersedes_coverage_id')->nullable()->after('assessment_obligation_id');
            $table->string('state', 24)->default('Applied')->after('supersedes_coverage_id');
            $table->string('category', 40)->nullable()->after('state');
            $table->string('safe_source_description')->nullable()->after('category');
            $table->date('authority_date')->nullable()->after('authority_reference');
            $table->date('effective_date')->nullable()->after('authority_date');
            $table->string('reversal_authority_reference')->nullable()->after('reversal_reason');
            $table->foreign('supersedes_coverage_id', 'approved_coverage_supersedes_fk')->references('id')->on('approved_coverages')->restrictOnDelete();
            $table->index(['assessment_obligation_id', 'state', 'effective_date'], 'coverage_obligation_state_index');
        });
        Schema::table('payment_evidence_versions', function (Blueprint $table) {
            $table->string('channel', 40)->nullable()->after('claimed_amount');
            $table->timestamp('paid_at')->nullable()->after('channel');
            $table->boolean('possible_duplicate')->default(false)->after('payment_reference');
            $table->string('external_check_reference')->nullable()->after('review_note');
            $table->decimal('actual_verified_amount', 12, 2)->nullable()->after('external_check_reference');
            $table->index(['term_account_id', 'channel', 'payment_reference'], 'payment_evidence_duplicate_index');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('state', 24)->default('Posted')->after('evidence_status');
            $table->string('verification_basis', 40)->nullable()->after('verified_by');
            $table->string('external_check_reference')->nullable()->after('verification_basis');
            $table->string('reversal_authority_reference')->nullable()->after('reversal_reason');
            $table->unique('payment_evidence_version_id', 'payments_evidence_posting_unique');
            $table->unique('reverses_payment_id', 'payments_one_reversal_unique');
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unsignedSmallInteger('sequence')->nullable()->after('payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropColumn('sequence'));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_one_reversal_unique');
            $table->dropUnique('payments_evidence_posting_unique');
            $table->dropColumn(['state', 'verification_basis', 'external_check_reference', 'reversal_authority_reference']);
        });
        Schema::table('payment_evidence_versions', function (Blueprint $table) {
            $table->dropIndex('payment_evidence_duplicate_index');
            $table->dropColumn(['channel', 'paid_at', 'possible_duplicate', 'external_check_reference', 'actual_verified_amount']);
        });
        Schema::table('approved_coverages', function (Blueprint $table) {
            $table->dropIndex('coverage_obligation_state_index');
            $table->dropForeign('approved_coverage_supersedes_fk');
            $table->dropColumn(['supersedes_coverage_id', 'state', 'category', 'safe_source_description', 'authority_date', 'effective_date', 'reversal_authority_reference']);
        });
    }
};
