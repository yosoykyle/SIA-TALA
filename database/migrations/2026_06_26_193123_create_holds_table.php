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
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hold_type')->index();
            $table->string('blocking_level')->index();
            $table->string('status')->default('active')->index();
            $table->text('reason');
            $table->text('staff_only_reason')->nullable();
            $table->text('student_message')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('resolution_requirement')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'status', 'blocking_level'], 'holds_student_status_blocking_index');
            $table->index(['student_profile_id', 'term_id', 'status'], 'holds_student_term_status_index');
            $table->index(['student_profile_id', 'enrollment_id', 'status'], 'holds_student_enrollment_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
