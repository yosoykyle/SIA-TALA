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
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('ledger_category');
            $table->string('display_category');
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('calculation_type');
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('rate', 7, 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('authority');
            $table->timestamps();
            $table->unique(['code', 'program_id', 'term_id', 'effective_from'], 'fee_rules_scope_unique');
            $table->index(['program_id', 'term_id', 'is_active'], 'fee_rules_applicability_index');
            $table->index(['ledger_category', 'is_active']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state');
            $table->char('currency', 3)->default('PHP');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('required_downpayment', 12, 2)->default(0);
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('superseded_by_assessment_id')->nullable()->constrained('assessments')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['enrollment_id', 'version']);
            $table->index(['state', 'created_at']);
        });

        Schema::create('assessment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_line_key');
            $table->string('description_snapshot');
            $table->decimal('quantity', 7, 4)->default(1);
            $table->decimal('rate', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->string('line_type');
            $table->timestamps();
            $table->unique(['assessment_id', 'source_line_key']);
            $table->index(['fee_rule_id', 'line_type']);
            $table->index(['course_enrollment_id']);
        });

        DB::statement('ALTER TABLE fee_rules ADD CONSTRAINT fee_rules_value_check CHECK (amount IS NOT NULL OR rate IS NOT NULL)');
        DB::statement('ALTER TABLE assessments ADD CONSTRAINT assessments_money_check CHECK (subtotal >= 0 AND discount_total >= 0 AND total >= 0 AND required_downpayment >= 0)');
        DB::statement('ALTER TABLE assessment_lines ADD CONSTRAINT assessment_lines_money_check CHECK (quantity > 0 AND rate >= 0 AND amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_lines');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('fee_rules');
    }
};
