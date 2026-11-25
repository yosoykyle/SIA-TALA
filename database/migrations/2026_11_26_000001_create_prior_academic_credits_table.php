<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prior_academic_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('course_specification_id')->constrained('course_specifications')->restrictOnDelete();
            $table->foreignId('curriculum_entry_id')->nullable()->constrained('curriculum_entries')->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->restrictOnDelete();
            $table->string('academic_year_label', 64)->nullable();
            $table->string('term_label', 64)->nullable();
            $table->unsignedTinyInteger('year_level')->nullable();
            $table->decimal('units', 5, 2)->default(0);
            $table->string('final_grade', 32)->nullable();
            $table->string('result_status', 32)->default('passed');
            $table->string('source_school', 255)->nullable();
            $table->string('state', 32)->default('ACTIVE')->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'state'], 'prior_credits_student_state_idx');
            $table->index(['student_profile_id', 'course_specification_id', 'state'], 'prior_credits_student_spec_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prior_academic_credits');
    }
};
