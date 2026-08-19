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
        Schema::table('course_specifications', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable()->after('revision_code');
            $table->date('effective_from')->nullable()->after('authority_reference');
            $table->date('effective_until')->nullable()->after('effective_from');
            $table->string('academic_classification', 32)->nullable()->after('grading_profile_version');
            $table->index(['course_id', 'state', 'effective_from'], 'course_revision_effective_index');
        });

        Schema::table('curriculum_versions', function (Blueprint $table): void {
            $table->date('authority_date')->nullable()->after('approval_reference');
            $table->date('effective_from')->nullable()->after('authority_date');
            $table->date('effective_until')->nullable()->after('effective_from');
            $table->char('content_hash', 64)->nullable()->after('effective_until');
        });

        Schema::table('sections', function (Blueprint $table): void {
            $table->foreignId('term_calendar_package_id')->nullable()->after('term_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_specification_id')->nullable()->after('term_calendar_package_id')->constrained()->restrictOnDelete();
            $table->string('class_reference', 64)->nullable()->after('code');
            $table->string('source', 32)->nullable()->after('class_reference');
            $table->string('delivery_mode', 32)->nullable()->after('source');
            $table->string('authority_reference', 255)->nullable()->after('delivery_mode');
            $table->foreignId('confirmed_by')->nullable()->after('state')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            $table->index(['term_calendar_package_id', 'state', 'course_specification_id'], 'class_offering_readiness_index');
        });

        Schema::table('schedule_runs', function (Blueprint $table): void {
            $table->string('contract_version', 64)->nullable()->after('input_hash');
            $table->string('quality_policy', 64)->nullable()->after('model_version');
            $table->unsignedInteger('candidate_version')->default(1)->after('candidate_key');
            $table->string('candidate_state', 32)->nullable()->after('candidate_version');
            $table->foreignId('candidate_reviewed_by')->nullable()->after('candidate_state')->constrained('users')->restrictOnDelete();
            $table->timestamp('candidate_reviewed_at')->nullable()->after('candidate_reviewed_by');
            $table->text('candidate_review_reason')->nullable()->after('candidate_reviewed_at');
            $table->json('quality_measures')->nullable()->after('objective_value');
            $table->index(['term_id', 'candidate_state', 'candidate_version'], 'term_candidate_version_index');
        });

        Schema::table('candidate_schedule_rows', function (Blueprint $table): void {
            $table->foreignId('supersedes_candidate_row_id')->nullable()->after('schedule_run_id');
            $table->string('change_type', 32)->nullable()->after('status');
            $table->foreign('supersedes_candidate_row_id', 'candidate_row_supersedes_fk')
                ->references('id')
                ->on('candidate_schedule_rows')
                ->restrictOnDelete();
        });

        Schema::table('section_meetings', function (Blueprint $table): void {
            $table->foreignId('published_timetable_version_id')->nullable()->after('schedule_run_id');
            $table->foreign('published_timetable_version_id', 'section_meeting_timetable_version_fk')
                ->references('id')
                ->on('published_timetable_versions')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_meetings', function (Blueprint $table): void {
            $table->dropForeign('section_meeting_timetable_version_fk');
            $table->dropColumn('published_timetable_version_id');
        });

        Schema::table('candidate_schedule_rows', function (Blueprint $table): void {
            $table->dropForeign('candidate_row_supersedes_fk');
            $table->dropColumn(['supersedes_candidate_row_id', 'change_type']);
        });

        Schema::table('schedule_runs', function (Blueprint $table): void {
            $table->dropIndex('term_candidate_version_index');
            $table->dropConstrainedForeignId('candidate_reviewed_by');
            $table->dropColumn([
                'contract_version',
                'quality_policy',
                'candidate_version',
                'candidate_state',
                'candidate_reviewed_at',
                'candidate_review_reason',
                'quality_measures',
            ]);
        });

        Schema::table('sections', function (Blueprint $table): void {
            $table->dropIndex('class_offering_readiness_index');
            $table->dropConstrainedForeignId('term_calendar_package_id');
            $table->dropConstrainedForeignId('course_specification_id');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['class_reference', 'source', 'delivery_mode', 'authority_reference', 'confirmed_at']);
        });

        Schema::table('curriculum_versions', function (Blueprint $table): void {
            $table->dropColumn(['authority_date', 'effective_from', 'effective_until', 'content_hash']);
        });

        Schema::table('course_specifications', function (Blueprint $table): void {
            $table->dropIndex('course_revision_effective_index');
            $table->dropColumn(['authority_reference', 'effective_from', 'effective_until', 'academic_classification']);
        });
    }
};
