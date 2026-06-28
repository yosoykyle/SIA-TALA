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
        Schema::create('grade_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->string('state');
            $table->json('grading_profile_snapshot');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
            $table->unique(['term_offering_id', 'section_id']);
            $table->index(['faculty_user_id', 'state']);
        });

        Schema::create('grade_roster_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_roster_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_enrollment_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('prelim_equivalent', 7, 4)->nullable();
            $table->decimal('midterm_equivalent', 7, 4)->nullable();
            $table->decimal('final_equivalent', 7, 4)->nullable();
            $table->decimal('computed_average', 7, 4)->nullable();
            $table->string('current_outcome_code')->nullable();
            $table->string('current_outcome_category')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['grade_roster_id', 'current_outcome_category'], 'grade_rows_roster_outcome_index');
        });

        Schema::create('grade_outcome_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_roster_row_id')->constrained()->restrictOnDelete();
            $table->string('event_type');
            $table->decimal('previous_value', 7, 4)->nullable();
            $table->decimal('new_value', 7, 4)->nullable();
            $table->string('previous_category')->nullable();
            $table->string('new_category')->nullable();
            $table->date('deadline')->nullable();
            $table->string('authority');
            $table->text('reason');
            $table->string('evidence_reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['grade_roster_row_id', 'created_at']);
            $table->index(['event_type', 'deadline']);
        });

        Schema::create('late_grade_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_roster_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->string('grading_period');
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->string('state');
            $table->timestamps();
            $table->index(['faculty_user_id', 'opens_at', 'closes_at', 'state'], 'late_grade_window_index');
        });

        DB::statement('ALTER TABLE grade_roster_rows ADD CONSTRAINT grade_rows_range_check CHECK ((prelim_equivalent IS NULL OR prelim_equivalent BETWEEN 0 AND 100) AND (midterm_equivalent IS NULL OR midterm_equivalent BETWEEN 0 AND 100) AND (final_equivalent IS NULL OR final_equivalent BETWEEN 0 AND 100) AND (computed_average IS NULL OR computed_average BETWEEN 0 AND 100))');
        DB::statement('ALTER TABLE late_grade_authorizations ADD CONSTRAINT late_grade_window_check CHECK (opens_at < closes_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('late_grade_authorizations');
        Schema::dropIfExists('grade_outcome_events');
        Schema::dropIfExists('grade_roster_rows');
        Schema::dropIfExists('grade_rosters');
    }
};
