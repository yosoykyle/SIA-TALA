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
        Schema::create('installment_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('education_level')->index();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('year_level')->nullable()->index();
            $table->unsignedTinyInteger('max_months')->default(10);
            $table->string('due_day_rule')->default('end_of_month'); // end_of_month
            $table->unsignedTinyInteger('grace_days')->default(3);
            $table->decimal('penalty_rate', 5, 2)->default('5.00');
            $table->string('penalty_frequency')->default('per_missed_month'); // per_missed_month, one_time
            $table->boolean('allow_partial_payments')->default(false);
            $table->boolean('promissory_is_non_clearing')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['education_level', 'is_active']);
            $table->unique(['name', 'education_level', 'program_id', 'year_level'], 'installment_policies_scope_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installment_policies');
    }
};
