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
        Schema::create('shifting_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('effective_term_id')->constrained('terms')->restrictOnDelete();
            $table->foreignId('current_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('requested_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('status')->default('submitted')->index(); // submitted, under_review, approved, rejected, cancelled
            $table->text('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifting_requests');
    }
};
