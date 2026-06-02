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
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('department')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description');
            $table->decimal('units', 4, 2)->default('0.00');
            $table->string('department')->index();
            $table->string('subject_type')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('curriculums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('effective_year');
            $table->string('version_name');
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'effective_year', 'version_name']);
            $table->index(['program_id', 'is_active']);
        });

        Schema::create('curriculum_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curriculums')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('year_level');
            $table->string('semester');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['curriculum_id', 'subject_id', 'year_level', 'semester'], 'curriculum_subject_scope_unique');
            $table->index(['curriculum_id', 'year_level', 'semester']);
        });

        Schema::create('prerequisites', function (Blueprint $table) {
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_id')->constrained('subjects')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['subject_id', 'prerequisite_id']);
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(false)->index();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('scheduling_starts_at')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('room')->nullable();
            $table->unsignedSmallInteger('max_seats');
            $table->unsignedSmallInteger('enrolled_count')->default(0);
            $table->string('modality');
            $table->timestamps();

            $table->unique(['term_id', 'program_id', 'name']);
            $table->index(['term_id', 'modality']);
            $table->index('program_id');
        });

        Schema::create('section_teacher', function (Blueprint $table) {
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->primary(['section_id', 'user_id', 'subject_id']);
            $table->index('user_id');
            $table->index('subject_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_teacher');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('prerequisites');
        Schema::dropIfExists('curriculum_subjects');
        Schema::dropIfExists('curriculums');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('programs');
    }
};
