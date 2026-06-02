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
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_subject_id')->nullable()->constrained('enrollment_subjects')->nullOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('prelim_grade', 6, 2)->nullable();
            $table->decimal('midterm_grade', 6, 2)->nullable();
            $table->decimal('final_grade', 6, 2)->nullable();
            $table->decimal('grade', 6, 2)->nullable();
            $table->string('remarks')->nullable()->index();
            $table->boolean('is_inc')->default(false)->index();
            $table->timestamp('inc_expires_at')->nullable();
            $table->boolean('is_finalized')->default(false)->index();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'subject_id'], 'grades_enrollment_subject_unique');
            $table->index(['term_id', 'is_finalized']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
