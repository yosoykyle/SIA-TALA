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
        Schema::create('term_calendar_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 32)->default('Draft');
            $table->date('administrative_starts_on');
            $table->date('administrative_ends_on');
            $table->date('classes_start_on');
            $table->date('classes_end_on');
            $table->string('authority_reference', 255);
            $table->date('authority_date');
            $table->text('special_term_schedule_basis')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'version']);
            $table->index(['term_id', 'state']);
        });

        Schema::create('term_calendar_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_calendar_package_id')->constrained()->restrictOnDelete();
            $table->string('window_type', 64);
            $table->date('opens_on');
            $table->date('closes_on');
            $table->time('cutoff_at')->nullable();
            $table->timestamps();

            $table->unique(['term_calendar_package_id', 'window_type'], 'term_package_window_unique');
        });

        Schema::create('term_teaching_grid_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_calendar_package_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->json('breaks')->nullable();
            $table->timestamps();

            $table->unique(['term_calendar_package_id', 'day_of_week'], 'term_package_grid_day_unique');
        });

        Schema::create('term_dated_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_calendar_package_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('exception_type', 64);
            $table->string('label', 255);
            $table->boolean('blocks_teaching')->default(true);
            $table->string('authority_reference', 255);
            $table->timestamps();
        });

        Schema::create('term_cohorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->string('source', 32);
            $table->unsignedInteger('forecast_count')->nullable();
            $table->unsignedInteger('confirmed_count')->nullable();
            $table->string('state', 32)->default('Forecast');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'reference']);
            $table->index(['term_id', 'state', 'program_id']);
        });

        Schema::create('section_term_cohort', function (Blueprint $table) {
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_cohort_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_count');
            $table->timestamps();

            $table->primary(['section_id', 'term_cohort_id']);
        });

        Schema::create('faculty_availability_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('declaration', 32);
            $table->json('hard_unavailability')->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->unique(['term_id', 'faculty_user_id', 'version'], 'faculty_term_declaration_version_unique');
        });

        Schema::create('resource_unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('faculty_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('effective_on')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('authority_reference', 255);
            $table->text('reason');
            $table->timestamps();

            $table->index(['term_id', 'room_id', 'day_of_week'], 'room_unavailability_lookup');
            $table->index(['term_id', 'faculty_user_id', 'day_of_week'], 'faculty_unavailability_lookup');
        });

        Schema::create('scheduling_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('faculty_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('authority_reference', 255);
            $table->text('reason');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['term_id', 'section_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduling_commitments');
        Schema::dropIfExists('resource_unavailabilities');
        Schema::dropIfExists('faculty_availability_declarations');
        Schema::dropIfExists('section_term_cohort');
        Schema::dropIfExists('term_cohorts');
        Schema::dropIfExists('term_dated_exceptions');
        Schema::dropIfExists('term_teaching_grid_rows');
        Schema::dropIfExists('term_calendar_windows');
        Schema::dropIfExists('term_calendar_packages');
    }
};
