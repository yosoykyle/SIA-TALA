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
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('state')->index();
            $table->timestamps();
        });

        Schema::create('course_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('revision_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('credit_units', 5, 2);
            $table->string('grading_profile_key');
            $table->unsignedInteger('grading_profile_version');
            $table->json('allowed_modalities');
            $table->boolean('same_faculty_default')->default(true);
            $table->foreignId('effective_term_id')->nullable()->constrained('terms')->restrictOnDelete();
            $table->string('state');
            $table->timestamps();
            $table->unique(['course_id', 'revision_code']);
            $table->index(['course_id', 'state']);
        });

        Schema::create('course_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_specification_id')->constrained()->restrictOnDelete();
            $table->string('component_type');
            $table->decimal('weekly_contact_hours', 5, 2);
            $table->string('room_type_default')->nullable()->index();
            $table->string('modality_restriction')->nullable();
            $table->boolean('requires_consecutive_block')->default(false);
            $table->boolean('same_faculty')->default(true);
            $table->unsignedSmallInteger('sequence');
            $table->timestamps();
            $table->unique(['course_specification_id', 'component_type'], 'course_components_type_unique');
        });

        Schema::create('course_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_specification_id')->constrained()->restrictOnDelete();
            $table->string('rule_type');
            $table->string('group_key');
            $table->foreignId('related_course_id')->constrained('courses')->restrictOnDelete();
            $table->string('direction')->nullable();
            $table->string('equivalency_scope')->nullable();
            $table->string('required_outcome')->nullable();
            $table->decimal('minimum_grade', 7, 4)->nullable();
            $table->boolean('accepts_transfer_credit')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('authority');
            $table->string('state');
            $table->unsignedSmallInteger('sequence');
            $table->timestamps();
            $table->unique(['course_specification_id', 'rule_type', 'group_key', 'related_course_id', 'effective_from'], 'course_requirements_identity_unique');
            $table->index(['rule_type', 'state']);
        });

        Schema::create('curriculum_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('version_code');
            $table->string('name');
            $table->foreignId('effective_entry_term_id')->nullable()->constrained('terms')->restrictOnDelete();
            $table->string('state')->index();
            $table->string('approval_reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'version_code']);
        });

        Schema::create('curriculum_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_specification_id')->constrained()->restrictOnDelete();
            $table->string('year_level');
            $table->string('term_label');
            $table->string('term_type');
            $table->unsignedSmallInteger('sequence');
            $table->string('requirement_group')->default('required');
            $table->timestamps();
            $table->unique(['curriculum_version_id', 'year_level', 'term_label', 'course_specification_id'], 'curriculum_entries_identity_unique');
            $table->index(['curriculum_version_id', 'year_level', 'term_label', 'sequence'], 'curriculum_entries_order_index');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('template_version');
            $table->string('source_disk');
            $table->string('source_path');
            $table->string('source_checksum')->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->string('state');
            $table->json('validation_details')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'state', 'created_at']);
        });

        DB::statement('ALTER TABLE course_components ADD CONSTRAINT course_components_hours_check CHECK (weekly_contact_hours > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('curriculum_entries');
        Schema::dropIfExists('curriculum_versions');
        Schema::dropIfExists('course_requirements');
        Schema::dropIfExists('course_components');
        Schema::dropIfExists('course_specifications');
        Schema::dropIfExists('courses');
    }
};
