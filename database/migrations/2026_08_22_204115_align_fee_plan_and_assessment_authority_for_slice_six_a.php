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
        Schema::table('fee_plans', function (Blueprint $table) {
            $table->date('authority_date')->nullable()->after('authority_reference');
        });
        Schema::table('fee_plan_charges', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('label');
        });
        Schema::table('fee_plan_obligations', function (Blueprint $table) {
            $table->unsignedSmallInteger('sequence')->nullable()->after('fee_plan_id');
            $table->string('purpose', 40)->nullable()->after('label');
            $table->timestamp('due_at')->nullable()->after('amount');
            $table->index(['fee_plan_id', 'due_at', 'sequence'], 'fee_plan_obligation_due_index');
        });
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('reason_category', 48)->nullable()->after('assessment_basis');
            $table->date('authority_date')->nullable()->after('authority_reference');
            $table->json('source_snapshot')->nullable()->after('authority_date');
        });
        Schema::table('assessment_obligations', function (Blueprint $table) {
            $table->unsignedSmallInteger('sequence')->nullable()->after('assessment_id');
            $table->string('purpose', 40)->nullable()->after('label');
            $table->timestamp('due_at')->nullable()->after('amount');
            $table->index(['assessment_id', 'due_at', 'sequence'], 'assessment_obligation_due_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessment_obligations', function (Blueprint $table) {
            $table->dropIndex('assessment_obligation_due_index');
            $table->dropColumn(['sequence', 'purpose', 'due_at']);
        });
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['reason_category', 'authority_date', 'source_snapshot']);
        });
        Schema::table('fee_plan_obligations', function (Blueprint $table) {
            $table->dropIndex('fee_plan_obligation_due_index');
            $table->dropColumn(['sequence', 'purpose', 'due_at']);
        });
        Schema::table('fee_plan_charges', fn (Blueprint $table) => $table->dropColumn('category'));
        Schema::table('fee_plans', fn (Blueprint $table) => $table->dropColumn('authority_date'));
    }
};
