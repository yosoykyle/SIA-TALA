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
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending_payment')->index();
            $table->string('student_type')->nullable()->index(); // new, transferee, regular, irregular, returnee
            $table->string('year_level')->nullable();
            $table->string('modality')->nullable();
            $table->string('lis_status')->default('not_encoded')->index();
            $table->boolean('is_late_enrollment')->default(false);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('pre_enrolled_at')->nullable();
            $table->timestamp('officially_enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_profile_id', 'term_id'], 'enrollments_student_term_unique');
            $table->index(['term_id', 'status']);
            $table->index(['section_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
