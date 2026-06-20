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
        Schema::create('admission_requirement_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_offering_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft')->index();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('source_label')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['admission_offering_id', 'version'], 'admission_requirement_policies_version_unique');
            $table->index(['admission_offering_id', 'status', 'effective_from'], 'admission_requirement_policies_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_requirement_policies');
    }
};
