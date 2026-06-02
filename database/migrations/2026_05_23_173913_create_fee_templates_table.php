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
        Schema::create('fee_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('education_level')->index(); // shs or college
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('year_level')->nullable()->index();
            $table->decimal('tuition_fee', 12, 2)->default('0.00');
            $table->decimal('laboratory_fee', 12, 2)->default('0.00');
            $table->decimal('misc_fee', 12, 2)->default('0.00');
            $table->decimal('other_fee', 12, 2)->default('0.00');
            $table->decimal('minimum_downpayment_percentage', 5, 2)->default('20.00');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['education_level', 'is_active']);
            $table->unique(['name', 'education_level', 'program_id', 'year_level'], 'fee_templates_scope_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_templates');
    }
};
