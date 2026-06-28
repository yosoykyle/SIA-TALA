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
        Schema::create('scheduling_demands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_component_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_delivery_group_id')->constrained()->restrictOnDelete();
            $table->string('demand_key')->unique();
            $table->unsignedSmallInteger('required_duration_minutes');
            $table->unsignedTinyInteger('meeting_count');
            $table->string('modality');
            $table->foreignId('fixed_faculty_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('fixed_room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->unsignedTinyInteger('fixed_day_of_week')->nullable();
            $table->time('fixed_start_time')->nullable();
            $table->string('validation_state')->index();
            $table->timestamps();
            $table->unique(['term_offering_id', 'course_component_id', 'section_delivery_group_id'], 'scheduling_demands_identity_unique');
        });

        Schema::create('schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('input_snapshot');
            $table->char('input_hash', 64);
            $table->string('solver_version');
            $table->string('model_version')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->decimal('objective_value', 12, 2)->nullable();
            $table->json('diagnostics')->nullable();
            $table->string('candidate_key')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('publication_version')->nullable();
            $table->text('publication_note')->nullable();
            $table->timestamps();
            $table->unique(['term_id', 'input_hash']);
            $table->index(['term_id', 'status', 'created_at']);
            $table->index(['term_id', 'publication_version']);
        });

        Schema::create('candidate_schedule_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('scheduling_demand_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('meeting_sequence');
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('time_block_key')->nullable();
            $table->string('status');
            $table->json('scores')->nullable();
            $table->json('warnings')->nullable();
            $table->json('violations')->nullable();
            $table->string('override_authority')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $table->unique(['schedule_run_id', 'scheduling_demand_id', 'meeting_sequence'], 'candidate_rows_identity_unique');
            $table->index(['room_id', 'day_of_week', 'starts_at', 'ends_at'], 'candidate_room_overlap_index');
            $table->index(['faculty_user_id', 'day_of_week', 'starts_at', 'ends_at'], 'candidate_faculty_overlap_index');
        });

        Schema::create('section_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('scheduling_demand_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('meeting_sequence');
            $table->foreignId('faculty_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('modality');
            $table->string('state');
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['schedule_run_id', 'scheduling_demand_id', 'meeting_sequence'], 'section_meetings_identity_unique');
            $table->index(['room_id', 'day_of_week', 'starts_at', 'ends_at'], 'meeting_room_overlap_index');
            $table->index(['faculty_user_id', 'day_of_week', 'starts_at', 'ends_at'], 'meeting_faculty_overlap_index');
        });

        Schema::create('schedule_revision_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('change_type');
            $table->text('reason');
            $table->date('effective_date');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('old_snapshot');
            $table->json('new_snapshot');
            $table->unsignedInteger('affected_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['section_meeting_id', 'created_at']);
            $table->index(['term_id', 'effective_date']);
        });

        DB::statement('ALTER TABLE candidate_schedule_rows ADD CONSTRAINT candidate_rows_time_check CHECK (starts_at < ends_at)');
        DB::statement("ALTER TABLE section_meetings ADD CONSTRAINT section_meetings_room_check CHECK (modality <> 'FACE_TO_FACE' OR room_id IS NOT NULL)");
        DB::statement('ALTER TABLE section_meetings ADD CONSTRAINT section_meetings_time_check CHECK (starts_at < ends_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_revision_events');
        Schema::dropIfExists('section_meetings');
        Schema::dropIfExists('candidate_schedule_rows');
        Schema::dropIfExists('schedule_runs');
        Schema::dropIfExists('scheduling_demands');
    }
};
