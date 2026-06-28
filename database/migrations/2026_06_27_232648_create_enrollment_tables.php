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
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('student_type');
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('officially_enrolled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->unique(['student_profile_id', 'term_id']);
            $table->index(['term_id', 'status']);
            $table->index(['student_profile_id', 'status']);
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_offering_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->decimal('units_snapshot', 5, 2)->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'term_offering_id']);
            $table->index(['term_offering_id', 'status']);
        });

        Schema::create('student_schedule_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_meeting_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('source');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();
            $table->unique(['course_enrollment_id', 'section_meeting_id'], 'schedule_bindings_identity_unique');
            $table->index(['section_meeting_id', 'is_active']);
        });

        Schema::create('enrollment_seat_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->foreignId('registrar_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['course_enrollment_id', 'section_id'], 'seat_reservations_placement_unique');
            $table->index(['section_id', 'status', 'deadline']);
        });

        Schema::create('enrollment_gate_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->string('gate_type');
            $table->unsignedInteger('sequence');
            $table->string('result');
            $table->string('responsible_office');
            $table->string('blocker_code')->nullable();
            $table->text('blocker_message')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('checked_at');
            $table->string('rule_version');
            $table->timestamps();
            $table->unique(['enrollment_id', 'gate_type', 'sequence'], 'gate_results_version_unique');
            $table->index(['result', 'gate_type']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('enrollment_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('exception_type');
            $table->foreignId('enrollment_gate_result_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('target_term_offering_id')->nullable()->constrained('term_offerings')->restrictOnDelete();
            $table->string('original_failed_result')->nullable();
            $table->string('original_rule')->nullable();
            $table->string('scope_key');
            $table->timestamp('expires_at')->nullable();
            $table->json('requested_values')->nullable();
            $table->json('approved_values')->nullable();
            $table->text('reason');
            $table->string('evidence_reference')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('state');
            $table->timestamps();
            $table->unique(['enrollment_id', 'exception_type', 'scope_key'], 'enrollment_exceptions_scope_unique');
            $table->index(['student_profile_id', 'term_id', 'exception_type', 'state'], 'enrollment_exceptions_queue_index');
            $table->index(['enrollment_gate_result_id']);
            $table->index(['target_term_offering_id']);
            $table->index(['expires_at']);
        });

        DB::statement("ALTER TABLE enrollment_exceptions ADD CONSTRAINT enrollment_exceptions_target_check CHECK ((exception_type = 'GATE_OVERRIDE' AND enrollment_gate_result_id IS NOT NULL) OR exception_type <> 'GATE_OVERRIDE')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_exceptions');
        Schema::dropIfExists('enrollment_gate_results');
        Schema::dropIfExists('enrollment_seat_reservations');
        Schema::dropIfExists('student_schedule_bindings');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('enrollments');
    }
};
