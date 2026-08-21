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
        Schema::create('timetable_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_version_id');
            $table->unsignedBigInteger('successor_version_id')->nullable();
            $table->string('state', 24)->default('Draft');
            $table->string('change_type', 40);
            $table->json('changes_snapshot');
            $table->json('impact_snapshot');
            $table->char('content_hash', 64);
            $table->string('authority_reference');
            $table->text('reason');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['source_version_id', 'content_hash'], 'timetable_revision_source_hash_unique');
            $table->index(['term_id', 'state', 'prepared_at'], 'timetable_revision_queue_index');
            $table->foreign('source_version_id', 'timetable_revision_source_fk')->references('id')->on('published_timetable_versions')->restrictOnDelete();
            $table->foreign('successor_version_id', 'timetable_revision_successor_fk')->references('id')->on('published_timetable_versions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timetable_revisions');
    }
};
