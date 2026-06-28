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
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('blocking_level');
            $table->string('status');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('staff_reason');
            $table->text('student_reason')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();
            $table->index(['student_profile_id', 'status', 'blocking_level'], 'holds_student_status_index');
            $table->index(['term_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('student_lifecycle_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->foreignId('enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('course_enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('requested_on')->nullable();
            $table->date('effective_on');
            $table->date('decided_on');
            $table->string('authority');
            $table->string('private_source_reference')->nullable();
            $table->text('reason');
            $table->json('impact_snapshot')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state');
            $table->timestamps();
            $table->index(['student_profile_id', 'effective_on', 'type'], 'lifecycle_student_effective_index');
            $table->index(['term_id', 'state']);
        });

        Schema::create('program_shift_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_lifecycle_change_id');
            $table->foreignId('curriculum_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->foreignId('source_grade_outcome_event_id')->nullable();
            $table->string('treatment');
            $table->string('state');
            $table->decimal('numeric_grade', 7, 4)->nullable();
            $table->timestamps();
            $table->unique(['student_lifecycle_change_id', 'curriculum_entry_id'], 'shift_credit_entry_unique');
            $table->index(['curriculum_entry_id', 'state']);
            $table->foreign('student_lifecycle_change_id', 'shift_credit_lifecycle_fk')->references('id')->on('student_lifecycle_changes')->restrictOnDelete();
            $table->foreign('source_grade_outcome_event_id', 'shift_credit_grade_event_fk')->references('id')->on('grade_outcome_events')->restrictOnDelete();
        });

        Schema::create('graduation_review_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('state');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('filter_summary')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['term_id', 'name']);
            $table->index(['term_id', 'state']);
        });

        Schema::create('graduation_review_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('graduation_review_batch_id');
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at');
            $table->boolean('is_active')->default(true);
            $table->unique(['graduation_review_batch_id', 'student_profile_id'], 'graduation_member_unique');
            $table->index(['student_profile_id', 'is_active']);
            $table->foreign('graduation_review_batch_id', 'graduation_member_batch_fk')->references('id')->on('graduation_review_batches')->restrictOnDelete();
        });

        Schema::create('graduation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('graduation_review_member_id');
            $table->unsignedInteger('version');
            $table->string('result_status');
            $table->json('evaluation_snapshot');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->foreignId('made_visible_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('made_visible_at')->nullable();
            $table->text('visibility_reason')->nullable();
            $table->unique(['graduation_review_member_id', 'version'], 'graduation_snapshot_version_unique');
            $table->index(['result_status', 'generated_at']);
            $table->index(['made_visible_at']);
            $table->foreign('graduation_review_member_id', 'graduation_snapshot_member_fk')->references('id')->on('graduation_review_members')->restrictOnDelete();
        });

        Schema::create('output_access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('output_type');
            $table->string('source_record_type');
            $table->unsignedBigInteger('source_record_id');
            $table->foreignId('student_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->string('copy_context')->nullable();
            $table->unsignedInteger('schedule_version')->nullable();
            $table->json('filter_summary')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('purpose')->nullable();
            $table->string('sensitivity')->nullable();
            $table->string('stored_file_reference')->nullable();
            $table->json('request_context')->nullable();
            $table->string('status');
            $table->timestamp('occurred_at');
            $table->index(['source_record_type', 'source_record_id'], 'output_logs_source_index');
            $table->index(['output_type', 'action', 'occurred_at'], 'output_logs_action_index');
            $table->index(['student_profile_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['sensitivity', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('output_access_logs');
        Schema::dropIfExists('graduation_snapshots');
        Schema::dropIfExists('graduation_review_members');
        Schema::dropIfExists('graduation_review_batches');
        Schema::dropIfExists('program_shift_credit_entries');
        Schema::dropIfExists('student_lifecycle_changes');
        Schema::dropIfExists('holds');
    }
};
