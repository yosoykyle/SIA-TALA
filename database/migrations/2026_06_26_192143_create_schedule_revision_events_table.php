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
        Schema::create('schedule_revision_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->foreignId('section_meeting_id')->constrained('section_meetings')->restrictOnDelete();
            $table->string('change_type');
            $table->text('reason');
            $table->date('effective_date');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->json('old_snapshot_json');
            $table->json('new_snapshot_json');
            $table->integer('affected_student_count')->default(0);
            $table->integer('affected_faculty_count')->default(0);
            $table->timestamps();
            
            $table->index('term_id');
            $table->index('section_meeting_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_revision_events');
    }
};
