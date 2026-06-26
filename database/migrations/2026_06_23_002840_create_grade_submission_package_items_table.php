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
        Schema::create('grade_submission_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_submission_package_id')->constrained('grade_submission_packages', 'id', 'gspi_package_fk')->cascadeOnDelete();
            $table->foreignId('enrollment_subject_id')->constrained('enrollment_subjects')->restrictOnDelete();
            $table->foreignId('grade_id')->constrained('grades')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->json('entered_values');
            $table->json('derived_grade');
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['grade_submission_package_id', 'enrollment_subject_id'], 'grade_package_item_subject_unique');
            $table->index('grade_id');
            $table->index(['student_profile_id', 'subject_id'], 'gspi_student_subject_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_submission_package_items');
    }
};
