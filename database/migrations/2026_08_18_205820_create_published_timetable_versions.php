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
        Schema::create('published_timetable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('schedule_run_id')->constrained('schedule_runs')->restrictOnDelete();
            $table->foreignId('supersedes_version_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('state', 32)->default('Draft');
            $table->string('authority_reference', 255);
            $table->text('publication_reason')->nullable();
            $table->json('source_versions');
            $table->json('impact_summary')->nullable();
            $table->char('content_hash', 64);
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['term_id', 'version']);
            $table->unique('content_hash');
            $table->index(['term_id', 'state', 'published_at'], 'published_timetable_current_index');
            $table->foreign('supersedes_version_id', 'ptv_supersedes_fk')
                ->references('id')
                ->on('published_timetable_versions')
                ->restrictOnDelete();
        });

        Schema::create('published_timetable_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('published_timetable_version_id');
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('scheduling_demand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('meeting_sequence');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('modality', 32);
            $table->string('location_label', 255);
            $table->foreignId('supersedes_meeting_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['published_timetable_version_id', 'section_id', 'meeting_sequence'],
                'published_version_section_meeting_unique',
            );
            $table->index(['faculty_user_id', 'day_of_week', 'starts_at'], 'published_faculty_schedule_index');
            $table->foreign('published_timetable_version_id', 'ptm_version_fk')
                ->references('id')
                ->on('published_timetable_versions')
                ->restrictOnDelete();
            $table->foreign('supersedes_meeting_id', 'ptm_supersedes_fk')
                ->references('id')
                ->on('published_timetable_meetings')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('published_timetable_meetings');
        Schema::dropIfExists('published_timetable_versions');
    }
};
