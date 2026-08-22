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
        $ambiguousLegacyDataExists = DB::table('grade_roster_rows')
            ->where(function ($query): void {
                $query->whereNotNull('prelim_equivalent')
                    ->orWhereNotNull('midterm_equivalent')
                    ->orWhereNotNull('final_equivalent')
                    ->orWhereNotNull('computed_average')
                    ->orWhere('current_outcome_code', 'P');
            })
            ->exists();

        if ($ambiguousLegacyDataExists) {
            throw new RuntimeException(
                'Slice 5 migration stopped: legacy period-derived or P-grade data requires an explicit disposition decision.',
            );
        }

        Schema::create('class_offering_teaching_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('term_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 24);
            $table->string('state', 24)->default('ACTIVE');
            $table->string('authority_reference');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('replaced_by_assignment_id')->nullable();
            $table->foreign('replaced_by_assignment_id', 'teaching_assignments_replacement_fk')
                ->references('id')->on('class_offering_teaching_assignments')->nullOnDelete();
            $table->timestamps();

            $table->index(['section_id', 'state', 'role']);
            $table->index(['faculty_user_id', 'state']);
        });

        Schema::table('grade_rosters', function (Blueprint $table): void {
            $table->unsignedInteger('current_version_number')->default(0)->after('grading_profile_snapshot');
            $table->string('membership_signature', 64)->nullable()->after('current_version_number');
            $table->foreignId('teaching_assignment_id')->nullable()->after('faculty_user_id')
                ->constrained('class_offering_teaching_assignments')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0)->after('membership_signature');
            $table->timestamp('invalidated_at')->nullable()->after('return_reason');
            $table->foreignId('invalidated_by')->nullable()->after('invalidated_at')->constrained('users')->nullOnDelete();
            $table->text('invalidation_reason')->nullable()->after('invalidated_by');
        });

        Schema::table('grade_roster_rows', function (Blueprint $table): void {
            $table->string('final_result', 8)->nullable()->after('course_enrollment_id');
            $table->text('inc_completion_note')->nullable()->after('final_result');
            $table->boolean('is_current_membership')->default(true)->after('inc_completion_note');
            $table->unsignedInteger('row_revision')->default(0)->after('is_current_membership');
            $table->timestamp('returned_at')->nullable()->after('released_at');
            $table->foreignId('returned_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
            $table->text('return_reason')->nullable()->after('returned_by');
        });

        Schema::create('grade_roster_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_roster_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('teaching_assignment_id')->constrained('class_offering_teaching_assignments')->restrictOnDelete();
            $table->string('membership_signature', 64);
            $table->string('state', 24);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->timestamps();

            $table->unique(['grade_roster_id', 'version_number']);
            $table->index(['grade_roster_id', 'state']);
        });

        Schema::create('grade_roster_version_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_roster_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_roster_row_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->restrictOnDelete();
            $table->string('final_result', 8);
            $table->text('inc_completion_note')->nullable();
            $table->unsignedInteger('row_revision');
            $table->timestamps();

            $table->unique(['grade_roster_version_id', 'grade_roster_row_id'], 'grade_roster_version_row_unique');
        });

        Schema::create('grade_roster_returned_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_roster_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_roster_row_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['grade_roster_version_id', 'grade_roster_row_id'], 'grade_roster_returned_row_unique');
        });

        Schema::table('grade_outcome_events', function (Blueprint $table): void {
            $table->string('result_code', 8)->nullable()->after('event_type');
            $table->date('source_term_ends_on')->nullable()->after('result_code');
            $table->text('inc_completion_note')->nullable()->after('source_term_ends_on');
            $table->foreignId('source_version_id')->nullable()->after('inc_completion_note')
                ->constrained('grade_roster_versions')->restrictOnDelete();
            $table->foreignId('predecessor_event_id')->nullable()->after('source_version_id')
                ->constrained('grade_outcome_events')->restrictOnDelete();
            $table->timestamp('released_at')->nullable()->after('recorded_by');
            $table->string('source_key')->nullable()->after('released_at');
            $table->unique('source_key');
        });

        Schema::create('inc_completion_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_outcome_event_id')->constrained()->restrictOnDelete();
            $table->string('proposed_result', 8);
            $table->text('completion_note');
            $table->string('state', 24);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('released_event_id')->nullable()->constrained('grade_outcome_events')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->index(['grade_outcome_event_id', 'state']);
        });

        Schema::create('inc_deadline_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grade_outcome_event_id')->constrained()->restrictOnDelete();
            $table->date('previous_deadline');
            $table->date('new_deadline');
            $table->string('authority_reference');
            $table->date('authority_date');
            $table->text('reason');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        Schema::create('external_competency_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_competency_requirement_id');
            $table->foreign('external_competency_requirement_id', 'competency_results_requirement_fk')
                ->references('id')->on('external_competency_requirements')->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('outcome', 32);
            $table->string('evidence_reference');
            $table->string('authority_reference');
            $table->date('authority_date');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignId('supersedes_result_id')->nullable()->constrained('external_competency_results')->restrictOnDelete();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['student_profile_id', 'external_competency_requirement_id', 'is_current'], 'external_competency_current_index');
        });

        Schema::create('academic_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('effect', 32);
            $table->string('authority_reference');
            $table->date('authority_date');
            $table->text('reason');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('state', 24)->default('ACTIVE');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['student_profile_id', 'state', 'effective_from']);
        });

        Schema::create('term_average_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->string('authority_reference');
            $table->date('authority_date');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['term_id', 'is_current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('term_average_labels');
        Schema::dropIfExists('academic_decisions');
        Schema::dropIfExists('external_competency_results');
        Schema::dropIfExists('inc_deadline_amendments');
        Schema::dropIfExists('inc_completion_submissions');

        Schema::table('grade_outcome_events', function (Blueprint $table): void {
            $table->dropForeign(['source_version_id']);
            $table->dropForeign(['predecessor_event_id']);
            $table->dropUnique(['source_key']);
            $table->dropColumn([
                'result_code', 'source_term_ends_on', 'inc_completion_note', 'source_version_id',
                'predecessor_event_id', 'released_at', 'source_key',
            ]);
        });

        Schema::dropIfExists('grade_roster_returned_rows');
        Schema::dropIfExists('grade_roster_version_rows');
        Schema::dropIfExists('grade_roster_versions');

        Schema::table('grade_roster_rows', function (Blueprint $table): void {
            $table->dropForeign(['returned_by']);
            $table->dropColumn([
                'final_result', 'inc_completion_note', 'is_current_membership', 'row_revision',
                'returned_at', 'returned_by', 'return_reason',
            ]);
        });

        Schema::table('grade_rosters', function (Blueprint $table): void {
            $table->dropForeign(['teaching_assignment_id']);
            $table->dropForeign(['invalidated_by']);
            $table->dropColumn([
                'current_version_number', 'membership_signature', 'teaching_assignment_id',
                'lock_version', 'invalidated_at', 'invalidated_by', 'invalidation_reason',
            ]);
        });

        Schema::dropIfExists('class_offering_teaching_assignments');
    }
};
