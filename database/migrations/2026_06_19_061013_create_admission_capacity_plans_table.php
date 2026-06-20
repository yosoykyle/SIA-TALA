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
        Schema::create('admission_capacity_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type')->index();
            $table->string('education_level')->nullable()->index();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('year_level')->nullable()->index();
            $table->string('delivery_setup')->nullable()->index();
            $table->unsignedInteger('capacity_limit');
            $table->unsignedInteger('reserved_count')->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'status', 'scope_type'], 'admission_capacity_plans_resolution_index');
            $table->unique(
                ['term_id', 'scope_type', 'education_level', 'program_id', 'year_level', 'delivery_setup', 'status'],
                'admission_capacity_plans_scope_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_capacity_plans');
    }
};
