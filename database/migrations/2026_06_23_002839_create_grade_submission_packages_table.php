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
        Schema::create('grade_submission_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_id')->constrained('users')->restrictOnDelete();
            $table->string('state')->default('submitted')->index();
            $table->string('roster_snapshot_checksum', 64);
            $table->json('grading_profile_snapshot')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('registrar_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registrar_reviewed_at')->nullable();
            $table->string('return_reason', 500)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'section_id', 'subject_id', 'state'], 'grade_packages_scope_state_index');
            $table->index(['faculty_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_submission_packages');
    }
};
