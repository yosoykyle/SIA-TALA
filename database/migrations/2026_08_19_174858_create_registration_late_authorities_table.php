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
        Schema::create('registration_late_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('action_type', 40);
            $table->unsignedBigInteger('before_course_enrollment_id');
            $table->unsignedBigInteger('after_section_id')->nullable();
            $table->string('approving_office', 120);
            $table->string('authority_reference', 255);
            $table->date('authority_date');
            $table->text('reason');
            $table->timestamp('effective_at');
            $table->string('learner_acknowledgement_basis', 255);
            $table->string('source_academic_decision', 255);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'action_type', 'authority_reference'],
                'registration_late_authority_identity_unique',
            );
            $table->index(['enrollment_id', 'action_type', 'consumed_at'], 'registration_late_authority_open_index');
            $table->foreign('before_course_enrollment_id', 'reg_late_before_course_fk')
                ->references('id')
                ->on('course_enrollments')
                ->restrictOnDelete();
            $table->foreign('after_section_id', 'reg_late_after_section_fk')
                ->references('id')
                ->on('sections')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_late_authorities');
    }
};
