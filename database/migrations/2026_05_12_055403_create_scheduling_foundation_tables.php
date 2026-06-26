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
        Schema::create('faculty_availability_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['opens_at', 'closes_at']);
        });

        Schema::create('faculty_availability_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('availability_period_id')->constrained('faculty_availability_periods')->restrictOnDelete();
            $table->foreignId('faculty_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('parent_submission_id')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'faculty_id', 'version'], 'faculty_availability_submission_version_unique');
            $table->index(['availability_period_id', 'status'], 'faculty_availability_submission_period_status_index');
            $table->index(['faculty_id', 'status'], 'faculty_availability_submission_faculty_status_index');
            $table->index('parent_submission_id', 'faculty_availability_submission_parent_index');
            $table->foreign('parent_submission_id', 'faculty_availability_submission_parent_fk')
                ->references('id')
                ->on('faculty_availability_submissions')
                ->restrictOnDelete();
        });

        Schema::create('faculty_availability_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('faculty_availability_submissions')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['submission_id', 'day_of_week']);
        });

        Schema::create('faculty_availability_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submission_id')->constrained('faculty_availability_submissions')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('creates_submission_id')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'status']);
            $table->index(['faculty_id', 'status']);
            $table->index('submission_id');
            $table->foreign('creates_submission_id', 'faculty_availability_change_created_submission_fk')
                ->references('id')
                ->on('faculty_availability_submissions')
                ->nullOnDelete();
        });

        Schema::create('schedule_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('generated');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->json('constraint_summary')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'status']);
            $table->index('requested_by');
            $table->index('generated_at');
            $table->index('committed_at');
        });

        Schema::create('candidate_schedule_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_run_id')->constrained('schedule_generation_runs')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('room')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('modality');
            $table->string('status')->default('ok');
            $table->json('conflict_payload')->nullable();
            $table->json('warning_payload')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['generation_run_id', 'status']);
            $table->index('section_id');
            $table->index(['faculty_id', 'day_of_week', 'starts_at', 'ends_at'], 'schedule_draft_faculty_time_index');
        });

        Schema::create('section_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('room')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('modality');
            $table->foreignId('schedule_generation_run_id')->nullable()->constrained('schedule_generation_runs')->nullOnDelete();
            $table->foreignId('committed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('committed_at');
            $table->timestamps();

            $table->index(['term_id', 'section_id']);
            $table->index(['faculty_id', 'day_of_week', 'starts_at', 'ends_at'], 'section_meetings_faculty_time_index');
            $table->index(['room', 'day_of_week', 'starts_at', 'ends_at'], 'section_meetings_room_time_index');
            $table->index('schedule_generation_run_id');
        });

        Schema::create('schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_meeting_id')->nullable()->constrained('section_meetings')->nullOnDelete();
            $table->string('status')->default('proposed');
            $table->json('old_payload');
            $table->json('new_payload');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'status']);
            $table->index('section_meeting_id');
            $table->index('requested_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_changes');
        Schema::dropIfExists('section_meetings');
        Schema::dropIfExists('candidate_schedule_rows');
        Schema::dropIfExists('schedule_generation_runs');
        Schema::dropIfExists('faculty_availability_change_requests');
        Schema::dropIfExists('faculty_availability_windows');
        Schema::dropIfExists('faculty_availability_submissions');
        Schema::dropIfExists('faculty_availability_periods');
    }
};
