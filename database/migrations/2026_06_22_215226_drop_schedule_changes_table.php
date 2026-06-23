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
        Schema::dropIfExists('schedule_changes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_meeting_id')->nullable()->constrained('section_meetings')->nullOnDelete();
            $table->string('status')->default('proposed');
            $table->json('old_payload');
            $table->json('new_payload');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'status']);
            $table->index('section_meeting_id');
            $table->index('requested_by');
        });
    }
};
