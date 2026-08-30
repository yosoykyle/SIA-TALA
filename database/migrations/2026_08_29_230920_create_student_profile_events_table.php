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
        Schema::create('student_profile_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('source', 64);
            $table->string('authority_reference', 255);
            $table->text('reason');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->json('changed_fields');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['student_profile_id', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_profile_events');
    }
};
