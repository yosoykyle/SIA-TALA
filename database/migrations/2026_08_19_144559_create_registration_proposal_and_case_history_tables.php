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
        Schema::create('registration_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 40);
            $table->string('from_outcome', 40)->nullable();
            $table->string('to_outcome', 40)->nullable();
            $table->text('reason')->nullable();
            $table->string('authority_reference')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['enrollment_id', 'sequence']);
            $table->index(['event_type', 'recorded_at']);
        });

        Schema::create('registration_proposal_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_version_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('state', 32)->default('Draft');
            $table->string('selection_basis', 40);
            $table->unsignedBigInteger('published_timetable_version_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->json('source_snapshot');
            $table->char('content_hash', 64);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'version']);
            $table->unique('content_hash');
            $table->index(['enrollment_id', 'state', 'prepared_at'], 'registration_proposal_queue_index');
            $table->foreign('supersedes_version_id', 'registration_proposal_supersedes_fk')
                ->references('id')->on('registration_proposal_versions')->restrictOnDelete();
            $table->foreign('published_timetable_version_id', 'reg_proposal_timetable_fk')
                ->references('id')->on('published_timetable_versions')->restrictOnDelete();
            $table->foreign('curriculum_version_id', 'reg_proposal_curriculum_fk')
                ->references('id')->on('curriculum_versions')->restrictOnDelete();
        });

        Schema::create('registration_proposal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_proposal_version_id');
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('term_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->decimal('units_snapshot', 5, 2);
            $table->string('course_code_snapshot', 32);
            $table->string('course_title_snapshot');
            $table->json('meeting_snapshot');
            $table->timestamps();

            $table->unique(['registration_proposal_version_id', 'sequence'], 'registration_proposal_item_sequence_unique');
            $table->unique(['registration_proposal_version_id', 'term_offering_id'], 'registration_proposal_offering_unique');
            $table->foreign('registration_proposal_version_id', 'reg_proposal_item_version_fk')
                ->references('id')->on('registration_proposal_versions')->restrictOnDelete();
        });

        Schema::create('registration_proposal_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_proposal_version_id');
            $table->string('method', 24);
            $table->foreignId('learner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assisted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assisted_evidence_reference')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique('registration_proposal_version_id', 'registration_proposal_confirmation_unique');
            $table->foreign('registration_proposal_version_id', 'reg_proposal_confirmation_version_fk')
                ->references('id')->on('registration_proposal_versions')->restrictOnDelete();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreign('current_proposal_version_id', 'registration_case_current_proposal_fk')
                ->references('id')->on('registration_proposal_versions')->restrictOnDelete();
        });

        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->foreign('registration_proposal_item_id', 'course_registration_proposal_item_fk')
                ->references('id')->on('registration_proposal_items')->restrictOnDelete();
        });

        Schema::table('enrollment_seat_reservations', function (Blueprint $table) {
            $table->foreign('registration_proposal_item_id', 'seat_reservation_proposal_item_fk')
                ->references('id')->on('registration_proposal_items')->restrictOnDelete();
            $table->unique('registration_proposal_item_id', 'seat_reservation_proposal_item_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollment_seat_reservations', function (Blueprint $table) {
            $table->dropUnique('seat_reservation_proposal_item_unique');
            $table->dropForeign('seat_reservation_proposal_item_fk');
        });
        Schema::table('course_enrollments', fn (Blueprint $table) => $table->dropForeign('course_registration_proposal_item_fk'));
        Schema::table('enrollments', fn (Blueprint $table) => $table->dropForeign('registration_case_current_proposal_fk'));
        Schema::dropIfExists('registration_proposal_confirmations');
        Schema::dropIfExists('registration_proposal_items');
        Schema::dropIfExists('registration_proposal_versions');
        Schema::dropIfExists('registration_case_events');
    }
};
