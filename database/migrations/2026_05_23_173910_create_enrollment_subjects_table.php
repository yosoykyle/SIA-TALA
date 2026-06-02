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
        Schema::create('enrollment_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_meeting_id')->nullable()->constrained('section_meetings')->nullOnDelete();
            $table->decimal('units', 4, 2)->default('0.00');
            $table->decimal('lec_hours', 4, 2)->nullable();
            $table->string('status')->default('enrolled')->index();
            $table->boolean('is_dropped')->default(false)->index();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'subject_id'], 'enrollment_subject_unique');
            $table->index(['subject_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_subjects');
    }
};
