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
        Schema::create('transcript_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('degree_conferral_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('supersedes_request_id')->nullable();
            $table->string('external_request_reference')->unique();
            $table->date('requested_on');
            $table->date('due_on');
            $table->string('template_version', 64);
            $table->string('signatory_name');
            $table->string('signatory_title');
            $table->string('seal_input_type', 32);
            $table->string('seal_path')->nullable();
            $table->char('seal_checksum', 64)->nullable();
            $table->text('seal_placement_instruction')->nullable();
            $table->char('source_fingerprint', 64);
            $table->string('state', 24);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('supersedes_request_id', 'transcript_requests_supersedes_fk')
                ->references('id')->on('transcript_requests')->restrictOnDelete();
            $table->unique(['student_profile_id', 'version'], 'transcript_request_version_unique');
            $table->index(['state', 'due_on', 'recorded_at'], 'transcript_requests_queue_index');
        });

        Schema::create('transcript_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transcript_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('degree_conferral_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('supersedes_snapshot_id')->nullable();
            $table->string('reference', 64)->unique();
            $table->string('template_version', 64);
            $table->char('source_fingerprint', 64);
            $table->json('content');
            $table->string('status', 24);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->foreign('supersedes_snapshot_id', 'transcript_snapshots_supersedes_fk')
                ->references('id')->on('transcript_snapshots')->restrictOnDelete();
            $table->unique(['transcript_request_id', 'version'], 'transcript_snapshot_version_unique');
            $table->index(['transcript_request_id', 'status', 'issued_at'], 'transcript_snapshots_state_index');
        });

        Schema::create('transcript_issuance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transcript_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('transcript_snapshot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('predecessor_event_id')->nullable();
            $table->string('type', 24);
            $table->string('reference', 64)->unique();
            $table->text('reason')->nullable();
            $table->string('authority_reference');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('predecessor_event_id', 'transcript_events_predecessor_fk')
                ->references('id')->on('transcript_issuance_events')->restrictOnDelete();
            $table->index(['transcript_request_id', 'type', 'recorded_at'], 'transcript_events_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcript_issuance_events');
        Schema::dropIfExists('transcript_snapshots');
        Schema::dropIfExists('transcript_requests');
    }
};
